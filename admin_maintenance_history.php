<?php
session_start();
require 'db.php';
require 'motorcycle_health_helper.php';

// Security check: Only admins can access this page
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$msg = "";
$msg_type = "";

// --- 1. Add Maintenance Record ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_maintenance'])) {
    $customer_id = $_POST['customer_id'] ?? 0;
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
            WHERE id = ?
        ");
        $stmt->execute([
            $service_date, $mileage, $service_type, $parts_replaced, 
            $cost, $mechanic_remarks, $performed_by, $maintenance_id
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
        $stmt = $pdo->prepare("DELETE FROM maintenance_history WHERE id = ?");
        $stmt->execute([$maintenance_id]);

        $msg = "✅ Maintenance record deleted successfully!";
        $msg_type = "success";
    } catch (PDOException $e) {
        $msg = "❌ Error deleting maintenance record: " . $e->getMessage();
        $msg_type = "error";
        error_log("Maintenance deletion error: " . $e->getMessage());
    }
}

// --- Fetch Data for Dropdowns ---
$customers = [];
$motorcycles = [];
$mechanics = [];
try {
    $customers = $pdo->query("SELECT id, username as name FROM users WHERE role='customer' ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
    $motorcycles = $pdo->query("SELECT id, brand, model, year_model, plate_number, user_id FROM motorcycles ORDER BY brand ASC")->fetchAll(PDO::FETCH_ASSOC);
    $mechanics = $pdo->query("SELECT name FROM mechanics ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching dropdown data: " . $e->getMessage());
}

// --- Helpers for completed booking records ---
function get_admin_booking_item_names($pdo, $service_ids_json, $package_ids_json) {
    $names = [];
    $service_ids = json_decode($service_ids_json, true) ?: [];
    $package_ids = json_decode($package_ids_json, true) ?: [];
    if (!empty($service_ids)) {
        $placeholders = rtrim(str_repeat('?,', count($service_ids)), ',');
        $stmt = $pdo->prepare("SELECT service_name FROM services WHERE id IN ($placeholders)");
        $stmt->execute($service_ids);
        $names = array_merge($names, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!empty($package_ids)) {
        $placeholders = rtrim(str_repeat('?,', count($package_ids)), ',');
        $stmt = $pdo->prepare("SELECT package_name FROM service_packages WHERE id IN ($placeholders)");
        $stmt->execute($package_ids);
        $names = array_merge($names, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    return $names;
}

function get_admin_booking_mechanics($pdo, $booking_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT m.name 
            FROM booking_mechanics bm 
            JOIN mechanics m ON bm.mechanic_id = m.id 
            WHERE bm.booking_id = ?
        ");
        $stmt->execute([$booking_id]);
        $names = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!empty($names)) {
            return implode(', ', $names);
        }
        $stmt = $pdo->prepare("SELECT m.name FROM bookings b LEFT JOIN mechanics m ON b.mechanic_id = m.id WHERE b.id = ? AND b.mechanic_id IS NOT NULL");
        $stmt->execute([$booking_id]);
        $single = $stmt->fetchColumn();
        return $single ? $single : 'AutoCare Pro';
    } catch (PDOException $e) {
        return 'AutoCare Pro';
    }
}

// --- UI helpers for the list view ---
function get_initials($name, $limit = 2) {
    if (empty($name)) return '?';
    $parts = preg_split('/\s+/', trim($name), 0, PREG_SPLIT_NO_EMPTY);
    if (count($parts) === 1) {
        return strtoupper(substr($parts[0], 0, $limit));
    }
    $initials = '';
    $max = min($limit, count($parts));
    for ($i = 0; $i < $max; $i++) {
        $initials .= strtoupper(substr($parts[$i], 0, 1));
    }
    return $initials;
}

function get_avatar_color($name) {
    $colors = ['#6366f1', '#8b5cf6', '#ec4899', '#f97316', '#10b981', '#3b82f6', '#14b8a6', '#ef4444'];
    return $colors[abs(crc32($name)) % count($colors)];
}

// --- Fetch Maintenance Records ---
$maintenance_history = [];
$maintenance_records = [];
$completed_records = [];
$selected_customer = $_GET['customer_id'] ?? '';
$selected_motorcycle = $_GET['motorcycle_id'] ?? '';
$prefill_mileage = isset($_GET['mileage']) && is_numeric($_GET['mileage']) ? (int) $_GET['mileage'] : 0;
$open_add_modal = isset($_GET['open_add']) && $selected_customer && $selected_motorcycle;

try {
    $mhParams = [];
    $mhWhere = 'WHERE 1=1';
    if ($selected_customer) { $mhWhere .= ' AND mh.customer_id = ?'; $mhParams[] = $selected_customer; }
    if ($selected_motorcycle) { $mhWhere .= ' AND mh.motorcycle_id = ?'; $mhParams[] = $selected_motorcycle; }

    $stmt = $pdo->prepare("
        SELECT mh.*, 'maintenance' AS source,
               CONCAT(m.brand, ' ', m.model, ' (', m.plate_number, ')') as motorcycle_info,
               c.username as customer_name,
               (SELECT m2.name 
                FROM bookings b2 
                LEFT JOIN mechanics m2 ON b2.mechanic_id = m2.id 
                WHERE b2.user_id = mh.customer_id 
                  AND b2.vehicle_id = mh.motorcycle_id 
                  AND m2.name IS NOT NULL 
                ORDER BY b2.schedule_date DESC, b2.id DESC 
                LIMIT 1) as mechanic_name
        FROM maintenance_history mh
        JOIN motorcycles m ON mh.motorcycle_id = m.id
        JOIN users c ON mh.customer_id = c.id
        $mhWhere
        ORDER BY mh.service_date DESC
    ");
    $stmt->execute($mhParams);
    $maintenance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching maintenance history: " . $e->getMessage());
}

// --- Fetch Completed Bookings ---
try {
    $bParams = ['completed'];
    $bWhere = "WHERE b.status = ?";
    if ($selected_customer) { $bWhere .= " AND b.user_id = ?"; $bParams[] = $selected_customer; }
    if ($selected_motorcycle) { $bWhere .= " AND b.vehicle_id = ?"; $bParams[] = $selected_motorcycle; }

    $stmt = $pdo->prepare("
        SELECT b.id, b.user_id, b.vehicle_id, b.schedule_date, b.service_ids, b.package_ids, b.total_price,
               m.current_mileage,
               c.username as customer_name,
               CONCAT(m.brand, ' ', m.model, ' (', m.plate_number, ')') as motorcycle_info
        FROM bookings b
        JOIN motorcycles m ON b.vehicle_id = m.id
        JOIN users c ON b.user_id = c.id
        $bWhere
          AND NOT EXISTS (
              SELECT 1 FROM maintenance_history mh
              WHERE mh.motorcycle_id = b.vehicle_id
                AND mh.customer_id = b.user_id
                AND mh.service_date = b.schedule_date
          )
        ORDER BY b.schedule_date DESC
        LIMIT 100
    ");
    $stmt->execute($bParams);
    $booking_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($booking_records as $b) {
        $item_names = get_admin_booking_item_names($pdo, $b['service_ids'], $b['package_ids']);
        $mechanics = get_admin_booking_mechanics($pdo, $b['id']);
        $completed_records[] = [
            'id' => $b['id'],
            'source' => 'booking',
            'motorcycle_id' => $b['vehicle_id'],
            'customer_id' => $b['user_id'],
            'motorcycle_info' => $b['motorcycle_info'],
            'customer_name' => $b['customer_name'],
            'service_date' => $b['schedule_date'],
            'mileage' => $b['current_mileage'] ?? 0,
            'service_type' => !empty($item_names) ? 'Booked: ' . implode(', ', $item_names) : 'Booked Service',
            'parts_replaced' => !empty($item_names) ? implode(', ', $item_names) : 'N/A',
            'cost' => $b['total_price'] ?? 0,
            'mechanic_remarks' => 'Completed booking #' . $b['id'],
            'performed_by' => $mechanics,
            'mechanic_name' => $mechanics,
        ];
    }
} catch (PDOException $e) {
    error_log("Error fetching completed bookings: " . $e->getMessage());
}

// Merge records and sort by service date
$maintenance_history = array_merge($maintenance_records, $completed_records);
usort($maintenance_history, function($a, $b) {
    return strtotime($b['service_date']) - strtotime($a['service_date']);
});

$pageTitle = 'Maintenance History Management';
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
        background: #ffffff !important;
        display: flex;
        flex-direction: column;
        height: 100vh !important;
        overflow: hidden !important;
    }
    @media (max-width: 991px) {
        .top-header { left: 0 !important; }
    }
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
    :root {
        --primary-color: #0f172a;
        --secondary-color: #64748b;
        --accent-color: #FACC15;
        --success: #10b981;
        --danger: #ef4444;
        --warning: #FACC15;
        --info: #3b82f6;
    }

    .mh-content {
        color: #000000;
        font-size: 0.75rem;
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
    }
    .mh-content .table-responsive {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    .mh-content .table-responsive::-webkit-scrollbar {
        width: 0;
        background: transparent;
    }
    .mh-content .table {
        font-size: 0.75rem;
        color: #000000 !important;
        background-color: transparent;
    }
    .mh-content .table thead th {
        background-color: #f8fafc !important;
        color: #1e3a5f !important;
        font-weight: 700;
        border: none;
        border-bottom: 2px solid #e2e8f0;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        position: sticky;
        top: 0;
        z-index: 5;
    }
    .mh-content .table tbody tr,
    .mh-content .table tbody tr:hover,
    .mh-content .table td {
        background-color: transparent !important;
    }
    .mh-content .table td {
        vertical-align: middle;
    }

    .mh-form {
        font-size: 0.75rem;
        color: #000000;
    }
    .mh-form .form-label {
        font-size: 0.7rem;
        margin-bottom: 5px;
        color: #000000;
    }
    .mh-form .form-control,
    .mh-form .form-select,
    .mh-form .form-check-label,
    .mh-form .form-text {
        font-size: 0.75rem;
        color: #000000;
    }
    .mh-form .form-control,
    .mh-form .form-select {
        padding: 8px 12px;
    }
    .mh-form .form-control:focus,
    .mh-form .form-select:focus {
        border-color: var(--accent-color);
        box-shadow: 0 0 0 0.2rem rgba(250, 204, 21, 0.25);
    }
    .mh-form .btn {
        font-size: 0.75rem;
        padding: 8px 16px;
    }

    .section-card {
        background: #ffffff;
        border: 1px solid rgba(0, 0, 0, 0.1);
        border-radius: 12px;
        padding: 1rem;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
    }
    .section-title {
        font-size: 0.85rem;
        font-weight: 700;
        color: #000000;
        margin-bottom: 0.5rem;
    }

    /* Modal styling */
    .modal-content {
        border-radius: 12px;
        border: none;
        overflow: hidden;
    }
    .modal-header {
        background: #ffffff;
        color: #000000;
        border: none;
        position: relative;
        padding: 15px 20px;
    }
    .modal-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #FACC15, #FDE047, #FACC15);
    }
    .modal-title {
        color: #000000;
        font-weight: 700;
        font-size: 0.85rem;
    }
    .modal-header .btn-close { filter: none; }
    .modal-body {
        padding: 20px;
    }
    .modal-footer {
        padding: 15px 20px;
        border-top: 1px solid rgba(0, 0, 0, 0.1);
    }

    /* Filter row */
    .filter-row .form-label {
        font-size: 0.7rem;
        color: #000000;
        margin-bottom: 5px;
    }
    .filter-row .form-select,
    .filter-row .btn {
        font-size: 0.75rem;
    }

    /* Action buttons */
    .action-group {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }
    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 8px;
        border: 1px solid transparent;
        font-size: 0.75rem;
        background: transparent;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
        padding: 0;
    }
    .action-btn.view { background: transparent; color: #2563eb; border-color: transparent; }
    .action-btn.view:hover { color: #1d4ed8; }
    .action-btn.edit { background: transparent; color: #FACC15; border-color: transparent; }
    .action-btn.edit:hover { color: #EAB308; }
    .action-btn.delete { background: transparent; color: #dc2626; border-color: transparent; }
    .action-btn.delete:hover { color: #b91c1c; }
    .action-btn:disabled {
        opacity: 0.45;
        cursor: not-allowed;
    }

    .service-type-badge {
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        background: rgba(250, 204, 21, 0.1);
        color: #3b82f6;
    }

    .view-label {
        font-size: 0.75rem;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 0.15rem;
    }
    .view-value {
        font-size: 0.75rem;
        color: #000000;
        font-weight: 600;
        margin-bottom: 0.5rem;
    }

    /* New list layout */
    .avatar-circle {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: 700;
        color: #ffffff;
        text-transform: uppercase;
        flex-shrink: 0;
    }
    .moto-thumb {
        width: 50px;
        height: 50px;
        border-radius: 10px;
        background: #f1f5f9;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #64748b;
        flex-shrink: 0;
        overflow: hidden;
    }
    .moto-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .moto-model {
        font-weight: 600;
        color: #000;
    }
    .moto-plate {
        font-size: 0.75rem;
        color: #64748b;
    }
    .customer-name, .mechanic-name {
        font-weight: 600;
        color: #000;
        font-size: 0.75rem;
    }
    .cell-icon {
        color: #94a3b8;
        font-size: 0.85rem;
    }
    .date-cell .date-line {
        line-height: 1.2;
    }
    .mileage-cell {
        font-weight: 600;
        color: #000;
    }
    .cost-cell {
        font-weight: 700;
        color: #000;
    }
    .service-type-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.35rem 0.75rem;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        white-space: normal;
        line-height: 1.3;
        max-width: 260px;
    }
    .service-type-badge.booked {
        background: #fffbeb;
        color: #EAB308;
        border: 1px solid #FEF3C7;
    }
    .service-type-badge.general {
        background: #f3e8ff;
        color: #7e22ce;
        border: 1px solid #d8b4fe;
    }
    .mh-content .table tbody tr {
        background: #ffffff;
        border-bottom: 1px solid #f1f5f9;
    }
    .mh-content .table tbody td {
        padding: 0.6rem 0.5rem;
    }
    .mh-content .table thead th {
        padding: 0.6rem 0.5rem;
    }
    .customer-name, .mechanic-name, .moto-model, .mileage-cell, .cost-cell {
        white-space: nowrap;
    }
    .list-footer {
        padding: 0.75rem 1rem;
        color: #64748b;
        font-size: 0.75rem;
    }
    .pagination-sm .page-link {
        border: none;
        color: #64748b;
        padding: 0.25rem 0.6rem;
    }
    .pagination-sm .page-item.active .page-link {
        background: #f97316;
        color: #fff;
        border-radius: 8px;
    }
    .mh-search-wrapper {
        position: relative;
        max-width: 360px;
    }
    .mh-search-icon {
        position: absolute;
        left: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        color: #6b7280;
        z-index: 2;
        pointer-events: none;
        font-size: 0.85rem;
    }
    .mh-search-input {
        padding: 0.55rem 2.25rem;
        border-radius: 10px;
        border: 1px solid #FACC15;
        height: 40px;
        font-size: 0.85rem;
        background: #ffffff;
        color: #111827;
        transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
    }
    .mh-search-input:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.2);
    }
    .mh-search-clear {
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
    .mh-search-clear:hover {
        color: #ef4444;
        background: #f3f4f6;
    }
    .mh-search-info {
        font-size: 0.75rem;
        color: #6b7280;
        margin-top: 0.4rem;
    }

    /* Dark mode overrides */
    html[data-theme="dark"] .mh-content .table { color: #e2e8f0 !important; }
    html[data-theme="dark"] .mh-content .table thead th {
        background-color: #22335a !important;
        color: #93c5fd !important;
        border-bottom-color: rgba(255, 255, 255, 0.12) !important;
    }
    html[data-theme="dark"] .mh-content .table tbody tr {
        background: #1a2b4f !important;
        border-bottom-color: rgba(255, 255, 255, 0.08) !important;
    }
    html[data-theme="dark"] .mh-content,
    html[data-theme="dark"] .mh-form, html[data-theme="dark"] .mh-form .form-label,
    html[data-theme="dark"] .mh-form .form-check-label, html[data-theme="dark"] .mh-form .form-text,
    html[data-theme="dark"] .filter-row .form-label { color: #e2e8f0 !important; }
    html[data-theme="dark"] .section-title { color: #e2e8f0 !important; }
    html[data-theme="dark"] .view-label { color: #94a3b8 !important; }
    html[data-theme="dark"] .view-value { color: #e2e8f0 !important; }
    html[data-theme="dark"] .moto-model, html[data-theme="dark"] .customer-name,
    html[data-theme="dark"] .mechanic-name, html[data-theme="dark"] .mileage-cell,
    html[data-theme="dark"] .cost-cell { color: #e2e8f0 !important; }
    html[data-theme="dark"] .moto-plate, html[data-theme="dark"] .cell-icon { color: #94a3b8 !important; }
    html[data-theme="dark"] .service-type-badge.booked { background: rgba(250, 204, 21, 0.12) !important; border-color: rgba(250, 204, 21, 0.3) !important; }
    html[data-theme="dark"] .service-type-badge.general { background: rgba(168, 85, 247, 0.15) !important; color: #c084fc !important; border-color: rgba(168, 85, 247, 0.3) !important; }
    html[data-theme="dark"] .pagination-sm .page-item.active .page-link { color: #111827 !important; }
</style>

<div class="container-fluid mh-content">
    <!-- Alert Message -->
    <?php if (!empty($msg)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="alert alert-<?= $msg_type === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                <?= $msg ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter / Add row -->
    <div class="row g-2 align-items-end filter-row mb-4">
        <div class="col-12 col-md-4">
            <label class="form-label"><i class="bi bi-person me-1"></i>Filter by Customer</label>
            <select class="form-select" onchange="window.location.href='?customer_id='+this.value">
                <option value="">All Customers</option>
                <?php foreach ($customers as $customer): ?>
                    <option value="<?= $customer['id'] ?>" <?= $selected_customer == $customer['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($customer['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-4">
            <div class="d-flex align-items-center h-100">
                <span class="badge bg-light text-dark border fs-6 fw-normal">
                    <i class="bi bi-clipboard-data me-1"></i><?= count($maintenance_history) ?> Records
                </span>
            </div>
        </div>
        <div class="col-12 col-md-4 text-md-end">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMaintenanceModal">
                <i class="bi bi-plus-lg me-2"></i>Add Maintenance Record
            </button>
        </div>
    </div>

    <!-- Search records -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="mh-search-wrapper">
                <i class="bi bi-search mh-search-icon"></i>
                <input type="text" class="form-control mh-search-input" id="maintenanceSearch" placeholder="Search records..." autocomplete="off">
                <button type="button" class="mh-search-clear" id="clearMaintenanceSearch" aria-label="Clear search">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="mh-search-info" id="maintenanceSearchInfo" style="display: none;">
                <span id="maintenanceSearchCount">0</span> record(s) found
            </div>
        </div>
    </div>

    <!-- Maintenance History Table -->
    <?php if (empty($maintenance_history)): ?>
        <div class="text-center py-5">
            <i class="bi bi-wrench display-1 text-muted"></i>
            <h4 class="mt-3 text-muted">No Maintenance Records Found</h4>
            <p class="text-muted">Start tracking motorcycle maintenance by adding your first service record.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="maintenanceTable">
                <thead>
                    <tr>
                        <th class="text-nowrap"><i class="bi bi-person me-1"></i>Customer</th>
                        <th class="text-nowrap">Motorcycle</th>
                        <th class="text-nowrap">Service Date</th>
                        <th class="text-nowrap">Mileage</th>
                        <th class="text-nowrap">Service Type</th>
                        <th class="text-nowrap">Cost</th>
                        <th class="text-nowrap">Performed By</th>
                        <th class="text-end text-nowrap"><i class="bi bi-three-dots me-1"></i>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($maintenance_history as $record):
                        $mechanicName = htmlspecialchars($record['mechanic_name'] ?: ($record['performed_by'] ?: '-'));
                        $motoParts = explode('(', $record['motorcycle_info'], 2);
                        $motoModel = trim($motoParts[0]);
                        $motoPlate = isset($motoParts[1]) ? '(' . $motoParts[1] : '';
                        $badgeClass = stripos($record['service_type'], 'Booked') !== false ? 'booked' : (stripos($record['service_type'], 'General') !== false ? 'general' : 'booked');
                    ?>
                        <tr>
                            <td>
                                <span class="customer-name"><?= htmlspecialchars($record['customer_name']) ?></span>
                            </td>
                            <td>
                                <div>
                                    <div class="moto-model"><?= htmlspecialchars($motoModel) ?></div>
                                    <div class="moto-plate"><?= htmlspecialchars($motoPlate) ?></div>
                                </div>
                            </td>
                            <td>
                                <div class="date-cell">
                                    <div class="date-line"><?= date('M d,', strtotime($record['service_date'])) ?></div>
                                    <div class="date-line text-muted small"><?= date('Y', strtotime($record['service_date'])) ?></div>
                                </div>
                            </td>
                            <td>
                                <span class="mileage-cell"><?= number_format($record['mileage']) ?> km</span>
                            </td>
                            <td>
                                <span class="service-type-badge <?= $badgeClass ?>">
                                    <?= htmlspecialchars($record['service_type']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="cost-cell">&#8369;<?= number_format($record['cost'], 2) ?></span>
                            </td>
                            <td>
                                <span class="mechanic-name"><?= $mechanicName ?></span>
                            </td>
                            <td class="text-end">
                                <div class="action-group">
                                    <button type="button" class="action-btn view" data-bs-toggle="modal" data-bs-target="#viewMaintenanceModal<?= $record['id'] ?>_<?= $record['source'] ?>" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <?php if ($record['source'] === 'maintenance'): ?>
                                    <button type="button" class="action-btn edit" data-bs-toggle="modal" data-bs-target="#editMaintenanceModal<?= $record['id'] ?>_maintenance" title="Edit Record">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this maintenance record?')">
                                        <input type="hidden" name="delete_maintenance" value="1">
                                        <input type="hidden" name="maintenance_id" value="<?= $record['id'] ?>">
                                        <button type="submit" class="action-btn delete" title="Delete Record">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <button type="button" class="action-btn edit" disabled title="Booked records cannot be edited">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="action-btn delete" disabled title="Booked records cannot be deleted">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>
</div>

<!-- Add Maintenance Modal -->
<div class="modal fade" id="addMaintenanceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Add Maintenance Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" class="mh-form">
                <div class="modal-body">
                    <input type="hidden" name="add_maintenance" value="1">
                    
                    <div class="row g-3">
                        <div class="col-md-6 mb-1">
                            <label class="form-label">Customer *</label>
                            <select class="form-select" name="customer_id" id="customerSelect" required onchange="filterMotorcycles()">
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?= $customer['id'] ?>" <?= ($selected_customer == $customer['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($customer['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-1">
                            <label class="form-label">Motorcycle *</label>
                            <select class="form-select" name="motorcycle_id" id="motorcycleSelect" required>
                                <option value="">Select Customer First</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6 mb-1">
                            <label class="form-label">Service Date *</label>
                            <input type="date" class="form-control" name="service_date" required>
                        </div>
                        <div class="col-md-6 mb-1">
                            <label class="form-label">Mileage (km) *</label>
                            <input type="number" class="form-control" name="mileage" id="mileageInput" value="<?= $prefill_mileage > 0 ? $prefill_mileage : '' ?>" required>
                        </div>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6 mb-1">
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
                        <div class="col-md-6 mb-1">
                            <label class="form-label">Cost ($)</label>
                            <input type="number" step="0.01" class="form-control" name="cost" value="0.00">
                        </div>
                    </div>
                    
                    <div class="mb-1">
                        <label class="form-label">Parts Replaced</label>
                        <textarea class="form-control" name="parts_replaced" rows="2" placeholder="List any parts that were replaced..."></textarea>
                    </div>
                    
                    <div class="mb-1">
                        <label class="form-label">Mechanic Remarks</label>
                        <textarea class="form-control" name="mechanic_remarks" rows="3" placeholder="Any notes from the mechanic..."></textarea>
                    </div>
                    
                    <div class="mb-1">
                        <label class="form-label">Performed By</label>
                        <select class="form-select" name="performed_by">
                            <option value="">Select Mechanic</option>
                            <?php foreach ($mechanics as $mech): ?>
                                <option value="<?= htmlspecialchars($mech['name']) ?>"><?= htmlspecialchars($mech['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
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

<!-- Edit / View Maintenance Modals -->
<?php foreach ($maintenance_history as $record): ?>
<!-- View Modal -->
<div class="modal fade" id="viewMaintenanceModal<?= $record['id'] ?>_<?= $record['source'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Maintenance Record Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body mh-form">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="view-label">Customer</div>
                        <div class="view-value"><?= htmlspecialchars($record['customer_name']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="view-label">Motorcycle</div>
                        <div class="view-value"><?= htmlspecialchars($record['motorcycle_info']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="view-label">Service Date</div>
                        <div class="view-value"><?= date('F j, Y', strtotime($record['service_date'])) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="view-label">Mileage</div>
                        <div class="view-value"><?= number_format($record['mileage']) ?> km</div>
                    </div>
                    <div class="col-md-6">
                        <div class="view-label">Service Type</div>
                        <div class="view-value"><span class="service-type-badge"><?= htmlspecialchars($record['service_type']) ?></span></div>
                    </div>
                    <div class="col-md-6">
                        <div class="view-label">Cost</div>
                        <div class="view-value">$<?= number_format($record['cost'], 2) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="view-label">Performed By</div>
                        <div class="view-value"><?= htmlspecialchars($record['mechanic_name'] ?: ($record['performed_by'] ?: '-')) ?></div>
                    </div>
                    <div class="col-12">
                        <div class="view-label">Parts Replaced</div>
                        <div class="view-value fw-normal"><?= nl2br(htmlspecialchars($record['parts_replaced'] ?: 'None')) ?></div>
                    </div>
                    <div class="col-12">
                        <div class="view-label">Mechanic Remarks</div>
                        <div class="view-value fw-normal"><?= nl2br(htmlspecialchars($record['mechanic_remarks'] ?: 'None')) ?></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<?php if ($record['source'] === 'maintenance'): ?>
<div class="modal fade" id="editMaintenanceModal<?= $record['id'] ?>_maintenance" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Maintenance Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" class="mh-form">
                <div class="modal-body">
                    <input type="hidden" name="edit_maintenance" value="1">
                    <input type="hidden" name="maintenance_id" value="<?= $record['id'] ?>">
                    
                    <div class="row g-3">
                        <div class="col-md-6 mb-1">
                            <label class="form-label">Service Date *</label>
                            <input type="date" class="form-control" name="service_date" value="<?= $record['service_date'] ?>" required>
                        </div>
                        <div class="col-md-6 mb-1">
                            <label class="form-label">Mileage (km) *</label>
                            <input type="number" class="form-control" name="mileage" value="<?= $record['mileage'] ?>" required>
                        </div>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6 mb-1">
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
                        <div class="col-md-6 mb-1">
                            <label class="form-label">Cost ($)</label>
                            <input type="number" step="0.01" class="form-control" name="cost" value="<?= $record['cost'] ?>">
                        </div>
                    </div>
                    
                    <div class="mb-1">
                        <label class="form-label">Parts Replaced</label>
                        <textarea class="form-control" name="parts_replaced" rows="2"><?= htmlspecialchars($record['parts_replaced']) ?></textarea>
                    </div>
                    
                    <div class="mb-1">
                        <label class="form-label">Mechanic Remarks</label>
                        <textarea class="form-control" name="mechanic_remarks" rows="3"><?= htmlspecialchars($record['mechanic_remarks']) ?></textarea>
                    </div>
                    
                    <div class="mb-1">
                        <label class="form-label">Performed By</label>
                        <select class="form-select" name="performed_by">
                            <option value="">Select Mechanic</option>
                            <?php foreach ($mechanics as $mech): ?>
                                <option value="<?= htmlspecialchars($mech['name']) ?>" <?= $record['performed_by'] === $mech['name'] ? 'selected' : '' ?>><?= htmlspecialchars($mech['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
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
<?php endif; ?>
<?php endforeach; ?>

<script>
function filterMotorcycles() {
    const customerId = document.getElementById('customerSelect').value;
    const motorcycleSelect = document.getElementById('motorcycleSelect');

    // Clear existing options
    motorcycleSelect.innerHTML = '<option value="">Select Motorcycle</option>';

    if (customerId) {
        const motorcycles = <?php echo json_encode($motorcycles); ?>;
        const filteredMotorcycles = motorcycles.filter(m => m.user_id == customerId);

        filteredMotorcycles.forEach(moto => {
            const option = document.createElement('option');
            option.value = moto.id;
            option.textContent = `${moto.brand} ${moto.model} (${moto.plate_number})`;
            motorcycleSelect.appendChild(option);
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const prefillCustomer = <?= json_encode((int) $selected_customer) ?>;
    const prefillMotorcycle = <?= json_encode((int) $selected_motorcycle) ?>;
    const openAdd = <?= $open_add_modal ? 'true' : 'false' ?>;

    if (openAdd && prefillCustomer && prefillMotorcycle) {
        const customerSelect = document.getElementById('customerSelect');
        const motorcycleSelect = document.getElementById('motorcycleSelect');

        customerSelect.value = prefillCustomer;
        filterMotorcycles();
        motorcycleSelect.value = prefillMotorcycle;

        const addModal = new bootstrap.Modal(document.getElementById('addMaintenanceModal'));
        addModal.show();
    }

    // Table search filter
    const searchInput = document.getElementById('maintenanceSearch');
    const clearBtn = document.getElementById('clearMaintenanceSearch');
    const searchInfo = document.getElementById('maintenanceSearchInfo');
    const searchCount = document.getElementById('maintenanceSearchCount');
    const table = document.getElementById('maintenanceTable');

    if (searchInput && table) {
        const tbody = table.querySelector('tbody');
        const rows = tbody ? Array.from(tbody.querySelectorAll('tr')) : [];

        let noResults = document.getElementById('noMaintenanceResults');
        if (!noResults && tbody) {
            noResults = document.createElement('tr');
            noResults.id = 'noMaintenanceResults';
            noResults.innerHTML = '<td colspan="8" class="text-center py-4 text-muted">No matching records found.</td>';
            noResults.style.display = 'none';
            tbody.appendChild(noResults);
        }

        function filterRows(query) {
            const q = query.trim().toLowerCase();
            let matchCount = 0;

            rows.forEach(function(row) {
                const text = row.textContent.toLowerCase();
                const match = !q || text.includes(q);
                row.style.display = match ? '' : 'none';
                if (match) matchCount++;
            });

            if (noResults) {
                noResults.style.display = (q && matchCount === 0) ? '' : 'none';
            }

            searchCount.textContent = matchCount;
            searchInfo.style.display = q ? 'block' : 'none';
            if (clearBtn) clearBtn.style.display = q ? 'flex' : 'none';
        }

        searchInput.addEventListener('input', function() {
            filterRows(this.value);
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                searchInput.value = '';
                filterRows('');
                searchInput.focus();
            });
        }
    }
});
</script>

<?php require 'admin_footer.php'; ?>