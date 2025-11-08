@php
    use App\Models\Utils;
@endphp
<!DOCTYPE html>
<html>
<head>
    <title>Sale Receipt - {{ $sale->receipt_number }}</title>
    @include('css.css')
    <style>
        body {
            font-family: 'Arial', sans-serif;
            font-size: 10px;
            margin: 0;
            padding: 0;
        }
        .receipt-header {
            text-align: center;
            margin-bottom: 8px;
        }
        .receipt-title {
            font-size: 16px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 3px 0;
        }
        .receipt-number {
            font-size: 11px;
            font-weight: bold;
            margin: 2px 0;
        }
        .company-info {
            text-align: center;
            margin-bottom: 5px;
            line-height: 1.2;
        }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            margin-top: 8px;
            margin-bottom: 4px;
            text-transform: uppercase;
            border-bottom: 1.5px solid #333;
            padding-bottom: 2px;
        }
        .info-table {
            width: 100%;
            margin-bottom: 8px;
        }
        .info-table td {
            padding: 2px 0;
            font-size: 9px;
        }
        .info-label {
            font-weight: bold;
            width: 30%;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .items-table th,
        .items-table td {
            border: 1px solid #333;
            padding: 4px 3px;
            text-align: left;
            font-size: 9px;
        }
        .items-table th {
            background-color: #f2f2f2;
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
            border-top: 1.5px solid #333;
        }
        .footer {
            margin-top: 15px;
            text-align: center;
            font-size: 8px;
            border-top: 1px solid #333;
            padding-top: 6px;
        }
        .signature-section {
            margin-top: 20px;
            display: table;
            width: 100%;
        }
        .signature-box {
            display: table-cell;
            width: 50%;
            text-align: center;
            font-size: 8px;
        }
        .signature-line {
            border-top: 1px solid #333;
            margin: 0 15px;
            margin-top: 25px;
        }
        hr.divider {
            height: 1.5px;
            background-color: #333;
            border: none;
            margin: 5px 0;
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

    <div class="receipt-header">
        <div class="receipt-title">SALE RECEIPT</div>
        <div class="receipt-number">{{ $sale->receipt_number }}</div>
        <div style="font-size: 12px; margin-top: 5px;">Date: {{ Utils::my_date($sale->sale_date) }}</div>
    </div>

    <div class="section-title">Customer Information</div>
    <table class="info-table">
        <tr>
            <td class="info-label">Customer Name:</td>
            <td>{{ $sale->customer_name ?? 'Walk-in Customer' }}</td>
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

    <div class="section-title">Items</div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;" class="text-center">#</th>
                <th style="width: 40%;">Item Description</th>
                <th style="width: 10%;" class="text-center">Qty</th>
                <th style="width: 20%;" class="text-right">Unit Price</th>
                <th style="width: 25%;" class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->saleRecordItems as $index => $item)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td>
                    <strong>{{ $item->item_name }}</strong>
                    @if($item->item_sku)
                    <br><small>SKU: {{ $item->item_sku }}</small>
                    @endif
                </td>
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
        <div class="totals-row" style="margin-top: 15px;">
            <div class="totals-label">Amount Paid:</div>
            <div class="totals-value">UGX {{ number_format($sale->amount_paid, 0) }}</div>
        </div>
        @if($sale->balance > 0)
        <div class="totals-row" style="color: #d9534f;">
            <div class="totals-label">Balance Due:</div>
            <div class="totals-value">UGX {{ number_format($sale->balance, 0) }}</div>
        </div>
        @endif
    </div>

    <div class="section-title">Payment Information</div>
    <table class="info-table">
        <tr>
            <td class="info-label">Payment Method:</td>
            <td>{{ $sale->payment_method }}</td>
        </tr>
        <tr>
            <td class="info-label">Payment Status:</td>
            <td><strong>{{ $sale->payment_status }}</strong></td>
        </tr>
        @if($sale->notes)
        <tr>
            <td class="info-label">Notes:</td>
            <td>{{ $sale->notes }}</td>
        </tr>
        @endif
    </table>

    <div class="signature-section">
        <div class="signature-box">
            <div class="signature-line"></div>
            <div style="margin-top: 5px;">Customer Signature</div>
        </div>
        <div class="signature-box">
            <div class="signature-line"></div>
            <div style="margin-top: 5px;">Authorized Signature</div>
        </div>
    </div>

    <div class="footer">
        <p>Thank you for your business!</p>
        <p>Generated on {{ Utils::my_date(now()) }}</p>
    </div>
</body>
</html>
