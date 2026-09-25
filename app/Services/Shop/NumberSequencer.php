<?php

namespace App\Services\Shop;

use Illuminate\Support\Facades\DB;

/**
 * Per-company, per-year document numbers (Appendix D, server side):
 * RCP-2026-000981, INV-2026-000042, PO-2026-000007 ...
 * Row-locked so concurrent sales never collide; uniqueness is enforced per
 * company by the DB (`sale_records_company_receipt_unique`), never globally.
 * Must be called inside the caller's transaction so the lock is held until
 * the document is committed.
 */
class NumberSequencer
{
    public const FORMATS = [
        'receipt' => 'RCP',
        'invoice' => 'INV',
        'purchase_order' => 'PO',
        'goods_receipt' => 'GRN',
        'adjustment' => 'ADJ',
        'stock_take' => 'STK',
        'shift' => 'SHF',
        'refund' => 'RFD',
        'purchase_return' => 'PRT',
        'transfer' => 'TRF',
    ];

    public static function next(int $companyId, string $kind, ?\DateTimeInterface $on = null): string
    {
        $prefix = self::FORMATS[$kind] ?? strtoupper($kind);
        $periodKey = ($on ? \Illuminate\Support\Carbon::instance($on) : now())->format('Y');

        $value = DB::transaction(function () use ($companyId, $kind, $periodKey) {
            $row = DB::table('number_sequences')
                ->where('company_id', $companyId)->where('kind', $kind)->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                try {
                    DB::table('number_sequences')->insert([
                        'company_id' => $companyId, 'kind' => $kind, 'period_key' => $periodKey,
                        'last_value' => 0, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    // Lost the insert race; the row now exists — lock it below.
                }
                $row = DB::table('number_sequences')
                    ->where('company_id', $companyId)->where('kind', $kind)->where('period_key', $periodKey)
                    ->lockForUpdate()
                    ->first();
            }

            $next = (int) $row->last_value + 1;
            DB::table('number_sequences')->where('id', $row->id)->update(['last_value' => $next, 'updated_at' => now()]);

            return $next;
        });

        return sprintf('%s-%s-%06d', $prefix, $periodKey, $value);
    }
}
