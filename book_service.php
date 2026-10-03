<?php
session_start();
require 'db.php'; // Ensure db.php is correctly set up for PDO connection

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    header("Location: login.php");
    exit;
}

// --- Auto-proceed from available.php to select_mechanic.php ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['auto_proceed']) && $_GET['auto_proceed'] == '1') {
    $slot_id = $_GET['slot_id'] ?? null;
    $date = $_GET['date'] ?? null;
    $start = $_GET['start'] ?? null;
    $end = $_GET['end'] ?? null;
    $vehicle_id = $_SESSION['temp_vehicle_id'] ?? null;

    if ($slot_id && $date && $start && $end && $vehicle_id) {
        $_SESSION['final_booking_details'] = [
            'service_ids'   => $_SESSION['temp_service_ids'] ?? [],
            'package_ids'   => $_SESSION['temp_package_ids'] ?? [],
            'vehicle_id'    => $vehicle_id,
            'slot_id'       => $slot_id,
            'date'          => $date,
            'start_time'    => $start,
            'end_time'      => $end,
            'total_price'   => 0,
            'mechanic_id'   => null,
        ];
        header("Location: select_mechanic.php");
        exit;
    }
}

$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? 'Customer';
$active_page = basename($_SERVER['PHP_SELF']);

// --- Fetch Customer's Motorcycles ---
$customer_vehicles = [];
if ($user_id) {
    try {
        $stmt = $pdo->prepare("SELECT id, brand, model, year_model, plate_number FROM motorcycles WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$user_id]);
        $customer_vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Handle gracefully
    }
}

// --- LOGIC 1: Save Selections and Redirect to Availability ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_and_check') {
    // Save current selections to session
    $_SESSION['temp_service_ids'] = $_POST['service_id'] ?? [];
    $_SESSION['temp_package_ids'] = $_POST['package_id'] ?? [];
    $_SESSION['temp_vehicle_id'] = $_POST['vehicle_id'] ?? ''; 
    $_SESSION['temp_duration'] = $_POST['total_duration_minutes'] ?? 0;
    
    // Redirect to availability page
    $duration = $_SESSION['temp_duration'] > 0 ? $_SESSION['temp_duration'] : 30;
    header("Location: available.php?duration=" . $duration);
    exit;
}

// --- LOGIC 2: Handle redirect from Slot Selection (Step 2 to Step 3 - Mechanic Selection) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'proceed_to_payment') {
    // Check if a slot and required fields are present
    $has_services = !empty($_POST['service_id']);
    $has_packages = !empty($_POST['package_id']);
    
    if ((!$has_services && !$has_packages) || empty($_POST['vehicle_id']) || empty($_POST['slot_id'])) { 
           $msg = "❌ Please select at least one service or package, a saved vehicle, and an available slot.";
           // Fall through to display the error on the current page
    } else {
        // Save ALL necessary booking details to the session for the next page
        $_SESSION['final_booking_details'] = [
            'service_ids'   => $_POST['service_id'] ?? [],
            'package_ids'   => $_POST['package_id'] ?? [],
            'vehicle_id'    => $_POST['vehicle_id'], 
            'slot_id'       => $_POST['slot_id'],
            'date'          => $_POST['booking_date'] ?? null, 
            'start_time'    => $_POST['booking_start_time'] ?? null,
            'end_time'      => $_POST['booking_end_time'] ?? null,
            'total_price'   => $_POST['total_price_hidden'] ?? 0,
            'mechanic_id'   => null, // Initialize mechanic ID to null/unset
        ];
        // >>> REDIRECT TO THE NEW MECHANIC SELECTION STEP <<<
        header("Location: select_mechanic.php");
        exit;
    }
}

// Clear saved selections after a final "Confirm Booking" is successful (redirect with a success message).
$success_msg = $_GET['msg'] ?? '';
if (!empty($success_msg)) {
    // Clear ALL temporary session data after successful booking
    unset($_SESSION['temp_service_ids']);
    unset($_SESSION['temp_package_ids']);
    unset($_SESSION['temp_vehicle_id']); 
    unset($_SESSION['temp_duration']);
    unset($_SESSION['final_booking_details']);
}

// --- START: SLOT SELECTION HANDLING ---
$selected_slot_id = $_GET['slot_id'] ?? null;
$selected_date = $_GET['date'] ?? null;
$selected_start_time = $_GET['start'] ?? null; 
$selected_end_time = $_GET['end'] ?? null;
$is_slot_selected = false;
$slot_message = "No time slot selected. Click 'Check Availability' below.";

if ($selected_slot_id && $selected_date && $selected_start_time && $selected_end_time) {
    $is_slot_selected = true;
    $date_display = date('D, M jS', strtotime($selected_date));
    $start_time_display = date('h:i A', strtotime($selected_start_time));
    $end_time_display = date('h:i A', strtotime($selected_end_time));
    $slot_message = " Selected Time: " . $date_display . " from " . $start_time_display . " to " . $end_time_display;
} else {
    $selected_slot_id = null;
    $selected_date = null;
    $selected_start_time = null;
    $selected_end_time = null;
}

