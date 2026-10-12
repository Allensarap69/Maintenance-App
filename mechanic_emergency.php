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

// Find the mechanic record linked to this user
$mechanic_stmt = $pdo->prepare("SELECT id, name FROM mechanics WHERE user_id = ?");
$mechanic_stmt->execute([$user_id]);
$mechanic = $mechanic_stmt->fetch(PDO::FETCH_ASSOC);

if (!$mechanic) {
    die("Your mechanic profile is missing. Please contact the administrator.");
}

$mechanic_id = $mechanic['id'];

$emergency_msg = '';
$emergency_msg_type = 'success';

// Flash message after redirect (prevents form resubmission on reload)
if (isset($_SESSION['mech_emergency_flash'])) {
    $emergency_msg = $_SESSION['mech_emergency_flash']['msg'];
    $emergency_msg_type = $_SESSION['mech_emergency_flash']['type'];
    unset($_SESSION['mech_emergency_flash']);
}

// Sync mechanic status based on open work
$sync_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM emergency_service_requests
    WHERE assigned_mechanic_id = ? AND request_status IN ('assigned','accepted','in_progress')
");
$sync_stmt->execute([$mechanic_id]);
$open_emergency_count = (int) $sync_stmt->fetchColumn();

if ($open_emergency_count > 0) {
    $pdo->prepare("UPDATE mechanics SET status = 'Busy' WHERE id = ? AND status NOT IN ('Busy','Off-Duty')")->execute([$mechanic_id]);
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
        $pdo->prepare("UPDATE mechanics SET status = 'Available' WHERE id = ? AND status = 'Busy'")->execute([$mechanic_id]);
    }
}


// Mechanic accepts an assigned emergency request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_emergency']) && isset($_POST['emergency_id'])) {
    $emergency_id = filter_input(INPUT_POST, 'emergency_id', FILTER_VALIDATE_INT);
    if ($emergency_id) {
        try {
            $accept_stmt = $pdo->prepare("UPDATE emergency_service_requests SET request_status = 'accepted', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND assigned_mechanic_id = ? AND request_status = 'assigned'");
            $accept_stmt->execute([$emergency_id, $mechanic_id]);
            if ($accept_stmt->rowCount() > 0) {
                $pdo->prepare("UPDATE mechanics SET status = 'Busy' WHERE id = ?")->execute([$mechanic_id]);
                try {
                    $pdo->prepare("INSERT INTO emergency_request_updates (request_id, update_type, update_message, updated_by) VALUES (?, 'accepted', ?, ?)")
                        ->execute([$emergency_id, 'Emergency request accepted by mechanic', $mechanic['name']]);
                } catch (PDOException $updErr) {
                    // Ignore updates-log errors; the main status must still save
                }
                $emergency_msg = "Emergency request #{$emergency_id} accepted.";
                $emergency_msg_type = 'success';
            } else {
                $emergency_msg = "Emergency request could not be accepted. It may already be accepted or not assigned to you.";
                $emergency_msg_type = 'danger';
            }
        } catch (PDOException $e) {
            $emergency_msg = "Database error: " . $e->getMessage();
            $emergency_msg_type = 'danger';
        }
    } else {
        $emergency_msg = "Invalid emergency request ID.";
        $emergency_msg_type = 'danger';
    }

    $_SESSION['mech_emergency_flash'] = ['msg' => $emergency_msg, 'type' => $emergency_msg_type];
    header("Location: mechanic_emergency.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_emergency_complete']) && isset($_POST['emergency_id'])) {
    $emergency_id = filter_input(INPUT_POST, 'emergency_id', FILTER_VALIDATE_INT);
    if ($emergency_id) {
        try {
            $complete_stmt = $pdo->prepare("UPDATE emergency_service_requests SET request_status = 'completed', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND assigned_mechanic_id = ? AND request_status != 'completed'");
            $complete_stmt->execute([$emergency_id, $mechanic_id]);
            if ($complete_stmt->rowCount() > 0) {
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

                $emergency_msg = "Emergency request #{$emergency_id} marked as completed.";
                $emergency_msg_type = 'success';
            } else {
                $emergency_msg = "Emergency request could not be marked as completed. It may already be completed or not assigned to you.";
                $emergency_msg_type = 'danger';
            }
        } catch (PDOException $e) {
            $emergency_msg = "Database error: " . $e->getMessage();
            $emergency_msg_type = 'danger';
        }
    } else {
        $emergency_msg = "Invalid emergency request ID.";
        $emergency_msg_type = 'danger';
    }

    $_SESSION['mech_emergency_flash'] = ['msg' => $emergency_msg, 'type' => $emergency_msg_type];
    header("Location: mechanic_emergency.php");
    exit;
}

