<?php
/**
 * Shared visual metadata for waste categories: icon glyph + accent colors.
 * Falls back to a neutral slate style for any category not explicitly listed.
 */
function wasteMeta($categoryName) {
    $map = [
        'Paper Box' => ['icon' => '📦', 'color' => '#b45309', 'bg' => '#fef3c7', 'bar' => '#d97706', 'code' => 'PAPE'],
        'Paper'     => ['icon' => '📄', 'color' => '#1d4ed8', 'bg' => '#dbeafe', 'bar' => '#2563eb', 'code' => 'PAPE'],
        'Metal'     => ['icon' => '⚙️', 'color' => '#475569', 'bg' => '#f1f5f9', 'bar' => '#64748b', 'code' => 'META'],
        'Plastic'   => ['icon' => '♻️', 'color' => '#16a34a', 'bg' => '#dcfce7', 'bar' => '#22c55e', 'code' => 'PLAS'],
        'Rubbish'   => ['icon' => '🗑️', 'color' => '#dc2626', 'bg' => '#fee2e2', 'bar' => '#ef4444', 'code' => 'RUBB'],
        'Minyak Masak'   => ['icon' => '🧴', 'color' => '#e9e509', 'bg' => '#ffe70e', 'bar' => '#efc744', 'code' => 'OIL'],
        'Tin'=> ['icon' => '🥫', 'color' => '#fefffb', 'bg' => '#e9e9e8', 'bar' => '#fffdf8', 'code' => 'TIN'],
    ];
    if (isset($map[$categoryName])) return $map[$categoryName];
    return ['icon' => '🔹', 'color' => '#334155', 'bg' => '#f1f5f9', 'bar' => '#64748b', 'code' => strtoupper(substr($categoryName, 0, 4))];
}

/** Booking status → display label (DB keeps 'Scheduled', UI shows 'Confirmed'). */
function bookingStatusLabel($status) {
    return $status === 'Scheduled' ? 'Confirmed' : $status;
}

function getDriverQueueStatus(PDO $conn, array $booking): ?string {
    try {
        $queueExists = app_table_exists($conn, 'arrival_queue');
        if (!$queueExists) {
            return null;
        }

        $columns = app_table_columns($conn, 'arrival_queue');
        if (!in_array('status', $columns, true)) {
            return null;
        }

        $where = [];
        $params = [];

        if (in_array('booking_id', $columns, true) && !empty($booking['booking_id'])) {
            $where[] = 'booking_id = ?';
            $params[] = (int) $booking['booking_id'];
        } elseif (in_array('geolocation', $columns, true) && in_array('plate_number', $columns, true)) {
            if (empty($booking['address']) || empty($booking['lorry_id'])) {
                return null;
            }

            $plateStmt = $conn->prepare('SELECT plate_number FROM lorries WHERE lorry_id = ? LIMIT 1');
            $plateStmt->execute([(int) $booking['lorry_id']]);
            $plateNumber = $plateStmt->fetchColumn();
            if ($plateNumber === false || $plateNumber === '') {
                return null;
            }

            $where[] = 'geolocation = ?';
            $params[] = (string) $booking['address'];
            $where[] = 'plate_number = ?';
            $params[] = (string) $plateNumber;
        } elseif (in_array('assigned_lorry_id', $columns, true) && !empty($booking['lorry_id'])) {
            $where[] = 'assigned_lorry_id = ?';
            $params[] = (int) $booking['lorry_id'];
        } elseif (in_array('driver_id', $columns, true) && !empty($booking['driver_id'])) {
            $where[] = 'driver_id = ?';
            $params[] = (int) $booking['driver_id'];
        } else {
            return null;
        }

        $order = in_array('id', $columns, true) ? ' ORDER BY id DESC' : '';
        $stmt = $conn->prepare('SELECT status FROM arrival_queue WHERE ' . implode(' AND ', $where) . $order . ' LIMIT 1');
        $stmt->execute($params);
        $status = $stmt->fetchColumn();

        return $status === false ? null : strtolower(trim((string) $status));
    } catch (PDOException $e) {
        return null;
    }
}

/** Booking/payment status → badge color classes. */
function statusBadgeClass($status) {
    $label = bookingStatusLabel($status);
    $map = [
        'Pending'   => 'badge-status-pending',
        'Confirmed' => 'badge-status-confirmed',
        'Completed' => 'badge-status-completed',
        'Cancelled' => 'badge-status-cancelled',
        'Paid'      => 'badge-status-completed',
    ];
    return $map[$label] ?? 'badge-status-pending';
}
