<?php
require_once __DIR__ . '/../config/session.php';


header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only administrators and staff can manage lorries.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are accepted.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : $_POST;
$csrf = (string)($_SESSION['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string)($input['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid session token. Refresh the page and try again.']);
    exit;
}

function coordinateOrNull($value, float $min, float $max): ?float {
    if ($value === null || trim((string)$value) === '') return null;
    if (!is_numeric($value)) throw new RuntimeException('Coordinates must be numeric.');
    $number = (float)$value;
    if ($number < $min || $number > $max) throw new RuntimeException('Coordinates are outside the valid range.');
    return $number;
}

try {
    require_once __DIR__ . '/../config/db.php';
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $plate = strtoupper(trim((string)($input['plate_number'] ?? '')));
    $status = trim((string)($input['status'] ?? 'Available'));
    $driverId = (int)($input['driver_id'] ?? 0);
    $currentLat = coordinateOrNull($input['current_lat'] ?? null, -90, 90);
    $currentLng = coordinateOrNull($input['current_long'] ?? null, -180, 180);
    $destinationLat = coordinateOrNull($input['destination_lat'] ?? null, -90, 90);
    $destinationLng = coordinateOrNull($input['destination_long'] ?? null, -180, 180);

    if (!in_array($action, ['create', 'update', 'delete'], true)) throw new RuntimeException('Invalid lorry action.');
    if ($action !== 'delete') {
        if ($plate === '' || strlen($plate) > 20) throw new RuntimeException('A valid plate number is required.');
        if (!in_array($status, ['Available', 'On Duty', 'Maintenance'], true)) throw new RuntimeException('Invalid lorry status.');
        if (($currentLat === null) !== ($currentLng === null)) throw new RuntimeException('Both current coordinates are required together.');
        if (($destinationLat === null) !== ($destinationLng === null)) throw new RuntimeException('Both destination coordinates are required together.');
        if ($driverId > 0) {
            $driver = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'Driver' AND status = 'Active' LIMIT 1");
            $driver->execute([$driverId]);
            if (!$driver->fetchColumn()) throw new RuntimeException('Select an active Driver account.');
        }
    }

    $lorryId = (int)($input['lorry_id'] ?? 0);
    if ($action === 'create') {
        $check = $conn->prepare('SELECT lorry_id FROM lorries WHERE plate_number = ? LIMIT 1');
        $check->execute([$plate]);
        if ($check->fetchColumn()) throw new RuntimeException('That plate number already exists.');
        if ($driverId > 0) {
            $check = $conn->prepare('SELECT plate_number FROM lorries WHERE driver_id = ? LIMIT 1');
            $check->execute([$driverId]);
            if ($check->fetchColumn()) throw new RuntimeException('This Driver is already assigned to another lorry.');
        }
        $stmt = $conn->prepare('INSERT INTO lorries (plate_number, driver_id, status, current_lat, current_long, destination_lat, destination_long, destination_set_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)' );
        $stmt->execute([$plate, $driverId > 0 ? $driverId : null, $status, $currentLat, $currentLng, $destinationLat, $destinationLng, ($destinationLat !== null ? date('Y-m-d H:i:s') : null)]);
        echo json_encode(['success' => true, 'message' => 'Lorry created successfully.']);
        exit;
    }

    if ($lorryId <= 0) throw new RuntimeException('A lorry is required.');
    $existing = $conn->prepare('SELECT * FROM lorries WHERE lorry_id = ? LIMIT 1');
    $existing->execute([$lorryId]);
    $lorry = $existing->fetch(PDO::FETCH_ASSOC);
    if (!$lorry) throw new RuntimeException('Lorry not found.');

    // Coordinates are maintained by GPS tracking and the arrival map, not by
    // the basic lorry form. Preserve them when an admin edits plate, driver,
    // or status without sending coordinate fields.
    if ($action === 'update') {
        if (!array_key_exists('current_lat', $input)) $currentLat = $lorry['current_lat'] !== null ? (float)$lorry['current_lat'] : null;
        if (!array_key_exists('current_long', $input)) $currentLng = $lorry['current_long'] !== null ? (float)$lorry['current_long'] : null;
        if (!array_key_exists('destination_lat', $input)) $destinationLat = $lorry['destination_lat'] !== null ? (float)$lorry['destination_lat'] : null;
        if (!array_key_exists('destination_long', $input)) $destinationLng = $lorry['destination_long'] !== null ? (float)$lorry['destination_long'] : null;
    }

    if ($action === 'delete') {
        if (($lorry['status'] ?? '') === 'On Duty' || $lorry['destination_lat'] !== null) throw new RuntimeException('An active lorry cannot be deleted. Stop or complete its assignment first.');
        $conn->prepare('DELETE FROM lorries WHERE lorry_id = ?')->execute([$lorryId]);
        echo json_encode(['success' => true, 'message' => 'Lorry deleted successfully.']);
        exit;
    }

    $check = $conn->prepare('SELECT lorry_id FROM lorries WHERE plate_number = ? AND lorry_id <> ? LIMIT 1');
    $check->execute([$plate, $lorryId]);
    if ($check->fetchColumn()) throw new RuntimeException('That plate number already exists.');
    if ($driverId > 0) {
        $check = $conn->prepare('SELECT plate_number FROM lorries WHERE driver_id = ? AND lorry_id <> ? LIMIT 1');
        $check->execute([$driverId, $lorryId]);
        if ($check->fetchColumn()) throw new RuntimeException('This Driver is already assigned to another lorry.');
    }
    $stmt = $conn->prepare('UPDATE lorries SET plate_number = ?, driver_id = ?, status = ?, current_lat = ?, current_long = ?, destination_lat = ?, destination_long = ?, destination_set_at = ? WHERE lorry_id = ?');
    $stmt->execute([$plate, $driverId > 0 ? $driverId : null, $status, $currentLat, $currentLng, $destinationLat, $destinationLng, ($destinationLat !== null ? ($lorry['destination_set_at'] ?: date('Y-m-d H:i:s')) : null), $lorryId]);
    echo json_encode(['success' => true, 'message' => 'Lorry updated successfully.']);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save lorry changes.']);
}
