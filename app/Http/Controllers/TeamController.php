<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Teams where user is an accepted member
        $teams = $user->teams()
            ->wherePivot('status', 'accepted')
            ->with(['owner', 'members' => function($q) {
                $q->wherePivot('status', 'accepted');
            }])
            ->get()
            ->map(function($team) {
                $totalTasks = $team->todos()->count();
                $completedTasks = $team->todos()->where('is_completed', true)->count();
                $team->progress = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;
                return $team;
            });

        // Pending invitations for the user
        $invitations = $user->teams()
            ->wherePivot('status', 'pending')
            ->with('owner')
            ->get();

        return response()->json([
            'teams' => $teams,
            'invitations' => $invitations,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $team = \App\Models\Team::create([
            'name' => $request->name,
            'description' => $request->description,
            'created_by' => $request->user()->id,
        ]);

        // Creator automatically becomes an accepted member
        $team->members()->attach($request->user()->id, ['status' => 'accepted']);

        return response()->json([
            'message' => 'Team created successfully',
            'team' => $team->load(['members' => function($q) {
                $q->wherePivot('status', 'accepted');
            }])
        ], 201);
    }

    public function invite(Request $request, \App\Models\Team $team)
    {
        // Only owner can invite
        if ($team->created_by !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $userToInvite = \App\Models\User::where('email', $request->email)->first();

        if ($team->members()->where('user_id', $userToInvite->id)->where('status', '!=', 'declined')->exists()) {
            $membership = $team->members()->where('user_id', $userToInvite->id)->first();
            if ($membership->pivot->status === 'banned') {
                return response()->json(['message' => 'This user is banned from this team'], 422);
            }
            return response()->json(['message' => 'User is already a member or invited to this team'], 422);
        }

        // Attach with pending status
        $team->members()->attach($userToInvite->id, ['status' => 'pending']);

        return response()->json([
            'message' => 'User invited to team successfully',
            'team' => $team->load('members')
        ]);
    }

    public function acceptInvitation(Request $request, \App\Models\Team $team)
    {
        $user = $request->user();
        
        $membership = $team->members()->where('user_id', $user->id)->first();
        
        if (!$membership || $membership->pivot->status !== 'pending') {
            return response()->json(['message' => 'No pending invitation found'], 404);
        }

        $team->members()->updateExistingPivot($user->id, ['status' => 'accepted']);

        return response()->json([
            'message' => 'Invitation accepted successfully',
            'team' => $team->load(['members' => function($q) {
                $q->wherePivot('status', 'accepted');
            }])
        ]);
    }

    public function declineInvitation(Request $request, \App\Models\Team $team)
    {
        $user = $request->user();
        
        $membership = $team->members()->where('user_id', $user->id)->first();
        
        if (!$membership || $membership->pivot->status !== 'pending') {
            return response()->json(['message' => 'No pending invitation found'], 404);
        }

        $team->members()->detach($user->id);

        return response()->json([
            'message' => 'Invitation declined successfully'
        ]);
    }

    public function show(\App\Models\Team $team)
    {
        $user = auth()->user();
        $isMember = $team->members()->where('user_id', $user->id)->where('status', 'accepted')->exists();

        if (!$isMember) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $members = $team->members()
            ->wherePivot('status', 'accepted')
            ->get()
            ->map(function($user) use ($team) {
                $totalTasks = $team->todos()->where('user_id', $user->id)->count();
                $completedTasks = $team->todos()->where('user_id', $user->id)->where('is_completed', true)->count();
                $user->progress = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;
                $user->role = ($user->id === $team->created_by) ? 'Ketua Team' : 'Member';
                return $user;
            });

        return response()->json([
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'description' => $team->description,
                'created_by' => $team->created_by,
                'owner' => $team->owner,
                'members' => $members,
            ],
            'tasks' => $team->todos()->with('user')->get(),
        ]);
    }

    public function update(Request $request, \App\Models\Team $team)
    {
        if ($team->created_by !== auth()->id()) {
            return response()->json(['message' => 'Only owner can update team'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $team->update($request->only('name', 'description'));

        return response()->json([
            'message' => 'Team updated successfully',
            'team' => $team
        ]);
    }

    public function removeMember(Request $request, \App\Models\Team $team, \App\Models\User $user)
    {
        if ($team->created_by !== auth()->id()) {
            return response()->json(['message' => 'Only owner can remove members'], 403);
        }

        if ($user->id === $team->created_by) {
            return response()->json(['message' => 'Cannot remove the owner'], 400);
        }

        $team->members()->detach($user->id);

        return response()->json(['message' => 'Member removed successfully']);
    }

    public function banMember(Request $request, \App\Models\Team $team, \App\Models\User $user)
    {
        if ($team->created_by !== auth()->id()) {
            return response()->json(['message' => 'Only owner can ban members'], 403);
        }

        if ($user->id === $team->created_by) {
            return response()->json(['message' => 'Cannot ban the owner'], 400);
        }

        $team->members()->updateExistingPivot($user->id, ['status' => 'banned']);

        return response()->json(['message' => 'Member banned successfully']);
    }

    public function destroy(Request $request, \App\Models\Team $team)
    {
        if ($team->created_by !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $team->delete();

        return response()->json(['message' => 'Team deleted successfully']);
    }
}
