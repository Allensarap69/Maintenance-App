<?php
session_start();
require 'db.php'; // Ensure your database connection is correct
require 'security.php'; // Security functions and headers

/**
 * Secure password verification - supports both old MD5 and new bcrypt
 * @param string $password Plain text password
 * @param string $hash Stored hash (MD5 or bcrypt)
 * @return bool True if password matches
 */
function verify_password($password, $hash) {
    // Check if it's a bcrypt hash (starts with $2y$)
    if (strpos($hash, '$2y$') === 0 || strpos($hash, '$2a$') === 0) {
        return password_verify($password, $hash);
    }
    // Check if it's MD5 (32 hex characters)
    elseif (strlen($hash) === 32 && ctype_xdigit($hash)) {
        return hash_equals($hash, md5($password));
    }
    // Plain text comparison (for very old entries - should be migrated)
    else {
        return hash_equals($hash, $password);
    }
}

/**
 * Sanitize user input to prevent XSS
 * @param string $data Input data
 * @return string Sanitized data
 */
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

$msg = "";
$login_attempt = false;
$register_attempt = false;
$registration_success = false;

// Variables for registration form
$reg_username = $_POST['reg_username'] ?? '';
$reg_email    = $_POST['reg_email'] ?? '';
$reg_phone    = $_POST['reg_phone'] ?? '';
$reg_address  = $_POST['reg_address'] ?? '';

// --- 1. Check for logged-in user and redirect ---
if (isset($_SESSION['user_id'])) {
    $user_role = $_SESSION['role'] ?? 'customer';
    $dashboard_files = [
        'admin' => 'dashboard_admin.php',
        'mechanic' => 'dashboard_mechanic.php',
        'customer' => 'dashboard_customer.php'
    ];
    $dashboard_file = $dashboard_files[$user_role] ?? 'dashboard_customer.php';
    header("Location: " . $dashboard_file);
    exit;
}

// --- 2. PHP Login Submission Logic (Handles modal form POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $login_attempt = true; // Flag to show the modal again if login fails

    // Verify CSRF token
    if (!verify_csrf_token()) {
        $msg = "Security validation failed. Please refresh the page and try again.";
    } else {
        $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';

        // Check progressive login delay (server-side, keyed on email + IP)
        $delay_info = check_login_delay($pdo, $email);

        // If account is locked or delayed, do not process credentials
        if ($delay_info['locked'] || $delay_info['delay'] > 0) {
            $msg = $delay_info['message'];
        } elseif (empty($_POST['email']) || empty($_POST['password'])) {
            $msg = "Please enter both email and password.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, role, username, password FROM users WHERE email=?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                // Verify password using password_verify (supports both old MD5 and new bcrypt)
                if (!$user || !verify_password($password, $user['password'])) {
                    $user = null;
                }

                if ($user) {
                    // Transparently upgrade weak MD5/plaintext hashes to bcrypt
                    $stored_hash = $user['password'];
                    $is_bcrypt = strpos($stored_hash, '$2y$') === 0 || strpos($stored_hash, '$2a$') === 0;
                    if (!$is_bcrypt) {
                        try {
                            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                                ->execute([password_hash($password, PASSWORD_BCRYPT), $user['id']]);
                        } catch (PDOException $e) {
                            error_log("Password rehash failed for user {$user['id']}: " . $e->getMessage());
                        }
                    }

                    // Successful login: Clear failed attempts
                    clear_login_rate_limit($pdo, $email);

                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role']    = $user['role'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['last_login'] = time();

                    // Redirect based on role
                    $dashboard_files = [
                        'admin' => 'dashboard_admin.php',
                        'mechanic' => 'dashboard_mechanic.php',
                        'customer' => 'dashboard_customer.php'
                    ];
                    $target = $dashboard_files[$user['role']] ?? 'dashboard_customer.php';
                    header("Location: " . $target);
                    exit;
                } else {
                    // Failed login - record attempt and show delay
                    record_failed_login($pdo, $email);
                    $delay_info = check_login_delay($pdo, $email);
                    
                    if ($delay_info['delay'] > 0) {
                        $msg = "Invalid credentials. " . $delay_info['message'];
                    } else {
                        $msg = "Invalid email or password.";
                    }
                }
            } catch (PDOException $e) {
                error_log("Login error: " . $e->getMessage());
                $msg = "A database error occurred. Please try again.";
            }
        }
    }
}
// --- END PHP Login Submission Logic ---

// --- 3. PHP Registration Logic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_submit'])) {
    $register_attempt = true;

    // Verify CSRF token
    if (!verify_csrf_token()) {
        $msg = "Security validation failed. Please refresh the page and try again.";
    } elseif (empty($_POST['reg_password']) || empty($_POST['reg_username']) || empty($_POST['reg_email']) || empty($_POST['reg_phone']) || empty($_POST['reg_address'])) {
        $msg = "Please fill in all required fields.";
    } else {
        $username = $_POST['reg_username'];
        $email    = $_POST['reg_email'];
        // Use secure password hashing (bcrypt)
        $password = password_hash($_POST['reg_password'], PASSWORD_BCRYPT);
        $phone    = preg_replace('/\D/', '', $_POST['reg_phone']); // digits only
        $address  = sanitize_input($_POST['reg_address']);

        if (!preg_match('/^09\d{9}$/', $phone)) {
            $msg = "Please enter a valid 11-digit mobile number starting with 09 (e.g., 0917 123 4567).";
        } else {
        try {
            $check = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $check->execute([$email]);

            if ($check->rowCount() > 0) {
                $msg = "Email already registered. Please use a different email or login.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO users (username,email,password,phone,address,role) VALUES (?,?,?,?,?,'customer')");
                $stmt->execute([$username, $email, $password, $phone, $address]);
                
                $msg = "Registration successful! You can now login.";
                $registration_success = true;
                
                // Clear values on success
                $reg_username = $reg_email = $reg_phone = $reg_address = '';
            }
        } catch (PDOException $e) {
            $msg = "A database error occurred during registration. Please try again.";
        }
        }
    }
}
// --- END PHP Registration Logic ---

