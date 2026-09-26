<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\StockItem;
use App\Services\Onboarding\OnboardingService;
use App\Support\XlsxReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;
use Tests\Support\XlsxFixture;

/** Excel import (POWER_PLAN §3.1): the first sheet of an .xlsx goes through the same CSV import. */
class OnboardingXlsxImportTest extends ApiTestCase
{
    private function sheet(): string
    {
        return XlsxFixture::make([
            ['Product', 'Category', 'Price', 'Cost', 'Qty'],
            ['Omo 500g', 'Detergent', 4000, 3400, 12],
            ['Jik 750ml', 'Detergent', 6500.5, null, 6],
            [],
            ['Bad row', 'Detergent', 'abc', null, null],
            ['Coma, "quoted"', 'Snacks', 0.30000000000000004, 0.1, 1],
        ]);
    }

    public function test_reader_follows_the_workbook_and_reads_every_cell_kind(): void
    {
        $rows = XlsxReader::rows($this->sheet());
        $this->assertSame(['Product', 'Category', 'Price', 'Cost', 'Qty'], $rows[0]);
        $this->assertSame(['Omo 500g', 'Detergent', '4000', '3400', '12'], $rows[1]);
        $this->assertSame(['Jik 750ml', 'Detergent', '6500.5', '', '6'], $rows[2], 'gaps kept in place');
        $this->assertCount(5, $rows, 'blank rows dropped');
        $this->assertSame('0.3', $rows[4][2], 'float noise tidied');
        $this->assertTrue(XlsxReader::isXlsx($this->sheet()));
        $this->assertFalse(XlsxReader::isXlsx("name,price\n"));

        $parsed = (new OnboardingService())->parseCsv($this->sheet());
        $this->assertSame(['Omo 500g', 'Jik 750ml', 'Coma, "quoted"'], array_column($parsed['rows'], 'name'), 'commas and quotes survive the CSV step');
        $this->assertSame([4], array_column($parsed['errors'], 'row'), 'numbered like a CSV: blank rows do not count');
    }

    public function test_a_damaged_file_is_refused_in_words(): void
    {
        $this->expectException(BusinessRuleException::class);
        XlsxReader::rows("PK\x03\x04not really a zip");
    }

    public function test_api_import_accepts_xlsx_preview_and_commit(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']) + ['Accept' => 'application/json'];
        $good = XlsxFixture::make([['name', 'category', 'selling_price', 'buying_price', 'opening_stock'], ['Omo 500g', 'Detergent', 4000, 3400, 12], ['Jik 750ml', 'Detergent', 6500, 5200, 6]]);

        $file = fn () => UploadedFile::fake()->createWithContent('products.xlsx', $good);
        $this->post('/api/v1/onboarding/import', ['file' => $file(), 'dry_run' => 1], $h)->assertOk()->assertJsonPath('data.count', 2);
        $this->post('/api/v1/onboarding/import', ['file' => $file()], $h)->assertStatus(201)->assertJsonPath('data.created', 2);
        $this->assertEquals(12, (float) StockItem::withoutGlobalScopes()->where('company_id', $t['company_id'])->where('name', 'Omo 500g')->value('current_quantity'));
        $this->assertSame(1, DB::table('onboarding_events')->where('company_id', $t['company_id'])->where('event', 'import_done')->count());
        $this->assertSame('app', DB::table('onboarding_events')->where('company_id', $t['company_id'])->where('event', 'import_done')->value('channel'));
    }
}
