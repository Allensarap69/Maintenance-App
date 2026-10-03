<?php
/**
 * Mechanic Sidebar Template
 * Same layout/UI as the admin sidebar (admin_sidebar_template.php).
 * Include this at the top of mechanic pages for consistent responsive sidebar + top header.
 * Usage:
 *   $pageTitle = 'Mechanic Dashboard';
 *   include 'mechanic_sidebar.php';
 *   <div class="content-area"> your content </div>
 *   include 'mechanic_sidebar_footer.php';
 */

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mechanic') {
    header("Location: index.php");
    exit;
}

$mechanicName = $_SESSION['username'] ?? 'Mechanic';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
require_once 'notification_helper.php';
require_once __DIR__ . '/status_helper.php';
require_once __DIR__ . '/pagination_helper.php';

// Resolve the mechanic id (pages usually set $mechanic_id already)
$sidebarMechanicId = $mechanic_id ?? ($user['mechanic_id'] ?? null);
if (!$sidebarMechanicId && isset($pdo) && isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM mechanics WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $sidebarMechanicId = $stmt->fetchColumn() ?: null;
    } catch (PDOException $e) {
        $sidebarMechanicId = null;
    }
}

// Sidebar badge counts: newly assigned bookings / emergency requests
$assignedBookingCount = 0;
$assignedEmergencyCount = 0;
if ($sidebarMechanicId && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT b.id)
            FROM bookings b
            LEFT JOIN booking_mechanics bm ON bm.booking_id = b.id
            WHERE (b.mechanic_id = ? OR bm.mechanic_id = ?) AND b.status = 'assigned'
        ");
        $stmt->execute([$sidebarMechanicId, $sidebarMechanicId]);
        $assignedBookingCount = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        $assignedBookingCount = 0;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(id) FROM emergency_service_requests
            WHERE assigned_mechanic_id = ? AND request_status = 'assigned'
        ");
        $stmt->execute([$sidebarMechanicId]);
        $assignedEmergencyCount = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        $assignedEmergencyCount = 0;
    }
}

// --- Mechanic notification items for the header bell ---
$mechNotificationItems = [];
$mechSeenBookings  = $_SESSION['mechanic_seen_booking_ids'] ?? [];
$mechSeenEmergency = $_SESSION['mechanic_seen_emergency_ids'] ?? [];

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

// Newly assigned bookings needing mechanic action
if ($sidebarMechanicId && isset($pdo)) {
    try {
        $sql = "
            SELECT DISTINCT b.id, b.schedule_date, b.schedule_start_time, u.name AS customer_name
            FROM bookings b
            LEFT JOIN booking_mechanics bm ON bm.booking_id = b.id
            JOIN users u ON b.user_id = u.id
            WHERE (b.mechanic_id = ? OR bm.mechanic_id = ?) AND b.status = 'assigned'
        ";
        if (!empty($mechSeenBookings)) {
            $sql .= " AND b.id NOT IN (" . implode(',', array_map('intval', $mechSeenBookings)) . ")";
        }
        $sql .= " ORDER BY b.schedule_date ASC, b.schedule_start_time ASC LIMIT 5";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sidebarMechanicId, $sidebarMechanicId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mechNotificationItems[] = [
                'type'       => 'booking',
                'title'      => 'New Assignment #' . $row['id'],
                'message'    => ($row['customer_name'] ?: 'Customer') . ' - ' . date('M d, Y', strtotime($row['schedule_date'])),
                'link'       => 'mechanic_bookings.php',
                'time_label' => date('M d, Y g:i A', strtotime($row['schedule_date'] . ' ' . $row['schedule_start_time']))
            ];
        }
    } catch (PDOException $e) {
        error_log("Mechanic notif bookings error: " . $e->getMessage());
    }

    // Newly assigned emergency requests
    try {
        $sql = "
            SELECT esr.id, esr.request_status, esr.created_at, u.name AS customer_name
            FROM emergency_service_requests esr
            JOIN users u ON esr.customer_id = u.id
            WHERE esr.assigned_mechanic_id = ? AND esr.request_status = 'assigned'
        ";
        if (!empty($mechSeenEmergency)) {
            $sql .= " AND esr.id NOT IN (" . implode(',', array_map('intval', $mechSeenEmergency)) . ")";
        }
        $sql .= " ORDER BY esr.created_at DESC LIMIT 5";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sidebarMechanicId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mechNotificationItems[] = [
                'type'       => 'emergency',
                'title'      => 'Emergency Request #' . $row['id'],
                'message'    => ($row['customer_name'] ?: 'Customer') . ' needs assistance',
                'link'       => 'mechanic_emergency.php',
                'time_label' => notifTimeAgo($row['created_at'])
            ];
        }
    } catch (PDOException $e) {
        error_log("Mechanic notif emergency error: " . $e->getMessage());
    }
}

$mechNotificationCount = count($mechNotificationItems);
$mechNotifIcons = [
    'booking'   => 'bi-journal-text',
    'emergency' => 'bi-exclamation-triangle-fill'
];

