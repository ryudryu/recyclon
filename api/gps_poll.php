<?php
/**
 * GPS Poll Endpoint
 * Read-only JSON feed for the fleet tracker.
 */
require_once __DIR__ . '/../config/session.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!in_array(strtolower(trim((string)($_SESSION['role'] ?? ''))), ['admin', 'staff'], true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Login required.']);
    exit;
}
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    $trackers = $conn->query(
        "SELECT l.lorry_id, l.plate_number, l.status, l.current_lat, l.current_long,
                l.last_updated, l.destination_lat, l.destination_long, l.destination_set_at,
                l.driver_id, COALESCE(u.name, 'Unassigned') AS driver_name
         FROM lorries l
         LEFT JOIN users u ON l.driver_id = u.user_id
         ORDER BY l.lorry_id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $now = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));
    $nowTs = $now->getTimestamp();

    foreach ($trackers as &$t) {
        $t['stale'] = true;
        $t['speed_kmh'] = null;
        $t['heading'] = null;

        if (!empty($t['last_updated'])) {
            $updatedTs = strtotime($t['last_updated']);
            if ($updatedTs !== false) {
                $t['stale'] = ($nowTs - $updatedTs) > 300;
                // A driver is considered actively On Duty only while the
                // browser/device is sending a recent GPS heartbeat.
                if (($nowTs - $updatedTs) > 30 && strtolower((string)($t['status'] ?? '')) === 'on duty') {
                    $t['status'] = 'Available';
                }
            }
        }

        if ($t['current_lat'] !== null && $t['current_long'] !== null) {
            $latestLog = $conn->prepare("
                SELECT latitude, longitude, recorded_at
                FROM gps_log
                WHERE lorry_id = ?
                ORDER BY recorded_at DESC
                LIMIT 2
            ");
            $latestLog->execute([(int)$t['lorry_id']]);
            $logs = $latestLog->fetchAll(PDO::FETCH_ASSOC);

            if (count($logs) === 2) {
                $curr = $logs[0];
                $prev = $logs[1];
                $currTs = strtotime($curr['recorded_at']);
                $prevTs = strtotime($prev['recorded_at']);
                $diffSec = $currTs - $prevTs;

                if ($diffSec > 0) {
                    $distKm = haversineDistance(
                        (float)$curr['latitude'],
                        (float)$curr['longitude'],
                        (float)$prev['latitude'],
                        (float)$prev['longitude']
                    );
                    $speedKmh = $distKm / ($diffSec / 3600);

                    if ($speedKmh >= 0 && $speedKmh < 300) {
                        $t['speed_kmh'] = round($speedKmh, 1);
                        if ($speedKmh > 0.5) {
                            $t['heading'] = calculateHeading(
                                (float)$prev['latitude'],
                                (float)$prev['longitude'],
                                (float)$curr['latitude'],
                                (float)$curr['longitude']
                            );
                        }
                    } else {
                        $t['speed_kmh'] = 0;
                    }
                } else {
                    $t['speed_kmh'] = 0;
                }
            }
        }
    }
    unset($t);

    $queueSummary = [];
    $queueExists = false;
    $queueCols = [];
    try {
        $queueExists = app_table_exists($conn, 'arrival_queue');
        if ($queueExists) {
            $queueCols = app_table_columns($conn, 'arrival_queue');

            if (in_array('assigned_lorry_id', $queueCols, true) && in_array('status', $queueCols, true)) {
                $select = "assigned_lorry_id, COUNT(*) AS cnt";
                if (in_array('location_name', $queueCols, true)) {
                    $select .= ", string_agg(location_name, '|') AS locs";
                }
                $qst = $conn->query("
                    SELECT $select
                    FROM arrival_queue
                    WHERE status = 'ongoing'
                    GROUP BY assigned_lorry_id
                ");
                foreach ($qst->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $queueSummary[(int)$row['assigned_lorry_id']] = $row;
                }
            }
        }
    } catch (Exception $e) {
        $queueSummary = [];
    }

    // Keep simplified queue schemas linked to the fleet map as well. This
    // lets the admin retain an assigned target even when the driver is not
    // currently sending GPS updates.
    try {
        if ($queueExists && in_array('status', $queueCols, true)) {
            $lookupParts = [];
            if (in_array('plate_number', $queueCols, true)) $lookupParts[] = 'plate_number';
            if (in_array('driver_name', $queueCols, true)) $lookupParts[] = 'driver_name';
            if ($lookupParts) {
                $ongoingRows = $conn->query("SELECT " . implode(', ', $lookupParts) . " FROM arrival_queue WHERE status = 'ongoing'")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($trackers as &$tracker) {
                    foreach ($ongoingRows as $ongoingRow) {
                        $samePlate = in_array('plate_number', $lookupParts, true)
                            && strcasecmp(trim((string)($ongoingRow['plate_number'] ?? '')), trim((string)$tracker['plate_number'])) === 0;
                        $sameDriver = in_array('driver_name', $lookupParts, true)
                            && strcasecmp(trim((string)($ongoingRow['driver_name'] ?? '')), trim((string)$tracker['driver_name'])) === 0;
                        if ($samePlate || $sameDriver) {
                            $tracker['has_ongoing_queue'] = true;
                            break;
                        }
                    }
                }
                unset($tracker);
            }
        }
    } catch (Exception $e) {
        // Queue linkage is optional and must not break GPS polling.
    }

    // Reconcile the fleet state with the queue. This is especially important
    // for the legacy queue schema, which has no assigned_lorry_id or GPS
    // columns and otherwise allows a lorry to remain On Duty forever.
    try {
        $ongoingByLorry = [];
        if ($queueExists && in_array('status', $queueCols, true)) {
            if (in_array('assigned_lorry_id', $queueCols, true)) {
                $rows = $conn->query("SELECT assigned_lorry_id FROM arrival_queue WHERE status = 'ongoing' AND assigned_lorry_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) $ongoingByLorry[(int)$row['assigned_lorry_id']] = true;
            } else {
                $identityColumns = [];
                if (in_array('plate_number', $queueCols, true)) $identityColumns[] = 'plate_number';
                if (in_array('driver_name', $queueCols, true)) $identityColumns[] = 'driver_name';
                if ($identityColumns) {
                    $rows = $conn->query("SELECT " . implode(', ', $identityColumns) . " FROM arrival_queue WHERE status = 'ongoing'")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($trackers as &$tracker) {
                        foreach ($rows as $row) {
                            $samePlate = in_array('plate_number', $identityColumns, true)
                                && trim((string)($row['plate_number'] ?? '')) !== ''
                                && strcasecmp(trim((string)$row['plate_number']), trim((string)$tracker['plate_number'])) === 0;
                            $sameDriver = in_array('driver_name', $identityColumns, true)
                                && trim((string)($row['driver_name'] ?? '')) !== ''
                                && strcasecmp(trim((string)$row['driver_name']), trim((string)$tracker['driver_name'])) === 0;
                            if ($samePlate || $sameDriver) {
                                $ongoingByLorry[(int)$tracker['lorry_id']] = true;
                                break;
                            }
                        }
                    }
                    unset($tracker);
                }
            }
        }

        foreach ($trackers as &$tracker) {
            $lorryKey = (int)$tracker['lorry_id'];
            if (isset($ongoingByLorry[$lorryKey]) && strtolower((string)($tracker['status'] ?? '')) !== 'on duty') {
                $tracker['status'] = 'On Duty';
            } elseif (!isset($ongoingByLorry[$lorryKey]) &&
                (strtolower((string)($tracker['status'] ?? '')) === 'on duty' ||
                 $tracker['destination_lat'] !== null || $tracker['destination_long'] !== null)) {
                $tracker['status'] = 'Available';
                $tracker['destination_lat'] = null;
                $tracker['destination_long'] = null;
                $tracker['destination_set_at'] = null;
            }
        }
        unset($tracker);
    } catch (Exception $e) {
        // State reconciliation must never prevent the fleet feed from loading.
    }

    echo json_encode([
        'success' => true,
        'trackers' => $trackers,
        'queue' => $queueSummary,
        'server_time' => $now->format('H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load lorry tracking data.',
    ]);
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
