@php
    $currency = $company->currency ?? 'UGX';
    $d = $data;
@endphp

<style>
    * {
        font-size: 13px;
    }
    
    .dash-container {
        background: #f5f5f5;
        padding: 15px;
        margin: -15px;
    }
    
    .dash-card {
        background: #fff;
        border: 1px solid #ddd;
        padding: 15px;
        margin-bottom: 15px;
    }
    
    .dash-header {
        font-size: 14px;
        font-weight: 600;
        color: #333;
        margin: 0 0 12px 0;
        padding-bottom: 8px;
        border-bottom: 2px solid #2196F3;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 15px;
    }
    
    .stat-box {
        background: #fff;
        border: 1px solid #ddd;
        padding: 12px;
    }
    
    .stat-label {
        font-size: 11px;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin: 0 0 5px 0;
    }
    
    .stat-value {
        font-size: 22px;
        font-weight: 600;
        color: #2196F3;
        margin: 0;
    }
    
    .stat-sub {
        font-size: 11px;
        color: #999;
        margin: 3px 0 0 0;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    
    table th {
        background: #fafafa;
        padding: 8px;
        text-align: left;
        font-weight: 600;
        color: #333;
        border-bottom: 2px solid #2196F3;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    table td {
        padding: 8px;
        border-bottom: 1px solid #eee;
        color: #555;
    }
    
    table tr:hover {
        background: #fafafa;
    }
    
    .badge {
        display: inline-block;
        padding: 2px 8px;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .badge-danger {
        background: #ffebee;
        color: #c62828;
        border: 1px solid #ef5350;
    }
    
    .badge-warning {
        background: #fff3e0;
        color: #e65100;
        border: 1px solid #ff9800;
    }
    
    .badge-info {
        background: #e3f2fd;
        color: #1565c0;
        border: 1px solid #2196F3;
    }
    
    .text-primary {
        color: #2196F3;
    }
    
    .text-muted {
        color: #999;
    }
    
    .grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }
    
    .grid-3 {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 15px;
    }
    
    @media (max-width: 768px) {
        .grid-2, .grid-3 {
            grid-template-columns: 1fr;
        }
    }
    
    .no-data {
        text-align: center;
        padding: 30px;
        color: #999;
        font-size: 12px;
    }
</style>

<div class="dash-container">
    
    {{-- Overview Stats --}}
    <div class="stat-grid">
        <div class="stat-box">
            <p class="stat-label">Total Items</p>
            <h3 class="stat-value">{{ number_format($d['inventory_overview']['total_items'] ?? 0) }}</h3>
            <p class="stat-sub">In Inventory</p>
        </div>
        
        <div class="stat-box">
            <p class="stat-label">Stock Value</p>
            <h3 class="stat-value">{{ $currency }} {{ number_format($d['inventory_overview']['total_stock_value'] ?? 0) }}</h3>
            <p class="stat-sub">Total Worth</p>
        </div>
        
        <div class="stat-box">
            <p class="stat-label">Out of Stock</p>
            <h3 class="stat-value">{{ $d['inventory_overview']['out_of_stock'] ?? 0 }}</h3>
            <p class="stat-sub">Items Depleted</p>
        </div>
        
        <div class="stat-box">
            <p class="stat-label">Low Stock</p>
            <h3 class="stat-value">{{ $d['inventory_overview']['low_stock'] ?? 0 }}</h3>
            <p class="stat-sub">Need Restocking</p>
        </div>
        
        <div class="stat-box">
            <p class="stat-label">Avg Profit Margin</p>
            <h3 class="stat-value">{{ number_format($d['inventory_overview']['avg_profit_margin'] ?? 0, 1) }}%</h3>
            <p class="stat-sub">Per Item</p>
        </div>
        
        <div class="stat-box">
            <p class="stat-label">Month Sales</p>
            <h3 class="stat-value">{{ $currency }} {{ number_format($d['inventory_overview']['month_sales'] ?? 0) }}</h3>
            <p class="stat-sub">Current Month</p>
        </div>
    </div>

    {{-- Sales Performance --}}
    <div class="grid-2">
        {{-- Quick Stats --}}
        <div class="dash-card">
            <h4 class="dash-header">Sales Performance</h4>
            <table>
                <tr>
                    <td><strong>Today</strong></td>
                    <td class="text-primary">{{ $currency }} {{ number_format($d['quick_stats']['today']->sales ?? 0) }}</td>
                    <td class="text-muted">{{ $d['quick_stats']['today']->transactions ?? 0 }} transactions</td>
                </tr>
                <tr>
                    <td><strong>This Week</strong></td>
                    <td class="text-primary">{{ $currency }} {{ number_format($d['quick_stats']['week']->sales ?? 0) }}</td>
                    <td class="text-muted">{{ $d['quick_stats']['week']->transactions ?? 0 }} transactions</td>
                </tr>
                <tr>
                    <td><strong>This Month</strong></td>
                    <td class="text-primary">{{ $currency }} {{ number_format($d['quick_stats']['month']->sales ?? 0) }}</td>
                    <td class="text-muted">{{ $d['quick_stats']['month']->transactions ?? 0 }} transactions</td>
                </tr>
                <tr>
                    <td><strong>This Year</strong></td>
                    <td class="text-primary">{{ $currency }} {{ number_format($d['quick_stats']['year']->sales ?? 0) }}</td>
                    <td class="text-muted">{{ $d['quick_stats']['year']->transactions ?? 0 }} transactions</td>
                </tr>
            </table>
        </div>

        {{-- Week Activity --}}
        <div class="dash-card">
            <h4 class="dash-header">This Week Activity</h4>
            @if(!empty($d['stock_alerts']['week_activity']))
                <table>
                    @foreach($d['stock_alerts']['week_activity'] as $activity)
                        <tr>
                            <td><strong>{{ ucfirst($activity->type) }}</strong></td>
                            <td class="text-primary">{{ number_format($activity->count) }} transactions</td>
                            <td class="text-muted">{{ $currency }} {{ number_format($activity->total_value) }}</td>
                        </tr>
                    @endforeach
                </table>
            @else
                <div class="no-data">No activity this week</div>
            @endif
        </div>
    </div>

    {{-- Top Products & Stock Alerts --}}
    <div class="grid-2">
        {{-- Top Selling Products --}}
        <div class="dash-card">
            <h4 class="dash-header">Top Selling Products</h4>
            @if(!empty($d['top_performers']['products']))
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Sales</th>
                            <th>Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($d['top_performers']['products'] as $product)
                            <tr>
                                <td>{{ $product->name }}</td>
                                <td class="text-primary">{{ $currency }} {{ number_format($product->total_sales) }}</td>
                                <td>{{ number_format($product->total_quantity) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="no-data">No sales data available</div>
            @endif
        </div>

        {{-- Stock Alerts --}}
        <div class="dash-card">
            <h4 class="dash-header">Stock Alerts</h4>
            @if(!empty($d['stock_alerts']['out_of_stock']) || !empty($d['stock_alerts']['low_stock']))
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>SKU</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($d['stock_alerts']['out_of_stock'] ?? [] as $item)
                            <tr>
                                <td>{{ $item->name }}</td>
                                <td>{{ $item->sku }}</td>
                                <td><span class="badge badge-danger">Out of Stock</span></td>
                            </tr>
                        @endforeach
                        @foreach($d['stock_alerts']['low_stock'] ?? [] as $item)
                            <tr>
                                <td>{{ $item->name }}</td>
                                <td>{{ $item->sku }}</td>
                                <td><span class="badge badge-warning">Low ({{ $item->current_quantity }})</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="no-data">No stock alerts</div>
            @endif
        </div>
    </div>

    {{-- Best Category --}}
    @if(!empty($d['inventory_overview']['best_category']))
    <div class="dash-card">
        <h4 class="dash-header">Best Performing Category This Month</h4>
        <table>
            <tr>
                <td><strong>Category</strong></td>
                <td class="text-primary">{{ $d['inventory_overview']['best_category'] }}</td>
            </tr>
        </table>
    </div>
    @endif

    {{-- System Info --}}
    <div class="grid-3">
        <div class="stat-box">
            <p class="stat-label">Total Employees</p>
            <h3 class="stat-value">{{ $d['employees_stats']['total_employees'] ?? 0 }}</h3>
        </div>
        <div class="stat-box">
            <p class="stat-label">Active Users</p>
            <h3 class="stat-value">{{ $d['employees_stats']['active_employees'] ?? 0 }}</h3>
        </div>
        <div class="stat-box">
            <p class="stat-label">Last Updated</p>
            <h3 class="stat-value" style="font-size: 14px;">{{ now()->format('H:i') }}</h3>
            <p class="stat-sub">{{ now()->format('d M Y') }}</p>
        </div>
    </div>

</div>
    
    .stat-value {
        font-size: 36px;
        font-weight: 800;
        margin: 10px 0;
        position: relative;
        z-index: 1;
    }
    
    .stat-label {
        font-size: 14px;
        opacity: 0.9;
        text-transform: uppercase;
        letter-spacing: 1px;
        position: relative;
        z-index: 1;
    }
    
    .stat-icon {
        position: absolute;
        right: 20px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 60px;
        opacity: 0.3;
        z-index: 0;
    }
    
    .progress-custom {
        height: 25px;
        border-radius: 15px;
        background: #e9ecef;
        overflow: hidden;
        box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .progress-bar-custom {
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 12px;
        transition: width 0.6s ease;
    }
    
    .alert-item {
        padding: 10px 15px;
        border-left: 4px solid;
        margin-bottom: 10px;
        border-radius: 5px;
        transition: all 0.3s ease;
    }
    
    .alert-item:hover {
        transform: translateX(5px);
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    .alert-danger {
        background: #fff5f5;
        border-color: #fc8181;
    }
    
    .alert-warning {
        background: #fffaf0;
        border-color: #f6ad55;
    }
    
    .alert-success {
        background: #f0fff4;
        border-color: #68d391;
    }
    
    .table-dashboard {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    
    .table-dashboard th {
        background: #f7fafc;
        padding: 12px;
        text-align: left;
        font-weight: 600;
        color: #4a5568;
        border-bottom: 2px solid #e2e8f0;
    }
    
    .table-dashboard td {
        padding: 12px;
        border-bottom: 1px solid #e2e8f0;
    }
    
    .table-dashboard tr:hover {
        background: #f7fafc;
    }
    
    .badge-custom {
        display: inline-block;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        color: white;
    }
    
    .badge-success { background: #48bb78; }
    .badge-danger { background: #f56565; }
    .badge-warning { background: #ed8936; }
    .badge-info { background: #4299e1; }
    .badge-primary { background: #667eea; }
    
    .activity-item {
        display: flex;
        align-items: center;
        padding: 12px;
        border-bottom: 1px solid #e2e8f0;
        transition: all 0.3s ease;
    }
    
    .activity-item:hover {
        background: #f7fafc;
        padding-left: 20px;
    }
    
    .activity-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 15px;
        font-size: 18px;
    }
    
    .chart-container {
        position: relative;
        height: 300px;
    }
    
    .section-title {
        font-size: 18px;
        font-weight: 700;
        color: #2d3748;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
    }
    
    .section-title i {
        margin-right: 10px;
        color: #667eea;
    }
    
    .quick-action-btn {
        display: inline-block;
        padding: 10px 20px;
        background: #667eea;
        color: white;
        border-radius: 5px;
        text-decoration: none;
        transition: all 0.3s ease;
        margin: 5px;
    }
    
    .quick-action-btn:hover {
        background: #5568d3;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        color: white;
    }
    
    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .animate-slide {
        animation: slideIn 0.5s ease forwards;
    }
</style>

<div class="container-fluid">
    
    {{-- Top Stats Cards --}}
    <div class="row">
        <div class="col-lg-3 col-md-6 animate-slide" style="animation-delay: 0.1s">
            <div class="stat-card success">
                <div class="stat-icon"><i class="fa fa-money"></i></div>
                <div class="stat-label">Total Income</div>
                <div class="stat-value">{{ $currency }} {{ number_format($d['financial_overview']['total_income']) }}</div>
                <small><i class="fa fa-arrow-up"></i> All time revenue</small>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 animate-slide" style="animation-delay: 0.2s">
            <div class="stat-card danger">
                <div class="stat-icon"><i class="fa fa-credit-card"></i></div>
                <div class="stat-label">Total Expenses</div>
                <div class="stat-value">{{ $currency }} {{ number_format($d['financial_overview']['total_expense']) }}</div>
                <small><i class="fa fa-arrow-down"></i> All time spending</small>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 animate-slide" style="animation-delay: 0.3s">
            <div class="stat-card {{ $d['financial_overview']['net_profit'] >= 0 ? 'success' : 'danger' }}">
                <div class="stat-icon"><i class="fa fa-line-chart"></i></div>
                <div class="stat-label">Net Profit</div>
                <div class="stat-value">{{ $currency }} {{ number_format($d['financial_overview']['net_profit']) }}</div>
                <small>
                    <i class="fa fa-percent"></i> {{ $d['financial_overview']['profit_margin'] }}% margin
                </small>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 animate-slide" style="animation-delay: 0.4s">
            <div class="stat-card info">
                <div class="stat-icon"><i class="fa fa-cubes"></i></div>
                <div class="stat-label">Stock Value</div>
                <div class="stat-value">{{ $currency }} {{ number_format($d['inventory_overview']['total_stock_value']) }}</div>
                <small><i class="fa fa-archive"></i> {{ $d['inventory_overview']['total_items'] }} items</small>
            </div>
        </div>
    </div>
    
    {{-- Quick Stats Today/Week/Month/Year --}}
    <div class="row">
        <div class="col-lg-3 col-md-6">
            <div class="dashboard-card text-center">
                <i class="fa fa-calendar-o" style="font-size: 30px; color: #667eea;"></i>
                <h4 style="margin: 10px 0; color: #2d3748;">TODAY</h4>
                <h2 style="color: #667eea; font-weight: 800;">{{ $currency }} {{ number_format($d['quick_stats']['today']->sales) }}</h2>
                <p style="color: #718096; margin: 0;">{{ $d['quick_stats']['today']->transactions }} transactions</p>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="dashboard-card text-center">
                <i class="fa fa-calendar" style="font-size: 30px; color: #48bb78;"></i>
                <h4 style="margin: 10px 0; color: #2d3748;">THIS WEEK</h4>
                <h2 style="color: #48bb78; font-weight: 800;">{{ $currency }} {{ number_format($d['quick_stats']['week']->sales) }}</h2>
                <p style="color: #718096; margin: 0;">{{ $d['quick_stats']['week']->transactions }} transactions</p>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="dashboard-card text-center">
                <i class="fa fa-calendar-check-o" style="font-size: 30px; color: #ed8936;"></i>
                <h4 style="margin: 10px 0; color: #2d3748;">THIS MONTH</h4>
                <h2 style="color: #ed8936; font-weight: 800;">{{ $currency }} {{ number_format($d['quick_stats']['month']->sales) }}</h2>
                <p style="color: #718096; margin: 0;">{{ $d['quick_stats']['month']->transactions }} transactions</p>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="dashboard-card text-center">
                <i class="fa fa-calendar-plus-o" style="font-size: 30px; color: #f56565;"></i>
                <h4 style="margin: 10px 0; color: #2d3748;">THIS YEAR</h4>
                <h2 style="color: #f56565; font-weight: 800;">{{ $currency }} {{ number_format($d['quick_stats']['year']->sales) }}</h2>
                <p style="color: #718096; margin: 0;">{{ $d['quick_stats']['year']->transactions }} transactions</p>
            </div>
        </div>
    </div>
    
    {{-- Budget Programs Overview --}}
    <div class="row">
        <div class="col-lg-6">
            <div class="dashboard-card">
                <div class="section-title">
                    <i class="fa fa-bullseye"></i> Budget Programs Status
                </div>
                
                <div style="margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                        <span><strong>Collection Progress</strong></span>
                        <span><strong>{{ $d['budget_programs']['collection_rate'] }}%</strong></span>
                    </div>
                    <div class="progress-custom">
                        <div class="progress-bar-custom" style="width: {{ $d['budget_programs']['collection_rate'] }}%; background: linear-gradient(90deg, #11998e 0%, #38ef7d 100%);">
                            {{ $currency }} {{ number_format($d['budget_programs']['total_collected']) }} / {{ number_format($d['budget_programs']['total_expected']) }}
                        </div>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                        <span><strong>Budget Utilization</strong></span>
                        <span><strong>{{ $d['budget_programs']['burn_rate'] }}%</strong></span>
                    </div>
                    <div class="progress-custom">
                        <div class="progress-bar-custom" style="width: {{ $d['budget_programs']['burn_rate'] }}%; background: linear-gradient(90deg, #eb3349 0%, #f45c43 100%);">
                            {{ $currency }} {{ number_format($d['budget_programs']['budget_spent']) }} / {{ number_format($d['budget_programs']['budget_total']) }}
                        </div>
                    </div>
                </div>
                
                <div class="row text-center" style="margin-top: 20px;">
                    <div class="col-md-4">
                        <h3 style="color: #667eea; margin: 0;">{{ $d['budget_programs']['active_programs'] }}</h3>
                        <small style="color: #718096;">Active Programs</small>
                    </div>
                    <div class="col-md-4">
                        <h3 style="color: #ed8936; margin: 0;">{{ $currency }} {{ number_format($d['budget_programs']['total_in_pledge']) }}</h3>
                        <small style="color: #718096;">Pending Pledges</small>
                    </div>
                    <div class="col-md-4">
                        <h3 style="color: {{ $d['budget_programs']['budget_balance'] >= 0 ? '#48bb78' : '#f56565' }}; margin: 0;">
                            {{ $currency }} {{ number_format($d['budget_programs']['budget_balance']) }}
                        </h3>
                        <small style="color: #718096;">Budget Balance</small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6">
            <div class="dashboard-card">
                <div class="section-title">
                    <i class="fa fa-users"></i> Contributions Overview
                </div>
                
                <div style="margin-bottom: 20px;">
                    <div class="row text-center">
                        <div class="col-md-4">
                            <div style="padding: 15px; background: #f0fff4; border-radius: 10px; margin-bottom: 10px;">
                                <h3 style="color: #48bb78; margin: 0;">{{ $d['contributions']['fully_paid'] }}</h3>
                                <small style="color: #2d3748;">Fully Paid</small>
                                <div style="font-size: 12px; color: #718096;">{{ $d['contributions']['fully_paid_percent'] }}%</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div style="padding: 15px; background: #fffaf0; border-radius: 10px; margin-bottom: 10px;">
                                <h3 style="color: #ed8936; margin: 0;">{{ $d['contributions']['partial_paid'] }}</h3>
                                <small style="color: #2d3748;">Partial Payment</small>
                                <div style="font-size: 12px; color: #718096;">{{ $d['contributions']['partial_paid_percent'] }}%</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div style="padding: 15px; background: #fff5f5; border-radius: 10px; margin-bottom: 10px;">
                                <h3 style="color: #f56565; margin: 0;">{{ $d['contributions']['not_paid'] }}</h3>
                                <small style="color: #2d3748;">Not Paid</small>
                                <div style="font-size: 12px; color: #718096;">{{ $d['contributions']['not_paid_percent'] }}%</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div style="border-top: 1px solid #e2e8f0; padding-top: 15px;">
                    <h4 style="margin-bottom: 15px; color: #2d3748;">By Category</h4>
                    @foreach($d['contributions']['by_category'] as $cat)
                    <div style="display: flex; justify-content: space-between; margin-bottom: 10px; padding: 8px; background: #f7fafc; border-radius: 5px;">
                        <span><strong>{{ $cat->category_id }}</strong> ({{ $cat->count }})</span>
                        <span class="badge-custom badge-success">{{ $currency }} {{ number_format($cat->total_amount) }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    
    {{-- Stock Alerts --}}
    @if(count($d['stock_alerts']['out_of_stock']) > 0 || count($d['stock_alerts']['low_stock']) > 0)
    <div class="row">
        <div class="col-lg-12">
            <div class="dashboard-card">
                <div class="section-title">
                    <i class="fa fa-exclamation-triangle"></i> Stock Alerts - Action Required!
                </div>
                
                <div class="row">
                    @if(count($d['stock_alerts']['out_of_stock']) > 0)
                    <div class="col-lg-6">
                        <h5 style="color: #f56565; margin-bottom: 15px;">
                            <i class="fa fa-times-circle"></i> Out of Stock ({{ count($d['stock_alerts']['out_of_stock']) }})
                        </h5>
                        @foreach($d['stock_alerts']['out_of_stock'] as $item)
                        <div class="alert-item alert-danger">
                            <strong>{{ $item->name }}</strong>
                            @if($item->sku)
                            <small style="color: #718096;"> - SKU: {{ $item->sku }}</small>
                            @endif
                        </div>
                        @endforeach
                    </div>
                    @endif
                    
                    @if(count($d['stock_alerts']['low_stock']) > 0)
                    <div class="col-lg-6">
                        <h5 style="color: #ed8936; margin-bottom: 15px;">
                            <i class="fa fa-warning"></i> Low Stock ({{ count($d['stock_alerts']['low_stock']) }})
                        </h5>
                        @foreach($d['stock_alerts']['low_stock'] as $item)
                        <div class="alert-item alert-warning">
                            <strong>{{ $item->name }}</strong>
                            <span class="badge-custom badge-warning" style="float: right;">{{ $item->current_quantity }} units left</span>
                            @if($item->sku)
                            <br><small style="color: #718096;">SKU: {{ $item->sku }}</small>
                            @endif
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
                
                <div style="margin-top: 15px; text-align: center;">
                    <a href="{{ admin_url('stock-items') }}" class="quick-action-btn">
                        <i class="fa fa-cubes"></i> Manage Stock Items
                    </a>
                </div>
            </div>
        </div>
    </div>
    @endif
    
    {{-- Top Performers --}}
    <div class="row">
        <div class="col-lg-6">
            <div class="dashboard-card">
                <div class="section-title">
                    <i class="fa fa-trophy"></i> Top 5 Products
                </div>
                
                <table class="table-dashboard">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product Name</th>
                            <th>Quantity Sold</th>
                            <th>Total Sales</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($d['top_performers']['products'] as $index => $product)
                        <tr>
                            <td><strong>{{ $index + 1 }}</strong></td>
                            <td>{{ $product->name }}</td>
                            <td>{{ number_format($product->total_quantity) }}</td>
                            <td><span class="badge-custom badge-success">{{ $currency }} {{ number_format($product->total_sales) }}</span></td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" style="text-align: center; color: #718096;">No sales data available</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="col-lg-6">
            <div class="dashboard-card">
                <div class="section-title">
                    <i class="fa fa-star"></i> Top 5 Contributors
                </div>
                
                <table class="table-dashboard">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Total Contributed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($d['top_performers']['contributors'] as $index => $contributor)
                        <tr>
                            <td><strong>{{ $index + 1 }}</strong></td>
                            <td>{{ $contributor->name }}</td>
                            <td><span class="badge-custom badge-info">{{ $contributor->category_id }}</span></td>
                            <td><span class="badge-custom badge-success">{{ $currency }} {{ number_format($contributor->total_contributed) }}</span></td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" style="text-align: center; color: #718096;">No contribution data available</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    {{-- Category Performance --}}
    <div class="row">
        <div class="col-lg-12">
            <div class="dashboard-card">
                <div class="section-title">
                    <i class="fa fa-pie-chart"></i> Financial Categories Performance
                </div>
                
                <div style="overflow-x: auto;">
                    <table class="table-dashboard">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Income</th>
                                <th>Expenses</th>
                                <th>Balance</th>
                                <th>Performance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($d['category_performance'] as $category)
                            <tr>
                                <td><strong>{{ $category->category_name }}</strong></td>
                                <td><span class="badge-custom badge-success">{{ $currency }} {{ number_format($category->income) }}</span></td>
                                <td><span class="badge-custom badge-danger">{{ $currency }} {{ number_format($category->expense) }}</span></td>
                                <td>
                                    <span class="badge-custom {{ $category->balance >= 0 ? 'badge-success' : 'badge-danger' }}">
                                        {{ $currency }} {{ number_format($category->balance) }}
                                    </span>
                                </td>
                                <td>
                                    @php
                                        $total = $category->income + $category->expense;
                                        $percentage = $total > 0 ? ($category->balance / $total) * 100 : 0;
                                    @endphp
                                    <div class="progress-custom" style="height: 20px;">
                                        <div class="progress-bar-custom" style="width: {{ abs($percentage) }}%; background: {{ $category->balance >= 0 ? '#48bb78' : '#f56565' }};">
                                            {{ number_format($percentage, 1) }}%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="5" style="text-align: center; color: #718096;">No category data available</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    {{-- Pending Payments --}}
    @if($d['pending_payments']['total_outstanding'] > 0)
    <div class="row">
        <div class="col-lg-12">
            <div class="dashboard-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h3 style="margin: 0; color: white;">
                            <i class="fa fa-clock-o"></i> Pending Payments & Pledges
                        </h3>
                        <p style="margin: 5px 0 0 0; opacity: 0.9;">Total Outstanding: <strong>{{ $currency }} {{ number_format($d['pending_payments']['total_outstanding']) }}</strong></p>
                    </div>
                    <div style="text-align: right;">
                        <a href="{{ admin_url('contribution-records') }}" class="btn btn-light">
                            <i class="fa fa-eye"></i> View All
                        </a>
                    </div>
                </div>
                
                <div class="row" style="margin-top: 20px;">
                    <div class="col-md-4">
                        <div style="padding: 20px; background: rgba(255,255,255,0.2); border-radius: 10px; text-align: center;">
                            <h2 style="margin: 0; color: white;">{{ $d['pending_payments']['total_pledges'] }}</h2>
                            <p style="margin: 5px 0 0 0; opacity: 0.9;">Total Pledges</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div style="padding: 20px; background: rgba(255,87,87,0.3); border-radius: 10px; text-align: center;">
                            <h2 style="margin: 0; color: white;">{{ $d['pending_payments']['overdue_count'] }}</h2>
                            <p style="margin: 5px 0 0 0; opacity: 0.9;">Overdue ({{ $currency }} {{ number_format($d['pending_payments']['overdue_amount']) }})</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div style="padding: 20px; background: rgba(255,170,51,0.3); border-radius: 10px; text-align: center;">
                            <h2 style="margin: 0; color: white;">{{ $d['pending_payments']['due_week_count'] }}</h2>
                            <p style="margin: 5px 0 0 0; opacity: 0.9;">Due This Week ({{ $currency }} {{ number_format($d['pending_payments']['due_week_amount']) }})</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif
    
    {{-- Recent Activities --}}
    <div class="row">
        <div class="col-lg-12">
            <div class="dashboard-card">
                <div class="section-title">
                    <i class="fa fa-clock-o"></i> Recent Activities
                </div>
                
                @forelse($d['recent_activities'] as $activity)
                <div class="activity-item">
                    <div class="activity-icon" style="background: 
                        @if($activity->type == 'sale') #e6fffa 
                        @elseif($activity->type == 'contribution') #fff5f7 
                        @else #fef5e7 
                        @endif; color: 
                        @if($activity->type == 'sale') #319795 
                        @elseif($activity->type == 'contribution') #d53f8c 
                        @else #d69e2e 
                        @endif;">
                        @if($activity->type == 'sale')
                            <i class="fa fa-shopping-cart"></i>
                        @elseif($activity->type == 'contribution')
                            <i class="fa fa-money"></i>
                        @else
                            <i class="fa fa-credit-card"></i>
                        @endif
                    </div>
                    <div style="flex: 1;">
                        <strong>
                            @if($activity->type == 'sale') Sale:
                            @elseif($activity->type == 'contribution') Contribution:
                            @else Expense:
                            @endif
                        </strong> {{ $activity->item_name }}
                        <br>
                        <small style="color: #718096;">{{ \Carbon\Carbon::parse($activity->created_at)->diffForHumans() }}</small>
                    </div>
                    <div>
                        <span class="badge-custom {{ $activity->type == 'expense' ? 'badge-danger' : 'badge-success' }}">
                            {{ $currency }} {{ number_format($activity->amount) }}
                        </span>
                    </div>
                </div>
                @empty
                <div style="text-align: center; padding: 40px; color: #718096;">
                    <i class="fa fa-inbox" style="font-size: 48px; margin-bottom: 10px;"></i>
                    <p>No recent activities</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>
    
    {{-- Quick Actions --}}
    <div class="row">
        <div class="col-lg-12">
            <div class="dashboard-card" style="text-align: center; padding: 30px;">
                <h4 style="margin-bottom: 20px; color: #2d3748;">
                    <i class="fa fa-bolt"></i> Quick Actions
                </h4>
                <a href="{{ admin_url('contribution-records/create') }}" class="quick-action-btn">
                    <i class="fa fa-plus"></i> Add Contribution
                </a>
                <a href="{{ admin_url('stock-records/create') }}" class="quick-action-btn">
                    <i class="fa fa-shopping-cart"></i> Record Sale
                </a>
                <a href="{{ admin_url('financial-records/create') }}" class="quick-action-btn">
                    <i class="fa fa-money"></i> Add Transaction
                </a>
                <a href="{{ admin_url('stock-items/create') }}" class="quick-action-btn">
                    <i class="fa fa-cube"></i> Add Stock Item
                </a>
                <a href="{{ admin_url('budget-programs') }}" class="quick-action-btn">
                    <i class="fa fa-bullseye"></i> Manage Programs
                </a>
            </div>
        </div>
    </div>
    
</div>

<script>
    // Add animation on scroll
    document.addEventListener('DOMContentLoaded', function() {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, {
            threshold: 0.1
        });
        
        document.querySelectorAll('.dashboard-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'all 0.6s ease';
            observer.observe(card);
        });
    });
</script>
