<div class="box box-primary">
    <div class="box-header with-border">
        <h3 class="box-title">Generate Inventory Forecasts</h3>
    </div>
    <div class="box-body">
        <p>This will generate forecasts for all stock items in your company based on historical demand data.</p>
        
        <form action="{{ admin_url('inventory-forecasts-generate') }}" method="POST">
            @csrf
            
            <div class="form-group">
                <label>Forecasting Algorithm</label>
                <select name="algorithm" class="form-control">
                    <option value="moving_average">Moving Average (Recommended)</option>
                    <option value="exponential_smoothing">Exponential Smoothing</option>
                    <option value="linear_regression">Linear Regression</option>
                </select>
                <p class="help-block">
                    <strong>Moving Average:</strong> Best for stable demand patterns<br>
                    <strong>Exponential Smoothing:</strong> Better for trending data<br>
                    <strong>Linear Regression:</strong> Best for strong linear trends
                </p>
            </div>
            
            <div class="form-group">
                <label>Forecast Horizon (Days)</label>
                <input type="number" name="forecast_days" class="form-control" value="30" min="7" max="365">
                <p class="help-block">Number of days ahead to forecast (7-365 days)</p>
            </div>
            
            <div class="alert alert-info">
                <i class="fa fa-info-circle"></i>
                <strong>Note:</strong> The system will analyze the last 90 days of sales data to generate predictions.
                Items without sufficient historical data will receive default forecasts.
            </div>
            
            <button type="submit" class="btn btn-primary">
                <i class="fa fa-refresh"></i> Generate Forecasts
            </button>
            <a href="{{ admin_url('inventory-forecasts') }}" class="btn btn-default">
                <i class="fa fa-arrow-left"></i> Back
            </a>
        </form>
    </div>
</div>
