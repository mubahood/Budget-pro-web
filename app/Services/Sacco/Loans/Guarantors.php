<?php

namespace App\Services\Sacco\Loans;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanGuarantor;
use App\Models\SaccoLoanProduct;
use App\Models\SaccoMember;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Sacco\OfficerService;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Guarantors (SI 108 reg. 10, Coop s.55AP(3), AK-48, AK-49). Each guarantor is told in writing what they may owe and
 * consents in writing: a signed form an officer uploads, the signed link sent by SMS with a fresh code (the portal's
 * public consent page), or their own member sign-in with a fresh code. Consent places a lien on their savings for
 * the amount guaranteed (available() falls by it), released when the loan is paid, cancelled or the guarantee
 * dropped. Officers, staff and the board may not guarantee; a member guarantees at most their free savings and at
 * most three loans at once (the product may set fewer).
 */
final class Guarantors
{
    /** Loan statuses while guarantors may still be added or changed. */
    public const EDITABLE = ['draft', 'submitted', 'appraised', 'in_committee', 'approved', 'appealed', 'signed'];

    /** A guarantee that still binds. */
    public const LIVE = ['requested', 'consented'];

    public const LINK_DAYS = 7;

    public function add(Company $company, int $loanId, int $guarantorMemberId, int $amountMinor, User $by): SaccoLoanGuarantor
    {
        SaccoAccess::authorize($by, 'sacco_loans');
        $cid = (int) $company->id;
        if ($amountMinor < 1) {
            throw BusinessRuleException::make('invalid_amount', 'Say how much this member guarantees.');
        }

        [$g, $token] = DB::transaction(function () use ($cid, $loanId, $guarantorMemberId, $amountMinor, $by) {
            $loan = LoanBooks::lock($cid, $loanId);
            LoanBooks::assertStatus($loan, self::EDITABLE, 'given guarantors now');
            $guarantor = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->lockForUpdate()->find($guarantorMemberId)
                ?? throw BusinessRuleException::make('member_not_found', 'That member is not in this SACCO.');
            $this->assertMayGuarantee($loan, $guarantor, $amountMinor);
            if (SaccoLoanGuarantor::withoutGlobalScopes()->where('loan_id', $loan->id)->where('guarantor_member_id', $guarantor->id)->exists()) {
                throw BusinessRuleException::make('guarantor_exists', "{$guarantor->full_name} is already a guarantor of this loan.");
            }
            $token = Str::random(48);
            $g = SaccoLoanGuarantor::create(['company_id' => $cid, 'loan_id' => $loan->id, 'guarantor_member_id' => $guarantor->id, 'guaranteed_minor' => $amountMinor,
                'consent_status' => 'requested', 'notice_sent_at' => now(), 'consent_token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDays(self::LINK_DAYS),
                'requested_by_id' => $by->id]);
            SaccoAudit::record('sacco.loan.guarantor_requested', $g, $cid, ['loan_id' => $loan->id, 'amount_minor' => $amountMinor], (int) $by->id);
            $this->tell($loan, $g, $token);

            return [$g, $token];
        });

        return $g->setAttribute('consent_link', self::link($token));
    }

    /** An officer records the guarantor's signed consent form (the upload's file id). */
    public function consentWithForm(Company $company, int $guarantorId, int $fileId, User $by): SaccoLoanGuarantor
    {
        SaccoAccess::authorize($by, 'sacco_loans');
        if (! \App\Models\StoredFile::withoutGlobalScopes()->where('company_id', $company->id)->whereKey($fileId)->exists()) {
            throw BusinessRuleException::make('file_not_found', 'Upload the signed consent form first.');
        }

        return $this->consent((int) $company->id, $guarantorId, 'signed_form', ['consent_evidence_file_id' => $fileId], (int) $by->id);
    }

    /** The guarantor's notice on the public page (by the opaque token in the link): nothing about anyone else. @return array<string, mixed> */
    public static function notice(string $token): array
    {
        $g = self::byToken($token);
        $loan = SaccoLoan::withoutGlobalScopes()->findOrFail($g->loan_id);
        $borrower = SaccoMember::withoutGlobalScopes()->findOrFail($loan->member_id);
        $settings = LoanBooks::settings((int) $g->company_id);
        $terms = $loan->loanTerms();

        return ['sacco' => $settings->short_name, 'borrower' => $borrower->first_name, 'guaranteed_minor' => (int) $g->guaranteed_minor,
            'loan_minor' => (int) ($loan->approved_minor ?? $loan->applied_minor), 'currency' => $loan->currency, 'term' => $terms->term, 'frequency' => $terms->frequency,
            'status' => $g->consent_status, 'expires_at' => $g->token_expires_at?->toIso8601String(),
            'liability' => 'If '.$borrower->first_name.' does not repay, the SACCO may recover up to the amount you guarantee from your savings and other money you hold in the SACCO (Cooperative Societies Act s.55AK). Your savings stay yours, but this amount is held and cannot be withdrawn until the loan is repaid or you are released.'];
    }

