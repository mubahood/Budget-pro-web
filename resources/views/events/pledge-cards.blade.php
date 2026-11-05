@extends('rentals.document')
@section('body')
<style>
  table.pc { width: 100%; border-collapse: collapse; page-break-inside: avoid; margin: 0 0 7px; }
  table.pc td.card { border: 0.6px solid #1e2b33; padding: 12px 14px; height: 204px; vertical-align: top; }
  .pc-event { font-size: 12px; font-weight: bold; margin: 0 0 12px; }
  table.pc-row { width: 100%; border-collapse: collapse; }
  table.pc-row td { padding: 10px 8px 1px 0; vertical-align: bottom; }
  .pc-k { font-size: 8px; color: #55606a; white-space: nowrap; width: 1%; }
  .pc-v { border-bottom: 0.5px solid #1e2b33; font-size: 10.5px; height: 18px; }
  .pc-tear { margin-top: 16px; padding-top: 8px; border-top: 0.6px dashed #55606a; font-size: 8px; color: #55606a; }
</style>
@foreach($cards as $n => $c)
  <table class="pc" @if($n > 0 && $n % 4 === 0) style="page-break-before:always" @endif><tr><td class="card">
    <p class="pc-event">{{ $event['name'] }}@if($event['date']) · {{ $day($event['date']) }}@endif</p>
    <table class="pc-row">
      <tr><td class="pc-k">Name</td><td class="pc-v" colspan="3">{{ $c['name'] }}</td></tr>
      <tr><td class="pc-k">Phone</td><td class="pc-v">{{ $c['phone'] }}</td><td class="pc-k">Group</td><td class="pc-v">{{ $c['group'] }}</td></tr>
      <tr><td class="pc-k">Amount ({{ $label }})</td><td class="pc-v">{{ $c['pledged'] === '' ? '' : $m($c['pledged']) }}</td><td class="pc-k">Signature</td><td class="pc-v"></td></tr>
    </table>
    <p class="pc-tear">Received by: ______________________________</p>
  </td></tr></table>
@endforeach
@if($cards === [])<p class="sum">No pledges yet: print blank cards instead.</p>@endif
@endsection
