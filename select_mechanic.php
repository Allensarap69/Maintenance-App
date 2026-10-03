<?php
session_start();
// NOTE: Ensure db.php correctly terminates all statements with a semicolon (;)
require 'db.php'; 

$active_page = 'book_service.php';
// ----------------------------------------------------------------------
// 1. SECURITY AND INITIAL CHECKS
// ----------------------------------------------------------------------

$username = $_SESSION['username'] ?? 'Customer';

// Check 1: User Role Authentication
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    header("Location: login.php");
    exit;
}

// Check 2: Essential Booking Details Verification
if (!isset($_SESSION['final_booking_details']['service_ids']) 
    || !isset($_SESSION['final_booking_details']['vehicle_id']) 
    || !isset($_SESSION['final_booking_details']['date']) 
    || !isset($_SESSION['final_booking_details']['start_time']) 
    || !isset($_SESSION['final_booking_details']['end_time'])) {
    
    header("Location: book_service.php?msg=" . urlencode("❌ Please re-select your service, vehicle, and time slot. Essential details are missing."));
    exit;
}

$booking_details = $_SESSION['final_booking_details'];
$service_ids = $booking_details['service_ids'];
$booking_date = $booking_details['date'];
$start_time = $booking_details['start_time'];
$end_time = $booking_details['end_time'];

// --- FILTERING VARIABLE ---
$current_filter_spec = $_GET['filter_spec'] ?? 'REQUIRED'; 
$msg = $_GET['msg'] ?? '';

// ----------------------------------------------------------------------
// 2. HELPER FUNCTIONS: DATABASE LOGIC
// ----------------------------------------------------------------------

/**
 * Fetches the specialties required for the chosen service IDs.
 */
function get_required_specialties($pdo, $service_ids) {
    if (empty($service_ids)) return [];
    
    $placeholders = rtrim(str_repeat('?,', count($service_ids)), ',');
    
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT sp.id AS specialty_id, sp.specialty_name 
            FROM services s
            JOIN service_specialties ss ON s.id = ss.service_id
            JOIN specialties sp ON ss.specialty_id = sp.id
            WHERE s.id IN ($placeholders)
        ");
        $stmt->execute($service_ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Required Specialties Error: " . $e->getMessage());
        return []; 
    }
}

/**
 * Fetches available mechanics based on required service skills OR an explicit filter.
 */
function get_matching_mechanics($pdo, $required_specialty_ids, $booking_date, $start_time, $filter_specialty_name) {
    
    $params = [$booking_date, $start_time];
    
    $subquery_not_available = "
        SELECT mechanic_id FROM bookings
        WHERE schedule_date = ? AND schedule_start_time = ? 
        AND status IN ('pending', 'accepted', 'in_progress')
    ";
    
    $join_clause = "";
    $where_specialty = "";
    $group_by_fields = "m.id, m.name, m.status, m.created_at";

    if ($filter_specialty_name === 'REQUIRED') {
        if (!empty($required_specialty_ids)) {
            $specialty_placeholders = rtrim(str_repeat('?,', count($required_specialty_ids)), ',');
            
            $where_specialty = "AND m.id IN (
                                                SELECT mechanic_id FROM mechanic_specialties 
                                                WHERE specialty_id IN ($specialty_placeholders) 
                                            )";
        }
        $join_clause = "LEFT JOIN mechanic_specialties ms ON m.id = ms.mechanic_id
                        LEFT JOIN specialties sp ON ms.specialty_id = sp.id";

    } else if ($filter_specialty_name !== 'ALL') {
        $join_clause = "JOIN mechanic_specialties ms ON m.id = ms.mechanic_id
                        JOIN specialties sp ON ms.specialty_id = sp.id";
        
        $where_specialty = "AND sp.specialty_name = ?";
        
    } else { // $filter_specialty_name === 'ALL'
        $join_clause = "LEFT JOIN mechanic_specialties ms ON m.id = ms.mechanic_id
                        LEFT JOIN specialties sp ON ms.specialty_id = sp.id";
    }
    
    try {
        $sql = "
            SELECT m.id, m.name, m.status, 
                   GROUP_CONCAT(DISTINCT sp.specialty_name SEPARATOR ' | ') AS specialties
            FROM mechanics m
            $join_clause
            WHERE 
                m.status = 'Available' 
                AND m.id NOT IN (
                    $subquery_not_available 
                )
                $where_specialty 
            GROUP BY $group_by_fields
            ORDER BY m.name
        ";
        
        $final_params = [$booking_date, $start_time];
        
        if ($filter_specialty_name === 'REQUIRED' && !empty($required_specialty_ids)) {
            $final_params = array_merge($final_params, $required_specialty_ids);
        }
        if ($filter_specialty_name !== 'ALL' && $filter_specialty_name !== 'REQUIRED') {
             $final_params[] = $filter_specialty_name;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($final_params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Mechanic Query Error: " . $e->getMessage());
        return [];
    }
}
// ----------------------------------------------------------------------

// ----------------------------------------------------------------------
// 3. POST LOGIC: HANDLE MULTIPLE MECHANIC SELECTION
// ----------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mechanic_ids'])) {
    $selected_mechanic_ids = $_POST['mechanic_ids'];
    
    if (!is_array($selected_mechanic_ids) || empty($selected_mechanic_ids)) {
        $msg = "❌ Please select at least one mechanic to check availability.";
    } else {
        $_SESSION['final_booking_details']['mechanic_ids'] = $selected_mechanic_ids;
        header("Location: view_availability.php"); 
        exit;
    }
} else if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['mechanic_ids'])) {
      $msg = "❌ Please select at least one mechanic to check availability.";
}


