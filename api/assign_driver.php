<?php
require_once __DIR__ . '/../config/session.php';


header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only administrators and staff can assign drivers.']);
    exit;
}
$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are accepted.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : $_POST;
$lorryId = (int)($input['lorry_id'] ?? 0);
$driverId = (int)($input['driver_id'] ?? 0);

if ($sessionToken === '' || !hash_equals($sessionToken, (string)($input['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid session token. Refresh the page and try again.']);
    exit;
}

try {
    require_once __DIR__ . '/../config/db.php';
    if ($lorryId <= 0) throw new RuntimeException('Please select a lorry.');
    if ($driverId > 0) {
        $check = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'Driver' AND status = 'Active' LIMIT 1");
        $check->execute([$driverId]);
        if (!$check->fetchColumn()) throw new RuntimeException('Select an active Driver account.');

        $assignedCheck = $conn->prepare('SELECT plate_number FROM lorries WHERE driver_id = ? AND lorry_id <> ? LIMIT 1');
        $assignedCheck->execute([$driverId, $lorryId]);
        $existingLorry = $assignedCheck->fetchColumn();
        if ($existingLorry) {
            throw new RuntimeException("This Driver is already assigned to lorry $existingLorry. A Driver can only be assigned to one lorry.");
        }
    }
    $lorryCheck = $conn->prepare('SELECT lorry_id FROM lorries WHERE lorry_id = ? LIMIT 1');
    $lorryCheck->execute([$lorryId]);
    if (!$lorryCheck->fetchColumn()) throw new RuntimeException('Lorry not found.');

    $stmt = $conn->prepare('UPDATE lorries SET driver_id = ? WHERE lorry_id = ?');
    $stmt->execute([$driverId > 0 ? $driverId : null, $lorryId]);
    echo json_encode(['success' => true, 'message' => $driverId > 0 ? 'Driver assigned successfully.' : 'Driver unassigned successfully.']);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to assign driver.']);
}