$login_button_text = 'Login'; 
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Mindanao Eversure — Motorcycle Service Scheduling & Monitoring System. Book services, track maintenance, monitor motorcycle health, and get smart reminders.">
    <meta name="theme-color" content="#ffffff">
    <script>
        (function () {
            var t = localStorage.getItem('theme');
            if (t !== 'dark' && t !== 'light') {
                t = localStorage.getItem('mev-theme');
            }
            if (t !== 'dark' && t !== 'light') {
                t = (localStorage.getItem('adminDarkMode') === '1' || localStorage.getItem('customerDarkMode') === '1') ? 'dark'
                    : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            }
            localStorage.setItem('theme', t);
            document.documentElement.setAttribute('data-theme', t);
            var meta = document.querySelector('meta[name="theme-color"]');
            if (meta) meta.setAttribute('content', t === 'dark' ? '#0b1220' : '#ffffff');
        })();
    </script>
    <title>Mindanao Eversure | Motor Maintenance System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="fonts.css">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/ui.css">
    <noscript><style>[data-reveal]{opacity:1!important;transform:none!important}.bar-fill{width:var(--w)!important}</style></noscript>
    <link rel="stylesheet" href="assets/css/landing.css">
</head>
<?php 
// Show progressive delay overlay if there are failed attempts
$delay_info = check_login_delay($pdo, $_POST['email'] ?? '');
if ($delay_info['delay'] > 0 && $login_attempt) {
    echo render_login_delay($delay_info);
}
?>
<body class="mev-landing">

<nav class="navbar navbar-expand-lg fixed-top navbar-custom">
    <div class="container">
        <a class="navbar-brand" href="index.php" aria-label="Mindanao Eversure home">
            <span class="brand-mark"><i data-lucide="motorbike"></i></span>
            <span>
                Mindanao Eversure
                <small class="brand-tagline">MOTORCYCLE SERVICE</small>
            </span>
        </a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-lg-center">
                <li class="nav-item"><a class="nav-link active" href="#home">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="#features">Features</a></li>
                <li class="nav-item"><a class="nav-link" href="#service-list">Services</a></li>
                <li class="nav-item"><a class="nav-link" href="#how-it-works">How It Works</a></li>
                <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
                <li class="nav-item ms-lg-2 mt-2 mt-lg-0 d-flex align-items-center justify-content-center">
                    <button type="button" class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode" aria-pressed="false" title="Toggle dark mode">
                        <i data-lucide="moon" class="theme-icon theme-icon-moon"></i>
                        <i data-lucide="sun" class="theme-icon theme-icon-sun"></i>
                    </button>
                </li>
                <li class="nav-item ms-lg-3 mt-2 mt-lg-0 d-flex flex-column flex-lg-row gap-2">
                    <a href="#" class="btn btn-mev-login" data-bs-toggle="modal" data-bs-target="#loginModal"><i data-lucide="user-round" class="me-1" style="width:14px;height:14px;"></i>Login</a>
                    <a href="#" class="btn btn-mev-register" data-bs-toggle="modal" data-bs-target="#registerModal">Get Started</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Hero Section -->
