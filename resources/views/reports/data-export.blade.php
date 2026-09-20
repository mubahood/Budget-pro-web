@php
    $categoryLabel = str_replace('_', ' ', $export->category_id ?? 'All');
    $totalAmount = $records->sum('amount');
    $totalPaid = $records->sum('paid_amount');
    $totalPending = $records->sum('not_paid_amount');
    $currency = $company->currency ?? 'UGX';

    // WhatsApp's own plain-text markup (*bold*, real line breaks) -- no
    // HTML, same reasoning as reports/thanks.blade.php's copy button.
    $waLines = [];
    $waLines[] = '📋 *' . ($company->name ?? '') . '*';
    $waLines[] = '_Contributions - ' . $categoryLabel . '_';
    if ($treasurer) {
        $waLines[] = 'Treasurer: ' . $treasurer->name;
    }
    $waLines[] = '';
    foreach ($records as $i => $r) {
        $status = $r->fully_paid === 'Yes'
            ? '✅ Paid'
            : '📌 ' . $currency . ' ' . number_format((float) $r->paid_amount) . ' paid, ' . $currency . ' ' . number_format((float) $r->not_paid_amount) . ' pending';
        $waLines[] = ($i + 1) . '. ' . $r->name . ' - *' . $currency . ' ' . number_format((float) $r->amount) . '* (' . $status . ')';
    }
    if ($records->count()) {
        $waLines[] = '';
        $waLines[] = '*Total Pledged:* ' . $currency . ' ' . number_format($totalAmount);
        $waLines[] = '*Total Paid:* ' . $currency . ' ' . number_format($totalPaid);
        $waLines[] = '*Total Pending:* ' . $currency . ' ' . number_format($totalPending);
    } else {
        $waLines[] = 'No contributions recorded yet.';
    }
    $whatsappText = implode("\n", $waLines);
@endphp
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>{{ $categoryLabel }} - Contributions Report</title>
    @include('css.css')
    <style>
        body {
            padding: 24px;
        }

        .my-table {
            border-collapse: collapse;
            width: 100%;
        }

        .my-table th,
        .my-table td {
            border: 1px solid #999;
            padding: 6px 8px;
        }

        .my-table th {
            background: #f2f2f2;
        }

        @media print {
            .no-print {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="no-print" style="text-align: right; margin-bottom: 12px;">
        <button class="copy-btn" id="copyBtn" type="button"
            style="padding: 8px 18px; background: #25D366; color: #fff; border: none; border-radius: 6px; cursor: pointer;">
            📋 Copy for WhatsApp
        </button>
        <button type="button" onclick="window.print()"
            style="padding: 8px 18px; background: #444; color: #fff; border: none; border-radius: 6px; cursor: pointer;">
            🖨️ Print
        </button>
    </div>

    <p class="fs-18 text-center fw-700 text-uppercase mb-1">{{ $company->name ?? '' }}</p>
    <p class="fs-16 text-center mb-4">Contributions Report &mdash; {{ $categoryLabel }}</p>

    <table class="w-100 mb-4">
        <tr>
            <td>@if ($treasurer)Treasurer: <strong>{{ $treasurer->name }}</strong>@endif</td>
            <td class="text-right">Generated: {{ \App\Models\Utils::my_date(time()) }}</td>
        </tr>
    </table>

    <table class="my-table">
        <thead>
            <tr>
                <th>Sn.</th>
                <th>Name</th>
                <th class="text-right">Pledged</th>
                <th class="text-right">Paid</th>
                <th class="text-right">Balance</th>
                <th class="text-center">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $i => $r)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $r->name }}</td>
                    <td class="text-right">{{ number_format($r->amount) }}</td>
                    <td class="text-right">{{ number_format($r->paid_amount) }}</td>
                    <td class="text-right">{{ number_format($r->not_paid_amount) }}</td>
                    <td class="text-center">{{ $r->fully_paid === 'Yes' ? 'Paid' : 'Pending' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center">No contributions recorded under "{{ $categoryLabel }}"
                        {{ $treasurer ? 'for ' . $treasurer->name : '' }} yet.</td>
                </tr>
            @endforelse
        </tbody>
        @if ($records->count())
            <tfoot>
                <tr>
                    <td colspan="2" class="fw-700">Total</td>
                    <td class="text-right fw-700">{{ $currency }} {{ number_format($totalAmount) }}</td>
                    <td class="text-right fw-700">{{ $currency }} {{ number_format($totalPaid) }}</td>
                    <td class="text-right fw-700">{{ $currency }} {{ number_format($totalPending) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        @endif
    </table>

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
