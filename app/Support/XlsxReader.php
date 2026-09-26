<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;

/**
 * Reads the first worksheet of an Excel .xlsx file into rows of strings — enough for a product
 * list — without PhpSpreadsheet (≈ 30 MB of vendor code for one import screen).
 *
 * An .xlsx is a zip of XML parts: the workbook names its sheets, the relationships file says where
 * each sheet lives, shared strings hold the text, and each cell is `<c r="B2" t="s"><v>3</v></c>`.
 * Formulas give their last calculated value; dates stay Excel serial numbers (not needed here).
 */
class XlsxReader
{
    /** Refuse sheets beyond this many rows (a product list, not a data warehouse). */
    public const MAX_ROWS = 20000;

    public static function isXlsx(string $contents): bool
    {
        return str_starts_with($contents, "PK\x03\x04");
    }

    /**
     * @return list<list<string>> the first sheet's rows, each padded to the widest row
     */
    public static function rows(string $contents): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw BusinessRuleException::make('xlsx_unsupported', 'Excel files cannot be read on this server. Save the sheet as CSV and upload that.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $contents);
        $zip = new \ZipArchive();
        $open = false;
        try {
            if ($zip->open($tmp) !== true) {
                throw BusinessRuleException::make('invalid_file', 'This file is not a readable Excel (.xlsx) file.');
            }
            $open = true;
            $strings = self::sharedStrings($zip);
            $sheet = $zip->getFromName(self::firstSheetPath($zip));
            if ($sheet === false) {
                throw BusinessRuleException::make('invalid_file', 'This Excel file has no worksheet.');
            }

            return self::sheetRows($sheet, $strings);
        } finally {
            if ($open) {
                $zip->close();
            }
            @unlink($tmp);
        }
    }

    /** The first sheet as CSV text (comma separated, header row first), for the existing CSV import. */
    public static function toCsv(string $contents): string
    {
        $out = fopen('php://temp', 'r+');
        foreach (self::rows($contents) as $row) {
            fputcsv($out, $row);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private static function xml(string $xml): \SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        try {
            // LIBXML_NONET: never fetch anything; no entity expansion flags are set.
            $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if ($doc === false) {
            throw BusinessRuleException::make('invalid_file', 'This Excel file is damaged.');
        }

        return $doc;
    }

    /** @return list<string> */
    private static function sharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $doc = self::xml($xml);
        $out = [];
        foreach ($doc->children(self::NS)->si as $si) {
            $out[] = self::text($si);
        }

        return $out;
    }

    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Text of a shared string or inline string: plain `<t>` or rich-text runs `<r><t>`. */
    private static function text(\SimpleXMLElement $node): string
    {
        $c = $node->children(self::NS);
        if (isset($c->t)) {
            return (string) $c->t;
        }
        $s = '';
        foreach ($c->r as $run) {
            $s .= (string) $run->children(self::NS)->t;
        }

        return $s;
    }

    private static function firstSheetPath(\ZipArchive $zip): string
    {
        $fallback = 'xl/worksheets/sheet1.xml';
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) {
            return $fallback;
        }
        $sheets = self::xml($wb)->children(self::NS)->sheets;
        if (! isset($sheets->sheet[0])) {
            return $fallback;
        }
        $rid = (string) $sheets->sheet[0]->attributes(self::REL_NS)['id'];
        foreach (self::xml($rels)->children('http://schemas.openxmlformats.org/package/2006/relationships') as $rel) {
            $a = $rel->attributes();
            if ((string) $a['Id'] === $rid) {
                $target = ltrim((string) $a['Target'], '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        return $fallback;
    }

    /** "C" → 2, "AA" → 26 */
    private static function column(string $ref): int
    {
        $letters = preg_replace('/\d+/', '', strtoupper($ref));
        $n = 0;
        foreach (str_split((string) $letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return max(0, $n - 1);
    }

    /**
     * @param  list<string>  $strings
     * @return list<list<string>>
     */
    private static function sheetRows(string $xml, array $strings): array
    {
        $doc = self::xml($xml);
        $data = $doc->children(self::NS)->sheetData;
        $rows = [];
        $width = 0;
        $count = 0;
        foreach ($data->row as $row) {
            if (++$count > self::MAX_ROWS) {
                throw BusinessRuleException::make('file_too_large', 'The sheet has more than '.number_format(self::MAX_ROWS).' rows. Split it into smaller files.');
            }
            $cells = [];
            $next = 0;
            foreach ($row->children(self::NS)->c as $c) {
                $attrs = $c->attributes(); // unprefixed attributes (not in the sheet's namespace)
                $ref = (string) $attrs['r'];
                $i = $ref !== '' ? self::column($ref) : $next;
                $next = $i + 1;
                $type = (string) $attrs['t'];
                $kids = $c->children(self::NS);
                $v = isset($kids->v) ? (string) $kids->v : '';
                $cells[$i] = match ($type) {
                    's' => $strings[(int) $v] ?? '',
                    'inlineStr' => isset($kids->is) ? self::text($kids->is) : '',
                    'b' => $v === '1' ? 'TRUE' : 'FALSE',
                    default => self::number($v),
                };
            }
            if ($cells === [] || implode('', $cells) === '') {
                continue; // blank row
            }
            $max = max(array_keys($cells));
            $line = [];
            for ($j = 0; $j <= $max; $j++) {
                $line[] = trim((string) ($cells[$j] ?? ''));
            }
            $width = max($width, count($line));
            $rows[] = $line;
        }

        return array_map(fn ($r) => array_pad($r, $width, ''), $rows);
    }

    /** Excel stores 4000 as "4000" but 0.1+0.2 style floats as "0.30000000000000004"; tidy those. */
    private static function number(string $v): string
    {
        if ($v === '' || ! is_numeric($v) || (! str_contains($v, '.') && ! str_contains(strtolower($v), 'e'))) {
            return $v;
        }
        $f = (float) $v;

        return rtrim(rtrim(sprintf('%.6F', round($f, 6)), '0'), '.');
    }
}
