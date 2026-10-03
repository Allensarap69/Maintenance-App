<?php
/**
 * Customer Sidebar Template
 * Include this file in customer pages to add the consistent sidebar navigation
 * Usage: include 'customer_sidebar.php';
 * 
 * Variables expected:
 * - $username: Current user's username
 * - $totalUpcoming: Number of upcoming bookings (optional, defaults to 0)
 * - $show_badge: Whether to show notification badge (optional, defaults to false)
 * - $active_page: The current page filename for highlighting active menu item (optional)
 */

if (!isset($username)) $username = $_SESSION['username'] ?? 'Customer';
if (!isset($totalUpcoming)) $totalUpcoming = 0;
if (!isset($show_badge)) $show_badge = $totalUpcoming > 0;
if (!isset($active_page)) $active_page = basename($_SERVER['PHP_SELF']);

require_once __DIR__ . '/status_helper.php';
require_once __DIR__ . '/pagination_helper.php';

// Shared UI layer — emitted here (body partial) since customer pages
// each own their <head>. Guarded against double-inclusion.
if (!defined('ACPC_UI_ASSETS')) {
    define('ACPC_UI_ASSETS', true);
    echo '<link rel="stylesheet" href="assets/css/app.css">' . "\n";
    echo '<link rel="stylesheet" href="assets/css/ui.css">' . "\n";
    echo '<script src="assets/js/pager.js?v=' . @filemtime(__DIR__ . '/assets/js/pager.js') . '" defer></script>' . "\n";
}

