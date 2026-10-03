<?php
/**
 * Mark mechanic notifications as read by remembering the IDs currently shown
 * in the notification bell (newly assigned bookings and emergency requests).
 * Called via AJAX when the mechanic opens the notification bell.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mechanic') {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    require_once __DIR__ . '/../db.php';

    // Resolve the mechanic record linked to this user
    $mechanicId = null;
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT id FROM mechanics WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $mechanicId = $stmt->fetchColumn() ?: null;
    }

    if ($mechanicId) {
        $seenMap = [
            'mechanic_seen_booking_ids' => [
                "SELECT DISTINCT b.id FROM bookings b
                 LEFT JOIN booking_mechanics bm ON bm.booking_id = b.id
                 WHERE (b.mechanic_id = ? OR bm.mechanic_id = ?) AND b.status = 'assigned'",
                [$mechanicId, $mechanicId]
            ],
            'mechanic_seen_emergency_ids' => [
                "SELECT id FROM emergency_service_requests
                 WHERE assigned_mechanic_id = ? AND request_status = 'assigned'",
                [$mechanicId]
            ],
        ];

        foreach ($seenMap as $sessionKey => [$sql, $params]) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
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
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("mark_mechanic_notifications_read error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
