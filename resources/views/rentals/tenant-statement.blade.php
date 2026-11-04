{{-- A tenant's statement (the tenant_statement report): what is owed big at the top, then each bill and payment, the deposit, and how to pay. --}}
@extends('rentals.document')
@section('body')
  <table class="figs"><tr>
    <td style="width:50%"><div class="cap">{{ $big['label'] }}</div><div class="fig">{{ $big['value'] }}</div></td>
    <td><div class="cap">Tenant</div><div>{{ $big['tenant'] }}</div><div class="cap" style="margin-top:2px">{{ $big['where'] }}</div></td>
  </tr></table>
  @include('rentals.pdf-table', ['t' => $main])
  @foreach($sections as $t)
    @include('rentals.pdf-table', ['t' => $t])
  @endforeach
  @if($payTo !== '')
    <h2>How to pay</h2>
    <p>{!! nl2br(e($payTo)) !!}</p>
  @endif
@endsection
