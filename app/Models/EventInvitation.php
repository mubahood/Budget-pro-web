<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/**
 * One person on an event's invitation log: a card was made for them, and perhaps they were marked as invited.
 * Written by App\Services\Budget\EventInvitations.
 */
class EventInvitation extends Model
{
    use Syncable;

    protected $guarded = ['id'];

    protected $casts = [
        'is_deleted' => 'boolean',
        'cards_made' => 'integer',
        'invited_at' => 'datetime',
        'last_card_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
