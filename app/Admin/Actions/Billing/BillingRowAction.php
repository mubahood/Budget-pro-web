<?php

namespace App\Admin\Actions\Billing;

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\PlatformAdminOnly;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Encore\Admin\Actions\RowAction;
use Encore\Admin\Facades\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * A platform-admin billing action on a Subscriptions row (POWER_PLAN §4.2). Actions post to
 * laravel-admin's generic action handler, so each one re-checks that the actor is a platform
 * admin; the work itself is AdminBilling, which requires a reason and writes billing_events.
 */
abstract class BillingRowAction extends RowAction
{
    abstract protected function run(Company $company, Subscription $subscription, Request $request, User $actor): string;

    public function authorize($user, $model): bool
    {
        return $user !== null && PlatformAdminOnly::isPlatformAdmin($user);
    }

    public function handle(Model $model, Request $request)
    {
        $actor = Admin::user();
        if ($actor === null || ! PlatformAdminOnly::isPlatformAdmin($actor)) {
            return $this->response()->error('Platform administrators only.');
        }
        /** @var Subscription $model */
        $company = Company::withoutGlobalScopes()->find($model->company_id);
        if ($company === null) {
            return $this->response()->error('Company not found.');
        }
        try {
            $message = $this->run($company, $model, $request, User::withoutGlobalScopes()->findOrFail($actor->id));
        } catch (BusinessRuleException $e) {
            return $this->response()->error($e->getMessage());
        }

        return $this->response()->success($message)->refresh();
    }

    protected function reasonField(): void
    {
        $this->textarea('reason', 'Reason (kept in the billing history)')->rows(2)->rules('required|min:3');
    }
}
