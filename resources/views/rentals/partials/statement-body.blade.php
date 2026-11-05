{{-- One landlord's statement on paper (RentalExport::statementData), alone or among many: the balance first, how it came about, unit by unit, each line, expenses, what was paid, what is held and corrections. A section with nothing is not drawn. --}}
@if(! empty($st['stamp']))<div class="box">{{ $st['stamp'] }}</div>@endif
<table class="figs"><tr>
  <td style="width:40%"><div class="cap">{{ $st['big']['label'] }}</div><div class="fig">{{ $st['big']['value'] }}</div></td>
  <td style="width:30%"><div class="cap">Units</div><div class="fignote" style="margin:3px 0 0">{{ $st['big']['units'] }}</div></td>
  @if(! empty($st['number']))<td><div class="cap">Statement</div><div class="fignote" style="margin:3px 0 0">{{ $st['number'] }}@if(! empty($st['issued'])) · issued {{ $st['issued'] }}@endif</div></td>@endif
</tr></table>
@if(! empty($st['replaces']))
  <table class="kv whole">
    <tr class="b"><td class="k">Replaces {{ $st['replaces']['number'] }}</td><td class="m">Before</td><td class="m">Now</td></tr>
    @foreach($st['replaces']['rows'] as [$what, $before, $after])
      <tr><td class="k">{{ $what }}</td><td class="m">{{ $before }}</td><td class="m">{{ $after }}</td></tr>
    @endforeach
  </table>
@endif
<table class="kv whole">
  @foreach($st['money'] as [$label, $value, $strong])
    <tr @class(['b' => $strong])><td class="k">{{ $label }}</td><td class="m">{{ $value }}</td></tr>
  @endforeach
</table>
@if($st['units'])@include('rentals.pdf-table', ['t' => $st['units']])@endif
@include('rentals.pdf-table', ['t' => $st['main']])
@if($st['expenses'])@include('rentals.pdf-table', ['t' => $st['expenses']])@endif
@if($st['payouts'])@include('rentals.pdf-table', ['t' => $st['payouts']])@endif
@if($st['held'])
  @include('rentals.pdf-table', ['t' => $st['held']])
  <p class="note">{{ $st['heldNote'] }}</p>
@endif
@if($st['corrections'])@include('rentals.pdf-table', ['t' => $st['corrections']])@endif
@if($st['owes'])@include('rentals.pdf-table', ['t' => $st['owes']])@endif
