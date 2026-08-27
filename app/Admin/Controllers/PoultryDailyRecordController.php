<?php

namespace App\Admin\Controllers;

use App\Models\PoultryBatch;
use App\Models\PoultryDailyRecord;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/**
 * Tenant-scoped poultry daily record CRUD — web-portal alternative to the
 * mobile app's core daily-entry screen. Mirrors FinancialCategoryController's
 * grid/detail/form structure and company scoping (PoultryDailyRecord::booted()
 * applies CompanyScope automatically for reads; writes still explicitly
 * stamp company_id here, matching every other tenant-scoped controller).
 */
class PoultryDailyRecordController extends AdminController
{
    protected $title = 'Daily Records';

    protected function grid()
    {
        $grid = new Grid(new PoultryDailyRecord());
        $grid->model()->orderBy('date', 'desc');

        $grid->disableBatchActions();
        $grid->quickSearch('notes')->placeholder('Search by notes');

        $batches = PoultryBatch::pluck('name', 'id')->all();

        $grid->filter(function ($filter) use ($batches) {
            $filter->disableIdFilter();
            $filter->equal('batch_id', 'Batch')->select($batches);
            $filter->between('date', 'Date')->date();
        });

        $grid->column('id', __('ID'))->sortable();
        $grid->column('batch.name', __('Batch'));
        $grid->column('date', __('Date'))->sortable();
        $grid->column('eggs_trays', __('Trays'))->sortable();
        $grid->column('eggs_loose', __('Loose'))->sortable();
        $grid->column('total_eggs', __('Total Eggs'))->display(function () {
            return $this->eggs_trays * 30 + $this->eggs_loose;
        });
        $grid->column('mortality', __('Mortality'))->display(function ($v) {
            return $v > 0
                ? '<span class="badge badge-danger">'.$v.'</span>'
                : $v;
        })->sortable();
        $grid->column('feed_kg', __('Feed (kg)'))->sortable();
        $grid->column('avg_weight_kg', __('Avg Weight (kg)'))->display(function ($v) {
            return $v !== null ? $v : '—';
        });

        return $grid;
    }

    protected function detail($id)
    {
        $show = new Show(PoultryDailyRecord::findOrFail($id));

        $show->field('id', __('ID'));
        $show->field('batch.name', __('Batch'));
        $show->field('date', __('Date'));
        $show->field('eggs_trays', __('Trays'));
        $show->field('eggs_loose', __('Loose'));
        $show->field('total_eggs', __('Total Eggs'))->as(function () {
            return $this->eggs_trays * 30 + $this->eggs_loose;
        });
        $show->field('mortality', __('Mortality'));
        $show->field('feed_kg', __('Feed (kg)'));
        $show->field('water_l', __('Water (L)'));
        $show->field('egg_unit_price', __('Egg Unit Price'))->as(function ($v) {
            return 'UGX '.number_format($v);
        });
        $show->field('feed_price_per_kg', __('Feed Price/kg'))->as(function ($v) {
            return 'UGX '.number_format($v);
        });
        $show->field('avg_weight_kg', __('Avg Weight (kg)'))->as(function ($v) {
            return $v !== null ? $v : '—';
        });
        $show->field('notes', __('Notes'));
        $show->field('created_at', __('Created At'));
        $show->field('updated_at', __('Last Updated'));

        return $show;
    }

    protected function form()
    {
        $form = new Form(new PoultryDailyRecord());

        $u = Admin::user();
        $form->hidden('company_id', __('Company'))->default($u->company_id);

        $batches = PoultryBatch::pluck('name', 'id')->all();

        $form->divider('Daily Record');

        $form->select('batch_id', __('Batch'))
            ->options($batches)
            ->rules('required')
            ->help('Every daily record belongs to exactly one batch.');

        $form->date('date', __('Date'))
            ->rules(function ($form) {
                $id = $form->model()->id;

                return 'required|unique:poultry_daily_records,date,'.($id ?: 'NULL').',id,batch_id,'.request('batch_id');
            })
            ->help('Only one record allowed per batch per day.')
            ->default(date('Y-m-d'));

        $form->number('eggs_trays', __('Eggs (Trays)'))
            ->rules('required|integer|min:0')
            ->default(0);

        $form->number('eggs_loose', __('Eggs (Loose)'))
            ->rules('required|integer|min:0')
            ->default(0)
            ->help('Loose eggs not making up a full tray (30 loose = 1 tray).');

        $form->number('mortality', __('Mortality'))
            ->rules('required|integer|min:0')
            ->default(0);

        $form->decimal('feed_kg', __('Feed (kg)'))
            ->rules('required|numeric|min:0')
            ->default(0);

        $form->decimal('water_l', __('Water (L)'))
            ->rules('required|numeric|min:0')
            ->default(0);

        $form->currency('egg_unit_price', __('Egg Unit Price'))
            ->symbol('UGX')
            ->rules('required|numeric|min:0')
            ->help('Price per egg on this day.');

        $form->currency('feed_price_per_kg', __('Feed Price per kg'))
            ->symbol('UGX')
            ->rules('required|numeric|min:0');

        $form->decimal('avg_weight_kg', __('Avg Weight (kg)'))
            ->rules('nullable|numeric|min:0')
            ->help('Only relevant for broiler batches. Leave blank otherwise.');

        $form->textarea('notes', __('Notes'))->rows(3);

        $form->tools(function (Form\Tools $tools) {
            $tools->disableView();
        });

        return $form;
    }
}
