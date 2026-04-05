<?php

namespace App\Http\Controllers;

use App\Models\UserNotificationSetting;
use Illuminate\Http\Request;

class UserNotificationSettingController extends Controller
{
    /**
     * Get user notification settings.
     */
    public function index(Request $request)
    {
        $settings = $request->user()->notificationSetting()->firstOrCreate(
            ['user_id' => $request->user()->id],
            [
                'reminder_days' => [0, 1, 2, 3],
                'reminder_time' => '09:00',
                'vibration' => true,
                'remote_alerts' => true,
            ]
        );

        return response()->json($settings);
    }

    /**
     * Update user notification settings.
     */
    public function update(Request $request)
    {
        $request->validate([
            'reminder_days' => 'required|array',
            'reminder_days.*' => 'integer|min:0|max:7',
            'reminder_time' => 'required|string',
            'vibration' => 'required|boolean',
            'remote_alerts' => 'required|boolean',
        ]);

        $settings = $request->user()->notificationSetting()->updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->only(['reminder_days', 'reminder_time', 'vibration', 'remote_alerts'])
        );

        return response()->json([
            'message' => 'Notification settings updated successfully',
            'data' => $settings
        ]);
    }
}
