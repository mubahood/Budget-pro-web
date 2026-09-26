<?php

namespace App\Admin\Actions\Billing;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\AdminBilling;
use Illuminate\Http\Request;

class RecordPayment extends BillingRowAction
{
    public $name = 'Record a manual payment';

    public function form()
    {
        $this->select('plan_id', 'Plan paid for')->options(Plan::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'))->rules('required');
        $this->select('interval', 'Period')->options(['month' => 'One month', 'year' => 'One year'])->default('month');
        $this->text('amount', 'Amount received')->rules('required|numeric|min:1');
        $this->text('currency', 'Currency')->default('UGX')->rules('required|size:3');
        $this->select('method', 'Paid by')->options(['cash' => 'Cash', 'bank' => 'Bank transfer', 'mobile_money' => 'Mobile money (outside Flutterwave)', 'other' => 'Other'])->default('bank');
        $this->text('reference', 'Receipt / bank reference');
        $this->reasonField();
    }

    protected function run(Company $company, Subscription $subscription, Request $request, User $actor): string
    {
        $invoice = app(AdminBilling::class)->recordManualPayment($company, Plan::findOrFail((int) $request->get('plan_id')), (string) $request->get('interval', 'month'),
            (float) $request->get('amount'), (string) $request->get('currency', 'UGX'), (string) $request->get('method', 'bank'), $request->get('reference') ?: null, (string) $request->get('reason'), $actor);

        return "Payment recorded as invoice {$invoice->number}; the plan runs until ".$invoice->period_end->toFormattedDateString().'.';
    }
}
