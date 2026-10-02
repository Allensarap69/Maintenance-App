<?php
session_start();
require 'db.php'; // Include your database connection

// ----------------------------------------------------------------------
// 1. SECURITY AND DATA RETRIEVAL (FIXED)
// ----------------------------------------------------------------------

// FIX: Allow both 'customer' and 'admin' roles to access this page.
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'customer' && $_SESSION['role'] !== 'admin')) {
    header("Location: login.php");
    exit;
}

$is_admin = ($_SESSION['role'] === 'admin');

// --- START CUSTOMER LOGIC BLOCK ---
// This entire block only executes if the user is a 'customer'
if (!$is_admin) {

    // Check if necessary booking details and mechanics are in the session (Customer-only check)
    if (!isset($_SESSION['final_booking_details']['mechanic_ids']) || 
        !isset($_SESSION['final_booking_details']['date'])) {
        header("Location: select_mechanic.php?msg=" . urlencode("❌ Please re-select your preferred mechanics."));
        exit;
    }

    $booking_details = $_SESSION['final_booking_details'];
    $selected_mechanic_ids = $booking_details['mechanic_ids'];
    $booking_date = $booking_details['date'];

    $msg = "";
    $msg_type = "";

    // ----------------------------------------------------------------------
    // 2. LOGIC FOR FINAL TIME SLOT SELECTION
    // ----------------------------------------------------------------------

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['final_start_time'])) {
        $final_start_time = trim($_POST['final_start_time']);

        // Update the session with the newly selected confirmed time
        $_SESSION['final_booking_details']['start_time'] = $final_start_time;

        // Redirect to payment confirmation
        header("Location: confirm_payment.php");
        exit;
    }

    // ----------------------------------------------------------------------
    // 3. HELPER FUNCTION: CONFLICT CHECK 
    // ----------------------------------------------------------------------

    /**
     * Checks if ANY of the given mechanics are booked for the specified time slot 
     * in the 'bookings' table.
     */
    function isAnyMechanicBooked($pdo, $mechanic_ids, $date, $time) {
        if (empty($mechanic_ids)) return true; 

        $placeholders = rtrim(str_repeat('?,', count($mechanic_ids)), ',');
        $params = array_merge($mechanic_ids, [$date, $time]);

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(id) 
                FROM bookings
                WHERE mechanic_id IN ($placeholders)
                AND schedule_date = ? 
                AND schedule_start_time = ? 
                AND status IN ('pending', 'accepted', 'in_progress')
            ");
            $stmt->execute($params);
            return $stmt->fetchColumn() > 0;

        } catch (PDOException $e) {
            error_log("Mechanic booking check error: " . $e->getMessage());
            return true; 
        }
    }


    // ----------------------------------------------------------------------
    // 4. CORE AVAILABILITY CALCULATION (QUERY FIXED)
    // ----------------------------------------------------------------------

    // 4.1. Get the admin-defined availability slots for the selected date
    $shop_slots = [];
    try {
        // FIX: Added 'AND mechanic_id IS NULL' to explicitly fetch shop-wide hours
        // and ignore individual mechanic's personal availability if set.
        $stmt = $pdo->prepare("
            SELECT start_time, end_time 
            FROM availability 
            WHERE date = ? AND status = 'available' AND mechanic_id IS NULL
            ORDER BY start_time
        ");
        $stmt->execute([$booking_date]);
        $shop_slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Admin availability fetch error: " . $e->getMessage());
        $shop_slots = []; 
    }

    $available_slots = [];
    $interval = 60; 

    if (!empty($shop_slots)) {
        foreach ($shop_slots as $slot) {
            $start_timestamp = strtotime("$booking_date " . $slot['start_time']);
            $end_timestamp = strtotime("$booking_date " . $slot['end_time']);
            
            $current_timestamp = $start_timestamp;

            while ($current_timestamp < $end_timestamp) {
                $current_time = date('H:i:s', $current_timestamp);

                if (!in_array($current_time, $available_slots)) {
                    if (!isAnyMechanicBooked($pdo, $selected_mechanic_ids, $booking_date, $current_time)) {
                        $available_slots[] = $current_time;
                    }
                }
                $current_timestamp += $interval * 60;
            }
        }
        sort($available_slots);
    }


    // Fetch the names of the selected mechanics for display
    try {
        $mechanic_placeholders = rtrim(str_repeat('?,', count($selected_mechanic_ids)), ',');
        $stmt = $pdo->prepare("SELECT name FROM mechanics WHERE id IN ($mechanic_placeholders)");
        $stmt->execute($selected_mechanic_ids);
        $selected_mechanic_names = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    } catch (PDOException $e) {
        $selected_mechanic_names = ["Error Fetching Mechanic Names"];
        error_log("Mechanic name fetch error: " . $e->getMessage());
    }

    $mechanic_list_display = implode(', ', $selected_mechanic_names);
    $date_display = date('l, F j, Y', strtotime($booking_date));
// --- END CUSTOMER LOGIC BLOCK ---
}


