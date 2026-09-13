<?php
require_once __DIR__ . '/../config/session.php';


/**
 * Arrival Queue JSON API
 *
 * GET    ?driver_id=&status=&limit=
 * POST   create queue item
 * PUT    update queue item
 * DELETE ?id=   soft-cancel queue item
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (session_status() === PHP_SESSION_NONE) session_start();
if ((int)($_SESSION['user_id'] ?? 0) <= 0 || !in_array(strtolower(trim((string)($_SESSION['role'] ?? ''))), ['admin', 'staff', 'driver'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Login required.']);
    exit;
}

try {
    require_once __DIR__ . '/../config/db.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB connection not available.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if ($role === 'driver' && $method !== 'GET') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Drivers may only view their assigned queue.']);
    exit;
}
$raw = file_get_contents('php://input');
$decoded = json_decode($raw, true);
$input = is_array($decoded) ? $decoded : $_POST;

if ($method !== 'GET') {
    $submittedCsrf = is_array($input) ? (string)($input['csrf_token'] ?? '') : '';
    if (!verify_csrf_token($submittedCsrf)) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token.']);
        exit;
    }
}

$allowedStatuses = ['pending', 'ongoing', 'completed', 'cancelled'];

try {
    $queueCols = app_table_columns($conn, 'arrival_queue');

    if (!$queueCols) {
        throw new RuntimeException('arrival_queue table is unavailable.');
    }

    if ($method === 'GET') {
        $driverId = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;
        if ($role === 'driver') $driverId = (int)$_SESSION['user_id'];
        $status = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : '';
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
        $limit = max(1, min($limit, 500));

        $driverLorry = null;
        $driverName = trim((string)($_SESSION['name'] ?? ''));
        if ($role === 'driver') {
            $driverStmt = $conn->prepare('SELECT lorry_id, plate_number FROM lorries WHERE driver_id = ? LIMIT 1');
            $driverStmt->execute([$driverId]);
            $driverLorry = $driverStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($driverName === '') {
                $nameStmt = $conn->prepare('SELECT name FROM users WHERE user_id = ? LIMIT 1');
                $nameStmt->execute([$driverId]);
                $driverName = trim((string)$nameStmt->fetchColumn());
            }
        }

        $select = "q.*";
        if (in_array('driver_id', $queueCols, true)) {
            $select .= ", u.name AS driver_name";
        }
        if (in_array('assigned_lorry_id', $queueCols, true)) {
            $select .= ", l.plate_number AS assigned_plate";
        }

        $sql = "SELECT $select FROM arrival_queue q";
        if (in_array('driver_id', $queueCols, true)) {
            $sql .= " LEFT JOIN users u ON u.user_id = q.driver_id";
        }
        if (in_array('assigned_lorry_id', $queueCols, true)) {
            $sql .= " LEFT JOIN lorries l ON l.lorry_id = q.assigned_lorry_id";
        }

        $where = [];
        $params = [];

        if ($role === 'driver') {
            if (in_array('assigned_lorry_id', $queueCols, true) && $driverLorry) {
                $where[] = 'q.assigned_lorry_id = ?';
                $params[] = (int)$driverLorry['lorry_id'];
            } elseif (in_array('driver_id', $queueCols, true)) {
                $where[] = 'q.driver_id = ?';
                $params[] = $driverId;
            } elseif (in_array('driver_name', $queueCols, true) && $driverName !== '') {
                if (in_array('plate_number', $queueCols, true) && $driverLorry) {
                    $where[] = '(q.driver_name = ? AND q.plate_number = ?)';
                    $params[] = $driverName;
                    $params[] = $driverLorry['plate_number'];
                } else {
                    $where[] = 'q.driver_name = ?';
                    $params[] = $driverName;
                }
            } elseif (in_array('plate_number', $queueCols, true) && $driverLorry) {
                $where[] = 'q.plate_number = ?';
                $params[] = $driverLorry['plate_number'];
            } else {
                // Fail closed when this queue schema cannot identify ownership.
                $where[] = '1 = 0';
            }
        } elseif ($driverId > 0 && in_array('driver_id', $queueCols, true)) {
            $where[] = "q.driver_id = ?";
            $params[] = $driverId;
        }
        if ($status !== '') {
            if (!in_array($status, $allowedStatuses, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid status.']);
                exit;
            }
            $where[] = "q.status = ?";
            $params[] = $status;
        }

        if ($where) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $priorityCol = in_array('priority', $queueCols, true) ? 'q.priority ASC, ' : '';
        $createdCol = in_array('created_at', $queueCols, true) ? 'q.created_at ASC' : 'q.id ASC';
        $sql .= " ORDER BY $priorityCol $createdCol LIMIT $limit";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        echo json_encode([
            'success' => true,
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
        // Support the older live schema used by this installation.
        if (!in_array('driver_id', $queueCols, true) ||
            !in_array('latitude', $queueCols, true) ||
            !in_array('longitude', $queueCols, true)) {
            $driverId = (int)($input['driver_id'] ?? 0);
            $locationName = trim((string)($input['location_name'] ?? $input['geolocation'] ?? ''));
            if ($driverId <= 0 || $locationName === '') {
                throw new RuntimeException('Driver and location are required.');
            }

            $driverStmt = $conn->prepare('SELECT name FROM users WHERE user_id = ? LIMIT 1');
            $driverStmt->execute([$driverId]);
            $driverName = (string)($driverStmt->fetchColumn() ?: 'Unassigned');
            $assignedLorryId = (int)($input['assigned_lorry_id'] ?? 0);
            $plateNumber = trim((string)($input['plate_number'] ?? ''));
            if ($assignedLorryId <= 0) {
                $lorryStmt = $conn->prepare('SELECT lorry_id, plate_number FROM lorries WHERE driver_id = ? LIMIT 1');
                $lorryStmt->execute([$driverId]);
                $assignedLorry = $lorryStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                $assignedLorryId = (int)($assignedLorry['lorry_id'] ?? 0);
                if ($plateNumber === '' && $assignedLorry) {
                    $plateNumber = (string)($assignedLorry['plate_number'] ?? '');
                }
            } elseif ($plateNumber === '') {
                $plateStmt = $conn->prepare('SELECT plate_number FROM lorries WHERE lorry_id = ? LIMIT 1');
                $plateStmt->execute([$assignedLorryId]);
                $plateNumber = (string)($plateStmt->fetchColumn() ?: '');
            }
            if (in_array('assigned_lorry_id', $queueCols, true) && $assignedLorryId <= 0) {
                throw new RuntimeException('A lorry assignment is required for this queue item.');
            }

            $legacyFields = ['geolocation', 'status'];
            $legacyValues = [$locationName, 'pending'];
            if (in_array('driver_name', $queueCols, true)) {
                $legacyFields[] = 'driver_name';
                $legacyValues[] = $driverName;
            }
            if (in_array('plate_number', $queueCols, true)) {
                $legacyFields[] = 'plate_number';
                $legacyValues[] = $plateNumber ?: null;
            }
            if (in_array('assigned_lorry_id', $queueCols, true) && $assignedLorryId > 0) {
                $legacyFields[] = 'assigned_lorry_id';
                $legacyValues[] = $assignedLorryId;
            }
            if (in_array('created_at', $queueCols, true)) {
                $legacyFields[] = 'created_at';
                $legacyValues[] = date('Y-m-d H:i:s');
            }

            $marks = implode(', ', array_fill(0, count($legacyFields), '?'));
            $conn->prepare(
                'INSERT INTO arrival_queue (' . implode(', ', $legacyFields) . ") VALUES ($marks)"
            )->execute($legacyValues);
            $id = (int)$conn->lastInsertId();
            $stmt = $conn->prepare("SELECT * FROM arrival_queue WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        requireColumn($queueCols, 'driver_id');
        requireColumn($queueCols, 'location_name');
        requireColumn($queueCols, 'latitude');
        requireColumn($queueCols, 'longitude');

        $driverId = isset($input['driver_id']) ? (int)$input['driver_id'] : 0;
        $locationName = isset($input['location_name']) ? trim((string)$input['location_name']) : null;
        $latitude = parseCoordinate($input['latitude'] ?? null, -90, 90);
        $longitude = parseCoordinate($input['longitude'] ?? null, -180, 180);
        $priority = isset($input['priority']) ? (int)$input['priority'] : 100;
        $priority = max(1, min($priority, 9999));
        $notes = isset($input['notes']) ? trim((string)$input['notes']) : null;
        $createdBy = isset($input['created_by']) && (int)$input['created_by'] > 0
            ? (int)$input['created_by']
            : null;

        if ($driverId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'driver_id is required.']);
            exit;
        }
        $assignedLorryId = (int)($input['assigned_lorry_id'] ?? 0);
        if (in_array('assigned_lorry_id', $queueCols, true)) {
            if ($assignedLorryId <= 0) {
                $lorryStmt = $conn->prepare('SELECT lorry_id FROM lorries WHERE driver_id = ? LIMIT 1');
                $lorryStmt->execute([$driverId]);
                $assignedLorryId = (int)($lorryStmt->fetchColumn() ?: 0);
            }
            if ($assignedLorryId <= 0) {
                throw new RuntimeException('A lorry assignment is required for this queue item.');
            }
        }
        if ($latitude === null || $longitude === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid latitude and longitude are required.']);
            exit;
        }

        $fields = ['driver_id', 'location_name', 'latitude', 'longitude'];
        $values = [$driverId, $locationName ?: null, $latitude, $longitude];
        $placeholders = ['?', '?', '?', '?'];

        if (in_array('assigned_lorry_id', $queueCols, true)) {
            $fields[] = 'assigned_lorry_id';
            $values[] = $assignedLorryId;
            $placeholders[] = '?';
        }

        if (in_array('created_by', $queueCols, true)) {
            $fields[] = 'created_by';
            $values[] = $createdBy;
            $placeholders[] = '?';
        }
        if (in_array('priority', $queueCols, true)) {
            $fields[] = 'priority';
            $values[] = $priority;
            $placeholders[] = '?';
        }
        if (in_array('notes', $queueCols, true)) {
            $fields[] = 'notes';
            $values[] = $notes ?: null;
            $placeholders[] = '?';
        }
        if (in_array('status', $queueCols, true)) {
            $fields[] = 'status';
            $values[] = 'pending';
            $placeholders[] = '?';
        }

        $sql = "INSERT INTO arrival_queue (" . implode(', ', $fields) . ")
                VALUES (" . implode(', ', $placeholders) . ")";
        $conn->prepare($sql)->execute($values);
        $id = (int)$conn->lastInsertId();

        $stmt = $conn->prepare("SELECT * FROM arrival_queue WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);

        echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'PUT') {
        $id = isset($input['id']) ? (int)$input['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'id is required for update.']);
            exit;
        }

        $existingStmt = $conn->prepare("SELECT * FROM arrival_queue WHERE id = ? LIMIT 1");
        $existingStmt->execute([$id]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Queue item not found.']);
            exit;
        }

        $fields = [];
        $params = [];

        if (array_key_exists('status', $input) && in_array('status', $queueCols, true)) {
            $newStatus = strtolower(trim((string)$input['status']));
            if (!in_array($newStatus, $allowedStatuses, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid status.']);
                exit;
            }
            $fields[] = 'status = ?';
            $params[] = $newStatus;
        }

        $map = [
            'assigned_lorry_id' => 'assigned_lorry_id',
            'priority' => 'priority',
            'location_name' => 'location_name',
            'notes' => 'notes',
        ];

        foreach ($map as $key => $column) {
            if (!array_key_exists($key, $input) || !in_array($column, $queueCols, true)) {
                continue;
            }
            if ($key === 'assigned_lorry_id') {
                $fields[] = "$column = ?";
                $params[] = ((int)$input[$key] > 0) ? (int)$input[$key] : null;
            } elseif ($key === 'priority') {
                $fields[] = "$column = ?";
                $params[] = max(1, min((int)$input[$key], 9999));
            } else {
                $fields[] = "$column = ?";
                $params[] = trim((string)$input[$key]) ?: null;
            }
        }

        if (array_key_exists('latitude', $input) && in_array('latitude', $queueCols, true)) {
            $lat = parseCoordinate($input['latitude'], -90, 90, true);
            if ($lat === false) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid latitude.']);
                exit;
            }
            $fields[] = 'latitude = ?';
            $params[] = $lat;
        }

        if (array_key_exists('longitude', $input) && in_array('longitude', $queueCols, true)) {
            $lng = parseCoordinate($input['longitude'], -180, 180, true);
            if ($lng === false) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid longitude.']);
                exit;
            }
            $fields[] = 'longitude = ?';
            $params[] = $lng;
        }

        $statusValue = isset($input['status']) ? strtolower(trim((string)$input['status'])) : ($existing['status'] ?? null);

        if ($statusValue === 'completed' && in_array('completed_at', $queueCols, true)) {
            if (!array_key_exists('completed_at', $input)) {
                $fields[] = 'completed_at = NOW()';
            }
        } elseif ($statusValue !== 'completed' && array_key_exists('status', $input) && in_array('completed_at', $queueCols, true)) {
            $fields[] = 'completed_at = NULL';
        }

        if (array_key_exists('completed_at', $input) && in_array('completed_at', $queueCols, true)) {
            $fields[] = 'completed_at = ?';
            $params[] = $input['completed_at'] ?: null;
        }

        if (in_array('updated_at', $queueCols, true)) {
            $fields[] = 'updated_at = NOW()';
        }

        if (!$fields) {
            echo json_encode(['success' => false, 'message' => 'No updatable fields provided.']);
            exit;
        }

        $sql = "UPDATE arrival_queue SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $id;
        $upd = $conn->prepare($sql);
        $upd->execute($params);

        // Keep an ongoing queue item linked to its driver's lorry. Queue
        // Management often changes only the status, so resolve the lorry from
        // driver_id when assigned_lorry_id was not supplied.
        if ($statusValue === 'ongoing') {
            $syncLorryId = (int)($input['assigned_lorry_id'] ?? ($existing['assigned_lorry_id'] ?? 0));
            if ($syncLorryId <= 0 && in_array('driver_id', $queueCols, true)) {
                $driverForQueue = (int)($existing['driver_id'] ?? 0);
                if ($driverForQueue > 0) {
                    $lorryLookup = $conn->prepare('SELECT lorry_id FROM lorries WHERE driver_id = ? LIMIT 1');
                    $lorryLookup->execute([$driverForQueue]);
                    $syncLorryId = (int)($lorryLookup->fetchColumn() ?: 0);
                    if ($syncLorryId > 0 && in_array('assigned_lorry_id', $queueCols, true)) {
                        $conn->prepare('UPDATE arrival_queue SET assigned_lorry_id = ? WHERE id = ?')->execute([$syncLorryId, $id]);
                    }
                }
            }
            if ($syncLorryId > 0) syncLorryDestination($conn, $queueCols, $id, $syncLorryId);
        }

        if ($statusValue === 'completed') {
            $completedLorryId = (int)($existing['assigned_lorry_id'] ?? 0);
            if ($completedLorryId <= 0 && in_array('driver_id', $queueCols, true)) {
                $lorryLookup = $conn->prepare('SELECT lorry_id FROM lorries WHERE driver_id = ? LIMIT 1');
                $lorryLookup->execute([(int)($existing['driver_id'] ?? 0)]);
                $completedLorryId = (int)($lorryLookup->fetchColumn() ?: 0);
            }
            if ($completedLorryId > 0) clearLorryDestinationIfMatching($conn, $completedLorryId, $existing);
        }

        $stmt = $conn->prepare("SELECT * FROM arrival_queue WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);

        echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'id is required.']);
            exit;
        }

        if (!in_array('status', $queueCols, true)) {
            throw new RuntimeException('Queue status column is missing.');
        }

        $stmt = $conn->prepare("UPDATE arrival_queue SET status = 'cancelled'" .
            (in_array('updated_at', $queueCols, true) ? ", updated_at = NOW()" : "") .
            " WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode([
            'success' => true,
            'updated' => $stmt->rowCount(),
        ]);
        exit;
    }

    http_response_code(405);
    header('Allow: GET, POST, PUT, DELETE, OPTIONS');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);

} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}

function requireColumn(array $columns, string $column): void {
    if (!in_array($column, $columns, true)) {
        throw new RuntimeException("arrival_queue.$column is missing.");
    }
}

function parseCoordinate($value, float $min, float $max, bool $allowNull = false) {
    if ($value === null || $value === '') {
        return $allowNull ? null : null;
    }
    if (!is_numeric($value)) {
        return $allowNull ? false : null;
    }
    $number = (float)$value;
    if (!is_finite($number) || $number < $min || $number > $max) {
        return $allowNull ? false : null;
    }
    return $number;
}

function syncLorryDestination(PDO $conn, array $queueCols, int $queueId, int $lorryId): void {
    if (!in_array('latitude', $queueCols, true) || !in_array('longitude', $queueCols, true)) {
        // Legacy queues store only the address. Keep the lorry state aligned;
        // the driver dashboard resolves the address to coordinates when it
        // starts the route.
        $conn->prepare("UPDATE lorries SET status = 'On Duty' WHERE lorry_id = ?")
            ->execute([$lorryId]);
        return;
    }

    $q = $conn->prepare("SELECT latitude, longitude, status FROM arrival_queue WHERE id = ? LIMIT 1");
    $q->execute([$queueId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['latitude'] === null || $row['longitude'] === null) {
        return;
    }

    $conn->prepare("
        UPDATE lorries
        SET destination_lat = ?, destination_long = ?, destination_set_at = NOW(), status = 'On Duty'
        WHERE lorry_id = ?
    ")->execute([(float)$row['latitude'], (float)$row['longitude'], $lorryId]);
}

function clearLorryDestinationIfMatching(PDO $conn, int $lorryId, array $queueRow): void {
    if (!isset($queueRow['latitude'], $queueRow['longitude']) ||
        $queueRow['latitude'] === null || $queueRow['longitude'] === null) {
        // Legacy queue rows have no coordinates. The lorry was resolved from
        // the row's driver/plate identity, so clear its active destination.
        $conn->prepare("UPDATE lorries
            SET destination_lat = NULL, destination_long = NULL,
                destination_set_at = NULL, status = 'Available'
            WHERE lorry_id = ?")->execute([$lorryId]);
        return;
    }
    $stmt = $conn->prepare("
        UPDATE lorries
        SET destination_lat = NULL, destination_long = NULL, destination_set_at = NULL, status = 'Available'
        WHERE lorry_id = ?
          AND destination_lat = ?
          AND destination_long = ?
    ");
    $stmt->execute([$lorryId, $queueRow['latitude'], $queueRow['longitude']]);
}
?>
