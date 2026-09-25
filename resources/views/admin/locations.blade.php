<div class="row">
    <div class="col-md-7">
        <div class="box box-primary">
            <div class="box-body no-padding">
                <table class="table">
                    <tr><th>Location</th><th>Address</th><th class="text-right">Stock value (cost)</th></tr>
                    @foreach($locations as $l)
                        <tr><td>{{ $l->name }} @if($l->is_default)<span class="label label-default">main</span>@endif @unless($l->is_active)<span class="label label-danger">closed</span>@endunless</td>
                            <td>{{ $l->address }}</td><td class="text-right">{{ \App\Admin\Controllers\LocationController::value($values[$l->id] ?? 0) }}</td></tr>
                    @endforeach
                </table>
            </div>
        </div>
        @if($multi)
            <form method="post" action="{{ admin_url('locations') }}" class="form-inline">@csrf
                <input name="name" class="form-control" placeholder="New location name" required>
                <input name="address" class="form-control" placeholder="Address (optional)">
                <button class="btn btn-primary">Add location</button>
            </form>
        @else
            <p class="text-muted">More than one shop or store is part of the Business plan. <a href="{{ admin_url('billing') }}">See plans</a>.</p>
        @endif
    </div>
    <div class="col-md-5">
        <div class="box">
            <div class="box-header"><h3 class="box-title">Which phone sells where</h3></div>
            <div class="box-body">
                <form method="post" action="{{ admin_url('locations/devices') }}">@csrf
                    @forelse($devices as $d)
                        <div class="form-group"><label>{{ $d->name }}</label>
                            <select class="form-control" name="device_location[{{ $d->id }}]">
                                <option value="">Main location</option>
                                @foreach($locations as $l)<option value="{{ $l->id }}" @selected($d->location_id == $l->id)>{{ $l->name }}</option>@endforeach
                            </select></div>
                    @empty
                        <p class="text-muted">No phones yet. Sign in on the app to add one.</p>
                    @endforelse
                    @if(count($devices))<button class="btn btn-default">Save</button>@endif
                </form>
            </div>
        </div>
    </div>
</div>
