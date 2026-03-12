<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use Illuminate\Http\Request;

class TodoController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user('sanctum');
        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;
        \Log::info('Todo index request', ['user_id' => $user?->id, 'device_id' => $deviceId]);

        if ($user) {
            $todos = Todo::where(function($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->orWhereIn('team_id', $user->teams->pluck('id'));
            })->latest()->get();
        } elseif ($deviceId) {
            $todos = Todo::where('device_id', $deviceId)->whereNull('user_id')->latest()->get();
        } else {
            return response()->json(['message' => 'Unauthorized or Device ID required'], 401);
        }

        return response()->json([
            'todos' => $todos,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'judul' => 'required|string|max:255',
            'deskripsi' => 'nullable|string',
            'deadline' => 'nullable|date',
            'priority' => 'nullable|in:high,medium,low',
            'team_id' => 'nullable|exists:teams,id',
            'assigned_emails' => 'nullable|array',
        ]);

        $user = $request->user('sanctum');
        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;
        \Log::info('Todo store request', ['user_id' => $user?->id, 'device_id' => $deviceId, 'judul' => $request->judul]);

        if (!$user && !$deviceId) {
            return response()->json(['message' => 'Unauthorized or Device ID required'], 401);
        }

        $todo = Todo::create([
            'judul' => $request->judul,
            'deskripsi' => $request->deskripsi,
            'deadline' => $request->deadline,
            'priority' => $request->priority ?? 'medium',
            'user_id' => $user ? $user->id : null,
            'device_id' => $user ? null : $deviceId,
            'team_id' => $request->team_id,
            'assigned_emails' => $request->assigned_emails,
        ]);

        return response()->json([
            'message' => 'Todo berhasil ditambahkan',
            'todo' => $todo,
        ], 201);
    }

    public function show(Request $request, Todo $todo)
    {
        $user = $request->user('sanctum');
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
        $user = $request->user('sanctum');
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

        $todo->update($request->only(['judul', 'deskripsi', 'is_completed', 'deadline', 'priority', 'team_id', 'assigned_emails']));

        return response()->json([
            'message' => 'Todo berhasil diupdate',
            'todo' => $todo,
        ]);
    }

    public function destroy(Request $request, Todo $todo)
    {
        $user = $request->user('sanctum');
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
            'message' => 'Todo berhasil dihapus',
        ]);
    }
}
