<?php

namespace App\Services\Sacco\Payments;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoLoan;
use App\Models\SaccoMember;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSetting;
use App\Models\SaccoTransaction;
use App\Models\User;
use App\Services\Billing\SimulatedFlutterwave;
use App\Services\Engage\MomoCollections;
use App\Services\FlutterwaveService;
use App\Services\Sacco\Ledger\PostingRules;
use App\Services\Sacco\Loans\Repayments;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoAudit;
use App\Services\Sacco\SaccoTransactions;
use App\Support\LocalDate;
use App\Support\Phone;
use App\Support\Rules\SaccoRules;
use App\Support\Sacco\Amount;
use App\Support\Sacco\Currency;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A member's money by mobile money (docs/akiba/04-architecture.md §11.1, P3.1, AK-100): a request-to-pay for a deposit
 * to savings, a loan repayment or shares, sent by an officer or by the member from the app. The prompt goes to the
 * member's phone through Flutterwave into the SACCO's own subaccount (MomoCollections::setup), so the platform never
 * holds the money. Nothing is posted when the request is made.
 *
 * settle() is the only way a collection becomes money in the books, and the webhook, the poll and the app's status
 * check all call it: under a lock on the momo_requests row it asks the provider by reference, and only a successful
 * answer for the same reference, currency and at least the amount (compared in whole minor units) records the
 * receipt, channel `momo` (Dr 1160 MoMo clearing). The receipt's uuid is derived from `momo:{tx_ref}`, so however
 * many times settle() runs, and in however many processes, there is one receipt and one posting. A declined or
 * cancelled prompt posts nothing. When the money arrives but its account can no longer take it (closed, the loan
 * paid off meanwhile), it is held in 2150 Unallocated receipts for an officer to allocate, never lost or refused.
 *
 * The driver (config sacco.collections.driver): `off` (production until the owner adds keys: nothing is sent),
 * `simulated` (local: SimulatedFlutterwave, no network; a phone ending in 000 declines) or `flutterwave`. A demo
 * SACCO is always simulated. Tests bind a fake FlutterwaveService, and the real one refuses network calls in tests.
 */
final class SaccoCollections
{
    /** References of SACCO collections (the webhook routes them here). */
    public const PREFIX = 'SACCO-';

    /** purpose => the receipt it becomes */
    public const PURPOSES = ['sacco_deposit' => 'deposit', 'sacco_repayment' => 'loan_repayment', 'sacco_shares' => 'share_purchase'];

    /** Statuses after which nothing changes. */
    public const FINAL = ['successful', 'failed', 'cancelled', 'expired'];

    public static function driver(?Company $company = null): string
    {
        if ($company !== null && (bool) $company->is_demo) {
            return 'simulated'; // a demo SACCO's members are made up: never a real prompt
        }

        return (string) config('sacco.collections.driver', 'off');
    }

    /** Whether this SACCO can send a request-to-pay now. */
    public static function enabled(Company $company): bool
    {
        return match (self::driver($company)) {
            'simulated' => true,
            'flutterwave' => MomoCollections::direct() ? filled(config('flutterwave.secret_key')) : (bool) $company->momo_subaccount_id,
            default => false,
        };
    }

    /** The provider for this SACCO's collections. */
    public static function provider(Company $company): FlutterwaveService
    {
        return match (self::driver($company)) {
            'simulated' => new SimulatedFlutterwave((int) config('sacco.collections.simulated_approve_after', 6)),
            'flutterwave' => app(FlutterwaveService::class),
            default => throw BusinessRuleException::make('collections_off', 'Mobile-money collections are not switched on yet: take the money in cash or by bank.'),
        };
    }

