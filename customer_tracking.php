<?php
require_once __DIR__ . '/config/session.php';


session_start();

if (!isset($_SESSION['user_id']) || strtolower(trim((string)($_SESSION['role'] ?? ''))) !== 'customer') {
    header('Location: auth/login.php');
    exit;
}

$page = 'customer_tracking.php';
include 'header.php';
$userId = (int)$_SESSION['user_id'];
$livePickup = null;

try {
    require_once __DIR__ . '/config/customer_tracking.php';
    $livePickup = fetchCustomerTracking($conn, $userId);
} catch (PDOException $e) {}
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
    .customer-track-map { height: min(70vh, 650px); min-height: 360px; border-radius: 16px; z-index: 1; }
    .tracking-status { background: #f0fdf4; color: #166534; border-radius: 10px; padding: .8rem 1rem; }
</style>

<div class="page-body">
    <?php
 include 'sidebar.php'; ?>
    <section class="content-panel px-3 px-md-4 py-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h1 class="h4 fw-bold mb-1" style="color:#166534;">Track My Pickup</h1>
                        <p class="text-muted mb-0">Follow your assigned lorry and estimated arrival time.</p>
                    </div>
                    <span class="status-pill badge" id="customerTrackingBadge">Checking...</span>
                </div>
                <div class="customer-track-map" id="customerTrackingMap"></div>
                <div class="tracking-status mt-3" id="customerMapStatus">Loading lorry location...</div>
            </div>
        </div>
    </section>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(() => {
    const mapElement = document.getElementById('customerTrackingMap');
    const statusElement = document.getElementById('customerMapStatus');
    const badgeElement = document.getElementById('customerTrackingBadge');
    let tracking = <?php
 echo json_encode($livePickup ?: null, JSON_UNESCAPED_UNICODE); ?>;
    let routeLayer = null;
    let lorryMarker = null;
    let destinationMarker = null;
    let routeKey = '';
    if (!mapElement || typeof L === 'undefined') return;

    const map = L.map(mapElement).setView([6.1244, 100.3670], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxNativeZoom: 19, maxZoom: 21,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    const valid = value => value !== null && value !== '' && Number.isFinite(Number(value));
    function clearMap() {
        [lorryMarker, destinationMarker, routeLayer].forEach(layer => {
            if (layer) map.removeLayer(layer);
        });
        lorryMarker = destinationMarker = routeLayer = null;
        routeKey = '';
    }
    function updateMap(data) {
        tracking = data;
        if (!data) {
            clearMap();
            badgeElement.textContent = 'No active pickup';
            statusElement.textContent = 'No active lorry is assigned to your booking yet.';
            return;
        }
        badgeElement.textContent = data.lorry_status || data.status || 'Assigned';
        if (!valid(data.current_lat) || !valid(data.current_long)) {
            statusElement.textContent = 'Lorry assigned. Waiting for its GPS location...';
            return;
        }
        if (!valid(data.destination_lat) || !valid(data.destination_long)) {
            statusElement.textContent = 'Lorry location received. Waiting for the arrival destination route...';
            return;
        }
        const start = [Number(data.current_lat), Number(data.current_long)];
        const end = [Number(data.destination_lat), Number(data.destination_long)];
        if (!lorryMarker) lorryMarker = L.marker(start).addTo(map);
        else lorryMarker.setLatLng(start);
        lorryMarker.bindPopup('<strong>' + (data.plate_number || 'Assigned lorry') + '</strong><br>Live lorry position');
        if (!destinationMarker) destinationMarker = L.marker(end).addTo(map);
        else destinationMarker.setLatLng(end);
        destinationMarker.bindPopup('<strong>Your pickup destination</strong><br>' + (data.address || 'Assigned location'));

        const key = start.join(',') + '|' + end.join(',');
        if (key === routeKey) return;
        routeKey = key;
        const path = start[1] + ',' + start[0] + ';' + end[1] + ',' + end[0] + '?overview=full&geometries=geojson';
        fetch('https://router.project-osrm.org/route/v1/driving/' + path)
            .then(response => response.json())
            .then(result => {
                if (!result.routes || !result.routes[0]) throw new Error('No route');
                if (routeLayer) map.removeLayer(routeLayer);
                const route = result.routes[0];
                routeLayer = L.geoJSON(route.geometry, { style: { color: '#16a34a', weight: 6, opacity: .9 } }).addTo(map);
                statusElement.textContent = 'Lorry ' + (data.plate_number || '') + ' · ETA about ' +
                    Math.max(1, Math.round(route.duration / 60)) + ' min · ' +
                    (route.distance / 1000).toFixed(1) + ' km away';
                map.fitBounds(routeLayer.getBounds(), { padding: [25, 25] });
            })
            .catch(() => {
                if (routeLayer) map.removeLayer(routeLayer);
                routeLayer = L.polyline([start, end], { color: '#16a34a', weight: 4, dashArray: '8 8' }).addTo(map);
                statusElement.textContent = 'Lorry is on the way · road ETA unavailable';
                map.fitBounds(routeLayer.getBounds(), { padding: [25, 25] });
            });
    }
    function refreshTracking() {
        fetch('api/customer_tracking.php', { cache: 'no-store', credentials: 'same-origin' })
            .then(response => response.json())
            .then(result => { if (result.success) updateMap(result.tracking || null); })
            .catch(() => { statusElement.textContent = 'Tracking temporarily unavailable.'; });
    }
    updateMap(tracking);
    setInterval(refreshTracking, 10000);
    window.addEventListener('resize', () => map.invalidateSize());
})();
</script>
<?php
 include 'footer.php'; ?>
