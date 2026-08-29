<?php

namespace App\Admin\Controllers;

use App\Models\DeviceCommand;
use App\Models\TrackedDevice;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/**
 * Audit trail for remote commands (currently just "Locate Now", queued from
 * TrackedDeviceController's detail page) — lets an admin confirm a command
 * was actually picked up and executed by the device, not just sent.
 * Read-only: creating a command is done via the "Locate Now" button, not
 * a generic form, so there's no ambiguity about what commands mean.
 */
class DeviceCommandController extends AdminController
{
    protected $title = 'Remote Commands';

    protected function grid()
    {
        $grid = new Grid(new DeviceCommand());
        $grid->model()->orderBy('created_at', 'desc');

        $grid->disableCreateButton();
        $grid->actions(function ($actions) {
            $actions->disableEdit();
        });

        $devices = TrackedDevice::pluck('name', 'id')->all();

        $grid->filter(function ($filter) use ($devices) {
            $filter->disableIdFilter();
            $filter->equal('device_id', 'Device')->select($devices);
            $filter->equal('status', 'Status')->select(['pending' => 'Pending', 'delivered' => 'Delivered', 'executed' => 'Executed']);
        });

        $grid->column('id', __('ID'))->sortable();
        $grid->column('device.name', __('Device'))->display(fn ($v) => $v ?: '—');
        $grid->column('command', __('Command'));
        $grid->column('status', __('Status'))->display(function ($status) {
            $colors = ['pending' => 'warning', 'delivered' => 'info', 'executed' => 'success'];
            $color = $colors[$status] ?? 'secondary';

            return "<span class='badge badge-$color'>$status</span>";
        });
        $grid->column('created_at', __('Requested'))->sortable();
        $grid->column('executed_at', __('Executed'))->display(fn ($v) => $v ?: '—')->sortable();

        return $grid;
    }

    protected function detail($id)
    {
        $command = DeviceCommand::findOrFail($id);
        $show = new Show($command);

        $show->field('id', __('ID'));
        $show->field('device.name', __('Device'));
        $show->field('command', __('Command'));
        $show->field('status', __('Status'));
        $show->field('created_at', __('Requested At'));
        $show->field('executed_at', __('Executed At'))->as(fn ($v) => $v ?: 'Not yet executed');

        return $show;
    }
}
