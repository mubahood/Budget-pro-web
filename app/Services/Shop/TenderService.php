<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\Payment;
use App\Models\SaleRecord;
use Illuminate\Support\Facades\DB;

/**
 * Tenders that are not money coming in (supermarket plan C1, C2, A9): Points, Gift card, Store credit and
 * Exchange credit. Each is a payment row on the sale (method = the tender, Payment::TENDERS) that posts
 * NO income: the money came in earlier (the gift card sold, the sale returned) or never does (points).
 * The value it uses comes off its own ledger in the same transaction, so nothing is created or lost:
 *
 *   tender row on a sale, amount A  →  the tender's balance moves by −A
 *     gift_card:    gift_card_ledger −A on the card
 *     points:       loyalty_ledger −(A ÷ point value)
 *     store_credit: the customer's account −A (a payment on account, CustomerService::balance)
 *     exchange:     a return's refund held for the new sale (ReturnService::exchange), used up here
 *
 * A refund back to a tender is the same with −A (the balance grows), and a reversal (void / reverse payment)
 * undoes exactly what the original row did. A checkout without tender rows is untouched.
 */
class TenderService
{
    /** @var array<int, float> return id => refund held for an exchange in this request (ReturnService::exchange) */
    public static array $exchangeHolds = [];

    public static function isTender(?string $method): bool
    {
        return isset(Payment::TENDERS[(string) $method]);
    }

