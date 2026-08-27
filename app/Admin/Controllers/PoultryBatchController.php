<?php

namespace App\Admin\Controllers;

use App\Models\PoultryBatch;
use App\Models\PoultryFarmType;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/**
 * Tenant-scoped poultry batch CRUD — full web-portal alternative to the
 * mobile app's Batches screen. Mirrors FinancialCategoryController's
 * grid/detail/form structure and company scoping (PoultryBatch::booted()
 * applies CompanyScope automatically for reads; writes still explicitly
 * stamp company_id here, matching every other tenant-scoped controller).
 */
class PoultryBatchController extends AdminController
{
    protected $title = 'Batches';

    protected function grid()
    {
        $grid = new Grid(new PoultryBatch());
        $grid->model()->orderBy('created_at', 'desc');

        $grid->disableBatchActions();
        $grid->quickSearch('name', 'notes')->placeholder('Search by name or notes');

        $farmTypes = PoultryFarmType::pluck('name', 'slug')->all();

        $grid->filter(function ($filter) use ($farmTypes) {
            $filter->disableIdFilter();
            $filter->equal('type', 'Farm Type')->select($farmTypes);
            $filter->equal('status', 'Status')->select(['active' => 'Active', 'closed' => 'Closed']);
            $filter->between('acquired_date', 'Acquired')->date();
        });

        $grid->column('id', __('ID'))->sortable();
        $grid->column('name', __('Name'))->sortable();
        $grid->column('type', __('Farm Type'))->display(function ($slug) use ($farmTypes) {
            return $farmTypes[$slug] ?? $slug;
        })->label('info');
        $grid->column('acquired_date', __('Acquired'))->sortable();
        $grid->column('start_count', __('Start Count'))->sortable();
        $grid->column('cost_per_chick', __('Cost/Chick'))->display(function ($v) {
            return 'UGX '.number_format($v);
        });
        $grid->column('acquisition_cost', __('Total Cost'))->display(function () {
            return 'UGX '.number_format($this->start_count * $this->cost_per_chick);
        });
        $grid->column('status', __('Status'))->display(function ($status) {
            return $status === 'active'
                ? '<span class="badge badge-success">Active</span>'
                : '<span class="badge badge-secondary">Closed</span>';
        });
        $grid->column('is_main_farm', __('Main Farm'))->display(function ($v) {
            return $v ? '<i class="fa fa-star text-warning"></i>' : '';
        });
        $grid->column('created_at', __('Created'))->sortable()->hide();

        return $grid;
    }

    protected function detail($id)
    {
        $show = new Show(PoultryBatch::findOrFail($id));

        $show->field('id', __('ID'));
        $show->field('name', __('Name'));
        $show->field('type', __('Farm Type'));
        $show->field('source', __('Source'));
        $show->field('acquired_date', __('Acquired Date'));
        $show->field('start_count', __('Start Count'));
        $show->field('cost_per_chick', __('Cost per Chick'))->as(function ($v) {
            return 'UGX '.number_format($v);
        });
        $show->field('status', __('Status'));
        $show->field('is_main_farm', __('Main Farm'))->as(fn ($v) => $v ? 'Yes' : 'No');
        $show->field('notes', __('Notes'));
        $show->field('created_at', __('Created At'));
        $show->field('updated_at', __('Last Updated'));

        $show->divider();

        $show->dailyRecords('Daily Records', function ($g) {
            $g->disableCreateButton();
            $g->resource('/admin/poultry-daily-records');
            $g->column('date', __('Date'));
            $g->column('eggs_trays', __('Trays'));
            $g->column('eggs_loose', __('Loose'));
            $g->column('mortality', __('Mortality'));
        });

        $show->healthEvents('Health Events', function ($g) {
            $g->disableCreateButton();
            $g->resource('/admin/poultry-health-events');
            $g->column('date', __('Date'));
            $g->column('symptoms', __('Symptoms'));
            $g->column('cost', __('Cost'));
        });

        $show->vaccinationEvents('Vaccinations', function ($g) {
            $g->disableCreateButton();
            $g->resource('/admin/poultry-vaccination-events');
            $g->column('vaccine', __('Vaccine'));
            $g->column('due_date', __('Due'));
            $g->column('done', __('Done'))->display(fn ($v) => $v ? 'Yes' : 'No');
        });

        return $show;
    }

    protected function form()
    {
        $form = new Form(new PoultryBatch());

        $u = Admin::user();
        $form->hidden('company_id', __('Company'))->default($u->company_id);

        $farmTypes = PoultryFarmType::pluck('name', 'slug')->all();

        $form->divider('Batch Information');

        $form->text('name', __('Batch Name'))
            ->rules('required|max:191')
            ->placeholder('e.g. Layers Batch 1 - Aug 2026');

        $form->select('type', __('Farm Type'))
            ->options($farmTypes)
            ->rules('required')
            ->help('Determines which stats (egg laying, etc.) apply to this batch.');

        $form->text('source', __('Source'))
            ->placeholder('e.g. Uganda Hatcheries Ltd')
            ->help('Where the chicks were acquired from.');

        $form->date('acquired_date', __('Acquired Date'))
            ->rules('required')
            ->default(date('Y-m-d'));

        $form->number('start_count', __('Start Count'))
            ->rules('required|integer|min:1')
            ->help('Number of birds at the start of this batch.');

        $form->currency('cost_per_chick', __('Cost per Chick'))
            ->symbol('UGX')
            ->rules('required|numeric|min:0');

        $form->select('status', __('Status'))
            ->options(['active' => 'Active', 'closed' => 'Closed'])
            ->default('active');

        $form->switch('is_main_farm', __('Main Farm'))
            ->default(0)
            ->help('Flags this as the primary/reference batch shown first on dashboards.');

        $form->textarea('notes', __('Notes'))->rows(3);

        $form->tools(function (Form\Tools $tools) {
            $tools->disableView();
        });

        return $form;
    }
}
