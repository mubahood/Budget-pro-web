<?php

namespace App\Services\Shop;

use App\Models\Company;
use App\Models\SaleRecord;
use App\Support\Money;

/**
 * Receipt rendering (plan A3, P2-5): WhatsApp-ready text (the same layout the
 * phone prints/shares) and the PDF. Shows the final number once synced and the
 * device's provisional reference when there is one (Appendix D).
 */
class ReceiptService
{
    public function text(SaleRecord $sale): string
    {
        $sale->loadMissing('saleRecordItems');
        $company = Company::withoutGlobalScopes()->find($sale->company_id);
        $cur = Money::symbol((int) $sale->company_id);
        $n = fn ($v, $d = 0) => number_format((float) $v, $d);
        $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');

        $out = [];
        $out[] = '*'.trim((string) ($company?->name ?? 'Receipt')).'*';
        if ($company?->receipt_header) {
            $out[] = trim($company->receipt_header);
        }
        if ($company?->phone_number) {
            $out[] = 'Tel: '.$company->phone_number;
        }
        $out[] = '';
        $out[] = 'Receipt: *'.($sale->receipt_number ?: $sale->provisional_number ?: '#'.$sale->id).'*';
        if ($sale->provisional_number && $sale->receipt_number) {
            $out[] = 'Ref: '.preg_replace('/^[A-Z]+-/', '', $sale->provisional_number);
        }
        $out[] = 'Date: '.optional($sale->sale_date)->format('d M Y').' '.optional($sale->created_at)->format('H:i');
        if ($sale->customer_name && strcasecmp($sale->customer_name, 'Walk-in Customer') !== 0) {
            $out[] = 'Customer: '.$sale->customer_name;
        }
        $out[] = '--------------------------------';
        foreach ($sale->saleRecordItems as $i) {
            $out[] = $i->item_name;
            $line = '  '.$qty($i->quantity).' x '.$n($i->unit_price).' = '.$n($i->subtotal);
            $out[] = $line;
            if ((float) $i->discount_amount > 0) {
                $out[] = '  less '.$n($i->discount_amount);
            }
            if ((float) $i->returned_quantity > 0) {
                $out[] = '  returned '.$qty($i->returned_quantity);
            }
        }
        $out[] = '--------------------------------';
        if ((float) $sale->discount_amount > 0) {
            $out[] = 'Subtotal: '.$cur.' '.$n($sale->subtotal);
            $out[] = 'Discount: '.$cur.' '.$n($sale->discount_amount);
        }
        $out[] = '*TOTAL: '.$cur.' '.$n($sale->total_amount).'*';
        if ((float) $sale->refunded_amount > 0) {
            $out[] = 'Returned: '.$cur.' '.$n($sale->refunded_amount);
        }
        $out[] = 'Paid: '.$cur.' '.$n($sale->amount_paid);
        if ((float) $sale->change_given > 0) {
            $out[] = 'Change: '.$cur.' '.$n($sale->change_given);
        }
        if ((float) $sale->balance > 0) {
            $out[] = '*Balance due: '.$cur.' '.$n($sale->balance).'*';
        }
        if ($sale->voided_at) {
            $out[] = '*** VOIDED ***';
        }
        $out[] = '';
        $out[] = trim((string) ($company?->receipt_footer ?: 'Thank you for your business!'));

        return implode("\n", $out);
    }

    public function pdf(SaleRecord $sale): string
    {
        $sale->loadMissing(['saleRecordItems', 'company']);
        $pdf = app('dompdf.wrapper');
        $pdf->loadHTML(view('reports.sale-receipt', ['sale' => $sale, 'company' => $sale->company])->render());

        return $pdf->output();
    }
}
