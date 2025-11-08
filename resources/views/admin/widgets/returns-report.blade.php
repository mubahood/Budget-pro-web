<div class="box box-solid" style="border-top: 3px solid #f39c12;">
    <div class="box-header with-border">
        <h3 class="box-title">
            <i class="fa fa-undo"></i> Returns & Refunds Report
        </h3>
        <div class="box-tools">
            <button type="button" class="btn btn-box-tool" data-widget="collapse">
                <i class="fa fa-minus"></i>
            </button>
        </div>
    </div>
    <div class="box-body">
        
        <!-- Summary Cards -->
        <div class="row">
            <div class="col-md-4">
                <div class="info-box bg-yellow">
                    <span class="info-box-icon"><i class="fa fa-calendar-check-o"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Today's Returns</span>
                        <span class="info-box-number">{{ number_format($data['summary']['today']->returns_count) }}</span>
                        <div class="progress">
                            <div class="progress-bar" style="width: 100%"></div>
                        </div>
                        <span class="progress-description">
                            {{ number_format($data['summary']['today']->units_returned) }} units | UGX {{ number_format($data['summary']['today']->refund_total) }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="info-box bg-orange">
                    <span class="info-box-icon"><i class="fa fa-calendar-o"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">This Month</span>
                        <span class="info-box-number">{{ number_format($data['summary']['month']->returns_count) }}</span>
                        <div class="progress">
                            <div class="progress-bar" style="width: 100%"></div>
                        </div>
                        <span class="progress-description">
                            {{ number_format($data['summary']['month']->units_returned) }} units | UGX {{ number_format($data['summary']['month']->refund_total) }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="info-box bg-red">
                    <span class="info-box-icon"><i class="fa fa-history"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">All-Time Total</span>
                        <span class="info-box-number">{{ number_format($data['summary']['total']->returns_count) }}</span>
                        <div class="progress">
                            <div class="progress-bar" style="width: 100%"></div>
                        </div>
                        <span class="progress-description">
                            {{ number_format($data['summary']['total']->units_returned) }} units | UGX {{ number_format($data['summary']['total']->refund_total) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="row">
            <!-- Monthly Trend -->
            <div class="col-md-8">
                <div class="box box-warning">
                    <div class="box-header with-border">
                        <h3 class="box-title">
                            <i class="fa fa-line-chart"></i> 6-Month Returns Trend
                        </h3>
                    </div>
                    <div class="box-body">
                        <canvas id="returnsTrendChart" height="80"></canvas>
                    </div>
                </div>
            </div>

            <!-- Returns by Reason -->
            <div class="col-md-4">
                <div class="box box-danger">
                    <div class="box-header with-border">
                        <h3 class="box-title">
                            <i class="fa fa-pie-chart"></i> Top Return Reasons
                        </h3>
                    </div>
                    <div class="box-body">
                        <canvas id="reasonChart" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Returned Products -->
        <div class="row">
            <div class="col-md-12">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">
                            <i class="fa fa-exclamation-triangle"></i> Top 10 Most Returned Products
                        </h3>
                    </div>
                    <div class="box-body">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr style="background: #dd4b39; color: white;">
                                    <th>#</th>
                                    <th>Image</th>
                                    <th>Product Name</th>
                                    <th>Return Count</th>
                                    <th>Total Units Returned</th>
                                    <th>Total Refunded</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($data['top_returned_products'] as $index => $product)
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
                                        <td><span class="badge bg-red">{{ number_format($product->return_count) }} times</span></td>
                                        <td><span class="badge bg-orange">{{ number_format($product->total_returned) }} units</span></td>
                                        <td><span class="text-red"><strong>UGX {{ number_format($product->total_refunded) }}</strong></span></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">
                                            <i class="fa fa-info-circle"></i> No returns recorded yet
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Returns -->
        <div class="row">
            <div class="col-md-12">
                <div class="box box-info">
                    <div class="box-header with-border">
                        <h3 class="box-title">
                            <i class="fa fa-clock-o"></i> Recent Returns (Last 20)
                        </h3>
                    </div>
                    <div class="box-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr style="background: #00c0ef; color: white;">
                                        <th>Date & Time</th>
                                        <th>Product</th>
                                        <th>Quantity</th>
                                        <th>Details</th>
                                        <th>Refund Amount</th>
                                        <th>Processed By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($data['recent_returns'] as $return)
                                        <tr>
                                            <td>
                                                <small>{{ date('d M Y, h:i A', strtotime($return->created_at)) }}</small>
                                            </td>
                                            <td>
                                                <strong>{{ $return->product_name }}</strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-yellow">{{ number_format($return->quantity) }}</span>
                                            </td>
                                            <td>
                                                <small>{{ $return->description }}</small>
                                            </td>
                                            <td>
                                                <span class="text-red"><strong>UGX {{ number_format(abs($return->total_sales)) }}</strong></span>
                                            </td>
                                            <td>
                                                <small>{{ $return->processed_by }}</small>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">
                                                <i class="fa fa-info-circle"></i> No recent returns
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Chart.js already loaded from Sales Analytics Widget -->
<script>
$(document).ready(function() {
    
    // Returns Trend Chart
    var trendCtx = document.getElementById('returnsTrendChart').getContext('2d');
    var trendChart = new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: {!! json_encode($data['monthly_trend']['labels']) !!},
            datasets: [{
                label: 'Returns Count',
                data: {!! json_encode($data['monthly_trend']['counts']) !!},
                borderColor: 'rgb(243, 156, 18)',
                backgroundColor: 'rgba(243, 156, 18, 0.1)',
                tension: 0.4,
                fill: true
            }, {
                label: 'Refund Amount (UGX)',
                data: {!! json_encode($data['monthly_trend']['refunds']) !!},
                borderColor: 'rgb(221, 75, 57)',
                backgroundColor: 'rgba(221, 75, 57, 0.1)',
                tension: 0.4,
                fill: true,
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Returns Count'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Refund Amount (UGX)'
                    },
                    grid: {
                        drawOnChartArea: false,
                    }
                }
            }
        }
    });

    // Reason Pie Chart
    @if(count($data['by_reason']) > 0)
    var reasonCtx = document.getElementById('reasonChart').getContext('2d');
    var reasonLabels = {!! json_encode(array_column($data['by_reason'], 'reason')) !!};
    var reasonCounts = {!! json_encode(array_column($data['by_reason'], 'count')) !!};
    
    var reasonChart = new Chart(reasonCtx, {
        type: 'doughnut',
        data: {
            labels: reasonLabels,
            datasets: [{
                data: reasonCounts,
                backgroundColor: [
                    'rgba(221, 75, 57, 0.8)',
                    'rgba(243, 156, 18, 0.8)',
                    'rgba(255, 193, 7, 0.8)',
                    'rgba(255, 152, 0, 0.8)',
                    'rgba(255, 87, 34, 0.8)',
                    'rgba(244, 67, 54, 0.8)',
                    'rgba(233, 30, 99, 0.8)',
                    'rgba(156, 39, 176, 0.8)',
                    'rgba(103, 58, 183, 0.8)',
                    'rgba(63, 81, 181, 0.8)'
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
                            return label + ': ' + value + ' (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });
    @endif
    
});
</script>
