<div class="box box-solid" style="border-top: 3px solid #00c0ef;">
    <div class="box-header with-border">
        <h3 class="box-title">
            <i class="fa fa-line-chart"></i> Sales Analytics Dashboard
        </h3>
        <div class="box-tools">
            <button type="button" class="btn btn-box-tool" data-widget="collapse">
                <i class="fa fa-minus"></i>
            </button>
        </div>
    </div>
    <div class="box-body">
        
        <!-- Overview Cards -->
        <div class="row">
            <!-- Today's Sales -->
            <div class="col-md-3 col-sm-6">
                <div class="info-box bg-aqua">
                    <span class="info-box-icon"><i class="fa fa-calendar-check-o"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Today's Sales</span>
                        <span class="info-box-number">UGX {{ number_format($data['overview']['today']['revenue']) }}</span>
                        <div class="progress">
                            <div class="progress-bar" style="width: 100%"></div>
                        </div>
                        <span class="progress-description">
                            {{ number_format($data['overview']['today']['transactions']) }} transactions
                        </span>
                    </div>
                </div>
            </div>

            <!-- This Week -->
            <div class="col-md-3 col-sm-6">
                <div class="info-box bg-green">
                    <span class="info-box-icon"><i class="fa fa-calendar"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">This Week</span>
                        <span class="info-box-number">UGX {{ number_format($data['overview']['week']['revenue']) }}</span>
                        <div class="progress">
                            <div class="progress-bar" style="width: 100%"></div>
                        </div>
                        <span class="progress-description">
                            {{ number_format($data['overview']['week']['units_sold']) }} units sold
                        </span>
                    </div>
                </div>
            </div>

            <!-- This Month -->
            <div class="col-md-3 col-sm-6">
                <div class="info-box bg-yellow">
                    <span class="info-box-icon"><i class="fa fa-calendar-o"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">This Month</span>
                        <span class="info-box-number">UGX {{ number_format($data['overview']['month']['revenue']) }}</span>
                        <div class="progress">
                            <div class="progress-bar" style="width: 100%"></div>
                        </div>
                        <span class="progress-description">
                            @if($data['overview']['month']['growth_rate'] >= 0)
                                <i class="fa fa-arrow-up"></i> {{ number_format($data['overview']['month']['growth_rate'], 1) }}% growth
                            @else
                                <i class="fa fa-arrow-down"></i> {{ number_format(abs($data['overview']['month']['growth_rate']), 1) }}% decline
                            @endif
                        </span>
                    </div>
                </div>
            </div>

            <!-- Month's Profit -->
            <div class="col-md-3 col-sm-6">
                <div class="info-box bg-red">
                    <span class="info-box-icon"><i class="fa fa-money"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Month's Profit</span>
                        <span class="info-box-number">UGX {{ number_format($data['overview']['month']['profit']) }}</span>
                        <div class="progress">
                            <div class="progress-bar" style="width: 100%"></div>
                        </div>
                        <span class="progress-description">
                            Avg: UGX {{ number_format($data['overview']['today']['avg_transaction']) }}/txn
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="row">
            <!-- Financial Overview (Income & Expense) -->
            <div class="col-md-8">
                <!-- Summary Cards -->
                <div class="row" style="margin-bottom: 15px;">
                    <div class="col-md-4">
                        <div class="info-box" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; border-radius: 8px;">
                            <span class="info-box-icon" style="background: rgba(255,255,255,0.2);">
                                <i class="fa fa-arrow-down"></i>
                            </span>
                            <div class="info-box-content">
                                <span class="info-box-text" style="color: rgba(255,255,255,0.9);">Total Income</span>
                                <span class="info-box-number" style="font-size: 20px;">
                                    UGX {{ number_format($data['financial_data']['total_income']) }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box" style="background: linear-gradient(135deg, #dc3545 0%, #e83e8c 100%); color: white; border-radius: 8px;">
                            <span class="info-box-icon" style="background: rgba(255,255,255,0.2);">
                                <i class="fa fa-arrow-up"></i>
                            </span>
                            <div class="info-box-content">
                                <span class="info-box-text" style="color: rgba(255,255,255,0.9);">Total Expense</span>
                                <span class="info-box-number" style="font-size: 20px;">
                                    UGX {{ number_format($data['financial_data']['total_expense']) }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        @php
                            $balance = $data['financial_data']['balance'];
                            $balanceColor = $balance >= 0 ? '#17a2b8' : '#6c757d';
                            $balanceGradient = $balance >= 0 
                                ? 'linear-gradient(135deg, #17a2b8 0%, #138496 100%)' 
                                : 'linear-gradient(135deg, #6c757d 0%, #5a6268 100%)';
                        @endphp
                        <div class="info-box" style="background: {{ $balanceGradient }}; color: white; border-radius: 8px;">
                            <span class="info-box-icon" style="background: rgba(255,255,255,0.2);">
                                <i class="fa fa-balance-scale"></i>
                            </span>
                            <div class="info-box-content">
                                <span class="info-box-text" style="color: rgba(255,255,255,0.9);">Balance</span>
                                <span class="info-box-number" style="font-size: 20px;">
                                    UGX {{ number_format(abs($balance)) }}
                                    @if($balance < 0)
                                        <small style="font-size: 12px;">(Deficit)</small>
                                    @endif
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Financial Chart -->
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">
                            <i class="fa fa-line-chart"></i> Daily Financial Overview (Income & Expense)
                        </h3>
                    </div>
                    <div class="box-body">
                        <canvas id="financialChart" height="80"></canvas>
                    </div>
                </div>
            </div>

            <!-- Category Breakdown -->
            <div class="col-md-4">
                <div class="box box-success">
                    <div class="box-header with-border">
                        <h3 class="box-title">
                            <i class="fa fa-pie-chart"></i> Sales by Category
                        </h3>
                    </div>
                    <div class="box-body">
                        <canvas id="categoryChart" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Daily Sales Chart & List -->
        <div class="row">
            <div class="col-md-8">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">
                            <i class="fa fa-line-chart"></i> Daily Sales & Profit (Current Month)
                        </h3>
                    </div>
                    <div class="box-body">
                        <canvas id="dailySalesChart" height="100"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="box box-success" style="border-top: 3px solid #28a745;">
                    <div class="box-header with-border">
                        <h3 class="box-title">
                            <i class="fa fa-list"></i> Last 30 Days Details
                        </h3>
                    </div>
                    <div class="box-body" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-hover table-condensed" style="margin-bottom: 0;">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th class="text-right">Sales</th>
                                    <th class="text-right">Profit</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    // Get last 30 days of sales
                                    $companyId = auth()->user()->company_id ?? 25;
                                    $last30Days = DB::select("
                                        SELECT 
                                            DATE(sr.sale_date) as date,
                                            COALESCE(SUM(sr.total_amount), 0) as revenue,
                                            COALESCE(SUM(sri.quantity * (sri.unit_price - si.buying_price)), 0) as profit
                                        FROM sale_records sr
                                        LEFT JOIN sale_record_items sri ON sr.id = sri.sale_record_id
                                        LEFT JOIN stock_items si ON sri.stock_item_id = si.id
                                        WHERE sr.company_id = ?
                                        AND DATE(sr.sale_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                        AND DATE(sr.sale_date) <= CURDATE()
                                        GROUP BY DATE(sr.sale_date)
                                        ORDER BY date DESC
                                    ", [$companyId]);
                                @endphp
                                @php
                                    $previousProfit = null;
                                @endphp
                                @forelse($last30Days as $index => $day)
                                    @php
                                        $currentProfit = (float) $day->profit;
                                        $isDecrease = $previousProfit !== null && $currentProfit < $previousProfit;
                                        $bgColor = $isDecrease ? '#ffebee' : '#ffffff';
                                        $profitColor = $isDecrease ? '#c62828' : '#2e7d32';
                                        $icon = $isDecrease ? 'fa-arrow-down' : 'fa-arrow-up';
                                        $previousProfit = $currentProfit;
                                    @endphp
                                    <tr style="background: {{ $bgColor }};">
                                        <td style="padding: 10px 8px;">
                                            <i class="fa fa-calendar" style="margin-right: 5px;"></i>
                                            {{ \Carbon\Carbon::parse($day->date)->format('M j, Y') }}
                                        </td>
                                        <td class="text-right" style="padding: 10px 8px;">
                                            UGX {{ number_format($day->revenue, 0) }}
                                        </td>
                                        <td class="text-right" style="padding: 10px 8px; color: {{ $profitColor }}; font-weight: bold;">
                                            <i class="fa {{ $icon }}" style="margin-right: 3px;"></i>
                                            UGX {{ number_format($day->profit, 0) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center text-muted">No sales data available</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Products Table -->
        <div class="row">
            <div class="col-md-12">
                <div class="box box-warning">
                    <div class="box-header with-border">
                        <h3 class="box-title">
                            <i class="fa fa-trophy"></i> Top 10 Best-Selling Products (This Month)
                        </h3>
                    </div>
                    <div class="box-body">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr style="background: #f39c12; color: white;">
                                    <th>#</th>
                                    <th>Image</th>
                                    <th>Product Name</th>
                                    <th>Units Sold</th>
                                    <th>Revenue</th>
                                    <th>Profit</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($data['top_products'] as $index => $product)
                                    <tr>
                                        <td><strong>{{ $index + 1 }}</strong></td>
                                        <td>
                                            @if($product->image)
                                                <img src="{{ url('storage/' . $product->image) }}" 
                                                     alt="{{ $product->name }}" 
                                                     style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;">
                                            @else
                                                <div style="width: 40px; height: 40px; background: #ccc; border-radius: 4px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fa fa-image"></i>
                                                </div>
                                            @endif
                                        </td>
                                        <td><strong>{{ $product->name }}</strong></td>
                                        <td><span class="badge bg-blue">{{ number_format($product->total_sold) }} units</span></td>
                                        <td><span class="text-green"><strong>UGX {{ number_format($product->total_revenue) }}</strong></span></td>
                                        <td><span class="text-orange"><strong>UGX {{ number_format($product->total_profit) }}</strong></span></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">
                                            <i class="fa fa-info-circle"></i> No sales data available for this month
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Monthly Comparison -->
        <div class="row">
            <div class="col-md-12">
                <div class="box box-danger">
                    <div class="box-header with-border">
                        <h3 class="box-title">
                            <i class="fa fa-bar-chart"></i> 3-Month Comparison
                        </h3>
                    </div>
                    <div class="box-body">
                        <div class="row text-center">
                            @foreach($data['monthly_comparison'] as $month)
                                <div class="col-md-4">
                                    <div style="padding: 20px; border: 2px solid #dd4b39; border-radius: 8px; margin: 10px;">
                                        <h4 style="color: #dd4b39; margin-top: 0;">{{ $month['label'] }}</h4>
                                        <p style="font-size: 24px; font-weight: bold; margin: 10px 0;">
                                            UGX {{ number_format($month['revenue']) }}
                                        </p>
                                        <p style="color: #666; margin: 5px 0;">
                                            <i class="fa fa-shopping-cart"></i> {{ number_format($month['transactions']) }} transactions
                                        </p>
                                        <p style="color: #666; margin: 5px 0;">
                                            <i class="fa fa-cubes"></i> {{ number_format($month['units']) }} units sold
                                        </p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<script>
$(document).ready(function() {
    
    // Financial Chart (Income as Bars, Expense as Line)
    var financialCtx = document.getElementById('financialChart').getContext('2d');
    var financialChart = new Chart(financialCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($data['financial_data']['labels']) !!},
            datasets: [{
                label: 'Income',
                data: {!! json_encode($data['financial_data']['income']) !!},
                backgroundColor: 'rgba(40, 167, 69, 0.7)',
                borderColor: 'rgba(40, 167, 69, 1)',
                borderWidth: 1,
                yAxisID: 'y'
            }, {
                label: 'Expense',
                data: {!! json_encode($data['financial_data']['expense']) !!},
                type: 'line',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                borderColor: 'rgba(220, 53, 69, 1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointRadius: 5,
                pointHoverRadius: 8,
                pointBackgroundColor: 'rgba(220, 53, 69, 1)',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: 'rgba(220, 53, 69, 1)',
                yAxisID: 'y'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 15,
                        font: {
                            size: 13,
                            weight: '600'
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    titleColor: '#fff',
                    titleFont: {
                        size: 14,
                        weight: 'bold'
                    },
                    bodyColor: '#fff',
                    bodyFont: {
                        size: 13
                    },
                    borderColor: 'rgba(255, 255, 255, 0.1)',
                    borderWidth: 1,
                    callbacks: {
                        label: function(context) {
                            var label = context.dataset.label || '';
                            var value = context.parsed.y;
                            
                            if (label.includes('Income')) {
                                return '💰 ' + label + ': UGX ' + value.toLocaleString();
                            } else {
                                return '💸 ' + label + ': UGX ' + value.toLocaleString();
                            }
                        },
                        afterBody: function(context) {
                            if (context.length > 0) {
                                var index = context[0].dataIndex;
                                var income = {!! json_encode($data['financial_data']['income']) !!}[index];
                                var expense = {!! json_encode($data['financial_data']['expense']) !!}[index];
                                var balance = income - expense;
                                var balanceLabel = balance >= 0 ? '✅ Net: ' : '⚠️ Net: ';
                                return balanceLabel + 'UGX ' + balance.toLocaleString();
                            }
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    },
                    ticks: {
                        callback: function(value) {
                            return 'UGX ' + value.toLocaleString();
                        },
                        font: {
                            size: 11
                        },
                        color: '#6c757d'
                    },
                    title: {
                        display: true,
                        text: 'Amount (UGX)',
                        font: {
                            size: 12,
                            weight: 'bold'
                        },
                        color: '#495057'
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 11
                        },
                        color: '#6c757d'
                    }
                }
            }
        }
    });

    // Category Breakdown Chart
    var categoryCtx = document.getElementById('categoryChart').getContext('2d');
    var categoryLabels = {!! json_encode(array_column($data['category_breakdown'], 'name')) !!};
    var categorySales = {!! json_encode(array_column($data['category_breakdown'], 'sales')) !!};
    
    var categoryChart = new Chart(categoryCtx, {
        type: 'doughnut',
        data: {
            labels: categoryLabels,
            datasets: [{
                data: categorySales,
                backgroundColor: [
                    'rgba(255, 99, 132, 0.8)',
                    'rgba(54, 162, 235, 0.8)',
                    'rgba(255, 206, 86, 0.8)',
                    'rgba(75, 192, 192, 0.8)',
                    'rgba(153, 102, 255, 0.8)',
                    'rgba(255, 159, 64, 0.8)',
                    'rgba(199, 199, 199, 0.8)',
                    'rgba(83, 102, 255, 0.8)',
                    'rgba(255, 99, 255, 0.8)',
                    'rgba(99, 255, 132, 0.8)'
                ],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            var label = context.label || '';
                            var value = context.parsed || 0;
                            var total = context.dataset.data.reduce((a, b) => a + b, 0);
                            var percentage = ((value / total) * 100).toFixed(1);
                            return label + ': UGX ' + value.toLocaleString() + ' (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });

    // Daily Sales & Profit Chart (Mixed: Bar + Line)
    var dailyCtx = document.getElementById('dailySalesChart').getContext('2d');
    var dailyChart = new Chart(dailyCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($data['daily_sales']['labels']) !!},
            datasets: [{
                label: 'Daily Revenue',
                data: {!! json_encode($data['daily_sales']['revenue']) !!},
                backgroundColor: 'rgba(54, 162, 235, 0.7)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1,
                yAxisID: 'y'
            }, {
                label: 'Daily Profit',
                data: {!! json_encode($data['daily_sales']['profit']) !!},
                type: 'line',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                borderColor: 'rgba(40, 167, 69, 1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointRadius: 5,
                pointHoverRadius: 8,
                pointBackgroundColor: 'rgba(40, 167, 69, 1)',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: 'rgba(40, 167, 69, 1)',
                yAxisID: 'y'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 15,
                        font: {
                            size: 13,
                            weight: '600'
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    titleColor: '#fff',
                    titleFont: {
                        size: 14,
                        weight: 'bold'
                    },
                    bodyColor: '#fff',
                    bodyFont: {
                        size: 13
                    },
                    borderColor: 'rgba(255, 255, 255, 0.1)',
                    borderWidth: 1,
                    callbacks: {
                        label: function(context) {
                            var label = context.dataset.label || '';
                            var value = context.parsed.y;
                            var transactions = {!! json_encode($data['daily_sales']['transactions']) !!}[context.dataIndex];
                            
                            if (label.includes('Revenue')) {
                                return [
                                    '💰 ' + label + ': UGX ' + value.toLocaleString(),
                                    '📊 Transactions: ' + transactions
                                ];
                            } else {
                                return '📈 ' + label + ': UGX ' + value.toLocaleString();
                            }
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    },
                    ticks: {
                        callback: function(value) {
                            return 'UGX ' + value.toLocaleString();
                        },
                        font: {
                            size: 11
                        },
                        color: '#6c757d'
                    },
                    title: {
                        display: true,
                        text: 'Amount (UGX)',
                        font: {
                            size: 12,
                            weight: 'bold'
                        },
                        color: '#495057'
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 11
                        },
                        color: '#6c757d'
                    }
                }
            }
        }
    });
    
});
</script>

<style>
.info-box {
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.info-box-icon {
    font-size: 50px;
}
</style>