$total_estimated_time = $_SESSION['temp_duration'] ?? 0;

// If no duration is saved but we have checked services/packages, calculate it
if ($total_estimated_time == 0 && (!empty($checked_services) || !empty($checked_packages))) {
    foreach ($services as $s) {
        if (in_array($s['id'], $checked_services)) {
            $total_minutes = $s['duration_minutes'] ?? 0;
            if ($total_minutes == 0) {
                if (preg_match('/(\d+)\s*hour/i', $s['duration'], $matches_h)) $total_minutes += intval($matches_h[1]) * 60;
                if (preg_match('/(\d+)\s*min/i', $s['duration'], $matches_m)) $total_minutes += intval($matches_m[1]);
            }
            $total_estimated_time += $total_minutes;
        }
    }
    foreach ($packages as $pkg) {
        if (in_array($pkg['id'], $checked_packages)) {
            $total_estimated_time += $pkg['duration_minutes'];
        }
    }
}

function convertMinutesToHoursMins($minutes) {
    if ($minutes <= 0) return "0 mins";
    $hours = floor($minutes / 60);
    $remaining_minutes = $minutes % 60;
    $output = [];
    if ($hours > 0) $output[] = $hours . " hr";
    if ($remaining_minutes > 0) $output[] = $remaining_minutes . " min";
    return implode(" ", $output);
}
$duration_human_readable = convertMinutesToHoursMins($total_estimated_time);

// Get services and previous selections for display
// --- UPDATED SQL QUERY TO FETCH REQUIRED SKILLS ---
$sql_services = "
    SELECT 
        s.*,
        GROUP_CONCAT(sp.specialty_name ORDER BY sp.specialty_name ASC SEPARATOR ', ') AS required_skills
    FROM services s
    LEFT JOIN service_specialties ss ON s.id = ss.service_id
    LEFT JOIN specialties sp ON ss.specialty_id = sp.id
    GROUP BY s.id
    ORDER BY s.service_name
";
$services = $pdo->query($sql_services)->fetchAll(PDO::FETCH_ASSOC);

// --- FETCH SERVICE PACKAGES ---
$sql_packages = "
    SELECT
        sp.*,
        GROUP_CONCAT(
            CONCAT(spi.service_name, '|', spi.description)
            ORDER BY spi.sort_order ASC
            SEPARATOR '||'
        ) AS package_items,
        GROUP_CONCAT(DISTINCT spt.specialty_name ORDER BY spt.specialty_name ASC SEPARATOR ', ') AS required_skills
    FROM service_packages sp
    LEFT JOIN service_package_items spi ON sp.id = spi.package_id
    LEFT JOIN service_package_specialties sps ON sp.id = sps.package_id
    LEFT JOIN specialties spt ON sps.specialty_id = spt.id
    WHERE sp.status = 'active'
    GROUP BY sp.id
    ORDER BY sp.package_name
";
$packages = $pdo->query($sql_packages)->fetchAll(PDO::FETCH_ASSOC);

// Pre-select services and vehicle from admin recommendation link
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['service_id'])) {
    $raw = is_array($_GET['service_id']) ? $_GET['service_id'] : [$_GET['service_id']];
    $_SESSION['temp_service_ids'] = array_map('intval', array_filter($raw, 'is_numeric'));
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['vehicle_id'])) {
    $_SESSION['temp_vehicle_id'] = intval($_GET['vehicle_id']);
}

$checked_services = $_SESSION['temp_service_ids'] ?? [];
$checked_packages = $_SESSION['temp_package_ids'] ?? [];
$selected_vehicle_id = $_SESSION['temp_vehicle_id'] ?? ''; 

