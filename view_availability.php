<?php
session_start();
require 'db.php'; // Include your database connection

$active_page = 'book_service.php';
$username = $_SESSION['username'] ?? 'Customer';

// ----------------------------------------------------------------------
// 1. SECURITY AND DATA RETRIEVAL
// ----------------------------------------------------------------------

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    header("Location: login.php");
    exit;
}

// Check if ALL final booking details (including the confirmed time) are present
if (!isset($_SESSION['final_booking_details']['mechanic_ids']) || 
    !isset($_SESSION['final_booking_details']['date']) ||
    !isset($_SESSION['final_booking_details']['start_time']) ||
    !isset($_SESSION['final_booking_details']['end_time']) ||
    !isset($_SESSION['final_booking_details']['service_ids'])) { 
    // If time or mechanics are missing, redirect the user back to selection
    header("Location: select_mechanic.php?msg=" . urlencode("❌ Please re-select your preferred mechanics and date. Missing final time slot."));
    exit;
}

$booking_details = $_SESSION['final_booking_details'];
$service_ids = $booking_details['service_ids']; // Capture service IDs

// Ensure $selected_mechanic_ids is an array.
$selected_mechanic_ids = $booking_details['mechanic_ids'];
if (!is_array($selected_mechanic_ids)) {
    $selected_mechanic_ids = [];
}

$booking_date = $booking_details['date'];
$start_time = $booking_details['start_time']; // Confirmed Start Time
$end_time = $booking_details['end_time']; // Confirmed End Time

$msg = "";
$msg_type = "";

// ----------------------------------------------------------------------
// 2. HELPER FUNCTION: GET SERVICE DETAILS (Used for Duration and Skills)
// ----------------------------------------------------------------------

/**
 * Fetches the total duration and required specialties for the chosen service IDs.
 */
function get_service_details($pdo, $service_ids, $start_time, $end_time) {
    
    // Calculate duration based on confirmed times
    $start_timestamp = strtotime("2000-01-01 $start_time"); // Use a dummy date for time calculation
    $end_timestamp = strtotime("2000-01-01 $end_time");
    $duration_seconds = $end_timestamp - $start_timestamp;
    $total_duration = round($duration_seconds / 60); // Duration in minutes
    
    if (empty($service_ids)) return ['total_duration' => $total_duration, 'required_list' => ['General Repair']];
    
    $placeholders = rtrim(str_repeat('?,', count($service_ids)), ',');
    $params = $service_ids;
    
    $summary = [
        'total_duration' => $total_duration,
        'required_list' => []
    ];

    try {
        // 1. Get required specialties
        $stmt = $pdo->prepare("
            SELECT DISTINCT sp.specialty_name 
            FROM services s
            JOIN service_specialties ss ON s.id = ss.service_id
            JOIN specialties sp ON ss.specialty_id = sp.id
            WHERE s.id IN ($placeholders)
        ");
        $stmt->execute($params);
        $summary['required_list'] = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'specialty_name');

    } catch (PDOException $e) {
        error_log("Service Details Error: " . $e->getMessage());
        $summary['required_list'] = ['Error Fetching Skills']; 
    }
    
    return $summary;
}

// Calculate duration and fetch required skills based on confirmed time, services, and packages
$service_summary = get_service_details($pdo, $service_ids, $start_time, $end_time);
$interval = $service_summary['total_duration']; 
$required_list = $service_summary['required_list'];

// --- Include required skills from selected packages
$package_ids = $booking_details['package_ids'] ?? [];
if (!empty($package_ids)) {
    $pkg_placeholders = rtrim(str_repeat('?,', count($package_ids)), ',');
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT sp.specialty_name 
            FROM service_package_specialties sps
            JOIN specialties sp ON sps.specialty_id = sp.id
            WHERE sps.package_id IN ($pkg_placeholders)
        ");
        $stmt->execute($package_ids);
        $package_skills = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'specialty_name');
        $required_list = array_merge($required_list, $package_skills);
    } catch (PDOException $e) {
        error_log("Package Skills Error: " . $e->getMessage());
    }
}

$required_list = implode(', ', $required_list);

// --- Fetch selected services and packages for display
$selected_services = [];
$selected_packages = [];

