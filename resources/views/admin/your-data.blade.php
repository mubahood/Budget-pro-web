<div class="row">
    <div class="col-md-6">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-download"></i> Download all your data</h3></div>
            <div class="box-body">
                <p>A zip with one spreadsheet (CSV) per list: products, sales, payments, customers, suppliers, stock movements, expenses, team and more.</p>
                <form method="post" action="{{ admin_url('your-data/export') }}">@csrf<button class="btn btn-primary">Prepare my download</button></form>
                <table class="table table-condensed" style="margin-top:12px">
                    @foreach($requests->where('kind', 'export') as $r)
                        <tr><td>{{ substr((string) $r->created_at, 0, 16) }}</td><td>{{ ucfirst($r->status) }}</td>
                            <td>@if($r->status === 'ready' && now()->diffInDays($r->completed_at) <= 7)<a href="{{ admin_url('your-data/exports/'.$r->id) }}"><i class="fa fa-file-archive-o"></i> Download</a>@endif</td></tr>
                    @endforeach
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="box box-danger">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-trash"></i> Delete the shop</h3></div>
            <div class="box-body">
                @if($scheduled)
                    <div class="alert alert-danger">Everything will be deleted on <strong>{{ \Illuminate\Support\Carbon::parse($scheduled->purge_after)->format('d M Y') }}</strong>.</div>
                    <form method="post" action="{{ admin_url('your-data/delete/cancel') }}">@csrf<button class="btn btn-success">Keep my shop — cancel deletion</button></form>
                @elseif($isOwner)
                    <p>All products, sales, customers, team logins and settings are deleted {{ $graceDays }} days after you confirm. Until then you can cancel. Download your data first.</p>
                    <form method="post" action="{{ admin_url('your-data/delete') }}" onsubmit="return confirm('Delete the shop and all its data in {{ $graceDays }} days?')">@csrf
                        <div class="form-group"><label>Your password</label><input type="password" name="password" class="form-control" required></div>
                        <button class="btn btn-danger">Schedule deletion</button>
                    </form>
                @else
                    <p class="text-muted">Only the owner can delete the shop.</p>
                @endif
            </div>
        </div>
    </div>
</div>
