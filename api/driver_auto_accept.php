<?php
require_once __DIR__ . '/../config/session.php';


header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

$driverId = (int)($_SESSION['user_id'] ?? 0);
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if ($driverId <= 0 || $role !== 'driver') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only drivers can change this setting.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$csrf = (string)($_SESSION['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string)($input['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

try {
    require_once __DIR__ . '/../config/db.php';
    $enabled = !empty($input['enabled']) ? 1 : 0;
    $stmt = $conn->prepare("UPDATE users SET auto_accept_bookings=? WHERE user_id=? AND role='Driver'");
    $stmt->execute([$enabled, $driverId]);
    echo json_encode(['success' => true, 'enabled' => (bool)$enabled]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save auto-accept setting.']);
}
