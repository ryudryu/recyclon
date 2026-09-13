<?php
require_once __DIR__ . '/config/session.php';


session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

if ($_SESSION['role'] !== "Customer") {
    header("Location: auth/login.php");
    exit();
}

$page = 'dashboard_cus.php';
include "header.php";

$userId = (int) $_SESSION['user_id'];

/*
MONTHLY FILTER
*/

$selectedMonth = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// Validate month format
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

// Create start and end date
$monthStart = $selectedMonth . '-01';
$monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));

// Display month
$displayMonth = date('F Y', strtotime($monthStart));

// Customer-specific stats
$myBookings = [];
$totalBookings = 0;
$completedBookings = 0;
$pendingBookings = 0;
$totalSpent = 0;
$totalRecycled = 0;
$recentActivity = [];

try {

    /*
    | TOTAL BOOKINGS - SELECTED MONTH
    */
    $stmt = $conn->prepare(
        "SELECT COUNT(*)
         FROM booking
         WHERE customer_id = ?
         AND booking_date >= ?
         AND booking_date < ?"
    );
    $stmt->execute([$userId, $monthStart, $monthEnd]);
    $totalBookings = (int) $stmt->fetchColumn();

    /*
    | COMPLETED BOOKINGS - SELECTED MONTH
    */
    $stmt = $conn->prepare(
        "SELECT COUNT(*)
         FROM booking
         WHERE customer_id = ?
         AND status = 'Completed'
         AND booking_date >= ?
         AND booking_date < ?"
    );
    $stmt->execute([$userId, $monthStart, $monthEnd]);
    $completedBookings = (int) $stmt->fetchColumn();

    /*
    | PENDING BOOKINGS - SELECTED MONTH
    */
    $stmt = $conn->prepare(
        "SELECT COUNT(*)
         FROM booking
         WHERE customer_id = ?
         AND status = 'Pending'
         AND booking_date >= ?
         AND booking_date < ?"
    );
    $stmt->execute([$userId, $monthStart, $monthEnd]);
    $pendingBookings = (int) $stmt->fetchColumn();

    /*
    | EARNED - SELECTED MONTH
    */
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(s.total_amount), 0)
         FROM sales s
         JOIN booking b ON b.booking_id = s.booking_id
         WHERE s.customer_id = ?
         AND s.payment_status = 'Paid'
         AND b.status = 'Completed'
         AND b.booking_date >= ?
         AND b.booking_date < ?"
    );
    $stmt->execute([$userId, $monthStart, $monthEnd]);
    $totalSpent = (float) $stmt->fetchColumn();

    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(si.weight_kg), 0)
         FROM sales s
         JOIN sale_items si ON si.sale_id = s.sale_id
         JOIN booking b ON b.booking_id = s.booking_id
         WHERE s.customer_id = ?
         AND s.payment_status = 'Paid'
         AND b.status = 'Completed'
         AND b.booking_date >= ?
         AND b.booking_date < ?"
    );
    $stmt->execute([$userId, $monthStart, $monthEnd]);
    $totalRecycled = (float) $stmt->fetchColumn();

    /*
    | RECENT BOOKINGS
    */
    $stmt = $conn->prepare(
        "SELECT b.booking_id,
                b.status,
                b.booking_date,
                b.pickup_date,
                b.scheduled_date,
                b.address,
                COALESCE(u.name, 'Not assigned yet') AS driver_name,
                MAX(CASE
                    WHEN s.payment_status = 'Paid'
                     AND s.total_amount IS NOT NULL
                     AND si.item_id IS NOT NULL
                     AND si.weight_kg > 0
                    THEN s.sale_id
                END) AS receipt_sale_id,
                MAX(CASE
                    WHEN s.payment_status = 'Paid'
                     AND s.total_amount IS NOT NULL
                     AND si.item_id IS NOT NULL
                     AND si.weight_kg > 0
                    THEN s.total_amount
                END) AS total_amount,
                COALESCE(string_agg(DISTINCT wc.category_name, ', ' ORDER BY wc.category_name), 'Materials') AS categories,
                COALESCE(SUM(si.weight_kg), 0) AS weight_kg
         FROM booking b
         LEFT JOIN sales s ON s.booking_id = b.booking_id
         LEFT JOIN sale_items si ON si.sale_id = s.sale_id
         LEFT JOIN waste_categories wc ON wc.waste_id = si.waste_id
         LEFT JOIN users u ON u.user_id = b.driver_id
         WHERE b.customer_id = ?
         AND b.booking_date >= ?
         AND b.booking_date < ?
         GROUP BY b.booking_id, b.status, b.booking_date, b.pickup_date, b.scheduled_date, b.address, u.name
         ORDER BY MAX(b.created_at) DESC
         LIMIT 5"
    );
    $stmt->execute([$userId, $monthStart, $monthEnd]);
    $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Keep page usable if a query fails
}

