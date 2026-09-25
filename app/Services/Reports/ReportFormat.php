<?php

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;

/** Renders a report as JSON data, PDF or XLSX (plan A6). */
class ReportFormat
{
    public static function cell(mixed $value, string $type): string
    {
        return match ($type) {
            'money' => number_format((float) $value, 0),
            'percent' => number_format((float) $value, 1).'%',
            'number' => rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.'),
            default => (string) $value,
        };
    }

    /** @return array{0: string, 1: string, 2: string} [bytes, content type, file name] */
    public static function render(array $report, string $format): array
    {
        $file = $report['name'].'-'.$report['from'].'-'.$report['to'];

        return match ($format) {
            'pdf' => [app('dompdf.wrapper')->loadHTML(view('reports.table-report', ['report' => $report])->render())->setPaper('a4', count($report['columns']) > 5 ? 'landscape' : 'portrait')->output(), 'application/pdf', $file.'.pdf'],
            'xlsx' => [XlsxWriter::fromReport($report), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $file.'.xlsx'],
            default => throw BusinessRuleException::make('unknown_format', 'Use format=json, pdf or xlsx.'),
        };
    }
}
