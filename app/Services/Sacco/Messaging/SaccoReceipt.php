<?php

namespace App\Services\Sacco\Messaging;

use App\Models\SaccoMember;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSetting;
use App\Models\SaccoShareAccount;
use App\Models\SaccoTransaction;
use App\Services\Sacco\Reports\SaccoReportFormat;
use Illuminate\Support\Facades\DB;

/**
 * A member's printed or shared receipt (04 §13.2 `GET sacco/transactions/{id}/receipt`): the same lines as plain
 * text for a 58 mm Bluetooth printer (32 characters wide, ESC/POS-safe ASCII), a small PDF, and the WhatsApp text an
 * officer shares from the phone. Headed with the SACCO's own name, CS No. and licence; "Regulated by …" only when
 * licensed; the platform is never named.
 */
final class SaccoReceipt
{
    public const WIDTH = 32;

    /** @return array{header: list<string>, lines: list<array{0: string, 1: string}>, footer: list<string>} */
    public static function parts(SaccoTransaction $tx): array
    {
        $s = SaccoSetting::for((int) $tx->company_id);
        $cur = $tx->currency;
        $money = fn (int $m) => $cur.' '.SaccoReportFormat::money($m, $cur);
        $member = $tx->member_id === null ? null : SaccoMember::withoutGlobalScopes()->find($tx->member_id);
        $account = $tx->savings_account_id ? SaccoSavingsAccount::withoutGlobalScopes()->find($tx->savings_account_id) : null;
        $shares = $tx->share_account_id ? SaccoShareAccount::withoutGlobalScopes()->find($tx->share_account_id) : null;
        $officer = $tx->received_by_id === null ? null : DB::table('admin_users')->where('id', $tx->received_by_id)->value('name');
        $header = array_values(array_filter([(string) ($s->legal_name ?? ''), $s?->registration_no ? 'CS No. '.$s->registration_no : null,
            $s?->licence_no ? 'Licence '.$s->licence_no : null, $s?->regulatedLine() ? 'Regulated by '.$s->regulator()['short'] : null]));
        $lines = [['Receipt', (string) ($tx->receipt_no ?? '')]];
        if ($tx->device_receipt_no) {
            $lines[] = ['Phone receipt', (string) $tx->device_receipt_no];
        }
        $lines[] = ['Date', $tx->value_date->format('d/m/Y')];
        $lines[] = ['What', ucfirst(str_replace('_', ' ', $tx->kind))];
        if ($member) {
            $lines[] = ['Member', trim($member->member_no.' '.$member->full_name)];
        }
        if ($account) {
            $lines[] = ['Account', $account->account_no];
        }
        if ($tx->shares_count) {
            $lines[] = ['Shares', (string) $tx->shares_count];
        }
        $lines[] = ['Amount', $money((int) $tx->amount_minor)];
        if ((int) $tx->fee_minor > 0) {
            $lines[] = ['Fee', $money((int) $tx->fee_minor)];
        }
        if ($account && $tx->status === 'posted') {
            $lines[] = ['Balance now', $money((int) $account->balance_minor)];
        }
        if ($shares && $tx->status === 'posted') {
            $lines[] = ['Shares held', (string) $shares->shares_count];
        }
        $lines[] = ['Paid by', ucfirst((string) $tx->channel)];
        if ($tx->status !== 'posted') {
            $lines[] = ['Status', $tx->status === 'held' ? 'Received, being allocated' : str_replace('_', ' ', (string) $tx->status)];
        }
        if ($officer) {
            $lines[] = ['Served by', (string) $officer];
        }

        return ['header' => $header, 'lines' => $lines, 'footer' => ['Keep this receipt.', (string) ($s->short_name ?? '')]];
    }

    /** Plain text for the Bluetooth printer. */
    public static function text(SaccoTransaction $tx, int $width = self::WIDTH): string
    {
        $p = self::parts($tx);
        $ascii = fn (string $s) => preg_replace('/[^\x20-\x7E]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s)) ?? '';
        $center = fn (string $s) => str_pad(mb_substr($ascii($s), 0, $width), $width, ' ', STR_PAD_BOTH);
        $out = array_map($center, $p['header']);
        $out[] = str_repeat('-', $width);
        foreach ($p['lines'] as [$label, $value]) {
            $label = $ascii($label);
            $value = $ascii($value);
            if (mb_strlen($label) + mb_strlen($value) + 1 <= $width) {
                $out[] = $label.str_repeat(' ', $width - mb_strlen($label) - mb_strlen($value)).$value;
            } else {
                $out[] = $label.':';
                foreach (str_split($value, $width) as $chunk) {
                    $out[] = str_pad($chunk, $width, ' ', STR_PAD_LEFT);
                }
            }
        }
        $out[] = str_repeat('-', $width);
        foreach ($p['footer'] as $f) {
            $out[] = $center($f);
        }

        return rtrim(implode("\n", array_map('rtrim', $out)))."\n";
    }

    public static function pdf(SaccoTransaction $tx): string
    {
        return app('dompdf.wrapper')->loadHTML(view()->file(dirname(__DIR__, 4).'/resources/views/sacco/receipt.blade.php', ['p' => self::parts($tx)])->render())
            ->setPaper([0, 0, 226.77, 566.93])->output(); // 80 mm wide
    }
}
