<?php
session_start();
require 'db.php';
require 'management_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

ensure_management_tables($pdo);

// --- Filters ---
$f_action   = $_GET['action'] ?? '';
$f_entity   = $_GET['entity'] ?? '';
$f_user     = trim($_GET['user'] ?? '');
$f_from     = $_GET['from'] ?? '';
$f_to       = $_GET['to'] ?? '';

$where  = [];
$params = [];
if ($f_action !== '') { $where[] = "action = ?"; $params[] = $f_action; }
if ($f_entity !== '') { $where[] = "entity = ?"; $params[] = $f_entity; }
if ($f_user !== '')   { $where[] = "username LIKE ?"; $params[] = "%$f_user%"; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) { $where[] = "created_at >= ?"; $params[] = "$f_from 00:00:00"; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to))   { $where[] = "created_at <= ?"; $params[] = "$f_to 23:59:59"; }

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count = $pdo->prepare("SELECT COUNT(*) FROM audit_log $where_sql");
$count->execute($params);
$total = (int)$count->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM audit_log $where_sql ORDER BY id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$actions = $pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$entities = $pdo->query("SELECT DISTINCT entity FROM audit_log WHERE entity != '' ORDER BY entity")->fetchAll(PDO::FETCH_COLUMN);

$action_badge = function ($action) {
    if (str_contains($action, 'fail') || str_contains($action, 'reject') || str_contains($action, 'delete') || str_contains($action, 'void')) return 'bg-danger';
    if (str_contains($action, 'login')) return 'bg-info text-dark';
    if (str_contains($action, 'payment') || str_contains($action, 'invoice')) return 'bg-success';
    if (str_contains($action, 'part') || str_contains($action, 'stock')) return 'bg-warning text-dark';
    return 'bg-secondary';
};

$pageTitle = 'Audit Log';
require 'admin_sidebar_template.php';
?>

<style>
    /* Light mode: white filter bar matching the module cards */
    .audit-log-card .card-header {
        background: #ffffff;
        color: #111827;
        border-bottom: 1px solid #E2E8F0;
    }
    /* Table fits the viewport — scrolls inside the box, header stays visible */
    .audit-log-card .table-responsive {
        max-height: calc(100vh - 305px);
        overflow-y: auto;
    }
    .audit-log-card .table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #ffffff;
        box-shadow: inset 0 -1px 0 #E2E8F0;
    }
    html[data-theme="dark"] .audit-log-card .card-header {
        background: rgba(255, 255, 255, 0.03);
        color: #e2e8f0;
        border-bottom-color: rgba(255, 255, 255, 0.09);
    }
    html[data-theme="dark"] .audit-log-card .table thead th {
        background: #141b30;
        box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.09);
    }
</style>

<div class="container-fluid py-4">
    <div class="card audit-log-card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0">Audit Log <span class="badge bg-secondary"><?= $total ?></span></h5>
            <form method="GET" class="d-flex gap-2 flex-wrap">
                <select name="action" class="form-select form-select-sm" style="width:auto">
                    <option value="">All actions</option>
                    <?php foreach ($actions as $a): ?>
                    <option value="<?= htmlspecialchars($a) ?>" <?= $f_action === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="entity" class="form-select form-select-sm" style="width:auto">
                    <option value="">All entities</option>
                    <?php foreach ($entities as $e): ?>
                    <option value="<?= htmlspecialchars($e) ?>" <?= $f_entity === $e ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="user" class="form-control form-control-sm" style="width:130px" placeholder="User" value="<?= htmlspecialchars($f_user) ?>">
                <input type="date" name="from" class="form-control form-control-sm" style="width:auto" value="<?= htmlspecialchars($f_from) ?>">
                <input type="date" name="to" class="form-control form-control-sm" style="width:auto" value="<?= htmlspecialchars($f_to) ?>">
                <button class="btn btn-sm btn-warning text-dark fw-semibold">Filter</button>
                <a href="admin_audit_log.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            </form>
        </div>
        <div class="card-body">
            <?php if (empty($rows)): ?>
                <p class="text-muted mb-0">No audit entries match. Actions are logged for logins, booking status changes, invoices, payments, and inventory changes.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                    <thead>
                        <tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="text-nowrap"><small><?= date('M j, Y g:i:s A', strtotime($r['created_at'])) ?></small></td>
                            <td>
                                <?= htmlspecialchars($r['username'] ?: '—') ?>
                                <?php if ($r['role']): ?><small class="text-muted">(<?= htmlspecialchars($r['role']) ?>)</small><?php endif; ?>
                            </td>
                            <td><span class="badge <?= $action_badge($r['action']) ?>"><?= htmlspecialchars($r['action']) ?></span></td>
                            <td>
                                <?php if ($r['entity']): ?>
                                    <?= htmlspecialchars($r['entity']) ?><?= $r['entity_id'] ? ' #' . (int)$r['entity_id'] : '' ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td><small><?= htmlspecialchars($r['details'] ?? '') ?></small></td>
                            <td><small class="text-muted"><?= htmlspecialchars($r['ip']) ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require 'admin_footer.php'; ?>
