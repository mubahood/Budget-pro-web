<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="box box-solid">
    <div class="box-body">
        @if ($devices->isEmpty())
            <div class="alert alert-info">No device has reported a location yet.</div>
        @else
            <div id="fleet-map" style="height: 600px; width: 100%;"></div>
        @endif
    </div>
</div>

@if ($devices->isNotEmpty())
<script>
(function () {
    var map = L.map('fleet-map');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);

    var points = [];
    var devices = @json($devicesJson);

    devices.forEach(function (d) {
        var color = d.tracking ? '#28a745' : '#6c757d';
        var marker = L.circleMarker([d.lat, d.lng], {
            radius: 9,
            color: color,
            fillColor: color,
            fillOpacity: 0.8,
        }).addTo(map);

        var battery = d.battery !== null ? d.battery + '%' : 'unknown';
        marker.bindPopup(
            '<b>' + d.name + '</b><br>' +
            (d.model || '') + '<br>' +
            '📍 ' + (d.placeName || (d.lat.toFixed(5) + ', ' + d.lng.toFixed(5))) + '<br>' +
            'Battery: ' + battery + '<br>' +
            'Last fix: ' + d.lastFix + '<br>' +
            '<a href="' + d.url + '">Device details</a> &middot; ' +
            '<a href="' + d.trailUrl + '">View trail</a>'
        );

        points.push([d.lat, d.lng]);
    });

    if (points.length === 1) {
        map.setView(points[0], 15);
    } else {
        map.fitBounds(points, { padding: [40, 40] });
    }
})();
</script>
@endif
