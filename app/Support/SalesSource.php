<?php

namespace App\Support;

/**
 * One list of sales for dashboards and reports (client report 2026-09-25): sale documents
 * (not voided, net of returns) plus the stand-alone Sale movements the old app and quick-sale
 * still record without a sale document (not reversed). Call LocalTime::prime() first.
 *
 * Dates are the shop's local day. Rows written before local dates were stored carry the UTC
 * date of their creation; for those the local day is taken from created_at instead.
 */
class SalesSource
{
    /** From this moment (UTC) business dates are written as the shop's local day (App\Support\LocalDate). */
    public const LOCAL_DATES_SINCE = '2026-09-26 00:00:00';

    /**
     * Local sale day of a row with a DATE column and a UTC created_at. Rows from before local dates were
     * written carry the UTC day, so for those the local day comes from created_at unless the date was
     * set by hand (it differs from the UTC day).
     */
    public static function localDay(string $dateCol, string $createdCol): string
    {
        $local = "DATE(CONVERT_TZ({$createdCol}, '+00:00', COALESCE(@tz_offset, '+00:00')))";

        return "(CASE WHEN {$dateCol} IS NULL THEN {$local} WHEN {$createdCol} >= '".self::LOCAL_DATES_SINCE."' THEN {$dateCol}"
            ." WHEN {$dateCol} = DATE({$createdCol}) THEN {$local} ELSE {$dateCol} END)";
    }

    /**
     * One row per sale: sale_id, sale_date, total_amount (net of refunds), amount_paid, balance, payment_status,
     * customer_id, customer_name, customer_phone, profit, created_by_id.
     *
     * @return array{0: string, 1: array<int, int>} [SQL for a FROM clause (aliased), bindings]
     */
    public static function sql(int $companyId, string $alias = 's'): array
    {
        $saleDay = self::localDay('r.sale_date', 'r.created_at');
        $moveDay = self::localDay('m.date', 'm.created_at');
        $sql = "(
            SELECT CAST(r.id AS SIGNED) AS sale_id, {$saleDay} AS sale_date, r.total_amount - COALESCE(r.refunded_amount, 0) AS total_amount,
                r.amount_paid, r.balance, r.payment_status, r.customer_id, r.customer_name, r.customer_phone,
                COALESCE((SELECT SUM(i.profit) FROM sale_record_items i WHERE i.sale_record_id = r.id), 0) AS profit, r.created_by_id
            FROM sale_records r
            WHERE r.company_id = ? AND r.voided_at IS NULL AND r.status <> 'Voided'
            UNION ALL
            SELECT -m.id, {$moveDay}, m.total_sales, m.total_sales, 0, 'Paid', NULL, NULL, NULL, m.profit, m.created_by_id
            FROM stock_records m
            WHERE m.company_id = ? AND m.type = 'Sale' AND m.sale_record_id IS NULL AND m.is_reversal = 0
              AND NOT EXISTS (SELECT 1 FROM stock_records rv WHERE rv.reverses_id = m.id)
        ) {$alias}";

        return [$sql, [$companyId, $companyId]];
    }

    /**
     * One row per product sold: sale_id, stock_item_id, item_name, sale_date, quantity (net of returns, in the
     * product's base unit), revenue (net of returns and discounts), profit.
     *
     * @return array{0: string, 1: array<int, int>}
     */
    public static function linesSql(int $companyId, string $alias = 'l'): array
    {
        $saleDay = self::localDay('r.sale_date', 'r.created_at');
        $moveDay = self::localDay('m.date', 'm.created_at');
        $sql = "(
            SELECT CAST(r.id AS SIGNED) AS sale_id, i.stock_item_id, i.item_name, {$saleDay} AS sale_date,
                (i.quantity - COALESCE(i.returned_quantity, 0)) * COALESCE(NULLIF(i.unit_factor, 0), 1) AS quantity,
                COALESCE(i.line_total, i.subtotal) * (i.quantity - COALESCE(i.returned_quantity, 0)) / NULLIF(i.quantity, 0) AS revenue,
                i.profit
            FROM sale_record_items i JOIN sale_records r ON r.id = i.sale_record_id
            WHERE r.company_id = ? AND r.voided_at IS NULL AND r.status <> 'Voided' AND COALESCE(i.is_deleted, 0) = 0
            UNION ALL
            SELECT -m.id, m.stock_item_id, NULL, {$moveDay}, m.quantity, m.total_sales, m.profit
            FROM stock_records m
            WHERE m.company_id = ? AND m.type = 'Sale' AND m.sale_record_id IS NULL AND m.is_reversal = 0
              AND NOT EXISTS (SELECT 1 FROM stock_records rv WHERE rv.reverses_id = m.id)
        ) {$alias}";

        return [$sql, [$companyId, $companyId]];
    }
}
