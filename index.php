<?php
require_once __DIR__ . '/config/session.php';


session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

if ($_SESSION['role'] != "Admin") {
    header("Location: auth/login.php");
    exit();
}
$page = 'index.php';
include "header.php";


$todayBookings = 0;
$completedRevenue = 0.00;
$activeTrucks = 0;
$pendingJobs = 0;
$livePrices = [];
$recentBookings = [];



try {
    $todayBookings = (int) $conn->query("SELECT COUNT(*) FROM booking WHERE booking_date = CURRENT_DATE")->fetchColumn();
    $completedRevenue = (float) $conn->query("SELECT COALESCE(SUM(s.total_amount), 0)
        FROM sales s
        INNER JOIN booking b ON b.booking_id = s.booking_id
        WHERE s.payment_status = 'Paid'
          AND b.status = 'Completed'
          AND s.sale_date::date = CURRENT_DATE")->fetchColumn();
    $activeTrucks = (int) $conn->query("SELECT COUNT(*) FROM lorries WHERE status = 'On Duty'")->fetchColumn();
    $pendingJobs = (int) $conn->query("SELECT COUNT(*) FROM booking WHERE status = 'Pending'")->fetchColumn();
    $totalTrucks = (int) $conn->query("SELECT COUNT(*) FROM lorries")->fetchColumn();
    $confirmedToday = (int) $conn->query("SELECT COUNT(*) FROM booking WHERE booking_date = CURRENT_DATE AND status IN ('Scheduled', 'Confirmed')")->fetchColumn();

    $livePrices = $conn->query("SELECT category_name, unit_price, unit FROM waste_categories WHERE status = 'Active' ORDER BY waste_id LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

    $recentBookings = $conn->query(
        "SELECT b.booking_id, b.status, COALESCE(u.name, 'Unknown') AS customer_name,
                MAX(s.total_amount) AS total_amount,
                COALESCE(string_agg(DISTINCT wc.category_name, ', ' ORDER BY wc.category_name), 'Unassigned') AS category_name
         FROM booking b
         LEFT JOIN users u ON b.customer_id = u.user_id
         LEFT JOIN sales s ON s.booking_id = b.booking_id
         LEFT JOIN sale_items si ON si.sale_id = s.sale_id
         LEFT JOIN waste_categories wc ON wc.waste_id = si.waste_id
         GROUP BY b.booking_id, b.status, u.name
         ORDER BY b.booking_date DESC, b.booking_id DESC LIMIT 6"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Preserve page load even if DB is empty or disconnected
}
?>

<div class="page-body">
    <?php
 include "sidebar.php"; ?>

    <section class="content-panel px-4 py-4">
        <div class="page-title mb-4">
            <h1 class="display-6">Dashboard</h1>
            <p class="text-secondary mb-0"><?php
 echo date('l, j F Y'); ?></p>
        </div>

        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3 mb-4">
            <div class="col">
                <article class="metric-card card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="metric-label">TODAY'S BOOKINGS</div>
                        <div class="metric-value"><?php
 echo $todayBookings; ?></div>
                        <div class="metric-note"><?php
 echo $confirmedToday; ?> confirmed</div>
                    </div>
                </article>
            </div>
            <div class="col">
                <article class="metric-card card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="metric-label">COMPLETED REVENUE</div>
                        <div class="metric-value metric-value--green">RM <?php
 echo number_format($completedRevenue, 2); ?></div>
                        <div class="metric-note">From completed jobs</div>
                    </div>
                </article>
            </div>
            <div class="col">
                <article class="metric-card card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="metric-label">ACTIVE TRUCKS</div>
                        <div class="metric-value metric-value--orange"><?php
 echo $activeTrucks; ?></div>
                        <div class="metric-note"><?php
 echo $totalTrucks; ?> total fleet</div>
                    </div>
                </article>
            </div>
            <div class="col">
                <article class="metric-card card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="metric-label">PENDING JOBS</div>
                        <div class="metric-value metric-value--red"><?php
 echo $pendingJobs; ?></div>
                        <div class="metric-note">Awaiting dispatch</div>
                    </div>
                </article>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <div class="panel card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="panel-header">
                            <h2 class="h6 mb-0">MARKET PRICES</h2>
                          
                            <a class="btn btn-primary" href="live_pricing.php">Edit Price</a>
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
            <div class="col-12 col-lg-6">
                <div class="panel card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="panel-header">
                            <h2 class="h6 mb-0">RECENT BOOKINGS</h2>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php
 if (!empty($recentBookings)): ?>
                                <?php
 foreach ($recentBookings as $booking): ?>
                                    <div class="booking-row bg-light rounded-3">
                                        <div>
                                            <strong><?php
 echo htmlspecialchars($booking['customer_name']); ?></strong>
                                            <div class="small text-muted">BK-<?php
 echo str_pad($booking['booking_id'], 4, '0', STR_PAD_LEFT); ?> · <?php
 echo htmlspecialchars($booking['category_name'] ?? 'Unassigned'); ?></div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-bold">
                                                <?php
 if (bookingStatusLabel($booking['status']) === 'Completed'): ?>
                                                    RM <?php
 echo number_format($booking['total_amount'] ?? 0, 2); ?>
                                                <?php
 else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php
 endif; ?>
                                            </div>
                                            <span class="<?php
 echo statusBadgeClass($booking['status']); ?>"><?php
 echo bookingStatusLabel($booking['status']); ?></span>
                                        </div>
                                    </div>
                                <?php
 endforeach; ?>
                            <?php
 else: ?>
                                <div class="booking-row bg-light rounded-3">No recent bookings found.</div>
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
