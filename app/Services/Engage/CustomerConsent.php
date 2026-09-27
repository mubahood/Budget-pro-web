<?php

namespace App\Services\Engage;

use App\Models\Company;
use App\Models\Customer;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;

/**
 * Supermarket plan C4: may the shop message this customer? One rulebook for every message
 * a customer gets (receipts, statements, debt reminders, offers).
 *
 *  - Transactional messages (a receipt, a statement, a debt reminder) go out unless the
 *    customer asked for no messages at all (messages_opt_out).
 *  - Anything else is marketing: it needs marketing_opt_in, and no opt-out.
 *  - A phone number with no customer record keeps today's behaviour (allowed).
 *
 * Refusals never throw: Messenger logs them in message_log as skipped_opt_out / skipped_no_consent.
 * The customer stops messages from one link (unsubscribeUrl), there is no inbound SMS/WhatsApp webhook.
 */
class CustomerConsent
{
    public const TRANSACTIONAL = ['receipt', 'statement', 'debt_reminder'];

    public const STATUS_OPT_OUT = 'skipped_opt_out';

    public const STATUS_NO_CONSENT = 'skipped_no_consent';

    public static function isTransactional(?string $purpose): bool
    {
        return in_array((string) $purpose, self::TRANSACTIONAL, true);
    }

    public function canMessage(?Customer $customer, ?string $purpose): bool
    {
        return $this->refusal($customer, $purpose) === null;
    }

    /** null = allowed; otherwise the message_log status explaining why not. */
    public function refusal(?Customer $customer, ?string $purpose): ?string
    {
        if ($customer === null) {
            return null;
        }
        if ($customer->messages_opt_out) {
            return self::STATUS_OPT_OUT;
        }
        if (! self::isTransactional($purpose) && ! $customer->marketing_opt_in) {
            return self::STATUS_NO_CONSENT;
        }

        return null;
    }

    /** Plain words for a refusal (message_log.error, toasts). */
    public static function reason(string $status, ?string $name = null): string
    {
        $who = $name ?: 'The customer';

        return $status === self::STATUS_OPT_OUT ? "{$who} asked for no messages." : "{$who} has not agreed to offers and news.";
    }

    /**
     * The shop's customer who owns this phone: $preferred when its phone is that number, otherwise
     * any customer of the shop with the same number (formats differ: "0772 555 010" = "+256772555010").
     */
    public function forPhone(int $companyId, ?string $e164, ?Customer $preferred = null): ?Customer
    {
        if ($e164 === null || $e164 === '') {
            return null;
        }
        $country = (string) (DB::table('companies')->where('id', $companyId)->value('country') ?: 'UG');
        if ($preferred !== null && (int) $preferred->company_id === $companyId && Phone::e164((string) $preferred->phone, $country) === $e164) {
            return $preferred;
        }
        $tail = substr(preg_replace('/\D+/', '', $e164), -9);
        if (strlen($tail) < 7) {
            return null;
        }
        $candidates = Customer::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)
            ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '.', '') LIKE ?", ['%'.$tail])
            ->orderByDesc('messages_opt_out')->get();

        return $candidates->first(fn (Customer $c) => Phone::e164((string) $c->phone, $country) === $e164);
    }

    /** Keep the "since" dates in step with the two switches (called when a customer is saved). */
    public static function stamp(Customer $customer): void
    {
        if ($customer->isDirty('marketing_opt_in')) {
            $customer->marketing_opt_in_at = $customer->marketing_opt_in ? now() : null;
        }
        if ($customer->isDirty('messages_opt_out')) {
            $customer->messages_opt_out_at = $customer->messages_opt_out ? now() : null;
        }
    }

    /** The customer asked for no messages (the unsubscribe link). Idempotent. */
    public function optOut(Customer $customer): void
    {
        if ($customer->messages_opt_out) {
            return;
        }
        $customer->messages_opt_out = true;
        self::stamp($customer);
        $customer->saveQuietlySynced();
    }

    public static function token(int $customerId): string
    {
        return substr(hash_hmac('sha256', 'customer-stop:'.$customerId, (string) config('app.key')), 0, 16);
    }

    public static function verify(int $customerId, string $token): bool
    {
        return hash_equals(self::token($customerId), $token);
    }

    public static function unsubscribeUrl(Customer $customer): string
    {
        return rtrim((string) config('saas.public_url', config('app.url')), '/').'/stop/'.$customer->id.'/'.self::token((int) $customer->id);
    }

    /** The line added to a customer message so one tap stops them. Empty without a customer record. */
    public static function footer(?Customer $customer): string
    {
        return $customer ? "\nStop messages: ".self::unsubscribeUrl($customer) : '';
    }

    public static function shopName(Customer $customer): string
    {
        return (string) (Company::withoutGlobalScopes()->where('id', $customer->company_id)->value('name') ?: 'this shop');
    }
}
