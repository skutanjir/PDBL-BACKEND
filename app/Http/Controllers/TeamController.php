<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Notification;
use App\Models\Team;
use App\Models\User;
use App\Jobs\SendPushNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = auth('api')->user();
        
        // Teams where user is an accepted member
        $teams = $user->teams()
            ->wherePivot('status', 'accepted')
            ->with(['owner', 'members' => function($q) {
                $q->wherePivot('status', 'accepted');
            }])
            ->withCount(['todos', 'todos as completed_todos_count' => function($query) {
                $query->where('is_completed', true);
            }])
            ->get();
        
        $teams->transform(function($team) {
            $team->progress = $team->todos_count > 0 ? round(($team->completed_todos_count / $team->todos_count) * 100) : 0;
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
            'description' => 'nullable|string|max:300',
            'max_members' => 'nullable|integer|min:1|max:100',
        ]);

        /** @var \App\Models\User $user */
        $user = auth('api')->user();
        $team = \App\Models\Team::create([
            'name' => $request->name,
            'description' => $request->description,
            'max_members' => $request->input('max_members', 100),
            'created_by' => $user->id,
        ]);

        // Creator automatically becomes an accepted member
        $team->members()->attach($user->id, ['status' => 'accepted']);

        return response()->json([
            'message' => 'Team created successfully',
            'team' => $team->load(['members' => function($q) {
                $q->wherePivot('status', 'accepted');
            }])
        ], 201);
    }

    public function invite(Request $request, \App\Models\Team $team)
    {
        /** @var \App\Models\User $user */
        $user = auth('api')->user();
        // Only owner can invite
        if ($team->created_by !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'email' => 'required|email',
        ]);

        $userToInvite = \App\Models\User::where('email', $request->email)->first();

        if (!$userToInvite) {
            return response()->json(['message' => 'User with this email does not exist.'], 404);
        }

        // Check capacity
        $currentAccepted = $team->members()->wherePivot('status', 'accepted')->count();
        if ($currentAccepted >= $team->max_members) {
            return response()->json(['message' => "Team is full. Maximum capacity is {$team->max_members} members."], 422);
        }

        if ($team->members()->where('user_id', $userToInvite->id)->where('status', '!=', 'declined')->exists()) {
            $membership = $team->members()->where('user_id', $userToInvite->id)->first();
            if ($membership->pivot->status === 'banned') {
                return response()->json(['message' => 'This user is banned from this team'], 422);
            }
            return response()->json(['message' => 'An active invitation for this user already exists. Please wait for their response.'], 422);
        }

        // Attach with pending status
        $team->members()->attach($userToInvite->id, ['status' => 'pending']);

        // Notification
        Notification::create([
            'user_id' => $userToInvite->id,
            'type' => 'invite',
            'message' => "You have been invited to join team {$team->name}.",
            'team_id' => $team->id,
        ]);

        // Push Notification (Queued)
        SendPushNotification::dispatch(
            $userToInvite,
            "Team Invitation",
            "You have been invited to join team {$team->name}.",
            [
                'team_id' => (string)$team->id, 
                'type' => 'invite',
                'priority' => 'high',
                'description' => 'Click to view team details and respond (Accept/Decline).'
            ]
        );

        return response()->json([
            'message' => 'User invited to team successfully',
            'team' => $team->load('members')
        ]);
    }

    public function acceptInvitation(Request $request, \App\Models\Team $team)
    {
        /** @var \App\Models\User $user */
        $user = auth('api')->user();
        
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
        /** @var \App\Models\User $user */
        $user = auth('api')->user();
        
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
        /** @var \App\Models\User $user */
        $user = auth('api')->user();
        $isMember = $team->members()->where('user_id', $user->id)->where('status', 'accepted')->exists();

        if (!$isMember) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $allTeamTasks = DB::table('todos')
            ->where('team_id', $team->id)
            ->select('id', 'user_id', 'assigned_emails', 'completed_by', 'is_completed')
            ->get();
            
        $members = $team->members()->wherePivot('status', 'accepted')->get();

        // One pass through all tasks to build a summary map for all members
        // Using a fast PHP array instead of full Eloquent models for calculation
        $memberStats = [];
        foreach ($allTeamTasks as $todo) {
            $assignedEmails = json_decode($todo->assigned_emails ?? '[]', true);
            $completedBy = json_decode($todo->completed_by ?? '[]', true);
            
            $assignedEmails = collect($assignedEmails)->map(fn($e) => strtolower(trim($e)))->unique();
            $completedEmails = collect($completedBy)->map(fn($e) => strtolower(trim((string)$e)))->unique();

            $processedEmailsInThisTask = [];

            // A. Count tasks from assigned_emails
            foreach ($assignedEmails as $email) {
                if (!isset($memberStats[$email])) $memberStats[$email] = ['total' => 0, 'completed' => 0];
                $memberStats[$email]['total']++;
                if ($completedEmails->contains($email)) {
                    $memberStats[$email]['completed']++;
                }
                $processedEmailsInThisTask[] = $email;
            }

            // B. Count tasks from user_id (if not already covered by email assignment)
            if ($todo->user_id) {
                $owner = $members->firstWhere('id', $todo->user_id);
                if ($owner) {
                    $ownerEmail = strtolower(trim($owner->email));
                    if (!in_array($ownerEmail, $processedEmailsInThisTask)) {
                        if (!isset($memberStats[$ownerEmail])) $memberStats[$ownerEmail] = ['total' => 0, 'completed' => 0];
                        $memberStats[$ownerEmail]['total']++;
                        if ($todo->is_completed) {
                            $memberStats[$ownerEmail]['completed']++;
                        }
                    }
                }
            }
        }

        $members = $members->map(function($user) use ($team, $memberStats) {
            $email = strtolower(trim($user->email));
            $stats = $memberStats[$email] ?? ['total' => 0, 'completed' => 0];
            
            $user->progress = $stats['total'] > 0 ? round(($stats['completed'] / $stats['total']) * 100) : 0;
            $user->role = ($user->id === $team->created_by) ? 'Team Leader' : 'Member';
            return $user;
        });

        return response()->json([
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'description' => $team->description,
                'max_members' => $team->max_members,
                'created_by' => $team->created_by,
                'avatar_url' => $team->avatar_url,
                'owner' => $team->owner,
                'members' => $members,
            ],
            // Tasks themselves are still loaded once via Eloquent for JSON response
            'tasks' => $team->todos()->with('user')->latest()->get(),
        ]);
    }

    public function update(Request $request, \App\Models\Team $team)
    {
        if ($team->created_by !== auth('api')->id()) {
            return response()->json(['message' => 'Only owner can update team'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'max_members' => 'nullable|integer|min:1|max:100',
        ]);

        $team->update($request->only('name', 'description', 'max_members'));

        return response()->json([
            'message' => 'Team updated successfully',
            'team' => $team
        ]);
    }

    public function removeMember(Request $request, \App\Models\Team $team, \App\Models\User $user)
    {
        if ($team->created_by !== auth('api')->id()) {
            return response()->json(['message' => 'Only owner can remove members'], 403);
        }

        if ($user->id === $team->created_by) {
            return response()->json(['message' => 'Cannot remove the owner'], 400);
        }

        $team->members()->detach($user->id);

        // Notification
        Notification::create([
            'user_id' => $user->id,
            'type' => 'kick',
            'message' => "You have been removed from team {$team->name}.",
            'team_id' => $team->id,
        ]);

        // Push Notification (Queued)
        SendPushNotification::dispatch(
            $user,
            "Team Updated",
            "You have been removed from team {$team->name}.",
            ['team_id' => (string)$team->id, 'type' => 'kick']
        );

        return response()->json(['message' => 'Member removed successfully']);
    }


    public function updateAvatar(Request $request, \App\Models\Team $team)
    {
        if ($team->created_by !== auth('api')->id()) {
            return response()->json(['message' => 'Only the team owner can change the team photo'], 403);
        }

        $maxSize = $request->file('avatar')->getClientOriginalExtension() === 'gif' ? 2048 : 1024;
        $request->validate([
            'avatar' => "required|image|mimes:jpeg,png,jpg,webp,gif|max:$maxSize",
        ]);

        if ($team->avatar) {
            Storage::disk('public')->delete($team->avatar);
        }

        $path = $request->file('avatar')->store('teams', 'public');
        $team->update(['avatar' => $path]);

        return response()->json([
            'message' => 'Team photo updated successfully',
            'avatar_url' => $team->avatar_url,
        ]);
    }

    public function destroy(Request $request, \App\Models\Team $team)
    {
        /** @var \App\Models\User $user */
        $user = auth('api')->user();
        if ($team->created_by !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $team->delete();

        return response()->json(['message' => 'Team deleted successfully']);
    }
}
