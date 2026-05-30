<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\MonitoringController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes
// Public routes with auth throttling
Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/auth/google', [AuthController::class, 'googleLogin']);
    Route::post('/auth/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/auth/resend-verification', [AuthController::class, 'resendVerification']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
});

// Protected routes (Requires Auth)
Route::middleware('throttle:auth')->post('/refresh', [AuthController::class, 'refresh']);

// Protected routes (Requires Auth)
Route::middleware(['auth:api', 'throttle:api'])->group(function () {
    // Auth
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/auth/register-fcm-token', [AuthController::class, 'registerFcmToken']);

    Route::get('/users/check-email', [AuthController::class, 'checkEmail']);
    
    // Teams management
    Route::apiResource('teams', TeamController::class);
    Route::post('/teams/{team}/invite', [TeamController::class, 'invite']);
    
    // Profile
    Route::post('profile/avatar', [ProfileController::class, 'updateAvatar']);
    Route::post('profile/password', [ProfileController::class, 'updatePassword']);
    Route::post('profile/email', [ProfileController::class, 'updateEmail']);
    Route::post('profile/update', [ProfileController::class, 'updateProfile']);
    
    Route::post('/teams/{team}/avatar', [TeamController::class, 'updateAvatar']);
    Route::post('/teams/{team}/accept', [TeamController::class, 'acceptInvitation']);
    Route::post('/teams/{team}/decline', [TeamController::class, 'declineInvitation']);
    Route::delete('/teams/{team}/members/{user}', [TeamController::class, 'removeMember']);
    
    // Team task member toggle
    Route::post('todos/{todo}/toggle-member', [TodoController::class, 'toggleMember']);

    // Notifications
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy']);

    // Chat
    Route::get('chat/conversations', [ChatController::class, 'conversations']);
    Route::get('chat/conversations/{conversation}/messages', [ChatController::class, 'messages']);
    Route::post('chat/conversations/{conversation}/read', [ChatController::class, 'markRead']);
    Route::post('chat/conversations/{conversation}/messages', [ChatController::class, 'send']);
    Route::patch('chat/conversations/{conversation}/messages/{message}', [ChatController::class, 'editMessage']);
    Route::delete('chat/conversations/{conversation}/messages/{message}', [ChatController::class, 'deleteMessage']);
    Route::post('chat/private/{user}', [ChatController::class, 'startPrivate']);

    // WUDI AI Assistant
    Route::get('ai/conversations', [AiController::class, 'conversations'])->middleware('throttle:60,1');
    Route::post('ai/conversations', [AiController::class, 'newConversation'])->middleware('throttle:20,1');
    Route::get('ai/history', [AiController::class, 'history'])->middleware('throttle:60,1');
    Route::post('ai/chat', [AiController::class, 'chat'])->middleware('throttle:120,1');
    Route::post('ai/cancel', [AiController::class, 'cancel'])->middleware('throttle:60,1');

    // Notification Settings
    Route::get('notification-settings', [\App\Http\Controllers\UserNotificationSettingController::class, 'index']);
    Route::post('notification-settings', [\App\Http\Controllers\UserNotificationSettingController::class, 'update']);
});

// Privacy-safe monitoring events from mobile clients, including guest sessions before login.
Route::post('monitoring/events', [MonitoringController::class, 'event'])->middleware('throttle:120,1');

// Private monitoring backend. Keep behind deployment/network controls because it has no normal app login UI.
Route::prefix('monitoring')->middleware('throttle:api')->group(function () {
    Route::get('dashboard', [MonitoringController::class, 'dashboard']);
    Route::get('activity', [MonitoringController::class, 'activity']);
    Route::post('users/{user}/status', [MonitoringController::class, 'updateUserStatus']);
});

// Hybrid routes (Auth OR Device ID for Guests)
Route::middleware(['throttle:api'])->group(function () {
    Route::post('todos/bulk', [TodoController::class, 'bulkStore']);
    Route::apiResource('todos', TodoController::class);
});
