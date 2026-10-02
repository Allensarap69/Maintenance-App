<?php
session_start();
require 'db.php'; // Your database connection file

$active_page = 'book_service.php';
$username = $_SESSION['username'] ?? 'Customer';

// --- Initial Message Handler ---
$msg = "";
if (isset($_GET['error_msg'])) {
    $msg = urldecode($_GET['error_msg']);
}

// --- Security Check ---
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    header("Location: login.php");
    exit;
}

// -------------------------------------------------------------------------------------------------
// --- CRITICAL VALIDATION AND DATA PREPARATION (FIXED DISPLAY LOGIC) ---
// -------------------------------------------------------------------------------------------------

// Define the required fields for a successful booking
$required_keys = ['date', 'mechanic_ids', 'start_time', 'end_time', 'service_ids', 'vehicle_id', 'mechanic_id']; 
$booking_data = $_SESSION['final_booking_details'] ?? [];

$missing_data = false;
foreach ($required_keys as $key) {
    if (!isset($booking_data[$key]) || (is_array($booking_data[$key]) && empty($booking_data[$key])) || empty($booking_data[$key])) {
        if ($key === 'service_ids' && isset($booking_data[$key])) continue;
        
        $missing_data = true;
        break;
    }
}

if ($missing_data) {
    header("Location: view_availability.php?error_msg=" . urlencode("❌ Essential booking details are missing. Please re-select your time."));
    exit;
}

// --- Dynamic Price Calculation ---
$service_ids = $booking_data['service_ids'];
$package_ids = $booking_data['package_ids'] ?? [];
$total_price = 0;

if (!empty($service_ids)) {
    try {
        $placeholders = rtrim(str_repeat('?,', count($service_ids)), ',');
        $stmt = $pdo->prepare("SELECT price FROM services WHERE id IN ($placeholders)");
        $stmt->execute($service_ids);
        
        $service_prices = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'price');
        $total_price += array_sum($service_prices);

    } catch (PDOException $e) {
        error_log("Price calculation error: " . $e->getMessage());
        header("Location: select_service.php?error_msg=" . urlencode("⚠️ Failed to confirm service price. Please try selecting services again."));
        exit;
    }
}

if (!empty($package_ids)) {
    try {
        $pkg_placeholders = rtrim(str_repeat('?,', count($package_ids)), ',');
        $stmt = $pdo->prepare("SELECT price FROM service_packages WHERE id IN ($pkg_placeholders)");
        $stmt->execute($package_ids);
        
        $package_prices = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'price');
        $total_price += array_sum($package_prices);

    } catch (PDOException $e) {
        error_log("Package price calculation error: " . $e->getMessage());
    }
}

// Store the calculated price back into the session
$_SESSION['final_booking_details']['total_price'] = $total_price;
$booking_data['total_price'] = $total_price; 

// --- Assign core variables from session ---
$user_id = $_SESSION['user_id'];
$vehicle_id = $booking_data['vehicle_id'];
$schedule_date = $booking_data['date'];
$schedule_start_time = $booking_data['start_time'];
$schedule_end_time = $booking_data['end_time'];
$mechanic_id = $booking_data['mechanic_id']; // The single primary ID for DB insert
$mechanic_ids_array = $booking_data['mechanic_ids']; // ARRAY of ALL mechanic IDs
$slot_id = $booking_data['slot_id'] ?? null; // Keep null if not used

// --- Define Payment Details & Calculate Deposit/Balance ---
$gcash_qr_path = 'g.png.jpg'; 
$deposit_percentage = 0.50; // 50% deposit
$deposit_amount = round($total_price * $deposit_percentage, 2);
$remaining_balance = round($total_price - $deposit_amount, 2);


// -------------------------------------------------------------------------------------------------
// --- MANUAL TRANSACTION REFERENCE LOGIC ---
// -------------------------------------------------------------------------------------------------

function generate_unique_booking_id() {
    // Generate a unique booking ID for internal use and display
    return substr(time(), -6) . str_pad(mt_rand(0, 999), 3, '0', STR_PAD_LEFT);
}

if (!isset($booking_data['system_booking_ref'])) {
    $booking_data['system_booking_ref'] = generate_unique_booking_id();
    $_SESSION['final_booking_details']['system_booking_ref'] = $booking_data['system_booking_ref'];
}
$system_booking_ref = $booking_data['system_booking_ref']; 


// --- 🔑 FIX: Retrieve ALL mechanic names for display (from view_availability.php) ---
$mechanic_info_display = $booking_data['mechanic_list_display'] ?? "Auto-assigned by Admin";

