<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLandlord;
use App\Models\RentalPayout;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Messages\RentalMessages;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalLinks;
use App\Services\Rentals\RentalsModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Money paid to landlords (rentals_landlords); in owner mode, owner drawings. Reversed with a reason (rentals_reverse). */
class PayoutsController extends RentalsController
{
    /** GET rentals/payouts?landlord_id&from&to&q&status=live|reversed|all: `status`, as the web's: paid, or reversed; with none, every one. `q` also finds the landlord's name. `meta.totals`: {count, amount_minor (not reversed)} of the whole list. */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            RentalAccess::authorize($this->user($request), 'rentals_landlords');
            $this->wholeAccount($request);
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
            match ($request->query('status')) {
                'live' => $q->whereNull('reversed_at'),
                'reversed' => $q->whereNotNull('reversed_at'),
                default => null,
            };
            if (($term = trim((string) $request->query('q', ''))) !== '') {
                $like = self::like($term);
                $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('reference', 'like', $like)
                    ->orWhereIn('landlord_id', RentalLandlord::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('name', 'like', $like)->select('id')));
            }
            $amount = (int) (clone $q)->toBase()->whereNull('reversed_at')->sum('amount_minor');
            $p = $this->paginate($q->orderByDesc('paid_on')->orderByDesc('id'), $request);
            $present = $this->present($request)->prime($p->items());

            return $this->page($p, collect($p->items())->map(fn ($x) => $present->payout($x))->all(), ['totals' => ['count' => $p->total(), 'amount_minor' => $amount]]);
        });
    }

    /** POST rentals/payouts {landlord_id, amount_minor, method, paid_on, reference, period_from, period_to, notes, pay_ahead, advance_reason, advance_others_ok} */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $this->wholeAccount($request);
            $landlord = RentalLandlord::withoutGlobalScopes()->where('company_id', $this->cid($request))->find((int) $request->input('landlord_id'))
                ?? throw $this->notFoundRecord('landlord');
            $this->day($request, 'paid_on');
            $data = $request->only(['method', 'paid_on', 'reference', 'notes', 'advance_reason']) + ['advance_others_ok' => $request->boolean('advance_others_ok'), 'amount_minor' => $this->minor($request, 'amount_minor'),
                'period_from' => $this->day($request, 'period_from'), 'period_to' => $this->day($request, 'period_to'),
                'advance' => $request->boolean('pay_ahead') || $request->boolean('advance')];

            return $this->created($this->present($request)->payout(app(Payouts::class)->record($this->user($request), $landlord, $data)), 'Payout recorded.');
        });
    }

    /** GET rentals/payouts/{id}/advice: a week's signed link to the payout's advice, and the words for this person's own phone. The server sends nothing. Agency staff, never a caretaker. */
    public function advice(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $by = $this->user($request);
            RentalAccess::authorize($by, 'rentals_landlords');
            $this->wholeAccount($request);
            $cid = $this->cid($request);
            if (RentalsModule::isOwnerMode($cid)) {
                throw BusinessRuleException::make('agency_only', 'This is for agencies that pay landlords.');
            }
            $payout = RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->find($id) ?? throw $this->notFoundRecord('payout');
            $landlord = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->find($payout->landlord_id) ?? throw $this->notFoundRecord('payout');
            $link = RentalLinks::payoutAdvicePdf($payout, 'staff', (int) $by->id, RentalLinks::SHARED_MINUTES);
            $text = RentalMessages::forPayoutAdvice($cid, (string) $landlord->name, (string) $payout->number, (int) $payout->amount_minor, $payout->paid_on->format('j M Y'), $link['url'], $payout->reversed_at !== null);
            $phone = trim((string) $landlord->phone_e164) ?: null;

            return $this->success(['payout_id' => (int) $payout->id, 'number' => (string) $payout->number, 'landlord' => ['id' => (int) $landlord->id, 'name' => (string) $landlord->name], 'amount_minor' => (int) $payout->amount_minor,
                'paid_on' => $payout->paid_on->toDateString(), 'reversed' => $payout->reversed_at !== null, 'url' => $link['url'], 'expires_in' => $link['expires_in'], 'text' => $text, 'phone' => $phone,
                'whatsapp' => RentalMessages::whatsappLink($phone, $text), 'sms' => RentalMessages::smsLink($phone, $text)], 'Link ready.');
        });
    }

    /** POST rentals/payouts/{id}/reverse {reason} */
    public function reverse(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $this->wholeAccount($request);
            $payout = RentalPayout::withoutGlobalScopes()->where('company_id', $this->cid($request))->find($id) ?? throw $this->notFoundRecord('payout');

            return $this->success($this->present($request)->payout(app(Payouts::class)->reverse($this->user($request), $payout, (string) $request->input('reason', ''))), 'Payout reversed.');
        });
    }
}
