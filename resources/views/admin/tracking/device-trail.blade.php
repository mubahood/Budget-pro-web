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
<div class="box box-solid" id="thread">
    <div class="box-header with-border">
        <h3 class="box-title"><i class="fa fa-list"></i> Location Thread</h3>
        <p class="text-muted" style="margin: 4px 0 0;">Every fix this device recorded, newest first — each one is its own entry, not just the last-known snapshot.</p>
    </div>
    <div class="box-body" style="max-height: 500px; overflow-y: auto; padding: 0;">
        <ul class="list-group" id="thread-list" style="margin-bottom: 0;"></ul>
    </div>
</div>
@endif

@if ($points->isNotEmpty())
<script>
(function () {
    var ACTIVITY_COLORS = { still: 'secondary', walking: 'info', running: 'warning', in_vehicle: 'danger' };
    var ACTIVITY_ICONS = { still: 'fa-male', walking: 'fa-male', running: 'fa-bolt', in_vehicle: 'fa-car' };

    function placeLabel(p) {
        return p.placeName || (p.lat.toFixed(5) + ', ' + p.lng.toFixed(5));
    }

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
        .bindPopup('<b>Start</b><br>📍 ' + placeLabel(points[0]) + '<br>' + points[0].recordedAt);

    var last = points[points.length - 1];
    L.circleMarker(latLngs[latLngs.length - 1], { radius: 8, color: '#dc3545', fillColor: '#dc3545', fillOpacity: 0.9 })
        .addTo(map)
        .bindPopup('<b>Latest</b><br>📍 ' + placeLabel(last) + '<br>' + last.recordedAt + '<br>Battery: ' + (last.battery !== null ? last.battery + '%' : 'unknown'));

    // Small markers for every intermediate point — a full popup per point
    // would be too heavy for thousands of fixes, so these stay light.
    points.forEach(function (p, i) {
        if (i === 0 || i === points.length - 1) return;
        L.circleMarker([p.lat, p.lng], { radius: 3, color: '#1565C0', fillColor: '#1565C0', fillOpacity: 0.6 })
            .addTo(map)
            .bindPopup('📍 ' + placeLabel(p) + '<br>' + p.recordedAt + (p.activity ? ' &middot; ' + p.activity : ''));
    });

    map.fitBounds(latLngs, { padding: [40, 40] });

    // Thread feed — same data as the map, newest first, read like an
    // activity timeline instead of a table of raw coordinates.
    var list = document.getElementById('thread-list');
    var html = points.slice().reverse().map(function (p) {
        var color = ACTIVITY_COLORS[p.activity] || 'light';
        var icon = ACTIVITY_ICONS[p.activity] || 'fa-question';
        var battery = p.battery !== null && p.battery !== undefined ? p.battery + '%' : '—';
        return '' +
            '<li class="list-group-item" style="border-left: 3px solid #1565C0;">' +
                '<div style="display:flex; justify-content:space-between; align-items:flex-start;">' +
                    '<div>' +
                        '<i class="fa fa-map-marker" style="color:#1565C0;"></i> <b>' + placeLabel(p) + '</b><br>' +
                        '<small class="text-muted">' + p.lat.toFixed(5) + ', ' + p.lng.toFixed(5) + '</small>' +
                    '</div>' +
                    '<div style="text-align:right; white-space:nowrap; margin-left:12px;">' +
                        '<span class="badge badge-' + color + '"><i class="fa ' + icon + '"></i> ' + (p.activity || 'unknown') + '</span><br>' +
                        '<small class="text-muted">' + p.recordedAt + ' &middot; 🔋 ' + battery + '</small>' +
                    '</div>' +
                '</div>' +
            '</li>';
    }).join('');
    list.innerHTML = html;
})();
</script>
@endif
