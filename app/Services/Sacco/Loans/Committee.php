<?php

namespace App\Services\Sacco\Loans;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanVote;
use App\Models\SaccoOfficer;
use App\Models\User;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoAudit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who decides a loan (04 §5.3, §6.2, §7 `loan_decision`, P2.7; Coop s.55AH, SI 51 reg. 38). Every decision is a
 * `loan_decision` request in SaccoApprovals, asked by the appraiser, so the maker is never the checker:
 *
 * - delegated: an officer whose position's approval limit covers the amount approves or rejects it alone (never an
 *   insider's loan, never above the limit);
 * - committee: the loans committee votes; a majority of its members who may vote (the applicant, the appraiser and
 *   anyone who recused are left out of the count) approves; once a majority can no longer be reached it is rejected;
 * - appeal: a rejected applicant appeals to the board, which votes the same way.
 *
 * The votes are kept in sacco_loan_votes (one per officer per round, final); the request records the vote that
 * carried it. Only this class casts a committee's decision (`committee_vote_only` otherwise).
 */
final class Committee
{
    public const ROUNDS = ['delegated' => 'committee', 'committee' => 'committee', 'appeal' => 'appeal'];

    /** The request this class is deciding right now (the committee's carrying vote), so the executor lets it through. */
    private static ?int $casting = null;

    public static function casting(): ?int
    {
        return self::$casting;
    }

    /** Send an appraised loan to the loans committee. */
    public function sendToCommittee(Company $company, int $loanId, User $maker, ?string $uuid = null): SaccoApproval
    {
        return $this->ask($company, $loanId, $maker, 'committee', $uuid);
    }

    /** Ask an officer with a delegated limit to decide (not for an insider's loan). */
    public function requestDelegated(Company $company, int $loanId, User $maker, ?string $uuid = null): SaccoApproval
    {
        return $this->ask($company, $loanId, $maker, 'delegated', $uuid);
    }

    /** The applicant (or an officer for them) appeals a rejection to the board. */
    public function appeal(Company $company, int $loanId, ?User $by, string $reason, ?int $memberActorId = null, ?string $uuid = null): SaccoApproval
    {
        if (blank($reason)) {
            throw BusinessRuleException::make('reason_required', 'Say why the decision should change.');
        }
        $cid = (int) $company->id;
        $loan = LoanBooks::find($cid, $loanId);
        if ($memberActorId !== null && (int) $loan->member_id !== $memberActorId) {
            throw BusinessRuleException::make('loan_not_found', 'That loan is not in this SACCO.');
        }
        LoanBooks::assertStatus($loan, ['rejected'], 'appealed');
        if (SaccoLoanVote::withoutGlobalScopes()->where('loan_id', $loan->id)->where('round', 'appeal')->exists()) {
            throw BusinessRuleException::make('already_appealed', 'This loan was already appealed: the board\'s decision is final.');
        }
        // The appeal is asked in the appraiser's name when the member appeals (the maker of the original decision).
        $maker = $by ?? User::withoutGlobalScopes()->find($loan->appraised_by_id) ?? throw BusinessRuleException::make('forbidden', 'An officer must record the appeal.');
        $loan->forceFill(['appeal_reason' => mb_substr($reason, 0, 255)])->save();

        return $this->ask($company, $loanId, $maker, 'appeal', $uuid, true);
    }

    /** A delegated officer decides (approve or reject, with a note on a rejection). */
    public function decide(Company $company, int $loanId, User $checker, string $decision, ?string $note = null): SaccoLoan
    {
        $a = $this->openRequest((int) $company->id, $loanId);
        if (($a->payload['route'] ?? null) !== 'delegated') {
            throw BusinessRuleException::make('committee_vote_only', 'The committee votes on this loan: use the vote.');
        }
        $approvals = new SaccoApprovals();
        $decision === 'approve' ? $approvals->approve($company, (int) $a->id, $checker, $note) : $approvals->reject($company, (int) $a->id, $checker, (string) $note);

        return LoanBooks::find((int) $company->id, $loanId);
    }

