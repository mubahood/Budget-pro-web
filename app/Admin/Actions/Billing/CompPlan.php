<?php

namespace App\Admin\Actions\Billing;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\AdminBilling;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CompPlan extends BillingRowAction
{
    public $name = 'Comp a plan until…';

    public function form()
    {
        $this->select('plan_id', 'Plan')->options(Plan::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'))->rules('required');
        $this->date('until', 'Free until')->rules('required|date|after:today');
        $this->reasonField();
    }

    protected function run(Company $company, Subscription $subscription, Request $request, User $actor): string
    {
        $sub = app(AdminBilling::class)->comp($company, Plan::findOrFail((int) $request->get('plan_id')), Carbon::parse($request->get('until')), (string) $request->get('reason'), $actor);

        return "{$company->name} is on {$sub->plan->name} free until ".$sub->ends_at->toFormattedDateString().'.';
    }
}
