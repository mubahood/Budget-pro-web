<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A member in a chat, with how far they have read (App\Services\Chat\ChatService). */
class ChatParticipant extends Model
{
    protected $table = 'chat_participants';

    protected $fillable = ['conversation_id', 'user_id', 'last_read_message_id', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];
}
