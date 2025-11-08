<?php

namespace App\Admin\Actions\Grid;

use Encore\Admin\Actions\RowAction;
use Encore\Admin\Facades\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ProcessReturn extends RowAction
{
    public $name = 'Process Return';

    public function handle(Model $model)
    {
        $returnQty = request('return_quantity');
        $reason = request('return_reason');
        $refundAmount = request('refund_amount');
        $notes = request('notes');
        
        if (!$returnQty || $returnQty <= 0) {
            return $this->response()->error('Please enter a valid return quantity!');
        }
        
        if (!$reason) {
            return $this->response()->error('Please select a return reason!');
        }
        
        DB::beginTransaction();
        
        try {
            // Update stock quantity (return back to inventory)
            $model->current_quantity += $returnQty;
            $model->save();
            
            // Create stock record for the return
            \App\Models\StockRecord::create([
                'company_id' => $model->company_id,
                'created_by_id' => Admin::user()->id,
                'stock_item_id' => $model->id,
                'stock_sub_category_id' => $model->stock_sub_category_id,
                'quantity' => $returnQty,
                'type' => 'Return',
                'description' => sprintf(
                    'Product returned: %s. Reason: %s. Refund Amount: UGX %s. Notes: %s',
                    $model->name,
                    $reason,
                    number_format($refundAmount ?? 0, 2),
                    $notes ?? 'N/A'
                ),
                'total_sales' => -($refundAmount ?? 0), // Negative to track refund
                'created_at' => now(),
            ]);
            
            DB::commit();
            
            return $this->response()->success(sprintf(
                'Return processed successfully! %s unit(s) returned to stock. Refund: UGX %s',
                $returnQty,
                number_format($refundAmount ?? 0, 2)
            ))->refresh();
            
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->response()->error('Failed to process return: ' . $e->getMessage());
        }
    }

    public function form()
    {
        $this->text('return_quantity', 'Return Quantity')
            ->rules('required|numeric|min:0.01')
            ->attribute(['type' => 'number', 'step' => '0.01', 'min' => '0.01'])
            ->placeholder('Enter quantity being returned')
            ->required();

        $this->select('return_reason', 'Return Reason')
            ->options([
                'Defective Product' => '🔧 Defective/Damaged Product',
                'Wrong Item' => '❌ Wrong Item Delivered',
                'Customer Changed Mind' => '🔄 Customer Changed Mind',
                'Quality Issues' => '⚠️ Quality Not as Expected',
                'Expired Product' => '📅 Expired or Near Expiry',
                'Size/Fit Issues' => '📏 Size or Fit Issues',
                'Not as Described' => '📋 Not as Described',
                'Duplicate Order' => '📦 Duplicate Order',
                'Supplier Recall' => '🔙 Supplier Recall',
                'Other' => '📝 Other (See Notes)',
            ])
            ->required()
            ->help('Select the reason for return');

        $this->currency('refund_amount', 'Refund Amount (UGX)')
            ->symbol('UGX')
            ->default(0.00)
            ->rules('nullable|numeric|min:0')
            ->help('Enter the refund amount to be given to customer (leave 0 if no refund)');

        $this->textarea('notes', 'Additional Notes')
            ->rows(3)
            ->placeholder('Add any additional details about this return...')
            ->help('Optional notes about the return');

        $this->html('
            <div class="alert alert-info" style="margin-top: 15px;">
                <i class="fa fa-info-circle"></i> <strong>Return Process:</strong><br>
                1. The returned quantity will be <strong>added back</strong> to current stock<br>
                2. A stock record will be created with type "Return"<br>
                3. If refund amount is specified, it will be tracked (as negative sale)<br>
                4. Full audit trail will be maintained
            </div>
        ');
    }

    public function html()
    {
        return '<a class="btn btn-sm btn-warning"><i class="fa fa-undo"></i> Process Return</a>';
    }

    public function dialog()
    {
        $this->confirm('Process return for this product?', 'This will add the quantity back to stock', [
            'confirmButtonText' => 'Process Return',
            'confirmButtonColor' => '#f39c12',
        ]);
    }
}
