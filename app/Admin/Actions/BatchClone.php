<?php

namespace App\Admin\Actions;

use Encore\Admin\Actions\BatchAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BatchClone extends BatchAction
{
    protected $entityName;

    public function __construct($entityName = 'Item')
    {
        $this->entityName = $entityName;
        parent::__construct();
    }

    public function name()
    {
        return "Clone Selected {$this->entityName}s";
    }

    public function handle(Collection $collection)
    {
        try {
            DB::beginTransaction();
            
            $cloned = 0;
            foreach ($collection as $model) {
                // Create a replica of the model
                $newModel = $model->replicate();
                
                // Update the name to indicate it's a copy
                if (isset($newModel->name)) {
                    $newModel->name = $model->name . ' (Copy ' . now()->format('Y-m-d H:i') . ')';
                }
                
                // Reset certain fields
                $newModel->created_at = now();
                $newModel->updated_at = now();
                
                // Reset calculated fields if they exist
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
                $cloned++;
            }
            
            DB::commit();
            
            return $this->response()
                ->success("Successfully cloned {$cloned} {$this->entityName}(s).")
                ->refresh();
                
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->response()->error('Error cloning: ' . $e->getMessage());
        }
    }

    public function dialog()
    {
        $this->confirm("Clone selected {$this->entityName}s? This will create duplicates with reset financial data.");
    }
}
