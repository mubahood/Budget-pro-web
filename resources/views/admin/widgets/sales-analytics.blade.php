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
            <!-- Sales Trends Chart -->
            <div class="col-md-8">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">
                            <i class="fa fa-line-chart"></i> 12-Month Sales Trend
                        </h3>
                    </div>
                    <div class="box-body">
                        <canvas id="salesTrendChart" height="80"></canvas>
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

        <!-- Daily Sales Chart -->
        <div class="row">
            <div class="col-md-12">
                <div class="box box-info">
                    <div class="box-header with-border">
                        <h3 class="box-title">
                            <i class="fa fa-bar-chart"></i> Daily Sales (Current Month)
                        </h3>
                    </div>
                    <div class="box-body">
                        <canvas id="dailySalesChart" height="60"></canvas>
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
    
    // Sales Trend Chart
    var trendsCtx = document.getElementById('salesTrendChart').getContext('2d');
    var trendsChart = new Chart(trendsCtx, {
        type: 'line',
        data: {
            labels: {!! json_encode($data['trends']['labels']) !!},
            datasets: [{
                label: 'Revenue (UGX)',
                data: {!! json_encode($data['trends']['revenue']) !!},
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Revenue: UGX ' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return 'UGX ' + value.toLocaleString();
                        }
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

    // Daily Sales Chart
    var dailyCtx = document.getElementById('dailySalesChart').getContext('2d');
    var dailyChart = new Chart(dailyCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($data['daily_sales']['labels']) !!},
            datasets: [{
                label: 'Daily Revenue (UGX)',
                data: {!! json_encode($data['daily_sales']['revenue']) !!},
                backgroundColor: 'rgba(54, 162, 235, 0.6)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            var transactions = {!! json_encode($data['daily_sales']['transactions']) !!}[context.dataIndex];
                            return [
                                'Revenue: UGX ' + context.parsed.y.toLocaleString(),
                                'Transactions: ' + transactions
                            ];
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return 'UGX ' + value.toLocaleString();
                        }
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
