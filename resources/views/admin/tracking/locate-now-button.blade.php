<form action="{{ admin_url('tracked-devices/'.$device->id.'/locate-now') }}" method="post" style="display:inline">
    @csrf
    <button type="submit" class="btn btn-warning">
        <i class="fa fa-crosshairs"></i> Locate Now
    </button>
    <small class="text-muted">Queues an on-demand GPS fix — the device fetches it on its next sync (usually within {{ $intervalSeconds ?? 60 }}s).</small>
</form>
