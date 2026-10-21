<?php

namespace App\Services\Sacco\Messaging;

use App\Models\SaccoMember;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSetting;
use App\Models\SaccoShareAccount;
use App\Models\SaccoTransaction;
use App\Services\Sacco\Reports\SaccoReportFormat;

/**
 * The words of a SACCO's messages to its members (docs/akiba/04-architecture.md §10, P1.17), in English and
 * Kiswahili; Luganda falls back to English until it is translated. Placeholders only; the SMS form of each is at
 * most 160 characters (longer values are shortened, never the meaning), and the WhatsApp form may say a little more.
 * Every message is signed with the SACCO's own short name and never names the platform or any other app (owner's
 * rule: Akiba Ledger stands alone).
 */
final class SaccoMessages
{
    public const LANGS = ['en', 'sw', 'lg'];

    public const SMS_MAX = 160;

    /** key => [lang => [sms, whatsapp]] */
    public const TEXTS = [
        'receipt_deposit' => [
            'en' => ['{sacco}: received {amount} to {account} on {date}. Balance {balance}. Rct {receipt}.',
                "{sacco}\nReceived {amount} to your account {account} on {date}.\nBalance: {balance}\nReceipt: {receipt}"],
            'sw' => ['{sacco}: tumepokea {amount} kwa {account} tarehe {date}. Salio {balance}. Risiti {receipt}.',
                "{sacco}\nTumepokea {amount} kwa akaunti yako {account} tarehe {date}.\nSalio: {balance}\nRisiti: {receipt}"],
        ],
        'receipt_withdrawal' => [
            'en' => ['{sacco}: paid {amount} from {account}. Balance {balance}. Rct {receipt}.',
                "{sacco}\nPaid {amount} from your account {account} on {date}.\nBalance: {balance}\nReceipt: {receipt}"],
            'sw' => ['{sacco}: umelipwa {amount} kutoka {account}. Salio {balance}. Risiti {receipt}.',
                "{sacco}\nUmelipwa {amount} kutoka akaunti yako {account} tarehe {date}.\nSalio: {balance}\nRisiti: {receipt}"],
        ],
        'receipt_shares' => [
            'en' => ['{sacco}: {count} shares bought, {amount}. You hold {total} shares. Rct {receipt}.',
                "{sacco}\n{count} shares bought for {amount} on {date}.\nYou now hold {total} shares.\nReceipt: {receipt}"],
            'sw' => ['{sacco}: hisa {count} zimenunuliwa, {amount}. Una hisa {total}. Risiti {receipt}.',
                "{sacco}\nHisa {count} zimenunuliwa kwa {amount} tarehe {date}.\nSasa una hisa {total}.\nRisiti: {receipt}"],
        ],
        'receipt_fee' => [
            'en' => ['{sacco}: received {amount} for {what} on {date}. Rct {receipt}.', "{sacco}\nReceived {amount} for {what} on {date}.\nReceipt: {receipt}"],
            'sw' => ['{sacco}: tumepokea {amount} kwa {what} tarehe {date}. Risiti {receipt}.', "{sacco}\nTumepokea {amount} kwa {what} tarehe {date}.\nRisiti: {receipt}"],
        ],
        'receipt_transfer' => [
            'en' => ['{sacco}: moved {amount} from {account} to {to}. Balance {balance}. Rct {receipt}.',
                "{sacco}\nMoved {amount} from {account} to {to} on {date}.\nBalance: {balance}\nReceipt: {receipt}"],
            'sw' => ['{sacco}: {amount} imehamishwa kutoka {account} kwenda {to}. Salio {balance}. Risiti {receipt}.',
                "{sacco}\n{amount} imehamishwa kutoka {account} kwenda {to} tarehe {date}.\nSalio: {balance}\nRisiti: {receipt}"],
        ],
        'receipt_reversal' => [
            'en' => ['{sacco}: receipt {original} of {amount} was reversed: {reason}. Rct {receipt}.',
                "{sacco}\nReceipt {original} of {amount} was reversed on {date}.\nReason: {reason}\nReceipt: {receipt}"],
            'sw' => ['{sacco}: risiti {original} ya {amount} imefutwa: {reason}. Risiti {receipt}.',
                "{sacco}\nRisiti {original} ya {amount} imefutwa tarehe {date}.\nSababu: {reason}\nRisiti: {receipt}"],
        ],
        'terms_notice' => [
            'en' => ['{sacco}: from {date} {product} pays {rate} a year. Ask us for the new terms.',
                "{sacco}\nFrom {date}, {product} pays {rate} a year.\nAsk us for the full new terms."],
            'sw' => ['{sacco}: kuanzia {date} {product} italipa riba {rate} kwa mwaka. Uliza masharti mapya.',
                "{sacco}\nKuanzia {date}, {product} italipa riba {rate} kwa mwaka.\nUliza masharti mapya."],
        ],
    ];

