<?php

namespace App\Admin\Actions\Grid;

use Encore\Admin\Actions\RowAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ViewStockTimeline extends RowAction
{
    public $name = '📊 Stock Timeline';

    public function handle(Model $model)
    {
        // Get all stock movements
        $movements = DB::table('stock_records')
            ->where('stock_item_id', $model->id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        $html = '<div style="max-height: 500px; overflow-y: auto;">';
        
        // Summary Cards
        $html .= '<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px;">';
        
        $totalIn = $movements->where('quantity', '>', 0)->sum('quantity');
        $totalOut = abs($movements->where('quantity', '<', 0)->sum('quantity'));
        $netChange = $totalIn - $totalOut;
        $currentStock = $model->current_quantity;
        
        $html .= '<div style="padding: 15px; background: #e8f5e9; border-radius: 6px; text-align: center;">';
        $html .= '<div style="font-size: 24px; font-weight: bold; color: #2e7d32;">↑ ' . number_format($totalIn) . '</div>';
        $html .= '<div style="font-size: 12px; color: #666;">Stock In</div>';
        $html .= '</div>';
        
        $html .= '<div style="padding: 15px; background: #ffebee; border-radius: 6px; text-align: center;">';
        $html .= '<div style="font-size: 24px; font-weight: bold; color: #c62828;">↓ ' . number_format($totalOut) . '</div>';
        $html .= '<div style="font-size: 12px; color: #666;">Stock Out</div>';
        $html .= '</div>';
        
        $html .= '<div style="padding: 15px; background: #e3f2fd; border-radius: 6px; text-align: center;">';
        $html .= '<div style="font-size: 24px; font-weight: bold; color: #1565c0;">⚖️ ' . number_format($netChange) . '</div>';
        $html .= '<div style="font-size: 12px; color: #666;">Net Change</div>';
        $html .= '</div>';
        
        $html .= '<div style="padding: 15px; background: #fff3e0; border-radius: 6px; text-align: center;">';
        $html .= '<div style="font-size: 24px; font-weight: bold; color: #ef6c00;">📦 ' . number_format($currentStock) . '</div>';
        $html .= '<div style="font-size: 12px; color: #666;">Current Stock</div>';
        $html .= '</div>';
        
        $html .= '</div>';
        
        // Timeline
        $html .= '<h4 style="margin: 20px 0 15px;">📅 Movement Timeline</h4>';
        
        if ($movements->isEmpty()) {
            $html .= '<p style="color: #999; text-align: center; padding: 40px;">No stock movements recorded yet.</p>';
        } else {
            $html .= '<div class="timeline" style="position: relative; padding-left: 30px;">';
            
            foreach ($movements as $index => $movement) {
                $isPositive = $movement->quantity > 0;
                $icon = $isPositive ? '📥' : '📤';
                $color = $isPositive ? '#4caf50' : '#f44336';
                $bgColor = $isPositive ? '#e8f5e9' : '#ffebee';
                
                // Type icon
                $typeIcon = match($movement->type) {
                    'Sale' => '💰',
                    'Purchase' => '🛒',
                    'Stock Adjustment' => '⚙️',
                    'Return' => '↩️',
                    'Transfer' => '🔄',
                    'Damage' => '💔',
                    default => '📦'
                };
                
                $html .= '<div style="margin-bottom: 20px; position: relative;">';
                
                // Timeline dot
                $html .= '<div style="position: absolute; left: -30px; width: 12px; height: 12px; background: ' . $color . '; border-radius: 50%; border: 3px solid white; box-shadow: 0 0 0 2px ' . $color . ';"></div>';
                
                // Timeline line (not for last item)
                if ($index < count($movements) - 1) {
                    $html .= '<div style="position: absolute; left: -24px; top: 12px; width: 2px; height: calc(100% + 20px); background: #e0e0e0;"></div>';
                }
                
                // Content card
                $html .= '<div style="padding: 12px 15px; background: ' . $bgColor . '; border-left: 4px solid ' . $color . '; border-radius: 4px;">';
                
                $html .= '<div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">';
                $html .= '<div>';
                $html .= '<span style="font-size: 18px; margin-right: 8px;">' . $typeIcon . '</span>';
                $html .= '<strong style="color: ' . $color . ';">' . $movement->type . '</strong>';
                $html .= '</div>';
                $html .= '<div style="text-align: right;">';
                $html .= '<div style="font-size: 20px; font-weight: bold; color: ' . $color . ';">';
                $html .= ($isPositive ? '+' : '') . number_format($movement->quantity);
                $html .= '</div>';
                $html .= '<div style="font-size: 11px; color: #666;">' . date('M d, Y H:i', strtotime($movement->created_at)) . '</div>';
                $html .= '</div>';
                $html .= '</div>';
                
                if (!empty($movement->description)) {
                    $html .= '<div style="font-size: 13px; color: #555; margin-top: 5px;">';
                    $html .= e($movement->description);
                    $html .= '</div>';
                }
                
                $html .= '</div></div>';
            }
            
            $html .= '</div>';
        }
        
        $html .= '</div>';

        return $this->response()->html($html)->modal([
            'title' => '📊 Stock Timeline: ' . $model->name,
            'width' => '900px'
        ]);
    }
}