    /** Register the number the SACCO's collections settle to (its subaccount), as a shop does (MomoCollections::setup). */
    public function setup(Company $company, User $by, string $phone, string $network): Company
    {
        SaccoAccess::authorize($by, 'sacco_settings');
        $company = (new MomoCollections(self::provider($company)))->setup($company, $phone, $network);
        SaccoAudit::record('sacco.collections.setup', 'companies', (int) $company->id, ['network' => strtoupper($network)], (int) $by->id, (int) $company->id);

        return $company;
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'uuid' => ['nullable', 'uuid'],
            'purpose' => ['required', Rule::in(array_keys(self::PURPOSES))],
            'savings_account_id' => ['nullable', 'integer'],
            'loan_id' => ['nullable', 'integer'],
            'member_id' => ['nullable', 'integer'],
            'amount_minor' => SaccoRules::minor(true, 1),
            'phone' => ['nullable', 'string', 'max:20'],
            'network' => ['nullable', 'string', 'max:10'],
        ];
    }

    /**
     * Send a request-to-pay. An officer (sacco_teller) asks for any member's money; a member asks only for their own
     * account or loan. Idempotent on `uuid`: the same uuid and facts return the first request, other facts are
     * `idempotency_conflict`.
     *
     * @param  array<string, mixed>  $data  rules()
     * @return object the momo_requests row
     */
    public function request(Company $company, array $data, ?User $by, ?SaccoMember $asMember = null): object
    {
        $cid = (int) $company->id;
        $v = validator($data, self::rules());
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_collection', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }
        $d = $v->validated();
        if ($by !== null) {
            SaccoAccess::authorize($by, 'sacco_teller');
        } elseif ($asMember === null || (int) $asMember->company_id !== $cid) {
            throw BusinessRuleException::make('forbidden', 'Sign in to ask for a payment.');
        }
        $uuid = strtolower((string) ($d['uuid'] ?? Str::uuid()));
        $purpose = (string) $d['purpose'];
        [$subjectType, $subjectId, $member] = $this->subject($cid, $purpose, $d, $asMember);
        if (! self::enabled($company)) {
            throw BusinessRuleException::make('collections_off', 'Mobile-money collections are not switched on yet: take the money in cash or by bank.');
        }
        $amount = (int) $d['amount_minor'];
        $existing = DB::table('momo_requests')->where('company_id', $cid)->where('uuid', $uuid)->first();
        if ($existing !== null) {
            if ($existing->purpose !== $purpose || (int) $existing->subject_id !== $subjectId || (int) $existing->amount_minor !== $amount) {
                throw BusinessRuleException::make('idempotency_conflict', 'That request was already sent with other details.', ['collection_id' => (int) $existing->id]);
            }

            return $existing;
        }
        $settings = SaccoSetting::for($cid) ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
        $currency = (string) $settings->currency;
        if ($purpose === 'sacco_shares' && $amount % max(1, (int) $settings->share_par_minor) !== 0) {
            throw BusinessRuleException::make('not_whole_shares', 'Ask for whole shares: a share is '.Amount::of((int) $settings->share_par_minor, $currency)->toMajorString().'.');
        }
        $type = config('flutterwave.momo.charge_types.'.$currency);
        if (! $type) {
            throw BusinessRuleException::make('unsupported_currency', 'Mobile-money requests are not available for '.$currency.'.');
        }
        $phone = Phone::e164((string) ($d['phone'] ?? $member->phone_e164 ?? ''), (string) ($company->country ?: 'UG'));
        if ($phone === null) {
            throw BusinessRuleException::make('invalid_phone', 'Give the mobile-money number the prompt goes to.');
        }
        $network = isset($d['network']) && $d['network'] !== '' ? strtoupper((string) $d['network']) : null;
        $money = Amount::of($amount, $currency);
        $txRef = self::PREFIX.$cid.'-'.Str::upper(Str::random(12));
        try {
            $id = DB::table('momo_requests')->insertGetId(['company_id' => $cid, 'purpose' => $purpose, 'uuid' => $uuid, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
                'sacco_member_id' => (int) $member->id, 'phone' => $phone, 'network' => $network, 'amount' => $money->toMajorString(), 'amount_minor' => $amount, 'currency' => $currency,
                'tx_ref' => $txRef, 'status' => 'pending', 'requested_via' => $by !== null ? 'officer' : 'member', 'created_by_id' => $by?->id, 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Illuminate\Database\QueryException $e) {
            $twin = DB::table('momo_requests')->where('company_id', $cid)->where('uuid', $uuid)->first();
            if ($twin !== null) {
                return $twin; // a double tap committed first
            }
            throw $e;
        }
        $host = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
        $r = self::provider($company)->chargeMobileMoney((string) $type, array_filter([
            'tx_ref' => $txRef, 'amount' => $money->toMajorString(), 'currency' => $currency, 'phone_number' => ltrim($phone, '+'), 'network' => $network,
            'email' => $member->email ?: 'member'.$member->id.'@'.$host, 'fullname' => (string) $member->full_name,
            'subaccounts' => self::driver($company) === 'flutterwave' && ! MomoCollections::direct() ? [['id' => $company->momo_subaccount_id]] : null,
            'meta' => ['company_id' => $cid, 'purpose' => $purpose],
        ], fn ($x) => $x !== null));
        DB::table('momo_requests')->where('id', $id)->update($r['success']
            ? ['provider_id' => $r['id'] ?? null, 'redirect_url' => $r['redirect'] ?? null, 'updated_at' => now()]
            : ['status' => 'failed', 'error' => mb_substr((string) ($r['message'] ?? 'The provider refused the request.'), 0, 500), 'updated_at' => now()]);
        SaccoAudit::record('sacco.collection.requested', 'momo_requests', $cid, ['purpose' => $purpose, 'amount_minor' => $amount, 'member_id' => (int) $member->id,
            'phone' => \App\Services\Auth\OtpService::mask($phone)], $by?->id, $id);

        return DB::table('momo_requests')->find($id);
    }

    /**
     * Ask the provider and, on a confirmed payment, record the receipt once (webhook, poll and status checks).
     *
     * @return ?object the momo_requests row (null when the reference is not a SACCO collection)
     */
    public function settle(string $txRef): ?object
    {
        return DB::transaction(function () use ($txRef) {
            $req = DB::table('momo_requests')->where('tx_ref', $txRef)->whereNotNull('purpose')->lockForUpdate()->first();
            if ($req === null || $req->status !== 'pending') {
                return $req;
            }
            $company = Company::withoutGlobalScopes()->find($req->company_id);
            if ($company === null) {
                return $req;
            }
            $v = self::provider($company)->verifyByReference($txRef);
            DB::table('momo_requests')->where('id', $req->id)->update(['checks' => DB::raw('checks + 1'), 'checked_at' => now(), 'updated_at' => now()]);
            if (! $v['success']) {
                return DB::table('momo_requests')->find($req->id); // not answered yet (or the provider is down): asked again later
            }
            $data = (array) $v['data'];
            $status = strtolower((string) ($data['status'] ?? ''));
            if ($status === 'successful') {
                $paid = self::providerMinor($data['amount'] ?? null, (string) $req->currency);
                if (($data['tx_ref'] ?? null) !== $txRef || strtoupper((string) ($data['currency'] ?? '')) !== strtoupper((string) $req->currency) || $paid === null || $paid < (int) $req->amount_minor) {
                    DB::table('momo_requests')->where('id', $req->id)->update(['status' => 'failed', 'error' => 'The provider confirmed a different reference, currency or amount: nothing was recorded.',
                        'updated_at' => now()]);
                    SaccoAudit::record('sacco.collection.mismatch', 'momo_requests', (int) $req->company_id, ['tx_ref' => $txRef], null, (int) $req->id);

                    return DB::table('momo_requests')->find($req->id);
                }
                $tx = $this->receipt($company, $req, (string) ($data['id'] ?? $req->provider_id ?? ''));
                DB::table('momo_requests')->where('id', $req->id)->update(['status' => 'successful', 'sacco_transaction_id' => $tx->id, 'provider_id' => (string) ($data['id'] ?? $req->provider_id),
                    'settled_at' => now(), 'error' => $tx->status === 'held' ? 'Received, but its account could not take it: held for an officer to allocate.' : null, 'updated_at' => now()]);
                SaccoAudit::record('sacco.collection.settled', 'momo_requests', (int) $req->company_id, ['transaction_id' => (int) $tx->id, 'status' => $tx->status], null, (int) $req->id);
            } elseif (in_array($status, ['failed', 'cancelled'], true)) {
                DB::table('momo_requests')->where('id', $req->id)->update(['status' => $status, 'error' => mb_substr((string) ($data['processor_response'] ?? 'Declined on the phone.'), 0, 500),
                    'updated_at' => now()]);
            }

            return DB::table('momo_requests')->find($req->id);
        });
    }

    /** Ask about every collection still pending; one left unanswered for a day expires. @return int settled or closed */
    public function poll(?int $companyId = null, int $minAgeSeconds = 5): int
    {
        $n = 0;
        $expireBefore = now()->subHours((int) config('sacco.collections.expire_hours', 24));
        DB::table('momo_requests')->whereNotNull('purpose')->where('status', 'pending')->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('created_at', '<=', now()->subSeconds($minAgeSeconds))->orderBy('id')->select(['id', 'tx_ref', 'created_at'])
            ->chunkById(200, function ($rows) use (&$n, $expireBefore) {
                foreach ($rows as $row) {
                    try {
                        $after = $this->settle((string) $row->tx_ref);
                    } catch (BusinessRuleException) {
                        continue; // collections switched off since: the row waits
                    }
                    if ($after !== null && $after->status === 'pending' && \Illuminate\Support\Carbon::parse($row->created_at)->lt($expireBefore)) {
                        DB::table('momo_requests')->where('id', $row->id)->where('status', 'pending')->update(['status' => 'expired', 'error' => 'No answer from the phone within a day.', 'updated_at' => now()]);
                        $n++;
                    } elseif ($after !== null && $after->status !== 'pending') {
                        $n++;
                    }
                }
            });

        return $n;
    }

    /** A provider's amount (JSON number or string, major units) in whole minor units, or null if it is not one. */
    public static function providerMinor(mixed $amount, string $currency): ?int
    {
        if (is_int($amount)) {
            return $amount * Currency::factor($currency);
        }
        if (is_float($amount)) {
            // The provider's JSON decoded as a float: fixed to the currency's decimals at this boundary only.
            $amount = number_format($amount, Currency::exponent($currency), '.', '');
        }
        if (! is_string($amount) || $amount === '') {
            return null;
        }
        try {
            return Amount::fromMajor($amount, $currency)->minor;
        } catch (BusinessRuleException) {
            return null;
        }
    }

    /** @return array<string, mixed> the API's view of a collection */
    public static function present(object $r): array
    {
        return ['id' => (int) $r->id, 'uuid' => $r->uuid, 'purpose' => $r->purpose, 'status' => $r->status, 'member_id' => $r->sacco_member_id === null ? null : (int) $r->sacco_member_id,
            'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id === null ? null : (int) $r->subject_id, 'amount_minor' => (int) $r->amount_minor, 'currency' => $r->currency,
            'phone' => $r->phone, 'network' => $r->network, 'tx_ref' => $r->tx_ref, 'requested_via' => $r->requested_via, 'transaction_id' => $r->sacco_transaction_id === null ? null : (int) $r->sacco_transaction_id,
            'error' => $r->error, 'checks' => (int) $r->checks, 'created_at' => $r->created_at === null ? null : \Illuminate\Support\Carbon::parse($r->created_at)->toIso8601String(),
            'settled_at' => $r->settled_at === null ? null : \Illuminate\Support\Carbon::parse($r->settled_at)->toIso8601String()];
    }

    /**
     * What the money is for, checked: [subject_type, subject_id, member].
     *
     * @param  array<string, mixed>  $d
     * @return array{0: string, 1: int, 2: SaccoMember}
     */
    private function subject(int $cid, string $purpose, array $d, ?SaccoMember $asMember): array
    {
        if ($purpose === 'sacco_deposit') {
            $a = SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $cid)->find((int) ($d['savings_account_id'] ?? 0));
            if ($a === null || ($asMember !== null && (int) $a->member_id !== (int) $asMember->id)) {
                throw BusinessRuleException::make('account_not_found', 'Choose one of the member\'s savings accounts.');
            }
            if ($a->status === 'closed') {
                throw BusinessRuleException::make('account_closed', "{$a->account_no} is closed.");
            }
            $subject = ['sacco_savings_accounts', (int) $a->id, (int) $a->member_id];
        } elseif ($purpose === 'sacco_repayment') {
            $l = SaccoLoan::withoutGlobalScopes()->where('company_id', $cid)->find((int) ($d['loan_id'] ?? 0));
            if ($l === null || ($asMember !== null && (int) $l->member_id !== (int) $asMember->id)) {
                throw BusinessRuleException::make('loan_not_found', 'Choose one of the member\'s loans.');
            }
            if ($l->status !== 'active') {
                throw BusinessRuleException::make('loan_closed', "A loan that is {$l->status} takes no repayment.");
            }
            $subject = ['sacco_loans', (int) $l->id, (int) $l->member_id];
        } else {
            $memberId = $asMember !== null ? (int) $asMember->id : (int) ($d['member_id'] ?? 0);
            $subject = ['sacco_members', $memberId, $memberId];
        }
        $member = (new SaccoTransactions())->payingMember($cid, $subject[2]);
        if ($asMember !== null && (int) $member->id !== (int) $asMember->id) {
            throw BusinessRuleException::make('member_not_found', 'That member is not you.');
        }

        return [$subject[0], $subject[1], $member];
    }

    /** The receipt a confirmed collection becomes (or, when its account cannot take it, a held receipt in 2150). */
    private function receipt(Company $company, object $req, string $providerId): SaccoTransaction
    {
        $cid = (int) $company->id;
        $uuid = SyncSequence::childUuid('momo', (string) $req->tx_ref);
        $hit = SaccoTransaction::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first();
        if ($hit !== null) {
            return $hit;
        }
        $kind = self::PURPOSES[$req->purpose];
        $base = ['uuid' => $uuid, 'kind' => $kind, 'channel' => 'momo', 'member_id' => (int) $req->sacco_member_id, 'amount_minor' => (int) $req->amount_minor,
            'reference' => mb_substr($providerId !== '' ? $providerId : (string) $req->tx_ref, 0, 80), 'narration' => 'Mobile money '.(string) $req->tx_ref];
        $opts = ['via' => 'momo'];
        try {
            return DB::transaction(function () use ($company, $req, $kind, $base, $opts) {
                if ($kind === 'loan_repayment') {
                    $rep = (new Repayments())->record($company, (int) $req->subject_id, $base, null, $opts);

                    return SaccoTransaction::withoutGlobalScopes()->findOrFail($rep->transaction_id);
                }

                return app(SaccoTransactions::class)->record($company, $base + ($kind === 'deposit' ? ['savings_account_id' => (int) $req->subject_id] : []), null, $opts)['transaction'];
            });
        } catch (BusinessRuleException $e) {
            return $this->hold($cid, $base, $e->errorCode());
        }
    }

    /** @param array<string, mixed> $base */
    private function hold(int $cid, array $base, string $why): SaccoTransaction
    {
        SaccoTransactions::lockHead($cid);
        $stx = new SaccoTransactions();
        $settings = SaccoSetting::for($cid) ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
        $reason = in_array($why, SaccoTransactions::HOLD_REASONS, true) ? $why : 'unknown_account';
        $tx = $stx->document($cid, $base + ['value_date' => LocalDate::today($cid)->toDateString()], null, [], 'held', $reason);
        $draft = (new PostingRules($cid, (string) $settings->mode))->heldReceipt('txn:'.$tx->uuid, $stx->amount($cid, (int) $tx->amount_minor), (int) $tx->member_id, 'momo');

        return $stx->post($tx, $draft, null, [], 'held');
    }
}
