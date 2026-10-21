<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Receipt</title>
<style>body{font-family:DejaVu Sans,sans-serif;font-size:9px;color:#1f2933;margin:8px}.c{text-align:center}.h{font-weight:bold;font-size:11px}
table{width:100%;border-collapse:collapse;margin:6px 0;border-top:1px dashed #555;border-bottom:1px dashed #555}td{padding:2px 0}td.r{text-align:right}</style></head>
<body>
@foreach($p['header'] as $i => $h)<div class="c {{ $i === 0 ? 'h' : '' }}">{{ $h }}</div>@endforeach
<table>@foreach($p['lines'] as [$label, $value])<tr><td>{{ $label }}</td><td class="r">{{ $value }}</td></tr>@endforeach</table>
@foreach($p['footer'] as $f)<div class="c">{{ $f }}</div>@endforeach
</body></html>
