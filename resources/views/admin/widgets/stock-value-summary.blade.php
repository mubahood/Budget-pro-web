<div class="box box-primary">
    <div class="box-header with-border">
        <h3 class="box-title">
            <i class="fa fa-money"></i> Stock Value Summary
        </h3>
        <div class="box-tools pull-right">
            <button type="button" class="btn btn-box-tool" data-widget="collapse">
                <i class="fa fa-minus"></i>
            </button>
        </div>
    </div>
    <div class="box-body">
        <!-- Key Metrics -->
        <div class="row">
            <div class="col-md-3 col-sm-6 col-xs-12">
                <div class="info-box bg-aqua">
                    <span class="info-box-icon"><i class="fa fa-cubes"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Stock Value</span>
                        <span class="info-box-number">UGX {{ number_format($totalStockValue) }}</span>
                        <div class="progress"><div class="progress-bar" style="width: 100%"></div></div>
                        <span class="progress-description">Cost price basis</span>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-sm-6 col-xs-12">
                <div class="info-box bg-green">
                    <span class="info-box-icon"><i class="fa fa-line-chart"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Potential Revenue</span>
                        <span class="info-box-number">UGX {{ number_format($totalPotentialRevenue) }}</span>
                        <div class="progress"><div class="progress-bar" style="width: 100%"></div></div>
                        <span class="progress-description">If all sold</span>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-sm-6 col-xs-12">
                <div class="info-box bg-yellow">
                    <span class="info-box-icon"><i class="fa fa-dollar"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Potential Profit</span>
                        <span class="info-box-number">UGX {{ number_format($totalPotentialProfit) }}</span>
                        <div class="progress"><div class="progress-bar" style="width: {{ min(100, ($totalPotentialProfit / max($totalStockValue, 1)) * 100) }}%"></div></div>
                        <span class="progress-description">{{ number_format($avgMargin, 1) }}% avg margin</span>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-sm-6 col-xs-12">
                <div class="info-box bg-red">
                    <span class="info-box-icon"><i class="fa fa-shopping-cart"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Products in Stock</span>
                        <span class="info-box-number">{{ $inStockProducts }} / {{ $totalProducts }}</span>
                        <div class="progress"><div class="progress-bar" style="width: {{ $totalProducts > 0 ? ($inStockProducts / $totalProducts * 100) : 0 }}%"></div></div>
                        <span class="progress-description">{{ $totalProducts - $inStockProducts }} out of stock</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Valuable Items -->
        @if($topValuableItems->count() > 0)
        <div style="margin-top: 20px;">
            <h4 style="border-bottom: 2px solid #3c8dbc; padding-bottom: 8px; margin-bottom: 15px;">
                <i class="fa fa-star"></i> Top 5 Most Valuable Items
            </h4>
            <div class="table-responsive">
                <table class="table table-striped table-bordered table-hover">
                    <thead style="background: #f4f4f4;">
                        <tr>
                            <th style="width: 40px;">#</th>
                            <th>Product Name</th>
                            <th class="text-center">Stock</th>
                            <th class="text-right">Unit Price</th>
                            <th class="text-right">Total Value</th>
                            <th class="text-center">% of Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($topValuableItems as $index => $item)
                        <tr>
                            <td class="text-center">
                                <span class="badge bg-blue">{{ $index + 1 }}</span>
                            </td>
                            <td>
                                <strong>{{ $item->name }}</strong>
                                @if($item->sku)
                                    <br><small class="text-muted"><code>{{ $item->sku }}</code></small>
                                @endif
                            </td>
                            <td class="text-center">
                                <span class="badge bg-green">{{ number_format($item->current_quantity) }}</span>
                            </td>
                            <td class="text-right">
                                UGX {{ number_format($item->buying_price) }}
                            </td>
                            <td class="text-right">
                                <strong style="color: #00a65a;">
                                    UGX {{ number_format($item->stock_value) }}
                                </strong>
                            </td>
                            <td class="text-center">
                                @php
                                    $percentage = $totalStockValue > 0 ? ($item->stock_value / $totalStockValue * 100) : 0;
                                @endphp
                                <div class="progress progress-xs" style="margin: 0;">
                                    <div class="progress-bar progress-bar-primary" style="width: {{ $percentage }}%"></div>
                                </div>
                                <small>{{ number_format($percentage, 1) }}%</small>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        <!-- Quick Actions -->
        <div class="row" style="margin-top: 20px;">
            <div class="col-md-4">
                <a href="{{ admin_url('stock-items?current_quantity=%3E0') }}" class="btn btn-block btn-success">
                    <i class="fa fa-check-circle"></i> View In-Stock Items
                </a>
            </div>
            <div class="col-md-4">
                <a href="{{ admin_url('stock-items?current_quantity=%3C%3D10') }}" class="btn btn-block btn-warning">
                    <i class="fa fa-exclamation-triangle"></i> View Low Stock
                </a>
            </div>
            <div class="col-md-4">
                <a href="{{ admin_url('stock-items') }}" class="btn btn-block btn-primary">
                    <i class="fa fa-list"></i> All Products
                </a>
            </div>
        </div>
    </div>
</div>

<style>
    .info-box-number {
        font-size: 20px !important;
        font-weight: bold;
    }
    .progress {
        margin-top: 5px;
    }
</style>
