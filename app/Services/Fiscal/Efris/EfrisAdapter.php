<?php

namespace App\Services\Fiscal\Efris;

use App\Models\Company;
use App\Models\Payment;
use App\Models\SaleRecord;
use App\Models\SaleReturn;
use App\Models\TaxClass;
use App\Services\Fiscal\FiscalAdapter;
use App\Services\Fiscal\FiscalResult;
use App\Services\Shop\TaxClassService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Uganda EFRIS (URA's Electronic Fiscal Receipting and Invoicing Solution): maps a Budget Pro sale to a
 * T109 invoice upload and reads back the fiscal document number (FDN), the verification (anti-fake) code and
 * the QR code. The protocol itself (envelope, keys, signing) is EfrisClient.
 *
 * Built to URA's EFRIS system-to-system spec. Must be certified on URA's sandbox with the shop's own
 * credentials before production. Field names verified against: URA's public EFRIS system-to-system interface
 * design (T109 "Invoice Upload": sellerDetails, basicInformation, buyerDetails, goodsDetails, taxDetails,
 * summary, payWay, extend; T110 "Credit Note Application") and the samples published with open-source EFRIS
 * clients. Not verified live (no URA credentials here).
 *
 * UNCERTAIN (check on the sandbox before production):
 *  - goods must already be registered with URA (T130 "goods upload", not done here): `itemCode` is sent as the
 *    product SKU and `goodsCategoryId` from the shop's goods-code mapping; URA rejects unknown goods;
 *  - `unitOfMeasure` must be URA's code for the unit the goods were registered with (config, default "101");
 *  - discount representation (a discounted line with discountFlag 1 followed by a discount line with
 *    discountFlag 0 and negative totals);
 *  - invoiceKind 2 = receipt / 1 = invoice (a named buyer with a TIN gets an invoice), dataSource 103 = web service,
 *    invoiceIndustryCode 101 = general, buyerType 0 = B2B / 1 = B2C;
 *  - tax codes: taxCategoryCode 01 standard (taxRate "0.18"), 02 zero-rated ("0"), 03 exempt ("-");
 *  - payment mode codes 101 credit, 102 cash, 103 cheque, 105 mobile money, 106 card, 107 bank transfer;
 *  - a retried upload that URA already accepted: referenceNo + isCheckReferenceNo "1" make URA refuse the
 *    duplicate; fetching the existing FDN (T106/T108) is not implemented, so such a sale is flagged for the owner.
 */
class EfrisAdapter implements FiscalAdapter
{
    public const TAX_CODES = [
        'standard' => ['01', 'Standard'], 'reduced' => ['01', 'Standard'], 'zero' => ['02', 'Zero-rated'], 'exempt' => ['03', 'Exempt'],
    ];

    public const PAY_MODES = ['credit' => '101', 'cash' => '102', 'cheque' => '103', 'mobile_money' => '105', 'card' => '106', 'bank' => '107', 'other' => '102'];

    /** Clients by TIN+environment for one run (one key exchange per scheduler run). @var array<string, EfrisClient> */
    private array $clients = [];

    public function key(): string
    {
        return 'efris';
    }

    public function label(): string
    {
        return 'Uganda EFRIS (URA)';
    }

    public function country(): ?string
    {
        return 'UG';
    }

    public function remote(): bool
    {
        return true;
    }

    public function configFields(): array
    {
        return [
            'tin' => ['TIN', 'text', true, 'The shop\'s URA Taxpayer Identification Number.'],
            'device_no' => ['Device number', 'text', true, 'The EFRIS device number URA issued for this system.'],
            'brn' => ['Business registration number', 'text', false, ''],
            'legal_name' => ['Legal name', 'text', false, 'As registered with URA. Empty = the business name.'],
            'private_key' => ['Private key (PEM)', 'secret_textarea', true, 'The key registered with URA, as PEM text (from a .pfx: openssl pkcs12 -in key.pfx -nocerts). Stored encrypted.'],
            'key_password' => ['Private key password', 'secret', false, 'Only if the key is protected by a password.'],
            'goods_codes' => ['Goods codes', 'textarea', true, 'One per line: standard = 50202306, zero = …, exempt = …, default = …, sku:ABC = …, product:12 = …. URA commodity category codes; goods must be registered with URA.'],
            'unit_of_measure' => ['Unit of measure code', 'text', false, 'URA\'s unit code the goods were registered with. Default 101.'],
            'default_tax' => ['Tax when a product has no tax class', 'select', false, 'Used when tax classes are off and the shop rate is 0.', ['standard' => 'Standard', 'zero' => 'Zero-rated', 'exempt' => 'Exempt']],
        ];
    }

    public function supportsCreditNotes(): bool
    {
        return true;
    }

    public function client(array $config, string $environment): EfrisClient
    {
        $k = ($config['tin'] ?? '').'@'.$environment;

        return $this->clients[$k] ??= new EfrisClient(EfrisClient::urlFor($environment),
            ['tin' => (string) ($config['tin'] ?? ''), 'device_no' => (string) ($config['device_no'] ?? ''), 'brn' => (string) ($config['brn'] ?? '')],
            (string) ($config['private_key'] ?? ''), ($config['key_password'] ?? '') !== '' ? (string) $config['key_password'] : null,
            (int) (config('fiscal.efris.timeout') ?: 30));
    }

    public function test(array $config, string $environment): FiscalResult
    {
        try {
            $this->clients = [];
            $client = $this->client($config, $environment);
            $time = $client->serverTime();
            if (! $time['ok'] && $time['code'] !== '') {
                return FiscalResult::fail('URA answered: '.($time['message'] ?: $time['code']), ['request' => $time['request'], 'response' => $time['response']]);
            }
            $client->symmetricKey();
            $when = is_array($time['content']) ? ($time['content']['currentTime'] ?? '') : '';

            return FiscalResult::ok('Connected to EFRIS ('.$environment.')'.($when ? ', URA time '.$when : '').'. The key exchange worked.');
        } catch (\Throwable $e) {
            return FiscalResult::fail($e->getMessage());
        }
    }

    public function submit(SaleRecord $sale, array $config, string $environment): FiscalResult
    {
        try {
            $invoice = $this->invoice($sale, $config);
        } catch (RuntimeException $e) {
            return FiscalResult::fail($e->getMessage(), [], true); // the data needs fixing first: no point retrying
        }
        $r = $this->client($config, $environment)->uploadInvoice($invoice);

        return $this->result($r);
    }

    public function creditNote(SaleReturn $return, SaleRecord $sale, array $original, array $config, string $environment): FiscalResult
    {
        try {
            $application = $this->creditNoteApplication($return, $sale, $original, $config);
        } catch (RuntimeException $e) {
            return FiscalResult::fail($e->getMessage(), [], true);
        }
        $r = $this->client($config, $environment)->creditNote($application);
        if (! $r['ok']) {
            return FiscalResult::fail('URA refused the credit note: '.($r['message'] ?: $r['code']), ['request' => $r['request'], 'response' => $r['response']]);
        }
        // T110 answers an application reference; the credit note's FDN is issued when URA approves it.
        $ref = is_array($r['content']) ? (string) ($r['content']['referenceNo'] ?? $r['content']['id'] ?? $application['oriInvoiceNo']) : (string) $application['oriInvoiceNo'];

        return FiscalResult::ok($ref, null, null, ['request' => $r['request'], 'response' => $r['response']]);
    }

    /** @param array{ok: bool, code: string, message: string, content: mixed, request: array, response: mixed} $r */
    public function result(array $r): FiscalResult
    {
        $raw = ['request' => $r['request'], 'response' => $r['response']];
        if (! $r['ok']) {
            return FiscalResult::fail('URA refused the receipt: '.($r['message'] ?: 'code '.$r['code']), $raw);
        }
        $c = is_array($r['content']) ? $r['content'] : [];
        $fdn = (string) ($c['basicInformation']['invoiceNo'] ?? '');
        if ($fdn === '') {
            return FiscalResult::fail('URA accepted the receipt but sent no fiscal document number.', $raw);
        }

        return FiscalResult::ok($fdn, ($c['basicInformation']['antifakeCode'] ?? null) ?: null, ($c['summary']['qrCode'] ?? null) ?: null, $raw);
    }

    // ── Mapping ──────────────────────────────────────────────

    /** "standard = 123" lines → [key => code]. Keys: standard|zero|exempt|reduced|default|sku:X|product:N. */
    public static function goodsCodes(?string $text): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n|;/', (string) $text) as $line) {
            if (str_contains($line, '=')) {
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                if ($k !== '' && $v !== '') {
                    $out[strtolower($k)] = $v;
                }
            }
        }

        return $out;
    }

    private static function num(float $v, int $dp = 2): string
    {
        $s = number_format($v, $dp, '.', '');

        return str_contains($s, '.') ? (rtrim(rtrim($s, '0'), '.') ?: '0') : $s;
    }

    /** The tax class of a line: [kind standard|zero|exempt, rate %]. */
    private function lineTax(object $line, array $classCodes, Company $company, array $config): array
    {
        if ($line->tax_rate !== null) {
            $code = $line->tax_class_id ? ($classCodes[(int) $line->tax_class_id] ?? null) : null;
            $rate = (float) $line->tax_rate;
            $kind = match (true) {
                $code === 'exempt' => 'exempt',
                $code === 'zero', $rate <= 0 => 'zero',
                default => 'standard',
            };

            return [$kind, $kind === 'standard' ? $rate : 0.0];
        }
        $rate = (float) ($company->tax_rate ?? 0);
        if ($rate > 0) {
            return ['standard', $rate];
        }
        $kind = in_array($config['default_tax'] ?? '', ['standard', 'zero', 'exempt'], true) ? $config['default_tax'] : 'standard';

        return [$kind, $kind === 'standard' ? 18.0 : 0.0];
    }

    private function taxRateString(string $kind, float $rate): string
    {
        return match ($kind) {
            'exempt' => '-',
            'zero' => '0',
            default => self::num($rate / 100, 4),
        };
    }

    private function goodsCode(array $codes, object $line, string $kind): string
    {
        foreach (['product:'.(int) $line->stock_item_id, 'sku:'.strtolower((string) $line->item_sku), $kind, 'default'] as $k) {
            if (isset($codes[$k])) {
                return $codes[$k];
            }
        }
        throw new RuntimeException('No EFRIS goods code for "'.$line->item_name.'". Add a line for "'.$kind.'" or "default" under Goods codes.');
    }

    /**
     * The T109 invoice for a sale. Amounts are tax-inclusive (EFRIS convention): a line's gross is what the
     * customer pays for it (line_total), its tax the class tax stored on the line (or worked out at the rate).
     *
     * @return array<string, mixed>
     */
    public function invoice(SaleRecord $sale, array $config): array
    {
        $sale->loadMissing('saleRecordItems');
        $company = Company::withoutGlobalScopes()->findOrFail($sale->company_id);
        $codes = self::goodsCodes($config['goods_codes'] ?? '');
        $uom = trim((string) ($config['unit_of_measure'] ?? '')) ?: '101';
        $classCodes = TaxClass::withoutGlobalScopes()->where('company_id', $sale->company_id)->pluck('code', 'id')->all();
        $onTop = TaxClassService::addedOnTop($sale);

        $goods = [];
        $groups = [];
        $order = 0;
        foreach ($sale->saleRecordItems as $line) {
            [$kind, $rate] = $this->lineTax($line, $classCodes, $company, $config);
            $taxOf = fn (float $gross) => $kind === 'standard' && $rate > 0 ? round($gross * $rate / (100 + $rate), 2) : 0.0;
            $qty = (float) $line->quantity;
            $gross = round((float) $line->line_total, 2);
            $tax = $line->tax_amount !== null ? round((float) $line->tax_amount, 2) : $taxOf($gross);
            // Before any discount, tax-inclusive: the shelf price (plus the tax, when it is added on top).
            $before = round((float) $line->subtotal * ($onTop && $kind === 'standard' ? (1 + $rate / 100) : 1), 2);
            $discount = round($before - $gross, 2);
            $category = $this->goodsCode($codes, $line, $kind);
            $row = [
                'item' => mb_substr((string) $line->item_name, 0, 200),
                'itemCode' => (string) ($line->item_sku ?: 'BP'.$line->stock_item_id),
                'qty' => self::num($qty, 3),
                'unitOfMeasure' => $uom,
                'unitPrice' => self::num($qty > 0 ? ($discount > 0.004 ? $before : $gross) / $qty : 0, 8),
                'total' => self::num($discount > 0.004 ? $before : $gross),
                'taxRate' => $this->taxRateString($kind, $rate),
                'tax' => self::num($discount > 0.004 ? $taxOf($before) : $tax),
                'discountTotal' => $discount > 0.004 ? self::num(-$discount) : '',
                'discountTaxRate' => $discount > 0.004 ? $this->taxRateString($kind, $rate) : '',
                'orderNumber' => (string) $order++,
                'discountFlag' => $discount > 0.004 ? '1' : '2',
                'deemedFlag' => '2',
                'exciseFlag' => '2',
                'categoryId' => '',
                'categoryName' => '',
                'goodsCategoryId' => $category,
                'goodsCategoryName' => '',
                'exciseRate' => '',
                'exciseRule' => '',
                'exciseTax' => '',
                'pack' => '',
                'stick' => '',
                'exciseUnit' => '',
                'exciseCurrency' => '',
                'exciseRateName' => '',
                'vatApplicableFlag' => '1',
            ];
            $goods[] = $row;
            if ($discount > 0.004) {
                $goods[] = array_merge($row, [
                    'item' => mb_substr((string) $line->item_name, 0, 189).' (Discount)',
                    'qty' => '', 'unitOfMeasure' => '', 'unitPrice' => '',
                    'total' => self::num(-$discount),
                    'tax' => self::num(-round($taxOf($before) - $tax, 2)),
                    'discountTotal' => '', 'discountTaxRate' => '',
                    'orderNumber' => (string) $order++,
                    'discountFlag' => '0',
                ]);
            }
            $g = $kind.'@'.$rate;
            $groups[$g] ??= ['kind' => $kind, 'rate' => $rate, 'gross' => 0.0, 'tax' => 0.0];
            $groups[$g]['gross'] = round($groups[$g]['gross'] + $gross, 2);
            $groups[$g]['tax'] = round($groups[$g]['tax'] + $tax, 2);
        }

        $taxDetails = [];
        foreach ($groups as $g) {
            [$cat, $name] = self::TAX_CODES[$g['kind']];
            $taxDetails[] = [
                'taxCategoryCode' => $cat,
                'netAmount' => self::num($g['gross'] - $g['tax']),
                'taxRate' => $this->taxRateString($g['kind'], $g['rate']),
                'taxAmount' => self::num($g['tax']),
                'grossAmount' => self::num($g['gross']),
                'exciseUnit' => '',
                'exciseCurrency' => '',
                'taxRateName' => $g['kind'] === 'standard' ? 'VAT '.self::num($g['rate']).'%' : $name,
            ];
        }
        $grossTotal = round(array_sum(array_column($groups, 'gross')), 2);
        $taxTotal = round(array_sum(array_column($groups, 'tax')), 2);

        $customer = $sale->customer_id ? DB::table('customers')->where('company_id', $sale->company_id)->where('id', $sale->customer_id)->first() : null;
        $buyerTin = trim((string) ($customer->tin ?? ''));
        $walkIn = ! $sale->customer_name || strcasecmp((string) $sale->customer_name, 'Walk-in Customer') === 0;
        $reference = (string) ($sale->receipt_number ?: 'BP-'.$sale->id);

        return [
            'sellerDetails' => [
                'tin' => (string) ($config['tin'] ?? ''),
                'ninBrn' => (string) ($config['brn'] ?? ''),
                'legalName' => (string) (($config['legal_name'] ?? '') ?: $company->name),
                'businessName' => (string) $company->name,
                'address' => (string) ($company->address ?? ''),
                'mobilePhone' => (string) ($company->phone_number ?? ''),
                'linePhone' => '',
                'emailAddress' => (string) ($company->email ?? ''),
                'placeOfBusiness' => (string) ($company->address ?? ''),
                'referenceNo' => $reference,
                'branchId' => '',
                'isCheckReferenceNo' => '1',
            ],
            'basicInformation' => [
                'invoiceNo' => '',
                'antifakeCode' => '',
                'deviceNo' => (string) ($config['device_no'] ?? ''),
                'issuedDate' => $sale->created_at ? $sale->created_at->copy()->setTimezone($company->timezone ?: 'Africa/Kampala')->format('Y-m-d H:i:s') : now('Africa/Kampala')->format('Y-m-d H:i:s'),
                'operator' => mb_substr((string) (DB::table('admin_users')->where('id', $sale->created_by_id)->value('name') ?: 'Cashier'), 0, 100),
                'currency' => strtoupper((string) ($sale->currency ?: $company->currency ?: 'UGX')),
                'oriInvoiceId' => '',
                'invoiceType' => '1',
                'invoiceKind' => $buyerTin !== '' ? '1' : '2',
                'dataSource' => '103',
                'invoiceIndustryCode' => '101',
                'isBatch' => '0',
            ],
            'buyerDetails' => [
                'buyerTin' => $buyerTin,
                'buyerNinBrn' => '',
                'buyerPassportNum' => '',
                'buyerLegalName' => $walkIn ? '' : mb_substr((string) $sale->customer_name, 0, 256),
                'buyerBusinessName' => '',
                'buyerAddress' => (string) ($sale->customer_address ?? ''),
                'buyerEmail' => (string) ($customer->email ?? ''),
                'buyerMobilePhone' => (string) ($sale->customer_phone ?? ''),
                'buyerLinePhone' => '',
                'buyerPlaceOfBusi' => '',
                'buyerType' => $buyerTin !== '' ? '0' : '1',
                'buyerCitizenship' => '',
                'buyerSector' => '',
                'buyerReferenceNo' => '',
            ],
            'goodsDetails' => $goods,
            'taxDetails' => $taxDetails,
            'summary' => [
                'netAmount' => self::num($grossTotal - $taxTotal),
                'taxAmount' => self::num($taxTotal),
                'grossAmount' => self::num($grossTotal),
                'itemCount' => (string) count($goods),
                'modeCode' => '1',
                'remarks' => mb_substr((string) ($sale->notes ?? ''), 0, 500),
                'qrCode' => '',
            ],
            'payWay' => $this->payWay($sale, $grossTotal),
            'extend' => ['reason' => '', 'reasonCode' => ''],
        ];
    }

    /** @return list<array{paymentMode: string, paymentAmount: string, orderNumber: string}> */
    private function payWay(SaleRecord $sale, float $gross): array
    {
        $paid = DB::table('payments')->where('company_id', $sale->company_id)->where('sale_record_id', $sale->id)->where('is_deleted', 0)
            ->where('amount', '>', 0)->get(['method', 'amount']);
        $modes = [];
        foreach ($paid as $p) {
            $m = self::PAY_MODES[Payment::normalizeMethod($p->method)] ?? '102';
            $modes[$m] = round(($modes[$m] ?? 0) + (float) $p->amount, 2);
        }
        $owed = round($gross - array_sum($modes), 2);
        if ($owed > 0.004) {
            $modes['101'] = round(($modes['101'] ?? 0) + $owed, 2);
        }
        if ($modes === []) {
            $modes['102'] = $gross;
        }
        $out = [];
        $letter = 'a';
        foreach ($modes as $mode => $amount) {
            $out[] = ['paymentMode' => (string) $mode, 'paymentAmount' => self::num(min($amount, $gross)), 'orderNumber' => $letter++];
        }

        return $out;
    }

    /**
     * T110: a credit note application for returned goods of a fiscalised sale.
     *
     * @return array<string, mixed>
     */
    public function creditNoteApplication(SaleReturn $return, SaleRecord $sale, array $original, array $config): array
    {
        $return->loadMissing('items');
        $invoice = $this->invoice($sale, $config);
        $byLine = collect($sale->saleRecordItems)->keyBy('id');
        $goods = [];
        $gross = 0.0;
        $tax = 0.0;
        $order = 0;
        foreach ($return->items as $ri) {
            $line = $byLine->get($ri->sale_record_item_id);
            if ($line === null || (float) $line->quantity <= 0) {
                continue;
            }
            $share = min(1.0, (float) $ri->quantity / (float) $line->quantity);
            $src = collect($invoice['goodsDetails'])->first(fn ($g) => $g['discountFlag'] !== '0' && $g['item'] === mb_substr((string) $line->item_name, 0, 200));
            $lineGross = round((float) $ri->value, 2);
            $lineTax = $line->tax_amount !== null ? round((float) $line->tax_amount * $share, 2) : 0.0;
            $gross += $lineGross;
            $tax += $lineTax;
            $goods[] = [
                'item' => (string) $line->item_name, 'itemCode' => $src['itemCode'] ?? (string) $line->item_sku,
                'qty' => self::num(-(float) $ri->quantity, 3), 'unitOfMeasure' => $src['unitOfMeasure'] ?? '101',
                'unitPrice' => self::num((float) $ri->quantity > 0 ? $lineGross / (float) $ri->quantity : 0, 8), 'total' => self::num(-$lineGross),
                'taxRate' => $src['taxRate'] ?? '0.18', 'tax' => self::num(-$lineTax), 'orderNumber' => (string) $order++,
                'deemedFlag' => '2', 'exciseFlag' => '2', 'goodsCategoryId' => $src['goodsCategoryId'] ?? '', 'vatApplicableFlag' => '1',
            ];
        }
        if ($goods === []) {
            throw new RuntimeException('Nothing on this return can be matched to the fiscalised sale.');
        }

        return [
            'oriInvoiceId' => (string) ($original['raw_invoice_id'] ?? ''),
            'oriInvoiceNo' => (string) ($original['fiscal_number'] ?? ''),
            'reasonCode' => '102', // 102 = goods returned (UNCERTAIN: check URA's reason code list)
            'reason' => mb_substr((string) ($return->reason ?: 'Goods returned'), 0, 1024),
            'applicationTime' => now('Africa/Kampala')->format('Y-m-d H:i:s'),
            'invoiceApplyCategoryCode' => '101',
            'currency' => $invoice['basicInformation']['currency'],
            'contactName' => '',
            'contactMobileNum' => '',
            'contactEmail' => '',
            'source' => '103',
            'remarks' => 'Return '.$return->id.' of '.($sale->receipt_number ?: $sale->id),
            'sellersReferenceNo' => 'R-'.$return->id,
            'goodsDetails' => $goods,
            'taxDetails' => [],
            'summary' => ['netAmount' => self::num(-($gross - $tax)), 'taxAmount' => self::num(-$tax), 'grossAmount' => self::num(-$gross),
                'itemCount' => (string) count($goods), 'modeCode' => '0', 'qrCode' => ''],
            'payWay' => [['paymentMode' => '102', 'paymentAmount' => self::num(-$gross), 'orderNumber' => 'a']],
            'buyerDetails' => $invoice['buyerDetails'],
        ];
    }
}
