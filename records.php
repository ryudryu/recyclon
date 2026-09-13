<?php
require_once __DIR__ . '/config/session.php';


if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
    header('Location: auth/login.php');
    exit;
}

$page = basename(__FILE__);
include 'header.php';
$customers = [];
$availableLorries = [];
$isCustomer = isset($_SESSION['role']) && $_SESSION['role'] === 'Customer';
$isAdmin = in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true);
$isAdminUser = ($_SESSION['role'] ?? '') === 'Admin';
$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);

if ($isAdmin) {
    try {
        $customers = $conn->query("
            SELECT user_id, name, phone, ic_number
            FROM users
            WHERE role = 'Customer' AND status = 'Active'
            ORDER BY name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($customers as &$c) {
            $c['ic_number'] = app_decrypt((string)($c['ic_number'] ?? ''), $encryptionKey);
        }
        unset($c);
        $availableLorries = $conn->query("
            SELECT l.lorry_id, l.plate_number, l.driver_id, l.status, u.name AS driver_name
            FROM lorries l
            INNER JOIN users u ON u.user_id = l.driver_id
            WHERE l.status IN ('Available', 'On Duty')
              AND u.role = 'Driver'
              AND u.status = 'Active'
            ORDER BY l.plate_number ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {

    }
}
$isAdmin = !$isCustomer;
// Customers can never browse by another customer's ID — the customer_id
// GET param is only honoured for admins. For a customer, results are
// always scoped to their own session user_id below.
$selectedCustomerId = $isAdmin && isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
$searchQuery = trim((string)($_GET['search'] ?? ''));
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$recordsPerPage = 20;
$sort = $_GET['sort'] ?? 'oldest';
$allowedSorts = ['newest', 'oldest'];
if (!in_array($sort, $allowedSorts, true)) $sort = 'oldest';

$statusOrder = [
    'Pending' => 0,
    'Confirmed' => 1,
    'Completed' => 2,
    'Cancelled' => 3,
];
$calculationReady = [];

$bookingComparator = static function (array $a, array $b) use ($sort, $statusOrder, &$calculationReady): int {
    $aStatus = bookingStatusLabel($a['status']);
    $bStatus = bookingStatusLabel($b['status']);
    $statusCompare = ($statusOrder[$aStatus] ?? 99) <=> ($statusOrder[$bStatus] ?? 99);

    if ($statusCompare !== 0) {
        return $statusCompare;
    }

    // Keep Pending first, but surface Confirmed pickups that are ready for
    // calculation above Confirmed pickups still waiting on the driver.
    if ($aStatus === 'Confirmed') {
        $aReady = $calculationReady[(int)($a['booking_id'] ?? 0)] ?? false;
        $bReady = $calculationReady[(int)($b['booking_id'] ?? 0)] ?? false;
        if ($aReady !== $bReady) {
            return $aReady ? -1 : 1;
        }
    }

    $aCreated = strtotime((string)($a['created_at'] ?? '')) ?: strtotime((string)($a['booking_date'] ?? '')) ?: 0;
    $bCreated = strtotime((string)($b['created_at'] ?? '')) ?: strtotime((string)($b['booking_date'] ?? '')) ?: 0;
    $dateCompare = $aCreated <=> $bCreated;

    if ($dateCompare !== 0) {
        return $sort === 'oldest' ? $dateCompare : -$dateCompare;
    }

    $idCompare = ((int)($a['booking_id'] ?? 0)) <=> ((int)($b['booking_id'] ?? 0));
    return $sort === 'oldest' ? $idCompare : -$idCompare;
};

$filter = $_GET['status'] ?? 'All';
$allowedFilters = ['All', 'Pending', 'Confirmed', 'Completed', 'Cancelled'];
if (!in_array($filter, $allowedFilters, true)) $filter = 'All';

$bookings = [];
$counts = ['Pending' => ['jobs' => 0, 'value' => 0], 'Confirmed' => ['jobs' => 0, 'value' => 0], 'Completed' => ['jobs' => 0, 'value' => 0], 'Cancelled' => ['jobs' => 0, 'value' => 0]];

try {
  $rows = $conn->query(
        "SELECT b.booking_id,b.customer_id, b.driver_id, b.lorry_id, b.address, b.status, b.booking_date, b.pickup_date, b.scheduled_date, b.created_at, b.booking_type,
                MAX(s.sale_id) AS sale_id,
                COALESCE(u.name, 'Unknown') AS customer_name,
                COALESCE(u.ic_number, '') AS customer_ic,
                COALESCE(driver.name, '') AS driver_name,
                string_agg(DISTINCT wc.category_name, '||' ORDER BY wc.category_name) AS categories,
                SUM(si.weight_kg) AS weight_kg,
                COALESCE(SUM(si.subtotal), 0) AS value
         FROM booking b
         LEFT JOIN users u ON b.customer_id = u.user_id
         LEFT JOIN users driver ON b.driver_id = driver.user_id
         LEFT JOIN sales s ON s.booking_id = b.booking_id
         LEFT JOIN sale_items si ON si.sale_id = s.sale_id
         LEFT JOIN waste_categories wc ON wc.waste_id = si.waste_id
         GROUP BY b.booking_id
         ORDER BY b.booking_date DESC, b.booking_id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

  // Scope the entire dataset to the logged-in customer BEFORE it's used
  // to build $bookings / $counts, so nothing downstream (filters, tabs,
  // summary tiles, the pending-booking dropdown) can leak other
  // customers' records, regardless of any GET params passed in.
  if ($isCustomer) {
      $rows = array_values(array_filter(
          $rows,
          fn($r) => (int) $r['customer_id'] === $sessionUserId
      ));
  }

  foreach ($rows as $row) {
        $row['customer_ic'] = app_decrypt((string)($row['customer_ic'] ?? ''), $encryptionKey);
        // Split the concatenated category list back into an array for display
        $row['category_list'] = $row['categories'] !== null ? explode('||', $row['categories']) : [];

        $label = bookingStatusLabel($row['status']);
        if (isset($counts[$label])) {
            $counts[$label]['jobs']++;
            $counts[$label]['value'] += (float) $row['value'];
        }
        $bookings[] = $row;
    }
} catch (PDOException $e) {
    // keep page usable even if the query fails
}

// Keep records grouped by workflow status, with the oldest booking first by
// default inside each status group.
foreach ($bookings as $booking) {
    if (bookingStatusLabel($booking['status']) === 'Confirmed') {
        $calculationReady[(int)$booking['booking_id']] = getDriverQueueStatus($conn, $booking) === 'completed';
    }
}
usort($bookings, $bookingComparator);

$filtered = $bookings;
if ($searchQuery !== '') {
    $matchesSearch = fn($b) => stripos((string)$b['customer_name'], $searchQuery) !== false
        || stripos((string)$b['customer_ic'], $searchQuery) !== false;
    $filtered = array_values(array_filter($filtered, $matchesSearch));
}
if ($selectedCustomerId > 0) {
    $filtered = array_values(array_filter($filtered, fn($b) => $b['customer_id'] == $selectedCustomerId));
}
if ($filter !== 'All') {
    $filtered = array_values(array_filter($filtered, fn($b) => bookingStatusLabel($b['status']) === $filter));
}
usort($filtered, $bookingComparator);

$totalFiltered = count($filtered);
$totalPages = max(1, (int)ceil($totalFiltered / $recordsPerPage));
$currentPage = min($currentPage, $totalPages);
$pagedBookings = array_slice($filtered, ($currentPage - 1) * $recordsPerPage, $recordsPerPage);

$tabSource = $bookings;
if ($searchQuery !== '') {
    $tabSource = array_values(array_filter($tabSource, $matchesSearch));
}
if ($selectedCustomerId > 0) {
    $tabSource = array_values(array_filter($tabSource, fn($b) => $b['customer_id'] == $selectedCustomerId));
}
function tabCount($bookings, $label) {
    return count(array_filter($bookings, fn($b) => bookingStatusLabel($b['status']) === $label));
}
?>


<div class="page-body">
    <?php
 include 'sidebar.php'; ?>

    <section class="content-panel">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-1">
            <div class="page-title">
                <h1 class="display-6 fw-bold"><?= htmlspecialchars(t('booking_records')) ?></h1>
                <p id="recordsTotal" class="text-secondary mb-0"><?php
 echo count($filtered); ?> <?= htmlspecialchars(t('matching_records')) ?></p>
            </div>
            <div id="recordsTabs" class="records-tabs">
                <a href="records.php?status=All&amp;customer_id=<?= $selectedCustomerId ?>&amp;search=<?= urlencode($searchQuery) ?>&amp;sort=<?= urlencode($sort) ?>" class="records-tab<?php
 echo $filter === 'All' ? ' active' : ''; ?>"><?= htmlspecialchars(t('all')) ?> (<?php echo count($tabSource); ?>)</a>
                <a href="records.php?status=Pending&amp;customer_id=<?= $selectedCustomerId ?>&amp;search=<?= urlencode($searchQuery) ?>&amp;sort=<?= urlencode($sort) ?>" class="records-tab<?php
 echo $filter === 'Pending' ? ' active' : ''; ?>"><?= htmlspecialchars(t('pending')) ?> (<?php
 echo tabCount($tabSource, 'Pending'); ?>)</a>
                <a href="records.php?status=Confirmed&amp;customer_id=<?= $selectedCustomerId ?>&amp;search=<?= urlencode($searchQuery) ?>&amp;sort=<?= urlencode($sort) ?>" class="records-tab<?php
 echo $filter === 'Confirmed' ? ' active' : ''; ?>"><?= htmlspecialchars(t('confirmed')) ?> (<?php
 echo tabCount($tabSource, 'Confirmed'); ?>)</a>
                <a href="records.php?status=Completed&amp;customer_id=<?= $selectedCustomerId ?>&amp;search=<?= urlencode($searchQuery) ?>&amp;sort=<?= urlencode($sort) ?>" class="records-tab<?php
 echo $filter === 'Completed' ? ' active' : ''; ?>"><?= htmlspecialchars(t('completed')) ?> (<?php
 echo tabCount($tabSource, 'Completed'); ?>)</a>
                <a href="records.php?status=Cancelled&amp;customer_id=<?= $selectedCustomerId ?>&amp;search=<?= urlencode($searchQuery) ?>&amp;sort=<?= urlencode($sort) ?>" class="records-tab<?php
 echo $filter === 'Cancelled' ? ' active' : ''; ?>"><?= htmlspecialchars(t('cancelled')) ?> (<?php
 echo tabCount($tabSource, 'Cancelled'); ?>)</a>
            </div>
        </div>

        <?php
 if ($isAdmin): ?>
        <form method="get" class="mb-4">

            <input type="hidden" name="status" value="<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>">

            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label fw-bold"><?= htmlspecialchars(t('select_customer')) ?></label>
                    <div class="input-group">
                        <input type="search" class="form-control" id="customerFilter" name="search"
                               value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="<?= htmlspecialchars(t('search_name_ic'), ENT_QUOTES, 'UTF-8') ?>" autocomplete="off"
                               style="border-radius: 10px 0 0 10px;">
                        <button class="btn btn-outline-secondary" type="button" id="clearCustomerFilter"
                                style="border-radius: 0 10px 10px 0; padding: 0.4rem 0.8rem;">✕</button>
                    </div>


                    <select name="customer_id" class="form-select mt-1" id="customerSelect">
                        <option value=""><?= htmlspecialchars(t('select_customer_option')) ?></option>
                        <?php
 foreach ($customers as $c): ?>
                            <option value="<?= $c['user_id'] ?>" <?= ($selectedCustomerId == $c['user_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?> — IC: <?= htmlspecialchars($c['ic_number'] ?? '-') ?>
                            </option>
                        <?php
 endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold" for="recordsSort"><?= htmlspecialchars(t('sort_by')) ?></label>
                    <select name="sort" id="recordsSort" class="form-select">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>><?= htmlspecialchars(t('newest_booking')) ?></option>
                        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>><?= htmlspecialchars(t('oldest_booking')) ?></option>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary fw-bold">Apply Filters</button>
                </div>
            </div>
         </form>
        <?php
 endif; ?>

        <div class="card border-0 shadow-sm mt-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars(t('booking_id')) ?></th>
                                <th><?= htmlspecialchars(t('customer')) ?></th>
                                <th><?= htmlspecialchars(t('category')) ?></th>
                                <th><?= htmlspecialchars(t('weight')) ?></th>
                                <th><?= htmlspecialchars(t('value')) ?></th>
                                <th><?= htmlspecialchars($isAdminUser ? t('pickup_date') : t('date_time')) ?></th>
                                <th><?= htmlspecialchars(t('driver')) ?></th>
                                <th><?= htmlspecialchars(t('status')) ?></th>
                                <th><?= htmlspecialchars(t('action')) ?></th>
                                <th><?= htmlspecialchars(t('calculate')) ?></th>
                                <th><?= htmlspecialchars(t('receipt')) ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="recordsTableBody">
                            <?php
 if (!empty($pagedBookings)): ?>
                                <?php
 foreach ($pagedBookings as $b): ?>
                                    <?php $isWalkinRow = ($b['booking_type'] ?? 'pickup') === 'walkin'; ?>
                                    <?php
                                    $bookingStatusLabel = bookingStatusLabel($b['status']);
                                    $driverQueueStatus = null;
                                    $canCalculate = false;
                                    if ($isWalkinRow) {
                                        // Walk-ins are calculated immediately, until completed.
                                        $canCalculate = !in_array($bookingStatusLabel, ['Completed', 'Cancelled'], true);
                                    } elseif ($bookingStatusLabel === 'Confirmed') {
                                        // Customer pickups can be calculated only after the
                                        // driver marks the assigned queue item completed.
                                        $canCalculate = $calculationReady[(int)$b['booking_id']] ?? false;
                                    }
                                    ?>
                                    <tr>
                                        <td class="text-muted">BK-<?php
 echo date('Y'); ?>-<?php
 echo str_pad($b['booking_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                        <td>
                                            <div class="fw-semibold"><?php
 echo htmlspecialchars($b['customer_name']); ?></div>
                                            <div class="small text-muted"><?php
 echo htmlspecialchars($b['address']); ?></div>
                                        </td>
                                        <td>
                                            <?php
 if (!empty($b['category_list'])): ?>
                                                <?php
 foreach ($b['category_list'] as $cat): ?>
                                                    <span class="badge bg-light text-dark border"><?php
 echo htmlspecialchars($cat); ?></span>
                                                <?php
 endforeach; ?>
                                            <?php
 else: ?>
                                                <span class="text-muted">—</span>
                                            <?php
 endif; ?>
                                        </td>
                                        <td>
                                            <?php
 if (bookingStatusLabel($b['status']) === 'Completed' && $b['weight_kg']): ?>
                                                <?php
 echo number_format($b['weight_kg'], 1); ?> kg
                                            <?php
 else: ?>
                                                <span class="text-muted">—</span>
                                            <?php
 endif; ?>
                                        </td>
                                        <td class="fw-bold">
                                            <?php
 if (bookingStatusLabel($b['status']) === 'Completed'): ?>
                                                RM <?php
 echo number_format($b['value'], 2); ?>
                                            <?php
 else: ?>
                                                <span class="text-muted">—</span>
                                            <?php
 endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isAdminUser): ?>
                                                <div class="record-pickup-editor">
                                                    <label class="visually-hidden" for="pickup-date-<?= (int)$b['booking_id'] ?>">
                                                        <?= htmlspecialchars(t('pickup_date')) ?>
                                                    </label>
                                                    <input
                                                        type="date"
                                                        class="form-control form-control-sm record-pickup-date"
                                                        id="pickup-date-<?= (int)$b['booking_id'] ?>"
                                                        value="<?= htmlspecialchars((string)($b['pickup_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                        data-booking-id="<?= (int)$b['booking_id'] ?>"
                                                        aria-label="<?= htmlspecialchars(t('pickup_date') . ' ' . $b['customer_name'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <button type="button" class="btn btn-sm btn-primary save-pickup-date-btn"
                                                        data-booking-id="<?= (int)$b['booking_id'] ?>">
                                                        <?= htmlspecialchars(t('save')) ?>
                                                    </button>
                                                    <span class="record-pickup-feedback small" aria-live="polite"></span>
                                                </div>
                                                <div class="small text-muted mt-1">
                                                    <?= htmlspecialchars(t('booking_date')) ?>: <?= htmlspecialchars((string)$b['booking_date']) ?>
                                                </div>
                                                <?php if ($b['scheduled_date']): ?><div class="small text-muted"><?= date('H:i', strtotime($b['scheduled_date'])) ?></div><?php endif; ?>
                                            <?php else: ?>
                                                <?php
 $displayDate = $b['pickup_date'] ?: $b['booking_date'];
 echo date('Y-m-d', strtotime($displayDate)); ?>
                                                <?php if ($b['scheduled_date']): ?><div class="small text-muted"><?= date('H:i', strtotime($b['scheduled_date'])) ?></div><?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isWalkinRow): ?>
                                                <span class="badge rounded-pill bg-info-subtle text-info border border-info-subtle px-3 py-2">Walk-in</span>
                                            <?php elseif (!empty($b['driver_name'])): ?>
                                                <?php
 echo htmlspecialchars($b['driver_name']); ?>
                                            <?php
 else: ?>
                                                <span class="text-muted">—</span>
                                            <?php
 endif; ?>
                                            <?php
 if ($isAdmin && !$isWalkinRow && in_array(bookingStatusLabel($b['status']), ['Pending', 'Confirmed'], true) && (int)$b['driver_id'] <= 0 && (int)$b['lorry_id'] <= 0): ?>
                                                <div class="d-flex flex-column gap-1 mt-1" style="min-width:190px;">

                                                     <label class="small fw-semibold text-primary mb-0"><?= htmlspecialchars(t('choose_lorry')) ?></label>
                                                     <select class="form-select form-select-sm record-lorry-select" aria-label="Select lorry">
                                                         <option value="">▼ Select lorry ▼</option>
                                                        <?php
 foreach ($availableLorries as $available): ?>
                                                            <option value="<?php
 echo (int)$available['lorry_id']; ?>" data-driver-id="<?php
 echo (int)$available['driver_id']; ?>" data-driver-name="<?php
 echo htmlspecialchars($available['driver_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                                                <?php
 echo htmlspecialchars($available['plate_number'] . ' — ' . $available['driver_name'] . ' (' . $available['status'] . ')'); ?>
                                                            </option>
                                                        <?php
 endforeach; ?>
                                                    </select>
                                                    <button type="button" class="btn btn-sm btn-success assign-booking-btn"
                                                        data-booking-id="<?php
 echo (int)$b['booking_id']; ?>"
                                                        data-address="<?php
 echo htmlspecialchars($b['address'], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <?= htmlspecialchars(t('assign_pickup')) ?>
                                                    </button>
                                                </div>
                                            <?php
 endif; ?>
                                        </td>
                                        <td><span class="<?php
 echo statusBadgeClass($b['status']); ?>"><?php
 echo htmlspecialchars(t(strtolower(bookingStatusLabel($b['status'])))); ?></span></td>
                                        <td class="text-nowrap">
                                            <a href="new_booking.php?booking_id=<?php
 echo $b['booking_id']; ?>" class="btn btn-sm btn-outline-primary mb-1"><?= htmlspecialchars(t('edit')) ?></a>
                                                <?php
 if (false): ?>
                                                                    <?php
 echo htmlspecialchars($available['plate_number'] . ' — ' . $available['driver_name'] . ' (' . $available['status'] . ')'); ?>

                                                <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-3 py-2">✓ Assigned</span>
                                            <?php
 endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($canCalculate): ?>
                                                <a href="calculator.php?booking_id=<?php
 echo $b['booking_id']; ?>" class="btn btn-sm btn-outline-success"><?= htmlspecialchars(t('calculate_now')) ?></a>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Available after the driver completes the pickup."><?= htmlspecialchars(t('calculate_now')) ?></button>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
 if (bookingStatusLabel($b['status']) === 'Completed' && (int)($b['sale_id'] ?? 0) > 0): ?>
                                                <a href="receipt.php?id=<?php
 echo (int)$b['sale_id']; ?>" class="btn btn-primary"><?= htmlspecialchars(t('view_receipt')) ?></a>
                                            <?php
 else: ?>
                                                <span class="text-muted">—</span>
                                            <?php
 endif; ?>
                                        </td>
                                    </tr>
                                <?php
 endforeach; ?>
                            <?php
 else: ?>
                                <tr><td colspan="12" class="text-muted text-center py-4"><?= htmlspecialchars(t('no_bookings')) ?></td></tr>
                            <?php
 endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav id="recordsPagination" class="d-flex justify-content-center mt-4" aria-label="Booking record pages">
                <ul class="pagination mb-0">
                    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?status=<?= urlencode($filter) ?>&amp;customer_id=<?= $selectedCustomerId ?>&amp;search=<?= urlencode($searchQuery) ?>&amp;sort=<?= urlencode($sort) ?>&amp;page=<?= $currentPage - 1 ?>" data-page="<?= $currentPage - 1 ?>" aria-label="<?= htmlspecialchars(t('previous')) ?>"><?= htmlspecialchars(t('previous')) ?></a>
                    </li>
                    <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                        <li class="page-item <?= $pageNumber === $currentPage ? 'active' : '' ?>">
                            <a class="page-link" href="?status=<?= urlencode($filter) ?>&amp;customer_id=<?= $selectedCustomerId ?>&amp;search=<?= urlencode($searchQuery) ?>&amp;sort=<?= urlencode($sort) ?>&amp;page=<?= $pageNumber ?>" data-page="<?= $pageNumber ?>" <?= $pageNumber === $currentPage ? 'aria-current="page"' : '' ?>><?= $pageNumber ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?status=<?= urlencode($filter) ?>&amp;customer_id=<?= $selectedCustomerId ?>&amp;search=<?= urlencode($searchQuery) ?>&amp;sort=<?= urlencode($sort) ?>&amp;page=<?= $currentPage + 1 ?>" data-page="<?= $currentPage + 1 ?>" aria-label="<?= htmlspecialchars(t('next')) ?>"><?= htmlspecialchars(t('next')) ?></a>
                    </li>
                </ul>
            </nav>
        <?php else: ?>
            <div id="recordsPagination"></div>
        <?php endif; ?>

        <div class="row row-cols-1 row-cols-md-4 g-3 mt-1">
            <?php

            $tileColors = ['Pending' => '#b45309', 'Confirmed' => '#1d4ed8', 'Completed' => '#16a34a', 'Cancelled' => '#dc2626'];
            foreach ($counts as $label => $data):
            ?>
                <div class="col">
                    <div class="summary-tile h-100">
                        <div class="summary-tile-label" style="color: <?php
 echo $tileColors[$label]; ?>;"><?php
 echo strtoupper(htmlspecialchars(t(strtolower($label)))); ?></div>
                        <div class="summary-tile-value"><?php
 echo $data['jobs']; ?> <?= htmlspecialchars(t('jobs')) ?></div>
                        <div class="summary-tile-note">RM <?php
 echo number_format($data['value'], 2); ?></div>
                    </div>
                </div>
            <?php
 endforeach; ?>
        </div>
    </section>
</div>

<!-- Live record search and pagination -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('customerFilter');
    const customerSelect = document.getElementById('customerSelect');
    const sortSelect = document.getElementById('recordsSort');
    const clearSearch = document.getElementById('clearCustomerFilter');
    let searchTimer;

    function loadRecords(page) {
        const params = new URLSearchParams({
            status: new URLSearchParams(window.location.search).get('status') || 'All',
            customer_id: customerSelect ? customerSelect.value : '',
            search: searchInput ? searchInput.value.trim() : '',
            sort: sortSelect ? sortSelect.value : 'oldest',
            page: String(page)
        });

        if (searchInput) searchInput.setAttribute('aria-busy', 'true');

        fetch('records.php?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                if (!response.ok) throw new Error('Unable to load booking records');
                return response.text();
            })
            .then(function (html) {
                const parsed = new DOMParser().parseFromString(html, 'text/html');
                ['recordsTableBody', 'recordsPagination', 'recordsTabs', 'recordsTotal'].forEach(function (id) {
                    const current = document.getElementById(id);
                    const replacement = parsed.getElementById(id);
                    if (current && replacement) current.replaceWith(replacement);
                });
                if (typeof bindRecordAssignmentButtons === 'function') bindRecordAssignmentButtons();
                if (typeof bindPickupDateButtons === 'function') bindPickupDateButtons();
                window.history.replaceState({}, '', 'records.php?' + params.toString());
            })
            .catch(function () {
                // Keep the current results visible if a live request fails.
            })
            .finally(function () {
                if (searchInput) searchInput.removeAttribute('aria-busy');
            });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () { loadRecords(1); }, 400);
        });
    }

    if (clearSearch) {
        clearSearch.addEventListener('click', function () {
            if (!searchInput) return;
            searchInput.value = '';
            loadRecords(1);
            searchInput.focus();
        });
    }

    if (customerSelect) {
        customerSelect.addEventListener('change', function () {
            loadRecords(1);
        });
    }

    if (sortSelect) {
        sortSelect.addEventListener('change', function () {
            loadRecords(1);
        });
    }

    document.addEventListener('click', function (event) {
        const pageLink = event.target.closest('#recordsPagination a[data-page]');
        if (!pageLink || pageLink.closest('.disabled')) return;
        event.preventDefault();
        loadRecords(parseInt(pageLink.getAttribute('data-page'), 10) || 1);
    });
});
</script>

