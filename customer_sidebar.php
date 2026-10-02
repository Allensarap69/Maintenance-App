<?php
/**
 * Customer Sidebar Template
 * Include this file in customer pages to add the consistent sidebar navigation
 * Usage: include 'customer_sidebar.php';
 * 
 * Variables expected:
 * - $username: Current user's username
 * - $totalUpcoming: Number of upcoming bookings (optional, defaults to 0)
 * - $show_badge: Whether to show notification badge (optional, defaults to false)
 * - $active_page: The current page filename for highlighting active menu item (optional)
 */

if (!isset($username)) $username = $_SESSION['username'] ?? 'Customer';
if (!isset($totalUpcoming)) $totalUpcoming = 0;
if (!isset($show_badge)) $show_badge = $totalUpcoming > 0;
if (!isset($active_page)) $active_page = basename($_SERVER['PHP_SELF']);

$healthScoreNotificationCount = 0;
$unreadHealthCount = 0;
$customerNotificationItems = [];
$unreadBookingItems = [];

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
if (isset($_SESSION['user_id'])) {
    try {
        require_once 'db.php';
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

        // Total upcoming bookings for sidebar badge
        if ($totalUpcoming === 0) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM bookings
                WHERE user_id = ? AND status IN ('pending','deposit_submitted','accepted')
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $totalUpcoming = (int) $stmt->fetchColumn();
        }

        // Health score unapplied notifications for sidebar badge
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT n.motorcycle_id)
            FROM customer_notifications n
            JOIN motorcycles m ON n.motorcycle_id = m.id
            WHERE n.customer_id = ? AND n.is_applied = 0
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $healthScoreNotificationCount = (int) $stmt->fetchColumn();

        // --- Unread notification count for top bell ---

        // Health score notifications unread
        $unreadHealthCount = 0;
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
        $stmt->execute([$_SESSION['user_id']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $unreadHealthItems[] = [
                'type' => 'health',
                'title' => 'Health Score Alert',
                'message' => ($row['brand'] . ' ' . $row['model'] . ' (' . $row['plate_number'] . ') - health ' . $row['health_score']),
                'link' => 'customer_health_score.php',
                'time_label' => notifTimeAgo($row['created_at'])
            ];
        }
        $unreadHealthCount = count($unreadHealthItems);

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
            $stmt->execute(array_merge([$_SESSION['user_id']], $seenBookingIds));
        } else {
            $stmt = $pdo->prepare("
                SELECT id, status, schedule_date, schedule_start_time, vehicle_id
                FROM bookings
                WHERE user_id = ? AND status IN ('pending','deposit_submitted','accepted')
                ORDER BY schedule_date ASC, schedule_start_time ASC
                LIMIT 5
            ");
            $stmt->execute([$_SESSION['user_id']]);
        }
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $unreadBookingItems[] = [
                'type' => 'booking',
                'title' => 'Booking #' . $row['id'],
                'message' => ucfirst(str_replace('_', ' ', $row['status'])) . ' on ' . date('M d, Y', strtotime($row['schedule_date'])),
                'link' => 'my_bookings.php',
                'time_label' => date('M d, Y g:i A', strtotime($row['schedule_date'] . ' ' . $row['schedule_start_time']))
            ];
        }

        $customerNotificationItems = array_merge($unreadHealthItems, $unreadBookingItems);
    } catch (PDOException $e) {
        error_log("Sidebar notification query error: " . $e->getMessage());
    }
}

$totalUpcoming = count($unreadBookingItems);
$healthScoreNotificationCount = $unreadHealthCount;
$totalNotificationCount = count($customerNotificationItems);
?>

<script>
    // Global theme state: apply saved theme before first paint to avoid a light-theme flash
    (function () {
        var t = localStorage.getItem('theme');
        if (t !== 'dark' && t !== 'light') {
            t = (localStorage.getItem('adminDarkMode') === '1' || localStorage.getItem('customerDarkMode') === '1') ? 'dark' : 'light';
            localStorage.setItem('theme', t);
            localStorage.removeItem('adminDarkMode');
            localStorage.removeItem('customerDarkMode');
        }
        document.documentElement.setAttribute('data-theme', t);
    })();
</script>

