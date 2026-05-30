<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringEvent extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'session_key',
        'event_type',
        'category',
        'source',
        'duration_ms',
        'screen',
        'feature',
        'network_type',
        'offline',
        'payload_size',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'offline' => 'boolean',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
