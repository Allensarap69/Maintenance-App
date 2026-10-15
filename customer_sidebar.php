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
    echo '<link rel="stylesheet" href="assets/css/mobile.css?v=' . @filemtime(__DIR__ . '/assets/css/mobile.css') . '">' . "\n";
    echo '<script src="assets/js/pager.js?v=' . @filemtime(__DIR__ . '/assets/js/pager.js') . '" defer></script>' . "\n";
}

$healthScoreNotificationCount = 0;
$unreadHealthCount = 0;
$customerNotificationItems = [];
$unreadBookingItems = [];

if (isset($_SESSION['user_id'])) {
    try {
        require_once 'db.php';
        require_once __DIR__ . '/customer_notifications_helper.php';
        $custNotif = fetch_customer_notifications($pdo, (int) $_SESSION['user_id']);
        $customerNotificationItems = $custNotif['items'];
        $unreadBookingItems = $custNotif['booking_items'];
        $unreadHealthCount = $custNotif['unread_health'];
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
        if (localStorage.getItem('themeManual') !== '1' || (t !== 'dark' && t !== 'light')) {
            t = 'light';
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
                health: 'bi-heart-pulse',
                part: 'bi-gear-fill'
            };

            const notifEmptyHTML =
                '<div class="notification-empty">' +
                    '<i class="bi bi-bell-slash"></i>' +
                    '<div class="ne-title">All caught up</div>' +
                    '<div class="ne-sub">No new notifications right now</div>' +
                '</div>';

            const notifItemHTML = function (item) {
                const iconClass = iconMap[item.type] || 'bi-bell';
                return '<a href="' + esc(item.link) + '" class="notification-item ' + esc(item.type) + '"' +
                    (item.key ? ' data-key="' + esc(item.key) + '"' : '') + '>' +
                    '<div class="notification-icon"><i class="bi ' + iconClass + '"></i></div>' +
                    '<div class="notification-item-text">' +
                        '<div class="notification-item-title">' + esc(item.title) + '</div>' +
                        '<div class="notification-item-message">' + esc(item.message) + '</div>' +
                        (item.time_label ? '<div class="notification-item-time"><i class="bi bi-clock"></i>' + esc(item.time_label) + '</div>' : '') +
                    '</div>' +
                    '<span class="notification-dot"></span>' +
                '</a>';
            };

            const renderNotifHeader = function (count) {
                return '<div class="notification-dropdown-header">' +
                    '<div class="nd-title">Notifications' +
                    (count > 0 ? '<span class="nd-count">' + count + ' new</span>' : '') +
                    '</div>' +
                    '<a href="my_bookings.php" class="nd-link">View All</a>' +
                    '</div>';
            };

            const renderNotifList = function (items) {
                return (items && items.length) ? items.map(notifItemHTML).join('') : notifEmptyHTML;
            };

            dropdown.innerHTML = renderNotifHeader(notifData.count) +
                '<div class="notification-list">' + renderNotifList(notifData.items) + '</div>';
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
                    localStorage.setItem('themeManual', '1');
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

            // --- Live notification polling (no page refresh needed) ---
            const customerNotifKnown = {};
            (notifData.items || []).forEach(function (it) {
                if (it.key) customerNotifKnown[it.key] = true;
            });

            const setCustomerNotifCount = function (count) {
                let badge = bell.querySelector('.badge');
                if (count > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'badge';
                        bell.appendChild(badge);
                    }
                    badge.textContent = count;
                    badge.style.display = '';
                } else if (badge) {
                    badge.remove();
                }

                const ndTitle = dropdown.querySelector('.nd-title');
                let ndCount = dropdown.querySelector('.nd-count');
                if (count > 0) {
                    if (!ndCount && ndTitle) {
                        ndCount = document.createElement('span');
                        ndCount.className = 'nd-count';
                        ndTitle.appendChild(ndCount);
                    }
                    if (ndCount) ndCount.textContent = count + ' new';
                } else if (ndCount) {
                    ndCount.remove();
                }
            };

            const setNavBadge = function (href, count) {
                const link = document.querySelector('.sidebar-nav-item[href="' + href + '"]');
                if (!link) return;
                let badge = link.querySelector('.badge');
                if (count > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'badge';
                        link.appendChild(badge);
                    }
                    badge.textContent = count;
                } else if (badge) {
                    badge.remove();
                }
            };

            // Raise an Android notification via @capacitor/local-notifications when
            // running inside the app WebView. Silently no-ops in normal browsers.
            var nativeNotifPerm = null;
            var fireNativeNotif = function (title, body) {
                try {
                    var LN = window.Capacitor && window.Capacitor.Plugins &&
                             window.Capacitor.Plugins.LocalNotifications;
                    if (!LN || !LN.schedule) return;
                    var doSchedule = function () {
                        LN.schedule({
                            notifications: [{
                                id: Math.floor(Math.random() * 2000000000),
                                title: title,
                                body: body,
                                schedule: { at: new Date(Date.now() + 200) }
                            }]
                        }).catch(function () {});
                    };
                    if (nativeNotifPerm === 'granted') { doSchedule(); return; }
                    if (nativeNotifPerm === 'denied') return;
                    LN.requestPermissions().then(function (p) {
                        nativeNotifPerm = (p && p.display) || 'denied';
                        if (nativeNotifPerm === 'granted') doSchedule();
                    }).catch(function () {});
                } catch (e) {}
            };

            const pollCustomerNotifications = function () {
                if (document.hidden) return;
                fetch('api/get_customer_notifications.php', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                })
                .then(function (res) { return res.ok ? res.json() : null; })
                .then(function (data) {
                    if (!data || !data.success) return;
                    const items = data.items || [];
                    const list = dropdown.querySelector('.notification-list');
                    const isOpen = dropdown.classList.contains('show');
                    const newItems = items.filter(function (it) {
                        return it.key && !customerNotifKnown[it.key];
                    });

                    if (!isOpen && list) {
                        list.innerHTML = renderNotifList(items);
                    } else if (newItems.length && list) {
                        const empty = list.querySelector('.notification-empty');
                        if (empty) empty.remove();
                        newItems.slice().reverse().forEach(function (it) {
                            list.insertAdjacentHTML('afterbegin', notifItemHTML(it));
                        });
                    }

                    items.forEach(function (it) {
                        if (it.key) customerNotifKnown[it.key] = true;
                    });

                    newItems.forEach(function (it) {
                        if (typeof window.showToast === 'function') {
                            window.showToast(
                                it.title + (it.message ? ' \u2014 ' + it.message : ''),
                                (it.type === 'part' || it.type === 'emergency') ? 'warning' : 'info',
                                6000
                            );
                        }
                    });

                    // Let pages react to fresh notifications (e.g. dashboard popup)
                    if (newItems.length) {
                        document.dispatchEvent(new CustomEvent('customerNotificationsUpdated', {
                            detail: { newItems: newItems, items: items }
                        }));

                        // Inside the Capacitor app, also raise a phone-tray
                        // notification for each fresh item (no-op in browsers).
                        newItems.forEach(function (it) {
                            fireNativeNotif(it.title || 'Notification', it.message || '');
                        });
                    }

                    notifData.items = items;
                    notifData.count = parseInt(data.count, 10) || 0;
                    setCustomerNotifCount(notifData.count);
                    setNavBadge('my_bookings.php', parseInt(data.unread_bookings, 10) || 0);
                    setNavBadge('customer_health_score.php', parseInt(data.unread_health, 10) || 0);
                })
                .catch(function () { /* network hiccup — try again next tick */ });
            };

            setInterval(pollCustomerNotifications, 15000);
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) pollCustomerNotifications();
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