<?php
require_once __DIR__ . '/config/session.php';


if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff', 'Customer'], true)) {
    header('Location: auth/login.php');
    exit;
}

$page = basename(__FILE__);
include 'header.php';

$staff = [];
$message = '';
$errors = [];
$successBookingId = 0;
$successIsWalkin = false;
$csrfToken = ensure_csrf_token();
$form = [
    'customer_id' => '',
    'full_name' => '',
    'phone' => '',
    'address' => '',
    'note' => '',
    'weights' => [],
    'staff_id' => '',
    'lorry_id' => '',
    'booking_date' => '',
    'status' => 'Pending',
    'booking_type' => 'pickup'
];

$isCustomer = isset($_SESSION['role']) && $_SESSION['role'] === 'Customer';
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'Admin';
$isStaff = isset($_SESSION['role']) && $_SESSION['role'] === 'Staff';


if ($isCustomer && isset($_SESSION['user_id'])) {
    try {
        $stmt = $conn->prepare("
            SELECT user_id,name,phone,address FROM users
            WHERE user_id=? AND role='Customer' AND status='Active' LIMIT 1
        ");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $customerUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($customerUser) {
            $form['customer_id'] = $customerUser['user_id'];
            $form['full_name'] = $customerUser['name'] ?? '';
            $form['phone'] = $customerUser['phone'] ?? '';
            $form['address'] = $customerUser['address'] ?? '';
        }
    } catch (PDOException $e) {
        $customerUser = null;
        $errors[] = 'Unable to load customer information.';
    }
}

$customers = [];
$categories = [];