    /**
     * A committee (or board) member votes. Returns the tally; when the vote carries the loan or makes a majority
     * impossible, the request is approved or rejected in that voter's name.
     *
     * @return array{approve: int, reject: int, abstain: int, recused: int, eligible: int, required: int, outcome: ?string}
     */
    public function vote(Company $company, int $loanId, User $voter, string $vote, ?string $note = null): array
    {
        if (! in_array($vote, SaccoLoanVote::VOTES, true)) {
            throw BusinessRuleException::make('invalid_vote', 'Vote approve, reject or abstain.');
        }
        $cid = (int) $company->id;
        $a = $this->openRequest($cid, $loanId);
        $route = (string) ($a->payload['route'] ?? '');
        if ($route === 'delegated') {
            throw BusinessRuleException::make('not_in_committee', 'This loan is decided within an officer\'s limit, not by the committee.');
        }
        $round = self::ROUNDS[$route];
        $loan = LoanBooks::find($cid, $loanId);
        $officer = $this->seat($cid, $voter, $round) ?? throw BusinessRuleException::make('not_a_voter', $round === 'appeal'
            ? 'Only the board decides an appeal.' : 'Only the loans committee votes on loans.');
        if (! SaccoAccess::canVote($voter, (int) $loan->member_id, $officer->member_id === null ? null : (int) $officer->member_id, [], $round === 'appeal')) {
            throw BusinessRuleException::make('not_a_voter', 'You may not vote on this loan (it is your own, or your role cannot vote).');
        }
        if ($a->requested_by_id !== null && (int) $a->requested_by_id === (int) $voter->id) {
            throw BusinessRuleException::make('self_approval', 'You appraised this loan: another member of the committee decides it.');
        }

        $tally = DB::transaction(function () use ($cid, $loan, $officer, $voter, $vote, $note, $round) {
            DB::table('sacco_loans')->where('id', $loan->id)->lockForUpdate()->first();
            if (SaccoLoanVote::withoutGlobalScopes()->where('loan_id', $loan->id)->where('round', $round)->where('officer_id', $officer->id)->exists()) {
                throw BusinessRuleException::make('already_voted', 'You have already voted on this loan.');
            }
            SaccoLoanVote::create(['company_id' => $cid, 'loan_id' => $loan->id, 'round' => $round, 'officer_id' => $officer->id, 'user_id' => $voter->id,
                'vote' => $vote, 'recused' => false, 'note' => $note === null ? null : mb_substr($note, 0, 255), 'created_at' => now()]);
            SaccoAudit::record('sacco.loan.voted', $loan, $cid, ['vote' => $vote, 'round' => $round], (int) $voter->id);

            return $this->tally($loan, $round);
        });

        $tally['outcome'] = null;
        if ($tally['approve'] >= $tally['required']) {
            $tally['outcome'] = 'approved';
            $this->cast($company, $a, $voter, 'approve', $note);
        } elseif ($tally['required'] > $tally['approve'] + $tally['undecided']) {
            $tally['outcome'] = 'rejected';
            $this->cast($company, $a, $voter, 'reject', $note ?: 'The committee did not approve it.');
        }
        unset($tally['undecided']);

        return $tally;
    }

    /** A committee member steps out (related to the applicant, or another interest): left out of the count. */
    public function recuse(Company $company, int $loanId, User $voter, ?string $note = null): SaccoLoanVote
    {
        $cid = (int) $company->id;
        $a = $this->openRequest($cid, $loanId);
        $round = self::ROUNDS[(string) $a->payload['route']];
        $officer = $this->seat($cid, $voter, $round) ?? throw BusinessRuleException::make('not_a_voter', 'Only a member of the deciding body can recuse.');
        $loan = LoanBooks::find($cid, $loanId);

        return DB::transaction(function () use ($cid, $loan, $officer, $voter, $note, $round) {
            DB::table('sacco_loans')->where('id', $loan->id)->lockForUpdate()->first();
            if (SaccoLoanVote::withoutGlobalScopes()->where('loan_id', $loan->id)->where('round', $round)->where('officer_id', $officer->id)->exists()) {
                throw BusinessRuleException::make('already_voted', 'You have already voted on this loan.');
            }
            $v = SaccoLoanVote::create(['company_id' => $cid, 'loan_id' => $loan->id, 'round' => $round, 'officer_id' => $officer->id, 'user_id' => $voter->id,
                'vote' => 'abstain', 'recused' => true, 'note' => $note === null ? null : mb_substr($note, 0, 255), 'created_at' => now()]);
            SaccoAudit::record('sacco.loan.recused', $loan, $cid, ['round' => $round], (int) $voter->id);

            return $v;
        });
    }