// Re-calculate the price based on saved session state for initial load
$initial_total_price = 0;
foreach ($services as $s) {
    if (in_array($s['id'], $checked_services)) {
        $initial_total_price += $s['price'];
    }
}
foreach ($packages as $pkg) {
    if (in_array($pkg['id'], $checked_packages)) {
        $initial_total_price += $pkg['price'];
    }
}
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
    <title>Book Service</title>
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
            --accent-color: #F97316;
            --accent-gradient: linear-gradient(135deg, #F97316 0%, #ea580c 100%);
            --secondary-color: #10b981;
            --bg-light: #F8FAFC;
            --bg-dark: #172A46;
            --text-dark: #172033;
            --text-light: #64748B;
            --white: #ffffff;
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --info-color: #3b82f6;
            --border-color: #E2E8F0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-light);
            scroll-behavior: smooth;
            overflow-x: hidden;
        }

        .sidebar {
            font-family: 'Poppins', sans-serif !important;
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
            border-bottom: 2px solid rgba(245, 158, 11, 0.3);
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
                rgba(245, 158, 11, 0.8) 20%, 
                rgba(59, 130, 246, 0.8) 50%, 
                rgba(245, 158, 11, 0.8) 80%, 
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
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);
            border-color: var(--accent-color);
        }

        /* ============================================
           CARDS
           ============================================ */
        .card-custom {
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--border-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            overflow: hidden;
            background: white;
        }

        .card-custom:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .card-custom .card-body {
            padding: 24px;
        }

        .step-header {
            background: #fff;
            padding: 14px 20px;
            font-weight: 600;
            color: #172033;
            border-bottom: 1px solid #E2E8F0;
            font-size: 1rem;
        }

        html[data-theme="dark"] .step-header {
            background: #22335a;
            color: #e2e8f0;
            border-bottom-color: rgba(255, 255, 255, 0.09);
        }
        
        /* --- MODIFIED SERVICE SELECTION STYLES --- */
        .service-selection-container {
            min-height: 280px;
            max-height: 280px;
            overflow-y: auto;
            padding-right: 8px;
        }

        .service-selection-container::-webkit-scrollbar {
            width: 4px;
        }

        .service-selection-container::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.05);
            border-radius: 2px;
        }

        .service-selection-container::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 2px;
        }

        .row-cols-md-2 > .col {
            flex: 0 0 auto;
            width: 50%;
        }

        @media (max-width: 767.98px) {
            .row-cols-md-2 > .col {
                width: 100%;
            }
        }

        .custom-control { display: block; margin-bottom: 6px; }
        .custom-control label {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 14px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
            background-color: white;
            font-size: 1rem;
            height: 100%;
        }
        .custom-control .package-checkbox + label {
            padding: 10px 12px;
        }
        .custom-control label:hover {
            border-color: var(--accent-color);
            background-color: rgba(249, 115, 22, 0.03);
        }
        .custom-control input {
            position: absolute;
            opacity: 0;
            width: 1px;
            height: 1px;
            overflow: hidden;
        }
        .custom-control input:focus-visible + label {
            outline: 2px solid var(--accent-color);
            outline-offset: 2px;
        }
        .custom-control input:checked + label {
            border-color: var(--accent-color);
            background-color: rgba(249, 115, 22, 0.05);
            font-weight: 500;
        }

        /* Package checkbox styling */
        .package-checkbox:checked + label {
            border-color: var(--accent-color);
            background-color: rgba(249, 115, 22, 0.08);
            font-weight: 500;
        }

        .package-section-divider {
            border-top: 1px dashed var(--border-color);
            margin: 20px 0;
            position: relative;
        }

        .package-section-divider::after {
            content: 'OR';
            position: absolute;
            top: -10px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 0 12px;
            color: var(--text-light);
            font-weight: 500;
            font-size: 0.85rem;
        }

        .price-summary {
            background: white;
            color: var(--text-dark);
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--border-color);
        }

        #totalPrice {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent-color);
        }

        .slot-display {
            background: rgba(249, 115, 22, 0.05);
            border: 1px solid var(--accent-color);
            padding: 14px;
            border-radius: 8px;
            margin-top: 16px;
            font-weight: 500;
            font-size: 1rem;
        }

        .slot-display.invalidated {
            background: rgba(239, 68, 68, 0.05);
            border-color: #ef4444;
        }

        .skill-tag {
            display: inline-block;
            background: rgba(59, 130, 246, 0.08);
            color: var(--info-color);
            padding: 4px 10px;
            margin-right: 4px;
            margin-top: 4px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .package-skills .skill-tag {
            background: transparent !important;
            padding: 0 2px 0 0 !important;
            margin: 0 !important;
            border-radius: 0;
        }

        /* ============================================
           SERVICE TABS
           ============================================ */
        .service-tabs {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 20px;
            background: white;
            border-radius: 8px 8px 0 0;
            padding: 0 4px;
        }

        .service-tab {
            padding: 12px 24px;
            cursor: pointer;
            font-weight: 500;
            color: var(--text-light);
            border-bottom: 2px solid transparent;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
            background: none;
            border: none;
            font-size: 1rem;
            border-radius: 8px 8px 0 0;
        }

        .service-tab:hover {
            color: var(--primary-color);
            background: rgba(23, 42, 70, 0.03);
        }

        .service-tab.active {
            color: var(--accent-color);
            border-bottom: 2px solid var(--accent-color);
            background: rgba(249, 115, 22, 0.05);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .package-section-divider {
            display: none;
        }

        /* ============================================
           SELECTED SERVICES SUMMARY
           ============================================ */
        #servicesList {
            max-height: 180px;
            overflow-y: auto;
            padding-right: 8px;
        }

        #servicesList::-webkit-scrollbar {
            width: 4px;
        }

        #servicesList::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.05);
            border-radius: 2px;
        }

        #servicesList::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 2px;
        }

        #servicesList::-webkit-scrollbar-thumb:hover {
            background: rgba(0, 0, 0, 0.3);
        }

        .service-info {
            display: flex;
            flex-direction: column;
            text-align: start;
            flex-grow: 1;
            margin-right: 8px;
        }

        /* ============================================
           BUTTONS
           ============================================ */
        .btn-accent {
            background: var(--accent-color);
            border: none;
            color: white;
            font-weight: 600;
            padding: 10px 24px;
            border-radius: 8px;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
            box-shadow: 0 2px 8px rgba(249, 115, 22, 0.3);
        }

        .btn-accent:hover {
            background: #ea580c;
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.4);
        }

        .btn-outline-custom {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-dark);
            font-weight: 500;
            padding: 8px 20px;
            border-radius: 8px;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
        }

        .btn-outline-custom:hover {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        /* ============================================
           ANIMATIONS
           ============================================ */
        .fade-in-up {
            animation: fadeInUp 0.8s ease both;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* --- COMPACT / MINIMIZE --- */
        .page-stepper { gap: 20px; margin-bottom: 16px; padding: 10px 0; }
        .page-stepper .step-item i { font-size: 1.1rem; }
        .page-stepper .step-item span { font-size: 0.8rem; }
        .card-custom .card-body { padding: 16px; }
        .step-header { padding: 10px 16px; font-size: 0.9rem; }
        h5, .h5 { font-size: 1rem !important; }
        .form-select, .form-select.form-select-lg { font-size: 0.85rem; padding: 0.4rem 0.75rem !important; }
        .btn, .btn.btn-lg { font-size: 0.85rem; padding: 0.5rem 1rem !important; }
        #mainActionButton { font-size: 0.9rem !important; padding: 10px 16px !important; }
        .service-tab { padding: 10px 16px; font-size: 0.85rem; }
        .custom-control label { padding: 10px 12px; font-size: 0.85rem; }
        .service-info strong { font-size: 0.85rem; }
        .service-info small, .service-info .small { font-size: 0.75rem !important; }
        .skill-tag { font-size: 0.65rem; padding: 2px 5px; }
        .package-skills { font-size: 0.7rem !important; }
        .price-summary { padding: 16px; }
        #totalPrice { font-size: 1.5rem !important; }
        #displayDurationHuman { font-size: 1rem !important; }
        #servicesList { font-size: 0.85rem !important; }
        .slot-display { padding: 10px; font-size: 0.85rem; }
        .alert { font-size: 0.85rem; padding: 0.6rem; }
        .table { font-size: 0.75rem; }
        .table th, .table td { padding: 0.4rem; }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 1200px) {
            .container {
                max-width: 100%;
                padding: 0 20px;
            }
            
            .service-selection-container {
                max-height: 400px;
            }
        }

        @media (max-width: 992px) {
            .page-stepper {
                gap: 24px;
            }
            
            .page-stepper .step-item i {
                font-size: 1.2rem;
            }
            
            .page-stepper .step-item span {
                font-size: 0.85rem;
            }
            
            .service-selection-container {
                max-height: 300px;
            }
        }

        @media (max-width: 768px) {
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
            
            #totalPrice {
                font-size: 1.5rem;
            }
            
            .price-summary {
                padding: 16px;
            }
            
            .step-header {
                padding: 12px 16px;
                font-size: 0.95rem;
            }
            
            .custom-control label {
                padding: 12px 14px;
                font-size: 0.95rem;
            }
            
            .service-tab {
                padding: 10px 20px;
                font-size: 0.95rem;
            }
            
            .service-selection-container {
                max-height: 280px;
            }
            
            .main-content {
                padding-top: 8px;
            }
        }

        @media (max-width: 576px) {
            .container {
                padding: 0 10px;
            }

            /* Tabs split the full width instead of overflowing */
            .service-tabs {
                padding: 0;
            }
            .service-tab {
                flex: 1 1 0;
                min-width: 0;
                padding: 10px 8px;
                font-size: 0.85rem;
                text-align: center;
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
            
            .row-cols-md-2 > .col {
                width: 100%;
            }
            
            .service-selection-container {
                max-height: 250px;
            }
        }

        /* Main content spacing */
        .main-content {
            padding-top: 8px;
        }

        /* Ensure no horizontal overflow */
        html, body {
            max-width: 100%;
            overflow-x: hidden;
        }

        /* Prevent content overflow in cards */
        .card-custom {
            overflow: hidden;
        }

        .card-body {
            overflow-x: hidden;
        }

        /* Service selection container overflow */
        .service-selection-container {
            overflow-x: hidden;
        }

        /* Disable sticky positioning on mobile */
        @media (max-width: 992px) {
            .price-summary {
                position: static !important;
            }
        }

        /* ============================================
           PAGE TRANSITION ANIMATIONS
           ============================================ */
        .main-content {
            animation: fadeInUp 0.6s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card-custom {
            animation: fadeIn 0.8s ease-out;
            animation-delay: 0.2s;
            animation-fill-mode: both;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
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
            <h1 class="top-bar-title">Book Service</h1>
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
        <div class="container py-2">
    
    <!-- In-Page Stepper -->
    <div class="page-stepper">
        <div class="step-item active">
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
        <div class="alert alert-danger text-center mb-4 fw-bold"><i class="bi bi-x-octagon-fill me-1"></i> <?= $msg ?></div>
    <?php endif; ?>
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success text-center mb-4 fw-bold"><i class="bi bi-check-circle-fill me-1"></i> <?= $success_msg ?></div>
    <?php endif; ?>

    <form method="post" id="bookingForm" class="mb-3">
        <input type="hidden" name="slot_id" value="<?= htmlspecialchars($selected_slot_id) ?>"> 
        <input type="hidden" name="booking_date" value="<?= htmlspecialchars($selected_date) ?>"> 
        <input type="hidden" name="booking_start_time" value="<?= htmlspecialchars($selected_start_time) ?>">
        <input type="hidden" name="booking_end_time" value="<?= htmlspecialchars($selected_end_time) ?>">
        
        <input type="hidden" name="total_duration_minutes" id="totalDurationInput" value="<?= $total_estimated_time ?>">
        <input type="hidden" id="originalDurationInput" value="<?= $total_estimated_time ?>"> 
        <input type="hidden" name="total_price_hidden" id="totalPriceHidden" value="<?= $initial_total_price ?>">
        <input type="hidden" name="action" id="formAction" value="save_and_check"> 
        
        <div class="row g-3">
            
            <div class="col-md-8">
                <div class="card card-custom mb-4">
                    <div class="step-header">STEP 1: Vehicle & Service Selection</div>
                    <div class="card-body">
                        
                        <h5 class="mb-2 text-secondary"><i class="bi bi-car-front-fill me-2"></i> Select Your Vehicle</h5>

                        <?php if (empty($customer_vehicles)): ?>
                            <div class="alert alert-warning mb-3">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i> **You have no registered motorcycles.** Please register one before proceeding with a booking.
                            </div>
                            <a href="dashboard_customer.php" class="btn btn-success btn-lg w-100 mb-4">
                                <i class="bi bi-plus-circle-fill me-2"></i> Go to Dashboard to Register Motorcycle
                            </a>
                            <select name="vehicle_id" id="vehicleSelect" class="form-select form-select-lg mb-4" disabled required>
                                <option value="">-- No Motorcycles Registered --</option>
                            </select>
                        <?php else: ?>
                            <select name="vehicle_id" id="vehicleSelect" class="form-select form-select-lg mb-3" required>
                                <option value="">-- Select Your Motorcycle --</option>
                                <?php foreach ($customer_vehicles as $v): ?>
                                    <?php 
                                        $display_name = htmlspecialchars($v['year_model'] . ' ' . $v['brand'] . ' ' . $v['model'] . ' (Plate: ' . $v['plate_number'] . ')');
                                        $is_selected = ($selected_vehicle_id == $v['id']) ? 'selected' : '';
                                    ?>
                                    <option value="<?= $v['id'] ?>" <?= $is_selected ?>>
                                        <?= $display_name ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        
                        <h5 class="mb-2 text-secondary"><i class="bi bi-wrench me-2"></i> Select Services</h5>
                        
                        <!-- Service Tabs -->
                        <div class="service-tabs">
                            <button type="button" class="service-tab active" data-tab="packages">
                                <i class="bi bi-box-seam me-2"></i> Service Packages
                            </button>
                            <button type="button" class="service-tab" data-tab="services">
                                <i class="bi bi-gear me-2"></i> Individual Services
                            </button>
                        </div>
                        
                        <!-- Service Packages Tab Content -->
                        <div class="tab-content active" id="packages-tab">
                            <div class="service-selection-container">
                            <?php if (!empty($packages)): ?>
                                <div class="row row-cols-1 row-cols-md-2 g-2">
                                    <?php foreach ($packages as $pkg): 
                                        // Parse package items
                                        $package_items = [];
                                        if (!empty($pkg['package_items'])) {
                                            $items_array = explode('||', $pkg['package_items']);
                                            foreach ($items_array as $item) {
                                                $parts = explode('|', $item);
                                                $package_items[] = [
                                                    'name' => $parts[0] ?? '',
                                                    'description' => $parts[1] ?? ''
                                                ];
                                            }
                                        }
                                    ?>
                                        <div class="col custom-control">
                                            <input 
                                                type="checkbox" 
                                                id="pkg_<?= $pkg['id'] ?>" 
                                                name="package_id[]" 
                                                value="<?= $pkg['id'] ?>" 
                                                data-price="<?= $pkg['price'] ?>" 
                                                data-duration="<?= $pkg['duration_minutes'] ?>"
                                                data-type="package"
                                                class="package-checkbox"
                                                <?= in_array($pkg['id'], $checked_packages) ? 'checked' : '' ?>>
                                            <label for="pkg_<?= $pkg['id'] ?>">
                                                <div class="service-info" style="width: 100%;">
                                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                                                        <div>
                                                            <strong class="text-primary"><?= htmlspecialchars($pkg['package_name']) ?></strong>
                                                            <small class="text-muted mb-1 d-block" style="font-size: 0.8rem;"><?= $pkg['duration_minutes'] ?> minutes</small>
                                                        </div>
                                                        <span class="fw-bold text-primary" style="white-space: nowrap; font-size: 0.9rem;">₱<?= number_format($pkg['price'], 2) ?></span>
                                                    </div>

                                                    <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px; justify-content: space-between; align-items: flex-start;">
                                                        <div style="flex: 1; min-width: 120px;">
                                                            <small class="text-muted fw-bold" style="font-size: 0.75rem;">Includes:</small>
                                                            <div class="small text-muted" style="font-size: 0.8rem;">
                                                                <?php if (!empty($package_items)): ?>
                                                                    <?php foreach (array_slice($package_items, 0, 3) as $item): ?>
                                                                        <div><i class="bi bi-check-circle-fill text-success me-1"></i><?= htmlspecialchars($item['name']) ?></div>
                                                                    <?php endforeach; ?>
                                                                    <?php if (count($package_items) > 3): ?>
                                                                        <div class="text-primary">+<?= count($package_items) - 3 ?> more services</div>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <em>No items listed</em>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <div style="flex: 1; min-width: 120px; text-align: right;">
                                                            <small class="text-muted fw-bold d-block" style="font-size: 0.75rem;">Required Skills:</small>
                                                            <div class="package-skills" style="display: flex; flex-wrap: wrap; gap: 2px; margin-top: 0; justify-content: flex-end; font-size: 0.8rem;">
                                                                <?php
                                                                    $pkg_skills = !empty($pkg['required_skills']) ? explode(', ', $pkg['required_skills']) : [];
                                                                    $pkg_has_skills = !empty($pkg['required_skills']) && count($pkg_skills) > 0 && !empty(trim($pkg_skills[0]));
                                                                    if ($pkg_has_skills):
                                                                        foreach ($pkg_skills as $skill): ?>
                                                                            <span class="skill-tag"><?= htmlspecialchars(trim($skill)) ?></span>
                                                                <?php endforeach;
                                                                    else: ?>
                                                                    <span class="skill-tag" style="background-color: rgba(100, 116, 139, 0.1); color: var(--text-light);">General Skill</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-1"></i> No service packages available at this time.
                                </div>
                            <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Individual Services Tab Content -->
                        <div class="tab-content" id="services-tab">
                            <div class="service-selection-container"> 
                                <div class="row row-cols-1 row-cols-md-2 g-2">
                                    <?php foreach ($services as $s): 
                                        // PHP Duration Calculation block
                                        $total_minutes = $s['duration_minutes'] ?? 0;
                                        if ($total_minutes == 0) {
                                            if (preg_match('/(\d+)\s*hour/i', $s['duration'], $matches_h)) $total_minutes += intval($matches_h[1]) * 60;
                                            if (preg_match('/(\d+)\s*min/i', $s['duration'], $matches_m)) $total_minutes += intval($matches_m[1]);
                                        }
                                    ?>
                                        <div class="col custom-control">
                                            <input 
                                                type="checkbox" 
                                                id="s_<?= $s['id'] ?>" 
                                                name="service_id[]" 
                                                value="<?= $s['id'] ?>" 
                                                data-price="<?= $s['price'] ?>" 
                                                data-duration="<?= $total_minutes ?>"
                                                data-type="service"
                                                <?= in_array($s['id'], $checked_services) ? 'checked' : '' ?>>
                                            <label for="s_<?= $s['id'] ?>">
                                                <div class="service-info">
                                                    <strong><?= htmlspecialchars($s['service_name']) ?></strong>
                                                    <small class="text-muted mb-1"><?= htmlspecialchars($s['duration']) ?></small>
                                                    
                                                    <div>
                                                    <?php 
                                                        $skills = explode(', ', $s['required_skills']);
                                                        $has_skills = !empty($s['required_skills']) && count($skills) > 0 && !empty(trim($skills[0]));

                                                        if ($has_skills): 
                                                            foreach ($skills as $skill): ?>
                                                                <span class="skill-tag">
                                                                    <?= htmlspecialchars(trim($skill)) ?>
                                                                </span>
                                                        <?php endforeach; 
                                                        else: ?>
                                                            <span class="skill-tag" style="background-color: rgba(100, 116, 139, 0.1); color: var(--text-light);">
                                                                General Skill
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    </div>
                                                <span class="fw-bold text-end">₱<?= number_format($s['price'], 2) ?></span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        </div>
                </div>
            </div>

            <div class="col-md-4">
                
                <div class="price-summary mb-4" style="position: sticky; top: 60px; z-index: 100;">
                    <h5 class="text-center mb-3 fw-bold" style="color: var(--primary-color); font-size: 1.1rem;">Service & Time Summary</h5>
                    <div class="text-center">
                        <small class="d-block mb-2" style="color: var(--text-light); font-size: 0.9rem;">Total Estimated Service Time:</small>
                        <h4 class="fw-bold" id="displayDurationHuman" style="color: var(--text-dark); font-size: 1.2rem;"><?= $duration_human_readable ?></h4>
                        
                        <hr style="border-color: var(--border-color); margin: 12px 0;">
                        <small class="d-block mb-2 fw-bold" style="color: var(--text-dark); font-size: 0.95rem;">TOTAL PRICE:</small>
                        <h3 id="totalPrice" style="font-size: 2rem;">₱<?= number_format($initial_total_price, 2) ?></h3>
                        
                        <hr style="border-color: var(--border-color); margin: 12px 0;">
                        <div id="selectedServicesSummary" class="text-start mt-3">
                            <small class="d-block mb-2 fw-bold" style="color: var(--text-light); font-size: 0.9rem;">Selected Services:</small>
                            <div id="servicesList" class="small" style="color: var(--text-dark); font-size: 0.95rem;">
                                <em style="color: var(--text-light);">No services selected</em>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card card-custom">
                    <div class="step-header">STEP 2: Appointment Slot</div>
                    <div class="card-body text-center">
                        
                        <div class="slot-display mb-3 <?= ($is_slot_selected) ? '' : 'invalidated' ?>">
                            <i class="bi bi-calendar-check me-1"></i>
                            <span class="fw-bold slot-message-js" style="color: var(--text-dark);">
                                <?= $slot_message ?>
                            </span>
                        </div>
                        
                        <button 
                            type="submit" 
                            id="mainActionButton" 
                            class="btn w-100 <?= ($is_slot_selected) ? 'btn-success' : 'btn-secondary' ?>" 
                            name="main_action_btn"
                            style="border-radius: 8px; font-weight: 600; padding: 12px 20px; font-size: 1rem;">
                            <?= ($is_slot_selected) ? '<i class="bi bi-arrow-right-circle me-2"></i> Select Mechanic' : '<i class="bi bi-search me-2"></i> Check Availability' ?>
                        </button>
                        <p class="small mt-2 mb-0 <?= ($is_slot_selected) ? '' : 'd-none' ?>" style="color: var(--text-light); font-size: 0.9rem;">Final review on the next step.</p>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
    </div>
</div>

<script>
// Tab switching functionality
document.addEventListener('DOMContentLoaded', function() {
    const serviceTabs = document.querySelectorAll('.service-tab');
    const tabContents = document.querySelectorAll('.tab-content');
    
    serviceTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            // Remove active class from all tabs and contents
            serviceTabs.forEach(t => t.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            
            // Add active class to clicked tab
            this.classList.add('active');
            
            // Show corresponding content
            const tabId = this.getAttribute('data-tab');
            document.getElementById(tabId + '-tab').classList.add('active');
        });
    });
});

