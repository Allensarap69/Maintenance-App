<?php
session_start();
require 'db.php';
require 'motorcycle_health_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['booking_id']) && $_POST['action'] === 'complete') {
    $booking_id = (int)$_POST['booking_id'];
    if ($booking_id > 0) {
        try {
            $pdo->beginTransaction();

            // Find any mechanic(s) linked to this booking
            $mechanic_ids = [];
            $stmt = $pdo->prepare("SELECT mechanic_id FROM booking_mechanics WHERE booking_id = ?");
            $stmt->execute([$booking_id]);
            $mechanic_ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'mechanic_id');

            if (empty($mechanic_ids)) {
                $stmt = $pdo->prepare("SELECT mechanic_id FROM bookings WHERE id = ?");
                $stmt->execute([$booking_id]);
                $single = $stmt->fetchColumn();
                if ($single) {
                    $mechanic_ids[] = $single;
                }
            }

            $stmt = $pdo->prepare("UPDATE bookings SET status = 'completed' WHERE id = ?");
            $stmt->execute([$booking_id]);
            $movedRows = $stmt->rowCount();

            // Free the mechanic(s) — only if they have no other unfinished work
            if (!empty($mechanic_ids)) {
                $busy_check = $pdo->prepare("
                    SELECT
                        (SELECT COUNT(*) FROM emergency_service_requests WHERE assigned_mechanic_id = ? AND request_status IN ('assigned','accepted','in_progress')) +
                        (SELECT COUNT(*) FROM bookings WHERE mechanic_id = ? AND status IN ('assigned','accepted','in_progress')) +
                        (SELECT COUNT(*) FROM booking_mechanics bm JOIN bookings b2 ON b2.id = bm.booking_id WHERE bm.mechanic_id = ? AND b2.status IN ('assigned','accepted','in_progress')) +
                        (SELECT COUNT(*) FROM mechanics WHERE id = ? AND current_booking_id IS NOT NULL)
                ");
                foreach ($mechanic_ids as $mid) {
                    $pdo->prepare("UPDATE mechanics SET current_booking_id = NULL WHERE id = ? AND current_booking_id = ?")->execute([$mid, $booking_id]);
                    $busy_check->execute([$mid, $mid, $mid, $mid]);
                    if ((int)$busy_check->fetchColumn() === 0) {
                        $pdo->prepare("UPDATE mechanics SET status = 'Available' WHERE id = ? AND status = 'Busy'")->execute([$mid]);
                    }
                }
            }

            // Cash is collected at service completion, so mark the cash payment as verified
            $stmt = $pdo->prepare("UPDATE payments SET status = 'verified' WHERE booking_id = ? AND payment_method = 'cash' AND status = 'pending'");
            $stmt->execute([$booking_id]);

            // Save the completed booking into maintenance history + refresh health score
            recordCompletedBookingHistory($booking_id);

            $pdo->commit();
            $_SESSION['booking_flash'] = "Booking #{$booking_id} marked as completed ({$movedRows} row).";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['booking_flash'] = "Database error: " . $e->getMessage();
        }
    } else {
        $_SESSION['booking_flash'] = "Invalid booking ID received.";
    }
    header("Location: bookings_status.php?tab=completed");
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

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

function renderList($bookings, $listId, $activeTab, $tabKey) {
    $activeClass = ($activeTab === $tabKey) ? ' active' : '';
    echo '<ul class="ab-list' . $activeClass . '" id="' . $listId . '" data-tab-list="' . $tabKey . '">';
    if (empty($bookings)) {
        echo '<li class="ab-empty-state" style="border: none; background: none; box-shadow: none; text-align: center; padding: 2rem 1rem;">';
        echo '<i data-lucide="inbox" style="width: 48px; height: 48px; margin-bottom: 1rem; opacity: 0.4;"></i>';
        echo '<h4 style="color: var(--text-main); font-weight: 700;">No ' . ucfirst($tabKey) . ' bookings</h4>';
        echo '</li>';
    } else {
        foreach ($bookings as $b) {
            $customer = htmlspecialchars($b['customer_name'] ?: $b['username']);
            $status = $b['status'];
            $is_rejected = in_array($status, ['rejected', 'deposit_rejected']);
            $is_completed = ($status === 'completed');
            $status_label = $is_rejected ? ucfirst(str_replace('_', ' ', $status)) : ($is_completed ? 'Completed' : 'Accepted');
            $status_class = $is_rejected ? 'rejected' : ($is_completed ? 'completed' : 'accepted');
            $date = htmlspecialchars($b['schedule_date']);
            echo '<li class="ab-list-item" data-booking-id="' . $b['id'] . '">';
            echo '<div class="ab-list-icon"><i data-lucide="calendar"></i></div>';
            echo '<div class="ab-list-main">';
            echo '<div class="ab-list-customer">' . $customer . '</div>';
            echo '<div class="ab-list-meta">Booking #' . sprintf('%04d', $b['id']) . ' &middot; ' . $date . '</div>';
            echo '</div>';
            echo '<div class="ab-list-status ' . $status_class . '">' . $status_label . '</div>';
            echo '<div class="ab-list-arrow"><i data-lucide="chevron-right"></i></div>';
            echo '</li>';
        }
    }
    echo '</ul>';
}

