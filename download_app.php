<?php
/**
 * Serves the Android APK for download.
 * Drop your .apk file into the /downloads folder and it will be served automatically.
 * If a file named app-release.apk exists it is preferred; otherwise the first .apk found is used.
 */

$dir = __DIR__ . DIRECTORY_SEPARATOR . 'downloads';

$apk = $dir . DIRECTORY_SEPARATOR . 'app-release.apk';
if (!is_file($apk)) {
    $matches = glob($dir . DIRECTORY_SEPARATOR . '*.apk') ?: [];
    $apk = $matches[0] ?? null;
}

if (!$apk || !is_file($apk)) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>App Download | Mindanao Eversure</title>
    </head>
    <body>
        <p>The mobile app is not available for download yet. Please check back soon.</p>
        <p><a href="index.php">&larr; Back to homepage</a></p>
    </body>
    </html>
    <?php
    exit;
}

$download_name = 'MindanaoEversure.apk';

header('Content-Description: File Transfer');
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="' . $download_name . '"');
header('Content-Length: ' . filesize($apk));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($apk);
exit;
