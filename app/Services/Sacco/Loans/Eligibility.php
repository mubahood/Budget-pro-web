<?php

namespace App\Services\Sacco\Loans;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanProduct;
use App\Models\SaccoMember;
use App\Models\SaccoOfficer;
use App\Models\SaccoSetting;
use App\Support\LocalDate;
use App\Support\Rules\SaccoLoanProductRules;
use Illuminate\Support\Facades\DB;

/**
 * Whether a member may borrow this (04 §4, P2.5; MobiSave's rules ported: one open loan, one pending application,
 * amount limits, cash on hand). Each rule is a finding with a code; a hard finding that fails stops the step it is
 * checked at, a soft one is shown to the appraiser and the committee.
 *
 * | Code | Rule | Checked at |
 * |---|---|---|
 * | member_not_active, kyc_required | an active member whose identity is verified (office mode) | submit |
 * | membership_too_new | the product's minimum days of membership (AK-50: 90) | submit |
 * | min_shares | the product's minimum shares | submit |
 * | loan_open | one open loan per member (a top-up settles the one it replaces) | submit, disburse |
 * | application_pending | one application on its way at a time | submit |
 * | savings_multiple | at most the product's multiple of free savings (shares excluded, Tier 4 s.48; a group counts shares) | submit |
 * | employee_loan_policy | an employee takes only a salary advance unless the SACCO's policy sends it to the committee (AK-43) | submit |
 * | cost_cap_exceeded | interest and fees within 100 % of the principal a year (SI 108 reg. 4(2)) | submit |
 * | repayment_capacity | the instalment within the member's monthly surplus and half their income | appraise (soft with an override note) |
 * | liquidity | cash, bank and mobile money on hand cover the loan | approve, disburse |
 * | single_borrower_limit | the member's loans within min(10 % of assets, 25 % of institutional capital) (Coop s.55AN(3)) | approve |
 * | guarantees_incomplete | guarantors consented in writing for the part above the member's own free savings (SI 108 reg. 10) | decide, disburse |
 * | insider | an officer's loan goes to the committee, the officer recused (reg. 38) | routing, not a refusal |
 */
final class Eligibility
{
    public const STAGES = ['submit', 'appraise', 'approve', 'disburse'];

    /** The most of a member's income their instalments may take (half). */
    public const MAX_DEBT_SERVICE_BP = 5000;

    /** Positions held by SACCO staff, not elected officers: the employees of AK-43. */
    public const EMPLOYEE_POSITIONS = ['manager', 'teller'];

