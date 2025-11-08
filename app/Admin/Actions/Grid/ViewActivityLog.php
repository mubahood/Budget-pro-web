<?php

namespace App\Admin\Actions\Grid;

use Encore\Admin\Actions\RowAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ViewActivityLog extends RowAction
{
    public $name = '📜 Activity Log';

    public function handle(Model $model)
    {
        // Get all activities for this product
        $activities = DB::table('stock_records')
            ->where('stock_item_id', $model->id)
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();
        
        // Get admin operation logs if available
        $adminLogs = DB::table('admin_operation_log')
            ->where('path', 'LIKE', '%stock-items/' . $model->id . '%')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        $html = '<div style="max-height: 550px; overflow-y: auto;">';
        
        // Summary stats
        $html .= '<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px;">';
        
        $totalActivities = $activities->count();
        $totalSales = $activities->where('type', 'Sale')->count();
        $totalPurchases = $activities->where('type', 'Purchase')->count();
        $totalAdjustments = $activities->where('type', 'Stock Adjustment')->count();
        
        $html .= '<div style="padding: 15px; background: #e3f2fd; border-radius: 6px; text-align: center;">';
        $html .= '<div style="font-size: 24px; font-weight: bold; color: #1565c0;">' . $totalActivities . '</div>';
        $html .= '<div style="font-size: 12px; color: #666;">Total Activities</div>';
        $html .= '</div>';
        
        $html .= '<div style="padding: 15px; background: #e8f5e9; border-radius: 6px; text-align: center;">';
        $html .= '<div style="font-size: 24px; font-weight: bold; color: #2e7d32;">' . $totalSales . '</div>';
        $html .= '<div style="font-size: 12px; color: #666;">Sales</div>';
        $html .= '</div>';
        
        $html .= '<div style="padding: 15px; background: #fff3e0; border-radius: 6px; text-align: center;">';
        $html .= '<div style="font-size: 24px; font-weight: bold; color: #ef6c00;">' . $totalPurchases . '</div>';
        $html .= '<div style="font-size: 12px; color: #666;">Purchases</div>';
        $html .= '</div>';
        
        $html .= '<div style="padding: 15px; background: #fce4ec; border-radius: 6px; text-align: center;">';
        $html .= '<div style="font-size: 24px; font-weight: bold; color: #c2185b;">' . $totalAdjustments . '</div>';
        $html .= '<div style="font-size: 12px; color: #666;">Adjustments</div>';
        $html .= '</div>';
        
        $html .= '</div>';
        
        // Tabs
        $html .= '<ul class="nav nav-tabs" style="margin-bottom: 20px;">';
        $html .= '<li class="active"><a data-toggle="tab" href="#stock-activities">📦 Stock Activities</a></li>';
        $html .= '<li><a data-toggle="tab" href="#admin-logs">⚙️ Admin Actions</a></li>';
        $html .= '</ul>';
        
        $html .= '<div class="tab-content">';
        
        // Stock Activities Tab
        $html .= '<div id="stock-activities" class="tab-pane fade in active">';
        
        if ($activities->isEmpty()) {
            $html .= '<p style="text-align: center; color: #999; padding: 40px;">No stock activities recorded yet.</p>';
        } else {
            $html .= '<div class="timeline" style="position: relative; padding-left: 30px;">';
            
            foreach ($activities as $index => $activity) {
                $typeInfo = $this->getActivityTypeInfo($activity->type);
                
                $html .= '<div style="margin-bottom: 20px; position: relative;">';
                
                // Timeline dot
                $html .= '<div style="position: absolute; left: -30px; width: 12px; height: 12px; background: ' . $typeInfo['color'] . '; border-radius: 50%; border: 3px solid white; box-shadow: 0 0 0 2px ' . $typeInfo['color'] . ';"></div>';
                
                // Timeline line
                if ($index < $activities->count() - 1) {
                    $html .= '<div style="position: absolute; left: -24px; top: 12px; width: 2px; height: calc(100% + 20px); background: #e0e0e0;"></div>';
                }
                
                // Content card
                $html .= '<div style="padding: 12px 15px; background: ' . $typeInfo['bg'] . '; border-left: 4px solid ' . $typeInfo['color'] . '; border-radius: 4px;">';
                
                $html .= '<div style="display: flex; justify-content: space-between; margin-bottom: 8px;">';
                $html .= '<div>';
                $html .= '<span style="font-size: 18px; margin-right: 8px;">' . $typeInfo['icon'] . '</span>';
                $html .= '<strong>' . $activity->type . '</strong>';
                $html .= '</div>';
                $html .= '<div style="text-align: right;">';
                
                if ($activity->quantity != 0) {
                    $qtyColor = $activity->quantity > 0 ? '#4caf50' : '#f44336';
                    $html .= '<div style="font-size: 18px; font-weight: bold; color: ' . $qtyColor . ';">';
                    $html .= ($activity->quantity > 0 ? '+' : '') . number_format($activity->quantity);
                    $html .= '</div>';
                }
                
                $html .= '<div style="font-size: 11px; color: #666;">' . date('M d, Y H:i', strtotime($activity->created_at)) . '</div>';
                $html .= '</div>';
                $html .= '</div>';
                
                if (!empty($activity->description)) {
                    $html .= '<div style="font-size: 13px; color: #555; margin-top: 5px;">';
                    $html .= e($activity->description);
                    $html .= '</div>';
                }
                
                $html .= '</div></div>';
            }
            
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        // Admin Logs Tab
        $html .= '<div id="admin-logs" class="tab-pane fade">';
        
        if ($adminLogs->isEmpty()) {
            $html .= '<p style="text-align: center; color: #999; padding: 40px;">No admin actions recorded yet.</p>';
        } else {
            $html .= '<table class="table table-striped">';
            $html .= '<thead><tr>';
            $html .= '<th>Date/Time</th><th>Action</th><th>Method</th><th>User</th><th>IP Address</th>';
            $html .= '</tr></thead><tbody>';
            
            foreach ($adminLogs as $log) {
                $html .= '<tr>';
                $html .= '<td>' . date('M d, Y H:i:s', strtotime($log->created_at)) . '</td>';
                $html .= '<td>' . e($log->path) . '</td>';
                $html .= '<td><span class="label label-' . ($log->method == 'GET' ? 'info' : 'warning') . '">' . $log->method . '</span></td>';
                $html .= '<td>' . $log->user_id . '</td>';
                $html .= '<td>' . e($log->ip) . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody></table>';
        }
        
        $html .= '</div>';
        
        $html .= '</div>'; // End tab-content
        
        $html .= '</div>';

        return $this->response()->html($html)->modal([
            'title' => '📜 Activity Log: ' . $model->name,
            'width' => '950px'
        ]);
    }
    
    private function getActivityTypeInfo($type)
    {
        $types = [
            'Sale' => ['icon' => '💰', 'color' => '#4caf50', 'bg' => '#e8f5e9'],
            'Purchase' => ['icon' => '🛒', 'color' => '#2196f3', 'bg' => '#e3f2fd'],
            'Stock Adjustment' => ['icon' => '⚙️', 'color' => '#ff9800', 'bg' => '#fff3e0'],
            'Return' => ['icon' => '↩️', 'color' => '#9c27b0', 'bg' => '#f3e5f5'],
            'Transfer' => ['icon' => '🔄', 'color' => '#00bcd4', 'bg' => '#e0f7fa'],
            'Damage' => ['icon' => '💔', 'color' => '#f44336', 'bg' => '#ffebee'],
            'Price Change' => ['icon' => '💵', 'color' => '#795548', 'bg' => '#efebe9'],
            'Category Change' => ['icon' => '🏷️', 'color' => '#607d8b', 'bg' => '#eceff1'],
        ];
        
        return $types[$type] ?? ['icon' => '📦', 'color' => '#9e9e9e', 'bg' => '#f5f5f5'];
    }
}
