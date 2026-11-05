@php
    $categoryLabel = str_replace('_', ' ', $export->category_id ?? 'All');
    $totalAmount = $records->sum('amount');
    $totalPaid = $records->sum('paid_amount');
    $totalPending = $records->sum('not_paid_amount');
    $currency = $company->currency ?? config('saas.default_currency');
    // Shillings in whole figures, dollars with their cents: a printed figure is never rounded.
    $money = fn ($v) => number_format((float) $v, in_array(strtoupper((string) $currency), \App\Support\Money::ZERO_DECIMAL, true) ? 0 : 2); // display only

    // WhatsApp's own plain-text markup (*bold*, real line breaks) -- no
    // HTML, same reasoning as reports/thanks.blade.php's copy button.
    $waLines = [];
    $waLines[] = '*' . ($company->name ?? '') . '*';
    $waLines[] = '_Contributions - ' . $categoryLabel . '_';
    if ($treasurer) {
        $waLines[] = 'Treasurer: ' . $treasurer->name;
    }
    $waLines[] = '';
    foreach ($records as $i => $r) {
        $status = $r->fully_paid === 'Yes'
            ? 'Paid'
            : $currency . ' ' . $money($r->paid_amount) . ' paid, ' . $currency . ' ' . $money($r->not_paid_amount) . ' still owed';
        $waLines[] = ($i + 1) . '. ' . $r->name . ' - *' . $currency . ' ' . $money($r->amount) . '* (' . $status . ')';
    }
    if ($records->count()) {
        $waLines[] = '';
        $waLines[] = '*Pledged:* ' . $currency . ' ' . $money($totalAmount);
        $waLines[] = '*Collected:* ' . $currency . ' ' . $money($totalPaid);
        $waLines[] = '*Still owed:* ' . $currency . ' ' . $money($totalPending);
    } else {
        $waLines[] = 'No contributions recorded yet.';
    }
    $whatsappText = implode("\n", $waLines);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $categoryLabel }}: pledge list</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: #1e2b33; font: 13.5px/1.4 -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 860px; margin: 0 auto; padding: 16px; }
        h1 { font-size: 18px; margin: 0; overflow-wrap: anywhere; }
        .sub { color: #55606a; margin: 2px 0 12px; overflow-wrap: anywhere; }
        .note { border: 1px solid #1e2b33; padding: 8px 10px; margin: 0 0 12px; font-weight: 600; }
        .acts { display: flex; gap: 8px; margin-bottom: 12px; }
        .acts button { flex: 1; min-height: 44px; border: 1px solid #1e2b33; border-radius: 6px; background: #fff; color: #1e2b33; font: inherit; font-weight: 600; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
        th { font-size: 12px; text-align: left; padding: 5px 6px; border-bottom: 1px solid #1e2b33; }
        td { padding: 6px; border-bottom: 1px solid #d8dcdf; vertical-align: top; overflow-wrap: anywhere; }
        .n { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: 700; border-top: 1px solid #1e2b33; border-bottom: 1px solid #1e2b33; }
        .empty { color: #55606a; text-align: center; padding: 18px 6px; }
        @media (max-width: 520px) { .status { display: none; } main { padding: 12px; } body { font-size: 12.5px; } th, td { padding: 5px 3px; } }
        @media print { .no-print { display: none; } main { max-width: none; padding: 0; } }
    </style>
</head>
<body>
<main>
    <h1>{{ $company->name ?? '' }}</h1>
    <p class="sub">Pledge list: {{ $categoryLabel }} · {{ \App\Models\Utils::my_date(time()) }}@if ($treasurer) · Treasurer: {{ $treasurer->name }}@endif · Amounts in {{ $currency }}</p>
    @if (! empty($eventNote))<p class="note">{{ $eventNote }}</p>@endif
    <div class="no-print acts">
        <button class="copy-btn" id="copyBtn" type="button">Copy for WhatsApp</button>
        <button type="button" onclick="window.print()">Print</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th class="n">Pledged</th>
                <th class="n">Paid</th>
                <th class="n">Still owed</th>
                <th class="status">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $r)
                <tr>
                    <td>{{ $r->name }}</td>
                    <td class="n">{{ $money($r->amount) }}</td>
                    <td class="n">{{ $money($r->paid_amount) }}</td>
                    <td class="n">{{ $money($r->not_paid_amount) }}</td>
                    <td class="status">{{ $r->fully_paid === 'Yes' ? 'Paid' : 'Pending' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No pledges recorded under "{{ $categoryLabel }}"{{ $treasurer ? ' for ' . $treasurer->name : '' }} yet.</td></tr>
            @endforelse
        </tbody>
        @if ($records->count())
            <tfoot>
                <tr>
                    <td>Total</td>
                    <td class="n">{{ $money($totalAmount) }}</td>
                    <td class="n">{{ $money($totalPaid) }}</td>
                    <td class="n">{{ $money($totalPending) }}</td>
                    <td class="status"></td>
                </tr>
            </tfoot>
        @endif
    </table>
</main>

    <script>
        const whatsappText = {!! json_encode($whatsappText) !!};

        document.getElementById('copyBtn').addEventListener('click', async function () {
            const btn = this;
            try {
                await navigator.clipboard.writeText(whatsappText);
            } catch (e) {
                const ta = document.createElement('textarea');
                ta.value = whatsappText;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            }
            const original = btn.textContent;
            btn.textContent = '✅ Copied!';
            setTimeout(function () {
                btn.textContent = original;
            }, 2000);
        });
    </script>
</body>

</html>
