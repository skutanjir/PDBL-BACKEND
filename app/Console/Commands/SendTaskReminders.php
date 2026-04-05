<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Todo;
use App\Services\FirebaseService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendTaskReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:deadlines';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send deadline reminders to users via FCM push notifications based on their settings.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Get all users who have an FCM token and have not disabled remote alerts
        $users = User::whereNotNull('fcm_token')
            ->whereHas('notificationSetting', function($query) {
                $query->where('remote_alerts', true);
            })
            ->with('notificationSetting', 'todos')
            ->get();

        /** @var \App\Models\User $user */
        foreach ($users as $user) {
            $now = Carbon::now($user->timezone ?? config('app.timezone'));
            $settings = $user->notificationSetting;
            if (!$settings || empty($settings->reminder_days)) continue;

            // Simple logic: fetch todos where deadline is in x days
            foreach ($user->todos()->where('is_completed', false)->whereNotNull('deadline')->get() as $todo) {
                try {
                    $deadline = Carbon::parse($todo->deadline);
                    $diffInDays = ceil($now->diffInDays($deadline, false));
                    
                    // If the current difference matches one of the user's reminder settings
                    if (in_array((int)$diffInDays, $settings->reminder_days)) {
                        $relativeText = $diffInDays <= 0 ? 'today' : "$diffInDays days from now";
                        
                        FirebaseService::sendNotification(
                            $user,
                            "Deadline Reminder: {$todo->title}",
                            "This task ends $relativeText! Please complete it soon.",
                            [
                                'type' => 'todo_reminder',
                                'todo_id' => $todo->id,
                                'vibration' => $settings->vibration ? 'true' : 'false'
                            ]
                        );
                    }
                } catch (\Exception $e) {
                    $this->error("Error processing todo {$todo->id}: " . $e->getMessage());
                }
            }
        }

        $this->info('Deadline reminders processed.');
    }
}
