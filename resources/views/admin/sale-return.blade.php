@php
    $lines = \App\Models\SaleRecordItem::withoutGlobalScopes()->where('sale_record_id', $sale->id)->get();
    $returns = \App\Models\SaleReturn::withoutGlobalScopes()->where('sale_record_id', $sale->id)->where('is_deleted', 0)->with('items')->orderByDesc('id')->get();
    $open = $lines->filter(fn ($l) => (float) $l->quantity - (float) $l->returned_quantity > 0);
@endphp
@foreach($returns as $r)
    <div><small>{{ $r->created_at }} · returned value <strong>{{ number_format((float) $r->value) }}</strong>@if($r->reason) · {{ $r->reason }}@endif
        · {{ $r->items->where('restock', true)->sum('quantity') + 0 }} back to stock, {{ $r->items->where('restock', false)->sum('quantity') + 0 }} faulty</small></div>
@endforeach
@if($sale->voided_at)
    <small class="text-muted">This sale was voided.</small>
@elseif($open->isEmpty())
    <small class="text-muted">Everything on this sale has been returned.</small>
@else
    <form method="post" action="{{ admin_url('sale-records/'.$sale->id.'/return') }}" onsubmit="return confirm('Record this return?')">@csrf
        <table class="table table-condensed" style="margin-bottom:6px">
            <tr><th>Item</th><th>Sold</th><th>Can return</th><th>Return qty</th><th>Condition</th></tr>
            @foreach($open as $l)
                <tr>
                    <td>{{ $l->item_name }}</td>
                    <td>{{ (float) $l->quantity + 0 }}</td>
                    <td>{{ (float) $l->quantity - (float) $l->returned_quantity + 0 }}</td>
                    <td><input type="number" step="any" min="0" max="{{ (float) $l->quantity - (float) $l->returned_quantity }}" name="lines[{{ $l->id }}][quantity]" class="form-control input-sm" style="width:90px" value="0"></td>
                    <td><select name="lines[{{ $l->id }}][condition]" class="form-control input-sm">
                        <option value="good">Good — back to stock</option>
                        <option value="faulty">Faulty / damaged — don't restock</option>
                    </select></td>
                </tr>
            @endforeach
        </table>
        <div class="form-inline">
            <input name="reason" class="form-control input-sm" placeholder="Reason (e.g. faulty, wrong item)" style="width:260px">
            <select name="refund_method" class="form-control input-sm"><option value="cash">Refund cash</option><option value="mobile_money">Refund mobile money</option></select>
            <button class="btn btn-sm btn-warning"><i class="fa fa-undo"></i> Record return</button>
        </div>
    </form>
@endif
