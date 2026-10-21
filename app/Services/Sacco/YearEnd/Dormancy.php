<?php

namespace App\Services\Sacco\YearEnd;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoDormancyNotice;
use App\Models\SaccoSavingsAccount;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\JournalDraft;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoAudit;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Support\Rules\SaccoRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dormant and unclaimed savings (P4.10, AK-42; Tier 4 s.53, the stricter rule by default; Coop s.55AL differs). By the
 * months since the member last moved money (`last_activity_on`, else the day the account opened; interest and
 * dividends credited by the SACCO do not count):
 *
 *   - after the SACCO's dormancy months (24 by default): the account goes on the dormant register and the member is
 *     sent written notice (stages `register` and `notice`); a withdrawal from it then needs a second officer;
 *   - 12 months later (3 years by default): it is due to be advertised; an officer records the advertisement;
 *   - after 60 months (5 years): the balance is due to the regulator; a second officer approves the transfer
 *     (`dormant_transfer`), which posts Dr the account's 21x0 / Cr 2450 Unclaimed balances due to the regulator, key
 *     `dorm:{account_id}`. The member reclaims it from the regulator afterwards.
 *
 * No dormancy fee is ever charged. Any deposit makes the account active again (SaccoTransactions). For a licensed
 * SACCO the transfer needs the advertisement first; for any other SACCO the transfer is never required: what is due
 * shows on the dormant register as a warning, and a transfer without an advertisement is a warning too
 * (SaccoRules::strict). Office mode only.
 */
final class Dormancy
{
    public const ADVERTISE_AFTER_MONTHS = 12;

    public const TRANSFER_AFTER_MONTHS = 60;

    /**
     * Put accounts that have gone quiet on the register and tell their members (the month-end job).
     *
     * @return array{registered: int}
     */
    public function run(int $companyId, ?string $asOf = null): array
    {
        $settings = LoanBooks::settings($companyId);
        if ($settings->isGroup()) {
            return ['registered' => 0];
        }
        $today = $asOf !== null ? Carbon::parse($asOf)->startOfDay() : LoanBooks::today($companyId);
        $months = (int) ($settings->dormancy_months ?: 24);
        $cutoff = $today->copy()->subMonthsNoOverflow($months)->toDateString();
        $company = Company::withoutGlobalScopes()->findOrFail($companyId);
        $n = 0;
        foreach (SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'active')->where('balance_minor', '>', 0)
            ->whereRaw('COALESCE(last_activity_on, opened_on) <= ?', [$cutoff])->orderBy('id')->get() as $a) {
            DB::transaction(function () use ($company, $companyId, $a, $today, $months, &$n) {
                $acc = (new SavingsAccounts())->markDormant($company, (int) $a->id, null);
                foreach (['register', 'notice'] as $stage) {
                    SaccoDormancyNotice::create(['company_id' => $companyId, 'savings_account_id' => $acc->id, 'member_id' => $acc->member_id, 'stage' => $stage,
                        'on' => $today->toDateString(), 'balance_minor' => (int) $acc->balance_minor]);
                }
                YearEndNotices::toMember($companyId, (int) $acc->member_id, 'dormancy_notice', ['account' => (string) $acc->account_no, 'months' => $months]);
                $n++;
            });
        }

