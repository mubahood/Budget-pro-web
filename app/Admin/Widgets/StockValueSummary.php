<?php

namespace App\Admin\Widgets;

use App\Models\StockItem;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Widgets\Widget;

class StockValueSummary extends Widget
{
    protected $view = 'admin.widgets.stock-value-summary';

    public function render()
    {
        $u = Admin::user();
        if (!$u) {
            return '';
        }

        $companyId = $u->company_id;

        // Calculate total stock value (cost price basis)
        $totalStockValue = StockItem::where('company_id', $companyId)
            ->selectRaw('SUM(current_quantity * buying_price) as total_value')
            ->first()
            ->total_value ?? 0;

        // Calculate total potential revenue (selling price basis)
        $totalPotentialRevenue = StockItem::where('company_id', $companyId)
            ->selectRaw('SUM(current_quantity * selling_price) as total_revenue')
            ->first()
            ->total_revenue ?? 0;

        // Calculate total potential profit
        $totalPotentialProfit = $totalPotentialRevenue - $totalStockValue;

        // Count products
        $totalProducts = StockItem::where('company_id', $companyId)->count();
        $inStockProducts = StockItem::where('company_id', $companyId)
            ->where('current_quantity', '>', 0)
            ->count();

        // Get top 5 valuable items
        $topValuableItems = StockItem::where('company_id', $companyId)
            ->selectRaw('*, (current_quantity * buying_price) as stock_value')
            ->orderByRaw('(current_quantity * buying_price) DESC')
            ->limit(5)
            ->get();

        // Average profit margin
        $avgMargin = StockItem::where('company_id', $companyId)
            ->where('buying_price', '>', 0)
            ->where('current_quantity', '>', 0)
            ->selectRaw('AVG(((selling_price - buying_price) / buying_price) * 100) as avg_margin')
            ->first()
            ->avg_margin ?? 0;

        return view($this->view, [
            'totalStockValue' => $totalStockValue,
            'totalPotentialRevenue' => $totalPotentialRevenue,
            'totalPotentialProfit' => $totalPotentialProfit,
            'totalProducts' => $totalProducts,
            'inStockProducts' => $inStockProducts,
            'topValuableItems' => $topValuableItems,
            'avgMargin' => $avgMargin,
        ]);
    }
}