// If only one was selected, we check that too, for robustness.
if (empty($mechanic_info_display) && $mechanic_id) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM mechanics WHERE id = ?");
        $stmt->execute([$mechanic_id]);
        $mechanic_info_display = htmlspecialchars($stmt->fetchColumn());
    } catch (PDOException $e) {
        $mechanic_info_display = "Error fetching mechanic details.";
    }
}


// --- Final checks ---
if ($total_price <= 0 || !$mechanic_id) { 
    header("Location: select_service.php?error_msg=" . urlencode("Critical booking data is invalid. Please restart the service selection."));
    exit;
}


// --- 1. Fetch Motorcycle Details for Display (Updated Logic) ---
$selected_vehicle_details = [];
$vehicle_info_display = "N/A";
if ($vehicle_id && $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT brand, model, year_model, plate_number FROM motorcycles WHERE id = ? AND user_id = ?");
        $stmt->execute([$vehicle_id, $user_id]);
        $selected_vehicle_details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($selected_vehicle_details) {
            $v = $selected_vehicle_details;
            $vehicle_info_display = htmlspecialchars($v['year_model']) . " " . htmlspecialchars($v['brand']) . " " . htmlspecialchars($v['model']) . " (Plate: " . htmlspecialchars($v['plate_number']) . ")";
        }
    } catch (PDOException $e) {
        $vehicle_info_display = "Error fetching motorcycle details.";
    }
}


