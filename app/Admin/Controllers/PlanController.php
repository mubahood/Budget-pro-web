<?php

namespace App\Admin\Controllers;

use App\Models\Plan;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/**
 * budget-pro's own SaaS plan management — the `plans` table (introduced by
 * the SaaS billing feature) had no admin UI at all; this mirrors
 * PingPinPlanController's pattern (real switches/number inputs for the
 * feature/limit JSON columns, not a raw textarea) for the same reasons.
 */
class PlanController extends AdminController
{
    protected $title = 'Plans';

    /** Keep in sync with PlanSeeder's vocabulary. */
    private const FEATURE_KEYS = [
        'inventory' => 'Inventory',
        'sales' => 'Sales',
        'finance' => 'Finance',
        'budgets' => 'Budgets',
        'api_access' => 'API Access',
        'forecasting' => 'Forecasting',
        'auto_reorder' => 'Auto Reorder',
    ];

    private const LIMIT_KEYS = [
        'max_users' => 'Max Users',
        'max_stock_items' => 'Max Stock Items',
        'max_sales_per_month' => 'Max Sales / Month',
        'max_budget_programs' => 'Max Budget Programs',
    ];

    protected function grid()
    {
        $grid = new Grid(new Plan());
        $grid->model()->orderBy('sort_order');

        $grid->column('id', __('ID'))->sortable();
        $grid->column('name', __('Name'))->sortable();
        $grid->column('slug', __('Slug'));
        $grid->column('price', __('Price (USD)'))->display(fn ($v) => '$'.number_format((float) $v, 2));
        $grid->column('price_ugx', __('Price (UGX)'))->display(fn ($v) => number_format((float) $v, 0).' UGX');
        $grid->column('interval', __('Interval'));
        $grid->column('trial_days', __('Trial Days'))->display(fn ($v) => $v > 0 ? "{$v}d" : '—');
        $grid->column('is_active', __('Active'))->display(fn ($v) => $v
            ? '<span class="badge badge-success">Active</span>'
            : '<span class="badge badge-secondary">Retired</span>');
        $grid->column('is_public', __('Public'))->display(fn ($v) => $v ? 'Yes' : 'No (hidden)');
        $grid->column('sort_order', __('Order'))->sortable();

        return $grid;
    }

    protected function detail($id)
    {
        $plan = Plan::findOrFail($id);
        $show = new Show($plan);

        $show->field('id', __('ID'));
        $show->field('name', __('Name'));
        $show->field('slug', __('Slug'));
        $show->field('description', __('Description'));
        $show->field('price', __('Price (USD)'))->as(fn ($v) => '$'.number_format((float) $v, 2));
        $show->field('price_ugx', __('Price (UGX)'))->as(fn ($v) => number_format((float) $v, 0).' UGX');
        $show->field('currency', __('Currency'));
        $show->field('interval', __('Interval'));
        $show->field('trial_days', __('Trial Days'));
        $show->field('is_active', __('Active'))->as(fn ($v) => $v ? 'Yes' : 'No');
        $show->field('is_public', __('Public'))->as(fn ($v) => $v ? 'Yes' : 'No');

        $show->divider();

        // See PingPinPlanController::detail() — Show\Field::as() rebinds
        // $this to the MODEL, so these constants must be captured as locals
        // before the closures, not referenced via self:: inside them.
        $featureKeys = self::FEATURE_KEYS;
        $limitKeys = self::LIMIT_KEYS;

        $show->field('feature_summary', __('Features'))->unescape()->as(function () use ($plan, $featureKeys) {
            $rows = [];
            foreach ($featureKeys as $key => $label) {
                $on = $plan->allowsFeature($key);
                $rows[] = "<span class='badge badge-".($on ? 'success' : 'secondary')."' style='margin:2px;'>{$label}</span>";
            }

            return implode(' ', $rows);
        });

        $show->field('limit_summary', __('Limits'))->unescape()->as(function () use ($plan, $limitKeys) {
            $rows = [];
            foreach ($limitKeys as $key => $label) {
                $limit = $plan->limit($key);
                $rows[] = "<b>{$label}:</b> ".($limit === null ? 'Unlimited' : $limit);
            }

            return implode('<br>', $rows);
        });

        return $show;
    }

    protected function form()
    {
        $form = new Form(new Plan());

        $form->divider('Plan');
        $form->text('name', __('Name'))->rules('required|max:191');
        $form->text('slug', __('Slug'))->rules('required|alpha_dash|max:191')->help('Used by the API and billing logic — changing it after launch will break existing references.');
        $form->textarea('description', __('Description'))->rows(2);

        $form->divider('Pricing');
        $form->decimal('price', __('Price (USD)'))->rules('required|numeric|min:0')->default(0);
        $form->decimal('price_ugx', __('Price (UGX)'))->rules('required|numeric|min:0')->default(0);
        $form->text('currency', __('Currency'))->default('USD');
        $form->select('interval', __('Billing Interval'))->options(['month' => 'Monthly', 'year' => 'Yearly', 'lifetime' => 'Lifetime'])->default('month');
        $form->number('trial_days', __('Trial Days'))->default(0)->help('0 for a paid plan; 14 for the trial plan itself.');

        $form->divider('Visibility');
        $form->switch('is_active', __('Active'))->default(1)->help('Inactive plans are hidden everywhere and block new checkouts, but never affect subscriptions already on them.');
        $form->switch('is_public', __('Public'))->default(1)->help('Shown on the pricing list. Turn off for a plan assigned only automatically (like the trial).');
        $form->number('sort_order', __('Sort Order'))->default(0);

        $form->divider('Features');
        $existingFeatures = $form->model()->exists ? ($form->model()->features ?? []) : [];
        foreach (self::FEATURE_KEYS as $key => $label) {
            $form->switch("feature_{$key}", $label)->default(($existingFeatures[$key] ?? false) ? 1 : 0);
        }

        $form->divider('Limits (blank = unlimited)');
        $existingLimits = $form->model()->exists ? ($form->model()->limits ?? []) : [];
        foreach (self::LIMIT_KEYS as $key => $label) {
            $form->number("limit_{$key}", $label)->default($existingLimits[$key] ?? null);
        }

        // features/limits are single JSON columns, not real feature_*/limit_*
        // columns — ignore the virtual fields and reassemble the JSON in
        // saving(), same pattern as PingPinPlanController.
        $virtualFields = array_merge(
            array_map(fn ($k) => "feature_{$k}", array_keys(self::FEATURE_KEYS)),
            array_map(fn ($k) => "limit_{$k}", array_keys(self::LIMIT_KEYS)),
        );
        $form->ignore($virtualFields);

        $form->saving(function (Form $form) {
            $features = [];
            foreach (self::FEATURE_KEYS as $key => $label) {
                $features[$key] = (bool) request("feature_{$key}");
            }

            $limits = [];
            foreach (self::LIMIT_KEYS as $key => $label) {
                $raw = request("limit_{$key}");
                $limits[$key] = ($raw === null || $raw === '') ? null : (int) $raw;
            }

            $form->model()->features = $features;
            $form->model()->limits = $limits;
        });

        return $form;
    }
}