function renderBookingDetail($b, $pdo) {
    $status = $b['status'];
    $is_rejected = in_array($status, ['rejected', 'deposit_rejected']);
    $is_completed = ($status === 'completed');
    $status_label = $is_rejected ? ucfirst(str_replace('_', ' ', $status)) : ($is_completed ? 'Completed' : 'Accepted');
    $status_class = $is_rejected ? 'rejected' : ($is_completed ? 'completed' : 'accepted');

    $vehicle_parts = array_filter([$b['year_model'], $b['brand'], $b['model']]);
    $vehicle_display = implode(' ', $vehicle_parts);
    if (!empty($b['plate_number'])) $vehicle_display .= " (Plate: " . htmlspecialchars($b['plate_number']) . ")";
    if (empty($vehicle_display)) $vehicle_display = "Motorcycle information unavailable";

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
    $vehicle_img_src = !empty($b['vehicle_image']) ? $b['vehicle_image'] : ($modelImages[$b['model']] ?? null);

    $start_time_ts = strtotime($b['schedule_start_time']);
    $end_time_ts = strtotime($b['schedule_end_time']);
    $time_display = $end_time_ts ? date('g:i A', $start_time_ts) . ' - ' . date('g:i A', $end_time_ts) : date('g:i A', $start_time_ts);
    $date_display = date('M d, Y', strtotime($b['schedule_date']));
    $services = get_service_names($pdo, $b['service_ids']);
    $mechanics = get_mechanic_names($pdo, $b['id']);
    $customer_name = htmlspecialchars($b['customer_name'] ?: $b['username']);

    $payment_record_status = $b['payment_record_status'] ?? null;
    $payment_method = $b['payment_method'] ?? null;
    // A completed cash booking is considered paid at service completion
    if ($is_completed && !empty($payment_method) && strtolower($payment_method) === 'cash') {
        $payment_record_status = 'verified';
    }
    $payment_verified = ($payment_record_status === 'verified');
    $amount_paid = $payment_verified ? (float)($b['payment_amount'] ?? 0) : 0;
    $total_price = $b['total_price'];
    $balance_due = max(0, $total_price - $amount_paid);

    if ($is_rejected) {
        $reason = ($status == 'deposit_rejected') ? 'Deposit failed verification.' : 'Manually rejected by Admin.';
        $box_class = 'ab-status-banner rejected';
        $box_icon = '';
        $box_text = 'Booking Rejected';
        $box_subtext = 'This booking has been rejected.';
    } elseif ($is_completed) {
        $box_class = 'ab-status-banner completed';
        $box_icon = '';
        $box_text = 'Service Completed';
        $box_subtext = 'This service has been completed successfully.';
    } else {
        $box_class = 'ab-status-banner accepted';
        $box_icon = '';
        $box_text = 'Booking Confirmed';
        $box_subtext = 'This booking has been confirmed and is ready for service.';
    }

    $actionBtns = '';
    if (!$is_rejected) {
        $actionBtns .= '<a href="print_receipt.php?booking_id=' . $b['id'] . '" target="_blank" class="ab-action-btn print"><i data-lucide="printer"></i> Print</a>';
    }
    if (!$is_rejected && !$is_completed) {
        $actionBtns .= '<form method="POST" action="bookings_status.php?tab=accepted" style="display:inline;margin:0;padding:0;">
            <input type="hidden" name="booking_id" value="' . $b['id'] . '">
            <input type="hidden" name="action" value="complete">
            <button type="submit" class="ab-action-btn complete" style="margin:0;"><i data-lucide="flag"></i> Complete</button>
        </form>';
    }
    $payment_method_label = !empty($b['payment_method']) ? htmlspecialchars(ucfirst($b['payment_method'])) : 'N/A';
    if (!empty($payment_record_status) && $payment_record_status !== 'verified') {
        $payment_method_label .= ' (' . htmlspecialchars(ucfirst($payment_record_status)) . ')';
    }
    $payment_status = ($balance_due == 0) ? 'Completed' : (($amount_paid > 0) ? 'Partial' : 'Pending');
    $payment_pill_class = ($balance_due == 0) ? 'completed' : (($amount_paid > 0) ? 'partial' : 'pending');
    $paid_html = '₱' . number_format($amount_paid, 2);
    $balance_class = ($balance_due == 0 ? 'ab-text-green' : 'ab-text-gold');

    $detailItem = function($icon, $label, $value, $valueClass = '', $full = false) {
        $fullClass = $full ? ' ab-detail-item-full' : '';
        return '
    <div class="ab-detail-item' . $fullClass . '">
        <div class="ab-detail-icon"><i data-lucide="' . $icon . '"></i></div>
        <div class="ab-detail-text">
            <div class="ab-detail-label">' . htmlspecialchars($label) . '</div>
            <div class="ab-detail-value ' . $valueClass . '">' . $value . '</div>
        </div>
    </div>';
    };
    ?>
<div class="ab-detail-content" id="details-<?= $b['id'] ?>">
    <div class="ab-detail-grid">
        <div class="ab-detail-card">
            <div class="ab-detail-header">
                <div class="ab-detail-header-main">
                    <div class="ab-detail-header-top">
                        <div class="ab-detail-id">Booking #<?= sprintf('%04d', $b['id']) ?></div>
                        <span class="ab-status-badge"><?= $status_label ?></span>
                    </div>
                    <div class="ab-detail-meta">
                        <span class="ab-detail-meta-item"><i data-lucide="calendar"></i> <?= $date_display ?></span>
                        <span class="ab-detail-meta-item"><i data-lucide="clock"></i> <?= $time_display ?></span>
                    </div>
                </div>
                <?php if ($vehicle_img_src): ?>
                <img class="ab-detail-vehicle-img" src="<?= htmlspecialchars($vehicle_img_src) ?>" alt="<?= htmlspecialchars(trim(($b['brand'] ?? '') . ' ' . ($b['model'] ?? ''))) ?>">
                <?php endif; ?>
            </div>
            <div class="<?= $box_class ?>">
                <?php if ($box_icon): ?>
                <div class="ab-status-banner-icon"><i data-lucide="<?= $box_icon ?>"></i></div>
                <?php endif; ?>
                <div class="ab-status-banner-content">
                    <div class="ab-status-banner-title"><?= $box_text ?></div>
                    <div class="ab-status-banner-sub"><?= $box_subtext ?></div>
                </div>
            </div>
            <div class="ab-card-title"><i data-lucide="file-text"></i> Booking Details</div>
            <div class="ab-detail-body">
                <div class="ab-detail-col">
                    <?= $detailItem('user', 'Customer', $customer_name) ?>
                    <?= $detailItem('mail', 'Email', htmlspecialchars($b['customer_email'] ?: 'N/A')) ?>
                    <?= $detailItem('phone', 'Phone', !empty($b['customer_phone']) ? format_phone($b['customer_phone']) : 'No contact') ?>
                    <?= $detailItem('motorbike', 'Vehicle', htmlspecialchars($vehicle_display)) ?>
                    <?= $detailItem('calendar', 'Date', $date_display) ?>
                    <?= $detailItem('clock', 'Time', $time_display) ?>
                </div>
                <div class="ab-detail-col">
                    <?= $detailItem('wrench', 'Mechanic', $mechanics, $is_rejected ? 'ab-text-muted' : '') ?>
                    <?php if (!$is_rejected): ?>
                        <?= $detailItem('credit-card', 'Payment', '<span class="ab-status-pill ' . $payment_pill_class . '">' . $payment_status . '</span>') ?>
                        <?= $detailItem('banknote', 'Total', '₱' . number_format($total_price, 2)) ?>
                        <?= $detailItem('banknote', 'Paid', $paid_html) ?>
                        <?= $detailItem('wallet', 'Method', $payment_method_label) ?>
                        <?= $detailItem('banknote', 'Balance Due', '₱' . number_format($balance_due, 2), $balance_class) ?>
                        <?= $detailItem('file-text', 'Service', htmlspecialchars($services)) ?>
                    <?php else: ?>
                        <?= $detailItem('x-octagon', 'Reason', htmlspecialchars($reason), '', true) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="ab-detail-footer <?= $status_class ?>">
        <?= $actionBtns ?>
    </div>
</div>
    <?php
}

