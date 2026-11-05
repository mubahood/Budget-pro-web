{{-- A landlord's statement, the only one (the landlord_statement report, RentalExport::statementData). A closed month prints its issued copy (StatementBook). --}}
@extends('rentals.document')
@section('body')
  @include('rentals.partials.statement-body', ['st' => $st])
@endsection