<section class="hero-section" id="home">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <div class="hero-copy" data-reveal>
                    <span class="hero-eyebrow">Smarter maintenance. Longer journeys.</span>
                    <h1 class="hero-title">
                        Motor Maintenance
                        <span>System</span>
                    </h1>
                    <p class="hero-subtitle">
                        Easily manage your motorcycle maintenance, track service schedules, and keep your ride in top shape — all in one place.
                    </p>
                    <div class="hero-feature-grid">
                        <div class="hero-feature">
                            <span class="hero-feature-icon"><i data-lucide="calendar-days"></i></span>
                            <span><strong>Service</strong><small>Scheduling</small></span>
                        </div>
                        <div class="hero-feature">
                            <span class="hero-feature-icon"><i data-lucide="wrench"></i></span>
                            <span><strong>Track</strong><small>Maintenance</small></span>
                        </div>
                        <div class="hero-feature">
                            <span class="hero-feature-icon"><i data-lucide="clipboard-list"></i></span>
                            <span><strong>Repair</strong><small>Records</small></span>
                        </div>
                        <div class="hero-feature">
                            <span class="hero-feature-icon"><i data-lucide="bell"></i></span>
                            <span><strong>Get</strong><small>Reminders</small></span>
                        </div>
                    </div>
                    <div class="hero-cta">
                        <button class="btn btn-cta-primary" data-bs-toggle="modal" data-bs-target="#loginModal">
                            Get Started<i data-lucide="arrow-right"></i>
                        </button>
                        <button class="btn btn-cta-outline" data-bs-toggle="modal" data-bs-target="#registerModal">
                            Create Account<i data-lucide="chevrons-right"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="hero-visual">
                    <div class="dashboard-preview" data-reveal data-delay="160">
                        <aside class="dashboard-sidebar" aria-label="Dashboard preview navigation">
                            <div class="dash-brand">
                                <span class="dash-brand-mark"><i data-lucide="wrench"></i></span>
                                <span>Eversure</span>
                            </div>
                            <nav class="dash-nav">
                                <span class="active"><i data-lucide="house"></i>Dashboard</span>
                                <span><i data-lucide="gauge"></i>Maintenance</span>
                                <span><i data-lucide="wrench"></i>Repairs</span>
                                <span><i data-lucide="history"></i>Service History</span>
                                <span><i data-lucide="bell"></i>Reminders</span>
                                <span><i data-lucide="settings"></i>Settings</span>
                            </nav>
                        </aside>
                        <div class="dashboard-main">
                            <div class="dash-top">
                                <div class="dash-greeting">
                                    Good Morning, Rider!
                                    <p>Keep your bike in top shape and ready for the road.</p>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="dash-user"><i data-lucide="circle-user-round"></i>John Doe</span>
                                    <span class="dash-add"><i data-lucide="plus"></i>Add Service</span>
                                </div>
                            </div>
                            <div class="stats-grid">
                                <div class="stat-card">
                                    <span class="stat-icon"><i data-lucide="motorbike"></i></span>
                                    <span><span class="stat-label">Total Bikes</span><span class="stat-value">3</span></span>
                                </div>
                                <div class="stat-card">
                                    <span class="stat-icon"><i data-lucide="calendar-days"></i></span>
                                    <span><span class="stat-label">Scheduled</span><span class="stat-value">2</span></span>
                                </div>
                                <div class="stat-card">
                                    <span class="stat-icon"><i data-lucide="wrench"></i></span>
                                    <span><span class="stat-label">In Service</span><span class="stat-value">1</span></span>
                                </div>
                                <div class="stat-card">
                                    <span class="stat-icon"><i data-lucide="circle-check"></i></span>
                                    <span><span class="stat-label">Completed</span><span class="stat-value">12</span></span>
                                </div>
                            </div>
                            <div class="dash-content-grid">
                                <div class="dash-panel">
                                    <div class="dash-panel-head">Upcoming Maintenance <a href="#service-list">View All</a></div>
                                    <div class="upcoming-list">
                                        <div class="upcoming-item">
                                            <span class="upcoming-icon"><img src="click125.png" alt="Honda Click 125"></span>
                                            <span><span class="upcoming-name">Honda Click 125</span><span class="upcoming-meta">Oil Change · 1,200 km</span></span>
                                            <span class="status-pill scheduled">Scheduled</span>
                                        </div>
                                        <div class="upcoming-item">
                                            <span class="upcoming-icon"><img src="snip.png" alt="Yamaha Sniper 150"></span>
                                            <span><span class="upcoming-name">Yamaha Sniper 150</span><span class="upcoming-meta">Tire Check · 4,000 km</span></span>
                                            <span class="status-pill scheduled">Scheduled</span>
                                        </div>
                                        <div class="upcoming-item">
                                            <span class="upcoming-icon"><img src="rai.png" alt="Suzuki Raider 150"></span>
                                            <span><span class="upcoming-name">Suzuki Raider 150</span><span class="upcoming-meta">Brake Inspection · 6,000 km</span></span>
                                            <span class="status-pill upcoming">Upcoming</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="dash-panel">
                                    <div class="dash-panel-head">Quick Actions</div>
                                    <div class="quick-actions">
                                        <div class="quick-action"><i data-lucide="calendar-plus"></i><span><strong>Add Maintenance</strong>Schedule a service</span></div>
                                        <div class="quick-action"><i data-lucide="history"></i><span><strong>View History</strong>Check past records</span></div>
                                        <div class="quick-action"><i data-lucide="wrench"></i><span><strong>Manage Repairs</strong>Track repair status</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Features Section -->
<section class="features-section" id="features">
    <div class="container">
        <div class="feature-showcase">
            <div class="feature-intro" data-reveal>
                <span class="section-eyebrow">Why Choose Mindanao Eversure?</span>
                <h2 class="section-title">Simple. <span>Reliable.</span> Efficient.</h2>
                <p class="section-subtitle">Everything you need to maintain your motorcycle, reduce downtime, and ride with confidence — anytime, anywhere.</p>
                <a href="#how-it-works" class="btn btn-cta-primary">Learn More<i data-lucide="arrow-right"></i></a>
            </div>
            <div class="feature-cards">
                <div class="feature-card" data-reveal>
                    <span class="feature-card-icon"><i data-lucide="wrench"></i></span>
                    <h4>Service Scheduling</h4>
                    <p>Never miss a service with automated reminders.</p>
                </div>
                <div class="feature-card" data-reveal data-delay="60">
                    <span class="feature-card-icon"><i data-lucide="clipboard-list"></i></span>
                    <h4>Repair Tracking</h4>
                    <p>Log and monitor repairs for each vehicle.</p>
                </div>
                <div class="feature-card" data-reveal data-delay="120">
                    <span class="feature-card-icon"><i data-lucide="history"></i></span>
                    <h4>Service History</h4>
                    <p>View complete maintenance records anytime.</p>
                </div>
                <div class="feature-card" data-reveal data-delay="180">
                    <span class="feature-card-icon"><i data-lucide="bell"></i></span>
                    <h4>Smart Reminders</h4>
                    <p>Get notified before your next service is due.</p>
                </div>
                <div class="feature-card" data-reveal data-delay="240">
                    <span class="feature-card-icon"><i data-lucide="shield-check"></i></span>
                    <h4>Keep Your Ride Reliable</h4>
                    <p>Less downtime, more rides.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Our Services Section -->
