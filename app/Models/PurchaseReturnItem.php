<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturnItem extends Model
{
    protected $fillable = ['company_id', 'purchase_return_id', 'stock_item_id', 'quantity', 'unit_cost', 'stock_record_id'];

    protected $casts = ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:2'];
}
