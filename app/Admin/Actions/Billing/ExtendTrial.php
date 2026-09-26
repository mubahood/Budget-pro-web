<?php

namespace App\Admin\Actions\Billing;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\AdminBilling;
use Illuminate\Http\Request;

class ExtendTrial extends BillingRowAction
{
    public $name = 'Extend trial';

    public function form()
    {
        $this->integer('days', 'Days to add')->default(7)->rules('required|integer|min:1|max:90');
        $this->reasonField();
    }

    protected function run(Company $company, Subscription $subscription, Request $request, User $actor): string
    {
        $sub = app(AdminBilling::class)->extendTrial($company, (int) $request->get('days'), (string) $request->get('reason'), $actor);

        return 'Trial now ends '.$sub->trial_ends_at->toFormattedDateString().'.';
    }
}
