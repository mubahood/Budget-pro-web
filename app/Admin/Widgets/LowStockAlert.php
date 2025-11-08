<?php

namespace App\Admin\Widgets;

use App\Models\StockItem;
use Encore\Admin\Facades\Admin;
use Illuminate\Support\Facades\Request;
use Encore\Admin\Widgets\Widget;

class LowStockAlert extends Widget
{
    protected $view = 'admin.widgets.low-stock-alert';

    public function script()
    {
        return <<<JS
        // Refresh widget every 5 minutes
        setInterval(function() {
            $.get(window.location.href, function(data) {
                var newContent = $(data).find('#low-stock-widget').html();
                $('#low-stock-widget').html(newContent);
            });
        }, 300000); // 5 minutes
JS;
    }

    public function render()
    {
        $u = Admin::user();
        if (!$u) {
            return '';
        }

        // Get low stock items (less than 10 units)
        $lowStockItems = StockItem::where('company_id', $u->company_id)
            ->where('current_quantity', '>', 0)
            ->where('current_quantity', '<=', 10)
            ->orderBy('current_quantity', 'asc')
            ->limit(10)
            ->get();

        // Get out of stock items
        $outOfStockItems = StockItem::where('company_id', $u->company_id)
            ->where('current_quantity', '<=', 0)
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get();

        $lowStockCount = StockItem::where('company_id', $u->company_id)
            ->where('current_quantity', '>', 0)
            ->where('current_quantity', '<=', 10)
            ->count();

        $outOfStockCount = StockItem::where('company_id', $u->company_id)
            ->where('current_quantity', '<=', 0)
            ->count();

        return view($this->view, [
            'lowStockItems' => $lowStockItems,
            'outOfStockItems' => $outOfStockItems,
            'lowStockCount' => $lowStockCount,
            'outOfStockCount' => $outOfStockCount,
        ]);
    }
}
