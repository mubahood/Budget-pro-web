<?php

namespace App\Admin\Controllers;

use App\Models\PoultryBatch;
use App\Models\PoultryVaccinationEvent;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/**
 * Tenant-scoped poultry vaccination schedule — web-portal equivalent of the
 * mobile app's vaccination tracking. Mirrors PoultryBatchController's
 * grid/detail/form structure and company scoping (PoultryVaccinationEvent::
 * booted() applies CompanyScope automatically for reads).
 */
class PoultryVaccinationEventController extends AdminController
{
    protected $title = 'Vaccinations';

    protected function grid()
    {
        $grid = new Grid(new PoultryVaccinationEvent());
        $grid->model()->orderBy('due_date', 'desc');

        $grid->disableBatchActions();
        $grid->quickSearch('vaccine', 'method', 'note')->placeholder('Search by vaccine, method or note');

        $batches = PoultryBatch::pluck('name', 'id')->all();

        $grid->filter(function ($filter) use ($batches) {
            $filter->disableIdFilter();
            $filter->equal('batch_id', 'Batch')->select($batches);
            $filter->equal('done', 'Status')->select([1 => 'Done', 0 => 'Pending']);
        });

        $grid->column('id', __('ID'))->sortable();
        $grid->column('batch.name', __('Batch'))->label('info');
        $grid->column('vaccine', __('Vaccine'));
        $grid->column('due_date', __('Due Date'))->sortable();
        $grid->column('done', __('Done'))->display(function ($done) {
            return $done
                ? '<span class="badge badge-success">Done</span>'
                : '<span class="badge badge-warning">Pending</span>';
        });
        $grid->column('done_date', __('Done Date'))->display(function ($doneDate) {
            return $doneDate ?: '—';
        });
        $grid->column('status', __('Status'))->display(function () {
            if (! $this->done && $this->due_date && $this->due_date->lt(today())) {
                return '<strong class="text-danger">Overdue</strong>';
            }

            return $this->done ? 'Done' : 'Upcoming';
        });
        $grid->column('created_at', __('Created'))->sortable()->hide();

        return $grid;
    }

    protected function detail($id)
    {
        $show = new Show(PoultryVaccinationEvent::findOrFail($id));

        $show->field('id', __('ID'));
        $show->field('batch.name', __('Batch'));
        $show->field('vaccine', __('Vaccine'));
        $show->field('method', __('Method'));
        $show->field('age_days', __('Age (Days)'));
        $show->field('withdrawal_days', __('Withdrawal Days'));
        $show->field('due_date', __('Due Date'));
        $show->field('done', __('Done'))->as(fn ($v) => $v ? 'Yes' : 'No');
        $show->field('done_date', __('Done Date'));
        $show->field('note', __('Note'));
        $show->field('created_at', __('Created At'));
        $show->field('updated_at', __('Last Updated'));

        return $show;
    }

    protected function form()
    {
        $form = new Form(new PoultryVaccinationEvent());

        $u = Admin::user();
        $form->hidden('company_id', __('Company'))->default($u->company_id);

        $batches = PoultryBatch::pluck('name', 'id')->all();

        $form->divider('Vaccination Schedule');

        $form->select('batch_id', __('Batch'))
            ->options($batches)
            ->rules('required')
            ->help('Which batch this vaccination applies to.');

        $form->text('vaccine', __('Vaccine'))
            ->rules('required|max:191')
            ->placeholder('e.g. Newcastle Disease (NDV), Gumboro (IBD), Marek\'s');

        $form->text('method', __('Method'))
            ->placeholder('e.g. eye drop, drinking water, injection');

        $form->number('age_days', __('Age (Days)'))
            ->rules('integer|min:0')
            ->default(0)
            ->help('Bird age in days when this vaccination is due.');

        $form->number('withdrawal_days', __('Withdrawal Days'))
            ->rules('integer|min:0')
            ->default(0);

        $form->date('due_date', __('Due Date'))
            ->rules('required')
            ->default(date('Y-m-d'));

        $form->switch('done', __('Done'))
            ->default(0);

        $form->date('done_date', __('Done Date'))
            ->help('Only relevant when marked Done above.');

        $form->textarea('note', __('Note'))->rows(3);

        $form->tools(function (Form\Tools $tools) {
            $tools->disableView();
        });

        return $form;
    }
}
