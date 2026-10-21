<?php

namespace App\Http\Controllers\Api\V1\Sacco\YearEnd;

use App\Http\Controllers\Api\V1\Sacco\Present;
use App\Http\Controllers\Api\V1\Sacco\SaccoController;
use App\Models\SaccoAppropriation;
use App\Models\SaccoApproval;
use App\Models\SaccoDividendLine;
use App\Models\SaccoDividendRun;
use App\Models\SaccoFiscalYear;
use App\Models\SaccoInterestRun;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\YearEnd\Appropriation;
use App\Services\Sacco\YearEnd\Closing;
use App\Services\Sacco\YearEnd\Dividends;
use App\Services\Sacco\YearEnd\Dormancy;
use App\Services\Sacco\YearEnd\SavingsInterest;
use App\Services\Sacco\YearEnd\ShareTransferFund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The year-end and the money runs (P4.6–P4.10, 04 §13.2): savings interest runs, the year-end close, the
 * appropriation, the dividend run, Share Transfer Fund buy-backs and dormant accounts. Every money move is a request
 * for other officers (202 with the approval); previews are reads with a body (sacco_view). The services check the
 * maker's permission again and the law's limits (a licensed SACCO is refused, any other gets the warnings).
 */
class YearEndController extends SaccoController
{
    /** GET sacco/interest-runs?product_id&kind */
    public function interestRuns(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $p = SaccoInterestRun::withoutGlobalScopes()->where('company_id', $this->cid($request))
            ->when($request->query('product_id'), fn ($q, $v) => $q->where('product_id', (int) $v))->when($request->query('kind'), fn ($q, $v) => $q->where('kind', (string) $v))
            ->orderByDesc('period_from')->orderByDesc('id')->paginate($this->perPage($request));

        return $this->page($p, $p->getCollection()->map(fn (SaccoInterestRun $r) => $r->toArray())->all());
    }

    /** POST sacco/interest-runs/preview {month, product_id?}: what the month's accrual would post, per product. */
    public function previewInterest(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $d = $request->validate(['month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'product_id' => ['nullable', 'integer']]);

        return $this->attempt(function () use ($request, $d) {
            $si = new SavingsInterest();
            $ids = isset($d['product_id']) ? [(int) $d['product_id']] : array_map(fn ($p) => (int) $p->id, $si->products($this->cid($request)));

            return $this->success(array_map(fn ($id) => $si->preview($this->cid($request), $id, $d['month']), $ids));
        });
    }

    /** POST sacco/interest-runs {month, uuid?}: a month's interest, accrued and credited, once a second officer approves. */
    public function requestInterest(Request $request): JsonResponse
    {
        $d = $request->validate(['month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        return $this->attempt(fn () => $this->asked((new SaccoApprovals())->request($this->company($request), 'interest_run', ['month' => $d['month']], $this->user($request),
            $request->input('uuid')), $request));
    }

    /** GET sacco/year-end/{year}: the year's figures, its close, its appropriation and its dividend. */
    public function year(Request $request, int $year): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $cid = $this->cid($request);

        return $this->attempt(function () use ($cid, $year) {
            $fy = SaccoFiscalYear::withoutGlobalScopes()->where('company_id', $cid)->where('year', $year)->first();

            return $this->success(['year' => $year, 'fiscal_year' => $fy?->toArray(), 'figures' => (new Closing())->preview($cid, $year),
                'appropriations' => $fy === null ? [] : SaccoAppropriation::withoutGlobalScopes()->where('company_id', $cid)->where('fiscal_year_id', $fy->id)->orderByDesc('id')->get()->toArray(),
                'dividend_runs' => $fy === null ? [] : SaccoDividendRun::withoutGlobalScopes()->where('company_id', $cid)->where('fiscal_year_id', $fy->id)->orderByDesc('id')->get()->toArray()]);
        });
    }

    /** POST sacco/year-end/{year}/close {on?, uuid?}: two officers in the chair's, vice chair's or treasurer's office approve. */
    public function close(Request $request, int $year): JsonResponse
    {
        $d = $request->validate(['on' => ['nullable', 'date']]);

        return $this->attempt(fn () => $this->asked((new Closing())->request($this->company($request), $year, $this->user($request), ['on' => $d['on'] ?? null], $request->input('uuid')), $request));
    }

