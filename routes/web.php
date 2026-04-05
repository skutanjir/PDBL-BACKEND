<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Fallback to prevent "Route [login] not found" or 404 on redirects
Route::get('/login', function () {
    return response()->json([
        'message' => 'Sesi Anda telah berakhir. Silakan login kembali.',
        'error' => 'Unauthenticated'
    ], 401);
})->name('login');

// Also catch POST /login to root if client hits wrong endpoint
Route::post('/login', function () {
    return response()->json([
        'message' => 'Endpoint ini berpindah ke /api/login. Silakan sesuaikan aplikasi Anda.',
        'error' => 'Not Found'
    ], 404);
});