// Live prices
$livePrices = [];

try {
    $livePrices = $conn->query(
        "SELECT category_name, unit_price, unit
         FROM waste_categories
         WHERE status = 'Active'
         ORDER BY waste_id
         LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}

?>

<style>
    .content-panel {
        background: #f3fbf6 !important;
    }

    .metric-card,
    .panel {
        background: rgba(255, 255, 255, .92) !important;
        border: 1px solid #d1fae5 !important;
        box-shadow: 0 8px 24px rgba(6, 78, 59, .06) !important;
    }

    .metric-card:hover,
    .panel:hover {
        box-shadow: 0 10px 28px rgba(6, 78, 59, .1) !important;
    }

    .btn-primary-custom {
        background: #047857 !important;
        color: #fff !important;
        border: none !important;
        border-radius: 10px !important;
        padding: 0.6rem 1.5rem !important;
        font-weight: 700 !important;
        box-shadow: 0 4px 12px rgba(21, 128, 61, 0.25) !important;
    }

    .btn-primary-custom:hover {
        transform: translateY(-1px) !important;
        box-shadow: 0 6px 20px rgba(21, 128, 61, 0.3) !important;
    }

    .btn-outline-custom {
        border: 2px solid #34d399 !important;
        color: #047857 !important;
        border-radius: 10px !important;
        font-weight: 700 !important;
        background: transparent !important;
    }

    .btn-outline-custom:hover {
        background: #f0fdf4 !important;
    }

    .welcome-banner {
        background: linear-gradient(135deg, #d1fae5 0%, #ecfdf5 100%) !important;
        border-radius: 18px !important;
        padding: 1.25rem 1.5rem !important;
        color: #064e3b !important;
        box-shadow: 0 8px 30px rgba(6, 78, 59, 0.1) !important;
        margin-bottom: 1.5rem !important;
    }

    .welcome-banner h1 {
        font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        letter-spacing: -0.025em;
        font-weight: 800;
        color: #065f46 !important;
    }

    .welcome-banner p {
        color: #047857 !important;
    }

    .customer-profile-card {
        transition: opacity .2s ease, transform .2s ease, box-shadow .2s ease;
    }

    .customer-profile-card:hover {
        opacity: .55;
        transform: translateY(-1px);
    }

    .welcome-icon {
        width: 64px;
        height: 64px;
        background: rgba(255, 255, 255, 0.65);
        color: #047857;
        border-radius: 999px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
    }

    /*
    |--------------------------------------------------------------------------
    | MONTH SELECTOR
    |--------------------------------------------------------------------------
    */

    .month-filter {
        background: white;
        border: 1px solid #d1fae5;
        border-radius: 14px;
        padding: 15px 18px;
        margin-bottom: 20px;
        box-shadow: 0 5px 18px rgba(6, 78, 59, .05);
    }

    .month-filter label {
        color: #047857;
        font-weight: 700;
        margin-bottom: 5px;
    }

    .month-filter input {
        border: 2px solid #a7f3d0;
        border-radius: 10px;
        padding: 8px 12px;
        color: #065f46;
        font-weight: 600;
    }

    .month-filter input:focus {
        border-color: #059669;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, .15);
        outline: none;
    }

    .customer-dashboard-heading { color: #064e3b; font-weight: 800; letter-spacing: -.02em; }
    .impact-card {
        border: 1px solid #d1fae5;
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 7px 20px rgba(6, 78, 59, .05);
    }
    .impact-card .eyebrow { color: #059669; font-size: .72rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
    .impact-card { padding: 1.25rem; background: #f0fdf4; }
    .impact-number { color: #166534; font-size: 1.55rem; font-weight: 800; }
    .impact-label { color: #64756c; font-size: .82rem; }
    .impact-bar { height: 8px; overflow: hidden; border-radius: 999px; background: #d1fae5; }
    .impact-bar span { display: block; width: 100%; height: 100%; border-radius: inherit; background: #22c55e; }
    .activity-item { border: 1px solid #d1fae5; border-radius: 12px; background: #fff; }
    .activity-meta { color: #71847a; font-size: .82rem; }
    .booking-history-panel { height: auto !important; min-height: 0 !important; }
    .booking-history-list { padding-bottom: .25rem; }
    .booking-history-item { align-items: flex-start !important; gap: 1rem; }
    .booking-history-item > :first-child { min-width: 0; flex: 1 1 auto; }
    .booking-history-summary { flex: 0 0 auto; min-width: 120px; }
    .booking-history-empty { padding-block: 1.5rem !important; }
    .metric-label { display: flex; align-items: center; gap: .45rem; }
    .metric-icon { width: 18px; height: 18px; color: #059669; }
    @media (max-width: 575.98px) {
        .welcome-banner { align-items: flex-start !important; }
        .welcome-icon { width: 48px; height: 48px; flex: 0 0 48px; }
        .welcome-banner h1 { font-size: 1.65rem; }
        .booking-history-item { flex-direction: column; gap: .75rem; }
        .booking-history-summary { width: 100%; min-width: 0; text-align: left !important; }
        .booking-history-summary .badge-status-pending,
        .booking-history-summary .badge-status-confirmed,
        .booking-history-summary .badge-status-completed,
        .booking-history-summary .badge-status-cancelled { display: inline-block; }
    }
</style>


<div class="page-body">

    <?php
    include "sidebar.php"; ?>

    <section class="content-panel px-4 py-4">

        <!-- Welcome Banner -->

        <div class="welcome-banner d-flex align-items-center gap-4">

            <div class="welcome-icon" aria-hidden="true">
                <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m9 5 3-2 3 2"/><path d="M12 3v6"/><path d="m19 9 2 3-2 3"/><path d="M21 12h-6"/><path d="m5 15-2-3 2-3"/><path d="M3 12h6"/></svg>
            </div>

            <div>
                <h1 class="display-6 mb-1">
                    Welcome back, <?php
                                    echo htmlspecialchars($_SESSION['name']); ?>
                </h1>
                <p class="mb-0">
                    <?php
                    echo date('l, j F Y'); ?> · Track your recycling impact and manage pickups
                </p>
            </div>

        </div>



        <!-- ============================================================
             MONTH SELECTOR
        ============================================================ -->

        <div class="month-filter">

            <form method="GET" action="dashboard_cus.php" class="d-flex flex-wrap align-items-end gap-3">

                <div>
                    <label for="month" class="form-label">MONTHLY SUMMARY</label>
                    <div class="small text-muted">
                        Select a month to view your booking and recycling totals
                    </div>
                </div>

                <div>
                    <input
                        type="month"
                        id="month"
                        name="month"
                        value="<?php
                                echo htmlspecialchars($selectedMonth); ?>"
                        class="form-control"
                        onchange="this.form.submit()">
                </div>

                    <button type="submit" class="btn btn-primary">Apply</button>

                

            </form>

        </div>


        <!-- ============================================================
             MONTHLY METRIC CARDS
        ============================================================ -->

        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3 mb-4">

            <!-- TOTAL BOOKINGS -->
            <div class="col">
                <article class="metric-card card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="metric-label"><svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/></svg> TOTAL BOOKINGS</div>
                        <div class="metric-value"><?php
                                                    echo $totalBookings; ?></div>
                        <div class="metric-note"><?php
                                                    echo htmlspecialchars($displayMonth); ?></div>
                    </div>
                </article>
            </div>

            <!-- COMPLETED -->
            <div class="col">
                <article class="metric-card card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="metric-label"><svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg> COMPLETED</div>
                        <div class="metric-value metric-value--green"><?php
                                                                        echo $completedBookings; ?></div>
                        <div class="metric-note">Completed in <?php
                                                                echo htmlspecialchars($displayMonth); ?></div>
                    </div>
                </article>
            </div>

            <!-- RECYCLED -->
            <div class="col">
                <article class="metric-card card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="metric-label"><svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 5 3-2 3 2"/><path d="M12 3v6"/><path d="m19 9 2 3-2 3"/><path d="M21 12h-6"/><path d="m5 15-2-3 2-3"/><path d="M3 12h6"/></svg> RECYCLED</div>
                        <div class="metric-value metric-value--green"><?php
                                                                        echo number_format($totalRecycled, 1); ?> kg</div>
                        <div class="metric-note">Recycled in <?php
                                                                echo htmlspecialchars($displayMonth); ?></div>
                    </div>
                </article>
            </div>

            <!-- EARNED -->
            <div class="col">
                <article class="metric-card card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="metric-label"><svg class="metric-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 6v12M15 9.5c0-1.5-1.3-2.5-3-2.5s-3 1-3 2.3c0 3 6 1.3 6 4.3 0 1.4-1.3 2.4-3 2.4s-3-1-3-2.5"/></svg> EARNED</div>
                        <div class="metric-value metric-value--orange">RM <?php
                                                                            echo number_format($totalSpent, 2); ?></div>
                        <div class="metric-note">Earned in <?php
                                                            echo htmlspecialchars($displayMonth); ?></div>
                    </div>
                </article>
            </div>

        </div>

        <div class="impact-card mb-4">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-4">
                <div>
                    <div class="eyebrow">Your recycling impact</div>
                    <h2 class="h5 mt-2 mb-2 customer-dashboard-heading">Every completed pickup helps keep materials in motion.</h2>
                    <p class="small text-muted mb-0">Your totals are based on completed, paid recycling activity for <?= htmlspecialchars($displayMonth) ?>.</p>
                </div>
                <div class="row row-cols-2 g-3 flex-grow-1" style="max-width: 430px;">
                    <div class="col"><div class="impact-number"><?= number_format($totalRecycled, 1) ?> kg</div><div class="impact-label">Recycled this month</div><div class="impact-bar mt-2"><span></span></div></div>
                    <div class="col"><div class="impact-number"><?= $completedBookings ?></div><div class="impact-label">Pickups completed this month</div><div class="impact-bar mt-2"><span></span></div></div>
                </div>
            </div>
        </div>

        <div class="row g-3">

            <!-- ESTIMATED PRICES -->
            <div class="col-12 col-lg-5">
                <div class="panel card h-100 border-0 shadow-sm">
                    <div class="card-body">

                        <div class="panel-header">
                            <h2 class="h6 mb-0" style="color: #047857;">ESTIMATE PRICE</h2>
                        </div>

                        <p class="text-muted small mb-3">
                            Estimated value per kg of recyclable waste
                        </p>

                        <div class="list-group list-group-flush">

                            <?php
                            if (!empty($livePrices)): ?>

                                <?php
                                foreach ($livePrices as $price): $meta = wasteMeta($price['category_name']); ?>

                                    <div class="d-flex justify-content-between align-items-center py-2 px-3 rounded-3 mb-2" style="background: <?php
                                                                                                                                                echo $meta['bg']; ?>;">
                                        <span style="font-weight: 600; color: <?php
                                                                                echo $meta['color']; ?>;">
                                            <?php
                                            echo $meta['icon']; ?> <?php
                                                                    echo htmlspecialchars($price['category_name']); ?>
                                        </span>
                                        <span style="font-weight: 700; color: #047857;">
                                            RM <?php
                                                echo number_format($price['unit_price'], 2); ?> / <?php
                                                                                                    echo htmlspecialchars($price['unit']); ?>
                                        </span>
                                    </div>

                                <?php
                                endforeach; ?>

                            <?php
                            else: ?>

                                <div class="text-muted small py-3">Pricing data loading...</div>

                            <?php
                            endif; ?>

                        </div>

                    </div>
                </div>
            </div>


            <!-- RECENT ACTIVITY -->
            <div class="col-12 col-lg-7">
                <div class="panel booking-history-panel card border-0 shadow-sm">
                    <div class="card-body">

                        <div class="panel-header">
                            <h2 class="h6 mb-0" style="color: #047857;">MY BOOKINGS HISTORY</h2>
                        </div>

                        <div class="list-group list-group-flush mt-2 booking-history-list">

                            <?php
                            if (!empty($recentActivity)): ?>

                                <?php
                                foreach ($recentActivity as $job): ?>

                                    <div class="activity-item booking-history-item d-flex justify-content-between py-3 px-3 mb-2">

                                        <div>
                                            <strong style="color: #92400e;">
                                                BK-<?php
                                                    echo str_pad($job['booking_id'], 4, '0', STR_PAD_LEFT); ?>
                                            </strong>
                                            <div class="activity-meta"><?php echo htmlspecialchars($job['categories'] ?? 'Materials'); ?> · <?php echo number_format((float)($job['weight_kg'] ?? 0), 1); ?> kg</div>
                                            <div class="activity-meta"><?php echo htmlspecialchars($job['address']); ?></div>
                                            <div class="small" style="color: #92400e;">
                                                <?php
                                                echo date('d M Y', strtotime($job['booking_date'])); ?>
                                            </div>
                                        </div>

                                        <div class="booking-history-summary text-end">

                                            <?php
                                            $receiptAvailable = bookingStatusLabel($job['status']) === 'Completed'
                                                && (int)($job['receipt_sale_id'] ?? 0) > 0
                                                && $job['total_amount'] !== null;
                                            if ($receiptAvailable): ?>
                                                <div class="fw-bold" style="color: #047857;">
                                                    RM <?php
                                                        echo number_format($job['total_amount'], 2); ?>
                                                </div>
                                            <?php
                                            endif; ?>

                                            <span class="<?php
                                                            echo statusBadgeClass($job['status']); ?>">
                                                <?php
                                                echo bookingStatusLabel($job['status']); ?>
                                            </span>

                                            <?php if ($receiptAvailable): ?>
                                                <a href="receipt.php?id=<?= (int)$job['receipt_sale_id'] ?>"
                                                   class="btn btn-sm btn-outline-success mt-2">View Receipt</a>
                                            <?php endif; ?>

                                        </div>

                                    </div>

                                <?php
                                endforeach; ?>

                            <?php
                            else: ?>

                                <div class="booking-history-empty text-center">
                                    <div class="mb-3 text-success" aria-hidden="true">
                                        <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="m9 5 3-2 3 2"/><path d="M12 3v6"/><path d="m19 9 2 3-2 3"/><path d="M21 12h-6"/><path d="m5 15-2-3 2-3"/><path d="M3 12h6"/></svg>
                                    </div>
                                    <p class="text-muted mb-1">No booking history yet</p>
                                    <div class="small text-muted mb-3">Your completed and upcoming pickups will appear here.</div>
                                    <a href="new_booking.php" class="btn btn-primary-custom">Schedule a Pickup</a>
                                </div>

                            <?php
                            endif; ?>

                        </div>

                    </div>
                </div>
            </div>

        </div>

    </section>

</div>


<script>
    (() => {

        const mapElement = document.getElementById('customerTrackingMap');
        const statusElement = document.getElementById('customerMapStatus');
        const badgeElement = document.getElementById('customerTrackingBadge');

        if (!mapElement || typeof L === 'undefined') return;

        let tracking = null;
        let routeLayer = null;
        let lorryMarker = null;
        let destinationMarker = null;
        let routeKey = '';

        const map = L.map(mapElement).setView([6.1244, 100.3670], 12);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxNativeZoom: 19,
            maxZoom: 21,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        function valid(value) {
            return value !== null && value !== '' && Number.isFinite(Number(value));
        }

        function clearMap() {
            if (lorryMarker) {
                map.removeLayer(lorryMarker);
                lorryMarker = null;
            }

            if (destinationMarker) {
                map.removeLayer(destinationMarker);
                destinationMarker = null;
            }

            if (routeLayer) {
                map.removeLayer(routeLayer);
                routeLayer = null;
            }

            routeKey = '';
        }

        function updateMap(data) {
            tracking = data;

            if (!data) {
                clearMap();
                badgeElement.textContent = 'No active pickup';
                statusElement.textContent = 'No active lorry has been assigned to your booking yet.';
                return;
            }

            badgeElement.textContent = data.lorry_status || data.status || 'Assigned';

            const hasLorry = valid(data.current_lat) && valid(data.current_long);
            const hasDestination = valid(data.destination_lat) && valid(data.destination_long);

            if (!hasLorry || !hasDestination) {
                statusElement.textContent = hasLorry ?
                    'Lorry assigned. Waiting for the destination route to be set.' :
                    'Lorry assigned. Waiting for its GPS location...';
                return;
            }

            const start = [Number(data.current_lat), Number(data.current_long)];
            const end = [Number(data.destination_lat), Number(data.destination_long)];

            if (!lorryMarker) {
                lorryMarker = L.marker(start).addTo(map);
            } else {
                lorryMarker.setLatLng(start);
            }

            lorryMarker.bindPopup('<strong>' + (data.plate_number || 'Assigned lorry') + '</strong><br>Live lorry position');

            if (!destinationMarker) {
                destinationMarker = L.marker(end).addTo(map);
            } else {
                destinationMarker.setLatLng(end);
            }

            destinationMarker.bindPopup('<strong>Your pickup destination</strong><br>' + (data.address || 'Assigned location'));

            const key = start.join(',') + '|' + end.join(',');

            if (key === routeKey) return;

            routeKey = key;

            const path = start[1] + ',' + start[0] + ';' + end[1] + ',' + end[0] + '?overview=full&geometries=geojson';

            fetch('https://router.project-osrm.org/route/v1/driving/' + path)
                .then(response => response.json())
                .then(result => {
                    if (!result.routes || !result.routes[0]) {
                        throw new Error('No route');
                    }

                    if (routeLayer) {
                        map.removeLayer(routeLayer);
                    }

                    const route = result.routes[0];

                    routeLayer = L.geoJSON(route.geometry, {
                        style: {
                            color: '#16a34a',
                            weight: 6,
                            opacity: .9
                        }
                    }).addTo(map);

                    statusElement.textContent = 'Lorry ' + (data.plate_number || '') + ' · ETA about ' + Math.max(1, Math.round(route.duration / 60)) + ' min · ' + (route.distance / 1000).toFixed(1) + ' km away';

                    map.fitBounds(routeLayer.getBounds(), {
                        padding: [25, 25]
                    });
                })
                .catch(() => {
                    if (routeLayer) {
                        map.removeLayer(routeLayer);
                    }

                    routeLayer = L.polyline([start, end], {
                        color: '#16a34a',
                        weight: 4,
                        dashArray: '8 8'
                    }).addTo(map);

                    statusElement.textContent = 'Lorry ' + (data.plate_number || '') + ' is on the way · road ETA unavailable';

                    map.fitBounds(routeLayer.getBounds(), {
                        padding: [25, 25]
                    });
                });
        }

        function refreshTracking() {
            fetch('api/customer_tracking.php', {
                    cache: 'no-store',
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        updateMap(result.tracking || null);
                    }
                })
                .catch(() => {
                    statusElement.textContent = 'Tracking temporarily unavailable.';
                });
        }

        updateMap(tracking);

        setInterval(refreshTracking, 10000);

        window.addEventListener('resize', () => map.invalidateSize());

    })();
</script>


<?php
include "footer.php"; ?>
