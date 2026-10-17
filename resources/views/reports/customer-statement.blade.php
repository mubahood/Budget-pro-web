@php
  // A customer statement for dompdf (GET /api/v1/customers/{id}/statement.pdf): the same layout as the web
  // customer page's PDF (budget-pro-new livewire/shop/customers/statement-pdf). Plain HTML, inline styles only.
  // $shop (Company), $party (Customer), $statement (CustomerService::statement), $from (?Y-m-d), $printedAt
  $fmt = fn ($v) => \App\Support\Money::format($v, null, (int) $shop->id);
  $entries = $statement['entries'] ?? [];
  $opening = (float) ($statement['opening_balance'] ?? 0);
  $closing = (float) ($statement['closing_balance'] ?? 0);
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Statement · {{ $party->name }}</title>
<style>
  body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#111c2e;margin:0;}
  h1{font-size:18px;margin:0 0 2px;}
  .muted{color:#5a6b80;}
  .head{width:100%;margin-bottom:14px;}
  .head td{vertical-align:top;}
  .box{border:1px solid #cbd5e1;padding:8px 10px;}
  table.lines{width:100%;border-collapse:collapse;}
  table.lines th{background:#eef2f7;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.04em;padding:5px 6px;border-bottom:1px solid #cbd5e1;}
  table.lines td{padding:4px 6px;border-bottom:1px solid #e2e8f0;}
  .r{text-align:right;white-space:nowrap;}
  .total{font-size:14px;font-weight:bold;}
</style>
</head>
<body>
  <table class="head">
    <tr>
      <td>
        <h1>{{ $shop->name }}</h1>
        <div class="muted">{{ trim(($shop->address ?? '').' '.($shop->phone_number ?? '')) }}</div>
      </td>
      <td class="r">
        <div class="total">Statement</div>
        @if($from)<div class="muted">From {{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }}</div>@endif
        <div class="muted">Printed {{ $printedAt->format('d M Y, H:i') }}</div>
      </td>
    </tr>
  </table>

  <table class="head">
    <tr>
      <td class="box">
        <b>{{ $party->name }}</b>
        @if($party->phone)<div class="muted">{{ $party->phone }}</div>@endif
        @if($party->address)<div class="muted">{{ $party->address }}</div>@endif
      </td>
      <td class="box r" style="width:40%;">
        <div class="muted">Owes</div>
        <div class="total">{{ $fmt(max(0, $closing)) }}</div>
      </td>
    </tr>
  </table>

  @if($entries || $opening != 0)
    <table class="lines">
      <thead><tr><th>Date</th><th>What</th><th class="r">Bought</th><th class="r">Paid</th><th class="r">Balance</th></tr></thead>
      <tbody>
        @if($opening != 0)
          <tr><td></td><td>Brought forward</td><td></td><td></td><td class="r">{{ $fmt($opening) }}</td></tr>
        @endif
        @foreach($entries as $e)
          <tr>
            <td style="white-space:nowrap;">{{ $e['date'] ? \Illuminate\Support\Carbon::parse($e['date'])->format('d M Y') : '' }}</td>
            <td>{{ $e['description'] }}</td>
            <td class="r">{{ (float) $e['debit'] > 0 ? $fmt($e['debit']) : '' }}</td>
            <td class="r">{{ (float) $e['credit'] > 0 ? $fmt($e['credit']) : '' }}</td>
            <td class="r"><b>{{ $fmt($e['balance']) }}</b></td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @else
    <p class="muted">No entries yet.</p>
  @endif
  @include('reports.partials.support-line', ['company' => $shop ?? null, 'size' => '9px'])
</body>
</html>
