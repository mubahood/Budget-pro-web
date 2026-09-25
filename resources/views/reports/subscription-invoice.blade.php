<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2933; }
        h1 { font-size: 20px; margin: 0; } .muted { color: #6b7785; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        th, td { padding: 8px; border-bottom: 1px solid #e3e8ee; text-align: left; } th { background: #f4f6f8; }
        .right { text-align: right; } .total td { font-weight: bold; border-top: 2px solid #1f2933; }
        .paid { color: #2e9e5b; font-weight: bold; font-size: 14px; }
    </style>
</head>
<body>
<table style="margin-top:0">
    <tr>
        <td style="border:0; padding:0">
            <h1>{{ config('app.name') }}</h1>
            <div class="muted">Subscription invoice</div>
        </td>
        <td style="border:0; padding:0" class="right">
            <div><strong>{{ $invoice->number ?: '#'.$invoice->id }}</strong></div>
            <div class="muted">Paid {{ optional($invoice->paid_at)->format('d M Y') }}</div>
            <div class="paid">PAID</div>
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
        <td>{{ $plan?->name ?? 'Subscription' }} plan ({{ $plan?->interval ?? 'month' }}ly)</td>
        <td>{{ optional($invoice->period_start)->format('d M Y') }} – {{ optional($invoice->period_end)->format('d M Y') }}</td>
        <td class="right">{{ number_format((float) data_get($invoice->meta, 'full_price', $invoice->amount), 2) }}</td>
    </tr>
    @if((float) data_get($invoice->meta, 'credit', 0) > 0)
    <tr>
        <td>Credit for unused days of the previous plan</td><td></td>
        <td class="right">-{{ number_format((float) data_get($invoice->meta, 'credit'), 2) }}</td>
    </tr>
    @endif
    <tr class="total"><td colspan="2">Total paid</td><td class="right">{{ number_format((float) $invoice->amount, 2) }}</td></tr>
    </tbody>
</table>
<p class="muted">Payment reference {{ $invoice->provider_invoice_id }} · {{ data_get($invoice->meta, 'payment_type', $invoice->provider) }}</p>
</body>
</html>