const serviceCheckboxes = document.querySelectorAll("input[name='service_id[]']");
const packageCheckboxes = document.querySelectorAll("input[name='package_id[]']");
const vehicleSelect = document.getElementById("vehicleSelect");
const totalPriceEl = document.getElementById("totalPrice");
const totalPriceHiddenInput = document.getElementById("totalPriceHidden");
const totalDurationInput = document.getElementById("totalDurationInput");
const originalDurationInput = document.getElementById("originalDurationInput"); 
const displayDurationHumanEl = document.getElementById("displayDurationHuman");
const mainActionButton = document.getElementById("mainActionButton");
const bookingForm = document.getElementById("bookingForm");
const formAction = document.getElementById("formAction");
const slotDisplay = document.querySelector(".slot-display");
const slotMessageEl = document.querySelector(".slot-message-js");
const smallTextEl = document.querySelector('.slot-display + .btn-lg + p');
const servicesListEl = document.getElementById("servicesList");

function convertMinutesToHuman(minutes) {
    if (minutes <= 0) return "0 min";
    const hours = Math.floor(minutes / 60);
    const remainingMinutes = minutes % 60;
    
    let output = [];
    if (hours > 0) output.push(hours + " hr");
    if (remainingMinutes > 0) output.push(remainingMinutes + " min");
    
    return output.join(" ");
}

