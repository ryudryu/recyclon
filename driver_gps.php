<?php
/**
 * Driver GPS Auto-Tracker
 * Drivers use the browser Geolocation API to send a position every 5 seconds.
 */

header('Permissions-Policy: geolocation=(self)');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/config/db.php';

$currentUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$currentRole = (string) ($_SESSION['role'] ?? '');
$isAuthenticated = $currentUserId > 0;
$canUseDriverGps = $isAuthenticated && in_array($currentRole, ['Admin', 'Staff', 'Driver'], true);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];

$message = '';
$error = '';

$lorries = [];
$ongoingAssignments = [];
if (!$canUseDriverGps) {
    header('Location: auth/login.php');
    exit;
}
// The dashboard is the single driver tracking workspace. Admins and staff
// can still open this standalone page when they need the focused GPS view.
if ($currentRole === 'Driver') {
    header('Location: driver_dashboard.php');
    exit;
}
try {
    $userId = $currentUserId;
    $isStaff = in_array($currentRole, ['Staff', 'Driver'], true);

    if ($isStaff && $userId > 0) {
        $stmt = $conn->prepare("
            SELECT l.lorry_id, l.plate_number,
                   COALESCE(u.name, 'Unassigned') AS driver_name,
                   l.current_lat, l.current_long,
                   l.destination_lat, l.destination_long, l.status
            FROM lorries l
            LEFT JOIN users u ON l.driver_id = u.user_id
            WHERE l.driver_id = ?
            ORDER BY l.lorry_id ASC
        ");
        $stmt->execute([$userId]);
        $lorries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $lorries = $conn->query("
            SELECT l.lorry_id, l.plate_number,
                   COALESCE(u.name, 'Unassigned') AS driver_name,
                   l.current_lat, l.current_long,
                   l.destination_lat, l.destination_long, l.status
            FROM lorries l
            LEFT JOIN users u ON l.driver_id = u.user_id
            ORDER BY l.lorry_id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // The project has used two queue schemas. Read the active assignment from
    // either schema so the driver page still shows the ongoing job.
    if (app_table_exists($conn, 'arrival_queue')) {
        $queueColumns = app_table_columns($conn, 'arrival_queue');
        if (in_array('status', $queueColumns, true)) {
            $queueRows = $conn->query("SELECT * FROM arrival_queue WHERE status = 'ongoing' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($lorries as $lorry) {
                foreach ($queueRows as $queueRow) {
                    $matchesLorry = in_array('plate_number', $queueColumns, true)
                        && strcasecmp(trim((string)($queueRow['plate_number'] ?? '')), trim((string)$lorry['plate_number'])) === 0;
                    $matchesDriver = in_array('driver_name', $queueColumns, true)
                        && strcasecmp(trim((string)($queueRow['driver_name'] ?? '')), trim((string)$lorry['driver_name'])) === 0;
                    if ($matchesLorry || $matchesDriver) {
                        $ongoingAssignments[(int)$lorry['lorry_id']] = $queueRow;
                        break;
                    }
                }
            }
        }
    }
} catch (PDOException $e) {
    $lorries = [];
}

$autoLorryId = null;
if ($currentUserId > 0 && in_array($currentRole, ['Staff', 'Driver'], true)) {
    $stmt = $conn->prepare("SELECT lorry_id FROM lorries WHERE driver_id = ? LIMIT 1");
    $stmt->execute([$currentUserId]);
    $autoLorryId = $stmt->fetchColumn();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Driver GPS Tracker</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="assets/css/driver-gps.css">
</head>
<body>

<div class="header">
    <a href="driver_dashboard.php" class="back-dashboard-btn">← Back to Dashboard</a>
    <h1>Driver GPS Tracker</h1>
    <p>GPS updates every 5 seconds &middot; HTTPS/localhost required for browser location access</p>
</div>

<div class="container">

    <div class="card">
        <div class="card-title">Select Your Lorry</div>
        <select class="form-select" id="lorrySelect">
            <option value="">&mdash; Choose lorry &mdash;</option>
            <?php foreach ($lorries as $l): ?>
                <option value="<?php echo $l['lorry_id']; ?>"
                    data-current-lat="<?php echo htmlspecialchars((string) ($l['current_lat'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                    data-current-lng="<?php echo htmlspecialchars((string) ($l['current_long'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                    data-destination-lat="<?php echo htmlspecialchars((string) ($l['destination_lat'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                    data-destination-lng="<?php echo htmlspecialchars((string) ($l['destination_long'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                    data-destination-name="<?php echo htmlspecialchars((string) ($l['destination_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                    data-ongoing-location="<?php echo htmlspecialchars((string) ($ongoingAssignments[(int)$l['lorry_id']]['location_name'] ?? $ongoingAssignments[(int)$l['lorry_id']]['geolocation'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                    data-ongoing-id="<?php echo htmlspecialchars((string) ($ongoingAssignments[(int)$l['lorry_id']]['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                    <?php echo ($autoLorryId == $l['lorry_id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($l['plate_number'] . ' - ' . $l['driver_name'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div id="statusBar">
            <span class="status-badge inactive">
                <span class="status-dot inactive"></span> Not tracking
            </span>
        </div>
    </div>

    <div class="card">
        <div class="card-title map-card-title">
            <span>Driver Route Map</span>
            <span class="driver-live-pill"><span></span> LIVE</span>
        </div>
        <div class="map-shell" id="driverMapShell">
            <div id="driverMap"></div>
            <button type="button" class="map-fullscreen-btn" id="fullscreenMapBtn" aria-label="Open map full screen">⛶ Full screen</button>
            <button type="button" class="map-locate-btn" id="locateMapBtn" aria-label="Center map on my location">◎</button>
        </div>
        <div class="route-panel" id="routePanel">Select your lorry to load its route.</div>
        <div class="destination-summary" id="destinationSummary">
            <div class="destination-summary-label">Assigned destination</div>
            <div class="destination-summary-name" id="destinationSummaryName">No destination assigned</div>
            <div class="destination-summary-meta" id="destinationSummaryMeta">An administrator must assign a pickup before you start.</div>
            <a class="destination-navigation-btn hidden" id="destinationNavigationBtn" href="#" target="_blank" rel="noopener">Open turn-by-turn navigation &rarr;</a>
            <button type="button" class="btn btn-complete hidden" id="completeDestinationBtn">Complete Destination</button>
        </div>

        <div class="coords-display" id="coordsDisplay">
            <div class="label">Current Position</div>
            <div class="latlng" id="coordText">&mdash;</div>
            <div class="accuracy-note" id="accuracyText"></div>
        </div>

        <div class="metrics-grid">
            <div class="metric-box">
                <div class="metric-label">Speed</div>
                <div class="metric-value" id="speedText">&mdash;</div>
            </div>
            <div class="metric-box">
                <div class="metric-label">Heading</div>
                <div class="metric-value" id="headingText">&mdash;</div>
            </div>
            <div class="metric-box">
                <div class="metric-label">Updates</div>
                <div class="metric-value" id="updateCount">0</div>
            </div>
        </div>

        <button class="btn btn-start" id="btnStart" onclick="startTracking()">Start Tracking</button>
        <button class="btn btn-stop hidden" id="btnStop" onclick="stopTracking()">Stop Tracking</button>
    </div>

    <div class="card">
        <div class="card-title">How It Works</div>
        <div class="info-box">
            <strong>1.</strong> Select your lorry from the dropdown<br>
            <strong>2.</strong> Tap <strong>"Start Tracking"</strong> &mdash; your browser will ask for location permission<br>
            <strong>3.</strong> Every <strong>5 seconds</strong> your GPS position is sent to the server<br>
            <strong>4.</strong> Status automatically changes to <strong>"On Duty"</strong><br>
            <strong>5.</strong> Tap <strong>Complete Destination</strong> when the pickup is finished
        </div>
    </div>
</div>

<script>
let watchId = null;
let trackingInterval = null;
let lorryId = null;
let updateHistory = [];
let currentLat = null;
let currentLng = null;
let driverMap = null;
let driverMarker = null;
let updateCount = 0;
let trackingActive = false;
let trackingGeneration = 0;
let positionRequestInFlight = false;
let activeRequestController = null;
let statusRequest = null;
let routeLine = null;
let traveledLine = null;
let traveledPoints = [];
let traveledLoadGeneration = 0;
let destinationMarker = null;
let routeKey = '';
let routeRequestGeneration = 0;
let followMode = true;
let geocodeInFlight = false;

const SEND_INTERVAL = 5000;
const API_URL = 'api/gps_api.php';
const CSRF_TOKEN = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

const lorrySelect = document.getElementById('lorrySelect');
const btnStart = document.getElementById('btnStart');
const btnStop = document.getElementById('btnStop');
const statusBar = document.getElementById('statusBar');
const coordText = document.getElementById('coordText');
const accuracyText = document.getElementById('accuracyText');
const speedText = document.getElementById('speedText');
const headingText = document.getElementById('headingText');
const historyList = document.getElementById('historyList');
const updateCountEl = document.getElementById('updateCount');
const routePanel = document.getElementById('routePanel');
const destinationSummaryName = document.getElementById('destinationSummaryName');
const destinationSummaryMeta = document.getElementById('destinationSummaryMeta');
const destinationNavigationBtn = document.getElementById('destinationNavigationBtn');
const completeDestinationBtn = document.getElementById('completeDestinationBtn');
const mapShell = document.getElementById('driverMapShell');
const fullscreenMapBtn = document.getElementById('fullscreenMapBtn');
const locateMapBtn = document.getElementById('locateMapBtn');

driverMap = L.map('driverMap').setView([6.1244, 100.3670], 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    noWrap: true,
    attribution: '&copy; OpenStreetMap'
}).addTo(driverMap);

function selectedLorryOption() {
    return lorrySelect.options[lorrySelect.selectedIndex];
}

function coordinatesFromOption() {
    const option = selectedLorryOption();
    if (!option || !option.value) return null;
    const number = value => Number.parseFloat(option.dataset[number]);
    const destinationLat = number('destinationLat');
    const destinationLng = number('destinationLng');
    const ongoingLocation = option.dataset.ongoingLocation || '';
    if (!Number.isFinite(destinationLat) || !Number.isFinite(destinationLng)) {
        return ongoingLocation ? { ongoingLocation, destinationName: ongoingLocation } : null;
    }
    return {
        destinationLat,
        destinationLng,
        destinationName: ongoingLocation || option.dataset.destinationName || 'Assigned destination',
        ongoingId: option.dataset.ongoingId || ''
    };
}

function updateDestinationSummary() {
    const option = selectedLorryOption();
    const destination = coordinatesFromOption();
    if (!option || !option.value || !destination) {
        destinationSummaryName.textContent = 'No destination assigned';
        destinationSummaryMeta.textContent = 'An administrator must assign a pickup before you start.';
        destinationNavigationBtn.classList.add('hidden');
        completeDestinationBtn.classList.add('hidden');
        return;
    }

    destinationSummaryName.textContent = destination.destinationName || destination.ongoingLocation || 'Assigned pickup';
    if (Number.isFinite(destination.destinationLat) && Number.isFinite(destination.destinationLng)) {
        destinationSummaryMeta.textContent = 'Route goal: ' + destination.destinationLat.toFixed(6) + '°N, ' + destination.destinationLng.toFixed(6) + '°E';
        destinationNavigationBtn.href = 'https://www.google.com/maps/dir/?api=1&destination=' + destination.destinationLat + ',' + destination.destinationLng;
        destinationNavigationBtn.classList.remove('hidden');
        updateCompletionButton(destination);
    } else {
        destinationSummaryMeta.textContent = 'Ongoing pickup · finding route coordinates...';
        destinationNavigationBtn.classList.add('hidden');
        completeDestinationBtn.classList.add('hidden');
    }
}

function distanceBetweenPoints(lat1, lng1, lat2, lng2) {
    const earthRadius = 6371;
    const radians = value => value * Math.PI / 180;
    const dLat = radians(lat2 - lat1);
    const dLng = radians(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2
        + Math.cos(radians(lat1)) * Math.cos(radians(lat2)) * Math.sin(dLng / 2) ** 2;
    return earthRadius * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function updateCompletionButton(destination) {
    const hasGps = Number.isFinite(currentLat) && Number.isFinite(currentLng);
    const hasDestination = Number.isFinite(destination.destinationLat) && Number.isFinite(destination.destinationLng);
    const distanceM = hasGps && hasDestination
        ? distanceBetweenPoints(currentLat, currentLng, destination.destinationLat, destination.destinationLng) * 1000
        : Infinity;
    const isNear = distanceM <= 50;

    completeDestinationBtn.classList.toggle('hidden', !trackingActive);
    completeDestinationBtn.disabled = !isNear;
    completeDestinationBtn.title = isNear
        ? 'Complete this arrival'
        : 'Move within 50 metres of the arrival location';
    completeDestinationBtn.textContent = isNear
        ? 'Complete Destination'
        : 'Move within 50m to Complete';
}

async function completeDestination() {
    if (!trackingActive || !lorryId || currentLat === null || currentLng === null) {
        updateStatus('error', 'Start tracking and wait for a GPS position first.');
        return;
    }
    if (!coordinatesFromOption()) {
        updateStatus('error', 'There is no active destination to complete.');
        return;
    }
    if (!confirm('Mark this destination as completed?')) return;

    completeDestinationBtn.disabled = true;
    completeDestinationBtn.textContent = 'Completing...';
    const formData = new FormData();
    formData.append('action', 'complete');
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('lorry_id', lorryId);
    formData.append('lat', currentLat);
    formData.append('lng', currentLng);
    formData.append('status', 'On Duty');
    const destination = coordinatesFromOption();
    if (destination.destinationName) formData.append('destination_name', destination.destinationName);

    try {
        const response = await fetch(API_URL, { method: 'POST', body: formData, credentials: 'same-origin' });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Could not complete destination.');
        addHistory(new Date().toLocaleTimeString('en-MY', { hour12: false }), 'Destination completed', true);
        const option = selectedLorryOption();
        option.dataset.destinationLat = '';
        option.dataset.destinationLng = '';
        option.dataset.ongoingLocation = '';
        routeKey = '';
        drawRoute(currentLat, currentLng);
        completeDestinationBtn.classList.add('hidden');
        stopTracking();
    } catch (error) {
        updateStatus('error', error.message);
        completeDestinationBtn.disabled = false;
        completeDestinationBtn.textContent = 'Complete Destination';
    }
}

completeDestinationBtn.addEventListener('click', completeDestination);

function fetchJsonWithTimeout(url, options = {}, timeoutMs = 8000) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    return fetch(url, { ...options, signal: controller.signal })
        .then(response => {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .finally(() => clearTimeout(timer));
}

function fetchRouteWithFallback(routePath) {
    const urls = [
        'https://router.project-osrm.org/route/v1/driving/' + routePath,
        'https://routing.openstreetmap.de/routed-car/route/v1/driving/' + routePath
    ];
    const tryRoute = index => {
        if (index >= urls.length) return Promise.reject(new Error('No routing service available'));
        return fetchJsonWithTimeout(urls[index], {}, 10000).catch(() => tryRoute(index + 1));
    };
    return tryRoute(0);
}

function findDestinationCoordinates(location) {
    const queries = [location + ', Malaysia', location];
    const tryQuery = index => {
        if (index >= queries.length) return Promise.reject(new Error('Location not found'));
        const nominatimUrl = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=my&q=' + encodeURIComponent(queries[index]);
        return fetchJsonWithTimeout(nominatimUrl, { headers: { Accept: 'application/json' } })
            .then(results => results[0] || Promise.reject(new Error('No result')))
            .catch(() => {
                const photonUrl = 'https://photon.komoot.io/api/?limit=1&q=' + encodeURIComponent(queries[index]);
                return fetchJsonWithTimeout(photonUrl, { headers: { Accept: 'application/json' } })
                    .then(data => data.features && data.features[0] ? {
                        lat: data.features[0].geometry.coordinates[1],
                        lon: data.features[0].geometry.coordinates[0]
                    } : Promise.reject(new Error('No result')));
            })
            .catch(() => tryQuery(index + 1));
    };
    return tryQuery(0);
}

function drawRoute(startLat, startLng) {
    const requestGeneration = ++routeRequestGeneration;
    const destination = coordinatesFromOption();
    updateDestinationSummary();
    if (!destination) {
        routePanel.textContent = 'No destination has been assigned to this lorry yet.';
        routeKey = '';
        if (routeLine) { driverMap.removeLayer(routeLine); routeLine = null; }
        if (destinationMarker) { driverMap.removeLayer(destinationMarker); destinationMarker = null; }
        return;
    }

    if (!Number.isFinite(destination.destinationLat) || !Number.isFinite(destination.destinationLng)) {
        routeKey = '';
        if (routeLine) { driverMap.removeLayer(routeLine); routeLine = null; }
        if (destinationMarker) { driverMap.removeLayer(destinationMarker); destinationMarker = null; }
        if (geocodeInFlight) return;
        geocodeInFlight = true;
        routePanel.textContent = 'Finding route to ' + destination.ongoingLocation + '...';
        findDestinationCoordinates(destination.ongoingLocation)
            .then(result => {
                const option = selectedLorryOption();
                option.dataset.destinationLat = result.lat;
                option.dataset.destinationLng = result.lon;
                option.dataset.destinationName = destination.ongoingLocation;
                routeKey = '';
                drawRoute(startLat, startLng);
            })
            .catch(() => {
                routePanel.textContent = 'Ongoing pickup: ' + destination.ongoingLocation + ' · location could not be mapped.';
            })
            .finally(() => { geocodeInFlight = false; });
        return;
    }

    const hasStart = Number.isFinite(startLat) && Number.isFinite(startLng);
    const start = hasStart ? [startLat, startLng] : [
        Number.parseFloat(selectedLorryOption().dataset.currentLat),
        Number.parseFloat(selectedLorryOption().dataset.currentLng)
    ];
    if (!Number.isFinite(start[0]) || !Number.isFinite(start[1])) {
        routePanel.textContent = 'Waiting for your current GPS position to calculate the route.';
        return;
    }

    // Match the admin fleet-map behavior: redraw when the current position
    // or assigned destination changes, while rounding GPS noise to avoid
    // unnecessary routing requests.
    const nextKey = [start[0].toFixed(4), start[1].toFixed(4), destination.destinationLat, destination.destinationLng].join('|');
    if (nextKey === routeKey) return;
    routeKey = nextKey;
    routePanel.textContent = 'Loading route to ' + destination.destinationName + '...';

    if (routeLine) { driverMap.removeLayer(routeLine); routeLine = null; }
    if (destinationMarker) driverMap.removeLayer(destinationMarker);
    const destinationIcon = L.divIcon({
        className: 'destination-flag-icon',
        html: '<span>⚑</span>',
        iconSize: [34, 34],
        iconAnchor: [8, 30]
    });
    destinationMarker = L.marker([destination.destinationLat, destination.destinationLng], { icon: destinationIcon })
        .addTo(driverMap)
        .bindTooltip('Destination', { permanent: false });

    // Show an immediate direction line while the detailed road route loads.
    routeLine = L.polyline([start, [destination.destinationLat, destination.destinationLng]], {
        color: '#2563eb', weight: 5, dashArray: '10 8', opacity: 0.75
    }).addTo(driverMap);
    driverMap.fitBounds(routeLine.getBounds(), { padding: [24, 24] });

    const routePath = start[1] + ',' + start[0] + ';' + destination.destinationLng + ',' + destination.destinationLat +
        '?overview=full&geometries=geojson';
    fetchRouteWithFallback(routePath)
        .then(data => {
            if (requestGeneration !== routeRequestGeneration) return;
            if (!data.routes || !data.routes[0]) throw new Error('No route');
            if (routeLine) { driverMap.removeLayer(routeLine); routeLine = null; }
            routeLine = L.geoJSON(data.routes[0].geometry, {
                style: { color: '#2563eb', weight: 6, opacity: 0.9 }
            }).addTo(driverMap);
            const distanceKm = (data.routes[0].distance / 1000).toFixed(1);
            const durationMin = Math.max(1, Math.round(data.routes[0].duration / 60));
            routePanel.textContent = 'Route to ' + destination.destinationName + ' · ' + distanceKm + ' km · about ' + durationMin + ' min';
            driverMap.fitBounds(routeLine.getBounds().extend([start, [destination.destinationLat, destination.destinationLng]]), { padding: [24, 24] });
        })
        .catch(() => {
            if (requestGeneration !== routeRequestGeneration) return;
            if (!routeLine) {
                routeLine = L.polyline([start, [destination.destinationLat, destination.destinationLng]], {
                    color: '#2563eb', weight: 5, dashArray: '10 8', opacity: 0.85
                }).addTo(driverMap);
            }
            routePanel.textContent = 'Route to ' + destination.destinationName + ' · road route unavailable, showing direction';
            driverMap.fitBounds(routeLine.getBounds(), { padding: [24, 24] });
        });
}

function resetTraveledPath() {
    traveledLoadGeneration++;
    traveledPoints = [];
    if (traveledLine) {
        driverMap.removeLayer(traveledLine);
        traveledLine = null;
    }
}

function loadTraveledPath() {
    const selectedId = lorrySelect.value;
    const loadGeneration = ++traveledLoadGeneration;
    if (!selectedId) return;
    fetch('api/gps_history.php?lorry_id=' + encodeURIComponent(selectedId) + '&limit=200', { credentials: 'same-origin' })
        .then(response => response.json())
        .then(data => {
            if (loadGeneration !== traveledLoadGeneration || lorrySelect.value !== selectedId || traveledPoints.length || !data.success) return;
            const points = (data.history || []).reverse()
                .map(item => [Number.parseFloat(item.latitude), Number.parseFloat(item.longitude)])
                .filter(point => Number.isFinite(point[0]) && Number.isFinite(point[1]));
            if (points.length < 2) return;
            traveledPoints = points;
            traveledLine = L.polyline(traveledPoints, {
                color: '#1d4ed8', weight: 5, opacity: 0.9,
                lineCap: 'round', lineJoin: 'round'
            }).addTo(driverMap);
        })
        .catch(() => {});
}

function appendTraveledPoint(lat, lng, accuracy) {
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
    // Ignore weak fixes and tiny GPS jitter so the breadcrumb follows the road.
    if (Number.isFinite(accuracy) && accuracy > 75) return;
    const lastPoint = traveledPoints[traveledPoints.length - 1];
    if (lastPoint && driverMap.distance(lastPoint, [lat, lng]) < 8) return;
    traveledPoints.push([lat, lng]);
    if (traveledPoints.length > 1000) traveledPoints.shift();
    if (!traveledLine) {
        traveledLine = L.polyline(traveledPoints, {
            color: '#1d4ed8', weight: 5, opacity: 0.9,
            lineCap: 'round', lineJoin: 'round'
        }).addTo(driverMap);
    } else {
        traveledLine.setLatLngs(traveledPoints);
    }
}

lorrySelect.addEventListener('change', () => {
    routeKey = '';
    resetTraveledPath();
    loadTraveledPath();
    updateDestinationSummary();
    const option = selectedLorryOption();
    if (!option || !option.value) {
        routePanel.textContent = 'Select your lorry to load its route.';
        return;
    }
    drawRoute(Number.parseFloat(option.dataset.currentLat), Number.parseFloat(option.dataset.currentLng));
});

if (lorrySelect.value) {
    const option = selectedLorryOption();
    updateDestinationSummary();
    loadTraveledPath();
    drawRoute(Number.parseFloat(option.dataset.currentLat), Number.parseFloat(option.dataset.currentLng));
}

function refreshLiveAssignment() {
    const option = selectedLorryOption();
    if (!option || !option.value) return;
    const savedDestinationLat = option.dataset.destinationLat || '';
    const savedDestinationLng = option.dataset.destinationLng || '';
    Promise.all([
        fetch('api/gps_poll.php', { credentials: 'same-origin' }).then(response => response.json()),
        fetch('api/arrival_queue.php?status=ongoing&limit=200', { credentials: 'same-origin' }).then(response => response.json())
    ]).then(([gps, queue]) => {
        const tracker = (gps.trackers || []).find(item => Number(item.lorry_id) === Number(option.value));
        if (tracker) {
            option.dataset.currentLat = tracker.current_lat || '';
            option.dataset.currentLng = tracker.current_long || '';
            option.dataset.destinationLat = tracker.destination_lat || savedDestinationLat;
            option.dataset.destinationLng = tracker.destination_long || savedDestinationLng;
        }
        const ongoing = (queue.data || []).find(item =>
            String(item.plate_number || '').trim().toLowerCase() === String(option.textContent).split(' - ')[0].trim().toLowerCase()
            || String(item.driver_name || '').trim().toLowerCase() === String(option.textContent).split(' - ')[1].trim().toLowerCase()
        );
        if (ongoing) {
            option.dataset.ongoingLocation = ongoing.location_name || ongoing.geolocation || 'Ongoing pickup';
            option.dataset.ongoingId = ongoing.id || '';
        } else {
            option.dataset.ongoingLocation = '';
            option.dataset.ongoingId = '';
            option.dataset.destinationLat = '';
            option.dataset.destinationLng = '';
        }
        routeKey = '';
        updateDestinationSummary();
        drawRoute(Number.parseFloat(option.dataset.currentLat), Number.parseFloat(option.dataset.currentLng));
    }).catch(() => {});
}

refreshLiveAssignment();
setInterval(refreshLiveAssignment, 15000);

fullscreenMapBtn.addEventListener('click', async () => {
    try {
        if (document.fullscreenElement || mapShell.classList.contains('map-expanded')) {
            if (document.fullscreenElement) await document.exitFullscreen();
            mapShell.classList.remove('map-expanded');
            fullscreenMapBtn.textContent = '⛶ Full screen';
        } else {
            await mapShell.requestFullscreen();
        }
    } catch (error) {
        mapShell.classList.toggle('map-expanded');
        fullscreenMapBtn.textContent = mapShell.classList.contains('map-expanded') ? '× Exit full screen' : '⛶ Full screen';
    }
    setTimeout(() => driverMap.invalidateSize(), 250);
});

document.addEventListener('fullscreenchange', () => {
    fullscreenMapBtn.textContent = document.fullscreenElement ? '× Exit full screen' : '⛶ Full screen';
    setTimeout(() => driverMap.invalidateSize(), 250);
});

driverMap.on('dragstart', () => {
    followMode = false;
    locateMapBtn.classList.add('locate-muted');
});

locateMapBtn.addEventListener('click', () => {
    followMode = true;
    locateMapBtn.classList.remove('locate-muted');
    if (currentLat !== null && currentLng !== null) {
        driverMap.flyTo([currentLat, currentLng], 16, { duration: 0.7 });
    } else {
        routePanel.textContent = 'Waiting for your GPS position...';
    }
});

async function startTracking() {
    if (trackingActive) return;

    // Wait for the previous stop request before sending On Duty again.
    // This prevents Available and On Duty updates from arriving out of order.
    if (statusRequest) {
        btnStart.disabled = true;
        await statusRequest;
        statusRequest = null;
        if (trackingActive) return;
        btnStart.disabled = false;
    }

    lorryId = parseInt(lorrySelect.value, 10);
    if (!lorryId) {
        alert('Please select a lorry first.');
        return;
    }

    if (!navigator.geolocation) {
        alert('Geolocation is not supported by your browser.');
        return;
    }

    trackingActive = true;
    followMode = true;
    locateMapBtn.classList.remove('locate-muted');
    updateDestinationSummary();
    routeKey = '';
    trackingGeneration++;
    lorrySelect.disabled = true;
    btnStart.disabled = true;
    btnStart.textContent = 'Requesting GPS...';
    updateStatus('active', 'Requesting location...');

    watchId = navigator.geolocation.watchPosition(
        onPositionSuccess,
        onPositionError,
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 5000 }
    );
}

function onPositionSuccess(position) {
    if (!trackingActive) return;

    currentLat = position.coords.latitude;
    currentLng = position.coords.longitude;
    const accuracy = position.coords.accuracy;

    appendTraveledPoint(currentLat, currentLng, accuracy);
    const destination = coordinatesFromOption();
    if (destination && Number.isFinite(destination.destinationLat) && Number.isFinite(destination.destinationLng)) {
        updateCompletionButton(destination);
    }

    coordText.textContent = currentLat.toFixed(6) + '\u00b0N, ' + currentLng.toFixed(6) + '\u00b0E';
    accuracyText.textContent = 'Accuracy: \u00b1' + Math.round(accuracy) + 'm';

    if (driverMarker) {
        driverMarker.setLatLng([currentLat, currentLng]);
    } else {
        const currentLocationIcon = L.divIcon({
            className: 'driver-location-icon',
            html: '<span></span>',
            iconSize: [26, 26],
            iconAnchor: [13, 13]
        });
        driverMarker = L.marker([currentLat, currentLng], { icon: currentLocationIcon }).addTo(driverMap);
    }
    drawRoute(currentLat, currentLng);

    if (!trackingInterval) {
        btnStart.classList.add('hidden');
        btnStop.classList.remove('hidden');
        updateStatus('active', 'Tracking - sending every 5s');
        sendPosition(trackingGeneration);
        trackingInterval = setInterval(() => sendPosition(trackingGeneration), SEND_INTERVAL);
    }
}

function onPositionError(error) {
    let msg = '';
    switch (error.code) {
        case error.PERMISSION_DENIED:
            msg = 'Location permission denied. Please allow location access.';
            break;
        case error.POSITION_UNAVAILABLE:
            msg = 'Location unavailable. Try moving to an open area.';
            break;
        case error.TIMEOUT:
            msg = 'GPS timeout. Retrying...';
            break;
        default:
            msg = 'Unknown GPS error (' + error.code + ')';
    }
    updateStatus('error', msg);

    if (error.code === error.PERMISSION_DENIED) {
        stopTracking();
    } else {
        btnStart.disabled = true;
        btnStart.textContent = 'Retrying GPS...';
    }
}

function sendPosition(sessionId) {
    if (!trackingActive || sessionId !== trackingGeneration || currentLat === null || currentLng === null || !lorryId || positionRequestInFlight) return;

    positionRequestInFlight = true;
    activeRequestController = new AbortController();
    const sentLat = currentLat;
    const sentLng = currentLng;
    const sentLorryId = lorryId;

    const formData = new FormData();
    formData.append('action', 'update');
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('lorry_id', sentLorryId);
    formData.append('lat', sentLat);
    formData.append('lng', sentLng);
    formData.append('status', 'On Duty');
    const activeDestination = coordinatesFromOption();
    if (activeDestination && activeDestination.destinationName) {
        formData.append('destination_name', activeDestination.destinationName);
    }

    fetch(API_URL, {
        method: 'POST',
        body: formData,
        signal: activeRequestController.signal,
        credentials: 'same-origin'
    })
    .then(response => {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
    })
    .then(data => {
        if (!trackingActive || sessionId !== trackingGeneration) return;

        if (data.success) {
            const gpsData = data.data || data;
            updateCount++;
            updateCountEl.textContent = updateCount;
            const timeStr = new Date().toLocaleTimeString('en-MY', { hour12: false });
            addHistory(timeStr, sentLat.toFixed(4) + ', ' + sentLng.toFixed(4), true);

            if (gpsData.speed_kmh !== null && gpsData.speed_kmh !== undefined) {
                speedText.textContent = gpsData.speed_kmh + ' km/h';
            }
            if (gpsData.heading !== null && gpsData.heading !== undefined) {
                const dirs = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
                const normalizedHeading = ((Number(gpsData.heading) % 360) + 360) % 360;
                const dir = dirs[Math.round(normalizedHeading / 45) % 8];
                headingText.textContent = dir + ' (' + Math.round(normalizedHeading) + '\u00b0)';
            }
            if (gpsData.assigned_destination) {
                addHistory(timeStr, 'Assigned: ' + (gpsData.assigned_destination.location_name || 'New destination'), true);
            }
            if (gpsData.arrival_completed) {
                addHistory(timeStr, 'Destination completed', true);
                const option = selectedLorryOption();
                if (option && Number(option.value) === sentLorryId) {
                    option.dataset.destinationLat = '';
                    option.dataset.destinationLng = '';
                    option.dataset.ongoingLocation = '';
                    routeKey = '';
                    drawRoute(sentLat, sentLng);
                }
            }
        } else {
            addHistory('now', 'Error: ' + (data.message || 'Unknown error'), false);
            updateStatus('error', data.message || 'Update failed');
        }
    })
    .catch(error => {
        if (error.name !== 'AbortError' && trackingActive && sessionId === trackingGeneration) {
            addHistory('now', 'Network error', false);
            updateStatus('error', 'Network error. Retrying...');
        }
    })
    .finally(() => {
        positionRequestInFlight = false;
        activeRequestController = null;
    });
}

function stopTracking() {
    const stopLorryId = lorryId;
    const stopLat = currentLat;
    const stopLng = currentLng;

    trackingActive = false;
    trackingGeneration++;
    if (activeRequestController) activeRequestController.abort();

    if (watchId !== null) {
        navigator.geolocation.clearWatch(watchId);
        watchId = null;
    }
    if (trackingInterval) {
        clearInterval(trackingInterval);
        trackingInterval = null;
    }

    btnStart.classList.remove('hidden');
    btnStop.classList.add('hidden');
    completeDestinationBtn.classList.add('hidden');
    btnStart.disabled = false;
    btnStart.textContent = 'Start Tracking';
    lorrySelect.disabled = false;
    updateDestinationSummary();
    updateStatus('inactive', 'Not tracking');

    if (stopLorryId) {
        const formData = new FormData();
        formData.append('action', 'update');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('lorry_id', stopLorryId);
        if (stopLat !== null && stopLng !== null) {
            formData.append('lat', stopLat);
            formData.append('lng', stopLng);
        }
        formData.append('status', 'Available');
        formData.append('preserve_destination', '1');

        statusRequest = fetch(API_URL, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        }).catch(() => {}).finally(() => {
            statusRequest = null;
        });
    }

    currentLat = null;
    currentLng = null;
    speedText.textContent = '-';
    headingText.textContent = '-';
}

function updateStatus(type, text) {
    const dotClass = type === 'active' ? 'active' : (type === 'error' ? 'error' : 'inactive');
    const badge = document.createElement('span');
    badge.className = 'status-badge ' + type;
    const dot = document.createElement('span');
    dot.className = 'status-dot ' + dotClass;
    badge.append(dot, document.createTextNode(' ' + text));
    statusBar.replaceChildren(badge);
}

function addHistory(time, coord, success) {
    if (!historyList) return;
    const icon = success ? 'OK' : 'FAIL';
    if (historyList.children.length === 1 && historyList.children[0].textContent.trim() === 'No updates yet') {
        historyList.replaceChildren();
    }

    const li = document.createElement('li');
    const timeElement = document.createElement('span');
    timeElement.className = 'time';
    timeElement.textContent = icon + ' ' + time;
    const coordElement = document.createElement('span');
    coordElement.className = 'coord';
    coordElement.textContent = coord;
    li.append(timeElement, coordElement);
    historyList.insertBefore(li, historyList.firstChild);
    updateHistory.push({ time, coord, success });
}
</script>

</body>
</html>
