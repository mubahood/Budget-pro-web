<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\BillingService;
use App\Services\Billing\Quotas;
use App\Services\Team\Permissions;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;

/** Tenant billing (plan C8, P3-6): plan, usage vs limits, plan change with proration, cancel/resume, invoices. */
class BillingController extends Controller
{
    private function company(): Company
    {
        return Company::withoutGlobalScopes()->findOrFail(Admin::user()->company_id);
    }

    public function expired(Content $content)
    {
        $company = Company::find(Admin::user()->company_id);

        return $content
            ->title('Subscription')
            ->description('Your access has expired')
            ->body(view('admin.subscription-expired', [
                'company' => $company,
                'state' => $company?->accessState() ?? 'expired',
                'endedAt' => $company?->accessEndedAt(),
                'plan' => $company?->subscription?->plan,
            ]));
    }

    public function index(Content $content)
    {
        $company = $this->company();
        $billing = app(BillingService::class);
        $plans = Plan::where('is_active', true)->where('is_public', true)->where('slug', '!=', 'trial')->orderBy('sort_order')->get();
        $quotes = [];
        foreach ($plans as $plan) {
            try {
                $quotes[$plan->id] = $plan->isFree() ? null : $billing->quote($company, $plan);
            } catch (BusinessRuleException) {
                $quotes[$plan->id] = null;
            }
        }

        return $content->title('Billing')->description('Your plan, usage and invoices')->body(view('admin.billing', [
            'company' => $company,
            'subscription' => $company->subscription,
            'state' => $company->accessState(),
            'usage' => (new Quotas())->usage($company),
            'plans' => $plans,
            'quotes' => $quotes,
            'invoices' => SubscriptionInvoice::where('company_id', $company->id)->orderByDesc('id')->limit(24)->get(),
            'canManage' => Permissions::can(Admin::user(), 'billing'),
        ]));
    }

    public function checkout(Request $request)
    {
        $this->guard();
        $plan = Plan::where('is_active', true)->where('is_public', true)->findOrFail((int) $request->input('plan_id'));
        try {
            $r = app(BillingService::class)->checkout($this->company(), Admin::user(), $plan);
        } catch (BusinessRuleException $e) {
            admin_error('Could not start payment', $e->getMessage());

            return redirect(admin_url('billing'));
        }
        if ($r['immediate']) {
            admin_success('Plan changed', 'Your unused days paid for the new plan.');

            return redirect(admin_url('billing'));
        }

        return redirect()->away($r['payment_link']);
    }

    public function cancel()
    {
        return $this->act(fn (BillingService $b, Company $c) => $b->cancel($c), 'Plan cancelled', 'You keep your plan until the end of the period, then move to the Free plan.');
    }

    public function resume()
    {
        return $this->act(fn (BillingService $b, Company $c) => $b->resume($c), 'Plan resumed', 'Your plan will renew as normal.');
    }

    public function invoice($id)
    {
        $company = $this->company();
        $invoice = SubscriptionInvoice::where('company_id', $company->id)->where('status', 'paid')->findOrFail($id);
        $pdf = app('dompdf.wrapper');
        $pdf->loadHTML(view('reports.subscription-invoice', ['invoice' => $invoice, 'company' => $company, 'plan' => Plan::find(data_get($invoice->meta, 'plan_id'))])->render());

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="invoice-'.($invoice->number ?: $invoice->id).'.pdf"']);
    }

    private function act(callable $fn, string $title, string $message)
    {
        $this->guard();
        try {
            $fn(app(BillingService::class), $this->company());
            admin_success($title, $message);
        } catch (BusinessRuleException $e) {
            admin_error('Not done', $e->getMessage());
        }

        return redirect(admin_url('billing'));
    }

    private function guard(): void
    {
        abort_unless(Permissions::can(Admin::user(), 'billing'), 403, 'Only the owner can change the plan.');
    }
}
