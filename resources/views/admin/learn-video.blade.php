@php
    $chosen = old('apps', $l->exists ? $l->apps() : []);
    $src = old('source_type', $l->source_type ?? 'youtube');
    $dur = $l->duration_seconds ? $l->length() : '';
@endphp
<div class="row"><div class="col-md-12" style="margin-bottom:10px">
    <a href="{{ admin_url('learn-videos') }}"><i class="fa fa-arrow-left"></i> All lessons</a>
</div></div>
@if($errors->any())
    <div class="alert alert-danger"><ul style="margin:0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif
<div class="row">
<div class="col-md-8">
    <form method="post" action="{{ $l->exists ? admin_url('learn-videos/'.$l->id) : admin_url('learn-videos') }}" id="learn-form">@csrf
    <div class="box box-solid" style="border:1px solid #d2d6de">
        <div class="box-header with-border"><h3 class="box-title">The video</h3></div>
        <div class="box-body">
            <div class="form-group">
                <label class="radio-inline"><input type="radio" name="source_type" value="youtube" @checked($src === 'youtube')> YouTube</label>
                <label class="radio-inline"><input type="radio" name="source_type" value="file" @checked($src === 'file')> A file uploaded here</label>
                <label class="radio-inline"><input type="radio" name="source_type" value="url" @checked($src === 'url')> A link to a video file</label>
            </div>
            <div class="form-group learn-src" data-src="youtube">
                <label>YouTube link</label>
                <div class="input-group">
                    <input name="youtube" id="learn-youtube" class="form-control" maxlength="300" placeholder="https://www.youtube.com/watch?v=…" value="{{ old('youtube', $l->youtube_id ? 'https://www.youtube.com/watch?v='.$l->youtube_id : '') }}">
                    <span class="input-group-btn"><button type="button" class="btn btn-default" id="learn-lookup">Fill title and picture</button></span>
                </div>
                <p class="help-block" id="learn-lookup-msg"><small>Paste the link and press the button: the title and picture come from YouTube.</small></p>
            </div>
            <div class="form-group learn-src" data-src="url">
                <label>Link to the video file (.mp4)</label>
                <input name="video_url" type="url" class="form-control" maxlength="500" value="{{ old('video_url', $l->video_url) }}">
            </div>
            <p class="learn-src text-muted" data-src="file"><small>{{ $l->exists ? 'Upload the file in "Files" on the right.' : 'Save the lesson first, then upload its file on the right.' }}</small></p>
            <div class="row">
                <div class="col-sm-8 form-group"><label>Picture link <small class="text-muted">(optional; YouTube gives one)</small></label>
                    <input name="thumbnail_url" id="learn-thumb" type="url" class="form-control" maxlength="500" value="{{ old('thumbnail_url', $l->thumbnail_url) }}"></div>
                <div class="col-sm-4 form-group"><label>Length <small class="text-muted">m:ss</small></label>
                    <input name="duration" class="form-control" maxlength="10" placeholder="3:20" value="{{ old('duration', $dur) }}"></div>
            </div>
        </div>
    </div>

    <div class="box box-solid" style="border:1px solid #d2d6de">
        <div class="box-header with-border"><h3 class="box-title">Words</h3></div>
        <div class="box-body">
            <div class="form-group"><label>Title</label><input name="title" id="learn-title" class="form-control" required maxlength="160" value="{{ old('title', $l->title) }}"></div>
            <div class="form-group"><label>Short description <small class="text-muted">(one line under the title)</small></label>
                <input name="summary" class="form-control" maxlength="300" value="{{ old('summary', $l->summary) }}"></div>
            <div class="form-group"><label>More about it <small class="text-muted">(shown under the player)</small></label>
                <textarea name="description" class="form-control" rows="3" maxlength="5000">{{ old('description', $l->description) }}</textarea></div>
            <p class="text-muted"><small>Write for the app that shows it. A lesson for several apps should not name any one of them.</small></p>
        </div>
    </div>

    <div class="box box-solid" style="border:1px solid #d2d6de">
        <div class="box-header with-border"><h3 class="box-title">Where and for whom</h3></div>
        <div class="box-body">
            <div class="form-group"><label>Apps</label>
                <div class="checkbox" style="margin-top:0"><label><input type="checkbox" name="all_apps" value="1" id="learn-all" @checked(old('all_apps', $l->all_apps))> <b>All apps</b> (now and any added later)</label></div>
                <div id="learn-apps">
                    @foreach($apps as $slug => $name)
                        <label class="checkbox-inline" style="margin-left:0;margin-right:14px"><input type="checkbox" name="apps[]" value="{{ $slug }}" @checked(in_array($slug, $chosen, true))> {{ $name }}</label>
                    @endforeach
                </div>
            </div>
            <div class="row">
                <div class="col-sm-6 form-group"><label>Section</label>
                    <input name="section" class="form-control" list="learn-sections" maxlength="120" placeholder="e.g. Getting started" value="{{ old('section', $l->section?->title) }}">
                    <datalist id="learn-sections">@foreach($sections as $s)<option value="{{ $s }}">@endforeach</datalist>
                    <p class="help-block"><small>Pick one, or type a new name to start a section.</small></p></div>
                <div class="col-sm-6 form-group"><label>Who sees it</label>
                    <select name="audience" class="form-control">@foreach($audiences as $k => $label)<option value="{{ $k }}" @selected(old('audience', $l->audience) === $k)>{{ $label }}</option>@endforeach</select>
                    <p class="help-block"><small>Members are the read-only roles (viewer, family member); officers are everyone else in the books.</small></p></div>
            </div>
            <div class="form-group"><label>Screens it explains <small class="text-muted">(area keys, comma separated)</small></label>
                <input name="areas" class="form-control" maxlength="300" placeholder="e.g. contributions, gatherings" value="{{ old('areas', implode(', ', (array) $l->areas)) }}">
                <p class="help-block"><small>A screen's "How this works" link shows the lessons with its key. See docs/LEARN_VIDEOS.md for the keys in use.</small></p></div>
            <div class="row">
                <div class="col-sm-6 form-group"><label>Only these roles <small class="text-muted">(optional)</small></label>
                    <input name="roles" class="form-control" list="learn-roles" maxlength="500" value="{{ old('roles', implode(', ', (array) $l->roles)) }}">
                    <datalist id="learn-roles">@foreach($roles as $r)<option value="{{ $r }}">@endforeach</datalist></div>
                <div class="col-sm-6 form-group"><label>Only people who may <small class="text-muted">(permission keys, optional)</small></label>
                    <input name="permissions" class="form-control" list="learn-perms" maxlength="500" value="{{ old('permissions', implode(', ', (array) $l->permissions)) }}">
                    <datalist id="learn-perms">@foreach($perms as $p)<option value="{{ $p }}">@endforeach</datalist></div>
            </div>
            <div class="row">
                <div class="col-sm-4 form-group"><label>Language</label>
                    <select name="language" class="form-control">@foreach($languages as $k => $label)<option value="{{ $k }}" @selected(old('language', $l->language) === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-sm-8 form-group" style="padding-top:22px">
                    <label class="checkbox-inline"><input type="checkbox" name="featured" value="1" @checked(old('featured', $l->featured))> Feature it at the top of Learn</label>
                    <label class="checkbox-inline"><input type="checkbox" name="published" value="1" @checked(old('published', $l->published))> Published</label>
                </div>
            </div>
        </div>
        <div class="box-footer"><button class="btn btn-primary">{{ $l->exists ? 'Save' : 'Add the lesson' }}</button></div>
    </div>
    </form>
</div>

<div class="col-md-4">
    @if($l->exists)
        <div class="box box-solid" style="border:1px solid #d2d6de">
            <div class="box-header with-border"><h3 class="box-title">Preview</h3></div>
            <div class="box-body">
                @if($l->source_type === 'youtube' && $l->youtube_id)
                    <div style="position:relative;padding-top:56.25%"><iframe style="position:absolute;inset:0;width:100%;height:100%;border:0"
                        src="https://www.youtube-nocookie.com/embed/{{ $l->youtube_id }}?rel=0&modestbranding=1&playsinline=1" allowfullscreen loading="lazy" title="Preview"></iframe></div>
                @elseif($l->playUrl())
                    <video src="{{ $l->playUrl() }}" controls preload="metadata" style="width:100%" @if($l->thumbnail()) poster="{{ $l->thumbnail() }}" @endif></video>
                @else
                    <p class="text-muted">No video yet.</p>
                @endif
                <p style="margin:8px 0 0">@if($l->published)<span class="label label-success">Live</span> since {{ $l->published_at?->format('j M Y') }}@else<span class="label label-default">Draft</span>@endif</p>
                @if($stats)<p style="margin:6px 0 0"><small>{{ $stats['views'] }} views · {{ $stats['people'] }} people · {{ $stats['completed'] }} finished · {{ $stats['minutes'] }} minutes watched</small></p>@endif
            </div>
        </div>
        <div class="box box-solid" style="border:1px solid #d2d6de" id="learn-files">
            <div class="box-header with-border"><h3 class="box-title">Files</h3></div>
            <div class="box-body">
                @foreach([
                    'video' => ['Video file', '.mp4,.webm,.m4v,.mov', 'An MP4 (H.264 and AAC) plays everywhere. Choosing a file makes this an uploaded lesson.', $l->file_path],
                    'thumbnail' => ['Picture', '.jpg,.jpeg,.png,.webp', 'Shown before it plays, 16:9.', $l->thumbnail_path],
                ] as $kind => [$label, $accept, $hint, $now])
                    <div class="form-group learn-up" data-url="{{ admin_url('learn-videos/'.$l->id.'/upload/'.$kind) }}" data-max="{{ $max[$kind] ?? 0 }}">
                        <label>{{ $label }} <small class="text-muted">@if($now) now: <a href="{{ $media($now) }}" target="_blank" rel="noopener">{{ basename($now) }}</a> @else none yet @endif</small></label>
                        <input type="file" accept="{{ $accept }}">
                        <p class="help-block"><small>{{ $hint }} Up to {{ intdiv((int) ($max[$kind] ?? 0), 1048576) }} MB.</small></p>
                        <div class="progress" style="display:none;margin:4px 0"><div class="progress-bar" style="width:0%"></div></div>
                        <p class="learn-up-msg" style="margin:0" role="status"></p>
                    </div>
                @endforeach
            </div>
        </div>
        <form method="post" action="{{ admin_url('learn-videos/'.$l->id.'/destroy') }}" onsubmit="return confirm('Delete this lesson and everyone\'s progress on it?')">@csrf
            <button class="btn btn-default btn-sm text-danger"><i class="fa fa-trash"></i> Delete the lesson</button></form>
    @else
        <p class="text-muted">Save the lesson to upload files and see its numbers.</p>
    @endif
</div>
</div>

<script>
(function () {
    var form = document.getElementById('learn-form');
    if (!form || form.dataset.bound) { return; }
    form.dataset.bound = '1';
    var token = @json(csrf_token()), lookup = @json(admin_url('learn-videos/youtube'));
    function showSrc() {
        var v = (form.querySelector('input[name=source_type]:checked') || {}).value;
        form.querySelectorAll('.learn-src').forEach(function (el) { el.style.display = el.dataset.src === v ? '' : 'none'; });
    }
    form.querySelectorAll('input[name=source_type]').forEach(function (r) { r.addEventListener('change', showSrc); });
    showSrc();
    var all = document.getElementById('learn-all'), list = document.getElementById('learn-apps');
    function showApps() { list.style.opacity = all.checked ? '0.4' : ''; list.querySelectorAll('input').forEach(function (i) { i.disabled = all.checked; }); }
    all.addEventListener('change', showApps);
    showApps();
    document.getElementById('learn-lookup').addEventListener('click', async function () {
        var msg = document.getElementById('learn-lookup-msg');
        msg.textContent = 'Asking YouTube…';
        try {
            var r = await fetch(lookup + '?url=' + encodeURIComponent(document.getElementById('learn-youtube').value), {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
            var d = await r.json();
            if (!r.ok) { throw new Error(d.message || 'YouTube did not answer.'); }
            var title = document.getElementById('learn-title');
            if (d.title && !title.value) { title.value = d.title; }
            var thumb = document.getElementById('learn-thumb');
            if (!thumb.value) { thumb.value = d.thumbnail_url; }
            msg.textContent = d.title ? 'Filled from YouTube.' : 'Found the video; type its title (YouTube did not give it).';
        } catch (e) { msg.textContent = e.message; }
    });

    function hex(buf) { return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
    function rand() { var a = new Uint8Array(16); crypto.getRandomValues(a); return hex(a); }
    document.querySelectorAll('#learn-files .learn-up').forEach(function (box) {
        var input = box.querySelector('input[type=file]'), bar = box.querySelector('.progress-bar'), wrap = box.querySelector('.progress'), msg = box.querySelector('.learn-up-msg');
        function say(text, cls) { msg.textContent = text; msg.className = 'learn-up-msg ' + (cls || 'text-muted'); }
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
