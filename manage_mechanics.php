<?php
session_start();
// Check if the user is logged in as Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// --- INITIALIZE VARIABLES TO AVOID UNDEFINED WARNINGS ---
$msg = "";
$msg_type = "";
$mechanics = [];
$current_specialty = $_GET['specialty'] ?? 'All';

// Ensure database connection is attempted AFTER session check
require 'db.php'; 

// --- Fetch all Specialties for Checkboxes ---
try {
    $stmt = $pdo->query("SELECT id, specialty_name FROM specialties ORDER BY specialty_name");
    $all_specialties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching specialties: " . $e->getMessage());
    $all_specialties = [];
} 

// --- Specialty List for Buttons and Filtering ---
$specialties_list = [
    'All' => 'All Mechanics',
    'Brake System Service' => 'Brake System Service',
    'Electrical Systems' => 'Electrical Systems',
    'Engine Repair & Maintenance' => 'Engine Repair & Maintenance',
    'General Motorcycle Maintenance' => 'General Motorcycle Maintenance',
    'Motorcycle Diagnostics' => 'Motorcycle Diagnostics',
    'Preventive & Safety Inspection' => 'Preventive & Safety Inspection',
    'Tire, Wheel & Suspension' => 'Tire, Wheel & Suspension',
    'Transmission & Drivetrain' => 'Transmission & Drivetrain',
];

