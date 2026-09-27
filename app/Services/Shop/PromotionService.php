<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Support\StoreFeatures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Promotions (SUPERMARKET_PLAN.md B3) around the pure PromotionEngine: the shop's promotions (kept 60 s),
 * the till's quote, what checkout records on a sale, and the promotions screen's writes. Only with the
 * `promotions` feature on; a shop without it never reaches this code.
 *
 * The till and SaleService::checkout price a cart through the same quote()/lineFor() code, so the till
 * shows exactly what the sale will record, and nothing a browser sends can make a discount: the
 * browser only sends products, quantities and an optional coupon code.
 */
class PromotionService
{
    public const CACHE_SECONDS = 60;

    public const TARGET_TYPES = ['product', 'category', 'sub_category'];

    private static ?bool $available = null;

    public static function available(): bool
    {
        return self::$available ??= Schema::hasTable('promotions');
    }

    public static function enabled(Company|int|null $company): bool
    {
        $company = is_int($company) ? Company::withoutGlobalScopes()->find($company) : $company;

        return StoreFeatures::enabled($company, 'promotions') && self::available();
    }

    private static function cacheKey(int $companyId): string
    {
        return 'bp.promotions.active.'.$companyId;
    }

    public static function forget(int $companyId): void
    {
        Cache::forget(self::cacheKey($companyId));
    }

    /**
     * The shop's switched-on promotions that have not ended, with their targets (kept 60 s; every change
     * made through this service forgets it). Dates and times are checked by the engine at each call.
     *
     * @return list<array<string, mixed>>
     */
    public static function active(int $companyId): array
    {
        return Cache::remember(self::cacheKey($companyId), self::CACHE_SECONDS, fn () => self::load($companyId, true));
    }

