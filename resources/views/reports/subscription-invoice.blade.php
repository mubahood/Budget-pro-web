@php
    $seller = $seller ?? (array) config('saas.invoice', []);
    $taxRate = $taxRate ?? (float) ($invoice->tax_rate ?? 0);
    $taxAmount = $taxAmount ?? (float) ($invoice->tax_amount ?? 0);
    $taxLabel = $taxLabel ?? (string) config('saas.invoice.tax_label', 'VAT');
    $interval = data_get($invoice->meta, 'interval', $plan?->interval ?? 'month') === 'year' ? 'yearly' : 'monthly';
    $money = fn ($v) => number_format((float) $v, in_array($invoice->currency, ['UGX', 'RWF', 'TZS'], true) ? 0 : 2);
    $refunded = $invoice->status === 'refunded' || (float) ($invoice->refund_amount ?? 0) > 0;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2933; }
        h1 { font-size: 20px; margin: 0; } .muted { color: #6b7785; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        th, td { padding: 8px; border-bottom: 1px solid #e3e8ee; text-align: left; vertical-align: top; } th { background: #f4f6f8; }
        .right { text-align: right; } .total td { font-weight: bold; border-top: 2px solid #1f2933; }
        .paid { color: #2e9e5b; font-weight: bold; font-size: 14px; } .refunded { color: #b45309; font-weight: bold; font-size: 14px; }
        .small { font-size: 10px; }
    </style>
</head>
<body>
<table style="margin-top:0">
    <tr>
        <td style="border:0; padding:0">
            <h1>{{ $seller['seller_name'] ?? config('app.name') }}</h1>
            @if(!empty($seller['seller_address']))<div class="muted">{{ $seller['seller_address'] }}</div>@endif
            @if(!empty($seller['seller_phone']) || !empty($seller['seller_email']))
                <div class="muted">{{ implode(' · ', array_filter([$seller['seller_phone'] ?? null, $seller['seller_email'] ?? null])) }}</div>
            @endif
            @if(!empty($seller['seller_tin']))<div class="muted">TIN {{ $seller['seller_tin'] }}</div>@endif
            <div class="muted" style="margin-top:6px">{{ $taxRate > 0 ? 'Tax invoice' : 'Subscription invoice' }}</div>
        </td>
        <td style="border:0; padding:0" class="right">
            <div><strong>{{ $invoice->number ?: '#'.$invoice->id }}</strong></div>
            <div class="muted">Paid {{ optional($invoice->paid_at)->format('d M Y') }}</div>
            @if($refunded)
                <div class="refunded">REFUNDED{{ $invoice->status !== 'refunded' ? ' IN PART' : '' }}</div>
            @else
                <div class="paid">PAID</div>
            @endif
        </td>
    </tr>
</table>

<p><strong>Billed to</strong><br>
    {{ $company?->name }}<br>
    @if($company?->address){{ $company->address }}<br>@endif
    @if($company?->phone_number){{ $company->phone_number }}<br>@endif
    @if($company?->email){{ $company->email }}@endif
</p>

<table>
    <thead><tr><th>Description</th><th>Period</th><th class="right">Amount ({{ $invoice->currency }})</th></tr></thead>
    <tbody>
    <tr>
        <td>{{ $plan?->name ?? 'Subscription' }} plan ({{ $interval }})</td>
        <td>{{ optional($invoice->period_start)->format('d M Y') }} – {{ optional($invoice->period_end)->format('d M Y') }}</td>
        <td class="right">{{ $money(data_get($invoice->meta, 'full_price', $invoice->amount)) }}</td>
    </tr>
    @if((float) data_get($invoice->meta, 'credit', 0) > 0)
    <tr>
        <td>Credit for unused days of the previous plan</td><td></td>
        <td class="right">-{{ $money(data_get($invoice->meta, 'credit')) }}</td>
    </tr>
    @endif
    <tr class="total"><td colspan="2">Total paid</td><td class="right">{{ $money($invoice->amount) }}</td></tr>
    @if($taxRate > 0)
    <tr><td colspan="2" class="muted">Includes {{ $taxLabel }} at {{ rtrim(rtrim(number_format($taxRate, 2), '0'), '.') }}%</td><td class="right muted">{{ $money($taxAmount) }}</td></tr>
    @endif
    @if($refunded)
    <tr><td colspan="2">Refunded {{ optional($invoice->refunded_at)->format('d M Y') }}</td><td class="right">-{{ $money($invoice->refund_amount) }}</td></tr>
    @endif
    </tbody>
</table>
<p class="muted small">Payment reference {{ $invoice->provider_invoice_id }} · {{ str_replace('_', ' ', (string) data_get($invoice->meta, 'payment_type', $invoice->provider)) }}</p>
</body>
</html>
