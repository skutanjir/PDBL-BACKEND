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
        if (!$this->tableReady('monitoring_events')) {
            return new MonitoringEvent((array) $request->input());
        }

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
        if (!$sessionKey || !$this->tableReady('user_sessions', ['session_key'])) {
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

        if ($user && Schema::hasColumn('users', 'last_seen_at')) {
            $user->forceFill(['last_seen_at' => $now])->save();
        }
    }

    public function touchDevice(Request $request, ?User $user = null): void
    {
        $deviceId = $request->input('device_id') ?: $request->header('X-Device-ID');
        if ((!$user && !$deviceId) || !$this->tableReady('user_device_histories')) {
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
        if (!$this->tableReady('api_activity_logs')) {
            return;
        }

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
        if (!$this->tableReady('audit_logs')) {
            return;
        }

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

        $monthlyCompleted = $this->todoCompletedCount($monthStart);
        $previousCompleted = $this->todoCompletedCount($previousMonthStart, $previousMonthEnd);

        return [
            'privacy_safe' => true,
            'generated_at' => $now->toIso8601String(),
            'active_users' => [
                'daily' => $this->activeUsers($now->copy()->startOfDay()),
                'monthly' => $this->activeUsers($monthStart),
                'yearly' => $this->activeUsers($now->copy()->startOfYear()),
                'inactive_30_days' => $this->inactiveUsers($now->copy()->subDays(30)),
            ],
            'sessions' => [
                'average_duration_seconds' => $this->sessionAverageDuration($monthStart),
                'total_duration_seconds' => $this->sessionTotalDuration($monthStart),
            ],
            'tasks' => [
                'created' => $this->tableCount('todos'),
                'created_today' => $this->todoCreatedCount($now->copy()->startOfDay()),
                'created_this_month' => $this->todoCreatedCount($monthStart),
                'completed' => $this->todoCompletedCount(),
                'incomplete' => $this->todoIncompleteCount(),
                'monthly_completed' => $monthlyCompleted,
                'monthly_productivity_growth_percent' => $this->growth($monthlyCompleted, $previousCompleted),
                'team_distribution' => $this->taskTeamDistribution(),
                'created_this_month_series' => $this->taskCreatedSeries($monthStart, $now),
            ],
            'chat' => [
                'messages' => $this->tableCount('chat_messages'),
                'active_chat_users' => $this->activeChatUsers($monthStart),
                'ai_requests' => $this->tableReady('ai_request_logs', ['created_at']) ? DB::table('ai_request_logs')->where('created_at', '>=', $monthStart)->count() : 0,
                'ai_tokens_estimate' => $this->aiTokensEstimate($monthStart),
            ],
            'api' => [
                'requests_today' => $this->apiCount($now->copy()->startOfDay()),
                'average_latency_ms' => $this->apiAverageLatency($now->copy()->startOfDay()),
                'error_rate_percent' => $this->apiErrorRate($now->copy()->startOfDay()),
                'failed_login_rate' => $this->failedLoginRate($now->copy()->startOfDay()),
                'slow_requests' => $this->slowRequests(),
                'latency_series' => $this->apiLatencySeries($now->copy()->subMinutes(60), 20),
            ],
            'charts' => [
                'last_7_days' => $this->dailySeries($now->copy()->subDays(6), $now),
                'this_month' => $this->dailySeries($monthStart, $now),
                'this_year' => $this->monthlySeries($now->copy()->startOfYear(), $now),
            ],
            'security' => [
                'flagged_users' => $this->userStatusCount('flagged'),
                'warning_users' => $this->userStatusCount('warning'),
                'banned_users' => $this->userStatusCount('banned'),
                'blocked_requests' => $this->apiBooleanCount('blocked', $now->copy()->subDay()),
                'suspicious_events' => $this->apiBooleanCount('suspicious', $now->copy()->subDay()),
                'device_count' => $this->tableCount('user_device_histories'),
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
                    'pending' => $this->tableCount('jobs'),
                    'failed' => $this->tableCount('failed_jobs'),
                ],
                'database' => $this->databaseGrowth(),
                'storage' => $this->storageUsage(),
            ],
        ];
    }

    private function tableReady(string $table, array $columns = []): bool
    {
        if (!Schema::hasTable($table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function tableCount(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }

    private function todoCreatedCount(?Carbon $from = null, ?Carbon $to = null): int
    {
        if (!$this->tableReady('todos', ['created_at'])) {
            return 0;
        }

        $query = DB::table('todos');
        if ($from) {
            $to ? $query->whereBetween('created_at', [$from, $to]) : $query->where('created_at', '>=', $from);
        }

        return $query->count();
    }

    private function todoCompletedCount(?Carbon $from = null, ?Carbon $to = null): int
    {
        if (!$this->tableReady('todos', ['is_completed'])) {
            return 0;
        }

        $query = DB::table('todos')->where('is_completed', true);
        if ($from && $this->tableReady('todos', ['updated_at'])) {
            $to ? $query->whereBetween('updated_at', [$from, $to]) : $query->where('updated_at', '>=', $from);
        }

        return $query->count();
    }

    private function todoIncompleteCount(): int
    {
        return $this->tableReady('todos', ['is_completed']) ? DB::table('todos')->where('is_completed', false)->count() : 0;
    }

    private function taskCreatedSeries(Carbon $start, Carbon $end): array
    {
        if (!$this->tableReady('todos', ['created_at'])) {
            return [];
        }

        $rows = DB::table('todos')
            ->whereBetween('created_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->get(['created_at'])
            ->groupBy(fn ($item) => Carbon::parse($item->created_at)->toDateString());

        $series = [];
        for ($day = $start->copy()->startOfDay(); $day <= $end->copy()->startOfDay(); $day->addDay()) {
            $key = $day->toDateString();
            $series[] = [
                'label' => $day->format('d M'),
                'tasks' => collect($rows->get($key, []))->count(),
            ];
        }

        return $series;
    }

    private function taskTeamDistribution(): array
    {
        if (!$this->tableReady('todos', ['team_id'])) {
            return [];
        }

        return DB::table('todos')
            ->select('team_id', DB::raw('count(*) as total'))
            ->whereNotNull('team_id')
            ->groupBy('team_id')
            ->limit(20)
            ->get()
            ->all();
    }

    private function inactiveUsers(Carbon $before): int
    {
        if (!$this->tableReady('users', ['last_seen_at'])) {
            return 0;
        }

        return User::where(function ($query) use ($before) {
            $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $before);
        })->count();
    }

    private function activeUsers(Carbon $since): int
    {
        $query = User::query();
        $hasUserLastSeen = $this->tableReady('users', ['last_seen_at']);
        $hasSessions = $this->tableReady('user_sessions', ['last_seen_at', 'user_id']);

        if (!$hasUserLastSeen && !$hasSessions) {
            return 0;
        }

        $query->where(function ($users) use ($since, $hasUserLastSeen, $hasSessions) {
            if ($hasUserLastSeen) {
                $users->where('last_seen_at', '>=', $since);
            }

            if ($hasSessions) {
                $method = $hasUserLastSeen ? 'orWhereIn' : 'whereIn';
                $users->{$method}('id', UserSession::where('last_seen_at', '>=', $since)->whereNotNull('user_id')->select('user_id'));
            }
        });

        return $query->count();
    }

    private function sessionAverageDuration(Carbon $since): int
    {
        if (!$this->tableReady('user_sessions', ['started_at', 'duration_seconds'])) {
            return 0;
        }

        return (int) UserSession::where('started_at', '>=', $since)->avg('duration_seconds');
    }

    private function sessionTotalDuration(Carbon $since): int
    {
        if (!$this->tableReady('user_sessions', ['started_at', 'duration_seconds'])) {
            return 0;
        }

        return (int) UserSession::where('started_at', '>=', $since)->sum('duration_seconds');
    }

    private function activeChatUsers(Carbon $since): int
    {
        if (!$this->tableReady('chat_messages', ['created_at', 'sender_id'])) {
            return 0;
        }

        return DB::table('chat_messages')->where('created_at', '>=', $since)->distinct('sender_id')->count('sender_id');
    }

    private function aiTokensEstimate(Carbon $since): int
    {
        if (!$this->tableReady('ai_request_logs', ['created_at', 'prompt_tokens_estimate', 'response_tokens_estimate'])) {
            return 0;
        }

        return (int) DB::table('ai_request_logs')
            ->where('created_at', '>=', $since)
            ->get(['prompt_tokens_estimate', 'response_tokens_estimate'])
            ->sum(fn ($row) => (int) $row->prompt_tokens_estimate + (int) $row->response_tokens_estimate);
    }

    private function apiCount(Carbon $since): int
    {
        return $this->tableReady('api_activity_logs', ['occurred_at']) ? ApiActivityLog::where('occurred_at', '>=', $since)->count() : 0;
    }

    private function apiAverageLatency(Carbon $since): int
    {
        if (!$this->tableReady('api_activity_logs', ['occurred_at', 'duration_ms'])) {
            return 0;
        }

        return (int) ApiActivityLog::where('occurred_at', '>=', $since)->avg('duration_ms');
    }

    private function slowRequests(): array
    {
        if (!$this->tableReady('api_activity_logs', ['duration_ms', 'occurred_at'])) {
            return [];
        }

        return ApiActivityLog::where('duration_ms', '>=', 1000)->latest('occurred_at')->limit(20)->get()->all();
    }

    private function userStatusCount(string $status): int
    {
        return $this->tableReady('users', ['status']) ? User::where('status', $status)->count() : 0;
    }

    private function apiBooleanCount(string $column, Carbon $since): int
    {
        if (!$this->tableReady('api_activity_logs', [$column, 'occurred_at'])) {
            return 0;
        }

        return ApiActivityLog::where($column, true)->where('occurred_at', '>=', $since)->count();
    }

    private function growth(int $current, int $previous): float
    {
        if ($previous === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }
        return round((($current - $previous) / $previous) * 100, 2);
    }

    private function dailySeries(Carbon $start, Carbon $end): array
    {
        $rows = $this->apiRowsBetween($start->copy()->startOfDay(), $end->copy()->endOfDay())
            ->groupBy(fn ($item) => optional($item->occurred_at)->toDateString());

        $series = [];
        for ($day = $start->copy()->startOfDay(); $day <= $end->copy()->startOfDay(); $day->addDay()) {
            $key = $day->toDateString();
            $group = collect($rows->get($key, []));
            $series[] = [
                'label' => $day->format('d M'),
                'requests' => $group->count(),
                'latency' => (int) $group->avg(fn ($item) => (int) $item->duration_ms),
            ];
        }

        return $series;
    }

    private function monthlySeries(Carbon $start, Carbon $end): array
    {
        $rows = $this->apiRowsBetween($start->copy()->startOfMonth(), $end->copy()->endOfMonth())
            ->groupBy(fn ($item) => optional($item->occurred_at)->format('Y-m'));

        $series = [];
        for ($month = $start->copy()->startOfMonth(); $month <= $end->copy()->startOfMonth(); $month->addMonth()) {
            $key = $month->format('Y-m');
            $group = collect($rows->get($key, []));
            $series[] = [
                'label' => $month->format('M Y'),
                'requests' => $group->count(),
                'latency' => (int) $group->avg(fn ($item) => (int) $item->duration_ms),
            ];
        }

        return $series;
    }

    private function apiRowsBetween(Carbon $start, Carbon $end)
    {
        if (!$this->tableReady('api_activity_logs', ['occurred_at', 'duration_ms'])) {
            return collect();
        }

        return ApiActivityLog::whereBetween('occurred_at', [$start, $end])->get(['duration_ms', 'occurred_at']);
    }

    private function apiLatencySeries(Carbon $since, int $limit): array
    {
        if (!$this->tableReady('api_activity_logs', ['occurred_at', 'duration_ms'])) {
            return [];
        }

        $columns = collect(['id', 'user_id', 'status_code', 'duration_ms', 'path', 'occurred_at'])
            ->filter(fn ($column) => Schema::hasColumn('api_activity_logs', $column))
            ->values()
            ->all();

        return ApiActivityLog::where('occurred_at', '>=', $since)
            ->oldest('occurred_at')
            ->limit($limit)
            ->get($columns)
            ->map(fn ($item) => [
                'label' => optional($item->occurred_at)->format('H:i') ?? '-',
                'latency' => (int) ($item->duration_ms ?? 0),
                'status_code' => $item->status_code ?? null,
                'user_id' => $item->user_id ?? null,
                'path' => $item->path ?? null,
            ])
            ->all();
    }

    private function apiErrorRate(Carbon $since): float
    {
        if (!$this->tableReady('api_activity_logs', ['occurred_at', 'status_code'])) {
            return 0.0;
        }

        $total = ApiActivityLog::where('occurred_at', '>=', $since)->count();
        if ($total === 0) return 0.0;
        $errors = ApiActivityLog::where('occurred_at', '>=', $since)->where('status_code', '>=', 400)->count();
        return round(($errors / $total) * 100, 2);
    }

    private function failedLoginRate(Carbon $since): float
    {
        if (!$this->tableReady('api_activity_logs', ['occurred_at', 'path', 'status_code'])) {
            return 0.0;
        }

        $attempts = ApiActivityLog::where('occurred_at', '>=', $since)->where('path', 'like', '%login%')->count();
        if ($attempts === 0) return 0.0;
        $failed = ApiActivityLog::where('occurred_at', '>=', $since)->where('path', 'like', '%login%')->where('status_code', '>=', 400)->count();
        return round(($failed / $attempts) * 100, 2);
    }

    private function retentionPercent(Carbon $activeSince, Carbon $cohortSince): float
    {
        if (!$this->tableReady('users', ['created_at']) || !$this->tableReady('user_sessions', ['user_id', 'last_seen_at'])) {
            return 0.0;
        }

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
        $avatars = $this->tableReady('users', ['avatar']) ? DB::table('users')->whereNotNull('avatar')->count() : 0;
        $teamAvatars = $this->tableReady('teams', ['avatar']) ? DB::table('teams')->whereNotNull('avatar')->count() : 0;
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
            ->reject(fn ($_value, $key) => in_array(strtolower((string) $key), $blocked, true))
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
