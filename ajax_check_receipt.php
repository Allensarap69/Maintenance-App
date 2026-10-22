<?php
// Pre-submit GCash receipt check. Runs OCR analysis on a temporary copy of the
// upload and returns the verdict JSON; the real file is stored on form submit.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';
header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'customer') {
    http_response_code(403);
    echo json_encode(['verdict' => 'error', 'error' => 'unauthorized']);
    exit;
}

if (empty($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['verdict' => 'error', 'error' => 'no file uploaded']);
    exit;
}

$ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
    echo json_encode(['verdict' => 'error', 'error' => 'invalid file type']);
    exit;
}
if ($_FILES['receipt']['size'] > 10 * 1024 * 1024) {
    echo json_encode(['verdict' => 'error', 'error' => 'file too large']);
    exit;
}

$amount = (float) ($_POST['amount'] ?? 0);
$ref    = preg_replace('/\D/', '', (string) ($_POST['ref'] ?? ''));

$tmp_dir = 'uploads/receipts/';
if (!is_dir($tmp_dir)) {
    mkdir($tmp_dir, 0775, true);
}
$tmp = $tmp_dir . 'precheck_' . uniqid('', true) . '.' . $ext;

if (!move_uploaded_file($_FILES['receipt']['tmp_name'], $tmp)) {
    echo json_encode(['verdict' => 'error', 'error' => 'upload failed']);
    exit;
}

$md5 = md5_file($tmp);
$cmd = 'python ' . escapeshellarg(__DIR__ . '/scripts/receipt_check.py')
     . ' --image ' . escapeshellarg($tmp)
     . ' --amount ' . escapeshellarg((string) $amount)
     . ' --ref ' . escapeshellarg($ref)
     . ' 2>&1';
$raw = shell_exec($cmd);
@unlink($tmp);

$parsed = $raw ? json_decode(trim($raw), true) : null;
if (is_array($parsed)) {
    // Cache by content hash so the submit handler can reuse this verdict
    // instead of running OCR a second time on the stored upload.
    if (!isset($_SESSION['receipt_analysis']) || !is_array($_SESSION['receipt_analysis'])) {
        $_SESSION['receipt_analysis'] = [];
    }
    $_SESSION['receipt_analysis'][$md5] = $parsed;
    while (count($_SESSION['receipt_analysis']) > 5) {
        array_shift($_SESSION['receipt_analysis']);
    }
    echo json_encode($parsed);
} else {
    echo json_encode(['verdict' => 'error', 'error' => 'analysis unavailable']);
}
