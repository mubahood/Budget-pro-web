<?php

namespace Tests\Unit;

use App\Services\Shop\PromotionEngine;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/** The pure promotions engine (SUPERMARKET_PLAN.md B3): one test per type, plus stacking, limits, members, coupons and time windows. */
class PromotionEngineTest extends TestCase
{
    private const JUICE = 1;

    private const SODA = 2;

    private const BREAD = 3;

    private const MILK = 4;

    private const EGGS = 5;

    private const DRINKS = 10; // category

    private function now(string $at = '2026-09-28 10:00'): Carbon
    {
        return Carbon::parse($at, 'Africa/Kampala'); // a Monday
    }

    private function line(int $product, float $qty, float $price, array $extra = []): array
    {
        return $extra + ['product_id' => $product, 'category_id' => in_array($product, [self::JUICE, self::SODA], true) ? self::DRINKS : 20, 'sub_category_id' => 0,
            'quantity' => $qty, 'unit_factor' => 1, 'unit_price' => $price, 'eligible' => true];
    }

    private function promo(int $id, string $type, array $rules, array $targets = [], array $extra = []): array
    {
        return $extra + ['id' => $id, 'name' => "Promo {$id}", 'type' => $type, 'rules' => $rules, 'is_active' => true,
            'targets' => array_map(fn ($t) => ['type' => $t[0], 'id' => $t[1]], $targets)];
    }

    private function go(array $promos, array $lines, array $context = []): array
    {
        return PromotionEngine::apply($promos, $lines, $context + ['now' => $this->now()]);
    }

    public function test_percent_off_a_category(): void
    {
        $r = $this->go([$this->promo(1, 'percent_off', ['percent' => 10], [['category', self::DRINKS]])],
            ['a' => $this->line(self::JUICE, 2, 3000), 'b' => $this->line(self::BREAD, 1, 4000)]);

        $this->assertSame(600.0, $r['lines']['a']['discount']);
        $this->assertSame(0.0, $r['lines']['b']['discount']);
        $this->assertSame(600.0, $r['total']);
        $this->assertSame([['promotion_id' => 1, 'name' => 'Promo 1', 'amount' => 600.0]], $r['applied']);
    }

    public function test_amount_off_each_item_never_below_zero(): void
    {
        $r = $this->go([$this->promo(1, 'amount_off', ['amount' => 500], [['product', self::JUICE]])], ['a' => $this->line(self::JUICE, 3, 3000)]);
        $this->assertSame(1500.0, $r['total']);

        $r = $this->go([$this->promo(1, 'amount_off', ['amount' => 5000], [['product', self::JUICE]])], ['a' => $this->line(self::JUICE, 1, 3000)]);
        $this->assertSame(3000.0, $r['total'], 'at most the price');
    }

    public function test_fixed_promotional_price_only_when_lower(): void
    {
        $r = $this->go([$this->promo(1, 'fixed_price', ['price' => 2500], [['product', self::JUICE]])], ['a' => $this->line(self::JUICE, 2, 3000)]);
        $this->assertSame(1000.0, $r['total']);

        $r = $this->go([$this->promo(1, 'fixed_price', ['price' => 3500], [['product', self::JUICE]])], ['a' => $this->line(self::JUICE, 2, 3000)]);
        $this->assertSame(0.0, $r['total']);
        $this->assertSame([], $r['applied']);
    }

    public function test_buy_two_get_one_free_gives_the_cheapest_and_counts_whole_groups(): void
    {
        $p = $this->promo(1, 'buy_x_get_y', ['buy' => 2, 'get' => 1], [['category', self::DRINKS]]);
        $r = $this->go([$p], ['a' => $this->line(self::JUICE, 2, 3000), 'b' => $this->line(self::SODA, 1, 2000)]);
        $this->assertSame(2000.0, $r['total'], 'the soda (cheapest) is free');
        // Shared by value over the three bottles: 6,000 of juice and 2,000 of soda.
        $this->assertSame(1500.0, $r['lines']['a']['discount']);
        $this->assertSame(500.0, $r['lines']['b']['discount']);

        $r = $this->go([$p], ['a' => $this->line(self::JUICE, 7, 3000)]);
        $this->assertSame(6000.0, $r['total'], '7 bottles = 2 full groups');

        $r = $this->go([$this->promo(1, 'buy_x_get_y', ['buy' => 1, 'get' => 1, 'percent' => 50], [['product', self::JUICE]])], ['a' => $this->line(self::JUICE, 2, 3000)]);
        $this->assertSame(1500.0, $r['total'], 'buy one, get the second half price');
    }

    public function test_mix_and_match_any_three_for_a_price(): void
    {
        $p = $this->promo(1, 'mix_match', ['qty' => 3, 'price' => 10000], [['category', self::DRINKS]]);
        $r = $this->go([$p], ['a' => $this->line(self::JUICE, 2, 4000), 'b' => $this->line(self::SODA, 2, 3500)]);
        // Dearest three: 4,000 + 4,000 + 3,500 = 11,500 for 10,000.
        $this->assertSame(1500.0, $r['total']);
        $this->assertSame(round(1500 * 8000 / 11500, 2), $r['lines']['a']['discount']);
        $this->assertEqualsWithDelta(1500, $r['lines']['a']['discount'] + $r['lines']['b']['discount'], 0.001);

        $r = $this->go([$p], ['a' => $this->line(self::JUICE, 3, 3000)]);
        $this->assertSame(0.0, $r['total'], 'no saving when three already cost less');
    }

