<?php
require_once __DIR__ . '/config/session.php';


if (session_status() === PHP_SESSION_NONE) session_start();

header('Cache-Control: no-store, no-cache, must-revalidate');
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
    header('Location: auth/login.php');
    exit;
}

$page = basename(__FILE__);
include 'header.php';

$trackers = [];
$message = '';
$errors = [];
$staffList = [];
$isGpsManager = in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true);
if ($isGpsManager && empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)($_SESSION['csrf_token'] ?? '');

function fetch_trackers(PDO $conn): array
{
    return $conn->query(
        "SELECT l.lorry_id, l.plate_number, l.status, l.current_lat, l.current_long, l.last_updated,
                l.destination_lat, l.destination_long, l.destination_set_at, l.driver_id,
                COALESCE(u.name, 'Unassigned') AS driver_name
         FROM lorries l
         LEFT JOIN users u ON l.driver_id = u.user_id
         ORDER BY l.lorry_id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $trackers = fetch_trackers($conn);
    $staffList = $conn->query("SELECT user_id, name FROM users WHERE role = 'Driver' AND status = 'Active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch queued arrival destinations for display (optional table)
$queue = [];
try {
    // Try the legacy, richer schema first (has driver_id, assigned_lorry_id, priority)
    $queue = $conn->query("SELECT q.*, COALESCE(u.name, 'Unassigned') AS driver_name, l.plate_number AS assigned_plate
        FROM arrival_queue q
         LEFT JOIN users u ON q.driver_id = u.user_id
         LEFT JOIN lorries l ON q.assigned_lorry_id = l.lorry_id
         WHERE q.status = 'ongoing'
         ORDER BY (q.status = 'ongoing') DESC, CASE q.status WHEN 'ongoing' THEN 1 WHEN 'pending' THEN 2 WHEN 'completed' THEN 3 WHEN 'cancelled' THEN 4 ELSE 5 END, q.priority ASC, q.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    // Fallback: arrival_queue may use the simplified geolocation-only schema.
    try {
        $queue = $conn->query("SELECT q.*, 
            COALESCE(q.driver_name, 'Unassigned') AS driver_name, 
            q.plate_number AS assigned_plate,
            q.geolocation AS location_name,
            q.status, q.created_at
            FROM arrival_queue q
            WHERE q.status = 'ongoing'
            ORDER BY (q.status = 'ongoing') DESC, CASE q.status WHEN 'ongoing' THEN 1 WHEN 'pending' THEN 2 WHEN 'completed' THEN 3 WHEN 'cancelled' THEN 4 ELSE 5 END, q.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ex2) {
        // ignore — queue table may not exist or be entirely different
        $queue = [];
    }
}

} catch (PDOException $e) {
$errors[] = 'Unable to load lorry tracking data.';
}

$defaultLat = 6.1244;
$defaultLng = 100.3670;

// How often (ms) the map/panel/table quietly refresh in the background.
$pollIntervalMs = 8000;
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<link rel="stylesheet" href ="assets/css/gps_tracker.css">


<div class="page-body">
    <?php
 include 'sidebar.php'; ?>

    <section class="content-panel gps-page">
        <div class="page-title mb-4 d-flex align-items-start justify-content-between flex-wrap gap-3">
            <div>
                <h1 class="display-6 fw-bold">GPS Tracker</h1>
                <p class="text-secondary mb-0">Real-time fleet positions — Alor Setar, Kedah</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <?php
 if ($isGpsManager): ?>
                    <a href="queue_management.php" class="btn fw-bold" style="background: var(--nav-bg); color: #fff; border-radius: 40px; white-space: nowrap;">Queue Management →</a>
                <?php
 endif; ?>
                <a href="history.php" class="btn fw-bold" style="background: var(--nav-bg); color: #fff; border-radius: 40px; white-space: nowrap;">History →</a>
            </div>
        </div>

        <?php
 if ($isGpsManager): ?>
            <div class="card border-0 shadow-sm mb-4 gps-admin-card">
                <div class="card-body p-3 d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div><h2 class="h6 fw-bold mb-1">Driver Assignment</h2><p class="text-secondary small mb-0">Assign an active Driver account to a lorry.</p></div>
                    <button type="button" class="btn btn-sm fw-bold" id="assignDriverBtn" style="background: var(--nav-bg); color:#fff; border-radius:10px;">Assign Driver</button>
                </div>
                <div id="assignDriverPanel" class="card-body border-top" style="display:none;">
                    <form id="assignDriverForm" class="row g-3 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?php
 echo htmlspecialchars($csrfToken); ?>">
                        <div class="col-md-5"><label class="form-label fw-semibold">Lorry</label><select name="lorry_id" class="form-select" required><option value="">Choose lorry</option><?php
 foreach ($trackers as $truck): ?><option value="<?php
 echo (int)$truck['lorry_id']; ?>"><?php
 echo htmlspecialchars($truck['plate_number']); ?></option><?php
 endforeach; ?></select></div>
                        <div class="col-md-5"><label class="form-label fw-semibold">Driver</label><select name="driver_id" class="form-select"><option value="0">Unassign driver</option><?php
 foreach ($staffList as $driver): ?><option value="<?php
 echo (int)$driver['user_id']; ?>"><?php
 echo htmlspecialchars($driver['name']); ?></option><?php
 endforeach; ?></select></div>
                        <div class="col-md-2"><button type="submit" class="btn btn-primary w-100 fw-bold">Save</button></div>
                    </form>
                </div>
            </div>
            <div class="card border-0 shadow-sm mb-4 gps-admin-card gps-admin-card--lorry">
                <div class="card-body p-3 d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div><h2 class="h6 fw-bold mb-1">Lorry Management</h2><p class="text-secondary small mb-0">Create, edit, remove lorries, and update their assigned driver, status, GPS, and destination data.</p></div>
                    <button type="button" class="btn btn-sm fw-bold" id="manageLorryBtn" style="background:#0f766e;color:#fff;border-radius:10px;">Manage Lorries</button>
                </div>
                <div id="manageLorryPanel" class="card-body border-top" style="display:none;">
                    <form id="lorryManageForm" class="row g-3 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?php
 echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="create" id="lorryAction">
                        <input type="hidden" name="lorry_id" value="" id="managedLorryId">
                        <div class="col-md-3"><label class="form-label fw-semibold">Plate number</label><input class="form-control" name="plate_number" id="managedPlate" maxlength="20" placeholder="KDB 1234" required></div>
                        <div class="col-md-2"><label class="form-label fw-semibold">Driver</label><select class="form-select" name="driver_id" id="managedDriver"><option value="0">Unassigned</option><?php
 foreach ($staffList as $driver): ?><option value="<?php
 echo (int)$driver['user_id']; ?>"><?php
 echo htmlspecialchars($driver['name']); ?></option><?php
 endforeach; ?></select></div>
                        <div class="col-md-2"><label class="form-label fw-semibold">Status</label><select class="form-select" name="status" id="managedStatus"><option>Available</option><option>On Duty</option><option>Maintenance</option></select></div>
                        <div class="col-md-3 d-flex gap-2"><button type="submit" class="btn btn-primary fw-bold" id="saveManagedLorry">Create Lorry</button><button type="button" class="btn btn-outline-secondary" id="resetManagedLorry">Clear</button></div>
                    </form>
                    <div class="table-responsive mt-4"><table class="table table-sm align-middle"><thead><tr><th>Plate</th><th>Driver</th><th>Status</th><th>Current position</th><th>Destination</th><th>Actions</th></tr></thead><tbody>
                        <?php
 foreach ($trackers as $truck): ?>
                            <tr><td class="fw-semibold"><?php
 echo htmlspecialchars($truck['plate_number']); ?></td><td><?php
 echo htmlspecialchars($truck['driver_name']); ?></td><td><?php
 echo htmlspecialchars($truck['status']); ?></td><td class="small text-secondary"><?php
 echo $truck['current_lat'] !== null && $truck['current_long'] !== null ? htmlspecialchars($truck['current_lat'] . ', ' . $truck['current_long']) : '—'; ?></td><td class="small text-secondary"><?php
 echo $truck['destination_lat'] !== null && $truck['destination_long'] !== null ? htmlspecialchars($truck['destination_lat'] . ', ' . $truck['destination_long']) : '—'; ?></td><td class="text-nowrap"><button type="button" class="btn btn-sm btn-outline-primary edit-lorry-btn" data-lorry="<?php
 echo htmlspecialchars(json_encode($truck), ENT_QUOTES, 'UTF-8'); ?>">Edit</button> <button type="button" class="btn btn-sm btn-outline-danger delete-lorry-btn" data-lorry-id="<?php
 echo (int)$truck['lorry_id']; ?>">Delete</button></td></tr>
                        <?php
 endforeach; ?>
                    </tbody></table></div>
                </div>
            </div>
        <?php
 endif; ?>

        <?php
 if ($message !== ''): ?><div class="alert alert-success mb-3"><?php
 echo htmlspecialchars($message); ?></div><?php
 endif; ?>
        <?php
 if (!empty($errors)): ?>
            <div class="alert alert-danger mb-3"><ul class="mb-0"><?php
 foreach ($errors as $error): ?><li><?php
 echo htmlspecialchars($error); ?></li><?php
 endforeach; ?></ul></div>
        <?php
 endif; ?>

        <div class="gps-layout mb-4">
            <div class="gps-map-wrap">
                <div class="gps-map-header">
                    <span class="gps-map-title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                        Fleet Map — Click to set arrival destination
                    </span>
                    <button type="button" class="btn btn-sm" id="refreshMapBtn" onclick="refreshTrackers()" style="background:rgba(255,255,255,0.15);color:#fff;border-radius:999px;padding:0.35rem 0.8rem;font-weight:700;font-size:0.75rem;border:1px solid rgba(255,255,255,0.3);">
                        ↻ Refresh
                    </button>
                    <button type="button" class="btn btn-sm" id="fitBoundsBtn" onclick="fitMapToTrucks()" style="background:rgba(255,255,255,0.15);color:#fff;border-radius:999px;padding:0.35rem 0.8rem;font-weight:700;font-size:0.75rem;border:1px solid rgba(255,255,255,0.3);">
                        ⊞ Fit All
                    </button>
                    <span class="gps-live-badge"><span class="gps-live-dot"></span>LIVE</span>
                    <span class="gps-sync-note" id="syncNote">Syncing…</span>
                </div>
                <div id="trackerMap"></div>
            </div>

        </div>

        <div class="card border-0 shadow-sm gps-control-card">
            <div class="card-header border-0 d-flex align-items-center gap-3 py-3 px-4 gps-section-header">
                <span class="gps-section-icon d-flex align-items-center justify-content-center">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="10" r="3"/><path d="M12 21.7C17.3 17 20 13 20 10a8 8 0 1 0-16 0c0 3 2.7 6.9 8 11.7z"/></svg>
                </span>
                <div>
                    <h2 class="h6 mb-0 fw-bold">Set Arrival Destination</h2>
                    <span class="gps-section-description">Select a lorry, then click the map to set its arrival location</span>
                </div>
            </div>
            <div class="card-body px-4 py-4">
                <form method="post" id="locationForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="row g-4">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold d-flex align-items-center gap-2 mb-2" style="font-size: 0.85rem; color: #0f172a;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--nav-bg)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                                Lorry
                            </label>
                            <select class="form-select" name="lorry_id" id="arrivalLorrySelect" required style="border-radius: 10px; border: 1.5px solid #e2e8f0; padding: 0.6rem 1rem; font-weight: 600; background: #fafcff;">
                                <option value="">Choose lorry ⬇️</option>
                                <?php
 foreach ($trackers as $truck): ?>
                                    <option value="<?php
 echo $truck['lorry_id']; ?>" data-driver-id="<?php
 echo (int)($truck['driver_id'] ?? 0); ?>" data-driver-name="<?php
 echo htmlspecialchars($truck['driver_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8'); ?>"><?php
 echo htmlspecialchars($truck['plate_number']); ?></option>
                                <?php
 endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold d-flex align-items-center gap-2 mb-2" style="font-size: 0.85rem; color: #0f172a;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--nav-bg)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                Assigned Driver
                            </label>
                            <div class="form-control" id="assignedDriverName" style="background:#f1f5f9; font-weight:600;">Choose a lorry to see its assigned driver</div>
                        </div>
                        <div class="col-md-4" style="position: relative;">
                            <label class="form-label fw-semibold d-flex align-items-center gap-2 mb-2" style="font-size: 0.85rem; color: #0f172a;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--nav-bg)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a8 8 0 0 0-8 8c0 5.4 8 12 8 12s8-6.6-8-12a8 8 0 0 0-8-8z"/><circle cx="12" cy="10" r="3"/></svg>
                                Arrival Location
                            </label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="location_name" id="locationInput" placeholder="e.g. No. 12, Jalan Melati, Taman Murni, Alor Setar..." autocomplete="street-address" required style="border-radius: 10px 0 0 10px; border: 1.5px solid #e2e8f0; padding: 0.6rem 1rem; font-weight: 600; background: #fafcff;">
                                <button type="button" class="btn" id="geoLocateBtn" onclick="getMyLocation()" style="border-radius: 0 10px 10px 0; border: 1.5px solid #e2e8f0; border-left: 0; background: #fafcff; padding: 0.6rem 1rem;" title="Use my location">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--nav-bg)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg>
                                </button>
                            </div>
                            <div id="locationDropdown" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:1000; background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; max-height:300px; overflow-y:auto; box-shadow:0 12px 32px rgba(15,23,42,0.15); margin-top:3px;"></div>
                            <div style="font-size: 0.72rem; color: #64748b; margin-top: 0.3rem;" id="locationPreview"></div>
                            <input type="hidden" name="latitude" id="latField" value="">
                            <input type="hidden" name="longitude" id="lngField" value="">
                            <input type="hidden" name="mode" value="arrival">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn w-100 d-flex align-items-center justify-content-center gap-2 fw-bold" style="background: #1d4ed8; color: #fff; border-radius: 10px; padding: 0.7rem 1rem; border: none; font-size: 0.95rem;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 14.66V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h5.34"/><polygon points="18 2 22 6 12 16 8 16 8 12 18 2"/></svg>
                                Set Arrival
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-4 gps-queue-card">
            <div class="card-body p-0">
                <div class="gps-subsection-heading">
                    <div>
                        <h2 class="h5 mb-1">Active queue</h2>
                        <p class="mb-0">Ongoing arrival destinations currently assigned to the fleet.</p>
                    </div>
                    <span class="gps-heading-accent" aria-hidden="true"></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Plate</th>
                                <th>Driver</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
 if (empty($queue)): ?>
                                <tr><td colspan="6" class="text-muted text-center py-4">No ongoing queued destinations found.</td></tr>
                            <?php
 else: ?>
                                <?php
 foreach ($queue as $q): ?>
                                    <tr>
                                        <td class="fw-bold">#<?php
 echo (int)($q['id'] ?? 0); ?></td>
                                        <td><?php
 echo htmlspecialchars($q['assigned_plate'] ?? $q['plate_number'] ?? ''); ?></td>
                                        <td><?php
 echo htmlspecialchars($q['driver_name'] ?? ''); ?></td>
                                        <td><?php
 echo htmlspecialchars($q['location_name'] ?? $q['geolocation'] ?? ($q['latitude'] ?? '') . ($q['longitude'] ?? '')); ?></td>
                                        <td>
                                            <span class="status-pill <?php
 echo strtolower((string)($q['status'] ?? 'pending')); ?>">
                                                <span class="status-dot" style="width:7px;height:7px;border-radius:50%;background:currentColor;"></span>
                                                <?php
 echo htmlspecialchars(ucfirst((string)($q['status'] ?? 'pending'))); ?>
                                            </span>
                                        </td>
                                        <td><?php
 echo htmlspecialchars($q['created_at'] ?? ''); ?></td>
                                    </tr>
                                <?php
 endforeach; ?>
                            <?php
 endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-4 gps-integration-card">
            <div class="card-body p-4">
                <div class="gps-subsection-heading gps-subsection-heading--integration">
                    <div>
                        <h2 class="h5 mb-1">Automation &amp; Integration</h2>
                        <p class="mb-0">Connect fleet updates and driver tracking to the operational map.</p>
                    </div>
                    <span class="gps-heading-accent" aria-hidden="true"></span>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="gps-integration-panel">
                            <div class="gps-panel-kicker"><span class="gps-panel-icon">↗</span> API Endpoint</div>
                            <code class="gps-endpoint">POST http://localhost/FYP/api/gps_api.php</code>
                            <pre class="gps-code-block">{
  "api_key": "your-secret-key",
  "lorry_id": 1,
  "lat": 3.1390,
  "lng": 101.6869,
  "status": "On Duty"
}</pre>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="gps-integration-panel gps-integration-panel--action">
                            <div class="gps-panel-kicker"><span class="gps-panel-icon">◎</span> Driver Auto-Tracker</div>
                            <p>Drivers open this page on their phone — uses browser GPS to send position every 10 seconds.</p>
                            <a href="driver_gps.php" target="_blank" class="btn btn-sm fw-bold gps-action-button">Open Driver Page <span aria-hidden="true">→</span></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>



<script>
function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    }[character]));
}

