<?php

namespace App\Services\Shop;

use Illuminate\Support\Carbon;

/**
 * The promotions engine (SUPERMARKET_PLAN.md B3). PURE: no database, no clock of its own. Given the
 * shop's promotions, the cart and the context (customer, level, now in the shop's timezone, coupon), it
 * returns the discount of every line and the promotions that gave it, by name.
 *
 * Rules:
 *  - Quantities are counted in base units (a 6-pack is six juices) and prices per base unit.
 *  - Only eligible lines take part (not a line the cashier discounted or re-priced, not a markdown label,
 *    not an open-price key): an automatic promotion never stacks on a manual price.
 *  - The best price for the customer wins: item promotions are applied biggest saving first, then the
 *    whole-cart ones (spend and save, coupons), each on what is left.
 *  - A non-stackable promotion takes units no other promotion has touched, and no other promotion may
 *    touch them afterwards. Stackable promotions combine with each other on the same units.
 *  - A non-stackable whole-cart promotion is also tried on its own; it wins when it saves more.
 *  - A promotion's discount is shared over the lines it used in proportion to their value, so a return
 *    refunds the right share.
 *
 * Promotion: {id, name, type, rules[], starts_at?, ends_at? (UTC), window? {days: [1..7], from: "HH:MM", to: "HH:MM"},
 *             member_only, stackable, priority, per_sale_limit?, is_active, code?, targets: list<{type, id}>}
 * Line (keyed): {product_id, category_id?, sub_category_id?, quantity, unit_factor?, unit_price (per sold unit), eligible}
 * Context: {customer_id?, level?, now: Carbon (shop time), coupon?}
 */
final class PromotionEngine
{
    public const TYPES = [
        'percent_off' => '% off',
        'amount_off' => 'Amount off each',
        'fixed_price' => 'Promotional price',
        'buy_x_get_y' => 'Buy X get Y',
        'mix_match' => 'Any N for a price',
        'bundle' => 'Bundle price',
        'spend_save' => 'Spend and save',
        'coupon' => 'Coupon',
    ];

    public const CART_TYPES = ['spend_save', 'coupon'];

    private const EPS = 0.0005;

    /**
     * @param  list<array<string, mixed>>  $promotions
     * @param  array<string|int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $context
     * @return array{lines: array<string|int, array{discount: float, promotions: array<int, float>}>, applied: list<array{promotion_id: int, name: string, amount: float}>, total: float, coupon: ?array{code: string, ok: bool, message: string}}
     */
    public static function apply(array $promotions, array $lines, array $context): array
    {
        $now = $context['now'] ?? Carbon::now();
        $coupon = strtoupper(trim((string) ($context['coupon'] ?? '')));
        $state = self::state($lines);
        $live = [];
        $couponKnown = false;
        foreach ($promotions as $p) {
            $p = self::normalise($p);
            if ($coupon !== '' && $p['code'] !== null && $p['code'] === $coupon) {
                $couponKnown = true;
            }
            if (self::applies($p, $now, $context, $coupon)) {
                $live[] = $p;
            }
        }

        $result = self::greedy($live, $state);
        // A non-stackable whole-cart promotion alone may beat everything else together.
        foreach ($live as $p) {
            if (! $p['stackable'] && in_array($p['type'], self::CART_TYPES, true)) {
                $alone = self::greedy([$p], $state);
                if ($alone['total'] > $result['total'] + 0.004) {
                    $result = $alone;
                }
            }
        }

        $out = [];
        foreach ($lines as $key => $l) {
            $out[$key] = ['discount' => round($result['lines'][$key] ?? 0, 2), 'promotions' => $result['by_line'][$key] ?? []];
        }
        $couponInfo = null;
        if ($coupon !== '') {
            $used = collect($result['applied'])->contains(fn ($a) => $a['code'] === $coupon);
            $couponInfo = ['code' => $coupon, 'ok' => $used,
                'message' => $used ? 'Coupon applied.' : ($couponKnown ? 'This coupon does not apply to this sale.' : 'This coupon code is not known.')];
        }

        return ['lines' => $out, 'applied' => array_map(fn ($a) => ['promotion_id' => $a['promotion_id'], 'name' => $a['name'], 'amount' => $a['amount']], $result['applied']),
            'total' => round($result['total'], 2), 'coupon' => $couponInfo];
    }

