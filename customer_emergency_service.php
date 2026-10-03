<?php
session_start();
require 'db.php';
require_once 'feedback_helper.php';

// Security check - only customers can access emergency service requests
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    header("Location: index.php");
    exit;
}

$customer_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Customer';
$msg = "";
$msg_type = "";

// Show any flash message (e.g. after a redirect)
if (isset($_SESSION['emergency_flash'])) {
    $msg = $_SESSION['emergency_flash']['msg'];
    $msg_type = $_SESSION['emergency_flash']['type'];
    unset($_SESSION['emergency_flash']);
}

// Feedback submission flash (set by rate_service.php)
if (isset($_SESSION['feedback_flash'])) {
    $msg = $_SESSION['feedback_flash']['msg'];
    $msg_type = $_SESSION['feedback_flash']['type'];
    unset($_SESSION['feedback_flash']);
}

$active_page = basename($_SERVER['PHP_SELF']);

// Fetch customer's registered phone number
$customer_phone = '';
try {
    $phone_stmt = $pdo->prepare("SELECT phone FROM users WHERE id = ?");
    $phone_stmt->execute([$customer_id]);
    $customer_phone = $phone_stmt->fetchColumn() ?: '';
} catch (PDOException $e) {
    error_log("Error fetching customer phone: " . $e->getMessage());
}

// --- 1. Submit Emergency Service Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_emergency_request'])) {
    $motorcycle_id = $_POST['motorcycle_id'] ?? 0;
    $problem_description = trim($_POST['problem_description'] ?? '');
    $motorcycle_issue = trim($_POST['motorcycle_issue'] ?? '');
    $location = trim($_POST['location'] ?? $_POST['location_description'] ?? '');
    $location_description = trim($_POST['location_description'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? $customer_phone);
    $priority = $_POST['priority'] ?? 'medium';
    $service_type = in_array($_POST['service_type'] ?? '', ['onsite_repair', 'tow_service']) ? $_POST['service_type'] : 'onsite_repair';
    $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
    $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
    
    // Handle image upload
    $image_path = '';
    if (isset($_FILES['issue_image']) && $_FILES['issue_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/emergency_requests/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES['issue_image']['name'], PATHINFO_EXTENSION);
        $file_name = 'emergency_' . time() . '_' . $customer_id . '.' . $file_extension;
        $upload_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['issue_image']['tmp_name'], $upload_path)) {
            $image_path = $upload_path;
        }
    }
    
    if (empty($motorcycle_id) || empty($problem_description) || empty($motorcycle_issue) || empty($contact_number)) {
        $msg = "❌ Please fill in all required fields.";
        $msg_type = "error";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO emergency_service_requests 
                (customer_id, motorcycle_id, problem_description, motorcycle_issue, image_path, location, location_description, contact_number, priority, service_type, latitude, longitude, request_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([
                $customer_id, $motorcycle_id, $problem_description, $motorcycle_issue, 
                $image_path, $location, $location_description, $contact_number, $priority,
                $service_type, $latitude, $longitude
            ]);

            // Add initial update
            $request_id = $pdo->lastInsertId();
            $update_stmt = $pdo->prepare("
                INSERT INTO emergency_request_updates 
                (request_id, update_type, update_message, updated_by)
                VALUES (?, 'created', 'Emergency service request submitted', ?)
            ");
            $update_stmt->execute([$request_id, 'Customer']);

            $_SESSION['emergency_flash'] = [
                'msg' => '✅ Emergency service request submitted successfully! Our team will respond shortly.',
                'type' => 'success'
            ];
            header('Location: customer_emergency_service.php');
            exit;
        } catch (PDOException $e) {
            $msg = "❌ Error submitting emergency request: " . $e->getMessage();
            $msg_type = "error";
            error_log("Emergency request error: " . $e->getMessage());
        }
    }

    if ($msg !== '') {
        $_SESSION['emergency_flash'] = ['msg' => $msg, 'type' => $msg_type];
    }
    header('Location: customer_emergency_service.php');
    exit;
}

