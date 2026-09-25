<?php

namespace App\Http\Controllers;

use App\Models\SaleRecord;

/** The link in a WhatsApp/SMS receipt (plan Part E1): the full receipt, readable on any phone, with a PDF. */
class PublicReceiptController extends Controller
{
    public function show(string $token)
    {
        $sale = SaleRecord::withoutGlobalScopes()->where('receipt_token', $token)->with(['saleRecordItems', 'company'])->firstOrFail();

        return view('receipt-public', ['sale' => $sale, 'company' => $sale->company, 'token' => $token]);
    }

    public function pdf(string $token)
    {
        $sale = SaleRecord::withoutGlobalScopes()->where('receipt_token', $token)->firstOrFail();

        return response((new \App\Services\Shop\ReceiptService())->pdf($sale), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="receipt-'.($sale->receipt_number ?: $sale->id).'.pdf"']);
    }
}
