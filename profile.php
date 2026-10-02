<?php
session_start();
require 'db.php'; // Ensure your database connection is included

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? 'Customer';
$msg = '';
$user_data = [];
$vehicles = [];
$active_page = basename($_SERVER['PHP_SELF']);

// --- Tab Management Logic ---
// 1. Check for 'tab' parameter from the URL (e.g., from dashboard link)
// 2. Default to 'profile' if no tab is set.
// 3. Check for 'msg' parameter from the URL (e.g., from book_service.php redirection)
$active_tab = 'profile';
$url_msg = $_GET['msg'] ?? '';

// If a message exists in the URL, use it and ensure the vehicle tab is active
if (!empty($url_msg)) {
    // The message from book_service.php is already URL-encoded
    $msg = urldecode($url_msg);
    // The vehicle tabs were removed, keep the message on the profile tab
    $active_tab = 'profile'; 
}


if ($user_id) {
    try {
        // 1. Fetch User Data
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        unset($user_data['password']);

        // 2. Fetch Customer's Motorcycles (Using the 'motorcycles' table linked by user_id)
        $stmt = $pdo->prepare("SELECT id, brand, model, year_model, plate_number FROM motorcycles WHERE user_id = ? AND status = 'active' ORDER BY id DESC");
        $stmt->execute([$user_id]);
        $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $msg = "❌ Database error while fetching profile data: " . $e->getMessage();
    }
}

// --- Handle Form Submission (Update Profile) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_username = trim($_POST['username']);
    $new_email = trim($_POST['email']);
    $new_phone = trim($_POST['phone']);
    $active_tab = 'profile'; // Keep tab active after submission

    if (empty($new_username) || empty($new_email)) {
        $msg = "❌ Username and Email are required fields.";
    } else if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $msg = "❌ Invalid email format.";
    } else {
        try {
            // Update the user's details
            $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, phone = ? WHERE id = ?");
            if ($stmt->execute([$new_username, $new_email, $new_phone, $user_id])) {
                
                // Update session data immediately
                $_SESSION['username'] = $new_username;
                
                // Re-fetch data to update the form fields
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
                unset($user_data['password']);
                
                $msg = "✅ Your profile has been successfully updated!";
            } else {
                $msg = "❌ Profile update failed.";
            }
        } catch (PDOException $e) {
            $msg = "❌ Error updating profile: " . $e->getMessage();
        }
    }
}

// --- Handle Motorcycle Management (Adding a new motorcycle) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_motorcycle'])) {
    $brand = trim($_POST['brand']);
    $model = trim($_POST['model']);
    $year_model = trim($_POST['year_model']);
    $plate_number = trim($_POST['plate_number']);
    $color = trim($_POST['color']);
    $engine_number = trim($_POST['engine_number']);
    $chassis_number = trim($_POST['chassis_number']);
    $purchase_date = trim($_POST['purchase_date']);
    $current_mileage = trim($_POST['current_mileage']);
    $active_tab = 'register'; // Keep the registration tab active after submission

    if (empty($brand) || empty($model) || empty($plate_number) || empty($engine_number) || empty($chassis_number) || empty($purchase_date)) {
        $msg = "❌ Motorcycle Brand, Model, Plate Number, Engine Number, Chassis Number, and Purchase Date are required fields.";
    } else {
        try {
            // Check if plate number is already registered
            $check = $pdo->prepare("SELECT id FROM motorcycles WHERE plate_number=?");
            $check->execute([$plate_number]);
            
            if ($check->rowCount() > 0) {
                $msg = "❌ Plate number already registered. Please use a different plate number.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO motorcycles (user_id, brand, model, color, year_model, plate_number, engine_number, chassis_number, purchase_date, current_mileage) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $brand, $model, $color, $year_model, $plate_number, $engine_number, $chassis_number, $purchase_date, $current_mileage]);
                
                $msg = "✅ New motorcycle added successfully! Go to the 'Manage Motorcycles' tab to see the list.";
                
                // Refresh motorcycle list for immediate display
                $stmt = $pdo->prepare("SELECT id, brand, model, year_model, plate_number FROM motorcycles WHERE user_id = ? AND status = 'active' ORDER BY id DESC");
                $stmt->execute([$user_id]);
                $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Redirect to the management tab after successful addition
                header("Location: profile.php?tab=vehicles&msg=" . urlencode("✅ New motorcycle added successfully! Make another booking now!"));
                exit;
            }
        } catch (PDOException $e) {
            $msg = "❌ Error adding motorcycle: " . $e->getMessage();
        }
    }
}