<section class="services-section" id="service-list">
    <div class="container">
        <div class="services-head" data-reveal>
            <span class="section-eyebrow">Our Services</span>
            <h2 class="section-title">Quality Care for Your Motorcycle</h2>
            <p class="section-subtitle">Choose from our range of professional motorcycle services and maintenance packages.</p>
        </div>

        <div class="row g-4 row-cols-1 row-cols-md-2 row-cols-lg-4">
            <div class="col">
                <div class="service-card" data-reveal>
                    <div class="service-img s-grad-1"><div class="s-icon"><i data-lucide="droplet"></i></div></div>
                    <div class="service-body">
                        <h4 class="service-name">Oil Change</h4>
                        <p class="service-desc">Keep your engine running smoothly.</p>
                        <div class="service-meta">
                            <span class="service-price">₱450</span>
                            <span class="service-time"><i data-lucide="clock"></i>30 mins</span>
                        </div>
                        <button class="service-book" data-bs-toggle="modal" data-bs-target="#loginModal">Book Now<i data-lucide="arrow-right"></i></button>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="service-card" data-reveal data-delay="60">
                    <div class="service-img s-grad-2"><div class="s-icon"><i data-lucide="disc"></i></div></div>
                    <div class="service-body">
                        <h4 class="service-name">Brake Service</h4>
                        <p class="service-desc">Ensure your safety on every ride.</p>
                        <div class="service-meta">
                            <span class="service-price">₱800</span>
                            <span class="service-time"><i data-lucide="clock"></i>45 mins</span>
                        </div>
                        <button class="service-book" data-bs-toggle="modal" data-bs-target="#loginModal">Book Now<i data-lucide="arrow-right"></i></button>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="service-card" data-reveal data-delay="120">
                    <div class="service-img s-grad-3"><div class="s-icon"><i data-lucide="life-buoy"></i></div></div>
                    <div class="service-body">
                        <h4 class="service-name">Tire Service</h4>
                        <p class="service-desc">Better grip, safer every journey.</p>
                        <div class="service-meta">
                            <span class="service-price">₱700</span>
                            <span class="service-time"><i data-lucide="clock"></i>30 mins</span>
                        </div>
                        <button class="service-book" data-bs-toggle="modal" data-bs-target="#loginModal">Book Now<i data-lucide="arrow-right"></i></button>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="service-card" data-reveal data-delay="180">
                    <div class="service-img s-grad-4"><div class="s-icon"><i data-lucide="wrench"></i></div></div>
                    <div class="service-body">
                        <h4 class="service-name">Full Maintenance</h4>
                        <p class="service-desc">Complete check and premium care.</p>
                        <div class="service-meta">
                            <span class="service-price">₱1,200</span>
                            <span class="service-time"><i data-lucide="clock"></i>90 mins</span>
                        </div>
                        <button class="service-book" data-bs-toggle="modal" data-bs-target="#loginModal">Book Now<i data-lucide="arrow-right"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- How It Works Section -->
<section class="how-section" id="how-it-works">
    <div class="container">
        <div class="row align-items-center g-4">
            <div class="col-lg-3 how-left" data-reveal>
                <h2 class="section-title">How It Works</h2>
                <p class="section-subtitle">Getting your motorcycle service is easy in just 4 simple steps.</p>
                <button class="btn btn-cta-primary" data-bs-toggle="modal" data-bs-target="#loginModal">
                    Book a Service<i data-lucide="arrow-right"></i>
                </button>
            </div>
            <div class="col-lg-9">
                <div class="row steps-row">
                    <div class="col-lg-3 col-md-6 step-item" data-reveal>
                        <div class="step-icon blue"><i data-lucide="calendar-check"></i></div>
                        <h4 class="step-title"><span class="s-num">01.</span>Book</h4>
                        <p class="step-desc">Choose your service, date and time.</p>
                    </div>
                    <div class="col-lg-3 col-md-6 step-item" data-reveal data-delay="80">
                        <div class="step-icon blue"><i data-lucide="check-circle"></i></div>
                        <h4 class="step-title"><span class="s-num">02.</span>Confirm</h4>
                        <p class="step-desc">Wait for the dealership to confirm your appointment.</p>
                    </div>
                    <div class="col-lg-3 col-md-6 step-item" data-reveal data-delay="160">
                        <div class="step-icon yellow"><i data-lucide="wrench"></i></div>
                        <h4 class="step-title"><span class="s-num">03.</span>Service</h4>
                        <p class="step-desc">Bring your motorcycle to the dealership.</p>
                    </div>
                    <div class="col-lg-3 col-md-6 step-item" data-reveal data-delay="240">
                        <div class="step-icon yellow"><i data-lucide="flag"></i></div>
                        <h4 class="step-title"><span class="s-num">04.</span>Complete</h4>
                        <p class="step-desc">View your service record and updated status.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Motorcycle Health Section -->
