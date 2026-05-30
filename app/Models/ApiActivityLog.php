<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'method',
        'path',
        'route_name',
        'status_code',
        'duration_ms',
        'ip_address',
        'user_agent',
        'action',
        'blocked',
        'rate_limited',
        'suspicious',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'blocked' => 'boolean',
            'rate_limited' => 'boolean',
            'suspicious' => 'boolean',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
