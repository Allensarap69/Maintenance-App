<?php
session_start();
require 'db.php';
require 'motorcycle_health_helper.php';
require_once 'sms_helper.php';

// Check if the user is an admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$msg = "";
$msg_type = "";
if (isset($_SESSION['mech_assign_flash'])) {
    $msg = $_SESSION['mech_assign_flash']['msg'];
    $msg_type = $_SESSION['mech_assign_flash']['type'];
    unset($_SESSION['mech_assign_flash']);
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

// --- 1. Logic to Handle Mechanic Assignment POST Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_mechanic'])) {
    $booking_id = $_POST['booking_id'] ?? null;
    $mechanic_id = $_POST['mechanic_id'] ?? null;

    if (!$booking_id || !$mechanic_id) {
        $msg = "❌ Invalid Booking ID or Mechanic ID provided.";
        $msg_type = "error";
    } else {
        $pdo->beginTransaction();
        try {
            // A. Update the booking status and assign the mechanic
            // Change status from 'pending' or 'unassigned' to 'assigned'
            $stmt_booking = $pdo->prepare("UPDATE bookings SET mechanic_id = ?, status = 'assigned' WHERE id = ? AND (status = 'pending' OR status = 'unassigned')");
            $stmt_booking->execute([$mechanic_id, $booking_id]);

            if ($stmt_booking->rowCount() > 0) {
                $stmt_mechanic = $pdo->prepare("UPDATE mechanics SET status = 'Busy', current_booking_id = ? WHERE id = ?");
                $stmt_mechanic->execute([$booking_id, $mechanic_id]);

                // Fetch mechanic name and customer details for SMS
                $mech_name_stmt = $pdo->prepare("SELECT name FROM mechanics WHERE id = ?");
                $mech_name_stmt->execute([$mechanic_id]);
                $mechanic_name = $mech_name_stmt->fetchColumn() ?: 'a mechanic';

                $cust_stmt = $pdo->prepare("
                    SELECT b.user_id, u.username AS customer_name, u.phone AS customer_phone
                    FROM bookings b
                    JOIN users u ON b.user_id = u.id
                    WHERE b.id = ?
                ");
                $cust_stmt->execute([$booking_id]);
                $customer = $cust_stmt->fetch(PDO::FETCH_ASSOC);

                $pdo->commit();
                $msg = "✅ Booking #{$booking_id} successfully assigned to the selected mechanic!";
                $msg_type = "success";

                // Send SMS notification
                $smsNote = '';
                try {
                    if ($customer && !empty($customer['customer_phone'])) {
                        $sms = new SMSHelper();
                        $message = "Hi " . $customer['customer_name'] . ", a mechanic (" . $mechanic_name . ") has been assigned to your booking #" . $booking_id . ".";
                        $result = $sms->sendSMS($customer['customer_phone'], $message, 'MECHANIC_ASSIGNED', [
                            'customer_id' => $customer['user_id'],
                            'booking_id' => $booking_id,
                            'notification_key' => 'MECHANIC_ASSIGNED_' . $booking_id
                        ]);
                        if (!$result['success']) {
                            $smsMessage = is_array($result['message'] ?? '') ? json_encode($result['message']) : ($result['message'] ?? '');
                            $smsNote = ' SMS not sent: ' . $smsMessage;
                        } else {
                            $smsNote = ' SMS sent.';
                        }
                    } else {
                        $smsNote = ' SMS not sent: customer phone not found.';
                    }
                } catch (Exception $smsError) {
                    $smsNote = ' SMS failed: ' . $smsError->getMessage();
                }
                $msg .= $smsNote;
            } else {
                $pdo->rollBack();
                $msg = "❌ Assignment failed. Booking #{$booking_id} might already be assigned or its status is not pending/unassigned.";
                $msg_type = "error";
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "❌ Database Error during assignment: " . $e->getMessage();
            $msg_type = "error";
        }
    }
    $_SESSION['mech_assign_flash'] = ['msg' => $msg, 'type' => $msg_type];
    header("Location: mechanic_assignment.php");
    exit;
}
// --- END Assignment Logic ---

// --- Complete Booking Logic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_booking'])) {
    $booking_id = $_POST['booking_id'] ?? null;

    if (!$booking_id) {
        $msg = "❌ Invalid Booking ID provided.";
        $msg_type = "error";
    } else {
        $pdo->beginTransaction();
        try {
            // Find the assigned mechanic so we can free them
            $stmt_check = $pdo->prepare("SELECT mechanic_id FROM bookings WHERE id = ?");
            $stmt_check->execute([$booking_id]);
            $mechanic_id = $stmt_check->fetchColumn();

            // Mark the booking as completed
            $stmt = $pdo->prepare("UPDATE bookings SET status = 'completed' WHERE id = ?");
            $stmt->execute([$booking_id]);

            recordCompletedBookingHistory($booking_id);

            // Free up the mechanic if one was assigned
            if ($mechanic_id) {
                $pdo->prepare("UPDATE mechanics SET current_booking_id = NULL WHERE id = ?")->execute([$mechanic_id]);

                $busy_check = $pdo->prepare("
                    SELECT
                        (SELECT COUNT(*) FROM emergency_service_requests WHERE assigned_mechanic_id = ? AND request_status IN ('assigned','accepted','in_progress')) +
                        (SELECT COUNT(*) FROM bookings WHERE mechanic_id = ? AND status IN ('assigned','accepted','in_progress')) +
                        (SELECT COUNT(*) FROM booking_mechanics bm JOIN bookings b ON b.id = bm.booking_id WHERE bm.mechanic_id = ? AND b.status IN ('assigned','accepted','in_progress')) +
                        (SELECT COUNT(*) FROM mechanics WHERE id = ? AND current_booking_id IS NOT NULL)
                ");
                $busy_check->execute([$mechanic_id, $mechanic_id, $mechanic_id, $mechanic_id]);
                if ((int)$busy_check->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE mechanics SET status = 'Available' WHERE id = ?")->execute([$mechanic_id]);
                }
            }

            $pdo->commit();
            $msg = "✅ Booking #{$booking_id} marked as completed!";
            $msg_type = "success";
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "❌ Database Error: " . $e->getMessage();
            $msg_type = "error";
        }
    }
    $_SESSION['mech_assign_flash'] = ['msg' => $msg, 'type' => $msg_type];
    header("Location: mechanic_assignment.php");
    exit;
}
// --- END Complete Booking Logic ---

// --- Complete Emergency Logic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_emergency'])) {
    $emergency_id = (int)($_POST['emergency_id'] ?? 0);

    if ($emergency_id <= 0) {
        $msg = "❌ Invalid emergency request ID.";
        $msg_type = "error";
    } else {
        $pdo->beginTransaction();
        try {
            $req = $pdo->prepare("SELECT assigned_mechanic_id FROM emergency_service_requests WHERE id = ?");
            $req->execute([$emergency_id]);
            $assigned = $req->fetchColumn();

            $pdo->prepare("UPDATE emergency_service_requests SET request_status = 'completed', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$emergency_id]);

            recordCompletedEmergencyHistory($emergency_id);

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

            $pdo->commit();
            $msg = "✅ Emergency request #{$emergency_id} marked as completed!";
            $msg_type = "success";
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "❌ Database Error: " . $e->getMessage();
            $msg_type = "error";
        }
    }
    $_SESSION['mech_assign_flash'] = ['msg' => $msg, 'type' => $msg_type];
    header("Location: mechanic_assignment.php");
    exit;
}
// --- END Complete Emergency Logic ---


// --- Sync mechanic statuses: Busy while they have unfinished assigned/accepted/in-progress work ---
try {
    $mech_rows = $pdo->query("SELECT id, status, current_booking_id FROM mechanics")->fetchAll(PDO::FETCH_ASSOC);
    $active_check = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM emergency_service_requests
             WHERE assigned_mechanic_id = ? AND request_status IN ('assigned','accepted','in_progress')) +
            (SELECT COUNT(*) FROM bookings
             WHERE mechanic_id = ? AND status IN ('assigned','accepted','in_progress')) +
            (SELECT COUNT(*) FROM booking_mechanics bm
             JOIN bookings b ON b.id = bm.booking_id
             WHERE bm.mechanic_id = ? AND b.status IN ('assigned','accepted','in_progress'))
    ");
    $booking_active = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE id = ? AND status IN ('assigned','accepted','in_progress')");
    $clear_current = $pdo->prepare("UPDATE mechanics SET current_booking_id = NULL WHERE id = ?");
    $set_status = $pdo->prepare("UPDATE mechanics SET status = ? WHERE id = ?");
    foreach ($mech_rows as $mr) {
        // A current_booking_id pointing at a finished/deleted booking is stale — clear it
        if (!empty($mr['current_booking_id'])) {
            $booking_active->execute([$mr['current_booking_id']]);
            if ((int)$booking_active->fetchColumn() === 0) {
                $clear_current->execute([$mr['id']]);
                $mr['current_booking_id'] = null;
            }
        }
        $active_check->execute([$mr['id'], $mr['id'], $mr['id']]);
        $has_active = ((int)$active_check->fetchColumn() > 0) || !empty($mr['current_booking_id']);
        if ($has_active && $mr['status'] !== 'Busy') {
            $set_status->execute(['Busy', $mr['id']]);
        } elseif (!$has_active && $mr['status'] === 'Busy') {
            $set_status->execute(['Available', $mr['id']]);
        }
    }
} catch (PDOException $e) {
    error_log("Mechanic status sync error: " . $e->getMessage());
}

// --- 2. Fetch Unassigned Bookings (To show in the table) ---
$unassigned_bookings = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            b.id, b.schedule_date, b.schedule_start_time, b.total_price,
            v.brand AS make, v.model, v.plate_number, v.image,
            u.username AS customer_name,
            b.service_ids, b.status,
            b.mechanic_id, m.name AS mechanic_name
        FROM 
            bookings b
        JOIN 
            motorcycles v ON b.vehicle_id = v.id
        JOIN 
            users u ON b.user_id = u.id
        LEFT JOIN 
            mechanics m ON b.mechanic_id = m.id
        WHERE 
            b.status IN ('unassigned', 'assigned', 'accepted', 'in_progress') 
        ORDER BY 
            b.schedule_date, b.schedule_start_time ASC
    ");
    $stmt->execute();
    $unassigned_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Log the error but don't stop the page
    // $msg = "Error fetching bookings: " . $e->getMessage(); 
}

