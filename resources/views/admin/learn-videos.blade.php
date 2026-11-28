@php
    $audLabel = ['public' => 'Anyone', 'everyone' => 'Signed in', 'officers' => 'Officers', 'members' => 'Members'];
@endphp
<div class="row"><div class="col-md-12" style="margin-bottom:12px">
    <a class="btn btn-primary btn-sm" href="{{ admin_url('learn-videos/create') }}"><i class="fa fa-plus"></i> Add a lesson</a>
    <span style="margin-left:12px">Show:</span>
    <div class="btn-group btn-group-sm" style="margin-left:4px;flex-wrap:wrap">
        <a class="btn {{ $app === null ? 'btn-info' : 'btn-default' }}" href="{{ admin_url('learn-videos') }}">Every app</a>
        @foreach($apps as $slug => $name)
            <a class="btn {{ $app === $slug ? 'btn-info' : 'btn-default' }}" href="{{ admin_url('learn-videos?app='.$slug) }}">{{ $name }}</a>
        @endforeach
    </div>
    <p class="text-muted" style="margin:8px 0 0"><small>
        {{ $total }} {{ \Illuminate\Support\Str::plural('lesson', $total) }}{{ $app ? ' in '.$apps[$app].' (its own and those for all apps)' : '' }}.
        Drag a row by <i class="fa fa-bars"></i>, or use the arrows, to change the order inside a section. Views count each sitting; people count who started; done means watched to 90%.
    </small></p>
</div></div>

@forelse($groups as $g)
    @php $s = $g['section']; @endphp
    <div class="box box-solid" style="border:1px solid #d2d6de">
        <div class="box-header with-border">
            <h3 class="box-title">{{ $s?->title ?? 'No section' }}</h3>
            @if($s)<small class="text-muted" style="margin-left:6px">{{ $s->slug }}</small>@endif
            @if($s)
                <div class="box-tools">
                    <form method="post" action="{{ admin_url('learn-videos/sections/'.$s->id.'/move') }}" style="display:inline">@csrf
                        <input type="hidden" name="dir" value="up"><button class="btn btn-xs btn-default" title="Section up"><i class="fa fa-arrow-up"></i></button></form>
                    <form method="post" action="{{ admin_url('learn-videos/sections/'.$s->id.'/move') }}" style="display:inline">@csrf
                        <input type="hidden" name="dir" value="down"><button class="btn btn-xs btn-default" title="Section down"><i class="fa fa-arrow-down"></i></button></form>
                </div>
            @endif
        </div>
        <div class="box-body no-padding table-responsive">
            @if($g['lessons']->isEmpty())
                <p class="text-muted" style="padding:10px">No lessons here{{ $app ? ' for this app' : '' }}.</p>
            @else
            <table class="table table-condensed table-hover learn-table" style="margin:0">
                <thead><tr><th style="width:28px"></th><th style="width:70px">Order</th><th>Lesson</th><th>Apps</th><th>Who</th><th>Status</th>
                    <th class="text-right">Views</th><th class="text-right">People</th><th class="text-right">Done</th><th class="text-right">Minutes</th><th></th></tr></thead>
                <tbody class="learn-sortable">
                @foreach($g['lessons'] as $l)
                    @php $st = $stats[$l->id] ?? ['views' => 0, 'people' => 0, 'completed' => 0, 'minutes' => 0]; @endphp
                    <tr draggable="true" data-id="{{ $l->id }}">
                        <td class="learn-handle" style="cursor:move;color:#999" title="Drag to reorder"><i class="fa fa-bars"></i></td>
                        <td style="white-space:nowrap">
                            <form method="post" action="{{ admin_url('learn-videos/'.$l->id.'/move') }}" style="display:inline">@csrf
                                <input type="hidden" name="dir" value="up"><button class="btn btn-xs btn-default" title="Up"><i class="fa fa-arrow-up"></i></button></form>
                            <form method="post" action="{{ admin_url('learn-videos/'.$l->id.'/move') }}" style="display:inline">@csrf
                                <input type="hidden" name="dir" value="down"><button class="btn btn-xs btn-default" title="Down"><i class="fa fa-arrow-down"></i></button></form>
                        </td>
                        <td>
                            <a href="{{ admin_url('learn-videos/'.$l->id.'/edit') }}"><b>{{ $l->title }}</b></a>
                            @if($l->featured) <span class="label label-warning">Featured</span>@endif
                            <small class="text-muted"> · {{ $l->source_type === 'youtube' ? 'YouTube' : ($l->source_type === 'file' ? 'Uploaded file' : 'Link') }}{{ $l->length() ? ' · '.$l->length() : '' }}{{ $l->language !== 'en' ? ' · '.$l->language : '' }}</small>
                            @if($l->summary)<br><small class="text-muted">{{ \Illuminate\Support\Str::limit($l->summary, 110) }}</small>@endif
                            @if($l->areas)<br>@foreach($l->areas as $a)<span class="label label-default" style="margin-right:3px">{{ $a }}</span>@endforeach @endif
                        </td>
                        <td><small>{{ $l->all_apps ? 'All apps' : collect($l->apps())->map(fn ($a) => $apps[$a] ?? $a)->implode(', ') }}</small></td>
                        <td><small>{{ $audLabel[$l->audience] ?? $l->audience }}{{ $l->roles ? ' · '.implode(', ', $l->roles) : '' }}</small></td>
                        <td>@if($l->published)<span class="label label-success">Live</span>@else<span class="label label-default">Draft</span>@endif</td>
                        <td class="text-right">{{ $st['views'] }}</td>
                        <td class="text-right">{{ $st['people'] }}</td>
                        <td class="text-right">{{ $st['completed'] }}</td>
                        <td class="text-right">{{ $st['minutes'] }}</td>
                        <td class="text-right" style="white-space:nowrap">
                            <a class="btn btn-xs btn-default" href="{{ admin_url('learn-videos/'.$l->id.'/edit') }}">Edit</a>
                            <form method="post" action="{{ admin_url('learn-videos/'.$l->id.'/publish') }}" style="display:inline">@csrf
                                <button class="btn btn-xs {{ $l->published ? 'btn-default' : 'btn-success' }}">{{ $l->published ? 'Take down' : 'Publish' }}</button></form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>
    </div>
