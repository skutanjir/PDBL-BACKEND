<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Todo extends Model
{
    use HasFactory;

    protected $fillable = [
        'judul',
        'deskripsi',
        'is_completed',
        'deadline',
        'priority',
        'device_id',
        'user_id',
        'team_id',
        'team_id',
        'assigned_emails',

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'deadline' => 'datetime',
            'assigned_emails' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
}
}