document.addEventListener('DOMContentLoaded', function () {
    const map = L.map('trackerMap', {
        zoomControl: true,
        attributionControl: true,
        maxBounds: L.latLngBounds([-90, -180], [90, 180]),
        maxBoundsViscosity: 1.0
    }).setView([<?php
 echo $defaultLat; ?>, <?php
 echo $defaultLng; ?>], 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        noWrap: true,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);

    // Landmarks — Alor Setar & Kedah region (static, drawn once)
    const landmarks = [
        { label: 'Alor Setar', lat: 6.1244, lng: 100.3670 },
        { label: 'Menara Alor Setar', lat: 6.1176, lng: 100.3650 },
        { label: 'Pekan Rabu', lat: 6.1220, lng: 100.3680 },
        { label: 'Masjid Zahir', lat: 6.1210, lng: 100.3660 },
        { label: 'Stadium Darul Aman', lat: 6.1370, lng: 100.3700 },
        { label: 'Jitra', lat: 6.2700, lng: 100.4200 },
        { label: 'Kubang Pasu', lat: 6.2500, lng: 100.4000 },
    ];

    landmarks.forEach(function (lm) {
        const marker = L.marker([lm.lat, lm.lng], {
            icon: L.divIcon({
                className: '',
                html: '<span style="display:block;width:9px;height:9px;border-radius:999px;background:#0c3a78;border:2px solid #fff;box-shadow:0 2px 4px rgba(0,0,0,0.2);"></span>',
                iconSize: [9, 9],
                iconAnchor: [4, 4]
            }),
            interactive: true
        }).addTo(map);

        marker.bindTooltip(lm.label, { direction: 'top', offset: [0, -6], className: 'landmark-tooltip' });
    });

    // ---------- Live truck state ----------
    // Keyed by lorry_id so a poll updates markers/lines in place instead
    // of tearing down and rebuilding the whole map every refresh.
    const truckMarkers = {}; // current-position markers
    const destMarkers = {};  // arrival-destination markers
    const routeLines = {};   // { id: [line, label?] } — routed path from current position to destination
    const routeKeys = {};    // { id: 'lat,lng,destLat,destLng' } — skips redundant OSRM calls on poll
    let trucks = <?php
 echo json_encode($trackers); ?>;
    window.trackerTrucks = trucks;

    function getTruckColor(status) {
        if (status === 'On Duty') return '#22c55e';
        if (status === 'Maintenance') return '#f59e0b';
        return '#94a3b8';
    }

    function truckIcon(truck) {
        const color = getTruckColor(truck.status);
        return L.divIcon({
            className: '',
            html: '<div style="display:flex;flex-direction:column;align-items:center;gap:2px;">' +
                  '<div style="width:40px;height:40px;border-radius:999px;background:' + color + ';border:3px solid #fff;box-shadow:0 4px 12px rgba(0,0,0,0.3);display:flex;align-items:center;justify-content:center;">' +
                  '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M3 7h11v9H3zM14 11h4l3 3v2h-7zM6.5 19.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM17.5 19.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z"/><\/svg>' +
                  '<\/div>' +
                  '<span style="background:#fff;border-radius:999px;padding:1px 6px;font-size:0.7rem;font-weight:700;color:#0f172a;box-shadow:0 2px 6px rgba(0,0,0,0.15);white-space:nowrap;">' + truck.plate_number + '<\/span>' +
                  '<\/div>',
            iconSize: [0, 0],
            iconAnchor: [0, 20]
        });
    }

    function truckPopup(truck) {
        const latNum = parseFloat(truck.current_lat);
        const lngNum = parseFloat(truck.current_long);
        return '<div style="font-family:Inter,sans-serif;font-size:0.85rem;">' +
            '<strong>' + truck.plate_number + '<\/strong><br>' +
            'Driver: ' + truck.driver_name + '<br>' +
            'Status: <strong>' + truck.status + '<\/strong><br>' +
            '<span style="color:#64748b;font-size:0.75rem;">' + latNum.toFixed(4) + '°N, ' + lngNum.toFixed(4) + '°E<\/span>' +
            '<\/div>';
    }

    function destIcon(truck) {
        return L.divIcon({
            className: '',
            html: '<div style="display:flex;flex-direction:column;align-items:center;gap:1px;">' +
                  '<div style="font-size:0.9rem;font-weight:800;color:#1d4ed8;text-shadow:0 0 4px #fff;">🏁</div>' +
                  '<div style="width:18px;height:18px;border-radius:999px;background:#1d4ed8;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.4);"></div>' +
                  '<div style="font-size:0.55rem;font-weight:800;color:#1d4ed8;text-transform:uppercase;background:#fff;padding:0 4px;border-radius:2px;">' + truck.plate_number + '</div>' +
                  '<\/div>',
            iconSize: [0, 0],
            iconAnchor: [0, 18]
        });
    }

    function destPopup(truck) {
        const destLat = parseFloat(truck.destination_lat);
        const destLng = parseFloat(truck.destination_long);
        return '<div style="font-family:Inter,sans-serif;font-size:0.85rem;">' +
            '<strong>' + truck.plate_number + ' — Arrival Destination<\/strong><br>' +
            'Driver: ' + truck.driver_name + '<br>' +
            '<span style="color:#1d4ed8;font-size:0.75rem;">' + destLat.toFixed(4) + '°N, ' + destLng.toFixed(4) + '°E<\/span>' +
            '<\/div>';
    }

    // Create/update a single truck's current-position marker. Markers only
    // ever move or restyle — never destroyed and recreated on poll, which
    // is what keeps open popups/tooltips from flickering.
    function upsertTruckMarker(truck) {
        const id = String(truck.lorry_id);
        const hasFix = truck.current_lat !== null && truck.current_long !== null;

        if (!hasFix) {
            if (truckMarkers[id]) { map.removeLayer(truckMarkers[id]); delete truckMarkers[id]; }
            return;
        }

        const latLng = [parseFloat(truck.current_lat), parseFloat(truck.current_long)];

        if (truckMarkers[id]) {
            truckMarkers[id].setLatLng(latLng);
            truckMarkers[id].setIcon(truckIcon(truck));
            truckMarkers[id].setPopupContent(truckPopup(truck));
        } else {
            const marker = L.marker(latLng, { icon: truckIcon(truck) }).addTo(map);
            marker.bindPopup(truckPopup(truck));
            truckMarkers[id] = marker;
        }
    }

    // Draws the actual road route (via OSRM) from a truck's current
    // position to its arrival destination, with a distance/ETA label at
    // the midpoint — falls back to a plain dashed line if OSRM fails.
    // Skips the network call entirely if neither point moved since the
    // last time this truck's route was drawn.
    function fetchRouteJson(url, timeoutMs = 10000) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs);
        return fetch(url, { signal: controller.signal })
            .then(response => {
                if (!response.ok) throw new Error('Routing service returned HTTP ' + response.status);
                return response.json();
            })
            .finally(() => clearTimeout(timer));
    }

    function fetchRouteWithFallback(urls, index = 0) {
        if (index >= urls.length) return Promise.reject(new Error('No routing service available'));
        return fetchRouteJson(urls[index]).catch(() => fetchRouteWithFallback(urls, index + 1));
    }

    function drawRouteLine(truck) {
        const id = String(truck.lorry_id);
        const hasBoth = truck.current_lat !== null && truck.current_long !== null
            && truck.destination_lat !== null && truck.destination_long !== null;

        if (!hasBoth) {
            if (routeLines[id]) { routeLines[id].forEach(l => map.removeLayer(l)); delete routeLines[id]; }
            delete routeKeys[id];
            return;
        }

        const truckLat = parseFloat(truck.current_lat);
        const truckLng = parseFloat(truck.current_long);
        const destLat = parseFloat(truck.destination_lat);
        const destLng = parseFloat(truck.destination_long);

        const key = `${truckLat},${truckLng},${destLat},${destLng}`;
        if (routeKeys[id] === key) return; // nothing moved — keep the existing route on screen
        routeKeys[id] = key;

        const routePath = `${truckLng},${truckLat};${destLng},${destLat}?overview=full&geometries=geojson`;
        const routeUrls = [
            `https://router.project-osrm.org/route/v1/driving/${routePath}`,
            `https://routing.openstreetmap.de/routed-car/route/v1/driving/${routePath}`
        ];

        fetchRouteWithFallback(routeUrls)
            .then(data => {
                if (!data.routes || !data.routes[0]) throw new Error('no route');
                const route = data.routes[0];
                const coords = route.geometry.coordinates.map(c => [c[1], c[0]]);

                if (routeLines[id]) { routeLines[id].forEach(l => map.removeLayer(l)); }

                const line = L.polyline(coords, { color: '#4285F4',weight: 5,opacity: 0.9,lineCap: 'round',lineJoin: 'round'}).addTo(map);

                const distKm = (route.distance / 1000).toFixed(1);
                const etaMin = Math.round(route.duration / 60);
                const mid = coords[Math.floor(coords.length / 2)];

                const label = L.marker(mid, {
                    icon: L.divIcon({
                        className: '',
                        html: `<div style="background:#fff;border-radius:8px;padding:3px 8px;font-size:0.72rem;font-weight:700;color:#1d4ed8;box-shadow:0 2px 6px rgba(0,0,0,0.25);white-space:nowrap;">${distKm} km · ${etaMin} min</div>`,
                        iconSize: [0, 0]
                    }),
                    interactive: false
                }).addTo(map);

                routeLines[id] = [line, label];
            })
            .catch(() => {
                if (routeLines[id]) { routeLines[id].forEach(l => map.removeLayer(l)); }
                const line = L.polyline([[truckLat, truckLng], [destLat, destLng]], {
                    color: '#94a3b8', weight: 2, dashArray: '6,8'
                }).addTo(map);
                routeLines[id] = [line];
            });
    }

    // Create/update a truck's arrival-destination marker + its routed path.
    function upsertDestMarker(truck) {
        const id = String(truck.lorry_id);
        let hasDest = truck.destination_lat !== null && truck.destination_long !== null
            && Number.isFinite(parseFloat(truck.destination_lat)) && Number.isFinite(parseFloat(truck.destination_long));

        if (!hasDest) {
            if (destMarkers[id]) { map.removeLayer(destMarkers[id]); delete destMarkers[id]; }
            if (routeLines[id]) { routeLines[id].forEach(l => map.removeLayer(l)); delete routeLines[id]; }
            delete routeKeys[id];
            return;
        }

        const destLatLng = [parseFloat(truck.destination_lat), parseFloat(truck.destination_long)];

        if (destMarkers[id]) {
            destMarkers[id].setLatLng(destLatLng);
            destMarkers[id].setPopupContent(destPopup(truck));
        } else {
            const marker = L.marker(destLatLng, { icon: destIcon(truck) }).addTo(map);
            marker.bindPopup(destPopup(truck));
            destMarkers[id] = marker;
        }

        drawRouteLine(truck);
    }

    trucks.forEach(function (truck) {
        upsertTruckMarker(truck);
        upsertDestMarker(truck);
    });

    // ---------- Click map to set arrival destination (preview only —
    // nothing is saved until the form below is submitted) ----------
    let previewMarker = null;

    map.on('click', function (e) {
        const selectedLorryId = document.getElementById('arrivalLorrySelect') ? document.getElementById('arrivalLorrySelect').value : '';
        if (!selectedLorryId) {
            alert('Please select a lorry first, then click the map to set its arrival destination.');
            return;
        }

        const lat = parseFloat(e.latlng.lat.toFixed(6));
        const lng = parseFloat(e.latlng.lng.toFixed(6));

        document.getElementById('latField').value = lat;
        document.getElementById('lngField').value = lng;

        const url = 'https://nominatim.openstreetmap.org/reverse?lat=' + lat + '&lon=' + lng + '&format=json&zoom=16';
        fetch(url, { headers: { 'User-Agent': 'RecyclonTracker/1.0' } })
            .then(r => r.json())
            .then(data => {
                const displayName = data.display_name || lat + '°N, ' + lng + '°E';
                const shortName = displayName.split(', ').slice(0, 3).join(', ');
                document.getElementById('locationInput').value = shortName;
                document.getElementById('locationPreview').textContent = '📍 ' + lat + '°N, ' + lng + '°E';
            })
            .catch(() => {
                document.getElementById('locationInput').value = lat + '°N, ' + lng + '°E';
                document.getElementById('locationPreview').textContent = '📍 ' + lat + '°N, ' + lng + '°E';
            });

        if (previewMarker) map.removeLayer(previewMarker);
        previewMarker = L.marker([lat, lng], {
            icon: L.divIcon({
                className: '',
                html: '<div style="display:flex;flex-direction:column;align-items:center;gap:1px;">' +
                      '<div style="font-size:0.9rem;font-weight:800;color:#1d4ed8;text-shadow:0 0 4px #fff;">📍</div>' +
                      '<div style="width:18px;height:18px;border-radius:999px;background:#1d4ed8;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.4);"></div>' +
                      '<div style="font-size:0.55rem;font-weight:800;color:#1d4ed8;text-transform:uppercase;background:#fff;padding:0 4px;border-radius:2px;">Arrival</div>' +
                      '<\/div>',
                iconSize: [0, 0],
                iconAnchor: [0, 18]
            })
        }).addTo(map);
    });

    // ---------- Reverse-geocode a table location cell (only called when
    // its coordinates actually change, to stay polite to Nominatim) ----------
    function reverseGeocodeCell(cell, lat, lng) {
        const url = 'https://nominatim.openstreetmap.org/reverse?lat=' + lat + '&lon=' + lng + '&format=json&zoom=14';
        fetch(url, { headers: { 'User-Agent': 'RecyclonTracker/1.0' } })
            .then(r => r.json())
            .then(data => {
                if (data.display_name) {
                    cell.textContent = data.display_name.split(', ').slice(0, 3).join(', ');
                }
            })
            .catch(() => {});
    }

    document.querySelectorAll('.location-cell').forEach(function (cell) {
        const lat = cell.getAttribute('data-lat');
        const lng = cell.getAttribute('data-lng');
        if (!lat || !lng || lat === '' || lng === '') return;
        reverseGeocodeCell(cell, lat, lng);
    });

    window.trackerMap = map;

    setTimeout(function () { map.invalidateSize(); }, 200);
    window.addEventListener('resize', function () { map.invalidateSize(); });

    // ---------- Live refresh ----------
    // Polls a lightweight JSON endpoint and patches the map, fleet panel,
    // and table in place — no page reload, no lost map pan/zoom, no lost
    // arrival-destination preview marker.
    const POLL_URL = 'api/gps_poll.php';
    const POLL_INTERVAL_MS = <?php
 echo (int) $pollIntervalMs; ?>;
    const syncNote = document.getElementById('syncNote');

    function flash(el) {
        if (!el) return;
        el.classList.remove('gps-just-updated');
        void el.offsetWidth; // restart the animation on repeated updates
        el.classList.add('gps-just-updated');
    }

    function applyPollResult(freshTrucks) {
        trucks = freshTrucks;
        window.trackerTrucks = freshTrucks;
        trucks.forEach(function (truck) {
            try {
                upsertTruckMarker(truck);
                upsertDestMarker(truck);
                drawBreadcrumbs(truck);
            } catch (error) {
                // One malformed tracker must not stop the other lorries
                // from refreshing.
            }
        });
    }

    function pollTrackers() {
        fetch(POLL_URL, { cache: 'no-store' })
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(text => {
                let data;
                try {
                    data = JSON.parse(text);
                } catch (error) {
                    throw new Error('Invalid GPS sync response');
                }
                return data;
            })
            .then(data => {
                if (!data.success) throw new Error(data.message || 'poll failed');
                if (!Array.isArray(data.trackers)) throw new Error('Invalid tracker data');
                applyPollResult(data.trackers);
                syncNote.textContent = 'Synced ' + (data.server_time || '');
                syncNote.classList.remove('sync-error');
            })
            .catch(error => {
                syncNote.textContent = 'Sync failed - retrying...';
                syncNote.classList.add('sync-error');
            });
    }

    // Pause polling while the tab is hidden so we don't hammer the server
    // with a background tab nobody is looking at.
    let pollTimer = setInterval(pollTrackers, POLL_INTERVAL_MS);
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            clearInterval(pollTimer);
        } else {
            pollTrackers();
            pollTimer = setInterval(pollTrackers, POLL_INTERVAL_MS);
        }
    });

    pollTrackers();
});

