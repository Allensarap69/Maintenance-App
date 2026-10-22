<?php
session_start();
require 'db.php';

// --- Ensure payment_settings table exists + seed defaults (idempotent) ---
$default_emv_payload = '00020101021127830012com.p2pqrpay0111GXCHPHM2XXX02089996440303152170200000006560417DWQM4TK3JDO7CBK0B5204601653036085802PH5910NO****A G.6007Glamang61041234630489FE';
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_settings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            gcash_name VARCHAR(100) NOT NULL DEFAULT '',
            gcash_number VARCHAR(20) NOT NULL DEFAULT '',
            qr_image_path VARCHAR(255) NOT NULL DEFAULT 'g.png.jpg',
            emv_payload TEXT NULL,
            deposit_percentage DECIMAL(5,2) NOT NULL DEFAULT 50.00,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->prepare("
        INSERT IGNORE INTO payment_settings
            (id, gcash_name, gcash_number, qr_image_path, emv_payload, deposit_percentage)
        VALUES (1, '', '', 'g.png.jpg', ?, 50.00)
    ")->execute([$default_emv_payload]);
} catch (PDOException $e) {
    error_log("payment_settings ensure error: " . $e->getMessage());
}

// Security check: Only admins can access this page
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$msg = "";
$msg_type = "";

