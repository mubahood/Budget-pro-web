<?php

namespace App\Services\Reports;

use ZipArchive;

/**
 * A minimal Office Open XML (.xlsx) writer for report tables — one sheet, a
 * bold header row, numbers stored as numbers. No third-party dependency
 * (vendor/ ships with the app; decision E12).
 */
class XlsxWriter
{
    /**
     * @param  array<int, array<int, string|int|float|null>>  $rows  first row = header
     */
    public static function build(string $sheetTitle, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $title = htmlspecialchars(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $sheetTitle) ?: 'Report', 0, 31), ENT_XML1);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$title.'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="3"><xf/><xf fontId="1" applyFont="1"/><xf numFmtId="4" applyNumberFormat="1"/></cellXfs></styleSheet>');
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach (array_values($rows) as $r => $row) {
            $xml .= '<row r="'.($r + 1).'">';
            foreach (array_values($row) as $c => $value) {
                $ref = self::column($c).($r + 1);
                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="'.$ref.'"'.(is_float($value) ? ' s="2"' : '').'><v>'.$value.'</v></c>';
                } else {
                    $xml .= '<c r="'.$ref.'" t="inlineStr"'.($r === 0 ? ' s="1"' : '').'><is><t xml:space="preserve">'.htmlspecialchars((string) $value, ENT_XML1).'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml.'</sheetData></worksheet>');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** A workbook's cell styles, by name. */
    private const STYLES = [
        'default' => [0, 0, 0, null], 'title' => [2, 0, 0, null], 'note' => [3, 0, 0, null], 'bold' => [1, 0, 0, null],
        'head' => [1, 0, 1, 'left'], 'head_r' => [1, 0, 1, 'right'],
        'money0' => [0, 164, 0, null], 'money2' => [0, 165, 0, null], 'int' => [0, 164, 0, null], 'pct' => [0, 166, 0, null], 'date' => [0, 167, 0, 'left'],
        'total' => [1, 0, 2, null], 'total_money0' => [1, 164, 2, null], 'total_money2' => [1, 165, 2, null], 'total_int' => [1, 164, 2, null], 'total_pct' => [1, 166, 2, null],
    ];

    /**
     * A finished workbook: several sheets, each with its own column widths, bold heading row kept in view, filter over its
     * rows, real numbers, percentages and dates. No formulas: every cell is a value or inline text, so a name that looks
     * like a formula stays a name. build() stays as it is for the reports that use it.
     *
     * A cell is a value, or ['v' => value, 's' => a name from STYLES]. A date is its Excel day number in style 'date'.
     *
     * @param  list<array{name: string, rows: list<list<mixed>>, widths?: list<float|int>, heading?: int, last?: int, columns?: int}>  $sheets  heading: 1-based row of the column headings; last: 1-based last row of the filter
     * @return string the .xlsx file
     */
    public static function workbook(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $names = [];
        foreach ($sheets as $i => $sheet) {
            $name = trim(mb_substr((string) preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $sheet['name']), 0, 31)) ?: 'Sheet '.($i + 1);
            $names[$i] = in_array(mb_strtolower($name), array_map('mb_strtolower', $names), true) ? mb_substr($name, 0, 27).' '.($i + 1) : $name;
        }
        $override = '';
        $rels = '';
        $list = '';
        $defined = '';
        foreach ($sheets as $i => $sheet) {
            $n = $i + 1;
            $override .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $list .= '<sheet name="'.htmlspecialchars($names[$i], ENT_XML1 | ENT_QUOTES).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            if (($range = self::filterRange($sheet, true)) !== null) {
                $defined .= '<definedName name="_xlnm._FilterDatabase" localSheetId="'.$i.'" hidden="1">\''.htmlspecialchars(str_replace("'", "''", $names[$i]), ENT_XML1).'\'!'.$range.'</definedName>';
            }
            $zip->addFromString('xl/worksheets/sheet'.$n.'.xml', self::sheetXml($sheet));
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$override.'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$list.'</sheets>'.($defined !== '' ? '<definedNames>'.$defined.'</definedNames>' : '').'</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'<Relationship Id="rId'.(count($sheets) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** @param array<string, mixed> $sheet */
    private static function filterRange(array $sheet, bool $absolute): ?string
    {
        $heading = (int) ($sheet['heading'] ?? 0);
        $last = (int) ($sheet['last'] ?? 0);
        $columns = (int) ($sheet['columns'] ?? 0);
        if ($heading < 1 || $last <= $heading || $columns < 1) {
            return null;
        }
        $end = self::column($columns - 1);

        return $absolute ? '$A$'.$heading.':$'.$end.'$'.$last : 'A'.$heading.':'.$end.$last;
    }

    /** @param array<string, mixed> $sheet */
    private static function sheetXml(array $sheet): string
    {
        $styleIds = array_flip(array_keys(self::STYLES));
        $heading = (int) ($sheet['heading'] ?? 0);
        $data = '';
        $wide = 1;
        foreach (array_values($sheet['rows']) as $r => $row) {
            $data .= '<row r="'.($r + 1).'">';
            $row = array_values($row);
            $wide = max($wide, count($row));
            foreach ($row as $c => $cell) {
                $value = is_array($cell) ? ($cell['v'] ?? null) : $cell;
                $style = is_array($cell) ? ($styleIds[$cell['s'] ?? 'default'] ?? 0) : 0;
                $at = ' r="'.self::column($c).($r + 1).'"'.($style > 0 ? ' s="'.$style.'"' : '');
                if ($value === null || $value === '') {
                    $data .= $style > 0 ? '<c'.$at.'/>' : '';
                } elseif (is_int($value) || is_float($value)) {
                    $data .= '<c'.$at.'><v>'.json_encode($value).'</v></c>';
                } else {
                    $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value);
                    $data .= '<c'.$at.' t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1).'</t></is></c>';
                }
            }
            $data .= '</row>';
        }
        $count = count($sheet['rows']);
        $cols = '';
        foreach (array_values($sheet['widths'] ?? []) as $i => $w) {
            $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.round((float) $w, 1).'" customWidth="1"/>';
        }
        $pane = $heading > 0 ? '<pane ySplit="'.$heading.'" topLeftCell="A'.($heading + 1).'" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A'.($heading + 1).'" sqref="A'.($heading + 1).'"/>' : '';
        $filter = self::filterRange($sheet, false);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:'.self::column($wide - 1).max(1, $count).'"/>'
            .'<sheetViews><sheetView workbookViewId="0" showGridLines="1">'.$pane.'</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>'
            .($cols !== '' ? '<cols>'.$cols.'</cols>' : '').'<sheetData>'.$data.'</sheetData>'
            .($filter !== null ? '<autoFilter ref="'.$filter.'"/>' : '')
            .'<pageMargins left="0.5" right="0.5" top="0.5" bottom="0.5" header="0.3" footer="0.3"/><pageSetup paperSize="9" orientation="'.($wide > 7 ? 'landscape' : 'portrait').'" fitToWidth="1" fitToHeight="0"/></worksheet>';
    }

    private static function stylesXml(): string
    {
        $xfs = '';
        foreach (self::STYLES as [$font, $format, $border, $align]) {
            $xfs .= '<xf numFmtId="'.$format.'" fontId="'.$font.'" fillId="0" borderId="'.$border.'" xfId="0"'.($format > 0 ? ' applyNumberFormat="1"' : '').($font > 0 ? ' applyFont="1"' : '').($border > 0 ? ' applyBorder="1"' : '')
                .($align !== null ? ' applyAlignment="1"><alignment horizontal="'.$align.'"/></xf>' : '/>');
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="4"><numFmt numFmtId="164" formatCode="#,##0"/><numFmt numFmtId="165" formatCode="#,##0.00"/><numFmt numFmtId="166" formatCode="0.0%"/><numFmt numFmtId="167" formatCode="d mmm yyyy"/></numFmts>'
            .'<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="13"/><name val="Calibri"/></font><font><sz val="10"/><color rgb="FF55606A"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="3"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="thin"><color rgb="FF1E2B33"/></bottom><diagonal/></border><border><left/><right/><top style="thin"><color rgb="FF1E2B33"/></top><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="'.count(self::STYLES).'">'.$xfs.'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    public static function column(int $index): string
    {
        $s = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26).$s;
        }

        return $s;
    }

    /** Report → rows for build(): header, data, totals; then each of its `sections` (if any) below, after a blank row and its title. */
    public static function fromReport(array $report): string
    {
        $out = self::tableRows($report['columns'], $report['rows'], $report['totals'] ?? []);
        foreach ($report['sections'] ?? [] as $section) {
            $out[] = [''];
            $out[] = [(string) $section['title']];
            array_push($out, ...self::tableRows($section['columns'], $section['rows'], $section['totals'] ?? []));
        }

        return self::build($report['title'], $out);
    }

    /** @return list<list<string|float>> */
    private static function tableRows(array $cols, array $rows, array $totals): array
    {
        $out = [array_map(fn ($c) => $c['label'], $cols)];
        foreach ($rows as $row) {
            $out[] = array_map(fn ($c) => in_array($c['type'], ['money', 'number', 'percent'], true) ? (float) ($row[$c['key']] ?? 0) : (string) ($row[$c['key']] ?? ''), $cols);
        }
        if (! empty($totals)) {
            $out[] = array_map(fn ($c, $i) => $i === 0 ? 'Total' : (array_key_exists($c['key'], $totals) ? (float) $totals[$c['key']] : ''), $cols, array_keys($cols));
        }

        return $out;
    }
}
