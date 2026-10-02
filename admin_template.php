<?php
/**
 * Admin Template with Sidebar
 * Include this file at the top of every admin page for consistent layout
 * 
 * Usage:
 * require 'admin_template.php';
 * Then place your content inside: <div class="main-content">...</div>
 */

session_start();
require 'db.php';
require 'notification_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Get admin info
$adminName = $_SESSION['username'] ?? 'Admin';

// Get pending bookings count for badge
$pendingCount = 0;
try {
    $pendingCount = $pdo->query("
        SELECT COUNT(id) FROM bookings 
        WHERE status IN ('pending', 'unassigned', 'deposit_submitted')
    ")->fetchColumn();
} catch (PDOException $e) {
    $pendingCount = 0;
}

// Current page for active menu highlighting
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? $pageTitle : 'Admin Dashboard' ?> | Mindanao Eversure</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="fonts.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <style>
        :root {
            --sidebar-width: 280px;
            --sidebar-collapsed: 80px;
            --primary-color: #0f172a;
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
            --accent-color: #f59e0b;
            --accent-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --bg-light: #f1f5f9;
            --bg-card: #ffffff;
            --text-dark: #1e293b;
            --text-light: #64748b;
            --success: #10b981;
            --danger: #ef4444;
            --info: #3b82f6;
            --warning: #f59e0b;
            --sidebar-bg: #0f172a;
            --sidebar-hover: rgba(245, 158, 11, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg-light);
            color: var(--text-dark);
            overflow-x: hidden;
        }

        /* Animated Background */
        .bg-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }

        .bg-animation .circle {
            position: absolute;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, transparent 70%);
            animation: float 20s infinite ease-in-out;
        }

        .bg-animation .circle:nth-child(1) {
            width: 400px;
            height: 400px;
            top: -100px;
            right: -100px;
            animation-delay: 0s;
        }

        .bg-animation .circle:nth-child(2) {
            width: 300px;
            height: 300px;
            bottom: -50px;
            left: -50px;
            animation-delay: 5s;
        }

        .bg-animation .circle:nth-child(3) {
            width: 250px;
            height: 250px;
            top: 50%;
            right: 10%;
            animation-delay: 10s;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -30px) scale(1.1); }
            66% { transform: translate(-20px, 20px) scale(0.9); }
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-bg);
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
        }

        .sidebar-header {
            padding: 12px 20px;
            text-align: center;
            border-bottom: 2px solid rgba(245, 158, 11, 0.3);
            position: relative;
            background: rgba(0, 0, 0, 0.2);
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sidebar-header::after {
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

        .sidebar-brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
            color: white;
            font-size: 1.4rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .sidebar-brand i {
            background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 50%, #f59e0b 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-size: 1.8rem;
        }

        .sidebar-brand span {
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .sidebar-menu {
            padding: 20px 0;
            flex: 1;
        }

        .menu-section {
            padding: 0 20px;
            margin-bottom: 20px;
        }

        .menu-title {
            color: rgba(255, 255, 255, 0.4);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
            padding-left: 15px;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            border-radius: 12px;
            margin: 4px 15px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .menu-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: var(--accent-gradient);
            transform: scaleY(0);
            transition: transform 0.3s ease;
        }

        .menu-item:hover,
        .menu-item.active {
            background: var(--sidebar-hover);
            color: white;
        }

        .menu-item:hover::before,
        .menu-item.active::before {
            transform: scaleY(1);
        }

        .menu-item i {
            font-size: 1.3rem;
            width: 30px;
            text-align: center;
        }

        .menu-item span {
            font-size: 0.95rem;
            font-weight: 500;
        }

        .menu-badge {
            margin-left: auto;
            background: var(--danger);
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            animation: pulse-badge 2s infinite;
        }

        @keyframes pulse-badge {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            50% { transform: scale(1.05); box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); }
        }

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
        }

        .user-avatar {
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
        }

        .user-info {
            flex: 1;
            min-width: 0;
        }

        .user-name {
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.75rem;
        }

        .btn-logout {
            background: transparent;
            border: none;
            color: rgba(255, 255, 255, 0.5);
            cursor: pointer;
            padding: 5px;
            
        }

        .btn-logout:hover {
            color: var(--danger);
        }

        /* Main Content */
        .main-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        .top-header {
            background: #1e293b;
            padding: 12px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 2px solid rgba(245, 158, 11, 0.3);
            overflow: hidden;
            position: relative;
            height: 60px;
        }

        .top-header::after {
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

        .top-header .page-title,
        .top-header .text-muted {
            color: white !important;
        }

        .top-header .page-title i {
            background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 50%, #f59e0b 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .top-header .btn-toggle-sidebar {
            color: white !important;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
            line-height: 1.2;
        }

        .page-title i {
            color: var(--accent-color);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .btn-toggle-sidebar {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-dark);
            cursor: pointer;
        }

        .content-area {
            padding: 30px;
        }

        /* Responsive */
        @media (max-width: 991px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-wrapper {
                margin-left: 0;
            }

            .btn-toggle-sidebar {
                display: block;
            }

            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 999;
            }

            .sidebar-overlay.show {
                display: block;
            }
        }

        /* Scroll to top */
        .scroll-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 45px;
            height: 45px;
            background: var(--accent-gradient);
            color: white;
            border: none;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            visibility: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            z-index: 99;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
        }

        .scroll-top.visible {
            opacity: 1;
            visibility: visible;
        }

        .scroll-top:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.5);
        }

        /* Floating icons for top header */
        .header-floating-icons {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 1;
        }

        .header-floating-icons i {
            position: absolute;
            color: rgba(30, 41, 59, 0.2);
            font-size: 1.5rem;
            animation: floatHeader 4s ease-in-out infinite;
        }

        .header-floating-icons i:nth-child(1) { top: 20%; left: 10%; animation-delay: 0s; }
        .header-floating-icons i:nth-child(2) { top: 30%; right: 15%; animation-delay: 0.5s; font-size: 1.2rem; }
        .header-floating-icons i:nth-child(3) { top: 50%; left: 25%; animation-delay: 1s; font-size: 1.8rem; }
        .header-floating-icons i:nth-child(4) { top: 40%; right: 30%; animation-delay: 1.5s; font-size: 1.3rem; }
        .header-floating-icons i:nth-child(5) { top: 60%; left: 15%; animation-delay: 2s; font-size: 1.6rem; }
        .header-floating-icons i:nth-child(6) { top: 25%; right: 25%; animation-delay: 2.5s; font-size: 1.4rem; }

        @keyframes floatHeader {
            0%, 100% { transform: translateY(0) rotate(0deg); opacity: 0.3; }
            50% { transform: translateY(-10px) rotate(5deg); opacity: 0.6; }
        }
    </style>
