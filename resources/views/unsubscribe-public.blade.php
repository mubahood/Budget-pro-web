<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Messages from {{ $shop }}</title>
    <style>
        body { margin: 0; font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f4f6f8; color: #1f2933; }
        .card { max-width: 420px; margin: 24px 16px; background: #fff; border-radius: 12px; padding: 22px; box-shadow: 0 6px 24px rgba(16,42,67,.08); text-align: center; }
        @media (min-width: 452px) { .card { margin: 24px auto; } }
        h1 { font-size: 20px; margin: 0 0 8px; } p { color: #52606d; font-size: 15px; line-height: 1.5; }
        button { width: 100%; padding: 12px; border: 0; border-radius: 8px; background: #1f2933; color: #fff; font-size: 16px; font-weight: 600; cursor: pointer; }
    </style>
</head>
<body>
<div class="card">
    @if($done)
        <h1>Done</h1>
        <p>You won't get more messages from {{ $shop }}.</p>
        <p>Changed your mind? Ask the shop to turn messages back on.</p>
    @else
        <h1>Stop messages?</h1>
        <p>{{ $shop }} sends you receipts and payment reminders on WhatsApp or SMS.</p>
        <form method="POST" action="{{ url('stop/'.$customer.'/'.$token) }}">
            @csrf
            <button type="submit">Stop all messages from {{ $shop }}</button>
        </form>
    @endif
</div>
</body>
</html>
