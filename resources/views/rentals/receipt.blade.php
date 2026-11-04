{{-- A tenant's receipt (Estate Ledger, docs/estate-ledger/04-architecture.md §10.4): Payments\Receipt::parts(). Plain, light, square. --}}
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Receipt</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1e2b33; margin: 8px; }
    .c { text-align: center; } .h { font-weight: bold; font-size: 11px; } .t { font-weight: bold; font-size: 10px; letter-spacing: 1px; text-transform: uppercase; margin-top: 2px; }
    table { width: 100%; border-collapse: collapse; margin: 6px 0; border-top: 1px solid #1e2b33; }
    td { padding: 2px 0; vertical-align: top; } td.r { text-align: right; } .sub { font-weight: bold; padding-top: 4px; }
    .rev { border: 1px solid #b42318; color: #b42318; padding: 3px; margin: 6px 0; text-align: center; font-weight: bold; }
    .f { color: #4b5a63; }
</style></head>
<body>
@foreach($p['header'] as $i => $h)<div class="c {{ $i === 0 ? 'h' : ($loop->last ? 't' : 'f') }}">{{ $h }}</div>@endforeach
<table>@foreach($p['lines'] as [$label, $value])<tr><td>{{ $label }}</td><td class="r">{{ $value }}</td></tr>@endforeach</table>
@if(! empty($p['words']))<div class="f">{{ $p['words'] }}</div>@endif
@if($p['settled'] !== [])
<table><tr><td class="sub" colspan="2">Settled</td></tr>@foreach($p['settled'] as [$label, $value])<tr><td>{{ $label }}</td><td class="r">{{ $value }}</td></tr>@endforeach</table>
@endif
<table>@foreach($p['after'] as [$label, $value])<tr><td>{{ $label }}</td><td class="r">{{ $value }}</td></tr>@endforeach</table>
@if($p['reversed'])<div class="rev">REVERSED · {{ $p['reversed'] }}</div>@endif
@foreach($p['footer'] as $f)<div class="c f">{{ $f }}</div>@endforeach
</body></html>
