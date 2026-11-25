@php
    $n = fn ($v) => number_format((float) $v);
    $ugx = fn ($v) => 'UGX '.number_format((float) $v);
    $ago = fn ($d) => $d ? $d->diffForHumans(null, true).' ago' : 'never';
    $t = $o['totals']; $b = $o['books']; $bill = $o['billing']; $h = $o['health'];
    $rows = $only && isset($apps[$only]) ? $o['rows']->where('app', $only) : $o['rows'];
    $maxWeek = max(1, max($o['growth']));
@endphp
<style>
    .po-links a{display:inline-block;margin:0 14px 6px 0}
    .po-stat{border:1px solid #e3e6ea;background:#fff;padding:10px 12px;margin-bottom:12px}
    .po-stat b{display:block;font-size:22px;line-height:1.2;color:#222}
    .po-stat span{color:#666;font-size:12px}
    .po-tbl td,.po-tbl th{vertical-align:middle!important}
    .po-num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
    .po-bars{display:flex;align-items:flex-end;gap:6px;height:90px;padding-top:6px}
    .po-bars div{flex:1;text-align:center;font-size:11px;color:#666}
    .po-bars i{display:block;background:#3c8dbc;margin:0 auto 4px;width:100%;min-height:1px}
    .po-book dt{font-weight:normal;color:#666}
    .po-book dd{font-weight:600;margin-bottom:6px;font-variant-numeric:tabular-nums}
    .po-demo{color:#888;font-size:11px;border:1px solid #ccc;padding:0 4px;margin-left:4px}
</style>

<div class="po-links">
    <a href="{{ admin_url('/') }}?shop=1"><i class="fa fa-home"></i> Your own shop's dashboard</a>
    <a href="{{ admin_url('companies') }}"><i class="fa fa-building"></i> Companies</a>
    <a href="{{ admin_url('subscriptions') }}"><i class="fa fa-credit-card"></i> Subscriptions</a>
    <a href="{{ admin_url('plans') }}"><i class="fa fa-tags"></i> Plans</a>
    <a href="{{ admin_url('auth/users') }}"><i class="fa fa-users"></i> Users</a>
    <a href="{{ admin_url('same-person-twice') }}"><i class="fa fa-clone"></i> Same person twice</a>
    <a href="{{ admin_url('promo-videos') }}"><i class="fa fa-film"></i> Promo videos</a>
    <a href="{{ admin_url('system-health') }}"><i class="fa fa-heartbeat"></i> System health</a>
    <span class="text-muted">As of {{ $o['at']->format('d M Y, H:i') }}</span>
</div>

<div class="row">
    @foreach([
        ['Accounts', $t['accounts'], $n($t['demo']).' demo accounts not counted'],
        ['New accounts, 30 days', $t['new_accounts_30d'], $n($t['new_people_30d']).' new people'],
        ['People', $t['people'], $n($t['active_7d']).' active in the last 7 days'],
        ['On a paid plan', $bill['paying'], $ugx($bill['invoices_paid']).' paid invoices to date'],
        ['Phones linked', $t['phones'], $n($t['phones_7d']).' seen in the last 7 days'],
        ['Problems', $h['errors'] + $h['failed_jobs'], $n($h['errors']).' open errors, '.$n($h['failed_jobs']).' failed jobs'],
    ] as [$label, $value, $sub])
        <div class="col-md-2 col-sm-4 col-xs-6"><div class="po-stat"><span>{{ $label }}</span><b>{{ $n($value) }}</b><span>{{ $sub }}</span></div></div>
    @endforeach
</div>

<div class="box">
    <div class="box-header with-border"><h3 class="box-title">Apps</h3></div>
    <div class="box-body no-padding table-responsive">
        <table class="table table-condensed po-tbl" style="margin:0">
            <tr><th>App</th><th class="po-num">Accounts</th><th class="po-num">New, 30 days</th><th class="po-num">Active, 7 days</th><th class="po-num">On a paid plan</th><th class="po-num">People</th><th class="po-num">Demo</th><th class="po-num">Films live</th><th></th></tr>
            @foreach($o['apps'] as $key => $a)
                @php $v = $o['videos'][$key] ?? null; @endphp
                <tr>
                    <td><strong>{{ $a['name'] }}</strong><br><small class="text-muted">{{ $a['what'] }}</small></td>
                    <td class="po-num">{{ $n($a['accounts']) }}</td>
                    <td class="po-num">{{ $n($a['new_30d']) }}</td>
                    <td class="po-num">{{ $n($a['active_7d']) }}</td>
                    <td class="po-num">{{ $n($a['paying']) }}</td>
                    <td class="po-num">{{ $n($a['people']) }}</td>
                    <td class="po-num">{{ $n($a['demo']) }}</td>
                    <td class="po-num">{{ $v ? $n($v->live).' of '.$n($v->n) : '—' }}</td>
                    <td class="text-right"><a href="?app={{ $key }}#accounts">Accounts</a></td>
                </tr>
            @endforeach
        </table>
    </div>
    <div class="box-footer"><small class="text-muted">An account's app follows its modules: SACCO = Akiba Ledger, rentals = Estate Ledger, shop alone = Shelfie, poultry alone = Kuku Tally, events alone = Pledge &amp; Budget, several (or none chosen) = Budget Pro. "Active" means someone in the account used it.</small></div>
</div>

<div class="row">
    @foreach([
        ['Shops', 'fa-shopping-cart', [
            ['Products', $n($b['shop']['products'])], ['Sales, all time', $n($b['shop']['sales'])], ['Sales, 30 days', $n($b['shop']['sales_30d'])],
            ['Takings, 30 days', $ugx($b['shop']['takings_30d'])], ['Owed by customers', $ugx($b['shop']['unpaid'])], ['Customers', $n($b['shop']['customers'])]]],
        ['Poultry farms', 'fa-leaf', [
            ['Flocks running', $n($b['poultry']['flocks']).' of '.$n($b['poultry']['flocks_all'])], ['Birds at start (running)', $n($b['poultry']['birds_started'])],
            ['Daily records, 30 days', $n($b['poultry']['days_30d'])], ['Deaths, 30 days', $n($b['poultry']['deaths_30d'])], ['Farm sales, 30 days', $ugx($b['poultry']['sales_30d'])]]],
        ['Events and pledges', 'fa-calendar', [
            ['Events', $n($b['budget']['events']).' ('.$n($b['budget']['open']).' open)'], ['Pledges', $n($b['budget']['pledges'])],
            ['Pledged', $ugx($b['budget']['pledged'])], ['Received', $ugx($b['budget']['received'])], ['Paid in, 30 days', $ugx($b['budget']['payments_30d'])], ['Invitation cards', $n($b['budget']['cards'])]]],
        ['SACCOs', 'fa-bank', [
            ['Members', $n($b['sacco']['members']).' (+'.$n($b['sacco']['applicants']).' applying)'], ['Loans running', $n($b['sacco']['loans_active'])],
            ['Loans waiting', $n($b['sacco']['loans_waiting'])], ['Owed on loans', $ugx($b['sacco']['outstanding'])], ['Transactions, 30 days', $n($b['sacco']['transactions_30d'])]]],
        ['Rentals', 'fa-key', [
            ['Properties', $n($b['rentals']['properties'])], ['Units', $n($b['rentals']['units'])], ['Units let', $n($b['rentals']['let'])],
            ['Tenants', $n($b['rentals']['tenants'])], ['Rent collected, 30 days', $ugx($b['rentals']['collected_30d'])]]],
        ['Families', 'fa-sitemap', [
            ['Families', $n($b['kin']['families'])], ['People', $n($b['kin']['people']).' (+'.$n($b['kin']['departed']).' departed)'],
            ['Branches', $n($b['kin']['branches'])], ['Parent links', $n($b['kin']['links'])]]],
        ['Associations', 'fa-users', [
            ['Associations', $n($b['unity']['associations'])], ['Members', $n($b['unity']['members']).' ('.$n($b['unity']['lapsed']).' lapsed)'],
            ['Chapters', $n($b['unity']['chapters'])], ['Subscriptions paid, 30 days', $ugx($b['unity']['collected_30d'])]]],
    ] as [$title, $icon, $lines])
        <div class="col-md-{{ $loop->index < 3 ? 4 : 6 }}">
            <div class="box"><div class="box-header with-border"><h3 class="box-title"><i class="fa {{ $icon }}"></i> {{ $title }}</h3></div>
                <div class="box-body"><dl class="dl-horizontal po-book" style="margin:0">
                    @foreach($lines as [$k, $v])<dt>{{ $k }}</dt><dd>{{ $v }}</dd>@endforeach
                </dl></div></div>
        </div>
    @endforeach
</div>
<p class="text-muted" style="margin-top:-8px"><small>All accounts together, demo accounts included. Money is from accounts in UGX only. Money book entries: {{ $n($b['money']['records']) }}.</small></p>

<div class="row">
    <div class="col-md-4">
        <div class="box"><div class="box-header with-border"><h3 class="box-title">Plans</h3></div>
            <div class="box-body no-padding"><table class="table table-condensed" style="margin:0">
                @foreach($bill['by_plan'] as $plan => $count)<tr><td>{{ $plan }}</td><td class="po-num">{{ $n($count) }}</td></tr>@endforeach
                <tr><td class="text-muted">Status</td><td class="po-num text-muted">@foreach($bill['by_status'] as $s => $c){{ $s }} {{ $c }}@if(! $loop->last) · @endif @endforeach</td></tr>
                <tr><td class="text-muted">Invoices not yet paid</td><td class="po-num">{{ $n($bill['invoices_open']) }}</td></tr>
            </table></div></div>
    </div>
    <div class="col-md-4">
        <div class="box"><div class="box-header with-border"><h3 class="box-title">Plans ending in the next 14 days</h3></div>
            <div class="box-body no-padding"><table class="table table-condensed" style="margin:0">
                @forelse($bill['ending_soon'] as $r)
                    <tr><td><a href="{{ admin_url('companies/'.$r->id.'/edit') }}">{{ $r->name }}</a><br><small class="text-muted">{{ $apps[$r->app]['name'] }} · {{ $r->plan }}</small></td>
                        <td class="po-num">{{ \Illuminate\Support\Carbon::parse($r->ends)->format('d M') }}</td></tr>
                @empty
                    <tr><td class="text-muted">None.</td></tr>
                @endforelse
            </table></div></div>
    </div>
    <div class="col-md-4">
        <div class="box"><div class="box-header with-border"><h3 class="box-title">New accounts per week</h3></div>
            <div class="box-body"><div class="po-bars">
                @foreach($o['growth'] as $week => $count)
                    <div title="Week of {{ $week }}: {{ $count }}"><span>{{ $count ?: '' }}</span><i style="height:{{ round(60 * $count / $maxWeek) }}px"></i>{{ substr($week, 0, 2) }}</div>
                @endforeach
            </div><small class="text-muted">Last 12 weeks, real accounts; the number under each bar is the Monday's date.</small></div></div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="box"><div class="box-header with-border"><h3 class="box-title">System</h3></div>
            <div class="box-body no-padding"><table class="table table-condensed" style="margin:0">
                <tr><td>Open errors</td><td class="po-num">{!! $h['errors'] ? '<span class="text-red">'.$n($h['errors']).'</span>' : '0' !!}</td></tr>
                <tr><td>Failed jobs</td><td class="po-num">{{ $n($h['failed_jobs']) }}</td></tr>
                <tr><td>Jobs waiting</td><td class="po-num">{{ $n($h['waiting_jobs']) }}</td></tr>
                <tr><td>Last good backup</td><td class="po-num">{{ $h['last_backup'] ? \Illuminate\Support\Carbon::parse($h['last_backup'])->diffForHumans() : 'none' }}</td></tr>
                <tr><td>Messages, 24 hours</td><td class="po-num">@forelse($h['messages_24h'] as $s => $c){{ $s }} {{ $c }}@if(! $loop->last) · @endif @empty none @endforelse</td></tr>
                <tr><td>Accounts waiting to be deleted</td><td class="po-num">{{ $n($h['deletions']) }}</td></tr>
            </table></div>
            <div class="box-footer"><a href="{{ admin_url('system-health') }}">System health in full</a></div></div>
    </div>
    <div class="col-md-8">
        <div class="box"><div class="box-header with-border"><h3 class="box-title">Newest people</h3></div>
            <div class="box-body no-padding table-responsive"><table class="table table-condensed" style="margin:0">
                @foreach($o['recent_people'] as $p)
                    <tr><td>{{ $p->name }}<br><small class="text-muted">{{ $p->username }}</small></td>
                        <td>@if($p->company_id)<a href="{{ admin_url('companies/'.$p->company_id.'/edit') }}">{{ $p->company ?? '#'.$p->company_id }}</a>@endif</td>
                        <td class="po-num"><small>joined {{ \Illuminate\Support\Carbon::parse($p->created_at)->format('d M Y') }}<br>last seen {{ $p->last_active_at ? \Illuminate\Support\Carbon::parse($p->last_active_at)->diffForHumans() : 'never' }}</small></td></tr>
                @endforeach
            </table></div></div>
    </div>
</div>

<div class="box" id="accounts">
    <div class="box-header with-border">
        <h3 class="box-title">{{ $only && isset($apps[$only]) ? $apps[$only]['name'].' accounts' : 'Every account' }} ({{ $rows->count() }})</h3>
        <div class="box-tools" style="position:static;display:inline-block;margin-left:10px">
            <a href="?#accounts" class="btn btn-xs btn-default{{ $only ? '' : ' active' }}">All</a>
            @foreach($apps as $key => $a)<a href="?app={{ $key }}#accounts" class="btn btn-xs btn-default{{ $only === $key ? ' active' : '' }}">{{ $a['name'] }}</a> @endforeach
            <input type="search" id="po-find" placeholder="Find an account, owner or contact" class="form-control input-sm" style="display:inline-block;width:240px;margin-left:8px">
        </div>
    </div>
    <div class="box-body no-padding table-responsive">
        <table class="table table-condensed table-hover po-tbl" style="margin:0" id="po-accounts">
            <tr><th>#</th><th>Account</th><th>App</th><th>Owner</th><th class="po-num">People</th><th>Last used</th><th>Plan</th><th>Joined</th><th></th></tr>
            @foreach($rows as $r)
                <tr data-find="{{ strtolower($r->id.' '.$r->name.' '.$r->owner.' '.$r->contact) }}">
                    <td>{{ $r->id }}</td>
                    <td><strong>{{ $r->name }}</strong>@if($r->demo)<span class="po-demo">demo</span>@endif</td>
                    <td>{{ $apps[$r->app]['name'] }}</td>
                    <td>{{ $r->owner }}<br><small class="text-muted">{{ $r->contact }}</small></td>
                    <td class="po-num">{{ $r->users }}</td>
                    <td><small>{{ $ago($r->seen) }}</small></td>
                    <td><small>{{ $r->plan ?? '—' }}@if($r->sub_status) · {{ $r->sub_status }}@endif @if($r->ends)<br><span class="text-muted">ends {{ \Illuminate\Support\Carbon::parse($r->ends)->format('d M Y') }}</span>@endif</small></td>
                    <td><small>{{ $r->created?->format('d M Y') }}</small></td>
                    <td class="text-right" style="white-space:nowrap">
                        <a href="{{ admin_url('companies/'.$r->id.'/edit') }}" class="btn btn-xs btn-default">Open</a>
                        <a href="{{ admin_url('companies/'.$r->id.'/sign-in-as') }}" class="btn btn-xs btn-default">Sign in as</a>
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
<script>
(function () {
    var box = document.getElementById('po-find');
    if (!box) return;
    box.addEventListener('input', function () {
        var q = box.value.trim().toLowerCase();
        document.querySelectorAll('#po-accounts tr[data-find]').forEach(function (tr) {
            tr.style.display = !q || tr.getAttribute('data-find').indexOf(q) !== -1 ? '' : 'none';
        });
    });
})();
</script>
