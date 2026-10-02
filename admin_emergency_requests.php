<?php
session_start();
require 'db.php';
require_once 'sms_helper.php';

// Security check: Only admins can access this page
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// --- 1. AJAX Endpoint: Save Boundary Coordinates ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_boundary') {
    header('Content-Type: application/json');
    $coords_json = $_POST['coordinates'] ?? '';

    if (empty($coords_json)) {
        echo json_encode(['success' => false, 'message' => 'No coordinates provided']);
        exit;
    }

    try {
        // Upsert the boundary record
        $stmt = $pdo->prepare("
            INSERT INTO service_boundaries (id, name, coordinates) 
            VALUES (1, 'Default Service Area', ?) 
            ON DUPLICATE KEY UPDATE coordinates = VALUES(coordinates), updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$coords_json]);

        echo json_encode(['success' => true, 'message' => 'Boundary updated successfully!']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

$msg = "";
$msg_type = "";

// Flash message after redirect (prevents form resubmission on reload)
if (isset($_SESSION['admin_emergency_flash'])) {
    $msg = $_SESSION['admin_emergency_flash']['msg'];
    $msg_type = $_SESSION['admin_emergency_flash']['type'];
    unset($_SESSION['admin_emergency_flash']);
}

// --- 2. Accept Emergency Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_request'])) {
    $request_id = $_POST['request_id'] ?? 0;

    if (empty($request_id)) {
        $msg = "❌ Invalid request.";
        $msg_type = "error";
    } else {
        $request = null;
        $smsNote = '';
        try {
            $pdo->beginTransaction();

            $detail_stmt = $pdo->prepare("
                SELECT esr.*, 
                       CONCAT(m.brand, ' ', m.model, ' (', m.plate_number, ')') as motorcycle_info,
                       c.username as customer_name, c.phone as customer_phone, c.id as customer_id,
                       mech.name as mechanic_name
                FROM emergency_service_requests esr
                JOIN motorcycles m ON esr.motorcycle_id = m.id
                JOIN users c ON esr.customer_id = c.id
                LEFT JOIN mechanics mech ON esr.assigned_mechanic_id = mech.id
                WHERE esr.id = ?
            ");
            $detail_stmt->execute([$request_id]);
            $request = $detail_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new Exception('Request not found.');
            }
            $reqStatus = strtolower(trim($request['request_status'] ?? ''));
            if (!in_array($reqStatus, ['pending', 'new', 'assigned'])) {
                throw new Exception('Request is not pending or assigned.');
            }

            $stmt = $pdo->prepare("
                UPDATE emergency_service_requests 
                SET request_status = 'accepted', updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND request_status IN ('pending', 'new', 'assigned')
            ");
            $stmt->execute([$request_id]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('Request is not pending or assigned.');
            }

            if (!empty($request['assigned_mechanic_id'])) {
                $pdo->prepare("UPDATE mechanics SET status = 'Busy' WHERE id = ?")->execute([$request['assigned_mechanic_id']]);
            }

            try {
                $update_stmt = $pdo->prepare("
                    INSERT INTO emergency_request_updates (request_id, update_type, update_message, updated_by)
                    VALUES (?, 'accepted', 'Request accepted by admin', 'Admin')
                ");
                $update_stmt->execute([$request_id]);
            } catch (PDOException $updErr) {
                // Ignore updates-log errors; the main status must still save
            }

            $pdo->commit();
            $msg = "✅ Request accepted.";
            $msg_type = "success";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = "❌ Error: " . $e->getMessage();
            $msg_type = "error";
        }

        if ($msg_type === 'success' && $request) {
            try {
                $sms = new SMSHelper();
                $mechNote = !empty($request['mechanic_name'])
                    ? " Mechanic " . $request['mechanic_name'] . " has been assigned."
                    : " We are now assigning a mechanic.";
                $message = "Hi " . $request['customer_name'] . ", your emergency service request for " . $request['motorcycle_info'] . " has been ACCEPTED." . $mechNote;
                $result = $sms->sendSMS($request['customer_phone'], $message, 'EMERGENCY_ACCEPTED', [
                    'customer_id' => $request['customer_id'],
                    'booking_id' => $request_id,
                    'notification_key' => 'EMERGENCY_ACCEPTED_' . $request_id
                ]);
                if (!$result['success']) {
                    $smsNote = ' SMS not sent: ' . $result['message'];
                }
            } catch (Exception $smsError) {
                $smsNote = ' SMS failed: ' . $smsError->getMessage();
            }

            $_SESSION['emergency_flash'] = $msg . $smsNote;
            header("Location: emergency_status.php?tab=accepted");
            exit;
        }
    }

    $_SESSION['admin_emergency_flash'] = ['msg' => $msg, 'type' => $msg_type];
    header("Location: admin_emergency_requests.php");
    exit;
}

// --- 3. Decline Emergency Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['decline_request'])) {
    $request_id = $_POST['request_id'] ?? 0;

    if (empty($request_id)) {
        $msg = "❌ Invalid request.";
        $msg_type = "error";
    } else {
        $request = null;
        $smsNote = '';
        try {
            $pdo->beginTransaction();

            $detail_stmt = $pdo->prepare("
                SELECT esr.*, 
                       CONCAT(m.brand, ' ', m.model, ' (', m.plate_number, ')') as motorcycle_info,
                       c.username as customer_name, c.phone as customer_phone, c.id as customer_id
                FROM emergency_service_requests esr
                JOIN motorcycles m ON esr.motorcycle_id = m.id
                JOIN users c ON esr.customer_id = c.id
                WHERE esr.id = ?
            ");
            $detail_stmt->execute([$request_id]);
            $request = $detail_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new Exception('Request not found.');
            }

            $stmt = $pdo->prepare("
                UPDATE emergency_service_requests 
                SET request_status = 'declined', updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND request_status != 'declined'
            ");
            $stmt->execute([$request_id]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('Request has already been declined.');
            }

            if (!empty($request['assigned_mechanic_id'])) {
                $assigned_mech = $request['assigned_mechanic_id'];

                $busy_check = $pdo->prepare("
                    SELECT COUNT(*) FROM emergency_service_requests
                    WHERE assigned_mechanic_id = ? AND request_status IN ('assigned', 'accepted', 'in_progress')
                ");
                $busy_check->execute([$assigned_mech]);
                $open_work = (int)$busy_check->fetchColumn();

                $booking_check = $pdo->prepare("SELECT current_booking_id FROM mechanics WHERE id = ?");
                $booking_check->execute([$assigned_mech]);
                $current_booking = $booking_check->fetchColumn();

                if ($open_work === 0 && empty($current_booking)) {
                    $pdo->prepare("UPDATE mechanics SET status = 'Available' WHERE id = ?")->execute([$assigned_mech]);
                }
            }

            try {
                $update_stmt = $pdo->prepare("
                    INSERT INTO emergency_request_updates (request_id, update_type, update_message, updated_by)
                    VALUES (?, 'declined', 'Request declined by admin', 'Admin')
                ");
                $update_stmt->execute([$request_id]);
            } catch (PDOException $updErr) {
                // Ignore updates-log errors; the main status must still save
            }

            $pdo->commit();
            $msg = "✅ Request declined.";
            $msg_type = "success";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = "❌ Error: " . $e->getMessage();
            $msg_type = "error";
        }

        if ($msg_type === 'success' && $request) {
            try {
                $sms = new SMSHelper();
                $message = "Hi " . $request['customer_name'] . ", we are sorry but your emergency service request for " . $request['motorcycle_info'] . " has been DECLINED. Please contact us for more info.";
                $result = $sms->sendSMS($request['customer_phone'], $message, 'EMERGENCY_DECLINED', [
                    'customer_id' => $request['customer_id'],
                    'booking_id' => $request_id,
                    'notification_key' => 'EMERGENCY_DECLINED_' . $request_id
                ]);
                if (!$result['success']) {
                    $smsNote = ' SMS not sent: ' . $result['message'];
                }
            } catch (Exception $smsError) {
                $smsNote = ' SMS failed: ' . $smsError->getMessage();
            }

            $_SESSION['emergency_flash'] = $msg . $smsNote;
            header("Location: emergency_status.php?tab=declined");
            exit;
        }
    }

    $_SESSION['admin_emergency_flash'] = ['msg' => $msg, 'type' => $msg_type];
    header("Location: admin_emergency_requests.php");
    exit;
}

// --- 4. Assign Mechanic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_mechanic'])) {
    $request_id = $_POST['request_id'] ?? 0;
    $mechanic_id = $_POST['mechanic_id'] ?? 0;
    $admin_response = trim($_POST['admin_response'] ?? '');

    if (empty($request_id) || empty($mechanic_id)) {
        $msg = "❌ Please select a mechanic.";
        $msg_type = "error";
    } else {
        $request = null;
        $mechanic_name = 'a mechanic';
        try {
            $pdo->beginTransaction();

            $detail_stmt = $pdo->prepare("
                SELECT esr.*, 
                       CONCAT(m.brand, ' ', m.model, ' (', m.plate_number, ')') as motorcycle_info,
                       c.username as customer_name, c.phone as customer_phone, c.id as customer_id
                FROM emergency_service_requests esr
                JOIN motorcycles m ON esr.motorcycle_id = m.id
                JOIN users c ON esr.customer_id = c.id
                WHERE esr.id = ?
            ");
            $detail_stmt->execute([$request_id]);
            $request = $detail_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new Exception('Request not found.');
            }
            $reqStatus = strtolower(trim($request['request_status'] ?? ''));
            if (!in_array($reqStatus, ['pending', 'new', 'accepted', 'assigned'])) {
                throw new Exception('Request must be pending or accepted before assigning a mechanic.');
            }

            $stmt = $pdo->prepare("
                UPDATE emergency_service_requests 
                SET request_status = 'assigned', assigned_mechanic_id = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND request_status IN ('pending', 'new', 'accepted', 'assigned')
                  AND NOT (assigned_mechanic_id <=> ?)
            ");
            $stmt->execute([$mechanic_id, $request_id, $mechanic_id]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('No changes made. This mechanic may already be assigned or the request status has changed.');
            }

            $pdo->prepare("UPDATE mechanics SET status = 'Busy' WHERE id = ?")->execute([$mechanic_id]);

            try {
                $update_stmt = $pdo->prepare("
                    INSERT INTO emergency_request_updates (request_id, update_type, update_message, updated_by)
                    VALUES (?, 'assigned', ?, 'Admin')
                ");
                $update_stmt->execute([$request_id, $admin_response ?: 'Mechanic assigned by admin']);
            } catch (PDOException $updErr) {
                // Ignore updates-log errors; the main status must still save
            }

            $mech_stmt = $pdo->prepare("SELECT name FROM mechanics WHERE id = ?");
            $mech_stmt->execute([$mechanic_id]);
            $mechanic = $mech_stmt->fetch(PDO::FETCH_ASSOC);
            $mechanic_name = $mechanic ? $mechanic['name'] : 'a mechanic';

            $pdo->commit();
            $msg = "✅ Mechanic assigned successfully!";
            $msg_type = "success";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = "❌ Error: " . $e->getMessage();
            $msg_type = "error";
        }
    }

    $_SESSION['admin_emergency_flash'] = ['msg' => $msg, 'type' => $msg_type];
    header("Location: admin_emergency_requests.php");
    exit;
}

// --- 3. Fetch Saved Service Boundary ---
$saved_boundary = [];
try {
    $stmt = $pdo->query("SELECT coordinates FROM service_boundaries WHERE id = 1 LIMIT 1");
    $boundary_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($boundary_data && !empty($boundary_data['coordinates'])) {
        $saved_boundary = json_decode($boundary_data['coordinates'], true);
    }
} catch (PDOException $e) {
    error_log("Error fetching boundary: " . $e->getMessage());
}

// Default boundary fallback (Tupi region) if none saved yet
if (empty($saved_boundary)) {
    $saved_boundary = [
        [6.4000, 124.8800],
        [6.4200, 124.9800],
        [6.3500, 125.0500],
        [6.2600, 125.0100],
        [6.2500, 124.8900]
    ];
}

// --- 4. Fetch Emergency Requests ---
$emergency_requests = [];
try {
    $stmt = $pdo->query("
        SELECT esr.*, 
               CONCAT(m.brand, ' ', m.model, ' (', m.plate_number, ')') as motorcycle_info,
               c.username as customer_name, c.phone as customer_phone, c.email as customer_email,
               mech.name as mechanic_name
        FROM emergency_service_requests esr
        JOIN motorcycles m ON esr.motorcycle_id = m.id
        JOIN users c ON esr.customer_id = c.id
        LEFT JOIN mechanics mech ON esr.assigned_mechanic_id = mech.id
        ORDER BY esr.created_at DESC
    ");
    $emergency_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching requests: " . $e->getMessage());
}

$statusCounts = array_count_values(array_column($emergency_requests, 'request_status'));
$allCount = count($emergency_requests);
$pendingCount = ($statusCounts['pending'] ?? 0) + ($statusCounts['new'] ?? 0);
$acceptedCount = ($statusCounts['accepted'] ?? 0) + ($statusCounts['assigned'] ?? 0);
$declinedCount = $statusCounts['declined'] ?? 0;
$completedCount = $statusCounts['completed'] ?? 0;

$mechanics = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM mechanics ORDER BY name ASC");
    $mechanics = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching mechanics: " . $e->getMessage());
}

$map_markers = array_filter($emergency_requests, function($r) {
    return !empty($r['latitude']) && !empty($r['longitude']);
});

$pageTitle = 'Emergency Service Requests Management';
?>
<?php require 'admin_sidebar_template.php'; ?>

<!-- Leaflet & Leaflet.draw CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css" />

<style>
    .top-header { position: fixed !important; top: 0; left: var(--sidebar-width); right: 0; z-index: 100; background: #ffffff !important; border-bottom: 2px solid #FACC15 !important; }
    .main-content { padding-top: 75px !important; background: white !important; }

    .emergency-page { font-size: 0.85rem; color: #1e293b; }

    /* Top stat cards */
    .emergency-stats { display: flex; flex-wrap: wrap; gap: 0.6rem; }
    .stat-card {
        flex: 1 1 100px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.55rem 0.75rem;
        box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        display: flex;
        align-items: center;
        gap: 0.6rem;
        min-width: 110px;
        cursor: pointer;
        
    }
    .stat-card:hover { border-color: #cbd5e1; background: #f8fafc; }
    .stat-card.active { border-color: #FACC15; background: #fffbeb; }
    .stat-icon {
        width: 30px; height: 30px;
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.9rem;
    }
    .stat-icon.all { background: #f1f5f9; color: #475569; }
    .stat-icon.pending { background: #fffbeb; color: #FACC15; }
    .stat-icon.accepted { background: #f0fdf4; color: #10b981; }
    .stat-icon.declined { background: #fef2f2; color: #ef4444; }
    .stat-icon.completed { background: rgba(30, 58, 95, 0.12); color: #1e3a5f; }
    .stat-label { font-size: 0.7rem; color: #64748b; }
    .stat-value { font-size: 1.1rem; font-weight: 700; color: #1e293b; line-height: 1; }

    /* Filter pills */
    .filter-pills { gap: 0.25rem; }
    .filter-pills .nav-item { flex: 1 1 auto; text-align: center; }
    .filter-pills .nav-link {
        border-radius: 999px;
        padding: 0.22rem 0.45rem;
        font-size: 0.7rem;
        font-weight: 500;
        color: #475569;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 2px rgba(0,0,0,0.04);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.25rem;
    }
    .filter-pills .nav-link.active {
        background: #FACC15;
        border-color: #FACC15;
        color: #ffffff;
    }
    .filter-pills .nav-link .pill-count {
        background: rgba(0,0,0,0.08);
        color: inherit;
        border-radius: 999px;
        padding: 0.05rem 0.3rem;
        font-size: 0.65rem;
        font-weight: 600;
    }
    .filter-pills .nav-link.active .pill-count { background: rgba(255,255,255,0.25); }

    /* Light panel headers with orange line */
    .emergency-panel {
        background: #ffffff !important;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.06);
        overflow: hidden;
    }
    .emergency-panel .panel-header {
        background: #ffffff;
        color: #1e293b;
        padding: 0.85rem 1rem;
        border-bottom: 1px solid #f1f5f9;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .emergency-panel .panel-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #FACC15, #FDE047, #FACC15);
    }
    .emergency-panel .panel-title {
        font-size: 0.95rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin: 0;
    }
    .emergency-panel .panel-title i { color: #FACC15; }
    .emergency-panel .panel-body { padding: 0.75rem; }

    /* Scrollable request list */
    .emergency-list {
        max-height: calc(100vh - 290px);
        overflow-y: auto;
        padding-right: 0.25rem;
    }
    .emergency-list::-webkit-scrollbar { width: 5px; }
    .emergency-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

    /* Compact request cards */
    .emergency-card {
        background: #ffffff !important;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        overflow: hidden;
        margin-bottom: 0.6rem;
        cursor: pointer;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .emergency-card:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.08); border-color: #cbd5e1; }
    .emergency-card .ecard-header {
        padding: 0.5rem 0.7rem;
        background: #ffffff;
        border-bottom: 1px solid #f1f5f9;
    }
    .emergency-card .ecard-body { padding: 0.55rem 0.7rem; font-size: 0.85rem; }
    .emergency-card .ecard-footer { padding: 0.45rem 0.7rem; background: #ffffff; border-top: 1px solid #f8fafc; }
    .emergency-card .ecard-title { font-size: 0.85rem; font-weight: 600; color: #1e293b; margin: 0; }
    .emergency-card .ecard-meta { font-size: 0.8rem; color: #64748b; }
    .emergency-card .ecard-meta strong { color: #1e293b; }
    .emergency-card .ecard-row { display: flex; align-items: flex-start; gap: 0.4rem; margin-bottom: 0.35rem; }
    .emergency-card .ecard-row i { margin-top: 0.15rem; }
    .emergency-card .ecard-row:last-child { margin-bottom: 0; }
    .emergency-card .ecard-label { font-size: 0.65rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.03em; display: block; }
    .emergency-card .ecard-value { font-size: 0.8rem; color: #1e293b; }

    /* Badges */
    .priority-badge {
        padding: 0.15rem 0.5rem;
        border-radius: 999px;
        font-size: 0.65rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .priority-urgent { background: #fee2e2; color: #991b1b; }
    .priority-high { background: #fef3c7; color: #92400e; }
    .priority-medium { background: #dbeafe; color: #1e40af; }
    .priority-low { background: #f3f4f6; color: #4b5563; }

    .status-pill {
        display: inline-flex;
        align-items: center;
        padding: 0.15rem 0.5rem;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: capitalize;
    }
    .status-pill.pending { background: #fffbeb; color: #EAB308; }
    .status-pill.accepted { background: #f0fdf4; color: #15803d; }
    .status-pill.assigned { background: rgba(30, 58, 95, 0.12); color: #1e3a5f; }
    .status-pill.in_progress { background: #faf5ff; color: #7e22ce; }
    .status-pill.completed { background: #f0fdf4; color: #15803d; }
    .status-pill.declined { background: #fef2f2; color: #b91c1c; }

    /* Collapsible details */
    .emergency-card .details-inner {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 0.55rem 0.65rem;
        margin-top: 0.4rem;
    }
    .emergency-card .action-bar { margin-top: 0.5rem; padding-top: 0.45rem; border-top: 1px solid #e2e8f0; }
    .emergency-card .btn {
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 500;
        padding: 0.15rem 0.45rem;
        line-height: 1.2;
    }
    .emergency-card .form-select { font-size: 0.75rem; padding: 0.25rem 0.45rem; border-radius: 6px; }

    /* Map */
    #adminLiveMap {
        height: calc(100vh - 290px);
        min-height: 420px;
        width: 100%;
        border-radius: 10px;
    }
    .assign-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    .assign-modal-overlay.active { display: flex; }
    .assign-modal {
        background: #fff;
        border-radius: 12px;
        padding: 1.25rem;
        width: 100%;
        max-width: 420px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
    }
    .assign-modal h5 { margin-top: 0; margin-bottom: 1rem; font-weight: 700; color: #1e293b; }
    .assign-modal .form-select { font-size: 0.85rem; padding: 0.5rem; border-radius: 8px; margin-bottom: 1rem; }
    .assign-modal .modal-actions { display: flex; gap: 0.5rem; justify-content: flex-end; }

    /* ===== Dark mode overrides ===== */
    html[data-theme="dark"] .emergency-page { color: #e2e8f0; }

    html[data-theme="dark"] .emergency-panel {
        background: #1a2b4f !important;
        border-color: rgba(255, 255, 255, 0.09);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
    }
    html[data-theme="dark"] .emergency-panel .panel-header {
        background: #1a2b4f;
        color: #e2e8f0;
        border-bottom-color: rgba(255, 255, 255, 0.09);
    }
    html[data-theme="dark"] .panel-title { color: #e2e8f0; }

    html[data-theme="dark"] .stat-card { box-shadow: 0 1px 4px rgba(0, 0, 0, 0.4); }
    html[data-theme="dark"] .stat-card:hover { background: #22335a; border-color: #475569; }
    html[data-theme="dark"] .stat-card.active { background: rgba(250, 204, 21, 0.08); border-color: #FACC15; }
    html[data-theme="dark"] .stat-icon.all { background: #2a3d6b; color: #94a3b8; }
    html[data-theme="dark"] .stat-icon.pending { background: rgba(250, 204, 21, 0.15); color: #FDE047; }
    html[data-theme="dark"] .stat-icon.accepted { background: rgba(16, 185, 129, 0.15); color: #34d399; }
    html[data-theme="dark"] .stat-icon.declined { background: rgba(239, 68, 68, 0.15); color: #f87171; }
    html[data-theme="dark"] .stat-icon.completed { background: rgba(59, 130, 246, 0.15); color: #93c5fd; }
    html[data-theme="dark"] .stat-label { color: #94a3b8; }
    html[data-theme="dark"] .stat-value { color: #e2e8f0; }

    html[data-theme="dark"] .filter-pills .nav-link {
        background: #1a2b4f;
        border-color: rgba(255, 255, 255, 0.09);
        color: #cbd5e1;
    }
    html[data-theme="dark"] .filter-pills .nav-link.active { background: #FACC15; border-color: #FACC15; color: #111827; }
    html[data-theme="dark"] .filter-pills .nav-link .pill-count { background: rgba(255, 255, 255, 0.1); }
    html[data-theme="dark"] .filter-pills .nav-link.active .pill-count { background: rgba(0, 0, 0, 0.15); }

    html[data-theme="dark"] .emergency-card {
        background: #16233f !important;
        border-color: rgba(255, 255, 255, 0.09);
    }
    html[data-theme="dark"] .emergency-card:hover { border-color: #3b82f6; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.4); }
    html[data-theme="dark"] .emergency-card .ecard-header { background: #1a2b4f; border-bottom-color: rgba(255, 255, 255, 0.08); }
    html[data-theme="dark"] .emergency-card .ecard-footer { background: #1a2b4f; border-top-color: rgba(255, 255, 255, 0.06); }
    html[data-theme="dark"] .emergency-card .ecard-title,
    html[data-theme="dark"] .emergency-card .ecard-value,
    html[data-theme="dark"] .emergency-card .ecard-meta strong { color: #e2e8f0; }
    html[data-theme="dark"] .emergency-card .ecard-meta { color: #94a3b8; }
    html[data-theme="dark"] .emergency-card .details-inner { background: #1a2b4f; border-color: rgba(255, 255, 255, 0.09); }
    html[data-theme="dark"] .emergency-card .action-bar { border-top-color: rgba(255, 255, 255, 0.09); }
    html[data-theme="dark"] .emergency-list::-webkit-scrollbar-thumb { background: #3b4d7d; }

    html[data-theme="dark"] .priority-urgent { background: rgba(239, 68, 68, 0.15); color: #fca5a5; }
    html[data-theme="dark"] .priority-high { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }
    html[data-theme="dark"] .priority-medium { background: rgba(59, 130, 246, 0.15); color: #93c5fd; }
    html[data-theme="dark"] .priority-low { background: rgba(148, 163, 184, 0.15); color: #cbd5e1; }

    html[data-theme="dark"] .status-pill.pending { background: rgba(250, 204, 21, 0.15); color: #FDE047; }
    html[data-theme="dark"] .status-pill.accepted,
    html[data-theme="dark"] .status-pill.completed { background: rgba(16, 185, 129, 0.15); color: #34d399; }
    html[data-theme="dark"] .status-pill.assigned { background: rgba(59, 130, 246, 0.15); color: #93c5fd; }
    html[data-theme="dark"] .status-pill.in_progress { background: rgba(139, 92, 246, 0.15); color: #c4b5fd; }
    html[data-theme="dark"] .status-pill.declined { background: rgba(239, 68, 68, 0.15); color: #f87171; }

    html[data-theme="dark"] .assign-modal { background: #1a2b4f; color: #e2e8f0; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.6); }
    html[data-theme="dark"] .assign-modal h5 { color: #e2e8f0; }
    html[data-theme="dark"] .assign-modal .form-label { color: #94a3b8; }

    html[data-theme="dark"] .btn-outline-secondary { color: #cbd5e1; border-color: #475569; }
    html[data-theme="dark"] .btn-outline-secondary:hover { background: #2a3d6b; border-color: #64748b; color: #e2e8f0; }
    html[data-theme="dark"] .btn-outline-primary { color: #93c5fd; border-color: #3b82f6; }
    html[data-theme="dark"] .btn-outline-primary:hover { background: #3b82f6; color: #fff; }

    /* Leaflet controls */
    html[data-theme="dark"] .leaflet-bar a,
    html[data-theme="dark"] .leaflet-control-layers {
        background: #22335a;
        color: #e2e8f0;
        border-color: rgba(255, 255, 255, 0.1);
    }
    html[data-theme="dark"] .leaflet-bar a:hover { background: #2a3d6b; }
    html[data-theme="dark"] .leaflet-control-attribution { background: rgba(21, 31, 54, 0.8); color: #64748b; }
    html[data-theme="dark"] .leaflet-control-attribution a { color: #60a5fa; }
    html[data-theme="dark"] .leaflet-tile { filter: brightness(0.85) saturate(0.85); }
</style>

<div class="container-fluid emergency-page">
    <?php $toast_msg = $msg; $toast_type = $msg_type; include 'floating_toast.php'; ?>



    <div class="row g-3">
        <!-- Left: scrollable request list -->
        <div class="col-lg-4">
            <div class="emergency-panel h-100">
                <div class="panel-header">
                    <h4 class="panel-title"><i class="bi bi-ambulance"></i> Emergency Requests</h4>
                </div>
                <div class="panel-body">
                    <div class="emergency-list">
                        <?php foreach ($emergency_requests as $request): ?>
                            <?php
                            $reqStatus = strtolower(trim($request['request_status'] ?? ''));
                            if (!in_array($reqStatus, ['pending', 'new', 'assigned'])) continue;
                            ?>
                            <div class="request-wrapper" data-status="<?= $request['request_status'] ?>">
                                <div class="card emergency-card request-locator" data-request-id="<?= $request['id'] ?>">
                                    <div class="ecard-header">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h5 class="ecard-title"><?= htmlspecialchars($request['customer_name']) ?></h5>
                                            <div class="d-flex align-items-center gap-1">
                                                <span class="status-pill <?= strtolower($request['request_status'] ?? '') ?>"><?= ucfirst($request['request_status'] ?? '') ?></span>
                                                <span class="priority-badge priority-<?= strtolower($request['priority'] ?? 'medium') ?>"><?= $request['priority'] ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="ecard-body">
                                        <div class="ecard-row">
                                            <i class="bi bi-bicycle text-muted"></i>
                                            <div>
                                                <span class="ecard-label">Motorcycle</span>
                                                <span class="ecard-value"><?= htmlspecialchars($request['motorcycle_info']) ?></span>
                                            </div>
                                        </div>
                                        <div class="ecard-row">
                                            <?php if (($request['service_type'] ?? 'onsite_repair') === 'tow_service'): ?>
                                                <i class="bi bi-truck text-warning"></i>
                                                <div>
                                                    <span class="ecard-label">Service</span>
                                                    <span class="ecard-value">Tow &amp; Lift</span>
                                                </div>
                                            <?php else: ?>
                                                <i class="bi bi-wrench-adjustable-circle text-primary"></i>
                                                <div>
                                                    <span class="ecard-label">Service</span>
                                                    <span class="ecard-value">Fix On-Site</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ecard-row">
                                            <i class="bi bi-geo-alt text-danger"></i>
                                            <div>
                                                <span class="ecard-label">Location</span>
                                                <span class="ecard-value"><?= htmlspecialchars($request['location']) ?></span>
                                            </div>
                                        </div>
                                        <div class="ecard-row">
                                            <i class="bi bi-telephone text-success"></i>
                                            <div>
                                                <span class="ecard-label">Contact</span>
                                                <span class="ecard-value"><?= htmlspecialchars($request['customer_phone']) ?></span>
                                            </div>
                                        </div>
                                        <div class="ecard-row">
                                            <i class="bi bi-info-circle text-secondary"></i>
                                            <div>
                                                <span class="ecard-label">Status</span>
                                                <span class="ecard-value"><?= ucfirst($request['request_status']) ?></span>
                                            </div>
                                        </div>
                                        <?php if (!empty($request['mechanic_name'])): ?>
                                            <div class="ecard-row">
                                                <i class="bi bi-person-gear text-primary"></i>
                                                <div>
                                                    <span class="ecard-label">Mechanic</span>
                                                    <span class="ecard-value"><?= htmlspecialchars($request['mechanic_name']) ?></span>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (in_array(strtolower(trim($request['request_status'] ?? '')), ['pending', 'new'])): ?>
                                            <div class="action-bar" style="display: flex; gap: 0.5rem;">
                                                <form method="POST" onclick="event.stopPropagation();" style="display: flex; gap: 0.5rem;">
                                                    <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                    <button type="button" class="btn btn-success" onclick="confirmBtn(this.form, 'accept_request', 'Accept Emergency?', 'Are you sure you want to accept this emergency request?', 'question', 'Yes, Accept', '#10b981')"><i class="bi bi-check me-1"></i> Accept</button>
                                                    <button type="button" class="btn btn-danger" onclick="confirmBtn(this.form, 'decline_request', 'Decline Emergency?', 'Are you sure you want to decline this emergency request?', 'warning', 'Yes, Decline', '#ef4444')"><i class="bi bi-x me-1"></i> Decline</button>
                                                </form>
                                                <button type="button" class="btn btn-primary" onclick="event.stopPropagation(); openAssignModal(<?= $request['id'] ?>)"><i class="bi bi-tools me-1"></i> Assign Mechanic</button>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (strtolower(trim($request['request_status'] ?? '')) === 'assigned'): ?>
                                            <div class="action-bar" style="display: flex; gap: 0.5rem;">
                                                <form method="POST" onclick="event.stopPropagation();">
                                                    <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                    <button type="button" class="btn btn-success" onclick="confirmBtn(this.form, 'accept_request', 'Accept Emergency?', 'Accept this request with mechanic <?= htmlspecialchars($request['mechanic_name'] ?? '') ?>?', 'question', 'Yes, Accept', '#10b981')"><i class="bi bi-check me-1"></i> Accept</button>
                                                </form>
                                                <button type="button" class="btn btn-outline-primary" onclick="event.stopPropagation(); openAssignModal(<?= $request['id'] ?>)"><i class="bi bi-tools me-1"></i> Re-assign</button>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (strtolower(trim($request['request_status'] ?? '')) === 'accepted'): ?>
                                            <div class="action-bar">
                                                <button type="button" class="btn btn-primary" onclick="event.stopPropagation(); openAssignModal(<?= $request['id'] ?>)"><i class="bi bi-tools me-1"></i> Assign Mechanic</button>
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

        <!-- Right: service coverage map -->
        <div class="col-lg-8">
            <div class="emergency-panel h-100">
                <div class="panel-header">
                    <h4 class="panel-title"><i class="bi bi-map"></i> Service Coverage</h4>
                    <div class="btn-group">
                        <button id="btnSaveBoundary" class="btn btn-sm btn-success" style="display:none;">
                            <i class="bi bi-save me-1"></i> Save Boundary
                        </button>
                        <button id="btnResetView" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-bounding-box me-1"></i> Center Boundary
                        </button>
                    </div>
                </div>
                <div class="panel-body">
                    <div id="adminLiveMap"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Assign Mechanic Modal -->
<div id="assignMechanicModal" class="assign-modal-overlay" onclick="if(event.target === this) closeAssignModal()">
    <div class="assign-modal">
        <h5><i class="bi bi-tools me-2"></i>Assign Mechanic</h5>
        <form method="POST" onclick="event.stopPropagation();">
            <input type="hidden" name="request_id" id="modalRequestId" value="">
            <input type="hidden" name="admin_response" value="Mechanic assigned by admin">
            <input type="hidden" name="assign_mechanic" value="1">
            <label for="modalMechanicId" class="form-label" style="font-size: 0.8rem;">Select Mechanic</label>
            <select name="mechanic_id" id="modalMechanicId" class="form-select form-select-sm" required>
                <option value="">-- Select Mechanic --</option>
                <?php foreach ($mechanics as $m): ?>
                    <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="closeAssignModal()">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="confirmAssign(this.form)">Assign</button>
            </div>
        </form>
    </div>
</div>

<!-- Leaflet & Leaflet.draw Scripts -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js"></script>

<script>
// 1. Initialize Leaflet Map
const adminMap = L.map('adminLiveMap').setView([6.3333, 124.9500], 12);

const streetLayer = L.tileLayer('https://mt1.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
    attribution: '&copy; Google Maps'
});

const satelliteLayer = L.tileLayer('https://mt1.google.com/vt/lyrs=s&x={x}&y={y}&z={z}', {
    attribution: '&copy; Google Maps'
});

const baseMaps = {
    "Street": streetLayer,
    "Satellite": satelliteLayer
};

L.control.layers(baseMaps).addTo(adminMap);
streetLayer.addTo(adminMap);

// 2. Feature Group for Editable Layers
const drawnItems = new L.FeatureGroup();
adminMap.addLayer(drawnItems);

// Saved coordinates from PHP database fetch
let currentBoundaryCoords = <?= json_encode($saved_boundary); ?>;

// Render initial polygon from saved database coordinates
let activePolygon = L.polygon(currentBoundaryCoords, {
    color: '#ef4444',
    fillColor: '#ef4444',
    fillOpacity: 0.15,
    weight: 2,
    dashArray: '5, 10'
});
drawnItems.addLayer(activePolygon);

// 3. Initialize Leaflet Draw Control Toolbar
const drawControl = new L.Control.Draw({
    edit: {
        featureGroup: drawnItems,
        remove: true
    },
    draw: {
        polygon: {
            allowIntersection: false,
            showArea: true,
            shapeOptions: {
                color: '#ef4444',
                fillColor: '#ef4444',
                fillOpacity: 0.2
            }
        },
        polyline: false,
        circle: false,
        rectangle: false,
        marker: false,
        circlemarker: false
    }
});
adminMap.addControl(drawControl);

// Flag to show/hide save button
const btnSave = document.getElementById('btnSaveBoundary');

function getActivePolygonCoords() {
    let coords = [];
    drawnItems.eachLayer(function(layer) {
        if (layer instanceof L.Polygon) {
            const latLngs = layer.getLatLngs()[0];
            coords = latLngs.map(pt => [pt.lat, pt.lng]);
        }
    });
    return coords;
}

// Event: User created a new polygon
adminMap.on(L.Draw.Event.CREATED, function (e) {
    // Remove previous boundaries to enforce a single coverage zone
    drawnItems.clearLayers();
    const layer = e.layer;
    drawnItems.addLayer(layer);
    btnSave.style.display = 'inline-block';
});

// Event: User edited or deleted the polygon
adminMap.on(L.Draw.Event.EDITED, function () { btnSave.style.display = 'inline-block'; });
adminMap.on(L.Draw.Event.DELETED, function () { btnSave.style.display = 'inline-block'; });

// 4. Save Boundary to Database via AJAX
btnSave.addEventListener('click', function() {
    const coords = getActivePolygonCoords();

    if (coords.length < 3) {
        alert('Please draw a valid closed boundary polygon with at least 3 points.');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'save_boundary');
    formData.append('coordinates', JSON.stringify(coords));

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAlert('success', data.message);
            btnSave.style.display = 'none';
            currentBoundaryCoords = coords;
            refreshMarkers(); // Re-evaluate request markers against new boundary
        } else {
            showAlert('danger', 'Error: ' + data.message);
        }
    })
    .catch(err => {
        showAlert('danger', 'Failed to communicate with server.');
    });
});

// 5. Ray-casting Algorithm: Check Point-In-Polygon
function isPointInPolygon(point, vs) {
    const x = point[0], y = point[1];
    let inside = false;
    for (let i = 0, j = vs.length - 1; i < vs.length; j = i++) {
        const xi = vs[i][0], yi = vs[i][1];
        const xj = vs[j][0], yj = vs[j][1];
        const intersect = ((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi);
        if (intersect) inside = !inside;
    }
    return inside;
}

// 6. Plot Request Markers
const requestLocations = <?= json_encode(array_values($map_markers)); ?>;
const markerGroup = L.layerGroup().addTo(adminMap);
const requestMarkers = {};

function refreshMarkers() {
    markerGroup.clearLayers();
    const coords = getActivePolygonCoords().length > 0 ? getActivePolygonCoords() : currentBoundaryCoords;

    if (requestLocations.length > 0) {
        requestLocations.forEach(req => {
            const point = [parseFloat(req.latitude), parseFloat(req.longitude)];
            const inside = isPointInPolygon(point, coords);

            const marker = L.marker(point);
            const popupContent = `
                <strong>${req.customer_name}</strong><br>
                <em>${req.motorcycle_info}</em><br>
                <small>Phone: ${req.customer_phone}</small><br>
                <div class="mt-1">
                    ${inside 
                        ? '<span class="badge bg-success">Within Service Area</span>' 
                        : '<span class="badge bg-danger">OUTSIDE SERVICE AREA</span>'}
                </div>
                <a href="https://www.google.com/maps?q=${req.latitude},${req.longitude}" target="_blank" class="d-block mt-2">Get Directions</a>
            `;
            marker.bindPopup(popupContent);
            requestMarkers[req.id] = marker;
            markerGroup.addLayer(marker);
        });
    }
}

refreshMarkers();

// 7. Click request card to locate on map
document.querySelectorAll('.request-locator').forEach(card => {
    card.addEventListener('click', function() {
        const reqId = this.getAttribute('data-request-id');
        const marker = requestMarkers[reqId];
        if (marker) {
            adminMap.flyTo(marker.getLatLng(), 17);
            marker.openPopup();
        }
    });
});

// 7b. Focus marker from URL focus_id (e.g. from emergency_status "Open Map")
const urlParams = new URLSearchParams(window.location.search);
const focusReqId = urlParams.get('focus_id');
if (focusReqId && requestMarkers[focusReqId]) {
    setTimeout(() => {
        const marker = requestMarkers[focusReqId];
        adminMap.flyTo(marker.getLatLng(), 17);
        marker.openPopup();
    }, 400);
}

// Map Action Controls
document.getElementById('btnResetView').addEventListener('click', function() {
    if (drawnItems.getLayers().length > 0) {
        adminMap.fitBounds(drawnItems.getBounds(), { padding: [20, 20] });
    }
});

function showAlert(type, text) {
    showToast(text, type);
}

function openAssignModal(requestId) {
    document.getElementById('modalRequestId').value = requestId;
    document.getElementById('assignMechanicModal').classList.add('active');
}

function closeAssignModal() {
    document.getElementById('assignMechanicModal').classList.remove('active');
}

function confirmBtn(form, actionName, title, text, icon, confirmText, confirmColor) {
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
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = actionName;
            input.value = '1';
            form.appendChild(input);
            form.submit();
        }
    });
}

function confirmAssign(form) {
    const mechanicSelect = form.querySelector('select[name="mechanic_id"]');
    if (!mechanicSelect || !mechanicSelect.value) {
        Swal.fire({ icon: 'warning', title: 'No mechanic selected', text: 'Please select a mechanic first.', confirmButtonColor: '#FACC15' });
        return;
    }
    Swal.fire({
        title: 'Assign Mechanic?',
        text: 'Are you sure you want to assign this mechanic?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#1e3a5f',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, Assign',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });
}
</script>

<?php require 'admin_footer.php'; ?>