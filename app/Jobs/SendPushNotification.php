<?php
// app/Jobs/SendPushNotification.php

namespace App\Jobs;

use App\Models\User;
use App\Services\FirebaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user;
    protected $title;
    protected $body;
    protected $data;

    /**
     * Create a new job instance.
     */
    public function __construct(User $user, string $title, string $body, array $data = [])
    {
        $this->user = $user;
        $this->title = $title;
        $this->body = $body;
        $this->data = $data;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $success = FirebaseService::sendNotification(
                $this->user,
                $this->title,
                $this->body,
                $this->data
            );

            if (!$success) {
                Log::warning("Push notification failed for User ID: {$this->user->id}");
            }
        } catch (\Exception $e) {
            Log::error("Error in SendPushNotification Job: " . $e->getMessage());
            // Fail the job so it can be retried if configured
            throw $e;
        }
    }
}
