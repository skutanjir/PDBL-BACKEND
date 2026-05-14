<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatConversation extends Model
{
    protected $fillable = ['type', 'team_id'];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function participants()
    {
        return $this->belongsToMany(User::class)->withPivot('last_read_at')->withTimestamps();
    }
}
