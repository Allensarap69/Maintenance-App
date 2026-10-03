<?php
/**
 * Mark customer notifications as read and remember seen booking IDs.
 * Called via AJAX when the customer opens the notification bell.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    require_once __DIR__ . '/../db.php';

    $customerId = (int) $_SESSION['user_id'];

    // Mark all health score notifications as read
    $stmt = $pdo->prepare("UPDATE customer_notifications SET is_read = 1 WHERE customer_id = ? AND is_read = 0");
    $stmt->execute([$customerId]);

    // Remember current upcoming booking IDs as seen
    $stmt = $pdo->prepare("
        SELECT id FROM bookings
        WHERE user_id = ? AND status IN ('pending','deposit_submitted','accepted')
    ");
    $stmt->execute([$customerId]);
    $seenBookingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!isset($_SESSION['seen_booking_ids']) || !is_array($_SESSION['seen_booking_ids'])) {
        $_SESSION['seen_booking_ids'] = [];
    }
    $_SESSION['seen_booking_ids'] = array_unique(array_merge($_SESSION['seen_booking_ids'], $seenBookingIds));

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("mark_notifications_read error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
