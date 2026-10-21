<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoJournalLine;
use App\Models\SaccoMemberExit;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoShareAccount;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Loans\Guarantors;
use App\Services\Sacco\Loans\LoanApplications;
use App\Services\Sacco\Members\MemberExit;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\Shares\ShareService;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P2.15 (04 §3.4, §7 `member_exit`): an exit waits for the member's loans and guarantees to end, then by
 * approval transfers the shares to the member who takes them and pays out every savings account, closes them and
 * marks the member exited. The history stays.
 */
class MemberExitTest extends ApiTestCase
{
    use MakesSaccoLoans;

    public function test_blocks_then_settlement_by_approval_with_history_intact(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $leaver = $this->loanMember($company, $owner);
        $acct = $this->loanSavings($company, $leaver, 300_000, $owner);
        $cmp = $this->loanSavings($company, $leaver, 50_000, $owner, 'CMP');
        app(ShareService::class)->purchase($company, ['member_id' => $leaver->id, 'shares_count' => 3, 'channel' => 'cash'], $owner);
        $buyer = $this->loanMember($company, $owner);
        app(ShareService::class)->purchase($company, ['member_id' => $buyer->id, 'shares_count' => 10, 'channel' => 'cash'], $owner);
        $svc = new MemberExit();
        $secretary = $this->saccoStaff($company, 'sacco_secretary');

        $draft = (new LoanApplications())->create($company, ['member_id' => $leaver->id, 'product_id' => $this->ordinaryProduct($company)->id, 'applied_minor' => 100_000, 'term' => 6], $owner);
        $this->assertSame('loan_open', $this->errorCode(fn () => $svc->request($company, (int) $leaver->id, $secretary, ['reason' => 'voluntary', 'shares_to_member_id' => $buyer->id])));
        (new LoanApplications())->withdraw($company, (int) $draft->id, $owner, 'Leaving instead');
        $other = $this->loanMember($company, $owner);
        $this->loanSavings($company, $other, 100_000, $owner);
        $theirs = $this->application($company, $other, 200_000, 6, $owner);
        $g = (new Guarantors())->add($company, (int) $theirs->id, (int) $leaver->id, 50_000, $owner);
        $this->assertSame('guarantee_active', $this->errorCode(fn () => $svc->request($company, (int) $leaver->id, $secretary, ['reason' => 'voluntary', 'shares_to_member_id' => $buyer->id])));
        (new Guarantors())->decline($cid, (int) $g->id, $owner, 'Leaving');
        $this->assertSame('shares_buyer_required', $this->errorCode(fn () => $svc->request($company, (int) $leaver->id, $secretary, ['reason' => 'voluntary'])));

        $linesBefore = SaccoJournalLine::where('company_id', $cid)->where('member_id', $leaver->id)->count();
        $a = $svc->request($company, (int) $leaver->id, $secretary, ['reason' => 'voluntary', 'shares_to_member_id' => $buyer->id, 'channel' => 'cash', 'note' => 'Moving to Gulu']);
        $this->assertSame('forbidden', $this->errorCode(fn () => (new SaccoApprovals())->approve($company, (int) $a->id, $secretary)), 'a second officer with sacco_approve');
        (new SaccoApprovals())->approve($company, (int) $a->id, $owner);
        $exit = SaccoMemberExit::where('member_id', $leaver->id)->firstOrFail();
        $this->assertSame('completed', $exit->status);
        $this->assertSame('exited', $leaver->fresh()->status);
        $this->assertSame([0, 13], [(int) SaccoShareAccount::where('member_id', $leaver->id)->value('shares_count'), (int) SaccoShareAccount::where('member_id', $buyer->id)->value('shares_count')]);
        foreach ([$acct, $cmp] as $s) {
            $s = SaccoSavingsAccount::find($s->id);
            $this->assertSame(['closed', 0], [$s->status, (int) $s->balance_minor]);
        }
        $this->assertGreaterThan($linesBefore, SaccoJournalLine::where('company_id', $cid)->where('member_id', $leaver->id)->count(), 'history kept, the settlement added');
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
        $this->assertSame('invalid_exit', $this->errorCode(fn () => $svc->request($company, (int) $leaver->id, $secretary, ['reason' => 'voluntary'])));
    }
}
