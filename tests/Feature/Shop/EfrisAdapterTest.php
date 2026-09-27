<?php

namespace Tests\Feature\Shop;

use App\Models\Customer;
use App\Models\FinancialPeriod;
use App\Models\FiscalSubmission;
use App\Models\SaleRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Services\Fiscal\Efris\EfrisAdapter;
use App\Services\Fiscal\Efris\EfrisClient;
use App\Services\Fiscal\FiscalService;
use App\Services\Shop\ReturnService;
use App\Services\Shop\SaleService;
use App\Services\Shop\TaxClassService;
use App\Support\StoreFeatures;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Admin\AdminTestCase;

/**
 * Uganda EFRIS connector (F2) against a simulated URA endpoint (Http::fake; never the network): the envelope,
 * the RSA key exchange and AES round trip, signing, the T109 invoice mapping, response parsing and retries.
 */
class EfrisAdapterTest extends AdminTestCase
{
    private array $t;

    private int $cid;

    private string $privatePem;

    private string $publicPem;

    private string $aesKey;

    /** @var list<array{code: string, body: array, payload: mixed}> */
    private array $seen = [];

    /** What the fake URA answers to T109: null = success. */
    private ?array $t109Error = null;

    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->makeTenant('company');
        $this->cid = (int) $this->t['company']->id;
        $this->t['company']->forceFill(['timezone' => 'Africa/Kampala', 'tax_rate' => 18, 'address' => 'Plot 1 Kampala Rd', 'phone_number' => '0772000000'])->save();
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $this->cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $this->cid, 'name' => 'Food'])->id;
        $sub = StockSubCategory::create(['company_id' => $this->cid, 'stock_category_id' => $cat, 'name' => 'Dry', 'measurement_unit' => 'pcs'])->id;

        StoreFeatures::update($this->t['company'], ['features' => ['fiscal' => true, 'tax_classes' => true]]);
        $classes = (new TaxClassService())->ensureDefaults($this->cid)->keyBy('code');
        foreach ([['Soda', 'SODA', 1180, 'standard'], ['Milk', 'MILK', 2000, 'zero'], ['Maize flour', 'MAIZE', 4000, 'exempt']] as [$name, $sku, $price, $code]) {
            $this->items[$code] = StockItem::create(['company_id' => $this->cid, 'created_by_id' => $this->t['user']->id, 'stock_category_id' => $cat, 'stock_sub_category_id' => $sub,
                'name' => $name, 'sku' => $sku, 'buying_price' => 500, 'selling_price' => $price, 'original_quantity' => 100, 'tax_class_id' => $classes[$code]->id])->id;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem, 'pass123');
        $this->privatePem = $pem;
        $this->publicPem = openssl_pkey_get_details($key)['key'];
        $this->aesKey = random_bytes(16);
        Http::fake(['*' => fn (Request $r) => $this->ura($r)]);
    }

    /** A stand-in for URA's getInformation endpoint. */
    private function ura(Request $r)
    {
        $body = $r->data();
        $code = $body['globalInfo']['interfaceCode'] ?? '';
        $content = (string) ($body['data']['content'] ?? '');
        $payload = null;
        if ($content !== '') {
            $this->assertSame(1, openssl_verify($content, base64_decode($body['data']['signature']), $this->publicPem, OPENSSL_ALGO_SHA1), 'signed with the private key');
            $payload = ($body['data']['dataDescription']['codeType'] ?? '0') === '1'
                ? json_decode(EfrisClient::aesDecrypt($content, $this->aesKey), true)
                : json_decode(base64_decode($content), true);
        }
        $this->seen[] = ['code' => $code, 'body' => $body, 'payload' => $payload];
        $ok = ['returnCode' => '00', 'returnMessage' => 'SUCCESS'];
        $plain = fn (array $c) => ['data' => ['content' => base64_encode(json_encode($c)), 'signature' => '', 'dataDescription' => ['codeType' => '0', 'encryptCode' => '1', 'zipCode' => '0']],
            'globalInfo' => $body['globalInfo'], 'returnStateInfo' => $ok];
        $cipher = fn (array $c) => ['data' => ['content' => EfrisClient::aesEncrypt(json_encode($c), $this->aesKey), 'signature' => '', 'dataDescription' => ['codeType' => '1', 'encryptCode' => '2', 'zipCode' => '0']],
            'globalInfo' => $body['globalInfo'], 'returnStateInfo' => $ok];

        switch ($code) {
            case 'T101':
                return Http::response($plain(['currentTime' => '27/09/2026 10:00:00']));
            case 'T104':
                openssl_public_encrypt(base64_encode($this->aesKey), $enc, $this->publicPem, OPENSSL_PKCS1_PADDING);

                return Http::response($plain(['passowrdDes' => base64_encode($enc), 'sign' => 'x']));
            case 'T109':
                if ($this->t109Error) {
                    return Http::response(['data' => ['content' => ''], 'globalInfo' => $body['globalInfo'], 'returnStateInfo' => $this->t109Error]);
                }

                return Http::response($cipher(array_replace_recursive($payload, ['basicInformation' => ['invoiceId' => '99887766', 'invoiceNo' => '3240000012345', 'antifakeCode' => '82951735482'],
                    'summary' => ['qrCode' => '020000001234500000000000000000000000000000000000000000000000000000000000000~3240000012345']])));
            case 'T110':
                return Http::response($cipher(['referenceNo' => 'CN-APP-1']));
        }

        return Http::response(['returnStateInfo' => ['returnCode' => '99', 'returnMessage' => 'Unknown interface']]);
    }

    private function connect(array $extra = []): void
    {
        (new FiscalService())->saveSettings($this->cid, 'efris', $extra + [
            'tin' => '1000023456', 'device_no' => 'TCS0001', 'private_key' => $this->privatePem, 'key_password' => 'pass123',
            'goods_codes' => "standard = 50202306\nzero = 50131700\nexempt = 50221101\nsku:SODA = 50202310",
        ], 'sandbox', true, $this->t['user']->id);
    }

    private function sell(array $lines, array $extra = []): SaleRecord
    {
        $items = [];
        $total = 0;
        foreach ($lines as $code => $qty) {
            $items[] = ['stock_item_id' => $this->items[$code], 'quantity' => $qty];
        }
        $sale = (new SaleService())->checkout($this->cid, $this->t['user']->id, $extra + ['items' => $items, 'payments' => [], 'payments_explicit' => false, 'amount_paid' => 0])['sale'];

        return $sale;
    }

    public function test_encryption_round_trip_and_key_exchange(): void
    {
        $key = random_bytes(16);
        $cipher = EfrisClient::aesEncrypt('{"a":"é"}', $key);
        $this->assertNotSame('{"a":"é"}', base64_decode($cipher));
        $this->assertSame('{"a":"é"}', EfrisClient::aesDecrypt($cipher, $key));
        $this->assertSame('x', EfrisClient::aesDecrypt(EfrisClient::aesEncrypt('x', str_repeat('k', 32)), str_repeat('k', 32)), 'AES-256 too');

        $client = new EfrisClient('https://efris.test/ws', ['tin' => '1', 'device_no' => 'D'], $this->privatePem, 'pass123');
        foreach ([base64_encode($key), $key] as $inside) { // raw key or base64 of it
            openssl_public_encrypt($inside, $enc, $this->publicPem, OPENSSL_PKCS1_PADDING);
            $this->assertSame($key, $client->decryptSymmetricKey(base64_encode($enc)));
        }
        $sig = $client->sign('content');
        $this->assertSame(1, openssl_verify('content', base64_decode($sig), $this->publicPem, OPENSSL_ALGO_SHA1));

        $env = $client->envelope('T109', 'abc', 'sig', true);
        $this->assertSame(['content' => 'abc', 'signature' => 'sig', 'dataDescription' => ['codeType' => '1', 'encryptCode' => '2', 'zipCode' => '0']], $env['data']);
        foreach (['appId', 'version', 'dataExchangeId', 'interfaceCode', 'requestCode', 'requestTime', 'responseCode', 'userName', 'deviceMAC', 'deviceNo', 'tin', 'brn', 'taxpayerID', 'extendField'] as $f) {
            $this->assertArrayHasKey($f, $env['globalInfo']);
        }
        $this->assertSame(['T109', 'TP', 'TA', '1', 'D'], [$env['globalInfo']['interfaceCode'], $env['globalInfo']['requestCode'], $env['globalInfo']['responseCode'], $env['globalInfo']['tin'], $env['globalInfo']['deviceNo']]);
        $this->assertSame(32, strlen($env['globalInfo']['dataExchangeId']));
        $this->assertSame(['returnCode' => '', 'returnMessage' => ''], $env['returnStateInfo']);
    }

    public function test_connection_test_uses_t101_and_t104(): void
    {
        $this->connect();
        $r = (new FiscalService())->testConnection($this->cid);
        $this->assertTrue($r->ok, (string) $r->error);
        $this->assertStringContainsString('27/09/2026', $r->fiscal_number);
        $this->assertSame(['T101', 'T104'], array_column($this->seen, 'code'));
        Http::assertSent(fn (Request $req) => $req->url() === EfrisClient::SANDBOX_URL);
        $this->assertTrue(FiscalService::setting($this->cid)->last_test_ok);

        // A wrong key password is refused when saving.
        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        $this->connect(['key_password' => 'wrong']);
    }

    public function test_sale_is_uploaded_with_t109_and_the_receipt_gets_the_fdn(): void
    {
        $this->connect();
        $buyer = Customer::create(['company_id' => $this->cid, 'name' => 'Acme Ltd', 'phone' => '0700111222']);
        $buyer->forceFill(['tin' => '1000099999'])->save();
        $sale = (new SaleService())->checkout($this->cid, $this->t['user']->id, [
            'items' => [['stock_item_id' => $this->items['standard'], 'quantity' => 2, 'discount_amount' => 360], ['stock_item_id' => $this->items['zero'], 'quantity' => 1], ['stock_item_id' => $this->items['exempt'], 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 5000], ['method' => 'mobile_money', 'amount' => 3000]], 'payments_explicit' => true, 'customer_id' => $buyer->id,
        ])['sale'];
        $this->assertEquals(8000, (float) $sale->total_amount);
        $this->assertSame([], $this->seen, 'nothing is sent during checkout');

        Artisan::call('fiscal:submit-due');
        $this->assertSame(['T104', 'T109'], array_column($this->seen, 'code'));
        $req = $this->seen[1];
        $this->assertSame('1', $req['body']['data']['dataDescription']['codeType']);
        $this->assertStringNotContainsString('Soda', json_encode($req['body']), 'the invoice travels encrypted');
        $inv = $req['payload'];

        $this->assertSame(['sellerDetails', 'basicInformation', 'buyerDetails', 'goodsDetails', 'taxDetails', 'summary', 'payWay', 'extend'], array_keys($inv));
        $this->assertSame('1000023456', $inv['sellerDetails']['tin']);
        $this->assertSame($sale->receipt_number, $inv['sellerDetails']['referenceNo']);
        $this->assertSame(['TCS0001', 'UGX', '1', '1', '103'], [$inv['basicInformation']['deviceNo'], $inv['basicInformation']['currency'], $inv['basicInformation']['invoiceType'],
            $inv['basicInformation']['invoiceKind'], $inv['basicInformation']['dataSource']]);
        $this->assertSame(['1000099999', '0', 'Acme Ltd'], [$inv['buyerDetails']['buyerTin'], $inv['buyerDetails']['buyerType'], $inv['buyerDetails']['buyerLegalName']]);

        // Soda: 2 × 1180 = 2360 less 360 → item line + discount line; milk zero-rated; flour exempt.
        $g = $inv['goodsDetails'];
        $this->assertCount(4, $g);
        $this->assertSame(['Soda', 'SODA', '2', '1180', '2360', '0.18', '360', '1', '-360', '50202310'],
            [$g[0]['item'], $g[0]['itemCode'], $g[0]['qty'], $g[0]['unitPrice'], $g[0]['total'], $g[0]['taxRate'], $g[0]['tax'], $g[0]['discountFlag'], $g[0]['discountTotal'], $g[0]['goodsCategoryId']]);
        $this->assertSame(['0', '-360', '-54.92', ''], [$g[1]['discountFlag'], $g[1]['total'], $g[1]['tax'], $g[1]['qty']]);
        $this->assertSame(['Milk', '0', '0', '2', '50131700'], [$g[2]['item'], $g[2]['taxRate'], $g[2]['tax'], $g[2]['discountFlag'], $g[2]['goodsCategoryId']]);
        $this->assertSame(['-', '0', '50221101'], [$g[3]['taxRate'], $g[3]['tax'], $g[3]['goodsCategoryId']]);
        $this->assertSame(['0', '1', '2', '3'], array_column($g, 'orderNumber'));

        $tax = collect($inv['taxDetails'])->keyBy('taxCategoryCode');
        $this->assertSame(['2000', '305.08', '1694.92', '0.18'], [$tax['01']['grossAmount'], $tax['01']['taxAmount'], $tax['01']['netAmount'], $tax['01']['taxRate']]);
        $this->assertSame(['2000', '0', '0'], [$tax['02']['grossAmount'], $tax['02']['taxAmount'], $tax['02']['taxRate']]);
        $this->assertSame(['4000', '-'], [$tax['03']['grossAmount'], $tax['03']['taxRate']]);
        $this->assertSame(['8000', '305.08', '7694.92', '4'], [$inv['summary']['grossAmount'], $inv['summary']['taxAmount'], $inv['summary']['netAmount'], $inv['summary']['itemCount']]);
        $this->assertSame([['102', '5000'], ['105', '3000']], array_map(fn ($p) => [$p['paymentMode'], $p['paymentAmount']], $inv['payWay']));

        $s = FiscalSubmission::query()->where('sale_record_id', $sale->id)->sole();
        $this->assertSame(['sent', '3240000012345', '82951735482'], [$s->status, $s->fiscal_number, $s->verification_code]);
        $this->assertStringEndsWith('~3240000012345', $s->qr_payload);
        // What is kept: the invoice and the answer, never the key, the private key or the signature.
        foreach ([$s->raw_request, $s->raw_response] as $raw) {
            $this->assertStringNotContainsString('PRIVATE KEY', $raw);
            $this->assertStringNotContainsString(base64_encode($this->aesKey), $raw);
        }
        $this->assertStringContainsString('"signature":"[signed]"', $s->raw_request);
        $this->assertStringContainsString('3240000012345', view('reports.sale-receipt', ['sale' => $sale, 'company' => $sale->company])->render());

        // A return becomes a T110 credit note against that FDN.
        (new ReturnService())->create($sale->fresh(), [['sale_item_id' => $sale->saleRecordItems->firstWhere('stock_item_id', $this->items['zero'])->id, 'quantity' => 1]], $this->t['user']->id, 'Spoilt');
        Artisan::call('fiscal:submit-due');
        $t110 = collect($this->seen)->firstWhere('code', 'T110');
        $this->assertNotNull($t110);
        $this->assertSame(['3240000012345', '99887766', '-1', '-2000'], [$t110['payload']['oriInvoiceNo'], $t110['payload']['oriInvoiceId'], $t110['payload']['goodsDetails'][0]['qty'], $t110['payload']['goodsDetails'][0]['total']]);
        $this->assertSame('sent', FiscalSubmission::query()->where('sale_record_id', $sale->id)->whereNotNull('sale_return_id')->value('status'));
    }

    public function test_refusals_are_retried_and_missing_goods_codes_fail_at_once(): void
    {
        $this->connect();
        $this->t109Error = ['returnCode' => '2124', 'returnMessage' => 'Device is not active'];
        $sale = $this->sell(['standard' => 1]);
        Artisan::call('fiscal:submit-due');
        $s = FiscalSubmission::query()->where('sale_record_id', $sale->id)->sole();
        $this->assertSame(['pending', 1], [$s->status, $s->attempts]);
        $this->assertStringContainsString('Device is not active', $s->error);
        $this->assertSame('pending', FiscalService::forReceipt($sale)['status']);

        $this->t109Error = null;
        $this->travel(2)->minutes();
        Artisan::call('fiscal:submit-due');
        $this->assertSame('sent', $s->fresh()->status);

        // No code for exempt goods in the mapping: failed without calling URA, the owner is told.
        $this->connect(['goods_codes' => 'standard = 50202306']);
        $before = count($this->seen);
        $b = $this->sell(['exempt' => 1]);
        Artisan::call('fiscal:submit-due');
        $s = FiscalSubmission::query()->where('sale_record_id', $b->id)->sole();
        $this->assertSame('failed', $s->status);
        $this->assertStringContainsString('No EFRIS goods code for "Maize flour"', $s->error);
        $this->assertSame([], array_slice($this->seen, $before), 'nothing is sent for a sale that cannot be mapped');
    }

    public function test_no_calls_when_off(): void
    {
        $this->connect();
        StoreFeatures::update($this->t['company']->fresh(), ['features' => ['fiscal' => false]]);
        $this->sell(['standard' => 1]);
        Artisan::call('fiscal:submit-due');
        $this->assertSame(0, FiscalSubmission::query()->where('company_id', $this->cid)->count());
        Http::assertNothingSent();

        $this->assertSame(['standard' => '1', 'sku:abc' => '2'], EfrisAdapter::goodsCodes("Standard = 1\n\n  sku:ABC=2 \nnonsense"));
    }
}