// --- 2b. Fetch assigned/accepted emergency requests (admin-committed emergency work) ---
$assigned_emergencies = [];
try {
    $stmt = $pdo->query("
        SELECT esr.id, esr.request_status, esr.priority, esr.motorcycle_issue, esr.problem_description,
               esr.created_at, c.username AS customer_name, mech.name AS mechanic_name,
               CONCAT(m.brand, ' ', m.model, ' (', m.plate_number, ')') AS motorcycle_info
        FROM emergency_service_requests esr
        JOIN users c ON esr.customer_id = c.id
        LEFT JOIN motorcycles m ON esr.motorcycle_id = m.id
        LEFT JOIN mechanics mech ON esr.assigned_mechanic_id = mech.id
        WHERE esr.request_status IN ('assigned','accepted','in_progress')
        ORDER BY esr.created_at DESC
    ");
    $assigned_emergencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching assigned emergencies: " . $e->getMessage());
}

// --- 3. Fetch Available Mechanics (To show in the dropdown) ---
$available_mechanics = [];
try {
    $stmt = $pdo->query("
        SELECT m.id, m.name,
               GROUP_CONCAT(DISTINCT s.specialty_name ORDER BY s.specialty_name SEPARATOR ', ') AS specialty
        FROM mechanics m
        LEFT JOIN mechanic_specialties ms ON m.id = ms.mechanic_id
        LEFT JOIN specialties s ON ms.specialty_id = s.id
        WHERE m.status = 'Available'
        GROUP BY m.id, m.name
        ORDER BY m.name
    ");
    $available_mechanics = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Log the error
}

// --- 4. Fetch mechanic workload / top performers ---
$mechanic_stats = [];
$top_mechanic = null;
try {
    $stmt = $pdo->query("
        SELECT m.id, m.name, m.status,
               GROUP_CONCAT(DISTINCT s.specialty_name ORDER BY s.specialty_name SEPARATOR ', ') AS specialty,
               COUNT(DISTINCT b.id) AS total_jobs,
               COUNT(DISTINCT CASE WHEN b.status IN ('assigned','accepted','in_progress') THEN b.id END) AS active_jobs,
               COUNT(DISTINCT CASE WHEN b.status = 'completed' THEN b.id END) AS completed_jobs
        FROM mechanics m
        LEFT JOIN bookings b ON b.mechanic_id = m.id AND b.status IN ('assigned','accepted','in_progress','completed')
        LEFT JOIN mechanic_specialties ms ON m.id = ms.mechanic_id
        LEFT JOIN specialties s ON ms.specialty_id = s.id
        GROUP BY m.id, m.name, m.status
        ORDER BY total_jobs DESC, completed_jobs DESC, m.name ASC
    ");
    $mechanic_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $top_mechanic = $mechanic_stats[0] ?? null;
} catch (PDOException $e) {
    error_log("Mechanic stats error: " . $e->getMessage());
}

$busy_count = count(array_filter($mechanic_stats, fn($m) => $m['status'] === 'Busy'));

// --- 4b. Fetch each mechanic's current work items (bookings + emergencies) ---
$mechanic_work = [];
$completed_emg = [];
try {
    $w1 = $pdo->query("
        SELECT mechanic_id, id, status FROM bookings
        WHERE mechanic_id IS NOT NULL AND status IN ('assigned','accepted','in_progress')
    ")->fetchAll(PDO::FETCH_ASSOC);
    $w2 = $pdo->query("
        SELECT bm.mechanic_id, b.id, b.status FROM booking_mechanics bm
        JOIN bookings b ON b.id = bm.booking_id
        WHERE b.status IN ('assigned','accepted','in_progress')
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach (array_merge($w1, $w2) as $row) {
        $label = 'Booking #' . sprintf('%04d', $row['id']) . ' (' . $row['status'] . ')';
        if (!in_array($label, $mechanic_work[$row['mechanic_id']] ?? [], true)) {
            $mechanic_work[$row['mechanic_id']][] = $label;
        }
    }
    $w3 = $pdo->query("
        SELECT assigned_mechanic_id AS mechanic_id, id, request_status AS status
        FROM emergency_service_requests
        WHERE assigned_mechanic_id IS NOT NULL AND request_status IN ('assigned','accepted','in_progress')
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($w3 as $row) {
        $mechanic_work[$row['mechanic_id']][] = 'Emergency #' . sprintf('%04d', $row['id']) . ' (' . $row['status'] . ')';
    }
    $completed_emg = $pdo->query("
        SELECT assigned_mechanic_id, COUNT(*) AS c FROM emergency_service_requests
        WHERE assigned_mechanic_id IS NOT NULL AND request_status = 'completed'
        GROUP BY assigned_mechanic_id
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    error_log("Mechanic work fetch error: " . $e->getMessage());
}

// --- 5. Helper function to get service names (for display) ---
function get_service_names($pdo, $service_ids_json) {
    if (empty($service_ids_json)) return "N/A";
    
    // Decode the JSON array of IDs
    $service_ids = json_decode($service_ids_json);
    if (!is_array($service_ids) || count($service_ids) === 0) return "N/A";

    // Create placeholders for the SQL IN clause
    $placeholders = rtrim(str_repeat('?,', count($service_ids)), ',');
    $stmt = $pdo->prepare("SELECT service_name FROM services WHERE id IN ($placeholders)");
    $stmt->execute($service_ids);
    
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return implode(', ', array_map('htmlspecialchars', $names));
}

$pageTitle = 'Mechanic Assignment';
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
    .top-header {
        position: fixed !important;
        top: 0;
        left: var(--sidebar-width);
        right: 0;
        z-index: 100;
    }
    .main-content {
        padding-top: 75px !important;
        background: white !important;
    }
    @media (max-width: 991px) {
        .top-header { left: 0 !important; }
    }

    /* Assignment list view */
    .assignment-list {
        background: transparent;
        border: 1px solid var(--card-border);
        border-radius: 12px;
        overflow: hidden;
    }
    .assignment-list-header,
    .assignment-row {
        display: grid;
        grid-template-columns: 1fr 1.8fr 1.8fr 0.8fr 1fr 1fr;
        align-items: center;
        gap: 0.75rem;
        padding: 0.65rem 1rem;
        font-size: 0.8rem;
    }
    .assignment-list-header {
        background: transparent;
        border-bottom: 1px solid var(--card-border);
        font-weight: 700;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.03em;
        font-size: 0.65rem;
    }
    #assignmentList .assignment-list-header {
        color: #1e3a5f;
    }
    .assignment-row {
        border-bottom: 1px solid var(--card-border);
        transition: background 0.15s ease;
    }
    .assignment-row:last-child { border-bottom: none; }
    .assignment-row:hover { background: rgba(0, 0, 0, 0.02); }
    .assignment-cell { min-width: 0; }
    .assignment-title {
        font-weight: 700;
        color: var(--text-dark);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .assignment-sub {
        font-size: 0.72rem;
        color: var(--text-muted);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .assignment-services { font-size: 0.78rem; color: var(--text-dark); }
    .assignment-price { font-weight: 800; color: #b91c1c; }
    .assignment-status {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.55rem;
        border-radius: 99px;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: capitalize;
    }
    .assignment-status.pending { background: #fffbeb; color: #EAB308; }
    .assignment-status.assigned { background: rgba(30, 58, 95, 0.12); color: #1e3a5f; }
    .assignment-status.accepted { background: #dcfce7; color: #15803d; }
    .assignment-status.unassigned { background: #f3f4f6; color: #475569; }
    .assignment-status.in_progress { background: #faf5ff; color: #7e22ce; }
    .assignment-status.urgent { background: #fee2e2; color: #991b1b; }
    .assignment-status.high { background: #fef3c7; color: #92400e; }
    .assignment-status.medium { background: #dbeafe; color: #1e40af; }
    .assignment-status.low { background: #f3f4f6; color: #4b5563; }
    .section-title { font-size: 1rem; font-weight: 700; color: #1e3a5f; margin: 1.5rem 0 0.75rem; }
    .assignment-mechanic {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.2rem 0.55rem;
        border-radius: 99px;
        background: #dcfce7;
        color: #15803d;
        font-size: 0.68rem;
        font-weight: 700;
    }
    .assign-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        background: #FACC15;
        border: none;
        color: #111827;
        border-radius: 8px;
        padding: 0.35rem 0.7rem;
        font-size: 0.72rem;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.2s ease;
    }
    .assign-btn:hover { background: #EAB308; }
    .assign-btn i { font-size: 0.85rem; }
    .complete-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        background: #10b981;
        border: none;
        color: #fff;
        border-radius: 8px;
        padding: 0.35rem 0.7rem;
        font-size: 0.72rem;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.2s ease;
    }
    .complete-btn:hover { background: #059669; }

    @media (max-width: 991px) {
        .assignment-list-header { display: none; }
        .assignment-row {
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            padding: 0.75rem;
        }
        .assignment-col-datetime { grid-column: 1 / -1; }
        .assignment-col-customer { grid-column: 1 / -1; }
        .assignment-col-action { grid-column: 1 / -1; justify-self: start; }
    }

    /* Mechanic stats cards */
    .mechanic-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .mechanic-stat-card {
        background: #ffffff;
        border: 1px solid var(--card-border);
        border-radius: 12px;
        padding: 1rem;
        display: flex;
        align-items: center;
        gap: 0.85rem;
        cursor: pointer;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .mechanic-stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05); }
    .mechanic-stat-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        background: #fffbeb;
        color: #FACC15;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        flex-shrink: 0;
    }
    .mechanic-stat-icon.blue { background: rgba(30, 58, 95, 0.12); color: #1e3a5f; }
    .mechanic-stat-icon.green { background: #dcfce7; color: #10b981; }
    .mechanic-stat-label { font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em; }
    .mechanic-stat-value { font-size: 1.1rem; font-weight: 800; color: var(--text-dark); line-height: 1.2; }
    .mechanic-stat-sub { font-size: 0.72rem; color: var(--text-muted); }

    .assignment-vehicle {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .assignment-vehicle .moto-thumb {
        width: 48px;
        height: 34px;
        object-fit: contain;
        flex-shrink: 0;
        mix-blend-mode: multiply;
    }

    /* Dark mode overrides */
    html.dark-mode .assignment-price { color: #f87171 !important; }
    html.dark-mode .assignment-status.pending { background: rgba(250, 204, 21, 0.15) !important; color: #FDE047 !important; }
    html.dark-mode .assignment-status.assigned { background: rgba(59, 130, 246, 0.15) !important; color: #93c5fd !important; }
    html.dark-mode .assignment-status.accepted { background: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; }
    html.dark-mode .assignment-status.unassigned { background: rgba(148, 163, 184, 0.15) !important; color: #cbd5e1 !important; }
    html.dark-mode .assignment-status.in_progress { background: rgba(168, 85, 247, 0.15) !important; color: #c084fc !important; }
    html.dark-mode .assignment-status.urgent { background: rgba(239, 68, 68, 0.15) !important; color: #f87171 !important; }
    html.dark-mode .assignment-status.high { background: rgba(251, 191, 36, 0.15) !important; color: #fbbf24 !important; }
    html.dark-mode .assignment-status.medium { background: rgba(59, 130, 246, 0.15) !important; color: #93c5fd !important; }
    html.dark-mode .assignment-status.low { background: rgba(148, 163, 184, 0.12) !important; color: #94a3b8 !important; }
    html.dark-mode .assignment-mechanic { background: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; }
    html.dark-mode .mechanic-stat-icon { background: rgba(250, 204, 21, 0.15) !important; }
    html.dark-mode .mechanic-stat-icon.blue { background: rgba(59, 130, 246, 0.15) !important; color: #93c5fd !important; }
    html.dark-mode .mechanic-stat-icon.green { background: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; }
    html.dark-mode .assignment-vehicle .moto-thumb { mix-blend-mode: normal; }

    /* Mechanic Workload modal - show ~3 mechanics, scroll for the rest */
    #mechanicStatsModal .workload-rows {
        max-height: 320px;
        overflow-y: auto;
        scrollbar-width: thin;
    }
    #mechanicStatsModal .workload-rows::-webkit-scrollbar { width: 6px; }
    #mechanicStatsModal .workload-rows::-webkit-scrollbar-thumb { background: rgba(0, 0, 0, 0.2); border-radius: 3px; }
    html.dark-mode #mechanicStatsModal .workload-rows::-webkit-scrollbar-thumb { background: #3b4d7d; }
</style>

<div class="container-fluid">
    
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type == 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
            <?= $msg_type == 'success' ? '<i class="bi bi-check-circle-fill me-2"></i>' : '<i class="bi bi-x-octagon-fill me-2"></i>' ?>
            <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="mechanic-stats">
        <div class="mechanic-stat-card" data-bs-toggle="modal" data-bs-target="#mechanicStatsModal">
            <div class="mechanic-stat-icon"><i class="bi bi-trophy-fill"></i></div>
            <div>
                <div class="mechanic-stat-label">Outstanding Mechanic</div>
                <div class="mechanic-stat-value"><?= $top_mechanic ? htmlspecialchars($top_mechanic['name']) : 'None yet' ?></div>
                <div class="mechanic-stat-sub"><?= $top_mechanic ? ($top_mechanic['completed_jobs'] + $top_mechanic['active_jobs']) . ' total jobs' : '' ?></div>
            </div>
        </div>
        <div class="mechanic-stat-card" data-bs-toggle="modal" data-bs-target="#mechanicStatsModal">
            <div class="mechanic-stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="mechanic-stat-label">Completed Jobs</div>
                <div class="mechanic-stat-value"><?= $top_mechanic ? number_format($top_mechanic['completed_jobs']) : '0' ?></div>
                <div class="mechanic-stat-sub"><?= $top_mechanic ? 'by ' . htmlspecialchars($top_mechanic['name']) : '' ?></div>
            </div>
        </div>
        <div class="mechanic-stat-card" data-bs-toggle="modal" data-bs-target="#mechanicStatsModal">
            <div class="mechanic-stat-icon blue"><i class="bi bi-wrench"></i></div>
            <div>
                <div class="mechanic-stat-label">Busy Mechanics</div>
                <div class="mechanic-stat-value"><?= number_format($busy_count) ?></div>
                <div class="mechanic-stat-sub">currently on the job</div>
            </div>
        </div>
    </div>

    <?php if (empty($unassigned_bookings)): ?>
        <div class="alert alert-info text-center fw-bold p-4">
            <i class="bi bi-check-all me-2"></i> All bookings are currently assigned! Great job!
        </div>
    <?php else: ?>
        <div class="assignment-list" id="assignmentList">
            <div class="assignment-list-header">
                <span class="assignment-col-datetime">Date/Time</span>
                <span class="assignment-col-customer">Customer/Vehicle</span>
                <span class="assignment-col-services">Services</span>
                <span class="assignment-col-price">Price</span>
                <span class="assignment-col-status">Status</span>
                <span class="assignment-col-action">Action</span>
            </div>
            <?php foreach ($unassigned_bookings as $booking): ?>
                <div class="assignment-row">
                    <div class="assignment-cell assignment-col-datetime">
                        <div class="assignment-title"><?= date('M j, Y', strtotime($booking['schedule_date'])) ?></div>
                        <div class="assignment-sub"><?= date('h:i A', strtotime($booking['schedule_start_time'])) ?></div>
                    </div>
                    <div class="assignment-cell assignment-col-customer">
                        <div class="assignment-title"><?= htmlspecialchars($booking['customer_name']) ?></div>
                        <div class="assignment-sub assignment-vehicle">
                            <?php $mImg = !empty($booking['image']) ? $booking['image'] : ($modelImages[$booking['model']] ?? null); ?>
                            <?php if ($mImg): ?><img class="moto-thumb" src="<?= htmlspecialchars($mImg) ?>" alt=""><?php endif; ?>
                            <span><?= htmlspecialchars($booking['make']) . " " . htmlspecialchars($booking['model']) ?> (<?= htmlspecialchars($booking['plate_number']) ?>)</span>
                        </div>
                    </div>
                    <div class="assignment-cell assignment-col-services">
                        <div class="assignment-services"><?= get_service_names($pdo, $booking['service_ids']) ?></div>
                    </div>
                    <div class="assignment-cell assignment-col-price">
                        <span class="assignment-price">₱<?= number_format($booking['total_price'], 2) ?></span>
                    </div>
                    <div class="assignment-cell assignment-col-status">
                        <span class="assignment-status <?= strtolower($booking['status']) ?>"><?= ucfirst(htmlspecialchars($booking['status'])) ?></span>
                        <?php if (!empty($booking['mechanic_name'])): ?>
                            <div class="assignment-mechanic">
                                <i class="bi bi-wrench"></i> <?= htmlspecialchars($booking['mechanic_name']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="assignment-cell assignment-col-action">
                        <?php if (!empty($booking['mechanic_name'])): ?>
                            <form method="POST" action="mechanic_assignment.php" class="complete-form" style="display:inline;margin:0;padding:0;">
                                <input type="hidden" name="booking_id" value="<?= htmlspecialchars($booking['id']) ?>">
                                <input type="hidden" name="complete_booking" value="1">
                                <button type="submit" class="complete-btn" onclick="return confirm('Mark this booking as completed?')">
                                    <i class="bi bi-check-circle-fill"></i> Complete
                                </button>
                            </form>
                        <?php else: ?>
                            <button
                                class="assign-btn"
                                data-booking-id="<?= htmlspecialchars($booking['id']) ?>"
                                data-bs-toggle="modal"
                                data-bs-target="#assignModal">
                                <i class="bi bi-person-plus-fill"></i> Assign
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($assigned_emergencies)): ?>
        <h5 class="section-title"><i class="bi bi-ambulance me-2"></i>Assigned Emergency Requests</h5>
        <div class="assignment-list">
            <div class="assignment-list-header">
                <span class="assignment-col-datetime">Date/Time</span>
                <span class="assignment-col-customer">Customer/Vehicle</span>
                <span class="assignment-col-services">Issue</span>
                <span class="assignment-col-price">Priority</span>
                <span class="assignment-col-status">Status</span>
                <span class="assignment-col-action">Action</span>
            </div>
            <?php foreach ($assigned_emergencies as $emg): ?>
                <div class="assignment-row">
                    <div class="assignment-cell assignment-col-datetime">
                        <div class="assignment-title"><?= date('M j, Y', strtotime($emg['created_at'])) ?></div>
                        <div class="assignment-sub"><?= date('h:i A', strtotime($emg['created_at'])) ?></div>
                    </div>
                    <div class="assignment-cell assignment-col-customer">
                        <div class="assignment-title"><?= htmlspecialchars($emg['customer_name']) ?></div>
                        <div class="assignment-sub"><?= htmlspecialchars($emg['motorcycle_info'] ?: 'N/A') ?></div>
                    </div>
                    <div class="assignment-cell assignment-col-services">
                        <div class="assignment-services"><?= htmlspecialchars($emg['motorcycle_issue'] ?: ($emg['problem_description'] ?: 'N/A')) ?></div>
                    </div>
                    <div class="assignment-cell assignment-col-price">
                        <span class="assignment-status <?= strtolower($emg['priority'] ?? 'medium') ?>"><?= ucfirst($emg['priority'] ?? 'medium') ?></span>
                    </div>
                    <div class="assignment-cell assignment-col-status">
                        <span class="assignment-status <?= strtolower($emg['request_status']) ?>"><?= ucfirst(htmlspecialchars($emg['request_status'])) ?></span>
                        <?php if (!empty($emg['mechanic_name'])): ?>
                            <div class="assignment-mechanic">
                                <i class="bi bi-wrench"></i> <?= htmlspecialchars($emg['mechanic_name']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="assignment-cell assignment-col-action">
                        <?php if (!empty($emg['mechanic_name'])): ?>
                            <form method="POST" action="mechanic_assignment.php" style="display:inline;margin:0;padding:0;">
                                <input type="hidden" name="emergency_id" value="<?= htmlspecialchars($emg['id']) ?>">
                                <input type="hidden" name="complete_emergency" value="1">
                                <button type="submit" class="complete-btn" onclick="return confirm('Mark this emergency request as completed?')">
                                    <i class="bi bi-check-circle-fill"></i> Complete
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<div class="modal fade" id="assignModal" tabindex="-1" aria-labelledby="assignModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: #FACC15; color: #111827;">
                <h5 class="modal-title" id="assignModalLabel"><i class="bi bi-person-badge-fill me-2"></i> Assign Mechanic to Booking</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="mechanic_assignment.php">
                <div class="modal-body">
                    <input type="hidden" name="booking_id" id="modal_booking_id">
                    
                    <p class="lead">Assign a mechanic to **Booking #<span id="display_booking_id" class="text-primary"></span>**.</p>
                    <div class="alert alert-warning small py-2">
                        <i class="bi bi-info-circle-fill me-1"></i> Only **Available** mechanics are shown here.
                    </div>

                    <div class="mb-3">
                        <label for="mechanic_id" class="form-label fw-bold">Select Available Mechanic</label>
                        <select class="form-select" id="mechanic_id" name="mechanic_id" required>
                            <option value="" selected disabled>--- Choose a Mechanic ---</option>
                            <?php if (empty($available_mechanics)): ?>
                                <option disabled>No mechanics currently available.</option>
                            <?php else: ?>
                                <?php foreach ($available_mechanics as $mechanic): ?>
                                    <option value="<?= htmlspecialchars($mechanic['id']) ?>">
                                        <?= htmlspecialchars($mechanic['name']) ?> (Specialty: <?= htmlspecialchars($mechanic['specialty']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="assign_mechanic" class="btn btn-primary">
                        <i class="bi bi-check-circle-fill me-2"></i> Finalize Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="mechanicStatsModal" tabindex="-1" aria-labelledby="mechanicStatsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: #FACC15; color: #111827;">
                <h5 class="modal-title" id="mechanicStatsModalLabel"><i class="bi bi-trophy me-2"></i> Mechanic Workload</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="assignment-list" style="border: none;">
                    <div class="assignment-list-header" style="grid-template-columns: 1.6fr 1.2fr 0.6fr 0.9fr 0.6fr 2fr;">
                        <span>Mechanic</span>
                        <span>Specialty</span>
                        <span>Active</span>
                        <span>Completed</span>
                        <span>Total</span>
                        <span>Current Work</span>
                    </div>
                    <div class="workload-rows">
                    <?php foreach ($mechanic_stats as $m):
                        $work_items = $mechanic_work[$m['id']] ?? [];
                        $active_total = count($work_items);
                        $completed_total = $m['completed_jobs'] + (int)($completed_emg[$m['id']] ?? 0);
                    ?>
                        <div class="assignment-row" style="grid-template-columns: 1.6fr 1.2fr 0.6fr 0.9fr 0.6fr 2fr;">
                            <div class="assignment-cell">
                                <div class="assignment-title"><?= htmlspecialchars($m['name']) ?></div>
                                <div class="assignment-sub"><?= $m['status'] === 'Busy' ? '<i class="bi bi-wrench me-1"></i> Busy' : 'Available' ?></div>
                            </div>
                            <div class="assignment-cell"><?= htmlspecialchars($m['specialty'] ?: '-') ?></div>
                            <div class="assignment-cell"><?= number_format($active_total) ?></div>
                            <div class="assignment-cell"><?= number_format($completed_total) ?></div>
                            <div class="assignment-cell assignment-price"><?= number_format($active_total + $completed_total) ?></div>
                            <div class="assignment-cell">
                                <?php if ($work_items): ?>
                                    <div class="assignment-sub" style="white-space: normal;"><?= htmlspecialchars(implode(' · ', $work_items)) ?></div>
                                <?php else: ?>
                                    <span class="assignment-sub">No active work</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Script to populate the modal with the correct booking ID
    document.addEventListener('DOMContentLoaded', function() {
        var assignModal = document.getElementById('assignModal');
        assignModal.addEventListener('show.bs.modal', function (event) {
            // Button that triggered the modal
            var button = event.relatedTarget;
            // Extract info from data-bs-* attributes
            var bookingId = button.getAttribute('data-booking-id');

            // Update the modal's content.
            var modalBookingIdInput = assignModal.querySelector('#modal_booking_id');
            var modalBookingIdDisplay = assignModal.querySelector('#display_booking_id');

            modalBookingIdInput.value = bookingId;
            modalBookingIdDisplay.textContent = bookingId;
        });

        // Optional: Show SweetAlert for assignment success/error
        <?php if ($msg_type == 'success' || $msg_type == 'error'): ?>
            Swal.fire({
                icon: '<?= $msg_type ?>',
                title: '<?= $msg_type == 'success' ? "Success!" : "Error!" ?>',
                html: '<?= $msg ?>',
                showConfirmButton: true
            });
        <?php endif; ?>
    });
</script>
<?php require 'admin_sidebar_footer.php'; ?>
