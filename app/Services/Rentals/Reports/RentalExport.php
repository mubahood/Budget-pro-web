<?php

namespace App\Services\Rentals\Reports;

use App\Exceptions\BusinessRuleException;
use App\Services\Rentals\RentalsModule;
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
 * without thousands separators in CSV, as numbers in XLSX, and grouped for people in the PDF.
 *
 * CSV: the column headings are row 1, so a pivot or an import can read it; what the file is comes last. It starts with
 * a UTF-8 BOM (so spreadsheet programs read the names right) and a text cell that would start a formula (=, +, -, @) is
 * written with an apostrophe in front.
 *
 * XLSX: one sheet for the report and one for each of its sections, with column widths, a bold heading that stays in
 * view, a filter, real numbers and real dates (XlsxWriter::workbook).
 *
 * PDF: one document layout (RentalDocument, rentals/document.blade.php): figures in the mono face, a dash for zero
 * except in totals, brackets for negatives, one date format.
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
        'in_use' => 'Used by the landlord or a caretaker', 'direct_minor' => 'Tenants paid the landlord directly',
    ];

    /** The longest list a one-page report prints in full. */
    private const ONE_PAGE_ROWS = 20;

    /** A table this short stays whole on one page. */
    private const WHOLE_ROWS = 14;

    /** Columns that break into pieces if wrapped: a code, a phone, a one-word state. */
    private const NOWRAP = ['phone', 'unit', 'lease', 'number', 'code', 'method', 'status', 'state', 'held_by', 'priority', 'reference', 'kind', 'type'];

    /**
     * What a report does on paper beyond its table. hide: columns left off the page (the other files keep them);
     * group: rows under a heading per value, with a subtotal of `sum` (subtotal_words: its words); subtotal_by: a subtotal
     * of `sum` after each value; total_label: the last line's words; show_head: false when the rows say what they are;
     * portrait: stays upright however many columns. A report may carry the same keys itself in its `paper`, so a new report
     * needs no line here.
     */
    private const PAPER = [
        'rent_roll' => ['hide' => ['property', 'cycle_months', 'asking_minor'], 'group' => 'property', 'sum' => ['rent_minor', 'deposit_held_minor', 'owed_minor', 'overdue_minor']],
        'arrears_aging' => ['hide' => ['status', 'lease']],
        'lease_list' => ['hide' => ['cycle_months']],
        'cash_book' => ['subtotal_by' => 'date', 'sum' => ['in_minor', 'out_minor']],
        'income_statement' => ['hide' => ['section'], 'group' => 'section', 'sum' => ['amount_minor'], 'total_label' => 'Net', 'show_head' => false, 'subtotal_words' => 'Total %s'],
        'landlord_statement' => ['hide' => ['type', 'property']],
    ];

    /** @param array<string, mixed> $report */
    public static function csv(array $report, int $companyId): string
    {
        $h = fopen('php://temp', 'r+');
        foreach (self::csvRows($report, $companyId) as $row) {
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
        $d = self::decimals($report, $companyId);
        $note = trim(implode(' · ', array_filter([(string) ($report['subtitle'] ?? ''), ! empty($report['currency']) ? 'Amounts in '.$report['currency'] : '', 'Generated '.self::generated($report, $companyId)])));
        $account = (string) ($report['account']['name'] ?? '');
        $sheets = [self::sheet((string) $report['title'], trim($account.' · '.$report['title'], ' ·'), $note, $report['columns'], $report['rows'], (array) ($report['totals'] ?? []),
            (string) ($report['total_label'] ?? 'Total'), $d, self::extraTotals($report))];
        foreach ($report['sections'] ?? [] as $s) {
            $sheets[] = self::sheet((string) $s['title'], trim($account.' · '.$report['title'].' · '.$s['title'], ' ·'), $note, $s['columns'], $s['rows'], (array) ($s['totals'] ?? []),
                (string) ($s['total_label'] ?? 'Total'), $d, []);
        }

        return XlsxWriter::workbook($sheets);
    }

    /** @param array<string, mixed> $report */
    public static function pdf(array $report, int $companyId): string
    {
        $paper = self::paperOf($report);
        $hide = (array) ($paper['hide'] ?? []);
        $wide = count(array_filter($report['columns'], fn ($c) => ! in_array($c['key'], $hide, true)));
        foreach ($report['sections'] ?? [] as $s) {
            $wide = max($wide, count($s['columns']));
        }
        $orientation = $wide > 7 && empty($paper['portrait']) ? 'landscape' : 'portrait';
        $foot = ['left' => implode(' · ', array_filter([(string) ($report['account']['name'] ?? ''), (string) $report['title'], self::period($report, $companyId)])), 'generated' => self::generated($report, $companyId)];
        $issued = (array) ($report['figures']['statement'] ?? []);
        if (! empty($issued['number'])) {
            // An issued copy prints the same each time: its number and proof come first, so a long name is what gets cut. No "generated" day.
            $foot = ['left' => implode(' · ', array_filter([(string) $issued['number'], 'Proof '.$issued['proof_short'], (string) ($issued['letterhead']['name'] ?? $report['account']['name'] ?? ''), self::period($report, $companyId)])), 'generated' => ''];
        }
        if (empty($report['one_page'])) {
            return RentalDocument::output(self::html($report, $companyId), 'a4', $orientation, $foot);
        }
        // One sheet of paper, always: each try folds a little more of what is long.
        $bytes = '';
        foreach ([0, 1, 2, 3] as $fold) {
            $pages = 1;
            $bytes = RentalDocument::output(self::html($report, $companyId, $fold), 'a4', $orientation, $foot, $pages);
            if ($pages <= 1) {
                break;
            }
        }

        return $bytes;
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

    /** A Ugandan phone as people read it, "0700 000474"; anything else as it was. It is also never read as a number by a spreadsheet. */
    public static function phone(?string $phone): string
    {
        $p = trim((string) $phone);
        $digits = (string) preg_replace('/[\s\-()]/', '', $p);
        if (preg_match('/^(?:\+?256|0)(7\d{2}|[2-9]\d{2})(\d{6})$/', $digits, $m)) {
            return '0'.$m[1].' '.$m[2];
        }

        return $p;
    }

    /** What a report does on paper: its own `paper`, else its line in PAPER. @param array<string, mixed> $report @return array<string, mixed> */
    private static function paperOf(array $report): array
    {
        return (array) ($report['paper'] ?? self::PAPER[$report['key'] ?? ''] ?? []);
    }

    /** Columns left off the paper for this report (the other files keep them). @return list<string> */
    public static function paperHidden(string $key): array
    {
        return array_values((array) (self::PAPER[$key]['hide'] ?? []));
    }

    /** A text cell that a spreadsheet would read as a formula, made safe (OWASP CSV injection). */
    public static function safe(string $text): string
    {
        return preg_match('/^\s*[=+\-@]|^[\t\r\n]/', $text) && ! is_numeric(trim($text)) ? "'".$text : $text;
    }

    // ── Rows of cells (CSV and XLSX) ─────────────────────────────────────

    /**
     * The table with its headings on row 1 and its totals line, the other totals, each section, and last what the file is.
     *
     * @param  array<string, mixed>  $report
     * @return list<list<string|int|float>>
     */
    private static function csvRows(array $report, int $companyId): array
    {
        $d = self::decimals($report, $companyId);
        $out = [];
        $block = function (array $cols, array $rows, array $totals, string $label) use (&$out, $d) {
            $out[] = array_map(fn ($c) => self::safe((string) $c['label']), $cols);
            foreach ($rows as $r) {
                $out[] = array_map(fn ($c) => self::csvValue($r[$c['key']] ?? null, $c, $d), $cols);
            }
            $line = [];
            $any = false;
            foreach (array_values($cols) as $i => $c) {
                if (array_key_exists($c['key'], $totals) && $c['type'] !== 'text') {
                    $line[] = self::csvValue($totals[$c['key']], $c, $d);
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
                $out[] = [self::safe($label), self::csvValue($value, ['key' => '', 'type' => $type], $d)];
            }
        }
        foreach ($report['sections'] ?? [] as $s) {
            $out[] = [];
            $out[] = [self::safe((string) $s['title'])];
            $block($s['columns'], $s['rows'], (array) ($s['totals'] ?? []), (string) ($s['total_label'] ?? 'Total'));
        }
        $out[] = [];
        foreach ([(string) ($report['account']['name'] ?? ''), (string) $report['title'], (string) ($report['subtitle'] ?? '')] as $line) {
            $out[] = [self::safe($line)];
        }
        $out[] = ['Generated '.self::generated($report, $companyId)];

        return $out;
    }

    /** A CSV cell: exact strings, numbers without separators, a phone that stays text. @param array{key: string, type: string} $c */
    private static function csvValue(mixed $v, array $c, int $decimals): string|int
    {
        if ($v === null || $v === '') {
            return '';
        }

        return match ($c['type']) {
            'money' => Minor::toMajor((int) $v, $decimals),
            'int' => (int) $v,
            'pct' => Minor::toMajor((int) $v, 2),
            'date' => (string) $v,
            default => self::safe($c['key'] === 'phone' ? self::phone((string) $v) : (string) $v),
        };
    }

    /**
     * One sheet: two title lines, the headings, the rows, the totals line in bold, the other totals below.
     *
     * @param  list<array{key: string, label: string, type: string}>  $cols
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $totals
     * @param  list<array{0: string, 1: mixed, 2: string}>  $below
     * @return array{name: string, rows: list<list<mixed>>, widths: list<float>, heading: int, last: int, columns: int}
     */
    private static function sheet(string $name, string $title, string $note, array $cols, array $rows, array $totals, string $label, int $d, array $below): array
    {
        $out = [[['v' => $title, 's' => 'title']], [['v' => $note, 's' => 'note']], []];
        $out[] = array_map(fn ($c) => ['v' => (string) $c['label'], 's' => $c['type'] === 'text' || $c['type'] === 'date' ? 'head' : 'head_r'], $cols);
        $widths = array_map(fn ($c) => mb_strlen((string) $c['label']) + 4.0, $cols);
        foreach ($rows as $r) {
            $line = [];
            foreach (array_values($cols) as $i => $c) {
                $line[] = self::xlsxCell($r[$c['key']] ?? null, $c, $d, false);
                $widths[$i] = max($widths[$i], self::shownWidth($r[$c['key']] ?? null, $c, $d));
            }
            $out[] = $line;
        }
        $last = count($out);
        $has = false;
        $line = [];
        foreach (array_values($cols) as $i => $c) {
            if (array_key_exists($c['key'], $totals) && $c['type'] !== 'text') {
                $has = true;
                $line[] = self::xlsxCell($totals[$c['key']], $c, $d, true);
                $widths[$i] = max($widths[$i], self::shownWidth($totals[$c['key']], $c, $d));
            } else {
                $line[] = ['v' => $i === 0 ? $label : null, 's' => 'total'];
                if ($i === 0) {
                    $widths[0] = max($widths[0], min(30.0, mb_strlen($label) + 2.0));
                }
            }
        }
        if ($has && $rows !== []) {
            $out[] = $line;
        }
        if ($below !== []) {
            $out[] = [];
            foreach ($below as [$l, $v, $type]) {
                $out[] = [['v' => $l, 's' => 'bold'], self::xlsxCell($v, ['key' => '', 'type' => $type], $d, false)];
                $widths[0] = max($widths[0], min(30.0, mb_strlen($l) + 2.0));
            }
        }

        return ['name' => $name, 'rows' => $out, 'widths' => array_map(fn ($w) => max(8.0, min(46.0, (float) $w)), $widths), 'heading' => 4, 'last' => $last, 'columns' => count($cols)];
    }

    /** A sheet's cell: numbers as numbers, a percentage as a percentage, a date as a date. @param array{key: string, type: string} $c @return array{v: mixed, s: string} */
    private static function xlsxCell(mixed $v, array $c, int $decimals, bool $total): array
    {
        $style = fn (string $s) => $total ? 'total_'.$s : $s;
        if ($v === null || $v === '') {
            return ['v' => null, 's' => $total ? 'total' : 'default'];
        }

        return match ($c['type']) {
            'money' => ['v' => $decimals === 0 ? (int) $v : (float) Minor::toMajor((int) $v, $decimals), 's' => $style($decimals === 0 ? 'money0' : 'money2')],
            'int' => ['v' => (int) $v, 's' => $style('int')],
            'pct' => ['v' => round(((int) $v) / 10000, 4), 's' => $style('pct')],
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v)
                ? ['v' => (int) Carbon::parse('1899-12-30', 'UTC')->diffInDays(Carbon::parse((string) $v, 'UTC')), 's' => $total ? 'total' : 'date']
                : ['v' => (string) $v, 's' => $total ? 'total' : 'default'],
            default => ['v' => $c['key'] === 'phone' ? self::phone((string) $v) : (string) $v, 's' => $total ? 'total' : 'default'],
        };
    }

    /** About how wide a cell draws, in characters. @param array{key: string, type: string} $c */
    private static function shownWidth(mixed $v, array $c, int $decimals): float
    {
        if ($v === null || $v === '') {
            return 0.0;
        }

        return match ($c['type']) {
            'money' => mb_strlen(self::money((int) $v, $decimals)) + 3.0,
            'int' => mb_strlen(number_format((int) $v)) + 3.0,
            'pct' => 8.0,
            'date' => 13.0,
            default => mb_strlen($c['key'] === 'phone' ? self::phone((string) $v) : (string) $v) + 2.0,
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
            if (in_array($k, ['in_use', 'direct_minor'], true) && (int) $v === 0) {
                continue; // said only when there is one
            }
            $type = str_ends_with($k, '_minor') ? 'money' : (str_ends_with($k, '_bp') ? 'pct' : 'int');
            $out[] = [self::TOTAL_LABELS[$k] ?? ucfirst(str_replace('_', ' ', preg_replace('/_(minor|bp)$/', '', $k))), $v, $type];
        }

        return $out;
    }

    // ── PDF ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $report
     * @param  int  $fold  how much a one-page report folds to fit (0: only what is plainly too long)
     */
    private static function html(array $report, int $companyId, int $fold = 0): string
    {
        $d = self::decimals($report, $companyId);
        $key = (string) ($report['key'] ?? '');
        $paper = self::paperOf($report);
        $doc = ['title' => (string) $report['title'], 'head' => RentalDocument::letterhead($companyId),
            'sub' => array_filter([(string) ($report['subtitle'] ?? ''), ! empty($report['currency']) ? 'Amounts in '.$report['currency'] : ''])];

        return match ($key) {
            'monthly_building' => self::monthlyHtml($report, $doc, $d, $fold),
            'landlord_statement' => self::statementHtml($report, $doc, $d, $companyId),
            'tenant_statement' => self::tenantHtml($report, $doc, $d, $companyId),
            default => view('rentals.report', ['doc' => $doc, 'summary' => self::summary($report, $d),
                'main' => self::paperTable($report['columns'], $report['rows'], (array) ($report['totals'] ?? []), (string) ($paper['total_label'] ?? $report['total_label'] ?? 'Total'), $d, $paper),
                'sections' => self::paperSections($report, $d)])->render(),
        };
    }

    /** @param array<string, mixed> $report @return list<array<string, mixed>> */
    private static function paperSections(array $report, int $d, array $skip = []): array
    {
        $out = [];
        foreach ($report['sections'] ?? [] as $i => $s) {
            if ($s['rows'] !== [] && ! in_array($i, $skip, true)) {
                $out[] = self::paperTable($s['columns'], $s['rows'], (array) ($s['totals'] ?? []), (string) ($s['total_label'] ?? 'Total'), $d,
                    ['title' => (string) $s['title'], 'show_head' => ! (count($s['columns']) === 2 && $s['columns'][0]['label'] === $s['title'])]);
            }
        }

        return $out;
    }

    /**
     * The monthly building report on one page: the three big figures, occupancy and arrears in two lines, income, the
     * expenses (their categories when the list is long), the named balance and the tenants' money apart.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $doc
     */
    private static function monthlyHtml(array $report, array $doc, int $d, int $fold): string
    {
        $currency = (string) ($report['currency'] ?? '');
        $f = (array) ($report['figures'] ?? []);
        $sections = $report['sections'];
        $big = fn (int $minor) => trim($currency.' '.self::paperMoney($minor, $d, true));
        $main = self::foldLines($report['rows'], $fold >= 3 ? 6 : null, 'line', 'Other income');
        $expenses = $sections[0];
        if ($fold > 0 || count($expenses['rows']) > self::ONE_PAGE_ROWS) {
            $expenses = ($expenses['summary'] ?? []) + $expenses;
            $expenses['rows'] = self::foldLines($expenses['rows'], $fold >= 2 ? 6 : 14, 'category', 'Other categories');
        }
        $tables = [];
        if ($expenses['rows'] !== []) {
            $tables[] = self::paperTable($expenses['columns'], $expenses['rows'], (array) ($expenses['totals'] ?? []), (string) ($expenses['total_label'] ?? 'Total'), $d, ['title' => (string) $expenses['title']]);
        }
        foreach ([1, 2] as $i) {
            $s = $sections[$i];
            $tables[] = self::paperTable($s['columns'], $s['rows'], (array) ($s['totals'] ?? []), (string) ($s['total_label'] ?? 'Total'), $d, ['title' => (string) $s['title'], 'show_head' => false]);
        }
        $asAt = (string) ($f['as_at'] ?? '');
        $tenants = (int) ($f['overdue_tenants'] ?? 0);
        $overdue = (int) ($f['overdue_minor'] ?? 0);

        return view('rentals.monthly', ['doc' => $doc, 'sections' => $tables,
            'main' => self::paperTable($report['columns'], $main, (array) ($report['totals'] ?? []), (string) ($report['total_label'] ?? 'Total'), $d, ['title' => 'Income', 'show_head' => false]),
            'big' => ['collected' => $big((int) ($f['income_minor'] ?? 0)),
                'paid_out' => array_key_exists('paid_out_minor', $f) ? $big((int) $f['paid_out_minor']) : null, 'paid_out_label' => (string) ($f['paid_out_label'] ?? 'Paid out'),
                'balance' => $big((int) ($f['final_balance_minor'] ?? 0)), 'balance_label' => (string) ($sections[1]['total_label'] ?? 'Balance'),
                'occupancy' => (int) ($f['occupied'] ?? 0).' of '.(int) ($f['units'] ?? 0).' units occupied on '.self::paperDate((string) ($f['occupied_on'] ?? '')).'.',
                'arrears' => $overdue > 0 ? $big($overdue).' overdue on '.self::paperDate($asAt).', '.$tenants.' '.($tenants === 1 ? 'tenant' : 'tenants').'.' : 'Nothing overdue on '.self::paperDate($asAt).'.'],
        ])->render();
    }

    /**
     * The longest lines of a list, the rest added up in one: a long list keeps its total and fits the page. A line of a
     * section keeps its order; "count" columns are added up too.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function foldLines(array $rows, ?int $keep, string $labelKey, string $otherLabel): array
    {
        if ($keep === null || count($rows) <= $keep) {
            return $rows;
        }
        usort($rows, fn ($a, $b) => abs((int) $b['amount_minor']) <=> abs((int) $a['amount_minor']));
        $other = [$labelKey => $otherLabel, 'amount_minor' => 0];
        foreach (array_slice($rows, $keep - 1) as $r) {
            $other['amount_minor'] += (int) $r['amount_minor'];
            if (isset($r['count'])) {
                $other['count'] = (int) ($other['count'] ?? 0) + (int) $r['count'];
            }
        }

        return [...array_slice($rows, 0, $keep - 1), $other];
    }

    /** @param array<string, mixed> $report @param array<string, mixed> $doc */
    private static function statementHtml(array $report, array $doc, int $d, int $companyId): string
    {
        $s = (array) ($report['figures']['statement'] ?? []);
        $doc['head'] = self::issuedHead($s, $companyId) ?? $doc['head'];
        if (! empty($s['commission_rule'])) {
            $doc['sub'][] = (string) $s['commission_rule'];
        }

        return view('rentals.landlord-statement', ['doc' => $doc, 'st' => self::statementData($report, $d)])->render();
    }

    /**
     * A landlord statement's page body, for one statement or many on one sheet (LC-08): the balance and how it came about; unit by unit; each line; expenses; paid to you; held
     * for you, with its sentence; corrections. A section with nothing is dropped.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function statementData(array $report, int $d): array
    {
        $t = (array) ($report['totals'] ?? []);
        $s = (array) ($report['figures']['statement'] ?? []);
        $currency = (string) ($s['currency'] ?? $report['currency'] ?? '');
        $owner = ! collect($report['columns'])->contains(fn ($c) => $c['key'] === 'commission_minor') && ! array_key_exists('commission_minor', $t);
        $v = fn (string $k) => (int) ($t[$k] ?? 0);
        $closing = $v('closing_minor');
        $closingLabel = $closing < 0 ? ($owner ? 'Short at the end' : 'The landlord owes the agency') : ($owner ? 'Money in hand at the end' : 'Owed to the landlord at the end');
        $money = [['Balance at the start', self::paperMoney($v('opening_minor'), $d, true), false], ['Rent and fees collected', self::paperMoney($v('collected_minor'), $d), false]];
        foreach ([['commission_minor', 'Commission', -1], ['deposits_minor', 'Deposits passed to the landlord', 1], ['expenses_minor', 'Expenses', -1], ['payouts_minor', $owner ? 'Owner drawings' : 'Paid to the landlord', -1], ['other_minor', 'Other', 1]] as [$k, $label, $sign]) {
            if ($v($k) !== 0) {
                $money[] = [$label, self::paperMoney($sign * $v($k), $d), false];
            }
        }
        if ($v('direct_minor') !== 0) {
            $money[] = ['Tenants paid you directly (with you already)', self::paperMoney($v('direct_minor'), $d), false];
        }
        $money[] = [$closingLabel, self::paperMoney(abs($closing), $d, true), true];

        $sections = collect($report['sections'] ?? [])->keyBy(fn ($x) => (string) ($x['key'] ?? ''))->all();
        $table = function (array $x, array $o = []) use ($d) {
            return self::paperTable($x['columns'], $x['rows'], (array) ($x['totals'] ?? []), (string) ($x['total_label'] ?? 'Total'), $d, $o + ['title' => (string) $x['title']]);
        };
        // The unit table and each line go under a heading per property when there is more than one.
        $units = null;
        if (! empty($sections['units']['rows'])) {
            $many = collect($sections['units']['rows'])->pluck('property')->unique()->count() > 1;
            $units = $table($sections['units'], $many ? ['hide' => ['property'], 'group' => 'property', 'sum' => ['paid_minor', 'owed_minor']] : ['hide' => ['property']]);
        }
        $lines = array_slice($report['rows'], 1);
        $many = collect($lines)->pluck('property')->filter()->unique()->count() > 1;
        if ($many) {
            // A running balance means nothing across headings, and the opening is in the summary above.
            $rows = array_map(fn ($r) => ['property' => $r['property'] ?: 'Not for one property'] + $r, $lines);
            $main = self::paperTable($report['columns'], $rows, array_intersect_key($t, array_flip(['gross_minor', 'commission_minor', 'amount_minor'])), 'Total', $d,
                ['hide' => ['type', 'property', 'balance_minor'], 'group' => 'property', 'sum' => ['gross_minor', 'commission_minor', 'amount_minor'], 'title' => 'Each line']);
        } else {
            $rows = array_map(function ($r) {
                $r['unit'] = trim(trim((string) ($r['unit'] ?? '')).', '.trim((string) ($r['property'] ?? '')), ', ');

                return $r;
            }, $report['rows']);
            $main = self::paperTable($report['columns'], $rows, array_intersect_key($t, array_flip(['gross_minor', 'commission_minor', 'amount_minor'])), 'Total', $d, self::PAPER['landlord_statement'] + ['title' => 'Each line']);
        }
        $replaces = $s['replaces'] ?? null;

        return ['money' => $money, 'title' => (string) $report['title'], 'subtitle' => (string) ($report['subtitle'] ?? ''), 'stamp' => $s['stamp'] ?? null, 'number' => $s['number'] ?? null, 'issued' => $s['issued_at_words'] ?? null,
            'big' => ['label' => $closingLabel, 'value' => trim($currency.' '.self::paperMoney(abs($closing), $d, true)), 'units' => $v('units').' units · '.$v('occupied').' occupied · '.$v('vacant').' vacant'.($v('in_use') > 0 ? ' · '.$v('in_use').' used by you or a caretaker' : '')],
            'units' => $units, 'main' => $main,
            'expenses' => ! empty($sections['expenses']['rows']) ? $table($sections['expenses']) : null,
            'payouts' => ! empty($sections['payouts']['rows']) ? $table($sections['payouts']) : null,
            'held' => ! empty($sections['held']['rows']) ? $table($sections['held']) : null,
            'heldNote' => $owner ? 'This is your tenants\' money until it falls due, and it is not in the balance above.' : 'This is your tenants\' money until it falls due. It is not in your balance.',
            'corrections' => ! empty($sections['corrections']['rows']) ? $table($sections['corrections']) : null,
            'owes' => $units === null && ! empty($sections['arrears']['rows']) ? $table($sections['arrears']) : null,
            'replaces' => is_array($replaces) ? ['number' => (string) ($replaces['number'] ?? ''), 'rows' => array_map(fn ($x) => [(string) $x['what'], self::paperMoney((int) $x['before_minor'], $d, true), self::paperMoney((int) $x['after_minor'], $d, true)], (array) ($replaces['difference'] ?? []))] : null];
    }

    /**
     * A month's statements on one sheet (StatementPdf::renderMany): for the first part a cover with the by-landlord table for the office file, then each statement on its own page.
     *
     * @param  list<array<string, mixed>>  $reports  this part's statements
     * @param  array<int, \App\Models\RentalStatement>  $all  every statement of the month
     */
    public static function statementsPdf(array $reports, array $all, int $companyId, string $month, int $part): string
    {
        $d = Minor::decimals($companyId);
        $settings = RentalsModule::settings($companyId);
        $owner = $settings->isOwnerMode();
        $name = Carbon::parse($month.'-01')->format('F Y');
        $cover = [];
        foreach ($all as $s) {
            $cover[] = ['landlord' => (string) (((array) $s->body)['landlord']['name'] ?? ''), 'number' => (string) $s->number, 'opening_minor' => (int) $s->opening_minor, 'collected_minor' => (int) $s->collected_minor,
                'commission_minor' => (int) $s->commission_minor, 'expenses_minor' => (int) $s->expenses_minor, 'payouts_minor' => (int) $s->payouts_minor, 'closing_minor' => (int) $s->closing_minor];
        }
        $columns = [['key' => 'landlord', 'label' => $owner ? 'Account' : 'Landlord', 'type' => 'text'], ['key' => 'number', 'label' => 'Number', 'type' => 'text'], ['key' => 'opening_minor', 'label' => 'Opening', 'type' => 'money'],
            ['key' => 'collected_minor', 'label' => 'Collected', 'type' => 'money'], ['key' => 'commission_minor', 'label' => 'Commission', 'type' => 'money'], ['key' => 'expenses_minor', 'label' => 'Expenses', 'type' => 'money'],
            ['key' => 'payouts_minor', 'label' => $owner ? 'Drawings' : 'Paid out', 'type' => 'money'], ['key' => 'closing_minor', 'label' => 'Closing', 'type' => 'money']];
        if ($owner) {
            $columns = array_values(array_filter($columns, fn ($c) => $c['key'] !== 'commission_minor'));
        }
        $totals = [];
        foreach (['opening_minor', 'collected_minor', 'commission_minor', 'expenses_minor', 'payouts_minor', 'closing_minor'] as $k) {
            $totals[$k] = array_sum(array_column($cover, $k));
        }
        $company = (string) (\App\Models\Company::withoutGlobalScopes()->whereKey($companyId)->value('name') ?? '');
        $title = $owner ? 'Property income statements' : 'Landlord statements';
        $doc = ['title' => $title, 'head' => RentalDocument::letterhead($companyId), 'sub' => [$name, 'Amounts in '.$settings->currency, 'Part '.$part]];
        $parts = (int) ceil(count($all) / \App\Services\Rentals\Landlords\StatementPdf::PART_SIZE);
        if ($parts <= 1) {
            array_pop($doc['sub']);
        }
        $html = view('rentals.landlord-statements', ['doc' => $doc, 'cover' => $part === 1 ? self::paperTable($columns, $cover, $totals, 'Total', $d, ['title' => count($cover).' statements for '.$name]) : null,
            'statements' => array_map(fn ($r) => self::statementData($r, $d), $reports)])->render();

        return RentalDocument::output($html, 'a4', 'landscape', ['left' => implode(' · ', [$company, $title, $name]), 'generated' => '']);
    }

    /**
     * The letterhead as it stood when a statement was issued: its name and lines, the logo it was issued under.
     *
     * @param  array<string, mixed>  $s
     * @return array{name: string, logo: ?string, lines: list<string>}|null
     */
    private static function issuedHead(array $s, int $companyId): ?array
    {
        $head = $s['letterhead'] ?? null;
        if (! is_array($head)) {
            return null;
        }

        return ['name' => (string) ($head['name'] ?? ''), 'logo' => RentalDocument::issued($companyId, (string) ($head['name'] ?? ''), null, (string) ($head['logo_path'] ?? ''), true)['logo'], 'lines' => array_values((array) ($head['lines'] ?? []))];
    }

    /** @param array<string, mixed> $report @param array<string, mixed> $doc */
    private static function tenantHtml(array $report, array $doc, int $d, int $companyId): string
    {
        $t = (array) ($report['totals'] ?? []);
        $balance = (int) ($t['closing_minor'] ?? 0);
        $currency = (string) ($report['currency'] ?? '');
        $lease = (array) ($report['lease'] ?? []);
        $rows = $report['rows'];
        if ($rows !== [] && (int) ($rows[0]['balance_minor'] ?? 0) === 0 && ($rows[0]['description'] ?? '') === 'Opening balance') {
            array_shift($rows); // nothing to carry in: the first bill says where it starts
        }
        $sections = $report['sections'];
        foreach ($sections as $i => $s) {
            if (($s['title'] ?? '') === 'Deposit' && array_sum(array_map('intval', array_column($s['rows'], 'amount_minor'))) === 0) {
                $sections[$i]['rows'] = [];
            }
        }

        return view('rentals.tenant-statement', ['doc' => $doc,
            'big' => ['label' => $balance > 0 ? 'Owed' : ($balance < 0 ? 'Paid ahead' : 'Balance'), 'value' => $balance === 0 ? 'Nothing owed' : trim($currency.' '.self::paperMoney(abs($balance), $d, true)),
                'tenant' => (string) ($lease['tenant'] ?? ''), 'where' => trim(trim(($lease['unit'] ?? '').', '.($lease['property'] ?? ''), ', ').' · lease '.($lease['number'] ?? ''), ' ·')],
            'main' => self::paperTable($report['columns'], $rows, array_intersect_key($t, array_flip(['charged_minor', 'paid_minor', 'balance_minor'])), 'Total', $d, []),
            'sections' => self::paperSections(['sections' => $sections], $d),
            'payTo' => trim((string) (RentalsModule::settings($companyId)->payment_instructions ?? '')),
        ])->render();
    }

    /**
     * A one-line summary from the totals that are not under a column: "120 units · 104 occupied", "Held by landlords UGX 3,200,000".
     *
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private static function summary(array $report, int $d): array
    {
        $currency = (string) ($report['currency'] ?? '');
        $out = [];
        foreach (self::extraTotals($report) as [$label, $value, $type]) {
            $out[] = $type === 'int' ? number_format((int) $value).' '.mb_strtolower($label) : $label.' '.trim($currency.' '.self::paperMoney((int) $value, $d, true));
        }

        return $out;
    }

    /**
     * One table as the page draws it: cells with their classes, the group headings and subtotals, and the totals line.
     *
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $o  title, hide, group, subtotal_by, sum, show_head (see PAPER)
     * @return array{title: ?string, whole: bool, show_head: bool, head: list<array{label: string, class: string}>, rows: list<array{class: string, cells: list<array{text: string, class: string, span: int}>}>}
     */
    public static function paperTable(array $columns, array $rows, array $totals, string $label, int $d, array $o = []): array
    {
        $hide = (array) ($o['hide'] ?? []);
        $cols = array_values(array_filter($columns, fn ($c) => ! in_array($c['key'], $hide, true)));
        $group = $o['group'] ?? null;
        $by = $o['subtotal_by'] ?? null;
        $sum = (array) ($o['sum'] ?? []);
        $class = fn (array $c) => in_array($c['type'], ['money', 'int', 'pct'], true) ? 'n' : (self::keepsTogether($c) ? 'nw' : '');
        // A column with a bracket keeps its digits under each other: the others leave the bracket's room.
        $bracket = [];
        foreach ($cols as $c) {
            if ($c['type'] === 'money') {
                $bracket[$c['key']] = (int) ($totals[$c['key']] ?? 0) < 0 || collect($rows)->contains(fn ($r) => (int) ($r[$c['key']] ?? 0) < 0);
            }
        }
        $cell = function (array $c, mixed $v, bool $inTotal) use ($d, $class, $bracket) {
            $text = self::paperCell($v, $c, $d, $inTotal);
            if (($bracket[$c['key']] ?? false) && $text !== '' && ! str_ends_with($text, ')')) {
                $text .= "\u{00A0}";
            }

            return ['text' => $text, 'class' => $class($c), 'span' => 1];
        };
        $summed = function (string $words, array $values, string $rowClass) use ($cols, $cell) {
            $cells = [];
            $first = null;
            foreach ($cols as $i => $c) {
                if (array_key_exists($c['key'], $values) && $c['type'] !== 'text') {
                    $first ??= $i;
                }
            }
            $first = (int) ($first ?? count($cols));
            if ($first > 0) {
                $cells[] = ['text' => $words, 'class' => '', 'span' => $first];
            }
            foreach (array_slice($cols, $first) as $c) {
                $cells[] = array_key_exists($c['key'], $values) && $c['type'] !== 'text' ? $cell($c, $values[$c['key']], true) : ['text' => '', 'class' => '', 'span' => 1];
            }

            return ['class' => $rowClass, 'cells' => $cells];
        };
        $sumOf = function (array $list) use ($sum) {
            $out = [];
            foreach ($sum as $k) {
                $out[$k] = array_sum(array_map(fn ($r) => (int) ($r[$k] ?? 0), $list));
            }

            return $out;
        };
        $body = [];
        $n = 0;
        $draw = function (array $r) use (&$body, &$n, $cols, $cell) {
            $body[] = ['class' => $n < 2 ? 'keep' : '', 'cells' => array_map(fn ($c) => $cell($c, $r[$c['key']] ?? null, false), $cols)];
            $n++;
        };
        if ($group !== null) {
            foreach (collect($rows)->groupBy(fn ($r) => (string) ($r[$group] ?? '')) as $name => $list) {
                $body[] = ['class' => 'group', 'cells' => [['text' => (string) $name, 'class' => '', 'span' => max(1, count($cols))]]];
                $n = 0;
                $list = $list->all();
                foreach ($list as $r) {
                    $draw($r);
                }
                $body[] = $summed(sprintf((string) ($o['subtotal_words'] ?? 'Total, %s'), isset($o['subtotal_words']) ? mb_strtolower((string) $name) : (string) $name), $sumOf($list), 'sub');
            }
        } elseif ($by !== null) {
            foreach (collect($rows)->groupBy(fn ($r) => (string) ($r[$by] ?? '')) as $name => $list) {
                $list = $list->all();
                foreach ($list as $r) {
                    $draw($r);
                }
                if (count($list) > 1) {
                    $body[] = $summed(self::paperDate((string) $name), $sumOf($list), 'sub');
                }
            }
        } else {
            foreach ($rows as $r) {
                $draw($r);
            }
        }
        if ($rows === []) {
            $body[] = ['class' => '', 'cells' => [['text' => 'Nothing to show for this choice.', 'class' => 'empty', 'span' => max(1, count($cols))]]];
        } elseif (collect($cols)->contains(fn ($c) => array_key_exists($c['key'], $totals) && $c['type'] !== 'text')) {
            $body[] = $summed($label, $totals, 'total');
        }

        return ['title' => $o['title'] ?? null, 'whole' => count($rows) <= self::WHOLE_ROWS, 'show_head' => (bool) ($o['show_head'] ?? true),
            'head' => array_map(fn ($c) => ['label' => (string) $c['label'], 'class' => $class($c)], $cols), 'rows' => $body];
    }

    /** A cell on paper: a dash for zero except in totals, brackets for negatives, one date format, a phone as people read it. @param array{key: string, label?: string, type: string, places?: int} $c */
    public static function paperCell(mixed $v, array $c, int $decimals, bool $inTotal = false): string
    {
        if ($v === null || $v === '') {
            return '';
        }

        return match ($c['type']) {
            'money' => self::paperMoney((int) $v, $decimals, $inTotal),
            'int' => (int) $v === 0 && ! $inTotal ? '—' : number_format((int) $v),
            'pct' => (int) $v === 0 && ! $inTotal ? '—' : self::paperPercent((int) $v, $c['places'] ?? null),
            'date' => self::paperDate((string) $v),
            default => $c['key'] === 'phone' ? self::phone((string) $v) : (string) $v,
        };
    }

    /** Money on paper: "1,250,000", "(25,000)", and a dash for zero unless it is a total. */
    public static function paperMoney(int $minor, int $decimals, bool $showZero = false): string
    {
        if ($minor === 0 && ! $showZero) {
            return '—';
        }
        $shown = self::money(abs($minor), $decimals);

        return $minor < 0 ? '('.$shown.')' : $shown;
    }

    /** "1 Oct 2026", whatever the context. */
    public static function paperDate(string $day): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? Carbon::parse($day)->format('j M Y') : $day;
    }

    private static function paperPercent(int $bp, ?int $places): string
    {
        if ($places === null) {
            return rtrim(rtrim(Minor::toMajor($bp, 2), '0'), '.').' %';
        }

        return number_format($bp / 100, $places).' %';
    }

    /** @param array<string, mixed> $report */
    private static function period(array $report, int $companyId): string
    {
        if (! empty($report['from']) && ! empty($report['to'])) {
            return self::paperDate((string) $report['from']).' to '.self::paperDate((string) $report['to']);
        }

        return 'As at '.Carbon::parse((string) ($report['generated_at'] ?? now()->toIso8601String()))->setTimezone(LocalDate::timezone($companyId))->format('j M Y');
    }

    /** A cell on the report's screen: as display(), a phone as people read it. @param array{key: string, label?: string, type: string} $c */
    public static function shown(mixed $v, array $c, int $decimals): string
    {
        return $c['key'] === 'phone' && $v !== null && $v !== '' ? self::phone((string) $v) : self::display($v, $c['type'], $decimals);
    }

    /** Whether a column's text stays on one line: a code, a phone, a date, a one-word state. @param array{key: string, label?: string, type: string} $c */
    public static function keepsTogether(array $c): bool
    {
        return $c['type'] === 'date' || in_array($c['key'], self::NOWRAP, true);
    }

    /** A cell as people read it on the screen. */
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
