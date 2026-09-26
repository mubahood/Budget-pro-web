<?php

namespace App\Admin\Actions\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Billing\AdminBilling;
use Illuminate\Http\Request;

class RefundInvoice extends BillingRowAction
{
    public $name = 'Refund an invoice';

    public function form($row = null)
    {
        $paid = SubscriptionInvoice::where('company_id', ($row ?? $this->row)?->company_id)->where('status', 'paid')->orderByDesc('id')->limit(12)->get()
            ->mapWithKeys(fn ($i) => [$i->id => ($i->number ?: '#'.$i->id).' — '.number_format((float) $i->amount).' '.$i->currency.' ('.optional($i->paid_at)->format('d M Y').')']);
        $this->select('invoice_id', 'Invoice')->options($paid)->rules('required');
        $this->text('amount', 'Amount to refund (blank = all)')->rules('nullable|numeric|min:1');
        $this->radio('via_gateway', 'How')->options([0 => 'Already returned by hand — just record it', 1 => 'Refund through Flutterwave'])->default(0);
        $this->radio('end_access', 'Plan')->options([0 => 'Keep the plan running', 1 => 'End the plan now'])->default(0);
        $this->reasonField();
    }

    protected function run(Company $company, Subscription $subscription, Request $request, User $actor): string
    {
        $invoice = SubscriptionInvoice::where('company_id', $company->id)->find((int) $request->get('invoice_id'));
        if ($invoice === null) {
            throw BusinessRuleException::make('not_found', 'Invoice not found for this company.');
        }
        $amount = $request->get('amount');
        $invoice = app(AdminBilling::class)->refund($invoice, $amount !== null && $amount !== '' ? (float) $amount : null, (string) $request->get('reason'), $actor,
            (bool) $request->get('via_gateway'), (bool) $request->get('end_access'));

        return 'Refund of '.number_format((float) $invoice->refund_amount).' '.$invoice->currency.' recorded.';
    }
}
