@forelse($groups as $g)
    <div class="box">
        <div class="box-header with-border"><h3 class="box-title">{{ $g['label'] }}: <code>{{ $g['key'] }}</code></h3></div>
        <div class="box-body">
            <form method="post" action="{{ admin_url('duplicates/merge') }}">@csrf
                <input type="hidden" name="kind" value="{{ $g['kind'] }}">
                <table class="table table-condensed"><tr><th>Keep</th><th>Name</th><th>Added</th></tr>
                    @foreach($g['rows'] as $i => $r)
                        <tr><td><input type="radio" name="keep_id" value="{{ $r['id'] }}" @checked($i === 0)><input type="hidden" name="ids[]" value="{{ $r['id'] }}"></td>
                            <td>{{ $r['name'] ?? '' }}</td><td>{{ substr((string) ($r['created_at'] ?? ''), 0, 16) }}</td></tr>
                    @endforeach
                </table>
                <button class="btn btn-primary btn-sm" onclick="return confirm('Merge these into the one you keep? Sales, stock and payments move over.')">Merge into the one I keep</button>
            </form>
        </div>
    </div>
@empty
    <div class="alert alert-success">No duplicates found.</div>
@endforelse
