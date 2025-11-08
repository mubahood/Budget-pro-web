<?php

namespace App\Admin\Actions;

use Encore\Admin\Actions\BatchAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BatchActivate extends BatchAction
{
    public $name = 'Activate Selected';

    public function handle(Collection $collection)
    {
        try {
            DB::beginTransaction();
            
            foreach ($collection as $model) {
                $model->status = 'Active';
                $model->save();
            }
            
            DB::commit();
            
            return $this->response()->success('Selected items have been activated successfully.')->refresh();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->response()->error('Error: ' . $e->getMessage());
        }
    }

    public function dialog()
    {
        $this->confirm('Are you sure you want to activate the selected items?');
    }
}