try {
    $customers = $conn->query("
        SELECT user_id,name,phone,address FROM users
        WHERE role='Customer' AND status='Active' ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $staff = $conn->query("
        SELECT user_id,name FROM users
        WHERE role='Staff' AND status='Active' ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $categories = $conn->query("
        SELECT waste_id,category_name,unit_price,unit FROM waste_categories
        WHERE status='Active' ORDER BY waste_id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $errors[] = 'Unable to load dropdown lists from users table.';
}

$editBookingId = null;
$isEditing = false;

if (isset($_GET['booking_id'])) {
    $editBookingId = (int)$_GET['booking_id'];

    try {
        $bookingRow = $conn->prepare("
            SELECT b.*,u.name AS customer_name,u.phone AS customer_phone,
                   u.address AS customer_address
            FROM booking b
            LEFT JOIN users u ON b.customer_id=u.user_id
            WHERE b.booking_id=?
              AND (? <> 'Customer' OR b.customer_id = ?)
        ");
        $bookingRow->execute([$editBookingId, $_SESSION['role'], (int)$_SESSION['user_id']]);
        $bookingData = $bookingRow->fetch(PDO::FETCH_ASSOC);

        if ($bookingData) {
    $isEditing = true;
    $form['customer_id'] = $bookingData['customer_id'] ?? '';
    $form['full_name'] = $bookingData['customer_name'] ?? '';
    $form['phone'] = $bookingData['customer_phone'] ?? '';
    $form['address'] = $bookingData['address'] ?? '';
    $form['note'] = $bookingData['note'] ?? '';
    $form['staff_id'] = $bookingData['staff_id'] ?? '';
    $form['lorry_id'] = $bookingData['lorry_id'] ?? '';
    $form['booking_date'] = $bookingData['booking_date'] ?? '';
    $form['status'] = $bookingData['status'] ?? 'Pending';
    $form['booking_type'] = $bookingData['booking_type'] ?? 'pickup';

    $items = $conn->prepare("
        SELECT si.waste_id,si.weight_kg
        FROM sales s
        JOIN sale_items si ON si.sale_id=s.sale_id
        WHERE s.booking_id=?
    ");
    $items->execute([$editBookingId]);

    $weights = [];
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $weights[$it['waste_id']] = (string)$it['weight_kg'];
    }
    $form['weights'] = $weights;
    $form['waste_selected'] = array_keys($weights);   // ← 加在这里，$weights 已经定义好了
}
    } catch (PDOException $e) {
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf_token()) {
    $errors[] = 'Invalid or expired form token. Please refresh and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token()) {
    $form['full_name'] = trim($_POST['full_name'] ?? '');
    $form['phone'] = trim($_POST['phone'] ?? '');
    $form['address'] = trim($_POST['address'] ?? '');
    $form['note'] = trim($_POST['note'] ?? '');
    $form['staff_id'] = trim($_POST['staff_id'] ?? '');
    // The lorry/driver selector is no longer shown here. Preserve an
    // existing assignment while editing, and leave new bookings unassigned.
    if (array_key_exists('lorry_id', $_POST)) {
        $form['lorry_id'] = trim($_POST['lorry_id']);
    } elseif (!$isEditing) {
        $form['lorry_id'] = '';
    }
    $form['customer_id'] = trim($_POST['customer_id'] ?? '');
    $form['waste_selected'] = $_POST['waste_selected'] ?? [];
    $form['booking_type'] = (!$isCustomer && in_array($_POST['booking_type'] ?? '', ['pickup', 'walkin'], true))
        ? $_POST['booking_type'] : 'pickup';

    if ($isCustomer) {
        $form['booking_date'] = date('Y-m-d');
        $form['status'] = 'Pending';
    } else {
        $form['booking_date'] = trim($_POST['booking_date'] ?? '');
        $form['status'] = trim($_POST['status'] ?? 'Pending');
        // A staff-created booking with a selected lorry is already dispatched.
        // Customer bookings remain Pending until a driver accepts them.
        if ($form['lorry_id'] !== '') {
            $form['status'] = 'Scheduled';
        }
    }

    if ($form['booking_type'] === 'walkin' && trim($form['booking_date'] ?? '') === '') {
        $form['booking_date'] = date('Y-m-d');
    }

    // Walk-in bookings are registered on the spot: no note and no lorry
    // dispatch since nothing needs to be driven out. Pickup dates are set
    // later by an administrator from the booking records page.
    $isWalkin = !$isCustomer && $form['booking_type'] === 'walkin';
    if ($isWalkin) {
        $form['note'] = '';
        $form['lorry_id'] = '';
    }

    if ($isCustomer && isset($_SESSION['user_id'])) {
        $form['customer_id'] = (int)$_SESSION['user_id'];
        // Assignment fields are server-managed and cannot be supplied by customers.
        $form['staff_id'] = '';
        $form['lorry_id'] = '';
    }

    if ($form['full_name'] === '') {
        $errors[] = 'Please enter the customer\'s full name.';
    }

    if ($form['address'] === '') {
        $errors[] = 'Please enter a collection address.';
    }

// ============================================================
// COLLECT WASTE ITEMS
// ============================================================

$wasteItems = [];

$selectedWaste = $_POST['waste_selected'] ?? [];

foreach ($categories as $cat) {

    $wid = (int)$cat['waste_id'];

    if (in_array($wid, $selectedWaste)) {

        $price = (float)$cat['unit_price'];

        $wasteItems[] = [
            'waste_id'       => $wid,
            'category_name'  => $cat['category_name'],
            'weight'         => 1,
            'price_per_kg'   => $price,
            'subtotal'       => round($price, 2)
        ];
    }
}

if (empty($wasteItems)) {
    $errors[] = 'Please select at least one waste type.';
}

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            $editingId = isset($_POST['booking_id']) && $_POST['booking_id'] !== ''
                ? (int)$_POST['booking_id'] : null;

            $existingBookingId = null;
            $existingSaleId = null;

            if ($editingId) {
                $existingBooking = $conn->prepare("
                    SELECT customer_id FROM booking
                    WHERE booking_id=?
                      AND (? <> 'Customer' OR customer_id = ?)
                ");
                $existingBooking->execute([$editingId, $_SESSION['role'], (int)$_SESSION['user_id']]);
                $existingData = $existingBooking->fetch(PDO::FETCH_ASSOC);

                if ($existingData) {
                    $existingBookingId = $editingId;
                    $customerId = (int)$existingData['customer_id'];

                    $saleLookup = $conn->prepare("
                        SELECT sale_id FROM sales WHERE booking_id=?
                    ");
                    $saleLookup->execute([$editingId]);
                    $existingSaleId = $saleLookup->fetchColumn() ?: null;

                    $updCust = $conn->prepare("
                        UPDATE users SET name=?,phone=?,address=? WHERE user_id=?
                    ");
                    $updCust->execute([
                        $form['full_name'],
                        $form['phone'] ?: null,
                        $form['address'],
                        $customerId
                    ]);
                }
            }

            if (!$existingBookingId) {
                if ($isCustomer && isset($_SESSION['user_id'])) {
                    $customerId = (int)$_SESSION['user_id'];

                    $checkCustomer = $conn->prepare("
                        SELECT user_id FROM users
                        WHERE user_id=? AND role='Customer'
                        AND status='Active' LIMIT 1
                    ");
                    $checkCustomer->execute([$customerId]);
                    $existingCustomerId = $checkCustomer->fetchColumn();

                    if (!$existingCustomerId) {
                        throw new Exception('Logged-in customer account was not found.');
                    }

                    $customerId = (int)$existingCustomerId;

                    $updateCustomer = $conn->prepare("
                        UPDATE users SET name=?,phone=?,address=? WHERE user_id=?
                    ");
                    $updateCustomer->execute([
                        $form['full_name'],
                        $form['phone'] ?: null,
                        $form['address'],
                        $customerId
                    ]);
                } else {
                    $customerId = !empty($_POST['customer_id'])
                        ? (int)$_POST['customer_id'] : null;

                    if ($customerId) {
                        $checkCustomer = $conn->prepare("
                            SELECT user_id FROM users
                            WHERE user_id=? AND role='Customer'
                            AND status='Active' LIMIT 1
                        ");
                        $checkCustomer->execute([$customerId]);
                        $existingCustomerId = $checkCustomer->fetchColumn();

                        if ($existingCustomerId) {
                            $customerId = (int)$existingCustomerId;

                            $updateCustomer = $conn->prepare("
                                UPDATE users SET name=?,phone=?,address=?
                                WHERE user_id=?
                            ");
                            $updateCustomer->execute([
                                $form['full_name'],
                                $form['phone'] ?: null,
                                $form['address'],
                                $customerId
                            ]);
                        } else {
                            $customerId = null;
                        }
                    }

                    if (!$customerId && $form['phone'] !== '') {
                        $lookup = $conn->prepare("
                            SELECT user_id FROM users
                            WHERE phone=? AND role='Customer'
                            AND status='Active' LIMIT 1
                        ");
                        $lookup->execute([$form['phone']]);
                        $customerId = $lookup->fetchColumn() ?: null;
                    }

                    if (!$customerId) {
                        $stmt = $conn->prepare("
                            INSERT INTO users(name,phone,role,address,status)
                            VALUES(?,?,'Customer',?,'Active')
                        ");
                        $stmt->execute([
                            $form['full_name'],
                            $form['phone'] ?: null,
                            $form['address']
                        ]);
                        $customerId = (int)$conn->lastInsertId();
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE users SET name=?,phone=?,address=?
                            WHERE user_id=?
                        ");
                        $stmt->execute([
                            $form['full_name'],
                            $form['phone'] ?: null,
                            $form['address'],
                            $customerId
                        ]);
                    }
                }
            }

            $scheduledDate = null;
            $selectedDriverId = null;
            if ($form['lorry_id'] !== '') {
                $driverLookup = $conn->prepare('SELECT driver_id FROM lorries WHERE lorry_id=? LIMIT 1');
                $driverLookup->execute([(int)$form['lorry_id']]);
                $selectedDriverId = $driverLookup->fetchColumn() ?: null;
            }

            if ($existingBookingId) {
                if ($isCustomer) {
                    $updBook = $conn->prepare("\n                        UPDATE booking\n                        SET address=?,note=?\n                        WHERE booking_id=? AND customer_id=?\n                    ");
                    $updBook->execute([
                        $form['address'],
                        $form['note'] ?: null,
                        $existingBookingId,
                        (int)$_SESSION['user_id']
                    ]);
                } else {
                    $updBook = $conn->prepare("\n                        UPDATE booking\n                        SET staff_id=?,driver_id=?,lorry_id=?,address=?,note=?,\n                            booking_date=?,status=?,scheduled_date=?,booking_type=?\n                        WHERE booking_id=?\n                    ");
                    $updBook->execute([
                        $form['staff_id'] !== '' ? (int)$form['staff_id'] : null,
                        $selectedDriverId ? (int)$selectedDriverId : null,
                        $form['lorry_id'] !== '' ? (int)$form['lorry_id'] : null,
                        $form['address'],
                        $form['note'] ?: null,
                        $form['booking_date'],
                        $form['status'],
                        $scheduledDate,
                        $form['booking_type'],
                        $existingBookingId
                    ]);
                }

                $bookingId = $existingBookingId;

                if ($existingSaleId) {
                    $conn->prepare("
                        DELETE FROM sale_items WHERE sale_id=?
                    ")->execute([$existingSaleId]);

                    $conn->prepare("
                        DELETE FROM sales WHERE sale_id=?
                    ")->execute([$existingSaleId]);
                }
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO booking
                    (customer_id,staff_id,driver_id,lorry_id,address,note,
                     booking_date,scheduled_date,status,booking_type)
                     VALUES(?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([
                    $customerId,
                    $form['staff_id'] !== '' ? (int)$form['staff_id'] : null,
                    $selectedDriverId ? (int)$selectedDriverId : null,
                    $form['lorry_id'] !== '' ? (int)$form['lorry_id'] : null,
                    $form['address'],
                    $form['note'] ?: null,
                    $form['booking_date'],
                    $scheduledDate,
                    $form['status'],
                    $form['booking_type']
                ]);

                $bookingId = (int)$conn->lastInsertId();
            }

            if (!empty($wasteItems)) {
                $totalAmount = round(
                    array_sum(array_column($wasteItems, 'subtotal')),
                    2
                );

                $saleStmt = $conn->prepare("
                    INSERT INTO sales
                    (customer_id,staff_id,booking_id,lorry_id,
                     total_amount,sale_date,payment_status)
                    VALUES(?,?,?,NULL,?,NOW(),'Paid')
                ");
                $saleStmt->execute([
                    $customerId,
                    $form['staff_id'] !== '' ? (int)$form['staff_id'] : null,
                    $bookingId,
                    $totalAmount
                ]);

                $saleId = (int)$conn->lastInsertId();

                $itemStmt = $conn->prepare("
                    INSERT INTO sale_items
                    (sale_id,waste_id,weight_kg,price_per_kg,subtotal)
                    VALUES(?,?,?,?,?)
                ");

                foreach ($wasteItems as $item) {
                    $itemStmt->execute([
                        $saleId,
                        $item['waste_id'],
                        $item['weight'],
                        $item['price_per_kg'],
                        $item['subtotal']
                    ]);
                }
            }

            // New staff bookings with a lorry selected must also appear on the
            // driver's route queue. The queue schema varies between installs,
            // so only columns that exist are written.
            if (!$isCustomer && $form['lorry_id'] !== '') {
                $queueExists = app_table_exists($conn, 'arrival_queue');
                if ($queueExists) {
                    $queueCols = app_table_columns($conn, 'arrival_queue');
                    $queueAlreadyExists = false;
                    if ($existingBookingId && in_array('booking_id', $queueCols, true)) {
                        $queueCheck = $conn->prepare("SELECT COUNT(*) FROM arrival_queue WHERE booking_id=? AND status IN ('pending','ongoing')");
                        $queueCheck->execute([$bookingId]);
                        $queueAlreadyExists = (int)$queueCheck->fetchColumn() > 0;
                    }
                    if (!$queueAlreadyExists) {
                        $lorryStmt = $conn->prepare("SELECT l.lorry_id,l.plate_number,l.driver_id,l.status,COALESCE(u.name,'Unassigned') AS driver_name FROM lorries l LEFT JOIN users u ON u.user_id=l.driver_id WHERE l.lorry_id=? LIMIT 1");
                        $lorryStmt->execute([(int)$form['lorry_id']]);
                        $assignedLorry = $lorryStmt->fetch(PDO::FETCH_ASSOC);
                    } else {
                        $assignedLorry = null;
                    }
                    if ($assignedLorry && !empty($assignedLorry['driver_id'])) {
                        $activeQueue = false;
                        if (in_array('assigned_lorry_id', $queueCols, true)) {
                            $active = $conn->prepare("SELECT COUNT(*) FROM arrival_queue WHERE assigned_lorry_id=? AND status='ongoing'");
                            $active->execute([(int)$assignedLorry['lorry_id']]);
                            $activeQueue = (int)$active->fetchColumn() > 0;
                        } elseif (in_array('plate_number', $queueCols, true)) {
                            $active = $conn->prepare("SELECT COUNT(*) FROM arrival_queue WHERE plate_number=? AND status='ongoing'");
                            $active->execute([$assignedLorry['plate_number']]);
                            $activeQueue = (int)$active->fetchColumn() > 0;
                        }
                        $queueFields = [];
                        $queueValues = [];
                        $queueData = [
                            'driver_id' => (int)$assignedLorry['driver_id'],
                            'location_name' => $form['address'],
                            'geolocation' => $form['address'],
                            // A newly created booking with a driver assigned
                            // starts immediately when that lorry is free.
                            'status' => $activeQueue ? 'pending' : 'ongoing',
                            'assigned_lorry_id' => (int)$assignedLorry['lorry_id'],
                            'booking_id' => $bookingId,
                            'driver_name' => $assignedLorry['driver_name'],
                            'plate_number' => $assignedLorry['plate_number'],
                            'created_by' => (int)($_SESSION['user_id'] ?? 0),
                            'priority' => 100,
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        foreach ($queueData as $column => $value) {
                            if (in_array($column, $queueCols, true)) {
                                $queueFields[] = $column;
                                $queueValues[] = $value;
                            }
                        }
                        if (in_array('status', $queueCols, true) && count($queueFields) > 0) {
                            $marks = implode(',', array_fill(0, count($queueFields), '?'));
                            $conn->prepare('INSERT INTO arrival_queue (' . implode(',', $queueFields) . ") VALUES ($marks)")->execute($queueValues);
                        }
                    }
                }
            }

            $conn->commit();

            $showSuccess = true;
            $successBookingId = (int)$bookingId;
            $successIsWalkin = $isWalkin;

        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $errors[] = 'Booking could not be saved: ' . $e->getMessage();
        }
    }
}
?>

<div class="page-body">
    <?php
 include 'sidebar.php'; ?>

    <section class="content-panel">
        <div class="page-title mb-4 d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1 class="display-6 fw-bold">
                    <?= $isEditing ? 'Edit Booking' : 'New Booking' ?>
                </h1>
                <p class="text-secondary mb-0">
                    Schedule a waste collection pickup
                </p>
            </div>

            <?php if (!$isCustomer): ?>
                <div style="min-width:180px;">
                    <label class="form-label fw-bold mb-1" for="bookingTypeSelect">Booking Type</label>
                    <select name="booking_type" id="bookingTypeSelect" form="bookingForm" class="form-select">
                        <option value="pickup" <?= $form['booking_type'] === 'pickup' ? 'selected' : '' ?>>Pickup</option>
                        <option value="walkin" <?= $form['booking_type'] === 'walkin' ? 'selected' : '' ?>>Walk-in</option>
                    </select>
                </div>
            <?php endif; ?>
        </div>

        <?php
 if ($message !== ''): ?>
            <div class="alert alert-success mb-4">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php
 endif; ?>

        <?php
 if (!empty($errors)): ?>
            <div class="alert alert-danger mb-4">
                <ul class="mb-0">
                    <?php
 foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php
 endforeach; ?>
                </ul>
            </div>
        <?php
 endif; ?>

        <?php
 if ($isEditing): ?>
            <div class="alert alert-info mb-4">
                Editing Booking
                BK-<?= date('Y') ?>-<?= str_pad($editBookingId, 3, '0', STR_PAD_LEFT) ?>
            </div>
        <?php
 endif; ?>

        <form method="post" id="bookingForm">
            <?= csrf_field() ?>
            <input type="hidden" name="booking_id"
                value="<?= htmlspecialchars($editBookingId ?? '') ?>">

            <div class="row g-4">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">

                            <div class="form-section-title">
                                Customer Information
                            </div>

                            <?php
 if (!$isCustomer): ?>
                                <div class="mb-3">
                                    <label class="form-label text-secondary fs-7">
                                        Select Existing Customer (Optional)
                                    </label>

                                    <div class="d-flex flex-column flex-md-row gap-2">
                                    <select name="customer_id"
                                        id="customerSelect"
                                        class="form-select flex-grow-1">
                                        <option value="">
                                            -- Select Existing Customer --
                                        </option>

                                        <?php
 foreach ($customers as $c): ?>
                                            <option
                                                value="<?= $c['user_id'] ?>"
                                                data-name="<?= htmlspecialchars($c['name']) ?>"
                                                data-phone="<?= htmlspecialchars($c['phone'] ?? '') ?>"
                                                data-address="<?= htmlspecialchars($c['address'] ?? '') ?>"
                                                <?= $form['customer_id'] == $c['user_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($c['name']) ?>
                                            </option>
                                        <?php
 endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-outline-primary text-nowrap" id="newCustomerButton">
                                        + Add New Customer
                                    </button>
                                    </div>
                                    <div id="newCustomerHint" class="small text-primary mt-2" hidden>
                                        New customer mode: enter the customer details below.
                                    </div>
                                </div>
                            <?php
 endif; ?>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Full Name *</label>

                                    <?php
 if ($isCustomer): ?>
                                        <div class="input-group">
                                            <input type="text"
                                                class="form-control"
                                                name="full_name"
                                                id="fullNameInput"
                                                value="<?= htmlspecialchars($form['full_name']) ?>"
                                                placeholder="Ahmad bin Hassan"
                                                required readonly>

                                            <span class="input-group-text"
                                                style="background:#f1f5f9;border-color:#e2e8f0;">
                                                🔒
                                            </span>
                                        </div>
                                    <?php
 else: ?>
                                        <input type="text"
                                            class="form-control"
                                            name="full_name"
                                            id="fullNameInput"
                                            value="<?= htmlspecialchars($form['full_name']) ?>"
                                            placeholder="Ahmad bin Hassan"
                                            required>
                                    <?php
 endif; ?>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Phone Number</label>
                                    <input type="text"
                                        class="form-control"
                                        name="phone"
                                        id="phoneInput"
                                        value="<?= htmlspecialchars($form['phone']) ?>"
                                        placeholder="+60 12-345 6789">
                                </div>
                            </div>

                            <div class="form-section-title">
                                Collection Address
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-12" style="position:relative;">
                                    <div class="input-group">
                                        <input type="text"
                                            class="form-control"
                                            name="address"
                                            id="addressInput"
                                            value="<?= htmlspecialchars($form['address']) ?>"
                                            placeholder="e.g. Jalan Pegawai, Alor Setar..."
                                            autocomplete="off"
                                            required>

                                        <button type="button"
                                            class="btn"
                                            onclick="getMyLocationForBooking()"
                                            style="border:1.5px solid #e2e8f0;border-left:0;background:#fafcff;padding:.5rem 1rem;"
                                            title="Use my location">
                                            📍
                                        </button>
                                    </div>

                                    <div id="addressDropdown"
                                        style="display:none;position:absolute;top:100%;left:0;right:0;z-index:40;background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;max-height:300px;overflow-y:auto;box-shadow:0 12px 32px rgba(15,23,42,.15);margin-top:3px;">
                                    </div>
                                </div>
                            </div>

                            <div class="form-section-title">
                                Waste to be Collected
                            </div>
                     <div class="row g-3 mb-4">

                       <?php
 foreach ($categories as $cat): ?>

                   <?php

                       $wid = (int)$cat['waste_id'];
                       $isChecked = in_array(
                           $wid,
                           $form['waste_selected'] ?? []
                       );
                   ?>

                   <div class="col-md-6">

                       <div class="border rounded-3 p-3 h-100"
                           style="background:#f8fafc;border-color:#e2e8f0!important;cursor:pointer;">

                           <div class="form-check">

                               <input
                                   class="form-check-input"
                                   type="checkbox"
                                   name="waste_selected[]"
                                   value="<?= $wid ?>"
                                   id="waste_<?= $wid ?>"
                                   <?= $isChecked ? 'checked' : '' ?>
                               >

                               <label
                                   class="form-check-label fw-semibold"
                                   for="waste_<?= $wid ?>"
                               >
                                   <?= htmlspecialchars($cat['category_name']) ?>
                               </label>

                           </div>

                           <small class="text-secondary ms-4">
                               Price:
                               RM <?= number_format((float)$cat['unit_price'],2) ?>
                               / <?= htmlspecialchars($cat['unit']) ?>
                          </small>

                          </div>

                          </div>

                         <?php
 endforeach; ?>

                          </div>

                            <div id="noteSection">
                                <div class="form-section-title">
                                    Additional Note
                                </div>

                                <div class="mb-4">
                                    <label class="form-label">
                                        Note / Special Instructions
                                    </label>

                                    <textarea name="note"
                                        id="noteInput"
                                        class="form-control"
                                        rows="4"
                                        placeholder="Example: Please collect the waste from the back entrance."><?= htmlspecialchars($form['note']) ?></textarea>

                                    <small class="text-secondary">
                                        Add any special instructions for the waste collection team.
                                    </small>
                                </div>
                            </div>

                            <?php
 if (!$isCustomer): ?>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label" id="bookingDateLabel">Booking Date</label>
                                        <input type="date"
                                            class="form-control"
                                            name="booking_date"
                                            id="bookingDateInput"
                                            value="<?= htmlspecialchars($form['booking_date'] ?: ($form['booking_type'] === 'walkin' ? date('Y-m-d') : '')) ?>">
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Status</label>

                                        <select name="status" class="form-select">
                                            <?php
 foreach (['Pending', 'Scheduled', 'Completed', 'Cancelled'] as $status): ?>
                                                <option value="<?= $status ?>"
                                                    <?= $form['status'] == $status ? 'selected' : '' ?>>
                                                    <?= $status ?>
                                                </option>
                                            <?php
 endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <?php if (false): ?>
                                <div id="lorrySection" class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <select name="lorry_id" class="form-select">
                                            <option value="">
                                                -- Unassigned --
                                            </option>

                                            <?php
 foreach ($lorries as $l): ?>
                                                <option value="<?= $l['lorry_id'] ?>"
                                                    <?= $form['lorry_id'] == $l['lorry_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($l['plate_number']) ?>
                                                    —
                                                    <?= htmlspecialchars($l['driver_name']) ?>
                                                    (<?= htmlspecialchars($l['status']) ?>)
                                                </option>
                                            <?php
 endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php
 endif; ?>

                            <?php
 if (!empty($categories)): ?>
                                <button type="submit"
                                    class="btn w-100 d-flex align-items-center justify-content-center gap-2 fw-bold py-2"
                                    style="background:var(--nav-bg);color:#fff;border-radius:10px;border:none;font-size:1rem;">
                                    ➕
                                    <?= $isEditing ? 'Update Booking' : 'Create Booking' ?>
                                </button>
                            <?php
 endif; ?>

                        </div>
                    </div>
                </div>
            </div>
        </form>
    </section>
</div>

<?php
 if (!empty($showSuccess)): ?>
<div id="successMessage" class="success-message">
    <div class="success-icon">✓</div>
    <div>
        <strong>Order Placed Successful!</strong>
        <small><?= $isCustomer ? 'Redirecting to Dashboard...' : ($successIsWalkin ? 'Redirecting to Calculator...' : 'Redirecting to Records...') ?></small>
    </div>
</div>
<?php
 endif; ?>

<script>
<?php
 if (!empty($showSuccess)): ?>
setTimeout(function () {
    var msg = document.getElementById('successMessage');
    if (msg) {
        msg.style.display = 'none';
    }
    <?php
 if ($isCustomer): ?>
    window.location.href = 'dashboard_cus.php';
    <?php
 endif; ?>

    <?php if (!$isCustomer && $successIsWalkin && $successBookingId > 0): ?>
    window.location.href = 'calculator.php?booking_id=<?= (int)$successBookingId ?>';
    <?php elseif ($isAdmin || $isStaff): ?>
    window.location.href = 'records.php';
    <?php endif; ?>
}, 1000);
<?php
 endif; ?>
</script>

<script>
    const customerSelect = document.getElementById('customerSelect');
    const newCustomerButton = document.getElementById('newCustomerButton');
    const newCustomerHint = document.getElementById('newCustomerHint');
    let newCustomerMode = false;

    function updateNewCustomerMode(enabled) {
        newCustomerMode = enabled;
        const fullNameInput = document.getElementById('fullNameInput');
        const phoneInput = document.getElementById('phoneInput');
        const addressInput = document.getElementById('addressInput');
        const isWalkin = document.getElementById('bookingTypeSelect')?.value === 'walkin';

        if (enabled) {
            if (customerSelect) customerSelect.value = '';
            if (fullNameInput) fullNameInput.value = '';
            if (phoneInput) phoneInput.value = '';
            if (addressInput) addressInput.value = '';
            if (newCustomerButton) {
                newCustomerButton.textContent = 'Use Existing Customer';
                newCustomerButton.classList.remove('btn-outline-primary');
                newCustomerButton.classList.add('btn-outline-secondary');
            }
            if (newCustomerHint) newCustomerHint.hidden = false;
            if (fullNameInput) fullNameInput.focus();
        } else {
            if (newCustomerButton) {
                newCustomerButton.textContent = isWalkin ? '+ Add Walk-in Customer' : '+ Add New Customer';
                newCustomerButton.classList.remove('btn-outline-secondary');
                newCustomerButton.classList.add('btn-outline-primary');
            }
            if (newCustomerHint) newCustomerHint.hidden = true;
        }
    }

    if (newCustomerButton) {
        newCustomerButton.addEventListener('click', function() {
            updateNewCustomerMode(!newCustomerMode);
        });
    }

    if (customerSelect) {
        customerSelect.addEventListener('change', function() {
            const option = this.options[this.selectedIndex];

            if (this.value !== '') {
                updateNewCustomerMode(false);
                document.getElementById('fullNameInput').value =
                    option.getAttribute('data-name') || '';

                document.getElementById('phoneInput').value =
                    option.getAttribute('data-phone') || '';

                document.getElementById('addressInput').value =
                    option.getAttribute('data-address') || '';
            }
        });
    }

    document.querySelectorAll('.waste-checkbox').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            const weightBox = document.getElementById(
                'weight_box_' + this.value
            );

            if (this.checked) {
                weightBox.style.display = 'block';
            } else {
                weightBox.style.display = 'none';

                const input = weightBox.querySelector('input');

                if (input) {
                    input.value = '';
                }
            }
        });
    });

    const addressInput = document.getElementById('addressInput');
    const addressDropdown = document.getElementById('addressDropdown');
    let addressTimer = null;

    if (addressInput) {
        addressInput.addEventListener('input', function() {
            const query = this.value.trim();

            if (addressTimer) {
                clearTimeout(addressTimer);
            }

            if (query.length < 3) {
                addressDropdown.style.display = 'none';
                return;
            }

            addressDropdown.innerHTML =
                '<div style="padding:.75rem 1rem;color:#64748b;font-size:.85rem;">🔍 Searching...</div>';

            addressDropdown.style.display = 'block';

            addressTimer = setTimeout(function() {
                const url =
                    'https://nominatim.openstreetmap.org/search?q=' +
                    encodeURIComponent(query + ', Malaysia') +
                    '&format=json&limit=8&addressdetails=1&countrycodes=my';

                fetch(url, {headers: {'User-Agent': 'RecyclonBooking/1.0'

                }
                })
                .then(r => r.json())
                .then(data => {
                     if (!data || data.length === 0) {
                            addressDropdown.innerHTML =
                                '<div style="padding:.75rem 1rem;color:#94a3b8;font-size:.85rem;">No results found</div>';
                            return;
                        }

                        let html = '';

                        data.forEach(function(place) {
                            const displayName = place.display_name
                                .split(', ')
                                .slice(0, 4)
                                .join(', ');

                            const type = place.type || 'place';

                            const icon =type === 'city' || type === 'town' ?
                                '🏙️' :type === 'road' || type === 'street' ?
                                '🛣️' : type === 'building' || type === 'amenity' ?
                                '🏢' :
                                '📍';

                            html +=
                                '<div class="addr-item" data-address="' +
                                escHtml(displayName) +
                                '" style="padding:.7rem 1rem;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:.85rem;display:flex;align-items:center;gap:.5rem;">' +
                                '<span>' + icon + '</span>' +
                                '<span style="font-weight:600;line-height:1.3;">' +
                                displayName +
                                '</span></div>';
                        });

                        addressDropdown.innerHTML = html;

                        document.querySelectorAll('.addr-item').forEach(function(item) {
                            item.addEventListener('click', function() {
                                addressInput.value =
                                    this.getAttribute('data-address');

                                addressDropdown.style.display = 'none';
                            });

                            item.addEventListener('mouseenter', function() {
                                this.style.background = '#eef4ff';
                            });

                            item.addEventListener('mouseleave', function() {
                                this.style.background = '';
                            });
                        });
                    })
                    .catch(function() {
                        addressDropdown.innerHTML =
                            '<div style="padding:.75rem 1rem;color:#dc2626;font-size:.85rem;">Search failed</div>';
                    });
            }, 350);
        });

        document.addEventListener('click', function(e) {
            if (
                !e.target.closest('#addressInput') &&
                !e.target.closest('#addressDropdown')
            ) {
                addressDropdown.style.display = 'none';
            }
        });
    }

    function getMyLocationForBooking() {
        if (!navigator.geolocation) {
            alert('Geolocation is not supported by your browser.');
            return;
        }

        navigator.geolocation.getCurrentPosition(
            function(pos) {
                const lat = pos.coords.latitude.toFixed(6);
                const lng = pos.coords.longitude.toFixed(6);

                const url ='https://nominatim.openstreetmap.org/reverse?lat=' +lat +'&lon=' +lng +'&format=json&zoom=16';

                fetch(url, {headers: {'User-Agent': 'RecyclonBooking/1.0'}
                    })
                    .then(r => r.json())
                    .then(data => {
                        const displayName = data.display_name || '';

                        const shortName = displayName
                            .split(', ')
                            .slice(0, 3)
                            .join(', ');

                        if (addressInput) {
                            addressInput.value =
                                shortName ||
                                lat + '°N, ' + lng + '°E';
                        }
                    })
                    .catch(() => {
                        if (addressInput) {
                            addressInput.value =
                                lat + '°N, ' + lng + '°E';
                        }
                    });
            },
            function() {
                alert(
                    'Could not get your location. Please enable GPS or type an address.'
                );
            }, {
                enableHighAccuracy: true,
                timeout: 10000
            }
        );
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
</script>

<script>
    const bookingTypeSelect = document.getElementById('bookingTypeSelect');
    const noteSection = document.getElementById('noteSection');
    const bookingDateInput = document.getElementById('bookingDateInput');
    const bookingDateLabel = document.getElementById('bookingDateLabel');
    let walkinLocationRequested = false;

    function getLocalToday() {
        const today = new Date();
        const month = String(today.getMonth() + 1).padStart(2, '0');
        const day = String(today.getDate()).padStart(2, '0');
        return today.getFullYear() + '-' + month + '-' + day;
    }

    function applyBookingTypeUI() {
        if (!bookingTypeSelect) return;
        const isWalkin = bookingTypeSelect.value === 'walkin';

        if (newCustomerButton && !newCustomerMode) {
            newCustomerButton.textContent = isWalkin ? '+ Add Walk-in Customer' : '+ Add New Customer';
        }

        if (noteSection) noteSection.style.display = isWalkin ? 'none' : '';
        if (bookingDateLabel) bookingDateLabel.textContent = isWalkin ? 'Walk-in Date' : 'Booking Date';

        if (isWalkin) {
            if (bookingDateInput && !bookingDateInput.value) {
                bookingDateInput.value = getLocalToday();
            }

            if (addressInput && !addressInput.value.trim() && !walkinLocationRequested) {
                walkinLocationRequested = true;
                getMyLocationForBooking();
            }
        } else {
            walkinLocationRequested = false;
        }
    }

    if (bookingTypeSelect) {
        bookingTypeSelect.addEventListener('change', applyBookingTypeUI);
        applyBookingTypeUI();
    }
</script>

<?php
 include 'footer.php'; ?>
