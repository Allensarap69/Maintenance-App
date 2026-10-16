<?php
/**
 * Live customer notifications endpoint.
 * Returns the same payload the customer sidebar bell renders so the page
 * can poll for new updates without a manual refresh.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$customerId = (int)($_SESSION['user_id'] ?? 0);
$customerRole = $_SESSION['role'] ?? 'customer';

// Release the session lock BEFORE hitting the database. PHP locks the
// session file for the whole request, so a slow poll (remote DB round
// trips) would otherwise block every real page navigation for this user.
session_write_close();

if (!$customerId || $customerRole !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../customer_notifications_helper.php';

    $data = fetch_customer_notifications($pdo, $customerId);
    unset($data['booking_items']);
    $data['success'] = true;

    echo json_encode($data);
} catch (Throwable $e) {
    error_log("get_customer_notifications error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