    /** One promotion, typed and with defaults. */
    public static function normalise(array $p): array
    {
        $rules = $p['rules'] ?? [];
        $rules = is_string($rules) ? (json_decode($rules, true) ?: []) : (array) $rules;
        $window = $p['window'] ?? null;
        $window = is_string($window) ? (json_decode($window, true) ?: null) : $window;
        $code = isset($p['code']) && trim((string) $p['code']) !== '' ? strtoupper(trim((string) $p['code'])) : null;

        return [
            'id' => (int) ($p['id'] ?? 0), 'name' => (string) ($p['name'] ?? 'Promotion'), 'type' => (string) ($p['type'] ?? ''), 'rules' => $rules,
            'starts_at' => $p['starts_at'] ?? null, 'ends_at' => $p['ends_at'] ?? null, 'window' => is_array($window) ? $window : null,
            'member_only' => (bool) ($p['member_only'] ?? false), 'stackable' => ($p['type'] ?? '') === 'coupon' || (bool) ($p['stackable'] ?? false), // a coupon always adds to the deals the customer already has
            'priority' => (int) ($p['priority'] ?? 0),
            'per_sale_limit' => isset($p['per_sale_limit']) && (int) $p['per_sale_limit'] > 0 ? (int) $p['per_sale_limit'] : null,
            'is_active' => (bool) ($p['is_active'] ?? true), 'code' => $code,
            'targets' => array_values(array_map(fn ($t) => ['type' => (string) ($t['type'] ?? $t['target_type'] ?? ''), 'id' => (int) ($t['id'] ?? $t['target_id'] ?? 0)], (array) ($p['targets'] ?? []))),
        ];
    }

    /** Is this promotion on for this sale, now? */
    public static function applies(array $p, Carbon $now, array $context, string $coupon = ''): bool
    {
        if (! $p['is_active'] || ! isset(self::TYPES[$p['type']])) {
            return false;
        }
        if ($p['type'] === 'coupon' && $p['code'] === null) {
            return false;
        }
        if ($p['code'] !== null && $p['code'] !== $coupon) {
            return false;
        }
        if ($p['member_only'] && empty($context['customer_id'])) {
            return false;
        }
        $ts = $now->getTimestamp();
        if ($p['starts_at'] !== null && Carbon::parse($p['starts_at'], 'UTC')->getTimestamp() > $ts) {
            return false;
        }
        if ($p['ends_at'] !== null && Carbon::parse($p['ends_at'], 'UTC')->getTimestamp() <= $ts) {
            return false;
        }

        return self::inWindow($p['window'], $now);
    }

    /** Days (ISO 1 = Monday … 7 = Sunday) and a time of day, in the shop's time ($now carries its timezone). A window past midnight (22:00–02:00) works. */
    public static function inWindow(?array $window, Carbon $now): bool
    {
        if (! $window) {
            return true;
        }
        $days = array_map('intval', (array) ($window['days'] ?? []));
        $from = self::minutes($window['from'] ?? null);
        $to = self::minutes($window['to'] ?? null);
        $m = (int) $now->format('G') * 60 + (int) $now->format('i');
        $day = (int) $now->format('N');
        if ($from !== null && $to !== null && $from > $to && $m < $to) {
            $day = $day === 1 ? 7 : $day - 1; // the early hours belong to the evening before
        }
        if ($days !== [] && ! in_array($day, $days, true)) {
            return false;
        }
        if ($from === null || $to === null || $from === $to) {
            return true;
        }

        return $from < $to ? ($m >= $from && $m < $to) : ($m >= $from || $m < $to);
    }

    private static function minutes(mixed $hhmm): ?int
    {
        if (! is_string($hhmm) || ! preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $x)) {
            return null;
        }

