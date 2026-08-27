<?php

namespace App\Admin\Controllers;

use App\Models\PoultryBatch;
use App\Models\PoultryFeedStock;
use App\Models\PoultryFeedType;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/**
 * Tenant-scoped poultry feed stock (in/out movements) CRUD — web-portal
 * equivalent of the mobile app's Feed Stock ledger. Mirrors
 * PoultryBatchController's grid/detail/form structure and company scoping
 * (PoultryFeedStock::booted() applies CompanyScope automatically for reads;
 * writes stamp company_id here).
 */
class PoultryFeedStockController extends AdminController
{
    protected $title = 'Feed Stock';

    protected function grid()
    {
        $grid = new Grid(new PoultryFeedStock());
        $grid->model()->orderBy('date', 'desc');

        $grid->disableBatchActions();
        $grid->quickSearch('note', 'source')->placeholder('Search by note or source');

        $feedTypes = PoultryFeedType::pluck('name', 'id')->all();

        $grid->filter(function ($filter) use ($feedTypes) {
            $filter->disableIdFilter();
            $filter->equal('direction', 'Direction')->select(['in' => 'In', 'out' => 'Out']);
            $filter->equal('feed_type_id', 'Feed Type')->select($feedTypes);
            $filter->between('date', 'Date')->date();
        });

        $grid->column('id', __('ID'))->sortable();
        $grid->column('feedType.name', __('Feed Type'));
        $grid->column('direction', __('Direction'))->display(function ($direction) {
            return $direction === 'in'
                ? '<span class="badge badge-success">In</span>'
                : '<span class="badge badge-danger">Out</span>';
        });
        $grid->column('qty_kg', __('Qty (kg)'))->display(function ($v) {
            return number_format($v, 2);
        })->sortable();
        $grid->column('cost', __('Cost'))->display(function ($v) {
            return 'UGX '.number_format($v);
        })->sortable();
        $grid->column('batch.name', __('Batch'))->display(function ($name) {
            return $name ?: '—';
        });
        $grid->column('date', __('Date'))->sortable();
        $grid->column('created_at', __('Created'))->sortable()->hide();

        return $grid;
    }

    protected function detail($id)
    {
        $show = new Show(PoultryFeedStock::findOrFail($id));

        $show->field('id', __('ID'));
        $show->field('feedType.name', __('Feed Type'));
        $show->field('direction', __('Direction'));
        $show->field('source', __('Source'));
        $show->field('qty_kg', __('Qty (kg)'));
        $show->field('cost', __('Cost'))->as(function ($v) {
            return 'UGX '.number_format($v);
        });
        $show->field('batch.name', __('Batch'));
        $show->field('date', __('Date'));
        $show->field('note', __('Note'));
        $show->field('created_at', __('Created At'));
        $show->field('updated_at', __('Last Updated'));

        return $show;
    }

    protected function form()
    {
        $form = new Form(new PoultryFeedStock());

        $u = Admin::user();
        $form->hidden('company_id', __('Company'))->default($u->company_id);

        $feedTypes = PoultryFeedType::pluck('name', 'id')->all();
        $batches = PoultryBatch::pluck('name', 'id')->all();

        $form->divider('Feed Movement');

        $form->select('feed_type_id', __('Feed Type'))
            ->options($feedTypes)
            ->rules('required')
            ->help('Which feed type moved.');

        $form->select('direction', __('Direction'))
            ->options(['in' => 'In', 'out' => 'Out'])
            ->rules('required')
            ->default('in');

        $form->select('source', __('Source'))
            ->options(['purchase' => 'Purchase', 'consumption' => 'Consumption', 'adjustment' => 'Adjustment'])
            ->help('Optional — why this movement happened.');

        $form->decimal('qty_kg', __('Qty (kg)'))
            ->rules('required|numeric|min:0');

        $form->currency('cost', __('Cost'))
            ->symbol('UGX')
            ->default(0)
            ->rules('required|numeric|min:0');

        $form->select('batch_id', __('Batch'))
            ->options(['' => '— None —'] + $batches)
            ->help('Optional — link this movement to a specific batch.');

        $form->date('date', __('Date'))
            ->rules('required')
            ->default(date('Y-m-d'));

        $form->textarea('note', __('Note'))->rows(3);

        $form->tools(function (Form\Tools $tools) {
            $tools->disableView();
        });

        return $form;
    }
}