    /** Send a fresh code to the guarantor's phone for the public page. @return array{sent: bool, sent_to: ?string} */
    public function requestCode(string $token, ?string $ip = null): array
    {
        $g = self::byToken($token);
        $this->assertOpen($g);
        $member = SaccoMember::withoutGlobalScopes()->findOrFail($g->guarantor_member_id);
        $to = $member->phone_e164 ?: $member->email;
        if (blank($to)) {
            throw BusinessRuleException::make('no_contact', 'This guarantor has no phone or email on record: an officer records a signed form instead.');
        }
        $r = app(OtpService::class)->request(self::otpKey($g), 'verify', $ip, (string) $to, self::sender((int) $g->company_id));

        return ['sent' => $r['sent'], 'sent_to' => $r['sent_to']];
    }

    /** The guarantor consents on the public page with the code just sent (written electronic consent, reg. 10). */
    public function consentByLink(string $token, string $code): SaccoLoanGuarantor
    {
        $g = self::byToken($token);
        $this->assertOpen($g);
        app(OtpService::class)->verify(self::otpKey($g), 'verify', $code);

        return $this->consent((int) $g->company_id, (int) $g->id, 'sms_link', ['consent_otp_ref' => 'otp:'.substr(hash('sha256', $code.$g->id), 0, 16)], null);
    }

    public function declineByLink(string $token, string $code, ?string $reason = null): SaccoLoanGuarantor
    {
        $g = self::byToken($token);
        $this->assertOpen($g);
        app(OtpService::class)->verify(self::otpKey($g), 'verify', $code);

        return $this->decline((int) $g->company_id, (int) $g->id, null, $reason ?: 'Declined on the consent page.');
    }

    /** The guarantor consents in their own member sign-in, with a fresh code sent to them (AK-61). */
    public function consentAsMember(Company $company, int $guarantorId, SaccoMember $member, string $code): SaccoLoanGuarantor
    {
        $g = SaccoLoanGuarantor::withoutGlobalScopes()->where('company_id', $company->id)->find($guarantorId);
        if ($g === null || (int) $g->guarantor_member_id !== (int) $member->id) {
            throw BusinessRuleException::make('guarantee_not_found', 'That guarantee request is not yours.');
        }
        $this->assertOpen($g);
        app(OtpService::class)->verify(self::otpKey($g), 'verify', $code);

        return $this->consent((int) $company->id, $guarantorId, 'member_app', ['consent_otp_ref' => 'otp:'.substr(hash('sha256', $code.$g->id), 0, 16)], null);
    }

    /** Send the member-app code (the same code flow as the public page). @return array{sent: bool, sent_to: ?string} */
    public function requestMemberCode(Company $company, int $guarantorId, SaccoMember $member): array
    {
        $g = SaccoLoanGuarantor::withoutGlobalScopes()->where('company_id', $company->id)->find($guarantorId);
        if ($g === null || (int) $g->guarantor_member_id !== (int) $member->id) {
            throw BusinessRuleException::make('guarantee_not_found', 'That guarantee request is not yours.');
        }
        $this->assertOpen($g);
        $r = app(OtpService::class)->request(self::otpKey($g), 'verify', null, (string) ($member->phone_e164 ?: $member->email), self::sender((int) $company->id));

        return ['sent' => $r['sent'], 'sent_to' => $r['sent_to']];
    }

    public function decline(int $companyId, int $guarantorId, ?User $by, string $reason): SaccoLoanGuarantor
    {
        return DB::transaction(function () use ($companyId, $guarantorId, $by, $reason) {
            $g = SaccoLoanGuarantor::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->find($guarantorId)
                ?? throw BusinessRuleException::make('guarantee_not_found', 'That guarantee is not in this SACCO.');
            if ($g->consent_status !== 'requested') {
                throw BusinessRuleException::make('guarantee_closed', "This guarantee is {$g->consent_status}.");
            }
            $g->update(['consent_status' => 'declined', 'declined_at' => now(), 'consent_token_hash' => null]);
            SaccoAudit::record('sacco.loan.guarantor_declined', $g, $companyId, ['reason' => mb_substr($reason, 0, 200)], $by?->id);

            return $g;
        });
    }

