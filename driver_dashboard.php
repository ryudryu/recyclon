<?php
header('Cache-Control: no-store, no-cache, must-revalidate');
$page = basename(__FILE__);
require_once __DIR__ . '/config/db.php';

$sessionEmail = trim((string)($_SESSION['email'] ?? ''));
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
$roleStmt = $conn->prepare('SELECT user_id, name, email, role, status FROM users WHERE email = ? OR user_id = ? ORDER BY (email = ?) DESC LIMIT 1');
$roleStmt->execute([$sessionEmail, $sessionUserId, $sessionEmail]);
$currentAccount = $roleStmt->fetch(PDO::FETCH_ASSOC);
if ($currentAccount) {
    $_SESSION['user_id'] = (int)$currentAccount['user_id'];
    $_SESSION['name'] = $currentAccount['name'];
    $_SESSION['email'] = $currentAccount['email'];
    $_SESSION['role'] = $currentAccount['role'];
}

if (!$currentAccount || strtolower(trim((string)$currentAccount['status'])) !== 'active' || strtolower(trim((string)$currentAccount['role'])) !== 'driver') {
    header('Location: dashboard_staff.php');
    exit;
}

// Keep navigation and subsequent API calls consistent even if the database
// contains a different capitalization or accidental whitespace.
$_SESSION['role'] = 'Driver';

include 'header.php';

