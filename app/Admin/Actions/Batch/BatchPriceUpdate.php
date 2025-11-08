<?php

namespace App\Admin\Actions\Batch;

use Encore\Admin\Actions\BatchAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class BatchPriceUpdate extends BatchAction
{
    public $name = '💰 Update Prices';

    public function handle(Collection $collection, Request $request)
    {
        $updateType = $request->get('update_type');
        $value = $request->get('value');
        
        if (empty($value) || $value == 0) {
            return $this->response()->error('Please enter a valid value')->refresh();
        }

        $count = 0;
        foreach ($collection as $model) {
            if ($updateType == 'percentage_increase') {
                // Increase by percentage
                $increase = ($model->selling_price * $value) / 100;
                $model->selling_price = $model->selling_price + $increase;
            } elseif ($updateType == 'percentage_decrease') {
                // Decrease by percentage
                $decrease = ($model->selling_price * $value) / 100;
                $model->selling_price = max(0, $model->selling_price - $decrease);
            } elseif ($updateType == 'fixed_increase') {
                // Increase by fixed amount
                $model->selling_price = $model->selling_price + $value;
            } elseif ($updateType == 'fixed_decrease') {
                // Decrease by fixed amount
                $model->selling_price = max(0, $model->selling_price - $value);
            } elseif ($updateType == 'set_price') {
                // Set to specific price
                $model->selling_price = $value;
            }
            
            $model->save();
            $count++;
        }

        return $this->response()->success("Successfully updated {$count} product(s) ✅")->refresh();
    }

    public function form()
    {
        $this->select('update_type', 'Update Type')
            ->options([
                'percentage_increase' => 'Increase by Percentage (%)',
                'percentage_decrease' => 'Decrease by Percentage (%)',
                'fixed_increase' => 'Increase by Fixed Amount (UGX)',
                'fixed_decrease' => 'Decrease by Fixed Amount (UGX)',
                'set_price' => 'Set to Specific Price (UGX)',
            ])
            ->rules('required')
            ->default('percentage_increase');

        $this->text('value', 'Value')
            ->rules('required|numeric|min:0')
            ->placeholder('Enter amount or percentage')
            ->attribute(['type' => 'number', 'step' => '0.01']);
    }

    public function html()
    {
        return <<<HTML
        <a class="btn btn-sm btn-warning"><i class="fa fa-money"></i> Update Prices</a>
HTML;
    }
}
