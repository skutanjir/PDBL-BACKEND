<?php

namespace App\Services;

use App\Models\ApiActivityLog;
use App\Models\AuditLog;
use App\Models\MonitoringEvent;
use App\Models\User;
use App\Models\UserDeviceHistory;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class MonitoringService
{
    private const SAFE_EVENT_TYPES = [
        'app_opened',
        'app_foregrounded',
        'app_backgrounded',
        'app_paused',
        'app_resumed',
        'app_terminated',
        'session_started',
        'session_heartbeat',
        'session_ended',
        'screen_view',
        'screen_loaded',
        'feature_used',
        'task_created',
        'task_completed',
        'team_invite_started',
        'chat_opened',
        'chat_message_sent',
        'ai_request_started',
        'ai_request_cancelled',
        'offline_action_queued',
        'sync_failed',
        'sync_retried',
        'sync_completed',
        'network_changed',
        'startup_time',
        'cold_start',
        'warm_start',
        'crash_breadcrumb',
        'frontend_error',
        'battery_guard_reduced_frequency',
    ];

    public function recordMobileEvent(Request $request, ?User $user = null): MonitoringEvent
    {
        $metadata = $this->safeMetadata((array) $request->input('metadata', []));
        $eventType = (string) $request->input('event_type');
        if (!in_array($eventType, self::SAFE_EVENT_TYPES, true)) {
            $eventType = 'feature_used';
        }

        $event = MonitoringEvent::create([
            'user_id' => $user?->id,
            'device_id' => $request->input('device_id') ?: $request->header('X-Device-ID'),
            'session_key' => $request->input('session_key'),
            'event_type' => $eventType,
            'category' => (string) $request->input('category', 'mobile'),
            'source' => 'mobile',
            'duration_ms' => $request->integer('duration_ms') ?: null,
            'screen' => $this->shortText($request->input('screen'), 80),
            'feature' => $this->shortText($request->input('feature'), 80),
            'network_type' => $this->shortText($request->input('network_type'), 40),
            'offline' => $request->boolean('offline'),
            'payload_size' => strlen(json_encode($metadata) ?: ''),
            'metadata' => $metadata,
            'occurred_at' => $this->timestamp($request->input('occurred_at')),
        ]);

        $this->touchSession($request, $user);
        $this->touchDevice($request, $user);

        return $event;
    }

    public function touchSession(Request $request, ?User $user = null): void
    {
        $sessionKey = $request->input('session_key');
        if (!$sessionKey) {
            return;
        }

        $now = now();
        $session = UserSession::firstOrNew(['session_key' => $sessionKey]);
        $startedAt = $session->started_at ?: $this->timestamp($request->input('started_at')) ?: $now;

        $session->fill([
            'user_id' => $user?->id ?? $session->user_id,
            'device_id' => $request->input('device_id') ?: $request->header('X-Device-ID') ?: $session->device_id,
            'platform' => $this->shortText($request->input('platform'), 30) ?: $session->platform,
            'app_version' => $this->shortText($request->input('app_version'), 40) ?: $session->app_version,
            'timezone' => $this->shortText($request->input('timezone') ?: $request->header('X-Timezone'), 80) ?: $session->timezone,
            'locale' => $this->shortText($request->input('locale'), 20) ?: $session->locale,
            'network_type' => $this->shortText($request->input('network_type'), 40) ?: $session->network_type,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'started_at' => $startedAt,
            'last_seen_at' => $now,
            'ended_at' => $request->input('event_type') === 'session_ended' ? $now : $session->ended_at,
            'duration_seconds' => max(0, $startedAt->diffInSeconds($now)),
            'metadata' => $this->safeMetadata((array) $request->input('session_metadata', [])),
        ])->save();

        if ($user) {
            $user->forceFill(['last_seen_at' => $now])->save();
        }
    }

    public function touchDevice(Request $request, ?User $user = null): void
    {
        $deviceId = $request->input('device_id') ?: $request->header('X-Device-ID');
        if (!$user && !$deviceId) {
            return;
        }

        $now = now();
        $device = UserDeviceHistory::firstOrNew([
            'user_id' => $user?->id,
            'device_id' => $deviceId,
            'ip_address' => $request->ip(),
        ]);

        $device->fill([
            'user_agent' => $request->userAgent(),
            'platform' => $this->shortText($request->input('platform'), 30) ?: $device->platform,
            'app_version' => $this->shortText($request->input('app_version'), 40) ?: $device->app_version,
            'first_seen_at' => $device->first_seen_at ?: $now,
            'last_seen_at' => $now,
            'seen_count' => ($device->seen_count ?? 0) + 1,
            'metadata' => $this->safeMetadata((array) $request->input('device_metadata', [])),
        ])->save();
    }

    public function recordApiActivity(Request $request, int $statusCode, int $durationMs, bool $blocked = false): void
    {
        try {
            $user = auth('api')->user();
            $path = '/' . ltrim($request->path(), '/');

            ApiActivityLog::create([
                'user_id' => $user?->id,
                'device_id' => $request->header('X-Device-ID') ?: $request->input('device_id'),
                'method' => $request->method(),
                'path' => $path,
                'route_name' => $request->route()?->getName(),
                'status_code' => $statusCode,
                'duration_ms' => $durationMs,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'action' => $this->actionFromRequest($request),
                'blocked' => $blocked,
                'rate_limited' => $statusCode === 429,
                'suspicious' => $this->isSuspicious($request, $statusCode),
                'metadata' => [
                    'privacy_safe' => true,
                    'query_keys' => array_keys($request->query()),
                ],
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('MonitoringService::recordApiActivity failed: ' . $e->getMessage());
        }
    }

    public function audit(Request $request, string $action, ?User $target = null, array $metadata = []): void
    {
        $actor = auth('api')->user();
        AuditLog::create([
            'actor_user_id' => $actor?->id,
            'target_user_id' => $target?->id,
            'action' => $action,
            'entity_type' => $metadata['entity_type'] ?? null,
            'entity_id' => $metadata['entity_id'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => $this->safeMetadata($metadata),
            'occurred_at' => now(),
        ]);
    }

    public function dashboard(): array
    {
        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $previousMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $previousMonthEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();

        $monthlyCompleted = DB::table('todos')->where('is_completed', true)->where('updated_at', '>=', $monthStart)->count();
        $previousCompleted = DB::table('todos')->where('is_completed', true)->whereBetween('updated_at', [$previousMonthStart, $previousMonthEnd])->count();

        return [
            'privacy_safe' => true,
            'generated_at' => $now->toIso8601String(),
            'active_users' => [
                'daily' => $this->activeUsers($now->copy()->startOfDay()),
                'monthly' => $this->activeUsers($monthStart),
                'yearly' => $this->activeUsers($now->copy()->startOfYear()),
                'inactive_30_days' => User::where(function ($query) use ($now) {
                    $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $now->copy()->subDays(30));
                })->count(),
            ],
            'sessions' => [
                'average_duration_seconds' => (int) UserSession::where('started_at', '>=', $monthStart)->avg('duration_seconds'),
                'total_duration_seconds' => (int) UserSession::where('started_at', '>=', $monthStart)->sum('duration_seconds'),
            ],
            'tasks' => [
                'created' => DB::table('todos')->count(),
                'completed' => DB::table('todos')->where('is_completed', true)->count(),
                'incomplete' => DB::table('todos')->where('is_completed', false)->count(),
                'monthly_completed' => $monthlyCompleted,
                'monthly_productivity_growth_percent' => $this->growth($monthlyCompleted, $previousCompleted),
                'team_distribution' => DB::table('todos')->select('team_id', DB::raw('count(*) as total'))->whereNotNull('team_id')->groupBy('team_id')->limit(20)->get(),
            ],
            'chat' => [
                'messages' => Schema::hasTable('chat_messages') ? DB::table('chat_messages')->count() : 0,
                'active_chat_users' => Schema::hasTable('chat_messages') ? DB::table('chat_messages')->where('created_at', '>=', $monthStart)->distinct('sender_id')->count('sender_id') : 0,
                'ai_requests' => Schema::hasTable('ai_request_logs') ? DB::table('ai_request_logs')->where('created_at', '>=', $monthStart)->count() : 0,
                'ai_tokens_estimate' => Schema::hasTable('ai_request_logs') ? (int) DB::table('ai_request_logs')->where('created_at', '>=', $monthStart)->sum(DB::raw('prompt_tokens_estimate + response_tokens_estimate')) : 0,
            ],
            'api' => [
                'requests_today' => ApiActivityLog::where('occurred_at', '>=', $now->copy()->startOfDay())->count(),
                'average_latency_ms' => (int) ApiActivityLog::where('occurred_at', '>=', $now->copy()->startOfDay())->avg('duration_ms'),
                'error_rate_percent' => $this->apiErrorRate($now->copy()->startOfDay()),
                'failed_login_rate' => $this->failedLoginRate($now->copy()->startOfDay()),
                'slow_requests' => ApiActivityLog::where('duration_ms', '>=', 1000)->latest()->limit(20)->get(),
            ],
            'security' => [
                'flagged_users' => User::where('status', 'flagged')->count(),
                'warning_users' => User::where('status', 'warning')->count(),
                'banned_users' => User::where('status', 'banned')->count(),
                'suspicious_events' => ApiActivityLog::where('suspicious', true)->where('occurred_at', '>=', $now->copy()->subDay())->count(),
                'device_count' => UserDeviceHistory::count(),
            ],
            'retention' => [
                'weekly_cohort_percent' => $this->retentionPercent($now->copy()->subDays(7), $now->copy()->subDays(14)),
                'monthly_cohort_percent' => $this->retentionPercent($now->copy()->subDays(30), $now->copy()->subDays(60)),
            ],
            'system' => [
                'sla' => [
                    'uptime_target_percent' => 99.9,
                    'latency_target_ms' => 500,
                    'error_rate_target_percent' => 1,
                ],
                'jobs' => [
                    'pending' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0,
                    'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
                ],
                'database' => $this->databaseGrowth(),
                'storage' => $this->storageUsage(),
            ],
        ];
    }

    private function activeUsers(Carbon $since): int
    {
        return User::where('last_seen_at', '>=', $since)
            ->orWhereIn('id', UserSession::where('last_seen_at', '>=', $since)->select('user_id'))
            ->count();
    }

    private function growth(int $current, int $previous): float
    {
        if ($previous === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }
        return round((($current - $previous) / $previous) * 100, 2);
    }

    private function apiErrorRate(Carbon $since): float
    {
        $total = ApiActivityLog::where('occurred_at', '>=', $since)->count();
        if ($total === 0) return 0.0;
        $errors = ApiActivityLog::where('occurred_at', '>=', $since)->where('status_code', '>=', 400)->count();
        return round(($errors / $total) * 100, 2);
    }

    private function failedLoginRate(Carbon $since): float
    {
        $attempts = ApiActivityLog::where('occurred_at', '>=', $since)->where('path', 'like', '%login%')->count();
        if ($attempts === 0) return 0.0;
        $failed = ApiActivityLog::where('occurred_at', '>=', $since)->where('path', 'like', '%login%')->where('status_code', '>=', 400)->count();
        return round(($failed / $attempts) * 100, 2);
    }

    private function retentionPercent(Carbon $activeSince, Carbon $cohortSince): float
    {
        $cohort = User::where('created_at', '>=', $cohortSince)->where('created_at', '<', $activeSince)->pluck('id');
        if ($cohort->isEmpty()) return 0.0;
        $retained = UserSession::whereIn('user_id', $cohort)->where('last_seen_at', '>=', $activeSince)->distinct('user_id')->count('user_id');
        return round(($retained / $cohort->count()) * 100, 2);
    }

    private function databaseGrowth(): array
    {
        return collect(['users', 'todos', 'teams', 'chat_messages', 'ai_request_logs', 'monitoring_events', 'api_activity_logs'])
            ->filter(fn ($table) => Schema::hasTable($table))
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])
            ->all();
    }

    private function storageUsage(): array
    {
        $avatars = DB::table('users')->whereNotNull('avatar')->count();
        $teamAvatars = Schema::hasTable('teams') ? DB::table('teams')->whereNotNull('avatar')->count() : 0;
        return ['avatar_records' => $avatars, 'team_avatar_records' => $teamAvatars, 'disk' => config('filesystems.default')];
    }

    private function actionFromRequest(Request $request): string
    {
        return strtolower($request->method()) . ' ' . '/' . ltrim($request->path(), '/');
    }

    private function isSuspicious(Request $request, int $statusCode): bool
    {
        return $statusCode === 401 || $statusCode === 403 || $statusCode === 429 || str_contains(strtolower($request->path()), 'password');
    }

    private function safeMetadata(array $metadata): array
    {
        $blocked = ['message', 'body', 'content', 'password', 'token', 'authorization', 'otp', 'secret'];
        return collect($metadata)
            ->reject(fn ($value, $key) => in_array(strtolower((string) $key), $blocked, true))
            ->map(function ($value) {
                if (is_string($value)) return $this->shortText($value, 200);
                if (is_array($value)) return $this->safeMetadata($value);
                return is_scalar($value) || is_null($value) ? $value : null;
            })
            ->all();
    }

    private function shortText(mixed $value, int $limit): ?string
    {
        if ($value === null) return null;
        return mb_substr((string) $value, 0, $limit);
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if (!$value) return null;
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
