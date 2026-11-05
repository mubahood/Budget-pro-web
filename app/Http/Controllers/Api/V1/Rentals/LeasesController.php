<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalCharge;
use App\Models\RentalLease;
use App\Models\RentalLeaseItem;
use App\Models\RentalProperty;
use App\Models\RentalUnit;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\Directory\Tenants;
use App\Services\Rentals\Leases\CommissionChanges;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Payments\Deposits;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalsModule;
use App\Support\Rentals\Day;
use App\Support\Rentals\Minor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Leases (docs/estate-ledger/04-architecture.md §5.7, §5.8): moving in, notice, renewal, moving out with the deposit
 * settled, cancelling one made by mistake, rent changes and fees; each answers the Lease shape (with its position).
 */
class LeasesController extends RentalsController
{
    /** What a move-in takes (Leases::moveIn); an import's opening figures are not taken here. */
    public const MOVE_IN = ['unit_id', 'tenant_id', 'tenant', 'start_date', 'rent_minor', 'cycle_months', 'anchor', 'end_date', 'deposit_minor', 'deposit_held_by',
        'commission', 'late_fee', 'items', 'notes', 'first_payment'];

    /**
     * GET rentals/leases?q&status&property_id&unit_id&tenant_id&sort=door: `status` is one or more of active, notice, ended, cancelled, or `live`, or as the web's list `term` (past its end date, still live)
     * or `ending` (ending or moving out in 30 days). `q` also finds the property's name. `sort=door`: by property, then unit. `meta.totals`: {count, rent_minor (live), owed_minor} of the whole list.
     */
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
            } elseif ($request->query('status') === 'term') {
                $q->whereIn('status', RentalLease::LIVE)->whereNotNull('end_date')->where('end_date', '<', $this->present($request)->today()->toDateString());
            } elseif ($request->query('status') === 'ending') {
                $today = $this->present($request)->today()->toDateString();
                $soon = $this->present($request)->today()->addDays(30)->toDateString();
                $q->whereIn('status', RentalLease::LIVE)->where(fn ($w) => $w->whereBetween('end_date', [$today, $soon])
                    ->orWhere(fn ($x) => $x->where('status', 'notice')->whereBetween('move_out_on', [$today, $soon])));
            }
            foreach (['property_id', 'unit_id', 'tenant_id'] as $f) {
                if ($request->filled($f)) {
                    $q->where($f, (int) $request->query($f));
                }
            }
            if (($term = trim((string) $request->query('q', ''))) !== '') {
                $like = self::like($term);
                $q->where(fn ($w) => $w->where('number', 'like', $like)
                    ->orWhereIn('tenant_id', self::tenantsLike($cid, $term))
                    ->orWhereIn('unit_id', RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->where('code', 'like', $like)->select('id'))
                    ->orWhereIn('property_id', RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->where('name', 'like', $like)->select('id')));
            }
            $rent = (int) (clone $q)->toBase()->whereIn('status', RentalLease::LIVE)->sum('rent_minor');
            $owed = (int) RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', (clone $q)->select('id'))->where('status', 'open')->where('kind', '!=', 'refund')
                ->sum(DB::raw('GREATEST(amount_minor - paid_minor, 0)'));
            if ($request->query('sort') === 'door') {
                $q->orderBy(RentalProperty::withoutGlobalScopes()->select('name')->whereColumn('rental_properties.id', 'rental_leases.property_id'))
                    ->orderBy(RentalUnit::withoutGlobalScopes()->selectRaw('LENGTH(code)')->whereColumn('rental_units.id', 'rental_leases.unit_id'))
                    ->orderBy(RentalUnit::withoutGlobalScopes()->select('code')->whereColumn('rental_units.id', 'rental_leases.unit_id'));
            }
            $p = $this->paginate($q->orderByDesc('start_date')->orderByDesc('id'), $request);
            $present = $this->present($request)->prime($p->items())->primePositions($p->items());

            return $this->page($p, collect($p->items())->map(fn ($l) => $present->leaseBrief($l))->all(), ['totals' => ['count' => $p->total(), 'rent_minor' => $rent, 'owed_minor' => $owed]]);
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
            Day::strict($request->input('first_payment.paid_on'), 'the payment\'s date');
            foreach ((array) $request->input('items', []) as $item) {
                Day::strict(is_array($item) ? ($item['ends_on'] ?? null) : null, 'the fee\'s last day');
            }
            $data = $request->only(self::MOVE_IN);
            if (! empty($data['tenant_id'])) {
                app(Tenants::class)->find($this->user($request), (int) $data['tenant_id']); // not found for a caretaker when they rent elsewhere: their name and phone would come back
            }
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
     * POST rentals/leases/{id}/move-out {date, readings[{utility, reading}], damages[{description, amount_minor, goes_to}], unused_days (true: forgive them; false: charge the full period),
     * refund_deposit (method or null), refund_credit (method or null), reason}: {lease, settlement}.
     */
    public function moveOut(Request $request, int $id): JsonResponse
    {
        $request->validate(['readings' => ['nullable', 'array'], 'damages' => ['nullable', 'array']]);

        return $this->attempt(function () use ($request, $id) {
            $this->day($request, 'date');
            $out = app(Leases::class)->moveOut($this->user($request), $this->lease($request, $id), self::moveOutTerms($request));

            return $this->success(['lease' => $this->present($request)->lease($out['lease']), 'settlement' => $out['settlement']], 'Moved out.');
        });
    }

    /**
     * POST rentals/leases/{id}/move-out-preview, the same body as move-out: what it would do with these choices, before anyone commits to it (Leases::moveOutPreview). No writes.
     */
    public function moveOutPreview(Request $request, int $id): JsonResponse
    {
        $request->validate(['readings' => ['nullable', 'array'], 'damages' => ['nullable', 'array']]);

        return $this->attempt(function () use ($request, $id) {
            $this->day($request, 'date');
            foreach ((array) $request->input('damages', []) as $damage) {
                if (is_array($damage) && ! empty($damage['amount_minor'])) {
                    Minor::positive($damage['amount_minor'], 'damage'); // what the move-out itself refuses, the preview refuses
                }
            }

            return $this->success(app(Leases::class)->moveOutPreview($this->lease($request, $id), self::moveOutTerms($request)));
        });
    }

    /**
     * The terms of a move-out as the service takes them. `unused_days` is a yes or a no: any word passed the service's test as a yes, so "charge the full period" forgave the rent.
     *
     * @return array<string, mixed>
     */
    private static function moveOutTerms(Request $request): array
    {
        $data = $request->only(['date', 'readings', 'damages', 'unused_days', 'refund_deposit', 'refund_credit', 'reason']);
        if (array_key_exists('unused_days', $data)) {
            $on = filter_var($data['unused_days'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($on === null) {
                throw BusinessRuleException::make('unused_days_invalid', 'Say yes or no to forgiving the days after the move-out.');
            }
            $data['unused_days'] = $on;
        }

        return $data;
    }

    /**
     * POST rentals/leases/move-in-preview {start_date, rent_minor, cycle_months, anchor, end_date, deposit_minor, items[]}: the bills a move-in would raise at once, with a part month's
     * calculated amount (Leases::moveInPreview). No writes.
     */
    public function moveInPreview(Request $request): JsonResponse
    {
        $request->validate(['items' => ['nullable', 'array']]);

        return $this->attempt(function () use ($request) {
            $this->day($request, 'start_date');
            $this->day($request, 'end_date');
            foreach (['rent_minor' => 'rent', 'deposit_minor' => 'deposit'] as $k => $label) {
                if ($request->filled($k)) {
                    Minor::nonNegative($request->input($k), $label); // the service reads a figure as it comes: a list as 1, "12abc" as 12
                }
            }
            foreach ((array) $request->input('items', []) as $item) {
                if (is_array($item) && ! empty($item['amount_minor'])) {
                    Minor::positive($item['amount_minor'], 'fee amount');
                }
            }

            return $this->success(Leases::moveInPreview($this->cid($request), $request->only(['start_date', 'rent_minor', 'cycle_months', 'anchor', 'end_date', 'deposit_minor', 'items'])));
        });
    }

    /**
     * POST rentals/leases/{id}/commission {type: percent|flat|none, bp, minor, effective_from, reason}: this lease's commission from a date after what is billed (LC-15). Bills already raised keep theirs and no money moves.
     */
    public function changeCommission(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $lease = $this->lease($request, $id);
            app(CommissionChanges::class)->change($this->user($request), $lease,
                ['commission_type' => $request->input('type'), 'commission_bp' => $request->input('bp'), 'commission_minor' => $request->input('minor')],
                $this->requiredDay($request, 'effective_from', 'Choose the day the new commission starts.'), (string) $request->input('reason', ''));

            return $this->success($this->present($request)->lease($lease->fresh()), 'Commission changed.');
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

    /**
     * GET rentals/leases/{id}/payment-preview?amount_minor=&reference=&method=: `reference_warning`, the words when that reference is already on a receipt or a tenant's waiting report (Payments::referenceClash; a warning, never a refusal; alone when
     * no amount is typed yet); what a payment of that much would settle now, oldest first (Payments::preview), what it could bill ahead (`ahead`, Billing::aheadPlan) and
     * what it would do if the tenant paid the landlord directly (`direct`, Payments::suggestSplit). No writes. How the money is shared with the landlord only for those who read landlords' money.
     */
    public function paymentPreview(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $lease = $this->lease($request, $id);
            $clash = Payments::referenceClash($this->cid($request), $request->query('reference'), $request->query('method'));
            if (! $request->filled('amount_minor') && $request->filled('reference')) {
                return $this->success(['reference_warning' => $clash]); // the reference was typed first
            }
            $amount = $this->minor($request, 'amount_minor');
            $by = $this->user($request);
            $owner = RentalsModule::isOwnerMode($this->cid($request));
            $sees = RentalAccess::can($by, 'rentals_landlords') && RentalAccess::propertyIds($by) === null;
            $ahead = null;
            if (! $owner && $lease->isLive() && RentalAccess::can($by, 'rentals_bill')) {
                $ahead = app(Billing::class)->aheadPlan($lease, $amount);
                if (! $sees) {
                    $ahead['periods'] = array_map(fn (array $p) => array_diff_key($p, array_flip(['commission_minor', 'landlord_minor', 'agency_minor'])), $ahead['periods']);
                }
            }
            $direct = null;
            if (! $owner) {
                $split = app(Payments::class)->suggestSplit($lease, $amount, [], true);
                $direct = ['open_minor' => (int) array_sum(array_column($split['lines'], 'left_minor')), 'amount_minor' => $amount, 'ok' => (bool) $split['ok']] + ($sees ? ['commission_minor' => (int) $split['commission_minor']] : []);
            }

            return $this->success(app(Payments::class)->preview($lease, $amount) + ['currency' => $this->present($request)->currency(), 'ahead' => $ahead, 'direct' => $direct, 'reference_warning' => $clash]);
        });
    }
}