function formatPrice(price) {
    return "₱" + parseFloat(price).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

function resetSlot() {
    // Clear hidden inputs for slot
    document.querySelector("input[name='slot_id']").value = ''; 
    document.querySelector("input[name='booking_date']").value = ''; 
    document.querySelector("input[name='booking_start_time']").value = '';
    document.querySelector("input[name='booking_end_time']").value = '';
    
    // Update visual feedback
    slotMessageEl.innerHTML = "⚠️ **Warning:** Selections changed. Please re-check availability.";
    slotDisplay.classList.add('invalidated');
    slotDisplay.classList.remove('alert-warning');
}

function updateServicesSummary() {
    let selectedServices = [];
    
    // Get selected individual services
    serviceCheckboxes.forEach(cb => {
        if (cb.checked) {
            const label = document.querySelector(`label[for="${cb.id}"]`);
            const serviceName = label.querySelector('strong').textContent;
            const servicePrice = label.querySelector('.fw-bold').textContent;
            selectedServices.push({
                name: serviceName,
                price: servicePrice,
                type: 'service'
            });
        }
    });
    
    // Get selected packages
    packageCheckboxes.forEach(cb => {
        if (cb.checked) {
            const label = document.querySelector(`label[for="${cb.id}"]`);
            const packageName = label.querySelector('strong').textContent;
            const packagePrice = label.querySelector('.fw-bold').textContent;
            selectedServices.push({
                name: packageName,
                price: packagePrice,
                type: 'package'
            });
        }
    });
    
    // Update the display
    if (selectedServices.length === 0) {
        servicesListEl.innerHTML = '<em style="color: var(--text-light);">No services selected</em>';
    } else {
        let html = '';
        selectedServices.forEach(service => {
            const icon = service.type === 'package' ? '<i class="bi bi-box-seam me-1"></i>' : '<i class="bi bi-gear me-1"></i>';
            html += `<div class="mb-2" style="color: var(--text-dark); font-size: 0.95rem;">${icon} ${service.name} <span class="float-end">${service.price}</span></div>`;
        });
        servicesListEl.innerHTML = html;
    }
}

function updateTotals() {
    let sumPrice = 0;
    let sumDuration = 0;
    let selectedVehicleID = vehicleSelect ? vehicleSelect.value : ''; 
    
    // Get current state of slot
    const slotIdInput = document.querySelector("input[name='slot_id']");
    const isSlotCurrentlySelected = slotIdInput.value !== '';
    const initialDuration = parseInt(originalDurationInput.value) || 0;
    
    // PHP variable holding the vehicle ID used for the current, selected slot
    const initialVehicleID = '<?= htmlspecialchars($selected_vehicle_id) ?>'; 

    // Calculate from individual services
    serviceCheckboxes.forEach(cb => {
        if (cb.checked) {
            sumPrice += parseFloat(cb.dataset.price || 0);
            sumDuration += parseInt(cb.dataset.duration) || 0;
        }
    });

    // Calculate from service packages
    packageCheckboxes.forEach(cb => {
        if (cb.checked) {
            sumPrice += parseFloat(cb.dataset.price || 0);
            sumDuration += parseInt(cb.dataset.duration) || 0;
        }
    });

    // === Logic to check if selections invalidate the slot (Crucial) ===
    const durationChanged = sumDuration !== initialDuration;
    const vehicleChanged = selectedVehicleID !== initialVehicleID; 
    
    if (isSlotCurrentlySelected && (durationChanged || vehicleChanged)) {
        resetSlot();
    } else if (!isSlotCurrentlySelected && slotDisplay.classList.contains('invalidated')) {
        // If nothing is selected, but slot was invalidated, return to default message
        slotMessageEl.innerHTML = "No time slot selected. Click 'Check Availability' below.";
        slotDisplay.classList.remove('invalidated');
    }
    // =================================================================


    // Update displays and hidden inputs
    totalPriceEl.innerText = formatPrice(sumPrice);
    totalPriceHiddenInput.value = sumPrice.toFixed(2);
    totalDurationInput.value = sumDuration;
    displayDurationHumanEl.innerText = convertMinutesToHuman(sumDuration);
    
    // Update services summary
    updateServicesSummary();

    // Update Main Action Button state and text
    const slotIsReady = document.querySelector("input[name='slot_id']").value !== '';
    const isVehicleSelected = selectedVehicleID !== '';
    const noVehiclesSaved = vehicleSelect.disabled === true;

    if (sumDuration === 0 || !isVehicleSelected || noVehiclesSaved) {
        mainActionButton.disabled = true;
        mainActionButton.classList.remove('btn-secondary', 'btn-success', 'btn-light', 'text-muted');
        mainActionButton.classList.add('btn-light', 'text-muted');
        mainActionButton.innerHTML = '<i class="bi bi-x-circle me-2"></i> Select Vehicle & Service';
        smallTextEl.classList.add('d-none');
        if (noVehiclesSaved) {
            bookingForm.classList.add('disabled-form'); 
        } else {
             bookingForm.classList.remove('disabled-form'); 
        }
    } else if (slotIsReady) {
        mainActionButton.disabled = false;
        mainActionButton.classList.remove('btn-secondary', 'btn-light', 'text-muted');
        mainActionButton.classList.add('btn-success');
        mainActionButton.innerHTML = '<i class="bi bi-arrow-right-circle me-2"></i> Select Mechanic'; // <<< CHANGED TEXT
        smallTextEl.classList.remove('d-none');
    } else {
        mainActionButton.disabled = false;
        mainActionButton.classList.remove('btn-success', 'btn-light', 'text-muted');
        mainActionButton.classList.add('btn-secondary');
        mainActionButton.innerHTML = '<i class="bi bi-search me-2"></i> Check Availability';
        smallTextEl.classList.add('d-none');
    }
}

// Event Listeners
serviceCheckboxes.forEach(cb => {
    cb.addEventListener("change", function() {
        updateTotals();
    });
});

packageCheckboxes.forEach(cb => {
    cb.addEventListener("change", function() {
        // Only one package at a time
        if (this.checked) {
            packageCheckboxes.forEach(pkg => {
                if (pkg !== this) {
                    pkg.checked = false;
                }
            });
        }
        updateTotals();
    });
});

if (vehicleSelect) {
    vehicleSelect.addEventListener("change", updateTotals);
}

// Initialize the services summary on page load
updateServicesSummary();

// Handle the Main Action Button click
mainActionButton.addEventListener('click', function(e) {
    e.preventDefault(); 

    const slotIsReady = document.querySelector("input[name='slot_id']").value !== '';

    if (this.disabled) return;

    // 1. If slot IS NOT selected: Proceed to Availability
    if (!slotIsReady) {
        formAction.value = "save_and_check"; // Redirect to available.php
        bookingForm.submit();
    } 
    // 2. If slot IS selected: Proceed to Mechanic Selection (New Step)
    else {
        formAction.value = "proceed_to_payment"; // Redirect to select_mechanic.php
        bookingForm.submit();
    }
});

// Initial run to set button state on load
document.addEventListener('DOMContentLoaded', updateTotals);
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