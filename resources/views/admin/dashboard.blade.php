@php
    $m = fn ($v) => \App\Support\Money::format($v, 0, $company->id);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    $chg = function ($now, $before) {
        $c = \App\Services\Dashboard\DashboardService::change((float) $now, (float) $before);
        if ($c === null) return '';
        $up = $c >= 0;
        return '<span class="bp-chg '.($up ? 'up' : 'down').'" title="Compared with the same length of time before"><i class="fa fa-arrow-'.($up ? 'up' : 'down').'"></i> '.number_format(abs($c), 0).'%</span>';
    };
    $q = fn (array $extra) => admin_url('/').'?'.http_build_query($extra);
@endphp
<style>
    .bp-dash { --bp-ink:#1f2d3d; --bp-muted:#6b7785; --bp-line:#e6e9ee; --bp-card:#fff; --bp-good:#16a34a; --bp-bad:#dc2626; --bp-accent:#1d6fb8; }
    .bp-dash .bp-card { background:var(--bp-card); border:1px solid var(--bp-line); border-radius:8px; padding:14px 16px; margin-bottom:16px; }
    .bp-dash .bp-card h4 { margin:0 0 10px; font-size:15px; font-weight:600; color:var(--bp-ink); }
    .bp-dash .bp-card h4 small { color:var(--bp-muted); font-weight:400; }
    .bp-dash .bp-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .bp-dash .bp-actions { display:flex; flex-wrap:wrap; gap:6px; }
    .bp-dash .bp-actions .btn { border-radius:6px; }
    .bp-dash .bp-ranges { display:flex; flex-wrap:wrap; gap:4px; align-items:center; }
    .bp-dash .bp-ranges a { padding:4px 10px; border-radius:14px; border:1px solid var(--bp-line); color:var(--bp-ink); background:#fff; font-size:13px; }
    .bp-dash .bp-ranges a.active { background:var(--bp-accent); border-color:var(--bp-accent); color:#fff; }
    .bp-dash .bp-ranges form { display:inline-flex; gap:4px; align-items:center; }
    .bp-dash .bp-ranges input[type=date] { height:28px; padding:2px 6px; border:1px solid var(--bp-line); border-radius:6px; font-size:13px; }
    .bp-dash .bp-kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:16px; }
    .bp-dash .bp-kpi { background:#fff; border:1px solid var(--bp-line); border-radius:8px; padding:12px 14px; }
    .bp-dash .bp-kpi .l { color:var(--bp-muted); font-size:12px; text-transform:uppercase; letter-spacing:.03em; }
    .bp-dash .bp-kpi .v { font-size:22px; font-weight:700; color:var(--bp-ink); margin:4px 0 2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .bp-dash .bp-kpi .s { font-size:12px; color:var(--bp-muted); }
    .bp-dash .bp-kpi.good .v { color:var(--bp-good); } .bp-dash .bp-kpi.bad .v { color:var(--bp-bad); }
    .bp-dash .bp-chg { font-size:12px; font-weight:600; margin-left:4px; } .bp-dash .bp-chg.up { color:var(--bp-good); } .bp-dash .bp-chg.down { color:var(--bp-bad); }
    .bp-dash .bp-alert { display:flex; justify-content:space-between; align-items:center; gap:8px; padding:8px 12px; border-radius:6px; margin-bottom:6px; font-size:13px; }
    .bp-dash .bp-alert.danger { background:#fdecec; color:#8a1c1c; } .bp-dash .bp-alert.warning { background:#fff6e0; color:#7a5200; } .bp-dash .bp-alert.info { background:#e8f2fb; color:#154a78; }
    .bp-dash .bp-alert .btn { white-space:nowrap; }
    .bp-dash table.bp-t { width:100%; font-size:13px; } .bp-dash table.bp-t td, .bp-dash table.bp-t th { padding:6px 4px; border-bottom:1px solid var(--bp-line); vertical-align:middle; }
    .bp-dash table.bp-t th { color:var(--bp-muted); font-weight:600; font-size:12px; }
    .bp-dash .num { text-align:right; white-space:nowrap; }
    .bp-dash .bp-empty { color:var(--bp-muted); padding:12px 0; text-align:center; }
    .bp-dash .bp-bar { height:6px; background:#eef1f5; border-radius:3px; overflow:hidden; } .bp-dash .bp-bar > span { display:block; height:100%; background:var(--bp-accent); }
    .bp-dash .bp-scroll { overflow-x:auto; }
    @media (max-width: 767px) { .bp-dash .bp-kpi .v { font-size:18px; } .bp-dash .bp-toolbar { flex-direction:column; align-items:stretch; } }
</style>

<div class="bp-dash">
    <div class="bp-toolbar">
        <div class="bp-actions">
            @if($can['sell'])<a class="btn btn-success" href="{{ admin_url('sale-records/create') }}"><i class="fa fa-shopping-cart"></i> New sale</a>@endif
            @if($can['restock'])<a class="btn btn-default" href="{{ admin_url('goods-receipts/create') }}"><i class="fa fa-truck"></i> Receive stock</a>@endif
            @if($can['adjust'])<a class="btn btn-default" href="{{ admin_url('stock-records/create?type=Damage') }}"><i class="fa fa-chain-broken"></i> Damage / loss</a>@endif
            @if($can['refund'])<a class="btn btn-default" href="{{ admin_url('sale-records') }}" title="Open the sale, then use Return items"><i class="fa fa-undo"></i> Customer return</a>@endif
            @if($can['finance'])<a class="btn btn-default" href="{{ admin_url('financial-records/create') }}"><i class="fa fa-money"></i> Record expense</a>@endif
            @if($can['sell'])<a class="btn btn-default" href="{{ admin_url('customers?owes=1') }}"><i class="fa fa-hand-o-right"></i> Receive debt payment</a>@endif
            @if($can['products'])<a class="btn btn-default" href="{{ admin_url('stock-items/create') }}"><i class="fa fa-plus"></i> Product</a>@endif
        </div>
        @if($kpi)
        <div class="bp-ranges">
            @foreach($ranges as $key => $label)
                <a href="{{ $q(['range' => $key]) }}" class="{{ $range['key'] === $key ? 'active' : '' }}">{{ $label }}</a>
            @endforeach
            <form method="get" action="{{ admin_url('/') }}">
                <input type="hidden" name="range" value="custom">
                <input type="date" name="from" value="{{ $range['from'] }}" aria-label="From">
                <input type="date" name="to" value="{{ $range['to'] }}" aria-label="To">
                <button class="btn btn-xs btn-primary">Show</button>
            </form>
        </div>
        @endif
    </div>

    @foreach($alerts as $a)
        <div class="bp-alert {{ $a['level'] }}"><span><i class="fa fa-exclamation-circle"></i> {{ $a['text'] }}</span><a class="btn btn-xs btn-default" href="{{ $a['link'] }}">{{ $a['action'] }}</a></div>
    @endforeach

    @if($kpi)
        <h4 style="margin:14px 0 8px;font-weight:600">{{ $range['label'] }} <small class="text-muted">{{ \Illuminate\Support\Carbon::parse($range['from'])->format('d M') }}@if($range['from'] !== $range['to']) – {{ \Illuminate\Support\Carbon::parse($range['to'])->format('d M Y') }}@else {{ \Illuminate\Support\Carbon::parse($range['to'])->format('Y') }}@endif</small></h4>
        <div class="bp-kpis">
            <div class="bp-kpi"><div class="l">Sales</div><div class="v">{{ $m($kpi['sales']) }}</div><div class="s">{{ $kpi['count'] }} sale(s) {!! $chg($kpi['sales'], $prev['sales']) !!}</div></div>
            @if($can['profit'])
                <div class="bp-kpi {{ $kpi['profit'] < 0 ? 'bad' : 'good' }}"><div class="l">Profit on sales</div><div class="v">{{ $m($kpi['profit']) }}</div><div class="s">{{ number_format($kpi['margin'], 1) }}% margin {!! $chg($kpi['profit'], $prev['profit']) !!}</div></div>
            @endif
            <div class="bp-kpi"><div class="l">Money received</div><div class="v">{{ $m($kpi['collected']) }}</div><div class="s">{{ $m($kpi['on_credit']) }} of these sales still unpaid</div></div>
            @if($can['finance'] || $can['profit'])
                <div class="bp-kpi"><div class="l">Expenses</div><div class="v">{{ $m($kpi['expenses']) }}</div><div class="s">Running costs (stock purchases not included) {!! $chg($kpi['expenses'], $prev['expenses']) !!}</div></div>
            @endif
            @if($can['profit'])
                <div class="bp-kpi {{ $kpi['net'] < 0 ? 'bad' : 'good' }}"><div class="l">Net profit</div><div class="v">{{ $m($kpi['net']) }}</div><div class="s">Profit on sales − expenses</div></div>
            @endif
            <div class="bp-kpi"><div class="l">Returns</div><div class="v">{{ $m($kpi['returns_value']) }}</div><div class="s">{{ $kpi['returns_count'] }} return(s)</div></div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <div class="bp-card">
                    <h4>Sales{{ $can['profit'] ? ' & profit' : '' }} <small>{{ count($daily['labels']) > 0 ? $daily['labels'][0].' – '.end($daily['labels']) : '' }}</small></h4>
                    <div style="position:relative;height:260px"><canvas id="bp-daily-chart"></canvas></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="bp-card">
                    <h4>Money received by method</h4>
                    @php $maxM = max(1, ...array_map(fn ($r) => abs($r['amount']), $methods ?: [['amount' => 1]])); @endphp
                    @forelse($methods as $r)
                        <div style="margin-bottom:10px">
                            <div style="display:flex;justify-content:space-between;font-size:13px"><span>{{ $r['label'] }}</span><strong>{{ $m($r['amount']) }}</strong></div>
                            <div class="bp-bar"><span style="width:{{ max(2, abs($r['amount']) / $maxM * 100) }}%"></span></div>
                        </div>
                    @empty
                        <div class="bp-empty">No money received in this period.</div>
                    @endforelse
                    <a href="{{ admin_url('shifts') }}" style="font-size:12px">Shifts & cash-up →</a>
                </div>
                <div class="bp-card">
                    <h4>Best sellers</h4>
                    @forelse($top as $p)
                        <div style="display:flex;justify-content:space-between;font-size:13px;padding:3px 0;border-bottom:1px solid #f1f3f6">
                            <span>{{ $p->name ?: 'Deleted product' }} <small class="text-muted">× {{ $qty($p->quantity) }}</small></span><strong>{{ $m($p->revenue) }}</strong>
                        </div>
                    @empty
                        <div class="bp-empty">No sales in this period.</div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    <div class="row">
        @if($receivables)
        <div class="col-md-6">
            <div class="bp-card">
                <h4>Customers owe you <small>{{ $m($receivables['total']) }}</small></h4>
                @if($receivables['top'])
                    <div class="bp-scroll"><table class="bp-t">
                        @foreach($receivables['top'] as $c)
                            <tr>
                                <td><a href="{{ admin_url('customers/'.$c->id) }}">{{ $c->name }}</a><br><small class="text-muted">{{ $c->phone }}</small></td>
                                <td class="num"><strong>{{ $m($c->balance) }}</strong></td>
                                <td class="num">
                                    @if($can['sell'])<a class="btn btn-xs btn-success" href="{{ admin_url('customers/'.$c->id.'/pay') }}">Receive</a>@endif
                                    @if($c->phone)
                                        <form method="post" action="{{ admin_url('customers/'.$c->id.'/remind') }}" style="display:inline" onsubmit="return confirm('Send {{ addslashes($c->name) }} a reminder?')">@csrf<button class="btn btn-xs btn-default" title="Send a reminder on WhatsApp/SMS"><i class="fa fa-bell"></i></button></form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table></div>
                @endif
                @if($receivables['unlinked_count'] > 0)
                    <p style="font-size:12px;margin:8px 0 0" class="text-muted">{{ $m($receivables['unlinked_total']) }} is owed on {{ $receivables['unlinked_count'] }} sale(s) with no customer account. <a href="{{ admin_url('sale-records?payment_status[]=Unpaid&payment_status[]=Partial') }}">See them</a></p>
                @endif
                @if(! $receivables['top'] && $receivables['unlinked_count'] === 0)<div class="bp-empty">Nobody owes you money.</div>@endif
            </div>
        </div>
        @endif
        @if($payables)
        <div class="col-md-6">
            <div class="bp-card">
                <h4>You owe suppliers <small>{{ $m($payables['total']) }}</small></h4>
                @forelse($payables['top'] as $s)
                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;padding:4px 0;border-bottom:1px solid #f1f3f6">
                        <span><a href="{{ admin_url('suppliers/'.$s->id) }}">{{ $s->name }}</a></span>
                        <span><strong>{{ $m($s->balance) }}</strong> @if($can['restock'])<a class="btn btn-xs btn-default" href="{{ admin_url('suppliers/'.$s->id.'/pay') }}">Pay</a>@endif</span>
                    </div>
                @empty
                    <div class="bp-empty">You owe no suppliers.</div>
                @endforelse
            </div>
        </div>
        @endif
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="bp-card">
                <h4>Recent sales <small><a href="{{ admin_url('sale-records') }}">All sales →</a></small></h4>
                @if($recent)
                    <div class="bp-scroll"><table class="bp-t">
                        <tr><th>Receipt</th><th>Customer</th><th class="num">Total</th><th class="num">Owed</th><th></th></tr>
                        @foreach($recent as $s)
                            <tr @if($s->voided_at) style="opacity:.55" @endif>
                                <td><a href="{{ admin_url('sale-records/'.$s->id) }}">{{ $s->receipt_number ?: '#'.$s->id }}</a><br><small class="text-muted">{{ \Illuminate\Support\Carbon::parse($s->created_at)->setTimezone(\App\Support\LocalTime::timezone($company))->format('d M H:i') }}</small></td>
                                <td>{{ $s->customer_name ?: 'Walk-in' }}</td>
                                <td class="num">{{ $m((float) $s->total_amount - (float) $s->refunded_amount) }}</td>
                                <td class="num">@if($s->voided_at)<span class="label label-default">Voided</span>@elseif((float) $s->balance > 0)<span class="label label-warning">{{ $m($s->balance) }}</span>@else<span class="label label-success">Paid</span>@endif</td>
                                <td class="num"><a href="{{ url('sale-receipt-pdf?id='.$s->id) }}" target="_blank" title="Receipt"><i class="fa fa-print"></i></a></td>
                            </tr>
                        @endforeach
                    </table></div>
                @else
                    <div class="bp-empty">No sales yet. @if($can['sell'])<a href="{{ admin_url('sale-records/create') }}">Record your first sale</a>@endif</div>
                @endif
            </div>
        </div>
        <div class="col-md-6">
            <div class="bp-card">
                <h4>Stock <small>{{ $stock['items'] }} product(s)</small></h4>
                <div class="bp-kpis" style="grid-template-columns:repeat(auto-fit, minmax(120px, 1fr));margin-bottom:10px">
                    @if($can['profit'] || $can['restock'])<div class="bp-kpi"><div class="l">Value at cost</div><div class="v" style="font-size:17px">{{ $m($stock['cost_value']) }}</div></div>@endif
                    <div class="bp-kpi"><div class="l">Value at price</div><div class="v" style="font-size:17px">{{ $m($stock['sale_value']) }}</div></div>
                    <div class="bp-kpi {{ $stock['out_of_stock'] ? 'bad' : '' }}"><div class="l"><a href="{{ admin_url('stock-items?_scope_=out') }}">Out of stock</a></div><div class="v" style="font-size:17px">{{ $stock['out_of_stock'] }}</div></div>
                    <div class="bp-kpi"><div class="l"><a href="{{ admin_url('stock-items?_scope_=low') }}">Running low</a></div><div class="v" style="font-size:17px">{{ $stock['low'] }}</div></div>
                </div>
                @if($stock['low_items'])
                    <table class="bp-t">
                        @foreach($stock['low_items'] as $i)
                            <tr><td><a href="{{ admin_url('stock-items/'.$i->id) }}">{{ $i->name }}</a></td>
                                <td class="num"><span class="label {{ (float) $i->current_quantity <= 0 ? 'label-danger' : 'label-warning' }}">{{ $qty($i->current_quantity) }} left</span></td>
                                <td class="num">@if($can['restock'])<a class="btn btn-xs btn-default" href="{{ admin_url('goods-receipts/create') }}">Restock</a>@endif</td></tr>
                        @endforeach
                    </table>
                    <a href="{{ admin_url('reorder-suggestions') }}" style="font-size:12px">Reorder list with suggested quantities →</a>
                @else
                    <div class="bp-empty">All products are well stocked.</div>
                @endif
            </div>
        </div>
    </div>
</div>

@if($kpi)
<script>
(function () {
    var data = @json($daily), showProfit = @json((bool) $can['profit']);
    function draw() {
        var el = document.getElementById('bp-daily-chart');
        if (!el || !window.Chart) return;
        if (el._chart) el._chart.destroy();
        var sets = [{ type: 'bar', label: 'Sales', data: data.sales, backgroundColor: 'rgba(29,111,184,.75)', borderRadius: 3 }];
        if (showProfit) sets.push({ type: 'line', label: 'Profit', data: data.profit, borderColor: '#16a34a', backgroundColor: '#16a34a', tension: .3, pointRadius: 2 });
        el._chart = new Chart(el, { data: { labels: data.labels, datasets: sets }, options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + Number(c.raw).toLocaleString() + ' (' + data.count[c.dataIndex] + ' sales)'; } } } },
            scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return Number(v).toLocaleString(); } } } } } });
    }
    if (window.Chart) { draw(); } else {
        var s = document.createElement('script'); s.src = 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js'; s.onload = draw; document.head.appendChild(s);
    }
})();
</script>
@endif