if (!empty($service_ids)) {
    $placeholders = rtrim(str_repeat('?,', count($service_ids)), ',');
    $stmt = $pdo->prepare("SELECT service_name, price FROM services WHERE id IN ($placeholders)");
    $stmt->execute($service_ids);
    $selected_services = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!empty($package_ids)) {
    $pkg_placeholders = rtrim(str_repeat('?,', count($package_ids)), ',');
    $stmt = $pdo->prepare("SELECT package_name, price FROM service_packages WHERE id IN ($pkg_placeholders)");
    $stmt->execute($package_ids);
    $selected_packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// ----------------------------------------------------------------------
// 3. LOGIC FOR PROCEEDING TO PAYMENT (NO TIME SELECTION REQUIRED)
// ----------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proceed_payment'])) {
    
    // Ensure the main mechanic ID is set (first one from selected mechanics)
    if (!empty($selected_mechanic_ids)) {
        $_SESSION['final_booking_details']['mechanic_id'] = $selected_mechanic_ids[0];
    } else {
        // Fallback: if no mechanics selected, redirect back to selection
        header("Location: select_mechanic.php?msg=" . urlencode("❌ No mechanics selected. Please select a mechanic before proceeding."));
        exit;
    }
    
    // Redirect to payment confirmation
    header("Location: confirm_payment.php");
    exit;
}


// ----------------------------------------------------------------------
// 4. MECHANIC NAME FETCH & DISPLAY PREPARATION
// ----------------------------------------------------------------------

$selected_mechanic_names = [];
if (!empty($selected_mechanic_ids)) { 
    try {
        $mechanic_placeholders = rtrim(str_repeat('?,', count($selected_mechanic_ids)), ',');
        
        $stmt = $pdo->prepare("SELECT name FROM mechanics WHERE id IN ($mechanic_placeholders)"); 
        $stmt->execute($selected_mechanic_ids);
        $selected_mechanic_names = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    } catch (PDOException $e) {
        $selected_mechanic_names = ["Error Fetching Mechanic Names"];
        error_log("Mechanic name fetch error: " . $e->getMessage());
    }
}

// Store the list of names for display on the next page
$mechanic_list_display = implode(', ', $selected_mechanic_names);
$_SESSION['final_booking_details']['mechanic_list_display'] = $mechanic_list_display;

