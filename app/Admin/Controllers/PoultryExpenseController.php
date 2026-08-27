<?php

namespace App\Admin\Controllers;

use App\Models\PoultryBatch;
use App\Models\PoultryExpense;
use App\Models\PoultryFeedType;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/**
 * Tenant-scoped poultry expense CRUD — web-portal equivalent of the mobile
 * app's Expenses ledger. Mirrors PoultryBatchController's grid/detail/form
 * structure and company scoping (PoultryExpense::booted() applies
 * CompanyScope automatically for reads; writes stamp company_id here).
 */
class PoultryExpenseController extends AdminController
{
    protected $title = 'Expenses';

    const CATEGORIES = [
        'feed' => 'Feed',
        'medicine' => 'Medicine',
        'labor' => 'Labor',
        'utilities' => 'Utilities',
        'other' => 'Other',
    ];

    protected function grid()
    {
        $grid = new Grid(new PoultryExpense());
        $grid->model()->orderBy('date', 'desc');

        $grid->disableBatchActions();
        $grid->quickSearch('note')->placeholder('Search by note');

        $categories = self::CATEGORIES;

        $grid->filter(function ($filter) use ($categories) {
            $filter->disableIdFilter();
            $filter->equal('category', 'Category')->select($categories);
            $filter->between('date', 'Date')->date();
        });

        $grid->column('id', __('ID'))->sortable();
        $grid->column('category', __('Category'))->display(function ($category) use ($categories) {
            $label = $categories[$category] ?? $category;

            return '<span class="badge badge-info">'.$label.'</span>';
        });
        $grid->column('feedType.name', __('Feed Type'))->display(function ($name) {
            return $name ?: '—';
        });
        $grid->column('amount', __('Amount'))
            ->display(function ($v) {
                return 'UGX '.number_format($v);
            })
            ->totalRow(function ($v) {
                return "<strong class='text-danger'>UGX ".number_format($v).'</strong>';
            })
            ->sortable();
        $grid->column('batch.name', __('Batch'))->display(function ($name) {
            return $name ?: '—';
        });
        $grid->column('date', __('Date'))->sortable();
        $grid->column('created_at', __('Created'))->sortable()->hide();

        return $grid;
    }

    protected function detail($id)
    {
        $show = new Show(PoultryExpense::findOrFail($id));

        $categories = self::CATEGORIES;

        $show->field('id', __('ID'));
        $show->field('category', __('Category'))->as(function ($category) use ($categories) {
            return $categories[$category] ?? $category;
        });
        $show->field('feedType.name', __('Feed Type'));
        $show->field('amount', __('Amount'))->as(function ($v) {
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
        $form = new Form(new PoultryExpense());

        $u = Admin::user();
        $form->hidden('company_id', __('Company'))->default($u->company_id);

        $feedTypes = PoultryFeedType::pluck('name', 'id')->all();
        $batches = PoultryBatch::pluck('name', 'id')->all();

        $form->divider('Expense Information');

        $form->select('category', __('Category'))
            ->options(self::CATEGORIES)
            ->rules('required')
            ->default('other');

        $form->select('feed_type_id', __('Feed Type'))
            ->options(['' => '— None —'] + $feedTypes)
            ->help('Optional — only relevant when category is Feed.');

        $form->currency('amount', __('Amount'))
            ->symbol('UGX')
            ->default(0)
            ->rules('required|numeric|min:0');

        $form->select('batch_id', __('Batch'))
            ->options(['' => '— None —'] + $batches)
            ->help('Optional — link this expense to a specific batch.');

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
