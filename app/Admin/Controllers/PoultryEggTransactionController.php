<?php

namespace App\Admin\Controllers;

use App\Models\PoultryEggTransaction;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/**
 * Tenant-scoped, farm-wide egg bookkeeping (broken eggs, home consumption,
 * manual stock adjustments) — has no batch_id, so it's simpler than the
 * other poultry event controllers. Mirrors PoultryFarmTypeController's level
 * of complexity, tenant-scoped like PoultryBatchController (PoultryEggTransaction::
 * booted() applies CompanyScope automatically for reads).
 */
class PoultryEggTransactionController extends AdminController
{
    protected $title = 'Egg Transactions';

    protected function grid()
    {
        $grid = new Grid(new PoultryEggTransaction());
        $grid->model()->orderBy('date', 'desc');

        $grid->disableBatchActions();
        $grid->quickSearch('note')->placeholder('Search by note');

        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            $filter->equal('type', 'Type')->select([
                'broken' => 'Broken',
                'consumption' => 'Consumption',
                'adjustment' => 'Adjustment',
            ]);
            $filter->between('date', 'Date')->date();
        });

        $grid->column('id', __('ID'))->sortable();
        $grid->column('type', __('Type'))->display(function ($type) {
            $labels = [
                'broken' => ['danger', 'Broken'],
                'consumption' => ['info', 'Consumption'],
                'adjustment' => ['secondary', 'Adjustment'],
            ];
            [$color, $label] = $labels[$type] ?? ['secondary', $type];

            return '<span class="badge badge-'.$color.'">'.$label.'</span>';
        });
        $grid->column('eggs', __('Eggs'))->display(function ($eggs) {
            if ($eggs < 0) {
                return '<strong class="text-danger">'.$eggs.'</strong>';
            }
            if ($eggs > 0) {
                return '<strong class="text-success">'.$eggs.'</strong>';
            }

            return $eggs;
        })->sortable();
        $grid->column('date', __('Date'))->sortable();
        $grid->column('note', __('Note'))->limit(60);
        $grid->column('created_at', __('Created'))->sortable()->hide();

        return $grid;
    }

    protected function detail($id)
    {
        $show = new Show(PoultryEggTransaction::findOrFail($id));

        $show->field('id', __('ID'));
        $show->field('type', __('Type'));
        $show->field('eggs', __('Eggs'));
        $show->field('date', __('Date'));
        $show->field('note', __('Note'));
        $show->field('created_at', __('Created At'));
        $show->field('updated_at', __('Last Updated'));

        return $show;
    }

    protected function form()
    {
        $form = new Form(new PoultryEggTransaction());

        $u = Admin::user();
        $form->hidden('company_id', __('Company'))->default($u->company_id);

        $form->divider('Egg Transaction');

        $form->select('type', __('Type'))
            ->options([
                'broken' => 'Broken',
                'consumption' => 'Consumption',
                'adjustment' => 'Adjustment',
            ])
            ->rules('required');

        $form->number('eggs', __('Eggs'))
            ->rules('required|integer')
            ->help('Positive to add, negative to subtract (adjustments only).');

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
