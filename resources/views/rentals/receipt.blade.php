{{-- A tenant's receipt (Estate Ledger, docs/estate-ledger/04-architecture.md §10.4): Payments\Receipt::parts(), as issued. On a till's 80 mm paper, or A5 for an office printer. --}}
@extends('rentals.document')
@section('body')
  <div style="text-align:{{ $doc['narrow'] ? 'center' : 'left' }};margin:{{ $doc['narrow'] ? '6px 0 6px' : '0 0 8px' }}">
    @if($doc['narrow'])<div class="doc" style="text-align:center;margin-bottom:5px">{{ $doc['title'] }}</div>@endif
    <div class="cap">{{ $amountLabel }}</div>
    <div class="fig">{{ $amount }}</div>
    @if(! empty($p['words']))<div class="cap">{{ $p['words'] }}</div>@endif
  </div>
  @if($p['reversed'])<div class="box">REVERSED · {{ $p['reversed'] }}</div>@endif
  <table class="kv">
    @foreach($lines as [$label, $value, $mono])<tr><td class="k">{{ $label }}</td><td class="{{ $mono ? 'm' : 'r' }}">{{ $value }}</td></tr>@endforeach
  </table>
  @if($settled !== [])
    <h2>Settled</h2>
    <table class="kv">
      @foreach($settled as [$label, $value, $mono])<tr><td>{{ $label }}</td><td class="{{ $mono ? 'm' : 'r' }}">{{ $value }}</td></tr>@endforeach
    </table>
  @endif
  <table class="kv">
    @foreach($after as [$label, $value, $mono])<tr><td class="k">{{ $label }}</td><td class="{{ $mono ? 'm' : 'r' }}">{{ $value }}</td></tr>@endforeach
  </table>
  <div style="text-align:center">@foreach($p['footer'] as $f)<div class="cap">{{ $f }}</div>@endforeach</div>
@endsection
