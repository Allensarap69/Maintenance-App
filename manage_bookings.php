<?php

session_start();
require 'db.php'; // Make sure this file correctly sets up your $pdo connection

// Include SMS functionality
require_once 'sms_helper.php';
require_once 'sms_config.php';
require_once 'SMSTemplates.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$pageTitle = 'Manage Bookings';
$msg = "";
$msg_type = "";

// Fetch message from URL if redirected
if (isset($_GET['msg'], $_GET['type'])) {
    $msg = urldecode($_GET['msg']);
    $msg_type = urldecode($_GET['type']);
}

// ----------------------------------------------------------------------
// --- Helper Functions ---
// ----------------------------------------------------------------------

function get_service_names($pdo, $service_ids_json, $package_ids_json = '[]') {
    $service_ids = json_decode($service_ids_json, true) ?: [];
    $package_ids = json_decode($package_ids_json, true) ?: [];
    $names = [];

    if (!empty($service_ids) && is_array($service_ids)) {
        $placeholders = rtrim(str_repeat('?,', count($service_ids)), ',');
        try {
            $stmt = $pdo->prepare("SELECT service_name FROM services WHERE id IN ($placeholders)");
            $stmt->execute($service_ids);
            $names = array_merge($names, array_column($stmt->fetchAll(), 'service_name'));
        } catch (PDOException $e) {
            return "Error fetching services";
        }
    }

    if (!empty($package_ids) && is_array($package_ids)) {
        $placeholders = rtrim(str_repeat('?,', count($package_ids)), ',');
        try {
            $stmt = $pdo->prepare("SELECT package_name FROM service_packages WHERE id IN ($placeholders)");
            $stmt->execute($package_ids);
            $names = array_merge($names, array_column($stmt->fetchAll(), 'package_name'));
        } catch (PDOException $e) {
            return "Error fetching packages";
        }
    }

    if (empty($names)) {
        return "N/A";
    }
    return implode(', ', $names);
}

function get_all_mechanic_names($pdo, $booking_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT m.name 
            FROM mechanics m
            JOIN booking_mechanics bm ON m.id = bm.mechanic_id
            WHERE bm.booking_id = ?
            ORDER BY m.name
        ");
        $stmt->execute([$booking_id]);
        $names = array_column($stmt->fetchAll(), 'name');
        
        if (!empty($names)) {
            return htmlspecialchars(implode(', ', $names));
        }

        $stmt_fallback = $pdo->prepare("
            SELECT m.name 
            FROM mechanics m
            JOIN bookings b ON m.id = b.mechanic_id
            WHERE b.id = ? AND b.mechanic_id IS NOT NULL
        ");
        $stmt_fallback->execute([$booking_id]);
        $fallback_name = $stmt_fallback->fetchColumn();
        
        return $fallback_name ? htmlspecialchars($fallback_name) : 'Unassigned';
        
    } catch (PDOException $e) {
        error_log("Mechanic fetching error: " . $e->getMessage());
        return "Error fetching mechanics";
    }
}

function create_report_record($pdo, $booking_id, $report_type) {
    if ($report_type !== 'Confirmation Slip') return true; 

    try {
        $stmt = $pdo->prepare("SELECT id FROM reports WHERE booking_id = ? AND report_type = ?");
        $stmt->execute([$booking_id, $report_type]);
        if ($stmt->fetch()) {
            return true; 
        }

        $stmt = $pdo->prepare("SELECT total_price FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) { return false; }
        
        $final_amount = $booking['total_price'];
        $generated_by = $_SESSION['username'] ?? 'Admin System'; 
        $notes = 'Confirmation Slip Generated upon Appointment Acceptance';

        $sql = "INSERT INTO reports (booking_id, report_type, report_date, final_amount, detailed_notes, generated_by)
                 VALUES (?, ?, CURDATE(), ?, ?, ?)";
                 
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$booking_id, $report_type, $final_amount, $notes, $generated_by]);

    } catch (PDOException $e) {
        error_log("Report Creation Error: " . $e->getMessage());
        return false;
    }
}

function get_status_class($status) {
    $status = strtolower($status);
    $status_map = [
        'pending' => 'status-pending', 
        'unassigned' => 'status-pending',
        'deposit_submitted' => 'status-awaiting-deposit',
        'assigned' => 'status-assigned',
        'accepted' => 'status-approved',
        'rejected' => 'status-rejected', 
        'deposit_rejected' => 'status-rejected', 
        'completed' => 'status-completed',
        'cancelled' => 'status-cancelled'
    ];
    return $status_map[$status] ?? 'status-default';
}

function format_phone($phone) {
    if (empty($phone)) return '';
    $clean = preg_replace('/\D/', '', $phone);
    if (strlen($clean) === 11 && substr($clean, 0, 2) === '09') {
        return substr($clean, 0, 4) . ' ' . substr($clean, 4, 3) . ' ' . substr($clean, 7);
    }
    if (strlen($clean) === 12 && substr($clean, 0, 2) === '63') {
        return '+' . substr($clean, 0, 2) . ' ' . substr($clean, 2, 3) . ' ' . substr($clean, 5, 3) . ' ' . substr($clean, 8);
    }
    return $phone;
}

