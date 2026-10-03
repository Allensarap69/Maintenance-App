<?php
// Admin Sidebar Template
// Usage: require 'admin_sidebar_template.php' at the top of admin pages after session_start() and db.php
// Then add your content, and finally require 'admin_sidebar_footer.php'

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/status_helper.php';
require_once __DIR__ . '/pagination_helper.php';

$adminName = $_SESSION['username'] ?? 'Admin';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
require 'notification_helper.php';

// Get pending count for badge
$pendingCount = 0;
try {
    $pendingCount = $pdo->query("
        SELECT COUNT(id) FROM bookings 
        WHERE status IN ('pending', 'unassigned', 'deposit_submitted')
    ")->fetchColumn();
} catch (PDOException $e) {
    $pendingCount = 0;
}

// Get new/pending emergency request count for badge
$emergencyCount = 0;
try {
    $emergencyCount = $pdo->query("
        SELECT COUNT(id) FROM emergency_service_requests 
        WHERE request_status IN ('pending', 'new')
    ")->fetchColumn();
} catch (PDOException $e) {
    $emergencyCount = 0;
}

// --- Admin notification items for the header bell ---
$adminNotificationItems = [];
$adminSeenBookings  = $_SESSION['admin_seen_booking_ids'] ?? [];
$adminSeenEmergency = $_SESSION['admin_seen_emergency_ids'] ?? [];
$adminSeenWarranty  = $_SESSION['admin_seen_warranty_ids'] ?? [];
$adminSeenHealth    = $_SESSION['admin_seen_health_ids'] ?? [];

if (!function_exists('notifTimeAgo')) {
    function notifTimeAgo($dt) {
        if (empty($dt)) return '';
        $ts = strtotime($dt);
        if (!$ts) return '';
        $now = time();
        if ($ts > $now) return date('M d, Y', $ts);
        $diff = $now - $ts;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';
        return date('M d, Y', $ts);
    }
}

// Pending / new bookings needing admin action
try {
    $sql = "
        SELECT b.id, b.status, b.schedule_date, b.schedule_start_time, u.name AS customer_name
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        WHERE b.status IN ('pending', 'unassigned', 'deposit_submitted')
    ";
    if (!empty($adminSeenBookings)) {
        $sql .= " AND b.id NOT IN (" . implode(',', array_map('intval', $adminSeenBookings)) . ")";
    }
    $sql .= " ORDER BY b.schedule_date ASC, b.schedule_start_time ASC LIMIT 5";
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $label = $row['status'] === 'deposit_submitted' ? 'Deposit submitted'
               : ($row['status'] === 'unassigned' ? 'Unassigned booking' : 'New booking');
        $adminNotificationItems[] = [
            'type'       => 'booking',
            'title'      => $label . ' #' . $row['id'],
            'message'    => ($row['customer_name'] ?: 'Customer') . ' - ' . date('M d, Y', strtotime($row['schedule_date'])),
            'link'       => 'manage_bookings.php',
            'time_label' => date('M d, Y g:i A', strtotime($row['schedule_date'] . ' ' . $row['schedule_start_time']))
        ];
    }
} catch (PDOException $e) {
    error_log("Admin notif bookings error: " . $e->getMessage());
}

// Pending / new emergency requests
try {
    $sql = "
        SELECT esr.id, esr.request_status, esr.created_at, u.name AS customer_name
        FROM emergency_service_requests esr
        JOIN users u ON esr.customer_id = u.id
        WHERE esr.request_status IN ('pending', 'new')
    ";
    if (!empty($adminSeenEmergency)) {
        $sql .= " AND esr.id NOT IN (" . implode(',', array_map('intval', $adminSeenEmergency)) . ")";
    }
    $sql .= " ORDER BY esr.created_at DESC LIMIT 5";
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $adminNotificationItems[] = [
            'type'       => 'emergency',
            'title'      => 'Emergency Request #' . $row['id'],
            'message'    => ($row['customer_name'] ?: 'Customer') . ' needs assistance',
            'link'       => 'admin_emergency_requests.php',
            'time_label' => notifTimeAgo($row['created_at'])
        ];
    }
} catch (PDOException $e) {
    error_log("Admin notif emergency error: " . $e->getMessage());
}

