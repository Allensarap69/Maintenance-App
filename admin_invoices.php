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

// Flash message from redirected actions (PRG pattern — prevents double-submit on refresh)
$msg = $_GET['msg'] ?? '';
$msg_type = $_GET['type'] ?? 'success';
function inv_redirect($url, $msg, $type) {
    header("Location: {$url}" . (strpos($url, '?') === false ? '?' : '&') . "msg=" . urlencode($msg) . "&type=" . urlencode($type));
    exit;
}

// One-time form tokens — a resubmitted/replayed POST carries a stale token and is rejected
function inv_token_valid($key) {
    return !empty($_SESSION[$key]) && hash_equals($_SESSION[$key], $_POST['form_token'] ?? '');
}
function inv_token_rotate($key) {
    $_SESSION[$key] = bin2hex(random_bytes(16));
}

// --- Generate invoices for all completed bookings that lack one ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_missing'])) {
    $stmt = $pdo->query(
        "SELECT b.id FROM bookings b
         LEFT JOIN invoices i ON i.booking_id = b.id
         WHERE b.status = 'completed' AND i.id IS NULL"
    );
    $made = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $bid) {
        if (generate_invoice_for_booking($pdo, $bid)) $made++;
    }
    inv_redirect('admin_invoices.php', "✅ Generated {$made} invoice(s) for completed bookings.", 'success');
}

// --- Record a payment against an invoice ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $amount     = (float)($_POST['amount'] ?? 0);
    $method     = in_array($_POST['method'] ?? '', ['cash', 'gcash']) ? $_POST['method'] : 'cash';
    $back       = "admin_invoices.php?view={$invoice_id}";

    if (!inv_token_valid('pay_form_token')) {
        inv_redirect($back, "⚠️ Form already submitted — refresh ignored.", 'error');
    }
    inv_token_rotate('pay_form_token');

    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND status != 'void'");
    $stmt->execute([$invoice_id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inv) {
        inv_redirect($back, "❌ Invoice not found.", 'error');
    } elseif ($amount <= 0) {
        inv_redirect($back, "❌ Payment amount must be greater than zero.", 'error');
    }
    $dup = $pdo->prepare(
        "SELECT id FROM payments WHERE booking_id = ? AND amount = ? AND transaction_type = 'full_payment'
         AND created_at > NOW() - INTERVAL 60 SECOND"
    );
    $dup->execute([$inv['booking_id'], $amount]);
    if ($dup->fetch()) {
        inv_redirect($back, "⚠️ This payment was already recorded — duplicate ignored.", 'error');
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO payments (booking_id, amount, payment_method, transaction_type, transaction_ref, status)
             VALUES (?, ?, ?, 'full_payment', ?, 'verified')"
        )->execute([$inv['booking_id'], $amount, $method, 'INV-' . $invoice_id . '-' . time()]);
        $pdo->commit();
        sync_invoice_payment($pdo, $invoice_id);
        log_audit($pdo, 'payment_recorded', 'invoice', $invoice_id, "₱" . number_format($amount, 2) . " via $method");
        inv_redirect($back, "✅ Payment of ₱" . number_format($amount, 2) . " recorded.", 'success');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        inv_redirect($back, "❌ " . $e->getMessage(), 'error');
    }
}

// --- Void an invoice ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['void_invoice'])) {
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $pdo->prepare("UPDATE invoices SET status = 'void' WHERE id = ?")->execute([$invoice_id]);
    log_audit($pdo, 'invoice_voided', 'invoice', $invoice_id);
    inv_redirect('admin_invoices.php', "✅ Invoice voided.", 'success');
}

