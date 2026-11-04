<?php

namespace App\Services\Rentals\Reports;

use App\Exceptions\BusinessRuleException;
use App\Services\Reports\XlsxWriter;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;

/**
 * An Estate Ledger report (RentalReports::run) as a file: CSV, XLSX or PDF. Each file carries the account's name, the
 * report's title and period, the day it was made, the table, its totals and its sections; nothing else (no product
 * or platform name, KR-05).
 *
 * Money stays exact: minor units become plain major-unit numbers through Minor::toMajor (never through a float),
 * without thousands separators in CSV, as numbers in XLSX, and grouped for people in the PDF. CSV starts with a UTF-8
 * BOM (so spreadsheet programs read the names right) and a text cell that would start a formula (=, +, -, @) is
 * written with an apostrophe in front.
 */
final class RentalExport
{
    public const TYPES = ['csv' => 'text/csv; charset=utf-8', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pdf' => 'application/pdf'];

    /** Labels of the totals that are not under a column of the table. */
    private const TOTAL_LABELS = [
        'opening_minor' => 'Opening balance', 'closing_minor' => 'Closing balance', 'collected_minor' => 'Rent and fees collected', 'expenses_minor' => 'Expenses',
        'payouts_minor' => 'Paid out', 'deposits_minor' => 'Deposits', 'other_minor' => 'Other', 'income_minor' => 'Income', 'net_minor' => 'Net',
        'drawings_minor' => 'Owner drawings', 'landlords_minor' => 'Charged to landlords', 'agency_minor' => 'The agency\'s own',
        'held_by_agency_minor' => 'Held by the agency', 'held_by_landlords_minor' => 'Held by landlords', 'units' => 'Units', 'occupied' => 'Occupied', 'tenants' => 'Tenants',
        'vacant' => 'Vacant', 'leases' => 'Leases', 'receipts' => 'Receipts', 'requests' => 'Requests', 'still_open' => 'Still open',
    ];

    /** The longest list a one-page report prints in full. */
    private const ONE_PAGE_ROWS = 20;

    /** @param array<string, mixed> $report */
    public static function csv(array $report, int $companyId): string
    {
        $h = fopen('php://temp', 'r+');
        foreach (self::table($report, $companyId, false) as $row) {
            fputcsv($h, $row);
        }
        rewind($h);
        $csv = (string) stream_get_contents($h);
        fclose($h);

        return "\xEF\xBB\xBF".$csv;
    }

    /** @param array<string, mixed> $report */
    public static function xlsx(array $report, int $companyId): string
    {
        return XlsxWriter::build((string) $report['title'], self::table($report, $companyId, true));
    }

    /** @param array<string, mixed> $report */
    public static function pdf(array $report, int $companyId): string
    {
        $wide = count($report['columns']);
        foreach ($report['sections'] ?? [] as $s) {
            $wide = max($wide, count($s['columns']));
        }

        $report = self::onePage($report);

        return (string) app('dompdf.wrapper')->loadHTML(self::html($report, $companyId))->setPaper('a4', $wide > 7 ? 'landscape' : 'portrait')->output();
    }

    /**
     * The file, its content type and a file name.
     *
     * @param  array<string, mixed>  $report
     * @return array{0: string, 1: string, 2: string}
     */
    public static function render(array $report, int $companyId, string $format): array
    {
        return match ($format) {
            'csv' => [self::csv($report, $companyId), self::TYPES['csv'], self::filename($report, 'csv')],
            'xlsx' => [self::xlsx($report, $companyId), self::TYPES['xlsx'], self::filename($report, 'xlsx')],
            'pdf' => [self::pdf($report, $companyId), self::TYPES['pdf'], self::filename($report, 'pdf')],
            default => throw BusinessRuleException::make('unknown_format', 'Download a report as csv, xlsx or pdf.'),
        };
    }

    /** "rent-roll-2026-10-24.csv", "collections-2026-10-01-2026-10-24.pdf". @param array<string, mixed> $report */
    public static function filename(array $report, string $ext): string
    {
        $dates = ! empty($report['from']) && ! empty($report['to']) ? $report['from'].'-'.$report['to'] : substr((string) ($report['generated_at'] ?? now()->toIso8601String()), 0, 10);

        return str_replace('_', '-', (string) $report['key']).'-'.$dates.'.'.$ext;
    }

    /** Money for people: "1,250,000", "-25,000.50". */
    public static function money(int $minor, int $decimals): string
    {
        $major = Minor::toMajor($minor, $decimals);
        $negative = str_starts_with($major, '-');
        [$int, $dec] = array_pad(explode('.', ltrim($major, '-')), 2, null);

        return ($negative ? '-' : '').number_format((int) $int).($dec !== null ? '.'.$dec : '');
    }

    /**
     * A one-page report (the monthly building report) keeps to one sheet of paper: a list too long for it prints as its
     * summary, with the same total. The CSV and XLSX keep every row.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function onePage(array $report): array
    {
        if (empty($report['one_page'])) {
            return $report;
        }
        foreach ($report['sections'] as $i => $s) {
            if (isset($s['summary']) && count($s['rows']) > self::ONE_PAGE_ROWS) {
                $report['sections'][$i] = $s['summary'] + $s;
            }
        }

        return $report;
    }

    /** A text cell that a spreadsheet would read as a formula, made safe (OWASP CSV injection). */
    public static function safe(string $text): string
    {
        return preg_match('/^\s*[=+\-@]|^[\t\r\n]/', $text) && ! is_numeric(trim($text)) ? "'".$text : $text;
    }

    // ── Rows of cells (CSV and XLSX) ─────────────────────────────────────

    /**
     * Header lines, the table with its totals line, the other totals, then each section.
     *
     * @param  array<string, mixed>  $report
     * @return list<list<string|int|float>>
     */
    private static function table(array $report, int $companyId, bool $typed): array
    {
        $d = self::decimals($report, $companyId);
        $text = fn (string $s) => $typed ? $s : self::safe($s);
        $out = [[$text((string) ($report['account']['name'] ?? ''))], [$text((string) $report['title'])], [$text((string) ($report['subtitle'] ?? ''))],
            ['Generated '.self::generated($report, $companyId)], []];
        $block = function (array $cols, array $rows, array $totals, string $label) use (&$out, $d, $typed, $text) {
            $out[] = array_map(fn ($c) => $text((string) $c['label']), $cols);
            foreach ($rows as $r) {
                $out[] = array_map(fn ($c) => self::value($r[$c['key']] ?? null, $c['type'], $d, $typed), $cols);
            }
            $line = [];
            $any = false;
            foreach (array_values($cols) as $i => $c) {
                if (array_key_exists($c['key'], $totals) && $c['type'] !== 'text') {
                    $line[] = self::value($totals[$c['key']], $c['type'], $d, $typed);
                    $any = true;
                } else {
                    $line[] = $i === 0 ? $label : '';
                }
            }
            if ($any && $rows !== []) {
                $line[0] = $line[0] === '' ? $label : $line[0];
                $out[] = $line;
            }
        };
        $block($report['columns'], $report['rows'], (array) ($report['totals'] ?? []), (string) ($report['total_label'] ?? 'Total'));
        $extra = self::extraTotals($report);
        if ($extra !== []) {
            $out[] = [];
            foreach ($extra as [$label, $value, $type]) {
                $out[] = [$text($label), self::value($value, $type, $d, $typed)];
            }
        }
        foreach ($report['sections'] ?? [] as $s) {
            $out[] = [];
            $out[] = [$text((string) $s['title'])];
            $block($s['columns'], $s['rows'], (array) ($s['totals'] ?? []), (string) ($s['total_label'] ?? 'Total'));
        }

        return $out;
    }

    /** A cell's value: numbers as numbers in XLSX, exact strings in CSV. */
    private static function value(mixed $v, string $type, int $decimals, bool $typed): string|int|float
    {
        if ($v === null || $v === '') {
            return '';
        }

        return match ($type) {
            'money' => $typed ? ($decimals === 0 ? (int) $v : (float) Minor::toMajor((int) $v, $decimals)) : Minor::toMajor((int) $v, $decimals),
            'int' => (int) $v,
            'pct' => $typed ? round(((int) $v) / 100, 2) : Minor::toMajor((int) $v, 2),
            'date' => (string) $v,
            default => $typed ? (string) $v : self::safe((string) $v),
        };
    }

    /**
     * Totals that are not under a column: [label, value, type].
     *
     * @param  array<string, mixed>  $report
     * @return list<array{0: string, 1: mixed, 2: string}>
     */
    public static function extraTotals(array $report): array
    {
        $cols = array_column($report['columns'], 'type', 'key');
        $out = [];
        foreach ((array) ($report['totals'] ?? []) as $k => $v) {
            if (isset($cols[$k]) && $cols[$k] !== 'text') {
                continue;
            }
            $type = str_ends_with($k, '_minor') ? 'money' : (str_ends_with($k, '_bp') ? 'pct' : 'int');
            $out[] = [self::TOTAL_LABELS[$k] ?? ucfirst(str_replace('_', ' ', preg_replace('/_(minor|bp)$/', '', $k))), $v, $type];
        }

        return $out;
    }

    // ── PDF ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $report */
    private static function html(array $report, int $companyId): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $d = self::decimals($report, $companyId);
        $right = fn (string $type) => in_array($type, ['money', 'int', 'pct'], true) ? ' class="r"' : '';
        $table = function (array $cols, array $rows, array $totals, string $label = 'Total') use ($e, $d, $right): string {
            $h = '<table class="t"><thead><tr>';
            foreach ($cols as $c) {
                $h .= '<th'.$right($c['type']).'>'.$e($c['label']).'</th>';
            }
            $h .= '</tr></thead><tbody>';
            if ($rows === []) {
                $h .= '<tr><td colspan="'.count($cols).'" class="empty">Nothing to show.</td></tr>';
            }
            foreach ($rows as $r) {
                $h .= '<tr>';
                foreach ($cols as $c) {
                    $h .= '<td'.$right($c['type']).'>'.$e(self::display($r[$c['key']] ?? null, $c['type'], $d)).'</td>';
                }
                $h .= '</tr>';
            }
            $line = '';
            $any = false;
            foreach (array_values($cols) as $i => $c) {
                $has = array_key_exists($c['key'], $totals) && $c['type'] !== 'text';
                $any = $any || $has;
                $line .= '<td'.$right($c['type']).'>'.($has ? $e(self::display($totals[$c['key']], $c['type'], $d)) : ($i === 0 ? $e($label) : '')).'</td>';
            }
            if ($any && $rows !== []) {
                $h .= '<tr class="total">'.$line.'</tr>';
            }

            return $h.'</tbody></table>';
        };
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.$e($report['title']).'</title><style>'
            .'@page{margin:26px 28px 30px}body{font-family:"DejaVu Sans",sans-serif;font-size:8.5px;color:#1e2b33;background:#fff;margin:0}'
            .'.head{padding-bottom:7px;border-bottom:0.6px solid #1e2b33;margin-bottom:10px}.account{font-size:13px;font-weight:bold;margin:0 0 3px}'
            .'.title{font-size:11px;font-weight:bold;margin:0}.meta{color:#55606a;margin-top:3px}'
            .'table.t{width:100%;border-collapse:collapse}table.t th{text-align:left;font-weight:bold;padding:3px 4px;border-bottom:0.6px solid #1e2b33}'
            .'table.t td{padding:3px 4px;border-bottom:0.4px solid #d8d3ca;vertical-align:top}.r{text-align:right;white-space:nowrap}table.t th.r{text-align:right}'
            .'table.t tr.total td{font-weight:bold;border-top:0.6px solid #1e2b33;border-bottom:0}h2{font-size:9.5px;margin:14px 0 4px}'
            .'table.s{border-collapse:collapse;margin-top:10px}table.s td{padding:2px 14px 2px 0;border-bottom:0.4px solid #d8d3ca}.empty{color:#55606a}'
            .'</style></head><body>';
        $html .= '<div class="head"><p class="account">'.$e($report['account']['name'] ?? '').'</p><p class="title">'.$e($report['title']).'</p>'
            .'<div class="meta">'.$e($report['subtitle'] ?? '').'</div><div class="meta">Currency '.$e($report['currency'] ?? '').' · generated '.$e(self::generated($report, $companyId)).'</div></div>';
        $html .= $table($report['columns'], $report['rows'], (array) ($report['totals'] ?? []), (string) ($report['total_label'] ?? 'Total'));
        $extra = self::extraTotals($report);
        if ($extra !== []) {
            $html .= '<table class="s">';
            foreach ($extra as [$label, $value, $type]) {
                $html .= '<tr><td>'.$e($label).'</td><td class="r">'.$e(self::display($value, $type, $d)).'</td></tr>';
            }
            $html .= '</table>';
        }
        foreach ($report['sections'] ?? [] as $s) {
            $html .= '<h2>'.$e($s['title']).'</h2>'.$table($s['columns'], $s['rows'], (array) ($s['totals'] ?? []), (string) ($s['total_label'] ?? 'Total'));
        }

        return $html.'</body></html>';
    }

    /** A cell as people read it in the PDF. */
    public static function display(mixed $v, string $type, int $decimals): string
    {
        if ($v === null || $v === '') {
            return '';
        }

        return match ($type) {
            'money' => self::money((int) $v, $decimals),
            'int' => number_format((int) $v),
            'pct' => rtrim(rtrim(Minor::toMajor((int) $v, 2), '0'), '.').' %',
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? Carbon::parse((string) $v)->format('j M Y') : (string) $v,
            default => (string) $v,
        };
    }

    /** @param array<string, mixed> $report */
    private static function decimals(array $report, int $companyId): int
    {
        return isset($report['decimals']) ? (int) $report['decimals'] : Minor::decimals($companyId);
    }

    /** When the report was made, in the account's own time. @param array<string, mixed> $report */
    private static function generated(array $report, int $companyId): string
    {
        $at = Carbon::parse((string) ($report['generated_at'] ?? now()->toIso8601String()));

        return $at->setTimezone(LocalDate::timezone($companyId))->format('j M Y H:i');
    }
}
