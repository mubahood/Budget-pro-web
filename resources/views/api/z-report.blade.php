{{-- Z report for the phone (GET api/v1/z-reports/{id}?format=pdf): 80 mm, the stored totals of ZReportService::close(). --}}
@php
    $cur = $f['currency'] ?? $company->currency;
    $m = fn ($v) => number_format((float) $v, 2);
    $flat = function ($v) use (&$flat) {
        if (! is_array($v)) { return (string) $v; }
        return implode(' · ', array_map(fn ($k, $x) => (is_int($k) ? '' : str_replace('_', ' ', $k).': ').$flat($x), array_keys($v), $v));
    };
@endphp
<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $z->number }}</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; margin: 6px; color: #000; }
    h1 { font-size: 12px; text-align: center; margin: 0 0 2px; }
    .c { text-align: center; } .r { text-align: right; }
    table { width: 100%; border-collapse: collapse; margin: 4px 0; }
    td { padding: 1px 0; vertical-align: top; }
    .h { border-top: 1px dashed #000; font-weight: bold; padding-top: 3px; }
</style></head>
<body>
    <h1>{{ $company->name }}</h1>
    <div class="c">Z REPORT {{ $z->number }}</div>
    <div class="c">Day {{ substr((string) $z->business_date, 0, 10) }}@if(!empty($f['location_name'])) · {{ $f['location_name'] }}@endif</div>
    <div class="c">Closed {{ optional($z->created_at)->format('d M Y H:i') }}@if($closedBy) by {{ $closedBy }}@endif</div>
    <table>
        <tr><td class="h" colspan="2">Sales ({{ $cur }})</td></tr>
        <tr><td>Sales</td><td class="r">{{ $f['sales']['count'] ?? 0 }}</td></tr>
        <tr><td>Gross</td><td class="r">{{ $m($f['sales']['gross'] ?? 0) }}</td></tr>
        <tr><td>Refunded</td><td class="r">{{ $m($f['sales']['refunded'] ?? 0) }}</td></tr>
        <tr><td><b>Net</b></td><td class="r"><b>{{ $m($f['sales']['net'] ?? 0) }}</b></td></tr>
        <tr><td>Discounts ({{ $f['discounts']['count'] ?? 0 }})</td><td class="r">{{ $m($f['discounts']['total'] ?? 0) }}</td></tr>
        <tr><td>Voids ({{ $f['voids']['count'] ?? 0 }})</td><td class="r">{{ $m($f['voids']['total'] ?? 0) }}</td></tr>
        <tr><td>Refunds ({{ $f['refunds']['count'] ?? 0 }})</td><td class="r">{{ $m($f['refunds']['refunded'] ?? 0) }}</td></tr>
        @if(!empty($f['tax']['rate']))
            <tr><td>VAT {{ $f['tax']['rate'] }}% in net sales</td><td class="r">{{ $m($f['tax']['vat'] ?? 0) }}</td></tr>
        @endif
        @foreach (['by_method' => 'By payment method', 'cash_movements' => 'Cash movements', 'cashiers' => 'Cashiers', 'late_sales' => 'Late sales (days already closed)'] as $key => $title)
            @if(!empty($f[$key]) && is_array($f[$key]))
                <tr><td class="h" colspan="2">{{ $title }}</td></tr>
                @foreach ($f[$key] as $k => $row)
                    <tr><td colspan="2">{{ is_int($k) ? '' : str_replace('_', ' ', $k).': ' }}{{ $flat($row) }}</td></tr>
                @endforeach
            @endif
        @endforeach
        <tr><td class="h">No-sale drawer openings</td><td class="h r">{{ $f['no_sales'] ?? 0 }}</td></tr>
        <tr><td>Cash over / short</td><td class="r">{{ $m($f['over_short'] ?? 0) }}</td></tr>
    </table>
    <div class="c">Printed {{ now()->format('d M Y H:i') }}</div>
</body></html>
