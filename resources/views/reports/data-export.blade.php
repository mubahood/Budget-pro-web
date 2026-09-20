@php
    $categoryLabel = str_replace('_', ' ', $export->category_id ?? 'All');
    $totalAmount = $records->sum('amount');
    $totalPaid = $records->sum('paid_amount');
    $totalPending = $records->sum('not_paid_amount');
    $currency = $company->currency ?? 'UGX';
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
</body>

</html>
