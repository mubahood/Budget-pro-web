<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/**
 * One chat message. A deleted message keeps its row (deleted_at set, body and attachment blanked) so
 * the thread can say "This message was deleted"; it is deliberately not a SoftDeletes model.
 */
class ChatMessage extends Model
{
    protected $table = 'chat_messages';

    protected $fillable = ['company_id', 'conversation_id', 'user_id', 'body', 'attachment_path', 'attachment_mime', 'deleted_at'];

    protected $casts = ['deleted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
