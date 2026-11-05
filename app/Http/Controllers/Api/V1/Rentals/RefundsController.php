<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Models\RentalLease;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Payments\Deposits;
use App\Services\Rentals\RentalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Money that went back to tenants, and former tenants' money still here (the web's Payments, its Refunds view). Reading them needs rentals_view and keeps to a caretaker's
 * properties; paying one back is POST leases/{id}/refund-deposit or refund-credit (LeasesController).
 */
class RefundsController extends RentalsController
{
    /**
     * GET rentals/refunds?from&to&property_id&q: refunds that were paid, newest first. A credit refund is one line however many payments funded it.
     * Each: {id, kind: deposit|credit, occurred_on, method, amount_minor, lease_id, tenant_name, unit_code, property_name}.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $cid = $this->cid($request);
            $q = DB::table('rental_entries as e')->join('rental_tenants as t', 't.id', '=', 'e.tenant_id')->join('rental_units as u', 'u.id', '=', 'e.unit_id')
                ->join('rental_properties as p', 'p.id', '=', 'e.property_id')->where('e.company_id', $cid)
                ->whereIn('e.type', array_merge(Deposits::REFUND_TYPES, ['credit_refund']))->whereNull('e.reversed_at')->whereNull('e.reverses_id');
            RentalAccess::scope($q, $this->user($request), 'e.property_id');
            if (($from = $this->day($request, 'from')) !== null) {
                $q->where('e.occurred_on', '>=', $from);
            }
            if (($to = $this->day($request, 'to')) !== null) {
                $q->where('e.occurred_on', '<=', $to);
            }
            if ($request->filled('property_id')) {
                $q->where('e.property_id', (int) $request->query('property_id'));
            }
            if (($term = trim((string) $request->query('q', ''))) !== '') {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $q->where(fn ($w) => $w->where('t.name', 'like', $like)->orWhere('t.phone', 'like', $like)->orWhere('u.code', 'like', $like));
            }
            $p = $q->selectRaw('MIN(e.id) AS id, e.type, e.occurred_on, e.method, e.lease_id, -SUM(e.cash_minor) AS amount_minor, t.name AS tenant_name, u.code AS unit_code, p.name AS property_name')
                ->groupByRaw('COALESCE(e.charge_id, -e.id), e.type, e.occurred_on, e.method, e.lease_id, t.name, u.code, p.name')->orderByDesc('e.occurred_on')->orderByDesc('id')
                ->paginate($this->perPage($request), ['*'], 'page', max(1, (int) $request->query('page', '1')));

            return $this->page($p, array_map(fn ($r) => ['id' => (int) $r->id, 'kind' => in_array($r->type, Deposits::REFUND_TYPES, true) ? 'deposit' : 'credit', 'occurred_on' => Present::day($r->occurred_on),
                'method' => $r->method === null ? null : (string) $r->method, 'amount_minor' => (int) $r->amount_minor, 'lease_id' => (int) $r->lease_id, 'tenant_name' => (string) $r->tenant_name,
                'unit_code' => (string) $r->unit_code, 'property_name' => (string) $r->property_name], $p->items()));
        });
    }

    /**
     * GET rentals/refunds/waiting: leases that ended with money of the tenant's still here (`position.credit_minor`, `position.deposit_held_minor`), most recently ended first: LeaseBrief.
     * A lease that still owes more than it holds is here too: refunding the deposit settles what is owed first.
     */
    public function waiting(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $credit = '(SELECT COALESCE(SUM(x.unallocated_minor), 0) FROM rental_payments x WHERE x.lease_id = rental_leases.id AND x.reversed_at IS NULL)';
            $held = "((SELECT COALESCE(SUM(a.amount_minor), 0) FROM rental_allocations a JOIN rental_charges c ON c.id = a.charge_id JOIN rental_payments x ON x.id = a.payment_id WHERE c.lease_id = rental_leases.id AND c.kind = 'deposit' AND x.method <> 'waiver')
                - (SELECT COALESCE(SUM(x.amount_minor), 0) FROM rental_payments x WHERE x.lease_id = rental_leases.id AND x.method = 'deposit' AND x.reversed_at IS NULL)
                + (SELECT COALESCE(SUM(e.cash_minor), 0) FROM rental_entries e WHERE e.lease_id = rental_leases.id AND e.type IN ('deposit_refund', 'deposit_refund_landlord') AND e.reversed_at IS NULL))";
            $q = $this->scoped(RentalLease::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('status', 'ended'), $request)
                ->whereRaw("({$credit} > 0 OR {$held} > 0)")->orderByDesc('ended_on')->orderByDesc('id');
            $p = $this->paginate($q, $request);
            $present = $this->present($request)->prime($p->items());
            $positions = Leases::positions($p->items());

            return $this->page($p, collect($p->items())->map(fn (RentalLease $l) => $present->leaseBrief($l, $positions[(int) $l->id] ?? null))->all());
        });
    }
}
