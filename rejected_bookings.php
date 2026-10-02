<?php
session_start();
require 'db.php'; 

header("Location: bookings_status.php?tab=rejected");
exit;

// Security check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// --- Helper function to get ALL assigned mechanic names from joining table ---
function get_mechanic_names($pdo, $booking_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT m.name 
            FROM booking_mechanics bm
            JOIN mechanics m ON bm.mechanic_id = m.id
            WHERE bm.booking_id = ?
        ");
        $stmt->execute([$booking_id]);
        $mechanic_names = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
        
        if (empty($mechanic_names)) {
            $stmt_fallback = $pdo->prepare("
                SELECT m.name 
                FROM bookings b
                JOIN mechanics m ON b.mechanic_id = m.id
                WHERE b.id = ?
            ");
            $stmt_fallback->execute([$booking_id]);
            $fallback_name = $stmt_fallback->fetchColumn();
            return $fallback_name ? htmlspecialchars($fallback_name) : 'N/A';
        }

        return htmlspecialchars(implode(', ', $mechanic_names));
    } catch (PDOException $e) {
        error_log("Mechanic fetching error for Booking ID {$booking_id}: " . $e->getMessage());
        return "Error fetching mechanics";
    }
}

function get_service_names($pdo, $service_ids_json) {
    $ids = json_decode($service_ids_json, true);
    if (empty($ids) || !is_array($ids)) return "N/A";
    $placeholders = rtrim(str_repeat('?,', count($ids)), ',');
    try {
        $stmt = $pdo->prepare("SELECT service_name FROM services WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        return implode(', ', array_column($stmt->fetchAll(), 'service_name'));
    } catch (PDOException $e) {
        return "Error";
    }
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


$errorMessage = "";
$rejectedBookings = [];

try {
    $stmt = $pdo->prepare("
        SELECT 
            b.id, b.service_ids, b.schedule_date, b.schedule_start_time, b.schedule_end_time, b.total_price, b.status,
            u.username, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
            v.brand, v.model, v.year_model, v.plate_number
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        LEFT JOIN motorcycles v ON b.vehicle_id = v.id 
        WHERE b.status IN ('rejected', 'deposit_rejected')
        ORDER BY b.schedule_date DESC, b.schedule_start_time DESC
    ");
    $stmt->execute();
    $rejectedBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $errorMessage = "Database Error: " . $e->getMessage();
}
$pageTitle = 'Rejected Bookings';
?>

<?php require 'admin_sidebar_template.php'; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<style>
    :root {
        --bg-dark: #f8fafc;
        --card-bg: #f8fafc;
        --card-border: rgba(226, 232, 240, 0.9);
        --accent-cyan: #06b6d4;
        --accent-orange: #f59e0b;
        --accent-gold: #d4af37;
        --accent-green: #10b981;
        --accent-red: #ef4444;
        --text-main: #0f172a;
        --text-muted: #475569;
    }

    body {
        background:
            radial-gradient(circle at 10% 10%, rgba(239, 68, 68, 0.06) 0%, transparent 35%),
            radial-gradient(circle at 90% 90%, rgba(245, 158, 11, 0.06) 0%, transparent 35%),
            var(--bg-dark) !important;
        font-family: 'Plus Jakarta Sans', sans-serif !important;
        color: var(--text-main) !important;
    }

    .top-header {
        position: fixed !important;
        top: 0;
        left: var(--sidebar-width);
        right: 0;
        z-index: 100;
        background: rgba(9, 13, 22, 0.8) !important;
        backdrop-filter: blur(12px);
        border-bottom: 1px solid var(--card-border);
    }

    .main-content {
        padding-top: 90px !important;
        padding-left: 28px !important;
        padding-right: 28px !important;
        background: var(--bg-dark) !important;
        min-height: 100vh;
    }

    .bg-animation,
    .floating-tools { display: none !important; }

    .rejected-page {
        max-width: 1200px;
        margin: 0 auto;
        width: 100%;
        display: flex;
        flex-direction: column;
        height: calc(100vh - 110px);
        min-height: 500px;
    }

    /* Header */
    .rb-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 0.75rem;
        flex-shrink: 0;
        flex-wrap: wrap;
    }
    .rb-page-title {
        font-size: 1.4rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: var(--text-main);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .rb-page-title i { color: var(--accent-red); }
    .rb-page-subtitle { font-size: 0.78rem; color: var(--text-muted); margin-top: 0.15rem; }
    .rb-count-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        background: rgba(0, 0, 0, 0.03);
        border: 1px solid var(--card-border);
        border-radius: 99px;
        padding: 0.35rem 0.8rem;
        font-size: 0.75rem;
        font-weight: 700;
        color: var(--text-main);
    }
    .rb-count-badge .dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--accent-red);
        box-shadow: 0 0 10px var(--accent-red);
    }
    .rb-back-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        background: rgba(0, 0, 0, 0.03);
        border: 1px solid var(--card-border);
        border-radius: 8px;
        padding: 0.4rem 0.75rem;
        font-size: 0.75rem;
        font-weight: 700;
        color: var(--text-main);
        text-decoration: none;
        transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
    }
    .rb-back-btn:hover { background: rgba(0, 0, 0, 0.08); color: var(--text-main); }
    .rb-back-btn i { width: 13px; height: 13px; }

    /* Main Card */
    .rb-main-card {
        flex: 1;
        display: flex;
        flex-direction: column;
        background: var(--card-bg);
        border: 1px solid var(--card-border);
        border-radius: 16px;
        backdrop-filter: blur(24px);
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        overflow: hidden;
        min-height: 0;
    }

    .rb-pane-layout {
        display: grid;
        grid-template-columns: 280px 1fr;
        flex: 1;
        min-height: 0;
    }

    /* Master List */
    .rb-master-pane {
        border-right: 1px solid var(--card-border);
        overflow-y: auto;
        background: var(--card-bg);
        padding: 0.5rem;
        scrollbar-width: none;
    }
    .rb-master-pane::-webkit-scrollbar { display: none; }
    .rb-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.5rem; }
    .rb-list-item {
        display: block;
        background: var(--card-bg);
        border: 1px solid var(--card-border);
        border-radius: 12px;
        padding: 0.7rem 0.85rem;
        color: var(--text-main);
        text-decoration: none;
        transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
        cursor: pointer;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
    }
    .rb-list-item:hover { border-color: rgba(245, 158, 11, 0.35); }
    .rb-list-item.active {
        background: #fff7ed;
        border-left: 3px solid var(--accent-orange);
        box-shadow: 0 0 22px rgba(245, 158, 11, 0.12);
    }
    .rb-list-header { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem; }
    .rb-list-id {
        font-size: 0.75rem;
        font-weight: 800;
        color: var(--accent-red);
    }
    .rb-list-date {
        font-size: 0.68rem;
        color: var(--text-muted);
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }
    .rb-list-date i { width: 11px; height: 11px; }
    .rb-list-customer {
        font-size: 0.85rem;
        font-weight: 800;
        color: var(--text-main);
        text-transform: uppercase;
        letter-spacing: 0.02em;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Detail Pane */
    .rb-detail-pane {
        position: relative;
        padding: 0.9rem;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        scrollbar-width: none;
    }
    .rb-detail-pane::-webkit-scrollbar { display: none; }
    .rb-detail-pane > * { position: relative; z-index: 1; }
    .rb-empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-muted); }
    .rb-empty-state i { width: 48px; height: 48px; margin-bottom: 1rem; opacity: 0.4; }
    .rb-detail-content { display: none; }
    .rb-detail-content.active { display: flex; flex-direction: column; }

    .rb-detail-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 0.75rem;
        padding: 0.55rem 0.8rem;
        background: #f1f5f9;
        border: 1px solid var(--card-border);
        border-radius: 12px;
        flex-wrap: wrap;
    }
    .rb-detail-header-left { display: flex; align-items: center; gap: 0.7rem; }
    .rb-detail-id {
        font-size: 1.15rem;
        font-weight: 800;
        color: var(--text-main);
    }
    .rb-status-badge {
        padding: 0.2rem 0.55rem;
        border-radius: 6px;
        font-size: 0.62rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        background: rgba(239, 68, 68, 0.12);
        color: #b91c1c;
        border: 1px solid rgba(239, 68, 68, 0.35);
        box-shadow: 0 0 10px rgba(239, 68, 68, 0.2);
    }
    .rb-detail-actions { display: flex; align-items: center; gap: 0.4rem; }
    .rb-action-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        background: rgba(0, 0, 0, 0.03);
        border: 1px solid var(--card-border);
        border-radius: 8px;
        padding: 0.4rem 0.7rem;
        font-size: 0.72rem;
        font-weight: 800;
        color: var(--text-main);
        text-decoration: none;
        transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
    }
    .rb-action-btn:hover { background: rgba(0, 0, 0, 0.08); color: var(--text-main); }
    .rb-action-btn i { width: 12px; height: 12px; }
    .rb-action-btn.manage { background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.3); color: #b45309; box-shadow: 0 0 12px rgba(245, 158, 11, 0.1); }
    .rb-action-btn.manage:hover { background: rgba(245, 158, 11, 0.2); }

    .rb-section-title {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.62rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--text-muted);
        margin-bottom: 0.35rem;
    }
    .rb-section-title i { width: 13px; height: 13px; color: var(--accent-cyan); }

    .rb-detail-grid {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        flex: 1;
    }
    .rb-detail-section {
        background: var(--card-bg);
        border: 1px solid var(--card-border);
        border-radius: 12px;
        padding: 0.75rem;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        width: 100%;
    }
    .rb-detail-section.rejection { padding: 0; }
    .rb-detail-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        padding: 0.2rem 0;
        border-bottom: 1px solid var(--card-border);
    }
    .rb-detail-row:last-child { border-bottom: none; }
    .rb-detail-row.align-top { align-items: flex-start; }
    .rb-detail-label {
        font-size: 0.65rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        white-space: nowrap;
    }
    .rb-detail-value {
        font-size: 0.8rem;
        font-weight: 700;
        color: var(--text-main);
        text-align: right;
        max-width: 65%;
    }
    .rb-detail-value.red { color: var(--accent-red); }
    .rb-detail-value.orange { color: var(--accent-orange); }
    .rb-detail-value.wrap { text-align: left; max-width: 100%; width: 100%; margin-top: 0.15rem; line-height: 1.4; }
    .rb-detail-block {
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--text-main);
        line-height: 1.4;
        word-break: break-word;
    }

    /* Rejection Card */
    .rb-rejection-card {
        position: relative;
        height: 100%;
        min-height: 155px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        background: #fff1f2;
        border: 1px solid rgba(239, 68, 68, 0.35);
        border-radius: 12px;
        padding: 0.75rem;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        overflow: hidden;
    }
    .rb-rejection-card::before { display: none; }
    .rb-rejection-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        z-index: 1;
    }
    .rb-rejection-title { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #b91c1c; display: flex; align-items: center; gap: 0.35rem; }
    .rb-rejection-title i { width: 14px; height: 14px; color: #b91c1c; }
    .rb-rejection-status {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        font-size: 0.62rem;
        font-weight: 800;
        color: #b91c1c;
        background: rgba(239, 68, 68, 0.12);
        border: 1px solid rgba(239, 68, 68, 0.35);
        border-radius: 99px;
        padding: 0.15rem 0.5rem;
    }
    .rb-rejection-status i { width: 10px; height: 10px; }
    .rb-rejection-rows { z-index: 1; }
    .rb-rejection-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        padding: 0.25rem 0;
    }
    .rb-rejection-row.status { border-bottom: 1px solid var(--card-border); padding-bottom: 0.35rem; margin-bottom: 0.25rem; }
    .rb-rejection-row.align-top { align-items: flex-start; }
    .rb-rejection-label { font-size: 0.65rem; color: #ef4444; text-transform: uppercase; letter-spacing: 0.05em; }
    .rb-rejection-value { font-size: 0.8rem; font-weight: 700; color: var(--text-main); text-align: right; max-width: 65%; }
    .rb-rejection-value.wrap { text-align: left; max-width: 100%; width: 100%; margin-top: 0.2rem; line-height: 1.4; }
    .rb-rejection-value.lg { font-size: 1.1rem; color: #b91c1c; }

    .rb-rejected-box {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        background: rgba(239, 68, 68, 0.1);
        border: 1px solid rgba(239, 68, 68, 0.4);
        border-radius: 10px;
        padding: 0.55rem;
        color: #b91c1c;
        font-weight: 800;
        font-size: 0.85rem;
        margin-top: 0.75rem;
        box-shadow: 0 0 25px rgba(239, 68, 68, 0.25);
    }
    .rb-rejected-box i { width: 16px; height: 16px; }

    .rb-error {
        background: rgba(239, 68, 68, 0.1);
        border: 1px solid rgba(239, 68, 68, 0.2);
        color: #b91c1c;
        border-radius: 10px;
        padding: 0.8rem 1rem;
        font-size: 0.85rem;
        margin-bottom: 1rem;
    }

    @media (max-width: 991px) {
        .rb-pane-layout { grid-template-columns: 1fr; grid-template-rows: 32% 68%; }
        .rb-master-pane { border-right: none; border-bottom: 1px solid var(--card-border); }
    }
</style>

<div class="rejected-page">
   

    <?php if ($errorMessage): ?>
        <div class="rb-error"><i data-lucide="alert-circle" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i><?= $errorMessage ?></div>
    <?php endif; ?>

    <!-- Main Card -->
    <div class="rb-main-card">
        <?php if (empty($rejectedBookings) && !$errorMessage): ?>
            <div class="rb-empty-state" style="display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1;">
                <i data-lucide="inbox"></i>
                <h4 style="color: var(--text-main); font-weight: 700;">No rejected bookings</h4>
                <p style="font-size: 0.85rem;">There are no rejected appointments at the moment.</p>
                <a href="manage_bookings.php" class="rb-back-btn" style="margin-top: 0.5rem;">Manage Bookings</a>
            </div>
        <?php else: ?>
            <div class="rb-pane-layout">
                <div class="rb-master-pane">
                    <ul class="rb-list" id="bookingList">
                        <?php foreach ($rejectedBookings as $b): ?>
                            <li class="rb-list-item" data-booking-id="<?= $b['id'] ?>">
                                <div class="rb-list-header">
                                    <span class="rb-list-id">Booking #<?= sprintf('%04d', $b['id']) ?></span>
                                    <span class="rb-list-date"><i data-lucide="calendar" style="width: 11px; height: 11px;"></i> <?= htmlspecialchars($b['schedule_date']) ?></span>
                                </div>
                                <div class="rb-list-customer"><?= htmlspecialchars($b['customer_name'] ?: $b['username']) ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="rb-detail-pane">
                    <div id="detailPlaceholder" class="rb-empty-state">
                        <i data-lucide="arrow-left-square"></i>
                        <h4 style="color: var(--text-main); font-weight: 700;">Select a booking</h4>
                        <p>Click a rejected booking on the left to view details.</p>
                    </div>

                    <?php foreach ($rejectedBookings as $b):
                        $vehicle_parts = array_filter([$b['year_model'], $b['brand'], $b['model']]);
                        $vehicle_display = implode(' ', $vehicle_parts);
                        if (!empty($b['plate_number'])) $vehicle_display .= " (Plate: " . htmlspecialchars($b['plate_number']) . ")";
                        if (empty($vehicle_display)) $vehicle_display = "Motorcycle information unavailable";

                        $reason = ($b['status'] == 'deposit_rejected') ? 'Deposit failed verification.' : 'Manually rejected by Admin.';
                        $status_label = ucfirst(str_replace('_', ' ', $b['status']));

                        $start_time_ts = strtotime($b['schedule_start_time']);
                        $end_time_ts = strtotime($b['schedule_end_time']);
                        $time_display = $end_time_ts ? date('g:i A', $start_time_ts) . ' - ' . date('g:i A', $end_time_ts) : date('g:i A', $start_time_ts);
                        $date_display = date('M d, Y', strtotime($b['schedule_date']));
                        $services = get_service_names($pdo, $b['service_ids']);
                        $mechanics = get_mechanic_names($pdo, $b['id']);
                        $customer_name = htmlspecialchars($b['customer_name'] ?: $b['username']);
                    ?>
                        <div class="rb-detail-content" id="details-<?= $b['id'] ?>">
                            <!-- Header -->
                            <div class="rb-detail-header">
                                <div class="rb-detail-header-left">
                                    <span class="rb-detail-id">Booking #<?= sprintf('%04d', $b['id']) ?></span>
                                    <span class="rb-status-badge"><?= $status_label ?></span>
                                </div>
                                <div class="rb-detail-actions">
                                    <a href="manage_bookings.php" class="rb-action-btn manage"><i data-lucide="settings"></i> Manage</a>
                                </div>
                            </div>

                            <!-- Dashboard Grid -->
                            <div class="rb-detail-grid">
                                <!-- Customer -->
                                <div class="rb-detail-section customer">
                                    <div class="rb-section-title"><i data-lucide="user"></i> Customer</div>
                                    <div class="rb-detail-row">
                                        <span class="rb-detail-label">Name</span>
                                        <span class="rb-detail-value"><?= $customer_name ?></span>
                                    </div>
                                    <div class="rb-detail-row align-top">
                                        <span class="rb-detail-label">Email</span>
                                        <span class="rb-detail-value wrap"><?= htmlspecialchars($b['customer_email'] ?: 'N/A') ?></span>
                                    </div>
                                    <div class="rb-detail-row">
                                        <span class="rb-detail-label">Phone</span>
                                        <span class="rb-detail-value"><?= !empty($b['customer_phone']) ? format_phone($b['customer_phone']) : 'No contact' ?></span>
                                    </div>
                                    <div class="rb-detail-row align-top">
                                        <span class="rb-detail-label">Vehicle</span>
                                        <span class="rb-detail-value wrap"><?= htmlspecialchars($vehicle_display) ?></span>
                                    </div>
                                </div>

                                <!-- Appointment -->
                                <div class="rb-detail-section appointment">
                                    <div class="rb-section-title"><i data-lucide="calendar"></i> Appointment</div>
                                    <div class="rb-detail-row">
                                        <span class="rb-detail-label">Date</span>
                                        <span class="rb-detail-value"><?= $date_display ?></span>
                                    </div>
                                    <div class="rb-detail-row">
                                        <span class="rb-detail-label">Time</span>
                                        <span class="rb-detail-value"><?= $time_display ?></span>
                                    </div>
                                </div>

                                <!-- Mechanic -->
                                <div class="rb-detail-section mechanic">
                                    <div class="rb-section-title"><i data-lucide="user-check"></i> Mechanic</div>
                                    <div class="rb-detail-block" style="color: var(--text-muted);"><?= $mechanics ?></div>
                                </div>

                                <!-- Rejection Details -->
                                <div class="rb-detail-section rejection">
                                    <div class="rb-rejection-card">
                                        <div class="rb-rejection-header">
                                            <div class="rb-rejection-title"><i data-lucide="alert-triangle"></i> Rejection Details</div>
                                            <div class="rb-rejection-status"><i data-lucide="x"></i> <?= $status_label ?></div>
                                        </div>
                                        <div class="rb-rejection-rows">
                                            <div class="rb-rejection-row status">
                                                <span class="rb-rejection-label">Status</span>
                                                <span class="rb-rejection-value"><?= $status_label ?></span>
                                            </div>
                                            <div class="rb-rejection-row align-top">
                                                <span class="rb-rejection-label">Reason</span>
                                                <span class="rb-rejection-value wrap"><?= htmlspecialchars($reason) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Service -->
                                <div class="rb-detail-section">
                                    <div class="rb-section-title"><i data-lucide="wrench"></i> Service</div>
                                    <div class="rb-detail-block"><?= htmlspecialchars($services) ?></div>
                                </div>
                            </div>

                            <div class="rb-rejected-box">
                                <i data-lucide="x-octagon"></i>
                                Booking Rejected
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require 'admin_sidebar_footer.php'; ?>

<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        lucide.createIcons();

        const bookingListItems = document.querySelectorAll('#bookingList .rb-list-item');
        const detailPlaceholder = document.getElementById('detailPlaceholder');
        const allDetailContents = document.querySelectorAll('.rb-detail-content');

        bookingListItems.forEach(item => {
            item.addEventListener('click', function (event) {
                event.preventDefault();

                const alreadyActive = this.classList.contains('active');
                const bookingId = this.getAttribute('data-booking-id');
                const targetDetailContent = document.getElementById('details-' + bookingId);

                // Reset all
                detailPlaceholder.style.display = 'none';
                allDetailContents.forEach(content => {
                    content.classList.remove('active');
                    content.style.display = 'none';
                });
                bookingListItems.forEach(i => i.classList.remove('active'));

                if (alreadyActive) {
                    detailPlaceholder.style.display = 'block';
                } else {
                    if (targetDetailContent) {
                        targetDetailContent.style.display = 'flex';
                        targetDetailContent.classList.add('active');
                    }
                    this.classList.add('active');
                }
            });
        });

        if (bookingListItems.length > 0) {
            bookingListItems[0].click();
        }
    });
</script>