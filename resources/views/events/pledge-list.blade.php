@extends('rentals.document')
@section('body')
@php $t = $p['totals']; @endphp
<p class="note">{{ $t['count'] }} {{ $t['count'] === 1 ? 'pledge' : 'pledges' }}@if($note !== '') in {{ $note }}@endif. Amounts in {{ $label }}.</p>
@foreach(array_chunk($p['rows'], 30) as $i => $rows)
  <table class="t" style="table-layout:fixed;margin-bottom:0">
    <thead><tr><th style="width:30%">Name</th><th style="width:15%">Phone</th><th style="width:16%">Group</th><th class="n" style="width:13%">Pledged</th><th class="n" style="width:13%">Paid</th><th class="n" style="width:13%">Balance</th></tr></thead>
    <tbody>
      @foreach($rows as $r)
        <tr><td>{{ $r['name'] }}</td><td class="nw">{{ \App\Services\Budget\EventExport::localPhone($r['phone']) }}</td><td>{{ $r['group'] === 'No group' ? '' : $r['group'] }}</td><td class="n">{{ $m($r['pledged']) }}</td><td class="n">{{ $m($r['paid']) }}</td><td class="n">{{ $m($r['owed']) }}</td></tr>
      @endforeach
    </tbody>
  </table>
@endforeach
@if($p['rows'] === [])<p class="sum">No pledges to list.</p>@endif
<table class="t whole" style="table-layout:fixed">
  <tbody><tr class="total"><td style="width:61%">Total</td><td class="n" style="width:13%">{{ $m($t['pledged']) }}</td><td class="n" style="width:13%">{{ $m($t['paid']) }}</td><td class="n" style="width:13%">{{ $m($t['owed']) }}</td></tr></tbody>
</table>
@if($footer !== '')<p class="note">{{ $footer }}</p>@endif
@endsection
