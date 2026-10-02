<?php
session_start();
require 'db.php';
require 'motorcycle_health_helper.php';
require 'sms_helper.php';
require 'SMSTemplates.php';

// Security check: Only admins can access this page
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
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

// Ensure image column exists for per-motorcycle photos
try {
    $pdo->exec("ALTER TABLE motorcycles ADD COLUMN IF NOT EXISTS image VARCHAR(255) NULL");
} catch (PDOException $e) {
    error_log("Motorcycles image column check failed: " . $e->getMessage());
}

// Default photos per model name (used when no uploaded image exists)
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
$modelImages = array_map(function ($p) { return $p . '?v=3'; }, $modelImages);

$msg = "";
$msg_type = "";
if (!empty($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msg_type = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// Handle admin "Notify Customer" action for low health scores
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notify_customer'])) {
    $customer_id = (int) ($_POST['customer_id'] ?? 0);
    $motorcycle_id = (int) ($_POST['motorcycle_id'] ?? 0);
    $health_score = (int) ($_POST['health_score'] ?? 0);
    $maintenance_items = is_array($_POST['maintenance_items'] ?? null) ? $_POST['maintenance_items'] : [];
    $notes = trim($_POST['recommendation'] ?? '');
    $items = array_filter(array_map('trim', $maintenance_items), 'strlen');

    $recommendation = 'Recommended maintenance: ' . (empty($items) ? 'General Inspection' : implode(', ', $items));
    if ($notes !== '') {
        $recommendation .= ' | Notes: ' . $notes;
    }

    if ($customer_id && $motorcycle_id) {
        try {
            $stmt = $pdo->prepare("INSERT INTO customer_notifications (customer_id, motorcycle_id, health_score, recommendation) VALUES (?, ?, ?, ?)");
            $stmt->execute([$customer_id, $motorcycle_id, $health_score, $recommendation]);
            $notificationId = (int) $pdo->lastInsertId();

            // Send SMS to the customer's registered phone number
            $stmt = $pdo->prepare("SELECT phone FROM users WHERE id = ?");
            $stmt->execute([$customer_id]);
            $customerPhone = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT brand, model FROM motorcycles WHERE id = ?");
            $stmt->execute([$motorcycle_id]);
            $moto = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $flashType = 'success';
            $smsNote = '';
            if (!empty($customerPhone)) {
                try {
                    $sms = new SMSHelper();
                    $smsText = SMSTemplates::healthScoreAlert(
                        $moto['brand'] ?? 'motorcycle',
                        $moto['model'] ?? '',
                        $health_score,
                        empty($items) ? 'General Inspection' : implode(', ', $items)
                    );
                    if ($notes !== '') {
                        $smsText .= ' Note: ' . $notes;
                    }
                    $smsResult = $sms->sendSMS($customerPhone, $smsText, 'HEALTH_SCORE_ALERT', [
                        'user_id' => $customer_id,
                        'customer_id' => $customer_id,
                        'motorcycle_id' => $motorcycle_id,
                        'notification_key' => 'HEALTH_ALERT_' . $notificationId,
                        'reference_id' => $notificationId
                    ]);
                    if ($smsResult['success']) {
                        $smsNote = ' SMS sent to ' . $customerPhone . '.';
                    } else {
                        $smsNote = ' SMS failed: ' . $smsResult['message'];
                        $flashType = 'warning';
                    }
                } catch (Exception $e) {
                    error_log('Health score SMS error: ' . $e->getMessage());
                    $smsNote = ' SMS could not be sent.';
                    $flashType = 'warning';
                }
            } else {
                $smsNote = ' No phone number on file - SMS not sent.';
                $flashType = 'warning';
            }

            $_SESSION['flash_msg'] = 'Customer notified successfully.' . $smsNote;
            $_SESSION['flash_type'] = $flashType;
        } catch (PDOException $e) {
            $_SESSION['flash_msg'] = "Failed to notify customer";
            $_SESSION['flash_type'] = "danger";
            error_log("Notify customer error: " . $e->getMessage());
        }
    } else {
        $_SESSION['flash_msg'] = "Invalid notification request";
        $_SESSION['flash_type'] = "warning";
    }

    // Post/Redirect/Get: prevent duplicate notifications when the page is reloaded
    header("Location: admin_health_scores.php");
    exit;
}

// --- Fetch Available Services ---
$services = [];
try {
    $stmt = $pdo->query("SELECT id, service_name FROM services ORDER BY service_name");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching services: " . $e->getMessage());
}

// --- Fetch All Motorcycles with Health Data ---
$motorcycles = [];
try {
    $stmt = $pdo->query("
        SELECT m.*, 
               u.username as customer_name, u.email as customer_email,
               (SELECT COUNT(*) FROM maintenance_history WHERE motorcycle_id = m.id) as maintenance_count,
               (SELECT MAX(service_date) FROM maintenance_history WHERE motorcycle_id = m.id) as last_service_date
        FROM motorcycles m
        JOIN users u ON m.user_id = u.id
        ORDER BY m.brand ASC
    ");
    $motorcycles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate health scores for each motorcycle
    foreach ($motorcycles as &$motorcycle) {
        $factors = [];
        $motorcycle['health_data'] = getMotorcycleDashboardData($motorcycle['id']);
        $motorcycle['health_score'] = calculateHealthScore($motorcycle, $factors);
        $motorcycle['factors'] = $factors;
        $motorcycle['warranty_status'] = getWarrantyStatus($motorcycle);
        $motorcycle['maintenance_schedule'] = getMaintenanceSchedule($motorcycle);

        // Save health score with factors for tracking
        saveHealthScore($motorcycle['id'], $motorcycle['health_score'], $factors);
    }
} catch (PDOException $e) {
    error_log("Error fetching motorcycles: " . $e->getMessage());
}

// Group by customer
$grouped = [];
foreach ($motorcycles as $m) {
    $grouped[$m['customer_name']][] = $m;
}
ksort($grouped);

$pageTitle = 'Motorcycle Health Scores';
?>
<?php require 'admin_sidebar_template.php'; ?>

<style>
    body .main-content {
        background: #f8fafc !important;
        color: #111827 !important;
        padding-top: 80px !important;
    }
    body .main-content .hs-content { color: #111827; }
    body .main-content .hs-content .text-muted { color: #6b7280 !important; }

    .hs-stats-row {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    @media (max-width: 991px) {
        .hs-stats-row { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 576px) {
        .hs-stats-row { grid-template-columns: 1fr; }
    }
    .hs-stat-card {
        background: #ffffff;
        border-radius: 14px;
        padding: 1.25rem;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        text-align: center;
        transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
    }
    .hs-stat-card:hover {
        border-color: #d1d5db;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.06);
    }
    .hs-stat-number {
        font-size: 1.75rem;
        font-weight: 800;
        color: #FACC15;
    }
    .hs-stat-label {
        font-size: 0.75rem;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
    }

    .customer-row {
        background: #ffffff;
        border-radius: 14px;
        padding: 1rem 1.25rem;
        margin-bottom: 0.75rem;
        border: 1px solid #FACC15;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        display: flex;
        align-items: center;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
    }
    .customer-row:hover {
        background: #fffbeb;
        border-color: #3b82f6;
    }
    .customer-avatar {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: linear-gradient(135deg, #FACC15 0%, #3b82f6 100%);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1.1rem;
        margin-right: 1rem;
        flex-shrink: 0;
    }
    .customer-info { flex: 1; }
    .customer-name { font-weight: 700; font-size: 1rem; color: #111827; }
    .customer-count { font-size: 0.8rem; color: #6b7280; }
    .customer-badge {
        background: #0891b2;
        color: #fff;
        border-radius: 8px;
        padding: 0.35rem 0.65rem;
        font-weight: 700;
        font-size: 0.85rem;
        margin-right: 0.75rem;
    }
    .customer-chevron {
        color: #0891b2;
        transition: transform 0.3s ease;
        font-size: 0.9rem;
    }
    .customer-row[aria-expanded="true"] .customer-chevron i { transform: rotate(180deg); }

    .customer-search-section {
        position: relative;
        max-width: 360px;
    }
    .customer-search-wrapper {
        position: relative;
    }
    .customer-search-icon {
        position: absolute;
        left: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        color: #6b7280;
        z-index: 2;
        pointer-events: none;
        font-size: 0.85rem;
    }
    .customer-search-input {
        padding: 0.55rem 2.25rem;
        border-radius: 10px;
        border: 1px solid #FACC15;
        height: 40px;
        font-size: 0.85rem;
        background: #ffffff;
        color: #111827;
        transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
    }
    .customer-search-input:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.2);
    }
    .customer-search-clear {
        position: absolute;
        right: 0.65rem;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: #6b7280;
        cursor: pointer;
        padding: 0.15rem;
        font-size: 0.75rem;
        display: none;
        align-items: center;
        justify-content: center;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        
    }
    .customer-search-clear:hover {
        color: #ef4444;
        background: #f3f4f6;
    }
    .customer-suggestions {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 1050;
        margin-top: 0.35rem;
        border-radius: 10px;
        border: 1px solid #fdba74;
        box-shadow: 0 6px 18px rgba(0,0,0,0.08);
        max-height: 220px;
        overflow-y: auto;
        background: #ffffff;
        display: none;
    }
    .customer-suggestions .list-group-item {
        border: none;
        border-bottom: 1px solid #fed7aa;
        padding: 0.65rem 0.9rem;
        cursor: pointer;
        color: #111827;
        font-size: 0.82rem;
        
    }
    .customer-suggestions .list-group-item:last-child {
        border-bottom: none;
    }
    .customer-suggestions .list-group-item:hover,
    .customer-suggestions .list-group-item.active {
        background: #fffbeb;
        color: #EAB308;
    }
    .customer-suggestions .list-group-item strong {
        color: #FACC15;
        font-weight: 700;
    }
    .customer-search-info {
        font-size: 0.75rem;
        color: #6b7280;
        margin-top: 0.4rem;
        padding-left: 0.25rem;
        display: none;
    }

    .customer-cards {
        padding: 0 0.5rem 1rem 0.5rem;
    }

    .health-event-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-left: 4px solid #e2e8f0;
        border-radius: 14px;
        padding: 1rem;
        margin-bottom: 0.75rem;
        color: #111827;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
    }
    .health-event-card:hover {
        border-color: #d1d5db;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.06);
    }
    .health-event-card.cond-excellent { border-left-color: #10b981; }
    .health-event-card.cond-good { border-left-color: #3b82f6; }

    /* Low health score highlighting */
    .health-event-card.cond-fair {
        background: #fffdf5;
        border-color: #fde68a;
        border-left-color: #FACC15;
    }
    .health-event-card.cond-poor {
        background: #fef2f2;
        border-color: #fca5a5;
        border-left: 4px solid #ef4444;
        box-shadow: 0 0 0 1px #fecaca, 0 4px 14px rgba(239, 68, 68, 0.12);
    }
    .health-event-card.cond-poor:hover {
        border-color: #ef4444;
        box-shadow: 0 0 0 1px #fca5a5, 0 8px 20px rgba(239, 68, 68, 0.2);
    }
    .health-event-card.cond-poor .detail-score {
        animation: hs-pulse-red 1.6s ease-in-out infinite;
    }
    @keyframes hs-pulse-red {
        0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.45); }
        50% { box-shadow: 0 0 0 9px rgba(239, 68, 68, 0); }
    }
    .hs-stat-number.stat-danger { color: #ef4444; }

    .customer-row.customer-row-alert {
        border-color: #fca5a5;
        border-left: 4px solid #ef4444;
        background: #fffafa;
    }
    .customer-row.customer-row-alert:hover {
        background: #fef2f2;
        border-color: #ef4444;
    }
    .customer-alert-badge {
        background: #fef2f2;
        color: #b91c1c;
        border: 1px solid #fca5a5;
        border-radius: 8px;
        padding: 0.35rem 0.65rem;
        font-weight: 700;
        font-size: 0.75rem;
        margin-right: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        white-space: nowrap;
    }
    .customer-alert-badge i { font-size: 0.7rem; }

    .event-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        margin-bottom: 0.5rem;
    }
    .event-title-block {
        display: flex;
        align-items: flex-start;
        gap: 0.6rem;
    }
    .event-icon {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        background: #fffbeb;
        color: #FACC15;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
    }
    .event-icon.icon-good { background: transparent; color: #10b981; font-size: 1.4rem; }
    .event-icon.icon-attention { background: #fef2f2; color: #ef4444; }
    .event-icon.icon-needs { background: #fffbeb; color: #FACC15; }
    .event-title {
        font-weight: 700;
        font-size: 0.9rem;
        color: #111827;
        margin-bottom: 0.1rem;
    }
    .event-info-tag {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.7rem;
        color: #6b7280;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        padding: 0.05rem 0.35rem;
        border-radius: 4px;
        margin-left: 0.25rem;
    }
    .event-subtitle {
        font-size: 0.75rem;
        color: #6b7280;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .event-subtitle i { color: #9ca3af; }
    .event-status {
        font-size: 0.7rem;
        font-weight: 700;
        padding: 0.25rem 0.6rem;
        border-radius: 6px;
        white-space: nowrap;
    }
    .status-needs { background: #fffbeb; color: #EAB308; }
    .status-good { background: #f0fdf4; color: #15803d; }
    .status-attention { background: #fef2f2; color: #b91c1c; }

    .event-meta-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
        margin-bottom: 0.5rem;
    }
    @media (max-width: 576px) {
        .event-meta-row { grid-template-columns: 1fr; }
    }
    .event-meta {
        border-left: 1px solid #e2e8f0;
        padding-left: 0.5rem;
    }
    .event-meta:first-child { border-left: none; padding-left: 0; }
    .event-meta-label {
        font-size: 0.65rem;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 0.15rem;
    }
    .event-meta-value {
        font-size: 0.8rem;
        color: #111827;
        font-weight: 600;
    }

    .event-actions {
        display: flex;
        gap: 0.6rem;
        margin-top: 0.5rem;
    }
    .event-actions .btn {
        font-size: 0.75rem;
        padding: 0.4rem 0.75rem;
        border-radius: 8px;
        font-weight: 600;
    }
    .btn-coaching-notes {
        background: #fffbeb;
        border: 1px solid #fdba74;
        color: #EAB308;
    }
    .btn-coaching-notes:hover {
        background: #FDE047;
        color: #9a3412;
    }

    /* Modals */
    .modal-content {
        background: #ffffff;
        color: #111827;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
    }
    .modal-header {
        border-bottom: 1px solid #e2e8f0;
        padding: 1rem 1.25rem;
    }
    .modal-title { color: #111827; font-weight: 700; }
    .btn-close { filter: none; }
    .modal-body { padding: 1.25rem; }
    .modal-footer { border-top: 1px solid #e2e8f0; }

    .detail-gauge {
        position: relative;
        width: 100%;
        height: 10px;
        background: linear-gradient(to right, #ef4444 0%, #FACC15 25%, #3b82f6 50%, #10b981 75%, #10b981 100%);
        border-radius: 5px;
        margin: 0.5rem 0;
    }
    .detail-gauge-marker {
        position: absolute;
        top: -3px;
        width: 4px;
        height: 18px;
        background: #fff;
        border: 2px solid #d1d5db;
        border-radius: 2px;
        transform: translateX(-50%);
    }
    .detail-score {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 0.75rem;
        font-size: 1.5rem;
        font-weight: 700;
        color: white;
    }
    .score-excellent { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
    .score-good { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
    .score-fair { background: linear-gradient(135deg, #FACC15 0%, #3b82f6 100%); }
    .score-poor { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }

    .detail-row {
        display: flex;
        justify-content: space-between;
        font-size: 0.8rem;
        padding: 0.2rem 0;
        border-bottom: 1px solid #e2e8f0;
    }
    .detail-row:last-child { border-bottom: none; }
    .detail-label { color: #6b7280; }
    .detail-value { color: #111827; font-weight: 600; }

    .hs-moto-thumb {
        width: 64px;
        height: 44px;
        object-fit: contain;
        flex-shrink: 0;
        mix-blend-mode: multiply;
    }
    .hs-moto-location {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* Dark mode overrides */
    html[data-theme="dark"] .hs-content, html[data-theme="dark"] body .main-content .hs-content { color: #e2e8f0 !important; }
    html[data-theme="dark"] .hs-stat-card { border-color: rgba(255, 255, 255, 0.12); }
    html[data-theme="dark"] .hs-stat-label { color: #94a3b8; }
    html[data-theme="dark"] .customer-row { border-color: rgba(250, 204, 21, 0.4); }
    html[data-theme="dark"] .customer-row:hover { background: #22335a !important; border-color: #3b82f6; }
    html[data-theme="dark"] .customer-name { color: #e2e8f0; }
    html[data-theme="dark"] .customer-count { color: #94a3b8; }
    html[data-theme="dark"] .customer-row.customer-row-alert { background: rgba(239, 68, 68, 0.08) !important; border-color: rgba(239, 68, 68, 0.5); }
    html[data-theme="dark"] .customer-row.customer-row-alert:hover { background: rgba(239, 68, 68, 0.12) !important; }
    html[data-theme="dark"] .customer-alert-badge { background: rgba(239, 68, 68, 0.15); color: #f87171; border-color: rgba(239, 68, 68, 0.4); }
    html[data-theme="dark"] .health-event-card.cond-fair { background: #1a2b4f; border-color: rgba(250, 204, 21, 0.4); }
    html[data-theme="dark"] .health-event-card.cond-poor { background: #1a1525; border-color: rgba(239, 68, 68, 0.5); }
    html[data-theme="dark"] .event-icon { background: rgba(250, 204, 21, 0.15); }
    html[data-theme="dark"] .event-icon.icon-attention { background: rgba(239, 68, 68, 0.15); }
    html[data-theme="dark"] .event-icon.icon-needs { background: rgba(250, 204, 21, 0.15); }
    html[data-theme="dark"] .event-title { color: #e2e8f0; }
    html[data-theme="dark"] .event-info-tag { background: #16233f; border-color: rgba(255, 255, 255, 0.12); color: #94a3b8; }
    html[data-theme="dark"] .event-subtitle, html[data-theme="dark"] .event-subtitle i { color: #94a3b8; }
    html[data-theme="dark"] .status-needs { background: rgba(250, 204, 21, 0.15); color: #FDE047; }
    html[data-theme="dark"] .status-good { background: rgba(16, 185, 129, 0.15); color: #34d399; }
    html[data-theme="dark"] .status-attention { background: rgba(239, 68, 68, 0.15); color: #f87171; }
    html[data-theme="dark"] .event-meta { border-left-color: rgba(255, 255, 255, 0.12); }
    html[data-theme="dark"] .event-meta-label { color: #94a3b8; }
    html[data-theme="dark"] .event-meta-value { color: #e2e8f0; }
    html[data-theme="dark"] .btn-coaching-notes { background: rgba(250, 204, 21, 0.12); border-color: rgba(250, 204, 21, 0.4); color: #FDE047; }
    html[data-theme="dark"] .btn-coaching-notes:hover { background: rgba(250, 204, 21, 0.25); color: #FDE047; }
    html[data-theme="dark"] .customer-suggestions .list-group-item { border-bottom-color: rgba(255, 255, 255, 0.09); }
    html[data-theme="dark"] .customer-suggestions .list-group-item:hover,
    html[data-theme="dark"] .customer-suggestions .list-group-item.active { background: rgba(250, 204, 21, 0.12); color: #FDE047; }
    html[data-theme="dark"] .detail-row { border-bottom-color: rgba(255, 255, 255, 0.09); }
    html[data-theme="dark"] .detail-label { color: #94a3b8; }
    html[data-theme="dark"] .detail-value { color: #e2e8f0; }
    html[data-theme="dark"] .detail-gauge-marker { background: #1a2b4f; border-color: #475569; }
    html[data-theme="dark"] .hs-moto-thumb { mix-blend-mode: normal; }

    /* Inline-style fixes (readonly inputs, recommendation boxes) */
    html[data-theme="dark"] .main-content [style*="color: #111827"],
    html[data-theme="dark"] .main-content [style*="color:#111827"],
    html[data-theme="dark"] .modal [style*="color: #111827"],
    html[data-theme="dark"] .modal [style*="color:#111827"] { color: #e2e8f0 !important; }
    html[data-theme="dark"] .main-content [style*="background: #fffbeb"],
    html[data-theme="dark"] .main-content [style*="background:#fffbeb"] { background: rgba(250, 204, 21, 0.08) !important; }
    html[data-theme="dark"] .main-content [style*="background: #fee2e2"],
    html[data-theme="dark"] .main-content [style*="background:#fee2e2"] { background: rgba(239, 68, 68, 0.1) !important; }
    html[data-theme="dark"] .modal [style*="background: #ffffff"],
    html[data-theme="dark"] .modal [style*="background:#ffffff"] { background: #16233f !important; }
</style>

<div class="container-fluid hs-content">
    <?php if ($msg): ?>
    <div class="row mb-3">
        <div class="col-12">
            <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show" role="alert">
                <?= $msg ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Overall Health Statistics -->
    <div class="hs-stats-row mb-4">
        <div class="hs-stat-card">
            <div class="hs-stat-number"><?= count($motorcycles) ?></div>
            <div class="hs-stat-label">Total Motorcycles</div>
        </div>
        <div class="hs-stat-card">
            <div class="hs-stat-number">
                <?= !empty($motorcycles) ? round(array_sum(array_column($motorcycles, 'health_score')) / count($motorcycles)) : 0 ?>
            </div>
            <div class="hs-stat-label">Avg Health Score</div>
        </div>
        <div class="hs-stat-card">
            <div class="hs-stat-number">
                <?= count(array_filter($motorcycles, function($m) { return $m['maintenance_schedule']['is_overdue']; })) ?>
            </div>
            <div class="hs-stat-label">Overdue Maintenance</div>
        </div>
        <div class="hs-stat-card">
            <div class="hs-stat-number stat-danger">
                <?= count(array_filter($motorcycles, function($m) { return $m['health_score'] < 40; })) ?>
            </div>
            <div class="hs-stat-label">Poor Condition</div>
        </div>
    </div>

    <!-- Customer Search -->
    <div class="customer-search-section mb-4">
        <div class="customer-search-wrapper">
            <i class="bi bi-search customer-search-icon"></i>
            <input type="text" class="form-control customer-search-input" id="customerSearch" placeholder="Search customer by name..." autocomplete="off">
            <button type="button" class="customer-search-clear" id="clearCustomerSearch" aria-label="Clear search">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <ul class="customer-suggestions list-group" id="customerSuggestions"></ul>
        <div class="customer-search-info" id="customerSearchInfo">
            <span id="customerSearchCount">0</span> customer(s) found
        </div>
    </div>

    <!-- Customer Grouped Cards -->
    <div class="customer-list">
        <?php if (empty($grouped)): ?>
            <div class="text-center py-5">
                <i class="fas fa-motorcycle fa-4x text-muted mb-3"></i>
                <h4 class="text-muted">No Motorcycles Found</h4>
                <p class="text-muted">No motorcycles are registered in the system.</p>
            </div>
        <?php else: ?>
            <?php $customerIndex = 0; ?>
            <?php foreach ($grouped as $customerName => $customerMotorcycles): ?>
                <?php
                $customerId = 'customer-' . $customerIndex;
                $initial = strtoupper(substr($customerName, 0, 1));
                $count = count($customerMotorcycles);
                $lowCount = count(array_filter($customerMotorcycles, function($m) { return $m['health_score'] < 40; }));
                $expanded = $customerIndex === 0 ? 'true' : 'false';
                $showClass = $customerIndex === 0 ? 'show' : '';
                ?>
                <div class="customer-row<?= $lowCount > 0 ? ' customer-row-alert' : '' ?>" data-bs-toggle="collapse" data-bs-target="#<?= $customerId ?>" aria-expanded="<?= $expanded ?>">
                    <div class="customer-avatar"><?= $initial ?></div>
                    <div class="customer-info">
                        <div class="customer-name"><?= htmlspecialchars($customerName) ?></div>
                        <div class="customer-count"><?= $count ?> motorcycle<?= $count != 1 ? 's' : '' ?></div>
                    </div>
                    <?php if ($lowCount > 0): ?>
                    <div class="customer-alert-badge" title="<?= $lowCount ?> motorcycle(s) in critical condition">
                        <i class="fas fa-exclamation-triangle"></i> <?= $lowCount ?> at risk
                    </div>
                    <?php endif; ?>
                    <div class="customer-badge"><?= $count ?></div>
                    <div class="customer-chevron"><i class="fas fa-chevron-down"></i></div>
                </div>
                <div class="collapse <?= $showClass ?>" id="<?= $customerId ?>">
                    <div class="customer-cards">
                        <?php foreach ($customerMotorcycles as $mIndex => $motorcycle): ?>
                            <?php
                            $health_score = $motorcycle['health_score'];
                            $condition = getHealthScoreCondition($health_score);
                            $schedule = $motorcycle['maintenance_schedule'];
                            $warranty = $motorcycle['warranty_status'];
                            $factors = $motorcycle['factors'] ?? [];

                            if ($schedule['is_overdue']) {
                                $eventTitle = 'Overdue Service';
                                $eventIcon = 'fa-wrench';
                                $statusClass = 'status-attention';
                                $statusText = 'Needs Service';
                                $iconClass = 'icon-attention';
                            } elseif ($health_score < 40) {
                                $eventTitle = 'Critical Condition';
                                $eventIcon = 'fa-exclamation-triangle';
                                $statusClass = 'status-attention';
                                $statusText = 'Needs Service';
                                $iconClass = 'icon-attention';
                            } elseif ($health_score < 60) {
                                $eventTitle = 'Needs Attention';
                                $eventIcon = 'fa-exclamation-circle';
                                $statusClass = 'status-needs';
                                $statusText = 'Needs Maintenance';
                                $iconClass = 'icon-needs';
                            } else {
                                $eventTitle = 'Health Status';
                                $eventIcon = 'fa-check-circle';
                                $statusClass = 'status-good';
                                $statusText = 'Healthy';
                                $iconClass = 'icon-good';
                            }

                            $eventTime = $schedule['next_date'] ? date('M j, Y', strtotime($schedule['next_date'])) : 'Not scheduled';
                            $location = htmlspecialchars($motorcycle['brand'] . ' ' . $motorcycle['model']) . ', ' . $motorcycle['year_model'];
                            $motoImg = !empty($motorcycle['image']) ? $motorcycle['image'] : ($modelImages[$motorcycle['model']] ?? null);
                            $cardId = $customerId . '-card-' . $mIndex;
                            $notesId = $customerId . '-notes-' . $mIndex;
                            $notifyId = $customerId . '-notify-' . $mIndex;
                            ?>
                            <div class="health-event-card cond-<?= strtolower($condition) ?>" id="<?= $cardId ?>">
                                <div class="event-header">
                                    <div class="event-title-block">
                                        <?php if ($iconClass !== 'icon-good'): ?>
                                            <div class="event-icon <?= $iconClass ?>"><i class="fas <?= $eventIcon ?>"></i></div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="event-title">
                                                <?php if ($iconClass === 'icon-good'): ?><i class="fas <?= $eventIcon ?>" style="color: #10b981; margin-right: 0.35rem; font-size: 1.1rem;"></i><?php endif; ?>
                                                <?= $eventTitle ?>
                                                <span class="event-info-tag"><i class="fas fa-info-circle"></i> Info</span>
                                            </div>
                                            <div class="event-subtitle">
                                                <i class="fas fa-user"></i> <?= htmlspecialchars($customerName) ?> &bull; <?= htmlspecialchars($motorcycle['plate_number']) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="event-status <?= $statusClass ?>"><?= $statusText ?></div>
                                </div>

                                <div class="event-meta-row">
                                    <div class="event-meta">
                                        <div class="event-meta-label">Event Time</div>
                                        <div class="event-meta-value"><?= $eventTime ?></div>
                                    </div>
                                    <div class="event-meta">
                                        <div class="event-meta-label">Location</div>
                                        <div class="event-meta-value hs-moto-location">
                                            <?php if ($motoImg): ?>
                                                <img src="<?= htmlspecialchars($motoImg) ?>" alt="" class="hs-moto-thumb">
                                            <?php endif; ?>
                                            <span><?= $location ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="card-details">
                                    <div class="d-flex align-items-center gap-3 mb-3">
                                        <div class="detail-score score-<?= strtolower($condition) ?>" style="margin:0;width:44px;height:44px;font-size:1rem;"><?= $health_score ?></div>
                                        <div>
                                            <div class="event-meta-label">Health Score</div>
                                            <div class="event-meta-value"><?= $condition ?></div>
                                        </div>
                                    </div>

                                    <div class="detail-gauge mb-3">
                                        <div class="detail-gauge-marker" style="left: <?= $health_score ?>%"></div>
                                    </div>

                                    <h6 class="mb-1" style="font-size: 0.7rem; color: #6b7280; font-weight: 700;">Score Breakdown</h6>
                                    <div class="detail-row"><span class="detail-label">Maintenance (30%)</span><span class="detail-value"><?= $factors['maintenance_score'] ?? 0 ?>/100</span></div>
                                    <div class="detail-row"><span class="detail-label">Mileage (20%)</span><span class="detail-value"><?= $factors['mileage_score'] ?? 0 ?>/100</span></div>
                                    <div class="detail-row"><span class="detail-label">Inspection (35%)</span><span class="detail-value"><?= $factors['inspection_score'] ?? 0 ?>/100</span></div>
                                    <div class="detail-row"><span class="detail-label">Overdue (15%)</span><span class="detail-value"><?= $factors['overdue_score'] ?? 0 ?>/100</span></div>

                                    <h6 class="mt-2 mb-1" style="font-size: 0.7rem; color: #6b7280; font-weight: 700;">Maintenance & Warranty</h6>
                                    <div class="detail-row"><span class="detail-label">Next Service</span><span class="detail-value"><?= $schedule['next_date'] ? date('M j, Y', strtotime($schedule['next_date'])) : 'Not set' ?></span></div>
                                    <div class="detail-row">
                                        <span class="detail-label">Status</span>
                                        <span class="detail-value <?= $schedule['is_overdue'] ? 'text-danger' : 'text-success' ?>">
                                            <?= $schedule['is_overdue'] ? 'Overdue by ' . $schedule['days_overdue'] . ' days' : ($schedule['days_until'] . ' days remaining') ?>
                                        </span>
                                    </div>
                                    <div class="detail-row"><span class="detail-label">Warranty</span><span class="detail-value <?= $warranty['is_valid'] ? 'text-success' : 'text-danger' ?>"><?= $warranty['message'] ?></span></div>

                                    <div class="mt-2 p-2" style="background: <?= $health_score < 40 ? '#fee2e2' : '#fffbeb' ?>; border-radius: 8px; border-left: 3px solid <?= $health_score < 40 ? '#ef4444' : '#FACC15' ?>; font-size: 0.8rem; color: #111827;">
                                        <?= getHealthScoreRecommendation($health_score) ?>
                                    </div>
                                </div>

                                <div class="event-actions">
                                    <?php if ($health_score < 50): ?>
                                    <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#<?= $notifyId ?>">
                                        <i class="fas fa-bell me-1"></i> Notify Customer
                                    </button>
                                    <?php else: ?>
                                    <button class="btn btn-coaching-notes" data-bs-toggle="modal" data-bs-target="#<?= $notesId ?>">
                                        <i class="fas fa-clipboard me-1"></i> Coaching Notes
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Coaching Notes Modal -->
                            <div class="modal fade" id="<?= $notesId ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Coaching Notes - <?= htmlspecialchars($motorcycle['brand'] . ' ' . $motorcycle['model']) ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <form onsubmit="event.preventDefault(); Swal.fire({icon:'success',title:'Saved',text:'Coaching notes saved.',timer:1500,showConfirmButton:false}); bootstrap.Modal.getInstance(document.getElementById('<?= $notesId ?>')).hide();">
                                                <div class="mb-3">
                                                    <label class="form-label" style="color: #6b7280; font-size: 0.85rem;">Customer</label>
                                                    <input type="text" class="form-control" style="background: #ffffff; border-color: #e2e8f0; color: #111827;" value="<?= htmlspecialchars($customerName) ?>" readonly>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label" style="color: #6b7280; font-size: 0.85rem;">Notes</label>
                                                    <textarea class="form-control" rows="4" style="background: #ffffff; border-color: #e2e8f0; color: #111827;" placeholder="Enter service or coaching notes..."></textarea>
                                                </div>
                                                <div class="text-end">
                                                    <button type="submit" class="btn" style="background: #FACC15; color: #0f172a; font-weight: 600;">Save Notes</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Notify Customer Modal -->
                            <div class="modal fade" id="<?= $notifyId ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Notify Customer - <?= htmlspecialchars($motorcycle['brand'] . ' ' . $motorcycle['model']) ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="notify_customer" value="1">
                                                <input type="hidden" name="customer_id" value="<?= (int) ($motorcycle['user_id'] ?? 0) ?>">
                                                <input type="hidden" name="motorcycle_id" value="<?= (int) $motorcycle['id'] ?>">
                                                <input type="hidden" name="health_score" value="<?= (int) $health_score ?>">
                                                <div class="mb-3">
                                                    <label class="form-label" style="color: #6b7280; font-size: 0.85rem;">Customer</label>
                                                    <input type="text" class="form-control" style="background: #ffffff; border-color: #e2e8f0; color: #111827;" value="<?= htmlspecialchars($customerName) ?>" readonly>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label" style="color: #6b7280; font-size: 0.85rem;">What maintenance is needed?</label>
                                                    <div class="border rounded p-2" style="background: #ffffff; border-color: #e2e8f0; max-height: 160px; overflow-y: auto;">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" name="maintenance_items[]" value="General Inspection" id="notify-<?= $notifyId ?>-general" checked style="accent-color: #FACC15;">
                                                            <label class="form-check-label" for="notify-<?= $notifyId ?>-general" style="color: #111827; font-size: 0.85rem;">General Inspection</label>
                                                        </div>
                                                        <?php foreach ($services as $service): ?>
                                                            <?php $serviceCheckId = 'notify-' . $notifyId . '-' . (int) $service['id']; ?>
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="checkbox" name="maintenance_items[]" value="<?= htmlspecialchars($service['service_name']) ?>" id="<?= $serviceCheckId ?>" style="accent-color: #FACC15;">
                                                                <label class="form-check-label" for="<?= $serviceCheckId ?>" style="color: #111827; font-size: 0.85rem;"><?= htmlspecialchars($service['service_name']) ?></label>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label" style="color: #6b7280; font-size: 0.85rem;">Additional notes</label>
                                                    <textarea class="form-control" name="recommendation" rows="2" style="background: #ffffff; border-color: #e2e8f0; color: #111827;" placeholder="Optional extra details..."><?= htmlspecialchars(getHealthScoreRecommendation($health_score), ENT_QUOTES, 'UTF-8') ?></textarea>
                                                </div>
                                                <div class="alert alert-warning" style="font-size: 0.8rem;">
                                                    <i class="fas fa-info-circle me-1"></i> Current health score: <?= $health_score ?>/100
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger" style="font-weight: 600;">
                                                    <i class="fas fa-bell me-1"></i> Send Notification
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php $customerIndex++; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Customer search with autocomplete
(function() {
    function escapeRegex(text) {
        return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function initCustomerSearch() {
        if (typeof bootstrap === 'undefined' || typeof document === 'undefined') {
            setTimeout(initCustomerSearch, 50);
            return;
        }

        const searchInput = document.getElementById('customerSearch');
        const clearBtn = document.getElementById('clearCustomerSearch');
        const suggestions = document.getElementById('customerSuggestions');
        const searchInfo = document.getElementById('customerSearchInfo');
        const searchCount = document.getElementById('customerSearchCount');
        const customerList = document.querySelector('.customer-list');

        if (!searchInput || !customerList) return;

        const customers = Array.from(document.querySelectorAll('.customer-row')).map(function(row) {
            const nameEl = row.querySelector('.customer-name');
            const collapseTarget = row.getAttribute('data-bs-target');
            const collapseEl = collapseTarget ? document.querySelector(collapseTarget) : null;
            return {
                row: row,
                collapseEl: collapseEl,
                name: nameEl ? nameEl.textContent.trim() : ''
            };
        });

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function getMatchRegex(query) {
            return new RegExp('(^|[ _-])(' + escapeRegex(query) + ')', 'gi');
        }

        function updateSuggestions(query) {
            suggestions.innerHTML = '';
            if (!query) {
                suggestions.style.display = 'none';
                return;
            }

            const q = query.toLowerCase();
            const regex = new RegExp('(^|[ _-])' + escapeRegex(q), 'i');
            const matches = customers.filter(function(c) {
                return regex.test(c.name.toLowerCase());
            });

            if (matches.length === 0) {
                suggestions.style.display = 'none';
                return;
            }

            matches.forEach(function(c) {
                const li = document.createElement('li');
                li.className = 'list-group-item';
                const highlighted = escapeHtml(c.name).replace(getMatchRegex(query), '$1<strong>$2</strong>');
                li.innerHTML = highlighted;
                li.addEventListener('click', function() {
                    searchInput.value = c.name;
                    filterCustomers(c.name, true);
                    suggestions.style.display = 'none';
                });
                suggestions.appendChild(li);
            });

            suggestions.style.display = 'block';
        }

        function filterCustomers(query, exact) {
            const q = query.toLowerCase().trim();
            let matchCount = 0;
            let regex = null;

            if (q && !exact) {
                regex = new RegExp('(^|[ _-])' + escapeRegex(q), 'i');
            }

            customers.forEach(function(c) {
                const nameLower = c.name.toLowerCase();
                const isMatch = !q || (exact ? nameLower === q : regex.test(nameLower));

                if (isMatch) {
                    c.row.style.display = '';
                    matchCount++;
                    if (c.collapseEl) {
                        const bsCollapse = bootstrap.Collapse.getOrCreateInstance(c.collapseEl);
                        bsCollapse.show();
                    }
                    c.row.setAttribute('aria-expanded', 'true');
                } else {
                    c.row.style.display = 'none';
                    if (c.collapseEl) {
                        const bsCollapse = bootstrap.Collapse.getOrCreateInstance(c.collapseEl);
                        bsCollapse.hide();
                    }
                    c.row.setAttribute('aria-expanded', 'false');
                }
            });

            if (!q) {
                customers.forEach(function(c, index) {
                    if (c.collapseEl) {
                        const bsCollapse = bootstrap.Collapse.getOrCreateInstance(c.collapseEl);
                        if (index === 0) {
                            bsCollapse.show();
                            c.row.setAttribute('aria-expanded', 'true');
                        } else {
                            bsCollapse.hide();
                            c.row.setAttribute('aria-expanded', 'false');
                        }
                    }
                });
                searchInfo.style.display = 'none';
            } else {
                searchCount.textContent = matchCount;
                searchInfo.style.display = 'block';
            }

            clearBtn.style.display = q ? 'flex' : 'none';

            const noResults = document.getElementById('noCustomerResults');
            if (matchCount === 0 && q) {
                if (!noResults) {
                    const div = document.createElement('div');
                    div.id = 'noCustomerResults';
                    div.className = 'text-center py-5';
                    div.innerHTML = '<i class="bi bi-search text-muted mb-3" style="font-size: 3rem;"></i><h4 class="text-muted">No Customers Found</h4><p class="text-muted">No customer names match "<strong>' + escapeHtml(query) + '</strong>"</p>';
                    customerList.prepend(div);
                }
            } else if (noResults) {
                noResults.remove();
            }
        }

        searchInput.addEventListener('input', function() {
            const query = this.value.trim();
            updateSuggestions(query);
            filterCustomers(query, false);
        });

        searchInput.addEventListener('focus', function() {
            if (this.value.trim()) {
                updateSuggestions(this.value.trim());
            }
        });

        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                suggestions.style.display = 'none';
            } else if (e.key === 'Escape') {
                suggestions.style.display = 'none';
            }
        });

        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !suggestions.contains(e.target)) {
                suggestions.style.display = 'none';
            }
        });

        clearBtn.addEventListener('click', function() {
            searchInput.value = '';
            filterCustomers('');
            suggestions.style.display = 'none';
            searchInput.focus();
        });
    }

    if (document.readyState === 'complete') {
        initCustomerSearch();
    } else {
        window.addEventListener('load', initCustomerSearch);
    }
})();
</script>

<?php require 'admin_footer.php'; ?>
