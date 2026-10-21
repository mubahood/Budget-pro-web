<?php

namespace App\Services\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoLedgerHead;
use App\Models\SaccoMember;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSavingsProduct;
use App\Models\SaccoSetting;
use App\Models\SaccoTransaction;
use App\Models\User;
use App\Services\Sacco\Ledger\JournalDraft;
use App\Services\Sacco\Ledger\PostingRules;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Ledger\ReversalService;
use App\Services\Sacco\Ledger\SubRef;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Services\Shop\NumberSequencer;
use App\Support\LocalDate;
use App\Support\Rules\SaccoRules;
use App\Support\Sacco\Amount;
use App\Support\Sync\SyncSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The member-facing money documents (docs/akiba/04-architecture.md §4, §5): record() writes a sacco_transactions row
 * and posts it through PostingRules and PostingService in one transaction, holds it (an offline cash receipt that
 * cannot reach its account, AK-24), or sends it to approval (a withdrawal above the threshold, from a dormant account,
 * with notice, or for a reason another module registers). reverse() asks for a `reversal` approval; its execution
 * posts the mirror entry and a `reversal` document beside the original (reversed once: `reverses_id` unique).
 *
 * Every call is idempotent on the document's uuid (the API body `uuid`, the phone's op uuid): the same uuid and
 * the same facts return the first document (`replayed`), other facts are `idempotency_conflict`. The posting key is
 * `txn:{uuid}`. Each call takes the SACCO's ledger head lock first, then the account rows, the order PostingService
 * itself uses, so concurrent withdrawals never deadlock and never overdraw (ConcurrentWithdrawalsTest).
 *
 * Explicit dates: `value_date` (data) is when the money moved, never after the posting day; `entry_date` (opts, for
 * opening balances and the demo's history) posts into that day's month, which must still be open.
 */
final class SaccoTransactions
{
    /** Kinds a teller records with record() (withdrawals need sacco_withdraw; the loan kinds are P2's). */
    public const RECORD_KINDS = ['deposit', 'withdrawal', 'fee', 'internal_transfer', 'share_purchase', 'entrance_fee'];

    /** The permission each kind needs from the officer who records it. */
    public const PERMISSION = ['deposit' => 'sacco_teller', 'fee' => 'sacco_teller', 'internal_transfer' => 'sacco_teller', 'share_purchase' => 'sacco_teller',
        'entrance_fee' => 'sacco_teller', 'withdrawal' => 'sacco_withdraw'];

    /** Why an offline receipt may be held (sacco_transactions.hold_reason). */
    public const HOLD_REASONS = ['unknown_account', 'account_closed', 'over_offline_max', 'loan_closed', 'not_whole_shares', 'share_cap_exceeded', 'entrance_fee_paid',
        'wrong_entrance_fee', 'no_entrance_fee', 'member_not_active', 'not_own_account', 'account_not_found', 'member_not_found'];

    /** Members whose money the SACCO still takes in. */
    public const PAYING_STATUSES = ['applicant', 'active', 'dormant', 'suspended'];

    /** @var array<string, callable(int, SaccoSavingsAccount, int): ?string> why a withdrawal needs approval (P2: arrears) */
    private static array $approvalChecks = [];

    /** @var array<string, array<string, callable(SaccoTransaction, SaccoTransaction): void>> per kind: what else a reversal undoes (shares count) */
    private static array $reversalHooks = [];

    /** @var array<string, callable(Company, array<string, mixed>, ?User, array<string, mixed>, SaccoSetting): SaccoTransaction> kinds another part posts (shares, P1.11) */
    private static array $recorders = [];

    /** @var array<string, callable(SaccoTransaction): void> after a document is posted (receipts, P1.17) */
    private static array $afterPosted = [];

    /** @param  callable(int, SaccoSavingsAccount, int): ?string  $check  a reason (shown to the checker) or null */
    public static function approvalCheck(string $key, callable $check): void
    {
        self::$approvalChecks[$key] = $check;
    }

    /** @param  callable(SaccoTransaction, SaccoTransaction): void  $hook  (original, reversal) inside the reversal's transaction */
    public static function onReverse(string $kind, string $key, callable $hook): void
    {
        self::$reversalHooks[$kind][$key] = $hook;
    }

    /**
     * A kind whose rules live with its own sub-ledger (shares). It runs inside record()'s transaction, after the head
     * lock, and writes its document with document() and posts it with post().
     *
     * @param  callable(Company, array<string, mixed>, ?User, array<string, mixed>, SaccoSetting): SaccoTransaction  $recorder
     */
    public static function recorder(string $kind, callable $recorder): void
    {
        self::$recorders[$kind] = $recorder;
    }

    /** @var array<string, callable(int, SubRef, int): void> per sub-ledger type: what an allocation of a held receipt also does (shares: the count) */
    private static array $allocationHooks = [];

    /** @param  callable(int, SubRef, int): void  $hook  (company, target, amount) inside the allocation's transaction */
    public static function onAllocate(string $type, callable $hook): void
    {
        self::$allocationHooks[$type] = $hook;
    }

    /** Run the target's allocation hook (HeldReceiptAllocationExecutor, before it posts). */
    public static function allocating(int $companyId, SubRef $target, int $amount): void
    {
        if (isset(self::$allocationHooks[$target->type])) {
            (self::$allocationHooks[$target->type])($companyId, $target, $amount);
        }
    }

    /** @param  callable(SaccoTransaction): void  $hook */
    public static function afterPosted(string $key, callable $hook): void
    {
        self::$afterPosted[$key] = $hook;
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'uuid' => ['nullable', 'uuid'],
            'kind' => ['required', Rule::in(self::RECORD_KINDS)],
            'channel' => ['nullable', Rule::in(['cash', 'bank', 'internal'])],
            'member_id' => ['nullable', 'integer'],
            'savings_account_id' => ['nullable', 'integer'],
            'share_account_id' => ['nullable', 'integer'],
            'counter_account_id' => ['nullable', 'integer'],
            'amount_minor' => SaccoRules::minor(true, 1),
            'value_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:80'],
            'narration' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Record a deposit, withdrawal, fee, internal transfer, share purchase or entrance fee.
     *
     * @param  array<string, mixed>  $data  rules()
     * @param  array{entry_date?: ?string, offline?: bool, device_id?: ?string, device_receipt_no?: ?string, client_created_at?: ?int}  $opts
     * @return array{transaction: SaccoTransaction, replayed: bool}
     */
    public function record(Company $company, array $data, ?User $by, array $opts = []): array
    {
        $cid = (int) $company->id;
        $v = validator($data, self::rules());
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_transaction', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }
        $d = $v->validated();
        $kind = (string) $d['kind'];
        if ($by !== null) {
            SaccoAccess::authorize($by, self::PERMISSION[$kind]);
        } elseif ($kind === 'withdrawal') {
            throw BusinessRuleException::make('forbidden', 'An officer must record a withdrawal.', ['permission' => 'sacco_withdraw']);
        }
        $d['uuid'] = strtolower((string) ($d['uuid'] ?? Str::uuid()));
        if (($hit = $this->replay($cid, $d)) !== null) {
            return ['transaction' => $hit, 'replayed' => true];
        }
        $settings = SaccoSetting::for($cid) ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
        $today = LocalDate::today($cid);
        $entryDate = isset($opts['entry_date']) && $opts['entry_date'] !== null ? Carbon::parse($opts['entry_date'])->startOfDay() : null;
        if ($entryDate !== null && $entryDate->gt($today)) {
            throw BusinessRuleException::make('invalid_date', 'Nothing is posted in the future.');
        }
        $valueDate = isset($d['value_date']) ? Carbon::parse($d['value_date'])->startOfDay() : ($entryDate ?? $today);
        if ($valueDate->gt($entryDate ?? $today)) {
            throw BusinessRuleException::make('invalid_value_date', 'The money cannot move after the day it is posted: the value date must be on or before today.');
        }
        $d['value_date'] = $valueDate->toDateString();
        $opts['entry_date'] = $entryDate?->toDateString();

        try {
            $tx = DB::transaction(function () use ($company, $cid, $d, $by, $opts, $settings, $kind) {
                self::lockHead($cid);

                return match ($kind) {
                    'deposit' => $this->deposit($company, $d, $by, $opts, $settings),
                    'withdrawal' => $this->withdrawal($company, $d, $by ?? throw BusinessRuleException::make('forbidden', 'An officer must record a withdrawal.'), $opts, $settings),
                    'fee' => $this->fee($company, $d, $by, $opts, $settings),
                    'internal_transfer' => $this->transfer($company, $d, $by, $opts, $settings),
                    default => isset(self::$recorders[$kind])
                        ? (self::$recorders[$kind])($company, $d, $by, $opts, $settings)
                        : throw BusinessRuleException::make('kind_not_available', 'The books cannot record '.str_replace('_', ' ', $kind).'s yet.'),
                };
            });
        } catch (QueryException $e) {
            if (($hit = $this->replay($cid, $d)) !== null) {
                return ['transaction' => $hit, 'replayed' => true]; // a twin with the same uuid committed first
            }
            throw $e;
        }

        return ['transaction' => $tx, 'replayed' => false];
    }

    /**
     * An approved withdrawal (or transfer) waiting in `pending_approval`, posted now (WithdrawalExecutor). The guard
     * re-checks the available balance under the lock; if the money is no longer there it throws and nothing is
     * written, and the approval stays approved with the reason.
     */
    public function postApproved(SaccoApproval $approval, User $checker): SaccoTransaction
    {
        $cid = (int) $approval->company_id;
        self::lockHead($cid);
        $tx = SaccoTransaction::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->find((int) ($approval->payload['transaction_id'] ?? 0))
            ?? throw BusinessRuleException::make('transaction_not_found', 'That withdrawal is not in this SACCO.');
        if ($tx->status === 'posted') {
            return $tx;
        }
        if ($tx->status !== 'pending_approval') {
            throw BusinessRuleException::make('transaction_closed', "This withdrawal is {$tx->status}.");
        }
        $settings = SaccoSetting::for($cid) ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
        $account = $this->account($cid, (int) $tx->savings_account_id, true);
        $product = SaccoSavingsProduct::withoutGlobalScopes()->findOrFail($account->product_id);
        $this->assertCanPayOut($account, $product, LocalDate::today($cid));
        if ((int) $product->notice_days > 0) {
            $ready = LocalDate::date($cid, $tx->created_at)->addDays((int) $product->notice_days);
            if ($ready->gt(LocalDate::today($cid))) {
                throw BusinessRuleException::make('notice_period', "{$product->name} asks {$product->notice_days} days' notice: this withdrawal can be paid from ".$ready->toDateString().'.',
                    ['from' => $ready->toDateString()]);
            }
        }
        $tx->forceFill(['approval_id' => $approval->id])->save();
        $draft = $tx->kind === 'internal_transfer'
            ? $this->transferDraft($cid, $settings, $tx, $account, $this->account($cid, (int) $tx->counter_account_id, true))
            : $this->withdrawalDraft($cid, $settings, $tx, $account, $product);
        $this->guardAvailable($draft, $cid, $account, (int) $tx->amount_minor + (int) $tx->fee_minor, (int) $tx->id, $product);
        $draft->approval((int) $approval->id);

        return $this->post($tx, $draft, $tx->received_by_id === null ? null : (int) $tx->received_by_id, ['entry_date' => null]);
    }

    /** A rejected, cancelled or expired approval closes its waiting document (SaccoApprovals → ClosesSubject). */
    public static function approvalClosed(SaccoApproval $approval, string $status): void
    {
        $tx = SaccoTransaction::withoutGlobalScopes()->where('company_id', $approval->company_id)->where('approval_id', $approval->id)->where('status', 'pending_approval')->first();
        $tx?->forceFill(['status' => $status === 'rejected' ? 'rejected' : 'cancelled', 'reason' => $tx->reason ?? mb_substr($status === 'expired' ? 'The approval expired.' : (string) $approval->decision_note, 0, 255)])->save();
    }

    /** Ask a second officer to reverse a posted document (04 §7 `reversal`). */
    public function reverse(Company $company, int $transactionId, string $reason, User $by, ?string $uuid = null): SaccoApproval
    {
        return app(SaccoApprovals::class)->request($company, 'reversal', ['transaction_id' => $transactionId, 'reason' => $reason], $by, $uuid);
    }

    /**
     * Check a reversal request (ReversalExecutor::prepare).
     *
     * @param  array<string, mixed>  $payload
     * @return array{payload: array<string, mixed>, subject_type: string, subject_id: int, amount_minor: int}
     */
    public static function prepareReversal(int $companyId, array $payload): array
    {
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            throw BusinessRuleException::make('reason_required', 'Say why the receipt is reversed.');
        }
        $tx = SaccoTransaction::withoutGlobalScopes()->where('company_id', $companyId)->find((int) ($payload['transaction_id'] ?? 0))
            ?? throw BusinessRuleException::make('transaction_not_found', 'That receipt is not in this SACCO.');
        if ($tx->kind === 'reversal') {
            throw BusinessRuleException::make('reversal_of_reversal', "{$tx->receipt_no} is itself a reversal: record the right receipt instead.");
        }
        if ($tx->status !== 'posted' || $tx->journal_entry_id === null) {
            throw BusinessRuleException::make('not_posted', "Only a posted receipt is reversed; this one is {$tx->status}.");
        }
        if (SaccoTransaction::withoutGlobalScopes()->where('reverses_id', $tx->id)->exists()) {
            throw BusinessRuleException::make('already_reversed', "{$tx->receipt_no} is already reversed.");
        }

        return ['payload' => ['transaction_id' => (int) $tx->id, 'receipt_no' => $tx->receipt_no, 'kind' => $tx->kind, 'member_id' => $tx->member_id,
            'amount_minor' => (int) $tx->amount_minor, 'fee_minor' => (int) $tx->fee_minor, 'entry_id' => (int) $tx->journal_entry_id, 'reason' => mb_substr($reason, 0, 200)],
            'subject_type' => 'sacco_transactions', 'subject_id' => (int) $tx->id, 'amount_minor' => (int) $tx->amount_minor + (int) $tx->fee_minor];
    }

    /** Post the approved reversal: the mirror entry and the `reversal` document (ReversalExecutor::execute). */
    public function executeReversal(SaccoApproval $approval, User $checker): SaccoTransaction
    {
        $cid = (int) $approval->company_id;
        $p = $approval->payload;
        self::lockHead($cid);
        $tx = SaccoTransaction::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->find((int) $p['transaction_id'])
            ?? throw BusinessRuleException::make('transaction_not_found', 'That receipt is not in this SACCO.');
        $existing = SaccoTransaction::withoutGlobalScopes()->where('company_id', $cid)->where('reverses_id', $tx->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        $maker = $approval->requested_by_id === null ? null : (int) $approval->requested_by_id;
        $entry = (new ReversalService())->reverse($cid, (int) $tx->journal_entry_id, (string) $p['reason'], (int) $approval->id, $maker);
        $reversal = SaccoTransaction::create(['company_id' => $cid, 'uuid' => SyncSequence::childUuid('rev', (string) $tx->uuid), 'receipt_no' => NumberSequencer::next($cid, 'rct'),
            'kind' => 'reversal', 'channel' => $tx->channel, 'member_id' => $tx->member_id, 'savings_account_id' => $tx->savings_account_id, 'share_account_id' => $tx->share_account_id,
            'loan_id' => $tx->loan_id, 'counter_account_id' => $tx->counter_account_id, 'shares_count' => $tx->shares_count, 'amount_minor' => $tx->amount_minor, 'fee_minor' => $tx->fee_minor,
            'currency' => $tx->currency, 'value_date' => LocalDate::today($cid)->toDateString(), 'status' => 'posted', 'journal_entry_id' => $entry->id, 'reverses_id' => $tx->id,
            'reason' => mb_substr((string) $p['reason'], 0, 255), 'approval_id' => $approval->id, 'received_by_id' => $maker, 'narration' => 'Reversal of '.$tx->receipt_no]);
        foreach (self::$reversalHooks[$tx->kind] ?? [] as $hook) {
            $hook($tx, $reversal);
        }
        $this->posted($reversal);

        return $reversal;
    }

    /**
     * A cash receipt pushed from a phone (SyncApplier `sacco_transaction`, 04 §9.1). It is never refused for where it
     * should go: an unknown or closed account, a member who has left, or an amount above the offline limit posts to
     * 2150 Unallocated receipts (`held`), with a conflict-inbox item; when the account is known, the system also asks
     * for its `held_receipt_allocation`. The phone names accounts and members by server id (`savings_account_id`,
     * `share_account_id`, `member_id`), or a member it registered offline by `member_uuid`.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $op
     * @return array{status: string, model?: SaccoTransaction, code?: string, message?: string}
     */
    public function applySynced(Company $company, array $data, User $by, ?string $deviceId, string $uuid, array $op = []): array
    {
        $cid = (int) $company->id;
        $uuid = strtolower($uuid);
        $existing = SaccoTransaction::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first();
        if ($existing !== null) {
            return ['status' => 'replayed', 'model' => $existing];
        }
        $amount = $data['amount_minor'] ?? null;
        if (! is_int($amount) || $amount <= 0) {
            return ['status' => 'rejected', 'code' => 'validation', 'message' => 'amount_minor must be a whole number of minor units above zero.'];
        }
        $kind = (string) ($data['kind'] ?? '');
        $settings = SaccoSetting::for($cid) ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
        $memberId = isset($data['member_id']) && is_numeric($data['member_id']) ? (int) $data['member_id'] : null;
        if ($memberId === null && ! empty($data['member_uuid'])) {
            $memberId = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', (string) $data['member_uuid'])->value('id');
        }
        $member = $memberId === null ? null : SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->find($memberId);
        $valueDate = isset($data['value_date']) && is_string($data['value_date']) ? Carbon::parse($data['value_date'])->startOfDay() : LocalDate::today($cid);
        if ($valueDate->gt(LocalDate::today($cid))) {
            $valueDate = LocalDate::today($cid);
        }
        $record = ['uuid' => $uuid, 'kind' => $kind, 'channel' => 'cash', 'member_id' => $member?->id, 'amount_minor' => $amount, 'value_date' => $valueDate->toDateString(),
            'reference' => isset($data['reference']) ? mb_substr((string) $data['reference'], 0, 80) : null, 'narration' => isset($data['narration']) ? mb_substr((string) $data['narration'], 0, 255) : null];
        $opts = ['offline' => true, 'device_id' => $deviceId, 'device_receipt_no' => isset($data['receipt_no']) ? mb_substr((string) $data['receipt_no'], 0, 40) : null,
            'client_created_at' => isset($op['client_created_at']) ? (int) $op['client_created_at'] : null];

        $hold = null;
        $target = null;
        if ($member === null || ! in_array($member->status, self::PAYING_STATUSES, true)) {
            $hold = 'unknown_account';
        }
        if ($kind === 'deposit') {
            $account = isset($data['savings_account_id']) && is_numeric($data['savings_account_id'])
                ? SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $cid)->find((int) $data['savings_account_id']) : null;
            if ($account === null || ($member !== null && (int) $account->member_id !== (int) $member->id)) {
                $hold ??= 'unknown_account';
            } elseif ($account->status === 'closed') {
                $hold ??= 'account_closed';
            } else {
                $record['savings_account_id'] = (int) $account->id;
                $target = new SubRef('savings', (int) $account->id, (int) $account->ledger_account_id, (int) $account->member_id);
            }
        } elseif ($kind === 'share_purchase' || $kind === 'entrance_fee' || $kind === 'fee') {
            if ($kind === 'share_purchase') {
                $record['share_account_id'] = isset($data['share_account_id']) && is_numeric($data['share_account_id']) ? (int) $data['share_account_id'] : null;
            }
            if ($hold === null && isset(self::$offlineChecks[$kind])) {
                [$hold, $target] = (self::$offlineChecks[$kind])($cid, $record, $member);
            }
        } else {
            return ['status' => 'rejected', 'code' => 'offline_not_allowed', 'message' => 'Needs the internet: this moves money out or approves it.'];
        }
        if ($hold === null && $amount > (int) $settings->offline_receipt_max_minor) {
            $hold = 'over_offline_max';
        }
        if ($hold === null) {
            try {
                $r = $this->record($company, $record, $by, $opts);

                return ['status' => $r['replayed'] ? 'replayed' : 'applied', 'model' => $r['transaction']];
            } catch (BusinessRuleException $e) {
                if (in_array($e->errorCode(), ['forbidden', 'idempotency_conflict', 'invalid_transaction', 'not_set_up'], true)) {
                    throw $e;
                }
                // The cash was taken: hold it rather than refuse it (AK-24), saying why.
                $hold = in_array($e->errorCode(), self::HOLD_REASONS, true) ? $e->errorCode() : 'unknown_account';
                $target = in_array($hold, ['share_cap_exceeded', 'not_whole_shares', 'entrance_fee_paid', 'wrong_entrance_fee', 'no_entrance_fee', 'member_not_active'], true) ? null : $target;
            }
        }

        $tx = DB::transaction(fn () => $this->hold($company, $record, $by, $opts, $settings, $hold, $target, $data));
        app(\App\Services\Sync\MasterDataService::class)->conflict($cid, $deviceId, 'sacco_transactions', $uuid, 'held_receipt', $data, ['receipt_no' => $tx->receipt_no, 'hold_reason' => $hold],
            'Receipt '.($tx->device_receipt_no ?: $tx->receipt_no).' is held: '.str_replace('_', ' ', $hold));

        return ['status' => 'applied', 'model' => $tx, 'code' => 'held'];
    }

    /** @var array<string, callable(int, array<string, mixed>, ?SaccoMember): array{0: ?string, 1: ?SubRef}> per offline kind: [hold reason, allocation target] */
    private static array $offlineChecks = [];

    /** @param  callable(int, array<string, mixed>, ?SaccoMember): array{0: ?string, 1: ?SubRef}  $check */
    public static function offlineCheck(string $kind, callable $check): void
    {
        self::$offlineChecks[$kind] = $check;
    }

    /**
     * A held receipt's allocation was posted (HeldReceiptAllocationExecutor): once nothing of it is left in 2150, the
     * document is posted and learns its account.
     */
    public static function allocated(int $companyId, int $heldEntryId, SubRef $target, int $unallocated): void
    {
        $tx = SaccoTransaction::withoutGlobalScopes()->where('company_id', $companyId)->where('journal_entry_id', $heldEntryId)->where('status', 'held')->lockForUpdate()->first();
        if ($tx === null || $unallocated > 0) {
            return;
        }
        $fill = ['status' => 'posted', 'hold_reason' => null];
        if ($tx->member_id === null && $target->memberId !== null) {
            $fill['member_id'] = $target->memberId;
        }
        if ($target->type === 'savings' && $tx->savings_account_id === null) {
            $fill['savings_account_id'] = $target->id;
        }
        if ($target->type === 'shares' && $tx->share_account_id === null) {
            $fill['share_account_id'] = $target->id;
        }
        $tx->forceFill($fill)->save();
    }

    /** The SACCO's posting lock: taken first by every money path (then account rows, ascending). */
    public static function lockHead(int $companyId): void
    {
        SaccoLedgerHead::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->first()
            ?? throw BusinessRuleException::make('ledger_not_set_up', 'This SACCO\'s books are not set up yet.');
    }

    // ---------------------------------------------------------------- kinds

    /** @param  array<string, mixed>  $d @param array<string, mixed> $opts */
    private function deposit(Company $company, array $d, ?User $by, array $opts, SaccoSetting $settings): SaccoTransaction
    {
        $cid = (int) $company->id;
        $account = $this->account($cid, (int) ($d['savings_account_id'] ?? 0), true);
        $member = $this->member($cid, $account, $d);
        if ($account->status === 'closed') {
            throw BusinessRuleException::make('account_closed', "{$account->account_no} is closed.");
        }
        $product = SaccoSavingsProduct::withoutGlobalScopes()->findOrFail($account->product_id);
        if (empty($opts['offline']) && (int) $product->min_opening_minor > (int) $d['amount_minor'] && ! $this->hasPostings($cid, (int) $account->id)) {
            throw BusinessRuleException::make('below_min_opening', "{$product->name} opens with at least ".Amount::of((int) $product->min_opening_minor, $settings->currency)->toMajorString().'.',
                ['min_opening_minor' => (int) $product->min_opening_minor]);
        }
        $d['member_id'] = $member->id;
        $d['channel'] ??= 'cash';
        $tx = $this->document($cid, $d, $by, $opts, 'posted');
        $draft = (new PostingRules($cid, $settings->mode))->deposit('txn:'.$tx->uuid, self::ref($account), $this->amount($cid, (int) $tx->amount_minor), $this->channel($tx));
        $tx = $this->post($tx, $draft, $by?->id, $opts);
        $account->fill(['last_activity_on' => $tx->value_date->toDateString()] + ($account->status === 'dormant' ? ['status' => 'active', 'dormant_since' => null] : []))->save();

        return $tx;
    }

    /** @param  array<string, mixed>  $d @param array<string, mixed> $opts */
    private function withdrawal(Company $company, array $d, User $by, array $opts, SaccoSetting $settings): SaccoTransaction
    {
        $cid = (int) $company->id;
        $account = $this->account($cid, (int) ($d['savings_account_id'] ?? 0), true);
        $member = $this->member($cid, $account, $d, true);
        $product = SaccoSavingsProduct::withoutGlobalScopes()->findOrFail($account->product_id);
        $this->assertCanPayOut($account, $product, Carbon::parse($d['value_date']));
        $amount = (int) $d['amount_minor'];
        $fee = $settings->isGroup() ? 0 : (int) $product->withdrawal_fee_minor;
        if ($amount + $fee > SavingsAccounts::available($cid, (int) $account->id, true)) {
            throw self::insufficient($cid, (int) $account->id);
        }
        $this->assertMonthlyLimit($cid, $account, $product, Carbon::parse($d['value_date']));
        $reasons = [];
        if ($amount > (int) $settings->withdrawal_approval_above_minor) {
            $reasons[] = 'above_threshold';
        }
        if ($account->status === 'dormant') {
            $reasons[] = 'dormant';
        }
        if ((int) $product->notice_days > 0) {
            $reasons[] = 'notice';
        }
        foreach (self::$approvalChecks as $check) {
            if (($reason = $check($cid, $account, $amount)) !== null) {
                $reasons[] = $reason;
            }
        }
        $d['member_id'] = $member->id;
        $d['channel'] ??= 'cash';
        $d['fee_minor'] = $fee;
        if ($reasons !== []) {
            $tx = $this->document($cid, $d, $by, $opts, 'pending_approval');
            $approval = app(SaccoApprovals::class)->request($company, 'withdrawal', ['transaction_id' => (int) $tx->id, 'reasons' => $reasons], $by,
                SyncSequence::childUuid('apr', (string) $tx->uuid));
            $tx->forceFill(['approval_id' => $approval->id])->save();

            return $tx;
        }
        $tx = $this->document($cid, $d, $by, $opts, 'posted');
        $draft = $this->withdrawalDraft($cid, $settings, $tx, $account, $product);
        $this->guardAvailable($draft, $cid, $account, $amount + $fee, (int) $tx->id, $product);
        $tx = $this->post($tx, $draft, $by->id, $opts);
        $account->fill(['last_activity_on' => $tx->value_date->toDateString()])->save();

        return $tx;
    }

    /** @param  array<string, mixed>  $d @param array<string, mixed> $opts */
    private function fee(Company $company, array $d, ?User $by, array $opts, SaccoSetting $settings): SaccoTransaction
    {
        $cid = (int) $company->id;
        $rules = new PostingRules($cid, $settings->mode);
        $amount = $this->amount($cid, (int) $d['amount_minor']);
        if (! empty($d['savings_account_id'])) {
            $account = $this->account($cid, (int) $d['savings_account_id'], true);
            $member = $this->member($cid, $account, $d, true);
            if (in_array($account->status, ['frozen', 'closed'], true)) {
                throw BusinessRuleException::make($account->status === 'closed' ? 'account_closed' : 'account_frozen', "{$account->account_no} is {$account->status}.");
            }
            $d += ['member_id' => $member->id];
            $d['channel'] = 'internal';
            $tx = $this->document($cid, $d, $by, $opts, 'posted');
            $draft = $rules->fee('txn:'.$tx->uuid, $amount, self::ref($account));
            $this->guardAvailable($draft, $cid, $account, (int) $d['amount_minor'], (int) $tx->id, null);
        } else {
            $member = $this->payingMember($cid, (int) ($d['member_id'] ?? 0));
            $d['member_id'] = $member->id;
            $d['channel'] ??= 'cash';
            $tx = $this->document($cid, $d, $by, $opts, 'posted');
            $draft = $rules->fee('txn:'.$tx->uuid, $amount, null, $this->channel($tx), (int) $member->id);
        }

        return $this->post($tx, $draft, $by?->id, $opts);
    }

    /** Between two of the member's own savings accounts. @param array<string, mixed> $d @param array<string, mixed> $opts */
    private function transfer(Company $company, array $d, ?User $by, array $opts, SaccoSetting $settings): SaccoTransaction
    {
        $cid = (int) $company->id;
        $ids = [(int) ($d['savings_account_id'] ?? 0), (int) ($d['counter_account_id'] ?? 0)];
        $sorted = $ids;
        sort($sorted);
        $locked = [];
        foreach ($sorted as $id) {
            $locked[$id] = $this->account($cid, $id, true);
        }
        [$from, $to] = [$locked[$ids[0]], $locked[$ids[1]]];
        if ((int) $from->member_id !== (int) $to->member_id) {
            throw BusinessRuleException::make('not_own_account', 'A transfer moves money between the same member\'s own accounts.');
        }
        if ($to->status === 'closed') {
            throw BusinessRuleException::make('account_closed', "{$to->account_no} is closed.");
        }
        $member = $this->member($cid, $from, $d, true);
        $product = SaccoSavingsProduct::withoutGlobalScopes()->findOrFail($from->product_id);
        $this->assertCanPayOut($from, $product, Carbon::parse($d['value_date']));
        if ((int) $d['amount_minor'] > SavingsAccounts::available($cid, (int) $from->id, true)) {
            throw self::insufficient($cid, (int) $from->id);
        }
        $d['member_id'] = $member->id;
        $d['channel'] = 'internal';
        $tx = $this->document($cid, $d, $by, $opts, 'posted');
        $draft = $this->transferDraft($cid, $settings, $tx, $from, $to);
        $this->guardAvailable($draft, $cid, $from, (int) $d['amount_minor'], (int) $tx->id, $product);

        return $this->post($tx, $draft, $by?->id, $opts);
    }

    /**
     * A receipt posted to 2150 (AK-24).
     *
     * @param  array<string, mixed>  $d
     * @param  array<string, mixed>  $opts
     * @param  array<string, mixed>  $phone
     */
    private function hold(Company $company, array $d, User $by, array $opts, SaccoSetting $settings, string $reason, ?SubRef $target, array $phone): SaccoTransaction
    {
        $cid = (int) $company->id;
        self::lockHead($cid);
        unset($d['savings_account_id'], $d['share_account_id']);
        $tx = $this->document($cid, $d, $by, $opts + ['meta' => ['phone' => array_intersect_key($phone, array_flip(['member_id', 'member_uuid', 'savings_account_id', 'share_account_id', 'kind', 'receipt_no']))]],
            'held', $reason);
        $draft = (new PostingRules($cid, $settings->mode))->heldReceipt('txn:'.$tx->uuid, $this->amount($cid, (int) $tx->amount_minor), $tx->member_id);
        $tx = $this->post($tx, $draft, (int) $by->id, $opts, 'held');
        if ($target !== null) {
            $approval = app(SaccoApprovals::class)->request($company, 'held_receipt_allocation', ['held_entry_id' => (int) $tx->journal_entry_id, 'amount_minor' => (int) $tx->amount_minor,
                'target' => ['type' => $target->type, 'id' => $target->id, 'account_id' => $target->accountId, 'member_id' => $target->memberId]], null, SyncSequence::childUuid('hold', (string) $tx->uuid));
            $tx->forceFill(['approval_id' => $approval->id])->save();
        }

        return $tx;
    }

    // ---------------------------------------------------------------- shared steps

    /**
     * The document row. A posted or held one gets its receipt number now; a waiting one when it posts.
     *
     * @param  array<string, mixed>  $d
     * @param  array<string, mixed>  $opts
     */
    public function document(int $companyId, array $d, ?User $by, array $opts, string $status, ?string $holdReason = null): SaccoTransaction
    {
        return SaccoTransaction::create(['company_id' => $companyId, 'uuid' => $d['uuid'], 'kind' => $d['kind'], 'channel' => $d['channel'] ?? 'cash',
            'receipt_no' => $status === 'pending_approval' ? null : NumberSequencer::next($companyId, 'rct', Carbon::parse($opts['entry_date'] ?? LocalDate::today($companyId))),
            'device_receipt_no' => $opts['device_receipt_no'] ?? null, 'member_id' => $d['member_id'] ?? null, 'savings_account_id' => $d['savings_account_id'] ?? null,
            'share_account_id' => $d['share_account_id'] ?? null, 'counter_account_id' => $d['counter_account_id'] ?? null, 'shares_count' => $d['shares_count'] ?? null,
            'amount_minor' => (int) $d['amount_minor'], 'fee_minor' => (int) ($d['fee_minor'] ?? 0), 'currency' => PostingService::currency($companyId),
            'value_date' => $d['value_date'], 'status' => $status, 'hold_reason' => $holdReason, 'reference' => $d['reference'] ?? null, 'narration' => $d['narration'] ?? null,
            'received_by_id' => $by?->id, 'meta' => $opts['meta'] ?? null, 'device_id' => $opts['device_id'] ?? null,
            'client_created_at' => $opts['client_created_at'] ?? null]);
    }

    /** Post the document's draft and mark it posted (or held). @param array<string, mixed> $opts */
    public function post(SaccoTransaction $tx, JournalDraft $draft, ?int $userId, array $opts, string $status = 'posted'): SaccoTransaction
    {
        $draft->source('sacco_transactions', (int) $tx->id)->by($userId)->dated($opts['entry_date'] ?? null, $tx->value_date->toDateString())
            ->memo(ucfirst(str_replace('_', ' ', $tx->kind)).' '.($tx->receipt_no ?? ''));
        $entry = (new PostingService())->post($draft);
        $fill = ['journal_entry_id' => (int) $entry->id];
        if ($tx->receipt_no === null) {
            $fill['receipt_no'] = NumberSequencer::next((int) $tx->company_id, 'rct');
        }
        if ($tx->status !== $status) {
            $fill['status'] = $status;
        }
        $tx->forceFill($fill)->save();
        if ($status === 'posted') {
            $this->posted($tx);
        }

        return $tx;
    }

    public static function ref(SaccoSavingsAccount $a): SubRef
    {
        return new SubRef('savings', (int) $a->id, (int) $a->ledger_account_id, (int) $a->member_id);
    }

    public function amount(int $companyId, int $minor): Amount
    {
        return Amount::of($minor, PostingService::currency($companyId));
    }

    private function posted(SaccoTransaction $tx): void
    {
        foreach (self::$afterPosted as $hook) {
            $hook($tx);
        }
    }

    private function channel(SaccoTransaction $tx): string
    {
        return $tx->channel === 'internal' ? 'cash' : (string) $tx->channel;
    }

    private function withdrawalDraft(int $cid, SaccoSetting $settings, SaccoTransaction $tx, SaccoSavingsAccount $account, SaccoSavingsProduct $product): JournalDraft
    {
        $rules = new PostingRules($cid, $settings->mode);
        $draft = $rules->withdrawal('txn:'.$tx->uuid, self::ref($account), $this->amount($cid, (int) $tx->amount_minor), $this->channel($tx));
        if ((int) $tx->fee_minor > 0) {
            foreach ($rules->fee('txn:'.$tx->uuid.':fee', $this->amount($cid, (int) $tx->fee_minor), self::ref($account))->lines as $line) {
                $draft->line($line);
            }
        }

        return $draft;
    }

    private function transferDraft(int $cid, SaccoSetting $settings, SaccoTransaction $tx, SaccoSavingsAccount $from, SaccoSavingsAccount $to): JournalDraft
    {
        return (new PostingRules($cid, $settings->mode))->internalTransfer('txn:'.$tx->uuid, self::ref($from), self::ref($to), $this->amount($cid, (int) $tx->amount_minor));
    }

    /** Re-check the money under the posting's locks (04 §5.2 step 3). */
    private function guardAvailable(JournalDraft $draft, int $cid, SaccoSavingsAccount $account, int $needed, int $exceptTx, ?SaccoSavingsProduct $product): void
    {
        $draft->guard(function () use ($cid, $account, $needed, $exceptTx, $product) {
            $available = $product === null
                ? max(0, (int) DB::table('sacco_savings_accounts')->where('id', $account->id)->lockForUpdate()->value('balance_minor') - (int) $account->held_minor)
                : SavingsAccounts::available($cid, (int) $account->id, true, $exceptTx);
            if ($needed > $available) {
                throw self::insufficient($cid, (int) $account->id, $available);
            }
        });
    }

    private function assertCanPayOut(SaccoSavingsAccount $account, SaccoSavingsProduct $product, \Carbon\CarbonInterface $on): void
    {
        if ($account->status === 'closed' || $account->status === 'frozen') {
            throw BusinessRuleException::make($account->status === 'closed' ? 'account_closed' : 'account_frozen', "{$account->account_no} is {$account->status}".
                ($account->frozen_reason ? ": {$account->frozen_reason}" : '').'.');
        }
        if (! $product->withdrawable) {
            throw BusinessRuleException::make('not_withdrawable', "{$product->name} cannot be withdrawn while the member stays a member.");
        }
        if ($account->maturity_on !== null && $account->maturity_on->gt($on)) {
            throw BusinessRuleException::make('not_matured', "{$account->account_no} matures on ".$account->maturity_on->toDateString().'.');
        }
    }

    private function assertMonthlyLimit(int $cid, SaccoSavingsAccount $account, SaccoSavingsProduct $product, \Carbon\CarbonInterface $on): void
    {
        if ($product->max_withdrawals_per_month === null) {
            return;
        }
        $n = SaccoTransaction::withoutGlobalScopes()->where('company_id', $cid)->where('savings_account_id', $account->id)->where('kind', 'withdrawal')
            ->whereIn('status', ['posted', 'pending_approval'])->whereBetween('value_date', [$on->copy()->startOfMonth()->toDateString(), $on->copy()->endOfMonth()->toDateString()])
            ->lockForUpdate()->count();
        if ($n >= (int) $product->max_withdrawals_per_month) {
            throw BusinessRuleException::make('withdrawal_limit', "{$product->name} allows {$product->max_withdrawals_per_month} withdrawals a month.");
        }
    }

    private static function insufficient(int $cid, int $accountId, ?int $available = null): BusinessRuleException
    {
        $available ??= SavingsAccounts::available($cid, $accountId, true);

        return BusinessRuleException::make('insufficient_available', 'Not enough available: '.Amount::of($available, PostingService::currency($cid))->toMajorString().' can be withdrawn now.',
            ['available_minor' => $available]);
    }

    public function account(int $cid, int $id, bool $lock = false): SaccoSavingsAccount
    {
        $q = SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $cid);

        return ($lock ? $q->lockForUpdate() : $q)->find($id) ?? throw BusinessRuleException::make('account_not_found', 'That savings account is not in this SACCO.');
    }

    /** @param  array<string, mixed>  $d */
    private function member(int $cid, SaccoSavingsAccount $account, array $d, bool $active = false): SaccoMember
    {
        if (! empty($d['member_id']) && (int) $d['member_id'] !== (int) $account->member_id) {
            throw BusinessRuleException::make('not_own_account', 'That account belongs to another member.');
        }
        $member = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($account->member_id);
        if (! in_array($member->status, $active ? ['active', 'dormant', 'suspended'] : self::PAYING_STATUSES, true)) {
            throw BusinessRuleException::make('member_not_active', "{$member->full_name} is {$member->status}.");
        }

        return $member;
    }

    public function payingMember(int $cid, int $memberId): SaccoMember
    {
        $member = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->find($memberId)
            ?? throw BusinessRuleException::make('member_not_found', 'That member is not in this SACCO.');
        if (! in_array($member->status, self::PAYING_STATUSES, true)) {
            throw BusinessRuleException::make('member_not_active', "{$member->full_name} is {$member->status}.");
        }

        return $member;
    }

    private function hasPostings(int $cid, int $accountId): bool
    {
        return SaccoTransaction::withoutGlobalScopes()->where('company_id', $cid)->where('savings_account_id', $accountId)->where('status', 'posted')->exists();
    }

    /** The same uuid again: the first document if the facts match, else `idempotency_conflict`. @param array<string, mixed> $d */
    private function replay(int $cid, array $d): ?SaccoTransaction
    {
        $hit = SaccoTransaction::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $d['uuid'])->first();
        if ($hit === null) {
            return null;
        }
        $same = $hit->kind === $d['kind'] && (int) $hit->amount_minor === (int) $d['amount_minor']
            && (empty($d['savings_account_id']) || (int) $hit->savings_account_id === (int) $d['savings_account_id'])
            && (empty($d['member_id']) || (int) $hit->member_id === (int) $d['member_id']);
        if (! $same) {
            throw BusinessRuleException::make('idempotency_conflict', 'That receipt was already recorded with other details.', ['transaction_id' => $hit->id]);
        }

        return $hit;
    }

    /** The entry a document posted (for its receipt). */
    public static function entryOf(SaccoTransaction $tx): ?SaccoJournalEntry
    {
        return $tx->journal_entry_id === null ? null : SaccoJournalEntry::withoutGlobalScopes()->find($tx->journal_entry_id);
    }
}
