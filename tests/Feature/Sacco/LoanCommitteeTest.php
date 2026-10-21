<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoApproval;
use App\Models\SaccoLoanVote;
use App\Services\Sacco\Loans\Committee;
use App\Services\Sacco\SaccoApprovals;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P2.7 (04 §5.3, §6.2, §7 `loan_decision`; Coop s.55AH, SI 51 reg. 38): a majority of the committee
 * members who may vote approves; a recused member and the applicant are out of the count; the appraiser never
 * decides; an insider's loan never goes to a delegated officer; a delegated limit is enforced; a rejection can be
 * appealed once to the board.
 */
class LoanCommitteeTest extends ApiTestCase
{
    use MakesSaccoLoans;

    /** @return array{0: \App\Models\Company, 1: \App\Models\User, 2: \App\Models\User, 3: list<\App\Models\User>} */
    private function committee(int $seats = 3): array
    {
        [$company, $owner] = $this->loanSacco();
        $officer = $this->saccoStaff($company, 'sacco_loans_officer');
        $members = [];
        for ($i = 0; $i < $seats; $i++) {
            $members[] = $this->officerUser($company, 'sacco_committee', 'loans_committee');
        }
        // Liquidity and assets for the approval checks: a well-funded SACCO.
        $depositor = $this->loanMember($company, $owner);
        $this->loanSavings($company, $depositor, 50_000_000, $owner);

        return [$company, $owner, $officer, $members];
    }

    public function test_majority_with_recusal_and_the_appraiser_out(): void
    {
        [$company, $owner, $officer, [$c1, $c2, $c3]] = $this->committee();
        $borrower = $this->loanMember($company, $owner);
        $this->loanSavings($company, $borrower, 1_000_000, $owner);
        $loan = $this->application($company, $borrower, 800_000, 12, $officer, $officer);
        $svc = new Committee();
        $approval = $svc->sendToCommittee($company, (int) $loan->id, $officer);
        $this->assertSame('in_committee', $loan->fresh()->status);
        $this->assertSame('committee_vote_only', $this->errorCode(fn () => (new SaccoApprovals())->approve($company, (int) $approval->id, $c1)), 'not through the generic approvals screen');

        $svc->recuse($company, (int) $loan->id, $c3, 'The applicant is my sister');
        $tally = $svc->vote($company, (int) $loan->id, $c1, 'approve');
        $this->assertSame([1, 2, 2, null], [$tally['approve'], $tally['eligible'], $tally['required'], $tally['outcome']], 'the recused member is out of the count');
        $this->assertSame('already_voted', $this->errorCode(fn () => $svc->vote($company, (int) $loan->id, $c1, 'approve')));
        $this->assertSame('already_voted', $this->errorCode(fn () => $svc->vote($company, (int) $loan->id, $c3, 'approve')), 'a recusal is final');
        $tally = $svc->vote($company, (int) $loan->id, $c2, 'approve', 'Good record');
        $this->assertSame('approved', $tally['outcome']);
        $loan->refresh();
        $this->assertSame(['approved', 800_000], [$loan->status, $loan->approved_minor]);
        $this->assertSame('executed', SaccoApproval::find($approval->id)->status);
        $this->assertSame(3, SaccoLoanVote::where('loan_id', $loan->id)->count());
        $this->assertTrue((bool) SaccoLoanVote::where('loan_id', $loan->id)->where('user_id', $c3->id)->value('recused'));
        $this->assertNotNull(DB::table('message_log')->where('company_id', $company->id)->where('purpose', 'sacco_notice')->where('body', 'like', '%approved%')->first());
    }

