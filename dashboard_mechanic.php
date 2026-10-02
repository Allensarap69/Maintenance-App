<?php
session_start();
require 'db.php';
require 'motorcycle_health_helper.php';

// Security: mechanic only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mechanic') {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Mechanic';
$active_tab = $_GET['tab'] ?? 'current';

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

    if ($open_bookings === 0 && empty($current_booking) && $mechanic['status'] === 'Busy') {
        $pdo->prepare("UPDATE mechanics SET status = 'Available' WHERE id = ? AND status != 'Available'")->execute([$mechanic_id]);
    }
}

// Handle job status actions
$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $booking_id = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
    
    try {
        if (!empty($_POST['start_job']) && $booking_id) {
            $pdo->beginTransaction();
            
            // Verify this booking belongs to this mechanic and is assigned/accepted
            $check = $pdo->prepare("SELECT id FROM bookings WHERE id = ? AND mechanic_id = ? AND status IN ('assigned','accepted')");
            $check->execute([$booking_id, $mechanic_id]);
            if ($check->fetch()) {
                $pdo->prepare("UPDATE bookings SET status = 'in_progress' WHERE id = ?")->execute([$booking_id]);
                $pdo->prepare("UPDATE mechanics SET status = 'Busy', current_booking_id = ? WHERE id = ?")->execute([$booking_id, $mechanic_id]);
                
                $pdo->commit();
                $msg = "✅ Job #{$booking_id} started.";
                $msg_type = "success";
            } else {
                $pdo->rollBack();
                $msg = "❌ Job cannot be started.";
                $msg_type = "error";
            }
        } elseif (!empty($_POST['complete_job']) && $booking_id) {
            $pdo->beginTransaction();
            
            // Verify this booking belongs to this mechanic and is in progress
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
                $msg = "✅ Job #{$booking_id} completed.";
                $msg_type = "success";
            } else {
                $pdo->rollBack();
                $msg = "❌ Invalid job or job is not in progress.";
                $msg_type = "error";
            }
        } elseif (!empty($_POST['mark_emergency_complete'])) {
            $emergency_id = filter_input(INPUT_POST, 'emergency_id', FILTER_VALIDATE_INT);
            if ($emergency_id) {
                $pdo->beginTransaction();

                $check = $pdo->prepare("SELECT id FROM emergency_service_requests WHERE id = ? AND assigned_mechanic_id = ? AND request_status != 'completed'");
                $check->execute([$emergency_id, $mechanic_id]);
                if ($check->fetch()) {
                    $pdo->prepare("UPDATE emergency_service_requests SET request_status = 'completed', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$emergency_id]);

                    recordCompletedEmergencyHistory($emergency_id);

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
                    $msg = "✅ Emergency request #{$emergency_id} completed.";
                    $msg_type = "success";
                } else {
                    $pdo->rollBack();
                    $msg = "❌ Emergency request could not be completed.";
                    $msg_type = "error";
                }
            } else {
                $msg = "❌ Invalid emergency request ID.";
                $msg_type = "error";
            }
        } elseif (!empty($_POST['set_availability'])) {
            $new_status = $_POST['availability_status'] ?? '';
            if (in_array($new_status, ['Available', 'Off-Duty'], true)) {
                $avail_stmt = $pdo->prepare("UPDATE mechanics SET status = ? WHERE id = ? AND status != 'Busy'");
                $avail_stmt->execute([$new_status, $mechanic_id]);
                if ($avail_stmt->rowCount() > 0) {
                    $msg = "✅ Status updated to {$new_status}.";
                    $msg_type = "success";
                } else {
                    $msg = "❌ Cannot change status while you are Busy.";
                    $msg_type = "error";
                }
                // Refresh mechanic status
                $mechanic_stmt->execute([$user_id]);
                $mechanic = $mechanic_stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $msg = "❌ Invalid status.";
                $msg_type = "error";
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Mechanic dashboard error: " . $e->getMessage());
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
        b.total_price,
        b.created_at,
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
        m.current_mileage
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    LEFT JOIN motorcycles m ON b.vehicle_id = m.id
    WHERE b.mechanic_id = ?
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
$jobs_stmt->execute([$mechanic_id]);
$all_jobs = $jobs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Split current vs history
$current_jobs = array_filter($all_jobs, fn($j) => $j['status'] !== 'completed');
$history_jobs = array_filter($all_jobs, fn($j) => $j['status'] === 'completed');

// Fetch emergency service requests assigned to this mechanic
$emergency_stmt = $pdo->prepare("
    SELECT 
        esr.id,
        esr.request_status as status,
        esr.motorcycle_issue as issue_type,
        esr.priority,
        esr.problem_description as description,
        esr.location,
        esr.location_description,
        esr.image_path,
        esr.contact_number,
        esr.latitude,
        esr.longitude,
        esr.created_at,
        esr.updated_at,
        c.id as customer_id,
        c.username as customer_name,
        c.phone as customer_phone,
        c.email as customer_email,
        c.address as customer_address,
        m.id as motorcycle_id,
        m.brand,
        m.model,
        m.year_model,
        m.plate_number,
        m.color,
        m.current_mileage
    FROM emergency_service_requests esr
    JOIN users c ON esr.customer_id = c.id
    LEFT JOIN motorcycles m ON esr.motorcycle_id = m.id
    WHERE esr.assigned_mechanic_id = ? AND esr.request_status NOT IN ('declined', 'cancelled')
    ORDER BY 
        CASE esr.request_status
            WHEN 'assigned' THEN 1
            WHEN 'accepted' THEN 2
            WHEN 'in_progress' THEN 3
            WHEN 'completed' THEN 4
            ELSE 5
        END,
        esr.created_at DESC
");
$emergency_stmt->execute([$mechanic_id]);
$emergency_requests = $emergency_stmt->fetchAll(PDO::FETCH_ASSOC);

$stats = [
    'total' => count($all_jobs),
    'current' => count($current_jobs),
    'completed' => count($history_jobs),
    'emergency' => count($emergency_requests)
];

// Fetch service names
try {
    $stmt = $pdo->query("SELECT id, service_name FROM services");
    $services = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $services = [];
}

function getServiceText($service_ids_json, $services) {
    $service_ids = [];
    if (!empty($service_ids_json)) {
        $decoded = json_decode($service_ids_json, true);
        if (is_array($decoded)) {
            $service_ids = $decoded;
        } elseif (is_string($service_ids_json)) {
            $service_ids = array_map('trim', explode(',', $service_ids_json));
        }
    }
    $labels = [];
    foreach ($service_ids as $sid) {
        if (isset($services[$sid])) {
            $labels[] = $services[$sid];
        }
    }
    return !empty($labels) ? implode(', ', $labels) : 'N/A';
}

// Build today's schedule from current jobs and all emergency requests
$today = date('Y-m-d');
$today_items = [];
foreach ($current_jobs as $job) {
    if ($job['schedule_date'] >= $today) {
        $today_items[] = [
            'type' => 'booking',
            'id' => $job['id'],
            'title' => 'Booking #' . $job['id'],
            'customer' => $job['customer_name'],
            'phone' => $job['customer_phone'],
            'time' => $job['schedule_start_time'] ? date('g:i A', strtotime($job['schedule_start_time'])) : '-',
            'date' => $job['schedule_date'],
            'ts' => strtotime($job['schedule_date'] . ' ' . ($job['schedule_start_time'] ?: '00:00:00')),
            'label' => 'Booking',
            'status' => $job['status'],
            'service' => getServiceText($job['service_ids'], $services),
            'vehicle' => ($job['brand'] ? $job['brand'] . ' ' . $job['model'] : '') . ($job['plate_number'] ? ' (' . $job['plate_number'] . ')' : '')
        ];
    }
}
foreach ($emergency_requests as $req) {
    if ($req['status'] !== 'completed') {
        $today_items[] = [
            'type' => 'emergency',
            'id' => $req['id'],
            'title' => 'Emergency: ' . ($req['issue_type'] ?? 'N/A'),
            'customer' => $req['customer_name'],
            'phone' => $req['customer_phone'],
            'time' => $req['created_at'] ? date('g:i A', strtotime($req['created_at'])) : '-',
            'date' => $req['created_at'] ? date('Y-m-d', strtotime($req['created_at'])) : '-',
            'ts' => $req['created_at'] ? strtotime($req['created_at']) : 0,
            'label' => 'Emergency',
            'status' => $req['status'],
            'service' => $req['issue_type'] ?? 'N/A',
            'vehicle' => ($req['brand'] ? $req['brand'] . ' ' . $req['model'] : '') . ($req['plate_number'] ? ' (' . $req['plate_number'] . ')' : '')
        ];
    }
}

usort($today_items, fn($a, $b) => $a['ts'] <=> $b['ts']);

function renderJobModal($job, $services) {
    $service_text = getServiceText($job['service_ids'], $services);
    $vehicle_text = ($job['brand'] ? $job['brand'] . ' ' . $job['model'] : 'N/A') . ($job['plate_number'] ? ' (' . $job['plate_number'] . ')' : '');
    ?>
    <div class="modal fade" id="jobModal<?= $job['id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title">Job #<?= $job['id'] ?> Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <h6 class="text-muted text-uppercase fw-bold fs-7">Customer</h6>
                        <p class="mb-1"><strong><?= htmlspecialchars($job['customer_name']) ?></strong></p>
                        <p class="mb-1"><i class="bi bi-envelope me-2 text-muted"></i><?= htmlspecialchars($job['customer_email'] ?? '-') ?></p>
                        <p class="mb-1"><i class="bi bi-telephone me-2 text-muted"></i><?= htmlspecialchars($job['customer_phone'] ?? '-') ?></p>
                        <p class="mb-0"><i class="bi bi-geo-alt me-2 text-muted"></i><?= nl2br(htmlspecialchars($job['customer_address'] ?? '-')) ?></p>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <h6 class="text-muted text-uppercase fw-bold fs-7">Motorcycle</h6>
                        <p class="mb-1"><strong><?= htmlspecialchars($vehicle_text) ?></strong></p>
                        <p class="mb-1">Color: <?= htmlspecialchars($job['color'] ?? '-') ?></p>
                        <p class="mb-1">Year: <?= htmlspecialchars($job['year_model'] ?? '-') ?></p>
                        <p class="mb-0">Mileage: <?= $job['current_mileage'] ? number_format($job['current_mileage']) . ' km' : '-' ?></p>
                    </div>
                    <hr>
                    <div class="mb-0">
                        <h6 class="text-muted text-uppercase fw-bold fs-7">Booking</h6>
                        <p class="mb-1">Date: <?= date('M d, Y', strtotime($job['schedule_date'])) ?></p>
                        <p class="mb-1">Time: <?= date('g:i A', strtotime($job['schedule_start_time'])) ?> - <?= date('g:i A', strtotime($job['schedule_end_time'])) ?></p>
                        <p class="mb-1">Services: <?= htmlspecialchars($service_text) ?></p>
                        <p class="mb-0">Total Price: ₱<?= number_format($job['total_price'], 2) ?></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <?php if (in_array($job['status'], ['assigned','accepted'])): ?>
                        <form method="POST" action="dashboard_mechanic.php" class="me-auto" onsubmit="return confirm('Start this job?')">
                            <input type="hidden" name="booking_id" value="<?= $job['id'] ?>">
                            <button type="submit" name="start_job" class="btn btn-warning"><i class="bi bi-play-fill me-1"></i>Start Job</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($job['status'], ['assigned','accepted','in_progress'])): ?>
                        <form method="POST" action="dashboard_mechanic.php" class="<?= in_array($job['status'], ['assigned','accepted']) ? '' : 'me-auto' ?>" onsubmit="return confirm('Mark this job as completed?')">
                            <input type="hidden" name="booking_id" value="<?= $job['id'] ?>">
                            <button type="submit" name="complete_job" class="btn btn-success"><i class="bi bi-check-circle me-1"></i>Complete</button>
                        </form>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function renderEmergencyModal($req) {
    $vehicle_text = ($req['brand'] ? $req['brand'] . ' ' . $req['model'] : 'N/A') . ($req['plate_number'] ? ' (' . $req['plate_number'] . ')' : '');
    ?>
    <div class="modal fade" id="emergencyModal<?= $req['id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title">Emergency Request #<?= $req['id'] ?> Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <h6 class="text-muted text-uppercase fw-bold fs-7">Customer</h6>
                        <p class="mb-1"><strong><?= htmlspecialchars($req['customer_name']) ?></strong></p>
                        <p class="mb-1"><i class="bi bi-telephone me-2 text-muted"></i><?= htmlspecialchars($req['customer_phone'] ?? '-') ?></p>
                        <p class="mb-0"><i class="bi bi-geo-alt me-2 text-muted"></i><?= nl2br(htmlspecialchars($req['customer_address'] ?? '-')) ?></p>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <h6 class="text-muted text-uppercase fw-bold fs-7">Motorcycle</h6>
                        <p class="mb-1"><strong><?= htmlspecialchars($vehicle_text) ?></strong></p>
                        <p class="mb-1">Color: <?= htmlspecialchars($req['color'] ?? '-') ?></p>
                        <p class="mb-1">Year: <?= htmlspecialchars($req['year_model'] ?? '-') ?></p>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <h6 class="text-muted text-uppercase fw-bold fs-7">Issue</h6>
                        <p class="mb-1"><strong><?= htmlspecialchars($req['issue_type'] ?? 'N/A') ?></strong></p>
                        <p class="mb-1">Priority: <span class="badge bg-<?= strtolower($req['priority'] ?? '') === 'urgent' ? 'danger' : (strtolower($req['priority'] ?? '') === 'high' ? 'warning' : 'info') ?>"><?= htmlspecialchars(ucfirst($req['priority'] ?? 'Normal')) ?></span></p>
                        <p class="mb-0">Description: <?= nl2br(htmlspecialchars($req['description'] ?? '-')) ?></p>
                    </div>
                    <hr>
                    <div class="mb-0">
                        <h6 class="text-muted text-uppercase fw-bold fs-7">Request</h6>
                        <p class="mb-1">Status: <?= htmlspecialchars(ucfirst($req['status'])) ?></p>
                        <p class="mb-1">Created: <?= $req['created_at'] ? date('M d, Y g:i A', strtotime($req['created_at'])) : '-' ?></p>
                        <?php if (!empty($req['latitude']) && !empty($req['longitude'])): ?>
                            <p class="mb-0">Location: <?= htmlspecialchars($req['latitude']) ?>, <?= htmlspecialchars($req['longitude']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <?php if ($req['status'] !== 'completed'): ?>
                        <form method="post" action="" class="me-auto" onsubmit="return confirm('Mark this emergency request as completed?');">
                            <input type="hidden" name="emergency_id" value="<?= $req['id'] ?>">
                            <button type="submit" name="mark_emergency_complete" class="btn btn-success"><i class="bi bi-check-lg me-2"></i>Mark as Complete</button>
                        </form>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php
}

?>

<?php
$pageTitle = 'Mechanic Dashboard';
$pageSubtitle = 'Mindanao Everstar Motorcycle Service';
include 'mechanic_sidebar.php';

// --- Dashboard prep vars ---
$isOffDuty = $mechanic['status'] === 'Off-Duty';
$isBusy = $mechanic['status'] === 'Busy';
$activeEmergencies = count(array_filter($emergency_requests, fn($r) => $r['status'] !== 'completed'));

// Jobs completed today (bookings have no updated_at, so use the schedule date)
$completedToday = 0;
foreach ($history_jobs as $j) {
    if ($j['schedule_date'] === $today) $completedToday++;
}
foreach ($emergency_requests as $r) {
    if ($r['status'] === 'completed' && $r['updated_at'] && date('Y-m-d', strtotime($r['updated_at'])) === $today) {
        $completedToday++;
    }
}

// Today's work queue: bookings scheduled today, ordered by start time
$todayQueue = array_values(array_filter($all_jobs, fn($j) => $j['schedule_date'] === $today));
usort($todayQueue, fn($a, $b) => strcmp($a['schedule_start_time'], $b['schedule_start_time']));

// Soft status pill: [label, css class]
function statusPill($status) {
    return match ($status) {
        'completed'                   => ['Completed', 'done'],
        'in_progress'                 => ['In Progress', 'progress'],
        'accepted'                    => ['Accepted', 'accepted'],
        'assigned'                    => ['Waiting', 'waiting'],
        'pending'                     => ['Pending', 'pending'],
        'deposit_submitted'           => ['Deposit', 'pending'],
        default                       => [ucfirst($status ?: 'In Progress'), 'waiting'],
    };
}

// Pseudo-priority for bookings based on job value
function jobPriority($job) {
    $p = (float) $job['total_price'];
    if ($p >= 1500) return ['High', 'high'];
    if ($p >= 700)  return ['Medium', 'medium'];
    return ['Low', 'low'];
}

// Current job for the Work Progress stepper
$activeJob = null;
foreach ($current_jobs as $j) {
    if ($j['status'] === 'in_progress') { $activeJob = $j; break; }
}
if (!$activeJob) {
    foreach ($todayQueue as $j) {
        if ($j['status'] !== 'completed') { $activeJob = $j; break; }
    }
}
if (!$activeJob && !empty($current_jobs)) $activeJob = reset($current_jobs);

$stepIndex = -1;
if ($activeJob) {
    $stepIndex = $activeJob['status'] === 'completed' ? 3 : ($activeJob['status'] === 'in_progress' ? 1 : 0);
}
$minsLeft = null;
if ($activeJob && $activeJob['schedule_date'] === $today && $activeJob['schedule_end_time']) {
    $minsLeft = max(0, (int) round((strtotime($today . ' ' . $activeJob['schedule_end_time']) - time()) / 60));
}

// Next open emergency for the emergency card
$nextEmergency = null;
foreach ($emergency_requests as $r) {
    if ($r['status'] !== 'completed') { $nextEmergency = $r; break; }
}

// Completion trend: jobs finished per day (last 7 days)
$trendLabels = [];
$trendData = [];
for ($i = 6; $i >= 0; $i--) {
    $key = date('Y-m-d', strtotime("-$i days"));
    $trendLabels[$key] = date('M j', strtotime("-$i days"));
    $trendData[$key] = 0;
}
foreach ($history_jobs as $j) {
    $k = $j['schedule_date'] ? date('Y-m-d', strtotime($j['schedule_date'])) : null;
    if ($k && isset($trendData[$k])) $trendData[$k]++;
}
foreach ($emergency_requests as $r) {
    if ($r['status'] === 'completed') {
        $d = $r['updated_at'] ?: $r['created_at'];
        $k = $d ? date('Y-m-d', strtotime($d)) : null;
        if ($k && isset($trendData[$k])) $trendData[$k]++;
    }
}
$trendLabelsJson = json_encode(array_values($trendLabels));
$trendDataJson = json_encode(array_values($trendData));

// Recent activity feed: "Job #id - customer - vehicle - service"
$activity_items = [];
foreach ($all_jobs as $j) {
    $vehicle = trim(($j['brand'] ?? '') . ' ' . ($j['model'] ?? '')) ?: 'N/A';
    $activity_items[] = [
        'sort'   => $j['created_at'] ?? '',
        'label'  => 'Job #' . $j['id'],
        'detail' => $j['customer_name'] . ' • ' . $vehicle . ' • ' . getServiceText($j['service_ids'], $services),
        'status' => $j['status'],
        'time'   => $j['created_at'],
        'type'   => 'booking',
    ];
}
foreach ($emergency_requests as $r) {
    $vehicle = trim(($r['brand'] ?? '') . ' ' . ($r['model'] ?? '')) ?: 'N/A';
    $activity_items[] = [
        'sort'   => $r['updated_at'] ?: $r['created_at'],
        'label'  => 'Emergency #' . $r['id'],
        'detail' => $r['customer_name'] . ' • ' . $vehicle . ' • ' . ($r['issue_type'] ?? 'N/A'),
        'status' => $r['status'],
        'time'   => $r['updated_at'] ?: $r['created_at'],
        'type'   => 'emergency',
    ];
}
usort($activity_items, fn($a, $b) => strcmp($b['sort'] ?? '', $a['sort'] ?? ''));
$activity_items = array_slice($activity_items, 0, 5);
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<script src="https://unpkg.com/lucide@latest"></script>
<style>
    :root { --card-border:#E8ECF3; --text-muted:#6B7280; }
    body { background-color:#F5F7FB !important; font-family:'Plus Jakarta Sans',sans-serif !important; }
    .content-area { background:#F5F7FB; }
    .dashboard-page { max-width:1460px; margin:0 auto; width:100%; padding-bottom:3rem; }
    .dash-header { display:flex; align-items:flex-start; justify-content:flex-end; gap:1rem; margin-bottom:1.1rem; flex-wrap:wrap; }
    .dash-header-meta { display:flex; align-items:center; gap:1.1rem; color:var(--text-muted); font-size:0.82rem; font-weight:600; }
    .dash-header-meta span { display:inline-flex; align-items:center; gap:0.4rem; }
    .dash-header-meta i { width:15px; height:15px; color:#9CA3AF; }
    .dash-alert { background:#fff; border:1px solid var(--card-border); border-radius:10px; padding:0.6rem 1rem; font-size:0.85rem; margin-bottom:1.25rem; }
    .dash-alert.success { border-left:3px solid #10b981; }
    .dash-alert.error { border-left:3px solid #ef4444; }

    /* ---- Stat cards ---- */
    .stat-grid { display:grid; grid-template-columns:1.4fr 1fr 1fr 1fr; gap:1rem; margin-bottom:1.1rem; }
    .stat-card { background:#fff; border:1px solid var(--card-border); border-radius:16px; padding:1.1rem 1.2rem; position:relative; box-shadow:0 2px 10px rgba(15,23,42,0.03); display:flex; flex-direction:column; transition:transform .18s ease,box-shadow .18s ease; }
    .stat-card:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(15,23,42,0.07); }
    .stat-head { display:flex; align-items:center; gap:0.6rem; }
    .stat-icon { width:42px; height:42px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .stat-icon i { width:19px; height:19px; }
    .stat-icon.amber { background:#FEF3D8; color:#f59e0b; }
    .stat-icon.blue { background:#E3EDFF; color:#3b82f6; }
    .stat-icon.green { background:#DCF5EC; color:#10b981; }
    .stat-icon.red { background:#FDE4E4; color:#ef4444; }
    .stat-icon.grey { background:#F1F4F9; color:#64748b; }
    .stat-label { font-size:0.78rem; font-weight:600; color:#6B7280; line-height:1.25; }
    .stat-value { font-size:1.65rem; font-weight:800; color:#0f172a; line-height:1.15; margin-top:0.55rem; }
    .stat-sub { font-size:0.72rem; color:var(--text-muted); margin-top:0.15rem; display:flex; align-items:center; gap:0.3rem; }
    .stat-sub .live-dot { width:7px; height:7px; border-radius:50%; background:#10b981; display:inline-block; }
    .stat-sub .live-dot.off { background:#94a3b8; }
    .stat-link { font-size:0.75rem; font-weight:600; color:#3b82f6; text-decoration:none; display:inline-flex; align-items:center; gap:0.25rem; margin-top:0.55rem; }
    .stat-link:hover { color:#1d4ed8; }
    .stat-link i { width:13px; height:13px; }

    /* Segmented duty toggle */
    .seg-toggle { display:inline-flex; background:#F1F4F9; border-radius:99px; padding:3px; gap:2px; margin-top:0.7rem; }
    .seg-toggle form { display:flex; }
    .seg-btn { border:none; background:transparent; font-family:inherit; font-size:0.72rem; font-weight:700; color:#64748b; padding:0.34rem 0.95rem; border-radius:99px; cursor:pointer; transition: transform .15s ease, box-shadow .15s ease, opacity .15s ease, visibility .15s ease, max-height .15s ease; }
    .seg-btn:hover:not(:disabled) { color:#0f172a; }
    .seg-btn.active.on { background:#FACC15; color:#111827; box-shadow:0 2px 6px rgba(250,204,21,0.45); }
    .seg-btn.active.off { background:#fff; color:#0f172a; box-shadow:0 1px 4px rgba(15,23,42,0.12); }
    .seg-btn:disabled { opacity:0.55; cursor:not-allowed; }

    /* ---- Cards ---- */
    .card-x { background:#fff; border:1px solid var(--card-border); border-radius:16px; padding:1.1rem 1.2rem; box-shadow:0 2px 10px rgba(15,23,42,0.03); display:flex; flex-direction:column; }
    .card-x-head { display:flex; align-items:flex-start; justify-content:space-between; gap:0.5rem; margin-bottom:0.7rem; }
    .card-x-title { font-size:0.95rem; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:0.45rem; }
    .card-x-title i { width:17px; height:17px; color:#f59e0b; }
    .card-x-title i.blue { color:#3b82f6; }
    .card-x-title i.red { color:#ef4444; }
    .card-x-title i.grey { color:#94a3b8; }
    .card-x-desc { font-size:0.75rem; color:var(--text-muted); margin-top:0.15rem; }
    .card-x-link { font-size:0.75rem; font-weight:600; color:#3b82f6; text-decoration:none; display:inline-flex; align-items:center; gap:0.2rem; white-space:nowrap; }
    .card-x-link:hover { color:#1d4ed8; }
    .card-x-link i { width:13px; height:13px; }
    .card-empty { text-align:center; color:var(--text-muted); font-size:0.8rem; padding:2rem 0; }

    /* ---- Layout rows ---- */
    .row-queue { display:grid; grid-template-columns:1.9fr 1fr; gap:1rem; margin-bottom:1.1rem; }
    .row-mid { display:grid; grid-template-columns:1fr 1.25fr 1fr; gap:1rem; margin-bottom:1.1rem; }

    /* ---- Work queue table ---- */
    .queue-table { width:100%; border-collapse:collapse; font-size:0.8rem; }
    .queue-table thead th { font-size:0.66rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted); font-weight:700; padding:0.5rem 0.55rem; border-bottom:1px solid var(--card-border); text-align:left; white-space:nowrap; }
    .queue-table tbody td { padding:0.65rem 0.55rem; border-bottom:1px solid #F1F4F9; vertical-align:middle; }
    .queue-table tbody tr:last-child td { border-bottom:none; }
    .q-num { color:#9CA3AF; font-weight:600; width:24px; }
    .q-name { font-weight:700; color:#0f172a; white-space:nowrap; }
    .q-sub { font-size:0.68rem; color:var(--text-muted); white-space:nowrap; }
    .q-svc { font-weight:600; color:#0f172a; }
    .q-svc-sub { font-size:0.68rem; color:var(--text-muted); }
    .q-time { font-weight:600; color:#0f172a; white-space:nowrap; }
    .pill { display:inline-block; font-size:0.66rem; font-weight:700; padding:0.26rem 0.65rem; border-radius:99px; white-space:nowrap; }
    .pill.waiting { background:#F1F4F9; color:#64748b; }
    .pill.pending { background:#FEF3D8; color:#d97706; }
    .pill.accepted { background:#E3EDFF; color:#2563eb; }
    .pill.progress { background:#DBEAFE; color:#1d4ed8; }
    .pill.inspect { background:#EDE7FD; color:#7c3aed; }
    .pill.done { background:#DCF5EC; color:#059669; }
    .pill.declined { background:#FDE4E4; color:#ef4444; }
    .prio { display:inline-block; font-size:0.66rem; font-weight:700; padding:0.26rem 0.65rem; border-radius:99px; white-space:nowrap; }
    .prio.high { background:#FDE4E4; color:#ef4444; }
    .prio.medium { background:#FEF3D8; color:#d97706; }
    .prio.low { background:#F1F4F9; color:#64748b; }
    .btn-view-sm { display:inline-flex; align-items:center; gap:0.3rem; background:#fff; border:1px solid #E2E8F0; border-radius:8px; padding:0.32rem 0.8rem; font-size:0.72rem; font-weight:700; color:#0f172a; cursor:pointer; transition: transform .15s ease, box-shadow .15s ease, opacity .15s ease, visibility .15s ease, max-height .15s ease; }
    .btn-view-sm:hover { border-color:#3b82f6; color:#3b82f6; }
    .queue-scroll { overflow-x:auto; }

    /* ---- Schedule timeline ---- */
    .sched-list { display:flex; flex-direction:column; flex:1; }
    .sched-item { display:flex; gap:0.75rem; padding:0.55rem 0; }
    .sched-time { width:58px; flex-shrink:0; font-size:0.76rem; font-weight:700; color:#0f172a; padding-top:0.1rem; }
    .sched-rail { display:flex; flex-direction:column; align-items:center; flex-shrink:0; width:14px; }
    .sched-dot { width:11px; height:11px; border-radius:50%; border:2.5px solid #3b82f6; background:#fff; flex-shrink:0; margin-top:0.15rem; }
    .sched-dot.done { border-color:#10b981; background:#10b981; }
    .sched-dot.emergency { border-color:#ef4444; }
    .sched-line { width:2px; flex:1; background:#E5EAF2; margin-top:2px; }
    .sched-item:last-child .sched-line { display:none; }
    .sched-info { flex:1; min-width:0; }
    .sched-name { font-size:0.82rem; font-weight:700; color:#0f172a; }
    .sched-sub { font-size:0.7rem; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .sched-badge-wrap { flex-shrink:0; align-self:center; }

    /* ---- Work progress stepper ---- */
    .stepper { display:flex; align-items:flex-start; margin:0.9rem 0 1rem; }
    .step { flex:1; display:flex; flex-direction:column; align-items:center; position:relative; }
    .step-circle { width:34px; height:34px; border-radius:50%; background:#F1F4F9; color:#94a3b8; display:flex; align-items:center; justify-content:center; font-size:0.78rem; font-weight:800; z-index:1; border:2px solid #E8ECF3; }
    .step-circle i { width:15px; height:15px; }
    .step.done .step-circle { background:#FACC15; border-color:#FACC15; color:#111827; }
    .step.current .step-circle { background:#fff; border-color:#FACC15; color:#d97706; box-shadow:0 0 0 4px rgba(250,204,21,0.2); }
    .step-label { font-size:0.66rem; font-weight:700; color:#94a3b8; margin-top:0.4rem; text-align:center; }
    .step.done .step-label, .step.current .step-label { color:#0f172a; }
    .step-conn { position:absolute; top:17px; left:calc(-50% + 17px); right:calc(50% + 17px); height:2.5px; background:#E8ECF3; }
    .step.done .step-conn { background:#FACC15; }
    .step:first-child .step-conn { display:none; }
    .current-job { background:#F8FAFF; border:1px solid #E3EDFF; border-radius:12px; padding:0.8rem 0.95rem; display:flex; align-items:center; gap:0.8rem; margin-top:auto; }
    .current-job-icon { width:38px; height:38px; border-radius:50%; background:#E3EDFF; color:#3b82f6; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .current-job-icon i { width:17px; height:17px; }
    .current-job-info { flex:1; min-width:0; }
    .current-job-title { font-size:0.8rem; font-weight:700; color:#0f172a; }
    .current-job-sub { font-size:0.7rem; color:var(--text-muted); }
    .time-left { font-size:0.66rem; font-weight:700; color:#2563eb; background:#E3EDFF; border-radius:99px; padding:0.28rem 0.65rem; white-space:nowrap; }

    /* ---- Emergency card ---- */
    .emg-card { background:#FFF7F7; border:1px solid #FBDCDC; border-radius:14px; padding:0.95rem 1rem; display:block; text-decoration:none;  }
    .emg-card:hover { border-color:#ef4444; }
    .emg-card-top { display:flex; align-items:center; justify-content:space-between; gap:0.5rem; }
    .emg-card-title { font-size:0.85rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:0.4rem; }
    .emg-card-title i { width:15px; height:15px; color:#ef4444; }
    .emg-card-top i.go { width:15px; height:15px; color:#C4CBD8; }
    .emg-card-loc { font-size:0.72rem; color:var(--text-muted); margin-top:0.2rem; }
    .emg-card-meta { font-size:0.72rem; color:#374151; font-weight:600; margin-top:0.55rem; display:flex; align-items:center; gap:0.35rem; }
    .emg-card-meta i { width:13px; height:13px; color:#9CA3AF; }
    .emg-card-foot { display:flex; align-items:center; justify-content:space-between; margin-top:0.65rem; }
    .emg-card-time { font-size:0.7rem; color:var(--text-muted); display:inline-flex; align-items:center; gap:0.3rem; }
    .emg-card-time i { width:12px; height:12px; }

    /* ---- Chart ---- */
    .chart-wrap { position:relative; height:210px; width:100%; }

    /* ---- Recent activity ---- */
    .act-row { display:flex; align-items:center; gap:0.8rem; padding:0.65rem 0.2rem; border-bottom:1px solid #F1F4F9; }
    .act-row:last-child { border-bottom:none; }
    .act-dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; }
    .act-dot.booking { background:#3b82f6; }
    .act-dot.emergency { background:#ef4444; }
    .act-text { flex:1; min-width:0; font-size:0.8rem; color:#0f172a; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .act-text b { font-weight:800; }
    .act-right { display:flex; align-items:center; gap:0.9rem; flex-shrink:0; }
    .act-time { font-size:0.7rem; color:var(--text-muted); min-width:58px; text-align:right; }

    @media (max-width:1200px) { .stat-grid { grid-template-columns:repeat(2,1fr); } .row-queue,.row-mid { grid-template-columns:1fr; } }
    @media (max-width:767px) { .stat-grid { grid-template-columns:1fr; } .act-text { white-space:normal; } }
</style>
<div class="content-area">
<div class="dashboard-page">
    <div class="dash-header">
        <div class="dash-header-meta">
            <span><i data-lucide="calendar"></i><?= date('l, F j, Y') ?></span>
            <span><i data-lucide="clock"></i><span id="dashClock"><?= date('g:i A') ?></span></span>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="dash-alert <?= $msg_type === 'success' ? 'success' : 'error' ?>"><?= $msg ?></div>
    <?php endif; ?>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-head">
                <div class="stat-icon <?= $isOffDuty ? 'grey' : 'amber' ?>"><i data-lucide="wrench"></i></div>
                <div class="stat-label">Current Status</div>
            </div>
            <div class="stat-value"><?= $isOffDuty ? 'Off Duty' : 'On Duty' ?></div>
            <div class="seg-toggle">
                <form method="POST" action="dashboard_mechanic.php">
                    <input type="hidden" name="availability_status" value="Off-Duty">
                    <button type="submit" name="set_availability" class="seg-btn <?= $isOffDuty ? 'active off' : '' ?>" <?= $isBusy ? 'disabled title="Finish current work first"' : '' ?>>Off Duty</button>
                </form>
                <form method="POST" action="dashboard_mechanic.php">
                    <input type="hidden" name="availability_status" value="Available">
                    <button type="submit" name="set_availability" class="seg-btn <?= !$isOffDuty ? 'active on' : '' ?>" <?= $isBusy ? 'disabled title="Finish current work first"' : '' ?>>On Duty</button>
                </form>
            </div>
            <div class="stat-sub">
                <span class="live-dot <?= $isOffDuty ? 'off' : '' ?>"></span>
                <?= $isBusy ? "You're on a job right now." : ($isOffDuty ? "You're off duty. Not receiving assignments." : "You're currently on duty. Ready for assigned jobs.") ?>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-head">
                <div class="stat-icon blue"><i data-lucide="clipboard-list"></i></div>
                <div class="stat-label">Assigned Jobs</div>
            </div>
            <div class="stat-value"><?= $stats['current'] ?></div>
            <a href="mechanic_bookings.php" class="stat-link">View all <i data-lucide="arrow-right"></i></a>
        </div>
        <div class="stat-card">
            <div class="stat-head">
                <div class="stat-icon green"><i data-lucide="check-circle"></i></div>
                <div class="stat-label">Completed Today</div>
            </div>
            <div class="stat-value"><?= $completedToday ?></div>
            <a href="mechanic_bookings.php" class="stat-link">View all <i data-lucide="arrow-right"></i></a>
        </div>
        <div class="stat-card">
            <div class="stat-head">
                <div class="stat-icon red"><i data-lucide="alert-triangle"></i></div>
                <div class="stat-label">Emergency Requests</div>
            </div>
            <div class="stat-value"><?= $activeEmergencies ?></div>
            <a href="mechanic_emergency.php" class="stat-link">View all <i data-lucide="arrow-right"></i></a>
        </div>
    </div>

    <div class="row-queue">
        <div class="card-x">
            <div class="card-x-head">
                <div>
                    <div class="card-x-title"><i data-lucide="calendar-days" class="blue"></i>Today's Work Queue</div>
                    <div class="card-x-desc">Your assigned jobs for today</div>
                </div>
            </div>
            <?php if (empty($todayQueue)): ?>
                <div class="card-empty">No jobs scheduled for today.</div>
            <?php else: ?>
                <div class="queue-scroll">
                    <table class="queue-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Customer</th>
                                <th>Motorcycle</th>
                                <th>Service / Issue</th>
                                <th>Scheduled Time</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todayQueue as $i => $job):
                                [$sText, $sClass] = statusPill($job['status']);
                                [$pText, $pClass] = jobPriority($job);
                                $vehicle = trim(($job['brand'] ?? '') . ' ' . ($job['model'] ?? '')) ?: 'N/A';
                                $svc = getServiceText($job['service_ids'], $services);
                            ?>
                                <tr>
                                    <td class="q-num"><?= $i + 1 ?></td>
                                    <td>
                                        <div class="q-name"><?= htmlspecialchars($job['customer_name']) ?></div>
                                        <div class="q-sub"><?= htmlspecialchars($job['customer_phone'] ?? '') ?></div>
                                    </td>
                                    <td>
                                        <div class="q-name"><?= htmlspecialchars($vehicle) ?></div>
                                        <div class="q-sub"><?= htmlspecialchars($job['plate_number'] ?? '') ?></div>
                                    </td>
                                    <td>
                                        <div class="q-svc"><?= htmlspecialchars($svc) ?></div>
                                        <div class="q-svc-sub"><?= $job['current_mileage'] ? '(' . number_format($job['current_mileage']) . ' km)' : '' ?></div>
                                    </td>
                                    <td class="q-time"><?= $job['schedule_start_time'] ? date('h:i A', strtotime($job['schedule_start_time'])) : '-' ?></td>
                                    <td><span class="pill <?= $sClass ?>"><?= $sText ?></span></td>
                                    <td><span class="prio <?= $pClass ?>"><?= $pText ?></span></td>
                                    <td>
                                        <button type="button" class="btn-view-sm" data-bs-toggle="modal" data-bs-target="#jobModal<?= $job['id'] ?>">View</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="card-x">
            <div class="card-x-head">
                <div>
                    <div class="card-x-title"><i data-lucide="calendar-clock" class="blue"></i>Today's Schedule</div>
                </div>
                <a href="mechanic_bookings.php" class="card-x-link">View Full Schedule <i data-lucide="arrow-right"></i></a>
            </div>
            <div class="sched-list">
                <?php if (empty($today_items)): ?>
                    <div class="card-empty">No upcoming jobs or emergencies.</div>
                <?php else: ?>
                    <?php foreach (array_slice($today_items, 0, 6) as $item):
                        [$sText, $sClass] = statusPill($item['status']); ?>
                        <div class="sched-item">
                            <div class="sched-time"><?= htmlspecialchars($item['time']) ?></div>
                            <div class="sched-rail">
                                <span class="sched-dot <?= $item['status'] === 'completed' ? 'done' : '' ?> <?= $item['label'] === 'Emergency' ? 'emergency' : '' ?>"></span>
                                <span class="sched-line"></span>
                            </div>
                            <div class="sched-info">
                                <div class="sched-name"><?= htmlspecialchars($item['customer']) ?></div>
                                <div class="sched-sub"><?= htmlspecialchars(implode(' • ', array_filter([$item['vehicle'], $item['service'] ?? '']))) ?></div>
                            </div>
                            <span class="sched-badge-wrap pill <?= $sClass ?>"><?= $sText ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row-mid">
        <div class="card-x">
            <div class="card-x-head">
                <div>
                    <div class="card-x-title"><i data-lucide="wrench"></i>Work Progress</div>
                    <div class="card-x-desc">Current job progress</div>
                </div>
            </div>
            <?php
            $steps = ['Accepted', 'In Progress', 'Inspection', 'Completed'];
            ?>
            <div class="stepper">
                <?php foreach ($steps as $idx => $label):
                    $cls = $stepIndex < 0 ? '' : ($idx < $stepIndex || $stepIndex === 3 ? 'done' : ($idx === $stepIndex ? 'current' : ''));
                ?>
                    <div class="step <?= $cls ?>">
                        <span class="step-conn"></span>
                        <div class="step-circle">
                            <?php if ($cls === 'done' || ($stepIndex === 3 && $idx === 3)): ?>
                                <i data-lucide="check"></i>
                            <?php else: ?>
                                <?= $idx + 1 ?>
                            <?php endif; ?>
                        </div>
                        <div class="step-label"><?= $label ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($activeJob):
                $ajVehicle = trim(($activeJob['brand'] ?? '') . ' ' . ($activeJob['model'] ?? '')) ?: 'N/A';
            ?>
                <div class="current-job">
                    <div class="current-job-icon"><i data-lucide="bike"></i></div>
                    <div class="current-job-info">
                        <div class="current-job-title"><?= date('h:i A', strtotime($activeJob['schedule_start_time'])) ?> &nbsp;<?= htmlspecialchars($activeJob['customer_name']) ?></div>
                        <div class="current-job-sub"><?= htmlspecialchars($ajVehicle) ?> &middot; <?= htmlspecialchars(getServiceText($activeJob['service_ids'], $services)) ?></div>
                    </div>
                    <?php if ($minsLeft !== null): ?>
                        <span class="time-left"><?= $minsLeft ?> min left</span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="card-empty">No active job right now.</div>
            <?php endif; ?>
        </div>
        <div class="card-x">
            <div class="card-x-head">
                <div>
                    <div class="card-x-title"><i data-lucide="trending-up" class="grey"></i>Performance Trend</div>
                    <div class="card-x-desc">Completed jobs (last 7 days)</div>
                </div>
            </div>
            <div class="chart-wrap"><canvas id="mechanicTrendChart"></canvas></div>
        </div>
        <div class="card-x">
            <div class="card-x-head">
                <div>
                    <div class="card-x-title"><i data-lucide="alert-triangle" class="red"></i>Emergency Request</div>
                </div>
                <a href="mechanic_emergency.php" class="card-x-link">View All <i data-lucide="arrow-right"></i></a>
            </div>
            <?php if ($nextEmergency):
                $emVehicle = trim(($nextEmergency['brand'] ?? '') . ' ' . ($nextEmergency['model'] ?? '')) ?: 'N/A';
                [$esText, $esClass] = statusPill($nextEmergency['status']);
            ?>
                <a class="emg-card" role="button" data-bs-toggle="modal" data-bs-target="#emergencyModal<?= $nextEmergency['id'] ?>">
                    <div class="emg-card-top">
                        <div class="emg-card-title"><i data-lucide="map-pin"></i><?= htmlspecialchars($nextEmergency['issue_type'] ?? 'Emergency Request') ?></div>
                        <i data-lucide="chevron-right" class="go"></i>
                    </div>
                    <div class="emg-card-loc"><?= htmlspecialchars($nextEmergency['location'] ?? $nextEmergency['location_description'] ?? 'Location not provided') ?></div>
                    <div class="emg-card-meta"><i data-lucide="bike"></i><?= htmlspecialchars($emVehicle) ?> &nbsp;&bull;&nbsp; <?= htmlspecialchars($nextEmergency['contact_number'] ?? $nextEmergency['customer_phone'] ?? '') ?></div>
                    <div class="emg-card-foot">
                        <span class="emg-card-time"><i data-lucide="clock"></i><?= $nextEmergency['created_at'] ? date('h:i A', strtotime($nextEmergency['created_at'])) : '-' ?></span>
                        <span class="pill <?= $esClass ?>"><?= $esText ?></span>
                    </div>
                </a>
            <?php else: ?>
                <div class="card-empty">No open emergency requests.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-x">
        <div class="card-x-head">
            <div>
                <div class="card-x-title"><i data-lucide="history" class="grey"></i>Recent Activity</div>
                <div class="card-x-desc">Latest updates from your work</div>
            </div>
        </div>
        <?php if (empty($activity_items)): ?>
            <div class="card-empty">No recent activity yet.</div>
        <?php else: ?>
            <?php foreach ($activity_items as $a):
                [$asText, $asClass] = statusPill($a['status']);
            ?>
                <div class="act-row">
                    <span class="act-dot <?= $a['type'] ?>"></span>
                    <div class="act-text"><b><?= htmlspecialchars($a['label']) ?></b> &nbsp;•&nbsp; <?= htmlspecialchars($a['detail']) ?></div>
                    <div class="act-right">
                        <span class="pill <?= $asClass ?>"><?= $asText ?></span>
                        <span class="act-time"><?= $a['time'] ? date('h:i A', strtotime($a['time'])) : '-' ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</div>

<?php foreach ($all_jobs as $job) { renderJobModal($job, $services); } ?>
<?php foreach ($emergency_requests as $req) { renderEmergencyModal($req); } ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    lucide.createIcons();

    // Live clock
    (function () {
        const el = document.getElementById('dashClock');
        if (!el) return;
        setInterval(() => {
            el.textContent = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        }, 30000);
    })();

    const trendCtx = document.getElementById('mechanicTrendChart').getContext('2d');
    const trendGrad = trendCtx.createLinearGradient(0, 0, 0, 210);
    trendGrad.addColorStop(0, 'rgba(245,158,11,0.28)');
    trendGrad.addColorStop(1, 'rgba(245,158,11,0.02)');

    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?= $trendLabelsJson ?>,
            datasets: [{
                label: 'Jobs Completed',
                data: <?= $trendDataJson ?>,
                borderColor: '#f59e0b',
                backgroundColor: trendGrad,
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: '#f59e0b',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                borderWidth: 2.5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#F1F4F9' } },
                x: { grid: { display: false } }
            },
            plugins: { legend: { display: false } }
        }
    });
</script>

<?php include 'mechanic_sidebar_footer.php'; ?>