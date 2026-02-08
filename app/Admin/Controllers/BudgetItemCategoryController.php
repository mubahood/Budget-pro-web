<?php

namespace App\Admin\Controllers;

use App\Admin\Actions\Batch\BatchFixCategories;
use App\Models\BudgetItemCategory;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

class BudgetItemCategoryController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Budget Item Categories';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new BudgetItemCategory());

        $grid->quickSearch('name');
        $u = Admin::user();
        $grid->model()
            ->where('company_id', $u->company_id)
            ->orderBy('target_amount', 'desc');

        // Enable batch fix action (max 50 categories per batch)
        $grid->batchActions(function ($batch) {
            $batch->add(new BatchFixCategories());
        });
        $grid->column('id', __('Id'))->sortable();
        /*         $grid->column('created_at', __('Created at'));
        $grid->column('updated_at', __('Updated at')); */
        $grid->column('name', __('Name'))->sortable();
        $grid->column('target_amount', __('Target Amount (UGX)'))
            ->display(function ($amount) {
                return number_format($amount);
            })
            ->totalRow(function ($amount) {
                return '<strong>'.number_format($amount).'</strong>';
            })->sortable();
        $grid->column('invested_amount', __('Invested Amount (UGX)'))
            ->display(function ($amount) {
                return number_format($amount);
            })
            ->totalRow(function ($amount) {
                return '<strong>'.number_format($amount).'</strong>';
            })->sortable();
        $grid->column('balance', __('Balance'))
            ->display(function ($amount) {
                return number_format($amount);
            })
            ->totalRow(function ($amount) {
                return '<strong>'.number_format($amount).'</strong>';
            })->sortable();
        $grid->column('percentage_done', __('Percentage Done'))
            ->display(function ($amount) {
                return number_format($amount);
            })
            ->totalRow(function ($amount) {
                return '<strong>'.number_format($amount).'</strong>';
            })->sortable();
        $grid->column('is_complete', __('Is complete'));

        return $grid;
    }

    /**
     * Make a show builder.
     *
     * @param  mixed  $id
     * @return Show
     */
    protected function detail($id)
    {
        $show = new Show(BudgetItemCategory::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('created_at', __('Created at'));
        $show->field('updated_at', __('Updated at'));
        $show->field('budget_program_id', __('Budget program id'));
        $show->field('company_id', __('Company id'));
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
        $form = new Form(new BudgetItemCategory());

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
            ->required();
        $form->hidden('company_id', __('Company id'))->default($u->company_id);
        $form->text('name', __('Category Name'))->rules('required');

        $form->divider('Financial Summary (Auto-calculated from Budget Items)');

        $form->html('<div class="alert alert-info">
            <i class="fa fa-info-circle"></i>
            <strong>Note:</strong> These values are automatically calculated from child budget items. 
            They will be recalculated whenever budget items are created or updated.
        </div>');

        $form->display('target_amount', __('Target Amount (UGX)'))
            ->with(function ($value) {
                return 'UGX ' . number_format($value ?? 0);
            });

        $form->display('invested_amount', __('Invested Amount (UGX)'))
            ->with(function ($value) {
                return 'UGX ' . number_format($value ?? 0);
            });

        $form->display('balance', __('Balance (UGX)'))
            ->with(function ($value) {
                $balance = $value ?? 0;
                $color = $balance > 0 ? 'red' : 'green';
                return "<span style='color: {$color}; font-weight: bold;'>UGX " . number_format($balance) . '</span>';
            });

        $form->display('percentage_done', __('Progress'))
            ->with(function ($value) {
                return round($value ?? 0, 2) . '%';
            });

        $form->display('is_complete', __('Is Complete'));

        return $form;
    }
}
