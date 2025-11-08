@php
    use App\Models\Utils;
@endphp
<!DOCTYPE html>
<html>
<head>
    <title>Invoice - {{ $sale->invoice_number }}</title>
    @include('css.css')
    <style>
        body {
            font-family: 'Arial', sans-serif;
            font-size: 10px;
        }
        .invoice-header {
            text-align: center;
            margin-bottom: 8px;
        }
        .invoice-title {
            font-size: 16px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 3px 0;
            color: #2c3e50;
        }
        .invoice-number {
            font-size: 11px;
            font-weight: bold;
            margin: 2px 0;
        }
        .company-info {
            text-align: center;
            margin-bottom: 5px;
            line-height: 1.2;
            font-size: 9px;
        }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            margin-top: 8px;
            margin-bottom: 4px;
            text-transform: uppercase;
            border-bottom: 1.5px solid #2c3e50;
            padding-bottom: 2px;
            color: #2c3e50;
        }
        .info-row {
            display: table;
            width: 100%;
            margin-bottom: 8px;
        }
        .info-box {
            display: table-cell;
            width: 48%;
            vertical-align: top;
        }
        .info-box.right {
            text-align: right;
        }
        .info-table {
            width: 100%;
        }
        .info-table td {
            padding: 2px 0;
            font-size: 9px;
        }
        .info-label {
            font-weight: bold;
            width: 40%;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .items-table th,
        .items-table td {
            border: 1px solid #2c3e50;
            padding: 4px 3px;
            text-align: left;
            font-size: 9px;
        }
        .items-table th {
            background-color: #34495e;
            color: white;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 9px;
        }
        .items-table td.text-right,
        .items-table th.text-right {
            text-align: right;
        }
        .items-table td.text-center,
        .items-table th.text-center {
            text-align: center;
        }
        .items-table tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .totals-section {
            width: 100%;
            margin-top: 8px;
        }
        .totals-row {
            display: table;
            width: 100%;
            margin-bottom: 2px;
            font-size: 9px;
        }
        .totals-label {
            display: table-cell;
            width: 70%;
            text-align: right;
            padding-right: 10px;
            font-weight: bold;
        }
        .totals-value {
            display: table-cell;
            width: 30%;
            text-align: right;
            font-weight: bold;
        }
        .totals-row.grand-total {
            font-size: 11px;
            margin-top: 4px;
            padding-top: 4px;
            border-top: 1.5px solid #2c3e50;
            color: #2c3e50;
        }
        .payment-terms {
            margin-top: 10px;
            padding: 5px;
            background-color: #f8f9fa;
            border-left: 2px solid #2c3e50;
            font-size: 9px;
        }
        .footer {
            margin-top: 15px;
            text-align: center;
            font-size: 8px;
            border-top: 1px solid #2c3e50;
            padding-top: 6px;
        }
        hr.divider {
            height: 1.5px;
            background-color: #2c3e50;
            border: none;
            margin: 5px 0;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 2px;
            font-weight: bold;
            font-size: 9px;
        }
        .status-paid {
            background-color: #d4edda;
            color: #155724;
        }
        .status-unpaid {
            background-color: #f8d7da;
            color: #721c24;
        }
        .status-partial {
            background-color: #fff3cd;
            color: #856404;
        }
    </style>
</head>
<body>
    <table class="w-100">
        <tr>
            <td style="width: 15%">
            </td>
            <td>
                <div class="company-info">
                    <p style="font-size: 18px; font-weight: bold; margin: 5px 0; text-transform: uppercase;">
                        {{ $company->name }}
                    </p>
                    <p style="margin: 3px 0;">TEL: {{ $company->phone_number }}@if($company->chairperson_phone_number), {{ $company->chairperson_phone_number }}@endif</p>
                    <p style="margin: 3px 0;">EMAIL: {{ $company->email }}</p>
                    @if($company->address)
                    <p style="margin: 3px 0;">{{ $company->address }}</p>
                    @endif
                    @if($company->slogan)
                    <p style="margin: 3px 0; font-style: italic;">{{ $company->slogan }}</p>
                    @endif
                </div>
            </td>
            <td style="width: 15%; text-align: right;">
                @if ($company->logo != null)
                <img style="width: 100%; max-width: 80px;" src="{{ public_path('storage/' . $company->logo) }}">
                @endif
            </td>
        </tr>
    </table>

    <hr class="divider">

    <div class="invoice-header">
        <div class="invoice-title">INVOICE</div>
        <div class="invoice-number">{{ $sale->invoice_number }}</div>
    </div>

    <div class="info-row">
        <div class="info-box">
            <div class="section-title">Bill To</div>
            <table class="info-table">
                <tr>
                    <td class="info-label">Customer:</td>
                    <td><strong>{{ $sale->customer_name ?? 'Walk-in Customer' }}</strong></td>
                </tr>
                @if($sale->customer_phone)
                <tr>
                    <td class="info-label">Phone:</td>
                    <td>{{ $sale->customer_phone }}</td>
                </tr>
                @endif
                @if($sale->customer_address)
                <tr>
                    <td class="info-label">Address:</td>
                    <td>{{ $sale->customer_address }}</td>
                </tr>
                @endif
            </table>
        </div>
        <div class="info-box right">
            <div class="section-title" style="text-align: right;">Invoice Details</div>
            <table class="info-table">
                <tr>
                    <td class="info-label" style="text-align: right;">Invoice Date:</td>
                    <td><strong>{{ Utils::my_date($sale->sale_date) }}</strong></td>
                </tr>
                <tr>
                    <td class="info-label" style="text-align: right;">Receipt No:</td>
                    <td>{{ $sale->receipt_number }}</td>
                </tr>
                <tr>
                    <td class="info-label" style="text-align: right;">Status:</td>
                    <td>
                        <span class="status-badge status-{{ strtolower($sale->payment_status) }}">
                            {{ $sale->payment_status }}
                        </span>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="section-title">Items</div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;" class="text-center">#</th>
                <th style="width: 35%;">Description</th>
                <th style="width: 10%;" class="text-center">SKU</th>
                <th style="width: 10%;" class="text-center">Qty</th>
                <th style="width: 20%;" class="text-right">Unit Price</th>
                <th style="width: 20%;" class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->saleRecordItems as $index => $item)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td><strong>{{ $item->item_name }}</strong></td>
                <td class="text-center">{{ $item->item_sku ?? '-' }}</td>
                <td class="text-center">{{ number_format($item->quantity, 2) }}</td>
                <td class="text-right">UGX {{ number_format($item->unit_price, 0) }}</td>
                <td class="text-right">UGX {{ number_format($item->subtotal, 0) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals-section">
        <div class="totals-row">
            <div class="totals-label">Subtotal:</div>
            <div class="totals-value">UGX {{ number_format($sale->total_amount, 0) }}</div>
        </div>
        <div class="totals-row grand-total">
            <div class="totals-label">TOTAL AMOUNT:</div>
            <div class="totals-value">UGX {{ number_format($sale->total_amount, 0) }}</div>
        </div>
    </div>

    <div class="payment-terms">
        <strong>Payment Information:</strong><br>
        <table class="info-table" style="margin-top: 10px;">
            <tr>
                <td class="info-label">Payment Method:</td>
                <td>{{ $sale->payment_method }}</td>
            </tr>
            <tr>
                <td class="info-label">Amount Paid:</td>
                <td><strong>UGX {{ number_format($sale->amount_paid, 0) }}</strong></td>
            </tr>
            @if($sale->balance > 0)
            <tr>
                <td class="info-label">Balance Due:</td>
                <td style="color: #d9534f;"><strong>UGX {{ number_format($sale->balance, 0) }}</strong></td>
            </tr>
            @else
            <tr>
                <td class="info-label">Status:</td>
                <td style="color: #28a745;"><strong>PAID IN FULL</strong></td>
            </tr>
            @endif
        </table>
        @if($sale->notes)
        <div style="margin-top: 10px;">
            <strong>Notes:</strong><br>
            {{ $sale->notes }}
        </div>
        @endif
    </div>

    <div class="footer">
        <p><strong>Thank you for your business!</strong></p>
        <p>This is a computer-generated invoice and does not require a signature.</p>
        <p>Generated on {{ Utils::my_date(now()) }}</p>
    </div>
</body>
</html>
