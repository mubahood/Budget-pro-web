<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Models\RentalLandlord;
use App\Models\RentalPayout;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\RentalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Money paid to landlords (rentals_landlords); in owner mode, owner drawings. Reversed with a reason (rentals_reverse). */
class PayoutsController extends RentalsController
{
    /** GET rentals/payouts?landlord_id&from&to */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            RentalAccess::authorize($this->user($request), 'rentals_landlords');
            $q = RentalPayout::withoutGlobalScopes()->where('company_id', $this->cid($request));
            if ($request->filled('landlord_id')) {
                $q->where('landlord_id', (int) $request->query('landlord_id'));
            }
            if (($from = $this->day($request, 'from')) !== null) {
                $q->where('paid_on', '>=', $from);
            }
            if (($to = $this->day($request, 'to')) !== null) {
                $q->where('paid_on', '<=', $to);
            }
            self::search($q, $request->query('q'), ['number', 'reference']);
            $p = $this->paginate($q->orderByDesc('paid_on')->orderByDesc('id'), $request);
            $present = $this->present($request)->prime($p->items());

            return $this->page($p, collect($p->items())->map(fn ($x) => $present->payout($x))->all());
        });
    }

    /** POST rentals/payouts {landlord_id, amount_minor, method, paid_on, reference, period_from, period_to, notes, pay_ahead, advance_reason, advance_others_ok} */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $landlord = RentalLandlord::withoutGlobalScopes()->where('company_id', $this->cid($request))->find((int) $request->input('landlord_id'))
                ?? throw $this->notFoundRecord('landlord');
            $this->day($request, 'paid_on');
            $data = $request->only(['method', 'paid_on', 'reference', 'notes', 'advance_reason']) + ['advance_others_ok' => $request->boolean('advance_others_ok'), 'amount_minor' => $this->minor($request, 'amount_minor'),
                'period_from' => $this->day($request, 'period_from'), 'period_to' => $this->day($request, 'period_to'),
                'advance' => $request->boolean('pay_ahead') || $request->boolean('advance')];

            return $this->created($this->present($request)->payout(app(Payouts::class)->record($this->user($request), $landlord, $data)), 'Payout recorded.');
        });
    }

    /** POST rentals/payouts/{id}/reverse {reason} */
    public function reverse(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $payout = RentalPayout::withoutGlobalScopes()->where('company_id', $this->cid($request))->find($id) ?? throw $this->notFoundRecord('payout');

            return $this->success($this->present($request)->payout(app(Payouts::class)->reverse($this->user($request), $payout, (string) $request->input('reason', ''))), 'Payout reversed.');
        });
    }
}
