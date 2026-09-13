<?php
require_once __DIR__ . '/../config/session.php';


/**
 * Driver-only queue acceptance endpoint.
 * A pending stop can become ongoing only when the driver's lorry is free.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (session_status() === PHP_SESSION_NONE) session_start();

$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$driverId = (int)($_SESSION['user_id'] ?? 0);
$csrfToken = (string)($_SESSION['csrf_token'] ?? '');
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

if ($role !== 'driver' || $driverId <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only drivers can accept queue requests.']);
    exit;
}

$submittedToken = (string)($input['csrf_token'] ?? '');
if ($csrfToken === '' || $submittedToken === '' || !hash_equals($csrfToken, $submittedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Refresh the page and try again.']);
    exit;
}

$queueId = (int)($input['id'] ?? 0);
$acceptedLat = isset($input['latitude']) && is_numeric($input['latitude']) ? (float)$input['latitude'] : null;
$acceptedLng = isset($input['longitude']) && is_numeric($input['longitude']) ? (float)$input['longitude'] : null;
if ($acceptedLat !== null && ($acceptedLat < -90 || $acceptedLat > 90)) $acceptedLat = null;
if ($acceptedLng !== null && ($acceptedLng < -180 || $acceptedLng > 180)) $acceptedLng = null;
if ($queueId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Queue item is required.']);
    exit;
}

try {
    require_once __DIR__ . '/../config/db.php';

    $lorryStmt = $conn->prepare("SELECT lorry_id, plate_number, status FROM lorries WHERE driver_id = ? LIMIT 1");
    $lorryStmt->execute([$driverId]);
    $lorry = $lorryStmt->fetch(PDO::FETCH_ASSOC);
    if (!$lorry) throw new RuntimeException('No lorry is assigned to your account.');

    $queueCols = app_table_columns($conn, 'arrival_queue');
    $itemStmt = $conn->prepare('SELECT * FROM arrival_queue WHERE id = ? LIMIT 1');
    $itemStmt->execute([$queueId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) throw new RuntimeException('Queue request not found.');
    if (strtolower((string)($item['status'] ?? '')) !== 'pending') {
        throw new RuntimeException('This request is no longer pending.');
    }

    $ownsRequest = false;
    if (in_array('assigned_lorry_id', $queueCols, true)) {
        // The lorry is the stable queue owner; the driver assigned to it may
        // change, so never accept a row assigned to another lorry.
        $ownsRequest = (int)($item['assigned_lorry_id'] ?? 0) === (int)$lorry['lorry_id'];
    } elseif (in_array('driver_id', $queueCols, true)) {
        $ownsRequest = (int)($item['driver_id'] ?? 0) === $driverId;
    } elseif (in_array('driver_name', $queueCols, true)) {
        $nameStmt = $conn->prepare('SELECT name FROM users WHERE user_id = ? LIMIT 1');
        $nameStmt->execute([$driverId]);
        $driverName = trim((string)$nameStmt->fetchColumn());
        $ownsRequest = $driverName !== ''
            && strcasecmp(trim((string)($item['driver_name'] ?? '')), $driverName) === 0;
        if ($ownsRequest && in_array('plate_number', $queueCols, true)) {
            $ownsRequest = strcasecmp(trim((string)($item['plate_number'] ?? '')), trim((string)$lorry['plate_number'])) === 0;
        }
    } elseif (in_array('plate_number', $queueCols, true)) {
        $ownsRequest = strcasecmp(trim((string)($item['plate_number'] ?? '')), trim((string)$lorry['plate_number'])) === 0;
    }
    if (!$ownsRequest) throw new RuntimeException('This request is not assigned to you.');

    if (in_array('assigned_lorry_id', $queueCols, true)) {
        $activeStmt = $conn->prepare("SELECT COUNT(*) FROM arrival_queue WHERE assigned_lorry_id = ? AND status = 'ongoing'");
        $activeStmt->execute([(int)$lorry['lorry_id']]);
    } elseif (in_array('plate_number', $queueCols, true)) {
        $activeStmt = $conn->prepare("SELECT COUNT(*) FROM arrival_queue WHERE plate_number = ? AND status = 'ongoing'");
        $activeStmt->execute([$lorry['plate_number']]);
    } else {
        $activeStmt = $conn->prepare("SELECT COUNT(*) FROM arrival_queue WHERE driver_id = ? AND status = 'ongoing'");
        $activeStmt->execute([$driverId]);
    }
    if ((int)$activeStmt->fetchColumn() > 0) {
        http_response_code(409);
        throw new RuntimeException('Finish the current stop before accepting the next request.');
    }

    $conn->beginTransaction();
    $set = ["status = 'ongoing'"];
    if (in_array('assigned_lorry_id', $queueCols, true)) $set[] = 'assigned_lorry_id = ' . (int)$lorry['lorry_id'];
    if (in_array('assigned_at', $queueCols, true)) $set[] = 'assigned_at = NOW()';
    if (in_array('updated_at', $queueCols, true)) $set[] = 'updated_at = NOW()';
    $update = $conn->prepare('UPDATE arrival_queue SET ' . implode(', ', $set) . " WHERE id = ? AND status = 'pending'");
    $update->execute([$queueId]);
    if ($update->rowCount() !== 1) throw new RuntimeException('This request was already accepted. Refresh the page.');

    // Address-created bookings may have empty queue coordinates. Use the
    // coordinates resolved by the driver's Start action as a fallback.
    $destinationLat = in_array('latitude', $queueCols, true)
        ? (($item['latitude'] ?? null) !== null ? (float)$item['latitude'] : $acceptedLat)
        : $acceptedLat;
    $destinationLng = in_array('longitude', $queueCols, true)
        ? (($item['longitude'] ?? null) !== null ? (float)$item['longitude'] : $acceptedLng)
        : $acceptedLng;
    if ($acceptedLat !== null && $acceptedLng !== null) {
        $coordinateSet = [];
        if (in_array('latitude', $queueCols, true) && ($item['latitude'] ?? null) === null) $coordinateSet[] = 'latitude = ' . (float)$acceptedLat;
        if (in_array('longitude', $queueCols, true) && ($item['longitude'] ?? null) === null) $coordinateSet[] = 'longitude = ' . (float)$acceptedLng;
        if ($coordinateSet) {
            $conn->prepare('UPDATE arrival_queue SET ' . implode(', ', $coordinateSet) . ' WHERE id = ?')->execute([$queueId]);
        }
    }
    if ($destinationLat !== null && $destinationLng !== null) {
        $conn->prepare("UPDATE lorries SET destination_lat = ?, destination_long = ?, destination_set_at = NOW(), status = 'On Duty' WHERE lorry_id = ?")
            ->execute([(float)$destinationLat, (float)$destinationLng, (int)$lorry['lorry_id']]);
    }
    $conn->commit();

    echo json_encode(['success' => true, 'message' => 'Queue request accepted.']);
} catch (RuntimeException $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to accept queue request.']);
}
