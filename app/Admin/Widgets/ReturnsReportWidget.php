<?php

namespace App\Admin\Widgets;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReturnsReportWidget extends Widget
{
    protected $view = 'admin.widgets.returns-report';

    public function __construct()
    {
        $this->class = 'returns-report-widget';
        $this->style = '.returns-report-widget { margin-bottom: 20px; }';
    }

    public function render()
    {
        $u = Admin::user();
        $companyId = $u->company_id;
        
        // Get returns data
        $data = [
            'summary' => $this->getReturnsSummary($companyId),
            'by_reason' => $this->getReturnsByReason($companyId),
            'recent_returns' => $this->getRecentReturns($companyId),
            'monthly_trend' => $this->getMonthlyTrend($companyId),
            'top_returned_products' => $this->getTopReturnedProducts($companyId),
        ];

        return view($this->view, compact('data'));
    }

    private function getReturnsSummary($companyId)
    {
        // Today
        $today = DB::select("
            SELECT 
                COALESCE(COUNT(*), 0) as returns_count,
                COALESCE(SUM(quantity), 0) as units_returned,
                COALESCE(SUM(ABS(total_sales)), 0) as refund_total
            FROM stock_records
            WHERE company_id = ?
            AND type = 'Return'
            AND DATE(created_at) = CURDATE()
        ", [$companyId]);

        // This month
        $month = DB::select("
            SELECT 
                COALESCE(COUNT(*), 0) as returns_count,
                COALESCE(SUM(quantity), 0) as units_returned,
                COALESCE(SUM(ABS(total_sales)), 0) as refund_total
            FROM stock_records
            WHERE company_id = ?
            AND type = 'Return'
            AND MONTH(created_at) = MONTH(CURDATE())
            AND YEAR(created_at) = YEAR(CURDATE())
        ", [$companyId]);

        // Total all-time
        $total = DB::select("
            SELECT 
                COALESCE(COUNT(*), 0) as returns_count,
                COALESCE(SUM(quantity), 0) as units_returned,
                COALESCE(SUM(ABS(total_sales)), 0) as refund_total
            FROM stock_records
            WHERE company_id = ?
            AND type = 'Return'
        ", [$companyId]);

        return [
            'today' => $today[0],
            'month' => $month[0],
            'total' => $total[0],
        ];
    }

    private function getReturnsByReason($companyId)
    {
        $returns = DB::select("
            SELECT 
                description,
                COUNT(*) as count,
                SUM(quantity) as total_units,
                SUM(ABS(total_sales)) as total_refund
            FROM stock_records
            WHERE company_id = ?
            AND type = 'Return'
            GROUP BY description
            ORDER BY count DESC
            LIMIT 10
        ", [$companyId]);

        $result = [];
        foreach ($returns as $return) {
            // Extract reason from description
            preg_match('/Reason: ([^.]+)/', $return->description, $matches);
            $reason = $matches[1] ?? 'Unknown';
            
            $result[] = [
                'reason' => $reason,
                'count' => $return->count,
                'units' => $return->total_units,
                'refund' => $return->total_refund,
            ];
        }

        return $result;
    }

    private function getRecentReturns($companyId)
    {
        $returns = DB::select("
            SELECT 
                sr.*,
                si.name as product_name,
                si.image as product_image,
                u.name as processed_by
            FROM stock_records sr
            JOIN stock_items si ON sr.stock_item_id = si.id
            JOIN admin_users u ON sr.created_by_id = u.id
            WHERE sr.company_id = ?
            AND sr.type = 'Return'
            ORDER BY sr.created_at DESC
            LIMIT 20
        ", [$companyId]);

        return $returns;
    }

    private function getMonthlyTrend($companyId)
    {
        $trend = DB::select("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as returns_count,
                SUM(quantity) as units_returned,
                SUM(ABS(total_sales)) as refund_amount
            FROM stock_records
            WHERE company_id = ?
            AND type = 'Return'
            AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ", [$companyId]);

        $labels = [];
        $counts = [];
        $units = [];
        $refunds = [];

        foreach ($trend as $t) {
            $labels[] = Carbon::parse($t->month . '-01')->format('M Y');
            $counts[] = $t->returns_count;
            $units[] = $t->units_returned;
            $refunds[] = $t->refund_amount;
        }

        return [
            'labels' => $labels,
            'counts' => $counts,
            'units' => $units,
            'refunds' => $refunds,
        ];
    }

    private function getTopReturnedProducts($companyId)
    {
        $products = DB::select("
            SELECT 
                si.id,
                si.name,
                si.image,
                COUNT(*) as return_count,
                SUM(sr.quantity) as total_returned,
                SUM(ABS(sr.total_sales)) as total_refunded
            FROM stock_records sr
            JOIN stock_items si ON sr.stock_item_id = si.id
            WHERE sr.company_id = ?
            AND sr.type = 'Return'
            GROUP BY si.id, si.name, si.image
            ORDER BY return_count DESC
            LIMIT 10
        ", [$companyId]);

        return $products;
    }
}
