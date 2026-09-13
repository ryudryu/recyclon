<?php
require_once __DIR__ . '/config/session.php';


session_start();
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
    header('Location: auth/login.php');
    exit;
}
$csrfToken = ensure_csrf_token();
$page = basename(__FILE__);
include 'header.php';
?>
<link rel="stylesheet" href="assets/css/gps_tracker.css">

<div class="page-body">
    <?php
 include 'sidebar.php'; ?>
    <section class="content-panel">
        <div class="page-title mb-4 d-flex align-items-start justify-content-between flex-wrap gap-3">
            <div>
                <h1 class="display-6 fw-bold">Queue Management</h1>
                <p class="text-secondary mb-0">Manage pending, active, completed, and cancelled arrival requests.</p>
            </div>
            <a href="gps_tracker.php" class="btn fw-bold" style="background:var(--nav-bg);color:#fff;border-radius:10px;">Back To GPS →</a>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-md-4">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <div class="btn-group flex-wrap" role="group" aria-label="Queue status filter">
                        <button type="button" class="btn btn-sm btn-outline-secondary queue-filter active" data-status="">All</button>
                        <button type="button" class="btn btn-sm btn-outline-warning queue-filter" data-status="pending">Pending</button>
                        <button type="button" class="btn btn-sm btn-outline-primary queue-filter" data-status="ongoing">Ongoing</button>
                        <button type="button" class="btn btn-sm btn-outline-success queue-filter" data-status="completed">Completed</button>
                        <button type="button" class="btn btn-sm btn-outline-danger queue-filter" data-status="cancelled">Cancelled</button>
                    </div>
                    <span id="queueStatus" class="refresh-note">Loading queue…</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>ID</th><th>Driver</th><th>Plate</th><th>Location</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
                        <tbody id="queueTableBody"><tr><td colspan="7" class="text-center text-muted py-4">Loading…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
(() => {
    const body = document.getElementById('queueTableBody');
    const statusText = document.getElementById('queueStatus');
    let currentStatus = '';
    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
    const locationOf = row => row.location_name || row.geolocation || (row.latitude != null && row.longitude != null ? `${row.latitude}, ${row.longitude}` : '—');

    async function loadQueue() {
        statusText.textContent = 'Loading queue…';
        try {
            const url = 'api/arrival_queue.php' + (currentStatus ? '?status=' + encodeURIComponent(currentStatus) : '');
            const response = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load queue.');
            const rows = result.data || [];
            body.innerHTML = rows.length ? rows.map(row => {
                const status = String(row.status || '').toLowerCase();
                let actions = '—';
                if (status === 'pending') actions = `<button class="btn btn-sm btn-outline-primary queue-action" data-id="${row.id}" data-status="ongoing">Start</button>`;
                if (status === 'ongoing') actions = `<div class="d-flex gap-1"><button class="btn btn-sm btn-outline-success queue-action" data-id="${row.id}" data-status="completed">Complete</button><button class="btn btn-sm btn-outline-danger queue-action" data-id="${row.id}" data-status="cancelled">Cancel</button></div>`;
                const lat = row.latitude ?? '';
                const lng = row.longitude ?? '';
                return `<tr><td class="fw-bold">#${escapeHtml(row.id)}</td><td>${escapeHtml(row.driver_name || 'Unassigned')}</td><td>${escapeHtml(row.assigned_plate || row.plate_number || '—')}</td><td class="queue-location-cell" data-lat="${escapeHtml(lat)}" data-lng="${escapeHtml(lng)}">${escapeHtml(locationOf(row))}</td><td><span class="status-pill ${escapeHtml(status)}"><span class="status-dot"></span>${escapeHtml(status.charAt(0).toUpperCase() + status.slice(1))}</span></td><td>${escapeHtml(row.created_at || '—')}</td><td>${actions}</td></tr>`;
            }).join('') : '<tr><td colspan="7" class="text-center text-muted py-4">No queue items found.</td></tr>';
            statusText.textContent = `${rows.length} item${rows.length === 1 ? '' : 's'}`;
        } catch (error) {
            body.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-4">${escapeHtml(error.message)}</td></tr>`;
            statusText.textContent = 'Queue unavailable';
        }
    }

    document.querySelectorAll('.queue-filter').forEach(button => button.addEventListener('click', () => {
        document.querySelectorAll('.queue-filter').forEach(item => item.classList.remove('active'));
        button.classList.add('active');
        currentStatus = button.dataset.status || '';
        loadQueue();
    }));
    body.addEventListener('click', async event => {
        const button = event.target.closest('.queue-action');
        if (!button) return;
        button.disabled = true;
        try {
            const response = await fetch('api/arrival_queue.php', { method: 'PUT', headers: {'Content-Type':'application/json','Accept':'application/json'}, body: JSON.stringify({id: button.dataset.id, status: button.dataset.status, csrf_token: <?= json_encode($csrfToken) ?>}) });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Update failed.');
            loadQueue();
        } catch (error) { alert(error.message); button.disabled = false; }
    });
    loadQueue();
    setInterval(() => {
        if (document.visibilityState !== 'hidden') loadQueue();
    }, 3000);
})();
</script>
<?php
 include 'footer.php'; ?>
