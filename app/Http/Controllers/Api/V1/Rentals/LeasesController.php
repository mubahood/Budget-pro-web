<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Models\RentalLease;
use App\Models\RentalLeaseItem;
use App\Models\RentalTenant;
use App\Models\RentalUnit;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Payments\Deposits;
use App\Services\Rentals\Payments\Payments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Leases (docs/estate-ledger/04-architecture.md §5.7, §5.8): moving in, notice, renewal, moving out with the deposit
 * settled, cancelling one made by mistake, rent changes and fees; each answers the Lease shape (with its position).
 */
class LeasesController extends RentalsController
{
    /** What a move-in takes (Leases::moveIn); an import's opening figures are not taken here. */
    public const MOVE_IN = ['unit_id', 'tenant_id', 'tenant', 'start_date', 'rent_minor', 'cycle_months', 'anchor', 'end_date', 'deposit_minor', 'deposit_held_by',
        'commission', 'late_fee', 'items', 'notes', 'first_payment'];

    /** GET rentals/leases?q&status&property_id&unit_id&tenant_id */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $cid = $this->cid($request);
            $q = $this->scoped(RentalLease::withoutGlobalScopes()->where('company_id', $cid), $request);
            $status = array_values(array_intersect(explode(',', (string) $request->query('status', '')), ['active', 'notice', 'ended', 'cancelled']));
            if ($status !== []) {
                $q->whereIn('status', $status);
            } elseif ($request->query('status') === 'live') {
                $q->whereIn('status', RentalLease::LIVE);
            }
            foreach (['property_id', 'unit_id', 'tenant_id'] as $f) {
                if ($request->filled($f)) {
                    $q->where($f, (int) $request->query($f));
                }
            }
            if (($term = trim((string) $request->query('q', ''))) !== '') {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $q->where(fn ($w) => $w->where('number', 'like', $like)
                    ->orWhereIn('tenant_id', RentalTenant::withoutGlobalScopes()->where('company_id', $cid)->where(fn ($t) => $t->where('name', 'like', $like)->orWhere('phone', 'like', $like)
                        ->orWhere('phone_e164', 'like', $like)->orWhere('code', 'like', $like))->select('id'))
                    ->orWhereIn('unit_id', RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->where('code', 'like', $like)->select('id')));
            }
            $p = $this->paginate($q->orderByDesc('start_date')->orderByDesc('id'), $request);
            $present = $this->present($request)->prime($p->items());

