<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** My Family Roots 360: news for the whole family, perhaps pinned to the top. */
class KinAnnouncement extends Model
{
    use SoftDeletes;

    protected $table = 'kin_announcements';

    protected $guarded = ['id'];

    protected $casts = ['pinned' => 'boolean', 'published_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
