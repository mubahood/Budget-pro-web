<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="box box-solid">
    <div class="box-body">
        <form method="get" class="form-inline" style="margin-bottom: 16px;">
            <label style="margin-right: 8px;">From</label>
            <input type="date" name="since" value="{{ $since }}" class="form-control" style="margin-right: 12px;">
            <label style="margin-right: 8px;">To</label>
            <input type="date" name="until" value="{{ $until }}" class="form-control" style="margin-right: 12px;">
            <button type="submit" class="btn btn-primary">Update</button>
            <a href="{{ admin_url('tracked-devices/' . $device->id) }}" class="btn btn-default" style="margin-left: 8px;">
                &larr; Back to device
            </a>
        </form>

        @if ($points->isEmpty())
            <div class="alert alert-info">No location points for {{ $device->name }} in this date range.</div>
        @else
            <div id="trail-map" style="height: 600px; width: 100%;"></div>
            <p class="text-muted" style="margin-top: 8px;">{{ $points->count() }} point(s) between {{ $since }} and {{ $until }}.</p>
        @endif
    </div>
</div>

@if ($points->isNotEmpty())
<script>
(function () {
    var map = L.map('trail-map');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);

    var points = @json($pointsJson);

    var latLngs = points.map(function (p) { return [p.lat, p.lng]; });

    L.polyline(latLngs, { color: '#1565C0', weight: 3, opacity: 0.7 }).addTo(map);

    L.circleMarker(latLngs[0], { radius: 8, color: '#28a745', fillColor: '#28a745', fillOpacity: 0.9 })
        .addTo(map)
        .bindPopup('<b>Start</b><br>' + points[0].recordedAt);

    var last = points[points.length - 1];
    L.circleMarker(latLngs[latLngs.length - 1], { radius: 8, color: '#dc3545', fillColor: '#dc3545', fillOpacity: 0.9 })
        .addTo(map)
        .bindPopup('<b>Latest</b><br>' + last.recordedAt + '<br>Battery: ' + (last.battery !== null ? last.battery + '%' : 'unknown'));

    // Small markers for every intermediate point — a full popup per point
    // would be too heavy for thousands of fixes, so these stay light.
    points.forEach(function (p, i) {
        if (i === 0 || i === points.length - 1) return;
        L.circleMarker([p.lat, p.lng], { radius: 3, color: '#1565C0', fillColor: '#1565C0', fillOpacity: 0.6 })
            .addTo(map)
            .bindPopup(p.recordedAt + (p.activity ? ' &middot; ' + p.activity : ''));
    });

    map.fitBounds(latLngs, { padding: [40, 40] });
})();
</script>
@endif
