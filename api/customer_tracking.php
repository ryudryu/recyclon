<?php
require_once __DIR__ . '/../config/session.php';


header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if ($userId <= 0 || $role !== 'customer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Customer login required.']);
    exit;
}

try {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../config/customer_tracking.php';
    $tracking = fetchCustomerTracking($conn, $userId);

    echo json_encode([
        'success' => true,
        'tracking' => $tracking,
        'server_time' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load tracking data.']);
}