// --- Calculate and retrieve service details for display (Existing Logic) ---
$selected_service_details = [];
if (!empty($service_ids)) {
    $placeholders = rtrim(str_repeat('?,', count($service_ids)), ',');
    $stmt = $pdo->prepare("SELECT service_name, price FROM services WHERE id IN ($placeholders)");
    $stmt->execute($service_ids);
    $selected_service_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$selected_package_details = [];
if (!empty($package_ids)) {
    $pkg_placeholders = rtrim(str_repeat('?,', count($package_ids)), ',');
    $stmt = $pdo->prepare("SELECT package_name, price FROM service_packages WHERE id IN ($pkg_placeholders)");
    $stmt->execute($package_ids);
    $selected_package_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// -------------------------------------------------------------------------------------------------
// --- 3. FINAL BOOKING CONFIRMATION & PAYMENT LOGIC (FIXED) ---
// -------------------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_booking_final'])) {

    // Re-check essential data before database transaction
    if ($total_price <= 0 || !$mechanic_id) {
        header("Location: select_service.php?error_msg=" . urlencode("❌ Missing essential booking details during final submission. Please restart the process."));
        exit;
    }

    $payment_method = $_POST['payment_method'] ?? 'cash';
    $receipt_path = null;
    $booking_status = 'pending'; 
    $payment_transaction_type = 'full_payment'; 
    $payment_status_for_table = 'pending'; 
    $success_message = "✅ Booking Confirmed! Please prepare CASH for your payment upon service completion. Thank you!";
    
    // --- Manual Transaction Reference Retrieval (KEY LOGIC) ---
    $transaction_ref_manual = null;
    if ($payment_method === 'gcash') {
        $transaction_ref_manual = $_POST['transaction_ref_manual'] ?? null;
        
        // Server-side validation for the 10-digit number
        if (empty($transaction_ref_manual) || !preg_match('/^\d{10}$/', $transaction_ref_manual)) {
             header("Location: confirm_payment.php?error_msg=" . urlencode("❌ Please enter exactly 10 numeric digits for the GCash Transaction Reference Number."));
             exit;
        }
    }
    // Set the reference for the DB insert (use the manual input if GCash, otherwise use the system reference for cash reference)
    $transaction_ref_for_db = $transaction_ref_manual ?? $system_booking_ref; 
    // --- END KEY LOGIC ---


    // Set payment amount for the database record
    if ($payment_method === 'cash') {
        $payment_amount = $total_price; // Records the full amount that is due
    } else { // GCASH (Deposit)
        $payment_amount = $deposit_amount; 
        $booking_status = 'deposit_submitted'; 
        $payment_transaction_type = 'deposit';
        $payment_status_for_table = 'pending'; // Deposit status is pending until admin verifies receipt
        $success_message = "✅ Deposit Receipt Uploaded! Your booking slot is confirmed. Your 50% deposit will be verified by admin shortly.";
    }
    
    // --- Validation and File Handling for GCASH (Unchanged and working) ---
    if ($payment_method === 'gcash') {
        
        if (empty($_FILES['receipt_image']['name'])) {
            header("Location: confirm_payment.php?error_msg=" . urlencode("❌ Please upload a receipt image for GCash deposit."));
            exit;
        }

        $target_dir = "uploads/receipts/";
        
        if (!is_dir($target_dir)) {
            if (!mkdir($target_dir, 0775, true)) { 
                header("Location: confirm_payment.php?error_msg=" . urlencode("❌ Server Error: Failed to create upload directory. Check file system permissions."));
                exit;
            }
        }
        
        $file_extension = strtolower(pathinfo($_FILES['receipt_image']['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'pdf'];

        if (!in_array($file_extension, $allowed_ext)) {
               header("Location: confirm_payment.php?error_msg=" . urlencode("❌ Invalid file type. Only JPG, JPEG, PNG, and PDF are allowed."));
               exit;
        }

        $new_filename = uniqid('receipt_') . '.' . $file_extension;
        $target_file = $target_dir . $new_filename;
        
        if (!move_uploaded_file($_FILES['receipt_image']['tmp_name'], $target_file)) {
            $file_error_code = $_FILES['receipt_image']['error'];
            $error_detail = ($file_error_code !== UPLOAD_ERR_OK) ? "File Upload Error Code: " . $file_error_code . ". The file may be too large." : "Server Error: Failed to save receipt file. Check folder permissions.";

            header("Location: confirm_payment.php?error_msg=" . urlencode("❌ " . $error_detail));
            exit; 
        }
        
        $receipt_path = $target_file; 
    }

    // --- Database Insertion (Wrapped in Transaction) ---
    $pdo->beginTransaction();
    try {
        // 1. Insert into BOOKINGS table
        $stmt_booking = $pdo->prepare("
            INSERT INTO bookings (
                user_id, service_ids, package_ids, vehicle_id, schedule_date, 
                schedule_start_time, schedule_end_time, total_price, status,
                mechanic_id 
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
        ");
        
        $stmt_booking->execute([
            $user_id,
            json_encode($service_ids),
            json_encode($package_ids),
            $vehicle_id,
            $schedule_date,
            $schedule_start_time,
            $schedule_end_time,
            $total_price,
            $booking_status,
            $mechanic_id 
        ]);
        
        $new_booking_id = $pdo->lastInsertId();

        // 2. Insert ALL assigned mechanics into the 'booking_mechanics' table
        $mechanic_ids_to_insert = is_array($mechanic_ids_array) ? $mechanic_ids_array : [$mechanic_id]; 
        
        if (!empty($mechanic_ids_to_insert)) {
            $sql_mechanics = "INSERT INTO booking_mechanics (booking_id, mechanic_id) VALUES ";
            $placeholders = [];
            $params = [];
            
            foreach ($mechanic_ids_to_insert as $m_id) {
                $placeholders[] = '(?, ?)';
                $params[] = $new_booking_id;
                $params[] = $m_id;
            }
            
            $sql_mechanics .= implode(', ', $placeholders);
            $stmt_mechanics = $pdo->prepare($sql_mechanics);
            $stmt_mechanics->execute($params);
        }

        // 3. Insert into PAYMENTS table
        $stmt_payment = $pdo->prepare("
            INSERT INTO payments (
                booking_id, amount, payment_method, transaction_type, 
                receipt_path, status, transaction_ref
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt_payment->execute([
            $new_booking_id,             
            $payment_amount,             
            $payment_method,             
            $payment_transaction_type,   
            $receipt_path,               
            $payment_status_for_table,   
            $transaction_ref_for_db      
        ]);


        // 4. Update the availability slot - (Still commented out)

        $pdo->commit();
        
        // Success redirect
        unset($_SESSION['final_booking_details']);
        
        // Set confirmation modal flag and message
        $_SESSION['show_confirmation_modal'] = true;
        $_SESSION['confirmation_message'] = $success_message;
        $_SESSION['confirmation_payment_method'] = $payment_method;
        
        header("Location: dashboard_customer.php?msg=" . urlencode($success_message));
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        // Clean up uploaded file if DB fails
        if ($receipt_path && file_exists($receipt_path)) unlink($receipt_path);
        
        $error_detail = "Booking failed due to a critical database error. Error: " . $e->getMessage();
        
        header("Location: confirm_payment.php?error_msg=" . urlencode("❌ " . $error_detail));
        exit;
    }
}
// -------------------------------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
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
    <title>Confirm Payment & Booking</title>
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
            --accent-gradient: linear-gradient(135deg, #3b82f6 0%, #FACC15 100%);
            --bg-light: #F8FAFC;
            --card-bg: white;
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
        .card-summary {
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            border: none;
            overflow: hidden;
            background: white;
        }

        .card-body-custom {
            padding: 0;
        }

        .card-body-custom > div {
            padding: 30px;
        }

        /* --- TRANSACTION REF BOX --- */
        .transaction-ref-box {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(255, 255, 255, 0.5) 100%);
            border: 2px solid var(--accent-color);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            margin-bottom: 25px;
        }

        .transaction-ref-box small {
            color: var(--text-light);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .transaction-ref-box span {
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-weight: 700;
            font-size: 1.1rem;
        }

        /* --- SCHEDULE BOX --- */
        .schedule-box {
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
        }

        .schedule-box h5 {
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-weight: 600;
        }

        .schedule-box p {
            color: var(--text-dark);
            line-height: 1.6;
        }

        .schedule-box .detail-row:last-child {
            margin-bottom: 0;
        }

        /* --- GRAND TOTAL --- */
        .grand-total small {
            font-size: 0.75rem;
            color: var(--text-light);
            letter-spacing: 1px;
            text-transform: uppercase;
            display: block;
            margin-bottom: 0.2rem;
        }

        .grand-total h1 {
            color: var(--accent-color);
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            line-height: 1.2;
        }

        /* --- QR SECTION --- */
        .qr-section {
            background: linear-gradient(135deg, #fff7ed 0%, #ffffff 100%);
            border: 2px dashed var(--accent-color);
            padding: 25px;
            border-radius: 16px;
            margin-top: 20px;
        }

        .qr-section p {
            color: var(--primary-color);
        }

        .qr-section img {
            max-width: 200px;
            height: auto;
            border: 5px solid white;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            border-radius: 12px;
        }

        /* --- PAYMENT OPTION CARDS --- */
        .payment-option-card {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            cursor: pointer;
            padding: 14px 16px;
            margin-bottom: 12px;
        }

        .payment-option-card label {
            font-size: 0.95rem;
            margin-bottom: 0;
        }

        .payment-option-card p {
            font-size: 0.85rem;
            line-height: 1.4;
            margin: 4px 0 0 1.6rem;
        }

        .payment-option-card:hover {
            border-color: var(--accent-color);
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.15);
        }

        .payment-option-card.active {
            border-color: var(--accent-color);
            background: linear-gradient(135deg, #fff7ed 0%, #ffffff 100%);
            box-shadow: 0 4px 15px rgba(249, 115, 22, 0.2);
        }

        /* --- SERVICE LIST --- */
        .list-group-item {
            border: none;
            border-bottom: 1px solid #f1f5f9;
            padding: 8px 0;
            background: transparent;
            font-size: 0.85rem;
            display: grid;
            grid-template-columns: auto auto;
            gap: 1rem;
            align-items: baseline;
            justify-content: start;
            word-break: break-word;
        }

        .list-group-item:last-child {
            border-bottom: none;
        }

        /* --- BUTTONS --- */
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            font-weight: 600;
            padding: 15px 30px;
            border-radius: 50px;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.5);
        }

        .btn-outline-secondary {
            border: 2px solid #e2e8f0;
            color: var(--text-dark);
            font-weight: 500;
            padding: 12px 30px;
            border-radius: 50px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        }

        .btn-outline-secondary:hover {
            background: var(--primary-gradient);
            border-color: transparent;
            color: white;
        }

        /* --- FORM STYLES --- */
        .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 1rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.1);
        }

        /* --- ALERT STYLES --- */
        .alert-warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(255, 255, 255, 0.5) 100%);
            border: 2px solid var(--accent-color);
            border-radius: 16px;
            color: var(--primary-color);
        }

        /* --- HEADINGS --- */
        h4, h5 {
            font-weight: 600;
        }

        .text-primary {
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent !important;
        }

        .text-secondary {
            color: var(--text-light) !important;
        }

        /* --- RESPONSIVE --- */
        @media (max-width: 991px) {
            .card-body-custom > div {
                padding: 20px;
            }
        }

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
            background: #e2e8f0;
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
            border: 3px solid #e2e8f0;
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
        /* --- SHARED SELECT_MECHANIC STYLES --- */
        .main-container {
            max-width: 1300px;
            padding-top: 8px;
            padding-bottom: 20px;
        }
        .content-row {
            overflow: visible;
        }
        .info-card {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--border-color);
        }
        .summary-title {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--accent-color);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .summary-block {
            margin-bottom: 15px;
        }

        .summary-block:last-of-type {
            margin-bottom: 0;
        }

        .summary-item i {
            color: var(--accent-color);
            font-size: 1.1rem;
            display: block;
            margin-bottom: 0.25rem;
        }
        .summary-item .item-label {
            font-size: 0.7rem;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.1rem;
        }
        .summary-item .item-value {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-dark);
            line-height: 1.3;
        }
        .qr-code-card .list-group-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            padding: 0.35rem 0;
            background: transparent;
        }
        .list-group-numbered .list-group-item::before {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.5rem;
            height: 1.5rem;
            background: var(--primary-color);
            color: white;
            border-radius: 50%;
            font-size: 0.7rem;
            font-weight: 700;
            margin-right: 0.5rem;
        }

        .booking-illustration {
            width: 90px;
            height: 90px;
            background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            color: var(--accent-color);
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.15);
            flex-shrink: 0;
        }
        .booking-illustration i {
            line-height: 1;
        }
        .booking-details .detail-value {
            text-align: right;
        }
        .booking-details .detail-row {
            margin-bottom: 6px;
        }
        .booking-details .detail-row:last-child {
            margin-bottom: 0;
        }
        .service-list .list-group-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .service-list .service-name {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .total-amount {
            background: #fff7ed;
            border: none;
            border-radius: 8px;
            padding: 10px 14px;
            margin-top: 10px;
            justify-content: space-between;
        }

        .compact-card {
            padding: 18px;
        }
        .compact-card .summary-title {
            font-size: 0.85rem;
            margin-bottom: 0.7rem;
        }
        .compact-section {
            margin-bottom: 1.25rem;
        }
        .compact-section.border-bottom {
            padding-bottom: 1.25rem;
        }
        .compact-card .summary-item .item-label {
            font-size: 0.8rem;
            margin-bottom: 0.2rem;
        }
        .compact-card .summary-item .item-value {
            font-size: 1rem;
        }
        .compact-card .service-list .list-group-item {
            padding: 8px 0;
            font-size: 0.9rem;
        }
        .compact-card .total-amount {
            padding: 10px 14px;
            margin-top: 10px;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        .compact-section p.small {
            font-size: 0.9rem;
            line-height: 1.4;
        }
        .text-accent {
            color: var(--accent-color) !important;
        }
        .border-left-accent {
            border-left-color: var(--accent-color) !important;
        }

        .detail-row {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 1rem;
            align-items: baseline;
            font-size: 0.85rem;
            margin-bottom: 2px;
        }
        .detail-label {
            color: var(--text-light);
            font-weight: 500;
            flex-shrink: 0;
        }
        .detail-value {
            color: var(--text-dark);
            font-weight: 600;
            text-align: left;
            word-break: break-word;
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
            color: #F97316;
        }
        .page-stepper .step-item span {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-dark);
        }
        .page-stepper .step-item.active span {
            color: #F97316;
            font-weight: 600;
        }
        .page-stepper .step-item.active i {
            color: #F97316;
        }
        .fixed-footer-bar {
            background-color: var(--card-bg);
            border-top: 1px solid var(--border-color);
            padding: 16px 0;
            z-index: 1000;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
        }
        .btn-check-availability {
            background: var(--primary-gradient);
            color: white;
            border-color: var(--primary-color);
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
        }
        .btn-check-availability:hover {
            background: #172A46;
            border-color: #172A46;
            color: white;
        }
        .btn-outline-accent {
            color: var(--accent-color);
            border-color: var(--accent-color);
            background-color: transparent;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
        }
        .btn-back {
            background: #ffffff;
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
        }
        .btn-back:hover {
            background: var(--primary-gradient);
            color: white;
            border-color: var(--primary-color);
        }

        .btn-outline-accent:hover,
        .btn-outline-accent:focus {
            background-color: var(--accent-color);
            color: white;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        .alert-warning h4 {
            font-size: 1.25rem;
            font-weight: 700;
        }
        .alert-warning small {
            font-size: 0.8rem;
        }

        @media (max-width: 576px) {
            .detail-row {
                grid-template-columns: 1fr;
                gap: 2px;
            }
            .detail-value {
                text-align: left;
            }
            .list-group-item {
                grid-template-columns: 1fr;
                gap: 2px;
            }
        }
        /* --- GCASH PAYMENT LAYOUT --- */
        .gcash-deposit-card {
            background: linear-gradient(135deg, #fff7ed 0%, #ffffff 100%);
            border: 1px dashed var(--accent-color);
            border-radius: 14px;
            padding: 18px;
        }

        .gcash-deposit-card .deposit-amount {
            color: var(--accent-color);
            font-weight: 700;
            font-size: 1.6rem;
            margin: 0;
        }

        .qr-code-card {
            background: #ffffff;
            border: 2px dashed var(--accent-color);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
        }

        .qr-code-card img {
            max-width: 140px;
            height: auto;
            border: 4px solid #fff;
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
            border-radius: 12px;
        }

        .receipt-upload .form-control {
            padding: 10px 14px;
        }

        /* --- COMPACT / MINIMIZE --- */
        .page-stepper { gap: 20px; margin-bottom: 16px; padding: 10px 0; }
        .page-stepper .step-item i { font-size: 1.1rem; }
        .page-stepper .step-item span { font-size: 0.8rem; }
        .booking-stepper { padding: 15px 0; }
        .step-circle { width: 40px; height: 40px; font-size: 1.1rem; }
        .step-label { font-size: 0.65rem; }
        .card-body-custom > div { padding: 20px; }
        .transaction-ref-box { padding: 15px; margin-bottom: 18px; }
        .transaction-ref-box span { font-size: 1rem; }
        .schedule-box { padding: 18px; }
        .qr-section { padding: 18px; margin-top: 15px; }
        .qr-section img { max-width: 160px; }
        .payment-option-card { padding: 10px 12px; margin-bottom: 10px; }
        .payment-option-card label { font-size: 0.85rem; }
        .payment-option-card p { font-size: 0.75rem; }
        .form-control { padding: 10px 14px; font-size: 0.9rem; }
        .btn-success { padding: 12px 24px; font-size: 0.9rem; }
        .btn-outline-secondary { padding: 10px 20px; font-size: 0.85rem; }
        .list-group-item { font-size: 0.8rem; padding: 6px 0; }
        .compact-card { padding: 15px; }
        .compact-card .summary-item .item-value { font-size: 0.9rem; }
        .compact-card .service-list .list-group-item { font-size: 0.85rem; }
        .compact-card .total-amount { font-size: 0.85rem; }
        .gcash-deposit-card .deposit-amount { font-size: 1.3rem; }
        .detail-row { font-size: 0.8rem; }
        .alert-warning h4 { font-size: 1.1rem; }
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
            <h1 class="top-bar-title">Confirm Payment</h1>
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

<!-- In-Page Stepper -->
<div class="container main-container">
    <div class="page-stepper">
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
        <div class="step-item">
            <i class="bi bi-calendar-check"></i>
            <span>Review</span>
        </div>
        <div class="step-item active">
            <i class="bi bi-credit-card"></i>
            <span>Payment</span>
        </div>
    </div>
</div>

<!-- Content Section -->
<div class="container main-container">
    
    <?php if (!empty($msg)): ?>
        <div class="alert alert-danger text-center mb-4 fw-bold"><i class="bi bi-x-octagon-fill me-1"></i> <?= $msg ?></div>
    <?php endif; ?>

                        <form method="POST" action="" enctype="multipart/form-data">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                            <div>
                                
                                <h2 class="h5 fw-bold text-dark mb-0">Booking & Payment Details</h2>
                                <p class="small text-muted mb-0">Please complete your payment to finalize your booking.</p>
                            </div>
                           
                        </div>
                        <div class="row g-4">
                            <div class="col-12 col-lg-6">
                        <!-- Booking Summary -->
                        <div class="info-card compact-card summary-block">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div class="booking-details flex-grow-1">
                                    <p class="summary-title mb-2"><i class="bi bi-calendar-check me-2"></i> Booking Summary</p>
                                    <div class="row g-3">
                                        <div class="col-6 summary-item">
                                            <div class="item-label">Ref #</div>
                                            <div class="item-value"><?= htmlspecialchars($system_booking_ref) ?></div>
                                        </div>
                                        <div class="col-6 summary-item">
                                            <div class="item-label">Date</div>
                                            <div class="item-value"><?= date('D, M jS, Y', strtotime($schedule_date)) ?></div>
                                        </div>
                                        <div class="col-6 summary-item">
                                            <div class="item-label">Time</div>
                                            <div class="item-value"><?= date('h:i A', strtotime($schedule_start_time)) ?> - <?= date('h:i A', strtotime($schedule_end_time)) ?></div>
                                        </div>
                                        <div class="col-6 summary-item">
                                            <div class="item-label">Duration</div>
                                            <div class="item-value"><?= round((strtotime($schedule_end_time) - strtotime($schedule_start_time)) / 60) ?> minutes</div>
                                        </div>
                                        <div class="col-6 summary-item">
                                            <div class="item-label">Mechanic</div>
                                            <div class="item-value"><?= $mechanic_info_display ?></div>
                                        </div>
                                        <div class="col-12 summary-item">
                                            <div class="item-label">Vehicle</div>
                                            <div class="item-value"><?= $vehicle_info_display ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="booking-illustration d-none d-md-block" style="width: 55px; height: 55px; font-size: 1.5rem;">
                                    <i class="bi bi-clipboard-check"></i>
                                </div>
                            </div>
                        </div>
                        <!-- Services & Packages -->
                        <div class="info-card compact-card summary-block">
                            <p class="summary-title mb-2"><i class="bi bi-wrench me-2"></i> Services & Packages Selected</p>
                            <ul class="list-group service-list">
                                <?php foreach ($selected_service_details as $s): ?>
                                    <li class="list-group-item">
                                        <span class="service-name">
                                            <i class="bi bi-gear text-muted"></i>
                                            <?= htmlspecialchars($s['service_name']) ?>
                                        </span>
                                        <span class="fw-bold text-dark">₱<?= number_format($s['price'], 2) ?></span>
                                    </li>
                                <?php endforeach; ?>
                                <?php foreach ($selected_package_details as $p): ?>
                                    <li class="list-group-item">
                                        <span class="service-name">
                                            <i class="bi bi-box-seam text-muted"></i>
                                            <?= htmlspecialchars($p['package_name']) ?>
                                        </span>
                                        <span class="fw-bold text-dark">₱<?= number_format($p['price'], 2) ?></span>
                                    </li>
                                <?php endforeach; ?>
                                    <li class="list-group-item total-amount">
                                        <span class="fw-bold text-accent">TOTAL AMOUNT</span>
                                        <span class="fw-bold text-accent">₱<?= number_format($total_price, 2) ?></span>
                                    </li>
                            </ul>
                        </div>
                       
                            </div>
                            <div class="col-12 col-lg-6">

                        <!-- Payment Selection -->
                        <div class="info-card summary-block">
                            <p class="summary-title"><i class="bi bi-cash-stack me-2"></i> Payment Selection</p>
                            <div class="grand-total mb-2">
                                <small>GRAND TOTAL:</small>
                                <h1>₱<?= number_format($total_price, 2) ?></h1>
                            </div>

                            <div class="payment-option-card" data-target="cash">
                                <div class="d-flex align-items-start gap-2">
                                    <input class="form-check-input mt-1" type="radio" name="payment_method" id="paymentCash" value="cash" checked required>
                                    <div class="flex-grow-1">
                                        <label class="form-check-label fw-bold d-flex align-items-center justify-content-between w-100" for="paymentCash">
                                            <span><i class="bi bi-wallet me-1 text-accent"></i> Cash</span>
                                        </label>
                                        <p class="small text-muted mb-0">Pay the full amount upon service completion. No advance payment required.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="payment-option-card" data-target="gcash">
                                <div class="d-flex align-items-start gap-2">
                                    <input class="form-check-input mt-1" type="radio" name="payment_method" id="paymentGcash" value="gcash" required>
                                    <div class="flex-grow-1">
                                        <label class="form-check-label fw-bold d-flex align-items-center justify-content-between w-100" for="paymentGcash">
                                            <span><i class="bi bi-phone-fill me-1 text-accent"></i> GCash Advance Payment (50% Deposit)</span>
                                            <span class="badge bg-primary">GCash</span>
                                        </label>
                                        <p class="small text-muted mb-0">Pay a 50% deposit now to instantly reserve your slot.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="gcash_details_section" class="info-card summary-block" style="display:none;">
                            <div class="gcash-deposit-card mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <p class="summary-title mb-0"><i class="bi bi-cash-coin me-2"></i> GCash Deposit</p>
                                    <span class="badge rounded-pill bg-light text-success border border-success">SELECTED</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2">
                                    <span class="text-uppercase small fw-bold text-secondary">REQUIRED 50% DEPOSIT NOW</span>
                                    <p class="deposit-amount">₱<?= number_format($deposit_amount, 2) ?></p>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-secondary small fw-bold">Balance Due Later:</span>
                                    <span class="fw-bold text-dark">₱<?= number_format($remaining_balance, 2) ?></span>
                                </div>
                            </div>

                            <div class="qr-code-card mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <p class="summary-title mb-0"><i class="bi bi-qr-code me-2"></i> Scan to Pay Deposit</p>
                                    <span class="badge rounded-pill bg-light text-dark border border-secondary"><i class="bi bi-clock me-1"></i> Expires in <?= date('h:i A', strtotime('+15 minutes')) ?></span>
                                </div>
                                <p class="text-muted small mb-3">Open GCash app and scan the QR code to pay</p>
                                <div class="row g-3 align-items-center">
                                    <div class="col-12 col-md-5 text-center">
                                        <img src="<?= htmlspecialchars($gcash_qr_path) ?>" alt="GCash QR Code" class="img-fluid rounded-3">
                                    </div>
                                    <div class="col-12 col-md-7 text-start">
                                        <ol class="list-group list-group-numbered small">
                                            <li class="list-group-item border-0 py-1 px-0 bg-transparent">Open your GCash app</li>
                                            <li class="list-group-item border-0 py-1 px-0 bg-transparent">Tap "Scan QR"</li>
                                            <li class="list-group-item border-0 py-1 px-0 bg-transparent">Scan the QR code</li>
                                            <li class="list-group-item border-0 py-1 px-0 bg-transparent">Confirm payment of ₱<?= number_format($deposit_amount, 2) ?></li>
                                            <li class="list-group-item border-0 py-1 px-0 bg-transparent">Take a screenshot of your payment receipt</li>
                                        </ol>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4">

                            <p class="summary-title mb-3"><i class="bi bi-input-cursor me-2"></i> Payment Details Submission</p>

                            <div class="mb-3">
                                <label for="transaction_ref_manual" class="form-label fw-bold">GCash Transaction Reference No. <span class="text-danger">*</span></label>
                                <input 
                                    class="form-control" 
                                    type="text" 
                                    id="transaction_ref_manual" 
                                    name="transaction_ref_manual" 
                                    placeholder="Enter the 10-digit GCash reference number" 
                                    maxlength="10" 
                                    pattern="\d{10}"
                                    inputmode="numeric"
                                    title="Please enter exactly 10 numeric digits." 
                                >
                            </div>

                            <div class="mb-2 receipt-upload">
                                <label for="receipt_image" class="form-label fw-bold">Receipt Image/PDF (Required)</label>
                                <input class="form-control" type="file" id="receipt_image" name="receipt_image" accept=".jpg,.jpeg,.png,.pdf">
                                <div class="form-text">Upload a screenshot or photo of your GCash payment confirmation.</div>
                            </div>
                        </div>
                            </div>
                        </div>

                        <!-- Bottom Actions -->
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                            <a href="view_availability.php" class="btn btn-back">
                                <i class="bi bi-arrow-left me-2"></i> Back
                            </a>
                            <button 
                                type="submit" 
                                name="confirm_booking_final" 
                                class="btn btn-check-availability">
                                <i class="bi bi-check-circle-fill me-2"></i> Finalize Booking
                            </button>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const gcashRadio = document.getElementById('paymentGcash');
    const cashRadio = document.getElementById('paymentCash');
    const gcashSection = document.getElementById('gcash_details_section');
    const receiptInput = document.getElementById('receipt_image');
    const paymentCards = document.querySelectorAll('.payment-option-card');
    const manualRefInput = document.getElementById('transaction_ref_manual');

    function toggleGcashDetails() {
        if (gcashRadio.checked) {
            gcashSection.style.display = 'block';
            // Set required dynamically when GCash is selected
            receiptInput.setAttribute('required', 'required'); 
            manualRefInput.setAttribute('required', 'required'); 
        } else {
            gcashSection.style.display = 'none';
            // Remove required and clear inputs when Cash is selected
            receiptInput.removeAttribute('required');
            manualRefInput.removeAttribute('required'); 
            receiptInput.value = ""; 
            manualRefInput.value = ""; 
        }
        
        // Visual feedback for cards
        paymentCards.forEach(card => {
            if (card.querySelector('input').checked) {
                card.classList.add('active');
            } else {
                card.classList.remove('active');
            }
        });
    }

    // --- NEW JAVASCRIPT BLOCK TO BLOCK NON-DIGIT INPUT IN REAL-TIME ---
    manualRefInput.addEventListener('keypress', function(event) {
        // Get the key code or key name
        const key = event.key;
        
        // Use a regular expression to test if the key is NOT a digit (0-9).
        // It also allows navigation/utility keys (like backspace, arrow keys, etc.)
        // by checking if the key.length is exactly 1 (a character).
        if (key.length === 1 && /\D/.test(key)) {
            // Check if it's a digit (0-9)
            if (key >= '0' && key <= '9') {
                // Allow digits (This check is redundant but safe)
            } else {
                // Prevent the default action (i.e., prevent the non-digit character from being typed)
                event.preventDefault();
            }
        }
    });

    // Handle paste events to ensure only digits are pasted (Optional but recommended)
    manualRefInput.addEventListener('paste', function(event) {
        const pasteData = event.clipboardData.getData('text');
        // Check if pasted data contains any non-digit characters
        if (/\D/.test(pasteData)) {
            event.preventDefault();
        }
    });
    // --- END NEW JAVASCRIPT BLOCK ---


    // Attach listeners
    gcashRadio.addEventListener('change', toggleGcashDetails);
    cashRadio.addEventListener('change', toggleGcashDetails);
    
    // Allow clicking the card wrapper to select the radio button
    paymentCards.forEach(card => {
        card.addEventListener('click', (event) => {
            // Check if the click target is NOT the input itself to prevent double-firing
            if (event.target !== card.querySelector('input')) {
                 card.querySelector('input').checked = true;
            }
            toggleGcashDetails();
        });
    });

    // Initial state setup
    document.addEventListener('DOMContentLoaded', toggleGcashDetails);
</script>
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