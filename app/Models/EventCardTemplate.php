<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/**
 * An invitation card design of an event: its picture (and the PDF it came from, if any) and where and how the
 * invitee's name is printed on it. Written by App\Services\Budget\CardTemplates.
 */
class EventCardTemplate extends Model
{
    use Syncable;

    protected $guarded = ['id'];

    /** Where its files live never leaves the server: they open by the design's id (API) or its route (portal). */
    protected $hidden = ['background_path', 'source_path'];

    protected $casts = [
        'is_deleted' => 'boolean',
        'is_default' => 'boolean',
        'name_y' => 'float',
        'font_size' => 'integer',
        'letter_spacing' => 'integer',
        'max_width' => 'integer',
        'pdf_page' => 'integer',
        'background_w' => 'integer',
        'background_h' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
