@php
    use App\Admin\Controllers\SaleRecordController as S;
    use App\Support\Money;
    $voided = $sale->voided_at !== null;
    $balance = (float) $sale->balance;
    $refunded = (float) $sale->refunded_amount;
    $payColor = ['Paid' => 'success', 'Partial' => 'warning', 'Unpaid' => 'danger'][$sale->payment_status] ?? 'default';
    $payText = ['Paid' => 'Paid', 'Partial' => 'Part paid', 'Unpaid' => 'Not paid'][$sale->payment_status] ?? (string) $sale->payment_status;
@endphp
<style>
    .sale-receipt .table > tbody > tr > td, .sale-receipt .table > thead > tr > th { padding: 6px 8px; }
    .sale-receipt .totals td { border-top: 0 !important; padding: 3px 8px !important; }
    .sale-actions .box-body form { margin-bottom: 0; }
    .sale-actions .btn-block + .btn-block { margin-top: 6px; }
    @media (max-width: 767px) { .hide-xs { display: none; } }
</style>

<div class="row">
    {{-- Receipt-like summary --}}
    <div class="col-md-7">
        <div class="box box-{{ $voided ? 'default' : 'primary' }} sale-receipt">
            <div class="box-header with-border">
                <h3 class="box-title">Receipt {{ $sale->receipt_number ?: '#'.$sale->id }}</h3>
                <div class="box-tools" style="top:8px">
                    {!! S::statusLabel((string) $sale->status) !!}
                    @unless($voided)<span class="label label-{{ $payColor }}">{{ $payText }}</span>@endunless
                </div>
            </div>
            <div class="box-body">
                <div class="row" style="margin-bottom:8px">
                    <div class="col-xs-6">
                        <div class="text-muted small">Customer</div>
                        @if($customer)
                            <a href="{{ admin_url('customers/'.$customer->id) }}"><strong>{{ $sale->customer_name ?: $customer->name }}</strong></a>
                        @else
                            <strong>{{ $sale->customer_name ?: 'Walk-in' }}</strong>
                        @endif
                        @if($sale->customer_phone)<div>{{ $sale->customer_phone }}</div>@endif
                        @if($customer && (float) $customer->balance > 0)<div class="small text-danger">Owes {{ Money::format($customer->balance) }} in total</div>@endif
                    </div>
                    <div class="col-xs-6 text-right">
                        <div class="text-muted small">Date</div>
                        <strong>{{ $sale->sale_date?->format('d M Y') }}</strong>
                        <div class="small text-muted">{{ S::methodLabel($sale->payment_method) }}@if($users->get($sale->created_by_id)) · sold by {{ $users->get($sale->created_by_id) }}@endif</div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped" style="margin-bottom:6px">
                        <thead><tr><th>Item</th><th class="text-right">Qty</th><th class="text-right hide-xs">Price</th><th class="text-right">Total</th></tr></thead>
                        <tbody>
                        @foreach($items as $item)
                            <tr>
                                <td>{{ $item->item_name }}
                                    @if((float) $item->discount_amount > 0)<div class="small text-muted">Discount {{ Money::format($item->discount_amount) }}</div>@endif
                                    @if((float) $item->returned_quantity > 0)<div class="small text-warning">{{ S::qty((float) $item->returned_quantity) }} returned</div>@endif
                                </td>
                                <td class="text-right">{{ S::qty((float) $item->quantity) }}</td>
                                <td class="text-right hide-xs">{{ Money::format($item->unit_price) }}</td>
                                <td class="text-right">{{ Money::format($item->line_total ?? $item->subtotal) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <table class="table totals" style="margin-bottom:0">
                    @if((float) $sale->discount_amount > 0)
                        <tr><td>Before discount</td><td class="text-right">{{ Money::format($sale->subtotal) }}</td></tr>
                        <tr><td>Discount</td><td class="text-right">− {{ Money::format($sale->discount_amount) }}</td></tr>
                    @endif
                    <tr style="font-size:17px"><td><strong>Total</strong></td><td class="text-right"><strong>{{ Money::format($sale->total_amount) }}</strong></td></tr>
                    @if($refunded > 0)
                        <tr><td>Returned goods</td><td class="text-right">− {{ Money::format($refunded) }}</td></tr>
                    @endif
                    <tr><td>Paid</td><td class="text-right">{{ Money::format($sale->amount_paid) }}</td></tr>
                    @if((float) $sale->change_given > 0)
                        <tr><td>Change given</td><td class="text-right">{{ Money::format($sale->change_given) }}</td></tr>
                    @endif
                    @unless($voided)
                        <tr style="font-size:16px"><td><strong>Balance</strong></td>
                            <td class="text-right"><strong class="{{ $balance > 0 ? 'text-danger' : 'text-success' }}">{{ $balance > 0 ? Money::format($balance) : 'Fully paid' }}</strong></td></tr>
                    @endunless
                    @if($canProfit && ! $voided)
                        <tr class="text-muted"><td>Profit</td><td class="text-right">{{ Money::format($profit) }}</td></tr>
                    @endif
                </table>

                @if($voided)
                    <div class="alert alert-warning" style="margin:10px 0 0">
                        Voided {{ $sale->voided_at?->format('d M Y H:i') }}@if($users->get($sale->voided_by_id)) by {{ $users->get($sale->voided_by_id) }}@endif.
                        @if($sale->voided_reason)Reason: {{ $sale->voided_reason }}.@endif
                        Stock and money were reversed; this sale no longer counts.
                    </div>
                @endif
                @if($sale->notes)<p class="text-muted" style="margin:10px 0 0">{{ $sale->notes }}</p>@endif
            </div>
            <div class="box-footer">
                <a href="{{ url('sale-receipt-pdf?id='.$sale->id) }}" target="_blank" class="btn btn-default"><i class="fa fa-print"></i> Print receipt</a>
                <a href="{{ url('sale-invoice-pdf?id='.$sale->id) }}" target="_blank" class="btn btn-default hide-xs"><i class="fa fa-file-text-o"></i> Invoice</a>
                <a href="#send-receipt" class="btn btn-default"><i class="fa fa-whatsapp"></i> Send on WhatsApp</a>
                <a href="{{ admin_url('sale-records/create') }}" class="btn btn-success"><i class="fa fa-plus"></i> New sale</a>
                <a href="{{ admin_url('sale-records/'.$sale->id.'/edit') }}" class="btn btn-link">Edit customer / notes</a>
            </div>
        </div>

        {{-- Payments --}}
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Payments</h3></div>
            <div class="box-body table-responsive no-padding">
                @if($payments->isEmpty())
                    <p class="text-muted" style="padding:10px">No money received yet.</p>
                @else
                    <table class="table table-condensed">
                        <tr><th>When</th><th>How</th><th class="text-right">Amount</th><th class="hide-xs">By</th><th></th></tr>
                        @foreach($payments as $p)
                            @php $isReversed = in_array((int) $p->id, $reversed, true); @endphp
                            <tr class="{{ $p->is_reversal || $isReversed ? 'text-muted' : '' }}">
                                <td>{{ $p->received_at?->format('d M Y H:i') }}</td>
                                <td>{{ S::methodLabel($p->method) }}@if($p->reference)<div class="small">Ref {{ $p->reference }}</div>@endif
                                    @if($p->is_reversal)<div class="small">Reversal</div>@elseif((float) $p->amount < 0)<div class="small">Refund</div>@endif
                                </td>
                                <td class="text-right">{{ Money::format($p->amount) }}</td>
                                <td class="hide-xs">{{ $users->get($p->received_by_id) }}</td>
                                <td class="text-right">
                                    @if($isReversed)
                                        <span class="label label-default">Reversed</span>
                                    @elseif($canVoid && ! $voided && ! $p->is_reversal && (float) $p->amount > 0)
                                        <form method="post" action="{{ admin_url('sale-records/'.$sale->id.'/payments/'.$p->id.'/reverse') }}" class="form-inline" style="display:inline"
                                              onsubmit="var r = prompt('Why reverse this payment of {{ Money::format($p->amount) }}?'); if (r === null) return false; this.reason.value = r; return true;">@csrf
                                            <input type="hidden" name="reason" value="">
                                            <button class="btn btn-xs btn-default"><i class="fa fa-undo"></i> Reverse</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </div>
        </div>
    </div>

    {{-- Actions --}}
    <div class="col-md-5 sale-actions">
        @if(! $voided && $balance > 0 && $canSell)
            <div class="box box-success">
                <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-money"></i> Receive payment</h3></div>
                <div class="box-body">
                    <form method="post" action="{{ admin_url('sale-records/'.$sale->id.'/payments') }}">@csrf
                        <div class="form-group">
                            <label>Amount (owed {{ Money::format($balance) }})</label>
                            <input type="number" name="amount" class="form-control" min="1" step="any" value="{{ round($balance, 2) + 0 }}" required>
                        </div>
                        <div class="row">
                            <div class="col-xs-6 form-group">
                                <label>Paid by</label>
                                <select name="method" class="form-control">
                                    @foreach($methods as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-xs-6 form-group">
                                <label>Reference <small class="text-muted">(optional)</small></label>
                                <input name="reference" class="form-control" maxlength="191" placeholder="e.g. MoMo ID">
                            </div>
                        </div>
                        <button class="btn btn-success btn-block"><i class="fa fa-check"></i> Record payment</button>
                    </form>
                </div>
            </div>
        @endif

        <div class="box box-default" id="send-receipt">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-whatsapp"></i> Send receipt{{ ! $voided && $balance > 0 ? ' / request mobile money' : '' }}</h3></div>
            <div class="box-body">@include('admin.sale-engage', ['sale' => $sale])</div>
        </div>

        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-undo"></i> Return items</h3></div>
            <div class="box-body">@include('admin.sale-return', ['sale' => $sale])</div>
        </div>

        @if(! $voided && $canVoid)
            <div class="box box-danger">
                <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-ban"></i> Void this sale</h3></div>
                <div class="box-body">
                    <p class="small text-muted">For a sale recorded by mistake: the stock goes back and the money is reversed. The sale stays on record as voided.</p>
                    <form method="post" action="{{ admin_url('sale-records/'.$sale->id.'/void') }}" onsubmit="return confirm('Void sale {{ $sale->receipt_number }}? Stock and money will be reversed.')">@csrf
                        <div class="form-group"><input name="reason" class="form-control" maxlength="191" placeholder="Reason (e.g. entered twice)" required></div>
                        <button class="btn btn-danger btn-block">Void sale</button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</div>
