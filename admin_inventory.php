<?php
session_start();
require 'db.php';
require 'management_helper.php';
require 'pagination_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

ensure_management_tables($pdo);

// Flash message from redirected actions (PRG pattern — same as admin_invoices.php,
// rendered alerts are auto-converted to floating toasts by floating_toast.php)
$msg = $_GET['msg'] ?? '';
$msg_type = $_GET['type'] ?? 'success';
function inventory_redirect($msg, $type, $extra = []) {
    $qs = [];
    if (trim($_GET['search'] ?? '') !== '') $qs['search'] = $_GET['search'];
    $qs = array_merge($qs, $extra, ['msg' => $msg, 'type' => $type]);
    header("Location: admin_inventory.php?" . http_build_query($qs));
    exit;
}

// One-time form tokens — same anti-resubmit pattern as admin_invoices.php
function pinv_token_valid($key) {
    return !empty($_SESSION[$key]) && hash_equals($_SESSION[$key], $_POST['form_token'] ?? '');
}
function pinv_token_rotate($key) {
    $_SESSION[$key] = bin2hex(random_bytes(16));
}

// --- Save part (create or update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_part'])) {
    $part_id       = (int)($_POST['part_id'] ?? 0);
    $name          = trim($_POST['name'] ?? '');
    $sku           = trim($_POST['sku'] ?? '') ?: null;
    $category      = trim($_POST['category'] ?? 'General') ?: 'General';
    $supplier      = trim($_POST['supplier'] ?? '') ?: null;
    $unit_cost     = max(0, (float)($_POST['unit_cost'] ?? 0));
    $selling_price = max(0, (float)($_POST['selling_price'] ?? 0));
    $stock_qty     = max(0, (int)($_POST['stock_qty'] ?? 0));
    $reorder_level = max(0, (int)($_POST['reorder_level'] ?? 5));
    $keep_edit     = $part_id > 0 ? ['edit' => $part_id] : [];

    if (!pinv_token_valid('part_form_token')) {
        inventory_redirect("⚠️ Form already submitted — refresh ignored.", 'error', $keep_edit);
    }
    pinv_token_rotate('part_form_token');

    if ($name === '') {
        inventory_redirect("❌ Part name is required.", 'error', $keep_edit);
    }
    try {
        if ($part_id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE parts SET name=?, sku=?, category=?, supplier=?, unit_cost=?, selling_price=?, reorder_level=?
                 WHERE id=?"
            );
            $stmt->execute([$name, $sku, $category, $supplier, $unit_cost, $selling_price, $reorder_level, $part_id]);
            log_audit($pdo, 'part_updated', 'part', $part_id, $name);
            inventory_redirect("✅ Part updated.", 'success');
        }
        $stmt = $pdo->prepare(
            "INSERT INTO parts (name, sku, category, supplier, unit_cost, selling_price, stock_qty, reorder_level)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$name, $sku, $category, $supplier, $unit_cost, $selling_price, $stock_qty, $reorder_level]);
        log_audit($pdo, 'part_created', 'part', $pdo->lastInsertId(), $name);
        inventory_redirect("✅ Part added.", 'success');
    } catch (PDOException $e) {
        $err = (strpos($e->getMessage(), 'uq_parts_sku') !== false)
            ? "❌ SKU already exists." : "❌ " . $e->getMessage();
        inventory_redirect($err, 'error', $keep_edit);
    }
}

// --- Stock adjustment ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_stock'])) {
    $part_id = (int)($_POST['part_id'] ?? 0);
    $delta   = (int)($_POST['delta'] ?? 0);

    if ($part_id && $delta !== 0) {
        try {
            $stmt = $pdo->prepare("UPDATE parts SET stock_qty = GREATEST(stock_qty + ?, 0) WHERE id = ?");
            $stmt->execute([$delta, $part_id]);
            if ($stmt->rowCount() > 0) {
                log_audit($pdo, 'stock_adjusted', 'part', $part_id, ($delta > 0 ? "+$delta" : "$delta"));
                inventory_redirect("✅ Stock adjusted by " . ($delta > 0 ? "+$delta" : $delta) . ".", 'success');
            }
        } catch (PDOException $e) {
            inventory_redirect("❌ " . $e->getMessage(), 'error');
        }
    }
    inventory_redirect("⚠️ Enter a non-zero quantity to adjust stock.", 'error');
}

