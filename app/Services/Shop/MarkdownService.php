<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Support\StoreFeatures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Markdowns for short-dated stock (SUPERMARKET_PLAN.md B4). "Mark down 30%" on a batch records a
 * `batch_markdowns` row with its own internal barcode ("MD" + number) and the reduced price; the
 * yellow label carries that barcode, and BarcodeResolver resolves it to the product at that price.
 *
 * What the markdown recovered is read from the stock ledger itself: sales that took stock from the
 * marked-down batch after the markdown (stock_record_batches), net of voids. Sales take batches
 * First-Expiry-First-Out, so a short-dated batch is the one the till sells first.
 *
 * Only for shops with the `markdowns` feature on.
 */
class MarkdownService
{
    public const PREFIX = 'MD';

    public const MIN_PCT = 1;

    public const MAX_PCT = 95;

    public static function available(): bool
    {
        return Schema::hasTable('batch_markdowns');
    }

    /**
     * The reduced price and batch of an active markdown of this product, when its batch still has stock
     * (the till sells a scanned markdown label at this price; checkout accepts exactly this price as
     * already approved). Null when the markdown is unknown, ended, another product's or sold out.
     *
     * @return array{price: float, batch_id: int}|null
     */
    public static function active(int $companyId, int $markdownId, int $stockItemId): ?array
    {
        if (! self::available()) {
            return null;
        }
        $m = DB::table('batch_markdowns as m')->join('stock_batches as b', 'b.id', '=', 'm.stock_batch_id')
            ->where('m.company_id', $companyId)->where('m.id', $markdownId)->where('m.stock_item_id', $stockItemId)
            ->where('m.status', 'active')->where('b.quantity', '>', 0)->first(['m.price', 'm.stock_batch_id']);

        return $m ? ['price' => round((float) $m->price, 2), 'batch_id' => (int) $m->stock_batch_id] : null;
    }

