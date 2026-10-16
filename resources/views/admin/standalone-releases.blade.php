@php
    $badge = [
        'queued' => ['default', 'Queued'], 'building' => ['info', 'Building'], 'staged' => ['primary', 'Ready to publish'],
        'published' => ['success', 'Published'], 'failed' => ['danger', 'Failed'], 'yanked' => ['warning', 'Withdrawn'],
    ];
    $when = fn ($t) => $t ? '<span title="'.e($t->toDateTimeString()).'">'.e($t->format('j M H:i')).'</span>' : '<span class="text-muted">—</span>';
@endphp
<div class="row">
    <div class="col-md-8">
        <div class="box">
            <div class="box-header with-border">
                <h3 class="box-title">Releases</h3>
                <div class="box-tools"><a href="{{ admin_url('standalone-releases') }}" class="btn btn-xs btn-default"><i class="fa fa-refresh"></i> Refresh</a></div>
            </div>
            <div class="box-body no-padding table-responsive">
                <table class="table table-condensed">
                    <tr><th>Version</th><th>Status</th><th>budget-pro</th><th>budget-pro-new</th><th class="text-right">Size</th><th>Staged</th><th>Proved</th><th>Published</th><th></th></tr>
                    @forelse($releases as $r)
                        <tr>
                            <td><strong>{{ $r->version }}</strong></td>
                            <td>
                                <span class="label label-{{ $badge[$r->status][0] ?? 'default' }}">{{ $badge[$r->status][1] ?? $r->status }}</span>
                                @if(in_array($r->status, ['queued', 'building'], true) && is_array($r->progress))
                                    <br><small class="text-muted">{{ $r->progress['step'] ?? '' }} ({{ (int) ($r->progress['percent'] ?? 0) }}%)</small>
                                @endif
                            </td>
                            <td><code title="{{ $r->commit_kernel }}">{{ $r->shortCommit('kernel') ?: '—' }}</code></td>
                            <td><code title="{{ $r->commit_web }}">{{ $r->shortCommit('web') ?: '—' }}</code></td>
                            <td class="text-right"><small>{{ $r->size ? number_format($r->size / 1048576, 1).' MB' : '' }}{{ $r->file_count ? ' · '.number_format($r->file_count).' files' : '' }}</small></td>
                            <td><small>{!! $when($r->staged_at) !!}</small></td>
                            <td><small>{!! $when($r->proved_at) !!}</small></td>
                            <td><small>{!! $when($r->published_at) !!}</small></td>
                            <td class="text-nowrap">
                                @if($r->status === 'staged' && $r->proved_at)
                                    <form method="post" action="{{ admin_url('standalone-releases/'.$r->id.'/publish') }}" style="display:inline">@csrf
                                        <button class="btn btn-xs btn-success" onclick="return confirm('Publish {{ $r->version }} to buyers and the update feed?')">Publish</button></form>
                                @endif
                                @if($r->status === 'failed')
                                    <form method="post" action="{{ admin_url('standalone-releases/'.$r->id.'/retry') }}" style="display:inline">@csrf
                                        <button class="btn btn-xs btn-default">Retry</button></form>
                                @endif
                                @if($r->status === 'published')
                                    <form method="post" action="{{ admin_url('standalone-releases/'.$r->id.'/withdraw') }}" style="display:inline">@csrf
                                        <button class="btn btn-xs btn-warning" onclick="return confirm('Withdraw {{ $r->version }} from sale and updates?')">Withdraw</button></form>
                                @endif
                                @if($r->archive_path && in_array($r->status, ['staged', 'published', 'yanked'], true))
                                    <a class="btn btn-xs btn-default" href="{{ admin_url('standalone-releases/'.$r->id.'/download') }}"><i class="fa fa-download"></i></a>
                                @endif
                            </td>
                        </tr>
                        @if($r->status === 'failed' && $r->failure)
                            <tr><td colspan="9"><pre style="max-height:180px;overflow:auto;white-space:pre-wrap;margin:0;font-size:11px">{{ $r->failure }}</pre></td></tr>
                        @endif
                        @if($r->notes && $r->status === 'staged')
                            <tr><td colspan="9"><small class="text-muted" style="white-space:pre-wrap">{{ \Illuminate\Support\Str::limit($r->notes, 600) }}</small></td></tr>
                        @endif
                    @empty
                        <tr><td colspan="9" class="text-muted">No release has been staged yet.</td></tr>
                    @endforelse
                </table>
            </div>
            @if($building)
                <div class="box-footer"><small class="text-muted">A build is in progress. The release worker runs every 2 minutes; refresh to follow it.</small></div>
            @endif
        </div>
    </div>
    <div class="col-md-4">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Ready to stage?</h3></div>
            <div class="box-body no-padding">
                <table class="table table-condensed">
                    @foreach($readiness as $what => $check)
                        <tr><td>{!! $check['ok'] ? '<i class="fa fa-check text-green"></i>' : '<i class="fa fa-times text-red"></i>' !!}</td>
                            <td>{{ $what }}<br><small class="text-muted">{{ \Illuminate\Support\Str::limit($check['detail'], 160) }}</small></td></tr>
                    @endforeach
                </table>
            </div>
        </div>
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">Stage a new version</h3></div>
            <form method="post" action="{{ admin_url('standalone-releases') }}">@csrf
                <div class="box-body">
                    <div class="form-group">
                        <label for="release-version">Version</label>
                        <input id="release-version" name="version" class="form-control" value="{{ $version }}" required pattern="\d+\.\d+\.\d+(-[a-z0-9.\-]+)?">
                    </div>
                    <div class="form-group">
                        <label for="release-notes">Notes</label>
                        <textarea id="release-notes" name="notes" class="form-control" rows="12" style="font-size:12px">{{ $notes }}</textarea>
                        <p class="help-block">From the commit subjects since the last built release, in both repositories. Edit freely.</p>
                    </div>
                </div>
                <div class="box-footer">
                    <button class="btn btn-primary" @disabled(! $ready)>Build</button>
                    @unless($ready)<small class="text-muted">&nbsp;Fix the red items first.</small>@endunless
                </div>
            </form>
        </div>
    </div>
</div>
