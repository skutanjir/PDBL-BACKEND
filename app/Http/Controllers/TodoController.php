<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TodoController extends Controller
{
    public function index(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = auth('api')->user();
        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;
        $perPage = $request->query('per_page', 50);

        $query = Todo::query();

        if ($user) {
            $query->where(function($q) use ($user, $request) {
                if ($request->boolean('assigned_only')) {
                    // Only my personal tasks OR team tasks specifically assigned to me
                    $q->where('user_id', $user->id)
                      ->orWhereJsonContains('assigned_emails', $user->email);
                } else {
                    // All tasks I created, my team's tasks, or tasks assigned to me
                    // Optimized with a direct subquery to avoid loading full Team models
                    $teamIds = DB::table('team_user')
                        ->where('user_id', $user->id)
                        ->where('status', 'accepted')
                        ->pluck('team_id');

                    $q->where('user_id', $user->id)
                      ->orWhereIn('team_id', $teamIds)
                      ->orWhereJsonContains('assigned_emails', $user->email);
                }
            });
        } elseif ($deviceId) {
            $query->where('device_id', $deviceId)->whereNull('user_id');
        } else {
            return response()->json(['message' => 'Unauthorized or Device ID required'], 401);
        }

        $todos = $query->with(['user', 'team.owner'])->latest()->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'todos' => $todos->items(),
            'pagination' => [
                'current_page' => $todos->currentPage(),
                'last_page' => $todos->lastPage(),
                'per_page' => $todos->perPage(),
                'total' => $todos->total(),
            ]
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'judul' => 'required|string|max:255',
            'deskripsi' => 'nullable|string',
            'deadline' => 'nullable|date',
            'priority' => 'nullable|in:high,medium,low',
            'is_completed' => 'nullable|boolean',
            'team_id' => 'nullable|exists:teams,id',
            'assigned_emails' => 'nullable|array',
        ]);

        /** @var \App\Models\User $user */
        $user = auth('api')->user();
        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;

        if (!$user && !$deviceId) {
            return response()->json(['message' => 'Unauthorized or Device ID required'], 401);
        }

        $todo = Todo::create([
            'judul' => $request->judul,
            'deskripsi' => $request->deskripsi,
            'is_completed' => $request->is_completed ?? false,
            'deadline' => $request->deadline,
            'priority' => $request->priority ?? 'medium',
            'user_id' => $user ? $user->id : null,
            'device_id' => $user ? null : $deviceId,
            'team_id' => $request->team_id,
            'assigned_emails' => $request->assigned_emails,
        ]);

        return response()->json([
            'message' => 'Todo added successfully',
            'todo' => $todo,
        ], 201);
    }

    public function show(Request $request, Todo $todo)
    {
        /** @var \App\Models\User $user */
        $user = auth('api')->user();
        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;

        $isOwnerOrMember = false;
        if ($user) {
            if ($todo->user_id === $user->id) {
                $isOwnerOrMember = true;
            } elseif ($todo->team_id && $user->teams()->where('team_id', $todo->team_id)->exists()) {
                $isOwnerOrMember = true;
            }
        } elseif ($deviceId && $todo->device_id === $deviceId && is_null($todo->user_id)) {
            $isOwnerOrMember = true;
        }

        if (!$isOwnerOrMember) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'todo' => $todo,
        ]);
    }

    public function update(Request $request, Todo $todo)
    {
        /** @var \App\Models\User $user */
        $user = auth('api')->user();
        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;

        $isOwnerOrMember = false;
        if ($user) {
            if ($todo->user_id === $user->id) {
                $isOwnerOrMember = true;
            } elseif ($todo->team_id && $user->teams()->where('team_id', $todo->team_id)->exists()) {
                $isOwnerOrMember = true;
            }
        } elseif ($deviceId && $todo->device_id === $deviceId && is_null($todo->user_id)) {
            $isOwnerOrMember = true;
        }

        if (!$isOwnerOrMember) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'judul' => 'sometimes|required|string|max:255',
            'deskripsi' => 'nullable|string',
            'is_completed' => 'sometimes|boolean',
            'deadline' => 'nullable|date',
            'priority' => 'nullable|in:high,medium,low',
            'team_id' => 'sometimes|nullable|exists:teams,id',
            'assigned_emails' => 'nullable|array',
        ]);

        $updateData = $request->only(['judul', 'deskripsi', 'deadline', 'priority', 'team_id', 'assigned_emails']);
        
        // Only allow is_completed update if it's NOT a team task
        // Team tasks must use the toggle-member endpoint for status changes
        if ($request->has('is_completed') && $todo->team_id === null) {
            $updateData['is_completed'] = $request->input('is_completed');
        }

        $todo->update($updateData);

        return response()->json([
            'message' => 'Todo updated successfully',
            'todo' => $todo,
        ]);
    }

    public function destroy(Request $request, Todo $todo)
    {
        /** @var \App\Models\User $user */
        $user = auth('api')->user();
        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;

        $isOwnerOrMember = false;
        if ($user) {
            if ($todo->user_id === $user->id) {
                $isOwnerOrMember = true;
            } elseif ($todo->team_id && $user->teams()->where('team_id', $todo->team_id)->exists()) {
                $isOwnerOrMember = true;
            }
        } elseif ($deviceId && $todo->device_id === $deviceId && is_null($todo->user_id)) {
            $isOwnerOrMember = true;
        }

        if (!$isOwnerOrMember) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $todo->delete();

        return response()->json([
            'message' => 'Todo deleted successfully',
        ]);
    }

    /**
     * Toggle current user's completion status on a team task.
     * Each assigned member must check individually.
     * Task is_completed = true only when ALL assigned members have checked.
     */
    public function toggleMember(Request $request, Todo $todo)
    {
        /** @var \App\Models\User $user */
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $email = strtolower(trim($user->email));
        $assignedEmails = collect($todo->assigned_emails ?? [])->map(fn($e) => strtolower(trim($e)));

        // Check if user is owner or assigned
        // Eager load team to avoid lazy loading in loop
        $todo->loadMissing('team');
        $isOwner = $todo->team && $todo->team->created_by === $user->id;
        $isAssigned = $assignedEmails->contains($email);

        if (!$isOwner && !$isAssigned) {
            return response()->json(['message' => 'Only the owner or assigned member can toggle this task'], 403);
        }

        $completedBy = collect($todo->completed_by ?? []);

        if ($completedBy->contains($email)) {
            // Uncheck: remove from completed_by
            $completedBy = $completedBy->reject(fn($e) => strtolower(trim((string)$e)) === $email)->values();
        } else {
            // Check: add to completed_by
            $completedBy->push($email);
        }

        $completedByArray = $completedBy->values()->all();
        $totalAssigned = max($assignedEmails->count(), 1);
        $totalCompleted = $completedBy->count();
        $isFullyCompleted = $totalCompleted >= $totalAssigned;

        $todo->update([
            'completed_by' => $completedByArray,
            'is_completed' => $isFullyCompleted,
        ]);

        return response()->json([
            'message' => 'Task status updated',
            'todo' => $todo->fresh(),
            'completed_count' => $totalCompleted,
            'total_assigned' => $totalAssigned,
            'is_fully_completed' => $isFullyCompleted,
        ]);
    }

    /**
     * Store multiple todos at once.
     * Used for initial sync or guest migration.
     */
    public function bulkStore(Request $request)
    {
        $request->validate([
            'tasks' => 'required|array',
            'tasks.*.local_id' => 'required',
            'tasks.*.judul' => 'required|string|max:255',
            'tasks.*.deskripsi' => 'nullable|string',
            'tasks.*.is_completed' => 'nullable|boolean',
            'tasks.*.deadline' => 'nullable|date',
            'tasks.*.priority' => 'nullable|in:high,medium,low',
            'tasks.*.team_id' => 'nullable|exists:teams,id',
            'tasks.*.assigned_emails' => 'nullable|array',
        ]);

        /** @var \App\Models\User $user */
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $results = [];

        DB::transaction(function () use ($request, $user, &$results) {
            foreach ($request->tasks as $taskData) {
                $todo = Todo::create([
                    'judul' => $taskData['judul'],
                    'deskripsi' => $taskData['deskripsi'],
                    'is_completed' => $taskData['is_completed'] ?? false,
                    'deadline' => $taskData['deadline'],
                    'priority' => $taskData['priority'] ?? 'medium',
                    'user_id' => $user->id,
                    'team_id' => $taskData['team_id'] ?? null,
                    'assigned_emails' => $taskData['assigned_emails'] ?? null,
                ]);

                $results[] = [
                    'local_id' => $taskData['local_id'],
                    'api_id' => $todo->id,
                    'todo' => $todo,
                ];
            }
        });

        return response()->json([
            'message' => count($results) . ' tasks synced successfully',
            'results' => $results,
        ], 201);
    }
}
