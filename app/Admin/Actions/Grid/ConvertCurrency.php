<?php

namespace App\Admin\Actions\Grid;

use Encore\Admin\Actions\RowAction;
use Encore\Admin\Facades\Admin;
use Illuminate\Database\Eloquent\Model;

class ConvertCurrency extends RowAction
{
    public $name = 'Convert Currency';

    public function handle(Model $model)
    {
        $currency = request('currency');
        $rate = request('exchange_rate');
        
        if (!$currency || !$rate) {
            return $this->response()->error('Please select currency and enter exchange rate!');
        }
        
        // Calculate converted prices
        $convertedBuyingPrice = $model->buying_price * $rate;
        $convertedSellingPrice = $model->selling_price * $rate;
        
        // Create HTML response with conversion details
        $html = '
        <div style="padding: 20px;">
            <h4 style="color: #00c0ef; margin-top: 0;">
                <i class="fa fa-exchange"></i> Currency Conversion Result
            </h4>
            
            <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin: 15px 0;">
                <h5 style="margin-top: 0;">Product: ' . htmlspecialchars($model->name) . '</h5>
                
                <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                    <tr style="background: #00c0ef; color: white;">
                        <th style="padding: 10px; text-align: left;">Description</th>
                        <th style="padding: 10px; text-align: right;">UGX (Original)</th>
                        <th style="padding: 10px; text-align: right;">' . strtoupper($currency) . ' (Converted)</th>
                    </tr>
                    <tr style="background: #fff;">
                        <td style="padding: 10px; border: 1px solid #ddd;"><strong>Exchange Rate</strong></td>
                        <td style="padding: 10px; border: 1px solid #ddd; text-align: right;">1.00</td>
                        <td style="padding: 10px; border: 1px solid #ddd; text-align: right;">' . number_format($rate, 4) . '</td>
                    </tr>
                    <tr style="background: #f9f9f9;">
                        <td style="padding: 10px; border: 1px solid #ddd;"><strong>Cost Price</strong></td>
                        <td style="padding: 10px; border: 1px solid #ddd; text-align: right;">UGX ' . number_format($model->buying_price, 2) . '</td>
                        <td style="padding: 10px; border: 1px solid #ddd; text-align: right;">' . strtoupper($currency) . ' ' . number_format($convertedBuyingPrice, 2) . '</td>
                    </tr>
                    <tr style="background: #fff;">
                        <td style="padding: 10px; border: 1px solid #ddd;"><strong>Selling Price</strong></td>
                        <td style="padding: 10px; border: 1px solid #ddd; text-align: right;">UGX ' . number_format($model->selling_price, 2) . '</td>
                        <td style="padding: 10px; border: 1px solid #ddd; text-align: right;">' . strtoupper($currency) . ' ' . number_format($convertedSellingPrice, 2) . '</td>
                    </tr>
                    <tr style="background: #e8f5e9;">
                        <td style="padding: 10px; border: 1px solid #ddd;"><strong>Profit Margin</strong></td>
                        <td style="padding: 10px; border: 1px solid #ddd; text-align: right;">UGX ' . number_format($model->selling_price - $model->buying_price, 2) . '</td>
                        <td style="padding: 10px; border: 1px solid #ddd; text-align: right;">' . strtoupper($currency) . ' ' . number_format($convertedSellingPrice - $convertedBuyingPrice, 2) . '</td>
                    </tr>
                </table>
                
                <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">
                    <i class="fa fa-info-circle"></i> <strong>Note:</strong> This is a preview only. To permanently save these converted prices, use the <strong>"Update Currency Prices"</strong> batch action on multiple products.
                </div>
                
                <div style="margin-top: 15px; padding: 15px; background: #d1ecf1; border-left: 4px solid #17a2b8; border-radius: 4px;">
                    <i class="fa fa-lightbulb-o"></i> <strong>Tip:</strong> Common exchange rates:<br>
                    - USD: ~3,700<br>
                    - EUR: ~4,000<br>
                    - GBP: ~4,700<br>
                    - KES: ~28<br>
                    (Rates are approximate and should be updated based on current market rates)
                </div>
            </div>
        </div>
        ';
        
        return $this->response()->html($html);
    }

    public function form()
    {
        $this->select('currency', 'Target Currency')
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
            ])
            ->required()
            ->help('Select the currency to convert to');

        $this->text('exchange_rate', 'Exchange Rate')
            ->rules('required|numeric|min:0.0001')
            ->attribute(['type' => 'number', 'step' => '0.0001', 'min' => '0.0001'])
            ->placeholder('e.g., 3700 for USD, 28 for KES')
            ->help('Enter 1 UGX = X target currency (e.g., 1 UGX = 0.00027 USD means rate is 3700)')
            ->required();
    }

    public function html()
    {
        return '<a class="btn btn-sm btn-info"><i class="fa fa-exchange"></i> Convert Currency</a>';
    }

    public function dialog()
    {
        $this->confirm('View currency conversion for this product?', '', [
            'confirmButtonText' => 'Convert',
            'confirmButtonColor' => '#00c0ef',
        ]);
    }
}