// ----------------------------------------------------------------------
// 4. DATA PREPARATION FOR DISPLAY
// ----------------------------------------------------------------------

$required_specialties_data = get_required_specialties($pdo, $service_ids);

// --- Include specialties required by selected packages (package-only bookings)
$package_ids = $booking_details['package_ids'] ?? [];
if (!empty($package_ids)) {
    $pkg_placeholders = rtrim(str_repeat('?,', count($package_ids)), ',');
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT sp.id AS specialty_id, sp.specialty_name 
            FROM service_package_specialties sps
            JOIN specialties sp ON sps.specialty_id = sp.id
            WHERE sps.package_id IN ($pkg_placeholders)
        ");
        $stmt->execute($package_ids);
        $package_specialties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $required_specialties_data = array_merge($required_specialties_data, $package_specialties);
    } catch (PDOException $e) {
        error_log("Package Specialties Error: " . $e->getMessage());
    }
}

$required_specialty_ids = array_column($required_specialties_data, 'specialty_id');
$required_specialty_names = array_column($required_specialties_data, 'specialty_name');

$available_mechanics = get_matching_mechanics($pdo, $required_specialty_ids, $booking_date, $start_time, $current_filter_spec);

$qualified_mechanics_for_display = [];
$other_mechanics_for_display = [];
$show_qualification_split = ($current_filter_spec === 'ALL' || $current_filter_spec === 'REQUIRED');

foreach ($available_mechanics as &$mechanic) {
    $mechanic_specialty_array = array_filter(explode(' | ', $mechanic['specialties']));
    $is_qualified_match = false;
    foreach ($required_specialty_names as $required_name) {
        if (in_array($required_name, $mechanic_specialty_array)) {
            $is_qualified_match = true;
            break;
        }
    }
    $mechanic['is_qualified_match'] = $is_qualified_match;

    if ($show_qualification_split) {
        if ($is_qualified_match) {
            $qualified_mechanics_for_display[] = $mechanic;
        } else if ($current_filter_spec === 'ALL') {
            $other_mechanics_for_display[] = $mechanic;
        }
    } else {
        // If filtering by a specific skill, all results are implicitly "matching" that filter
        $qualified_mechanics_for_display[] = $mechanic;
    }
}
unset($mechanic); 

$required_list = implode(', ', $required_specialty_names);

