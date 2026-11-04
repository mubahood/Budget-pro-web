{{-- A landlord's statement, the only one (LandlordAccounts::statement through the landlord_statement report): the balance first, how it came about, each line, what tenants still owe. --}}
@extends('rentals.document')
@section('body')
  <table class="figs"><tr>
    <td style="width:50%"><div class="cap">{{ $big['label'] }}</div><div class="fig">{{ $big['value'] }}</div></td>
    <td><div class="cap">Units</div><div class="fignote" style="margin:3px 0 0">{{ $big['units'] }}</div></td>
  </tr></table>
  <table class="kv whole">
    @foreach($money as [$label, $value, $strong])
      <tr @class(['b' => $strong])><td class="k">{{ $label }}</td><td class="m">{{ $value }}</td></tr>
    @endforeach
  </table>
  @include('rentals.pdf-table', ['t' => $main])
  @foreach($sections as $t)
    @include('rentals.pdf-table', ['t' => $t])
  @endforeach
@endsection
