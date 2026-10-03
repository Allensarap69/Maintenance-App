<?php
// Run this once in your browser, then delete this file.
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__DIR__ . '/../dashboard_admin.php', true);
    opcache_invalidate(__DIR__ . '/../admin_sidebar_template.php', true);
    echo "Dashboard cache cleared. <a href='../dashboard_admin.php'>Open dashboard</a>";
} else {
    echo "OPcache is not enabled. Please restart Apache and <a href='../dashboard_admin.php'>open dashboard</a>.";
}
