<?php
/**
 * Live admin notifications endpoint.
 * Returns the same payload the sidebar bell renders so the page can poll
 * for new bookings / emergency requests / warranty claims / low health
 * scores without a manual refresh.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../admin_notifications_helper.php';

    $data = fetch_admin_notifications($pdo);
    $data['success'] = true;

    echo json_encode($data);
} catch (Throwable $e) {
    error_log("get_admin_notifications error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
