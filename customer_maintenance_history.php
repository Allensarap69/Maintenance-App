<?php
session_start();
require 'db.php';
require 'motorcycle_health_helper.php';

// Security check - only customers can access their own maintenance history
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    header("Location: index.php");
    exit;
}

$customer_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Customer';
$msg = "";
$msg_type = "";
$active_page = basename($_SERVER['PHP_SELF']);

// Ensure image column exists for per-motorcycle photos
try {
    $pdo->exec("ALTER TABLE motorcycles ADD COLUMN IF NOT EXISTS image VARCHAR(255) NULL");
} catch (PDOException $e) {
    error_log("Motorcycles image column check failed: " . $e->getMessage());
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

/**
 * Fetch service and package names as an HTML list.
 */
function get_service_package_items($pdo, $service_ids_json, $package_ids_json = '[]') {
    $service_ids = json_decode($service_ids_json, true) ?: [];
    $package_ids = json_decode($package_ids_json, true) ?: [];
    $items = [];

    if (is_array($service_ids) && !empty($service_ids)) {
        $placeholders = rtrim(str_repeat('?,', count($service_ids)), ',');
        try {
            $stmt = $pdo->prepare("SELECT service_name AS name, price FROM services WHERE id IN ($placeholders)");
            $stmt->execute($service_ids);
            $items = array_merge($items, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) { error_log("Error fetching services: " . $e->getMessage()); }
    }

    if (is_array($package_ids) && !empty($package_ids)) {
        $placeholders = rtrim(str_repeat('?,', count($package_ids)), ',');
        try {
            $stmt = $pdo->prepare("SELECT package_name AS name, price FROM service_packages WHERE id IN ($placeholders)");
            $stmt->execute($package_ids);
            $items = array_merge($items, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) { error_log("Error fetching packages: " . $e->getMessage()); }
    }

    if (empty($items)) {
        return '<li class="service-package-item">N/A</li>';
    }

    $html = '';
    foreach ($items as $item) {
        $html .= '<li class="service-package-item">' . htmlspecialchars($item['name']) . '</li>';
    }
    return $html;
}

function get_all_mechanic_names($pdo, $booking_id) {
    try {
        $names = [];

        $stmt_ids = $pdo->prepare("SELECT mechanic_id FROM booking_mechanics WHERE booking_id = ?");
        $stmt_ids->execute([$booking_id]);
        $mechanic_ids = array_column($stmt_ids->fetchAll(PDO::FETCH_ASSOC), 'mechanic_id');

        if (!empty($mechanic_ids)) {
            $placeholders = rtrim(str_repeat('?,', count($mechanic_ids)), ',');
            $stmt_names = $pdo->prepare("SELECT name FROM mechanics WHERE id IN ($placeholders)");
            $stmt_names->execute($mechanic_ids);
            $names = array_column($stmt_names->fetchAll(PDO::FETCH_ASSOC), 'name');
        }

        if (empty($names)) {
            $stmt_single = $pdo->prepare("SELECT m.name FROM bookings b LEFT JOIN mechanics m ON b.mechanic_id = m.id WHERE b.id = ? AND b.mechanic_id IS NOT NULL");
            $stmt_single->execute([$booking_id]);
            $single_name = $stmt_single->fetchColumn();
            if ($single_name) {
                return htmlspecialchars($single_name);
            }
        }

        return !empty($names) ? htmlspecialchars(implode(', ', $names)) : 'N/A';

    } catch (PDOException $e) {
        error_log("Error fetching multiple mechanics for booking ID " . $booking_id . ": " . $e->getMessage());
        return "Error fetching mechanics";
    }
}

$pdo->exec("CREATE TABLE IF NOT EXISTS customer_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    motorcycle_id INT NOT NULL,
    health_score INT NOT NULL,
    recommendation TEXT,
    is_read TINYINT(1) DEFAULT 0,
    is_applied TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_motorcycle (motorcycle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// --- 0. Apply Recommended Maintenance ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_recommended'])) {
    $motorcycle_id = (int) ($_POST['motorcycle_id'] ?? 0);
    $notification_id = (int) ($_POST['notification_id'] ?? 0);
    $service_type = in_array($_POST['service_type'] ?? '', ['Oil Change', 'Tire Service', 'Brake Service', 'Engine Service', 'Electrical', 'General Inspection', 'Repair', 'Other']) ? $_POST['service_type'] : 'General Inspection';

    if ($motorcycle_id) {
        try {
            $stmt = $pdo->prepare("SELECT id, current_mileage, user_id FROM motorcycles WHERE id = ? AND user_id = ?");
            $stmt->execute([$motorcycle_id, $customer_id]);
            $motorcycle = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($motorcycle) {
                $mileage = (float) ($motorcycle['current_mileage'] ?? 0);

                $insert = $pdo->prepare("
                    INSERT INTO maintenance_history 
                    (motorcycle_id, customer_id, service_date, mileage, service_type, parts_replaced, cost, mechanic_remarks, performed_by)
                    VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?)
                ");
                $insert->execute([
                    $motorcycle_id, $customer_id, $mileage, $service_type,
                    'As recommended by admin', 0.00,
                    'Applied from admin health score recommendation', 'Customer'
                ]);

                $update = $pdo->prepare("
                    UPDATE motorcycles 
                    SET last_maintenance_date = CURDATE(),
                        last_service_mileage = GREATEST(COALESCE(last_service_mileage, 0), ?),
                        next_maintenance_date = DATE_ADD(CURDATE(), INTERVAL maintenance_interval_months MONTH)
                    WHERE id = ?
                ");
                $update->execute([$mileage, $motorcycle_id]);

                updateMotorcycleHealthScore($motorcycle_id);

                if ($notification_id) {
                    $mark = $pdo->prepare("UPDATE customer_notifications SET is_applied = 1 WHERE id = ? AND customer_id = ?");
                    $mark->execute([$notification_id, $customer_id]);
                }

                $msg = "✅ Maintenance applied. Your motorcycle health score has been recalculated.";
                $msg_type = "success";
            } else {
                $msg = "❌ Motorcycle not found";
                $msg_type = "error";
            }
        } catch (PDOException $e) {
            $msg = "❌ Error applying maintenance: " . $e->getMessage();
            $msg_type = "error";
            error_log("Apply recommended maintenance error: " . $e->getMessage());
        }
    } else {
        $msg = "❌ Invalid maintenance request";
        $msg_type = "error";
    }
}

// --- 1. Add Maintenance Record ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_maintenance'])) {
    $motorcycle_id = $_POST['motorcycle_id'] ?? 0;
    $service_date = $_POST['service_date'] ?? '';
    $mileage = $_POST['mileage'] ?? 0;
    $service_type = $_POST['service_type'] ?? '';
    $parts_replaced = $_POST['parts_replaced'] ?? '';
    $cost = $_POST['cost'] ?? 0.00;
    $mechanic_remarks = $_POST['mechanic_remarks'] ?? '';
    $performed_by = $_POST['performed_by'] ?? '';

    if (empty($motorcycle_id) || empty($service_date) || empty($service_type)) {
        $msg = "❌ Please fill in all required fields.";
        $msg_type = "error";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO maintenance_history 
                (motorcycle_id, customer_id, service_date, mileage, service_type, parts_replaced, cost, mechanic_remarks, performed_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $motorcycle_id, $customer_id, $service_date, $mileage, 
                $service_type, $parts_replaced, $cost, $mechanic_remarks, $performed_by
            ]);

            // Update motorcycle schedule and mileage if this is the most recent service
            $update_mileage = $pdo->prepare("
                UPDATE motorcycles 
                SET current_mileage = ?,
                    last_maintenance_date = ?,
                    last_service_mileage = GREATEST(COALESCE(last_service_mileage, 0), ?),
                    next_maintenance_date = DATE_ADD(?, INTERVAL maintenance_interval_months MONTH)
                WHERE id = ? AND current_mileage < ?
            ");
            $update_mileage->execute([$mileage, $service_date, $mileage, $service_date, $motorcycle_id, $mileage]);

            updateMotorcycleHealthScore($motorcycle_id);

            $msg = "✅ Maintenance record added successfully!";
            $msg_type = "success";
        } catch (PDOException $e) {
            $msg = "❌ Error adding maintenance record: " . $e->getMessage();
            $msg_type = "error";
            error_log("Maintenance record error: " . $e->getMessage());
        }
    }
}

// --- 2. Edit Maintenance Record ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_maintenance'])) {
    $maintenance_id = $_POST['maintenance_id'] ?? 0;
    $service_date = $_POST['service_date'] ?? '';
    $mileage = $_POST['mileage'] ?? 0;
    $service_type = $_POST['service_type'] ?? '';
    $parts_replaced = $_POST['parts_replaced'] ?? '';
    $cost = $_POST['cost'] ?? 0.00;
    $mechanic_remarks = $_POST['mechanic_remarks'] ?? '';
    $performed_by = $_POST['performed_by'] ?? '';

    try {
        $stmt = $pdo->prepare("
            UPDATE maintenance_history 
            SET service_date = ?, mileage = ?, service_type = ?, parts_replaced = ?, 
                cost = ?, mechanic_remarks = ?, performed_by = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND customer_id = ?
        ");
        $stmt->execute([
            $service_date, $mileage, $service_type, $parts_replaced, 
            $cost, $mechanic_remarks, $performed_by, $maintenance_id, $customer_id
        ]);

        $msg = "✅ Maintenance record updated successfully!";
        $msg_type = "success";
    } catch (PDOException $e) {
        $msg = "❌ Error updating maintenance record: " . $e->getMessage();
        $msg_type = "error";
        error_log("Maintenance update error: " . $e->getMessage());
    }
}

// --- 3. Delete Maintenance Record ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_maintenance'])) {
    $maintenance_id = $_POST['maintenance_id'] ?? 0;

    try {
        $stmt = $pdo->prepare("DELETE FROM maintenance_history WHERE id = ? AND customer_id = ?");
        $stmt->execute([$maintenance_id, $customer_id]);

        $msg = "✅ Maintenance record deleted successfully!";
        $msg_type = "success";
    } catch (PDOException $e) {
        $msg = "❌ Error deleting maintenance record: " . $e->getMessage();
        $msg_type = "error";
        error_log("Maintenance deletion error: " . $e->getMessage());
    }
}

// --- Fetch Customer's Motorcycles ---
$motorcycles = [];
try {
    $stmt = $pdo->prepare("SELECT id, brand, model, year_model, plate_number, current_mileage FROM motorcycles WHERE user_id = ? ORDER BY brand ASC");
    $stmt->execute([$customer_id]);
    $motorcycles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching motorcycles: " . $e->getMessage());
}

// --- Fetch Maintenance History ---
$maintenance_records = [];
$selected_motorcycle = $_GET['motorcycle_id'] ?? '';

try {
    if ($selected_motorcycle) {
        $stmt = $pdo->prepare("
            SELECT mh.*, 
                   CONCAT(m.brand, ' ', m.model, ' (', m.plate_number, ')') as motorcycle_info,
                   m.brand AS moto_brand, m.model AS moto_model,
                   m.plate_number AS moto_plate, m.image AS moto_image
            FROM maintenance_history mh
            JOIN motorcycles m ON mh.motorcycle_id = m.id
            WHERE mh.customer_id = ? AND mh.motorcycle_id = ?
            ORDER BY mh.service_date DESC
        ");
        $stmt->execute([$customer_id, $selected_motorcycle]);
    } else {
        $stmt = $pdo->prepare("
            SELECT mh.*, 
                   CONCAT(m.brand, ' ', m.model, ' (', m.plate_number, ')') as motorcycle_info,
                   m.brand AS moto_brand, m.model AS moto_model,
                   m.plate_number AS moto_plate, m.image AS moto_image
            FROM maintenance_history mh
            JOIN motorcycles m ON mh.motorcycle_id = m.id
            WHERE mh.customer_id = ?
            ORDER BY mh.service_date DESC
        ");
        $stmt->execute([$customer_id]);
    }
    $maintenance_records = array_map(function($r) {
        $r['source'] = 'maintenance';
        $r['record_status'] = 'Completed';
        return $r;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
    error_log("Error fetching maintenance history: " . $e->getMessage());
}

// --- Fetch Completed Bookings ---
$completed_records = [];

try {
    $bookingParams = [$customer_id];
    $vehicleFilter = '';
    if ($selected_motorcycle) {
        $vehicleFilter = ' AND b.vehicle_id = ?';
        $bookingParams[] = $selected_motorcycle;
    }
    $stmt = $pdo->prepare("
        SELECT
            b.id,
            b.service_ids,
            b.package_ids,
            b.schedule_date,
            b.schedule_start_time,
            b.schedule_end_time,
            b.total_price,
            b.created_at,
            b.status,
            b.vehicle_id,
            v.brand,
            v.model,
            v.plate_number,
            v.current_mileage,
            v.image AS moto_image,
            p.payment_method,
            p.status AS payment_status,
            p.transaction_ref
        FROM bookings b
        LEFT JOIN motorcycles v ON b.vehicle_id = v.id
        LEFT JOIN payments p ON b.id = p.booking_id
        WHERE b.user_id = ?
          AND b.status IN ('pending','unassigned','assigned','accepted','deposit_submitted','in_progress','completed') $vehicleFilter
          AND NOT EXISTS (
              SELECT 1 FROM maintenance_history mh
              WHERE mh.motorcycle_id = b.vehicle_id
                AND mh.customer_id = b.user_id
                AND mh.service_date = b.schedule_date
          )
        ORDER BY b.schedule_date DESC
    ");
    $stmt->execute($bookingParams);
    $completed_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($completed_bookings as $b) {
        $is_done = ($b['status'] === 'completed');
        $payment_method = strtolower($b['payment_method'] ?? 'N/A');
        $payment_status = $b['payment_status'] ?? 'N/A';
        $transaction_ref = $b['transaction_ref'] ?? 'N/A';
        $payment_text = 'Payment: ' . ucfirst($payment_method);
        if ($payment_method !== 'cash') {
            $payment_text .= ' (' . ucfirst($payment_status) . ')';
        }
        if ($transaction_ref && $transaction_ref !== 'N/A') {
            $payment_text .= ' | Ref: ' . $transaction_ref;
        }

        $scheduled_text = 'Scheduled service appointment';
        if (!empty($b['schedule_start_time'])) {
            $scheduled_text .= ' at ' . date('h:i A', strtotime($b['schedule_start_time']));
        }

        $completed_records[] = [
            'source' => 'booking',
            'id' => $b['id'],
            'motorcycle_id' => $b['vehicle_id'],
            'service_type' => $is_done ? 'Completed' : 'Scheduled',
            'service_date' => $b['schedule_date'],
            'mileage' => $b['current_mileage'] ?? 0,
            'cost' => $b['total_price'] ?? 0,
            'motorcycle_info' => $b['brand'] . ' ' . $b['model'] . ' (' . $b['plate_number'] . ')',
            'moto_brand' => $b['brand'],
            'moto_model' => $b['model'],
            'moto_plate' => $b['plate_number'],
            'moto_image' => $b['moto_image'] ?? null,
            'performed_by' => get_all_mechanic_names($pdo, $b['id']),
            'parts_replaced' => get_service_package_items($pdo, $b['service_ids'], $b['package_ids'] ?? '[]'),
            'mechanic_remarks' => $is_done ? $payment_text : $scheduled_text,
            'record_status' => $is_done ? 'Completed' : 'Pending',
            'created_at' => $b['created_at'],
        ];
    }
} catch (PDOException $e) {
    error_log("Error fetching completed bookings: " . $e->getMessage());
}

// Merge and sort by service date descending
$maintenance_history = array_merge($maintenance_records, $completed_records);
usort($maintenance_history, function($a, $b) {
    return strtotime($b['service_date']) - strtotime($a['service_date']);
});

// --- Apply date/period filter ---
$valid_filters = ['all', 'today', 'week', 'month', 'last_month', 'year'];
$date_filter = in_array($_GET['date_filter'] ?? 'all', $valid_filters) ? ($_GET['date_filter'] ?? 'all') : 'all';
$service_records = $maintenance_history;

if ($date_filter !== 'all') {
    $period_start = null;
    $period_end = null;

    switch ($date_filter) {
        case 'today':
            $period_start = strtotime('today 00:00:00');
            $period_end = strtotime('tomorrow 00:00:00') - 1;
            break;
        case 'week':
            $period_start = strtotime('monday this week 00:00:00');
            break;
        case 'month':
            $period_start = strtotime('first day of this month 00:00:00');
            break;
        case 'last_month':
            $period_start = strtotime('first day of last month 00:00:00');
            $period_end = strtotime('first day of this month 00:00:00') - 1;
            break;
        case 'year':
            $period_start = strtotime('first day of January this year 00:00:00');
            break;
    }

    $service_records = array_filter($maintenance_history, function($r) use ($period_start, $period_end) {
        $ts = strtotime($r['service_date']);
        if ($period_end !== null) {
            return $ts >= $period_start && $ts <= $period_end;
        }
        return $ts >= $period_start;
    });
    $service_records = array_values($service_records);
}

$pageTitle = 'Maintenance History';
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
    <style>
        /* ============================================
           MODERN MAINTENANCE HISTORY DESIGN
           ============================================ */
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            overflow-x: hidden;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-light);
            scroll-behavior: smooth;
            overflow-x: hidden;
            display: flex;
            min-height: 100vh;
        }

        /* ============================================
           NAVIGATION
           ============================================ */
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
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .navbar-brand i {
            background: linear-gradient(135deg, #3b82f6 0%, #EAB308 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .nav-user {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: #1e3a5f;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .btn-logout {
            background: transparent;
            border: 2px solid #e2e8f0;
            color: #1e293b;
            font-weight: 500;
            padding: 8px 20px;
            border-radius: 50px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        }

        @media (hover: hover) and (pointer: fine) {
            .btn-logout:hover {
                background: #dc3545;
                border-color: #dc3545;
                color: white;
                transform: translateY(-2px);
            }
        }

        /* ============================================
           HERO SECTION
           ============================================ */
        .hero-section {
            background: var(--primary-gradient);
            position: relative;
            padding: 60px 0 80px;
            overflow: hidden;
            color: white;
            margin-top: 76px;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 80%, rgba(59, 130, 246, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
            animation: pulse 8s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
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
            opacity: 0.9;
            margin-bottom: 0;
        }

        /* ============================================
           STATS CARDS
           ============================================ */
        .stats-container {
            margin-top: 20px;
            margin-bottom: 16px;
            position: relative;
            z-index: 10;
        }

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

        @media (hover: hover) and (pointer: fine) {
            .stat-card:hover {
                border-color: rgba(0, 0, 0, 0.12);
                transform: translateY(-2px);
            }
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

        .stat-card > div:last-child {
            min-width: 0;
            flex: 1;
        }

        .stat-icon-primary { background: rgba(59, 130, 246, 0.12); color: var(--info-color); }
        .stat-icon-success { background: rgba(16, 185, 129, 0.12); color: var(--success-color); }
        .stat-icon-warning { background: rgba(250, 204, 21, 0.12); color: #EAB308; }
        .stat-icon-info { background: rgba(139, 92, 246, 0.12); color: #8b5cf6; }

        .stat-value { font-size: 1.6rem; font-weight: 800; color: var(--text-dark); line-height: 1.1; word-break: break-word; }
        .stat-label { font-size: 0.78rem; font-weight: 600; color: var(--text-light); margin-top: 2px; word-break: break-word; }

        /* ============================================
           MAINTENANCE CARDS
           ============================================ */
        .emergency-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            overflow: hidden;
        }

        @media (hover: hover) and (pointer: fine) {
            .emergency-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12);
                border-color: var(--accent-color);
            }
        }

        .emergency-header {
            padding: 18px 22px;
            color: white;
            background: var(--primary-gradient);
        }

        .emergency-header h5 { font-size: 1rem; margin-bottom: 2px; }
        .emergency-header small { font-size: 0.75rem; }
        .emergency-header .priority-badge {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.4);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            font-size: 0.7rem;
            letter-spacing: 0.05em;
            padding: 5px 12px;
        }

        .emergency-card .card-body { padding: 20px; }
        .emergency-card .text-muted.small { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-light); }
        .emergency-card .fw-bold { font-size: 0.9rem; color: var(--text-dark); }
        .emergency-card .contact-info strong { font-size: 0.75rem; color: var(--text-dark); }
        .emergency-card .contact-info p { font-size: 0.8rem; color: var(--text-light); }
        .emergency-card .btn-sm { font-size: 0.75rem; border-radius: 8px; padding: 8px 16px; font-weight: 600; }

        .emergency-card .card-body > .row:first-of-type .col-6 > div,
        .emergency-card .card-body > .row:first-of-type .col-md-3 > div {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
        }

        @media (hover: hover) and (pointer: fine) {
            .emergency-card .card-body > .row:first-of-type .col-6:hover > div,
            .emergency-card .card-body > .row:first-of-type .col-md-3:hover > div {
                border-color: var(--accent-color);
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            }
        }

        .emergency-card .contact-info {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            border-left: 4px solid var(--accent-color);
            height: 100%;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
        }

        .emergency-card .contact-info strong {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .emergency-card .contact-info i {
            color: var(--accent-color);
        }

        .emergency-header.oil_change { background: linear-gradient(135deg, var(--info-color) 0%, #2563eb 100%); }
        .emergency-header.tire_service { background: var(--accent-gradient); }
        .emergency-header.brake_service { background: var(--primary-gradient); }
        .emergency-header.engine_service { background: linear-gradient(135deg, var(--success-color) 0%, #059669 100%); }
        .emergency-header.electrical { background: linear-gradient(135deg, #64748b 0%, #475569 100%); }
        .emergency-header.general_inspection { background: linear-gradient(135deg, var(--info-color) 0%, #2563eb 100%); }
        .emergency-header.repair { background: linear-gradient(135deg, #f43f5e 0%, #be123c 100%); }
        .emergency-header.other { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); }

        .priority-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-urgent { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
        .priority-high { background: rgba(250, 204, 21, 0.1); color: #EAB308; }
        .priority-medium { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        .priority-low { background: rgba(16, 185, 129, 0.1); color: #10b981; }

        .contact-info {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.05) 0%, rgba(255, 255, 255, 0.95) 100%);
            padding: 15px;
            border-radius: 12px;
            border-left: 4px solid var(--accent-color);
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            background: transparent;
            border: none;
            box-shadow: none;
            padding: 0;
        }

        .filter-control {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 50px;
            padding: 5px 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
        }

        .filter-control:hover {
            border-color: var(--accent-color);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
        }

        .filter-control .filter-icon {
            color: var(--accent-color);
            font-size: 0.9rem;
        }

        .filter-control .form-select {
            border: none;
            background: transparent;
            padding: 0 20px 0 0;
            font-weight: 500;
            font-size: 0.8rem;
            color: var(--text-dark);
            min-width: 140px;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0 center;
            background-size: 12px;
        }

        .filter-control .form-select:focus {
            outline: none;
            box-shadow: none;
        }

        .filter-bar .form-label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0;
        }

        .filter-bar .btn-primary {
            border-radius: 12px;
            padding: 10px 24px;
            font-weight: 600;
        }

        .info-label {
            font-size: 0.85rem;
            color: var(--text-light);
            font-weight: 500;
        }

        .info-value {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0;
        }

        .records-header small {
            display: block;
            line-height: 1.4;
        }

        /* ============================================
           EMPTY STATE
           ============================================ */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 20px;
            border: 2px dashed #e2e8f0;
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--text-light);
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .top-bar-user {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .top-bar-user-avatar {
            width: 40px;
            height: 40px;
            background: #1e3a5f;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }

        .top-bar-user-info {
            display: flex;
            flex-direction: column;
        }

        .top-bar-user-name {
            color: white;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .top-bar-user-role {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.75rem;
        }

        .empty-state h4 {
            color: var(--text-dark);
            margin-bottom: 10px;
        }

        .empty-state p {
            color: var(--text-light);
            margin-bottom: 0;
        }

        /* ============================================
           RESPONSIVE DESIGN
           ============================================ */
        @media (max-width: 992px) {
            .top-bar {
                padding: 12px 20px;
            }

            .top-bar-title {
                font-size: 1.1rem;
            }

            .top-bar-user-info {
                display: none;
            }

            .content-area {
                padding: 20px;
            }
        }

        @media (max-width: 768px) {
            .hero-section {
                padding: 40px 0 60px;
            }

            .stats-container {
                margin-top: -30px;
            }

            .stat-card {
                padding: 20px;
                margin-bottom: 15px;
            }

            .stat-value {
                font-size: 2rem;
            }

            .cost-display {
                font-size: 1.5rem;
            }

            .top-bar {
                padding: 10px 15px;
            }

            .top-bar-title {
                font-size: 1rem;
            }

            .content-area {
                padding: 15px;
            }
        }

        @media (max-width: 576px) {
            .top-bar {
                padding: 8px 12px;
            }

            .top-bar-title {
                font-size: 0.95rem;
            }

            .sidebar-toggle {
                padding: 6px 10px;
                font-size: 1.3rem;
            }

            .content-area {
                padding: 12px;
            }
        }

        /* ============================================
           SERVICE RECORD CARDS (matches my_bookings)
           ============================================ */
        .service-record-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            display: flex;
            align-items: stretch;
            height: 100%;
        }

        @media (hover: hover) and (pointer: fine) {
            .service-record-card:hover {
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
                transform: translateY(-2px);
            }
        }

        .service-record-number {
            background: var(--primary-gradient);
            color: #fff;
            font-weight: 700;
            font-size: 0.72rem;
            padding: 4px 12px;
            border-radius: 50px;
            word-break: break-word;
            align-self: flex-start;
        }

        .sr-media {
            width: 220px;
            flex-shrink: 0;
            background: #f8fafc;
            border-right: 1px solid #f1f5f9;
            padding: 16px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .sr-media-img {
            width: 100%;
            height: 110px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            overflow: hidden;
        }

        .sr-media-img img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            mix-blend-mode: multiply;
        }

        .sr-media-name {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-dark);
            text-align: center;
            word-break: break-word;
        }

        .sr-plate {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.68rem;
            font-weight: 600;
            color: var(--text-light);
            background: #fff;
            border: 1px solid #e2e8f0;
            padding: 3px 10px;
            border-radius: 50px;
        }

        .service-record-body {
            padding: 18px 20px;
            flex: 1;
            display: flex;
            gap: 24px;
            min-width: 0;
        }

        .sr-actions {
            width: 190px;
            flex-shrink: 0;
            padding: 18px 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: stretch;
            border-left: 1px solid #f1f5f9;
        }

        .sr-status {
            align-self: center;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 0.72rem;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .sr-status-completed {
            background: rgba(16, 185, 129, 0.12);
            color: #059669;
        }

        .sr-status-pending {
            background: rgba(250, 204, 21, 0.18);
            color: #b45309;
        }

        .sr-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 9px 8px;
            border-radius: 10px;
            font-size: 0.74rem;
            font-weight: 600;
            border: 1px solid #cbd5e1;
            background: #fff;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
            white-space: nowrap;
        }

        .sr-btn-view {
            border-color: #3b82f6;
            color: #3b82f6;
        }

        .sr-btn-view:hover {
            background: rgba(59, 130, 246, 0.06);
            color: #2563eb;
        }

        .sr-btn-report {
            border-color: #dbe2ea;
            color: #64748b;
        }

        .sr-btn-report:hover {
            border-color: #94a3b8;
            color: #334155;
            background: #f8fafc;
        }

        .service-record-col {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .service-record-section {
            margin-bottom: 12px;
            break-inside: avoid;
        }

        .service-record-section:last-child {
            margin-bottom: 0;
        }

        .record-section-title {
            color: var(--accent-color);
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.35rem;
            display: flex;
            align-items: center;
        }

        .record-section-title i {
            font-size: 1rem;
            margin-right: 6px;
        }

        .service-record-section p,
        .service-record-section .record-value {
            font-size: 0.75rem;
            color: var(--text-dark);
            margin-bottom: 2px;
            word-break: break-word;
        }

        .service-record-section .text-muted {
            font-size: 0.7rem;
            color: var(--text-light);
        }

        .service-record-section .price-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--success-color);
            word-break: break-word;
        }

        .service-record-section ul {
            margin: 0;
            padding-left: 1.1rem;
            font-size: 0.72rem;
            color: var(--text-dark);
        }

        .service-record-section ul li {
            margin-bottom: 0.25rem;
        }

        .service-records-list {
            min-height: 300px;
            max-height: calc(100vh - 320px);
            overflow-y: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
            margin-top: 16px;
        }

        .service-records-list::-webkit-scrollbar {
            display: none;
        }

        @media (max-width: 576px) {
            .stat-card {
                padding: 10px;
                gap: 6px;
            }
            .stat-icon {
                width: 32px;
                height: 32px;
                font-size: 0.9rem;
                border-radius: 10px;
            }
            .stat-value {
                font-size: 0.95rem;
            }
            .stat-label {
                font-size: 0.65rem;
            }

            .service-record-card {
                border-radius: 12px;
            }
            .service-record-number {
                font-size: 0.62rem;
            }
            .sr-media {
                padding: 12px;
                gap: 8px;
            }
            .sr-media-img {
                height: 90px;
            }
            .sr-media-name {
                font-size: 0.72rem;
            }
            .sr-plate {
                font-size: 0.62rem;
            }
            .sr-actions {
                padding: 12px;
            }
            .sr-status {
                font-size: 0.65rem;
                padding: 5px 12px;
            }
            .sr-btn {
                font-size: 0.68rem;
                padding: 7px 10px;
            }
            .service-record-body {
                padding: 12px;
                gap: 12px;
            }
            .record-section-title {
                font-size: 0.58rem;
            }
            .service-record-section p,
            .service-record-section .record-value {
                font-size: 0.7rem;
            }
            .service-record-section .price-value {
                font-size: 1rem;
            }
            .service-record-section ul {
                font-size: 0.68rem;
            }
        }

        @media (max-width: 576px) {
            .records-header.d-flex {
                flex-direction: column !important;
                align-items: flex-start !important;
            }
            .records-title {
                margin-bottom: 8px;
            }
            .filter-group.d-flex {
                justify-content: space-between !important;
            }
            .filter-group .filter-control {
                flex: 1;
                min-width: 0;
            }
            .filter-group .filter-control .form-select {
                width: 100%;
                min-width: 0;
            }
            .section-title {
                font-size: 1.1rem;
            }
        }

        @media (max-width: 992px) {
            .service-record-card {
                flex-wrap: wrap;
            }
            .sr-media {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid #f1f5f9;
                flex-direction: row;
                flex-wrap: wrap;
                align-items: center;
                justify-content: center;
            }
            .sr-media-img {
                width: 160px;
                flex-shrink: 0;
            }
            .sr-media-name {
                text-align: left;
                flex: 1;
                min-width: 120px;
            }
            .sr-actions {
                width: 100%;
                border-left: none;
                border-top: 1px solid #f1f5f9;
                flex-direction: row;
                align-items: center;
                flex-wrap: wrap;
            }
            .sr-btn {
                flex: 1;
                min-width: 140px;
            }
        }

        @media (max-width: 768px) {
            .service-record-body {
                flex-direction: column;
                gap: 10px;
            }
        }
    /* --- Match customer_health_score layout and stat-card sizing --- */
    .main-content > .content-area {
        max-width: 1400px;
        width: 100%;
        margin: 0 auto;
    }

    .content-area .container {
        max-width: 1400px;
        padding-left: 0;
        padding-right: 0;
    }

    .stat-card {
        border-radius: 12px;
        padding: 10px 12px;
        gap: 8px;
        margin-bottom: 0;
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

<!-- Main Content Area -->
<div class="main-content">
    <!-- Top Bar -->
    <div class="top-bar">
        <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="bi bi-list"></i>
            </button>
            <h1 class="top-bar-title">Maintenance History</h1>
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
    
    <!-- Content Area -->
    <div class="content-area">
        <!-- Overview Summary Row -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-primary"><i class="bi bi-currency-dollar"></i></div>
                    <div>
                        <div class="stat-value">
                            ₱<?= number_format(array_sum(array_map(function($r) {
                                return ($r['record_status'] ?? 'Completed') === 'Completed' ? (float) $r['cost'] : 0;
                            }, $maintenance_history)), 2) ?>
                        </div>
                        <div class="stat-label">Total Spent</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-success"><i class="bi bi-speedometer2"></i></div>
                    <div>
                        <div class="stat-value">
                            <?= !empty($maintenance_history) ? max(array_column($maintenance_history, 'mileage')) : 0 ?>
                        </div>
                        <div class="stat-label">Highest Mileage</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-warning"><i class="bi bi-calendar-check"></i></div>
                    <div>
                        <div class="stat-value">
                            <?= !empty($maintenance_history) ? count(array_filter($maintenance_history, function($m) { 
                                return ($m['record_status'] ?? 'Completed') === 'Completed'
                                    && (strtotime($m['service_date']) > strtotime('-1 year')); 
                            })) : 0 ?>
                        </div>
                        <div class="stat-label">Services This Year</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-info"><i class="bi bi-motorcycle"></i></div>
                    <div>
                        <div class="stat-value">
                            <?= !empty($maintenance_history) ? count($motorcycles) : 0 ?>
                        </div>
                        <div class="stat-label">Active Motorcycles</div>
                    </div>
                </div>
            </div>
        </div>

<!-- Main Content -->
<div class="container py-5">

    <!-- Filter controls are in the header -->

    <!-- Maintenance History Cards -->
    <div class="row">
        <div class="col-12 mb-3">
            <?php
            $filter_label = match($date_filter) {
                'today' => 'Today',
                'week' => 'This Week',
                'month' => 'This Month',
                'last_month' => 'Last Month',
                'year' => 'This Year',
                default => 'All Time'
            };
            if ($selected_motorcycle) {
                $filtered = array_values(array_filter($motorcycles, fn($m) => $m['id'] == $selected_motorcycle));
                $subtitle = $filtered ? 'Showing records for ' . htmlspecialchars($filtered[0]['brand'] . ' ' . $filtered[0]['model'] . ' (' . $filtered[0]['plate_number'] . ')') : 'Showing records for selected motorcycle';
            } else {
                $subtitle = 'Showing records for all motorcycles';
            }
            ?>
            <div class="d-flex justify-content-between align-items-center records-header">
                <div class="records-title">
                    <h3 class="section-title mb-0"><i class="bi bi-clock-history me-2"></i>Service Records</h3>
                    <small class="text-muted"><?= $subtitle ?> · <?= $filter_label ?></small>
                </div>
                <div class="d-flex align-items-center gap-2 filter-group">
                    <div class="filter-control">
                        <i class="bi bi-motorcycle filter-icon"></i>
                        <select class="form-select" onchange="window.location.href='?motorcycle_id='+this.value+'&date_filter=<?= urlencode($date_filter) ?>'">
                            <option value="">All Motorcycles</option>
                            <?php foreach ($motorcycles as $moto): ?>
                                <option value="<?= $moto['id'] ?>" <?= $selected_motorcycle == $moto['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($moto['brand'] . ' ' . $moto['model'] . ' (' . $moto['plate_number'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-control">
                        <i class="bi bi-calendar3 filter-icon"></i>
                        <select class="form-select" onchange="window.location.href='?date_filter='+this.value+'&motorcycle_id=<?= urlencode($selected_motorcycle) ?>'">
                            <option value="all" <?= $date_filter === 'all' ? 'selected' : '' ?>>All Time</option>
                            <option value="today" <?= $date_filter === 'today' ? 'selected' : '' ?>>Today</option>
                            <option value="week" <?= $date_filter === 'week' ? 'selected' : '' ?>>This Week</option>
                            <option value="month" <?= $date_filter === 'month' ? 'selected' : '' ?>>This Month</option>
                            <option value="last_month" <?= $date_filter === 'last_month' ? 'selected' : '' ?>>Last Month</option>
                            <option value="year" <?= $date_filter === 'year' ? 'selected' : '' ?>>This Year</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="service-records-list">
        <div class="row">
        
        <?php if (empty($service_records)): ?>
            <div class="col-12">
                <div class="empty-state">
                    <i class="bi bi-tools"></i>
                    <h4>No Maintenance Records Found</h4>
                    <p>Start tracking your motorcycle maintenance by adding your first service record.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($service_records as $record): ?>
                <?php
                $is_booking = ($record['source'] ?? 'maintenance') === 'booking';
                $parts = $is_booking ? [] : array_filter(array_map('trim', explode(',', $record['parts_replaced'] ?? '')));
                $record_status = $record['record_status'] ?? 'Completed';
                $is_completed = $record_status === 'Completed';
                $modal_id = 'recordDetailsModal_' . ($is_booking ? 'booking' : 'maintenance') . '_' . $record['id'];
                $moto_img = !empty($record['moto_image'])
                    ? $record['moto_image']
                    : ($modelImages[$record['moto_model'] ?? ''] ?? 'MOTOR.jpg');
                $moto_plate = $record['moto_plate'] ?? '';
                ?>
                <div class="col-12 mb-3">
                    <div class="service-record-card">
                        <div class="sr-media">
                            <span class="service-record-number">Service #<?= $record['id'] ?></span>
                            <div class="sr-media-img">
                                <img src="<?= htmlspecialchars($moto_img) ?>"
                                     alt="<?= htmlspecialchars($record['motorcycle_info']) ?>"
                                     onerror="this.onerror=null;this.src='MOTOR.jpg';">
                            </div>
                            <div class="sr-media-name"><?= htmlspecialchars($record['motorcycle_info']) ?></div>
                            <?php if ($moto_plate): ?>
                            <span class="sr-plate"><i class="bi bi-motorcycle"></i><?= htmlspecialchars($moto_plate) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="service-record-body">
                            <div class="service-record-col left-col">
                                <div class="service-record-section">
                                    <h6 class="record-section-title"><i class="bi bi-calendar-event"></i>Appointment</h6>
                                    <p class="mb-1"><span class="text-muted">Date:</span> <?= date('F j, Y', strtotime($record['service_date'])) ?></p>
                                    <p class="mb-1"><span class="text-muted">Mileage:</span> <?= number_format($record['mileage']) ?> km</p>
                                </div>
                                <div class="service-record-section">
                                    <h6 class="record-section-title"><i class="bi bi-wrench"></i>Vehicle & Services</h6>
                                    <p class="mb-1 text-break"><span class="text-muted">Vehicle:</span> <?= htmlspecialchars($record['motorcycle_info']) ?></p>
                                    <?php if ($record['parts_replaced']): ?>
                                        <?php if ($is_booking): ?>
                                        <ul class="service-package-list"><?= $record['parts_replaced'] ?></ul>
                                        <?php else: ?>
                                        <ul>
                                            <?php foreach ($parts as $part): ?>
                                                <?php if ($part): ?><li><?= htmlspecialchars($part) ?></li><?php endif; ?>
                                            <?php endforeach; ?>
                                        </ul>
                                        <?php endif; ?>
                                    <?php else: ?>
                                    <p class="mb-0 text-muted">—</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="service-record-col right-col">
                                <div class="service-record-section">
                                    <h6 class="record-section-title"><i class="bi bi-person"></i>Personnel</h6>
                                    <p class="mb-1"><?= $record['performed_by'] ? htmlspecialchars($record['performed_by']) : '—' ?></p>
                                </div>
                                <div class="service-record-section">
                                    <h6 class="record-section-title"><i class="bi bi-cash-stack"></i>Estimated Price</h6>
                                    <div class="price-value">₱<?= number_format($record['cost'], 2) ?></div>
                                </div>
                                <div class="service-record-section">
                                    <h6 class="record-section-title"><i class="bi bi-chat-left-text"></i>Remarks</h6>
                                    <p class="mb-0"><?= $record['mechanic_remarks'] ? htmlspecialchars($record['mechanic_remarks']) : '—' ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="sr-actions">
                            <span class="sr-status <?= $is_completed ? 'sr-status-completed' : 'sr-status-pending' ?>">
                                <i class="bi <?= $is_completed ? 'bi-check-circle-fill' : 'bi-clock-fill' ?>"></i>
                                <?= $record_status ?>
                            </span>
                            <button type="button" class="sr-btn sr-btn-view"
                                    data-bs-toggle="modal" data-bs-target="#<?= $modal_id ?>">
                                <i class="bi bi-pencil"></i>View Details
                            </button>
                            <?php if ($is_booking): ?>
                            <a href="print_receipt.php?booking_id=<?= (int) $record['id'] ?>" target="_blank" class="sr-btn sr-btn-report">
                                <i class="bi bi-file-earmark-text"></i>Download Report
                            </a>
                            <?php else: ?>
                            <a href="print_maintenance.php?maintenance_id=<?= (int) $record['id'] ?>" target="_blank" class="sr-btn sr-btn-report">
                                <i class="bi bi-file-earmark-text"></i>Download Report
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- Add Maintenance Modal -->
<div class="modal fade" id="addMaintenanceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--primary-gradient); color: white; border-radius: 20px 20px 0 0;">
                <h5 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Add Maintenance Record</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="add_maintenance" value="1">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Motorcycle *</label>
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
                            <label class="form-label">Service Date *</label>
                            <input type="date" class="form-control" name="service_date" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Mileage (km) *</label>
                            <input type="number" class="form-control" name="mileage" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Service Type *</label>
                            <select class="form-select" name="service_type" required>
                                <option value="">Select Type</option>
                                <option value="Oil Change">Oil Change</option>
                                <option value="Tire Service">Tire Service</option>
                                <option value="Brake Service">Brake Service</option>
                                <option value="Engine Service">Engine Service</option>
                                <option value="Electrical">Electrical</option>
                                <option value="General Inspection">General Inspection</option>
                                <option value="Repair">Repair</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                            <label class="form-label">Cost (₱)</label>
                            <input type="number" step="0.01" class="form-control" name="cost" value="0.00">
                        </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Parts Replaced</label>
                        <textarea class="form-control" name="parts_replaced" rows="2" placeholder="List any parts that were replaced..."></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Mechanic Remarks</label>
                        <textarea class="form-control" name="mechanic_remarks" rows="3" placeholder="Any notes from the mechanic..."></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Performed By</label>
                        <input type="text" class="form-control" name="performed_by" placeholder="Mechanic name or shop...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Maintenance Modals -->
<?php foreach ($maintenance_records as $record): ?>
<div class="modal fade" id="editMaintenanceModal<?= $record['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--primary-gradient); color: white; border-radius: 20px 20px 0 0;">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Maintenance Record</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="edit_maintenance" value="1">
                    <input type="hidden" name="maintenance_id" value="<?= $record['id'] ?>">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Service Date *</label>
                            <input type="date" class="form-control" name="service_date" value="<?= $record['service_date'] ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Mileage (km) *</label>
                            <input type="number" class="form-control" name="mileage" value="<?= $record['mileage'] ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Service Type *</label>
                            <select class="form-select" name="service_type" required>
                                <option value="">Select Type</option>
                                <option value="Oil Change" <?= $record['service_type'] == 'Oil Change' ? 'selected' : '' ?>>Oil Change</option>
                                <option value="Tire Service" <?= $record['service_type'] == 'Tire Service' ? 'selected' : '' ?>>Tire Service</option>
                                <option value="Brake Service" <?= $record['service_type'] == 'Brake Service' ? 'selected' : '' ?>>Brake Service</option>
                                <option value="Engine Service" <?= $record['service_type'] == 'Engine Service' ? 'selected' : '' ?>>Engine Service</option>
                                <option value="Electrical" <?= $record['service_type'] == 'Electrical' ? 'selected' : '' ?>>Electrical</option>
                                <option value="General Inspection" <?= $record['service_type'] == 'General Inspection' ? 'selected' : '' ?>>General Inspection</option>
                                <option value="Repair" <?= $record['service_type'] == 'Repair' ? 'selected' : '' ?>>Repair</option>
                                <option value="Other" <?= $record['service_type'] == 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Cost (₱)</label>
                            <input type="number" step="0.01" class="form-control" name="cost" value="<?= $record['cost'] ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Parts Replaced</label>
                        <textarea class="form-control" name="parts_replaced" rows="2"><?= htmlspecialchars($record['parts_replaced']) ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Mechanic Remarks</label>
                        <textarea class="form-control" name="mechanic_remarks" rows="3"><?= htmlspecialchars($record['mechanic_remarks']) ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Performed By</label>
                        <input type="text" class="form-control" name="performed_by" value="<?= htmlspecialchars($record['performed_by']) ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Record</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Record Details Modals -->
<?php foreach ($service_records as $record): ?>
    <?php
    $is_booking = ($record['source'] ?? 'maintenance') === 'booking';
    $record_status = $record['record_status'] ?? 'Completed';
    $is_completed = $record_status === 'Completed';
    $modal_id = 'recordDetailsModal_' . ($is_booking ? 'booking' : 'maintenance') . '_' . $record['id'];
    $detail_parts = $is_booking ? [] : array_filter(array_map('trim', explode(',', $record['parts_replaced'] ?? '')));
    ?>
<div class="modal fade" id="<?= $modal_id ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--primary-gradient); color: white; border-radius: 20px 20px 0 0;">
                <h5 class="modal-title"><i class="bi bi-clipboard-data me-2"></i>Service #<?= $record['id'] ?> Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <small class="text-muted d-block">Status</small>
                        <span class="sr-status <?= $is_completed ? 'sr-status-completed' : 'sr-status-pending' ?>">
                            <i class="bi <?= $is_completed ? 'bi-check-circle-fill' : 'bi-clock-fill' ?>"></i>
                            <?= $record_status ?>
                        </span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Vehicle</small>
                        <strong><?= htmlspecialchars($record['motorcycle_info']) ?></strong>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Service Date</small>
                        <strong><?= date('F j, Y', strtotime($record['service_date'])) ?></strong>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Mileage</small>
                        <strong><?= number_format($record['mileage']) ?> km</strong>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Service Type</small>
                        <strong><?= htmlspecialchars($record['service_type']) ?></strong>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Personnel</small>
                        <strong><?= $record['performed_by'] ? htmlspecialchars($record['performed_by']) : '—' ?></strong>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Estimated Price</small>
                        <strong class="text-success">₱<?= number_format($record['cost'], 2) ?></strong>
                    </div>
                    <div class="col-12">
                        <small class="text-muted d-block">Services / Parts</small>
                        <?php if ($is_booking): ?>
                            <ul class="mb-0"><?= $record['parts_replaced'] ?></ul>
                        <?php elseif (!empty($detail_parts)): ?>
                            <ul class="mb-0">
                                <?php foreach ($detail_parts as $part): ?>
                                    <li><?= htmlspecialchars($part) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <span>—</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-12">
                        <small class="text-muted d-block">Remarks</small>
                        <p class="mb-0"><?= $record['mechanic_remarks'] ? htmlspecialchars($record['mechanic_remarks']) : '—' ?></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <?php if ($is_booking): ?>
                    <a href="my_bookings.php" class="btn btn-outline-primary"><i class="bi bi-calendar-check me-1"></i>View Booking</a>
                <?php else: ?>
                    <button type="button" class="btn btn-primary" onclick="openEditModal(<?= (int) $record['id'] ?>)">
                        <i class="bi bi-pencil me-1"></i>Edit
                    </button>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="delete_maintenance" value="1">
                        <input type="hidden" name="maintenance_id" value="<?= $record['id'] ?>">
                        <button type="submit" class="btn btn-outline-danger"
                                onclick="return confirm('Are you sure you want to delete this maintenance record?')">
                            <i class="bi bi-trash me-1"></i>Delete
                        </button>
                    </form>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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

    function openEditModal(id) {
        const detailsEl = document.getElementById('recordDetailsModal_maintenance_' + id);
        const detailsInst = detailsEl ? bootstrap.Modal.getInstance(detailsEl) : null;
        if (detailsInst) detailsInst.hide();
        const editEl = document.getElementById('editMaintenanceModal' + id);
        if (editEl) bootstrap.Modal.getOrCreateInstance(editEl).show();
    }

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