function refreshTrackers() {
    location.reload();
}

// Browser geolocation button
function getMyLocation() {
    if (!navigator.geolocation) {
        alert('Geolocation is not supported by your browser.');
        return;
    }
    navigator.geolocation.getCurrentPosition(
        function (pos) {
            const lat = pos.coords.latitude.toFixed(6);
            const lng = pos.coords.longitude.toFixed(6);
            document.getElementById('latField').value = lat;
            document.getElementById('lngField').value = lng;

            const url = 'https://nominatim.openstreetmap.org/reverse?lat=' + lat + '&lon=' + lng + '&format=json&zoom=16';
            fetch(url, { headers: { 'User-Agent': 'RecyclonTracker/1.0' } })
                .then(r => r.json())
                .then(data => {
                    const displayName = data.display_name || '';
                    const shortName = displayName.split(', ').slice(0, 3).join(', ');
                    document.getElementById('locationInput').value = shortName || lat + '°N, ' + lng + '°E';
                    document.getElementById('locationPreview').textContent = '📍 ' + lat + '°N, ' + lng + '°E';
                })
                .catch(() => {
                    document.getElementById('locationInput').value = lat + '°N, ' + lng + '°E';
                });
        },
        function () {
            alert('Could not get your location. Please enable GPS or type a place name.');
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
}

// ===== Live Address Autocomplete =====
const locationInput = document.getElementById('locationInput');
const locationDropdown = document.getElementById('locationDropdown');
let autocompleteTimer = null;

locationInput.addEventListener('input', function () {
    document.getElementById('latField').value = '';
    document.getElementById('lngField').value = '';

    const query = this.value.trim();

    if (autocompleteTimer) clearTimeout(autocompleteTimer);

    if (query.length < 3) {
        locationDropdown.style.display = 'none';
        document.getElementById('locationPreview').textContent = '';
        return;
    }

    document.getElementById('locationPreview').textContent = '🔍 Searching...';
    locationDropdown.innerHTML = '<div style="padding:0.75rem 1rem;color:#64748b;font-size:0.85rem;">Searching...</div>';
    locationDropdown.style.display = 'block';

    autocompleteTimer = setTimeout(function () {
        const url = 'https://nominatim.openstreetmap.org/search?q=' + encodeURIComponent(query + ', Malaysia') + '&format=json&limit=8&addressdetails=1&countrycodes=my';
        fetch(url, { headers: { 'User-Agent': 'RecyclonTracker/1.0' } })
            .then(r => r.json())
            .then(data => {
                if (!data || data.length === 0) {
                    locationDropdown.innerHTML = '<div style="padding:0.75rem 1rem;color:#94a3b8;font-size:0.85rem;">No results found</div>';
                    return;
                }

                let html = '';
                data.forEach(function (place) {
                    const displayName = place.display_name.split(', ').slice(0, 4).join(', ');
                    const type = place.type || 'place';
                    const icon = type === 'city' || type === 'town' ? '🏙️' :
                                type === 'road' || type === 'street' ? '🛣️' :
                                type === 'building' || type === 'amenity' ? '🏢' : '📍';
                    html += '<div class="autocomplete-item" data-lat="' + escapeHtml(place.lat) + '" data-lng="' + escapeHtml(place.lon) + '" style="padding:0.6rem 1rem;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:0.85rem;transition:background 0.15s;">' +
                            '<span style="margin-right:0.4rem;">' + icon + '</span>' +
                            '<span style="font-weight:600;">' + escapeHtml(displayName) + '</span>' +
                            '</div>';
                });
                locationDropdown.innerHTML = html;

                document.querySelectorAll('.autocomplete-item').forEach(function (item) {
                    item.addEventListener('click', function () {
                        const lat = this.getAttribute('data-lat');
                        const lng = this.getAttribute('data-lng');
                        const text = this.textContent.trim().replace(/^[^\s]+\s/, ''); // remove icon

                        locationInput.value = text;
                        document.getElementById('latField').value = lat;
                        document.getElementById('lngField').value = lng;
                        document.getElementById('locationPreview').textContent = '📍 ' + parseFloat(lat).toFixed(4) + '°N, ' + parseFloat(lng).toFixed(4) + '°E';
                        locationDropdown.style.display = 'none';

                        if (window.trackerMap) {
                            window.trackerMap.setView([lat, lng], 15);
                        }
                    });

                    item.addEventListener('mouseenter', function () { this.style.background = '#eef4ff'; });
                    item.addEventListener('mouseleave', function () { this.style.background = ''; });
                });
            })
            .catch(function () {
                locationDropdown.innerHTML = '<div style="padding:0.75rem 1rem;color:#dc2626;font-size:0.85rem;">Search failed. Try again.</div>';
            });
    }, 350);
});

// Close dropdown on outside click
document.addEventListener('click', function (e) {
    if (!e.target.closest('#locationInput') && !e.target.closest('#locationDropdown')) {
        locationDropdown.style.display = 'none';
    }
});

// ===== GPS History Modal =====
function openHistoryModal(lorryId) {
    const backdrop = document.getElementById('historyModalBackdrop');
    const body = document.getElementById('historyModalBody');
    backdrop.classList.add('open');
    body.innerHTML = '<tr><td colspan="4" class="text-muted text-center">Loading...</td></tr>';

    fetch('api/gps_history.php?lorry_id=' + lorryId)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.history || data.history.length === 0) {
                body.innerHTML = '<tr><td colspan="4" class="text-muted text-center">No GPS history available.</td></tr>';
                return;
            }
            body.innerHTML = data.history.map(function (h) {
                const speed = h.speed_kmh !== null ? h.speed_kmh + ' km/h' : '—';
                return '<tr>' +
                    '<td>' + escapeHtml(h.recorded_at) + '</td>' +
                    '<td><code>' + escapeHtml(h.latitude) + '</code></td>' +
                    '<td><code>' + escapeHtml(h.longitude) + '</code></td>' +
                    '<td>' + escapeHtml(speed) + '</td>' +
                    '</tr>';
            }).join('');
        })
        .catch(() => {
            body.innerHTML = '<tr><td colspan="4" class="text-muted text-center">Failed to load history.</td></tr>';
        });
}