<section class="health-section" id="health">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <div class="health-bike-wrap" data-reveal>
                    <img src="moto_hero.jpg" alt="Motorcycle health monitoring" class="health-bike">
                </div>
            </div>
            <div class="col-lg-7">
                <div data-reveal>
                    <span class="section-eyebrow">Motorcycle Health</span>
                    <h2 class="section-title">Know Your Motorcycle's Health</h2>
                    <p class="section-subtitle">
                        Monitor your motorcycle's condition using maintenance history, inspection results, mileage, and overdue services.
                    </p>
                </div>
                <div class="health-score-row" data-reveal data-delay="120">
                    <div class="health-donut">
                        <div class="health-donut-inner">
                            <span class="health-donut-num" data-count="92">92%</span>
                            <span class="health-donut-label">Health Score</span>
                        </div>
                    </div>
                    <div class="health-bars">
                        <div class="h-bar">
                            <div class="h-bar-icon"><i data-lucide="cog"></i></div>
                            <div class="h-bar-content">
                                <div class="h-bar-top">
                                    <span class="h-bar-label">Engine</span>
                                    <span class="h-bar-value">95%</span>
                                </div>
                                <div class="h-bar-track"><div class="bar-fill" style="--w: 95%;"></div></div>
                            </div>
                        </div>
                        <div class="h-bar">
                            <div class="h-bar-icon"><i data-lucide="disc"></i></div>
                            <div class="h-bar-content">
                                <div class="h-bar-top">
                                    <span class="h-bar-label">Brakes</span>
                                    <span class="h-bar-value">90%</span>
                                </div>
                                <div class="h-bar-track"><div class="bar-fill" style="--w: 90%;"></div></div>
                            </div>
                        </div>
                        <div class="h-bar">
                            <div class="h-bar-icon"><i data-lucide="life-buoy"></i></div>
                            <div class="h-bar-content">
                                <div class="h-bar-top">
                                    <span class="h-bar-label">Tires</span>
                                    <span class="h-bar-value">88%</span>
                                </div>
                                <div class="h-bar-track"><div class="bar-fill warn" style="--w: 88%;"></div></div>
                            </div>
                        </div>
                        <div class="h-bar">
                            <div class="h-bar-icon"><i data-lucide="battery-charging"></i></div>
                            <div class="h-bar-content">
                                <div class="h-bar-top">
                                    <span class="h-bar-label">Battery</span>
                                    <span class="h-bar-value">94%</span>
                                </div>
                                <div class="h-bar-track"><div class="bar-fill" style="--w: 94%;"></div></div>
                            </div>
                        </div>
                    </div>
                </div>
                <button class="btn btn-cta-primary" data-bs-toggle="modal" data-bs-target="#loginModal" data-reveal data-delay="180">
                    View Health Details<i data-lucide="arrow-right"></i>
                </button>
            </div>
        </div>
    </div>
</section>

<!-- Track Your Service Section -->
<section class="track-section" id="track">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-5" data-reveal>
                <span class="section-eyebrow">Track Your Service</span>
                <h2 class="section-title">Follow Your Motorcycle's Journey</h2>
                <p class="section-subtitle">
                    Stay updated on the progress of your service from start to finish.
                </p>
                <button class="btn btn-cta-primary mt-3" data-bs-toggle="modal" data-bs-target="#loginModal">
                    Check Service Status<i data-lucide="arrow-right"></i>
                </button>
            </div>
            <div class="col-lg-7">
                <div class="track-card" data-reveal data-delay="120">
                    <div class="track-card-head">
                        <div class="t-icon"><i data-lucide="motorbike"></i></div>
                        <div>
                            <div class="t-name">Service Booking</div>
                            <div class="t-sub">Preventive Maintenance</div>
                        </div>
                        <span class="track-pill">In Progress</span>
                    </div>
                    <div class="track-steps">
                        <div class="track-step done">
                            <div class="track-node"><i data-lucide="check"></i></div>
                            <div class="track-label">Booking<br>Confirmed</div>
                        </div>
                        <div class="track-step done">
                            <div class="track-node"><i data-lucide="check"></i></div>
                            <div class="track-label">Motorcycle<br>Received</div>
                        </div>
                        <div class="track-step current">
                            <div class="track-node"><i data-lucide="wrench"></i></div>
                            <div class="track-label">Under<br>Maintenance</div>
                        </div>
                        <div class="track-step todo">
                            <div class="track-node">4</div>
                            <div class="track-label">Inspection</div>
                        </div>
                        <div class="track-step todo">
                            <div class="track-node">5</div>
                            <div class="track-label">Service<br>Complete</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Reminders + CTA Section -->
