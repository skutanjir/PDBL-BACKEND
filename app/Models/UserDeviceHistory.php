<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserDeviceHistory extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'ip_address',
        'user_agent',
        'platform',
        'app_version',
        'first_seen_at',
        'last_seen_at',
        'seen_count',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
