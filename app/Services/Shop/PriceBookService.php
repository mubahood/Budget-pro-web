<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\PriceChange;
use App\Models\StockItem;
use App\Support\StoreFeatures;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The price book (SUPERMARKET_PLAN.md B1, StoreFeatures `price_book`): every selling/buying price
 * change is kept (old → new, by whom, when, why), and a change can be scheduled to start later.
 *
 * - observe() is StockItem's `saved` hook: with `price_book` on, every price change made through the
 *   model (web, new interface, API, import, sync) is logged; with `shelf_labels` on, a changed selling
 *   price and a new product join the label queue. Off = it does nothing. It never fails the save.
 * - Scheduled changes are applied by `prices:apply-due` (every five minutes) through the model, so the
 *   product's own hooks (sync version, labels, barcodes…) run as for any edit.
 */
class PriceBookService
{
    public const FIELDS = ['selling' => 'selling_price', 'buying' => 'buying_price'];

    public const MAX_PRICE = 1_000_000_000_000;

    /** Set while this service saves a product, so observe() can attach the reason / scheduled row. */
    private static ?array $context = null;

    private static ?bool $ready = null;

    /**
     * Schedule a price change for later (starts_at in UTC, in the future).
     */
    public function schedule(int $companyId, int $itemId, string $field, float $new, CarbonInterface|string $startsAt, ?string $reason = null, ?int $userId = null, ?int $unitId = null, ?int $locationId = null): PriceChange
    {
        $this->check($field, $new);
        $item = $this->item($companyId, $itemId);
        $at = Carbon::parse($startsAt)->utc();
        if ($at->lte(now())) {
            throw BusinessRuleException::make('price_change_past', 'Pick a date and time in the future, or change the price now.');
        }

        return PriceChange::create([
            'company_id' => $companyId, 'stock_item_id' => $item->id, 'unit_id' => $unitId, 'field' => $field,
            'old' => null, 'new' => round($new, 2), 'starts_at' => $at, 'reason' => self::clean($reason), 'created_by' => $userId,
        ] + ($locationId !== null ? ['location_id' => $locationId] : [])); // one store's price (G1, StorePriceService)
    }

    /**
     * Change a price now, through the model, with a reason. Logged even when `price_book` is off
     * (only the price book screens call this).
     */
    public function changeNow(int $companyId, int $itemId, string $field, float $new, ?string $reason = null, ?int $userId = null): StockItem
    {
        $this->check($field, $new);
        $item = $this->item($companyId, $itemId);
        $column = self::FIELDS[$field];
        if (abs((float) $item->{$column} - $new) < 0.005) {
            throw BusinessRuleException::make('same_price', 'That is already the '.($field === 'selling' ? 'selling' : 'buying').' price.');
        }
        $this->saveWithContext($item, [$column => round($new, 2)], ['reason' => self::clean($reason), 'user' => $userId, 'force' => true]);

        return $item->fresh();
    }

