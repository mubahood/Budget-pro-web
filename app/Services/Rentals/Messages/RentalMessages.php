<?php

namespace App\Services\Rentals\Messages;

use App\Models\Company;
use App\Models\RentalLease;
use App\Models\RentalPayment;
use App\Models\RentalProperty;
use App\Models\RentalTenant;
use App\Models\RentalUnit;
use App\Services\Messaging\LogChannel;
use App\Services\Messaging\Messenger;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Payments\Receipt;
use App\Services\Rentals\RentalsModule;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Message texts (docs/estate-ledger/04-architecture.md §16): a default per kind, overridable in Settings, with
 * placeholders. They are sent as free links opened on the user's own phone (WhatsApp, SMS): the server sends nothing
 * (KR-18). A link someone opened is written down all the same (recordPrepared): a `prepared` row in message_log, and with
 * the channel's log driver (development and tests) the line a simulated message writes to the log.
 */
final class RentalMessages
{
    public const KINDS = ['bill', 'due', 'overdue', 'receipt', 'statement', 'notice'];

    public const DEFAULTS = [
        'bill' => 'Hello {tenant}, your bill for {unit}, {property} is {amount}, due {due_date}. Balance: {balance}. Pay to: {pay_to}. Thank you. {account}',
        'due' => 'Hello {tenant}, a reminder that rent of {amount} for {unit}, {property} is due on {due_date}. Balance: {balance}. Pay to: {pay_to}. {account}',
        'overdue' => 'Hello {tenant}, your rent for {unit}, {property} is overdue. You owe {balance}. Please pay as soon as you can. Pay to: {pay_to}. {account}',
        'receipt' => 'Thank you {tenant}. We received {amount} for {unit}, {property} (receipt {receipt}). Balance: {balance}. Paid up to {paid_up_to}. {account}',
        'statement' => 'Hello {landlord}, your statement for {period} is ready. Balance owed to you: {balance}. {account}',
        'notice' => 'Hello {tenant}, we have your notice to leave {unit}, {property} on {due_date}. Please clear any balance before then. {account}',
    ];

    public const PLACEHOLDERS = ['tenant', 'landlord', 'unit', 'property', 'amount', 'balance', 'due_date', 'paid_up_to', 'receipt', 'period', 'account', 'pay_to'];

    /** The account's text for a kind (its own, or the default). */
    public static function template(int $companyId, string $kind): string
    {
        $own = (array) (RentalsModule::settings($companyId)->messages ?? []);

        return trim((string) ($own[$kind] ?? '')) !== '' ? (string) $own[$kind] : (self::DEFAULTS[$kind] ?? '');
    }

    /** A message about a lease (bill, due, overdue, notice) with its figures filled in. */
    public static function forLease(RentalLease $lease, string $kind): string
    {
        $cid = (int) $lease->company_id;
        $pos = Leases::position($lease);
        $amount = $kind === 'overdue' ? $pos['overdue_minor'] : (int) ($pos['next_due']['amount_minor'] ?? $pos['owed_minor']);
        $due = $kind === 'notice' ? $lease->move_out_on?->toDateString() : ($pos['next_due']['date'] ?? null);

        return self::fill(self::template($cid, $kind), self::context($lease) + [
            'amount' => Minor::format($amount, $cid), 'balance' => Minor::format($pos['owed_minor'], $cid),
            'due_date' => $due ? Carbon::parse($due)->format('j M Y') : '', 'paid_up_to' => $pos['paid_up_to'] ? Carbon::parse($pos['paid_up_to'])->format('j M Y') : '',
        ]);
    }

    /** The receipt message for a payment (its own figures after the payment). */
    public static function forPayment(RentalPayment $p): string
    {
        $cid = (int) $p->company_id;
        $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($p->lease_id);
        $issued = Receipt::hasSnapshots() ? $p->receipt_snapshot : null;
        $pos = $issued['figures'] ?? Leases::position($lease);

        return self::fill(self::template($cid, 'receipt'), array_filter((array) ($issued['names'] ?? []), fn ($v) => $v !== '') + self::context($lease) + [
            'amount' => Minor::format((int) $p->amount_minor, $cid), 'balance' => Minor::format($pos['owed_minor'], $cid), 'receipt' => (string) $p->number,
            'paid_up_to' => $pos['paid_up_to'] ? Carbon::parse($pos['paid_up_to'])->format('j M Y') : '',
        ]);
    }

    public static function fill(string $template, array $values): string
    {
        $out = preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => (string) ($values[$m[1]] ?? ''), $template);

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', (string) $out));
    }

    /** wa.me link (opens WhatsApp with the text, on the user's own phone). */
    public static function whatsappLink(?string $phoneE164, string $text): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phoneE164);

        return $digits === '' ? null : 'https://wa.me/'.$digits.'?text='.rawurlencode($text);
    }

    /** sms: link (the phone's own messages app). */
    public static function smsLink(?string $phoneE164, string $text): ?string
    {
        $p = trim((string) $phoneE164);

        return $p === '' ? null : 'sms:'.$p.'?body='.rawurlencode($text);
    }

    /**
     * Write down a message prepared as a link on someone's own phone: status `prepared`, never `sent`, because only they
     * can send it. Nothing leaves the server, whatever the channel's driver: with the log driver the line goes to the log
     * as every simulated message does, and a live driver is not called at all.
     */
    public static function recordPrepared(int $companyId, string $channel, ?string $to, string $text, string $purpose, ?int $userId = null): ?int
    {
        $to = trim((string) $to);
        if ($to === '' || trim($text) === '' || ! in_array($channel, ['whatsapp', 'sms'], true)) {
            return null;
        }
        $id = DB::table('message_log')->insertGetId(['company_id' => $companyId, 'user_id' => $userId, 'channel' => $channel, 'to' => $to, 'purpose' => $purpose,
            'body' => $text, 'status' => 'prepared', 'created_at' => now(), 'updated_at' => now()]);
        $simulated = Messenger::channel($channel);
        if ($simulated instanceof LogChannel) {
            $simulated->send($to, $text);
        }

        return $id;
    }

    /** The reminder for a lease (the API's GET leases/{id}/reminder): its kind, text and links. */
    public static function reminder(RentalLease $lease): array
    {
        $pos = Leases::position($lease);
        $kind = $pos['overdue_minor'] > 0 ? 'overdue' : ($pos['owed_minor'] > 0 || $pos['next_due'] !== null ? 'due' : 'bill');
        $text = self::forLease($lease, $kind);
        $phone = RentalTenant::withoutGlobalScopes()->where('company_id', $lease->company_id)->whereKey($lease->tenant_id)->value('phone_e164');

        return ['kind' => $kind, 'text' => $text, 'whatsapp' => self::whatsappLink($phone, $text), 'sms' => self::smsLink($phone, $text), 'phone' => $phone];
    }

    private static function context(RentalLease $lease): array
    {
        $cid = (int) $lease->company_id;
        $s = RentalsModule::settings($cid);

        return [
            'tenant' => (string) RentalTenant::withoutGlobalScopes()->where('company_id', $cid)->whereKey($lease->tenant_id)->value('name'),
            'unit' => (string) RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->whereKey($lease->unit_id)->value('code'),
            'property' => (string) RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->whereKey($lease->property_id)->value('name'),
            'account' => (string) Company::withoutGlobalScopes()->whereKey($cid)->value('name'),
            'pay_to' => trim(preg_replace('/\s+/', ' ', (string) $s->payment_instructions)) ?: 'the office',
        ];
    }
}
