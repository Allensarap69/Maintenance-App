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
require_once __DIR__ . '/admin_notifications_helper.php';

$adminName = $_SESSION['username'] ?? 'Admin';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
require 'notification_helper.php';

// Sidebar badges + header bell items (shared with api/get_admin_notifications.php)
$adminNotif = fetch_admin_notifications($pdo);
$pendingCount = $adminNotif['pending_bookings'];
$emergencyCount = $adminNotif['pending_emergencies'];
$adminNotificationItems = $adminNotif['items'];
$adminNotificationCount = $adminNotif['count'];
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
    <!-- Top page-loading line + spinner overlay (shows while navigating between modules) -->
    <style>
        #pageLoadingBar {
            position: fixed;
            top: 0;
            left: 0;
            height: 2px;
            width: 0%;
            background: #FACC15;
            box-shadow: 0 0 6px rgba(250, 204, 21, 0.8);
            z-index: 999999;
            opacity: 0;
            pointer-events: none;
            transition: width .3s ease, opacity .3s ease;
        }
        #pageLoadingBar.active { opacity: 1; }

        #pageLoader {
            position: fixed;
            inset: 0;
            z-index: 999998;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 14px;
            background: rgba(248, 250, 252, 0.72);
            -webkit-backdrop-filter: blur(4px);
            backdrop-filter: blur(4px);
            opacity: 0;
            visibility: hidden;
            transition: opacity .18s ease, visibility .18s ease;
            pointer-events: none;
        }
        [data-theme="dark"] #pageLoader { background: rgba(15, 23, 42, 0.72); }
        #pageLoader.active {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        .page-loader-spinner {
            width: 46px;
            height: 46px;
            border: 4px solid rgba(250, 204, 21, 0.25);
            border-top-color: #FACC15;
            border-radius: 50%;
            animation: pageLoaderSpin .8s linear infinite;
        }
        .page-loader-text {
            font-family: 'Poppins', sans-serif;
            font-size: .78rem;
            font-weight: 500;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #64748b;
        }
        [data-theme="dark"] .page-loader-text { color: #94a3b8; }
        @keyframes pageLoaderSpin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) {
            .page-loader-spinner { animation-duration: 1.6s; }
        }
    </style>
    <div id="pageLoadingBar"></div>
    <div id="pageLoader" role="status" aria-label="Loading">
        <div class="page-loader-spinner"></div>
        <div class="page-loader-text">Loading</div>
    </div>
    <script>
    (function () {
        var bar = document.getElementById('pageLoadingBar');
        var loader = document.getElementById('pageLoader');
        if (!bar) return;
        var timer = null, failsafe = null, hideTimer = null, cancelCheck = null, pct = 0;
        var unloading = false;

        function showLoader() { if (loader) loader.classList.add('active'); }
        function hideLoader() { if (loader) loader.classList.remove('active'); }

        function showAt(v) {
            clearTimeout(hideTimer);
            bar.classList.add('active');
            pct = v;
            bar.style.width = pct + '%';
        }

        function creep(cap) {
            clearInterval(timer);
            timer = setInterval(function () {
                pct += Math.random() * 15;
                if (pct >= cap) { pct = cap; clearInterval(timer); }
                bar.style.width = pct + '%';
            }, 150);
        }

        function hide() {
            clearInterval(timer);
            clearTimeout(failsafe);
            bar.classList.remove('active');
            hideLoader();
            hideTimer = setTimeout(function () { bar.style.width = '0%'; }, 300);
        }

        // Bar reaches 100% only when the page (and its content) is fully loaded
        function finish() {
            clearInterval(timer);
            clearTimeout(failsafe);
            bar.classList.add('active');
            bar.style.width = '100%';
            hideLoader();
            hideTimer = setTimeout(hide, 300);
        }

        // Outgoing page: show bar + spinner when an internal link/form navigates
        function start() {
            showAt(30);
            creep(90);
            showLoader();
            clearTimeout(cancelCheck);
            clearTimeout(failsafe);
            // If no beforeunload follows, the navigation was cancelled client-side
            // (e.g. onclick="return confirm(...)" declined) — reset the indicators.
            cancelCheck = setTimeout(function () {
                if (!unloading) hide();
            }, 500);
            failsafe = setTimeout(hide, 8000); // no navigation happened — reset
        }

        document.addEventListener('click', function (e) {
            var a = e.target.closest('a[href]');
            if (!a) return;
            var href = a.getAttribute('href');
            if (!href || href.charAt(0) === '#' || a.target === '_blank' ||
                /^(javascript:|tel:|mailto:)/i.test(href) || a.hasAttribute('download')) return;
            if (a.hostname && a.hostname !== location.hostname) return;
            start();
        }, true);

        document.addEventListener('submit', function (e) {
            if (e.target && e.target.target === '_blank') return;
            start();
        }, true);

        window.addEventListener('beforeunload', function () {
            unloading = true;
            start();
        });

        // Incoming page: spinner covers the parse; progress follows real load stages
        if (document.readyState === 'loading') {
            showAt(15);
            creep(70); // still parsing HTML/resources
            showLoader();
            document.addEventListener('DOMContentLoaded', function () {
                showAt(85); // DOM ready — content is now displaying
                creep(95);
                hideLoader();
            });
        } else if (document.readyState === 'interactive') {
            showAt(85);
            creep(95);
        } else {
            showAt(95);
        }

        if (document.readyState === 'complete') {
            finish();
        } else {
            window.addEventListener('load', finish);
        }
        failsafe = setTimeout(hide, 12000); // never leave the indicators stuck

        // Reset if the page is restored from bfcache (back/forward buttons)
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) {
                unloading = false;
                clearInterval(timer);
                clearTimeout(failsafe);
                clearTimeout(hideTimer);
                clearTimeout(cancelCheck);
                bar.classList.remove('active');
                bar.style.width = '0%';
                hideLoader();
            }
        });
    })();
    </script>

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
                <img src="eversure-logo.png" alt="Mindanao Eversure" style="height: 40px; width: auto; flex: none;">
                <span style="white-space: nowrap; font-size: 1rem; line-height: 1.2;">
                    Mindanao Eversure
                    <small style="display: block; font-size: .55rem; letter-spacing: .12em; white-space: nowrap; color: #FACC15; line-height: 1;">MOTORCYCLE SERVICE</small>
                </span>
            </a>
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
                <div class="menu-title">Management</div>
                <a href="admin_inventory.php" class="menu-item <?= $currentPage == 'admin_inventory' ? 'active' : '' ?>" data-tooltip="Inventory">
                    <i data-lucide="boxes"></i>
                    <span>Inventory</span>
                </a>
                <a href="admin_invoices.php" class="menu-item <?= $currentPage == 'admin_invoices' ? 'active' : '' ?>" data-tooltip="Invoices &amp; Payments">
                    <i data-lucide="receipt"></i>
                    <span>Invoices &amp; Payments</span>
                </a>
                <a href="admin_receipts.php" class="menu-item <?= $currentPage == 'admin_receipts' ? 'active' : '' ?>" data-tooltip="Receipts">
                    <i data-lucide="file-image"></i>
                    <span>Receipts</span>
                </a>
                <a href="reports.php" class="menu-item <?= $currentPage == 'reports' ? 'active' : '' ?>" data-tooltip="Reports">
                    <i data-lucide="bar-chart-3"></i>
                    <span>Reports</span>
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
                <div class="menu-title">System</div>
                <a href="admin_audit_log.php" class="menu-item <?= $currentPage == 'admin_audit_log' ? 'active' : '' ?>" data-tooltip="Audit Log">
                    <i data-lucide="scroll-text"></i>
                    <span>Audit Log</span>
                </a>
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
            <img src="eversure-logo.png" alt="Mindanao Eversure" style="height: 22px; width: auto; flex: none;">
            <span style="display:flex;flex-direction:column;line-height:1.1;">Mindanao Eversure <small style="font-size:.55rem;letter-spacing:.12em;color:#FACC15;">MOTORCYCLE SERVICE</small></span>
        </div>
        <div class="sidebar-footer-copyright">
            © <?= date('Y') ?> All rights reserved
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
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

                // --- Live notification polling (no page refresh needed) ---
                var adminNotifIcons = <?= json_encode($adminNotifIcons) ?>;
                var adminNotifList = notifDropdown.querySelector('.notification-list');
                var adminNotifKnown = {};
                var adminNotifEmptyHTML =
                    '<div class="notification-empty"><i class="bi bi-bell-slash"></i>' +
                    '<div class="ne-title">All caught up</div>' +
                    '<div class="ne-sub">No new notifications right now</div></div>';

                notifDropdown.querySelectorAll('.notification-item[data-key]').forEach(function (el) {
                    adminNotifKnown[el.getAttribute('data-key')] = true;
                });

                var escNotif = function (s) {
                    return String(s == null ? '' : s)
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
                        .replace(/'/g, '&#39;');
                };

                var notifItemHTML = function (item) {
                    var icon = adminNotifIcons[item.type] || 'bi-bell';
                    return '<a href="' + escNotif(item.link) + '" class="notification-item ' + escNotif(item.type) + '"' +
                        (item.key ? ' data-key="' + escNotif(item.key) + '"' : '') + '>' +
                        '<div class="notification-icon"><i class="bi ' + icon + '"></i></div>' +
                        '<div class="notification-item-text">' +
                            '<div class="notification-item-title">' + escNotif(item.title) + '</div>' +
                            '<div class="notification-item-message">' + escNotif(item.message) + '</div>' +
                            (item.time_label ? '<div class="notification-item-time"><i class="bi bi-clock"></i>' + escNotif(item.time_label) + '</div>' : '') +
                        '</div>' +
                        '<span class="notification-dot"></span>' +
                    '</a>';
                };

                var setAdminNotifCount = function (count) {
                    var badge = document.getElementById('adminNotifBadge');
                    if (count > 0) {
                        if (!badge) {
                            badge = document.createElement('span');
                            badge.className = 'badge';
                            badge.id = 'adminNotifBadge';
                            notifBell.appendChild(badge);
                        }
                        badge.textContent = count;
                        badge.style.display = '';
                    } else if (badge) {
                        badge.remove();
                    }

                    var ndTitle = notifDropdown.querySelector('.nd-title');
                    var ndCount = notifDropdown.querySelector('.nd-count');
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

                var setMenuBadge = function (submenuId, count) {
                    var btn = document.querySelector('.menu-parent[data-submenu="' + submenuId + '"]');
                    if (!btn) return;
                    var badge = btn.querySelector('.menu-badge');
                    if (count > 0) {
                        if (!badge) {
                            badge = document.createElement('span');
                            badge.className = 'menu-badge';
                            btn.insertBefore(badge, btn.querySelector('.menu-caret'));
                        }
                        badge.textContent = count;
                    } else if (badge) {
                        badge.remove();
                    }
                };

                var pollAdminNotifications = function () {
                    if (document.hidden) return;
                    fetch('api/get_admin_notifications.php', {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin'
                    })
                    .then(function (res) { return res.ok ? res.json() : null; })
                    .then(function (data) {
                        if (!data || !data.success || !adminNotifList) return;
                        var items = data.items || [];
                        var isOpen = notifDropdown.classList.contains('show');
                        var newItems = items.filter(function (it) {
                            return it.key && !adminNotifKnown[it.key];
                        });

                        if (!isOpen) {
                            adminNotifList.innerHTML = items.length
                                ? items.map(notifItemHTML).join('')
                                : adminNotifEmptyHTML;
                        } else if (newItems.length) {
                            var empty = adminNotifList.querySelector('.notification-empty');
                            if (empty) empty.remove();
                            newItems.slice().reverse().forEach(function (it) {
                                adminNotifList.insertAdjacentHTML('afterbegin', notifItemHTML(it));
                            });
                        }

                        items.forEach(function (it) {
                            if (it.key) adminNotifKnown[it.key] = true;
                        });

                        newItems.forEach(function (it) {
                            if (typeof window.showToast === 'function') {
                                window.showToast(
                                    it.title + (it.message ? ' \u2014 ' + it.message : ''),
                                    it.type === 'emergency' ? 'warning' : 'info',
                                    6000
                                );
                            }
                        });

                        setAdminNotifCount(parseInt(data.count, 10) || 0);
                        setMenuBadge('submenuBookings', parseInt(data.pending_bookings, 10) || 0);
                        setMenuBadge('submenuEmergency', parseInt(data.pending_emergencies, 10) || 0);
                    })
                    .catch(function () { /* network hiccup — try again next tick */ });
                };

                setInterval(pollAdminNotifications, 15000);
                document.addEventListener('visibilitychange', function () {
                    if (!document.hidden) pollAdminNotifications();
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
            <button class="btn-toggle-sidebar" title="Toggle Sidebar" onclick="toggleSidebar()">
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
                                <a href="<?= htmlspecialchars($item['link']) ?>" class="notification-item <?= htmlspecialchars($item['type']) ?>"<?= !empty($item['key']) ? ' data-key="' . htmlspecialchars($item['key']) . '"' : '' ?>>
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