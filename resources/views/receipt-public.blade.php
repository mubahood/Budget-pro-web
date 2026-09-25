@php $cur = $company?->currency ?? ''; $f = fn ($v) => number_format((float) $v, 0); @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Receipt {{ $sale->receipt_number }} — {{ $company?->name }}</title>
    <style>
        body { margin: 0; font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f4f6f8; color: #1f2933; }
        .card { max-width: 420px; margin: 24px auto; background: #fff; border-radius: 12px; padding: 22px; box-shadow: 0 6px 24px rgba(16,42,67,.08); }
        h1 { font-size: 20px; margin: 0; text-align: center; } .muted { color: #6b7785; font-size: 13px; text-align: center; }
        table { width: 100%; border-collapse: collapse; margin: 14px 0; font-size: 14px; } td { padding: 5px 0; } .r { text-align: right; }
        .total td { border-top: 1px dashed #9aa5b1; font-weight: 700; } .btn { display: block; text-align: center; padding: 11px; border-radius: 8px; background: #eef2f6; color: #1f2933; text-decoration: none; font-weight: 600; margin-top: 10px; }
        .void { color: #d64545; text-align: center; font-weight: 700; }
    </style>
</head>
<body>
<div class="card">
    <h1>{{ $company?->name }}</h1>
    <div class="muted">{{ $company?->phone_number }} {{ $company?->address }}</div>
    <p class="muted">Receipt {{ $sale->receipt_number ?: '#'.$sale->id }} · {{ optional($sale->sale_date)->format('d M Y') }}</p>
    @if($sale->voided_at)<p class="void">This sale was cancelled.</p>@endif
    <table>
        @foreach($sale->saleRecordItems as $i)
            <tr><td>{{ rtrim(rtrim(number_format((float) $i->quantity, 3, '.', ''), '0'), '.') }} × {{ $i->item_name }}</td><td class="r">{{ $f($i->line_total ?: $i->subtotal) }}</td></tr>
        @endforeach
        @if((float) $sale->discount_amount > 0)<tr><td>Discount</td><td class="r">−{{ $f($sale->discount_amount) }}</td></tr>@endif
        <tr class="total"><td>Total ({{ $cur }})</td><td class="r">{{ $f($sale->total_amount) }}</td></tr>
        <tr><td>Paid</td><td class="r">{{ $f($sale->amount_paid) }}</td></tr>
        @if((float) $sale->balance > 0)<tr><td><strong>Balance</strong></td><td class="r"><strong>{{ $f($sale->balance) }}</strong></td></tr>@endif
        @if((float) $sale->refunded_amount > 0)<tr><td>Returned</td><td class="r">−{{ $f($sale->refunded_amount) }}</td></tr>@endif
    </table>
    @if($company?->receipt_footer)<p class="muted">{{ $company->receipt_footer }}</p>@endif
    <a class="btn" href="{{ url('r/'.$token.'/pdf') }}">Download PDF</a>
    <p class="muted" style="margin-top:14px">Thank you!</p>
</div>
</body>
</html>
