@extends('rentals.document')
@section('body')
<p class="note">{{ count($p['rows']) }} {{ count($p['rows']) === 1 ? 'entry' : 'entries' }}, in date order. Amounts in {{ $label }}. A mistake is put right with a reversal, never an edit.</p>
@foreach(array_chunk($p['rows'], 30) as $i => $rows)
  <table class="t" style="table-layout:fixed;margin-bottom:0">
    <thead><tr><th style="width:11%">Date</th><th style="width:17%">Receipt</th><th style="width:25%">Name</th><th class="n" style="width:13%">Amount</th><th style="width:14%">How</th><th style="width:20%">Received by</th></tr></thead>
    <tbody>
      @foreach($rows as $r)
        <tr><td class="nw">{{ $day($r['date']) }}</td><td>{{ $r['receipt'] }}</td><td>{{ $r['name'] }}@if($r['state'] !== '')<br><span class="muted">{{ $r['state'] }}</span>@endif</td><td class="n">{{ $m($r['amount']) }}</td><td>{{ $r['how'] }}@if($r['reference'] !== '')<br><span class="muted">{{ $r['reference'] }}</span>@endif</td><td>{{ $r['received_by'] }}</td></tr>
      @endforeach
    </tbody>
  </table>
@endforeach
@if($p['rows'] === [])<p class="sum">No payments yet.</p>@endif
<table class="t whole" style="table-layout:fixed"><tbody><tr class="total"><td style="width:53%">Total</td><td class="n" style="width:13%">{{ $m($p['total']) }}</td><td style="width:34%"></td></tr></tbody></table>
@if($footer !== '')<p class="note">{{ $footer }}</p>@endif
@endsection