    /**
     * Checkout payment rows with the tender rows checked and priced: tenders first (so cash covers the rest
     * and gives change), each no more than what is still due and what the tender holds. Rows:
     *   ['tender' => 'points', 'points' => 120]  (or 'amount'; the value is points × loyalty_point_value)
     *   ['tender' => 'gift_card', 'code' => '1234 5678 …', 'amount' => optional]
     *   ['tender' => 'store_credit', 'amount' => optional]
     *   ['tender' => 'exchange', 'return_id' => 9, 'amount' => …]  (only inside ReturnService::exchange)
     * A list without a tender row comes back as it was.
     */
    public function prepare(SaleRecord $sale, array $payments, float $total): array
    {
        $tenders = array_values(array_filter($payments, fn ($p) => is_array($p) && ! empty($p['tender'])));
        if ($tenders === []) {
            return $payments;
        }
        $others = array_values(array_filter($payments, fn ($p) => ! is_array($p) || empty($p['tender'])));
        $companyId = (int) $sale->company_id;
        $company = Company::withoutGlobalScopes()->find($companyId);
        $due = round($total, 2);
        $out = [];
        $usedPoints = 0;
        $usedCards = [];
        $usedCredit = 0.0;
        $customer = $sale->customer_id ? Customer::withoutGlobalScopes()->find($sale->customer_id) : null;

        foreach ($tenders as $p) {
            $kind = (string) $p['tender'];
            $asked = round((float) ($p['amount'] ?? 0), 2);
            if ($due <= 0) {
                break; // already covered: nothing more is taken from a card or the points
            }
            switch ($kind) {
                case 'points':
                    if (! LoyaltyService::enabled($company)) {
                        throw BusinessRuleException::make('feature_off', 'Loyalty points are not switched on for this shop.');
                    }
                    if ($customer === null) {
                        throw BusinessRuleException::make('customer_required', 'Choose the customer whose points pay for this.');
                    }
                    $value = LoyaltyService::pointValue($company);
                    if ($value <= 0) {
                        throw BusinessRuleException::make('points_no_value', 'Points have no value in this shop (set the value of a point in the settings).');
                    }
                    $have = (new LoyaltyService())->balance($companyId, (int) $customer->id) - $usedPoints;
                    $points = isset($p['points']) && is_numeric($p['points']) ? (int) $p['points'] : (int) floor(round($asked / $value, 6));
                    $points = min($points, (int) floor(round($due / $value, 6)));
                    if ($points > $have) {
                        throw BusinessRuleException::make('points_short', $customer->name.' has '.max(0, $have).' points.', ['points' => max(0, $have)]);
                    }
                    if ($points <= 0) {
                        throw BusinessRuleException::make('points_none', 'Not enough is due to pay with points (one point is worth '.number_format($value, 2).').');
                    }
                    $usedPoints += $points;
                    $amount = round($points * $value, 2);
                    $out[] = ['tender' => 'points', 'method' => 'points', 'amount' => $amount, 'points' => $points, 'reference' => $points.' points'];
                    break;

                case 'gift_card':
                    if (! GiftCardService::enabled($company)) {
                        throw BusinessRuleException::make('feature_off', 'Gift cards are not switched on for this shop.');
                    }
                    $card = (new GiftCardService())->usable($companyId, (string) ($p['code'] ?? ''), true);
                    $left = round((float) $card->balance - ($usedCards[$card->id] ?? 0), 2);
                    if ($left <= 0) {
                        throw BusinessRuleException::make('gift_card_empty', 'Gift card •••• '.$card->last4.' has nothing left on it.');
                    }
                    $amount = round(min($asked > 0 ? $asked : $left, $left, $due), 2);
                    $usedCards[$card->id] = ($usedCards[$card->id] ?? 0) + $amount;
                    $out[] = ['tender' => 'gift_card', 'method' => 'gift_card', 'amount' => $amount, 'gift_card_id' => (int) $card->id, 'reference' => '•••• '.$card->last4];
                    break;

                case 'store_credit':
                    if (! GiftCardService::enabled($company)) {
                        throw BusinessRuleException::make('feature_off', 'Store credit is not switched on for this shop.');
                    }
                    if ($customer === null) {
                        throw BusinessRuleException::make('customer_required', 'Choose the customer whose store credit pays for this.');
                    }
                    $left = round(max(0.0, -(new CustomerService())->balance($customer)) - $usedCredit, 2);
                    if ($left <= 0) {
                        throw BusinessRuleException::make('no_credit', $customer->name.' has no store credit.');
                    }
                    $amount = round(min($asked > 0 ? $asked : $left, $left, $due), 2);
                    $usedCredit += $amount;
                    $out[] = ['tender' => 'store_credit', 'method' => 'store_credit', 'amount' => $amount];
                    break;

                case 'exchange':
                    $rid = (int) ($p['return_id'] ?? 0);
                    $held = round((float) (self::$exchangeHolds[$rid] ?? 0), 2);
                    if ($held <= 0) {
                        throw BusinessRuleException::make('exchange_invalid', 'Exchange credit comes only from a return made in the same exchange.');
                    }
                    $amount = round(min($asked > 0 ? $asked : $held, $held, $due), 2);
                    self::$exchangeHolds[$rid] = round($held - $amount, 2);
                    $out[] = ['tender' => 'exchange', 'method' => 'exchange', 'amount' => $amount, 'return_id' => $rid, 'reference' => 'Return #'.$rid];
                    break;

                default:
                    throw BusinessRuleException::make('invalid_tender', 'Unknown way to pay: '.$kind.'.');
            }
            $due = round($due - $amount, 2);
        }

        return array_merge($out, $others);
    }

    /** A prepared tender row paid onto the sale: the payment row (no income) and the tender's balance down. */
    public function apply(SaleRecord $sale, array $p, float $amount, int $userId): Payment
    {
        $kind = (string) $p['tender'];
        $payment = (new PaymentService())->recordTender($sale, $kind, $amount, [
            'reference' => $p['reference'] ?? null, 'received_by_id' => $userId, 'shift_id' => $p['shift_id'] ?? null, 'client_uuid' => $p['client_uuid'] ?? null,
        ]);
        $this->post($sale, $payment, $userId, $p);

        return $payment;
    }

