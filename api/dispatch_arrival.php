<?php
require_once __DIR__ . '/../config/session.php';


/**
 * Assign a lorry to an arrival destination and create its ongoing queue item.
 * The GPS Tracker UI calls this endpoint; database writes stay in the API layer.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are accepted.']);
    exit;
}

session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only administrators and staff can dispatch arrivals.']);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : $_POST;
if (!verify_csrf_token((string)($input['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token.']);
    exit;
}

function dispatchCoordinate($value, float $min, float $max): ?float {
    if ($value === null || $value === '' || !is_numeric($value)) return null;
    $number = (float)$value;
    return ($number >= $min && $number <= $max) ? $number : null;
}

try {
    require_once __DIR__ . '/../config/db.php';
    $lorryId = (int)($input['lorry_id'] ?? 0);
    $driverId = !empty($input['driver_id']) ? (int)$input['driver_id'] : null;
    $lat = dispatchCoordinate($input['latitude'] ?? null, -90, 90);
    $lng = dispatchCoordinate($input['longitude'] ?? null, -180, 180);
    $location = trim((string)($input['location_name'] ?? ''));

    if ($lorryId <= 0 || $lat === null || $lng === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A lorry and valid destination coordinates are required.']);
        exit;
    }

    $lorryStmt = $conn->prepare('SELECT lorry_id, plate_number, driver_id FROM lorries WHERE lorry_id = ? LIMIT 1');
    $lorryStmt->execute([$lorryId]);
    $lorry = $lorryStmt->fetch(PDO::FETCH_ASSOC);
    if (!$lorry) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Lorry not found.']);
        exit;
    }
    // Driver assignment is managed separately by the Admin assignment API.
    // Arrival dispatch always uses the lorry's current assigned driver.
    $driverId = !empty($lorry['driver_id']) ? (int)$lorry['driver_id'] : null;
    if (!$driverId) {
        throw new RuntimeException('This lorry has no assigned driver and cannot be dispatched for pickup.');
    }

    $conn->beginTransaction();
    $conn->prepare("UPDATE lorries SET destination_lat = ?, destination_long = ?, destination_set_at = NOW(), status = 'On Duty', driver_id = ? WHERE lorry_id = ?")
        ->execute([$lat, $lng, $driverId, $lorryId]);

    $exists = app_table_exists($conn, 'arrival_queue');
    if (!$exists) throw new RuntimeException('arrival_queue table does not exist.');
    $cols = app_table_columns($conn, 'arrival_queue');

    if (in_array('driver_id', $cols, true) && in_array('latitude', $cols, true) && in_array('longitude', $cols, true)) {
        $fields = ['driver_id', 'location_name', 'latitude', 'longitude', 'status'];
        $values = [$driverId, $location !== '' ? $location : null, $lat, $lng, 'ongoing'];
        if (in_array('assigned_lorry_id', $cols, true)) { $fields[] = 'assigned_lorry_id'; $values[] = $lorryId; }
        if (in_array('assigned_at', $cols, true)) { $fields[] = 'assigned_at'; $values[] = date('Y-m-d H:i:s'); }
        if (in_array('priority', $cols, true)) { $fields[] = 'priority'; $values[] = 100; }
        $marks = implode(', ', array_fill(0, count($fields), '?'));
        $conn->prepare('INSERT INTO arrival_queue (' . implode(', ', $fields) . ") VALUES ($marks)")->execute($values);
    } elseif (in_array('geolocation', $cols, true)) {
        $geo = $location !== '' ? $location : "$lat,$lng";
        $fields = ['geolocation', 'status'];
        $values = [$geo, 'ongoing'];
        if (in_array('driver_name', $cols, true)) {
            $nameStmt = $conn->prepare('SELECT name FROM users WHERE user_id = ?');
            $nameStmt->execute([$driverId]);
            $fields[] = 'driver_name'; $values[] = $nameStmt->fetchColumn() ?: 'Unassigned';
        }
        if (in_array('assigned_lorry_id', $cols, true)) { $fields[] = 'assigned_lorry_id'; $values[] = $lorryId; }
        if (in_array('plate_number', $cols, true)) { $fields[] = 'plate_number'; $values[] = $lorry['plate_number']; }
        if (in_array('created_at', $cols)) { $fields[] = 'created_at'; $values[] = date('Y-m-d H:i:s'); }
        $marks = implode(', ', array_fill(0, count($fields), '?'));
        $conn->prepare('INSERT INTO arrival_queue (' . implode(', ', $fields) . ") VALUES ($marks)")->execute($values);
    } else {
        throw new RuntimeException('arrival_queue has an unsupported schema.');
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Arrival destination assigned.']);
} catch (RuntimeException $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to dispatch arrival.']);
}