$errorMessage = "";
$flash = $_SESSION['booking_flash'] ?? '';
unset($_SESSION['booking_flash']);
$acceptedBookings = $rejectedBookings = $completedBookings = [];
$activeTab = $_GET['tab'] ?? 'accepted';
if (!in_array($activeTab, ['accepted', 'rejected', 'completed'])) $activeTab = 'accepted';

$base_sql = "
    SELECT 
        b.id, b.service_ids, b.schedule_date, b.schedule_start_time, b.schedule_end_time, b.total_price, b.status, b.mechanic_id,
        p.amount AS payment_amount,
        p.payment_method,
        p.transaction_ref,
        p.transaction_type,
        p.status AS payment_record_status,
        u.username, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
        v.brand, v.model, v.year_model, v.plate_number, v.image AS vehicle_image
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    LEFT JOIN motorcycles v ON b.vehicle_id = v.id 
    LEFT JOIN payments p ON b.id = p.booking_id AND p.id = (SELECT MAX(id) FROM payments p2 WHERE p2.booking_id = b.id)
";

try {
    $stmt = $pdo->prepare($base_sql . " WHERE b.status = 'accepted' ORDER BY b.schedule_date DESC, b.schedule_start_time DESC");
    $stmt->execute();
    $acceptedBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare($base_sql . " WHERE b.status IN ('rejected', 'deposit_rejected') ORDER BY b.schedule_date DESC, b.schedule_start_time DESC");
    $stmt->execute();
    $rejectedBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare($base_sql . " WHERE b.status = 'completed' ORDER BY b.schedule_date DESC, b.schedule_start_time DESC");
    $stmt->execute();
    $completedBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errorMessage = "Database Error: " . $e->getMessage();
}

$allBookings = array_merge($acceptedBookings, $rejectedBookings, $completedBookings);
$pageTitle = 'Bookings Status';
?>

