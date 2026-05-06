<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\PasswordOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Mail\OtpMail;
use App\Mail\VerificationMail;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenBlacklistedException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $existingUser = User::where('email', $request->email)->first();
        if ($existingUser && !is_null($existingUser->email_verified_at)) {
            throw ValidationException::withMessages([
                'email' => ['The email has already been taken.'],
            ]);
        }

        if ($existingUser && !is_null($existingUser->google_id)) {
            throw ValidationException::withMessages([
                'email' => ['This email is already registered with Google Sign-In. Please use the "Continue with Google" button.'],
            ]);
        }

        // Registration stays pending until the OTP is verified. Do not create
        // a users row here, so unverified accounts never enter the users table.
        $otp = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($request, $existingUser, $otp) {
            // Clean up legacy rows created by the old register-before-verify flow.
            if ($existingUser && is_null($existingUser->email_verified_at)) {
                $existingUser->delete();
            }

            PasswordOtp::where('email', $request->email)
                ->where('type', 'email_verification')
                ->delete();

            PasswordOtp::create([
                'email'            => $request->email,
                'otp'              => $otp,
                'type'             => 'email_verification',
                'pending_name'     => $request->name,
                'pending_password' => Crypt::encryptString($request->password),
                'expires_at'       => now()->addMinutes(30),
            ]);
        });

        $this->sendVerificationMailAfterResponse($request->email, $otp, $request->name);

        return response()->json([
            'message' => 'Registration successful. Please check your email to verify your account.',
            'email'   => $request->email,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $credentials = $request->only('email', 'password');

        $existingUser = User::where('email', $request->email)->first();

        // Account registered via Google (no password)
        if ($existingUser && is_null($existingUser->password)) {
            throw ValidationException::withMessages([
                'email' => ['This account was created with Google Sign-In. Please use the "Continue with Google" button to log in.'],
            ]);
        }

        // Account exists but email not verified yet — return special 403
        if ($existingUser && is_null($existingUser->email_verified_at)) {
            if (!Hash::check($request->password, $existingUser->password)) {
                throw ValidationException::withMessages([
                    'email' => ['Invalid email or password.'],
                ]);
            }

            $this->refreshVerificationOtp($existingUser->email, $existingUser->name);

            return response()->json([
                'status'  => 'email_not_verified',
                'message' => 'Please verify your email address before logging in. We sent a new verification code.',
                'email'   => $existingUser->email,
            ], 403);
        }

        $pendingRegistration = PasswordOtp::where('email', $request->email)
            ->where('type', 'email_verification')
            ->whereNotNull('pending_name')
            ->whereNotNull('pending_password')
            ->latest()
            ->first();

        if (!$existingUser && $pendingRegistration) {
            try {
                $pendingPassword = Crypt::decryptString($pendingRegistration->pending_password);
            } catch (DecryptException $e) {
                $pendingRegistration->delete();
                throw ValidationException::withMessages([
                    'email' => ['Registration data is invalid. Please register again.'],
                ]);
            }

            if (!hash_equals($pendingPassword, $request->password)) {
                throw ValidationException::withMessages([
                    'email' => ['Invalid email or password.'],
                ]);
            }

            $this->refreshVerificationOtp($pendingRegistration->email, $pendingRegistration->pending_name, $pendingRegistration);

            return response()->json([
                'status'  => 'email_not_verified',
                'message' => 'Please verify your email address before logging in. We sent a new verification code.',
                'email'   => $pendingRegistration->email,
            ], 403);
        }

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
                'message' => 'Invalid email format.',
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
            'message' => 'No account found with that email address.',
        ], 404);
    }

    // ─── Google Sign-In ──────────────────────────────────────────────────────

    public function googleLogin(Request $request)
    {
        $request->validate([
            'google_id'  => 'required|string',
            'email'      => 'required|email|string',
            'name'       => 'required|string',
            'avatar_url' => 'nullable|string',
        ]);

        $user = User::where('google_id', $request->google_id)
            ->orWhere('email', $request->email)
            ->first();

        $accountConverted = false;

        if ($user) {
            // Detect a regular-account user signing in with Google for the first time
            $accountConverted = is_null($user->google_id) && !is_null($user->password);

            $updateData = [
                'google_id'         => $request->google_id,
                'name'              => $user->name ?: $request->name,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ];

            // Convert to Google-only: wipe password so email/password login is blocked
            if ($accountConverted) {
                $updateData['password'] = null;
                // Also delete any pending OTPs for this account
                PasswordOtp::where('email', $user->email)->delete();
            }

            $user->fill($updateData)->save();
        } else {
            $user = User::create([
                'name'              => $request->name,
                'email'             => $request->email,
                'google_id'         => $request->google_id,
                'password'          => null,
                'email_verified_at' => now(),
            ]);
        }

        $token = JWTAuth::fromUser($user);

        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;
        if ($deviceId) {
            \App\Models\Todo::where('device_id', $deviceId)
                ->whereNull('user_id')
                ->update(['user_id' => $user->id, 'device_id' => null]);
        }

        return response()->json([
            'message'           => 'Google login successful',
            'user'              => $user,
            'token'             => $token,
            'account_converted' => $accountConverted,
        ]);
    }

    // ─── Email Verification ──────────────────────────────────────────────────

    public function verifyEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email|string',
            'otp'   => 'required|string|size:4',
        ]);

        // Rate limit: max 5 wrong attempts per 10 minutes
        $attemptKey = 'verify_email_attempts:' . $request->email;
        $attempts   = Cache::get($attemptKey, 0);
        if ($attempts >= 5) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Too many failed attempts. Please request a new code.',
            ], 429);
        }

        $record = PasswordOtp::where('email', $request->email)
            ->where('otp', $request->otp)
            ->where('type', 'email_verification')
            ->latest()
            ->first();

        if (!$record) {
            Cache::put($attemptKey, $attempts + 1, now()->addMinutes(10));
            return response()->json(['status' => 'error', 'message' => 'Invalid verification code.'], 422);
        }

        if ($record->isExpired()) {
            $record->delete();
            return response()->json(['status' => 'error', 'message' => 'Verification code has expired. Please request a new one.'], 422);
        }

        try {
            $user = DB::transaction(function () use ($request, $record) {
                $user = User::where('email', $request->email)->lockForUpdate()->first();

                if ($user) {
                    $user->update(['email_verified_at' => now()]);
                    $record->delete();

                    return $user;
                }

                if (!$record->pending_name || !$record->pending_password) {
                    $record->delete();
                    throw ValidationException::withMessages([
                        'email' => ['Registration data has expired. Please register again.'],
                    ]);
                }

                $user = User::create([
                    'name'              => $record->pending_name,
                    'email'             => $record->email,
                    'password'          => Crypt::decryptString($record->pending_password),
                    'email_verified_at' => now(),
                ]);

                $record->delete();

                return $user;
            });
        } catch (DecryptException $e) {
            $record->delete();
            return response()->json([
                'status'  => 'error',
                'message' => 'Registration data is invalid. Please register again.',
            ], 422);
        }

        Cache::forget($attemptKey);

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'status'  => 'success',
            'message' => 'Email verified successfully.',
            'user'    => $user,
            'token'   => $token,
        ]);
    }

    public function resendVerification(Request $request)
    {
        $request->validate(['email' => 'required|email|string']);

        $user = User::where('email', $request->email)->first();
        $pendingRegistration = PasswordOtp::where('email', $request->email)
            ->where('type', 'email_verification')
            ->whereNotNull('pending_name')
            ->whereNotNull('pending_password')
            ->latest()
            ->first();

        if (!$user && !$pendingRegistration) {
            return response()->json(['status' => 'error', 'message' => 'Email not found.'], 404);
        }

        if ($user && !is_null($user->email_verified_at)) {
            return response()->json(['status' => 'success', 'message' => 'Email is already verified.']);
        }

        // Google users are auto-verified (Google already verified their email)
        if ($user && !is_null($user->google_id)) {
            $user->update(['email_verified_at' => now()]);
            return response()->json(['status' => 'success', 'message' => 'Email verified via Google.']);
        }

        $email = $user ? $user->email : $pendingRegistration->email;
        $name = $user ? $user->name : $pendingRegistration->pending_name;

        // Rate limit: max 3 resends per 10 minutes per email
        $countKey   = 'verify_resend_count:' . $email;
        $cooldownKey = 'verify_resend_cooldown:' . $email;

        if (Cache::has($cooldownKey)) {
            $seconds = Cache::get($cooldownKey);
            return response()->json([
                'status'  => 'error',
                'message' => "Please wait {$seconds} seconds before requesting another code.",
                'retry_after' => $seconds,
            ], 429);
        }

        $count = Cache::get($countKey, 0);
        if ($count >= 3) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Too many attempts. Please wait 10 minutes before requesting a new code.',
                'retry_after' => 600,
            ], 429);
        }

        Cache::put($countKey, $count + 1, now()->addMinutes(10));
        Cache::put($cooldownKey, 60, now()->addSeconds(60));

        $otp = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        PasswordOtp::where('email', $email)->where('type', 'email_verification')->delete();
        PasswordOtp::create([
            'email'            => $email,
            'otp'              => $otp,
            'type'             => 'email_verification',
            'pending_name'     => $pendingRegistration ? $pendingRegistration->pending_name : null,
            'pending_password' => $pendingRegistration ? $pendingRegistration->pending_password : null,
            'expires_at'       => now()->addMinutes(30),
        ]);
        $this->sendVerificationMailAfterResponse($email, $otp, $name);

        return response()->json([
            'status'  => 'success',
            'message' => 'Verification code resent.',
            'retry_after' => 60,
        ]);
    }

    // ─── Forgot Password (OTP via Gmail) ────────────────────────────────────

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|string',
        ]);

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Email address not found.',
            ], 404);
        }

        if (!is_null($user->google_id)) {
            return response()->json([
                'status'  => 'google_account',
                'message' => 'Sorry, you can\'t reset your password. You signed in with Google.',
            ], 403);
        }

        if (is_null($user->email_verified_at)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Please verify your email address first before resetting your password.',
            ], 403);
        }

        // Rate limit: 60s cooldown between resends, max 5 per hour
        $cooldownKey = 'forgot_pw_cooldown:' . $user->email;
        $countKey    = 'forgot_pw_count:' . $user->email;

        if (Cache::has($cooldownKey)) {
            return response()->json([
                'status'      => 'error',
                'message'     => 'Please wait 60 seconds before requesting another code.',
                'retry_after' => 60,
            ], 429);
        }

        $count = Cache::get($countKey, 0);
        if ($count >= 5) {
            return response()->json([
                'status'      => 'error',
                'message'     => 'Too many attempts. Please wait 1 hour before trying again.',
                'retry_after' => 3600,
            ], 429);
        }

        Cache::put($cooldownKey, true, now()->addSeconds(60));
        Cache::put($countKey, $count + 1, now()->addHour());

        // Generate 4-digit OTP
        $otp = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        // Delete old password-reset OTPs for this email
        PasswordOtp::where('email', $request->email)->where('type', 'password_reset')->delete();

        // Store new OTP (expires in 30 minutes, single-use)
        PasswordOtp::create([
            'email'      => $request->email,
            'otp'        => $otp,
            'type'       => 'password_reset',
            'expires_at' => now()->addMinutes(30),
        ]);

        // Send OTP via HTML email template
        Mail::to($request->email)->send(new OtpMail($otp, $user->name));

        return response()->json([
            'status'  => 'success',
            'message' => 'OTP has been sent to your email.',
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|string',
            'otp'   => 'required|string|size:4',
        ]);

        $record = PasswordOtp::where('email', $request->email)
            ->where('otp', $request->otp)
            ->where('type', 'password_reset')
            ->latest()
            ->first();

        if (!$record) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid OTP code.',
            ], 422);
        }

        if ($record->isExpired()) {
            $record->delete();
            return response()->json([
                'status'  => 'error',
                'message' => 'OTP code has expired. Please request a new one.',
            ], 422);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'OTP verified successfully.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'                 => 'required|email|string',
            'otp'                   => 'required|string|size:4',
            'password'              => 'required|string|min:8|confirmed',
        ]);

        $record = PasswordOtp::where('email', $request->email)
            ->where('otp', $request->otp)
            ->where('type', 'password_reset')
            ->latest()
            ->first();

        if (!$record || $record->isExpired()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'OTP is invalid or has expired.',
            ], 422);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'User not found.',
            ], 404);
        }

        $user->update(['password' => Hash::make($request->password)]);
        $record->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Password updated successfully. Please log in.',
        ]);
    }

    private function sendVerificationMailAfterResponse(string $email, string $otp, string $name): void
    {
        app()->terminating(function () use ($email, $otp, $name) {
            try {
                Mail::to($email)->send(new VerificationMail($otp, $name));
            } catch (\Exception $e) {
                Log::error('Failed to send verification email to ' . $email . ': ' . $e->getMessage());
            }
        });
    }

    private function refreshVerificationOtp(string $email, string $name, ?PasswordOtp $pendingRegistration = null): void
    {
        $otp = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        PasswordOtp::where('email', $email)
            ->where('type', 'email_verification')
            ->delete();

        PasswordOtp::create([
            'email'            => $email,
            'otp'              => $otp,
            'type'             => 'email_verification',
            'pending_name'     => $pendingRegistration ? $pendingRegistration->pending_name : null,
            'pending_password' => $pendingRegistration ? $pendingRegistration->pending_password : null,
            'expires_at'       => now()->addMinutes(30),
        ]);

        $this->sendVerificationMailAfterResponse($email, $otp, $name);
    }
}
