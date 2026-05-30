<?php

namespace App\Http\Controllers;

use App\Models\ApiActivityLog;
use App\Models\AuditLog;
use App\Models\MonitoringEvent;
use App\Models\User;
use App\Services\MonitoringService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'api' => ApiActivityLog::latest('occurred_at')->limit(100)->get(),
            'events' => MonitoringEvent::latest('occurred_at')->limit(100)->get(),
            'audit' => AuditLog::latest('occurred_at')->limit(100)->get(),
        ]);
    }

    public function updateUserStatus(Request $request, User $user, MonitoringService $monitoring)
    {
        $request->validate([
            'status' => ['required', Rule::in(['active', 'warning', 'flagged', 'banned'])],
            'reason' => 'nullable|string|max:500',
        ]);

        $status = $request->string('status')->toString();
        $user->forceFill([
            'status' => $status,
            'status_reason' => $request->input('reason'),
            'status_changed_at' => now(),
            'banned_at' => $status === 'banned' ? now() : null,
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
