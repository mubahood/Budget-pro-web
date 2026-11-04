<?php

namespace App\Services\Rentals\Payments;

use App\Models\Company;
use App\Models\RentalAllocation;
use App\Models\RentalCharge;
use App\Models\RentalLease;
use App\Models\RentalPayment;
use App\Models\RentalProperty;
use App\Models\RentalTenant;
use App\Models\RentalUnit;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\RentalDocument;
use App\Support\Rentals\AmountWords;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant's receipt (docs/estate-ledger/04-architecture.md §10.4): the same lines as plain text for WhatsApp and a 58 mm
 * Bluetooth printer (32 characters wide, ASCII only), and a small PDF. Headed with the account's own name; what the
 * payment settled, bill by bill; the credit left, what is still owed and "paid up to" as they stood when it was issued; who received
 * it; the account's footer. No other product is ever named (KR-05).
 */
final class Receipt
{
    public const WIDTH = 32;

    /** A till's paper, in points: 80 mm. */
    private const TILL_WIDTH = 226.77;

    private static ?bool $snapshots = null;

    /** How each method reads on a receipt. */
    public const METHODS = ['cash' => 'Cash', 'mobile_money' => 'Mobile money', 'bank' => 'Bank', 'cheque' => 'Cheque', 'deposit' => 'From the deposit',
        'waiver' => 'Forgiven', 'opening' => 'Brought forward'];

    /**
     * @return array{header: list<string>, lines: list<array{0: string, 1: string}>, settled: list<array{0: string, 1: string}>,
     *               after: list<array{0: string, 1: string}>, footer: list<string>, reversed: ?string, words: string, logo?: string}
     */
    public static function parts(RentalPayment $p): array
    {
        $issued = self::hasSnapshots() ? $p->receipt_snapshot : null;
        $reversed = $p->reversed_at !== null ? 'Reversed on '.$p->reversed_at->format('j M Y').($p->reverse_reason ? ': '.$p->reverse_reason : '') : null;

        return (is_array($issued) ? $issued : self::compose($p)) + ['reversed' => $reversed];
    }

    /**
     * Write down what the receipt says, once, inside the lock that posted the payment: a reprint shows it as issued,
     * whatever happens to the names, the tenant's balance or the account's footer later; only a reversal adds to it.
     */
    public static function stamp(RentalPayment $p): RentalPayment
    {
        $p = $p->fresh();
        if (self::hasSnapshots() && $p->receipt_snapshot === null) {
            $p->forceFill(['receipt_snapshot' => self::compose($p)])->save();
        }

        return $p->fresh();
    }

    /** The column arrived after the first payments (a copy not migrated yet keeps reading live). */
    public static function hasSnapshots(): bool
    {
        return self::$snapshots = self::$snapshots ?: Schema::hasColumn('rental_payments', 'receipt_snapshot');
    }

    /** What the receipt says now. @return array<string, mixed> */
    private static function compose(RentalPayment $p): array
    {
        $cid = (int) $p->company_id;
        $company = Company::withoutGlobalScopes()->find($cid);
        $settings = RentalsModule::settings($cid);
        $money = fn (int $minor) => Minor::format($minor, $cid);
        $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->find($p->lease_id);
        $tenant = (string) RentalTenant::withoutGlobalScopes()->where('company_id', $cid)->whereKey($p->tenant_id)->value('name');
        $unit = (string) RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->whereKey($p->unit_id)->value('code');
        $property = (string) RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->whereKey($p->property_id)->value('name');
        $receivedBy = $p->received_by_id ? (string) DB::table('admin_users')->where('id', $p->received_by_id)->where('company_id', $cid)->value('name') : '';

        $header = array_values(array_filter([(string) ($company?->name ?? ''), trim((string) ($company?->phone_number ?? '')), 'Receipt']));
        $method = self::METHODS[$p->method] ?? ucfirst(str_replace('_', ' ', (string) $p->method));
        $lines = [['Receipt no', (string) $p->number], ['Date', $p->paid_on->format('j M Y')], ['Tenant', $tenant],
            ['Unit', trim($unit.($property !== '' ? ', '.$property : ''))], ['Amount', $money((int) $p->amount_minor)], ['Paid by', $method]];
        if ($p->reference) {
            $lines[] = ['Reference', (string) $p->reference];
        }

        // What it settled, bill by bill (the allocations it made; a later correction is shown as reversed).
        $allocs = RentalAllocation::withoutGlobalScopes()->where('company_id', $cid)->where('payment_id', $p->id)->whereNull('reverses_id')->where('amount_minor', '>', 0)
            ->orderBy('id')->get(['charge_id', 'amount_minor']);
        $descriptions = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $allocs->pluck('charge_id')->all() ?: [0])->pluck('description', 'id');
        $settled = [];
        foreach ($allocs->groupBy('charge_id') as $chargeId => $rows) {
            $settled[] = [(string) ($descriptions[$chargeId] ?? 'Bill'), $money((int) $rows->sum('amount_minor'))];
        }
        $after = [];
        $pos = null;
        if ($lease !== null) {
            $pos = Leases::position($lease);
            $after[] = ['Credit left', $money((int) $pos['credit_minor'])];
            $after[] = ['Still owed', $money((int) $pos['owed_minor'])];
            $after[] = ['Paid up to', $pos['paid_up_to'] ? Carbon::parse($pos['paid_up_to'])->format('j M Y') : 'Not yet'];
        }
        if ($receivedBy !== '') {
            $after[] = ['Received by', $receivedBy];
        }
        $footer = array_values(array_filter([trim((string) ($settings->receipt_footer ?? '')), 'Thank you.']));

