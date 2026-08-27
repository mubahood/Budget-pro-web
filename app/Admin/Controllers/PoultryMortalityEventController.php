<?php

namespace App\Admin\Controllers;

use App\Models\PoultryBatch;
use App\Models\PoultryMortalityEvent;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/**
 * Tenant-scoped poultry mortality log — web-portal equivalent of the mobile
 * app's mortality logging. Mirrors PoultryBatchController's grid/detail/form
 * structure and company scoping (PoultryMortalityEvent::booted() applies
 * CompanyScope automatically for reads).
 */
class PoultryMortalityEventController extends AdminController
{
    protected $title = 'Mortality Events';

    protected function grid()
    {
        $grid = new Grid(new PoultryMortalityEvent());
        $grid->model()->orderBy('date', 'desc');

        $grid->disableBatchActions();
        $grid->quickSearch('cause', 'note')->placeholder('Search by cause or note');

        $batches = PoultryBatch::pluck('name', 'id')->all();

        $grid->filter(function ($filter) use ($batches) {
            $filter->disableIdFilter();
            $filter->equal('batch_id', 'Batch')->select($batches);
            $filter->between('date', 'Date')->date();
        });

        $grid->column('id', __('ID'))->sortable();
        $grid->column('batch.name', __('Batch'))->label('info');
        $grid->column('count', __('Count'))->display(function ($count) {
            return $count > 2
                ? '<strong class="text-danger">'.$count.'</strong>'
                : $count;
        })->sortable();
        $grid->column('cause', __('Cause'));
        $grid->column('date', __('Date'))->sortable();
        $grid->column('created_at', __('Created'))->sortable()->hide();

        return $grid;
    }

    protected function detail($id)
    {
        $show = new Show(PoultryMortalityEvent::findOrFail($id));

        $show->field('id', __('ID'));
        $show->field('batch.name', __('Batch'));
        $show->field('count', __('Count'));
        $show->field('cause', __('Cause'));
        $show->field('photo_path', __('Photo Path'));
        $show->field('date', __('Date'));
        $show->field('note', __('Note'));
        $show->field('created_at', __('Created At'));
        $show->field('updated_at', __('Last Updated'));

        return $show;
    }

    protected function form()
    {
        $form = new Form(new PoultryMortalityEvent());

        $u = Admin::user();
        $form->hidden('company_id', __('Company'))->default($u->company_id);

        $batches = PoultryBatch::pluck('name', 'id')->all();

        $form->divider('Mortality Event');

        $form->select('batch_id', __('Batch'))
            ->options($batches)
            ->rules('required')
            ->help('Which batch these birds died in.');

        $form->number('count', __('Count'))
            ->rules('required|integer|min:1')
            ->default(1)
            ->help('Number of birds that died in this event.');

        $form->text('cause', __('Cause'))
            ->placeholder('e.g. heat stress, disease, predator');

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
