<?php

namespace App\Http\Controllers\Api\V1\Sacco\Loans;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanGuarantor;
use App\Models\SaccoLoanScheduleLine;
use App\Models\SaccoMember;
use App\Services\Sacco\Loans\Guarantors;
use App\Services\Sacco\Loans\LoanApplications;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Loans for members themselves (04 §6.4, §13.2; AK-19, AK-61) and the guarantor's public consent page. Every /me query
 * is keyed by the token's member, never a request parameter: another member's loan answers 404. The consent link
 * carries only an opaque token; consenting or declining needs a fresh code sent to the guarantor.
 */
class LoanSelfServiceController extends Controller
{
    use ApiResponse;

    private function member(Request $request): SaccoMember
    {
        return $request->attributes->get('sacco_member');
    }

    private function company(Request $request): Company
    {
        return $request->attributes->get('company');
    }

    /** @param callable(): JsonResponse $fn */
    private function attempt(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (BusinessRuleException $e) {
            $notFound = in_array($e->errorCode(), ['loan_not_found', 'guarantee_not_found', 'link_invalid', 'product_not_found'], true);

            return $this->error($e->getMessage(), $notFound ? 404 : 422, $e->toErrors());
        }
    }

    /** GET sacco/me/loans */
    public function loans(Request $request): JsonResponse
    {
        $m = $this->member($request);

        return $this->success(SaccoLoan::withoutGlobalScopes()->where('company_id', $m->company_id)->where('member_id', $m->id)->where('is_deleted', 0)->orderByDesc('id')->get()
            ->map(fn ($l) => LoanPresent::loan($l))->all());
    }

    /** GET sacco/me/loans/{id}: with its schedule */
    public function loan(Request $request, int $id): JsonResponse
    {
        $m = $this->member($request);
        $l = SaccoLoan::withoutGlobalScopes()->where('company_id', $m->company_id)->where('member_id', $m->id)->where('is_deleted', 0)->find($id);
        if ($l === null) {
            return $this->notFound('Not found.');
        }

        return $this->success(LoanPresent::loan($l) + ['schedule' => SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $l->id)->where('schedule_version', $l->schedule_version)
            ->orderBy('installment_no')->get()->map(fn ($x) => LoanPresent::line($x))->all()]);
    }

    /** POST sacco/me/loan-applications {product_id, applied_minor, term, purpose, uuid}: made and submitted as the member's */
    public function apply(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->success(LoanPresent::loan((new LoanApplications())->applyAsMember($this->company($request), $this->member($request),
            $request->only(['uuid', 'product_id', 'applied_minor', 'term', 'purpose', 'purpose_category', 'disbursement_channel', 'inputs_share_bp']))), 'Application submitted.', 201));
    }

    /** GET sacco/me/guarantee-requests */
    public function guarantees(Request $request): JsonResponse
    {
        $m = $this->member($request);

        return $this->success(SaccoLoanGuarantor::withoutGlobalScopes()->where('company_id', $m->company_id)->where('guarantor_member_id', $m->id)->orderByDesc('id')->get()
            ->map(fn ($g) => LoanPresent::guaranteeRequest($g))->all());
    }

    /** POST sacco/me/guarantee-requests/{id}/{code|consent|decline} */
    public function guarantee(Request $request, int $id, string $action): JsonResponse
    {
        $m = $this->member($request);
        $svc = new Guarantors();

        return $this->attempt(function () use ($request, $id, $action, $m, $svc) {
            return match ($action) {
                'code' => $this->success($svc->requestMemberCode($this->company($request), $id, $m), 'A code was sent to you.'),
                'consent' => $this->success(LoanPresent::guaranteeRequest($svc->consentAsMember($this->company($request), $id, $m, (string) $request->input('code'))), 'Thank you: your consent is recorded.'),
                'decline' => $this->success(LoanPresent::guaranteeRequest($this->declineAsMember($request, $id, $m)), 'Recorded: you declined.'),
                default => $this->notFound('Not found.'),
            };
        });
    }

    /** GET sacco/consent/{token}: the guarantor's notice (public, by the link's opaque token) */
    public function notice(string $token): JsonResponse
    {
        return $this->attempt(fn () => $this->success(Guarantors::notice($token)));
    }

    /** POST sacco/consent/{token}/{code|consent|decline} {code} */
    public function consent(Request $request, string $token, string $action): JsonResponse
    {
        $svc = new Guarantors();

        return $this->attempt(fn () => match ($action) {
            'code' => $this->success($svc->requestCode($token, $request->ip()), 'A code was sent to you.'),
            'consent' => $this->success(['status' => $svc->consentByLink($token, (string) $request->input('code'))->consent_status], 'Thank you: your consent is recorded.'),
            'decline' => $this->success(['status' => $svc->declineByLink($token, (string) $request->input('code'), $request->input('reason'))->consent_status], 'Recorded: you declined.'),
            default => $this->notFound('Not found.'),
        });
    }

    private function declineAsMember(Request $request, int $id, SaccoMember $m): SaccoLoanGuarantor
    {
        $g = SaccoLoanGuarantor::withoutGlobalScopes()->where('company_id', $m->company_id)->where('guarantor_member_id', $m->id)->find($id)
            ?? throw BusinessRuleException::make('guarantee_not_found', 'That guarantee request is not yours.');

        return (new Guarantors())->decline((int) $m->company_id, (int) $g->id, null, (string) $request->input('reason', 'Declined in the member sign-in'));
    }
}