// ----------------------------------------------------------------------
// --- 1. ACCEPT APPOINTMENT Logic ---
// ----------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'accept' && isset($_GET['id'])) {
    $booking_id = $_GET['id'];
    $pdo->beginTransaction();

    try {
        $stmt_fetch = $pdo->prepare("
            SELECT b.id AS booking_id, b.user_id, b.vehicle_id, b.service_ids,
                   b.package_ids, b.mechanic_id, b.schedule_date, b.schedule_start_time,
                   u.phone as customer_phone, u.name as customer_name,
                   m.name as mechanic_name
            FROM bookings b 
            JOIN users u ON b.user_id = u.id 
            LEFT JOIN mechanics m ON b.mechanic_id = m.id 
            WHERE b.id = ?
        ");
        $stmt_fetch->execute([$booking_id]);
        $booking_data = $stmt_fetch->fetch(PDO::FETCH_ASSOC);

        if (!$booking_data) {
            throw new Exception("Booking not found.");
        }
        
        $mechanic_names_sms = get_all_mechanic_names($pdo, $booking_id);
        
        $stmt_ids = $pdo->prepare("SELECT mechanic_id FROM booking_mechanics WHERE booking_id = ?");
        $stmt_ids->execute([$booking_id]);
        $assigned_mechanic_ids = array_column($stmt_ids->fetchAll(), 'mechanic_id');
        
        $mechanic_update_success = true;
        if (!empty($assigned_mechanic_ids)) {
            $placeholders = rtrim(str_repeat('?,', count($assigned_mechanic_ids)), ',');
            $params = array_merge([$booking_id], $assigned_mechanic_ids);

            $stmt_mechanic = $pdo->prepare("UPDATE mechanics SET status = 'Busy', current_booking_id = ? WHERE id IN ($placeholders)");
            $mechanic_update_success = $stmt_mechanic->execute($params);
            
        } elseif (!empty($booking_data['mechanic_id'])) {
             $stmt_mechanic = $pdo->prepare("UPDATE mechanics SET status = 'Busy', current_booking_id = ? WHERE id = ?");
             $mechanic_update_success = $stmt_mechanic->execute([$booking_id, $booking_data['mechanic_id']]);
             $assigned_mechanic_ids = [$booking_data['mechanic_id']];
        }

        if (!$mechanic_update_success) {
            throw new Exception("Failed to update assigned mechanic statuses.");
        }

        $stmt_booking = $pdo->prepare("UPDATE bookings SET status = 'accepted' WHERE id = ? AND status IN ('pending', 'deposit_submitted')");
        $stmt_booking->execute([$booking_id]);

        if ($stmt_booking->rowCount() > 0) {
            $report_success = create_report_record($pdo, $booking_id, 'Confirmation Slip');
            $pdo->commit();

            // Send appointment confirmation SMS after commit (best-effort; does not fail the booking)
            $sms = new SMSHelper();
            $sms_sent = false;

            if (SMS_ENABLED && !empty($booking_data['customer_phone'])) {
                $date_formatted = date('M j, Y', strtotime($booking_data['schedule_date']));
                $time_formatted = date('g:i A', strtotime($booking_data['schedule_start_time']));
                $service_names = get_service_names($pdo, $booking_data['service_ids'], $booking_data['package_ids'] ?? '[]');

                $sms_message = SMSTemplates::appointmentConfirmation(
                    $booking_id,
                    $date_formatted,
                    $time_formatted,
                    $service_names
                );

                $sms_result = $sms->sendSMS(
                    $booking_data['customer_phone'],
                    $sms_message,
                    'APPOINTMENT_CONFIRMATION',
                    [
                        'user_id' => $booking_data['user_id'] ?? null,
                        'customer_id' => $booking_data['user_id'] ?? null,
                        'booking_id' => $booking_id,
                        'motorcycle_id' => $booking_data['vehicle_id'] ?? null,
                        'notification_key' => 'APPOINTMENT_CONFIRMATION_' . $booking_id
                    ]
                );
                $sms_sent = !empty($sms_result['success']) && empty($sms_result['error_code']);
            }

            $mechanic_count = count($assigned_mechanic_ids);
            $msg = "Appointment #{$booking_id} CONFIRMED! {$mechanic_count} Mechanic(s) set to BUSY.";
            if ($sms_sent) $msg .= " SMS sent.";
            $msg_type = "success";
        } else {
            $pdo->rollBack();
            $msg = "Confirmation failed. Booking might already be accepted.";
            $msg_type = "error";
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = "Database Error: " . $e->getMessage();
        $msg_type = "error";
    }

    header("Location: manage_bookings.php?msg=" . urlencode($msg) . "&type=" . urlencode($msg_type));
    exit;
} 

// ----------------------------------------------------------------------
// --- 2. Deposit Logic ---
// ----------------------------------------------------------------------
if (isset($_GET['action']) && in_array($_GET['action'], ['deposit_approved', 'deposit_rejected']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $booking_id = $_GET['id'];
    
    $pdo->beginTransaction();
    try {
        $stmt_payment_check = $pdo->prepare("SELECT id FROM payments WHERE booking_id = ? AND transaction_type = 'deposit'");
        $stmt_payment_check->execute([$booking_id]);
        $payment_id = $stmt_payment_check->fetchColumn();

        if (!$payment_id) {
            throw new Exception("Deposit payment record not found.");
        }

        if ($action === 'deposit_approved') {
            $stmt_booking = $pdo->prepare("UPDATE bookings SET status='pending', deposit_verified_at=NOW() WHERE id=? AND status='deposit_submitted'");
            $stmt_booking->execute([$booking_id]);
            
            $stmt_payment = $pdo->prepare("UPDATE payments SET status='verified' WHERE id=?");
            $stmt_payment->execute([$payment_id]);

            $msg = "Deposit for Booking #{$booking_id} APPROVED.";
            $msg_type = "success";
        } elseif ($action === 'deposit_rejected') {
            $stmt_booking = $pdo->prepare("UPDATE bookings SET status='deposit_rejected' WHERE id=? AND status='deposit_submitted'");
            $stmt_booking->execute([$booking_id]);

            $stmt_payment = $pdo->prepare("UPDATE payments SET status='failed' WHERE id=?");
            $stmt_payment->execute([$payment_id]);

            $msg = "Deposit for Booking #{$booking_id} REJECTED.";
            $msg_type = "error";
        }
        
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "Database Error: " . $e->getMessage();
        $msg_type = "error";
    }
    
    header("Location: manage_bookings.php?msg=" . urlencode($msg) . "&type=" . urlencode($msg_type));
    exit;
}

// ----------------------------------------------------------------------
// --- 3. Reject Logic ---
// ----------------------------------------------------------------------
if (isset($_GET['action'], $_GET['id'])) {
    $action = strtolower($_GET['action']);
    $id = $_GET['id'];
    
    if ($action == 'rejected') {
        $pdo->beginTransaction();
        try {
            $stmt_bm = $pdo->prepare("SELECT mechanic_id FROM booking_mechanics WHERE booking_id = ?");
            $stmt_bm->execute([$id]);
            $mech_ids = array_column($stmt_bm->fetchAll(PDO::FETCH_ASSOC), 'mechanic_id');
            $stmt_b = $pdo->prepare("SELECT mechanic_id FROM bookings WHERE id = ?");
            $stmt_b->execute([$id]);
            $single_mech = $stmt_b->fetchColumn();
            if ($single_mech) $mech_ids[] = $single_mech;
            $mech_ids = array_unique(array_filter($mech_ids));

            $stmt = $pdo->prepare("UPDATE bookings SET status=?, mechanic_id=NULL WHERE id=?");
            $stmt->execute([$action, $id]);

            foreach ($mech_ids as $mid) {
                $pdo->prepare("UPDATE mechanics SET current_booking_id = NULL WHERE id = ? AND current_booking_id = ?")->execute([$mid, $id]);

                $busy = $pdo->prepare("
                    SELECT
                        (SELECT COUNT(*) FROM emergency_service_requests WHERE assigned_mechanic_id = ? AND request_status IN ('assigned','accepted','in_progress')) +
                        (SELECT COUNT(*) FROM bookings WHERE mechanic_id = ? AND status IN ('assigned','accepted','in_progress')) +
                        (SELECT COUNT(*) FROM booking_mechanics bm JOIN bookings b ON b.id = bm.booking_id WHERE bm.mechanic_id = ? AND b.status IN ('assigned','accepted','in_progress')) +
                        (SELECT COUNT(*) FROM mechanics WHERE id = ? AND current_booking_id IS NOT NULL)
                ");
                $busy->execute([$mid, $mid, $mid, $mid]);
                if ((int)$busy->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE mechanics SET status = 'Available' WHERE id = ? AND status = 'Busy'")->execute([$mid]);
                }
            }

            $pdo->commit();
            $msg = "Booking #{$id} has been rejected.";
            $msg_type = "success";
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "Error: " . $e->getMessage();
            $msg_type = "error";
        }
        header("Location: manage_bookings.php?msg=" . urlencode($msg) . "&type=" . urlencode($msg_type));
        exit;
    }
}

// ----------------------------------------------------------------------
// --- Fetch Data ---
// ----------------------------------------------------------------------
$bookings = $pdo->query("SELECT 
    b.id, b.service_ids, b.package_ids, b.schedule_date, b.schedule_start_time, b.schedule_end_time, b.total_price, b.status, b.mechanic_id,
    p.amount AS deposit_amount, p.payment_method, p.receipt_path, p.status AS payment_status, p.transaction_ref,
    u.username, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
    v.brand, v.model, v.year_model, v.plate_number,
    m.name AS mechanic_name
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    LEFT JOIN motorcycles v ON b.vehicle_id = v.id 
    LEFT JOIN mechanics m ON b.mechanic_id = m.id
    LEFT JOIN payments p ON b.id = p.booking_id AND p.transaction_type = 'deposit'
    WHERE b.status NOT IN ('accepted', 'rejected', 'deposit_rejected', 'completed')
    ORDER BY 
        CASE b.status 
            WHEN 'deposit_submitted' THEN 1 
            WHEN 'pending' THEN 2 
            ELSE 4 
        END,
        b.schedule_date DESC, b.schedule_start_time DESC
")->fetchAll(PDO::FETCH_ASSOC);

$statusCounts = $pdo->query("SELECT LOWER(status) as status, COUNT(*) as c FROM bookings GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalBookings = array_sum($statusCounts);
$pendingCount = ($statusCounts['pending'] ?? 0) + ($statusCounts['unassigned'] ?? 0) + ($statusCounts['deposit_submitted'] ?? 0);
$acceptedCount = ($statusCounts['accepted'] ?? 0) + ($statusCounts['assigned'] ?? 0);
$completedCount = $statusCounts['completed'] ?? 0;

$serviceList = $pdo->query("SELECT service_name FROM services ORDER BY service_name ASC")->fetchAll(PDO::FETCH_COLUMN);

?>

<?php require 'admin_sidebar_template.php'; ?>

<!-- Google Fonts & Lucide -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<style>
    :root {
        --bg-dark: #F8FAFC;
        --card-bg: #ffffff;
        --card-border: #E5E7EB;
        --accent-orange: #FACC15;
        --accent-blue: #3b82f6;
        --accent-green: #10b981;
        --accent-red: #ef4444;
        --text-main: #111827;
        --text-muted: #6B7280;
    }

    body {
        background-color: #F8FAFC !important;
        font-family: 'Plus Jakarta Sans', sans-serif !important;
        color: var(--text-main) !important;
    }

    .top-header {
        position: fixed !important;
        top: 0;
        left: var(--sidebar-width);
        right: 0;
        z-index: 100;
        background: #ffffff !important;
        backdrop-filter: blur(12px);
        border-bottom: 1px solid #E5E7EB;
    }

    .main-content {
        padding-top: 90px !important;
        padding-left: 28px !important;
        padding-right: 28px !important;
        background: #F8FAFC !important;
        min-height: 100vh;
    }

    .manage-bookings-page {
        position: relative;
        max-width: 1440px;
        margin: 0 auto;
        width: 100%;
        padding-bottom: 3rem;
        z-index: 0;
    }


    /* Minimal Header */
    .mb-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .mb-page-title {
        font-size: 1.75rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: var(--text-main);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }

    .mb-page-title i {
        color: var(--accent-orange);
    }

    .mb-page-subtitle {
        font-size: 0.88rem;
        color: #94a3b8;
        margin-top: 0.2rem;
    }

    .mb-active-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        background: rgba(0, 0, 0, 0.03);
        border: 1px solid var(--card-border);
        border-radius: 99px;
        padding: 0.35rem 0.9rem;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-main);
    }

    .mb-active-badge .dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--accent-green);
        box-shadow: 0 0 8px var(--accent-green);
    }

    /* Stats Grid */
    .mb-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .mb-stat-card {
        background: var(--card-bg);
        border: 1px solid var(--card-border);
        border-radius: 14px;
        padding: 1rem;
        position: relative;
        overflow: hidden;
        transition: border-color 0.2s ease, transform 0.2s ease;
    }

    .mb-stat-card:hover {
        border-color: rgba(148, 163, 184, 0.7);
        transform: translateY(-2px);
    }

    .mb-stat-value {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--text-main);
        line-height: 1.2;
    }

    .mb-stat-label {
        font-size: 0.75rem;
        color: var(--text-muted);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    /* Search & Filter Bar */
    .mb-filter-bar {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        margin-bottom: 1.5rem;
        background: var(--card-bg);
        border: 1px solid var(--card-border);
        border-radius: 14px;
        padding: 0.75rem;
    }

    .mb-search {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex: 1;
        min-width: 200px;
        background: rgba(0, 0, 0, 0.04);
        border: 1px solid var(--card-border);
        border-radius: 8px;
        padding: 0.4rem 0.75rem;
        color: var(--text-main);
    }

    .mb-search input {
        border: none;
        background: transparent;
        outline: none;
        color: var(--text-main);
        font-weight: 500;
        font-size: 0.85rem;
        width: 100%;
    }

    .mb-search input::placeholder { color: var(--text-muted); }

    .mb-filter-bar select, .mb-filter-bar input[type="date"] {
        background: rgba(0, 0, 0, 0.04);
        border: 1px solid var(--card-border);
        border-radius: 8px;
        padding: 0.4rem 0.75rem;
        color: var(--text-main);
        outline: none;
        font-size: 0.82rem;
    }

    .mb-filter-btn, .mb-clear-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        border-radius: 8px;
        padding: 0.4rem 0.85rem;
        font-weight: 600;
        font-size: 0.82rem;
        cursor: pointer;
        transition: all 0.2s ease;
        border: none;
    }

    .mb-filter-btn {
        background: var(--accent-blue);
        color: #ffffff;
    }

    .mb-clear-btn {
        background: rgba(0, 0, 0, 0.05);
        color: var(--text-muted);
    }

    /* Minimal Cards Grid */
    .mb-booking-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 1.25rem;
    }

    /* Minimalist Booking Card Design */
    .mb-booking-card {
        background: var(--card-bg);
        border: 1px solid var(--card-border);
        border-top: 3px solid var(--accent-orange);
        border-radius: 14px;
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        transition: border-color 0.2s ease, transform 0.2s ease;
    }

    .mb-booking-card:hover {
        border-color: #3b82f6;
        border-top-color: var(--accent-orange);
        transform: translateY(-2px);
    }

    .mb-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.75rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid var(--card-border);
    }

    .mb-booking-id {
        font-size: 0.8rem;
        font-weight: 700;
        color: var(--accent-orange);
    }

    .mb-status-badge {
        padding: 0.25rem 0.65rem;
        border-radius: 99px;
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .status-pending { background: rgba(250, 204, 21, 0.12); color: #EAB308; }
    .status-awaiting-deposit { background: rgba(250, 204, 21, 0.12); color: #EAB308; }
    .status-assigned { background: rgba(59, 130, 246, 0.12); color: #1d4ed8; }
    .status-approved, .status-accepted { background: rgba(16, 185, 129, 0.12); color: #047857; }
    .status-rejected { background: rgba(239, 68, 68, 0.12); color: #b91c1c; }

    .mb-card-body {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .mb-customer-row {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 0.35rem;
    }

    .mb-avatar {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: rgba(245, 158, 11, 0.15);
        color: var(--accent-orange);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1rem;
    }

    .mb-customer-name {
        font-size: 1rem;
        font-weight: 700;
        color: #000000;
        line-height: 1.25;
    }

    .mb-customer-meta {
        font-size: 0.75rem;
        color: #000000;
        line-height: 1.35;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .mb-info-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        font-size: 0.8rem;
        margin-top: 0.35rem;
    }

    .mb-info-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        color: #000000 !important;
    }

    .mb-info-label {
        color: #000000 !important;
        font-size: 0.75rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }

    .mb-info-val {
        font-weight: 700;
        text-align: right;
        max-width: 180px;
        word-break: break-word;
        color: #000000 !important;
    }

    /* Minimal Payment Footer */
    .mb-payment-footer {
        margin-top: 0.75rem;
        padding-top: 0.5rem;
        border-top: 1px dashed var(--card-border);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .mb-payment-method {
        font-size: 0.75rem;
        font-weight: 700;
        color: #000000;
    }

    .mb-payment-amount {
        font-size: 0.95rem;
        font-weight: 800;
        color: var(--accent-green);
    }

    /* Minimal Actions */
    .mb-actions {
        display: flex;
        gap: 0.4rem;
        margin-top: 0.75rem;
    }

    .mb-action-btn {
        flex: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        padding: 0.25rem 0.45rem;
        border-radius: 4px;
        font-size: 0.7rem;
        font-weight: 700;
        text-decoration: none !important;
        transition: all 0.2s ease;
        border: 1px solid transparent;
        cursor: pointer;
        min-height: 26px;
        background: transparent;
    }

    .mb-action-btn.ghost {
        color: var(--text-main);
        border-color: rgba(0, 0, 0, 0.1);
    }

    .mb-action-btn.ghost:hover {
        background: rgba(0, 0, 0, 0.08);
        border-color: rgba(0, 0, 0, 0.15);
    }

    .mb-action-btn.success {
        color: #047857;
        border-color: rgba(16, 185, 129, 0.25);
    }

    .mb-action-btn.success:hover {
        background: var(--accent-green);
        border-color: var(--accent-green);
        color: #ffffff;
    }

    .mb-action-btn.danger {
        color: #b91c1c;
        border-color: rgba(239, 68, 68, 0.25);
    }

    .mb-action-btn.danger:hover {
        background: var(--accent-red);
        border-color: var(--accent-red);
        color: #ffffff;
    }

    .mb-action-btn.primary {
        color: #1d4ed8;
        border-color: rgba(59, 130, 246, 0.25);
    }

    .mb-action-btn.primary:hover {
        background: var(--accent-blue);
        border-color: var(--accent-blue);
        color: #ffffff;
    }

    /* Dynamic Refactored Modern Modal */
    .modal-backdrop.show {
        backdrop-filter: blur(8px);
        background: rgba(5, 8, 15, 0.75);
    }

    .modal-content.glass-modal {
        position: relative;
        background: var(--card-bg) !important;
        backdrop-filter: blur(24px);
        border: 1px solid var(--card-border) !important;
        border-radius: 16px !important;
        color: var(--text-main) !important;
        overflow: hidden;
    }
    .modal-content.glass-modal > * { position: relative; z-index: 1; }

    .modal-header.glass-modal-header {
        background: #ffffff !important;
        color: #111827;
        border-bottom: 2px solid #E5E7EB !important;
        padding: 1rem 1.25rem;
        position: relative;
    }

    .modal-header.glass-modal-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, #3b82f6 0%, #FACC15 100%);
    }

    .glass-modal .btn-close {
        filter: none;
        opacity: 0.6;
    }

    .glass-modal-body {
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .modal-detail-card {
        background: #f1f5f9;
        border: 1px solid var(--card-border);
        border-radius: 12px;
        padding: 0.85rem;
    }

    .modal-detail-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
    }

    .modal-detail-item {
        display: flex;
        flex-direction: column;
        gap: 0.1rem;
    }

    .modal-detail-label {
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--text-muted);
    }

    .modal-detail-val {
        font-size: 0.88rem;
        font-weight: 600;
        color: var(--text-main);
    }

    /* ===== Dark mode overrides ===== */
    html.dark-mode .mb-booking-card { box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4); }
    html.dark-mode .mb-booking-card:hover { border-color: #3b82f6; }

    html.dark-mode .mb-customer-name,
    html.dark-mode .mb-customer-meta,
    html.dark-mode .mb-info-item,
    html.dark-mode .mb-info-label,
    html.dark-mode .mb-info-val,
    html.dark-mode .mb-payment-method { color: #e2e8f0 !important; }

    html.dark-mode .mb-search,
    html.dark-mode .mb-filter-bar select,
    html.dark-mode .mb-filter-bar input[type="date"] {
        background: #16233f;
        border-color: #3b4d7d;
        color: #e2e8f0;
    }
    html.dark-mode .mb-search input { color: #e2e8f0; }
    html.dark-mode .mb-search input::placeholder { color: #64748b; }
    html.dark-mode .mb-search i { color: #94a3b8; }

    html.dark-mode .mb-active-badge { background: rgba(255, 255, 255, 0.05); }
    html.dark-mode .mb-clear-btn { background: rgba(255, 255, 255, 0.08); color: #cbd5e1; }
    html.dark-mode .mb-clear-btn:hover { background: rgba(255, 255, 255, 0.14); }

    html.dark-mode .mb-avatar { background: rgba(250, 204, 21, 0.15); color: #FACC15; }

    html.dark-mode .status-pending,
    html.dark-mode .status-awaiting-deposit { color: #FDE047; }
    html.dark-mode .status-assigned { color: #93c5fd; }
    html.dark-mode .status-approved,
    html.dark-mode .status-accepted { color: #34d399; }
    html.dark-mode .status-rejected { color: #f87171; }

    html.dark-mode .mb-action-btn.ghost { border-color: rgba(255, 255, 255, 0.15); }
    html.dark-mode .mb-action-btn.ghost:hover { background: rgba(255, 255, 255, 0.08); border-color: rgba(255, 255, 255, 0.25); }
    html.dark-mode .mb-action-btn.success { color: #34d399; }
    html.dark-mode .mb-action-btn.danger { color: #f87171; }
    html.dark-mode .mb-action-btn.primary { color: #60a5fa; }

    html.dark-mode .modal-header.glass-modal-header {
        background: #22335a !important;
        color: #e2e8f0;
        border-bottom-color: rgba(255, 255, 255, 0.09) !important;
    }
    html.dark-mode .modal-header.glass-modal-header .modal-title,
    html.dark-mode .modal-header.glass-modal-header h5 { color: #e2e8f0; }
    html.dark-mode .glass-modal .btn-close { filter: invert(1); opacity: 0.8; }
    html.dark-mode .modal-detail-card { background: #16233f; border-color: rgba(255, 255, 255, 0.09); }
</style>

<div class="manage-bookings-page">
    

    <!-- Alert Messages -->
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type == 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert" style="background: rgba(0,0,0,0.03); border: 1px solid var(--card-border); color: var(--text-main); border-radius: 10px; padding: 0.6rem 1rem; font-size: 0.85rem;">
            <i data-lucide="<?= $msg_type == 'success' ? 'check-circle' : 'alert-circle' ?>" style="width:16px; height:16px; vertical-align:middle; margin-right: 6px;"></i>
            <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="padding: 0.8rem;"></button>
        </div>
    <?php endif; ?>

    

  

    <!-- Main Grid Cards -->
    <div class="mb-booking-grid" id="bookingGrid">
        <?php if (empty($bookings)): ?>
            <div class="text-center w-100 py-4" style="grid-column: 1/-1;">
                <i data-lucide="inbox" style="width: 36px; height: 36px; color: #94a3b8; opacity: 0.5;"></i>
                <p class="mt-2" style="color: #94a3b8; font-size: 0.85rem;">No Active Bookings</p>
            </div>
        <?php else: ?>
            <?php foreach ($bookings as $b):
                $status = strtolower($b['status']);
                $status_class = get_status_class($status);
                $mechanic_name_list = get_all_mechanic_names($pdo, $b['id']);
                $display_status = ucfirst(str_replace('_', ' ', $status));

                $start_time = strtotime($b['schedule_start_time']);
                $end_time_display = (empty($b['schedule_end_time']) || $b['schedule_end_time'] == '00:00:00')
                    ? date('g:i A', strtotime('+1 hour', $start_time))
                    : date('g:i A', strtotime($b['schedule_end_time']));
                
                $time_range_display = date('g:i A', $start_time) . ' - ' . $end_time_display;
                $date_display = date('M d, Y', strtotime($b['schedule_date']));

                $services_raw = get_service_names($pdo, $b['service_ids'], $b['package_ids'] ?? '[]');
                $services_display = ($services_raw === 'N/A' || empty($services_raw)) ? 'N/A' : htmlspecialchars($services_raw);
                $customer_display_name = !empty($b['customer_name']) ? htmlspecialchars($b['customer_name']) : htmlspecialchars($b['username']);
                $customer_search = strtolower($customer_display_name . ' ' . ($b['customer_email'] ?? '') . ' ' . ($b['customer_phone'] ?? '') . ' ' . $b['id']);

                $vehicle_parts = array_filter([$b['year_model'] ?? '', $b['brand'] ?? '', $b['model'] ?? '']);
                $vehicle_name = implode(' ', $vehicle_parts);
            ?>
                <article class="mb-booking-card"
                         data-status="<?= $status ?>"
                         data-date="<?= htmlspecialchars($b['schedule_date']) ?>"
                         data-services="<?= htmlspecialchars(strtolower($services_raw)) ?>"
                         data-customer="<?= htmlspecialchars($customer_search) ?>"
                         data-booking-id="<?= $b['id'] ?>"
                         data-customer-name="<?= $customer_display_name ?>"
                         data-customer-email="<?= htmlspecialchars($b['customer_email'] ?? 'N/A') ?>"
                         data-customer-phone="<?= htmlspecialchars($b['customer_phone'] ?? 'N/A') ?>"
                         data-vehicle="<?= htmlspecialchars($vehicle_name ?: 'N/A') ?>"
                         data-plate="<?= htmlspecialchars($b['plate_number'] ?? 'N/A') ?>"
                         data-schedule-date="<?= $date_display ?>"
                         data-schedule-time="<?= $time_range_display ?>"
                         data-mechanic="<?= htmlspecialchars($mechanic_name_list) ?>"
                         data-payment-method="<?= strtoupper($b['payment_method'] ?? 'CASH') ?>"
                         data-total-price="₱<?= number_format($b['total_price'], 2) ?>"
                         data-status-display="<?= $display_status ?>"
                         data-status-class="<?= $status_class ?>">
                         
                    <div>
                        <div class="mb-card-header">
                            <span class="mb-booking-id">#<?= sprintf('%04d', $b['id']) ?></span>
                            <span class="mb-status-badge <?= $status_class ?>"><?= $display_status ?></span>
                        </div>

                        <div class="mb-card-body">
                            <!-- Customer Profile -->
                            <div class="mb-customer-row">
                                <div class="mb-avatar"><?= strtoupper(substr($customer_display_name, 0, 1)) ?></div>
                                <div style="min-width: 0;">
                                    <div class="mb-customer-name"><?= $customer_display_name ?></div>
                                    <div class="mb-customer-meta"><?= !empty($b['customer_phone']) ? format_phone($b['customer_phone']) : 'No Contact' ?></div>
                                    <?php if (!empty($b['customer_email'])): ?>
                                        <div class="mb-customer-meta" title="<?= htmlspecialchars($b['customer_email']) ?>"><?= htmlspecialchars($b['customer_email']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Concise Details List -->
                            <div class="mb-info-list">
                                <div class="mb-info-item">
                                    <span class="mb-info-label"><i data-lucide="calendar" style="width:12px;"></i> Date</span>
                                    <span class="mb-info-val"><?= $date_display ?></span>
                                </div>
                                <div class="mb-info-item">
                                    <span class="mb-info-label"><i data-lucide="clock" style="width:12px;"></i> Time</span>
                                    <span class="mb-info-val"><?= $time_range_display ?></span>
                                </div>
                                <div class="mb-info-item">
                                    <span class="mb-info-label"><i data-lucide="bike" style="width:12px;"></i> Vehicle</span>
                                    <span class="mb-info-val"><?= $vehicle_name ?: 'Not Specified' ?></span>
                                </div>
                                <div class="mb-info-item">
                                    <span class="mb-info-label"><i data-lucide="wrench" style="width:12px;"></i> Service</span>
                                    <span class="mb-info-val" title="<?= $services_display ?>"><?= $services_display ?></span>
                                </div>
                                <div class="mb-info-item">
                                    <span class="mb-info-label"><i data-lucide="user-check" style="width:12px;"></i> Staff</span>
                                    <span class="mb-info-val"><?= $mechanic_name_list ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <!-- Minimal Deposit Notice -->
                        <?php if ($b['payment_method'] === 'gcash' && $status === 'deposit_submitted'): ?>
                            <div style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.2); border-radius: 8px; padding: 0.4rem 0.6rem; margin-top: 0.5rem; font-size: 0.72rem;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span style="color:var(--accent-orange); font-weight:700;">Verify Deposit</span>
                                    <span style="font-weight:800;">₱<?= number_format($b['deposit_amount'] ?? 0, 2) ?></span>
                                </div>
                                <div class="d-flex gap-1 mt-1">
                                    <a href="?action=deposit_approved&id=<?= $b['id'] ?>" class="mb-action-btn success"><i data-lucide="check" style="width:12px;"></i> Approve</a>
                                    <a href="?action=deposit_rejected&id=<?= $b['id'] ?>" class="mb-action-btn danger"><i data-lucide="x" style="width:12px;"></i> Reject</a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Minimal Payment Row -->
                        <div class="mb-payment-footer">
                            <span class="mb-payment-method"><?= strtoupper($b['payment_method'] ?? 'CASH') ?></span>
                            <span class="mb-payment-amount">₱<?= number_format($b['total_price'], 2) ?></span>
                        </div>

                        <!-- Actions Bar -->
                        <div class="mb-actions">
                            <button type="button" class="mb-action-btn ghost" onclick="openViewModal(<?= $b['id'] ?>)"><i data-lucide="eye" style="width:12px;"></i> View</button>
                            
                            <?php if ($status == 'pending' || $status == 'assigned'): ?>
                                <?php if ($mechanic_name_list !== 'Unassigned'): ?>
                                    <a href="?action=accept&id=<?= $b['id'] ?>" onclick="return confirm('Confirm appointment acceptance?')" class="mb-action-btn success"><i data-lucide="check" style="width:12px;"></i> Accept</a>
                                <?php else: ?>
                                    <a href="mechanic_assignment.php" class="mb-action-btn primary"><i data-lucide="user-plus" style="width:12px;"></i> Assign</a>
                                <?php endif; ?>
                                <a href="?action=rejected&id=<?= $b['id'] ?>" onclick="return confirm('Are you sure you want to reject Booking #<?= sprintf('%04d', $b['id']) ?>?')" class="mb-action-btn danger"><i data-lucide="x" style="width:12px;"></i> Reject</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Dynamic Modal -->
<div class="modal fade" id="viewBookingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-modal">
            <div class="modal-header glass-modal-header d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <i data-lucide="file-text" style="color: var(--accent-orange); width: 18px; height: 18px;"></i>
                    <h6 class="m-0" style="font-weight: 700; color: #111827;" id="modalBookingId">Booking Details</h6>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span id="modalStatusBadge" class="mb-status-badge"></span>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>

            <div class="glass-modal-body" id="viewBookingBody">
                <!-- Customer Section -->
                <div class="modal-detail-card">
                    <div class="modal-detail-grid">
                        <div class="modal-detail-item">
                            <span class="modal-detail-label">Customer</span>
                            <span class="modal-detail-val" id="modalCustomerName">-</span>
                        </div>
                        <div class="modal-detail-item">
                            <span class="modal-detail-label">Phone</span>
                            <span class="modal-detail-val" id="modalCustomerPhone">-</span>
                        </div>
                        <div class="modal-detail-item" style="grid-column: span 2;">
                            <span class="modal-detail-label">Email</span>
                            <span class="modal-detail-val" id="modalCustomerEmail">-</span>
                        </div>
                    </div>
                </div>

                <!-- Vehicle & Schedule Grid -->
                <div class="modal-detail-card">
                    <div class="modal-detail-grid">
                        <div class="modal-detail-item">
                            <span class="modal-detail-label">Vehicle</span>
                            <span class="modal-detail-val" id="modalVehicle">-</span>
                        </div>
                        <div class="modal-detail-item">
                            <span class="modal-detail-label">Plate Number</span>
                            <span class="modal-detail-val" id="modalPlate">-</span>
                        </div>
                        <div class="modal-detail-item">
                            <span class="modal-detail-label">Date</span>
                            <span class="modal-detail-val" id="modalScheduleDate">-</span>
                        </div>
                        <div class="modal-detail-item">
                            <span class="modal-detail-label">Time Slot</span>
                            <span class="modal-detail-val" id="modalScheduleTime">-</span>
                        </div>
                    </div>
                </div>

                <!-- Services & Staff -->
                <div class="modal-detail-card">
                    <div class="modal-detail-grid">
                        <div class="modal-detail-item" style="grid-column: span 2;">
                            <span class="modal-detail-label">Requested Services</span>
                            <span class="modal-detail-val" id="modalServices">-</span>
                        </div>
                        <div class="modal-detail-item" style="grid-column: span 2;">
                            <span class="modal-detail-label">Assigned Mechanics</span>
                            <span class="modal-detail-val" id="modalMechanic">-</span>
                        </div>
                    </div>
                </div>

                <!-- Financial Section -->
                <div class="modal-detail-card" style="background: rgba(16, 185, 129, 0.05); border-color: rgba(16, 185, 129, 0.2);">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="modal-detail-label">Payment Method</span>
                            <div class="modal-detail-val" id="modalPaymentMethod" style="font-weight: 700; color: #047857;">-</div>
                        </div>
                        <div class="text-end">
                            <span class="modal-detail-label">Total Amount</span>
                            <div class="modal-detail-val" id="modalTotalPrice" style="font-size: 1.2rem; font-weight: 800; color: #047857;">-</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require 'admin_sidebar_footer.php'; ?>

<!-- Lucide Icons Script -->
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        lucide.createIcons();

        // Remove ?msg=&type= from URL so a reload doesn't re-show the alert
        const url = new URL(window.location.href);
        if (url.searchParams.has('msg')) {
            url.searchParams.delete('msg');
            url.searchParams.delete('type');
            window.history.replaceState({}, '', url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : ''));
        }

        const searchInput = document.getElementById('bookingSearch');
        const statusSelect = document.getElementById('statusFilter');
        const dateInput = document.getElementById('dateFilter');
        const serviceSelect = document.getElementById('serviceFilter');
        const applyBtn = document.getElementById('applyFilters');
        const clearBtn = document.getElementById('clearFilters');
        const cards = document.querySelectorAll('.mb-booking-card');

        function filterBookings() {
            const search = (searchInput.value || '').toLowerCase();
            const status = statusSelect.value;
            const date = dateInput.value;
            const service = (serviceSelect.value || '').toLowerCase();

            cards.forEach(card => {
                const cSearch = (card.dataset.customer || '').toLowerCase();
                const cStatus = card.dataset.status || '';
                const cDate = card.dataset.date || '';
                const cServices = (card.dataset.services || '').toLowerCase();

                const matchSearch = !search || cSearch.includes(search);
                const matchStatus = !status || cStatus === status;
                const matchDate = !date || cDate === date;
                const matchService = !service || cServices.includes(service);

                if (matchSearch && matchStatus && matchDate && matchService) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        searchInput.addEventListener('input', filterBookings);
        statusSelect.addEventListener('change', filterBookings);
        dateInput.addEventListener('change', filterBookings);
        serviceSelect.addEventListener('change', filterBookings);
        applyBtn.addEventListener('click', filterBookings);
        
        clearBtn.addEventListener('click', () => {
            searchInput.value = '';
            statusSelect.value = '';
            dateInput.value = '';
            serviceSelect.value = '';
            filterBookings();
        });
    });

    function openViewModal(bookingId) {
        const modal = new bootstrap.Modal(document.getElementById('viewBookingModal'));
        const card = document.querySelector(`[data-booking-id="${bookingId}"]`);

        if (card) {
            document.getElementById('modalBookingId').innerText = `Booking #${String(bookingId).padStart(4, '0')}`;
            
            const badge = document.getElementById('modalStatusBadge');
            badge.innerText = card.dataset.statusDisplay;
            badge.className = `mb-status-badge ${card.dataset.statusClass}`;

            document.getElementById('modalCustomerName').innerText = card.dataset.customerName;
            document.getElementById('modalCustomerPhone').innerText = card.dataset.customerPhone;
            document.getElementById('modalCustomerEmail').innerText = card.dataset.customerEmail;

            document.getElementById('modalVehicle').innerText = card.dataset.vehicle;
            document.getElementById('modalPlate').innerText = card.dataset.plate;

            document.getElementById('modalScheduleDate').innerText = card.dataset.scheduleDate;
            document.getElementById('modalScheduleTime').innerText = card.dataset.scheduleTime;

            const serviceVal = card.querySelector('.mb-info-val[title]');
            document.getElementById('modalServices').innerText = serviceVal ? serviceVal.getAttribute('title') : 'N/A';
            document.getElementById('modalMechanic').innerText = card.dataset.mechanic;

            document.getElementById('modalPaymentMethod').innerText = card.dataset.paymentMethod;
            document.getElementById('modalTotalPrice').innerText = card.dataset.totalPrice;

            modal.show();
            setTimeout(() => lucide.createIcons(), 100);
        }
    }
</script>