// --- 1. Logic for Adding a Mechanic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['add_mechanic'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $selected_specialty_ids = $_POST['specialty_ids'] ?? [];

    // Validation
    if (empty($name)) {
        $msg = "❌ Please fill in the mechanic's name.";
        $msg_type = "error";
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "❌ Please provide a valid email address.";
        $msg_type = "error";
    } elseif (empty($password) || strlen($password) < 6) {
        $msg = "❌ Password must be at least 6 characters.";
        $msg_type = "error";
    } elseif (empty($selected_specialty_ids)) {
        $msg = "❌ Please select at least one specialty.";
        $msg_type = "error";
    } else {
        try {
            // Check if email is already in use
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->rowCount() > 0) {
                $msg = "❌ Email address is already registered.";
                $msg_type = "error";
            } else {
                $pdo->beginTransaction();
                
                // Create user account for mechanic
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                $username = preg_replace('/[^a-zA-Z0-9]/', '', str_replace(' ', '', strtolower($name)));
                if (empty($username)) $username = 'mechanic' . time();
                
                $user_stmt = $pdo->prepare("INSERT INTO users (username, name, email, password, phone, address, role, status, archived) VALUES (?, ?, ?, ?, ?, ?, 'mechanic', 'Active', 0)");
                $user_stmt->execute([$username, $name, $email, $hashed_password, $phone, $address]);
                $user_id = $pdo->lastInsertId();
                
                // Insert mechanic with linked user account
                $stmt = $pdo->prepare("INSERT INTO mechanics (user_id, name, status) VALUES (?, ?, 'Available')");
                $stmt->execute([$user_id, $name]);
                $mechanic_id = $pdo->lastInsertId();
                
                // Insert specialty links
                $link_stmt = $pdo->prepare("INSERT INTO mechanic_specialties (mechanic_id, specialty_id) VALUES (?, ?)");
                foreach ($selected_specialty_ids as $specialty_id) {
                    $specialty_id = filter_var($specialty_id, FILTER_VALIDATE_INT);
                    if ($specialty_id) {
                        $link_stmt->execute([$mechanic_id, $specialty_id]);
                    }
                }
                
                $pdo->commit();
                $msg = "✅ Mechanic added successfully with login account and " . count($selected_specialty_ids) . " specialty/ies!";
                $msg_type = "success";
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Mechanic Add Error: " . $e->getMessage());
            $msg = "❌ Database Error during addition: " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// --- 2. Logic for Editing a Mechanic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['edit_mechanic'])) {
    $mechanic_id = filter_input(INPUT_POST, 'mechanic_id', FILTER_VALIDATE_INT);
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $selected_specialty_ids = $_POST['specialty_ids'] ?? [];

    if (empty($mechanic_id)) {
        $msg = "❌ Invalid mechanic ID.";
        $msg_type = "error";
    } elseif (empty($name)) {
        $msg = "❌ Please fill in the mechanic's name.";
        $msg_type = "error";
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "❌ Please provide a valid email address.";
        $msg_type = "error";
    } elseif (empty($selected_specialty_ids)) {
        $msg = "❌ Please select at least one specialty.";
        $msg_type = "error";
    } else {
        try {
            // Fetch existing mechanic and linked user
            $mech_stmt = $pdo->prepare("SELECT m.*, u.id as user_id, u.email as current_email FROM mechanics m LEFT JOIN users u ON m.user_id = u.id WHERE m.id = ?");
            $mech_stmt->execute([$mechanic_id]);
            $mechanic = $mech_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$mechanic) {
                $msg = "❌ Mechanic not found.";
                $msg_type = "error";
            } else {
                $user_id = $mechanic['user_id'];
                
                // Check email uniqueness if changed
                if ($email !== ($mechanic['current_email'] ?? '')) {
                    $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $check->execute([$email, $user_id]);
                    if ($check->rowCount() > 0) {
                        $msg = "❌ Email address is already registered.";
                        $msg_type = "error";
                        $mechanic = null; // prevent further processing
                    }
                }
                
                if (!empty($mechanic)) {
                    $pdo->beginTransaction();
                    
                    // Update user account
                    if ($user_id) {
                        $username = preg_replace('/[^a-zA-Z0-9]/', '', str_replace(' ', '', strtolower($name)));
                        if (empty($username)) $username = 'mechanic' . $mechanic_id;
                        
                        if (!empty($password)) {
                            if (strlen($password) < 6) {
                                throw new Exception("Password must be at least 6 characters.");
                            }
                            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                            $user_stmt = $pdo->prepare("UPDATE users SET username = ?, name = ?, email = ?, password = ?, phone = ?, address = ? WHERE id = ?");
                            $user_stmt->execute([$username, $name, $email, $hashed_password, $phone, $address, $user_id]);
                        } else {
                            $user_stmt = $pdo->prepare("UPDATE users SET username = ?, name = ?, email = ?, phone = ?, address = ? WHERE id = ?");
                            $user_stmt->execute([$username, $name, $email, $phone, $address, $user_id]);
                        }
                    }
                    
                    // Update mechanic name
                    $stmt = $pdo->prepare("UPDATE mechanics SET name = ? WHERE id = ?");
                    $stmt->execute([$name, $mechanic_id]);
                    
                    // Delete existing specialty links
                    $pdo->prepare("DELETE FROM mechanic_specialties WHERE mechanic_id = ?")->execute([$mechanic_id]);
                    
                    // Insert new specialty links
                    $link_stmt = $pdo->prepare("INSERT INTO mechanic_specialties (mechanic_id, specialty_id) VALUES (?, ?)");
                    foreach ($selected_specialty_ids as $specialty_id) {
                        $specialty_id = filter_var($specialty_id, FILTER_VALIDATE_INT);
                        if ($specialty_id) {
                            $link_stmt->execute([$mechanic_id, $specialty_id]);
                        }
                    }
                    
                    $pdo->commit();
                    $msg = "✅ Mechanic and login account updated successfully!";
                    $msg_type = "success";
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Mechanic Edit Error: " . $e->getMessage());
            $msg = "❌ Error during update: " . $e->getMessage();
            $msg_type = "error";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Mechanic Edit Error: " . $e->getMessage());
            $msg = "❌ Database Error during update: " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// --- 3. Logic for Deleting a Mechanic (UPDATED to use try/catch) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_mechanic'])) {
    $mechanic_id = filter_input(INPUT_POST, 'mechanic_id', FILTER_VALIDATE_INT);

    try {
        if (empty($mechanic_id)) {
            $msg = "❌ Invalid mechanic ID.";
            $msg_type = "error";
        } else {
            // Fetch the mechanic to find linked user account
            $mech_stmt = $pdo->prepare("SELECT m.*, u.id as user_id FROM mechanics m LEFT JOIN users u ON m.user_id = u.id WHERE m.id = ?");
            $mech_stmt->execute([$mechanic_id]);
            $mechanic = $mech_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$mechanic) {
                $msg = "⚠️ Deletion failed. Mechanic may not exist.";
                $msg_type = "warning";
            } else {
                // IMPORTANT: Before deleting, check if the mechanic has ANY associated, incomplete appointments.
                $check_appointments = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE mechanic_id = ? AND status IN ('pending', 'accepted', 'in_progress')");
                $check_appointments->execute([$mechanic_id]);
                
                if ($check_appointments->fetchColumn() > 0) {
                    $msg = "❌ Cannot delete mechanic. They still have active/assigned appointments that need to be completed or reassigned.";
                    $msg_type = "error";
                } else {
                    // Use transactions to ensure all related records are deleted
                    $pdo->beginTransaction();
                    
                    // 1. Delete links from mechanic_specialties
                    $stmt_links = $pdo->prepare("DELETE FROM mechanic_specialties WHERE mechanic_id = ?");
                    $stmt_links->execute([$mechanic_id]);
                    
                    // 2. Delete the mechanic
                    $stmt = $pdo->prepare("DELETE FROM mechanics WHERE id = ?");
                    $stmt->execute([$mechanic_id]);
                    
                    // 3. Delete the linked user account if it exists and is a mechanic
                    if (!empty($mechanic['user_id'])) {
                        $user_check = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                        $user_check->execute([$mechanic['user_id']]);
                        $user_role = $user_check->fetchColumn();
                        if ($user_role === 'mechanic') {
                            $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'mechanic'")->execute([$mechanic['user_id']]);
                        }
                    }
                    
                    $pdo->commit();

                    if ($stmt->rowCount()) {
                        $msg = "✅ Mechanic record and login account successfully deleted!";
                        $msg_type = "success";
                    } else {
                        $msg = "⚠️ Deletion failed. Mechanic may not exist.";
                        $msg_type = "warning";
                    }
                }
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Log the error for debugging
        error_log("Mechanic Deletion Error: " . $e->getMessage());
        $msg = "❌ Database Error during deletion: " . $e->getMessage();
        $msg_type = "error";
    }
}
// --- END Deletion Logic ---

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

// --- 2. Fetch Mechanics with Filtering Logic (CORRECTED Query with JOINs) ---
$params = [];

// Apply filter if a specialty is selected (and it's not 'All')
if ($current_specialty !== 'All' && array_key_exists($current_specialty, $specialties_list)) {
    // Use a subquery approach to filter mechanics by specialty but still show all their specialties
    $sql = "
        SELECT 
            m.id, 
            m.name, 
            m.status, 
            m.created_at,
            GROUP_CONCAT(DISTINCT s.specialty_name ORDER BY s.specialty_name ASC SEPARATOR ', ') AS specialty_name_list
        FROM 
            mechanics m
        LEFT JOIN 
            mechanic_specialties ms ON m.id = ms.mechanic_id
        LEFT JOIN 
            specialties s ON ms.specialty_id = s.id
        WHERE 
            m.id IN (
                SELECT DISTINCT ms2.mechanic_id 
                FROM mechanic_specialties ms2
                INNER JOIN specialties s2 ON ms2.specialty_id = s2.id
                WHERE s2.specialty_name = ?
            )
        GROUP BY 
            m.id, m.name, m.status, m.created_at
        ORDER BY 
            m.name ASC
    ";
    $params[] = $current_specialty;
} else {
    // No filter - show all mechanics with all their specialties
    $sql = "
        SELECT 
            m.id, 
            m.name, 
            m.status, 
            m.created_at,
            GROUP_CONCAT(DISTINCT s.specialty_name ORDER BY s.specialty_name ASC SEPARATOR ', ') AS specialty_name_list
        FROM 
            mechanics m
        LEFT JOIN 
            mechanic_specialties ms ON m.id = ms.mechanic_id
        LEFT JOIN 
            specialties s ON ms.specialty_id = s.id
        GROUP BY 
            m.id, m.name, m.status, m.created_at
        ORDER BY 
            m.name ASC
    ";
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $mechanics = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Log the error for debugging
    error_log("Error fetching mechanic list: " . $e->getMessage());
    $msg = "❌ Error fetching mechanic list: " . $e->getMessage();
    $msg_type = "error";
    // Keep $mechanics as an empty array [] to avoid the Fatal Error on count()
    $mechanics = [];
}
// --- END Fetch Logic ---

// --- Fetch Mechanic for Editing ---
$editing_mechanic = null;
$editing_specialty_ids = [];
if (isset($_GET['edit_id']) && !empty($_GET['edit_id'])) {
    try {
        $edit_id = filter_input(INPUT_GET, 'edit_id', FILTER_VALIDATE_INT);
        if ($edit_id) {
            $stmt = $pdo->prepare("
                SELECT m.*, u.email, u.phone, u.address
                FROM mechanics m
                LEFT JOIN users u ON m.user_id = u.id
                WHERE m.id = ?
            ");
            $stmt->execute([$edit_id]);
            $editing_mechanic = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($editing_mechanic) {
                // Get all specialties for this mechanic
                $spec_stmt = $pdo->prepare("
                    SELECT specialty_id 
                    FROM mechanic_specialties 
                    WHERE mechanic_id = ?
                ");
                $spec_stmt->execute([$editing_mechanic['id']]);
                $editing_specialty_ids = $spec_stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching mechanic for editing: " . $e->getMessage());
        $msg = "❌ Error loading mechanic for editing.";
        $msg_type = "error";
    }
}
// --- Fetch data for mechanic reports ---
$outstanding_mechanics = [];

// --- Filter for reports ---
$report_filter = $_GET['report_filter'] ?? 'all';
$period_labels = [
    'all' => 'All',
    'today' => 'Today',
    'week' => 'This Week',
    'month' => 'This Month'
];
$current_period = $period_labels[$report_filter] ?? 'All';

$date_conditions = [
    'all' => "1=1",
    'today' => "b.schedule_date = CURDATE()",
    'week' => "YEARWEEK(b.schedule_date, 1) = YEARWEEK(CURDATE(), 1)",
    'month' => "YEAR(b.schedule_date) = YEAR(CURDATE()) AND MONTH(b.schedule_date) = MONTH(CURDATE())"
];
$condition = $date_conditions[$report_filter] ?? $date_conditions['all'];
$mh_condition = str_replace('b.schedule_date', 'mh.service_date', $condition);

try {
    $stmt = $pdo->query("
        SELECT m.id, m.name,
               COUNT(b.id) AS total_jobs,
               SUM(CASE WHEN b.status = 'completed' THEN 1 ELSE 0 END) AS completed_jobs,
               (SELECT COUNT(*) FROM maintenance_history mh
                 WHERE mh.performed_by = m.name AND $mh_condition) AS services_done
        FROM mechanics m
        LEFT JOIN bookings b ON b.mechanic_id = m.id AND b.status IN ('assigned','accepted','in_progress','completed') AND $condition
        GROUP BY m.id, m.name
        ORDER BY services_done DESC, completed_jobs DESC, total_jobs DESC, m.name ASC
    ");
    $outstanding_mechanics = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching outstanding mechanics: " . $e->getMessage());
    $outstanding_mechanics = [];
}

$pageTitle = 'Manage Mechanics';
?>

<?php require 'admin_sidebar_template.php'; ?>

<style>
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
    .badge-available { background-color: #28a745; }
    .badge-busy { background-color: #ffc107; color: #343a40; }
    .badge-unavailable { background-color: #dc3545; }
    
    .mechanic-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        border: none;
        margin-bottom: 15px;
    }
    .mechanic-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 40px rgba(0,0,0,0.12);
    }
    .mechanic-avatar {
        width: 50px;
        height: 50px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.5rem;
        font-weight: 600;
    }
    .filter-btn {
        border-radius: 50px !important;
        padding: 5px 12px !important;
        font-size: 0.72rem !important;
        font-weight: 600 !important;
        text-transform: capitalize;
        transition: box-shadow 0.2s ease;
        border: 1px solid rgba(0, 0, 0, 0.1) !important;
        background: transparent !important;
        color: var(--text-dark) !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        white-space: nowrap;
        height: 30px;
    }
    .filter-btn:hover {
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        text-decoration: none;
        transform: none;
    }
    .filter-btn.active {
        background: #FACC15 !important;
        border-color: transparent !important;
        color: #111827 !important;
        box-shadow: 0 4px 12px rgba(250, 204, 21, 0.25);
    }
    body .btn-add-mechanic,
    body .btn-primary.btn-add-mechanic {
        padding: 5px 14px !important;
        font-size: 0.75rem !important;
        font-weight: 600 !important;
        border-radius: 50px !important;
        height: 30px;
        display: inline-flex;
        align-items: center;
        background: #FACC15 !important;
        border: 1px solid #FACC15 !important;
        color: #111827 !important;
        box-shadow: none !important;
    }
    body .btn-add-mechanic:hover,
    body .btn-primary.btn-add-mechanic:hover {
        background: #EAB308 !important;
        border-color: #EAB308 !important;
        color: #111827 !important;
        box-shadow: none !important;
    }
    .status-indicator {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 6px;
        animation: pulse 2s infinite;
    }
    .status-available { background: #28a745; }
    .status-busy { background: #ffc107; }
    .status-unavailable { background: #dc3545; }
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }

    /* Enhanced Modal Styling */
    .modal-content {
        border-radius: 20px;
        border: none;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        overflow: hidden;
    }
    .modal-header {
        background: #ffffff !important;
        color: #1e293b;
        padding: 20px 25px;
        border: none;
        position: relative;
        border-bottom: 2px solid rgba(250, 204, 21, 0.3);
    }
    .modal-header::after {
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
    .modal-header .btn-close {
        filter: none;
        opacity: 0.8;
    }
    .modal-title {
        font-weight: 600;
        font-size: 1.2rem;
    }
    .modal-body {
        padding: 15px;
        max-height: 70vh;
        overflow-y: auto;
    }
    .modal-footer {
        padding: 12px 18px;
        border-top: 1px solid rgba(0, 0, 0, 0.1);
    }
    body .modal .modal-dialog { max-width: 500px; }
    .modal-header { padding: 12px 18px; }
    .modal-title { font-size: 1rem; }
    body .modal-footer .btn-primary,
    body .modal-footer .btn-secondary { padding: 6px 14px; font-size: 0.8rem; border-radius: 8px; }
    .form-control, .form-select {
        border: 2px solid rgba(0, 0, 0, 0.1);
        border-radius: 12px;
        padding: 12px 16px;
        font-size: 0.9rem;
        transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
    }
    .form-control:focus, .form-select:focus {
        border-color: #FACC15;
        box-shadow: 0 0 0 4px rgba(250, 204, 21, 0.1);
    }
    body .modal .form-control,
    body .modal .form-select {
        padding: 8px 12px;
        font-size: 0.85rem;
    }
    .form-label {
        font-weight: 600;
        font-size: 0.85rem;
        color: #1e293b;
        margin-bottom: 8px;
    }
    body .modal .form-label { font-size: 0.78rem; margin-bottom: 4px; }
    body .btn-primary {
        background: #FACC15 !important;
        border-color: #FACC15 !important;
        color: #111827 !important;
        padding: 0 24px;
        border-radius: 12px;
        font-weight: 600;
        transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        box-shadow: none !important;
        height: 38px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    body .btn-primary:hover {
        transform: translateY(-2px);
        background: #EAB308 !important;
        border-color: #EAB308 !important;
        color: #111827 !important;
        box-shadow: none !important;
    }
    .btn-secondary {
        border-radius: 12px;
        padding: 10px 24px;
        font-weight: 600;
        transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
    }

    /* Mechanic list view */
    .mechanic-total {
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-muted);
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .mechanic-list {
        background: transparent;
        border: 1px solid var(--card-border);
        border-radius: 12px;
        overflow: hidden;
        max-height: calc(100vh - 220px);
        overflow-y: auto;
        scrollbar-width: none;
    }
    .mechanic-list::-webkit-scrollbar { display: none; }
    .mechanic-list-header,
    .mechanic-row {
        display: grid;
        grid-template-columns: 2fr 2.5fr 1fr 1fr 1fr;
        align-items: center;
        gap: 0.75rem;
        padding: 0.65rem 1rem;
        font-size: 0.8rem;
    }
    .mechanic-list-header {
        position: sticky;
        top: 0;
        z-index: 1;
        background: #f8fafc;
        border-bottom: 1px solid var(--card-border);
        font-weight: 700;
        color: #1e3a5f;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        font-size: 0.65rem;
    }
    .mechanic-row {
        border-bottom: 1px solid var(--card-border);
        
    }
    .mechanic-row:last-child { border-bottom: none; }
    .mechanic-row:hover { background: rgba(0, 0, 0, 0.02); }
    .mechanic-cell { min-width: 0; }
    .mechanic-name {
        font-weight: 700;
        color: var(--text-dark);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .mechanic-specialties {
        display: inline-flex;
        align-items: center;
        padding: 0.15rem 0.45rem;
        border-radius: 6px;
        background: #e0f2fe;
        color: #0369a1;
        font-weight: 600;
        font-size: 0.75rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
    }
    .mechanic-status {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.55rem;
        border-radius: 99px;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: capitalize;
    }
    .mechanic-status.Available { background: #dcfce7; color: #15803d; }
    .mechanic-status.Busy { background: #fff7ed; color: #c2410c; }
    .mechanic-status.Unavailable { background: #fee2e2; color: #991b1b; }
    .action-group { display: inline-flex; align-items: center; gap: 0.6rem; }
    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: auto;
        height: auto;
        border: none;
        font-size: 1rem;
        background: transparent;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
        padding: 0;
        color: #FACC15;
    }
    .action-btn:hover { color: #EAB308; }

    @media (max-width: 991px) {
        .mechanic-list-header { display: none; }
        .mechanic-row {
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            padding: 0.75rem;
        }
        .mechanic-col-name { grid-column: 1 / -1; }
        .mechanic-col-actions { grid-column: 1 / -1; justify-self: start; }
    }
    .report-table { font-size: 0.85rem; }
    .report-table th { background: #f8fafc; color: #1e3a5f; font-weight: 700; text-transform: uppercase; font-size: 0.7rem; }
    .report-section-title { font-weight: 700; color: #1e293b; margin-bottom: 0.75rem; font-size: 0.95rem; }
    body .btn-reports { background: #FACC15 !important; border: 1px solid #FACC15 !important; color: #111827 !important; border-radius: 50px; padding: 5px 14px; font-size: 0.75rem; font-weight: 600; height: 30px; display: inline-flex; align-items: center; flex-shrink: 0; box-shadow: none !important; }
    body .btn-reports:hover { background: #EAB308 !important; border-color: #EAB308 !important; color: #111827 !important; transform: translateY(-2px); box-shadow: none !important; }
    .mechanic-search-wrapper {
        position: relative;
        max-width: 360px;
    }
    .mechanic-search-icon {
        position: absolute;
        left: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        color: #6b7280;
        z-index: 2;
        pointer-events: none;
        font-size: 0.85rem;
    }
    .mechanic-search-input {
        padding: 0.55rem 2.25rem;
        border-radius: 10px;
        border: 1px solid #FACC15;
        height: 40px;
        font-size: 0.85rem;
        background: #ffffff;
        color: #111827;
        transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
    }
    .mechanic-search-input:focus {
        border-color: #FACC15;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.2);
    }
    .mechanic-search-clear {
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
    .mechanic-search-clear:hover {
        color: #ef4444;
        background: #f3f4f6;
    }
    .mechanic-search-info {
        font-size: 0.75rem;
        color: #6b7280;
        margin-top: 0.4rem;
    }
    .mechanic-row.hidden-match { display: none; }

    /* Dark mode overrides */
    html[data-theme="dark"] .mechanic-specialties { background: rgba(14, 165, 233, 0.15) !important; color: #7dd3fc !important; }
    html[data-theme="dark"] .mechanic-status.Available { background: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; }
    html[data-theme="dark"] .mechanic-status.Busy { background: rgba(249, 115, 22, 0.15) !important; color: #fdba74 !important; }
    html[data-theme="dark"] .mechanic-status.Unavailable { background: rgba(239, 68, 68, 0.15) !important; color: #f87171 !important; }
</style>

<div class="container-fluid">
    <div class="d-flex flex-nowrap justify-content-between align-items-center gap-2 mb-4">
        <div class="d-flex flex-wrap gap-2 flex-grow-1">
            <?php foreach ($specialties_list as $value => $label): ?>
                <?php 
                    $active_class = ($current_specialty == $value) ? 'active' : 'btn-outline-secondary';
                    $url = ($value == 'All') ? 'manage_mechanics.php' : 'manage_mechanics.php?specialty=' . urlencode($value);
                ?>
                <a href="<?= $url ?>" class="btn btn-sm filter-btn <?= $active_class ?>">
                    <?= $label ?>
                </a>
            <?php endforeach; ?>
        </div>
        <button class="btn btn-reports flex-shrink-0" onclick="openMechanicReportModal()">
            <i class="bi bi-clipboard-data me-1"></i> Reports
        </button>
        <button class="btn btn-primary btn-add-mechanic flex-shrink-0" onclick="openMechanicModal()">
            <i class="bi bi-person-plus-fill me-1"></i> Add New
        </button>
    </div>
    
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type == 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
            <?= $msg_type == 'success' ? '<i class="bi bi-check-circle-fill me-2"></i>' : '<i class="bi bi-x-octagon-fill me-2"></i>' ?>
            <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($mechanics)): ?>
    <div class="row mb-3">
        <div class="col-12">
            <div class="mechanic-search-wrapper">
                <i class="bi bi-search mechanic-search-icon"></i>
                <input type="text" class="form-control mechanic-search-input" id="mechanicSearch" placeholder="Search mechanic..." autocomplete="off">
                <button type="button" class="mechanic-search-clear" id="clearMechanicSearch" aria-label="Clear search">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="mechanic-search-info" id="mechanicSearchInfo" style="display: none;">
                <span id="mechanicSearchCount">0</span> mechanic(s) found
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($mechanics)): ?>
        <div class="text-center py-4 text-muted">
            <?php if ($current_specialty === 'All'): ?>
                No mechanics have been registered yet.
            <?php else: ?>
                No mechanics found for the specialty: <?= htmlspecialchars($specialties_list[$current_specialty] ?? $current_specialty) ?>.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="mechanic-total"><i class="bi bi-tools"></i> Total Mechanics: <strong><?= count($mechanics) ?></strong></div>
        <div class="mechanic-list">
            <div class="mechanic-list-header">
                <span class="mechanic-col-name">Name</span>
                <span class="mechanic-col-specialty">Specialty</span>
                <span class="mechanic-col-status">Status</span>
                <span class="mechanic-col-joined">Joined</span>
                <span class="mechanic-col-actions">Actions</span>
            </div>
            <?php foreach ($mechanics as $mechanic): ?>
                <div class="mechanic-row">
                    <div class="mechanic-cell mechanic-col-name">
                        <div class="mechanic-name"><?= htmlspecialchars($mechanic['name']) ?></div>
                    </div>
                    <div class="mechanic-cell mechanic-col-specialty">
                        <span class="mechanic-specialties"><?= htmlspecialchars($mechanic['specialty_name_list']) ?></span>
                    </div>
                    <div class="mechanic-cell mechanic-col-status">
                        <span class="mechanic-status <?= htmlspecialchars($mechanic['status']) ?>"><?= htmlspecialchars($mechanic['status']) ?></span>
                    </div>
                    <div class="mechanic-cell mechanic-col-joined"><?= date('M d, Y', strtotime($mechanic['created_at'])) ?></div>
                    <div class="mechanic-cell mechanic-col-actions">
                        <div class="action-group">
                            <button type="button" class="action-btn" onclick="openViewMechanicModal(<?= $mechanic['id'] ?>)" title="View">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button type="button" class="action-btn" onclick="openMechanicModal(<?= $mechanic['id'] ?>)" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="action-btn" onclick="confirmDelete(<?= $mechanic['id'] ?>, '<?= htmlspecialchars($mechanic['name']) ?>')" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="mechanicModal" tabindex="-1" aria-labelledby="mechanicModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mechanicModalLabel"><i class="bi bi-person-plus me-2"></i> <span id="modalTitle">Register New Mechanic</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="manage_mechanics.php"> 
                <div class="modal-body">
                    <input type="hidden" name="mechanic_id" id="mechanic_id" value="">
                    <input type="hidden" name="add_mechanic" id="add_mechanic" value="1">
                    <input type="hidden" name="edit_mechanic" id="edit_mechanic" value="">
                    
                    <div class="mb-3">
                        <label for="name" class="form-label">Mechanic Full Name</label>
                        <input type="text" class="form-control" id="name" name="name" required placeholder="e.g., Juan Dela Cruz">
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email" required placeholder="mechanic@example.com">
                    </div>
                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" id="phone" name="phone" placeholder="e.g., 09123456789">
                    </div>
                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2" placeholder="Mechanic's address..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" placeholder="At least 6 characters">
                        <small class="text-muted" id="passwordHelp">Required for new mechanics. Leave blank on edit to keep current password.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Specialties / Skills:</label>
                        <small class="text-muted d-block mb-2">Select all specialties that apply to this mechanic.</small>
                        <div class="border p-3 rounded bg-light">
                            <div class="row row-cols-1 g-2">
                                <?php if (empty($all_specialties)): ?>
                                    <p class="text-danger">No specialties found. Please configure the specialties table.</p>
                                <?php else: ?>
                                    <?php foreach ($all_specialties as $spec): ?>
                                        <div class="col">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" 
                                                       name="specialty_ids[]" 
                                                       value="<?= $spec['id'] ?>" 
                                                       id="spec_<?= $spec['id'] ?>">
                                                <label class="form-check-label" for="spec_<?= $spec['id'] ?>">
                                                    <?= htmlspecialchars($spec['specialty_name']) ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn"><i class="bi bi-save me-2"></i> Save Mechanic</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Mechanic Details Modal -->
<div class="modal fade" id="viewMechanicModal" tabindex="-1" aria-labelledby="viewMechanicModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewMechanicModalLabel"><i class="bi bi-person me-2"></i> Mechanic Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-4">
                    <div class="mechanic-avatar mx-auto" id="viewMechanicAvatar" style="width:70px; height:70px; font-size:2rem;"></div>
                    <h5 class="mt-2 mb-1" id="viewMechanicName"></h5>
                    <span id="viewMechanicStatus" class="mechanic-status"></span>
                </div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label text-muted mb-1">Specialties</label>
                        <div id="viewMechanicSpecialties"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted mb-1">Username</label>
                        <div id="viewMechanicUsername" class="fw-semibold text-dark"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted mb-1">Email</label>
                        <div id="viewMechanicEmail" class="fw-semibold text-dark"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted mb-1">Phone</label>
                        <div id="viewMechanicPhone" class="fw-semibold text-dark"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted mb-1">Joined Date</label>
                        <div id="viewMechanicJoined" class="fw-semibold text-dark"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label text-muted mb-1">Address</label>
                        <div id="viewMechanicAddress" class="fw-semibold text-dark"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="mechanicReportModal" tabindex="-1" aria-labelledby="mechanicReportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mechanicReportModalLabel"><i class="bi bi-clipboard-data me-2"></i> Mechanic Reports</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <h6 class="report-section-title"><i class="bi bi-trophy me-2"></i> Most Outstanding Mechanics — <?= htmlspecialchars($current_period) ?></h6>
                <div class="d-flex gap-2 mb-3 flex-wrap">
                    <a href="?report_filter=all" class="btn btn-sm <?= $report_filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>">All</a>
                    <a href="?report_filter=today" class="btn btn-sm <?= $report_filter === 'today' ? 'btn-primary' : 'btn-outline-secondary' ?>">Today</a>
                    <a href="?report_filter=week" class="btn btn-sm <?= $report_filter === 'week' ? 'btn-primary' : 'btn-outline-secondary' ?>">This Week</a>
                    <a href="?report_filter=month" class="btn btn-sm <?= $report_filter === 'month' ? 'btn-primary' : 'btn-outline-secondary' ?>">This Month</a>
                </div>
                <div class="table-responsive mb-4">
                    <table class="table table-sm table-hover report-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Mechanic</th>
                                <th>Services Done</th>
                                <th>Completed Jobs</th>
                                <th>Total Jobs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($outstanding_mechanics)): ?>
                                <tr><td colspan="5" class="text-center text-muted">No data available</td></tr>
                            <?php else: ?>
                                <?php foreach ($outstanding_mechanics as $index => $m): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($m['name']) ?></td>
                                    <td><?= number_format($m['services_done']) ?></td>
                                    <td><?= number_format($m['completed_jobs']) ?></td>
                                    <td><?= number_format($m['total_jobs']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>


            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-info" onclick="printReport()">
                    <i class="bi bi-printer me-2"></i> Print Report
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<form id="deleteForm" method="POST" action="manage_mechanics.php" style="display: none;">
    <input type="hidden" name="delete_mechanic" value="1">
    <input type="hidden" name="mechanic_id" id="deleteMechanicId">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Function to open mechanic modal (add or edit mode)
    function openMechanicModal(id = null) {
        const modal = new bootstrap.Modal(document.getElementById('mechanicModal'));
        const modalTitle = document.getElementById('modalTitle');
        const mechanicId = document.getElementById('mechanic_id');
        const nameInput = document.getElementById('name');
        const emailInput = document.getElementById('email');
        const phoneInput = document.getElementById('phone');
        const addressInput = document.getElementById('address');
        const passwordInput = document.getElementById('password');
        const passwordHelp = document.getElementById('passwordHelp');
        const addMechanic = document.getElementById('add_mechanic');
        const editMechanic = document.getElementById('edit_mechanic');
        const submitBtn = document.getElementById('submitBtn');
        
        // Uncheck all specialty checkboxes
        document.querySelectorAll('input[name="specialty_ids[]"]').forEach(checkbox => {
            checkbox.checked = false;
        });

        // Reset password help text
        passwordInput.required = false;

        if (id) {
            // Edit mode - fetch mechanic data via AJAX
            modalTitle.textContent = 'Edit Mechanic';
            mechanicId.value = id;
            addMechanic.value = '';
            editMechanic.value = '1';
            submitBtn.innerHTML = '<i class="bi bi-save me-2"></i> Update Mechanic';
            passwordHelp.textContent = 'Leave blank to keep the current password.';
            
            // Fetch mechanic data
            fetch(`get_mechanic_data.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        nameInput.value = data.mechanic.name;
                        emailInput.value = data.mechanic.email || '';
                        phoneInput.value = data.mechanic.phone || '';
                        addressInput.value = data.mechanic.address || '';
                        passwordInput.value = '';
                        // Check the appropriate specialty checkboxes
                        data.mechanic.specialties.forEach(specialtyId => {
                            const checkbox = document.getElementById(`spec_${specialtyId}`);
                            if (checkbox) {
                                checkbox.checked = true;
                            }
                        });
                    }
                })
                .catch(error => {
                    console.error('Error fetching mechanic data:', error);
                });
        } else {
            // Add mode
            modalTitle.textContent = 'Register New Mechanic';
            mechanicId.value = '';
            nameInput.value = '';
            emailInput.value = '';
            phoneInput.value = '';
            addressInput.value = '';
            passwordInput.value = '';
            addMechanic.value = '1';
            editMechanic.value = '';
            passwordInput.required = true;
            passwordHelp.textContent = 'Required. Must be at least 6 characters.';
            submitBtn.innerHTML = '<i class="bi bi-save me-2"></i> Save Mechanic';
        }

        modal.show();
    }

    // Function to open view mechanic details modal
    function openViewMechanicModal(id) {
        const modal = new bootstrap.Modal(document.getElementById('viewMechanicModal'));

        fetch(`get_mechanic_data.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const m = data.mechanic;

                    document.getElementById('viewMechanicName').textContent = m.name;
                    document.getElementById('viewMechanicAvatar').textContent = m.name ? m.name.charAt(0).toUpperCase() : '?';

                    const statusBadge = document.getElementById('viewMechanicStatus');
                    statusBadge.textContent = m.status;
                    statusBadge.className = 'mechanic-status ' + (m.status ? m.status.replace(/\s+/g, '') : '');

                    document.getElementById('viewMechanicUsername').textContent = m.username || 'N/A';
                    document.getElementById('viewMechanicEmail').textContent = m.email || 'N/A';
                    document.getElementById('viewMechanicPhone').textContent = m.phone || 'N/A';
                    document.getElementById('viewMechanicAddress').textContent = m.address || 'N/A';
                    document.getElementById('viewMechanicJoined').textContent = m.created_at
                        ? new Date(m.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                        : 'N/A';

                    const specContainer = document.getElementById('viewMechanicSpecialties');
                    specContainer.innerHTML = '';
                    if (m.specialty_names && m.specialty_names.length > 0) {
                        m.specialty_names.forEach(specName => {
                            const badge = document.createElement('span');
                            badge.className = 'mechanic-specialties me-1 mb-1';
                            badge.textContent = specName;
                            specContainer.appendChild(badge);
                        });
                    } else {
                        specContainer.innerHTML = '<span class="text-muted">No specialties assigned</span>';
                    }

                    modal.show();
                }
            })
            .catch(error => {
                console.error('Error fetching mechanic details:', error);
            });
    }

    // SweetAlert Deletion Confirmation (UNCHANGED)
    function confirmDelete(id, name) {
        Swal.fire({
            title: 'Are you sure?',
            html: `You are about to delete the mechanic ${name}. This action cannot be undone.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, Delete It!'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('deleteMechanicId').value = id;
                document.getElementById('deleteForm').submit();
            }
        })
    }

    function openMechanicReportModal() {
        const modal = new bootstrap.Modal(document.getElementById('mechanicReportModal'));
        modal.show();
    }

    function printReport() {
        const modal = document.getElementById('mechanicReportModal');
        const modalBody = modal.querySelector('.modal-body').cloneNode(true);
        modalBody.querySelectorAll('a, button').forEach(el => el.remove());
        const printWindow = window.open('', '_blank', 'width=900,height=700');
        printWindow.document.open();
        printWindow.document.write(`
            <html>
            <head>
                <title>Mechanic Reports</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                <style>
                    body { font-family: 'Segoe UI', Arial, sans-serif; padding: 30px; }
                    h1 { text-align: center; margin-bottom: 25px; }
                    .report-section-title { font-weight: 700; margin-bottom: 12px; font-size: 1.1rem; color: #1e293b; }
                    .report-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
                    .report-table th { background: #f8fafc; color: #1e3a5f; font-weight: 700; text-transform: uppercase; font-size: 0.7rem; }
                    .report-table th, .report-table td { border: 1px solid #dee2e6; padding: 8px; }
                </style>
            </head>
            <body>
                <h1>Mechanic Reports</h1>
                ${modalBody.innerHTML}
            </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.focus();
        setTimeout(() => {
            printWindow.print();
        }, 500);
    }
</script>

<script>
    // Animate table rows on load
    document.addEventListener('DOMContentLoaded', function() {
        const rows = document.querySelectorAll('tbody tr');
        rows.forEach((row, index) => {
            row.style.opacity = '0';
            row.style.transform = 'translateX(-20px)';
            setTimeout(() => {
                row.style.transition = 'all 0.4s ease';
                row.style.opacity = '1';
                row.style.transform = 'translateX(0)';
            }, index * 50);
        });
        
        // Add hover effect to filter buttons
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('mouseenter', function() {
                if (!this.classList.contains('active')) {
                    this.style.transform = 'translateY(-2px)';
                    this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
                }
            });
            btn.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = 'none';
            });
        });

        // Auto-open reports modal when a filter is selected
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('report_filter')) {
            openMechanicReportModal();
        }

        // Mechanic list search filter
        const searchInput = document.getElementById('mechanicSearch');
        const clearBtn = document.getElementById('clearMechanicSearch');
        const searchInfo = document.getElementById('mechanicSearchInfo');
        const searchCount = document.getElementById('mechanicSearchCount');
        const list = document.querySelector('.mechanic-list');

        if (searchInput && list) {
            const rows = Array.from(list.querySelectorAll('.mechanic-row'));
            let noResults = document.getElementById('noMechanicResults');
            if (!noResults) {
                noResults = document.createElement('div');
                noResults.id = 'noMechanicResults';
                noResults.className = 'text-center py-4 text-muted';
                noResults.style.display = 'none';
                noResults.innerHTML = '<i class="bi bi-search me-2"></i>No mechanics match your search.';
                list.appendChild(noResults);
            }

            function escapeRegex(text) {
                return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            }

            function filterMechanics(query) {
                const q = query.trim().toLowerCase();
                let matchCount = 0;

                rows.forEach(function(row) {
                    const nameEl = row.querySelector('.mechanic-name');
                    const name = nameEl ? nameEl.textContent.trim() : '';
                    const regex = q ? new RegExp('(^|[ _-])' + escapeRegex(q), 'i') : null;
                    const match = !q || regex.test(name);
                    row.classList.toggle('hidden-match', !match);
                    if (match) matchCount++;
                });

                noResults.style.display = (q && matchCount === 0) ? 'block' : 'none';
                searchCount.textContent = matchCount;
                searchInfo.style.display = q ? 'block' : 'none';
                if (clearBtn) clearBtn.style.display = q ? 'flex' : 'none';
            }

            searchInput.addEventListener('input', function() {
                filterMechanics(this.value);
            });

            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    searchInput.value = '';
                    filterMechanics('');
                    searchInput.focus();
                });
            }
        }
    });
</script>

<?php require 'admin_sidebar_footer.php'; ?>