<?php

namespace App\Services\Engage;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaleRecord;
use App\Services\FlutterwaveService;
use App\Services\Shop\PaymentService;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mobile-money request-to-pay (plan Part E3): the shop registers the number its
 * money should reach (a Flutterwave subaccount); the cashier requests the
 * amount from the customer's phone; when Flutterwave confirms (webhook or a
 * status check) the payment is recorded on the sale — once.
 */
class MomoCollections
{
    public function __construct(private readonly FlutterwaveService $flw)
    {
    }

    public function networks(Company $company): array
    {
        return array_keys(config('flutterwave.momo.networks.'.strtoupper((string) $company->currency), []));
    }

    public function setup(Company $company, string $phone, string $network): Company
    {
        $cur = strtoupper((string) $company->currency);
        $codes = config("flutterwave.momo.networks.{$cur}", []);
        if (! isset($codes[strtoupper($network)])) {
            throw BusinessRuleException::make('unsupported_network', 'Choose one of: '.implode(', ', array_keys($codes)).'.');
        }
        $e164 = Phone::e164($phone, $company->country ?? 'UG');
        if ($e164 === null) {
            throw BusinessRuleException::make('invalid_phone', 'Enter the mobile money number that should receive payments.');
        }
        $r = $this->flw->createSubaccount([
            'account_bank' => $codes[strtoupper($network)], 'account_number' => ltrim($e164, '+'), 'business_name' => $company->name,
            'business_email' => $company->email ?: 'shop'.$company->id.'@'.parse_url((string) config('app.url'), PHP_URL_HOST),
            'business_mobile' => $e164, 'country' => $company->country ?? 'UG',
            'split_type' => 'percentage', 'split_value' => (float) config('flutterwave.momo.platform_fee_percent', 0) / 100,
        ]);
        if (! $r['success']) {
            throw BusinessRuleException::make('provider_error', $r['message'] ?? 'Could not register the number.');
        }
        $company->momo_subaccount_id = $r['id'];
        $company->momo_payout_phone = $e164;
        $company->momo_payout_network = strtoupper($network);
        $company->saveQuietly();

        return $company;
    }

    /** @return object the momo_requests row */
    public function request(SaleRecord $sale, string $phone, ?string $network, ?float $amount, int $userId): object
    {
        $company = Company::withoutGlobalScopes()->findOrFail($sale->company_id);
        if (! $company->momo_subaccount_id) {
            throw BusinessRuleException::make('momo_not_set_up', 'Set up the mobile money number that receives payments first (Settings → Mobile money).');
        }
        if ($sale->voided_at !== null) {
            throw BusinessRuleException::make('sale_voided', 'This sale was voided.');
        }
        $amount = round($amount ?? (float) $sale->balance, 2);
        if ($amount <= 0 || $amount > (float) $sale->balance + 0.001) {
            throw BusinessRuleException::make('invalid_amount', 'Request an amount up to what is still owed ('.number_format((float) $sale->balance).').');
        }
        $cur = strtoupper((string) $company->currency);
        $type = config("flutterwave.momo.charge_types.{$cur}");
        if (! $type) {
            throw BusinessRuleException::make('unsupported_currency', 'Mobile money requests are not available for '.$cur.'.');
        }
        $e164 = Phone::e164($phone, $company->country ?? 'UG');
        if ($e164 === null) {
            throw BusinessRuleException::make('invalid_phone', 'Enter the customer\'s mobile money number.');
        }
        $network = $network ? strtoupper($network) : null;
        $txRef = 'MOMO-'.$company->id.'-'.Str::upper(Str::random(12));
        $id = DB::table('momo_requests')->insertGetId(['company_id' => $company->id, 'sale_record_id' => $sale->id, 'customer_id' => $sale->customer_id, 'phone' => $e164, 'network' => $network,
            'amount' => $amount, 'currency' => $cur, 'tx_ref' => $txRef, 'status' => 'pending', 'created_by_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        $r = $this->flw->chargeMobileMoney($type, array_filter([
            'tx_ref' => $txRef, 'amount' => $amount, 'currency' => $cur, 'phone_number' => ltrim($e164, '+'), 'network' => $network,
            'email' => $company->email ?: 'shop'.$company->id.'@'.parse_url((string) config('app.url'), PHP_URL_HOST), 'fullname' => $sale->customer_name ?: 'Customer',
            'subaccounts' => [['id' => $company->momo_subaccount_id]], 'meta' => ['company_id' => $company->id, 'sale_id' => $sale->id],
        ], fn ($v) => $v !== null));
        DB::table('momo_requests')->where('id', $id)->update($r['success']
            ? ['provider_id' => $r['id'] ?? null, 'redirect_url' => $r['redirect'] ?? null, 'updated_at' => now()]
            : ['status' => 'failed', 'error' => mb_substr((string) ($r['message'] ?? ''), 0, 500), 'updated_at' => now()]);

        return DB::table('momo_requests')->find($id);
    }

    /** Confirm with Flutterwave and record the payment once (webhook or status check). */
    public function settle(string $txRef): ?object
    {
        return DB::transaction(function () use ($txRef) {
            $req = DB::table('momo_requests')->where('tx_ref', $txRef)->lockForUpdate()->first();
            if (! $req || $req->status !== 'pending') {
                return $req;
            }
            $v = $this->flw->verifyByReference($txRef);
            if (! $v['success']) {
                return $req;
            }
            $data = $v['data'];
            $status = strtolower((string) ($data['status'] ?? ''));
            if ($status === 'successful' && $this->flw->transactionSatisfies($data, (float) $req->amount, (string) $req->currency) && ($data['tx_ref'] ?? null) === $txRef) {
                $sale = SaleRecord::withoutGlobalScopes()->find($req->sale_record_id);
                $payment = $sale ? app(PaymentService::class)->record($sale, ['amount' => $req->amount, 'method' => 'mobile_money', 'provider' => 'flutterwave',
                    'reference' => (string) ($data['id'] ?? $req->provider_id), 'received_by_id' => $req->created_by_id, 'client_uuid' => (string) Str::uuid()]) : null;
                DB::table('momo_requests')->where('id', $req->id)->update(['status' => 'successful', 'payment_id' => $payment?->id, 'provider_id' => (string) ($data['id'] ?? $req->provider_id), 'updated_at' => now()]);
            } elseif (in_array($status, ['failed', 'cancelled'], true)) {
                DB::table('momo_requests')->where('id', $req->id)->update(['status' => $status, 'error' => mb_substr((string) ($data['processor_response'] ?? ''), 0, 500), 'updated_at' => now()]);
            }

            return DB::table('momo_requests')->find($req->id);
        });
    }
}
