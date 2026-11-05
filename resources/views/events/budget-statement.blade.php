@extends('rentals.document')
@section('body')
<table class="figs"><tr>
  <td><div class="cap">Budget</div><div class="fig" style="font-size:13px">{{ $m($b['agreed']) }}</div></td>
  <td><div class="cap">Paid out</div><div class="fig" style="font-size:13px">{{ $m($b['paid']) }}</div></td>
  <td><div class="cap">Still to pay</div><div class="fig" style="font-size:13px">{{ $m($b['left']) }}</div></td>
  <td><div class="cap">Collected</div><div class="fig" style="font-size:13px">{{ $m($event['paid']) }}</div></td>
  <td><div class="cap">In hand</div><div class="fig" style="font-size:13px">{{ $m($event['in_hand']) }}</div></td>
</tr></table>
<p class="note">Amounts in {{ $label }}. @if($b['percent'] !== null){{ $b['percent'] }}% of the budget has been paid out.@endif</p>
@if($b['lines'] === 0)
  <p class="sum">No budget lines yet.</p>
@else
  <table class="t">
    <thead><tr><th>Line</th><th class="n" style="width:17%">Agreed</th><th class="n" style="width:17%">Paid out</th><th class="n" style="width:17%">Still to pay</th><th class="n" style="width:8%">Paid</th></tr></thead>
    <tbody>
      @foreach($b['categories'] as $c)
        <tr class="group keep"><td colspan="5">{{ $c['name'] }}</td></tr>
        @forelse($c['lines'] as $l)
          <tr>
            <td>{{ $l['name'] }}@if(\App\Support\Decimal::cmp($l['over'], '0') > 0)<br><b>Over by {{ $label }} {{ $m($l['over']) }}</b>@endif</td>
            <td class="n">{{ $m($l['agreed']) }}</td><td class="n">{{ $m($l['paid']) }}</td><td class="n">{{ $m($l['left']) }}</td><td class="n">{{ $l['percent'] === null ? '' : $l['percent'].'%' }}</td>
          </tr>
        @empty
          <tr><td colspan="5" class="muted">No lines yet.</td></tr>
        @endforelse
        <tr class="sub"><td>{{ $c['name'] }}: total</td><td class="n">{{ $m($c['agreed']) }}</td><td class="n">{{ $m($c['paid']) }}</td><td class="n">{{ $m($c['left']) }}</td><td class="n">{{ $c['percent'] === null ? '' : $c['percent'].'%' }}</td></tr>
      @endforeach
      <tr class="total"><td>Total</td><td class="n">{{ $m($b['agreed']) }}</td><td class="n">{{ $m($b['paid']) }}</td><td class="n">{{ $m($b['left']) }}</td><td class="n">{{ $b['percent'] === null ? '' : $b['percent'].'%' }}</td></tr>
    </tbody>
  </table>
@endif
@if(($b['payments']['rows'] ?? []) !== [])
  <p class="sum">Payments out, by date</p>
  <table class="t">
    <thead><tr><th style="width:11%">Date</th><th>Line</th><th>Paid to</th><th style="width:11%">How</th><th style="width:16%">Approved by</th><th class="n" style="width:15%">Amount</th></tr></thead>
    <tbody>
      @foreach($b['payments']['rows'] as $p)
        <tr>
          <td>{{ \Illuminate\Support\Carbon::parse($p['date'])->format('j M Y') }}</td>
          <td>{{ $p['line'] }}@if($p['state'] !== '')<br><span class="muted">{{ $p['state'] }}</span>@endif</td>
          <td>{{ $p['paid_to'] }}</td><td>{{ $p['how'] }}</td><td>{{ $p['approved_by'] }}</td><td class="n">{{ $m($p['amount']) }}</td>
        </tr>
      @endforeach
      <tr class="total"><td colspan="5">Total paid out</td><td class="n">{{ $m($b['payments']['total']) }}</td></tr>
    </tbody>
  </table>
@endif
@if($footer !== '')<p class="note">{{ $footer }}</p>@endif
@endsection
