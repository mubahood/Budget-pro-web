@php use App\Support\Money; @endphp
<div class="box box-primary">
    <div class="box-body">
        @if(empty($rows))
            <p class="text-muted">Nothing needs reordering — every product is above its minimum. Set a minimum on a product to get it on this list.</p>
        @else
        <form method="post" action="{{ admin_url('reorder-suggestions/orders') }}">@csrf
            <table class="table table-condensed">
                <tr><th><input type="checkbox" onclick="document.querySelectorAll('.pick').forEach(c => c.checked = this.checked)"></th><th>Product</th><th class="text-right">On hand</th><th class="text-right">Minimum</th><th class="text-right">Sold (30 days)</th><th>Order</th><th>Supplier</th><th>Why</th></tr>
                @foreach($rows as $r)
                    <tr>
                        <td><input class="pick" type="checkbox" name="items[{{ $r['stock_item_id'] }}][pick]" value="1" checked></td>
                        <td>{{ $r['name'] }}@if($r['runs_out_in_days'] !== null)<br><small class="text-danger">runs out in about {{ $r['runs_out_in_days'] }} day(s)</small>@endif</td>
                        <td class="text-right">{{ rtrim(rtrim(number_format($r['on_hand'], 3, '.', ''), '0'), '.') }}</td>
                        <td class="text-right">{{ rtrim(rtrim(number_format($r['min_stock'], 3, '.', ''), '0'), '.') }}</td>
                        <td class="text-right">{{ rtrim(rtrim(number_format($r['sold_30'], 3, '.', ''), '0'), '.') }}</td>
                        <td style="width:110px"><input type="number" step="any" min="0" class="form-control input-sm" name="items[{{ $r['stock_item_id'] }}][quantity]" value="{{ $r['suggested_quantity'] }}"></td>
                        <td>{{ $r['supplier_name'] ?? '—' }}<input type="hidden" name="items[{{ $r['stock_item_id'] }}][supplier_id]" value="{{ $r['supplier_id'] }}"></td>
                        <td><small class="text-muted">{{ $r['why'] }}</small></td>
                    </tr>
                @endforeach
            </table>
            <button class="btn btn-primary"><i class="fa fa-file-text-o"></i> Make draft purchase orders</button>
            <small class="text-muted">One order per supplier (the last supplier who delivered each product).</small>
        </form>
        @endif
    </div>
</div>
