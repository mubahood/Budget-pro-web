<div class="box box-danger" id="low-stock-widget">
    <div class="box-header with-border">
        <h3 class="box-title">
            <i class="fa fa-exclamation-triangle"></i> Stock Alerts
        </h3>
        <div class="box-tools pull-right">
            <button type="button" class="btn btn-box-tool" data-widget="collapse">
                <i class="fa fa-minus"></i>
            </button>
            <button type="button" class="btn btn-box-tool" data-widget="refresh" onclick="location.reload();">
                <i class="fa fa-refresh"></i>
            </button>
        </div>
    </div>
    <div class="box-body">
        <!-- Summary Stats -->
        <div class="row" style="margin-bottom: 15px;">
            <div class="col-sm-6">
                <div class="info-box bg-yellow">
                    <span class="info-box-icon"><i class="fa fa-warning"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Low Stock Items</span>
                        <span class="info-box-number">{{ $lowStockCount }}</span>
                    </div>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="info-box bg-red">
                    <span class="info-box-icon"><i class="fa fa-times-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Out of Stock</span>
                        <span class="info-box-number">{{ $outOfStockCount }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Low Stock Items -->
        @if($lowStockItems->count() > 0)
        <div class="alert alert-warning" style="margin-bottom: 10px;">
            <strong><i class="fa fa-exclamation-triangle"></i> Low Stock Warning</strong>
            <p style="margin: 5px 0 0 0; font-size: 12px;">The following items need restocking soon:</p>
        </div>
        
        <div class="table-responsive" style="max-height: 250px; overflow-y: auto;">
            <table class="table table-striped table-condensed" style="margin-bottom: 0;">
                <thead>
                    <tr style="background: #f9f9f9;">
                        <th>Product</th>
                        <th>SKU</th>
                        <th class="text-center">Stock</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($lowStockItems as $item)
                    <tr>
                        <td>
                            <strong>{{ $item->name }}</strong>
                            @if($item->stock_sub_category_id)
                                <br><small class="text-muted">{{ $item->stock_sub_category->name ?? '' }}</small>
                            @endif
                        </td>
                        <td><code>{{ $item->sku }}</code></td>
                        <td class="text-center">
                            <span class="badge bg-{{ $item->current_quantity <= 5 ? 'red' : 'yellow' }}">
                                {{ number_format($item->current_quantity) }} units
                            </span>
                        </td>
                        <td class="text-right">
                            <a href="{{ admin_url('stock-items/' . $item->id . '/edit') }}" 
                               class="btn btn-xs btn-primary" 
                               title="Edit">
                                <i class="fa fa-edit"></i> Restock
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div class="alert alert-success">
            <i class="fa fa-check-circle"></i> All products have sufficient stock levels!
        </div>
        @endif

        <!-- Out of Stock Items -->
        @if($outOfStockItems->count() > 0)
        <div class="alert alert-danger" style="margin: 15px 0 10px 0;">
            <strong><i class="fa fa-times-circle"></i> Out of Stock Alert</strong>
            <p style="margin: 5px 0 0 0; font-size: 12px;">These items require immediate attention:</p>
        </div>
        
        <div class="table-responsive" style="max-height: 200px; overflow-y: auto;">
            <table class="table table-striped table-condensed" style="margin-bottom: 0;">
                <thead>
                    <tr style="background: #f9f9f9;">
                        <th>Product</th>
                        <th>SKU</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($outOfStockItems as $item)
                    <tr>
                        <td>
                            <strong>{{ $item->name }}</strong>
                            @if($item->stock_sub_category_id)
                                <br><small class="text-muted">{{ $item->stock_sub_category->name ?? '' }}</small>
                            @endif
                        </td>
                        <td><code>{{ $item->sku }}</code></td>
                        <td class="text-right">
                            <a href="{{ admin_url('stock-items/' . $item->id . '/edit') }}" 
                               class="btn btn-xs btn-danger" 
                               title="Edit">
                                <i class="fa fa-edit"></i> Restock Now
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    <div class="box-footer">
        <a href="{{ admin_url('stock-items?current_quantity=%3C%3D10') }}" class="btn btn-sm btn-warning pull-left">
            <i class="fa fa-list"></i> View All Low Stock Items
        </a>
        <a href="{{ admin_url('stock-items?current_quantity=%3C%3D0') }}" class="btn btn-sm btn-danger pull-right">
            <i class="fa fa-ban"></i> View All Out of Stock
        </a>
        <div class="clearfix"></div>
    </div>
</div>

<style>
    #low-stock-widget .info-box {
        min-height: 70px;
    }
    #low-stock-widget .info-box-number {
        font-size: 28px;
    }
    #low-stock-widget .table-condensed > tbody > tr > td {
        padding: 8px 5px;
    }
</style>
