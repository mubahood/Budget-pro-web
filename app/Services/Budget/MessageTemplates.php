<?php

namespace App\Services\Budget;

use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * The words of an event's messages: reminders, thank-yous, payment confirmations, invitations to pledge and
 * committee meeting notices. Each event may keep its own text per kind (settings.messages); otherwise the default
 * text in the event's language (settings.message_lang: en, lg or sw) from resources/data/budget_templates.json.
 * The phone renders the same texts with the same rules (budget-pro-mobo lib/pledges/pledge_messages.dart), and
 * the messages always leave from the person's own phone (WhatsApp or SMS links): the server never sends them.
 *
 * Rendering: known placeholders are replaced, unknown ones stay as typed, a line whose placeholders all came out
 * empty is dropped (so "Pay to: {payment_number}" disappears when there is no number), three or more new lines
 * become two, and the result is trimmed.
 */
final class MessageTemplates
{
    public const KINDS = ['reminder', 'thanks', 'confirmation', 'invitation', 'meeting'];

    public const LANGS = ['en', 'lg', 'sw'];

    public const PLACEHOLDERS = ['name', 'amount', 'pledged', 'paid', 'balance', 'payment', 'how', 'event', 'date', 'days_left', 'venue',
        'treasurer', 'treasurer_phone', 'payment_number', 'payment_details', 'meeting_when', 'meeting_where'];

    /** The default text of a kind in a language (English when that language has none). */
    public static function defaultText(string $kind, string $lang = 'en'): string
    {
        $texts = (array) (BudgetTemplates::defaultMessages()[$kind] ?? []);

        return (string) ($texts[$lang] ?? $texts['en'] ?? '');
    }

    /** The text an event uses for a kind: its own, else the default in its language. */
    public static function textFor(string $kind, array $settings): string
    {
        $own = trim((string) ($settings['messages'][$kind] ?? ''));

        return $own !== '' ? (string) $settings['messages'][$kind] : self::defaultText($kind, (string) ($settings['message_lang'] ?? 'en') ?: 'en');
    }

    /** @param array<string, string> $values */
    public static function render(string $text, array $values): string
    {
        $lines = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
            $known = 0;
            $empty = 0;
            $out = preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($values, &$known, &$empty) {
                if (! array_key_exists($m[1], $values)) {
                    return $m[0];
                }
                $known++;
                $v = (string) $values[$m[1]];
                if (trim($v) === '') {
                    $empty++;
                }

                return $v;
            }, $line);
            if ($known > 0 && $empty === $known) {
                continue;
            }
            $lines[] = $out;
        }

        return trim((string) preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)));
    }

    /**
     * The placeholder values for one message.
     *
     * @param  array<string, mixed>  $event  id, name, date (Y-m-d or null), venue; settings and payment_details (EventSettings::read)
     * @param  object|array|null  $pledge  name, amount, paid_amount, not_paid_amount
     * @param  object|array|null  $payment  amount, method, receipt_no (a confirmation)
     * @return array<string, string>
     */
    public static function values(array $event, object|array|null $pledge = null, object|array|null $payment = null, ?string $currency = null, ?Carbon $today = null): array
    {
        $settings = (array) ($event['settings'] ?? []);
        $pay = (array) ($event['payment_details'] ?? []);
        $p = $pledge === null ? null : (object) $pledge;
        $x = $payment === null ? null : (object) $payment;
        $label = trim((string) ($settings['currency_display'] ?? '')) ?: strtoupper((string) ($currency ?? 'UGX'));
        $decimals = in_array(strtoupper((string) ($currency ?? 'UGX')), Money::ZERO_DECIMAL, true) ? 0 : 2;
        $money = fn ($v) => $label.' '.number_format(abs((float) $v), $decimals);
        $date = ! empty($event['date']) ? Carbon::parse((string) $event['date'])->startOfDay() : null;
        $today = ($today ?? now())->copy()->startOfDay();
        $left = $date ? (int) round($today->diffInDays($date, false)) : null;
        $methods = ['cash' => 'Cash', 'mobile_money' => 'Mobile money', 'bank' => 'Bank', 'card' => 'Card', 'cheque' => 'Cheque', 'other' => 'Other'];

        return [
            'name' => $p ? (trim((string) ($p->name ?? '')) ?: 'friend') : '',
            'amount' => $p ? $money($p->amount ?? 0) : '',
            'pledged' => $p ? $money($p->amount ?? 0) : '',
            'paid' => $p ? $money($p->paid_amount ?? 0) : '',
            'balance' => $p ? $money(max(0, (float) ($p->not_paid_amount ?? ((float) ($p->amount ?? 0) - (float) ($p->paid_amount ?? 0))))) : '',
            'payment' => $x ? $money($x->amount ?? 0) : '',
            'how' => $x ? implode(', ', array_filter([$methods[(string) ($x->method ?? '')] ?? ucfirst(str_replace('_', ' ', (string) ($x->method ?? ''))), (string) ($x->receipt_no ?? '')])) : '',
            'event' => (string) ($event['name'] ?? ''),
            'date' => $date ? $date->format('j M Y') : '',
            'days_left' => $left === null || $left < 0 ? '' : (string) $left,
            'venue' => (string) ($event['venue'] ?? ''),
            'treasurer' => (string) ($pay['treasurer'] ?? ''),
            'treasurer_phone' => self::localPhone((string) ($pay['treasurer_phone'] ?? '')),
            'payment_number' => self::localPhone((string) (($pay['mtn'] ?? '') !== '' ? $pay['mtn'] : ($pay['airtel'] ?? ''))),
            'payment_details' => implode("\n", self::paymentLines($pay)),
            'meeting_when' => (string) ($settings['meeting']['when'] ?? ''),
            'meeting_where' => (string) ($settings['meeting']['where'] ?? ''),
        ];
    }

    /** "MTN MoMo: 0772 123 456 (Doreen)", "Airtel Money: …", "Bank: Stanbic, Mukono, A/C 9030… (Brian T.)". @return list<string> */
    public static function paymentLines(array $d): array
    {
        $named = fn (string $v, string $name) => $name === '' ? $v : "{$v} ({$name})";
        $s = fn (string $k) => trim((string) ($d[$k] ?? ''));
        $lines = [];
        if ($s('mtn') !== '') {
            $lines[] = 'MTN MoMo: '.$named(self::localPhone($s('mtn')), $s('mtn_name'));
        }
        if ($s('airtel') !== '') {
            $lines[] = 'Airtel Money: '.$named(self::localPhone($s('airtel')), $s('airtel_name'));
        }
        if ($s('bank') !== '' || $s('bank_account') !== '') {
            $lines[] = 'Bank: '.$named(implode(', ', array_filter([$s('bank'), $s('bank_account') !== '' ? 'A/C '.$s('bank_account') : ''])), $s('bank_account_name'));
        }

        return $lines;
    }

    /** +256772123456 → "0772 123 456"; anything else as stored. */
    public static function localPhone(string $raw): string
    {
        $t = preg_replace('/\s+/', '', $raw);
        if (! preg_match('/^\+256(\d{9})$/', (string) $t, $m)) {
            return trim($raw);
        }
        $d = '0'.$m[1];

        return substr($d, 0, 4).' '.substr($d, 4, 3).' '.substr($d, 7);
    }
}
