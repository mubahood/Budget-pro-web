<?php

namespace App\Services\Sacco\Members;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanGuarantor;
use App\Models\SaccoMember;
use App\Models\SaccoMemberExit;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoShareAccount;
use App\Models\User;
use App\Services\Sacco\Ledger\PostingRules;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoAudit;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Services\Sacco\Shares\ShareService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A member leaving (04 §3.4, §7 `member_exit`, P2.15). Blocked while the member has an open or pending loan or a live
 * guarantee. The settlement is planned and frozen in the request: shares transferred to a member who takes them (the
 * Share Transfer Fund's buy-back is P4), every savings account paid out in cash or by bank and closed. A second
 * officer approves; executing posts each step as an ordinary journal (a share transfer, withdrawals), closes the
 * accounts and marks the member exited. Nothing in the member's history is rewritten.
 */
final class MemberExit
{
    /**
     * @param  array<string, mixed>  $data  reason (voluntary|expelled|deceased), note, shares_to_member_id, channel (cash|bank)
     */
    public function request(Company $company, int $memberId, User $maker, array $data, ?string $uuid = null): SaccoApproval
    {
        SaccoAccess::authorize($maker, 'sacco_members');
        $cid = (int) $company->id;
        $reason = (string) ($data['reason'] ?? '');
        if (! in_array($reason, SaccoMemberExit::REASONS, true)) {
            throw BusinessRuleException::make('invalid_exit', 'Say why the member leaves: voluntary, expelled or deceased.');
        }
        $member = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->find($memberId) ?? throw BusinessRuleException::make('member_not_found', 'That member is not in this SACCO.');
        $this->assertFree($cid, $member);
        $channel = in_array($data['channel'] ?? 'cash', ['cash', 'bank'], true) ? (string) ($data['channel'] ?? 'cash') : throw BusinessRuleException::make('invalid_exit', 'Pay savings out in cash or by bank.');
        $shares = SaccoShareAccount::withoutGlobalScopes()->where('company_id', $cid)->where('member_id', $member->id)->first();
        $plan = ['shares' => null, 'savings' => [], 'channel' => $channel];
        if ($shares !== null && (int) $shares->shares_count > 0) {
            $to = isset($data['shares_to_member_id']) ? SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->whereIn('status', ['active', 'dormant'])->find((int) $data['shares_to_member_id']) : null;
            if ($to === null || (int) $to->id === (int) $member->id) {
                throw BusinessRuleException::make('shares_buyer_required', 'Name the member who takes the shares (the Share Transfer Fund buys back from P4).');
            }
            $plan['shares'] = ['count' => (int) $shares->shares_count, 'amount_minor' => (int) $shares->balance_minor, 'to_member_id' => (int) $to->id, 'to_member_no' => $to->member_no];
        }
        foreach (SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $cid)->where('member_id', $member->id)->where('status', '!=', 'closed')->orderBy('id')->get() as $a) {
            $plan['savings'][] = ['account_id' => (int) $a->id, 'account_no' => $a->account_no, 'amount_minor' => (int) $a->balance_minor];
        }
        $exit = SaccoMemberExit::create(['company_id' => $cid, 'member_id' => $member->id, 'reason' => $reason, 'note' => isset($data['note']) ? mb_substr((string) $data['note'], 0, 255) : null,
            'requested_on' => LoanBooks::today($cid)->toDateString(), 'settlement' => $plan, 'created_by_id' => $maker->id]);
        $approval = (new SaccoApprovals())->request($company, 'member_exit', ['exit_id' => (int) $exit->id, 'member_id' => (int) $member->id, 'member_no' => $member->member_no,
            'reason' => $reason, 'settlement' => $plan, 'uuid' => (string) Str::uuid()], $maker, $uuid ?? (string) Str::uuid());
        $exit->update(['approval_id' => $approval->id]);

        return $approval;
    }

    /** @return array<string, mixed> */
    public function execute(SaccoApproval $approval, User $checker): array
    {
        $p = $approval->payload;
        $cid = (int) $approval->company_id;
        $company = Company::withoutGlobalScopes()->findOrFail($cid);
        SaccoTransactions::lockHead($cid);
        $exit = SaccoMemberExit::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail((int) $p['exit_id']);
        if ($exit->status === 'completed') {
            return ['exit_id' => (int) $exit->id, 'entries' => $exit->journal_entry_ids];
        }
        $member = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail((int) $p['member_id']);
        $this->assertFree($cid, $member);
        $settings = LoanBooks::settings($cid);
        $rules = new PostingRules($cid, $settings->mode);
        $stx = new SaccoTransactions();
        $entries = [];
        $plan = (array) $p['settlement'];
        if (! empty($plan['shares'])) {
            $s = $plan['shares'];
            $shares = new ShareService();
            $from = $shares->account($cid, (int) $member->id);
            $to = $shares->account($cid, (int) $s['to_member_id']);
            [$first, $second] = $from->id < $to->id ? [$from, $to] : [$to, $from];
            SaccoShareAccount::withoutGlobalScopes()->whereKey([$first->id, $second->id])->orderBy('id')->lockForUpdate()->get();
            $from->refresh();
            if ((int) $from->shares_count !== (int) $s['count'] || (int) $from->balance_minor !== (int) $s['amount_minor']) {
                throw BusinessRuleException::make('settlement_changed', 'The member\'s shares changed since the exit was asked: ask again.');
            }
            ShareService::assertCap($cid, $settings, $to->fresh(), (int) $s['count'], false);
            $doc = $stx->document($cid, ['uuid' => (string) Str::uuid(), 'kind' => 'share_transfer', 'channel' => 'internal', 'member_id' => (int) $member->id, 'share_account_id' => (int) $from->id,
                'counter_account_id' => (int) $to->id, 'shares_count' => (int) $s['count'], 'amount_minor' => (int) $s['amount_minor'], 'value_date' => LoanBooks::today($cid)->toDateString(),
                'narration' => 'Exit: shares to '.$s['to_member_no']], $checker, [], 'posted');
            ShareService::addShares((int) $from->id, -(int) $s['count']);
            ShareService::addShares((int) $to->id, (int) $s['count']);
            $entries[] = (int) $stx->post($doc, $rules->shareTransfer('txn:'.$doc->uuid, ShareService::ref($from), ShareService::ref($to), $stx->amount($cid, (int) $s['amount_minor']))
                ->approval((int) $approval->id), (int) $checker->id, [])->journal_entry_id;
        }
        foreach ((array) $plan['savings'] as $row) {
            $a = SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail((int) $row['account_id']);
            if ((int) $a->balance_minor !== (int) $row['amount_minor']) {
                throw BusinessRuleException::make('settlement_changed', 'A savings balance changed since the exit was asked: ask again.');
            }
            if ((int) $a->balance_minor > 0) {
                $doc = $stx->document($cid, ['uuid' => (string) Str::uuid(), 'kind' => 'withdrawal', 'channel' => (string) $plan['channel'], 'member_id' => (int) $member->id,
                    'savings_account_id' => (int) $a->id, 'amount_minor' => (int) $a->balance_minor, 'value_date' => LoanBooks::today($cid)->toDateString(), 'narration' => 'Exit: savings paid out'],
                    $checker, [], 'posted');
                $entries[] = (int) $stx->post($doc, $rules->withdrawal('txn:'.$doc->uuid, SaccoTransactions::ref($a), $stx->amount($cid, (int) $a->balance_minor), (string) $plan['channel'])
                    ->approval((int) $approval->id), (int) $checker->id, [])->journal_entry_id;
            }
            (new SavingsAccounts())->close($company, (int) $a->id, 'Member exit', $checker);
        }
        $status = $p['reason'] === 'deceased' ? 'deceased' : 'exited';
        (new MemberService())->setStatus($company, (int) $member->id, $status, $checker, 'Exit: '.$p['reason']);
        $exit->update(['status' => 'completed', 'completed_on' => LoanBooks::today($cid)->toDateString(), 'journal_entry_ids' => $entries]);
        SaccoAudit::record('sacco.member.exited', $exit, $cid, ['entries' => $entries], (int) $checker->id);

        return ['exit_id' => (int) $exit->id, 'entries' => $entries];
    }

    public static function closed(SaccoApproval $approval, string $status): void
    {
        SaccoMemberExit::withoutGlobalScopes()->where('company_id', $approval->company_id)->whereKey($approval->payload['exit_id'] ?? 0)->where('status', 'requested')->first()
            ?->update(['status' => $status === 'rejected' ? 'rejected' : 'cancelled']);
    }

    private function assertFree(int $cid, SaccoMember $member): void
    {
        if (! in_array($member->status, ['active', 'dormant', 'suspended'], true)) {
            throw BusinessRuleException::make('invalid_exit', "A member who is {$member->status} cannot exit.");
        }
        if (SaccoLoan::withoutGlobalScopes()->where('company_id', $cid)->where('member_id', $member->id)->whereIn('status', array_merge(SaccoLoan::OPEN, SaccoLoan::PENDING, ['draft']))->where('is_deleted', 0)->exists()) {
            throw BusinessRuleException::make('loan_open', 'The member has an open or pending loan: settle or withdraw it first.');
        }
        if (SaccoLoanGuarantor::withoutGlobalScopes()->where('company_id', $cid)->where('guarantor_member_id', $member->id)->whereIn('consent_status', ['requested', 'consented'])->exists()) {
            throw BusinessRuleException::make('guarantee_active', 'The member still guarantees a loan: release them first.');
        }
        if (DB::table('sacco_member_exits')->where('company_id', $cid)->where('member_id', $member->id)->where('status', 'requested')
            ->whereExists(fn ($q) => $q->from('sacco_approvals')->whereColumn('sacco_approvals.id', 'sacco_member_exits.approval_id')->where('sacco_approvals.status', 'pending'))->exists()) {
            throw BusinessRuleException::make('exit_pending', 'This member\'s exit is already waiting for approval.');
        }
    }
}
