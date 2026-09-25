<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2933; }
        h1 { font-size: 16px; margin: 0; } .muted { color: #6b7785; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #e3e8ee; text-align: left; }
        th { background: #f4f6f8; } .r { text-align: right; } tfoot td { font-weight: bold; border-top: 2px solid #1f2933; }
    </style>
</head>
<body>
    <h1>{{ $report['title'] }}</h1>
    <div class="muted">{{ $report['company'] }} · {{ $report['from'] }} – {{ $report['to'] }} · {{ $report['currency'] }} · generated {{ $report['generated_at'] }}</div>
    @if(!empty($report['meta']['note']))<p>{{ $report['meta']['note'] }}</p>@endif
    <table>
        <thead><tr>@foreach($report['columns'] as $c)<th class="{{ $c['type'] === 'text' ? '' : 'r' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse($report['rows'] as $row)
            <tr>@foreach($report['columns'] as $c)
                <td class="{{ $c['type'] === 'text' ? '' : 'r' }}">{{ \App\Services\Reports\ReportFormat::cell($row[$c['key']] ?? null, $c['type']) }}</td>
            @endforeach</tr>
        @empty
            <tr><td colspan="{{ count($report['columns']) }}" class="muted">Nothing in this period.</td></tr>
        @endforelse
        </tbody>
        @if(!empty($report['totals']))
            <tfoot><tr>@foreach($report['columns'] as $i => $c)<td class="{{ $c['type'] === 'text' ? '' : 'r' }}">{{ $i === 0 ? 'Total' : (array_key_exists($c['key'], $report['totals']) ? \App\Services\Reports\ReportFormat::cell($report['totals'][$c['key']], $c['type']) : '') }}</td>@endforeach</tr></tfoot>
        @endif
    </table>
    @foreach(($report['meta'] ?? []) as $k => $v)
        @if(!in_array($k, ['note', 'days', 'rate'], true) && is_numeric($v))<p><strong>{{ ucfirst(str_replace('_', ' ', $k)) }}:</strong> {{ number_format((float) $v, 2) }}</p>@endif
    @endforeach
</body>
</html>