// --- DATE/TIME FORMATTING FOR DISPLAY ---
$start_datetime_obj = new DateTime($booking_details['date'] . ' ' . $booking_details['start_time']);
$end_datetime_obj = new DateTime($booking_details['date'] . ' ' . $booking_details['end_time']); 

$date_display = date('D, M jS', $start_datetime_obj->getTimestamp());
$start_time_display = date('h:i A', $start_datetime_obj->getTimestamp());
$end_time_display = date('h:i A', $end_datetime_obj->getTimestamp()); 

$pre_selected_mechanic_ids = $_SESSION['final_booking_details']['mechanic_ids'] ?? [];


$button_specialties = [
    'Brake System Service',
    'Electrical Systems',
    'Engine Repair & Maintenance',
    'General Motorcycle Maintenance',
    'Motorcycle Diagnostics',
    'Preventive & Safety Inspection',
    'Tire, Wheel & Suspension',
    'Transmission & Drivetrain',
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <script>
        // Global theme state: apply saved theme before first paint to avoid a light-theme flash
        (function () {
            var t = localStorage.getItem('theme');
            if (localStorage.getItem('themeManual') !== '1' || (t !== 'dark' && t !== 'light')) {
                t = 'light';
                localStorage.setItem('theme', t);
            }
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Mechanic Availability</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="fonts.css">
    <style>
        /* ============================================
           MODERN CUSTOMER PORTAL - INDEX.PHP STYLE
           ============================================ */
        :root { 
            --primary-color: #172A46; 
            --primary-gradient: linear-gradient(135deg, #172A46 0%, #1e3a5f 100%);
            --secondary-color: #10b981; 
            --accent-color: #3b82f6; 
            --accent-gradient: linear-gradient(135deg, #3b82f6 0%, #1e3a5f 100%);
            --bg-light: #F8FAFC; 
            --card-bg: white;
            --qualified-border: #10b981;
            --text-dark: #172033;
            --text-light: #64748B;
            --border-color: #E2E8F0;
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            background-color: var(--bg-light); 
            font-family: 'Poppins', sans-serif;
            scroll-behavior: smooth;
            overflow-x: hidden;
            padding-top: 0;
        }

        .sidebar {
            font-family: 'Poppins', sans-serif !important;
        }

        /* ============================================
           NAVIGATION
           ============================================ */
        .navbar-custom {
            background: linear-gradient(135deg, #1e3a5f 0%, #0f172a 50%, #1e293b 100%);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            z-index: 1050;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            padding: 12px 0;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            border-bottom: 2px solid rgba(59, 130, 246, 0.3);
        }

        .navbar-custom::before {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, 
                transparent 0%, 
                rgba(59, 130, 246, 0.8) 20%, 
                rgba(59, 130, 246, 0.8) 50%, 
                rgba(59, 130, 246, 0.8) 80%, 
                transparent 100%
            );
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.3rem;
            color: white;
            text-decoration: none;
        }

        .navbar-brand i {
            color: var(--accent-color);
        }

        .btn-main {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 8px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            font-size: 0.95rem;
        }

        .btn-main:hover {
            background: var(--accent-color);
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
            border-color: var(--accent-color);
        }

        /* ============================================
           IN-PAGE STEPPER
           ============================================ */
        .page-stepper {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 32px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            padding: 16px 0;
        }

        .page-stepper .step-item {
            display: flex;
            align-items: center;
            gap: 8px;
            opacity: 0.4;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        }

        .page-stepper .step-item.active {
            opacity: 1;
        }

        .page-stepper .step-item i {
            font-size: 1.3rem;
            color: var(--accent-color);
        }

        .page-stepper .step-item span {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-dark);
        }

        .page-stepper .step-item.active span {
            color: var(--accent-color);
            font-weight: 600;
        }

        .main-container {
            max-width: 1300px;
            padding-top: 8px;
            padding-bottom: 20px;
        }
        .content-row {
            overflow: visible;
        }

        /* --- MECHANIC LIST CONTAINER --- */
        .mechanic-list-container {
            overflow-y: auto; 
            padding-right: 8px;
            padding-bottom: 20px;
            max-height: 500px;
        }

        /* --- MECHANIC CARD STYLES --- */
        .mechanic-selection label { 
            cursor: pointer; 
            border: 1px solid var(--border-color); 
            border-left: 4px solid var(--border-color);
            border-radius: 8px; 
            padding: 14px 16px; 
            margin-bottom: 10px; 
            display: flex;
            align-items: center; 
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease; 
            background-color: var(--card-bg); 
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            height: 100%;
        }
        .mechanic-selection label:hover {
            border-color: var(--accent-color);
            background-color: rgba(59, 130, 246, 0.03);
        }
        .mechanic-selection input[type="checkbox"] {
            order: 2; 
            margin-left: 15px; 
            transform: scale(1.3); 
            flex-shrink: 0;
        }
        .mechanic-info {
            order: 1; 
            flex-grow: 1; 
            padding-right: 10px;
        }
        .mechanic-selection input[type="checkbox"]:checked + label { 
            border-color: var(--accent-color); 
            border-left-color: var(--accent-color) !important; 
            background-color: rgba(59, 130, 246, 0.05);
        }
        .mechanic-selection .qualified label {
            border-left-color: var(--qualified-border) !important;
        }
        .group-header {
            padding: 10px 16px;
            font-weight: 600;
            font-size: 1rem;
            border-radius: 8px;
            margin-top: 20px;
            margin-bottom: 12px;
            background-color: #ffffff;
            color: #172A46;
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--accent-color);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .group-qualified {
            font-size: 0.9rem;
            padding: 8px 12px;
            color: #059669;
            border-left-color: var(--qualified-border) !important;
        }
        .group-other {
            border-left: 4px solid #dc2626;
            color: #dc2626;
            margin-top: 32px;
            margin-bottom: 24px;
        }
        .info-card {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--border-color);
        }
        .summary-title {
            font-size: 1.1rem;
            font-weight: bold;
            color: var(--primary-color);
        }
        .skills-link {
            font-size: 0.85rem;
            color: var(--primary-color);
            text-decoration: underline;
            cursor: pointer;
        }

        /* --- FIXED FOOTER BAR --- */
        .fixed-footer-bar {
            background-color: var(--card-bg); 
            border-top: 1px solid var(--border-color); 
            padding: 16px 0; 
            z-index: 1000;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
        }

        .btn-check-availability {
            background-color: var(--accent-color);
            color: white;
            border-color: var(--accent-color);
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .btn-check-availability:hover {
            background-color: #2563eb; 
            border-color: #2563eb;
            color: #ffffff;
        }

        .btn-accent {
            background: var(--primary-gradient);
            border-color: #172A46;
            color: white;
        }

        .btn-accent:hover,
        .btn-accent:focus {
            background: #172A46;
            border-color: #172A46;
            color: #ffffff;
        }

        .btn-outline-accent {
            color: #172A46;
            border-color: #172A46;
            background-color: transparent;
        }

        .btn-outline-accent:hover,
        .btn-outline-accent:focus {
            background: var(--primary-gradient);
            color: white;
            border-color: #172A46;
        }

        .border-left-accent {
            border-left-color: var(--accent-color) !important;
        }

        .badge-accent {
            background: var(--primary-gradient);
            color: white;
            white-space: normal;
            max-width: 100%;
            text-align: left;
            line-height: 1.2;
        }


        /* Responsive adjustments for mobile/smaller screens */
        @media (max-width: 992px) {
            .content-row {
                height: auto; 
                overflow-y: visible;
                display: block;
            }
            .mechanic-list-container {
                 height: auto;
                 overflow-y: visible;
                 padding-right: 0;
                 max-height: 400px;
            }
            
            .page-stepper {
                gap: 24px;
            }
            
            .page-stepper .step-item i {
                font-size: 1.2rem;
            }
            
            .page-stepper .step-item span {
                font-size: 0.85rem;
            }
        }

        @media (max-width: 768px) {
            body {
                padding-top: 0;
            }
            
            .navbar-custom {
                padding: 8px 0;
            }
            
            .navbar-brand {
                font-size: 1.15rem;
            }
            
            .btn-main {
                padding: 8px 16px;
                font-size: 0.85rem;
            }
            
            .page-stepper {
                gap: 16px;
                padding: 12px 0;
                margin-bottom: 16px;
            }
            
            .page-stepper .step-item i {
                font-size: 1.1rem;
            }
            
            .page-stepper .step-item span {
                font-size: 0.8rem;
            }
            
            .mechanic-list-container {
                max-height: 400px;
            }
            
            .info-card {
                padding: 16px;
            }
            
            .mechanic-selection label {
                padding: 12px 14px;
            }
        }

        @media (max-width: 576px) {
            body {
                padding-top: 0;
            }
            
            .container {
                padding: 0 10px;
            }
            
            .navbar-custom {
                padding: 6px 0;
            }
            
            .navbar-brand {
                font-size: 1.1rem;
            }
            
            .btn-main {
                padding: 6px 14px;
                font-size: 0.8rem;
            }
            
            .page-stepper {
                gap: 12px;
                padding: 10px 0;
                margin-bottom: 12px;
            }
            
            .page-stepper .step-item i {
                font-size: 1rem;
            }
            
            .page-stepper .step-item span {
                font-size: 0.75rem;
            }
            
            .mechanic-list-container {
                max-height: 350px;
            }
            
            .info-card {
                padding: 16px;
            }
            
            .mechanic-selection label {
                padding: 10px 12px;
            }
        }

        /* --- COMPACT / MINIMIZE --- */
        .page-stepper { gap: 20px; margin-bottom: 16px; padding: 10px 0; }
        .page-stepper .step-item i { font-size: 1.1rem; }
        .page-stepper .step-item span { font-size: 0.8rem; }
        .info-card { padding: 15px; }
        .summary-title { font-size: 1rem; }
        .mechanic-selection label { padding: 10px 12px; margin-bottom: 8px; }
        .mechanic-info h6 { font-size: 0.9rem; }
        .mechanic-info small { font-size: 0.75rem; }
        .skills-link { font-size: 0.75rem; }
        .group-header { padding: 8px 12px; font-size: 0.85rem; margin-top: 16px; margin-bottom: 10px; }
        .group-other { margin-top: 45px; margin-bottom: 15px; }
        .fixed-footer-bar { padding: 12px 0; }
        .btn-check-availability { padding: 8px 16px; font-size: 0.85rem; }
        .btn { font-size: 0.85rem; padding: 0.4rem 0.8rem; }
        .badge { font-size: 0.75rem; }
        .alert { font-size: 0.85rem; padding: 0.75rem; }
        .table { font-size: 0.75rem; }
        .table th, .table td { padding: 0.4rem; }

        /* Stepper color to match available.php */
        .page-stepper .step-item i,
        .page-stepper .step-item.active span {
            color: #F97316;
        }
    </style>
</head>
<body id="select-mechanic-page">

<?php include 'customer_sidebar.php'; ?>

<!-- Main Content Area -->
<div class="main-content">
    <!-- Top Bar -->
    <div class="top-bar">
        <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="bi bi-list"></i>
            </button>
            <h1 class="top-bar-title">Choose Mechanic</h1>
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
                    <i class="bi bi-person"></i> Profile
                </a>
                <a href="logout.php" class="dropdown-item danger">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </div>

    <!-- Content Area -->
    <div class="content-area">
        <div class="container main-container">
    
    <!-- In-Page Stepper -->
    <div class="page-stepper">
        <div class="step-item">
            <i class="bi bi-wrench"></i>
            <span>Service & Schedule</span>
        </div>
        <div class="step-item">
            <i class="bi bi-clock"></i>
            <span>Choose Time</span>
        </div>
        <div class="step-item active">
            <i class="bi bi-person-badge"></i>
            <span>Choose Mechanic</span>
        </div>
        <div class="step-item">
            <i class="bi bi-calendar-check"></i>
            <span>Review</span>
        </div>
        <div class="step-item">
            <i class="bi bi-credit-card"></i>
            <span>Payment</span>
        </div>
    </div>
    
    <?php if (!empty($msg)): ?>
        <div class="alert alert-danger text-center mb-3 fw-bold"><i class="bi bi-x-octagon-fill me-1"></i> <?= $msg ?></div>
    <?php endif; ?>

    <div class="row content-row">
        <div class="col-lg-4 info-sidebar h-100">
            <div class="info-card mb-3">
                <p class="summary-title mb-2"><i class="bi bi-calendar-check me-1"></i> Booking Summary</p>
                <div class="border-start border-3 border-left-accent ps-2 mb-3">
                    <p class="mb-0 small">Date: <?= $date_display ?></p>
                    <p class="mb-0 small">Time: <?= $start_time_display ?> - <?= $end_time_display ?></p>
                </div>
                
                <p class="summary-title mb-2"><i class="bi bi-tools me-1"></i> Required Skills</p>
                <p class="small text-muted mb-3">
                    <?= !empty($required_list) ? htmlspecialchars($required_list) : 'General Repair' ?>
                </p>

                <p class="summary-title mb-2"><i class="bi bi-filter me-1"></i> Filter Mechanics</p>
                <div class="d-flex gap-2 mb-3">
                    <?php
                        $required_active = ($current_filter_spec === 'REQUIRED') ? 'btn-accent' : 'btn-outline-accent';
                        $all_active = ($current_filter_spec === 'ALL') ? 'btn-accent' : 'btn-outline-accent';
                    ?>
                    <a href="select_mechanic.php" class="btn btn-sm <?= $required_active ?> flex-fill">Best Match</a>
                    <a href="select_mechanic.php?filter_spec=ALL" class="btn btn-sm <?= $all_active ?> flex-fill">All Staff</a>
                </div>


                <div class="dropdown filter-dropdown">
                    <?php 
                        $dropdown_label = ($current_filter_spec !== 'ALL' && $current_filter_spec !== 'REQUIRED') 
                                             ? htmlspecialchars($current_filter_spec) 
                                             : 'Filter by Specific Skill';
                    ?>
                    <button class="btn btn-sm btn-outline-accent dropdown-toggle w-100" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-list-stars me-1"></i> <?= $dropdown_label ?>
                    </button>
                    
                    <ul class="dropdown-menu">
                        <h6 class="dropdown-header">Filter by Specialty:</h6>
                        <?php foreach ($button_specialties as $specialty): ?>
                            <li>
                                <a class="dropdown-item <?= ($current_filter_spec === $specialty) ? 'active' : '' ?>" 
                                   href="select_mechanic.php?filter_spec=<?= urlencode($specialty) ?>">
                                    <?= htmlspecialchars($specialty) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

            </div>
            
            <div class="info-card qualification-key bg-white border-0 shadow-sm p-3 small">
                <p class="mb-0 fw-bold text-dark"><i class="bi bi-key-fill me-1"></i> Key:</p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge badge-accent"><i class="bi bi-check-circle-fill me-1"></i> Qualified</span>
                    <span class="badge badge-accent"><i class="bi bi-x-circle-fill me-1"></i> Not Qualified</span>
                    <span class="badge badge-accent"><i class="bi bi-info-circle me-1"></i> Skills</span>
                </div>
            </div>

            <a href="available.php" class="btn btn-sm btn-accent mt-5" style="padding: 4px 10px; font-size: 0.75rem;">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>

        <div class="col-lg-8 mechanic-list-col h-100 d-flex flex-column">
            <div class="mechanic-list-container flex-grow-1" style="min-height: 0;">
                <form method="post" class="mechanic-selection" id="mechanic-selection-form"> 
                    <?php if (empty($qualified_mechanics_for_display) && empty($other_mechanics_for_display)): ?>
                        <div class="alert alert-warning text-center p-4 rounded-3 shadow-sm">
                            <i class="bi bi-exclamation-triangle-fill me-1 fs-3 text-warning"></i> 
                            <h5 class="mb-0 mt-3 fw-bold">No mechanics available for this slot or filter.</h5>
                            <p class="text-muted mt-2 mb-0">Please try a different time or use the 'All Staff' filter.</p>
                        </div>
                    <?php else: ?>
                        
                        <?php if (!empty($qualified_mechanics_for_display)): ?>
                            <div class="group-header group-qualified">
                                <i class="bi bi-patch-check-fill me-2"></i> 
                                <?= ($current_filter_spec === 'REQUIRED') ? 'Qualified Specialists' : 'Matching Specialists' ?>
                            </div>
                            <div class="row row-cols-1 row-cols-md-2 g-2">
                                <?php foreach ($qualified_mechanics_for_display as $m): 
                                    $is_checked = in_array($m['id'], $pre_selected_mechanic_ids) ? 'checked' : '';
                                ?>
                                    <div class="col">
                                        <input type="checkbox" id="mech_<?= $m['id'] ?>" name="mechanic_ids[]" value="<?= $m['id'] ?>" <?= $is_checked ?> class="form-check-input">
                                        
                                        <label for="mech_<?= $m['id'] ?>" class="d-flex align-items-center qualified">
                                            <div class="mechanic-info">
                                                <h6 class="mb-0 text-dark fw-bold"><?= htmlspecialchars($m['name']) ?></h6>
                                                <small class="d-block text-success">
                                                    <i class="bi bi-check-circle-fill me-1"></i> Available
                                                </small>
                                                <a tabindex="0" class="skills-link mt-1" role="button" data-bs-toggle="popover" data-bs-trigger="focus" 
                                                   data-bs-placement="bottom" title="Specialties" 
                                                   data-bs-content="<?= htmlspecialchars(str_replace(' | ', ', ', $m['specialties'])) ?>">
                                                     View Skills
                                                </a>
                                            </div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($current_filter_spec === 'ALL' && !empty($other_mechanics_for_display)): ?>
                            <div class="group-header group-other">
                                <i class="bi bi-exclamation-circle-fill me-2"></i> Other Available Staff but not qualified
                            </div>
                            <div class="row row-cols-1 row-cols-md-2 g-2">
                                <?php foreach ($other_mechanics_for_display as $m): 
                                    $is_checked = in_array($m['id'], $pre_selected_mechanic_ids) ? 'checked' : '';
                                ?>
                                    <div class="col">
                                        <input type="checkbox" id="mech_<?= $m['id'] ?>" name="mechanic_ids[]" value="<?= $m['id'] ?>" <?= $is_checked ?> class="form-check-input">
                                        <label for="mech_<?= $m['id'] ?>" class="d-flex align-items-center">
                                            <div class="mechanic-info">
                                                <h6 class="mb-0 text-dark fw-bold"><?= htmlspecialchars($m['name']) ?></h6>
                                                <small class="d-block text-success">
                                                    <i class="bi bi-check-circle-fill me-1"></i> Available
                                                </small>
                                                <a tabindex="0" class="skills-link mt-1" role="button" data-bs-toggle="popover" data-bs-trigger="focus" 
                                                   data-bs-placement="bottom" title="Specialties" 
                                                   data-bs-content="<?= htmlspecialchars(str_replace(' | ', ', ', $m['specialties'])) ?>">
                                                     View Skills
                                                </a>
                                            </div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </form>
            </div>
            <div class="mechanic-footer p-3 border-top text-end">
                <button type="submit" form="mechanic-selection-form" class="btn btn-sm btn-accent" onclick="return validateSelection()" style="padding: 4px 10px; font-size: 0.75rem;">
                    <i class="bi bi-clock-fill me-1"></i> Confirm
                </button>
            </div>
        </div>
    </div>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Initialize Popovers
    document.addEventListener('DOMContentLoaded', function () {
        var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'))
        var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl)
        })
    });
    
    // Simple JavaScript validation
    function validateSelection() {
        const checkboxes = document.querySelectorAll('input[name="mechanic_ids[]"]:checked');
        if (checkboxes.length === 0) {
            alert('Please select at least one mechanic to proceed to confirmation.');
            return false;
        }
        return true;
    }
</script>

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
</script>
</body>
</html>