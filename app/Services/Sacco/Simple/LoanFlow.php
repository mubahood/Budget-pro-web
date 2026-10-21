<?php

namespace App\Services\Sacco\Simple;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanGuarantor;
use App\Models\SaccoLoanProduct;
use App\Models\SaccoLoanRepayment;
use App\Models\SaccoLoanScheduleLine;
use App\Models\SaccoLoanStep;
use App\Models\SaccoMember;
use App\Models\SaccoMemberNotice;
use App\Models\User;
use App\Services\Messaging\Messenger;
use App\Services\Notifications\Notifier;
use App\Services\Sacco\Ledger\LoanPostingRules;
use App\Services\Sacco\Loans\Guarantors;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\Loans\LoanJobs;
use App\Services\Sacco\Loans\LoanNotices;
use App\Services\Sacco\Loans\LoanProducts;
use App\Services\Sacco\Loans\Repayments;
use App\Services\Sacco\Loans\Schedule;
use App\Services\Sacco\OfficerService;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoAudit;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Shop\NumberSequencer;
use App\Support\Rules\SaccoRules;
use App\Support\Sacco\Amount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Flows 4 and 5: a loan request, its approval, the payout and the repayments, the simple way.
 *
 *   member asks (amount, months, purpose, one guarantor)  → pending_guarantor
 *   the guarantor approves                                → pending_chair     (declines → declined_by_guarantor)
 *   the chairperson approves                              → approved          (declines → declined_by_chair)
 *   the treasurer pays it out                             → disbursed (stored as the books' `active`), the schedule made
 *                                     or marks it not paid out → not_disbursed
 *   repayments (penalty → fees → interest → principal)   → closed when nothing is owed
 *
 * Every step is a sacco_loan_steps row (who, when, note), and the next person is told: members in their app
 * (sacco_member_notices) and by SMS/WhatsApp (LoanNotices), officers by an app notification (Notifier) and SMS/WhatsApp.
 * Interest comes from the SACCO's loan settings (its member loan product: default 1 % a month on the reducing balance,
 * flat as an option). Appraisal, committee votes, collateral, contracts and the cooling-off are not in this flow.
 */
final class LoanFlow
{
    public const PRODUCT_CODE = 'MEMBER';

    public const METHODS = ['cash', 'bank', 'mobile_money'];

    public const REPAY_METHODS = ['cash', 'bank', 'savings'];

    // ---------------------------------------------------------------- settings

    /** The SACCO's member loan product (made on first use): it holds the interest settings. */
    public function product(int $companyId): SaccoLoanProduct
    {
        $p = SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $companyId)->where('code', self::PRODUCT_CODE)->first();
        if ($p !== null) {
            return $p;
        }
        $currency = LoanBooks::settings($companyId)->currency;