        return ['registered' => $n];
    }

    /**
     * The dormant register: every dormant account with its stage and what is due next.
     *
     * @return list<array<string, mixed>>
     */
    public function register(int $companyId, ?string $asOf = null): array
    {
        $today = $asOf !== null ? Carbon::parse($asOf)->startOfDay() : LoanBooks::today($companyId);
        $strict = SaccoRules::strict($companyId);
        $out = [];
        $accounts = SaccoSavingsAccount::withoutGlobalScopes()->with('member:id,member_no,first_name,last_name')->where('company_id', $companyId)->where('status', 'dormant')->orderBy('id')->get();
        $notices = SaccoDormancyNotice::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('savings_account_id', $accounts->pluck('id')->all())->orderBy('on')->get()->groupBy('savings_account_id');
        foreach ($accounts as $a) {
            $last = Carbon::parse($a->last_activity_on ?? $a->opened_on);
            $quiet = (int) $last->diffInMonths($today);
            $mine = ($notices[$a->id] ?? collect())->filter(fn ($n) => $a->dormant_since === null || $n->on->gte($a->dormant_since));
            $advertised = $mine->firstWhere('stage', 'advertised');
            $transferred = $mine->firstWhere('stage', 'transferred');
            $months = (int) (LoanBooks::settings($companyId)->dormancy_months ?: 24);
            $due = match (true) {
                $transferred !== null => null,
                $quiet >= self::TRANSFER_AFTER_MONTHS => 'transfer',
                $advertised === null && $quiet >= $months + self::ADVERTISE_AFTER_MONTHS => 'advertise',
                default => null,
            };
            $out[] = ['savings_account_id' => (int) $a->id, 'account_no' => (string) $a->account_no, 'member_id' => (int) $a->member_id,
                'member_no' => (string) $a->member?->member_no, 'member_name' => trim(($a->member->first_name ?? '').' '.($a->member->last_name ?? '')),
                'balance_minor' => (int) $a->balance_minor, 'last_activity_on' => $last->toDateString(), 'dormant_since' => $a->dormant_since?->toDateString(), 'months_quiet' => $quiet,
                'stage' => $transferred !== null ? 'transferred' : ($advertised !== null ? 'advertised' : 'register'), 'advertised_on' => $advertised?->on?->toDateString(),
                'advertised_reference' => $advertised?->reference, 'due' => $due,
                'warning' => ! $strict && $due === 'transfer' ? 'Unclaimed for five years: due to the regulator for a licensed SACCO.' : null];
        }

        return $out;
    }

    /** Record that dormant accounts were advertised (an officer with sacco_accounts). @param list<int> $accountIds @return int recorded */
    public function advertised(Company $company, array $accountIds, string $reference, string $on, User $by): int
    {
        SaccoAccess::authorize($by, 'sacco_accounts');
        $cid = (int) $company->id;
        if (blank($reference)) {
            throw BusinessRuleException::make('reference_required', 'Say where the accounts were advertised (the newspaper and its date, or the notice).');
        }
        $day = Carbon::parse($on)->startOfDay();
        if ($day->gt(LoanBooks::today($cid))) {
            throw BusinessRuleException::make('invalid_date', 'An advertisement cannot be in the future.');
        }
        $n = 0;
        foreach (SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $cid)->where('status', 'dormant')->whereIn('id', $accountIds)->get() as $a) {
            SaccoDormancyNotice::firstOrCreate(['company_id' => $cid, 'savings_account_id' => $a->id, 'stage' => 'advertised', 'on' => $day->toDateString()],
                ['member_id' => $a->member_id, 'balance_minor' => (int) $a->balance_minor, 'reference' => mb_substr(trim($reference), 0, 120), 'created_by_id' => $by->id]);
            $n++;
        }
        SaccoAudit::record('sacco.dormancy.advertised', LoanBooks::settings($cid), $cid, ['accounts' => $accountIds, 'reference' => $reference], (int) $by->id);

        return $n;
    }

    /** Ask a second officer to transfer a dormant account's balance to the regulator. */
    public function requestTransfer(Company $company, int $accountId, User $maker, ?string $uuid = null): SaccoApproval
    {
        SaccoAccess::authorize($maker, 'sacco_accounts');
        $p = $this->transferable((int) $company->id, $accountId);

        return (new SaccoApprovals())->request($company, 'dormant_transfer', ['savings_account_id' => $accountId, 'account_no' => $p['account_no'], 'member_id' => $p['member_id'],
            'amount_minor' => $p['balance_minor'], 'warnings' => $p['warnings']], $maker, $uuid ?? (string) Str::uuid());
    }

    /** @return array<string, mixed> */
    public function executeTransfer(SaccoApproval $approval, User $checker): array
    {
        $cid = (int) $approval->company_id;
        $pl = (array) $approval->payload;
        SaccoTransactions::lockHead($cid);
        $done = SaccoDormancyNotice::withoutGlobalScopes()->where('company_id', $cid)->where('savings_account_id', $pl['savings_account_id'])->where('stage', 'transferred')
            ->where('approval_id', $approval->id)->first();
        if ($done !== null) {
            return ['entry_id' => $done->entry_id];
        }
        $p = $this->transferable($cid, (int) $pl['savings_account_id']);
        if ($p['balance_minor'] !== (int) $pl['amount_minor']) {
            throw BusinessRuleException::make('settlement_changed', 'The balance changed since the transfer was asked: ask again.');
        }
        (new ChartOfAccounts())->seed($cid, 'office'); // a chart from before P4 gains 2450
        $a = SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $cid)->findOrFail((int) $pl['savings_account_id']);
        $money = LoanBooks::money($cid, $p['balance_minor']);
        $entry = (new PostingService())->post(JournalDraft::make($cid, 'dormant_transfer', 'dorm:'.$a->id)->source('sacco_savings_accounts', (int) $a->id)
            ->approval((int) $approval->id)->by((int) $checker->id)->memo('Unclaimed balance of '.$a->account_no.' transferred to the regulator')
            ->debit((int) $a->ledger_account_id, $money, 'savings', (int) $a->id, (int) $a->member_id)
            ->credit(ChartOfAccounts::id($cid, 'unclaimed.regulator'), $money, memberId: (int) $a->member_id));
        SaccoDormancyNotice::create(['company_id' => $cid, 'savings_account_id' => $a->id, 'member_id' => $a->member_id, 'stage' => 'transferred',
            'on' => LoanBooks::today($cid)->toDateString(), 'balance_minor' => $p['balance_minor'], 'approval_id' => $approval->id, 'entry_id' => $entry->id, 'created_by_id' => $checker->id]);

        return ['entry_id' => (int) $entry->id, 'amount_minor' => $p['balance_minor']];
    }

    /** @return array{account_no: string, member_id: int, balance_minor: int, warnings: list<string>} */
    private function transferable(int $cid, int $accountId): array
    {
        if (LoanBooks::settings($cid)->isGroup()) {
            throw BusinessRuleException::make('not_in_group_mode', 'A savings group has no dormant accounts to transfer.');
        }
        $row = collect($this->register($cid))->firstWhere('savings_account_id', $accountId)
            ?? throw BusinessRuleException::make('not_dormant', 'That account is not on the dormant register.');
        if ($row['due'] !== 'transfer' || $row['balance_minor'] <= 0) {
            throw BusinessRuleException::make('transfer_not_due', 'A dormant balance goes to the regulator only after five years without activity.');
        }
        $warnings = [];
        if ($row['advertised_on'] === null) {
            if (SaccoRules::strict($cid)) {
                throw BusinessRuleException::make('advertise_first', 'Advertise the dormant account before its balance is transferred.');
            }
            $warnings[] = 'not_advertised';
        }

        return ['account_no' => $row['account_no'], 'member_id' => $row['member_id'], 'balance_minor' => $row['balance_minor'], 'warnings' => $warnings];
    }
}
