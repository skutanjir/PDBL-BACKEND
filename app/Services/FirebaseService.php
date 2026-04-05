<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\AndroidConfig;

class FirebaseService
{
    /**
     * Send a push notification to a specific user.
     *
     * @param User $user
     * @param string $title
     * @param string $body
     * @param array $data
     * @return bool
     */
    public static function sendNotification(User $user, string $title, string $body, array $data = [])
    {
        if (!$user || !$user->fcm_token) {
            Log::warning("FCM: User has no token.");
            return false;
        }

        // 1. Log to database for record keeping
        try {
            \App\Models\Notification::create([
                'user_id' => $user->id,
                'message' => $body,
                'type' => $data['type'] ?? 'update',
                'team_id' => $data['team_id'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Notification Database Logging Error: ' . $e->getMessage());
        }

        // 2. Send real-time push via FCM HTTP v1
        try {
            $credPath = env('FIREBASE_CREDENTIALS');
            
            // Robust path resolution
            $fullPath = null;
            if ($credPath) {
                $possiblePaths = [
                    base_path($credPath),
                    storage_path('app/' . basename($credPath)),
                    $credPath // Absolute path fallback
                ];
                
                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        $fullPath = realpath($path);
                        break;
                    }
                }
            }

            if (!$fullPath) {
                // Last ditch effort: search for any json file in storage/app starting with 'teka-teki'
                $files = glob(storage_path('app/teka-teki-*.json'));
                if (!empty($files)) {
                    $fullPath = $files[0];
                }
            }

            if (!$fullPath) {
                throw new \Exception("FIREBASE_CREDENTIALS file not found. Check .env or storage/app/");
            }

            // Credential path resolved
            $factory = (new Factory)
                ->withServiceAccount($fullPath);
            $messaging = $factory->createMessaging();

            $message = CloudMessage::withTarget('token', $user->fcm_token)
                ->withNotification(Notification::create($title, $body))
                ->withData(array_map('strval', $data)) 
                ->withAndroidConfig([
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => 'high_importance_channel_v2',
                        'sound' => 'default',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                        'visibility' => 'public',
                    ],
                ]);

            $messaging->send($message);
            
            return true;
        } catch (\Exception $e) {
            Log::error("FCM Send Failure for User ID {$user->id}: " . $e->getMessage());
            
            if (str_contains($e->getMessage(), 'unregistered') || str_contains($e->getMessage(), 'not found')) {
                Log::warning("FCM Warning: User ID {$user->id} token is stale. Re-login required.");
            }
            
            return false;
        }
    }
}
