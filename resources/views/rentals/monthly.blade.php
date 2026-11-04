{{-- The monthly building report on one A4 page an owner can sign (03-improvements.md §9). --}}
@extends('rentals.document')
@section('body')
  <table class="figs"><tr>
    <td style="width:34%"><div class="cap">Collected</div><div class="fig">{{ $big['collected'] }}</div></td>
    @if($big['paid_out'] !== null)<td style="width:33%"><div class="cap">{{ $big['paid_out_label'] }}</div><div class="fig">{{ $big['paid_out'] }}</div></td>@endif
    <td><div class="cap">{{ $big['balance_label'] }}</div><div class="fig">{{ $big['balance'] }}</div></td>
  </tr></table>
  <p class="fignote">{{ $big['occupancy'] }}<br>{{ $big['arrears'] }}</p>
  @include('rentals.pdf-table', ['t' => $main])
  @foreach($sections as $t)
    @include('rentals.pdf-table', ['t' => $t])
  @endforeach
  <table class="sign"><tr><td><div class="line">Prepared by, name and date</div></td><td><div class="line">Checked by, name and date</div></td></tr></table>
@endsection
