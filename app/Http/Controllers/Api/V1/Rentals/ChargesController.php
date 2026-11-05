<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Models\RentalCharge;
use App\Models\RentalLease;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\RentalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bills: rent and fees each period, one-off charges, voids and waivers, and "Raise bills now" (rentals_bill). */
class ChargesController extends RentalsController
{
    /**
     * GET rentals/charges?lease_id&status=open|paid|void&kind&property_id&unit_id&tenant_id&from&to&overdue&no_refunds&q: `q` finds what it is for, the tenant's name or phone, the unit's code, the lease's number.
     * `no_refunds=1`: bills only, so a page holds what a bills list shows. `meta.totals`: {count, amount_minor (not cancelled), left_minor (still open)} of the whole list.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $q = $this->scoped(RentalCharge::withoutGlobalScopes()->where('company_id', $this->cid($request)), $request);
            foreach (['lease_id', 'property_id', 'unit_id', 'tenant_id'] as $f) {
                if ($request->filled($f)) {
                    $q->where($f, (int) $request->query($f));
                }
            }
            if (in_array($request->query('status'), ['open', 'paid', 'void'], true)) {
                $q->where('status', $request->query('status'));
            }
            if (in_array($request->query('kind'), RentalCharge::KINDS, true)) {
                $q->where('kind', $request->query('kind'));
            }
            if (($from = $this->day($request, 'from')) !== null) {
                $q->where('due_date', '>=', $from);
            }
            if (($to = $this->day($request, 'to')) !== null) {
                $q->where('due_date', '<=', $to);
            }
            if ($request->boolean('overdue')) {
                $present = $this->present($request);
                $q->where('status', 'open')->where('kind', '!=', 'refund')
                    ->where('due_date', '<', $present->today()->subDays((int) $present->settingsRow()->grace_days)->toDateString());
            }
            if ($request->boolean('no_refunds')) {
                $q->where('kind', '!=', 'refund');
            }
            self::searchRecord($q, $this->cid($request), (string) $request->query('q', ''), ['description']);
            $sums = (clone $q)->toBase()->selectRaw("COALESCE(SUM(CASE WHEN status <> 'void' THEN amount_minor ELSE 0 END), 0) AS amount, COALESCE(SUM(CASE WHEN status = 'open' THEN GREATEST(amount_minor - paid_minor, 0) ELSE 0 END), 0) AS left_minor")->first();
            $p = $this->paginate($q->orderByDesc('due_date')->orderByDesc('id'), $request, 100);
            $present = $this->present($request)->prime($p->items());

            return $this->page($p, collect($p->items())->map(fn ($c) => $present->charge($c) + ['tenant' => $present->tenantRef($c->tenant_id, false), 'unit' => $present->ref('unit', $c->unit_id)])->all(),
                ['totals' => ['count' => $p->total(), 'amount_minor' => (int) $sums->amount, 'left_minor' => (int) $sums->left_minor]]);
        });
    }

    /** POST rentals/charges {lease_id, kind: one_off|utility, description, amount_minor, due_date, goes_to, commissionable}: a one-off bill. */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $lease = $this->lease($request, (int) $request->input('lease_id'));
            if (($due = $this->day($request, 'due_date')) !== null) {
                RentalLedger::assertOpen($this->cid($request), $due); // a bill back in a closed month would read as money owed since then, on papers already sent
            }
            $c = app(Billing::class)->charge($this->user($request), $lease, ['amount_minor' => $this->minor($request, 'amount_minor'), 'due_date' => $due]
                + $request->only(['kind', 'description', 'goes_to', 'commissionable']));

            return $this->created($this->present($request)->charge($c), 'Bill added.');
        });
    }

    /** POST rentals/charges/{id}/void {reason}: cancel a bill nothing has been paid on. */
    public function void(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->present($request)->charge(app(Billing::class)->void($this->user($request), $this->charge($request, $id),
            (string) $request->input('reason', ''))), 'Bill cancelled.'));
    }

    /** POST rentals/charges/{id}/waive {amount_minor, reason}: forgive part or all of what is left on a bill. */
    public function waive(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $charge = $this->charge($request, $id);
            app(Payments::class)->waive($this->user($request), $charge, $this->minor($request, 'amount_minor'), (string) $request->input('reason', ''));

            return $this->success($this->present($request)->charge($charge->fresh()), 'Forgiven.');
        });
    }

    /** POST rentals/billing/run {lease_id?}: raise the bills that are due by the horizon now (every lease, or one). */
    public function run(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $by = $this->user($request);
            if ($request->filled('lease_id')) {
                return $this->success(['bills' => app(Billing::class)->raiseNow($by, $this->lease($request, (int) $request->input('lease_id'))->id)], 'Bills raised.');
            }
            if (RentalAccess::propertyIds($by) === null) {
                return $this->success(['bills' => app(Billing::class)->raiseNow($by)], 'Bills raised.');
            }
            // A login tied to some properties raises their bills, not the whole account's.
            $made = 0;
            foreach ($this->scoped(RentalLease::withoutGlobalScopes()->where('company_id', $this->cid($request))->whereIn('status', RentalLease::LIVE), $request)->pluck('id') as $id) {
                $made += app(Billing::class)->raiseNow($by, (int) $id);
            }

            return $this->success(['bills' => $made], 'Bills raised.');
        });
    }

    private function charge(Request $request, int $id): RentalCharge
    {
        return $this->scoped(RentalCharge::withoutGlobalScopes()->where('company_id', $this->cid($request)), $request)->find($id) ?? throw $this->notFoundRecord('bill');
    }
}