// --- Add expense ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
    if (!inv_token_valid('exp_form_token')) {
        inv_redirect('admin_invoices.php', "⚠️ Form already submitted — refresh ignored.", 'error');
    }
    inv_token_rotate('exp_form_token');
    $date    = $_POST['expense_date'] ?? date('Y-m-d');
    $cat     = trim($_POST['category'] ?? 'General') ?: 'General';
    $desc    = trim($_POST['description'] ?? '');
    $amount  = (float)($_POST['amount'] ?? 0);

    if ($desc === '' || $amount <= 0) {
        inv_redirect('admin_invoices.php', "❌ Expense description and a positive amount are required.", 'error');
    }
    $dup = $pdo->prepare(
        "SELECT id FROM expenses WHERE expense_date = ? AND category = ? AND description = ? AND amount = ?
         AND created_at > NOW() - INTERVAL 60 SECOND"
    );
    $dup->execute([$date, $cat, $desc, $amount]);
    if ($dup->fetch()) {
        inv_redirect('admin_invoices.php', "⚠️ Same expense was already recorded — duplicate ignored.", 'error');
    }
    $pdo->prepare("INSERT INTO expenses (expense_date, category, description, amount, created_by) VALUES (?, ?, ?, ?, ?)")
        ->execute([$date, $cat, $desc, $amount, $_SESSION['user_id'] ?? null]);
    log_audit($pdo, 'expense_added', 'expense', $pdo->lastInsertId(), "$desc — ₱" . number_format($amount, 2));
    inv_redirect('admin_invoices.php', "✅ Expense recorded.", 'success');
}

// --- Delete expense ---
if (isset($_GET['del_expense']) && is_numeric($_GET['del_expense'])) {
    $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$_GET['del_expense']]);
    log_audit($pdo, 'expense_deleted', 'expense', (int)$_GET['del_expense']);
    inv_redirect('admin_invoices.php', "✅ Expense deleted.", 'success');
}

