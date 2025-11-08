<?php

namespace App\Admin\Actions;

use Encore\Admin\Actions\BatchAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BatchDeactivate extends BatchAction
{
    public $name = 'Deactivate Selected';

    public function handle(Collection $collection)
    {
        try {
            DB::beginTransaction();
            
            foreach ($collection as $model) {
                $model->status = 'Inactive';
                $model->save();
            }
            
            DB::commit();
            
            return $this->response()->success('Selected items have been deactivated successfully.')->refresh();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->response()->error('Error: ' . $e->getMessage());
        }
    }

    public function dialog()
    {
        $this->confirm('Are you sure you want to deactivate the selected items?');
    }
}
