<?php
require_once __DIR__ . '/config/session.php';


session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

if ($_SESSION['role'] !== "Staff") {
    header("Location: auth/login.php");
    exit();
}

$page = 'dashboard_staff.php';
include "header.php";

$userId = $_SESSION['user_id'];

// Staff-specific stats
$myPendingJobs = 0;
$myCompletedJobs = 0;
$myTodaysJobs = 0;
$assignedLorry = null;
$recentJobs = [];
$livePrices = [];

try {
    $stmt = $conn->query("SELECT COUNT(*) FROM booking
        WHERE COALESCE(booking_type, 'pickup') = 'pickup'
          AND status = 'Pending'");
    $myPendingJobs = (int) $stmt->fetchColumn();

    $stmt = $conn->query("SELECT COUNT(*) FROM booking
        WHERE COALESCE(booking_type, 'pickup') = 'pickup'
          AND status = 'Completed'");
    $myCompletedJobs = (int) $stmt->fetchColumn();

    $stmt = $conn->query("SELECT COUNT(*) FROM booking
        WHERE COALESCE(booking_type, 'pickup') = 'pickup'
          AND booking_date = CURRENT_DATE");
    $myTodaysJobs = (int) $stmt->fetchColumn();

    // Assigned lorry
    $stmt = $conn->prepare("SELECT plate_number FROM lorries WHERE driver_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $assignedLorry = $row ? $row['plate_number'] : 'Not assigned';

    $lorryGpsStatus = null;
    $lorryGpsData = null;
    if ($assignedLorry !== 'Not assigned') {
        $stmt = $conn->prepare("SELECT status, current_lat, current_long, last_updated, destination_lat, destination_long FROM lorries WHERE driver_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $lorryGpsData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($lorryGpsData) {
            $lorryGpsStatus = $lorryGpsData['status'];
        }
    }

    // Live prices
    $livePrices = $conn->query("SELECT category_name, unit_price, unit FROM waste_categories WHERE status = 'Active' ORDER BY waste_id LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

    // Pending customer pickup bookings, regardless of assigned staff member.
    $stmt = $conn->query(
        "SELECT b.booking_id, b.status, b.booking_date, b.address,
                COALESCE(u.name, 'Unknown') AS customer_name
         FROM booking b
         LEFT JOIN users u ON b.customer_id = u.user_id
         WHERE COALESCE(b.booking_type, 'pickup') = 'pickup'
           AND b.status = 'Pending'
         ORDER BY b.booking_date DESC, b.booking_id DESC LIMIT 6"
    );
    $recentJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // keep page usable
}
?>

<div class="page-body">
    <?php
 include "sidebar.php"; ?>

    <section class="content-panel px-4 py-4">
        <div class="page-title mb-4">
            <h1 class="display-6">Staff Dashboard</h1>
            <p class="text-secondary mb-0"><?php
 echo date('l, j F Y'); ?> · Welcome, <?php
 echo htmlspecialchars($_SESSION['name']); ?></p>
        </div>

        <!-- Metric cards -->
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3 mb-4">
            <div class="col">
                <article class="metric-card card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="metric-label">TODAY'S JOBS</div>
                        <div class="metric-value"><?php
 echo $myTodaysJobs; ?></div>
                        <div class="metric-note">Scheduled for today</div>
                    </div>
                </article>
            </div>
            <div class="col">
                <article class="metric-card card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="metric-label">PENDING JOBS</div>
                        <div class="metric-value metric-value--red"><?php
 echo $myPendingJobs; ?></div>
                        <div class="metric-note">Awaiting action</div>
                    </div>
                </article>
            </div>
            <div class="col">
                <article class="metric-card card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="metric-label">COMPLETED</div>
                        <div class="metric-value metric-value--green"><?php
 echo $myCompletedJobs; ?></div>
                        <div class="metric-note">All time</div>
                    </div>
                </article>
            </div>
            <div class="col">
                <article class="metric-card card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="metric-label">ASSIGNED LORRY</div>
                        <div class="metric-value metric-value--orange"><?php
 echo htmlspecialchars($assignedLorry === 'Not assigned' ? '—' : $assignedLorry); ?></div>
                        <div class="metric-note"><?php
 echo $assignedLorry === 'Not assigned' ? 'No lorry assigned' : 'Your vehicle'; ?></div>
                    </div>
                </article>
                
            </div>
        </div>

        <?php
 if ($assignedLorry !== 'Not assigned' && $lorryGpsData): ?>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                            <div>
                                <h6 class="fw-bold mb-1" style="color: var(--nav-bg);">My Lorry — <?php
 echo htmlspecialchars($assignedLorry); ?></h6>
                                <div class="text-muted small">
                                    Status: <strong><?php
 echo htmlspecialchars($lorryGpsStatus); ?></strong>
                                    <?php
 if ($lorryGpsData['last_updated']): ?>
                                        &nbsp;·&nbsp;Last update: <strong><?php
 echo htmlspecialchars(date('Y-m-d H:i', strtotime($lorryGpsData['last_updated']))); ?></strong>
                                    <?php
 endif; ?>
                                    <?php
 if ($lorryGpsData['current_lat'] !== null && $lorryGpsData['current_long'] !== null): ?>
                                        &nbsp;·&nbsp;Position: <code><?php
 echo number_format((float)$lorryGpsData['current_lat'], 4); ?>, <?php
 echo number_format((float)$lorryGpsData['current_long'], 4); ?></code>
                                    <?php
 else: ?>
                                        &nbsp;·&nbsp;No GPS fix
                                    <?php
 endif; ?>
                                </div>
                            </div>
                            <a href="gps_tracker.php" class="btn btn-sm fw-bold" style="background: var(--nav-bg); color: #fff; border-radius: 10px; white-space: nowrap;">
                                View on Map
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
 endif; ?>

        <div class="row g-3">
            <!-- Live Market Prices -->
            <div class="col-12 col-lg-6">
                <div class="panel card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="panel-header">
                            <h2 class="h6 mb-0">LIVE MARKET PRICES</h2>
                            <span class="status-pill badge bg-white">Live</span>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php
 if (!empty($livePrices)): ?>
                                <?php
 foreach ($livePrices as $price): $meta = wasteMeta($price['category_name']); ?>
                                    <div class="price-row" style="background: <?php
 echo $meta['bg']; ?>; color: <?php
 echo $meta['color']; ?>;">
                                        <span><?php
 echo $meta['icon']; ?> <?php
 echo htmlspecialchars($price['category_name']); ?></span>
                                        <span>RM <?php
 echo number_format($price['unit_price'], 2); ?>/<?php
 echo htmlspecialchars($price['unit']); ?></span>
                                    </div>
                                <?php
 endforeach; ?>
                            <?php
 else: ?>
                                <div class="price-row bg-light rounded-3">No pricing data available.</div>
                            <?php
 endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Jobs -->
            <div class="col-12 col-lg-6">
                <div class="panel card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="panel-header">
                            <h2 class="h6 mb-0">PENDING PICKUP</h2>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php
 if (!empty($recentJobs)): ?>
                                <?php
 foreach ($recentJobs as $job): ?>
                                    <a href="records.php" class="booking-row booking-row-link bg-light rounded-3 text-reset text-decoration-none"
                                       aria-label="Open booking records for <?= htmlspecialchars($job['customer_name'], ENT_QUOTES, 'UTF-8') ?>">
                                        <div>
                                            <strong><?php
 echo htmlspecialchars($job['customer_name']); ?></strong>
                                            <div class="small text-muted">BK-<?php
 echo str_pad($job['booking_id'], 4, '0', STR_PAD_LEFT); ?> · <?php
 echo htmlspecialchars($job['address']); ?></div>
                                        </div>
                                        <div class="text-end">
                                            <div class="small"><?php
 echo date('d/m/Y', strtotime($job['booking_date'])); ?></div>
                                            <span class="<?php
 echo statusBadgeClass($job['status']); ?>"><?php
 echo bookingStatusLabel($job['status']); ?></span>
                                        </div>
                                    </a>
                                <?php
 endforeach; ?>
                            <?php
 else: ?>
                                <div class="booking-row bg-light rounded-3">No pending customer pickups yet.</div>
                            <?php
 endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php
 include "footer.php"; ?>
