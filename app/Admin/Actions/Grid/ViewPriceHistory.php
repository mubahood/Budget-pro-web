<?php

namespace App\Admin\Actions\Grid;

use Encore\Admin\Actions\RowAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ViewPriceHistory extends RowAction
{
    public $name = '💰 Price History';

    public function handle(Model $model)
    {
        // Get price change history
        $history = DB::table('stock_records')
            ->where('stock_item_id', $model->id)
            ->where('type', 'Price Change')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        $html = '<div style="max-height: 400px; overflow-y: auto;">';
        $html .= '<h4>💰 Price Change History</h4>';
        
        if ($history->isEmpty()) {
            $html .= '<p style="color: #999; text-align: center; padding: 20px;">No price changes recorded yet.</p>';
        } else {
            $html .= '<table class="table table-striped" style="margin: 0;">';
            $html .= '<thead><tr>';
            $html .= '<th>Date</th>';
            $html .= '<th>Old Price</th>';
            $html .= '<th>New Price</th>';
            $html .= '<th>Change</th>';
            $html .= '<th>Reason</th>';
            $html .= '</tr></thead><tbody>';
            
            foreach ($history as $record) {
                // Parse description for price info
                preg_match('/from UGX ([\d,]+) to UGX ([\d,]+)/', $record->description, $matches);
                $oldPrice = isset($matches[1]) ? $matches[1] : '-';
                $newPrice = isset($matches[2]) ? $matches[2] : '-';
                
                if (isset($matches[1]) && isset($matches[2])) {
                    $old = (float) str_replace(',', '', $matches[1]);
                    $new = (float) str_replace(',', '', $matches[2]);
                    $change = $new - $old;
                    $changePercent = $old > 0 ? (($change / $old) * 100) : 0;
                    
                    $changeColor = $change > 0 ? 'green' : 'red';
                    $changeIcon = $change > 0 ? '↑' : '↓';
                    $changeText = number_format(abs($changePercent), 1) . '%';
                } else {
                    $changeColor = 'gray';
                    $changeIcon = '-';
                    $changeText = '-';
                }
                
                $html .= '<tr>';
                $html .= '<td>' . date('M d, Y H:i', strtotime($record->created_at)) . '</td>';
                $html .= '<td>UGX ' . $oldPrice . '</td>';
                $html .= '<td>UGX ' . $newPrice . '</td>';
                $html .= '<td style="color: ' . $changeColor . '; font-weight: bold;">';
                $html .= $changeIcon . ' ' . $changeText;
                $html .= '</td>';
                $html .= '<td style="font-size: 12px;">' . e($record->description) . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody></table>';
        }
        
        $html .= '</div>';
        
        // Show current prices
        $html .= '<div style="margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 4px;">';
        $html .= '<strong>Current Prices:</strong><br>';
        $html .= '📦 Buying: UGX ' . number_format($model->buying_price, 0) . '<br>';
        $html .= '💵 Selling: UGX ' . number_format($model->selling_price, 0) . '<br>';
        
        if ($model->selling_price > $model->buying_price) {
            $margin = (($model->selling_price - $model->buying_price) / $model->buying_price) * 100;
            $html .= '📊 Margin: ' . number_format($margin, 1) . '%';
        }
        
        $html .= '</div>';

        return $this->response()->html($html)->modal([
            'title' => 'Price History: ' . $model->name,
            'width' => '800px'
        ]);
    }
}
