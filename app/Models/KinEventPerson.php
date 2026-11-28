<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** One person's answer to a gathering (rsvp going | maybe | no) and their mark in the register (present | apology | absent). */
class KinEventPerson extends Model
{
    public const RSVPS = ['going', 'maybe', 'no'];

    public const ATTENDANCE = ['present', 'apology', 'absent'];

    protected $table = 'kin_event_people';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
