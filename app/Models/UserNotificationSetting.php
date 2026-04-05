<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotificationSetting extends Model
{
    protected $fillable = [
        'user_id',
        'reminder_days',
        'reminder_time',
        'vibration',
        'remote_alerts',
    ];

    protected $casts = [
        'reminder_days' => 'array',
        'vibration' => 'boolean',
        'remote_alerts' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