    /**
     * @param  array{monthly_income_minor?: ?int, monthly_expenses_minor?: ?int, override_reason?: ?string}  $appraisal
     * @return list<array{code: string, ok: bool, hard: bool, message: string, value?: mixed, limit?: mixed}>
     */
    public function check(SaccoLoan $loan, string $stage, array $appraisal = []): array
    {
        $cid = (int) $loan->company_id;
        $settings = SaccoSetting::for($cid) ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
        $member = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($loan->member_id);
        $product = SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($loan->product_id);
        $amount = (int) ($loan->approved_minor ?? $loan->applied_minor);
        $terms = $loan->loanTerms();
        $today = LocalDate::today($cid);
        $out = [];
        $add = function (string $code, bool $ok, bool $hard, string $message, array $extra = []) use (&$out) {
            $out[] = ['code' => $code, 'ok' => $ok, 'hard' => $hard, 'message' => $message] + $extra;
        };
        $at = fn (string ...$stages) => in_array($stage, $stages, true);

        if ($at('submit', 'appraise')) {
            $add('member_not_active', $member->status === 'active', true, $member->status === 'active' ? 'An active member.' : "The member is {$member->status}.");
            if (! $settings->isGroup()) {
                $add('kyc_required', $member->kyc_status === 'verified', true, $member->kyc_status === 'verified' ? 'Identity verified.' : 'Verify the member\'s identity first.');
            }
            $days = (int) $member->joined_on->diffInDays($today);
            $add('membership_too_new', $days >= (int) $product->min_membership_days, true, "A member for {$days} days; the product asks for {$product->min_membership_days}.",
                ['value' => $days, 'limit' => (int) $product->min_membership_days]);
            if ((int) $product->min_shares > 0) {
                [$shares] = MemberFunds::shares($cid, (int) $member->id);
                $add('min_shares', $shares >= (int) $product->min_shares, true, "Holds {$shares} shares; the product asks for {$product->min_shares}.",
                    ['value' => $shares, 'limit' => (int) $product->min_shares]);
            }
            $pending = SaccoLoan::withoutGlobalScopes()->where('company_id', $cid)->where('member_id', $member->id)->whereIn('status', SaccoLoan::PENDING)
                ->where('id', '!=', $loan->id)->exists();
            $add('application_pending', ! $pending, true, $pending ? 'Another application of this member is still on its way.' : 'No other application on its way.');
            if ((int) $product->savings_multiplier_bp > 0) {
                $base = MemberFunds::lendingBase($cid, (int) $member->id);
                $limit = intdiv($base * (int) $product->savings_multiplier_bp, 10000);
                $add('savings_multiple', $amount <= $limit, true, 'At most '.bcdiv((string) $product->savings_multiplier_bp, '10000', 2)." × free savings ({$base}): {$limit}.",
                    ['value' => $amount, 'limit' => $limit]);
            }
            if ($this->isEmployee($cid, (int) $member->id)) {
                $policy = (string) $settings->employee_loans_policy;
                $ok = $product->is_salary_advance || $policy === 'committee';
                $add('employee_loan_policy', $ok, true, $ok ? 'An employee\'s loan: '.($product->is_salary_advance ? 'a salary advance.' : 'the committee decides (the SACCO\'s policy).')
                    : 'An employee may take a salary advance only (the SACCO\'s policy, AK-43).');
            }
            if (! $settings->isGroup()) {
                $cost = SaccoLoanProductRules::costPerYearBp($amount, $terms, (string) $loan->currency);
                $add('cost_cap_exceeded', $cost <= SaccoLoanProductRules::MAX_COST_PER_YEAR_BP, true, 'Interest and fees cost '.bcdiv((string) $cost, '100', 2).' % of the principal a year (at most 100 %).',
                    ['value' => $cost, 'limit' => SaccoLoanProductRules::MAX_COST_PER_YEAR_BP]);
            }
        }
        if ($at('submit', 'disburse')) {
            $open = SaccoLoan::withoutGlobalScopes()->where('company_id', $cid)->where('member_id', $member->id)->whereIn('status', SaccoLoan::OPEN)
                ->where('id', '!=', $loan->id)->when($loan->top_up_of_loan_id !== null, fn ($q) => $q->where('id', '!=', $loan->top_up_of_loan_id))->exists();
            $add('loan_open', ! $open, true, $open ? 'The member already has an open loan: one at a time (a top-up replaces it).' : 'No other open loan.');
        }
        if ($at('appraise')) {
            $income = $appraisal['monthly_income_minor'] ?? null;
            $expenses = (int) ($appraisal['monthly_expenses_minor'] ?? 0);
            $instalment = $this->monthlyInstalment($amount, $terms, (string) $loan->currency);
            if ($income === null) {
                $add('repayment_capacity', false, false, 'Record the member\'s monthly income and expenses to show they can repay.', ['value' => $instalment]);
            } else {
                $room = min((int) $income - $expenses, intdiv((int) $income * self::MAX_DEBT_SERVICE_BP, 10000));
                $ok = $instalment <= $room;
                $add('repayment_capacity', $ok, false, $ok ? "A monthly instalment of {$instalment} fits the member's room of {$room}."
                    : "A monthly instalment of {$instalment} is more than the member's room of {$room} (surplus, and half of income).", ['value' => $instalment, 'limit' => $room]);
            }
            $add('insider', true, false, $loan->is_insider || $member->is_insider ? 'An insider: the committee decides and the officer leaves the room.' : 'Not an insider.');
        }
        if ($at('approve', 'disburse')) {
            $liquid = self::liquidity($cid);
            $add('liquidity', $liquid >= $amount || $loan->disbursement_channel === 'savings', $stage === 'disburse', "Cash, bank and mobile money on hand: {$liquid}.", ['value' => $amount, 'limit' => $liquid]);
        }
        if ($at('approve') && ! $settings->isGroup()) {
            $exposure = $amount + (int) SaccoLoan::withoutGlobalScopes()->where('company_id', $cid)->where('member_id', $member->id)->whereIn('status', SaccoLoan::OPEN)
                ->where('id', '!=', $loan->id)->when($loan->top_up_of_loan_id !== null, fn ($q) => $q->where('id', '!=', $loan->top_up_of_loan_id))->sum('principal_outstanding_minor');
            $limit = self::singleBorrowerLimit($cid);
            $add('single_borrower_limit', $exposure <= $limit, true, "The member's loans would be {$exposure}; one borrower may have at most {$limit}.", ['value' => $exposure, 'limit' => $limit]);
        }
        if ($at('approve', 'disburse')) {
            [$needed, $consented, $count] = Guarantors::coverage($loan);
            $ok = $consented >= $needed && ($needed === 0 || $count >= (int) $product->guarantors_required);
            $add('guarantees_incomplete', $ok, $stage === 'disburse', $ok ? 'Guarantees cover the loan.'
                : "Guarantors have consented to {$consented} of the {$needed} to be guaranteed ({$count} of {$product->guarantors_required} guarantors).",
                ['value' => $consented, 'limit' => $needed]);
        }

        return $out;
    }

