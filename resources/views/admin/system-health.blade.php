@php $ok = fn ($b) => $b ? '<span class="label label-success">OK</span>' : '<span class="label label-danger">Check</span>'; @endphp
<div class="row">
    <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-{{ $h['scheduler']['late'] ? 'red' : 'green' }}"><i class="fa fa-clock-o"></i></span><div class="info-box-content"><span class="info-box-text">Scheduler</span><span class="info-box-number" style="font-size:13px">{{ $h['scheduler']['last_hourly_run'] ?? 'never ran' }}</span></div></div></div>
    <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-{{ $h['queue']['oldest_minutes'] > 10 ? 'red' : 'aqua' }}"><i class="fa fa-envelope-o"></i></span><div class="info-box-content"><span class="info-box-text">Queue</span><span class="info-box-number">{{ $h['queue']['pending'] }} waiting · {{ $h['queue']['failed'] }} failed</span></div></div></div>
    <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-{{ $h['sync']['open_conflicts'] > 0 ? 'yellow' : 'green' }}"><i class="fa fa-refresh"></i></span><div class="info-box-content"><span class="info-box-text">Sync (24 h)</span><span class="info-box-number" style="font-size:13px">@foreach($h['sync']['batches_24h'] as $s => $n){{ $s }} {{ $n }} @endforeach · {{ $h['sync']['open_conflicts'] }} conflicts</span></div></div></div>
    <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-{{ $h['last_backup_ok'] && now()->diffInHours($h['last_backup_ok']) < 30 ? 'green' : 'red' }}"><i class="fa fa-database"></i></span><div class="info-box-content"><span class="info-box-text">Last good backup</span><span class="info-box-number" style="font-size:13px">{{ $h['last_backup_ok'] ?? 'none' }}</span></div></div></div>
</div>
@php
    $cfg = $h['config'];
    $cfgLabel = ['fail' => ['label-danger', 'Fix'], 'warn' => ['label-warning', 'Check'], 'ok' => ['label-success', 'OK']];
    $cfgOpen = array_values(array_filter($cfg['checks'], fn ($c) => $c['status'] !== 'ok'));
    $cfgFine = array_values(array_filter($cfg['checks'], fn ($c) => $c['status'] === 'ok'));
@endphp
<div class="row">
    <div class="col-md-12">
        <div class="box box-{{ ['fail' => 'danger', 'warn' => 'warning'][$cfg['overall']] ?? 'success' }}" id="configuration">
            <div class="box-header with-border">
                <h3 class="box-title">Configuration</h3>
                <div class="box-tools" style="position:static;display:inline-block;margin-left:8px">
                    <span class="label label-{{ $cfg['counts']['fail'] > 0 ? 'danger' : 'default' }}">{{ $cfg['counts']['fail'] }} to fix</span>
                    <span class="label label-{{ $cfg['counts']['warn'] > 0 ? 'warning' : 'default' }}">{{ $cfg['counts']['warn'] }} to check</span>
                    <span class="label label-success">{{ $cfg['counts']['ok'] }} fine</span>
                </div>
            </div>
            <div class="box-body no-padding">
                <table class="table table-condensed" style="margin:0;table-layout:fixed;width:100%">
                    @forelse($cfgOpen as $c)
                        <tr>
                            <td style="width:76px"><span class="label {{ $cfgLabel[$c['status']][0] }}">{{ $c['accepted'] ? 'Accepted' : $cfgLabel[$c['status']][1] }}</span></td>
                            <td style="overflow-wrap:anywhere"><code>{{ $c['key'] }}</code> {{ $c['message'] }}
                                @if($c['accepted'])<br><small><strong>Accepted by the owner</strong> (CONFIG_DOCTOR_ACCEPT), so it does not stop a deploy.</small>@endif
                                @if($c['fix'] !== '')<br><small class="text-muted">Fix: {{ $c['fix'] }}</small>@endif</td>
                        </tr>
                    @empty
                        <tr><td class="text-muted">Every setting is right for this server.</td></tr>
                    @endforelse
                </table>
                @if($cfgFine !== [])
                    <details style="padding:8px 10px">
                        <summary style="cursor:pointer;display:list-item">{{ count($cfgFine) }} {{ count($cfgFine) === 1 ? 'setting is' : 'settings are' }} fine</summary>
                        <table class="table table-condensed" style="margin:6px 0 0;table-layout:fixed;width:100%">
                            @foreach($cfgFine as $c)
                                <tr><td style="width:76px"><span class="label label-success">OK</span></td><td style="overflow-wrap:anywhere"><code>{{ $c['key'] }}</code> {{ $c['message'] }}</td></tr>
                            @endforeach
                        </table>
                    </details>
                @endif
            </div>
            <div class="box-footer"><small class="text-muted">The same list as <code>php artisan config:doctor</code>. In production a failure stops the deploy; on other servers every problem is only a warning. The owner can accept a failure for now with CONFIG_DOCTOR_ACCEPT in .env, except {{ implode(', ', \App\Support\ConfigDoctor::NEVER_ACCEPTED) }}.</small></div>
        </div>
    </div>
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
