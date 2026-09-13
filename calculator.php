<?php
require_once __DIR__ . '/config/session.php';


if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
    header('Location: auth/login.php');
    exit;
}

$page = basename(__FILE__);
include 'header.php';

$categories = [];
$pendingBookings = [];
$customers = [];
$errors = [];
$message = '';
$showSuccess = false;
$csrfToken = ensure_csrf_token();
$isCustomer = isset($_SESSION['role']) && $_SESSION['role'] === 'Customer';
$isAdmin = in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true);

try {
    $categories = $conn->query("
        SELECT waste_id, category_name, unit_price, unit
        FROM waste_categories
        WHERE status = 'Active'
        ORDER BY waste_id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($isAdmin) {
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

        $pendingBookings = $conn->query("
            SELECT b.booking_id, b.booking_date, b.address, b.status, b.booking_type, b.customer_id,
                   b.driver_id, b.lorry_id,
                   COALESCE(u.name, 'Unknown') AS customer_name
            FROM booking b
            LEFT JOIN users u ON b.customer_id = u.user_id
            WHERE b.status IN ('Pending', 'Scheduled')
            ORDER BY b.booking_date ASC, b.booking_id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $errors[] = 'Unable to load data.';
}

$byId = [];
foreach ($categories as $c) {
    $byId[$c['waste_id']] = $c;
}

$selectedBookingId = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
$selectedCustomerId = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
$selectedBooking = null;
$estimatedWasteIds = [];
$filteredBookings = $pendingBookings;

if ($selectedCustomerId > 0) {
    $filteredBookings = array_filter($pendingBookings, function ($pb) use ($selectedCustomerId) {
        return $pb['customer_id'] == $selectedCustomerId;
    });
}

if ($selectedBookingId > 0 && !empty($filteredBookings)) {
    foreach ($filteredBookings as $pb) {
        if ($pb['booking_id'] == $selectedBookingId) {
            $selectedBooking = $pb;
            break;
        }
    }
}

$selectedQueueStatus = $selectedBooking ? getDriverQueueStatus($conn, $selectedBooking) : null;
$selectedBookingStatus = $selectedBooking ? strtolower(trim((string) $selectedBooking['status'])) : '';
$selectedBookingLabel = $selectedBooking ? strtolower(trim((string) bookingStatusLabel($selectedBooking['status']))) : '';
$selectedBookingType = $selectedBooking ? strtolower(trim((string)($selectedBooking['booking_type'] ?? 'pickup'))) : '';
$canCalculate = $selectedBooking && (
    ($selectedBookingType === 'walkin' && !in_array($selectedBookingLabel, ['completed', 'cancelled'], true))
    || ($selectedBookingLabel === 'confirmed' && $selectedQueueStatus === 'completed')
);

// Load only the estimated waste selections for the chosen booking. The
// calculator keeps the quantity inputs empty so staff can enter the actual
// collected weights, while the estimated categories are highlighted.
if ($selectedBooking) {
    try {
        $estimateStmt = $conn->prepare("SELECT DISTINCT si.waste_id
            FROM sales s
            INNER JOIN sale_items si ON si.sale_id = s.sale_id
            WHERE s.booking_id = ?");
        $estimateStmt->execute([(int)$selectedBooking['booking_id']]);
        $estimatedWasteIds = array_map('intval', $estimateStmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        $estimatedWasteIds = [];
    }
}

// Only show the waste types the customer actually selected/estimated for
// this booking (e.g. only "Paper Box"). If no booking is selected yet, or
// the booking has no estimate rows, fall back to showing every active
// category so the page still works.
$displayCategories = ($selectedBooking && !empty($estimatedWasteIds))
    ? array_values(array_filter($categories, function ($c) use ($estimatedWasteIds) {
        return in_array((int)$c['waste_id'], $estimatedWasteIds, true);
    }))
    : $categories;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf_token()) {
    $errors[] = 'Invalid or expired form token. Please refresh and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && verify_csrf_token()) {

    $bookingId = isset($_POST['booking_id']) ? (int) $_POST['booking_id'] : 0;
    $weights = $_POST['weights'] ?? [];
    $items = [];

    if ($bookingId <= 0) {
        $errors[] = 'Please select a booking.';
    } else {

        // Check booking exists
        $checkStmt = $conn->prepare("
            SELECT status, booking_type, driver_id, lorry_id, address
            FROM booking
            WHERE booking_id = ?
        ");
        $checkStmt->execute([$bookingId]);
        $bookingRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
        $currentStatus = $bookingRow['status'] ?? false;

        if ($currentStatus === false) {

            $errors[] = 'Booking not found.';
        } else {

            $currentBookingLabel = strtolower(trim((string) bookingStatusLabel($currentStatus)));
            $currentBookingType = strtolower(trim((string)($bookingRow['booking_type'] ?? 'pickup')));
            $currentQueueStatus = $currentBookingType === 'walkin'
                ? null
                : getDriverQueueStatus($conn, $bookingRow);
            $calculationAllowed = ($currentBookingType === 'walkin'
                && !in_array($currentBookingLabel, ['completed', 'cancelled'], true))
                || ($currentBookingLabel === 'confirmed' && $currentQueueStatus === 'completed');

            // Calculate items
            if ($calculationAllowed) foreach ($weights as $wid => $weight) {

                $weight = (float) $weight;

                if ($weight > 0 && isset($byId[$wid])) {

                    $price = (float) $byId[$wid]['unit_price'];
                    $subtotal = round($weight * $price, 2);

                    $items[] = [
                        'waste_id' => (int) $wid,
                        'weight' => $weight,
                        'price_per_unit' => $price,
                        'subtotal' => $subtotal
                    ];
                }
            }

            if (!$calculationAllowed) {
                $errors[] = 'Calculation is available after the booking is confirmed and the driver completes the pickup.';
            } elseif (empty($items)) {

                $errors[] = 'Please enter at least one positive weight.';
            } else {

                try {

                    $conn->beginTransaction();

                    /*
                     * Lock the booking row.
                     * This prevents two calculations from modifying
                     * the same booking at the same time.
                     */
                    $lockStmt = $conn->prepare("
                        SELECT status
                        FROM booking
                        WHERE booking_id = ?
                        FOR UPDATE
                    ");
                    $lockStmt->execute([$bookingId]);

                    $lockedStatus = $lockStmt->fetchColumn();

                    if ($lockedStatus === false) {

                        $conn->rollBack();
                        $errors[] = 'Booking not found.';
                    } else {

                        /*
                         * Calculate total amount
                         */
                        $total = round(
                            array_sum(array_column($items, 'subtotal')),
                            2
                        );

                        /*
                         * Check whether this booking already
                         * has a sales record.
                         */
                        $saleCheckStmt = $conn->prepare("
                            SELECT sale_id
                            FROM sales
                            WHERE booking_id = ?
                            ORDER BY sale_id DESC
                            LIMIT 1
                        ");

                        $saleCheckStmt->execute([$bookingId]);

                        $existingSaleId = $saleCheckStmt->fetchColumn();

                        /*
                         * =========================================
                         * EXISTING SALE
                         * =========================================
                         *
                         * Update the existing sales row instead
                         * of creating another row.
                         */
                        if ($existingSaleId !== false) {

                            $saleId = (int) $existingSaleId;

                            /*
                             * Update existing sale
                             */
                            $updateSaleStmt = $conn->prepare("
                                UPDATE sales
                                SET total_amount = ?,
                                    sale_date = NOW(),
                                    payment_status = 'Paid'
                                WHERE sale_id = ?
                            ");

                            $updateSaleStmt->execute([
                                $total,
                                $saleId
                            ]);

                            /*
                             * Remove old sale items
                             * so only the latest calculation remains.
                             */
                            $deleteItemsStmt = $conn->prepare("
                                DELETE FROM sale_items
                                WHERE sale_id = ?
                            ");

                            $deleteItemsStmt->execute([
                                $saleId
                            ]);
                        } else {

                            /*
                             * =========================================
                             * NO EXISTING SALE
                             * =========================================
                             *
                             * First calculation -> create sale.
                             */
                            $insertSaleStmt = $conn->prepare("
                                INSERT INTO sales
                                (
                                    customer_id,
                                    staff_id,
                                    booking_id,
                                    lorry_id,
                                    total_amount,
                                    sale_date,
                                    payment_status
                                )
                                VALUES
                                (
                                    (SELECT customer_id
                                     FROM booking
                                     WHERE booking_id = ?),

                                    (SELECT staff_id
                                     FROM booking
                                     WHERE booking_id = ?),

                                    ?,

                                    (SELECT lorry_id
                                     FROM booking
                                     WHERE booking_id = ?),

                                    ?,
                                    NOW(),
                                    'Paid'
                                )
                            ");

                            $insertSaleStmt->execute([
                                $bookingId,
                                $bookingId,
                                $bookingId,
                                $bookingId,
                                $total
                            ]);

                            $saleId = (int) $conn->lastInsertId();
                        }

                        /*
                         * Insert the latest calculation items
                         * using the SAME sale_id.
                         */
                        $insertItemStmt = $conn->prepare("
                            INSERT INTO sale_items
                            (
                                sale_id,
                                waste_id,
                                weight_kg,
                                price_per_kg,
                                subtotal
                            )
                            VALUES (?, ?, ?, ?, ?)
                        ");

                        foreach ($items as $it) {

                            $insertItemStmt->execute([
                                $saleId,
                                $it['waste_id'],
                                $it['weight'],
                                $it['price_per_unit'],
                                $it['subtotal']
                            ]);
                        }

                        /*
                         * Keep booking as Completed after calculation.
                         */
                        $updateBookingStmt = $conn->prepare("
                            UPDATE booking
                            SET status = 'Completed'
                            WHERE booking_id = ?
                        ");

                        $updateBookingStmt->execute([
                            $bookingId
                        ]);
                        $conn->commit();
                        $showSuccess = true;
                        /*
                         * Remove booking from pending list
                         */
                        $pendingBookings = array_filter(
                            $pendingBookings,
                            function ($pb) use ($bookingId) {
                                return $pb['booking_id'] != $bookingId;
                            }
                        );

                        $filteredBookings = array_filter(
                            $filteredBookings,
                            function ($pb) use ($bookingId) {
                                return $pb['booking_id'] != $bookingId;
                            }
                        );

                        $selectedBooking = null;
                        $selectedBookingId = 0;
                    }
                } catch (PDOException $e) {

                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }

                    $errors[] =
                        'Failed to save calculation: '
                        . $e->getMessage();
                }
            }
        }
    }
}
?>


<style>
    .waste-list {
        background: #ffffff;
        border-radius: 16px;
        padding: 20px;
    }

    .waste-item {
        box-sizing: border-box;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        width: 100%;
        padding: 16px 20px;
        margin-bottom: 14px;
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        background: #f8fafc;
        transition: border-color .15s ease, box-shadow .15s ease;
    }

    .waste-item:last-of-type {
        margin-bottom: 0;
    }

    .waste-item .waste-title strong {
        display: block;
        font-size: 1rem;
        color: #0f172a;
    }

    .waste-item .waste-title small {
        font-size: .85rem;
    }

    .waste-item.estimate-picked {
        background: #eff6ff;
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }

    .estimate-picked-label {
        display: inline-block;
        margin-left: .5rem;
        padding: .2rem .55rem;
        border-radius: 999px;
        background: #dbeafe;
        color: #1d4ed8;
        font-size: .7rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .waste-item .input-group {
        flex-shrink: 0;
        width: 160px !important;
        max-width: 160px !important;
    }
</style>
<div class="page-body">
    <?php
    include 'sidebar.php'; ?>

    <section class="content-panel">
        <div class="page-title mb-4">
            <h1 class="display-6 fw-bold">Waste Calculator</h1>
            <p class="text-secondary mb-0">Select a customer and pending booking, enter weights, then confirm</p>
            <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
                <a class="mt-3 btn btn-primary" href="./waste/add_waste.php">Add Waste</a>
            <?php endif; ?>
        </div>

        <?php
        if (!empty($errors)): ?>
            <div class="alert alert-danger mb-4">
                <ul class="mb-0"><?php
                foreach ($errors as $error): ?><li><?php
                echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?></ul>
            </div>
        <?php
        endif; ?>
        <?php
        if (!empty($message)): ?>
            <div class="alert alert-success mb-4">
                <?php echo htmlspecialchars($message); ?></div>
        <?php
        endif; ?>

            <?php
            if ($selectedBooking): ?>
                <div class="alert alert-info mb-4">
                    <strong>Calculating for:</strong>
                    BK-<?= date('Y') ?>-<?= str_pad($selectedBooking['booking_id'], 3, '0', STR_PAD_LEFT) ?>
                    — <?= htmlspecialchars($selectedBooking['customer_name']) ?>
                    — <?= htmlspecialchars($selectedBooking['address']) ?>
                    — <?= htmlspecialchars($selectedBooking['booking_date']) ?>
                </div>
            <?php
            endif; ?>

            <?php if ($selectedBooking && !$canCalculate): ?>
                <div class="alert alert-warning mb-4">
                    Calculation is locked until the booking is confirmed and the driver completes the pickup.
                </div>
            <?php endif; ?>

            <?php
            if ($selectedBooking && empty($estimatedWasteIds)): ?>
                <div class="alert alert-warning mb-3">
                    No estimated waste type found for this booking — showing all categories.
                </div>
            <?php
            endif; ?>

            <?php
            if ($isAdmin && empty($filteredBookings)): ?>
                <div class="alert alert-success">No pending bookings available.</div>
            <?php
            endif; ?>

            <?php
            if ($isAdmin && !empty($filteredBookings)): ?>

                <!-- Wrap everything to be saved inside a POST form -->
                <form method="POST" action="calculator.php?booking_id=<?= $selectedBookingId ?>&customer_id=<?= $selectedCustomerId ?>">
                    <?= csrf_field() ?>
                    <?php
                    if ($selectedBooking): ?>
                        <input type="hidden" name="booking_id" value="<?= $selectedBooking['booking_id'] ?>">
                    <?php
                    endif; ?>



                   <div class="calc-grid mt-3">
    <div class="card border-0 shadow-sm">
        <div class="card-body mt-3">
            <div class="wc-row">
                <h2 class="display-6 fw-bold ms-2">Type of Waste</h2>
                <h2 class="container mt-3 display-6 fw-bold mt-3">QUANTITIES (KG)</h2>

                <div class="waste-list">
                    <?php foreach ($displayCategories as $category): ?>
                        <?php $isEstimatedWaste = in_array((int)$category['waste_id'], $estimatedWasteIds, true); ?>
                        <div class="waste-item<?php echo $isEstimatedWaste ? ' estimate-picked' : ''; ?>">
                            <div class="waste-title">
                                <strong><?= htmlspecialchars($category['category_name']) ?></strong>
                                <small class="text-success">RM <?= number_format($category['unit_price'], 2) ?>/kg</small>
                                <?php if ($isEstimatedWaste): ?>
                                    <span class="estimate-picked-label">Estimated selection</span>
                                <?php endif; ?>
                            </div>
                            <div class="input-group">
                                <input type="number" class="form-control kg-input"
                                    name="weights[<?= $category['waste_id'] ?>]"
                                    data-name="<?= htmlspecialchars($category['category_name']) ?>"
                                    data-price="<?= $category['unit_price'] ?>"
                                    min="0" step="0.01" value="0">
                                <span class="input-group-text">kg</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="button" class="btn btn-primary" onclick="clearAll()">Clear All</button>
                    <button type="submit" name="action" value="save" class="btn btn-success" <?= $canCalculate ? '' : 'disabled' ?>>Save & Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="fw-bold text-uppercase small mb-4">Breakdown</h6>
                <div id="breakdown"></div>
            </div>
        </div>
        <div class="total-value-card">
            <div class="label">Total Value</div>
            <div class="value" id="totalValue">RM 0.00</div>
            <div class="note" id="summary">0 of <?php echo count($displayCategories); ?> categories · 0.0 kg total</div>
        </div>
    </div>
</div>
                </form>

            <?php
            endif; ?>

    </section>
</div>

<?php
if (!empty($showSuccess)): ?>
    <div id="successMessage" class="success-message">
        <div class="success-icon">✓</div>
        <div>
            <strong>Calculation Successful!</strong>
            <small>Redirecting to Records...</small>
        </div>
    </div>
<?php
endif; ?>
</body>
<script>
    // ===== Customer Search + Dropdown Filter =====
    const customerFilter = document.getElementById('customerFilter');
    const customerSelect = document.getElementById('customerSelect');
    const clearCustomerFilter = document.getElementById('clearCustomerFilter');

    if (customerFilter && customerSelect) {
        customerFilter.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            const options = customerSelect.options;
            let visibleCount = 0;
            for (let i = 0; i < options.length; i++) {
                const text = options[i].textContent.toLowerCase();
                const match = query === '' || text.includes(query);
                options[i].style.display = match ? '' : 'none';
                if (match) visibleCount++;
            }
            customerSelect.size = Math.min(visibleCount + 1, 8);
            customerSelect.multiple = false;
        });

        if (clearCustomerFilter) {
            clearCustomerFilter.addEventListener('click', function() {
                customerFilter.value = '';
                const options = customerSelect.options;
                for (let i = 0; i < options.length; i++) {
                    options[i].style.display = '';
                }
                customerFilter.focus();
            });
        }
    }
</script>

<script>
    <?php
    if (!empty($showSuccess)): ?>
        setTimeout(function() {
            window.location.href = "records.php";
        }, 1000);
    <?php
    endif; ?>
</script>

<script src="assets/js/calculator.js"></script>
<?php
include 'footer.php'; ?>