<section class="reminder-section">
    <div class="container">
        <div class="row g-4 align-items-stretch">
            <div class="col-lg-6">
                <div class="reminder-wrap" data-reveal>
                    <div class="reminder-icon"><i data-lucide="bell"></i></div>
                    <div>
                        <div class="reminder-title">Stay Ahead of Maintenance &amp; Warranty</div>
                        <p class="reminder-sub">Get notified before your service or warranty expires.</p>
                    </div>
                </div>
                <div class="reminder-cards mt-3" data-reveal data-delay="100">
                    <div class="reminder-card">
                        <div class="rc-icon blue"><i data-lucide="calendar-check"></i></div>
                        <div>
                            <div class="rc-label">Next Maintenance</div>
                            <div class="rc-value">Mar 28, 2027</div>
                            <div class="rc-sub">Scheduled Visit</div>
                        </div>
                    </div>
                    <div class="reminder-card">
                        <div class="rc-icon yellow"><i data-lucide="shield-check"></i></div>
                        <div>
                            <div class="rc-label">Warranty</div>
                            <div class="rc-value">Valid until Dec 2027</div>
                            <div class="rc-sub">Active Coverage</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="cta-card" data-reveal data-delay="140">
                    <img src="MOTOR.jpg" alt="" class="cta-img" aria-hidden="true">
                    <div class="cta-content">
                        <h3 class="cta-title">Ready to Take Better Care of Your Motorcycle?</h3>
                        <p class="cta-subtitle">Schedule your next service and keep your motorcycle running smoothly.</p>
                        <button class="btn btn-cta-primary" data-bs-toggle="modal" data-bs-target="#loginModal">
                            Book a Service<i data-lucide="arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="benefits-strip" aria-label="Service benefits">
    <div class="container">
        <div class="benefits-grid">
            <div class="benefit-item" data-reveal>
                <span class="benefit-icon"><i data-lucide="shield-check"></i></span>
                <span><strong>Safe Rides</strong><small>Well-maintained bikes mean safer journeys.</small></span>
            </div>
            <div class="benefit-item" data-reveal data-delay="70">
                <span class="benefit-icon"><i data-lucide="settings"></i></span>
                <span><strong>Save Time</strong><small>Organize your maintenance in one place.</small></span>
            </div>
            <div class="benefit-item" data-reveal data-delay="140">
                <span class="benefit-icon"><i data-lucide="zap"></i></span>
                <span><strong>Lower Costs</strong><small>Prevent major repairs with regular service.</small></span>
            </div>
            <div class="benefit-item" data-reveal data-delay="210">
                <span class="benefit-icon"><i data-lucide="motorbike"></i></span>
                <span><strong>Your Bike. Our Priority.</strong><small>Keep it running. Keep exploring.</small></span>
            </div>
        </div>
    </div>
</section>

<footer class="app-footer" id="contact">
    <div class="container">
        <div class="footer-top">
            <a class="navbar-brand" href="index.php" aria-label="Mindanao Eversure home">
                <span class="brand-mark"><i data-lucide="motorbike"></i></span>
                <span>
                    Mindanao Eversure
                    <small class="brand-tagline">MOTORCYCLE SERVICE</small>
                </span>
            </a>
            <nav class="footer-nav" aria-label="Footer navigation">
                <a href="#home">Home</a>
                <a href="#features">Features</a>
                <a href="#service-list">Services</a>
                <a href="#how-it-works">How It Works</a>
                <a href="#contact">Contact</a>
            </nav>
            <div class="social-links">
                <a href="#" aria-label="Facebook"><i data-lucide="facebook"></i></a>
                <a href="#" aria-label="Instagram"><i data-lucide="instagram"></i></a>
                <a href="#" aria-label="YouTube"><i data-lucide="youtube"></i></a>
            </div>
        </div>
        <div class="footer-bottom">
            <p class="footer-copy">&copy; 2026 Mindanao Eversure Motorcycle Service. All rights reserved.</p>
            <p class="footer-tagline">Ride Safe. Service Always.</p>
        </div>
    </div>
</footer>