        return min(23, (int) $x[1]) * 60 + min(59, (int) $x[2]);
    }

    // ── The cart ─────────────────────────────────────────────

    /** Base quantities and prices of the eligible lines. */
    private static function state(array $lines): array
    {
        $s = [];
        foreach ($lines as $key => $l) {
            $factor = max(0.001, (float) ($l['unit_factor'] ?? 1));
            $q = round((float) ($l['quantity'] ?? 0) * $factor, 3);
            $price = (float) ($l['unit_price'] ?? 0) / $factor;
            if (empty($l['eligible']) || $q <= 0 || $price <= 0) {
                continue;
            }
            $s[$key] = ['q' => $q, 'p' => $price, 'product' => (int) ($l['product_id'] ?? 0), 'category' => (int) ($l['category_id'] ?? 0),
                'sub' => (int) ($l['sub_category_id'] ?? 0), 'excl' => 0.0, 'stack' => 0.0, 'disc' => 0.0];
        }

        return $s;
    }

    private static function matches(array $target, array $line): bool
    {
        return match ($target['type']) {
            'product' => $line['product'] === $target['id'],
            'category' => $line['category'] > 0 && $line['category'] === $target['id'],
            'sub_category' => $line['sub'] > 0 && $line['sub'] === $target['id'],
            default => false,
        };
    }

    /** Lines a promotion may use (its targets; every line for a whole-cart promotion without targets). */
    private static function targetLines(array $p, array $state): array
    {
        if ($p['targets'] === []) {
            return in_array($p['type'], self::CART_TYPES, true) ? array_keys($state) : [];
        }
        $keys = [];
        foreach ($state as $key => $l) {
            foreach ($p['targets'] as $t) {
                if (self::matches($t, $l)) {
                    $keys[] = $key;
                    break;
                }
            }
        }

        return $keys;
    }

    /** Units still free for this promotion on a line, and their price. @return array{0: float, 1: float} */
    private static function room(array $p, array $l): array
    {
        $avail = $p['stackable'] ? $l['q'] - $l['excl'] : $l['q'] - $l['excl'] - $l['stack'];
        $price = $p['stackable'] ? max(0, $l['p'] - $l['disc'] / $l['q']) : $l['p'];

        return [max(0, round($avail, 3)), $price];
    }

    // ── Applying ─────────────────────────────────────────────

    private static function greedy(array $promotions, array $state): array
    {
        $lines = [];
        $byLine = [];
        $applied = [];
        $total = 0.0;
        foreach ([false, true] as $cartPhase) {
            $left = array_values(array_filter($promotions, fn ($p) => in_array($p['type'], self::CART_TYPES, true) === $cartPhase));
            while ($left !== []) {
                $best = null;
                foreach ($left as $i => $p) {
                    $r = self::evaluate($p, $state);
                    if ($best === null || $r['amount'] > $best['r']['amount'] + 0.004
                        || (abs($r['amount'] - $best['r']['amount']) <= 0.004 && ($p['priority'] > $best['p']['priority'] || ($p['priority'] === $best['p']['priority'] && $p['id'] < $best['p']['id'])))) {
                        $best = ['i' => $i, 'p' => $p, 'r' => $r];
                    }
                }
                array_splice($left, $best['i'], 1);
                if ($best['r']['amount'] < 0.005) {
                    continue;
                }
                $amount = 0.0;
                foreach ($best['r']['alloc'] as $key => [$d, $used]) {
                    $l = &$state[$key];
                    $d = min($d, round($l['q'] * $l['p'] - $l['disc'], 2));
                    if ($d <= 0) {
                        unset($l);

                        continue;
                    }
                    $l['disc'] = round($l['disc'] + $d, 2);
                    if ($best['p']['stackable']) {
                        $l['stack'] = min($l['q'] - $l['excl'], max($l['stack'], $used));
                    } else {
                        $l['excl'] = min($l['q'], $l['excl'] + $used);
                    }
                    unset($l);
                    $lines[$key] = round(($lines[$key] ?? 0) + $d, 2);
                    $byLine[$key][$best['p']['id']] = round(($byLine[$key][$best['p']['id']] ?? 0) + $d, 2);
                    $amount += $d;
                }
                if ($amount >= 0.005) {
                    $applied[] = ['promotion_id' => $best['p']['id'], 'name' => $best['p']['name'], 'amount' => round($amount, 2), 'code' => $best['p']['code']];
                    $total += $amount;
                }
            }
        }

        return ['lines' => $lines, 'by_line' => $byLine, 'applied' => $applied, 'total' => round($total, 2)];
    }

    /**
     * What one promotion gives on the cart as it stands. @return array{amount: float, alloc: array<string|int, array{0: float, 1: float}>}
     */
    private static function evaluate(array $p, array $state): array
    {
        $keys = self::targetLines($p, $state);
        if ($keys === []) {
            return ['amount' => 0.0, 'alloc' => []];
        }
        $r = $p['rules'];
        $limit = $p['per_sale_limit'];

        return match ($p['type']) {
            'percent_off' => self::perUnit($p, $state, $keys, $limit, fn ($price) => $price * min(100, max(0, (float) ($r['percent'] ?? 0))) / 100),
            'amount_off' => self::perUnit($p, $state, $keys, $limit, fn ($price) => min($price, max(0, (float) ($r['amount'] ?? 0)))),
            'fixed_price' => self::perUnit($p, $state, $keys, $limit, fn ($price) => max(0, $price - max(0, (float) ($r['price'] ?? INF)))),
            'buy_x_get_y' => self::buyGet($p, $state, $keys, $limit, max(1, (int) ($r['buy'] ?? 1)), max(1, (int) ($r['get'] ?? 1)), min(100, max(0, (float) ($r['percent'] ?? 100)))),
            'mix_match' => self::mixMatch($p, $state, $keys, $limit, max(1, (int) ($r['qty'] ?? 1)), max(0, (float) ($r['price'] ?? 0))),
            'bundle' => self::bundle($p, $state, $limit, max(0, (float) ($r['price'] ?? 0))),
            'spend_save', 'coupon' => self::spend($p, $state, $keys, $r),
            default => ['amount' => 0.0, 'alloc' => []],
        };
    }

    /** % off, amount off, promotional price: a saving on each unit (up to the per-sale limit, dearest units first). */
    private static function perUnit(array $p, array $state, array $keys, ?int $limit, callable $saving): array
    {
        usort($keys, fn ($a, $b) => $state[$b]['p'] <=> $state[$a]['p']);
        $alloc = [];
        $total = 0.0;
        $left = $limit !== null ? (float) $limit : INF;
        foreach ($keys as $key) {
            [$avail, $price] = self::room($p, $state[$key]);
            $q = min($avail, $left);
            if ($q <= self::EPS) {
                continue;
            }
            $d = round($q * $saving($price), 2);
            if ($d > 0) {
                $alloc[$key] = [$d, $q];
                $total += $d;
                $left -= $q;
            }
        }

        return ['amount' => round($total, 2), 'alloc' => $alloc];
    }

    /** Whole units on offer, dearest first. @return list<array{key: string|int, price: float, n: int}> */
    private static function tranches(array $p, array $state, array $keys): array
    {
        $t = [];
        foreach ($keys as $key) {
            [$avail, $price] = self::room($p, $state[$key]);
            $n = (int) floor($avail + self::EPS);
            if ($n > 0 && $price > 0) {
                $t[] = ['key' => $key, 'price' => $price, 'n' => $n];
            }
        }
        usort($t, fn ($a, $b) => $b['price'] <=> $a['price']);

        return $t;
    }

    /** Take $n units from the dear end (or the cheap end) of the tranches. @return array{0: float, 1: array<string|int, array{0: float, 1: int}>} [value, per line [value, units]] */
    private static function take(array &$tranches, int $n, bool $cheapest): array
    {
        $value = 0.0;
        $used = [];
        $order = $cheapest ? array_reverse(array_keys($tranches)) : array_keys($tranches);
        foreach ($order as $i) {
            if ($n <= 0) {
                break;
            }
            $k = min($n, $tranches[$i]['n']);
            if ($k <= 0) {
                continue;
            }
            $tranches[$i]['n'] -= $k;
            $n -= $k;
            $value += $k * $tranches[$i]['price'];
            $key = $tranches[$i]['key'];
            $used[$key] = [($used[$key][0] ?? 0) + $k * $tranches[$i]['price'], ($used[$key][1] ?? 0) + $k];
        }

        return [$value, $used];
    }

    /** Share $amount over the lines by the value each put in (the last line takes the rounding). */
    private static function share(float $amount, array $used): array
    {
        $amount = round($amount, 2);
        $value = array_sum(array_map(fn ($u) => $u[0], $used));
        if ($amount <= 0 || $value <= 0) {
            return ['amount' => 0.0, 'alloc' => []];
        }
        $alloc = [];
        $given = 0.0;
        $keys = array_keys($used);
        foreach ($keys as $i => $key) {
            $d = $i === count($keys) - 1 ? round($amount - $given, 2) : round($amount * $used[$key][0] / $value, 2);
            $given += $d;
            $alloc[$key] = [$d, (float) $used[$key][1]];
        }

        return ['amount' => $amount, 'alloc' => $alloc];
    }

    private static function merge(array $a, array $b): array
    {
        foreach ($b as $key => [$v, $n]) {
            $a[$key] = [($a[$key][0] ?? 0) + $v, ($a[$key][1] ?? 0) + $n];
        }

        return $a;
    }

    /** Buy X get Y (free, or Y at % off): in each group the cheapest units are the ones given. */
    private static function buyGet(array $p, array $state, array $keys, ?int $limit, int $buy, int $get, float $pct): array
    {
        $tr = self::tranches($p, $state, $keys);
        $units = array_sum(array_column($tr, 'n'));
        $groups = intdiv($units, $buy + $get);
        if ($limit !== null) {
            $groups = min($groups, $limit);
        }
        if ($groups <= 0 || $pct <= 0) {
            return ['amount' => 0.0, 'alloc' => []];
        }
        [$freeValue, $free] = self::take($tr, $groups * $get, true);
        [, $paid] = self::take($tr, $groups * $buy, false);

        return self::share($freeValue * $pct / 100, self::merge($paid, $free));
    }

    /** Any N of the targets for a price: the dearest units make the groups, while a group still saves. */
    private static function mixMatch(array $p, array $state, array $keys, ?int $limit, int $n, float $price): array
    {
        $tr = self::tranches($p, $state, $keys);
        $saving = 0.0;
        $used = [];
        $groups = 0;
        while (($limit === null || $groups < $limit) && array_sum(array_column($tr, 'n')) >= $n) {
            $copy = $tr;
            [$value, $u] = self::take($copy, $n, false);
            if ($value - $price < 0.005) {
                break;
            }
            $tr = $copy;
            $saving += $value - $price;
            $used = self::merge($used, $u);
            $groups++;
        }

        return self::share($saving, $used);
    }

    /** Listed products together for a price: one of each target (rules.items can ask for more of one) per bundle. */
    private static function bundle(array $p, array $state, ?int $limit, float $price): array
    {
        $slots = [];
        foreach ($p['targets'] as $t) {
            $need = max(1, (int) ($p['rules']['items'][$t['type'].':'.$t['id']] ?? $p['rules']['items'][(string) $t['id']] ?? 1));
            $keys = array_keys(array_filter($state, fn ($l) => self::matches($t, $l)));
            $slots[] = ['need' => $need, 'tr' => self::tranches($p, $state, $keys)];
        }
        if (count($slots) < 2 && ($slots[0]['need'] ?? 1) < 2) {
            return ['amount' => 0.0, 'alloc' => []]; // a bundle needs at least two items
        }
        $saving = 0.0;
        $used = [];
        $groups = 0;
        while ($limit === null || $groups < $limit) {
            $copy = $slots;
            $value = 0.0;
            $u = [];
            foreach ($copy as &$slot) {
                if (array_sum(array_column($slot['tr'], 'n')) < $slot['need']) {
                    break 2;
                }
                [$v, $got] = self::take($slot['tr'], $slot['need'], false);
                $value += $v;
                $u = self::merge($u, $got);
            }
            unset($slot);
            // One line in two slots (a product in two target categories) must not be counted twice.
            foreach ($u as $key => [, $count]) {
                if ($count > floor(self::room($p, $state[$key])[0] + self::EPS) - (int) ($used[$key][1] ?? 0)) {
                    break 2;
                }
            }
            if ($value - $price < 0.005) {
                break;
            }
            $slots = $copy;
            $saving += $value - $price;
            $used = self::merge($used, $u);
            $groups++;
        }

        return self::share($saving, $used);
    }

    /** Spend and save, coupons: on what is left of the (targeted) lines once item promotions are taken off. */
    private static function spend(array $p, array $state, array $keys, array $r): array
    {
        $used = [];
        $spend = 0.0;
        foreach ($keys as $key) {
            [$avail, $price] = self::room($p, $state[$key]);
            if ($avail <= self::EPS) {
                continue;
            }
            $v = round($avail * $price, 2);
            $used[$key] = [$v, $avail];
            $spend += $v;
        }
        $min = max(0, (float) ($r['min_spend'] ?? 0));
        if ($spend <= 0 || $spend + 0.004 < $min) {
            return ['amount' => 0.0, 'alloc' => []];
        }
        $save = isset($r['percent']) && (float) $r['percent'] > 0
            ? $spend * min(100, (float) $r['percent']) / 100
            : min($spend, max(0, (float) ($r['amount'] ?? $r['save'] ?? 0)));

        return self::share($save, $used);
    }
}
