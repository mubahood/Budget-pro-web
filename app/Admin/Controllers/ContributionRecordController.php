<?php

namespace App\Admin\Controllers;

use App\Models\ContributionRecord;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

class ContributionRecordController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Contribution Records';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new ContributionRecord());
        $grid->disableBatchActions();
        
        $u = Admin::user();
        $grid->model()
            ->where('company_id', $u->company_id)
            ->orderBy('created_at', 'desc');
            
        $grid->filter(function ($filter) {
            $u = auth()->user();
            $users = \App\Models\User::where('company_id', $u->company_id)->get();
            
            $filter->equal('treasurer_id', __('Recorded By'))->select($users->pluck('name', 'id'));
            $filter->like('name', __('Contributor Name'));
            $filter->equal('category_id', __('Category'))
                ->select([
                    'Family' => 'Family',
                    'Friend' => 'Friend',
                    'MTK' => 'MTK',
                    'Member' => 'Member',
                    'Sponsor' => 'Sponsor',
                    'Other' => 'Other'
                ]);
            $filter->equal('fully_paid', __('Payment Status'))
                ->select(['Yes' => 'Paid in Full', 'No' => 'Pending/Partial']);
            $filter->between('created_at', __('Date Recorded'))->datetime();
            $filter->disableIdFilter();
        });
        
        $grid->quickSearch('name')->placeholder('Search contributor name');
        
        $grid->column('id', __('ID'))->sortable();
        
        $grid->column('created_at', __('Date'))
            ->display(function ($created_at) {
                return date('d M Y, h:i A', strtotime($created_at));
            })->sortable();

        $grid->column('budget_program_id', __('Budget Program'))
            ->display(function ($budget_program_id) {
                $bp = \App\Models\BudgetProgram::find($budget_program_id);
                return $bp ? $bp->name : 'N/A';
            })->sortable()->hide();

        $grid->column('name', __('Contributor'))
            ->display(function ($name) {
                return "<strong>{$name}</strong>";
            })->sortable();
            
        $grid->column('category_id', __('Category'))
            ->using([
                'Family' => 'Family',
                'Friend' => 'Friend',
                'MTK' => 'MTK',
                'Member' => 'Member',
                'Sponsor' => 'Sponsor',
                'Other' => 'Other'
            ])
            ->label([
                'Family' => 'primary',
                'Friend' => 'success',
                'MTK' => 'info',
                'Member' => 'warning',
                'Sponsor' => 'danger',
                'Other' => 'default'
            ])
            ->sortable()
            ->editable('select', [
                'Family' => 'Family',
                'Friend' => 'Friend',
                'MTK' => 'MTK',
                'Member' => 'Member',
                'Sponsor' => 'Sponsor',
                'Other' => 'Other'
            ]);
            
        $grid->column('amount', __('Pledged Amount'))
            ->display(function ($amount) {
                return '<span class="badge badge-primary">UGX ' . number_format($amount) . '</span>';
            })->sortable()
            ->totalRow(function ($amount) {
                return "<strong>UGX " . number_format($amount) . "</strong>";
            });
            
        $grid->column('paid_amount', __('Amount Paid'))
            ->display(function ($paid_amount) {
                return '<span class="badge badge-success">UGX ' . number_format($paid_amount) . '</span>';
            })->sortable()
            ->totalRow(function ($paid_amount) {
                return "<strong>UGX " . number_format($paid_amount) . "</strong>";
            });
            
        $grid->column('not_paid_amount', __('Balance'))
            ->display(function ($not_paid_amount) {
                if ($not_paid_amount > 0) {
                    return '<span class="badge badge-danger">UGX ' . number_format($not_paid_amount) . '</span>';
                } else {
                    return '<span class="badge badge-success">UGX 0</span>';
                }
            })->sortable()
            ->totalRow(function ($not_paid_amount) {
                return "<strong>UGX " . number_format($not_paid_amount) . "</strong>";
            });
            
        $grid->column('fully_paid', __('Status'))
            ->using([
                'Yes' => 'Paid in Full',
                'No' => 'Pending'
            ])
            ->label([
                'Yes' => 'success',
                'No' => 'warning'
            ])->filter([
                'Yes' => 'Paid in Full',
                'No' => 'Pending'
            ])->sortable();

        $grid->column('treasurer_id', __('Recorded By'))
            ->display(function ($treasurer_id) {
                $user = \App\Models\User::find($treasurer_id);
                return $user ? $user->name : 'Unknown';
            })->sortable();
            
        $grid->column('chaned_by_id', __('Last Updated By'))
            ->display(function ($chaned_by_id) {
                $u = \App\Models\User::find($chaned_by_id);
                if ($u == null) {
                    return 'N/A';
                }
                return $u->name;
            })->sortable()->hide();

        $grid->column('updated_at', __('Last Updated'))
            ->display(function ($updated_at) {
                return date('d M Y, h:i A', strtotime($updated_at));
            })->sortable()->hide();

        $grid->column('print', __('Receipt'))
            ->display(function () {
                $url = url('thanks?id=' . $this->id);
                return "<a href='{$url}' target='_blank' class='btn btn-xs btn-success'>
                    <i class='fa fa-print'></i> Print Thanks
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
        $show = new Show(ContributionRecord::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('created_at', __('Created at'));
        $show->field('updated_at', __('Updated at'));
        $show->field('budget_program_id', __('Budget program id'));
        $show->field('company_id', __('Company id'));
        $show->field('treasurer_id', __('Treasurer id'));
        $show->field('chaned_by_id', __('Chaned by id'));
        $show->field('name', __('Name'));
        $show->field('amount', __('Amount'));
        $show->field('paid_amount', __('Paid amount'));
        $show->field('not_paid_amount', __('Not paid amount'));
        $show->field('fully_paid', __('Fully paid'));

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new ContributionRecord());
        $u = auth()->user();
        
        if ($u == null) {
            throw new \Exception('User not found');
        }
        
        $bps = \App\Models\BudgetProgram::where('company_id', $u->company_id)
            ->orderBy('id', 'desc')
            ->get();
            
        $bp = [];
        $first_id = null;
        foreach ($bps as $b) {
            $bp[$b->id] = $b->name;
            if ($first_id == null) {
                $first_id = $b->id;
            }
        }
        
        $form->select('budget_program_id', __('Budget Program'))
            ->options($bp)
            ->default($first_id)
            ->rules('required')
            ->required()
            ->help('Select the budget program for this contribution');
            
        $form->hidden('company_id')->default($u->company_id); 

        $form->divider('Contributor Information');

        $form->text('name', __('Contributor Name'))
            ->rules('required|max:255')
            ->required()
            ->help('Enter the full name of the contributor')
            ->placeholder('e.g., John Doe');

        $form->select('category_id', 'Category')
            ->options([
                'Family' => 'Family',
                'Friend' => 'Friend',
                'MTK' => 'MTK',
                'Member' => 'Member',
                'Sponsor' => 'Sponsor',
                'Other' => 'Other'
            ])
            ->rules('required')
            ->required()
            ->default('Friend')
            ->help('Select the contributor category');
            
        $form->divider('Contribution Amount (Pledge)');

        $form->radio('custom_amount', __('Select or Enter Amount'))
            ->options([
                '5000' => 'UGX ' . number_format(5000),
                '10000' => 'UGX ' . number_format(10000),
                '20000' => 'UGX ' . number_format(20000),
                '30000' => 'UGX ' . number_format(30000),
                '50000' => 'UGX ' . number_format(50000),
                '100000' => 'UGX ' . number_format(100000),
                '200000' => 'UGX ' . number_format(200000),
                '500000' => 'UGX ' . number_format(500000),
                'custom' => 'Custom Amount (Enter below)'
            ])
            ->rules('required')
            ->required()
            ->default('10000')
            ->when('custom', function ($form) {
                $form->currency('amount', __('Custom Amount (UGX)'))
                    ->symbol('UGX')
                    ->rules('required|numeric|min:1')
                    ->required()
                    ->help('Enter the pledged amount');
            });
            
        $form->divider('Payment Status');

        $form->radio('fully_paid', __('Payment Status'))
            ->options([
                'Yes' => 'Paid in Full',
                'No' => 'Partially Paid / Pending'
            ])
            ->rules('required')
            ->required()
            ->default('No')
            ->help('Has the contributor paid the full amount?')
            ->when('No', function ($form) {
                $form->radio('custom_paid_amount', __('Amount Paid So Far'))
                    ->options([
                        '0' => 'UGX 0 (Not Yet Paid)',
                        '5000' => 'UGX ' . number_format(5000),
                        '10000' => 'UGX ' . number_format(10000),
                        '20000' => 'UGX ' . number_format(20000),
                        '30000' => 'UGX ' . number_format(30000),
                        '50000' => 'UGX ' . number_format(50000),
                        '100000' => 'UGX ' . number_format(100000),
                        '200000' => 'UGX ' . number_format(200000),
                        '500000' => 'UGX ' . number_format(500000),
                        'custom' => 'Custom Amount (Enter below)'
                    ])
                    ->rules('required')
                    ->required()
                    ->default('0')
                    ->when('custom', function ($form) {
                        $form->currency('paid_amount', __('Custom Paid Amount (UGX)'))
                            ->symbol('UGX')
                            ->rules('required|numeric|min:0')
                            ->required()
                            ->help('Enter the exact amount paid');
                    });
            });

        $form->divider('Record Keeping');

        $u = Admin::user();
        $form->select('treasurer_id', __('Recorded By (Treasurer)'))
            ->options(\App\Models\User::where('company_id', $u->company_id)
                ->pluck('name', 'id'))
            ->rules('required')
            ->required()
            ->default($u->id)
            ->help('Person recording this contribution');
            
        $form->hidden('chaned_by_id')->default($u->id);

        $form->saved(function (Form $form) {
            admin_success('Success', 'Contribution record saved successfully!');
        });

        return $form;
    }
}
