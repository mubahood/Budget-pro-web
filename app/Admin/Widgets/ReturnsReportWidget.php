<?php

namespace App\Admin\Widgets;

use Carbon\Carbon;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Widgets\Widget;
use Illuminate\Support\Facades\DB;

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

        \App\Support\LocalTime::prime((int) $companyId);

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

    /**
     * Every return (client report 2026-09-25): returns against a sale — including faulty goods that are
     * not put back on the shelf — plus older stand-alone Return movements.
     *
     * @return array{0: string, 1: array<int, int>}
     */
    private function source(int $companyId): array
    {
        return ["(
            SELECT ri.stock_item_id, ri.quantity, ri.value AS refund, r.created_at, r.created_by_id,
                CONCAT(IF(ri.restock = 1, 'Back to stock', 'Not restocked (faulty)'), IF(r.reason IS NULL OR r.reason = '', '', CONCAT(' — ', r.reason))) AS description,
                COALESCE(NULLIF(r.reason, ''), IF(ri.restock = 1, 'Returned', 'Faulty / not restocked')) AS reason
            FROM sale_return_items ri JOIN sale_returns r ON r.id = ri.sale_return_id
            WHERE ri.company_id = ? AND r.is_deleted = 0
            UNION ALL
            SELECT m.stock_item_id, m.quantity, ABS(m.total_sales), m.created_at, m.created_by_id, m.description,
                COALESCE(NULLIF(m.reason, ''), 'Returned')
            FROM stock_records m
            WHERE m.company_id = ? AND m.type = 'Return' AND m.is_reversal = 0 AND (m.reference_type IS NULL OR m.reference_type <> 'sale_return')
        ) x", [$companyId, $companyId]];
    }

    private function getReturnsSummary($companyId)
    {
        [$from, $bind] = $this->source((int) $companyId);
        $periods = [
            'today' => "DATE(CONVERT_TZ(x.created_at, '+00:00', @tz_offset)) = @local_today",
            'month' => "MONTH(CONVERT_TZ(x.created_at, '+00:00', @tz_offset)) = MONTH(@local_today) AND YEAR(CONVERT_TZ(x.created_at, '+00:00', @tz_offset)) = YEAR(@local_today)",
            'total' => '1 = 1',
        ];
        $out = [];
        foreach ($periods as $key => $where) {
            $out[$key] = DB::selectOne("SELECT COUNT(*) AS returns_count, COALESCE(SUM(x.quantity), 0) AS units_returned, COALESCE(SUM(x.refund), 0) AS refund_total FROM {$from} WHERE {$where}", $bind);
        }

        return $out;
    }

    private function getReturnsByReason($companyId)
    {
        [$from, $bind] = $this->source((int) $companyId);

        return array_map(fn ($r) => ['reason' => $r->reason, 'count' => $r->n, 'units' => $r->units, 'refund' => $r->refund],
            DB::select("SELECT x.reason, COUNT(*) AS n, SUM(x.quantity) AS units, SUM(x.refund) AS refund FROM {$from} GROUP BY x.reason ORDER BY n DESC LIMIT 10", $bind));
    }

    private function getRecentReturns($companyId)
    {
        [$from, $bind] = $this->source((int) $companyId);

        return DB::select("
            SELECT x.quantity, x.description, x.created_at, x.refund AS total_sales, si.name AS product_name, si.image AS product_image, u.name AS processed_by
            FROM {$from}
            JOIN stock_items si ON x.stock_item_id = si.id
            LEFT JOIN admin_users u ON x.created_by_id = u.id
            ORDER BY x.created_at DESC
            LIMIT 20
        ", $bind);
    }

    private function getMonthlyTrend($companyId)
    {
        [$from, $bind] = $this->source((int) $companyId);
        $trend = DB::select("
            SELECT DATE_FORMAT(x.created_at, '%Y-%m') AS month, COUNT(*) AS returns_count, SUM(x.quantity) AS units_returned, SUM(x.refund) AS refund_amount
            FROM {$from}
            WHERE x.created_at >= DATE_SUB(@local_today, INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(x.created_at, '%Y-%m')
            ORDER BY month ASC
        ", $bind);

        $labels = [];
        $counts = [];
        $units = [];
        $refunds = [];
        foreach ($trend as $t) {
            $labels[] = Carbon::parse($t->month.'-01')->format('M Y');
            $counts[] = $t->returns_count;
            $units[] = $t->units_returned;
            $refunds[] = $t->refund_amount;
        }

        return ['labels' => $labels, 'counts' => $counts, 'units' => $units, 'refunds' => $refunds];
    }

    private function getTopReturnedProducts($companyId)
    {
        [$from, $bind] = $this->source((int) $companyId);

        return DB::select("
            SELECT si.id, si.name, si.image, COUNT(*) AS return_count, SUM(x.quantity) AS total_returned, SUM(x.refund) AS total_refunded
            FROM {$from}
            JOIN stock_items si ON x.stock_item_id = si.id
            GROUP BY si.id, si.name, si.image
            ORDER BY return_count DESC
            LIMIT 10
        ", $bind);
    }
}
