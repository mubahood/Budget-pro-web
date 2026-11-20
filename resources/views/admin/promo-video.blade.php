@php
    $st = $v->state();
    $badge = ['draft' => ['default', 'Words changed: render to hear them'], 'queued' => ['info', 'Queued'], 'rendering' => ['info', 'Rendering'],
        'ready' => ['primary', 'Ready to publish'], 'live' => ['success', 'Live']];
    $rbadge = ['queued' => 'info', 'running' => 'info', 'done' => 'success', 'failed' => 'danger'];
    $qa = (array) $v->qa;
    $busy = in_array($st, ['queued', 'rendering'], true);
    $fromStudio = ! empty($v->script);
@endphp
<p><a href="{{ admin_url('promo-videos') }}"><i class="fa fa-arrow-left"></i> All videos</a></p>
<div class="row">
    <div class="col-md-4">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Latest render</h3>
                <div class="box-tools"><span class="label label-{{ $badge[$st][0] }}">{{ $badge[$st][1] }}</span></div></div>
            <div class="box-body">
                @if($v->video_path)
                    <video src="{{ $media($v->video_path) }}" poster="{{ $media($v->poster_path) }}" controls preload="metadata" playsinline
                           style="width:100%;max-height:560px;background:#000;border-radius:4px"></video>
                    <p class="text-muted" style="margin:8px 0 0"><small>
                        {{ $v->duration ? gmdate('i:s', (int) round($v->duration)) : '' }} · {{ $v->width }}×{{ $v->height }}
                        · {{ $v->video_size ? number_format($v->video_size / 1048576, 1).' MB' : '' }}
                        · filmed from words version {{ $v->rendered_rev }}{{ $v->rendered_rev !== $v->script_rev ? ' (the words have changed since)' : '' }}
                        · {{ $v->rendered_at?->format('j M H:i') }}
                        @if($qa) · checks {!! ($qa['pass'] ?? false) ? '<span class="text-success">passed</span>' : '<span class="text-danger">failed</span>' !!}
                            ({{ $qa['lufs'] ?? '?' }} LUFS){{ $v->voice ? ' · voice '.$v->voice : '' }}@endif
                        @if($v->sheet_path) · <a href="{{ $media($v->sheet_path) }}" target="_blank" rel="noopener">contact sheet</a>@endif
                        · captions: @if($v->captions_path)<a href="{{ $media($v->captions_path) }}" target="_blank" rel="noopener">uploaded .vtt</a>@elseif($v->transcript) made from the studio's timings @else none @endif
                    </small></p>
                @else
                    <p class="text-muted">No film yet. {{ $fromStudio ? 'Queue a render, or upload' : 'Upload' }} one under Files.</p>
                @endif
            </div>
            <div class="box-footer">
                @if($fromStudio)
                <form method="post" action="{{ admin_url('promo-videos/'.$v->id.'/render') }}" style="display:inline">@csrf
                    <button class="btn btn-default" @disabled($busy)><i class="fa fa-refresh"></i> {{ $busy ? 'Render waiting' : 'Queue a render' }}</button></form>
                @endif
                @if($v->hasUnpublishedRender())
                    <form method="post" action="{{ admin_url('promo-videos/'.$v->id.'/publish') }}" style="display:inline">@csrf
                        <button class="btn btn-success" onclick="return confirm('Publish? It replaces what the public sees now.')"><i class="fa fa-globe"></i> {{ $v->isPublished() ? 'Publish the changes' : 'Publish' }}</button></form>
                @endif
                @if($v->isPublished())
                    <form method="post" action="{{ admin_url('promo-videos/'.$v->id.'/unpublish') }}" style="display:inline">@csrf
                        <button class="btn btn-link text-danger" onclick="return confirm('Take this video down everywhere public?')">Take down</button></form>
                @endif
            </div>
        </div>
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">What the public sees</h3></div>
            <div class="box-body">
                @if($v->featured)<p><span class="label label-warning"><i class="fa fa-star"></i> Featured</span> It leads the app's home page.</p>@endif
                @if($v->isPublished())
                    <p>Live since {{ $v->published_at->format('j M Y H:i') }} (words version {{ $v->live_rev }}), {{ gmdate('i:s', (int) round((float) $v->live_duration)) }}.</p>
                    <p><small>On the app's website at <code>/videos/{{ $v->slug }}</code>, embeddable at <code>/videos/{{ $v->slug }}/embed</code>,
                        and in <code>/api/v1/videos?app={{ $v->app }}</code> for the phone apps.</small></p>
                @else
                    <p class="text-muted">Nothing: this video is not published.</p>
                @endif
            </div>
            <div class="box-footer">
                <form method="post" action="{{ admin_url('promo-videos/'.$v->id.'/feature') }}" style="display:inline">@csrf
                    <input type="hidden" name="on" value="{{ $v->featured ? 0 : 1 }}">
                    <button class="btn btn-default btn-sm"><i class="fa fa-star{{ $v->featured ? '-o' : '' }}"></i> {{ $v->featured ? 'Stop featuring' : 'Feature on the home page' }}</button></form>
            </div>
        </div>
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Renders</h3></div>
            <div class="box-body no-padding"><table class="table table-condensed">
                @forelse($v->renders as $r)
                    <tr><td><span class="label label-{{ $rbadge[$r->status] ?? 'default' }}">{{ $r->status }}</span></td>
                        <td><small>words v{{ $r->script_rev }} · {{ $r->created_at->format('j M H:i') }}{{ $r->finished_at ? ' → '.$r->finished_at->format('H:i') : '' }}</small>
                            @if($r->log)<details><summary><small>log</small></summary><pre style="max-height:240px;overflow:auto;font-size:11px;white-space:pre-wrap">{{ $r->log }}</pre></details>@endif</td></tr>
                @empty
                    <tr><td class="text-muted">None yet.</td></tr>
                @endforelse
            </table></div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Title and description</h3></div>
            <form method="post" action="{{ admin_url('promo-videos/'.$v->id.'/details') }}">@csrf
                <div class="box-body">
                    <div class="form-group"><label>Title</label><input name="title" class="form-control" maxlength="160" required value="{{ old('title', $v->title) }}"></div>
                    <div class="form-group"><label>Tagline</label><input name="tagline" class="form-control" maxlength="200" value="{{ old('tagline', $v->tagline) }}"></div>
                    <div class="form-group"><label>Description <small class="text-muted">(shown under the video on the website)</small></label>
                        <textarea name="description" class="form-control" rows="3" maxlength="5000">{{ old('description', $v->description) }}</textarea></div>
                    <div class="row">
                        <div class="col-sm-4 form-group"><label>App</label>
                            <select name="app" class="form-control">
                                @foreach($apps as $key => $name)<option value="{{ $key }}" @selected(old('app', $v->app) === $key)>{{ $name }}</option>@endforeach
                            </select></div>
                        <div class="col-sm-2 form-group"><label>Part</label><input name="part" type="number" min="1" max="99" class="form-control" value="{{ old('part', $v->part) }}"></div>
                        <div class="col-sm-2 form-group"><label>of</label><input name="parts" type="number" min="1" max="99" class="form-control" value="{{ old('parts', $v->parts) }}"></div>
                        <div class="col-sm-2 form-group"><label>Order</label><input name="sort" type="number" min="0" max="999" class="form-control" value="{{ old('sort', $v->sort) }}"></div>
                    </div>
                    <div class="form-group"><label>Series title <small class="text-muted">(shown when an app has more than one series)</small></label>
                        <input name="series_title" class="form-control" maxlength="120" value="{{ old('series_title', $v->series_title) }}"></div>
                    <div class="checkbox"><label><input type="checkbox" name="featured" value="1" @checked(old('featured', $v->featured))> Feature it on the app's home page (one film per app)</label></div>
                    <p class="text-muted"><small>Films are listed by part, then order. The studio finds a film by its app and its name (<code>{{ $v->slug }}</code>):
                        after moving it to another app, the studio pushes to the new app. A push from the studio never changes the title, words, part or app set here.</small></p>
                </div>
                <div class="box-footer"><button class="btn btn-primary">Save</button> <small class="text-muted">These show at once; no render needed.</small></div>
            </form>
        </div>
        <div class="box" id="promo-files">
            <div class="box-header with-border"><h3 class="box-title">Files</h3></div>
            <div class="box-body">
                <p class="text-muted"><small>Upload straight from this computer; no studio needed. Files go up in small pieces, so any size up to the limit works.
                    A film is made ready for phones as it arrives (it starts playing before it has all loaded). A new file waits here until you publish.</small></p>
                @foreach([
                    'video' => ['Film', '.mp4', 'video/mp4', 'An MP4 (H.264 and AAC). Vertical 1080 × 1920 for the series.'],
                    'poster' => ['Poster', '.jpg,.jpeg,.png', 'image/jpeg,image/png', 'The still shown before it plays: JPG or PNG, the film\'s shape, about 540 × 960.'],
                    'captions' => ['Captions', '.vtt', 'text/vtt', 'A WebVTT file. Without one, captions are made from the studio\'s timings when there are any.'],
                ] as $kind => [$label, $accept, $mime, $hint])
                    <div class="form-group promo-up" data-kind="{{ $kind }}" data-url="{{ admin_url('promo-videos/'.$v->id.'/upload/'.$kind) }}" data-max="{{ $max[$kind] ?? 0 }}">
                        <label>{{ $label }}
                            <small class="text-muted">@if($v->{$kind.'_path'}) now: {{ basename($v->{$kind.'_path'}) }} @else none yet @endif</small></label>
                        <input type="file" accept="{{ $accept }},{{ $mime }}">
                        <p class="help-block"><small>{{ $hint }} Up to {{ intdiv((int) ($max[$kind] ?? 0), 1048576) }} MB.</small></p>
                        <div class="progress" style="display:none;margin:4px 0"><div class="progress-bar" style="width:0%"></div></div>
                        <p class="promo-up-msg" style="margin:0" role="status"></p>
                    </div>
                @endforeach
            </div>
        </div>
        <script>
        (function () {
            var token = @json(csrf_token());
            function hex(buf) { return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
            function rand() { var a = new Uint8Array(16); crypto.getRandomValues(a); return hex(a); }
            document.querySelectorAll('#promo-files .promo-up').forEach(function (box) {
                if (box.dataset.bound) { return; }
                box.dataset.bound = '1';
                var input = box.querySelector('input[type=file]'), bar = box.querySelector('.progress-bar'), wrap = box.querySelector('.progress'), msg = box.querySelector('.promo-up-msg');
                function say(text, cls) { msg.textContent = text; msg.className = 'promo-up-msg ' + (cls || 'text-muted'); }
                input.addEventListener('change', async function () {
                    var file = input.files[0];
                    if (!file) { return; }
                    var max = parseInt(box.dataset.max, 10);
                    if (max && file.size > max) { say('That file is larger than the limit.', 'text-danger'); return; }
                    if (!window.crypto || !crypto.subtle) { say('This browser cannot check the file (open the admin over https).', 'text-danger'); return; }
                    input.disabled = true; wrap.style.display = ''; bar.style.width = '0%';
                    try {
                        say('Checking the file…');
                        var sha = hex(await crypto.subtle.digest('SHA-1', await file.arrayBuffer()));
                        var ext = (file.name.split('.').pop() || '').toLowerCase(), up = rand(), size = file.size, off = 0, step = 2 * 1048576, res = null;
                        while (off < size) {
                            var r = await fetch(box.dataset.url + '?upload=' + up + '&offset=' + off + '&size=' + size + '&sha1=' + sha + '&ext=' + encodeURIComponent(ext), {
                                method: 'POST', credentials: 'same-origin', body: file.slice(off, off + step),
                                headers: {'X-CSRF-TOKEN': token, 'Content-Type': 'application/octet-stream', 'Accept': 'application/json'}
                            });
                            res = await r.json().catch(function () { return {message: 'The server answered ' + r.status + '.'}; });
                            if (r.status === 409 && /received (\d+)/.test(res.message || '')) { off = parseInt(RegExp.$1, 10); continue; }
                            if (!r.ok) { throw new Error(res.message || ('The server answered ' + r.status + '.')); }
                            off = res.done ? size : res.received;
                            bar.style.width = Math.round(off * 100 / size) + '%';
                            say('Sending… ' + Math.round(off * 100 / size) + '%');
                        }
                        say('Received. Reloading…', 'text-success');
                        location.reload();
                    } catch (e) {
                        say(e.message || 'The upload stopped. Try again.', 'text-danger');
                        input.disabled = false;
                    }
                });
            });
        })();
        </script>
        @if($fromStudio)
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Spoken words <small>version {{ $v->script_rev }}</small></h3></div>
            <form method="post" action="{{ admin_url('promo-videos/'.$v->id.'/script') }}">@csrf
                <div class="box-body">
                    <p class="text-muted"><small>Change the words only: the scenes, screens and timing stay as filmed, so keep each line about as long as it is.
                        Every claim must be true of the app. After saving, queue a render; the public keeps the published film until you publish the new one.</small></p>
                    @foreach($v->script as $si => $scene)
                        @if(count($scene['lines']))
                            <h5 style="margin:14px 0 6px"><b>{{ $scene['id'] }}</b> <small class="text-muted">{{ $scene['template'] ?? '' }}</small></h5>
                            @foreach($scene['lines'] as $li => $line)
                                <div class="form-group" style="margin-bottom:6px">
                                    <div class="input-group">
                                        <span class="input-group-addon" style="min-width:96px;text-align:left"><small>{{ $line['speaker'] }}</small></span>
                                        <textarea name="lines[{{ $si }}][{{ $li }}]" class="form-control" rows="1" maxlength="400" required
                                                  style="resize:vertical">{{ old("lines.$si.$li", $line['text']) }}</textarea>
                                    </div>
                                </div>
                            @endforeach
                        @endif
                    @endforeach
                </div>
                <div class="box-footer"><button class="btn btn-primary">Save the words</button></div>
            </form>
        </div>
        @endif
    </div>
</div>