<script>
function bindPickupDateButtons() {
document.querySelectorAll('.save-pickup-date-btn').forEach(button => {
    button.addEventListener('click', async function () {
        const row = this.closest('tr');
        const input = row?.querySelector('.record-pickup-date');
        const feedback = row?.querySelector('.record-pickup-feedback');
        if (!input) return;

        this.disabled = true;
        if (feedback) {
            feedback.textContent = 'Saving...';
            feedback.className = 'record-pickup-feedback small text-muted';
        }

        const body = new FormData();
        body.append('booking_id', this.dataset.bookingId || '');
        body.append('pickup_date', input.value);
        body.append('csrf_token', <?= json_encode($csrfToken) ?>);

        try {
            const response = await fetch('api/update_pickup_date.php', {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Unable to save the pickup date.');

            if (feedback) {
                feedback.textContent = 'Saved';
                feedback.className = 'record-pickup-feedback small text-success';
            }
        } catch (error) {
            if (feedback) {
                feedback.textContent = error.message;
                feedback.className = 'record-pickup-feedback small text-danger';
            }
        } finally {
            this.disabled = false;
        }
    });
});
}
bindPickupDateButtons();

function bindRecordAssignmentButtons() {
document.querySelectorAll('.assign-booking-btn').forEach(button => {
    button.addEventListener('click', async function () {
        const row = this.closest('tr');
        const lorrySelect = row.querySelector('.record-lorry-select');
        const lorryId = lorrySelect?.value;
        if (!lorryId) {
            alert('Please select an available lorry.');
            return;
        }

        this.disabled = true;
        this.textContent = 'Finding address...';
        try {
            const address = this.dataset.address.trim();
            const response = await fetch(
                'https://nominatim.openstreetmap.org/search?q=' +
                encodeURIComponent(address + ', Malaysia') +
                '&format=json&limit=1&addressdetails=1&countrycodes=my',
                { headers: { Accept: 'application/json' } }
            );
            const places = await response.json();
            if (!places?.[0]) throw new Error('Could not locate this address. Update the booking address first.');

            const body = new FormData();
            body.append('booking_id', this.dataset.bookingId);
            body.append('lorry_id', lorryId);
            body.append('location_name', address);
            body.append('latitude', places[0].lat);
            body.append('longitude', places[0].lon);
            body.append('csrf_token', <?= json_encode($csrfToken) ?>);
            this.textContent = 'Assigning...';

            const save = await fetch('api/assign_booking.php', {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });
            const result = await save.json();
            if (!save.ok || !result.success) throw new Error(result.message || 'Assignment failed.');
            window.location.reload();
        } catch (error) {
            alert(error.message);
            this.disabled = false;
            this.textContent = 'Assign for pickup';
        }
    });
});
}
bindRecordAssignmentButtons();
</script>

<?php
 include 'footer.php'; ?>