?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title><?= $is_admin ? 'Admin: Set Availability' : 'Confirm Mutual Availability' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { 
            --primary-color: #004d80; 
            --secondary-color: #28a745; 
            --accent-color: #ff8c00; 
            --bg-light: #f4f7f9; 
            --card-bg: white;
            --slot-hover: #e9f0f4; 
        }
        body { background-color: var(--bg-light); font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }
        .app-header { background-color: var(--primary-color); color: white; padding: 30px 0; margin-bottom: 40px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2); }
        .alert-info-custom { border-left: 5px solid var(--primary-color); background-color: #e9f0f4; color: #1f374e; }
        .time-slot label {
            cursor: pointer;
            border: 1px solid #ced4da;
            border-radius: 8px;
            padding: 15px;
            display: block;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s, visibility 0.2s, max-height 0.2s;
            font-weight: 600;
            color: var(--primary-color);
            background-color: var(--card-bg);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }
        .time-slot input[type="radio"]:checked + label {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        .time-slot input[type="radio"] { display: none; }
    </style>
    <link rel="stylesheet" href="fonts.css">
</head>
<body>

<header class="app-header">
    <div class="container text-center">
        <?php if ($is_admin): ?>
            <h1><i class="bi bi-calendar-check me-2"></i> Admin: Availability Management</h1>
            <p class="lead mb-0">Use the dedicated admin tool to set shop and mechanic hours.</p>
        <?php else: ?>
            <h1><i class="bi bi-calendar-check me-2"></i> Confirm Mutual Availability</h1>
            <p class="lead mb-0">Select the best available time slot for **all** chosen mechanics on **<?= $date_display ?>**.</p>
        <?php endif; ?>
    </div>
</header>

<div class="container my-5">
    
    <?php if ($is_admin): ?>
        <div class="alert alert-warning p-4">
            <h4 class="alert-heading"><i class="bi bi-exclamation-triangle-fill me-2"></i> Admin Warning: Wrong Page!</h4>
            <p>You have successfully been directed to **`availability.php`**, but this file is designed for **Customer Time Slot SELECTION**, not for **Admin Hour SETTING**.</p>
            <p>To set new availability, you need to be redirected to the proper management page (e.g., `admin_set_availability.php`).</p>
            <hr>
            <p class="mb-0">Please use the appropriate link on your dashboard for the "Set Availability" **logic**.</p>
            <a href="dashboard_admin.php" class="btn btn-primary mt-3"><i class="bi bi-arrow-left-circle me-2"></i> Back to Dashboard</a>
        </div>

    <?php else: ?>
        <div class="info-box mb-4">
            <h5 class="text-primary mb-3"><i class="bi bi-person-workspace me-2"></i> Selected Mechanics</h5>
            <div class="alert alert-info-custom mb-0 p-3">
                <p class="mb-0 fw-bold">
                    <?= count($selected_mechanic_names) ?> Mechanic(s) selected:
                </p>
                <div class="mechanic-names">
                    **<?= htmlspecialchars($mechanic_list_display) ?>**
                </div>
            </div>
        </div>
        
        <h3 class="mb-4 text-secondary"><i class="bi bi-clock-history me-2"></i> Available Time Slots</h3>

        <form method="post">
            <?php if (empty($shop_slots)): ?>
                <div class="alert alert-warning text-center p-5 rounded-3 border-0 shadow-lg">
                    <i class="bi bi-calendar-x me-1 fs-3 text-warning"></i> 
                    <h5 class="mb-0 mt-3 fw-bold">The shop has not set any working hours for <?= $date_display ?>.</h5>
                    <p class="text-muted mt-2 mb-0">Please check back later or choose a different date.</p>
                </div>
            <?php elseif (empty($available_slots)): ?>
                <div class="alert alert-danger text-center p-5 rounded-3 border-0 shadow-lg">
                    <i class="bi bi-x-octagon-fill me-1 fs-3 text-danger"></i> 
                    <h5 class="mb-0 mt-3 fw-bold">No mutual availability found on <?= $date_display ?>.</h5>
                    <p class="text-muted mt-2 mb-0">All selected mechanics are fully booked during the shop's set hours. Please go back and select different mechanics or a different date.</p>
                </div>
            <?php else: ?>
                <p class="text-muted mb-4">The following slots are available based on shop hours AND mechanic bookings:</p>
                <div class="row row-cols-2 row-cols-md-4 row-cols-lg-6 g-3 time-slot">
                    <?php foreach ($available_slots as $slot): ?>
                        <div class="col">
                            <input 
                                type="radio" 
                                id="time_<?= $slot ?>" 
                                name="final_start_time" 
                                value="<?= $slot ?>" 
                                required>
                            <label for="time_<?= $slot ?>">
                                <?= date('h:i A', strtotime($slot)) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="d-flex justify-content-between mt-5 pt-3 border-top">
                    <a href="select_mechanic.php" class="btn btn-secondary btn-lg"><i class="bi bi-arrow-left-circle me-2"></i> Change Mechanics</a>
                    <button type="submit" class="btn btn-success btn-lg"><i class="bi bi-credit-card-fill me-2"></i> Select Time & Proceed to Payment</button>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>