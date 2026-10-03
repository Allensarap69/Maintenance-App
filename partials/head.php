<?php
/**
 * Shared <head> for portal sidebar templates (admin + mechanic).
 * Emits <!DOCTYPE>, <html>, and everything inside <head> that is
 * common to both portals. The including template keeps its own
 * </head>/<body> and may add page-specific assets after the include.
 *
 * Variables (set before requiring):
 *   $portalTitle - fallback title when $pageTitle is unset ('Admin'|'Mechanic')
 *   $portalCss   - href of the portal's sidebar stylesheet (optional)
 */
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? $pageTitle : ($portalTitle ?? 'Dashboard') ?> | Mindanao Eversure</title>
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="fonts.css">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/ui.css">
    <script src="assets/js/pager.js?v=<?= @filemtime(__DIR__ . '/../assets/js/pager.js') ?>" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <?php if (!empty($portalCss)): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($portalCss) ?>?v=<?= @filemtime(__DIR__ . '/../' . $portalCss) ?>">
    <?php endif; ?>