    /** Mark a batch down by $pct percent; an earlier markdown of the same batch ends. */
    public function create(int $companyId, int $userId, int $batchId, float $pct): object
    {
        $company = Company::withoutGlobalScopes()->find($companyId);
        if (! StoreFeatures::enabled($company, 'markdowns') || ! self::available()) {
            throw BusinessRuleException::make('feature_off', 'Markdowns are off for this shop. Turn them on in Settings → Supermarket.');
        }
        $pct = round($pct, 2);
        if ($pct < self::MIN_PCT || $pct > self::MAX_PCT) {
            throw BusinessRuleException::make('invalid_markdown', 'Choose a markdown between '.self::MIN_PCT.'% and '.self::MAX_PCT.'%.');
        }
        $b = ShortDatedService::batch($companyId, $batchId);
        if ((float) $b->quantity <= 0) {
            throw BusinessRuleException::make('batch_empty', 'This batch has nothing left to mark down.');
        }
        $original = round((float) $b->selling_price, 2);
        if ($original <= 0) {
            throw BusinessRuleException::make('no_price', "{$b->name} has no selling price to mark down. Set its price first.");
        }
        $price = round($original * (100 - $pct) / 100, 2);

        return DB::transaction(function () use ($companyId, $userId, $b, $pct, $original, $price) {
            DB::table('batch_markdowns')->where('company_id', $companyId)->where('stock_batch_id', $b->id)->where('status', 'active')
                ->update(['status' => 'ended', 'updated_at' => now()]);
            $id = DB::table('batch_markdowns')->insertGetId([
                'company_id' => $companyId, 'stock_batch_id' => (int) $b->id, 'stock_item_id' => (int) $b->stock_item_id, 'barcode' => 'tmp-'.uniqid('', true),
                'pct' => $pct, 'original_price' => $original, 'price' => $price, 'quantity' => round((float) $b->quantity, 3),
                'status' => 'active', 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $code = self::PREFIX.str_pad((string) $id, 8, '0', STR_PAD_LEFT);
            for ($n = 1; BarcodeService::owner($companyId, $code) !== null; $n++) { // a product already uses it (unlikely): next free variant
                $code = self::PREFIX.str_pad((string) $id, 8, '0', STR_PAD_LEFT).'-'.$n;
            }
            DB::table('batch_markdowns')->where('id', $id)->update(['barcode' => $code]);

            return DB::table('batch_markdowns')->where('id', $id)->first();
        });
    }

    /** End a markdown: its label stops scanning at the reduced price. */
    public function end(int $companyId, int $markdownId): void
    {
        DB::table('batch_markdowns')->where('company_id', $companyId)->where('id', $markdownId)->update(['status' => 'ended', 'updated_at' => now()]);
    }

    /**
     * The active markdown a scanned code names, while its batch still has stock (else null: the code
     * then scans as nothing, or as whatever else owns it).
     */
    public static function forBarcode(int $companyId, string $code): ?object
    {
        if (! str_starts_with(strtoupper($code), self::PREFIX) || ! self::available()) {
            return null;
        }

        return DB::table('batch_markdowns as m')->join('stock_batches as b', 'b.id', '=', 'm.stock_batch_id')
            ->join('stock_items as p', 'p.id', '=', 'm.stock_item_id')
            ->where('m.company_id', $companyId)->where('m.barcode', strtoupper($code))->where('m.status', 'active')->where('b.quantity', '>', 0)
            ->where('p.is_deleted', 0)->where('p.is_active', 1)
            ->first(['m.id', 'm.stock_batch_id', 'm.price', 'p.id as stock_item_id', 'p.sold_by', 'p.selling_price']);
    }

    /** A markdown with what its label shows. */
    public static function label(int $companyId, int $markdownId): ?object
    {
        return DB::table('batch_markdowns as m')->join('stock_batches as b', 'b.id', '=', 'm.stock_batch_id')->join('stock_items as p', 'p.id', '=', 'm.stock_item_id')
            ->where('m.company_id', $companyId)->where('m.id', $markdownId)
            ->first(['m.*', 'p.name', 'b.batch_number', 'b.expiry_date', 'b.quantity as on_hand']);
    }

    /**
     * Money recovered by markdowns versus stock written off, for local days $from..$to. Also refreshes
     * each markdown's sold_qty / sold_value (all time) from the ledger.
     *
     * @return array{recovered: float, recovered_qty: float, full_price: float, written_off: float, written_off_qty: float, markdowns: int}
     */
    public function report(int $companyId, string $from, string $to): array
    {
        $tz = \App\Support\LocalDate::timezone($companyId);
        $start = Carbon::parse($from, $tz)->startOfDay()->utc();
        $end = Carbon::parse($to, $tz)->endOfDay()->utc();
        $out = ['recovered' => 0.0, 'recovered_qty' => 0.0, 'full_price' => 0.0, 'written_off' => 0.0, 'written_off_qty' => 0.0, 'markdowns' => 0];

        if (self::available()) {
            // Sales out of a marked-down batch after its markdown (a void's reversal links give it back).
            $sold = fn () => DB::table('batch_markdowns as m')
                ->join('stock_record_batches as a', 'a.stock_batch_id', '=', 'm.stock_batch_id')
                ->join('stock_records as r', 'r.id', '=', 'a.stock_record_id')
                ->where('m.company_id', $companyId)->where('r.company_id', $companyId)->where('r.type', 'Sale')->whereColumn('r.created_at', '>=', 'm.created_at')
                // a later markdown of the same batch takes over from its start
                ->whereNotExists(fn ($q) => $q->from('batch_markdowns as n')->whereColumn('n.stock_batch_id', 'm.stock_batch_id')->whereColumn('n.id', '>', 'm.id')->whereColumn('n.created_at', '<=', 'r.created_at'));
            foreach ((clone $sold())->groupBy('m.id')->selectRaw('m.id, -SUM(a.quantity) AS qty, -SUM(a.quantity * r.selling_price) AS value')->get() as $m) {
                DB::table('batch_markdowns')->where('id', $m->id)->update(['sold_qty' => round((float) $m->qty, 3), 'sold_value' => round((float) $m->value, 2)]);
            }
            $t = (clone $sold())->whereBetween('r.created_at', [$start, $end])
                ->selectRaw('COALESCE(-SUM(a.quantity), 0) AS qty, COALESCE(-SUM(a.quantity * r.selling_price), 0) AS value, COALESCE(-SUM(a.quantity * m.original_price), 0) AS full')->first();
            $out['recovered'] = round((float) $t->value, 2);
            $out['recovered_qty'] = round((float) $t->qty, 3);
            $out['full_price'] = round((float) $t->full, 2);
            $out['markdowns'] = (int) DB::table('batch_markdowns')->where('company_id', $companyId)->whereBetween('created_at', [$start, $end])->count();
        }

        // Written off out of dated batches (expired, damaged, lost, own use), at cost, net of undos.
        $w = DB::table('stock_record_batches as a')->join('stock_records as r', 'r.id', '=', 'a.stock_record_id')->join('stock_batches as b', 'b.id', '=', 'a.stock_batch_id')
            ->where('r.company_id', $companyId)->whereIn('r.type', ShrinkService::WRITE_OFF_TYPES)->whereNotNull('b.expiry_date')
            ->whereBetween('r.created_at', [$start, $end])
            ->selectRaw('COALESCE(-SUM(a.quantity), 0) AS qty, COALESCE(-SUM(a.quantity * r.unit_cost), 0) AS value')->first();
        $out['written_off'] = round((float) $w->value, 2);
        $out['written_off_qty'] = round((float) $w->qty, 3);

        return $out;
    }
}
