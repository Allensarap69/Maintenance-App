<?php
session_start();
require 'db.php';
$msg = "";
$registration_success = false;

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    $user_role = $_SESSION['role'] ?? 'customer';
    $dashboard_file = ($user_role == 'admin') ? 'dashboard_admin.php' : 'dashboard_customer.php';
    header("Location: " . $dashboard_file);
    exit;
}

// Variables to hold submitted data in case of an error
$username_val = $_POST['username'] ?? '';
$email_val    = $_POST['email'] ?? '';
$phone_val    = $_POST['phone'] ?? '';
$address_val  = $_POST['address'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $email    = $_POST['email'];
    
    // Check for empty fields before proceeding
    if (empty($_POST['password']) || empty($username) || empty($email) || empty($_POST['phone']) || empty($_POST['address'])) {
        $msg = "❌ Please fill in all required fields.";
    } else {
        // Use secure password hashing (bcrypt) - never use MD5!
        $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
        $phone    = preg_replace('/\D/', '', $_POST['phone']); // digits only
        $address  = htmlspecialchars(trim($_POST['address']), ENT_QUOTES, 'UTF-8');

        if (!preg_match('/^09\d{9}$/', $phone)) {
            $msg = "❌ Please enter a valid 11-digit mobile number starting with 09 (e.g., 0917 123 4567).";
        } else {
        try {
            // Check if email is already registered
            $check = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $check->execute([$email]);
            
            if ($check->rowCount() > 0) {
                $msg = "❌ Email already registered. Please use a different email or login.";
            } else {
                // Proceed with registration
                $stmt = $pdo->prepare("INSERT INTO users (username,email,password,phone,address,role) VALUES (?,?,?,?,?,'customer')");
                $stmt->execute([$username, $email, $password, $phone, $address]);
                
                $msg = "✅ Registration successful! You can now login.";
                $registration_success = true;

                // Clear input values on success
                $username_val = $email_val = $phone_val = $address_val = '';
            }
        } catch (PDOException $e) {
            $msg = "A database error occurred during registration. Please try again.";
        }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up | AutoCare Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="fonts.css">
    <style>
        /* --- Modern Color Palette & Variables --- */
        :root {
            --primary-color: #0f172a;
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
            --accent-color: #f59e0b;
            --accent-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --bg-light: #f8fafc;
            --bg-dark: #0f172a;
            --text-dark: #1e293b;
            --text-light: #64748b;
            --white: #ffffff;
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
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
        }

        /* --- Modern Navigation --- */
        .navbar-custom {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.1);
            z-index: 1030;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
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
            margin-right: 8px;
            color: transparent;
        }

        .nav-link {
            font-weight: 500;
            color: var(--text-dark) !important;
            
            position: relative;
        }

        .nav-link::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            width: 0;
            height: 2px;
            background: var(--accent-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            transform: translateX(-50%);
        }

        .nav-link:hover::after {
            width: 80%;
        }

        .btn-main {
            background: var(--primary-gradient);
            border: none;
            color: white;
            font-weight: 600;
            padding: 10px 24px;
            border-radius: 50px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.3);
        }

        .btn-main:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(15, 23, 42, 0.4);
            color: white;
        }

        /* --- Animated Hero Section --- */
        .hero-section {
            min-height: 100vh;
            background: var(--primary-gradient);
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: white;
            overflow: hidden;
            padding-top: 80px;
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
        }

        /* Floating decorative elements */
        .floating-icon {
            position: absolute;
            opacity: 0.1;
            animation: float 6s ease-in-out infinite;
            color: white;
        }

        .floating-icon:nth-child(1) {
            top: 20%;
            left: 10%;
            font-size: 4rem;
            animation-delay: 0s;
        }

        .floating-icon:nth-child(2) {
            top: 60%;
            right: 10%;
            font-size: 3rem;
            animation-delay: 2s;
        }

        .floating-icon:nth-child(3) {
            bottom: 20%;
            left: 15%;
            font-size: 3.5rem;
            animation-delay: 4s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(5deg); }
        }

        /* --- Register Card --- */
        .register-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 500px;
            padding: 20px;
        }

        .register-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px 35px;
            position: relative;
            overflow: hidden;
        }

        .register-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: var(--accent-gradient);
        }

        .register-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .register-icon {
            width: 70px;
            height: 70px;
            background: var(--primary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.3);
        }

        .register-icon i {
            font-size: 2rem;
            color: white;
        }

        .register-card h2 {
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 10px;
            font-size: 1.6rem;
        }

        .register-subtitle {
            color: var(--text-light);
            font-size: 0.95rem;
        }

        /* --- Form Styling --- */
        .form-floating {
            position: relative;
        }

        .form-floating > .form-control {
            height: 58px;
            padding: 1rem 1rem 0.5rem 3rem;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            background-color: #f8fafc;
            font-size: 0.95rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        }

        .form-floating > .form-control:focus {
            border-color: var(--accent-color);
            background-color: white;
            box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.1);
        }

        .form-floating > label {
            padding: 1rem 1rem 0.5rem 3rem;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .form-floating > .form-control:focus ~ label,
        .form-floating > .form-control:not(:placeholder-shown) ~ label {
            padding: 0.5rem 1rem 0 3rem;
            font-size: 0.75rem;
            color: var(--accent-color);
            font-weight: 500;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 1.1rem;
            z-index: 10;
            
        }

        .form-floating > .form-control:focus ~ .input-icon {
            color: var(--accent-color);
        }

        textarea.form-control {
            min-height: 80px !important;
            padding-top: 1.5rem !important;
        }

        /* --- Button Styling --- */
        .btn-register {
            background: var(--accent-gradient);
            border: none;
            color: white;
            font-weight: 600;
            padding: 14px 30px;
            border-radius: 50px;
            font-size: 1rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
            width: 100%;
        }

        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.5);
            color: white;
        }

        .btn-register i {
            margin-right: 8px;
        }

        /* --- Alert Styling --- */
        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 25px;
        }

        .alert-success-custom {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
            border-left: 4px solid #10b981;
        }

        .alert-danger-custom {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
            border-left: 4px solid #ef4444;
        }

        /* --- Links --- */
        .login-link {
            color: var(--text-light);
            text-decoration: none;
            font-weight: 500;
            
        }

        .login-link:hover {
            color: var(--accent-color);
        }

        .login-link strong {
            color: var(--accent-color);
        }

        /* --- Responsive --- */
        @media (max-width: 576px) {
            .register-container {
                padding: 12px;
            }

            .register-card {
                padding: 30px 20px;
            }

            .form-floating > .form-control {
                font-size: 16px;
            }

            .navbar-collapse {
                background: rgba(255, 255, 255, 0.98);
                padding: 12px 16px;
                border-radius: 12px;
                margin-top: 10px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            }

            .navbar-nav .btn-main {
                margin-left: 0 !important;
                margin-top: 8px;
                justify-content: center;
            }

            .register-card h2 {
                font-size: 1.3rem;
            }

            .register-icon {
                width: 60px;
                height: 60px;
            }

            .register-icon i {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg fixed-top navbar-custom">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <i class="bi bi-gear-fill"></i> AutoCare Pro
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="index.php#services">Services</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="index.php#about">About</a>
                    </li>
                    <li class="nav-item">
                        <a href="index.php" class="btn btn-main btn-sm ms-lg-3 d-flex align-items-center">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Login
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section with Register Form -->
    <section class="hero-section">
        <i class="bi bi-car-front floating-icon"></i>
        <i class="bi bi-tools floating-icon"></i>
        <i class="bi bi-gear floating-icon"></i>

        <div class="register-container">
            <div class="register-card">
                <!-- Header -->
                <div class="register-header">
                    <div class="register-icon">
                        <i class="bi bi-person-plus-fill"></i>
                    </div>
                    <h2>Create Your Account</h2>
                    <p class="register-subtitle">Join AutoCare Pro and book your car service easily</p>
                </div>

                <!-- Alert Messages -->
                <?php if ($msg): ?>
                    <div class="alert alert-custom <?= $registration_success ? 'alert-success-custom' : 'alert-danger-custom' ?> alert-dismissible fade show" role="alert">
                        <div class="d-flex align-items-center">
                            <?php if ($registration_success): ?>
                                <i class="bi bi-check-circle-fill me-2 fs-5"></i>
                            <?php else: ?>
                                <i class="bi bi-exclamation-circle-fill me-2 fs-5"></i>
                            <?php endif; ?>
                            <div>
                                <?= htmlspecialchars($msg) ?>
                                <?php if ($registration_success): ?>
                                    <br><a href="index.php" class="login-link"><strong>Click here to Login →</strong></a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Registration Form -->
                <form method="post" action="register.php">
                    
                    <!-- Username -->
                    <div class="form-floating mb-3">
                        <i class="bi bi-person input-icon"></i>
                        <input type="text" class="form-control" id="username" name="username" placeholder="Full Name" required value="<?= htmlspecialchars($username_val) ?>">
                        <label for="username">Full Name</label>
                    </div>

                    <!-- Email -->
                    <div class="form-floating mb-3">
                        <i class="bi bi-envelope input-icon"></i>
                        <input type="email" class="form-control" id="email" name="email" placeholder="Email Address" required value="<?= htmlspecialchars($email_val) ?>">
                        <label for="email">Email Address</label>
                    </div>

                    <!-- Password -->
                    <div class="form-floating mb-3">
                        <i class="bi bi-lock input-icon"></i>
                        <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                        <label for="password">Password (Min. 6 characters)</label>
                    </div>

                    <!-- Phone -->
                    <div class="form-floating mb-3">
                        <i class="bi bi-telephone input-icon"></i>
                        <input type="tel" class="form-control" id="phone" name="phone" placeholder="Phone Number" required value="<?= htmlspecialchars($phone_val) ?>" inputmode="numeric" maxlength="13" pattern="09[0-9]{2} [0-9]{3} [0-9]{4}" title="11-digit mobile number starting with 09 (e.g., 0917 123 4567)" oninput="formatPhoneNumber(this)">
                        <label for="phone">Phone Number</label>
                    </div>

                    <!-- Address -->
                    <div class="form-floating mb-4">
                        <i class="bi bi-geo-alt input-icon" style="top: 25px;"></i>
                        <textarea class="form-control" id="address" name="address" placeholder="Full Address" required style="height: 80px;"><?= htmlspecialchars($address_val) ?></textarea>
                        <label for="address">Full Address (Street, Barangay, City)</label>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="btn btn-register mb-3">
                        <i class="bi bi-person-check-fill"></i> Create Account
                    </button>

                    <!-- Login Link -->
                    <p class="text-center mb-0" style="color: var(--text-light);">
                        Already have an account? 
                        <a href="index.php" class="login-link"><strong>Login here</strong></a>
                    </p>

                </form>
            </div>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
    </script>
</body>
</html>