            return $this->page($p, collect($p->items())->map(fn ($l) => $present->leaseBrief($l))->all());
        });
    }

    /** POST rentals/leases: move in {unit_id, tenant_id | tenant{…}, start_date, rent_minor, cycle_months, anchor, end_date, deposit_minor, deposit_held_by, commission{type, bp, minor}, late_fee, items[], notes, first_payment{amount_minor, method, reference, paid_on, uuid}} */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['unit_id' => ['required', 'integer'], 'tenant_id' => ['nullable', 'integer'], 'tenant' => ['nullable', 'array'], 'items' => ['nullable', 'array'],
            'first_payment' => ['nullable', 'array'], 'commission' => ['nullable', 'array']]);

        return $this->attempt(function () use ($request) {
            $this->day($request, 'start_date');
            $this->day($request, 'end_date');
            $data = $request->only(self::MOVE_IN);
            if (! empty($data['first_payment']) && $this->fromApp($request) && empty($data['first_payment']['device_id'])) {
                $data['first_payment']['device_id'] = $request->header('X-Device-Id');
            }
            $lease = app(Leases::class)->moveIn($this->user($request), $data);

            return $this->created($this->present($request)->lease($lease), 'Moved in.');
        });
    }

    /** GET rentals/leases/{id}: the Lease with its position, bills, payments, fees, rent history, deposit and follow-ups. */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->present($request)->lease($this->lease($request, $id))));
    }

    /** POST rentals/leases/{id}/notice {notice_on, move_out_on}: the tenant gave notice; billing stops after the move-out day. */
    public function notice(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->present($request)->lease(app(Leases::class)->notice($this->user($request), $this->lease($request, $id),
            $this->day($request, 'notice_on'), $this->requiredDay($request, 'move_out_on', 'Choose the day the tenant moves out.')))));
    }

    /** POST rentals/leases/{id}/withdraw-notice */
    public function withdrawNotice(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->present($request)->lease(app(Leases::class)->withdrawNotice($this->user($request), $this->lease($request, $id)))));
    }

    /** POST rentals/leases/{id}/renew {end_date | null}: a later end for a fixed term, or none (month to month). */
    public function renew(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->present($request)->lease(app(Leases::class)->renew($this->user($request), $this->lease($request, $id),
            $this->day($request, 'end_date'))), 'Renewed.'));
    }

    /**
     * POST rentals/leases/{id}/move-out {date, readings[{utility, reading}], damages[{description, amount_minor, goes_to}], unused_days,
     * refund_deposit (method or null), refund_credit (method or null), reason}: {lease, settlement}.
     */
    public function moveOut(Request $request, int $id): JsonResponse
    {
        $request->validate(['readings' => ['nullable', 'array'], 'damages' => ['nullable', 'array']]);

        return $this->attempt(function () use ($request, $id) {
            $this->day($request, 'date');
            $out = app(Leases::class)->moveOut($this->user($request), $this->lease($request, $id),
                $request->only(['date', 'readings', 'damages', 'unused_days', 'refund_deposit', 'refund_credit', 'reason']));

            return $this->success(['lease' => $this->present($request)->lease($out['lease']), 'settlement' => $out['settlement']], 'Moved out.');
        });
    }

    /**
     * POST rentals/leases/{id}/refund-deposit {method, amount_minor?, on?}: a deposit kept at move-out goes back later, all of
     * what is held by default. It settles anything still owed first (§5.7).
     */
    public function refundDeposit(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(Deposits::refundKept($this->user($request), $this->lease($request, $id),
            $request->filled('amount_minor') ? $this->minor($request, 'amount_minor') : null, (string) $request->input('method', ''), $this->day($request, 'on')), 'Deposit refunded.'));
    }

    /** POST rentals/leases/{id}/refund-credit {method, amount_minor?, on?}: money paid ahead goes back, all of it by default. */
    public function refundCredit(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(app(Payments::class)->refundKeptCredit($this->user($request), $this->lease($request, $id),
            $request->filled('amount_minor') ? $this->minor($request, 'amount_minor') : null, (string) $request->input('method', ''), $this->day($request, 'on')), 'Credit refunded.'));
    }

    /** POST rentals/leases/{id}/cancel {reason}: a lease made by mistake (no money on it). */
    public function cancel(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->present($request)->lease(app(Leases::class)->cancel($this->user($request), $this->lease($request, $id),
            trim((string) $request->input('reason', '')) ?: 'Made by mistake')), 'Cancelled.'));
    }

    /** POST rentals/leases/{id}/rent-change {rent_minor, effective_from, reason} */
    public function rentChange(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $lease = $this->lease($request, $id);
            app(Leases::class)->changeRent($this->user($request), $lease, $this->minor($request, 'rent_minor', 'rent'),
                $this->requiredDay($request, 'effective_from', 'Choose the day the new rent starts (the start of a period not billed yet).'),
                $request->filled('reason') ? (string) $request->input('reason') : null);

            return $this->success($this->present($request)->lease($lease->fresh()), 'Rent changed.');
        });
    }

    /** POST rentals/leases/{id}/items {name, amount_minor, goes_to, commissionable, starts_on, ends_on}: a fee billed beside the rent. */
    public function addItem(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->created($this->present($request)->item(app(Leases::class)->addItem($this->user($request), $this->lease($request, $id),
            ['amount_minor' => $this->minor($request, 'amount_minor', 'fee amount'), 'starts_on' => $this->day($request, 'starts_on'), 'ends_on' => $this->day($request, 'ends_on')]
            + $request->only(['name', 'goes_to', 'commissionable']))), 'Fee added.'));
    }

    /** POST rentals/leases/{id}/items/{item}/end {ends_on} */
    public function endItem(Request $request, int $id, int $item): JsonResponse
    {
        return $this->attempt(function () use ($request, $id, $item) {
            $lease = $this->lease($request, $id);
            $row = RentalLeaseItem::withoutGlobalScopes()->where('company_id', $lease->company_id)->where('lease_id', $lease->id)->find($item) ?? throw $this->notFoundRecord('fee');

            return $this->success($this->present($request)->item(app(Leases::class)->endItem($this->user($request), $row, $this->day($request, 'ends_on'))), 'Fee stopped.');
        });
    }

    /** GET rentals/leases/{id}/payment-preview?amount_minor=: what a payment of that much would settle now, oldest first (Payments::preview). */
    public function paymentPreview(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $lease = $this->lease($request, $id);

            return $this->success(app(Payments::class)->preview($lease, $this->minor($request, 'amount_minor')) + ['currency' => $this->present($request)->currency()]);
        });
    }
}
