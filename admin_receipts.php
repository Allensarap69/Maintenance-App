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
    "SELECT b.id AS booking_id, b.schedule_date, b.schedule_start_time, b.schedule_end_time,
            b.status AS booking_status, b.total_price, b.service_ids, b.package_ids, b.created_at AS booked_at,
            p.id AS payment_id, p.amount, p.payment_method, p.transaction_type,
            p.transaction_ref, p.receipt_path, p.status AS payment_status, p.created_at AS payment_at,
            u.name AS customer_name, u.username, u.phone AS customer_phone, u.email AS customer_email,
            v.brand, v.model, v.year_model, v.plate_number,
            pm.name AS primary_mechanic,
            i.id AS invoice_id, i.invoice_no, i.total AS invoice_total
     FROM bookings b
     LEFT JOIN payments p ON p.booking_id = b.id
     LEFT JOIN users u ON u.id = b.user_id
     LEFT JOIN motorcycles v ON v.id = b.vehicle_id
     LEFT JOIN mechanics pm ON pm.id = b.mechanic_id
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

// --- Bulk lookups powering the detail modal (mechanics, services/packages, parts, invoice lines) ---
$decode_ids = function ($raw) {
    $ids = json_decode((string)$raw, true);
    if (!is_array($ids)) $ids = explode(',', (string)$raw);
    return array_values(array_filter(array_map('intval', $ids)));
};

$booking_ids = array_values(array_unique(array_map('intval', array_column($payments, 'booking_id'))));
$in_bookings = $booking_ids ? implode(',', $booking_ids) : '0';
$mech_map = $parts_map = $svc_map = $pkg_map = $inv_map = [];

