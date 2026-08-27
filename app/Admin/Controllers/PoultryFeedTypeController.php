<?php

namespace App\Admin\Controllers;

use App\Models\PoultryFeedType;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/**
 * Tenant-scoped poultry feed type CRUD — web-portal equivalent of the mobile
 * app's Feed Types picker. Mirrors PoultryBatchController's grid/detail/form
 * structure and company scoping (PoultryFeedType::booted() applies
 * CompanyScope automatically for reads; writes stamp company_id here).
 */
class PoultryFeedTypeController extends AdminController
{
    protected $title = 'Feed Types';

    protected function grid()
    {
        $grid = new Grid(new PoultryFeedType());
        $grid->model()->orderBy('created_at', 'desc');

        $grid->disableBatchActions();
        $grid->quickSearch('name', 'category')->placeholder('Search by name or category');

        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            $filter->like('name', 'Name');
            $filter->like('category', 'Category');
        });

        $grid->column('id', __('ID'))->sortable();
        $grid->column('name', __('Name'))->sortable();
        $grid->column('category', __('Category'))->display(function ($category) {
            return $category ? '<span class="badge badge-info">'.$category.'</span>' : '—';
        });
        $grid->column('kg_per_bag', __('Kg per Bag'))->display(function ($v) {
            return number_format($v, 2).' kg';
        })->sortable();
        $grid->column('created_at', __('Created'))->sortable()->hide();

        return $grid;
    }

    protected function detail($id)
    {
        $show = new Show(PoultryFeedType::findOrFail($id));

        $show->field('id', __('ID'));
        $show->field('name', __('Name'));
        $show->field('category', __('Category'));
        $show->field('kg_per_bag', __('Kg per Bag'))->as(function ($v) {
            return number_format($v, 2).' kg';
        });
        $show->field('created_at', __('Created At'));
        $show->field('updated_at', __('Last Updated'));

        return $show;
    }

    protected function form()
    {
        $form = new Form(new PoultryFeedType());

        $u = Admin::user();
        $form->hidden('company_id', __('Company'))->default($u->company_id);

        $form->divider('Feed Type Information');

        $form->text('name', __('Name'))
            ->rules('required|max:191')
            ->placeholder('e.g. Layer Mash');

        $form->text('category', __('Category'))
            ->placeholder('e.g. starter, grower, layer_mash, broiler_finisher, other')
            ->help('Free text — common examples: starter, grower, layer_mash, broiler_finisher, other.');

        $form->decimal('kg_per_bag', __('Kg per Bag'))
            ->default(50)
            ->rules('required|numeric|min:0')
            ->help('Standard bag weight for this feed type, used to convert bags to kg.');

        $form->tools(function (Form\Tools $tools) {
            $tools->disableView();
        });

        return $form;
    }
}
