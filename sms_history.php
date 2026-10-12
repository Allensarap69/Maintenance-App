<?php
session_start();
require 'db.php'; // Required for admin_sidebar_template.php

// ====================
// Database Connection
// ====================
$conn = mysqli_init();
$db_ssl = getenv('DB_SSL') === '1' || getenv('DB_SSL') === 'true';
if ($db_ssl) {
    $conn->ssl_set(null, null, null, null, null);
}
$conn->real_connect(
    getenv('DB_HOST') ?: "localhost",
    getenv('DB_USER') ?: "root",
    getenv('DB_PASS') !== false ? getenv('DB_PASS') : "",
    getenv('DB_NAME') ?: "maintenance_db",
    (int)(getenv('DB_PORT') ?: 3306),
    null,
    $db_ssl ? MYSQLI_CLIENT_SSL : 0
);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->query("SET time_zone = '+08:00'");

// ====================
// Fetch SMS History with User Info
// ====================
$sql = "
    SELECT 
        s.sms_id,
        u.name AS full_name,
        u.username AS client_username, -- Fetching the client's username
        s.recipient_phone,
        s.message_body,
        s.message_type,
        s.sent_status,
        s.api_message_id,
        s.sent_timestamp
    FROM sms_history s
    LEFT JOIN bookings b ON s.booking_id = b.id
    LEFT JOIN users u ON s.user_id = u.id
    ORDER BY s.sent_timestamp DESC
";

$result = $conn->query($sql);
$pageTitle = 'SMS History';
?>

<?php require 'admin_sidebar_template.php'; ?>

<style>
    table th {
        background-color: #ffffff;
        color: #1e293b !important;
        text-align: center;
        font-size: 0.9rem;
    }
    table td {
        vertical-align: middle;
        text-align: center;
        font-size: 0.9rem;
    }
    .status-sent {
        color: #28a745;
        font-weight: bold;
    }
    .status-failed {
        color: #dc3545;
        font-weight: bold;
    }
    .msg-preview {
        max-width: 300px;
        text-align: left;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .btn-view {
        border: none;
        background: none;
        color: #0d6efd;
        cursor: pointer;
        font-size: 0.8rem;
    }
    .btn-view:hover {
        text-decoration: underline;
    }
    /* Table fits the viewport — scrolls inside the box, header stays visible */
    .table-responsive {
        max-height: calc(100vh - 300px);
        overflow-y: auto;
    }
    .table-responsive thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background-color: #ffffff;
        box-shadow: inset 0 -1px 0 #E2E8F0;
    }

    /* Dark mode */
    html[data-theme="dark"] table th,
    html[data-theme="dark"] .table-responsive thead th {
        background-color: #1a2340;
        color: #e2e8f0 !important;
        box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.09);
    }
    html[data-theme="dark"] .btn-view { color: #60a5fa; }
    html[data-theme="dark"] .status-sent { color: #34d399; }
    html[data-theme="dark"] .status-failed { color: #f87171; }
    html[data-theme="dark"] .badge.bg-info {
        background-color: rgba(59, 130, 246, 0.18) !important;
        color: #93c5fd !important;
    }
</style>

<div class="container-fluid py-4">
    <div class="card p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0 text-primary"><i class="bi bi-chat-dots-fill me-2"></i>SMS History Log</h2>
            <a href="dashboard_admin.php" class="btn btn-outline-primary"><i class="bi bi-arrow-left-circle me-1"></i> Back to Dashboard</a>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        
                        <th>User Name</th>
                        <th>Phone</th>
                        <th>Message</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Sent Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td class="text-muted"><?= htmlspecialchars($row['client_username'] ?: 'N/A') ?></td>
                                <td><?= htmlspecialchars($row['recipient_phone']) ?></td>
                                <td class="msg-preview">
                                    <?= htmlspecialchars($row['message_body']) ?>
                                    <button 
                                        class="btn-view" 
                                        onclick="viewMessage('<?= htmlspecialchars(addslashes($row['message_body']), ENT_QUOTES) ?>', '<?= htmlspecialchars($row['full_name'] ?: $row['recipient_phone'], ENT_QUOTES) ?>')">
                                        [View Full]
                                    </button>
                                </td>
                                <td>
                                    <span class="badge bg-info text-dark"><?= htmlspecialchars($row['message_type']) ?></span>
                                </td>
                                <td>
                                    <?php if (strtoupper($row['sent_status']) === 'SENT'): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle-fill"></i> SENT</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><i class="bi bi-x-circle-fill"></i> FAILED</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars(date('M j, Y H:i', strtotime($row['sent_timestamp']))) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted p-4">No SMS history found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="bi bi-envelope-paper-fill me-2"></i>Full Message</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p id="modalRecipient" class="fw-bold"></p>
        <pre id="modalMessage" style="white-space: pre-wrap; font-family: inherit;"></pre>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function viewMessage(message, name) {
    document.getElementById('modalRecipient').textContent = "To: " + name;
    document.getElementById('modalMessage').textContent = message;
    var myModal = new bootstrap.Modal(document.getElementById('viewModal'));
    myModal.show();
}
</script>

<?php require 'admin_sidebar_footer.php'; ?>
<?php
$conn->close();
?>