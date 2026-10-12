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

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? 'customer') !== 'customer')) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../customer_notifications_helper.php';

    $data = fetch_customer_notifications($pdo, (int) $_SESSION['user_id']);
    unset($data['booking_items']);
    $data['success'] = true;

    echo json_encode($data);
} catch (Throwable $e) {
    error_log("get_customer_notifications error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
