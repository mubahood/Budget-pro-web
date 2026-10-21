<?php

namespace App\Services\Sacco\Loans;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanProduct;
use App\Models\SaccoMember;
use App\Models\User;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoAudit;
use App\Services\Shop\NumberSequencer;
use App\Support\Rules\SaccoRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A loan from application to appraisal (04 §5.3, P2.6). A draft may be started by an officer, by the member in their
 * own sign-in, or on a phone offline (only `draft` comes from a phone, AK-23); it copies its product's terms at once
 * (the snapshot). Submitting numbers it (LN-yyyy-nnnnnn), checks Eligibility's submission rules and copies the
 * member's insider flag; appraising records the member's capacity, the findings and the recommended amount and
 * term. A member's own application is submitted as it is made. Each step is audited.
 */
final class LoanApplications
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(bool $creating = true): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'uuid' => ['nullable', 'uuid'],
            'member_id' => [$req, 'integer'],
            'product_id' => [$req, 'integer'],
            'applied_minor' => SaccoRules::minor($creating, 1),
            'term' => [$req, 'integer', 'min:1', 'max:600'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:255'],
            'purpose_category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'inputs_share_bp' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'disbursement_channel' => ['sometimes', 'nullable', Rule::in(SaccoLoan::CHANNELS)],
            'disburse_to_savings_account_id' => ['sometimes', 'nullable', 'integer'],
            'momo_phone_e164' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    /**
     * Start an application (a draft), or with opts.submit make and submit it. Idempotent on uuid.
     *
     * @param  array<string, mixed>  $data  rules()
     * @param  array{source?: string, member_actor_id?: ?int, submit?: bool, device_id?: ?string, applied_on?: ?string}  $opts
     */
    public function create(Company $company, array $data, ?User $by, array $opts = []): SaccoLoan
    {
        $cid = (int) $company->id;
        if ($by !== null) {
            SaccoAccess::authorize($by, 'sacco_loans');
        } elseif (empty($opts['member_actor_id'])) {
            throw BusinessRuleException::make('forbidden', 'An officer or the member must make the application.', ['permission' => 'sacco_loans']);
        }
        $d = $this->validated($data, true);
        $uuid = strtolower((string) ($d['uuid'] ?? Str::uuid()));
        $existing = SaccoLoan::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first();
        if ($existing !== null) {
            if ((int) $existing->member_id !== (int) $d['member_id'] || (int) $existing->applied_minor !== (int) $d['applied_minor'] || (int) $existing->product_id !== (int) $d['product_id']) {
                throw BusinessRuleException::make('idempotency_conflict', 'That application was already made with other details.', ['loan_id' => $existing->id]);
            }

            return $existing;
        }
        $settings = LoanBooks::settings($cid);

        $loan = DB::transaction(function () use ($cid, $d, $by, $opts, $uuid, $settings) {
            $member = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->find((int) $d['member_id'])
                ?? throw BusinessRuleException::make('member_not_found', 'That member is not in this SACCO.');
            $product = SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $cid)->find((int) $d['product_id'])
                ?? throw BusinessRuleException::make('product_not_found', 'That loan product is not in this SACCO.');
            LoanProducts::assertFits($product, (int) $d['applied_minor'], (int) $d['term']);
            $this->assertChannel($product, $d['disbursement_channel'] ?? null);
            $this->assertInputs($product, $d['inputs_share_bp'] ?? null);
            $loan = SaccoLoan::create(array_merge(array_intersect_key($d, array_flip(['applied_minor', 'term', 'purpose', 'purpose_category', 'inputs_share_bp',
                'disbursement_channel', 'disburse_to_savings_account_id', 'momo_phone_e164'])), [
                    'company_id' => $cid, 'uuid' => $uuid, 'member_id' => $member->id, 'product_id' => $product->id, 'status' => 'draft',
                    'source' => $opts['source'] ?? ($by !== null ? 'staff' : 'member'), 'currency' => $settings->currency,
                    'terms' => $product->terms((int) $d['term'], (string) $settings->allocation_order)->toArray(), 'officer_id' => $by?->id,
                    'member_actor_id' => $opts['member_actor_id'] ?? null, 'device_id' => $opts['device_id'] ?? null, 'created_by_id' => $by?->id,
                    'created_at' => isset($opts['applied_on']) ? \Illuminate\Support\Carbon::parse($opts['applied_on']) : now(),
                ]));
            SaccoAudit::record('sacco.loan.drafted', $loan, $cid, ['source' => $loan->source, 'applied_minor' => (int) $loan->applied_minor], $by?->id);

            return $loan;
        });

        return ! empty($opts['submit']) ? $this->submit($company, (int) $loan->id, $by, $opts['member_actor_id'] ?? null, $opts['applied_on'] ?? null) : $loan;
    }

    /** The member applies in their own sign-in (AK-61): made and submitted at once, as theirs. @param array<string, mixed> $data */
    public function applyAsMember(Company $company, SaccoMember $member, array $data): SaccoLoan
    {
        if ((int) $member->company_id !== (int) $company->id) {
            throw BusinessRuleException::make('member_not_found', 'That member is not in this SACCO.');
        }
        unset($data['disburse_to_savings_account_id']);

        return $this->create($company, ['member_id' => (int) $member->id] + $data, null, ['source' => 'member', 'member_actor_id' => (int) $member->id, 'submit' => true]);
    }

    /** Change a draft (or a submitted application before appraisal). @param array<string, mixed> $data */
    public function update(Company $company, int $loanId, array $data, ?User $by, ?int $memberActorId = null): SaccoLoan
    {
        $this->actor($by, $memberActorId);
        $cid = (int) $company->id;
        $d = $this->validated($data, false);
        unset($d['uuid'], $d['member_id']);

        return DB::transaction(function () use ($cid, $loanId, $d, $by, $memberActorId) {
            $loan = LoanBooks::lock($cid, $loanId);
            $this->assertOwn($loan, $memberActorId);
            LoanBooks::assertStatus($loan, ['draft', 'submitted'], 'changed now');
            $product = SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($d['product_id'] ?? $loan->product_id);
            $term = (int) ($d['term'] ?? $loan->term);
            LoanProducts::assertFits($product, (int) ($d['applied_minor'] ?? $loan->applied_minor), $term);
            $this->assertChannel($product, array_key_exists('disbursement_channel', $d) ? $d['disbursement_channel'] : $loan->disbursement_channel);
            $this->assertInputs($product, array_key_exists('inputs_share_bp', $d) ? $d['inputs_share_bp'] : $loan->inputs_share_bp);
            $loan->fill($d + ['updated_by_id' => $by?->id, 'version' => (int) $loan->version + 1]);
            if (isset($d['product_id']) || isset($d['term'])) {
                $loan->terms = $product->terms($term, (string) LoanBooks::settings($cid)->allocation_order)->toArray();
            }
            $loan->save();

            return $loan;
        });
    }

    /** Submit: numbered, checked against Eligibility's submission rules, the insider flag copied. */
    public function submit(Company $company, int $loanId, ?User $by, ?int $memberActorId = null, ?string $on = null): SaccoLoan
    {
        $this->actor($by, $memberActorId);
        $cid = (int) $company->id;

        return DB::transaction(function () use ($cid, $loanId, $by, $memberActorId, $on) {
            $loan = LoanBooks::lock($cid, $loanId);
            $this->assertOwn($loan, $memberActorId);
            LoanBooks::assertStatus($loan, ['draft'], 'submitted');
            SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->whereKey($loan->member_id)->lockForUpdate()->first(); // one pending application at a time
            (new Eligibility())->assert($loan, 'submit');
            $member = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($loan->member_id);
            $at = $on !== null ? \Illuminate\Support\Carbon::parse($on) : now();
            $loan->update(['status' => 'submitted', 'loan_no' => $loan->loan_no ?? NumberSequencer::next($cid, 'ln', $at), 'submitted_at' => $at,
                'is_insider' => (bool) $member->is_insider || (new Eligibility())->isEmployee($cid, (int) $member->id), 'updated_by_id' => $by?->id]);
            SaccoAudit::record('sacco.loan.submitted', $loan, $cid, ['loan_no' => $loan->loan_no, 'by_member' => $memberActorId], $by?->id);

            return $loan;
        });
    }

    /**
     * Appraise (sacco_loans): the member's monthly income and expenses, the officer's notes and recommendation, and
     * Eligibility's findings, kept on the loan for the committee. A repayment-capacity shortfall needs an override
     * reason. The recommended amount and term may be lower than asked, within the product.
     *
     * @param  array{monthly_income_minor?: ?int, monthly_expenses_minor?: ?int, notes?: ?string, recommended_minor?: ?int, term?: ?int, override_reason?: ?string}  $appraisal
     */
    public function appraise(Company $company, int $loanId, User $by, array $appraisal): SaccoLoan
    {
        SaccoAccess::authorize($by, 'sacco_loans');
        $cid = (int) $company->id;
        $v = validator($appraisal, ['monthly_income_minor' => SaccoRules::minor(false), 'monthly_expenses_minor' => SaccoRules::minor(false),
            'recommended_minor' => SaccoRules::minor(false, 1), 'term' => ['sometimes', 'integer', 'min:1'], 'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'override_reason' => ['sometimes', 'nullable', 'string', 'max:255'], 'on' => ['sometimes', 'date']]);
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_appraisal', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }
        $a = $v->validated();

        return DB::transaction(function () use ($cid, $loanId, $by, $a) {
            $loan = LoanBooks::lock($cid, $loanId);
            LoanBooks::assertStatus($loan, ['submitted', 'appraised'], 'appraised');
            $product = SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($loan->product_id);
            $amount = (int) ($a['recommended_minor'] ?? $loan->applied_minor);
            $term = (int) ($a['term'] ?? $loan->term);
            if ($amount > (int) $loan->applied_minor) {
                throw BusinessRuleException::make('invalid_appraisal', 'Recommend at most what the member asked for.');
            }
            LoanProducts::assertFits($product, $amount, $term);
            $loan->approved_minor = $amount;
            $loan->terms = $loan->loanTerms()->with($term)->toArray();
            $findings = (new Eligibility())->assert($loan, 'appraise', $a);
            [$count, $value] = MemberFunds::shares($cid, (int) $loan->member_id);
            $at = isset($a['on']) ? \Illuminate\Support\Carbon::parse($a['on']) : now();
            $loan->fill(['status' => 'appraised', 'appraised_by_id' => $by->id, 'appraised_at' => $at, 'updated_by_id' => $by->id, 'appraisal' => [
                'monthly_income_minor' => $a['monthly_income_minor'] ?? null, 'monthly_expenses_minor' => $a['monthly_expenses_minor'] ?? null,
                'notes' => $a['notes'] ?? null, 'override_reason' => $a['override_reason'] ?? null, 'recommended_minor' => $amount, 'term' => $term,
                'free_savings_minor' => MemberFunds::freeSavings($cid, (int) $loan->member_id), 'shares' => $count, 'shares_minor' => $value,
                'exposure' => Guarantors::exposure($cid, (int) $loan->member_id), 'findings' => $findings,
                'key_facts' => array_diff_key(LoanProducts::keyFacts($amount, $loan->loanTerms(), $at, Schedule::firstDueAfter($at, $loan->loanTerms()->frequency), (string) $loan->currency), ['schedule' => 1]),
            ]])->save();
            SaccoAudit::record('sacco.loan.appraised', $loan, $cid, ['recommended_minor' => $amount, 'term' => $term], (int) $by->id);

            return $loan;
        });
    }

    /** The applicant (or an officer) withdraws an application before it is decided. */
    public function withdraw(Company $company, int $loanId, ?User $by, string $reason, ?int $memberActorId = null): SaccoLoan
    {
        $this->actor($by, $memberActorId);
        $cid = (int) $company->id;

        return DB::transaction(function () use ($cid, $loanId, $by, $reason, $memberActorId) {
            $loan = LoanBooks::lock($cid, $loanId);
            $this->assertOwn($loan, $memberActorId);
            LoanBooks::assertStatus($loan, ['draft', 'submitted', 'appraised'], 'withdrawn');
            $loan->update(['status' => 'cancelled', 'cancelled_on' => LoanBooks::today($cid)->toDateString(), 'decision_note' => mb_substr($reason, 0, 255), 'updated_by_id' => $by?->id]);
            (new Guarantors())->releaseAll($loan, 'The application was withdrawn.', $by?->id);
            SaccoAudit::record('sacco.loan.withdrawn', $loan, $cid, ['reason' => $reason], $by?->id);

            return $loan;
        });
    }

    private function actor(?User $by, ?int $memberActorId): void
    {
        if ($by !== null) {
            SaccoAccess::authorize($by, 'sacco_loans');
        } elseif ($memberActorId === null) {
            throw BusinessRuleException::make('forbidden', 'An officer or the member must do this.', ['permission' => 'sacco_loans']);
        }
    }

    private function assertOwn(SaccoLoan $loan, ?int $memberActorId): void
    {
        if ($memberActorId !== null && (int) $loan->member_id !== $memberActorId) {
            throw BusinessRuleException::make('loan_not_found', 'That loan is not in this SACCO.');
        }
    }

    private function assertChannel(SaccoLoanProduct $product, ?string $channel): void
    {
        if ($channel !== null && ! in_array($channel, (array) $product->allowed_channels, true)) {
            throw BusinessRuleException::make('channel_not_allowed', "{$product->name} is not paid out by {$channel}.", ['allowed' => $product->allowed_channels]);
        }
    }

    private function assertInputs(SaccoLoanProduct $product, mixed $share): void
    {
        if ($product->inputs_share_min_bp !== null && $share !== null && (int) $share < (int) $product->inputs_share_min_bp) {
            throw BusinessRuleException::make('inputs_share_too_low', 'At least '.bcdiv((string) $product->inputs_share_min_bp, '100', 0).' % of this loan goes on inputs.');
        }
    }

    /** @param  array<string, mixed>  $data @return array<string, mixed> */
    private function validated(array $data, bool $creating): array
    {
        $v = validator($data, self::rules($creating));
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_loan', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }

        return $v->validated();
    }
}
