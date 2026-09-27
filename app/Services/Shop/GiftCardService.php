<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\FinancialCategory;
use App\Models\FinancialRecord;
use App\Models\GiftCard;
use App\Models\Payment;
use App\Support\LocalDate;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\DB;

/**
 * Gift cards (supermarket plan C2, `gift_cards` feature).
 *
 * Selling a card is not a sale: the money is real (a payment row in the drawer/shift and an Income row in
 * the ledger, source_type 'payment', which the profit figures leave out like other sales money) but it is
 * owed to the card holder, so it never shows in sales, profit or the Sales list. The card's balance is the
 * sum of its `gift_card_ledger` rows (issue +, redeem −, refund +, reverse ±); `gift_cards.balance` is a cache.
 * Paying with a card is a tender (TenderService) that lowers what is owed.
 */
class GiftCardService
{
    public const LEDGER_CATEGORY = 'Gift cards sold';

    public static function enabled(?Company $company): bool
    {
        return StoreFeatures::enabled($company, 'gift_cards');
    }

    /** Codes are compared without spaces or dashes, case-insensitive. */
    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $code));
    }

    public static function hash(int $companyId, string $code): string
    {
        return hash('sha256', $companyId.'|'.self::normalize($code));
    }

    /** A new 16-digit code, shown once (only its hash is kept). */
    public static function generate(): string
    {
        $d = '';
        for ($i = 0; $i < 16; $i++) {
            $d .= (string) random_int(0, 9);
        }

        return implode(' ', str_split($d, 4));
    }

    public function find(int $companyId, string $code, bool $lock = false): ?GiftCard
    {
        if (strlen(self::normalize($code)) < 4) {
            return null;
        }

        return GiftCard::withoutGlobalScopes()->where('company_id', $companyId)->where('code_hash', self::hash($companyId, $code))
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    /** A card that can pay now, or a refusal that says why. */
    public function usable(int $companyId, string $code, bool $lock = false): GiftCard
    {
        $card = $this->find($companyId, $code, $lock);
        if ($card === null) {
            throw BusinessRuleException::make('gift_card_not_found', 'No gift card has that code. Check the number and try again.');
        }
        if (! $card->is_active) {
            throw BusinessRuleException::make('gift_card_inactive', 'Gift card •••• '.$card->last4.' has been stopped.');
        }
        if ($card->isExpired()) {
            throw BusinessRuleException::make('gift_card_expired', 'Gift card •••• '.$card->last4.' expired on '.$card->expires_at->format('d M Y').'.');
        }
        if ((float) $card->balance <= 0) {
            throw BusinessRuleException::make('gift_card_empty', 'Gift card •••• '.$card->last4.' has nothing left on it.');
        }

        return $card;
    }

    /**
     * Sell a gift card: the money comes in by `$method` (cash, mobile money, card…), the card holds `$amount`.
     * Returns the card, the payment and the code (null when the cashier typed or scanned their own).
     *
     * @param  array{code?: ?string, customer_id?: ?int, shift_id?: ?int, reference?: ?string, expires_at?: ?string, client_uuid?: ?string}  $opts
     * @return array{card: GiftCard, payment: Payment, code: ?string}
     */
    public function sell(int $companyId, int $userId, float $amount, string $method, array $opts = []): array
    {
        $company = Company::withoutGlobalScopes()->find($companyId);
        if (! self::enabled($company)) {
            throw BusinessRuleException::make('feature_off', 'Gift cards are not switched on for this shop.');
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw BusinessRuleException::make('invalid_amount', 'Enter the amount to put on the gift card.');
        }
        $method = Payment::normalizeMethod($method);
        if ($method === 'credit') {
            throw BusinessRuleException::make('gift_card_credit', 'A gift card is paid for now: choose cash, mobile money or card.');
        }
        if (! empty($opts['client_uuid'])) {
            $existing = Payment::withoutGlobalScopes()->where('company_id', $companyId)->where('client_uuid', $opts['client_uuid'])->first();
            if ($existing) {
                $cardId = DB::table('gift_card_ledger')->where('payment_id', $existing->id)->value('gift_card_id');

                return ['card' => GiftCard::withoutGlobalScopes()->findOrFail($cardId), 'payment' => $existing, 'code' => null];
            }
        }

        return DB::transaction(function () use ($companyId, $company, $userId, $amount, $method, $opts) {
            [$card, $code] = $this->newCard($companyId, $userId, $opts['code'] ?? null, 'sold', $opts);

            $p = new Payment();
            $p->client_uuid = $opts['client_uuid'] ?? null;
            $p->company_id = $companyId;
            $p->customer_id = null; // never the customer's account: that would count as their credit too
            $p->shift_id = $opts['shift_id'] ?? null;
            $p->method = $method;
            $p->reference = $opts['reference'] ?? null;
            $p->amount = $amount;
            $p->currency = $company?->currency;
            $p->received_at = now();
            $p->received_by_id = $userId;
            $p->notes = 'Gift card sold •••• '.$card->last4;
            $p->save();

            $row = new FinancialRecord();
            $row->financial_category_id = $this->ledgerCategory($companyId)->id;
            $row->company_id = $companyId;
            $row->user_id = $userId;
            $row->created_by_id = $userId;
            $row->amount = $amount;
            $row->quantity = 1;
            $row->type = 'Income';
            $row->payment_method = $method;
            $row->recipient = '';
            $row->receipt = 'GC-'.$card->last4;
            $row->date = LocalDate::today($companyId);
            $row->description = 'Gift card sold •••• '.$card->last4.' (owed to the card holder)';
            $row->source_type = 'payment'; // money received, not profit (FinancialReportService leaves it out of other income)
            $row->source_id = $p->id;
            $row->currency = $p->currency;
            $row->save();
            $p->financial_record_id = $row->id;
            $p->saveQuietlySynced();

            $this->move($card, $amount, 'issue', null, (int) $p->id, $userId);

            return ['card' => $card->fresh(), 'payment' => $p, 'code' => $code];
        });
    }

    /**
     * A new card holding a refund (A9 "refund to a gift card"): no money comes in, the sale's refund row
     * (TenderService::refundTo) records it. Returns [card, code or null].
     *
     * @return array{0: GiftCard, 1: ?string}
     */
    public function issueForRefund(int $companyId, int $userId, ?string $code, ?int $customerId): array
    {
        return $this->newCard($companyId, $userId, $code, 'refund', ['customer_id' => $customerId]);
    }

    /** @return array{0: GiftCard, 1: ?string} */
    private function newCard(int $companyId, int $userId, ?string $typed, string $source, array $opts): array
    {
        $typed = $typed !== null ? trim($typed) : '';
        $plain = null;
        if ($typed !== '') {
            $norm = self::normalize($typed);
            if (strlen($norm) < 6 || strlen($norm) > 32) {
                throw BusinessRuleException::make('gift_card_code', 'A gift card code has 6 to 32 letters or digits.');
            }
            if ($this->find($companyId, $typed) !== null) {
                throw BusinessRuleException::make('gift_card_taken', 'A gift card with that code already exists. Scan a new card.');
            }
            $code = $typed;
        } else {
            do {
                $code = $plain = self::generate();
            } while ($this->find($companyId, $code) !== null);
        }
        $norm = self::normalize($code);
        $card = new GiftCard();
        $card->forceFill([
            'company_id' => $companyId, 'code_hash' => self::hash($companyId, $code), 'last4' => substr($norm, -4), 'balance' => 0,
            'expires_at' => ! empty($opts['expires_at']) ? \Illuminate\Support\Carbon::parse($opts['expires_at'])->endOfDay() : null,
            'customer_id' => $opts['customer_id'] ?? null, 'is_active' => true, 'source' => $source, 'created_by' => $userId,
        ])->save();

        return [$card, $plain];
    }

    /** One ledger row and the balance cache (the card row locked). Never below zero. */
    public function move(GiftCard $card, float $amount, string $reason, ?int $saleId, ?int $paymentId, ?int $userId): void
    {
        $amount = round($amount, 2);
        $locked = GiftCard::withoutGlobalScopes()->lockForUpdate()->findOrFail($card->id);
        $balance = round((float) DB::table('gift_card_ledger')->where('gift_card_id', $card->id)->sum('amount') + $amount, 2);
        if ($balance < -0.004) {
            throw BusinessRuleException::make('gift_card_short', 'Gift card •••• '.$card->last4.' has only '.number_format((float) $locked->balance, 2).' left.');
        }
        DB::table('gift_card_ledger')->insert([
            'company_id' => $card->company_id, 'gift_card_id' => $card->id, 'amount' => $amount, 'reason' => $reason,
            'sale_record_id' => $saleId, 'payment_id' => $paymentId, 'created_by' => $userId, 'created_at' => now(),
        ]);
        $locked->forceFill(['balance' => $balance])->save();
        $card->balance = $balance;
    }

    /** Stop a card (lost / stolen). Its balance stays owed, and shows in the report until it is used or expires. */
    public function deactivate(GiftCard $card): void
    {
        $card->forceFill(['is_active' => false])->save();
    }

    /** What the shop owes on gift cards now (active, unexpired cards). */
    public function liability(int $companyId): float
    {
        return round((float) GiftCard::withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', 1)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->sum('balance'), 2);
    }

    private function ledgerCategory(int $companyId): FinancialCategory
    {
        $cat = FinancialCategory::withoutGlobalScopes()->where('company_id', $companyId)->where('name', self::LEDGER_CATEGORY)->first();
        if ($cat === null) {
            $cat = new FinancialCategory();
            $cat->company_id = $companyId;
            $cat->name = self::LEDGER_CATEGORY;
            $cat->type = 'Income';
            $cat->save();
        }

        return $cat;
    }
}