// Date and Time Formatting for display
$date_display_full = date('l, F j, Y', strtotime($booking_date)); // e.g., Friday, October 31, 2025
$date_display_short = date('D, M jS', strtotime($booking_date)); // e.g., Fri, Oct 31st
$start_time_display = date('h:i A', strtotime($start_time)); // e.g., 06:00 AM
$end_time_display = date('h:i A', strtotime($end_time)); // e.g., 09:30 AM
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
    <title>Confirm Booking Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="fonts.css">
    <style>
        /* ============================================
           MODERN CUSTOMER PORTAL - INDEX.PHP STYLE
           ============================================ */
        :root {
            --primary-color: #0f172a;
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
            --secondary-color: #10b981;
            --accent-color: #F97316;
            --accent-gradient: linear-gradient(135deg, #F97316 0%, #ea580c 100%);
            --bg-light: #f8fafc;
            --card-bg: white;
            --slot-hover: #e9f0f4;
            --text-dark: #1e293b;
            --text-light: #64748b;
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
        }

        .sidebar {
            font-family: 'Poppins', sans-serif !important;
        }

        /* --- HERO SECTION WITH FLOATING ICONS --- */
        .hero-section {
            background: var(--primary-gradient);
            position: relative;
            padding: 80px 0 100px;
            overflow: hidden;
            color: white;
            margin-top: 0;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 80%, rgba(245, 158, 11, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
            animation: pulse 8s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .floating-icon {
            position: absolute;
            opacity: 0.1;
            animation: float 6s ease-in-out infinite;
            color: white;
        }

        .floating-icon:nth-child(1) { top: 20%; left: 10%; font-size: 3rem; animation-delay: 0s; }
        .floating-icon:nth-child(2) { top: 60%; right: 10%; font-size: 2.5rem; animation-delay: 2s; }
        .floating-icon:nth-child(3) { bottom: 20%; left: 15%; font-size: 2rem; animation-delay: 4s; }
        .floating-icon:nth-child(4) { top: 30%; right: 20%; font-size: 2.5rem; animation-delay: 1s; }
        .floating-icon:nth-child(5) { bottom: 30%; right: 15%; font-size: 2rem; animation-delay: 3s; }
        .floating-icon:nth-child(6) { top: 50%; left: 5%; font-size: 2rem; animation-delay: 5s; }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(5deg); }
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 0.9rem;
            margin-bottom: 20px;
            border: 1px solid var(--glass-border);
        }

        .hero-title {
            font-size: clamp(1.8rem, 4vw, 2.5rem);
            font-weight: 700;
            margin-bottom: 10px;
        }

        .hero-title span {
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-subtitle {
            font-size: 1.1rem;
            color: rgba(255, 255, 255, 0.8);
        }

        /* --- NAVIGATION --- */
        .navbar-custom {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.1);
            z-index: 1030;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            padding: 15px 0;
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .navbar-brand i {
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .app-header { display: none; }

        /* ============================================
           BOOKING STEPPER NAVIGATION
           ============================================ */
        .booking-stepper {
            background: white;
            padding: 25px 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            position: relative;
            z-index: 100;
        }

        .stepper-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 900px;
            margin: 0 auto;
            padding: 0 20px;
            position: relative;
        }

        .stepper-line {
            position: absolute;
            top: 50%;
            left: 60px;
            right: 60px;
            height: 3px;
            background: rgba(15, 23, 42, 0.7);
            transform: translateY(-50%);
            z-index: 1;
        }

        .stepper-line-progress {
            position: absolute;
            top: 50%;
            left: 60px;
            height: 3px;
            background: rgba(15, 23, 42, 0.7);
            transform: translateY(-50%);
            z-index: 2;
            transition: width 0.3s ease;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 3;
            cursor: default;
        }

        .step-circle {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: white;
            border: 3px solid rgba(15, 23, 42, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: #94a3b8;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            margin-bottom: 8px;
        }

        .step.active .step-circle {
            background: rgba(15, 23, 42, 0.9);
            border-color: rgba(15, 23, 42, 0.9);
            color: white;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.3);
        }

        .step.completed .step-circle {
            background: rgba(15, 23, 42, 0.7);
            border-color: rgba(15, 23, 42, 0.7);
            color: white;
        }

        .step-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #94a3b8;
            text-align: center;
            white-space: nowrap;
        }

        .step.active .step-label {
            color: rgba(15, 23, 42, 0.9);
        }

        .step.completed .step-label {
            color: rgba(15, 23, 42, 0.7);
        }

        @media (max-width: 768px) {
            .stepper-container {
                padding: 0 10px;
            }
            .step-circle {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }
            .step-label {
                font-size: 0.65rem;
            }
            .stepper-line {
                left: 45px;
                right: 45px;
            }
        }

        .alert-info-custom { border-left: 5px solid var(--primary-color); background-color: #e9f0f4; color: #1f374e; }
        .info-box { background-color: var(--card-bg); padding: 20px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); }
        .mechanic-names { font-style: italic; color: #5a5a5a; margin-top: 10px; border-top: 1px dashed #e0e0e0; padding-top: 10px; }
        .mechanic-names strong { color: #343a40; }
        .final-summary-box { border: 2px solid var(--primary-color); }
        .info-card {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--border-color, #E2E8F0);
        }
        .summary-title {
            font-size: 1.1rem;
            font-weight: bold;
            color: var(--primary-color);
        }
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
        .btn-orange {
            background: var(--primary-gradient);
            color: white;
            border: none;
        }
        .btn-orange:hover {
            background: #0f172a;
            color: white;
        }

        .btn-back {
            background: var(--primary-gradient);
            border: 1px solid var(--primary-color);
            color: #ffffff;
            font-weight: 600;
        }
        .btn-back:hover {
            background: #0f172a;
            color: #ffffff;
            border-color: #0f172a;
        }

        /* --- COMPACT / MINIMIZE --- */
        .page-stepper { gap: 20px; margin-bottom: 16px; padding: 10px 0; }
        .page-stepper .step-item i { font-size: 1.1rem; }
        .page-stepper .step-item span { font-size: 0.8rem; }
        .booking-stepper { padding: 15px 0; }
        .step-circle { width: 40px; height: 40px; font-size: 1.1rem; }
        .step-label { font-size: 0.65rem; }
        .info-card { padding: 15px; }
        .summary-title { font-size: 1rem; }
        .list-group-item { font-size: 0.85rem; padding: 0.5rem 0.75rem; }
        .mechanic-names { font-size: 0.85rem; margin-top: 8px; padding-top: 8px; }
        .btn { font-size: 0.85rem; padding: 0.4rem 0.8rem; }
        .btn-orange { padding: 0.4rem 0.8rem; }
        .alert { font-size: 0.85rem; padding: 0.75rem; }
        .table { font-size: 0.75rem; }
        .table th, .table td { padding: 0.4rem; }
    </style>
</head>
<body>

<?php include 'customer_sidebar.php'; ?>

<!-- Main Content Area -->
<div class="main-content">
    <!-- Top Bar -->
    <div class="top-bar">
        <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="bi bi-list"></i>
            </button>
            <h1 class="top-bar-title">Review Booking</h1>
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

   

    <div class="d-flex align-items-center mb-4">
        
        <div class="page-stepper w-100 mb-0">
            <div class="step-item">
                <i class="bi bi-wrench"></i>
                <span>Service & Schedule</span>
            </div>
            <div class="step-item">
                <i class="bi bi-clock"></i>
                <span>Choose Time</span>
            </div>
            <div class="step-item">
                <i class="bi bi-person-badge"></i>
                <span>Choose Mechanic</span>
            </div>
            <div class="step-item active">
                <i class="bi bi-calendar-check"></i>
                <span>Review</span>
            </div>
            <div class="step-item">
                <i class="bi bi-credit-card"></i>
                <span>Payment</span>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12">
            <div class="info-card">
                <h5 class="summary-title mb-3"><i class="bi bi-calendar-check me-1"></i> Booking Summary</h5>
                <div class="border-start border-primary border-3 ps-2 mb-3">
                    <p class="mb-0 small">Date: <?= $date_display_short ?></p>
                    <p class="mb-0 small">Time: <?= $start_time_display ?> - <?= $end_time_display ?></p>
                    <p class="mb-0 small">Duration: <?= $interval ?> minutes</p>
                </div>

                <?php if (!empty($selected_services) || !empty($selected_packages)): ?>
                    <h6 class="summary-title mb-2"><i class="bi bi-wrench me-1"></i> Selected Services & Packages</h6>
                    <ul class="list-group mb-3">
                        <?php foreach ($selected_services as $s): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><?= htmlspecialchars($s['service_name']) ?></span>
                                <span class="fw-bold text-dark">₱<?= number_format($s['price'], 2) ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php foreach ($selected_packages as $p): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><?= htmlspecialchars($p['package_name']) ?></span>
                                <span class="fw-bold text-dark">₱<?= number_format($p['price'], 2) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <h6 class="summary-title mb-2"><i class="bi bi-tools me-1"></i> Required Skills</h6>
                <p class="small text-muted mb-3">
                    <?= !empty($required_list) ? htmlspecialchars($required_list) : 'General Repair' ?>
                </p>

                <h6 class="summary-title mb-2"><i class="bi bi-person-workspace me-2"></i> Selected Mechanics</h6>
                <div class="p-3 mb-3 border-start border-primary border-3">
                    <p class="mb-0 fw-bold">
                        <?= count($selected_mechanic_names) ?> Mechanic(s) selected:
                    </p>
                    <div class="mechanic-names">
                        <?= htmlspecialchars($mechanic_list_display) ?>
                    </div>
                </div>

                <form method="post">
                    <input type="hidden" name="proceed_payment" value="1">
                    <div class="d-flex flex-wrap justify-content-between gap-2 mt-4 pt-3 border-top">
                        <a href="select_mechanic.php" class="btn btn-back">
                            <i class="bi bi-arrow-left me-2"></i> Back
                        </a>
                        <button type="submit" class="btn btn-orange">
                            <i class="bi bi-credit-card-fill me-2"></i> Proceed to Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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