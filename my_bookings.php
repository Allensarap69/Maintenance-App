<?php
session_start();
require 'db.php'; // Your database connection file
require_once 'feedback_helper.php';
require_once 'management_helper.php';

// 1. Security Check: Only logged-in customers can view this page.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header("Location: login.php");
    exit;
}

// 2. Get User ID & Set Notification Clear Marker
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Customer';

// Customer approves/declines a part the mechanic added
ensure_management_tables($pdo);
$parts_flash = '';
$parts_flash_type = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['part_decision'], $_POST['bp_id'])) {
    $r = customer_decide_part($pdo, (int)$_POST['bp_id'], $user_id, $_POST['part_decision']);
    header("Location: my_bookings.php?pmsg=" . urlencode($r['msg']) . "&ptype=" . ($r['ok'] ? 'success' : 'danger'));
    exit;
}
if (isset($_GET['pmsg'])) {
    $parts_flash = urldecode($_GET['pmsg']);
    $parts_flash_type = ($_GET['ptype'] ?? 'info') === 'success' ? 'success' : 'danger';
}

// Feedback submission flash (set by rate_service.php)
$toast_msg = '';
$toast_type = 'info';
if (isset($_SESSION['feedback_flash'])) {
    $toast_msg = $_SESSION['feedback_flash']['msg'];
    $toast_type = $_SESSION['feedback_flash']['type'];
    unset($_SESSION['feedback_flash']);
}

// === CRUCIAL LINES TO CLEAR DASHBOARD NOTIFICATION ===
$_SESSION['last_booking_view'] = time();
session_write_close(); // This fixes the notification timing issue
// ===================================================

// =========================================================================================
// 3. HELPER FUNCTIONS
// =========================================================================================

