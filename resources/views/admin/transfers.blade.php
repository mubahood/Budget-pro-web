<div class="box">
    <div class="box-body no-padding">
        <table class="table table-striped">
            <tr><th>No.</th><th>When</th><th>From</th><th>To</th><th>Note</th></tr>
            @forelse($rows as $r)
                <tr><td>{{ $r->number }}</td><td>{{ substr((string) $r->created_at, 0, 16) }}</td><td>{{ $r->from }}</td><td>{{ $r->to }}</td><td>{{ $r->notes }}</td></tr>
            @empty
                <tr><td colspan="5" class="text-muted">No transfers yet.</td></tr>
            @endforelse
        </table>
    </div>
</div>
