<?php

namespace App\Admin\Actions\Batch;

use Encore\Admin\Actions\BatchAction;
use Encore\Admin\Facades\Admin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BatchCurrencyUpdate extends BatchAction
{
    public $name = 'Update Currency Prices';

    public function handle(Collection $collection, Request $request)
    {
        $currency = $request->get('currency');
        $rate = $request->get('exchange_rate');
        $updateType = $request->get('update_type');

        if (! $currency || ! $rate) {
            return $this->response()->error('Please select currency and enter exchange rate!');
        }

        $updated = 0;
        $errors = [];

        $base = \App\Support\Money::symbol();

        DB::beginTransaction();

        try {
            foreach ($collection as $product) {
                $oldBuying = $product->buying_price;
                $oldSelling = $product->selling_price;

                if ($updateType === 'convert_to') {
                    // Convert FROM the company currency TO the target currency
                    $newBuying = $oldBuying * $rate;
                    $newSelling = $oldSelling * $rate;
                } else {
                    // Convert FROM the target currency TO the company currency
                    $newBuying = $oldBuying / $rate;
                    $newSelling = $oldSelling / $rate;
                }

                // Update prices
                $product->buying_price = $newBuying;
                $product->selling_price = $newSelling;
                $product->save();

                // Price changes are not stock movements: the StockItem AuditLogger records the old/new prices.
                Log::info('Batch currency conversion applied', [
                    'stock_item_id' => $product->id, 'company_id' => $product->company_id, 'by' => Admin::user()->id,
                    'from' => $updateType === 'convert_to' ? $base : strtoupper($currency),
                    'to' => $updateType === 'convert_to' ? strtoupper($currency) : $base,
                    'rate' => $rate, 'buying' => [$oldBuying, $newBuying], 'selling' => [$oldSelling, $newSelling],
                ]);

                $updated++;
            }

            DB::commit();

            return $this->response()->success(
                sprintf(
                    'Successfully updated %d product(s) from %s to %s using rate %.4f',
                    $updated,
                    $updateType === 'convert_to' ? $base : strtoupper($currency),
                    $updateType === 'convert_to' ? strtoupper($currency) : $base,
                    $rate
                )
            )->refresh();

        } catch (\Exception $e) {
            DB::rollBack();

            return $this->response()->error('Failed to update prices: '.$e->getMessage());
        }
    }

    public function form()
    {
        $this->radio('update_type', 'Conversion Direction')
            ->options([
                'convert_to' => 'Convert FROM '.\App\Support\Money::symbol().' TO foreign currency',
                'convert_from' => 'Convert FROM foreign currency TO '.\App\Support\Money::symbol(),
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
            ->help('Enter the exchange rate (e.g., 1 '.\App\Support\Money::symbol().' = 0.00027 USD means rate is 0.00027)')
            ->required();

        $this->html('
            <div class="alert alert-warning" style="margin-top: 15px;">
                <i class="fa fa-warning"></i> <strong>Warning:</strong> This action will permanently update the prices in the database. Make sure you have the correct exchange rate!
            </div>
            
            <div class="alert alert-info">
                <i class="fa fa-info-circle"></i> <strong>How the rate works:</strong><br>
                <ul>
                    <li>Rate = how much of the foreign currency one unit of your company currency buys.</li>
                    <li>Converting <em>to</em> a foreign currency multiplies prices by the rate; converting <em>from</em> it divides.</li>
                    <li>Check today\'s rate with your bank or mobile money provider before running a batch conversion.</li>
                </ul>
                <small><em>Note: These are approximate rates. Please verify with current market rates.</em></small>
            </div>
        ');
    }
}