        return (new LoanProducts())->create($companyId, ['code' => self::PRODUCT_CODE, 'name' => 'Member loan', 'interest_method' => 'reducing_annuity', 'rate_bp' => 100,
            'rate_period' => 'month', 'repayment_frequency' => 'monthly', 'min_term' => 1, 'max_term' => 60, 'min_amount_minor' => Amount::fromMajor('1000', $currency)->minor,
            'max_amount_minor' => SaccoRules::MAX_MINOR / 1000, 'savings_multiplier_bp' => 0, 'min_membership_days' => 0, 'min_shares' => 0,
            'guarantors_required' => 1, 'guarantee_cover_bp' => 0, 'processing_fee_bp' => 0, 'processing_fee_minor' => 0, 'insurance_bp' => 0,
            'penalty_rate_bp' => 0, 'grace_days' => 0, 'principal_grace_periods' => 0, 'cooling_off_working_days' => LoanBooks::strict($companyId) ? 5 : 0, 'cancellation_fee_bp' => 0,
            'allowed_channels' => ['savings', 'cash', 'bank'], 'collateral_types' => ['other'], 'is_active' => true]);
    }

    /** @return array{rate_bp: int, rate_period: string, method: string, currency: string} */
    public function settings(int $companyId): array
    {
        $p = $this->product($companyId);

        return ['rate_bp' => (int) $p->rate_bp, 'rate_period' => (string) $p->rate_period, 'method' => $p->interest_method === 'flat' ? 'flat' : 'reducing',
            'currency' => LoanBooks::settings($companyId)->currency];
    }

    /** @param array{rate_bp?: int, rate_period?: string, method?: string} $data @return array<string, mixed> */
    public function updateSettings(Company $company, User $by, array $data): array
    {
        SaccoAccess::authorize($by, 'sacco_settings');
        $v = validator($data, ['rate_bp' => SaccoRules::bp(100000), 'rate_period' => ['sometimes', Rule::in(['month', 'year'])], 'method' => ['sometimes', Rule::in(['reducing', 'flat'])]]);
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_settings', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }
        $d = $v->validated();
        $cid = (int) $company->id;
        $p = $this->product($cid);
        $changes = array_filter(['rate_bp' => $d['rate_bp'] ?? null, 'rate_period' => $d['rate_period'] ?? null,
            'interest_method' => isset($d['method']) ? ($d['method'] === 'flat' ? 'flat' : 'reducing_annuity') : null], fn ($x) => $x !== null);
        if ($changes !== []) {
            (new LoanProducts())->update($cid, (int) $p->id, $changes, (int) $by->id);
        }

        return $this->settings($cid);
    }

    // ---------------------------------------------------------------- asking

    /**
     * What the loan costs before it is asked for: the monthly instalment, the interest, the total and the schedule.
     *
     * @return array<string, mixed>
     */
    public function quote(int $companyId, int $amountMinor, int $months): array
    {
        if ($amountMinor < 1) {
            throw BusinessRuleException::make('invalid_loan', 'Enter the amount you want to borrow.');
        }
        if ($months < 1 || $months > 60) {
            throw BusinessRuleException::make('invalid_loan', 'Repay over 1 to 60 months.');
        }
        $p = $this->product($companyId);
        LoanProducts::assertFits($p, $amountMinor, $months);
        $terms = $p->terms($months, (string) LoanBooks::settings($companyId)->allocation_order);
        $today = LoanBooks::today($companyId);
        $facts = LoanProducts::keyFacts($amountMinor, $terms, $today, Schedule::firstDueAfter($today, 'monthly'), (string) LoanBooks::settings($companyId)->currency);

        return ['amount_minor' => $amountMinor, 'months' => $months, 'currency' => $facts['currency'], 'rate_bp' => (int) $p->rate_bp, 'rate_period' => (string) $p->rate_period,
            'method' => $p->interest_method === 'flat' ? 'flat' : 'reducing', 'monthly_instalment_minor' => (int) $facts['instalment_minor'],
            'total_interest_minor' => (int) $facts['total_interest_minor'], 'total_payable_minor' => (int) $facts['total_repayable_minor'],
            'first_due_on' => $facts['first_due_on'], 'last_due_on' => $facts['maturity_on'], 'schedule' => array_map([self::class, 'line'], $facts['schedule'])];
    }

    /** Members a borrower may pick as their guarantor: active, not themselves. @return list<array<string, mixed>> */
    public function guarantorOptions(int $companyId, int $borrowerId): array
    {
        return SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->where('status', 'active')->where('id', '!=', $borrowerId)
            ->orderBy('first_name')->orderBy('last_name')->get()->map(fn ($m) => ['member_id' => (int) $m->id, 'member_no' => $m->member_no, 'name' => $m->full_name])->all();
    }

    /**
     * The member asks (web /member or the app). Idempotent on uuid.
     *
     * @param  array{uuid?: ?string, amount_minor: int, months: int, purpose?: ?string, guarantor_member_id: int}  $data
     */
    public function request(Company $company, SaccoMember $member, array $data): SaccoLoan
    {
        $cid = (int) $company->id;
        if ((int) $member->company_id !== $cid) {
            throw BusinessRuleException::make('member_not_found', 'That member is not in this SACCO.');
        }
        $v = validator($data, ['uuid' => ['nullable', 'uuid'], 'amount_minor' => SaccoRules::minor(true, 1), 'months' => ['required', 'integer', 'min:1', 'max:60', SaccoRules::noFloat()],
            'purpose' => ['nullable', 'string', 'max:255'], 'guarantor_member_id' => ['required', 'integer']],
            ['guarantor_member_id.required' => 'Pick one member to guarantee your loan.']);
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_loan', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }
        $d = $v->validated();
        $uuid = strtolower((string) ($d['uuid'] ?? Str::uuid()));
        $existing = SaccoLoan::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first();
        if ($existing !== null) {
            if ((int) $existing->member_id !== (int) $member->id || (int) $existing->applied_minor !== (int) $d['amount_minor']) {
                throw BusinessRuleException::make('idempotency_conflict', 'That request was already made with other details.');
            }

            return $existing;
        }
        if ($member->status !== 'active') {
            throw BusinessRuleException::make('member_not_active', 'Only an active member can ask for a loan.');
        }
        if ((int) $d['guarantor_member_id'] === (int) $member->id) {
            throw BusinessRuleException::make('invalid_guarantor', 'You cannot guarantee your own loan: pick another member.');
        }
        $guarantor = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->find((int) $d['guarantor_member_id'])
            ?? throw BusinessRuleException::make('invalid_guarantor', 'That guarantor is not a member of this SACCO.');
        if ($guarantor->status !== 'active') {
            throw BusinessRuleException::make('invalid_guarantor', 'Your guarantor must be an active member.');
        }
        $quote = $this->quote($cid, (int) $d['amount_minor'], (int) $d['months']);
        $product = $this->product($cid);
        $settings = LoanBooks::settings($cid);

        $loan = DB::transaction(function () use ($cid, $member, $guarantor, $d, $uuid, $product, $settings) {
            SaccoMember::withoutGlobalScopes()->whereKey($member->id)->lockForUpdate()->first();
            if (SaccoLoan::withoutGlobalScopes()->where('company_id', $cid)->where('member_id', $member->id)->whereIn('status', SaccoLoan::SIMPLE_PENDING)->exists()) {
                throw BusinessRuleException::make('request_pending', 'You already have a loan request waiting. Wait for it to be decided first.');
            }
            $now = now();
            $loan = SaccoLoan::create(['company_id' => $cid, 'uuid' => $uuid, 'member_id' => $member->id, 'product_id' => $product->id, 'status' => 'pending_guarantor',
                'source' => 'member', 'applied_minor' => (int) $d['amount_minor'], 'currency' => $settings->currency, 'term' => (int) $d['months'],
                'purpose' => $d['purpose'] ?? null, 'terms' => $product->terms((int) $d['months'], (string) $settings->allocation_order)->toArray(),
                'member_actor_id' => $member->id, 'loan_no' => NumberSequencer::next($cid, 'ln', $now), 'submitted_at' => $now]);
            SaccoLoanGuarantor::create(['company_id' => $cid, 'loan_id' => $loan->id, 'guarantor_member_id' => $guarantor->id, 'guaranteed_minor' => (int) $d['amount_minor'],
                'consent_status' => 'requested', 'consent_channel' => 'member_app', 'notice_sent_at' => $now]);
            $this->step($loan, 'requested', null, $member, $d['purpose'] ?? null);
            SaccoAudit::record('sacco.loan.requested', $loan, $cid, ['applied_minor' => (int) $loan->applied_minor, 'guarantor' => (int) $guarantor->id]);
            $amount = LoanBooks::fmt($cid, (int) $loan->applied_minor);
            $this->tellMember($loan, (int) $guarantor->id, 'guarantor_ask', 'A loan request waits for you',
                "{$member->full_name} asks you to guarantee a loan of {$amount} over {$loan->term} months. Approve or decline it.", ['borrower' => $member->full_name, 'amount' => $amount]);

            return $loan;
        });

        return $loan->fresh();
    }

    // ---------------------------------------------------------------- deciding

    /** The guarantor (signed in as a member) approves or declines. */
    public function guarantorDecide(Company $company, SaccoMember $guarantor, int $loanId, string $decision, ?string $note = null): SaccoLoan
    {
        $cid = (int) $company->id;
        $this->assertDecision($decision);

        return DB::transaction(function () use ($cid, $guarantor, $loanId, $decision, $note) {
            $loan = LoanBooks::lock($cid, $loanId);
            $g = SaccoLoanGuarantor::withoutGlobalScopes()->where('company_id', $cid)->where('loan_id', $loan->id)->where('guarantor_member_id', $guarantor->id)->lockForUpdate()->first()
                ?? throw BusinessRuleException::make('loan_not_found', 'That loan request is not waiting for you.');
            LoanBooks::assertStatus($loan, ['pending_guarantor'], $decision === 'approve' ? 'approved by the guarantor' : 'declined by the guarantor');
            $borrower = SaccoMember::withoutGlobalScopes()->findOrFail($loan->member_id);
            $amount = LoanBooks::fmt($cid, (int) $loan->applied_minor);
            if ($decision === 'approve') {
                $g->update(['consent_status' => 'consented', 'consented_at' => now()]);
                $loan->update(['status' => 'pending_chair']);
                $this->step($loan, 'guarantor_approved', null, $guarantor, $note);
                $this->tellMember($loan, (int) $borrower->id, 'loan_guaranteed', 'Your guarantor approved', "{$guarantor->full_name} approved your loan request. It now waits for the chairperson.",
                    ['guarantor' => $guarantor->full_name]);
                $this->tellStaff($loan, $this->chairs($cid), 'A loan request waits for your approval', "{$borrower->full_name} asks for {$amount} over {$loan->term} months; {$guarantor->full_name} guarantees it.");
            } else {
                $g->update(['consent_status' => 'declined', 'declined_at' => now()]);
                $loan->update(['status' => 'declined_by_guarantor', 'rejected_at' => now(), 'decision_note' => $note === null ? null : mb_substr($note, 0, 255)]);
                $this->step($loan, 'guarantor_declined', null, $guarantor, $note);
                $this->tellMember($loan, (int) $borrower->id, 'loan_declined', 'Your guarantor declined', "{$guarantor->full_name} declined to guarantee your loan request.".($note ? " Note: {$note}" : ''),
                    ['by' => $guarantor->full_name]);
            }

            return $loan->fresh();
        });
    }

    /** The chairperson approves or declines a guarantor-approved request. */
    public function chairDecide(Company $company, User $chair, int $loanId, string $decision, ?string $note = null): SaccoLoan
    {
        $cid = (int) $company->id;
        $this->assertDecision($decision);
        if (! $this->isChair($company, $chair)) {
            throw BusinessRuleException::make('forbidden', 'Only the chairperson approves loan requests.', ['position' => 'chairperson']);
        }

        return DB::transaction(function () use ($cid, $chair, $loanId, $decision, $note) {
            $loan = LoanBooks::lock($cid, $loanId);
            LoanBooks::assertStatus($loan, ['pending_chair'], $decision === 'approve' ? 'approved by the chairperson' : 'declined by the chairperson');
            $amount = LoanBooks::fmt($cid, (int) $loan->applied_minor);
            $borrower = SaccoMember::withoutGlobalScopes()->findOrFail($loan->member_id);
            if ($decision === 'approve') {
                $loan->update(['status' => 'approved', 'approved_minor' => (int) $loan->applied_minor, 'approved_at' => now(), 'decision_note' => $note === null ? null : mb_substr($note, 0, 255),
                    'officer_id' => $chair->id, 'updated_by_id' => $chair->id]);
                $this->step($loan, 'chair_approved', $chair, null, $note);
                $this->tellMember($loan, (int) $borrower->id, 'loan_request_approved', 'Your loan is approved', "Your loan of {$amount} is approved. The treasurer will pay it out.", ['amount' => $amount]);
                $this->tellStaff($loan, $this->treasurers($cid), 'A loan is ready to pay out', "{$borrower->full_name}'s loan of {$amount} was approved by the chairperson.");
            } else {
                $loan->update(['status' => 'declined_by_chair', 'rejected_at' => now(), 'decision_note' => $note === null ? null : mb_substr($note, 0, 255), 'updated_by_id' => $chair->id]);
                $this->step($loan, 'chair_declined', $chair, null, $note);
                (new Guarantors())->releaseAll($loan, 'The loan request was declined.', (int) $chair->id);
                $this->tellMember($loan, (int) $borrower->id, 'loan_declined', 'Your loan request was declined', 'The chairperson declined your loan request.'.($note ? " Note: {$note}" : ''), ['by' => 'the chairperson']);
            }

            return $loan->fresh();
        });
    }

    // ---------------------------------------------------------------- paying out

    /**
     * The treasurer pays an approved loan out: posted to the ledger (Dr loans, Cr cash/bank/mobile money) and the
     * schedule made. Paying out twice gives the same loan, not a second payout.
     *
     * @param  array{paid_on?: ?string, method: string, notes?: ?string}  $data
     */
    public function payOut(Company $company, User $treasurer, int $loanId, array $data): SaccoLoan
    {
        SaccoAccess::authorize($treasurer, 'sacco_disburse');
        $cid = (int) $company->id;
        $v = validator($data, ['paid_on' => ['nullable', 'date'], 'method' => ['required', Rule::in(self::METHODS)], 'notes' => ['nullable', 'string', 'max:255']]);
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_payout', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }
        $d = $v->validated();
        $on = Carbon::parse($d['paid_on'] ?? LoanBooks::today($cid))->startOfDay();

        return DB::transaction(function () use ($cid, $treasurer, $loanId, $d, $on) {
            SaccoTransactions::lockHead($cid);
            $loan = LoanBooks::lock($cid, $loanId);
            if (in_array($loan->status, ['active', 'closed'], true) && $loan->disbursement_entry_id !== null) {
                return $loan; // already paid out: a second tap changes nothing
            }
            LoanBooks::assertStatus($loan, ['approved'], 'paid out');
            if ($on->gt(LoanBooks::today($cid))) {
                throw BusinessRuleException::make('invalid_date', 'A loan is paid out today or on a past day, never in the future.');
            }
            $method = (string) $d['method'];
            $principal = (int) ($loan->approved_minor ?? $loan->applied_minor);
            $currency = (string) $loan->currency;
            $money = Money::account($cid, $method);
            $available = (int) DB::table('sacco_account_balances')->where('company_id', $cid)->where('ledger_account_id', $money)->value('balance_minor');
            if ($available < $principal) {
                throw BusinessRuleException::make('insufficient_funds', 'The '.strtolower(Money::LABELS[$method]).' account holds '.LoanBooks::fmt($cid, max(0, $available)).': not enough to pay this loan out.',
                    ['available_minor' => max(0, $available)]);
            }
            $terms = $loan->loanTerms();
            $first = Schedule::firstDueAfter($on, $terms->frequency);
            $lines = Schedule::build($principal, $terms, $first, $currency);
            $version = (int) $loan->schedule_version;
            foreach ($lines as $l) {
                SaccoLoanScheduleLine::create(['company_id' => $cid, 'loan_id' => $loan->id, 'schedule_version' => $version, 'installment_no' => $l->no, 'due_on' => $l->dueOn,
                    'opening_principal_minor' => $l->openingPrincipal, 'principal_due_minor' => $l->principal, 'interest_due_minor' => $l->interest, 'fee_due_minor' => $l->fee]);
            }
            [$entryDate, $valueDate] = LoanBooks::dates($cid, $on);
            $amount = Amount::of($principal, $currency);
            $zero = Amount::zero($currency);
            $draft = (new LoanPostingRules($cid, LoanBooks::settings($cid)->mode))->disbursement('loan:'.$loan->id.':payout', $loan, $amount, $zero, $zero, null, $money)
                ->by((int) $treasurer->id);
            $stx = new SaccoTransactions();
            $tx = $stx->document($cid, ['uuid' => (string) Str::uuid(), 'kind' => 'loan_disbursement', 'channel' => ['cash' => 'cash', 'bank' => 'bank', 'mobile_money' => 'momo'][$method],
                'member_id' => (int) $loan->member_id, 'loan_id' => (int) $loan->id, 'amount_minor' => $principal, 'value_date' => $valueDate,
                'narration' => 'Loan '.$loan->loan_no.' paid out'.(filled($d['notes'] ?? null) ? ': '.$d['notes'] : '')], $treasurer, ['entry_date' => $entryDate], 'posted');
            $tx = $stx->post($tx, $draft, (int) $treasurer->id, ['entry_date' => $entryDate]);
            $loan->fill(['status' => 'active', 'approved_minor' => $principal, 'disbursed_on' => $valueDate, 'first_due_on' => $first->toDateString(), 'maturity_on' => end($lines)->dueOn,
                'disbursement_entry_id' => (int) $tx->journal_entry_id, 'disbursement_channel' => ['cash' => 'cash', 'bank' => 'bank', 'mobile_money' => 'momo'][$method],
                'arrears_on' => $valueDate, 'updated_by_id' => $treasurer->id])->save();
            $this->step($loan, 'paid_out', $treasurer, null, trim(Money::LABELS[$method].(filled($d['notes'] ?? null) ? ': '.$d['notes'] : '')));
            SaccoAudit::record('sacco.loan.disbursed', $loan, $cid, ['method' => $method, 'amount_minor' => $principal, 'entry_id' => (int) $tx->journal_entry_id], (int) $treasurer->id);
            $fmt = LoanBooks::fmt($cid, $principal);
            $this->tellMember($loan, (int) $loan->member_id, 'loan_paid_out', 'Your loan was paid out',
                "{$fmt} was paid to you (".strtolower(Money::LABELS[$method]).'). First instalment '.LoanBooks::fmt($cid, $lines[0]->instalment())." on {$lines[0]->dueOn}.",
                ['amount' => $fmt, 'due' => LoanBooks::fmt($cid, $lines[0]->instalment()), 'date' => $lines[0]->dueOn]);

            return $loan->fresh();
        });
    }

    /** The treasurer marks an approved loan as not paid out, with a note. */
    public function notPaidOut(Company $company, User $treasurer, int $loanId, string $notes): SaccoLoan
    {
        SaccoAccess::authorize($treasurer, 'sacco_disburse');
        if (trim($notes) === '') {
            throw BusinessRuleException::make('reason_required', 'Say why the loan is not paid out.');
        }
        $cid = (int) $company->id;

        return DB::transaction(function () use ($cid, $treasurer, $loanId, $notes) {
            $loan = LoanBooks::lock($cid, $loanId);
            LoanBooks::assertStatus($loan, ['approved'], 'marked not paid out');
            $loan->update(['status' => 'not_disbursed', 'cancelled_on' => LoanBooks::today($cid)->toDateString(), 'decision_note' => mb_substr(trim($notes), 0, 255), 'updated_by_id' => $treasurer->id]);
            (new Guarantors())->releaseAll($loan, 'The loan was not paid out.', (int) $treasurer->id);
            $this->step($loan, 'not_paid_out', $treasurer, null, $notes);
            $this->tellMember($loan, (int) $loan->member_id, 'loan_not_paid_out', 'Your loan was not paid out', 'Your approved loan was not paid out. Note: '.trim($notes), ['note' => trim($notes)]);

            return $loan->fresh();
        });
    }

    // ---------------------------------------------------------------- repaying

    /**
     * Record a repayment (cash, bank or from the member's savings). Interest due up to the day is charged first, so
     * the money goes to penalty, then interest, then principal; the loan closes when nothing is owed.
     *
     * @param  array{uuid?: ?string, amount_minor: int, paid_on?: ?string, method?: string, savings_account_id?: ?int}  $data
     */
    public function repay(Company $company, User $by, int $loanId, array $data): SaccoLoanRepayment
    {
        SaccoAccess::authorize($by, 'sacco_teller');
        $cid = (int) $company->id;
        $v = validator($data, ['uuid' => ['nullable', 'uuid'], 'amount_minor' => SaccoRules::minor(true, 1), 'paid_on' => ['nullable', 'date'],
            'method' => ['nullable', Rule::in(self::REPAY_METHODS)], 'savings_account_id' => ['nullable', 'integer']]);
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_repayment', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }
        $d = $v->validated();
        $loan = LoanBooks::find($cid, $loanId);
        $on = Carbon::parse($d['paid_on'] ?? LoanBooks::today($cid))->startOfDay();
        $uuid = strtolower((string) ($d['uuid'] ?? Str::uuid()));
        $replay = SaccoLoanRepayment::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first();
        if ($replay === null) {
            LoanBooks::assertStatus($loan, ['active'], 'repaid');
            (new LoanJobs())->run($cid, $on->toDateString());
        }
        $method = (string) ($d['method'] ?? 'cash');
        $savings = $method === 'savings' ? \App\Services\Sacco\Loans\MemberFunds::payoutAccount($cid, (int) $loan->member_id, isset($d['savings_account_id']) ? (int) $d['savings_account_id'] : null) : null;
        if ($method === 'savings' && $savings === null) {
            throw BusinessRuleException::make('account_not_found', 'The member has no open savings account to pay from.');
        }
        [$entryDate] = LoanBooks::dates($cid, $on);
        $r = (new Repayments())->record($company, $loanId, ['uuid' => $uuid, 'amount_minor' => (int) $d['amount_minor'], 'channel' => $method === 'savings' ? 'internal' : $method,
            'savings_account_id' => $savings?->id, 'value_date' => $on->toDateString()], $by, ['entry_date' => $entryDate]);
        if ($replay === null) {
            $loan = LoanBooks::find($cid, $loanId);
            DB::transaction(function () use ($loan, $by, $r, $cid) {
                $this->step($loan, 'repayment', $by, null, 'Paid '.LoanBooks::fmt($cid, (int) $r->amount_minor));
                if ($loan->status === 'closed') {
                    $this->step($loan, 'closed', $by, null, 'Fully paid');
                    $this->tellMember($loan, (int) $loan->member_id, 'loan_closed', 'Your loan is fully paid', 'Your loan '.$loan->loan_no.' is fully paid. Thank you.', [], false);
                }
            });
        }

        return $r;
    }

    // ---------------------------------------------------------------- reading

    /** The status in the flow's words. */
    public static function status(SaccoLoan $loan): string
    {
        return match ($loan->status) {
            'active', 'disbursing', 'restructured' => 'disbursed',
            'submitted', 'appraised', 'in_committee', 'appealed' => 'pending_chair',
            'rejected' => 'declined_by_chair',
            'signed' => 'approved',
            'cancelled' => 'not_disbursed',
            default => (string) $loan->status,
        };
    }

    public const LABELS = ['pending_guarantor' => 'Waiting for the guarantor', 'pending_chair' => 'Waiting for the chairperson', 'approved' => 'Approved: to pay out',
        'disbursed' => 'Paid out', 'closed' => 'Fully paid', 'declined_by_guarantor' => 'Declined by the guarantor', 'declined_by_chair' => 'Declined by the chairperson',
        'not_disbursed' => 'Not paid out', 'written_off' => 'Written off', 'draft' => 'Draft'];

    /**
     * Everything a screen shows about one loan: the request, the guarantor, the steps, and once paid out, the
     * schedule (principal, interest, balance), what is paid, due and overdue, and what is still owed.
     *
     * @return array<string, mixed>
     */
    public function present(SaccoLoan $loan, bool $full = true): array
    {
        $cid = (int) $loan->company_id;
        $borrower = SaccoMember::withoutGlobalScopes()->find($loan->member_id);
        $g = SaccoLoanGuarantor::withoutGlobalScopes()->where('loan_id', $loan->id)->orderBy('id')->first();
        $gm = $g === null ? null : SaccoMember::withoutGlobalScopes()->find($g->guarantor_member_id);
        $status = self::status($loan);
        $outstanding = max(0, (int) $loan->principal_outstanding_minor) + max(0, (int) $loan->interest_due_minor) + max(0, (int) $loan->penalty_due_minor) + max(0, (int) $loan->fees_due_minor);
        $out = ['id' => (int) $loan->id, 'uuid' => $loan->uuid, 'loan_no' => $loan->loan_no, 'status' => $status, 'status_label' => self::LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status)),
            'member_id' => (int) $loan->member_id, 'member_name' => $borrower?->full_name, 'member_no' => $borrower?->member_no, 'amount_minor' => (int) ($loan->approved_minor ?? $loan->applied_minor),
            'months' => (int) $loan->term, 'purpose' => $loan->purpose, 'currency' => $loan->currency, 'rate_bp' => (int) ($loan->terms['rate_bp'] ?? 0),
            'rate_period' => (string) ($loan->terms['rate_period'] ?? 'month'), 'method' => ($loan->terms['method'] ?? '') === 'flat' ? 'flat' : 'reducing',
            'guarantor' => $g === null ? null : ['member_id' => (int) $g->guarantor_member_id, 'name' => $gm?->full_name, 'status' => $g->consent_status],
            'requested_at' => $loan->submitted_at?->toIso8601String(), 'decision_note' => $loan->decision_note, 'paid_out_on' => $loan->disbursed_on?->toDateString(),
            'outstanding_minor' => $outstanding, 'days_overdue' => (int) $loan->days_past_due, 'closed_on' => $loan->closed_on?->toDateString()];
        if (! $full) {
            return $out;
        }
        $out['steps'] = SaccoLoanStep::withoutGlobalScopes()->where('loan_id', $loan->id)->orderBy('id')->get()
            ->map(fn ($s) => ['step' => $s->step, 'by' => $s->actor_name, 'by_type' => $s->actor_type, 'at' => $s->created_at?->toIso8601String(), 'note' => $s->note])->all();
        $today = LoanBooks::today($cid);
        $lines = SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $loan->id)->where('schedule_version', $loan->schedule_version)->orderBy('installment_no')->get();
        if ($lines->isEmpty()) {
            $q = $this->quoteFor($loan);
            $out['schedule'] = $q['schedule'];
            $out['totals'] = ['monthly_instalment_minor' => $q['monthly_instalment_minor'], 'total_interest_minor' => $q['total_interest_minor'], 'total_payable_minor' => $q['total_payable_minor']];
            $out['position'] = null;

            return $out;
        }
        $schedule = [];
        $dueNow = 0;
        foreach ($lines as $l) {
            $interest = (int) ($l->interest_charged_minor ?? $l->interest_due_minor);
            $paid = (int) $l->principal_paid_minor + (int) $l->interest_paid_minor + (int) $l->fee_paid_minor;
            $owed = (int) $l->principal_due_minor + $interest + (int) $l->fee_due_minor;
            if ($l->due_on->lte($today) && $l->status !== 'paid') {
                $dueNow += max(0, $owed - $paid);
            }
            $schedule[] = ['no' => (int) $l->installment_no, 'due_on' => $l->due_on->toDateString(), 'principal_minor' => (int) $l->principal_due_minor, 'interest_minor' => $interest,
                'instalment_minor' => $owed, 'balance_minor' => (int) $l->opening_principal_minor - (int) $l->principal_due_minor, 'paid_minor' => $paid, 'status' => $l->status,
                'overdue' => $l->due_on->lt($today) && $l->status !== 'paid'];
        }
        $repayments = SaccoLoanRepayment::withoutGlobalScopes()->where('loan_id', $loan->id)->where('status', 'posted')->orderBy('value_date')->orderBy('id')->get();
        $next = $lines->first(fn ($l) => $l->status !== 'paid');
        $out['schedule'] = $schedule;
        $out['totals'] = ['monthly_instalment_minor' => $schedule[0]['instalment_minor'] ?? 0, 'total_interest_minor' => array_sum(array_column($schedule, 'interest_minor')),
            'total_payable_minor' => array_sum(array_column($schedule, 'instalment_minor'))];
        $out['position'] = ['paid_minor' => (int) $repayments->where('kind', 'repayment')->sum('amount_minor'), 'due_now_minor' => $loan->status === 'closed' ? 0 : $dueNow,
            'days_overdue' => (int) $loan->days_past_due, 'outstanding_minor' => $outstanding, 'principal_minor' => max(0, (int) $loan->principal_outstanding_minor),
            'interest_minor' => max(0, (int) $loan->interest_due_minor), 'penalty_minor' => max(0, (int) $loan->penalty_due_minor),
            'next_due_on' => $next?->due_on?->toDateString(), 'next_due_minor' => $next === null ? 0 : (int) $next->principal_due_minor + (int) ($next->interest_charged_minor ?? $next->interest_due_minor)
                - (int) $next->principal_paid_minor - (int) $next->interest_paid_minor];
        $out['repayments'] = $repayments->map(fn ($r) => ['id' => (int) $r->id, 'date' => $r->value_date->toDateString(), 'amount_minor' => (int) $r->amount_minor,
            'penalty_minor' => (int) $r->penalty_minor, 'interest_minor' => (int) $r->interest_minor + (int) $r->interest_suspense_minor + (int) $r->interest_to_day_minor,
            'principal_minor' => (int) $r->principal_minor, 'channel' => $r->channel, 'transaction_id' => $r->transaction_id])->values()->all();

        return $out;
    }

    /** Loans by the flow's status, for the officers' lists. @param list<string>|null $statuses */
    public function list(int $companyId, ?array $statuses = null, ?int $memberId = null): array
    {
        $db = [];
        foreach ($statuses ?? [] as $s) {
            $db = array_merge($db, match ($s) {
                'disbursed' => ['active', 'disbursing', 'restructured'], 'pending_chair' => ['pending_chair', 'submitted', 'appraised', 'in_committee', 'appealed'],
                'declined_by_chair' => ['declined_by_chair', 'rejected'], 'not_disbursed' => ['not_disbursed', 'cancelled'], default => [$s]
            });
        }

        return SaccoLoan::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->where('status', '!=', 'draft')
            ->when($statuses !== null, fn ($q) => $q->whereIn('status', $db))->when($memberId !== null, fn ($q) => $q->where('member_id', $memberId))
            ->orderByDesc('id')->limit(500)->get()->map(fn ($l) => $this->present($l, false))->all();
    }

    /** Requests a member is asked to guarantee. @return list<array<string, mixed>> */
    public function guaranteeRequests(int $companyId, int $memberId): array
    {
        $ids = SaccoLoanGuarantor::withoutGlobalScopes()->where('company_id', $companyId)->where('guarantor_member_id', $memberId)->pluck('loan_id');

        return SaccoLoan::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('id', $ids)->orderByDesc('id')->get()
            ->map(fn ($l) => $this->present($l) + ['can_decide' => $l->status === 'pending_guarantor'])->all();
    }

    /** Whether a member may see a loan: theirs, or one they were asked to guarantee. */
    public static function memberMaySee(SaccoMember $member, SaccoLoan $loan): bool
    {
        return (int) $loan->company_id === (int) $member->company_id && ((int) $loan->member_id === (int) $member->id
            || SaccoLoanGuarantor::withoutGlobalScopes()->where('loan_id', $loan->id)->where('guarantor_member_id', $member->id)->exists());
    }

    /** The chairperson: the sacco_chair role, the seated chairperson, or the owner. */
    public function isChair(Company $company, User $user): bool
    {
        $cid = (int) $company->id;

        return (int) $company->owner_id === (int) $user->id || OfficerService::holds($cid, (int) $user->id, ['chairperson'])
            || DB::table('company_members')->where('company_id', $cid)->where('user_id', $user->id)->where('status', 'active')->where('role', 'sacco_chair')->exists();
    }

    /** @return list<User> */
    public function chairs(int $companyId): array
    {
        return $this->holders($companyId, 'sacco_chair', 'chairperson');
    }

    /** @return list<User> */
    public function treasurers(int $companyId): array
    {
        return $this->holders($companyId, 'sacco_treasurer', 'treasurer');
    }

    // ---------------------------------------------------------------- inside

    /** @return array<string, mixed> */
    private function quoteFor(SaccoLoan $loan): array
    {
        $terms = $loan->loanTerms();
        $today = LoanBooks::today((int) $loan->company_id);
        $facts = LoanProducts::keyFacts((int) ($loan->approved_minor ?? $loan->applied_minor), $terms, $today, Schedule::firstDueAfter($today, $terms->frequency), (string) $loan->currency);

        return ['monthly_instalment_minor' => (int) $facts['instalment_minor'], 'total_interest_minor' => (int) $facts['total_interest_minor'], 'total_payable_minor' => (int) $facts['total_repayable_minor'],
            'schedule' => array_map([self::class, 'line'], $facts['schedule'])];
    }

    /** A schedule line in the flow's words: principal, interest, the instalment and the balance after it. @param array<string, int|string> $l @return array<string, mixed> */
    public static function line(array $l): array
    {
        return ['no' => (int) $l['installment_no'], 'due_on' => (string) $l['due_on'], 'principal_minor' => (int) $l['principal_due_minor'], 'interest_minor' => (int) $l['interest_due_minor'],
            'instalment_minor' => (int) $l['instalment_minor'], 'balance_minor' => (int) $l['opening_principal_minor'] - (int) $l['principal_due_minor']];
    }

    /** @return list<User> */
    private function holders(int $companyId, string $role, string $position): array
    {
        $ids = DB::table('company_members')->where('company_id', $companyId)->where('status', 'active')->where('role', $role)->pluck('user_id')->all();
        $ids = array_merge($ids, OfficerService::active($companyId)->where('position', $position)->pluck('user_id')->filter()->all());
        if ($ids === []) {
            $ids[] = (int) Company::withoutGlobalScopes()->whereKey($companyId)->value('owner_id');
        }

        return User::withoutGlobalScopes()->whereIn('id', array_unique(array_map('intval', $ids)))->get()->all();
    }

    private function assertDecision(string $decision): void
    {
        if (! in_array($decision, ['approve', 'decline'], true)) {
            throw BusinessRuleException::make('invalid_decision', 'Choose approve or decline.');
        }
    }

    private function step(SaccoLoan $loan, string $step, ?User $user, ?SaccoMember $member, ?string $note): void
    {
        SaccoLoanStep::create(['company_id' => $loan->company_id, 'loan_id' => $loan->id, 'step' => $step, 'actor_type' => $member !== null ? 'member' : 'user',
            'actor_user_id' => $user?->id, 'actor_member_id' => $member?->id, 'actor_name' => mb_substr((string) ($member?->full_name ?? $user?->name ?? 'System'), 0, 150),
            'note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 500), 'created_at' => now()]);
    }

    /** A member's app notice, and (after commit) an SMS/WhatsApp in the SACCO's name. @param array<string, string|int> $vars */
    private function tellMember(SaccoLoan $loan, int $memberId, string $kind, string $title, string $body, array $vars, bool $message = true): void
    {
        SaccoMemberNotice::create(['company_id' => $loan->company_id, 'member_id' => $memberId, 'loan_id' => $loan->id, 'kind' => $kind, 'title' => $title,
            'body' => mb_substr($body, 0, 500), 'created_at' => now()]);
        if ($message && isset(LoanNotices::TEMPLATES[$kind])) {
            LoanNotices::toMember((int) $loan->company_id, $memberId, $kind, ['loan' => (string) $loan->loan_no] + $vars);
        }
    }

    /** Officers: an app notification and an SMS/WhatsApp to their phone, after commit. @param list<User> $users */
    private function tellStaff(SaccoLoan $loan, array $users, string $title, string $body): void
    {
        $cid = (int) $loan->company_id;
        DB::afterCommit(function () use ($cid, $loan, $users, $title, $body) {
            (new Notifier())->notify($cid, 'sacco_loans', $title, $body, ['loan_id' => (int) $loan->id], $users);
            $settings = LoanBooks::settings($cid);
            if (in_array($settings->receipts_channel, ['none', 'app'], true)) {
                return;
            }
            $name = $settings->short_name ?: $settings->legal_name;
            foreach ($users as $u) {
                if ($u->phone_e164) {
                    app(Messenger::class)->send($u->phone_e164, "{$name}: {$title}. {$body}", [$settings->receipts_channel === 'sms' ? 'sms' : 'whatsapp'],
                        ['company_id' => $cid, 'user_id' => $u->id, 'purpose' => 'sacco_notice', 'context' => ['template' => 'loan_step', 'loan_id' => (int) $loan->id]]);
                }
            }
        });
    }
}
