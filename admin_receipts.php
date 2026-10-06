<?php
session_start();
require 'db.php';
require 'management_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

ensure_management_tables($pdo);

// --- All payment records (receipts uploaded by customers live in the payments table) ---
$payments = $pdo->query(
    "SELECT b.id AS booking_id, b.schedule_date, b.status AS booking_status, b.total_price,
            p.id AS payment_id, p.amount, p.payment_method, p.transaction_type,
            p.transaction_ref, p.receipt_path, p.status AS payment_status, p.created_at AS payment_at,
            u.name AS customer_name, u.username,
            i.id AS invoice_id, i.invoice_no
     FROM bookings b
     LEFT JOIN payments p ON p.booking_id = b.id
     LEFT JOIN users u ON u.id = b.user_id
     LEFT JOIN invoices i ON i.booking_id = b.id
     WHERE b.status IN ('completed', 'rejected', 'deposit_rejected')
     ORDER BY b.schedule_date DESC, b.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// bookings_status.php treats a cash payment on a completed booking as verified
// (cash is collected when the service is done) — apply the same convention here
foreach ($payments as &$p) {
    $eff = $p['payment_status'];
    if ($eff === 'pending' && $p['booking_status'] === 'completed' && strtolower((string)$p['payment_method']) === 'cash') {
        $eff = 'verified';
    }
    $p['_eff'] = $eff;
}
unset($p);

// --- Emergency requests (no payment table link — the "receipt" is the request slip) ---
$emergencies = $pdo->query(
    "SELECT esr.id, esr.motorcycle_issue, esr.image_path, esr.service_type, esr.request_status,
            esr.location, esr.location_description, esr.contact_number, esr.created_at,
            u.name AS customer_name, u.username, mech.name AS mechanic_name
     FROM emergency_service_requests esr
     LEFT JOIN users u ON u.id = esr.customer_id
     LEFT JOIN mechanics mech ON mech.id = esr.assigned_mechanic_id
     WHERE esr.request_status IN ('completed', 'complete', 'declined', 'decline', 'rejected', 'reject')
     ORDER BY esr.created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// --- KPIs ---
$kpi = ['verified_total' => 0.0, 'rejected_cnt' => 0, 'img_cnt' => 0];
foreach ($payments as $p) {
    if ($p['_eff'] === 'verified') $kpi['verified_total'] += (float)$p['amount'];
    elseif (in_array($p['_eff'], ['rejected', 'failed'], true)) $kpi['rejected_cnt']++;
    if ($p['receipt_path'] && file_exists($p['receipt_path'])) $kpi['img_cnt']++;
}

// --- CSV export ---
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="receipts_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Payment ID', 'Booking', 'Booking Status', 'Invoice', 'Customer', 'Method', 'Type', 'Ref No', 'Amount', 'Payment Status', 'Submitted', 'Receipt File']);
    foreach ($payments as $p) {
        fputcsv($out, [
            $p['payment_id'] ?: '-', '#' . $p['booking_id'], $p['booking_status'], $p['invoice_no'] ?: '-',
            $p['customer_name'] ?: $p['username'] ?: 'Unknown',
            $p['payment_method'] ?: '-', $p['transaction_type'] ?: '-', $p['transaction_ref'] ?: '-',
            $p['amount'] !== null ? number_format((float)$p['amount'], 2, '.', '') : '-',
            $p['_eff'] ?: 'no payment', $p['payment_at'] ?: '-', $p['receipt_path'] ?: '-',
        ]);
    }
    exit;
}

if (($_GET['export'] ?? '') === 'emergency') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="emergency_requests_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Request ID', 'Customer', 'Issue', 'Service Type', 'Status', 'Mechanic', 'Location', 'Requested']);
    foreach ($emergencies as $e) {
        fputcsv($out, [
            '#' . $e['id'], $e['customer_name'] ?: $e['username'] ?: 'Unknown',
            $e['motorcycle_issue'], $e['service_type'], $e['request_status'],
            $e['mechanic_name'] ?: 'Unassigned',
            $e['location_description'] ?: $e['location'] ?: '-', $e['created_at'],
        ]);
    }
    exit;
}

$status_badge = [
    'verified' => 'bg-success',
    'pending'  => 'bg-warning text-dark',
    'rejected' => 'bg-danger',
    'failed'   => 'bg-danger',
    'refunded' => 'bg-secondary',
];

