<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $teams = $user->teams()->with('owner')->get();
        $ownedTeams = $user->ownedTeams;

        return response()->json([
            'teams' => $teams,
            'owned_teams' => $ownedTeams
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $team = \App\Models\Team::create([
            'name' => $request->name,
            'created_by' => $request->user()->id,
        ]);

        // Creator automatically becomes a member
        $team->members()->attach($request->user()->id);

        return response()->json([
            'message' => 'Team created successfully',
            'team' => $team->load('members')
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

        if ($team->members()->where('user_id', $userToInvite->id)->exists()) {
            return response()->json(['message' => 'User is already a member of this team'], 422);
        }

        $team->members()->attach($userToInvite->id);

        return response()->json([
            'message' => 'User invited to team successfully',
            'team' => $team->load('members')
        ]);
    }

    public function show(\App\Models\Team $team)
    {
        return response()->json([
            'team' => $team->load(['members', 'todos.user'])
        ]);
    }

    public function destroy(Request $request, \App\Models\Team $team)
    {
        if ($team->created_by !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $team->delete();

        return response()->json(['message' => 'Team deleted successfully']);
}