try {
    foreach ($pdo->query("SELECT bm.booking_id, m.name FROM booking_mechanics bm JOIN mechanics m ON m.id = bm.mechanic_id WHERE bm.booking_id IN ($in_bookings) ORDER BY m.name")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $mech_map[$r['booking_id']][] = $r['name'];
    }
    foreach ($pdo->query("SELECT bp.booking_id, bp.quantity, bp.unit_price, bp.status, pt.name FROM booking_parts bp JOIN parts pt ON pt.id = bp.part_id WHERE bp.booking_id IN ($in_bookings) ORDER BY bp.id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $parts_map[$r['booking_id']][] = $r;
    }
} catch (Exception $e) { /* junction/parts tables may not exist yet */ }

$svc_ids = $pkg_ids = [];
foreach ($payments as $p) {
    foreach ($decode_ids($p['service_ids']) as $id) $svc_ids[$id] = true;
    foreach ($decode_ids($p['package_ids']) as $id) $pkg_ids[$id] = true;
}
if ($svc_ids) {
    foreach ($pdo->query("SELECT id, service_name, price FROM services WHERE id IN (" . implode(',', array_keys($svc_ids)) . ")")->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $svc_map[(int)$s['id']] = $s;
    }
}
try {
    if ($pkg_ids) {
        foreach ($pdo->query("SELECT id, package_name, price FROM service_packages WHERE id IN (" . implode(',', array_keys($pkg_ids)) . ")")->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $pkg_map[(int)$s['id']] = $s;
        }
    }
    $invoice_ids = array_values(array_unique(array_filter(array_map('intval', array_column($payments, 'invoice_id')))));
    if ($invoice_ids) {
        foreach ($pdo->query("SELECT invoice_id, item_type, description, quantity, line_total FROM invoice_items WHERE invoice_id IN (" . implode(',', $invoice_ids) . ") ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $inv_map[$r['invoice_id']][] = $r;
        }
    }
} catch (Exception $e) { /* management tables may not exist yet */ }

// --- Emergency requests (no payment table link — the "receipt" is the request slip) ---
$emergencies = $pdo->query(
    "SELECT esr.id, esr.motorcycle_issue, esr.problem_description, esr.image_path, esr.service_type,
            esr.request_status, esr.priority, esr.location, esr.location_description, esr.contact_number,
            esr.latitude, esr.longitude, esr.created_at, esr.updated_at,
            u.name AS customer_name, u.username, mech.name AS mechanic_name,
            v.brand, v.model, v.year_model, v.plate_number
     FROM emergency_service_requests esr
     LEFT JOIN users u ON u.id = esr.customer_id
     LEFT JOIN mechanics mech ON mech.id = esr.assigned_mechanic_id
     LEFT JOIN motorcycles v ON v.id = esr.motorcycle_id
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
    /* Details modal — receipt-style document */
    .rct-receipt-dialog { max-width:660px; }
    .rct-receipt-close { position:absolute; top:.7rem; right:.75rem; z-index:5; filter:invert(1) grayscale(100%); opacity:.85; }
    .rcpt { background:#fff; border-radius:inherit; overflow:hidden; font-size:.8rem; }
    .rcpt-head { display:flex; justify-content:space-between; align-items:center; gap:1rem; padding:1.05rem 1.4rem; background:linear-gradient(135deg,#0f172a,#1e3a5f); color:#fff; position:relative; }
    .rcpt-head::after { content:''; position:absolute; left:0; right:0; bottom:0; height:3px; background:linear-gradient(90deg,#3b82f6,#FACC15); }
    .rcpt-brand { display:flex; align-items:center; gap:.6rem; min-width:0; }
    .rcpt-mark { width:36px; height:36px; border-radius:9px; background:rgba(250,204,21,.15); border:1px solid rgba(250,204,21,.4); color:#FACC15; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
    .rcpt-bname { font-size:.84rem; font-weight:800; }
    .rcpt-bsub { font-size:.58rem; color:rgba(255,255,255,.6); }
    .rcpt-doc { text-align:right; padding-right:1.3rem; }
    .rcpt-doctype { font-size:.56rem; font-weight:800; text-transform:uppercase; letter-spacing:.14em; color:#FACC15; }
    .rcpt-no { font-size:1.15rem; font-weight:800; line-height:1.15; }
    .rcpt-pill { display:inline-block; margin-top:4px; padding:2px 9px; border-radius:99px; font-size:.56rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; border:1px solid; }
    .rcpt-pill.ok { background:rgba(16,185,129,.15); color:#34d399; border-color:rgba(52,211,153,.4); }
    .rcpt-pill.bad { background:rgba(239,68,68,.15); color:#fca5a5; border-color:rgba(252,165,165,.4); }
    .rcpt-pill.warn { background:rgba(250,204,21,.15); color:#fde047; border-color:rgba(250,204,21,.4); }
    .rcpt-meta { display:flex; flex-wrap:wrap; gap:3px 18px; padding:7px 22px; background:#f8fafc; border-bottom:1px solid #e5e7eb; font-size:.68rem; color:#6b7280; }
    .rcpt-meta b { color:#111827; }
    .rcpt-body { padding:14px 22px 16px; }
    .rcpt-cards { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-bottom:14px; }
    .rcpt-card { border:1px solid #e5e7eb; border-radius:9px; padding:8px 10px; min-width:0; }
    .rcpt-card h6 { font-size:.54rem; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#1e3a5f; margin:0 0 3px; }
    .rcpt-card .v { font-size:.76rem; font-weight:700; word-break:break-word; }
    .rcpt-card .s { font-size:.64rem; color:#6b7280; word-break:break-word; }
    .rcpt-label { font-size:.58rem; font-weight:800; text-transform:uppercase; letter-spacing:.1em; color:#6b7280; margin-bottom:2px; }
    .rcpt-items { width:100%; border-collapse:collapse; border-top:2px solid #1e3a5f; }
    .rcpt-items td { padding:7px 0; border-bottom:1px solid #eef2f7; font-size:.76rem; vertical-align:top; }
    .rcpt-items .it-meta { font-size:.64rem; color:#94a3b8; font-weight:500; }
    .rcpt-items .it-amt { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; padding-left:.75rem; }
    .rcpt-total { display:flex; justify-content:space-between; align-items:center; border-top:2px solid #1e3a5f; margin-top:6px; padding-top:7px; font-size:.9rem; font-weight:800; color:#1e3a5f; }
    .rcpt-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:12px; }
    .rcpt-chip { background:#f8fafc; border:1px solid #e5e7eb; border-radius:7px; padding:4px 10px; font-size:.66rem; color:#6b7280; }
    .rcpt-chip b { color:#111827; font-weight:700; }
    .rcpt-detail-dl { display:grid; grid-template-columns:auto 1fr; gap:3px 12px; margin:10px 0 0; font-size:.74rem; }
    .rcpt-detail-dl dt { color:#64748b; font-weight:600; white-space:nowrap; }
    .rcpt-detail-dl dd { margin:0; text-align:right; font-weight:600; word-break:break-word; }
    .rcpt-imglink { margin-top:12px; font-size:.72rem; }
    .rcpt-foot { margin-top:14px; padding-top:10px; border-top:1px dashed #e5e7eb; text-align:center; font-size:.6rem; color:#9ca3af; }
    @media (max-width:560px) { .rcpt-cards { grid-template-columns:1fr; } .rcpt-doc { padding-right:1rem; } }
    html[data-theme="dark"] .rcpt { background:#161e33; color:#e2e8f0; }
    html[data-theme="dark"] .rcpt-meta { background:rgba(255,255,255,.03); border-bottom-color:rgba(255,255,255,.09); color:#94a3b8; }
    html[data-theme="dark"] .rcpt-meta b { color:#e2e8f0; }
    html[data-theme="dark"] .rcpt-card { border-color:#2a3350; }
    html[data-theme="dark"] .rcpt-card h6 { color:#93c5fd; }
    html[data-theme="dark"] .rcpt-card .s { color:#94a3b8; }
    html[data-theme="dark"] .rcpt-items { border-top-color:#3b82f6; }
    html[data-theme="dark"] .rcpt-items td { border-bottom-color:rgba(255,255,255,.07); }
    html[data-theme="dark"] .rcpt-items .it-meta { color:#64748b; }
    html[data-theme="dark"] .rcpt-total { border-top-color:#3b82f6; color:#93c5fd; }
    html[data-theme="dark"] .rcpt-chip { background:#141b30; border-color:#2a3350; color:#94a3b8; }
    html[data-theme="dark"] .rcpt-chip b { color:#e2e8f0; }
    html[data-theme="dark"] .rcpt-detail-dl dt { color:#94a3b8; }
    html[data-theme="dark"] .rcpt-imglink { color:#cbd5e1; border-color:#2a3350; }
    html[data-theme="dark"] .rcpt-foot { border-top-color:rgba(255,255,255,.12); color:#64748b; }
    .rct-empty { text-align:center; padding:2.5rem 1rem; color:#94a3b8; }
    .rct-empty i { font-size:2rem; display:block; margin-bottom:.5rem; }
    html[data-theme="dark"] .rct-stat { background:#141b30; border-color:rgba(255,255,255,.09); box-shadow:0 4px 20px rgba(0,0,0,.35); }
    html[data-theme="dark"] .rct-stat-value { color:#e2e8f0; }
    html[data-theme="dark"] .i-blue { color:#93c5fd; }
    html[data-theme="dark"] .i-green { color:#34d399; }
    html[data-theme="dark"] .i-red { color:#f87171; }
    html[data-theme="dark"] .i-amber { color:#fbbf24; }
    html[data-theme="dark"] .rct-head { background:rgba(255,255,255,.03); color:#e2e8f0; border-color:rgba(255,255,255,.09); }
    html[data-theme="dark"] .rct-pill { background:#161e33; border-color:#2a3350; color:#cbd5e1; }
    html[data-theme="dark"] .rct-pill:hover { border-color:#FACC15; color:#fff; }
    html[data-theme="dark"] .rct-pill.active { background:#FACC15; border-color:#FACC15; color:#111827; }
    html[data-theme="dark"] .rct-table thead th { background:#141b30; color:#94a3b8; border-bottom-color:rgba(255,255,255,.09) !important; }
    html[data-theme="dark"] .rct-nothumb { background:#161e33; color:#64748b; }
    html[data-theme="dark"] .rct-thumb { border-color:rgba(255,255,255,.12); }
    html[data-theme="dark"] .rct-ref { color:#94a3b8; }
    html[data-theme="dark"] .modal-content { background:#161e33; color:#e2e8f0; }

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

    html[data-theme="dark"] .rct-seg { background:#161e33; box-shadow:inset 0 0 0 1px #2a3350; }
    html[data-theme="dark"] .rct-seg-btn { color:#94a3b8; }
    html[data-theme="dark"] .rct-seg-btn:hover { color:#e2e8f0; }
    html[data-theme="dark"] .rct-seg-btn.active { color:#111827; }
    html[data-theme="dark"] .rct-table { --bs-table-hover-bg: rgba(255,255,255,.05); --bs-table-color: #e2e8f0; --bs-table-border-color: rgba(255,255,255,.07); }
    html[data-theme="dark"] .rct-method.m-gcash { color:#93c5fd; border-color:rgba(147,197,253,.4); }
    html[data-theme="dark"] .rct-method.m-cash { color:#34d399; border-color:rgba(52,211,153,.35); }
    html[data-theme="dark"] .rct-method.m-other { color:#94a3b8; border-color:#2a3350; }
    html[data-theme="dark"] .rct-search,
    html[data-theme="dark"] .rct-select { background:#161e33; border-color:#2a3350; color:#e2e8f0; }
    html[data-theme="dark"] .rct-search::placeholder { color:#64748b; }
    /* Badges that ship with light-only colors */
    html[data-theme="dark"] .rct-page .badge.bg-light { background:#1a2340 !important; color:#cbd5e1 !important; border-color:#2a3350 !important; }
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
    html[data-theme="dark"] .modal-content { border:1px solid #2a3350; }
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
                        $vehicle = trim("{$p['year_model']} {$p['brand']} {$p['model']}");
                        $mechanics = $mech_map[$p['booking_id']] ?? [];
                        if (!$mechanics && $p['primary_mechanic']) $mechanics = [$p['primary_mechanic']];
                        $sched = date('M j, Y', strtotime($p['schedule_date']));
                        if ($p['schedule_start_time']) {
                            $sched .= ' · ' . date('g:i A', strtotime($p['schedule_start_time']));
                            if ($p['schedule_end_time']) $sched .= '–' . date('g:i A', strtotime($p['schedule_end_time']));
                        }
                        // Itemized charges — invoice lines when issued, else booked services/packages + parts
                        $items = [];
                        $detail_total = '₱' . number_format((float)$p['total_price'], 2);
                        if (!empty($p['invoice_id']) && !empty($inv_map[$p['invoice_id']])) {
                            foreach ($inv_map[$p['invoice_id']] as $it) {
                                $items[] = [
                                    'desc'   => $it['description'],
                                    'meta'   => ucfirst($it['item_type']) . ((int)$it['quantity'] > 1 ? ' ×' . (int)$it['quantity'] : ''),
                                    'amount' => '₱' . number_format((float)$it['line_total'], 2),
                                ];
                            }
                            if ($p['invoice_total'] !== null) $detail_total = '₱' . number_format((float)$p['invoice_total'], 2);
                        } else {
                            foreach ($decode_ids($p['service_ids']) as $sid) {
                                if (isset($svc_map[$sid])) $items[] = ['desc' => $svc_map[$sid]['service_name'], 'meta' => 'Service', 'amount' => '₱' . number_format((float)$svc_map[$sid]['price'], 2)];
                            }
                            foreach ($decode_ids($p['package_ids']) as $pid) {
                                if (isset($pkg_map[$pid])) $items[] = ['desc' => $pkg_map[$pid]['package_name'], 'meta' => 'Package', 'amount' => '₱' . number_format((float)$pkg_map[$pid]['price'], 2)];
                            }
                            foreach ($parts_map[$p['booking_id']] ?? [] as $pt) {
                                $items[] = [
                                    'desc'   => $pt['name'],
                                    'meta'   => 'Part ×' . (int)$pt['quantity'] . ' · ' . ucfirst($pt['status']),
                                    'amount' => '₱' . number_format((float)$pt['unit_price'] * (int)$pt['quantity'], 2),
                                ];
                            }
                        }
                        // Receipt-style payload for the detail modal
                        $receipt = [
                            'doc'        => $is_rej_booking ? 'Booking Record' : 'Service Receipt',
                            'no'         => '#' . sprintf('%04d', (int)$p['booking_id']),
                            'statusText' => $is_rej_booking ? 'Rejected' : 'Completed',
                            'statusCls'  => $is_rej_booking ? 'bad' : 'ok',
                            'meta'       => array_values(array_filter([
                                $p['payment_at'] ? ['Issued', date('M j, Y g:i A', strtotime($p['payment_at']))] : null,
                                ['Schedule', $sched],
                                $p['booked_at'] ? ['Booked On', date('M j, Y', strtotime($p['booked_at']))] : null,
                            ])),
                            'cards'      => [
                                ['label' => 'Customer', 'v' => $customer, 'subs' => array_values(array_filter([$p['customer_phone'], $p['customer_email']]))],
                                ['label' => 'Vehicle', 'v' => $vehicle ?: 'N/A', 'subs' => array_values(array_filter([$p['plate_number'] ? 'Plate: ' . $p['plate_number'] : null]))],
                                ['label' => 'Mechanic(s)', 'v' => $mechanics ? implode(', ', $mechanics) : 'Unassigned', 'subs' => []],
                            ],
                            'itemsLabel' => 'Services & Charges',
                            'items'      => $items,
                            'totalLabel' => $p['invoice_id'] ? 'Invoice Total' : 'Booking Total',
                            'total'      => $detail_total,
                            'chips'      => array_values(array_filter([
                                $has_pay ? ['Method', ucfirst((string)$p['payment_method'])] : ['Payment', 'No payment recorded'],
                                $has_pay && $p['transaction_type'] ? ['Type', $p['transaction_type'] === 'deposit' ? 'Deposit' : 'Full payment'] : null,
                                $has_pay && $p['transaction_ref'] ? ['Ref No', $p['transaction_ref']] : null,
                                $has_pay ? ['Payment', ucfirst($st)] : null,
                                $has_pay && $p['amount'] !== null ? ['Amount Paid', '₱' . number_format((float)$p['amount'], 2)] : null,
                                $p['invoice_no'] ? ['Invoice', $p['invoice_no']] : null,
                            ])),
                            'img'      => $has_img ? $p['receipt_path'] : '',
                            'imgLabel' => 'View uploaded receipt',
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
                                    data-receipt="<?= htmlspecialchars(json_encode($receipt), ENT_QUOTES) ?>"><i class="bi bi-eye"></i></button>
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
                        $clean_txt = fn($s) => trim((string)preg_replace('/^[\s\'",]+|[\s\'",]+$/u', '', (string)$s));
                        $e_vehicle = trim("{$e['year_model']} {$e['brand']} {$e['model']}");
                        $e_desc = $clean_txt($e['problem_description']);
                        $e_loc  = $clean_txt($e['location_description']) ?: $clean_txt($e['location']);
                        $e_receipt = [
                            'doc'        => 'Emergency Request Slip',
                            'no'         => '#' . sprintf('%04d', (int)$e['id']),
                            'statusText' => ucwords(str_replace('_', ' ', $e['request_status'])),
                            'statusCls'  => $grp === 'completed' ? 'ok' : 'bad',
                            'meta'       => array_values(array_filter([
                                ['Requested', date('M j, Y g:i A', strtotime($e['created_at']))],
                                $e['updated_at'] ? ['Last Update', date('M j, Y g:i A', strtotime($e['updated_at']))] : null,
                                ['Priority', ucfirst($e['priority'] ?? 'medium')],
                            ])),
                            'cards'      => [
                                ['label' => 'Customer', 'v' => $customer, 'subs' => array_values(array_filter([$e['contact_number']]))],
                                ['label' => 'Vehicle', 'v' => $e_vehicle ?: 'N/A', 'subs' => array_values(array_filter([$e['plate_number'] ? 'Plate: ' . $e['plate_number'] : null]))],
                                ['label' => 'Mechanic', 'v' => $e['mechanic_name'] ?: 'Unassigned', 'subs' => []],
                            ],
                            'itemsLabel' => 'Requested Service',
                            'items'      => [[
                                'desc'   => $issue_name,
                                'meta'   => $e['service_type'] === 'tow_service' ? 'Tow & Lift' : 'On-Site Repair',
                                'amount' => isset($pm[1]) ? '₱' . number_format((float)str_replace(',', '', $pm[1]), 2) : '',
                            ]],
                            'details'    => array_values(array_filter([
                                $e_desc ? ['Description', $e_desc] : null,
                                $e_loc ? ['Location', $e_loc] : null,
                                (!empty($e['latitude']) && !empty($e['longitude'])) ? ['Coordinates', $e['latitude'] . ', ' . $e['longitude']] : null,
                            ])),
                            'img'      => $has_img ? $e['image_path'] : '',
                            'imgLabel' => 'View issue photo',
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
                                    data-receipt="<?= htmlspecialchars(json_encode($e_receipt), ENT_QUOTES) ?>"><i class="bi bi-eye"></i></button>
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
    <div class="modal-dialog modal-dialog-centered rct-receipt-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <button type="button" class="btn-close rct-receipt-close" data-bs-dismiss="modal" aria-label="Close"></button>
            <div class="modal-body p-0" id="rctDetailBody"></div>
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
        if (!t) return; // opened programmatically — src/label already set
        document.getElementById('rctImgFull').src = t.dataset.imgSrc;
        document.getElementById('rctImgLabel').textContent = t.dataset.imgLabel;
    });

    // Details modal — receipt-style rendering
    const rcEl = (tag, cls, text) => {
        const el = document.createElement(tag);
        if (cls) el.className = cls;
        if (text !== undefined && text !== null) el.textContent = text;
        return el;
    };

    function buildReceipt(r) {
        const root = rcEl('div', 'rcpt');
        // Header: brand + doc type/no + status pill
        const head = rcEl('div', 'rcpt-head');
        const brand = rcEl('div', 'rcpt-brand');
        const mark = rcEl('div', 'rcpt-mark');
        mark.innerHTML = '<i class="bi bi-wrench-adjustable-circle"></i>';
        const bt = rcEl('div');
        bt.append(rcEl('div', 'rcpt-bname', 'Advance Motorcycle Service'),
                  rcEl('div', 'rcpt-bsub', 'Official Service Document'));
        brand.append(mark, bt);
        const doc = rcEl('div', 'rcpt-doc');
        doc.append(rcEl('div', 'rcpt-doctype', r.doc || 'Record'),
                   rcEl('div', 'rcpt-no', r.no || ''));
        if (r.statusText) doc.appendChild(rcEl('span', 'rcpt-pill ' + (r.statusCls || 'warn'), r.statusText));
        head.append(brand, doc);
        root.appendChild(head);
        // Meta strip
        if (r.meta && r.meta.length) {
            const meta = rcEl('div', 'rcpt-meta');
            r.meta.forEach(([k, v]) => {
                const s = rcEl('span');
                s.append(rcEl('b', '', k + ':'), document.createTextNode(' ' + v));
                meta.appendChild(s);
            });
            root.appendChild(meta);
        }
        const bodyEl = rcEl('div', 'rcpt-body');
        // Info cards
        if (r.cards && r.cards.length) {
            const cards = rcEl('div', 'rcpt-cards');
            r.cards.forEach(c => {
                const card = rcEl('div', 'rcpt-card');
                card.append(rcEl('h6', '', c.label), rcEl('div', 'v', c.v));
                (c.subs || []).forEach(s => card.appendChild(rcEl('div', 's', s)));
                cards.appendChild(card);
            });
            bodyEl.appendChild(cards);
        }
        // Itemized charges
        if (r.items && r.items.length) {
            bodyEl.appendChild(rcEl('div', 'rcpt-label', r.itemsLabel || 'Itemized Charges'));
            const tb = document.createElement('tbody');
            r.items.forEach(it => {
                const tr = document.createElement('tr');
                const d = rcEl('td', '', it.desc);
                if (it.meta) d.appendChild(rcEl('div', 'it-meta', it.meta));
                tr.append(d, rcEl('td', 'it-amt', it.amount));
                tb.appendChild(tr);
            });
            const tbl = rcEl('table', 'rcpt-items');
            tbl.appendChild(tb);
            bodyEl.appendChild(tbl);
            if (r.total) {
                const tt = rcEl('div', 'rcpt-total');
                tt.append(rcEl('span', '', r.totalLabel || 'Total'), rcEl('span', '', r.total));
                bodyEl.appendChild(tt);
            }
        }
        // Extra detail rows (e.g. emergency location/description)
        if (r.details && r.details.length) {
            const dl = rcEl('dl', 'rcpt-detail-dl');
            r.details.forEach(([k, v]) => dl.append(rcEl('dt', '', k), rcEl('dd', '', v)));
            bodyEl.appendChild(dl);
        }
        // Payment chips
        if (r.chips && r.chips.length) {
            const chips = rcEl('div', 'rcpt-chips');
            r.chips.forEach(([k, v]) => {
                const c = rcEl('span', 'rcpt-chip');
                c.append(document.createTextNode(k + ': '), rcEl('b', '', v));
                chips.appendChild(c);
            });
            bodyEl.appendChild(chips);
        }
        // Uploaded receipt/photo link → image modal
        if (r.img) {
            const btn = rcEl('button', 'btn btn-sm btn-outline-secondary rcpt-imglink');
            btn.type = 'button';
            btn.innerHTML = '<i class="bi bi-image me-1"></i>' + (r.imgLabel || 'View attachment');
            btn.addEventListener('click', () => {
                document.getElementById('rctImgFull').src = r.img;
                document.getElementById('rctImgLabel').textContent = (r.doc || 'Receipt') + ' ' + (r.no || '');
                bootstrap.Modal.getOrCreateInstance(imgModal).show();
            });
            bodyEl.appendChild(btn);
        }
        bodyEl.appendChild(rcEl('div', 'rcpt-foot', 'Advance Motorcycle Service · System-generated record'));
        root.appendChild(bodyEl);
        return root;
    }

    const detailModal = document.getElementById('rctDetailModal');
    const detailBody = document.getElementById('rctDetailBody');
    detailModal.addEventListener('show.bs.modal', e => {
        const t = e.relatedTarget;
        if (!t) return;
        let r = {};
        try { r = JSON.parse(t.dataset.receipt || '{}'); } catch (_) {}
        detailBody.innerHTML = '';
        detailBody.appendChild(buildReceipt(r));
    });
})();
</script>

<?php require 'admin_footer.php'; ?>
