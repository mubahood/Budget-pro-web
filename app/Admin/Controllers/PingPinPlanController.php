<?php

namespace App\Admin\Controllers;

use App\Models\PingPinPlan;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/**
 * Ping Pin's own plan management — budget-pro has NO admin UI for its own
 * `plans` table at all (confirmed during Task 1.7's research: they're
 * managed purely by a seeder + the API), so this is genuinely new ground,
 * not an extension of an existing screen. Feature flags and limits are
 * fixed, known vocabularies (matching PingPinPlanSeeder exactly) exposed as
 * real switches/number inputs — not a raw JSON textarea — so an admin can
 * create or edit a plan with no code deploy (TASKS.md 2.5's acceptance
 * criteria) and no risk of a hand-typed JSON syntax error breaking a plan.
 */
class PingPinPlanController extends AdminController
{
    protected $title = 'Ping Pin Plans';

    /** Keep in sync with PingPinPlanSeeder's vocabulary. */
    private const FEATURE_KEYS = [
        'live_location' => 'Live Location',
        'geofencing' => 'Geofencing',
        'remote_ring' => 'Remote Ring',
        'remote_lock' => 'Remote Lock',
        'remote_wipe' => 'Remote Wipe',
        'sms_fallback' => 'SMS Fallback',
        'intruder_photo' => 'Intruder Photo',
        'police_report' => 'Police Report PDF',
        'web_dashboard' => 'Web Dashboard',
    ];

    private const LIMIT_KEYS = [
        'max_devices' => 'Max Devices',
        'max_geofences' => 'Max Geofences',
        'history_retention_days' => 'History Retention (days)',
        'max_trusted_contacts' => 'Max Trusted Contacts',
    ];

    protected function grid()
    {
        $grid = new Grid(new PingPinPlan());
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
        $plan = PingPinPlan::findOrFail($id);
        $show = new Show($plan);

        $show->field('id', __('ID'));
        $show->field('name', __('Name'));
        $show->field('slug', __('Slug'));
        $show->field('description', __('Description'));
        $show->field('price', __('Price (USD)'))->as(fn ($v) => '$'.number_format((float) $v, 2));
        $show->field('price_ugx', __('Price (UGX)'))->as(fn ($v) => number_format((float) $v, 0).' UGX');
        $show->field('interval', __('Interval'));
        $show->field('trial_days', __('Trial Days'));
        $show->field('is_active', __('Active'))->as(fn ($v) => $v ? 'Yes' : 'No');
        $show->field('is_public', __('Public'))->as(fn ($v) => $v ? 'Yes' : 'No');

        $show->divider();

        // Captured into locals BEFORE the closures: Show\Field::as() rebinds
        // $this/self inside its callback to the MODEL instance (PingPinPlan),
        // not this controller — the exact same rebinding TrackedDeviceController's
        // Grid::display() closures rely on for `$this->last_lat` — so a bare
        // self::FEATURE_KEYS inside these closures resolves against the wrong
        // class entirely and throws "Undefined constant". Confirmed by a real
        // smoke-test failure before this fix, not assumed.
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
        $form = new Form(new PingPinPlan());

        $form->divider('Plan');
        $form->text('name', __('Name'))->rules('required|max:191');
        $form->text('slug', __('Slug'))->rules('required|alpha_dash|max:191')->help('Used by the API and mobile client — changing it after launch will break existing references.');
        $form->textarea('description', __('Description'))->rows(2);

        $form->divider('Pricing');
        $form->decimal('price', __('Price (USD)'))->rules('required|numeric|min:0')->default(0);
        $form->decimal('price_ugx', __('Price (UGX)'))->rules('required|numeric|min:0')->default(0);
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

        // The feature_*/limit_* fields above don't correspond to real
        // columns (features/limits are single JSON columns) — ignore them
        // so Form doesn't try to write them onto PingPinPlan directly, and
        // reassemble the JSON in saving() instead. Same pattern as
        // TrackedDeviceController's DeviceConfig virtual fields.
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
