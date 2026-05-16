<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    protected $fillable = [
        'chat_conversation_id',
        'sender_id',
        'body',
        'mentioned_user_ids',
        'mentions_all',
        'reply_to_id',
        'edited_at',
        'deleted_at',
        'deleted_by_id',
        'delete_reason',
    ];

    protected $casts = [
        'mentioned_user_ids' => 'array',
        'mentions_all' => 'boolean',
        'edited_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo()
    {
        return $this->belongsTo(ChatMessage::class, 'reply_to_id');
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by_id');
    }
}
