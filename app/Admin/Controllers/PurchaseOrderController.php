<?php

namespace App\Admin\Controllers;

use App\Models\PurchaseOrder;
use App\Models\Company;
use App\Models\User;
use App\Models\FinancialPeriod;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Encore\Admin\Facades\Admin;

class PurchaseOrderController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Purchase Orders';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new PurchaseOrder());

        $grid->column('id', __('Id'));
        $grid->column('company_id', __('Company id'));
        $grid->column('created_by_id', __('Created by id'));
        $grid->column('financial_period_id', __('Financial period id'));
        $grid->column('po_number', __('Po number'));
        $grid->column('po_date', __('Po date'));
        $grid->column('expected_delivery_date', __('Expected delivery date'));
        $grid->column('actual_delivery_date', __('Actual delivery date'));
        $grid->column('supplier_name', __('Supplier name'));
        $grid->column('supplier_email', __('Supplier email'));
        $grid->column('supplier_phone', __('Supplier phone'));
        $grid->column('supplier_address', __('Supplier address'));
        $grid->column('items', __('Items'));
        $grid->column('subtotal', __('Subtotal'));
        $grid->column('tax_amount', __('Tax amount'));
        $grid->column('shipping_cost', __('Shipping cost'));
        $grid->column('discount_amount', __('Discount amount'));
        $grid->column('total_amount', __('Total amount'));
        $grid->column('status', __('Status'));
        $grid->column('approved_by_id', __('Approved by id'));
        $grid->column('approved_at', __('Approved at'));
        $grid->column('approval_notes', __('Approval notes'));
        $grid->column('items_ordered', __('Items ordered'));
        $grid->column('items_received', __('Items received'));
        $grid->column('received_percentage', __('Received percentage'));
        $grid->column('notes', __('Notes'));
        $grid->column('terms_and_conditions', __('Terms and conditions'));
        $grid->column('reference_number', __('Reference number'));
        $grid->column('payment_terms', __('Payment terms'));
        $grid->column('created_at', __('Created at'));
        $grid->column('updated_at', __('Updated at'));
        $grid->column('deleted_at', __('Deleted at'));

        return $grid;
    }

    /**
     * Make a show builder.
     *
     * @param mixed $id
     * @return Show
     */
    protected function detail($id)
    {
        $show = new Show(PurchaseOrder::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('company_id', __('Company id'));
        $show->field('created_by_id', __('Created by id'));
        $show->field('financial_period_id', __('Financial period id'));
        $show->field('po_number', __('Po number'));
        $show->field('po_date', __('Po date'));
        $show->field('expected_delivery_date', __('Expected delivery date'));
        $show->field('actual_delivery_date', __('Actual delivery date'));
        $show->field('supplier_name', __('Supplier name'));
        $show->field('supplier_email', __('Supplier email'));
        $show->field('supplier_phone', __('Supplier phone'));
        $show->field('supplier_address', __('Supplier address'));
        $show->field('items', __('Items'));
        $show->field('subtotal', __('Subtotal'));
        $show->field('tax_amount', __('Tax amount'));
        $show->field('shipping_cost', __('Shipping cost'));
        $show->field('discount_amount', __('Discount amount'));
        $show->field('total_amount', __('Total amount'));
        $show->field('status', __('Status'));
        $show->field('approved_by_id', __('Approved by id'));
        $show->field('approved_at', __('Approved at'));
        $show->field('approval_notes', __('Approval notes'));
        $show->field('items_ordered', __('Items ordered'));
        $show->field('items_received', __('Items received'));
        $show->field('received_percentage', __('Received percentage'));
        $show->field('notes', __('Notes'));
        $show->field('terms_and_conditions', __('Terms and conditions'));
        $show->field('reference_number', __('Reference number'));
        $show->field('payment_terms', __('Payment terms'));
        $show->field('created_at', __('Created at'));
        $show->field('updated_at', __('Updated at'));
        $show->field('deleted_at', __('Deleted at'));

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new PurchaseOrder());

        $form->number('company_id', __('Company id'));
        $form->number('created_by_id', __('Created by id'));
        $form->number('financial_period_id', __('Financial period id'));
        $form->text('po_number', __('Po number'));
        $form->date('po_date', __('Po date'))->default(date('Y-m-d'));
        $form->date('expected_delivery_date', __('Expected delivery date'))->default(date('Y-m-d'));
        $form->date('actual_delivery_date', __('Actual delivery date'))->default(date('Y-m-d'));
        $form->text('supplier_name', __('Supplier name'));
        $form->text('supplier_email', __('Supplier email'));
        $form->text('supplier_phone', __('Supplier phone'));
        $form->textarea('supplier_address', __('Supplier address'));
        $form->text('items', __('Items'));
        $form->decimal('subtotal', __('Subtotal'))->default(0.00);
        $form->decimal('tax_amount', __('Tax amount'))->default(0.00);
        $form->decimal('shipping_cost', __('Shipping cost'))->default(0.00);
        $form->decimal('discount_amount', __('Discount amount'))->default(0.00);
        $form->decimal('total_amount', __('Total amount'))->default(0.00);
        $form->text('status', __('Status'))->default('draft');
        $form->number('approved_by_id', __('Approved by id'));
        $form->datetime('approved_at', __('Approved at'))->default(date('Y-m-d H:i:s'));
        $form->textarea('approval_notes', __('Approval notes'));
        $form->number('items_ordered', __('Items ordered'));
        $form->number('items_received', __('Items received'));
        $form->decimal('received_percentage', __('Received percentage'))->default(0.00);
        $form->textarea('notes', __('Notes'));
        $form->textarea('terms_and_conditions', __('Terms and conditions'));
        $form->text('reference_number', __('Reference number'));
        $form->text('payment_terms', __('Payment terms'));

        return $form;
    }
}
