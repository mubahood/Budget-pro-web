<?php

namespace App\Admin\Controllers;

use App\Models\PoultryBatch;
use App\Models\PoultryCustomer;
use App\Models\PoultrySale;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/**
 * Tenant-scoped poultry sale CRUD — web-portal alternative to the mobile
 * app's Sales screen. Mirrors FinancialCategoryController's grid/detail/form
 * structure and company scoping (PoultrySale::booted() applies CompanyScope
 * automatically for reads; writes still explicitly stamp company_id here,
 * matching every other tenant-scoped controller).
 */
class PoultrySaleController extends AdminController
{
    protected $title = 'Sales';

    protected $categories = [
        'eggs' => 'Eggs',
        'birds' => 'Birds',
        'manure' => 'Manure',
        'other' => 'Other',
    ];

    protected function grid()
    {
        $grid = new Grid(new PoultrySale());
        $grid->model()->orderBy('date', 'desc');

        $grid->disableBatchActions();
        $grid->quickSearch('product_label', 'unit', 'note')->placeholder('Search by product, unit or note');

        $categories = $this->categories;

        $grid->filter(function ($filter) use ($categories) {
            $filter->disableIdFilter();
            $filter->equal('category', 'Category')->select($categories);
            $filter->between('date', 'Date')->date();
            $filter->where(function ($q) {
                $q->whereColumn('amount_paid', '<', 'total');
            }, 'Has Balance', 'has_balance')->checkbox('Yes');
        });

        $grid->column('id', __('ID'))->sortable();
        $grid->column('category', __('Category'))->display(function ($category) use ($categories) {
            $labels = [
                'eggs' => 'info',
                'birds' => 'primary',
                'manure' => 'warning',
                'other' => 'secondary',
            ];
            $color = $labels[$category] ?? 'secondary';

            return '<span class="badge badge-'.$color.'">'.($categories[$category] ?? $category).'</span>';
        });
        $grid->column('product_label', __('Product'));
        $grid->column('qty', __('Qty'))->sortable();
        $grid->column('unit_price', __('Unit Price'))->display(function ($v) {
            return 'UGX '.number_format($v);
        });
        $grid->column('total', __('Total'))
            ->display(function ($v) {
                return 'UGX '.number_format($v);
            })
            ->totalRow(function ($v) {
                return "<strong class='text-success'>UGX ".number_format($v).'</strong>';
            })
            ->sortable();
        $grid->column('amount_paid', __('Amount Paid'))->display(function ($v) {
            return 'UGX '.number_format($v);
        });
        $grid->column('balance', __('Balance'))->display(function () {
            $color = $this->balance > 0 ? 'danger' : 'success';

            return '<span class="badge badge-'.$color.'">UGX '.number_format($this->balance).'</span>';
        });
        $grid->column('customer.name', __('Customer'))->display(function ($name) {
            return $name ?: '—';
        });
        $grid->column('date', __('Date'))->sortable();

        return $grid;
    }

    protected function detail($id)
    {
        $show = new Show(PoultrySale::findOrFail($id));

        $show->field('id', __('ID'));
        $show->field('category', __('Category'))->as(function ($category) {
            return $this->categories[$category] ?? $category;
        });
        $show->field('product_label', __('Product'));
        $show->field('qty', __('Qty'));
        $show->field('unit', __('Unit'));
        $show->field('unit_price', __('Unit Price'))->as(function ($v) {
            return 'UGX '.number_format($v);
        });
        $show->field('total', __('Total'))->as(function ($v) {
            return 'UGX '.number_format($v);
        });
        $show->field('amount_paid', __('Amount Paid'))->as(function ($v) {
            return 'UGX '.number_format($v);
        });
        $show->field('balance', __('Balance'))->as(function () {
            return 'UGX '.number_format($this->balance);
        });
        $show->field('customer.name', __('Customer'))->as(function ($name) {
            return $name ?: '—';
        });
        $show->field('batch.name', __('Batch'))->as(function ($name) {
            return $name ?: '—';
        });
        $show->field('date', __('Date'));
        $show->field('note', __('Note'));
        $show->field('created_at', __('Created At'));
        $show->field('updated_at', __('Last Updated'));

        return $show;
    }

    protected function form()
    {
        $form = new Form(new PoultrySale());

        $u = Admin::user();
        $form->hidden('company_id', __('Company'))->default($u->company_id);

        $customers = PoultryCustomer::pluck('name', 'id')->all();
        $batches = PoultryBatch::pluck('name', 'id')->all();

        $form->divider('Sale Information');

        $form->select('category', __('Category'))
            ->options($this->categories)
            ->rules('required');

        $form->text('product_label', __('Product'))
            ->placeholder('e.g. 30 trays eggs, Broiler live weight');

        $form->decimal('qty', __('Quantity'))
            ->rules('required|numeric|min:0')
            ->default(0);

        $form->text('unit', __('Unit'))
            ->placeholder('e.g. trays, kg, birds');

        $form->currency('unit_price', __('Unit Price'))
            ->symbol('UGX')
            ->rules('required|numeric|min:0');

        $form->currency('total', __('Total'))
            ->symbol('UGX')
            ->rules('required|numeric|min:0');

        $form->currency('amount_paid', __('Amount Paid'))
            ->symbol('UGX')
            ->rules('required|numeric|min:0')
            ->default(0);

        $form->select('customer_id', __('Customer'))
            ->options($customers)
            ->help('Leave blank for cash / walk-in sales with no named customer.');

        $form->select('batch_id', __('Batch'))
            ->options($batches)
            ->help('Leave blank if this sale is not tied to a specific batch.');

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
