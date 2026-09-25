@php
    $company = \App\Models\Company::withoutGlobalScopes()->find($sale->company_id);
    $requests = \Illuminate\Support\Facades\DB::table('momo_requests')->where('sale_record_id', $sale->id)->orderByDesc('id')->limit(3)->get();
@endphp
<div class="row">
    <div class="col-sm-6">
        <form method="post" action="{{ admin_url('sale-records/'.$sale->id.'/send-receipt') }}" class="form-inline">@csrf
            <input class="form-control input-sm" name="phone" value="{{ $sale->customer_phone }}" placeholder="Customer phone">
            <button class="btn btn-sm btn-success"><i class="fa fa-whatsapp"></i> Send receipt</button>
        </form>
        @if($sale->receipt_sent_at)<small class="text-muted">Sent {{ $sale->receipt_sent_at }}</small>@endif
    </div>
    <div class="col-sm-6">
        @if((float) $sale->balance > 0 && ! $sale->voided_at)
            @if($company?->momo_subaccount_id)
                <form method="post" action="{{ admin_url('sale-records/'.$sale->id.'/momo-request') }}" class="form-inline">@csrf
                    <input class="form-control input-sm" name="phone" value="{{ $sale->customer_phone }}" placeholder="Mobile money number" required>
                    <select name="network" class="form-control input-sm">@foreach(app(\App\Services\Engage\MomoCollections::class)->networks($company) as $n)<option>{{ $n }}</option>@endforeach</select>
                    <button class="btn btn-sm btn-primary">Request {{ number_format((float) $sale->balance) }}</button>
                </form>
            @else
                <a href="{{ admin_url('engagement') }}">Set up mobile money to request payment</a>
            @endif
            @foreach($requests as $r)<div><small>{{ $r->phone }} · {{ number_format((float) $r->amount) }} · <strong>{{ $r->status }}</strong></small></div>@endforeach
        @endif
    </div>
</div>
