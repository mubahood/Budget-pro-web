<?php

namespace App\Admin\Controllers;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/**
 * budget-pro's own SaaS subscriptions — like `plans`, the `subscriptions`
 * table had no admin UI: subscriptions were only ever created by the
 * checkout flow or PlanSeeder's backfill. This lets an admin activate,
 * change, or extend a company's subscription directly (e.g. a manual/
 * comped upgrade) without touching the database by hand.
 */
class SubscriptionController extends AdminController
{
    protected $title = 'Subscriptions';

    protected function grid()
    {
        $grid = new Grid(new Subscription());
        $grid->model()->orderBy('id', 'desc');

        $grid->column('id', __('ID'))->sortable();
        $grid->column('company.name', __('Company'));
        $grid->column('plan.name', __('Plan'));
        $grid->column('status', __('Status'))->display(function ($v) {
            $colors = [
                'active' => 'success',
                'trialing' => 'info',
                'past_due' => 'warning',
                'canceled' => 'secondary',
                'expired' => 'danger',
            ];
            $color = $colors[$v] ?? 'secondary';

            return "<span class='badge badge-{$color}'>{$v}</span>";
        })->unescape();
        $grid->column('trial_ends_at', __('Trial Ends'));
        $grid->column('starts_at', __('Starts'));
        $grid->column('ends_at', __('Ends'));
        $grid->column('billing_interval', __('Billing'))->display(fn ($v) => $v === 'year' ? 'Yearly' : 'Monthly');
        $grid->column('pending_plan_id', __('Scheduled change'))->display(fn ($v) => $v ? (Plan::find($v)?->name ?? '#'.$v).' at period end' : '—');
        $grid->column('provider', __('Provider'));

        // Billing actions go through AdminBilling: a reason is required and each is written to billing_events.
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->add(new \App\Admin\Actions\Billing\CompPlan());
            $actions->add(new \App\Admin\Actions\Billing\ExtendTrial());
            $actions->add(new \App\Admin\Actions\Billing\ExtendPeriod());
            $actions->add(new \App\Admin\Actions\Billing\RecordPayment());
            $actions->add(new \App\Admin\Actions\Billing\RefundInvoice());
        });

        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            $filter->equal('company_id', 'Company')->select(Company::pluck('name', 'id'));
            $filter->equal('plan_id', 'Plan')->select(Plan::pluck('name', 'id'));
            $filter->equal('status', 'Status')->select([
                'trialing' => 'Trialing',
                'active' => 'Active',
                'past_due' => 'Past Due',
                'canceled' => 'Canceled',
                'expired' => 'Expired',
            ]);
        });

        return $grid;
    }

    protected function detail($id)
    {
        $subscription = Subscription::findOrFail($id);
        $show = new Show($subscription);

        $show->field('id', __('ID'));
        $show->field('company.name', __('Company'));
        $show->field('plan.name', __('Plan'));
        $show->field('status', __('Status'));
        $show->field('trial_ends_at', __('Trial Ends'));
        $show->field('starts_at', __('Starts'));
        $show->field('ends_at', __('Ends'));
        $show->field('canceled_at', __('Canceled At'));
        $show->field('provider', __('Provider'));
        $show->field('provider_subscription_id', __('Provider Subscription ID'));
        $show->field('provider_customer_id', __('Provider Customer ID'));
        $show->field('billing_interval', __('Billing interval'));
        $show->field('auto_renew', __('Auto-renew'))->as(fn ($v) => $v ? 'On' : 'Off');
        $show->field('meta', __('Meta'))->as(function ($meta) {
            $meta = (array) $meta;
            if (isset($meta['card']['token'])) {
                $meta['card']['token'] = '••••'; // never show the card token
            }

            return json_encode($meta, JSON_PRETTY_PRINT);
        });

        $show->divider();
        $show->field('billing_history', __('Billing history'))->unescape()->as(function () use ($subscription) {
            $rows = \App\Models\BillingEvent::where('company_id', $subscription->company_id)->orderByDesc('id')->limit(30)->get();
            if ($rows->isEmpty()) {
                return '<span class="text-muted">No billing events yet.</span>';
            }
            $names = \App\Models\User::withoutGlobalScopes()->whereIn('id', $rows->pluck('actor_id')->filter())->pluck('name', 'id');

            return '<table class="table table-condensed"><tr><th>When</th><th>What</th><th>Who</th><th>Why</th><th>Details</th></tr>'.$rows->map(fn ($e) => '<tr><td>'.e($e->created_at?->format('d M Y H:i'))
                .'</td><td>'.e(str_replace('_', ' ', $e->action)).'</td><td>'.e($e->actor_id ? ($names[$e->actor_id] ?? '#'.$e->actor_id) : 'system').'</td><td>'.e((string) $e->reason)
                .'</td><td><code>'.e(json_encode($e->meta)).'</code></td></tr>')->implode('').'</table>';
        });

        return $show;
    }

    protected function form()
    {
        $form = new Form(new Subscription());

        $form->select('company_id', __('Company'))
            ->options(Company::pluck('name', 'id'))
            ->rules('required')
            ->required();

        $form->select('plan_id', __('Plan'))
            ->options(Plan::orderBy('sort_order')->pluck('name', 'id'))
            ->rules('required')
            ->required();

        $form->select('status', __('Status'))->options([
            'trialing' => 'Trialing',
            'active' => 'Active',
            'past_due' => 'Past Due',
            'canceled' => 'Canceled',
            'expired' => 'Expired',
        ])->default('active')->required();

        $form->datetime('trial_ends_at', __('Trial Ends At'));
        $form->datetime('starts_at', __('Starts At'));
        $form->datetime('ends_at', __('Ends At'))->help('Blank = never expires while active.');
        $form->datetime('canceled_at', __('Canceled At'));
        $form->select('billing_interval', __('Billing interval'))->options(['month' => 'Monthly', 'year' => 'Yearly'])->default('month');
        $form->html('<div class="text-muted">Prefer the row actions (comp, extend, manual payment, refund): they keep an audit trail. Raw edits here are logged as “admin_edit”.</div>');

        $form->text('provider', __('Provider'))->default('manual')->help('"manual" for an admin-activated subscription, or the payment provider name.');
        $form->text('provider_subscription_id', __('Provider Subscription ID'));
        $form->text('provider_customer_id', __('Provider Customer ID'));

        $form->saved(function (Form $form) {
            $m = $form->model();
            \App\Models\BillingEvent::record((int) $m->company_id, 'admin_edit', \Encore\Admin\Facades\Admin::user()?->id,
                $m->only(['plan_id', 'status', 'trial_ends_at', 'ends_at', 'billing_interval']), 'Edited on the Subscriptions form', $m->id);
        });

        return $form;
    }
}
