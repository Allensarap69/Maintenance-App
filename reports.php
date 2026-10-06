<?php
session_start();
require 'db.php';
require 'management_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

ensure_management_tables($pdo);

// --- Date range (default: current month) ---
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; }

$presets = [
    'This month'  => [date('Y-m-01'), date('Y-m-d')],
    'Last month'  => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last month'))],
    'Last 30 days'=> [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
    'This year'   => [date('Y-01-01'), date('Y-m-d')],
    'All time'    => ['2020-01-01', date('Y-m-d')],
];

// --- Fetch completed bookings in range ---
$bookings = $pdo->prepare(
    "SELECT b.id, b.schedule_date, b.total_price, b.service_ids, b.package_ids, b.mechanic_id,
            u.username AS customer, m.name AS primary_mechanic,
            (SELECT GROUP_CONCAT(m2.name) FROM booking_mechanics bm JOIN mechanics m2 ON m2.id = bm.mechanic_id WHERE bm.booking_id = b.id) AS team
     FROM bookings b
     LEFT JOIN users u ON u.id = b.user_id
     LEFT JOIN mechanics m ON m.id = b.mechanic_id
     WHERE b.status = 'completed' AND b.schedule_date BETWEEN ? AND ?
     ORDER BY b.schedule_date"
);
$bookings->execute([$from, $to]);
$bookings = $bookings->fetchAll(PDO::FETCH_ASSOC);

// --- Service/package catalog for name + price lookup ---
$svc_names = $pdo->query("SELECT id, service_name, price FROM services")->fetchAll(PDO::FETCH_ASSOC);
$pkg_names = $pdo->query("SELECT id, package_name, price FROM service_packages")->fetchAll(PDO::FETCH_ASSOC);
$svc_map = []; foreach ($svc_names as $s) $svc_map[$s['id']] = $s;
$pkg_map = []; foreach ($pkg_names as $p) $pkg_map[$p['id']] = $p;

// --- Aggregations ---
$service_revenue = 0;          // booked services + packages
$daily_revenue   = [];
$service_counts  = [];         // name => [count, revenue]
$mechanic_perf   = [];         // name => [jobs, revenue]
$customer_spend  = [];

foreach ($bookings as $b) {
    $price = (float)$b['total_price'];
    $service_revenue += $price;
    $daily_revenue[$b['schedule_date']] = ($daily_revenue[$b['schedule_date']] ?? 0) + $price;
    $customer_spend[$b['customer'] ?? 'Unknown'] = ($customer_spend[$b['customer'] ?? 'Unknown'] ?? 0) + $price;

    foreach (json_decode($b['service_ids'] ?? '[]', true) ?: [] as $sid) {
        $s = $svc_map[$sid] ?? null;
        $name = $s['service_name'] ?? "Service #$sid";
        $service_counts[$name]['count'] = ($service_counts[$name]['count'] ?? 0) + 1;
        $service_counts[$name]['revenue'] = ($service_counts[$name]['revenue'] ?? 0) + ($s['price'] ?? 0);
    }
    foreach (json_decode($b['package_ids'] ?? '[]', true) ?: [] as $pid) {
        $p = $pkg_map[$pid] ?? null;
        $name = ($p['package_name'] ?? "Package #$pid") . ' (pkg)';
        $service_counts[$name]['count'] = ($service_counts[$name]['count'] ?? 0) + 1;
        $service_counts[$name]['revenue'] = ($service_counts[$name]['revenue'] ?? 0) + ($p['price'] ?? 0);
    }

    $team = array_filter(array_map('trim', explode(',', $b['team'] ?? '')));
    if (empty($team) && $b['primary_mechanic']) $team = [$b['primary_mechanic']];
    if (empty($team)) $team = ['Unassigned'];
    $share = $price / count($team);
    foreach ($team as $mech) {
        $mechanic_perf[$mech]['jobs'] = ($mechanic_perf[$mech]['jobs'] ?? 0) + 1;
        $mechanic_perf[$mech]['revenue'] = ($mechanic_perf[$mech]['revenue'] ?? 0) + $share;
    }
}

// --- Parts revenue in range ---
$parts_rows = $pdo->prepare(
    "SELECT p.name AS part_name, bp.quantity, bp.unit_price
     FROM booking_parts bp
     JOIN bookings b ON b.id = bp.booking_id
     JOIN parts p ON p.id = bp.part_id
     WHERE b.status = 'completed' AND bp.status = 'approved' AND b.schedule_date BETWEEN ? AND ?"
);
$parts_rows->execute([$from, $to]);
$parts_rows = $parts_rows->fetchAll(PDO::FETCH_ASSOC);
$parts_revenue = 0;
$parts_counts = [];
foreach ($parts_rows as $pr) {
    $rev = $pr['quantity'] * $pr['unit_price'];
    $parts_revenue += $rev;
    $parts_counts[$pr['part_name']]['qty'] = ($parts_counts[$pr['part_name']]['qty'] ?? 0) + $pr['quantity'];
    $parts_counts[$pr['part_name']]['revenue'] = ($parts_counts[$pr['part_name']]['revenue'] ?? 0) + $rev;
}

$total_revenue = $service_revenue + $parts_revenue;

// --- Payments by method (verified, created in range) ---
$pay_methods = $pdo->prepare(
    "SELECT payment_method, SUM(amount) AS total FROM payments
     WHERE status = 'verified' AND DATE(created_at) BETWEEN ? AND ? GROUP BY payment_method"
);
$pay_methods->execute([$from, $to]);
$pay_methods = array_filter(
    $pay_methods->fetchAll(PDO::FETCH_KEY_PAIR),
    fn($v, $k) => $k !== '' && $k !== null && (float)$v > 0,
    ARRAY_FILTER_USE_BOTH
);

// --- Expenses in range ---
$exp_rows = $pdo->prepare("SELECT category, SUM(amount) AS total FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
$exp_rows->execute([$from, $to]);
$expense_by_cat = $exp_rows->fetchAll(PDO::FETCH_KEY_PAIR);
$total_expenses = array_sum($expense_by_cat);
$net_profit = $total_revenue - $total_expenses;

// --- CSV export ---
if (($_GET['export'] ?? '') === 'csv') {
    $name_map = function ($ids_json, $map, $col) {
        $out = [];
        foreach (json_decode($ids_json ?? '[]', true) ?: [] as $id) {
            $out[] = $map[$id][$col] ?? "#$id";
        }
        return implode(' | ', $out) ?: '—';
    };

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="report_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Report period', $from, 'to', $to]);
    fputcsv($out, []);
    fputcsv($out, ['Date', 'Booking ID', 'Customer', 'Services', 'Packages', 'Mechanics', 'Amount']);
    foreach ($bookings as $b) {
        fputcsv($out, [
            $b['schedule_date'], $b['id'], $b['customer'],
            $name_map($b['service_ids'], $svc_map, 'service_name'),
            $name_map($b['package_ids'], $pkg_map, 'package_name'),
            $b['team'] ?: $b['primary_mechanic'] ?: 'Unassigned',
            number_format($b['total_price'], 2, '.', ''),
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Parts used']);
    fputcsv($out, ['Part', 'Qty', 'Revenue']);
    foreach ($parts_counts as $n => $c) fputcsv($out, [$n, $c['qty'], number_format($c['revenue'], 2, '.', '')]);
    fputcsv($out, []);
    fputcsv($out, ['Summary']);
    fputcsv($out, ['Service revenue', number_format($service_revenue, 2, '.', '')]);
    fputcsv($out, ['Parts revenue', number_format($parts_revenue, 2, '.', '')]);
    fputcsv($out, ['Total revenue', number_format($total_revenue, 2, '.', '')]);
    fputcsv($out, ['Expenses', number_format($total_expenses, 2, '.', '')]);
    fputcsv($out, ['Net profit', number_format($net_profit, 2, '.', '')]);
    exit;
}

// Sort breakdowns for display
uasort($service_counts, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
uasort($mechanic_perf, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
uasort($parts_counts, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
arsort($customer_spend);
$top_services  = array_slice($service_counts, 0, 10, true);
$top_customers = array_slice($customer_spend, 0, 10, true);
$top_parts     = array_slice($parts_counts, 0, 10, true);

// Max values drive the share-of-total bars in each breakdown card
$rep_max = fn(array $rows, string $key) => ($m = max(array_map(fn($r) => (float)$r[$key], $rows ?: [[$key => 0]]))) > 0 ? $m : 1;
$svc_max = $rep_max($top_services, 'revenue');
$mch_max = $rep_max($mechanic_perf, 'revenue');
$prt_max = $rep_max($top_parts, 'revenue');
$cst_max = $top_customers ? max($top_customers) : 1;
$exp_max = $expense_by_cat ? max($expense_by_cat) : 1;

$pageTitle = 'Reports & Analytics';
require 'admin_sidebar_template.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<style>
    .rep-page { min-width:0; overflow-x:clip; }
    .rep-page .card { border-radius:12px; min-width:0; }
    .rep-page .card-body { padding:.65rem .8rem; }
    .rep-page .card-header { padding:.45rem .85rem; }
    .rep-lbl { display:block; font-size:.68rem; font-weight:600; letter-spacing:.4px; text-transform:uppercase; color:#64748b; margin-bottom:2px; }
    .rep-page .form-control-sm { font-size:.8rem; }
    .rep-preset { border-radius:999px; font-size:.72rem; padding:.15rem .6rem; transition:all .15s ease; }
    .rep-preset.btn-outline-secondary { color:#64748b; border-color:#e2e8f0; background:rgba(255,255,255,.55); }
    .rep-preset.btn-outline-secondary:hover { color:#0f172a; border-color:#cbd5e1; background:#ffffff; }
    .rep-preset.btn-primary { box-shadow:0 2px 8px rgba(13,110,253,.28); }
    /* Toolbar card: brand accent strip */
    .rep-toolbar { border-left:3px solid #FACC15; }
    /* Card headers: soft accent-tinted (accent set via --rep-acc on the card) */
    .rep-page .rep-head {
        display:flex; align-items:center; gap:.45rem;
        padding:.55rem .9rem;
        font-size:.82rem; font-weight:700; letter-spacing:.02em;
        color:#1e293b;
        background:#f8fafc;
        background:color-mix(in srgb, var(--rep-acc,#3b82f6) 9%, #ffffff);
        border-bottom:1px solid #eef2f7;
        border-bottom-color:color-mix(in srgb, var(--rep-acc,#3b82f6) 20%, #eef2f7);
    }
    .rep-page .rep-head h6 { font-size:.82rem; font-weight:700; display:flex; align-items:center; gap:.4rem; }
    .rep-page .rep-head h6 i { color:var(--rep-acc,#3b82f6); font-size:.92rem; }
    .rep-range { font-size:.68rem; font-weight:600; color:#64748b; letter-spacing:.02em; }
    /* Uniform card height: header stays pinned, body scrolls internally */
    .rep-card { display:flex; flex-direction:column; height:300px; }
    .rep-card > .card-body { flex:1 1 0; min-height:0; display:flex; flex-direction:column; overflow-y:auto; }
    .rep-card .rep-chart { flex:1 1 0; min-height:0; height:auto; }
    .rep-card .rep-empty { flex:1 1 0; }
    /* lg+: page fills the viewport exactly — no page scroll.
       Toolbar + KPI strip keep natural height; the two card rows share
       the leftover space and each card body scrolls internally. */
    @media (min-width: 992px) {
        .rep-page { display:flex; flex-direction:column; height:100%; min-height:0; }
        .rep-page > .row { --bs-gutter-y:0; flex:1 1 0; min-height:0; }
        .rep-page .rep-card { height:100%; }
    }
    /* KPI strip: one card, segmented cells with hairline dividers + accent ticks */
    .rep-kpis { --rep-div:rgba(15,23,42,.07); display:grid; grid-template-columns:repeat(6,1fr); }
    .rep-kpi-cell { position:relative; display:flex; align-items:center; gap:.55rem; padding:.55rem .7rem .55rem .95rem; min-width:0; }
    .rep-kpi-cell::before { content:''; position:absolute; left:0; top:20%; bottom:20%; width:3px; border-radius:0 3px 3px 0; background:var(--kpi-acc,transparent); }
    .rep-kpi-ic { width:32px; height:32px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; font-size:.95rem; line-height:1; flex-shrink:0; background:#f1f5f9; background:color-mix(in srgb, currentColor 13%, transparent); }
    .rep-kpi-lbl { font-size:.64rem; }
    .rep-val { font-size:.95rem; font-weight:700; line-height:1.15; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    @media (min-width:1200px) { .rep-kpi-cell + .rep-kpi-cell { border-left:1px solid var(--rep-div); } }
    @media (min-width:576px) and (max-width:1199.98px) {
        .rep-kpis { grid-template-columns:repeat(3,1fr); }
        .rep-kpi-cell:nth-child(n+4) { border-top:1px solid var(--rep-div); }
        .rep-kpi-cell:not(:nth-child(3n+1)) { border-left:1px solid var(--rep-div); }
    }
    @media (max-width:575.98px) {
        .rep-kpis { grid-template-columns:repeat(2,1fr); }
        .rep-kpi-cell:nth-child(n+3) { border-top:1px solid var(--rep-div); }
        .rep-kpi-cell:nth-child(even) { border-left:1px solid var(--rep-div); }
    }
    /* Rank badges for leaderboard lists */
    .rep-rank { display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; border-radius:6px; margin-right:.4rem; font-size:.62rem; font-weight:700; color:#64748b; background:rgba(15,23,42,.06); flex-shrink:0; }
    .rep-rank-top { color:#fff; background:var(--rep-acc,#3b82f6); }
    /* Chart wrappers: sized + relative so Chart.js can't overflow the page */
    .rep-chart { position:relative; width:100%; }
    .rep-chart-lg { height:280px; }
    .rep-chart-sm { height:220px; }
    .rep-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:.5rem; padding:1.2rem .75rem; color:#94a3b8; text-align:center; }
    .rep-empty i { font-size:1.3rem; width:50px; height:50px; border-radius:14px; display:flex; align-items:center; justify-content:center; background:#f1f5f9; background:color-mix(in srgb, var(--rep-acc,#94a3b8) 10%, #f1f5f9); color:#94a3b8; color:color-mix(in srgb, var(--rep-acc,#64748b) 70%, #64748b); }
    .rep-empty .rep-empty-msg { font-size:.85rem; font-weight:600; color:#64748b; }
    .rep-empty small { font-size:.72rem; }
    /* Breakdown list tables */
    .rep-list-scroll { max-height:265px; overflow-y:auto; scrollbar-width:thin; }
    .rep-card .rep-list-scroll { flex:1 1 0; min-height:0; max-height:none; }
    .rep-list-scroll::-webkit-scrollbar { width:6px; }
    .rep-list-scroll::-webkit-scrollbar-thumb { background:rgba(100,116,139,.3); border-radius:3px; }
    .rep-list-scroll table { margin-bottom:0; }
    .rep-list-scroll thead th { position:sticky; top:0; z-index:2; background:#f8fafc; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; border-bottom:1px solid #e2e8f0 !important; }
    .rep-list-scroll td { font-size:.8rem; vertical-align:middle; }
    .rep-list-scroll tbody tr { transition:background .12s ease; }
    .rep-list-scroll tbody tr:hover { background:rgba(15,23,42,.025); background:color-mix(in srgb, var(--rep-acc,#3b82f6) 5%, transparent); }
    .rep-list-scroll tbody tr.fw-bold { background:rgba(239,68,68,.05); background:color-mix(in srgb, var(--rep-acc,#3b82f6) 6%, transparent); }
    /* Pinned totals row stays visible while the list scrolls */
    .rep-list-scroll tbody tr.fw-bold td { position:sticky; bottom:0; background:#fff; background:color-mix(in srgb, var(--rep-acc,#3b82f6) 8%, #ffffff); }
    .rep-bar { height:5px; border-radius:99px; background:rgba(15,23,42,.08); overflow:hidden; margin-top:3px; min-width:60px; }
    .rep-bar i { display:block; height:100%; border-radius:99px; background:var(--rep-acc,#3b82f6); }
    .rep-name { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:150px; }
    /* Dark theme overrides */
    html[data-theme="dark"] .rep-page .rep-head { background:rgba(255,255,255,.03) !important; background:color-mix(in srgb, var(--rep-acc,#3b82f6) 16%, transparent) !important; color:#e2e8f0; border-bottom-color:rgba(255,255,255,.09) !important; }
    html[data-theme="dark"] .rep-page .rep-head h6 i { color:color-mix(in srgb, var(--rep-acc,#3b82f6) 65%, #ffffff); }
    html[data-theme="dark"] .rep-range { color:#94a3b8; }
    html[data-theme="dark"] .rep-toolbar { border-left-color:#FACC15; }
    html[data-theme="dark"] .rep-kpis { --rep-div:rgba(255,255,255,.09); }
    html[data-theme="dark"] .rep-kpi-ic { background:rgba(255,255,255,.06); background:color-mix(in srgb, currentColor 20%, transparent); }
    html[data-theme="dark"] .rep-rank { background:rgba(255,255,255,.1); color:#cbd5e1; }
    html[data-theme="dark"] .rep-rank-top { color:#fff; }
    html[data-theme="dark"] .rep-list-scroll tbody tr.fw-bold td { background:color-mix(in srgb, var(--rep-acc,#3b82f6) 15%, #1a2b4f); }
    html[data-theme="dark"] .rep-list-scroll thead th { background:#1a2b4f; color:#94a3b8; border-bottom-color:rgba(255,255,255,.09) !important; }
    html[data-theme="dark"] .rep-list-scroll tbody tr:hover { background:rgba(255,255,255,.04); }
    html[data-theme="dark"] .rep-list-scroll tbody tr.fw-bold { background:rgba(255,255,255,.05); }
    html[data-theme="dark"] .rep-bar { background:rgba(255,255,255,.1); }
    html[data-theme="dark"] .rep-lbl { color:#94a3b8; }
    html[data-theme="dark"] .rep-empty i { background:rgba(255,255,255,.06); background:color-mix(in srgb, var(--rep-acc,#94a3b8) 16%, transparent); }
    html[data-theme="dark"] .rep-empty .rep-empty-msg { color:#cbd5e1; }
    html[data-theme="dark"] .rep-preset.btn-outline-secondary { background:rgba(255,255,255,.04); border-color:rgba(255,255,255,.15); color:#cbd5e1; }
    html[data-theme="dark"] .rep-preset.btn-outline-secondary:hover { background:rgba(255,255,255,.08); border-color:rgba(255,255,255,.25); color:#fff; }
</style>

<div class="container-fluid py-3 rep-page">
    <div class="card rep-toolbar mb-2">
        <div class="card-body d-flex flex-wrap align-items-end gap-2">
            <form method="GET" class="d-flex flex-wrap align-items-end gap-2">
                <div>
                    <label class="rep-lbl">From</label>
                    <input type="date" name="from" class="form-control form-control-sm" value="<?= $from ?>">
                </div>
                <div>
                    <label class="rep-lbl">To</label>
                    <input type="date" name="to" class="form-control form-control-sm" value="<?= $to ?>">
                </div>
                <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
            </form>
            <div class="d-flex flex-wrap gap-1 align-items-center">
                <?php foreach ($presets as $label => $r):
                    $active = ($from === $r[0] && $to === $r[1]); ?>
                    <a href="?from=<?= $r[0] ?>&to=<?= $r[1] ?>"
                       class="btn btn-sm rep-preset <?= $active ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
            <a href="?from=<?= $from ?>&to=<?= $to ?>&export=csv" class="btn btn-sm btn-success ms-auto" title="Download CSV for <?= $from ?> to <?= $to ?>">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
            </a>
        </div>
    </div>

    <?php
    $kpis = [
        ['Service Revenue',  $service_revenue, 'success',  'bi-wrench-adjustable', true],
        ['Parts Revenue',    $parts_revenue,   'info',     'bi-gear',              true],
        ['Total Revenue',    $total_revenue,   'primary',  'bi-cash-stack',        true],
        ['Expenses',         $total_expenses,  'danger',   'bi-receipt',           true],
        ['Net Profit',       $net_profit,      $net_profit >= 0 ? 'success' : 'danger', 'bi-graph-up-arrow', true],
        ['Completed Jobs',   count($bookings), 'secondary','bi-check2-circle',     false],
    ];
    ?>
    <div class="card rep-kpis mb-2">
        <?php foreach ($kpis as [$label, $val, $color, $icon, $money]): ?>
        <div class="rep-kpi-cell" style="--kpi-acc:var(--bs-<?= $color ?>)">
            <i class="bi <?= $icon ?> rep-kpi-ic text-<?= $color ?>"></i>
            <div style="min-width:0">
                <small class="text-muted d-block text-truncate rep-kpi-lbl"><?= $label ?></small>
                <div class="rep-val text-<?= $color ?>"><?= $money ? '₱' . number_format($val, 2) : number_format($val) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-2 mb-2">
        <div class="col-lg-5">
            <div class="card rep-card" style="--rep-acc:#eab308">
                <div class="card-header rep-head justify-content-between">
                    <h6 class="mb-0"><i class="bi bi-graph-up me-1"></i>Revenue (completed bookings)</h6>
                    <small class="rep-range"><?= $from ?> → <?= $to ?></small>
                </div>
                <div class="card-body">
                    <?php if (empty($daily_revenue)): ?>
                    <div class="rep-empty">
                        <i class="bi bi-graph-up"></i>
                        <div class="rep-empty-msg">No completed bookings in this period</div>
                        <small>Try a wider date range above.</small>
                    </div>
                    <?php else: ?>
                    <div class="rep-chart rep-chart-lg"><canvas id="revChart"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card rep-card" style="--rep-acc:#3b82f6">
                <div class="card-header rep-head"><h6 class="mb-0"><i class="bi bi-credit-card me-1"></i>Payments by Method</h6></div>
                <div class="card-body">
                    <?php if (empty($pay_methods)): ?>
                    <div class="rep-empty">
                        <i class="bi bi-credit-card"></i>
                        <div class="rep-empty-msg">No verified payments in this period</div>
                        <small>Only payments marked <em>verified</em> count here.</small>
                    </div>
                    <?php else: ?>
                    <div class="rep-chart rep-chart-sm"><canvas id="payChart"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="card rep-card" style="--rep-acc:#ef4444">
                <div class="card-header rep-head"><h6 class="mb-0"><i class="bi bi-receipt me-1"></i>Expenses by Category</h6></div>
                <div class="card-body p-0">
                    <?php if (empty($expense_by_cat)): ?>
                    <div class="rep-empty"><i class="bi bi-receipt"></i><div class="rep-empty-msg">No expenses recorded</div><small><a href="admin_invoices.php">Add expenses</a> in the Invoices page.</small></div>
                    <?php else: ?>
                    <div class="rep-list-scroll">
                    <table class="table table-sm">
                        <thead><tr><th>Category</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($expense_by_cat as $cat => $amt): ?>
                            <tr>
                                <td class="rep-name" title="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?>
                                    <div class="rep-bar"><i style="width:<?= round($amt / $exp_max * 100) ?>%"></i></div></td>
                                <td class="text-end">₱<?= number_format($amt, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="fw-bold"><td>Total</td><td class="text-end">₱<?= number_format($total_expenses, 2) ?></td></tr>
                        </tbody>
                    </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-2">
        <div class="col-md-6 col-lg-3">
            <div class="card rep-card" style="--rep-acc:#3b82f6">
                <div class="card-header rep-head"><h6 class="mb-0"><i class="bi bi-tools me-1"></i>Top Services &amp; Packages</h6></div>
                <div class="card-body p-0">
                    <?php if (empty($top_services)): ?>
                    <div class="rep-empty"><i class="bi bi-tools"></i><div class="rep-empty-msg">No services booked</div><small>Completed bookings will populate this list.</small></div>
                    <?php else: ?>
                    <div class="rep-list-scroll">
                    <table class="table table-sm">
                        <thead><tr><th>Service</th><th class="text-end">Jobs</th><th class="text-end">Revenue</th></tr></thead>
                        <tbody>
                        <?php $r = 0; foreach ($top_services as $name => $c): $r++; ?>
                            <tr>
                                <td class="rep-name" title="<?= htmlspecialchars($name) ?>"><span class="rep-rank <?= $r <= 3 ? 'rep-rank-top' : '' ?>"><?= $r ?></span><?= htmlspecialchars($name) ?>
                                    <div class="rep-bar"><i style="width:<?= round($c['revenue'] / $svc_max * 100) ?>%"></i></div></td>
                                <td class="text-end"><?= $c['count'] ?></td>
                                <td class="text-end">₱<?= number_format($c['revenue'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card rep-card" style="--rep-acc:#14b8a6">
                <div class="card-header rep-head"><h6 class="mb-0"><i class="bi bi-gear me-1"></i>Top Parts Used</h6></div>
                <div class="card-body p-0">
                    <?php if (empty($top_parts)): ?>
                    <div class="rep-empty"><i class="bi bi-gear"></i><div class="rep-empty-msg">No parts used</div><small>Approved parts on completed bookings appear here.</small></div>
                    <?php else: ?>
                    <div class="rep-list-scroll">
                    <table class="table table-sm">
                        <thead><tr><th>Part</th><th class="text-end">Qty</th><th class="text-end">Revenue</th></tr></thead>
                        <tbody>
                        <?php $r = 0; foreach ($top_parts as $name => $c): $r++; ?>
                            <tr>
                                <td class="rep-name" title="<?= htmlspecialchars($name) ?>"><span class="rep-rank <?= $r <= 3 ? 'rep-rank-top' : '' ?>"><?= $r ?></span><?= htmlspecialchars($name) ?>
                                    <div class="rep-bar"><i style="width:<?= round($c['revenue'] / $prt_max * 100) ?>%"></i></div></td>
                                <td class="text-end"><?= $c['qty'] ?></td>
                                <td class="text-end">₱<?= number_format($c['revenue'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card rep-card" style="--rep-acc:#8b5cf6">
                <div class="card-header rep-head"><h6 class="mb-0"><i class="bi bi-person-gear me-1"></i>Mechanic Performance</h6></div>
                <div class="card-body p-0">
                    <?php if (empty($mechanic_perf)): ?>
                    <div class="rep-empty"><i class="bi bi-person-gear"></i><div class="rep-empty-msg">No mechanic data</div><small>Assign mechanics to bookings to track performance.</small></div>
                    <?php else: ?>
                    <div class="rep-list-scroll">
                    <table class="table table-sm">
                        <thead><tr><th>Mechanic</th><th class="text-end">Jobs</th><th class="text-end">Revenue</th></tr></thead>
                        <tbody>
                        <?php $r = 0; foreach ($mechanic_perf as $name => $c): $r++; ?>
                            <tr>
                                <td class="rep-name" title="<?= htmlspecialchars($name) ?>"><span class="rep-rank <?= $r <= 3 ? 'rep-rank-top' : '' ?>"><?= $r ?></span><?= htmlspecialchars($name) ?>
                                    <div class="rep-bar"><i style="width:<?= round($c['revenue'] / $mch_max * 100) ?>%"></i></div></td>
                                <td class="text-end"><?= $c['jobs'] ?></td>
                                <td class="text-end">₱<?= number_format($c['revenue'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card rep-card" style="--rep-acc:#f59e0b">
                <div class="card-header rep-head"><h6 class="mb-0"><i class="bi bi-people me-1"></i>Top Customers</h6></div>
                <div class="card-body p-0">
                    <?php if (empty($top_customers)): ?>
                    <div class="rep-empty"><i class="bi bi-people"></i><div class="rep-empty-msg">No customers yet</div><small>Customer spend from completed bookings appears here.</small></div>
                    <?php else: ?>
                    <div class="rep-list-scroll">
                    <table class="table table-sm">
                        <thead><tr><th>Customer</th><th class="text-end">Total Spend</th></tr></thead>
                        <tbody>
                        <?php $r = 0; foreach ($top_customers as $name => $amt): $r++; ?>
                            <tr>
                                <td class="rep-name" title="<?= htmlspecialchars($name) ?>"><span class="rep-rank <?= $r <= 3 ? 'rep-rank-top' : '' ?>"><?= $r ?></span><?= htmlspecialchars($name) ?>
                                    <div class="rep-bar"><i style="width:<?= round($amt / $cst_max * 100) ?>%"></i></div></td>
                                <td class="text-end">₱<?= number_format($amt, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
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
    if (typeof Chart === 'undefined') return;

    const dark = document.documentElement.dataset.theme === 'dark';
    const tickColor = dark ? '#cbd5e1' : '#475569';
    const gridColor = dark ? 'rgba(255,255,255,.08)' : 'rgba(15,23,42,.07)';
    let revChart = null, payChart = null;

    const revEl = document.getElementById('revChart');
    if (revEl) {
        const revData = <?= json_encode([
            'labels' => array_keys($daily_revenue),
            'values' => array_values($daily_revenue),
        ]) ?>;
        revChart = new Chart(revEl, {
            type: 'line',
            data: {
                labels: revData.labels,
                datasets: [{
                    label: 'Revenue ₱',
                    data: revData.values,
                    borderColor: '#FACC15',
                    backgroundColor: dark ? 'rgba(250,204,21,0.12)' : 'rgba(250,204,21,0.18)',
                    fill: true, tension: 0.3, pointRadius: 3
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: tickColor, maxTicksLimit: 8 }, grid: { color: gridColor } },
                    y: { beginAtZero: true, ticks: { color: tickColor }, grid: { color: gridColor } }
                }
            }
        });
    }

    const payEl = document.getElementById('payChart');
    if (payEl) {
        const payData = <?= json_encode([
            'labels' => array_map('ucfirst', array_keys($pay_methods)),
            'values' => array_map('floatval', array_values($pay_methods)),
        ]) ?>;
        payChart = new Chart(payEl, {
            type: 'doughnut',
            data: {
                labels: payData.labels,
                datasets: [{ data: payData.values, backgroundColor: ['#3b82f6', '#10b981', '#FACC15', '#ef4444', '#8b5cf6'], borderWidth: 0 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '58%',
                plugins: { legend: { position: 'bottom', labels: { color: tickColor, boxWidth: 12, padding: 10 } } }
            }
        });
    }

    // Re-tint charts when the header dark-mode toggle flips theme
    document.addEventListener('adminThemeChanged', function (e) {
        const d = !!(e.detail && e.detail.dark);
        const tc = d ? '#cbd5e1' : '#475569';
        const gc = d ? 'rgba(255,255,255,.08)' : 'rgba(15,23,42,.07)';
        if (revChart) {
            revChart.options.scales.x.ticks.color = tc;
            revChart.options.scales.x.grid.color = gc;
            revChart.options.scales.y.ticks.color = tc;
            revChart.options.scales.y.grid.color = gc;
            revChart.data.datasets[0].backgroundColor = d ? 'rgba(250,204,21,0.12)' : 'rgba(250,204,21,0.18)';
            revChart.update();
        }
        if (payChart) {
            payChart.options.plugins.legend.labels.color = tc;
            payChart.update();
        }
    });
})();
</script>

<?php require 'admin_footer.php'; ?>
