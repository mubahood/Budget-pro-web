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
        'multi_location' => 'Several locations',
        'whatsapp_receipts' => 'WhatsApp receipts',
        'whatsapp_automation' => 'WhatsApp automation',
    ];

    /** Local currencies a plan can be priced in besides UGX (price_ugx) and USD (price). */
    private const LOCAL_CURRENCIES = ['KES', 'TZS', 'RWF'];

    private const LIMIT_KEYS = [
        'max_users' => 'Max Users',
        'max_products' => 'Max Products',
        'max_sales_per_month' => 'Max Sales / Month (soft: warns, never blocks)',
        'max_budget_programs' => 'Max Budget Programs',
        'max_locations' => 'Max Locations',
        'max_devices' => 'Max Phones',
        'storage_mb' => 'Storage (MB)',
    ];

    protected function grid()
    {
        $grid = new Grid(new Plan());
        $grid->model()->orderBy('sort_order');

        $grid->column('id', __('ID'))->sortable();
        $grid->column('name', __('Name'))->sortable();
        $grid->column('slug', __('Slug'));
        $grid->column('price', __('Price (USD)'))->display(fn ($v) => '$'.number_format((float) $v, 2));
        $grid->column('price_ugx', __('Price ('.\App\Support\Money::symbol().')'))->display(fn ($v) => number_format((float) $v, 0).' '.\App\Support\Money::symbol().'');
        $grid->column('price_ugx_annual', __('Per year (UGX)'))->display(fn ($v, $c) => number_format((float) ($v ?: $this->price_ugx * 10), 0).($v ? '' : ' (10×)'));
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
        $show->field('price_ugx', __('Price ('.\App\Support\Money::symbol().')'))->as(fn ($v) => number_format((float) $v, 0).' '.\App\Support\Money::symbol().'');
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
        $form->decimal('price_ugx', __('Price per month (UGX)'))->rules('required|numeric|min:0')->default(0);
        $form->decimal('price_ugx_annual', __('Price per year (UGX)'))->rules('nullable|numeric|min:0')->help('Blank = 10 × the monthly price (two months free).');
        $prices = $form->model()->exists ? ($form->model()->prices ?? []) : [];
        $form->decimal('price_usd_year', __('Price per year (USD)'))->default(data_get($prices, 'USD.year'))->help('Blank = 10 × the monthly USD price.');
        foreach (self::LOCAL_CURRENCIES as $cur) {
            $entry = $prices[$cur] ?? null;
            $form->decimal("price_{$cur}_month", __("Price per month ({$cur})"))->default(is_array($entry) ? ($entry['month'] ?? null) : $entry)
                ->help("Blank = {$cur} shops pay in USD by card.");
            $form->decimal("price_{$cur}_year", __("Price per year ({$cur})"))->default(is_array($entry) ? ($entry['year'] ?? null) : null);
        }
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
            ['price_usd_year'],
            array_merge(...array_map(fn ($c) => ["price_{$c}_month", "price_{$c}_year"], self::LOCAL_CURRENCIES)),
        );
        $form->ignore($virtualFields);

        $form->saving(function (Form $form) {
            // Merge onto what the plan already has, so keys this form doesn't show are never dropped.
            $features = (array) ($form->model()->features ?? []);
            foreach (self::FEATURE_KEYS as $key => $label) {
                $features[$key] = (bool) request("feature_{$key}");
            }

            $limits = (array) ($form->model()->limits ?? []);
            foreach (self::LIMIT_KEYS as $key => $label) {
                $raw = request("limit_{$key}");
                $limits[$key] = ($raw === null || $raw === '') ? null : (int) $raw;
            }

            $num = fn ($v) => $v === null || $v === '' ? null : round((float) $v, 2);
            $prices = (array) ($form->model()->prices ?? []);
            foreach (self::LOCAL_CURRENCIES as $cur) {
                $month = $num(request("price_{$cur}_month"));
                $year = $num(request("price_{$cur}_year"));
                if ($month === null && $year === null) {
                    unset($prices[$cur]);
                } else {
                    $prices[$cur] = array_filter(['month' => $month, 'year' => $year], fn ($v) => $v !== null);
                }
            }
            $usdYear = $num(request('price_usd_year'));
            if ($usdYear === null) {
                unset($prices['USD']);
            } else {
                $prices['USD'] = ['month' => (float) request('price', 0), 'year' => $usdYear];
            }

            $form->model()->features = $features;
            $form->model()->limits = $limits;
            $form->model()->prices = $prices ?: null;
        });

        return $form;
    }
}
