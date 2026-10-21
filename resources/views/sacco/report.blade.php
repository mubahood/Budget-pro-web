@php($cur = $report['currency'])
@php($cell = fn ($v, $t) => \App\Services\Sacco\Reports\SaccoReportFormat::cell($v, $t, $cur))
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] }} · {{ $report['sacco']['short_name'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1f2933; }
        .head { border-bottom: 2px solid #1f2933; padding-bottom: 6px; margin-bottom: 8px; }
        .sacco { font-size: 15px; font-weight: bold; margin: 0; } h1 { font-size: 13px; margin: 6px 0 0; }
        .muted { color: #5b6672; } .reg { font-size: 9px; color: #1f5b3a; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 3px 5px; border-bottom: 1px solid #e3e8ee; text-align: left; vertical-align: top; }
        th { background: #f4f6f8; } .r { text-align: right; white-space: nowrap; } tfoot td { font-weight: bold; border-top: 2px solid #1f2933; }
        h2 { font-size: 11px; margin: 14px 0 0; } .foot { margin-top: 14px; font-size: 8.5px; color: #5b6672; }
    </style>
</head>
<body>
    <div class="head">
        <p class="sacco">{{ $report['sacco']['legal_name'] }}</p>
        <div class="muted">
            @if(!empty($report['sacco']['registration_no']))Registration (CS No.) {{ $report['sacco']['registration_no'] }}@endif
            @if(!empty($report['sacco']['licence_no'])) · Licence {{ $report['sacco']['licence_no'] }}@endif
        </div>
        @if(!empty($report['sacco']['regulated_line']))<div class="reg">{{ $report['sacco']['regulated_line'] }}</div>@endif
        <h1>{{ $report['title'] }}</h1>
        <div class="muted">{{ $report['from'] }} to {{ $report['to'] }} · {{ $cur }} · printed {{ \Illuminate\Support\Carbon::parse($report['generated_at'])->format('j M Y H:i') }}</div>
    </div>
    @foreach(array_merge([['title' => null, 'columns' => $report['columns'], 'rows' => $report['rows'], 'totals' => $report['totals'] ?? []]], $report['sections'] ?? []) as $t)
        @if($t['title'])<h2>{{ $t['title'] }}</h2>@endif
        <table>
            <thead><tr>@foreach($t['columns'] as $c)<th class="{{ in_array($c['type'], ['money', 'number', 'bp'], true) ? 'r' : '' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse($t['rows'] as $row)
                <tr>@foreach($t['columns'] as $c)<td class="{{ in_array($c['type'], ['money', 'number', 'bp'], true) ? 'r' : '' }}">{{ $cell($row[$c['key']] ?? null, $c['type']) }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($t['columns']) }}" class="muted">Nothing in this period.</td></tr>
            @endforelse
            </tbody>
            @if(!empty($t['totals']) && !empty($t['rows']))
                <tfoot><tr>@foreach($t['columns'] as $i => $c)<td class="{{ in_array($c['type'], ['money', 'number', 'bp'], true) ? 'r' : '' }}">{{ $i === 0 ? 'Total' : (array_key_exists($c['key'], $t['totals']) ? $cell($t['totals'][$c['key']], $c['type']) : '') }}</td>@endforeach</tr></tfoot>
            @endif
        </table>
    @endforeach
    @if(!empty($report['meta']['note']))<p>{{ $report['meta']['note'] }}</p>@endif
    <div class="foot">{{ $report['sacco']['short_name'] }} · computed from the ledger · Akiba Ledger</div>
</body>
</html>
