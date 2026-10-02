<?php
session_start();
require 'db.php';

// Security check: Only admins can access this page
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Retrieve and clear session flash messages
$msg = $_SESSION['warranty_msg'] ?? "";
$msg_type = $_SESSION['warranty_msg_type'] ?? "";
unset($_SESSION['warranty_msg'], $_SESSION['warranty_msg_type']);

// --- 1. Register New Warranty ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_warranty'])) {
    $customer_id = trim($_POST['customer_id'] ?? '');
    $motorcycle_id = trim($_POST['motorcycle_id'] ?? '');
    $warranty_type = trim($_POST['warranty_type'] ?? 'standard');
    $warranty_start = trim($_POST['warranty_start'] ?? '');
    $warranty_duration_months = trim($_POST['warranty_duration_months'] ?? '12');
    $coverage_details = trim($_POST['coverage_details'] ?? '');
    $terms_conditions = trim($_POST['terms_conditions'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Calculate warranty end date
    $warranty_end = date('Y-m-d', strtotime("+$warranty_duration_months months", strtotime($warranty_start)));

    // Validation
    if (empty($customer_id) || empty($motorcycle_id) || empty($warranty_start)) {
        $msg = "❌ Please fill in all required fields.";
        $msg_type = "error";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO warranties 
                (customer_id, motorcycle_id, warranty_type, warranty_start, warranty_end, warranty_duration_months, coverage_details, terms_conditions, notes, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([
                $customer_id, $motorcycle_id, $warranty_type, $warranty_start, $warranty_end, 
                $warranty_duration_months, $coverage_details, $terms_conditions, $notes
            ]);
            $msg = "✅ Warranty registered successfully!";
            $msg_type = "success";
        } catch (PDOException $e) {
            $msg = "❌ Error registering warranty: " . $e->getMessage();
            $msg_type = "error";
            error_log("Warranty registration error: " . $e->getMessage());
        }
    }
    $_SESSION['warranty_msg'] = $msg;
    $_SESSION['warranty_msg_type'] = $msg_type;
    header("Location: admin_warranty.php");
    exit;
}

// --- 2. Update Warranty ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_warranty'])) {
    $warranty_id = trim($_POST['warranty_id'] ?? '');
    $warranty_type = trim($_POST['warranty_type'] ?? 'standard');
    $warranty_start = trim($_POST['warranty_start'] ?? '');
    $warranty_duration_months = trim($_POST['warranty_duration_months'] ?? '12');
    $status = trim($_POST['status'] ?? 'active');
    $coverage_details = trim($_POST['coverage_details'] ?? '');
    $terms_conditions = trim($_POST['terms_conditions'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Calculate warranty end date
    $warranty_end = date('Y-m-d', strtotime("+$warranty_duration_months months", strtotime($warranty_start)));

    try {
        $stmt = $pdo->prepare("
            UPDATE warranties 
            SET warranty_type = ?, warranty_start = ?, warranty_end = ?, warranty_duration_months = ?, 
                status = ?, coverage_details = ?, terms_conditions = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([
            $warranty_type, $warranty_start, $warranty_end, $warranty_duration_months, 
            $status, $coverage_details, $terms_conditions, $notes, $warranty_id
        ]);
        $msg = "✅ Warranty updated successfully!";
        $msg_type = "success";
    } catch (PDOException $e) {
        $msg = "❌ Error updating warranty: " . $e->getMessage();
        $msg_type = "error";
        error_log("Warranty update error: " . $e->getMessage());
    }
    $_SESSION['warranty_msg'] = $msg;
    $_SESSION['warranty_msg_type'] = $msg_type;
    header("Location: admin_warranty.php");
    exit;
}

// --- 3. Record Warranty Claim ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_claim'])) {
    $warranty_id = trim($_POST['warranty_id'] ?? '');
    $claim_description = trim($_POST['claim_description'] ?? '');
    $claim_status = trim($_POST['claim_status'] ?? 'pending');
    $cost = trim($_POST['cost'] ?? '0.00');
    $resolution_details = trim($_POST['resolution_details'] ?? '');

    if (empty($warranty_id) || empty($claim_description)) {
        $msg = "❌ Please fill in all required fields.";
        $msg_type = "error";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO warranty_claims 
                (warranty_id, claim_date, claim_description, claim_status, cost, resolution_details, processed_at)
                VALUES (?, CURDATE(), ?, ?, ?, ?, 
                    CASE WHEN ? IN ('approved', 'rejected', 'completed') THEN CURRENT_TIMESTAMP ELSE NULL END)
            ");
            $stmt->execute([
                $warranty_id, $claim_description, $claim_status, $cost, $resolution_details, $claim_status
            ]);

            // Update warranty status if claim is completed
            if ($claim_status === 'completed') {
                $pdo->prepare("UPDATE warranties SET status = 'claimed' WHERE id = ?")->execute([$warranty_id]);
            }

            $msg = "✅ Warranty claim recorded successfully!";
            $msg_type = "success";
        } catch (PDOException $e) {
            $msg = "❌ Error recording claim: " . $e->getMessage();
            $msg_type = "error";
            error_log("Warranty claim error: " . $e->getMessage());
        }
    }
    $_SESSION['warranty_msg'] = $msg;
    $_SESSION['warranty_msg_type'] = $msg_type;
    header("Location: admin_warranty.php#claims-tab");
    exit;
}

// --- 4. Delete Warranty ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_warranty'])) {
    $warranty_id = trim($_POST['warranty_id'] ?? '');
    try {
        $stmt = $pdo->prepare("DELETE FROM warranties WHERE id = ?");
        $stmt->execute([$warranty_id]);
        $msg = "✅ Warranty deleted successfully!";
        $msg_type = "success";
    } catch (PDOException $e) {
        $msg = "❌ Error deleting warranty: " . $e->getMessage();
        $msg_type = "error";
        error_log("Warranty deletion error: " . $e->getMessage());
    }
    $_SESSION['warranty_msg'] = $msg;
    $_SESSION['warranty_msg_type'] = $msg_type;
    header("Location: admin_warranty.php");
    exit;
}

