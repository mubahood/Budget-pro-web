<?php

namespace App\Services\Sacco\Savings;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSavingsProduct;
use App\Models\User;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoAudit;
use App\Services\Shop\NumberSequencer;
use App\Support\LocalDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Members' savings accounts (docs/akiba/04-architecture.md §3.3, §4): open, freeze, unfreeze, close, mark dormant,
 * and the available balance. The balance itself moves only through the books (SaccoTransactions → PostingService).
 *
 * Available = balance − held (liens) − the product's minimum balance − withdrawals still waiting for approval. It is
 * computed when asked, never stored; under a posting it is read with locking reads, so two withdrawals racing for the
 * same money see each other (ConcurrentWithdrawalsTest).
 */
final class SavingsAccounts
{
    /** Members who may hold an account. */
    public const MEMBER_STATUSES = ['applicant', 'active', 'dormant'];

    /** @param  array{opened_on?: ?string, uuid?: ?string}  $opts */
    public function open(Company $company, int $memberId, int $productId, ?User $by, array $opts = []): SaccoSavingsAccount
    {
        $cid = (int) $company->id;
        if ($by !== null) {
            SaccoAccess::authorize($by, 'sacco_teller');
        }

        return DB::transaction(function () use ($cid, $memberId, $productId, $by, $opts) {
            $member = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->lockForUpdate()->find($memberId)
                ?? throw BusinessRuleException::make('member_not_found', 'That member is not in this SACCO.');
            if (! in_array($member->status, self::MEMBER_STATUSES, true)) {
                throw BusinessRuleException::make('member_not_active', "A member who is {$member->status} cannot open an account.");
            }
            $product = SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $cid)->find($productId)
                ?? throw BusinessRuleException::make('product_not_found', 'That savings product is not in this SACCO.');
            if (! $product->is_active) {
                throw BusinessRuleException::make('product_inactive', "{$product->name} is switched off.");
            }
            if ($product->kind !== 'fixed' && SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $cid)->where('member_id', $member->id)
                ->where('product_id', $product->id)->where('status', '!=', 'closed')->exists()) {
                throw BusinessRuleException::make('account_exists', "{$member->full_name} already has a {$product->name} account.");
            }
            $opened = Carbon::parse($opts['opened_on'] ?? LocalDate::today($cid)->toDateString())->startOfDay();
            if ($opened->gt(LocalDate::today($cid))) {
                throw BusinessRuleException::make('invalid_date', 'An account cannot be opened in the future.');
            }
            $account = SaccoSavingsAccount::create(['company_id' => $cid, 'account_no' => NumberSequencer::next($cid, 'sav'), 'member_id' => $member->id,
                'product_id' => $product->id, 'ledger_account_id' => $product->ledger_account_id, 'status' => 'active', 'opened_on' => $opened->toDateString(),
                'maturity_on' => $product->kind === 'fixed' ? $opened->copy()->addMonthsNoOverflow((int) $product->term_months)->toDateString() : null,
                'uuid' => isset($opts['uuid']) && \Illuminate\Support\Str::isUuid($opts['uuid']) ? strtolower($opts['uuid']) : null,
                'created_by_id' => $by?->id, 'updated_by_id' => $by?->id]);

            return $account->fresh();
        });
    }

    /** No money leaves a frozen account (deposits still come in). */
    public function freeze(Company $company, int $accountId, string $reason, User $by): SaccoSavingsAccount
    {
        if (blank($reason)) {
            throw BusinessRuleException::make('reason_required', 'Say why the account is frozen: the member is told.');
        }

        return $this->move($company, $accountId, $by, ['active', 'dormant'], ['status' => 'frozen', 'frozen_reason' => mb_substr(trim($reason), 0, 255)], 'sacco.savings.frozen');
    }

    public function unfreeze(Company $company, int $accountId, User $by): SaccoSavingsAccount
    {
        return $this->move($company, $accountId, $by, ['frozen'], ['status' => 'active', 'frozen_reason' => null, 'dormant_since' => null], 'sacco.savings.unfrozen');
    }

    /** Close an empty account: nothing in it, nothing held, nothing waiting. */
    public function close(Company $company, int $accountId, string $reason, User $by): SaccoSavingsAccount
    {
        if (blank($reason)) {
            throw BusinessRuleException::make('reason_required', 'Say why the account is closed.');
        }

        return DB::transaction(function () use ($company, $accountId, $reason, $by) {
            $a = $this->find((int) $company->id, $accountId, true);
            if ((int) $a->balance_minor !== 0 || (int) $a->held_minor !== 0) {
                throw BusinessRuleException::make('account_not_empty', 'Pay out or transfer the balance first: only an empty account closes.');
            }
            if (self::pendingOut((int) $company->id, (int) $a->id) > 0) {
                throw BusinessRuleException::make('account_not_empty', 'A withdrawal from this account still waits for approval.');
            }

            return $this->move($company, $accountId, $by, ['active', 'dormant', 'frozen'], ['status' => 'closed', 'closed_on' => LocalDate::today((int) $company->id)->toDateString(),
                'closed_reason' => mb_substr(trim($reason), 0, 255)], 'sacco.savings.closed');
        });
    }

    /** An account left untouched for the SACCO's dormancy months (the P4 dormancy run, or an officer). */
    public function markDormant(Company $company, int $accountId, ?User $by): SaccoSavingsAccount
    {
        return $this->move($company, $accountId, $by, ['active'], ['status' => 'dormant', 'dormant_since' => LocalDate::today((int) $company->id)->toDateString()], 'sacco.savings.dormant');
    }

    /**
     * What can be withdrawn now, in minor units (never below zero). With $lock the account row and the waiting
     * withdrawals are read with locking reads (call it inside the posting's transaction).
     */
    public static function available(int $companyId, int $accountId, bool $lock = false, ?int $exceptTransactionId = null): int
    {
        $q = DB::table('sacco_savings_accounts as a')->join('sacco_savings_products as p', 'p.id', '=', 'a.product_id')
            ->where('a.company_id', $companyId)->where('a.id', $accountId)->select('a.balance_minor', 'a.held_minor', 'p.min_balance_minor');
        $row = ($lock ? $q->lockForUpdate() : $q)->first();
        if ($row === null) {
            return 0;
        }

        return max(0, (int) $row->balance_minor - (int) $row->held_minor - (int) $row->min_balance_minor - self::pendingOut($companyId, $accountId, $lock, $exceptTransactionId));
    }

    /** Withdrawals and transfers out of an account still waiting for a decision (amount plus fee). */
    public static function pendingOut(int $companyId, int $accountId, bool $lock = false, ?int $exceptTransactionId = null): int
    {
        $q = DB::table('sacco_transactions as t')->leftJoin('sacco_approvals as ap', 'ap.id', '=', 't.approval_id')
            ->where('t.company_id', $companyId)->where('t.savings_account_id', $accountId)->where('t.status', 'pending_approval')
            ->whereIn('t.kind', ['withdrawal', 'internal_transfer'])
            ->where(fn ($w) => $w->whereNull('ap.id')->orWhere(fn ($x) => $x->whereIn('ap.status', ['pending', 'approved'])->where('ap.expires_at', '>=', now())))
            ->when($exceptTransactionId !== null, fn ($w) => $w->where('t.id', '!=', $exceptTransactionId));
        $rows = ($lock ? $q->lockForUpdate() : $q)->get(['t.amount_minor', 't.fee_minor']);

        return (int) $rows->sum(fn ($r) => (int) $r->amount_minor + (int) $r->fee_minor);
    }

    public function find(int $companyId, int $accountId, bool $lock = false): SaccoSavingsAccount
    {
        $q = SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $companyId);

        return ($lock ? $q->lockForUpdate() : $q)->find($accountId)
            ?? throw BusinessRuleException::make('account_not_found', 'That savings account is not in this SACCO.');
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $changes
     */
    private function move(Company $company, int $accountId, ?User $by, array $from, array $changes, string $audit): SaccoSavingsAccount
    {
        if ($by !== null) {
            SaccoAccess::authorize($by, 'sacco_approve');
        }

        return DB::transaction(function () use ($company, $accountId, $by, $from, $changes, $audit) {
            $a = $this->find((int) $company->id, $accountId, true);
            if (! in_array($a->status, $from, true)) {
                throw BusinessRuleException::make($a->status === 'closed' ? 'account_closed' : 'invalid_status_change', "This account is {$a->status}.");
            }
            $a->fill($changes + ['updated_by_id' => $by?->id])->save();
            SaccoAudit::record($audit, $a, (int) $company->id, ['account_no' => $a->account_no, 'status' => $a->status], $by?->id);

            return $a;
        });
    }
}
