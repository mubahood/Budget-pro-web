<?php

namespace App\Admin\Controllers;

use App\Models\PoultryCustomer;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

/**
 * Tenant-scoped poultry customer CRUD — web-portal alternative to the
 * mobile app's Customers screen. Mirrors FinancialCategoryController's
 * grid/detail/form structure and company scoping (PoultryCustomer::booted()
 * applies CompanyScope automatically for reads; writes still explicitly
 * stamp company_id here, matching every other tenant-scoped controller).
 */
class PoultryCustomerController extends AdminController
{
    protected $title = 'Customers';

    protected function grid()
    {
        $grid = new Grid(new PoultryCustomer());
        $grid->model()->orderBy('created_at', 'desc');

        $grid->disableBatchActions();
        $grid->quickSearch('name', 'phone', 'notes')->placeholder('Search by name, phone or notes');

        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            $filter->like('name', 'Name');
            $filter->like('phone', 'Phone');
        });

        $grid->column('id', __('ID'))->sortable();
        $grid->column('name', __('Name'))->sortable();
        $grid->column('phone', __('Phone'));
        $grid->column('notes', __('Notes'))->limit(60);
        $grid->column('sales_count', __('Sales'))->display(function () {
            return $this->sales()->count();
        });
        $grid->column('created_at', __('Created'))
            ->display(function ($created_at) {
                return date('d M Y, h:i A', strtotime($created_at));
            })
            ->sortable()
            ->hide();

        return $grid;
    }

    protected function detail($id)
    {
        $show = new Show(PoultryCustomer::findOrFail($id));

        $show->field('id', __('ID'));
        $show->field('name', __('Name'));
        $show->field('phone', __('Phone'));
        $show->field('notes', __('Notes'));
        $show->field('created_at', __('Created At'));
        $show->field('updated_at', __('Last Updated'));

        $show->divider();

        $show->sales('Sales', function ($g) {
            $g->disableCreateButton();
            $g->resource('/admin/poultry-sales');
            $g->column('date', __('Date'));
            $g->column('product_label', __('Product'));
            $g->column('total', __('Total'))->display(function ($v) {
                return 'UGX '.number_format($v);
            });
        });

        return $show;
    }

    protected function form()
    {
        $form = new Form(new PoultryCustomer());

        $u = Admin::user();
        $form->hidden('company_id', __('Company'))->default($u->company_id);

        $form->divider('Customer Information');

        $form->text('name', __('Name'))
            ->rules('required|max:191')
            ->placeholder('e.g. John Mukasa');

        $form->text('phone', __('Phone'))
            ->rules('nullable|max:32')
            ->placeholder('e.g. 0772123456');

        $form->textarea('notes', __('Notes'))->rows(3);

        $form->tools(function (Form\Tools $tools) {
            $tools->disableView();
        });

        return $form;
    }
}