// --- Handle Motorcycle Deletion ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_motorcycle_id'])) {
    $delete_id = intval($_POST['delete_motorcycle_id']);
    $active_tab = 'vehicles'; // Keep the management tab active after submission
    
    try {
        // Check if motorcycle has associated bookings
        $check_bookings = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE vehicle_id = ? AND status IN ('pending', 'accepted', 'in_progress', 'deposit_submitted')");
        $check_bookings->execute([$delete_id]);
        
        if ($check_bookings->fetchColumn() > 0) {
            $msg = "❌ Cannot delete motorcycle. It still has active bookings that need to be completed or cancelled.";
        } else {
            // Ensure the user owns the motorcycle before deleting
            $stmt = $pdo->prepare("DELETE FROM motorcycles WHERE id = ? AND user_id = ?");
            if ($stmt->execute([$delete_id, $user_id]) && $stmt->rowCount() > 0) {
                $msg = "✅ Motorcycle removed successfully.";
                
                // Refresh motorcycle list
                $stmt = $pdo->prepare("SELECT id, brand, model, year_model, plate_number FROM motorcycles WHERE user_id = ? AND status = 'active' ORDER BY id DESC");
                $stmt->execute([$user_id]);
                $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $msg = "❌ Error: Motorcycle not found or you do not have permission to delete it.";
            }
        }
    } catch (PDOException $e) {
        $msg = "❌ Database error during motorcycle deletion.";
    }
}

