<?php
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (session_status() === PHP_SESSION_NONE) session_start();

if (($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only administrators can set pickup dates.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are accepted.']);
    exit;
}

$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
$submittedToken = (string)($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

$bookingId = (int)($_POST['booking_id'] ?? 0);
$pickupDate = trim((string)($_POST['pickup_date'] ?? ''));

if ($bookingId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid booking is required.']);
    exit;
}

if ($pickupDate !== '') {
    $date = DateTime::createFromFormat('!Y-m-d', $pickupDate);
    $dateErrors = DateTime::getLastErrors();
    if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $pickupDate) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Please enter a valid pickup date.']);
        exit;
    }
}

try {
    require_once __DIR__ . '/../config/db.php';

    $booking = $conn->prepare('SELECT booking_id FROM booking WHERE booking_id = ? LIMIT 1');
    $booking->execute([$bookingId]);
    if (!$booking->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit;
    }

    $update = $conn->prepare('UPDATE booking SET pickup_date = ? WHERE booking_id = ?');
    $update->execute([$pickupDate !== '' ? $pickupDate : null, $bookingId]);

    echo json_encode([
        'success' => true,
        'message' => $pickupDate === '' ? 'Pickup date cleared.' : 'Pickup date saved.'
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save the pickup date.']);
}
