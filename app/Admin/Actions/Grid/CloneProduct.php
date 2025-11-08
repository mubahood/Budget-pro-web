<?php

namespace App\Admin\Actions\Grid;

use Encore\Admin\Actions\RowAction;
use Illuminate\Database\Eloquent\Model;

class CloneProduct extends RowAction
{
    public $name = '📋 Clone';

    public function handle(Model $model)
    {
        // Create a copy of the product
        $clone = $model->replicate();
        
        // Generate new unique SKU
        $clone->sku = 'CLONE-' . time() . '-' . rand(1000, 9999);
        
        // Update name to indicate it's a copy
        $clone->name = $model->name . ' (Copy)';
        
        // Reset quantities to 0 for the clone
        $clone->current_quantity = 0;
        $clone->original_quantity = 0;
        
        // Save the cloned product
        $clone->save();

        return $this->response()->success("Product cloned successfully! ✅")->refresh();
    }

    public function dialog()
    {
        $this->confirm('Clone this product?', 'A duplicate will be created with a new SKU.');
    }

    public function html()
    {
        return <<<HTML
        <a class="btn btn-sm btn-info"><i class="fa fa-copy"></i> Clone</a>
HTML;
    }
}
