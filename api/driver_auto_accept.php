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
    $enabled = !empty($input['enabled']);
    if (!in_array('auto_accept_bookings', app_table_columns($conn, 'users'), true)) {
        throw new RuntimeException('The auto_accept_bookings column is missing from users.');
    }

    // PostgreSQL does not reliably infer a boolean type from a PDO parameter
    // in every deployment. Cast an explicit textual boolean there, while
    // keeping the parameter compatible with MySQL installations.
    if (($dbDriver ?? '') === 'pgsql') {
        $stmt = $conn->prepare('UPDATE users SET auto_accept_bookings = CAST(? AS boolean) WHERE user_id = ?');
        $stmt->execute([$enabled ? 'true' : 'false', $driverId]);
    } else {
        $stmt = $conn->prepare('UPDATE users SET auto_accept_bookings = ? WHERE user_id = ?');
        $stmt->execute([$enabled ? 1 : 0, $driverId]);
    }

    if ($stmt->rowCount() === 0) {
        $verify = $conn->prepare('SELECT user_id FROM users WHERE user_id = ? LIMIT 1');
        $verify->execute([$driverId]);
        if (!$verify->fetchColumn()) {
            throw new RuntimeException('Driver account was not found.');
        }
    }
    echo json_encode(['success' => true, 'enabled' => (bool)$enabled]);
} catch (Throwable $e) {
    error_log('Driver auto-accept save failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save auto-accept setting.']);
}
