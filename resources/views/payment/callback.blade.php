<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} — Payment {{ ucfirst($outcome) }}</title>
    <style>
        body { margin: 0; font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f4f6f8; color: #1f2933; }
        .card { max-width: 440px; margin: 48px auto; background: #fff; border-radius: 12px; padding: 32px 28px; box-shadow: 0 6px 24px rgba(16, 42, 67, .08); text-align: center; }
        .icon { width: 72px; height: 72px; border-radius: 50%; margin: 0 auto 18px; display: flex; align-items: center; justify-content: center; font-size: 34px; color: #fff; }
        .paid { background: #2e9e5b; } .pending { background: #e0a100; } .cancelled, .failed, .not_found { background: #d64545; }
        h1 { font-size: 22px; margin: 0 0 8px; } p { line-height: 1.5; margin: 0 0 20px; }
        .ref { font-size: 12px; color: #6b7785; word-break: break-all; }
        .btn { display: block; margin: 10px 0; padding: 12px 16px; border-radius: 8px; text-decoration: none; font-weight: 600; }
        .primary { background: #1f6feb; color: #fff; } .secondary { background: #eef2f6; color: #1f2933; }
    </style>
</head>
<body>
<div class="card">
    <div class="icon {{ $outcome }}">{{ $outcome === 'paid' ? '✓' : ($outcome === 'pending' ? '…' : '✕') }}</div>
    <h1>
        @switch($outcome)
            @case('paid') Payment confirmed @break
            @case('pending') Payment received @break
            @case('cancelled') Payment cancelled @break
            @case('not_found') Payment not found @break
            @default Payment failed
        @endswitch
    </h1>
    <p>{{ $message }}</p>
    @if($invoice)
        <p class="ref">{{ number_format((float) $invoice->amount, 2) }} {{ $invoice->currency }} · Ref {{ $txRef }}</p>
    @elseif($txRef !== '')
        <p class="ref">Ref {{ $txRef }}</p>
    @endif
    @if($appLink)
        <a class="btn primary" href="{{ $appLink }}?status={{ $outcome }}&tx_ref={{ urlencode($txRef) }}">Return to the app</a>
    @endif
    <a class="btn secondary" href="{{ $dashboardUrl }}">Go to the web dashboard</a>
</div>
</body>
</html>
