<?php
/**
 * GPS History Endpoint
 * Returns recent GPS log entries for a lorry, newest first.
 */
require_once __DIR__ . '/../config/session.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';

$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if ((int)($_SESSION['user_id'] ?? 0) <= 0 || !in_array($role, ['admin', 'staff', 'driver'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrator or staff login required.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$lorryId = filter_input(INPUT_GET, 'lorry_id', FILTER_VALIDATE_INT);
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);

$lorryId = $lorryId !== false && $lorryId !== null ? $lorryId : 0;
$limit = $limit !== false && $limit !== null ? $limit : 50;
$limit = max(1, min($limit, 200));

if ($lorryId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid lorry_id.']);
    exit;
}

if ($role === 'driver') {
    $access = $conn->prepare('SELECT driver_id FROM lorries WHERE lorry_id = ? LIMIT 1');
    $access->execute([$lorryId]);
    $assignedDriverId = $access->fetchColumn();
    if ((int)$assignedDriverId !== (int)$_SESSION['user_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You can only view GPS history for your assigned lorry.']);
        exit;
    }
}

try {
    // Fetch one extra point so speed can be calculated for the oldest
    // returned point without changing the requested result count.
    $fetchLimit = $limit + 1;
    $stmt = $conn->prepare("
        SELECT lorry_id, latitude, longitude, recorded_at
        FROM gps_log
        WHERE lorry_id = ?
        ORDER BY recorded_at DESC
        LIMIT $fetchLimit
    ");
    $stmt->execute([$lorryId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $speedByIndex = [];
    for ($i = 0; $i < count($history) - 1; $i++) {
        $current = $history[$i];
        $previous = $history[$i + 1];

        $currentTs = strtotime($current['recorded_at']);
        $previousTs = strtotime($previous['recorded_at']);
        $diffSec = $currentTs - $previousTs;

        if ($diffSec > 0) {
            $distKm = haversineDistance(
                (float)$current['latitude'],
                (float)$current['longitude'],
                (float)$previous['latitude'],
                (float)$previous['longitude']
            );
            $speed = $distKm / ($diffSec / 3600);

            // Reject impossible GPS jumps instead of displaying nonsense.
            $speedByIndex[$i] = ($speed >= 0 && $speed < 300) ? round($speed, 1) : null;
        } else {
            $speedByIndex[$i] = null;
        }
    }

    $history = array_slice($history, 0, $limit);
    foreach ($history as $i => &$row) {
        $row['speed_kmh'] = $speedByIndex[$i] ?? null;
        $row['heading'] = null;
        if ($i < count($history) - 1) {
            $row['heading'] = calculateHeading(
                (float)$history[$i + 1]['latitude'],
                (float)$history[$i + 1]['longitude'],
                (float)$row['latitude'],
                (float)$row['longitude']
            );
        }
    }
    unset($row);

    echo json_encode([
        'success' => true,
        'history' => $history,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load GPS history.']);
}

function haversineDistance($lat1, $lng1, $lat2, $lng2): float {
    $earthRadius = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $a = max(0.0, min(1.0, $a));
    return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

function calculateHeading($lat1, $lng1, $lat2, $lng2): int {
    $dLng = deg2rad($lng2 - $lng1);
    $y = sin($dLng) * cos(deg2rad($lat2));
    $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2))
       - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos($dLng);

    // atan2 already returns radians. Convert to degrees exactly once.
    $bearing = rad2deg(atan2($y, $x));
    $bearing = fmod($bearing + 360.0, 360.0);
    return (int)round($bearing);
}
