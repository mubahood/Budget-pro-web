<?php

namespace App\Console\Commands;

use App\Models\StockItem;
use App\Services\Shop\StockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Write-offs (Damage, Expired, Internal Use, …) recorded before Phase 0 never moved stock: the backfill
 * gave them quantity_delta 0. This takes the goods off the shelf now, as new Adjustment Out movements that
 * point at the original row, so the ledger stays append-only and a second run changes nothing.
 * Opt-in per company, dry run unless --apply.
 */
class ApplyOldWriteoffs extends Command
{
    public const REFERENCE = 'old_writeoff';

    protected $signature = 'stock:apply-old-writeoffs {--company= : Company id (required)} {--apply : Record the movements (default is a dry run)}';

    protected $description = 'Take old write-offs that never reduced stock off the shelf';

    public function handle(StockService $stock): int
    {
        $companyId = (int) $this->option('company');
        if ($companyId <= 0) {
            $this->error('Pass --company=<id>.');

            return self::FAILURE;
        }
        $outbound = array_values(array_diff(StockService::OUTBOUND, ['Sale', 'Transfer Out']));
        $rows = DB::table('stock_records as m')
            ->where('m.company_id', $companyId)->whereIn('m.type', $outbound)
            ->where('m.quantity_delta', 0)->where('m.quantity', '>', 0)->where('m.is_reversal', 0)
            ->whereNotExists(fn ($q) => $q->from('stock_records as rv')->whereColumn('rv.reverses_id', 'm.id'))
            ->whereNotExists(fn ($q) => $q->from('stock_records as c')->where('c.reference_type', self::REFERENCE)->whereColumn('c.reference_id', 'm.id'))
            ->orderBy('m.id')->get(['m.id', 'm.stock_item_id', 'm.type', 'm.quantity', 'm.created_at', 'm.created_by_id']);

        $done = 0;
        foreach ($rows as $r) {
            $item = StockItem::withoutGlobalScopes()->find($r->stock_item_id);
            $take = $item ? min((float) $r->quantity, max(0.0, (float) $item->current_quantity)) : 0.0;
            $this->line(sprintf('#%d %s %s × %s (%s) → take %s, stock %s → %s', $r->id, $r->type, $item->name ?? 'missing product', $r->quantity, $r->created_at, $take, $item->current_quantity ?? '-', $item ? (float) $item->current_quantity - $take : '-'));
            if (! $this->option('apply') || $take <= 0 || $item->track_stock === false) {
                continue;
            }
            $stock->record([
                'stock_item_id' => (int) $r->stock_item_id, 'type' => 'Adjustment Out', 'quantity' => $take,
                'unit_cost' => (float) $item->buying_price, 'created_by_id' => $r->created_by_id,
                'description' => "{$r->type} #{$r->id} of {$r->created_at} applied to stock (it was recorded before write-offs reduced stock)",
                'reference_type' => self::REFERENCE, 'reference_id' => (int) $r->id,
            ]);
            $done++;
        }
        $this->info($this->option('apply') ? "{$done} write-offs applied." : count($rows).' write-offs found. Dry run: nothing changed; add --apply.');

        return self::SUCCESS;
    }
}
