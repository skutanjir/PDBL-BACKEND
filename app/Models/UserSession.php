<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSession extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'session_key',
        'platform',
        'app_version',
        'timezone',
        'locale',
        'network_type',
        'ip_address',
        'user_agent',
        'started_at',
        'last_seen_at',
        'ended_at',
        'duration_seconds',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
