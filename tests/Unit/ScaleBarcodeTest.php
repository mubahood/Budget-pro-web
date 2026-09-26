<?php

namespace Tests\Unit;

use App\Support\BarcodeResolver;
use PHPUnit\Framework\TestCase;

/** GS1 in-store scale labels (SUPERMARKET_PLAN.md A2): one test per format. */
class ScaleBarcodeTest extends TestCase
{
    private function label(string $twelve): string
    {
        return $twelve.BarcodeResolver::checkDigit($twelve);
    }

    private function settings(array $s = []): array
    {
        return $s + ['prefixes' => ['20', '21', '22', '23', '24', '25', '26', '27', '28', '29'], 'item_digits' => 5, 'format' => 'price', 'decimals' => 0];
    }

    public function test_price_format_whole_currency(): void
    {
        // 20 | 04011 | 03450 | c  → item 04011, price 3,450
        $this->assertSame(['item' => '04011', 'value' => 3450.0, 'kind' => 'price'], BarcodeResolver::decodeScale($this->label('200401103450'), $this->settings()));
    }

    public function test_price_format_with_cents(): void
    {
        // 21 | 00123 | 01299 | c  → 12.99
        $d = BarcodeResolver::decodeScale($this->label('210012301299'), $this->settings(['decimals' => 2]));
        $this->assertSame('00123', $d['item']);
        $this->assertEqualsWithDelta(12.99, $d['value'], 0.0001);
    }

    public function test_weight_format_in_grams(): void
    {
        // 22 | 04011 | 01250 | c  → 1.250 kg
        $d = BarcodeResolver::decodeScale($this->label('220401101250'), $this->settings(['format' => 'weight', 'decimals' => 3]));
        $this->assertSame(['item' => '04011', 'value' => 1.25, 'kind' => 'weight'], $d);
    }

    public function test_four_digit_item_code_leaves_six_value_digits(): void
    {
        // 20 | 4011 | 012500 | c, weight in grams → 12.5 kg
        $d = BarcodeResolver::decodeScale($this->label('204011012500'), $this->settings(['item_digits' => 4, 'format' => 'weight', 'decimals' => 3]));
        $this->assertSame('4011', $d['item']);
        $this->assertEqualsWithDelta(12.5, $d['value'], 0.0001);
    }

    public function test_bad_check_digit_other_prefix_and_ordinary_barcodes_are_not_scale_labels(): void
    {
        $good = $this->label('200401103450');
        $bad = substr($good, 0, 12).((((int) $good[12]) + 1) % 10);
        $this->assertNull(BarcodeResolver::decodeScale($bad, $this->settings()), 'a misread label is refused');
        $this->assertNull(BarcodeResolver::decodeScale($this->label('600401103450'), $this->settings()), 'not an in-store prefix');
        $this->assertNull(BarcodeResolver::decodeScale('5000112637922', $this->settings()), 'an ordinary EAN-13');
        $this->assertNull(BarcodeResolver::decodeScale('4011', $this->settings()));
        $this->assertNull(BarcodeResolver::decodeScale($this->label('200401100000'), $this->settings()), 'zero value');
        $this->assertTrue(BarcodeResolver::validEan13('5000112637922'));
    }
}
