<?php

namespace App\Admin\Controllers;

use App\Models\PoultryFarmType;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/**
 * Platform-wide reference data (BACKEND_API_MASTER_TASKS.md §9.3) — mirrors
 * FinancialCategoryController's grid/detail/form structure, minus the
 * per-company scoping that doesn't apply here (every farm sees the same
 * types).
 */
class PoultryFarmTypeController extends AdminController
{
    protected $title = 'Farm Types';

    protected function grid()
    {
        $grid = new Grid(new PoultryFarmType());

        $grid->model()->orderBy('name', 'asc');
        $grid->disableBatchActions();
        $grid->quickSearch('slug', 'name', 'description')->placeholder('Search by slug, name or description');

        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            $filter->like('name', 'Name');
            $filter->equal('is_active', 'Active')->select([1 => 'Yes', 0 => 'No']);
        });

        $grid->column('id', __('ID'))->sortable();
        $grid->column('slug', __('Slug'))->display(function ($slug) {
            return '<code>'.$slug.'</code>';
        });
        $grid->column('name', __('Name'))->sortable();
        $grid->column('description', __('Description'))->limit(60);
        $grid->column('guide_tasks_count', __('Guide Tasks'))->display(function () {
            return $this->guideTasks()->count();
        });
        $grid->column('is_active', __('Active'))->display(function ($active) {
            return $active
                ? '<span class="badge badge-success">Active</span>'
                : '<span class="badge badge-secondary">Disabled</span>';
        });
        $grid->column('created_at', __('Created'))->display(function ($created_at) {
            return date('d M Y, h:i A', strtotime($created_at));
        })->sortable()->hide();

        return $grid;
    }

    protected function detail($id)
    {
        $show = new Show(PoultryFarmType::findOrFail($id));

        $show->field('id', __('ID'));
        $show->field('slug', __('Slug'));
        $show->field('name', __('Name'));
        $show->field('description', __('Description'));
        $show->field('is_active', __('Active'))->as(function ($active) {
            return $active ? 'Yes' : 'No';
        });
        $show->field('created_at', __('Created At'));
        $show->field('updated_at', __('Last Updated'));

        $show->divider();

        $show->guideTasks('Production Guide Tasks', function ($tasks) {
            $tasks->disableCreateButton();
            $tasks->resource('/admin/poultry-production-guide-tasks');
            $tasks->column('title', __('Title'));
            $tasks->column('days_after_start', __('Days After Start'));
            $tasks->column('is_active', __('Active'))->display(function ($active) {
                return $active ? 'Yes' : 'No';
            });
        });

        return $show;
    }

    protected function form()
    {
        $form = new Form(new PoultryFarmType());

        $form->divider('Farm Type');

        $form->text('slug', __('Slug'))
            ->rules(function ($form) {
                $id = $form->model()->id;

                return 'required|max:64|alpha_dash|unique:poultry_farm_types,slug,'.($id ?: 'NULL').',id';
            })
            ->help('Lowercase, no spaces — must match the mobile app\'s Batch.type value exactly (e.g. "layer", "broiler"). Cannot be changed once batches of this type exist on a farm.')
            ->placeholder('e.g. layer');

        $form->text('name', __('Display Name'))
            ->rules('required|max:191')
            ->placeholder('e.g. Layers');

        $form->textarea('description', __('Description'))
            ->rows(3)
            ->placeholder('Shown to farmers in the guide viewer.');

        $form->switch('is_active', __('Active'))
            ->default(1)
            ->help('Disable to hide from new-batch pickers without deleting existing history.');

        $form->tools(function (Form\Tools $tools) {
            $tools->disableView();
        });

        return $form;
    }
}