    /** Drop a guarantor before the money is paid out (an officer's change): the lien, if any, is released. */
    public function remove(Company $company, int $guarantorId, User $by, string $reason): SaccoLoanGuarantor
    {
        SaccoAccess::authorize($by, 'sacco_loans');

        return DB::transaction(function () use ($company, $guarantorId, $by, $reason) {
            $g = SaccoLoanGuarantor::withoutGlobalScopes()->where('company_id', $company->id)->lockForUpdate()->find($guarantorId)
                ?? throw BusinessRuleException::make('guarantee_not_found', 'That guarantee is not in this SACCO.');
            $loan = LoanBooks::lock((int) $company->id, (int) $g->loan_id);
            LoanBooks::assertStatus($loan, self::EDITABLE, 'given other guarantors now');
            $this->releaseOne($g, $reason, (int) $by->id);

            return $g->fresh();
        });
    }

    /** Release every guarantee of a loan (paid, cancelled, rejected, written off and recovered): the liens go. */
    public function releaseAll(SaccoLoan $loan, string $reason, ?int $byUserId = null): void
    {
        SaccoLoanGuarantor::withoutGlobalScopes()->where('loan_id', $loan->id)->whereIn('consent_status', self::LIVE)->orderBy('id')->lockForUpdate()->get()
            ->each(fn (SaccoLoanGuarantor $g) => $this->releaseOne($g, $reason, $byUserId));
    }

    /**
     * What the loan needs guaranteed and what is: the part above the borrower's own free savings times the product's
     * cover (when it asks for guarantors), the consented total and the number of consented guarantors.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function coverage(SaccoLoan $loan): array
    {
        $cid = (int) $loan->company_id;
        $product = SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $cid)->find($loan->product_id);
        $amount = (int) ($loan->approved_minor ?? $loan->applied_minor);
        $needed = 0;
        if ($product !== null && (int) $product->guarantors_required > 0) {
            $own = MemberFunds::freeSavings($cid, (int) $loan->member_id);
            $needed = intdiv(max(0, $amount - $own) * (int) $product->guarantee_cover_bp, 10000);
        }
        $consented = SaccoLoanGuarantor::withoutGlobalScopes()->where('loan_id', $loan->id)->where('consent_status', 'consented');

        return [$needed, (int) (clone $consented)->sum('guaranteed_minor'), (clone $consented)->count()];
    }

    /** A member's live guarantees (for capacity and the guarantor-exposure report). @return array{count: int, guaranteed_minor: int, held_minor: int} */
    public static function exposure(int $companyId, int $memberId): array
    {
        $q = SaccoLoanGuarantor::withoutGlobalScopes()->where('company_id', $companyId)->where('guarantor_member_id', $memberId)->whereIn('consent_status', self::LIVE);

        return ['count' => (clone $q)->count(), 'guaranteed_minor' => (int) (clone $q)->sum('guaranteed_minor'), 'held_minor' => (int) (clone $q)->sum('held_minor')];
    }

    /** The public consent page's address (the portal route /sacco/consent/{token}). */
    public static function link(string $token): string
    {
        $base = rtrim((string) (config('saas.new_ui_url') ?: config('saas.public_url')), '/');

        return $base.'/sacco/consent/'.$token;
    }

