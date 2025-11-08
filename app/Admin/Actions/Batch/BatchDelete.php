<?php

namespace App\Admin\Actions\Batch;

use Encore\Admin\Actions\BatchAction;
use Illuminate\Database\Eloquent\Collection;

class BatchDelete extends BatchAction
{
    public $name = '🗑️ Delete Selected';

    public function handle(Collection $collection)
    {
        $count = $collection->count();
        
        foreach ($collection as $model) {
            $model->delete();
        }

        return $this->response()->success("Successfully deleted {$count} product(s) ✅")->refresh();
    }

    public function dialog()
    {
        $this->confirm('Are you sure you want to delete the selected products?', 'This action cannot be undone!');
    }

    public function html()
    {
        return <<<HTML
        <a class="btn btn-sm btn-danger"><i class="fa fa-trash"></i> Delete Selected</a>
HTML;
    }
}
