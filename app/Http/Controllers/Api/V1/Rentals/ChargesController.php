<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Models\RentalCharge;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\Payments\Payments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bills: rent and fees each period, one-off charges, voids and waivers, and "Raise bills now" (rentals_bill). */
class ChargesController extends RentalsController
{
    /** GET rentals/charges?lease_id&status=open|paid|void&kind&property_id&from&to&overdue */
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
            self::search($q, $request->query('q'), ['description']);
            $p = $this->paginate($q->orderByDesc('due_date')->orderByDesc('id'), $request, 100);
            $present = $this->present($request);

            return $this->page($p, collect($p->items())->map(fn ($c) => $present->charge($c))->all());
        });
    }

    /** POST rentals/charges {lease_id, kind: one_off|utility, description, amount_minor, due_date, goes_to, commissionable}: a one-off bill. */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $lease = $this->lease($request, (int) $request->input('lease_id'));
            $c = app(Billing::class)->charge($this->user($request), $lease, ['amount_minor' => $this->minor($request, 'amount_minor'), 'due_date' => $this->day($request, 'due_date')]
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
        return $this->attempt(fn () => $this->success(['bills' => app(Billing::class)->raiseNow($this->user($request),
            $request->filled('lease_id') ? $this->lease($request, (int) $request->input('lease_id'))->id : null)], 'Bills raised.'));
    }

    private function charge(Request $request, int $id): RentalCharge
    {
        return $this->scoped(RentalCharge::withoutGlobalScopes()->where('company_id', $this->cid($request)), $request)->find($id) ?? throw $this->notFoundRecord('bill');
    }
}
