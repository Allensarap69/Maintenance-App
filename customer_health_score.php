<?php
session_start();
require 'db.php';
require 'motorcycle_health_helper.php';

// Security check - only customers can access their own health scores
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    header("Location: index.php");
    exit;
}

$customer_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Customer';
$active_page = basename($_SERVER['PHP_SELF']);

// --- Mark recommendation as applied when scheduling from timeline ---
if (isset($_GET['mark_applied'], $_GET['vehicle_id']) && is_numeric($_GET['vehicle_id'])) {
    $v_id = (int) $_GET['vehicle_id'];
    try {
        $stmt = $pdo->prepare("
            UPDATE customer_notifications
            SET is_applied = 1
            WHERE customer_id = ? AND motorcycle_id = ? AND is_applied = 0
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$customer_id, $v_id]);
    } catch (PDOException $e) {
        error_log("Could not mark health notification as applied: " . $e->getMessage());
    }
    header("Location: book_service.php?vehicle_id=" . $v_id);
    exit;
}

// --- Fetch Customer's Motorcycles with Health Data ---
$motorcycles = [];
try {
    $stmt = $pdo->prepare("
        SELECT m.*, 
               (SELECT COUNT(*) FROM maintenance_history WHERE motorcycle_id = m.id) as maintenance_count,
               (SELECT MAX(service_date) FROM maintenance_history WHERE motorcycle_id = m.id) as last_service_date
        FROM motorcycles m
        WHERE m.user_id = ?
        ORDER BY m.brand ASC
    ");
    $stmt->execute([$customer_id]);
    $motorcycles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate health scores for each motorcycle
    foreach ($motorcycles as &$motorcycle) {
        $factors = [];
        $motorcycle['health_data'] = getMotorcycleDashboardData($motorcycle['id']);
        $motorcycle['health_score'] = calculateHealthScore($motorcycle, $factors);
        $motorcycle['factors'] = $factors;
        $motorcycle['warranty_status'] = getWarrantyStatus($motorcycle);
        $motorcycle['maintenance_schedule'] = getMaintenanceSchedule($motorcycle);

        // Save current health score to database for tracking
        saveHealthScore($motorcycle['id'], $motorcycle['health_score'], $factors);
    }
    unset($motorcycle);
} catch (PDOException $e) {
    error_log("Error fetching motorcycles: " . $e->getMessage());
}

// --- Fetch Customer's Admin Recommendations ---
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

$notifications = [];
try {
    $stmt = $pdo->prepare("
        SELECT n.id, n.customer_id, n.motorcycle_id, n.health_score, n.recommendation,
               n.is_read, n.is_applied, n.created_at, n.updated_at,
               m.brand, m.model, m.plate_number
        FROM customer_notifications n
        JOIN motorcycles m ON n.motorcycle_id = m.id
        INNER JOIN (
            SELECT MAX(id) AS max_id
            FROM customer_notifications
            WHERE customer_id = ? AND is_applied = 0
            GROUP BY motorcycle_id
        ) latest ON n.id = latest.max_id
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([$customer_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching notifications: " . $e->getMessage());
}

// --- Map Service Names to IDs for Admin Recommendations ---
$services = [];
$serviceNameToId = [];
try {
    $stmt = $pdo->query("SELECT id, service_name FROM services ORDER BY service_name");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($services as $s) {
        $serviceNameToId[$s['service_name']] = $s['id'];
    }
} catch (PDOException $e) {
    error_log("Error fetching services: " . $e->getMessage());
}

// --- Fetch Customer's Maintenance History for Timeline ---
$maintenance_history = [];
try {
    $stmt = $pdo->prepare("
        SELECT mh.*,
               m.brand, m.model, m.plate_number
        FROM maintenance_history mh
        JOIN motorcycles m ON mh.motorcycle_id = m.id
        WHERE mh.customer_id = ?
        ORDER BY mh.service_date DESC, mh.mileage DESC
    ");
    $stmt->execute([$customer_id]);
    $maintenance_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group maintenance records by motorcycle for per-vehicle timeline
    $history_by_moto = [];
    foreach ($maintenance_history as $record) {
        $history_by_moto[$record['motorcycle_id']][] = $record;
    }

    $service_plan = [
        1000 => 'First Service',
        3000 => 'Preventive Maintenance',
        6000 => 'Preventive Maintenance',
        9000 => 'Preventive Maintenance',
        12000 => 'Major Service',
        15000 => 'Preventive Maintenance',
        18000 => 'Major Service',
        21000 => 'Preventive Maintenance',
        24000 => 'Major Service',
    ];
} catch (PDOException $e) {
    error_log("Error fetching maintenance history: " . $e->getMessage());
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
    <title>Health Score | AutoCare Pro</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="fonts.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --bg-canvas: #f8fafc;
            --bg-card: #ffffff;
            --bg-card-hover: #f1f5f9;
            --border-color: rgba(0, 0, 0, 0.06);
            --border-highlight: rgba(0, 0, 0, 0.12);
            --text-main: #111827;
            --text-sub: #6b7280;
            --text-muted: #9ca3af;
            --accent-gold: #FACC15;
            --accent-green: #10b981;
            --accent-blue: #3b82f6;
            --accent-red: #ef4444;
            --accent-purple: #8b5cf6;
        }

        html, body {
            background-color: var(--bg-canvas) !important;
            font-family: 'Plus Jakarta Sans', sans-serif !important;
            color: var(--text-main) !important;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }

        .sidebar {
            font-family: 'Poppins', sans-serif !important;
        }

        .main-content {
            margin-left: 280px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background-color: var(--bg-canvas);
        }

        .top-bar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            padding: 18px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1020;
            border-bottom: 1px solid var(--border-color);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
        }

        .top-bar-title {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--text-main);
            margin: 0;
            letter-spacing: -0.02em;
        }

        .top-bar-user-avatar {
            width: 34px;
            height: 34px;
            background: #1e3a5f;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-weight: 800;
            font-size: 0.9rem;
        }

        .top-bar-user-dropdown {
            position: absolute;
            top: 110%;
            right: 0;
            width: 180px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 8px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            display: none;
            z-index: 100;
        }

        .top-bar-user.active .top-bar-user-dropdown {
            display: block;
        }

        .dropdown-header {
            padding: 8px 12px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 6px;
        }

        .dropdown-header-name {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-main);
        }

        .dropdown-header-role {
            font-size: 0.68rem;
            color: var(--text-muted);
        }

        .top-bar-user-dropdown .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-sub);
            border-radius: 8px;
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
        }

        .top-bar-user-dropdown .dropdown-item:hover {
            background: rgba(0, 0, 0, 0.04);
            color: var(--text-main);
        }

        .top-bar-user-dropdown .dropdown-item.danger {
            color: #ef4444;
            border-top: 1px solid var(--border-color);
        }

        .top-bar-user-dropdown .dropdown-item.danger:hover {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }

        .content-area {
            padding: 32px;
            max-width: 1400px;
            width: 100%;
            margin: 0 auto;
        }

        /* Metric Summary Cards */
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            height: 100%;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
        }

        .stat-card:hover {
            border-color: var(--border-highlight);
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

        .stat-icon-primary { background: rgba(59, 130, 246, 0.12); color: var(--accent-blue); }
        .stat-icon-success { background: rgba(16, 185, 129, 0.12); color: var(--accent-green); }
        .stat-icon-warning { background: rgba(250, 204, 21, 0.12); color: #EAB308; }
        .stat-icon-info { background: rgba(139, 92, 246, 0.12); color: var(--accent-purple); }

        .stat-value {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-main);
            line-height: 1.1;
            word-break: break-word;
        }

        .stat-label {
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-sub);
            margin-top: 2px;
            word-break: break-word;
        }

        .stat-card > div:last-child {
            min-width: 0;
            flex: 1;
        }

        /* Health Cards Layout */
        .health-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
        }

        .health-card .card-header-custom {
            padding: 10px 14px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
        }

        .motorcycle-title {
            font-size: 0.85rem;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 2px;
        }

        .motorcycle-subtitle {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--accent-gold);
            white-space: nowrap;
            flex-shrink: 0;
        }

        .card-body {
            padding: 16px;
        }

        /* Radial Score Indicator */
        .health-score-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 6px 0;
        }

        .health-score-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 800;
            color: #ffffff;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }

        .health-score-excellent { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .health-score-good { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .health-score-fair { background: linear-gradient(135deg, #FACC15 0%, #EAB308 100%); }
        .health-score-poor { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }

        .health-score-label {
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 6px;
            color: var(--text-sub);
        }

        /* Component Factor Display */
        .factor-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 12px;
        }

        .factor-icon {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
            background: rgba(0, 0, 0, 0.04);
            color: var(--text-main);
        }

        .factor-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.03em;
        }

        .factor-value {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .breakdown-box {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 10px;
        }

        .breakdown-title {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 6px;
            text-align: center;
        }

        .progress-bar-custom {
            height: 6px;
            background: rgba(0, 0, 0, 0.06);
            border-radius: 10px;
            overflow: hidden;
            margin-top: 4px;
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
            background: var(--accent-blue);
        }

        /* Status Callout Blocks */
        .status-block {
            padding: 8px 10px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid var(--border-color);
        }

        .status-block-title {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 2px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .status-block-value {
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-sub);
            line-height: 1.4;
        }

        /* Motorcycle List Navigation */
        .moto-list {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 10px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .moto-list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
        }

        .moto-list-item:hover {
            background: #f8fafc;
            border-color: var(--border-highlight);
        }

        .moto-list-item.active {
            background: #3b82f6;
            border-color: #3b82f6;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
            color: #ffffff;
        }

        .moto-list-title {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--text-main);
            word-break: break-word;
        }

        .moto-list-item.active .moto-list-title {
            color: #ffffff;
        }

        .moto-list-subtitle {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 600;
            word-break: break-word;
        }

        .moto-list-item.active .moto-list-subtitle {
            color: rgba(255, 255, 255, 0.9);
        }

        .moto-list-item > div:first-child {
            min-width: 0;
            flex: 1;
        }

        .moto-score-badge {
            font-size: 0.78rem;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 50px;
            color: #ffffff;
            min-width: 42px;
            text-align: center;
            flex-shrink: 0;
        }

        .moto-detail {
            display: none;
        }

        .moto-detail.active {
            display: block;
        }

        /* --- Refined Vertical Maintenance Timeline --- */
        .timeline-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px;
            height: 100%;
        }

        .timeline-wrapper {
            position: relative;
            padding-left: 20px;
            margin-top: 10px;
        }

        .timeline-wrapper::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 7px;
            bottom: 7px;
            width: 1px;
            background: linear-gradient(to bottom, #22C55E var(--progress, 0%), var(--border-color) var(--progress, 0%));
        }

        .timeline-item {
            position: relative;
            padding-bottom: 6px;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-dot {
            position: absolute;
            left: -18px;
            top: 2px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #ffffff;
            border: 2px solid var(--accent-blue);
            z-index: 2;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
        }

        .timeline-dot:hover {
            transform: scale(1.15);
        }

        .timeline-dot::after {
            content: '+';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 6px;
            color: var(--accent-blue);
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        .timeline-dot:hover::after,
        .timeline-item.expanded .timeline-dot::after {
            opacity: 1;
        }

        .timeline-item.completed .timeline-dot {
            border-color: var(--accent-green);
            background: var(--accent-green);
        }

        .timeline-item.completed .timeline-dot::after {
            color: #ffffff;
        }

        .timeline-item.past .timeline-dot {
            border-color: var(--accent-green);
            background: var(--accent-green);
        }

        .timeline-item.past .timeline-dot::after {
            color: #ffffff;
        }

        .timeline-item.current-target .timeline-dot {
            border-color: var(--accent-green);
            background: var(--accent-green);
        }

        .timeline-item.current-target .timeline-dot::after {
            color: #ffffff;
        }

        .timeline-item.scheduled .timeline-dot {
            border-color: var(--accent-gold);
            background: #ffffff;
        }

        .timeline-item.current .timeline-dot {
            border-color: var(--accent-green);
            background: var(--accent-green);
        }

        .timeline-item.current .timeline-dot::after {
            color: #ffffff;
        }

        .timeline-content {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 6px 8px;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
            font-size: 0.7rem;
        }

        .timeline-content:hover {
            border-color: var(--border-highlight);
        }

        .timeline-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2px;
        }

        .timeline-title {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .timeline-badge {
            font-size: 0.58rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: 1px 5px;
            border-radius: 50px;
        }
        button.timeline-badge {
            border: none;
            background: none;
            cursor: pointer;
            font: inherit;
            line-height: inherit;
            box-shadow: none;
        }
        button.timeline-badge:hover {
            opacity: 0.8;
        }

        .timeline-badge.completed {
            background: rgba(16, 185, 129, 0.12);
            color: #059669;
        }

        .timeline-badge.scheduled {
            background: rgba(250, 204, 21, 0.12);
            color: #EAB308;
        }

        .timeline-badge.past {
            background: rgba(16, 185, 129, 0.12);
            color: #059669;
        }

        .timeline-badge.current {
            background: rgba(17, 24, 39, 0.1);
            color: #111827;
        }

        .timeline-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.65rem;
            color: var(--text-muted);
            font-weight: 600;
        }

        .timeline-detail {
            display: none;
            margin-top: 4px;
            padding-top: 4px;
            border-top: 1px dashed var(--border-color);
            font-size: 0.65rem;
            color: var(--text-sub);
            line-height: 1.4;
        }

        .timeline-item.expanded .timeline-detail {
            display: block;
        }

        .timeline-detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1px;
        }

        .timeline-detail-row:last-child {
            margin-bottom: 0;
        }

        .timeline-detail-label {
            color: var(--text-muted);
            font-weight: 600;
        }

        .sidebar-toggle {
            background: transparent;
            border: none;
            color: var(--text-main);
            font-size: 1.5rem;
            display: none;
            cursor: pointer;
        }

        .empty-state {
            padding: 40px 20px;
            text-align: center;
            background: var(--bg-card);
            border: 1px dashed var(--border-color);
            border-radius: 18px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 10px;
            display: block;
            opacity: 0.4;
        }

        @media (max-width: 992px) {
            .main-content { margin-left: 0; }
            .content-area { padding: 20px; }
            .sidebar-toggle { display: block; }
        }

        /* Horizontal Maintenance Timeline */
        .maintenance-timeline .vehicle-header {
            background: #fff;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(0,0,0,0.04);
        }
        .maintenance-timeline .vehicle-title { font-size: 1rem; font-weight: 800; color: #0f172a; margin: 0; }
        .maintenance-timeline .vehicle-meta { color: #64748b; font-size: 0.85rem; margin-top: 4px; }
        .maintenance-timeline .vehicle-hero {
            width: 80px; height: 60px;
            background: linear-gradient(135deg, #e2e8f0 0%, #f8fafc 100%);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: #64748b;
            font-size: 2.2rem;
            overflow: hidden;
        }

        .maintenance-timeline .current-mileage {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
            color: #fff !important;
            border-radius: 16px;
            padding: 14px 24px;
            text-align: center;
            display: inline-block;
            margin: 0 auto 16px;
            box-shadow: 0 10px 24px rgba(0,0,0,0.2);
        }
        .maintenance-timeline .current-mileage .km {
            font-size: 1.6rem;
            font-weight: 800;
            color: #FFB800 !important;
            line-height: 1;
        }
        .maintenance-timeline .current-mileage .label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 4px;
            color: #fff !important;
        }

        .maintenance-timeline .timeline-wrapper { position: relative; }
        .maintenance-timeline .timeline-track {
            display: flex;
            gap: 16px;
            overflow-x: auto;
            padding: 30px 4px 16px;
            position: relative;
            min-height: 170px;
            align-items: flex-start;
        }
        .maintenance-timeline .timeline-track::before {
            content: '';
            position: absolute;
            top: 8px;
            left: 0;
            right: 0;
            height: 3px;
            background: #E0E0E0;
            border-radius: 2px;
            z-index: 1;
        }
        .maintenance-timeline .progress-fill {
            position: absolute;
            top: 8px;
            left: 0;
            height: 3px;
            background: #22C55E;
            border-radius: 2px;
            z-index: 2;
        }
        .maintenance-timeline .service-card {
            flex: 0 0 220px;
            background: #fff;
            border-radius: 14px;
            padding: 16px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.08);
            border: 1px solid rgba(0,0,0,0.04);
            position: relative;
            z-index: 2;
            margin-top: 24px;
        }
        .maintenance-timeline .service-card::before {
            content: '';
            position: absolute;
            top: -36px;
            left: 50%;
            transform: translateX(-50%);
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #CCCCCC;
            z-index: 3;
        }
        .maintenance-timeline .service-card.completed::before { background: #22C55E; }
        .maintenance-timeline .service-card.active::before {
            top: -39px;
            width: 14px;
            height: 14px;
            border: 2px solid #111827;
        }
        .maintenance-timeline .service-card.active.completed::before {
            background: #22C55E;
            box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.25);
        }
        .maintenance-timeline .service-card.active:not(.completed)::before {
            background: #CCCCCC;
            box-shadow: 0 0 0 4px rgba(156, 163, 175, 0.35);
        }
        .maintenance-timeline .service-card.active::after {
            content: '';
            position: absolute;
            top: -24px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
            border-top: 7px solid #9CA3AF;
            z-index: 3;
        }
        .maintenance-timeline .service-card.active.completed::after { border-top-color: #22C55E; }
        .maintenance-timeline .service-card .top {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;
        }
        .maintenance-timeline .service-card .mileage {
            font-size: 1.1rem; font-weight: 800; color: #0f172a;
        }
        .maintenance-timeline .service-card .type { font-size: 0.85rem; color: #1e293b; font-weight: 600; margin-bottom: 4px; }
        .maintenance-timeline .service-card .plate { font-size: 0.75rem; color: #64748b; margin-bottom: 10px; }
        .maintenance-timeline .service-card .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .maintenance-timeline .service-card .badge-completed {
            background: #DCFCE7;
            color: #15803D;
        }
        .maintenance-timeline .service-card .badge-pending {
            background: #F3F4F6;
            color: #6B7280;
        }
        @media (max-width: 576px) {
            .stat-card {
                padding: 12px;
                border-radius: 14px;
                gap: 10px;
            }
            .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 1.1rem;
                border-radius: 10px;
            }
            .stat-value {
                font-size: 1.2rem;
            }
            .stat-label {
                font-size: 0.7rem;
            }
            .moto-list-item {
                padding: 10px 12px;
                gap: 8px;
            }
            .moto-list-title {
                font-size: 0.8rem;
            }
            .moto-list-subtitle {
                font-size: 0.7rem;
            }
            .moto-score-badge {
                min-width: 36px;
                padding: 3px 8px;
                font-size: 0.72rem;
            }
        }

        /* Compact fit-to-screen */
        .health-card .card-header-custom { padding: 6px 10px; }
        .card-body { padding: 10px; }
        .health-score-circle {
            width: 64px;
            height: 64px;
            font-size: 1.5rem;
        }
        .health-score-label { font-size: 0.68rem; }
        .factor-item { padding: 5px 8px; gap: 6px; }
        .factor-icon {
            width: 28px;
            height: 28px;
            font-size: 0.8rem;
        }
        .factor-label { font-size: 0.65rem; }
        .factor-value { font-size: 0.75rem; }
        .breakdown-box { padding: 6px; }
        .breakdown-title { font-size: 0.62rem; margin-bottom: 4px; }
        .progress-bar-custom { height: 5px; margin-top: 2px; }
        .status-block { padding: 6px 8px; }
        .status-block-title { font-size: 0.62rem; }
        .status-block-value { font-size: 0.7rem; }
        .moto-list {
            background: transparent;
            border: none;
            padding: 4px;
            gap: 4px;
        }
        .moto-list-item {
            background: transparent;
            border: none;
            padding: 4px 8px;
        }
        .moto-list-item:hover,
        .moto-list-item.active {
            background: #3b82f6;
            color: #fff;
            border-color: #3b82f6;
            border-radius: 8px;
        }
        .moto-list-title { font-size: 0.78rem; }
        .moto-list-subtitle { font-size: 0.65rem; color: #000; }
        .moto-score-badge {
            font-size: 0.65rem;
            padding: 2px 6px;
            min-width: 32px;
        }

        /* Smaller stat cards */
        .stat-card {
            padding: 10px 12px;
            border-radius: 12px;
            gap: 8px;
        }
        .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            font-size: 1rem;
        }
        .stat-value { font-size: 1.2rem; }
        .stat-label { font-size: 0.68rem; }
        .timeline-badge { font-size: 0.48rem; }
        .timeline-badge.scheduled {
            background: #3b82f6 !important;
            color: #fff !important;
        }
        button.timeline-badge.scheduled:disabled {
            opacity: 0.65 !important;
            cursor: not-allowed !important;
        }

        /* Scrollable vertical maintenance history with hidden scrollbar */
        .maintenance-timeline .timeline-wrapper {
            max-height: calc(100vh - 280px);
            overflow-y: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .maintenance-timeline .timeline-wrapper::-webkit-scrollbar {
            display: none;
        }
    </style>
</head>
<body>

<?php include 'customer_sidebar.php'; ?>

<div class="main-content">
    <!-- Top Bar -->
    <div class="top-bar">
        <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="bi bi-list"></i>
            </button>
            <h1 class="top-bar-title">Motorcycle Health Analytics</h1>
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
        <?php if (!empty($notifications)): ?>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="alert alert-warning">
                    <h6 class="fw-bold"><i class="bi bi-bell me-2"></i>Admin Maintenance Recommendations</h6>
                    <?php foreach ($notifications as $n): ?>
                    <?php
                    $rec = $n['recommendation'] ?? '';
                    $items_part = '';
                    if (strpos($rec, 'Recommended maintenance: ') === 0) {
                        $rec = substr($rec, strlen('Recommended maintenance: '));
                        $parts = explode(' | Notes: ', $rec, 2);
                        $items_part = $parts[0];
                    }
                    $recommended_names = array_filter(array_map('trim', explode(',', $items_part)));
                    $recommended_ids = [];
                    foreach ($recommended_names as $name) {
                        if (isset($serviceNameToId[$name])) {
                            $recommended_ids[] = $serviceNameToId[$name];
                        }
                    }
                    ?>
                    <div class="d-flex justify-content-between align-items-start border-bottom py-2">
                        <div>
                            <strong><?= htmlspecialchars($n['brand'] . ' ' . $n['model']) ?> (<?= htmlspecialchars($n['plate_number']) ?>)</strong><br>
                            <small>Health Score: <?= (int) $n['health_score'] ?>% — <?= htmlspecialchars($n['recommendation']) ?></small>
                        </div>
                        <form method="GET" action="book_service.php" class="mt-1">
                            <input type="hidden" name="vehicle_id" value="<?= (int) $n['motorcycle_id'] ?>">
                            <?php foreach ($recommended_ids as $sid): ?>
                            <input type="hidden" name="service_id[]" value="<?= (int) $sid ?>">
                            <?php endforeach; ?>
                            <button type="submit" class="btn btn-sm btn-success">
                                <i class="bi bi-wrench me-1"></i> Apply
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Overview Summary Row -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-primary"><i class="bi bi-motorcycle"></i></div>
                    <div>
                        <div class="stat-value"><?= count($motorcycles) ?></div>
                        <div class="stat-label">Registered Vehicles</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-success"><i class="bi bi-heart-pulse"></i></div>
                    <div>
                        <div class="stat-value">
                            <?= !empty($motorcycles) ? round(array_sum(array_column($motorcycles, 'health_score')) / count($motorcycles)) : 0 ?>%
                        </div>
                        <div class="stat-label">Average Health Rating</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-warning"><i class="bi bi-exclamation-triangle"></i></div>
                    <div>
                        <div class="stat-value">
                            <?= count(array_filter($motorcycles, function($m) { 
                                return isset($m['maintenance_schedule']['is_overdue']) && $m['maintenance_schedule']['is_overdue']; 
                            })) ?>
                        </div>
                        <div class="stat-label">Overdue Maintenance</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-info"><i class="bi bi-shield-check"></i></div>
                    <div>
                        <div class="stat-value">
                            <?= count(array_filter($motorcycles, function($m) { 
                                return !empty($m['warranty_status']['is_valid']); 
                            })) ?>
                        </div>
                        <div class="stat-label">Active Warranties</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Workspace Row: Selector & Combined Vehicle Card -->
        <?php if (empty($motorcycles)): ?>
            <div class="row g-4">
                <div class="col-12">
                    <div class="empty-state mb-4">
                        <i class="bi bi-motorcycle"></i>
                        <h5 class="fw-bold text-dark mb-1">No Vehicles Registered</h5>
                        <p class="mb-0">You currently have no motorcycles associated with your account.</p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <!-- Left Column: Vehicle Selector -->
                <div class="col-lg-4">
                    <div class="mb-3">
                        <h4 class="fw-bold m-0" style="font-size: 1.1rem;">Your Vehicles</h4>
                        <p class="text-muted small m-0">Select a motorcycle to view details</p>
                    </div>
                    <div class="moto-list">
                        <?php foreach ($motorcycles as $index => $motorcycle): ?>
                            <?php
                            $health_score = round($motorcycle['health_score']);

                            if ($health_score >= 80) {
                                $score_class = 'health-score-excellent';
                            } elseif ($health_score >= 60) {
                                $score_class = 'health-score-good';
                            } elseif ($health_score >= 40) {
                                $score_class = 'health-score-fair';
                            } else {
                                $score_class = 'health-score-poor';
                            }
                            ?>
                            <div class="moto-list-item <?= $index === 0 ? 'active' : '' ?>" data-index="<?= $index ?>">
                                <div>
                                    <div class="moto-list-title"><?= htmlspecialchars($motorcycle['brand'] . ' ' . $motorcycle['model']) ?></div>
                                    <div class="moto-list-subtitle">Plate: <?= htmlspecialchars($motorcycle['plate_number']) ?></div>
                                </div>
                                <div class="moto-score-badge <?= $score_class ?>"><?= $health_score ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Right Column: Combined Health & Maintenance Card -->
                <div class="col-lg-8">
                    <?php foreach ($motorcycles as $index => $motorcycle): ?>
                        <?php 
                        $health_score = round($motorcycle['health_score']);
                        
                        if ($health_score >= 80) {
                            $condition = 'Excellent Condition';
                            $score_class = 'health-score-excellent';
                        } elseif ($health_score >= 60) {
                            $condition = 'Good Condition';
                            $score_class = 'health-score-good';
                        } elseif ($health_score >= 40) {
                            $condition = 'Fair Condition';
                            $score_class = 'health-score-fair';
                        } else {
                            $condition = 'Requires Attention';
                            $score_class = 'health-score-poor';
                        }
                        
                        $schedule = $motorcycle['maintenance_schedule'];
                        $warranty = $motorcycle['warranty_status'];
                        $factors = $motorcycle['factors'] ?? [];
                        $age = !empty($motorcycle['purchase_date']) ? (new DateTime())->diff(new DateTime($motorcycle['purchase_date']))->y : 0;
                        $mileage = $motorcycle['current_mileage'] ?? 0;
                        $moto_history = $history_by_moto[$motorcycle['id']] ?? [];

                        $current = (int) $mileage;
                        $history = $moto_history;
                        $plate = $motorcycle['plate_number'];
                        $timeline = [];
                        foreach ($history as $h) {
                            $timeline[] = [
                                'mileage' => (int) $h['mileage'],
                                'label' => htmlspecialchars($h['service_type']),
                                'status' => 'completed',
                                'date' => $h['service_date'],
                                'plate' => $h['plate_number'],
                                'mechanic_remarks' => !empty($h['mechanic_remarks']) ? htmlspecialchars($h['mechanic_remarks']) : null,
                                'parts_replaced' => !empty($h['parts_replaced']) ? htmlspecialchars($h['parts_replaced']) : null,
                            ];
                        }
                        $recorded_mileages = array_map('intval', array_column($history, 'mileage'));
                        foreach ($service_plan as $km => $label) {
                            if (!in_array($km, $recorded_mileages)) {
                                $timeline[] = [
                                    'mileage' => $km,
                                    'label' => $label,
                                    'status' => $km >= $current ? 'upcoming' : 'missed',
                                    'date' => null,
                                    'plate' => $plate,
                                    'mechanic_remarks' => null,
                                    'parts_replaced' => null,
                                ];
                            }
                        }
                        $existing_mileages = array_map('intval', array_column($timeline, 'mileage'));
                        if (!in_array($current, $existing_mileages)) {
                            $timeline[] = [
                                'mileage' => $current,
                                'label' => 'Current Mileage',
                                'status' => 'current',
                                'date' => null,
                                'plate' => $plate,
                                'mechanic_remarks' => null,
                                'parts_replaced' => null,
                            ];
                        }
                        ?>
                        
                        <div class="moto-detail <?= $index === 0 ? 'active' : '' ?>" data-index="<?= $index ?>">
                            <div class="health-card">
                                <div class="card-header-custom">
                                    <div class="motorcycle-title">
                                        <?= htmlspecialchars($motorcycle['brand'] . ' ' . $motorcycle['model']) ?>
                                    </div>
                                    <div class="motorcycle-subtitle">
                                        Plate: <?= htmlspecialchars($motorcycle['plate_number']) ?> • Model Year: <?= $motorcycle['year_model'] ?>
                                    </div>
                                </div>
                                
                                <div class="card-body" style="padding: 10px;">
                                    <div class="row g-2">
                                        <!-- Health Details -->
                                        <div class="col-lg-6 col-12">
                                            <!-- Health Score Circle -->
                                            <div class="health-score-container">
                                                <div class="health-score-circle <?= $score_class ?>">
                                                    <?= $health_score ?>
                                                </div>
                                                <div class="health-score-label"><?= $condition ?></div>
                                            </div>
                                            
                                            <!-- Key Factors -->
                                            <div class="d-flex flex-column gap-2">
                                                <div class="factor-item">
                                                    <div class="factor-icon"><i class="bi bi-calendar3"></i></div>
                                                    <div>
                                                        <div class="factor-label">Vehicle Age</div>
                                                        <div class="factor-value"><?= $age ?> Years Old</div>
                                                    </div>
                                                </div>
                                                <div class="factor-item">
                                                    <div class="factor-icon"><i class="bi bi-speedometer2"></i></div>
                                                    <div>
                                                        <div class="factor-label">Recorded Mileage</div>
                                                        <div class="factor-value"><?= number_format($mileage, 2) ?> km</div>
                                                    </div>
                                                </div>
                                                <div class="factor-item">
                                                    <div class="factor-icon"><i class="bi bi-wrench-adjustable"></i></div>
                                                    <div>
                                                        <div class="factor-label">Servicing Frequency</div>
                                                        <div class="factor-value"><?= isset($motorcycle['maintenance_count']) ? $motorcycle['maintenance_count'] : 0 ?> Logged Services</div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Weighted Health Breakdown -->
                                            <div class="breakdown-box mt-2">
                                                <div class="breakdown-title">Health Score Breakdown</div>
                                                <div class="d-flex flex-column gap-2">
                                                    <div>
                                                        <div class="d-flex justify-content-between small text-sub" style="font-size: 0.72rem;">
                                                            <span>Maintenance Logs (30%)</span>
                                                            <span class="fw-bold text-dark"><?= $factors['maintenance_score'] ?? 0 ?>/100</span>
                                                        </div>
                                                        <div class="progress-bar-custom">
                                                            <div class="progress-fill" style="width: <?= min(100, max(0, $factors['maintenance_score'] ?? 0)) ?>%;"></div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <div class="d-flex justify-content-between small text-sub" style="font-size: 0.72rem;">
                                                            <span>Mileage Wear (20%)</span>
                                                            <span class="fw-bold text-dark"><?= $factors['mileage_score'] ?? 0 ?>/100</span>
                                                        </div>
                                                        <div class="progress-bar-custom">
                                                            <div class="progress-fill" style="width: <?= min(100, max(0, $factors['mileage_score'] ?? 0)) ?>%; background: var(--accent-gold);"></div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <div class="d-flex justify-content-between small text-sub" style="font-size: 0.72rem;">
                                                            <span>Physical Inspection (35%)</span>
                                                            <span class="fw-bold text-dark"><?= $factors['inspection_score'] ?? 0 ?>/100</span>
                                                        </div>
                                                        <div class="progress-bar-custom">
                                                            <div class="progress-fill" style="width: <?= min(100, max(0, $factors['inspection_score'] ?? 0)) ?>%; background: var(--accent-green);"></div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <div class="d-flex justify-content-between small text-sub" style="font-size: 0.72rem;">
                                                            <span>Overdue Penalty (15%)</span>
                                                            <span class="fw-bold text-dark"><?= $factors['overdue_score'] ?? 0 ?>/100</span>
                                                        </div>
                                                        <div class="progress-bar-custom">
                                                            <div class="progress-fill" style="width: <?= min(100, max(0, $factors['overdue_score'] ?? 0)) ?>%; background: var(--accent-purple);"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Maintenance Schedule Block -->
                                            <div class="status-block mt-2">
                                                <div class="status-block-title"><i class="bi bi-clock-history"></i> Next Service Schedule</div>
                                                <div class="status-block-value">
                                                    <?php if (isset($schedule['next_date']) && $schedule['next_date']): ?>
                                                        <div><?= date('F j, Y', strtotime($schedule['next_date'])) ?></div>
                                                        <div style="font-size:0.75rem;" class="mt-1">
                                                            <?= $schedule['is_overdue'] 
                                                                ? '<span class="text-danger fw-bold"><i class="bi bi-exclamation-circle me-1"></i>Overdue by ' . ($schedule['days_overdue'] ?? 0) . ' days</span>' 
                                                                : '<span class="text-success fw-bold"><i class="bi bi-check-circle me-1"></i>' . ($schedule['days_until'] ?? 'N/A') . ' days remaining</span>' ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">No upcoming maintenance scheduled</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <!-- Warranty Block -->
                                            <div class="status-block mt-2">
                                                <div class="status-block-title"><i class="bi bi-shield-check"></i> Warranty Coverage</div>
                                                <div class="status-block-value">
                                                    <?= !empty($warranty['is_valid']) 
                                                        ? '<span class="text-success fw-bold"><i class="bi bi-check-circle me-1"></i>Valid until ' . date('M j, Y', strtotime($warranty['end_date'])) . '</span>' 
                                                        : '<span class="text-muted">No active warranty plan</span>' ?>
                                                </div>
                                            </div>
                                            
                                            <!-- Action Recommendation Block -->
                                            <div class="status-block mt-2">
                                                <div class="status-block-title"><i class="bi bi-lightbulb"></i> Action Plan</div>
                                                <div class="status-block-value">
                                                    <?php if ($health_score >= 80): ?>
                                                        Vehicle is in optimal health. Continue standard check-ups and routine maintenance.
                                                    <?php elseif ($health_score >= 60): ?>
                                                        Vehicle status is stable. Monitor component wear and consider booking upcoming checkups.
                                                    <?php elseif ($health_score >= 40): ?>
                                                        Requires servicing soon. Inspect key components to avoid unexpected breakdown.
                                                    <?php else: ?>
                                                        Immediate inspection required. Critical maintenance delays may affect safety and performance.
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Maintenance History Timeline -->
                                        <div class="col-lg-6 col-12">
                                            <h6 class="fw-bold mb-2" style="font-size: 0.9rem;">Maintenance History</h6>
                                            <div class="maintenance-timeline">
                                                <?php
                                                $displayTimeline = $timeline;
                                                usort($displayTimeline, fn($a, $b) => $a['mileage'] <=> $b['mileage']);
                                                ?>
                                                <?php
                                                $totalItems = count($displayTimeline);
                                                $currentIndex = -1;
                                                foreach ($displayTimeline as $i => $item) {
                                                    if ((int) $item['mileage'] <= $current) {
                                                        $currentIndex = $i;
                                                    }
                                                }
                                                $progressPercent = ($totalItems <= 1 || $currentIndex < 0) ? 0 : min(100, (($currentIndex + 0.5) / ($totalItems - 1)) * 100);

                                                $stmt = $pdo->prepare("SELECT recommendation FROM customer_notifications WHERE customer_id = ? AND motorcycle_id = ? AND is_applied = 1");
                                                $stmt->execute([$customer_id, $motorcycle['id']]);
                                                $appliedNotifications = $stmt->fetchAll(PDO::FETCH_COLUMN);

                                                $nextMileage = null;
                                                foreach ($displayTimeline as $it) {
                                                    if ($it['status'] !== 'upcoming') continue;
                                                    $isApplied = false;
                                                    foreach ($appliedNotifications as $rec) {
                                                        if (stripos($rec, $it['label']) !== false) {
                                                            $isApplied = true;
                                                            break;
                                                        }
                                                    }
                                                    if (!$isApplied) {
                                                        $nextMileage = $it['mileage'];
                                                        break;
                                                    }
                                                }
                                                ?>
                                                <div class="timeline-wrapper" style="--progress: <?= $progressPercent ?>%">
                                                    <?php foreach ($displayTimeline as $item): ?>
                                                        <?php
                                                        $status = $item['status'];
                                                        $applied = false;
                                                        if ($status === 'upcoming' && !empty($appliedNotifications)) {
                                                            foreach ($appliedNotifications as $rec) {
                                                                if (stripos($rec, $item['label']) !== false) {
                                                                    $applied = true;
                                                                    break;
                                                                }
                                                            }
                                                        }
                                                        if ($applied || $status === 'missed') {
                                                            $status = 'completed';
                                                        }
                                                        if ($status === 'completed') {
                                                            $status_label = 'Complete';
                                                            $status_class = 'completed';
                                                        } elseif ($status === 'upcoming') {
                                                            $status_label = 'Scheduled';
                                                            $status_class = 'scheduled';
                                                        } elseif ($status === 'missed') {
                                                            $status_label = 'Scheduled';
                                                            $status_class = 'past';
                                                        } elseif ($status === 'current') {
                                                            $status_label = 'Current';
                                                            $status_class = 'current';
                                                        } else {
                                                            $status_label = 'Pending';
                                                            $status_class = 'scheduled';
                                                        }
                                                        $formatted_date = !empty($item['date']) ? date('M j, Y', strtotime($item['date'])) : 'N/A';
                                                        $remarks = $item['mechanic_remarks'];
                                                        $parts = $item['parts_replaced'];
                                                        $has_details = !empty($remarks) || !empty($parts);
                                                        $extraClass = ($status_class === 'scheduled' && (int) $item['mileage'] === $current) ? ' current-target' : '';
                                                        ?>
                                                        <div class="timeline-item <?= $status_class ?><?= $extraClass ?>">
                                                            <div class="timeline-dot" title="Click for details"></div>
                                                            <div class="timeline-content">
                                                                <div class="timeline-header">
                                                                    <span class="timeline-title"><?= $item['label'] ?></span>
                                                                    <?php if ($status_class === 'scheduled'): ?>
    <?php if ($nextMileage !== null && (int) $item['mileage'] === (int) $nextMileage): ?>
        <button type="button" class="timeline-badge <?= $status_class ?>" onclick="window.location.href='customer_health_score.php?mark_applied=1&vehicle_id=<?= (int) $motorcycle['id'] ?>'">
            <?= $status_label ?>
        </button>
    <?php else: ?>
        <button type="button" class="timeline-badge <?= $status_class ?>" disabled style="opacity: 0.5; cursor: not-allowed;">
            <?= $status_label ?>
        </button>
    <?php endif; ?>
<?php else: ?>
<span class="timeline-badge <?= $status_class ?>"><?= $status_label ?></span>
<?php endif; ?>
                                                                </div>
                                                                <div class="timeline-meta">
                                                                    <span><i class="bi bi-speedometer2 me-1"></i><?= number_format($item['mileage']) ?> km</span>
                                                                    <span><?= htmlspecialchars($item['plate'] ?? '') ?></span>
                                                                </div>
                                                                <div class="mt-1 text-muted" style="font-size:0.65rem;">
                                                                    <i class="bi bi-calendar-event me-1"></i><?= $formatted_date ?>
                                                                </div>
                                                                <div class="timeline-detail">
                                                                    <div class="timeline-detail-row">
                                                                        <span class="timeline-detail-label">Service</span>
                                                                        <span><?= $item['label'] ?></span>
                                                                    </div>
                                                                    <div class="timeline-detail-row">
                                                                        <span class="timeline-detail-label">Mileage</span>
                                                                        <span><?= number_format($item['mileage']) ?> km</span>
                                                                    </div>
                                                                    <div class="timeline-detail-row">
                                                                        <span class="timeline-detail-label">Plate</span>
                                                                        <span><?= htmlspecialchars($item['plate'] ?? '') ?></span>
                                                                    </div>
                                                                    <div class="timeline-detail-row">
                                                                        <span class="timeline-detail-label">Date</span>
                                                                        <span><?= $formatted_date ?></span>
                                                                    </div>
                                                                    <?php if (!empty($remarks)): ?>
                                                                    <div class="timeline-detail-row" style="align-items:flex-start; flex-direction:column;">
                                                                        <span class="timeline-detail-label">Remarks</span>
                                                                        <span><?= $remarks ?></span>
                                                                    </div>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($parts)): ?>
                                                                    <div class="timeline-detail-row" style="align-items:flex-start; flex-direction:column;">
                                                                        <span class="timeline-detail-label">Parts Replaced</span>
                                                                        <span><?= $parts ?></span>
                                                                    </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

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
            
            document.addEventListener('click', function(e) {
                if (!topBarUser.contains(e.target)) {
                    topBarUser.classList.remove('active');
                }
            });
        }
    });

    function confirmLogout() {
        Swal.fire({
            title: 'Confirm Logout',
            text: "Are you sure you want to log out of your account?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Log Out',
            cancelButtonText: 'Cancel',
            background: '#ffffff',
            color: '#111827'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'logout.php';
            }
        });
    }

    // Motorcycle list selection handler
    document.addEventListener('DOMContentLoaded', function() {
        const listItems = document.querySelectorAll('.moto-list-item');
        const details = document.querySelectorAll('.moto-detail');

        listItems.forEach(item => {
            item.addEventListener('click', function() {
                const index = this.getAttribute('data-index');

                listItems.forEach(li => li.classList.remove('active'));
                this.classList.add('active');

                details.forEach(panel => {
                    panel.classList.remove('active');
                    if (panel.getAttribute('data-index') === index) {
                        panel.classList.add('active');
                    }
                });
            });
        });

        document.querySelectorAll('.timeline-dot').forEach(dot => {
            dot.addEventListener('click', function(e) {
                e.stopPropagation();
                const item = this.closest('.timeline-item');
                const wasExpanded = item.classList.contains('expanded');

                // Optional: close other expanded items in the same timeline
                const wrapper = this.closest('.timeline-wrapper');
                wrapper.querySelectorAll('.timeline-item.expanded').forEach(i => i.classList.remove('expanded'));

                if (!wasExpanded) {
                    item.classList.add('expanded');
                }
            });
        });
    });
</script>
</body>
</html>
