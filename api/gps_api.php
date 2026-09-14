<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';


/**
 * GPS API Endpoint
 * Accepts POST JSON updates from external GPS clients.
 *
 * Authentication:
 *   Prefer the X-API-Key header.
 *   JSON api_key is retained for backwards compatibility.
 *
 * Set RECYLON_GPS_API_KEY (or RECYCLON_GPS_API_KEY) in the server environment.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Only POST requests are accepted.']);
    exit;
}

$apiSecret = $env['RECYCLON_GPS_API_KEY'] ?? '';
if ($apiSecret === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server misconfigured: RECYCLON_GPS_API_KEY is missing.']);
    exit;
}

$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

if (!is_array($input) || !$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
    exit;
}

// Browser driver GPS can authenticate with the logged-in session and CSRF token.
// External devices continue to use X-API-Key or the legacy JSON api_key.
$sessionGpsUserId = (int)($_SESSION['user_id'] ?? 0);
$sessionGpsRole = (string)($_SESSION['role'] ?? '');
$sessionGpsAuth = false;
if ($providedKey === '' && $sessionGpsUserId > 0 && in_array($sessionGpsRole, ['Admin', 'Staff', 'Driver'], true)) {
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $submittedToken = (string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $sessionGpsAuth = $sessionToken !== '' && $submittedToken !== '' && hash_equals($sessionToken, $submittedToken);
}

if ($providedKey === '' && !$sessionGpsAuth) {
    $providedKey = isset($input['api_key']) ? (string)$input['api_key'] : '';
}

if (!$sessionGpsAuth && !hash_equals($apiSecret, $providedKey)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing API key.']);
    exit;
}

$lorryId = isset($input['lorry_id']) ? (int)$input['lorry_id'] : 0;
$action = trim((string)($input['action'] ?? 'update'));
$status = isset($input['status']) ? trim((string)$input['status']) : null;
$preserveDestination = filter_var($input['preserve_destination'] ?? false, FILTER_VALIDATE_BOOLEAN);
$allowedStatuses = ['Available', 'On Duty', 'Maintenance'];

if ($lorryId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid lorry_id.']);
    exit;
}

if ($status !== null && !in_array($status, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid status. Allowed: ' . implode(', ', $allowedStatuses),
    ]);
    exit;
}

$lat = null;
$lng = null;
$destinationLat = null;
$destinationLng = null;
$reportedDestinationLat = isset($input['destination_lat']) && is_numeric($input['destination_lat']) ? (float)$input['destination_lat'] : null;
$reportedDestinationLng = isset($input['destination_long']) && is_numeric($input['destination_long']) ? (float)$input['destination_long'] : null;
$reportedDestinationName = trim((string)($input['destination_name'] ?? ''));
if ($reportedDestinationLat !== null && ($reportedDestinationLat < -90 || $reportedDestinationLat > 90)) $reportedDestinationLat = null;
if ($reportedDestinationLng !== null && ($reportedDestinationLng < -180 || $reportedDestinationLng > 180)) $reportedDestinationLng = null;

if (!in_array($action, ['update', 'complete'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported GPS action.']);
    exit;
}

if ($status !== 'Available') {
    if (!isset($input['lat'], $input['lng']) || $input['lat'] === '' || $input['lng'] === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid lat and lng are required unless status is Available.']);
        exit;
    }

    if (!is_numeric($input['lat']) || !is_numeric($input['lng'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Latitude and longitude must be numeric.']);
        exit;
    }

    $lat = (float)$input['lat'];
    $lng = (float)$input['lng'];

    if (!is_finite($lat) || $lat < -90 || $lat > 90) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid latitude value.']);
        exit;
    }
    if (!is_finite($lng) || $lng < -180 || $lng > 180) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid longitude value.']);
        exit;
    }
}

try {
    $check = $conn->prepare("
         SELECT l.lorry_id, l.driver_id, l.plate_number, l.destination_lat, l.destination_long,
             l.current_lat, l.current_long, u.name AS driver_name
        FROM lorries l
         LEFT JOIN users u ON u.user_id = l.driver_id
         WHERE l.lorry_id = ?
        LIMIT 1
    ");
    $check->execute([$lorryId]);
    $lorryRow = $check->fetch(PDO::FETCH_ASSOC);

    if (!$lorryRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => "Lorry ID $lorryId not found."]);
        exit;
    }

    if (in_array($sessionGpsRole, ['Staff', 'Driver'], true) && (int)($lorryRow['driver_id'] ?? 0) !== $sessionGpsUserId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You can only update the lorry assigned to your account.']);
        exit;
    }

    $speedKmh = null;
    $heading = null;

    if ($lat !== null && $lng !== null) {
        $prevLog = $conn->prepare("
            SELECT latitude, longitude, recorded_at
            FROM gps_log
            WHERE lorry_id = ?
            ORDER BY recorded_at DESC
            LIMIT 1
        ");
        $prevLog->execute([$lorryId]);
        $prev = $prevLog->fetch(PDO::FETCH_ASSOC);

        if ($prev) {
            $prevTime = strtotime($prev['recorded_at']);
            $diffSec = time() - $prevTime;

            if ($diffSec > 0) {
                $distKm = haversineDistance(
                    $lat,
                    $lng,
                    (float)$prev['latitude'],
                    (float)$prev['longitude']
                );
                $candidateSpeed = $distKm / ($diffSec / 3600);

                if ($candidateSpeed >= 0 && $candidateSpeed < 300) {
                    $speedKmh = $candidateSpeed;
                    if ($candidateSpeed > 0.5) {
                        $heading = calculateHeading(
                            (float)$prev['latitude'],
                            (float)$prev['longitude'],
                            $lat,
                            $lng
                        );
                    }
                } else {
                    $speedKmh = 0;
                }
            }
        }

        $conn->prepare("
            UPDATE lorries
            SET current_lat = ?, current_long = ?, last_updated = NOW()
            " . ($status !== null ? ", status = ?" : "") . "
            " . ($status === 'Available' && !$preserveDestination ? ", destination_lat = NULL, destination_long = NULL, destination_set_at = NULL" : "") . "
            WHERE lorry_id = ?
        ")->execute(
            $status !== null
                ? [$lat, $lng, $status, $lorryId]
                : [$lat, $lng, $lorryId]
        );

        $conn->prepare("
            INSERT INTO gps_log (lorry_id, latitude, longitude)
            VALUES (?, ?, ?)
        ")->execute([$lorryId, $lat, $lng]);
    } else {
        $sql = "UPDATE lorries SET last_updated = NOW()";
        $params = [];
        if ($status !== null) {
            $sql .= ", status = ?";
            $params[] = $status;
        }
        if ($status === 'Available' && !$preserveDestination) {
            $sql .= ", destination_lat = NULL, destination_long = NULL, destination_set_at = NULL";
        }
        $sql .= " WHERE lorry_id = ?";
        $params[] = $lorryId;
        $conn->prepare($sql)->execute($params);
    }

    // Refresh the row after the GPS/status update.
    $check->execute([$lorryId]);
    $lorryRow = $check->fetch(PDO::FETCH_ASSOC);

    // Older queue tables store the destination as text only. The driver page
    // can resolve that text to coordinates; save them once for GPS completion.
    if ($status === 'On Duty' &&
        $lorryRow['destination_lat'] === null && $lorryRow['destination_long'] === null &&
        $reportedDestinationLat !== null && $reportedDestinationLng !== null) {
        $conn->prepare("UPDATE lorries SET destination_lat = ?, destination_long = ?, destination_set_at = NOW(), status = 'On Duty' WHERE lorry_id = ?")
            ->execute([$reportedDestinationLat, $reportedDestinationLng, $lorryId]);
        $check->execute([$lorryId]);
        $lorryRow = $check->fetch(PDO::FETCH_ASSOC);
    }

    $responseData = [
        'lorry_id' => $lorryId,
        'lat' => $lat,
        'lng' => $lng,
        'status' => $status ?? 'unchanged',
        'speed_kmh' => $speedKmh !== null ? round($speedKmh, 1) : null,
        'heading' => $heading,
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    $destinationCleared = false;

    if ($action === 'complete' &&
        ($lorryRow['destination_lat'] === null || $lorryRow['destination_long'] === null)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'There is no active destination to complete.']);
        exit;
    }

    if ($action === 'complete' && $lat !== null && $lng !== null &&
        $lorryRow['destination_lat'] !== null &&
        $lorryRow['destination_long'] !== null) {

        $distanceToDestination = haversineDistance(
            $lat,
            $lng,
            (float)$lorryRow['destination_lat'],
            (float)$lorryRow['destination_long']
        );
        if ($distanceToDestination > 0.05) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'You must be within 50 metres of the arrival location to complete this destination.',
                'distance_m' => (int)round($distanceToDestination * 1000),
            ]);
            exit;
        }

        $queueId = completeOneQueueItem(
            $conn,
            $lorryId,
            (int)($lorryRow['driver_id'] ?? 0),
            (float)$lorryRow['destination_lat'],
            (float)$lorryRow['destination_long'],
            $reportedDestinationName !== '' ? $reportedDestinationName : null
        );

        // Completion is an explicit driver action; it is not inferred from
        // GPS distance because a location fix can be inaccurate or indirect.
        $conn->prepare("
            UPDATE lorries
            SET destination_lat = NULL, destination_long = NULL, destination_set_at = NULL, status = 'Available'
            WHERE lorry_id = ?
        ")->execute([$lorryId]);

        $responseData['arrival_completed'] = true;
        $responseData['completed_queue_id'] = $queueId;
        $destinationCleared = true;
    }

    // When a lorry is On Duty and has no active destination, assign the
    // nearest pending job for that lorry. This also runs immediately after
    // the previous destination is completed, so the driver receives the next
    // route without needing to stop and restart tracking.
    if ($action === 'update' && $status === 'On Duty' &&
        $lorryRow &&
        ($destinationCleared || empty($lorryRow['destination_lat'])) &&
        !empty($lorryRow['driver_id'])) {

        $refLat = $lat !== null ? $lat : ($lorryRow['current_lat'] !== null ? (float)$lorryRow['current_lat'] : null);
        $refLng = $lng !== null ? $lng : ($lorryRow['current_long'] !== null ? (float)$lorryRow['current_long'] : null);

        if ($refLat !== null && $refLng !== null) {
            $queueExists = app_table_exists($conn, 'arrival_queue');

            if ($queueExists) {
                $queueCols = app_table_columns($conn, 'arrival_queue');

                if (in_array('driver_id', $queueCols, true) &&
                    in_array('status', $queueCols, true) &&
                    in_array('latitude', $queueCols, true) &&
                    in_array('longitude', $queueCols, true) &&
                    in_array('assigned_lorry_id', $queueCols, true)) {

                    // Clamp acos input to avoid floating-point domain errors.
                    $distanceExpr = "(6371 * ACOS(LEAST(1, GREATEST(-1,
                        COS(RADIANS(:refLat1)) * COS(RADIANS(latitude)) *
                        COS(RADIANS(longitude) - RADIANS(:refLng1)) +
                        SIN(RADIANS(:refLat1)) * SIN(RADIANS(latitude))
                    ))))";

                    $selSql = "
                        SELECT id, location_name, latitude, longitude
                        FROM arrival_queue
                        WHERE assigned_lorry_id = :assigned_lorry_id
                          AND status = 'pending'
                          AND latitude IS NOT NULL
                          AND longitude IS NOT NULL
                        ORDER BY $distanceExpr ASC, id ASC
                        LIMIT 1
                    ";

                    $sel = $conn->prepare($selSql);
                    $sel->bindValue(':assigned_lorry_id', $lorryId, PDO::PARAM_INT);
                    $sel->bindValue(':refLat1', $refLat);
                    $sel->bindValue(':refLng1', $refLng);
                    $sel->execute();
                    $candidate = $sel->fetch(PDO::FETCH_ASSOC);

                    if ($candidate) {
                        $assignedAtField = in_array('assigned_at', $queueCols, true) ? ', assigned_at = NOW()' : '';
                        $updQ = $conn->prepare("
                            UPDATE arrival_queue
                            SET status = 'ongoing', assigned_lorry_id = ? $assignedAtField
                            WHERE id = ? AND status = 'pending'
                        ");
                        $updQ->execute([$lorryId, (int)$candidate['id']]);

                        if ($updQ->rowCount() > 0) {
                            $conn->prepare("
                                UPDATE lorries
                                SET destination_lat = ?, destination_long = ?, destination_set_at = NOW(), status = 'On Duty'
                                WHERE lorry_id = ?
                            ")->execute([
                                (float)$candidate['latitude'],
                                (float)$candidate['longitude'],
                                $lorryId
                            ]);

                            $responseData['assigned_destination'] = [
                                'queue_id' => (int)$candidate['id'],
                                'location_name' => $candidate['location_name'] ?? null,
                                'lat' => (float)$candidate['latitude'],
                                'lng' => (float)$candidate['longitude'],
                            ];
                        }
                    }
                }
            }
        }
    }

    // Do not leave a driver On Duty when the queue has no active work. This
    // also covers legacy queue schemas and completed destinations.
    $check->execute([$lorryId]);
    $lorryRow = $check->fetch(PDO::FETCH_ASSOC) ?: $lorryRow;
    if ($status === 'On Duty' &&
        empty($lorryRow['destination_lat']) &&
        empty($lorryRow['destination_long']) &&
        !hasOngoingQueue($conn, $lorryRow, $lorryId)) {
        $conn->prepare("UPDATE lorries SET status = 'Available' WHERE lorry_id = ?")->execute([$lorryId]);
    }

    $destinationState = $conn->prepare("
        SELECT destination_lat, destination_long, status
        FROM lorries
        WHERE lorry_id = ?
        LIMIT 1
    ");
    $destinationState->execute([$lorryId]);
    $destinationNow = $destinationState->fetch(PDO::FETCH_ASSOC) ?: [];
    $responseData['destination_active'] =
        $destinationNow['destination_lat'] !== null &&
        $destinationNow['destination_long'] !== null;
    $responseData['current_status'] = $destinationNow['status'] ?? $status ?? 'Unknown';

    echo json_encode([
        'success' => true,
        'message' => "Location updated for lorry $lorryId",
        'data' => $responseData,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}

function completeOneQueueItem(PDO $conn, int $lorryId, int $driverId, ?float $lat, ?float $lng, ?string $destinationName = null): ?int {
    try {
        $exists = app_table_exists($conn, 'arrival_queue');
        if (!$exists) {
            return null;
        }

        $cols = app_table_columns($conn, 'arrival_queue');

        if (!in_array('status', $cols, true) || !in_array('id', $cols, true)) {
            return null;
        }

        $conditions = ["status = 'ongoing'"];
        $params = [];

        if (in_array('assigned_lorry_id', $cols, true)) {
            $conditions[] = "assigned_lorry_id = ?";
            $params[] = $lorryId;
        } elseif (in_array('driver_id', $cols, true) && $driverId > 0) {
            $conditions[] = "driver_id = ?";
            $params[] = $driverId;
        } elseif (in_array('driver_name', $cols, true) || in_array('plate_number', $cols, true)) {
            // Older installations identify a queue item by driver name or
            // plate number instead of foreign keys.
            $identity = $conn->prepare("
                SELECT l.plate_number, u.name AS driver_name
                FROM lorries l
                LEFT JOIN users u ON u.user_id = l.driver_id
                WHERE l.lorry_id = ?
                LIMIT 1
            ");
            $identity->execute([$lorryId]);
            $lorryIdentity = $identity->fetch(PDO::FETCH_ASSOC) ?: [];
            $legacyMatch = [];
            $legacyParams = [];
            if (in_array('driver_name', $cols, true) && !empty($lorryIdentity['driver_name'])) {
                $legacyMatch[] = "driver_name = ?";
                $legacyParams[] = $lorryIdentity['driver_name'];
            }
            if (in_array('plate_number', $cols, true) && !empty($lorryIdentity['plate_number'])) {
                $legacyMatch[] = "plate_number = ?";
                $legacyParams[] = $lorryIdentity['plate_number'];
            }
            if (!$legacyMatch) return null;
            $conditions[] = '(' . implode(' OR ', $legacyMatch) . ')';
            $params = array_merge($params, $legacyParams);
        } else {
            return null;
        }

        // Legacy queues identify the active stop by driver/plate, not by GPS
        // coordinates. Do not require the reverse-geocoded name to be an
        // exact match because it can legitimately differ from geolocation.
        $hasDirectAssignment = in_array('assigned_lorry_id', $cols, true) || in_array('driver_id', $cols, true);
        $hasQueueCoordinates = in_array('latitude', $cols, true) && in_array('longitude', $cols, true);
        if (!$hasDirectAssignment && !$hasQueueCoordinates &&
            $destinationName !== null && $destinationName !== '' && in_array('geolocation', $cols, true)) {
            $conditions[] = 'geolocation = ?';
            $params[] = $destinationName;
        }

        if ($lat !== null && $lng !== null &&
            in_array('latitude', $cols, true) && in_array('longitude', $cols, true)) {
            $conditions[] = "latitude = ?";
            $params[] = $lat;
            $conditions[] = "longitude = ?";
            $params[] = $lng;
        }

        $order = in_array('created_at', $cols, true) ? 'created_at ASC, id ASC' : 'id ASC';
        $selectFields = ['id'];
        if (in_array('geolocation', $cols, true)) $selectFields[] = 'geolocation';
        if (in_array('location_name', $cols, true)) $selectFields[] = 'location_name';
        $stmt = $conn->prepare("
            SELECT " . implode(', ', $selectFields) . "
            FROM arrival_queue
            WHERE " . implode(' AND ', $conditions) . "
            ORDER BY $order
            LIMIT 1
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $set = "status = 'completed'";
        if (in_array('completed_at', $cols, true)) {
            $set .= ", completed_at = NOW()";
        }
        if (in_array('updated_at', $cols, true)) {
            $set .= ", updated_at = NOW()";
        }

        $upd = $conn->prepare("UPDATE arrival_queue SET $set WHERE id = ? AND status = 'ongoing'");
        $upd->execute([(int)$row['id']]);

        // If old data contains duplicate rows for the same stop, complete
        // those matching rows together once the lorry reaches the location.
        $duplicateConditions = $conditions;
        $duplicateParams = $params;
        if (!in_array('latitude', $cols, true) && in_array('geolocation', $cols, true) && $row['geolocation'] !== null && $destinationName === null) {
            $duplicateConditions[] = 'geolocation = ?';
            $duplicateParams[] = $row['geolocation'];
        }
        $canCompleteDuplicates =
            (in_array('latitude', $cols, true) && in_array('longitude', $cols, true)) ||
            (in_array('geolocation', $cols, true) && $row['geolocation'] !== null);
        if ($canCompleteDuplicates) {
            $duplicateUpd = $conn->prepare("UPDATE arrival_queue SET $set WHERE " . implode(' AND ', $duplicateConditions));
            $duplicateUpd->execute($duplicateParams);
        }

        return $upd->rowCount() > 0 ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function hasOngoingQueue(PDO $conn, array $lorryRow, int $lorryId): bool {
    if (!app_table_exists($conn, 'arrival_queue')) return false;
    $cols = app_table_columns($conn, 'arrival_queue');
    if (!in_array('status', $cols, true)) return false;

    $where = ["status = 'ongoing'"];
    $params = [];
    if (in_array('assigned_lorry_id', $cols, true)) {
        $where[] = 'assigned_lorry_id = ?';
        $params[] = $lorryId;
    } elseif (in_array('driver_id', $cols, true) && !empty($lorryRow['driver_id'])) {
        $where[] = 'driver_id = ?';
        $params[] = (int)$lorryRow['driver_id'];
    } else {
        $identity = [];
        if (in_array('plate_number', $cols, true) && !empty($lorryRow['plate_number'])) {
            $identity[] = 'plate_number = ?';
            $params[] = (string)$lorryRow['plate_number'];
        }
        if (in_array('driver_name', $cols, true) && !empty($lorryRow['driver_name'])) {
            $identity[] = 'driver_name = ?';
            $params[] = (string)$lorryRow['driver_name'];
        }
        if (!$identity) return false;
        $where[] = '(' . implode(' OR ', $identity) . ')';
    }

    $stmt = $conn->prepare('SELECT 1 FROM arrival_queue WHERE ' . implode(' AND ', $where) . ' LIMIT 1');
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
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

    $bearing = rad2deg(atan2($y, $x));
    $bearing = fmod($bearing + 360.0, 360.0);
    return (int)round($bearing);
}
?>