    /** Send a fresh link (the old one stops working). */
    public function resendLink(Company $company, int $guarantorId, User $by): string
    {
        SaccoAccess::authorize($by, 'sacco_loans');

        return DB::transaction(function () use ($company, $guarantorId) {
            $g = SaccoLoanGuarantor::withoutGlobalScopes()->where('company_id', $company->id)->lockForUpdate()->find($guarantorId)
                ?? throw BusinessRuleException::make('guarantee_not_found', 'That guarantee is not in this SACCO.');
            if ($g->consent_status !== 'requested') {
                throw BusinessRuleException::make('guarantee_closed', "This guarantee is {$g->consent_status}.");
            }
            $token = Str::random(48);
            $g->update(['consent_token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDays(self::LINK_DAYS), 'notice_sent_at' => now()]);
            $this->tell(LoanBooks::find((int) $company->id, (int) $g->loan_id), $g, $token);

            return self::link($token);
        });
    }

    private function assertMayGuarantee(SaccoLoan $loan, SaccoMember $guarantor, int $amountMinor): void
    {
        $cid = (int) $loan->company_id;
        if ((int) $guarantor->id === (int) $loan->member_id) {
            throw BusinessRuleException::make('guarantor_not_allowed', 'A borrower cannot guarantee their own loan.');
        }
        if (! in_array($guarantor->status, ['active', 'dormant'], true)) {
            throw BusinessRuleException::make('guarantor_not_allowed', "A member who is {$guarantor->status} cannot guarantee a loan.");
        }
        if (OfficerService::memberHoldsOffice($cid, (int) $guarantor->id)) {
            throw BusinessRuleException::make('guarantor_not_allowed', 'Officers, staff and board members may not guarantee loans (Cooperative Societies Act s.55AP(3)).');
        }
        $product = SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($loan->product_id);
        $exposure = self::exposure($cid, (int) $guarantor->id);
        if ($exposure['count'] >= (int) $product->max_guarantees_per_guarantor) {
            throw BusinessRuleException::make('guarantee_limit', "{$guarantor->full_name} already guarantees {$exposure['count']} loans, the most allowed.");
        }
        $free = MemberFunds::freeSavings($cid, (int) $guarantor->id) - ($exposure['guaranteed_minor'] - $exposure['held_minor']);
        if ($amountMinor > $free) {
            throw BusinessRuleException::make('guarantee_capacity', "{$guarantor->full_name} can guarantee at most {$free} from their free savings.", ['free_minor' => max(0, $free)]);
        }
    }

    /** @param  array<string, mixed>  $evidence */
    private function consent(int $companyId, int $guarantorId, string $channel, array $evidence, ?int $byUserId): SaccoLoanGuarantor
    {
        return DB::transaction(function () use ($companyId, $guarantorId, $channel, $evidence, $byUserId) {
            $g = SaccoLoanGuarantor::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->find($guarantorId)
                ?? throw BusinessRuleException::make('guarantee_not_found', 'That guarantee is not in this SACCO.');
            $this->assertOpen($g);
            $loan = LoanBooks::lock($companyId, (int) $g->loan_id);
            LoanBooks::assertStatus($loan, self::EDITABLE, 'guaranteed now');
            $account = MemberFunds::hold($companyId, (int) $g->guarantor_member_id, (int) $g->guaranteed_minor);
            $g->update(['consent_status' => 'consented', 'consent_channel' => $channel, 'consented_at' => now(), 'consent_token_hash' => null,
                'hold_savings_account_id' => $account, 'held_minor' => (int) $g->guaranteed_minor] + $evidence);
            SaccoAudit::record('sacco.loan.guarantor_consented', $g, $companyId, ['channel' => $channel, 'held_minor' => (int) $g->guaranteed_minor, 'account_id' => $account], $byUserId);

            return $g->fresh();
        });
    }

    private function releaseOne(SaccoLoanGuarantor $g, string $reason, ?int $byUserId): void
    {
        if (! in_array($g->consent_status, self::LIVE, true)) {
            return;
        }
        if ($g->hold_savings_account_id !== null && (int) $g->held_minor > 0) {
            MemberFunds::release((int) $g->company_id, (int) $g->hold_savings_account_id, (int) $g->held_minor);
        }
        $g->update(['consent_status' => 'released', 'released_at' => now(), 'held_minor' => 0, 'consent_token_hash' => null]);
        SaccoAudit::record('sacco.loan.guarantor_released', $g, (int) $g->company_id, ['reason' => mb_substr($reason, 0, 200)], $byUserId);
    }

    private function assertOpen(SaccoLoanGuarantor $g): void
    {
        if ($g->consent_status !== 'requested') {
            throw BusinessRuleException::make('guarantee_closed', "This guarantee is already {$g->consent_status}.");
        }
    }

    private static function byToken(string $token): SaccoLoanGuarantor
    {
        $g = strlen($token) >= 32 ? SaccoLoanGuarantor::withoutGlobalScopes()->where('consent_token_hash', hash('sha256', $token))->first() : null;
        if ($g === null || $g->token_expires_at === null || $g->token_expires_at->isPast()) {
            throw BusinessRuleException::make('link_invalid', 'This link has expired or was replaced. Ask the SACCO for a new one.');
        }

        return $g;
    }

    /** Codes and notices go out in the SACCO's own name (Akiba Ledger is independent of the family). */
    private static function sender(int $companyId): string
    {
        return LoanBooks::settings($companyId)->short_name ?: 'Akiba Ledger';
    }

    private static function otpKey(SaccoLoanGuarantor $g): string
    {
        return 'sacco_guarantee:'.$g->id;
    }

    private function tell(SaccoLoan $loan, SaccoLoanGuarantor $g, string $token): void
    {
        $cid = (int) $loan->company_id;
        $borrower = SaccoMember::withoutGlobalScopes()->find($loan->member_id);
        LoanNotices::toMember($cid, (int) $g->guarantor_member_id, 'guarantor_request', ['borrower' => (string) ($borrower?->first_name ?? ''),
            'amount' => LoanBooks::fmt($cid, (int) $g->guaranteed_minor), 'loan_amount' => LoanBooks::fmt($cid, (int) ($loan->approved_minor ?? $loan->applied_minor)),
            'link' => self::link($token)]);
    }
}
