<?php
session_start();
require __DIR__ . '/../db.php';
require __DIR__ . '/../sms_config.php';
require __DIR__ . '/../sms_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$sms = new SMSHelper();
$result = null;
$phone = '';
$message = 'This is a test SMS from Mindanao Eversure.';
$notificationType = 'TEST_SMS';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
    $phone = trim($_POST['phone'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $notificationType = trim($_POST['notification_type'] ?? 'TEST_SMS');

    if (empty($phone) || empty($message)) {
        $result = ['success' => false, 'message' => 'Phone and message are required.'];
    } else {
        $result = $sms->sendSMS($phone, $message, $notificationType, [
            'user_id' => $_SESSION['user_id'] ?? null,
            'customer_id' => $_SESSION['user_id'] ?? null,
            'notification_key' => 'TEST_' . time() . '_' . ($_SESSION['user_id'] ?? 0)
        ]);
    }
}

$credits = $sms->getCredits();
$pageTitle = 'Test SMS';
require __DIR__ . '/../admin_sidebar_template.php';
?>

<div class="container-fluid py-4">
    <div class="card p-4">
        <h2 class="mb-4 text-primary">Test SMS (Admin Only)</h2>

        <div class="alert alert-info">
            <strong>SMS Enabled:</strong> <?= SMS_ENABLED ? 'Yes' : 'No' ?><br>
            <strong>API Token Configured:</strong> <?= !empty(SMS_API_KEY) ? 'Yes' : 'No' ?><br>
            <strong>SMS Credits:</strong> <?= $credits['success'] ? number_format($credits['credits']) : 'Unable to fetch (' . htmlspecialchars($credits['message'] ?? '') . ')' ?>
        </div>

        <?php if ($result): ?>
            <div class="alert alert-<?= $result['success'] ? 'success' : 'danger' ?>">
                <strong>Status:</strong> <?= $result['success'] ? 'SENT' : 'FAILED' ?><br>
                <strong>Message:</strong> <?= htmlspecialchars($result['message']) ?><br>
                <?php if (!empty($result['provider_message_id'])): ?>
                    <strong>Provider Reference:</strong> <?= htmlspecialchars($result['provider_message_id']) ?><br>
                <?php endif; ?>
                <?php if (!empty($result['error_code'])): ?>
                    <strong>Error Code:</strong> <?= htmlspecialchars($result['error_code']) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="send_test" value="1">
            <div class="mb-3">
                <label class="form-label">Recipient Number</label>
                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($phone) ?>" placeholder="09XXXXXXXXX" required>
                <div class="form-text">Philippine numbers only: 09XXXXXXXXX or +639XXXXXXXXX</div>
            </div>
            <div class="mb-3">
                <label class="form-label">Message</label>
                <textarea name="message" class="form-control" rows="4" required><?= htmlspecialchars($message) ?></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Notification Type</label>
                <select name="notification_type" class="form-select">
                    <option value="TEST_SMS" <?= $notificationType === 'TEST_SMS' ? 'selected' : '' ?>>TEST_SMS</option>
                    <option value="MAINTENANCE_REMINDER" <?= $notificationType === 'MAINTENANCE_REMINDER' ? 'selected' : '' ?>>MAINTENANCE_REMINDER</option>
                    <option value="OVERDUE_MAINTENANCE" <?= $notificationType === 'OVERDUE_MAINTENANCE' ? 'selected' : '' ?>>OVERDUE_MAINTENANCE</option>
                    <option value="APPOINTMENT_CONFIRMATION" <?= $notificationType === 'APPOINTMENT_CONFIRMATION' ? 'selected' : '' ?>>APPOINTMENT_CONFIRMATION</option>
                    <option value="APPOINTMENT_REMINDER" <?= $notificationType === 'APPOINTMENT_REMINDER' ? 'selected' : '' ?>>APPOINTMENT_REMINDER</option>
                    <option value="WARRANTY_EXPIRATION" <?= $notificationType === 'WARRANTY_EXPIRATION' ? 'selected' : '' ?>>WARRANTY_EXPIRATION</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Send Test SMS</button>
            <a href="sms_history.php" class="btn btn-outline-secondary ms-2">View SMS History</a>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../admin_sidebar_footer.php'; ?>