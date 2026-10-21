<?php

namespace App\Services\Sacco\Channels;

use App\Exceptions\BusinessRuleException;
use App\Support\Sacco\Currency;
use App\Support\XlsxReader;
use Illuminate\Support\Carbon;

/**
 * Reads the files people upload to Akiba (statements, employers' remittances, P3.4 and P3.5): an .xlsx sheet
 * (XlsxReader) or a CSV with commas, semicolons or tabs; headers found by their names, whatever their order; amounts
 * as written on paper ("1,500,000", "(25,000)", "UGX 1500.00") turned into whole minor units exactly, never floats.
 */
final class SheetReader
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @return list<list<string>> */
    public static function rows(string $contents): array
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw BusinessRuleException::make('file_too_large', 'The file is larger than 5 MB: split it by month.');
        }
        if (XlsxReader::isXlsx($contents)) {
            return XlsxReader::rows($contents);
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $first = (string) ($lines[0] ?? '');
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($first, $d))->first();
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = array_map(fn ($c) => trim((string) $c), str_getcsv($line, (string) $delimiter));
            if (count($rows) > XlsxReader::MAX_ROWS) {
                throw BusinessRuleException::make('file_too_large', 'The file has more than '.XlsxReader::MAX_ROWS.' rows: split it by month.');
            }
        }

        return $rows;
    }

    /**
     * Find the header row (the first row naming at least $need of the fields) and each field's column.
     *
     * @param  list<list<string>>  $rows
     * @param  array<string, list<string>>  $synonyms  field => header names, most likely first
     * @return array{0: int, 1: array<string, int>} [header row index, field => column]
     */
    public static function header(array $rows, array $synonyms, int $need = 2): array
    {
        foreach (array_slice($rows, 0, 15, true) as $i => $row) {
            $names = array_map(fn ($c) => self::norm($c), $row);
            $map = [];
            foreach ($synonyms as $field => $options) {
                foreach ($options as $option) {
                    $col = array_search(self::norm($option), $names, true);
                    if ($col !== false && ! in_array($col, $map, true)) {
                        $map[$field] = (int) $col;
                        break;
                    }
                }
            }
            if (count($map) >= $need) {
                return [(int) $i, $map];
            }
        }
        throw BusinessRuleException::make('unknown_format', 'The file\'s columns were not recognised: it needs at least a date and an amount (or debit and credit) column.');
    }

    /** A written amount in whole minor units (signed), or null for an empty cell. */
    public static function minor(string $raw, string $currency): ?int
    {
        $s = trim($raw);
        if ($s === '' || $s === '-') {
            return null;
        }
        $negative = str_starts_with($s, '(') && str_ends_with($s, ')');
        $s = preg_replace('/[^\d.\-]/', '', $s) ?? '';
        if (str_starts_with($s, '-')) {
            $negative = true;
            $s = ltrim($s, '-');
        }
        if (preg_match('/^\d+(\.\d+)?$/', $s) !== 1) {
            throw BusinessRuleException::make('invalid_amount', "\"{$raw}\" is not an amount.");
        }
        [$whole, $frac] = array_pad(explode('.', $s, 2), 2, '');
        $exp = Currency::exponent($currency);
        $extra = substr($frac, $exp);
        if ($extra !== '' && trim($extra, '0') !== '') {
            throw BusinessRuleException::make('invalid_amount', "\"{$raw}\" has more decimals than {$currency} has.");
        }
        $frac = str_pad(substr($frac, 0, $exp), $exp, '0');
        $digits = ltrim($whole.$frac, '0');
        if (strlen($digits) > 17) {
            throw BusinessRuleException::make('invalid_amount', "\"{$raw}\" is too large.");
        }
        $minor = (int) ($digits === '' ? '0' : $digits);

        return $negative ? -$minor : $minor;
    }

    /** A written date: d/m/Y (the Ugandan way), Y-m-d, an Excel serial number or anything Carbon reads. */
    public static function date(string $raw): ?Carbon
    {
        $s = trim($raw);
        if ($s === '') {
            return null;
        }
        try {
            if (preg_match('/^\d{5}(\.\d+)?$/', $s) === 1) {
                return Carbon::create(1899, 12, 30)->addDays((int) $s)->startOfDay();
            }
            if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})#', $s, $m) === 1) {
                return Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();
            }

            return Carbon::parse($s)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function norm(string $header): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($header)));
    }
}
