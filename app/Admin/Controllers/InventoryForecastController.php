<?php

namespace App\Admin\Controllers;

use App\Models\InventoryForecast;
use App\Models\StockItem;
use App\Services\InventoryForecastService;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;

class InventoryForecastController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Inventory Forecasting';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new InventoryForecast());

        $grid->model()->orderBy('created_at', 'desc');

        $grid->filter(function($filter) {
            $filter->disableIdFilter();
            $filter->equal('stock_item_id', 'Stock Item')->select(StockItem::pluck('name', 'id'));
            $filter->equal('stock_status', 'Stock Status')->select([
                'overstocked' => 'Overstocked',
                'optimal' => 'Optimal',
                'low' => 'Low',
                'critical' => 'Critical',
                'stockout' => 'Stockout',
            ]);
            $filter->equal('trend', 'Trend')->select([
                'increasing' => 'Increasing',
                'stable' => 'Stable',
                'decreasing' => 'Decreasing',
                'seasonal' => 'Seasonal',
                'volatile' => 'Volatile',
            ]);
            $filter->equal('action_required', 'Action Required')->radio([
                '' => 'All',
                1 => 'Yes',
                0 => 'No',
            ]);
        });

        $grid->column('stock_item_id', __('Product'))->display(function($itemId) {
            $item = StockItem::find($itemId);
            return $item ? $item->name : 'N/A';
        })->sortable();

        $grid->column('forecast_date', __('Forecast Date'))->display(function($date) {
            return date('Y-m-d', strtotime($date));
        })->sortable();

        $grid->column('current_stock', __('Current Stock'))->sortable();
        $grid->column('predicted_demand', __('Predicted Demand'))->sortable();

        $grid->column('stock_status', __('Status'))->display(function($status) {
            return $this->stock_status_badge;
        })->sortable();

        $grid->column('trend', __('Trend'))->display(function($trend) {
            return $this->trend_badge;
        });

        $grid->column('confidence_level', __('Confidence'))->progressBar()->sortable();

        $grid->column('days_until_stockout', __('Days to Stockout'))->display(function($days) {
            if ($days === null) return 'N/A';
            if ($days <= 7) return "<span class='badge badge-danger'>{$days} days</span>";
            if ($days <= 14) return "<span class='badge badge-warning'>{$days} days</span>";
            return "<span class='badge badge-success'>{$days} days</span>";
        });

        $grid->column('action_required', __('Action'))->display(function($required) {
            return $required ? '<span class="badge badge-danger">Required</span>' : '<span class="badge badge-success">None</span>';
        })->sortable();

        $grid->disableCreateButton();
        $grid->disableActions();

        $grid->tools(function ($tools) {
            $tools->append('<a href="' . admin_url('inventory-forecasts/generate') . '" class="btn btn-sm btn-primary"><i class="fa fa-refresh"></i> Generate Forecasts</a>');
        });

        return $grid;
    }

    /**
     * Generate forecasts page
     */
    public function generate(Content $content)
    {
        return $content
            ->title('Generate Inventory Forecasts')
            ->description('Create forecasts for all stock items')
            ->body(view('admin.inventory-forecasts.generate'));
    }

    /**
     * Process forecast generation
     */
    public function processGenerate()
    {
        $user = Admin::user();
        $service = new InventoryForecastService();
        
        try {
            $forecasts = $service->generateBatchForecasts($user->company_id);
            
            admin_toastr('Successfully generated ' . count($forecasts) . ' forecasts', 'success');
        } catch (\Exception $e) {
            admin_toastr('Error generating forecasts: ' . $e->getMessage(), 'error');
        }
        
        return redirect(admin_url('inventory-forecasts'));
    }

    /**
     * Make a show builder.
     *
     * @param mixed $id
     * @return Show
     */
    protected function detail($id)
    {
        $show = new Show(InventoryForecast::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('company_id', __('Company id'));
        $show->field('stock_item_id', __('Stock item id'));
        $show->field('financial_period_id', __('Financial period id'));
        $show->field('forecast_date', __('Forecast date'));
        $show->field('forecast_period', __('Forecast period'));
        $show->field('historical_average', __('Historical average'));
        $show->field('historical_min', __('Historical min'));
        $show->field('historical_max', __('Historical max'));
        $show->field('standard_deviation', __('Standard deviation'));
        $show->field('predicted_demand', __('Predicted demand'));
        $show->field('predicted_min', __('Predicted min'));
        $show->field('predicted_max', __('Predicted max'));
        $show->field('confidence_level', __('Confidence level'));
        $show->field('trend', __('Trend'));
        $show->field('trend_percentage', __('Trend percentage'));
        $show->field('is_seasonal', __('Is seasonal'));
        $show->field('seasonal_factors', __('Seasonal factors'));
        $show->field('recommended_reorder_point', __('Recommended reorder point'));
        $show->field('recommended_order_quantity', __('Recommended order quantity'));
        $show->field('safety_stock', __('Safety stock'));
        $show->field('current_stock', __('Current stock'));
        $show->field('days_until_stockout', __('Days until stockout'));
        $show->field('stock_status', __('Stock status'));
        $show->field('algorithm_used', __('Algorithm used'));
        $show->field('algorithm_parameters', __('Algorithm parameters'));
        $show->field('forecast_accuracy', __('Forecast accuracy'));
        $show->field('notes', __('Notes'));
        $show->field('action_required', __('Action required'));
        $show->field('recommended_action', __('Recommended action'));
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
        $form = new Form(new InventoryForecast());

        $form->number('company_id', __('Company id'));
        $form->number('stock_item_id', __('Stock item id'));
        $form->number('financial_period_id', __('Financial period id'));
        $form->date('forecast_date', __('Forecast date'))->default(date('Y-m-d'));
        $form->text('forecast_period', __('Forecast period'))->default('monthly');
        $form->number('historical_average', __('Historical average'));
        $form->number('historical_min', __('Historical min'));
        $form->number('historical_max', __('Historical max'));
        $form->decimal('standard_deviation', __('Standard deviation'))->default(0.00);
        $form->number('predicted_demand', __('Predicted demand'));
        $form->number('predicted_min', __('Predicted min'));
        $form->number('predicted_max', __('Predicted max'));
        $form->decimal('confidence_level', __('Confidence level'))->default(0.00);
        $form->text('trend', __('Trend'))->default('stable');
        $form->decimal('trend_percentage', __('Trend percentage'))->default(0.00);
        $form->switch('is_seasonal', __('Is seasonal'));
        $form->text('seasonal_factors', __('Seasonal factors'));
        $form->number('recommended_reorder_point', __('Recommended reorder point'));
        $form->number('recommended_order_quantity', __('Recommended order quantity'));
        $form->number('safety_stock', __('Safety stock'));
        $form->number('current_stock', __('Current stock'));
        $form->number('days_until_stockout', __('Days until stockout'));
        $form->text('stock_status', __('Stock status'))->default('optimal');
        $form->text('algorithm_used', __('Algorithm used'))->default('moving_average');
        $form->text('algorithm_parameters', __('Algorithm parameters'));
        $form->decimal('forecast_accuracy', __('Forecast accuracy'));
        $form->textarea('notes', __('Notes'));
        $form->switch('action_required', __('Action required'));
        $form->textarea('recommended_action', __('Recommended action'));

        return $form;
    }
}
