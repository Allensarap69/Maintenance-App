<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

require 'db.php';
require 'sms_helper.php';
require 'SMSTemplates.php';

$msg = '';
$msg_type = '';
if (!empty($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msg_type = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// Ensure notifications table exists
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

// --- Remind Customer (maintenance reminder notification + SMS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remind_customer'])) {
    $customer_id = (int) ($_POST['customer_id'] ?? 0);
    $motorcycle_id = (int) ($_POST['motorcycle_id'] ?? 0);
    $maintenance_label = trim($_POST['maintenance_label'] ?? '');
    $mileage = (int) ($_POST['mileage'] ?? 0);
    $notes = trim($_POST['reminder_notes'] ?? '');

    if (empty($customer_id) || empty($motorcycle_id) || empty($maintenance_label)) {
        $msg = '❌ Invalid reminder request.';
        $msg_type = 'danger';
    } else {
        $recommendation = 'Recommended maintenance: ' . $maintenance_label . ' (due at ' . number_format($mileage) . ' km)';
        if ($notes !== '') {
            $recommendation .= ' | Notes: ' . $notes;
        }

        try {
            $motoStmt = $pdo->prepare("SELECT brand, model, health_score FROM motorcycles WHERE id = ?");
            $motoStmt->execute([$motorcycle_id]);
            $moto = $motoStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $stmt = $pdo->prepare("INSERT INTO customer_notifications (customer_id, motorcycle_id, health_score, recommendation) VALUES (?, ?, ?, ?)");
            $stmt->execute([$customer_id, $motorcycle_id, (int) ($moto['health_score'] ?? 0), $recommendation]);
            $notificationId = (int) $pdo->lastInsertId();

            // Send SMS reminder to the customer's registered phone number
            $phoneStmt = $pdo->prepare("SELECT phone FROM users WHERE id = ?");
            $phoneStmt->execute([$customer_id]);
            $customerPhone = $phoneStmt->fetchColumn();

            $flashType = 'success';
            $smsNote = '';
            if (!empty($customerPhone)) {
                try {
                    $sms = new SMSHelper();
                    $smsText = SMSTemplates::maintenanceDueReminder(
                        $moto['brand'] ?? 'motorcycle',
                        $moto['model'] ?? '',
                        $maintenance_label,
                        number_format($mileage)
                    );
                    if ($notes !== '') {
                        $smsText .= ' Note: ' . $notes;
                    }
                    $smsResult = $sms->sendSMS($customerPhone, $smsText, 'MAINTENANCE_REMINDER', [
                        'user_id' => $customer_id,
                        'customer_id' => $customer_id,
                        'motorcycle_id' => $motorcycle_id,
                        'notification_key' => 'MAINT_REMIND_' . $notificationId,
                        'reference_id' => $notificationId
                    ]);
                    if ($smsResult['success']) {
                        $smsNote = ' SMS sent to ' . $customerPhone . '.';
                    } else {
                        $smsNote = ' SMS failed: ' . $smsResult['message'];
                        $flashType = 'warning';
                    }
                } catch (Exception $e) {
                    error_log('Maintenance reminder SMS error: ' . $e->getMessage());
                    $smsNote = ' SMS could not be sent.';
                    $flashType = 'warning';
                }
            } else {
                $smsNote = ' No phone number on file - SMS not sent.';
                $flashType = 'warning';
            }

            $_SESSION['flash_msg'] = '✅ Reminder sent to customer.' . $smsNote;
            $_SESSION['flash_type'] = $flashType;
        } catch (PDOException $e) {
            $_SESSION['flash_msg'] = '❌ Error sending reminder.';
            $_SESSION['flash_type'] = 'danger';
            error_log('Maintenance reminder error: ' . $e->getMessage());
        }

        // Post/Redirect/Get: prevent duplicate reminders when the page is reloaded
        header('Location: maintenance_management.php?customer_id=' . $customer_id);
        exit;
    }
}

$maintenance_timeline = [];
$upcoming_maintenance = [];
$overdue_maintenance = [];
$recent_records = [];

try {
    $stmt = $pdo->query("
        SELECT m.id AS motorcycle_id, m.brand, m.model, m.plate_number, m.current_mileage,
               c.id AS customer_id, c.username AS customer_name
        FROM motorcycles m
        JOIN users c ON m.user_id = c.id
        WHERE c.role = 'customer'
        ORDER BY c.username, m.brand, m.model
    ");
    $maintenance_timeline = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $history_by_motorcycle = [];
    $stmt = $pdo->query("
        SELECT id, motorcycle_id, service_date, mileage, service_type,
               mechanic_remarks, parts_replaced
        FROM maintenance_history
        ORDER BY mileage ASC
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $history_by_motorcycle[$h['motorcycle_id']][] = $h;
    }

    $upcoming_sql = "
        SELECT
            c.id AS customer_id,
            c.username AS customer_name,
            m.id AS motorcycle_id,
            m.brand,
            m.model,
            m.plate_number,
            MAX(mh.service_date) AS last_service,
            DATE_ADD(MAX(mh.service_date), INTERVAL 3 MONTH) AS next_due,
            DATEDIFF(DATE_ADD(MAX(mh.service_date), INTERVAL 3 MONTH), CURDATE()) AS days_until_due
        FROM users c
        JOIN motorcycles m ON m.user_id = c.id
        LEFT JOIN maintenance_history mh ON mh.motorcycle_id = m.id
        WHERE c.role = 'customer'
        GROUP BY m.id, c.id, c.username, m.brand, m.model, m.plate_number
        HAVING next_due BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY next_due ASC
    ";
    $stmt = $pdo->query($upcoming_sql);
    $upcoming_maintenance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $overdue_sql = "
        SELECT
            c.id AS customer_id,
            c.username AS customer_name,
            m.id AS motorcycle_id,
            m.brand,
            m.model,
            m.plate_number,
            MAX(mh.service_date) AS last_service,
            DATE_ADD(MAX(mh.service_date), INTERVAL 3 MONTH) AS next_due,
            DATEDIFF(DATE_ADD(MAX(mh.service_date), INTERVAL 3 MONTH), CURDATE()) AS days_until_due
        FROM users c
        JOIN motorcycles m ON m.user_id = c.id
        LEFT JOIN maintenance_history mh ON mh.motorcycle_id = m.id
        WHERE c.role = 'customer'
        GROUP BY m.id, c.id, c.username, m.brand, m.model, m.plate_number
        HAVING next_due < CURDATE()
        ORDER BY next_due ASC
    ";
    $stmt = $pdo->query($overdue_sql);
    $overdue_maintenance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $recent_sql = "
        SELECT
            mh.*,
            CONCAT(m.brand, ' ', m.model, ' (', m.plate_number, ')') AS motorcycle_info,
            c.username AS customer_name
        FROM maintenance_history mh
        JOIN motorcycles m ON mh.motorcycle_id = m.id
        JOIN users c ON mh.customer_id = c.id
        WHERE mh.service_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY mh.service_date DESC
    ";
    $stmt = $pdo->query($recent_sql);
    $recent_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $msg = '❌ Error loading maintenance data: ' . $e->getMessage();
    $msg_type = 'danger';
    error_log('Maintenance management error: ' . $e->getMessage());
}

$selected_customer_id = (isset($_GET['customer_id']) && is_numeric($_GET['customer_id'])) ? (int) $_GET['customer_id'] : null;
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

$customers = [];
foreach ($maintenance_timeline as $m) {
    $customers[$m['customer_id']]['name'] = $m['customer_name'];
    $customers[$m['customer_id']]['motorcycles'][] = $m;
}
ksort($customers);

$filtered_customers = [];
foreach ($customers as $cid => $c) {
    if ($search !== '') {
        $s = strtolower($search);
        $match = stripos(strtolower($c['name']), $s) !== false;
        foreach ($c['motorcycles'] as $m) {
            if (stripos(strtolower($m['brand']), $s) !== false
                || stripos(strtolower($m['model']), $s) !== false
                || stripos(strtolower($m['plate_number']), $s) !== false) {
                $match = true;
            }
        }
        if (!$match) continue;
    }
    $filtered_customers[$cid] = $c;
}

$selected_customer = null;
if ($selected_customer_id && isset($filtered_customers[$selected_customer_id])) {
    $selected_customer = $filtered_customers[$selected_customer_id];
}

$upcoming_by_customer = [];
foreach ($upcoming_maintenance as $row) {
    $upcoming_by_customer[$row['customer_id']][] = $row;
}
$overdue_by_customer = [];
foreach ($overdue_maintenance as $row) {
    $overdue_by_customer[$row['customer_id']][] = $row;
}
$recent_by_customer = [];
foreach ($recent_records as $row) {
    $recent_by_customer[$row['customer_id']][] = $row;
}

$pageTitle = 'Maintenance Management';
?>
<?php require 'admin_sidebar_template.php'; ?>

<style>
    body .btn-primary {
        background: #FACC15 !important;
        border-color: #FACC15 !important;
        color: #111827 !important;
        box-shadow: none !important;
    }
    body .btn-primary:hover {
        background: #EAB308 !important;
        border-color: #EAB308 !important;
        color: #111827 !important;
        box-shadow: none !important;
    }
    :root {
        --bg-card: #ffffff;
        --border-color: rgba(0, 0, 0, 0.06);
        --border-highlight: rgba(0, 0, 0, 0.12);
        --text-main: #111827;
        --text-sub: #6b7280;
        --text-muted: #9ca3af;
        --accent-gold: #FACC15;
        --accent-green: #10b981;
        --accent-blue: #3b82f6;
    }
    .top-header {
        position: fixed !important;
        top: 0;
        left: var(--sidebar-width);
        right: 0;
        z-index: 100;
    }
    .main-content {
        padding-top: 75px !important;
        background: #ffffff !important;
    }
    @media (max-width: 991px) {
        .top-header { left: 0 !important; }
    }
    body, .mm-text { font-size: 0.85rem; color: #000; }
    .mm-label, .form-label { font-size: 0.8rem; color: #000; }
    .mm-heading, h3, h5, .mm-section-title, .vehicle-title, .action-card h5 { font-size: 1rem; color: #000; }
    .mm-section-title {
        background: #fff;
        color: #000;
        font-weight: 700;
        padding: 12px 18px;
        position: relative;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .mm-section-title::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #FACC15, #FDE047, #FACC15);
    }
    .mm-section-title .mm-customer-count {
        margin-left: auto;
        font-size: 0.72rem;
        font-weight: 600;
        color: #6b7280;
    }
    .modal-header {
        background: #fff !important;
        color: #000 !important;
        position: relative;
        border-bottom: none;
    }
    .modal-header .modal-title { color: #000 !important; font-size: 1rem; }
    .modal-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #FACC15, #FDE047, #FACC15);
    }
    .modal-header .btn-close,
    .modal-header .btn-close-white { filter: none; }
    /* Use default admin top-header from admin_sidebar_template */

    .mm-topbar {
        position: fixed;
        top: 0;
        left: var(--sidebar-width);
        right: 0;
        height: 60px;
        background: #1e293b;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        padding: 0 30px;
        z-index: 1000;
        box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        border-bottom: none;
    }
    .mm-topbar::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg,
            transparent 0%,
            rgba(250, 204, 21, 0.8) 20%,
            rgba(59, 130, 246, 0.8) 50%,
            rgba(250, 204, 21, 0.8) 80%,
            transparent 100%
        );
    }
    .mm-topbar .mm-title {
        font-size: 1rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
        white-space: nowrap;
        color: #fff;
    }
    .mm-topbar .mm-title i {
        background: linear-gradient(135deg, #FACC15 0%, #FDE047 50%, #FACC15 100%);
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
    }
    .mm-topbar .search-box {
        position: relative;
        flex: 1;
        max-width: 420px;
    }
    .mm-topbar .search-box input {
        border: none;
        border-radius: 10px;
        padding: 9px 16px 9px 40px;
        width: 100%;
        background: rgba(255,255,255,0.12);
        color: #fff;
        outline: none;
        font-size: 0.85rem;
    }
    .mm-topbar .search-box input::placeholder { color: rgba(255,255,255,0.55); }
    .mm-topbar .search-box i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: rgba(255,255,255,0.7);
    }
    .mm-topbar .actions { display: flex; gap: 16px; align-items: center; }
    .mm-topbar .actions i { font-size: 1.25rem; cursor: pointer; color: #fff; }
    .mm-avatar {
        width: 36px; height: 36px;
        border-radius: 50%;
        background: #FACC15;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; color: #0f172a;
    }
    .maintenance-timeline, .empty-state.card { background: #fff !important; color: #000; }
    .customer-list { color: #000; }
    .customer-list { border-radius: 0 0 12px 12px; overflow-y: auto; }
    .maintenance-timeline { padding: 16px 16px 40px; border-radius: 0 0 12px 12px; overflow: hidden; }
    .maintenance-timeline .timeline-wrapper {
        max-height: calc(100vh - 240px);
        overflow-y: auto;
    }
    .customer-list {
        height: calc(100vh - 160px);
        overflow-y: auto;
    }
    .maintenance-timeline {
        height: calc(100vh - 160px);
        overflow: hidden;
    }
    body, .main-content {
        overflow: hidden;
    }
    .customer-list::-webkit-scrollbar,
    .maintenance-timeline .timeline-wrapper::-webkit-scrollbar { display: none; }
    .customer-list, .maintenance-timeline .timeline-wrapper {
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    .customer-list .list-group-item {
        border: none;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        padding: 10px 14px;
        color: #000;
        font-size: 0.85rem;
        font-weight: 500;
        background: transparent;
        transition: all 0.2s;
    }
    .customer-list .list-group-item > span:first-child { color: inherit !important; }
    .customer-list .list-group-item:hover,
    .customer-list .list-group-item.active {
        background: #FACC15;
        color: #fff !important;
    }
    .customer-list .list-group-item.active .badge,
    .customer-list .list-group-item:hover .badge {
        background: #fff !important;
        color: #FACC15 !important;
    }
    .customer-list .badge { font-size: 0.75rem; }

    .vehicle-list {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .vehicle-header {
        display: flex;
        align-items: center;
        gap: 6px;
        width: 100%;
        padding: 5px 12px;
        border-radius: 6px;
        background: #fff;
        border: 1px solid rgba(0, 0, 0, 0.08);
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        color: #374151;
        cursor: pointer;
        white-space: nowrap;
        transition: all 0.2s;
    }
    .vehicle-header:hover {
        border-color: #FACC15;
        color: #0f172a;
    }
    .vehicle-header.active {
        background: #FACC15;
        border-color: #FACC15;
        color: #fff;
    }
    .vehicle-title { font-size: 0.7rem; font-weight: 700; color: inherit; margin: 0; }
    .vehicle-meta { font-size: 0.58rem; color: inherit; opacity: 0.85; margin-top: 1px; }
    .moto-timeline { display: none; }
    .moto-timeline.active { display: block; }

    .current-mileage {
        background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
        color: #fff !important;
        border-radius: 14px;
        padding: 10px 20px;
        text-align: center;
        display: inline-block;
        margin: 0 auto 16px;
        box-shadow: 0 6px 16px rgba(0,0,0,0.15);
    }
    .current-mileage .km {
        font-size: 1.3rem;
        font-weight: 800;
        color: #FFB800 !important;
        line-height: 1;
    }
    .current-mileage .label {
        font-size: 0.65rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-top: 2px;
        color: #fff !important;
    }

    .timeline-wrapper { position: relative; }
    .timeline-track {
        display: flex;
        gap: 20px;
        overflow-x: auto;
        padding: 40px 4px 20px;
        position: relative;
        min-height: 200px;
        align-items: flex-start;
    }
    .timeline-track::before {
        content: '';
        position: absolute;
        top: 15px;
        left: 0;
        right: 0;
        height: 3px;
        background: #E0E0E0;
        border-radius: 2px;
        z-index: 1;
    }
    .progress-fill {
        position: absolute;
        top: 15px;
        left: 0;
        height: 3px;
        background: #22C55E;
        border-radius: 2px;
        z-index: 2;
    }
    .service-card {
        flex: 0 0 260px;
        background: #fff;
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 6px 20px rgba(0,0,0,0.08);
        border: 1px solid rgba(0,0,0,0.04);
        position: relative;
        z-index: 2;
        margin-top: 30px;
    }
    .service-card::before {
        content: '';
        position: absolute;
        top: -43px;
        left: 50%;
        transform: translateX(-50%);
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #CCCCCC;
        z-index: 3;
    }
    .service-card.completed::before { background: #22C55E; }
    .service-card.active::before {
        top: -46px;
        width: 16px;
        height: 16px;
        border: 2px solid #111827;
    }
    .service-card.active.completed::before {
        background: #22C55E;
        box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.25);
    }
    .service-card.active:not(.completed)::before {
        background: #CCCCCC;
        box-shadow: 0 0 0 4px rgba(156, 163, 175, 0.35);
    }
    .service-card.active::after {
        content: '';
        position: absolute;
        top: -30px;
        left: 50%;
        transform: translateX(-50%);
        width: 0;
        height: 0;
        border-left: 6px solid transparent;
        border-right: 6px solid transparent;
        border-top: 8px solid #9CA3AF;
        z-index: 3;
    }
    .service-card.active.completed::after { border-top-color: #22C55E; }
    .service-card .top {
        display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;
    }
    .service-card .mileage {
        font-size: 1.3rem; font-weight: 800; color: #0f172a;
    }
    .service-card .type { font-size: 0.95rem; color: #1e293b; font-weight: 600; margin-bottom: 4px; }
    .service-card .plate { font-size: 0.8rem; color: #64748b; margin-bottom: 14px; }
    .service-card .badge {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 50px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .service-card .badge-completed {
        background: #DCFCE7;
        color: #15803D;
    }
    .service-card .badge-pending {
        background: #F3F4F6;
        color: #6B7280;
    }
    .service-card .checks { list-style: none; padding: 0; margin: 0; }
    .service-card .checks li {
        font-size: 0.85rem; color: #475569; padding: 4px 0;
        display: flex; align-items: center; gap: 8px;
    }
    .service-card .checks li i { color: #9CA3AF; }
    .service-card.completed .checks li i { color: #22C55E; }

    .action-card {
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 6px 20px rgba(0,0,0,0.08);
        border: none;
    }
    .action-card.upcoming { background: linear-gradient(135deg, #fffbeb 0%, #fffbeb 100%); border-left: 5px solid #FACC15; }
    .action-card.overdue { background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%); border-left: 5px solid #ef4444; }
    .action-card h5 { font-weight: 800; margin-bottom: 10px; }
    .action-card .meta { color: #64748b; font-size: 0.9rem; margin-bottom: 16px; }
    .action-card .btn { border-radius: 50px; padding: 10px 24px; font-weight: 600; }

    .empty-state { text-align: center; padding: 40px 20px; color: #64748b; }
    .empty-state i { font-size: 3rem; margin-bottom: 15px; display: block; }

    .customer-name { font-weight: 700; color: #000; }
    .customer-motorcycle { border-left: 4px solid #3b82f6; background: #f8fafc; }
    .maintenance-timeline { color: #000; }

    .maintenance-timeline .timeline-wrapper {
        position: relative;
        padding-left: 20px;
        margin-top: 10px;
    }
    .maintenance-timeline .timeline-wrapper::before {
        content: '';
        position: absolute;
        left: 7px;
        top: 7px;
        bottom: 7px;
        width: 1px;
        background: linear-gradient(to bottom, #22C55E var(--progress, 0%), var(--border-color) var(--progress, 0%));
    }
    .maintenance-timeline .timeline-item {
        position: relative;
        padding-bottom: 6px;
    }
    .maintenance-timeline .timeline-item:last-child {
        padding-bottom: 0;
    }
    .maintenance-timeline .timeline-dot {
        position: absolute;
        left: -18px;
        top: 2px;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: #ffffff;
        border: 2px solid var(--accent-blue);
        z-index: 2;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .maintenance-timeline .timeline-dot:hover {
        transform: scale(1.15);
    }
    .maintenance-timeline .timeline-dot::after {
        content: '+';
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 6px;
        color: var(--accent-blue);
        opacity: 0;
        transition: opacity 0.2s ease;
    }
    .maintenance-timeline .timeline-dot:hover::after,
    .maintenance-timeline .timeline-item.expanded .timeline-dot::after {
        opacity: 1;
    }
    .maintenance-timeline .timeline-item.completed .timeline-dot,
    .maintenance-timeline .timeline-item.past .timeline-dot,
    .maintenance-timeline .timeline-item.current-target .timeline-dot,
    .maintenance-timeline .timeline-item.current .timeline-dot {
        border-color: var(--accent-green);
        background: var(--accent-green);
    }
    .maintenance-timeline .timeline-item.completed .timeline-dot::after,
    .maintenance-timeline .timeline-item.past .timeline-dot::after,
    .maintenance-timeline .timeline-item.current-target .timeline-dot::after,
    .maintenance-timeline .timeline-item.current .timeline-dot::after {
        color: #ffffff;
    }
    .maintenance-timeline .timeline-item.scheduled .timeline-dot {
        border-color: var(--accent-gold);
        background: #ffffff;
    }
    .maintenance-timeline .timeline-content {
        background: #f8fafc;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 6px 8px;
        transition: all 0.2s ease;
        font-size: 0.7rem;
    }
    .maintenance-timeline .timeline-content:hover {
        border-color: var(--border-highlight);
    }
    .maintenance-timeline .timeline-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 2px;
    }
    .maintenance-timeline .timeline-title {
        font-size: 0.72rem;
        font-weight: 700;
        color: var(--text-main);
    }
    .maintenance-timeline .timeline-badge {
        font-size: 0.58rem;
        font-weight: 700;
        text-transform: uppercase;
        padding: 1px 5px;
        border-radius: 50px;
        border: none;
        background: none;
    }
    .maintenance-timeline button.timeline-badge {
        cursor: pointer;
        font: inherit;
        line-height: inherit;
        box-shadow: none;
    }
    .maintenance-timeline button.timeline-badge:hover {
        opacity: 0.8;
    }
    .maintenance-timeline button.timeline-badge:disabled {
        opacity: 0.5 !important;
        cursor: not-allowed;
    }
    .maintenance-timeline .timeline-badge.completed {
        background: rgba(16, 185, 129, 0.12);
        color: #059669;
    }
    .maintenance-timeline .timeline-badge.scheduled,
    .maintenance-timeline .timeline-badge.past {
        background: rgba(250, 204, 21, 0.12);
        color: #3b82f6;
    }
    .maintenance-timeline .timeline-badge.current {
        background: rgba(17, 24, 39, 0.1);
        color: #111827;
    }
    .maintenance-timeline .timeline-meta {
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 0.65rem;
        color: var(--text-muted);
        font-weight: 600;
    }
    .maintenance-timeline .timeline-detail {
        display: none;
        margin-top: 4px;
        padding-top: 4px;
        border-top: 1px dashed var(--border-color);
        font-size: 0.65rem;
        color: var(--text-sub);
        line-height: 1.4;
    }
    .maintenance-timeline .timeline-item.expanded .timeline-detail {
        display: block;
    }
    .maintenance-timeline .timeline-detail-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 1px;
    }
    .maintenance-timeline .timeline-detail-row:last-child {
        margin-bottom: 0;
    }
    .maintenance-timeline .timeline-detail-label {
        color: var(--text-muted);
        font-weight: 600;
    }

    @media (max-width: 991px) {
        .mm-topbar { flex-wrap: wrap; }
        .vehicle-header { flex-direction: column; align-items: flex-start; gap: 20px; }
    }
    .mm-quick-search {
        border: 1px solid #FACC15;
    }
    .mm-quick-search:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.2);
    }

    /* Dark mode overrides */
    html.dark-mode .mm-text, html.dark-mode .mm-label,
    html.dark-mode .mm-heading, html.dark-mode .mm-section-title,
    html.dark-mode .vehicle-title, html.dark-mode .action-card h5,
    html.dark-mode .customer-list, html.dark-mode .maintenance-timeline,
    html.dark-mode .customer-name { color: #e2e8f0 !important; }
    html.dark-mode .mm-section-title { background: transparent !important; }
    html.dark-mode .mm-section-title .mm-customer-count { color: #94a3b8 !important; }
    html.dark-mode .customer-list .list-group-item { color: #e2e8f0 !important; border-bottom-color: rgba(255, 255, 255, 0.07) !important; }
    html.dark-mode .customer-list .list-group-item:hover,
    html.dark-mode .customer-list .list-group-item.active { background: #FACC15 !important; color: #111827 !important; }
    html.dark-mode .customer-list .list-group-item.active .badge,
    html.dark-mode .customer-list .list-group-item:hover .badge { color: #FACC15 !important; }
    html.dark-mode .vehicle-header { color: #cbd5e1 !important; }
    html.dark-mode .vehicle-header:hover { color: #e2e8f0 !important; }
    html.dark-mode .vehicle-header.active { background: #FACC15 !important; color: #111827 !important; }
    html.dark-mode .timeline-track::before { background: #3b4d7d !important; }
    html.dark-mode .service-card .type { color: #e2e8f0 !important; }
    html.dark-mode .service-card .plate { color: #94a3b8 !important; }
    html.dark-mode .service-card .badge-completed { background: rgba(34, 197, 94, 0.15) !important; color: #4ade80 !important; }
    html.dark-mode .service-card .badge-pending { background: rgba(148, 163, 184, 0.15) !important; color: #cbd5e1 !important; }
    html.dark-mode .service-card .checks li i { color: #64748b !important; }
    html.dark-mode .service-card.completed .checks li i { color: #4ade80 !important; }
    html.dark-mode .action-card.upcoming { background: rgba(250, 204, 21, 0.07) !important; }
    html.dark-mode .action-card.overdue { background: rgba(239, 68, 68, 0.08) !important; }
    html.dark-mode .action-card .meta { color: #94a3b8 !important; }
    html.dark-mode .empty-state { color: #94a3b8 !important; }
    html.dark-mode .customer-motorcycle { background: #22335a !important; }
    html.dark-mode .maintenance-timeline .timeline-content { background: #1a2b4f !important; }
    html.dark-mode .maintenance-timeline .timeline-dot,
    html.dark-mode .maintenance-timeline .timeline-item.scheduled .timeline-dot { background: #1a2b4f !important; }
    html.dark-mode .maintenance-timeline .timeline-badge.current { background: rgba(255, 255, 255, 0.12) !important; color: #e2e8f0 !important; }
</style>

<div class="container-fluid">
    <?php if ($msg): ?>
    <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show mb-4" role="alert">
        <?= $msg ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="row mb-3">
        <div class="col-12">
            <form method="get" action="" class="d-flex gap-2" style="max-width: 420px;">
                <?php if ($selected_customer_id): ?>
                    <input type="hidden" name="customer_id" value="<?= $selected_customer_id ?>">
                <?php endif; ?>
                <div class="position-relative flex-fill">
                    <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-2 text-muted"></i>
                    <input type="text" name="q" class="form-control form-control-sm ps-4 mm-quick-search" placeholder="Quick Search Customer/Plate" value="<?= htmlspecialchars($search) ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Search</button>
            </form>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-3 col-md-4">
            <div class="mm-section-title">
                <i class="bi bi-people"></i>
                <span>Customer List</span>
                <span class="mm-customer-count"><?= count($filtered_customers) ?> total</span>
            </div>
            <div class="customer-list">
                <div class="list-group list-group-flush">
                    <?php if (empty($filtered_customers)): ?>
                        <div class="list-group-item text-muted">No customers found.</div>
                    <?php else: ?>
                        <?php foreach ($filtered_customers as $customer_id => $customer): ?>
                            <?php $is_active = ($selected_customer_id == $customer_id); ?>
                            <a href="?customer_id=<?= $customer_id ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $is_active ? 'active' : '' ?>">
                                <span><i class="bi bi-person-circle me-2"></i><?= htmlspecialchars($customer['name']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-9 col-md-8">
            <?php if ($selected_customer): ?>

                <h3 class="customer-name mb-4">
                    <i class="bi bi-person-circle me-2 text-primary"></i><?= htmlspecialchars($selected_customer['name']) ?>
                </h3>

                <div class="mm-section-title d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar3 me-2"></i>Maintenance Timeline</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-primary" onclick="showMaintenanceTimeline()">
                            <i class="bi bi-calendar3 me-1"></i>Timeline
                        </button>
                        <button type="button" class="btn btn-sm btn-warning" onclick="showMaintenanceSection('upcoming')">
                            <i class="bi bi-calendar-event me-1"></i>Upcoming
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="showMaintenanceSection('overdue')">
                            <i class="bi bi-exclamation-triangle me-1"></i>Overdue
                        </button>
                    </div>
                </div>
                <div class="maintenance-timeline mb-4">
                            <?php
                            $service_plan = [
                                1000 => 'First Service',
                                3000 => 'Preventive Maintenance',
                                6000 => 'Preventive Maintenance',
                                9000 => 'Preventive Maintenance',
                                12000 => 'Major Service',
                                15000 => 'Preventive Maintenance',
                                18000 => 'Major Service',
                                21000 => 'Preventive Maintenance',
                                24000 => 'Major Service',
                            ];
                            ?>

                            <div id="upcomingCard" class="card action-card upcoming mb-3" style="display:none;">
                                <div class="card-body">
                                    <?php if (!empty($upcoming_by_customer[$selected_customer_id])): ?>
                                        <?php $u = $upcoming_by_customer[$selected_customer_id][0]; ?>
                                        <div class="meta">
                                            <?= htmlspecialchars($u['brand'] . ' ' . $u['model']) ?> (<?= htmlspecialchars($u['plate_number']) ?>)
                                            &bull; Due on <?= date('M d, Y', strtotime($u['next_due'])) ?>
                                            &bull; <strong><?= $u['days_until_due'] ?> day(s) left</strong>
                                        </div>
                                        <button class="btn btn-warning text-dark btn-sm">Scheduling</button>
                                    <?php else: ?>
                                        <div class="meta">No upcoming maintenance for this customer.</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div id="overdueCard" class="card action-card overdue mb-3" style="display:none;">
                                <div class="card-body">
                                    <?php if (!empty($overdue_by_customer[$selected_customer_id])): ?>
                                        <?php $o = $overdue_by_customer[$selected_customer_id][0]; ?>
                                        <div class="meta">
                                            <?= htmlspecialchars($o['brand'] . ' ' . $o['model']) ?> (<?= htmlspecialchars($o['plate_number']) ?>)
                                            &bull; Was due <?= date('M d, Y', strtotime($o['next_due'])) ?>
                                            &bull; <strong class="text-danger"><?= abs($o['days_until_due']) ?> day(s) overdue</strong>
                                        </div>
                                        <button class="btn btn-danger btn-sm">Immediate Action</button>
                                    <?php else: ?>
                                        <div class="meta">No overdue maintenance for this customer.</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div id="vehicleTimeline">
                                <div class="row g-3">
                                    <div class="col-lg-3 col-md-4">
                                        <div class="vehicle-list">
                                <?php foreach ($selected_customer['motorcycles'] as $index => $m): ?>
                                    <?php $tabCurrent = (int) ($m['current_mileage'] ?? 0); ?>
                                    <div class="vehicle-header <?= $index === 0 ? 'active' : '' ?>" data-moto="<?= $m['motorcycle_id'] ?>" onclick="showMotoTimeline(<?= (int) $m['motorcycle_id'] ?>)">
                                        <div>
                                            <div class="vehicle-title"><?= htmlspecialchars(strtoupper($m['brand'] . ' ' . $m['model'])) ?></div>
                                            <div class="vehicle-meta">
                                                <i class="bi bi-speedometer2 me-1"></i><?= number_format($tabCurrent) ?> km | <?= htmlspecialchars($m['plate_number']) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="col-lg-9 col-md-8">

                                <?php foreach ($selected_customer['motorcycles'] as $index => $m): ?>
                                    <?php
                                    $current = (int) ($m['current_mileage'] ?? 0);
                                    $history = $history_by_motorcycle[$m['motorcycle_id']] ?? [];
                                    $timeline = [];
                                    foreach ($history as $h) {
                                        $timeline[] = [
                                            'mileage' => (int) $h['mileage'],
                                            'label' => htmlspecialchars($h['service_type']),
                                            'status' => 'completed',
                                            'date' => $h['service_date'],
                                            'plate' => $h['plate_number'] ?? $m['plate_number'],
                                            'mechanic_remarks' => !empty($h['mechanic_remarks']) ? htmlspecialchars($h['mechanic_remarks']) : null,
                                            'parts_replaced' => !empty($h['parts_replaced']) ? htmlspecialchars($h['parts_replaced']) : null,
                                        ];
                                    }
                                    $recorded_mileages = array_map('intval', array_column($history, 'mileage'));
                                    foreach ($service_plan as $km => $label) {
                                        if (!in_array($km, $recorded_mileages)) {
                                            $timeline[] = [
                                                'mileage' => $km,
                                                'label' => $label,
                                                'status' => $km >= $current ? 'upcoming' : 'missed',
                                                'date' => null,
                                                'plate' => $m['plate_number'],
                                                'mechanic_remarks' => null,
                                                'parts_replaced' => null,
                                            ];
                                        }
                                    }
                                    $existing_mileages = array_map('intval', array_column($timeline, 'mileage'));
                                    if (!in_array($current, $existing_mileages)) {
                                        $timeline[] = [
                                            'mileage' => $current,
                                            'label' => 'Current Mileage',
                                            'status' => 'current',
                                            'date' => null,
                                            'plate' => $m['plate_number'],
                                            'mechanic_remarks' => null,
                                            'parts_replaced' => null,
                                        ];
                                    }
                                    usort($timeline, fn($a, $b) => $a['mileage'] <=> $b['mileage']);
                                    ?>

                                    <div id="moto-<?= $m['motorcycle_id'] ?>" class="moto-timeline <?= $index === 0 ? 'active' : '' ?>">

                                    <div class="row g-3">
                                        <div class="col-lg-4 col-md-12 d-flex align-items-center justify-content-center">
                                            <div class="current-mileage">
                                                <div class="km"><?= number_format($current) ?> km</div>
                                                <div class="label">Current Mileage</div>
                                            </div>
                                        </div>
                                        <div class="col-lg-8 col-md-12">

                                    <h6 class="fw-bold mb-2" style="font-size: 0.9rem;">Maintenance History</h6>
                                    <?php
                                    $displayTimeline = $timeline;
                                    usort($displayTimeline, fn($a, $b) => $a['mileage'] <=> $b['mileage']);
                                    $totalItems = count($displayTimeline);
                                    $currentIndex = -1;
                                    foreach ($displayTimeline as $i => $item) {
                                        if ((int) $item['mileage'] <= $current) {
                                            $currentIndex = $i;
                                        }
                                    }
                                    $progressPercent = ($totalItems <= 1 || $currentIndex < 0) ? 0 : min(100, (($currentIndex + 0.5) / ($totalItems - 1)) * 100);

                                    $appliedNotifications = [];
                                    try {
                                        $stmt = $pdo->prepare("SELECT recommendation FROM customer_notifications WHERE customer_id = ? AND motorcycle_id = ? AND is_applied = 1");
                                        $stmt->execute([$selected_customer_id, $m['motorcycle_id']]);
                                        $appliedNotifications = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                    } catch (PDOException $e) {
                                        error_log('Could not fetch applied notifications: ' . $e->getMessage());
                                    }

                                    $nextMileage = null;
                                    foreach ($displayTimeline as $it) {
                                        if ($it['status'] !== 'upcoming') continue;
                                        $isApplied = false;
                                        foreach ($appliedNotifications as $rec) {
                                            if (stripos($rec, $it['label']) !== false) {
                                                $isApplied = true;
                                                break;
                                            }
                                        }
                                        if (!$isApplied) {
                                            $nextMileage = $it['mileage'];
                                            break;
                                        }
                                    }
                                    ?>
                                    <div class="timeline-wrapper mb-4" style="--progress: <?= $progressPercent ?>%">
                                        <?php foreach ($displayTimeline as $item): ?>
                                            <?php
                                            $status = $item['status'];
                                            $applied = false;
                                            if ($status === 'upcoming' && !empty($appliedNotifications)) {
                                                foreach ($appliedNotifications as $rec) {
                                                    if (stripos($rec, $item['label']) !== false) {
                                                        $applied = true;
                                                        break;
                                                    }
                                                }
                                            }
                                            if ($applied || $status === 'missed') {
                                                $status = 'completed';
                                            }
                                            if ($status === 'completed') {
                                                $status_label = 'Complete';
                                                $status_class = 'completed';
                                            } elseif ($status === 'upcoming') {
                                                $status_label = 'Remind';
                                                $status_class = 'scheduled';
                                            } elseif ($status === 'missed') {
                                                $status_label = 'Scheduled';
                                                $status_class = 'past';
                                            } elseif ($status === 'current') {
                                                $status_label = 'Current';
                                                $status_class = 'current';
                                            } else {
                                                $status_label = 'Pending';
                                                $status_class = 'scheduled';
                                            }
                                            $formatted_date = !empty($item['date']) ? date('M j, Y', strtotime($item['date'])) : 'N/A';
                                            $remarks = $item['mechanic_remarks'];
                                            $parts = $item['parts_replaced'];
                                            $has_details = !empty($remarks) || !empty($parts);
                                            $extraClass = ($status_class === 'scheduled' && (int) $item['mileage'] === $current) ? ' current-target' : '';
                                            $isNextScheduled = ($nextMileage !== null && (int) $item['mileage'] === (int) $nextMileage);
                                            ?>
                                            <div class="timeline-item <?= $status_class ?><?= $extraClass ?>">
                                                <div class="timeline-dot" title="Click for details"></div>
                                                <div class="timeline-content">
                                                    <div class="timeline-header">
                                                        <span class="timeline-title"><?= $item['label'] ?></span>
                                                        <?php if ($status_class === 'scheduled'): ?>
                                                            <?php if ($isNextScheduled): ?>
                                                                <button type="button" class="timeline-badge <?= $status_class ?>"
                                                                        data-customer="<?= (int) $selected_customer_id ?>"
                                                                        data-moto="<?= (int) $m['motorcycle_id'] ?>"
                                                                        data-mileage="<?= (int) $item['mileage'] ?>"
                                                                        data-moto-label="<?= htmlspecialchars($m['brand'] . ' ' . $m['model'] . ' (' . $m['plate_number'] . ')') ?>"
                                                                        data-service-label="<?= htmlspecialchars($item['label']) ?>"
                                                                        onclick="openRemindModal(this)">
                                                                    <i class="bi bi-bell me-1"></i><?= $status_label ?>
                                                                </button>
                                                            <?php else: ?>
                                                                <button type="button" class="timeline-badge <?= $status_class ?>" disabled style="opacity: 0.5; cursor: not-allowed;">
                                                                    <?= $status_label ?>
                                                                </button>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="timeline-badge <?= $status_class ?>"><?= $status_label ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="timeline-meta">
                                                        <span><i class="bi bi-speedometer2 me-1"></i><?= number_format($item['mileage']) ?> km</span>
                                                        <span><?= htmlspecialchars($item['plate'] ?? '') ?></span>
                                                    </div>
                                                    <div class="mt-1 text-muted" style="font-size:0.65rem;">
                                                        <i class="bi bi-calendar-event me-1"></i><?= $formatted_date ?>
                                                    </div>
                                                    <div class="timeline-detail">
                                                        <div class="timeline-detail-row">
                                                            <span class="timeline-detail-label">Service</span>
                                                            <span><?= $item['label'] ?></span>
                                                        </div>
                                                        <div class="timeline-detail-row">
                                                            <span class="timeline-detail-label">Mileage</span>
                                                            <span><?= number_format($item['mileage']) ?> km</span>
                                                        </div>
                                                        <div class="timeline-detail-row">
                                                            <span class="timeline-detail-label">Plate</span>
                                                            <span><?= htmlspecialchars($item['plate'] ?? '') ?></span>
                                                        </div>
                                                        <div class="timeline-detail-row">
                                                            <span class="timeline-detail-label">Date</span>
                                                            <span><?= $formatted_date ?></span>
                                                        </div>
                                                        <?php if (!empty($remarks)): ?>
                                                        <div class="timeline-detail-row" style="align-items:flex-start; flex-direction:column;">
                                                            <span class="timeline-detail-label">Remarks</span>
                                                            <span><?= $remarks ?></span>
                                                        </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($parts)): ?>
                                                        <div class="timeline-detail-row" style="align-items:flex-start; flex-direction:column;">
                                                            <span class="timeline-detail-label">Parts Replaced</span>
                                                            <span><?= $parts ?></span>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                        </div>
                                    </div>
                                    </div>
                                <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                </div>



            <?php else: ?>
                <div class="empty-state card">
                    <i class="bi bi-arrow-left-circle text-muted"></i>
                    <p>Select a customer from the list to view their maintenance timeline.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Remind Customer Modal -->
<div class="modal fade" id="remindCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-bell me-2"></i>Remind Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" class="mm-text">
                <div class="modal-body">
                    <input type="hidden" name="remind_customer" value="1">
                    <input type="hidden" name="customer_id" id="remindCustomerId">
                    <input type="hidden" name="motorcycle_id" id="remindMotorcycleId">
                    <input type="hidden" name="maintenance_label" id="remindLabel">
                    <input type="hidden" name="mileage" id="remindMileage">

                    <div class="mb-3">
                        <label class="form-label">Motorcycle</label>
                        <div class="fw-bold" id="remindMotoInfo"></div>
                        <div class="text-muted" id="remindItemInfo" style="font-size: 0.75rem;"></div>
                    </div>

                    <div class="alert alert-warning py-2 mb-3" style="font-size: 0.8rem;">
                        <i class="bi bi-info-circle me-1"></i>
                        The customer will receive an in-app notification and an SMS reminder to book this maintenance.
                    </div>

                    <div class="mb-1">
                        <label class="form-label">Notes (optional)</label>
                        <textarea class="form-control" name="reminder_notes" rows="3" placeholder="e.g. Please visit the shop this week for your scheduled service."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning text-dark fw-bold">
                        <i class="bi bi-bell me-1"></i>Send Reminder
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openRemindModal(btn) {
    document.getElementById('remindCustomerId').value = btn.dataset.customer;
    document.getElementById('remindMotorcycleId').value = btn.dataset.moto;
    document.getElementById('remindLabel').value = btn.dataset.serviceLabel;
    document.getElementById('remindMileage').value = btn.dataset.mileage;
    document.getElementById('remindMotoInfo').textContent = btn.dataset.motoLabel;
    document.getElementById('remindItemInfo').textContent = 'Maintenance due: ' + btn.dataset.serviceLabel + ' @ ' + Number(btn.dataset.mileage).toLocaleString() + ' km';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('remindCustomerModal')).show();
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.maintenance-timeline .timeline-dot').forEach(dot => {
        dot.addEventListener('click', function(e) {
            e.stopPropagation();
            const item = this.closest('.timeline-item');
            const wasExpanded = item.classList.contains('expanded');
            const wrapper = this.closest('.timeline-wrapper');
            wrapper.querySelectorAll('.timeline-item.expanded').forEach(i => i.classList.remove('expanded'));
            if (!wasExpanded) {
                item.classList.add('expanded');
            }
        });
    });
});

function showMotoTimeline(motoId) {
    const tab = document.querySelector('.vehicle-header[data-moto="' + motoId + '"]');
    document.querySelectorAll('.vehicle-header').forEach(h => h.classList.remove('active'));
    if (tab) tab.classList.add('active');

    document.querySelectorAll('.moto-timeline').forEach(t => {
        t.classList.remove('active');
    });
    const panel = document.getElementById('moto-' + motoId);
    if (panel) panel.classList.add('active');
}

function showMaintenanceTimeline() {
    document.getElementById('upcomingCard').style.display = 'none';
    document.getElementById('overdueCard').style.display = 'none';
    document.getElementById('vehicleTimeline').style.display = 'block';
}
function showMaintenanceSection(section) {
    const upcoming = document.getElementById('upcomingCard');
    const overdue = document.getElementById('overdueCard');
    const timeline = document.getElementById('vehicleTimeline');
    if (section === 'upcoming') {
        if (upcoming.style.display === 'block') {
            upcoming.style.display = 'none';
            timeline.style.display = 'block';
        } else {
            upcoming.style.display = 'block';
            overdue.style.display = 'none';
            timeline.style.display = 'none';
        }
    } else {
        if (overdue.style.display === 'block') {
            overdue.style.display = 'none';
            timeline.style.display = 'block';
        } else {
            overdue.style.display = 'block';
            upcoming.style.display = 'none';
            timeline.style.display = 'none';
        }
    }
}
</script>

<?php require 'admin_sidebar_footer.php'; ?>