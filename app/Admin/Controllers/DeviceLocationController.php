<?php

namespace App\Admin\Controllers;

use App\Models\DeviceLocation;
use App\Models\TrackedDevice;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
use Encore\Admin\Show;
use Illuminate\Support\Facades\DB;

/**
 * Full location history across all of the company's devices — read-only
 * (a location point is an immutable log entry from the phone, never
 * hand-edited), with a device/date filter and a link into the map view.
 * TrackedDeviceController's own detail page already shows a "last 50"
 * sub-grid per device; this is the full, filterable, cross-device view.
 */
class DeviceLocationController extends AdminController
{
    protected $title = 'Location History';

    protected function grid()
    {
        $grid = new Grid(new DeviceLocation());
        $grid->model()->orderBy('recorded_at', 'desc');

        $grid->disableCreateButton();
        $grid->disableExport();
        $grid->actions(function ($actions) {
            $actions->disableEdit();
        });

        $devices = TrackedDevice::pluck('name', 'id')->all();

        $grid->filter(function ($filter) use ($devices) {
            $filter->disableIdFilter();
            $filter->equal('device_id', 'Device')->select($devices);
            $filter->between('recorded_at', 'Recorded')->datetime();
        });

        $grid->column('id', __('ID'))->sortable();
        $grid->column('device.name', __('Device'))->display(function ($name) {
            return $name ?: '—';
        });
        $grid->column('place_name', __('Place'))->display(fn ($v) => $v ?: '—');
        $grid->column('lat', __('Lat'));
        $grid->column('lng', __('Lng'));
        $grid->column('accuracy_m', __('Accuracy (m)'));
        $grid->column('activity', __('Activity'))->display(function ($a) {
            $colors = ['still' => 'secondary', 'walking' => 'info', 'running' => 'warning', 'in_vehicle' => 'danger'];
            $color = $colors[$a] ?? 'light';

            return $a ? "<span class='badge badge-$color'>$a</span>" : '—';
        });
        $grid->column('battery_pct', __('Battery'))->display(fn ($v) => $v === null ? '—' : "$v%");
        $grid->column('network', __('Network'));
        $grid->column('recorded_at', __('Recorded'))->display(function ($ms) {
            return \Carbon\Carbon::createFromTimestampMs($ms)->format('d M Y, H:i:s');
        })->sortable();
        $grid->column('map', __('Map'))->display(function () {
            return "<a href='https://www.google.com/maps?q={$this->lat},{$this->lng}' target='_blank'>"
                ."<i class='fa fa-map-marker'></i> View</a>";
        });

        return $grid;
    }

    protected function detail($id)
    {
        $location = DeviceLocation::findOrFail($id);
        $show = new Show($location);

        $show->field('id', __('ID'));
        $show->field('device.name', __('Device'));
        $show->field('place_name', __('Place'))->as(fn ($v) => $v ?: 'Not resolved yet');
        $show->field('lat', __('Latitude'));
        $show->field('lng', __('Longitude'));
        $show->field('accuracy_m', __('Accuracy (m)'));
        $show->field('altitude_m', __('Altitude (m)'));
        $show->field('speed_mps', __('Speed (m/s)'));
        $show->field('heading_deg', __('Heading (deg)'));
        $show->field('activity', __('Activity'));
        $show->field('battery_pct', __('Battery'))->as(fn ($v) => $v === null ? '—' : "$v%");
        $show->field('network', __('Network'));
        $show->field('recorded_at', __('Recorded At'))->as(function ($ms) {
            return \Carbon\Carbon::createFromTimestampMs($ms)->format('d M Y, H:i:s');
        });

        return $show;
    }

    /**
     * All of the company's devices' LAST-KNOWN position on one map —
     * the fleet overview. A plain Laravel view (not a Grid/Show/Form
     * resource), registered as a custom route in app/Admin/routes.php.
     */
    public function fleetMap(Content $content)
    {
        $devices = TrackedDevice::whereNotNull('last_lat')->whereNotNull('last_lng')->get([
            'id', 'name', 'model', 'last_lat', 'last_lng', 'last_location_name', 'last_location_at', 'last_battery_pct', 'tracking_enabled',
        ]);

        // Shaped here, not in the Blade file, so the view's @json() call is a
        // plain variable reference — a multiline ->map(function(){ return [...]; })
        // inlined inside @json(...) trips a Blade directive-parsing edge case.
        $devicesJson = $devices->map(function ($d) {
            return [
                'id' => $d->id,
                'name' => $d->name,
                'model' => $d->model,
                'lat' => (float) $d->last_lat,
                'lng' => (float) $d->last_lng,
                'placeName' => $d->last_location_name,
                'battery' => $d->last_battery_pct,
                'lastFix' => $d->last_location_at ? \Carbon\Carbon::parse($d->last_location_at)->diffForHumans() : 'unknown',
                'tracking' => (bool) $d->tracking_enabled,
                'url' => admin_url('tracked-devices/'.$d->id),
                'trailUrl' => admin_url('tracking-map/'.$d->id),
            ];
        })->values()->all();

        return $content
            ->title('Fleet Map')
            ->description('Last known location of every device')
            ->body(view('admin.tracking.fleet-map', ['devices' => $devices, 'devicesJson' => $devicesJson]));
    }

    /**
     * One device's full breadcrumb trail over a date range, drawn as a
     * connected path — not just a grid of coordinates.
     */
    public function deviceTrail($deviceId, Content $content)
    {
        $device = TrackedDevice::findOrFail($deviceId);

        $since = request('since') ? \Carbon\Carbon::parse(request('since'))->timestamp * 1000 : now()->subDay()->timestamp * 1000;
        $until = request('until') ? \Carbon\Carbon::parse(request('until'))->timestamp * 1000 : now()->timestamp * 1000;

        $points = DB::table('device_locations')
            ->where('device_id', $device->id)
            ->whereBetween('recorded_at', [$since, $until])
            ->orderBy('recorded_at')
            ->limit(2000)
            ->get(['lat', 'lng', 'place_name', 'recorded_at', 'activity', 'battery_pct']);

        $pointsJson = $points->map(function ($p) {
            return [
                'lat' => (float) $p->lat,
                'lng' => (float) $p->lng,
                'placeName' => $p->place_name,
                'recordedAt' => \Carbon\Carbon::createFromTimestampMs($p->recorded_at)->format('d M Y, H:i:s'),
                'activity' => $p->activity,
                'battery' => $p->battery_pct,
            ];
        })->values()->all();

        return $content
            ->title('Device Trail')
            ->description($device->name)
            ->body(view('admin.tracking.device-trail', [
                'device' => $device,
                'points' => $points,
                'pointsJson' => $pointsJson,
                'since' => request('since') ?: now()->subDay()->toDateString(),
                'until' => request('until') ?: now()->toDateString(),
            ]));
    }
}
