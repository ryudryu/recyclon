<?php
require_once __DIR__ . '/config/session.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
    header('Location: auth/login.php');
    exit;
}
ob_start();
$page = basename(__FILE__);
include 'header.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$csrfToken = ensure_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf_token()) {
    http_response_code(419);
    exit('Invalid or expired form token. Please refresh and try again.');
}
?>

<style>
.queue-card {
    background: #fff;
    border: 1px solid #e5eaf2;
    border-radius: 14px;
    padding: 1rem 1.25rem;
    margin-bottom: 0.75rem;
    transition: all 0.2s ease;
    box-shadow: 0 2px 10px rgba(15,23,42,0.04);
}
.queue-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 24px rgba(15,23,42,0.08);
    border-color: #c7d7f2;
}
.queue-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.03em;
}
.queue-badge.ongoing { background: #fef3c7; color: #92400e; }
.queue-badge.pending { background: #fef3c7; color: #92400e; }
.queue-badge.completed { background: #dcfce7; color: #166534; }
.queue-badge.cancelled { background: #fee2e2; color: #991b1b; }
.queue-meta {
    font-size: 0.8rem;
    color: #64748b;
    font-weight: 600;
}
.queue-location {
    font-weight: 700;
    color: #0f172a;
    font-size: 0.9rem;
    margin-top: 0.35rem;
}
.table-custom thead th {
    background: #f5f8fc;
    color: #64748b;
    border-bottom: 1px solid #e5eaf2;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-weight: 850;
    padding: 0.85rem 1rem;
    white-space: nowrap;
}
.table-custom tbody td {
    color: #334155;
    border-color: #edf1f6;
    padding: 0.9rem 1rem;
    font-size: 0.84rem;
    vertical-align: middle;
}
.table-custom tbody tr {
    transition: background 0.15s ease;
}
.table-custom tbody tr:hover {
    background: #f8fbff;
}
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 800;
}
.status-pill.ongoing { background: #fef3c7; color: #92400e; }
.status-pill.pending { background: #fef3c7; color: #92400e; }
.status-pill.completed { background: #dcfce7; color: #166534; }
.status-pill.cancelled { background: #fee2e2; color: #991b1b; }
.status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: currentColor;
}
.btn-action {
    border-radius: 8px;
    padding: 0.35rem 0.7rem;
    font-size: 0.75rem;
    font-weight: 700;
}
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #94a3b8;
}
.empty-state svg {
    width: 48px;
    height: 48px;
    margin-bottom: 1rem;
    opacity: 0.5;
}
.refresh-note {
    font-size: 0.72rem;
    color: #94a3b8;
    font-weight: 600;
}
.page-title > div:first-child > a.btn-outline-secondary {
    display: none;
}
.page-title h3 {
    color: #0f172a;
    font-size: 2.5rem;
    font-weight: 700;
}
@media (max-width: 768px) {
    .queue-card { padding: 0.85rem; }
    .table-custom thead th,
    .table-custom tbody td { padding: 0.6rem 0.5rem; font-size: 0.78rem; }
}
</style>

<link rel="stylesheet" href="assets/css/gps_tracker.css">

<?php

$staffId = isset($_GET['staff_id']) ? (int)$_GET['staff_id'] : 0;
$statusFilter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
if (!in_array($statusFilter, ['all','pending','ongoing','completed','cancelled'], true)) {
    $statusFilter = 'all';
}
// History is the completed-history view; live ongoing items are shown on GPS Tracker.
$statusFilter = 'completed';
$staffName = '';
$rows = [];
$errors = [];
$queueExists = false;
$queueCols = [];

try {
    if ($staffId > 0) {
        $stmt = $conn->prepare("SELECT name FROM users WHERE user_id = ? LIMIT 1");
        $stmt->execute([$staffId]);
        $staffName = $stmt->fetchColumn() ?: '';
    }

    try { $queueExists = app_table_exists($conn, 'arrival_queue'); } catch (Exception $ex) { $queueExists = false; }
    if ($queueExists) {
        $queueCols = app_table_columns($conn, 'arrival_queue');

        $params = [];
        $where = [];
        if ($staffId > 0) {
            if (in_array('driver_id', $queueCols)) {
                $where[] = "q.driver_id = ?";
                $params[] = $staffId;
            } elseif (in_array('driver_name', $queueCols)) {
                $where[] = "q.driver_name = ?";
                $params[] = $staffName;
            }
        }
        if ($statusFilter !== 'all') {
            $where[] = "q.status = ?";
            $params[] = $statusFilter;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        if (in_array('driver_id', $queueCols) || in_array('driver_name', $queueCols)) {
            // Select sensible columns, adapt to simplified schema
            $selectCols = "q.*";
            if (in_array('plate_number', $queueCols)) { $selectCols .= ", q.plate_number"; }
            if (in_array('geolocation', $queueCols)) { $selectCols .= ", q.geolocation"; }
            if (in_array('location_name', $queueCols)) { $selectCols .= ", q.location_name"; }

            $order = "ORDER BY (q.status = 'ongoing') DESC, CASE q.status WHEN 'ongoing' THEN 1 WHEN 'pending' THEN 2 WHEN 'cancelled' THEN 3 WHEN 'completed' THEN 4 ELSE 5 END, q.created_at DESC";

            $sql = "SELECT {$selectCols} FROM arrival_queue q {$whereSql} {$order}";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    // ignore for display, present friendly message
}
?>

<div class="page-body">
    <?php
 include 'sidebar.php'; ?>
    <section class="content-panel">
    <?php
 foreach ($errors as $error): ?>
        <div class="alert alert-danger mb-3"><?php
 echo htmlspecialchars($error); ?></div>
    <?php
 endforeach; ?>
    <div class="page-title mb-4 d-flex align-items-start justify-content-between flex-wrap gap-3">
        <div>
            <a href="gps_tracker.php" class="btn btn-sm btn-outline-secondary mb-2">← Back to Tracker</a>
            <h3 class="mb-0">Completed History</h3>
            <?php
 if ($staffName): ?>
                <p class="text-muted mb-0">Showing queued destinations for <strong><?php
 echo htmlspecialchars($staffName); ?></strong></p>
            <?php
 endif; ?>
        </div>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <span class="refresh-note">Auto-refreshes every 15s</span>
            <a href="gps_tracker.php" class="btn fw-bold" style="background: var(--nav-bg); color: #fff; border-radius: 10px; white-space: nowrap;">Back To GPS→</a>
        </div>
    </div>

    <?php
 if (!$queueExists): ?>
        <div class="alert alert-warning">arrival_queue table not found. No queued destinations available.</div>
    <?php
 else: ?>
        <div class="mb-3">
            <span class="status-pill completed"><span class="status-dot"></span>Completed destinations</span>
        </div>

        <?php
 if (isset($_GET['action']) && $_GET['action'] === 'edit' && !empty($_GET['id'])):
            $editId = (int)$_GET['id'];
            $editRow = null;
            try {
                $cols = app_table_columns($conn, 'arrival_queue');
                $editSql = "SELECT * FROM arrival_queue WHERE id = ?";
                $editParams = [$editId];
                if ($staffId > 0) {
                    if (in_array('driver_id', $cols)) {
                        $editSql .= " AND driver_id = ?";
                        $editParams[] = $staffId;
                    } elseif (in_array('driver_name', $cols) && $staffName !== '') {
                        $editSql .= " AND driver_name = ?";
                        $editParams[] = $staffName;
                    }
                }
                $editSql .= " LIMIT 1";
                $q = $conn->prepare($editSql);
                $q->execute($editParams);
                $editRow = $q->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) { /* ignore */ }
        ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="mb-3">Edit Queue Item #<?php
 echo $editId; ?></h5>
                    <?php
 if (empty($editRow)): ?>
                        <div class="alert alert-warning">Queue item not found.</div>
                        <a href="history.php?staff_id=<?php
 echo $staffId; ?>" class="btn btn-sm btn-outline-secondary">Back</a>
                    <?php
 else: ?>
                        <form method="post" id="historyEditForm">
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="csrf_token" value="<?php
 echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="id" value="<?php
 echo (int)$editId; ?>">
                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="pending" <?php
 echo (strtolower($editRow['status'] ?? '')==='pending')? 'selected' : ''; ?>>Pending</option>
                                    <option value="ongoing" <?php
 echo (strtolower($editRow['status'] ?? '')==='ongoing')? 'selected' : ''; ?>>Ongoing</option>
                                    <option value="completed" <?php
 echo (strtolower($editRow['status'] ?? '')==='completed')? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php
 echo (strtolower($editRow['status'] ?? '')==='cancelled')? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <?php
 if (in_array('location_name', $queueCols) || (in_array('latitude', $queueCols) && in_array('longitude', $queueCols))): ?>
                                <div class="mb-3">
                                    <label class="form-label">Location name</label>
                                    <input type="text" name="location_name" class="form-control" value="<?php
 echo htmlspecialchars($editRow['location_name'] ?? ''); ?>">
                                </div>
                                <div class="row g-2 mb-3">
                                    <div class="col-6"><input type="text" name="latitude" class="form-control" placeholder="Latitude" value="<?php
 echo htmlspecialchars($editRow['latitude'] ?? ''); ?>"></div>
                                    <div class="col-6"><input type="text" name="longitude" class="form-control" placeholder="Longitude" value="<?php
 echo htmlspecialchars($editRow['longitude'] ?? ''); ?>"></div>
                                </div>
                            <?php
 elseif (in_array('geolocation', $queueCols)): ?>
                                <div class="mb-3">
                                    <label class="form-label">Geolocation</label>
                                    <input type="text" name="geolocation" class="form-control" value="<?php
 echo htmlspecialchars($editRow['geolocation'] ?? ''); ?>">
                                </div>
                            <?php
 endif; ?>
                            <?php
 if (in_array('plate_number', $queueCols)): ?>
                                <div class="mb-3">
                                    <label class="form-label">Plate number</label>
                                    <input type="text" name="plate_number" class="form-control" value="<?php
 echo htmlspecialchars($editRow['plate_number'] ?? ''); ?>">
                                </div>
                            <?php
 endif; ?>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                <a href="history.php?staff_id=<?php
 echo $staffId; ?>" class="btn btn-sm btn-outline-secondary">Cancel</a>
                            </div>
                        </form>
                    <?php
 endif; ?>
                </div>
            </div>
        <?php
 else: ?>
            <?php
 if (empty($rows)): ?>
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    <div>No queued destinations found for this filter.</div>
                </div>
            <?php
 else: ?>
                <div class="table-responsive">
                    <table class="table table-custom align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Plate</th>
                                <th>Location</th>
                                <th>Created</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
 foreach ($rows as $r): ?>
                                <tr>
                                    <td class="fw-bold">#<?php
 echo (int)($r['id'] ?? 0); ?></td>
                                    <td><?php
 echo htmlspecialchars($r['plate_number'] ?? $r['assigned_plate'] ?? ''); ?></td>
                                    <td><?php

$location = $r['location_name'] ?? $r['geolocation'] ?? '';
if ($location === '' && isset($r['latitude'], $r['longitude']) && $r['latitude'] !== null && $r['longitude'] !== null) {
    $location = number_format((float)$r['latitude'], 6) . ', ' . number_format((float)$r['longitude'], 6);
}
echo htmlspecialchars($location);
?></td>
                                    <td><?php
 echo htmlspecialchars($r['created_at'] ?? ''); ?></td>
                                    <td>
                                        <span class="status-pill <?php
 echo in_array(strtolower((string)($r['status'] ?? '')), ['pending','ongoing','completed','cancelled'], true) ? strtolower((string)$r['status']) : 'pending'; ?>">
                                            <span class="status-dot" style="width:7px;height:7px;border-radius:50%;background:currentColor;"></span>
                                            <?php
 echo htmlspecialchars(ucfirst((string)($r['status'] ?? ''))); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="history.php?staff_id=<?php
 echo $staffId; ?>&action=edit&id=<?php
 echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-primary btn-action">Edit</a>
                                    </td>
                                </tr>
                            <?php
 endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php
 endif; ?>
        <?php
 endif; ?>
    <?php
 endif; ?>
    </section>
</div>

<script>
setTimeout(function() {
    if (!document.hidden) {
        location.reload();
    }
}, 15000);
</script>

<script>
document.getElementById('historyEditForm')?.addEventListener('submit', async function (event) {
    event.preventDefault();
    const form = this;
    const payload = {};
    new FormData(form).forEach((value, key) => {
        if (key !== 'action') payload[key] = value;
    });
    try {
        const response = await fetch('api/arrival_queue.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Could not save queue item.');
        window.location.href = 'history.php?staff_id=<?php
 echo $staffId; ?>';
    } catch (error) {
        alert(error.message);
    }
});
</script>

<?php
 include 'footer.php';
