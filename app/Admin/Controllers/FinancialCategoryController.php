<?php

namespace App\Admin\Controllers;

use App\Models\Company;
use App\Models\FinancialCategory;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

class FinancialCategoryController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'FinancialCategory';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new FinancialCategory());
        $u = Admin::user();

        
        if($u != null){
            $grid->model()->where('company_id', $u->company_id)
                ->orderBy('created_at', 'desc');
        }
        
        $cats = FinancialCategory::where('company_id', $u->company_id)->get();
        if($cats->count() <= 0){
            $company = $u->company;
            Company::prepare_account_categories($company->id); 
        } 

        $grid->disableBatchActions();
        $grid->quickSearch('name', 'description')->placeholder('Search by name or description');
        
        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            $filter->like('name', 'Name');
            $filter->between('created_at', 'Created')->datetime();
            $filter->where(function ($query) {
                $query->where('total_income', '>', 0);
            }, 'Has Income', 'has_income')->checkbox('Yes');
            $filter->where(function ($query) {
                $query->where('total_expense', '>', 0);
            }, 'Has Expenses', 'has_expenses')->checkbox('Yes');
        });

        $grid->column('id', __('ID'))->sortable();
        
        $grid->column('name', __('Category Name'))
            ->display(function ($name) {
                return '<strong>' . $name . '</strong>';
            })->sortable();
            
        $grid->column('description', __('Description'))
            ->limit(50)
            ->sortable();
            
        $grid->column('total_income', __('Total Income'))
            ->display(function ($amount) {
                return '<span class="badge badge-success">UGX ' . number_format($amount) . '</span>';
            })
            ->totalRow(function ($amount) {
                return "<strong class='text-success'>UGX " . number_format($amount) . "</strong>";
            })
            ->sortable();
            
        $grid->column('total_expense', __('Total Expenses'))
            ->display(function ($amount) {
                return '<span class="badge badge-danger">UGX ' . number_format($amount) . '</span>';
            })
            ->totalRow(function ($amount) {
                return "<strong class='text-danger'>UGX " . number_format($amount) . "</strong>";
            })
            ->sortable();
            
        $grid->column('balance', __('Balance'))
            ->display(function () {
                $balance = $this->total_income - $this->total_expense;
                $color = $balance >= 0 ? 'success' : 'danger';
                return '<span class="badge badge-' . $color . '">UGX ' . number_format($balance) . '</span>';
            });
            
        $grid->column('created_at', __('Created'))
            ->display(function ($created_at) {
                return date('d M Y, h:i A', strtotime($created_at));
            })
            ->sortable()
            ->hide();

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
        $show = new Show(FinancialCategory::findOrFail($id));

        $show->field('id', __('ID'));
        $show->field('name', __('Category Name'));
        $show->field('description', __('Description'));
        
        $show->field('total_income', __('Total Income'))->as(function ($total_income) {
            return 'UGX ' . number_format($total_income);
        });
        
        $show->field('total_expense', __('Total Expenses'))->as(function ($total_expense) {
            return 'UGX ' . number_format($total_expense);
        });
        
        $show->field('balance', __('Net Balance'))->as(function () {
            $balance = $this->total_income - $this->total_expense;
            return 'UGX ' . number_format($balance);
        });
        
        $show->field('created_at', __('Created At'));
        $show->field('updated_at', __('Last Updated'));

        $show->divider();
        
        // Show related budget items
        $show->budgetItems('Budget Items', function ($budgetItems) {
            $budgetItems->disableCreateButton();
            $budgetItems->resource('/admin/budget-items');
            
            $budgetItems->column('name', __('Item Name'));
            $budgetItems->column('target_amount', __('Target'))->display(function ($amount) {
                return 'UGX ' . number_format($amount);
            });
            $budgetItems->column('invested_amount', __('Invested'))->display(function ($amount) {
                return 'UGX ' . number_format($amount);
            });
            $budgetItems->column('balance', __('Balance'))->display(function ($amount) {
                return 'UGX ' . number_format($amount);
            });
        });

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new FinancialCategory());

        $u = Admin::user();
        
        $form->hidden('company_id', __('Company'))->default($u->company_id);
        
        $form->divider('Category Information');
        
        $form->text('name', __('Category Name'))
            ->rules('required|max:191')
            ->required()
            ->help('Enter a clear and descriptive category name')
            ->placeholder('e.g., Salaries, Transportation, Equipment');
            
        $form->textarea('description', __('Description'))
            ->rows(4)
            ->help('Provide detailed description of what this category covers')
            ->placeholder('Describe the purpose and scope of this financial category');
        
        $form->divider('Financial Summary (Auto-calculated)');
        
        $form->display('total_income', __('Total Income'))
            ->with(function ($value) {
                return 'UGX ' . number_format($value ?: 0);
            });
            
        $form->display('total_expense', __('Total Expenses'))
            ->with(function ($value) {
                return 'UGX ' . number_format($value ?: 0);
            });
        
        $form->display('balance', __('Net Balance'))
            ->with(function () {
                $balance = ($this->total_income ?: 0) - ($this->total_expense ?: 0);
                return 'UGX ' . number_format($balance);
            });

        // Hide these from user input (auto-calculated by observers)
        $form->hidden('total_income')->default(0);
        $form->hidden('total_expense')->default(0);
        
        $form->tools(function (Form\Tools $tools) {
            $tools->disableView();
        });

        return $form;
    }
}
