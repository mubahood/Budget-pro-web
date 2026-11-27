<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: a meeting, event or AGM, of the whole group (chapter_id null) or of a chapter. Rules live in App\Services\Unity. */
class UnityMeeting extends Model
{
    use Syncable;

    protected $table = 'unity_meetings';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'rsvp_open' => 'boolean', 'capacity' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