$driverId = (int)($_SESSION['user_id'] ?? 0);
$driverName = (string)($_SESSION['name'] ?? 'Driver');
$autoAcceptBookings = false;
try {
    $autoAcceptColumn = in_array('auto_accept_bookings', app_table_columns($conn, 'users'), true);
    if ($autoAcceptColumn) {
        $autoAcceptStmt = $conn->prepare("SELECT COALESCE(auto_accept_bookings, false) FROM users WHERE user_id=? LIMIT 1");
        $autoAcceptStmt->execute([$driverId]);
        $autoAcceptBookings = (bool)$autoAcceptStmt->fetchColumn();
    }
} catch (PDOException $e) {
    $autoAcceptBookings = false;
}
$csrfToken = (string)($_SESSION['csrf_token'] ?? '');
if ($csrfToken === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $csrfToken = $_SESSION['csrf_token'];
}
$lorry = null;
$queue = [];
try {
    $stmt = $conn->prepare("SELECT l.lorry_id, l.plate_number, l.status, l.current_lat, l.current_long,
            l.last_updated, l.destination_lat, l.destination_long, l.destination_set_at
            FROM lorries l WHERE l.driver_id = ? LIMIT 1");
    $stmt->execute([$driverId]);
    $lorry = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Do not mutate the database while rendering a GET request. The GPS API
    // owns lorry status changes; this only keeps the displayed state useful.
    if ($lorry && strtolower((string)($lorry['status'] ?? '')) === 'on duty') {
        $lastGpsUpdate = !empty($lorry['last_updated']) ? strtotime((string)$lorry['last_updated']) : false;
        if ($lastGpsUpdate === false || (time() - $lastGpsUpdate) > 30) {
            $lorry['status'] = 'Available';
        }
    }

} catch (PDOException $e) {
    $lorry = null;
}
if ($lorry) {
    try {
        $queueColumns = app_table_columns($conn, 'arrival_queue');
        $ownership = [];
        $queueParams = [];
        if (in_array('assigned_lorry_id', $queueColumns, true)) {
            // The lorry is the stable queue owner; the driver assigned to it
            // may change over time.
            $ownership[] = 'q.assigned_lorry_id = ?';
            $queueParams[] = (int)$lorry['lorry_id'];
        } elseif (in_array('driver_id', $queueColumns, true)) {
            $ownership[] = 'q.driver_id = ?';
            $queueParams[] = $driverId;
        } elseif (in_array('driver_name', $queueColumns, true)) {
            if (in_array('plate_number', $queueColumns, true)) {
                $ownership[] = '(q.driver_name = ? AND q.plate_number = ?)';
                $queueParams[] = $driverName;
                $queueParams[] = $lorry['plate_number'];
            } else {
                $ownership[] = 'q.driver_name = ?';
                $queueParams[] = $driverName;
            }
        } elseif (in_array('plate_number', $queueColumns, true)) {
            $ownership[] = 'q.plate_number = ?';
            $queueParams[] = $lorry['plate_number'];
        }

        // If the queue has no ownership field, fail closed instead of showing
        // every driver's job to the logged-in driver.
        $ownershipSql = $ownership ? '(' . implode(' OR ', $ownership) . ')' : '1 = 0';
        $q = $conn->prepare("SELECT q.* FROM arrival_queue q
            WHERE q.status IN ('ongoing', 'pending') AND $ownershipSql
            ORDER BY CASE WHEN q.status = 'ongoing' THEN 0 ELSE 1 END, q.id ASC");
        $q->execute($queueParams);
        $queue = $q->fetchAll(PDO::FETCH_ASSOC);

        // Only one stop may be active for a lorry. Older data can contain
        // several ongoing rows; keep the oldest active row and return the
        // remaining rows to the waiting queue.
        $activeStopFound = false;
        foreach ($queue as &$queueItem) {
            if (strtolower((string)($queueItem['status'] ?? '')) !== 'ongoing') continue;
            if (!$activeStopFound) {
                $activeStopFound = true;
                continue;
            }
            $queueItem['status'] = 'pending';
        }
        unset($queueItem);

        // The legacy queue can contain duplicate active rows for the same
        // destination. Show one route per destination to the driver.
        $uniqueQueue = [];
        $seenQueueKeys = [];
        foreach ($queue as $queueItem) {
            $queueLocation = strtolower(trim((string)($queueItem['location_name'] ?? $queueItem['geolocation'] ?? '')));
            $queueLat = (string)($queueItem['latitude'] ?? '');
            $queueLng = (string)($queueItem['longitude'] ?? '');
            $queueKey = strtolower((string)($queueItem['status'] ?? '')) . '|' . $queueLocation . '|' . $queueLat . '|' . $queueLng;
            if (isset($seenQueueKeys[$queueKey])) continue;
            $seenQueueKeys[$queueKey] = true;
            $uniqueQueue[] = $queueItem;
        }
        $queue = $uniqueQueue;
    } catch (PDOException $e) {
        $queue = [];
    }
}
$currentQueueItem = null;
foreach ($queue as $queueItem) {
    if (strtolower((string)($queueItem['status'] ?? '')) === 'ongoing') {
        $currentQueueItem = $queueItem;
        break;
    }
}
?>
<link rel="stylesheet" href="assets/css/gps_tracker.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-rotate@0.2.8/dist/leaflet-rotate-src.js"></script>
<style>
    .driver-route-preview { overflow: hidden; border-radius: 18px; position: relative; z-index: 0; }
    .driver-route-shell { position: relative; z-index: 0; }
    #driverRouteMap { height: min(58vh, 520px); min-height: 320px; width: 100%; }
    .driver-map-fullscreen { position: absolute; z-index: 1000; top: .75rem; right: .75rem; border: 0; border-radius: 9px; padding: .65rem .8rem; background: rgba(255,255,255,.95); color: #0c3a78; font-weight: 800; box-shadow: 0 2px 8px rgba(15,23,42,.2); cursor: pointer; }
    .driver-follow-badge { position: absolute; z-index: 1000; left: .75rem; top: .75rem; border-radius: 999px; padding: .55rem .7rem; background: rgba(255,255,255,.95); color: #1d4ed8; font-size: .72rem; font-weight: 800; box-shadow: 0 2px 8px rgba(15,23,42,.2); }
    .driver-heading-icon { width: 30px !important; height: 30px !important; margin-left: -15px !important; margin-top: -15px !important; }
    .driver-heading-icon span { display: block; width: 0; height: 0; margin: 2px auto; border-left: 10px solid transparent; border-right: 10px solid transparent; border-bottom: 24px solid #1a73e8; filter: drop-shadow(0 1px 2px rgba(15,23,42,.45)); transform-origin: 50% 50%; }
    .driver-route-shell:fullscreen, .driver-map-expanded { width: 100vw; height: 100vh; padding: .75rem; background: #eef4ff; }
    .driver-route-shell:fullscreen #driverRouteMap, .driver-map-expanded #driverRouteMap { height: calc(100vh - 1.5rem); min-height: 0; }
    .driver-route-status { padding: .85rem 1rem; background: #eff6ff; color: #1e40af; font-weight: 700; font-size: .85rem; }
    .driver-route-actions { display: flex; gap: .75rem; flex-wrap: wrap; align-items: center; }
    .driver-route-actions a { text-decoration: none; }
    .driver-refresh-note { font-size: .72rem; color: #64748b; font-weight: 600; }
    .driver-auto-accept-button { min-height: 44px; border: 1px solid #cbd5e1; border-radius: 10px; padding: .65rem .9rem; background: #fff; color: #334155; font-size: .82rem; font-weight: 800; transition: background-color .2s ease, border-color .2s ease, color .2s ease; }
    .driver-auto-accept-button.is-enabled { border-color: #15803d; background: #15803d; color: #fff; }
    .driver-auto-accept-button:disabled { cursor: wait; opacity: .65; }
    @media (max-width: 600px) {
        .content-panel { padding: 1rem !important; }
        .page-title h1 { font-size: 1.65rem; }
        #driverRouteMap { height: min(52vh, 400px); min-height: 280px; }
        .driver-route-preview .card-header,
        .driver-route-preview .driver-route-actions { padding-left: 1rem !important; padding-right: 1rem !important; }
        .driver-route-actions { display: grid; grid-template-columns: 1fr; }
        .driver-route-actions .btn { width: 100%; min-height: 48px; }
        .driver-queue-table thead { display: none; }
        .driver-queue-table,
        .driver-queue-table tbody,
        .driver-queue-table tr,
        .driver-queue-table td { display: block; width: 100%; }
        .driver-queue-table tr { margin: .75rem; padding: .75rem; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 3px 12px rgba(15,23,42,.05); }
        .driver-queue-table td { display: flex; justify-content: space-between; align-items: flex-start; gap: .75rem; padding: .45rem 0 !important; border: 0; text-align: right; white-space: normal; }
        .driver-queue-table td::before { content: attr(data-label); color: #64748b; font-size: .7rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; text-align: left; flex: 0 0 35%; }
        .driver-queue-table td:first-child { display: block; text-align: left; padding-top: 0 !important; }
        .driver-queue-table td:first-child::before { display: none; }
        .driver-queue-table td:last-child { display: block; text-align: left; padding-top: .7rem !important; margin-top: .35rem; border-top: 1px solid #e2e8f0; }
        .driver-queue-table td:last-child::before { display: none; }
        .driver-queue-table td:last-child .btn { width: 100%; min-height: 44px; }
    }
</style>
<div class="page-body">
    <section class="content-panel">
        <div class="page-title mb-4">
            <h1 class="display-6 fw-bold">Driver Dashboard</h1>
            <p class="text-secondary mb-0">Welcome, <?php echo htmlspecialchars($driverName); ?>. Your assigned work and GPS status.</p>
        </div>

        <?php if (!$lorry): ?>
            <div class="alert alert-warning">No lorry has been assigned to your account yet. Please contact an administrator.</div>
        <?php else: ?>
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body p-4" id="driverDestinationCard">
                        <div class="text-secondary small fw-bold text-uppercase mb-2">Assigned Lorry</div>
                        <h2 class="h3 fw-bold mb-2"><?php echo htmlspecialchars($lorry['plate_number']); ?></h2>
                <div class="text-secondary">Status: <strong id="assignedLorryStatus"><?php echo htmlspecialchars($lorry['status'] ?? 'Unknown'); ?></strong></div>
                <button type="button" class="driver-auto-accept-button mt-2 <?php echo $autoAcceptBookings ? 'is-enabled' : ''; ?>" id="autoAcceptBookings" data-enabled="<?php echo $autoAcceptBookings ? '1' : '0'; ?>" aria-pressed="<?php echo $autoAcceptBookings ? 'true' : 'false'; ?>">
                    Auto-accept: <?php echo $autoAcceptBookings ? 'On' : 'Off'; ?>
                </button>
                        <div class="text-secondary small mt-2">Last update: <span id="assignedLorryLastUpdate"><?php echo htmlspecialchars($lorry['last_updated'] ?? 'Not available'); ?></span></div>
                    </div></div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body p-4">
                        <div class="text-secondary small fw-bold text-uppercase mb-2">Current Destination</div>
                        <?php if ($currentQueueItem): ?>
                            <h2 class="h5 fw-bold mb-2"><?php echo htmlspecialchars($currentQueueItem['location_name'] ?? $currentQueueItem['geolocation'] ?? 'GPS coordinates assigned'); ?></h2>
                            <div class="text-secondary small">Status: <strong>Ongoing</strong></div>
                            <?php if ($lorry['destination_lat'] !== null && $lorry['destination_long'] !== null): ?>
                                <div class="text-secondary small">Coordinates: <?php echo number_format((float)$lorry['destination_lat'], 6) . ', ' . number_format((float)$lorry['destination_long'], 6); ?></div>
                            <?php endif; ?>
                            <div class="text-secondary small mt-2">Your route is ready. Use the Start Tracking button below to begin.</div>
                        <?php elseif (!empty($queue[0])): ?>
                            <h2 class="h5 fw-bold mb-2"><?php echo htmlspecialchars($queue[0]['location_name'] ?? $queue[0]['geolocation'] ?? 'Queued GPS destination'); ?></h2>
                            <div class="text-secondary small">Status: <strong>Pending</strong></div>
                            <div class="text-secondary small mt-2">This stop will become active after the current route is completed.</div>
                        <?php else: ?>
                            <?php if ($lorry['destination_lat'] !== null && $lorry['destination_long'] !== null): ?>
                                <h2 class="h5 fw-bold mb-2">Assigned GPS destination</h2>
                                <div class="text-secondary small">Coordinates: <?php echo htmlspecialchars((string)$lorry['destination_lat'] . ', ' . (string)$lorry['destination_long']); ?></div>
                                <div class="text-secondary small mt-2">Your route is ready. Use the Start Tracking button below to begin.</div>
                            <?php else: ?>
                                <h2 class="h5 fw-bold mb-2">No active destination</h2>
                                <div class="text-secondary small">Wait for an administrator to assign your next destination.</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div></div>
                </div>
            </div>
            <div class="card border-0 shadow-sm driver-route-preview mb-4">
                <div class="card-header border-0 d-flex align-items-center justify-content-between py-3 px-4" style="background:var(--nav-bg); border-radius:18px 18px 0 0 !important;">
                    <h2 class="h6 mb-0 fw-bold text-white">My Assigned Route</h2>
                    <span class="badge rounded-pill bg-danger">Destination</span>
                </div>
                <div class="driver-route-shell" id="driverRouteShell">
                    <div id="driverRouteMap"></div>
                    <span class="driver-follow-badge" id="driverFollowBadge">Follow mode</span>
                    <button type="button" class="driver-map-fullscreen" id="driverMapFullscreen">⛶ Full screen</button>
                </div>
                <div class="driver-route-status" id="driverRouteStatus">Loading your assigned route...</div>
                <div class="driver-route-actions p-3">
                    <button type="button" class="btn btn-success fw-bold" id="dashboardStartTracking">Start Tracking</button>
                    <button type="button" class="btn btn-danger fw-bold d-none" id="dashboardStopTracking">Stop Tracking</button>
                    <button type="button" class="btn btn-primary fw-bold d-none" id="dashboardCompleteDestination" disabled>Move within 50m to Complete</button>
                </div>
            </div>
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header border-0 d-flex align-items-center justify-content-between py-3 px-4" style="background:var(--nav-bg); border-radius:18px 18px 0 0 !important;">
                    <h2 class="h6 mb-0 fw-bold text-white">My Queue</h2>
                    <span class="badge rounded-pill bg-light text-dark" id="driverQueueCount"><?php echo count($queue); ?></span>
                </div>
                <div class="card-body p-0" id="driverQueueBody">
                    <?php if (empty($queue)): ?>
                        <div class="text-center text-secondary py-4">No queue items assigned to you.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 driver-queue-table">
                                <thead><tr><th class="ps-4">Location</th><th>Coordinates</th><th>Created</th><th>Status</th><th class="pe-4">Action</th></tr></thead>
                                <tbody>
                                <?php foreach ($queue as $item): ?>
                                    <?php
                                        $location = $item['location_name'] ?? $item['geolocation'] ?? 'GPS destination';
                                        $isCurrentItem = strtolower((string)($item['status'] ?? '')) === 'ongoing';
                                        $itemLat = $item['latitude'] ?? ($isCurrentItem ? $lorry['destination_lat'] : null);
                                        $itemLng = $item['longitude'] ?? ($isCurrentItem ? $lorry['destination_long'] : null);
                                        $coordinates = ($itemLat !== null && $itemLng !== null)
                                            ? number_format((float)$itemLat, 6) . ', ' . number_format((float)$itemLng, 6)
                                            : 'Coordinates pending';
                                    ?>
                                    <tr>
                                        <td class="ps-4 fw-semibold queue-location-cell" data-label="Location"
                                            data-lat="<?php echo $itemLat !== null ? htmlspecialchars((string)$itemLat, ENT_QUOTES, 'UTF-8') : ''; ?>"
                                            data-lng="<?php echo $itemLng !== null ? htmlspecialchars((string)$itemLng, ENT_QUOTES, 'UTF-8') : ''; ?>">
                                            <?php echo htmlspecialchars($location); ?>
                                        </td>
                                        <td class="text-secondary small" data-label="Coordinates"><?php echo htmlspecialchars($coordinates); ?></td>
                                        <td class="text-secondary small"><?php echo htmlspecialchars($item['created_at'] ?? '—'); ?></td>
                                        <?php $queueStatus = strtolower((string)($item['status'] ?? 'ongoing')); ?>
                                        <td data-label="Status"><span class="status-pill <?php echo $queueStatus === 'pending' ? 'pending' : 'ongoing'; ?>"><span class="status-dot"></span><?php echo ucfirst($queueStatus); ?></span></td>
                                        <td class="pe-4 text-nowrap" data-label="Action">
                                            <?php if ($queueStatus === 'pending' && !$currentQueueItem): ?>
                                                <button type="button" class="btn btn-sm btn-success driver-accept-queue" data-queue-id="<?php echo (int)($item['id'] ?? 0); ?>" data-location="<?php echo htmlspecialchars($location, ENT_QUOTES, 'UTF-8'); ?>" <?php echo !$currentQueueItem ? 'data-auto-start="1"' : ''; ?>>Start job</button>
                                            <?php elseif ($queueStatus === 'pending'): ?>
                                                <span class="small text-secondary">After current stop</span>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-primary driver-complete-queue" disabled>Move within 50m to Complete</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card border-0 shadow-sm"><div class="card-body p-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
                <div><h2 class="h5 fw-bold mb-1">Ready to follow your route?</h2><p class="text-secondary mb-0">Use the Start Tracking button above to send your live location every 5 seconds.</p></div>
            </div></div>
        <?php endif; ?>
    </section>
</div>
<script>
(() => {
    const routeMapElement = document.getElementById('driverRouteMap');
    const routeStatus = document.getElementById('driverRouteStatus');
    const routeShell = document.getElementById('driverRouteShell');
    const fullscreenButton = document.getElementById('driverMapFullscreen');
    const startButton = document.getElementById('dashboardStartTracking');
    const stopButton = document.getElementById('dashboardStopTracking');
    const completeButton = document.getElementById('dashboardCompleteDestination');
    const followBadge = document.getElementById('driverFollowBadge');
    if (!routeMapElement || typeof L === 'undefined') return;

    let currentLat = <?php echo $lorry && $lorry['current_lat'] !== null ? json_encode((float)$lorry['current_lat']) : 'null'; ?>;
    let currentLng = <?php echo $lorry && $lorry['current_long'] !== null ? json_encode((float)$lorry['current_long']) : 'null'; ?>;
    window.recyclonDriverPosition = { lat: currentLat, lng: currentLng };
    let destinationLat = <?php echo $lorry && $lorry['destination_lat'] !== null ? json_encode((float)$lorry['destination_lat']) : 'null'; ?>;
    let destinationLng = <?php echo $lorry && $lorry['destination_long'] !== null ? json_encode((float)$lorry['destination_long']) : 'null'; ?>;
    let destinationName = <?php echo json_encode($currentQueueItem ? ($currentQueueItem['location_name'] ?? $currentQueueItem['geolocation'] ?? 'Assigned destination') : 'Assigned destination'); ?>;
    const csrfToken = <?php echo json_encode($csrfToken); ?>;
    const lorryId = <?php echo $lorry ? (int)$lorry['lorry_id'] : 0; ?>;
    const trackingStorageKey = 'recyclon.driverTracking.' + lorryId;
    const serverTrackingActive = <?php echo $lorry &&
        strtolower(trim((string)$lorry['status'])) === 'on duty' &&
        !empty($lorry['last_updated']) &&
        (time() - strtotime((string)$lorry['last_updated'])) <= 30 ? 'true' : 'false'; ?>;
    const hasInitialDestination = Number.isFinite(destinationLat) && Number.isFinite(destinationLng);
    const shouldResumeTracking = hasInitialDestination && (serverTrackingActive || localStorage.getItem(trackingStorageKey) === '1');
    let trackingActive = false;
    window.recyclonDriverTrackingActive = false;
    let watchId = null;
    let sendTimer = null;
    let statusRequest = null;
    let latestLat = currentLat;
    let latestLng = currentLng;
    let currentMarker = null;
    let followMode = true;
    let driverFollowZoomed = false;
    let lastHeading = null;
    let hasDestination = hasInitialDestination;
    let routeInitialized = false;
    let destinationMarker = null;
    let routeLine = null;

    const map = L.map(routeMapElement, { zoomControl: true, rotate: true, bearing: 0 }).setView(
        currentLat !== null && currentLng !== null ? [currentLat, currentLng] : [6.1244, 100.3670],
        13
    );
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxNativeZoom: 19, maxZoom: 21, attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    fullscreenButton.addEventListener('click', async () => {
        try {
            if (document.fullscreenElement) await document.exitFullscreen();
            else await routeShell.requestFullscreen();
        } catch (error) {
            routeShell.classList.toggle('driver-map-expanded');
        }
        setTimeout(() => map.invalidateSize(), 250);
    });

    document.addEventListener('fullscreenchange', () => {
        fullscreenButton.textContent = document.fullscreenElement ? '× Exit full screen' : '⛶ Full screen';
        setTimeout(() => map.invalidateSize(), 250);
    });

    map.on('dragstart', () => {
        followMode = false;
        followBadge.textContent = 'Follow paused · tap Start Tracking to resume';
        followBadge.style.color = '#64748b';
    });

    function driverIcon(heading) {
        const icon = L.divIcon({ className: 'driver-heading-icon', html: '<span></span>', iconSize: [30, 30], iconAnchor: [15, 15] });
        if (Number.isFinite(heading)) icon.html = '<span style="transform:rotate(' + heading + 'deg)"></span>';
        return icon;
    }

    function updateHeading(heading) {
        if (!Number.isFinite(heading)) return;
        lastHeading = heading;
        if (currentMarker && currentMarker.setIcon) currentMarker.setIcon(driverIcon(heading));
        if (typeof map.setBearing === 'function' && followMode) {
            const currentBearing = typeof map.getBearing === 'function' ? map.getBearing() : 0;
            let delta = ((heading - currentBearing + 540) % 360) - 180;
            map.setBearing(currentBearing + delta * 0.18);
        }
    }

    function updateDashboardPosition(position) {
        currentLat = position.coords.latitude;
        currentLng = position.coords.longitude;
        latestLat = position.coords.latitude;
        latestLng = position.coords.longitude;
        const heading = Number(position.coords.heading);
        if (currentMarker) currentMarker.setLatLng([latestLat, latestLng]);
        else currentMarker = L.marker([latestLat, latestLng], { icon: driverIcon(heading) }).addTo(map).bindTooltip('Your current location');
        updateHeading(heading);
        updateCompletionButton();
        if (!routeInitialized && hasDestination) initializeRoute();
        if (trackingActive && followMode) {
            if (!driverFollowZoomed) {
                driverFollowZoomed = true;
                map.flyTo([latestLat, latestLng], 18, { animate: true, duration: .65 });
            } else {
                map.panTo([latestLat, latestLng], { animate: true, duration: .45 });
            }
        }
    }

    window.addEventListener('recyclon:driver-dashboard-update', event => {
        const update = event.detail || {};
        if (trackingActive || !Number.isFinite(Number(update.current_lat)) || !Number.isFinite(Number(update.current_long))) return;
        currentLat = Number(update.current_lat);
        currentLng = Number(update.current_long);
        window.recyclonDriverPosition = { lat: currentLat, lng: currentLng };
        if (currentMarker) currentMarker.setLatLng([currentLat, currentLng]);
        else currentMarker = L.marker([currentLat, currentLng], { icon: driverIcon(null) }).addTo(map).bindTooltip('Your current location');
    });

    function sendDashboardPosition(status) {
        if (!lorryId || latestLat === null || latestLng === null) return;
        if (status === 'On Duty' && !(Number.isFinite(destinationLat) && Number.isFinite(destinationLng))) {
            return Promise.resolve();
        }
        const body = new FormData();
        body.append('action', 'update');
        body.append('csrf_token', csrfToken);
        body.append('lorry_id', lorryId);
        body.append('lat', latestLat);
        body.append('lng', latestLng);
        body.append('status', status);
        if (status === 'On Duty' && Number.isFinite(destinationLat) && Number.isFinite(destinationLng)) {
            body.append('destination_lat', destinationLat);
            body.append('destination_long', destinationLng);
            body.append('destination_name', destinationName);
        }
        if (status === 'Available') body.append('preserve_destination', '1');
        return fetch('api/gps_api.php', { method: 'POST', body, credentials: 'same-origin' })
            .then(response => response.json())
            .then(result => {
                const gpsData = result && result.data ? result.data : result;
                const statusElement = document.getElementById('assignedLorryStatus');
                if (statusElement && gpsData && gpsData.current_status) {
                    statusElement.textContent = gpsData.current_status;
                }
                if (status === 'On Duty' && result && result.success && gpsData &&
                    gpsData.destination_active === false) {
                    stopDashboardTracking('No destination assigned');
                }
            })
            .catch(() => {});
    }

    function distanceBetweenPoints(lat1, lng1, lat2, lng2) {
        const radians = value => value * Math.PI / 180;
        const dLat = radians(lat2 - lat1);
        const dLng = radians(lng2 - lng1);
        const a = Math.sin(dLat / 2) ** 2
            + Math.cos(radians(lat1)) * Math.cos(radians(lat2)) * Math.sin(dLng / 2) ** 2;
        return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function updateCompletionButton() {
        if (!completeButton) return;
        const hasGps = Number.isFinite(latestLat) && Number.isFinite(latestLng);
        const hasTarget = Number.isFinite(destinationLat) && Number.isFinite(destinationLng);
        const distanceM = hasGps && hasTarget
            ? distanceBetweenPoints(latestLat, latestLng, destinationLat, destinationLng) * 1000
            : Infinity;
        const isNear = distanceM <= 50;
        completeButton.classList.toggle('d-none', !trackingActive || !hasTarget);
        completeButton.disabled = !isNear;
        completeButton.textContent = isNear ? 'Complete Destination' : 'Move within 50m to Complete';
        completeButton.title = isNear ? 'Complete this arrival' : 'Move within 50 metres of the arrival location';
        document.querySelectorAll('.driver-complete-queue').forEach(button => {
            button.disabled = !isNear;
            button.textContent = isNear ? 'Complete' : 'Move within 50m';
            button.title = completeButton.title;
        });
    }

    async function completeDashboardDestination() {
        if (!trackingActive || !Number.isFinite(latestLat) || !Number.isFinite(latestLng) || !hasDestination) return;
        if (!confirm('Mark this destination as completed?')) return;
        completeButton.disabled = true;
        completeButton.textContent = 'Completing...';
        const body = new FormData();
        body.append('action', 'complete');
        body.append('csrf_token', csrfToken);
        body.append('lorry_id', lorryId);
        body.append('lat', latestLat);
        body.append('lng', latestLng);
        body.append('status', 'On Duty');
        body.append('destination_name', destinationName);
        try {
            const response = await fetch('api/gps_api.php', { method: 'POST', body, credentials: 'same-origin' });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Could not complete destination.');
            destinationLat = null;
            destinationLng = null;
            hasDestination = false;
            if (destinationMarker) { map.removeLayer(destinationMarker); destinationMarker = null; }
            if (routeLine) { map.removeLayer(routeLine); routeLine = null; }
            routeInitialized = false;
            updateCompletionButton();
            stopDashboardTracking('Destination completed');
            window.dispatchEvent(new CustomEvent('recyclon:driver-route-update', { detail: { destination_lat: null, destination_long: null } }));
        } catch (error) {
            routeStatus.textContent = error.message;
            updateCompletionButton();
        }
    }

    completeButton.addEventListener('click', completeDashboardDestination);
    window.recyclonCompleteQueueDestination = completeDashboardDestination;

    startButton.addEventListener('click', async () => {
        if (!navigator.geolocation || trackingActive) return;
        if (statusRequest) {
            startButton.disabled = true;
            await statusRequest;
            statusRequest = null;
            startButton.disabled = false;
        }
        if (!hasDestination) {
            localStorage.removeItem(trackingStorageKey);
            routeStatus.textContent = 'Select a destination on the map before starting tracking.';
            startButton.disabled = true;
            return;
        }
        localStorage.setItem(trackingStorageKey, '1');
        trackingActive = true;
        window.recyclonDriverTrackingActive = true;
        followMode = true;
        driverFollowZoomed = false;
        followBadge.textContent = 'Following you';
        followBadge.style.color = '#1d4ed8';
        startButton.classList.add('d-none');
        stopButton.classList.remove('d-none');
        updateCompletionButton();
        routeStatus.textContent = 'Tracking active · sending your location every 5 seconds';
        watchId = navigator.geolocation.watchPosition(position => {
            updateDashboardPosition(position);
            sendDashboardPosition('On Duty');
        }, () => {
            routeStatus.textContent = 'Location permission is required to start tracking.';
            stopDashboardTracking();
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 5000 });
        sendTimer = setInterval(() => sendDashboardPosition('On Duty'), 5000);
    });

    function stopDashboardTracking(message = 'Tracking stopped') {
        localStorage.removeItem(trackingStorageKey);
        trackingActive = false;
        window.recyclonDriverTrackingActive = false;
        if (watchId !== null) navigator.geolocation.clearWatch(watchId);
        if (sendTimer) clearInterval(sendTimer);
        watchId = null;
        sendTimer = null;
        statusRequest = sendDashboardPosition('Available');
        startButton.classList.remove('d-none');
        stopButton.classList.add('d-none');
        completeButton.classList.add('d-none');
        routeStatus.textContent = message;
    }

    stopButton.addEventListener('click', stopDashboardTracking);

    function initializeRoute() {
        if (destinationLat === null || destinationLng === null) {
            if (destinationMarker) { map.removeLayer(destinationMarker); destinationMarker = null; }
            if (routeLine) { map.removeLayer(routeLine); routeLine = null; }
            routeInitialized = false;
            hasDestination = false;
            startButton.disabled = true;
            routeStatus.textContent = 'Waiting for an ongoing destination.';
            return;
        }

        hasDestination = true;
        startButton.disabled = false;
        if (currentLat === null || currentLng === null) {
            routeStatus.textContent = 'Destination ready. Start tracking to get your current position.';
            return;
        }
        const start = [currentLat, currentLng];
        const destination = [destinationLat, destinationLng];
        if (currentMarker) currentMarker.setLatLng(start);
        else currentMarker = L.marker(start, { icon: driverIcon(null) }).addTo(map).bindTooltip('Your current location');
        routeInitialized = true;
        if (destinationMarker) map.removeLayer(destinationMarker);
        if (routeLine) map.removeLayer(routeLine);
        destinationMarker = L.marker(destination).addTo(map).bindTooltip(destinationName, { permanent: true, direction: 'top' });
        routeLine = L.polyline([start, destination], { color: '#2563eb', weight: 6, dashArray: '10 8', opacity: .75 }).addTo(map);
        map.fitBounds(routeLine.getBounds(), { padding: [30, 30] });
        routeStatus.textContent = 'Route to ' + destinationName + ' · calculating road route...';

        const routePath = currentLng + ',' + currentLat + ';' + destinationLng + ',' + destinationLat + '?overview=full&geometries=geojson';
        fetch('https://router.project-osrm.org/route/v1/driving/' + routePath)
            .then(response => response.json())
            .then(data => {
                if (!data.routes || !data.routes[0]) throw new Error('No route');
                map.removeLayer(routeLine);
                routeLine = L.geoJSON(data.routes[0].geometry, { style: { color: '#2563eb', weight: 6, opacity: .9 } }).addTo(map);
                routeStatus.textContent = 'Route to ' + destinationName + ' · ' + (data.routes[0].distance / 1000).toFixed(1) + ' km · about ' + Math.max(1, Math.round(data.routes[0].duration / 60)) + ' min';
                map.fitBounds(routeLine.getBounds(), { padding: [30, 30] });
            })
            .catch(() => {
                routeStatus.textContent = 'Route to ' + destinationName + ' · showing direction to destination';
            });
    }

    window.addEventListener('recyclon:driver-route-update', event => {
        const update = event.detail || {};
        const destinationCard = document.getElementById('driverDestinationCard');
        destinationLat = update.destination_lat == null ? null : Number(update.destination_lat);
        destinationLng = update.destination_long == null ? null : Number(update.destination_long);
        destinationName = update.destination_name || 'Assigned destination';
        hasDestination = destinationLat !== null && destinationLng !== null;
        updateCompletionButton();
        if (destinationCard) {
            destinationCard.innerHTML = hasDestination
                ? '<div class="text-secondary small fw-bold text-uppercase mb-2">Current Destination</div><h2 class="h5 fw-bold mb-2">' + destinationName.replace(/[&<>'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character])) + '</h2><div class="text-secondary small">Status: <strong>Ongoing</strong></div><div class="text-secondary small">Coordinates: ' + destinationLat.toFixed(6) + ', ' + destinationLng.toFixed(6) + '</div><div class="text-secondary small mt-2">Your route is ready. Use the Start Tracking button below to begin.</div>'
                : '<div class="text-secondary small fw-bold text-uppercase mb-2">Current Destination</div><h2 class="h5 fw-bold mb-2">No active destination</h2><div class="text-secondary small">Wait for your next destination to be assigned.</div>';
        }
        if (!hasDestination && trackingActive) stopDashboardTracking('No destination assigned');
        initializeRoute();
    });

    function resolveTextDestination() {
        if (Number.isFinite(destinationLat) && Number.isFinite(destinationLng)) {
            initializeRoute();
            maybeResumeTracking();
            return;
        }
        if (!destinationName || destinationName === 'Assigned destination') {
            initializeRoute();
            return;
        }

        startButton.disabled = true;
        routeStatus.textContent = 'Finding coordinates for ' + destinationName + '...';
        fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=my&q=' + encodeURIComponent(destinationName + ', Malaysia'), {
            headers: { Accept: 'application/json' }
        })
            .then(response => response.json())
            .then(places => {
                if (!places || !places[0]) throw new Error('Destination not found');
                destinationLat = Number(places[0].lat);
                destinationLng = Number(places[0].lon);
                initializeRoute();
                maybeResumeTracking();
            })
            .catch(() => {
                hasDestination = false;
                startButton.disabled = true;
                routeStatus.textContent = 'Could not locate the ongoing destination. Contact an administrator.';
            });
    }

    function maybeResumeTracking() {
        if (!shouldResumeTracking || trackingActive || !hasDestination || startButton.disabled) return;
        routeStatus.textContent = 'Resuming tracking...';
        setTimeout(() => {
            if (!trackingActive && hasDestination && !startButton.disabled) startButton.click();
        }, 250);
    }

    resolveTextDestination();
})();

// Keep assignment, queue, lorry status, and position current while the driver
// leaves the dashboard open. Reload only when the route or queue changes, so
// active GPS tracking is not interrupted by unnecessary page refreshes.
function renderDriverQueue(queue) {
    const body = document.getElementById('driverQueueBody');
    const count = document.getElementById('driverQueueCount');
    if (!body) return;
    if (count) count.textContent = queue.length;
    if (!queue.length) {
        body.innerHTML = '<div class="text-center text-secondary py-4">No queue items assigned to you.</div>';
        return;
    }
    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character]));
    const hasOngoing = queue.some(item => String(item.status || '').toLowerCase() === 'ongoing');
    const rows = queue.map(item => {
        const status = String(item.status || 'ongoing').toLowerCase();
        const location = item.location_name || item.geolocation || (item.latitude != null && item.longitude != null ? item.latitude + ', ' + item.longitude : '—');
        const lat = item.latitude ?? (status === 'ongoing' ? item.destination_lat : null);
        const lng = item.longitude ?? (status === 'ongoing' ? item.destination_long : null);
        const coordinates = lat != null && lng != null ? Number(lat).toFixed(6) + ', ' + Number(lng).toFixed(6) : 'Coordinates pending';
        let action = '<button type="button" class="btn btn-sm btn-primary driver-complete-queue" disabled>Move within 50m</button>';
        if (status === 'pending' && !hasOngoing) {
            action = '<button type="button" class="btn btn-sm btn-success driver-accept-queue" data-queue-id="' + Number(item.id || 0) + '" data-location="' + escapeHtml(location) + '" data-auto-start="1">Start job</button>';
        } else if (status === 'pending') {
            action = '<span class="small text-secondary">After current stop</span>';
        }
        return '<tr>' +
            '<td class="ps-4 fw-semibold queue-location-cell" data-label="Location" data-lat="' + escapeHtml(lat ?? '') + '" data-lng="' + escapeHtml(lng ?? '') + '">' + escapeHtml(location) + '</td>' +
            '<td class="text-secondary small" data-label="Coordinates">' + escapeHtml(coordinates) + '</td>' +
            '<td class="text-secondary small" data-label="Created">' + escapeHtml(item.created_at || '—') + '</td>' +
            '<td data-label="Status"><span class="status-pill ' + (status === 'pending' ? 'pending' : 'ongoing') + '"><span class="status-dot"></span>' + escapeHtml(status.charAt(0).toUpperCase() + status.slice(1)) + '</span></td>' +
            '<td class="pe-4 text-nowrap" data-label="Action">' + action + '</td>' +
            '</tr>';
    }).join('');
    body.innerHTML = '<div class="table-responsive"><table class="table table-hover align-middle mb-0 driver-queue-table"><thead><tr><th class="ps-4">Location</th><th>Coordinates</th><th>Created</th><th>Status</th><th class="pe-4">Action</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
}

(function startDashboardAutoRefresh() {
    const driverId = <?php echo (int)$driverId; ?>;
    const lorryId = <?php echo $lorry ? (int)$lorry['lorry_id'] : 0; ?>;
    const initialQueueState = <?php echo json_encode(array_map(static function ($item) {
        return [
            'id' => (int)($item['id'] ?? 0),
            'status' => strtolower((string)($item['status'] ?? '')),
            'location' => (string)($item['location_name'] ?? $item['geolocation'] ?? ''),
            'lat' => (string)($item['latitude'] ?? ''),
            'lng' => (string)($item['longitude'] ?? ''),
        ];
    }, $queue), JSON_UNESCAPED_UNICODE); ?>;
    let lastQueueState = JSON.stringify(initialQueueState);
    let lastRouteState = null;
    let refreshing = false;

    async function refreshDashboard() {
        if (refreshing || document.visibilityState === 'hidden') return;
        refreshing = true;
        try {
            const [gpsResponse, queueResponse] = await Promise.all([
                fetch('api/gps_poll.php?_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' }),
                driverId ? fetch('api/arrival_queue.php?driver_id=' + encodeURIComponent(driverId) + '&limit=200&_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' }) : null
            ]);
            if (!gpsResponse.ok) throw new Error('GPS status request failed');
            const gps = await gpsResponse.json();
            const tracker = lorryId
                ? (gps.trackers || []).find(item => Number(item.lorry_id) === lorryId)
                : (gps.trackers || []).find(item => Number(item.driver_id) === driverId);
            if ((lorryId && !tracker) || (!lorryId && tracker)) {
                const statusElement = document.getElementById('assignedLorryStatus');
                if (statusElement) statusElement.textContent = tracker ? 'Assignment changed' : 'Waiting for lorry assignment';
                return;
            }
            if (tracker) {
                const statusElement = document.getElementById('assignedLorryStatus');
                const updateElement = document.getElementById('assignedLorryLastUpdate');
                if (statusElement) statusElement.textContent = tracker.status || 'Unknown';
                if (updateElement) updateElement.textContent = tracker.last_updated || 'Not available';
                window.recyclonDriverPosition = { lat: Number(tracker.current_lat), lng: Number(tracker.current_long) };
                window.dispatchEvent(new CustomEvent('recyclon:driver-dashboard-update', { detail: tracker }));
            }
            if (queueResponse) {
                if (!queueResponse.ok) throw new Error('Queue status request failed');
                const queueResult = await queueResponse.json();
                if (queueResult.success) {
                    const activeQueue = (queueResult.data || []).filter(item => ['pending', 'ongoing'].includes(String(item.status || '').toLowerCase()));
                    const queueState = activeQueue.map(item => ({
                        id: Number(item.id || 0),
                        status: String(item.status || '').toLowerCase(),
                        location: String(item.location_name || item.geolocation || ''),
                        lat: String(item.latitude ?? ''),
                        lng: String(item.longitude ?? '')
                    }));
                    const nextQueueState = JSON.stringify(queueState);
                    if (nextQueueState !== lastQueueState) renderDriverQueue(activeQueue);
                    const ongoing = activeQueue.find(item => String(item.status || '').toLowerCase() === 'ongoing');
                    // Auto-accept is allowed only while GPS tracking is active.
                    // If the driver's lorry is free, accept the first pending
                    // job as soon as it appears in the polling response.
                    const autoAcceptToggle = document.getElementById('autoAcceptBookings');
                    const autoAcceptButton = document.querySelector('.driver-accept-queue[data-auto-start="1"]');
                    if (!ongoing && window.recyclonDriverTrackingActive === true &&
                        autoAcceptToggle?.dataset.enabled === '1' && autoAcceptButton &&
                        !autoAcceptButton.disabled && !autoAcceptButton.dataset.autoAccepting) {
                        autoAcceptButton.dataset.autoAccepting = '1';
                        setTimeout(() => autoAcceptButton.click(), 50);
                    }
                    const routeState = JSON.stringify([
                        tracker ? tracker.destination_lat : null,
                        tracker ? tracker.destination_long : null,
                        ongoing ? ongoing.id : 0,
                        ongoing ? (ongoing.location_name || ongoing.geolocation || '') : ''
                    ]);
                    if (routeState !== lastRouteState) {
                        lastRouteState = routeState;
                        window.dispatchEvent(new CustomEvent('recyclon:driver-route-update', {
                            detail: {
                                destination_lat: tracker ? tracker.destination_lat : null,
                                destination_long: tracker ? tracker.destination_long : null,
                                destination_name: ongoing ? (ongoing.location_name || ongoing.geolocation || 'Assigned destination') : 'Assigned destination'
                            }
                        }));
                    }
                    const queueCount = document.getElementById('driverQueueCount');
                    if (queueCount) queueCount.textContent = queueState.length;
                    lastQueueState = nextQueueState;
                }
            }
        } catch (error) {
            // A temporary network failure should not interrupt GPS tracking.
        } finally {
            refreshing = false;
        }
    }

    window.recyclonRefreshDriverDashboard = refreshDashboard;
    refreshDashboard();
    setInterval(refreshDashboard, 3000);
})();

document.addEventListener('click', async event => {
    const completeButton = event.target.closest('.driver-complete-queue');
    if (completeButton) {
        if (typeof window.recyclonCompleteQueueDestination === 'function') {
            await window.recyclonCompleteQueueDestination();
        }
        return;
    }

    const button = event.target.closest('.driver-accept-queue');
    if (!button) return;
    {
        const queueId = button.dataset.queueId;
        if (!queueId) return;
        button.disabled = true;
        button.textContent = 'Accepting...';
        try {
            let coordinates = {};
            const location = button.dataset.location || '';
            if (location) {
                const lookup = await fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=my&q=' + encodeURIComponent(location + ', Malaysia'), {
                    headers: { Accept: 'application/json' }
                });
                const places = await lookup.json();
                if (places[0]) coordinates = { latitude: places[0].lat, longitude: places[0].lon };
            }
            const response = await fetch('api/driver_queue_accept.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    id: Number(queueId),
                    csrf_token: <?php echo json_encode($csrfToken); ?>,
                    ...coordinates
                })
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Could not accept this request.');
            if (typeof window.recyclonRefreshDriverDashboard === 'function') {
                await window.recyclonRefreshDriverDashboard();
            }
        } catch (error) {
            alert(error.message);
            button.disabled = false;
            delete button.dataset.autoAccepting;
        button.textContent = 'Start job';
        }
    }
    
});

document.getElementById('autoAcceptBookings')?.addEventListener('click', async (event) => {
    const toggle = event.currentTarget;
    const previousEnabled = toggle.dataset.enabled === '1';
    const nextEnabled = !previousEnabled;
    toggle.dataset.enabled = nextEnabled ? '1' : '0';
    toggle.setAttribute('aria-pressed', nextEnabled ? 'true' : 'false');
    toggle.classList.toggle('is-enabled', nextEnabled);
    toggle.textContent = 'Auto-accept: ' + (nextEnabled ? 'On' : 'Off');
    toggle.disabled = true;
    try {
        const response = await fetch('api/driver_auto_accept.php', {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            body: JSON.stringify({enabled: nextEnabled ? 1 : 0, csrf_token: <?php echo json_encode($csrfToken); ?>})
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Unable to save setting.');
    } catch (error) {
        toggle.dataset.enabled = previousEnabled ? '1' : '0';
        toggle.setAttribute('aria-pressed', previousEnabled ? 'true' : 'false');
        toggle.classList.toggle('is-enabled', previousEnabled);
        toggle.textContent = 'Auto-accept: ' + (previousEnabled ? 'On' : 'Off');
        alert(error.message);
    } finally {
        toggle.disabled = false;
    }
});

// Sort address-only waiting jobs by distance, then automatically start the
// closest one when the lorry is free.
(async function sortWaitingJobsAndStartClosest() {
    if (window.recyclonDriverTrackingActive !== true) return;
    if (document.getElementById('autoAcceptBookings')?.dataset.enabled !== '1') return;
    const tbody = document.querySelector('.driver-accept-queue')?.closest('tbody');
    const driverPosition = window.recyclonDriverPosition || {};
    if (!tbody || !Number.isFinite(Number(driverPosition.lat)) || !Number.isFinite(Number(driverPosition.lng))) return;

    const rows = [...tbody.querySelectorAll('tr')];
    const waiting = rows.filter(row => row.querySelector('.driver-accept-queue[data-auto-start="1"]'));
    const distance = (lat, lng) => {
        const rad = Math.PI / 180;
        const dLat = (lat - Number(driverPosition.lat)) * rad;
        const dLng = (lng - Number(driverPosition.lng)) * rad;
        const a = Math.sin(dLat / 2) ** 2 + Math.cos(Number(driverPosition.lat) * rad) * Math.cos(lat * rad) * Math.sin(dLng / 2) ** 2;
        return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    };
    const ranked = [];
    for (const row of waiting) {
        const button = row.querySelector('.driver-accept-queue');
        let lat = Number(row.querySelector('.queue-location-cell')?.dataset.lat);
        let lng = Number(row.querySelector('.queue-location-cell')?.dataset.lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            try {
                const response = await fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=my&q=' + encodeURIComponent((button.dataset.location || '') + ', Malaysia'), {headers: {Accept: 'application/json'}});
                const places = await response.json();
                if (places[0]) { lat = Number(places[0].lat); lng = Number(places[0].lon); }
            } catch (error) { /* Start action will retry the lookup if needed. */ }
        }
        ranked.push({row, distance: Number.isFinite(lat) && Number.isFinite(lng) ? distance(lat, lng) : Number.POSITIVE_INFINITY});
    }
    ranked.sort((a, b) => a.distance - b.distance);
    ranked.forEach(item => tbody.appendChild(item.row));
    const closest = ranked[0]?.row?.querySelector('.driver-accept-queue[data-auto-start="1"]');
    if (closest) setTimeout(() => closest.click(), 400);
})();
</script>
<?php include 'footer.php'; ?>
