<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TeamController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes
Route::apiResource('todos', TodoController::class);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/logout', [AuthController::class, 'logout']);

    // Teams management
    Route::apiResource('teams', TeamController::class);
    Route::post('teams/{team}/invite', [TeamController::class, 'invite']);
    Route::post('teams/{team}/accept', [TeamController::class, 'acceptInvitation']);
    Route::post('teams/{team}/decline', [TeamController::class, 'declineInvitation']);
    Route::delete('teams/{team}/members/{user}', [TeamController::class, 'removeMember']);
    Route::post('teams/{team}/members/{user}/ban', [TeamController::class, 'banMember']);

    // Profile
    Route::post('profile/avatar', [ProfileController::class, 'updateAvatar']);
    Route::post('profile/password', [ProfileController::class, 'updatePassword']);
    Route::post('profile/email', [ProfileController::class, 'updateEmail']);
    Route::post('profile/update', [ProfileController::class, 'updateProfile']);
});