// Pending warranty claims
try {
    $sql = "
        SELECT wc.id, wc.claim_date, u.name AS customer_name
        FROM warranty_claims wc
        JOIN warranties w ON wc.warranty_id = w.id
        LEFT JOIN users u ON w.customer_id = u.id
        WHERE wc.claim_status = 'pending'
    ";
    if (!empty($adminSeenWarranty)) {
        $sql .= " AND wc.id NOT IN (" . implode(',', array_map('intval', $adminSeenWarranty)) . ")";
    }
    $sql .= " ORDER BY wc.claim_date DESC LIMIT 5";
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $adminNotificationItems[] = [
            'type'       => 'warranty',
            'title'      => 'Warranty Claim #' . $row['id'],
            'message'    => ($row['customer_name'] ?: 'Customer') . ' - ' . date('M d, Y', strtotime($row['claim_date'])),
            'link'       => 'admin_warranty.php',
            'time_label' => notifTimeAgo($row['claim_date'])
        ];
    }
} catch (PDOException $e) {
    error_log("Admin notif warranty error: " . $e->getMessage());
}

// Motorcycles with critical health scores
try {
    $sql = "
        SELECT m.id, m.health_score, m.brand, m.model, m.plate_number, u.name AS customer_name
        FROM motorcycles m
        LEFT JOIN users u ON m.user_id = u.id
        WHERE m.health_score < 60
    ";
    if (!empty($adminSeenHealth)) {
        $sql .= " AND m.id NOT IN (" . implode(',', array_map('intval', $adminSeenHealth)) . ")";
    }
    $sql .= " ORDER BY m.health_score ASC LIMIT 5";
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $adminNotificationItems[] = [
            'type'       => 'health',
            'title'      => 'Low Health Score',
            'message'    => $row['brand'] . ' ' . $row['model'] . ' (' . $row['plate_number'] . ') - health ' . $row['health_score'],
            'link'       => 'admin_health_scores.php',
            'time_label' => ''
        ];
    }
} catch (PDOException $e) {
    error_log("Admin notif health error: " . $e->getMessage());
}

$adminNotificationCount = count($adminNotificationItems);
$adminNotifIcons = [
    'booking'   => 'bi-journal-text',
    'emergency' => 'bi-exclamation-triangle-fill',
    'warranty'  => 'bi-shield-check',
    'health'    => 'bi-heart-pulse'
];
?>
<?php
$portalTitle = 'Admin';
$portalCss = 'assets/css/sidebar-admin.css';
require __DIR__ . '/partials/head.php';
?>
    <script>
        function confirmLogout() {
            Swal.fire({
                title: 'Logout?',
                text: 'You will be logged out.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, Logout',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'logout.php';
                }
            });
        }
    </script>
