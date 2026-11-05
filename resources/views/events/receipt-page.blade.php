<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Receipt {{ $r['number'] }}</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; background: #fff; color: #1e2b33; font: 15px/1.45 -apple-system, "Segoe UI", Roboto, sans-serif; }
  main { max-width: 480px; margin: 0 auto; padding: 20px 16px 28px; }
  .acct { font-size: 17px; font-weight: 700; margin: 0; overflow-wrap: anywhere; }
  .lines { font-size: 12.5px; color: #55606a; margin: 2px 0 0; overflow-wrap: anywhere; }
  img.logo { max-height: 48px; max-width: 150px; display: block; margin-bottom: 8px; }
  h1 { font-size: 13px; font-weight: 600; color: #55606a; margin: 18px 0 2px; padding-top: 14px; border-top: 1px solid #1e2b33; }
  .amount { font-size: 30px; font-weight: 700; font-variant-numeric: tabular-nums; margin: 0; overflow-wrap: anywhere; }
  .words { font-size: 13px; color: #55606a; margin: 2px 0 14px; }
  dl { margin: 0 0 14px; }
  dl div { display: flex; justify-content: space-between; gap: 16px; padding: 7px 0; border-bottom: 1px solid #d8dcdf; }
  dt { color: #55606a; flex: none; }
  dd { margin: 0; text-align: right; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
  .b { font-weight: 700; } .b dt { color: inherit; }
  .gone { border: 1px solid #1e2b33; padding: 8px 10px; font-weight: 700; margin: 12px 0 0; }
  .foot { font-size: 12.5px; color: #55606a; margin: 0 0 16px; }
  .acts { display: flex; gap: 8px; }
  .acts a, .acts button { flex: 1; min-height: 44px; display: flex; align-items: center; justify-content: center; border: 1px solid #1e2b33; border-radius: 6px; background: #fff; color: #1e2b33; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
  @media print { .acts { display: none; } }
</style>
</head>
<body>
<main>
  @if($head['logo'])<img class="logo" src="{{ $head['logo'] }}" alt="">@endif
  <p class="acct">{{ $head['name'] }}</p>
  @foreach($head['lines'] as $line)<p class="lines">{{ $line }}</p>@endforeach
  @if($r['reversed_on'])<p class="gone">REVERSED on {{ $day($r['reversed_on']) }}. This payment was cancelled and does not count.</p>@endif
  <h1>Receipt {{ $r['number'] }}</h1>
  <p class="amount">{{ $label }} {{ $m($r['amount']) }}</p>
  <p class="words">{{ $words }} {{ $label }}</p>
  <dl>
    <div><dt>Received from</dt><dd>{{ $r['from'] }}</dd></div>
    <div><dt>For</dt><dd>{{ $r['event'] }}</dd></div>
    <div><dt>Date</dt><dd>{{ $day($r['date']) }}</dd></div>
    <div><dt>How</dt><dd>{{ $r['how'] }}</dd></div>
    @if($r['reference'] !== '')<div><dt>Reference</dt><dd>{{ $r['reference'] }}</dd></div>@endif
    @if($r['received_by'] !== '')<div><dt>Received by</dt><dd>{{ $r['received_by'] }}</dd></div>@endif
  </dl>
  <dl>
    <div><dt>Pledged</dt><dd>{{ $m($r['pledged']) }}</dd></div>
    <div><dt>Paid so far, with this payment</dt><dd>{{ $m($r['paid_then']) }}</dd></div>
    <div class="b"><dt>Left to pay after this</dt><dd>{{ $m($r['left_then']) }}</dd></div>
  </dl>
  @if($r['footer'] !== '')<p class="foot">{{ $r['footer'] }}</p>@endif
  <div class="acts">
    <button type="button" onclick="window.print()">Print</button>
    <a href="{{ $pdf }}">Download PDF</a>
  </div>
</main>
</body>
</html>
