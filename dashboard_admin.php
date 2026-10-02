<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$pageTitle = 'Dashboard';
$currentPage = 'dashboard_admin';

$msg = "";
$msg_type = "";

// --- Fetch all Specialties for Checkboxes ---
try {
    $stmt = $pdo->query("SELECT id, specialty_name FROM specialties ORDER BY specialty_name");
    $all_specialties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching specialties: " . $e->getMessage());
    $all_specialties = [];
}

// --- PHP Logic for Creating a New Mechanic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_mechanic'])) {
    $name = trim($_POST['name'] ?? '');
    $selected_specialty_ids = $_POST['specialty_ids'] ?? [];

    if (empty($name)) {
        $msg = "Please fill in the mechanic's name.";
        $msg_type = "error";
    } elseif (empty($selected_specialty_ids)) {
        $msg = "Please select at least one specialty.";
        $msg_type = "error";
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO mechanics (name, status) VALUES (?, 'Available')");
            $stmt->execute([$name]);
            $mechanic_id = $pdo->lastInsertId();
            $link_stmt = $pdo->prepare("INSERT INTO mechanic_specialties (mechanic_id, specialty_id) VALUES (?, ?)");
            foreach ($selected_specialty_ids as $specialty_id) {
                $specialty_id = filter_var($specialty_id, FILTER_VALIDATE_INT);
                if ($specialty_id) {
                    $link_stmt->execute([$mechanic_id, $specialty_id]);
                }
            }
            $pdo->commit();
            $msg = "Mechanic " . htmlspecialchars($name) . " created successfully with " . count($selected_specialty_ids) . " specialty/ies!";
            $msg_type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $msg = "Database Error: Could not create mechanic.";
            $msg_type = "error";
        }
    }
}

