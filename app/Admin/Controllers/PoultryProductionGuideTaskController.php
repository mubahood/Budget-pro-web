<?php

namespace App\Admin\Controllers;

use App\Models\PoultryFarmType;
use App\Models\PoultryProductionGuideTask;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/**
 * Platform-wide reference data (BACKEND_API_MASTER_TASKS.md §9.3) — mirrors
 * FinancialCategoryController's grid/detail/form structure. Grid is
 * grouped/filterable by farm type per §9.3 ("no need for anything fancier
 * than what FinancialCategoryController already does").
 */
class PoultryProductionGuideTaskController extends AdminController
{
    protected $title = 'Production Guide Tasks';

    protected function grid()
    {
        $grid = new Grid(new PoultryProductionGuideTask());

        $grid->model()->orderBy('farm_type_slug', 'asc')->orderBy('days_after_start', 'asc');
        $grid->disableBatchActions();
        $grid->quickSearch('title', 'description')->placeholder('Search by title or description');

        $farmTypes = PoultryFarmType::pluck('name', 'slug')->all();

        $grid->filter(function ($filter) use ($farmTypes) {
            $filter->disableIdFilter();
            $filter->equal('farm_type_slug', 'Farm Type')->select($farmTypes);
            $filter->equal('is_active', 'Active')->select([1 => 'Yes', 0 => 'No']);
        });

        $grid->column('id', __('ID'))->sortable();
        $grid->column('farm_type_slug', __('Farm Type'))->display(function ($slug) use ($farmTypes) {
            return $farmTypes[$slug] ?? $slug;
        })->label('info');
        $grid->column('title', __('Task'))->sortable();
        $grid->column('days_after_start', __('Days After Start'))
            ->display(function ($days) {
                return $days.' day'.((int) $days === 1 ? '' : 's');
            })
            ->sortable();
        $grid->column('description', __('Description'))->limit(60);
        $grid->column('is_active', __('Active'))->display(function ($active) {
            return $active
                ? '<span class="badge badge-success">Active</span>'
                : '<span class="badge badge-secondary">Disabled</span>';
        });

        return $grid;
    }

    protected function detail($id)
    {
        $show = new Show(PoultryProductionGuideTask::findOrFail($id));

        $show->field('id', __('ID'));
        $show->field('farm_type_slug', __('Farm Type'));
        $show->field('title', __('Task'));
        $show->field('description', __('Description'));
        $show->field('days_after_start', __('Days After Start'));
        $show->field('is_active', __('Active'))->as(function ($active) {
            return $active ? 'Yes' : 'No';
        });
        $show->field('created_at', __('Created At'));
        $show->field('updated_at', __('Last Updated'));

        return $show;
    }

    protected function form()
    {
        $form = new Form(new PoultryProductionGuideTask());

        $farmTypes = PoultryFarmType::pluck('name', 'slug')->all();

        $form->divider('Guide Task');

        $form->select('farm_type_slug', __('Farm Type'))
            ->options($farmTypes)
            ->rules('required')
            ->help('Which farm type this task applies to.');

        $form->text('title', __('Task Title'))
            ->rules('required|max:191')
            ->placeholder('e.g. Switch to layers mash');

        $form->textarea('description', __('Description'))
            ->rows(3)
            ->placeholder('Shown to farmers alongside the task.');

        $form->number('days_after_start', __('Days After Start'))
            ->rules('required|integer|min:0')
            ->default(0)
            ->help('Days after the batch\'s acquired date this task becomes due — relative, not a fixed calendar date.');

        $form->switch('is_active', __('Active'))
            ->default(1)
            ->help('Disable to stop generating this task for new batches without deleting history.');

        $form->tools(function (Form\Tools $tools) {
            $tools->disableView();
        });

        return $form;
    }
}
