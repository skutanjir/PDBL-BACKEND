<?php

namespace App\Http\Controllers;

use App\Models\ApiActivityLog;
use App\Models\AuditLog;
use App\Models\MonitoringEvent;
use App\Models\User;
use App\Services\MonitoringService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;

class MonitoringController extends Controller
{
    public function event(Request $request, MonitoringService $monitoring)
    {
        $request->validate([
            'event_type' => 'required|string|max:80',
            'category' => 'nullable|string|max:40',
            'session_key' => 'nullable|string|max:120',
            'device_id' => 'nullable|string|max:120',
            'duration_ms' => 'nullable|integer|min:0|max:86400000',
            'screen' => 'nullable|string|max:80',
            'feature' => 'nullable|string|max:80',
            'network_type' => 'nullable|string|max:40',
            'offline' => 'nullable|boolean',
            'metadata' => 'nullable|array',
            'occurred_at' => 'nullable|date',
            'platform' => 'nullable|string|max:30',
            'app_version' => 'nullable|string|max:40',
            'timezone' => 'nullable|string|max:80',
            'locale' => 'nullable|string|max:20',
        ]);

        $event = $monitoring->recordMobileEvent($request, auth('api')->user());

        return response()->json(['status' => 'recorded', 'event_id' => $event->id], 201);
    }

    public function dashboard(MonitoringService $monitoring)
    {
        return response()->json($monitoring->dashboard());
    }

    public function activity()
    {
        return response()->json([
            'privacy_safe' => true,
            'api' => $this->latestMonitoringRows(ApiActivityLog::class, 'api_activity_logs'),
            'events' => $this->latestMonitoringRows(MonitoringEvent::class, 'monitoring_events'),
            'audit' => $this->latestMonitoringRows(AuditLog::class, 'audit_logs'),
        ]);
    }

    public function users(Request $request)
    {
        $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:5|max:50',
            'search' => 'nullable|string|max:120',
            'status' => ['nullable', Rule::in(['active', 'warning', 'flagged', 'banned'])],
        ]);

        $users = User::query()
            ->select($this->safeUserColumns())
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.$request->string('search')->toString().'%';
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', $search)->orWhere('email', 'like', $search);
                });
            })
            ->when($request->filled('status') && Schema::hasColumn('users', 'status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($this->safeUserCountRelations() !== [], fn ($query) => $query->withCount($this->safeUserCountRelations()))
            ->when(
                Schema::hasColumn('users', 'last_seen_at'),
                fn ($query) => $query->orderByRaw('last_seen_at IS NULL')->latest('last_seen_at'),
                fn ($query) => $query->latest('created_at')
            )
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'privacy_safe' => true,
            'users' => $users,
        ]);
    }

    private function latestMonitoringRows(string $model, string $table)
    {
        if (!Schema::hasTable($table)) {
            return collect();
        }

        $query = $model::query();
        Schema::hasColumn($table, 'occurred_at') ? $query->latest('occurred_at') : $query->latest();

        return $query->limit(100)->get();
    }

    private function safeUserColumns(): array
    {
        return collect([
            'id',
            'name',
            'email',
            'status',
            'status_reason',
            'status_changed_at',
            'last_seen_at',
            'banned_at',
            'email_verified_at',
            'created_at',
        ])->filter(fn ($column) => Schema::hasColumn('users', $column))->values()->all();
    }

    private function safeUserCountRelations(): array
    {
        return collect([
            'todos' => Schema::hasTable('todos'),
            'teams' => Schema::hasTable('teams') && Schema::hasTable('team_user'),
            'chatConversations' => Schema::hasTable('chat_conversations') && Schema::hasTable('chat_conversation_user'),
        ])->filter()->keys()->all();
    }

    private function statusPayload(string $status, ?string $reason): array
    {
        return collect([
            'status' => $status,
            'status_reason' => $reason,
            'status_changed_at' => now(),
            'banned_at' => $status === 'banned' ? now() : null,
        ])->filter(fn ($_value, $column) => Schema::hasColumn('users', (string) $column))->all();
    }

    public function updateUserStatus(Request $request, User $user, MonitoringService $monitoring)
    {
        $request->validate([
            'status' => ['required', Rule::in(['active', 'warning', 'flagged', 'banned'])],
            'reason' => 'nullable|string|max:500',
        ]);

        $status = $request->string('status')->toString();
        $user->forceFill([
            ...$this->statusPayload($status, $request->input('reason')),
        ])->save();

        $monitoring->audit($request, "user.status.{$status}", $user, [
            'reason' => $request->input('reason'),
            'entity_type' => 'user',
            'entity_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'User status updated',
            'user' => $user->fresh(),
        ]);
    }
}