function closeHistoryModal() {
    document.getElementById('historyModalBackdrop').classList.remove('open');
}

document.getElementById('historyModalBackdrop').addEventListener('click', function (e) {
    if (e.target === this) closeHistoryModal();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeHistoryModal();
});

// ===== Fit All Trucks =====
function fitMapToTrucks() {
    const trackerMap = window.trackerMap;
    const currentTrucks = window.trackerTrucks || [];
    if (!trackerMap) return;

    const activeTrucks = currentTrucks.filter(t => t.current_lat !== null && t.current_long !== null);
    if (activeTrucks.length === 0) {
        trackerMap.setView([<?php
 echo $defaultLat; ?>, <?php
 echo $defaultLng; ?>], 12);
        return;
    }
    if (activeTrucks.length === 1) {
        trackerMap.setView([parseFloat(activeTrucks[0].current_lat), parseFloat(activeTrucks[0].current_long)], 14);
        return;
    }
    const bounds = L.latLngBounds();
    activeTrucks.forEach(t => bounds.extend([parseFloat(t.current_lat), parseFloat(t.current_long)]));
    trackerMap.fitBounds(bounds, { padding: [40, 40], maxZoom: 15 });
}

// ===== Breadcrumb Trails =====
const breadcrumbLines = {};

function drawBreadcrumbs(truck) {
    const trackerMap = window.trackerMap;
    if (!trackerMap) return;

    const id = String(truck.lorry_id);
    if (breadcrumbLines[id]) {
        trackerMap.removeLayer(breadcrumbLines[id]);
        delete breadcrumbLines[id];
    }
    if (truck.current_lat === null || truck.current_long === null) return;

    fetch('api/gps_history.php?lorry_id=' + truck.lorry_id + '&limit=20')
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.history || data.history.length < 2) return;
            const coords = data.history.map(h => [parseFloat(h.latitude), parseFloat(h.longitude)]);
            coords.push([parseFloat(truck.current_lat), parseFloat(truck.current_long)]);
            const line = L.polyline(coords, { color: '#94a3b8', weight: 2, opacity: 0.5, dashArray: '4,6' }).addTo(trackerMap);
            breadcrumbLines[id] = line;
        })
        .catch(() => {});
}

