@php use App\Support\Money; $open = in_array($po->status, ['draft', 'sent', 'partially_received'], true); @endphp
<div class="row">
    <div class="col-md-7">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">{{ $po->number }} · {{ $po->supplier?->name ?? 'No supplier' }}</h3>
                <span class="label label-{{ ['draft' => 'default', 'sent' => 'info', 'partially_received' => 'warning', 'received' => 'success', 'cancelled' => 'danger'][$po->status] ?? 'default' }} pull-right">{{ ucfirst(str_replace('_', ' ', $po->status)) }}</span>
            </div>
            <div class="box-body">
                <form method="post" action="{{ admin_url('purchase-orders/'.$po->id.'/receive') }}">@csrf
                <table class="table table-condensed">
                    <tr><th>Product</th><th class="text-right">Ordered</th><th class="text-right">Received</th><th class="text-right">Cost</th>@if($open)<th>Arrived now</th><th>Actual cost</th>@endif</tr>
                    @foreach($progress['lines'] as $l)
                        <tr>
                            <td>{{ $l['name'] }}</td>
                            <td class="text-right">{{ rtrim(rtrim(number_format($l['ordered'], 3, '.', ''), '0'), '.') }}</td>
                            <td class="text-right">{{ rtrim(rtrim(number_format($l['received'], 3, '.', ''), '0'), '.') }}</td>
                            <td class="text-right">{{ Money::format($l['unit_cost']) }}</td>
                            @if($open)
                                <td><input type="number" step="any" min="0" class="form-control input-sm" name="received[{{ $l['id'] }}][quantity]" value="{{ $l['outstanding'] > 0 ? rtrim(rtrim(number_format($l['outstanding'], 3, '.', ''), '0'), '.') : 0 }}"></td>
                                <td><input type="number" step="any" min="0" class="form-control input-sm" name="received[{{ $l['id'] }}][unit_cost]" value="{{ $l['unit_cost'] }}"></td>
                            @endif
                        </tr>
                    @endforeach
                    <tr><th>Total</th><th></th><th></th><th class="text-right">{{ Money::format($po->subtotal) }}</th>@if($open)<th colspan="2"></th>@endif</tr>
                </table>
                @if($open)
                    <div class="row">
                        <div class="col-sm-4"><label>Supplier invoice no.</label><input class="form-control" name="invoice_ref"></div>
                        <div class="col-sm-4"><label>Paid now</label><input type="number" step="any" min="0" class="form-control" name="amount_paid" value="0"></div>
                        <div class="col-sm-4"><label>Paid with</label><select class="form-control" name="payment_method"><option value="cash">Cash</option><option value="mobile_money">Mobile money</option><option value="bank">Bank</option></select></div>
                    </div>
                    <p style="margin-top:10px"><button class="btn btn-success"><i class="fa fa-download"></i> Receive these quantities</button> <small class="text-muted">Partial deliveries are fine; the order stays open for the rest.</small></p>
                @endif
                </form>
                @if($progress['cost_variance'] != 0)
                    <p class="{{ $progress['cost_variance'] > 0 ? 'text-danger' : 'text-success' }}">Cost difference against the order: {{ Money::format($progress['cost_variance']) }}</p>
                @endif
            </div>
        </div>
        @if(count($progress['receipts']))
            <div class="box"><div class="box-header"><h3 class="box-title">Deliveries</h3></div><div class="box-body no-padding"><table class="table">
                @foreach($progress['receipts'] as $g)
                    <tr><td><a href="{{ admin_url('goods-receipts/'.$g->id) }}">{{ $g->number }}</a></td><td>{{ substr((string) $g->received_on, 0, 10) }}</td><td class="text-right">{{ Money::format($g->total_cost) }}</td><td class="text-right">paid {{ Money::format($g->amount_paid) }}</td></tr>
                @endforeach
            </table></div></div>
        @endif
    </div>
    <div class="col-md-5">
        <div class="box">
            <div class="box-header"><h3 class="box-title">Send to supplier</h3></div>
            <div class="box-body">
                <pre style="white-space:pre-wrap">{{ $text }}</pre>
                @if(in_array($po->status, ['draft', 'sent'], true))
                    <form method="post" action="{{ admin_url('purchase-orders/'.$po->id.'/send') }}" style="display:inline">@csrf<button class="btn btn-success"><i class="fa fa-whatsapp"></i> Open in WhatsApp</button></form>
                    <form method="post" action="{{ admin_url('purchase-orders/'.$po->id.'/send') }}" style="display:inline">@csrf<input type="hidden" name="via_api" value="1"><button class="btn btn-default">Send by SMS/WhatsApp for me</button></form>
                @endif
            </div>
        </div>
        @if($open)
            <form method="post" action="{{ admin_url('purchase-orders/'.$po->id.'/cancel') }}" onsubmit="return confirm('Close this order? Goods already received stay received.')">@csrf<button class="btn btn-link text-danger">{{ $po->status === 'partially_received' ? 'Close — nothing more is coming' : 'Cancel order' }}</button></form>
        @endif
    </div>
</div>