<?php require 'admin_sidebar_template.php'; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<style>
    :root {
        --bg-dark: #F8FAFC;
        --card-bg: #ffffff;
        --card-border: #E5E7EB;
        --accent-cyan: #1e3a5f;
        --accent-orange: #FACC15;
        --accent-gold: #FACC15;
        --accent-green: #10b981;
        --accent-red: #ef4444;
        --accent-blue: #1e3a5f;
        --text-main: #111827;
        --text-muted: #6B7280;
    }

    body {
        background: var(--bg-dark) !important;
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

    .bg-animation,
    .floating-tools { display: none !important; }

    .booking-status-page {
        max-width: 1200px;
        margin: 0 auto;
        width: 100%;
        display: flex;
        flex-direction: column;
        height: calc(100vh - 110px);
        min-height: 500px;
    }

    .ab-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 0.75rem;
        flex-shrink: 0;
        flex-wrap: wrap;
    }
    .ab-page-title {
        font-size: 1.4rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: var(--text-main);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .ab-page-title i { color: #FACC15; }
    .ab-page-subtitle { font-size: 0.78rem; color: var(--text-muted); margin-top: 0.15rem; }
    .ab-back-btn {
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
        transition: all 0.2s ease;
    }
    .ab-back-btn:hover { background: rgba(0, 0, 0, 0.08); color: var(--text-main); }
    .ab-back-btn i { width: 13px; height: 13px; }

    .ab-tabs {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.75rem;
        flex-shrink: 0;
        flex-wrap: wrap;
    }
    .ab-tab {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        background: var(--card-bg);
        border: 1px solid var(--card-border);
        border-radius: 10px;
        padding: 0.5rem 0.9rem;
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--text-main);
        text-decoration: none;
        transition: all 0.2s ease;
    }
    .ab-tab i { width: 14px; height: 14px; }
    .ab-tab:hover { border-color: #1e3a5f; }
    .ab-tab.active {
        background: #fff;
        border-color: #FACC15;
    }

    .ab-main-card {
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

    .ab-pane-layout {
        display: grid;
        grid-template-columns: 280px 1fr;
        flex: 1;
        min-height: 0;
    }

    .ab-master-pane {
        border-right: 1px solid var(--card-border);
        overflow-y: auto;
        background: var(--card-bg);
        padding: 0.5rem;
        scrollbar-width: none;
    }
    .ab-master-pane::-webkit-scrollbar { display: none; }
    .ab-list { list-style: none; padding: 0; margin: 0; display: none; flex-direction: column; gap: 0.5rem; }
    .ab-list.active { display: flex; }
    .ab-list-item {
        display: block;
        background: var(--card-bg);
        border: 1px solid var(--card-border);
        border-radius: 12px;
        padding: 0.7rem 0.85rem;
        color: var(--text-main);
        text-decoration: none;
        transition: all 0.2s ease;
        cursor: pointer;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
    }
    .ab-list-item:hover { border-color: rgba(250, 204, 21, 0.35); }
    .ab-list-item.active {
        background: #fffbeb;
        border-left: 3px solid var(--accent-orange);
    }
    .ab-list-header { display: flex; justify-content: flex-end; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem; }
    .ab-list-id {
        font-size: 0.75rem;
        font-weight: 800;
        color: var(--accent-cyan);
    }
    .ab-list-date {
        font-size: 0.68rem;
        color: var(--text-muted);
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }
    .ab-list-date i { width: 11px; height: 11px; }
    .ab-list-customer {
        font-size: 0.85rem;
        font-weight: 800;
        color: var(--text-main);
        text-transform: uppercase;
        letter-spacing: 0.02em;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .ab-detail-pane {
        position: relative;
        padding: 0.9rem;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        scrollbar-width: none;
    }
    .ab-detail-pane::-webkit-scrollbar { display: none; }
    .ab-detail-pane > * { position: relative; z-index: 1; }
    .ab-empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-muted); }
    .ab-empty-state i { width: 48px; height: 48px; margin-bottom: 1rem; opacity: 0.4; }
    .ab-detail-content { display: none; }
    .ab-detail-content.active { display: flex; flex-direction: column; }

    .ab-detail-header {
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
    .ab-detail-header-left { display: flex; align-items: center; gap: 0.7rem; }
    .ab-detail-id {
        font-size: 1.15rem;
        font-weight: 800;
        color: var(--text-main);
    }
    .ab-status-badge {
        padding: 0.2rem 0.55rem;
        border-radius: 6px;
        font-size: 0.62rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        border: 1px solid;
    }
    .ab-detail-actions { display: flex; align-items: center; gap: 0.4rem; }
    .ab-action-btn {
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
        transition: all 0.2s ease;
    }
    .ab-action-btn:hover { background: rgba(0, 0, 0, 0.08); color: var(--text-main); }
    .ab-action-btn i { width: 12px; height: 12px; }
    .ab-action-btn.print { background: rgba(30, 58, 95, 0.1); border-color: rgba(30, 58, 95, 0.3); color: #1e3a5f; }
    .ab-action-btn.print:hover { background: rgba(30, 58, 95, 0.2); }
    .ab-action-btn.manage { background: rgba(250, 204, 21, 0.1); border-color: rgba(250, 204, 21, 0.3); color: #EAB308; }
    .ab-action-btn.manage:hover { background: rgba(250, 204, 21, 0.2); }
    .ab-action-btn.complete { background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.3); color: #15803d; }
    .ab-action-btn.complete:hover { background: rgba(16, 185, 129, 0.2); }

    .ab-section-title {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.62rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--text-muted);
        margin-bottom: 0.2rem;
    }
    .ab-section-title i { width: 13px; height: 13px; color: var(--accent-cyan); }

    .ab-detail-grid {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        flex: 1;
        align-self: center;
        width: 100%;
        max-width: 720px;
    }
    .ab-detail-section {
        background: var(--card-bg);
        border: 1px solid var(--card-border);
        border-radius: 12px;
        padding: 1rem;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        width: 100%;
    }
    .ab-detail-section.payment { padding: 0; }
    .ab-detail-section.rejection { padding: 0; }
    .ab-detail-row {
        display: grid;
        grid-template-columns: 90px 1fr;
        align-items: center;
        gap: 0.75rem;
        padding: 0.25rem 0;
        border-bottom: 1px solid var(--card-border);
    }
    .ab-detail-row:last-child { border-bottom: none; }
    .ab-detail-row.align-top { align-items: flex-start; }
    .ab-detail-label {
        font-size: 0.65rem;
        font-weight: 700;
        color: #000000;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        white-space: nowrap;
        min-width: 90px;
    }
    .ab-detail-value {
        font-size: 0.8rem;
        font-weight: 700;
        color: #4B5563;
        text-align: left;
        max-width: none;
        line-height: 1.2;
    }
    .ab-detail-value.green { color: var(--accent-green); }
    .ab-detail-value.orange { color: var(--accent-orange); }
    .ab-detail-value.wrap { text-align: left; max-width: 100%; width: 100%; margin-top: 0; line-height: 1.3; }
    .ab-detail-block {
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--text-main);
        line-height: 1.4;
        word-break: break-word;
    }
    .ab-detail-block.muted { color: var(--text-muted); }

    .ab-payment-card {
        position: relative;
        height: 100%;
        min-height: 155px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        background: #fffbeb;
        border: 1px solid rgba(250, 204, 21, 0.35);
        border-radius: 12px;
        padding: 0.75rem;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        overflow: hidden;
    }
    .ab-payment-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        z-index: 1;
    }
    .ab-payment-title { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: var(--accent-gold); display: flex; align-items: center; gap: 0.35rem; }
    .ab-payment-title i { width: 14px; height: 14px; color: var(--accent-gold); }
    .ab-payment-status {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        font-size: 0.62rem;
        font-weight: 800;
        color: var(--accent-green);
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.35);
        border-radius: 99px;
        padding: 0.15rem 0.5rem;
    }
    .ab-payment-status i { width: 10px; height: 10px; }
    .ab-payment-chip {
        width: 34px;
        height: 24px;
        border: 1px solid rgba(250, 204, 21, 0.5);
        border-radius: 4px;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1;
    }
    .ab-payment-chip i { width: 18px; height: 18px; color: var(--accent-gold); }
    .ab-payment-rows { z-index: 1; }
    .ab-payment-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        padding: 0.25rem 0;
    }
    .ab-payment-row.total { border-bottom: 1px solid var(--card-border); padding-bottom: 0.35rem; margin-bottom: 0.25rem; }
    .ab-payment-label { font-size: 0.65rem; color: #EAB308; text-transform: uppercase; letter-spacing: 0.05em; }
    .ab-payment-value { font-size: 0.85rem; font-weight: 800; color: var(--accent-gold); }
    .ab-payment-value.lg { font-size: 1.1rem; }

    .ab-rejection-card {
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
    .ab-rejection-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        z-index: 1;
    }
    .ab-rejection-title { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #b91c1c; display: flex; align-items: center; gap: 0.35rem; }
    .ab-rejection-title i { width: 14px; height: 14px; color: #b91c1c; }
    .ab-rejection-status {
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
    .ab-rejection-status i { width: 10px; height: 10px; }
    .ab-rejection-rows { z-index: 1; }
    .ab-rejection-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        padding: 0.25rem 0;
    }
    .ab-rejection-row.status { border-bottom: 1px solid var(--card-border); padding-bottom: 0.35rem; margin-bottom: 0.25rem; }
    .ab-rejection-row.align-top { align-items: flex-start; }
    .ab-rejection-label { font-size: 0.65rem; color: #ef4444; text-transform: uppercase; letter-spacing: 0.05em; }
    .ab-rejection-value { font-size: 0.8rem; font-weight: 700; color: var(--text-main); text-align: right; max-width: 65%; }
    .ab-rejection-value.wrap { text-align: left; max-width: 100%; width: 100%; margin-top: 0.2rem; line-height: 1.4; }
    .ab-rejection-value.lg { font-size: 1.1rem; color: #b91c1c; }

    .ab-confirmed-box,
    .ab-rejected-box {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        border-radius: 10px;
        padding: 0.55rem;
        font-weight: 800;
        font-size: 0.85rem;
        margin-top: 0.75rem;
    }
    .ab-confirmed-box {
        background: rgba(16, 185, 129, 0.1);
        border: 1px solid rgba(16, 185, 129, 0.4);
        color: var(--accent-green);
    }
    .ab-rejected-box {
        background: rgba(239, 68, 68, 0.1);
        border: 1px solid rgba(239, 68, 68, 0.4);
        color: #b91c1c;
    }
    .ab-confirmed-box i,
    .ab-rejected-box i { width: 16px; height: 16px; }

    .ab-error {
        background: rgba(239, 68, 68, 0.1);
        border: 1px solid rgba(239, 68, 68, 0.2);
        color: #b91c1c;
        border-radius: 10px;
        padding: 0.8rem 1rem;
        font-size: 0.85rem;
        margin-bottom: 1rem;
    }

    @media (max-width: 991px) {
        .ab-pane-layout { grid-template-columns: 1fr; grid-template-rows: 32% 68%; }
        .ab-master-pane { border-right: none; border-bottom: 1px solid var(--card-border); }
        .ab-tabs { overflow-x: auto; flex-wrap: nowrap; }
    }

    /* Refreshed layout */
    .ab-pane-layout { grid-template-columns: 320px 1fr; }

    .ab-master-pane {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        padding: 0.75rem;
    }
    .ab-master-search {
        position: relative;
    }
    .ab-master-search i {
        position: absolute;
        left: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        width: 15px;
        height: 15px;
        color: var(--text-muted);
        pointer-events: none;
    }
    .ab-list-search {
        width: 100%;
        background: #fff;
        border: 1px solid #E5E7EB;
        border-radius: 10px;
        padding: 0.6rem 0.75rem 0.6rem 2.2rem;
        font-size: 0.78rem;
        color: var(--text-main);
        outline: none;
    }
    .ab-list-search:focus {
        border-color: #1e3a5f;
        box-shadow: 0 0 0 3px rgba(30, 58, 95, 0.2);
    }
    .ab-list-count {
        text-align: center;
        font-size: 0.75rem;
        color: var(--text-muted);
        padding-bottom: 0.25rem;
    }

    .ab-list-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        background: #fff;
        border: 1px solid var(--card-border);
        border-radius: 12px;
        padding: 0.75rem;
        color: var(--text-main);
        text-decoration: none;
        transition: all 0.2s ease;
        cursor: pointer;
    }
    .ab-list-item:hover { border-color: #1e3a5f; }
    .ab-list-item.active {
        background: rgba(250, 204, 21, 0.12);
        border-left: 3px solid #FACC15;
    }
    .ab-list-item.active .ab-list-icon {
        background: transparent;
        color: #1e40af;
    }
    .ab-list-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        background: transparent;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #1e40af;
        flex-shrink: 0;
        box-shadow: none;
    }
    .ab-list-icon i { width: 16px; height: 16px; box-shadow: none; }
    .ab-list-main { flex: 1; min-width: 0; }
    .ab-list-customer {
        font-size: 0.85rem;
        font-weight: 800;
        color: var(--text-main);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .ab-list-meta {
        font-size: 0.68rem;
        color: var(--text-muted);
        margin-top: 0.1rem;
    }
    .ab-list-status {
        font-size: 0.58rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        padding: 0.15rem 0.45rem;
        border-radius: 99px;
        border: 1px solid;
        flex-shrink: 0;
    }
    .ab-list-status.accepted { background: rgba(16, 185, 129, 0.12); color: #047857; border-color: rgba(16, 185, 129, 0.35); }
    .ab-list-status.rejected { background: rgba(239, 68, 68, 0.12); color: #b91c1c; border-color: rgba(239, 68, 68, 0.35); }
    .ab-list-status.completed { background: rgba(30, 58, 95, 0.12); color: #1d4ed8; border-color: rgba(30, 58, 95, 0.35); }
    .ab-list-arrow { color: #1e40af; }
    .ab-list-arrow i { width: 16px; height: 16px; }

    .ab-detail-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 0.75rem;
        padding: 1rem 1.1rem;
        background: transparent;
        border: none;
        border-radius: 16px;
        color: var(--text-main);
        flex-wrap: wrap;
    }
    .ab-detail-header-main { display: flex; flex-direction: column; gap: 0.35rem; }
    .ab-detail-header-top {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    .ab-detail-id {
        font-size: 1.25rem;
        font-weight: 800;
        color: #000000;
    }
    .ab-status-badge {
        padding: 0.25rem 0.65rem;
        border-radius: 99px;
        font-size: 0.65rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        border: 1px solid #E5E7EB;
        background: #ffffff;
        color: #000000;
    }
    .ab-detail-meta { display: flex; align-items: center; gap: 1rem; }
    .ab-detail-meta-item {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.78rem;
        color: #000000;
    }
    .ab-detail-meta-item i { width: 14px; height: 14px; color: #1e40af; }
    .ab-detail-vehicle-img {
        width: 170px;
        height: 115px;
        object-fit: cover;
        border-radius: 10px;
        flex-shrink: 0;
    }
    .ab-detail-actions { display: flex; align-items: center; gap: 0.4rem; }
    .ab-detail-footer {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-top: 0.75rem;
        flex-wrap: wrap;
    }
    .ab-detail-footer.accepted { justify-content: flex-end; }
    .ab-detail-footer.accepted .ab-action-btn {
        background: transparent;
        border: none;
        padding: 0;
    }
    .ab-detail-footer.accepted .ab-action-btn:hover { background: transparent; }
    .ab-action-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        background: rgba(0, 0, 0, 0.03);
        border: 1px solid var(--card-border);
        color: var(--text-main);
        border-radius: 10px;
        padding: 0.45rem 0.8rem;
        font-size: 0.72rem;
        font-weight: 800;
        text-decoration: none;
        transition: all 0.2s ease;
    }
    .ab-action-btn:hover { background: rgba(0, 0, 0, 0.08); color: var(--text-main); }
    .ab-action-btn i { width: 14px; height: 14px; }
    .ab-action-btn.print { background: rgba(30, 58, 95, 0.1); border-color: rgba(30, 58, 95, 0.3); color: #1e3a5f; }
    .ab-action-btn.print:hover { background: rgba(30, 58, 95, 0.2); }
    .ab-action-btn.complete { background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.3); color: #15803d; }
    .ab-action-btn.complete:hover { background: rgba(16, 185, 129, 0.2); }
    .ab-action-btn.manage { background: rgba(250, 204, 21, 0.1); border-color: rgba(250, 204, 21, 0.3); color: #EAB308; }
    .ab-action-btn.manage:hover { background: rgba(250, 204, 21, 0.2); }

    .ab-detail-grid { width: 100%; max-width: none; align-self: stretch; }
    .ab-detail-card {
        background: #fff;
        border: 1px solid var(--card-border);
        border-radius: 16px;
        padding: 1.1rem;
        width: 100%;
        overflow: hidden;
    }
    .ab-detail-card > .ab-detail-header {
        margin: -1.1rem -1.1rem 0.75rem -1.1rem;
        border-radius: 16px 16px 0 0;
    }
    .ab-detail-card > .ab-status-banner {
        margin: 0 -1.1rem 1rem -1.1rem;
        border-radius: 0;
    }
    .ab-detail-card > .ab-status-banner.accepted,
    .ab-detail-card > .ab-status-banner.completed,
    .ab-detail-card > .ab-status-banner.rejected {
        margin: 0 0 0.5rem 0;
        padding: 0.25rem 0;
        background: transparent;
        border: none;
    }
    .ab-card-title {
        font-size: 0.9rem;
        font-weight: 800;
        color: #10b981;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .ab-card-title i { width: 18px; height: 18px; color: #1e40af; }
    .ab-detail-body {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.25rem;
    }
    .ab-detail-col { display: flex; flex-direction: column; gap: 1rem; }
    .ab-detail-item { display: flex; align-items: flex-start; gap: 0.75rem; }
    .ab-detail-item-full { grid-column: 1 / -1; }
    .ab-detail-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        background: transparent;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #1e40af;
        flex-shrink: 0;
        box-shadow: none;
    }
    .ab-detail-icon i { width: 16px; height: 16px; box-shadow: none; }
    .ab-detail-text { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; }
    .ab-detail-label { font-size: 0.65rem; font-weight: 700; color: #000000; text-transform: uppercase; letter-spacing: 0.05em; }
    .ab-detail-value { font-size: 0.85rem; font-weight: 700; color: #4B5563; word-break: break-word; }
    .ab-text-green { color: var(--accent-green); }
    .ab-text-gold { color: var(--accent-gold); }
    .ab-text-muted { color: var(--text-muted); }

    .ab-status-pill {
        display: inline-flex;
        align-items: center;
        padding: 0.15rem 0.5rem;
        border-radius: 99px;
        font-size: 0.65rem;
        font-weight: 800;
        border: 1px solid;
    }
    .ab-status-pill.pending { background: rgba(250, 204, 21, 0.12); color: #EAB308; border-color: rgba(250, 204, 21, 0.35); }
    .ab-status-pill.partial { background: rgba(30, 58, 95, 0.12); color: #1e3a5f; border-color: rgba(30, 58, 95, 0.35); }
    .ab-status-pill.completed { background: rgba(16, 185, 129, 0.12); color: #047857; border-color: rgba(16, 185, 129, 0.35); }

    .ab-status-banner {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        border-radius: 14px;
        padding: 0.9rem 1rem;
        margin-top: 0.75rem;
    }
    .ab-status-banner.accepted { background: transparent; border: none; color: var(--accent-green); }
    .ab-status-banner.completed { background: transparent; border: none; color: #1d4ed8; }
    .ab-status-banner.rejected { background: transparent; border: none; color: #b91c1c; }
    .ab-status-banner-icon {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: transparent;
        color: inherit;
        box-shadow: none;
    }
    .ab-status-banner-icon i { width: 18px; height: 18px; box-shadow: none; }
    .ab-status-banner-content { display: flex; flex-direction: column; gap: 0.1rem; }
    .ab-status-banner-title { font-weight: 800; font-size: 0.75rem; }
    .ab-status-banner-sub { font-size: 0.75rem; color: #000000; opacity: 1; }

    @media (max-width: 767px) {
        .ab-detail-body { grid-template-columns: 1fr; }
        .ab-detail-header { flex-direction: column; align-items: flex-start; }
    }
</style>

<div class="booking-status-page">
    <?php if ($errorMessage): ?>
        <div class="ab-error"><i data-lucide="alert-circle" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>
    <?php if ($flash): ?>
        <div class="ab-error" style="background: rgba(250, 204, 21, 0.1); border-color: rgba(250, 204, 21, 0.2); color: #EAB308;"><i data-lucide="info" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    

    <div class="ab-tabs">
        <a href="?tab=accepted" class="ab-tab <?= $activeTab === 'accepted' ? 'active' : '' ?>" data-tab="accepted"><i data-lucide="check-circle"></i> Accepted</a>
        <a href="?tab=rejected" class="ab-tab <?= $activeTab === 'rejected' ? 'active' : '' ?>" data-tab="rejected"><i data-lucide="x-circle"></i> Rejected</a>
        <a href="?tab=completed" class="ab-tab <?= $activeTab === 'completed' ? 'active' : '' ?>" data-tab="completed"><i data-lucide="check-circle-2"></i> Completed</a>
    </div>

    <div class="ab-main-card">
        <?php if (empty($allBookings) && !$errorMessage): ?>
            <div class="ab-empty-state" style="display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1;">
                <i data-lucide="inbox"></i>
                <h4 style="color: var(--text-main); font-weight: 700;">No bookings found</h4>
                <p style="font-size: 0.85rem;">There are no accepted, rejected or completed appointments at the moment.</p>
                <a href="manage_bookings.php" class="ab-back-btn" style="margin-top: 0.5rem;">Manage Bookings</a>
            </div>
        <?php else: ?>
            <div class="ab-pane-layout">
                <div class="ab-master-pane">
                    <div class="ab-master-search">
                        <i data-lucide="search"></i>
                        <input type="text" class="ab-list-search" placeholder="Search by booking number, customer...">
                    </div>
                    <?php renderList($acceptedBookings, 'acceptedList', $activeTab, 'accepted'); ?>
                    <?php renderList($rejectedBookings, 'rejectedList', $activeTab, 'rejected'); ?>
                    <?php renderList($completedBookings, 'completedList', $activeTab, 'completed'); ?>
                    <div class="ab-list-count" id="ab-list-count"><?= count($allBookings) ?> Booking<?= count($allBookings) !== 1 ? 's' : '' ?> Found</div>
                </div>

                <div class="ab-detail-pane">
                    <div id="detailPlaceholder" class="ab-empty-state">
                        <i data-lucide="arrow-left-square"></i>
                        <h4 style="color: var(--text-main); font-weight: 700;">Select a booking</h4>
                        <p>Click a booking on the left to view details.</p>
                    </div>

                    <?php foreach ($allBookings as $b) renderBookingDetail($b, $pdo); ?>
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

        const bookingListItems = document.querySelectorAll('.ab-list-item');
        const detailPlaceholder = document.getElementById('detailPlaceholder');
        const allDetailContents = document.querySelectorAll('.ab-detail-content');
        const tabLinks = document.querySelectorAll('.ab-tab');
        const allLists = document.querySelectorAll('.ab-list');

        function resetDetail() {
            detailPlaceholder.style.display = 'block';
            allDetailContents.forEach(content => {
                content.classList.remove('active');
                content.style.display = 'none';
            });
            bookingListItems.forEach(i => i.classList.remove('active'));
        }

        function switchTab(tabKey) {
            tabLinks.forEach(tab => {
                if (tab.getAttribute('data-tab') === tabKey) {
                    tab.classList.add('active');
                } else {
                    tab.classList.remove('active');
                }
            });

            allLists.forEach(list => {
                if (list.getAttribute('data-tab-list') === tabKey) {
                    list.classList.add('active');
                } else {
                    list.classList.remove('active');
                }
            });

            resetDetail();

            const activeList = document.getElementById(tabKey + 'List');
            if (activeList) {
                const firstItem = activeList.querySelector('.ab-list-item:not(.ab-empty-state)');
                if (firstItem) {
                    firstItem.click();
                }
            }
            updateListCount();
        }

        tabLinks.forEach(tab => {
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                const tabKey = this.getAttribute('data-tab');
                if (tabKey) {
                    switchTab(tabKey);
                    history.replaceState({}, '', '?tab=' + tabKey);
                }
            });
        });

        bookingListItems.forEach(item => {
            item.addEventListener('click', function (event) {
                if (this.classList.contains('ab-empty-state')) return;
                event.preventDefault();

                const alreadyActive = this.classList.contains('active');
                const bookingId = this.getAttribute('data-booking-id');
                const targetDetailContent = document.getElementById('details-' + bookingId);

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

        const searchInput = document.querySelector('.ab-list-search');
        const listCount = document.getElementById('ab-list-count');

        function updateListCount() {
            const activeList = document.querySelector('.ab-list.active');
            if (!activeList || !listCount) return;
            const visible = Array.from(activeList.querySelectorAll('.ab-list-item')).filter(i => i.style.display !== 'none').length;
            listCount.textContent = visible + ' Booking' + (visible !== 1 ? 's' : '') + ' Found';
        }

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                const term = this.value.toLowerCase();
                allLists.forEach(list => {
                    const items = list.querySelectorAll('li.ab-list-item');
                    items.forEach(item => {
                        const text = item.textContent.toLowerCase();
                        item.style.display = text.includes(term) ? '' : 'none';
                    });
                });
                updateListCount();
            });
        }

        const initialTab = '<?= $activeTab ?>';
        switchTab(initialTab);
    });
</script>