<!-- Sidebar CSS -->
<style>
    /* --- Global Styles --- */
    body {
        margin: 0;
        padding: 0;
        overflow-x: hidden;
        width: 100%;
    }

    /* --- Sidebar Navigation --- */
    .sidebar {
        width: 280px;
        background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
        position: fixed;
        top: 0;
        left: 0;
        right: auto;
        bottom: auto;
        height: 100vh;
        z-index: 1040;
        overflow-y: auto;
        transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.4s cubic-bezier(0.4, 0, 0.2, 1), visibility 0.4s cubic-bezier(0.4, 0, 0.2, 1), max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 4px 0 30px rgba(0, 0, 0, 0.2);
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* IE and Edge */
        border-right: none;
        display: flex;
        flex-direction: column;
        transform: none;
    }

    .sidebar::-webkit-scrollbar {
        display: none; /* Chrome, Safari, Opera */
    }

    .sidebar.collapsed {
        width: 80px;
    }

    .sidebar-header {
        padding: 12px 20px;
        border-bottom: none;
        background: transparent;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-shrink: 0;
        position: relative;
        height: 60px;
    }

    .sidebar-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: #FACC15;
    }

    .sidebar-menu {
        padding: 20px 0;
        flex: 1;
        overflow-y: auto;
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* IE and Edge */
    }

    .sidebar-menu::-webkit-scrollbar {
        display: none; /* Chrome, Safari, Opera */
    }

    .sidebar-brand {
        font-weight: 700;
        font-size: 1.2rem;
        color: white;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .sidebar.collapsed .sidebar-brand span {
        display: none;
    }

    .sidebar-collapse-btn {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: white;
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
    }

    .sidebar-collapse-btn:hover {
        background: rgba(255, 255, 255, 0.2);
    }

    .sidebar-brand i,
    .sidebar-brand svg {
        color: #FACC15;
        background: none;
        -webkit-background-clip: unset;
        background-clip: unset;
        width: 34px;
        height: 34px;
        flex-shrink: 0;
    }

    .sidebar-brand span {
        color: white;
    }

    .sidebar-section {
        margin-bottom: 25px;
    }

    .sidebar-section-title {
        color: rgba(255, 255, 255, 0.5);
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
        padding: 0 20px;
        margin-bottom: 10px;
    }

    .sidebar.collapsed .sidebar-section-title {
        display: none;
    }

    .sidebar-nav-item {
        display: flex;
        align-items: center;
        padding: 12px 20px;
        color: #ffffff;
        text-decoration: none;
        
        border-radius: 12px;
        margin: 4px 15px;
        position: relative;
        overflow: hidden;
        gap: 12px;
    }

    .sidebar-nav-item::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 3px;
        background: #FACC15;
        transform: scaleY(0);
        transition: transform 0.3s ease;
    }

    .sidebar-nav-item:hover {
        background: rgba(255, 255, 255, 0.1);
        color: white;
    }

    .sidebar-nav-item.active {
        background: #FACC15;
        color: #111827 !important;
    }

    .sidebar-nav-item:hover::before, .sidebar-nav-item.active::before {
        transform: scaleY(1);
    }

    .sidebar:not(.collapsed) .sidebar-nav-item:hover {
        transform: none;
    }

    .sidebar:not(.collapsed) .sidebar-nav-item.active {
        transform: none;
    }

    .sidebar-nav-item i {
        font-size: 1.1rem;
        width: 20px;
        height: 20px;
        text-align: center;
        flex-shrink: 0;
    }

    .sidebar-nav-item span {
        font-size: 0.85rem;
        font-weight: 500;
    }

    .sidebar.collapsed .sidebar-nav-item span {
        display: none;
    }

    .sidebar-nav-item .badge {
        margin-left: auto;
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        color: white;
        font-size: 0.75rem;
        padding: 3px 10px;
        border-radius: 20px;
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0%, 100% {
            transform: scale(1);
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4);
        }
        50% {
            transform: scale(1.05);
            box-shadow: 0 0 0 10px rgba(239, 68, 68, 0.2);
        }
    }

    .sidebar.collapsed .sidebar-nav-item .badge {
        position: absolute;
        top: 8px;
        right: 8px;
    }

    .sidebar.collapsed .sidebar-nav-item {
        padding: 12px 28px;
        justify-content: center;
        margin: 4px 15px;
    }

    .sidebar.collapsed .sidebar-nav-item:hover::after {
        content: attr(data-tooltip);
        position: absolute;
        left: 100%;
        top: 50%;
        transform: translateY(-50%);
        background: #0f172a;
        color: white;
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 0.85rem;
        white-space: nowrap;
        margin-left: 10px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        z-index: 1050;
        opacity: 0;
        animation: fadeIn 0.3s ease forwards;
    }

    .sidebar-footer {
        padding: 20px;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        background: rgba(0, 0, 0, 0.2);
        margin-top: auto;
    }

    .sidebar.collapsed .sidebar-footer {
        padding: 20px 10px;
        text-align: center;
    }

    .sidebar-footer-text {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        color: white;
        font-size: 0.8rem;
        font-weight: 600;
        margin-bottom: 8px;
    }

    .sidebar-footer-text i,
    .sidebar-footer-text svg {
        color: #FACC15;
        background: none;
        -webkit-background-clip: unset;
        background-clip: unset;
        width: 20px;
        height: 20px;
        flex-shrink: 0;
    }

    .sidebar.collapsed .sidebar-footer-text span {
        display: none;
    }

    .sidebar-footer-copyright {
        text-align: center;
        color: rgba(255, 255, 255, 0.5);
        font-size: 0.65rem;
    }

    .sidebar.collapsed .sidebar-footer-copyright {
        display: none;
    }

    /* --- Main Content Area --- */
    .main-content {
        flex: 1;
        margin-left: 280px;
        padding: 0;
        min-height: 100vh;
        transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        width: calc(100% - 280px);
    }

    .main-content.expanded {
        margin-left: 80px;
        width: calc(100% - 80px);
    }

    .main-content.expanded .top-bar {
        left: 80px;
    }

    .content-area {
        padding: 30px;
        animation: fadeIn 0.5s ease-out;
        overflow-y: auto;
        overflow-x: hidden;
        flex: 1;
        width: 100%;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .top-bar {
        background: #ffffff;
        padding: 12px 30px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: fixed;
        top: 0;
        left: 280px;
        right: 0;
        z-index: 1030;
        border-bottom: none;
        height: 60px;
        transition: left 0.3s ease;
    }

    .top-bar::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: #FACC15;
    }

    .top-bar-title {
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--text-dark);
    }

    .top-bar-user {
        display: flex;
        align-items: center;
        gap: 12px;
        cursor: pointer;
        position: relative;
        padding: 0 12px;
        border-radius: 12px;
        transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
    }

    .top-bar-user:hover {
        background: rgba(0, 0, 0, 0.05);
    }

    .top-bar-user-avatar {
        width: 36px;
        height: 36px;
        background: #1e3a5f;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #ffffff;
        font-weight: 600;
        font-size: 1rem;
        border: 2px solid rgba(0, 0, 0, 0.1);
        position: relative;
    }

    .top-bar-user-avatar::after {
        content: '';
        position: absolute;
        bottom: 2px;
        right: 2px;
        width: 10px;
        height: 10px;
        background: #10b981;
        border: 2px solid #0f172a;
        border-radius: 50%;
    }

    /* --- Notification bell & dropdown (shared design) --- */
    .top-bar-notifications {
        position: relative;
        margin-right: 8px;
        display: flex;
        align-items: center;
    }

    .notification-bell {
        position: relative;
        cursor: pointer;
        color: var(--text-dark, #1e293b);
        font-size: 1.25rem;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: transform 0.25s ease, box-shadow 0.25s ease, opacity 0.25s ease, visibility 0.25s ease, max-height 0.25s ease;
    }

    .notification-bell:hover {
        background: rgba(148, 163, 184, 0.28);
        transform: translateY(-1px);
    }

    .notification-bell .badge {
        position: absolute;
        top: 3px;
        right: 3px;
        background: linear-gradient(135deg, #ef4444, #dc2626);
        color: #fff;
        font-size: 0.6rem;
        font-weight: 700;
        padding: 2px 5px;
        border-radius: 10px;
        min-width: 17px;
        text-align: center;
        border: 2px solid #fff;
        box-shadow: 0 2px 6px rgba(239, 68, 68, 0.45);
    }

    .notification-dropdown {
        position: absolute;
        top: calc(100% + 10px);
        right: -6px;
        width: 360px;
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 16px 48px rgba(15, 23, 42, 0.18), 0 4px 12px rgba(15, 23, 42, 0.08);
        border: 1px solid rgba(226, 232, 240, 0.9);
        z-index: 1060;
        overflow: hidden;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-8px) scale(0.98);
        transform-origin: top right;
        transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s;
    }

    .notification-dropdown.show {
        opacity: 1;
        visibility: visible;
        transform: translateY(0) scale(1);
    }

    .notification-dropdown-header {
        padding: 14px 18px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid #f1f5f9;
        background: #ffffff;
    }

    .nd-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .nd-count {
        background: #ef4444;
        color: #fff;
        font-size: 0.65rem;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 999px;
    }

    .nd-link {
        font-size: 0.75rem;
        font-weight: 600;
        color: #3b82f6;
        text-decoration: none;
    }

    .nd-link:hover { text-decoration: underline; }

    .notification-list {
        max-height: 360px;
        overflow-y: auto;
    }

    .notification-list::-webkit-scrollbar { width: 5px; }
    .notification-list::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 3px; }

    .notification-item {
        padding: 12px 16px;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        text-decoration: none;
        color: inherit;
        border-bottom: 1px solid #f8fafc;
        
    }

    .notification-item:last-child { border-bottom: none; }
    .notification-item:hover { background: #f8fafc; }

    .notification-icon {
        width: 38px;
        height: 38px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
    }

    .notification-item.booking .notification-icon { background: rgba(59, 130, 246, 0.12); color: #3b82f6; }
    .notification-item.emergency .notification-icon { background: rgba(239, 68, 68, 0.12); color: #ef4444; }
    .notification-item.warranty .notification-icon { background: rgba(139, 92, 246, 0.12); color: #8b5cf6; }
    .notification-item.health .notification-icon { background: rgba(234, 179, 8, 0.15); color: #ca8a04; }

    .notification-item-text {
        flex: 1;
        min-width: 0;
    }

    .notification-item-title {
        font-size: 0.82rem;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 2px;
    }

    .notification-item-message {
        font-size: 0.75rem;
        color: #64748b;
        word-break: break-word;
        line-height: 1.4;
    }

    .notification-item-time {
        font-size: 0.68rem;
        color: #94a3b8;
        margin-top: 4px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .notification-item-time i { font-size: 0.65rem; }

    .notification-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #3b82f6;
        margin-top: 8px;
        flex-shrink: 0;
    }

    .notification-empty {
        padding: 32px 20px;
        text-align: center;
        color: #94a3b8;
    }

    .notification-empty > i {
        font-size: 1.8rem;
        display: block;
        margin-bottom: 8px;
        color: #cbd5e1;
    }

    .notification-empty .ne-title { font-size: 0.85rem; font-weight: 600; color: #475569; }
    .notification-empty .ne-sub { font-size: 0.72rem; margin-top: 2px; }

    .top-bar-user-info {
        display: flex;
        flex-direction: column;
        text-align: left;
        flex: 1;
    }

    .top-bar-user-name {
        color: var(--text-dark);
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 2px;
    }

    .top-bar-user-role {
        color: var(--text-light);
        font-size: 0.85rem;
    }

    .top-bar-dropdown-btn {
        color: var(--text-light);
        font-size: 0.7rem;
        transition: transform 0.3s ease;
        margin-left: 8px;
    }

    .top-bar-user.active .top-bar-dropdown-btn {
        transform: rotate(180deg);
    }

    .top-bar-user-dropdown {
        position: absolute;
        top: calc(100% + 10px);
        right: 0;
        background: white;
        border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        min-width: 170px;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-10px);
        transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        z-index: 1050;
        overflow: hidden;
    }

    .top-bar-user.active .top-bar-user-dropdown {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }

    .dropdown-header {
        padding: 10px 14px;
        background: #ffffff;
        border-bottom: 1px solid #f1f5f9;
    }

    .dropdown-header-name {
        color: #1e293b;
        font-size: 0.82rem;
        font-weight: 600;
        margin-bottom: 1px;
    }

    .dropdown-header-role {
        color: #64748b;
        font-size: 0.68rem;
    }

    .dropdown-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 14px;
        color: var(--text-dark);
        text-decoration: none;
        transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        cursor: pointer;
    }

    .dropdown-item:hover {
        background: rgba(250, 204, 21, 0.1);
        color: #FACC15;
    }

    .dropdown-item i {
        font-size: 1rem;
        width: 18px;
        text-align: center;
    }

    .dropdown-item.danger {
        color: #ef4444;
        border-top: 1px solid #f1f5f9;
    }

    .dropdown-item.danger:hover {
        background: rgba(239, 68, 68, 0.1);
        color: #dc2626;
    }

    .sidebar-toggle {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: white;
        padding: 8px 12px;
        border-radius: 8px;
        cursor: pointer;
        transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
    }

    .sidebar-toggle:hover {
        background: rgba(255, 255, 255, 0.2);
    }

    .content-area {
        padding: 70px 30px 30px 30px;
        overflow-y: auto;
        overflow-x: hidden;
        flex: 1;
        width: 100%;
    }

    /* --- Mobile Responsive --- */
    .sidebar-toggle {
        display: none;
        background: none;
        border: none;
        color: #1e293b;
        font-size: 1.5rem;
        cursor: pointer;
    }

    @media (max-width: 992px) {
        .sidebar {
            transform: translateX(-100%);
            width: 280px;
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .main-content {
            margin-left: 0;
            width: 100%;
        }

        .main-content.expanded {
            margin-left: 0;
            width: 100%;
        }

        .main-content.expanded .top-bar {
            left: 0;
        }

        .sidebar-toggle {
            display: block;
        }

        .top-bar {
            padding: 12px 20px;
            left: 0;
        }

        .top-bar-title {
            font-size: 1.1rem;
        }

        .top-bar-user {
            gap: 8px;
        }

        .top-bar-user-avatar {
            width: 36px;
            height: 36px;
            font-size: 0.95rem;
        }

        .top-bar-user-name {
            font-size: 0.95rem;
        }

        .top-bar-user-role {
            font-size: 0.8rem;
        }

        .content-area {
            padding: 70px 20px 20px 20px;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1035;
        }

        .sidebar-overlay.active {
            display: block;
        }
    }

    @media (max-width: 768px) {
        .top-bar {
            padding: 10px 15px;
        }

        .top-bar-title {
            font-size: 1rem;
        }

        .top-bar-user-info {
            display: none;
        }

        .content-area {
            padding: 70px 15px 15px 15px;
        }

        .sidebar {
            width: 260px;
        }

        .sidebar-footer {
            padding: 15px;
        }

        .sidebar-footer-text {
            font-size: 0.8rem;
        }

        .sidebar-footer-copyright {
            font-size: 0.65rem;
        }
    }

    @media (max-width: 576px) {
        .top-bar {
            padding: 8px 12px;
        }

        .notification-dropdown {
            width: calc(100vw - 24px);
            right: -12px;
        }

        .top-bar-title {
            font-size: 0.95rem;
        }

        .sidebar-toggle {
            padding: 6px 10px;
            font-size: 1.3rem;
        }

        .content-area {
            padding: 70px 12px 12px 12px;
        }

        .sidebar {
            width: 100%;
            max-width: 280px;
        }

        .sidebar-header {
            padding: 15px;
        }

        .sidebar-brand {
            font-size: 1.2rem;
        }

        .sidebar-nav-item {
            padding: 10px 15px;
        }

        .sidebar-section-title {
            padding: 0 15px;
        }
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    /* Smaller customer header user text and dropdown items */
    .top-bar-user-name { font-size: 0.8rem; }
    .top-bar-user-role { font-size: 0.7rem; }
    .dropdown-header-name { font-size: 0.82rem; }
    .dropdown-header-role { font-size: 0.68rem; }
    .top-bar-user-dropdown .dropdown-item { font-size: 0.8rem; }

    /* ===== DARK MODE ===== */
    .dark-mode-toggle {
        background: transparent;
        border: none;
        color: var(--text-dark, #1e293b);
        font-size: 1.15rem;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: transform 0.25s ease, box-shadow 0.25s ease, opacity 0.25s ease, visibility 0.25s ease, max-height 0.25s ease;
        margin-right: 4px;
    }
    .dark-mode-toggle:hover {
        background: rgba(148, 163, 184, 0.28);
        transform: translateY(-1px);
    }
    html[data-theme="dark"] .dark-mode-toggle { color: #FACC15; }
    html[data-theme="dark"] .dark-mode-toggle:hover { background: rgba(250, 204, 21, 0.12); }

    html[data-theme="dark"] {
        color-scheme: dark;
        --bg-light: #101f3c;
        --bg-dark: #101f3c;
        --bg-card: #1a2b4f;
        --card-bg: #1a2b4f;
        --bg-darker: #16233f;
        --text-dark: #e2e8f0;
        --text-light: #94a3b8;
        --text-muted: #94a3b8;
        --text-main: #e2e8f0;
        --text-sub: #94a3b8;
        --card-border: rgba(255, 255, 255, 0.09);
        --border-color: rgba(255, 255, 255, 0.09);
        --border-highlight: rgba(250, 204, 21, 0.35);
        --bg-canvas: #101f3c;
        --bg-card-hover: #22335a;
    }

    html[data-theme="dark"] body { background: #101f3c !important; color: #e2e8f0 !important; }
    html[data-theme="dark"] .main-content { background: #101f3c !important; }
    html[data-theme="dark"] body .main-content { color: #e2e8f0 !important; }
    html[data-theme="dark"] .content-area { background: #101f3c !important; }

    /* Sidebar - blends seamlessly into the page background */
    html[data-theme="dark"] .sidebar {
        background: #101f3c !important;
        box-shadow: none !important;
        border-right: 1px solid rgba(255, 255, 255, 0.07) !important;
    }
    html[data-theme="dark"] .sidebar-footer { background: transparent !important; border-top-color: rgba(255, 255, 255, 0.07) !important; }

    /* Top bar - same navy as sidebar for a unified frame */
    html[data-theme="dark"] .top-bar { background: #101f3c !important; box-shadow: none !important; }
    html[data-theme="dark"] .top-bar-title { color: #e2e8f0 !important; }
    html[data-theme="dark"] .top-bar-user:hover { background: rgba(255, 255, 255, 0.06) !important; }
    html[data-theme="dark"] .top-bar-user-name { color: #e2e8f0 !important; }
    html[data-theme="dark"] .top-bar-user-role { color: #94a3b8 !important; }
    html[data-theme="dark"] .top-bar-dropdown-btn { color: #94a3b8 !important; }
    html[data-theme="dark"] .notification-bell { color: #e2e8f0 !important; }
    html[data-theme="dark"] .notification-bell:hover { background: rgba(255, 255, 255, 0.08) !important; }
    html[data-theme="dark"] .notification-bell .badge { border-color: #0f172a !important; }

    /* Notification dropdown */
    html[data-theme="dark"] .notification-dropdown {
        background: #22335a !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
        box-shadow: 0 16px 48px rgba(0, 0, 0, 0.6) !important;
    }
    html[data-theme="dark"] .notification-dropdown-header { background: #22335a !important; border-bottom-color: rgba(255, 255, 255, 0.09) !important; }
    html[data-theme="dark"] .nd-title { color: #e2e8f0 !important; }
    html[data-theme="dark"] .nd-link { color: #60a5fa !important; }
    html[data-theme="dark"] .notification-item { border-bottom-color: rgba(255, 255, 255, 0.06) !important; }
    html[data-theme="dark"] .notification-item:hover { background: rgba(255, 255, 255, 0.05) !important; }
    html[data-theme="dark"] .notification-item-title { color: #e2e8f0 !important; }
    html[data-theme="dark"] .notification-item-message { color: #94a3b8 !important; }
    html[data-theme="dark"] .notification-item-time { color: #64748b !important; }
    html[data-theme="dark"] .notification-empty { color: #64748b !important; }
    html[data-theme="dark"] .notification-empty > i { color: #3b4d7d !important; }
    html[data-theme="dark"] .notification-empty .ne-title { color: #cbd5e1 !important; }
    html[data-theme="dark"] .notification-list::-webkit-scrollbar-thumb { background: #3b4d7d !important; }

    /* User dropdown */
    html[data-theme="dark"] .top-bar-user-dropdown { background: #22335a !important; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.6) !important; }
    html[data-theme="dark"] .top-bar-user-dropdown .dropdown-header { background: #22335a !important; border-bottom-color: rgba(255, 255, 255, 0.09) !important; }
    html[data-theme="dark"] .dropdown-header-name { color: #e2e8f0 !important; }
    html[data-theme="dark"] .dropdown-header-role { color: #94a3b8 !important; }
    html[data-theme="dark"] .top-bar-user-dropdown .dropdown-item { color: #e2e8f0 !important; }
    html[data-theme="dark"] .top-bar-user-dropdown .dropdown-item:hover { background: rgba(250, 204, 21, 0.12) !important; color: #FDE047 !important; }
    html[data-theme="dark"] .dropdown-item.danger { color: #f87171 !important; border-top-color: rgba(255, 255, 255, 0.09) !important; }
    html[data-theme="dark"] .dropdown-item.danger:hover { background: rgba(239, 68, 68, 0.15) !important; color: #ef4444 !important; }

    /* Cards & surfaces */
    html[data-theme="dark"] .main-content .card,
    html[data-theme="dark"] .modern-card,
    html[data-theme="dark"] .stat-card,
    html[data-theme="dark"] .card-glass {
        background: #1a2b4f !important;
        border-color: rgba(255, 255, 255, 0.09) !important;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.35) !important;
        color: #e2e8f0 !important;
    }
    html[data-theme="dark"] .main-content .card:hover,
    html[data-theme="dark"] .modern-card:hover,
    html[data-theme="dark"] .stat-card:hover {
        background: #22335a !important;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.45) !important;
    }
    html[data-theme="dark"] .card-header, html[data-theme="dark"] .card-footer {
        background: rgba(255, 255, 255, 0.03) !important;
        border-color: rgba(255, 255, 255, 0.09) !important;
        color: #e2e8f0 !important;
    }
    html[data-theme="dark"] .card-header-gradient, html[data-theme="dark"] .card-header-accent { color: #fff !important; }
    html[data-theme="dark"] .list-group-item {
        background: rgba(255, 255, 255, 0.04) !important;
        border-color: rgba(255, 255, 255, 0.08) !important;
        color: #e2e8f0 !important;
    }
    html[data-theme="dark"] .list-group-item:hover { background: rgba(255, 255, 255, 0.07) !important; }

    /* Text colors */
    html[data-theme="dark"] .main-content h1,
    html[data-theme="dark"] .main-content h2,
    html[data-theme="dark"] .main-content h3,
    html[data-theme="dark"] .main-content h4,
    html[data-theme="dark"] .main-content h5,
    html[data-theme="dark"] .main-content h6,
    html[data-theme="dark"] .stat-value { color: #e2e8f0 !important; }
    html[data-theme="dark"] .text-muted,
    html[data-theme="dark"] .text-secondary,
    html[data-theme="dark"] .stat-label { color: #94a3b8 !important; }
    html[data-theme="dark"] .text-dark { color: #e2e8f0 !important; }
    html[data-theme="dark"] .bg-white, html[data-theme="dark"] .bg-light { background-color: #1a2b4f !important; }
    html[data-theme="dark"] .border, html[data-theme="dark"] .border-top, html[data-theme="dark"] .border-bottom,
    html[data-theme="dark"] .border-start, html[data-theme="dark"] .border-end, html[data-theme="dark"] hr { border-color: rgba(255, 255, 255, 0.09) !important; }
    html[data-theme="dark"] hr { opacity: 0.4; }

    /* Tables */
    html[data-theme="dark"] .table {
        --bs-table-color: #e2e8f0;
        --bs-table-bg: transparent;
        --bs-table-border-color: rgba(255, 255, 255, 0.09);
        --bs-table-striped-bg: rgba(255, 255, 255, 0.03);
        --bs-table-striped-color: #e2e8f0;
        --bs-table-hover-bg: rgba(255, 255, 255, 0.05);
        --bs-table-hover-color: #e2e8f0;
        --bs-table-active-bg: rgba(255, 255, 255, 0.07);
        --bs-table-active-color: #e2e8f0;
        color: #e2e8f0;
    }
    html[data-theme="dark"] .table th, html[data-theme="dark"] .table td { color: #e2e8f0; border-color: rgba(255, 255, 255, 0.09); }

    /* Forms */
    html[data-theme="dark"] .form-control,
    html[data-theme="dark"] .form-select,
    html[data-theme="dark"] .form-control-modern,
    html[data-theme="dark"] input[type="text"],
    html[data-theme="dark"] input[type="email"],
    html[data-theme="dark"] input[type="password"],
    html[data-theme="dark"] input[type="number"],
    html[data-theme="dark"] input[type="date"],
    html[data-theme="dark"] input[type="time"],
    html[data-theme="dark"] input[type="tel"],
    html[data-theme="dark"] textarea,
    html[data-theme="dark"] select {
        background-color: #16233f !important;
        color: #e2e8f0 !important;
        border-color: #3b4d7d !important;
    }
    html[data-theme="dark"] .form-control::placeholder, html[data-theme="dark"] textarea::placeholder,
    html[data-theme="dark"] .form-control-modern::placeholder { color: #64748b; }
    html[data-theme="dark"] .form-control:focus, html[data-theme="dark"] .form-select:focus, html[data-theme="dark"] .form-control-modern:focus {
        background-color: #132140 !important;
        color: #e2e8f0 !important;
        border-color: #FACC15 !important;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.15) !important;
    }
    html[data-theme="dark"] .form-control:disabled, html[data-theme="dark"] .form-control[readonly] { background-color: #22335a !important; color: #94a3b8 !important; }
    html[data-theme="dark"] .form-check-input { background-color: #16233f; border-color: #3b4d7d; }
    html[data-theme="dark"] .form-check-input:checked { background-color: #FACC15; border-color: #FACC15; }
    html[data-theme="dark"] .form-label, html[data-theme="dark"] .form-check-label, html[data-theme="dark"] .form-text,
    html[data-theme="dark"] .form-label-modern { color: #e2e8f0 !important; }
    html[data-theme="dark"] .input-group-text { background-color: #22335a !important; color: #94a3b8 !important; border-color: #3b4d7d !important; }

    /* Bootstrap dropdowns, modals, pagination, tabs */
    html[data-theme="dark"] .dropdown-menu { background-color: #22335a; border-color: rgba(255, 255, 255, 0.1); }
    html[data-theme="dark"] .dropdown-menu .dropdown-item { color: #e2e8f0; }
    html[data-theme="dark"] .dropdown-menu .dropdown-item:hover,
    html[data-theme="dark"] .dropdown-menu .dropdown-item:focus { background: rgba(250, 204, 21, 0.12); color: #FDE047; }
    html[data-theme="dark"] .dropdown-menu .dropdown-divider { border-color: rgba(255, 255, 255, 0.09); }
    html[data-theme="dark"] .modal-content { background-color: #1a2b4f; color: #e2e8f0; border-color: rgba(255, 255, 255, 0.1); }
    html[data-theme="dark"] .modal-header { background: #1a2b4f !important; color: #e2e8f0 !important; }
    html[data-theme="dark"] .modal-header .modal-title, html[data-theme="dark"] .modal-title { color: #e2e8f0 !important; }
    html[data-theme="dark"] .modal-header, html[data-theme="dark"] .modal-footer { border-color: rgba(255, 255, 255, 0.09); }
    html[data-theme="dark"] .btn-close { filter: invert(1); }
    html[data-theme="dark"] .page-link { background-color: #22335a; border-color: rgba(255, 255, 255, 0.1); color: #e2e8f0; }
    html[data-theme="dark"] .page-link:hover { background-color: #2a3d6b; color: #FDE047; }
    html[data-theme="dark"] .page-item.active .page-link { background-color: #FACC15; border-color: #FACC15; color: #111827; }
    html[data-theme="dark"] .page-item.disabled .page-link { background-color: #1a2b4f; color: #475569; border-color: rgba(255, 255, 255, 0.09); }
    html[data-theme="dark"] .nav-tabs { border-color: rgba(255, 255, 255, 0.09); }
    html[data-theme="dark"] .nav-tabs .nav-link { color: #94a3b8; }
    html[data-theme="dark"] .nav-tabs .nav-link:hover { border-color: rgba(255, 255, 255, 0.15); color: #e2e8f0; }
    html[data-theme="dark"] .nav-tabs .nav-link.active { background-color: #1a2b4f; color: #FACC15; border-color: rgba(255, 255, 255, 0.09) rgba(255, 255, 255, 0.09) #1a2b4f; }
    html[data-theme="dark"] .accordion-item { background-color: #1a2b4f; color: #e2e8f0; border-color: rgba(255, 255, 255, 0.09); }
    html[data-theme="dark"] .accordion-button { background-color: #22335a; color: #e2e8f0; }
    html[data-theme="dark"] .accordion-button:not(.collapsed) { background-color: #2a3d6b; color: #FDE047; }
    html[data-theme="dark"] .accordion-button::after { filter: invert(1); }
    html[data-theme="dark"] .badge.bg-light, html[data-theme="dark"] .badge.text-bg-light { background-color: #2a3d6b !important; color: #cbd5e1 !important; }
    html[data-theme="dark"] .badge.bg-secondary, html[data-theme="dark"] .badge.text-bg-secondary { background-color: #3b4d7d !important; }

    /* Alerts */
    html[data-theme="dark"] .alert-success { background: rgba(16, 185, 129, 0.12); color: #6ee7b7; border-color: rgba(16, 185, 129, 0.3); }
    html[data-theme="dark"] .alert-danger { background: rgba(239, 68, 68, 0.12); color: #fca5a5; border-color: rgba(239, 68, 68, 0.3); }
    html[data-theme="dark"] .alert-warning { background: rgba(245, 158, 11, 0.12); color: #fcd34d; border-color: rgba(245, 158, 11, 0.3); }
    html[data-theme="dark"] .alert-info { background: rgba(59, 130, 246, 0.12); color: #93c5fd; border-color: rgba(59, 130, 246, 0.3); }
    html[data-theme="dark"] .alert-secondary { background: rgba(148, 163, 184, 0.12); color: #cbd5e1; border-color: rgba(148, 163, 184, 0.3); }

    /* Customer template components */
    html[data-theme="dark"] .navbar-custom { background: rgba(21, 31, 54, 0.95) !important; box-shadow: 0 4px 30px rgba(0, 0, 0, 0.5) !important; }
    html[data-theme="dark"] .nav-link { color: #cbd5e1 !important; }
    html[data-theme="dark"] .btn-outline-custom { border-color: #475569; color: #cbd5e1; }
    html[data-theme="dark"] .btn-logout { border-color: #475569; color: #cbd5e1; }
    html[data-theme="dark"] .btn-logout:hover { background: #dc3545; border-color: #dc3545; color: #fff; }

    /* SweetAlert2 popup */
    html[data-theme="dark"] .swal2-popup { background: #22335a; color: #e2e8f0; }
    html[data-theme="dark"] .swal2-title, html[data-theme="dark"] .swal2-html-container { color: #e2e8f0; }
    html[data-theme="dark"] .swal2-input, html[data-theme="dark"] .swal2-select, html[data-theme="dark"] .swal2-textarea {
        background-color: #16233f; color: #e2e8f0; border-color: #3b4d7d;
    }

    /* ===== Module-wide surfaces (customer pages) ===== */
    /* Card-level containers */
    html[data-theme="dark"] .card-summary, html[data-theme="dark"] .payment-option-card,
    html[data-theme="dark"] .booking-stepper, html[data-theme="dark"] .warranty-card,
    html[data-theme="dark"] .claim-card, html[data-theme="dark"] .emergency-card,
    html[data-theme="dark"] .emergency-table, html[data-theme="dark"] .empty-state,
    html[data-theme="dark"] .card-custom, html[data-theme="dark"] .price-summary,
    html[data-theme="dark"] .service-tabs, html[data-theme="dark"] .booking-list-item,
    html[data-theme="dark"] .booking-box, html[data-theme="dark"] .vhealth-card,
    html[data-theme="dark"] .action-card, html[data-theme="dark"] .service-record-card,
    html[data-theme="dark"] .moto-list-item, html[data-theme="dark"] .timeline-content,
    html[data-theme="dark"] .card-header-custom, html[data-theme="dark"] .health-card,
    html[data-theme="dark"] .time-slot-card, html[data-theme="dark"] .transaction-ref-box,
    html[data-theme="dark"] .schedule-box, html[data-theme="dark"] .view-toggle .btn,
    html[data-theme="dark"] .service-card {
        background: #1a2b4f !important;
        border-color: rgba(255, 255, 255, 0.12) !important;
        color: #e2e8f0 !important;
    }
    /* Inner chips/panels (sit inside cards) */
    html[data-theme="dark"] .dc-icon, html[data-theme="dark"] .dc-arrow,
    html[data-theme="dark"] .step-circle, html[data-theme="dark"] .factor-item,
    html[data-theme="dark"] .breakdown-box, html[data-theme="dark"] .status-block,
    html[data-theme="dark"] .sr-plate, html[data-theme="dark"] .timeline-dot,
    html[data-theme="dark"] .vhealth-bar, html[data-theme="dark"] .booking-list-footer,
    html[data-theme="dark"] .booking-box-footer, html[data-theme="dark"] .sr-media,
    html[data-theme="dark"] .slot-panel-header, html[data-theme="dark"] #timeSlots,
    html[data-theme="dark"] .progress-bar-custom, html[data-theme="dark"] .sr-btn,
    html[data-theme="dark"] .vehicle-header {
        background: #22335a !important;
        border-color: rgba(255, 255, 255, 0.12) !important;
        color: #e2e8f0 !important;
    }
    html[data-theme="dark"] .moto-list-item:hover { background: #22335a !important; }
    html[data-theme="dark"] .moto-list-item.active { background: #3b82f6 !important; color: #fff !important; }

    /* Hardcoded dark text -> light */
    html[data-theme="dark"] .dc-title, html[data-theme="dark"] .dc-value,
    html[data-theme="dark"] .vhealth-head h3, html[data-theme="dark"] .vh-name,
    html[data-theme="dark"] .vhealth-cond-head, html[data-theme="dark"] .vhealth-bike,
    html[data-theme="dark"] .vehicle-title, html[data-theme="dark"] .service-card .type,
    html[data-theme="dark"] .service-card .mileage, html[data-theme="dark"] .moto-list-title,
    html[data-theme="dark"] .timeline-badge.current {
        color: #e2e8f0 !important;
    }
    html[data-theme="dark"] .booking-number { background: #FACC15 !important; color: #111827 !important; }
    html[data-theme="dark"] .timeline-badge.current { background: rgba(255, 255, 255, 0.12) !important; }
    html[data-theme="dark"] .dc-meta, html[data-theme="dark"] .vhealth-plate,
    html[data-theme="dark"] .vehicle-meta, html[data-theme="dark"] .service-card .plate,
    html[data-theme="dark"] .moto-list-subtitle, html[data-theme="dark"] .mechanic-names,
    html[data-theme="dark"] .step-label { color: #94a3b8 !important; }
    html[data-theme="dark"] .mechanic-names strong { color: #e2e8f0 !important; }
    html[data-theme="dark"] .dc-navy .dc-icon, html[data-theme="dark"] .dc-navy .dc-arrow,
    html[data-theme="dark"] .vhealth-link, html[data-theme="dark"] .slot-panel-header { color: #93c5fd !important; }
    html[data-theme="dark"] .group-header { background-color: #22335a !important; color: #e2e8f0 !important; }
    html[data-theme="dark"] .summary-title { color: #e2e8f0 !important; }
    html[data-theme="dark"] .skills-link { color: #93c5fd !important; }
    html[data-theme="dark"] .mechanic-selection input[type="checkbox"] { accent-color: #FACC15; }
    html[data-theme="dark"] .vhealth-link { background: rgba(59, 130, 246, 0.15) !important; }
    html[data-theme="dark"] .vhealth-link:hover { background: rgba(59, 130, 246, 0.25) !important; color: #93c5fd !important; }
    html[data-theme="dark"] .vhealth-bar { background: #3b4d7d !important; }
    html[data-theme="dark"] .vh-ico.good { background: rgba(16, 185, 129, 0.15) !important; }
    html[data-theme="dark"] .vh-ico.fair { background: rgba(245, 158, 11, 0.15) !important; }
    html[data-theme="dark"] .vh-ico.bad { background: rgba(239, 68, 68, 0.15) !important; }
    html[data-theme="dark"] .timeline-track::before { background: #3b4d7d !important; }
    html[data-theme="dark"] .service-card .badge-completed { background: rgba(34, 197, 94, 0.15) !important; color: #4ade80 !important; }
    html[data-theme="dark"] .service-card .badge-pending { background: rgba(148, 163, 184, 0.15) !important; color: #cbd5e1 !important; }

    /* Steppers (confirm_payment, view_availability) */
    html[data-theme="dark"] .stepper-line { background: #3b4d7d !important; }
    html[data-theme="dark"] .stepper-line-progress { background: #FACC15 !important; }
    html[data-theme="dark"] .step-circle { color: #94a3b8 !important; }
    html[data-theme="dark"] .step.active .step-circle { background: #FACC15 !important; border-color: #FACC15 !important; color: #111827 !important; }
    html[data-theme="dark"] .step.completed .step-circle { background: #22335a !important; border-color: #3b4d7d !important; }
    html[data-theme="dark"] .step.active .step-label, html[data-theme="dark"] .step.completed .step-label { color: #FDE047 !important; }

    /* my_bookings specifics */
    html[data-theme="dark"] .booking-list-header { border-bottom-color: rgba(255, 255, 255, 0.09) !important; }
    html[data-theme="dark"] .service-package-item { color: #e2e8f0 !important; }
    html[data-theme="dark"] .nav-tabs .nav-link { background-color: transparent !important; }
    html[data-theme="dark"] .payment-pending-box { background: rgba(59, 130, 246, 0.12) !important; color: #93c5fd !important; }
    html[data-theme="dark"] .view-toggle .btn.active { background: var(--primary-color, #FACC15) !important; color: #fff !important; border-color: var(--primary-color, #FACC15) !important; }
    html[data-theme="dark"] .view-toggle .btn:hover:not(.active) { background: #2a3d6b !important; }

    /* Priority / status pill pastels -> translucent dark */
    html[data-theme="dark"] .priority-urgent { background: rgba(239, 68, 68, 0.15) !important; color: #f87171 !important; }
    html[data-theme="dark"] .priority-high { background: rgba(250, 204, 21, 0.15) !important; color: #FDE047 !important; }
    html[data-theme="dark"] .priority-medium { background: rgba(59, 130, 246, 0.15) !important; color: #93c5fd !important; }
    html[data-theme="dark"] .priority-low { background: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; }
    html[data-theme="dark"] .sr-status-pending { color: #FDE047 !important; }
    html[data-theme="dark"] .timeline-badge.scheduled { background: rgba(250, 204, 21, 0.15) !important; color: #FDE047 !important; }
    html[data-theme="dark"] .timeline-badge.completed, html[data-theme="dark"] .timeline-badge.past { color: #4ade80 !important; }

    /* FullCalendar (available.php) */
    html[data-theme="dark"] .fc { --fc-page-bg-color: #101f3c; --fc-border-color: rgba(255, 255, 255, 0.12); --fc-neutral-bg-color: #22335a; --fc-today-bg-color: rgba(250, 204, 21, 0.08); color: #e2e8f0; }
    html[data-theme="dark"] .fc .fc-daygrid-day-number, html[data-theme="dark"] .fc .fc-col-header-cell-cushion { color: #e2e8f0 !important; }
    html[data-theme="dark"] .fc-toolbar-title { color: #e2e8f0 !important; }
    html[data-theme="dark"] .fc-prev-button, html[data-theme="dark"] .fc-next-button,
    html[data-theme="dark"] .fc .fc-button-group .fc-button,
    html[data-theme="dark"] .fc .fc-button-group .fc-button-primary {
        background-color: #22335a !important;
        border-color: rgba(255, 255, 255, 0.15) !important;
        color: #e2e8f0 !important;
    }
    html[data-theme="dark"] .fc-prev-button:hover, html[data-theme="dark"] .fc-next-button:hover,
    html[data-theme="dark"] .fc .fc-button-group .fc-button:hover,
    html[data-theme="dark"] .fc .fc-button-group .fc-button-primary:hover {
        background-color: #FACC15 !important; border-color: #FACC15 !important; color: #111827 !important;
    }
    html[data-theme="dark"] .fc .fc-button-group .fc-button-active,
    html[data-theme="dark"] .fc .fc-button-group .fc-button-primary:not(:disabled).fc-button-active {
        background-color: #FACC15 !important; border-color: #FACC15 !important; color: #111827 !important;
    }
    html[data-theme="dark"] .service-time-display { color: #cbd5e1 !important; }
    html[data-theme="dark"] .time-slot-card:hover { box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4) !important; }
    html[data-theme="dark"] #timeSlots::-webkit-scrollbar-thumb { background: #3b4d7d; }
    html[data-theme="dark"] .initial-message { border-color: #10b981 !important; }
    html[data-theme="dark"] .fc-day-unavailable, html[data-theme="dark"] .fc-day-past { background-color: rgba(255, 255, 255, 0.03) !important; }

    /* book_service / select_mechanic options */
    html[data-theme="dark"] .custom-control label { background-color: #1a2b4f !important; border-color: rgba(255, 255, 255, 0.12) !important; color: #e2e8f0 !important; }
    html[data-theme="dark"] .custom-control label:hover { background-color: rgba(250, 204, 21, 0.1) !important; border-color: #FACC15 !important; }
    html[data-theme="dark"] .custom-control input:checked + label,
    html[data-theme="dark"] .package-checkbox:checked + label { background-color: rgba(250, 204, 21, 0.15) !important; border-color: #FACC15 !important; }
    html[data-theme="dark"] .service-info .text-primary, html[data-theme="dark"] .text-primary { color: #60a5fa !important; }
    html[data-theme="dark"] #servicesList::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.05); }
    html[data-theme="dark"] #servicesList::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2); }
    html[data-theme="dark"] .service-tab:hover { background: rgba(255, 255, 255, 0.05) !important; }
    html[data-theme="dark"] .service-tab.active { background: rgba(250, 204, 21, 0.15) !important; color: #FDE047 !important; border-bottom-color: #FACC15 !important; }
    html[data-theme="dark"] .package-section-divider::after { background: #1a2b4f !important; }
    html[data-theme="dark"] .slot-display { color: #e2e8f0 !important; }
    html[data-theme="dark"] .initial-message { background: rgba(16, 185, 129, 0.1) !important; color: #6ee7b7 !important; }
    html[data-theme="dark"] .btn-outline-accent { color: #cbd5e1 !important; border-color: #475569 !important; }
    html[data-theme="dark"] .btn-outline-accent:hover, html[data-theme="dark"] .btn-outline-accent:focus { color: #fff !important; }

    /* Remaining per-page leftovers */
    html[data-theme="dark"] .filter-control { background: #1a2b4f !important; border-color: rgba(255, 255, 255, 0.12) !important; }
    html[data-theme="dark"] .emergency-card .card-body > .row:first-of-type .col-md-3 > div,
    html[data-theme="dark"] .contact-info { background: #22335a !important; border-color: rgba(255, 255, 255, 0.12) !important; color: #e2e8f0 !important; }
    html[data-theme="dark"] .booking-box-header, html[data-theme="dark"] .info-row,
    html[data-theme="dark"] .booking-list-header { border-bottom-color: rgba(255, 255, 255, 0.09) !important; }
    html[data-theme="dark"] .nav-tabs .nav-link { border-color: rgba(255, 255, 255, 0.15) !important; }
    html[data-theme="dark"] .nav-tabs .nav-link.active { border-color: rgba(255, 255, 255, 0.09) rgba(255, 255, 255, 0.09) #1a2b4f !important; }
    html[data-theme="dark"] .coverage-badge { border-color: rgba(255, 255, 255, 0.15) !important; color: #cbd5e1 !important; }
    html[data-theme="dark"] .total-amount { background: rgba(250, 204, 21, 0.1) !important; }
    html[data-theme="dark"] .qr-code-card { background: #1a2b4f !important; border-color: rgba(255, 255, 255, 0.12) !important; }
    html[data-theme="dark"] .btn-back { background: #22335a !important; color: #e2e8f0 !important; border-color: rgba(255, 255, 255, 0.2) !important; }
    html[data-theme="dark"] .schedule-box { border-color: rgba(255, 255, 255, 0.12) !important; }
    html[data-theme="dark"] .empty-state { border-color: rgba(255, 255, 255, 0.15) !important; }
    html[data-theme="dark"] .empty-state i { color: #3b4d7d !important; }
    html[data-theme="dark"] .service-card.active::before { border-color: #e2e8f0 !important; }
    html[data-theme="dark"] .service-card.active::after { border-top-color: #94a3b8 !important; }
    html[data-theme="dark"] .emergency-table tr:hover { background: rgba(255, 255, 255, 0.04) !important; }
    html[data-theme="dark"] .sr-media-img { background: transparent !important; }
    html[data-theme="dark"] .sr-media-img img { mix-blend-mode: normal !important; }
    html[data-theme="dark"] .support-box { background-color: #1a2b4f !important; color: #e2e8f0 !important; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4) !important; }
    html[data-theme="dark"] .support-box .lead { color: #cbd5e1 !important; }
    html[data-theme="dark"] .support-box h4 { color: #FDE047 !important; }

    /* Booking-flow stepper -> brand yellow (both themes) */
    .page-stepper .step-item i,
    .page-stepper .step-item span,
    .page-stepper .step-item.active i,
    .page-stepper .step-item.active span {
        color: #FACC15 !important;
    }

    /* Selection accents -> brand yellow (both themes) */
    .custom-control label:hover,
    .mechanic-selection label:hover { border-color: #FACC15 !important; }
    .custom-control input:checked + label,
    .package-checkbox:checked + label,
    .mechanic-selection input[type="checkbox"]:checked + label {
        border-color: #FACC15 !important;
        background-color: rgba(250, 204, 21, 0.08) !important;
    }
    .service-tab.active {
        color: #EAB308 !important;
        border-bottom-color: #FACC15 !important;
        background: rgba(250, 204, 21, 0.06) !important;
    }

    /* Booking action button: navy "Check Availability" -> green "Select Mechanic" */
    #mainActionButton.btn-secondary {
        background: linear-gradient(135deg, #2a4d80 0%, #1e3a5f 100%) !important;
        border-color: #3b5d8f !important;
        color: #ffffff !important;
        box-shadow: 0 4px 14px rgba(42, 77, 128, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.18) !important;
    }
    #mainActionButton.btn-secondary:hover,
    #mainActionButton.btn-secondary:focus {
        background: linear-gradient(135deg, #35598f 0%, #25476f 100%) !important;
        border-color: #4a6da3 !important;
        color: #ffffff !important;
        box-shadow: 0 6px 18px rgba(42, 77, 128, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.22) !important;
    }

    /* Back button on booking-flow pages - same highlighted navy */
    .back-btn {
        background: linear-gradient(135deg, #2a4d80 0%, #1e3a5f 100%) !important;
        border-color: #3b5d8f !important;
        color: #ffffff !important;
        box-shadow: 0 3px 10px rgba(42, 77, 128, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.18) !important;
    }
    .back-btn:hover,
    .back-btn:focus {
        background: linear-gradient(135deg, #35598f 0%, #25476f 100%) !important;
        border-color: #4a6da3 !important;
        color: #ffffff !important;
        box-shadow: 0 5px 14px rgba(42, 77, 128, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.22) !important;
    }

    /* Back / Proceed buttons on view_availability - same highlighted navy */
    .btn-back, .btn-orange {
        background: linear-gradient(135deg, #2a4d80 0%, #1e3a5f 100%) !important;
        border-color: #3b5d8f !important;
        color: #ffffff !important;
        box-shadow: 0 3px 10px rgba(42, 77, 128, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.18) !important;
    }
    .btn-back:hover, .btn-back:focus,
    .btn-orange:hover, .btn-orange:focus {
        background: linear-gradient(135deg, #35598f 0%, #25476f 100%) !important;
        border-color: #4a6da3 !important;
        color: #ffffff !important;
        box-shadow: 0 5px 14px rgba(42, 77, 128, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.22) !important;
    }

    /* Accent buttons (Back / Confirm) - same highlighted navy */
    .btn-accent {
        background: linear-gradient(135deg, #2a4d80 0%, #1e3a5f 100%) !important;
        border-color: #3b5d8f !important;
        color: #ffffff !important;
        box-shadow: 0 3px 10px rgba(42, 77, 128, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.18) !important;
    }
    .btn-accent:hover,
    .btn-accent:focus {
        background: linear-gradient(135deg, #35598f 0%, #25476f 100%) !important;
        border-color: #4a6da3 !important;
        color: #ffffff !important;
        box-shadow: 0 5px 14px rgba(42, 77, 128, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.22) !important;
    }

    /* ===== Light mode sidebar - light surface ===== */
    html[data-theme="light"] .sidebar {
        background: #ffffff;
        box-shadow: 4px 0 20px rgba(0, 0, 0, 0.06);
        border-right: 1px solid #e2e8f0;
    }
    html[data-theme="light"] .sidebar-brand,
    html[data-theme="light"] .sidebar-brand span { color: #0f172a; }
    html[data-theme="light"] .sidebar-collapse-btn { background: rgba(0, 0, 0, 0.05); border-color: rgba(0, 0, 0, 0.12); color: #334155; }
    html[data-theme="light"] .sidebar-collapse-btn:hover { background: rgba(0, 0, 0, 0.09); }
    html[data-theme="light"] .sidebar-section-title { color: #94a3b8; }
    html[data-theme="light"] .sidebar-nav-item { color: #334155; }
    html[data-theme="light"] .sidebar-nav-item:hover { background: rgba(0, 0, 0, 0.05); color: #0f172a; }
    html[data-theme="light"] .sidebar-nav-item.active { background: #FACC15; color: #111827 !important; }
    html[data-theme="light"] .sidebar-footer { background: #f8fafc; border-top-color: #e2e8f0; }
    html[data-theme="light"] .sidebar-footer-text { color: #334155; }
    html[data-theme="light"] .sidebar-footer-copyright { color: #94a3b8; }
    html[data-theme="dark"] .user-avatar, html[data-theme="dark"] .top-bar-user-avatar { border: 2px solid rgba(255, 255, 255, 0.15); }

    /* Theme switch crossfade duration (one synchronous global update, view-transition) */
    ::view-transition-old(root), ::view-transition-new(root) { animation-duration: 180ms; }
</style>

<!-- Sidebar Overlay for Mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar Navigation -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <a href="dashboard_customer.php" class="sidebar-brand">
            <i data-lucide="motorbike" style="width: 34px; height: 34px;"></i>
            <span style="white-space: nowrap; font-size: 1rem; line-height: 1.2;">
                Mindanao Eversure
                <small style="display: block; font-size: .55rem; letter-spacing: .12em; white-space: nowrap; color: #FACC15; line-height: 1;">MOTORCYCLE SERVICE</small>
            </span>
        </a>
        <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Toggle Sidebar">
            <i class="bi bi-chevron-left"></i>
        </button>
    </div>
    
    <div class="sidebar-menu">
        <div class="sidebar-section">
            <div class="sidebar-section-title">Main Menu</div>
            <a href="dashboard_customer.php" class="sidebar-nav-item <?= $active_page === 'dashboard_customer.php' ? 'active' : '' ?>" data-tooltip="Dashboard">
                <i class="bi bi-grid-1x2"></i>
                <span>Dashboard</span>
            </a>
            <a href="book_service.php" class="sidebar-nav-item <?= $active_page === 'book_service.php' ? 'active' : '' ?>" data-tooltip="Book Service">
                <i class="bi bi-calendar-plus"></i>
                <span>Book Service</span>
            </a>
            <a href="my_bookings.php" class="sidebar-nav-item <?= $active_page === 'my_bookings.php' ? 'active' : '' ?>" data-tooltip="My Bookings">
                <i class="bi bi-journal-text"></i>
                <span>My Bookings</span>
                <?php if ($totalUpcoming > 0): ?>
                    <span class="badge"><?= $totalUpcoming ?></span>
                <?php endif; ?>
            </a>
            <a href="customer_emergency_service.php" class="sidebar-nav-item <?= $active_page === 'customer_emergency_service.php' ? 'active' : '' ?>" data-tooltip="Request Emergency">
                <i class="bi bi-exclamation-triangle"></i>
                <span>Request Emergency</span>
            </a>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-section-title">My Vehicles</div>
            <a href="customer_health_score.php" class="sidebar-nav-item <?= $active_page === 'customer_health_score.php' ? 'active' : '' ?>" data-tooltip="Health Score">
                <i class="bi bi-heart-pulse"></i>
                <span>Health Score</span>
                <?php if ($healthScoreNotificationCount > 0): ?>
                    <span class="badge"><?= $healthScoreNotificationCount ?></span>
                <?php endif; ?>
            </a>
            <a href="customer_maintenance_history.php" class="sidebar-nav-item <?= $active_page === 'customer_maintenance_history.php' ? 'active' : '' ?>" data-tooltip="Maintenance History">
                <i class="bi bi-tools"></i>
                <span>Maintenance History</span>
            </a>
            <a href="customer_warranty_info.php" class="sidebar-nav-item <?= $active_page === 'customer_warranty_info.php' ? 'active' : '' ?>" data-tooltip="Warranty Info">
                <i class="bi bi-shield-check"></i>
                <span>Warranty Info</span>
            </a>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-section-title">Services</div>
            <a href="support.php" class="sidebar-nav-item <?= $active_page === 'support.php' ? 'active' : '' ?>" data-tooltip="Help & Support">
                <i class="bi bi-headset"></i>
                <span>Help & Support</span>
            </a>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-section-title">Account</div>
            <a href="profile.php" class="sidebar-nav-item <?= ($active_page === 'profile.php' && (!isset($_GET['tab']) || $_GET['tab'] === 'profile')) ? 'active' : '' ?>" data-tooltip="Profile Settings">
                <i class="bi bi-person-gear"></i>
                <span>Profile Settings</span>
            </a>
        </div>
    </div>
    
    <div class="sidebar-footer">
        <div class="sidebar-footer-text">
            <i data-lucide="motorbike" style="width: 20px; height: 20px;"></i>
            <span>Mindanao Eversure</span>
        </div>
        <div class="sidebar-footer-copyright">
            © <?= date('Y') ?> All rights reserved
        </div>
    </div>
</aside>

<script>
    window.customerNotificationsData = <?= json_encode([
        'count' => $totalNotificationCount,
        'items' => $customerNotificationItems
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>

<!-- Sidebar JavaScript -->
<script>
    // Sidebar toggle functionality
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const sidebarCollapseBtn = document.getElementById('sidebarCollapseBtn');
        const mainContent = document.querySelector('.main-content');

        if (sidebarToggle && sidebar && sidebarOverlay) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
                sidebarOverlay.classList.toggle('active');
            });

            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
            });
        }

        // Customer notification bell
        const topBar = document.querySelector('.top-bar');
        const topBarUser = document.querySelector('.top-bar-user');
        const notifData = window.customerNotificationsData || { count: 0, items: [] };

        if (topBar && topBarUser) {
            const notifWrapper = document.createElement('div');
            notifWrapper.className = 'top-bar-notifications';

            const bell = document.createElement('div');
            bell.className = 'notification-bell';
            bell.title = 'Notifications';
            bell.innerHTML = '<i class="bi bi-bell-fill"></i>' +
                (notifData.count > 0 ? '<span class="badge">' + notifData.count + '</span>' : '');

            const dropdown = document.createElement('div');
            dropdown.className = 'notification-dropdown';

            const esc = function (s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            };

            const iconMap = {
                booking: 'bi-journal-text',
                emergency: 'bi-exclamation-triangle-fill',
                warranty: 'bi-shield-check',
                health: 'bi-heart-pulse'
            };

            let dropdownHTML = '<div class="notification-dropdown-header">' +
                '<div class="nd-title">Notifications' +
                (notifData.count > 0 ? '<span class="nd-count">' + notifData.count + ' new</span>' : '') +
                '</div>' +
                '<a href="my_bookings.php" class="nd-link">View All</a>' +
                '</div><div class="notification-list">';

            if (notifData.items && notifData.items.length > 0) {
                notifData.items.forEach(item => {
                    const iconClass = iconMap[item.type] || 'bi-bell';
                    dropdownHTML += '<a href="' + esc(item.link) + '" class="notification-item ' + esc(item.type) + '">' +
                        '<div class="notification-icon"><i class="bi ' + iconClass + '"></i></div>' +
                        '<div class="notification-item-text">' +
                            '<div class="notification-item-title">' + esc(item.title) + '</div>' +
                            '<div class="notification-item-message">' + esc(item.message) + '</div>' +
                            (item.time_label ? '<div class="notification-item-time"><i class="bi bi-clock"></i>' + esc(item.time_label) + '</div>' : '') +
                        '</div>' +
                        '<span class="notification-dot"></span>' +
                    '</a>';
                });
            } else {
                dropdownHTML += '<div class="notification-empty">' +
                    '<i class="bi bi-bell-slash"></i>' +
                    '<div class="ne-title">All caught up</div>' +
                    '<div class="ne-sub">No new notifications right now</div>' +
                    '</div>';
            }

            dropdownHTML += '</div>';

            dropdown.innerHTML = dropdownHTML;
            notifWrapper.appendChild(bell);
            notifWrapper.appendChild(dropdown);

            const userAvatar = topBarUser.querySelector('.top-bar-user-avatar');
            if (userAvatar) {
                topBarUser.insertBefore(notifWrapper, userAvatar);
            } else if (topBarUser.firstChild) {
                topBarUser.insertBefore(notifWrapper, topBarUser.firstChild);
            } else {
                topBarUser.appendChild(notifWrapper);
            }

            // Dark mode toggle (beside the notification bell)
            const themeToggle = document.createElement('button');
            themeToggle.type = 'button';
            themeToggle.className = 'dark-mode-toggle';
            themeToggle.title = 'Toggle dark mode';
            themeToggle.setAttribute('aria-label', 'Toggle dark mode');
            const renderToggleIcon = function () {
                themeToggle.innerHTML = '<i class="bi ' +
                    (document.documentElement.getAttribute('data-theme') === 'dark' ? 'bi-sun-fill' : 'bi-moon-fill') +
                    '"></i>';
            };
            renderToggleIcon();
            themeToggle.addEventListener('click', function (e) {
                e.stopPropagation();
                const root = document.documentElement;
                const isDark = root.getAttribute('data-theme') !== 'dark';
                const applyTheme = function () {
                    root.setAttribute('data-theme', isDark ? 'dark' : 'light');
                    localStorage.setItem('theme', isDark ? 'dark' : 'light');
                    renderToggleIcon();
                    window.dispatchEvent(new CustomEvent('customerThemeChanged', { detail: { dark: isDark } }));
                };
                const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                if (!reduceMotion && document.startViewTransition) {
                    document.startViewTransition(applyTheme);
                } else {
                    applyTheme();
                }
            });
            notifWrapper.parentNode.insertBefore(themeToggle, notifWrapper.nextSibling);

            bell.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdown.classList.toggle('show');
                const badge = bell.querySelector('.badge');
                if (badge) {
                    badge.style.display = 'none';
                }

                // Mark notifications as read on the server
                if (notifData.count > 0) {
                    fetch('mark_notifications_read.php', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    }).catch(function(err) {
                        console.error('mark notifications read failed', err);
                    });
                }

                document.querySelectorAll('.notification-dropdown.show').forEach(el => {
                    if (el !== dropdown) el.classList.remove('show');
                });
            });

            document.addEventListener('click', function(e) {
                if (!notifWrapper.contains(e.target)) {
                    dropdown.classList.remove('show');
                }
            });
        }

        // Sidebar collapse functionality
        if (sidebarCollapseBtn && sidebar && mainContent) {
            sidebarCollapseBtn.addEventListener('click', function() {
                if (window.innerWidth <= 992) {
                    sidebar.classList.remove('active');
                    if (sidebarOverlay) sidebarOverlay.classList.remove('active');
                    return;
                }
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');

                // Update collapse button icon
                const icon = sidebarCollapseBtn.querySelector('i');
                if (sidebar.classList.contains('collapsed')) {
                    icon.classList.remove('bi-chevron-left');
                    icon.classList.add('bi-chevron-right');
                } else {
                    icon.classList.remove('bi-chevron-right');
                    icon.classList.add('bi-chevron-left');
                }
            });
        }
    });
</script>

<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script>
    if (typeof lucide !== 'undefined') lucide.createIcons();
</script>

<?php include __DIR__ . '/floating_toast.php'; ?>