// Function to fetch and format service names from the JSON array
function get_service_names($pdo, $service_ids_json) {
    $ids = json_decode($service_ids_json, true);
    if (empty($ids) || !is_array($ids)) {
        return "N/A";
    }

    $placeholders = rtrim(str_repeat('?,', count($ids)), ',');
    try {
        $stmt = $pdo->prepare("SELECT service_name FROM services WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $names = array_column($stmt->fetchAll(), 'service_name');
        return implode(', ', $names);
    } catch (PDOException $e) {
        return "Error fetching services";
    }
}

/**
 * NEW FUNCTION: Fetches all mechanic names assigned to a single booking 
 * using the many-to-many junction table (booking_mechanics).
 */
/**
 * Fetch service and package names as an HTML list.
 */
function get_service_package_items($pdo, $service_ids_json, $package_ids_json = '[]') {
    $service_ids = json_decode($service_ids_json, true) ?: [];
    $package_ids = json_decode($package_ids_json, true) ?: [];
    $items = [];

    if (is_array($service_ids) && !empty($service_ids)) {
        $placeholders = rtrim(str_repeat('?,', count($service_ids)), ',');
        try {
            $stmt = $pdo->prepare("SELECT service_name AS name, price FROM services WHERE id IN ($placeholders)");
            $stmt->execute($service_ids);
            $items = array_merge($items, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) { error_log("Error fetching services: " . $e->getMessage()); }
    }

    if (is_array($package_ids) && !empty($package_ids)) {
        $placeholders = rtrim(str_repeat('?,', count($package_ids)), ',');
        try {
            $stmt = $pdo->prepare("SELECT package_name AS name, price FROM service_packages WHERE id IN ($placeholders)");
            $stmt->execute($package_ids);
            $items = array_merge($items, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) { error_log("Error fetching packages: " . $e->getMessage()); }
    }

    if (empty($items)) {
        return '<li class="service-package-item">N/A</li>';
    }

    $html = '';
    foreach ($items as $item) {
        $html .= '<li class="service-package-item">' . htmlspecialchars($item['name']) . '</li>';
    }
    return $html;
}

function get_all_mechanic_names($pdo, $booking_id) {
    try {
        $names = [];

        // Query the booking_mechanics table to get all assigned mechanic IDs
        $stmt_ids = $pdo->prepare("SELECT mechanic_id FROM booking_mechanics WHERE booking_id = ?");
        $stmt_ids->execute([$booking_id]);
        $mechanic_ids = array_column($stmt_ids->fetchAll(PDO::FETCH_ASSOC), 'mechanic_id');

        if (!empty($mechanic_ids)) {
            // Fetch the names for all collected IDs
            $placeholders = rtrim(str_repeat('?,', count($mechanic_ids)), ',');
            $stmt_names = $pdo->prepare("SELECT name FROM mechanics WHERE id IN ($placeholders)");
            $stmt_names->execute($mechanic_ids);
            $names = array_column($stmt_names->fetchAll(PDO::FETCH_ASSOC), 'name');
        }

        // Fallback: If no records (or no valid names), use the single mechanic_id from the bookings table
        if (empty($names)) {
            $stmt_single = $pdo->prepare("SELECT m.name FROM bookings b LEFT JOIN mechanics m ON b.mechanic_id = m.id WHERE b.id = ? AND b.mechanic_id IS NOT NULL");
            $stmt_single->execute([$booking_id]);
            $single_name = $stmt_single->fetchColumn();
            if ($single_name) {
                return htmlspecialchars($single_name);
            }
        }

        return !empty($names) ? htmlspecialchars(implode(', ', $names)) : 'N/A';

    } catch (PDOException $e) {
        error_log("Error fetching multiple mechanics for booking ID " . $booking_id . ": " . $e->getMessage());
        return "Error fetching mechanics";
    }
}

// Check if a final report/invoice exists for a booking
function report_exists($pdo, $booking_id) {
    try {
        // We look for any report (Confirmation Slip or Final Invoice)
        $stmt = $pdo->prepare("SELECT COUNT(id) FROM reports WHERE booking_id = ?");
        $stmt->execute([$booking_id]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// =========================================================================================
// 4. DATABASE QUERIES: Fetch bookings separated by status (p.transaction_ref ADDED)
// Note: We leave the LEFT JOIN mechanics m ON b.mechanic_id = m.id, but stop using m.name, 
// relying instead on the new helper function for the actual display name(s).
// =========================================================================================

// --- Base SQL Query for reuse ---
$base_query_with_payment = "
    SELECT
        b.id,
        b.service_ids,
        b.package_ids,
        b.schedule_date,
        b.schedule_start_time,
        b.schedule_end_time,
        b.total_price,
        b.status,
        b.created_at,
        v.brand,
        v.model,
        v.year_model,
        v.plate_number,
        v.image,
        m.name AS mechanic_name, -- Retained for legacy/simplicity, but not used in the final display
        p.payment_method,
        p.status AS payment_status,
        p.transaction_ref
    FROM bookings b
    LEFT JOIN motorcycles v ON b.vehicle_id = v.id
    LEFT JOIN mechanics m ON b.mechanic_id = m.id
    LEFT JOIN payments p ON b.id = p.booking_id
    WHERE b.user_id = ? AND b.status = ?
    ORDER BY b.schedule_date DESC, b.schedule_start_time DESC
";

// 4a. Accepted Bookings
$stmt_accepted = $pdo->prepare($base_query_with_payment);
$stmt_accepted->execute([$user_id, 'accepted']);
$accepted_bookings = $stmt_accepted->fetchAll();


// 4b. PENDING BOOKINGS - Correctly fetches initial submissions and deposit payments
$pending_query = "
    SELECT
        b.id,
        b.service_ids,
        b.package_ids,
        b.schedule_date,
        b.schedule_start_time,
        b.schedule_end_time,
        b.total_price,
        b.status,
        b.created_at,
        v.brand,
        v.model,
        v.year_model,
        v.plate_number,
        v.image,
        m.name AS mechanic_name, -- Retained for legacy/simplicity, but not used in the final display
        p.payment_method,
        p.status AS payment_status,
        p.transaction_ref
    FROM bookings b
    LEFT JOIN motorcycles v ON b.vehicle_id = v.id
    LEFT JOIN mechanics m ON b.mechanic_id = m.id
    LEFT JOIN payments p ON b.id = p.booking_id
    WHERE b.user_id = ? AND b.status IN ('pending', 'deposit_submitted')
    ORDER BY b.schedule_date DESC, b.schedule_start_time DESC
";

$stmt_pending = $pdo->prepare($pending_query);
$stmt_pending->execute([$user_id]);
$pending_bookings = $stmt_pending->fetchAll();


// 4c. Rejected Bookings
$stmt_rejected = $pdo->prepare($base_query_with_payment);
$stmt_rejected->execute([$user_id, 'rejected']);
$rejected_bookings = $stmt_rejected->fetchAll();

// 4d. Completed Bookings
$stmt_completed = $pdo->prepare($base_query_with_payment);
$stmt_completed->execute([$user_id, 'completed']);
$completed_bookings = $stmt_completed->fetchAll();

// Calculate total counts for tab badges
$pending_count = count($pending_bookings);
$accepted_count = count($accepted_bookings);
$rejected_count = count($rejected_bookings);
$completed_count = count($completed_bookings);

// Calculate total upcoming for sidebar badge
$totalUpcoming = $pending_count + $accepted_count;
$active_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <script>
        // Global theme state: apply saved theme before first paint to avoid a light-theme flash
        (function () {
            var t = localStorage.getItem('theme');
            if (localStorage.getItem('themeManual') !== '1' || (t !== 'dark' && t !== 'light')) {
                t = 'light';
                localStorage.setItem('theme', t);
            }
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="fonts.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* ============================================
           MODERN CUSTOMER PORTAL - INDEX.PHP STYLE
           ============================================ */
        :root {
            --primary-color: #0f172a;
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
            --accent-color: #3b82f6;
            --accent-gradient: linear-gradient(135deg, #3b82f6 0%, #FACC15 100%);
            --bg-light: #f8fafc;
            --bg-dark: #0f172a;
            --text-dark: #1e293b;
            --text-light: #64748b;
            --white: #ffffff;
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --success: #10b981;
            --warning: #FACC15;
            --info: #3b82f6;
            --danger: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-light);
            scroll-behavior: smooth;
            overflow-x: hidden;
            display: flex;
            min-height: 100vh;
        }

        /* ============================================
           HERO SECTION WITH FLOATING ICONS
           ============================================ */
        .hero-section {
            background: var(--primary-gradient);
            position: relative;
            padding: 40px 0 60px;
            overflow: hidden;
            color: white;
            margin-top: 0;
            border-radius: 20px;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 80%, rgba(59, 130, 246, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
            animation: pulse 8s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 0.9rem;
            margin-bottom: 20px;
            border: 1px solid var(--glass-border);
        }

        .hero-title {
            font-size: clamp(1.8rem, 4vw, 2.5rem);
            font-weight: 700;
            margin-bottom: 10px;
        }

        .hero-title span {
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-subtitle {
            font-size: 1.1rem;
            color: rgba(255, 255, 255, 0.8);
        }

        /* --- Floating Icons --- */
        .floating-icon {
            position: absolute;
            opacity: 0.1;
            animation: float 6s ease-in-out infinite;
            color: white;
        }

        .floating-icon:nth-child(1) { top: 20%; left: 10%; font-size: 3rem; animation-delay: 0s; }
        .floating-icon:nth-child(2) { top: 60%; right: 10%; font-size: 2.5rem; animation-delay: 2s; }
        .floating-icon:nth-child(3) { bottom: 20%; left: 15%; font-size: 2rem; animation-delay: 4s; }
        .floating-icon:nth-child(4) { top: 30%; right: 20%; font-size: 2.5rem; animation-delay: 1s; }
        .floating-icon:nth-child(5) { bottom: 30%; right: 15%; font-size: 2rem; animation-delay: 3s; }
        .floating-icon:nth-child(6) { top: 50%; left: 5%; font-size: 2rem; animation-delay: 5s; }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(5deg); }
        }

        /* ============================================
           NAVIGATION
           ============================================ */
        .navbar-custom {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.1);
            z-index: 1030;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            padding: 15px 0;
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .navbar-brand i {
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .btn-back {
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.3);
            color: white;
            font-weight: 500;
            padding: 10px 20px;
            border-radius: 50px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        }

        .btn-back:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: white;
            color: white;
        }

        /* ============================================
           BOOKING CARDS
           ============================================ */
        .booking-card {
            border-radius: 20px;
            border: none;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            margin-bottom: 25px;
            transition: transform 0.4s ease, box-shadow 0.4s ease, opacity 0.4s ease, visibility 0.4s ease, max-height 0.4s ease;
            overflow: hidden;
        }

        .booking-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12);
        }

        .booking-header {
            background: var(--primary-gradient);
            padding: 20px;
            font-weight: 600;
            color: white;
            border-bottom: none;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .booking-header.rejected { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); }
        .booking-header.accepted { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .booking-header.pending { background: var(--accent-gradient); }
        .booking-header.completed { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }

        .booking-status {
            font-size: 0.9rem;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 50px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .info-label { 
            font-weight: 600; 
            color: var(--text-light);
        }

        .card-body {
            padding: 25px;
        }

        /* ============================================
           TOP BAR STYLING
           ============================================ */
        .top-bar {
            background: var(--primary-gradient);
            padding: 15px 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1020;
            border-bottom: 2px solid rgba(250, 204, 21, 0.3);
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .content-area {
            padding: 30px;
            overflow-y: auto;
            flex: 1;
        }

        .top-bar-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: white;
        }

        .top-bar-user {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .top-bar-user-avatar {
            width: 40px;
            height: 40px;
            background: #1e3a5f;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }

        .top-bar-user-info {
            display: flex;
            flex-direction: column;
        }

        .top-bar-user-name {
            color: white;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .top-bar-user-role {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.75rem;
        }

        /* --- NEW STYLE FOR PAYMENT DETAILS --- */
        .payment-info-box {
            border-left: 4px solid var(--accent-color);
            padding: 15px 20px;
            margin-top: 20px;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.05) 0%, rgba(255, 255, 255, 0.1) 100%);
            border-radius: 0 12px 12px 0;
        }

        /* ============================================
           NAV TABS
           ============================================ */
        .nav-tabs {
            border-bottom: none;
            gap: 10px;
            margin-bottom: 30px;
        }

        .nav-tabs .nav-link {
            border: none;
            border-radius: 50px;
            padding: 12px 25px;
            font-weight: 500;
            color: var(--text-light);
            background: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        }

        .nav-tabs .nav-link:hover {
            color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .nav-tabs .nav-link.active {
            background: var(--primary-gradient);
            color: white;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.3);
        }

        .nav-tabs .nav-link.active.success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .nav-tabs .nav-link.active.warning { background: var(--accent-gradient); }
        .nav-tabs .nav-link.active.danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }

        /* ============================================
           ALERTS
           ============================================ */
        .alert-modern {
            border-radius: 16px;
            border: none;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }

        /* ============================================
           BUTTONS
           ============================================ */
        .btn-main {
            background: var(--primary-gradient);
            border: none;
            color: white;
            font-weight: 600;
            padding: 10px 25px;
            border-radius: 50px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.3);
        }

        .btn-main:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(15, 23, 42, 0.4);
            color: white;
        }

        .btn-accent {
            background: var(--accent-gradient);
            border: none;
            color: white;
            font-weight: 600;
            padding: 10px 25px;
            border-radius: 50px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4);
        }

        .btn-accent:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.5);
            color: white;
        }

        .btn-outline-custom {
            background: transparent;
            border: 2px solid #e2e8f0;
            color: var(--text-dark);
            font-weight: 500;
            padding: 8px 20px;
            border-radius: 50px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        }

        .btn-outline-custom:hover {
            background: var(--primary-gradient);
            border-color: transparent;
            color: white;
        }

        /* ============================================
           ANIMATIONS
           ============================================ */
        .fade-in-up {
            animation: fadeInUp 0.8s ease both;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 992px) {
            .top-bar {
                padding: 12px 20px;
            }

            .top-bar-title {
                font-size: 1.1rem;
            }

            .top-bar-user-info {
                display: none;
            }

            .content-area {
                padding: 20px;
            }
        }

        @media (max-width: 768px) {
            .hero-section { padding: 60px 0 80px; }
            .floating-icon { font-size: 1.5rem !important; }
            .nav-tabs .nav-link { padding: 10px 15px; font-size: 0.9rem; }
            .booking-header { flex-direction: column; gap: 10px; text-align: center; }
            .top-bar { padding: 10px 15px; }
            .top-bar-title { font-size: 1rem; }
            .content-area { padding: 15px; }
        }

        @media (max-width: 576px) {
            .top-bar { padding: 8px 12px; }
            .top-bar-title { font-size: 0.95rem; }
            .sidebar-toggle { padding: 6px 10px; font-size: 1.3rem; }
            .content-area { padding: 12px; }
        }
    
    /* --- Smaller, readable page content --- */
    .content-area {
        font-size: 0.9rem;
    }

    .content-area h5 {
        font-size: 1rem;
    }

    .content-area h6 {
        font-size: 0.85rem;
    }

    .content-area p,
    .content-area .info-label,
    .content-area .btn,
    .content-area .booking-status,
    .content-area .text-muted {
        font-size: 0.85rem;
    }

    .nav-tabs .nav-link {
        padding: 10px 20px;
        font-size: 0.85rem;
    }

    .booking-card .card-body {
        padding: 20px;
    }

    /* --- Empty state (no results) --- */
    .empty-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 3rem 1rem;
        text-align: center;
        color: var(--text-light);
    }

    .empty-state i {
        order: 2;
        font-size: 4rem;
        color: #cbd5e1;
        margin-top: 1rem;
        margin-bottom: 0;
        opacity: 0.6;
    }

    .empty-state p {
        order: 1;
        font-size: 1rem;
        margin: 0;
        color: var(--text-light);
    }

    .empty-state strong {
        color: var(--text-dark);
    }

    /* --- Compact booking cards --- */
    .booking-card {
        border-radius: 16px;
        margin-bottom: 15px;
    }

    .booking-header {
        padding: 10px 15px;
    }

    .booking-header h5 {
        font-size: 0.9rem;
    }

    .booking-status {
        padding: 4px 10px;
        font-size: 0.7rem;
    }

    .booking-card .card-body {
        padding: 15px;
    }

    .booking-card h6 {
        font-size: 0.8rem;
        margin-bottom: 0.5rem;
    }

    .booking-card p,
    .booking-card .info-label,
    .booking-card .text-muted,
    .booking-card .text-success,
    .booking-card .text-warning,
    .booking-card .fw-bold {
        font-size: 0.8rem;
    }

    .booking-card .btn {
        font-size: 0.75rem;
        padding: 5px 10px;
    }

    .booking-card .card-footer {
        padding: 10px 15px;
    }

    .booking-card .payment-info-box {
        padding: 8px 12px;
        margin-top: 10px;
    }
    /* --- Modern booking list items --- */
    .booking-list-item {
        background: #fff;
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
    }

    .booking-list-item:hover {
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        transform: translateY(-2px);
    }

    .booking-list-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 18px;
        border-bottom: 1px solid #f1f5f9;
        flex-wrap: wrap;
        gap: 10px;
    }

    .booking-list-header > div {
        min-width: 0;
    }

    .booking-number {
        background: #FACC15;
        color: #000000;
        font-weight: 700;
        font-size: 0.8rem;
        padding: 4px 10px;
        border-radius: 50px;
    }

    .booking-status-badge {
        font-size: 0.75rem;
        font-weight: 600;
        padding: 3px 8px;
        border-radius: 50px;
        border: 1px solid;
    }

    .booking-status-badge.status-pending { color: #EAB308; border-color: #EAB308; background: rgba(250, 204, 21, 0.08); }
    .booking-status-badge.status-accepted { color: var(--success); border-color: var(--success); background: rgba(16, 185, 129, 0.08); }
    .booking-status-badge.status-rejected { color: var(--danger); border-color: var(--danger); background: rgba(239, 68, 68, 0.08); }
    .booking-status-badge.status-completed { color: var(--info); border-color: var(--info); background: rgba(59, 130, 246, 0.08); }

    .booked-on {
        color: var(--text-light);
        font-size: 0.8rem;
        white-space: normal;
        word-break: break-word;
    }

    .booking-more {
        font-size: 1.2rem;
        line-height: 1;
    }

    .booking-list-body {
        padding: 18px;
    }

    .booking-section {
        height: 100%;
    }

    .section-title {
        color: var(--text-light);
        font-size: 0.65rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
    }

    .section-title i {
        color: var(--text-light);
        font-size: 1rem;
    }

    .service-package-list {
        margin: 0;
        padding-left: 1.1rem;
        color: var(--text-dark);
        font-size: 0.85rem;
        word-break: break-word;
    }

    .service-package-item {
        margin-bottom: 0.25rem;
    }

    .price-section {
        margin-bottom: 1rem;
    }

    .price-value {
        font-size: 1.4rem;
        font-weight: 700;
        word-break: break-word;
    }

    .payment-section {
        background: transparent;
        border-radius: 10px;
        padding: 12px;
    }

    .payment-pending-box {
        background: #eff6ff;
        color: #3b82f6;
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 0.85rem;
        font-weight: 500;
    }

    .booking-list-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 18px;
        background: #f8fafc;
        border-top: 1px solid #f1f5f9;
        flex-wrap: wrap;
        gap: 10px;
    }

    .footer-message {
        color: var(--text-light);
        font-size: 0.8rem;
        flex: 1;
        min-width: 0;
        word-break: break-word;
    }

    .btn-view-details {
        border: 1px solid var(--accent-color);
        color: var(--accent-color);
        background: transparent;
        padding: 5px 14px;
        border-radius: 50px;
        font-weight: 600;
        font-size: 0.8rem;
        text-decoration: none;
        transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
    }

    .btn-view-details:hover {
        background: var(--accent-color);
        color: #fff;
    }

    /* --- Modern filter tabs --- */
    .nav-tabs {
        border-bottom: none;
        gap: 10px;
        margin-bottom: 30px;
        flex-wrap: wrap;
    }

    .nav-tabs .nav-link {
        border: 1px solid #e2e8f0;
        border-radius: 50px;
        padding: 8px 18px;
        font-weight: 500;
        color: var(--text-light);
        background: #fff;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .nav-tabs .nav-link:hover {
        border-color: var(--accent-color);
        color: var(--accent-color);
    }

    .nav-tabs .nav-link.active {
        background: #fff;
        color: var(--accent-color);
        border-color: var(--accent-color);
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
    }

    @media (max-width: 768px) {
        .booking-list-header { padding: 12px 18px; }
        .booking-list-body { padding: 18px; }
        .booking-list-footer { padding: 12px 18px; }
        .section-title { font-size: 0.65rem; }
        .price-value { font-size: 1.4rem; }
        .booking-box-body { flex-direction: column; gap: 12px; }
        .booking-box-media {
            flex-basis: auto;
            margin: -16px -16px 0 -16px;
            padding: 12px 14px;
            border-right: none;
            border-bottom: 1px solid #e2e8f0;
            flex-direction: row;
            justify-content: flex-start;
            gap: 12px;
            text-align: left;
        }
        .booking-box-media img { width: 56px; height: 56px; }
        .tab-pane.view-list .booking-box-media { margin: -8px -8px 0 -8px; padding: 8px 12px; }
        .tab-pane.view-list .booking-box-media img { width: 44px; height: 44px; }
    }
    /* --- Box cards --- */
    .booking-box {
        background: #fff;
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        display: flex;
        flex-direction: column;
        height: 100%;
    }

    .booking-box:hover {
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        transform: translateY(-2px);
    }

    .booking-box-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 16px;
        border-bottom: 1px solid #f1f5f9;
        flex-wrap: wrap;
        gap: 8px;
    }

    .booking-box-header > span {
        min-width: 0;
    }

    .booking-box-body {
        padding: 16px;
        flex: 1;
        display: flex;
        gap: 20px;
    }

    /* --- Motorcycle media rail --- */
    .booking-box-media {
        flex: 0 0 118px;
        margin: -16px 0 -16px -16px;
        padding: 14px 10px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 8px;
        text-align: center;
        background: linear-gradient(160deg, #f8fafc 0%, #e8eef5 100%);
        border-right: 1px solid #e2e8f0;
    }

    .booking-box-media img {
        width: 92px;
        height: 92px;
        object-fit: contain;
        filter: drop-shadow(0 4px 8px rgba(15, 23, 42, 0.18));
        transition: transform 0.3s ease;
    }

    .booking-box:hover .booking-box-media img {
        transform: scale(1.06);
    }

    .booking-box-media-name {
        font-size: 0.68rem;
        font-weight: 600;
        color: var(--text-dark);
        line-height: 1.2;
    }

    .booking-box-media-plate {
        font-size: 0.6rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        color: #fff;
        background: #0f172a;
        border-radius: 6px;
        padding: 2px 8px;
        white-space: nowrap;
    }

    .booking-box-col {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    /* --- Compact list view --- */
    .tab-pane.view-list .booking-box {
        font-size: 0.7rem;
        border-radius: 12px;
    }
    .tab-pane.view-list .booking-box-header {
        padding: 8px 12px;
    }
    .tab-pane.view-list .booking-box-body {
        padding: 8px;
        gap: 0 8px;
    }
    .tab-pane.view-list .booking-box-section {
        margin-bottom: 2px;
    }
    .tab-pane.view-list .section-title {
        font-size: 0.55rem;
        margin-bottom: 0.2rem;
    }
    .tab-pane.view-list p,
    .tab-pane.view-list .service-package-list,
    .tab-pane.view-list .small,
    .tab-pane.view-list .payment-pending-box {
        font-size: 0.7rem;
    }
    .tab-pane.view-list .price-value {
        font-size: 0.95rem;
    }
    .tab-pane.view-list .booking-number,
    .tab-pane.view-list .booking-status-badge {
        font-size: 0.6rem;
        padding: 2px 6px;
    }
    .tab-pane.view-list .payment-section {
        background: transparent;
        padding: 0;
    }
    .tab-pane.view-list .booking-box-media {
        flex-basis: 72px;
        margin: -8px 0 -8px -8px;
        padding: 8px 6px;
        gap: 4px;
    }
    .tab-pane.view-list .booking-box-media img {
        width: 52px;
        height: 52px;
        filter: drop-shadow(0 2px 4px rgba(15, 23, 42, 0.15));
    }
    .tab-pane.view-list .booking-box-media-name,
    .tab-pane.view-list .booking-box-media-plate {
        display: none;
    }

    .booking-box-section {
        margin-bottom: 12px;
        break-inside: avoid;
    }

    .booking-box-section:last-child {
        margin-bottom: 0;
    }

    .booking-box-footer {
        padding: 12px 16px;
        background: #f8fafc;
        border-top: 1px solid #f1f5f9;
    }

    /* --- Box card compact text --- */
    .booking-box {
        font-size: 0.75rem;
    }

    .booking-box .section-title {
        font-size: 0.62rem;
        margin-bottom: 0.35rem;
    }

    .booking-box .service-package-list {
        font-size: 0.72rem;
    }

    .booking-box .price-value {
        font-size: 1.2rem;
    }

    .booking-box .small {
        font-size: 0.7rem;
    }

    .booking-box .booking-number {
        font-size: 0.72rem;
        padding: 3px 8px;
    }

    .booking-box .booking-status-badge {
        font-size: 0.68rem;
        padding: 2px 7px;
    }

    /* --- View toggle & list/grid styles --- */
    .view-toggle {
        display: flex;
        gap: 0.5rem;
        align-items: center;
    }
    .view-toggle .btn {
        padding: 6px 12px;
        border: 1px solid var(--border-color);
        background: #fff;
        color: var(--text-dark);
        border-radius: 8px;
        font-size: 0.85rem;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s, visibility 0.2s, max-height 0.2s;
    }
    .view-toggle .btn.active {
        background: var(--primary-color);
        color: #fff;
        border-color: var(--primary-color);
    }
    .view-toggle .btn:hover:not(.active) {
        background: #f1f5f9;
    }

    /* --- Parts approval --- */
    .parts-approval {
        border-top: 1px dashed #fde68a;
        background: #fffbeb;
        padding: 10px 16px;
    }
    .parts-approval-title {
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #b45309;
        margin-bottom: 6px;
    }
    .part-approve-row {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 0;
        border-bottom: 1px solid #fef3c7;
        font-size: 0.78rem;
        flex-wrap: wrap;
    }
    .part-approve-row:last-child { border-bottom: none; }
    .part-approve-row .pname { font-weight: 600; color: var(--text-dark); }
    .part-approve-row .pmeta { color: var(--text-light); font-size: 0.72rem; }
    .part-approve-row .btn-approve,
    .part-approve-row .btn-decline {
        border: none;
        border-radius: 50px;
        font-size: 0.72rem;
        font-weight: 600;
        padding: 4px 12px;
        cursor: pointer;
    }
    .part-approve-row .btn-approve { background: #10b981; color: #fff; }
    .part-approve-row .btn-approve:hover { background: #059669; }
    .part-approve-row .btn-decline { background: transparent; color: #dc2626; border: 1px solid #fecaca; }
    .part-approve-row .btn-decline:hover { background: #fef2f2; }
    .parts-approved-list { font-size: 0.74rem; color: var(--text-light); padding: 8px 16px; border-top: 1px dashed #e2e8f0; background: #f8fafc; }
    .parts-approved-list .bi-check-circle-fill { color: var(--success); }

    .tab-pane.view-list .booking-row > [class*="col-"] {
        width: 100% !important;
        max-width: 100% !important;
        flex: 0 0 100% !important;
    }
</style>
</head>
<body>

<?php include 'customer_sidebar.php'; ?>

<!-- Main Content Area -->
<div class="main-content">
    <!-- Top Bar -->
    <div class="top-bar">
        <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="bi bi-list"></i>
            </button>
            <h1 class="top-bar-title">My Bookings</h1>
        </div>
        <div class="top-bar-user" id="topBarUser">
            <div class="top-bar-user-avatar">
                <?= strtoupper(substr($username, 0, 1)) ?>
            </div>
            <div class="top-bar-user-info">
                <span class="top-bar-user-name"><?= htmlspecialchars($username) ?></span>
                <span class="top-bar-user-role">Customer Account</span>
            </div>
            <i class="bi bi-chevron-down top-bar-dropdown-btn"></i>
            <div class="top-bar-user-dropdown">
                <div class="dropdown-header">
                    <div class="dropdown-header-name"><?= htmlspecialchars($username) ?></div>
                    <div class="dropdown-header-role">Customer Account</div>
                </div>
                <a href="profile.php" class="dropdown-item">
                    <i class="bi bi-person-gear"></i>
                    <span>Profile Settings</span>
                </a>
                <div class="dropdown-item danger" onclick="confirmLogout()">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Content Area -->
    <div class="content-area">
<!-- Content Section -->
<div class="py-5">

    <?php if ($parts_flash): ?>
    <div class="alert alert-<?= $parts_flash_type ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($parts_flash) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <ul class="nav nav-tabs nav-fill mb-4" id="bookingTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab" aria-controls="pending" aria-selected="true">
                <i class="bi bi-hourglass-split me-1 text-warning"></i> Pending
                <span class="badge rounded-pill bg-warning text-dark"><?= $pending_count ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="accepted-tab" data-bs-toggle="tab" data-bs-target="#accepted" type="button" role="tab" aria-controls="accepted" aria-selected="false">
                <i class="bi bi-check-circle-fill me-1 text-success"></i> Accepted
                <span class="badge rounded-pill bg-success"><?= $accepted_count ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="rejected-tab" data-bs-toggle="tab" data-bs-target="#rejected" type="button" role="tab" aria-controls="rejected" aria-selected="false">
                <i class="bi bi-x-circle-fill me-1 text-danger"></i> Rejected
                <span class="badge rounded-pill bg-danger"><?= $rejected_count ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="completed-tab" data-bs-toggle="tab" data-bs-target="#completed" type="button" role="tab" aria-controls="completed" aria-selected="false">
                <i class="bi bi-clipboard-check-fill me-1 text-info"></i> Completed
                <span class="badge rounded-pill bg-info text-dark"><?= $completed_count ?></span>
            </button>
        </li>
        </ul>

    <div class="tab-content" id="bookingTabsContent">

        <div class="tab-pane fade show active view-list" id="pending" role="tabpanel" aria-labelledby="pending-tab">
            <div class="d-flex justify-content-end mb-3 view-toggle">
                <button class="btn btn-sm" data-view="grid" data-target="pending"><i class="bi bi-grid-3x3-gap-fill"></i> Grid</button>
                <button class="btn btn-sm active" data-view="list" data-target="pending"><i class="bi bi-list-ul"></i> List</button>
            </div>
            <div class="row booking-row">
                <?= generateBookingCards($pending_bookings, $pdo); ?>
            </div>
            <?php if (empty($pending_bookings)): ?>
                <div class="empty-state">
                    <i class="bi bi-hourglass-split"></i>
                    <p>You have no <strong>Pending</strong> requests.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="tab-pane fade view-list" id="accepted" role="tabpanel" aria-labelledby="accepted-tab">
            <div class="d-flex justify-content-end mb-3 view-toggle">
                <button class="btn btn-sm" data-view="grid" data-target="accepted"><i class="bi bi-grid-3x3-gap-fill"></i> Grid</button>
                <button class="btn btn-sm active" data-view="list" data-target="accepted"><i class="bi bi-list-ul"></i> List</button>
            </div>
            <div class="row booking-row">
                <?= generateBookingCards($accepted_bookings, $pdo); // Use helper function ?>
            </div>
            <?php if (empty($accepted_bookings)): ?>
                <div class="empty-state">
                    <i class="bi bi-check-circle"></i>
                    <p>You have no <strong>Accepted</strong> bookings.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="tab-pane fade view-list" id="rejected" role="tabpanel" aria-labelledby="rejected-tab">
            <div class="d-flex justify-content-end mb-3 view-toggle">
                <button class="btn btn-sm" data-view="grid" data-target="rejected"><i class="bi bi-grid-3x3-gap-fill"></i> Grid</button>
                <button class="btn btn-sm active" data-view="list" data-target="rejected"><i class="bi bi-list-ul"></i> List</button>
            </div>
            <div class="row booking-row">
                <?= generateBookingCards($rejected_bookings, $pdo); ?>
            </div>
            <?php if (empty($rejected_bookings)): ?>
                <div class="empty-state">
                    <i class="bi bi-x-circle"></i>
                    <p>You have no <strong>Rejected</strong> bookings.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="tab-pane fade view-list" id="completed" role="tabpanel" aria-labelledby="completed-tab">
            <div class="d-flex justify-content-end mb-3 view-toggle">
                <button class="btn btn-sm" data-view="grid" data-target="completed"><i class="bi bi-grid-3x3-gap-fill"></i> Grid</button>
                <button class="btn btn-sm active" data-view="list" data-target="completed"><i class="bi bi-list-ul"></i> List</button>
            </div>
            <div class="row booking-row">
                <?= generateBookingCards($completed_bookings, $pdo); ?>
            </div>
            <?php if (empty($completed_bookings)): ?>
                <div class="empty-state">
                    <i class="bi bi-clipboard-check"></i>
                    <p>You have no <strong>Completed</strong> bookings.</p>
                </div>
            <?php endif; ?>
        </div>

        </div>
</div>

<?php
// =========================================================================================
// 5. HELPER FUNCTION TO GENERATE CARD HTML (UPDATED TO CALL get_all_mechanic_names)
// =========================================================================================
function generateBookingCards($bookings, $pdo) {
    // Re-import the necessary functions (best practice for helper files)
    if (!function_exists('get_service_names')) {
        // Fallback or re-define if the function scope requires it
         function get_service_names($pdo, $service_ids_json) {
              $ids = json_decode($service_ids_json, true);
              if (empty($ids) || !is_array($ids)) { return "N/A"; }
              $placeholders = rtrim(str_repeat('?,', count($ids)), ',');
              try {
                  $stmt = $pdo->prepare("SELECT service_name FROM services WHERE id IN ($placeholders)");
                  $stmt->execute($ids);
                  $names = array_column($stmt->fetchAll(), 'service_name');
                  return implode(', ', $names);
              } catch (PDOException $e) { return "Error fetching services"; }
         }
    }

    // Check if report_exists is defined, if not, define the simplified version here:
    if (!function_exists('report_exists')) {
             function report_exists($pdo, $booking_id) {
                 try {
                     $stmt = $pdo->prepare("SELECT COUNT(id) FROM reports WHERE booking_id = ?");
                     $stmt->execute([$booking_id]);
                     return $stmt->fetchColumn() > 0;
                 } catch (PDOException $e) { return false; }
             }
    }

    // Check if get_all_mechanic_names is defined, if not, define the simplified version here:
    // ** NOTE: This is critical for the fix. It must be defined or available. **
    if (!function_exists('get_all_mechanic_names')) {
        // Since we cannot redefine it reliably here, we assume it's globally available
        // as defined at the top of the file.
    }


    ob_start(); // Start output buffering to capture HTML

    foreach ($bookings as $b):
        // --- CORRECT TIME RETRIEVAL LOGIC ---
        $start_time_ts = strtotime($b['schedule_start_time']);

        // Use the actual end time from the database
        if (!empty($b['schedule_end_time'])) {
            $end_time_ts = strtotime($b['schedule_end_time']);
        } else {
            // Fallback in case old records don't have end time
            $end_time_ts = strtotime('+1 hour', $start_time_ts);
        }

        $time_display = date('h:i A', $start_time_ts) . ' - ' . date('h:i A', $end_time_ts);
        // --- END CORRECT TIME RETRIEVAL LOGIC ---
        
        // Determine status class
        $status = strtolower($b['status']);

        // Custom class mapping for deposit_submitted to use the pending styling
        if ($status === 'deposit_submitted') {
            $status_class = 'status-pending';
            $status_display = 'Pending Verification'; // Display a more specific status
        } elseif ($status === 'accepted') {
            $status_class = 'status-accepted';
            $status_display = 'Accepted by Admin';
        } else {
            $status_class = 'status-' . $status;
            $status_display = ucfirst($status);
        }

        // --- NEW PAYMENT VARIABLES (INCLUDING transaction_ref) ---
        $payment_method = strtolower(htmlspecialchars($b['payment_method'] ?? 'N/A'));
        $payment_status = htmlspecialchars($b['payment_status'] ?? 'N/A');
        $transaction_ref = htmlspecialchars($b['transaction_ref'] ?? 'N/A');
        // --- END NEW PAYMENT VARIABLES ---

        // --- IMPROVED VEHICLE INFO LOGIC: Display what's available ---
        $vehicle_parts = [];
        if (!empty($b['year_model'])) { $vehicle_parts[] = htmlspecialchars($b['year_model']); }
        if (!empty($b['brand'])) { $vehicle_parts[] = htmlspecialchars($b['brand']); }
        if (!empty($b['model'])) { $vehicle_parts[] = htmlspecialchars($b['model']); }

        $vehicle_display = implode(' ', $vehicle_parts);

        // Append plate number if available
        if (!empty($b['plate_number'])) {
            $vehicle_display .= " (Plate: " . htmlspecialchars($b['plate_number']) . ")";
        }

        // Final check: if still empty (no vehicle data at all), show the 'missing' message.
        if (empty($vehicle_display)) {
            $vehicle_display = "N/A (Motorcycle Details Missing)";
        }
        // --- END IMPROVED VEHICLE INFO LOGIC ---

        // --- VEHICLE IMAGE: uploaded image → model illustration → generic fallback ---
        $modelImages = [
            'Click 125'  => 'click125.png',
            'Click 160'  => 'click160.png',
            'ADV 160'    => 'adv.png',
            'PCX 160'    => 'pcx.png',
            'XRM 125'    => 'xrm.png',
            'Mio i 125'  => 'mio.png',
            'NMAX'       => 'nmax.png',
            'Aerox'      => 'ea.png',
            'Sniper 155' => 'snip.png',
            'Raider'     => 'rai.png',
            'Smash 115'  => 'sma.png',
            'Bajaj'      => 'bad.png',
        ];
        $vehicle_img_src = !empty($b['image'])
            ? $b['image']
            : (isset($modelImages[$b['model'] ?? '']) ? $modelImages[$b['model']] . '?v=3' : 'MOTOR.jpg');
        // --- END VEHICLE IMAGE ---

        // Get status-specific footer message and button text
        $footer_message = '';
        $show_button = false;
        $button_text = '';
        $button_link = '#';
        
        // =================================================================================
        // *** FIX START: Use the new function to fetch all mechanic names ***
        // =================================================================================
        $booking_id = $b['id'];
        $all_mechanic_names = get_all_mechanic_names($pdo, $booking_id);

        $mechanic_display_html = '';
        // =================================================================================
        // *** FIX END ***
        // =================================================================================


        // Prepare mechanic display for all statuses
        $mechanic_label = ($status === 'accepted' || $status === 'completed') ? 'Assigned Mechanic(s):' : 'Preferred Mechanic(s):';
        if ($all_mechanic_names === 'N/A' || $all_mechanic_names === '') {
            if ($status === 'pending' || $status === 'deposit_submitted') {
                $mechanic_display_html = '<p class="mb-1"><span class="info-label">Assigned Mechanic:</span> <span class="text-warning fw-bold">Pending Assignment</span></p>';
            } else {
                $mechanic_display_html = '<p class="mb-1"><span class="info-label">Assigned Mechanic:</span> <span class="text-muted">N/A</span></p>';
            }
        } else {
            $mechanic_color = ($status === 'rejected') ? 'text-muted' : (($status === 'accepted' || $status === 'completed') ? 'text-success' : 'text-primary');
            $mechanic_display_html = '<p class="mb-1"><span class="info-label">' . $mechanic_label . '</span> <span class="' . $mechanic_color . ' fw-bold">' . $all_mechanic_names . '</span></p>';
        }

        if ($status === 'pending') {
            $footer_message = "Waiting for admin approval. Please check back later.";
        } elseif ($status === 'deposit_submitted') {
            $footer_message = "⏳ Deposit submitted. Awaiting admin verification before acceptance.";
        } elseif ($status === 'accepted') {
            $footer_message = "✅ Your booking is confirmed! Ready for your scheduled time.";
            $show_button = true;

            $report_available = report_exists($pdo, $b['id']);
            if ($report_available) {
                $button_text = 'View Confirmation Slip/Report';
                $button_link = 'print_receipt.php?booking_id=' . $b['id'];
            } else {
                $button_text = 'View Confirmation Slip (Report Pending)';
                $button_link = 'print_receipt.php?booking_id=' . $b['id'];
            }
        } elseif ($status === 'completed') {
            $footer_message = "✅ Service completed. Thank you for choosing us!";
            $show_button = true;

            $report_available = report_exists($pdo, $b['id']);
            if ($report_available) {
                $button_text = 'View Final Report/Invoice';
                $button_link = 'print_receipt.php?booking_id=' . $b['id'];
            } else {
                $button_text = 'View Confirmation Slip';
                $button_link = 'print_receipt.php?booking_id=' . $b['id'];
            }
        } elseif ($status === 'rejected') {
            $footer_message = "❌ This request was rejected. Please book a new time or contact us for assistance.";
        }
        // Price and payment display logic
        $has_payment = !empty($b['payment_method']);
        $payment_verified = in_array(strtolower($payment_status), ['verified', 'paid']);
        $show_price = !($status === 'deposit_submitted' && !$payment_verified);
        $price_display = $show_price ? '₱' . number_format($b['total_price'], 2) : 'N/A';
        $price_class = $show_price ? 'text-success' : 'text-muted';
        $booked_on = !empty($b['created_at']) ? date('M j, Y · h:i A', strtotime($b['created_at'])) : 'N/A';
        $booking_status_class = $status === 'deposit_submitted' ? 'pending' : $status;
?>
    <div class='col-xl-4 col-md-6 mb-4'>
        <div class='booking-box <?= $booking_status_class ?>'>
            <div class='booking-box-header'>
                <span class='booking-number'>Booking #<?= $b['id'] ?></span>
                <span class='booking-status-badge <?= $status_class ?>'><?= $status_display ?></span>
            </div>
            <div class='booking-box-body'>
                <div class='booking-box-media'>
                    <img src='<?= htmlspecialchars($vehicle_img_src) ?>' alt='<?= htmlspecialchars(trim(($b['brand'] ?? '') . ' ' . ($b['model'] ?? ''))) ?>' onerror="this.onerror=null;this.src='MOTOR.jpg';">
                    <?php $media_name = trim(($b['brand'] ?? '') . ' ' . ($b['model'] ?? '')); ?>
                    <?php if ($media_name !== ''): ?>
                        <div class='booking-box-media-name'><?= htmlspecialchars($media_name) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($b['plate_number'])): ?>
                        <div class='booking-box-media-plate'><?= htmlspecialchars($b['plate_number']) ?></div>
                    <?php endif; ?>
                </div>
                <div class='booking-box-col left-col'>
                    <div class='booking-box-section'>
                        <h6 class='section-title'><i class='bi bi-calendar-event me-2'></i>Appointment</h6>
                        <p class='mb-1'><span class='info-label'>Date:</span> <?= date('Y-m-d (D)', strtotime($b['schedule_date'])) ?></p>
                        <p class='mb-1'><span class='info-label'>Time:</span> <?= $time_display ?></p>
                        <p class='mb-1 small text-muted'>Booked <?= $booked_on ?></p>
                    </div>
                    <div class='booking-box-section'>
                        <h6 class='section-title'><i class='bi bi-car-front-fill me-2'></i>Vehicle & Services</h6>
                        <p class='mb-1 text-break'><span class='info-label'>Vehicle:</span> <?= $vehicle_display ?></p>
                        <ul class='service-package-list'>
                            <?= get_service_package_items($pdo, $b['service_ids'], $b['package_ids'] ?? '[]') ?>
                        </ul>
                    </div>
                </div>
                <div class='booking-box-col right-col'>
                    <div class='booking-box-section'>
                        <h6 class='section-title'><i class='bi bi-person-gear me-2'></i>Personnel</h6>
                        <?= $mechanic_display_html ?>
                    </div>
                    <div class='booking-box-section'>
                        <h6 class='section-title'><i class='bi bi-cash-stack me-2'></i>Estimated Price</h6>
                        <div class='price-value <?= $price_class ?>'><?= $price_display ?></div>
                    </div>
                    <?php if ($has_payment && ($payment_verified || $payment_method === 'cash')): ?>
                    <div class='booking-box-section payment-section'>
                        <h6 class='section-title'><i class='bi bi-credit-card me-2'></i>Payment</h6>
                        <p class='mb-1'><span class='info-label'>Method:</span> <span class='fw-bold'><?= ucfirst($payment_method) ?></span></p>
                        <?php if ($payment_method !== 'cash'): ?>
                        <p class='mb-1'><span class='info-label'>Status:</span> <span class='fw-bold <?php if ($payment_status === 'verified'): ?>text-success<?php elseif ($payment_status === 'pending'): ?>text-warning<?php else: ?>text-muted<?php endif; ?>'><?= ucfirst($payment_status) ?></span></p>
                        <?php endif; ?>
                        <?php if ($transaction_ref !== 'N/A'): ?>
                        <p class='mb-0'><span class='info-label'>Ref. No.:</span> <span class='fw-bold text-dark'><?= $transaction_ref ?></span></p>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($has_payment): ?>
                    <div class='booking-box-section payment-pending-box'>
                        <i class='bi bi-info-circle me-2 text-info'></i> Payment details will be available once verified.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
            // Parts added by the mechanic — customer approval for open bookings
            $job_parts = get_booking_parts($pdo, $b['id']);
            $pending_parts = array_values(array_filter($job_parts, fn($p) => $p['status'] === 'pending'));
            $approved_parts = array_values(array_filter($job_parts, fn($p) => $p['status'] === 'approved'));
            $parts_open = in_array($status, ['accepted', 'assigned', 'in_progress']);
            ?>
            <?php if (!empty($pending_parts)): ?>
            <div class='parts-approval'>
                <div class='parts-approval-title'><i class='bi bi-exclamation-circle me-1'></i>Parts added — your approval needed</div>
                <?php foreach ($pending_parts as $pp): ?>
                <div class='part-approve-row'>
                    <span class='pname flex-grow-1'><?= htmlspecialchars($pp['part_name']) ?></span>
                    <span class='pmeta'>×<?= (int)$pp['quantity'] ?> · ₱<?= number_format($pp['quantity'] * $pp['unit_price'], 2) ?></span>
                    <?php if ($parts_open): ?>
                    <form method='POST' class='d-inline-flex gap-1 m-0'>
                        <input type='hidden' name='bp_id' value='<?= (int)$pp['id'] ?>'>
                        <button type='submit' name='part_decision' value='approved' class='btn-approve'><i class='bi bi-check-lg me-1'></i>Approve</button>
                        <button type='submit' name='part_decision' value='declined' class='btn-decline'><i class='bi bi-x-lg me-1'></i>Decline</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <div class='pmeta mt-1'>Approved parts are added to your final bill. Declined parts won't be charged.</div>
            </div>
            <?php endif; ?>
            <?php if (!empty($approved_parts)): ?>
            <div class='parts-approved-list'>
                <i class='bi bi-check-circle-fill me-1'></i>Approved parts:
                <?= implode(', ', array_map(fn($p) => htmlspecialchars($p['part_name']) . ' ×' . (int)$p['quantity'], $approved_parts)) ?>
            </div>
            <?php endif; ?>
            <?php if ($status === 'completed'): ?>
            <?php $feedback = get_booking_feedback($pdo, $b['id']); ?>
            <div class='booking-box-footer'>
                <?php if ($feedback): ?>
                    <span class='footer-message'>
                        <i class='bi bi-star-fill' style='color:#EAB308;'></i>
                        <strong>Your rating:</strong>
                        <?= render_stars((int)($feedback['mechanic_rating'] ?? $feedback['service_rating'])) ?>
                        <?php if (!empty($feedback['comments'])): ?>
                            <span class='text-muted'> — <?= htmlspecialchars(mb_strimwidth($feedback['comments'], 0, 80, '…')) ?></span>
                        <?php endif; ?>
                    </span>
                    <a href='rate_service.php?type=booking&id=<?= (int)$b['id'] ?>' class='btn-view-details'><i class='bi bi-eye'></i> View</a>
                <?php else: ?>
                    <span class='footer-message'><i class='bi bi-chat-heart me-1'></i> How was your service? Let us know!</span>
                    <a href='rate_service.php?type=booking&id=<?= (int)$b['id'] ?>' class='btn-view-details'><i class='bi bi-star me-1'></i>Rate Service</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
<?php
    endforeach;

    return ob_get_clean(); // Return the captured HTML
}
?>

<?php include 'floating_toast.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Activate the completed tab when redirected back from the feedback form
    if (location.hash === '#completed') {
        var completedTab = document.getElementById('completed-tab');
        if (completedTab && window.bootstrap) new bootstrap.Tab(completedTab).show();
    }

    // Top bar user dropdown toggle
    document.addEventListener('DOMContentLoaded', function() {
        const topBarUser = document.getElementById('topBarUser');
        
        if (topBarUser) {
            topBarUser.addEventListener('click', function(e) {
                e.stopPropagation();
                this.classList.toggle('active');
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!topBarUser.contains(e.target)) {
                    topBarUser.classList.remove('active');
                }
            });
        }
    });

    // View toggle (list/grid) for booking tabs
    document.querySelectorAll('.view-toggle .btn[data-view]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const pane = document.getElementById(this.dataset.target);
            const siblings = this.parentElement.querySelectorAll('.btn[data-view]');
            siblings.forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            if (this.dataset.view === 'list') {
                pane.classList.add('view-list');
            } else {
                pane.classList.remove('view-list');
            }
        });
    });

    function confirmLogout() {
        Swal.fire({
            title: 'Ready to log out?',
            text: "You will need to log back in to access your dashboard.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, log out',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'logout.php';
            }
        });
    }
</script>
</div>
</body>
</html>