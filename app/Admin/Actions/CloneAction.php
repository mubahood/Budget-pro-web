<?php

namespace App\Admin\Actions;

use Encore\Admin\Actions\RowAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CloneAction extends RowAction
{
    public $name = 'Clone';

    public function handle(Model $model)
    {
        try {
            DB::beginTransaction();
            
            // Create a replica of the model
            $newModel = $model->replicate();
            
            // Update the name to indicate it's a copy
            if (isset($newModel->name)) {
                $newModel->name = $model->name . ' (Copy)';
            }
            
            // Reset timestamps
            $newModel->created_at = now();
            $newModel->updated_at = now();
            
            // Reset calculated fields
            if (method_exists($model, 'getFillable')) {
                $fillable = $model->getFillable();
                if (in_array('current_quantity', $fillable)) {
                    $newModel->current_quantity = 0;
                }
                if (in_array('buying_price', $fillable)) {
                    $newModel->buying_price = 0;
                }
                if (in_array('selling_price', $fillable)) {
                    $newModel->selling_price = 0;
                }
                if (in_array('expected_profit', $fillable)) {
                    $newModel->expected_profit = 0;
                }
                if (in_array('earned_profit', $fillable)) {
                    $newModel->earned_profit = 0;
                }
            }
            
            $newModel->save();
            
            DB::commit();
            
            return $this->response()
                ->success('Item cloned successfully.')
                ->refresh();
                
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->response()->error('Error cloning item: ' . $e->getMessage());
        }
    }

    public function dialog()
    {
        $this->confirm('Clone this item? Financial data will be reset.');
    }

    public function html()
    {
        return '<a class="btn btn-xs btn-warning"><i class="fa fa-copy"></i> Clone</a>';
    }
}
