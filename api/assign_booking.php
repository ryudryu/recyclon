<?php
require_once __DIR__ . '/../config/session.php';


header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!in_array(strtolower(trim((string)($_SESSION['role'] ?? ''))), ['admin', 'staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only administrators and staff can assign bookings.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are accepted.']);
    exit;
}
if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token.']);
    exit;
}

function assignmentCoordinate($value, float $min, float $max): ?float {
    if ($value === null || $value === '' || !is_numeric($value)) return null;
    $number = (float)$value;
    return is_finite($number) && $number >= $min && $number <= $max ? $number : null;
}

try {
    require_once __DIR__ . '/../config/db.php';
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $lorryId = (int)($_POST['lorry_id'] ?? 0);
    $lat = assignmentCoordinate($_POST['latitude'] ?? null, -90, 90);
    $lng = assignmentCoordinate($_POST['longitude'] ?? null, -180, 180);
    $location = trim((string)($_POST['location_name'] ?? ''));

    if ($bookingId <= 0 || $lorryId <= 0 || $lat === null || $lng === null || $location === '') {
        throw new RuntimeException('Booking, available lorry, address, and valid coordinates are required.');
    }

    $bookingStmt = $conn->prepare("
        SELECT booking_id, address, status, driver_id, lorry_id
        FROM booking
        WHERE booking_id = ?
        LIMIT 1
    ");
    $bookingStmt->execute([$bookingId]);
    $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) throw new RuntimeException('Booking not found.');
    if (in_array(strtolower((string)$booking['status']), ['completed', 'cancelled'], true)) {
        throw new RuntimeException('Completed or cancelled bookings cannot be assigned.');
    }
    if (!empty($booking['driver_id']) || !empty($booking['lorry_id'])) {
        throw new RuntimeException('This booking already has an assignment.');
    }

    $hasAutoAccept = in_array('auto_accept_bookings', app_table_columns($conn, 'users'), true);
    $autoAcceptSelect = $hasAutoAccept ? "COALESCE(u.auto_accept_bookings, 0) AS auto_accept_bookings," : "0 AS auto_accept_bookings,";
    $lorryStmt = $conn->prepare("
        SELECT l.lorry_id, l.plate_number, l.driver_id, l.status,
               $autoAcceptSelect
               l.destination_lat, l.destination_long, u.name AS driver_name
        FROM lorries l
        INNER JOIN users u ON u.user_id = l.driver_id
        WHERE l.lorry_id = ?
          AND l.status IN ('Available', 'On Duty')
          AND u.role = 'Driver'
          AND u.status = 'Active'
        LIMIT 1
    ");
    $lorryStmt->execute([$lorryId]);
    $lorry = $lorryStmt->fetch(PDO::FETCH_ASSOC);
    if (!$lorry) throw new RuntimeException('That lorry is unavailable or has no active driver.');

    $conn->beginTransaction();
    $bookingUpdate = $conn->prepare("
        UPDATE booking
        SET driver_id = ?, lorry_id = ?, status = 'Scheduled'
        WHERE booking_id = ? AND driver_id IS NULL AND lorry_id IS NULL
    ");
    $bookingUpdate->execute([(int)$lorry['driver_id'], $lorryId, $bookingId]);
    if ($bookingUpdate->rowCount() !== 1) {
        throw new RuntimeException('This booking was assigned by someone else. Refresh the records page.');
    }

    $queueExists = app_table_exists($conn, 'arrival_queue');
    if (!$queueExists) throw new RuntimeException('arrival_queue table does not exist.');
    $queueCols = $queueExists ? app_table_columns($conn, 'arrival_queue') : [];

    // Do not create two active queue stops for the same lorry and destination.
    // This also protects the older queue schema, which does not store booking_id.
    if (in_array('booking_id', $queueCols, true)) {
        $duplicateStmt = $conn->prepare("SELECT COUNT(*) FROM arrival_queue WHERE booking_id = ? AND status IN ('pending', 'ongoing')");
        $duplicateStmt->execute([$bookingId]);
    } elseif (in_array('geolocation', $queueCols, true) && in_array('plate_number', $queueCols, true)) {
        $duplicateStmt = $conn->prepare("SELECT COUNT(*) FROM arrival_queue WHERE plate_number = ? AND geolocation = ? AND status IN ('pending', 'ongoing')");
        $duplicateStmt->execute([$lorry['plate_number'], $location]);
    } else {
        $duplicateStmt = null;
    }
    if ($duplicateStmt && (int)$duplicateStmt->fetchColumn() > 0) {
        throw new RuntimeException('This lorry already has the same destination in its active queue.');
    }

    // Keep the current destination active. Additional bookings for the same
    // driver wait in the queue until the current destination is completed.
    $activeQueue = false;
    $queueHasAssignmentKey = false;
    if (in_array('assigned_lorry_id', $queueCols, true)) {
        $queueHasAssignmentKey = true;
        $activeStmt = $conn->prepare("SELECT COUNT(*) FROM arrival_queue WHERE assigned_lorry_id = ? AND status = 'ongoing'");
        $activeStmt->execute([$lorryId]);
        $activeQueue = (int)$activeStmt->fetchColumn() > 0;
    } elseif (in_array('plate_number', $queueCols, true)) {
        $queueHasAssignmentKey = true;
        $activeStmt = $conn->prepare("SELECT COUNT(*) FROM arrival_queue WHERE plate_number = ? AND status = 'ongoing'");
        $activeStmt->execute([$lorry['plate_number']]);
        $activeQueue = (int)$activeStmt->fetchColumn() > 0;
    }
    // Only use the lorry GPS fields as a fallback when the queue table has no
    // way to identify which lorry owns a queue item.
    $activeQueue = $activeQueue || (!$queueHasAssignmentKey && $lorry['status'] === 'On Duty' &&
        $lorry['destination_lat'] !== null && $lorry['destination_long'] !== null);
    // Once a driver is assigned, start the job immediately when the lorry is
    // available. Only a lorry already on an active trip keeps the next job
    // pending, so routes cannot overlap.
    $queueStatus = $activeQueue ? 'pending' : 'ongoing';

    if ($queueStatus === 'ongoing') {
        $conn->prepare("
            UPDATE lorries
            SET destination_lat = ?, destination_long = ?, destination_set_at = NOW(), status = 'On Duty'
            WHERE lorry_id = ?
        ")->execute([$lat, $lng, $lorryId]);
    }

    $fields = [];
    $values = [];
    if (in_array('driver_id', $queueCols, true)) { $fields[] = 'driver_id'; $values[] = (int)$lorry['driver_id']; }
    if (in_array('location_name', $queueCols, true)) { $fields[] = 'location_name'; $values[] = $location; }
    if (in_array('latitude', $queueCols, true)) { $fields[] = 'latitude'; $values[] = $lat; }
    if (in_array('longitude', $queueCols, true)) { $fields[] = 'longitude'; $values[] = $lng; }
    if (in_array('status', $queueCols, true)) { $fields[] = 'status'; $values[] = $queueStatus; }
    if (in_array('assigned_lorry_id', $queueCols, true)) { $fields[] = 'assigned_lorry_id'; $values[] = $lorryId; }
    if (in_array('booking_id', $queueCols, true)) { $fields[] = 'booking_id'; $values[] = $bookingId; }
    if (in_array('created_by', $queueCols, true)) { $fields[] = 'created_by'; $values[] = (int)$_SESSION['user_id']; }
    if (in_array('priority', $queueCols, true)) { $fields[] = 'priority'; $values[] = 100; }

    if (in_array('geolocation', $queueCols, true)) { $fields[] = 'geolocation'; $values[] = $location; }
    if (in_array('driver_name', $queueCols, true)) { $fields[] = 'driver_name'; $values[] = $lorry['driver_name']; }
    if (in_array('plate_number', $queueCols, true)) { $fields[] = 'plate_number'; $values[] = $lorry['plate_number']; }
    if (in_array('created_at', $queueCols, true) && !in_array('created_at', $fields, true)) { $fields[] = 'created_at'; $values[] = date('Y-m-d H:i:s'); }

    if (!in_array('status', $queueCols, true) || count($fields) === 0) {
        throw new RuntimeException('arrival_queue has an unsupported schema.');
    }
    $marks = implode(', ', array_fill(0, count($fields), '?'));
    $conn->prepare('INSERT INTO arrival_queue (' . implode(', ', $fields) . ") VALUES ($marks)")->execute($values);
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => $activeQueue
            ? 'Booking added to ' . $lorry['plate_number'] . '\'s pending queue.'
            : 'Booking assigned to ' . $lorry['plate_number'] . ' and started.',
    ]);
} catch (RuntimeException $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to assign booking.']);
}