    /** @return list<array<string, mixed>> */
    private static function load(int $companyId, bool $liveOnly, ?int $id = null): array
    {
        $rows = DB::table('promotions')->where('company_id', $companyId)
            ->when($liveOnly, fn ($q) => $q->where('is_active', 1)->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now())))
            ->when($id !== null, fn ($q) => $q->where('id', $id))
            ->orderByDesc('priority')->orderBy('id')->get();
        $targets = $rows->isEmpty() ? collect() : DB::table('promotion_targets')->whereIn('promotion_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('promotion_id');

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'name' => (string) $r->name, 'type' => (string) $r->type, 'rules' => json_decode((string) $r->rules, true) ?: [],
            'starts_at' => $r->starts_at, 'ends_at' => $r->ends_at, 'window' => $r->window ? (json_decode((string) $r->window, true) ?: null) : null,
            'member_only' => (bool) $r->member_only, 'stackable' => (bool) $r->stackable, 'priority' => (int) $r->priority,
            'per_sale_limit' => $r->per_sale_limit !== null ? (int) $r->per_sale_limit : null, 'is_active' => (bool) $r->is_active,
            'code' => $r->code, 'created_at' => $r->created_at,
            'targets' => ($targets[$r->id] ?? collect())->map(fn ($t) => ['type' => (string) $t->target_type, 'id' => (int) $t->target_id])->values()->all(),
        ])->values()->all();
    }

    // ── Pricing a cart (till and checkout) ───────────────────

    /**
     * Should promotions look at this line? Not when the cashier discounted or re-priced it, a markdown
     * label priced it, or it is an open-price key: an automatic promotion never stacks on a manual price.
     */
    public static function eligible(?float $explicitPrice, float $catalogue, float $lineDiscount, bool $markdown, bool $openPrice): bool
    {
        return $lineDiscount <= 0 && ! $markdown && ! $openPrice && ($explicitPrice === null || abs($explicitPrice - $catalogue) < 0.005);
    }

    /** Category and sub-category of products, one query. @return array<int, object> */
    public static function products(int $companyId, array $productIds): array
    {
        return $productIds === [] ? [] : DB::table('stock_items')->where('company_id', $companyId)->whereIn('id', array_values(array_unique($productIds)))
            ->get(['id', 'selling_price', 'open_price', 'stock_category_id', 'stock_sub_category_id'])->keyBy('id')->all();
    }

    /** The shop's "now", in its own timezone (time windows are shop time). */
    public static function now(int $companyId): Carbon
    {
        return now(\App\Support\LocalTime::timezone(Company::withoutGlobalScopes()->find($companyId)));
    }

    /**
     * The engine on a cart. $lines keyed; each {product_id, category_id, sub_category_id, quantity, unit_factor, unit_price, eligible}.
     * $context: {customer_id?, level?, coupon?, now?}.
     */
    public static function run(int $companyId, array $lines, array $context): array
    {
        $context['now'] ??= self::now($companyId);

        return PromotionEngine::apply(self::active($companyId), $lines, $context);
    }

    /**
     * What the till shows for a cart: each line's catalogue price (level prices and quantity breaks, B2) and
     * promotion discount (B3), the promotions that apply and what the customer saves. The same code checkout
     * runs; one engine call.
     *
     * @param  array<string|int, array<string, mixed>>  $items  keyed: {stock_item_id, quantity, unit_id?, unit_factor?, unit_price? (explicit, re-priced), discount_amount?, markdown_id?, open_price?}
     * @param  array{customer_id?: ?int, coupon?: ?string}  $context
     * @return array{lines: array<string|int, array{unit_price: float, discount: float, names: list<string>}>, applied: list<array{promotion_id: int, name: string, amount: float}>, saved: float, coupon: ?array}
     */
    public static function quote(int $companyId, array $items, array $context = []): array
    {
        $company = Company::withoutGlobalScopes()->find($companyId);
        $levels = PriceLevelService::enabled($company);
        $promos = self::enabled($company);
        $out = ['lines' => [], 'applied' => [], 'saved' => 0.0, 'coupon' => null];
        // Store prices (G1): with context location_id, the store's own prices replace the selling price, as at checkout.
        $location = ! empty($context['location_id']) && PriceLevelService::storePricesOn($company) ? (int) $context['location_id'] : null;
        if ((! $levels && ! $promos && $location === null) || $items === []) {
            return $out;
        }
        $customerId = ! empty($context['customer_id']) ? (int) $context['customer_id'] : null;
        $level = $levels ? PriceLevelService::levelOf($companyId, $customerId) : null;
        $ids = array_map(fn ($i) => (int) $i['stock_item_id'], $items);
        $products = self::products($companyId, $ids);
        $rows = $levels ? PriceLevelService::rowsFor($companyId, $ids) : [];
        $store = $location !== null ? PriceLevelService::locationRows($companyId, $location, $ids) : [];
        $deptKeys = StoreFeatures::enabled($company, 'department_keys');
        $engine = [];
        foreach ($items as $key => $i) {
            $p = $products[(int) $i['stock_item_id']] ?? null;
            if ($p === null) {
                continue;
            }
            $factor = max(0.001, (float) ($i['unit_factor'] ?? 1));
            $unitId = ! empty($i['unit_id']) ? (int) $i['unit_id'] : null;
            $qty = (float) ($i['quantity'] ?? 0);
            $catalogue = ($levels ? PriceLevelService::pick($rows[(int) $p->id] ?? [], $unitId, $factor, $qty, $level) : null)
                ?? PriceLevelService::storeBase($store[(int) $p->id] ?? null, (float) $p->selling_price, $unitId, $factor);
            $explicit = isset($i['unit_price']) && $i['unit_price'] !== null ? round((float) $i['unit_price'], 2) : null;
            $open = ! empty($i['open_price']) || ($deptKeys && $p->open_price);
            $eligible = self::eligible($explicit, $catalogue, (float) ($i['discount_amount'] ?? 0), ! empty($i['markdown_id']), $open);
            $out['lines'][$key] = ['unit_price' => $catalogue, 'discount' => 0.0, 'names' => []];
            $engine[$key] = self::lineFor($p, $qty, $factor, $explicit ?? $catalogue, $eligible);
        }
        if ($promos && $engine !== []) {
            $r = self::run($companyId, $engine, ['customer_id' => $customerId, 'level' => $level, 'coupon' => $context['coupon'] ?? null,
                'now' => now(\App\Support\LocalTime::timezone($company))]);
            $names = collect($r['applied'])->pluck('name', 'promotion_id')->all();
            foreach ($r['lines'] as $key => $l) {
                $out['lines'][$key]['discount'] = $l['discount'];
                $out['lines'][$key]['names'] = array_values(array_map(fn ($id) => $names[$id] ?? '', array_keys($l['promotions'])));
            }
            $out['applied'] = $r['applied'];
            $out['saved'] = $r['total'];
            $out['coupon'] = $r['coupon'];
        }

        return $out;
    }

    /** One engine line. */
    public static function lineFor(object $product, float $qty, float $factor, float $unitPrice, bool $eligible): array
    {
        return ['product_id' => (int) $product->id, 'category_id' => (int) ($product->stock_category_id ?? 0), 'sub_category_id' => (int) ($product->stock_sub_category_id ?? 0),
            'quantity' => $qty, 'unit_factor' => $factor, 'unit_price' => $unitPrice, 'eligible' => $eligible];
    }

    /** Checkout: remember what each promotion gave on the sale. @param list<array{promotion_id: int, name: string, amount: float}> $applied */
    public static function record(int $companyId, int $saleId, array $applied): void
    {
        $rows = [];
        foreach ($applied as $a) {
            if ((float) $a['amount'] >= 0.005) {
                $rows[] = ['company_id' => $companyId, 'sale_id' => $saleId, 'promotion_id' => $a['promotion_id'] ?: null, 'name' => mb_substr((string) $a['name'], 0, 160),
                    'amount' => round((float) $a['amount'], 2), 'created_at' => now(), 'updated_at' => now()];
            }
        }
        if ($rows !== []) {
            DB::table('sale_promotions')->insert($rows);
        }
    }

    /**
     * The promotions a sale got, for its receipt ("Juice 3 for 10,000 −2,000", "You saved X").
     *
     * @return array{rows: list<array{name: string, amount: float}>, saved: float}
     */
    public static function forSale(object $sale): array
    {
        if (! self::available()) {
            return ['rows' => [], 'saved' => 0.0];
        }
        $rows = DB::table('sale_promotions')->where('company_id', $sale->company_id)->where('sale_id', $sale->id)->orderBy('id')->get(['name', 'amount'])
            ->map(fn ($r) => ['name' => (string) $r->name, 'amount' => (float) $r->amount])->all();

        return ['rows' => $rows, 'saved' => round(array_sum(array_column($rows, 'amount')), 2)];
    }

    // ── The promotions screen ────────────────────────────────

    /** @return list<array<string, mixed>> every promotion of the shop, newest first */
    public function list(int $companyId): array
    {
        return self::available() ? array_reverse(self::load($companyId, false)) : [];
    }

    public function find(int $companyId, int $id): array
    {
        $p = self::available() ? (self::load($companyId, false, $id)[0] ?? null) : null;
        if ($p === null) {
            throw BusinessRuleException::make('promotion_not_found', 'That promotion was not found.');
        }

        return $p;
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:'.implode(',', array_keys(PromotionEngine::TYPES))],
            'percent' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:1000000000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'buy' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'get' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'qty' => ['nullable', 'integer', 'min:2', 'max:1000'],
            'min_spend' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'days' => ['nullable', 'array'],
            'days.*' => ['integer', 'between:1,7'],
            'from' => ['nullable', 'date_format:H:i'],
            'to' => ['nullable', 'date_format:H:i'],
            'member_only' => ['nullable', 'boolean'],
            'stackable' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:-100', 'max:100'],
            'per_sale_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-_]+$/'],
            'targets' => ['nullable', 'array', 'max:50'],
            'targets.*.type' => ['required', 'in:'.implode(',', self::TARGET_TYPES)],
            'targets.*.id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Create or change a promotion. $data: name, type, the type's numbers (percent / amount / price / buy + get
     * [+ percent] / qty + price / min_spend + amount or percent), starts_at / ends_at (shop time), days, from, to,
     * member_only, stackable, priority, per_sale_limit, is_active, code, targets [{type, id}].
     */
    public function save(int $companyId, int $userId, array $data, ?int $id = null): array
    {
        if (! self::enabled($companyId)) {
            throw BusinessRuleException::make('feature_off', 'Promotions are off for this shop. Turn them on in Settings → Supermarket.');
        }
        $v = Validator::make($data, self::rules(), [], ['per_sale_limit' => 'limit per sale', 'min_spend' => 'minimum spend', 'qty' => 'how many'])->validate();
        $type = $v['type'];
        $need = fn (string $field, string $why) => isset($v[$field]) && $v[$field] !== null && $v[$field] !== '' ? $v[$field]
            : throw BusinessRuleException::make('invalid_promotion', $why, ['field' => $field]);
        $rules = match ($type) {
            'percent_off' => ['percent' => (float) $need('percent', 'Enter the % off.')],
            'amount_off' => ['amount' => (float) $need('amount', 'Enter the amount off each item.')],
            'fixed_price' => ['price' => (float) $need('price', 'Enter the promotional price.')],
            'buy_x_get_y' => ['buy' => (int) $need('buy', 'Enter how many they buy.'), 'get' => (int) $need('get', 'Enter how many they get.'), 'percent' => (float) ($v['percent'] ?? 100)],
            'mix_match' => ['qty' => (int) $need('qty', 'Enter how many items make the deal.'), 'price' => (float) $need('price', 'Enter the price for them together.')],
            'bundle' => ['price' => (float) $need('price', 'Enter the price for the bundle.')],
            'spend_save', 'coupon' => ['min_spend' => (float) ($v['min_spend'] ?? 0)] + (! empty($v['percent']) ? ['percent' => (float) $v['percent']]
                : ['amount' => (float) $need('amount', 'Enter the amount they save, or a % off.')]),
        };
        if ($type === 'spend_save' && ($rules['min_spend'] ?? 0) <= 0) {
            throw BusinessRuleException::make('invalid_promotion', 'Enter how much they must spend.', ['field' => 'min_spend']);
        }
        $targets = collect($v['targets'] ?? [])->map(fn ($t) => ['type' => $t['type'], 'id' => (int) $t['id']])->unique(fn ($t) => $t['type'].':'.$t['id'])->values()->all();
        if (! in_array($type, PromotionEngine::CART_TYPES, true) && $targets === []) {
            throw BusinessRuleException::make('invalid_promotion', 'Choose the products or categories this promotion is for.', ['field' => 'targets']);
        }
        if ($type === 'bundle' && count($targets) < 2) {
            throw BusinessRuleException::make('invalid_promotion', 'A bundle needs at least two products.', ['field' => 'targets']);
        }
        foreach ($targets as $t) {
            $table = ['product' => 'stock_items', 'category' => 'stock_categories', 'sub_category' => 'stock_sub_categories'][$t['type']];
            if (! DB::table($table)->where('company_id', $companyId)->where('id', $t['id'])->exists()) {
                throw BusinessRuleException::make('target_not_found', 'One of the chosen '.str_replace('_', '-', $t['type']).'s was not found.', ['field' => 'targets']);
            }
        }
        $code = isset($v['code']) && trim((string) $v['code']) !== '' ? strtoupper(trim($v['code'])) : null;
        if ($type === 'coupon' && $code === null) {
            throw BusinessRuleException::make('invalid_promotion', 'Give the coupon a code the cashier types at the till.', ['field' => 'code']);
        }
        if ($code !== null && DB::table('promotions')->where('company_id', $companyId)->where('code', $code)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
            throw BusinessRuleException::make('duplicate_code', "Another promotion already uses the code {$code}.", ['field' => 'code']);
        }
        $tz = \App\Support\LocalTime::timezone(Company::withoutGlobalScopes()->find($companyId));
        $starts = ! empty($v['starts_at']) ? Carbon::parse($v['starts_at'], $tz)->utc() : null;
        $ends = ! empty($v['ends_at']) ? Carbon::parse($v['ends_at'], $tz)->utc() : null;
        if ($starts && $ends && $ends->lessThanOrEqualTo($starts)) {
            throw BusinessRuleException::make('invalid_promotion', 'The end must be after the start.', ['field' => 'ends_at']);
        }
        $days = array_values(array_unique(array_map('intval', (array) ($v['days'] ?? []))));
        sort($days);
        $window = ($days !== [] && count($days) < 7) || (! empty($v['from']) && ! empty($v['to']))
            ? array_filter(['days' => $days !== [] && count($days) < 7 ? $days : null, 'from' => $v['from'] ?? null, 'to' => $v['to'] ?? null], fn ($x) => $x !== null && $x !== '') : null;
        $row = [
            'name' => trim($v['name']), 'type' => $type, 'rules' => json_encode($rules), 'starts_at' => $starts, 'ends_at' => $ends,
            'window' => $window ? json_encode($window) : null, 'member_only' => (bool) ($v['member_only'] ?? false), 'stackable' => (bool) ($v['stackable'] ?? false),
            'priority' => (int) ($v['priority'] ?? 0), 'per_sale_limit' => $v['per_sale_limit'] ?? null, 'is_active' => (bool) ($v['is_active'] ?? true),
            'code' => $code, 'updated_at' => now(),
        ];

        $id = DB::transaction(function () use ($companyId, $userId, $row, $targets, $id) {
            if ($id !== null) {
                $this->find($companyId, $id);
                DB::table('promotions')->where('company_id', $companyId)->where('id', $id)->update($row);
                DB::table('promotion_targets')->where('promotion_id', $id)->delete();
            } else {
                $id = DB::table('promotions')->insertGetId($row + ['company_id' => $companyId, 'created_by' => $userId, 'created_at' => now()]);
            }
            if ($targets !== []) {
                DB::table('promotion_targets')->insert(array_map(fn ($t) => ['promotion_id' => $id, 'target_type' => $t['type'], 'target_id' => $t['id']], $targets));
            }

            return $id;
        });
        self::forget($companyId);

        return $this->find($companyId, $id);
    }

    /** Pause or restart a promotion. */
    public function setActive(int $companyId, int $id, bool $on): array
    {
        $this->find($companyId, $id);
        DB::table('promotions')->where('company_id', $companyId)->where('id', $id)->update(['is_active' => $on, 'updated_at' => now()]);
        self::forget($companyId);

        return $this->find($companyId, $id);
    }

    /** Delete a promotion that no sale used yet (one that did is paused instead, so its results stay). */
    public function delete(int $companyId, int $id): void
    {
        $this->find($companyId, $id);
        $used = DB::table('sale_promotions')->where('company_id', $companyId)->where('promotion_id', $id)->count();
        if ($used > 0) {
            throw BusinessRuleException::make('promotion_used', "This promotion was given on {$used} ".($used === 1 ? 'sale' : 'sales').'. Pause it instead, so its results stay in the report.');
        }
        DB::transaction(function () use ($companyId, $id) {
            DB::table('promotion_targets')->where('promotion_id', $id)->delete();
            DB::table('promotions')->where('company_id', $companyId)->where('id', $id)->delete();
        });
        self::forget($companyId);
    }

    /**
     * A worked example for the form: a small basket of the first target product, priced by this promotion
     * alone (dates, times, member and code set aside). Null when there is nothing to show yet.
     *
     * @return array{qty: float, product: string, before: float, after: float}|null
     */
    public function example(int $companyId, array $promotion): ?array
    {
        $p = PromotionEngine::normalise($promotion + ['is_active' => true]);
        $p['code'] = null;
        $p['member_only'] = false;
        $p['starts_at'] = $p['ends_at'] = $p['window'] = null;
        if ($p['type'] === 'coupon') {
            $p['type'] = 'spend_save';
        }
        $r = $p['rules'];
        $cart = [];
        $pick = function (array $t) use ($companyId) {
            $col = ['product' => 'id', 'category' => 'stock_category_id', 'sub_category' => 'stock_sub_category_id'][$t['type']] ?? 'id';

            return DB::table('stock_items')->where('company_id', $companyId)->where($col, $t['id'])->where('is_deleted', 0)->where('selling_price', '>', 0)
                ->orderBy('id')->first(['id', 'name', 'selling_price', 'stock_category_id', 'stock_sub_category_id']);
        };
        $targets = $p['targets'];
        if ($p['type'] === 'bundle') {
            foreach ($targets as $i => $t) {
                if ($prod = $pick($t)) {
                    $cart['t'.$i] = [$prod, 1];
                }
            }
        } elseif ($targets !== [] && ($prod = $pick($targets[0]))) {
            $qty = match ($p['type']) {
                'buy_x_get_y' => max(1, (int) ($r['buy'] ?? 1)) + max(1, (int) ($r['get'] ?? 1)),
                'mix_match' => max(1, (int) ($r['qty'] ?? 1)),
                'spend_save' => max(1, (int) ceil((float) ($r['min_spend'] ?? 0) / max(0.01, (float) $prod->selling_price))),
                default => 1,
            };
            $cart['t0'] = [$prod, $qty];
        } elseif (in_array($p['type'], PromotionEngine::CART_TYPES, true)) {
            $prod = DB::table('stock_items')->where('company_id', $companyId)->where('is_deleted', 0)->where('selling_price', '>', 0)->orderBy('id')
                ->first(['id', 'name', 'selling_price', 'stock_category_id', 'stock_sub_category_id']);
            if ($prod) {
                $cart['t0'] = [$prod, max(1, (int) ceil((float) ($r['min_spend'] ?? 0) / (float) $prod->selling_price))];
            }
        }
        if ($cart === []) {
            return null;
        }
        $lines = [];
        $before = 0.0;
        foreach ($cart as $key => [$prod, $qty]) {
            $lines[$key] = self::lineFor($prod, (float) $qty, 1.0, (float) $prod->selling_price, true);
            $before += $qty * (float) $prod->selling_price;
        }
        $res = PromotionEngine::apply([$p], $lines, ['now' => now(), 'customer_id' => 1]);
        $first = reset($cart);

        return ['qty' => (float) array_sum(array_map(fn ($c) => $c[1], $cart)), 'product' => implode(' + ', array_map(fn ($c) => (string) $c[0]->name, $cart)),
            'single' => count($cart) === 1 ? (string) $first[0]->name : null, 'before' => round($before, 2), 'after' => round($before - $res['total'], 2)];
    }
}