    public function test_bundle_of_listed_products(): void
    {
        $p = $this->promo(1, 'bundle', ['price' => 12000], [['product', self::BREAD], ['product', self::MILK], ['product', self::EGGS]]);
        $lines = ['b' => $this->line(self::BREAD, 2, 4000), 'm' => $this->line(self::MILK, 1, 3000), 'e' => $this->line(self::EGGS, 1, 7000)];
        $r = $this->go([$p], $lines);
        $this->assertSame(2000.0, $r['total'], '14,000 for 12,000, once: only one milk');

        unset($lines['e']);
        $this->assertSame(0.0, $this->go([$p], $lines)['total'], 'no eggs, no bundle');
    }

    public function test_spend_and_save_and_percent(): void
    {
        $lines = ['a' => $this->line(self::JUICE, 10, 5000), 'b' => $this->line(self::BREAD, 15, 4000)];
        $r = $this->go([$this->promo(1, 'spend_save', ['min_spend' => 100000, 'amount' => 5000])], $lines);
        $this->assertSame(5000.0, $r['total']);
        $this->assertSame(2272.73, $r['lines']['a']['discount'], 'shared by value 50,000 : 60,000');
        $this->assertSame(2727.27, $r['lines']['b']['discount']);

        $r = $this->go([$this->promo(1, 'spend_save', ['min_spend' => 200000, 'amount' => 5000])], $lines);
        $this->assertSame(0.0, $r['total'], 'below the threshold');

        $r = $this->go([$this->promo(1, 'spend_save', ['min_spend' => 100000, 'percent' => 10])], $lines);
        $this->assertSame(11000.0, $r['total']);
    }

    public function test_coupon_needs_its_code(): void
    {
        $c = $this->promo(1, 'coupon', ['min_spend' => 0, 'percent' => 10], [], ['code' => 'SAVE10']);
        $lines = ['a' => $this->line(self::JUICE, 1, 5000)];

        $none = $this->go([$c], $lines);
        $this->assertSame(0.0, $none['total']);
        $this->assertNull($none['coupon']);

        $ok = $this->go([$c], $lines, ['coupon' => ' save10 ']);
        $this->assertSame(500.0, $ok['total']);
        $this->assertTrue($ok['coupon']['ok']);

        $bad = $this->go([$c], $lines, ['coupon' => 'NOPE']);
        $this->assertSame(0.0, $bad['total']);
        $this->assertFalse($bad['coupon']['ok']);
        $this->assertStringContainsString('not known', $bad['coupon']['message']);

        $min = $this->go([$this->promo(1, 'coupon', ['min_spend' => 10000, 'amount' => 1000], [], ['code' => 'BIG'])], $lines, ['coupon' => 'BIG']);
        $this->assertFalse($min['coupon']['ok']);
        $this->assertStringContainsString('does not apply', $min['coupon']['message']);
    }

    public function test_best_price_wins_and_non_stackable_promotions_do_not_combine(): void
    {
        $ten = $this->promo(1, 'percent_off', ['percent' => 10], [['product', self::JUICE]]);
        $twenty = $this->promo(2, 'percent_off', ['percent' => 20], [['category', self::DRINKS]]);
        $r = $this->go([$ten, $twenty], ['a' => $this->line(self::JUICE, 1, 10000)]);
        $this->assertSame(2000.0, $r['total'], 'the bigger saving, alone');
        $this->assertSame([2], array_column($r['applied'], 'promotion_id'));

        // Both stackable: the second works on what is left (10,000 − 20% − 10% of 8,000).
        $r = $this->go([$ten + ['stackable' => true], $twenty + ['stackable' => true]], ['a' => $this->line(self::JUICE, 1, 10000)]);
        $this->assertSame(2800.0, $r['total']);
        $this->assertCount(2, $r['applied']);

        // A stackable one does not join a non-stackable one on the same units.
        $r = $this->go([$ten + ['stackable' => true], $twenty], ['a' => $this->line(self::JUICE, 1, 10000)]);
        $this->assertSame(2000.0, $r['total']);

        // Non-stackable on different lines both apply.
        $bread = $this->promo(3, 'amount_off', ['amount' => 500], [['product', self::BREAD]]);
        $r = $this->go([$twenty, $bread], ['a' => $this->line(self::JUICE, 1, 10000), 'b' => $this->line(self::BREAD, 1, 4000)]);
        $this->assertSame(2500.0, $r['total']);
    }

