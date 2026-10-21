<?php

namespace App\Services\Sacco\Reports;

use App\Exceptions\BusinessRuleException;
use App\Services\Reports\XlsxWriter;
use App\Support\Sacco\Amount;
use App\Support\Sacco\Currency;

/**
 * A SACCO report as a file (04 §12): PDF through dompdf with the SACCO's header, XLSX through XlsxWriter, or CSV.
 * Money stays exact: minor units become major-unit strings by Amount (never through a float); in XLSX a currency
 * without decimals is written as whole numbers, one with decimals as exact text. The PDF view is found from this
 * class, so the web portal renders the same file.
 */
final class SaccoReportFormat
{
    public static function view(): string
    {
        return dirname(__DIR__, 4).'/resources/views/sacco/report.blade.php';
    }

    /** A cell as people read it. */
    public static function cell(mixed $value, string $type, string $currency): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            'money' => self::money((int) $value, $currency),
            'bp' => self::bp((int) $value),
            'number' => number_format((int) $value),
            default => is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value,
        };
    }

    public static function money(int $minor, string $currency): string
    {
        $major = Amount::of($minor, $currency)->toMajorString();
        $negative = str_starts_with($major, '-');
        [$int, $dec] = array_pad(explode('.', ltrim($major, '-')), 2, null);
        $grouped = ltrim(strrev(implode(',', str_split(strrev($int), 3))), ',');

        return ($negative ? '-' : '').$grouped.($dec !== null ? '.'.$dec : '');
    }

    public static function bp(int $bp): string
    {
        return intdiv($bp, 100).'.'.str_pad((string) ($bp % 100), 2, '0', STR_PAD_LEFT).' %';
    }

    /** @param array<string, mixed> $report @return array{0: string, 1: string, 2: string} [bytes, content type, file name] */
    public static function render(array $report, string $format): array
    {
        $file = 'sacco-'.$report['name'].'-'.$report['from'].'-'.$report['to'];

        return match ($format) {
            'pdf' => [app('dompdf.wrapper')->loadHTML(view()->file(self::view(), ['report' => $report])->render())
                ->setPaper('a4', self::wide($report) ? 'landscape' : 'portrait')->output(), 'application/pdf', $file.'.pdf'],
            'xlsx' => [XlsxWriter::build($report['title'], self::table($report, true)), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $file.'.xlsx'],
            'csv' => [self::csv(self::table($report, false)), 'text/csv; charset=utf-8', $file.'.csv'],
            default => throw BusinessRuleException::make('unknown_format', 'Use format=json, pdf, xlsx or csv.'),
        };
    }

    /**
     * Header block, the main table, then each section, as rows of cells.
     *
     * @param  array<string, mixed>  $report
     * @return list<list<string|int>>
     */
    public static function table(array $report, bool $numbers): array
    {
        $cur = (string) $report['currency'];
        $whole = Currency::exponent($cur) === 0;
        $value = function (mixed $v, string $type) use ($numbers, $whole, $cur) {
            if ($numbers && $type === 'money' && $whole && $v !== null && $v !== '') {
                return (int) $v;
            }
            if ($numbers && $type === 'number' && $v !== null && $v !== '') {
                return (int) $v;
            }
            if ($type === 'money' && $v !== null && $v !== '') {
                return Amount::of((int) $v, $cur)->toMajorString();
            }

            return self::cell($v, $type, $cur);
        };
        $out = [[(string) $report['sacco']['legal_name']], [$report['title'].' · '.$report['from'].' to '.$report['to'].' · '.$cur]];
        if (! empty($report['sacco']['registration_no'])) {
            $out[] = ['Registration '.$report['sacco']['registration_no']];
        }
        $block = function (array $cols, array $rows, array $totals) use (&$out, $value) {
            $out[] = array_map(fn ($c) => $c['label'], $cols);
            foreach ($rows as $r) {
                $out[] = array_map(fn ($c) => $value($r[$c['key']] ?? null, $c['type']), $cols);
            }
            if ($totals !== []) {
                $out[] = array_map(fn ($c, $i) => $i === 0 ? 'Total' : (array_key_exists($c['key'], $totals) ? $value($totals[$c['key']], $c['type']) : ''), $cols, array_keys($cols));
            }
        };
        $block($report['columns'], $report['rows'], $report['totals'] ?? []);
        foreach ($report['sections'] ?? [] as $s) {
            $out[] = [''];
            $out[] = [(string) $s['title']];
            $block($s['columns'], $s['rows'], $s['totals'] ?? []);
        }

        return $out;
    }

    /** @param list<list<string|int>> $rows */
    private static function csv(array $rows): string
    {
        $h = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            fputcsv($h, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) && ! is_numeric($v) ? "'".$v : $v, $r));
        }
        rewind($h);
        $csv = (string) stream_get_contents($h);
        fclose($h);

        return "\xEF\xBB\xBF".$csv;
    }

    /** @param array<string, mixed> $report */
    private static function wide(array $report): bool
    {
        $max = count($report['columns']);
        foreach ($report['sections'] ?? [] as $s) {
            $max = max($max, count($s['columns']));
        }

        return $max > 5;
    }
}
