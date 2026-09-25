<?php

namespace App\Support;

/**
 * One list of sales for dashboards (client report 2026-09-25): sale documents
 * (not voided, net of returns) plus the stand-alone Sale movements the old app
 * and quick-sale still record without a sale document (not reversed). Use after
 * LocalTime::prime() so movement dates are in the shop's timezone.
 */
class SalesSource
{
    /**
     * Derived table with: sale_id, sale_date, total_amount, amount_paid, balance, payment_status, customer_name, customer_phone, profit.
     *
     * @return array{0: string, 1: array<int, int>} [SQL for a FROM clause (aliased), bindings]
     */
    public static function sql(int $companyId, string $alias = 's'): array
    {
        $sql = "(
            SELECT r.id AS sale_id, DATE(r.sale_date) AS sale_date, r.total_amount - COALESCE(r.refunded_amount, 0) AS total_amount,
                r.amount_paid, r.balance, r.payment_status, r.customer_name, r.customer_phone,
                COALESCE((SELECT SUM(i.profit) FROM sale_record_items i WHERE i.sale_record_id = r.id), 0) AS profit
            FROM sale_records r
            WHERE r.company_id = ? AND r.status <> 'Voided'
            UNION ALL
            SELECT -m.id, DATE(CONVERT_TZ(COALESCE(m.date, m.created_at), '+00:00', COALESCE(@tz_offset, '+00:00'))), m.total_sales, m.total_sales, 0, 'Paid', NULL, NULL, m.profit
            FROM stock_records m
            WHERE m.company_id = ? AND m.type = 'Sale' AND m.sale_record_id IS NULL AND m.is_reversal = 0
              AND NOT EXISTS (SELECT 1 FROM stock_records rv WHERE rv.reverses_id = m.id)
        ) {$alias}";

        return [$sql, [$companyId, $companyId]];
    }
}
