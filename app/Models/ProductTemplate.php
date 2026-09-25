<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A template-pack product (plan C3): platform data, not tenant data. */
class ProductTemplate extends Model
{
    protected $fillable = ['business_type', 'country', 'pack_version', 'name', 'category', 'sub_category', 'unit', 'prices', 'sort_order', 'is_active'];

    protected $casts = ['prices' => 'array', 'is_active' => 'boolean', 'pack_version' => 'integer', 'sort_order' => 'integer'];
}