    public function test_rejection_appeal_and_seats_that_cannot_vote(): void
    {
        [$company, $owner, $officer, [$c1, $c2, $c3]] = $this->committee();
        $insiderMember = $this->loanMember($company, $owner);
        $this->loanSavings($company, $insiderMember, 2_000_000, $owner);
        $c4 = $this->officerUser($company, 'sacco_committee', 'loans_committee', null, (int) $insiderMember->id);
        $loan = $this->application($company, $insiderMember, 500_000, 6, $officer, $officer);
        $this->assertTrue($loan->is_insider, 'a committee member\'s own loan');
        $svc = new Committee();
        $this->assertSame('insider_to_committee', $this->errorCode(fn () => $svc->requestDelegated($company, (int) $loan->id, $officer)));
        $svc->sendToCommittee($company, (int) $loan->id, $officer);
        $this->assertSame('not_a_voter', $this->errorCode(fn () => $svc->vote($company, (int) $loan->id, $c4, 'approve')), 'the applicant leaves the room');
        $this->assertSame(3, $svc->tally($loan->fresh(), 'committee')['eligible']);
        $svc->vote($company, (int) $loan->id, $c1, 'reject', 'Too soon after the last loan');
        $tally = $svc->vote($company, (int) $loan->id, $c2, 'reject', 'Agree');
        $this->assertSame('rejected', $tally['outcome'], 'a majority can no longer approve');
        $this->assertSame('rejected', $loan->fresh()->status);

        $this->assertSame('reason_required', $this->errorCode(fn () => $svc->appeal($company, (int) $loan->id, null, '', (int) $insiderMember->id)));
        $chair = $this->officerUser($company, 'sacco_chair', 'chairperson');
        $board = $this->officerUser($company, 'sacco_chair', 'board_member');
        $svc->appeal($company, (int) $loan->id, null, 'My salary rose in October', (int) $insiderMember->id);
        $this->assertSame('appealed', $loan->fresh()->status);
        $this->assertSame('not_a_voter', $this->errorCode(fn () => $svc->vote($company, (int) $loan->id, $c1, 'approve')), 'the committee does not hear the appeal');
        $svc->vote($company, (int) $loan->id, $chair, 'approve');
        // The founder (owner) also sits as chairperson: three board seats, two approvals carry.
        $tally = $svc->vote($company, (int) $loan->id, $board, 'approve');
        $this->assertSame('approved', $tally['outcome']);
        $this->assertSame('approved', $loan->fresh()->status);
        $this->assertSame('invalid_loan_status', $this->errorCode(fn () => $svc->appeal($company, (int) $loan->id, $officer, 'again')));
    }

    public function test_delegated_limit_and_the_maker_rule(): void
    {
        [$company, $owner, $officer] = $this->committee(1);
        $manager = $this->officerUser($company, 'sacco_manager', 'manager', 1_000_000);
        $borrower = $this->loanMember($company, $owner);
        $this->loanSavings($company, $borrower, 2_000_000, $owner);
        $big = $this->application($company, $borrower, 1_500_000, 12, $officer, $officer);
        $svc = new Committee();
        $svc->requestDelegated($company, (int) $big->id, $officer);
        $this->assertSame('delegated_limit', $this->errorCode(fn () => $svc->decide($company, (int) $big->id, $manager, 'approve')));
        $this->assertSame('self_approval', $this->errorCode(fn () => $svc->decide($company, (int) $big->id, $officer, 'approve')), 'the appraiser is the maker');
        $this->assertSame('not_in_committee', $this->errorCode(fn () => $svc->vote($company, (int) $big->id, $manager, 'approve')), 'a delegated decision is not a vote');
        $svc->decide($company, (int) $big->id, $manager, 'reject', 'Above my limit: send it to the committee');
        $this->assertSame('rejected', $big->fresh()->status);

        $b2 = $this->loanMember($company, $owner);
        $this->loanSavings($company, $b2, 1_000_000, $owner);
        $small = $this->application($company, $b2, 900_000, 12, $officer, $officer);
        $svc->requestDelegated($company, (int) $small->id, $officer);
        $this->assertSame('approved', $svc->decide($company, (int) $small->id, $manager, 'approve')->status);
    }
}
