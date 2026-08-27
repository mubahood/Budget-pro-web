<?php

namespace App\Admin\Controllers;

use App\Models\PoultryBatch;
use App\Models\PoultryHealthEvent;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/**
 * Tenant-scoped poultry health log — web-portal equivalent of the mobile
 * app's health event logging. Mirrors PoultryBatchController's grid/detail/
 * form structure and company scoping (PoultryHealthEvent::booted() applies
 * CompanyScope automatically for reads).
 */
class PoultryHealthEventController extends AdminController
{
    protected $title = 'Health Events';

    protected function grid()
    {
        $grid = new Grid(new PoultryHealthEvent());
        $grid->model()->orderBy('date', 'desc');

        $grid->disableBatchActions();
        $grid->quickSearch('symptoms', 'diagnosis', 'note')->placeholder('Search by symptoms, diagnosis or note');

        $batches = PoultryBatch::pluck('name', 'id')->all();

        $grid->filter(function ($filter) use ($batches) {
            $filter->disableIdFilter();
            $filter->equal('batch_id', 'Batch')->select($batches);
            $filter->between('date', 'Date')->date();
        });

        $grid->column('id', __('ID'))->sortable();
        $grid->column('batch.name', __('Batch'))->label('info');
        $grid->column('symptoms', __('Symptoms'))->limit(40);
        $grid->column('diagnosis', __('Diagnosis'))->limit(40);
        $grid->column('cost', __('Cost'))->display(function ($cost) {
            return 'UGX '.number_format($cost);
        })->sortable();
        $grid->column('withdrawal_days', __('Withdrawal'))->display(function ($days) {
            return $days.' days';
        });
        $grid->column('date', __('Date'))->sortable();
        $grid->column('created_at', __('Created'))->sortable()->hide();

        return $grid;
    }

    protected function detail($id)
    {
        $show = new Show(PoultryHealthEvent::findOrFail($id));

        $show->field('id', __('ID'));
        $show->field('batch.name', __('Batch'));
        $show->field('symptoms', __('Symptoms'));
        $show->field('diagnosis', __('Diagnosis'));
        $show->field('treatment', __('Treatment'));
        $show->field('cost', __('Cost'))->as(function ($cost) {
            return 'UGX '.number_format($cost);
        });
        $show->field('withdrawal_days', __('Withdrawal Days'));
        $show->field('date', __('Date'));
        $show->field('note', __('Note'));
        $show->field('created_at', __('Created At'));
        $show->field('updated_at', __('Last Updated'));

        return $show;
    }

    protected function form()
    {
        $form = new Form(new PoultryHealthEvent());

        $u = Admin::user();
        $form->hidden('company_id', __('Company'))->default($u->company_id);

        $batches = PoultryBatch::pluck('name', 'id')->all();

        $form->divider('Health Event');

        $form->select('batch_id', __('Batch'))
            ->options($batches)
            ->rules('required')
            ->help('Which batch this health event applies to.');

        $form->textarea('symptoms', __('Symptoms'))->rows(3);

        $form->textarea('diagnosis', __('Diagnosis'))->rows(3);

        $form->textarea('treatment', __('Treatment'))->rows(3);

        $form->currency('cost', __('Cost'))
            ->symbol('UGX')
            ->rules('required|numeric|min:0')
            ->default(0);

        $form->number('withdrawal_days', __('Withdrawal Days'))
            ->rules('integer|min:0')
            ->default(0)
            ->help('Days meat/eggs should not be sold after treatment (e.g. antibiotic withdrawal period).');

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