// --- Fetch Data for Dropdowns ---
$customers = [];
$motorcycles = [];
try {
    $customers = $pdo->query("SELECT id, username as name FROM users WHERE role='customer' ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
    $motorcycles = $pdo->query("SELECT id, user_id, brand, model, year_model FROM motorcycles ORDER BY brand ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching dropdown data: " . $e->getMessage());
}

// --- Fetch Existing Warranties ---
$warranties = [];
try {
    $stmt = $pdo->query("
        SELECT w.*,
               c.username as customer_name,
               CONCAT(m.brand, ' ', m.model, ' (', m.year_model, ')') as motorcycle_info,
               DATEDIFF(w.warranty_end, CURDATE()) as remaining_days
        FROM warranties w
        LEFT JOIN users c ON w.customer_id = c.id
        LEFT JOIN motorcycles m ON w.motorcycle_id = m.id
        WHERE w.status <> 'claimed'
        ORDER BY w.created_at DESC
    ");
    $warranties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching warranties: " . $e->getMessage());
}

// --- Fetch Warranty Claims ---
$warranty_claims = [];
try {
    $stmt = $pdo->query("
        SELECT wc.*, 
               w.warranty_type,
               c.username as customer_name,
               CONCAT(m.brand, ' ', m.model, ' (', m.year_model, ')') as motorcycle_info
        FROM warranty_claims wc
        JOIN warranties w ON wc.warranty_id = w.id
        LEFT JOIN users c ON w.customer_id = c.id
        LEFT JOIN motorcycles m ON w.motorcycle_id = m.id
        ORDER BY wc.claim_date DESC
    ");
    $warranty_claims = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching warranty claims: " . $e->getMessage());
}

$pageTitle = 'Warranty Management';
?>
<?php require 'admin_sidebar_template.php'; ?>

<style>
    .top-header {
        position: fixed !important;
        top: 0;
        left: var(--sidebar-width);
        right: 0;
        z-index: 100;
    }
    .main-content {
        padding-top: 75px !important;
        background: white !important;
    }
    @media (max-width: 991px) {
        .top-header { left: 0 !important; }
    }
    :root {
        --primary-color: #0f172a;
        --secondary-color: #64748b;
        --accent-color: #f59e0b;
        --success: #10b981;
        --danger: #ef4444;
        --warning: #f59e0b;
        --info: #3b82f6;
    }
    
    .warranty-card {
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        border: none;
        transition: all 0.3s ease;
    }
    
    .warranty-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    }
    
    .status-badge {
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
    }
    
    .status-active { background-color: #d1fae5; color: #065f46; }
    .status-expired { background-color: #fee2e2; color: #991b1b; }
    .status-claimed { background-color: #fef3c7; color: #92400e; }
    .status-cancelled { background-color: #f3f4f6; color: #374151; }
    
    .status-pending { background-color: #dbeafe; color: #1e40af; }
    .status-approved { background-color: #d1fae5; color: #065f46; }
    .status-rejected { background-color: #fee2e2; color: #991b1b; }
    .status-completed { background-color: #d1fae5; color: #065f46; }
    
    .days-remaining {
        font-weight: 700;
        font-size: 1.1rem;
    }
    
    .days-positive { color: var(--success); }
    .days-negative { color: var(--danger); }
    .days-warning { color: var(--warning); }
    
    .nav-tabs .nav-link {
        color: var(--secondary-color);
        font-weight: 500;
        border: none;
        padding: 12px 20px;
    }
    
    .nav-tabs .nav-link.active {
        background-color: var(--primary-color);
        color: white;
        border-radius: 8px;
    }
    
    .form-label {
        font-weight: 600;
        color: var(--primary-color);
    }
    
    .table-responsive {
        border-radius: 12px;
        overflow: hidden;
    }
    
    .table thead th {
        background-color: #ffffff !important;
        color: #000000 !important;
        font-weight: 700;
        border: none;
    }
    #warranties-tab .table thead th {
        color: #F97316 !important;
    }
    
    .table tbody tr:hover {
        background-color: #f8fafc;
    }
    .warranty-card-header {
        background: #ffffff !important;
        color: #000000;
        padding: 15px 20px;
        border: none;
        position: relative;
    }
    .warranty-card-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #f59e0b, #fbbf24, #f59e0b);
    }
    .warranty-card-header h4 {
        font-size: 1rem;
        font-weight: 700;
        color: #000000;
    }
    .warranty-card-header .badge {
        background: #1e293b !important;
        color: #fff !important;
        font-size: 0.75rem;
    }
    .warranty-form { font-size: 0.85rem; color: #000000; }
    .warranty-form .form-label { font-size: 0.8rem; margin-bottom: 5px; color: #000000; }
    .warranty-form .form-control,
    .warranty-form .form-select,
    .warranty-form .form-check-label { font-size: 0.85rem; color: #000000; }
    .warranty-form .form-control,
    .warranty-form .form-select { padding: 8px 12px; }
    .warranty-form .btn { font-size: 0.85rem; padding: 8px 16px; }
    .warranty-content { font-size: 0.85rem; color: #000000; }
    .warranty-content .table { font-size: 0.85rem; color: #000000; }
    .warranty-content .table th,
    .warranty-content .table td { color: #000000; }
    .warranty-content .btn { font-size: 0.8rem; }
    .warranty-content .nav-link { font-size: 0.85rem; color: #000000; }
    .claim-modal-header {
        background: #ffffff;
        color: #000000;
        padding: 15px 20px;
        border: none;
        position: relative;
    }
    .claim-modal-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #f59e0b, #fbbf24, #f59e0b);
    }
    .claim-modal-header .modal-title { color: #000000; font-weight: 700; }
    .claim-modal-header .btn-close { filter: none; }

    /* Dark mode overrides */
    html.dark-mode .warranty-card-header { background: #151f36 !important; color: #e2e8f0 !important; }
    html.dark-mode .warranty-card-header h4 { color: #e2e8f0 !important; }
    html.dark-mode .claim-modal-header { background: #151f36 !important; color: #e2e8f0 !important; }
    html.dark-mode .claim-modal-header .modal-title { color: #e2e8f0 !important; }
    html.dark-mode .table thead th { background-color: #1a2540 !important; color: #e2e8f0 !important; }
    html.dark-mode #warranties-tab .table thead th { color: #FDE047 !important; }
    html.dark-mode .table tbody tr:hover { background-color: rgba(255, 255, 255, 0.05) !important; }
    html.dark-mode .warranty-form,
    html.dark-mode .warranty-form .form-label,
    html.dark-mode .warranty-form .form-control,
    html.dark-mode .warranty-form .form-select,
    html.dark-mode .warranty-form .form-check-label,
    html.dark-mode .warranty-content,
    html.dark-mode .warranty-content .table,
    html.dark-mode .warranty-content .table th,
    html.dark-mode .warranty-content .table td,
    html.dark-mode .warranty-content .nav-link { color: #e2e8f0 !important; }
    html.dark-mode .nav-tabs .nav-link.active { background-color: #22304f !important; color: #FDE047 !important; }
    html.dark-mode .status-active { background-color: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; }
    html.dark-mode .status-expired { background-color: rgba(239, 68, 68, 0.15) !important; color: #f87171 !important; }
    html.dark-mode .status-claimed { background-color: rgba(250, 204, 21, 0.15) !important; color: #FDE047 !important; }
    html.dark-mode .status-cancelled { background-color: rgba(148, 163, 184, 0.15) !important; color: #94a3b8 !important; }
    html.dark-mode .status-pending { background-color: rgba(59, 130, 246, 0.15) !important; color: #93c5fd !important; }
    html.dark-mode .status-approved { background-color: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; }
    html.dark-mode .status-rejected { background-color: rgba(239, 68, 68, 0.15) !important; color: #f87171 !important; }
    html.dark-mode .status-completed { background-color: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; }
</style>

<div class="container-fluid">
    <div class="row g-4">
        <!-- Register New Warranty -->
        <div class="col-lg-4">
            <div class="warranty-card-header mb-2">
                <h4 class="mb-0"><i class="bi bi-shield-check me-2"></i> Register Warranty</h4>
            </div>
            <form method="POST" class="warranty-form">
                        <div class="mb-3">
                            <label class="form-label">Customer </label>
                            <select name="customer_id" id="customerSelect" class="form-select" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?= $customer['id'] ?>"><?= htmlspecialchars($customer['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Motorcycle </label>
                            <select name="motorcycle_id" id="motorcycleSelect" class="form-select" required>
                                <option value="">Select Motorcycle</option>
                                <?php foreach ($motorcycles as $motorcycle): ?>
                                    <option value="<?= $motorcycle['id'] ?>" data-user-id="<?= $motorcycle['user_id'] ?>">
                                        <?= htmlspecialchars($motorcycle['brand'] . ' ' . $motorcycle['model'] . ' (' . $motorcycle['year_model'] . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Warranty Type</label>
                            <select name="warranty_type" class="form-select">
                                <option value="standard">Standard</option>
                                <option value="extended">Extended</option>
                                <option value="premium">Premium</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date x</label>
                                <input type="date" name="warranty_start" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Duration (Months)</label>
                                <input type="number" name="warranty_duration_months" class="form-control" value="12" min="1" max="120">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Coverage Details</label>
                            <textarea name="coverage_details" class="form-control" rows="2" placeholder="What's covered under this warranty..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Terms & Conditions</label>
                            <textarea name="terms_conditions" class="form-control" rows="2" placeholder="Warranty terms and conditions..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
                        </div>
                        <button type="submit" name="register_warranty" class="btn btn-primary w-100">
                            <i class="bi bi-plus-circle me-2"></i>Register Warranty
                        </button>
                    </form>
        </div>

        <!-- Warranty List & Claims -->
        <div class="col-lg-8">
            <div class="warranty-card-header d-flex justify-content-between align-items-center mb-2">
                <h4 class="mb-0"><i class="bi bi-list-check me-2"></i> Warranty Management</h4>
                <span class="badge"><?= count($warranties) ?> Active Warranties</span>
            </div>
            <div class="warranty-content">
                    <!-- Tabs -->
                    <ul class="nav nav-tabs border-0 p-3" id="warrantyTabs" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#warranties-tab">
                                <i class="bi bi-shield-check me-1"></i> Warranties
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#claims-tab">
                                <i class="bi bi-file-earmark-text me-1"></i> Claims
                            </button>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <div class="tab-content p-3">
                        <!-- Warranties Tab -->
                        <div class="tab-pane fade show active" id="warranties-tab">
                            <?php if (empty($warranties)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-inbox fs-1 text-muted"></i>
                                    <p class="text-muted mt-3">No warranties registered yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Customer</th>
                                                <th>Motorcycle</th>
                                                <th>Type</th>
                                                <th>Period</th>
                                                <th>Remaining</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($warranties as $warranty): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($warranty['customer_name']) ?></td>
                                                    <td><?= htmlspecialchars($warranty['motorcycle_info']) ?></td>
                                                    <td>
                                                        <span class="badge bg-secondary"><?= ucfirst($warranty['warranty_type']) ?></span>
                                                    </td>
                                                    <td>
                                                        <small><?= date('M d, Y', strtotime($warranty['warranty_start'])) ?></small><br>
                                                        <small class="text-muted">to <?= date('M d, Y', strtotime($warranty['warranty_end'])) ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="days-remaining 
                                                            <?= $warranty['remaining_days'] > 30 ? 'days-positive' : 
                                                               ($warranty['remaining_days'] > 0 ? 'days-warning' : 'days-negative') ?>">
                                                            <?= $warranty['remaining_days'] ?> days
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge status-<?= $warranty['status'] ?>">
                                                            <?= ucfirst($warranty['status']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="editWarranty(<?= htmlspecialchars(json_encode($warranty)) ?>)">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-warning" 
                                                                title="Claim"
                                                                onclick="showClaimsTab(<?= $warranty['id'] ?>)">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                onclick="deleteWarranty(<?= $warranty['id'] ?>)">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Claims Tab -->
                        <div class="tab-pane fade" id="claims-tab">
                            <div class="mb-3">
                                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#claimModal">
                                    <i class="bi bi-plus-circle me-2"></i>Record New Claim
                                </button>
                            </div>
                            <?php if (empty($warranty_claims)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-file-earmark-text fs-1 text-muted"></i>
                                    <p class="text-muted mt-3">No warranty claims recorded yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Customer</th>
                                                <th>Motorcycle</th>
                                                <th>Description</th>
                                                <th>Status</th>
                                                <th>Cost</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($warranty_claims as $claim): ?>
                                                <tr>
                                                    <td><?= date('M d, Y', strtotime($claim['claim_date'])) ?></td>
                                                    <td><?= htmlspecialchars($claim['customer_name']) ?></td>
                                                    <td><?= htmlspecialchars($claim['motorcycle_info']) ?></td>
                                                    <td><?= htmlspecialchars(substr($claim['claim_description'], 0, 50)) ?>...</td>
                                                    <td>
                                                        <span class="status-badge status-<?= $claim['claim_status'] ?>">
                                                            <?= ucfirst($claim['claim_status']) ?>
                                                        </span>
                                                    </td>
                                                    <td>$<?= number_format($claim['cost'], 2) ?></td>
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
</div>

<!-- Edit Warranty Modal -->
<div class="modal fade" id="editWarrantyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Warranty</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editWarrantyForm">
                    <input type="hidden" name="warranty_id" id="edit_warranty_id">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Warranty Type</label>
                            <select name="warranty_type" id="edit_warranty_type" class="form-select">
                                <option value="standard">Standard</option>
                                <option value="extended">Extended</option>
                                <option value="premium">Premium</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="expired">Expired</option>
                                <option value="claimed">Claimed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="warranty_start" id="edit_warranty_start" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Duration (Months)</label>
                            <input type="number" name="warranty_duration_months" id="edit_warranty_duration_months" class="form-control" min="1" max="120">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Coverage Details</label>
                        <textarea name="coverage_details" id="edit_coverage_details" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Terms & Conditions</label>
                        <textarea name="terms_conditions" id="edit_terms_conditions" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
                    </div>
                    <button type="submit" name="update_warranty" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>Update Warranty
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- New Claim Modal -->
<div class="modal fade" id="claimModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header claim-modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Record Warranty Claim</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" class="warranty-form">
                    <div class="mb-3">
                        <label class="form-label">Select Warranty *</label>
                        <select name="warranty_id" id="claim_warranty_id" class="form-select" required>
                            <option value="">Select Warranty</option>
                            <?php foreach ($warranties as $warranty): ?>
                                <?php if ($warranty['status'] === 'active'): ?>
                                    <option value="<?= $warranty['id'] ?>">
                                        <?= htmlspecialchars($warranty['customer_name'] . ' - ' . $warranty['motorcycle_info']) ?>
                                        (<?= ucfirst($warranty['warranty_type']) ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Claim Description *</label>
                        <textarea name="claim_description" class="form-control" rows="4" required placeholder="Describe the warranty claim..."></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Claim Status</label>
                            <select name="claim_status" class="form-select">
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Cost ($)</label>
                            <input type="number" name="cost" class="form-control" value="0.00" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Resolution Details</label>
                        <textarea name="resolution_details" class="form-control" rows="3" placeholder="How was this claim resolved..."></textarea>
                    </div>
                    <button type="submit" name="record_claim" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>Record Claim
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this warranty? This action cannot be undone.</p>
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="warranty_id" id="delete_warranty_id">
                    <button type="submit" name="delete_warranty" class="btn btn-danger">
                        <i class="bi bi-trash me-2"></i>Delete Warranty
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require 'admin_sidebar_footer.php'; ?>

<script>
// Edit Warranty Function
function editWarranty(warranty) {
    document.getElementById('edit_warranty_id').value = warranty.id;
    document.getElementById('edit_warranty_type').value = warranty.warranty_type;
    document.getElementById('edit_status').value = warranty.status;
    document.getElementById('edit_warranty_start').value = warranty.warranty_start;
    document.getElementById('edit_warranty_duration_months').value = warranty.warranty_duration_months;
    document.getElementById('edit_coverage_details').value = warranty.coverage_details || '';
    document.getElementById('edit_terms_conditions').value = warranty.terms_conditions || '';
    document.getElementById('edit_notes').value = warranty.notes || '';
    
    new bootstrap.Modal(document.getElementById('editWarrantyModal')).show();
}

// Delete Warranty Function
function deleteWarranty(warrantyId) {
    document.getElementById('delete_warranty_id').value = warrantyId;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// Switch to Claims tab and optionally open the new-claim modal for a warranty
function showClaimsTab(warrantyId) {
    const claimsTab = document.querySelector('[data-bs-target="#claims-tab"]');
    if (claimsTab) {
        const tab = new bootstrap.Tab(claimsTab);
        tab.show();
    }

    if (warrantyId) {
        const warrantySelect = document.getElementById('claim_warranty_id');
        if (warrantySelect) {
            warrantySelect.value = warrantyId;
        }
        const claimModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('claimModal'));
        claimModal.show();
    }
}

// Show messages
<?php if ($msg): ?>
Swal.fire({
    title: '<?= $msg_type === "success" ? "Success" : "Error" ?>',
    text: '<?= $msg ?>',
    icon: '<?= $msg_type ?>',
    confirmButtonColor: '<?= $msg_type === "success" ? "#10b981" : "#ef4444" ?>'
});
<?php endif; ?>

// Filter motorcycle dropdown by selected customer
const customerSelect = document.getElementById('customerSelect');
const motorcycleSelect = document.getElementById('motorcycleSelect');
const motorcycleOptions = motorcycleSelect ? Array.from(motorcycleSelect.options) : [];

function filterMotorcycles() {
    const selectedCustomer = customerSelect ? customerSelect.value : '';
    if (!motorcycleSelect) return;
    motorcycleSelect.innerHTML = '';

    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = 'Select Motorcycle';
    motorcycleSelect.appendChild(defaultOption);

    motorcycleOptions.forEach(function(option) {
        if (!option.value) return;
        if (!selectedCustomer || option.dataset.userId === selectedCustomer) {
            motorcycleSelect.appendChild(option);
        }
    });
}

if (customerSelect) {
    customerSelect.addEventListener('change', filterMotorcycles);
}

// Auto-open the Claims tab after recording a claim (redirect fragment)
if (window.location.hash === '#claims-tab') {
    showClaimsTab();
}
</script>