$healthScoreNotificationCount = 0;
$unreadHealthCount = 0;
$customerNotificationItems = [];
$unreadBookingItems = [];

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
if (isset($_SESSION['user_id'])) {
    try {
        require_once 'db.php';
        $pdo->exec("CREATE TABLE IF NOT EXISTS customer_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            motorcycle_id INT NOT NULL,
            health_score INT NOT NULL,
            recommendation TEXT,
            is_read TINYINT(1) DEFAULT 0,
            is_applied TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_customer (customer_id),
            INDEX idx_motorcycle (motorcycle_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Total upcoming bookings for sidebar badge
        if ($totalUpcoming === 0) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM bookings
                WHERE user_id = ? AND status IN ('pending','deposit_submitted','accepted')
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $totalUpcoming = (int) $stmt->fetchColumn();
        }

        // Health score unapplied notifications for sidebar badge
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT n.motorcycle_id)
            FROM customer_notifications n
            JOIN motorcycles m ON n.motorcycle_id = m.id
            WHERE n.customer_id = ? AND n.is_applied = 0
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $healthScoreNotificationCount = (int) $stmt->fetchColumn();

        // --- Unread notification count for top bell ---

        // Health score notifications unread
        $unreadHealthCount = 0;
        $unreadHealthItems = [];
        $stmt = $pdo->prepare("
            SELECT n.id, n.motorcycle_id, n.health_score, n.recommendation, n.created_at,
                   m.brand, m.model, m.plate_number
            FROM customer_notifications n
            JOIN motorcycles m ON n.motorcycle_id = m.id
            WHERE n.customer_id = ? AND n.is_read = 0
            ORDER BY n.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$_SESSION['user_id']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $unreadHealthItems[] = [
                'type' => 'health',
                'title' => 'Health Score Alert',
                'message' => ($row['brand'] . ' ' . $row['model'] . ' (' . $row['plate_number'] . ') - health ' . $row['health_score']),
                'link' => 'customer_health_score.php',
                'time_label' => notifTimeAgo($row['created_at'])
            ];
        }
        $unreadHealthCount = count($unreadHealthItems);

        // Unseen upcoming bookings for bell
        $seenBookingIds = $_SESSION['seen_booking_ids'] ?? [];
        $unreadBookingItems = [];
        if (!empty($seenBookingIds)) {
            $placeholders = implode(',', array_fill(0, count($seenBookingIds), '?'));
            $stmt = $pdo->prepare("
                SELECT id, status, schedule_date, schedule_start_time, vehicle_id
                FROM bookings
                WHERE user_id = ? AND status IN ('pending','deposit_submitted','accepted') AND id NOT IN ($placeholders)
                ORDER BY schedule_date ASC, schedule_start_time ASC
                LIMIT 5
            ");
            $stmt->execute(array_merge([$_SESSION['user_id']], $seenBookingIds));
        } else {
            $stmt = $pdo->prepare("
                SELECT id, status, schedule_date, schedule_start_time, vehicle_id
                FROM bookings
                WHERE user_id = ? AND status IN ('pending','deposit_submitted','accepted')
                ORDER BY schedule_date ASC, schedule_start_time ASC
                LIMIT 5
            ");
            $stmt->execute([$_SESSION['user_id']]);
        }
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $unreadBookingItems[] = [
                'type' => 'booking',
                'title' => 'Booking #' . $row['id'],
                'message' => ucfirst(str_replace('_', ' ', $row['status'])) . ' on ' . date('M d, Y', strtotime($row['schedule_date'])),
                'link' => 'my_bookings.php',
                'time_label' => date('M d, Y g:i A', strtotime($row['schedule_date'] . ' ' . $row['schedule_start_time']))
            ];
        }

        $customerNotificationItems = array_merge($unreadHealthItems, $unreadBookingItems);
    } catch (PDOException $e) {
        error_log("Sidebar notification query error: " . $e->getMessage());
    }
}

$totalUpcoming = count($unreadBookingItems);
$healthScoreNotificationCount = $unreadHealthCount;
$totalNotificationCount = count($customerNotificationItems);
?>

<script>
    // Global theme state: apply saved theme before first paint to avoid a light-theme flash
    (function () {
        var t = localStorage.getItem('theme');
        if (t !== 'dark' && t !== 'light') {
            t = (localStorage.getItem('adminDarkMode') === '1' || localStorage.getItem('customerDarkMode') === '1') ? 'dark' : 'light';
            localStorage.setItem('theme', t);
            localStorage.removeItem('adminDarkMode');
            localStorage.removeItem('customerDarkMode');
        }
        document.documentElement.setAttribute('data-theme', t);
    })();
</script>

<!-- Sidebar CSS -->
<link rel="stylesheet" href="assets/css/customer-sidebar.css?v=<?= @filemtime(__DIR__ . '/assets/css/customer-sidebar.css') ?>">

<!-- Sidebar Overlay for Mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar Navigation -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <a href="dashboard_customer.php" class="sidebar-brand">
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
    
    <div class="sidebar-menu">
        <div class="sidebar-section">
            <div class="sidebar-section-title">Main Menu</div>
            <a href="dashboard_customer.php" class="sidebar-nav-item <?= $active_page === 'dashboard_customer.php' ? 'active' : '' ?>" data-tooltip="Dashboard">
                <i class="bi bi-grid-1x2"></i>
                <span>Dashboard</span>
            </a>
            <a href="book_service.php" class="sidebar-nav-item <?= $active_page === 'book_service.php' ? 'active' : '' ?>" data-tooltip="Book Service">
                <i class="bi bi-calendar-plus"></i>
                <span>Book Service</span>
            </a>
            <a href="my_bookings.php" class="sidebar-nav-item <?= $active_page === 'my_bookings.php' ? 'active' : '' ?>" data-tooltip="My Bookings">
                <i class="bi bi-journal-text"></i>
                <span>My Bookings</span>
                <?php if ($totalUpcoming > 0): ?>
                    <span class="badge"><?= $totalUpcoming ?></span>
                <?php endif; ?>
            </a>
            <a href="customer_emergency_service.php" class="sidebar-nav-item <?= $active_page === 'customer_emergency_service.php' ? 'active' : '' ?>" data-tooltip="Request Emergency">
                <i class="bi bi-exclamation-triangle"></i>
                <span>Request Emergency</span>
            </a>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-section-title">My Vehicles</div>
            <a href="customer_health_score.php" class="sidebar-nav-item <?= $active_page === 'customer_health_score.php' ? 'active' : '' ?>" data-tooltip="Health Score">
                <i class="bi bi-heart-pulse"></i>
                <span>Health Score</span>
                <?php if ($healthScoreNotificationCount > 0): ?>
                    <span class="badge"><?= $healthScoreNotificationCount ?></span>
                <?php endif; ?>
            </a>
            <a href="customer_maintenance_history.php" class="sidebar-nav-item <?= $active_page === 'customer_maintenance_history.php' ? 'active' : '' ?>" data-tooltip="Maintenance History">
                <i class="bi bi-tools"></i>
                <span>Maintenance History</span>
            </a>
            <a href="customer_warranty_info.php" class="sidebar-nav-item <?= $active_page === 'customer_warranty_info.php' ? 'active' : '' ?>" data-tooltip="Warranty Info">
                <i class="bi bi-shield-check"></i>
                <span>Warranty Info</span>
            </a>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-section-title">Services</div>
            <a href="support.php" class="sidebar-nav-item <?= $active_page === 'support.php' ? 'active' : '' ?>" data-tooltip="Help & Support">
                <i class="bi bi-headset"></i>
                <span>Help & Support</span>
            </a>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-section-title">Account</div>
            <a href="profile.php" class="sidebar-nav-item <?= ($active_page === 'profile.php' && (!isset($_GET['tab']) || $_GET['tab'] === 'profile')) ? 'active' : '' ?>" data-tooltip="Profile Settings">
                <i class="bi bi-person-gear"></i>
                <span>Profile Settings</span>
            </a>
        </div>
    </div>
    
    <div class="sidebar-footer">
        <div class="sidebar-footer-text">
            <i data-lucide="motorbike" style="width: 20px; height: 20px;"></i>
            <span>Mindanao Eversure</span>
        </div>
        <div class="sidebar-footer-copyright">
            © <?= date('Y') ?> All rights reserved
        </div>
    </div>
</aside>

<script>
    window.customerNotificationsData = <?= json_encode([
        'count' => $totalNotificationCount,
        'items' => $customerNotificationItems
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>

<!-- Sidebar JavaScript -->
<script>
    // Sidebar toggle functionality
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const sidebarCollapseBtn = document.getElementById('sidebarCollapseBtn');
        const mainContent = document.querySelector('.main-content');

        if (sidebarToggle && sidebar && sidebarOverlay) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
                sidebarOverlay.classList.toggle('active');
            });

            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
            });
        }

        // Customer notification bell
        const topBar = document.querySelector('.top-bar');
        const topBarUser = document.querySelector('.top-bar-user');
        const notifData = window.customerNotificationsData || { count: 0, items: [] };

        if (topBar && topBarUser) {
            const notifWrapper = document.createElement('div');
            notifWrapper.className = 'top-bar-notifications';

            const bell = document.createElement('div');
            bell.className = 'notification-bell';
            bell.title = 'Notifications';
            bell.innerHTML = '<i class="bi bi-bell-fill"></i>' +
                (notifData.count > 0 ? '<span class="badge">' + notifData.count + '</span>' : '');

            const dropdown = document.createElement('div');
            dropdown.className = 'notification-dropdown';

            const esc = function (s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            };

            const iconMap = {
                booking: 'bi-journal-text',
                emergency: 'bi-exclamation-triangle-fill',
                warranty: 'bi-shield-check',
                health: 'bi-heart-pulse'
            };

            let dropdownHTML = '<div class="notification-dropdown-header">' +
                '<div class="nd-title">Notifications' +
                (notifData.count > 0 ? '<span class="nd-count">' + notifData.count + ' new</span>' : '') +
                '</div>' +
                '<a href="my_bookings.php" class="nd-link">View All</a>' +
                '</div><div class="notification-list">';

            if (notifData.items && notifData.items.length > 0) {
                notifData.items.forEach(item => {
                    const iconClass = iconMap[item.type] || 'bi-bell';
                    dropdownHTML += '<a href="' + esc(item.link) + '" class="notification-item ' + esc(item.type) + '">' +
                        '<div class="notification-icon"><i class="bi ' + iconClass + '"></i></div>' +
                        '<div class="notification-item-text">' +
                            '<div class="notification-item-title">' + esc(item.title) + '</div>' +
                            '<div class="notification-item-message">' + esc(item.message) + '</div>' +
                            (item.time_label ? '<div class="notification-item-time"><i class="bi bi-clock"></i>' + esc(item.time_label) + '</div>' : '') +
                        '</div>' +
                        '<span class="notification-dot"></span>' +
                    '</a>';
                });
            } else {
                dropdownHTML += '<div class="notification-empty">' +
                    '<i class="bi bi-bell-slash"></i>' +
                    '<div class="ne-title">All caught up</div>' +
                    '<div class="ne-sub">No new notifications right now</div>' +
                    '</div>';
            }

            dropdownHTML += '</div>';

            dropdown.innerHTML = dropdownHTML;
            notifWrapper.appendChild(bell);
            notifWrapper.appendChild(dropdown);

            const userAvatar = topBarUser.querySelector('.top-bar-user-avatar');
            if (userAvatar) {
                topBarUser.insertBefore(notifWrapper, userAvatar);
            } else if (topBarUser.firstChild) {
                topBarUser.insertBefore(notifWrapper, topBarUser.firstChild);
            } else {
                topBarUser.appendChild(notifWrapper);
            }

            // Dark mode toggle (beside the notification bell)
            const themeToggle = document.createElement('button');
            themeToggle.type = 'button';
            themeToggle.className = 'dark-mode-toggle';
            themeToggle.title = 'Toggle dark mode';
            themeToggle.setAttribute('aria-label', 'Toggle dark mode');
            const renderToggleIcon = function () {
                themeToggle.innerHTML = '<i class="bi ' +
                    (document.documentElement.getAttribute('data-theme') === 'dark' ? 'bi-sun-fill' : 'bi-moon-fill') +
                    '"></i>';
            };
            renderToggleIcon();
            themeToggle.addEventListener('click', function (e) {
                e.stopPropagation();
                const root = document.documentElement;
                const isDark = root.getAttribute('data-theme') !== 'dark';
                const applyTheme = function () {
                    root.setAttribute('data-theme', isDark ? 'dark' : 'light');
                    localStorage.setItem('theme', isDark ? 'dark' : 'light');
                    renderToggleIcon();
                    window.dispatchEvent(new CustomEvent('customerThemeChanged', { detail: { dark: isDark } }));
                };
                const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                if (!reduceMotion && document.startViewTransition) {
                    document.startViewTransition(applyTheme);
                } else {
                    applyTheme();
                }
            });
            notifWrapper.parentNode.insertBefore(themeToggle, notifWrapper.nextSibling);

            bell.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdown.classList.toggle('show');
                const badge = bell.querySelector('.badge');
                if (badge) {
                    badge.style.display = 'none';
                }

                // Mark notifications as read on the server
                if (notifData.count > 0) {
                    fetch('api/mark_notifications_read.php', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    }).catch(function(err) {
                        console.error('mark notifications read failed', err);
                    });
                }

                document.querySelectorAll('.notification-dropdown.show').forEach(el => {
                    if (el !== dropdown) el.classList.remove('show');
                });
            });

            document.addEventListener('click', function(e) {
                if (!notifWrapper.contains(e.target)) {
                    dropdown.classList.remove('show');
                }
            });
        }

        // Sidebar collapse functionality
        if (sidebarCollapseBtn && sidebar && mainContent) {
            sidebarCollapseBtn.addEventListener('click', function() {
                if (window.innerWidth <= 992) {
                    sidebar.classList.remove('active');
                    if (sidebarOverlay) sidebarOverlay.classList.remove('active');
                    return;
                }
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');

                // Update collapse button icon
                const icon = sidebarCollapseBtn.querySelector('i');
                if (sidebar.classList.contains('collapsed')) {
                    icon.classList.remove('bi-chevron-left');
                    icon.classList.add('bi-chevron-right');
                } else {
                    icon.classList.remove('bi-chevron-right');
                    icon.classList.add('bi-chevron-left');
                }
            });
        }
    });
</script>

<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script>
    if (typeof lucide !== 'undefined') lucide.createIcons();
</script>

<?php include __DIR__ . '/floating_toast.php'; ?>