        return ['header' => $header, 'logo' => RentalDocument::logoPath($company), 'lines' => $lines, 'words' => AmountWords::of((int) $p->amount_minor, $cid), 'settled' => $settled, 'after' => $after, 'footer' => $footer,
            'figures' => ['credit_minor' => (int) ($pos['credit_minor'] ?? 0), 'owed_minor' => (int) ($pos['owed_minor'] ?? 0), 'paid_up_to' => $pos['paid_up_to'] ?? null],
            'names' => ['tenant' => $tenant, 'unit' => $unit, 'property' => $property]];
    }

    /** Plain text for WhatsApp and the 58 mm printer: $width characters a line, ASCII only. */
    public static function text(RentalPayment $p, int $width = self::WIDTH): string
    {
        $parts = self::parts($p);
        $center = fn (string $s) => str_pad(mb_substr(self::ascii($s), 0, $width), $width, ' ', STR_PAD_BOTH);
        $rule = str_repeat('-', $width);
        $out = array_map($center, $parts['header']);
        $out[] = $rule;
        foreach ($parts['lines'] as $line) {
            array_push($out, ...self::pair($line, $width));
        }
        if ($parts['settled'] !== []) {
            $out[] = $rule;
            $out[] = 'Settled:';
            foreach ($parts['settled'] as $line) {
                array_push($out, ...self::pair($line, $width));
            }
        }
        $out[] = $rule;
        foreach ($parts['after'] as $line) {
            array_push($out, ...self::pair($line, $width));
        }
        if ($parts['reversed'] !== null) {
            $out[] = $rule;
            foreach (self::wrap('REVERSED. '.$parts['reversed'], $width) as $l) {
                $out[] = $l;
            }
        }
        $out[] = $rule;
        foreach ($parts['footer'] as $f) {
            foreach (self::wrap($f, $width) as $l) {
                $out[] = $center($l);
            }
        }

        return rtrim(implode("\n", array_map('rtrim', $out)))."\n";
    }

    /**
     * The receipt as a PDF, as issued: on a till's 80 mm paper cut to its content, or 'a5' for an office printer. The
     * amount stays on one line on both. It prints the same each time: nothing live but a logo on a receipt issued before
     * logos were written down.
     */
    public static function pdf(RentalPayment $p, string $size = 'till'): string
    {
        $parts = self::parts($p);
        $cid = (int) $p->company_id;
        $narrow = $size !== 'a5';
        $header = array_values($parts['header']);
        $title = (string) ($header[count($header) - 1] ?? 'Receipt');
        $head = RentalDocument::issued($cid, (string) ($header[0] ?? ''), count($header) > 2 ? (string) $header[1] : null, isset($parts['logo']) ? (string) $parts['logo'] : null, array_key_exists('logo', $parts));
        $mono = fn (string $value) => preg_match('/^(?:[A-Z]{3}\s)?-?[\d,]+(?:\.\d+)?$/', $value) === 1;
        $rows = fn (array $list) => array_map(fn ($l) => [(string) $l[0], (string) $l[1], $mono((string) $l[1])], $list);
        $amount = '';
        $amountLabel = 'Amount';
        $number = '';
        $lines = [];
        foreach ($parts['lines'] as $line) {
            if ($line[0] === 'Amount' && $amount === '') {
                $amount = (string) $line[1];
            } else {
                $number = $line[0] === 'Receipt no' ? (string) $line[1] : $number;
                $lines[] = $line;
            }
        }
        $html = view('rentals.receipt', ['p' => $parts, 'amount' => $amount, 'amountLabel' => $amountLabel, 'lines' => $rows($lines), 'settled' => $rows($parts['settled']), 'after' => $rows($parts['after']),
            'doc' => ['title' => $title, 'sub' => [$number], 'head' => $head, 'narrow' => $narrow, 'small' => ! $narrow]])->render();
        if (! $narrow) {
            return RentalDocument::output($html, 'a5', 'portrait', ['left' => $head['name'].' · '.$title.' '.$number, 'generated' => '']);
        }
        $tallest = 1400.0; // far past any receipt
        $height = RentalDocument::heightOf($html, self::TILL_WIDTH, $tallest);

        return RentalDocument::output($html, [0, 0, self::TILL_WIDTH, $height > 0 ? min($tallest, max(200.0, $height + 24)) : 623.62]);
    }

    /** "Label        value" on one line, or the label then the value right-aligned below it. @return list<string> */
    private static function pair(array $line, int $width): array
    {
        [$label, $value] = [self::ascii((string) $line[0]), self::ascii((string) $line[1])];
        if (strlen($label) + strlen($value) + 1 <= $width) {
            return [$label.str_repeat(' ', $width - strlen($label) - strlen($value)).$value];
        }
        $out = self::wrap($label.':', $width);
        foreach (self::wrap($value, $width) as $chunk) {
            $out[] = str_pad($chunk, $width, ' ', STR_PAD_LEFT);
        }

        return $out;
    }

    /** @return list<string> */
    private static function wrap(string $text, int $width): array
    {
        $text = self::ascii($text);

        return $text === '' ? [] : explode("\n", wordwrap($text, $width, "\n", true));
    }

    /** ESC/POS printers print ASCII: "1 Mar – 31 Mar" → "1 Mar - 31 Mar". */
    private static function ascii(string $s): string
    {
        $s = str_replace(["\u{2013}", "\u{2014}", "\u{2019}", "\u{2192}", "\u{00A0}"], ['-', '-', "'", '->', ' '], $s);
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);

        return trim((string) preg_replace('/[^\x20-\x7E]/', '', $t === false ? $s : $t));
    }
}