$pageIcons = [
    'dashboard_mechanic' => 'bi-speedometer2',
    'mechanic_bookings'  => 'bi-calendar-check',
    'mechanic_emergency' => 'bi-exclamation-triangle',
    'mechanic_profile'   => 'bi-person'
];
$pageIcon = $pageIcons[$currentPage] ?? 'bi-speedometer2';
?>
<?php
$portalTitle = 'Mechanic';
$portalCss = 'assets/css/sidebar-mechanic.css';
require __DIR__ . '/partials/head.php';
?>
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
            <a href="dashboard_mechanic.php" class="sidebar-brand">
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
            <div class="menu-section">
                <div class="menu-title">Main Menu</div>
                <a href="dashboard_mechanic.php" class="menu-item <?= $currentPage == 'dashboard_mechanic' ? 'active' : '' ?>">
                    <i data-lucide="layout-dashboard"></i>
                    <span>Dashboard</span>
                </a>
                <a href="mechanic_bookings.php" class="menu-item <?= $currentPage == 'mechanic_bookings' ? 'active' : '' ?>">
                    <i data-lucide="calendar-check"></i>
                    <span>Assign Appointments</span>
                    <?php if ($assignedBookingCount > 0): ?>
                        <span class="menu-badge"><?= $assignedBookingCount ?></span>
                    <?php endif; ?>
                </a>
                <a href="mechanic_emergency.php" class="menu-item <?= $currentPage == 'mechanic_emergency' ? 'active' : '' ?>">
                    <i data-lucide="siren"></i>
                    <span>Emergency</span>
                    <?php if ($assignedEmergencyCount > 0): ?>
                        <span class="menu-badge"><?= $assignedEmergencyCount ?></span>
                    <?php endif; ?>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-title">Account</div>
                <a href="mechanic_profile.php" class="menu-item <?= $currentPage == 'mechanic_profile' ? 'active' : '' ?>">
                    <i data-lucide="user-cog"></i>
                    <span>My Profile</span>
                </a>
            </div>
        </nav>

    <script>
        (function () {
            const sidebar = document.getElementById('sidebar');
            const sidebarMenu = sidebar ? sidebar.querySelector('.sidebar-menu') : null;
            const savedScrollPosition = localStorage.getItem('mechanicSidebarScrollPosition');
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

            // Mechanic notification bell
            const notifWrapper = document.getElementById('mechNotifWrapper');
            const notifBell = document.getElementById('mechNotifBell');
            const notifDropdown = document.getElementById('mechNotifDropdown');

            if (notifWrapper && notifBell && notifDropdown) {
                notifBell.addEventListener('click', function (e) {
                    e.stopPropagation();
                    notifDropdown.classList.toggle('show');

                    const badge = document.getElementById('mechNotifBadge');
                    if (badge) {
                        fetch('api/mark_mechanic_notifications_read.php', {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        }).then(function (res) {
                            if (res.ok) badge.style.display = 'none';
                        }).catch(function (err) {
                            console.error('mark mechanic notifications read failed', err);
                        });
                    }
                });

                document.addEventListener('click', function (e) {
                    if (!notifWrapper.contains(e.target)) {
                        notifDropdown.classList.remove('show');
                    }
                });
            }

            // Header user dropdown (same behavior as admin)
            const mechUserWrap = document.getElementById('mechUserWrap');
            if (mechUserWrap) {
                mechUserWrap.addEventListener('click', function (e) {
                    e.stopPropagation();
                    mechUserWrap.classList.toggle('active');
                });

                document.addEventListener('click', function (e) {
                    if (!mechUserWrap.contains(e.target)) {
                        mechUserWrap.classList.remove('active');
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
                <i class="bi <?= $pageIcon ?>"></i>
                <span class="page-title-text">
                    <?= isset($pageTitle) ? $pageTitle : 'Mechanic' ?>
                    <?php if (!empty($pageSubtitle)): ?>
                        <small class="page-subtitle"><?= htmlspecialchars($pageSubtitle) ?></small>
                    <?php endif; ?>
                </span>
            </h1>
            <!-- Mechanic Notification Bell -->
            <div class="mech-notifications ms-auto" id="mechNotifWrapper">
                <div class="notification-bell" id="mechNotifBell" title="Notifications">
                    <i class="bi bi-bell-fill"></i>
                    <?php if ($mechNotificationCount > 0): ?>
                        <span class="badge" id="mechNotifBadge"><?= $mechNotificationCount ?></span>
                    <?php endif; ?>
                </div>
                <div class="notification-dropdown" id="mechNotifDropdown">
                    <div class="notification-dropdown-header">
                        <div class="nd-title">
                            Notifications
                            <?php if ($mechNotificationCount > 0): ?>
                                <span class="nd-count"><?= $mechNotificationCount ?> new</span>
                            <?php endif; ?>
                        </div>
                        <a href="dashboard_mechanic.php" class="nd-link">Dashboard</a>
                    </div>
                    <div class="notification-list">
                        <?php if (!empty($mechNotificationItems)): ?>
                            <?php foreach ($mechNotificationItems as $item): ?>
                                <a href="<?= htmlspecialchars($item['link']) ?>" class="notification-item <?= htmlspecialchars($item['type']) ?>">
                                    <div class="notification-icon"><i class="bi <?= $mechNotifIcons[$item['type']] ?? 'bi-bell' ?>"></i></div>
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
            <div class="header-user-wrap" id="mechUserWrap">
                <div class="user-avatar">
                    <?= strtoupper(substr($mechanicName, 0, 1)) ?>
                </div>
                <div class="user-info text-end">
                    <div class="user-name"><?= htmlspecialchars($mechanicName) ?></div>
                    <div class="user-role">Mechanic</div>
                </div>
                <i class="bi bi-chevron-down top-bar-dropdown-btn"></i>
                <div class="header-user-dropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-header-name"><?= htmlspecialchars($mechanicName) ?></div>
                        <div class="dropdown-header-role">Mechanic</div>
                    </div>
                    <a href="mechanic_profile.php" class="dropdown-item">
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