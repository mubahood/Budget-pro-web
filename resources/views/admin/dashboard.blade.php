@php
    $currency = $company->currency ?? 'UGX';
    $d = $data;
@endphp

<style>
    * {
        font-size: 13px;
    }
    
    .dash-container {
        background: #f5f6fa;
        padding: 15px;
        margin: -15px;
    }
    
    .dash-card {
        background: #fff;
        border: 1px solid #e1e4e8;
        border-radius: 6px;
        padding: 20px;
        margin-bottom: 15px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    }
    
    .dash-header {
        font-size: 14px;
        font-weight: 700;
        color: #2c3e50;
        margin: 0 0 12px 0;
        padding-bottom: 8px;
        border-bottom: 2px solid #3498db;
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
        border: 1px solid #e1e4e8;
        border-left: 4px solid #3498db;
        border-radius: 6px;
        padding: 18px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    }
    
    .stat-box:nth-child(2) {
        border-left-color: #2ecc71;
    }
    
    .stat-box:nth-child(3) {
        border-left-color: #3498db;
    }
    
    .stat-box:nth-child(4) {
        border-left-color: #27ae60;
    }
    
    .stat-box:nth-child(5) {
        border-left-color: #e67e22;
    }
    
    .stat-box:nth-child(6) {
        border-left-color: #9b59b6;
    }
    
    .stat-label {
        font-size: 11px;
        color: #7f8c8d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin: 0 0 5px 0;
        font-weight: 600;
    }
    
    .stat-value {
        font-size: 24px;
        font-weight: 700;
        color: #2c3e50;
        margin: 5px 0;
    }
    
    .stat-sub {
        font-size: 11px;
        color: #95a5a6;
        margin: 3px 0 0 0;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    
    table th {
        background: #34495e;
        padding: 10px;
        text-align: left;
        font-weight: 700;
        color: #fff;
        border: none;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    table td {
        padding: 10px;
        border-bottom: 1px solid #e1e4e8;
        color: #2c3e50;
    }
    
    table tr:hover {
        background: #f8f9fa;
    }
    
    .badge {
        display: inline-block;
        padding: 4px 12px;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        border-radius: 4px;
    }
    
    .badge-danger {
        background: #e74c3c;
        color: #fff;
    }
    
    .badge-warning {
        background: #f39c12;
        color: #fff;
    }
    
    .text-primary {
        color: #3498db;
        font-weight: 700;
    }
    
    .text-muted {
        color: #7f8c8d;
    }
    
    .text-success {
        color: #27ae60;
        font-weight: 700;
    }
    
    .text-danger {
        color: #e74c3c;
        font-weight: 700;
    }
    
    .text-warning {
        color: #f39c12;
        font-weight: 700;
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
        font-style: italic;
    }
</style>

<div class="dash-container">
    
    {{-- Sales Overview Stats --}}
    <div class="stat-grid">
        <div class="stat-box">
            <p class="stat-label">Total Sales</p>
            <h3 class="stat-value">{{ number_format($d['sales_overview']['total_sales'] ?? 0) }}</h3>
            <p class="stat-sub">{{ number_format($d['sales_overview']['total_customers'] ?? 0) }} Customers</p>
        </div>
        
        <div class="stat-box">
            <p class="stat-label">Total Revenue</p>
            <h3 class="stat-value">{{ $currency }} {{ number_format($d['sales_overview']['total_revenue'] ?? 0) }}</h3>
            <p class="stat-sub">All Sales</p>
        </div>
        
        <div class="stat-box">
            <p class="stat-label">Collected</p>
            <h3 class="stat-value">{{ $currency }} {{ number_format($d['sales_overview']['total_collected'] ?? 0) }}</h3>
            <p class="stat-sub">{{ number_format($d['sales_overview']['collection_rate'] ?? 0, 1) }}% Collection Rate</p>
        </div>
        
        <div class="stat-box">
            <p class="stat-label">Outstanding</p>
            <h3 class="stat-value" style="color: #f44336;">{{ $currency }} {{ number_format($d['sales_overview']['total_outstanding'] ?? 0) }}</h3>
            <p class="stat-sub">Pending Payment</p>
        </div>
        
        <div class="stat-box">
            <p class="stat-label">Avg Sale Value</p>
            <h3 class="stat-value">{{ $currency }} {{ number_format($d['sales_overview']['avg_sale_value'] ?? 0) }}</h3>
            <p class="stat-sub">Per Transaction</p>
        </div>
        
        <div class="stat-box">
            <p class="stat-label">Payment Status</p>
            <h3 class="stat-value">{{ number_format($d['sales_overview']['paid_percentage'] ?? 0, 1) }}%</h3>
            <p class="stat-sub">{{ $d['sales_overview']['paid_count'] ?? 0 }} Paid, {{ $d['sales_overview']['unpaid_count'] ?? 0 }} Unpaid</p>
        </div>
    </div>

    {{-- Financial Overview --}}
    <div class="dash-card">
        <h4 class="dash-header">Financial Overview</h4>
        <div class="stat-grid">
            <div class="stat-box">
                <p class="stat-label">Sales Income</p>
                <h3 class="stat-value" style="color: #4caf50;">{{ $currency }} {{ number_format($d['financial_overview']['sales_income'] ?? 0) }}</h3>
            </div>
            <div class="stat-box">
                <p class="stat-label">Other Income</p>
                <h3 class="stat-value" style="color: #4caf50;">{{ $currency }} {{ number_format($d['financial_overview']['other_income'] ?? 0) }}</h3>
            </div>
            <div class="stat-box">
                <p class="stat-label">Total Expenses</p>
                <h3 class="stat-value" style="color: #f44336;">{{ $currency }} {{ number_format($d['financial_overview']['total_expense'] ?? 0) }}</h3>
            </div>
            <div class="stat-box">
                <p class="stat-label">Net Profit</p>
                <h3 class="stat-value" style="color: {{ ($d['financial_overview']['net_profit'] ?? 0) >= 0 ? '#4caf50' : '#f44336' }};">
                    {{ $currency }} {{ number_format($d['financial_overview']['net_profit'] ?? 0) }}
                </h3>
                <p class="stat-sub">{{ number_format($d['financial_overview']['profit_margin'] ?? 0, 1) }}% Margin</p>
            </div>
        </div>
    </div>

    {{-- Debts & Receivables --}}
    @if(($d['debts_receivables']['total_debt'] ?? 0) > 0)
    <div class="dash-card">
        <h4 class="dash-header">Debts & Receivables</h4>
        <div class="stat-grid">
            <div class="stat-box">
                <p class="stat-label">Total Debtors</p>
                <h3 class="stat-value">{{ number_format($d['debts_receivables']['total_debtors'] ?? 0) }}</h3>
                <p class="stat-sub">Customers with Balance</p>
            </div>
            <div class="stat-box">
                <p class="stat-label">Total Debt</p>
                <h3 class="stat-value" style="color: #f44336;">{{ $currency }} {{ number_format($d['debts_receivables']['total_debt'] ?? 0) }}</h3>
                <p class="stat-sub">Outstanding Amount</p>
            </div>
            <div class="stat-box">
                <p class="stat-label">Fully Unpaid</p>
                <h3 class="stat-value" style="color: #f44336;">{{ $currency }} {{ number_format($d['debts_receivables']['fully_unpaid'] ?? 0) }}</h3>
            </div>
            <div class="stat-box">
                <p class="stat-label">Partial Unpaid</p>
                <h3 class="stat-value" style="color: #ff9800;">{{ $currency }} {{ number_format($d['debts_receivables']['partial_unpaid'] ?? 0) }}</h3>
            </div>
            <div class="stat-box">
                <p class="stat-label">Overdue 30+ Days</p>
                <h3 class="stat-value" style="color: #f44336;">{{ $currency }} {{ number_format($d['debts_receivables']['overdue_30'] ?? 0) }}</h3>
            </div>
            <div class="stat-box">
                <p class="stat-label">Overdue 60+ Days</p>
                <h3 class="stat-value" style="color: #d32f2f;">{{ $currency }} {{ number_format($d['debts_receivables']['overdue_60'] ?? 0) }}</h3>
            </div>
        </div>

        {{-- Top Debtors --}}
        @if(!empty($d['debts_receivables']['top_debtors']))
        <h4 class="dash-header" style="margin-top: 20px;">Top Debtors</h4>
        <table>
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Phone</th>
                    <th>Sales</th>
                    <th>Outstanding</th>
                    <th>Last Sale</th>
                </tr>
            </thead>
            <tbody>
                @foreach($d['debts_receivables']['top_debtors'] as $debtor)
                    <tr>
                        <td><strong>{{ $debtor->customer_name }}</strong></td>
                        <td>{{ $debtor->customer_phone ?? 'N/A' }}</td>
                        <td>{{ $debtor->sale_count }}</td>
                        <td class="text-primary">{{ $currency }} {{ number_format($debtor->total_debt) }}</td>
                        <td>{{ \Carbon\Carbon::parse($debtor->last_sale_date)->format('d M Y') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
    @endif

    {{-- Inventory Overview Stats --}}
    <div class="dash-card">
        <h4 class="dash-header">Inventory Overview</h4>
        <div class="stat-grid">
            <div class="stat-box">
                <p class="stat-label">Total Items</p>
                <h3 class="stat-value">{{ number_format($d['inventory_overview']['total_items'] ?? 0) }}</h3>
                <p class="stat-sub">In Stock</p>
            </div>
            
            <div class="stat-box">
                <p class="stat-label">Stock Value</p>
                <h3 class="stat-value">{{ $currency }} {{ number_format($d['inventory_overview']['total_stock_value'] ?? 0) }}</h3>
                <p class="stat-sub">Buying Price</p>
            </div>
            
            <div class="stat-box">
                <p class="stat-label">Potential Revenue</p>
                <h3 class="stat-value">{{ $currency }} {{ number_format($d['inventory_overview']['potential_revenue'] ?? 0) }}</h3>
                <p class="stat-sub">Selling Price</p>
            </div>
            
            <div class="stat-box">
                <p class="stat-label">Potential Profit</p>
                <h3 class="stat-value" style="color: #4caf50;">{{ $currency }} {{ number_format($d['inventory_overview']['potential_profit'] ?? 0) }}</h3>
                <p class="stat-sub">If All Sold</p>
            </div>
            
            <div class="stat-box">
                <p class="stat-label">Out of Stock</p>
                <h3 class="stat-value" style="color: #f44336;">{{ $d['inventory_overview']['out_of_stock'] ?? 0 }}</h3>
                <p class="stat-sub">Items Depleted</p>
            </div>
            
            <div class="stat-box">
                <p class="stat-label">Low Stock</p>
                <h3 class="stat-value" style="color: #ff9800;">{{ $d['inventory_overview']['low_stock'] ?? 0 }}</h3>
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
            
            <div class="stat-box">
                <p class="stat-label">Best Category</p>
                <h3 class="stat-value" style="font-size: 14px;">{{ $d['inventory_overview']['best_category'] ?? 'N/A' }}</h3>
                <p class="stat-sub">{{ $d['inventory_overview']['best_category_sales'] ?? 0 }} Sales</p>
            </div>
        </div>
    </div>

    {{-- Sales Performance --}}
    <div class="grid-2">
        {{-- Quick Stats --}}
        <div class="dash-card">
            <h4 class="dash-header">Sales Performance</h4>
            <table>
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Revenue</th>
                        <th>Collected</th>
                        <th>Transactions</th>
                        <th>Avg Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Today</strong></td>
                        <td class="text-primary">{{ $currency }} {{ number_format($d['quick_stats']['today']['sales'] ?? 0) }}</td>
                        <td class="text-success">{{ $currency }} {{ number_format($d['quick_stats']['today']['collected'] ?? 0) }}</td>
                        <td>{{ $d['quick_stats']['today']['transactions'] ?? 0 }}</td>
                        <td>{{ $currency }} {{ number_format($d['quick_stats']['today']['avg_value'] ?? 0) }}</td>
                    </tr>
                    <tr>
                        <td><strong>This Week</strong></td>
                        <td class="text-primary">{{ $currency }} {{ number_format($d['quick_stats']['week']['sales'] ?? 0) }}</td>
                        <td class="text-success">{{ $currency }} {{ number_format($d['quick_stats']['week']['collected'] ?? 0) }}</td>
                        <td>{{ $d['quick_stats']['week']['transactions'] ?? 0 }}</td>
                        <td>{{ $currency }} {{ number_format($d['quick_stats']['week']['avg_value'] ?? 0) }}</td>
                    </tr>
                    <tr>
                        <td><strong>This Month</strong></td>
                        <td class="text-primary">{{ $currency }} {{ number_format($d['quick_stats']['month']['sales'] ?? 0) }}</td>
                        <td class="text-success">{{ $currency }} {{ number_format($d['quick_stats']['month']['collected'] ?? 0) }}</td>
                        <td>{{ $d['quick_stats']['month']['transactions'] ?? 0 }}</td>
                        <td>{{ $currency }} {{ number_format($d['quick_stats']['month']['avg_value'] ?? 0) }}</td>
                    </tr>
                    <tr>
                        <td><strong>This Year</strong></td>
                        <td class="text-primary">{{ $currency }} {{ number_format($d['quick_stats']['year']['sales'] ?? 0) }}</td>
                        <td class="text-success">{{ $currency }} {{ number_format($d['quick_stats']['year']['collected'] ?? 0) }}</td>
                        <td>{{ $d['quick_stats']['year']['transactions'] ?? 0 }}</td>
                        <td>{{ $currency }} {{ number_format($d['quick_stats']['year']['avg_value'] ?? 0) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Week Activity --}}
        <div class="dash-card">
            <h4 class="dash-header">This Week Activity</h4>
            @if(!empty($d['stock_alerts']['week_activity']))
                <table>
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Transactions</th>
                            <th>Quantity</th>
                            <th>Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($d['stock_alerts']['week_activity'] as $activity)
                            <tr>
                                <td><strong>{{ ucfirst($activity->type) }}</strong></td>
                                <td>{{ number_format($activity->count) }}</td>
                                <td>{{ number_format($activity->total_quantity) }}</td>
                                <td class="text-primary">{{ $currency }} {{ number_format($activity->total_value) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="no-data">No activity this week</div>
            @endif
        </div>
    </div>

    {{-- Top Products & Top Customers --}}
    <div class="grid-2">
        {{-- Top Selling Products --}}
        <div class="dash-card">
            <h4 class="dash-header">Top Selling Products</h4>
            @if(!empty($d['top_performers']['products']))
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Sales</th>
                            <th>Revenue</th>
                            <th>Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($d['top_performers']['products'] as $product)
                            <tr>
                                <td><strong>{{ $product->name }}</strong></td>
                                <td>{{ $product->sku }}</td>
                                <td>{{ $product->sale_count }}</td>
                                <td class="text-primary">{{ $currency }} {{ number_format($product->total_revenue) }}</td>
                                <td>{{ number_format($product->total_quantity) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="no-data">No sales data available</div>
            @endif
        </div>

        {{-- Top Customers --}}
        <div class="dash-card">
            <h4 class="dash-header">Top Customers</h4>
            @if(!empty($d['top_performers']['customers']))
                <table>
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Purchases</th>
                            <th>Total Spent</th>
                            <th>Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($d['top_performers']['customers'] as $customer)
                            <tr>
                                <td><strong>{{ $customer->customer_name }}</strong></td>
                                <td>{{ $customer->customer_phone ?? 'N/A' }}</td>
                                <td>{{ $customer->purchase_count }}</td>
                                <td class="text-primary">{{ $currency }} {{ number_format($customer->total_spent) }}</td>
                                <td style="color: {{ $customer->outstanding_balance > 0 ? '#f44336' : '#4caf50' }};">
                                    {{ $currency }} {{ number_format($customer->outstanding_balance) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="no-data">No customer data available</div>
            @endif
        </div>
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