</script>

<script>
document.getElementById('locationForm')?.addEventListener('submit', async function (event) {
    event.preventDefault();
    const form = this;
    const lorryOption = document.querySelector('#arrivalLorrySelect option:checked');
    if (!lorryOption?.dataset.driverId || lorryOption.dataset.driverId === '0') {
        alert('This lorry has no assigned driver and cannot be dispatched for pickup.');
        return;
    }
    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton) { submitButton.disabled = true; submitButton.textContent = 'Saving...'; }
    try {
        const locationInput = document.getElementById('locationInput');
        const latField = document.getElementById('latField');
        const lngField = document.getElementById('lngField');

        // If the admin typed a full home address without selecting an
        // autocomplete result, geocode it automatically before dispatching.
        if (!latField.value || !lngField.value) {
            const address = locationInput.value.trim();
            if (!address) throw new Error('Please enter an arrival address.');
            if (submitButton) submitButton.textContent = 'Finding address...';

            const geocodeUrl = 'https://nominatim.openstreetmap.org/search?q=' +
                encodeURIComponent(address + ', Malaysia') +
                '&format=json&limit=1&addressdetails=1&countrycodes=my';
            const geocodeResponse = await fetch(geocodeUrl, {
                headers: { 'Accept': 'application/json' }
            });
            const places = await geocodeResponse.json();
            if (!places || !places[0]) {
                throw new Error('Address not found. Include the house number, street, taman, town, and state.');
            }
            latField.value = places[0].lat;
            lngField.value = places[0].lon;
            document.getElementById('locationPreview').textContent =
                '📍 ' + Number(places[0].lat).toFixed(6) + '°N, ' +
                Number(places[0].lon).toFixed(6) + '°E';
        }
        if (submitButton) submitButton.textContent = 'Saving...';
        const response = await fetch('api/dispatch_arrival.php', {
            method: 'POST',
            body: new FormData(form),
            headers: { 'Accept': 'application/json' }
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Could not assign destination.');
        window.location.reload();
    } catch (error) {
        alert(error.message);
        if (submitButton) { submitButton.disabled = false; submitButton.textContent = 'Set Arrival'; }
    }
});

document.getElementById('arrivalLorrySelect')?.addEventListener('change', function () {
    const selected = this.options[this.selectedIndex];
    const driverName = selected?.dataset.driverName || '';
    const target = document.getElementById('assignedDriverName');
    if (target) target.textContent = this.value ? (driverName || 'No driver assigned') : 'Choose a lorry to see its assigned driver';
});
</script>

<?php
 if ($isGpsManager): ?>
<script>
document.getElementById('assignDriverBtn')?.addEventListener('click', function () {
    const panel = document.getElementById('assignDriverPanel');
    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
});
document.getElementById('assignDriverForm')?.addEventListener('submit', async function (event) {
    event.preventDefault();
    try {
        const response = await fetch('api/assign_driver.php', {
            method: 'POST',
            body: new FormData(this),
            headers: { 'Accept': 'application/json' }
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Assignment failed.');
        alert(result.message);
        window.location.reload();
    } catch (error) {
        alert(error.message);
    }
});

const lorryPanel = document.getElementById('manageLorryPanel');
const lorryForm = document.getElementById('lorryManageForm');
const lorryAction = document.getElementById('lorryAction');
const managedLorryId = document.getElementById('managedLorryId');
const saveManagedLorry = document.getElementById('saveManagedLorry');
const resetManagedLorry = document.getElementById('resetManagedLorry');
const managedPlate = document.getElementById('managedPlate');

// Keep Malaysian-style plate input consistent: KDB1234 becomes KDB 1234.
managedPlate?.addEventListener('input', function () {
    const compact = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    this.value = compact.length > 3
        ? compact.slice(0, 3) + ' ' + compact.slice(3)
        : compact;
});

document.getElementById('manageLorryBtn')?.addEventListener('click', function () {
    lorryPanel.style.display = lorryPanel.style.display === 'none' ? 'block' : 'none';
});

function resetLorryForm() {
    lorryForm.reset();
    lorryAction.value = 'create';
    managedLorryId.value = '';
    saveManagedLorry.textContent = 'Create Lorry';
    document.getElementById('managedStatus').value = 'Available';
}

resetManagedLorry?.addEventListener('click', resetLorryForm);

document.querySelectorAll('.edit-lorry-btn').forEach(button => button.addEventListener('click', function () {
    const lorry = JSON.parse(this.dataset.lorry);
    lorryAction.value = 'update';
    managedLorryId.value = lorry.lorry_id || '';
    managedPlate.value = lorry.plate_number || '';
    managedPlate.dispatchEvent(new Event('input'));
    document.getElementById('managedDriver').value = lorry.driver_id || '0';
    document.getElementById('managedStatus').value = lorry.status || 'Available';
    saveManagedLorry.textContent = 'Update Lorry';
    lorryPanel.style.display = 'block';
    lorryForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
}));

lorryForm?.addEventListener('submit', async function (event) {
    event.preventDefault();
    saveManagedLorry.disabled = true;
    try {
        const response = await fetch('api/lorry_manage.php', { method: 'POST', body: new FormData(lorryForm), headers: { Accept: 'application/json' } });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Lorry save failed.');
        window.location.reload();
    } catch (error) {
        alert(error.message);
        saveManagedLorry.disabled = false;
    }
});

document.querySelectorAll('.delete-lorry-btn').forEach(button => button.addEventListener('click', async function () {
    if (!confirm('Delete this lorry? This cannot be undone.')) return;
    const body = new FormData();
    body.append('action', 'delete');
    body.append('lorry_id', this.dataset.lorryId);
    body.append('csrf_token', <?php
 echo json_encode($csrfToken); ?>);
    try {
        const response = await fetch('api/lorry_manage.php', { method: 'POST', body, headers: { Accept: 'application/json' } });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Lorry deletion failed.');
        window.location.reload();
    } catch (error) {
        alert(error.message);
    }
}));
</script>
<?php
 endif; ?>

<!-- GPS History Modal -->
<div class="history-modal-backdrop" id="historyModalBackdrop">
    <div class="history-modal">
        <div class="history-modal-header">
            <h6 class="fw-bold mb-0" style="color: var(--nav-bg);">GPS History</h6>
            <button class="btn-close-custom" onclick="closeHistoryModal()">&times;</button>
        </div>
        <div class="history-modal-body">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Latitude</th>
                        <th>Longitude</th>
                        <th>Speed</th>
                    </tr>
                </thead>
                <tbody id="historyModalBody">
                    <tr><td colspan="4" class="text-muted text-center">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
 include 'footer.php'; ?>