// Fetch emergency service requests assigned to this mechanic
$emergency_stmt = $pdo->prepare("
    SELECT 
        esr.id,
        esr.request_status as status,
        esr.motorcycle_issue as issue_type,
        esr.service_type,
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

function renderEmergencyTable($emergencies) {
    if (empty($emergencies)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-inbox fs-1 d-block mb-3"></i>
            <p class="mb-0">No emergency requests assigned.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive-card">
            <table class="table emergency-table align-middle">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Issue</th>
                        <th>Priority</th>
                        <th class="d-none d-md-table-cell">Vehicle</th>
                        <th class="d-none d-md-table-cell">Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($emergencies as $req):
                        $vehicle_text = ($req['brand'] ? $req['brand'] . ' ' . $req['model'] : 'N/A') . ($req['plate_number'] ? ' (' . $req['plate_number'] . ')' : '');
                    ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($req['customer_name']) ?>
                                <br><small class="text-muted"><?= htmlspecialchars($req['customer_phone'] ?? '') ?></small>
                            </td>
                            <td>
                                <?= htmlspecialchars($req['issue_type'] ?? 'N/A') ?>
                                <br>
                                <?php if (($req['service_type'] ?? 'onsite_repair') === 'tow_service'): ?>
                                    <small class="badge rounded-pill bg-warning text-dark"><i class="bi bi-truck me-1"></i>Tow &amp; Lift</small>
                                <?php else: ?>
                                    <small class="badge rounded-pill bg-primary"><i class="bi bi-wrench-adjustable-circle me-1"></i>Fix On-Site</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge rounded-pill bg-<?= 
                                    strtolower($req['priority'] ?? '') === 'urgent' ? 'danger' : 
                                    (strtolower($req['priority'] ?? '') === 'high' ? 'warning' : 'info')
                                ?>">
                                    <?= htmlspecialchars(ucfirst($req['priority'] ?? 'Normal')) ?>
                                </span>
                            </td>
                            <td class="d-none d-md-table-cell"><?= htmlspecialchars($vehicle_text) ?></td>
                            <td class="d-none d-md-table-cell">
                                <span class="badge rounded-pill bg-<?= 
                                    $req['status'] === 'completed' ? 'success' : 
                                    ($req['status'] === 'assigned' ? 'primary' : 
                                    ($req['status'] === 'accepted' ? 'info' : 
                                    ($req['status'] === 'in_progress' ? 'warning' : 'secondary')))
                                ?>">
                                    <?= htmlspecialchars(ucfirst($req['status'])) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1">
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#emergencyModal<?= $req['id'] ?>" title="View details">
                                        <i class="bi bi-eye d-inline d-md-none"></i><span class="d-none d-md-inline">Details</span>
                                    </button>
                                    <?php if ($req['status'] === 'assigned'): ?>
                                        <form method="post" action="" class="d-inline" onsubmit="return confirm('Accept this emergency request?');">
                                            <input type="hidden" name="emergency_id" value="<?= $req['id'] ?>">
                                            <button type="submit" name="accept_emergency" class="btn btn-sm btn-primary" title="Accept emergency">
                                                <i class="bi bi-check d-inline d-md-none"></i><span class="d-none d-md-inline">Accept</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (in_array($req['status'], ['accepted', 'in_progress'])): ?>
                                        <form method="post" action="" class="d-inline" onsubmit="return confirm('Mark this emergency request as completed?');">
                                            <input type="hidden" name="emergency_id" value="<?= $req['id'] ?>">
                                            <button type="submit" name="mark_emergency_complete" class="btn btn-sm btn-success" title="Mark as complete">
                                                <i class="bi bi-check-lg d-inline d-md-none"></i><span class="d-none d-md-inline">Complete</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif;
}

function renderEmergencyModal($req) {
    $vehicle_text = ($req['brand'] ? $req['brand'] . ' ' . $req['model'] : 'N/A') . ($req['plate_number'] ? ' (' . $req['plate_number'] . ')' : '');
    $motorcycle_image = !empty($req['image_path']) ? $req['image_path'] : 'MOTOR.jpg';
    $maps_url = !empty($req['latitude']) && !empty($req['longitude']) ? 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode($req['latitude'] . ',' . $req['longitude']) : '#';

    $color_lower = strtolower($req['color'] ?? '');
    switch ($color_lower) {
        case 'red': $color_class = 'bg-danger'; break;
        case 'blue': $color_class = 'bg-primary'; break;
        case 'green': $color_class = 'bg-success'; break;
        case 'yellow': case 'gold': $color_class = 'bg-warning text-dark'; break;
        case 'black': $color_class = 'bg-dark'; break;
        case 'white': $color_class = 'bg-light text-dark border'; break;
        default: $color_class = 'bg-secondary';
    }

    $status_class = match ($req['status']) {
        'completed' => 'bg-success',
        'assigned' => 'bg-primary',
        'accepted' => 'bg-success',
        'in_progress' => 'bg-warning text-dark',
        default => 'bg-secondary'
    };
    $priority_class = match (strtolower($req['priority'] ?? '')) {
        'urgent' => 'bg-danger',
        'high' => 'bg-warning text-dark',
        'medium' => 'priority-medium',
        default => 'bg-info text-dark'
    };
    ?>
    <div class="modal fade" id="emergencyModal<?= $req['id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-fullscreen-sm-down">
            <div class="modal-content emergency-modal">
                <div class="modal-header emergency-modal-header">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle text-warning-custom me-2"></i>Emergency Request #<?= $req['id'] ?></h5>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge rounded-pill <?= $status_class ?>"><?= htmlspecialchars(ucfirst($req['status'])) ?></span>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body emergency-modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="emergency-card customer-card h-100">
                                <div class="emergency-card-header customer-header">
                                    <i class="bi bi-person me-2"></i>Customer
                                </div>
                                <div class="emergency-card-body">
                                    <div class="d-flex gap-3">
                                        <div class="customer-avatar">
                                            <i class="bi bi-person"></i>
                                        </div>
                                        <div>
                                            <h6 class="mb-1 fw-bold"><?= htmlspecialchars($req['customer_name']) ?></h6>
                                            <p class="mb-1 text-muted small"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($req['customer_phone'] ?? '-') ?></p>
                                            <p class="mb-0 text-muted small"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($req['customer_address'] ?? '-') ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="emergency-card motorcycle-card h-100">
                                <div class="emergency-card-header motorcycle-header">
                                    <i class="bi bi-motorcycle me-2"></i>Motorcycle
                                </div>
                                <div class="emergency-card-body">
                                    <div class="d-flex gap-3 align-items-center">
                                        <div class="motorcycle-info flex-grow-1">
                                            <h6 class="mb-1 fw-bold"><?= htmlspecialchars(($req['brand'] ? $req['brand'] . ' ' . $req['model'] : 'N/A')) ?></h6>
                                            <p class="mb-2 text-muted small">Unit: <?= htmlspecialchars($req['plate_number'] ?? '-') ?></p>
                                            <div class="d-flex gap-2">
                                                <span class="badge rounded-pill <?= $color_class ?>"><?= htmlspecialchars(ucfirst($req['color'] ?? 'N/A')) ?></span>
                                                <span class="badge rounded-pill year-badge"><?= htmlspecialchars($req['year_model'] ?? '-') ?></span>
                                            </div>
                                        </div>
                                        <div class="motorcycle-image">
                                            <img src="<?= htmlspecialchars($motorcycle_image) ?>" alt="Motorcycle" onerror="this.src='MOTOR.jpg'">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="emergency-card issue-card mt-3">
                        <div class="emergency-card-header issue-header">
                            <div><i class="bi bi-exclamation-triangle me-2"></i>Emergency Issue</div>
                            <span class="badge rounded-pill <?= $priority_class ?>"><?= htmlspecialchars(ucfirst($req['priority'] ?? 'Normal')) ?></span>
                        </div>
                        <div class="emergency-card-body">
                            <h5 class="fw-bold mb-2"><?= htmlspecialchars($req['issue_type'] ?? 'N/A') ?></h5>
                            <?php if (($req['service_type'] ?? 'onsite_repair') === 'tow_service'): ?>
                                <p class="mb-2"><span class="badge rounded-pill bg-warning text-dark"><i class="bi bi-truck me-1"></i>Tow &amp; Lift — motorcycle will be towed to the shop</span></p>
                            <?php else: ?>
                                <p class="mb-2"><span class="badge rounded-pill bg-primary"><i class="bi bi-wrench-adjustable-circle me-1"></i>Fix On-Site — repair at customer location</span></p>
                            <?php endif; ?>
                            <p class="mb-0 text-muted"><?= nl2br(htmlspecialchars($req['description'] ?? '-')) ?></p>
                        </div>
                    </div>

                    <div class="emergency-card request-info-card mt-3">
                        <div class="emergency-card-header info-header">
                            <i class="bi bi-file-text me-2"></i>Request Information
                        </div>
                        <div class="emergency-card-body">
                            <div class="row text-center g-2">
                                <div class="col-4">
                                    <p class="text-muted mb-1 small">Status</p>
                                    <span class="badge rounded-pill <?= $status_class ?>"><?= htmlspecialchars(ucfirst($req['status'])) ?></span>
                                </div>
                                <div class="col-4 info-divider">
                                    <p class="text-muted mb-1 small">Created</p>
                                    <p class="mb-0 small fw-semibold"><i class="bi bi-calendar me-1 text-muted"></i><?= $req['created_at'] ? date('M d, Y g:i A', strtotime($req['created_at'])) : '-' ?></p>
                                </div>
                                <div class="col-4 info-divider">
                                    <p class="text-muted mb-1 small">Coordinates</p>
                                    <p class="mb-0 small fw-semibold"><i class="bi bi-geo-alt me-1 text-muted"></i><?= !empty($req['latitude']) && !empty($req['longitude']) ? htmlspecialchars(round($req['latitude'], 7) . ', ' . round($req['longitude'], 7)) : 'N/A' ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="emergency-card location-card mt-3">
                        <div class="emergency-card-header location-header">
                            <i class="bi bi-geo-alt me-2"></i>Emergency Location
                        </div>
                        <div class="emergency-card-body location-body">
                            <?php if (!empty($req['latitude']) && !empty($req['longitude'])): ?>
                                <a href="<?= $maps_url ?>" target="_blank" class="btn btn-sm btn-light border view-in-maps"><i class="bi bi-box-arrow-up-right me-1"></i>View in Maps</a>
                                <div id="emergencyMap<?= $req['id'] ?>" class="emergency-map" data-lat="<?= htmlspecialchars($req['latitude']) ?>" data-lng="<?= htmlspecialchars($req['longitude']) ?>"></div>
                                <span class="badge bg-light text-dark border map-coords"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars(round($req['latitude'], 7) . ', ' . round($req['longitude'], 7)) ?></span>
                            <?php else: ?>
                                <p class="mb-0 text-muted"><i class="bi bi-geo-alt me-2"></i>No location data available.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <?php if ($req['status'] === 'assigned'): ?>
                        <form method="post" action="" class="me-auto" onsubmit="return confirm('Accept this emergency request?');">
                            <input type="hidden" name="emergency_id" value="<?= $req['id'] ?>">
                            <button type="submit" name="accept_emergency" class="btn btn-primary"><i class="bi bi-check me-2"></i>Accept Emergency</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($req['status'], ['accepted', 'in_progress'])): ?>
                        <form method="post" action="" class="me-auto" onsubmit="return confirm('Mark this emergency request as completed?');">
                            <input type="hidden" name="emergency_id" value="<?= $req['id'] ?>">
                            <button type="submit" name="mark_emergency_complete" class="btn btn-success"><i class="bi bi-check-lg me-2"></i>Mark as Complete</button>
                        </form>
                    <?php endif; ?>
                    <?php if (!empty($req['latitude']) && !empty($req['longitude'])): ?>
                        <a href="<?= $maps_url ?>" target="_blank" class="btn btn-light border"><i class="bi bi-geo-alt me-2"></i>View Location</a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php
}

$pageTitle = 'Emergency Requests';
include 'mechanic_sidebar.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
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

    .emergency-modal {
        border: none;
        border-radius: 16px;
    }
    .emergency-modal-header {
        background: #ffffff !important;
        color: #1e293b !important;
        border-bottom: none;
        position: relative;
        padding: 1rem 1.25rem;
    }
    .emergency-modal-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg,
            transparent 0%,
            rgba(245, 158, 11, 0.8) 20%,
            rgba(59, 130, 246, 0.8) 50%,
            rgba(245, 158, 11, 0.8) 80%,
            transparent 100%
        );
    }
    .emergency-modal-header .modal-title {
        font-weight: 700;
        font-size: 1.05rem;
    }
    .emergency-modal-body {
        background: #f8fafc;
        padding: 1.25rem;
    }
    .emergency-modal .modal-footer {
        background: #f8fafc;
        padding: 0 1.25rem 1.25rem;
    }

    .emergency-card {
        background: #ffffff;
        border-radius: 14px;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
        overflow: hidden;
    }
    .emergency-card-header {
        padding: 0.65rem 1rem;
        font-size: 0.85rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .emergency-card-body {
        padding: 1rem;
    }

    .customer-header {
        background: #eff6ff;
        color: #1d4ed8;
    }
    .customer-avatar {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: #dbeafe;
        color: #1d4ed8;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        flex-shrink: 0;
    }

    .motorcycle-header {
        background: #fff7ed;
        color: #c2410c;
    }
    .motorcycle-image img {
        width: 90px;
        height: 70px;
        object-fit: cover;
        border-radius: 10px;
        background: #f1f5f9;
    }
    .year-badge {
        background: #f1f5f9 !important;
        color: #475569 !important;
        border: 1px solid #e2e8f0;
    }

    .issue-card {
        border-left: 4px solid #ef4444;
    }
    .issue-header {
        background: #fef2f2;
        color: #dc2626;
    }
    .priority-medium {
        background: #fff7ed !important;
        color: #c2410c !important;
        border: 1px solid #fed7aa;
    }

    .info-header, .location-header {
        background: #eff6ff;
        color: #1d4ed8;
    }
    .info-divider {
        border-left: 1px solid #e2e8f0;
    }

    .location-body {
        position: relative;
        padding: 0;
    }
    .location-body .emergency-map {
        height: 300px;
        width: 100%;
        border-radius: 0 0 14px 14px;
        z-index: 1;
    }

    .custom-map-marker {
        position: relative;
        width: 44px !important;
        height: 44px !important;
    }
    .marker-pin {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: #2563eb;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.45);
        border: 3px solid #ffffff;
        position: absolute;
        left: 5px;
        top: 3px;
        z-index: 2;
    }
    .marker-pulse {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: rgba(37, 99, 235, 0.35);
        position: absolute;
        left: 0;
        top: 0;
        z-index: 1;
        animation: markerPulse 2s ease-out infinite;
    }
    @keyframes markerPulse {
        0% { transform: scale(0.6); opacity: 1; }
        100% { transform: scale(1.8); opacity: 0; }
    }
    .leaflet-popup-content-wrapper {
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    .mechanic-map-marker {
        position: relative;
        width: 34px !important;
        height: 34px !important;
    }
    .mechanic-marker-pin {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: #16a34a;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
        box-shadow: 0 3px 8px rgba(22, 163, 74, 0.45);
        border: 3px solid #ffffff;
        position: absolute;
        left: 2px;
        top: 2px;
        z-index: 2;
    }

    .view-in-maps {
        position: absolute;
        top: 10px;
        right: 10px;
        z-index: 500;
        background: rgba(255, 255, 255, 0.95) !important;
        border-radius: 8px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        font-size: 0.8rem;
        font-weight: 600;
    }
    .map-coords {
        position: absolute;
        bottom: 10px;
        right: 10px;
        z-index: 500;
        background: rgba(255, 255, 255, 0.95) !important;
        border-radius: 8px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        font-size: 0.75rem;
        font-weight: 600;
    }

    @media (max-width: 767.98px) {
        .emergency-card-body .row .col-4 {
            border-left: none !important;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 0.75rem;
        }
        .emergency-card-body .row .col-4:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
    }
</style>
<div class="content-area">
    <div class="container-fluid">
        <h2 class="fw-bold mb-4"><i class="bi bi-exclamation-triangle me-2 text-danger"></i>Emergency Requests</h2>

        <?php $toast_msg = $emergency_msg; $toast_type = $emergency_msg_type; include 'floating_toast.php'; ?>

        <div class="p-4">
            <h5 class="fw-bold mb-3">Assigned Emergency Requests</h5>
            <?php renderEmergencyTable($emergency_requests); ?>
        </div>
    </div>
</div>

<?php foreach ($emergency_requests as $req) { renderEmergencyModal($req); } ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        function formatDistance(meters) {
            if (meters >= 1000) return (meters / 1000).toFixed(2) + ' km';
            return Math.round(meters) + ' m';
        }
        function formatDuration(seconds) {
            if (seconds >= 3600) return Math.floor(seconds / 3600) + 'h ' + Math.round((seconds % 3600) / 60) + 'm';
            return Math.round(seconds / 60) + ' min';
        }

        function metersBetween(aLat, aLng, bLat, bLng) {
            var R = 6371000, rad = Math.PI / 180;
            var dLat = (bLat - aLat) * rad, dLng = (bLng - aLng) * rad;
            var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                    Math.cos(aLat * rad) * Math.cos(bLat * rad) *
                    Math.sin(dLng / 2) * Math.sin(dLng / 2);
            return 2 * R * Math.asin(Math.sqrt(h));
        }

        document.querySelectorAll('.emergency-map').forEach(function (mapEl) {
            var modal = mapEl.closest('.modal');
            if (!modal) return;

            var custLat = null, custLng = null, customerMarker = null;
            var tracker = { watchId: null, mechMarker: null, accCircle: null, routeLine: null, straightLine: null, lastRouted: null, lastFetch: 0, fitted: false };

            function onPosition(pos) {
                var map = mapEl._leaflet_map;
                if (!map || custLat === null) return;
                var mechLat = pos.coords.latitude;
                var mechLng = pos.coords.longitude;
                var accuracy = pos.coords.accuracy || 0;

                if (!tracker.mechMarker) {
                    var mechanicIcon = L.divIcon({
                        className: 'mechanic-map-marker',
                        html: '<div class="mechanic-marker-pin"><i class="bi bi-wrench"></i></div>',
                        iconSize: [34, 34],
                        iconAnchor: [17, 34],
                        popupAnchor: [0, -36]
                    });
                    tracker.mechMarker = L.marker([mechLat, mechLng], { icon: mechanicIcon }).addTo(map);
                    tracker.mechMarker.bindPopup('');
                    tracker.accCircle = L.circle([mechLat, mechLng], {
                        color: '#22c55e',
                        fillColor: '#22c55e',
                        fillOpacity: 0.08,
                        radius: accuracy,
                        weight: 1.5,
                        dashArray: '4, 4'
                    }).addTo(map);
                } else {
                    tracker.mechMarker.setLatLng([mechLat, mechLng]);
                    tracker.accCircle.setLatLng([mechLat, mechLng]);
                    tracker.accCircle.setRadius(accuracy);
                }
                tracker.mechMarker.setPopupContent('<div class="text-center"><strong class="d-block text-success">My Location</strong><span class="small text-muted">' + mechLat.toFixed(7) + ', ' + mechLng.toFixed(7) + '</span><br><span class="small ' + (accuracy <= 50 ? 'text-success' : 'text-warning') + '">±' + Math.round(accuracy) + ' m accuracy</span></div>');

                // Dashed straight line tracks the mechanic instantly while the road route refreshes
                if (!tracker.straightLine) {
                    tracker.straightLine = L.polyline([[mechLat, mechLng], [custLat, custLng]], {
                        color: '#3b82f6', weight: 3, opacity: 0.7, dashArray: '8, 8'
                    }).addTo(map);
                } else {
                    tracker.straightLine.setLatLngs([[mechLat, mechLng], [custLat, custLng]]);
                }

                // Re-route at most every 15s and only after moving >30m (protects the OSRM server)
                var now = Date.now();
                var movedEnough = !tracker.lastRouted || metersBetween(mechLat, mechLng, tracker.lastRouted[0], tracker.lastRouted[1]) > 30;
                if (movedEnough && now - tracker.lastFetch > 15000) {
                    tracker.lastFetch = now;
                    tracker.lastRouted = [mechLat, mechLng];
                    fetch('https://router.project-osrm.org/route/v1/driving/' + mechLng + ',' + mechLat + ';' + custLng + ',' + custLat + '?overview=full&geometries=geojson')
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            if (data.routes && data.routes.length > 0) {
                                var route = data.routes[0];
                                var routeCoords = route.geometry.coordinates.map(function (c) { return [c[1], c[0]]; });
                                if (tracker.routeLine) {
                                    tracker.routeLine.setLatLngs(routeCoords);
                                } else {
                                    tracker.routeLine = L.polyline(routeCoords, {
                                        color: '#2563eb', weight: 5, opacity: 0.85, lineCap: 'round', lineJoin: 'round'
                                    }).addTo(map);
                                }
                                map.removeLayer(tracker.straightLine);
                                customerMarker.setPopupContent('<div class="text-center"><strong class="d-block text-primary">Customer Location</strong><span class="small text-muted">' + custLat.toFixed(7) + ', ' + custLng.toFixed(7) + '</span><hr class="my-1"><span class="small text-success"><i class="bi bi-car-front me-1"></i>' + formatDistance(route.distance) + ' &bull; ' + formatDuration(route.duration) + '</span></div>');
                                if (!tracker.fitted) {
                                    map.fitBounds(tracker.routeLine.getBounds(), { padding: [50, 50], maxZoom: 16 });
                                    tracker.fitted = true;
                                }
                            } else if (!tracker.fitted) {
                                map.fitBounds(L.latLngBounds([[mechLat, mechLng], [custLat, custLng]]), { padding: [50, 50], maxZoom: 16 });
                                tracker.fitted = true;
                            }
                        })
                        .catch(function () {
                            if (!tracker.fitted) {
                                map.fitBounds(L.latLngBounds([[mechLat, mechLng], [custLat, custLng]]), { padding: [50, 50], maxZoom: 16 });
                                tracker.fitted = true;
                            }
                        });
                }
            }

            // Stop GPS watch when the modal closes (battery + privacy)
            modal.addEventListener('hidden.bs.modal', function () {
                if (tracker.watchId !== null) {
                    navigator.geolocation.clearWatch(tracker.watchId);
                    tracker.watchId = null;
                }
                tracker.fitted = false;
            });

            modal.addEventListener('shown.bs.modal', function () {
                var lat = parseFloat(mapEl.dataset.lat);
                var lng = parseFloat(mapEl.dataset.lng);
                if (isNaN(lat) || isNaN(lng)) return;
                custLat = lat;
                custLng = lng;

                if (!mapEl._leaflet_map) {
                    mapEl._leaflet_map = L.map(mapEl.id).setView([lat, lng], 15);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap contributors',
                        maxZoom: 19
                    }).addTo(mapEl._leaflet_map);

                    var customerIcon = L.divIcon({
                        className: 'custom-map-marker',
                        html: '<div class="marker-pulse"></div><div class="marker-pin"><i class="bi bi-geo-alt-fill"></i></div>',
                        iconSize: [44, 44],
                        iconAnchor: [22, 44],
                        popupAnchor: [0, -46]
                    });

                    customerMarker = L.marker([lat, lng], { icon: customerIcon, zIndexOffset: 100 }).addTo(mapEl._leaflet_map);
                    customerMarker.bindPopup('<div class="text-center"><strong class="d-block text-primary">Customer Location</strong><span class="small text-muted" id="customerCoords">' + lat.toFixed(7) + ', ' + lng.toFixed(7) + '</span></div>').openPopup();

                    L.circle([lat, lng], {
                        color: '#3b82f6',
                        fillColor: '#3b82f6',
                        fillOpacity: 0.08,
                        radius: 200,
                        weight: 2
                    }).addTo(mapEl._leaflet_map);
                }

                // (Re)start live GPS tracking each time the modal opens
                if (navigator.geolocation && tracker.watchId === null) {
                    tracker.watchId = navigator.geolocation.watchPosition(onPosition, function () {
                        // Geolocation denied or unavailable — customer pin still shows
                    }, {
                        enableHighAccuracy: true,
                        timeout: 20000,
                        maximumAge: 0
                    });
                }

                setTimeout(function () {
                    mapEl._leaflet_map.invalidateSize();
                }, 200);
            });
        });
    });
</script>
<?php include 'mechanic_sidebar_footer.php'; ?>