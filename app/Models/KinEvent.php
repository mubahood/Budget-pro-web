<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** My Family Roots 360: a meeting or family gathering, with its agenda, minutes and perhaps a linked contribution. */
class KinEvent extends Model
{
    public const KINDS = ['meeting', 'gathering', 'wedding', 'burial', 'introduction', 'other'];

    public const STATUSES = ['planned', 'held', 'cancelled'];

    protected $table = 'kin_events';

    protected $guarded = ['id'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
