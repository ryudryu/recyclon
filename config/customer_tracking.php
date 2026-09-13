<?php

/**
 * Return the customer's active lorry only when it has a matching ongoing
 * arrival-queue item. This supports both the old and rich queue schemas.
 */
function fetchCustomerTracking(PDO $conn, int $customerId): ?array
{
    $queueExists = app_table_exists($conn, 'arrival_queue');
    if (!$queueExists) return null;

    $queueCols = array_map(
        'strtolower',
        app_table_columns($conn, 'arrival_queue')
    );

    if (in_array('booking_id', $queueCols, true)) {
        $queueJoin = "INNER JOIN arrival_queue q
                      ON q.booking_id = b.booking_id AND q.status = 'ongoing'";
    } elseif (in_array('geolocation', $queueCols, true) && in_array('plate_number', $queueCols, true)) {
        $queueJoin = "INNER JOIN arrival_queue q
                      ON q.geolocation = b.address
                     AND q.plate_number = l.plate_number
                     AND q.status = 'ongoing'";
    } elseif (in_array('assigned_lorry_id', $queueCols, true)) {
        $queueJoin = "INNER JOIN arrival_queue q
                      ON q.assigned_lorry_id = l.lorry_id AND q.status = 'ongoing'";
    } else {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT b.booking_id, b.address, b.status,
               l.lorry_id, l.plate_number, l.status AS lorry_status,
               l.current_lat, l.current_long,
               l.destination_lat, l.destination_long,
               l.last_updated
        FROM booking b
        INNER JOIN lorries l ON l.lorry_id = b.lorry_id
        $queueJoin
        WHERE b.customer_id = ?
          AND b.status NOT IN ('Completed', 'Cancelled')
        ORDER BY b.created_at DESC, b.booking_id DESC
        LIMIT 1
    ");
    $stmt->execute([$customerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
