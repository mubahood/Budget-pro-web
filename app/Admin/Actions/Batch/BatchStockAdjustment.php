<?php

namespace App\Admin\Actions\Batch;

use Encore\Admin\Actions\BatchAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class BatchStockAdjustment extends BatchAction
{
    public $name = '📦 Adjust Stock';

    public function handle(Collection $collection, Request $request)
    {
        $adjustmentType = $request->get('adjustment_type');
        $quantity = abs($request->get('quantity', 0));
        $reason = $request->get('reason', '');

        if ($quantity <= 0) {
            return $this->response()->error('Please enter a valid quantity!')->refresh();
        }

        $successCount = 0;

        foreach ($collection as $model) {
            try {
                $oldQuantity = $model->current_quantity;
                
                switch ($adjustmentType) {
                    case 'add':
                        $model->current_quantity += $quantity;
                        $description = "Stock increased by {$quantity} units. {$reason}";
                        break;
                        
                    case 'subtract':
                        $model->current_quantity = max(0, $model->current_quantity - $quantity);
                        $description = "Stock decreased by {$quantity} units. {$reason}";
                        break;
                        
                    case 'set':
                        $model->current_quantity = $quantity;
                        $description = "Stock set to {$quantity} units. {$reason}";
                        break;
                        
                    default:
                        continue 2;
                }

                $model->save();

                // Create stock record for audit trail
                $stockRecord = new \App\Models\StockRecord();
                $stockRecord->company_id = $model->company_id;
                $stockRecord->stock_item_id = $model->id;
                $stockRecord->quantity = $model->current_quantity - $oldQuantity;
                $stockRecord->type = 'Stock Adjustment';
                $stockRecord->description = $description;
                $stockRecord->created_by_id = \Encore\Admin\Facades\Admin::user()->id;
                $stockRecord->save();

                $successCount++;
            } catch (\Exception $e) {
                // Continue with other items
            }
        }

        if ($successCount > 0) {
            return $this->response()->success("Successfully adjusted stock for {$successCount} item(s)!")->refresh();
        } else {
            return $this->response()->error('No items were updated!')->refresh();
        }
    }

    public function form()
    {
        $this->select('adjustment_type', 'Adjustment Type')
             ->options([
                 'add' => '➕ Add to Current Stock',
                 'subtract' => '➖ Subtract from Current Stock',
                 'set' => '🎯 Set Exact Stock Level',
             ])
             ->default('add')
             ->required();

        $this->decimal('quantity', 'Quantity')
             ->attribute(['min' => 0, 'step' => 1])
             ->required()
             ->help('Enter the amount to add, subtract, or set');

        $this->textarea('reason', 'Reason (Optional)')
             ->rows(3)
             ->placeholder('e.g., Stock take adjustment, Damaged goods, Restock...');

        $this->html('<div class="alert alert-info" style="margin-top: 15px;">
            <strong><i class="fa fa-info-circle"></i> How it works:</strong>
            <ul style="margin: 10px 0 0 20px;">
                <li><strong>Add:</strong> Increases stock by specified amount (e.g., restock)</li>
                <li><strong>Subtract:</strong> Decreases stock by specified amount (e.g., damaged goods)</li>
                <li><strong>Set:</strong> Sets stock to exact amount (e.g., after physical count)</li>
            </ul>
            <p style="margin-top: 10px;"><strong>Note:</strong> All adjustments are logged for audit purposes.</p>
        </div>');
    }

    public function html()
    {
        return <<<HTML
        <a class="btn btn-sm btn-warning batch-stock-adjustment">
            <i class="fa fa-balance-scale"></i> Adjust Stock
        </a>
HTML;
    }
}
