<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLandlord;
use App\Models\RentalPayoutRun;
use App\Models\RentalPayoutRunLine;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\PayoutRuns;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalLinks;
use App\Services\Rentals\RentalsModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Many landlords paid at once (plan §7.3). PayoutRuns holds the rules: a draft, a check of each account, then all of it paid or none of it. Agency staff who read landlords, never a caretaker. */
class PayoutRunsController extends RentalsController
{
    /** GET rentals/payout-runs?status */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $this->guard($request);
            $q = RentalPayoutRun::withoutGlobalScopes()->where('company_id', $this->cid($request));
            if (in_array($request->query('status'), RentalPayoutRun::STATUSES, true)) {
                $q->where('status', $request->query('status'));
            }
            $p = $this->paginate($q->orderByDesc('id'), $request);
            $ids = collect($p->items())->pluck('id')->all();
            $sums = DB::table('rental_payout_run_lines')->where('company_id', $this->cid($request))->whereIn('run_id', $ids ?: [0])->groupBy('run_id')->selectRaw('run_id, COUNT(*) AS n, SUM(amount_minor) AS total')->get()->keyBy('run_id');

            return $this->page($p, collect($p->items())->map(fn (RentalPayoutRun $r) => $this->brief($r, (int) ($sums->get($r->id)->n ?? 0), (int) ($sums->get($r->id)->total ?? 0)))->all(), ['currency' => $this->present($request)->currency()]);
        });
    }

    /** GET rentals/payout-runs/candidates?above=: every active landlord owed more than that. */
    public function candidates(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->success(app(PayoutRuns::class)->candidates($this->user($request), max(0, (int) $request->query('above', '0')))));
    }

    /** POST rentals/payout-runs {lines: [{landlord_id, amount_minor, method}]}: a draft. */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->created($this->full($request, app(PayoutRuns::class)->save($this->user($request), $this->lines($request))), 'Saved.'));
    }

    /** GET rentals/payout-runs/{id}: its lines, and what it would do to each account. */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $this->guard($request);

            return $this->success($this->full($request, $this->find($request, $id)));
        });
    }

    /** PUT rentals/payout-runs/{id} {lines}: replaces a draft's lines. */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $this->guard($request);

            return $this->success($this->full($request, app(PayoutRuns::class)->save($this->user($request), $this->lines($request), $this->find($request, $id))), 'Saved.');
        });
    }

    /** POST rentals/payout-runs/{id}/post {paid_on, reference, references: {line id: text}}: every line becomes a payout, or none does. */
    public function post(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $this->guard($request);
            $data = ['paid_on' => $this->day($request, 'paid_on'), 'reference' => $request->filled('reference') ? (string) $request->input('reference') : null, 'references' => (array) $request->input('references', [])];

            return $this->success($this->full($request, app(PayoutRuns::class)->post($this->user($request), $this->find($request, $id), $data)), 'Paid.');
        });
    }

    /** POST rentals/payout-runs/{id}/cancel: a cancelled draft leaves nothing. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $this->guard($request);

            return $this->success($this->full($request, app(PayoutRuns::class)->cancel($this->user($request), $this->find($request, $id))), 'Cancelled.');
        });
    }

    /** GET rentals/payout-runs/{id}/sheet?format=pdf|xlsx|csv: a 30-minute link to the sheet to take to the bank. */
    public function sheet(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $this->guard($request);
            $run = $this->find($request, $id);
            $format = (string) $request->query('format', 'pdf');
            if (! in_array($format, ['pdf', 'xlsx', 'csv'], true)) {
                throw BusinessRuleException::make('unknown_format', 'Download a report as csv, xlsx or pdf.');
            }

            return $this->success(RentalLinks::payoutRunSheet($this->cid($request), (int) $run->id, $format, (int) $this->user($request)->id) + ['format' => $format], 'Link ready.');
        });
    }

    private function guard(Request $request): void
    {
        RentalAccess::authorize($this->user($request), 'rentals_landlords');
        $this->wholeAccount($request);
        if (RentalsModule::isOwnerMode($this->cid($request))) {
            throw BusinessRuleException::make('agency_only', 'This is for agencies that pay landlords.');
        }
    }

    private function find(Request $request, int $id): RentalPayoutRun
    {
        return RentalPayoutRun::withoutGlobalScopes()->where('company_id', $this->cid($request))->find($id) ?? throw $this->notFoundRecord('payout run');
    }

    /** @return list<array<string, mixed>> */
    private function lines(Request $request): array
    {
        $lines = $request->input('lines', []);

        return is_array($lines) ? array_values(array_filter($lines, 'is_array')) : [];
    }

    /** @return array<string, mixed> */
    private function brief(RentalPayoutRun $r, int $count, int $total): array
    {
        return ['id' => (int) $r->id, 'number' => (string) $r->number, 'status' => (string) $r->status, 'paid_on' => $r->paid_on?->toDateString(), 'posted_at' => $r->posted_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(), 'lines_count' => $count, 'total_minor' => $total];
    }

    /** @return array<string, mixed> */
    private function full(Request $request, RentalPayoutRun $r): array
    {
        $cid = $this->cid($request);
        $lines = RentalPayoutRunLine::withoutGlobalScopes()->where('company_id', $cid)->where('run_id', $r->id)->orderBy('id')->get();
        $landlords = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $lines->pluck('landlord_id')->all() ?: [0])->get()->keyBy('id');
        $balances = LandlordAccounts::balances($cid);

        return $this->brief($r, $lines->count(), (int) $lines->sum('amount_minor')) + ['currency' => $this->present($request)->currency(),
            'lines' => $lines->map(fn (RentalPayoutRunLine $l) => ['id' => (int) $l->id, 'landlord_id' => (int) $l->landlord_id, 'name' => (string) ($landlords->get($l->landlord_id)->name ?? ''), 'code' => $landlords->get($l->landlord_id)->code ?? null,
                'amount_minor' => (int) $l->amount_minor, 'method' => (string) $l->method, 'balance_at_draft_minor' => (int) $l->balance_at_draft, 'balance_minor' => (int) ($balances[(int) $l->landlord_id] ?? 0), 'reference' => $l->reference,
                'payout_id' => $l->payout_id === null ? null : (int) $l->payout_id, 'details' => $landlords->get($l->landlord_id)->payout_details ?? null])->values()->all(),
            'check' => PayoutRuns::check($cid, $lines)];
    }
}