</head>
<body>
    <!-- Animated Background -->
    <div class="bg-animation">
        <div class="circle"></div>
        <div class="circle"></div>
        <div class="circle"></div>
    </div>

    <!-- Sidebar Overlay for Mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="dashboard_admin.php" class="sidebar-brand">
                <i class="bi bi-gear-fill"></i>
                <span>Mindanao Eversure</span>
            </a>
        </div>

        <nav class="sidebar-menu">
            <div class="menu-section">
                <div class="menu-title">Main Menu</div>
                <a href="dashboard_admin.php" class="menu-item <?= $currentPage == 'dashboard_admin' ? 'active' : '' ?>">
                    <i class="bi bi-grid-fill"></i>
                    <span>Dashboard</span>
                </a>
                <a href="manage_bookings.php" class="menu-item <?= $currentPage == 'manage_bookings' ? 'active' : '' ?>">
                    <i class="bi bi-calendar-check-fill"></i>
                    <span>Bookings</span>
                    <?php if ($pendingCount > 0): ?>
                        <span class="menu-badge"><?= $pendingCount ?></span>
                    <?php endif; ?>
                </a>
                <a href="bookings_status.php" class="menu-item <?= $currentPage == 'bookings_status' ? 'active' : '' ?>">
                    <i class="bi bi-calendar-check-fill"></i>
                    <span>Bookings Status</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-title">Management</div>
                <a href="manage_customers.php" class="menu-item <?= $currentPage == 'manage_customers' ? 'active' : '' ?>">
                    <i class="bi bi-person-lines-fill"></i>
                    <span>Customers</span>
                </a>
                <a href="manage_motorcycles.php" class="menu-item <?= $currentPage == 'manage_motorcycles' ? 'active' : '' ?>">
                    <i class="bi bi-bicycle-fill"></i>
                    <span>Motorcycles</span>
                </a>
                <a href="manage_mechanics.php" class="menu-item <?= $currentPage == 'manage_mechanics' ? 'active' : '' ?>">
                    <i class="bi bi-tools-fill"></i>
                    <span>Mechanics</span>
                </a>
                <a href="mechanic_assignment.php" class="menu-item <?= $currentPage == 'mechanic_assignment' ? 'active' : '' ?>">
                    <i class="bi bi-person-check-fill"></i>
                    <span>Assignments</span>
                </a>
                <a href="services.php" class="menu-item <?= $currentPage == 'services' ? 'active' : '' ?>">
                    <i class="bi bi-gear-fill"></i>
                    <span>Services</span>
                </a>
                <a href="admin_availability.php" class="menu-item <?= $currentPage == 'admin_availability' ? 'active' : '' ?>">
                    <i class="bi bi-calendar-week-fill"></i>
                    <span>Availability</span>
                </a>
                <a href="admin_warranty.php" class="menu-item <?= $currentPage == 'admin_warranty' ? 'active' : '' ?>">
                    <i class="bi bi-shield-check-fill"></i>
                    <span>Warranty</span>
                </a>
                <a href="admin_service_packages.php" class="menu-item <?= $currentPage == 'admin_service_packages' ? 'active' : '' ?>">
                    <i class="bi bi-box-seam-fill"></i>
                    <span>Service Packages</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-title">Communication</div>
                <a href="sms_history.php" class="menu-item <?= $currentPage == 'sms_history' ? 'active' : '' ?>">
                    <i class="bi bi-chat-dots-fill"></i>
                    <span>SMS History</span>
                </a>
            </div>
            <div class="menu-section">
                <div class="menu-title">Account</div>
                <a href="admin_profile.php" class="menu-item <?= $currentPage == 'admin_profile' ? 'active' : '' ?>">
                    <i class="bi bi-person-gear"></i>
                    <span>Profile Settings</span>
                </a>
            </div>
        </nav>

        <div class="sidebar-footer">
            <div class="user-profile">
                <div class="user-avatar">
                    <?= strtoupper(substr($adminName, 0, 1)) ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?= htmlspecialchars($adminName) ?></div>
                    <div class="user-role">Administrator</div>
                </div>
                <button class="btn-logout" onclick="confirmLogout()" title="Logout">
                    <i class="bi bi-box-arrow-right fs-5"></i>
                </button>
            </div>
        </div>
    </aside>

    <!-- Main Content Wrapper -->
    <div class="main-wrapper">
        <!-- Top Header -->
        <header class="top-header">
            <!-- Floating Icons -->
            <div class="header-floating-icons">
                <i class="bi bi-gear"></i>
                <i class="bi bi-wrench"></i>
                <i class="bi bi-tools"></i>
                <i class="bi bi-car-front"></i>
                <i class="bi bi-speedometer2"></i>
                <i class="bi bi-calendar-check"></i>
            </div>
            <button class="btn-toggle-sidebar" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
            </button>
            <h1 class="page-title">
                <i class="bi bi-speedometer2"></i>
                <?= isset($pageTitle) ? $pageTitle : 'Dashboard' ?>
            </h1>
            <div class="header-actions">
                <span class="text-muted d-none d-md-block"><?= date('l, F j, Y') ?></span>
            </div>
        </header>

        <!-- Content Area -->
        <main class="content-area">
