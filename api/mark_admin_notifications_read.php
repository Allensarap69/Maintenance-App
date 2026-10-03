<?php
/**
 * Mark admin notifications as read by remembering the IDs currently shown
 * in the notification bell (bookings, emergency requests, warranty claims,
 * low health score motorcycles).
 * Called via AJAX when the admin opens the notification bell.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    require_once __DIR__ . '/../db.php';

    $seenMap = [
        'admin_seen_booking_ids'   => "SELECT id FROM bookings WHERE status IN ('pending', 'unassigned', 'deposit_submitted')",
        'admin_seen_emergency_ids' => "SELECT id FROM emergency_service_requests WHERE request_status IN ('pending', 'new')",
        'admin_seen_warranty_ids'  => "SELECT id FROM warranty_claims WHERE claim_status = 'pending'",
        'admin_seen_health_ids'    => "SELECT id FROM motorcycles WHERE health_score < 60",
    ];

    foreach ($seenMap as $sessionKey => $sql) {
        try {
            $ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            $ids = [];
        }

        if (!isset($_SESSION[$sessionKey]) || !is_array($_SESSION[$sessionKey])) {
            $_SESSION[$sessionKey] = [];
        }
        $merged = array_values(array_unique(array_merge($_SESSION[$sessionKey], $ids)));
        // Cap stored IDs so the session does not grow unbounded
        $_SESSION[$sessionKey] = array_slice($merged, -500);
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("mark_admin_notifications_read error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