    /**
     * The count: eligible = the deciding body's seats with a login, less the applicant's own seat, the appraiser's and
     * those recused; a majority of them is required.
     *
     * @return array{approve: int, reject: int, abstain: int, recused: int, eligible: int, required: int, undecided: int}
     */
    public function tally(SaccoLoan $loan, string $round): array
    {
        $cid = (int) $loan->company_id;
        $votes = SaccoLoanVote::withoutGlobalScopes()->where('loan_id', $loan->id)->where('round', $round)->get();
        $recused = $votes->where('recused', true)->pluck('officer_id')->map(fn ($id) => (int) $id)->all();
        $seats = $this->seats($cid, $loan, $round)->reject(fn (SaccoOfficer $o) => in_array((int) $o->id, $recused, true));
        $eligible = $seats->count();
        if ($eligible === 0) {
            throw BusinessRuleException::make('committee_empty', $round === 'appeal' ? 'There is no board member who may decide this appeal.' : 'There is no committee member who may vote on this loan: appoint the loans committee first.');
        }
        $counted = $votes->where('recused', false)->whereIn('officer_id', $seats->pluck('id')->all());
        $approve = $counted->where('vote', 'approve')->count();
        $reject = $counted->where('vote', 'reject')->count();
        $abstain = $counted->where('vote', 'abstain')->count();

        return ['approve' => $approve, 'reject' => $reject, 'abstain' => $abstain, 'recused' => count($recused), 'eligible' => $eligible,
            'required' => intdiv($eligible, 2) + 1, 'undecided' => $eligible - $approve - $reject - $abstain];
    }

    /** The deciding body's seats today, one per login, without the applicant's own seat or the appraiser's. @return Collection<int, SaccoOfficer> */
    public function seats(int $companyId, SaccoLoan $loan, string $round): Collection
    {
        $positions = $round === 'appeal' ? SaccoAccess::APPEAL_POSITIONS : SaccoAccess::VOTE_POSITIONS;

        return SaccoOfficer::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('position', $positions)->whereNotNull('user_id')
            ->activeOn(LoanBooks::today($companyId))->orderBy('id')->get()
            ->reject(fn (SaccoOfficer $o) => ($o->member_id !== null && (int) $o->member_id === (int) $loan->member_id)
                || ($loan->appraised_by_id !== null && (int) $o->user_id === (int) $loan->appraised_by_id))
            ->unique('user_id')->values();
    }

    private function ask(Company $company, int $loanId, User $maker, string $route, ?string $uuid, bool $appeal = false): SaccoApproval
    {
        $cid = (int) $company->id;
        SaccoAccess::authorize($maker, 'sacco_loans');
        $loan = LoanBooks::find($cid, $loanId);
        LoanBooks::assertStatus($loan, $appeal ? ['rejected'] : ['appraised'], $appeal ? 'appealed' : 'sent for a decision');
        if ($route === 'delegated' && $loan->is_insider) {
            throw BusinessRuleException::make('insider_to_committee', 'An insider\'s loan goes to the committee, with the insider out of the room (SI 51 reg. 38).');
        }
        if ($route !== 'delegated') {
            $this->tally($loan, self::ROUNDS[$route]); // refuses an empty committee or board
        }
        $payload = ['loan_id' => (int) $loan->id, 'loan_no' => $loan->loan_no, 'route' => $route, 'amount_minor' => (int) ($loan->approved_minor ?? $loan->applied_minor),
            'term' => $loan->loanTerms()->term, 'member_id' => (int) $loan->member_id];
        $approval = (new SaccoApprovals())->request($company, 'loan_decision', $payload, $maker, $uuid ?? (string) Str::uuid());
        DB::transaction(function () use ($cid, $loan, $approval, $route, $maker) {
            $l = LoanBooks::lock($cid, (int) $loan->id);
            $l->update(['status' => $route === 'appeal' ? 'appealed' : ($route === 'committee' ? 'in_committee' : 'appraised'), 'decision_approval_id' => $approval->id,
                'updated_by_id' => $maker->id]);
        });

        return $approval;
    }

    private function openRequest(int $companyId, int $loanId): SaccoApproval
    {
        $loan = LoanBooks::find($companyId, $loanId);

        return SaccoApproval::withoutGlobalScopes()->where('company_id', $companyId)->where('action', 'loan_decision')->where('subject_id', $loan->id)
            ->where('status', 'pending')->orderByDesc('id')->first()
            ?? throw BusinessRuleException::make('no_decision_pending', 'This loan is not waiting for a decision.');
    }

    private function seat(int $companyId, User $voter, string $round): ?SaccoOfficer
    {
        $positions = $round === 'appeal' ? SaccoAccess::APPEAL_POSITIONS : SaccoAccess::VOTE_POSITIONS;

        return SaccoOfficer::withoutGlobalScopes()->where('company_id', $companyId)->where('user_id', $voter->id)->whereIn('position', $positions)
            ->activeOn(LoanBooks::today($companyId))->orderBy('id')->first();
    }

    private function cast(Company $company, SaccoApproval $a, User $voter, string $decision, ?string $note): void
    {
        self::$casting = (int) $a->id;
        try {
            $approvals = new SaccoApprovals();
            $decision === 'approve' ? $approvals->approve($company, (int) $a->id, $voter, $note) : $approvals->reject($company, (int) $a->id, $voter, (string) $note);
        } finally {
            self::$casting = null;
        }
    }
}