// --- Save Payment Settings ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment_settings'])) {
    $gcash_name = trim($_POST['gcash_name'] ?? '');
    $gcash_number = trim($_POST['gcash_number'] ?? '');
    $deposit_pct = trim($_POST['deposit_percentage'] ?? '50');
    $emv_payload = trim($_POST['emv_payload'] ?? '');

    $errors = [];

    if (strlen($gcash_name) > 100) {
        $errors[] = "GCash account name is too long (max 100 characters).";
    }
    if ($gcash_number !== '' && !preg_match('/^[0-9+\-\s()]{5,20}$/', $gcash_number)) {
        $errors[] = "GCash number looks invalid (digits, spaces, +, -, () only).";
    }
    if (!is_numeric($deposit_pct) || $deposit_pct < 1 || $deposit_pct > 100) {
        $errors[] = "Deposit percentage must be between 1 and 100.";
    }
    if ($emv_payload !== '' && !preg_match('/^00020101\d{2}/', $emv_payload)) {
        $errors[] = "The QR payload does not look like a valid EMV/QR Ph payload.";
    }

    // --- QR image upload ---
    $new_qr_path = null;
    if (empty($errors) && !empty($_FILES['qr_image']['name'])) {
        $target_dir = 'uploads/qr/';
        if (!is_dir($target_dir) && !mkdir($target_dir, 0775, true)) {
            $errors[] = "Failed to create upload directory.";
        } else {
            $ext = strtolower(pathinfo($_FILES['qr_image']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
                $errors[] = "QR image must be a JPG or PNG file.";
            } elseif ($_FILES['qr_image']['size'] > 5 * 1024 * 1024) {
                $errors[] = "QR image is too large (max 5MB).";
            } elseif (!@getimagesize($_FILES['qr_image']['tmp_name'])) {
                $errors[] = "Uploaded file is not a valid image.";
            } else {
                $new_qr_path = $target_dir . 'qr_' . uniqid() . '.' . $ext;
                if (!move_uploaded_file($_FILES['qr_image']['tmp_name'], $new_qr_path)) {
                    $errors[] = "Failed to save the uploaded QR image.";
                    $new_qr_path = null;
                }
            }
        }
    }

    if (empty($errors)) {
        try {
            $old_qr_path = $pdo->query("SELECT qr_image_path FROM payment_settings WHERE id = 1")
                               ->fetchColumn();

            $stmt = $pdo->prepare("
                UPDATE payment_settings
                SET gcash_name = ?, gcash_number = ?, qr_image_path = ?,
                    emv_payload = ?, deposit_percentage = ?
                WHERE id = 1
            ");
            $stmt->execute([
                $gcash_name,
                $gcash_number,
                $new_qr_path ?? $old_qr_path,
                ($emv_payload !== '' ? $emv_payload : null),
                $deposit_pct
            ]);

            // Remove the previously uploaded QR file (don't touch the default g.png.jpg)
            if ($new_qr_path && $old_qr_path && strpos($old_qr_path, 'uploads/qr/') === 0 && file_exists($old_qr_path)) {
                unlink($old_qr_path);
            }

            $msg = "✅ Payment settings updated successfully.";
            $msg_type = "success";
        } catch (PDOException $e) {
            if ($new_qr_path && file_exists($new_qr_path)) unlink($new_qr_path);
            $msg = "❌ Error saving payment settings.";
            $msg_type = "error";
            error_log("payment_settings update error: " . $e->getMessage());
        }
    } else {
        if ($new_qr_path && file_exists($new_qr_path)) unlink($new_qr_path);
        $msg = "❌ " . implode("<br>", $errors);
        $msg_type = "error";
    }
}

// --- Load current settings ---
$settings = [
    'gcash_name' => '',
    'gcash_number' => '',
    'qr_image_path' => 'g.png.jpg',
    'emv_payload' => $default_emv_payload,
    'deposit_percentage' => '50.00',
];
try {
    $row = $pdo->query("SELECT * FROM payment_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) $settings = array_merge($settings, $row);
} catch (PDOException $e) {
    error_log("payment_settings fetch error: " . $e->getMessage());
}

$deposit_pct_label = rtrim(rtrim(number_format((float)$settings['deposit_percentage'], 2, '.', ''), '0'), '.');
$amount_lock_active = !empty($settings['emv_payload']);

$pageTitle = 'Payment Settings';
require 'admin_sidebar_template.php';
?>

<style>
    .main-content { padding-top: 75px !important; }

    /* --- Page head --- */
    .ps-breadcrumb { font-size: 0.75rem; color: #9ca3af; margin-bottom: 4px; }
    .ps-breadcrumb .sep { margin: 0 6px; }
    .ps-page-title { font-size: 1.5rem; font-weight: 700; color: #111827; margin-bottom: 2px; }
    .ps-page-desc { font-size: 0.82rem; color: #6b7280; margin-bottom: 18px; }

    /* --- Card --- */
    .settings-card {
        background: var(--bg-card, #fff);
        border: 1px solid #E2E8F0;
        border-radius: 16px;
        box-shadow: 0 4px 24px rgba(15,23,42,0.06);
        padding: 22px 24px;
        color: var(--text-dark, #111827);
    }
    .ps-header {
        border-bottom: 1px solid #E2E8F0;
        padding-bottom: 16px;
        margin-bottom: 18px;
    }
    .ps-icon {
        width: 46px; height: 46px; border-radius: 13px;
        background: linear-gradient(135deg, #fb923c, #f97316);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.35rem; color: #fff;
        box-shadow: 0 5px 14px rgba(249,115,22,0.35);
        flex-shrink: 0;
    }
    .ps-card-title { font-size: 1rem; font-weight: 700; }
    .ps-card-sub { font-size: 0.78rem; color: #6b7280; }
    .btn-save {
        background: #f97316; border: none; color: #fff;
        font-size: 0.85rem; font-weight: 600;
        padding: 9px 18px; border-radius: 10px;
        display: inline-flex; align-items: center; gap: 7px;
        transition: background .15s ease, transform .15s ease;
        white-space: nowrap;
    }
    .btn-save:hover { background: #ea580c; color: #fff; transform: translateY(-1px); }

    /* --- Form --- */
    .settings-card .form-label { font-size: 0.8rem; font-weight: 600; color: #374151; margin-bottom: 0.3rem; }
    .settings-card .form-control {
        font-size: 0.85rem; padding: 9px 13px;
        border: 1px solid #d1d5db; border-radius: 10px; color: #111827;
    }
    .settings-card .form-control::placeholder { color: #9ca3af; }
    .settings-card .form-text { font-size: 0.74rem; line-height: 1.4; color: #6b7280; }
    .settings-card .input-group-text {
        font-size: 0.85rem; padding: 9px 13px;
        background: #f9fafb; color: #6b7280;
        border: 1px solid #d1d5db; border-radius: 0 10px 10px 0;
    }
    .settings-card .form-control:focus {
        border-color: #f97316;
        box-shadow: 0 0 0 3px rgba(249,115,22,0.18);
    }

    /* --- Dropzone + current QR --- */
    .qr-dropzone {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 3px; text-align: center; cursor: pointer;
        border: 2px dashed #d1d5db; border-radius: 12px;
        background: #fafbfc; padding: 16px 10px; min-height: 118px;
        transition: border-color .15s ease, background .15s ease;
    }
    .qr-dropzone:hover { border-color: #f97316; background: #fff7ed; }
    .qr-dropzone i { font-size: 1.55rem; color: #9ca3af; transition: color .15s ease; }
    .qr-dropzone:hover i { color: #f97316; }
    .qr-dropzone .dz-main { font-size: 0.8rem; font-weight: 700; color: #374151; }
    .qr-dropzone .dz-sub { font-size: 0.7rem; color: #9ca3af; }
    .qr-dropzone .qr-file-name {
        font-size: 0.72rem; font-weight: 600; color: #f97316;
        word-break: break-all; display: none; margin-top: 2px;
    }
    .qr-current-box {
        border: 1px solid #e5e7eb; border-radius: 12px;
        padding: 10px; text-align: center; background: #fff;
    }
    .qr-current-box img {
        max-width: 104px; width: 100%; height: auto; display: inline-block;
    }

    /* --- Payload + banners --- */
    #emv_payload {
        font-family: monospace; font-size: 0.72rem !important;
        word-break: break-all; background: #f8fafc;
    }
    .payload-status {
        display: flex; align-items: center; gap: 8px;
        border-radius: 10px; padding: 10px 14px;
        font-size: 0.8rem; font-weight: 600; border: 1px solid;
    }
    .payload-status.ok { background: rgba(16,185,129,0.08); border-color: #a7f3d0; color: #047857; }
    .payload-status.warn { background: rgba(245,158,11,0.08); border-color: #fcd34d; color: #b45309; }
    .info-banner {
        display: flex; gap: 8px; align-items: flex-start;
        border-radius: 10px; padding: 10px 14px;
        font-size: 0.75rem; line-height: 1.45;
        background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af;
    }
    .info-banner i { color: #3b82f6; margin-top: 1px; flex-shrink: 0; }

    html[data-theme="dark"] .ps-page-title { color: #f1f5f9; }
    html[data-theme="dark"] .ps-page-desc,
    html[data-theme="dark"] .ps-card-sub { color: #94a3b8; }
    html[data-theme="dark"] .settings-card { background: #141b30; border-color: rgba(255,255,255,0.09); color: #e2e8f0; }
    html[data-theme="dark"] .ps-header { border-color: rgba(255,255,255,0.09); }
    html[data-theme="dark"] .settings-card .form-label { color: #cbd5e1; }
    html[data-theme="dark"] .settings-card .form-control { background: #161e33; border-color: #2a3350; color: #e2e8f0; }
    html[data-theme="dark"] .settings-card .input-group-text { background: #0f1526; border-color: #2a3350; color: #94a3b8; }
    html[data-theme="dark"] .qr-dropzone { background: #0f1526; border-color: #2a3350; }
    html[data-theme="dark"] .qr-dropzone .dz-main { color: #cbd5e1; }
    html[data-theme="dark"] .qr-current-box { background: #0f1526; border-color: #2a3350; }
    html[data-theme="dark"] #emv_payload { background: #0f1526; }
    html[data-theme="dark"] .info-banner { background: rgba(59,130,246,0.12); border-color: rgba(147,197,253,0.25); color: #93c5fd; }
    html[data-theme="dark"] .payload-status.ok { background: rgba(16,185,129,0.12); border-color: rgba(52,211,153,0.3); color: #34d399; }
    html[data-theme="dark"] .payload-status.warn { background: rgba(245,158,11,0.12); border-color: rgba(252,211,77,0.3); color: #fcd34d; }
</style>

<div>
    <div class="content-wrapper">
        <div class="container-fluid">

            <!-- Page head -->
            <div class="ps-breadcrumb">Settings <span class="sep">/</span> <span class="fw-semibold">Payments</span></div>
            <h2 class="ps-page-title">Payment Settings</h2>
            <p class="ps-page-desc">Configure your GCash payment details and QR code settings for customer payments.</p>

            <?php if ($msg): ?>
                <div class="alert alert-<?= $msg_type === 'success' ? 'success' : 'danger' ?> fw-bold"><?= $msg ?></div>
            <?php endif; ?>

            <div class="row g-4">
                <!-- Settings Form -->
                <div class="col-12">
                    <div class="settings-card">
                        <div class="ps-header d-flex align-items-center gap-3 flex-wrap">
                            <div class="ps-icon"><i class="bi bi-qr-code"></i></div>
                            <div class="me-auto">
                                <div class="ps-card-title">GCash Payment Settings</div>
                                <div class="ps-card-sub">Set up your GCash account details and QR code for receiving payments from customers.</div>
                            </div>
                            <button type="submit" name="save_payment_settings" form="paymentSettingsForm" class="btn-save">
                                <i class="bi bi-save"></i> Save Changes
                            </button>
                        </div>

                        <form method="POST" action="" enctype="multipart/form-data" id="paymentSettingsForm">
                            <div class="row g-4">
                                <!-- Left column -->
                                <div class="col-lg-5">
                                    <div class="mb-3">
                                        <label class="form-label" for="gcash_name">GCash Account Name</label>
                                        <input type="text" class="form-control" id="gcash_name" name="gcash_name"
                                               maxlength="100" value="<?= htmlspecialchars($settings['gcash_name']) ?>"
                                               placeholder="e.g. Juan Dela Cruz">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="gcash_number">GCash Number</label>
                                        <input type="text" class="form-control" id="gcash_number" name="gcash_number"
                                               maxlength="20" value="<?= htmlspecialchars($settings['gcash_number']) ?>"
                                               placeholder="e.g. 0917 123 4567">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="deposit_percentage">Required Deposit (%)</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="deposit_percentage" name="deposit_percentage"
                                                   min="1" max="100" step="0.01" value="<?= htmlspecialchars($deposit_pct_label) ?>" required>
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-sm-7">
                                            <label class="form-label" for="qr_image">QR Code Image</label>
                                            <label class="qr-dropzone" for="qr_image">
                                                <i class="bi bi-upload"></i>
                                                <span class="dz-main">Upload GCash QR Code</span>
                                                <span class="dz-sub">JPG / PNG, max 5 MB</span>
                                                <span class="qr-file-name" id="qr_file_name"></span>
                                            </label>
                                            <input type="file" class="d-none" id="qr_image" name="qr_image" accept=".jpg,.jpeg,.png">
                                        </div>
                                        <div class="col-sm-5">
                                            <label class="form-label">Current QR Code</label>
                                            <div class="qr-current-box">
                                                <img id="current_qr_img" src="<?= htmlspecialchars($settings['qr_image_path']) ?>" alt="Current GCash QR">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right column -->
                                <div class="col-lg-7">
                                    <label class="form-label" for="emv_payload">QR Payload (EMV / QR Ph)</label>
                                    <textarea class="form-control" id="emv_payload" name="emv_payload" rows="5"
                                              placeholder="Auto-filled when you upload a decodable QR image"><?= htmlspecialchars($settings['emv_payload'] ?? '') ?></textarea>
                                    <div class="form-text mt-2 mb-2">
                                        Required for the amount-locked QR — must match the QR image or payment locks to the wrong account.
                                    </div>
                                    <div id="payload_status" class="payload-status <?= $amount_lock_active ? 'ok' : 'warn' ?>">
                                        <i class="bi <?= $amount_lock_active ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
                                        <span><?= $amount_lock_active ? 'Payload detected — deposit amount will be locked to the QR.' : 'No payload — customers will type the amount manually.' ?></span>
                                    </div>
                                    <div class="info-banner mt-3">
                                        <i class="bi bi-info-circle-fill"></i>
                                        <span>Make sure the payload content matches the QR image you uploaded.<br>If they do not match, the payment may be rejected or locked to the wrong account.</span>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>

<script src="assets/js/jsQR.js"></script>
<script>
    var payloadInput = document.getElementById('emv_payload');
    var payloadStatus = document.getElementById('payload_status');

    function setPayloadStatus(ok, text) {
        payloadStatus.className = 'payload-status ' + (ok ? 'ok' : 'warn');
        payloadStatus.innerHTML =
            '<i class="bi ' + (ok ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill') + '"></i>' +
            '<span>' + text + '</span>';
    }

    payloadInput.addEventListener('input', function () {
        var has = (payloadInput.value || '').trim() !== '';
        setPayloadStatus(has, has
            ? 'Payload detected — deposit amount will be locked to the QR.'
            : 'No payload — customers will type the amount manually.');
    });

    // --- Auto-extract the EMV payload when a new QR image is chosen ---
    var fileInput = document.getElementById('qr_image');
    var currentImg = document.getElementById('current_qr_img');
    var fileName = document.getElementById('qr_file_name');
    fileInput.addEventListener('change', function () {
        var file = fileInput.files && fileInput.files[0];
        if (!file) return;
        if (fileName) { fileName.textContent = file.name; fileName.style.display = 'block'; }
        var reader = new FileReader();
        reader.onload = function (e) {
            currentImg.src = e.target.result; // preview the chosen image
            var img = new Image();
            img.onload = function () {
                var canvas = document.createElement('canvas');
                canvas.width = img.naturalWidth;
                canvas.height = img.naturalHeight;
                var ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0);
                try {
                    var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    var code = jsQR(imageData.data, imageData.width, imageData.height);
                    if (code && code.data) {
                        payloadInput.value = code.data;
                        setPayloadStatus(true, 'Payload detected from the new QR image — deposit amount will be locked to the QR.');
                    } else {
                        setPayloadStatus(false, 'Could not decode a QR payload from this image — paste it manually if you have it.');
                    }
                } catch (err) {
                    setPayloadStatus(false, 'Failed to read the image for decoding.');
                }
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });
</script>

<?php require 'admin_sidebar_footer.php'; ?>
