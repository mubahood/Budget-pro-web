@php
    $badge = ['draft' => ['default', 'Words changed'], 'queued' => ['info', 'Queued'], 'rendering' => ['info', 'Rendering'],
        'ready' => ['primary', 'Ready to publish'], 'live' => ['success', 'Live']];
    $seen = $worker ? \Illuminate\Support\Carbon::parse($worker['at']) : null;
@endphp
<div class="row"><div class="col-md-12">
    <div class="callout {{ $seen && $seen->gt(now()->subMinutes(5)) ? 'callout-success' : 'callout-warning' }}" style="margin-bottom:15px">
        @if($seen && $seen->gt(now()->subMinutes(5)))
            <p><b>The studio worker is running</b> ({{ $worker['worker'] }}, checked {{ $seen->diffForHumans() }}). Queued renders start within a minute.</p>
        @else
            <p><b>The studio worker is not running</b>{{ $seen ? ' (last seen '.$seen->diffForHumans().')' : '' }}. Queued renders wait until it runs on the studio computer:
                <code>./studio worker</code>. Everything else here works without it.</p>
        @endif
    </div>
</div></div>
<div class="box collapsed-box">
    <div class="box-header with-border"><h3 class="box-title">Add a video without the studio</h3>
        <div class="box-tools"><button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-plus"></i></button></div></div>
    <form method="post" action="{{ admin_url('promo-videos') }}">@csrf
        <div class="box-body"><div class="row">
            <div class="col-sm-3 form-group"><label>App</label><select name="app" class="form-control">
                @foreach($apps as $key => $name)<option value="{{ $key }}" @selected(old('app') === $key)>{{ $name }}</option>@endforeach</select></div>
            <div class="col-sm-3 form-group"><label>Address <small class="text-muted">/videos/…</small></label>
                <input name="slug" class="form-control" required pattern="[a-z0-9-]{2,80}" placeholder="e.g. the-question" value="{{ old('slug') }}"></div>
            <div class="col-sm-4 form-group"><label>Title</label><input name="title" class="form-control" required maxlength="160" value="{{ old('title') }}"></div>
            <div class="col-sm-2 form-group"><label>Part</label><input name="part" type="number" min="1" max="99" class="form-control" value="{{ old('part') }}"></div>
        </div></div>
        <div class="box-footer"><button class="btn btn-primary">Add, then upload its film</button></div>
    </form>
</div>
@forelse($groups as $app => $videos)
    <div class="box">
        <div class="box-header with-border"><h3 class="box-title">{{ $app }}</h3>
            <div class="box-tools"><small class="text-muted">{{ $videos->filter->isPublished()->count() }} of {{ $videos->count() }} live</small></div></div>
        <div class="box-body no-padding table-responsive">
            <table class="table table-condensed table-hover">
                <tr><th style="width:70px">Part</th><th>Title</th><th>Status</th><th class="text-right">Length</th><th>Words</th><th>Published</th><th></th></tr>
                @foreach($videos as $v)
                    @php $st = $v->state(); @endphp
                    <tr>
                        <td>{{ $v->part ? $v->part.($v->parts ? ' / '.$v->parts : '') : '—' }}</td>
                        <td><a href="{{ admin_url('promo-videos/'.$v->id) }}"><b>{{ $v->title }}</b></a>@if($v->featured) <span class="label label-warning" title="Leads the app's home page"><i class="fa fa-star"></i> Featured</span>@endif @if($v->tagline)<br><small class="text-muted">{{ $v->tagline }}</small>@endif</td>
                        <td><span class="label label-{{ $badge[$st][0] }}">{{ $badge[$st][1] }}</span>
                            @if($v->isPublished() && $st !== 'live')<br><small class="text-muted">an older cut is live</small>@endif</td>
                        <td class="text-right"><small>{{ $v->duration ? gmdate('i:s', (int) round($v->duration)) : '—' }}</small></td>
                        <td><small>version {{ $v->script_rev }}{{ $v->rendered_rev ? ' · filmed '.$v->rendered_rev : '' }}</small></td>
                        <td><small>{{ $v->published_at?->format('j M Y H:i') ?? '—' }}</small></td>
                        <td class="text-right"><a class="btn btn-xs btn-default" href="{{ admin_url('promo-videos/'.$v->id) }}">Open</a></td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
@empty
    <div class="box"><div class="box-body">
        <p>No videos yet. They arrive from the video studio: on the studio computer, run <code>./studio push all</code>.</p>
    </div></div>
@endforelse
