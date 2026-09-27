<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\FinancialPeriod;
use App\Models\FiscalSetting;
use App\Models\FiscalSubmission;
use App\Models\SaleRecord;
use App\Models\SaleReturn;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Services\Fiscal\FiscalAdapter;
use App\Services\Fiscal\FiscalRegistry;
use App\Services\Fiscal\FiscalResult;
use App\Services\Fiscal\FiscalService;
use App\Services\Shop\ReceiptService;
use App\Services\Shop\SaleService;
use App\Support\FiscalQr;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Admin\AdminTestCase;

/** Supermarket plan F2: the fiscal framework (queue, retries, owner notice), the manual adapter, and receipts. */
class FiscalReceiptsTest extends AdminTestCase
{
    private array $t;

    private int $cid;

    private int $item;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->t = $this->makeTenant('company');
        $this->cid = (int) $this->t['company']->id;
        $this->t['company']->forceFill(['timezone' => 'Africa/Kampala', 'tax_rate' => 18])->save();
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $this->cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $this->cid, 'name' => 'Food'])->id;
        $sub = StockSubCategory::create(['company_id' => $this->cid, 'stock_category_id' => $cat, 'name' => 'Dry', 'measurement_unit' => 'pcs'])->id;
        $this->item = StockItem::create(['company_id' => $this->cid, 'created_by_id' => $this->t['user']->id, 'stock_category_id' => $cat, 'stock_sub_category_id' => $sub,
            'name' => 'Sugar 1kg', 'sku' => 'SUG1', 'buying_price' => 3000, 'selling_price' => 5000, 'original_quantity' => 100])->id;
    }

    protected function tearDown(): void
    {
        FiscalRegistry::bind('efris', null);
        parent::tearDown();
    }

    private function fiscalOn(bool $on = true): void
    {
        StoreFeatures::update($this->t['company'], ['features' => ['fiscal' => $on]]);
        $this->t['company'] = $this->t['company']->fresh();
    }

    private function sell(float $qty = 2): SaleRecord
    {
        return (new SaleService())->checkout($this->cid, $this->t['user']->id, [
            'items' => [['stock_item_id' => $this->item, 'quantity' => $qty]], 'payments' => [['method' => 'cash', 'amount' => 5000 * $qty]], 'payments_explicit' => true,
        ])['sale'];
    }

    /** A remote adapter that answers what the test tells it to. */
    private function fakeRemote(callable $answer): FiscalAdapter
    {
        $a = new class($answer) implements FiscalAdapter
        {
            public int $calls = 0;

            public function __construct(private $answer)
            {
            }

            public function key(): string
            {
                return 'efris';
            }

            public function label(): string
            {
                return 'Fake';
            }

            public function country(): ?string
            {
                return null;
            }

            public function configFields(): array
            {
                return ['tin' => ['TIN', 'text', true], 'secret_key' => ['Key', 'secret', true]];
            }

            public function remote(): bool
            {
                return true;
            }

            public function test(array $config, string $environment): FiscalResult
            {
                return FiscalResult::ok('ok');
            }

            public function submit(SaleRecord $sale, array $config, string $environment): FiscalResult
            {
                $this->calls++;

                return ($this->answer)($sale, $config);
            }

            public function supportsCreditNotes(): bool
            {
                return false;
            }

            public function creditNote(SaleReturn $return, SaleRecord $sale, array $original, array $config, string $environment): FiscalResult
            {
                return FiscalResult::fail('no');
            }
        };
        FiscalRegistry::bind('efris', $a);

        return $a;
    }

    private function connect(string $adapter, array $config = []): void
    {
        (new FiscalService())->saveSettings($this->cid, $adapter, $config, 'sandbox', true, $this->t['user']->id);
    }

    public function test_off_changes_nothing(): void
    {
        $sale = $this->sell();
        $this->assertSame(0, FiscalSubmission::query()->where('company_id', $this->cid)->count());
        $this->assertNull(FiscalService::forReceipt($sale));
        $this->assertStringNotContainsString('Fiscal', (new ReceiptService())->text($sale));
        $this->assertStringNotContainsString('Fiscal', view('reports.sale-receipt', ['sale' => $sale, 'company' => $sale->company])->render());

        // Feature on but no connector (or one switched off): still nothing.
        $this->fiscalOn();
        (new FiscalService())->saveSettings($this->cid, 'manual', [], 'sandbox', false, $this->t['user']->id);
        $this->sell();
        $this->assertSame(0, FiscalSubmission::query()->where('company_id', $this->cid)->count());

        // A connector configured but the feature off: nothing either.
        $this->connect('manual');
        $this->fiscalOn(false);
        $this->sell();
        $this->assertSame(0, FiscalSubmission::query()->where('company_id', $this->cid)->count());
        Artisan::call('fiscal:submit-due');
        Http::assertNothingSent();
    }

    public function test_manual_adapter_waits_for_the_typed_number(): void
    {
        $this->fiscalOn();
        $this->connect('manual', ['device_name' => 'EFD 123']);
        $sale = $this->sell();

        $s = FiscalSubmission::query()->where('sale_record_id', $sale->id)->sole();
        $this->assertSame('pending', $s->status);
        $this->assertSame('manual', $s->adapter);
        $this->assertNull($s->next_attempt_at, 'nothing to send: the command never picks it up');
        Artisan::call('fiscal:submit-due');
        $this->assertSame(0, $s->fresh()->attempts);
        $this->assertSame('pending', FiscalService::forReceipt($sale)['status']);
        $this->assertStringContainsString('Fiscal receipt pending', (new ReceiptService())->text($sale));

        $svc = new FiscalService();
        try {
            $svc->recordManual($this->cid, $s->id, '  ', null, $this->t['user']->id);
            $this->fail('empty number refused');
        } catch (BusinessRuleException $e) {
            $this->assertSame('fiscal_number_required', $e->errorCode());
        }
        try {
            $svc->retry($this->cid, $s->id);
            $this->fail('manual rows are not retried');
        } catch (BusinessRuleException) {
        }
        $svc->recordManual($this->cid, $s->id, 'FD-0001234', 'AB12', $this->t['user']->id);
        $info = FiscalService::forReceipt($sale);
        $this->assertSame(['sent', 'FD-0001234', 'AB12'], [$info['status'], $info['number'], $info['code']]);
        $this->assertStringContainsString('FD-0001234', (new ReceiptService())->text($sale));
        $html = view('reports.sale-receipt', ['sale' => $sale, 'company' => $sale->company])->render();
        $this->assertStringContainsString('FD-0001234', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('FD-0001234', view('reports.sale-invoice', ['sale' => $sale, 'company' => $sale->company])->render());
        $this->assertStringStartsWith('%PDF', (new ReceiptService())->pdf($sale->fresh()));

        // Another shop cannot touch it.
        $other = $this->makeTenant('company');
        $this->expectException(BusinessRuleException::class);
        $svc->recordManual((int) $other['company']->id, $s->id, 'X', null, $other['user']->id);
    }

    public function test_credentials_are_encrypted_and_never_shown_again(): void
    {
        $this->fiscalOn();
        $this->fakeRemote(fn () => FiscalResult::ok('F1'));
        $svc = new FiscalService();
        $svc->saveSettings($this->cid, 'efris', ['tin' => '1000000000', 'secret_key' => 'TOP-SECRET-VALUE'], 'sandbox', true, $this->t['user']->id);

        $raw = (string) DB::table('fiscal_settings')->where('company_id', $this->cid)->value('config');
        $this->assertStringNotContainsString('TOP-SECRET-VALUE', $raw);
        $this->assertStringNotContainsString('1000000000', $raw);
        $pub = $svc->publicSettings($this->cid);
        $this->assertSame(['tin' => '1000000000'], $pub['values']);
        $this->assertSame(['secret_key' => true], $pub['configured']);
        $this->assertStringNotContainsString('TOP-SECRET', json_encode($pub));
        $this->assertArrayNotHasKey('config', FiscalSetting::query()->where('company_id', $this->cid)->first()->toArray());

        // Left empty = kept.
        $svc->saveSettings($this->cid, 'efris', ['tin' => '1000000001', 'secret_key' => ''], 'production', true, $this->t['user']->id);
        $this->assertSame('TOP-SECRET-VALUE', FiscalService::setting($this->cid)->decrypted()['secret_key']);
        $this->assertSame('production', FiscalService::setting($this->cid)->environment);

        // Switching on needs every required field.
        $this->expectException(BusinessRuleException::class);
        $svc->saveSettings($this->cid, 'efris', ['tin' => ''], 'sandbox', true, $this->t['user']->id);
    }

    public function test_failures_retry_with_backoff_then_fail_and_tell_the_owner(): void
    {
        $this->fiscalOn();
        $fail = true;
        $adapter = $this->fakeRemote(function () use (&$fail) {
            if ($fail) {
                throw new \RuntimeException('Connection timed out');
            }

            return FiscalResult::ok('FDN-9', 'VC-9', 'https://efris.example/qr/9', ['request' => ['x' => 1], 'response' => ['ok' => true]]);
        });
        $this->connect('efris', ['tin' => '1', 'secret_key' => 'k']);
        $sale = $this->sell(); // never blocked
        $this->assertSame('Completed', $sale->status);
        $s = FiscalSubmission::query()->where('sale_record_id', $sale->id)->sole();
        $this->assertSame('pending', $s->status);

        Artisan::call('fiscal:submit-due');
        $s->refresh();
        $this->assertSame(['pending', 1, 'Connection timed out'], [$s->status, $s->attempts, $s->error]);
        $this->assertEqualsWithDelta(now()->addMinute()->timestamp, $s->next_attempt_at->timestamp, 5);

        Artisan::call('fiscal:submit-due'); // not due yet
        $this->assertSame(1, $adapter->calls);

        $this->travel(2)->minutes();
        Artisan::call('fiscal:submit-due');
        $s->refresh();
        $this->assertSame(2, $s->attempts);
        $this->assertEqualsWithDelta(now()->addMinutes(2)->timestamp, $s->next_attempt_at->timestamp, 5);
        $this->assertSame(1, FiscalService::delayMinutes(1));
        $this->assertSame(64, FiscalService::delayMinutes(7));
        $this->assertSame(FiscalService::MAX_DELAY_MINUTES, FiscalService::delayMinutes(30));
        $this->assertSame('pending', FiscalService::forReceipt($sale)['status']);

        // 24 hours on: failed, and the owner is told once.
        $this->travel(25)->hours();
        Artisan::call('fiscal:submit-due');
        $s->refresh();
        $this->assertSame('failed', $s->status);
        $this->assertNull($s->next_attempt_at);
        $this->assertTrue($s->owner_notified);
        $this->assertSame(1, DB::table('app_notifications')->where('company_id', $this->cid)->where('type', 'fiscal')->where('user_id', $this->t['user']->id)->count());
        Artisan::call('fiscal:submit-due');
        $this->assertSame(3, $adapter->calls, 'failed rows are left alone');

        // Retry now: sent, with the numbers on the receipt.
        $fail = false;
        $s = (new FiscalService())->retry($this->cid, $s->id);
        $this->assertSame(['sent', 'FDN-9', 'VC-9', 'https://efris.example/qr/9'], [$s->status, $s->fiscal_number, $s->verification_code, $s->qr_payload]);
        $this->assertStringContainsString('FDN-9', (new ReceiptService())->text($sale));
        $this->assertSame('sent', FiscalService::forReceipt($sale)['status']);
        $this->assertSame(1, (new FiscalService())->counts($this->cid)['sent']);
    }

    public function test_voided_sale_is_skipped_and_permanent_errors_fail_at_once(): void
    {
        $this->fiscalOn();
        $this->fakeRemote(fn () => FiscalResult::fail('No goods code', [], true));
        $this->connect('efris', ['tin' => '1', 'secret_key' => 'k']);
        $a = $this->sell();
        $b = $this->sell(1);
        (new SaleService())->void($a->fresh(), 'mistake', $this->t['user']->id);

        Artisan::call('fiscal:submit-due');
        $this->assertSame('skipped', FiscalSubmission::query()->where('sale_record_id', $a->id)->value('status'));
        $this->assertNull(FiscalService::forReceipt($a->fresh()));
        $this->assertSame('failed', FiscalSubmission::query()->where('sale_record_id', $b->id)->value('status'));
        $this->assertSame(1, DB::table('app_notifications')->where('company_id', $this->cid)->where('type', 'fiscal')->count());
        $this->assertSame(1, (new FiscalService())->recent($this->cid, 10, 'failed')->count());
    }

    public function test_replayed_checkout_is_queued_once(): void
    {
        $this->fiscalOn();
        $this->connect('manual');
        $payload = ['items' => [['stock_item_id' => $this->item, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 5000]], 'payments_explicit' => true, 'client_uuid' => 'fisc-'.uniqid()];
        (new SaleService())->checkout($this->cid, $this->t['user']->id, $payload);
        (new SaleService())->checkout($this->cid, $this->t['user']->id, $payload);
        $this->assertSame(1, FiscalSubmission::query()->where('company_id', $this->cid)->count());
    }

    public function test_qr_codes(): void
    {
        $svg = FiscalQr::svg('https://efris.ura.go.ug/verify?fdn=123', 100);
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('<path', $svg);
        $this->assertStringStartsWith('data:image/png;base64,', FiscalQr::dataUri('FDN 123'));
        $this->assertGreaterThanOrEqual(21, count(FiscalQr::matrix('x')));
    }
}
