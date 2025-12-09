<?php

namespace App\Admin\Actions\Batch;

use Encore\Admin\Actions\BatchAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class BatchStatusUpdate extends BatchAction
{
    public $name = '🔄 Update Status';

    public function form()
    {
        $this->select('status', 'New Status')
            ->options([
                'Active' => '✅ Active',
                'Inactive' => '❌ Inactive',
                'Discontinued' => '🚫 Discontinued',
                'Out of Stock' => '📦 Out of Stock',
                'Coming Soon' => '🔜 Coming Soon',
                'On Sale' => '💰 On Sale',
                'Clearance' => '🏷️ Clearance',
            ])
            ->rules('required')
            ->help('Select the new status for selected products');

        $this->textarea('reason', 'Reason for Status Change')
            ->rows(3)
            ->placeholder('Optional: Explain why you are changing the status...');
    }

    public function handle(Collection $collection, Request $request)
    {
        $newStatus = $request->get('status');
        $reason = $request->get('reason');

        $updated = 0;
        foreach ($collection as $model) {
            $oldStatus = $model->status ?? 'Unknown';

            // Update status
            $model->status = $newStatus;
            $model->save();

            // Create audit log
            $stockRecord = new \App\Models\StockRecord();
            $stockRecord->stock_item_id = $model->id;
            $stockRecord->quantity = 0; // No quantity change
            $stockRecord->type = 'Status Change';
            $stockRecord->description = "Status changed from '{$oldStatus}' to '{$newStatus}'".
                                       ($reason ? ". Reason: {$reason}" : '');
            $stockRecord->created_by = admin_toastr()->user()->id ?? 1;
            $stockRecord->company_id = $model->company_id;
            $stockRecord->save();

            $updated++;
        }

        return $this->response()->success("✅ Successfully updated status for {$updated} product(s) to '{$newStatus}'")->refresh();
    }
}
