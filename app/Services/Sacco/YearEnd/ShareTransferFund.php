<?php

namespace App\Services\Sacco\YearEnd;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanGuarantor;
use App\Models\SaccoMember;
use App\Models\SaccoShareAccount;
use App\Models\SaccoStfBuyback;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\JournalDraft;
use App\Services\Sacco\Ledger\PostingRules;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\Reports\Figures;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoAudit;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Sacco\Shares\ShareService;
use App\Support\Rules\SaccoRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Share Transfer Fund buys back a leaving member's shares (P4.9, 02 §1.2 and §1.6, AK-41): shares are never
 * refunded from capital, so a member who leaves without another member to take their shares is paid out of the fund
 * the AGM set aside from surplus (3300, P4.7). The buy-back is the first step of an exit (Members\MemberExit then pays
 * the savings and closes the member): all the member's shares, and only while they have no open loan or guarantee.
 *
 * One entry, key `stf:{buyback_id}` (kind stf_buyback): Dr 3100 the member's share account and Cr cash or bank (the
 * member is paid), and Dr 3300 / Cr 3600 for the same amount, so the fund that was spent becomes a permanent reserve
 * (capital redemption): total equity falls by what was paid, and core capital keeps the reserve. The fund never pays
 * more than it holds (arithmetic: everyone). The law's yearly limit, buy-backs within a financial year at most 5 % of
 * the share capital at its start, refuses a licensed SACCO only and is a warning for any other (SaccoRules::strict).
 * A second officer approves (`stf_buyback`).
 */
final class ShareTransferFund
{
    public const YEAR_LIMIT_BP = 500;

    /** What a buy-back would pay and whether the fund allows it. @param array<string, mixed> $data channel (cash | bank) @return array<string, mixed> */
    public function preview(int $companyId, int $memberId, array $data = []): array
    {
        if (LoanBooks::settings($companyId)->isGroup()) {
            throw BusinessRuleException::make('not_in_group_mode', 'A savings group pays shares out at share-out.');
        }
        $member = SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->find($memberId) ?? throw BusinessRuleException::make('member_not_found', 'That member is not in this SACCO.');
        $this->assertLeaving($companyId, $member);
        $shares = SaccoShareAccount::withoutGlobalScopes()->where('company_id', $companyId)->where('member_id', $member->id)->first();
        if ($shares === null || (int) $shares->shares_count <= 0 || (int) $shares->balance_minor <= 0) {
            throw BusinessRuleException::make('no_shares', 'The member holds no shares to buy back.');
        }
        $channel = (string) ($data['channel'] ?? 'cash');
        if (! in_array($channel, PostingRules::RECEIPT_CHANNELS, true)) {
            throw BusinessRuleException::make('invalid_channel', 'Pay the member in cash or by bank.');
        }
        $amount = (int) $shares->balance_minor;
        $fund = (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id($companyId, 'stf'))->value('balance_minor');
        if ($amount > $fund) {
            throw BusinessRuleException::make('stf_insufficient', 'The Share Transfer Fund holds '.LoanBooks::fmt($companyId, $fund).', less than the shares\' '.LoanBooks::fmt($companyId, $amount).'.');
        }
        $today = LoanBooks::today($companyId);
        $year = FiscalCalendar::yearOf($companyId, $today);
        [$start] = FiscalCalendar::bounds($companyId, $year);
        $capital = Figures::position($companyId, $start->copy()->subDay())['lines']['share_capital'];
        $limit = LoanBooks::money($companyId, max(0, $capital))->times(bcdiv((string) self::YEAR_LIMIT_BP, '10000', 4))->minor;
        $used = (int) SaccoStfBuyback::withoutGlobalScopes()->where('company_id', $companyId)->where('fiscal_year', $year)->where('status', 'posted')->sum('amount_minor');
        $issues = [];
        if ($used + $amount > $limit) {
            $issues[] = ['code' => 'stf_limit_exceeded', 'message' => 'Buy-backs this year would reach '.LoanBooks::fmt($companyId, $used + $amount).'; the fund may buy back at most 5 % of the share capital ('
                .LoanBooks::fmt($companyId, $limit).') a year.'];
        }
        $strict = SaccoRules::strict($companyId);

        return ['member_id' => (int) $member->id, 'member_no' => (string) $member->member_no, 'share_account_id' => (int) $shares->id, 'shares_count' => (int) $shares->shares_count,
            'amount_minor' => $amount, 'channel' => $channel, 'fiscal_year' => $year, 'fund_minor' => $fund, 'year_limit_minor' => $limit, 'year_used_minor' => $used,
            'refusals' => $strict ? $issues : [], 'warnings' => $strict ? [] : $issues];
    }