// --- 2. Cancel Emergency Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_emergency_request'])) {
    $request_id = $_POST['request_id'] ?? 0;

    try {
        $stmt = $pdo->prepare("
            UPDATE emergency_service_requests 
            SET request_status = 'cancelled', updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND customer_id = ? AND request_status IN ('pending', 'assigned')
        ");
        $stmt->execute([$request_id, $customer_id]);

        if ($stmt->rowCount()) {
            // Add cancellation update
            $update_stmt = $pdo->prepare("
                INSERT INTO emergency_request_updates 
                (request_id, update_type, update_message, updated_by)
                VALUES (?, 'cancelled', 'Request cancelled by customer', ?)
            ");
            $update_stmt->execute([$request_id, 'Customer']);

            $_SESSION['emergency_flash'] = [
                'msg' => '✅ Emergency request cancelled successfully!',
                'type' => 'success'
            ];
        } else {
            $_SESSION['emergency_flash'] = [
                'msg' => '⚠️ Unable to cancel request. It may already be in progress.',
                'type' => 'warning'
            ];
        }
        header('Location: customer_emergency_service.php');
        exit;
    } catch (PDOException $e) {
        error_log("Emergency cancellation error: " . $e->getMessage());
        $_SESSION['emergency_flash'] = [
            'msg' => "❌ Error cancelling request: " . $e->getMessage(),
            'type' => 'error'
        ];
        header('Location: customer_emergency_service.php');
        exit;
    }
}

// --- Fetch Customer's Motorcycles ---
$motorcycles = [];
try {
    $stmt = $pdo->prepare("SELECT id, brand, model, year_model, plate_number FROM motorcycles WHERE user_id = ? ORDER BY brand ASC");
    $stmt->execute([$customer_id]);
    $motorcycles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching motorcycles: " . $e->getMessage());
}

// --- Fetch Emergency Service Requests ---
$emergency_requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT esr.*, 
               CONCAT(m.brand, ' ', m.model, ' (', m.plate_number, ')') as motorcycle_info,
               u.username as mechanic_name
        FROM emergency_service_requests esr
        JOIN motorcycles m ON esr.motorcycle_id = m.id
        LEFT JOIN users u ON esr.assigned_mechanic_id = u.id
        WHERE esr.customer_id = ?
        ORDER BY esr.created_at DESC
    ");
    $stmt->execute([$customer_id]);
    $emergency_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch updates for each request
    foreach ($emergency_requests as &$request) {
        $update_stmt = $pdo->prepare("
            SELECT * FROM emergency_request_updates 
            WHERE request_id = ? 
            ORDER BY created_at ASC
        ");
        $update_stmt->execute([$request['id']]);
        $request['updates'] = $update_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching emergency requests: " . $e->getMessage());
}

// --- Fetch Saved Service Boundary ---
$saved_boundary = [];
try {
    $stmt = $pdo->query("SELECT coordinates FROM service_boundaries WHERE id = 1 LIMIT 1");
    $boundary_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($boundary_data && !empty($boundary_data['coordinates'])) {
        $saved_boundary = json_decode($boundary_data['coordinates'], true);
    }
} catch (PDOException $e) {
    error_log("Error fetching service boundary: " . $e->getMessage());
}

if (empty($saved_boundary)) {
    $saved_boundary = [
        [6.4000, 124.8800],
        [6.4200, 124.9800],
        [6.3500, 125.0500],
        [6.2600, 125.0100],
        [6.2500, 124.8900]
    ];
}

$pageTitle = 'Emergency Service Request';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <script>
        // Global theme state: apply saved theme before first paint to avoid a light-theme flash
        (function () {
            var t = localStorage.getItem('theme');
            if (t !== 'dark' && t !== 'light') {
                t = (localStorage.getItem('adminDarkMode') === '1' || localStorage.getItem('customerDarkMode') === '1') ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | AutoCare Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="fonts.css">
    
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <style>
        :root {
            --primary-color: #0f172a;
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
            --accent-color: #3b82f6;
            --accent-gradient: linear-gradient(135deg, #3b82f6 0%, #FACC15 100%);
            --secondary-color: #10b981;
            --bg-light: #f8fafc;
            --bg-dark: #0f172a;
            --text-dark: #1e293b;
            --text-light: #64748b;
            --white: #ffffff;
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --info-color: #3b82f6;
            --success-color: #10b981;
            --warning-color: #FACC15;
            --danger-color: #ef4444;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-light);
            scroll-behavior: smooth;
            overflow-x: hidden;
            display: flex;
            min-height: 100vh;
        }

        .stats-container { position: relative; z-index: 10; }

        .stat-card {
            background: #ffffff;
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 18px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            height: 100%;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
        }

        .stat-card:hover {
            border-color: rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }
        .stat-icon-primary { background: rgba(59, 130, 246, 0.12); color: var(--info-color); }
        .stat-icon-warning { background: rgba(250, 204, 21, 0.12); color: #EAB308; }
        .stat-icon-info { background: rgba(59, 130, 246, 0.12); color: var(--info-color); }
        .stat-icon-success { background: rgba(16, 185, 129, 0.12); color: var(--success-color); }
        .stat-icon-danger { background: rgba(239, 68, 68, 0.12); color: var(--danger-color); }

        .stat-value { font-size: 1.6rem; font-weight: 800; color: var(--text-dark); line-height: 1.1; }
        .stat-label { font-size: 0.78rem; font-weight: 600; color: var(--text-light); margin-top: 2px; }

        .emergency-card {
            background: white; border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            border: none; transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease; overflow: hidden;
        }
        .emergency-header { padding: 20px 25px; color: white; }
        .emergency-header.pending { background: var(--primary-gradient); }
        .emergency-header.assigned { background: var(--accent-gradient); }
        .emergency-header.in_progress { background: var(--primary-gradient); }
        .emergency-header.completed { background: linear-gradient(135deg, var(--success-color) 0%, #059669 100%); }
        .emergency-header.cancelled { background: linear-gradient(135deg, #64748b 0%, #475569 100%); }

        .priority-badge { padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .priority-urgent { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
        .priority-high { background: rgba(250, 204, 21, 0.1); color: #EAB308; }
        .priority-medium { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        .priority-low { background: rgba(16, 185, 129, 0.1); color: #10b981; }

        .contact-info {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.05) 0%, rgba(255, 255, 255, 0.95) 100%);
            padding: 15px; border-radius: 12px; border-left: 4px solid var(--accent-color);
        }
        .info-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }

        .empty-state { text-align: center; padding: 80px 20px; background: #f8fafc; border-radius: 20px; border: 2px dashed #e2e8f0; }
        .empty-state i { font-size: 4rem; color: var(--text-light); opacity: 0.5; }

        .upload-preview { max-width: 200px; max-height: 200px; border-radius: 12px; margin-top: 10px; display: none; }
        #requestMap { height: 250px; width: 100%; border-radius: 12px; }

    /* --- Smaller, readable emergency page content --- */
    .content-area {
        font-size: 0.9rem;
    }

    .top-bar-title {
        font-size: 1.2rem;
    }

    .content-area h3 {
        font-size: 1.1rem;
    }

    .content-area h4 {
        font-size: 1rem;
    }

    .content-area h5 {
        font-size: 0.9rem;
    }

    .emergency-card {
        border-radius: 16px;
    }

    .emergency-header {
        padding: 12px 15px;
    }

    .emergency-card .card-body {
        padding: 15px;
    }

    .emergency-card .info-row,
    .emergency-card .contact-info,
    .emergency-card .mb-3 {
        font-size: 0.85rem;
    }

    .emergency-card p,
    .emergency-card small,
    .emergency-card .small,
    .emergency-card strong {
        font-size: 0.8rem;
    }

    .emergency-card .btn {
        font-size: 0.8rem;
        padding: 6px 12px;
    }

    .priority-badge {
        font-size: 0.65rem;
        padding: 4px 10px;
    }

    .empty-state {
        padding: 50px 20px;
    }

    .empty-state i {
        font-size: 3rem;
    }

    .empty-state h4 {
        font-size: 1.1rem;
    }

    .empty-state p {
        font-size: 0.9rem;
    }

    /* --- New emergency modal UI/UX --- */
    .modal-content {
        border: none;
        border-radius: 16px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        overflow: hidden;
    }

    .modal-header {
        background: var(--primary-gradient);
        color: #ffffff;
        border-bottom: 2px solid #FACC15;
        padding: 12px 20px;
        align-items: center;
        position: relative;
    }

    .modal-header .btn-close {
        filter: invert(1) grayscale(100%) brightness(200%);
    }

    .modal-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: #FACC15;
    }

    .modal-title {
        font-size: 1rem;
        font-weight: 600;
        color: #ffffff;
    }

    .modal-body {
        padding: 18px 20px;
        font-size: 0.85rem;
    }

    .modal-body .mb-3,
    .modal-body .mb-2 {
        margin-bottom: 0.75rem !important;
    }

    .form-label {
        font-size: 0.8rem;
        margin-bottom: 0.3rem;
        font-weight: 500;
        color: var(--primary-color);
    }

    .btn-outline-accent {
        color: var(--primary-color);
        border-color: var(--primary-color);
        background-color: transparent;
    }

    .btn-outline-accent:hover,
    .btn-outline-accent:focus {
        color: #fff;
        background-color: var(--primary-color);
        border-color: var(--primary-color);
    }

    .btn-check:checked + .btn-outline-accent {
        color: #fff;
        background-color: var(--primary-color);
        border-color: var(--primary-color);
        box-shadow: 0 6px 18px rgba(15, 23, 42, 0.25);
    }

    .btn-check:checked + .btn-outline-accent small {
        color: rgba(255, 255, 255, 0.8) !important;
    }

    .service-type-btn {
        padding: 10px 8px;
        border-radius: 12px;
        height: 100%;
    }

    .form-control,
    .form-select {
        font-size: 0.85rem;
        padding: 0.4rem 0.75rem;
    }

    textarea.form-control {
        min-height: 60px;
    }

    #requestMap {
        height: 220px;
    }

    .modal-footer {
        padding: 12px 20px;
    }

    .modal-footer .btn {
        font-size: 0.85rem;
        padding: 0.4rem 1rem;
    }

    /* --- Smaller emergency cards --- */
    .emergency-card {
        border-radius: 12px;
        height: auto;
        margin-bottom: 0.75rem;
    }

    .emergency-header {
        padding: 10px 12px;
    }

    .emergency-card .card-body {
        padding: 12px;
    }

    .emergency-card h5 {
        font-size: 0.85rem;
    }

    .emergency-card small,
    .emergency-card p,
    .emergency-card strong,
    .emergency-card .info-value,
    .emergency-card .info-label,
    .emergency-card .btn {
        font-size: 0.75rem;
    }

    .emergency-card .contact-info {
        padding: 8px 10px;
    }

    .emergency-card .info-row {
        padding: 4px 0;
    }

    .emergency-card .priority-badge {
        font-size: 0.6rem;
        padding: 3px 8px;
    }

    .emergency-card .badge {
        font-size: 0.65rem;
        padding: 4px 8px;
    }

    .filter-card {
        cursor: pointer;
        user-select: none;
    }

    .filter-card.active {
        border: 2px solid var(--accent-color);
        box-shadow: 0 10px 30px rgba(59, 130, 246, 0.15);
    }

    .filter-card:hover {
        transform: translateY(-2px);
    }

    .emergency-table {
        font-size: 0.8rem;
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
    }

    .emergency-table th {
        background: var(--primary-color);
        color: white;
        font-weight: 600;
        font-size: 0.75rem;
        padding: 10px 12px;
    }

    .emergency-table td {
        padding: 8px 12px;
        vertical-align: middle;
    }

    .emergency-table tr:hover {
        background: rgba(59, 130, 246, 0.05);
    }

    .emergency-table .btn {
        font-size: 0.75rem;
        padding: 3px 10px;
    }

    /* --- COMPACT / MINIMIZE --- */
    .emergency-table { font-size: 0.75rem; }
    .emergency-table th { font-size: 0.7rem; padding: 8px 10px; }
    .emergency-table td { padding: 6px 10px; }
    .emergency-table .btn { font-size: 0.7rem; padding: 2px 8px; }
    .modal-body { font-size: 0.8rem; }
    .modal-title { font-size: 0.9rem; }
    .form-label { font-size: 0.75rem; }
    .emergency-card .btn { font-size: 0.75rem; padding: 5px 10px; }
    .table { font-size: 0.75rem; }
    .table th, .table td { padding: 0.4rem; }

    /* --- Match customer_health_score content width --- */
    .content-area .container {
        max-width: 1400px;
        padding-left: 0;
        padding-right: 0;
    }

    /* --- Match customer_health_score stat-card sizing --- */
    .stat-card {
        border-radius: 12px;
        padding: 10px 12px;
        gap: 8px;
    }

    .stat-card > div:last-child {
        min-width: 0;
        flex: 1;
    }

    .stat-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        font-size: 1rem;
    }

    .stat-value { font-size: 1.2rem; }
    .stat-label { font-size: 0.68rem; }

    @media (max-width: 576px) {
        .stat-card {
            padding: 12px;
            border-radius: 14px;
            gap: 10px;
        }

        .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            font-size: 1.1rem;
        }

        .stat-value { font-size: 1.2rem; }
        .stat-label { font-size: 0.7rem; }
    }
    </style>
</head>
<body>

<?php include 'customer_sidebar.php'; ?>

<div class="main-content">
    <div class="top-bar">
        <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
            <h1 class="top-bar-title">Emergency Service</h1>
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
    
    <div class="content-area">
        <div class="container stats-container">
            <div class="row g-3 mb-4">
                <div class="col-6 col-xl-3">
                    <div class="stat-card filter-card active" data-filter="pending">
                        <div class="stat-icon stat-icon-warning"><i class="bi bi-clock"></i></div>
                        <div>
                            <div class="stat-value"><?= count(array_filter($emergency_requests, fn($r) => $r['request_status'] === 'pending')) ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-xl-3">
                    <div class="stat-card filter-card" data-filter="in_progress">
                        <div class="stat-icon stat-icon-info"><i class="bi bi-gear"></i></div>
                        <div>
                            <div class="stat-value"><?= count(array_filter($emergency_requests, fn($r) => in_array($r['request_status'], ['assigned', 'in_progress']))) ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-xl-3">
                    <div class="stat-card filter-card" data-filter="completed">
                        <div class="stat-icon stat-icon-success"><i class="bi bi-check-circle"></i></div>
                        <div>
                            <div class="stat-value"><?= count(array_filter($emergency_requests, fn($r) => $r['request_status'] === 'completed')) ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-xl-3">
                    <div class="stat-card filter-card" data-filter="cancelled">
                        <div class="stat-icon stat-icon-danger"><i class="bi bi-x-circle"></i></div>
                        <div>
                            <div class="stat-value"><?= count(array_filter($emergency_requests, fn($r) => $r['request_status'] === 'cancelled')) ?></div>
                            <div class="stat-label">Cancelled</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container py-5">
            <?php $toast_msg = $msg; $toast_type = $msg_type; include 'floating_toast.php'; ?>

            <div class="row">
                <div class="col-12 mb-4 d-flex justify-content-between align-items-center">
                    <h3 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Emergency Requests</h3>
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#emergencyModal">
                        <i class="bi bi-plus-lg me-2"></i>New Emergency Request
                    </button>
                </div>
                
                <?php if (empty($emergency_requests)): ?>
                    <div class="col-12">
                        <div class="empty-state">
                            <i class="bi bi-ambulance"></i>
                            <h4>No Emergency Requests</h4>
                            <p>You haven't submitted any emergency service requests.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($emergency_requests as $request): ?>
                        <div class="col-md-6 col-lg-4 mb-3 emergency-card-wrapper" data-status="<?= htmlspecialchars($request['request_status']) ?>">
                            <div class="emergency-card">
                                <div class="emergency-header <?= $request['request_status'] ?>">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h5 class="mb-1"><?= htmlspecialchars($request['motorcycle_info']) ?></h5>
                                            <small class="opacity-75"><?= date('M j, Y g:i A', strtotime($request['created_at'])) ?></small>
                                        </div>
                                        <span class="priority-badge priority-<?= $request['priority'] ?>"><?= $request['priority'] ?></span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="mb-2">
                                        <span class="badge bg-<?= $request['request_status'] === 'completed' ? 'success' : ($request['request_status'] === 'cancelled' ? 'secondary' : 'primary') ?>">
                                            <?= ucfirst(str_replace('_', ' ', $request['request_status'])) ?>
                                        </span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Issue</span>
                                        <span class="info-value"><?= htmlspecialchars($request['motorcycle_issue']) ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Service</span>
                                        <span class="info-value">
                                            <?php if (($request['service_type'] ?? 'onsite_repair') === 'tow_service'): ?>
                                                <i class="bi bi-truck me-1"></i>Tow &amp; Lift
                                            <?php else: ?>
                                                <i class="bi bi-wrench-adjustable-circle me-1"></i>Fix On-Site
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <?php if ($request['location']): ?>
                                        <div class="contact-info mb-2">
                                            <strong><i class="bi bi-geo-alt me-1"></i>Location:</strong>
                                            <p class="mb-0 small"><?= htmlspecialchars($request['location']) ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <div class="contact-info mb-2">
                                        <strong><i class="bi bi-telephone me-1"></i>Contact:</strong>
                                        <p class="mb-0 small"><?= htmlspecialchars($request['contact_number']) ?></p>
                                    </div>
                                    <?php if (in_array($request['request_status'], ['pending', 'assigned'])): ?>
                                        <form method="POST">
                                            <input type="hidden" name="cancel_emergency_request" value="1">
                                            <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger w-100" onclick="return confirm('Cancel request?')">
                                                Cancel Request
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($request['request_status'] === 'completed'): ?>
                                        <?php $feedback = get_emergency_feedback($pdo, $request['id']); ?>
                                        <?php if ($feedback): ?>
                                            <div class="contact-info mb-0">
                                                <strong><i class="bi bi-star-fill me-1" style="color:#EAB308;"></i>Your Feedback</strong>
                                                <div class="small mt-1">
                                                    <?php if ($feedback['mechanic_rating'] !== null): ?>
                                                        <div>Mechanic: <?= render_stars((int)$feedback['mechanic_rating']) ?></div>
                                                    <?php endif; ?>
                                                    <div>Service: <?= render_stars((int)$feedback['service_rating']) ?></div>
                                                    <?php if (!empty($feedback['comments'])): ?>
                                                        <div class="text-muted mt-1"><?= htmlspecialchars(mb_strimwidth($feedback['comments'], 0, 100, '…')) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <a href="rate_service.php?type=emergency&id=<?= (int)$request['id'] ?>" class="btn btn-sm btn-warning w-100">
                                                <i class="bi bi-star me-1"></i>Rate Mechanic &amp; Service
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Emergency Request Modal -->
<div class="modal fade" id="emergencyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header emergency-modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Emergency Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="submit_emergency_request" value="1">
                    <input type="hidden" name="latitude" id="latitude">
                    <input type="hidden" name="longitude" id="longitude">
                    <input type="hidden" name="contact_number" value="<?= htmlspecialchars($customer_phone) ?>">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Motorcycle </label>
                            <select class="form-select" name="motorcycle_id" required>
                                <option value="">Select Motorcycle</option>
                                <?php foreach ($motorcycles as $moto): ?>
                                    <option value="<?= $moto['id'] ?>">
                                        <?= htmlspecialchars($moto['brand'] . ' ' . $moto['model'] . ' (' . $moto['plate_number'] . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Priority Level</label>
                            <select class="form-select" name="priority">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Service Needed</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <input type="radio" class="btn-check" name="service_type" id="serviceOnsite" value="onsite_repair" checked>
                                <label class="btn btn-outline-accent w-100 service-type-btn" for="serviceOnsite">
                                    <i class="bi bi-wrench-adjustable-circle d-block mb-1" style="font-size:1.3rem;"></i>
                                    <span class="d-block fw-semibold">Fix It On-Site</span>
                                    <small class="d-block text-muted">Mechanic repairs it at your location</small>
                                </label>
                            </div>
                            <div class="col-6">
                                <input type="radio" class="btn-check" name="service_type" id="serviceTow" value="tow_service">
                                <label class="btn btn-outline-accent w-100 service-type-btn" for="serviceTow">
                                    <i class="bi bi-truck d-block mb-1" style="font-size:1.3rem;"></i>
                                    <span class="d-block fw-semibold">Tow &amp; Lift</span>
                                    <small class="d-block text-muted">Motorcycle is towed to the shop</small>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Issue / Rescue Cost </label>
                        <select class="form-select" name="motorcycle_issue" required>
                            <option value="">Select issue and rescue cost</option>
                            <option value="Engine Won't Start (₱300)">Engine Won't Start — ₱300</option>
                            <option value="Engine Overheating (₱350)">Engine Overheating — ₱350</option>
                            <option value="Engine Misfiring (₱400)">Engine Misfiring — ₱400</option>
                            <option value="Loss of Engine Power (₱450)">Loss of Engine Power — ₱450</option>
                            <option value="Difficulty Shifting Gears (₱450)">Difficulty Shifting Gears — ₱450</option>
                            <option value="Motorcycle Cannot Move / Drivetrain Problem (₱500)">Motorcycle Cannot Move / Drivetrain Problem — ₱500</option>
                            <option value="Oil Leak (₱350)">Oil Leak — ₱350</option>
                            <option value="Chain Keeps Coming Loose (₱300)">Chain Keeps Coming Loose — ₱300</option>
                            <option value="Brake Failure / Weak Brakes (₱400)">Brake Failure / Weak Brakes — ₱400</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Problem Description </label>
                        <textarea class="form-control" name="problem_description" rows="3" required></textarea>
                    </div>

                    <!-- Interactive Map Selection -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label mb-0">Pin Emergency Location on Map</label>
                            <button type="button" class="btn btn-sm btn-outline-accent" id="btnDetectLocation">
                                <i class="bi bi-crosshair me-1"></i> Use My Current GPS Location
                            </button>
                        </div>
                        <div id="requestMap"></div>
                        <small class="text-muted mt-1 d-block">The red dashed outline shows the admin's current service coverage area. Click on the map or use your GPS to set your location.</small>
                        <div id="coverageMessage" class="alert alert-danger mt-2 d-none">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>Emergency service is not available in your current location. You are outside the service coverage area.
                        </div>
                    </div>


                    <div class="mb-3">
                        <label class="form-label">Location Description</label>
                        <textarea class="form-control" name="location_description" rows="2" placeholder="Landmarks, street names, etc."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
let map, marker;
const defaultLat = 6.3333; // Default region coordinates (Tupi)
const defaultLng = 124.9500;
const boundaryCoords = <?= json_encode($saved_boundary); ?>;

// Point-in-polygon test (ray casting)
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

function setCoverageState(inside) {
    const msg = document.getElementById('coverageMessage');
    const submitBtn = document.querySelector('#emergencyModal button[type="submit"]');
    const controls = document.querySelectorAll('#emergencyModal input:not([type=hidden]), #emergencyModal select, #emergencyModal textarea');
    if (inside) {
        msg.classList.add('d-none');
        submitBtn.disabled = false;
        controls.forEach(el => el.disabled = false);
    } else {
        msg.classList.remove('d-none');
        submitBtn.disabled = true;
        controls.forEach(el => el.disabled = true);
    }
}

function checkCoverage(lat, lng) {
    return setCoverageState(isPointInPolygon([lat, lng], boundaryCoords));
}

document.getElementById('emergencyModal').addEventListener('shown.bs.modal', function () {
    if (!map) {
        map = L.map('requestMap').setView([defaultLat, defaultLng], 13);

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

        L.control.layers(baseMaps).addTo(map);
        streetLayer.addTo(map);

        marker = L.marker([defaultLat, defaultLng], { draggable: false }).addTo(map);

        // Display the admin-defined service coverage boundary
        const serviceBoundary = L.polygon(boundaryCoords, {
            color: '#ef4444',
            fillColor: '#ef4444',
            fillOpacity: 0.15,
            weight: 2,
            dashArray: '5, 10',
            interactive: false
        }).addTo(map);

        map.fitBounds(serviceBoundary.getBounds(), { padding: [20, 20] });

        document.getElementById('latitude').value = defaultLat;
        document.getElementById('longitude').value = defaultLng;
        checkCoverage(defaultLat, defaultLng);

        // Update coordinates on click
        map.on('click', function (e) {
            marker.setLatLng(e.latlng);
            document.getElementById('latitude').value = e.latlng.lat;
            document.getElementById('longitude').value = e.latlng.lng;
            checkCoverage(e.latlng.lat, e.latlng.lng);
        });
    }
    map.invalidateSize();
});

// Auto Detect Browser GPS Location
document.getElementById('btnDetectLocation').addEventListener('click', function() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            map.setView([lat, lng], 16);
            marker.setLatLng([lat, lng]);
            document.getElementById('latitude').value = lat;
            document.getElementById('longitude').value = lng;
            checkCoverage(lat, lng);
        }, function(error) {
            alert('Unable to retrieve your location. Please select manually on the map.');
        });
    } else {
        alert('Geolocation is not supported by your browser.');
    }
});

// Stat-card filter for emergency request cards
document.querySelectorAll('.filter-card').forEach(card => {
    card.addEventListener('click', function() {
        const filter = this.dataset.filter;

        document.querySelectorAll('.filter-card').forEach(c => c.classList.remove('active'));
        this.classList.add('active');

        document.querySelectorAll('.emergency-card-wrapper').forEach(ecard => {
            const status = ecard.dataset.status;
            const inProgress = ['assigned', 'in_progress'];

            const show = (filter === 'in_progress' && inProgress.includes(status)) ||
                         filter === status;

            ecard.style.display = show ? 'block' : 'none';
        });
    });
});

// Apply the default active filter on page load
document.querySelector('.filter-card.active')?.click();
</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
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
</body>
</html>