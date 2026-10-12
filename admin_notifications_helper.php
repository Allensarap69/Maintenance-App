<?php
/**
 * Admin notification helper
 * Centralizes the header-bell + sidebar-badge queries so the server-rendered
 * sidebar (admin_sidebar_template.php) and the live polling endpoint
 * (api/get_admin_notifications.php) always return the same data.
 *
 * Usage:
 *   require_once 'admin_notifications_helper.php';
 *   $adminNotif = fetch_admin_notifications($pdo);
 */

if (session_status() === PHP_SESSION_NONE) {
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
 * Build the admin notification payload.
 *
 * Returns:
 *   items              unseen notification items (session-seen filtered),
 *                      each with a stable 'key' for client-side dedupe
 *   count              count(items)
 *   pending_bookings   total pending/unassigned/deposit_submitted bookings
 *   pending_emergencies total pending/new emergency requests
 */
function fetch_admin_notifications($pdo) {
    $items = [];

    $adminSeenBookings  = $_SESSION['admin_seen_booking_ids'] ?? [];
    $adminSeenEmergency = $_SESSION['admin_seen_emergency_ids'] ?? [];
    $adminSeenWarranty  = $_SESSION['admin_seen_warranty_ids'] ?? [];
    $adminSeenHealth    = $_SESSION['admin_seen_health_ids'] ?? [];

    // Sidebar badge totals (not filtered by "seen")
    $pendingBookings = 0;
    try {
        $pendingBookings = (int) $pdo->query("
            SELECT COUNT(id) FROM bookings
            WHERE status IN ('pending', 'unassigned', 'deposit_submitted')
        ")->fetchColumn();
    } catch (PDOException $e) {
        $pendingBookings = 0;
    }

    $pendingEmergencies = 0;
    try {
        $pendingEmergencies = (int) $pdo->query("
            SELECT COUNT(id) FROM emergency_service_requests
            WHERE request_status IN ('pending', 'new')
        ")->fetchColumn();
    } catch (PDOException $e) {
        $pendingEmergencies = 0;
    }

    // Pending / new bookings needing admin action
    try {
        $sql = "
            SELECT b.id, b.status, b.schedule_date, b.schedule_start_time, u.name AS customer_name
            FROM bookings b
            JOIN users u ON b.user_id = u.id
            WHERE b.status IN ('pending', 'unassigned', 'deposit_submitted')
        ";
        if (!empty($adminSeenBookings)) {
            $sql .= " AND b.id NOT IN (" . implode(',', array_map('intval', $adminSeenBookings)) . ")";
        }
        $sql .= " ORDER BY b.schedule_date ASC, b.schedule_start_time ASC LIMIT 5";
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $label = $row['status'] === 'deposit_submitted' ? 'Deposit submitted'
                   : ($row['status'] === 'unassigned' ? 'Unassigned booking' : 'New booking');
            $items[] = [
                'key'        => 'booking-' . (int) $row['id'],
                'type'       => 'booking',
                'title'      => $label . ' #' . $row['id'],
                'message'    => ($row['customer_name'] ?: 'Customer') . ' - ' . date('M d, Y', strtotime($row['schedule_date'])),
                'link'       => 'manage_bookings.php',
                'time_label' => date('M d, Y g:i A', strtotime($row['schedule_date'] . ' ' . $row['schedule_start_time']))
            ];
        }
    } catch (PDOException $e) {
        error_log("Admin notif bookings error: " . $e->getMessage());
    }

    // Pending / new emergency requests
    try {
        $sql = "
            SELECT esr.id, esr.request_status, esr.created_at, u.name AS customer_name
            FROM emergency_service_requests esr
            JOIN users u ON esr.customer_id = u.id
            WHERE esr.request_status IN ('pending', 'new')
        ";
        if (!empty($adminSeenEmergency)) {
            $sql .= " AND esr.id NOT IN (" . implode(',', array_map('intval', $adminSeenEmergency)) . ")";
        }
        $sql .= " ORDER BY esr.created_at DESC LIMIT 5";
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'key'        => 'emergency-' . (int) $row['id'],
                'type'       => 'emergency',
                'title'      => 'Emergency Request #' . $row['id'],
                'message'    => ($row['customer_name'] ?: 'Customer') . ' needs assistance',
                'link'       => 'admin_emergency_requests.php',
                'time_label' => notifTimeAgo($row['created_at'])
            ];
        }
    } catch (PDOException $e) {
        error_log("Admin notif emergency error: " . $e->getMessage());
    }

    // Pending warranty claims
    try {
        $sql = "
            SELECT wc.id, wc.claim_date, u.name AS customer_name
            FROM warranty_claims wc
            JOIN warranties w ON wc.warranty_id = w.id
            LEFT JOIN users u ON w.customer_id = u.id
            WHERE wc.claim_status = 'pending'
        ";
        if (!empty($adminSeenWarranty)) {
            $sql .= " AND wc.id NOT IN (" . implode(',', array_map('intval', $adminSeenWarranty)) . ")";
        }
        $sql .= " ORDER BY wc.claim_date DESC LIMIT 5";
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'key'        => 'warranty-' . (int) $row['id'],
                'type'       => 'warranty',
                'title'      => 'Warranty Claim #' . $row['id'],
                'message'    => ($row['customer_name'] ?: 'Customer') . ' - ' . date('M d, Y', strtotime($row['claim_date'])),
                'link'       => 'admin_warranty.php',
                'time_label' => notifTimeAgo($row['claim_date'])
            ];
        }
    } catch (PDOException $e) {
        error_log("Admin notif warranty error: " . $e->getMessage());
    }

    // Motorcycles with critical health scores
    try {
        $sql = "
            SELECT m.id, m.health_score, m.brand, m.model, m.plate_number, u.name AS customer_name
            FROM motorcycles m
            LEFT JOIN users u ON m.user_id = u.id
            WHERE m.health_score < 60
        ";
        if (!empty($adminSeenHealth)) {
            $sql .= " AND m.id NOT IN (" . implode(',', array_map('intval', $adminSeenHealth)) . ")";
        }
        $sql .= " ORDER BY m.health_score ASC LIMIT 5";
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'key'        => 'health-' . (int) $row['id'],
                'type'       => 'health',
                'title'      => 'Low Health Score',
                'message'    => $row['brand'] . ' ' . $row['model'] . ' (' . $row['plate_number'] . ') - health ' . $row['health_score'],
                'link'       => 'admin_health_scores.php',
                'time_label' => ''
            ];
        }
    } catch (PDOException $e) {
        error_log("Admin notif health error: " . $e->getMessage());
    }

    return [
        'count'               => count($items),
        'items'               => $items,
        'pending_bookings'    => $pendingBookings,
        'pending_emergencies' => $pendingEmergencies,
    ];
}
