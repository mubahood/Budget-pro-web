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
        $grid->column('provider', __('Provider'));

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
        $show->field('meta', __('Meta'))->json();

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

        $form->text('provider', __('Provider'))->default('manual')->help('"manual" for an admin-activated subscription, or the payment provider name.');
        $form->text('provider_subscription_id', __('Provider Subscription ID'));
        $form->text('provider_customer_id', __('Provider Customer ID'));

        return $form;
    }
}
