<?php
/**
 * Customer Template - Include this at the top of customer pages for consistent styling
 * Usage: include 'customer_template.php';
 */
if (!isset($page_title)) $page_title = 'AutoCare Pro';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> | AutoCare Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="fonts.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* ============================================
           CUSTOMER PORTAL - MODERN DESIGN SYSTEM
           Based on index.php style
           ============================================ */
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
            --success: #10b981;
            --warning: #f59e0b;
            --info: #3b82f6;
            --danger: #ef4444;
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
            color: var(--text-dark);
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

        /* ============================================
           HERO SECTION WITH FLOATING ICONS
           ============================================ */
        .hero-section {
            background: var(--primary-gradient);
            position: relative;
            padding: 80px 0 100px;
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
                radial-gradient(circle at 20% 80%, rgba(245, 158, 11, 0.15) 0%, transparent 50%),
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
            color: rgba(255, 255, 255, 0.8);
        }

        /* --- Floating Icons --- */
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

        /* ============================================
           CARDS & COMPONENTS
           ============================================ */
        .content-section {
            padding: 60px 0;
        }

        .modern-card {
            background: white;
            border-radius: 20px;
            border: none;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            transition: transform 0.4s ease, box-shadow 0.4s ease, opacity 0.4s ease, visibility 0.4s ease, max-height 0.4s ease;
            overflow: hidden;
        }

        .modern-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12);
        }

        .card-header-gradient {
            background: var(--primary-gradient);
            color: white;
            padding: 20px 25px;
            border-bottom: none;
        }

        .card-header-accent {
            background: var(--accent-gradient);
            color: white;
            padding: 20px 25px;
            border-bottom: none;
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
        .stat-card.warning .stat-icon-wrapper { background: rgba(245, 158, 11, 0.1); color: var(--accent-color); }
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
        }

        /* ============================================
           BUTTONS
           ============================================ */
        .btn-main {
            background: var(--primary-gradient);
            border: none;
            color: white;
            font-weight: 600;
            padding: 12px 30px;
            border-radius: 50px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.3);
        }

        .btn-main:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(15, 23, 42, 0.4);
            color: white;
        }

        .btn-accent {
            background: var(--accent-gradient);
            border: none;
            color: white;
            font-weight: 600;
            padding: 12px 30px;
            border-radius: 50px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
        }

        .btn-accent:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.5);
            color: white;
        }

        .btn-outline-custom {
            background: transparent;
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
            font-weight: 600;
            padding: 10px 25px;
            border-radius: 50px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        }

        .btn-outline-custom:hover {
            background: var(--primary-gradient);
            color: white;
        }

        /* ============================================
           FORMS
           ============================================ */
        .form-control-modern {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 0.95rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        }

        .form-control-modern:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.1);
        }

        .form-label-modern {
            font-weight: 500;
            color: var(--text-dark);
            margin-bottom: 8px;
        }

        /* ============================================
           ALERTS & BADGES
           ============================================ */
        .alert-modern {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
        }

        .badge-modern {
            padding: 8px 16px;
            border-radius: 50px;
            font-weight: 500;
            font-size: 0.85rem;
        }

        /* ============================================
           FOOTER
           ============================================ */
        .footer-custom {
            background: var(--primary-color);
            color: rgba(255, 255, 255, 0.7);
            padding: 40px 0 30px;
            font-size: 0.9rem;
        }

        .footer-custom h5 {
            color: white;
            margin-bottom: 20px;
            font-weight: 600;
        }

        .footer-custom a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            
        }

        .footer-custom a:hover {
            color: var(--accent-color);
        }

        /* ============================================
           ANIMATIONS
           ============================================ */
        .fade-in-up {
            animation: fadeInUp 0.8s ease both;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .reveal {
            opacity: 0;
            transform: translateY(30px);
            transition: transform 0.8s ease, box-shadow 0.8s ease, opacity 0.8s ease, visibility 0.8s ease, max-height 0.8s ease;
        }

        .reveal.active {
            opacity: 1;
            transform: translateY(0);
        }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 768px) {
            .hero-section { padding: 60px 0 80px; }
            .content-section { padding: 40px 0; }
            .stat-card { padding: 20px; }
            .stat-value { font-size: 2rem; }
            .floating-icon { font-size: 1.5rem !important; }
        }
    </style>
</head>
<body>
</body>
</html>
