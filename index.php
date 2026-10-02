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
        // Check progressive login delay
        $delay_info = check_login_delay();
        
        // If account is locked, show error
        if ($delay_info['locked']) {
            $msg = $delay_info['message'];
        } elseif (empty($_POST['email']) || empty($_POST['password'])) {
            $msg = "Please enter both email and password.";
        } else {
            $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
            $password = $_POST['password'];

            try {
                $stmt = $pdo->prepare("SELECT id, role, username, password FROM users WHERE email=?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                // Verify password using password_verify (supports both old MD5 and new bcrypt)
                if (!$user || !verify_password($password, $user['password'])) {
                    $user = null;
                }

                if ($user) {
                    // Successful login: Clear failed attempts
                    clear_login_rate_limit();
                    
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
                    record_failed_login();
                    $delay_info = check_login_delay();
                    
                    if ($delay_info['delay'] > 0) {
                        $msg = "Invalid credentials. " . $delay_info['message'];
                    } else {
                        $msg = "Invalid email or password.";
                    }
                }
            } catch (PDOException $e) {
                // Detailed error handling is good for development
                $msg = "A database error occurred: " . $e->getMessage();
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
    <link rel="stylesheet" href="assets/css/ui.css">
    <noscript><style>[data-reveal]{opacity:1!important;transform:none!important}.bar-fill{width:var(--w)!important}</style></noscript>
    <style>
        /* === Mindanao Eversure — Landing UI (light theme) === */
        :root {
            --mev-navy: #102a5c;
            --mev-navy-dark: #0a1e42;
            --mev-footer: #0a1c3d;
            --mev-blue: #2563eb;
            --mev-blue-dark: #1d4ed8;
            --mev-blue-soft: #e8f0fe;
            --mev-yellow: #fbbf24;
            --mev-yellow-dark: #f59e0b;
            --mev-yellow-soft: #fef3c7;
            --mev-text: #1e293b;
            --mev-muted: #64748b;
            --mev-faint: #94a3b8;
            --mev-line: #e2e8f0;
            --mev-bg-soft: #f5f9ff;
            --mev-green: #22c55e;
            --mev-radius: 16px;
            --mev-shadow-sm: 0 4px 18px rgba(16, 42, 92, 0.07);
            --mev-shadow-md: 0 14px 34px rgba(16, 42, 92, 0.11);
            --mev-shadow-lg: 0 24px 54px rgba(16, 42, 92, 0.16);
        }

        body.mev-landing {
            background: #ffffff;
            color: var(--mev-text);
            font-family: 'Poppins', sans-serif;
            padding-top: 74px;
            overflow-x: hidden;
            line-height: 1.6;
        }

        html { scroll-behavior: smooth; overflow-x: hidden; }
        section[id], footer[id] { scroll-margin-top: 84px; }
        img { max-width: 100%; height: auto; }

        .mev-landing :focus-visible {
            outline: 2px solid var(--mev-blue);
            outline-offset: 2px;
            border-radius: 4px;
        }

        ::selection { background: rgba(251, 191, 36, 0.35); color: var(--mev-navy); }

        /* ---------- Scroll reveal ---------- */
        [data-reveal] {
            opacity: 0;
            transform: translateY(26px);
            transition: opacity 0.7s ease, transform 0.7s cubic-bezier(0.22, 0.61, 0.36, 1);
        }
        [data-reveal].revealed { opacity: 1; transform: none; }

        /* ---------- Navbar ---------- */
        .navbar-custom {
            background: #ffffff;
            border-bottom: 1px solid var(--mev-line);
            padding: 14px 0;
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 1030;
            transition: padding 0.3s ease, box-shadow 0.3s ease;
        }
        .navbar-custom.scrolled {
            padding: 9px 0;
            box-shadow: 0 8px 24px rgba(16, 42, 92, 0.10);
        }

        .navbar-brand {
            color: var(--mev-navy) !important;
            font-weight: 700;
            font-size: 1.02rem;
            display: flex;
            align-items: center;
            gap: 10px;
            line-height: 1.15;
        }
        .brand-mark {
            width: 38px; height: 38px;
            flex: none;
            background: linear-gradient(135deg, var(--mev-yellow) 0%, var(--mev-yellow-dark) 100%);
            border-radius: 11px;
            display: flex; align-items: center; justify-content: center;
            color: var(--mev-navy);
            box-shadow: 0 6px 14px rgba(251, 191, 36, 0.35);
        }
        .brand-mark i { width: 21px; height: 21px; }
        .brand-tagline {
            display: block;
            font-size: 0.5rem;
            letter-spacing: 0.16em;
            color: var(--mev-blue);
            text-transform: uppercase;
            font-weight: 600;
            margin-top: 1px;
        }

        .navbar-custom .nav-link {
            color: var(--mev-muted) !important;
            font-weight: 500;
            font-size: 0.85rem;
            padding: 6px 12px !important;
            position: relative;
            
        }
        .navbar-custom .nav-link:hover { color: var(--mev-blue) !important; }
        .navbar-custom .nav-link.active { color: var(--mev-blue) !important; font-weight: 600; }
        .navbar-custom .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: 0; left: 12px; right: 12px;
            height: 2.5px;
            background: var(--mev-yellow);
            border-radius: 2px;
        }

        .btn-mev-login {
            border: 1.5px solid var(--mev-blue);
            background: #ffffff;
            color: var(--mev-blue);
            font-weight: 600;
            padding: 6px 20px;
            font-size: 0.82rem;
            border-radius: 8px;
            transition: 0.2s;
            text-decoration: none;
        }
        .btn-mev-login:hover {
            background: var(--mev-blue);
            color: #ffffff;
        }

        .btn-mev-register {
            background: var(--mev-yellow);
            color: var(--mev-navy);
            font-weight: 600;
            padding: 6px 20px;
            font-size: 0.82rem;
            border-radius: 8px;
            border: 1.5px solid var(--mev-yellow);
            transition: 0.2s;
            text-decoration: none;
        }
        .btn-mev-register:hover { background: var(--mev-yellow-dark); border-color: var(--mev-yellow-dark); color: var(--mev-navy); }

        /* ---------- Buttons ---------- */
        .btn-cta-primary {
            background: var(--mev-yellow);
            color: var(--mev-navy);
            font-weight: 600;
            padding: 12px 26px;
            border-radius: 10px;
            border: none;
            font-size: 0.92rem;
            transition: 0.25s;
            box-shadow: 0 8px 22px rgba(251, 191, 36, 0.32);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-cta-primary:hover {
            background: var(--mev-yellow-dark);
            color: var(--mev-navy);
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(245, 158, 11, 0.4);
        }
        .btn-cta-primary i { width: 17px; height: 17px; }

        .btn-cta-outline {
            background: transparent;
            color: var(--mev-navy);
            border: none;
            font-weight: 600;
            padding: 12px 10px;
            font-size: 0.92rem;
            transition: 0.25s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }
        .btn-cta-outline:hover {
            color: var(--mev-blue);
        }
        .btn-cta-outline i { width: 17px; height: 17px; }

        .btn-cta-outline-yellow {
            background: #ffffff;
            color: var(--mev-yellow-dark);
            border: 1.5px solid var(--mev-yellow);
            font-weight: 600;
            padding: 10px 22px;
            border-radius: 10px;
            font-size: 0.86rem;
            transition: 0.25s;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
        }
        .btn-cta-outline-yellow:hover {
            background: var(--mev-yellow);
            color: var(--mev-navy);
        }
        .btn-cta-outline-yellow i { width: 15px; height: 15px; }

        /* ---------- Hero ---------- */
        .hero-section {
            position: relative;
            padding: 40px 0;
            min-height: calc(100vh - 74px);
            display: flex;
            align-items: center;
            background: #ffffff;
            overflow: hidden;
        }
        .hero-section::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(60% 60% at 88% 12%, rgba(37, 99, 235, 0.06), transparent 65%);
            pointer-events: none;
        }

        .hero-eyebrow {
            display: block;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--mev-faint);
            margin-bottom: 16px;
        }

        .hero-title {
            font-size: clamp(2rem, 4vw, 2.9rem);
            font-weight: 800;
            color: var(--mev-navy);
            line-height: 1.14;
            margin-bottom: 18px;
        }
        .hero-title span { color: var(--mev-yellow-dark); display: block; }

        .hero-subtitle {
            color: var(--mev-muted);
            font-size: 0.98rem;
            line-height: 1.75;
            margin: 0 auto 30px;
            max-width: 480px;
        }

        .hero-cta { display: flex; gap: 14px; flex-wrap: wrap; justify-content: center; margin-bottom: 34px; }

        .hero-trust {
            display: flex;
            gap: 26px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .hero-trust span {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--mev-muted);
        }
        .hero-trust i { width: 15px; height: 15px; color: var(--mev-yellow-dark); }

        /* ---------- Shared section bits ---------- */
        .section-eyebrow {
            display: inline-block;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--mev-blue);
            margin-bottom: 12px;
        }
        .section-title { color: var(--mev-navy); font-weight: 700; font-size: 1.6rem; margin-bottom: 8px; }
        .section-subtitle { color: var(--mev-muted); font-size: 0.92rem; max-width: 560px; }
        .section-header { text-align: center; margin-bottom: 46px; }
        .section-header .section-subtitle { margin: 0 auto; }

        /* ---------- Why choose ---------- */
        .services-why { margin-bottom: 36px; scroll-margin-top: 84px; }
        .why-item { text-align: center; padding: 10px 16px; height: 100%; }
        .why-icon {
            width: 54px; height: 54px;
            background: transparent;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            color: var(--mev-navy);
            transition: transform 0.25s;
        }
        .why-item:hover .why-icon { transform: translateY(-4px) rotate(-4deg); }
        .why-icon i { width: 24px; height: 24px; }
        .why-title { font-weight: 600; font-size: 0.98rem; color: var(--mev-navy); margin-bottom: 6px; }
        .why-desc { color: var(--mev-muted); font-size: 0.83rem; line-height: 1.6; margin: 0; }

        /* ---------- Services ---------- */
        .services-section { padding: 44px 0 72px; background: var(--mev-bg-soft); }
        .services-head { margin-bottom: 28px; }
        .services-head .section-subtitle { margin: 0; }
        .services-why .section-header { margin-bottom: 30px; }

        .service-card {
            background: #ffffff;
            border: 1px solid var(--mev-line);
            border-radius: var(--mev-radius);
            overflow: hidden;
            height: 100%;
            box-shadow: var(--mev-shadow-sm);
            transition: transform 0.25s, box-shadow 0.25s;
            display: flex;
            flex-direction: column;
        }
        .service-card:hover { transform: translateY(-6px); box-shadow: var(--mev-shadow-md); }
        .service-img {
            height: 118px;
            position: relative;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
        }
        .service-img::after {
            content: '';
            position: absolute;
            width: 170px; height: 170px;
            border-radius: 50%;
            border: 26px solid rgba(255, 255, 255, 0.08);
            right: -60px; top: -60px;
        }
        .service-img .s-icon {
            width: 52px; height: 52px;
            background: transparent;
            border: none;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            color: #ffffff;
            position: relative;
            z-index: 1;
        }
        .service-img .s-icon i { width: 25px; height: 25px; }
        .s-grad-1 { background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); }
        .s-grad-2 { background: linear-gradient(135deg, #0f2a5c 0%, #2563eb 100%); }
        .s-grad-3 { background: linear-gradient(135deg, #0369a1 0%, #38bdf8 100%); }
        .s-grad-4 { background: linear-gradient(135deg, #1d4ed8 0%, #102a5c 100%); }

        .service-body { padding: 20px 18px 18px; display: flex; flex-direction: column; flex: 1; }
        .service-name { font-weight: 700; font-size: 0.98rem; color: var(--mev-navy); margin-bottom: 5px; }
        .service-desc { color: var(--mev-muted); font-size: 0.82rem; line-height: 1.55; margin-bottom: 14px; }
        .service-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 12px;
            border-top: 1px dashed var(--mev-line);
            margin-top: auto;
        }
        .service-price { color: var(--mev-navy); font-weight: 700; font-size: 0.95rem; }
        .service-time { color: var(--mev-faint); font-size: 0.76rem; display: inline-flex; align-items: center; gap: 4px; }
        .service-time i { width: 13px; height: 13px; }
        .service-book {
            margin-top: 10px;
            color: var(--mev-yellow-dark);
            font-weight: 600;
            font-size: 0.82rem;
            font-family: inherit;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            transition: gap 0.2s;
        }
        .service-book:hover { gap: 9px; color: var(--mev-yellow-dark); }
        .service-book i { width: 14px; height: 14px; }

        /* ---------- How it works ---------- */
        .how-section { padding: 84px 0; background: #ffffff; }
        .how-left .btn-cta-primary { margin-top: 18px; }

        .steps-row { position: relative; }
        .step-item { text-align: left; padding: 0 12px; position: relative; }
        .step-item:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 24px;
            left: 66px;
            width: calc(100% - 54px);
            border-top: 2px dashed #cbd5e1;
        }
        .step-icon {
            width: 48px; height: 48px;
            border-radius: 13px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 14px;
            position: relative;
            z-index: 1;
        }
        .step-icon.blue { background: transparent; color: var(--mev-blue); }
        .step-icon.yellow { background: transparent; color: var(--mev-yellow-dark); }
        .step-icon i { width: 22px; height: 22px; }
        .step-title { font-weight: 700; font-size: 0.95rem; color: var(--mev-navy); margin-bottom: 6px; }
        .step-title .s-num { color: var(--mev-blue); margin-right: 4px; }
        .step-desc { color: var(--mev-muted); font-size: 0.8rem; line-height: 1.55; margin: 0; }

        /* ---------- Health ---------- */
        .health-section { padding: 84px 0; background: var(--mev-bg-soft); }
        .health-bike-wrap {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 380px;
        }
        .health-bike-wrap::before {
            content: '';
            position: absolute;
            width: 340px; height: 340px;
            max-width: 92%;
            border-radius: 50%;
            background: transparent;
            border: 2px dashed rgba(37, 99, 235, 0.35);
        }
        .health-bike-wrap::after {
            content: '';
            position: absolute;
            width: 56px; height: 56px;
            border-radius: 50%;
            background: var(--mev-yellow);
            bottom: 12%;
            left: 8%;
            opacity: 0.9;
        }
        .health-bike {
            position: relative;
            z-index: 1;
            width: 300px; height: 300px;
            max-width: 82%;
            border-radius: 50%;
            object-fit: cover;
            box-shadow: var(--mev-shadow-md);
        }

        .health-score-row { display: flex; gap: 34px; align-items: center; flex-wrap: wrap; margin: 26px 0 28px; }

        .health-donut {
            width: 148px; height: 148px;
            flex: none;
            border-radius: 50%;
            background: conic-gradient(var(--mev-green) 0 92%, #e2e8f0 92% 100%);
            display: flex; align-items: center; justify-content: center;
            box-shadow: var(--mev-shadow-sm);
        }
        .health-donut-inner {
            width: 112px; height: 112px;
            background: #ffffff;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .health-donut-num { font-size: 1.75rem; font-weight: 800; color: var(--mev-navy); line-height: 1; }
        .health-donut-label { font-size: 0.62rem; font-weight: 600; color: var(--mev-muted); margin-top: 4px; }

        .health-bars { flex: 1; min-width: 240px; display: flex; flex-direction: column; gap: 15px; }
        .h-bar { display: flex; align-items: center; gap: 12px; }
        .h-bar-icon {
            width: 34px; height: 34px;
            flex: none;
            background: transparent;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: var(--mev-blue);
        }
        .h-bar-icon i { width: 17px; height: 17px; }
        .h-bar-content { flex: 1; }
        .h-bar-top { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 5px; }
        .h-bar-label { font-weight: 600; color: var(--mev-navy); font-size: 0.82rem; }
        .h-bar-value { font-size: 0.76rem; font-weight: 600; color: var(--mev-muted); }
        .h-bar-track { height: 7px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
        .bar-fill {
            height: 100%;
            width: 0;
            border-radius: 999px;
            background: var(--mev-green);
            transition: width 1.2s cubic-bezier(0.22, 0.61, 0.36, 1) 0.3s;
        }
        .bar-fill.warn { background: var(--mev-yellow); }
        .revealed .bar-fill { width: var(--w); }

        /* ---------- Track ---------- */
        .track-section { padding: 84px 0 60px; background: #ffffff; }

        .track-card {
            background: #ffffff;
            border: 1px solid var(--mev-line);
            border-radius: 18px;
            padding: 26px 26px 30px;
            box-shadow: var(--mev-shadow-md);
        }
        .track-card-head {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 20px;
            margin-bottom: 26px;
            border-bottom: 1px solid var(--mev-line);
        }
        .track-card-head .t-icon {
            width: 40px; height: 40px;
            flex: none;
            background: transparent;
            border-radius: 11px;
            display: flex; align-items: center; justify-content: center;
            color: var(--mev-blue);
        }
        .track-card-head .t-icon i { width: 20px; height: 20px; }
        .track-card-head .t-name { font-weight: 700; font-size: 0.92rem; color: var(--mev-navy); line-height: 1.3; }
        .track-card-head .t-sub { font-size: 0.72rem; color: var(--mev-muted); }
        .track-pill {
            margin-left: auto;
            flex: none;
            background: var(--mev-yellow-soft);
            color: var(--mev-yellow-dark);
            font-size: 0.68rem;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 999px;
        }

        .track-steps { display: flex; }
        .track-step { flex: 1; text-align: center; position: relative; }
        .track-step::before {
            content: '';
            position: absolute;
            top: 17px;
            left: -50%;
            width: 100%;
            height: 3px;
            background: #e2e8f0;
            z-index: 0;
        }
        .track-step:first-child::before { display: none; }
        .track-step.done::before, .track-step.current::before { background: var(--mev-green); }
        .track-node {
            width: 36px; height: 36px;
            margin: 0 auto 9px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            position: relative;
            z-index: 1;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .track-node i { width: 16px; height: 16px; }
        .track-step.done .track-node { background: transparent; color: var(--mev-green); }
        .track-step.current .track-node {
            background: transparent;
            color: var(--mev-yellow-dark);
        }
        .track-step.todo .track-node { background: transparent; color: var(--mev-faint); }
        .track-label { font-size: 0.66rem; font-weight: 600; color: var(--mev-muted); line-height: 1.35; }
        .track-step.done .track-label { color: var(--mev-navy); }
        .track-step.current .track-label { color: var(--mev-navy); font-weight: 700; }

        /* ---------- Reminders + CTA ---------- */
        .reminder-section { padding: 0 0 84px; background: #ffffff; }
        .reminder-wrap { display: flex; align-items: center; gap: 16px; height: 100%; }
        .reminder-icon {
            width: 46px; height: 46px;
            flex: none;
            background: transparent;
            border-radius: 13px;
            display: flex; align-items: center; justify-content: center;
            color: var(--mev-yellow-dark);
        }
        .reminder-icon i { width: 22px; height: 22px; }
        .reminder-title { font-weight: 700; font-size: 0.95rem; color: var(--mev-navy); line-height: 1.35; margin-bottom: 3px; }
        .reminder-sub { font-size: 0.78rem; color: var(--mev-muted); margin: 0; }

        .reminder-cards { display: flex; gap: 14px; flex-wrap: wrap; }
        .reminder-card {
            flex: 1;
            min-width: 200px;
            display: flex;
            align-items: center;
            gap: 12px;
            background: #ffffff;
            border: 1px solid var(--mev-line);
            border-radius: 14px;
            padding: 14px 16px;
            box-shadow: var(--mev-shadow-sm);
        }
        .reminder-card .rc-icon {
            width: 38px; height: 38px;
            flex: none;
            border-radius: 11px;
            display: flex; align-items: center; justify-content: center;
        }
        .reminder-card .rc-icon.blue { background: transparent; color: var(--mev-blue); }
        .reminder-card .rc-icon.yellow { background: transparent; color: var(--mev-yellow-dark); }
        .reminder-card .rc-icon i { width: 18px; height: 18px; }
        .rc-label { font-size: 0.66rem; font-weight: 600; color: var(--mev-muted); text-transform: uppercase; letter-spacing: 0.05em; }
        .rc-value { font-size: 0.85rem; font-weight: 700; color: var(--mev-navy); line-height: 1.3; }
        .rc-sub { font-size: 0.68rem; color: var(--mev-faint); }

        .cta-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #0a1e42 0%, #102a5c 100%);
            border-radius: 22px;
            min-height: 260px;
            height: 100%;
            box-shadow: var(--mev-shadow-lg);
            display: flex;
            align-items: center;
        }
        .cta-img {
            position: absolute;
            top: 0; right: 0; bottom: 0;
            width: 52%;
            object-fit: cover;
            -webkit-mask-image: linear-gradient(90deg, transparent 0%, #000 42%);
            mask-image: linear-gradient(90deg, transparent 0%, #000 42%);
            opacity: 0.85;
        }
        .cta-content { position: relative; z-index: 1; padding: 36px 34px; max-width: 62%; }
        .cta-title { font-size: 1.35rem; font-weight: 700; color: #ffffff; margin-bottom: 10px; line-height: 1.3; }
        .cta-subtitle { font-size: 0.85rem; color: rgba(255, 255, 255, 0.68); margin-bottom: 22px; }

        /* ---------- Footer ---------- */
        .app-footer {
            background: var(--mev-footer);
            color: #cbd5e1;
            padding: 44px 0 24px;
        }
        .footer-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
            padding-bottom: 26px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.09);
        }
        .app-footer .navbar-brand { color: #ffffff !important; }
        .app-footer .brand-tagline { color: var(--mev-yellow); }
        .footer-nav { display: flex; gap: 6px; flex-wrap: wrap; }
        .footer-nav a {
            color: rgba(255, 255, 255, 0.65);
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 500;
            padding: 4px 12px;
            
        }
        .footer-nav a:hover { color: var(--mev-yellow); }
        .social-links { display: flex; gap: 10px; }
        .social-links a {
            width: 36px; height: 36px;
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: rgba(255, 255, 255, 0.7);
            transition: 0.25s;
        }
        .social-links a i { width: 16px; height: 16px; }
        .social-links a:hover {
            background: var(--mev-yellow);
            border-color: var(--mev-yellow);
            color: var(--mev-navy);
            transform: translateY(-3px);
        }
        .footer-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding-top: 22px;
        }
        .footer-copy { color: rgba(255, 255, 255, 0.45); font-size: 0.78rem; margin: 0; }
        .footer-tagline {
            font-family: 'Dancing Script', cursive;
            color: var(--mev-yellow);
            font-size: 1.15rem;
            margin: 0;
        }

        /* ---------- Modals ---------- */
        .modal-blur-effect .modal-dialog { max-width: 400px; }
        .modal-blur-effect .modal-content {
            background: #ffffff;
            color: var(--mev-text);
            border: 1px solid var(--mev-line);
            border-radius: 18px;
            box-shadow: var(--mev-shadow-lg);
            overflow: hidden;
            position: relative;
        }
        .mev-landing .modal-backdrop.show { backdrop-filter: blur(5px); }
        .modal-blur-effect .modal-content::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--mev-blue) 0%, var(--mev-yellow) 100%);
        }
        .modal-blur-effect.show .modal-content { animation: authPop 0.35s cubic-bezier(0.22, 0.61, 0.36, 1); }
        @keyframes authPop {
            from { transform: scale(0.95) translateY(12px); opacity: 0; }
            to { transform: none; opacity: 1; }
        }
        .modal-blur-effect .modal-body { padding: 24px 22px 18px; }
        .modal-blur-effect .modal-close {
            position: absolute;
            top: 14px; right: 14px;
            z-index: 5;
            width: 30px; height: 30px;
            padding: 0;
            background-color: #f1f5f9;
            background-size: 12px;
            border: 1px solid var(--mev-line);
            border-radius: 9px;
            opacity: 1;
            transition: 0.2s;
        }
        .modal-blur-effect .modal-close:hover { background-color: #e2e8f0; }
        #registerModal .modal-dialog { max-width: 420px; }
        .text-brand { color: var(--mev-yellow-dark); }

        .auth-brand { text-align: center; margin-bottom: 12px; }
        .auth-logo {
            width: 44px; height: 44px;
            margin: 0 auto 10px;
            background: transparent;
            border-radius: 13px;
            display: flex; align-items: center; justify-content: center;
            color: var(--mev-navy);
        }
        .auth-logo i { width: 22px; height: 22px; }
        .auth-brand-name { font-weight: 700; color: var(--mev-navy); font-size: 0.95rem; line-height: 1.3; }
        .auth-brand-name small {
            display: block;
            font-size: 0.52rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--mev-blue);
            font-weight: 600;
            margin-top: 2px;
        }
        .auth-heading { text-align: center; margin-bottom: 14px; }
        .auth-heading h4 { font-size: 1.15rem; font-weight: 700; color: var(--mev-navy); margin-bottom: 3px; }
        .auth-heading p { font-size: 0.8rem; color: var(--mev-muted); margin: 0; }
        .auth-field { margin-bottom: 10px; }
        .auth-field > label {
            display: block;
            font-size: 0.74rem;
            font-weight: 600;
            color: var(--mev-text);
            margin-bottom: 4px;
        }
        .auth-submit {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            height: 42px;
            margin-top: 4px;
        }
        .auth-submit i { width: 16px; height: 16px; transition: transform 0.2s; }
        .auth-submit:hover i { transform: translateX(4px); }
        .auth-switch {
            text-align: center;
            font-size: 0.8rem;
            color: var(--mev-muted);
            border-top: 1px solid var(--mev-line);
            padding-top: 12px;
            margin: 12px 0 0;
        }
        .auth-switch a { color: var(--mev-blue); text-decoration: none; font-weight: 600; }
        .auth-switch a:hover { color: var(--mev-blue-dark); }
        .auth-secure {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 10px;
            font-size: 0.68rem;
            color: var(--mev-faint);
        }
        .auth-secure i { width: 12px; height: 12px; color: var(--mev-yellow-dark); }
        .pw-meter {
            display: none;
            height: 5px;
            background: #e2e8f0;
            border-radius: 99px;
            overflow: hidden;
            margin-top: 6px;
        }
        .pw-meter span {
            display: block;
            height: 100%;
            width: 0;
            border-radius: 99px;
            transition: width 0.3s ease;
        }
        .pw-hint { display: none; font-size: 0.7rem; color: var(--mev-muted); margin-top: 4px; }
        .pw-hint strong { font-weight: 600; }
        .modal-blur-effect .input-group {
            border: 1px solid var(--mev-line);
            border-radius: 12px;
            overflow: hidden;
            background: #f8fafc;
            transition: box-shadow 0.15s ease-in-out;
        }
        .modal-blur-effect .input-group:focus-within {
            border-color: var(--mev-blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
            background: #ffffff;
        }
        .modal-blur-effect .input-group .input-group-text {
            background: transparent;
            border: none;
            padding: 9px 12px;
        }
        .modal-blur-effect .input-group .form-control {
            border: none;
            background: transparent;
            padding: 9px 12px;
            font-size: 0.88rem;
            height: 40px;
            box-shadow: none;
            color: var(--mev-text);
        }
        .modal-blur-effect .input-group .form-control::placeholder { color: var(--mev-faint); }
        .modal-blur-effect .input-group .form-control:focus {
            background: transparent;
            border: none;
            box-shadow: none;
            color: var(--mev-text);
        }
        .modal-blur-effect .input-icon { width: 16px; height: 16px; color: var(--mev-blue); }
        .modal-blur-effect .password-toggle { cursor: pointer; }
        .modal-blur-effect .password-toggle .input-icon { color: var(--mev-faint); }
        .modal-blur-effect .form-check-input {
            width: 1em; height: 1em;
            background-color: #ffffff;
            border-color: #cbd5e1;
        }
        .modal-blur-effect .form-check-input:checked { background-color: var(--mev-blue); border-color: var(--mev-blue); }
        .modal-blur-effect .form-check-input:focus { border-color: var(--mev-blue); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12); }
        .modal-blur-effect .form-check-label { font-size: 0.78rem; color: var(--mev-muted); padding-left: 0.3rem; }
        .modal-blur-effect .forgot-link { color: var(--mev-blue); font-size: 0.78rem; text-decoration: none; font-weight: 500; }
        .modal-blur-effect .forgot-link:hover { color: var(--mev-blue-dark); }
        .modal-blur-effect .remember-row { margin: 10px 0 12px; }
        .modal-blur-effect .btn-primary {
            padding: 10px 14px;
            font-size: 0.9rem;
            border-radius: 10px;
            font-weight: 600;
            background: var(--mev-yellow);
            border: none;
            color: var(--mev-navy);
            transition: 0.2s;
        }
        .modal-blur-effect .btn-primary:hover { background: var(--mev-yellow-dark); color: var(--mev-navy); }
        .modal-blur-effect .alert-warning {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
            font-size: 0.8rem;
        }
        .modal-blur-effect .alert-success {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            font-size: 0.8rem;
        }
        .modal-blur-effect .alert a { color: inherit; font-weight: 600; }

        /* ---------- Scroll to top ---------- */
        .scroll-top {
            position: fixed;
            bottom: 26px; right: 26px;
            width: 46px; height: 46px;
            background: var(--mev-yellow);
            color: var(--mev-navy);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px);
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            z-index: 999;
            border: none;
            box-shadow: 0 10px 24px rgba(251, 191, 36, 0.4);
        }
        .scroll-top.visible { opacity: 1; visibility: visible; transform: translateY(0); }
        .scroll-top:hover { transform: translateY(-3px); background: var(--mev-yellow-dark); }
        .scroll-top i { width: 20px; height: 20px; }

        /* ---------- Responsive ---------- */
        @media (max-width: 991px) {
            .navbar-collapse {
                background: #ffffff;
                border: 1px solid var(--mev-line);
                padding: 16px;
                margin-top: 12px;
                border-radius: 14px;
                box-shadow: var(--mev-shadow-md);
            }
            .navbar-custom .nav-link.active::after { left: 0; right: auto; width: 30px; }
            .hero-section { padding: 20px 0 70px; }
            .how-left { text-align: center; margin-bottom: 40px; }
            .how-left .section-subtitle { margin: 0 auto; }
            .step-item:not(:last-child)::after { display: none; }
            .step-item { text-align: center; margin-bottom: 32px; }
            .step-icon { margin: 0 auto 14px; }
            .health-bike-wrap { min-height: 320px; margin-bottom: 30px; }
            .health-bike-wrap::before { width: 300px; height: 300px; }
            .health-bike { width: 260px; height: 260px; }
            .track-section .col-lg-5 { text-align: center; margin-bottom: 36px; }
            .track-section .section-subtitle { margin: 0 auto; }
            .cta-content { max-width: 100%; text-align: center; margin: 0 auto; }
            .cta-img { opacity: 0.25; width: 100%; -webkit-mask-image: none; mask-image: none; }
            .cta-card { justify-content: center; }
            .reminder-wrap { justify-content: center; text-align: center; flex-direction: column; margin-bottom: 26px; }
        }
        @media (max-width: 576px) {
            .navbar-brand { font-size: 0.92rem; }
            .navbar-collapse { text-align: center; }
            .btn-mev-login, .btn-mev-register { width: 100%; }
            .hero-section { padding: 16px 0 60px; }
            .hero-title { font-size: 1.8rem; }
            .hero-subtitle { font-size: 0.9rem; }
            .hero-cta .btn, .hero-cta a { width: 100%; justify-content: center; }
            .hero-trust { justify-content: center; gap: 18px; }
            .services-section, .how-section,
            .health-section, .track-section { padding: 60px 0; }
            .reminder-section { padding: 0 0 60px; }
            .section-title { font-size: 1.35rem; }
            .health-score-row { gap: 22px; justify-content: center; }
            .track-card { padding: 20px 14px 24px; }
            .track-label { font-size: 0.58rem; }
            .track-node { width: 30px; height: 30px; }
            .track-step::before { top: 15px; }
            .cta-content { padding: 30px 22px; }
            .cta-title { font-size: 1.15rem; }
            .footer-top { flex-direction: column; text-align: center; }
            .footer-nav { justify-content: center; }
            .footer-bottom { flex-direction: column; text-align: center; }
            .scroll-top { bottom: 18px; right: 18px; width: 42px; height: 42px; }
        }
        @media (max-width: 400px) {
            .hero-title { font-size: 1.55rem; }
            .section-title { font-size: 1.2rem; }
        }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            [data-reveal] { opacity: 1 !important; transform: none !important; transition: none !important; }
            .bar-fill { transition: none !important; }
        }
    </style>
    <style>
        :root {
            --mf-navy: #0b1f4d;
            --mf-navy-2: #12306f;
            --mf-blue: #2449a8;
            --mf-yellow: #f6c614;
            --mf-yellow-dark: #e7ad00;
            --mf-ink: #172554;
            --mf-muted: #64748b;
            --mf-soft: #f3f6fb;
            --mf-line: #e5eaf3;
            --mf-green: #22c55e;
        }

        body.mev-landing {
            padding-top: 74px;
            background: #ffffff;
        }

        .navbar-custom {
            padding: 14px 0;
            border-bottom: 3px solid var(--mf-yellow);
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(14px);
        }
        .navbar-custom.scrolled { box-shadow: 0 10px 30px rgba(11, 31, 77, 0.08); }
        .brand-mark {
            width: 34px;
            height: 34px;
            background: transparent;
            color: #FACC15;
            border-radius: 0;
            box-shadow: none;
        }
        .brand-mark i { width: 34px; height: 34px; }
        .brand-tagline {
            color: #FACC15;
            font-size: 0.5rem;
            letter-spacing: 0.12em;
        }
        .navbar-custom .nav-link {
            color: #4b5b7c !important;
            font-size: 0.82rem;
            padding: 7px 13px !important;
        }
        .navbar-custom .nav-link:hover,
        .navbar-custom .nav-link.active { color: var(--mf-navy) !important; }
        .btn-mev-login {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            background: var(--mf-navy);
            border: 1.5px solid var(--mf-navy);
            color: #ffffff;
            border-radius: 10px;
            padding: 8px 22px;
        }
        .btn-mev-login:hover {
            background: var(--mf-navy-2);
            border-color: var(--mf-navy-2);
            color: #ffffff;
        }
        .btn-mev-register {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--mf-yellow);
            border-color: var(--mf-yellow);
            border-radius: 10px;
            padding: 8px 22px;
        }

        .btn-cta-primary {
            background: var(--mf-yellow);
            border-radius: 12px;
            box-shadow: 0 12px 24px rgba(246, 198, 20, 0.24);
            padding: 13px 28px;
        }
        .btn-cta-primary:hover {
            background: var(--mf-yellow-dark);
            box-shadow: 0 16px 30px rgba(231, 173, 0, 0.28);
        }
        .btn-cta-outline {
            border: none;
            background: transparent;
            color: var(--mf-navy);
            padding: 13px 10px;
            font-weight: 600;
            gap: 4px;
        }
        .btn-cta-outline:hover {
            background: transparent;
            color: var(--mf-blue);
            transform: none;
        }
        .btn-cta-outline i { transition: transform 0.2s ease; }
        .btn-cta-outline:hover i { transform: translateX(3px); }

        .hero-section {
            min-height: calc(100vh - 74px);
            min-height: calc(100svh - 74px);
            padding: 58px 0 76px;
            background: linear-gradient(180deg, #fbfdff 0%, #f5f8fc 100%);
        }
        .hero-section::before {
            content: '';
            position: absolute;
            inset: auto;
            top: 0;
            right: 12%;
            bottom: auto;
            left: auto;
            width: 190px;
            height: 118px;
            background: var(--mf-yellow);
            clip-path: polygon(30% 0, 100% 0, 70% 100%, 0 100%);
            opacity: 0.95;
            pointer-events: none;
        }
        .hero-section::after {
            content: '';
            position: absolute;
            right: 0;
            bottom: 0;
            width: 220px;
            height: 42%;
            background: var(--mf-navy);
            clip-path: polygon(38% 0, 100% 0, 100% 100%, 0 100%);
            opacity: 0.96;
        }
        .hero-section .container { position: relative; z-index: 1; }
        .hero-copy { max-width: 620px; }
        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            background: rgba(246, 198, 20, 0.16);
            color: #8a6b00;
            border-radius: 999px;
            padding: 7px 14px;
            font-size: 0.62rem;
            letter-spacing: 0.13em;
            margin-bottom: 18px;
        }
        .hero-title {
            font-size: clamp(2.4rem, 4.8vw, 4rem);
            line-height: 1.02;
            letter-spacing: -0.045em;
            margin-bottom: 20px;
        }
        .hero-title span {
            color: var(--mf-yellow);
            text-shadow: 0 1px 0 rgba(11, 31, 77, 0.05);
        }
        .hero-subtitle {
            margin: 0 0 28px;
            max-width: 540px;
            font-size: 0.98rem;
        }
        .hero-cta {
            justify-content: flex-start;
            margin-bottom: 0;
        }
        .hero-feature-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin: 0 0 30px;
            max-width: 650px;
        }
        .hero-feature {
            display: flex;
            align-items: center;
            gap: 9px;
            min-width: 0;
        }
        .hero-feature-icon {
            width: 40px;
            height: 40px;
            flex: none;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            color: var(--mf-yellow-dark);
            border-radius: 11px;
        }
        .hero-feature-icon i { width: 18px; height: 18px; }
        .hero-feature strong {
            display: block;
            color: var(--mf-navy);
            font-size: 0.72rem;
            line-height: 1.25;
        }
        .hero-feature small {
            display: block;
            color: var(--mf-muted);
            font-size: 0.66rem;
            line-height: 1.25;
        }

        .hero-visual {
            position: relative;
            padding: 34px 0 0 18px;
        }
        .hero-visual::before {
            content: '';
            position: absolute;
            top: 0;
            left: 12%;
            width: 170px;
            height: 105px;
            background: var(--mf-yellow);
            clip-path: polygon(28% 0, 100% 0, 72% 100%, 0 100%);
            z-index: 0;
        }
        .dashboard-preview {
            position: relative;
            z-index: 1;
            display: flex;
            max-width: 650px;
            min-height: 350px;
            margin-left: auto;
            overflow: hidden;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 26px 70px rgba(11, 31, 77, 0.22);
            transform: perspective(1200px) rotateY(-4deg);
        }
        .dashboard-sidebar {
            width: 136px;
            flex: none;
            background: var(--mf-navy);
            color: rgba(255, 255, 255, 0.72);
            padding: 18px 13px;
        }
        .dash-brand {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #ffffff;
            font-size: 0.82rem;
            font-weight: 700;
            margin-bottom: 22px;
        }
        .dash-brand-mark {
            width: 25px;
            height: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            color: var(--mf-yellow);
            border-radius: 7px;
        }
        .dash-brand-mark i { width: 14px; height: 14px; }
        .dash-nav { display: grid; gap: 7px; }
        .dash-nav span {
            display: flex;
            align-items: center;
            gap: 8px;
            min-height: 32px;
            padding: 7px 9px;
            border-radius: 8px;
            font-size: 0.64rem;
            font-weight: 600;
        }
        .dash-nav span.active {
            background: var(--mf-yellow);
            color: var(--mf-navy);
        }
        .dash-nav i { width: 14px; height: 14px; }
        .dashboard-main {
            flex: 1;
            min-width: 0;
            padding: 20px;
            background: #f8fafc;
        }
        .dash-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }
        .dash-greeting { font-size: 0.88rem; font-weight: 700; color: var(--mf-navy); }
        .dash-greeting p { margin: 3px 0 0; color: var(--mf-muted); font-size: 0.62rem; }
        .dash-user {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--mf-navy);
            font-size: 0.65rem;
            font-weight: 600;
        }
        .dash-user i { width: 22px; height: 22px; color: var(--mf-muted); }
        .dash-add {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--mf-yellow);
            color: var(--mf-navy);
            border-radius: 8px;
            padding: 7px 10px;
            font-size: 0.64rem;
            font-weight: 700;
        }
        .dash-add i { width: 12px; height: 12px; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }
        .stat-card {
            display: flex;
            align-items: center;
            gap: 9px;
            background: #ffffff;
            border: 1px solid var(--mf-line);
            border-radius: 10px;
            padding: 10px;
            box-shadow: 0 5px 15px rgba(15, 23, 42, 0.04);
        }
        .stat-icon {
            width: 28px;
            height: 28px;
            flex: none;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: transparent;
            color: var(--mf-blue);
        }
        .stat-icon i { width: 15px; height: 15px; }
        .stat-label {
            display: block;
            color: var(--mf-muted);
            font-size: 0.55rem;
            line-height: 1.1;
        }
        .stat-value {
            display: block;
            color: var(--mf-navy);
            font-size: 0.86rem;
            font-weight: 800;
            line-height: 1.1;
        }
        .dash-content-grid {
            display: grid;
            grid-template-columns: 1.5fr 0.8fr;
            gap: 12px;
        }
        .dash-panel {
            background: #ffffff;
            border: 1px solid var(--mf-line);
            border-radius: 10px;
            padding: 12px;
            min-width: 0;
        }
        .dash-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            color: var(--mf-navy);
            font-size: 0.67rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .dash-panel-head a {
            color: var(--mf-blue);
            font-size: 0.56rem;
            text-decoration: none;
        }
        .upcoming-list { display: grid; gap: 8px; }
        .upcoming-item {
            display: grid;
            grid-template-columns: 30px 1fr auto;
            align-items: center;
            gap: 9px;
        }
        .upcoming-icon {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            color: var(--mf-blue);
        }
        .upcoming-icon img {
            width: 30px;
            height: 30px;
            object-fit: contain;
            display: block;
        }
        .upcoming-name {
            color: var(--mf-navy);
            font-size: 0.62rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .upcoming-meta {
            color: var(--mf-muted);
            font-size: 0.52rem;
            line-height: 1.3;
        }
        .status-pill {
            padding: 4px 7px;
            border-radius: 999px;
            font-size: 0.48rem;
            font-weight: 800;
            white-space: nowrap;
        }
        .status-pill.scheduled { background: #fef3c7; color: #92400e; }
        .status-pill.upcoming { background: #dbeafe; color: #1d4ed8; }
        .quick-actions { display: grid; gap: 8px; }
        .quick-action {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--mf-muted);
            font-size: 0.58rem;
            line-height: 1.2;
        }
        .quick-action i {
            width: 24px;
            height: 24px;
            flex: none;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 7px;
            background: transparent;
            color: var(--mf-blue);
        }
        .quick-action strong { display: block; color: var(--mf-navy); font-size: 0.58rem; }

        .features-section,
        .services-section {
            padding: 78px 0 72px;
            background: #ffffff;
        }
        .features-section,
        .services-section,
        .how-section,
        .health-section,
        .track-section,
        .reminder-section {
            min-height: calc(100vh - 74px);
            min-height: calc(100svh - 74px);
            display: flex;
            align-items: center;
        }
        .features-section > .container,
        .services-section > .container,
        .how-section > .container,
        .health-section > .container,
        .track-section > .container,
        .reminder-section > .container {
            width: 100%;
        }
        .feature-showcase {
            display: grid;
            grid-template-columns: 250px minmax(0, 1fr);
            gap: 42px;
            align-items: center;
            margin-bottom: 0;
            scroll-margin-top: 84px;
        }
        .feature-intro .section-eyebrow {
            color: var(--mf-muted);
            margin-bottom: 10px;
        }
        .feature-intro .section-title {
            font-size: clamp(1.8rem, 3vw, 2.5rem);
            line-height: 1.08;
            letter-spacing: -0.03em;
            margin-bottom: 14px;
        }
        .feature-intro .section-title span { color: var(--mf-yellow-dark); }
        .feature-intro .section-subtitle { margin-bottom: 22px; }
        .feature-cards {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 14px;
        }
        .feature-card {
            position: relative;
            min-height: 158px;
            padding: 18px 16px 32px;
            background: #ffffff;
            border: 1px solid var(--mf-line);
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.05);
            transition: transform 0.25s, box-shadow 0.25s;
        }
        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 18px 38px rgba(11, 31, 77, 0.1);
        }
        .feature-card-icon {
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            border-radius: 10px;
            background: transparent;
            color: var(--mf-yellow-dark);
        }
        .feature-card-icon i { width: 19px; height: 19px; }
        .feature-card h4 {
            color: var(--mf-navy);
            font-size: 0.78rem;
            font-weight: 700;
            line-height: 1.25;
            margin-bottom: 6px;
        }
        .feature-card p {
            color: var(--mf-muted);
            font-size: 0.62rem;
            line-height: 1.55;
            margin: 0;
        }
        .feature-card::after {
            content: '→';
            position: absolute;
            left: 16px;
            bottom: 12px;
            color: var(--mf-yellow-dark);
            font-weight: 800;
        }
        .services-head { margin-top: 0; }
        #service-list { scroll-margin-top: 84px; }
        .services-head .section-title { font-size: 1.75rem; }
        .service-card {
            border-radius: 18px;
            border-color: var(--mf-line);
        }
        .service-img {
            height: 104px;
        }
        .service-img.s-grad-1,
        .service-img.s-grad-2,
        .service-img.s-grad-3,
        .service-img.s-grad-4 {
            background: linear-gradient(135deg, var(--mf-navy) 0%, var(--mf-blue) 100%);
        }

        .benefits-strip {
            position: relative;
            overflow: hidden;
            padding: 34px 0;
            background: var(--mf-navy);
            color: #ffffff;
        }
        .benefits-strip::after {
            content: '';
            position: absolute;
            right: -70px;
            bottom: -90px;
            width: 290px;
            height: 230px;
            background: var(--mf-yellow);
            clip-path: polygon(40% 0, 100% 0, 100% 100%, 0 100%);
        }
        .benefits-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 28px;
        }
        .benefit-item {
            display: flex;
            align-items: center;
            gap: 13px;
            min-width: 0;
        }
        .benefit-icon {
            width: 42px;
            height: 42px;
            flex: none;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: transparent;
            color: var(--mf-yellow);
        }
        .benefit-icon i { width: 20px; height: 20px; }
        .benefit-item strong {
            display: block;
            color: #ffffff;
            font-size: 0.78rem;
            line-height: 1.25;
        }
        .benefit-item small {
            display: block;
            color: rgba(255, 255, 255, 0.62);
            font-size: 0.62rem;
            line-height: 1.4;
        }
        .app-footer {
            padding: 30px 0 22px;
            background: #071739;
        }

        .modal-blur-effect .modal-dialog {
            width: calc(100% - 32px);
            max-width: 820px;
        }
        #registerModal .modal-dialog { max-width: 860px; }
        .modal-blur-effect .modal-content {
            max-height: calc(100vh - 40px);
            max-height: calc(100dvh - 40px);
            border: none;
            border-radius: 24px;
            box-shadow: 0 30px 80px rgba(11, 31, 77, 0.28);
        }
        .modal-blur-effect .modal-body { padding: 0; }
        .modal-blur-effect .modal-close {
            top: 16px;
            right: 16px;
            width: 34px;
            height: 34px;
            background-color: #f1f5f9;
            border-radius: 10px;
            z-index: 10;
        }
        .auth-shell {
            display: grid;
            grid-template-columns: 310px minmax(0, 1fr);
            height: 560px;
            max-height: calc(100vh - 40px);
            max-height: calc(100dvh - 40px);
            background: #ffffff;
        }
        .auth-visual {
            position: relative;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            padding: 34px 30px;
            background: linear-gradient(145deg, #071739 0%, var(--mf-navy) 55%, var(--mf-navy-2) 100%);
            color: #ffffff;
        }
        .auth-visual::before {
            content: '';
            position: absolute;
            top: -52px;
            right: -48px;
            width: 190px;
            height: 150px;
            background: var(--mf-yellow);
            clip-path: polygon(35% 0, 100% 0, 100% 100%, 0 100%);
            opacity: 0.95;
        }
        .auth-visual::after {
            content: '';
            position: absolute;
            left: -90px;
            bottom: -110px;
            width: 240px;
            height: 240px;
            border: 34px solid rgba(255, 255, 255, 0.06);
            border-radius: 50%;
        }
        .auth-visual-brand,
        .auth-visual-copy,
        .auth-visual-card { position: relative; z-index: 1; }
        .auth-visual-brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .auth-visual-logo {
            width: 40px;
            height: 40px;
            flex: none;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: transparent;
            color: var(--mf-yellow);
        }
        .auth-visual-logo i { width: 22px; height: 22px; }
        .auth-visual-name {
            color: #ffffff;
            font-size: 0.9rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .auth-visual-name small {
            display: block;
            margin-top: 2px;
            color: var(--mf-yellow);
            font-size: 0.52rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .auth-visual-copy { margin-top: 58px; }
        .auth-kicker {
            display: inline-flex;
            margin-bottom: 14px;
            padding: 6px 11px;
            border-radius: 999px;
            background: rgba(246, 198, 20, 0.14);
            color: var(--mf-yellow);
            font-size: 0.58rem;
            font-weight: 800;
            letter-spacing: 0.13em;
            text-transform: uppercase;
        }
        .auth-visual-copy h3 {
            max-width: 250px;
            margin-bottom: 12px;
            color: #ffffff;
            font-size: 1.65rem;
            font-weight: 800;
            line-height: 1.12;
            letter-spacing: -0.03em;
        }
        .auth-visual-copy p {
            max-width: 240px;
            margin: 0;
            color: rgba(255, 255, 255, 0.68);
            font-size: 0.78rem;
            line-height: 1.65;
        }
        .auth-visual-card {
            margin-top: auto;
            padding: 16px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.07);
            backdrop-filter: blur(8px);
        }
        .auth-card-label {
            display: block;
            margin-bottom: 5px;
            color: rgba(255, 255, 255, 0.55);
            font-size: 0.58rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .auth-card-value {
            display: block;
            color: #ffffff;
            font-size: 0.98rem;
            font-weight: 800;
            line-height: 1.25;
        }
        .auth-card-sub {
            display: block;
            margin-top: 4px;
            color: var(--mf-yellow);
            font-size: 0.62rem;
            font-weight: 600;
        }
        .auth-panel {
            min-width: 0;
            max-height: calc(100vh - 40px);
            max-height: calc(100dvh - 40px);
            overflow-y: auto;
            padding: 34px 34px 26px;
            background: #ffffff;
        }
        .auth-panel .auth-heading {
            margin-bottom: 20px;
            text-align: left;
        }
        .auth-panel .auth-heading h4 {
            margin-bottom: 5px;
            color: var(--mf-navy);
            font-size: 1.45rem;
            font-weight: 800;
            letter-spacing: -0.025em;
        }
        .auth-panel .auth-heading p {
            color: var(--mf-muted);
            font-size: 0.82rem;
        }
        .auth-panel .auth-field { margin-bottom: 13px; }
        .auth-panel .auth-field > label {
            margin-bottom: 6px;
            color: var(--mf-navy);
            font-size: 0.72rem;
            font-weight: 700;
        }
        .modal-blur-effect .input-group {
            border-color: #dbe3ef;
            border-radius: 13px;
            background: #f8fafc;
        }
        .modal-blur-effect .input-group:focus-within {
            border-color: var(--mf-blue);
            box-shadow: 0 0 0 4px rgba(36, 73, 168, 0.1);
        }
        .modal-blur-effect .input-group .input-group-text {
            width: 44px;
            justify-content: center;
            padding: 0;
        }
        .modal-blur-effect .input-group .form-control {
            height: 46px;
            font-size: 0.86rem;
        }
        .modal-blur-effect .input-icon {
            width: 17px;
            height: 17px;
            color: var(--mf-blue);
        }
        .modal-blur-effect .password-toggle:hover .input-icon { color: var(--mf-navy); }
        .modal-blur-effect .remember-row {
            align-items: center;
            margin: 15px 0 16px;
        }
        .modal-blur-effect .form-check-label,
        .modal-blur-effect .forgot-link { font-size: 0.76rem; }
        .modal-blur-effect .btn-primary.auth-submit {
            height: 48px;
            margin-top: 2px;
            border-radius: 12px;
            background: var(--mf-yellow);
            color: var(--mf-navy);
            font-size: 0.88rem;
            font-weight: 800;
        }
        .modal-blur-effect .btn-primary.auth-submit:hover {
            background: var(--mf-yellow-dark);
            color: var(--mf-navy);
        }
        .auth-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            column-gap: 12px;
        }
        .auth-form-grid .auth-field.full { grid-column: 1 / -1; }
        .auth-panel .auth-switch {
            margin-top: 16px;
            padding: 12px 14px;
            border: 1px solid var(--mf-line);
            border-radius: 12px;
            background: #f8fafc;
        }
        .auth-panel .auth-switch a { color: var(--mf-blue); }
        .auth-panel .auth-secure { margin-top: 12px; }
        .auth-panel .auth-secure i { color: var(--mf-yellow-dark); }
        .modal-blur-effect .alert {
            border-radius: 12px;
            line-height: 1.45;
        }

        @media (max-width: 1199px) {
            .feature-showcase { grid-template-columns: 1fr; gap: 28px; }
            .feature-cards { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 991px) {
            .hero-section { padding: 42px 0 64px; }
            .hero-copy { max-width: 100%; }
            .hero-visual { padding: 26px 0 0; }
            .dashboard-preview {
                margin: 0 auto;
                transform: none;
            }
            .feature-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .benefits-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .auth-shell { grid-template-columns: 265px minmax(0, 1fr); }
            .auth-visual { padding: 30px 24px; }
            .auth-panel { padding: 30px 26px 24px; }
        }
        @media (max-width: 767px) {
            .hero-feature-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .dashboard-sidebar { display: none; }
            .dashboard-preview { min-height: auto; }
            .dash-content-grid { grid-template-columns: 1fr; }
            .auth-shell {
                grid-template-columns: 1fr;
                height: auto;
                max-height: none;
            }
            .auth-visual { display: none; }
            .auth-panel { max-height: calc(100vh - 42px); max-height: calc(100dvh - 42px); }
        }
        @media (max-width: 576px) {
            body.mev-landing { padding-top: 70px; }
            .hero-title { font-size: 2.05rem; }
            .hero-feature-grid { gap: 10px; }
            .dashboard-main { padding: 14px; }
            .dash-top { flex-direction: column; }
            .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .feature-cards { grid-template-columns: 1fr; }
            .benefits-grid { grid-template-columns: 1fr; gap: 18px; }
            .auth-panel { padding: 28px 20px 20px; }
            .auth-form-grid { grid-template-columns: 1fr; column-gap: 0; }
            .modal-blur-effect .modal-dialog { width: calc(100% - 20px); margin: 10px auto; }
            .modal-blur-effect .modal-content { max-height: calc(100dvh - 20px); border-radius: 18px; }
            .modal-blur-effect .input-group .form-control { font-size: 16px; }
            .remember-row { flex-wrap: wrap; gap: 8px; }
        }

        /* ---------- Dark mode ---------- */
        html[data-theme="dark"] { color-scheme: dark; }
        html[data-theme="dark"] body.mev-landing { background: #0b1220; color: #cbd5e1; }

        .theme-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            padding: 0;
            background: transparent;
            border: 1.5px solid var(--mf-line);
            border-radius: 10px;
            color: var(--mf-navy);
            transition: 0.2s;
        }
        .theme-toggle:hover { border-color: var(--mf-yellow); color: var(--mf-yellow-dark); }
        .theme-icon { width: 17px; height: 17px; }
        .theme-icon-sun { display: none; }
        html[data-theme="dark"] .theme-toggle { border-color: #2a3a58; color: var(--mf-yellow); }
        html[data-theme="dark"] .theme-icon-sun { display: inline-block; }
        html[data-theme="dark"] .theme-icon-moon { display: none; }

        html[data-theme="dark"] .navbar-custom { background: rgba(11, 18, 32, 0.95); }
        html[data-theme="dark"] .navbar-custom .navbar-brand { color: #f1f5f9 !important; }
        html[data-theme="dark"] .navbar-custom .nav-link { color: #93a4c0 !important; }
        html[data-theme="dark"] .navbar-custom .nav-link:hover,
        html[data-theme="dark"] .navbar-custom .nav-link.active { color: var(--mf-yellow) !important; }
        html[data-theme="dark"] .navbar-toggler-icon {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255, 255, 255, 0.75%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
        }
        html[data-theme="dark"] .btn-mev-login { background: #182642; border-color: #2f4166; color: #ffffff; }
        html[data-theme="dark"] .btn-mev-login:hover { background: var(--mf-navy-2); border-color: var(--mf-navy-2); }

        html[data-theme="dark"] .hero-section { background: linear-gradient(180deg, #0d1526 0%, #0b1220 100%); }
        html[data-theme="dark"] .hero-section::after { background: #101d38; }
        html[data-theme="dark"] .features-section,
        html[data-theme="dark"] .services-section,
        html[data-theme="dark"] .how-section,
        html[data-theme="dark"] .track-section,
        html[data-theme="dark"] .reminder-section { background: #0b1220; }
        html[data-theme="dark"] .health-section { background: #0d1526; }
        html[data-theme="dark"] .benefits-strip { background: #081020; }
        html[data-theme="dark"] .app-footer { background: #050d1d; }

        html[data-theme="dark"] .hero-title,
        html[data-theme="dark"] .section-title,
        html[data-theme="dark"] .why-title,
        html[data-theme="dark"] .step-title,
        html[data-theme="dark"] .service-name,
        html[data-theme="dark"] .service-price,
        html[data-theme="dark"] .feature-card h4,
        html[data-theme="dark"] .track-card-head .t-name,
        html[data-theme="dark"] .reminder-title,
        html[data-theme="dark"] .rc-value,
        html[data-theme="dark"] .h-bar-label,
        html[data-theme="dark"] .health-donut-num,
        html[data-theme="dark"] .dash-greeting,
        html[data-theme="dark"] .dash-user,
        html[data-theme="dark"] .dash-panel-head,
        html[data-theme="dark"] .upcoming-name,
        html[data-theme="dark"] .quick-action strong,
        html[data-theme="dark"] .stat-value,
        html[data-theme="dark"] .hero-feature strong,
        html[data-theme="dark"] .track-step.done .track-label,
        html[data-theme="dark"] .track-step.current .track-label { color: #e8eef7; }

        html[data-theme="dark"] .hero-subtitle,
        html[data-theme="dark"] .section-subtitle,
        html[data-theme="dark"] .why-desc,
        html[data-theme="dark"] .step-desc,
        html[data-theme="dark"] .service-desc,
        html[data-theme="dark"] .feature-card p,
        html[data-theme="dark"] .track-card-head .t-sub,
        html[data-theme="dark"] .reminder-sub,
        html[data-theme="dark"] .rc-label,
        html[data-theme="dark"] .rc-sub,
        html[data-theme="dark"] .track-label,
        html[data-theme="dark"] .h-bar-value,
        html[data-theme="dark"] .health-donut-label,
        html[data-theme="dark"] .dash-greeting p,
        html[data-theme="dark"] .upcoming-meta,
        html[data-theme="dark"] .quick-action,
        html[data-theme="dark"] .stat-label,
        html[data-theme="dark"] .hero-feature small { color: #93a4c0; }

        html[data-theme="dark"] .section-eyebrow,
        html[data-theme="dark"] .step-title .s-num { color: #7aa5ff; }
        html[data-theme="dark"] .feature-intro .section-eyebrow { color: #93a4c0; }
        html[data-theme="dark"] .hero-eyebrow { background: rgba(246, 198, 20, 0.14); color: var(--mf-yellow); }

        html[data-theme="dark"] .feature-card,
        html[data-theme="dark"] .service-card,
        html[data-theme="dark"] .track-card,
        html[data-theme="dark"] .reminder-card,
        html[data-theme="dark"] .stat-card,
        html[data-theme="dark"] .dash-panel {
            background: #131c31;
            border-color: #22304a;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35);
        }
        html[data-theme="dark"] .service-meta,
        html[data-theme="dark"] .track-card-head { border-color: #22304a; }
        html[data-theme="dark"] .track-pill { background: rgba(246, 198, 20, 0.14); color: var(--mf-yellow); }

        html[data-theme="dark"] .why-icon,
        html[data-theme="dark"] .step-icon.blue,
        html[data-theme="dark"] .h-bar-icon,
        html[data-theme="dark"] .track-card-head .t-icon,
        html[data-theme="dark"] .reminder-card .rc-icon.blue,
        html[data-theme="dark"] .stat-icon,
        html[data-theme="dark"] .upcoming-icon,
        html[data-theme="dark"] .quick-action i { color: #7aa5ff; }

        html[data-theme="dark"] .dashboard-preview { background: #131c31; box-shadow: 0 26px 70px rgba(0, 0, 0, 0.55); }
        html[data-theme="dark"] .dashboard-main { background: #0d1526; }
        html[data-theme="dark"] .dashboard-sidebar { background: #0a1428; }
        html[data-theme="dark"] .status-pill.scheduled { background: rgba(251, 191, 36, 0.16); color: #fbbf24; }
        html[data-theme="dark"] .status-pill.upcoming { background: rgba(59, 130, 246, 0.16); color: #93c5fd; }

        html[data-theme="dark"] .health-donut { background: conic-gradient(var(--mev-green) 0 92%, #22304a 92% 100%); }
        html[data-theme="dark"] .health-donut-inner { background: #131c31; }
        html[data-theme="dark"] .h-bar-track { background: #22304a; }
        html[data-theme="dark"] .track-step::before { background: #22304a; }
        html[data-theme="dark"] .track-step.done::before,
        html[data-theme="dark"] .track-step.current::before { background: var(--mev-green); }
        html[data-theme="dark"] .step-item:not(:last-child)::after { border-top-color: #2a3a58; }
        html[data-theme="dark"] .health-bike-wrap::before { border-color: rgba(122, 165, 255, 0.3); }

        html[data-theme="dark"] .btn-cta-outline { color: #dbe4f0; }
        html[data-theme="dark"] .btn-cta-outline:hover { color: #93b4ff; background: transparent; }
        html[data-theme="dark"] .btn-cta-outline-yellow { background: #131c31; color: var(--mf-yellow); }
        html[data-theme="dark"] .btn-cta-outline-yellow:hover { background: var(--mf-yellow); color: var(--mf-navy); }
        html[data-theme="dark"] .service-book { color: var(--mf-yellow); }

        html[data-theme="dark"] .modal-blur-effect .modal-content { background: #131c31; color: #dbe4f0; }
        html[data-theme="dark"] .auth-shell,
        html[data-theme="dark"] .auth-panel { background: #131c31; }
        html[data-theme="dark"] .auth-panel .auth-heading h4,
        html[data-theme="dark"] .auth-brand-name { color: #f1f5f9; }
        html[data-theme="dark"] .auth-panel .auth-heading p,
        html[data-theme="dark"] .pw-hint { color: #93a4c0; }
        html[data-theme="dark"] .auth-panel .auth-field > label { color: #cbd5e1; }
        html[data-theme="dark"] .modal-blur-effect .input-group { background: #0d1526; border-color: #22304a; }
        html[data-theme="dark"] .modal-blur-effect .input-group:focus-within {
            background: #0d1526;
            border-color: #7aa5ff;
            box-shadow: 0 0 0 4px rgba(122, 165, 255, 0.12);
        }
        html[data-theme="dark"] .modal-blur-effect .input-group .form-control { color: #e8eef7; }
        html[data-theme="dark"] .modal-blur-effect .input-group .form-control::placeholder { color: #5b6b85; }
        html[data-theme="dark"] .modal-blur-effect .input-icon { color: #7aa5ff; }
        html[data-theme="dark"] .auth-panel .auth-switch { background: #0d1526; border-color: #22304a; color: #93a4c0; }
        html[data-theme="dark"] .auth-panel .auth-switch a,
        html[data-theme="dark"] .modal-blur-effect .forgot-link { color: #93b4ff; }
        html[data-theme="dark"] .auth-panel .auth-switch a:hover,
        html[data-theme="dark"] .modal-blur-effect .forgot-link:hover { color: #b8ccff; }
        html[data-theme="dark"] .auth-secure { color: #5b6b85; }
        html[data-theme="dark"] .modal-blur-effect .form-check-input { background-color: #0d1526; border-color: #2a3a58; }
        html[data-theme="dark"] .modal-blur-effect .form-check-label { color: #93a4c0; }
        html[data-theme="dark"] .modal-blur-effect .modal-close { background-color: #22304a; border-color: #2a3a58; filter: invert(1); }
        html[data-theme="dark"] .modal-blur-effect .modal-close:hover { background-color: #2a3a58; }
        html[data-theme="dark"] .pw-meter { background: #22304a; }
        html[data-theme="dark"] .modal-blur-effect .alert-warning { background: #3a2f10; border-color: #6b5518; color: #fde68a; }
        html[data-theme="dark"] .modal-blur-effect .alert-success { background: #0d3325; border-color: #14532d; color: #a7f3d0; }
        html[data-theme="dark"] .text-brand { color: var(--mf-yellow); }

        @media (max-width: 991px) {
            html[data-theme="dark"] .navbar-collapse { background: #111a2e; border-color: #22304a; }
        }
    </style>
</head>
<?php 
// Show progressive delay overlay if there are failed attempts
$delay_info = check_login_delay();
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