    /**
     * Check and throw the first hard failure (soft failures too, unless the appraiser gave an override reason).
     *
     * @param  array{monthly_income_minor?: ?int, monthly_expenses_minor?: ?int, override_reason?: ?string}  $appraisal
     * @return list<array{code: string, ok: bool, hard: bool, message: string}>
     */
    public function assert(SaccoLoan $loan, string $stage, array $appraisal = []): array
    {
        $findings = $this->check($loan, $stage, $appraisal);
        foreach ($findings as $f) {
            if (! $f['ok'] && ($f['hard'] || ($stage === 'appraise' && blank($appraisal['override_reason'] ?? null)))) {
                throw BusinessRuleException::make($f['code'], $f['message'], array_intersect_key($f, array_flip(['value', 'limit'])));
            }
        }

        return $findings;
    }

    /** A loan's instalment as a monthly figure (a weekly instalment × 52 ÷ 12), for the capacity test. */
    public function monthlyInstalment(int $amount, LoanTerms $terms, string $currency): int
    {
        $lines = Schedule::build($amount, $terms, '2026-01-31', $currency);
        $instalment = $lines[min(count($lines) - 1, $terms->principalGracePeriods)]->instalment();

        return intdiv($instalment * $terms->periodsPerYear() + 11, 12);
    }

    public function isEmployee(int $companyId, int $memberId): bool
    {
        return SaccoOfficer::withoutGlobalScopes()->where('company_id', $companyId)->where('member_id', $memberId)->whereIn('position', self::EMPLOYEE_POSITIONS)
            ->activeOn(LocalDate::today($companyId))->exists();
    }

    /** Cash in hand and tills, the lockbox, petty cash, bank and mobile-money float (not collections still in transit). */
    public static function liquidity(int $companyId): int
    {
        $roots = DB::table('sacco_ledger_accounts')->where('company_id', $companyId)->whereIn('system_key', ['cash.office', 'cash.box', 'cash.petty', 'bank.main', 'momo.float'])->pluck('id');
        $ids = DB::table('sacco_ledger_accounts')->where('company_id', $companyId)->where(fn ($q) => $q->whereIn('id', $roots)->orWhereIn('parent_id', $roots))->pluck('id');

        return (int) DB::table('sacco_account_balances')->where('company_id', $companyId)->whereIn('ledger_account_id', $ids)->sum('balance_minor');
    }

    /** Total assets, net of the contra accounts (the allowance for loan losses, depreciation). */
    public static function totalAssets(int $companyId): int
    {
        return (int) DB::table('sacco_account_balances as b')->join('sacco_ledger_accounts as a', 'a.id', '=', 'b.ledger_account_id')
            ->where('b.company_id', $companyId)->where('a.type', 'asset')
            ->selectRaw("COALESCE(SUM(CASE WHEN a.normal_side = 'credit' THEN -b.balance_minor ELSE b.balance_minor END), 0) AS v")->value('v');
    }

    /** Institutional capital: reserves, retained earnings, grants and this year's surplus so far (share capital excluded). */
    public static function institutionalCapital(int $companyId): int
    {
        $row = DB::table('sacco_account_balances as b')->join('sacco_ledger_accounts as a', 'a.id', '=', 'b.ledger_account_id')->where('b.company_id', $companyId)
            ->selectRaw("COALESCE(SUM(CASE WHEN a.type = 'equity' AND (a.system_key IS NULL OR a.system_key NOT IN ('shares.capital', 'opening')) THEN b.balance_minor ELSE 0 END), 0) AS eq,
                COALESCE(SUM(CASE WHEN a.type = 'income' THEN b.balance_minor ELSE 0 END), 0) AS inc,
                COALESCE(SUM(CASE WHEN a.type = 'expense' THEN b.balance_minor ELSE 0 END), 0) AS exp")->first();

        return (int) $row->eq + (int) $row->inc - (int) $row->exp;
    }

    /**
     * The most one member may owe (Coop s.55AN(3)): the smaller of 10 % of total assets and 25 % of institutional
     * capital. A young SACCO with no institutional capital yet is held to 10 % of its assets (AK-82).
     */
    public static function singleBorrowerLimit(int $companyId): int
    {
        $assets = intdiv(max(0, self::totalAssets($companyId)), 10);
        $capital = self::institutionalCapital($companyId);

        return $capital > 0 ? min($assets, intdiv($capital, 4)) : $assets;
    }
}
