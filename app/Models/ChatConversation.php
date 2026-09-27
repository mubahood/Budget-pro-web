<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** A team chat: `direct` between two members of one shop, or the shop's `team` group (App\Services\Chat\ChatService). */
class ChatConversation extends Model
{
    protected $table = 'chat_conversations';

    protected $fillable = ['company_id', 'type', 'direct_key', 'title', 'last_message_at'];

    protected $casts = ['last_message_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function isTeam(): bool
    {
        return $this->type === 'team';
    }

    public function participants()
    {
        return $this->hasMany(ChatParticipant::class, 'conversation_id');
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }
}