    public function test_spend_and_save_stacks_after_item_promotions_or_wins_alone(): void
    {
        $item = $this->promo(1, 'percent_off', ['percent' => 10], [['product', self::JUICE]], ['stackable' => true]);
        $spend = $this->promo(2, 'spend_save', ['min_spend' => 50000, 'amount' => 3000], [], ['stackable' => true]);
        $lines = ['a' => $this->line(self::JUICE, 10, 6000)];
        $r = $this->go([$item, $spend], $lines);
        // Item promotion first: 60,000 − 6,000 = 54,000 still reaches 50,000, and the stackable spend-and-save adds 3,000.
        $this->assertSame(9000.0, $r['total']);
        // A non-stackable item promotion keeps its units to itself: the spend-and-save finds nothing left.
        $this->assertSame(6000.0, $this->go([array_merge($item, ['stackable' => false]), $spend], $lines)['total']);

        // A non-stackable spend-and-save only takes untouched units, but on its own it saves more: it wins alone.
        $big = $this->promo(3, 'spend_save', ['min_spend' => 50000, 'amount' => 8000]);
        $r = $this->go([$item, $big], $lines);
        $this->assertSame(8000.0, $r['total']);
        $this->assertSame([3], array_column($r['applied'], 'promotion_id'));
    }

    public function test_per_sale_limit(): void
    {
        $r = $this->go([$this->promo(1, 'amount_off', ['amount' => 1000], [['product', self::JUICE]], ['per_sale_limit' => 2])], ['a' => $this->line(self::JUICE, 5, 3000)]);
        $this->assertSame(2000.0, $r['total']);
        $r = $this->go([$this->promo(1, 'buy_x_get_y', ['buy' => 1, 'get' => 1], [['product', self::JUICE]], ['per_sale_limit' => 1])], ['a' => $this->line(self::JUICE, 6, 3000)]);
        $this->assertSame(3000.0, $r['total']);
    }

    public function test_member_only_and_ineligible_lines(): void
    {
        $p = $this->promo(1, 'percent_off', ['percent' => 10], [['product', self::JUICE]], ['member_only' => true]);
        $this->assertSame(0.0, $this->go([$p], ['a' => $this->line(self::JUICE, 1, 5000)])['total'], 'walk-in');
        $this->assertSame(500.0, $this->go([$p], ['a' => $this->line(self::JUICE, 1, 5000)], ['customer_id' => 7])['total']);

        $open = $this->promo(2, 'percent_off', ['percent' => 10], [['product', self::JUICE]]);
        $this->assertSame(0.0, $this->go([$open], ['a' => $this->line(self::JUICE, 1, 5000, ['eligible' => false])])['total'], 'a re-priced line takes no promotion');
        $this->assertSame(0.0, $this->go([array_merge($open, ['is_active' => false])], ['a' => $this->line(self::JUICE, 1, 5000)])['total'], 'paused');
    }

    public function test_packs_count_in_base_units(): void
    {
        // A 6-pack line (factor 6) at 15,000: six juices at 2,500 each; "any 3 for 6,000" makes two groups.
        $p = $this->promo(1, 'mix_match', ['qty' => 3, 'price' => 6000], [['product', self::JUICE]]);
        $r = $this->go([$p], ['a' => $this->line(self::JUICE, 1, 15000, ['unit_factor' => 6])]);
        $this->assertSame(3000.0, $r['total']);
    }

    public function test_dates_and_time_windows_use_shop_time(): void
    {
        $p = $this->promo(1, 'percent_off', ['percent' => 10], [['product', self::JUICE]]);
        $lines = ['a' => $this->line(self::JUICE, 1, 10000)];
        // 10:00 in Kampala is 07:00 UTC.
        $this->assertSame(0.0, $this->go([$p + ['starts_at' => '2026-09-28 07:30:00']], $lines)['total'], 'starts later today');
        $this->assertSame(1000.0, $this->go([$p + ['starts_at' => '2026-09-28 06:59:00', 'ends_at' => '2026-09-28 08:00:00']], $lines)['total']);
        $this->assertSame(0.0, $this->go([$p + ['ends_at' => '2026-09-28 07:00:00']], $lines)['total'], 'ended at this minute');

        $happy = $p + ['window' => ['days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'to' => '11:00']];
        $this->assertSame(1000.0, $this->go([$happy], $lines)['total'], 'Monday 10:00 local');
        $this->assertSame(0.0, $this->go([$happy], $lines, ['now' => $this->now('2026-09-28 11:00')])['total']);
        $this->assertSame(0.0, $this->go([$happy], $lines, ['now' => $this->now('2026-09-27 10:00')])['total'], 'Sunday');
        // The same instant in UTC (07:00) would be outside 09:00–11:00: the window is read in the shop's time.
        $this->assertSame(0.0, $this->go([$happy], $lines, ['now' => $this->now()->copy()->setTimezone('UTC')])['total']);

        $night = $p + ['window' => ['days' => [5], 'from' => '22:00', 'to' => '02:00']];
        $this->assertSame(1000.0, $this->go([$night], $lines, ['now' => $this->now('2026-10-02 23:30')])['total'], 'Friday night');
        $this->assertSame(1000.0, $this->go([$night], $lines, ['now' => $this->now('2026-10-03 01:30')])['total'], 'still Friday night');
        $this->assertSame(0.0, $this->go([$night], $lines, ['now' => $this->now('2026-10-04 01:30')])['total'], 'Saturday night is not Friday');
    }
}
