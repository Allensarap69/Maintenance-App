<?php
session_start();
require 'db.php';
require 'management_helper.php';

// Accessible by admins (any booking) and mechanics (their own jobs)
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'mechanic'])) {
    header("Location: index.php");
    exit;
}

ensure_management_tables($pdo);

$booking_id = (int)($_REQUEST['booking_id'] ?? 0);
if (!$booking_id) {
    header("Location: " . ($role === 'admin' ? 'manage_bookings.php' : 'mechanic_bookings.php'));
    exit;
}

// Access check for mechanics: must be the primary or a team mechanic on this booking
if ($role === 'mechanic') {
    $stmt = $pdo->prepare("SELECT id FROM mechanics WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $mechanic_id = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM bookings b
         LEFT JOIN booking_mechanics bm ON bm.booking_id = b.id AND bm.mechanic_id = ?
         WHERE b.id = ? AND (b.mechanic_id = ? OR bm.mechanic_id IS NOT NULL)"
    );
    $stmt->execute([$mechanic_id, $booking_id, $mechanic_id]);
    if (!(int)$stmt->fetchColumn()) {
        header("Location: mechanic_bookings.php");
        exit;
    }
}

$msg = "";
$msg_type = "";
if (isset($_GET['msg'], $_GET['type'])) {
    $msg = urldecode($_GET['msg']);
    $msg_type = $_GET['type'] === 'success' ? 'success' : 'error';
}

// --- Add a part ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_part'])) {
    $r = record_booking_part($pdo, $booking_id, (int)($_POST['part_id'] ?? 0), (int)($_POST['quantity'] ?? 1));
    header("Location: booking_parts.php?booking_id=$booking_id&msg=" . urlencode($r['msg']) . "&type=" . ($r['ok'] ? 'success' : 'error'));
    exit;
}

// --- Notify customer about pending parts ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notify_customer'])) {
    $r = notify_customer_pending_parts($pdo, $booking_id);
    header("Location: booking_parts.php?booking_id=$booking_id&msg=" . urlencode($r['msg']) . "&type=" . ($r['ok'] ? 'success' : 'error'));
    exit;
}

// --- Remove a part ---
if (isset($_GET['remove']) && is_numeric($_GET['remove'])) {
    $r = remove_booking_part($pdo, (int)$_GET['remove']);
    header("Location: booking_parts.php?booking_id=$booking_id&msg=" . urlencode($r['msg']) . "&type=" . ($r['ok'] ? 'success' : 'error'));
    exit;
}

