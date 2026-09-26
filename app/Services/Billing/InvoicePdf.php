<?php

namespace App\Services\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Team\Permissions;

/**
 * Subscription invoice documents for budget-pro (classic + API) and the new app (POWER_PLAN §4.1):
 * only people with the `billing` permission, only their own shop's paid (or refunded) invoices,
 * with the seller's details and the tax contained in the price from config('saas.invoice').
 */
class InvoicePdf
{
    /** The invoice $user may download, or a refusal (403 permission / 404 not theirs or not paid). */
    public function find(User $user, int $invoiceId): SubscriptionInvoice
    {
        if (! Permissions::can($user, 'billing')) {
            throw BusinessRuleException::make('forbidden', 'Only people who manage billing can download invoices.');
        }
        $invoice = SubscriptionInvoice::where('company_id', (int) $user->company_id)->whereIn('status', ['paid', 'refunded'])->find($invoiceId);
        if ($invoice === null) {
            throw BusinessRuleException::make('not_found', 'Invoice not found.');
        }

        return $invoice;
    }

    public function html(SubscriptionInvoice $invoice): string
    {
        $rate = (float) $invoice->tax_rate;

        return view('reports.subscription-invoice', [
            'invoice' => $invoice,
            'company' => Company::withoutGlobalScopes()->find($invoice->company_id),
            'plan' => $invoice->plan(),
            'seller' => (array) config('saas.invoice', []),
            'taxRate' => $rate,
            'taxAmount' => (float) $invoice->tax_amount,
            'taxLabel' => (string) config('saas.invoice.tax_label', 'VAT'),
        ])->render();
    }

    /** PDF bytes. */
    public function render(SubscriptionInvoice $invoice): string
    {
        $pdf = app('dompdf.wrapper');
        $pdf->loadHTML($this->html($invoice));

        return $pdf->output();
    }

    public function filename(SubscriptionInvoice $invoice): string
    {
        return 'invoice-'.($invoice->number ?: $invoice->id).'.pdf';
    }

    /** An inline PDF response, for any of the three apps' routes. */
    public function response(SubscriptionInvoice $invoice): \Symfony\Component\HttpFoundation\Response
    {
        return response($this->render($invoice), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$this->filename($invoice).'"']);
    }
}
