<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordOtp extends Model
{
    protected $fillable = [
        'email',
        'otp',
        'type',
        'pending_name',
        'pending_password',
        'expires_at',
    ];

    protected $hidden = [
        'otp',
        'pending_password',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return now()->isAfter($this->expires_at);
    }
}
