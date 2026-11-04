{{-- A report on paper (RentalExport): a one-line summary, its table and its smaller tables. --}}
@extends('rentals.document')
@section('body')
  @if($summary !== [])<p class="sum">{!! implode(' · ', array_map(fn ($s) => e($s), $summary)) !!}</p>@endif
  @include('rentals.pdf-table', ['t' => $main])
  @foreach($sections as $t)
    @include('rentals.pdf-table', ['t' => $t])
  @endforeach
@endsection
