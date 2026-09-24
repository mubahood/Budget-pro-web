<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Device;
use App\Models\SaleRecord;
use App\Models\StockItem;
use App\Models\StockRecord;
use App\Services\Sync\SyncApplier;
use App\Support\Sync\SyncSequence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sync soak test (plan B11): several simulated devices sell, restock and return the
 * same few products through the real SyncApplier for a while, with "lost" responses
 * that force replays and batches sent out of order. Afterwards every invariant must
 * hold: on-hand == opening + Σ movements, no sale applied twice, every client batch
 * applied exactly once.
 *
 *   php artisan sync:soak --company=ID --seconds=60 --devices=3
 */
class SyncSoak extends Command
{
    protected $signature = 'sync:soak {--company= : company id to run against (a throwaway tenant!)} {--seconds=30} {--devices=3} {--seed=}';

    protected $description = 'Hammer the sync applier with concurrent-style device traffic and verify stock/sale invariants';

    public function handle(SyncApplier $applier): int
    {
        $company = Company::find($this->option('company'));
        if ($company === null) {
            $this->error('Pass --company=<id> of a throwaway tenant.');

            return self::FAILURE;
        }
        mt_srand((int) ($this->option('seed') ?: time()));
        $products = StockItem::withoutGlobalScopes()->where('company_id', $company->id)->where('track_stock', 1)->limit(5)->get();
        if ($products->count() < 2) {
            $this->error('The company needs at least two stocked products.');

            return self::FAILURE;
        }
        $opening = [];
        foreach ($products as $p) {
            $opening[$p->id] = (float) $p->current_quantity - (float) StockRecord::withoutGlobalScopes()->where('stock_item_id', $p->id)->sum('quantity_delta');
        }
        $userId = (int) $company->owner_id;
        $devices = [];
        for ($i = 0; $i < (int) $this->option('devices'); $i++) {
            $devices[] = Device::register($company->id, $userId, (string) Str::uuid(), ['name' => 'Soak '.($i + 1)]);
        }

        $sent = [];      // batch_uuid => batch (for replays)
        $sales = [];     // sale uuid => [product uuid => qty]
        $pending = [];   // batches "lost" in transit, re-sent later out of order
        $until = microtime(true) + (int) $this->option('seconds');
        $n = 0;
        while (microtime(true) < $until) {
            $device = $devices[array_rand($devices)];
            $batch = $this->randomBatch($products, $sales);
            $sent[$batch['batch_uuid']] = $batch;
            if (mt_rand(1, 10) === 1) {
                $pending[] = $batch; // response lost: the device will retry later

                continue;
            }
            $applier->push($company->fresh(), $device, $userId, [$batch]);
            if (mt_rand(1, 6) === 1 && $sent !== []) {
                $applier->push($company->fresh(), $device, $userId, [$sent[array_rand($sent)]]); // stray replay
            }
            if ($pending !== [] && mt_rand(1, 4) === 1) {
                shuffle($pending);
                $applier->push($company->fresh(), $device, $userId, [array_pop($pending)]);
            }
            $n++;
        }
        foreach ($pending as $b) {
            $applier->push($company->fresh(), $devices[0], $userId, [$b]);
        }

        // Invariants.
        $fail = 0;
        foreach ($products as $p) {
            $p->refresh();
            $sum = (float) StockRecord::withoutGlobalScopes()->where('stock_item_id', $p->id)->sum('quantity_delta');
            $ok = abs(($opening[$p->id] + $sum) - (float) $p->current_quantity) < 0.0005;
            $this->line(sprintf('%-30s on-hand %10.3f  opening+Σ %10.3f  %s', $p->name, (float) $p->current_quantity, $opening[$p->id] + $sum, $ok ? 'OK' : 'MISMATCH'));
            $fail += $ok ? 0 : 1;
        }
        $applied = SaleRecord::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('uuid', array_keys($sales))->count();
        $dupes = DB::table('sale_records')->where('company_id', $company->id)->select('uuid')->groupBy('uuid')->havingRaw('COUNT(*) > 1')->count();
        $this->line("batches: {$n} sent (+replays), sales created: ".count($sales).", sales on server: {$applied}, duplicate sales: {$dupes}");
        $fail += ($applied !== count($sales) ? 1 : 0) + ($dupes > 0 ? 1 : 0);

        if ($fail > 0) {
            $this->error("{$fail} invariant(s) broken.");

            return self::FAILURE;
        }
        $this->info('All invariants hold.');

        return self::SUCCESS;
    }

    private function randomBatch($products, array &$sales): array
    {
        $p = $products->random();
        $roll = mt_rand(1, 10);
        $now = SyncSequence::nowMs();
        if ($roll <= 6) {
            $uuid = (string) Str::uuid();
            $qty = mt_rand(1, 3);
            $sales[$uuid] = [$p->uuid => $qty];

            return ['batch_uuid' => (string) Str::uuid(), 'kind' => 'sale', 'ops' => [
                ['op_uuid' => (string) Str::uuid(), 'table' => 'sales', 'uuid' => $uuid, 'action' => 'insert', 'data' => ['occurred_at' => $now, 'has_payment_ops' => 1, 'items' => [['product_uuid' => $p->uuid, 'quantity' => (string) $qty]]]],
                ['op_uuid' => (string) Str::uuid(), 'table' => 'payments', 'uuid' => (string) Str::uuid(), 'action' => 'insert', 'data' => ['sale_uuid' => $uuid, 'method' => 'cash', 'amount' => (string) ($qty * (float) $p->selling_price)]],
            ]];
        }
        if ($roll <= 8 || $sales === []) {
            return ['batch_uuid' => (string) Str::uuid(), 'kind' => 'movement', 'ops' => [
                ['op_uuid' => (string) Str::uuid(), 'table' => 'stock_movements', 'uuid' => (string) Str::uuid(), 'action' => 'insert', 'data' => ['product_uuid' => $p->uuid, 'type' => 'purchase_receipt', 'quantity' => (string) mt_rand(1, 6)]],
            ]];
        }
        $saleUuid = array_rand($sales);
        [$productUuid, $qty] = [array_key_first($sales[$saleUuid]), reset($sales[$saleUuid])];

        return ['batch_uuid' => (string) Str::uuid(), 'kind' => 'return', 'ops' => [
            ['op_uuid' => (string) Str::uuid(), 'table' => 'sale_returns', 'uuid' => (string) Str::uuid(), 'action' => 'insert', 'data' => ['sale_uuid' => $saleUuid, 'items' => [['product_uuid' => $productUuid, 'quantity' => '1', 'restock' => (bool) mt_rand(0, 1)]]]],
        ]];
    }
}
