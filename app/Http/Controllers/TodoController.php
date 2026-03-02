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

        if ($user) {
            $todos = $user->todos()->latest()->get();
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
        ]);

        $user = $request->user('sanctum');
        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;

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

        $isOwner = false;
        if ($user && $todo->user_id === $user->id) {
            $isOwner = true;
        } elseif (!$user && $deviceId && $todo->device_id === $deviceId && is_null($todo->user_id)) {
            $isOwner = true;
        }

        if (!$isOwner) {
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

        $isOwner = false;
        if ($user && $todo->user_id === $user->id) {
            $isOwner = true;
        } elseif (!$user && $deviceId && $todo->device_id === $deviceId && is_null($todo->user_id)) {
            $isOwner = true;
        }

        if (!$isOwner) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'judul' => 'sometimes|required|string|max:255',
            'deskripsi' => 'nullable|string',
            'is_completed' => 'sometimes|boolean',
            'deadline' => 'nullable|date',
            'priority' => 'nullable|in:high,medium,low',
        ]);

        $todo->update($request->only(['judul', 'deskripsi', 'is_completed', 'deadline', 'priority']));

        return response()->json([
            'message' => 'Todo berhasil diupdate',
            'todo' => $todo,
        ]);
    }

    public function destroy(Request $request, Todo $todo)
    {
        $user = $request->user('sanctum');
        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;

        $isOwner = false;
        if ($user && $todo->user_id === $user->id) {
            $isOwner = true;
        } elseif (!$user && $deviceId && $todo->device_id === $deviceId && is_null($todo->user_id)) {
            $isOwner = true;
        }

        if (!$isOwner) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $todo->delete();

        return response()->json([
            'message' => 'Todo berhasil dihapus',
        ]);
    }
}