<!-- Login Modal -->
<div class="modal fade modal-blur-effect" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <button type="button" class="btn-close modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
      <div class="modal-body">
        <div class="auth-shell">
          <aside class="auth-visual" aria-label="Mindanao Eversure account benefits">
            <div class="auth-visual-brand">
              <span class="auth-visual-logo"><i data-lucide="motorbike"></i></span>
              <span class="auth-visual-name">Mindanao Eversure<small>Motorcycle Service</small></span>
            </div>
            <div class="auth-visual-copy">
              <span class="auth-kicker">Secure Access</span>
              <h3>Your maintenance, always in view.</h3>
              <p>Sign in to manage bookings, track repairs, and review every service record in one place.</p>
            </div>
            <div class="auth-visual-card">
              <span class="auth-card-label">Next Maintenance</span>
              <strong class="auth-card-value">Mar 28, 2027</strong>
              <small class="auth-card-sub">Oil Change · Honda Click 125</small>
            </div>
          </aside>

          <div class="auth-panel">
            <div class="auth-heading">
                <h4 id="loginModalLabel">Welcome <span class="text-brand">back</span></h4>
                <p>Enter your credentials to open your rider dashboard.</p>
            </div>

            <?php if ($msg && $login_attempt): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-3 py-2 px-2" role="alert">
                    <?= htmlspecialchars($msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="padding: 2px 6px; font-size: 0.7rem;"></button>
                </div>
            <?php endif; ?>

            <form method="post" action="index.php">
                <input type="hidden" name="login_submit" value="1">
                <?= csrf_field() ?>

                <div class="auth-field">
                    <label for="loginEmail">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i data-lucide="mail" class="input-icon"></i></span>
                        <input type="email" class="form-control" id="loginEmail" name="email" required
                                   autocomplete="email" inputmode="email" placeholder="you@example.com"
                                   value="<?= $login_attempt ? htmlspecialchars($_POST['email'] ?? '') : '' ?>">
                    </div>
                </div>

                <div class="auth-field">
                    <label for="loginPassword">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i data-lucide="lock" class="input-icon"></i></span>
                        <input type="password" class="form-control" id="loginPassword" name="password" required
                                   autocomplete="current-password" placeholder="Enter your password">
                        <span class="input-group-text password-toggle" role="button" tabindex="0"
                              onclick="togglePassword('loginPassword')" aria-label="Show password">
                            <i data-lucide="eye" class="input-icon"></i>
                        </span>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center remember-row">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="rememberMe" name="remember">
                        <label class="form-check-label" for="rememberMe">Remember me</label>
                    </div>
                    <a href="#" class="forgot-link">Forgot password?</a>
                </div>

                <button type="submit" class="btn btn-primary w-100 auth-submit">
                    Sign In <i data-lucide="arrow-right"></i>
                </button>
            </form>

            <p class="auth-switch">
                Don't have an account? <a href="#" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#registerModal">Create one now</a>
            </p>
            <div class="auth-secure"><i data-lucide="shield-check"></i>Protected by secure authentication</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Registration Modal -->
<div class="modal fade modal-blur-effect" id="registerModal" tabindex="-1" aria-labelledby="registerModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <button type="button" class="btn-close modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
      <div class="modal-body">
        <div class="auth-shell">
          <aside class="auth-visual" aria-label="Mindanao Eversure registration benefits">
            <div class="auth-visual-brand">
              <span class="auth-visual-logo"><i data-lucide="motorbike"></i></span>
              <span class="auth-visual-name">Mindanao Eversure<small>Motorcycle Service</small></span>
            </div>
            <div class="auth-visual-copy">
              <span class="auth-kicker">Rider Profile</span>
              <h3>Build a complete service record.</h3>
              <p>Create an account to schedule maintenance, monitor repairs, and receive service reminders.</p>
            </div>
            <div class="auth-visual-card">
              <span class="auth-card-label">What You Get</span>
              <strong class="auth-card-value">Bookings · History · Reminders</strong>
              <small class="auth-card-sub">One profile for every motorcycle</small>
            </div>
          </aside>

          <div class="auth-panel">
            <div class="auth-heading">
                <h4 id="registerModalLabel">Create your <span class="text-brand">account</span></h4>
                <p>Tell us a few details to get started.</p>
            </div>

            <?php if ($msg && $register_attempt): ?>
                <div class="alert alert-<?= $registration_success ? 'success' : 'danger' ?> alert-dismissible fade show mb-3 py-2 px-2" role="alert">
                    <?= htmlspecialchars($msg) ?>
                    <?php if ($registration_success): ?>
                        <a href="#" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#loginModal">Login →</a>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="padding: 2px 6px; font-size: 0.7rem;"></button>
                </div>
            <?php endif; ?>

            <form method="post" action="index.php">
                <input type="hidden" name="register_submit" value="1">
                <?= csrf_field() ?>

                <div class="auth-form-grid">
                    <div class="auth-field">
                        <label for="regUsername">Full Name</label>
                        <div class="input-group">
                            <span class="input-group-text"><i data-lucide="user" class="input-icon"></i></span>
                            <input type="text" class="form-control" id="regUsername" name="reg_username" required
                                       autocomplete="name" placeholder="Juan Dela Cruz" value="<?= htmlspecialchars($reg_username) ?>">
                        </div>
                    </div>

                    <div class="auth-field">
                        <label for="regEmail">Email Address</label>
                        <div class="input-group">
                            <span class="input-group-text"><i data-lucide="mail" class="input-icon"></i></span>
                            <input type="email" class="form-control" id="regEmail" name="reg_email" required
                                       autocomplete="email" inputmode="email" placeholder="you@example.com" value="<?= htmlspecialchars($reg_email) ?>">
                        </div>
                    </div>

                    <div class="auth-field">
                        <label for="regPassword">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i data-lucide="lock" class="input-icon"></i></span>
                            <input type="password" class="form-control" id="regPassword" name="reg_password" required
                                       autocomplete="new-password" placeholder="Create a password">
                            <span class="input-group-text password-toggle" role="button" tabindex="0"
                                  onclick="togglePassword('regPassword')" aria-label="Show password">
                                <i data-lucide="eye" class="input-icon"></i>
                            </span>
                        </div>
                        <div class="pw-meter" id="pwMeter"><span></span></div>
                        <div class="pw-hint" id="pwHint">Password strength: <strong id="pwLabel"></strong></div>
                    </div>

                    <div class="auth-field">
                        <label for="regPhone">Phone Number</label>
                        <div class="input-group">
                            <span class="input-group-text"><i data-lucide="phone" class="input-icon"></i></span>
                            <input type="tel" class="form-control" id="regPhone" name="reg_phone" required
                                       autocomplete="tel" inputmode="numeric" maxlength="13" pattern="09[0-9]{2} [0-9]{3} [0-9]{4}" title="11-digit mobile number starting with 09 (e.g., 0917 123 4567)" placeholder="09XX XXX XXXX" oninput="formatPhoneNumber(this)" value="<?= htmlspecialchars($reg_phone) ?>">
                        </div>
                    </div>

                    <div class="auth-field full">
                        <label for="regAddress">Address</label>
                        <div class="input-group">
                            <span class="input-group-text"><i data-lucide="map-pin" class="input-icon"></i></span>
                            <input type="text" class="form-control" id="regAddress" name="reg_address" required
                                       autocomplete="street-address" placeholder="Street, Barangay, City" value="<?= htmlspecialchars($reg_address) ?>">
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 auth-submit">
                    Create Account <i data-lucide="arrow-right"></i>
                </button>
            </form>

            <p class="auth-switch">
                Already have an account? <a href="#" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#loginModal">Login here</a>
            </p>
            <div class="auth-secure"><i data-lucide="shield-check"></i>Protected by secure authentication</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Scroll to Top Button -->
<button class="scroll-top" id="scrollTop" onclick="scrollToTop()" aria-label="Scroll to top">
    <i data-lucide="arrow-up"></i>
</button>

<script>
    // Formats input as 09** *** **** (11 digits, digits only)
    function formatPhoneNumber(input) {
        var digits = input.value.replace(/\D/g, '').slice(0, 11);
        var parts = [];
        if (digits.length > 0) parts.push(digits.slice(0, 4));
        if (digits.length > 4) parts.push(digits.slice(4, 7));
        if (digits.length > 7) parts.push(digits.slice(7, 11));
        input.value = parts.join(' ');
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Auto-show modal on failed login/register attempt
        <?php if ($login_attempt && $msg): ?>
            new bootstrap.Modal(document.getElementById('loginModal')).show();
        <?php endif; ?>
        <?php if ($register_attempt && $msg): ?>
            new bootstrap.Modal(document.getElementById('registerModal')).show();
        <?php endif; ?>

        // Dark mode toggle
        const themeToggle = document.getElementById('themeToggle');
        const themeMeta = document.querySelector('meta[name="theme-color"]');
        if (themeToggle) {
            const applyTheme = (theme) => {
                document.documentElement.setAttribute('data-theme', theme);
                localStorage.setItem('theme', theme);
                themeToggle.setAttribute('aria-pressed', theme === 'dark');
                if (themeMeta) themeMeta.setAttribute('content', theme === 'dark' ? '#0b1220' : '#ffffff');
            };
            themeToggle.setAttribute('aria-pressed', document.documentElement.getAttribute('data-theme') === 'dark');
            themeToggle.addEventListener('click', () => {
                const theme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                if (!reduceMotion && document.startViewTransition) {
                    document.startViewTransition(() => applyTheme(theme));
                } else {
                    applyTheme(theme);
                }
            });
        }

        // Navbar scrolled state + scroll-to-top visibility
        const navbar = document.querySelector('.navbar-custom');
        const scrollTopBtn = document.getElementById('scrollTop');
        function onScroll() {
            navbar.classList.toggle('scrolled', window.scrollY > 30);
            scrollTopBtn.classList.toggle('visible', window.scrollY > 500);
            setActiveNav();
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();

        // Highlight active nav link on scroll
        function setActiveNav() {
            const links = document.querySelectorAll('.navbar-custom .nav-link');
            const ids = ['features', 'service-list', 'how-it-works', 'contact'];
            let activeId = 'home';
            ids.forEach(id => {
                const section = document.getElementById(id);
                if (section && section.getBoundingClientRect().top <= 120) activeId = id;
            });
            links.forEach(link => {
                link.classList.toggle('active', link.getAttribute('href') === '#' + activeId);
            });
        }

        // Scroll reveal animations
        function animateCount(el) {
            const target = parseInt(el.dataset.count, 10);
            if (isNaN(target)) return;
            const duration = 1400;
            const start = performance.now();
            function tick(now) {
                const p = Math.min((now - start) / duration, 1);
                const eased = 1 - Math.pow(1 - p, 3);
                el.textContent = Math.round(target * eased) + '%';
                if (p < 1) requestAnimationFrame(tick);
            }
            requestAnimationFrame(tick);
        }

        function revealEl(el) {
            const delay = parseInt(el.dataset.delay || '0', 10);
            if (delay) el.style.transitionDelay = delay + 'ms';
            el.classList.add('revealed');
            el.querySelectorAll('[data-count]').forEach(animateCount);
        }

        const revealEls = document.querySelectorAll('[data-reveal]');
        if ('IntersectionObserver' in window) {
            const io = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        revealEl(entry.target);
                        io.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.15 });
            revealEls.forEach(el => io.observe(el));
        } else {
            revealEls.forEach(revealEl);
        }

        // Password strength meter (register modal)
        const regPw = document.getElementById('regPassword');
        if (regPw) {
            const meter = document.getElementById('pwMeter');
            const bar = meter.querySelector('span');
            const hint = document.getElementById('pwHint');
            const label = document.getElementById('pwLabel');
            const levels = [
                { pct: '18%',  color: '#ef4444', text: 'Too short' },
                { pct: '38%',  color: '#f97316', text: 'Weak' },
                { pct: '62%',  color: '#FACC15', text: 'Fair' },
                { pct: '82%',  color: '#84cc16', text: 'Good' },
                { pct: '100%', color: '#22c55e', text: 'Strong' }
            ];
            regPw.addEventListener('input', () => {
                const v = regPw.value;
                if (!v.length) {
                    meter.style.display = 'none';
                    hint.style.display = 'none';
                    return;
                }
                let s = 0;
                if (v.length >= 8) s++;
                if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
                if (/\d/.test(v)) s++;
                if (/[^A-Za-z0-9]/.test(v)) s++;
                s = v.length < 6 ? 0 : Math.max(s, 1);
                const lv = levels[s];
                meter.style.display = 'block';
                hint.style.display = 'block';
                bar.style.width = lv.pct;
                bar.style.background = lv.color;
                label.textContent = lv.text;
                label.style.color = lv.color;
            });
        }

        // Initialize Lucide icons
        lucide.createIcons();
    });

    // Scroll to top function
    function scrollToTop() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Smooth scroll offset for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const targetId = this.getAttribute('href').split('#')[1];
            if (!targetId) {
                e.preventDefault();
                return;
            }
            const target = document.getElementById(targetId);
            if (target) {
                e.preventDefault();
                const offset = 80;
                const top = target.getBoundingClientRect().top + window.pageYOffset - offset;
                window.scrollTo({ top: top, behavior: 'smooth' });
            }
        });
    });

    function togglePassword(id) {
        const input = document.getElementById(id);
        if (input) input.type = input.type === 'password' ? 'text' : 'password';
    }
</script>
</body>
</html>