    /**
     * Money back on a sale that goes to a tender instead of the drawer (returns, A9):
     *  - 'points' / 'gift_card' / 'store_credit': back where the sale was paid from ($opts gift_card_id for a card)
     *  - 'new_gift_card': onto a new card ($opts code, optional); its code is returned in $issued
     * A negative tender row on the sale; no ledger row (no money leaves the drawer).
     */
    public function refundTo(SaleRecord $sale, string $kind, float $amount, int $userId, ?int $shiftId = null, array $opts = [], ?string &$issued = null): Payment
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw BusinessRuleException::make('invalid_amount', 'Refund amount must be greater than zero.');
        }
        if ($kind === 'store_credit' && ! $sale->customer_id) {
            throw BusinessRuleException::make('customer_required', 'Store credit needs a customer on the sale. Refund to a gift card instead.');
        }
        if ($kind === 'new_gift_card') {
            [$card, $issued] = (new GiftCardService())->issueForRefund((int) $sale->company_id, $userId, $opts['code'] ?? null, $sale->customer_id ? (int) $sale->customer_id : null);
            $opts['gift_card_id'] = (int) $card->id;
            $kind = 'gift_card';
        }
        $ref = null;
        if ($kind === 'gift_card') {
            $card = GiftCard::withoutGlobalScopes()->where('company_id', $sale->company_id)->findOrFail((int) ($opts['gift_card_id'] ?? 0));
            $ref = '•••• '.$card->last4;
        }
        if ($kind === 'points') {
            $ref = $this->pointsFor($sale, $amount).' points';
        }
        $payment = (new PaymentService())->recordTender($sale, $kind, -$amount, [
            'reference' => $ref, 'received_by_id' => $userId, 'shift_id' => $shiftId,
            'notes' => 'Refund for sale '.($sale->receipt_number ?: '#'.$sale->id).' to '.strtolower(Payment::TENDERS[$kind]),
        ]);
        $this->post($sale, $payment, $userId, $opts);

        return $payment;
    }

    /**
     * What each tender paid on this sale and has not had back yet (refunds go back there first).
     *
     * @return array<string, float>|array{gift_cards?: array<int, float>}
     */
    public function tenderedOn(SaleRecord $sale): array
    {
        $rows = Payment::withoutGlobalScopes()->where('sale_record_id', $sale->id)->whereIn('method', ['points', 'gift_card', 'store_credit'])
            ->groupBy('method')->selectRaw('method, SUM(amount) AS total')->pluck('total', 'method');
        $out = [];
        foreach (['gift_card', 'points', 'store_credit'] as $m) {
            if (round((float) ($rows[$m] ?? 0), 2) > 0) {
                $out[$m] = round((float) $rows[$m], 2);
            }
        }
        if (isset($out['gift_card'])) {
            $out['gift_cards'] = DB::table('gift_card_ledger')->where('sale_record_id', $sale->id)
                ->groupBy('gift_card_id')->selectRaw('gift_card_id, -SUM(amount) AS spent')->having('spent', '>', 0)->pluck('spent', 'gift_card_id')
                ->map(fn ($v) => round((float) $v, 2))->all();
        }

        return $out;
    }

    /** A tender row was reversed (void, reverse payment): undo exactly what the original did. */
    public function onReverse(Payment $original, Payment $contra, ?int $userId): void
    {
        $sale = $original->sale_record_id ? SaleRecord::withoutGlobalScopes()->find($original->sale_record_id) : null;
        switch ((string) $original->method) {
            case 'points':
                foreach (DB::table('loyalty_ledger')->where('payment_id', $original->id)->get() as $r) {
                    (new LoyaltyService())->write((int) $r->company_id, (int) $r->customer_id, -(int) $r->points, 'reverse', $r->sale_record_id ? (int) $r->sale_record_id : null, (int) $contra->id, $userId);
                }
                break;
            case 'gift_card':
                $gc = new GiftCardService();
                foreach (DB::table('gift_card_ledger')->where('payment_id', $original->id)->get() as $r) {
                    $gc->move(GiftCard::withoutGlobalScopes()->findOrFail($r->gift_card_id), -(float) $r->amount, 'reverse', $r->sale_record_id ? (int) $r->sale_record_id : null, (int) $contra->id, $userId);
                }
                break;
            case 'store_credit':
                if ($sale !== null) {
                    $this->post($sale, $contra, (int) ($userId ?? $contra->received_by_id), []);
                }
                break;
            case 'exchange':
                if ((float) $original->amount < 0) {
                    throw BusinessRuleException::make('exchanged', 'Goods returned on this sale were exchanged for others. Return items instead of voiding it.');
                }
                // Exchange credit came from goods returned: undoing it hands that money back, from the drawer.
                if ($sale !== null) {
                    (new PaymentService())->refund($sale, (float) $original->amount, 'cash', (int) ($userId ?? $contra->received_by_id), $original->shift_id ? (int) $original->shift_id : null);
                }
                break;
        }
    }

    /** The tender's own balance moves by −payment amount. */
    private function post(SaleRecord $sale, Payment $payment, int $userId, array $p): void
    {
        $amount = round((float) $payment->amount, 2);
        switch ((string) $payment->method) {
            case 'points':
                $points = $amount > 0 ? (int) ($p['points'] ?? 0) : $this->pointsFor($sale, -$amount);
                if ($points <= 0 || ! $sale->customer_id) {
                    throw BusinessRuleException::make('points_invalid', 'Could not work out the points for this payment.');
                }
                (new LoyaltyService())->write((int) $sale->company_id, (int) $sale->customer_id, $amount > 0 ? -$points : $points, $amount > 0 ? 'redeem' : 'reverse', (int) $sale->id, (int) $payment->id, $userId);
                break;
            case 'gift_card':
                $card = GiftCard::withoutGlobalScopes()->where('company_id', $sale->company_id)->findOrFail((int) ($p['gift_card_id'] ?? 0));
                if ($amount > 0 && (! $card->is_active || $card->isExpired())) {
                    throw BusinessRuleException::make('gift_card_inactive', 'Gift card •••• '.$card->last4.' cannot be used.');
                }
                (new GiftCardService())->move($card, -$amount, $amount > 0 ? 'redeem' : 'refund', (int) $sale->id, (int) $payment->id, $userId);
                break;
            case 'store_credit':
                $customer = Customer::withoutGlobalScopes()->findOrFail((int) $sale->customer_id);
                $acct = new Payment();
                $acct->company_id = $sale->company_id;
                $acct->customer_id = $customer->id;
                $acct->shift_id = $payment->shift_id;
                $acct->method = 'store_credit';
                $acct->amount = -$amount;
                $acct->currency = $payment->currency;
                $acct->received_at = now();
                $acct->received_by_id = $userId;
                $acct->reference = $sale->receipt_number;
                $acct->notes = $amount > 0 ? 'Store credit used on sale '.($sale->receipt_number ?: '#'.$sale->id) : 'Store credit from sale '.($sale->receipt_number ?: '#'.$sale->id);
                $acct->save();
                (new CustomerService())->recalc((int) $customer->id);
                break;
            case 'exchange':
                break; // the hold was used up in prepare(); ReturnService::exchange settles the returned sale
        }
    }

    /** Points worth $amount at the rate this sale redeemed them (or the shop's point value now). */
    private function pointsFor(SaleRecord $sale, float $amount): int
    {
        $r = DB::table('payments as p')->join('loyalty_ledger as l', 'l.payment_id', '=', 'p.id')
            ->where('p.sale_record_id', $sale->id)->where('p.method', 'points')->where('p.amount', '>', 0)->where('l.reason', 'redeem')
            ->selectRaw('SUM(p.amount) AS amount, -SUM(l.points) AS points')->first();
        $rate = ($r && (float) $r->amount > 0 && (int) $r->points > 0) ? (float) $r->amount / (int) $r->points
            : LoyaltyService::pointValue(Company::withoutGlobalScopes()->find($sale->company_id));

        return $rate > 0 ? (int) round($amount / $rate) : 0;
    }
}
