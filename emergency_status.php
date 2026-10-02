<?php
session_start();
require 'db.php';
require 'motorcycle_health_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['request_id'])) {
    $request_id = (int)$_POST['request_id'];
    $action = $_POST['action'];
    $mechanic_id = isset($_POST['mechanic_id']) ? (int)$_POST['mechanic_id'] : 0;

    if ($request_id <= 0) {
        $_SESSION['emergency_flash'] = "Invalid request ID received. Form value: " . htmlspecialchars($_POST['request_id'] ?? '(empty)');
        header("Location: emergency_status.php?tab=accepted");
        exit;
    }

    $redirectTab = 'accepted';
    try {
        if ($action === 'accept') {
            $redirectTab = 'accepted';

            // Use the submitted mechanic, or fall back to the one already assigned
            if ($mechanic_id <= 0) {
                $cur = $pdo->prepare("SELECT assigned_mechanic_id FROM emergency_service_requests WHERE id = ?");
                $cur->execute([$request_id]);
                $mechanic_id = (int)$cur->fetchColumn();
            }

            $stmt = $pdo->prepare("UPDATE emergency_service_requests SET request_status = 'accepted', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND request_status IN ('pending', 'new', 'assigned')");
            $stmt->execute([$request_id]);
            $movedRows = $stmt->rowCount();
            if ($mechanic_id > 0) {
                $pdo->prepare("UPDATE emergency_service_requests SET assigned_mechanic_id = ? WHERE id = ?")->execute([$mechanic_id, $request_id]);
                $pdo->prepare("UPDATE mechanics SET status = 'Busy' WHERE id = ?")->execute([$mechanic_id]);
                $_SESSION['emergency_flash'] = "Request #{$request_id} accepted ({$movedRows} row).";
            } else {
                $_SESSION['emergency_flash'] = "Request #{$request_id} accepted ({$movedRows} row), but no mechanic was selected.";
            }
            header("Location: emergency_status.php?tab=accepted");
            exit;
        } elseif ($action === 'decline') {
            $redirectTab = 'declined';
            $stmt = $pdo->prepare("UPDATE emergency_service_requests SET request_status = 'declined', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND request_status != 'declined'");
            $stmt->execute([$request_id]);
            $movedRows = $stmt->rowCount();
            $_SESSION['emergency_flash'] = "Request #{$request_id} declined ({$movedRows} row).";
            header("Location: emergency_status.php?tab=declined");
            exit;
        } elseif ($action === 'complete') {
            $redirectTab = 'completed';

            $req = $pdo->prepare("SELECT assigned_mechanic_id FROM emergency_service_requests WHERE id = ?");
            $req->execute([$request_id]);
            $assigned = $req->fetchColumn();

            $stmt = $pdo->prepare("UPDATE emergency_service_requests SET request_status = 'completed', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND request_status != 'completed'");
            $stmt->execute([$request_id]);
            $movedRows = $stmt->rowCount();

            if ($movedRows > 0) {
                recordCompletedEmergencyHistory($request_id);
            }

            if ($assigned) {
                $busy_check = $pdo->prepare("
                    SELECT
                        (SELECT COUNT(*) FROM emergency_service_requests WHERE assigned_mechanic_id = ? AND request_status IN ('assigned','accepted','in_progress')) +
                        (SELECT COUNT(*) FROM bookings WHERE mechanic_id = ? AND status IN ('assigned','accepted','in_progress')) +
                        (SELECT COUNT(*) FROM booking_mechanics bm JOIN bookings b ON b.id = bm.booking_id WHERE bm.mechanic_id = ? AND b.status IN ('assigned','accepted','in_progress')) +
                        (SELECT COUNT(*) FROM mechanics WHERE id = ? AND current_booking_id IS NOT NULL)
                ");
                $busy_check->execute([$assigned, $assigned, $assigned, $assigned]);
                if ((int)$busy_check->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE mechanics SET status = 'Available' WHERE id = ?")->execute([$assigned]);
                }
            }

            $_SESSION['emergency_flash'] = "Request #{$request_id} completed ({$movedRows} row).";
            header("Location: emergency_status.php?tab=completed");
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['emergency_flash'] = "Database error: " . $e->getMessage();
        header("Location: emergency_status.php?tab=" . $redirectTab);
        exit;
    }
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

function getStatusGroup($status) {
    $status = strtolower(trim($status));
    if (in_array($status, ['pending', 'new'])) return 'pending';
    if (in_array($status, ['accepted', 'assigned', 'accept'])) return 'accepted';
    if (in_array($status, ['declined', 'rejected', 'decline', 'reject'])) return 'declined';
    if (in_array($status, ['completed', 'complete'])) return 'completed';
    return 'pending';
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

function renderList($requests, $listId, $activeTab, $tabKey) {
    $activeClass = ($activeTab === $tabKey) ? ' active' : '';
    echo '<ul class="ab-list' . $activeClass . '" id="' . $listId . '" data-tab-list="' . $tabKey . '">';
    if (empty($requests)) {
        echo '<li class="ab-empty-state" style="border: none; background: none; box-shadow: none; text-align: center; padding: 2rem 1rem;">';
        echo '<i data-lucide="inbox" style="width: 48px; height: 48px; margin-bottom: 1rem; opacity: 0.4;"></i>';
        echo '<h4 style="color: var(--text-main); font-weight: 700;">No ' . ucfirst($tabKey) . ' requests</h4>';
        echo '</li>';
    } else {
        foreach ($requests as $b) {
            $customer = htmlspecialchars(($b['customer_fullname'] ?: $b['customer_name']) ?: 'Unknown');
            $detail_id = sprintf('%04d', $b['id']);
            $group = getStatusGroup($b['request_status']);
            $status_label = ($group === 'declined') ? 'Rejected' : ucfirst($b['request_status']);
            $status_class = ($group === 'declined') ? 'rejected' : $group;
            $created = !empty($b['created_at']) ? date('M d, Y', strtotime($b['created_at'])) : 'N/A';
            echo '<li class="ab-list-item" data-booking-id="' . $b['id'] . '">';
            echo '<div class="ab-list-icon"><i data-lucide="alert-triangle"></i></div>';
            echo '<div class="ab-list-main">';
            echo '<div class="ab-list-customer">' . $customer . '</div>';
            echo '<div class="ab-list-meta">Emergency #' . $detail_id . ' &middot; ' . $created . '</div>';
            echo '</div>';
            echo '<div class="ab-list-status ' . $status_class . '">' . $status_label . '</div>';
            echo '<div class="ab-list-arrow"><i data-lucide="chevron-right"></i></div>';
            echo '</li>';
        }
    }
    echo '</ul>';
}

function renderEmergencyDetail($b) {
    global $mechanics;
    $group = getStatusGroup($b['request_status']);
    $customer_name = htmlspecialchars(($b['customer_fullname'] ?: $b['customer_name']) ?: 'Unknown');
    $customer_email = htmlspecialchars($b['customer_email'] ?: 'N/A');
    $customer_phone = !empty($b['customer_phone']) ? format_phone($b['customer_phone']) : 'No contact';
    $motorcycle = htmlspecialchars($b['motorcycle_info'] ?? 'Unknown vehicle');
    $issue = htmlspecialchars($b['motorcycle_issue'] ?? 'N/A');
    $description = htmlspecialchars($b['problem_description'] ?? '');
    $location = htmlspecialchars($b['location_description'] ?? ($b['location'] ?? 'No location'));
    $coords = '';
    if (!empty($b['latitude']) && !empty($b['longitude'])) {
        $coords = number_format($b['latitude'], 5) . ', ' . number_format($b['longitude'], 5);
    }
    $map_link = (!empty($b['latitude']) && !empty($b['longitude'])) ? 'admin_emergency_requests.php?focus_id=' . $b['id'] : '';
    $mechanic = !empty($b['mechanic_fullname']) ? htmlspecialchars($b['mechanic_fullname']) : 'Not assigned';
    $contact = !empty($b['contact_number']) ? format_phone($b['contact_number']) : 'No contact';
    $priority = htmlspecialchars(ucfirst($b['priority'] ?? 'normal'));
    $admin_response = !empty($b['admin_response']) ? htmlspecialchars($b['admin_response']) : '';
    $is_tow = (($b['service_type'] ?? 'onsite_repair') === 'tow_service');
    $service_label = $is_tow ? 'Tow &amp; Lift' : 'Fix On-Site';
    $service_icon = $is_tow ? 'truck' : 'wrench';
    $created = !empty($b['created_at']) ? date('M d, Y g:i A', strtotime($b['created_at'])) : 'N/A';
    $updated = !empty($b['updated_at']) ? date('M d, Y g:i A', strtotime($b['updated_at'])) : 'N/A';

    $detail_id = sprintf('%04d', $b['id']);

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
    $vehicle_img_src = !empty($b['vehicle_image']) ? $b['vehicle_image'] : ($modelImages[$b['moto_model']] ?? null);

    $is_pending = ($group === 'pending');
    $is_accepted = ($group === 'accepted');
    $is_declined = ($group === 'declined');
    $is_completed = ($group === 'completed');

    $status_label = $is_declined ? 'Rejected' : ucfirst($b['request_status']);
    $status_class = ($group === 'declined') ? 'rejected' : $group;

    if ($is_pending) {
        $box_text = 'Request Pending';
        $box_subtext = 'Awaiting admin response.';
    } elseif ($is_accepted) {
        if (strtolower(trim($b['request_status'] ?? '')) === 'assigned') {
            $box_text = 'Mechanic Assigned';
            $box_subtext = 'A mechanic has been assigned. Awaiting acceptance.';
        } else {
            $box_text = 'Request Accepted';
            $box_subtext = 'This emergency request has been accepted.';
        }
    } elseif ($is_declined) {
        $box_text = 'Request Declined';
        $box_subtext = 'This emergency request has been declined.';
    } else {
        $box_text = 'Service Completed';
        $box_subtext = 'This emergency service has been completed.';
    }

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

    $col1 = $detailItem('user', 'Customer', $customer_name) .
            $detailItem('mail', 'Email', $customer_email) .
            $detailItem('phone', 'Phone', $customer_phone) .
            $detailItem('motorbike', 'Vehicle', $motorcycle) .
            $detailItem('alert-triangle', 'Issue Type', $issue) .
            $detailItem($service_icon, 'Service Type', $service_label) .
            $detailItem('phone-call', 'Contact', $contact);

    $col2 = $detailItem('flag', 'Status', $status_label) .
            $detailItem('zap', 'Priority', $priority) .
            $detailItem('wrench', 'Mechanic', $mechanic) .
            $detailItem('calendar', 'Created', $created) .
            $detailItem('clock', 'Updated', $updated) .
            ($coords ? $detailItem('navigation', 'Coordinates', $coords) : '');

    $full = $detailItem('file-text', 'Description', nl2br($description), '', 'ab-detail-item-full') .
            ($location !== 'No location' ? $detailItem('map-pin', 'Location', $location, '', 'ab-detail-item-full') : '') .
            $detailItem('message-square', 'Response', !empty($admin_response) ? $admin_response : 'No response recorded', '', 'ab-detail-item-full');

    $action_forms = '';
    if (!empty($b['image_path'])) {
        $action_forms .= '<a href="' . htmlspecialchars($b['image_path']) . '" target="_blank" class="ab-action-btn print"><i data-lucide="image"></i> View Image</a>';
    }
    if ($map_link) {
        $action_forms .= '<a href="' . $map_link . '" target="_blank" class="ab-action-btn print"><i data-lucide="navigation"></i> Open Map</a>';
    }
    if ($is_accepted || $is_completed) {
        $action_forms .= '<a href="print_emergency_receipt.php?request_id=' . $b['id'] . '" target="_blank" class="ab-action-btn print"><i data-lucide="printer"></i> Print</a>';
    }

    if ($is_pending) {
        $mechanic_options = '';
        foreach ($mechanics as $m) {
            $mechanic_options .= '<option value="' . $m['id'] . '">' . htmlspecialchars($m['name']) . '</option>';
        }
        $action_forms .= '
        <div class="er-actions" id="action-buttons-' . $b['id'] . '">
            <button type="button" class="ab-action-btn manage" onclick="showAcceptForm(' . $b['id'] . ')"><i data-lucide="check-circle"></i> Accept</button>
            <button type="button" class="ab-action-btn reject" onclick="showDeclineForm(' . $b['id'] . ')"><i data-lucide="x-circle"></i> Decline</button>
        </div>
        <form id="accept-form-' . $b['id'] . '" class="er-action-form" style="display:none;" method="POST" action="emergency_status.php?tab=pending" onsubmit="confirmAction(event, \'Accept Emergency?\', \'Are you sure you want to accept this emergency request?\', \'question\', \'Yes, Accept\', \'#10b981\')">
            <input type="hidden" name="request_id" value="' . $b['id'] . '">
            <input type="hidden" name="action" value="accept">
            <select name="mechanic_id" class="er-input" required>
                <option value="">Select mechanic</option>
                ' . $mechanic_options . '
            </select>
            <button type="submit" class="ab-action-btn manage" style="margin-top:0.5rem;"><i data-lucide="check"></i> Confirm Accept</button>
            <button type="button" class="ab-action-btn" style="margin-top:0.5rem;" onclick="hideForms(' . $b['id'] . ')">Cancel</button>
        </form>
        <form id="decline-form-' . $b['id'] . '" class="er-action-form" style="display:none;" method="POST" action="emergency_status.php?tab=pending" onsubmit="confirmAction(event, \'Decline Emergency?\', \'Are you sure you want to decline this emergency request?\', \'warning\', \'Yes, Decline\', \'#ef4444\')">
            <input type="hidden" name="request_id" value="' . $b['id'] . '">
            <input type="hidden" name="action" value="decline">
            <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:0.5rem;">Confirm you want to decline this request.</p>
            <button type="submit" class="ab-action-btn reject" style="margin-top:0.25rem;"><i data-lucide="x"></i> Confirm Decline</button>
            <button type="button" class="ab-action-btn" style="margin-top:0.25rem;" onclick="hideForms(' . $b['id'] . ')">Cancel</button>
        </form>';
    } elseif ($is_accepted) {
        if (strtolower(trim($b['request_status'] ?? '')) === 'assigned') {
            $action_forms .= '
        <form method="POST" action="emergency_status.php?tab=accepted" onsubmit="confirmAction(event, \'Accept Emergency?\', \'Accept this request with the assigned mechanic?\', \'question\', \'Yes, Accept\', \'#10b981\')" style="display:inline-block;">
            <input type="hidden" name="request_id" value="' . $b['id'] . '">
            <input type="hidden" name="action" value="accept">
            <button type="submit" class="ab-action-btn manage" style="margin-top:0.25rem;"><i data-lucide="check-circle"></i> Accept</button>
        </form>';
        }
        $action_forms .= '
        <form method="POST" action="emergency_status.php?tab=accepted" onsubmit="confirmAction(event, \'Mark as Complete?\', \'Are you sure this emergency service is completed?\', \'question\', \'Yes, Complete\', \'#1e3a5f\')" style="display:inline-block;">
            <input type="hidden" name="request_id" value="' . $b['id'] . '">
            <input type="hidden" name="action" value="complete">
            <button type="submit" class="ab-action-btn complete" style="margin-top:0.25rem;"><i data-lucide="flag"></i> Mark as Complete</button>
        </form>';
    }
    ?>
<div class="ab-detail-content" id="details-<?= $b['id'] ?>">
    <div class="ab-detail-grid">
        <div class="ab-detail-card">
            <div class="ab-detail-header">
                <div class="ab-detail-header-main">
                    <div class="ab-detail-header-top">
                        <div class="ab-detail-id">Emergency #<?= $detail_id ?></div>
                        <span class="ab-status-badge"><?= $status_label ?></span>
                    </div>
                    <div class="ab-detail-meta">
                        <span class="ab-detail-meta-item"><i data-lucide="calendar"></i> <?= $created ?></span>
                        <span class="ab-detail-meta-item"><i data-lucide="clock"></i> <?= $updated ?></span>
                    </div>
                </div>
                <?php if ($vehicle_img_src): ?>
                <img class="ab-detail-vehicle-img" src="<?= htmlspecialchars($vehicle_img_src) ?>" alt="<?= htmlspecialchars(trim(($b['moto_brand'] ?? '') . ' ' . ($b['moto_model'] ?? ''))) ?>">
                <?php endif; ?>
            </div>
            <div class="ab-status-banner <?= $status_class ?>">
                <div class="ab-status-banner-title"><?= $box_text ?></div>
                <div class="ab-status-banner-sub"><?= $box_subtext ?></div>
            </div>
            <div class="ab-card-title"><i data-lucide="file-text"></i> Emergency Details</div>
            <div class="ab-detail-body">
                <div class="ab-detail-col">
                    <?= $col1 ?>
                </div>
                <div class="ab-detail-col">
                    <?= $col2 ?>
                </div>
                <?= $full ?>
            </div>
        </div>
    </div>
    <?php if ($action_forms): ?>
    <div class="ab-detail-footer <?= $status_class ?>">
        <?= $action_forms ?>
    </div>
    <?php endif; ?>
</div>
    <?php
}

$requests = [];
$errorMessage = $_GET['error'] ?? '';
$flash = $_SESSION['emergency_flash'] ?? '';
unset($_SESSION['emergency_flash']);
try {
    $stmt = $pdo->query("
        SELECT esr.*,
               CONCAT(m.brand, ' ', m.model, ' (', m.plate_number, ')') as motorcycle_info,
               m.image AS vehicle_image, m.model AS moto_model, m.brand AS moto_brand,
               c.username as customer_name, c.name as customer_fullname, c.phone as customer_phone, c.email as customer_email,
               mech.name as mechanic_fullname
        FROM emergency_service_requests esr
        JOIN motorcycles m ON esr.motorcycle_id = m.id
        JOIN users c ON esr.customer_id = c.id
        LEFT JOIN mechanics mech ON esr.assigned_mechanic_id = mech.id
        ORDER BY esr.created_at DESC
    ");
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errorMessage = 'Database error: ' . $e->getMessage();
}

$mechanics = [];
try {
    $mechStmt = $pdo->query("SELECT id, name FROM mechanics WHERE status = 'Available' ORDER BY name ASC");
    $mechanics = $mechStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mechanics = [];
}

$pendingRequests = [];
$acceptedRequests = [];
$declinedRequests = [];
$completedRequests = [];

foreach ($requests as $r) {
    $group = getStatusGroup($r['request_status']);
    if ($group === 'pending') $pendingRequests[] = $r;
    elseif ($group === 'accepted') $acceptedRequests[] = $r;
    elseif ($group === 'declined') $declinedRequests[] = $r;
    elseif ($group === 'completed') $completedRequests[] = $r;
}

$activeTab = $_GET['tab'] ?? 'accepted';
if (!in_array($activeTab, ['accepted', 'declined', 'completed'])) $activeTab = 'accepted';

$allRequests = array_merge($acceptedRequests, $declinedRequests, $completedRequests);
$counts = [
    'accepted' => count($acceptedRequests),
    'declined' => count($declinedRequests),
    'completed' => count($completedRequests),
];

$pageTitle = 'Emergency Requests Status';
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
        grid-template-columns: 320px 1fr;
        flex: 1;
        min-height: 0;
    }

    .ab-master-pane {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        border-right: 1px solid var(--card-border);
        overflow-y: auto;
        background: var(--card-bg);
        padding: 0.75rem;
        scrollbar-width: none;
    }
    .ab-master-pane::-webkit-scrollbar { display: none; }
    .ab-list { list-style: none; padding: 0; margin: 0; display: none; flex-direction: column; gap: 0.5rem; }
    .ab-list.active { display: flex; }
    .ab-master-search { position: relative; }
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
    .ab-list-status.completed { background: rgba(30, 58, 95, 0.12); color: #1e3a5f; border-color: rgba(30, 58, 95, 0.35); }
    .ab-list-arrow { color: #1e40af; }
    .ab-list-arrow i { width: 16px; height: 16px; }

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
    .ab-detail-content.active { display: flex; flex-direction: column; align-items: center; }

    .ab-detail-grid { width: 100%; max-width: 800px; align-self: center; }
    .ab-detail-card {
        background: #fff;
        border: 1px solid var(--card-border);
        border-radius: 12px;
        padding: 0.8rem;
        width: 100%;
        overflow: hidden;
    }
    .ab-detail-card > .ab-detail-header {
        margin: -0.8rem -0.8rem 0.6rem -0.8rem;
        border-radius: 12px 12px 0 0;
    }
    .ab-detail-card > .ab-status-banner {
        margin: 0 -0.8rem 0.75rem -0.8rem;
        border-radius: 0;
    }
    .ab-detail-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.75rem 0.9rem;
        background: transparent;
        border: none;
        border-radius: 12px;
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
        font-size: 1.05rem;
        font-weight: 800;
        color: #000000;
    }
    .ab-status-badge {
        padding: 0.15rem 0.45rem;
        border-radius: 99px;
        font-size: 0.58rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        border: 1px solid #E5E7EB;
        background: #ffffff;
        color: #000000;
    }
    .ab-detail-meta { display: flex; align-items: center; gap: 0.75rem; }
    .ab-detail-meta-item {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        font-size: 0.72rem;
        color: #000000;
    }
    .ab-detail-meta-item i { width: 13px; height: 13px; color: #1e40af; }
    .ab-detail-vehicle-img {
        width: 170px;
        height: 115px;
        object-fit: cover;
        border-radius: 10px;
        flex-shrink: 0;
    }

    .ab-status-banner {
        display: flex;
        flex-direction: column;
        gap: 0.05rem;
        padding: 0.35rem 0.75rem;
        background: transparent;
        border: none;
    }
    .ab-status-banner.accepted { color: #047857; }
    .ab-status-banner.completed { color: #1e3a5f; }
    .ab-status-banner.rejected { color: #b91c1c; }
    .ab-status-banner.pending { color: #EAB308; }
    .ab-status-banner-title { font-weight: 800; font-size: 0.8rem; }
    .ab-status-banner-sub { font-size: 0.7rem; opacity: 0.85; }

    .ab-card-title {
        font-size: 0.82rem;
        font-weight: 800;
        color: var(--text-main);
        margin-bottom: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }
    .ab-card-title i { width: 16px; height: 16px; color: #1e40af; }

    .ab-detail-body {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.6rem;
    }
    .ab-detail-col { display: flex; flex-direction: column; gap: 0.5rem; }
    .ab-detail-item { display: flex; align-items: flex-start; gap: 0.55rem; }
    .ab-detail-item-full { grid-column: 1 / -1; }
    .ab-detail-icon {
        width: 26px;
        height: 26px;
        border-radius: 8px;
        background: transparent;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #1e40af;
        flex-shrink: 0;
        box-shadow: none;
    }
    .ab-detail-icon i { width: 14px; height: 14px; box-shadow: none; }
    .ab-detail-text { display: flex; flex-direction: column; gap: 0.05rem; min-width: 0; }
    .ab-detail-label {
        font-size: 0.58rem;
        font-weight: 700;
        color: #000000;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .ab-detail-value {
        font-size: 0.78rem;
        font-weight: 700;
        color: #4B5563;
        word-break: break-word;
    }
    .ab-text-green { color: var(--accent-green); }
    .ab-text-gold { color: var(--accent-gold); }
    .ab-text-muted { color: var(--text-muted); }

    .ab-detail-footer {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        margin-top: 0.5rem;
        flex-wrap: wrap;
        width: 100%;
        max-width: 800px;
        align-self: center;
    }
    .ab-detail-footer.accepted { justify-content: flex-end; }
    .ab-detail-footer .ab-action-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        background: rgba(0, 0, 0, 0.03);
        border: 1px solid var(--card-border);
        color: var(--text-main);
        border-radius: 8px;
        padding: 0.35rem 0.6rem;
        font-size: 0.68rem;
        font-weight: 800;
        text-decoration: none;
        transition: all 0.2s ease;
    }
    .ab-detail-footer .ab-action-btn:hover { background: rgba(0, 0, 0, 0.08); color: var(--text-main); }
    .ab-detail-footer .ab-action-btn i { width: 12px; height: 12px; }
    .ab-detail-footer .ab-action-btn.print { background: rgba(30, 58, 95, 0.1); border-color: rgba(30, 58, 95, 0.3); color: #1e3a5f; }
    .ab-detail-footer .ab-action-btn.print:hover { background: rgba(30, 58, 95, 0.2); }
    .ab-detail-footer .ab-action-btn.complete { background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.3); color: #15803d; }
    .ab-detail-footer .ab-action-btn.complete:hover { background: rgba(16, 185, 129, 0.2); }
    .ab-detail-footer .ab-action-btn.manage { background: rgba(250, 204, 21, 0.1); border-color: rgba(250, 204, 21, 0.3); color: #EAB308; }
    .ab-detail-footer .ab-action-btn.manage:hover { background: rgba(250, 204, 21, 0.2); }
    .ab-detail-footer .ab-action-btn.reject { background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.3); color: #b91c1c; }
    .ab-detail-footer .ab-action-btn.reject:hover { background: rgba(239, 68, 68, 0.2); }

    .er-actions { display: flex; align-items: center; gap: 0.4rem; }
    .er-action-form { display: none; margin-top: 0.5rem; }
    .er-action-form .er-input,
    .er-action-form select,
    .er-action-form textarea {
        font-size: 0.78rem;
        padding: 0.35rem 0.5rem;
        border: 1px solid var(--card-border);
        border-radius: 8px;
        background: #fff;
        color: var(--text-main);
        font-family: 'Plus Jakarta Sans', sans-serif;
        width: 100%;
        max-width: 220px;
    }

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
    @media (max-width: 767px) {
        .ab-detail-body { grid-template-columns: 1fr; }
        .ab-detail-header { flex-direction: column; align-items: flex-start; }
    }
</style>

<div class="booking-status-page">
    <?php if ($errorMessage): ?>
        <div class="ab-error"><i data-lucide="alert-circle" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>
    <?php
    if ($flash) {
        $toast_msg = is_array($flash) ? ($flash['msg'] ?? '') : (string)$flash;
        $toast_type = is_array($flash)
            ? ($flash['type'] ?? 'success')
            : (preg_match('/error|invalid|fail/i', $flash) ? 'error' : 'success');
        if ($toast_msg !== '') {
            include 'floating_toast.php';
        }
    }
    ?>

    <div class="ab-tabs">
        <a href="?tab=accepted" class="ab-tab <?= $activeTab === 'accepted' ? 'active' : '' ?>" data-tab="accepted"><i data-lucide="check-circle"></i> Accepted</a>
        <a href="?tab=declined" class="ab-tab <?= $activeTab === 'declined' ? 'active' : '' ?>" data-tab="declined"><i data-lucide="x-circle"></i> Rejected</a>
        <a href="?tab=completed" class="ab-tab <?= $activeTab === 'completed' ? 'active' : '' ?>" data-tab="completed"><i data-lucide="flag"></i> Completed</a>
    </div>

    <div class="ab-main-card">
        <?php if (empty($allRequests) && !$errorMessage): ?>
            <div class="ab-empty-state" style="display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1;">
                <i data-lucide="inbox"></i>
                <h4 style="color: var(--text-main); font-weight: 700;">No emergency requests</h4>
                <p style="font-size: 0.85rem;">There are no accepted, rejected or completed emergency requests at the moment.</p>
            </div>
        <?php else: ?>
            <div class="ab-pane-layout">
                <div class="ab-master-pane">
                    <div class="ab-master-search">
                        <i data-lucide="search"></i>
                        <input type="text" class="ab-list-search" placeholder="Search by customer, issue, location...">
                    </div>
                    <?php renderList($acceptedRequests, 'acceptedList', $activeTab, 'accepted'); ?>
                    <?php renderList($declinedRequests, 'declinedList', $activeTab, 'declined'); ?>
                    <?php renderList($completedRequests, 'completedList', $activeTab, 'completed'); ?>
                    <div class="ab-list-count" id="ab-list-count"><?= count($allRequests) ?> Request<?= count($allRequests) !== 1 ? 's' : '' ?> Found</div>
                </div>

                <div class="ab-detail-pane">
                    <div id="detailPlaceholder" class="ab-empty-state">
                        <i data-lucide="arrow-left-square"></i>
                        <h4 style="color: var(--text-main); font-weight: 700;">Select a request</h4>
                        <p>Click an emergency request on the left to view details.</p>
                    </div>

                    <?php foreach ($allRequests as $b) renderEmergencyDetail($b); ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require 'admin_sidebar_footer.php'; ?>

<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<script>
    function confirmAction(event, title, text, icon, confirmText, confirmColor) {
        event.preventDefault();
        const form = event.target;
        Swal.fire({
            title: title,
            text: text,
            icon: icon,
            showCancelButton: true,
            confirmButtonColor: confirmColor,
            cancelButtonColor: '#6b7280',
            confirmButtonText: confirmText,
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        lucide.createIcons();

        const bookingListItems = document.querySelectorAll('.ab-list-item');
        const detailPlaceholder = document.getElementById('detailPlaceholder');
        const allDetailContents = document.querySelectorAll('.ab-detail-content');
        const tabLinks = document.querySelectorAll('.ab-tab');
        const allLists = document.querySelectorAll('.ab-list');
        const searchInput = document.querySelector('.ab-list-search');
        const listCount = document.getElementById('ab-list-count');

        function resetDetail() {
            if (detailPlaceholder) detailPlaceholder.style.display = 'block';
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

        function updateListCount() {
            const activeList = document.querySelector('.ab-list.active');
            if (!activeList || !listCount) return;
            const visible = Array.from(activeList.querySelectorAll('.ab-list-item')).filter(i => i.style.display !== 'none').length;
            listCount.textContent = visible + ' Request' + (visible !== 1 ? 's' : '') + ' Found';
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

        window.showAcceptForm = function(id) {
            document.getElementById('action-buttons-' + id).style.display = 'none';
            document.getElementById('accept-form-' + id).style.display = 'block';
            document.getElementById('decline-form-' + id).style.display = 'none';
            lucide.createIcons();
        };
        window.showDeclineForm = function(id) {
            document.getElementById('action-buttons-' + id).style.display = 'none';
            document.getElementById('accept-form-' + id).style.display = 'none';
            document.getElementById('decline-form-' + id).style.display = 'block';
            lucide.createIcons();
        };
        window.hideForms = function(id) {
            document.getElementById('action-buttons-' + id).style.display = 'flex';
            document.getElementById('accept-form-' + id).style.display = 'none';
            document.getElementById('decline-form-' + id).style.display = 'none';
            lucide.createIcons();
        };

        const initialTab = '<?= $activeTab ?>';
        switchTab(initialTab);
    });
</script>