    /**
     * Apply every scheduled change that is due (all shops, or one), oldest first. A change that cannot be
     * saved (the product was deleted, a rule refuses it) is cancelled and logged, so it is not retried forever.
     *
     * @return int how many were applied
     */
    public function applyDue(?int $companyId = null, ?CarbonInterface $now = null): int
    {
        if (! self::ready()) {
            return 0;
        }
        $now = $now ? Carbon::parse($now)->utc() : now();
        $applied = 0;
        $due = PriceChange::query()->whereNull('applied_at')->whereNull('cancelled_at')->whereNotNull('starts_at')->where('starts_at', '<=', $now)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))->orderBy('starts_at')->orderBy('id')->limit(1000)->get();
        foreach ($due as $change) {
            try {
                DB::transaction(function () use ($change, &$applied) {
                    $row = PriceChange::query()->lockForUpdate()->find($change->id);
                    if (! $row || $row->applied_at || $row->cancelled_at) {
                        return; // another run took it
                    }
                    $item = StockItem::withoutGlobalScopes()->where('company_id', $row->company_id)->where('is_deleted', 0)->find($row->stock_item_id);
                    if (! $item) {
                        $row->forceFill(['cancelled_at' => now()])->save();

                        return;
                    }
                    if (! empty($row->location_id)) { // one store's price (G1): the store's row, not the product
                        $old = (new StorePriceService())->setPrice((int) $row->company_id, (int) $row->location_id, (int) $item->id, $row->unit_id ? (int) $row->unit_id : null, (float) $row->new);
                        $row->forceFill(['old' => $old, 'applied_at' => now()])->save();
                        $applied++;

                        return;
                    }
                    $column = self::FIELDS[$row->field] ?? null;
                    if ($column === null) {
                        $row->forceFill(['cancelled_at' => now()])->save();

                        return;
                    }
                    $old = (float) $item->{$column};
                    if (abs($old - (float) $row->new) >= 0.005) {
                        $this->saveWithContext($item, [$column => (float) $row->new], ['change' => $row]);
                    }
                    $row->forceFill(['old' => $old, 'applied_at' => now()])->save();
                    if ($row->field === 'selling') {
                        ShelfLabelService::queueIfOn((int) $row->company_id, (int) $item->id, 'price_change', $row->unit_id ? (int) $row->unit_id : null);
                    }
                    $applied++;
                });
            } catch (\Throwable $e) {
                Log::warning('prices:apply-due could not apply price change '.$change->id.': '.$e->getMessage());
                PriceChange::query()->whereKey($change->id)->whereNull('applied_at')->update(['cancelled_at' => now(), 'updated_at' => now()]);
            }
        }

        return $applied;
    }

    /** Log a change that has already happened (applied now). */
    public function record(int $companyId, int $itemId, string $field, ?float $old, float $new, ?string $reason = null, ?int $userId = null, ?int $unitId = null, ?int $locationId = null): PriceChange
    {
        $at = now();

        return PriceChange::create([
            'company_id' => $companyId, 'stock_item_id' => $itemId, 'unit_id' => $unitId, 'field' => $field,
            'old' => $old === null ? null : round($old, 2), 'new' => round($new, 2), 'starts_at' => $at, 'applied_at' => $at,
            'reason' => self::clean($reason), 'created_by' => $userId,
        ] + ($locationId !== null ? ['location_id' => $locationId] : []));
    }

    /**
     * Applied changes of a product, newest first.
     *
     * @param  list<string>  $fields  selling, buying
     * @return list<array{id: int, field: string, old: ?float, new: float, at: string, reason: ?string, who: ?string}>
     */
    public function history(int $companyId, int $itemId, array $fields = ['selling', 'buying'], int $limit = 50): array
    {
        return $this->rows($companyId, $itemId, $fields)->whereNotNull('p.applied_at')
            ->orderByDesc('p.applied_at')->orderByDesc('p.id')->limit($limit)->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'field' => (string) $r->field, 'old' => $r->old === null ? null : (float) $r->old, 'new' => (float) $r->new,
                'at' => (string) $r->applied_at, 'reason' => $r->reason, 'who' => $r->who])->all();
    }

    /**
     * Changes waiting for their start, soonest first.
     *
     * @param  list<string>  $fields
     * @return list<array{id: int, field: string, new: float, starts_at: string, reason: ?string, who: ?string}>
     */
    public function scheduled(int $companyId, int $itemId, array $fields = ['selling', 'buying']): array
    {
        return $this->rows($companyId, $itemId, $fields)->whereNull('p.applied_at')->whereNull('p.cancelled_at')
            ->orderBy('p.starts_at')->orderBy('p.id')->limit(100)->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'field' => (string) $r->field, 'new' => (float) $r->new,
                'starts_at' => (string) $r->starts_at, 'reason' => $r->reason, 'who' => $r->who])->all();
    }

    /** Cancel a scheduled change. Already-applied ones cannot be cancelled (change the price again instead). */
    public function cancel(int $companyId, int $changeId, ?array $fields = null): void
    {
        $row = PriceChange::query()->where('company_id', $companyId)->when($fields !== null, fn ($q) => $q->whereIn('field', $fields))->find($changeId);
        if (! $row || $row->cancelled_at) {
            throw BusinessRuleException::make('price_change_gone', 'That scheduled price change no longer exists.');
        }
        if ($row->applied_at) {
            throw BusinessRuleException::make('price_change_applied', 'That price change has already started. Change the price again instead.');
        }
        $row->forceFill(['cancelled_at' => now()])->save();
    }

    /**
     * StockItem `saved` hook. Does nothing unless the shop has `price_book` or `shelf_labels` on; never throws.
     */
    public static function observe(StockItem $item): void
    {
        try {
            $created = $item->wasRecentlyCreated && ! $item->wasChanged();
            $changed = array_filter(self::FIELDS, fn ($column) => $item->wasChanged($column) && abs((float) $item->getOriginal($column) - (float) $item->{$column}) >= 0.005);
            $context = self::$context;
            if (($context === null && ! $created && $changed === []) || ! self::ready()) {
                return;
            }
            $company = Company::withoutGlobalScopes()->find($item->company_id);
            if ($created) {
                if (StoreFeatures::enabled($company, 'shelf_labels')) {
                    ShelfLabelService::queue((int) $item->company_id, (int) $item->id, 'new');
                }

                return;
            }
            if ($changed === []) {
                return;
            }
            $scheduled = $context['change'] ?? null;
            if ($scheduled === null && (($context['force'] ?? false) || StoreFeatures::enabled($company, 'price_book'))) {
                foreach ($changed as $field => $column) {
                    (new self)->record((int) $item->company_id, (int) $item->id, $field, (float) $item->getOriginal($column), (float) $item->{$column},
                        $context['reason'] ?? null, $context['user'] ?? self::currentUserId());
                }
            }
            if (isset($changed['selling']) && $scheduled === null) {
                ShelfLabelService::queueIfOn((int) $item->company_id, (int) $item->id, 'price_change', null, $company);
            }
        } catch (\Throwable $e) {
            Log::warning('Price book could not log a change of product '.$item->id.': '.$e->getMessage());
        }
    }

    private function saveWithContext(StockItem $item, array $values, array $context): void
    {
        $before = self::$context;
        self::$context = $context;
        try {
            $item->forceFill($values)->save();
        } finally {
            self::$context = $before;
        }
    }

    private function rows(int $companyId, int $itemId, array $fields)
    {
        return DB::table('price_changes as p')->leftJoin('admin_users as u', 'u.id', '=', 'p.created_by')
            ->where('p.company_id', $companyId)->where('p.stock_item_id', $itemId)->whereIn('p.field', $fields)
            ->when(self::hasLocations(), fn ($q) => $q->whereNull('p.location_id')) // a store's own prices are listed with the store prices (G1)
            ->select(['p.id', 'p.field', 'p.old', 'p.new', 'p.starts_at', 'p.applied_at', 'p.reason', 'u.name as who']);
    }

    private function check(string $field, float $new): void
    {
        if (! isset(self::FIELDS[$field])) {
            throw BusinessRuleException::make('price_field', 'Choose the selling or the buying price.');
        }
        if ($new < 0 || $new > self::MAX_PRICE || ! is_finite($new)) {
            throw BusinessRuleException::make('price_invalid', 'Enter a price of zero or more.');
        }
    }

    private function item(int $companyId, int $itemId): StockItem
    {
        $item = StockItem::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->find($itemId);
        if (! $item) {
            throw BusinessRuleException::make('product_not_found', 'That product no longer exists.');
        }

        return $item;
    }

    private static function clean(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : mb_substr($reason, 0, 191);
    }

    private static function currentUserId(): ?int
    {
        foreach (['web', 'admin', null] as $guard) {
            try {
                $id = Auth::guard($guard)->id();
            } catch (\Throwable) {
                $id = null;
            }
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }

    private static ?bool $locations = null;

    /** price_changes can hold one store's price (G1 migration). */
    public static function hasLocations(): bool
    {
        return self::$locations ??= Schema::hasColumn('price_changes', 'location_id');
    }

    /** The tables exist (a database that has not been migrated yet must still save products). */
    private static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('price_changes') && Schema::hasTable('label_queue');
    }
}
