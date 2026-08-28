<?php

namespace App\Admin\Controllers;

use App\Models\DeviceCommand;
use App\Models\DeviceConfig;
use App\Models\TrackedDevice;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Illuminate\Http\RedirectResponse;

/**
 * Find My Phone — device list + management. Mirrors PoultryBatchController's
 * grid/detail/form/tenant-scoping pattern. Tracking interval/mode live on
 * the related DeviceConfig row, edited inline here rather than as a
 * separate admin resource — there's exactly one config per device.
 */
class TrackedDeviceController extends AdminController
{
    protected $title = 'Devices';

    protected function grid()
    {
        $grid = new Grid(new TrackedDevice());
        $grid->model()->orderBy('last_seen_at', 'desc');

        $grid->disableBatchActions();
        $grid->quickSearch('name', 'model')->placeholder('Search by name or model');

        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            $filter->equal('tracking_enabled', 'Tracking')->select([1 => 'Enabled', 0 => 'Disabled']);
            $filter->equal('platform', 'Platform')->select(['android' => 'Android', 'ios' => 'iOS']);
        });

        $grid->column('id', __('ID'))->sortable();
        $grid->column('name', __('Name'))->sortable();
        $grid->column('platform', __('Platform'))->display(fn ($p) => strtoupper($p));
        $grid->column('model', __('Model'));
        $grid->column('last_battery_pct', __('Battery'))->display(function ($v) {
            if ($v === null) return '—';
            $color = $v <= 15 ? 'danger' : ($v <= 40 ? 'warning' : 'success');

            return "<span class='badge badge-$color'>$v%</span>";
        });
        $grid->column('last_location', __('Last Location'))->display(function () {
            if ($this->last_lat === null) return 'No fix yet';

            return "<a href='https://www.google.com/maps?q={$this->last_lat},{$this->last_lng}' target='_blank'>"
                .number_format($this->last_lat, 5).', '.number_format($this->last_lng, 5).'</a>';
        });
        $grid->column('last_location_at', __('Fix Time'))->display(function ($v) {
            return $v ? \Carbon\Carbon::parse($v)->diffForHumans() : '—';
        })->sortable();
        $grid->column('last_seen_at', __('Last Synced'))->display(function ($v) {
            return $v ? \Carbon\Carbon::parse($v)->diffForHumans() : 'Never';
        })->sortable();
        $grid->column('tracking_enabled', __('Tracking'))->display(function ($v) {
            return $v
                ? '<span class="badge badge-success">Enabled</span>'
                : '<span class="badge badge-secondary">Disabled</span>';
        });

        return $grid;
    }

    protected function detail($id)
    {
        $device = TrackedDevice::findOrFail($id);
        $config = DeviceConfig::firstOrCreate(['device_id' => $device->id], ['tracking_interval_seconds' => 60, 'high_accuracy_mode' => true]);
        $show = new Show($device);

        $show->field('id', __('ID'));
        $show->field('name', __('Name'));
        $show->field('uuid', __('Device UUID'));
        $show->field('platform', __('Platform'));
        $show->field('model', __('Model'));
        $show->field('os_version', __('OS Version'));
        $show->field('app_version', __('App Version'));
        $show->field('tracking_enabled', __('Tracking'))->as(fn ($v) => $v ? 'Enabled' : 'Disabled');
        $show->field('last_battery_pct', __('Battery'))->as(fn ($v) => $v === null ? '—' : "$v%");
        $show->field('last_lat', __('Last Latitude'));
        $show->field('last_lng', __('Last Longitude'));
        $show->field('last_location_at', __('Last Fix'));
        $show->field('last_seen_at', __('Last Synced'));

        $show->divider();

        $show->html(view('admin.tracking.locate-now-button', [
            'device' => $device,
            'intervalSeconds' => $config->tracking_interval_seconds,
        ])->render());

        $show->divider();

        $show->html(
            '<b>Tracking interval:</b> '.$config->tracking_interval_seconds.'s &nbsp; '
            .'<b>High accuracy:</b> '.($config->high_accuracy_mode ? 'Yes' : 'No')
            .'<br><small class="text-muted">Edit these from the form (pencil icon above) — pulled by the '
            .'device on its next sync.</small>'
        );

        $show->divider();

        $show->locations('Recent Locations (last 50)', function ($g) {
            $g->model()->orderBy('recorded_at', 'desc')->take(50);
            $g->disableCreateButton();
            $g->disableActions();
            $g->column('lat', __('Lat'));
            $g->column('lng', __('Lng'));
            $g->column('accuracy_m', __('Accuracy (m)'));
            $g->column('activity', __('Activity'));
            $g->column('battery_pct', __('Battery'));
            $g->column('recorded_at', __('Recorded'))->display(function ($ms) {
                return \Carbon\Carbon::createFromTimestampMs($ms)->format('d M Y, H:i:s');
            });
        });

        return $show;
    }

    protected function form()
    {
        $form = new Form(new TrackedDevice());

        $u = Admin::user();
        $form->hidden('company_id', __('Company'))->default($u->company_id);
        $form->hidden('user_id', __('Owner'))->default($u->id);

        $form->divider('Device');

        $form->text('name', __('Name'))
            ->rules('required|max:191')
            ->placeholder('e.g. "Mubaraka\'s Pixel 8"');

        $form->switch('tracking_enabled', __('Tracking Enabled'))->default(1);

        $form->divider('Tracking Configuration');

        // DeviceConfig is a separate table (one-to-one) — loaded/saved
        // manually via saving()/saved() hooks below since laravel-admin's
        // Form only natively handles the primary model's own columns.
        $existingConfig = $form->model()->exists
            ? DeviceConfig::firstOrCreate(['device_id' => $form->model()->id], ['tracking_interval_seconds' => 60, 'high_accuracy_mode' => true])
            : null;

        $form->number('tracking_interval_seconds', __('Tracking Interval (seconds)'))
            ->default($existingConfig->tracking_interval_seconds ?? 60)
            ->rules('required|integer|min:15|max:3600')
            ->help('How often the device takes a GPS fix. 60s default — lower values drain battery faster.');

        $form->switch('high_accuracy_mode', __('High Accuracy Mode'))
            ->default($existingConfig->high_accuracy_mode ?? 1);

        $form->tools(function (Form\Tools $tools) {
            $tools->disableView();
        });

        $form->saved(function (Form $form) {
            $device = $form->model();
            DeviceConfig::updateOrCreate(
                ['device_id' => $device->id],
                [
                    'tracking_interval_seconds' => request('tracking_interval_seconds', 60),
                    'high_accuracy_mode' => (bool) request('high_accuracy_mode'),
                ]
            );
        });

        return $form;
    }

    /**
     * Queues an on-demand fix. The device picks this up the next time it
     * calls GET .../config (MVP poll fallback — see FIND_MY_PHONE_PLAN.md
     * §3.5; a Phase 2 FCM push would fire from here too, once added).
     */
    public function locateNow($id): RedirectResponse
    {
        $device = TrackedDevice::findOrFail($id);

        DeviceCommand::create([
            'device_id' => $device->id,
            'command' => DeviceCommand::LOCATE_NOW,
            'status' => 'pending',
        ]);

        admin_toastr('Locate Now queued — the device will fetch a fresh fix on its next sync.', 'success');

        return redirect()->back();
    }
}
