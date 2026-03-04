<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TodoController;
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
    Route::apiResource('teams', \App\Http\Controllers\TeamController::class);
    Route::post('teams/{team}/invite', [\App\Http\Controllers\TeamController.class, 'invite']);
});
