<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: one member at one meeting: their RSVP, ticket, and whether they came or sent an apology. Rules live in App\Services\Unity. */
class UnityAttendance extends Model
{
    use Syncable;

    protected $table = 'unity_attendance';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'present' => 'boolean', 'apology' => 'boolean', 'checked_in_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