// --- Fetch Dashboard Statistics ---
try {
    // Total Customers
    $totalCustomers = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
    $customersLastMonth = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)")->fetchColumn();
    $customerGrowth = $totalCustomers > 0 ? round(($customersLastMonth / $totalCustomers) * 100) : 0;

    // Registered Motorcycles
    $totalMotorcycles = $pdo->query("SELECT COUNT(*) FROM motorcycles")->fetchColumn();
    $motorcyclesLastMonth = $pdo->query("SELECT COUNT(*) FROM motorcycles WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)")->fetchColumn();
    $motorcycleGrowth = $totalMotorcycles > 0 ? round(($motorcyclesLastMonth / $totalMotorcycles) * 100) : 0;

    // Today's Appointments
    $todayAppointments = $pdo->query("SELECT COUNT(*) FROM bookings WHERE DATE(schedule_date) = CURDATE() AND status NOT IN ('rejected')")->fetchColumn();
    $yesterdayAppointments = $pdo->query("SELECT COUNT(*) FROM bookings WHERE DATE(schedule_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND status NOT IN ('rejected')")->fetchColumn();
    $appointmentChange = $yesterdayAppointments > 0
        ? round((($todayAppointments - $yesterdayAppointments) / $yesterdayAppointments) * 100)
        : ($todayAppointments > 0 ? 100 : 0);

    // Pending Appointments
    $pendingAppointments = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status IN ('pending', 'deposit_submitted')")->fetchColumn();
    $pendingLastWeek = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status IN ('pending', 'deposit_submitted') AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

    // Completed Services
    $completedServices = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'completed'")->fetchColumn();
    $completedThisMonth = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)")->fetchColumn();
    $completedGrowth = $completedServices > 0 ? round(($completedThisMonth / $completedServices) * 100) : 0;

    // Maintenance & Warranty
    $dueMaintenance = $pdo->query("SELECT COUNT(*) FROM motorcycles WHERE status = 'active' AND next_maintenance_date IS NOT NULL AND next_maintenance_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
    $expiringWarranties = $pdo->query("SELECT COUNT(*) FROM warranties WHERE status = 'active' AND warranty_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();

    // Emergency Requests (active = awaiting response or in progress)
    $activeEmergencies = $pdo->query("SELECT COUNT(*) FROM emergency_service_requests WHERE request_status IN ('pending','accepted','assigned','in_progress')")->fetchColumn();

    // Mechanics availability
    $totalMechanics = $pdo->query("SELECT COUNT(*) FROM mechanics")->fetchColumn();
    $availableMechanics = $pdo->query("SELECT COUNT(*) FROM mechanics WHERE status = 'Available'")->fetchColumn();

    // Service bays (fixed capacity; occupied = today's active bookings)
    $totalBays = 5;
    $occupiedBays = $pdo->query("SELECT COUNT(*) FROM bookings WHERE DATE(schedule_date) = CURDATE() AND status IN ('accepted','assigned')")->fetchColumn();
    $availableBays = max(0, $totalBays - (int)$occupiedBays);

    // --- Service Overview: monthly services + new customers (last 9 months) ---
    $monthlyServices = $pdo->query("
        SELECT DATE_FORMAT(schedule_date, '%Y-%m') as month, COUNT(*) as count
        FROM bookings
        WHERE schedule_date >= DATE_SUB(CURDATE(), INTERVAL 9 MONTH) AND status NOT IN ('rejected')
        GROUP BY month ORDER BY month
    ")->fetchAll(PDO::FETCH_ASSOC);

    $monthlyCustomers = $pdo->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count
        FROM users WHERE role = 'customer' AND created_at >= DATE_SUB(NOW(), INTERVAL 9 MONTH)
        GROUP BY month ORDER BY month
    ")->fetchAll(PDO::FETCH_ASSOC);

    // --- Maintenance Trends: completed services per month (last 6 months) ---
    $maintenanceTrends = $pdo->query("
        SELECT DATE_FORMAT(schedule_date, '%Y-%m') as month, COUNT(*) as count
        FROM bookings
        WHERE status = 'completed' AND schedule_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY month ORDER BY month
    ")->fetchAll(PDO::FETCH_ASSOC);

    // --- Service Package Distribution ---
    $packageNames = $pdo->query("SELECT id, package_name FROM service_packages WHERE status = 'active'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $bookingPkgRows = $pdo->query("SELECT package_ids FROM bookings WHERE status NOT IN ('rejected')")->fetchAll(PDO::FETCH_COLUMN);
    $packageCounts = [];
    foreach ($bookingPkgRows as $json) {
        $ids = json_decode($json ?? '', true);
        if (is_array($ids)) {
            foreach ($ids as $pid) {
                $pid = (int)$pid;
                if ($pid > 0) $packageCounts[$pid] = ($packageCounts[$pid] ?? 0) + 1;
            }
        }
    }
    $packageDist = [];
    foreach ($packageCounts as $pid => $count) {
        $packageDist[] = ['name' => $packageNames[$pid] ?? 'Other Services', 'count' => $count];
    }
    // Bookings without packages count as "Individual Services"
    $individualCount = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status NOT IN ('rejected') AND (package_ids IS NULL OR package_ids = '' OR package_ids = '[]')")->fetchColumn();
    if ($individualCount > 0) {
        $packageDist[] = ['name' => 'Individual Services', 'count' => (int)$individualCount];
    }
    usort($packageDist, fn($a, $b) => $b['count'] <=> $a['count']);
    $totalPackageServices = array_sum(array_column($packageDist, 'count'));

    // --- Upcoming Appointments ---
    $upcomingAppointments = $pdo->query("
        SELECT b.id, b.schedule_date, b.schedule_start_time, b.status, b.service_ids, b.package_ids,
               u.username AS customer_name, m.brand, m.model, m.plate_number
        FROM bookings b
        LEFT JOIN users u ON u.id = b.user_id
        LEFT JOIN motorcycles m ON m.id = b.vehicle_id
        WHERE b.schedule_date >= CURDATE() AND b.status NOT IN ('rejected', 'completed')
        ORDER BY b.schedule_date ASC, b.schedule_start_time ASC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Resolve service/package names for upcoming list
    $serviceNames = $pdo->query("SELECT id, service_name FROM services")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($upcomingAppointments as &$appt) {
        $names = [];
        $sids = json_decode($appt['service_ids'] ?? '', true);
        if (is_array($sids)) {
            foreach ($sids as $sid) {
                if (isset($serviceNames[(int)$sid])) $names[] = $serviceNames[(int)$sid];
            }
        }
        $pids = json_decode($appt['package_ids'] ?? '', true);
        if (is_array($pids)) {
            foreach ($pids as $pid) {
                if (isset($packageNames[(int)$pid])) $names[] = $packageNames[(int)$pid];
            }
        }
        $appt['service_label'] = !empty($names) ? implode(', ', array_slice($names, 0, 2)) : 'General Service';
        $appt['customer_display'] = ucwords(str_replace('_', ' ', $appt['customer_name'] ?? 'Customer'));
    }
    unset($appt);

} catch (PDOException $e) {
    $dashboardError = $e->getMessage();
    $totalCustomers = $totalMotorcycles = $todayAppointments = $appointmentChange = 0;
    $pendingAppointments = $pendingLastWeek = $completedServices = $completedThisMonth = $completedGrowth = 0;
    $dueMaintenance = $expiringWarranties = $activeEmergencies = 0;
    $totalMechanics = $availableMechanics = $totalBays = $occupiedBays = $availableBays = 0;
    $customerGrowth = $motorcycleGrowth = 0;
    $monthlyServices = $monthlyCustomers = $maintenanceTrends = $packageDist = $upcomingAppointments = [];
    $totalPackageServices = 0;
    error_log("Dashboard stats error: " . $e->getMessage());
}
// --- END Fetch Dashboard Statistics ---
?>
<?php require 'admin_sidebar_template.php'; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<style>
    :root {
        --card-border: #E8ECF3;
        --text-main: #111827;
        --text-muted: #6B7280;
        --blue: #3b82f6;
        --green: #10b981;
        --red: #ef4444;
        --amber: #f59e0b;
        --purple: #8b5cf6;
    }

    body {
        background-color: #F5F7FB !important;
        font-family: 'Plus Jakarta Sans', sans-serif !important;
        color: var(--text-main) !important;
    }

    .top-header {
        position: fixed !important;
        top: 0; left: var(--sidebar-width); right: 0;
        z-index: 100;
        background: #ffffff !important;
        border-bottom: 1px solid #E5E7EB;
        box-shadow: 0 2px 12px rgba(0,0,0,0.04);
    }

    .main-content {
        padding-top: 90px !important;
        padding-left: 26px !important;
        padding-right: 26px !important;
        background: #F5F7FB !important;
        min-height: 100vh;
    }

    .dashboard-page { max-width: 1460px; margin: 0 auto; width: 100%; padding-bottom: 3rem; }

    /* ===== Page Header ===== */
    .dash-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1.4rem; flex-wrap: wrap; }
    .dash-title-wrap { display: flex; align-items: center; gap: 0.75rem; }
    .dash-logo-badge {
        width: 42px; height: 42px; border-radius: 12px;
        background: linear-gradient(135deg, #FACC15, #FDE047);
        display: flex; align-items: center; justify-content: center;
        color: #111827; box-shadow: 0 4px 12px rgba(250,204,21,0.35);
    }
    .dash-logo-badge i { width: 22px; height: 22px; }
    .dash-page-title { font-size: 1.55rem; font-weight: 800; letter-spacing: -0.02em; margin: 0; color: #0f172a; }
    .dash-page-subtitle { font-size: 0.82rem; color: var(--text-muted); margin-top: 0.1rem; }
    .dash-header-meta { display: flex; align-items: center; gap: 1.1rem; color: var(--text-muted); font-size: 0.82rem; font-weight: 600; }
    .dash-header-meta span { display: inline-flex; align-items: center; gap: 0.4rem; }
    .dash-header-meta i { width: 15px; height: 15px; color: #9CA3AF; }

    /* ===== Alerts ===== */
    .dash-alert { background: #fff; border: 1px solid var(--card-border); border-radius: 10px; padding: 0.6rem 1rem; font-size: 0.85rem; margin-bottom: 1.25rem; }
    .dash-alert.success { border-left: 3px solid var(--green); }
    .dash-alert.error { border-left: 3px solid var(--red); }

    /* ===== Stat Cards ===== */
    .stat-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.1rem; }
    .stat-card {
        background: #fff; border: 1px solid var(--card-border); border-radius: 16px;
        padding: 1.05rem 1.1rem; position: relative; overflow: hidden;
        box-shadow: 0 2px 10px rgba(15,23,42,0.03);
        transition: transform .18s ease, box-shadow .18s ease;
    }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(15,23,42,0.07); }
    .stat-card::after {
        content: ''; position: absolute; right: -30px; bottom: -35px;
        width: 120px; height: 120px; border-radius: 50%;
        background: var(--tint, rgba(59,130,246,0.07));
    }
    .stat-top { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.65rem; }
    .stat-icon { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .stat-icon i { width: 18px; height: 18px; }
    .stat-icon.blue { background: #E3EDFF; color: #3b82f6; }
    .stat-icon.green { background: #DCF5EC; color: #10b981; }
    .stat-icon.amber { background: #FEF3D8; color: #f59e0b; }
    .stat-icon.red { background: #FDE4E4; color: #ef4444; }
    .stat-icon.purple { background: #EDE7FD; color: #8b5cf6; }
    .stat-label { font-size: 0.78rem; font-weight: 600; color: #374151; line-height: 1.25; }
    .stat-value { font-size: 1.7rem; font-weight: 800; color: #0f172a; line-height: 1.15; }
    .stat-trend { display: flex; align-items: center; gap: 0.3rem; font-size: 0.72rem; font-weight: 700; margin-top: 0.3rem; position: relative; z-index: 1; }
    .stat-trend i { width: 12px; height: 12px; }
    .stat-trend.up { color: #10b981; }
    .stat-trend.down { color: #ef4444; }
    .stat-trend.neutral { color: #9CA3AF; }
    .stat-trend-sub { font-size: 0.68rem; color: #9CA3AF; font-weight: 500; margin-top: 0.1rem; position: relative; z-index: 1; }

    /* ===== Generic Card ===== */
    .card-x {
        background: #fff; border: 1px solid var(--card-border); border-radius: 16px;
        padding: 1.1rem 1.2rem; box-shadow: 0 2px 10px rgba(15,23,42,0.03);
        display: flex; flex-direction: column; min-width: 0;
    }
    .card-x-title { font-size: 0.95rem; font-weight: 700; color: #0f172a; }
    .card-x-desc { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.1rem; }
    .card-x-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.6rem; }
    .card-x-link { font-size: 0.75rem; font-weight: 600; color: #3b82f6; text-decoration: none; display: inline-flex; align-items: center; gap: 0.2rem; white-space: nowrap; }
    .card-x-link:hover { color: #1d4ed8; }
    .card-x-link i { width: 13px; height: 13px; }

    /* ===== Layout rows ===== */
    .row-mid { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr) minmax(0, 1fr); gap: 1rem; margin-bottom: 1.1rem; }
    .row-low { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.15fr) minmax(0, 1fr); gap: 1rem; margin-bottom: 1.1rem; }
    .row-bot { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) minmax(0, 1fr); gap: 1rem; }

    /* ===== Chart wrappers ===== */
    .chart-wrap { position: relative; height: 230px; width: 100%; }
    .chart-wrap-sm { position: relative; height: 190px; width: 100%; }
    .chart-legend-inline { display: flex; gap: 1rem; font-size: 0.72rem; color: var(--text-muted); font-weight: 600; }
    .chart-legend-inline span { display: inline-flex; align-items: center; gap: 0.3rem; }
    .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }

    /* ===== Donut legend ===== */
    .donut-body { display: flex; align-items: center; gap: 1rem; flex: 1; }
    .donut-canvas { position: relative; width: 150px; height: 150px; flex-shrink: 0; }
    .donut-legend { display: flex; flex-direction: column; gap: 0.5rem; flex: 1; min-width: 0; }
    .donut-legend-row { display: flex; align-items: center; gap: 0.5rem; font-size: 0.76rem; }
    .donut-legend-row .name { flex: 1; color: #374151; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .donut-legend-row .pct { font-weight: 700; color: #0f172a; }

    /* ===== Appointments list ===== */
    .appt-list { display: flex; flex-direction: column; flex: 1; }
    .appt-item { display: flex; align-items: center; gap: 0.8rem; padding: 0.65rem 0.2rem; border-bottom: 1px solid #F1F4F9; }
    .appt-item:last-child { border-bottom: none; }
    .appt-time { font-size: 0.78rem; font-weight: 700; color: #3b82f6; width: 62px; flex-shrink: 0; }
    .appt-info { flex: 1; min-width: 0; }
    .appt-name { font-size: 0.82rem; font-weight: 700; color: #0f172a; }
    .appt-sub { font-size: 0.7rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .appt-badge { font-size: 0.68rem; font-weight: 700; padding: 0.28rem 0.7rem; border-radius: 99px; flex-shrink: 0; }
    .appt-badge.approved { background: #E3EDFF; color: #2563eb; }
    .appt-badge.pending { background: #FEF3D8; color: #d97706; }
    .appt-badge.scheduled { background: #F1F4F9; color: #64748b; }
    .appt-badge.confirmed { background: #DCF5EC; color: #059669; }
    .appt-chevron { color: #C4CBD8; width: 15px; height: 15px; flex-shrink: 0; }
    .appt-empty { text-align: center; color: var(--text-muted); font-size: 0.8rem; padding: 2rem 0; }

    /* ===== Maintenance & Warranty mini cards ===== */
    .mw-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; flex: 1; }
    .mw-card { border: 1px solid var(--card-border); border-radius: 12px; padding: 0.9rem; display: flex; flex-direction: column; gap: 0.25rem; }
    .mw-icon { width: 34px; height: 34px; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-bottom: 0.3rem; }
    .mw-icon i { width: 16px; height: 16px; }
    .mw-icon.red { background: #FDE4E4; color: #ef4444; }
    .mw-icon.amber { background: #FEF3D8; color: #f59e0b; }
    .mw-title { font-size: 0.74rem; font-weight: 600; color: #374151; }
    .mw-value { font-size: 1.5rem; font-weight: 800; color: #0f172a; line-height: 1.1; }
    .mw-sub { font-size: 0.7rem; color: var(--text-muted); }
    .mw-link { font-size: 0.72rem; font-weight: 600; color: #3b82f6; text-decoration: none; margin-top: 0.35rem; display: inline-flex; align-items: center; gap: 0.25rem; }
    .mw-link:hover { color: #1d4ed8; }
    .mw-link i { width: 12px; height: 12px; }

    /* ===== Emergency card ===== */
    .emg-title { display: flex; align-items: center; gap: 0.45rem; font-size: 0.95rem; font-weight: 700; color: #0f172a; }
    .emg-title i { width: 17px; height: 17px; color: #f59e0b; }
    .emg-body { display: flex; align-items: center; gap: 1rem; flex: 1; }
    .emg-count { font-size: 2.1rem; font-weight: 800; color: #ef4444; line-height: 1; }
    .emg-label { font-size: 0.8rem; font-weight: 700; color: #374151; }
    .emg-sub { font-size: 0.72rem; color: var(--text-muted); margin-bottom: 0.6rem; }
    .emg-illust { flex: 1; display: flex; align-items: center; justify-content: flex-end; min-width: 0; }
    .emg-illust svg { width: 185px; max-width: 100%; height: auto; display: block; }
    .btn-view { display: inline-flex; align-items: center; gap: 0.35rem; background: #fff; border: 1px solid #E2E8F0; border-radius: 8px; padding: 0.4rem 0.85rem; font-size: 0.75rem; font-weight: 700; color: #0f172a; text-decoration: none; transition: all .15s ease; }
    .btn-view:hover { border-color: #3b82f6; color: #3b82f6; }
    .btn-view i { width: 13px; height: 13px; }

    /* ===== Quick Actions ===== */
    .card-x.qa-card { background: #FFF9E8; border-color: #F5E9C8; }
    .qa-title { display: flex; align-items: center; gap: 0.45rem; font-size: 0.95rem; font-weight: 700; color: #0f172a; margin-bottom: 0.5rem; }
    .qa-title i { width: 17px; height: 17px; color: #f59e0b; }
    .qa-list { display: flex; flex-direction: column; gap: 0.45rem; flex: 1; }
    .qa-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.7rem 0.85rem; border: 1px solid var(--card-border); border-radius: 12px; text-decoration: none; color: #0f172a; font-size: 0.82rem; font-weight: 600; transition: all .15s ease; background: #fff; cursor: pointer; }
    .qa-item:hover { border-color: #3b82f6; background: #F8FAFF; color: #0f172a; }
    .qa-item i.lead { width: 17px; height: 17px; color: #374151; }
    .qa-item i.tail { width: 15px; height: 15px; color: #C4CBD8; margin-left: auto; }

    /* ===== Availability / Bays ===== */
    .av-head { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; font-weight: 700; color: #374151; margin-bottom: 0.7rem; }
    .av-head i { width: 17px; height: 17px; color: #3b82f6; }
    .av-value { font-size: 1.9rem; font-weight: 800; color: #0f172a; line-height: 1.05; }
    .av-sub { font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.7rem; }
    .av-bar { height: 8px; border-radius: 99px; background: #EDF1F7; overflow: hidden; margin-bottom: 0.7rem; }
    .av-bar-fill { height: 100%; border-radius: 99px; background: #3b82f6; transition: width .6s ease; }

    /* ===== Trends select ===== */
    .trend-select { border: 1px solid #E2E8F0; border-radius: 8px; font-size: 0.72rem; font-weight: 600; color: #374151; padding: 0.25rem 0.5rem; background: #fff; }

    /* ===== Modal ===== */
    .modal-backdrop.show { backdrop-filter: blur(8px); background: rgba(0,0,0,0.3); }
    .modal-content.glass-modal { background: #fff !important; border: 1px solid #E5E7EB !important; border-radius: 16px !important; color: #111827 !important; }
    .modal-header.glass-modal-header { border-bottom: 1px solid #E5E7EB !important; padding: 1rem 1.25rem; }
    .glass-modal .btn-close { filter: none; opacity: 0.6; }
    .glass-modal-body { padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem; }
    .dash-form-label { font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem; }
    .dash-form-control { background: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 8px; padding: 0.6rem 0.75rem; color: #111827; font-size: 0.85rem; width: 100%; outline: none; }
    .dash-form-control:focus { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.15); }
    .dash-check-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.5rem; }
    .dash-form-check { display: flex; align-items: center; gap: 0.5rem; background: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 8px; padding: 0.45rem 0.65rem; font-size: 0.82rem; }
    .dash-form-check input[type="checkbox"] { accent-color: #3b82f6; width: 16px; height: 16px; }
    .dash-btn { display: inline-flex; align-items: center; gap: 0.4rem; border: none; border-radius: 8px; padding: 0.55rem 1rem; font-size: 0.85rem; font-weight: 700; cursor: pointer; transition: all .2s ease; }
    .dash-btn.primary { background: linear-gradient(135deg, #FACC15, #FDE047); color: #090d16; }
    .dash-btn.primary:hover { background: #EAB308; }
    .dash-btn.ghost { background: #F3F4F6; color: #6B7280; }
    .dash-btn.ghost:hover { background: #E5E7EB; color: #111827; }
    .dash-btn i { width: 14px; height: 14px; }

    /* ===== Dark mode overrides ===== */
    html.dark-mode .emg-title,
    html.dark-mode .emg-label,
    html.dark-mode .qa-title,
    html.dark-mode .av-head,
    html.dark-mode .av-value { color: #e2e8f0 !important; }

    html.dark-mode .emg-sub, html.dark-mode .av-sub { color: #94a3b8 !important; }
    html.dark-mode .emg-title i, html.dark-mode .qa-title i { color: #fbbf24 !important; }
    html.dark-mode .av-head i { color: #60a5fa !important; }

    html.dark-mode .btn-view { background: #1a2540 !important; border-color: #33415f !important; color: #e2e8f0 !important; }
    html.dark-mode .btn-view:hover { border-color: #3b82f6 !important; color: #60a5fa !important; }

    html.dark-mode .card-x.qa-card { background: #151f36 !important; border-color: rgba(250, 204, 21, 0.2) !important; }
    html.dark-mode .qa-item { background: #0f1a30 !important; border-color: rgba(255, 255, 255, 0.09) !important; color: #e2e8f0 !important; }
    html.dark-mode .qa-item:hover { background: #16233f !important; border-color: #3b82f6 !important; color: #ffffff !important; }
    html.dark-mode .qa-item i.lead { color: #94a3b8 !important; }
    html.dark-mode .qa-item i.tail { color: #475569 !important; }

    html.dark-mode .av-bar { background: #22304f !important; }
    html.dark-mode .trend-select { background: #0f1a30 !important; border-color: #33415f !important; color: #e2e8f0 !important; }

    html.dark-mode .modal-content.glass-modal { background: #151f36 !important; border-color: rgba(255, 255, 255, 0.1) !important; color: #e2e8f0 !important; }
    html.dark-mode .glass-modal-header { border-bottom-color: rgba(255, 255, 255, 0.09) !important; }
    html.dark-mode .glass-modal .modal-title { color: #e2e8f0 !important; }
    html.dark-mode .glass-modal .btn-close { filter: invert(1) !important; opacity: 0.8; }
    html.dark-mode .dash-form-label { color: #94a3b8 !important; }
    html.dark-mode .dash-form-control { background: #0f1a30 !important; border-color: #33415f !important; color: #e2e8f0 !important; }
    html.dark-mode .dash-form-control::placeholder { color: #64748b; }
    html.dark-mode .dash-form-control:focus { border-color: #3b82f6 !important; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25) !important; }
    html.dark-mode .dash-form-check { background: #0f1a30 !important; border-color: #33415f !important; color: #e2e8f0 !important; }
    html.dark-mode .dash-btn.ghost { background: #22304f !important; color: #cbd5e1 !important; }
    html.dark-mode .dash-btn.ghost:hover { background: #2b3a5c !important; color: #ffffff !important; }

    @media (max-width: 1200px) {
        .stat-grid { grid-template-columns: repeat(3, 1fr); }
        .row-mid, .row-low, .row-bot { grid-template-columns: 1fr; }
    }
    @media (max-width: 767px) {
        .stat-grid { grid-template-columns: 1fr 1fr; }
        .mw-grid { grid-template-columns: 1fr; }
    }
</style>
<div class="dashboard-page">

    <!-- Page Header -->
    <div class="dash-header">
        <div class="dash-title-wrap">
            <div class="dash-logo-badge"><i data-lucide="wrench"></i></div>
            <div>
                <h1 class="dash-page-title">Dashboard</h1>
                <div class="dash-page-subtitle">Overview of your motorcycle service operations</div>
            </div>
        </div>
        <div class="dash-header-meta">
            <span><i data-lucide="calendar"></i> <?= date('D, M j, Y') ?></span>
            <span><i data-lucide="clock"></i> <?= date('g:i A') ?></span>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="dash-alert <?= $msg_type == 'success' ? 'success' : 'error' ?> alert alert-dismissible fade show" role="alert">
            <i data-lucide="<?= $msg_type == 'success' ? 'check-circle' : 'alert-circle' ?>" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i>
            <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="padding:0.8rem;"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($dashboardError)): ?>
        <div class="dash-alert error alert alert-danger alert-dismissible fade show" role="alert">
            <i data-lucide="alert-circle" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i>
            <strong>Dashboard data error:</strong> <?= htmlspecialchars($dashboardError) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- ===== Stat Cards ===== -->
    <div class="stat-grid">
        <div class="stat-card" style="--tint: rgba(59,130,246,0.08);">
            <div class="stat-top">
                <div class="stat-icon blue"><i data-lucide="users"></i></div>
                <div class="stat-label">Total Customers</div>
            </div>
            <div class="stat-value"><?= number_format($totalCustomers) ?></div>
            <div class="stat-trend up"><i data-lucide="trending-up"></i> +<?= $customerGrowth ?>%</div>
            <div class="stat-trend-sub">vs. last month</div>
        </div>
        <div class="stat-card" style="--tint: rgba(16,185,129,0.08);">
            <div class="stat-top">
                <div class="stat-icon green"><i data-lucide="bike"></i></div>
                <div class="stat-label">Registered Motorcycles</div>
            </div>
            <div class="stat-value"><?= number_format($totalMotorcycles) ?></div>
            <div class="stat-trend up"><i data-lucide="trending-up"></i> +<?= $motorcycleGrowth ?>%</div>
            <div class="stat-trend-sub">vs. last month</div>
        </div>
        <div class="stat-card" style="--tint: rgba(245,158,11,0.08);">
            <div class="stat-top">
                <div class="stat-icon amber"><i data-lucide="calendar-check"></i></div>
                <div class="stat-label">Today's Appointments</div>
            </div>
            <div class="stat-value"><?= number_format($todayAppointments) ?></div>
            <div class="stat-trend <?= $appointmentChange > 0 ? 'up' : ($appointmentChange < 0 ? 'down' : 'neutral') ?>">
                <i data-lucide="<?= $appointmentChange >= 0 ? 'minus' : 'trending-down' ?>"></i>
                <?= $appointmentChange >= 0 ? '+' : '' ?><?= $appointmentChange ?>%
            </div>
            <div class="stat-trend-sub">vs. yesterday</div>
        </div>
        <div class="stat-card" style="--tint: rgba(239,68,68,0.08);">
            <div class="stat-top">
                <div class="stat-icon red"><i data-lucide="clock"></i></div>
                <div class="stat-label">Pending Appointments</div>
            </div>
            <div class="stat-value"><?= number_format($pendingAppointments) ?></div>
            <div class="stat-trend <?= $pendingLastWeek > 0 ? 'down' : 'neutral' ?>">
                <i data-lucide="arrow-down-right"></i> <?= $pendingLastWeek ?>
            </div>
            <div class="stat-trend-sub">vs. last week</div>
        </div>
        <div class="stat-card" style="--tint: rgba(139,92,246,0.08);">
            <div class="stat-top">
                <div class="stat-icon purple"><i data-lucide="check-circle"></i></div>
                <div class="stat-label">Completed Services</div>
            </div>
            <div class="stat-value"><?= number_format($completedServices) ?></div>
            <div class="stat-trend up"><i data-lucide="trending-up"></i> +<?= $completedGrowth ?>%</div>
            <div class="stat-trend-sub">vs. last month</div>
        </div>
    </div>

    <!-- ===== Row: Service Overview | Package Distribution | Upcoming ===== -->
    <div class="row-mid">
        <div class="card-x">
            <div class="card-x-head">
                <div>
                    <div class="card-x-title">Service Overview</div>
                    <div class="card-x-desc">Monthly services and package distribution</div>
                </div>
                <div class="chart-legend-inline">
                    <span><span class="dot" style="background:#3b82f6;"></span> Services</span>
                    <span><span class="dot" style="background:#FACC15;"></span> Customers</span>
                </div>
            </div>
            <div class="chart-wrap"><canvas id="overviewChart"></canvas></div>
        </div>

        <div class="card-x">
            <div class="card-x-head">
                <div>
                    <div class="card-x-title">Service Package Distribution</div>
                </div>
            </div>
            <div class="donut-body">
                <div class="donut-canvas"><canvas id="packageChart"></canvas></div>
                <div class="donut-legend">
                    <?php
                    $donutColors = ['#3b82f6', '#FACC15', '#10b981', '#8b5cf6', '#94a3b8', '#f97316'];
                    foreach (array_slice($packageDist, 0, 6) as $i => $pd):
                        $pct = $totalPackageServices > 0 ? round(($pd['count'] / $totalPackageServices) * 100) : 0;
                    ?>
                        <div class="donut-legend-row">
                            <span class="dot" style="background:<?= $donutColors[$i % count($donutColors)] ?>;"></span>
                            <span class="name"><?= htmlspecialchars($pd['name']) ?></span>
                            <span class="pct"><?= $pct ?>%</span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($packageDist)): ?>
                        <div class="appt-sub">No booking data yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card-x">
            <div class="card-x-head">
                <div class="card-x-title">Upcoming Appointments</div>
                <a href="manage_bookings.php" class="card-x-link">View all <i data-lucide="arrow-right"></i></a>
            </div>
            <div class="appt-list">
                <?php if (empty($upcomingAppointments)): ?>
                    <div class="appt-empty">No upcoming appointments.</div>
                <?php else: ?>
                    <?php foreach ($upcomingAppointments as $appt):
                        $statusMap = [
                            'accepted' => ['Approved', 'approved'],
                            'assigned' => ['Scheduled', 'scheduled'],
                            'deposit_submitted' => ['Confirmed', 'confirmed'],
                            'pending' => ['Pending', 'pending'],
                        ];
                        [$badgeLabel, $badgeClass] = $statusMap[$appt['status']] ?? ['Pending', 'pending'];
                        $timeLabel = $appt['schedule_start_time'] ? date('g:i A', strtotime($appt['schedule_start_time'])) : '—';
                        $motoLabel = trim(($appt['brand'] ?? '') . ' ' . ($appt['model'] ?? ''));
                        $plateLabel = $appt['plate_number'] ?? '';
                    ?>
                        <div class="appt-item">
                            <div class="appt-time"><?= $timeLabel ?></div>
                            <div class="appt-info">
                                <div class="appt-name"><?= htmlspecialchars($appt['customer_display']) ?></div>
                                <div class="appt-sub"><?= htmlspecialchars($appt['service_label']) ?></div>
                                <?php if ($motoLabel || $plateLabel): ?>
                                    <div class="appt-sub"><?= htmlspecialchars($motoLabel) ?><?= $plateLabel ? ' (Plate: ' . htmlspecialchars($plateLabel) . ')' : '' ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="appt-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                            <i data-lucide="chevron-right" class="appt-chevron"></i>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===== Row: Maintenance & Warranty | Emergency | Quick Actions ===== -->
    <div class="row-low">
        <div class="card-x">
            <div class="card-x-head">
                <div class="card-x-title">Maintenance &amp; Warranty</div>
            </div>
            <div class="mw-grid">
                <div class="mw-card">
                    <div class="mw-icon red"><i data-lucide="wrench"></i></div>
                    <div class="mw-title">Due for Maintenance</div>
                    <div class="mw-value"><?= number_format($dueMaintenance) ?></div>
                    <div class="mw-sub">Motorcycles</div>
                    <a href="maintenance_management.php" class="mw-link">View list <i data-lucide="arrow-right"></i></a>
                </div>
                <div class="mw-card">
                    <div class="mw-icon amber"><i data-lucide="shield-check"></i></div>
                    <div class="mw-title">Warranty Expiring Soon</div>
                    <div class="mw-value"><?= number_format($expiringWarranties) ?></div>
                    <div class="mw-sub">Motorcycles</div>
                    <a href="admin_warranty.php" class="mw-link">View list <i data-lucide="arrow-right"></i></a>
                </div>
            </div>
        </div>

        <div class="card-x">
            <div class="card-x-head">
                <div class="emg-title"><i data-lucide="siren"></i> Emergency Service Requests</div>
            </div>
            <div class="emg-body">
                <div>
                    <div style="display:flex;align-items:baseline;gap:0.5rem;">
                        <div class="emg-count"><?= number_format($activeEmergencies) ?></div>
                        <div class="emg-label">Active Request<?= $activeEmergencies == 1 ? '' : 's' ?></div>
                    </div>
                    <div class="emg-sub">Awaiting response</div>
                    <a href="admin_emergency_requests.php" class="btn-view">View details <i data-lucide="arrow-right"></i></a>
                </div>
                <div class="emg-illust">
                    <svg viewBox="0 0 220 150" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M22 116 C10 84 30 42 76 30 C122 18 176 26 196 58 C212 84 204 118 170 128 C128 140 44 140 22 116 Z" fill="#EAF2FD"/>
                        <path d="M38 98 C72 86 104 96 136 80 C156 70 176 76 190 64" stroke="#fff" stroke-width="7" fill="none" stroke-linecap="round"/>
                        <path d="M52 122 C92 112 132 118 170 104" stroke="#fff" stroke-width="6" fill="none" stroke-linecap="round"/>
                        <path d="M58 58 C78 50 98 54 116 46" stroke="#fff" stroke-width="5" fill="none" stroke-linecap="round" opacity="0.7"/>
                        <g>
                            <circle cx="68" cy="108" r="18" fill="#111827"/>
                            <circle cx="68" cy="108" r="9.5" fill="#E2E8F0"/>
                            <circle cx="68" cy="108" r="4" fill="#94A3B8"/>
                            <circle cx="152" cy="108" r="18" fill="#111827"/>
                            <circle cx="152" cy="108" r="9.5" fill="#E2E8F0"/>
                            <circle cx="152" cy="108" r="4" fill="#94A3B8"/>
                            <path d="M52 98 C54 89 62 85 71 86" stroke="#3B82F6" stroke-width="5" fill="none" stroke-linecap="round"/>
                            <path d="M136 98 C138 89 146 85 155 86" stroke="#3B82F6" stroke-width="5" fill="none" stroke-linecap="round"/>
                            <path d="M68 108 L95 82 L125 82 L152 108" fill="none" stroke="#3B82F6" stroke-width="7" stroke-linejoin="round"/>
                            <rect x="98" y="90" width="24" height="15" rx="4" fill="#2563EB"/>
                            <ellipse cx="118" cy="78" rx="18" ry="9" fill="#3B82F6"/>
                            <path d="M95 82 L76 86 L83 74 Z" fill="#111827"/>
                            <path d="M140 80 L152 108" stroke="#374151" stroke-width="5" stroke-linecap="round"/>
                            <path d="M136 68 L146 78" stroke="#111827" stroke-width="4" stroke-linecap="round"/>
                            <circle cx="146" cy="80" r="5" fill="#FDE047"/>
                        </g>
                        <g transform="translate(152,10)">
                            <path d="M0 0 C-12.5 0 -20 8.5 -20 19.5 C-20 34 0 49 0 49 C0 49 20 34 20 19.5 C20 8.5 12.5 0 0 0 Z" fill="#F59E0B"/>
                            <circle cx="0" cy="19" r="7.5" fill="#fff"/>
                        </g>
                    </svg>
                </div>
            </div>
        </div>

        <div class="card-x qa-card">
            <div class="qa-title"><i data-lucide="zap"></i> Quick Actions</div>
            <div class="qa-list">
                <a href="manage_bookings.php" class="qa-item">
                    <i data-lucide="calendar-plus" class="lead"></i> Create Appointment
                    <i data-lucide="chevron-right" class="tail"></i>
                </a>
                <a href="manage_customers_motorcycles.php" class="qa-item">
                    <i data-lucide="user-plus" class="lead"></i> Register Customer
                    <i data-lucide="chevron-right" class="tail"></i>
                </a>
                <a href="manage_motorcycles.php" class="qa-item">
                    <i data-lucide="bike" class="lead"></i> Register Motorcycle
                    <i data-lucide="chevron-right" class="tail"></i>
                </a>
                <a href="admin_emergency_requests.php" class="qa-item">
                    <i data-lucide="alert-triangle" class="lead"></i> Emergency Request
                    <i data-lucide="chevron-right" class="tail"></i>
                </a>
                <button type="button" class="qa-item" data-bs-toggle="modal" data-bs-target="#mechanicModal">
                    <i data-lucide="wrench" class="lead"></i> Add Mechanic
                    <i data-lucide="chevron-right" class="tail"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- ===== Row: Maintenance Trends | Mechanics | Service Bays ===== -->
    <div class="row-bot">
        <div class="card-x">
            <div class="card-x-head">
                <div>
                    <div class="card-x-title">Maintenance Trends</div>
                    <div class="card-x-desc">Last 6 months</div>
                </div>
                <select class="trend-select" id="trendSelect">
                    <option value="services">Services</option>
                </select>
            </div>
            <div class="chart-wrap-sm"><canvas id="trendsChart"></canvas></div>
        </div>

        <div class="card-x">
            <div class="av-head"><i data-lucide="users"></i> Mechanics Availability</div>
            <div class="av-value"><?= number_format($availableMechanics) ?></div>
            <div class="av-sub">Available Mechanics<br>of <?= number_format($totalMechanics) ?> total</div>
            <div class="av-bar">
                <div class="av-bar-fill" style="width: <?= $totalMechanics > 0 ? round(($availableMechanics / $totalMechanics) * 100) : 0 ?>%;"></div>
            </div>
            <a href="manage_mechanics.php" class="card-x-link">View mechanics <i data-lucide="arrow-right"></i></a>
        </div>

        <div class="card-x">
            <div class="av-head"><i data-lucide="warehouse"></i> Service Bays</div>
            <div class="av-value"><?= number_format($availableBays) ?></div>
            <div class="av-sub">Available Bays<br>of <?= number_format($totalBays) ?> total</div>
            <div class="av-bar">
                <div class="av-bar-fill" style="width: <?= $totalBays > 0 ? round(($availableBays / $totalBays) * 100) : 0 ?>%;"></div>
            </div>
            <a href="admin_availability.php" class="card-x-link">View bays <i data-lucide="arrow-right"></i></a>
        </div>
    </div>
</div>

<!-- Add Mechanic Modal -->
<div class="modal fade" id="mechanicModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-modal">
            <div class="modal-header glass-modal-header d-flex justify-content-between align-items-center">
                <h5 class="modal-title" style="font-weight:700; color:#111827;"><i data-lucide="user-plus" style="width:18px;height:18px; margin-right:6px;"></i> Register New Mechanic</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="dashboard_admin.php">
                <div class="glass-modal-body">
                    <input type="hidden" name="create_mechanic" value="1">
                    <div>
                        <label class="dash-form-label">Mechanic Full Name</label>
                        <input type="text" class="dash-form-control" name="name" required placeholder="Enter mechanic name">
                    </div>
                    <div>
                        <label class="dash-form-label">Specialties / Skills</label>
                        <small style="color: var(--text-muted); font-size: 0.75rem; display:block; margin-bottom: 0.4rem;">Select all specialties that apply to this mechanic.</small>
                        <?php if (empty($all_specialties)): ?>
                            <p class="text-danger" style="font-size:0.8rem;">No specialties found. Please configure the specialties table.</p>
                        <?php else: ?>
                            <div class="dash-check-grid">
                                <?php foreach ($all_specialties as $spec): ?>
                                    <label class="dash-form-check">
                                        <input type="checkbox" name="specialty_ids[]" value="<?= $spec['id'] ?>">
                                        <span><?= htmlspecialchars($spec['specialty_name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--card-border); padding:1rem 1.25rem;">
                    <button type="button" class="dash-btn ghost" data-bs-dismiss="modal" style="margin-right: auto;">Close</button>
                    <button type="submit" class="dash-btn primary"><i data-lucide="save"></i> Save Mechanic</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require 'admin_sidebar_footer.php'; ?>
<?php
// --- Build chart data arrays ---
$months9 = [];
$months6 = [];
for ($i = 8; $i >= 0; $i--) { $months9[] = date('Y-m', strtotime("-$i months")); }
for ($i = 5; $i >= 0; $i--) { $months6[] = date('Y-m', strtotime("-$i months")); }

$svcMap = array_column($monthlyServices ?? [], 'count', 'month');
$custMap = array_column($monthlyCustomers ?? [], 'count', 'month');
$trendMap = array_column($maintenanceTrends ?? [], 'count', 'month');

$overviewLabels = [];
$overviewServices = [];
$overviewCustomers = [];
foreach ($months9 as $m) {
    $overviewLabels[] = date('M', strtotime($m . '-01'));
    $overviewServices[] = (int)($svcMap[$m] ?? 0);
    $overviewCustomers[] = (int)($custMap[$m] ?? 0);
}

$trendLabels = [];
$trendData = [];
foreach ($months6 as $m) {
    $trendLabels[] = date('M', strtotime($m . '-01'));
    $trendData[] = (int)($trendMap[$m] ?? 0);
}

$pkgLabels = array_column(array_slice($packageDist ?? [], 0, 6), 'name');
$pkgData = array_map('intval', array_column(array_slice($packageDist ?? [], 0, 6), 'count'));
$pkgTotal = (int)($totalPackageServices ?? 0);
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof lucide !== 'undefined') lucide.createIcons();
    });

    // Theme-aware chart colors
    function chartTheme() {
        const dark = document.documentElement.classList.contains('dark-mode');
        return {
            tick: dark ? '#94a3b8' : '#6B7280',
            grid: dark ? 'rgba(255, 255, 255, 0.08)' : 'rgba(15, 23, 42, 0.06)',
            donutBorder: dark ? '#151f36' : '#ffffff',
            centerMain: dark ? '#e2e8f0' : '#0f172a',
            centerSub: dark ? '#94a3b8' : '#6B7280'
        };
    }
    let chartColors = chartTheme();
    const tickColor = chartColors.tick;
    const gridColor = chartColors.grid;

    // ===== Service Overview: bars (services) + line (customers) =====
    const overviewChart = new Chart(document.getElementById('overviewChart'), {
        data: {
            labels: <?= json_encode($overviewLabels) ?>,
            datasets: [
                {
                    type: 'bar',
                    label: 'Services',
                    data: <?= json_encode($overviewServices) ?>,
                    backgroundColor: 'rgba(59, 130, 246, 0.85)',
                    borderRadius: 5,
                    barPercentage: 0.55,
                    order: 2
                },
                {
                    type: 'line',
                    label: 'Customers',
                    data: <?= json_encode($overviewCustomers) ?>,
                    borderColor: '#FACC15',
                    backgroundColor: '#FACC15',
                    pointBackgroundColor: '#FACC15',
                    pointRadius: 4,
                    tension: 0.4,
                    order: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: tickColor, font: { size: 10 } }, grid: { display: false } },
                y: { ticks: { color: tickColor, precision: 0 }, grid: { color: gridColor }, beginAtZero: true }
            }
        }
    });

    // ===== Package Distribution donut with center text =====
    const centerText = {
        id: 'centerText',
        afterDraw(chart) {
            const meta = chart.getDatasetMeta(0);
            if (!meta.data[0]) return;
            const { x, y } = meta.data[0];
            const ctx = chart.ctx;
            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = "800 22px 'Plus Jakarta Sans', sans-serif";
            ctx.fillStyle = chartColors.centerMain;
            ctx.fillText('<?= $pkgTotal ?>', x, y - 8);
            ctx.font = "600 9px 'Plus Jakarta Sans', sans-serif";
            ctx.fillStyle = chartColors.centerSub;
            ctx.fillText('Total Services', x, y + 12);
            ctx.restore();
        }
    };

    const packageChart = new Chart(document.getElementById('packageChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($pkgLabels) ?>,
            datasets: [{
                data: <?= json_encode($pkgData) ?>,
                backgroundColor: ['#3b82f6', '#FACC15', '#10b981', '#8b5cf6', '#94a3b8', '#f97316'],
                borderWidth: 2,
                borderColor: chartColors.donutBorder
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: { legend: { display: false } }
        },
        plugins: [centerText]
    });

    // ===== Maintenance Trends line =====
    const trendsCtx = document.getElementById('trendsChart').getContext('2d');
    const trendGradient = trendsCtx.createLinearGradient(0, 0, 0, 190);
    trendGradient.addColorStop(0, 'rgba(59, 130, 246, 0.25)');
    trendGradient.addColorStop(1, 'rgba(59, 130, 246, 0.01)');

    const trendsChart = new Chart(trendsCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($trendLabels) ?>,
            datasets: [{
                label: 'Services',
                data: <?= json_encode($trendData) ?>,
                borderColor: '#3b82f6',
                backgroundColor: trendGradient,
                pointBackgroundColor: '#3b82f6',
                pointRadius: 4,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: tickColor, font: { size: 10 } }, grid: { display: false } },
                y: { ticks: { color: tickColor, precision: 0 }, grid: { color: gridColor }, beginAtZero: true }
            }
        }
    });

    // Re-theme charts when dark mode is toggled
    document.addEventListener('adminThemeChanged', function () {
        chartColors = chartTheme();
        [overviewChart, trendsChart].forEach(function (c) {
            c.options.scales.x.ticks.color = chartColors.tick;
            c.options.scales.y.ticks.color = chartColors.tick;
            c.options.scales.y.grid.color = chartColors.grid;
            c.update();
        });
        packageChart.data.datasets[0].borderColor = chartColors.donutBorder;
        packageChart.update();
    });
</script>
