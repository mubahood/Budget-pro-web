@extends('rentals.document')
@section('body')
@if($r['reversed_on'])<div class="box">REVERSED on {{ $day($r['reversed_on']) }}. This payment was cancelled and does not count.</div>@endif
<table class="figs"><tr><td><div class="cap">Amount received ({{ $label }})</div><div class="fig">{{ $m($r['amount']) }}</div></td></tr></table>
<p class="note">{{ $words }} {{ $label }}.</p>
<table class="kv">
  <tr><td class="k">Receipt number</td><td class="r">{{ $r['number'] }}</td></tr>
  <tr><td class="k">Received from</td><td class="r">{{ $r['from'] }}</td></tr>
  <tr><td class="k">For</td><td class="r">{{ $r['event'] }}</td></tr>
  <tr><td class="k">Date</td><td class="r">{{ $day($r['date']) }}</td></tr>
  <tr><td class="k">How</td><td class="r">{{ $r['how'] }}</td></tr>
  @if($r['reference'] !== '')<tr><td class="k">Reference</td><td class="r">{{ $r['reference'] }}</td></tr>@endif
  @if($r['received_by'] !== '')<tr><td class="k">Received by</td><td class="r">{{ $r['received_by'] }}</td></tr>@endif
</table>
<table class="kv">
  <tr><td class="k">Pledged</td><td class="m">{{ $m($r['pledged']) }}</td></tr>
  <tr><td class="k">Paid so far, with this payment</td><td class="m">{{ $m($r['paid_then']) }}</td></tr>
  <tr class="b"><td>Left to pay after this payment</td><td class="m">{{ $m($r['left_then']) }}</td></tr>
</table>
@if($r['footer'] !== '')<p class="note">{{ $r['footer'] }}</p>@endif
@endsection
