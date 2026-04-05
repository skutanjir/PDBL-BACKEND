<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenBlacklistedException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = JWTAuth::fromUser($user);

        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;
        if ($deviceId) {
            \App\Models\Todo::where('device_id', $deviceId)
                ->whereNull('user_id')
                ->update(['user_id' => $user->id, 'device_id' => null]);
        }
        return response()->json([
            'message' => 'Registration successful',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $credentials = $request->only('email', 'password');

        /** @var \PHPOpenSourceSaver\JWTAuth\JWTGuard $guard */
        $guard = auth('api');

        if (! $token = $guard->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        $user = auth('api')->user();

        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;
        if ($deviceId) {
            \App\Models\Todo::where('device_id', $deviceId)
                ->whereNull('user_id')
                ->update(['user_id' => $user->id, 'device_id' => null]);
        }
        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Get the authenticated User.
     * Also claims any orphaned tasks matching the device_id.
     */
    public function user(Request $request)
    {
        $user = auth('api')->user();
        
        // Claim orphaned tasks if device_id is provided
        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;
        if ($user && $deviceId) {
            \App\Models\Todo::where('device_id', $deviceId)
                ->whereNull('user_id')
                ->update(['user_id' => $user->id, 'device_id' => null]);
        }

        return response()->json($user);
    }

    public function logout(Request $request)
    {
        /** @var \PHPOpenSourceSaver\JWTAuth\JWTGuard $guard */
        $guard = auth('api');
        $guard->logout();

        return response()->json([
            'message' => 'Logout successful',
        ]);
    }

    public function registerFcmToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        /** @var User $user */
        $user = User::find(auth('api')->id());
        if ($user) {
            $user->update(['fcm_token' => $request->token]);
            return response()->json(['message' => 'FCM Token registered successfully']);
        }

        return response()->json(['message' => 'User not found'], 404);
    }

    /**
     * Refresh the JWT token.
     *
     * Uses an idempotency cache keyed by the old token's JTI to handle
     * concurrent requests that arrive while the original token is inside
     * the blacklist grace period. The cache TTL MUST be >= the configured
     * `jwt.blacklist_grace_period` to prevent a window where the old token
     * is blacklisted but the cached new-token has already expired.
     */
    public function refresh(Request $request)
    {
        // Cache TTL aligned with blacklist grace period (+ 30s safety margin)
        $gracePeriod = (int) config('jwt.blacklist_grace_period', 30);
        $cacheTtl = $gracePeriod + 30;

        $oldJti = null;

        try {
            $tokenHeader = $request->header('Authorization') ?? $request->header('authorization');
            if ($tokenHeader && str_starts_with($tokenHeader, 'Bearer ')) {
                $token = substr($tokenHeader, 7);
                if (trim($token) === '') {
                    throw new \Exception("Token payload is empty");
                }

                // Extract JTI safely even if the token is blacklisted
                try {
                    $payload = JWTAuth::setToken($token)->getPayload();
                    $oldJti = $payload->get('jti');
                    
                    // IDEMPOTENCY CHECK: If we already refreshed this token recently, return cached result
                    if ($oldJti && Cache::has('refresh_jti_' . $oldJti)) {
                        $cachedToken = Cache::get('refresh_jti_' . $oldJti);
                        Log::info("Idempotent refresh hit for JTI: {$oldJti}");
                        return response()->json([
                            'status' => 'success',
                            'message' => 'Token (cached) successfully updated',
                            'token' => $cachedToken,
                        ]);
                    }
                } catch (TokenBlacklistedException $e) {
                    // Token is blacklisted — try to decode JTI from raw payload
                    // to check the idempotency cache before giving up
                    try {
                        $parts = explode('.', $token);
                        if (count($parts) === 3) {
                            $payload = json_decode(base64_decode($parts[1]), true);
                            $oldJti = $payload['jti'] ?? null;
                        }
                    } catch (\Exception $ex) {
                        // Malformed token, cannot extract JTI
                    }

                    if ($oldJti && Cache::has('refresh_jti_' . $oldJti)) {
                        $cachedToken = Cache::get('refresh_jti_' . $oldJti);
                        Log::info("Idempotent refresh hit (Blacklisted) for JTI: {$oldJti}");
                        return response()->json([
                            'status' => 'success',
                            'message' => 'Token (cached) successfully updated',
                            'token' => $cachedToken,
                        ]);
                    }
                    throw $e; // Re-throw if not in cache
                }
            }

            // Perform the actual refresh
            $newToken = JWTAuth::refresh();

            // Verify the user still exists after refresh
            $user = JWTAuth::setToken($newToken)->authenticate();
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Account not found. Please login again.',
                ], 401);
            }

            // Store in cache for grace period + safety margin
            if ($oldJti) {
                Cache::put('refresh_jti_' . $oldJti, $newToken, $cacheTtl);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Token successfully updated',
                'token' => $newToken,
            ]);
        } catch (TokenBlacklistedException $e) {
            Log::warning('Refresh failed: Token already blacklisted and no cache record found.');
            return response()->json([
                'status' => 'error',
                'message' => 'Token is invalid (already updated). Please login again.',
            ], 401);
        } catch (\Exception $e) {
            Log::error('Refresh error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update token: ' . $e->getMessage(),
            ], 401);
        }
    }
    
    public function checkEmail(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email|string|max:255',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Format email tidak valid.',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if ($user) {
            return response()->json([
                'status' => 'success',
                'exists' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar_url, // Use accessor if it exists
                ],
            ]);
        }

        return response()->json([
            'status' => 'error',
            'exists' => false,
            'message' => 'User dengan email tersebut tidak ditemukan.',
        ], 404);
    }
}