$emg_badge = [
    'pending'     => 'bg-warning text-dark',
    'accepted'    => 'bg-info text-dark',
    'assigned'    => 'bg-primary',
    'in_progress' => 'bg-primary',
    'completed'   => 'bg-success',
    'declined'    => 'bg-danger',
];
// Emergency filter groups → request_status values (same mapping as emergency_status.php)
$emg_group = fn(string $s) => match (true) {
    in_array($s, ['completed', 'complete'], true)            => 'completed',
    in_array($s, ['declined', 'decline', 'rejected', 'reject'], true) => 'declined',
    default                                                  => 'active',
};

$pageTitle = 'Payment Receipts';
require 'admin_sidebar_template.php';
?>

<style>
    .rct-page { min-width:0; overflow-x:clip; }
    .rct-page .card { border-radius:12px; }
    .rct-page .card-body { padding:.8rem 1rem; }
    .rct-stat { background:#fff; border:1px solid #e9eef5; border-radius:12px; padding:.65rem .9rem; display:flex; align-items:center; gap:.7rem; box-shadow:0 4px 16px rgba(15,23,42,.05); }
    .rct-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
    .i-blue { color:#1d4ed8; } .i-green { color:#047857; }
    .i-red { color:#dc2626; } .i-amber { color:#b45309; }
    .rct-stat-label { font-size:.64rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; }
    .rct-stat-value { font-weight:700; font-size:.98rem; line-height:1.2; }
    .rct-stat[data-filter] { cursor:pointer; transition:transform .12s ease, box-shadow .12s ease; }
    .rct-stat[data-filter]:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(0,0,0,.08); }
    .rct-head { padding:.55rem 1rem; font-size:.83rem; font-weight:700; display:flex; align-items:center; gap:.5rem; border-bottom:1px solid #eef2f7; background:#f8fafc; color:#1e293b; flex-wrap:wrap; }
    .rct-pill { font-size:.72rem; font-weight:600; padding:.25rem .7rem; border-radius:999px; border:1px solid #e2e8f0; color:#64748b; background:none; white-space:nowrap; }
    .rct-pill:hover { border-color:#cbd5e1; color:#1e293b; }
    .rct-pill.active { background:#FACC15; border-color:#FACC15; color:#111827; }
    .rct-search { font-size:.8rem; padding:.3rem .7rem; width:190px; }
    .rct-select { font-size:.75rem; padding:.25rem .5rem; }
    .rct-table { font-size:.8rem; }
    .rct-table > :not(caption) > * > * { padding:.32rem .5rem !important; white-space:nowrap; }
    .rct-table td { vertical-align:middle; }
    .rct-table thead th { position:sticky; top:0; z-index:2; background:#f1f5f9; font-size:.68rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; border-bottom:1px solid #e2e8f0 !important; }
    .rct-table-scroll { max-height:520px; overflow-y:auto; scrollbar-width:thin; }
    .rct-table-scroll::-webkit-scrollbar { width:6px; }
    .rct-table-scroll::-webkit-scrollbar-thumb { background:rgba(100,116,139,.3); border-radius:3px; }
    .rct-thumb { width:38px; height:38px; object-fit:cover; border-radius:8px; border:1px solid #e2e8f0; cursor:pointer; transition:transform .12s ease; }
    .rct-thumb:hover { transform:scale(1.08); }
    .rct-nothumb { width:38px; height:38px; border-radius:8px; display:flex; align-items:center; justify-content:center; background:#f1f5f9; color:#94a3b8; font-size:.95rem; }
    .rct-cust { max-width:140px; overflow:hidden; text-overflow:ellipsis; }
    .rct-ref { font-size:.72rem; color:#64748b; font-family:monospace; }
    .rct-iconbtn { padding:.2rem .5rem; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; }
    .rct-iconbtn i { font-size:.78rem; }
    .rct-act { color:#64748b; font-size:.95rem; padding:.15rem .35rem; display:inline-flex; align-items:center; text-decoration:none; transition:color .12s ease, transform .12s ease; }
    .rct-act:hover { transform:translateY(-1px); }
    .rct-act.rct-view:hover { color:#d97706; }
    .rct-act.rct-print:hover { color:#1d4ed8; }
    button.rct-act { background:none; border:none; cursor:pointer; }
    /* Details modal */
    .rct-dl { display:grid; grid-template-columns:auto 1fr; gap:.4rem 1rem; font-size:.8rem; margin:0; }
    .rct-dl dt { color:#64748b; font-weight:600; white-space:nowrap; }
    .rct-dl dd { margin:0; font-weight:600; text-align:right; word-break:break-word; }
    html[data-theme="dark"] .rct-dl dt { color:#94a3b8; }
    .rct-empty { text-align:center; padding:2.5rem 1rem; color:#94a3b8; }
    .rct-empty i { font-size:2rem; display:block; margin-bottom:.5rem; }
    html[data-theme="dark"] .rct-stat { background:#1a2b4f; border-color:rgba(255,255,255,.09); box-shadow:0 4px 20px rgba(0,0,0,.35); }
    html[data-theme="dark"] .rct-stat-value { color:#e2e8f0; }
    html[data-theme="dark"] .i-blue { color:#93c5fd; }
    html[data-theme="dark"] .i-green { color:#34d399; }
    html[data-theme="dark"] .i-red { color:#f87171; }
    html[data-theme="dark"] .i-amber { color:#fbbf24; }
    html[data-theme="dark"] .rct-head { background:rgba(255,255,255,.03); color:#e2e8f0; border-color:rgba(255,255,255,.09); }
    html[data-theme="dark"] .rct-pill { background:#16233f; border-color:#3b4d7d; color:#cbd5e1; }
    html[data-theme="dark"] .rct-pill:hover { border-color:#FACC15; color:#fff; }
    html[data-theme="dark"] .rct-pill.active { background:#FACC15; border-color:#FACC15; color:#111827; }
    html[data-theme="dark"] .rct-table thead th { background:#1a2b4f; color:#94a3b8; border-bottom-color:rgba(255,255,255,.09) !important; }
    html[data-theme="dark"] .rct-nothumb { background:#16233f; color:#64748b; }
    html[data-theme="dark"] .rct-thumb { border-color:rgba(255,255,255,.12); }
    html[data-theme="dark"] .rct-ref { color:#94a3b8; }
    html[data-theme="dark"] .modal-content { background:#16233f; color:#e2e8f0; }

    /* ===== Theme polish ===== */
    /* Segmented tab control — sliding gold thumb */
    .rct-seg { position:relative; display:inline-flex; align-items:center; background:#eef2f7; border-radius:999px; padding:3px; }
    .rct-seg-thumb { position:absolute; top:3px; left:3px; height:calc(100% - 6px); width:0; background:#FACC15; border-radius:999px; transition:left .22s ease, width .22s ease; box-shadow:0 2px 8px rgba(250,204,21,.45); }
    .rct-seg-btn { position:relative; z-index:1; border:none; background:transparent; padding:.3rem .95rem; font-size:.76rem; font-weight:600; color:#64748b; border-radius:999px; display:inline-flex; align-items:center; gap:.35rem; transition:color .2s ease, transform .1s ease; white-space:nowrap; }
    .rct-seg-btn i { font-size:.82rem; }
    .rct-seg-btn:hover { color:#1e293b; }
    .rct-seg-btn:active { transform:scale(.96); }
    .rct-seg-btn.active { color:#111827; }
    /* Table hover tint */
    .rct-table { --bs-table-hover-bg: rgba(30,58,95,.04); }
    /* Method badges — outline style so they can't be confused with filled status badges */
    .rct-method { background:transparent !important; border:1px solid; font-weight:600; }
    .rct-method.m-gcash { color:#1d4ed8; border-color:#93c5fd; }
    .rct-method.m-cash { color:#047857; border-color:#6ee7b7; }
    .rct-method.m-other { color:#64748b; border-color:#cbd5e1; }
    /* Inputs */
    .rct-search:focus, .rct-select:focus { border-color:#FACC15; box-shadow:0 0 0 3px rgba(250,204,21,.15); }
    .rct-thumb:hover { box-shadow:0 4px 14px rgba(15,23,42,.2); }

    html[data-theme="dark"] .rct-seg { background:#16233f; box-shadow:inset 0 0 0 1px #3b4d7d; }
    html[data-theme="dark"] .rct-seg-btn { color:#94a3b8; }
    html[data-theme="dark"] .rct-seg-btn:hover { color:#e2e8f0; }
    html[data-theme="dark"] .rct-seg-btn.active { color:#111827; }
    html[data-theme="dark"] .rct-table { --bs-table-hover-bg: rgba(255,255,255,.05); --bs-table-color: #e2e8f0; --bs-table-border-color: rgba(255,255,255,.07); }
    html[data-theme="dark"] .rct-method.m-gcash { color:#93c5fd; border-color:rgba(147,197,253,.4); }
    html[data-theme="dark"] .rct-method.m-cash { color:#34d399; border-color:rgba(52,211,153,.35); }
    html[data-theme="dark"] .rct-method.m-other { color:#94a3b8; border-color:#3b4d7d; }
    html[data-theme="dark"] .rct-search,
    html[data-theme="dark"] .rct-select { background:#16233f; border-color:#3b4d7d; color:#e2e8f0; }
    html[data-theme="dark"] .rct-search::placeholder { color:#64748b; }
    /* Badges that ship with light-only colors */
    html[data-theme="dark"] .rct-page .badge.bg-light { background:#22335a !important; color:#cbd5e1 !important; border-color:#3b4d7d !important; }
    html[data-theme="dark"] .rct-page .bg-success-subtle { background:rgba(16,185,129,.15) !important; color:#34d399 !important; border-color:rgba(16,185,129,.3) !important; }
    html[data-theme="dark"] .rct-page .bg-info { background:rgba(59,130,246,.25) !important; color:#bfdbfe !important; }
    html[data-theme="dark"] .rct-page .bg-warning { background:rgba(250,204,21,.2) !important; color:#fde047 !important; }
    /* Outcome label under the booking number */
    html[data-theme="dark"] .rct-page .text-success { color:#34d399 !important; }
    html[data-theme="dark"] .rct-page .text-danger { color:#f87171 !important; }
    /* Bare icon links + modal chrome */
    html[data-theme="dark"] .rct-act { color:#94a3b8; }
    html[data-theme="dark"] .rct-act.rct-view:hover { color:#fbbf24; }
    html[data-theme="dark"] .rct-act.rct-print:hover { color:#93c5fd; }
    html[data-theme="dark"] .modal-content { border:1px solid #3b4d7d; }
    html[data-theme="dark"] .modal-header { border-bottom-color:rgba(255,255,255,.09); }
    html[data-theme="dark"] .modal .btn-close { filter:invert(1) grayscale(100%); }
    @media (min-width: 992px) {
        .rct-page { display:flex; flex-direction:column; min-height:100%; }
        #rctCard { flex:1 1 0; min-height:0; display:flex; flex-direction:column; }
        #rctCard .rct-table-scroll { flex:1 1 0; min-height:0; max-height:none; }
    }
</style>

<div class="container-fluid py-3 rct-page">
    <div class="row g-2 mb-3">
        <div class="col-6 col-lg-3">
            <div class="rct-stat" data-filter="verified" role="button" title="Show verified payments">
                <div class="rct-icon i-green"><i class="bi bi-check-circle"></i></div>
                <div><div class="rct-stat-label">Collected (verified)</div><div class="rct-stat-value">₱<?= number_format($kpi['verified_total'], 2) ?></div></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="rct-stat" data-filter="" role="button" title="Show all records">
                <div class="rct-icon i-amber"><i class="bi bi-collection"></i></div>
                <div><div class="rct-stat-label">Total Records</div><div class="rct-stat-value"><?= count($payments) ?></div></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="rct-stat" data-filter="rejected" role="button" title="Show rejected payments">
                <div class="rct-icon i-red"><i class="bi bi-x-circle"></i></div>
                <div><div class="rct-stat-label">Rejected</div><div class="rct-stat-value"><?= $kpi['rejected_cnt'] ?></div></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="rct-stat" data-filter="img" role="button" title="Show payments with an uploaded receipt image">
                <div class="rct-icon i-blue"><i class="bi bi-image"></i></div>
                <div><div class="rct-stat-label">Receipts on File</div><div class="rct-stat-value"><?= $kpi['img_cnt'] ?> <small class="text-muted fw-normal">/ <?= count($payments) ?></small></div></div>
            </div>
        </div>
    </div>

    <div class="card" id="rctCard">
        <div class="rct-head">
            <div class="rct-seg" id="rctSeg" role="group" aria-label="Receipt type">
                <span class="rct-seg-thumb"></span>
                <button type="button" class="rct-seg-btn active" data-tab="book"><i class="bi bi-calendar-check"></i>Bookings</button>
                <button type="button" class="rct-seg-btn" data-tab="emg"><i class="bi bi-exclamation-triangle"></i>Emergency</button>
            </div>
            <span class="badge bg-secondary" id="rctCount"><?= count($payments) ?></span>
            <div class="d-flex flex-wrap align-items-center gap-1 ms-auto">
                <span id="rctBookFilters" class="d-flex flex-wrap align-items-center gap-1">
                    <button type="button" class="rct-pill active" data-bstat="">All</button>
                    <button type="button" class="rct-pill" data-bstat="completed">Completed</button>
                    <button type="button" class="rct-pill" data-bstat="rejected">Rejected</button>
                </span>
                <span id="rctEmgFilters" class="d-flex flex-wrap align-items-center gap-1 d-none">
                    <button type="button" class="rct-pill active" data-emg="">All</button>
                    <button type="button" class="rct-pill" data-emg="completed">Completed</button>
                    <button type="button" class="rct-pill" data-emg="declined">Declined</button>
                </span>
                <input type="text" class="form-control form-control-sm rct-search" id="rctSearch" placeholder="Search booking, customer, ref...">
                <a href="?export=csv" class="btn btn-sm btn-success rct-iconbtn" id="rctExport" title="Export CSV"><i class="bi bi-file-earmark-spreadsheet"></i></a>
            </div>
        </div>
        <div class="rct-table-scroll" id="rctWrapBook">
            <table class="table table-sm table-hover rct-table" id="rctTable">
                <thead>
                    <tr><th>Receipt</th><th>Booking</th><th>Customer</th><th>Method</th><th>Type</th>
                        <th>Ref No.</th><th class="text-end">Amount</th><th>Status</th><th>Submitted</th><th></th></tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                    <tr><td colspan="10"><div class="rct-empty"><i class="bi bi-receipt"></i>No payment records yet.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($payments as $p):
                        $has_img = $p['receipt_path'] && file_exists($p['receipt_path']);
                        $has_pay = $p['payment_id'] !== null;
                        $st = $has_pay ? ($p['_eff'] !== '' ? $p['_eff'] : 'rejected') : 'none';
                        $is_rej_booking = in_array($p['booking_status'], ['rejected', 'deposit_rejected'], true);
                        $customer = trim($p['customer_name'] ?: $p['username'] ?: 'Unknown');
                        $pmethod = strtolower((string)$p['payment_method']);
                        $search = strtolower("#{$p['booking_id']} {$customer} {$p['transaction_ref']} {$p['invoice_no']} {$p['payment_id']}");
                        $fields = [
                            'Booking'   => '#' . $p['booking_id'],
                            'Customer'  => $customer,
                            'Scheduled' => date('M j, Y', strtotime($p['schedule_date'])),
                            'Status'    => $is_rej_booking ? 'Rejected' : 'Completed',
                            'Payment'   => $has_pay ? ucfirst($st) . ' payment' : 'No payment recorded',
                            'Method'    => $has_pay ? ucfirst((string)$p['payment_method']) : '—',
                            'Type'      => $has_pay ? ($p['transaction_type'] === 'deposit' ? 'Deposit' : 'Full payment') : '—',
                            'Ref No'    => $p['transaction_ref'] ?: '—',
                            'Amount'    => $p['amount'] !== null ? '₱' . number_format((float)$p['amount'], 2) : '—',
                            'Invoice'   => $p['invoice_no'] ?: '—',
                            'Submitted' => $p['payment_at'] ? date('M j, Y g:i A', strtotime($p['payment_at'])) : '—',
                        ];
                    ?>
                    <tr data-status="<?= htmlspecialchars($st) ?>"
                        data-bstatus="<?= in_array($p['booking_status'], ['rejected', 'deposit_rejected'], true) ? 'rejected' : 'completed' ?>"
                        data-method="<?= htmlspecialchars(strtolower($p['payment_method'])) ?>"
                        data-img="<?= $has_img ? '1' : '0' ?>"
                        data-search="<?= htmlspecialchars($search) ?>">
                        <td>
                            <?php if ($has_img): ?>
                            <img src="<?= htmlspecialchars($p['receipt_path']) ?>" class="rct-thumb" alt="receipt"
                                 data-bs-toggle="modal" data-bs-target="#rctImgModal"
                                 data-img-src="<?= htmlspecialchars($p['receipt_path']) ?>"
                                 data-img-label="Booking #<?= (int)$p['booking_id'] ?> · <?= htmlspecialchars($customer) ?>">
                            <?php else: ?>
                            <div class="rct-nothumb" title="No receipt image<?= strtolower($p['payment_method']) === 'cash' ? ' (cash payment)' : '' ?>">
                                <i class="bi bi-<?= strtolower($p['payment_method']) === 'cash' ? 'cash' : 'file-earmark-x' ?>"></i>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><strong>#<?= (int)$p['booking_id'] ?></strong><br><small class="text-muted"><?= date('M j, Y', strtotime($p['schedule_date'])) ?></small></td>
                        <td class="rct-cust" title="<?= htmlspecialchars($customer) ?>"><?= htmlspecialchars($customer) ?></td>
                        <td><?= $has_pay ? '<span class="badge rct-method ' . ($pmethod === 'gcash' ? 'm-gcash' : ($pmethod === 'cash' ? 'm-cash' : 'm-other')) . '">' . htmlspecialchars(ucfirst($p['payment_method'])) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td><?= $has_pay ? '<span class="badge bg-light text-dark border">' . ($p['transaction_type'] === 'deposit' ? 'Deposit' : 'Full') . '</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td><span class="rct-ref"><?= htmlspecialchars($p['transaction_ref'] ?: '—') ?></span></td>
                        <td class="text-end fw-semibold"><?= $p['amount'] !== null ? '₱' . number_format((float)$p['amount'], 2) : '<span class="text-muted">—</span>' ?></td>
                        <td><span class="badge <?= $is_rej_booking ? 'bg-danger' : 'bg-success' ?>"><?= $is_rej_booking ? 'Rejected' : 'Completed' ?></span><br><small class="text-muted"><?= $has_pay ? ucfirst($st) . ' payment' : 'No payment' ?></small></td>
                        <td><?= $p['payment_at'] ? date('M j, Y g:i A', strtotime($p['payment_at'])) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end">
                            <button type="button" class="rct-act rct-view" title="View details"
                                    data-bs-toggle="modal" data-bs-target="#rctDetailModal"
                                    data-title="Booking #<?= (int)$p['booking_id'] ?>"
                                    data-img="<?= $has_img ? htmlspecialchars($p['receipt_path']) : '' ?>"
                                    data-fields="<?= htmlspecialchars(json_encode($fields), ENT_QUOTES) ?>"><i class="bi bi-eye"></i></button>
                            <a href="print_receipt.php?booking_id=<?= (int)$p['booking_id'] ?>" class="rct-act rct-print" title="Print receipt"><i class="bi bi-printer"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr id="rctNoMatch" style="display:none"><td colspan="10"><div class="rct-empty"><i class="bi bi-funnel"></i>No receipts match the current filters.</div></td></tr>
                </tbody>
            </table>
        </div>
        <div class="rct-table-scroll d-none" id="rctWrapEmg">
            <table class="table table-sm table-hover rct-table" id="emgTable">
                <thead>
                    <tr><th>Photo</th><th>Request</th><th>Customer</th><th>Issue</th><th>Type</th>
                        <th>Mechanic</th><th>Status</th><th>Requested</th><th></th></tr>
                </thead>
                <tbody>
                    <?php if (empty($emergencies)): ?>
                    <tr><td colspan="9"><div class="rct-empty"><i class="bi bi-exclamation-triangle"></i>No emergency requests yet.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($emergencies as $e):
                        $has_img = $e['image_path'] && file_exists($e['image_path']);
                        $grp = $emg_group($e['request_status']);
                        $customer = trim($e['customer_name'] ?: $e['username'] ?: 'Unknown');
                        preg_match('/₱([\d,]+(?:\.\d+)?)/', $e['motorcycle_issue'], $pm);
                        $issue_name = trim(preg_replace('/\s*\(₱[\d,\.]+\)\s*/', '', $e['motorcycle_issue']));
                        $search = strtolower("#{$e['id']} {$customer} {$issue_name} {$e['mechanic_name']} {$e['location_description']} {$e['location']}");
                        $e_fields = [
                            'Request'  => '#' . $e['id'],
                            'Customer' => $customer,
                            'Issue'    => $issue_name . (isset($pm[1]) ? ' — ₱' . number_format((float)str_replace(',', '', $pm[1]), 2) : ''),
                            'Type'     => $e['service_type'] === 'tow_service' ? 'Tow & Lift' : 'On-Site Repair',
                            'Mechanic' => $e['mechanic_name'] ?: 'Unassigned',
                            'Location' => $e['location_description'] ?: ($e['location'] ?: '—'),
                            'Contact'  => $e['contact_number'] ?: '—',
                            'Status'   => ucwords(str_replace('_', ' ', $e['request_status'])),
                            'Requested'=> date('M j, Y g:i A', strtotime($e['created_at'])),
                        ];
                    ?>
                    <tr data-emg="<?= htmlspecialchars($grp) ?>"
                        data-status="<?= htmlspecialchars($e['request_status']) ?>"
                        data-search="<?= htmlspecialchars($search) ?>">
                        <td>
                            <?php if ($has_img): ?>
                            <img src="<?= htmlspecialchars($e['image_path']) ?>" class="rct-thumb" alt="issue photo"
                                 data-bs-toggle="modal" data-bs-target="#rctImgModal"
                                 data-img-src="<?= htmlspecialchars($e['image_path']) ?>"
                                 data-img-label="Emergency #<?= (int)$e['id'] ?> · <?= htmlspecialchars($customer) ?>">
                            <?php else: ?>
                            <div class="rct-nothumb" title="No photo attached"><i class="bi bi-image"></i></div>
                            <?php endif; ?>
                        </td>
                        <td><strong>#<?= (int)$e['id'] ?></strong></td>
                        <td class="rct-cust" title="<?= htmlspecialchars($customer) ?>"><?= htmlspecialchars($customer) ?></td>
                        <td>
                            <?= htmlspecialchars($issue_name) ?>
                            <?php if (isset($pm[1])): ?><span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">₱<?= number_format((float)str_replace(',', '', $pm[1]), 2) ?></span><?php endif; ?>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?= $e['service_type'] === 'tow_service' ? 'Tow & Lift' : 'On-Site' ?></span></td>
                        <td class="rct-cust" title="<?= htmlspecialchars($e['mechanic_name'] ?? '') ?>"><?= htmlspecialchars($e['mechanic_name'] ?: '—') ?></td>
                        <td><span class="badge <?= $emg_badge[$e['request_status']] ?? 'bg-secondary' ?>"><?= ucwords(str_replace('_', ' ', $e['request_status'])) ?></span></td>
                        <td><?= date('M j, Y g:i A', strtotime($e['created_at'])) ?></td>
                        <td class="text-end">
                            <a href="print_emergency_receipt.php?request_id=<?= (int)$e['id'] ?>" class="rct-act rct-print" title="Print request slip"><i class="bi bi-printer"></i></a>
                            <button type="button" class="rct-act rct-view" title="View details"
                                    data-bs-toggle="modal" data-bs-target="#rctDetailModal"
                                    data-title="Emergency Request #<?= (int)$e['id'] ?>"
                                    data-img="<?= $has_img ? htmlspecialchars($e['image_path']) : '' ?>"
                                    data-fields="<?= htmlspecialchars(json_encode($e_fields), ENT_QUOTES) ?>"><i class="bi bi-eye"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr id="emgNoMatch" style="display:none"><td colspan="9"><div class="rct-empty"><i class="bi bi-funnel"></i>No requests match the current filters.</div></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="rctImgModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="rctImgLabel">Receipt</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-2">
                <img src="" id="rctImgFull" class="img-fluid rounded" alt="Receipt">
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="rctDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="rctDetailTitle">Details</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <img src="" id="rctDetailImg" class="img-fluid rounded mb-3 d-none" alt="Receipt">
                <dl class="rct-dl" id="rctDetailFields"></dl>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const bookRows = [...document.querySelectorAll('#rctTable tbody tr[data-search]')];
    const emgRows  = [...document.querySelectorAll('#emgTable tbody tr[data-search]')];
    const bstatPills = [...document.querySelectorAll('#rctBookFilters .rct-pill[data-bstat]')];
    const emgPills  = [...document.querySelectorAll('#rctEmgFilters .rct-pill')];
    const searchIn  = document.getElementById('rctSearch');
    const count     = document.getElementById('rctCount');
    const exportBtn = document.getElementById('rctExport');
    const tabs      = [...document.querySelectorAll('.rct-seg-btn')];
    const seg       = document.getElementById('rctSeg');
    const thumb     = seg.querySelector('.rct-seg-thumb');
    let tab = 'book', search = '';
    let book = { bstat: '', status: '', img: false };
    let emg  = { status: '' };

    function apply() {
        let visible = 0;
        if (tab === 'book') {
            bookRows.forEach(r => {
                const ok = (!book.bstat || r.dataset.bstatus === book.bstat)
                    && (!book.status || r.dataset.status === book.status)
                    && (!book.img || r.dataset.img === '1')
                    && (!search || r.dataset.search.includes(search));
                r.style.display = ok ? '' : 'none';
                if (ok) visible++;
            });
            document.getElementById('rctNoMatch').style.display = visible ? 'none' : '';
        } else {
            emgRows.forEach(r => {
                const ok = (!emg.status || r.dataset.emg === emg.status)
                    && (!search || r.dataset.search.includes(search));
                r.style.display = ok ? '' : 'none';
                if (ok) visible++;
            });
            document.getElementById('emgNoMatch').style.display = visible ? 'none' : '';
        }
        count.textContent = visible;
    }

    function moveThumb() {
        const active = seg.querySelector('.rct-seg-btn.active');
        if (!active) return;
        thumb.style.left = active.offsetLeft + 'px';
        thumb.style.width = active.offsetWidth + 'px';
    }
    requestAnimationFrame(moveThumb);
    window.addEventListener('load', moveThumb);
    window.addEventListener('resize', moveThumb);

    function setTab(t) {
        tab = t;
        tabs.forEach(x => x.classList.toggle('active', x.dataset.tab === t));
        moveThumb();
        document.getElementById('rctWrapBook').classList.toggle('d-none', t !== 'book');
        document.getElementById('rctWrapEmg').classList.toggle('d-none', t !== 'emg');
        document.getElementById('rctBookFilters').classList.toggle('d-none', t !== 'book');
        document.getElementById('rctEmgFilters').classList.toggle('d-none', t !== 'emg');
        searchIn.placeholder = t === 'book' ? 'Search booking, customer, ref...' : 'Search request, customer, issue...';
        exportBtn.href = t === 'book' ? '?export=csv' : '?export=emergency';
        apply();
    }

    tabs.forEach(b => b.addEventListener('click', () => setTab(b.dataset.tab)));

    bstatPills.forEach(p => p.addEventListener('click', () => {
        bstatPills.forEach(x => x.classList.remove('active'));
        p.classList.add('active');
        book.bstat = p.dataset.bstat;
        apply();
    }));
    emgPills.forEach(p => p.addEventListener('click', () => {
        emgPills.forEach(x => x.classList.remove('active'));
        p.classList.add('active');
        emg.status = p.dataset.emg;
        apply();
    }));
    searchIn.addEventListener('input', () => { search = searchIn.value.trim().toLowerCase(); apply(); });

    // KPI cards act as filter shortcuts (bookings tab only)
    document.querySelectorAll('.rct-stat[data-filter]').forEach(s => s.addEventListener('click', () => {
        const f = s.dataset.filter;
        book.img = f === 'img';
        book.status = book.img ? '' : f;
        if (tab !== 'book') setTab('book'); else apply();
        document.getElementById('rctCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }));

    // Receipt/issue photo modal (shared by both tabs)
    const imgModal = document.getElementById('rctImgModal');
    imgModal.addEventListener('show.bs.modal', e => {
        const t = e.relatedTarget;
        document.getElementById('rctImgFull').src = t.dataset.imgSrc;
        document.getElementById('rctImgLabel').textContent = t.dataset.imgLabel;
    });

    // Details modal (view icon on both tabs)
    const detailModal = document.getElementById('rctDetailModal');
    detailModal.addEventListener('show.bs.modal', e => {
        const t = e.relatedTarget;
        document.getElementById('rctDetailTitle').textContent = t.dataset.title;
        const img = document.getElementById('rctDetailImg');
        if (t.dataset.img) { img.src = t.dataset.img; img.classList.remove('d-none'); }
        else { img.src = ''; img.classList.add('d-none'); }
        const dl = document.getElementById('rctDetailFields');
        dl.innerHTML = '';
        let fields = {};
        try { fields = JSON.parse(t.dataset.fields || '{}'); } catch (_) {}
        Object.entries(fields).forEach(([k, v]) => {
            const dt = document.createElement('dt');
            const dd = document.createElement('dd');
            dt.textContent = k;
            dd.textContent = v;
            dl.append(dt, dd);
        });
    });
})();
</script>

<?php require 'admin_footer.php'; ?>
