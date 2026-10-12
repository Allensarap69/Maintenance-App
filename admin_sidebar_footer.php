        </main>
    </div>

    <!-- Scroll to Top Button -->
    <button class="scroll-top" id="scrollTop" onclick="scrollToTop()">
        <i class="bi bi-arrow-up"></i>
    </button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar Toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (window.innerWidth <= 991) {
                sidebar.classList.toggle('show');
                overlay.classList.toggle('show');
            } else {
                document.body.classList.toggle('collapsed');
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
            }
        }

        // Responsive sidebar state cleanup on resize
        window.addEventListener('resize', () => {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (window.innerWidth <= 991) {
                document.body.classList.remove('collapsed');
            } else {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
            }
        });

        // Scroll to Top
        function scrollToTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Scroll to Top Button Visibility
        const scrollTopBtn = document.getElementById('scrollTop');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 300) {
                scrollTopBtn.classList.add('visible');
            } else {
                scrollTopBtn.classList.remove('visible');
            }
        });

        // Confirm Logout
        function confirmLogout() {
            Swal.fire({
                title: 'Logout?',
                text: 'You will be logged out of the admin panel.',
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

        // Sidebar scroll position persistence
        const sidebar = document.getElementById('sidebar');
        const sidebarMenu = sidebar ? sidebar.querySelector('.sidebar-menu') : null;

        // Sidebar scroll position is restored inline in admin_sidebar_template.php
        // Save sidebar scroll position when scrolling
        if (sidebarMenu) {
            sidebarMenu.addEventListener('scroll', () => {
                localStorage.setItem('adminSidebarScrollPosition', sidebarMenu.scrollTop);
            });
        }

        // Card scroll/fade animation removed to prevent navigation flicker
    </script>
    <style>
        :root {
            --primary-color: #3b82f6;
            --primary-gradient: linear-gradient(135deg, #FACC15 0%, #FDE047 100%);
            --primary-dark: #EAB308;
            --accent-color: #3b82f6;
            --accent-cyan: #06B6D4;
            --accent-cyan-dark: #0891B2;
            --bg-light: #F8FAFC;
            --bg-card: #ffffff;
            --bg-darker: #ffffff;
            --text-dark: #111827;
            --text-light: #6B7280;
            --text-muted: #6B7280;
            --border-color: #E2E8F0;
            --success: #10b981;
            --danger: #ef4444;
            --info: #06B6D4;
            --warning: #FACC15;
            --accent-gradient: linear-gradient(135deg, #FACC15 0%, #FDE047 100%);
        }
        body { background: var(--bg-light); color: var(--text-dark); font-family: 'Poppins', sans-serif !important; }
        .bg-animation, .floating-tools, .header-floating-icons { display: none; }
        .sidebar-brand span { color: var(--text-dark); }

        .sidebar { background: var(--bg-light); }
        .sidebar-header { background: transparent; }
        .menu-title { background: transparent; color: rgba(30, 41, 59, 0.4); }
        .menu-item { color: var(--text-dark); }
        .sidebar-brand { color: var(--text-dark); }
        .user-name { color: var(--text-dark); }
        .user-role { color: rgba(30, 41, 59, 0.5); }
        .btn-logout-sm { color: rgba(30, 41, 59, 0.5); }
        .user-profile { background: rgba(30, 41, 59, 0.05); }
        .user-profile:hover { background: rgba(30, 41, 59, 0.08); }
        .sidebar-footer { border-top: 1px solid rgba(30, 41, 59, 0.1); }
        .sidebar::-webkit-scrollbar-thumb, .sidebar-menu::-webkit-scrollbar-thumb { background: rgba(30, 41, 59, 0.2); }

        .sidebar::-webkit-scrollbar, .sidebar-menu::-webkit-scrollbar { width: 0; height: 0; }
        .sidebar, .sidebar-menu { scrollbar-width: none; }
        .sidebar-brand i { background: none !important; -webkit-background-clip: initial !important; background-clip: initial !important; color: #EAB308 !important; }
        .menu-item:hover, .menu-item.active { color: #0f172a; background: rgba(15,23,42,0.06); }
        .menu-item::before { background: var(--accent-gradient) !important; }
        .top-header { background: rgba(255,255,255,0.95) !important; border-bottom: 2px solid #FACC15 !important; box-shadow: 0 2px 12px rgba(0,0,0,0.04) !important; }
        .top-header .page-title { color: var(--text-dark) !important; }
        .top-header .page-title i { color: #FACC15 !important; background: none !important; -webkit-background-clip: initial !important; background-clip: initial !important; }
        .top-header .text-muted { color: var(--text-dark) !important; }
        .top-header .btn-toggle-sidebar { color: var(--text-dark) !important; }
        .top-header .user-name,
        .top-header .user-role { color: var(--text-dark) !important; }
        .main-content { background: var(--bg-light) !important; }
        .main-content .card { background: var(--bg-card); border: 1px solid #E2E8F0; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); color: var(--text-dark); }
        .main-content .card:hover { box-shadow: 0 10px 30px rgba(0,0,0,0.08); border-color: rgba(30,58,95,0.3); }
        .btn-primary { background: #FACC15 !important; border-color: #FACC15 !important; color: #111827 !important; }
        .btn-primary:hover { background: #EAB308 !important; border-color: #EAB308 !important; color: #111827 !important; }
        .btn-outline-primary { color: var(--primary-color) !important; border-color: var(--primary-color) !important; }
        .btn-outline-primary:hover { background: var(--accent-gradient) !important; color: #fff !important; }
        .form-control:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(30,58,95,0.15); }
        a { color: var(--accent-cyan); }
        a:hover { color: var(--accent-cyan-dark); }
        .text-primary { color: var(--accent-cyan) !important; }
        .scroll-top { background: var(--accent-gradient); }
    </style>
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.js"></script>
    <script src="assets/notifications.js"></script>
    <?php render_notifications(); ?>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script>
        if (typeof lucide !== 'undefined') lucide.createIcons();
    </script>
</body>
</html>
