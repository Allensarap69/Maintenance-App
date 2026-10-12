<?php
/**
 * Customer notification helper
 * Centralizes the header-bell queries so the server-rendered sidebar
 * (customer_sidebar.php) and the live polling endpoint
 * (api/get_customer_notifications.php) always return the same data.
 *
 * Usage:
 *   require_once 'customer_notifications_helper.php';
 *   $custNotif = fetch_customer_notifications($pdo, $customerId);
 */

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

if (!function_exists('notifTimeAgo')) {
    function notifTimeAgo($dt) {
        if (empty($dt)) return '';
        $ts = strtotime($dt);
        if (!$ts) return '';
        $now = time();
        if ($ts > $now) return date('M d, Y', $ts);
        $diff = $now - $ts;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';
        return date('M d, Y', $ts);
    }
}

/**
 * Build the customer notification payload for the header bell.
 *
 * Returns:
 *   items          merged bell items (parts first, then health, then
 *                  bookings) each with a stable 'key' for client-side dedupe
 *   count          count(items)
 *   booking_items  the unseen upcoming-booking subset (sidebar badge)
 *   unread_health  number of unread health-score items (sidebar badge)
 */
function fetch_customer_notifications($pdo, $customerId) {
    $customerId = (int) $customerId;

    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        motorcycle_id INT NOT NULL,
        health_score INT NOT NULL,
        recommendation TEXT,
        is_read TINYINT(1) DEFAULT 0,
        is_applied TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_customer (customer_id),
        INDEX idx_motorcycle (motorcycle_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Health score notifications unread
    $unreadHealthItems = [];
    $stmt = $pdo->prepare("
        SELECT n.id, n.motorcycle_id, n.health_score, n.recommendation, n.created_at,
               m.brand, m.model, m.plate_number
        FROM customer_notifications n
        JOIN motorcycles m ON n.motorcycle_id = m.id
        WHERE n.customer_id = ? AND n.is_read = 0
        ORDER BY n.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$customerId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $unreadHealthItems[] = [
            'key' => 'health-' . (int) $row['id'],
            'type' => 'health',
            'title' => 'Health Score Alert',
            'message' => ($row['brand'] . ' ' . $row['model'] . ' (' . $row['plate_number'] . ') - health ' . $row['health_score']),
            'link' => 'customer_health_score.php',
            'time_label' => notifTimeAgo($row['created_at'])
        ];
    }

    // Unseen upcoming bookings for bell
    $seenBookingIds = $_SESSION['seen_booking_ids'] ?? [];
    $unreadBookingItems = [];
    if (!empty($seenBookingIds)) {
        $placeholders = implode(',', array_fill(0, count($seenBookingIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id, status, schedule_date, schedule_start_time, vehicle_id
            FROM bookings
            WHERE user_id = ? AND status IN ('pending','deposit_submitted','accepted') AND id NOT IN ($placeholders)
            ORDER BY schedule_date ASC, schedule_start_time ASC
            LIMIT 5
        ");
        $stmt->execute(array_merge([$customerId], $seenBookingIds));
    } else {
        $stmt = $pdo->prepare("
            SELECT id, status, schedule_date, schedule_start_time, vehicle_id
            FROM bookings
            WHERE user_id = ? AND status IN ('pending','deposit_submitted','accepted')
            ORDER BY schedule_date ASC, schedule_start_time ASC
            LIMIT 5
        ");
        $stmt->execute([$customerId]);
    }
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $unreadBookingItems[] = [
            'key' => 'booking-' . (int) $row['id'],
            'type' => 'booking',
            'title' => 'Booking #' . $row['id'],
            'message' => ucfirst(str_replace('_', ' ', $row['status'])) . ' on ' . date('M d, Y', strtotime($row['schedule_date'])),
            'link' => 'my_bookings.php',
            'time_label' => date('M d, Y g:i A', strtotime($row['schedule_date'] . ' ' . $row['schedule_start_time']))
        ];
    }

    // Parts awaiting the customer's approval (computed live — clears once decided)
    $unreadPartItems = [];
    try {
        $stmt = $pdo->prepare("
            SELECT bp.booking_id, COUNT(*) AS cnt, SUM(bp.quantity * bp.unit_price) AS total
            FROM booking_parts bp
            JOIN bookings b ON b.id = bp.booking_id
            WHERE b.user_id = ? AND bp.status = 'pending'
              AND b.status IN ('pending','deposit_submitted','accepted','assigned','in_progress')
            GROUP BY bp.booking_id
            ORDER BY bp.booking_id DESC
            LIMIT 5
        ");
        $stmt->execute([$customerId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $unreadPartItems[] = [
                'key' => 'part-' . (int) $row['booking_id'],
                'type' => 'part',
                'title' => 'Parts approval needed',
                'message' => 'Booking #' . $row['booking_id'] . ': ' . (int)$row['cnt'] . ' part(s) added — ₱' . number_format((float)$row['total'], 2) . ' awaiting your approval',
                'link' => 'my_bookings.php',
                'time_label' => ''
            ];
        }
    } catch (PDOException $e) {
        error_log('Sidebar parts notification error: ' . $e->getMessage());
    }

    $items = array_merge($unreadPartItems, $unreadHealthItems, $unreadBookingItems);

    return [
        'items'          => $items,
        'count'          => count($items),
        'booking_items'  => $unreadBookingItems,
        'unread_health'  => count($unreadHealthItems),
        'unread_bookings' => count($unreadBookingItems),
    ];
}