// --- Delete part ---
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $used = $pdo->prepare("SELECT COUNT(*) FROM booking_parts WHERE part_id = ?");
        $used->execute([$_GET['delete']]);
        if ((int)$used->fetchColumn() > 0) {
            inventory_redirect("❌ Cannot delete: part is recorded on bookings.", 'error');
        }
        $pdo->prepare("DELETE FROM parts WHERE id = ?")->execute([$_GET['delete']]);
        log_audit($pdo, 'part_deleted', 'part', (int)$_GET['delete']);
        inventory_redirect("✅ Part deleted.", 'success');
    } catch (PDOException $e) {
        inventory_redirect("❌ " . $e->getMessage(), 'error');
    }
}

// --- Fetch data ---
$edit_part = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM parts WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_part = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$search = trim($_GET['search'] ?? '');
$low_stock = [];
$parts = [];
$usage = [];
$categories = $suppliers = [];
$stats = ['total_parts' => 0, 'total_units' => 0, 'stock_value' => 0, 'low_cnt' => 0];
try {
    $low_stock = $pdo->query(
        "SELECT * FROM parts WHERE stock_qty <= reorder_level ORDER BY stock_qty ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $stats = $pdo->query(
        "SELECT COUNT(*) AS total_parts,
                COALESCE(SUM(stock_qty), 0) AS total_units,
                COALESCE(SUM(stock_qty * unit_cost), 0) AS stock_value
         FROM parts"
    )->fetch(PDO::FETCH_ASSOC);
    $stats['low_cnt'] = count($low_stock);

    $params = [];
    $where = '';
    if ($search !== '') {
        $where = "WHERE name LIKE ? OR sku LIKE ? OR category LIKE ? OR supplier LIKE ?";
        $like = "%$search%";
        $params = [$like, $like, $like, $like];
    }
    $stmt = $pdo->prepare("SELECT * FROM parts $where ORDER BY name");
    $stmt->execute($params);
    $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Existing values offered as datalist suggestions in the form
    $categories = $pdo->query("SELECT DISTINCT category FROM parts WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    $suppliers  = $pdo->query("SELECT DISTINCT supplier FROM parts WHERE supplier IS NOT NULL AND supplier != '' ORDER BY supplier")->fetchAll(PDO::FETCH_COLUMN);

    $usage = $pdo->query(
        "SELECT bp.*, p.name AS part_name, b.id AS booking_id
         FROM booking_parts bp
         JOIN parts p ON p.id = bp.part_id
         JOIN bookings b ON b.id = bp.booking_id
         ORDER BY bp.id DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Inventory fetch error: " . $e->getMessage());
}

$pageTitle = 'Inventory';
require 'admin_sidebar_template.php';
?>

<div class="container-fluid py-4 pinv-page">
    <style>
        .pinv-stat { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:.75rem .9rem; display:flex; align-items:center; gap:.7rem; box-shadow:0 2px 8px rgba(0,0,0,.04); height:100%; }
        .pinv-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
        .pi-blue { color:#1d4ed8; } .pi-green { color:#047857; }
        .pi-red { color:#dc2626; } .pi-amber { color:#b45309; }
        .pinv-stat-label { font-size:.64rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; }
        .pinv-stat-value { font-weight:700; font-size:.98rem; line-height:1.2; }
        .pinv-head { padding:.55rem 1rem; font-size:.83rem; font-weight:700; display:flex; align-items:center; gap:.5rem; border-bottom:1px solid #eef2f7; }
        .pinv-head-slate { background:#f8fafc; color:#1e293b; }
        .pinv-head-green { background:#ecfdf5; color:#047857; }
        .pinv-head-amber { background:#fff7ed; color:#c2410c; }
        .pinv-head-blue { background:#eff6ff; color:#1d4ed8; }
        .pinv-lowalert { background:#fffbeb; border:1px solid #fde68a; color:#92400e; border-radius:12px; padding:.7rem .9rem; display:flex; gap:.6rem; align-items:flex-start; font-size:.83rem; }
        .pinv-lowalert .bi { font-size:1rem; line-height:1.3; }
        .pinv-lowalert .btn-close { margin-left:auto; flex-shrink:0; }
        /* Only the rows scroll — header (search) and pagination stay fixed */
        .pinv-table-scroll { max-height:480px; overflow-y:auto; }
        .pinv-table thead th { position:sticky; top:0; z-index:2; background:#f1f5f9; font-size:.68rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; border-bottom:1px solid #e2e8f0 !important; white-space:nowrap; }
        .pinv-usage-scroll { max-height:290px; overflow-y:auto; }
        /* Flat usage list — drop the translucent per-item wash from sidebar-admin.css */
        .pinv-usage-card .list-group-item,
        .pinv-usage-card .list-group-item:hover { background:transparent; }
        html[data-theme="dark"] .pinv-usage-card .list-group-item,
        html[data-theme="dark"] .pinv-usage-card .list-group-item:hover { background:transparent !important; }
        .pinv-price { font-size:.74rem; font-weight:700; color:#047857; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:999px; padding:.15rem .65rem; white-space:nowrap; }
        .pinv-price .qty { color:#64748b; font-weight:600; }
        html[data-theme="dark"] .pinv-price { background:rgba(16,185,129,.15); border-color:rgba(16,185,129,.3); color:#34d399; }
        html[data-theme="dark"] .pinv-price .qty { color:#94a3b8; }
        /* On lg+ the page fills the visible content area: the cards row is sized
           top-down (basis:0 so content can never inflate it), and both the
           inventory table and the usage list scroll internally */
        @media (min-width: 992px) {
            .pinv-page { display:flex; flex-direction:column; min-height:100%; }
            .pinv-cols { flex:1 1 0; min-height:0; }
            #pinvSide, #pinvSide .card { min-height:0; }
            .pinv-usage-card { flex:1 1 0; }
            .pinv-usage-card .card-body { flex:1 1 auto; min-height:0; display:flex; flex-direction:column; }
            .pinv-usage-card .pinv-usage-scroll { flex:1 1 0; min-height:0; max-height:none; }
            #pinvInventoryCard { min-height:0; }
            #pinvInventoryCard .card-body { flex:1 1 auto; min-height:0; display:flex; flex-direction:column; }
            #pinvInventoryCard .pinv-table-scroll { flex:1 1 0; min-height:0; max-height:none; }
        }
        .pinv-iconbtn { width:28px; height:28px; padding:0; display:inline-flex; align-items:center; justify-content:center; border-radius:50%; }
        .pinv-iconbtn i { font-size:.75rem; }
        .pinv-step { width:24px; height:24px; }
        .pinv-stockbar { height:4px; width:64px; background:#e2e8f0; border-radius:99px; overflow:hidden; margin-top:4px; }
        .pinv-stockbar-fill { height:100%; background:#10b981; }
        .pinv-stockbar-fill.is-low { background:#ef4444; }
        .pinv-margin { font-size:.68rem; }
        html[data-theme="dark"] .pinv-stockbar { background:rgba(255,255,255,.1); }
        .pinv-table-scroll::-webkit-scrollbar { width:6px; }
        .pinv-table-scroll::-webkit-scrollbar-thumb { background:rgba(100,116,139,.3); border-radius:3px; }
        /* Usage list scrolls with the wheel but shows no scrollbar (both themes) */
        .pinv-usage-scroll { scrollbar-width:none; -ms-overflow-style:none; }
        .pinv-usage-scroll::-webkit-scrollbar { display:none; }
        .pinv-form .form-label { font-size:.66rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:.15rem; }
        .pinv-form .form-control-sm { padding:.22rem .5rem; font-size:.8rem; }
        html[data-theme="dark"] .pinv-form .form-label { color:#94a3b8; }
        html[data-theme="dark"] .pinv-stat { background:#1a2b4f; border-color:rgba(255,255,255,.09); box-shadow:0 4px 20px rgba(0,0,0,.35); }
        html[data-theme="dark"] .pinv-stat-value { color:#e2e8f0; }
        html[data-theme="dark"] .pi-blue { color:#93c5fd; }
        html[data-theme="dark"] .pi-green { color:#34d399; }
        html[data-theme="dark"] .pi-red { color:#f87171; }
        html[data-theme="dark"] .pi-amber { color:#fbbf24; }
        html[data-theme="dark"] .pinv-head { border-color:rgba(255,255,255,.09); }
        html[data-theme="dark"] .pinv-head-slate { background:rgba(255,255,255,.03); color:#e2e8f0; }
        html[data-theme="dark"] .pinv-head-green { background:rgba(16,185,129,.12); color:#34d399; }
        html[data-theme="dark"] .pinv-head-amber { background:rgba(245,158,11,.12); color:#fbbf24; }
        html[data-theme="dark"] .pinv-head-blue { background:rgba(59,130,246,.12); color:#93c5fd; }
        html[data-theme="dark"] .pinv-lowalert { background:rgba(245,158,11,.1); border-color:rgba(245,158,11,.3); color:#fbbf24; }
        html[data-theme="dark"] .pinv-table thead th { background:#1a2b4f; color:#94a3b8; border-bottom-color:rgba(255,255,255,.09) !important; }
        html[data-theme="dark"] .pinv-table .table-warning,
        html[data-theme="dark"] .table-warning { --bs-table-bg:rgba(245,158,11,.13); --bs-table-color:#e2e8f0; --bs-table-hover-bg:rgba(245,158,11,.2); --bs-table-hover-color:#e2e8f0; --bs-table-border-color:rgba(255,255,255,.09); }
    </style>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="pinv-stat"><div class="pinv-icon pi-blue"><i class="bi bi-boxes"></i></div>
                <div><div class="pinv-stat-label">Total Parts</div><div class="pinv-stat-value"><?= (int)$stats['total_parts'] ?></div></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="pinv-stat"><div class="pinv-icon pi-green"><i class="bi bi-stack"></i></div>
                <div><div class="pinv-stat-label">Units on Hand</div><div class="pinv-stat-value"><?= number_format((float)$stats['total_units']) ?></div></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="pinv-stat"><div class="pinv-icon pi-amber"><i class="bi bi-cash-stack"></i></div>
                <div><div class="pinv-stat-label">Stock Value</div><div class="pinv-stat-value">₱<?= number_format((float)$stats['stock_value'], 2) ?></div></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="pinv-stat"><div class="pinv-icon pi-red"><i class="bi bi-exclamation-triangle"></i></div>
                <div><div class="pinv-stat-label">Low Stock</div><div class="pinv-stat-value"><?= (int)$stats['low_cnt'] ?></div></div>
            </div>
        </div>
    </div>

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msg_type === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
        <?= $msg ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <script>
        // Strip flash params so the toast does not re-appear on refresh or navigation
        (function () {
            var u = new URL(window.location.href);
            u.searchParams.delete('msg');
            u.searchParams.delete('type');
            history.replaceState(null, '', u.toString());
        })();
    </script>
    <?php endif; ?>

    <?php if (!empty($low_stock)):
        $low_sig = md5(implode('|', array_map(function ($p) { return $p['id'] . ':' . $p['stock_qty']; }, $low_stock)));
    ?>
    <div class="alert alert-warning alert-dismissible fade show pinv-lowalert mb-4" id="lowStockAlert" data-sig="<?= $low_sig ?>" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
            <strong>Low stock alert:</strong>
            <?php foreach ($low_stock as $i => $p): ?>
                <?= $i > 0 ? '·' : '' ?>
                <?= htmlspecialchars($p['name']) ?> (<?= (int)$p['stock_qty'] ?> left)
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
    </div>
    <script>
        // Converted to a floating toast by floating_toast.php (auto-dismisses).
        // Session signature suppresses repeats unless the low-stock list changes.
        (function () {
            var el = document.getElementById('lowStockAlert');
            if (!el) return;
            if (sessionStorage.getItem('lowStockDismissed') === el.getAttribute('data-sig')) {
                el.remove();
            } else {
                sessionStorage.setItem('lowStockDismissed', el.getAttribute('data-sig'));
            }
        })();
    </script>
    <?php endif; ?>

    <div class="row g-4 pinv-cols">
        <div class="col-lg-4 d-flex flex-column" id="pinvSide">
            <div class="card">
                <div class="card-header pinv-head <?= $edit_part ? 'pinv-head-amber' : 'pinv-head-blue' ?> justify-content-between">
                    <span><i class="bi <?= $edit_part ? 'bi-pencil-square' : 'bi-plus-circle' ?> me-1"></i><?= $edit_part ? 'Edit Part' : 'Add Part' ?></span>
                    <?php if ($edit_part): ?>
                    <span class="badge rounded-pill bg-warning text-dark">Editing #<?= (int)$edit_part['id'] ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body py-2 px-3">
                    <form method="POST" class="pinv-form">
                        <input type="hidden" name="save_part" value="1">
                        <input type="hidden" name="form_token" value="<?= $_SESSION['part_form_token'] = $_SESSION['part_form_token'] ?? bin2hex(random_bytes(16)) ?>">
                        <input type="hidden" name="part_id" value="<?= $edit_part['id'] ?? 0 ?>">
                        <div class="row gx-2 gy-1">
                            <div class="col-7">
                                <label class="form-label">Part Name *</label>
                                <input type="text" name="name" class="form-control form-control-sm" required
                                       value="<?= htmlspecialchars($edit_part['name'] ?? '') ?>">
                            </div>
                            <div class="col-5">
                                <label class="form-label">SKU</label>
                                <input type="text" name="sku" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($edit_part['sku'] ?? '') ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Category</label>
                                <input type="text" name="category" class="form-control form-control-sm" list="pinvCategories"
                                       value="<?= htmlspecialchars($edit_part['category'] ?? 'General') ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Supplier</label>
                                <input type="text" name="supplier" class="form-control form-control-sm" list="pinvSuppliers"
                                       value="<?= htmlspecialchars($edit_part['supplier'] ?? '') ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Unit Cost ₱</label>
                                <input type="number" name="unit_cost" step="0.01" min="0" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($edit_part['unit_cost'] ?? '0') ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Sell Price ₱</label>
                                <input type="number" name="selling_price" step="0.01" min="0" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($edit_part['selling_price'] ?? '0') ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label"><?= $edit_part ? 'Stock (locked)' : 'Initial Stock' ?></label>
                                <input type="number" name="stock_qty" min="0" class="form-control form-control-sm"
                                       <?= $edit_part ? 'disabled title="Adjust stock via the inventory table"' : '' ?>
                                       value="<?= htmlspecialchars($edit_part['stock_qty'] ?? '0') ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Reorder Level</label>
                                <input type="number" name="reorder_level" min="0" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($edit_part['reorder_level'] ?? '5') ?>">
                            </div>
                            <div class="<?= $edit_part ? 'col-8' : 'col-12' ?> mt-1">
                                <button type="submit" class="btn btn-sm btn-primary w-100 rounded-pill fw-bold">
                                    <i class="bi <?= $edit_part ? 'bi-check-lg' : 'bi-plus-lg' ?> me-1"></i><?= $edit_part ? 'Update Part' : 'Add Part' ?>
                                </button>
                            </div>
                            <?php if ($edit_part): ?>
                            <div class="col-4 mt-1">
                                <a href="admin_inventory.php" class="btn btn-sm btn-outline-secondary w-100 rounded-pill">Cancel</a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </form>
                    <datalist id="pinvCategories">
                        <?php foreach ($categories as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?>
                    </datalist>
                    <datalist id="pinvSuppliers">
                        <?php foreach ($suppliers as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
            </div>

            <div class="card mt-4 pinv-usage-card">
                <div class="card-header pinv-head pinv-head-green">
                    <span><i class="bi bi-clock-history me-1"></i>Recent Parts Usage</span>
                </div>
                <div class="card-body">
                    <?php if (empty($usage)): ?>
                        <div class="text-center py-3 my-auto text-muted">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                            <p class="mb-0 small">No parts recorded on bookings yet.</p>
                        </div>
                    <?php else: ?>
                    <div class="pinv-usage-scroll">
                        <ul class="list-group list-group-flush">
                            <?php foreach ($usage as $u): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <div>
                                    <small><a href="booking_parts.php?booking_id=<?= (int)$u['booking_id'] ?>" class="text-muted text-decoration-none">Booking #<?= (int)$u['booking_id'] ?></a></small><br>
                                    <?= htmlspecialchars($u['part_name']) ?>
                                </div>
                                <span class="pinv-price"><span class="qty"><?= (int)$u['quantity'] ?> ×</span> ₱<?= number_format($u['unit_price'], 2) ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card h-100" id="pinvInventoryCard">
                <div class="card-header pinv-head pinv-head-slate justify-content-between flex-wrap gap-2">
                    <span><i class="bi bi-boxes me-1"></i>Parts Inventory</span>
                    <div class="d-flex gap-2 align-items-center flex-wrap">
                        <button type="button" id="pinvLowOnly" class="btn btn-sm btn-outline-warning rounded-pill px-3 text-nowrap">
                            <i class="bi bi-exclamation-triangle me-1"></i>Low only
                        </button>
                        <form method="GET" class="d-flex gap-2">
                            <input type="text" id="pinvSearch" name="search" class="form-control form-control-sm" placeholder="Search parts..."
                                   value="<?= htmlspecialchars($search) ?>">
                            <button class="btn btn-sm btn-outline-secondary rounded-pill px-3">Search</button>
                        </form>
                        <button type="button" id="pinvExport" class="btn btn-sm btn-outline-secondary pinv-iconbtn" title="Export CSV"><i class="bi bi-download"></i></button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($parts)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-box-seam fs-2 d-block mb-2"></i>
                            <p class="mb-0 small">No parts found. Add your first part using the form on the left.</p>
                        </div>
                    <?php else: ?>
                    <div class="pinv-table-scroll">
                        <table class="table table-sm table-hover align-middle mb-0 pinv-table" id="pinvTable">
                            <thead>
                                <tr>
                                    <th>Part</th>
                                    <th>Category</th>
                                    <th>Cost</th>
                                    <th>Price</th>
                                    <th>Stock</th>
                                    <th>Restock</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($parts as $p):
                                    $is_low  = (int)$p['stock_qty'] <= (int)$p['reorder_level'];
                                    $is_out  = (int)$p['stock_qty'] === 0;
                                    $margin  = (float)$p['unit_cost'] > 0 ? (($p['selling_price'] - $p['unit_cost']) / $p['unit_cost']) * 100 : null;
                                    $suggest = $is_low ? max(1, (int)$p['reorder_level'] * 2 - (int)$p['stock_qty']) : 0;
                                    $fill    = (int)$p['reorder_level'] > 0 ? min(100, (int)round($p['stock_qty'] / ($p['reorder_level'] * 2) * 100)) : 100;
                                ?>
                                <tr class="<?= $is_low ? 'table-warning' : '' ?>"
                                    data-name="<?= htmlspecialchars($p['name']) ?>" data-sku="<?= htmlspecialchars($p['sku'] ?? '') ?>"
                                    data-category="<?= htmlspecialchars($p['category']) ?>" data-supplier="<?= htmlspecialchars($p['supplier'] ?? '') ?>"
                                    data-cost="<?= $p['unit_cost'] ?>" data-price="<?= $p['selling_price'] ?>"
                                    data-stock="<?= (int)$p['stock_qty'] ?>" data-reorder="<?= (int)$p['reorder_level'] ?>">
                                    <td>
                                        <strong><?= htmlspecialchars($p['name']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($p['sku'] ?? '—') ?> · <?= htmlspecialchars($p['supplier'] ?? 'no supplier') ?></small>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($p['category']) ?></span></td>
                                    <td>₱<?= number_format($p['unit_cost'], 2) ?></td>
                                    <td>
                                        ₱<?= number_format($p['selling_price'], 2) ?>
                                        <?php if ($margin !== null): ?>
                                        <br><small class="pinv-margin <?= $margin < 0 ? 'text-danger fw-bold' : 'text-muted' ?>"><?= ($margin >= 0 ? '+' : '') . number_format($margin, 0) ?>%</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $is_low ? 'bg-danger' : 'bg-success' ?>"><?= (int)$p['stock_qty'] ?></span>
                                        <?php if ($is_out): ?><small class="text-danger fw-bold">out</small>
                                        <?php elseif ($is_low): ?><small class="text-danger">low</small><?php endif; ?>
                                        <div class="pinv-stockbar" title="Stock vs 2× reorder level"><div class="pinv-stockbar-fill <?= $is_low ? 'is-low' : '' ?>" style="width:<?= $fill ?>%"></div></div>
                                    </td>
                                    <td>
                                        <form method="POST" class="d-flex gap-1 align-items-center">
                                            <input type="hidden" name="adjust_stock" value="1">
                                            <input type="hidden" name="part_id" value="<?= (int)$p['id'] ?>">
                                            <button type="button" class="btn btn-sm btn-outline-secondary pinv-iconbtn pinv-step" data-step="-1" title="−1"><i class="bi bi-dash"></i></button>
                                            <input type="number" name="delta" class="form-control form-control-sm" style="width:64px"
                                                   placeholder="<?= $suggest > 0 ? "+$suggest" : '+10' ?>"
                                                   title="Negative to deduct<?= $suggest > 0 ? " · suggested +$suggest" : '' ?>">
                                            <button type="button" class="btn btn-sm btn-outline-secondary pinv-iconbtn pinv-step" data-step="1" title="+1"><i class="bi bi-plus"></i></button>
                                            <button class="btn btn-sm btn-outline-primary pinv-iconbtn" title="Apply stock change"><i class="bi bi-check-lg"></i></button>
                                        </form>
                                    </td>
                                    <td class="text-nowrap">
                                        <a href="?edit=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-secondary pinv-iconbtn" title="Edit"><i class="bi bi-pencil"></i></a>
                                        <a href="?delete=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-danger pinv-iconbtn" title="Delete"
                                           onclick="return confirm('Delete <?= htmlspecialchars($p['name'], ENT_QUOTES) ?>?')"><i class="bi bi-trash"></i></a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr id="pinvNoMatch" style="display:none"><td colspan="7" class="text-center text-muted py-4 small">No parts match the current filters.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var table   = document.getElementById('pinvTable');
    var search  = document.getElementById('pinvSearch');
    var lowBtn  = document.getElementById('pinvLowOnly');
    var tbody   = table ? table.querySelector('tbody') : null;
    var noMatch = document.getElementById('pinvNoMatch');

    // Live filter: search text + "low stock only" toggle (server-side ?search= still works)
    function applyFilters() {
        if (!tbody) return;
        var q       = (search && search.value || '').toLowerCase();
        var lowOnly = !!(lowBtn && lowBtn.classList.contains('active'));
        var visible = 0;
        tbody.querySelectorAll('tr').forEach(function (tr) {
            if (tr === noMatch) return;
            var show = (!q || tr.textContent.toLowerCase().indexOf(q) !== -1)
                    && (!lowOnly || tr.classList.contains('table-warning'));
            tr.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (noMatch) noMatch.style.display = visible ? 'none' : '';
    }
    if (search) search.addEventListener('input', applyFilters);
    if (lowBtn) lowBtn.addEventListener('click', function () {
        lowBtn.classList.toggle('active');
        applyFilters();
    });

    // Restock steppers — bump the delta input, admin confirms with the check button
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.pinv-step');
        if (!b) return;
        var inp = b.parentElement.querySelector('input[name="delta"]');
        if (inp) inp.value = (parseInt(inp.value, 10) || 0) + parseInt(b.dataset.step, 10);
    });

    // Export the currently visible rows to CSV
    var exportBtn = document.getElementById('pinvExport');
    if (exportBtn && tbody) {
        exportBtn.addEventListener('click', function () {
            var rows = [['Part', 'SKU', 'Category', 'Supplier', 'Cost', 'Price', 'Stock', 'Reorder']];
            tbody.querySelectorAll('tr').forEach(function (tr) {
                if (tr === noMatch || tr.style.display === 'none' || !tr.dataset.name) return;
                var d = tr.dataset;
                rows.push([d.name, d.sku, d.category, d.supplier, d.cost, d.price, d.stock, d.reorder]);
            });
            var csv = rows.map(function (r) {
                return r.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(',');
            }).join('\r\n');
            var a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' }));
            a.download = 'parts_inventory.csv';
            a.click();
            URL.revokeObjectURL(a.href);
        });
    }
})();
</script>

<?php require 'admin_footer.php'; ?>
