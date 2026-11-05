@extends('rentals.document')
@section('body')
@php $e = $r['event']; $b = $r['budget']; @endphp
<table class="figs"><tr>
  <td><div class="cap">Collected</div><div class="fig" style="font-size:13px">{{ $m($e['paid']) }}</div></td>
  <td><div class="cap">Paid out</div><div class="fig" style="font-size:13px">{{ $m($e['spent']) }}</div></td>
  <td><div class="cap">Balance in hand</div><div class="fig">{{ $m($e['in_hand']) }}</div></td>
</tr></table>
<p class="note">Amounts in {{ $label }}.@if($e['date']) The event {{ ($e['days_left'] ?? 0) > 0 ? 'is' : 'was' }} on {{ $day($e['date']) }}.@endif</p>

<table class="t whole">
  <thead><tr><th class="st" colspan="3">Income by group</th></tr><tr><th>Group</th><th class="n">Pledged</th><th class="n">Collected</th></tr></thead>
  <tbody>
    @forelse($r['groups'] as $g)<tr><td>{{ $g['label'] }}</td><td class="n">{{ $m($g['pledged']) }}</td><td class="n">{{ $m($g['paid']) }}</td></tr>@empty<tr><td colspan="3" class="empty">No pledges.</td></tr>@endforelse
    <tr class="total"><td>Total</td><td class="n">{{ $m($e['pledged']) }}</td><td class="n">{{ $m($e['paid']) }}</td></tr>
  </tbody>
</table>

<table class="t whole">
  <thead><tr><th class="st" colspan="3">Spending by category</th></tr><tr><th>Category</th><th class="n">Agreed</th><th class="n">Paid out</th></tr></thead>
  <tbody>
    @forelse($b['categories'] as $c)<tr><td>{{ $c['name'] }}</td><td class="n">{{ $m($c['agreed']) }}</td><td class="n">{{ $m($c['paid']) }}</td></tr>@empty<tr><td colspan="3" class="empty">No budget lines.</td></tr>@endforelse
    <tr class="total"><td>Total</td><td class="n">{{ $m($b['agreed']) }}</td><td class="n">{{ $m($b['paid']) }}</td></tr>
  </tbody>
</table>

<table class="kv">
  <tr><td class="k">Pledges still unpaid</td><td class="m">{{ $m($e['owed']) }}</td></tr>
  <tr><td class="k">Still to pay on the budget</td><td class="m">{{ $m($b['left']) }}</td></tr>
  <tr class="b"><td>Balance in hand</td><td class="m">{{ $m($e['in_hand']) }}</td></tr>
  <tr><td class="k">Held by</td><td class="r">{{ $r['held_by'] !== '' ? $r['held_by'] : 'Not recorded yet' }}</td></tr>
</table>
@if($r['note'] !== '')<p class="note">{{ $r['note'] }}</p>@endif
@include('events.partials.signatures', ['officers' => $r['officers']])
@if($footer !== '')<p class="note" style="margin-top:8px">{{ $footer }}</p>@endif
@endsection