// --- Determine which vehicle tab to display based on parameter ---
// If the dashboard link sends 'tab=vehicles', we display the vehicle list.
// If the redirection from book_service.php happens, the tab is set to 'vehicles'
// and the user sees the list, with the registration form easily accessible.

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
    <title>Customer Profile & Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="fonts.css">
    <style>
        /* ============================================
           MODERN CUSTOMER PORTAL - INDEX.PHP STYLE
           ============================================ */
        :root {
            --primary-color: #0f172a;
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
            --accent-color: #f59e0b;
            --accent-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --secondary-color: #10b981;
            --bg-light: #f8fafc;
            --card-bg: white;
            --text-dark: #1e293b;
            --text-light: #64748b;
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
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
            width: 100%;
        }

        /* --- HERO SECTION WITH FLOATING ICONS --- */
        .hero-section {
            background: var(--primary-gradient);
            position: relative;
            padding: 40px 0 60px;
            overflow: hidden;
            color: white;
            margin-top: 0;
            border-radius: 20px;
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

        .btn-main {
            background: var(--primary-gradient);
            border: none;
            color: white;
            font-weight: 600;
            padding: 10px 25px;
            border-radius: 50px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.3);
        }

        .btn-main:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(15, 23, 42, 0.4);
            color: white;
        }

        .app-header { display: none; }

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

        .profile-section {
            background-color: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .card-body .col-md-6 {
            font-size: 0.8rem;
            line-height: 1.4;
            padding: 0.4rem 0.5rem !important;
        }

        .card-body .small {
            font-size: 0.75rem;
        }

        /* --- COMPACT / MINIMIZE --- */
        h3 { font-size: 1.2rem; }
        h4 { font-size: 1rem; }
        .nav-link { font-size: 0.8rem; padding: 0.4rem 0.6rem; }
        .form-label { font-size: 0.75rem; margin-bottom: 0.25rem; }
        .form-control { font-size: 0.8rem; padding: 0.35rem 0.65rem; }
        .btn { font-size: 0.8rem; padding: 0.4rem 0.75rem; }
        .alert { font-size: 0.8rem; padding: 0.5rem; }
        .table { font-size: 0.75rem; }
        .table th,
        .table td { padding: 0.4rem; }

        /* ============================================
           RESPONSIVE
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
            .top-bar {
                padding: 10px 15px;
            }

            .top-bar-title {
                font-size: 1rem;
            }

            .content-area {
                padding: 15px;
            }

            .profile-section {
                padding: 20px;
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

            .profile-section {
                padding: 15px;
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
            <h1 class="top-bar-title">Profile Settings</h1>
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
<!-- Content Section -->
<div class="container py-5">

    <?php if (!empty($msg)): ?>
        <div class="alert alert-<?= strpos($msg, '✅') !== false ? 'success' : 'danger' ?> text-center mb-4 fw-bold"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    
    <ul class="nav nav-tabs" id="profileTabs" role="tablist">
        
        <li class="nav-item" role="presentation">
            <a class="nav-link <?= ($active_tab == 'profile') ? 'active' : '' ?>" 
               href="profile.php?tab=profile">
                <i class="bi bi-person-circle me-1"></i> Personal Details
            </a>
        </li>
        
    </ul>

    <div class="tab-content" id="profileTabsContent">

        <div class="tab-pane fade <?= ($active_tab == 'profile') ? 'active show' : '' ?>" id="profile-pane" role="tabpanel">
            <h3 class="text-primary mb-4 border-bottom pb-2">Update Personal Information</h3>
            
            <form method="post">
                <div class="mb-3">
                    <label for="username" class="form-label">Username (Full Name)</label>
                    <input type="text" class="form-control" id="username" name="username" value="<?= htmlspecialchars($user_data['username'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($user_data['email'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label for="phone" class="form-label">Phone Number</label>
                    <input type="text" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($user_data['phone'] ?? '') ?>">
                </div>
                
                <button type="submit" name="update_profile" class="btn btn-primary btn-lg mt-3 me-2">
                    <i class="bi bi-floppy me-2"></i> Save Profile Changes
                </button>
                <a href="change_password.php" class="btn btn-outline-secondary mt-3">
                    <i class="bi bi-lock"></i> Change Password
                </a>
            </form>

            <h4 class="mt-5 border-top pt-4 text-secondary"><i class="bi bi-person-lines-fill me-2"></i>All User Information</h4>
            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="row g-0">
                        <?php 
                        $infoIcons = [
                            'username' => 'bi-person',
                            'email' => 'bi-envelope',
                            'phone' => 'bi-telephone',
                            'address' => 'bi-geo-alt',
                            'birthdate' => 'bi-calendar',
                            'gender' => 'bi-gender-ambiguous',
                            'status' => 'bi-check-circle',
                            'archived' => 'bi-archive',
                            'role' => 'bi-shield',
                            'created_at' => 'bi-clock',
                            'name' => 'bi-person'
                        ];
                        foreach ($user_data as $key => $value): 
                            if (in_array($key, ['id', 'password'], true)) continue;
                            $icon = $infoIcons[$key] ?? 'bi-person';
                            $displayValue = ($value !== '' && $value !== null) ? $value : '—';
                        ?>
                            <div class="col-md-6 p-3 border-bottom d-flex align-items-center">
                                <i class="bi <?= $icon ?> text-primary me-3 fs-5"></i>
                                <span class="text-muted text-capitalize small fw-bold me-auto"><?= htmlspecialchars(str_replace('_', ' ', $key)) ?></span>
                                <span class="fw-semibold text-break">
                                    <?php if ($key === 'status' && strtolower($displayValue) === 'active'): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($displayValue) ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>



    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // This script manually activates the correct tab on page load based on the URL parameter
    document.addEventListener('DOMContentLoaded', function () {
        const urlParams = new URLSearchParams(window.location.search);
        let activeTabName = urlParams.get('tab') || 'profile'; // Default to 'profile'

        // Map the parameter to the tab content ID
        let targetId = '';
        if (activeTabName === 'vehicles') {
            targetId = '#vehicles-pane';
        } else if (activeTabName === 'register') {
            targetId = '#register-pane';
        } else {
            targetId = '#profile-pane';
        }

        const targetElement = document.querySelector(targetId);
        if (targetElement) {
            // Manually show the tab content
            const tab = new bootstrap.Tab(targetElement);
            tab.show();
        }
    });

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
</script>
</div>
</body>
</html>