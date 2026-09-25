@php use App\Services\Reports\ReportFormat; $q = request()->query(); @endphp
<div class="box box-primary">
    <div class="box-body">
        <form method="get" class="form-inline" action="{{ admin_url('reports') }}">
            <select name="report" class="form-control" onchange="this.form.submit()">
                @foreach($available as $key => $r)<option value="{{ $key }}" @selected($key === $name)>{{ $r[0] }}</option>@endforeach
            </select>
            <input type="date" name="from" class="form-control" value="{{ $report['from'] ?? ($q['from'] ?? '') }}">
            <input type="date" name="to" class="form-control" value="{{ $report['to'] ?? ($q['to'] ?? '') }}">
            @if(in_array($name, ['sales_summary', 'profit'], true))
                <select name="group_by" class="form-control">
                    @foreach($name === 'profit' ? ['day', 'product'] : $groups as $g)<option value="{{ $g }}" @selected(($q['group_by'] ?? 'day') === $g)>by {{ $g }}</option>@endforeach
                </select>
            @endif
            @if(in_array($name, ['dead_stock', 'expiry'], true))
                <input type="number" name="days" class="form-control" style="width:90px" value="{{ $q['days'] ?? 60 }}" title="days">
            @endif
            <button class="btn btn-primary">Show</button>
            @if($report)
                <a class="btn btn-default" href="{{ admin_url('reports').'?'.http_build_query(array_merge($q, ['report' => $name, 'format' => 'pdf'])) }}"><i class="fa fa-file-pdf-o"></i> PDF</a>
                <a class="btn btn-default" href="{{ admin_url('reports').'?'.http_build_query(array_merge($q, ['report' => $name, 'format' => 'xlsx'])) }}"><i class="fa fa-file-excel-o"></i> Excel</a>
            @endif
        </form>
    </div>
</div>
@if($error)<div class="alert alert-danger">{{ $error }}</div>@endif
@if($report)
<div class="box">
    <div class="box-header with-border"><h3 class="box-title">{{ $report['title'] }} · {{ $report['from'] }} – {{ $report['to'] }}</h3></div>
    <div class="box-body table-responsive no-padding">
        @if(!empty($report['meta']['note']))<p style="padding:10px">{{ $report['meta']['note'] }}</p>@endif
        <table class="table table-striped table-condensed">
            <thead><tr>@foreach($report['columns'] as $c)<th class="{{ $c['type'] === 'text' ? '' : 'text-right' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse($report['rows'] as $row)
                <tr>@foreach($report['columns'] as $c)<td class="{{ $c['type'] === 'text' ? '' : 'text-right' }}">{{ ReportFormat::cell($row[$c['key']] ?? null, $c['type']) }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ max(1, count($report['columns'])) }}" class="text-muted">Nothing in this period.</td></tr>
            @endforelse
            </tbody>
            @if(!empty($report['totals']))
                <tfoot><tr>@foreach($report['columns'] as $i => $c)<th class="{{ $c['type'] === 'text' ? '' : 'text-right' }}">{{ $i === 0 ? 'Total' : (array_key_exists($c['key'], $report['totals']) ? ReportFormat::cell($report['totals'][$c['key']], $c['type']) : '') }}</th>@endforeach</tr></tfoot>
            @endif
        </table>
        @foreach(($report['meta'] ?? []) as $k => $v)
            @if(!in_array($k, ['note', 'days', 'rate'], true) && is_numeric($v))<p style="padding:0 10px"><strong>{{ ucfirst(str_replace('_', ' ', $k)) }}:</strong> {{ \App\Support\Money::format($v) }}</p>@endif
        @endforeach
    </div>
</div>
@endif
