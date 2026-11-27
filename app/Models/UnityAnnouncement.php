<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: a notice on the group's noticeboard, for everyone or for a chapter, region or category. Rules live in App\Services\Unity. */
class UnityAnnouncement extends Model
{
    use Syncable;

    protected $table = 'unity_announcements';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'pinned' => 'boolean', 'published_at' => 'datetime', 'notices_sent' => 'integer', 'sms_sent' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