    /** POST sacco/year-end/{year}/appropriation/preview {reserve_bp, ncef_bp, education_bp, stf_minor, other_reserves_minor, dividend_rate_bp, dividend_basis, on} */
    public function previewAppropriation(Request $request, int $year): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->attempt(fn () => $this->success((new Appropriation())->preview($this->cid($request), $year, $this->appropriationData($request))));
    }

    /** POST sacco/year-end/{year}/appropriate {… as the preview, agm_date, agm_minutes_file_id, uuid?} */
    public function appropriate(Request $request, int $year): JsonResponse
    {
        $data = $this->appropriationData($request) + $request->validate(['agm_date' => ['nullable', 'date'], 'agm_minutes_file_id' => ['nullable', 'integer']]);

        return $this->attempt(fn () => $this->asked((new Appropriation())->request($this->company($request), $year, $data, $this->user($request), $request->input('uuid')), $request));
    }

    /** GET sacco/dividend-runs */
    public function dividendRuns(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $p = SaccoDividendRun::withoutGlobalScopes()->where('company_id', $this->cid($request))->orderByDesc('id')->paginate($this->perPage($request));

        return $this->page($p, $p->getCollection()->map(fn (SaccoDividendRun $r) => $r->toArray())->all());
    }

    /** GET sacco/dividend-runs/{id}: the run and every member's line. */
    public function dividendRun(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $run = SaccoDividendRun::withoutGlobalScopes()->where('company_id', $this->cid($request))->find($id);
        if ($run === null) {
            return $this->notFound('That dividend run is not in this SACCO.');
        }

        return $this->success($run->toArray() + ['lines' => SaccoDividendLine::withoutGlobalScopes()->where('run_id', $run->id)->orderBy('member_id')->get()->toArray()]);
    }

    /** POST sacco/dividend-runs/preview {year, pay_to, registrar_consent_file_id, registrar_consent_on, registrar_consent_ref, on} */
    public function previewDividend(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $d = $this->dividendData($request);

        return $this->attempt(fn () => $this->success((new Dividends())->preview($this->cid($request), (int) $d['year'], $d)));
    }

    /** POST sacco/dividend-runs {… as the preview, uuid?}: two officers approve; a licensed SACCO needs the Registrar's consent on file. */
    public function requestDividend(Request $request): JsonResponse
    {
        $d = $this->dividendData($request);

        return $this->attempt(fn () => $this->asked((new Dividends())->request($this->company($request), (int) $d['year'], $d, $this->user($request), $request->input('uuid')), $request));
    }

    /** POST sacco/dividend-lines/{id}/pay {to: savings|cash}: a dividend whose MoMo payout failed, paid again. */
    public function payDividendLine(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['to' => ['required', 'in:savings,cash']]);

        return $this->attempt(fn () => $this->success((new Dividends())->payFailed($this->company($request), $id, $d['to'], $this->user($request))->toArray(), 'Dividend paid.'));
    }

    /** GET sacco/members/{id}/stf-buyback?channel: what the Share Transfer Fund would pay for the member's shares. */
    public function previewBuyback(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->attempt(fn () => $this->success((new ShareTransferFund())->preview($this->cid($request), $id, ['channel' => (string) $request->query('channel', 'cash')])));
    }

    /** POST sacco/members/{id}/stf-buyback {channel, reason, uuid?} */
    public function requestBuyback(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['channel' => ['nullable', 'in:cash,bank'], 'reason' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->asked((new ShareTransferFund())->request($this->company($request), $id, $d, $this->user($request), $request->input('uuid')), $request));
    }

    /** GET sacco/dormancy: the dormant register with what is due. */
    public function dormancy(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->success((new Dormancy())->register($this->cid($request)));
    }

    /** POST sacco/dormancy/advertised {account_ids[], reference, on} */
    public function advertised(Request $request): JsonResponse
    {
        $d = $request->validate(['account_ids' => ['required', 'array', 'min:1'], 'account_ids.*' => ['integer'], 'reference' => ['required', 'string', 'max:120'], 'on' => ['required', 'date']]);

        return $this->attempt(fn () => $this->success(['recorded' => (new Dormancy())->advertised($this->company($request), array_map('intval', $d['account_ids']), $d['reference'], $d['on'],
            $this->user($request))], 'Advertisement recorded.'));
    }

    /** POST sacco/dormancy/{accountId}/transfer {uuid?}: an unclaimed balance to the regulator, once a second officer approves. */
    public function transfer(Request $request, int $accountId): JsonResponse
    {
        return $this->attempt(fn () => $this->asked((new Dormancy())->requestTransfer($this->company($request), $accountId, $this->user($request), $request->input('uuid')), $request));
    }

    private function asked(SaccoApproval $approval, Request $request): JsonResponse
    {
        $msg = $approval->required_approvals > 1 ? 'This waits for two officers.' : 'This waits for a second officer.';

        return $this->success(['approval' => Present::approval($approval, $this->user($request))], $msg, 202);
    }

    /** @return array<string, mixed> */
    private function appropriationData(Request $request): array
    {
        return $request->validate(['reserve_bp' => ['sometimes', 'integer', 'min:0', 'max:10000'], 'ncef_bp' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'education_bp' => ['sometimes', 'integer', 'min:0', 'max:10000'], 'stf_minor' => \App\Support\Rules\SaccoRules::minor(false), 'other_reserves_minor' => \App\Support\Rules\SaccoRules::minor(false),
            'dividend_rate_bp' => ['sometimes', 'integer', 'min:0', 'max:10000'], 'dividend_basis' => ['sometimes', 'in:year_end_shares,weighted_monthly'], 'on' => ['nullable', 'date']]);
    }

    /** @return array<string, mixed> */
    private function dividendData(Request $request): array
    {
        return $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'pay_to' => ['sometimes', 'in:savings,cash,momo'],
            'registrar_consent_file_id' => ['nullable', 'integer'], 'registrar_consent_on' => ['nullable', 'date'], 'registrar_consent_ref' => ['nullable', 'string', 'max:80'], 'on' => ['nullable', 'date']]);
    }
}
