@extends('rentals.document')
@section('body')
@php
  $e = $r['event'];
  $when = '';
  if ($e['date']) {
      $left = $e['days_left'];
      $when = ' The event '.(($left ?? 0) >= 0 ? 'is' : 'was').' on '.$day($e['date']).($left !== null && $left > 0 ? ', '.$left.($left === 1 ? ' day' : ' days').' from now' : '').'.';
  }
@endphp
<table class="figs"><tr>
  <td><div class="cap">In hand</div><div class="fig">{{ $m($e['in_hand']) }}</div></td>
  <td><div class="cap">Pledged</div><div class="fig" style="font-size:13px">{{ $m($e['pledged']) }}</div></td>
  <td><div class="cap">Collected</div><div class="fig" style="font-size:13px">{{ $m($e['paid']) }}</div></td>
  <td><div class="cap">Still owed</div><div class="fig" style="font-size:13px">{{ $m($e['owed']) }}</div></td>
  <td><div class="cap">Paid out</div><div class="fig" style="font-size:13px">{{ $m($e['spent']) }}</div></td>
</tr></table>
<p class="note">Amounts in {{ $label }}.{{ $when }} Budget {{ $m($e['budget']) }}. This week: {{ $m($r['week']['pledged']) }} pledged and {{ $m($r['week']['collected']) }} collected{{ \App\Support\Decimal::cmp($r['week']['paid_out'] ?? '0', '0') !== 0 ? ', '.$m($r['week']['paid_out']).' paid out' : '' }}.</p>

<table class="t whole">
  <thead><tr><th class="st" colspan="4">By group</th></tr><tr><th>Group</th><th class="n">Pledged</th><th class="n">Collected</th><th class="n">Still owed</th></tr></thead>
  <tbody>
    @forelse($r['groups'] as $g)
      <tr><td>{{ $g['label'] }} <span class="muted">{{ $g['percent'] === null ? '' : '· '.$g['percent'].'%' }}</span></td><td class="n">{{ $m($g['pledged']) }}</td><td class="n">{{ $m($g['paid']) }}</td><td class="n">{{ $m($g['owed']) }}</td></tr>
    @empty
      <tr><td colspan="4" class="empty">No pledges yet.</td></tr>
    @endforelse
    @if($r['groups'] !== [])<tr class="total"><td>Total</td><td class="n">{{ $m($e['pledged']) }}</td><td class="n">{{ $m($e['paid']) }}</td><td class="n">{{ $m($e['owed']) }}</td></tr>@endif
  </tbody>
</table>

<table style="width:100%;border-collapse:collapse"><tr>
  <td style="width:50%;padding-right:10px;vertical-align:top">
    <table class="t whole">
      <thead><tr><th class="st" colspan="2">How it came</th></tr></thead>
      <tbody>
        @forelse($r['methods'] as $x)<tr><td>{{ $x['label'] }}</td><td class="n">{{ $m($x['amount']) }}</td></tr>@empty<tr><td colspan="2" class="empty">Nothing yet.</td></tr>@endforelse
      </tbody>
    </table>
  </td>
  <td style="width:50%;vertical-align:top">
    <table class="t whole">
      <thead><tr><th class="st" colspan="2">Received by</th></tr></thead>
      <tbody>
        @forelse($r['receivers'] as $x)<tr><td>{{ $x['label'] }}</td><td class="n">{{ $m($x['amount']) }}</td></tr>@empty<tr><td colspan="2" class="empty">Nothing yet.</td></tr>@endforelse
      </tbody>
    </table>
  </td>
</tr></table>

<table class="t whole">
  <thead><tr><th class="st" colspan="2">Most still to pay</th></tr></thead>
  <tbody>
    @forelse($r['to_pay'] as $l)<tr><td>{{ $l['name'] }}</td><td class="n">{{ $m($l['left']) }}</td></tr>@empty<tr><td colspan="2" class="empty">Nothing left to pay on the budget.</td></tr>@endforelse
  </tbody>
</table>

@if($r['over'] !== [])
  <table class="t whole">
    <thead><tr><th class="st" colspan="2">Over budget</th></tr></thead>
    <tbody>@foreach($r['over'] as $l)<tr><td>{{ $l['name'] }}</td><td class="n">Over by {{ $m($l['over']) }}</td></tr>@endforeach</tbody>
  </table>
@endif

@if($r['names'] !== [])
  <table class="t">
    <thead><tr><th class="st" colspan="4">Who gave</th></tr><tr><th>Name</th><th class="n">Pledged</th><th class="n">Collected</th><th class="n">Still owed</th></tr></thead>
    <tbody>@foreach($r['names'] as $p)<tr><td>{{ $p['name'] }}</td><td class="n">{{ $m($p['pledged']) }}</td><td class="n">{{ $m($p['paid']) }}</td><td class="n">{{ $m($p['owed']) }}</td></tr>@endforeach</tbody>
  </table>
@endif

@include('events.partials.signatures', ['officers' => $r['officers']])
@if($footer !== '')<p class="note" style="margin-top:8px">{{ $footer }}</p>@endif
@endsection