</head>
<body>
    <!-- Animated Background -->
    <div class="bg-animation">
        <div class="circle"></div>
        <div class="circle"></div>
        <div class="circle"></div>
        <div class="circle"></div>
        <div class="circle"></div>
    </div>

    <!-- Floating Tools Icons (like index.php) -->
    <div class="floating-tools">
        <i class="bi bi-tools"></i>
        <i class="bi bi-wrench"></i>
        <i class="bi bi-gear"></i>
        <i class="bi bi-car-front"></i>
        <i class="bi bi-speedometer2"></i>
        <i class="bi bi-people-fill"></i>
        <i class="bi bi-calendar-check"></i>
        <i class="bi bi-clipboard-check"></i>
    </div>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="dashboard_admin.php" class="sidebar-brand">
                <i data-lucide="motorbike" style="width: 34px; height: 34px;"></i>
                <span style="white-space: nowrap; font-size: 1rem; line-height: 1.2;">
                    Mindanao Eversure
                    <small style="display: block; font-size: .55rem; letter-spacing: .12em; white-space: nowrap; color: #FACC15; line-height: 1;">MOTORCYCLE SERVICE</small>
                </span>
            </a>
            <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Toggle Sidebar">
                <i class="bi bi-chevron-left"></i>
            </button>
        </div>

        <nav class="sidebar-menu">
            <?php
                $bookingsActive = in_array($currentPage, ['manage_bookings', 'bookings_status'], true);
                $emergencyActive = in_array($currentPage, ['admin_emergency_requests', 'emergency_status'], true);
            ?>
            <div class="menu-section">
                <div class="menu-title">Main Menu</div>
                <a href="dashboard_admin.php" class="menu-item <?= $currentPage == 'dashboard_admin' ? 'active' : '' ?>" data-tooltip="Dashboard">
                    <i data-lucide="layout-dashboard"></i>
                    <span>Dashboard</span>
                </a>

                <button type="button" class="menu-item menu-parent <?= $bookingsActive ? 'child-active open' : '' ?>" data-submenu="submenuBookings" data-href="manage_bookings.php" data-tooltip="Bookings">
                    <i data-lucide="calendar-check"></i>
                    <span>Bookings</span>
                    <?php if ($pendingCount > 0): ?>
                        <span class="menu-badge"><?= $pendingCount ?></span>
                    <?php endif; ?>
                    <i data-lucide="chevron-down" class="menu-caret"></i>
                </button>
                <div class="submenu <?= $bookingsActive ? 'open' : '' ?>" id="submenuBookings">
                    <a href="manage_bookings.php" class="submenu-item <?= $currentPage == 'manage_bookings' ? 'active' : '' ?>">
                        <span>Bookings</span>
                    </a>
                    <a href="bookings_status.php" class="submenu-item <?= $currentPage == 'bookings_status' ? 'active' : '' ?>">
                        <span>Bookings Status</span>
                    </a>
                </div>

                <button type="button" class="menu-item menu-parent <?= $emergencyActive ? 'child-active open' : '' ?>" data-submenu="submenuEmergency" data-href="admin_emergency_requests.php" data-tooltip="Emergency">
                    <i data-lucide="siren"></i>
                    <span>Emergency</span>
                    <?php if ($emergencyCount > 0): ?>
                        <span class="menu-badge"><?= $emergencyCount ?></span>
                    <?php endif; ?>
                    <i data-lucide="chevron-down" class="menu-caret"></i>
                </button>
                <div class="submenu <?= $emergencyActive ? 'open' : '' ?>" id="submenuEmergency">
                    <a href="admin_emergency_requests.php" class="submenu-item <?= $currentPage == 'admin_emergency_requests' ? 'active' : '' ?>">
                        <span>Emergency</span>
                    </a>
                    <a href="emergency_status.php" class="submenu-item <?= $currentPage == 'emergency_status' ? 'active' : '' ?>">
                        <span>Emergency Status</span>
                    </a>
                </div>
            </div>

            <div class="menu-section">
                <div class="menu-title">Fleet &amp; Customers</div>
                <a href="manage_customers_motorcycles.php" class="menu-item <?= $currentPage == 'manage_customers_motorcycles' ? 'active' : '' ?>" data-tooltip="Customers">
                    <i data-lucide="users"></i>
                    <span>Customers</span>
                </a>
                <a href="manage_motorcycles.php" class="menu-item <?= $currentPage == 'manage_motorcycles' ? 'active' : '' ?>" data-tooltip="Motorcycles">
                    <i data-lucide="bike"></i>
                    <span>Motorcycles</span>
                </a>
                <a href="admin_health_scores.php" class="menu-item <?= $currentPage == 'admin_health_scores' ? 'active' : '' ?>" data-tooltip="Health Scores">
                    <i data-lucide="heart-pulse"></i>
                    <span>Health Scores</span>
                </a>
                <a href="maintenance_management.php" class="menu-item <?= $currentPage == 'maintenance_management' ? 'active' : '' ?>" data-tooltip="Maintenance Management">
                    <i data-lucide="clipboard-list"></i>
                    <span>Maintenance Management</span>
                </a>
                <a href="admin_maintenance_history.php" class="menu-item <?= $currentPage == 'admin_maintenance_history' ? 'active' : '' ?>" data-tooltip="Maintenance History">
                    <i data-lucide="history"></i>
                    <span>Maintenance History</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-title">Operations &amp; Staff</div>
                <a href="manage_mechanics.php" class="menu-item <?= $currentPage == 'manage_mechanics' ? 'active' : '' ?>" data-tooltip="Mechanics">
                    <i data-lucide="wrench"></i>
                    <span>Mechanics</span>
                </a>
                <a href="mechanic_assignment.php" class="menu-item <?= $currentPage == 'mechanic_assignment' ? 'active' : '' ?>" data-tooltip="Assignments">
                    <i data-lucide="user-check"></i>
                    <span>Assignments</span>
                </a>
                <a href="admin_availability.php" class="menu-item <?= $currentPage == 'admin_availability' ? 'active' : '' ?>" data-tooltip="Availability">
                    <i data-lucide="calendar-days"></i>
                    <span>Availability</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-title">Catalog &amp; Services</div>
                <a href="services.php" class="menu-item <?= $currentPage == 'services' ? 'active' : '' ?>" data-tooltip="Services">
                    <i data-lucide="settings"></i>
                    <span>Services</span>
                </a>
                <a href="admin_service_packages.php" class="menu-item <?= $currentPage == 'admin_service_packages' ? 'active' : '' ?>" data-tooltip="Service Packages">
                    <i data-lucide="package"></i>
                    <span>Service Packages</span>
                </a>
                <a href="admin_warranty.php" class="menu-item <?= $currentPage == 'admin_warranty' ? 'active' : '' ?>" data-tooltip="Warranty">
                    <i data-lucide="shield-check"></i>
                    <span>Warranty</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-title">Communication</div>
                <a href="sms_history.php" class="menu-item <?= $currentPage == 'sms_history' ? 'active' : '' ?>" data-tooltip="SMS History">
                    <i data-lucide="message-square"></i>
                    <span>SMS History</span>
                </a>
                <a href="admin_feedback.php" class="menu-item <?= $currentPage == 'admin_feedback' ? 'active' : '' ?>" data-tooltip="Customer Feedback">
                    <i data-lucide="star"></i>
                    <span>Customer Feedback</span>
                </a>
            </div>
            <div class="menu-section">
                <div class="menu-title">Account</div>
                <a href="admin_profile.php" class="menu-item <?= $currentPage == 'admin_profile' ? 'active' : '' ?>" data-tooltip="Profile Settings">
                    <i data-lucide="user-cog"></i>
                    <span>Profile Settings</span>
                </a>
            </div>
        </nav>

    <script>
        (function () {
            const sidebar = document.getElementById('sidebar');
            const sidebarMenu = sidebar ? sidebar.querySelector('.sidebar-menu') : null;
            const savedScrollPosition = localStorage.getItem('adminSidebarScrollPosition');
            if (sidebarMenu && savedScrollPosition) {
                sidebarMenu.scrollTop = parseInt(savedScrollPosition);
            }
        })();
    </script>

    <div class="sidebar-footer">
        <div class="sidebar-footer-text">
            <i data-lucide="motorbike" style="width: 20px; height: 20px;"></i>
            <span style="display:flex;flex-direction:column;line-height:1.1;">Mindanao Eversure <small style="font-size:.55rem;letter-spacing:.12em;color:#FACC15;">MOTORCYCLE SERVICE</small></span>
        </div>
        <div class="sidebar-footer-copyright">
            © <?= date('Y') ?> All rights reserved
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const collapseBtn = document.getElementById('sidebarCollapseBtn');
            if (collapseBtn) {
                collapseBtn.addEventListener('click', function () {
                    document.body.classList.toggle('collapsed');
                    const icon = collapseBtn.querySelector('i');
                    if (icon) {
                        if (document.body.classList.contains('collapsed')) {
                            icon.classList.remove('bi-chevron-left');
                            icon.classList.add('bi-chevron-right');
                        } else {
                            icon.classList.remove('bi-chevron-right');
                            icon.classList.add('bi-chevron-left');
                        }
                    }
                });
            }

            // Collapsible parent menus
            document.querySelectorAll('.menu-parent').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    // When sidebar is collapsed, navigate to the first child page
                    if (document.body.classList.contains('collapsed')) {
                        const href = btn.getAttribute('data-href');
                        if (href) window.location.href = href;
                        return;
                    }
                    const submenu = document.getElementById(btn.getAttribute('data-submenu'));
                    if (submenu) {
                        submenu.classList.toggle('open');
                        btn.classList.toggle('open');
                    }
                });
            });

            // Admin notification bell
            const notifWrapper = document.getElementById('adminNotifWrapper');
            const notifBell = document.getElementById('adminNotifBell');
            const notifDropdown = document.getElementById('adminNotifDropdown');

            if (notifWrapper && notifBell && notifDropdown) {
                notifBell.addEventListener('click', function (e) {
                    e.stopPropagation();
                    notifDropdown.classList.toggle('show');

                    const badge = document.getElementById('adminNotifBadge');
                    if (badge) {
                        fetch('api/mark_admin_notifications_read.php', {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        }).then(function (res) {
                            if (res.ok) badge.style.display = 'none';
                        }).catch(function (err) {
                            console.error('mark admin notifications read failed', err);
                        });
                    }
                });

                document.addEventListener('click', function (e) {
                    if (!notifWrapper.contains(e.target)) {
                        notifDropdown.classList.remove('show');
                    }
                });
            }

            // Dark mode toggle
            const darkModeToggle = document.getElementById('darkModeToggle');
            if (darkModeToggle) {
                const dmIcon = darkModeToggle.querySelector('i');
                const syncDarkIcon = function () {
                    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                    dmIcon.classList.toggle('bi-moon-fill', !isDark);
                    dmIcon.classList.toggle('bi-sun-fill', isDark);
                };
                syncDarkIcon();
                darkModeToggle.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const root = document.documentElement;
                    const isDark = root.getAttribute('data-theme') !== 'dark';
                    const applyTheme = function () {
                        root.setAttribute('data-theme', isDark ? 'dark' : 'light');
                        localStorage.setItem('theme', isDark ? 'dark' : 'light');
                        localStorage.setItem('themeManual', '1');
                        syncDarkIcon();
                        document.dispatchEvent(new CustomEvent('adminThemeChanged', { detail: { dark: isDark } }));
                    };
                    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    if (!reduceMotion && document.startViewTransition) {
                        document.startViewTransition(applyTheme);
                    } else {
                        applyTheme();
                    }
                });
            }

            // Admin user dropdown (same behavior as customer)
            const adminUserWrap = document.getElementById('adminUserWrap');
            if (adminUserWrap) {
                adminUserWrap.addEventListener('click', function (e) {
                    e.stopPropagation();
                    adminUserWrap.classList.toggle('active');
                });

                document.addEventListener('click', function (e) {
                    if (!adminUserWrap.contains(e.target)) {
                        adminUserWrap.classList.remove('active');
                    }
                });
            }
        });
    </script>

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
                <?= isset($pageTitle) ? $pageTitle : 'Admin' ?>
            </h1>
            <!-- Admin Notification Bell -->
            <div class="admin-notifications ms-auto" id="adminNotifWrapper">
                <div class="notification-bell" id="adminNotifBell" title="Notifications">
                    <i class="bi bi-bell-fill"></i>
                    <?php if ($adminNotificationCount > 0): ?>
                        <span class="badge" id="adminNotifBadge"><?= $adminNotificationCount ?></span>
                    <?php endif; ?>
                </div>
                <div class="notification-dropdown" id="adminNotifDropdown">
                    <div class="notification-dropdown-header">
                        <div class="nd-title">
                            Notifications
                            <?php if ($adminNotificationCount > 0): ?>
                                <span class="nd-count"><?= $adminNotificationCount ?> new</span>
                            <?php endif; ?>
                        </div>
                        <a href="dashboard_admin.php" class="nd-link">Dashboard</a>
                    </div>
                    <div class="notification-list">
                        <?php if (!empty($adminNotificationItems)): ?>
                            <?php foreach ($adminNotificationItems as $item): ?>
                                <a href="<?= htmlspecialchars($item['link']) ?>" class="notification-item <?= htmlspecialchars($item['type']) ?>">
                                    <div class="notification-icon"><i class="bi <?= $adminNotifIcons[$item['type']] ?? 'bi-bell' ?>"></i></div>
                                    <div class="notification-item-text">
                                        <div class="notification-item-title"><?= htmlspecialchars($item['title']) ?></div>
                                        <div class="notification-item-message"><?= htmlspecialchars($item['message']) ?></div>
                                        <?php if (!empty($item['time_label'])): ?>
                                            <div class="notification-item-time"><i class="bi bi-clock"></i><?= htmlspecialchars($item['time_label']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="notification-dot"></span>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="notification-empty">
                                <i class="bi bi-bell-slash"></i>
                                <div class="ne-title">All caught up</div>
                                <div class="ne-sub">No new notifications right now</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <!-- Dark Mode Toggle -->
            <div class="dark-mode-toggle" id="darkModeToggle" title="Toggle dark mode">
                <i class="bi bi-moon-fill"></i>
            </div>
            <div class="header-user-wrap d-none d-md-flex" id="adminUserWrap">
                <div class="user-avatar">
                    <?= strtoupper(substr($adminName, 0, 1)) ?>
                </div>
                <div class="user-info text-end">
                    <div class="user-name"><?= htmlspecialchars($adminName) ?></div>
                    <div class="user-role">Administrator</div>
                </div>
                <i class="bi bi-chevron-down top-bar-dropdown-btn"></i>
                <div class="header-user-dropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-header-name"><?= htmlspecialchars($adminName) ?></div>
                        <div class="dropdown-header-role">Administrator</div>
                    </div>
                    <a href="admin_profile.php" class="dropdown-item">
                        <i class="bi bi-person-gear"></i>
                        <span>Profile Settings</span>
                    </a>
                    <div class="dropdown-item danger" onclick="confirmLogout()">
                        <i class="bi bi-box-arrow-right"></i>
                        <span>Logout</span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="main-content">

<?php include __DIR__ . '/floating_toast.php'; ?>