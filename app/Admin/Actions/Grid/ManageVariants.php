<?php

namespace App\Admin\Actions\Grid;

use Encore\Admin\Actions\RowAction;
use Illuminate\Database\Eloquent\Model;

class ManageVariants extends RowAction
{
    public $name = '🎨 Manage Variants';

    public function handle(Model $model)
    {
        // Return redirect to variants management page
        return $this->response()->redirect(admin_url("stock-items/{$model->id}/variants"));
    }

    public function dialog()
    {
        $this->confirm('Manage product variants (colors, sizes, etc.)?');
    }
}
