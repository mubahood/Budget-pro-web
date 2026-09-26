<?php

namespace Tests\Support;

/** Builds a small real .xlsx (shared strings, inline strings, numbers) the way Excel lays one out. */
class XlsxFixture
{
    /** @param list<list<string|int|float|null>> $rows */
    public static function make(array $rows, string $sheetName = 'Products'): string
    {
        $strings = [];
        $sheet = '';
        foreach ($rows as $r => $row) {
            $sheet .= '<row r="'.($r + 1).'">';
            foreach (array_values($row) as $c => $v) {
                $ref = self::col($c).($r + 1);
                if ($v === null || $v === '') {
                    continue;
                }
                if (is_int($v) || is_float($v)) {
                    $sheet .= "<c r=\"{$ref}\"><v>{$v}</v></c>";
                } elseif ($c === 1) { // column B as an inline string, to cover both string kinds
                    $sheet .= "<c r=\"{$ref}\" t=\"inlineStr\"><is><t>".htmlspecialchars($v, ENT_XML1).'</t></is></c>';
                } else {
                    $i = array_search($v, $strings, true);
                    if ($i === false) {
                        $strings[] = $v;
                        $i = count($strings) - 1;
                    }
                    $sheet .= "<c r=\"{$ref}\" t=\"s\"><v>{$i}</v></c>";
                }
            }
            $sheet .= '</row>';
        }
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $sst = '<?xml version="1.0" encoding="UTF-8"?><sst '.$ns.' count="'.count($strings).'">'
            .implode('', array_map(fn ($s) => '<si><t>'.htmlspecialchars($s, ENT_XML1).'</t></si>', $strings)).'</sst>';
        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet7.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook '.$ns.' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="'.htmlspecialchars($sheetName, ENT_XML1).'" sheetId="1" r:id="rId3"/></sheets></workbook>',
            // The first sheet deliberately is not sheet1.xml: the reader must follow the relationships.
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet7.xml"/>'
                .'<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>',
            'xl/sharedStrings.xml' => $sst,
            'xl/worksheets/sheet7.xml' => '<?xml version="1.0" encoding="UTF-8"?><worksheet '.$ns.'><sheetData>'.$sheet.'</sheetData></worksheet>',
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'xf');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($parts as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }
}