    /** The kinds of receipt and the template each uses. */
    public const RECEIPT_KEYS = ['deposit' => 'receipt_deposit', 'withdrawal' => 'receipt_withdrawal', 'share_purchase' => 'receipt_shares', 'entrance_fee' => 'receipt_fee',
        'fee' => 'receipt_fee', 'internal_transfer' => 'receipt_transfer', 'reversal' => 'receipt_reversal'];

    /**
     * @param  array<string, string>  $values
     * @return array{sms: string, whatsapp: string}
     */
    public static function render(string $key, string $lang, array $values): array
    {
        $texts = self::TEXTS[$key] ?? throw new \InvalidArgumentException("No message {$key}.");
        [$sms, $wa] = $texts[$lang] ?? $texts['en'];
        $fill = fn (string $t, array $v) => trim(strtr($t, array_combine(array_map(fn ($k) => '{'.$k.'}', array_keys($v)), array_values($v))));
        $text = $fill($sms, $values);
        if (mb_strlen($text) > self::SMS_MAX) {
            // Shorten the free-text parts first (the SACCO's name, a reason, an account label), never the figures.
            foreach (['reason', 'what', 'sacco', 'product', 'to', 'account'] as $k) {
                if (isset($values[$k]) && mb_strlen($text) > self::SMS_MAX) {
                    $over = mb_strlen($text) - self::SMS_MAX;
                    $values[$k] = mb_substr($values[$k], 0, max(3, mb_strlen($values[$k]) - $over - 1)).'…';
                    $text = $fill($sms, $values);
                }
            }
            $text = mb_substr($text, 0, self::SMS_MAX);
        }

        return ['sms' => $text, 'whatsapp' => $fill($wa, $values)];
    }

    /**
     * A posted receipt's message for its member, in the SACCO's words.
     *
     * @return array{key: string, sms: string, whatsapp: string}|null
     */
    public static function forReceipt(SaccoTransaction $tx, ?string $lang = null): ?array
    {
        $key = self::RECEIPT_KEYS[$tx->kind] ?? null;
        $s = SaccoSetting::for((int) $tx->company_id);
        if ($key === null || $s === null) {
            return null;
        }
        $cur = $s->currency;
        $money = fn (int $minor) => $cur.' '.SaccoReportFormat::money($minor, $cur);
        $account = $tx->savings_account_id ? SaccoSavingsAccount::withoutGlobalScopes()->find($tx->savings_account_id) : null;
        $values = ['sacco' => (string) ($s->short_name ?: $s->legal_name), 'amount' => $money((int) $tx->amount_minor), 'date' => $tx->value_date->format('d/m/Y'),
            'receipt' => (string) $tx->receipt_no, 'account' => (string) ($account->account_no ?? ''), 'balance' => $account ? $money((int) $account->balance_minor) : ''];
        if ($tx->kind === 'share_purchase') {
            $values += ['count' => (string) $tx->shares_count, 'total' => (string) (int) SaccoShareAccount::withoutGlobalScopes()->whereKey($tx->share_account_id)->value('shares_count')];
        } elseif (in_array($tx->kind, ['fee', 'entrance_fee'], true)) {
            $values['what'] = $tx->kind === 'entrance_fee' ? ($lang === 'sw' ? 'ada ya kujiunga' : 'the entrance fee') : (string) ($tx->narration ?: ($lang === 'sw' ? 'ada' : 'a fee'));
        } elseif ($tx->kind === 'internal_transfer') {
            $values['to'] = (string) SaccoSavingsAccount::withoutGlobalScopes()->whereKey($tx->counter_account_id)->value('account_no');
        } elseif ($tx->kind === 'reversal') {
            $values += ['original' => (string) SaccoTransaction::withoutGlobalScopes()->whereKey($tx->reverses_id)->value('receipt_no'), 'reason' => (string) $tx->reason];
        }
        if ((int) $tx->fee_minor > 0 && $tx->kind === 'withdrawal') {
            $values['amount'] .= ' (fee '.$money((int) $tx->fee_minor).')';
        }

        return ['key' => $key] + self::render($key, $lang ?? self::langOf($tx->member_id === null ? null : SaccoMember::withoutGlobalScopes()->find($tx->member_id)), $values);
    }

    /** The member's language (P3 adds the member's own choice); English until then. */
    public static function langOf(?SaccoMember $member): string
    {
        $lang = $member === null ? null : ($member->getAttribute('message_lang') ?? null);

        return in_array($lang, self::LANGS, true) ? (string) $lang : 'en';
    }
}
