<?php
session_start();
require 'db.php';
require 'motorcycle_health_helper.php';
require 'management_helper.php';

// Security: mechanic only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mechanic') {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Find the mechanic record linked to this user
$mechanic_stmt = $pdo->prepare("SELECT m.*, u.email, u.phone, u.address FROM mechanics m LEFT JOIN users u ON m.user_id = u.id WHERE m.user_id = ?");
$mechanic_stmt->execute([$user_id]);
$mechanic = $mechanic_stmt->fetch(PDO::FETCH_ASSOC);

if (!$mechanic) {
    die("Your mechanic profile is missing. Please contact the administrator.");
}

$mechanic_id = $mechanic['id'];

// Sync mechanic status based on open work
$sync_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM emergency_service_requests
    WHERE assigned_mechanic_id = ? AND request_status IN ('assigned','accepted','in_progress')
");
$sync_stmt->execute([$mechanic_id]);
$open_emergency_count = (int) $sync_stmt->fetchColumn();

if ($open_emergency_count > 0) {
    $pdo->prepare("UPDATE mechanics SET status = 'Busy' WHERE id = ? AND status != 'Busy'")->execute([$mechanic_id]);
} else {
    $booking_check = $pdo->prepare("
        SELECT COUNT(*) FROM bookings
        WHERE mechanic_id = ? AND status IN ('assigned','accepted','in_progress')
    ");
    $booking_check->execute([$mechanic_id]);
    $open_bookings = (int) $booking_check->fetchColumn();

    $current = $pdo->prepare("SELECT current_booking_id FROM mechanics WHERE id = ?");
    $current->execute([$mechanic_id]);
    $current_booking = $current->fetchColumn();

    if ($open_bookings === 0 && empty($current_booking)) {
        $pdo->prepare("UPDATE mechanics SET status = 'Available' WHERE id = ? AND status != 'Available'")->execute([$mechanic_id]);
    }
}

// Handle job status actions
$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $booking_id = (int)($_POST['booking_id'] ?? 0);

    try {
        if (isset($_POST['start_job']) && $booking_id) {
            $pdo->beginTransaction();

            $check = $pdo->prepare("SELECT id FROM bookings WHERE id = ? AND mechanic_id = ? AND status IN ('assigned','accepted')");
            $check->execute([$booking_id, $mechanic_id]);
            if ($check->fetch()) {
                $pdo->prepare("UPDATE bookings SET status = 'in_progress' WHERE id = ?")->execute([$booking_id]);
                $pdo->prepare("UPDATE mechanics SET status = 'Busy', current_booking_id = ? WHERE id = ?")->execute([$booking_id, $mechanic_id]);

                $pdo->commit();
                log_audit($pdo, 'job_started', 'booking', $booking_id);
                $msg = "✅ Job #{$booking_id} started.";
                $msg_type = "success";
            } else {
                $pdo->rollBack();
                $msg = "❌ Job cannot be started.";
                $msg_type = "error";
            }
        } elseif (isset($_POST['complete_job']) && $booking_id) {
            $pdo->beginTransaction();

            $check = $pdo->prepare("SELECT id FROM bookings WHERE id = ? AND mechanic_id = ? AND status IN ('in_progress','assigned','accepted')");
            $check->execute([$booking_id, $mechanic_id]);
            if ($check->fetch()) {
                $pdo->prepare("UPDATE bookings SET status = 'completed' WHERE id = ?")->execute([$booking_id]);
                recordCompletedBookingHistory($booking_id);
                $pdo->prepare("UPDATE mechanics SET current_booking_id = NULL WHERE id = ?")->execute([$mechanic_id]);

                $busy_check = $pdo->prepare("
                    SELECT
                        (SELECT COUNT(*) FROM emergency_service_requests WHERE assigned_mechanic_id = ? AND request_status IN ('assigned','accepted','in_progress')) +
                        (SELECT COUNT(*) FROM bookings WHERE mechanic_id = ? AND status IN ('assigned','accepted','in_progress')) +
                        (SELECT COUNT(*) FROM booking_mechanics bm JOIN bookings b ON b.id = bm.booking_id WHERE bm.mechanic_id = ? AND b.status IN ('assigned','accepted','in_progress')) +
                        (SELECT COUNT(*) FROM mechanics WHERE id = ? AND current_booking_id IS NOT NULL)
                ");
                $busy_check->execute([$mechanic_id, $mechanic_id, $mechanic_id, $mechanic_id]);
                if ((int)$busy_check->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE mechanics SET status = 'Available' WHERE id = ?")->execute([$mechanic_id]);
                }

                $pdo->commit();
                generate_invoice_for_booking($pdo, $booking_id);
                log_audit($pdo, 'job_completed', 'booking', $booking_id);
                $msg = "✅ Job #{$booking_id} completed. Invoice generated.";
                $msg_type = "success";
            } else {
                $pdo->rollBack();
                $msg = "❌ Invalid job or job is not in progress.";
                $msg_type = "error";
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Mechanic bookings error: " . $e->getMessage());
        $msg = "❌ Database error: " . $e->getMessage();
        $msg_type = "error";
    }
}

// Fetch all jobs with customer + motorcycle details
$jobs_stmt = $pdo->prepare("
    SELECT 
        b.id,
        b.schedule_date,
        b.schedule_start_time,
        b.schedule_end_time,
        b.status,
        b.service_ids,
        b.package_ids,
        b.total_price,
        b.created_at AS booked_at,
        pm.name AS primary_mechanic,
        (SELECT GROUP_CONCAT(m2.name SEPARATOR ', ') FROM booking_mechanics bm JOIN mechanics m2 ON m2.id = bm.mechanic_id WHERE bm.booking_id = b.id) AS team_names,
        u.id as customer_id,
        u.username as customer_name,
        u.phone as customer_phone,
        u.email as customer_email,
        u.address as customer_address,
        m.id as motorcycle_id,
        m.brand,
        m.model,
        m.year_model,
        m.plate_number,
        m.color,
        m.current_mileage,
        m.image
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    LEFT JOIN motorcycles m ON b.vehicle_id = m.id
    LEFT JOIN mechanics pm ON pm.id = b.mechanic_id
    WHERE b.mechanic_id = ?
       OR EXISTS (SELECT 1 FROM booking_mechanics bm WHERE bm.booking_id = b.id AND bm.mechanic_id = ?)
    ORDER BY 
        CASE b.status
            WHEN 'assigned' THEN 1
            WHEN 'accepted' THEN 2
            WHEN 'in_progress' THEN 3
            WHEN 'completed' THEN 4
            ELSE 5
        END,
        b.schedule_date DESC,
        b.schedule_start_time ASC
");
$jobs_stmt->execute([$mechanic_id, $mechanic_id]);
$all_jobs = $jobs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch service and package names
try {
    $services = $pdo->query("SELECT id, service_name FROM services")->fetchAll(PDO::FETCH_KEY_PAIR);
    $packages = $pdo->query("SELECT id, package_name FROM service_packages")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $services = [];
    $packages = [];
}

// Parts logged per booking (for badges + modal)
ensure_management_tables($pdo);
$parts_map = [];
try {
    $pstmt = $pdo->prepare("
        SELECT bp.booking_id, p.name AS part_name, bp.quantity, bp.unit_price, bp.status AS part_status
        FROM booking_parts bp
        JOIN parts p ON p.id = bp.part_id
        JOIN bookings b ON b.id = bp.booking_id
        LEFT JOIN booking_mechanics bm ON bm.booking_id = b.id AND bm.mechanic_id = ?
        WHERE b.mechanic_id = ? OR bm.mechanic_id IS NOT NULL
    ");
    $pstmt->execute([$mechanic_id, $mechanic_id]);
    foreach ($pstmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
        $parts_map[$pr['booking_id']][] = $pr;
    }
} catch (PDOException $e) {
    $parts_map = [];
}

// Latest payment per booking (method + ref for the card)
$pay_map = [];
try {
    foreach ($pdo->query("SELECT booking_id, payment_method, transaction_ref, status FROM payments ORDER BY id") as $p) {
        $pay_map[$p['booking_id']] = $p;
    }
} catch (PDOException $e) {
    $pay_map = [];
}

// Status counts + filter
$today = date('Y-m-d');
$counts = ['open' => 0, 'in_progress' => 0, 'completed' => 0, 'today' => 0];
foreach ($all_jobs as $j) {
    if (in_array($j['status'], ['assigned', 'accepted'])) $counts['open']++;
    elseif ($j['status'] === 'in_progress') $counts['in_progress']++;
    elseif ($j['status'] === 'completed') $counts['completed']++;
    if ($j['schedule_date'] === $today) $counts['today']++;
}
$filter = $_GET['filter'] ?? 'all';
$jobs = match($filter) {
    'open' => array_values(array_filter($all_jobs, fn($j) => in_array($j['status'], ['assigned', 'accepted']))),
    'in_progress' => array_values(array_filter($all_jobs, fn($j) => $j['status'] === 'in_progress')),
    'completed' => array_values(array_filter($all_jobs, fn($j) => $j['status'] === 'completed')),
    default => $all_jobs,
};

function getServiceItems($service_ids_json, $package_ids_json, $services, $packages) {
    $decode = function ($v) {
        if (empty($v)) return [];
        $d = json_decode($v, true);
        if (is_array($d)) return $d;
        return array_filter(array_map('trim', explode(',', (string)$v)));
    };
    $labels = [];
    foreach ($decode($service_ids_json) as $sid) {
        if (isset($services[$sid])) $labels[] = $services[$sid];
    }
    foreach ($decode($package_ids_json) as $pid) {
        if (isset($packages[$pid])) $labels[] = $packages[$pid];
    }
    return $labels;
}

function getStatusClass($status) {
    return match ($status) {
        'completed' => 'bg-success',
        'assigned' => 'bg-primary',
        'accepted' => 'bg-info',
        'in_progress' => 'bg-warning text-dark',
        default => 'bg-secondary'
    };
}

function renderJobTable($jobs, $services, $packages, $parts_map, $pay_map) {
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    if (empty($jobs)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-inbox fs-1 d-block mb-3"></i>
            <p class="mb-0">No bookings found.</p>
        </div>
    <?php else:
        foreach ($jobs as $job):
            $vehicle_text = ($job['brand'] ? $job['brand'] . ' ' . $job['model'] : 'N/A') . ($job['plate_number'] ? ' (Plate: ' . $job['plate_number'] . ')' : '');
            $status_class = getStatusClass($job['status']);
            $service_items = getServiceItems($job['service_ids'], $job['package_ids'] ?? '[]', $services, $packages);
            $job_parts = $parts_map[$job['id']] ?? [];
            $parts_qty = array_sum(array_column($job_parts, 'quantity'));
            $parts_pending = count(array_filter($job_parts, fn($p) => ($p['part_status'] ?? 'approved') === 'pending'));
            $payment = $pay_map[$job['id']] ?? null;
            $mechanics = $job['team_names'] ?: ($job['primary_mechanic'] ?? 'Unassigned');
            $when = '';
            if ($job['schedule_date'] === $today) $when = 'Today';
            elseif ($job['schedule_date'] === $tomorrow) $when = 'Tomorrow';
            $img = resolve_moto_image($job);
        $accent = match ($job['status']) {
            'completed' => '#10b981', 'in_progress' => '#f59e0b',
            'accepted' => '#3b82f6', 'assigned' => '#8b5cf6',
            'rejected', 'deposit_rejected' => '#ef4444', default => '#94a3b8',
        };
        ?>
        <div class="card border-0 shadow-sm job-card mb-3" style="border-left:4px solid <?= $accent ?> !important;">
            <div class="card-body">
                <div class="d-flex justify-content-end align-items-center mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($when): ?><span class="badge bg-warning text-dark border"><?= $when ?></span><?php endif; ?>
                        <span class="badge rounded-pill <?= $status_class ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $job['status']))) ?></span>
                    </div>
                </div>

                <div class="job-layout">
                    <div class="job-img">
                        <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars(($job['brand'] ?? '') . ' ' . ($job['model'] ?? '')) ?>">
                    </div>

                    <div class="job-info">
                        <p class="job-label"><i class="bi bi-calendar-event me-1"></i>Appointment</p>
                        <p class="job-text"><strong>Date:</strong> <?= date('Y-m-d (D)', strtotime($job['schedule_date'])) ?></p>
                        <p class="job-text"><strong>Time:</strong> <?= date('g:i A', strtotime($job['schedule_start_time'])) ?> – <?= date('g:i A', strtotime($job['schedule_end_time'])) ?></p>
                        <p class="job-text text-muted"><i class="bi bi-clock-history me-1"></i>Booked <?= date('M d, Y · g:i A', strtotime($job['booked_at'])) ?></p>

                        <p class="job-label mt-2"><i class="bi bi-person me-1"></i>Customer</p>
                        <p class="job-text"><strong><?= htmlspecialchars($job['customer_name']) ?></strong><br><span class="text-muted"><?= htmlspecialchars($job['customer_phone'] ?? '') ?></span></p>
                    </div>

                    <div class="job-info">
                        <p class="job-label"><i class="bi bi-motorcycle me-1"></i>Vehicle &amp; Services</p>
                        <p class="job-text fw-semibold"><?= htmlspecialchars($vehicle_text) ?></p>
                        <?php if (empty($service_items)): ?>
                            <p class="job-text text-muted">N/A</p>
                        <?php else: ?>
                            <ul class="mb-0 ps-3 job-text">
                                <?php foreach ($service_items as $s): ?><li><?= htmlspecialchars($s) ?></li><?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <div class="job-info job-right">
                        <p class="job-label"><i class="bi bi-person-badge me-1"></i>Personnel</p>
                        <p class="job-text"><strong>Assigned:</strong> <?= htmlspecialchars($mechanics) ?></p>

                        <p class="job-label mt-2"><i class="bi bi-cash me-1"></i>Estimated Price</p>
                        <p class="fs-5 fw-bold text-success mb-2">₱<?= number_format($job['total_price'], 2) ?></p>

                        <p class="job-label"><i class="bi bi-credit-card me-1"></i>Payment</p>
                        <?php if ($payment): ?>
                        <p class="job-text"><strong>Method:</strong> <?= htmlspecialchars(ucfirst($payment['payment_method'])) ?>
                            <span class="badge <?= $payment['status'] === 'verified' ? 'bg-success' : 'bg-warning text-dark' ?> ms-1"><?= $payment['status'] ?></span></p>
                        <?php if ($payment['transaction_ref']): ?>
                        <p class="job-text"><strong>Ref. No.:</strong> <?= htmlspecialchars($payment['transaction_ref']) ?></p>
                        <?php endif; ?>
                        <?php else: ?>
                        <p class="job-text text-muted">No payment yet</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="job-actions">
                    <?php if (in_array($job['status'], ['assigned', 'accepted', 'in_progress'])): ?>
                        <a href="booking_parts.php?booking_id=<?= $job['id'] ?>" class="btn btn-sm btn-outline-info">
                            <i class="bi bi-gear me-1"></i>Parts
                            <?php if ($parts_qty > 0): ?><span class="badge bg-info text-dark ms-1"><?= $parts_qty ?></span><?php endif; ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($job['status'] === 'assigned' || $job['status'] === 'accepted'): ?>
                        <form method="post" action="" class="d-inline" onsubmit="return confirm('Start this job?');">
                            <input type="hidden" name="booking_id" value="<?= $job['id'] ?>">
                            <button type="submit" name="start_job" value="1" class="btn btn-sm btn-warning text-dark"><i class="bi bi-play-fill me-1"></i>Start</button>
                        </form>
                    <?php elseif ($job['status'] === 'in_progress'): ?>
                        <form method="post" action="" class="d-inline" onsubmit="return confirm(<?= $parts_pending > 0 ? "'" . $parts_pending . " part(s) still await customer approval — they will NOT be billed. Complete anyway?'" : "'Mark this job as completed?'" ?>);">
                            <input type="hidden" name="booking_id" value="<?= $job['id'] ?>">
                            <button type="submit" name="complete_job" value="1" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Complete<?= $parts_pending > 0 ? ' <span class="badge bg-light text-dark">' . $parts_pending . '⏳</span>' : '' ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach;
    endif;
}

$pageTitle = 'My Bookings';
include 'mechanic_sidebar.php';
?>
<style>
    /* Job cards */
    .job-layout { display: flex; gap: 1rem; }
    .job-img { flex: 0 0 96px; align-self: center; }
    .job-img img { width: 96px; height: 96px; object-fit: cover; border-radius: 12px; border: 1px solid #e2e8f0; background: #f8fafc; padding: 3px; }
    .job-info { flex: 1 1 0; min-width: 0; }
    .job-label { text-transform: uppercase; font-size: 0.64rem; letter-spacing: .05em; color: #94a3b8; margin-bottom: .2rem; font-weight: 600; }
    .job-text { font-size: 0.8rem; margin-bottom: .1rem; line-height: 1.3; }
    .job-text.fw-semibold, .job-info ul.job-text { margin-bottom: .2rem; }
    .job-info ul.job-text { padding-left: 1rem !important; }
    .job-card .card-body { padding: .75rem 1.1rem !important; }
    .job-card { transition: box-shadow .2s ease, transform .2s ease; }
    .job-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,.08) !important; }
    .job-actions { display: flex; justify-content: flex-end; gap: .5rem; flex-wrap: wrap; background: #f8fafc; border-radius: 10px; padding: .4rem .65rem; margin-top: .6rem; }
    .job-actions .btn { border-radius: 999px; padding: .3rem .9rem; font-size: .78rem; font-weight: 600; }
    .job-actions .btn:hover { transform: translateY(-1px); }
    .job-actions .btn .badge { border-radius: 999px; }
    @media (min-width: 992px) {
        .job-info + .job-info { border-left: 1px dashed #e2e8f0; padding-left: 1.25rem; }
    }
    @media (max-width: 991.98px) {
        .job-layout { flex-wrap: wrap; }
        .job-img { flex-basis: 100%; display: flex; justify-content: center; }
        .job-img img { width: 100%; max-width: 200px; height: 110px; }
        .job-info { flex: 1 1 45%; }
    }
    @media (max-width: 575.98px) {
        .job-info { flex: 1 1 100%; }
    }
    /* Filter pills */
    .filter-pill { border-radius: 999px; padding: .35rem 1rem; }
    /* Stat cards */
    .stat-card .stat-num { font-size: 1.6rem; font-weight: 700; line-height: 1.1; }
    .emergency-table,
    .emergency-table thead,
    .emergency-table tbody,
    .emergency-table tfoot,
    .emergency-table tr,
    .emergency-table th,
    .emergency-table td {
        background-color: transparent !important;
    }
    .emergency-table tbody tr:hover {
        background-color: transparent !important;
    }
</style>
<div class="content-area">
    <div class="container-fluid">
        <h2 class="fw-bold mb-4"><i class="bi bi-calendar-check me-2 text-warning-custom"></i>My Bookings</h2>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msg_type === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                <?= $msg ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <?php
            $stat_cards = [
                ['Open Jobs', $counts['open'], 'bi-hourglass-split', '#8b5cf6', 'rgba(139,92,246,.12)'],
                ['In Progress', $counts['in_progress'], 'bi-gear-fill', '#f59e0b', 'rgba(245,158,11,.12)'],
                ['Completed', $counts['completed'], 'bi-check-circle-fill', '#10b981', 'rgba(16,185,129,.12)'],
                ['Scheduled Today', $counts['today'], 'bi-calendar-day', '#3b82f6', 'rgba(59,130,246,.12)'],
            ];
            foreach ($stat_cards as [$label, $num, $icon, $color, $bg]): ?>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm stat-card h-100">
                    <div class="card-body d-flex align-items-center gap-3 py-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                             style="width:46px;height:46px;background:<?= $bg ?>;color:<?= $color ?>;">
                            <i class="bi <?= $icon ?> fs-5"></i>
                        </div>
                        <div>
                            <div class="stat-num" style="color:<?= $color ?>"><?= $num ?></div>
                            <small class="text-muted"><?= $label ?></small>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="d-flex gap-2 mb-4 flex-wrap">
            <?php
            $tabs = [
                'all' => 'All (' . count($all_jobs) . ')',
                'open' => 'Open (' . $counts['open'] . ')',
                'in_progress' => 'In Progress (' . $counts['in_progress'] . ')',
                'completed' => 'Completed (' . $counts['completed'] . ')',
            ];
            foreach ($tabs as $key => $label): ?>
                <a href="?filter=<?= $key ?>" class="btn btn-sm filter-pill <?= $filter === $key ? 'btn-dark' : 'btn-outline-secondary' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <h5 class="fw-bold mb-3">Assigned Bookings</h5>
        <?php renderJobTable($jobs, $services, $packages, $parts_map, $pay_map); ?>
    </div>
</div>

<?php include 'mechanic_sidebar_footer.php'; ?>