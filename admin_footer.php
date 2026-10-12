        </main>
    </div>

    <!-- Scroll to Top Button -->
    <button class="scroll-top" id="scrollTop" onclick="scrollToTop()">
        <i class="bi bi-arrow-up"></i>
    </button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.js"></script>
    <script src="assets/notifications.js"></script>
    <?php render_notifications(); ?>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script>
        if (typeof lucide !== 'undefined') lucide.createIcons();
    </script>
    <script>
        // Sidebar Toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        }

        // Sidebar scroll position persistence
        const sidebar = document.getElementById('sidebar');
        const sidebarMenu = sidebar ? sidebar.querySelector('.sidebar-menu') : null;

        // Restore sidebar scroll position on page load
        window.addEventListener('DOMContentLoaded', () => {
            if (sidebarMenu) {
                const savedScrollPosition = localStorage.getItem('adminSidebarScrollPosition');
                if (savedScrollPosition) {
                    sidebarMenu.scrollTop = parseInt(savedScrollPosition);
                }
            }
        });

        // Save sidebar scroll position when scrolling
        if (sidebarMenu) {
            sidebarMenu.addEventListener('scroll', () => {
                localStorage.setItem('adminSidebarScrollPosition', sidebarMenu.scrollTop);
            });
        }

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

        // Animate elements on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-in');
                }
            });
        }, observerOptions);

        document.querySelectorAll('.stat-card, .nav-card').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(20px)';
            el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            observer.observe(el);
        });

        // Add animate-in class style
        const style = document.createElement('style');
        style.textContent = `
            .animate-in {
                opacity: 1 !important;
                transform: translateY(0) !important;
            }
        `;
        document.head.appendChild(style);
    </script>
    <style>
        :root {
            --primary-color: #1e3a5f;
            --primary-dark: #EAB308;
            --accent-color: #FACC15;
            --accent-cyan: #3b82f6;
            --accent-cyan-dark: #1e3a5f;
            --bg-light: #F8FAFC;
            --bg-card: #ffffff;
            --bg-darker: #ffffff;
            --text-dark: #111827;
            --text-light: #6B7280;
            --text-muted: #6B7280;
            --border-color: #E2E8F0;
            --success: #10b981;
            --danger: #ef4444;
            --info: #3b82f6;
            --warning: #FACC15;
            --sidebar-bg: #F8FAFC;
            --accent-gradient: linear-gradient(135deg, #FACC15 0%, #FDE047 100%);
        }
        body { background: var(--bg-light); color: var(--text-dark); font-family: 'Poppins', sans-serif !important; }
        .bg-animation, .floating-tools, .header-floating-icons { display: none; }
        .sidebar { background: var(--sidebar-bg); }
        .sidebar-brand span { color: #111827; }
        .sidebar-brand i { color: #EAB308 !important; background: none !important; -webkit-background-clip: initial !important; background-clip: initial !important; }
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
        .btn-outline-primary { color: #1e3a5f !important; border-color: #1e3a5f !important; }
        .btn-outline-primary:hover { background: #1e3a5f !important; color: #fff !important; }
        .form-control:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.15); }
        a { color: var(--accent-cyan); }
        a:hover { color: var(--accent-cyan-dark); }
        .text-primary { color: var(--accent-cyan) !important; }
        .scroll-top { background: #FACC15; }
    </style>
</body>
</html>