// --- Invoice detail view ---
$view_invoice = null;
$view_items = $view_payments = [];
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $stmt = $pdo->prepare(
        "SELECT i.*, u.username AS customer_name, u.email, u.phone
         FROM invoices i JOIN users u ON u.id = i.customer_id WHERE i.id = ?"
    );
    $stmt->execute([$_GET['view']]);
    $view_invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($view_invoice) {
        sync_invoice_payment($pdo, $view_invoice['id']);
        $view_invoice['amount_paid'] = (float)$pdo->query("SELECT amount_paid FROM invoices WHERE id = " . (int)$view_invoice['id'])->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id");
        $stmt->execute([$view_invoice['id']]);
        $view_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT * FROM payments WHERE booking_id = ? ORDER BY created_at");
        $stmt->execute([$view_invoice['booking_id']]);
        $view_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// --- Invoice list (all rows — status/search filters run client-side) ---
$valid_status = ['unpaid', 'partial', 'paid', 'void'];
$status_filter = in_array($_GET['status'] ?? '', $valid_status) ? $_GET['status'] : '';

$invoices = $pdo->query(
    "SELECT i.*, u.username AS customer_name
     FROM invoices i JOIN users u ON u.id = i.customer_id
     ORDER BY i.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$missing_count = (int)$pdo->query(
    "SELECT COUNT(*) FROM bookings b LEFT JOIN invoices i ON i.booking_id = b.id
     WHERE b.status = 'completed' AND i.id IS NULL"
)->fetchColumn();

$fin = $pdo->query(
    "SELECT COALESCE(SUM(total),0) AS invoiced,
            COALESCE(SUM(amount_paid),0) AS collected,
            COALESCE(SUM(GREATEST(total - amount_paid, 0)),0) AS outstanding,
            SUM(status = 'unpaid') AS unpaid_cnt,
            SUM(status = 'partial') AS partial_cnt
     FROM invoices WHERE status != 'void'"
)->fetch(PDO::FETCH_ASSOC);

$expenses = $pdo->query("SELECT e.*, u.username AS recorded_by FROM expenses e LEFT JOIN users u ON u.id = e.created_by ORDER BY e.expense_date DESC, e.id DESC LIMIT 20")
    ->fetchAll(PDO::FETCH_ASSOC);

$status_badge = [
    'unpaid' => 'bg-danger', 'partial' => 'bg-warning text-dark',
    'paid' => 'bg-success', 'void' => 'bg-secondary'
];

$pageTitle = 'Invoices & Payments';
require 'admin_sidebar_template.php';
?>

<div class="container-fluid py-3 inv-page">
    <style>
        .inv-stat { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:.6rem .85rem; display:flex; align-items:center; gap:.7rem; box-shadow:0 2px 8px rgba(0,0,0,.04); height:100%; }
        .inv-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
        .i-blue { background:#eff6ff; color:#1d4ed8; } .i-green { background:#ecfdf5; color:#047857; }
        .i-red { background:#fef2f2; color:#dc2626; } .i-amber { background:#fffbeb; color:#b45309; }
        .inv-stat-label { font-size:.64rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; }
        .inv-stat-value { font-weight:700; font-size:.98rem; line-height:1.2; }
        .inv-head { padding:.55rem 1rem; font-size:.83rem; font-weight:700; display:flex; align-items:center; gap:.5rem; border-bottom:1px solid #eef2f7; }
        .inv-head-slate { background:#f8fafc; color:#1e293b; }
        .inv-head-green { background:#ecfdf5; color:#047857; }
        .inv-head-amber { background:#fff7ed; color:#c2410c; }
        .inv-head-blue { background:#eff6ff; color:#1d4ed8; }
        .inv-pill { font-size:.75rem; font-weight:600; padding:.3rem .85rem; border-radius:999px; border:1px solid #e2e8f0; color:#64748b; text-decoration:none; white-space:nowrap; }
        .inv-pill:hover { border-color:#cbd5e1; color:#1e293b; }
        .inv-pill.active { background:#FACC15; border-color:#FACC15; color:#111827; }
        .inv-table td { vertical-align:middle; }
        /* Compact invoice table — fits the column so no horizontal scroll is needed */
        .inv-table { font-size:.8rem; }
        .inv-table > :not(caption) > * > * { padding:.3rem .4rem !important; white-space:nowrap; }
        .inv-table .inv-cust { max-width:150px; overflow:hidden; text-overflow:ellipsis; }
        .inv-table .inv-view-btn { padding:.1rem .55rem; font-size:.7rem; border-radius:999px; }
        .pay-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:.7rem .9rem; }
        .inv-iconbtn { width:28px; height:28px; padding:0; display:inline-flex; align-items:center; justify-content:center; border-radius:50%; }
        .inv-iconbtn i { font-size:.75rem; }
        .inv-table-scroll { max-height:440px; overflow-y:auto; }
        .inv-table thead th { position:sticky; top:0; z-index:2; background:#f1f5f9; font-size:.68rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; border-bottom:1px solid #e2e8f0 !important; white-space:nowrap; }
        .inv-table-scroll::-webkit-scrollbar { width:6px; }
        .inv-table-scroll::-webkit-scrollbar-thumb { background:rgba(100,116,139,.3); border-radius:3px; }
        button.inv-pill { background:none; }
        tr[data-view] { cursor:pointer; }
        .inv-stat[data-filter] { cursor:pointer; transition:transform .12s ease, box-shadow .12s ease; }
        .inv-stat[data-filter]:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(0,0,0,.08); }
        /* Print: invoice detail card only, hide interactive elements */
        @media print {
            body * { visibility:hidden; }
            #invDetailCard, #invDetailCard * { visibility:visible; }
            #invDetailCard { position:absolute; top:0; left:0; width:100%; }
            #invDetailCard .no-print { display:none !important; }
        }
        .inv-page .card { border-radius:12px; }
        .inv-page .card-body { padding:.8rem 1rem; }
        .inv-page .table-sm > :not(caption) > * > * { padding:.32rem .5rem; }
        .inv-page .form-label { font-size:.68rem; font-weight:600; color:#64748b; margin-bottom:.15rem; }
        html[data-theme="dark"] .inv-page .form-label { color:#94a3b8; }
        html[data-theme="dark"] .inv-stat { background:#1a2b4f; border-color:rgba(255,255,255,.09); box-shadow:0 4px 20px rgba(0,0,0,.35); }
        html[data-theme="dark"] .inv-stat-label { color:#94a3b8; }
        html[data-theme="dark"] .inv-stat-value { color:#e2e8f0; }
        html[data-theme="dark"] .i-blue { background:rgba(59,130,246,.18); color:#93c5fd; }
        html[data-theme="dark"] .i-green { background:rgba(16,185,129,.18); color:#34d399; }
        html[data-theme="dark"] .i-red { background:rgba(239,68,68,.18); color:#f87171; }
        html[data-theme="dark"] .i-amber { background:rgba(245,158,11,.18); color:#fbbf24; }
        html[data-theme="dark"] .inv-head { border-color:rgba(255,255,255,.09); }
        html[data-theme="dark"] .inv-head-slate { background:rgba(255,255,255,.03); color:#e2e8f0; }
        html[data-theme="dark"] .inv-head-green { background:rgba(16,185,129,.12); color:#34d399; }
        html[data-theme="dark"] .inv-head-amber { background:rgba(245,158,11,.12); color:#fbbf24; }
        html[data-theme="dark"] .inv-head-blue { background:rgba(59,130,246,.12); color:#93c5fd; }
        html[data-theme="dark"] .inv-pill { background:#16233f; border-color:#3b4d7d; color:#cbd5e1; }
        html[data-theme="dark"] .inv-pill:hover { border-color:#FACC15; color:#fff; }
        html[data-theme="dark"] .inv-pill.active { background:#FACC15; border-color:#FACC15; color:#111827; }
        html[data-theme="dark"] .pay-card { background:#16233f; border-color:rgba(255,255,255,.09); }
        html[data-theme="dark"] .inv-table thead th { background:#1a2b4f; color:#94a3b8; border-bottom-color:rgba(255,255,255,.09) !important; }
        /* Dark mode: outline buttons via Bootstrap vars so every state stays readable */
        html[data-theme="dark"] .inv-page .btn-outline-primary {
            --bs-btn-color:#93c5fd; --bs-btn-border-color:rgba(147,197,253,.45);
            --bs-btn-hover-color:#fff; --bs-btn-hover-bg:rgba(59,130,246,.3); --bs-btn-hover-border-color:#3b82f6;
            --bs-btn-active-color:#fff; --bs-btn-active-bg:rgba(59,130,246,.35); --bs-btn-active-border-color:#3b82f6;
            --bs-btn-disabled-color:#93c5fd; --bs-btn-disabled-border-color:rgba(147,197,253,.3);
            color:#93c5fd !important; border-color:rgba(147,197,253,.45) !important;
        }
        html[data-theme="dark"] .inv-page .btn-outline-secondary {
            --bs-btn-color:#cbd5e1; --bs-btn-border-color:rgba(255,255,255,.22);
            --bs-btn-hover-color:#fff; --bs-btn-hover-bg:rgba(255,255,255,.08); --bs-btn-hover-border-color:rgba(255,255,255,.35);
            --bs-btn-active-color:#fff; --bs-btn-active-bg:rgba(255,255,255,.12); --bs-btn-active-border-color:rgba(255,255,255,.35);
            --bs-btn-disabled-color:#cbd5e1; --bs-btn-disabled-border-color:rgba(255,255,255,.15);
            color:#cbd5e1 !important; border-color:rgba(255,255,255,.22) !important;
        }
        html[data-theme="dark"] .inv-page .btn-outline-danger {
            --bs-btn-color:#f87171; --bs-btn-border-color:rgba(248,113,113,.45);
            --bs-btn-hover-color:#fff; --bs-btn-hover-bg:rgba(239,68,68,.25); --bs-btn-hover-border-color:#ef4444;
            --bs-btn-active-color:#fff; --bs-btn-active-bg:rgba(239,68,68,.3); --bs-btn-active-border-color:#ef4444;
            --bs-btn-disabled-color:#f87171; --bs-btn-disabled-border-color:rgba(248,113,113,.3);
            color:#f87171 !important; border-color:rgba(248,113,113,.45) !important;
        }
        /* View pill: tinted fill so it reads as a button on dark rows */
        html[data-theme="dark"] .inv-page .inv-view-btn { background:rgba(59,130,246,.14) !important; font-weight:600; }
        html[data-theme="dark"] .inv-page .inv-view-btn:hover { background:rgba(59,130,246,.3) !important; color:#fff !important; }
        /* Compact expense rows for the side column */
        .exp-scroll { max-height:340px; overflow-y:auto; }
        .exp-row { display:flex; align-items:center; gap:.55rem; padding:.45rem .1rem; border-bottom:1px solid #f1f5f9; }
        .exp-row:last-child { border-bottom:none; }
        .exp-name { font-size:.8rem; font-weight:600; line-height:1.25; }
        .exp-meta { font-size:.68rem; color:#94a3b8; }
        .exp-amt { font-size:.82rem; font-weight:700; color:#b45309; white-space:nowrap; }
        .exp-scroll::-webkit-scrollbar { width:6px; }
        .exp-scroll::-webkit-scrollbar-thumb { background:rgba(100,116,139,.3); border-radius:3px; }
        html[data-theme="dark"] .exp-row { border-bottom-color:rgba(255,255,255,.07); }
        html[data-theme="dark"] .exp-amt { color:#fbbf24; }
        /* On lg+ the page fills the visible content area: invoices table on the
           right, Record Expense + Recent Expenses stacked on the left; inner
           regions scroll so content never inflates the row */
        @media (min-width: 992px) {
            .inv-page { display:flex; flex-direction:column; min-height:100%; }
            .inv-cols { flex:1 1 0; min-height:0; }
            .inv-cols > [class*="col"] { min-height:0; }
            #invCard { min-height:0; }
            #invCard .card-body { display:flex; flex-direction:column; min-height:0; }
            #invCard .inv-table-scroll { flex:1 1 0; min-height:0; max-height:none; }
            .inv-exp-card { flex:1 1 0; min-height:0; }
            .inv-exp-card .card-body { flex:1 1 auto; min-height:0; display:flex; flex-direction:column; }
            .inv-exp-card .exp-scroll { flex:1 1 0; min-height:0; max-height:none; }
        }
    </style>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="inv-stat" data-filter="" role="button" title="Show all invoices"><div class="inv-icon i-blue"><i class="bi bi-receipt"></i></div>
                <div><div class="inv-stat-label">Total Invoiced</div><div class="inv-stat-value">₱<?= number_format($fin['invoiced'], 2) ?></div></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="inv-stat" data-filter="paid" role="button" title="Show paid invoices"><div class="inv-icon i-green"><i class="bi bi-cash-stack"></i></div>
                <div><div class="inv-stat-label">Collected</div><div class="inv-stat-value">₱<?= number_format($fin['collected'], 2) ?></div></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="inv-stat" data-filter="unpaid,partial" role="button" title="Show invoices with a balance"><div class="inv-icon i-red"><i class="bi bi-exclamation-circle"></i></div>
                <div><div class="inv-stat-label">Outstanding</div><div class="inv-stat-value">₱<?= number_format($fin['outstanding'], 2) ?></div></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="inv-stat" data-filter="unpaid,partial" role="button" title="Show unpaid & partial invoices"><div class="inv-icon i-amber"><i class="bi bi-hourglass-split"></i></div>
                <div><div class="inv-stat-label">Unpaid / Partial</div><div class="inv-stat-value"><?= (int)$fin['unpaid_cnt'] ?> / <?= (int)$fin['partial_cnt'] ?></div></div>
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

    <?php if ($view_invoice): $bal = max(0, $view_invoice['total'] - $view_invoice['amount_paid']); ?>
    <div class="card mb-3" id="invDetailCard">
        <div class="card-header inv-head inv-head-blue justify-content-between">
            <span><i class="bi bi-receipt me-1"></i>Invoice <?= htmlspecialchars($view_invoice['invoice_no']) ?>
                <span class="badge <?= $status_badge[$view_invoice['status']] ?> ms-2"><?= strtoupper($view_invoice['status']) ?></span>
            </span>
            <div class="d-flex gap-2 no-print">
                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
                <a href="admin_invoices.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3">Close</a>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-7">
                    <p class="mb-1"><strong>Customer:</strong> <?= htmlspecialchars($view_invoice['customer_name']) ?> (<?= htmlspecialchars($view_invoice['email']) ?>)</p>
                    <p class="mb-3"><strong>Booking:</strong> #<?= (int)$view_invoice['booking_id'] ?> · <strong>Issued:</strong> <?= date('M j, Y g:i A', strtotime($view_invoice['created_at'])) ?></p>
                    <table class="table table-sm">
                        <thead><tr><th>Item</th><th>Type</th><th class="text-end">Qty</th><th class="text-end">Unit</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                            <?php foreach ($view_items as $it): ?>
                            <tr>
                                <td><?= htmlspecialchars($it['description']) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= $it['item_type'] ?></span></td>
                                <td class="text-end"><?= (int)$it['quantity'] ?></td>
                                <td class="text-end">₱<?= number_format($it['unit_price'], 2) ?></td>
                                <td class="text-end">₱<?= number_format($it['line_total'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr><td colspan="4" class="text-end text-muted">Services subtotal</td><td class="text-end">₱<?= number_format($view_invoice['subtotal'], 2) ?></td></tr>
                            <tr><td colspan="4" class="text-end text-muted">Parts subtotal</td><td class="text-end">₱<?= number_format($view_invoice['parts_total'], 2) ?></td></tr>
                            <tr class="fw-bold"><td colspan="4" class="text-end">Total</td><td class="text-end">₱<?= number_format($view_invoice['total'], 2) ?></td></tr>
                            <tr><td colspan="4" class="text-end text-success">Paid</td><td class="text-end text-success">₱<?= number_format($view_invoice['amount_paid'], 2) ?></td></tr>
                            <tr class="fw-bold"><td colspan="4" class="text-end text-danger">Balance</td><td class="text-end text-danger">₱<?= number_format($bal, 2) ?></td></tr>
                        </tfoot>
                    </table>
                </div>
                <div class="col-md-5">
                    <h6 class="fw-bold">Payment History</h6>
                    <?php if (empty($view_payments)): ?>
                        <p class="text-muted small">No payments recorded.</p>
                    <?php else: ?>
                    <table class="table table-sm">
                        <thead><tr><th>Date</th><th>Method</th><th>Type</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                            <?php foreach ($view_payments as $pay): ?>
                            <tr>
                                <td><?= date('M j, Y', strtotime($pay['created_at'])) ?></td>
                                <td><?= htmlspecialchars($pay['payment_method']) ?></td>
                                <td><?= htmlspecialchars($pay['transaction_type'] ?: '—') ?></td>
                                <td><span class="badge <?= $pay['status'] === 'verified' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= $pay['status'] ?></span></td>
                                <td class="text-end">₱<?= number_format($pay['amount'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>

                    <?php if ($view_invoice['status'] !== 'void' && $bal > 0.005): ?>
                    <div class="pay-card mt-3 no-print">
                        <h6 class="fw-bold mb-2"><i class="bi bi-cash-coin me-1"></i>Record Payment</h6>
                        <form method="POST" class="row g-2">
                            <input type="hidden" name="record_payment" value="1">
                            <input type="hidden" name="form_token" value="<?= $_SESSION['pay_form_token'] = $_SESSION['pay_form_token'] ?? bin2hex(random_bytes(16)) ?>">
                            <input type="hidden" name="invoice_id" value="<?= (int)$view_invoice['id'] ?>">
                            <div class="col-5">
                                <input type="number" name="amount" step="0.01" min="0.01" max="<?= $bal ?>" class="form-control form-control-sm"
                                       value="<?= number_format($bal, 2, '.', '') ?>" required>
                            </div>
                            <div class="col-4">
                                <select name="method" class="form-select form-select-sm">
                                    <option value="cash">Cash</option>
                                    <option value="gcash">GCash</option>
                                </select>
                            </div>
                            <div class="col-3">
                                <button class="btn btn-sm btn-success w-100 rounded-pill fw-bold">Pay</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <?php if ($view_invoice['status'] !== 'void'): ?>
                    <form method="POST" class="mt-3 no-print" onsubmit="return confirm('Void this invoice? Payments stay on record.');">
                        <input type="hidden" name="invoice_id" value="<?= (int)$view_invoice['id'] ?>">
                        <button name="void_invoice" class="btn btn-sm btn-outline-danger">Void Invoice</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-3 inv-cols">
        <div class="col-lg-8 order-lg-last">
            <div class="card h-100" id="invCard">
        <div class="card-header inv-head inv-head-slate justify-content-between flex-wrap gap-2">
            <span><i class="bi bi-receipt me-1"></i>Invoices</span>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <input type="text" id="invSearch" class="form-control form-control-sm" placeholder="Search invoice, booking, customer..." style="width:220px">
                <div class="d-flex gap-1 flex-wrap" id="invStatusPills">
                    <button type="button" class="inv-pill <?= $status_filter === '' ? 'active' : '' ?>" data-status="">All</button>
                    <?php foreach ($valid_status as $s): ?>
                    <button type="button" class="inv-pill <?= $status_filter === $s ? 'active' : '' ?>" data-status="<?= $s ?>"><?= ucfirst($s) ?></button>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="invExport" class="btn btn-sm btn-outline-secondary inv-iconbtn" title="Export CSV"><i class="bi bi-download"></i></button>
                <?php if ($missing_count > 0): ?>
                <form method="POST">
                    <button name="generate_missing" class="btn btn-sm btn-warning text-dark rounded-pill">
                        <i class="bi bi-plus-circle me-1"></i>Generate <?= $missing_count ?> missing invoice<?= $missing_count > 1 ? 's' : '' ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($invoices)): ?>
                <p class="text-muted mb-0">No invoices yet. They are created automatically when a mechanic completes a job, or use the generate button above for existing bookings.</p>
            <?php else: ?>
            <div class="table-responsive inv-table-scroll">
                <table class="table table-sm table-hover align-middle inv-table" id="invTable">
                    <thead>
                        <tr><th>Invoice</th><th>Customer</th><th>Issued</th>
                            <th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th>
                            <th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $inv): $b = max(0, $inv['total'] - $inv['amount_paid']); ?>
                        <tr data-status="<?= $inv['status'] ?>" data-view="?view=<?= (int)$inv['id'] ?>"
                            data-invoice="<?= htmlspecialchars($inv['invoice_no']) ?>" data-booking="<?= (int)$inv['booking_id'] ?>"
                            data-customer="<?= htmlspecialchars($inv['customer_name']) ?>" data-issued="<?= $inv['created_at'] ?>"
                            data-total="<?= $inv['total'] ?>" data-paid="<?= $inv['amount_paid'] ?>" data-balance="<?= $b ?>">
                            <td><strong><?= htmlspecialchars($inv['invoice_no']) ?></strong><br><small class="text-muted">Booking #<?= (int)$inv['booking_id'] ?></small></td>
                            <td class="inv-cust" title="<?= htmlspecialchars($inv['customer_name']) ?>"><?= htmlspecialchars($inv['customer_name']) ?></td>
                            <td><?= date('M j, Y', strtotime($inv['created_at'])) ?></td>
                            <td class="text-end">₱<?= number_format($inv['total'], 2) ?></td>
                            <td class="text-end text-success">₱<?= number_format($inv['amount_paid'], 2) ?></td>
                            <td class="text-end <?= $b > 0.005 ? 'text-danger fw-bold' : 'text-muted' ?>">₱<?= number_format($b, 2) ?></td>
                            <td><span class="badge <?= $status_badge[$inv['status']] ?>"><?= $inv['status'] ?></span></td>
                            <td><a href="?view=<?= (int)$inv['id'] ?>" class="btn btn-sm btn-outline-primary inv-view-btn">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr id="invNoMatch" style="display:none"><td colspan="8" class="text-center text-muted py-4 small">No invoices match the current filters.</td></tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
            </div>
        </div>

        <div class="col-lg-4 d-flex flex-column">
            <div class="card">
                <div class="card-header inv-head inv-head-amber">
                    <span><i class="bi bi-plus-circle me-1"></i>Record Expense</span>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="add_expense" value="1">
                        <input type="hidden" name="form_token" value="<?= $_SESSION['exp_form_token'] = $_SESSION['exp_form_token'] ?? bin2hex(random_bytes(16)) ?>">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small mb-1">Date</label>
                                <input type="date" name="expense_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-1">Category</label>
                                <select name="category" class="form-select form-select-sm">
                                    <?php foreach (['Parts Purchase', 'Rent', 'Utilities', 'Salary', 'Supplies', 'Equipment', 'General'] as $c): ?>
                                    <option><?= $c ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label small mb-1">Description *</label>
                                <input type="text" name="description" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-7">
                                <label class="form-label small mb-1">Amount ₱ *</label>
                                <input type="number" name="amount" step="0.01" min="0.01" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-5 d-flex align-items-end">
                                <button class="btn btn-sm btn-primary w-100 rounded-pill fw-bold"><i class="bi bi-plus-lg me-1"></i>Record</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mt-3 inv-exp-card">
                <div class="card-header inv-head inv-head-amber justify-content-between">
                    <span><i class="bi bi-clock-history me-1"></i>Recent Expenses</span>
                    <div class="d-flex gap-2 align-items-center">
                        <?php if (!empty($expenses)): ?>
                        <input type="text" id="expSearch" class="form-control form-control-sm" placeholder="Filter..." style="width:100px">
                        <span class="badge rounded-pill bg-light text-dark border text-nowrap">₱<?= number_format(array_sum(array_column($expenses, 'amount')), 2) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($expenses)): ?>
                        <div class="text-center py-4 my-auto text-muted">
                            <i class="bi bi-receipt fs-2 d-block mb-2"></i>
                            <p class="mb-0 small">No expenses recorded yet.</p>
                        </div>
                    <?php else: ?>
                    <div class="exp-scroll" id="expList">
                        <?php foreach ($expenses as $e): ?>
                        <div class="exp-row">
                            <div class="flex-grow-1" style="min-width:0">
                                <div class="exp-name"><?= htmlspecialchars($e['description']) ?></div>
                                <div class="exp-meta"><?= date('M j, Y', strtotime($e['expense_date'])) ?> · <?= htmlspecialchars($e['category']) ?> · <?= htmlspecialchars($e['recorded_by'] ?? '—') ?></div>
                            </div>
                            <span class="exp-amt">₱<?= number_format($e['amount'], 2) ?></span>
                            <a href="?del_expense=<?= (int)$e['id'] ?>" class="btn btn-sm btn-outline-danger inv-iconbtn" title="Delete"
                               onclick="return confirm('Delete this expense?')"><i class="bi bi-trash"></i></a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var table   = document.getElementById('invTable');
    var tbody   = table ? table.querySelector('tbody') : null;
    var search  = document.getElementById('invSearch');
    var pills   = document.getElementById('invStatusPills');
    var noMatch = document.getElementById('invNoMatch');
    var invCard = document.getElementById('invCard');
    var status  = pills && pills.querySelector('.inv-pill.active')
                  ? pills.querySelector('.inv-pill.active').dataset.status : '';

    function applyInvFilters() {
        if (!tbody) return;
        var q       = (search && search.value || '').toLowerCase();
        var allowed = status === '' ? null : status.split(',');
        var visible = 0;
        tbody.querySelectorAll('tr[data-status]').forEach(function (tr) {
            var show = (!allowed || allowed.indexOf(tr.dataset.status) !== -1)
                    && (!q || tr.textContent.toLowerCase().indexOf(q) !== -1);
            tr.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (noMatch) noMatch.style.display = visible ? 'none' : '';
    }

    function setStatusFilter(value) {
        status = value;
        if (pills) {
            pills.querySelectorAll('.inv-pill').forEach(function (p) {
                p.classList.toggle('active', p.dataset.status === value);
            });
        }
        applyInvFilters();
    }

    if (pills) pills.addEventListener('click', function (e) {
        var p = e.target.closest('.inv-pill');
        if (p) setStatusFilter(p.dataset.status);
    });
    if (search) search.addEventListener('input', applyInvFilters);

    // Stat cards act as filter shortcuts
    document.querySelectorAll('.inv-stat[data-filter]').forEach(function (el) {
        el.addEventListener('click', function () {
            setStatusFilter(el.dataset.filter);
            if (invCard) invCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    // Row click opens the invoice (links/buttons still work normally)
    if (tbody) tbody.addEventListener('click', function (e) {
        var tr = e.target.closest('tr[data-view]');
        if (tr && !e.target.closest('a,button,form,input,select')) window.location = tr.dataset.view;
    });

    // Export visible invoices to CSV
    var exportBtn = document.getElementById('invExport');
    if (exportBtn && tbody) {
        exportBtn.addEventListener('click', function () {
            var rows = [['Invoice', 'Booking', 'Customer', 'Issued', 'Total', 'Paid', 'Balance', 'Status']];
            tbody.querySelectorAll('tr[data-status]').forEach(function (tr) {
                if (tr.style.display === 'none') return;
                var d = tr.dataset;
                rows.push([d.invoice, d.booking, d.customer, d.issued, d.total, d.paid, d.balance, d.status]);
            });
            var csv = rows.map(function (r) {
                return r.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(',');
            }).join('\r\n');
            var a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' }));
            a.download = 'invoices.csv';
            a.click();
            URL.revokeObjectURL(a.href);
        });
    }

    // Expenses list filter
    var expSearch = document.getElementById('expSearch');
    var expList   = document.getElementById('expList');
    if (expSearch && expList) {
        expSearch.addEventListener('input', function () {
            var q = expSearch.value.toLowerCase();
            expList.querySelectorAll('.exp-row').forEach(function (row) {
                row.style.display = (!q || row.textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }

    applyInvFilters();
})();
</script>

<?php require 'admin_footer.php'; ?>
