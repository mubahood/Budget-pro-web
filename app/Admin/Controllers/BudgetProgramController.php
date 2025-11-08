<?php

namespace App\Admin\Controllers;

use App\Models\BudgetProgram;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

class BudgetProgramController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Budget Programs';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new BudgetProgram());
        $grid->disableBatchActions();
        
        $u = Admin::user();
        $grid->model()
            ->where('company_id', $u->company_id)
            ->orderBy('created_at', 'desc');
            
        $grid->filter(function ($filter) {
            $filter->like('name', __('Program Name'));
            $filter->equal('status', __('Status'))
                ->select(['Active' => 'Active', 'Inactive' => 'Inactive']);
            $filter->between('deadline', __('Deadline'))->date();
            $filter->between('created_at', __('Date Created'))->datetime();
            $filter->disableIdFilter();
        });
        
        $grid->quickSearch('name', 'title')->placeholder('Search by name or title');
        
        $grid->column('id', __('ID'))->sortable();
        
        $grid->column('name', __('Program Name'))
            ->display(function ($name) {
                return "<strong>{$name}</strong>";
            })->sortable();
            
        $grid->column('status', __('Status'))
            ->using(['Active' => 'Active', 'Inactive' => 'Inactive'])
            ->label([
                'Active' => 'success',
                'Inactive' => 'default'
            ])->sortable()
            ->filter(['Active' => 'Active', 'Inactive' => 'Inactive']);
            
        $grid->column('deadline', __('Deadline'))
            ->display(function ($deadline) {
                if ($deadline) {
                    $date = date('d M Y', strtotime($deadline));
                    $isPast = strtotime($deadline) < time();
                    $color = $isPast ? 'danger' : 'success';
                    return "<span class='badge badge-{$color}'>{$date}</span>";
                }
                return 'N/A';
            })->sortable();
            
        $grid->column('total_expected', __('Expected'))
            ->display(function ($total_expected) {
                return '<span class="badge badge-primary">UGX ' . number_format($total_expected) . '</span>';
            })->sortable()
            ->totalRow(function ($amount) {
                return "<strong>UGX " . number_format($amount) . "</strong>";
            });
            
        $grid->column('total_collected', __('Collected'))
            ->display(function ($total_collected) {
                return '<span class="badge badge-success">UGX ' . number_format($total_collected) . '</span>';
            })->sortable()
            ->totalRow(function ($amount) {
                return "<strong>UGX " . number_format($amount) . "</strong>";
            });
            
        $grid->column('total_in_pledge', __('Pending'))
            ->display(function ($total_in_pledge) {
                return '<span class="badge badge-warning">UGX ' . number_format($total_in_pledge) . '</span>';
            })->sortable()
            ->totalRow(function ($amount) {
                return "<strong>UGX " . number_format($amount) . "</strong>";
            });
            
        $grid->column('budget_total', __('Budget'))
            ->display(function ($budget_total) {
                return '<span class="badge badge-info">UGX ' . number_format($budget_total) . '</span>';
            })->sortable()
            ->totalRow(function ($amount) {
                return "<strong>UGX " . number_format($amount) . "</strong>";
            });
            
        $grid->column('budget_spent', __('Spent'))
            ->display(function ($budget_spent) {
                return '<span class="badge badge-danger">UGX ' . number_format($budget_spent) . '</span>';
            })->sortable()
            ->totalRow(function ($amount) {
                return "<strong>UGX " . number_format($amount) . "</strong>";
            });
            
        $grid->column('budget_balance', __('Balance'))
            ->display(function ($budget_balance) {
                $color = $budget_balance >= 0 ? 'success' : 'danger';
                return "<span class='badge badge-{$color}'>UGX " . number_format($budget_balance) . '</span>';
            })->sortable()
            ->totalRow(function ($amount) {
                $color = $amount >= 0 ? 'success' : 'danger';
                return "<strong class='text-{$color}'>UGX " . number_format($amount) . "</strong>";
            });

        $grid->column('created_at', __('Created'))
            ->display(function ($created_at) {
                return date('d M Y, h:i A', strtotime($created_at));
            })->sortable()->hide();

        $grid->column('print', __('Actions'))
            ->display(function () {
                $link = url('budget-program-print?id=' . $this->id);
                return "<a href='{$link}' target='_blank' class='btn btn-xs btn-primary'>
                    <i class='fa fa-print'></i> Print Report
                </a>";
            });

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
        $show = new Show(BudgetProgram::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('created_at', __('Created at'));
        $show->field('updated_at', __('Updated at'));
        $show->field('company_id', __('Company id'));
        $show->field('name', __('Name'));
        $show->field('total_collected', __('Total collected'));
        $show->field('total_expected', __('Total expected'));
        $show->field('total_in_pledge', __('Total in pledge'));
        $show->field('budget_total', __('Budget total'));
        $show->field('budget_spent', __('Budget spent'));
        $show->field('budget_balance', __('Budget balance'));

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new BudgetProgram());
        
        $u = Admin::user();
        $form->hidden('company_id')->default($u->company_id);
        
        $form->divider('Program Information');
        
        $form->text('name', __('Program Name'))
            ->rules('required|max:255')
            ->required()
            ->help('Enter a unique name for this budget program')
            ->placeholder('e.g., Annual Fundraising 2025');
            
        $form->text('title', __('Budget Title'))
            ->rules('required|max:255')
            ->required()
            ->help('Enter the official title for printed reports')
            ->placeholder('e.g., Church Building Fund 2025');
            
        $form->divider('Program Settings');
        
        $form->radio('status', __('Program Status'))
            ->options([
                'Active' => 'Active (Accepting Contributions)',
                'Inactive' => 'Inactive (Closed)'
            ])
            ->rules('required')
            ->required()
            ->default('Active')
            ->help('Active programs can accept new contributions');
            
        $form->date('deadline', __('Target Deadline'))
            ->rules('nullable|date')
            ->help('Set the target completion date for this program');
            
        $form->radio('is_default', __('Set as Default Program'))
            ->options([
                'Yes' => 'Yes (Use by default)',
                'No' => 'No'
            ])
            ->rules('required')
            ->required()
            ->default('No')
            ->help('The default program will be pre-selected when creating new records');
            
        $form->divider('RSVP & Communication');
        
        $form->text('rsvp', __('RSVP Contact'))
            ->rules('nullable|max:255')
            ->help('Contact information for RSVP or inquiries')
            ->placeholder('e.g., +256 700 000 000 or rsvp@example.com');
            
        $form->divider('Branding & Customization');
        
        $form->image('logo', __('Program Logo'))
            ->uniqueName()
            ->rules('nullable|image|max:2048')
            ->help('Upload a logo for printed reports (max 2MB)');
            
        $form->textarea('bottom', __('Footer Text'))
            ->rows(3)
            ->rules('nullable')
            ->help('Text to appear at the bottom of printed reports')
            ->placeholder('e.g., Thank you for your generous contribution!');
            
        $form->divider('Financial Summary (Auto-calculated)');
        
        $form->html('<div class="alert alert-info">
            <i class="fa fa-info-circle"></i>
            <strong>Note:</strong> All financial totals are automatically calculated based on contributions and budget items.
        </div>');
        
        $form->display('total_expected', __('Total Expected (UGX)'))
            ->with(function ($value) {
                return 'UGX ' . number_format($value ?? 0);
            });
            
        $form->display('total_collected', __('Total Collected (UGX)'))
            ->with(function ($value) {
                return 'UGX ' . number_format($value ?? 0);
            });
            
        $form->display('total_in_pledge', __('Pending Pledges (UGX)'))
            ->with(function ($value) {
                return 'UGX ' . number_format($value ?? 0);
            });
            
        $form->display('budget_total', __('Total Budget (UGX)'))
            ->with(function ($value) {
                return 'UGX ' . number_format($value ?? 0);
            });
            
        $form->display('budget_spent', __('Budget Spent (UGX)'))
            ->with(function ($value) {
                return 'UGX ' . number_format($value ?? 0);
            });
            
        $form->display('budget_balance', __('Budget Balance (UGX)'))
            ->with(function ($value) {
                $balance = $value ?? 0;
                $color = $balance >= 0 ? 'green' : 'red';
                return "<span style='color: {$color}; font-weight: bold;'>UGX " . number_format($balance) . "</span>";
            });

        $form->saved(function (Form $form) {
            admin_success('Success', 'Budget program saved successfully!');
        });

        return $form;
    }
}
