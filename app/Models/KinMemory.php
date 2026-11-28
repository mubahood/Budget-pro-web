<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** My Family Roots 360: a story, photos, a voice note or a document, tagged with the people it is about. */
class KinMemory extends Model
{
    use SoftDeletes;

    public const KINDS = ['story', 'photo', 'voice', 'document'];

    protected $table = 'kin_memories';

    protected $guarded = ['id'];

    protected $casts = ['happened_on' => 'date:Y-m-d', 'happened_year' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
