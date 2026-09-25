@php $ok = fn ($b) => $b ? '<span class="label label-success">OK</span>' : '<span class="label label-danger">Check</span>'; @endphp
<div class="row">
    <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-{{ $h['scheduler']['late'] ? 'red' : 'green' }}"><i class="fa fa-clock-o"></i></span><div class="info-box-content"><span class="info-box-text">Scheduler</span><span class="info-box-number" style="font-size:13px">{{ $h['scheduler']['last_hourly_run'] ?? 'never ran' }}</span></div></div></div>
    <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-{{ $h['queue']['oldest_minutes'] > 10 ? 'red' : 'aqua' }}"><i class="fa fa-envelope-o"></i></span><div class="info-box-content"><span class="info-box-text">Queue</span><span class="info-box-number">{{ $h['queue']['pending'] }} waiting · {{ $h['queue']['failed'] }} failed</span></div></div></div>
    <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-{{ $h['sync']['open_conflicts'] > 0 ? 'yellow' : 'green' }}"><i class="fa fa-refresh"></i></span><div class="info-box-content"><span class="info-box-text">Sync (24 h)</span><span class="info-box-number" style="font-size:13px">@foreach($h['sync']['batches_24h'] as $s => $n){{ $s }} {{ $n }} @endforeach · {{ $h['sync']['open_conflicts'] }} conflicts</span></div></div></div>
    <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-{{ $h['last_backup_ok'] && now()->diffInHours($h['last_backup_ok']) < 30 ? 'green' : 'red' }}"><i class="fa fa-database"></i></span><div class="info-box-content"><span class="info-box-text">Last good backup</span><span class="info-box-number" style="font-size:13px">{{ $h['last_backup_ok'] ?? 'none' }}</span></div></div></div>
</div>
<div class="row">
    <div class="col-md-8">
        <div class="box"><div class="box-header"><h3 class="box-title">Unresolved errors</h3></div><div class="box-body no-padding"><table class="table table-condensed">
            <tr><th>Error</th><th>Where</th><th class="text-right">Count</th><th>Last seen</th><th></th></tr>
            @forelse($h['errors'] as $e)
                @php $ctx = json_decode((string) $e->last_context, true) ?: []; @endphp
                <tr><td><strong>{{ class_basename($e->class) }}</strong><br><small>{{ \Illuminate\Support\Str::limit($e->message, 140) }}</small></td>
                    <td><small>{{ $e->file }}:{{ $e->line }}<br>{{ $ctx['url'] ?? '' }} · company {{ $ctx['company_id'] ?? '—' }} · req {{ \Illuminate\Support\Str::limit((string) ($ctx['request_id'] ?? ''), 8, '') }}</small></td>
                    <td class="text-right">{{ $e->count }}</td><td><small>{{ $e->last_seen_at }}</small></td>
                    <td><form method="post" action="{{ admin_url('system-health/errors/'.$e->id.'/resolve') }}">@csrf<button class="btn btn-xs btn-default">Resolved</button></form></td></tr>
            @empty
                <tr><td colspan="5" class="text-muted">No unresolved errors.</td></tr>
            @endforelse
        </table></div></div>
    </div>
    <div class="col-md-4">
        <div class="box"><div class="box-header"><h3 class="box-title">Sync & devices</h3></div><div class="box-body">
            <p>Active phones (24 h): <strong>{{ $h['sync']['devices_active_24h'] }}</strong></p>
            <p>Phones silent over 3 days: <strong>{{ $h['sync']['devices_silent_3d'] }}</strong></p>
            <p>Largest pull lag (changes behind): <strong>{{ $h['sync']['max_pull_lag'] }}</strong></p>
            <p>Messages (24 h): @forelse($h['messages_24h'] as $s => $n){{ $s }} {{ $n }} · @empty none @endforelse</p>
            <p>Shops: {{ $h['tenants']['companies'] }} · deletions scheduled: {{ $h['tenants']['deletions_scheduled'] }}</p>
            <p>Old app (14 days): <strong>{{ $h['legacy']['legacy_percent'] }}%</strong> of active shops · {{ $h['legacy']['legacy_calls'] }} calls
                {!! $h['legacy']['ready'] ? '<span class="label label-success">ready to retire</span>' : '<span class="label label-default">keep</span>' !!}</p>
        </div></div>
        <div class="box"><div class="box-header"><h3 class="box-title">Backups & drills</h3></div><div class="box-body no-padding"><table class="table table-condensed">
            @forelse($h['backups'] as $b)
                <tr><td>{{ $b->kind }}</td><td>{!! $ok($b->status === 'ok') !!}</td><td><small>{{ $b->created_at }}</small></td><td class="text-right"><small>{{ $b->bytes ? number_format($b->bytes / 1048576, 1).' MB' : '' }}</small></td></tr>
            @empty
                <tr><td class="text-muted">No backup has run yet.</td></tr>
            @endforelse
        </table></div></div>
    </div>
</div>
