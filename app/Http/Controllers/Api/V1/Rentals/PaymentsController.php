<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalPayment;
use App\Models\RentalTenant;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Payments\Receipt;
use App\Services\Rentals\RentalLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Payments from tenants (rentals_collect) and their receipts; reversals with a reason (rentals_reverse). Receiving a
 * payment is the phone's offline-safe write: the body's `uuid` (required from the app) makes a resend the same
 * payment (200 with the first one); the same uuid with another lease, amount, method or split is 409 idempotency_conflict.
 * The cashier may say which bills the money settles (`split`, EL-05); without one it settles the oldest first (KR-10).
 */
class PaymentsController extends RentalsController
{
    /** GET rentals/payments?lease_id&tenant_id&property_id&method&from&to&q */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $cid = $this->cid($request);
            $q = $this->scoped(RentalPayment::withoutGlobalScopes()->where('company_id', $cid), $request);
            foreach (['lease_id', 'tenant_id', 'property_id', 'unit_id'] as $f) {
                if ($request->filled($f)) {
                    $q->where($f, (int) $request->query($f));
                }
            }
            if (in_array($request->query('method'), RentalPayment::METHODS, true)) {
                $q->where('method', $request->query('method'));
            }
            if (($from = $this->day($request, 'from')) !== null) {
                $q->where('paid_on', '>=', $from);
            }
            if (($to = $this->day($request, 'to')) !== null) {
                $q->where('paid_on', '<=', $to);
            }
            if (($term = trim((string) $request->query('q', ''))) !== '') {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('reference', 'like', $like)
                    ->orWhereIn('tenant_id', RentalTenant::withoutGlobalScopes()->where('company_id', $cid)->where('name', 'like', $like)->select('id')));
            }
            $p = $this->paginate($q->orderByDesc('paid_on')->orderByDesc('id'), $request);
            $present = $this->present($request)->prime($p->items());

            return $this->page($p, collect($p->items())->map(fn ($x) => $present->paymentBrief($x))->all());
        });
    }

    /**
     * POST rentals/payments {uuid, lease_id, amount_minor, method, paid_on, reference, notes, device_id, split}: the Payment
     * with its receipt. `split` is a list of {charge_id, amount_minor}: open bills of this lease, each at most what is left
     * on it, adding up to the amount (Payments::receive refuses anything else).
     */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $app = $this->fromApp($request);
            $uuid = $request->input('uuid');
            if ($app && ($uuid === null || $uuid === '')) {
                throw BusinessRuleException::make('uuid_required', 'Send the payment\'s id (uuid) so a resend is never counted twice.');
            }
            if ($uuid !== null && $uuid !== '' && (! is_string($uuid) || ! Str::isUuid($uuid))) {
                throw BusinessRuleException::make('uuid_invalid', 'The payment id is not valid.');
            }
            $this->day($request, 'paid_on');
            $lease = $this->lease($request, (int) $request->input('lease_id'));
            $replay = is_string($uuid) && $uuid !== '' && RentalPayment::withoutGlobalScopes()->where('company_id', $lease->company_id)->where('uuid', $uuid)->exists();
            $data = $request->only(['method', 'paid_on', 'reference', 'notes']) + ['amount_minor' => $this->minor($request, 'amount_minor'), 'uuid' => $uuid ?: null,
                'device_id' => $request->input('device_id') ?: $request->header('X-Device-Id'), 'split' => $this->split($request)];
            $payment = app(Payments::class)->receive($this->user($request), $lease, array_filter($data, fn ($v) => $v !== null), $app ? 'app' : 'web');
            $shape = $this->shape($request, $payment);

            return $replay ? $this->success($shape, 'Already received.') : $this->created($shape, 'Payment received.');
        });
    }

    /** The cashier's split as sent: a list of lines, whole numbers only (a decimal or text is refused, never rounded). @return list<array{charge_id: int, amount_minor: int}>|null */
    private function split(Request $request): ?array
    {
        $split = $request->input('split');
        if ($split === null) {
            return null;
        }
        $bad = fn () => BusinessRuleException::make('split_invalid', 'Each line of the split needs a bill and an amount in figures.');
        if (! is_array($split) || ! array_is_list($split)) {
            throw $bad();
        }
        foreach ($split as $line) {
            if (! is_array($line) || ! is_int($line['charge_id'] ?? null) || ! is_int($line['amount_minor'] ?? null)) {
                throw $bad();
            }
        }

        return array_map(fn (array $l) => ['charge_id' => $l['charge_id'], 'amount_minor' => $l['amount_minor']], $split);
    }

    /** GET rentals/payments/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->shape($request, $this->find($request, $id))));
    }

    /** POST rentals/payments/{id}/reverse {reason}: every bill it settled reopens; other credit then settles what it can. */
    public function reverse(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->shape($request, app(Payments::class)->reverse($this->user($request), $this->find($request, $id),
            (string) $request->input('reason', ''))), 'Payment reversed.'));
    }

    /** GET rentals/payments/{id}/receipt: {text (WhatsApp and 58 mm printers), pdf_url (a 30-minute signed link)}. */
    public function receipt(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(self::receiptOf($this->find($request, $id), 'staff', (int) $this->user($request)->id)));
    }

    /** @return array{text: string, pdf_url: string} */
    public static function receiptOf(RentalPayment $p, string $viewer, int $viewerId): array
    {
        return ['text' => Receipt::text($p), 'pdf_url' => RentalLinks::receiptPdf($p, $viewer, $viewerId)['url']];
    }

    private function shape(Request $request, RentalPayment $p): array
    {
        return $this->present($request)->prime([$p])->payment($p, self::receiptOf($p, 'staff', (int) $this->user($request)->id));
    }

    private function find(Request $request, int $id): RentalPayment
    {
        return $this->scoped(RentalPayment::withoutGlobalScopes()->where('company_id', $this->cid($request)), $request)->find($id) ?? throw $this->notFoundRecord('payment');
    }
}
