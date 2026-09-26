<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A shelf-edge label waiting to be printed (SUPERMARKET_PLAN.md B6, StoreFeatures `shelf_labels`).
 * Written only through App\Services\Shop\ShelfLabelService.
 */
class LabelQueueItem extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'label_queue';

    protected $guarded = ['id'];

    protected $casts = ['printed_at' => 'datetime'];
}
