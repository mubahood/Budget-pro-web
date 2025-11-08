<?php

namespace App\Admin\Controllers;

use App\Models\BudgetItem;
use App\Models\BudgetItemCategory;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

class BudgetItemController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Budget Items';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new BudgetItem());

        $cats = [];
        foreach (BudgetItemCategory::all() as $key => $cat) {
            $cats[$cat->id] = $cat->name;
        }

        $grid->disableBatchActions();
        $u = Admin::user();
        $grid->model()
            ->where('company_id', $u->company_id)
            ->orderBy('target_amount', 'desc');

        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            /* $cats = [];
            $u = Admin::user();
            foreach (BudgetItemCategory::where([
                'company_id' => $u->company_id,
            ])->get() as $key => $cat) {
                $cats[$cat->id] = $cat->name_text;
            }
            $filter->equal('budget_item_category_id', 'Category')->select($cats); */
        });
        $grid->quickSearch('name')->placeholder('Search by name');
        //$grid->disableBatchActions();
        $grid->column('id', __('Id'))->sortable();
        $grid->column('created_at', __('Created'))->hide();

        $cats = [];
        $u = Admin::user();
        foreach (BudgetItemCategory::where([
            'company_id' => $u->company_id,
        ])->get() as $key => $cat) {
            $cats[$cat->id] = $cat->name_text;
        }

        $grid->column('budget_item_category_id', __('Category'))
            ->display(function ($amount) {
                if ($this->category == null) {
                    return "N/A";
                }
                return $this->category->name;
            })->sortable()
            ->filter($cats);
            
        $grid->column('name', __('Item Name'))->sortable()->editable();
        
        $grid->column('quantity', __('Quantity'))
            ->display(function ($quantity) {
                return number_format($quantity);
            })
            ->sortable()
            ->editable();
            
        $grid->column('unit_price', __('Unit Price'))
            ->display(function ($unit_price) {
                return 'UGX ' . number_format($unit_price);
            })
            ->sortable()
            ->editable();

        $grid->column('target_amount', __('Target Amount'))->display(function ($amount) {
            return 'UGX ' . number_format($amount);
        })
            ->totalRow(function ($amount) {
                return "<strong>UGX " . number_format($amount) . "</strong>";
            })->sortable();
            
        $grid->column('invested_amount', __('Amount Invested'))
            ->display(function ($amount) {
                return 'UGX ' . number_format($amount);
            })
            ->totalRow(function ($amount) {
                return "<strong>UGX " . number_format($amount) . "</strong>";
            })->sortable()->editable();
            
        $grid->column('balance', __('Balance'))->display(function ($amount) {
            $color = $amount > 0 ? 'danger' : 'success';
            return '<span class="badge badge-' . $color . '">UGX ' . number_format($amount) . '</span>';
        })
            ->totalRow(function ($amount) {
                $color = $amount > 0 ? 'danger' : 'success';
                return "<strong class='text-$color'>UGX " . number_format($amount) . "</strong>";
            })->sortable();
            
        $grid->column('percentage_done', __('Progress'))
            ->display(function ($percentage) {
                $percentage = round($percentage, 1);
                $color = 'danger';
                if ($percentage >= 75) $color = 'success';
                elseif ($percentage >= 50) $color = 'warning';
                elseif ($percentage >= 25) $color = 'info';
                
                return '<div class="progress" style="min-width: 100px;">
                    <div class="progress-bar progress-bar-' . $color . '" role="progressbar" 
                         style="width: ' . $percentage . '%" 
                         aria-valuenow="' . $percentage . '" 
                         aria-valuemin="0" 
                         aria-valuemax="100">' . $percentage . '%</div>
                </div>';
            })
            ->totalRow(function ($amount) {
                $avg = round($amount / count($this), 1);
                return "<strong>" . $avg . "%</strong>";
            })->sortable();
            
        $grid->column('is_complete', __('Status'))
            ->label([
                'Yes' => 'success',
                'No' => 'danger',
            ])->sortable()
            ->filter([
                'Yes' => 'Yes',
                'No' => 'No',
            ]);
        $grid->column('approved', __('Approved'))
            ->sortable()
            ->switch([
                'On' => 'Yes',
                'Off' => 'No',
            ])->hide(); 
        $grid->column('details', __('Remarks'))
            ->sortable()
            ->editable();


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
        $show = new Show(BudgetItem::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('created_at', __('Created at'));
        $show->field('updated_at', __('Updated at'));
        $show->field('budget_program_id', __('Budget program id'));
        $show->field('budget_item_category_id', __('Budget item category id'));
        $show->field('company_id', __('Company id'));
        $show->field('created_by_id', __('Created by id'));
        $show->field('changed_by_id', __('Changed by id'));
        $show->field('name', __('Name'));
        $show->field('target_amount', __('Target amount'));
        $show->field('invested_amount', __('Invested amount'));
        $show->field('balance', __('Balance'));
        $show->field('percentage_done', __('Percentage done'));
        $show->field('is_complete', __('Is complete'));

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new BudgetItem());


        $u = auth()->user();
        if ($u == null) {
            throw new \Exception('User not found');
        }
        $bps = \App\Models\BudgetProgram::where('company_id', $u->company_id)->orderBy('id', 'desc')->get();
        $bp = [];
        $first_id = null;
        foreach ($bps as $b) {
            $bp[$b->id] = $b->name;
            if ($first_id == null) {
                $first_id = $b->id;
            }
        }
        $form->select('budget_program_id', __('Budget Program'))->options($bp)
            ->default($first_id)
            ->rules('required')
            ->required()
            ->help('Select the budget program this item belongs to');
            
        $form->hidden('company_id', __('Company'))->default($u->company_id);
        
        $form->divider('Item Details');
        
        $form->text('name', __('Item Name'))
            ->rules('required|max:255')
            ->required()
            ->help('Enter a clear and specific item name')
            ->placeholder('e.g., Office Laptops, Monthly Salaries');
            
        $cats = [];
        $u = Admin::user();
        foreach (BudgetItemCategory::where([
            'company_id' => $u->company_id,
        ])->get() as $key => $cat) {
            $cats[$cat->id] = $cat->name;
        }
        
        $form->radio('budget_item_category_id', __('Item Category'))
            ->options($cats)
            ->rules('required')
            ->required()
            ->help('Select the category this item belongs to');
            
        $form->divider('Pricing Information');
        
        $form->decimal('quantity', __('Quantity'))
            ->default(1)
            ->rules('required|min:0')
            ->required()
            ->help('Enter the quantity needed');
            
        $form->currency('unit_price', __('Unit Price (UGX)'))
            ->symbol('UGX')
            ->rules('required|min:0')
            ->required()
            ->help('Enter the price per unit');
        
        $form->html('<div class="alert alert-info"><strong>Target Amount</strong> will be calculated automatically as: Quantity × Unit Price</div>');
        
        $form->divider('Investment Tracking');
        
        $form->currency('invested_amount', __('Amount Invested (UGX)'))
            ->symbol('UGX')
            ->default(0)
            ->rules('min:0')
            ->help('Enter the amount already invested/spent on this item');
            
        $form->textarea('details', __('Remarks/Notes'))
            ->rows(3)
            ->help('Add any additional notes or remarks about this budget item');

        $form->hidden('created_by_id', __('Created by'))->default($u->id);
        $form->hidden('changed_by_id', __('Changed by'))->default($u->id);
        $form->hidden('approved', __('Approved'))->default('No');
        $form->hidden('is_complete', __('Is complete'))->default('No');

        return $form;
    }
}
