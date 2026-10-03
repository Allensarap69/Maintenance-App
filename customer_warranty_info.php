<?php
session_start();
require 'db.php';

// Security check - only customers can access their own warranty information
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    header("Location: index.php");
    exit;
}

$customer_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Customer';
$msg = "";
$msg_type = "";
$active_page = basename($_SERVER['PHP_SELF']);

// --- Fetch Customer's Warranties ---
$warranties = [];
try {
    $stmt = $pdo->prepare("
        SELECT w.*,
               CONCAT(m.brand, ' ', m.model, ' (', m.plate_number, ')') as motorcycle_info,
               m.brand, m.model, m.plate_number, m.year_model
        FROM warranties w
        JOIN motorcycles m ON w.motorcycle_id = m.id
        WHERE w.customer_id = ?
        ORDER BY w.warranty_end DESC
    ");
    $stmt->execute([$customer_id]);
    $warranties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching warranties: " . $e->getMessage());
}

// --- Fetch Warranty Claims ---
$warranty_claims = [];
try {
    $stmt = $pdo->prepare("
        SELECT wc.*, 
               w.warranty_type,
               CONCAT(m.brand, ' ', m.model, ' (', m.plate_number, ')') as motorcycle_info
        FROM warranty_claims wc
        JOIN warranties w ON wc.warranty_id = w.id
        JOIN motorcycles m ON w.motorcycle_id = m.id
        WHERE w.customer_id = ?
        ORDER BY wc.claim_date DESC
    ");
    $stmt->execute([$customer_id]);
    $warranty_claims = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching warranty claims: " . $e->getMessage());
}

// --- Helper function to calculate warranty status ---
function getWarrantyStatusInfo($warranty) {
    $today = new DateTime();
    $start_date = new DateTime($warranty['warranty_start']);
    $end_date = new DateTime($warranty['warranty_end']);
    
    $status = [
        'status' => 'unknown',
        'is_valid' => false,
        'days_remaining' => 0,
        'total_days' => 0,
        'percentage_remaining' => 0,
        'message' => '',
        'color_class' => ''
    ];
    
    $status['total_days'] = $start_date->diff($end_date)->days;
    
    if ($warranty['status'] === 'expired' || $end_date < $today) {
        $status['status'] = 'expired';
        $status['is_valid'] = false;
        $status['days_remaining'] = 0;
        $status['percentage_remaining'] = 0;
        $status['message'] = 'Warranty Expired';
        $status['color_class'] = 'danger';
    } elseif ($warranty['status'] === 'claimed') {
        $status['status'] = 'claimed';
        $status['is_valid'] = false;
        $status['days_remaining'] = $today->diff($end_date)->days;
        $status['percentage_remaining'] = ($status['days_remaining'] / $status['total_days']) * 100;
        $status['message'] = 'Warranty Claimed';
        $status['color_class'] = 'warning';
    } elseif ($warranty['status'] === 'cancelled') {
        $status['status'] = 'cancelled';
        $status['is_valid'] = false;
        $status['days_remaining'] = $today->diff($end_date)->days;
        $status['percentage_remaining'] = ($status['days_remaining'] / $status['total_days']) * 100;
        $status['message'] = 'Warranty Cancelled';
        $status['color_class'] = 'secondary';
    } elseif ($start_date > $today) {
        $status['status'] = 'pending';
        $status['is_valid'] = false;
        $status['days_remaining'] = $start_date->diff($today)->days;
        $status['percentage_remaining'] = 100;
        $status['message'] = 'Warranty Not Yet Active';
        $status['color_class'] = 'info';
    } else {
        $status['status'] = 'active';
        $status['is_valid'] = true;
        $status['days_remaining'] = $today->diff($end_date)->days;
        $status['percentage_remaining'] = ($status['days_remaining'] / $status['total_days']) * 100;
        $status['message'] = 'Warranty Active';
        $status['color_class'] = 'success';
    }
    
    return $status;
}

$pageTitle = 'Warranty Information';
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
    <title><?= htmlspecialchars($pageTitle) ?> | AutoCare Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="fonts.css">
    <style>
        /* ============================================
           MODERN WARRANTY PAGE DESIGN
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

        .btn-logout:hover {
            background: #dc3545;
            border-color: #dc3545;
            color: white;
            transform: translateY(-2px);
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
            margin-top: 0;
            margin-bottom: 24px;
            position: relative;
            z-index: 10;
        }

        .stat-card {
            background: #ffffff;
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
            display: flex;
            align-items: center;
            gap: 16px;
            height: 100%;
        }

        .stat-card:hover {
            border-color: rgba(0, 0, 0, 0.12);
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

        .stat-icon-primary {
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.1) 0%, rgba(30, 58, 95, 0.05) 100%);
            color: var(--primary-color);
        }

        .stat-icon-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(5, 150, 105, 0.05) 100%);
            color: var(--success-color);
        }

        .stat-icon-warning {
            background: linear-gradient(135deg, rgba(250, 204, 21, 0.1) 0%, rgba(250, 204, 21, 0.05) 100%);
            color: #EAB308;
        }

        .stat-icon-info {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(37, 99, 235, 0.05) 100%);
            color: var(--info-color);
        }

        .stat-value {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-dark);
            line-height: 1.1;
            word-break: break-word;
            margin-bottom: 0;
        }

        .stat-label {
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-light);
            word-break: break-word;
        }

        /* ============================================
           WARRANTY CARDS
           ============================================ */
        .warranty-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            border: none;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            overflow: hidden;
            position: relative;
        }

        .warranty-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12);
        }

        .warranty-header {
            padding: 20px 25px;
            color: white;
            position: relative;
        }

        .warranty-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: rgba(255, 255, 255, 0.3);
        }

        .warranty-header.active {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .warranty-header.expired {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        .warranty-header.pending {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }

        .warranty-header.claimed {
            background: linear-gradient(135deg, #3b82f6 0%, #EAB308 100%);
        }

        .warranty-header.cancelled {
            background: linear-gradient(135deg, #64748b 0%, #475569 100%);
        }

        .warranty-header .d-flex {
            flex-wrap: wrap;
            gap: 8px;
        }
        .warranty-header .d-flex > div:first-child {
            min-width: 0;
            flex: 1;
        }
        .warranty-header h5,
        .warranty-header small {
            word-break: break-word;
        }

        .days-remaining {
            font-size: 3rem;
            font-weight: 700;
            line-height: 1;
            text-align: center;
        }

        .progress-bar-custom {
            height: 10px;
            border-radius: 5px;
            background-color: #e2e8f0;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 5px;
            transition: width 0.5s ease;
        }

        .coverage-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 1px solid #e2e8f0;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .info-row:last-child {
            border-bottom: none;
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

        /* ============================================
           CLAIM CARDS
           ============================================ */
        .claim-card {
            background: white;
            border-left: 4px solid;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        }

        .claim-card:hover {
            transform: translateX(5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .claim-card.pending {
            border-left-color: var(--info-color);
        }

        .claim-card.approved {
            border-left-color: var(--success-color);
        }

        .claim-card.rejected {
            border-left-color: var(--danger-color);
        }

        .claim-card.completed {
            border-left-color: var(--warning-color);
        }

        .claim-status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
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

        /* --- COMPACT / MINIMIZE --- */
        h3 { font-size: 1.2rem; }
        h4 { font-size: 1rem; }
        .stat-card { padding: 15px; }
        .stat-icon { width: 40px; height: 40px; font-size: 1.2rem; }
        .stat-value { font-size: 1.3rem; }
        .stat-label { font-size: 0.7rem; }
        .warranty-card { border-radius: 14px; }
        .warranty-card .card-body { padding: 15px; }
        .warranty-header { padding: 15px 18px; }
        .warranty-header h5 { font-size: 0.85rem; }
        .warranty-header small { font-size: 0.75rem; }
        .warranty-header .badge { font-size: 0.7rem; }
        .days-remaining { font-size: 2rem; }
        .info-row { padding: 8px 0; }
        .info-label { font-size: 0.75rem; }
        .info-value { font-size: 0.8rem; }
        .coverage-badge { padding: 5px 10px; font-size: 0.7rem; }
        .claim-card { padding: 15px; }
        .claim-card h5 { font-size: 0.9rem; }
        .claim-status-badge { padding: 4px 10px; font-size: 0.7rem; }
        .empty-state { padding: 50px 15px; }
        .empty-state h4 { font-size: 1rem; }
        .empty-state p { font-size: 0.8rem; }
        .table { font-size: 0.75rem; }
        .table th,
        .table td { padding: 0.4rem; }

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
                margin-top: 0;
                margin-bottom: 24px;
            }

            .stat-card {
                padding: 20px;
                margin-bottom: 15px;
            }

            .stat-value {
                font-size: 2rem;
            }

            .days-remaining {
                font-size: 2.5rem;
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
        @media (max-width: 576px) {
            .stat-card {
                padding: 15px;
                border-radius: 14px;
            }
            .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 1.1rem;
                border-radius: 10px;
            }
            .stat-value {
                font-size: 1.5rem;
            }
            .stat-label {
                font-size: 0.75rem;
            }
            .warranty-header {
                padding: 15px;
            }
            .warranty-card .card-body {
                padding: 15px;
            }
            .days-remaining {
                font-size: 2rem;
            }
        }
    /* --- Match customer_health_score layout and stat-card sizing --- */
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
        box-shadow: none;
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
            <h1 class="top-bar-title">Warranty Information</h1>
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
<!-- Statistics Cards -->
<div class="container stats-container">
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-primary">
                        <i class="bi bi-shield-check"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?= count($warranties) ?></div>
                        <div class="stat-label">Total Warranties</div>
                    </div>
                </div>
        </div>
        <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-success">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div>
                        <div class="stat-value">
                            <?= count(array_filter($warranties, function($w) { 
                                $status = getWarrantyStatusInfo($w); 
                                return $status['is_valid']; 
                            })) ?>
                        </div>
                        <div class="stat-label">Valid Coverage</div>
                    </div>
                </div>
        </div>
        <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-warning">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?= count($warranty_claims) ?></div>
                        <div class="stat-label">Total Claims</div>
                    </div>
                </div>
        </div>
        <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon stat-icon-info">
                        <i class="bi bi-clock"></i>
                    </div>
                    <div>
                        <div class="stat-value">
                            <?= count(array_filter($warranty_claims, function($c) { 
                                return $c['claim_status'] === 'pending'; 
                            })) ?>
                        </div>
                        <div class="stat-label">Pending Claims</div>
                    </div>
                </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="container py-5">

    <!-- Section Tabs -->
    <ul class="nav nav-pills mb-4" id="customerWarrantyTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="warranties-tab" data-bs-toggle="pill" data-bs-target="#customer-warranties" type="button" role="tab" aria-controls="customer-warranties" aria-selected="true">
                <i class="bi bi-shield-check me-2"></i>Your Warranties
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="claims-tab" data-bs-toggle="pill" data-bs-target="#customer-claims" type="button" role="tab" aria-controls="customer-claims" aria-selected="false">
                <i class="bi bi-file-earmark-text me-2"></i>Warranty Claims
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="customer-warranties" role="tabpanel" aria-labelledby="warranties-tab">
            <!-- Warranty Cards -->
            <div class="row mb-5">
                <div class="col-12">
                    <h3 class="mb-4"><i class="bi bi-shield-check me-2"></i>Your Warranties</h3>

                    <?php if (empty($warranties)): ?>
                        <div class="empty-state">
                            <i class="bi bi-shield-x"></i>
                            <h4>No Warranties Found</h4>
                            <p>You don't have any active warranties registered for your motorcycles.</p>
                            <p class="small">Contact our service center to learn about warranty options.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Motorcycle</th>
                                        <th>Type</th>
                                        <th>Period</th>
                                        <th class="text-center">Days Left</th>
                                        <th>Status</th>
                                        <th>Coverage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($warranties as $warranty): ?>
                                        <?php $status_info = getWarrantyStatusInfo($warranty); ?>
                                        <tr>
                                            <td><?= htmlspecialchars($warranty['motorcycle_info']) ?></td>
                                            <td><?= ucfirst($warranty['warranty_type']) ?></td>
                                            <td>
                                                <small><?= date('M j, Y', strtotime($warranty['warranty_start'])) ?></small><br>
                                                <small class="text-muted"><?= date('M j, Y', strtotime($warranty['warranty_end'])) ?></small>
                                            </td>
                                            <td class="text-center">
                                                <span class="fw-bold text-<?= $status_info['color_class'] ?>"><?= $status_info['days_remaining'] ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $status_info['color_class'] ?>">
                                                    <?= $status_info['message'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="coverage-badge">
                                                    <i class="bi bi-clock me-1"></i><?= $warranty['warranty_duration_months'] ?> Months
                                                </span>
                                                <?php if ($warranty['coverage_details']): ?>
                                                    <p class="text-muted small mb-0 mt-1"><?= htmlspecialchars($warranty['coverage_details']) ?></p>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <!-- Warranty Claims -->
    <div class="tab-pane fade" id="customer-claims" role="tabpanel" aria-labelledby="claims-tab">
        <div class="row">
        <div class="col-12">
            <h3 class="mb-4"><i class="bi bi-file-earmark-text me-2"></i>Warranty Claims</h3>
        </div>
        
        <?php if (empty($warranty_claims)): ?>
            <div class="col-12">
                <div class="empty-state">
                    <i class="bi bi-clipboard-x"></i>
                    <h4>No Warranty Claims</h4>
                    <p>You haven't made any warranty claims yet.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="col-12">
                <?php foreach ($warranty_claims as $claim): ?>
                    <div class="claim-card <?= $claim['claim_status'] ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="mb-1">
                                    <?= htmlspecialchars($claim['motorcycle_info']) ?>
                                </h5>
                                <small class="text-muted">
                                    <i class="bi bi-calendar me-1"></i>
                                    <?= date('F j, Y', strtotime($claim['claim_date'])) ?>
                                </small>
                            </div>
                            <span class="claim-status-badge bg-<?= $claim['claim_status'] === 'approved' ? 'success' : ($claim['claim_status'] === 'rejected' ? 'danger' : ($claim['claim_status'] === 'completed' ? 'warning' : 'info')) ?>">
                                <?= ucfirst($claim['claim_status']) ?>
                            </span>
                        </div>
                        
                        <div class="mt-3">
                            <strong><i class="bi bi-chat me-2"></i>Claim Description:</strong>
                            <p class="mb-2"><?= htmlspecialchars($claim['claim_description']) ?></p>
                        </div>
                        
                        <?php if ($claim['cost'] > 0): ?>
                            <div class="mb-2">
                                <strong><i class="bi bi-currency-dollar me-2"></i>Cost:</strong>
                                <span>₱<?= number_format($claim['cost'], 2) ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($claim['resolution_details']): ?>
                            <div class="mb-2">
                                <strong><i class="bi bi-check-circle me-2"></i>Resolution:</strong>
                                <p class="mb-0 small"><?= htmlspecialchars($claim['resolution_details']) ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($claim['processed_at']): ?>
                            <small class="text-muted">
                                <i class="bi bi-clock me-1"></i>
                                Processed: <?= date('F j, Y g:i A', strtotime($claim['processed_at'])) ?>
                            </small>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
        </div>
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
