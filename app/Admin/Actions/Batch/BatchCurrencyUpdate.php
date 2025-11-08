<?php

namespace App\Admin\Actions\Batch;

use Encore\Admin\Actions\BatchAction;
use Encore\Admin\Facades\Admin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BatchCurrencyUpdate extends BatchAction
{
    public $name = 'Update Currency Prices';

    public function handle(Collection $collection, Request $request)
    {
        $currency = $request->get('currency');
        $rate = $request->get('exchange_rate');
        $updateType = $request->get('update_type');
        
        if (!$currency || !$rate) {
            return $this->response()->error('Please select currency and enter exchange rate!');
        }
        
        $updated = 0;
        $errors = [];
        
        DB::beginTransaction();
        
        try {
            foreach ($collection as $product) {
                $oldBuying = $product->buying_price;
                $oldSelling = $product->selling_price;
                
                if ($updateType === 'convert_to') {
                    // Convert FROM UGX TO target currency
                    $newBuying = $oldBuying * $rate;
                    $newSelling = $oldSelling * $rate;
                } else {
                    // Convert FROM target currency TO UGX
                    $newBuying = $oldBuying / $rate;
                    $newSelling = $oldSelling / $rate;
                }
                
                // Update prices
                $product->buying_price = $newBuying;
                $product->selling_price = $newSelling;
                $product->save();
                
                // Create audit log
                \App\Models\StockRecord::create([
                    'company_id' => $product->company_id,
                    'created_by_id' => Admin::user()->id,
                    'stock_item_id' => $product->id,
                    'stock_sub_category_id' => $product->stock_sub_category_id,
                    'quantity' => 0,
                    'type' => 'Price Change',
                    'description' => sprintf(
                        'Currency conversion: %s to %s (Rate: %s). Buying: %.2f → %.2f, Selling: %.2f → %.2f',
                        $updateType === 'convert_to' ? 'UGX' : strtoupper($currency),
                        $updateType === 'convert_to' ? strtoupper($currency) : 'UGX',
                        number_format($rate, 4),
                        $oldBuying,
                        $newBuying,
                        $oldSelling,
                        $newSelling
                    ),
                    'created_at' => now(),
                ]);
                
                $updated++;
            }
            
            DB::commit();
            
            return $this->response()->success(
                sprintf(
                    'Successfully updated %d product(s) from %s to %s using rate %.4f',
                    $updated,
                    $updateType === 'convert_to' ? 'UGX' : strtoupper($currency),
                    $updateType === 'convert_to' ? strtoupper($currency) : 'UGX',
                    $rate
                )
            )->refresh();
            
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->response()->error('Failed to update prices: ' . $e->getMessage());
        }
    }

    public function form()
    {
        $this->radio('update_type', 'Conversion Direction')
            ->options([
                'convert_to' => 'Convert FROM UGX TO foreign currency',
                'convert_from' => 'Convert FROM foreign currency TO UGX',
            ])
            ->default('convert_to')
            ->required()
            ->help('Choose the direction of currency conversion');

        $this->select('currency', 'Currency')
            ->options([
                'usd' => 'USD - US Dollar',
                'eur' => 'EUR - Euro',
                'gbp' => 'GBP - British Pound',
                'kes' => 'KES - Kenyan Shilling',
                'tzs' => 'TZS - Tanzanian Shilling',
                'rwf' => 'RWF - Rwandan Franc',
                'zar' => 'ZAR - South African Rand',
                'cny' => 'CNY - Chinese Yuan',
                'inr' => 'INR - Indian Rupee',
                'aed' => 'AED - UAE Dirham',
                'jpy' => 'JPY - Japanese Yen',
                'chf' => 'CHF - Swiss Franc',
                'cad' => 'CAD - Canadian Dollar',
                'aud' => 'AUD - Australian Dollar',
            ])
            ->required()
            ->help('Select the foreign currency');

        $this->text('exchange_rate', 'Exchange Rate')
            ->rules('required|numeric|min:0.0001')
            ->attribute(['type' => 'number', 'step' => '0.0001', 'min' => '0.0001'])
            ->placeholder('e.g., 3700 for USD, 28 for KES')
            ->help('Enter the exchange rate (e.g., 1 UGX = 0.00027 USD means rate is 0.00027)')
            ->required();

        $this->html('
            <div class="alert alert-warning" style="margin-top: 15px;">
                <i class="fa fa-warning"></i> <strong>Warning:</strong> This action will permanently update the prices in the database. Make sure you have the correct exchange rate!
            </div>
            
            <div class="alert alert-info">
                <i class="fa fa-info-circle"></i> <strong>Common Exchange Rates (UGX to Foreign):</strong><br>
                <ul style="margin: 10px 0 0 20px;">
                    <li>1 UGX = 0.00027 USD (or 1 USD = 3,700 UGX)</li>
                    <li>1 UGX = 0.00025 EUR (or 1 EUR = 4,000 UGX)</li>
                    <li>1 UGX = 0.00021 GBP (or 1 GBP = 4,700 UGX)</li>
                    <li>1 UGX = 0.035 KES (or 1 KES = 28 UGX)</li>
                </ul>
                <small><em>Note: These are approximate rates. Please verify with current market rates.</em></small>
            </div>
        ');
    }
}
