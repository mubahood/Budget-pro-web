<?php

namespace App\Services\Kin;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoDuesPayment;
use App\Models\SaccoMember;
use App\Models\User;
use App\Services\Engage\MomoCollections;
use App\Services\Sacco\Dues\DuesService;
use App\Services\Sacco\Payments\SaccoCollections;
use App\Services\Team\Permissions;
use App\Support\Phone;
use App\Services\Sacco\PostingCurrency;
use App\Support\Sacco\Amount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A contribution paid by mobile money (doc 13.4). It rides the same collections path as the purse's deposits: a
 * momo_requests row (purpose kin_dues: subject the programme, sacco_member_id whose dues, dues_period), a prompt through
 * the same provider and driver (SaccoCollections::driver: off, simulated, flutterwave; a demo family is always simulated),
 * and the same settle() that the webhook, the poll and the status check call. Nothing is recorded when the prompt is sent:
 * only a provider-confirmed payment becomes a DuesService::pay row (method mobile_money, the provider's reference), once,
 * under the lock settle() holds on the request row, and its id is kept on the request.
 */
class KinDuesMomo
{
    public const PURPOSE = 'kin_dues';

    public function __construct(private DuesService $dues) {}

    /** @return array{enabled: bool, provider: ?string, currency: string} */
    public static function status(Company $company): array
    {
        $on = SaccoCollections::enabled($company);

        return ['enabled' => $on, 'provider' => $on ? (SaccoCollections::driver($company) === 'simulated' ? 'simulated' : 'flutterwave') : null,
            'currency' => PostingCurrency::forCompany($company)];
    }

    /** The number the family's collections settle to (its subaccount): the head only. */
    public function setup(Company $company, User $by, string $phone, string $network): Company
    {
        if (! KinPurse::isHead($by, $company)) {
            throw BusinessRuleException::make('forbidden', 'Only the head of the family sets up mobile-money collection.');
        }

        return (new MomoCollections(SaccoCollections::provider($company)))->setup($company, $phone, $network);
    }

    /**
     * Send the prompt. The person themself (their sign-in's person) or kin_dues may ask.
     *
     * @param array{member_id: int, amount_minor: int, phone?: ?string, network?: ?string, period?: ?string, uuid?: ?string} $d
     * @return object the momo_requests row
     */
    public function request(Company $company, int $programmeId, array $d, User $by): object
    {
        $cid = (int) $company->id;
        $p = DuesService::find($cid, $programmeId);
        $me = DuesService::personOf($by, $cid);
        $memberId = (int) $d['member_id'];
        if (($me === null || (int) $me->id !== $memberId) && ! Permissions::can($by, 'kin_dues')) {
            throw BusinessRuleException::make('forbidden', 'You may pay your own contribution only. Ask the treasurer to take a payment for someone else.');
        }
        $member = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->whereNull('merged_into_id')->find($memberId)
            ?? throw BusinessRuleException::make('not_found', 'That person is not in this family.');
        if ($p->status !== 'active') {
            throw BusinessRuleException::make('programme_closed', 'This contribution is closed.');
        }
        if (! SaccoCollections::enabled($company)) {
            throw BusinessRuleException::make('collections_off', 'Paying by mobile money is not set up for this family yet: give the money to the treasurer.');
        }
        $amount = (int) $d['amount_minor'];
        $period = $p->kind === 'once' ? null : (filled($d['period'] ?? null) ? (string) $d['period'] : null);
        if ($period !== null && ! DB::table('sacco_dues_lines')->where('programme_id', $p->id)->where('member_id', $memberId)->where('period_key', $period)->exists()) {
            throw BusinessRuleException::make('period_invalid', "{$member->full_name} has no dues for ".(DuesService::periodLabel($period) ?? $period).' in this contribution.', ['period' => ['Not one of their periods.']]);
        }
        if ($p->amount_mode !== 'any' && ! $p->allow_advance) {
            $balance = DuesService::balanceOf($p, $memberId);
            if ($amount > $balance) {
                throw BusinessRuleException::make('over_balance', $balance > 0
                    ? "{$member->full_name} owes ".DuesService::fmt($company, $balance).' here: ask for that much or less.'
                    : "{$member->full_name} owes nothing here.", ['balance_minor' => max(0, $balance), 'amount_minor' => ['More than the balance.']]);
            }
        }
        $currency = PostingCurrency::forCompany($company);
        $type = config('flutterwave.momo.charge_types.'.$currency);
        if (! $type) {
            throw BusinessRuleException::make('unsupported_currency', 'Mobile-money payments are not available for '.$currency.'.');
        }
        $phone = Phone::e164((string) ($d['phone'] ?? $member->phone_e164 ?? $me?->phone_e164 ?? ''), (string) ($company->country ?: 'UG'));
        if ($phone === null) {
            throw BusinessRuleException::make('invalid_phone', 'Give the mobile-money number the prompt goes to.', ['phone' => ['Required.']]);
        }
        $uuid = strtolower((string) ($d['uuid'] ?? Str::uuid()));
        $existing = DB::table('momo_requests')->where('company_id', $cid)->where('uuid', $uuid)->first();
        if ($existing !== null) {
            if ($existing->purpose !== self::PURPOSE || (int) $existing->subject_id !== (int) $p->id || (int) $existing->sacco_member_id !== $memberId || (int) $existing->amount_minor !== $amount) {
                throw BusinessRuleException::make('idempotency_conflict', 'That request was already sent with other details.');
            }

            return $existing;
        }
        $network = filled($d['network'] ?? null) ? strtoupper((string) $d['network']) : null;
        $money = Amount::of($amount, $currency);
        $txRef = SaccoCollections::PREFIX.$cid.'-'.Str::upper(Str::random(12)); // the webhook routes SACCO- references to settle()
        $id = DB::table('momo_requests')->insertGetId(['company_id' => $cid, 'purpose' => self::PURPOSE, 'uuid' => $uuid, 'subject_type' => 'sacco_dues_programmes',
            'subject_id' => $p->id, 'sacco_member_id' => $memberId, 'dues_period' => $period, 'phone' => $phone, 'network' => $network, 'amount' => $money->toMajorString(),
            'amount_minor' => $amount, 'currency' => $currency, 'tx_ref' => $txRef, 'status' => 'pending', 'requested_via' => $me !== null && (int) $me->id === $memberId ? 'member' : 'officer',
            'created_by_id' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        $host = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
        $r = SaccoCollections::provider($company)->chargeMobileMoney((string) $type, array_filter([
            'tx_ref' => $txRef, 'amount' => $money->toMajorString(), 'currency' => $currency, 'phone_number' => ltrim($phone, '+'), 'network' => $network,
            'email' => $member->email ?: 'member'.$member->id.'@'.$host, 'fullname' => (string) $member->full_name,
            'subaccounts' => SaccoCollections::driver($company) === 'flutterwave' && ! MomoCollections::direct() ? [['id' => $company->momo_subaccount_id]] : null,
            'meta' => ['company_id' => $cid, 'purpose' => self::PURPOSE],
        ], fn ($x) => $x !== null));
        DB::table('momo_requests')->where('id', $id)->update($r['success']
            ? ['provider_id' => $r['id'] ?? null, 'redirect_url' => $r['redirect'] ?? null, 'updated_at' => now()]
            : ['status' => 'failed', 'error' => mb_substr((string) ($r['message'] ?? 'The provider refused the request.'), 0, 500), 'updated_at' => now()]);

        return DB::table('momo_requests')->find($id);
    }

    /**
     * The provider confirmed the money (settle(), under its lock on the request row): record the payment once. Returns
     * the payment, or null when it could not be recorded (the person left the family meanwhile): the request then says so
     * for the treasurer, and the money is not lost from view.
     */
    public function record(Company $company, object $req, string $providerId): ?SaccoDuesPayment
    {
        $cid = (int) $company->id;
        $reference = mb_substr($providerId !== '' ? $providerId : (string) $req->tx_ref, 0, 100);
        $hit = SaccoDuesPayment::withoutGlobalScopes()->where('company_id', $cid)->where('programme_id', $req->subject_id)->where('method', 'mobile_money')
            ->where('reference', $reference)->where('amount_minor', '>', 0)->first();
        if ($hit !== null || $req->sacco_dues_payment_id !== null) {
            return $hit ?? SaccoDuesPayment::withoutGlobalScopes()->find($req->sacco_dues_payment_id);
        }
        $p = DuesService::find($cid, (int) $req->subject_id);
        $by = User::withoutGlobalScopes()->find($req->created_by_id) ?? User::withoutGlobalScopes()->find($company->owner_id);
        $payer = $by !== null ? DuesService::personOf($by, $cid) : null;
        $d = ['member_id' => (int) $req->sacco_member_id, 'amount_minor' => (int) $req->amount_minor, 'method' => 'mobile_money', 'reference' => $reference,
            'period' => $req->dues_period, 'note' => 'Paid by mobile money', 'paid_by_member_id' => $payer !== null && (int) $payer->id !== (int) $req->sacco_member_id ? (int) $payer->id : null];
        try {
            return $this->dues->pay($company, $p, $d, $by, true);
        } catch (BusinessRuleException $e) {
            if ($e->errorCode() === 'period_invalid') {
                return $this->dues->pay($company, $p, ['period' => null] + $d, $by, true);
            }
            Log::warning('kin dues momo received but not recorded', ['request_id' => (int) $req->id, 'code' => $e->errorCode()]);

            return null;
        }
    }

    /** @return array{request_id: int, status: string, payment_id?: int, receipt_no?: string, amount_minor: int, currency: string, error: ?string} */
    public static function present(object $r): array
    {
        $status = match ((string) $r->status) {
            'successful' => 'paid',
            'failed', 'cancelled' => 'failed',
            'expired' => 'expired',
            default => 'pending',
        };
        $out = ['request_id' => (int) $r->id, 'status' => $status, 'amount_minor' => (int) $r->amount_minor, 'currency' => (string) $r->currency, 'error' => $r->error];
        if ($status === 'paid' && $r->sacco_dues_payment_id !== null) {
            $out['payment_id'] = (int) $r->sacco_dues_payment_id;
            $out['receipt_no'] = (string) DB::table('sacco_dues_payments')->where('id', $r->sacco_dues_payment_id)->value('receipt_no');
        }

        return $out;
    }
}
