<?php

namespace App\Services\Sacco\Loans;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSetting;
use App\Services\Sacco\Ledger\SubRef;
use App\Services\Sacco\SaccoTransactions;
use Illuminate\Support\Facades\DB;

/**
 * What a member has in the SACCO, as loans see it (AK-49, AK-50): free savings (balances less liens, shares excluded
 * in office mode, Tier 4 s.48), share holdings, the account a loan is paid into or an overpayment goes to, and the
 * liens a guarantee places on savings (sacco_savings_accounts.held_minor, which available() subtracts).
 */
final class MemberFunds
{
    /** Savings accounts that still count (a closed account holds nothing). */
    private const LIVE = ['active', 'dormant', 'frozen'];

    /** Σ (balance − held) over the member's savings accounts: what a loan multiple and a guarantee rest on. */
    public static function freeSavings(int $companyId, int $memberId): int
    {
        $rows = DB::table('sacco_savings_accounts')->where('company_id', $companyId)->where('member_id', $memberId)->whereIn('status', self::LIVE)
            ->selectRaw('COALESCE(SUM(balance_minor), 0) AS bal, COALESCE(SUM(held_minor), 0) AS held')->first();

        return max(0, (int) ($rows->bal ?? 0) - (int) ($rows->held ?? 0));
    }

    /** The member's savings and liens per account, for the appraisal. @return list<array{id: int, account_no: string, balance_minor: int, held_minor: int}> */
    public static function accounts(int $companyId, int $memberId): array
    {
        return DB::table('sacco_savings_accounts')->where('company_id', $companyId)->where('member_id', $memberId)->whereIn('status', self::LIVE)->orderBy('id')
            ->get(['id', 'account_no', 'balance_minor', 'held_minor'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'account_no' => (string) $r->account_no, 'balance_minor' => (int) $r->balance_minor, 'held_minor' => (int) $r->held_minor])->all();
    }

    /** Shares held: [count, value in minor units] (zero before a member buys any). @return array{0: int, 1: int} */
    public static function shares(int $companyId, int $memberId): array
    {
        $row = DB::table('sacco_share_accounts')->where('company_id', $companyId)->where('member_id', $memberId)->first(['shares_count', 'balance_minor']);

        return [(int) ($row->shares_count ?? 0), (int) ($row->balance_minor ?? 0)];
    }

    /** What a loan multiple is measured on: free savings in office mode; savings and shares in a group (VSLA: 3 × shares). */
    public static function lendingBase(int $companyId, int $memberId): int
    {
        $base = self::freeSavings($companyId, $memberId);
        if (SaccoSetting::for($companyId)?->isGroup()) {
            $base += self::shares($companyId, $memberId)[1];
        }

        return $base;
    }

    /**
     * The member's account a loan is paid into or an overpayment goes to (AK-27): the given one if it is theirs and
     * open, else their oldest withdrawable active account, else null.
     */
    public static function payoutAccount(int $companyId, int $memberId, ?int $accountId = null): ?SaccoSavingsAccount
    {
        $q = SaccoSavingsAccount::withoutGlobalScopes()->from('sacco_savings_accounts as a')->select('a.*')
            ->join('sacco_savings_products as p', 'p.id', '=', 'a.product_id')
            ->where('a.company_id', $companyId)->where('a.member_id', $memberId)->whereIn('a.status', ['active', 'dormant']);
        if ($accountId !== null) {
            return $q->where('a.id', $accountId)->first()
                ?? throw BusinessRuleException::make('account_not_found', 'That savings account is not this member\'s, or it is not open.');
        }

        return $q->where('p.withdrawable', true)->where('p.kind', '!=', 'fixed')->orderBy('a.id')->first();
    }

    public static function ref(SaccoSavingsAccount $account): SubRef
    {
        return SaccoTransactions::ref($account);
    }

    /**
     * Place a lien on a guarantor's savings (a guarantee consented to, AK-49): on their savings account with the most
     * free money, which must cover it whole, so a recovery later debits one account. Called inside the caller's
     * transaction; the rows are locked.
     *
     * @return int the savings account the lien sits on
     */
    public static function hold(int $companyId, int $memberId, int $minor): int
    {
        $rows = DB::table('sacco_savings_accounts')->where('company_id', $companyId)->where('member_id', $memberId)->whereIn('status', ['active', 'dormant'])
            ->orderBy('id')->lockForUpdate()->get(['id', 'balance_minor', 'held_minor']);
        $best = $rows->sortByDesc(fn ($r) => (int) $r->balance_minor - (int) $r->held_minor)->first();
        $free = $best === null ? 0 : max(0, (int) $best->balance_minor - (int) $best->held_minor);
        if ($best === null || $free < $minor) {
            throw BusinessRuleException::make('guarantee_capacity', 'The guarantor\'s free savings ('.$free.') do not cover the guarantee ('.$minor.').', ['free_minor' => $free]);
        }
        DB::table('sacco_savings_accounts')->where('id', $best->id)->update(['held_minor' => DB::raw('held_minor + '.$minor), 'updated_at' => now()]);

        return (int) $best->id;
    }

    /** Release (part of) a lien placed by hold(); never below zero. */
    public static function release(int $companyId, int $accountId, int $minor): void
    {
        if ($minor <= 0) {
            return;
        }
        DB::table('sacco_savings_accounts')->where('company_id', $companyId)->where('id', $accountId)->lockForUpdate()->first();
        DB::table('sacco_savings_accounts')->where('company_id', $companyId)->where('id', $accountId)
            ->update(['held_minor' => DB::raw('IF(held_minor > '.$minor.', held_minor - '.$minor.', 0)'), 'updated_at' => now()]);
    }
}