    /** @param array<string, mixed> $data channel, reason */
    public function request(Company $company, int $memberId, array $data, User $maker, ?string $uuid = null): SaccoApproval
    {
        SaccoAccess::authorize($maker, 'sacco_members');
        $cid = (int) $company->id;
        $p = $this->preview($cid, $memberId, $data);
        if ($p['refusals'] !== []) {
            throw BusinessRuleException::make($p['refusals'][0]['code'], $p['refusals'][0]['message']);
        }

        return DB::transaction(function () use ($company, $cid, $p, $data, $maker, $uuid) {
            $row = SaccoStfBuyback::create(['company_id' => $cid, 'member_id' => $p['member_id'], 'share_account_id' => $p['share_account_id'], 'shares_count' => $p['shares_count'],
                'amount_minor' => $p['amount_minor'], 'channel' => $p['channel'], 'fiscal_year' => $p['fiscal_year'], 'status' => 'requested',
                'reason' => isset($data['reason']) ? mb_substr((string) $data['reason'], 0, 255) : null, 'warnings' => $p['warnings'], 'created_by_id' => $maker->id]);
            $approval = (new SaccoApprovals())->request($company, 'stf_buyback', ['buyback_id' => (int) $row->id, 'member_id' => $p['member_id'], 'member_no' => $p['member_no'],
                'shares_count' => $p['shares_count'], 'amount_minor' => $p['amount_minor'], 'channel' => $p['channel'], 'warnings' => array_column($p['warnings'], 'code')],
                $maker, $uuid ?? (string) Str::uuid());
            $row->update(['approval_id' => $approval->id]);

            return $approval;
        });
    }

    /** @return array<string, mixed> */
    public function execute(SaccoApproval $approval, User $checker): array
    {
        $cid = (int) $approval->company_id;
        $pl = (array) $approval->payload;
        SaccoTransactions::lockHead($cid);
        $row = SaccoStfBuyback::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail((int) $pl['buyback_id']);
        if ($row->status === 'posted') {
            return ['buyback_id' => (int) $row->id, 'entry_id' => $row->entry_id];
        }
        $p = $this->preview($cid, (int) $pl['member_id'], ['channel' => $pl['channel']]);
        if ($p['refusals'] !== []) {
            throw BusinessRuleException::make($p['refusals'][0]['code'], $p['refusals'][0]['message']);
        }
        if ($p['amount_minor'] !== (int) $pl['amount_minor'] || $p['shares_count'] !== (int) $pl['shares_count']) {
            throw BusinessRuleException::make('settlement_changed', 'The member\'s shares changed since the buy-back was asked: ask again.');
        }
        $account = SaccoShareAccount::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($p['share_account_id']);
        $money = LoanBooks::money($cid, $p['amount_minor']);
        $rules = new PostingRules($cid, 'office');
        $ref = ShareService::ref($account);
        $draft = JournalDraft::make($cid, 'stf_buyback', 'stf:'.$row->id)->source('sacco_stf_buybacks', (int) $row->id)->approval((int) $approval->id)->by((int) $checker->id)
            ->memo('Shares of '.$p['member_no'].' bought back by the Share Transfer Fund')
            ->debit($ref->accountId, $money, $ref->type, $ref->id, $ref->memberId, $p['shares_count'].' shares')
            ->credit($rules->channelAccount($p['channel']), $money, memberId: $p['member_id'])
            ->debit(ChartOfAccounts::id($cid, 'stf'), $money)
            ->credit(ChartOfAccounts::id($cid, 'reserve.general'), $money, memo: 'Capital redemption reserve');
        $entry = (new PostingService())->post($draft);
        ShareService::addShares((int) $account->id, -$p['shares_count']);
        $row->update(['status' => 'posted', 'entry_id' => $entry->id, 'posted_on' => LoanBooks::today($cid)->toDateString(), 'warnings' => $p['warnings']]);
        SaccoAudit::record('sacco.shares.bought_back', $row, $cid, ['amount_minor' => $p['amount_minor'], 'entry_id' => $entry->id], (int) $checker->id);

        return ['buyback_id' => (int) $row->id, 'entry_id' => (int) $entry->id];
    }

    public static function closed(SaccoApproval $approval, string $status): void
    {
        SaccoStfBuyback::withoutGlobalScopes()->where('company_id', $approval->company_id)->whereKey($approval->payload['buyback_id'] ?? 0)->where('status', 'requested')
            ->first()?->update(['status' => $status]);
    }

    /** The exit's blockers: a member with an open or pending loan or a live guarantee cannot be bought out. */
    private function assertLeaving(int $cid, SaccoMember $member): void
    {
        if (! in_array($member->status, ['active', 'dormant', 'suspended'], true)) {
            throw BusinessRuleException::make('invalid_exit', "A member who is {$member->status} has no shares to sell back.");
        }
        if (SaccoLoan::withoutGlobalScopes()->where('company_id', $cid)->where('member_id', $member->id)->whereIn('status', array_merge(SaccoLoan::OPEN, SaccoLoan::PENDING, ['draft']))
            ->where('is_deleted', 0)->exists()) {
            throw BusinessRuleException::make('loan_open', 'The member has an open or pending loan: settle or withdraw it first.');
        }
        if (SaccoLoanGuarantor::withoutGlobalScopes()->where('company_id', $cid)->where('guarantor_member_id', $member->id)->whereIn('consent_status', ['requested', 'consented'])->exists()) {
            throw BusinessRuleException::make('guarantee_active', 'The member still guarantees a loan: release them first.');
        }
    }
}