// --- Load data ---
$stmt = $pdo->prepare(
    "SELECT b.*, u.username AS customer_name, u.phone AS customer_phone,
            m.brand, m.model, m.plate_number, m.image
     FROM bookings b
     JOIN users u ON u.id = b.user_id
     LEFT JOIN motorcycles m ON m.id = b.vehicle_id
     WHERE b.id = ?"
);
$stmt->execute([$booking_id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$booking) die("Booking not found.");

$vehicle_text = trim(($booking['brand'] ?? '') . ' ' . ($booking['model'] ?? '')) ?: 'N/A';
$moto_img = resolve_moto_image($booking);
$status_color = match ($booking['status']) {
    'completed' => '#10b981', 'in_progress' => '#f59e0b',
    'accepted' => '#3b82f6', 'assigned' => '#8b5cf6',
    'rejected', 'deposit_rejected' => '#ef4444', default => '#64748b',
};

$open = in_array($booking['status'], ['assigned', 'accepted', 'in_progress', 'pending', 'deposit_submitted']);

$used = $pdo->prepare(
    "SELECT bp.*, p.name AS part_name, p.sku
     FROM booking_parts bp JOIN parts p ON p.id = bp.part_id
     WHERE bp.booking_id = ? ORDER BY bp.id"
);
$used->execute([$booking_id]);
$used = $used->fetchAll(PDO::FETCH_ASSOC);
$used_total = array_sum(array_map(fn($u) => $u['quantity'] * $u['unit_price'], $used));

// Cost summary: split parts by approval status + verified payments
$pending_total  = array_sum(array_map(fn($u) => $u['quantity'] * $u['unit_price'], array_filter($used, fn($u) => $u['status'] === 'pending')));
$approved_total = array_sum(array_map(fn($u) => $u['quantity'] * $u['unit_price'], array_filter($used, fn($u) => $u['status'] === 'approved')));
$pending_count  = count(array_filter($used, fn($u) => $u['status'] === 'pending'));
$paid_total = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE booking_id = " . (int)$booking_id . " AND status = 'verified'")->fetchColumn();
$service_estimate = (float)$booking['total_price'];
$projected_total  = $service_estimate + $approved_total + $pending_total;
$balance_due      = max(0, $projected_total - $paid_total);
$parts_over_warn  = $service_estimate > 0 && ($approved_total + $pending_total) > $service_estimate * 0.3;

$parts_catalog = $pdo->query("SELECT id, name, sku, selling_price, stock_qty FROM parts ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$service_names = booked_service_names($pdo, $booking);
$recommended = recommended_parts_for_services($service_names, $parts_catalog);
$recommended_ids = array_flip(array_map(fn($p) => (int)$p['id'], $recommended));

$back = $role === 'admin' ? 'manage_bookings.php' : 'mechanic_bookings.php';
$pageTitle = "Parts — Booking #{$booking_id}";

if ($role === 'admin') {
    require 'admin_sidebar_template.php';
    echo '<div class="container-fluid py-3">';
} else {
    include 'mechanic_sidebar.php';
    echo '<div class="content-area"><div class="container-fluid py-3">';
}
?>

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msg_type === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
        <?= $msg ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <style>
        .bp-card { border: none; border-radius: 14px; box-shadow: 0 4px 12px rgba(0,0,0,.05); overflow: hidden; }
        .bp-head { padding: .45rem 1rem; font-size: .8rem; font-weight: 700; display: flex; align-items: center; gap: .45rem; }
        .bp-head-blue { background: #eff6ff; color: #1d4ed8; }
        .bp-head-amber { background: #fff7ed; color: #c2410c; }
        .bp-head-green { background: #ecfdf5; color: #047857; }
        .bp-moto-img { width: 52px; height: 52px; object-fit: cover; border-radius: 12px; border: 1px solid #e2e8f0; padding: 3px; background: #f8fafc; flex-shrink: 0; }
        .bp-label { font-size: .66rem; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; font-weight: 600; margin-bottom: .15rem; }
        .rec-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: .3rem; }
        .rec-row { display: flex; align-items: center; gap: .4rem; border: 1px solid #e2e8f0; background: #fff; border-radius: 8px; padding: .3rem .4rem; transition: box-shadow .15s ease, border-color .15s ease; }
        .rec-row:hover { box-shadow: 0 3px 10px rgba(0,0,0,.07); border-color: #cbd5e1; }
        .rec-icon { width: 26px; height: 26px; border-radius: 7px; background: #eff6ff; color: #1d4ed8; display: flex; align-items: center; justify-content: center; font-size: .85rem; flex-shrink: 0; }
        .rec-name { font-size: .76rem; font-weight: 600; line-height: 1.25; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .rec-price { font-size: .72rem; font-weight: 700; color: #047857; }
        .rec-stock { font-size: .66rem; color: #64748b; }
        .rec-qty { width: 44px; padding: .15rem .3rem; font-size: .78rem; border-radius: 8px; }
        @media (max-width: 575.98px) { .rec-grid { grid-template-columns: 1fr; } }
        .parts-scroll { flex: 1 1 auto; min-height: 0; overflow-y: auto; }
        .used-row { display: flex; align-items: center; gap: .6rem; padding: .4rem .55rem; border-bottom: 1px solid #f1f5f9; border-radius: 8px; transition: background .12s ease; }
        .used-row:hover { background: #f8fafc; }
        .used-row:last-child { border-bottom: none; }
        .used-name { font-size: .82rem; font-weight: 600; line-height: 1.25; }
        .used-meta { font-size: .7rem; color: #94a3b8; line-height: 1.25; }
        .used-qty { font-size: .72rem; font-weight: 700; background: #eff6ff; color: #1d4ed8; border-radius: 999px; padding: .1rem .5rem; white-space: nowrap; }
        .used-total { font-size: .85rem; font-weight: 700; color: #047857; white-space: nowrap; }
        .parts-total-bar { display: flex; justify-content: space-between; align-items: center; border-top: 2px solid #e2e8f0; padding-top: .4rem; margin-top: .25rem; font-size: .82rem; }
        .cost-strip { display: flex; gap: 1rem; flex-wrap: wrap; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: .4rem .7rem; margin-top: .5rem; font-size: .78rem; }
        .cost-strip .bp-label { margin-bottom: 0; }
        .cost-strip .cost-val { font-weight: 700; font-size: .82rem; }
        .used-declined .used-name, .used-declined .used-meta { text-decoration: line-through; color: #94a3b8 !important; }
        .part-status { font-size: .64rem; font-weight: 700; padding: .12rem .5rem; border-radius: 999px; white-space: nowrap; }
        .part-status.pending { background: #fef3c7; color: #b45309; }
        .part-status.approved { background: #d1fae5; color: #047857; }
        .part-status.declined { background: #f1f5f9; color: #64748b; }
        .bp-card { display: flex; flex-direction: column; }
        .bp-card > .card-body { flex: 1 1 auto; min-height: 0; }
        .bp-body-scroll { overflow-y: auto; }
        @media (min-width: 992px) {
            .bp-cols { height: calc(100vh - 160px); min-height: 460px; }
        }
    </style>

    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <a href="<?= $back ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3"><i class="bi bi-arrow-left me-1"></i>Back</a>
        <div class="d-flex align-items-center gap-2">
            <span class="badge rounded-pill px-3 py-2" style="background:#FACC15;color:#111;"><i class="bi bi-receipt me-1"></i>Booking #<?= (int)$booking_id ?></span>
            <span class="badge rounded-pill text-white" style="background:<?= $status_color ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $booking['status']))) ?></span>
        </div>
    </div>

    <div class="row g-3 bp-cols">
        <div class="col-lg-7">
            <div class="card bp-card h-100">
                <div class="bp-head bp-head-blue"><i class="bi bi-calendar-check"></i>Booking &amp; Add Parts
                    <span class="badge rounded-pill text-white ms-auto" style="background:<?= $status_color ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $booking['status']))) ?></span>
                </div>
                <div class="card-body p-2 px-3 bp-body-scroll">
                    <div class="d-flex gap-3 align-items-center flex-wrap">
                        <img src="<?= htmlspecialchars($moto_img) ?>" class="bp-moto-img" alt="<?= htmlspecialchars($vehicle_text) ?>">
                        <div class="flex-grow-1" style="min-width:150px">
                            <div class="fw-bold"><?= htmlspecialchars($vehicle_text) ?></div>
                            <div class="text-muted small"><i class="bi bi-credit-card-2-front me-1"></i><?= htmlspecialchars($booking['plate_number'] ?? 'No plate') ?></div>
                        </div>
                        <div>
                            <p class="bp-label">Customer</p>
                            <div class="small fw-semibold"><?= htmlspecialchars($booking['customer_name']) ?></div>
                            <?php if (!empty($booking['customer_phone'])): ?><div class="small text-muted"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($booking['customer_phone']) ?></div><?php endif; ?>
                        </div>
                        <div>
                            <p class="bp-label">Schedule</p>
                            <div class="small"><?= date('M j, Y', strtotime($booking['schedule_date'])) ?></div>
                            <div class="small text-muted"><i class="bi bi-clock me-1"></i><?= date('g:i A', strtotime($booking['schedule_start_time'])) ?> – <?= date('g:i A', strtotime($booking['schedule_end_time'])) ?></div>
                        </div>
                    </div>
                    <?php if (!empty($service_names)): ?>
                    <div class="mt-1">
                        <?php foreach ($service_names as $sn): ?><span class="badge rounded-pill text-bg-light border me-1 mb-1"><?= htmlspecialchars($sn) ?></span><?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="cost-strip">
                        <div><span class="bp-label">Service estimate</span><div class="cost-val">₱<?= number_format($service_estimate, 2) ?></div></div>
                        <div><span class="bp-label">Parts approved</span><div class="cost-val text-success">₱<?= number_format($approved_total, 2) ?></div></div>
                        <?php if ($pending_total > 0): ?>
                        <div><span class="bp-label">Awaiting approval</span><div class="cost-val text-warning">₱<?= number_format($pending_total, 2) ?></div></div>
                        <?php endif; ?>
                        <div><span class="bp-label">Projected total</span><div class="cost-val">₱<?= number_format($projected_total, 2) ?></div></div>
                        <div><span class="bp-label">Paid</span><div class="cost-val">−₱<?= number_format($paid_total, 2) ?></div></div>
                        <div><span class="bp-label">Balance due</span><div class="cost-val <?= $balance_due > 0 ? 'text-danger' : 'text-success' ?>">₱<?= number_format($balance_due, 2) ?></div></div>
                    </div>
                    <?php if ($parts_over_warn): ?>
                    <div class="alert alert-warning py-1 px-2 mt-2 mb-0 small"><i class="bi bi-exclamation-triangle me-1"></i>Parts exceed 30% of the service estimate — make sure the customer approves them.</div>
                    <?php endif; ?>

                    <?php if ($open): ?>
                    <hr class="my-2">
                    <?php if (empty($parts_catalog)): ?>
                        <p class="text-muted mb-0">No parts in inventory yet. <?php if ($role === 'admin'): ?><a href="admin_inventory.php">Add parts</a>.<?php endif; ?></p>
                    <?php else: ?>
                    <?php if (!empty($recommended)): ?>
                    <p class="small fw-semibold text-muted mb-1"><i class="bi bi-lightbulb me-1 text-warning"></i>Recommended for booked services</p>
                    <div class="rec-grid">
                    <?php foreach ($recommended as $p): ?>
                    <form method="POST" class="rec-row">
                        <input type="hidden" name="add_part" value="1">
                        <input type="hidden" name="booking_id" value="<?= (int)$booking_id ?>">
                        <input type="hidden" name="part_id" value="<?= (int)$p['id'] ?>">
                        <div class="rec-icon"><i class="bi bi-gear"></i></div>
                        <div class="flex-grow-1" style="min-width:0">
                            <div class="rec-name" title="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></div>
                            <div><span class="rec-price">₱<?= number_format($p['selling_price'], 2) ?></span> <span class="rec-stock">· <?= (int)$p['stock_qty'] ?> left</span></div>
                        </div>
                        <input type="number" name="quantity" min="1" max="<?= (int)$p['stock_qty'] ?>" value="1" class="form-control form-control-sm rec-qty" <?= $p['stock_qty'] < 1 ? 'disabled' : '' ?>>
                        <button class="btn btn-sm btn-warning text-dark rounded-circle flex-shrink-0" style="width:28px;height:28px;padding:0;display:inline-flex;align-items:center;justify-content:center;" title="Add part" <?= $p['stock_qty'] < 1 ? 'disabled' : '' ?>><i class="bi bi-plus-lg" style="font-size:.7rem"></i></button>
                    </form>
                    <?php endforeach; ?>
                    </div>
                    <hr class="my-1">
                    <?php endif; ?>
                    <form method="POST" class="row g-2 align-items-end">
                        <input type="hidden" name="add_part" value="1">
                        <input type="hidden" name="booking_id" value="<?= (int)$booking_id ?>">
                        <div class="col-12 col-sm-7">
                            <p class="bp-label mb-1"><?= !empty($recommended) ? 'All parts' : 'Select part' ?></p>
                            <select name="part_id" class="form-select form-select-sm" required>
                                <option value="">Select part…</option>
                                <?php foreach ($parts_catalog as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= $p['stock_qty'] < 1 ? 'disabled' : '' ?>>
                                    <?= isset($recommended_ids[(int)$p['id']]) ? '★ ' : '' ?><?= htmlspecialchars($p['name']) ?> — ₱<?= number_format($p['selling_price'], 2) ?> (<?= (int)$p['stock_qty'] ?> in stock)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-sm-2">
                            <input type="number" name="quantity" min="1" value="1" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6 col-sm-3">
                            <button class="btn btn-sm btn-primary w-100 rounded-pill"><i class="bi bi-plus-lg me-1"></i>Add</button>
                        </div>
                    </form>
                    <?php endif; ?>
                    <?php else: ?>
                        <hr class="my-2">
                        <div class="alert alert-secondary mb-0 rounded-3"><i class="bi bi-lock me-1"></i>This booking is <strong><?= htmlspecialchars($booking['status']) ?></strong> — parts can no longer be changed.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card bp-card h-100">
                <div class="bp-head bp-head-green"><i class="bi bi-gear"></i>Parts on this Job
                    <span class="ms-auto d-flex align-items-center gap-2">
                    <?php if ($pending_count > 0): ?>
                        <span class="badge bg-warning text-dark rounded-pill"><?= $pending_count ?> awaiting approval</span>
                    <?php elseif (!empty($used)): ?>
                        <span class="badge bg-success rounded-pill"><?= count($used) ?></span>
                    <?php endif; ?>
                    </span>
                </div>
                <div class="card-body p-2 px-3 d-flex flex-column">
                    <?php if (empty($used)): ?>
                        <div class="text-center my-auto py-5 text-muted">
                            <i class="bi bi-box-seam fs-1 d-block mb-2"></i>
                            <p class="mb-0 small">No parts recorded for this booking yet.</p>
                        </div>
                    <?php else: ?>
                    <div class="parts-scroll">
                        <?php foreach ($used as $u): ?>
                        <div class="used-row <?= $u['status'] === 'declined' ? 'used-declined' : '' ?>">
                            <div class="flex-grow-1" style="min-width:0">
                                <div class="used-name"><?= htmlspecialchars($u['part_name']) ?></div>
                                <div class="used-meta"><?= htmlspecialchars($u['sku'] ?? '—') ?> · ₱<?= number_format($u['unit_price'], 2) ?> each</div>
                            </div>
                            <span class="used-qty">×<?= (int)$u['quantity'] ?></span>
                            <span class="used-total">₱<?= number_format($u['quantity'] * $u['unit_price'], 2) ?></span>
                            <span class="part-status <?= $u['status'] ?>"><?= $u['status'] === 'pending' ? 'Awaiting' : ucfirst($u['status']) ?></span>
                            <?php if ($open): ?>
                            <a href="?booking_id=<?= (int)$booking_id ?>&remove=<?= (int)$u['id'] ?>"
                               class="btn btn-sm btn-outline-danger rounded-circle flex-shrink-0" style="width:28px;height:28px;padding:0;display:inline-flex;align-items:center;justify-content:center;"
                               title="Remove part"><i class="bi bi-x-lg" style="font-size:.7rem"></i></a>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="parts-total-bar">
                        <span class="text-muted">Parts total (billed)
                            <?php if ($pending_total > 0): ?><span class="text-warning">· +₱<?= number_format($pending_total, 2) ?> pending</span><?php endif; ?>
                        </span>
                        <strong class="text-success">₱<?= number_format($approved_total, 2) ?></strong>
                    </div>
                    <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Parts are added to the invoice automatically when the job is completed.</small>
                    <?php if ($pending_count > 0 && $open): ?>
                    <form method="POST" class="m-0 mt-2">
                        <input type="hidden" name="booking_id" value="<?= (int)$booking_id ?>">
                        <button type="submit" name="notify_customer" value="1" class="btn btn-warning text-dark rounded-pill w-100 btn-sm fw-bold">
                            <i class="bi bi-send me-1"></i>Notify Customer — <?= $pending_count ?> part(s) awaiting approval
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php
echo '</div>';
if ($role === 'admin') {
    require 'admin_footer.php';
} else {
    echo '</div>';
    include 'mechanic_sidebar_footer.php';
}
?>
