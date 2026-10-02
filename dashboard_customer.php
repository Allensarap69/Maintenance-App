<?php
session_start();
require 'db.php';
require 'motorcycle_health_helper.php';
require 'notification_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    header("Location: index.php"); 
    exit;
}

$username = $_SESSION['username'] ?? 'Customer';
$user_id = $_SESSION['user_id'] ?? 0;
$last_view_time = $_SESSION['last_booking_view'] ?? 0;
$active_page = basename($_SERVER['PHP_SELF']);

// --- Fetch Customer Statistics ---
$pendingBookings = 0;
$approvedBookings = 0;
$totalBookings = 0;

if ($user_id) {
    try {
        $stmtPending = $pdo->prepare("
            SELECT COUNT(id) FROM bookings 
            WHERE user_id = ? AND status IN ('', 'pending', 'deposit_submitted') 
            AND schedule_date >= CURDATE()
        ");
        $stmtPending->execute([$user_id]);
        $pendingBookings = $stmtPending->fetchColumn();

        $stmtApproved = $pdo->prepare("
            SELECT COUNT(id) FROM bookings 
            WHERE user_id = ? AND status = 'accepted' 
            AND schedule_date >= CURDATE()
        ");
        $stmtApproved->execute([$user_id]);
        $approvedBookings = $stmtApproved->fetchColumn();
        
        $stmtTotal = $pdo->prepare("SELECT COUNT(id) FROM bookings WHERE user_id = ?");
        $stmtTotal->execute([$user_id]);
        $totalBookings = $stmtTotal->fetchColumn();

    } catch (PDOException $e) {}
}

$totalUpcoming = $pendingBookings + $approvedBookings;
$show_badge = $totalUpcoming > 0;
if (isset($_SESSION['last_booking_view']) && (time() - $_SESSION['last_booking_view'] < 5)) {
    $show_badge = false; 
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

// --- Fetch Customer Motorcycles with Dashboard Data ---
$customerMotorcycles = [];
if ($user_id) {
    $customerMotorcycles = getCustomerMotorcyclesDashboard($user_id);
}

// --- Service history per motorcycle (for Service Timeline) ---
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
$recentServicesMap = [];
if (!empty($customerMotorcycles)) {
    try {
        $stmtRS = $pdo->prepare("
            SELECT service_date, service_type, mechanic_remarks, parts_replaced, mileage
            FROM maintenance_history
            WHERE motorcycle_id = ?
            ORDER BY mileage ASC, service_date ASC
        ");
        foreach ($customerMotorcycles as $md) {
            $mid = (int) $md['motorcycle']['id'];
            $stmtRS->execute([$mid]);
            $recentServicesMap[$mid] = $stmtRS->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {}
}

// --- Next upcoming appointment ---
$upcomingBooking = null;
if ($user_id) {
    try {
        $stmtUB = $pdo->prepare("
            SELECT b.id, b.schedule_date, b.schedule_start_time, b.schedule_end_time, b.service_ids, b.status, b.total_price,
                   m.brand, m.model, m.plate_number
            FROM bookings b
            LEFT JOIN motorcycles m ON b.vehicle_id = m.id
            WHERE b.user_id = ? AND b.schedule_date >= CURDATE()
              AND b.status IN ('', 'pending', 'unassigned', 'assigned', 'accepted', 'deposit_submitted')
            ORDER BY b.schedule_date, b.schedule_start_time
            LIMIT 1
        ");
        $stmtUB->execute([$user_id]);
        $upcomingBooking = $stmtUB->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($upcomingBooking) {
            $upcomingBooking['service_names'] = 'General Service';
            $ids = json_decode($upcomingBooking['service_ids'] ?? '[]', true);
            if (is_array($ids) && $ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $s = $pdo->prepare("SELECT service_name FROM services WHERE id IN ($ph)");
                $s->execute($ids);
                $names = $s->fetchAll(PDO::FETCH_COLUMN);
                if ($names) $upcomingBooking['service_names'] = implode(' • ', $names);
            }
        }
    } catch (PDOException $e) {}
}

// --- Maintenance alerts across all motorcycles ---
$alerts = [];
foreach ($customerMotorcycles as $md) {
    $m = $md['motorcycle'];
    $mn = $md['maintenance'] ?? [];
    $insp = $md['latest_inspection'] ?? null;
    $bikeName = trim(($m['brand'] ?? '') . ' ' . ($m['model'] ?? ''));
    if (!empty($mn['is_overdue'])) {
        $alerts[] = ['icon' => 'bi-exclamation-triangle-fill', 'cls' => 'red',
            'title' => 'Service Overdue — ' . $bikeName,
            'sub' => ($mn['days_overdue'] ?? 0) . ' days past due. Book a service now.'];
    } elseif (isset($mn['days_until']) && $mn['days_until'] !== null && $mn['days_until'] <= 30) {
        $alerts[] = ['icon' => 'bi-droplet-fill', 'cls' => 'amber',
            'title' => 'Service Due Soon — ' . $bikeName,
            'sub' => 'Next service in ' . $mn['days_until'] . ' days' . (!empty($mn['next_date']) ? ' (' . date('M j, Y', strtotime($mn['next_date'])) . ')' : '') . '.'];
    }
    if ($insp) {
        foreach (['engine' => 'Engine', 'brakes' => 'Brakes', 'tires' => 'Tires', 'battery' => 'Battery', 'lights' => 'Lights', 'suspension' => 'Suspension', 'fluids' => 'Oil Level'] as $k => $label) {
            $v = $insp[$k] ?? 'Good';
            if ($v !== 'Good') {
                $alerts[] = ['icon' => 'bi-gear-fill', 'cls' => $v === 'Fair' ? 'amber' : 'red',
                    'title' => "$label Check — $bikeName",
                    'sub' => "$label condition: $v. Consider inspection soon."];
            }
        }
    }
}
$alerts = array_slice($alerts, 0, 5);
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
    <title>My Dashboard | AutoCare Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="fonts.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <style>
        :root {
            --primary-color: #0f172a;
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
            --accent-color: #FACC15;
            --accent-gradient: linear-gradient(135deg, #FACC15 0%, #3b82f6 100%);
            --bg-light: #f8fafc;
            --text-dark: #1e293b;
            --text-light: #64748b;
            --white: #ffffff;
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --success: #10b981;
            --warning: #FACC15;
            --info: #3b82f6;
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-light);
            scroll-behavior: smooth;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            display: flex;
            min-height: 100vh;
        }

        /* --- Copy Protection --- */
        .protected-content {
            -webkit-user-select: text;
            -moz-user-select: text;
            -ms-user-select: text;
            user-select: text;
        }

        /* --- Modern Navigation --- */
        .navbar-custom {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #0f172a 100%);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.3);
            border-bottom: 2px solid rgba(250, 204, 21, 0.3);
            z-index: 1030;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            padding: 15px 0;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
        }

        /* --- Main Content Area --- */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 0;
            min-height: 100vh;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .main-content.expanded {
            margin-left: 80px;
        }

        .content-area {
            padding: 30px;
            overflow-y: auto;
            flex: 1;
            min-height: 0;
        }

        .top-bar {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
            padding: 15px 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1020;
            border-bottom: 2px solid rgba(250, 204, 21, 0.3);
        }

        .top-bar-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: white;
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

        .sidebar-toggle {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        }

        .sidebar-toggle:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* --- Mobile Responsive --- */
        .sidebar-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--text-dark);
            font-size: 1.5rem;
            cursor: pointer;
        }

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .sidebar-toggle {
                display: block;
            }
        }



        /* --- Hero Welcome Section --- */
        .hero-welcome {
            background: linear-gradient(135deg, #1e3a5f 0%, #0f172a 50%, #1e293b 100%);
            position: relative;
            padding: 40px 30px 50px;
            overflow: hidden;
            color: white;
            border-radius: 20px;
            margin-bottom: 30px;
        }

        .hero-welcome::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 10% 20%, rgba(250, 204, 21, 0.2) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(59, 130, 246, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(255, 255, 255, 0.05) 0%, transparent 50%);
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .welcome-title {
            font-size: clamp(2rem, 5vw, 3rem);
            font-weight: 700;
            margin-bottom: 15px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
        }

        .welcome-title span {
            background: linear-gradient(135deg, #FACC15 0%, #FDE047 50%, #FACC15 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 2px 4px rgba(250, 204, 21, 0.3));
        }

        .welcome-subtitle {
            font-size: 1.1rem;
            color: rgba(255, 255, 255, 0.8);
        }

        /* --- Stats Cards Section --- */
        .stats-section {
            margin-top: 30px;
            position: relative;
            z-index: 10;
            padding: 0;
            background: transparent;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            transition: transform 0.4s ease, box-shadow 0.4s ease, opacity 0.4s ease, visibility 0.4s ease, max-height 0.4s ease;
            position: relative;
            overflow: hidden;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            transition: transform 0.4s ease;
        }

        .stat-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12);
            color: inherit;
        }

        .stat-card.primary::before { background: var(--primary-gradient); }
        .stat-card.success::before { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .stat-card.warning::before { background: var(--accent-gradient); }
        .stat-card.info::before { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }

        .stat-icon-wrapper {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            font-size: 1.5rem;
        }

        .stat-card.primary .stat-icon-wrapper { background: rgba(15, 23, 42, 0.1); color: var(--primary-color); }
        .stat-card.success .stat-icon-wrapper { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stat-card.warning .stat-icon-wrapper { background: rgba(250, 204, 21, 0.1); color: var(--accent-color); }
        .stat-card.info .stat-icon-wrapper { background: rgba(59, 130, 246, 0.1); color: var(--info); }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--text-dark);
        }

        .stat-label {
            font-size: 0.95rem;
            color: var(--text-light);
            font-weight: 500;
        }

        .stat-meta {
            display: flex;
            gap: 15px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #f1f5f9;
            font-size: 0.85rem;
        }

        .stat-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .notification-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            font-weight: 700;
            animation: pulse-badge 2s infinite;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
        }

        @keyframes pulse-badge {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        /* --- Quick Actions Section --- */
        .actions-section {
            padding: 60px 0;
        }

        .section-header {
            text-align: center;
            margin-bottom: 50px;
        }

        .section-tag {
            display: inline-block;
            background: rgba(250, 204, 21, 0.1);
            color: var(--accent-color);
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 15px;
        }

        .section-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 10px;
        }

        .action-card {
            background: white;
            border-radius: 20px;
            padding: 35px 30px;
            text-align: center;
            transition: transform 0.4s ease, box-shadow 0.4s ease, opacity 0.4s ease, visibility 0.4s ease, max-height 0.4s ease;
            border: 1px solid rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
            text-decoration: none;
            color: inherit;
            display: block;
            height: 100%;
        }

        .action-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--accent-gradient);
            transform: scaleX(0);
            transition: transform 0.4s ease;
        }

        .action-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            color: inherit;
        }

        .action-card:hover::before {
            transform: scaleX(1);
        }

        .action-icon-wrapper {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, rgba(250, 204, 21, 0.1) 0%, rgba(250, 204, 21, 0.05) 100%);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            transition: transform 0.4s ease, box-shadow 0.4s ease, opacity 0.4s ease, visibility 0.4s ease, max-height 0.4s ease;
        }

        .action-card:hover .action-icon-wrapper {
            background: var(--accent-gradient);
            transform: rotate(-5deg) scale(1.1);
        }

        .action-icon {
            font-size: 1.5rem;
            color: var(--accent-color);
            
        }

        .action-card:hover .action-icon {
            color: white;
        }

        .action-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 10px;
        }

        .action-desc {
            color: var(--text-light);
            font-size: 0.9rem;
            line-height: 1.6;
        }

        /* --- CTA Section --- */
        .cta-section {
            background: var(--primary-gradient);
            padding: 60px 0;
            position: relative;
            overflow: hidden;
            border-radius: 20px;
            margin-top: 30px;
        }

        .cta-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 80% 80%, rgba(250, 204, 21, 0.2) 0%, transparent 50%);
        }

        .cta-content {
            position: relative;
            z-index: 2;
            text-align: center;
            color: white;
        }

        .cta-title {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 15px;
        }

        .cta-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 30px;
        }

        .btn-cta {
            background: var(--accent-gradient);
            color: white;
            font-weight: 600;
            padding: 15px 40px;
            border-radius: 50px;
            border: none;
            font-size: 1.1rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            box-shadow: 0 4px 20px rgba(250, 204, 21, 0.4);
            text-decoration: none;
            display: inline-block;
        }

        .btn-cta:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(250, 204, 21, 0.5);
            color: white;
        }

        /* --- Footer --- */
        .footer-custom {
            background: var(--primary-color);
            color: rgba(255, 255, 255, 0.7);
            padding: 20px 0;
            text-align: center;
            font-size: 0.9rem;
            margin-top: 30px;
            border-radius: 20px;
        }

        /* --- Responsive --- */
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
            .hero-welcome { padding: 30px 20px 40px; }
            .stats-section { margin-top: 20px; }
            .stat-card { padding: 20px; }
            .stat-value { font-size: 2rem; }
            .motorcycle-card { padding: 25px 20px; }
            .motorcycle-details { grid-template-columns: 1fr; }
            .content-area { padding: 20px; }
            .sidebar { width: 280px; }
            .top-bar { padding: 10px 15px; }
            .top-bar-title { font-size: 1rem; }
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

        /* Animations */
        .fade-in-up {
            animation: fadeInUp 0.8s ease both;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* --- Motorcycle Dashboard Cards --- */
        .motorcycle-dashboard-section {
            padding: 40px 0;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-radius: 20px;
        }

        .motorcycle-card {
            background: white;
            border-radius: 20px;
            padding: 30px 25px;
            text-align: center;
            transition: transform 0.4s ease, box-shadow 0.4s ease, opacity 0.4s ease, visibility 0.4s ease, max-height 0.4s ease;
            border: 1px solid rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .motorcycle-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
            transform: scaleX(0);
            transition: transform 0.4s ease;
        }

        .motorcycle-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }

        .motorcycle-card:hover::before {
            transform: scaleX(1);
        }

        .motorcycle-icon-wrapper {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.1) 0%, rgba(15, 23, 42, 0.05) 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            transition: transform 0.4s ease, box-shadow 0.4s ease, opacity 0.4s ease, visibility 0.4s ease, max-height 0.4s ease;
        }

        .motorcycle-card:hover .motorcycle-icon-wrapper {
            background: var(--primary-gradient);
            transform: rotate(-5deg) scale(1.1);
        }

        .motorcycle-icon {
            font-size: 2.5rem;
            color: var(--primary-color);
            
        }

        .motorcycle-card:hover .motorcycle-icon {
            color: white;
        }

        .motorcycle-brand {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 5px;
        }

        .motorcycle-model {
            font-size: 1rem;
            color: var(--text-light);
            margin-bottom: 10px;
        }

        .motorcycle-plate {
            background: rgba(15, 23, 42, 0.1);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 20px;
            color: var(--text-dark);
        }

        .motorcycle-card-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        /* Health Score Styles */
        .health-score-container {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.05) 100%);
            border-radius: 15px;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .health-score-circle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            font-weight: 700;
            color: white;
            position: relative;
            flex-shrink: 0;
        }

        .health-score-circle.excellent {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .health-score-circle.good {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }

        .health-score-circle.fair {
            background: linear-gradient(135deg, #FACC15 0%, #3b82f6 100%);
        }

        .health-score-circle.poor {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        .health-score-info h4 {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 3px;
            color: var(--text-dark);
        }

        .health-score-info p {
            font-size: 0.8rem;
            color: var(--text-light);
            margin: 0;
            line-height: 1.3;
        }

        /* Warranty Status Styles */
        .warranty-status {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            border-radius: 12px;
        }

        .warranty-status.active {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.05) 100%);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .warranty-status.expired {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(239, 68, 68, 0.05) 100%);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .warranty-status.none {
            background: linear-gradient(135deg, rgba(148, 163, 184, 0.1) 0%, rgba(148, 163, 184, 0.05) 100%);
            border: 1px solid rgba(148, 163, 184, 0.3);
        }

        .warranty-icon {
            font-size: 1.3rem;
        }

        .warranty-status.active .warranty-icon {
            color: var(--success);
        }

        .warranty-status.expired .warranty-icon {
            color: var(--danger);
        }

        .warranty-status.none .warranty-icon {
            color: var(--text-light);
        }

        .warranty-info h5 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 2px;
            color: var(--text-dark);
        }

        .warranty-info p {
            font-size: 0.75rem;
            color: var(--text-light);
            margin: 0;
            line-height: 1.2;
        }

        /* Maintenance Schedule Styles */
        .maintenance-schedule {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(59, 130, 246, 0.05) 100%);
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .maintenance-schedule.overdue {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(239, 68, 68, 0.05) 100%);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .maintenance-icon {
            font-size: 1.3rem;
            color: var(--info);
        }

        .maintenance-schedule.overdue .maintenance-icon {
            color: var(--danger);
        }

        .maintenance-info h5 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 2px;
            color: var(--text-dark);
        }

        .maintenance-info p {
            font-size: 0.75rem;
            color: var(--text-light);
            margin: 0;
        }

        .maintenance-countdown {
            font-size: 1rem;
            font-weight: 700;
            color: var(--info);
        }

        .maintenance-schedule.overdue .maintenance-countdown {
            color: var(--danger);
        }

        /* Motorcycle Details Grid */
        .motorcycle-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: auto;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
        }

        .detail-item {
            text-align: center;
        }

        .detail-label {
            font-size: 0.7rem;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }

        .detail-value {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .no-motorcycles {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
        }

        .no-motorcycles i {
            font-size: 4rem;
            color: var(--text-light);
            margin-bottom: 20px;
        }

        .no-motorcycles h3 {
            font-size: 1.5rem;
            color: var(--text-dark);
            margin-bottom: 10px;
        }

        .no-motorcycles p {
            color: var(--text-light);
            margin-bottom: 25px;
        }

        /* --- Compact Dashboard Adjustments --- */
        .welcome-title { font-size: clamp(1.4rem, 3.5vw, 2.2rem); }
        .welcome-subtitle { font-size: 0.9rem; }

        .stat-card { padding: 15px; }
        .stat-icon-wrapper {
            width: 38px;
            height: 38px;
            margin-bottom: 10px;
            font-size: 1.1rem;
            border-radius: 10px;
        }
        .stat-value { font-size: 1.7rem; }
        .stat-label { font-size: 0.8rem; }
        .stat-meta { font-size: 0.7rem; margin-top: 10px; padding-top: 10px; }

        .section-header { margin-bottom: 30px; }
        .section-tag { font-size: 0.7rem; padding: 5px 14px; }
        .section-title { font-size: 1.4rem; }

        .action-card { padding: 20px 15px; }
        .action-icon-wrapper {
            width: 36px;
            height: 36px;
            margin: 0 auto 12px;
        }
        .action-icon { font-size: 1.1rem; }
        .action-title { font-size: 0.95rem; }
        .action-desc { font-size: 0.75rem; }

        .motorcycle-dashboard-section { padding: 25px 0; }
        .motorcycle-card { padding: 15px 12px; }
        .motorcycle-icon-wrapper {
            width: 50px;
            height: 50px;
            margin: 0 auto 12px;
            border-radius: 14px;
        }
        .motorcycle-icon { font-size: 1.6rem; }
        .motorcycle-brand { font-size: 1rem; }
        .motorcycle-model { font-size: 0.8rem; margin-bottom: 6px; }
        .motorcycle-plate { font-size: 0.7rem; padding: 3px 10px; margin-bottom: 12px; }

        .health-score-container { padding: 10px; gap: 10px; }
        .health-score-circle {
            width: 38px;
            height: 38px;
            font-size: 1rem;
        }
        .health-score-info h4 { font-size: 0.75rem; }
        .health-score-info p { font-size: 0.7rem; }

        .warranty-icon, .maintenance-icon { font-size: 1rem; }
        .warranty-info h5, .maintenance-info h5 { font-size: 0.75rem; }
        .warranty-info p, .maintenance-info p { font-size: 0.68rem; }
        .maintenance-countdown { font-size: 0.8rem; }

        .detail-label { font-size: 0.6rem; }
        .detail-value { font-size: 0.75rem; }

        /* --- Admin-Style Top Header --- */
        .top-bar {
            background: #1e293b;
            padding: 12px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
            position: fixed;
            top: 0;
            left: 280px;
            right: 0;
            z-index: 1030;
            border-bottom: 2px solid rgba(250, 204, 21, 0.3);
            overflow: visible;
            height: 60px;
            transition: left 0.3s ease;
        }

        .top-bar::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg,
                transparent 0%,
                rgba(250, 204, 21, 0.8) 20%,
                rgba(59, 130, 246, 0.8) 50%,
                rgba(250, 204, 21, 0.8) 80%,
                transparent 100%
            );
        }

        .main-content.expanded .top-bar { left: 80px; }

        @media (max-width: 992px) {
            .top-bar { left: 0; padding: 12px 20px; }
            .main-content.expanded .top-bar { left: 0; }
        }

        .content-area { padding-top: 90px; }

        .top-bar-title {
            font-size: 1.4rem;
            font-weight: 600;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
            line-height: 1.2;
            margin: 0;
        }

        .top-bar-title i { color: var(--accent-color); }

        .sidebar-toggle {
            display: block;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: white;
            cursor: pointer;
            padding: 0;
            margin-right: 15px;
        }

        .top-bar-user {
            display: flex;
            align-items: center;
            gap: 10px;
            color: white;
            cursor: pointer;
            position: relative;
        }

        .top-bar-user-avatar {
            width: 45px;
            height: 45px;
            background: #1e3a5f;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        .top-bar-user-info {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
            text-align: right;
        }

        .top-bar-user-name {
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .top-bar-user-role {
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.75rem;
        }

        .top-bar-dropdown-btn {
            color: white;
            font-size: 0.8rem;
            transition: transform 0.3s ease;
        }

        .top-bar-user.active .top-bar-dropdown-btn { transform: rotate(180deg); }

        .top-bar-user-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            min-width: 170px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            z-index: 1100;
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        .top-bar-user.active .top-bar-user-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-header {
            padding: 10px 14px;
            border-bottom: 1px solid #f1f5f9;
        }

        .dropdown-header-name {
            font-size: 0.82rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1px;
        }

        .dropdown-header-role {
            font-size: 0.68rem;
            color: #64748b;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            font-size: 0.8rem;
            color: #1e293b;
            text-decoration: none;
            
        }

        .dropdown-item:hover {
            background: rgba(250, 204, 21, 0.1);
            color: #FACC15;
        }

        .dropdown-item.danger {
            color: #ef4444;
            cursor: pointer;
            border-top: 1px solid #f1f5f9;
        }
        @media (max-width: 576px) {
            .stats-section .row {
                --bs-gutter-x: 0.75rem;
            }
            .stat-card {
                padding: 12px;
                border-radius: 16px;
            }
            .stat-icon-wrapper {
                width: 32px;
                height: 32px;
                font-size: 1rem;
                border-radius: 8px;
                margin-bottom: 8px;
            }
            .stat-value {
                font-size: 1.4rem;
                margin-bottom: 2px;
            }
            .stat-label {
                font-size: 0.75rem;
            }
            .stat-meta {
                margin-top: 6px;
                padding-top: 6px;
                gap: 4px;
                font-size: 0.6rem;
                flex-wrap: wrap;
            }
            .stat-meta span {
                white-space: nowrap;
            }
            .notification-badge {
                top: 8px;
                right: 8px;
                width: 20px;
                height: 20px;
                font-size: 0.65rem;
            }
        }

        /* ===== Dashboard v2 — tinted stat cards ===== */
        .dc-card {
            display: flex;
            flex-direction: column;
            position: relative;
            border-radius: 18px;
            padding: 18px 18px 16px;
            text-decoration: none;
            color: var(--text-dark);
            border: 1px solid rgba(0,0,0,0.04);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            height: 100%;
            min-height: 132px;
            overflow: hidden;
        }
        .dc-card .dc-meta { margin-top: auto; }
        .dc-card:hover { transform: translateY(-4px); box-shadow: 0 12px 28px rgba(0,0,0,0.08); color: var(--text-dark); }
        .dc-card.dc-navy  { background: linear-gradient(135deg, #EAF0F8 0%, #DFE9F5 100%); }
        .dc-card.dc-gold  { background: linear-gradient(135deg, #FEF6DC 0%, #FDF0C0 100%); }
        .dc-card.dc-amber { background: linear-gradient(135deg, #FFF0DB 0%, #FFE8C7 100%); }
        .dc-card.dc-blue  { background: linear-gradient(135deg, #EAF2FE 0%, #DFEBFD 100%); }
        .dc-icon {
            width: 40px; height: 40px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.15rem;
            margin-bottom: 10px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .dc-navy .dc-icon { color: #1e3a5f; }
        .dc-gold .dc-icon { color: #EAB308; }
        .dc-amber .dc-icon { color: #F59E0B; }
        .dc-blue .dc-icon { color: #3B82F6; }
        .dc-title { font-size: 0.82rem; font-weight: 600; color: #374151; }
        .dc-value { font-size: 1.7rem; font-weight: 700; line-height: 1.15; color: #111827; }
        .dc-meta { font-size: 0.72rem; color: #6b7280; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .dc-meta .dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; margin-right: 4px; }
        .dc-meta .dot.green { background: #10B981; }
        .dc-meta .dot.amber { background: #F59E0B; }
        .dc-arrow {
            position: absolute;
            right: 14px;
            bottom: 14px;
            width: 30px; height: 30px;
            border-radius: 50%;
            background: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.8rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .dc-navy .dc-arrow { color: #1e3a5f; }
        .dc-gold .dc-arrow { color: #EAB308; }
        .dc-amber .dc-arrow { color: #F59E0B; }
        .dc-blue .dc-arrow { color: #3B82F6; }
        .dc-moto-bg {
            position: absolute;
            right: -8px; bottom: -6px;
            font-size: 4.2rem;
            color: rgba(30,58,95,0.12);
            transform: rotate(-8deg);
            pointer-events: none;
        }

        /* ===== Vehicle health card ===== */
        .vhealth-card {
            background: #fff;
            border-radius: 20px;
            border: 1px solid #ececf4;
            box-shadow: 0 6px 24px rgba(0,0,0,0.05);
            padding: 18px 20px;
            height: 100%;
        }
        .vhealth-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }
        .vhealth-heart {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1e3a5f, #2c4f7c);
            color: #FACC15;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
        }
        .vhealth-head h3 { font-size: 1.05rem; font-weight: 700; margin: 0; color: #111827; flex: 1; }
        .vhealth-bike {
            flex: 1;
            font-size: 0.8rem;
            font-weight: 700;
            color: #1e3a5f;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .vhealth-bike .vhealth-plate {
            font-weight: 600;
            color: #6b7280;
        }
        .vhealth-link {
            font-size: 0.75rem;
            font-weight: 600;
            color: #1e3a5f;
            text-decoration: none;
            display: flex; align-items: center; gap: 5px;
            background: #EAF0F8;
            padding: 5px 12px;
            border-radius: 20px;
        }
        .vhealth-link:hover { background: #DFE9F5; color: #0f172a; }
        .vhealth-body { display: flex; gap: 20px; align-items: center; margin-bottom: 16px; }
        .vhealth-img {
            flex: 0 0 190px;
            background: linear-gradient(135deg, #F0F4FA 0%, #E6EEF7 100%);
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            padding: 12px;
        }
        .vhealth-img svg { width: 100%; max-width: 170px; height: auto; display: block; }
        .vhealth-img .vhealth-moto-photo {
            width: 100%;
            max-width: 170px;
            max-height: 140px;
            object-fit: contain;
            display: block;
            mix-blend-mode: multiply;
        }
        .vhealth-right { flex: 1; min-width: 0; }
        .vhealth-cond-head {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            font-size: 0.78rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        .vhealth-cond-head .score { color: #10B981; font-weight: 700; }
        .vhealth-bar {
            height: 10px;
            background: #E5E7EB;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 16px;
        }
        .vhealth-bar-fill {
            height: 100%;
            border-radius: 10px;
            background: linear-gradient(90deg, #34D399, #10B981);
        }
        .vhealth-items {
            display: flex;
            justify-content: space-between;
            gap: 6px;
        }
        .vh-item { text-align: center; flex: 1; min-width: 0; }
        .vh-ico {
            width: 42px; height: 42px;
            margin: 0 auto 6px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
        }
        .vh-ico.good { background: #E9F9F1; color: #10B981; }
        .vh-ico.fair { background: #FFF4E4; color: #F59E0B; }
        .vh-ico.bad { background: #FDECEC; color: #EF4444; }
        .vh-name { font-size: 0.72rem; font-weight: 600; color: #374151; }
        .vh-status { font-size: 0.68rem; font-weight: 600; }
        .vh-status.good { color: #10B981; }
        .vh-status.fair { color: #F59E0B; }
        .vh-status.bad { color: #EF4444; }

        .vhealth-svc-head {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
        }
        .vhealth-svc-head i { color: #1e3a5f; }
        .vhealth-svc-head a {
            margin-left: auto;
            font-size: 0.72rem;
            font-weight: 600;
            color: #6b7280;
            text-decoration: none;
        }
        .vhealth-svc-head a:hover { color: #1e3a5f; }
        .svc-table { width: 100%; border-collapse: collapse; }
        .svc-table th {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #9CA3AF;
            font-weight: 600;
            text-align: left;
            padding: 6px 8px;
            border-bottom: 1px solid #F1F2F6;
        }
        .svc-table td {
            font-size: 0.76rem;
            color: #374151;
            padding: 9px 8px;
            border-bottom: 1px solid #F7F8FA;
            vertical-align: middle;
        }
        .svc-table tr:last-child td { border-bottom: none; }
        .svc-pill {
            display: inline-block;
            background: #E9F9F1;
            color: #059669;
            font-size: 0.68rem;
            font-weight: 600;
            border-radius: 20px;
            padding: 3px 10px;
        }
        .svc-caret { color: #C4C9D4; font-size: 0.75rem; }
        .svc-empty { font-size: 0.76rem; color: #9CA3AF; padding: 12px 8px; }

        .vhealth-cta {
            display: flex;
            align-items: center;
            gap: 14px;
            background: linear-gradient(135deg, #F0F4FA, #E6EEF7);
            border: 1px solid #D8E2F0;
            border-radius: 14px;
            padding: 13px 16px;
            margin-top: 14px;
        }
        .vhealth-cta-ico {
            width: 38px; height: 38px;
            border-radius: 50%;
            background: #fff;
            color: #1e3a5f;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.05rem;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(30,58,95,0.15);
        }
        .vhealth-cta-text { flex: 1; min-width: 0; }
        .vhealth-cta-text strong { font-size: 0.85rem; color: #111827; display: block; }
        .vhealth-cta-text p { font-size: 0.72rem; color: #6b7280; margin: 2px 0 0; }
        .vhealth-cta-btn {
            background: linear-gradient(135deg, #FACC15, #EAB308);
            color: #111827;
            font-size: 0.78rem;
            font-weight: 600;
            text-decoration: none;
            padding: 9px 18px;
            border-radius: 10px;
            display: flex; align-items: center; gap: 7px;
            flex-shrink: 0;
            box-shadow: 0 4px 14px rgba(250,204,21,0.35);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .vhealth-cta-btn:hover { transform: translateY(-2px); color: #111827; box-shadow: 0 8px 20px rgba(250,204,21,0.45); }

        /* ===== Vehicle switcher ===== */
        .veh-switch-section { margin-bottom: 1.25rem; }
        .veh-switch-head {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 0.85rem;
        }
        .veh-switch-head h2 { font-size: 1.05rem; font-weight: 700; color: #111827; margin: 0; }
        .veh-switch-link {
            font-size: 0.75rem; font-weight: 600; color: #6b7280;
            text-decoration: none; display: flex; align-items: center; gap: 4px;
        }
        .veh-switch-link:hover { color: #1e3a5f; }
        .veh-switch-row { display: flex; gap: 0.85rem; flex-wrap: wrap; }
        .veh-chip {
            display: flex; align-items: center; gap: 10px;
            background: #fff; border: 1.5px solid #E5E7EB; border-radius: 14px;
            padding: 10px 14px; cursor: pointer; text-decoration: none;
            transition: box-shadow 0.15s ease;
            font-family: inherit; position: relative; min-width: 200px;
        }
        .veh-chip:hover { border-color: #C7D6EA; box-shadow: 0 4px 12px rgba(15,23,42,0.06); }
        .veh-chip.selected { border-color: #FACC15; background: #FFFDF2; box-shadow: 0 4px 14px rgba(250,204,21,0.18); }
        .veh-chip-img {
            width: 52px; height: 40px; border-radius: 8px;
            background: #F3F6FA; display: flex; align-items: center; justify-content: center;
            color: #6b7280; font-size: 1.3rem; flex-shrink: 0; overflow: hidden;
        }
        .veh-chip-img img { width: 100%; height: 100%; object-fit: contain; mix-blend-mode: multiply; }
        .veh-chip-text { display: flex; flex-direction: column; min-width: 0; text-align: left; }
        .veh-chip-name { font-size: 0.82rem; font-weight: 700; color: #111827; white-space: nowrap; }
        .veh-chip-plate { font-size: 0.7rem; color: #6b7280; }
        .veh-chip-badge {
            display: none; font-size: 0.6rem; font-weight: 800; text-transform: uppercase;
            background: #FDE68A; color: #92400E; border-radius: 99px; padding: 3px 8px;
            letter-spacing: 0.04em; margin-left: 4px;
        }
        .veh-chip.selected .veh-chip-badge { display: inline-block; }
        .veh-chip-caret { color: #C4C9D4; font-size: 0.85rem; margin-left: auto; }
        .veh-chip-add {
            color: #1e3a5f; font-size: 0.8rem; font-weight: 600;
            justify-content: center; border-style: dashed; gap: 6px; min-width: 140px;
        }
        .veh-chip-add:hover { border-color: #1e3a5f; }

        /* ===== Vehicle overview card ===== */
        .vov-card {
            background: #fff; border: 1px solid #ececf4; border-radius: 16px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.05); padding: 18px 20px; margin-bottom: 1.25rem;
        }
        .vov-head { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
        .vov-head-ico {
            width: 32px; height: 32px; border-radius: 50%;
            background: linear-gradient(135deg, #1e3a5f, #2c4f7c); color: #FACC15;
            display: flex; align-items: center; justify-content: center; font-size: 0.9rem;
        }
        .vov-head h3 { font-size: 0.98rem; font-weight: 700; margin: 0; color: #111827; }
        .vov-body { display: grid; grid-template-columns: 190px 170px 1fr; gap: 1.5rem; align-items: center; }
        .vov-bike { text-align: center; }
        .vov-bike-img {
            height: 110px; display: flex; align-items: center; justify-content: center;
            color: #9CA3AF; font-size: 3rem; margin-bottom: 8px;
        }
        .vov-bike-img img { max-width: 170px; max-height: 110px; object-fit: contain; mix-blend-mode: multiply; }
        .vov-bike-name { font-size: 0.85rem; font-weight: 700; color: #111827; }
        .vov-bike-plate { font-size: 0.75rem; font-weight: 600; color: #6b7280; margin-bottom: 8px; }
        .vov-bike-meta { display: flex; justify-content: center; gap: 8px; align-items: center; }
        .vov-type { font-size: 0.68rem; color: #6b7280; display: flex; align-items: center; gap: 4px; }
        .vov-status {
            font-size: 0.62rem; font-weight: 800; text-transform: uppercase;
            background: #DCFCE7; color: #15803D; border-radius: 99px; padding: 2px 8px;
        }
        .vov-health { text-align: center; }
        .vov-ring {
            width: 110px; height: 110px; border-radius: 50%; margin: 0 auto 8px;
            display: flex; align-items: center; justify-content: center;
            background:
                radial-gradient(closest-side, #fff 80%, transparent 81% 100%),
                conic-gradient(var(--c, #10B981) calc(var(--p, 0) * 1%), #E5E7EB 0);
        }
        .vov-ring span { font-size: 1.7rem; font-weight: 800; color: #111827; line-height: 1; }
        .vov-ring small { font-size: 0.7rem; font-weight: 600; color: #9CA3AF; }
        .vov-health-label { font-size: 0.75rem; font-weight: 600; color: #6b7280; }
        .vov-health-cond { font-size: 0.78rem; font-weight: 700; margin-top: 2px; }
        .vov-health-cond.good { color: #10B981; }
        .vov-health-cond.fair { color: #EAB308; }
        .vov-health-cond.bad { color: #EF4444; }
        .vov-comps { min-width: 0; }
        .vov-comps-title { font-size: 0.8rem; font-weight: 700; color: #111827; margin-bottom: 10px; }
        .vov-comp {
            display: flex; align-items: center; gap: 10px;
            padding: 7px 10px; border: 1px solid #F1F3F7; border-radius: 10px; margin-bottom: 6px;
        }
        .vov-comp-ico {
            width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 0.8rem;
        }
        .vov-comp-ico.good { background: #E9F9F1; color: #10B981; }
        .vov-comp-ico.fair { background: #FEF3C7; color: #D97706; }
        .vov-comp-ico.bad { background: #FDECEC; color: #EF4444; }
        .vov-comp-name { flex: 1; font-size: 0.8rem; font-weight: 600; color: #111827; }
        .vov-comp-pill {
            font-size: 0.65rem; font-weight: 700; border-radius: 99px; padding: 3px 10px;
        }
        .vov-comp-pill.good { background: #DCFCE7; color: #15803D; }
        .vov-comp-pill.fair { background: #FEF3C7; color: #D97706; }
        .vov-comp-pill.bad { background: #FDECEC; color: #EF4444; }
        .vov-comp-caret { color: #C4C9D4; font-size: 0.75rem; }

        /* ===== Service timeline ===== */
        .stl-card {
            background: #fff; border: 1px solid #ececf4; border-radius: 16px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.05); padding: 18px 20px; margin-bottom: 1.25rem;
        }
        .stl-head { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
        .stl-head-ico {
            width: 30px; height: 30px; border-radius: 50%; background: #1e3a5f; color: #fff;
            display: flex; align-items: center; justify-content: center; font-size: 0.8rem;
        }
        .stl-head h3 { font-size: 0.98rem; font-weight: 700; margin: 0; color: #111827; flex: 1; }
        .stl-link { font-size: 0.75rem; font-weight: 600; color: #1e3a5f; text-decoration: none; display: flex; align-items: center; gap: 3px; }
        .stl-link:hover { color: #0f172a; }
        .stl-empty { color: #9CA3AF; font-size: 0.82rem; padding: 1rem 0; text-align: center; }
        .stl-list { display: flex; overflow-x: auto; padding: 4px 4px 10px; }
        .stl-item {
            position: relative; flex: 0 0 120px; min-width: 0;
            padding: 20px 8px 0 0;
            display: flex; flex-direction: column; gap: 3px;
        }
        .stl-item::before {
            content: ''; position: absolute; left: 5px; top: 5px; right: 0;
            height: 2px; background: #E5E7EB;
        }
        .stl-item.completed::before { background: #10B981; }
        .stl-item:last-child::before { display: none; }
        .stl-dot {
            position: absolute; left: 0; top: 1px;
            width: 10px; height: 10px; border-radius: 50%;
            background: #10B981; box-shadow: 0 0 0 3px #DCFCE7;
        }
        .stl-item.upcoming .stl-dot { background: #3B82F6; box-shadow: 0 0 0 3px #EBF3FE; }
        .stl-item.current .stl-dot { background: #FACC15; box-shadow: 0 0 0 3px #FEF3C7; }
        .stl-item.upcoming .stl-type { color: #6b7280; }
        .stl-date { font-size: 0.68rem; color: #9CA3AF; font-weight: 600; }
        .stl-main { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; min-width: 0; }
        .stl-type { font-size: 0.78rem; font-weight: 600; color: #111827; }
        .stl-pill {
            font-size: 0.62rem; font-weight: 700; background: #DCFCE7; color: #15803D;
            border-radius: 99px; padding: 2px 8px; flex-shrink: 0;
        }
        .stl-pill.upcoming { background: #EBF3FE; color: #1e40af; }
        .stl-pill.current { background: #FEF3C7; color: #92400E; }
        .stl-sub { font-size: 0.72rem; color: #9CA3AF; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        /* ===== Quick access bar ===== */
        .qa-bar {
            background: #fff; border: 1px solid #ececf4; border-radius: 14px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.05);
            display: flex; align-items: center; gap: 8px;
            padding: 10px 18px; flex-wrap: wrap;
        }
        .qa-label { font-size: 0.78rem; font-weight: 700; color: #111827; margin-right: auto; }
        .qa-item {
            display: flex; align-items: center; gap: 7px;
            font-size: 0.78rem; font-weight: 600; color: #374151; text-decoration: none;
            padding: 7px 12px; border-radius: 10px;
            
        }
        .qa-item i { color: #6b7280; }
        .qa-item:hover { background: #F3F6FA; color: #111827; }

        /* ===== Upcoming appointment ===== */
        .ua-card, .al-card, .tip-card {
            background: #fff; border: 1px solid #ececf4; border-radius: 16px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.05); padding: 16px 18px; margin-bottom: 1.25rem;
        }
        .ua-head, .al-head { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
        .ua-head-ico, .al-head-ico { color: #1e3a5f; font-size: 1rem; }
        .ua-head h3, .al-head h3 { font-size: 0.92rem; font-weight: 700; margin: 0; color: #111827; flex: 1; }
        .ua-link { font-size: 0.72rem; font-weight: 600; color: #1e3a5f; text-decoration: none; }
        .ua-link:hover { color: #0f172a; }
        .ua-body {
            background: #F7FAFD; border-radius: 12px; padding: 14px;
        }
        .ua-service { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 12px; }
        .ua-service-ico {
            width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0;
            background: #EBF3FE; color: #3B82F6;
            display: flex; align-items: center; justify-content: center;
        }
        .ua-service-name { font-size: 0.82rem; font-weight: 700; color: #111827; }
        .ua-service-bike { font-size: 0.72rem; color: #6b7280; margin-top: 2px; }
        .ua-meta { display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: 0.75rem; color: #6b7280; margin-bottom: 14px; }
        .ua-meta i { margin-right: 4px; }
        .ua-btn {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            background: linear-gradient(135deg, #FACC15, #EAB308); color: #111827;
            font-size: 0.8rem; font-weight: 700; text-decoration: none;
            padding: 10px; border-radius: 10px;
            box-shadow: 0 4px 14px rgba(250,204,21,0.3);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .ua-btn:hover { transform: translateY(-1px); color: #111827; box-shadow: 0 8px 18px rgba(250,204,21,0.4); }
        .ua-empty { text-align: center; color: #9CA3AF; font-size: 0.8rem; padding: 0.5rem 0; }
        .ua-empty i { font-size: 1.6rem; display: block; margin-bottom: 6px; opacity: 0.6; }

        /* ===== Maintenance alerts ===== */
        .al-count {
            font-size: 0.62rem; font-weight: 800; background: #FDECEC; color: #EF4444;
            border-radius: 99px; padding: 2px 8px;
        }
        .al-empty { display: flex; align-items: center; gap: 8px; color: #10B981; font-size: 0.8rem; font-weight: 600; padding: 0.5rem 0; }
        .al-list { display: flex; flex-direction: column; gap: 8px; }
        .al-item {
            display: flex; align-items: center; gap: 10px;
            background: #FBFCFE; border: 1px solid #F1F3F7; border-radius: 12px; padding: 10px 12px;
        }
        .al-ico {
            width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 0.85rem;
        }
        .al-ico.red { background: #FDECEC; color: #EF4444; }
        .al-ico.amber { background: #FEF3C7; color: #D97706; }
        .al-text { flex: 1; min-width: 0; }
        .al-title { font-size: 0.78rem; font-weight: 700; color: #111827; }
        .al-sub { font-size: 0.7rem; color: #6b7280; margin-top: 1px; }
        .al-caret { color: #C4C9D4; font-size: 0.8rem; }

        /* ===== Tip card ===== */
        .tip-card { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 0; }
        .tip-ico {
            width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
            background: #FEF3C7; color: #D97706;
            display: flex; align-items: center; justify-content: center; font-size: 0.85rem;
        }
        .tip-text strong { font-size: 0.8rem; color: #111827; display: block; }
        .tip-text p { font-size: 0.72rem; color: #6b7280; margin: 3px 0 0; }

        @media (max-width: 1199px) {
            .vov-body { grid-template-columns: 160px 150px 1fr; gap: 1rem; }
        }
        @media (max-width: 991px) {
            .vov-body { grid-template-columns: 1fr; text-align: center; }
            .vov-comps { text-align: left; }
            .veh-chip { min-width: calc(50% - 0.5rem); }
        }
        @media (max-width: 576px) {
            .veh-chip { min-width: 100%; }
            .vov-ring { width: 95px; height: 95px; }
        }

        /* ===== Dark mode overrides ===== */
        /* Stat / quick-link cards */
        html[data-theme="dark"] .dc-card.dc-navy,
        html[data-theme="dark"] .dc-card.dc-gold,
        html[data-theme="dark"] .dc-card.dc-amber,
        html[data-theme="dark"] .dc-card.dc-blue {
            background: linear-gradient(135deg, #22335a 0%, #1a2b4f 100%) !important;
        }
        html[data-theme="dark"] .dc-icon, html[data-theme="dark"] .dc-arrow { background: #22335a !important; }
        html[data-theme="dark"] .dc-navy .dc-icon, html[data-theme="dark"] .dc-navy .dc-arrow { color: #93c5fd !important; }
        html[data-theme="dark"] .dc-title { color: #cbd5e1 !important; }
        html[data-theme="dark"] .dc-moto-bg { color: rgba(255, 255, 255, 0.05) !important; }

        /* Vehicle switcher chips */
        html[data-theme="dark"] .veh-switch-head h2 { color: #e2e8f0 !important; }
        html[data-theme="dark"] .veh-switch-link { color: #94a3b8 !important; }
        html[data-theme="dark"] .veh-switch-link:hover { color: #FDE047 !important; }
        html[data-theme="dark"] .veh-chip { background: #1a2b4f !important; border-color: rgba(255, 255, 255, 0.12) !important; }
        html[data-theme="dark"] .veh-chip:hover { border-color: rgba(59, 130, 246, 0.5) !important; }
        html[data-theme="dark"] .veh-chip.selected { border-color: #FACC15 !important; background: rgba(250, 204, 21, 0.08) !important; }
        html[data-theme="dark"] .veh-chip-img { background: transparent !important; color: #94a3b8 !important; }
        html[data-theme="dark"] .veh-chip-img img { mix-blend-mode: normal; }
        html[data-theme="dark"] .veh-chip-name { color: #e2e8f0 !important; }
        html[data-theme="dark"] .veh-chip-plate { color: #94a3b8 !important; }
        html[data-theme="dark"] .veh-chip-badge { background: rgba(250, 204, 21, 0.2) !important; color: #FDE047 !important; }
        html[data-theme="dark"] .veh-chip-caret { color: #64748b !important; }
        html[data-theme="dark"] .veh-chip-add { color: #93c5fd !important; }
        html[data-theme="dark"] .veh-chip-add:hover { border-color: #93c5fd !important; }

        /* Vehicle overview card */
        html[data-theme="dark"] .vov-card, html[data-theme="dark"] .stl-card,
        html[data-theme="dark"] .ua-card, html[data-theme="dark"] .al-card,
        html[data-theme="dark"] .tip-card, html[data-theme="dark"] .qa-bar,
        html[data-theme="dark"] .no-motorcycles {
            background: #1a2b4f !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            color: #e2e8f0 !important;
        }
        html[data-theme="dark"] .vov-head h3, html[data-theme="dark"] .stl-head h3,
        html[data-theme="dark"] .ua-head h3, html[data-theme="dark"] .al-head h3,
        html[data-theme="dark"] .vov-comps-title, html[data-theme="dark"] .vov-bike-name,
        html[data-theme="dark"] .vov-comp-name, html[data-theme="dark"] .ua-service-name,
        html[data-theme="dark"] .al-title, html[data-theme="dark"] .tip-text strong,
        html[data-theme="dark"] .stl-type, html[data-theme="dark"] .vov-ring span,
        html[data-theme="dark"] .no-motorcycles h3 { color: #e2e8f0 !important; }
        html[data-theme="dark"] .vov-bike-plate, html[data-theme="dark"] .vov-type,
        html[data-theme="dark"] .vov-health-label, html[data-theme="dark"] .vov-ring small,
        html[data-theme="dark"] .stl-date, html[data-theme="dark"] .stl-sub,
        html[data-theme="dark"] .stl-empty, html[data-theme="dark"] .ua-service-bike,
        html[data-theme="dark"] .ua-meta, html[data-theme="dark"] .ua-empty,
        html[data-theme="dark"] .al-sub, html[data-theme="dark"] .tip-text p,
        html[data-theme="dark"] .no-motorcycles p { color: #94a3b8 !important; }
        html[data-theme="dark"] .ua-head-ico, html[data-theme="dark"] .al-head-ico,
        html[data-theme="dark"] .ua-link, html[data-theme="dark"] .stl-link { color: #93c5fd !important; }
        html[data-theme="dark"] .ua-link:hover, html[data-theme="dark"] .stl-link:hover { color: #FDE047 !important; }
        html[data-theme="dark"] .ua-body { background: #16233f !important; }
        html[data-theme="dark"] .ua-service-ico { background: rgba(59, 130, 246, 0.15) !important; }
        html[data-theme="dark"] .vov-bike-img { background: transparent !important; }
        html[data-theme="dark"] .vov-bike-img img { mix-blend-mode: normal; }
        html[data-theme="dark"] .vov-status { background: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; }
        html[data-theme="dark"] .vov-health-cond.good { color: #34d399 !important; }
        html[data-theme="dark"] .vov-health-cond.fair { color: #FDE047 !important; }
        html[data-theme="dark"] .vov-health-cond.bad { color: #f87171 !important; }
        html[data-theme="dark"] .vov-comp { border-color: rgba(255, 255, 255, 0.09) !important; }
        html[data-theme="dark"] .vov-comp-ico.good { background: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; }
        html[data-theme="dark"] .vov-comp-ico.fair { background: rgba(245, 158, 11, 0.15) !important; color: #fbbf24 !important; }
        html[data-theme="dark"] .vov-comp-ico.bad { background: rgba(239, 68, 68, 0.15) !important; color: #f87171 !important; }
        html[data-theme="dark"] .vov-comp-pill.good { background: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; }
        html[data-theme="dark"] .vov-comp-pill.fair { background: rgba(245, 158, 11, 0.15) !important; color: #fbbf24 !important; }
        html[data-theme="dark"] .vov-comp-pill.bad { background: rgba(239, 68, 68, 0.15) !important; color: #f87171 !important; }
        html[data-theme="dark"] .vov-comp-caret, html[data-theme="dark"] .al-caret { color: #64748b !important; }

        /* Service timeline */
        html[data-theme="dark"] .stl-head-ico { background: #22335a !important; color: #93c5fd !important; }
        html[data-theme="dark"] .stl-item::before { background: #3b4d7d !important; }
        html[data-theme="dark"] .stl-item.completed::before { background: #10B981 !important; }
        html[data-theme="dark"] .stl-dot { box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2) !important; }
        html[data-theme="dark"] .stl-item.upcoming .stl-dot { box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2) !important; }
        html[data-theme="dark"] .stl-item.current .stl-dot { box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.25) !important; }
        html[data-theme="dark"] .stl-item.upcoming .stl-type { color: #94a3b8 !important; }
        html[data-theme="dark"] .stl-pill { background: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; }
        html[data-theme="dark"] .stl-pill.upcoming { background: rgba(59, 130, 246, 0.15) !important; color: #93c5fd !important; }
        html[data-theme="dark"] .stl-pill.current { background: rgba(250, 204, 21, 0.15) !important; color: #FDE047 !important; }

        /* Alerts & tip cards */
        html[data-theme="dark"] .al-count { background: rgba(239, 68, 68, 0.15) !important; color: #f87171 !important; }
        html[data-theme="dark"] .al-item { background: #22335a !important; border-color: rgba(255, 255, 255, 0.09) !important; }
        html[data-theme="dark"] .al-ico.red { background: rgba(239, 68, 68, 0.15) !important; color: #f87171 !important; }
        html[data-theme="dark"] .al-ico.amber { background: rgba(245, 158, 11, 0.15) !important; color: #fbbf24 !important; }
        html[data-theme="dark"] .tip-ico { background: rgba(245, 158, 11, 0.15) !important; color: #fbbf24 !important; }

        /* Health ring inner disc + track */
        html[data-theme="dark"] .vov-ring {
            background:
                radial-gradient(closest-side, #1a2b4f 80%, transparent 81% 100%),
                conic-gradient(var(--c, #10B981) calc(var(--p, 0) * 1%), #3b4d7d 0) !important;
        }

        /* Quick action bar */
        html[data-theme="dark"] .qa-label { color: #e2e8f0 !important; }
        html[data-theme="dark"] .qa-item { color: #cbd5e1 !important; }
        html[data-theme="dark"] .qa-item i { color: #94a3b8 !important; }
        html[data-theme="dark"] .qa-item:hover { background: #2a3d6b !important; color: #FDE047 !important; }
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
            <h1 class="top-bar-title"><i class="bi bi-speedometer2"></i> Dashboard</h1>
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
        <!-- Stat Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <a href="my_bookings.php" class="dc-card dc-navy fade-in-up">
                    <div class="dc-icon"><i class="bi bi-calendar-check"></i></div>
                    <div class="dc-title">Upcoming Appointment</div>
                    <div class="dc-value"><?= $totalUpcoming ?></div>
                    <div class="dc-meta">
                        <span><span class="dot green"></span><?= $approvedBookings ?> Approved</span>
                        <span><span class="dot amber"></span><?= $pendingBookings ?> Pending</span>
                    </div>
                    <span class="dc-arrow"><i class="bi bi-chevron-right"></i></span>
                </a>
            </div>
            <div class="col-6 col-lg-3">
                <a href="my_bookings.php" class="dc-card dc-gold fade-in-up" style="animation-delay: 0.05s;">
                    <div class="dc-icon"><i class="bi bi-journal-check"></i></div>
                    <div class="dc-title">Total Appointments</div>
                    <div class="dc-value"><?= $totalBookings ?></div>
                    <div class="dc-meta"><span>View history</span></div>
                    <span class="dc-arrow"><i class="bi bi-chevron-right"></i></span>
                </a>
            </div>
            <div class="col-6 col-lg-3">
                <a href="book_service.php" class="dc-card dc-amber fade-in-up" style="animation-delay: 0.1s;">
                    <div class="dc-icon"><i class="bi bi-wrench"></i></div>
                    <div class="dc-title">Book Service</div>
                    <div class="dc-meta"><span>Quick booking</span></div>
                    <span class="dc-arrow"><i class="bi bi-chevron-right"></i></span>
                </a>
            </div>
            <div class="col-6 col-lg-3">
                <a href="profile.php?tab=vehicles" class="dc-card dc-blue fade-in-up" style="animation-delay: 0.15s;">
                    <div class="dc-icon"><i class="bi bi-car-front"></i></div>
                    <div class="dc-title">My Vehicles</div>
                    <div class="dc-value"><?= count($customerMotorcycles) ?></div>
                    <div class="dc-meta"><span>Manage vehicles</span></div>
                    <i class="bi bi-motorcycle dc-moto-bg"></i>
                    <span class="dc-arrow"><i class="bi bi-chevron-right"></i></span>
                </a>
            </div>
        </div>

        <!-- Vehicle Health & Status -->
        <?php if (empty($customerMotorcycles)): ?>
            <div class="no-motorcycles fade-in-up">
                <i class="bi bi-motorcycle"></i>
                <h3>No Vehicles Registered</h3>
                <p>You haven't registered any motorcycles yet. Add your first vehicle to track its health and maintenance schedule.</p>
                <a href="profile.php?tab=vehicles" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-2"></i>Add Your First Vehicle
                </a>
            </div>
        <?php else: ?>
            <!-- Your Vehicles switcher -->
            <div class="veh-switch-section fade-in-up">
                <div class="veh-switch-head">
                    <h2>Your Vehicles</h2>
                    <a href="profile.php?tab=vehicles" class="veh-switch-link">Switch Vehicle <i class="bi bi-chevron-down"></i></a>
                </div>
                <div class="veh-switch-row">
                    <?php foreach ($customerMotorcycles as $i => $md):
                        $vm = $md['motorcycle'];
                        $vImg = !empty($vm['image']) ? $vm['image'] : ($modelImages[$vm['model']] ?? null);
                    ?>
                    <button type="button" class="veh-chip <?= $i === 0 ? 'selected' : '' ?>" data-panel="vehicle-panel-<?= $i ?>">
                        <span class="veh-chip-img">
                            <?php if ($vImg): ?><img src="<?= htmlspecialchars($vImg) ?>" alt="<?= htmlspecialchars($vm['model']) ?>"><?php else: ?><i class="bi bi-motorcycle"></i><?php endif; ?>
                        </span>
                        <span class="veh-chip-text">
                            <span class="veh-chip-name"><?= htmlspecialchars(trim(($vm['brand'] ?? '') . ' ' . ($vm['model'] ?? ''))) ?></span>
                            <span class="veh-chip-plate"><?= htmlspecialchars($vm['plate_number'] ?? '') ?></span>
                        </span>
                        <span class="veh-chip-badge">Selected</span>
                        <i class="bi bi-chevron-right veh-chip-caret"></i>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row g-4">
                <!-- Left: vehicle panels -->
                <div class="col-lg-8">
                    <?php foreach ($customerMotorcycles as $index => $motoData): ?>
                        <?php
                            $motorcycle = $motoData['motorcycle'];
                            $healthScore = $motoData['health_score'];
                            $warranty = $motoData['warranty'];
                            $maintenance = $motoData['maintenance'];
                            $inspection = $motoData['latest_inspection'] ?? null;
                            $healthCondition = getHealthScoreCondition($healthScore);
                            $mid = (int) $motorcycle['id'];
                            $recentServices = $recentServicesMap[$mid] ?? [];
                            $motoImgSrc = !empty($motorcycle['image']) ? $motorcycle['image'] : ($modelImages[$motorcycle['model']] ?? null);
                            $ringColor = $healthScore >= 70 ? '#10B981' : ($healthScore >= 40 ? '#EAB308' : '#EF4444');
                            $condCls = $healthScore >= 70 ? 'good' : ($healthScore >= 40 ? 'fair' : 'bad');

                            // Mileage-based service timeline (same logic as customer_health_score.php)
                            $currentMileage = (int) ($motorcycle['current_mileage'] ?? 0);
                            $svcTimeline = [];
                            foreach ($recentServices as $h) {
                                $svcTimeline[] = [
                                    'mileage' => (int) ($h['mileage'] ?? 0),
                                    'label' => $h['service_type'],
                                    'status' => 'completed',
                                    'date' => $h['service_date'],
                                    'sub' => $h['mechanic_remarks'] ?: ($h['parts_replaced'] ?: ''),
                                ];
                            }
                            $recordedKm = array_map('intval', array_column($recentServices, 'mileage'));
                            foreach ($service_plan as $km => $label) {
                                if (!in_array($km, $recordedKm)) {
                                    $svcTimeline[] = [
                                        'mileage' => $km,
                                        'label' => $label,
                                        'status' => $km >= $currentMileage ? 'upcoming' : 'missed',
                                        'date' => null,
                                        'sub' => '',
                                    ];
                                }
                            }
                            if (!in_array($currentMileage, array_map('intval', array_column($svcTimeline, 'mileage')))) {
                                $svcTimeline[] = ['mileage' => $currentMileage, 'label' => 'Current Mileage', 'status' => 'current', 'date' => null, 'sub' => ''];
                            }
                            usort($svcTimeline, fn($a, $b) => $a['mileage'] <=> $b['mileage']);

                            $inspItems = [
                                ['Engine', 'engine', 'bi-gear-fill'],
                                ['Brakes', 'brakes', 'bi-disc-fill'],
                                ['Tires', 'tires', 'bi-record-circle'],
                                ['Battery', 'battery', 'bi-battery-charging'],
                                ['Oil Level', 'fluids', 'bi-droplet-fill'],
                            ];
                        ?>
                        <div class="vehicle-panel <?= $index === 0 ? '' : 'd-none' ?>" id="vehicle-panel-<?= $index ?>">
                            <!-- Vehicle Overview -->
                            <div class="vov-card fade-in-up" style="animation-delay: <?= 0.05 + $index * 0.1 ?>s;">
                                <div class="vov-head">
                                    <span class="vov-head-ico"><i class="bi bi-heart-fill"></i></span>
                                    <h3>Vehicle Overview</h3>
                                </div>
                                <div class="vov-body">
                                    <div class="vov-bike">
                                        <div class="vov-bike-img">
                                            <?php if ($motoImgSrc): ?>
                                                <img src="<?= htmlspecialchars($motoImgSrc) ?>" alt="<?= htmlspecialchars($motorcycle['model']) ?>">
                                            <?php else: ?>
                                                <i class="bi bi-motorcycle"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="vov-bike-name"><?= htmlspecialchars($motorcycle['brand']) ?> <?= htmlspecialchars($motorcycle['model']) ?></div>
                                        <div class="vov-bike-plate"><?= htmlspecialchars($motorcycle['plate_number']) ?></div>
                                        <div class="vov-bike-meta">
                                            <span class="vov-type"><i class="bi bi-motorcycle"></i> Motorcycle</span>
                                            <span class="vov-status">Active</span>
                                        </div>
                                    </div>
                                    <div class="vov-health">
                                        <div class="vov-ring" style="--p: <?= (int) $healthScore ?>; --c: <?= $ringColor ?>"><span><?= $healthScore ?><small>/100</small></span></div>
                                        <div class="vov-health-label">Overall Health</div>
                                        <div class="vov-health-cond <?= $condCls ?>"><?= $healthCondition ?></div>
                                    </div>
                                    <div class="vov-comps">
                                        <div class="vov-comps-title">Component Status</div>
                                        <?php foreach ($inspItems as $item):
                                            $val = $inspection[$item[1]] ?? 'Good';
                                            $cls = $val === 'Good' ? 'good' : ($val === 'Fair' ? 'fair' : 'bad');
                                        ?>
                                        <div class="vov-comp">
                                            <span class="vov-comp-ico <?= $cls ?>"><i class="bi <?= $item[2] ?>"></i></span>
                                            <span class="vov-comp-name"><?= $item[0] ?></span>
                                            <span class="vov-comp-pill <?= $cls ?>"><?= htmlspecialchars($val) ?></span>
                                            <i class="bi bi-chevron-right vov-comp-caret"></i>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Service Timeline -->
                            <div class="stl-card">
                                <div class="stl-head">
                                    <i class="bi bi-clock-history stl-head-ico"></i>
                                    <h3>Service Timeline</h3>
                                    <a href="customer_maintenance_history.php" class="stl-link">View History <i class="bi bi-chevron-right"></i></a>
                                </div>
                                <?php if (empty($svcTimeline)): ?>
                                    <div class="stl-empty">No service records yet.</div>
                                <?php else: ?>
                                    <div class="stl-list">
                                        <?php foreach ($svcTimeline as $item):
                                            $st = $item['status'];
                                            if ($st === 'missed') $st = 'completed';
                                            $statusLabel = $st === 'completed' ? 'Completed' : ($st === 'current' ? 'Current' : 'Scheduled');
                                        ?>
                                        <div class="stl-item <?= $st ?>">
                                            <span class="stl-dot"></span>
                                            <div class="stl-date"><?= $item['date'] ? date('M j, Y', strtotime($item['date'])) : '—' ?></div>
                                            <div class="stl-main">
                                                <span class="stl-type"><?= htmlspecialchars($item['label']) ?></span>
                                                <span class="stl-pill <?= $st ?>"><?= $statusLabel ?></span>
                                            </div>
                                            <div class="stl-sub">
                                                <?= number_format($item['mileage']) ?> km<?= $item['sub'] !== '' ? ' — ' . htmlspecialchars($item['sub']) : '' ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                   
                </div>

                <!-- Right column -->
                <div class="col-lg-4">
                    <!-- Upcoming Appointment -->
                    <div class="ua-card fade-in-up">
                        <div class="ua-head">
                            <i class="bi bi-calendar-event ua-head-ico"></i>
                            <h3>Upcoming Appointment</h3>
                            <a href="my_bookings.php" class="ua-link">View All</a>
                        </div>
                        <?php if ($upcomingBooking): ?>
                            <div class="ua-body">
                                <div class="ua-service">
                                    <span class="ua-service-ico"><i class="bi bi-wrench"></i></span>
                                    <div class="ua-service-text">
                                        <div class="ua-service-name"><?= htmlspecialchars($upcomingBooking['service_names']) ?></div>
                                        <div class="ua-service-bike"><?= htmlspecialchars(trim(($upcomingBooking['brand'] ?? '') . ' ' . ($upcomingBooking['model'] ?? ''))) ?><?= $upcomingBooking['plate_number'] ? ' (' . htmlspecialchars($upcomingBooking['plate_number']) . ')' : '' ?></div>
                                    </div>
                                </div>
                                <div class="ua-meta">
                                    <span><i class="bi bi-calendar3"></i> <?= date('M j, Y', strtotime($upcomingBooking['schedule_date'])) ?></span>
                                    <?php if ($upcomingBooking['schedule_start_time']): ?>
                                        <span><i class="bi bi-clock"></i> <?= date('g:i A', strtotime($upcomingBooking['schedule_start_time'])) ?><?= $upcomingBooking['schedule_end_time'] ? ' – ' . date('g:i A', strtotime($upcomingBooking['schedule_end_time'])) : '' ?></span>
                                    <?php endif; ?>
                                </div>
                                <a href="my_bookings.php" class="ua-btn">View Details <i class="bi bi-arrow-right"></i></a>
                            </div>
                        <?php else: ?>
                            <div class="ua-empty">
                                <i class="bi bi-calendar-x"></i>
                                <p>No upcoming appointments.</p>
                                <a href="book_service.php" class="ua-btn">Book Service <i class="bi bi-arrow-right"></i></a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Maintenance Alerts -->
                    <div class="al-card fade-in-up" style="animation-delay: 0.05s;">
                        <div class="al-head">
                            <i class="bi bi-bell-fill al-head-ico"></i>
                            <h3>Maintenance Alerts</h3>
                            <?php if ($alerts): ?><span class="al-count"><?= count($alerts) ?></span><?php endif; ?>
                        </div>
                        <?php if (empty($alerts)): ?>
                            <div class="al-empty"><i class="bi bi-check-circle-fill"></i> All good! No alerts.</div>
                        <?php else: ?>
                            <div class="al-list">
                                <?php foreach ($alerts as $al): ?>
                                    <div class="al-item">
                                        <span class="al-ico <?= $al['cls'] ?>"><i class="bi <?= $al['icon'] ?>"></i></span>
                                        <div class="al-text">
                                            <div class="al-title"><?= htmlspecialchars($al['title']) ?></div>
                                            <div class="al-sub"><?= htmlspecialchars($al['sub']) ?></div>
                                        </div>
                                        <i class="bi bi-chevron-right al-caret"></i>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Tip card -->
                    <div class="tip-card fade-in-up" style="animation-delay: 0.1s;">
                        <span class="tip-ico"><i class="bi bi-lightbulb-fill"></i></span>
                        <div class="tip-text">
                            <strong>Keep your ride in top shape!</strong>
                            <p>Regular maintenance ensures better performance, safety, and longer life for your motorcycle.</p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function confirmLogout() {
        Swal.fire({
            title: 'Ready to log out?',
            text: "You will need to log back in to access your dashboard.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, Log Me Out!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'logout.php';
            }
        });
    }

    // Scroll animations
    document.addEventListener('DOMContentLoaded', function() {
        const observerOptions = {
            root: null,
            rootMargin: '0px',
            threshold: 0.1
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        document.querySelectorAll('.fade-in-up').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'opacity 0.8s ease, transform 0.8s ease';
            observer.observe(el);
        });
    });
</script>

<?php if (isset($_SESSION['show_confirmation_modal']) && $_SESSION['show_confirmation_modal']): ?>
<!-- Booking Confirmation Modal -->
<div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
            <div class="modal-header" style="background: var(--primary-gradient); color: white; border-radius: 20px 20px 0 0; padding: 25px;">
                <h5 class="modal-title fw-bold" id="confirmationModalLabel">
                    <i class="bi bi-check-circle-fill me-2"></i> Appointment Confirmed!
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-4">
                <div class="mb-4">
                    <i class="bi bi-calendar-check" style="font-size: 4rem; color: var(--secondary-color);"></i>
                </div>
                <h4 class="fw-bold mb-3" style="color: var(--primary-color);">Thank You!</h4>
                <p class="lead mb-3"><?= htmlspecialchars($_SESSION['confirmation_message'] ?? 'Your booking has been confirmed.') ?></p>
                <div class="alert alert-info" style="background: linear-gradient(135deg, rgba(250, 204, 21, 0.1) 0%, rgba(255,255,255,0.5) 100%); border: 2px solid var(--accent-color); border-radius: 12px;">
                    <i class="bi bi-hourglass-split me-2"></i>
                    <strong>Waiting for admin confirmation...</strong><br>
                    <small>You will receive a notification once your booking is approved.</small>
                </div>
            </div>
            <div class="modal-footer justify-content-center pb-4" style="border: none;">
                <button type="button" class="btn btn-lg" data-bs-dismiss="modal" style="background: var(--primary-gradient); color: white; border-radius: 50px; padding: 12px 40px; font-weight: 600;">
                    <i class="bi bi-arrow-right me-2"></i> Go to My Bookings
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // Show confirmation modal on page load
    document.addEventListener('DOMContentLoaded', function() {
        var confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
        confirmationModal.show();
    });
</script>

<?php 
    // Clear the modal flags after showing
    unset($_SESSION['show_confirmation_modal']);
    unset($_SESSION['booking_details']);
endif; 
?>

<!-- Customer utility scripts -->
<script>
(function() {
    'use strict';

    // Top bar user dropdown toggle
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

    // Vehicle switcher: show the selected motorcycle's panel
    document.querySelectorAll('.veh-chip[data-panel]').forEach(function(chip) {
        chip.addEventListener('click', function() {
            document.querySelectorAll('.veh-chip[data-panel]').forEach(function(c) { c.classList.remove('selected'); });
            document.querySelectorAll('.vehicle-panel').forEach(function(p) { p.classList.add('d-none'); });
            this.classList.add('selected');
            const panel = document.getElementById(this.dataset.panel);
            if (panel) panel.classList.remove('d-none');
        });
    });
})();
</script>
<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.js"></script>
<script src="assets/notifications.js"></script>
<?php render_notifications(); ?>

</body>
</html>
