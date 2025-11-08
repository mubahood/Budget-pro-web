<?php

namespace App\Admin\Controllers;

use App\Models\AutoReorderRule;
use App\Models\Company;
use App\Models\StockItem;
use App\Services\AutoReorderService;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;

class AutoReorderRuleController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Auto Reorder Rules';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new AutoReorderRule());

        // Filter by company
        $grid->model()->where('company_id', auth()->user()->company_id);
        $grid->model()->with(['company', 'stockItem']);

        // Columns
        $grid->column('id', __('ID'))->sortable();
        $grid->column('is_enabled', __('Status'))->display(function ($enabled) {
            return $enabled 
                ? '<span class="label label-success">Active</span>' 
                : '<span class="label label-default">Disabled</span>';
        });
        $grid->column('rule_name', __('Rule Name'))->sortable();
        $grid->column('stockItem.name', __('Stock Item'));
        $grid->column('reorder_point', __('Reorder Point'))->sortable();
        $grid->column('reorder_quantity', __('Reorder Quantity'));
        $grid->column('reorder_method', __('Method'))->display(function ($method) {
            $labels = [
                'fixed_quantity' => '<span class="label label-primary">Fixed</span>',
                'economic_order_quantity' => '<span class="label label-info">EOQ</span>',
                'forecast_based' => '<span class="label label-warning">Forecast</span>',
            ];
            return $labels[$method] ?? $method;
        });
        $grid->column('check_frequency', __('Frequency'));
        $grid->column('last_checked_at', __('Last Checked'));
        $grid->column('last_triggered_at', __('Last Triggered'));
        $grid->column('times_triggered', __('Triggered Count'))->sortable();

        // Filters
        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            $filter->equal('is_enabled', __('Status'))->select([
                1 => 'Active',
                0 => 'Disabled',
            ]);
            $filter->equal('stock_item_id', __('Stock Item'))->select(
                StockItem::where('company_id', auth()->user()->company_id)
                    ->pluck('name', 'id')
            );
            $filter->equal('reorder_method', __('Method'))->select([
                'fixed_quantity' => 'Fixed Quantity',
                'economic_order_quantity' => 'Economic Order Quantity',
                'forecast_based' => 'Forecast Based',
            ]);
        });

        // Actions
        $grid->actions(function ($actions) {
            $actions->add(new \Encore\Admin\Grid\Actions\Delete);
        });

        // Tools
        $grid->tools(function ($tools) {
            $tools->append('<a href="'.admin_url('auto-reorder-rules/trigger').'" class="btn btn-sm btn-warning">
                <i class="fa fa-bolt"></i> Trigger All Rules
            </a>');
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
        $show = new Show(AutoReorderRule::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('company_id', __('Company id'));
        $show->field('stock_item_id', __('Stock item id'));
        $show->field('is_enabled', __('Is enabled'));
        $show->field('rule_name', __('Rule name'));
        $show->field('reorder_point', __('Reorder point'));
        $show->field('reorder_quantity', __('Reorder quantity'));
        $show->field('min_stock_level', __('Min stock level'));
        $show->field('max_stock_level', __('Max stock level'));
        $show->field('preferred_supplier_name', __('Preferred supplier name'));
        $show->field('preferred_supplier_email', __('Preferred supplier email'));
        $show->field('preferred_supplier_phone', __('Preferred supplier phone'));
        $show->field('preferred_supplier_address', __('Preferred supplier address'));
        $show->field('preferred_unit_price', __('Preferred unit price'));
        $show->field('lead_time_days', __('Lead time days'));
        $show->field('use_forecasting', __('Use forecasting'));
        $show->field('forecast_algorithm', __('Forecast algorithm'));
        $show->field('forecast_horizon_days', __('Forecast horizon days'));
        $show->field('reorder_method', __('Reorder method'));
        $show->field('holding_cost_percentage', __('Holding cost percentage'));
        $show->field('ordering_cost', __('Ordering cost'));
        $show->field('requires_approval', __('Requires approval'));
        $show->field('auto_approve_threshold', __('Auto approve threshold'));
        $show->field('check_frequency', __('Check frequency'));
        $show->field('check_time', __('Check time'));
        $show->field('check_days', __('Check days'));
        $show->field('send_email_notification', __('Send email notification'));
        $show->field('notification_emails', __('Notification emails'));
        $show->field('last_checked_at', __('Last checked at'));
        $show->field('last_triggered_at', __('Last triggered at'));
        $show->field('times_triggered', __('Times triggered'));
        $show->field('notes', __('Notes'));
        $show->field('created_at', __('Created at'));
        $show->field('updated_at', __('Updated at'));

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new AutoReorderRule());

        // Hidden company ID
        $form->hidden('company_id')->default(auth()->user()->company_id);

        // Basic Information
        $form->tab('Basic Information', function ($form) {
            $form->switch('is_enabled', __('Enable Rule'))->default(1);
            $form->text('rule_name', __('Rule Name'))->required();
            $form->select('stock_item_id', __('Stock Item'))
                ->options(StockItem::where('company_id', auth()->user()->company_id)->pluck('name', 'id'))
                ->required();
        });

        // Reorder Settings
        $form->tab('Reorder Settings', function ($form) {
            $form->number('reorder_point', __('Reorder Point'))->required()->help('Trigger when stock reaches this level');
            $form->number('reorder_quantity', __('Reorder Quantity'))->required()->help('Default quantity to order');
            $form->number('min_stock_level', __('Min Stock Level'))->default(0);
            $form->number('max_stock_level', __('Max Stock Level'))->default(0);
            
            $form->select('reorder_method', __('Reorder Method'))->options([
                'fixed_quantity' => 'Fixed Quantity',
                'economic_order_quantity' => 'Economic Order Quantity (EOQ)',
                'forecast_based' => 'Forecast Based',
            ])->default('fixed_quantity')->required();

            $form->decimal('holding_cost_percentage', __('Annual Holding Cost %'))->default(20.00);
            $form->decimal('ordering_cost', __('Cost Per Order'))->default(0.00);
        });

        // Supplier Information
        $form->tab('Supplier Info', function ($form) {
            $form->text('preferred_supplier_name', __('Supplier Name'));
            $form->email('preferred_supplier_email', __('Supplier Email'));
            $form->text('preferred_supplier_phone', __('Supplier Phone'));
            $form->textarea('preferred_supplier_address', __('Supplier Address'));
            $form->decimal('preferred_unit_price', __('Unit Price'))->default(0.00);
            $form->number('lead_time_days', __('Lead Time (Days)'))->default(7);
        });

        // Forecasting
        $form->tab('Forecasting', function ($form) {
            $form->switch('use_forecasting', __('Use Forecasting'))->default(1);
            $form->select('forecast_algorithm', __('Algorithm'))->options([
                'moving_average' => 'Moving Average',
                'exponential_smoothing' => 'Exponential Smoothing',
                'linear_regression' => 'Linear Regression',
            ])->default('moving_average');
            $form->number('forecast_horizon_days', __('Forecast Horizon (Days)'))->default(30);
        });

        // Approval & Notifications
        $form->tab('Approval & Notifications', function ($form) {
            $form->switch('requires_approval', __('Requires Approval'))->default(1);
            $form->decimal('auto_approve_threshold', __('Auto Approve If Below'))->help('Automatically approve orders under this amount');
            
            $form->switch('send_email_notification', __('Send Email Notifications'))->default(1);
            $form->tags('notification_emails', __('Notification Emails'))->help('Enter email addresses');
        });

        // Scheduling
        $form->tab('Schedule', function ($form) {
            $form->select('check_frequency', __('Check Frequency'))->options([
                'hourly' => 'Hourly',
                'daily' => 'Daily',
                'weekly' => 'Weekly',
            ])->default('daily')->required();
            $form->time('check_time', __('Check Time'))->default('09:00:00');
            $form->checkbox('check_days', __('Check Days (Weekly only)'))->options([
                'monday' => 'Monday',
                'tuesday' => 'Tuesday',
                'wednesday' => 'Wednesday',
                'thursday' => 'Thursday',
                'friday' => 'Friday',
                'saturday' => 'Saturday',
                'sunday' => 'Sunday',
            ]);
        });

        // Notes
        $form->tab('Notes', function ($form) {
            $form->textarea('notes', __('Notes'));
        });

        return $form;
    }

    /**
     * Trigger all rules manually
     */
    public function trigger(Request $request)
    {
        $service = app(AutoReorderService::class);
        $companyId = auth()->user()->company_id;
        
        $results = $service->checkAllRules($companyId);
        
        return back()->with([
            'message' => "Checked {$results['checked']} rules, triggered {$results['triggered']}, created {$results['orders_created']} orders.",
            'status' => count($results['errors']) > 0 ? 'warning' : 'success',
        ]);
    }
}
