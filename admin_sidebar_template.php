<?php
// Admin Sidebar Template
// Usage: require 'admin_sidebar_template.php' at the top of admin pages after session_start() and db.php
// Then add your content, and finally require 'admin_sidebar_footer.php'

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$adminName = $_SESSION['username'] ?? 'Admin';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
require 'notification_helper.php';

// Get pending count for badge
$pendingCount = 0;
try {
    $pendingCount = $pdo->query("
        SELECT COUNT(id) FROM bookings 
        WHERE status IN ('pending', 'unassigned', 'deposit_submitted')
    ")->fetchColumn();
} catch (PDOException $e) {
    $pendingCount = 0;
}

// Get new/pending emergency request count for badge
$emergencyCount = 0;
try {
    $emergencyCount = $pdo->query("
        SELECT COUNT(id) FROM emergency_service_requests 
        WHERE request_status IN ('pending', 'new')
    ")->fetchColumn();
} catch (PDOException $e) {
    $emergencyCount = 0;
}

// --- Admin notification items for the header bell ---
$adminNotificationItems = [];
$adminSeenBookings  = $_SESSION['admin_seen_booking_ids'] ?? [];
$adminSeenEmergency = $_SESSION['admin_seen_emergency_ids'] ?? [];
$adminSeenWarranty  = $_SESSION['admin_seen_warranty_ids'] ?? [];
$adminSeenHealth    = $_SESSION['admin_seen_health_ids'] ?? [];

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
        $adminNotificationItems[] = [
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
        $adminNotificationItems[] = [
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
        $adminNotificationItems[] = [
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
        $adminNotificationItems[] = [
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

$adminNotificationCount = count($adminNotificationItems);
$adminNotifIcons = [
    'booking'   => 'bi-journal-text',
    'emergency' => 'bi-exclamation-triangle-fill',
    'warranty'  => 'bi-shield-check',
    'health'    => 'bi-heart-pulse'
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? $pageTitle : 'Admin' ?> | Mindanao Eversure</title>
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="fonts.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <style>
        :root {
            --sidebar-width: 280px;
            --primary-color: #0f172a;
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
            --accent-color: #1e3a5f;
            --accent-gradient: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
            --bg-light: #f1f5f9;
            --bg-card: #ffffff;
            --text-dark: #1e293b;
            --text-light: #64748b;
            --success: #10b981;
            --danger: #ef4444;
            --info: #3b82f6;
            --sidebar-bg: #0f172a;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg-light);
            color: var(--text-dark);
            overflow: hidden;
            width: 100%;
            height: 100vh;
        }

        /* Enhanced Animated Background */
        .bg-animation {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            z-index: -1;
            overflow: hidden;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 50%, #f1f5f9 100%);
            pointer-events: none;
            will-change: opacity;
        }

        .bg-animation::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(circle at 80% 20%, rgba(30, 41, 59, 0.2) 0%, transparent 40%),
                radial-gradient(circle at 20% 80%, rgba(15, 23, 42, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(30, 41, 59, 0.1) 0%, transparent 60%);
            animation: bgPulse 15s ease-in-out infinite;
        }

        @keyframes bgPulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .bg-animation .circle {
            position: absolute;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.25) 0%, rgba(30, 41, 59, 0.08) 70%, transparent 100%);
            box-shadow: 0 0 60px rgba(30, 41, 59, 0.2);
            animation: float 15s infinite ease-in-out;
        }

        .bg-animation .circle:nth-child(1) {
            width: 500px; height: 500px;
            top: -150px; right: -100px;
            animation-delay: 0s;
        }

        .bg-animation .circle:nth-child(2) {
            width: 400px; height: 400px;
            bottom: -100px; left: -100px;
            animation-delay: 3s;
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.15) 0%, rgba(15, 23, 42, 0.04) 70%, transparent 100%);
        }

        .bg-animation .circle:nth-child(3) {
            width: 350px; height: 350px;
            top: 40%; right: 15%;
            animation-delay: 6s;
        }

        .bg-animation .circle:nth-child(4) {
            width: 300px; height: 300px;
            top: 10%; left: 20%;
            animation-delay: 9s;
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.2) 0%, rgba(30, 41, 59, 0.05) 70%, transparent 100%);
        }

        .bg-animation .circle:nth-child(5) {
            width: 250px; height: 250px;
            bottom: 20%; right: 30%;
            animation-delay: 12s;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1) rotate(0deg); }
            25% { transform: translate(30px, -30px) scale(1.1) rotate(5deg); }
            50% { transform: translate(-20px, 20px) scale(0.95) rotate(-5deg); }
            75% { transform: translate(20px, 10px) scale(1.05) rotate(3deg); }
        }

        /* Floating Tools Icons (like index.php) */
        .floating-tools {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
            opacity: 0.8;
            will-change: transform;
        }

        .floating-tools i {
            position: absolute;
            color: rgba(30, 41, 59, 0.35);
            font-size: 2.5rem;
            animation: floatTool 6s ease-in-out infinite;
            text-shadow: 0 0 25px rgba(30, 41, 59, 0.4);
        }

        .floating-tools i:nth-child(1) { top: 15%; left: 8%; animation-delay: 0s; font-size: 3rem; }
        .floating-tools i:nth-child(2) { top: 25%; right: 12%; animation-delay: 1s; font-size: 2rem; }
        .floating-tools i:nth-child(3) { top: 45%; left: 15%; animation-delay: 2s; font-size: 2.8rem; }
        .floating-tools i:nth-child(4) { bottom: 30%; right: 8%; animation-delay: 3s; font-size: 2.2rem; }
        .floating-tools i:nth-child(5) { top: 60%; left: 5%; animation-delay: 4s; font-size: 2.5rem; }
        .floating-tools i:nth-child(6) { bottom: 20%; left: 20%; animation-delay: 5s; font-size: 2rem; }
        .floating-tools i:nth-child(7) { top: 35%; right: 5%; animation-delay: 6s; font-size: 3rem; }
        .floating-tools i:nth-child(8) { bottom: 40%; right: 15%; animation-delay: 7s; font-size: 2.3rem; }

        @keyframes floatTool {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-25px) rotate(10deg); }
        }

        /* Glassmorphism Cards - Fully Transparent with blur */
        .card-glass {
            background: rgba(255, 255, 255, 0.4) !important;
            backdrop-filter: blur(15px) !important;
            -webkit-backdrop-filter: blur(15px) !important;
            border: 1px solid rgba(255, 255, 255, 0.6) !important;
            box-shadow: 0 8px 32px rgba(30, 41, 59, 0.1) !important;
        }

        .card-glass:hover {
            background: rgba(255, 255, 255, 0.6) !important;
            box-shadow: 0 12px 40px rgba(30, 41, 59, 0.15) !important;
        }

        /* Main content area cards transparency */
        .main-content .card {
            background: rgba(255, 255, 255, 0.45);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.7);
            box-shadow: 0 4px 24px rgba(30, 41, 59, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            color: #1e293b;
        }

        .main-content .card:hover {
            background: rgba(255, 255, 255, 0.65);
            box-shadow: 0 8px 32px rgba(30, 41, 59, 0.15);
        }

        /* Dark text for all content inside cards */
        .main-content .card h1,
        .main-content .card h2,
        .main-content .card h3,
        .main-content .card h4,
        .main-content .card h5,
        .main-content .card h6,
        .main-content .card p,
        .main-content .card span,
        .main-content .card div,
        .main-content .card label,
        .main-content .card td,
        .main-content .card th,
        .main-content .card li,
        .main-content .card small,
        .main-content .card strong,
        .main-content .card b,
        .main-content .card i:not(.bi):not(.fas):not(.far),
        .main-content .card a:not(.btn) {
            color: #1e293b;
        }

        .main-content .card .text-muted,
        .main-content .card .text-secondary {
            color: rgba(30, 41, 59, 0.7) !important;
        }

        /* List groups inside cards - more transparent */
        .card .list-group-item {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.4);
        }

        .card .list-group-item:hover {
            background: rgba(255, 255, 255, 0.5);
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: transform 0.3s ease, width 0.3s ease;
            box-shadow: 2px 0 20px rgba(0, 0, 0, 0.3);
        }

        .sidebar::-webkit-scrollbar { width: 6px; }
        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
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
            z-index: 10;
            min-height: 60px;
        }

        .sidebar-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent 0%, #3b82f6 25%, #FACC15 75%, transparent 100%);
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: white;
            font-size: 1.2rem;
            font-weight: 700;
            line-height: 1.2;
            transition: opacity 0.3s ease;
        }

        .sidebar-brand:hover {
            opacity: 0.9;
        }

        .sidebar-brand i,
        .sidebar-brand svg {
            color: #FACC15;
            width: 34px;
            height: 34px;
            flex-shrink: 0;
        }

        .sidebar-collapse-btn {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #FACC15;
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
            background: rgba(250, 204, 21, 0.15);
            border-color: rgba(250, 204, 21, 0.35);
        }
        .sidebar-collapse-btn i,
        .sidebar-collapse-btn svg {
            color: #FACC15 !important;
        }

        .sidebar-menu {
            padding: 20px 0;
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sidebar-menu::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar-menu::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 2px;
        }

        .menu-section { padding: 0 20px; margin-bottom: 20px; }

        .menu-title {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
            padding-left: 15px;
            position: sticky;
            top: 0;
            background: var(--sidebar-bg);
            z-index: 5;
            padding-top: 10px;
            padding-bottom: 5px;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: #ffffff;
            text-decoration: none;
            border-radius: 12px;
            margin: 4px 15px;
            
            position: relative;
            overflow: hidden;
        }

        .menu-item::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 3px;
            background: var(--accent-gradient);
            transform: scaleY(0);
            transition: transform 0.2s ease;
        }

        .menu-item:hover, .menu-item.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .menu-item.active {
            background: #FACC15;
            color: #111827 !important;
        }

        .menu-item:hover::before, .menu-item.active::before {
            transform: scaleY(1);
        }

        .menu-item.active, .menu-item.active::before {
            transition: none !important;
        }

        .menu-item i,
        .menu-item svg { width: 20px; height: 20px; flex-shrink: 0; }
        .menu-item span { font-size: 0.85rem; font-weight: 500; }
        .menu-title { color: rgba(255, 255, 255, 0.5); font-size: 0.75rem; font-weight: 700; }

        .menu-badge {
            margin-left: auto;
            background: #FACC15;
            color: #111827;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .menu-item[href*="manage_bookings.php"] .menu-badge {
            background: #ef4444;
            color: #ffffff;
            padding: 1px 6px;
            font-size: 0.65rem;
        }
        .menu-item[href*="admin_emergency_requests"] .menu-badge {
            background: #ef4444;
            color: #ffffff;
            padding: 1px 6px;
            font-size: 0.65rem;
        }

        /* ===== Collapsible parent menus ===== */
        .menu-parent {
            width: calc(100% - 30px);
            background: none;
            border: none;
            font-family: inherit;
            text-align: left;
            cursor: pointer;
        }
        .menu-caret {
            width: 15px !important;
            height: 15px !important;
            margin-left: auto;
            transition: transform 0.25s ease;
            flex-shrink: 0;
        }
        .menu-parent .menu-badge + .menu-caret { margin-left: 0; }
        .menu-parent.open .menu-caret { transform: rotate(180deg); }
        .menu-parent.child-active {
            background: rgba(255, 255, 255, 0.08);
            color: #FACC15;
        }
        .submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        .submenu.open { max-height: 300px; }
        .submenu-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 20px 9px 52px;
            margin: 2px 15px;
            color: rgba(255, 255, 255, 0.75);
            text-decoration: none;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 500;
            
            position: relative;
        }
        .submenu-item::before {
            content: '';
            position: absolute;
            left: 34px;
            top: 50%;
            transform: translateY(-50%);
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.35);
        }
        .submenu-item:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }
        .submenu-item.active { background: #FACC15; color: #111827 !important; }
        .submenu-item.active::before { background: #111827; }

        /* ===== Sub-group titles inside a section ===== */
        .menu-subtitle {
            color: rgba(255, 255, 255, 0.85);
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 10px 20px 4px 20px;
        }

        /* Pulse animation removed to prevent sidebar flicker */

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(30, 41, 59, 0.1);
            background: #ffffff;
            flex-shrink: 0;
            position: relative;
            z-index: 10;
            margin-top: auto;
        }

        .sidebar-footer-text {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #111827;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .sidebar-footer-text i,
        .sidebar-footer-text svg {
            color: #FACC15;
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        .sidebar-footer-copyright {
            text-align: center;
            color: rgba(30, 41, 59, 0.5);
            font-size: 0.65rem;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            
        }

        .user-profile:hover {
            background: rgba(255, 255, 255, 0.08);
        }

        .user-avatar {
            width: 45px; height: 45px;
            background: #1e3a5f;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        .user-info { flex: 1; min-width: 0; line-height: 1.2; }
        .user-name {
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .user-role { color: rgba(255, 255, 255, 0.5); font-size: 0.75rem; }

        .btn-logout-sm {
            background: transparent;
            border: none;
            color: rgba(255, 255, 255, 0.5);
            cursor: pointer;
            padding: 5px;
            transition: transform 0.2s ease;
            flex-shrink: 0;
        }
        .btn-logout-sm:hover {
            color: var(--danger);
            transform: scale(1.1);
        }

        /* Main Content */
        .main-wrapper {
            margin-left: var(--sidebar-width);
            height: 100vh;
            display: flex;
            flex-direction: column;
            transition: margin-left 0.3s ease;
            background: transparent;
            width: calc(100% - var(--sidebar-width));
        }

        .top-header {
            background: #ffffff;
            padding: 12px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            z-index: 100;
            border-bottom: 2px solid rgba(30, 58, 95, 0.3);
            overflow: visible;
            height: 60px;
            transition: left 0.3s ease;
        }

        .top-header::after { display: none; }

        .top-header .page-title,
        .top-header .text-muted {
            color: #111827 !important;
        }

        .top-header .page-title i {
            color: #FACC15 !important;
        }

        .top-header .btn-toggle-sidebar {
            color: #111827 !important;
        }

        .page-title {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
            line-height: 1.2;
        }
        .page-title i { color: var(--accent-color); }

        /* Smaller admin header user text */
        .top-header .user-name { font-size: 0.8rem; }
        .top-header .user-role { font-size: 0.7rem; }

        /* Admin user dropdown (same design as customer) */
        .header-user-wrap {
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            padding: 4px 10px;
            border-radius: 12px;
            
            z-index: 2;
        }

        .header-user-wrap:hover { background: rgba(0, 0, 0, 0.05); }

        .top-bar-dropdown-btn {
            color: var(--text-light);
            font-size: 0.7rem;
            transition: transform 0.3s ease;
        }

        .header-user-wrap.active .top-bar-dropdown-btn { transform: rotate(180deg); }

        .header-user-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            background: #ffffff;
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

        .header-user-wrap.active .header-user-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .header-user-dropdown .dropdown-header {
            padding: 10px 14px;
            background: #ffffff;
            border-bottom: 1px solid #f1f5f9;
        }

        .header-user-dropdown .dropdown-header-name {
            color: #1e293b;
            font-size: 0.82rem;
            font-weight: 600;
            margin-bottom: 1px;
        }

        .header-user-dropdown .dropdown-header-role {
            color: #64748b;
            font-size: 0.68rem;
        }

        .header-user-dropdown .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            color: var(--text-dark);
            text-decoration: none;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            cursor: pointer;
            font-size: 0.8rem;
        }

        .header-user-dropdown .dropdown-item:hover {
            background: rgba(250, 204, 21, 0.1);
            color: #FACC15;
        }

        .header-user-dropdown .dropdown-item i {
            font-size: 1rem;
            width: 18px;
            text-align: center;
        }

        .header-user-dropdown .dropdown-item.danger {
            color: #ef4444;
            border-top: 1px solid #f1f5f9;
        }

        .header-user-dropdown .dropdown-item.danger:hover {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }

        .btn-toggle-sidebar {
            display: block;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-dark);
            cursor: pointer;
            padding: 0;
            margin-right: 15px;
        }

        .main-content {
            padding: 60px 30px 30px 30px;
            background: transparent;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
        }

        /* Page fade-in animation removed to prevent navigation flicker */

        /* Overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }
        .sidebar-overlay.show { display: block; }

        /* Floating icons for top header */
        .header-floating-icons {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 1;
        }

        .header-floating-icons i {
            position: absolute;
            color: rgba(30, 41, 59, 0.2);
            font-size: 1.5rem;
            animation: floatHeader 4s ease-in-out infinite;
        }

        .header-floating-icons i:nth-child(1) { top: 20%; left: 10%; animation-delay: 0s; }
        .header-floating-icons i:nth-child(2) { top: 30%; right: 15%; animation-delay: 0.5s; font-size: 1.2rem; }
        .header-floating-icons i:nth-child(3) { top: 50%; left: 25%; animation-delay: 1s; font-size: 1.8rem; }
        .header-floating-icons i:nth-child(4) { top: 40%; right: 30%; animation-delay: 1.5s; font-size: 1.3rem; }
        .header-floating-icons i:nth-child(5) { top: 60%; left: 15%; animation-delay: 2s; font-size: 1.6rem; }
        .header-floating-icons i:nth-child(6) { top: 25%; right: 25%; animation-delay: 2.5s; font-size: 1.4rem; }

        @keyframes floatHeader {
            0%, 100% { transform: translateY(0) rotate(0deg); opacity: 0.3; }
            50% { transform: translateY(-10px) rotate(5deg); opacity: 0.6; }
        }

        /* Responsive */
        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
            .main-wrapper { margin-left: 0; width: 100%; }
            .btn-toggle-sidebar { display: block; }
            .top-header { left: 0; }
        }

        /* Scroll to top */
        .scroll-top {
            position: fixed;
            bottom: 30px; right: 30px;
            width: 45px; height: 45px;
            background: var(--accent-gradient);
            color: white;
            border: none;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            visibility: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            z-index: 99;
            box-shadow: 0 4px 15px rgba(30, 58, 95, 0.4);
        }
        .scroll-top.visible { opacity: 1; visibility: visible; }
        .scroll-top:hover { transform: translateY(-5px); }

        /* --- Notification bell & dropdown (shared design) --- */
        .admin-notifications {
            position: relative;
            margin-right: 12px;
            display: flex;
            align-items: center;
            z-index: 2;
        }

        .notification-bell {
            position: relative;
            cursor: pointer;
            color: inherit;
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

        .notification-item-text { flex: 1; min-width: 0; }

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

        @media (max-width: 576px) {
            .notification-dropdown {
                width: calc(100vw - 24px);
                right: -10px;
            }
        }

        .bg-animation, .floating-tools, .header-floating-icons { display: none; }
        /* Sidebar dark blue gradient theme / FOUC guard */
        .sidebar {
            background:
                radial-gradient(460px 170px at 85% -50px, rgba(250, 204, 21, 0.12), transparent 70%),
                linear-gradient(180deg, #12203c 0%, #0f172a 55%, #0a1120 100%) !important;
        }
        .sidebar-header { background: transparent !important; }
        .menu-title {
            background: transparent !important;
            color: rgba(255, 255, 255, 0.45) !important;
            display: flex;
            align-items: center;
            gap: 10px;
            backdrop-filter: blur(8px);
        }
        .menu-title::after { content: ''; flex: 1; height: 1px; background: rgba(255, 255, 255, 0.08); }
        .menu-subtitle {
            display: flex;
            align-items: center;
            gap: 10px;
            color: rgba(255, 255, 255, 0.55) !important;
        }
        .menu-subtitle::after { content: ''; flex: 1; height: 1px; background: rgba(255, 255, 255, 0.07); }
        .menu-section + .menu-section { border-top: 1px solid rgba(255, 255, 255, 0.05); padding-top: 14px; }
        .menu-item { color: rgba(255, 255, 255, 0.85) !important; transition: transform 0.2s ease; }
        .menu-item i, .menu-item svg { color: rgba(255, 255, 255, 0.55) !important;  }
        .menu-item:hover { color: #ffffff !important; background: rgba(255, 255, 255, 0.08) !important; transform: translateX(3px) !important; }
        .menu-item:hover i, .menu-item:hover svg { color: #FACC15 !important; }
        .menu-item.active {
            color: #111827 !important;
            background: linear-gradient(135deg, #FDE047 0%, #FACC15 100%) !important;
            box-shadow: 0 8px 18px rgba(250, 204, 21, 0.22) !important;
        }
        .menu-item.active i, .menu-item.active svg { color: #111827 !important; }
        .menu-item.active::before { transform: scaleY(0) !important; }
        .menu-item::before { background: #FACC15 !important; }
        .menu-parent.child-active i, .menu-parent.child-active svg { color: #FACC15 !important; }
        .submenu-item { transition: transform 0.2s ease; }
        .submenu-item:hover { transform: translateX(3px) !important; }
        .submenu-item.active {
            background: linear-gradient(135deg, #FDE047 0%, #FACC15 100%) !important;
            color: #111827 !important;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(250, 204, 21, 0.25) !important;
        }
        .sidebar-brand, .sidebar-brand span { color: white !important; }
        .sidebar-brand i, .sidebar-brand svg { color: #FACC15 !important; filter: drop-shadow(0 2px 6px rgba(250, 204, 21, 0.4)) !important; }
        .user-name { color: white !important; }
        .user-role { color: rgba(255, 255, 255, 0.5) !important; }
        .btn-logout-sm { color: rgba(255, 255, 255, 0.5) !important; }
        .btn-logout-sm:hover { color: #ef4444 !important; }
        .user-profile { background: rgba(255, 255, 255, 0.05) !important; }
        .user-profile:hover { background: rgba(255, 255, 255, 0.08) !important; }
        .sidebar-footer { background: transparent !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }
        .sidebar-footer-text { color: white !important; }
        .sidebar-footer-copyright { color: rgba(255, 255, 255, 0.5) !important; }
        .sidebar::-webkit-scrollbar, .sidebar-menu::-webkit-scrollbar { width: 0 !important; height: 0 !important; }
        .sidebar, .sidebar-menu { scrollbar-width: none !important; }
        .sidebar::-webkit-scrollbar-thumb, .sidebar-menu::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2) !important; }
    /* Collapsible sidebar */
    body.collapsed { --sidebar-width: 80px; }
    body.collapsed .menu-item { justify-content: center; padding: 12px 0; margin: 4px 10px; }
    body.collapsed .menu-item span,
    body.collapsed .menu-title,
    body.collapsed .menu-subtitle,
    body.collapsed .menu-caret,
    body.collapsed .submenu,
    body.collapsed .menu-badge,
    body.collapsed .sidebar-brand span,
    body.collapsed .user-info,
    body.collapsed .btn-logout-sm { display: none; }
    body.collapsed .user-avatar { width: 35px; height: 35px; font-size: 1rem; }
    body.collapsed .user-profile { padding: 10px; justify-content: center; }
    body.collapsed .sidebar-footer { padding: 10px; }
    body.collapsed .sidebar-footer-text span,
    body.collapsed .sidebar-footer-copyright { display: none; }

        /* ===== Dark Mode ===== */
        .dark-mode-toggle {
            position: relative;
            cursor: pointer;
            color: inherit;
            font-size: 1.25rem;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: transform 0.25s ease, box-shadow 0.25s ease, opacity 0.25s ease, visibility 0.25s ease, max-height 0.25s ease;
            margin-right: 12px;
            z-index: 2;
        }
        .dark-mode-toggle:hover { background: rgba(148, 163, 184, 0.28); transform: translateY(-1px); }
        .dark-mode-toggle i { transition: transform 0.3s ease; }
        .dark-mode-toggle:hover i { transform: rotate(20deg); }
        html[data-theme="dark"] .dark-mode-toggle { color: #FACC15; }

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
            --secondary-color: #94a3b8;
            --card-border: rgba(255, 255, 255, 0.09);
            --border-color: rgba(255, 255, 255, 0.09);
            --border-highlight: rgba(250, 204, 21, 0.35);
        }

        html[data-theme="dark"] body { background: #101f3c !important; color: #e2e8f0 !important; }
        html[data-theme="dark"] .bg-animation { background: linear-gradient(135deg, #101f3c 0%, #16233f 50%, #101f3c 100%) !important; }
        html[data-theme="dark"] .main-content { background: #101f3c !important; }
        html[data-theme="dark"] body .main-content { color: #e2e8f0 !important; }
        html[data-theme="dark"] .scroll-top { box-shadow: 0 4px 15px rgba(0, 0, 0, 0.6); }

        /* Top header */
        html[data-theme="dark"] .top-header {
            background: #101f3c !important;
            border-bottom: 2px solid rgba(250, 204, 21, 0.4) !important;
            box-shadow: none !important;
        }
        html[data-theme="dark"] .top-header .page-title,
        html[data-theme="dark"] .top-header .text-muted,
        html[data-theme="dark"] .top-header .btn-toggle-sidebar,
        html[data-theme="dark"] .top-header .user-name,
        html[data-theme="dark"] .top-header .user-role { color: #e2e8f0 !important; }

        /* Sidebar stays dark */
        html[data-theme="dark"] .sidebar {
            background:
                radial-gradient(460px 170px at 85% -50px, rgba(250, 204, 21, 0.08), transparent 70%),
                linear-gradient(180deg, #12203f 0%, #101f3c 55%, #0b1528 100%) !important;
            box-shadow: none !important;
            border-right: 1px solid rgba(255, 255, 255, 0.07) !important;
        }
        html[data-theme="dark"] .sidebar-header { background: transparent !important; }
        html[data-theme="dark"] .menu-title { background: transparent !important; color: rgba(255, 255, 255, 0.45) !important; }
        html[data-theme="dark"] .menu-subtitle { color: rgba(255, 255, 255, 0.6) !important; }
        html[data-theme="dark"] .menu-item { color: rgba(255, 255, 255, 0.85) !important; }
        html[data-theme="dark"] .menu-item:hover { color: #ffffff !important; background: rgba(255, 255, 255, 0.08) !important; }
        html[data-theme="dark"] .menu-item.active {
            color: #111827 !important;
            background: linear-gradient(135deg, #FDE047 0%, #FACC15 100%) !important;
            box-shadow: 0 8px 18px rgba(250, 204, 21, 0.22) !important;
        }
        html[data-theme="dark"] .menu-parent.child-active { color: #FACC15 !important; background: rgba(255, 255, 255, 0.08) !important; }
        html[data-theme="dark"] .menu-parent.child-active i, html[data-theme="dark"] .menu-parent.child-active svg { color: #FACC15 !important; }
        html[data-theme="dark"] .submenu-item { color: rgba(255, 255, 255, 0.75) !important; }
        html[data-theme="dark"] .submenu-item::before { background: rgba(255, 255, 255, 0.35) !important; }
        html[data-theme="dark"] .submenu-item:hover { color: #ffffff !important; background: rgba(255, 255, 255, 0.08) !important; transform: translateX(3px) !important; }
        html[data-theme="dark"] .submenu-item.active {
            color: #111827 !important;
            background: linear-gradient(135deg, #FDE047 0%, #FACC15 100%) !important;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(250, 204, 21, 0.25) !important;
        }
        html[data-theme="dark"] .submenu-item.active::before { background: #111827 !important; }
        html[data-theme="dark"] .sidebar-brand, html[data-theme="dark"] .sidebar-brand span { color: #ffffff !important; }
        html[data-theme="dark"] .sidebar-footer { background: transparent !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }
        html[data-theme="dark"] .sidebar-footer-text { color: #ffffff !important; }
        html[data-theme="dark"] .sidebar-footer-copyright { color: rgba(255, 255, 255, 0.5) !important; }
        html[data-theme="dark"] .user-profile { background: rgba(255, 255, 255, 0.05) !important; }
        html[data-theme="dark"] .user-profile:hover { background: rgba(255, 255, 255, 0.08) !important; }
        html[data-theme="dark"] .sidebar .user-name { color: #ffffff !important; }
        html[data-theme="dark"] .sidebar .user-role { color: rgba(255, 255, 255, 0.5) !important; }
        html[data-theme="dark"] .btn-logout-sm { color: rgba(255, 255, 255, 0.5) !important; }
        html[data-theme="dark"] .sidebar::-webkit-scrollbar-thumb, html[data-theme="dark"] .sidebar-menu::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2) !important; }

        /* Cards & surfaces */
        html[data-theme="dark"] .main-content .card,
        html[data-theme="dark"] .card-glass,
        html[data-theme="dark"] .stat-card,
        html[data-theme="dark"] .card-x,
        html[data-theme="dark"] .mw-card,
        html[data-theme="dark"] .dash-alert {
            background: #1a2b4f !important;
            border-color: rgba(255, 255, 255, 0.09) !important;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.35) !important;
            color: #e2e8f0 !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }
        html[data-theme="dark"] .main-content .card:hover,
        html[data-theme="dark"] .card-glass:hover,
        html[data-theme="dark"] .stat-card:hover,
        html[data-theme="dark"] .card-x:hover {
            background: #22335a !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.45) !important;
            border-color: rgba(250, 204, 21, 0.25) !important;
        }
        html[data-theme="dark"] .card-header, html[data-theme="dark"] .card-footer {
            background: rgba(255, 255, 255, 0.03) !important;
            border-color: rgba(255, 255, 255, 0.09) !important;
            color: #e2e8f0 !important;
        }
        html[data-theme="dark"] .list-group-item {
            background: rgba(255, 255, 255, 0.04) !important;
            border-color: rgba(255, 255, 255, 0.08) !important;
            color: #e2e8f0 !important;
        }
        html[data-theme="dark"] .card .list-group-item:hover,
        html[data-theme="dark"] .list-group-item:hover { background: rgba(255, 255, 255, 0.07) !important; }

        /* Text colors */
        html[data-theme="dark"] .main-content .card h1,
        html[data-theme="dark"] .main-content .card h2,
        html[data-theme="dark"] .main-content .card h3,
        html[data-theme="dark"] .main-content .card h4,
        html[data-theme="dark"] .main-content .card h5,
        html[data-theme="dark"] .main-content .card h6,
        html[data-theme="dark"] .main-content .card p,
        html[data-theme="dark"] .main-content .card span,
        html[data-theme="dark"] .main-content .card div,
        html[data-theme="dark"] .main-content .card label,
        html[data-theme="dark"] .main-content .card td,
        html[data-theme="dark"] .main-content .card th,
        html[data-theme="dark"] .main-content .card li,
        html[data-theme="dark"] .main-content .card small,
        html[data-theme="dark"] .main-content .card strong,
        html[data-theme="dark"] .main-content .card b,
        html[data-theme="dark"] .main-content .card i:not(.bi):not(.fas):not(.far),
        html[data-theme="dark"] .main-content .card a:not(.btn) { color: #e2e8f0; }

        html[data-theme="dark"] .main-content .card .text-muted,
        html[data-theme="dark"] .main-content .card .text-secondary,
        html[data-theme="dark"] .text-muted,
        html[data-theme="dark"] .text-secondary,
        html[data-theme="dark"] .dash-page-subtitle,
        html[data-theme="dark"] .dash-header-meta,
        html[data-theme="dark"] .card-x-desc,
        html[data-theme="dark"] .appt-sub,
        html[data-theme="dark"] .appt-empty,
        html[data-theme="dark"] .mw-sub,
        html[data-theme="dark"] .stat-trend-sub { color: #94a3b8 !important; }

        html[data-theme="dark"] .dash-page-title,
        html[data-theme="dark"] .stat-value,
        html[data-theme="dark"] .stat-label,
        html[data-theme="dark"] .card-x-title,
        html[data-theme="dark"] .appt-name,
        html[data-theme="dark"] .mw-title,
        html[data-theme="dark"] .mw-value,
        html[data-theme="dark"] .donut-legend-row .name,
        html[data-theme="dark"] .donut-legend-row .pct,
        html[data-theme="dark"] .main-content h1,
        html[data-theme="dark"] .main-content h2,
        html[data-theme="dark"] .main-content h3,
        html[data-theme="dark"] .main-content h4,
        html[data-theme="dark"] .main-content h5,
        html[data-theme="dark"] .main-content h6 { color: #e2e8f0 !important; }

        html[data-theme="dark"] .text-dark { color: #e2e8f0 !important; }
        html[data-theme="dark"] .bg-white, html[data-theme="dark"] .bg-light { background-color: #1a2b4f !important; }
        html[data-theme="dark"] .border, html[data-theme="dark"] .border-top, html[data-theme="dark"] .border-bottom,
        html[data-theme="dark"] .border-start, html[data-theme="dark"] .border-end, html[data-theme="dark"] hr { border-color: rgba(255, 255, 255, 0.09) !important; }
        html[data-theme="dark"] hr { opacity: 0.4; }

        /* Dashboard soft-tint elements */
        html[data-theme="dark"] .stat-icon.blue { background: transparent !important; color: #60a5fa !important; }
        html[data-theme="dark"] .stat-icon.green { background: transparent !important; color: #34d399 !important; }
        html[data-theme="dark"] .stat-icon.amber { background: transparent !important; color: #fbbf24 !important; }
        html[data-theme="dark"] .stat-icon.red { background: transparent !important; color: #f87171 !important; }
        html[data-theme="dark"] .stat-icon.purple { background: transparent !important; color: #a78bfa !important; }
        html[data-theme="dark"] .mw-icon.red { background: transparent !important; color: #f87171 !important; }
        html[data-theme="dark"] .mw-icon.amber { background: transparent !important; color: #fbbf24 !important; }
        html[data-theme="dark"] .appt-item { border-bottom-color: rgba(255, 255, 255, 0.08) !important; }
        html[data-theme="dark"] .appt-chevron { color: #475569 !important; }
        html[data-theme="dark"] .appt-badge.approved { background: rgba(59, 130, 246, 0.18) !important; color: #93c5fd !important; }
        html[data-theme="dark"] .appt-badge.pending { background: rgba(245, 158, 11, 0.18) !important; color: #fbbf24 !important; }
        html[data-theme="dark"] .appt-badge.scheduled { background: rgba(148, 163, 184, 0.18) !important; color: #cbd5e1 !important; }
        html[data-theme="dark"] .appt-badge.confirmed { background: rgba(16, 185, 129, 0.18) !important; color: #34d399 !important; }

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
        html[data-theme="dark"] .table-responsive { color: #e2e8f0; }

        /* Forms */
        html[data-theme="dark"] .form-control,
        html[data-theme="dark"] .form-select,
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
        html[data-theme="dark"] .form-control::placeholder, html[data-theme="dark"] textarea::placeholder { color: #64748b; }
        html[data-theme="dark"] .form-control:focus, html[data-theme="dark"] .form-select:focus {
            background-color: #132140 !important;
            color: #e2e8f0 !important;
            border-color: #FACC15 !important;
            box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.15) !important;
        }
        html[data-theme="dark"] .form-control:disabled, html[data-theme="dark"] .form-control[readonly] { background-color: #22335a !important; color: #94a3b8 !important; }
        html[data-theme="dark"] .form-check-input { background-color: #16233f; border-color: #3b4d7d; }
        html[data-theme="dark"] .form-check-input:checked { background-color: #FACC15; border-color: #FACC15; }
        html[data-theme="dark"] .form-label, html[data-theme="dark"] .form-check-label, html[data-theme="dark"] .form-text { color: #e2e8f0 !important; }
        html[data-theme="dark"] .input-group-text { background-color: #22335a !important; color: #94a3b8 !important; border-color: #3b4d7d !important; }

        /* Bootstrap dropdowns, modals, pagination, tabs */
        html[data-theme="dark"] .dropdown-menu { background-color: #22335a; border-color: rgba(255, 255, 255, 0.1); }
        html[data-theme="dark"] .dropdown-menu .dropdown-item { color: #e2e8f0; }
        html[data-theme="dark"] .dropdown-menu .dropdown-item:hover,
        html[data-theme="dark"] .dropdown-menu .dropdown-item:focus { background: rgba(250, 204, 21, 0.12); color: #FDE047; }
        html[data-theme="dark"] .dropdown-menu .dropdown-divider { border-color: rgba(255, 255, 255, 0.09); }
        html[data-theme="dark"] .modal-content { background-color: #1a2b4f; color: #e2e8f0; border-color: rgba(255, 255, 255, 0.1); }
        html[data-theme="dark"] .modal-header, html[data-theme="dark"] .modal-footer { border-color: rgba(255, 255, 255, 0.09); }
        html[data-theme="dark"] .modal-title { color: #e2e8f0; }
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

        /* Header notification dropdown */
        html[data-theme="dark"] .notification-dropdown {
            background: #22335a;
            border-color: rgba(255, 255, 255, 0.1);
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.6), 0 4px 12px rgba(0, 0, 0, 0.4);
        }
        html[data-theme="dark"] .notification-dropdown-header { background: #22335a; border-bottom-color: rgba(255, 255, 255, 0.09); }
        html[data-theme="dark"] .nd-title { color: #e2e8f0; }
        html[data-theme="dark"] .nd-link { color: #60a5fa; }
        html[data-theme="dark"] .notification-item { border-bottom-color: rgba(255, 255, 255, 0.06); }
        html[data-theme="dark"] .notification-item:hover { background: rgba(255, 255, 255, 0.05); }
        html[data-theme="dark"] .notification-item-title { color: #e2e8f0; }
        html[data-theme="dark"] .notification-item-message { color: #94a3b8; }
        html[data-theme="dark"] .notification-item-time { color: #64748b; }
        html[data-theme="dark"] .notification-list::-webkit-scrollbar-thumb { background: #3b4d7d; }
        html[data-theme="dark"] .notification-empty { color: #64748b; }
        html[data-theme="dark"] .notification-empty > i { color: #3b4d7d; }
        html[data-theme="dark"] .notification-empty .ne-title { color: #cbd5e1; }
        html[data-theme="dark"] .notification-bell .badge { border-color: #0f172a; }

        /* Header user dropdown */
        html[data-theme="dark"] .header-user-wrap:hover { background: rgba(255, 255, 255, 0.06); }
        html[data-theme="dark"] .top-bar-dropdown-btn { color: #94a3b8; }
        html[data-theme="dark"] .header-user-dropdown { background: #22335a; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.6); }
        html[data-theme="dark"] .header-user-dropdown .dropdown-header { background: #22335a; border-bottom-color: rgba(255, 255, 255, 0.09); }
        html[data-theme="dark"] .header-user-dropdown .dropdown-header-name { color: #e2e8f0; }
        html[data-theme="dark"] .header-user-dropdown .dropdown-header-role { color: #94a3b8; }
        html[data-theme="dark"] .header-user-dropdown .dropdown-item { color: #e2e8f0; }
        html[data-theme="dark"] .header-user-dropdown .dropdown-item:hover { background: rgba(250, 204, 21, 0.12); color: #FDE047; }
        html[data-theme="dark"] .header-user-dropdown .dropdown-item.danger { color: #f87171; border-top-color: rgba(255, 255, 255, 0.09); }
        html[data-theme="dark"] .header-user-dropdown .dropdown-item.danger:hover { background: rgba(239, 68, 68, 0.15); color: #ef4444; }

        /* SweetAlert2 popup */
        html[data-theme="dark"] .swal2-popup { background: #22335a; color: #e2e8f0; }
        html[data-theme="dark"] .swal2-title, html[data-theme="dark"] .swal2-html-container { color: #e2e8f0; }
        html[data-theme="dark"] .swal2-input, html[data-theme="dark"] .swal2-select, html[data-theme="dark"] .swal2-textarea {
            background-color: #16233f; color: #e2e8f0; border-color: #3b4d7d;
        }

        /* ===== Shared master/detail pattern (bookings_status, emergency_status, ...) ===== */
        html[data-theme="dark"] .ab-tab.active { background: #22335a !important; }
        html[data-theme="dark"] .ab-tab:hover { border-color: #3b82f6 !important; }

        html[data-theme="dark"] .ab-list-item { background: #1a2b4f !important; box-shadow: none !important; }
        html[data-theme="dark"] .ab-list-item:hover { border-color: #3b82f6 !important; }
        html[data-theme="dark"] .ab-list-item.active { background: rgba(250, 204, 21, 0.1) !important; }
        html[data-theme="dark"] .ab-list-search { background: #16233f !important; border-color: #3b4d7d !important; color: #e2e8f0 !important; }
        html[data-theme="dark"] .ab-list-search::placeholder { color: #64748b; }
        html[data-theme="dark"] .ab-list-search:focus { border-color: #3b82f6 !important; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25) !important; }

        html[data-theme="dark"] .ab-list-icon,
        html[data-theme="dark"] .ab-detail-icon,
        html[data-theme="dark"] .ab-list-arrow,
        html[data-theme="dark"] .ab-list-item.active .ab-list-icon,
        html[data-theme="dark"] .ab-card-title i,
        html[data-theme="dark"] .ab-detail-meta-item i { color: #60a5fa !important; }

        html[data-theme="dark"] .ab-detail-id,
        html[data-theme="dark"] .ab-detail-meta-item { color: #e2e8f0 !important; }
        html[data-theme="dark"] .ab-detail-label { color: #94a3b8 !important; }
        html[data-theme="dark"] .ab-detail-value { color: #cbd5e1 !important; }
        html[data-theme="dark"] .ab-detail-block { color: #e2e8f0 !important; }
        html[data-theme="dark"] .ab-detail-block.muted { color: #94a3b8 !important; }
        html[data-theme="dark"] .ab-status-badge { background: #22335a !important; border-color: rgba(255, 255, 255, 0.12) !important; color: #e2e8f0 !important; }
        html[data-theme="dark"] .ab-detail-card { background: #1a2b4f !important; }
        html[data-theme="dark"] .ab-status-banner-sub { color: #94a3b8 !important; }
        html[data-theme="dark"] .ab-empty-state { color: #94a3b8 !important; }

        html[data-theme="dark"] .ab-action-btn,
        html[data-theme="dark"] .ab-back-btn,
        html[data-theme="dark"] .ab-detail-footer .ab-action-btn { background: rgba(255, 255, 255, 0.05) !important; color: #e2e8f0 !important; }
        html[data-theme="dark"] .ab-action-btn:hover,
        html[data-theme="dark"] .ab-back-btn:hover,
        html[data-theme="dark"] .ab-detail-footer .ab-action-btn:hover { background: rgba(255, 255, 255, 0.1) !important; color: #e2e8f0 !important; }
        html[data-theme="dark"] .ab-action-btn.print,
        html[data-theme="dark"] .ab-detail-footer .ab-action-btn.print { background: rgba(59, 130, 246, 0.15) !important; border-color: rgba(59, 130, 246, 0.4) !important; color: #93c5fd !important; }
        html[data-theme="dark"] .ab-action-btn.print:hover,
        html[data-theme="dark"] .ab-detail-footer .ab-action-btn.print:hover { background: rgba(59, 130, 246, 0.25) !important; }
        html[data-theme="dark"] .ab-action-btn.complete,
        html[data-theme="dark"] .ab-detail-footer .ab-action-btn.complete { color: #34d399 !important; }
        html[data-theme="dark"] .ab-action-btn.manage,
        html[data-theme="dark"] .ab-detail-footer .ab-action-btn.manage { color: #FDE047 !important; }
        html[data-theme="dark"] .ab-action-btn.reject,
        html[data-theme="dark"] .ab-detail-footer .ab-action-btn.reject { color: #f87171 !important; }

        html[data-theme="dark"] .ab-payment-card { background: rgba(250, 204, 21, 0.06) !important; }
        html[data-theme="dark"] .ab-rejection-card { background: rgba(239, 68, 68, 0.07) !important; }
        html[data-theme="dark"] .ab-rejection-title,
        html[data-theme="dark"] .ab-rejection-title i,
        html[data-theme="dark"] .ab-rejection-status,
        html[data-theme="dark"] .ab-rejection-value.lg { color: #f87171 !important; }
        html[data-theme="dark"] .ab-payment-label { color: #FDE047 !important; }
        html[data-theme="dark"] .ab-rejection-label { color: #f87171 !important; }

        html[data-theme="dark"] .ab-list-status.accepted { color: #34d399 !important; }
        html[data-theme="dark"] .ab-list-status.rejected { color: #f87171 !important; }
        html[data-theme="dark"] .ab-list-status.completed { color: #93c5fd !important; }
        html[data-theme="dark"] .ab-status-pill.pending { color: #FDE047 !important; }
        html[data-theme="dark"] .ab-status-pill.partial { color: #93c5fd !important; }
        html[data-theme="dark"] .ab-status-pill.completed { color: #34d399 !important; }
        html[data-theme="dark"] .ab-status-banner.completed { color: #93c5fd !important; }
        html[data-theme="dark"] .ab-status-banner.rejected { color: #f87171 !important; }
        html[data-theme="dark"] .ab-status-banner.pending { color: #FDE047 !important; }
        html[data-theme="dark"] .ab-rejected-box { color: #f87171 !important; }
        html[data-theme="dark"] .ab-error { color: #f87171 !important; }

        html[data-theme="dark"] .er-action-form .er-input,
        html[data-theme="dark"] .er-action-form select,
        html[data-theme="dark"] .er-action-form textarea {
            background: #16233f !important;
            color: #e2e8f0 !important;
            border-color: #3b4d7d !important;
        }

        /* ===== Module-wide coverage ===== */
        /* Page-level styles often force white modal headers and light surfaces */
        html[data-theme="dark"] .modal-header { background: #1a2b4f !important; color: #e2e8f0 !important; }
        html[data-theme="dark"] .modal-header .modal-title, html[data-theme="dark"] .modal-title { color: #e2e8f0 !important; }

        /* Shared card/list surfaces across management pages */
        html[data-theme="dark"] .customer-card, html[data-theme="dark"] .mechanic-card,
        html[data-theme="dark"] .vc-card, html[data-theme="dark"] .view-card,
        html[data-theme="dark"] .mechanic-stat-card, html[data-theme="dark"] .service-form-card,
        html[data-theme="dark"] .service-item, html[data-theme="dark"] .package-card,
        html[data-theme="dark"] .included-service-item, html[data-theme="dark"] .section-card,
        html[data-theme="dark"] .vehicle-header, html[data-theme="dark"] .service-card,
        html[data-theme="dark"] .hs-stat-card, html[data-theme="dark"] .health-event-card,
        html[data-theme="dark"] .customer-row, html[data-theme="dark"] .mechanic-list,
        html[data-theme="dark"] .customer-list, html[data-theme="dark"] .assignment-list,
        html[data-theme="dark"] .moto-thumb, html[data-theme="dark"] .customer-suggestions,
        html[data-theme="dark"] .maintenance-timeline, html[data-theme="dark"] .empty-state {
            background: #1a2b4f !important;
            border-color: rgba(255, 255, 255, 0.12) !important;
            color: #e2e8f0 !important;
        }
        html[data-theme="dark"] .customer-row:hover,
        html[data-theme="dark"] .mechanic-row:hover,
        html[data-theme="dark"] .assignment-row:hover,
        html[data-theme="dark"] .vehicle-header:hover { background: rgba(255, 255, 255, 0.05) !important; }

        /* List header rows */
        html[data-theme="dark"] .mechanic-list-header, html[data-theme="dark"] .customer-list-header,
        html[data-theme="dark"] .assignment-list-header, html[data-theme="dark"] #assignmentList .assignment-list-header {
            background: #22335a !important; color: #93c5fd !important;
        }
        html[data-theme="dark"] .mechanic-row, html[data-theme="dark"] .assignment-row, html[data-theme="dark"] .customer-row {
            border-bottom-color: rgba(255, 255, 255, 0.09) !important;
        }
        html[data-theme="dark"] .report-table th { background: #22335a !important; color: #93c5fd !important; }

        /* Page search inputs */
        html[data-theme="dark"] .search-input, html[data-theme="dark"] .mechanic-search-input,
        html[data-theme="dark"] .mh-search-input, html[data-theme="dark"] .customer-search-input {
            background: #16233f !important; color: #e2e8f0 !important; border-color: #3b4d7d !important;
        }
        html[data-theme="dark"] .mechanic-search-icon, html[data-theme="dark"] .mechanic-search-clear,
        html[data-theme="dark"] .mechanic-search-info, html[data-theme="dark"] .mh-search-icon,
        html[data-theme="dark"] .mh-search-clear, html[data-theme="dark"] .mh-search-info,
        html[data-theme="dark"] .customer-search-icon, html[data-theme="dark"] .customer-search-clear,
        html[data-theme="dark"] .customer-search-info, html[data-theme="dark"] .mechanic-total,
        html[data-theme="dark"] .customer-total, html[data-theme="dark"] .list-footer { color: #94a3b8 !important; }
        html[data-theme="dark"] .mechanic-search-clear:hover, html[data-theme="dark"] .mh-search-clear:hover,
        html[data-theme="dark"] .customer-search-clear:hover { background: rgba(255, 255, 255, 0.08) !important; color: #f87171 !important; }

        html[data-theme="dark"] .filter-btn { border-color: rgba(255, 255, 255, 0.15) !important; }
        html[data-theme="dark"] .section-title, html[data-theme="dark"] .report-section-title { color: #e2e8f0 !important; }

        /* View-card internals (manage_customers_motorcycles) */
        html[data-theme="dark"] .view-card .vc-section-title { color: #93c5fd !important; }
        html[data-theme="dark"] .view-card .view-value { color: #e2e8f0 !important; }
        html[data-theme="dark"] .view-card .view-label, html[data-theme="dark"] .vc-section-title { color: #94a3b8 !important; }
        html[data-theme="dark"] .view-card .view-item { border-bottom-color: rgba(255, 255, 255, 0.09) !important; }

        /* ab-* extras (accepted_bookings) */
        html[data-theme="dark"] .ab-count-badge { background: rgba(255, 255, 255, 0.05) !important; border-color: rgba(255, 255, 255, 0.1) !important; color: #e2e8f0 !important; }
        html[data-theme="dark"] .ab-confirmed-box { color: #34d399 !important; }
        html[data-theme="dark"] .ab-payment-status { color: #34d399 !important; }

        /* rb-* family (rejected_bookings) - mirrors ab-* */
        html[data-theme="dark"] .rb-list-item { background: #1a2b4f !important; box-shadow: none !important; }
        html[data-theme="dark"] .rb-list-item:hover { border-color: #3b82f6 !important; }
        html[data-theme="dark"] .rb-list-item.active { background: rgba(239, 68, 68, 0.1) !important; }
        html[data-theme="dark"] .rb-back-btn, html[data-theme="dark"] .rb-count-badge,
        html[data-theme="dark"] .rb-action-btn { background: rgba(255, 255, 255, 0.05) !important; border-color: rgba(255, 255, 255, 0.1) !important; color: #e2e8f0 !important; }
        html[data-theme="dark"] .rb-back-btn:hover, html[data-theme="dark"] .rb-action-btn:hover { background: rgba(255, 255, 255, 0.1) !important; color: #e2e8f0 !important; }
        html[data-theme="dark"] .rb-action-btn.manage { color: #FDE047 !important; }
        html[data-theme="dark"] .rb-status-badge { background: rgba(239, 68, 68, 0.15) !important; color: #f87171 !important; border-color: rgba(239, 68, 68, 0.35) !important; }
        html[data-theme="dark"] .rb-page-title, html[data-theme="dark"] .rb-detail-id,
        html[data-theme="dark"] .rb-list-customer, html[data-theme="dark"] .rb-list-id { color: #e2e8f0 !important; }
        html[data-theme="dark"] .rb-detail-label { color: #94a3b8 !important; }
        html[data-theme="dark"] .rb-detail-value { color: #cbd5e1 !important; }
        html[data-theme="dark"] .rb-detail-block { color: #e2e8f0 !important; }
        html[data-theme="dark"] .rb-detail-card, html[data-theme="dark"] .rb-detail-section,
        html[data-theme="dark"] .rb-main-card { background: #1a2b4f !important; }
        html[data-theme="dark"] .rb-section-title, html[data-theme="dark"] .rb-list-date,
        html[data-theme="dark"] .rb-page-subtitle { color: #94a3b8 !important; }
        html[data-theme="dark"] .rb-empty-state { color: #94a3b8 !important; }
        html[data-theme="dark"] .rb-error { color: #f87171 !important; }
        html[data-theme="dark"] .rb-rejection-card { background: rgba(239, 68, 68, 0.07) !important; }
        html[data-theme="dark"] .rb-rejection-title, html[data-theme="dark"] .rb-rejection-title i,
        html[data-theme="dark"] .rb-rejection-status, html[data-theme="dark"] .rb-rejection-value.lg,
        html[data-theme="dark"] .rb-rejected-box { color: #f87171 !important; }
        html[data-theme="dark"] .rb-rejection-label { color: #f87171 !important; }

        /* Motorcycle photos now have transparent backgrounds - render normally in dark mode */
        html[data-theme="dark"] .moto-thumb, html[data-theme="dark"] .hs-moto-thumb,
        html[data-theme="dark"] .view-moto-image img, html[data-theme="dark"] .edit-moto-preview,
        html[data-theme="dark"] .model-picker-thumb, html[data-theme="dark"] .model-picker-option img {
            mix-blend-mode: normal !important;
        }

        /* ===== Light mode sidebar - light surface ===== */
        html[data-theme="light"] .sidebar {
            background: #ffffff !important;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.06) !important;
            border-right: 1px solid #e2e8f0 !important;
        }
        html[data-theme="light"] .sidebar-brand,
        html[data-theme="light"] .sidebar-brand span { color: #0f172a !important; }
        html[data-theme="light"] .sidebar-collapse-btn { background: rgba(0, 0, 0, 0.05) !important; border-color: rgba(0, 0, 0, 0.12) !important; color: #334155 !important; }
        html[data-theme="light"] .sidebar-collapse-btn:hover { background: rgba(0, 0, 0, 0.09) !important; }
        html[data-theme="light"] .menu-title { color: #94a3b8 !important; background: #ffffff !important; }
        html[data-theme="light"] .menu-item { color: #334155 !important; }
        html[data-theme="light"] .menu-item:hover { background: rgba(0, 0, 0, 0.05) !important; color: #0f172a !important; }
        html[data-theme="light"] .menu-item.active { background: #FACC15 !important; color: #111827 !important; }
        html[data-theme="light"] .submenu-item { color: #475569 !important; }
        html[data-theme="light"] .submenu-item:hover { background: rgba(0, 0, 0, 0.05) !important; color: #0f172a !important; }
        html[data-theme="light"] .sidebar-footer { background: #f8fafc !important; border-top-color: #e2e8f0 !important; }
        html[data-theme="light"] .sidebar-footer-text { color: #334155 !important; }
        html[data-theme="light"] .sidebar-footer-copyright { color: #94a3b8 !important; }
        html[data-theme="light"] .sidebar-menu::-webkit-scrollbar-thumb { background: rgba(0, 0, 0, 0.15); }
        html[data-theme="light"] .menu-item i,
        html[data-theme="light"] .menu-item svg { color: #64748b !important; }
        html[data-theme="light"] .menu-item:hover i,
        html[data-theme="light"] .menu-item:hover svg { color: #d97706 !important; }
        html[data-theme="light"] .menu-item.active i,
        html[data-theme="light"] .menu-item.active svg { color: #111827 !important; }
        html[data-theme="light"] .menu-parent.child-active i,
        html[data-theme="light"] .menu-parent.child-active svg { color: #d97706 !important; }
        html[data-theme="light"] .sidebar-brand i,
        html[data-theme="light"] .sidebar-brand svg { filter: none !important; }
        html[data-theme="light"] .menu-title::after { background: rgba(15, 23, 42, 0.08) !important; }
        html[data-theme="light"] .menu-subtitle::after { background: rgba(15, 23, 42, 0.07) !important; }
        html[data-theme="light"] .menu-section + .menu-section { border-top-color: rgba(15, 23, 42, 0.06) !important; }

        /* Theme switch crossfade duration (one synchronous global update, view-transition) */
        ::view-transition-old(root), ::view-transition-new(root) { animation-duration: 180ms; }
    </style>
    <script>
        function confirmLogout() {
            Swal.fire({
                title: 'Logout?',
                text: 'You will be logged out.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, Logout',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'logout.php';
                }
            });
        }
    </script>
</head>
<body>
    <!-- Animated Background -->
    <div class="bg-animation">
        <div class="circle"></div>
        <div class="circle"></div>
        <div class="circle"></div>
        <div class="circle"></div>
        <div class="circle"></div>
    </div>

    <!-- Floating Tools Icons (like index.php) -->
    <div class="floating-tools">
        <i class="bi bi-tools"></i>
        <i class="bi bi-wrench"></i>
        <i class="bi bi-gear"></i>
        <i class="bi bi-car-front"></i>
        <i class="bi bi-speedometer2"></i>
        <i class="bi bi-people-fill"></i>
        <i class="bi bi-calendar-check"></i>
        <i class="bi bi-clipboard-check"></i>
    </div>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="dashboard_admin.php" class="sidebar-brand">
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

        <nav class="sidebar-menu">
            <?php
                $bookingsActive = in_array($currentPage, ['manage_bookings', 'bookings_status'], true);
                $emergencyActive = in_array($currentPage, ['admin_emergency_requests', 'emergency_status'], true);
            ?>
            <div class="menu-section">
                <div class="menu-title">Main Menu</div>
                <a href="dashboard_admin.php" class="menu-item <?= $currentPage == 'dashboard_admin' ? 'active' : '' ?>">
                    <i data-lucide="layout-dashboard"></i>
                    <span>Dashboard</span>
                </a>

                <button type="button" class="menu-item menu-parent <?= $bookingsActive ? 'child-active open' : '' ?>" data-submenu="submenuBookings" data-href="manage_bookings.php">
                    <i data-lucide="calendar-check"></i>
                    <span>Bookings</span>
                    <?php if ($pendingCount > 0): ?>
                        <span class="menu-badge"><?= $pendingCount ?></span>
                    <?php endif; ?>
                    <i data-lucide="chevron-down" class="menu-caret"></i>
                </button>
                <div class="submenu <?= $bookingsActive ? 'open' : '' ?>" id="submenuBookings">
                    <a href="manage_bookings.php" class="submenu-item <?= $currentPage == 'manage_bookings' ? 'active' : '' ?>">
                        <span>Bookings</span>
                    </a>
                    <a href="bookings_status.php" class="submenu-item <?= $currentPage == 'bookings_status' ? 'active' : '' ?>">
                        <span>Bookings Status</span>
                    </a>
                </div>

                <button type="button" class="menu-item menu-parent <?= $emergencyActive ? 'child-active open' : '' ?>" data-submenu="submenuEmergency" data-href="admin_emergency_requests.php">
                    <i data-lucide="siren"></i>
                    <span>Emergency</span>
                    <?php if ($emergencyCount > 0): ?>
                        <span class="menu-badge"><?= $emergencyCount ?></span>
                    <?php endif; ?>
                    <i data-lucide="chevron-down" class="menu-caret"></i>
                </button>
                <div class="submenu <?= $emergencyActive ? 'open' : '' ?>" id="submenuEmergency">
                    <a href="admin_emergency_requests.php" class="submenu-item <?= $currentPage == 'admin_emergency_requests' ? 'active' : '' ?>">
                        <span>Emergency</span>
                    </a>
                    <a href="emergency_status.php" class="submenu-item <?= $currentPage == 'emergency_status' ? 'active' : '' ?>">
                        <span>Emergency Status</span>
                    </a>
                </div>
            </div>

            <div class="menu-section">

                <div class="menu-subtitle">Fleet &amp; Customers</div>
                <a href="manage_customers_motorcycles.php" class="menu-item <?= $currentPage == 'manage_customers_motorcycles' ? 'active' : '' ?>">
                    <i data-lucide="users"></i>
                    <span>Customers</span>
                </a>
                <a href="manage_motorcycles.php" class="menu-item <?= $currentPage == 'manage_motorcycles' ? 'active' : '' ?>">
                    <i data-lucide="bike"></i>
                    <span>Motorcycles</span>
                </a>
                <a href="admin_health_scores.php" class="menu-item <?= $currentPage == 'admin_health_scores' ? 'active' : '' ?>">
                    <i data-lucide="heart-pulse"></i>
                    <span>Health Scores</span>
                </a>
                <a href="maintenance_management.php" class="menu-item <?= $currentPage == 'maintenance_management' ? 'active' : '' ?>">
                    <i data-lucide="clipboard-list"></i>
                    <span>Maintenance Management</span>
                </a>
                <a href="admin_maintenance_history.php" class="menu-item <?= $currentPage == 'admin_maintenance_history' ? 'active' : '' ?>">
                    <i data-lucide="history"></i>
                    <span>Maintenance History</span>
                </a>

                <div class="menu-subtitle">Operations &amp; Staff</div>
                <a href="manage_mechanics.php" class="menu-item <?= $currentPage == 'manage_mechanics' ? 'active' : '' ?>">
                    <i data-lucide="wrench"></i>
                    <span>Mechanics</span>
                </a>
                <a href="mechanic_assignment.php" class="menu-item <?= $currentPage == 'mechanic_assignment' ? 'active' : '' ?>">
                    <i data-lucide="user-check"></i>
                    <span>Assignments</span>
                </a>
                <a href="admin_availability.php" class="menu-item <?= $currentPage == 'admin_availability' ? 'active' : '' ?>">
                    <i data-lucide="calendar-days"></i>
                    <span>Availability</span>
                </a>

                <div class="menu-subtitle">Catalog &amp; Services</div>
                <a href="services.php" class="menu-item <?= $currentPage == 'services' ? 'active' : '' ?>">
                    <i data-lucide="settings"></i>
                    <span>Services</span>
                </a>
                <a href="admin_service_packages.php" class="menu-item <?= $currentPage == 'admin_service_packages' ? 'active' : '' ?>">
                    <i data-lucide="package"></i>
                    <span>Service Packages</span>
                </a>
                <a href="admin_warranty.php" class="menu-item <?= $currentPage == 'admin_warranty' ? 'active' : '' ?>">
                    <i data-lucide="shield-check"></i>
                    <span>Warranty</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-title">Communication</div>
                <a href="sms_history.php" class="menu-item <?= $currentPage == 'sms_history' ? 'active' : '' ?>">
                    <i data-lucide="message-square"></i>
                    <span>SMS History</span>
                </a>
            </div>
            <div class="menu-section">
                <div class="menu-title">Account</div>
                <a href="admin_profile.php" class="menu-item <?= $currentPage == 'admin_profile' ? 'active' : '' ?>">
                    <i data-lucide="user-cog"></i>
                    <span>Profile Settings</span>
                </a>
            </div>
        </nav>

    <script>
        (function () {
            const sidebar = document.getElementById('sidebar');
            const sidebarMenu = sidebar ? sidebar.querySelector('.sidebar-menu') : null;
            const savedScrollPosition = localStorage.getItem('adminSidebarScrollPosition');
            if (sidebarMenu && savedScrollPosition) {
                sidebarMenu.scrollTop = parseInt(savedScrollPosition);
            }
        })();
    </script>

    <div class="sidebar-footer">
        <div class="sidebar-footer-text">
            <i data-lucide="motorbike" style="width: 20px; height: 20px;"></i>
            <span style="display:flex;flex-direction:column;line-height:1.1;">Mindanao Eversure <small style="font-size:.55rem;letter-spacing:.12em;color:#FACC15;">MOTORCYCLE SERVICE</small></span>
        </div>
        <div class="sidebar-footer-copyright">
            © <?= date('Y') ?> All rights reserved
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const collapseBtn = document.getElementById('sidebarCollapseBtn');
            if (collapseBtn) {
                collapseBtn.addEventListener('click', function () {
                    document.body.classList.toggle('collapsed');
                    const icon = collapseBtn.querySelector('i');
                    if (icon) {
                        if (document.body.classList.contains('collapsed')) {
                            icon.classList.remove('bi-chevron-left');
                            icon.classList.add('bi-chevron-right');
                        } else {
                            icon.classList.remove('bi-chevron-right');
                            icon.classList.add('bi-chevron-left');
                        }
                    }
                });
            }

            // Collapsible parent menus
            document.querySelectorAll('.menu-parent').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    // When sidebar is collapsed, navigate to the first child page
                    if (document.body.classList.contains('collapsed')) {
                        const href = btn.getAttribute('data-href');
                        if (href) window.location.href = href;
                        return;
                    }
                    const submenu = document.getElementById(btn.getAttribute('data-submenu'));
                    if (submenu) {
                        submenu.classList.toggle('open');
                        btn.classList.toggle('open');
                    }
                });
            });

            // Admin notification bell
            const notifWrapper = document.getElementById('adminNotifWrapper');
            const notifBell = document.getElementById('adminNotifBell');
            const notifDropdown = document.getElementById('adminNotifDropdown');

            if (notifWrapper && notifBell && notifDropdown) {
                notifBell.addEventListener('click', function (e) {
                    e.stopPropagation();
                    notifDropdown.classList.toggle('show');

                    const badge = document.getElementById('adminNotifBadge');
                    if (badge) {
                        fetch('mark_admin_notifications_read.php', {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        }).then(function (res) {
                            if (res.ok) badge.style.display = 'none';
                        }).catch(function (err) {
                            console.error('mark admin notifications read failed', err);
                        });
                    }
                });

                document.addEventListener('click', function (e) {
                    if (!notifWrapper.contains(e.target)) {
                        notifDropdown.classList.remove('show');
                    }
                });
            }

            // Dark mode toggle
            const darkModeToggle = document.getElementById('darkModeToggle');
            if (darkModeToggle) {
                const dmIcon = darkModeToggle.querySelector('i');
                const syncDarkIcon = function () {
                    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                    dmIcon.classList.toggle('bi-moon-fill', !isDark);
                    dmIcon.classList.toggle('bi-sun-fill', isDark);
                };
                syncDarkIcon();
                darkModeToggle.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const root = document.documentElement;
                    const isDark = root.getAttribute('data-theme') !== 'dark';
                    const applyTheme = function () {
                        root.setAttribute('data-theme', isDark ? 'dark' : 'light');
                        localStorage.setItem('theme', isDark ? 'dark' : 'light');
                        syncDarkIcon();
                        document.dispatchEvent(new CustomEvent('adminThemeChanged', { detail: { dark: isDark } }));
                    };
                    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    if (!reduceMotion && document.startViewTransition) {
                        document.startViewTransition(applyTheme);
                    } else {
                        applyTheme();
                    }
                });
            }

            // Admin user dropdown (same behavior as customer)
            const adminUserWrap = document.getElementById('adminUserWrap');
            if (adminUserWrap) {
                adminUserWrap.addEventListener('click', function (e) {
                    e.stopPropagation();
                    adminUserWrap.classList.toggle('active');
                });

                document.addEventListener('click', function (e) {
                    if (!adminUserWrap.contains(e.target)) {
                        adminUserWrap.classList.remove('active');
                    }
                });
            }
        });
    </script>

    </aside>

    <!-- Main Content Wrapper -->
    <div class="main-wrapper">
        <!-- Top Header -->
        <header class="top-header">
            <!-- Floating Icons -->
            <div class="header-floating-icons">
                <i class="bi bi-gear"></i>
                <i class="bi bi-wrench"></i>
                <i class="bi bi-tools"></i>
                <i class="bi bi-car-front"></i>
                <i class="bi bi-speedometer2"></i>
                <i class="bi bi-calendar-check"></i>
            </div>
            <button class="btn-toggle-sidebar" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
            </button>
            <h1 class="page-title">
                <i class="bi bi-speedometer2"></i>
                <?= isset($pageTitle) ? $pageTitle : 'Admin' ?>
            </h1>
            <!-- Admin Notification Bell -->
            <div class="admin-notifications ms-auto" id="adminNotifWrapper">
                <div class="notification-bell" id="adminNotifBell" title="Notifications">
                    <i class="bi bi-bell-fill"></i>
                    <?php if ($adminNotificationCount > 0): ?>
                        <span class="badge" id="adminNotifBadge"><?= $adminNotificationCount ?></span>
                    <?php endif; ?>
                </div>
                <div class="notification-dropdown" id="adminNotifDropdown">
                    <div class="notification-dropdown-header">
                        <div class="nd-title">
                            Notifications
                            <?php if ($adminNotificationCount > 0): ?>
                                <span class="nd-count"><?= $adminNotificationCount ?> new</span>
                            <?php endif; ?>
                        </div>
                        <a href="dashboard_admin.php" class="nd-link">Dashboard</a>
                    </div>
                    <div class="notification-list">
                        <?php if (!empty($adminNotificationItems)): ?>
                            <?php foreach ($adminNotificationItems as $item): ?>
                                <a href="<?= htmlspecialchars($item['link']) ?>" class="notification-item <?= htmlspecialchars($item['type']) ?>">
                                    <div class="notification-icon"><i class="bi <?= $adminNotifIcons[$item['type']] ?? 'bi-bell' ?>"></i></div>
                                    <div class="notification-item-text">
                                        <div class="notification-item-title"><?= htmlspecialchars($item['title']) ?></div>
                                        <div class="notification-item-message"><?= htmlspecialchars($item['message']) ?></div>
                                        <?php if (!empty($item['time_label'])): ?>
                                            <div class="notification-item-time"><i class="bi bi-clock"></i><?= htmlspecialchars($item['time_label']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="notification-dot"></span>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="notification-empty">
                                <i class="bi bi-bell-slash"></i>
                                <div class="ne-title">All caught up</div>
                                <div class="ne-sub">No new notifications right now</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <!-- Dark Mode Toggle -->
            <div class="dark-mode-toggle" id="darkModeToggle" title="Toggle dark mode">
                <i class="bi bi-moon-fill"></i>
            </div>
            <div class="header-user-wrap d-none d-md-flex" id="adminUserWrap">
                <div class="user-avatar">
                    <?= strtoupper(substr($adminName, 0, 1)) ?>
                </div>
                <div class="user-info text-end">
                    <div class="user-name"><?= htmlspecialchars($adminName) ?></div>
                    <div class="user-role">Administrator</div>
                </div>
                <i class="bi bi-chevron-down top-bar-dropdown-btn"></i>
                <div class="header-user-dropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-header-name"><?= htmlspecialchars($adminName) ?></div>
                        <div class="dropdown-header-role">Administrator</div>
                    </div>
                    <a href="admin_profile.php" class="dropdown-item">
                        <i class="bi bi-person-gear"></i>
                        <span>Profile Settings</span>
                    </a>
                    <div class="dropdown-item danger" onclick="confirmLogout()">
                        <i class="bi bi-box-arrow-right"></i>
                        <span>Logout</span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="main-content">

<?php include __DIR__ . '/floating_toast.php'; ?>