@empty
    <div class="box box-solid" style="border:1px solid #d2d6de"><div class="box-body">
        <p>No lessons yet. <a href="{{ admin_url('learn-videos/create') }}">Add the first one</a>, or load a list with <code>php artisan learn:import lessons.json</code>.</p>
    </div></div>
@endforelse

<script>
(function () {
    var token = @json(csrf_token()), url = @json(admin_url('learn-videos/reorder'));
    document.querySelectorAll('.learn-sortable').forEach(function (body) {
        if (body.dataset.bound) { return; }
        body.dataset.bound = '1';
        var dragging = null;
        body.addEventListener('dragstart', function (e) { dragging = e.target.closest('tr'); e.dataTransfer.effectAllowed = 'move'; dragging.style.opacity = '0.5'; });
        body.addEventListener('dragover', function (e) {
            e.preventDefault();
            var over = e.target.closest('tr');
            if (!dragging || !over || over === dragging || over.parentNode !== body) { return; }
            var r = over.getBoundingClientRect();
            body.insertBefore(dragging, (e.clientY - r.top) > r.height / 2 ? over.nextSibling : over);
        });
        body.addEventListener('dragend', function () {
            if (!dragging) { return; }
            dragging.style.opacity = '';
            dragging = null;
            var ids = Array.prototype.map.call(body.querySelectorAll('tr[data-id]'), function (tr) { return tr.dataset.id; });
            fetch(url, {method: 'POST', credentials: 'same-origin', body: JSON.stringify({ids: ids}),
                headers: {'X-CSRF-TOKEN': token, 'Content-Type': 'application/json', 'Accept': 'application/json'}});
        });
    });
})();
</script>
