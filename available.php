<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['username'] ?? 'Customer';
$active_page = 'book_service.php';

// 1. Fetch available slots from the master availability table
$stmt = $pdo->query("
    SELECT 
        id, 
        date, 
        start_time, 
        end_time
    FROM availability 
    WHERE status='available' AND date >= CURDATE() 
    ORDER BY date, start_time
");
$slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. FIX APPLIED HERE: Fetch all booked slots to identify occupied time, including 'accepted' status
$stmt_bookings = $pdo->prepare("
    SELECT schedule_date, schedule_start_time, schedule_end_time 
    FROM bookings 
    WHERE status IN ('confirmed', 'pending', 'deposit_submitted', 'accepted') 
    AND schedule_date >= CURDATE() 
    ORDER BY schedule_date, schedule_start_time
");
$stmt_bookings->execute();
$raw_bookings = $stmt_bookings->fetchAll(PDO::FETCH_ASSOC);

// Transform booking data keys for client-side consumption
$bookings = [];
foreach ($raw_bookings as $b) {
    $bookings[] = [
        'date' => $b['schedule_date'],
        'start_time' => $b['schedule_start_time'],
        'end_time' => $b['schedule_end_time'],
    ];
}

// Retrieve required duration (in minutes)
$required_duration = intval($_SESSION['temp_duration'] ?? ($_GET['duration'] ?? 30)); 

// --- PHP FUNCTION TO CONVERT MINUTES TO HOURS/MINS ---
function convertMinutesToHoursMins($minutes) {
    if ($minutes <= 0) {
        return "0 minutes";
    }
    $hours = floor($minutes / 60);
    $remaining_minutes = $minutes % 60;
    
    $output = [];
    if ($hours > 0) {
        $output[] = $hours . " hr" . ($hours > 1 ? "s" : "");
    }
    if ($remaining_minutes > 0) {
        $output[] = $remaining_minutes . " min" . ($remaining_minutes > 1 ? "s" : "");
    }
    
    return implode(" ", $output);
}

$required_duration_human_readable = convertMinutesToHoursMins($required_duration);
// --- END PHP FUNCTION ---
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
    <title>Select Appointment Time</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <link rel="stylesheet" href="fonts.css">
    <style>
        /* ============================================
           MODERN CUSTOMER PORTAL - INDEX.PHP STYLE
           ============================================ */
        :root {
            --primary-color: #172A46;
            --primary-gradient: linear-gradient(135deg, #172A46 0%, #1e3a5f 100%);
            --accent-color: #F97316;
            --accent-gradient: linear-gradient(135deg, #F97316 0%, #ea580c 100%);
            --secondary-color: #10b981;
            --bg-light: #F8FAFC;
            --bg-dark: #172A46;
            --text-dark: #172033;
            --text-light: #64748B;
            --white: #ffffff;
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --border-color: #E2E8F0;
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
            padding-top: 0;
        }

        /* ============================================
           HERO SECTION - COMPACT & CLEAN
           ============================================ */
        /* ============================================
           IN-PAGE STEPPER
           ============================================ */
        .page-stepper {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 32px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            padding: 16px 0;
        }

        .page-stepper .step-item {
            display: flex;
            align-items: center;
            gap: 8px;
            opacity: 0.4;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        }

        .page-stepper .step-item.active {
            opacity: 1;
        }

        .page-stepper .step-item i {
            font-size: 1.3rem;
            color: var(--accent-color);
        }

        .page-stepper .step-item span {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-dark);
        }

        .page-stepper .step-item.active span {
            color: var(--accent-color);
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .page-stepper {
                gap: 16px;
                padding: 12px 0;
                margin-bottom: 16px;
            }
            .page-stepper .step-item i {
                font-size: 1.1rem;
            }
            .page-stepper .step-item span {
                font-size: 0.8rem;
            }
        }



        @media (max-width: 768px) {
            body {
                padding-top: 0;
            }
            
            .navbar-custom {
                padding: 8px 0;
            }
            
            .navbar-brand {
                font-size: 1.15rem;
            }
            
            .btn-main {
                padding: 8px 16px;
                font-size: 0.85rem;
            }
            
            .fc .fc-daygrid-day-frame {
                min-height: 70px;
            }
            
            .fc .fc-daygrid-day-number {
                padding: 8px;
                font-size: 0.95rem;
            }
            
            #timeSlots {
                max-height: 350px;
            }
            
            .time-slot-card {
                padding: 12px;
                margin-bottom: 12px;
            }
            
            .service-time-display {
                font-size: 1.1rem;
            }
            
            .slot-panel-header {
                padding: 12px 16px;
                font-size: 1rem;
            }
        }

        @media (max-width: 576px) {
            body {
                padding-top: 0;
            }
            
            .container {
                padding: 0 10px;
            }
            
            .navbar-custom {
                padding: 6px 0;
            }
            
            .navbar-brand {
                font-size: 1.1rem;
            }
            
            .btn-main {
                padding: 6px 14px;
                font-size: 0.8rem;
            }
            
            .page-stepper {
                gap: 12px;
                padding: 10px 0;
                margin-bottom: 12px;
            }
            
            .page-stepper .step-item i {
                font-size: 1rem;
            }
            
            .page-stepper .step-item span {
                font-size: 0.75rem;
            }
            
            .fc .fc-view-harness {
                min-height: 350px !important;
            }
            
            .fc .fc-daygrid-day-frame {
                min-height: 60px;
            }
            
            #timeSlots {
                max-height: 300px;
            }
        }

        /* Ensure no horizontal overflow */
        html, body {
            max-width: 100%;
            overflow-x: hidden;
        }

        .container {
            max-width: 100%;
            padding-left: 15px;
            padding-right: 15px;
        }

        @media (min-width: 576px) {
            .container {
                max-width: 540px;
            }
        }

        @media (min-width: 768px) {
            .container {
                max-width: 720px;
            }
        }

        @media (min-width: 992px) {
            .container {
                max-width: 960px;
            }
        }

        @media (min-width: 1200px) {
            .container {
                max-width: 1140px;
            }
        }

        @media (min-width: 1400px) {
            .container {
                max-width: 1320px;
            }
        }

        /* Prevent content overflow in cards */
        .main-card {
            overflow: hidden;
        }

        #timeSlots {
            overflow-x: hidden;
        }

        .fc-day-past .fc-daygrid-day-number {
            color: #adb5bd !important; 
            background-color: transparent !important;
        }

        /* FullCalendar Styles */
        .fc {
            font-family: 'Poppins', sans-serif;
        }

        .fc .fc-daygrid-day-frame {
            min-height: 100px;
        }

        .fc .fc-daygrid-day-number {
            padding: 10px;
            font-size: 1.1rem;
        }

        .fc-daygrid-day-top {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .fc .fc-view-harness {
            min-height: 450px !important;
        }

        .fc-toolbar-title { 
            color: var(--primary-color); 
            font-weight: 700; 
            font-size: 1.3rem;
        }

        .fc-prev-button, .fc-next-button {
            background-color: #ffffff !important;
            border-color: var(--border-color) !important;
            color: #172A46 !important;
            opacity: 0.9;
            padding: 8px 16px;
            font-size: 1rem;
        }

        .fc-prev-button:hover, .fc-next-button:hover {
            opacity: 1;
            background-color: #FACC15 !important;
            border-color: #FACC15 !important;
        }

        .fc .fc-button-group .fc-button,
        .fc .fc-button-group .fc-button-primary {
            background-color: #ffffff !important;
            border-color: var(--border-color) !important;
            color: #172A46 !important;
        }
        .fc .fc-button-group .fc-button:hover,
        .fc .fc-button-group .fc-button-primary:hover {
            background-color: #FACC15 !important;
            border-color: #FACC15 !important;
            color: #172A46 !important;
        }
        .fc .fc-button-group .fc-button-active,
        .fc .fc-button-group .fc-button-primary:not(:disabled):active,
        .fc .fc-button-group .fc-button-primary:not(:disabled).fc-button-active {
            background-color: #FACC15 !important;
            border-color: #FACC15 !important;
            color: #172A46 !important;
        }

        /* Available Days - Green */
        .fc-day-available .fc-daygrid-day-number {
            background-color: var(--secondary-color);
            color: white !important;
            font-weight: 600;
            padding: 8px;
            border-radius: 8px;
            
            font-size: 1.1rem;
        }
        .fc-day-available:hover .fc-daygrid-day-number {
            background-color: #059669;
            cursor: pointer;
        }

        /* Selected Day - Dark Blue */
        .selected-date .fc-daygrid-day-number {
            background-color: var(--primary-color) !important;
            color: white !important;
            box-shadow: 0 0 0 3px var(--accent-color), 0 2px 5px rgba(0, 0, 0, 0.2);
            transform: scale(1.05);
        }

        .fc-day-unavailable, .fc-day-past {
            cursor: not-allowed;
            background-color: #f8f9fa; 
        }
        .fc-day-unavailable .fc-daygrid-day-number,
        .fc-day-past .fc-daygrid-day-number {
            color: #adb5bd !important; 
            background-color: transparent !important;
        }

        /* -------------------------------------- */
        /* --- TIME SLOTS STYLES --- */
        /* -------------------------------------- */

        #timeSlots {
            max-height: 500px; 
            overflow-y: auto; 
            padding: 0; 
            background-color: #fcfcfc;
            border-left: 1px solid var(--border-color);
        }

        .slot-panel-header {
            background: #ffffff;
            color: #172A46;
            padding: 16px 20px;
            font-weight: 600;
            border-bottom: 1px solid var(--border-color);
            position: sticky; 
            top: 0;
            z-index: 10;
            font-size: 1.1rem;
        }

        .time-slot-container-inner {
            padding: 20px;
        }

        .time-slot-card {
            border: 1px solid var(--border-color);
            background-color: #fff;
            padding: 16px;
            margin-bottom: 16px;
            border-radius: 8px;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
        }

        .time-slot-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border-left: 4px solid var(--secondary-color);
            transform: translateY(-2px);
        }

        .service-time-display {
            color: var(--primary-color);
            font-size: 1.3rem;
            font-weight: 700;
        }

        .remaining-time-display {
            color: var(--primary-color);
            font-weight: 600;
            font-size: 1rem;
        }

        .book-slot-btn {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
            font-weight: 600;
            color: white; 
            padding: 10px 20px;
            font-size: 1rem;
        }

        .book-slot-btn:hover {
            background-color: #059669;
            border-color: #059669;
        }

        .back-btn {
            background-color: #172A46;
            border: 1px solid #172A46;
            color: #ffffff;
            font-weight: 600;
            padding: 6px 12px;
            font-size: 0.85rem;
        }
        .back-btn:hover {
            background-color: #0f172a;
            border-color: #0f172a;
            color: #ffffff;
        }

        .initial-message {
            padding: 24px;
            margin: 20px;
            border: 2px dashed var(--secondary-color);
            background-color: #ecfdf5;
            color: var(--primary-color);
            border-radius: 8px;
            font-size: 1rem;
        }

        /* ============================================
           NAVIGATION
           ============================================ */
        .navbar-custom {
            background: linear-gradient(135deg, #1e3a5f 0%, #0f172a 50%, #1e293b 100%);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            z-index: 1050;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            padding: 12px 0;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            border-bottom: 2px solid rgba(245, 158, 11, 0.3);
        }

        .navbar-custom::before {
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

        .navbar-brand {
            font-weight: 700;
            font-size: 1.3rem;
            color: white;
            text-decoration: none;
        }

        .navbar-brand i {
            color: var(--accent-color);
        }

        .btn-main {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 8px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
            font-size: 0.95rem;
        }

        .btn-main:hover {
            background: var(--accent-color);
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);
            border-color: var(--accent-color);
        }

        /* ============================================
           CARDS
           ============================================ */
        .main-card {
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--border-color);
            overflow: hidden;
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

        /* --- COMPACT / MINIMIZE --- */
        .page-stepper { gap: 20px; margin-bottom: 16px; padding: 10px 0; }
        .page-stepper .step-item i { font-size: 1.1rem; }
        .page-stepper .step-item span { font-size: 0.8rem; }
        .fc .fc-daygrid-day-frame { min-height: 70px; }
        .fc .fc-daygrid-day-number { padding: 8px; font-size: 0.9rem; }
        .fc .fc-view-harness { min-height: 350px !important; }
        .fc-toolbar-title { font-size: 1rem; }
        .fc-prev-button, .fc-next-button { padding: 6px 12px; font-size: 0.85rem; }
        .fc-day-available .fc-daygrid-day-number { padding: 6px; font-size: 0.9rem; }
        .slot-panel-header { padding: 12px 16px; font-size: 0.95rem; }
        .time-slot-container-inner { padding: 15px; }
        .time-slot-card { padding: 12px; margin-bottom: 12px; }
        .service-time-display { font-size: 1.05rem; }
        .remaining-time-display { font-size: 0.85rem; }
        .book-slot-btn { padding: 8px 16px; font-size: 0.85rem; }
        .initial-message { padding: 16px; margin: 12px; font-size: 0.9rem; }
        .btn { font-size: 0.85rem; padding: 0.5rem 1rem; }
        .table { font-size: 0.75rem; }
        .table th, .table td { padding: 0.4rem; }

        /* --- Dark mode --- */
        html[data-theme="dark"] .service-time-display { color: #e2e8f0; }
        html[data-theme="dark"] .remaining-time-display { color: #e2e8f0; }
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
            <h1 class="top-bar-title">Select Appointment Time</h1>
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
                    <i class="bi bi-person"></i> Profile
                </a>
                <a href="logout.php" class="dropdown-item danger">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </div>

    <!-- Content Area -->
    <div class="content-area">
        <div class="container">
    
    <!-- In-Page Stepper -->
    <div class="page-stepper">
        <div class="step-item">
            <i class="bi bi-wrench"></i>
            <span>Service & Schedule</span>
        </div>
        <div class="step-item active">
            <i class="bi bi-calendar-check"></i>
            <span>Choose Time</span>
        </div>
        <div class="step-item">
            <i class="bi bi-person-badge"></i>
            <span>Choose Mechanic</span>
        </div>
        <div class="step-item">
            <i class="bi bi-calendar-check"></i>
            <span>Review</span>
        </div>
        <div class="step-item">
            <i class="bi bi-credit-card"></i>
            <span>Payment</span>
        </div>
    </div>

    <div class="card main-card">
        <div class="row g-0">
            <div class="col-md-7" id="calendar-container" style="padding: 20px;">
                <div id="calendar"></div>
                <div class="mt-3">
                    <a href="book_service.php" class="btn back-btn" style="padding: 4px 10px; font-size: 0.8rem;">
                        <i class="bi bi-arrow-left me-1"></i> Back
                    </a>
                </div>
            </div>

            <div class="col-md-5">
                <div id="timeSlots">
                    </div>
            </div>
        </div>
        </div>
    </div>
</div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const slots = <?= json_encode($slots) ?>;
    const bookings = <?= json_encode($bookings) ?>; // New: Pass booked slots
    const calendarEl = document.getElementById("calendar");
    const timeSlotsDiv = document.getElementById("timeSlots");
    let selectedDayElement = null; 
    const requiredDuration = parseInt(<?= $required_duration ?>); 
    const SLOT_INTERVAL = 30; // Minutes: services can start every 30 minutes

    const requiredDurationHumanReadable = "<?= $required_duration_human_readable ?>";
    
    // --- JS Helper Functions ---

    // Redefine PHP function in JS for client-side use
    function convertMinutesToHoursMins(minutes) {
        if (minutes <= 0) {
            return "0 minutes";
        }
        const hours = Math.floor(minutes / 60);
        const remaining_minutes = minutes % 60;
        
        let output = [];
        if (hours > 0) {
            output.push(hours + " hr" + (hours > 1 ? "s" : ""));
        }
        if (remaining_minutes > 0) {
            output.push(remaining_minutes + " min" + (remaining_minutes > 1 ? "s" : ""));
        }
        
        return output.join(" ");
    }


    // Initial content for the Time Slots panel
    const initialMessageHtml = `
        <div class="time-slot-container-inner">
            <div class="initial-message text-center" role="alert">
                <i class="bi bi-hand-index-thumb me-1 fs-4"></i>
                <h5 class="mt-2 mb-2">Time to Schedule!</h5>
                <p class="mb-2">Your total service requires <span class="fw-bold fs-5">${requiredDurationHumanReadable}</span>.</p>
                <small class="text-muted">Click on a highlighted date (green) on the calendar to see available time slots.</small>
            </div>
        </div>
    `;
    timeSlotsDiv.innerHTML = initialMessageHtml;

    // Map slots by date for quick lookup
    const slotMap = {};
    slots.forEach(s => {
        if (!slotMap[s.date]) slotMap[s.date] = [];
        slotMap[s.date].push(s);
    });

    // Map bookings by date for quick lookup
    const bookingMap = {};
    bookings.forEach(b => {
        if (!bookingMap[b.date]) bookingMap[b.date] = [];
        bookingMap[b.date].push(b);
    });

    const today = new Date();
    const INITIAL_DATE = today.toISOString().split('T')[0]; 
    
    function calculateSlotLength(startTime, endTime) {
        // Create dummy dates on the same day for time calculation
        const start = new Date(`2000/01/01 ${startTime}`);
        const end = new Date(`2000/01/01 ${endTime}`);
        if (end < start) end.setDate(end.getDate() + 1); // Handle midnight cross
        return (end - start) / 60000; // Difference in minutes
    }

    function addMinutesToTime(timeStr, minutes) {
        const date = new Date(`2000/01/01 ${timeStr}`);
        date.setMinutes(date.getMinutes() + minutes);
        
        const hours = date.getHours().toString().padStart(2, '0');
        const mins = date.getMinutes().toString().padStart(2, '0');
        return `${hours}:${mins}:00`; // Ensure consistent H:i:s format
    }
    
    function formatTime(timeStr) {
        let [h, m] = timeStr.split(':');
        let hours = parseInt(h);
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12; 
        const minutes = m.padStart(2, '0');
        return hours + ':' + minutes + ' ' + ampm;
    }
    
    // Core Logic: Function to subtract booked times from available slots to get actual free time windows
    function getFreeTimeWindows(date, availableSlots, bookedSlots) {
        let freeWindows = [];
        if (!availableSlots || availableSlots.length === 0) return [];
        
        // Filter bookings relevant to the date
        const dailyBookings = bookedSlots.filter(b => b.date === date);

        // Sort bookings for correct subtraction order
        dailyBookings.sort((a, b) => a.start_time.localeCompare(b.start_time));
        
        availableSlots.forEach(slot => {
            // Start with the full slot as the initial free segment
            let segments = [{ start_time: slot.start_time, end_time: slot.end_time, id: slot.id }];
            
            for (const booking of dailyBookings) {
                const bookingStart = booking.start_time;
                const bookingEnd = booking.end_time;
                
                let newSegments = [];
                
                for (const segment of segments) {
                    const segStart = segment.start_time;
                    const segEnd = segment.end_time;
                    
                    // 1. Booking completely after or before segment (no overlap)
                    if (bookingStart >= segEnd || bookingEnd <= segStart) {
                        newSegments.push(segment);
                        continue;
                    }
                    
                    // 2. Booking overlaps/is contained within segment (must split)
                    
                    // Segment 1: free time before the booking
                    if (segStart < bookingStart) {
                        newSegments.push({ id: segment.id, start_time: segStart, end_time: bookingStart });
                    }
                    
                    // Segment 2: free time after the booking
                    if (segEnd > bookingEnd) {
                        newSegments.push({ id: segment.id, start_time: bookingEnd, end_time: segEnd });
                    }
                }
                segments = newSegments; // The segments array is updated after each booking is processed
            }
            
            // Finalize segments for the freeWindows array
            segments.forEach(s => {
                // Only include segments long enough for the required duration
                if (calculateSlotLength(s.start_time, s.end_time) >= requiredDuration) {
                    // Attach the original end time for remaining time calculation
                    freeWindows.push({
                        ...s, 
                        original_end_time: slot.end_time,
                    });
                }
            });
        });
        
        // Sort the final free windows chronologically
        freeWindows.sort((a, b) => a.start_time.localeCompare(b.start_time));

        return freeWindows;
    }
    // --- End JS Helper Functions ---


    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialDate: INITIAL_DATE, 
        initialView: "dayGridMonth",
        headerToolbar: { left: "prev,next", center: "title", right: "" },
        fixedWeekCount: true,
        contentHeight: 400,
        aspectRatio: 1.5,
        firstDay: 1, 

        dateClick: function(info) {
            const selectedDate = info.dateStr;
            timeSlotsDiv.innerHTML = ""; // Clear content

            if (selectedDayElement) {
                selectedDayElement.classList.remove('selected-date');
            }
            
            // Get the actual free time windows for the selected date, subtracting bookings
            const dailyAvailableSlots = slotMap[selectedDate] || [];
            const dailyBookings = bookingMap[selectedDate] || [];
            const freeTimeWindows = getFreeTimeWindows(selectedDate, dailyAvailableSlots, dailyBookings);


            // Check if the clicked day is unavailable or past
            if (info.dayEl.classList.contains('fc-day-past') || freeTimeWindows.length === 0) {
                 timeSlotsDiv.innerHTML = `
                     <div class="time-slot-container-inner">
                         <div class="alert alert-danger text-center mt-3" role="alert">
                             <i class="bi bi-slash-circle me-1"></i> No compatible slots on ${selectedDate}.
                         </div>
                     </div>`;
                 return;
            }

            // Highlight the selected day
            info.dayEl.classList.add('selected-date');
            selectedDayElement = info.dayEl;

            // 2. Generate valid start times using the SLOT_INTERVAL from the free windows
            const validStartTimes = [];
            
            // CORRECTED LOOP LOGIC:
            freeTimeWindows.forEach(fw => {
                let currentStartTime = fw.start_time;
                const windowEnd = fw.end_time;
                
                // 1. Find the first valid start time on a 30-minute interval
                let [h, m] = currentStartTime.split(':').map(Number);
                
                // If the minute is not 00 or 30, round up to the next interval
                if (m % SLOT_INTERVAL !== 0) {
                    m = Math.ceil(m / SLOT_INTERVAL) * SLOT_INTERVAL;
                    if (m >= 60) {
                        h += Math.floor(m / 60);
                        m = m % 60;
                    }
                }
                
                // Reconstruct the starting time string
                let currentSlotTime = `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:00`;
                
                // Ensure this calculated start time is still within the free window 
                if (currentSlotTime >= windowEnd) {
                    return; 
                }
                
                // 2. Iterate through all possible start times in this free window
                while (true) {
                    // Check if the remaining length of the segment is enough for the required duration
                    if (calculateSlotLength(currentSlotTime, windowEnd) >= requiredDuration) {
                        
                        const serviceEndTime = addMinutesToTime(currentSlotTime, requiredDuration);
                        
                        // Calculate remaining time in the *current free window segment*
                        const windowEndObj = new Date(`2000/01/01 ${windowEnd}`);
                        const serviceEndObj = new Date(`2000/01/01 ${serviceEndTime}`);

                        // Time from the service end until the end of the free window
                        const remainingMillis = windowEndObj.getTime() - serviceEndObj.getTime();
                        const timeRemaining = Math.max(0, Math.floor(remainingMillis / 60000)); // Remaining minutes
                        
                        validStartTimes.push({
                            slot_id: fw.id, 
                            start_time: currentSlotTime,
                            end_time: serviceEndTime, 
                            time_remaining: convertMinutesToHoursMins(timeRemaining),
                        });

                        // Move to the next possible start time (e.g., in 30 minutes)
                        currentSlotTime = addMinutesToTime(currentSlotTime, SLOT_INTERVAL);
                        
                        // Safety/Loop break: if the next potential start time is at or after the window end, stop.
                        if (currentSlotTime >= windowEnd) {
                            break;
                        }
                    } else {
                        // The remaining part of the free window is too short for the service
                        break;
                    }
                }
            });


            // Sort the generated start times chronologically 
            validStartTimes.sort((a, b) => {
                if (a.start_time < b.start_time) return -1;
                if (a.start_time > b.start_time) return 1;
                return 0;
            });

            // 3. Display the generated valid start times
            if (validStartTimes.length === 0) {
                 timeSlotsDiv.innerHTML = `
                     <div class="time-slot-container-inner">
                         <div class="alert alert-info text-center mt-3" role="alert">
                             <i class="bi bi-info-circle me-1"></i> No ${requiredDurationHumanReadable} slots can be fitted on ${selectedDate}.
                         </div>
                     </div>`;
                 return;
            }

            // Display Header and Time Slots Container
            timeSlotsDiv.innerHTML = `
                     <div class="slot-panel-header">
                         <i class="bi bi-clock me-1"></i> Available Times for: <strong>${selectedDate}</strong>
                         <br><small class="text-muted">Duration: <span class="text-success fw-bold">${requiredDurationHumanReadable}</span></small>
                     </div>
                     <div class="time-slot-container-inner"></div>
                 `;
            
            const timeCardContainer = timeSlotsDiv.querySelector(".time-slot-container-inner");
            
            validStartTimes.forEach(t => {
                const serviceStart = formatTime(t.start_time);
                const serviceEnd = formatTime(t.end_time);

                const card = document.createElement("div");
                card.className = "d-flex justify-content-between align-items-center time-slot-card";
                card.innerHTML = `
                    <div>
                        <span class="service-time-display">${serviceStart} - ${serviceEnd}</span>
                        <br>
                        <small class="text-muted fs-6">
                            <i class="bi bi-clock-history me-1"></i>
                            Remaining Free Time: <span class="remaining-time-display">${t.time_remaining}</span>
                        </small>
                    </div>
                    <button 
                        class="btn book-slot-btn" 
                        data-slot-id="${t.slot_id}"
                        data-slot-date="${selectedDate}"
                        data-start-time="${t.start_time}"
                        data-end-time="${t.end_time}">
                        <i class="bi bi-check-circle"></i> Select
                    </button>`;
                timeCardContainer.appendChild(card);
            });
            
            attachBookingListeners(); 
        },
        dayCellDidMount: function(info) {
            // Use local date to avoid timezone offset issues
            const year = info.date.getFullYear();
            const month = String(info.date.getMonth() + 1).padStart(2, '0');
            const day = String(info.date.getDate()).padStart(2, '0');
            const dateStr = `${year}-${month}-${day}`;
            
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            // Check for past dates
            if (info.date < today) {
                info.el.classList.add("fc-day-past");
                info.el.style.pointerEvents = 'none';
                return;
            } 
            
            // Check for availability
            let hasCompatibleSlot = false;
            if (slotMap[dateStr]) {
                 // Check if there are any free time windows large enough for the booking after checking bookings
                 const dailyAvailableSlots = slotMap[dateStr] || [];
                 const dailyBookings = bookingMap[dateStr] || []; 
                 const freeTimeWindows = getFreeTimeWindows(dateStr, dailyAvailableSlots, dailyBookings);

                 hasCompatibleSlot = freeTimeWindows.length > 0;
            }

            if (hasCompatibleSlot) {
                info.el.classList.add("fc-day-available");
            } else {
                info.el.classList.add("fc-day-unavailable");
                info.el.style.pointerEvents = 'none'; // Disable clicking
            }
        }
    });

    function attachBookingListeners() {
        document.querySelectorAll('.book-slot-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const slotId = this.dataset.slotId;
                const slotDate = this.dataset.slotDate;
                const startTime = this.dataset.startTime;
                const endTime = this.dataset.endTime;     
                
                // Redirect to mechanic selection with the selected slot data
                window.location.href = `book_service.php?auto_proceed=1&slot_id=${slotId}&date=${slotDate}&start=${startTime}&end=${endTime}`;
            });
        });
    }
    
    calendar.render();
});
</script>

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
</script>
</body>
</html>