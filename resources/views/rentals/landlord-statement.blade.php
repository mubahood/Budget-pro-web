{{-- A landlord's statement (Estate Ledger, docs/estate-ledger/04-architecture.md §9.1 Statement, LandlordAccounts::statement). Plain, light, square. --}}
@php($m = fn (int $minor) => \App\Support\Rentals\Minor::format($minor, $cid))
@php($d = fn (?string $day) => $day ? \Illuminate\Support\Carbon::parse($day)->format('j M Y') : '')
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }} · {{ $s['landlord']['name'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1e2b33; }
        .head { border-bottom: 2px solid #1e2b33; padding-bottom: 6px; margin-bottom: 8px; }
        .account { font-size: 15px; font-weight: bold; margin: 0; } h1 { font-size: 13px; margin: 6px 0 0; } h2 { font-size: 11px; margin: 14px 0 0; }
        .muted { color: #4b5a63; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 3px 5px; border-bottom: 1px solid #e4e7e9; text-align: left; vertical-align: top; }
        th { background: #f4f5f6; } .r { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: bold; border-top: 2px solid #1e2b33; }
        .sum td { border: 1px solid #e4e7e9; } .sum td.l { background: #f4f5f6; width: 40%; }
        .neg { color: #b42318; } .foot { margin-top: 14px; font-size: 8.5px; color: #4b5a63; }
    </style>
</head>
<body>
    <div class="head">
        <p class="account">{{ $account }}</p>
        <h1>{{ $title }}</h1>
        <div class="muted">{{ $s['landlord']['name'] }} · {{ $d($s['from']) }} to {{ $d($s['to']) }} · {{ $s['currency'] }}</div>
    </div>

    <table class="sum">
        <tr><td class="l">Balance at the start</td><td class="r">{{ $m($s['opening_minor']) }}</td></tr>
        <tr><td class="l">Rent and fees collected</td><td class="r">{{ $m($s['totals']['collected_minor']) }}</td></tr>
        @if($s['totals']['commission_minor'] !== 0)<tr><td class="l">Commission</td><td class="r">{{ $m(-$s['totals']['commission_minor']) }}</td></tr>@endif
        @if($s['totals']['deposits_minor'] !== 0)<tr><td class="l">Deposits passed to you</td><td class="r">{{ $m($s['totals']['deposits_minor']) }}</td></tr>@endif
        @if($s['totals']['expenses_minor'] !== 0)<tr><td class="l">Expenses</td><td class="r">{{ $m(-$s['totals']['expenses_minor']) }}</td></tr>@endif
        @if($s['totals']['payouts_minor'] !== 0)<tr><td class="l">{{ $owner ? 'Owner drawings' : 'Paid to you' }}</td><td class="r">{{ $m(-$s['totals']['payouts_minor']) }}</td></tr>@endif
        @if($s['totals']['other_minor'] !== 0)<tr><td class="l">Other</td><td class="r">{{ $m($s['totals']['other_minor']) }}</td></tr>@endif
        <tr><td class="l"><strong>{{ $s['closing_minor'] < 0 ? 'You owe the agency' : ($owner ? 'Money in hand at the end' : 'Owed to you at the end') }}</strong></td>
            <td class="r"><strong class="{{ $s['closing_minor'] < 0 ? 'neg' : '' }}">{{ $m(abs($s['closing_minor'])) }}</strong></td></tr>
    </table>

    <h2>Lines</h2>
    <table>
        <thead><tr><th>Date</th><th>What</th><th>Unit</th><th>Tenant</th><th class="r">Collected</th><th class="r">Commission</th><th class="r">To you</th></tr></thead>
        <tbody>
        @forelse($s['lines'] as $l)
            <tr><td>{{ $d($l['date']) }}</td><td>{{ $l['description'] }}</td><td>{{ trim(($l['unit'] ?? '').(($l['property'] ?? '') !== '' ? ', '.$l['property'] : ''), ', ') }}</td>
                <td>{{ $l['tenant'] }}</td><td class="r">{{ $l['gross_minor'] ? $m($l['gross_minor']) : '' }}</td><td class="r">{{ $l['commission_minor'] ? $m($l['commission_minor']) : '' }}</td>
                <td class="r {{ $l['amount_minor'] < 0 ? 'neg' : '' }}">{{ $m($l['amount_minor']) }}</td></tr>
        @empty
            <tr><td colspan="7" class="muted">Nothing in this period.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Units</h2>
    <table class="sum">
        <tr><td class="l">Units</td><td class="r">{{ $s['occupancy']['units'] }}</td></tr>
        <tr><td class="l">Occupied</td><td class="r">{{ $s['occupancy']['occupied'] }}</td></tr>
        <tr><td class="l">Vacant</td><td class="r">{{ $s['occupancy']['vacant'] }}</td></tr>
    </table>

    @if($s['arrears'] !== [])
        <h2>Still owed by tenants on {{ $d($s['to']) }}</h2>
        <table>
            <thead><tr><th>Unit</th><th>Tenant</th><th class="r">Owed</th><th class="r">Days</th></tr></thead>
            <tbody>@foreach($s['arrears'] as $a)<tr><td>{{ $a['unit'] }}</td><td>{{ $a['tenant'] }}</td><td class="r">{{ $m($a['owed_minor']) }}</td><td class="r">{{ $a['days'] }}</td></tr>@endforeach</tbody>
        </table>
    @endif
    <div class="foot">{{ $account }} · printed {{ now()->format('j M Y H:i') }}</div>
</body>
</html>
