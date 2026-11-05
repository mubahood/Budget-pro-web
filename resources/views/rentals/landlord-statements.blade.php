{{-- A closed month's statements on one sheet, for printing (StatementPdf::renderMany): a cover with the by-landlord table for the office file, then one statement to a page. --}}
@extends('rentals.document')
@section('body')
  @if($cover)@include('rentals.pdf-table', ['t' => $cover])@endif
  @foreach($statements as $i => $st)
    <div @if($cover || $i > 0) style="page-break-before: always" @endif>
      <h2>{{ $st['subtitle'] }}</h2>
      @include('rentals.partials.statement-body', ['st' => $st])
    </div>
  @endforeach
@endsection
