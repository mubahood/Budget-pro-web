<?php

namespace App\Admin\Controllers;

use App\Models\FinancialRecord;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

class FinancialRecordController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'FinancialRecord';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new FinancialRecord());
        $u = \Encore\Admin\Facades\Admin::user();
        
        $grid->model()->where('company_id', $u->company_id)
            ->orderBy('date', 'desc');
            
        $grid->disableBatchActions();

        $grid->filter(function ($filter) use ($u) {
            $filter->disableIdFilter();
            
            $filter->equal('financial_category_id', __('Financial Category'))
                ->select(\App\Models\FinancialCategory::where('company_id', $u->company_id)
                    ->pluck('name', 'id'));
                    
            $filter->equal('type', __('Transaction Type'))
                ->select(['Income' => 'Income', 'Expense' => 'Expense']);
                
            $filter->like('recipient', __('Recipient/Payer'));
            
            $filter->equal('payment_method', __('Payment Method'))
                ->select([
                    'Cash' => 'Cash',
                    'Mobile Money' => 'Mobile Money',
                    'Bank Transfer' => 'Bank Transfer',
                    'Check' => 'Check',
                    'Other' => 'Other'
                ]);
                
            $filter->between('date', __('Date Range'))->date();
            $filter->between('amount', __('Amount Range'))->decimal();
            
            $filter->equal('created_by_id', __('Recorded By'))
                ->select(\App\Models\User::where('company_id', $u->company_id)
                    ->pluck('name', 'id'));
        });
        
        $grid->quickSearch('recipient', 'description')->placeholder('Search recipient or description');

        $grid->column('id', __('ID'))->sortable();
        
        $grid->column('date', __('Date'))
            ->display(function ($date) {
                return date('d M Y', strtotime($date));
            })->sortable();
            
        $grid->column('type', __('Type'))
            ->using(['Income' => 'Income', 'Expense' => 'Expense'])
            ->label([
                'Income' => 'success',
                'Expense' => 'danger'
            ])->sortable()
            ->filter(['Income' => 'Income', 'Expense' => 'Expense']);
        
        $grid->column('financial_category_id', __('Category'))
            ->display(function ($financial_category_id) {
                $cat = \App\Models\FinancialCategory::find($financial_category_id);
                return $cat ? "<strong>{$cat->name}</strong>" : 'N/A';
            })->sortable();

        $grid->column('recipient', __('Recipient/Payer'))
            ->display(function ($recipient) {
                return $recipient ?? 'N/A';
            })->sortable();

        $grid->column('amount', __('Amount'))
            ->display(function ($amount) {
                $color = $this->type == 'Income' ? 'success' : 'danger';
                return "<span class='badge badge-{$color}'>UGX " . number_format($amount) . "</span>";
            })->sortable()
            ->totalRow(function ($amount) {
                return "<strong>UGX " . number_format($amount) . "</strong>";
            });
            
        $grid->column('quantity', __('Qty'))
            ->display(function ($quantity) {
                return $quantity ? number_format($quantity) : '-';
            })->sortable()->hide();

        $grid->column('payment_method', __('Payment Method'))
            ->label([
                'Cash' => 'primary',
                'Mobile Money' => 'success',
                'Bank Transfer' => 'info',
                'Check' => 'warning',
                'Other' => 'default'
            ])->sortable();

        $grid->column('description', __('Description'))
            ->display(function ($description) {
                return $description ? substr($description, 0, 50) . '...' : 'N/A';
            })->hide();

        $grid->column('receipt', __('Receipt'))
            ->display(function ($receipt) {
                if ($receipt) {
                    return "<a href='{$receipt}' target='_blank' class='btn btn-xs btn-info'>
                        <i class='fa fa-file'></i> View
                    </a>";
                }
                return '-';
            })->hide();

        $grid->column('created_by_id', __('Recorded By'))
            ->display(function ($created_by_id) {
                $user = \App\Models\User::find($created_by_id);
                return $user ? $user->name : 'N/A';
            })->sortable()->hide();

        $grid->column('created_at', __('Created'))
            ->display(function ($created_at) {
                return date('d M Y, h:i A', strtotime($created_at));
            })->sortable()->hide();

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
        $show = new Show(FinancialRecord::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('created_at', __('Created at'));
        $show->field('updated_at', __('Updated at'));
        $show->field('financial_category_id', __('Financial category id'));
        $show->field('company_id', __('Company id'));
        $show->field('user_id', __('User id'));
        $show->field('amount', __('Amount'));
        $show->field('quantity', __('Quantity'));
        $show->field('type', __('Type'));
        $show->field('payment_method', __('Payment method'));
        $show->field('recipient', __('Recipient'));
        $show->field('description', __('Description'));
        $show->field('receipt', __('Receipt'));
        $show->field('date', __('Date'));
        $show->field('financial_period_id', __('Financial period id'));
        $show->field('created_by_id', __('Created by id'));

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new FinancialRecord());
        
        $u = \Encore\Admin\Facades\Admin::user();
        
        $form->hidden('company_id')->default($u->company_id);
        $form->hidden('created_by_id')->default($u->id);
        $form->hidden('user_id')->default($u->id);
        
        $form->divider('Transaction Information');
        
        $form->select('financial_category_id', __('Financial Category'))
            ->options(\App\Models\FinancialCategory::where('company_id', $u->company_id)
                ->pluck('name', 'id'))
            ->rules('required')
            ->required()
            ->help('Select the financial category for this transaction');
        
        $form->radio('type', __('Transaction Type'))
            ->options([
                'Income' => 'Income (Money In)',
                'Expense' => 'Expense (Money Out)'
            ])
            ->rules('required')
            ->required()
            ->default('Expense')
            ->help('Is this money coming in or going out?');
        
        $form->date('date', __('Transaction Date'))
            ->default(date('Y-m-d'))
            ->rules('required|date')
            ->required()
            ->help('When did this transaction occur?');
            
        $form->divider('Amount & Quantity');
        
        $form->currency('amount', __('Amount (UGX)'))
            ->symbol('UGX')
            ->rules('required|numeric|min:0.01')
            ->required()
            ->help('Enter the transaction amount');
        
        $form->decimal('quantity', __('Quantity (Optional)'))
            ->default(1)
            ->rules('nullable|numeric|min:0')
            ->help('If this is for multiple items, enter quantity');
            
        $form->divider('Payment Details');
        
        $form->select('payment_method', __('Payment Method'))
            ->options([
                'Cash' => 'Cash',
                'Mobile Money' => 'Mobile Money',
                'Bank Transfer' => 'Bank Transfer',
                'Check' => 'Check',
                'Credit Card' => 'Credit Card',
                'Debit Card' => 'Debit Card',
                'Other' => 'Other'
            ])
            ->rules('required')
            ->required()
            ->default('Cash')
            ->help('How was this payment made?');
        
        $form->text('recipient', __('Recipient/Payer'))
            ->rules('nullable|max:255')
            ->help('Who received the money (expense) or who paid (income)?')
            ->placeholder('e.g., John Doe, ABC Suppliers Ltd');
            
        $form->divider('Additional Information');
        
        $form->textarea('description', __('Description/Notes'))
            ->rows(3)
            ->rules('nullable')
            ->help('Add any additional details about this transaction')
            ->placeholder('Purpose, invoice number, reference, etc.');
        
        $form->file('receipt', __('Receipt/Invoice'))
            ->rules('nullable|file|max:5120')
            ->help('Upload receipt or invoice (max 5MB, PDF/Image)');
        
        $form->select('financial_period_id', __('Financial Period'))
            ->options(\App\Models\FinancialPeriod::where('company_id', $u->company_id)
                ->pluck('name', 'id'))
            ->rules('nullable')
            ->help('Optionally assign to a specific financial period');

        $form->saved(function (Form $form) {
            admin_success('Success', 'Financial record saved successfully!');
        });

        return $form;
    }
}
