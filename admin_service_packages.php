<?php
session_start();
require 'db.php';

// Ensure required-skills link table exists (idempotent)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS service_package_specialties (
            package_id INT NOT NULL,
            specialty_id INT NOT NULL,
            PRIMARY KEY (package_id, specialty_id),
            FOREIGN KEY (package_id) REFERENCES service_packages(id) ON DELETE CASCADE,
            FOREIGN KEY (specialty_id) REFERENCES specialties(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) {
    error_log("Error ensuring service_package_specialties table: " . $e->getMessage());
}

// Security check: Only admins can access this page
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$msg = "";
$msg_type = "";

// Fetch all available specialties for required skills selection
$all_specialties = [];
try {
    $stmt = $pdo->query("SELECT id, specialty_name FROM specialties ORDER BY specialty_name");
    $all_specialties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching specialties: " . $e->getMessage());
    $all_specialties = [];
}

// --- 1. Add New Service Package ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_package'])) {
    $package_name = trim($_POST['package_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = trim($_POST['price'] ?? '0.00');
    $duration_minutes = trim($_POST['duration_minutes'] ?? '30');
    $maintenance_interval_km = trim($_POST['maintenance_interval_km'] ?? '');
    $maintenance_interval_months = trim($_POST['maintenance_interval_months'] ?? '');
    $included_services = $_POST['included_services'] ?? [];

    // Validation
    if (empty($package_name) || empty($price)) {
        $msg = "❌ Please fill in all required fields.";
        $msg_type = "error";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO service_packages 
                (package_name, description, price, duration_minutes, maintenance_interval_km, maintenance_interval_months, status)
                VALUES (?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([
                $package_name, $description, $price, $duration_minutes, 
                $maintenance_interval_km ?: null, $maintenance_interval_months ?: null
            ]);
            
            $package_id = $pdo->lastInsertId();
            
            // Add included services
            if (!empty($included_services)) {
                foreach ($included_services as $index => $service) {
                    if (!empty(trim($service))) {
                        $item_stmt = $pdo->prepare("
                            INSERT INTO service_package_items (package_id, service_name, description, sort_order)
                            VALUES (?, ?, ?, ?)
                        ");
                        $item_stmt->execute([$package_id, trim($service), '', $index + 1]);
                    }
                }
            }

            // Add required skills/specialties
            $required_skills = $_POST['required_skills'] ?? [];
            if (!empty($required_skills)) {
                $spec_stmt = $pdo->prepare("INSERT INTO service_package_specialties (package_id, specialty_id) VALUES (?, ?)");
                foreach ($required_skills as $specialty_id) {
                    $specialty_id = filter_var($specialty_id, FILTER_VALIDATE_INT);
                    if ($specialty_id) {
                        $spec_stmt->execute([$package_id, $specialty_id]);
                    }
                }
            }
            
            $msg = "✅ Service package added successfully!";
            $msg_type = "success";
        } catch (PDOException $e) {
            $msg = "❌ Error adding service package: " . $e->getMessage();
            $msg_type = "error";
            error_log("Service package addition error: " . $e->getMessage());
        }
    }
}

// --- 2. Update Service Package ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_package'])) {
    $package_id = trim($_POST['package_id'] ?? '');
    $package_name = trim($_POST['package_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = trim($_POST['price'] ?? '0.00');
    $duration_minutes = trim($_POST['duration_minutes'] ?? '30');
    $maintenance_interval_km = trim($_POST['maintenance_interval_km'] ?? '');
    $maintenance_interval_months = trim($_POST['maintenance_interval_months'] ?? '');
    $status = trim($_POST['status'] ?? 'active');
    $included_services = $_POST['included_services'] ?? [];
    $service_descriptions = $_POST['service_descriptions'] ?? [];

    try {
        $stmt = $pdo->prepare("
            UPDATE service_packages 
            SET package_name = ?, description = ?, price = ?, duration_minutes = ?, 
                maintenance_interval_km = ?, maintenance_interval_months = ?, 
                status = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([
            $package_name, $description, $price, $duration_minutes, 
            $maintenance_interval_km ?: null, $maintenance_interval_months ?: null, 
            $status, $package_id
        ]);

        // Delete existing service items
        $pdo->prepare("DELETE FROM service_package_items WHERE package_id = ?")->execute([$package_id]);

        // Delete existing required skills
        $pdo->prepare("DELETE FROM service_package_specialties WHERE package_id = ?")->execute([$package_id]);
        
        // Add updated service items
        if (!empty($included_services)) {
            foreach ($included_services as $index => $service) {
                if (!empty(trim($service))) {
                    $item_stmt = $pdo->prepare("
                        INSERT INTO service_package_items (package_id, service_name, description, sort_order)
                        VALUES (?, ?, ?, ?)
                    ");
                    $desc = $service_descriptions[$index] ?? '';
                    $item_stmt->execute([$package_id, trim($service), trim($desc), $index + 1]);
                }
            }
        }

        // Add updated required skills/specialties
        $required_skills = $_POST['required_skills'] ?? [];
        if (!empty($required_skills)) {
            $spec_stmt = $pdo->prepare("INSERT INTO service_package_specialties (package_id, specialty_id) VALUES (?, ?)");
            foreach ($required_skills as $specialty_id) {
                $specialty_id = filter_var($specialty_id, FILTER_VALIDATE_INT);
                if ($specialty_id) {
                    $spec_stmt->execute([$package_id, $specialty_id]);
                }
            }
        }

        $msg = "✅ Service package updated successfully!";
        $msg_type = "success";
    } catch (PDOException $e) {
        $msg = "❌ Error updating service package: " . $e->getMessage();
        $msg_type = "error";
        error_log("Service package update error: " . $e->getMessage());
    }
}

// --- 3. Archive Service Package ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_package'])) {
    $package_id = trim($_POST['package_id'] ?? '');
    try {
        $stmt = $pdo->prepare("UPDATE service_packages SET status = 'archived' WHERE id = ?");
        $stmt->execute([$package_id]);
        $msg = "✅ Service package archived successfully!";
        $msg_type = "success";
    } catch (PDOException $e) {
        $msg = "❌ Error archiving service package: " . $e->getMessage();
        $msg_type = "error";
        error_log("Service package archive error: " . $e->getMessage());
    }
}

// --- 4. Delete Service Package ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_package'])) {
    $package_id = trim($_POST['package_id'] ?? '');
    try {
        // Delete service items first
        $pdo->prepare("DELETE FROM service_package_items WHERE package_id = ?")->execute([$package_id]);
        // Delete required skills
        $pdo->prepare("DELETE FROM service_package_specialties WHERE package_id = ?")->execute([$package_id]);
        // Delete package
        $pdo->prepare("DELETE FROM service_packages WHERE id = ?")->execute([$package_id]);
        $msg = "✅ Service package deleted successfully!";
        $msg_type = "success";
    } catch (PDOException $e) {
        $msg = "❌ Error deleting service package: " . $e->getMessage();
        $msg_type = "error";
        error_log("Service package deletion error: " . $e->getMessage());
    }
}

// --- Fetch Existing Service Packages ---
$service_packages = [];
try {
    $stmt = $pdo->query("
        SELECT sp.*, 
               (SELECT COUNT(*) FROM service_package_items WHERE package_id = sp.id) as service_count,
               (SELECT GROUP_CONCAT(specialty_name SEPARATOR ' | ')
                FROM service_package_specialties sps
                JOIN specialties s ON sps.specialty_id = s.id
                WHERE sps.package_id = sp.id) as required_skills
        FROM service_packages sp
        ORDER BY sp.created_at DESC
    ");
    $service_packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching service packages: " . $e->getMessage());
}

// --- Fetch Package for Editing ---
$editing_package = null;
$package_items = [];
$package_specialties = [];
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM service_packages WHERE id = ?");
        $stmt->execute([$_GET['edit']]);
        $editing_package = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($editing_package) {
            $item_stmt = $pdo->prepare("
                SELECT * FROM service_package_items 
                WHERE package_id = ? 
                ORDER BY sort_order ASC
            ");
            $item_stmt->execute([$editing_package['id']]);
            $package_items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);

            $spec_stmt = $pdo->prepare("
                SELECT specialty_id 
                FROM service_package_specialties 
                WHERE package_id = ?
            ");
            $spec_stmt->execute([$editing_package['id']]);
            $package_specialties = $spec_stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (PDOException $e) {
        error_log("Error fetching package for editing: " . $e->getMessage());
    }
}

$pageTitle = 'Service Package Management';
?>
<?php require 'admin_sidebar_template.php'; ?>

<style>
    body .btn-primary {
        background: #FACC15 !important;
        border-color: #FACC15 !important;
        color: #111827 !important;
        box-shadow: none !important;
        padding: 6px 14px;
        font-size: 0.8rem;
    }
    body .btn-primary:hover {
        background: #EAB308 !important;
        border-color: #EAB308 !important;
        color: #111827 !important;
        box-shadow: none !important;
    }
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
        --accent-color: #FACC15;
        --success: #10b981;
        --danger: #ef4444;
        --warning: #FACC15;
        --info: #3b82f6;
    }
    
    .package-card {
        border-radius: 12px;
        background: #ffffff;
        border: 1px solid rgba(0, 0, 0, 0.1);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        transition: all 0.3s ease;
    }
    
    .package-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
    }
    
    .package-card .card-body {
        padding: 1rem;
        font-size: 0.85rem;
        color: #000000;
    }
    
    .package-card .card-title {
        font-size: 1rem;
        font-weight: 700;
        color: #000000;
        margin-bottom: 0.25rem;
    }
    
    .status-badge {
        padding: 3px 8px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
    }
    
    .status-active {
        background-color: rgba(16, 185, 129, 0.1);
        color: #10b981;
    }
    
    .status-archived {
        background-color: rgba(100, 116, 139, 0.1);
        color: #64748b;
    }
    
    .price-tag {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--accent-color);
    }
    
    .package-card .service-item {
        background: rgba(15, 23, 42, 0.03);
        border-left: 3px solid var(--accent-color);
        padding: 6px 10px;
        margin-bottom: 6px;
        border-radius: 0 8px 8px 0;
        font-size: 0.8rem;
    }
    
    .package-card small, .package-card .text-muted {
        font-size: 0.8rem;
        color: #000000 !important;
        font-weight: 700 !important;
    }
    
    .package-card .dropdown-toggle {
        padding: 2px 6px;
    }
    
    .action-btn {
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 0.85rem;
        transition: all 0.2s ease;
    }
    
    .modal-header {
        background: #ffffff;
        color: #000000;
        border-radius: 12px 12px 0 0;
        position: relative;
        border: none;
    }
    .modal-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #3b82f6, #FACC15, #3b82f6);
    }
    .modal-title {
        color: #000000;
        font-weight: 700;
    }
    .modal-header .btn-close { filter: none; }
    .package-form { font-size: 0.85rem; color: #000000; }
    .package-form .form-label { font-size: 0.8rem; margin-bottom: 5px; color: #000000; }
    .package-form .form-control,
    .package-form .form-select,
    .package-form .form-check-label { font-size: 0.85rem; color: #000000; }
    .package-form .form-control,
    .package-form .form-select { padding: 8px 12px; }
    .package-form .btn { font-size: 0.85rem; }
    .package-form small { font-size: 0.75rem; }
    
    .form-control:focus, .form-select:focus {
        border-color: var(--accent-color);
        box-shadow: 0 0 0 0.2rem rgba(250, 204, 21, 0.25);
    }
    
    .included-service-item {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 12px;
        margin-bottom: 10px;
    }

    /* Dark mode overrides */
    html.dark-mode .package-card .card-body,
    html.dark-mode .package-card .card-title,
    html.dark-mode .package-card small,
    html.dark-mode .package-card .text-muted { color: #e2e8f0 !important; }
    html.dark-mode .package-card .service-item { background: rgba(255, 255, 255, 0.04) !important; }
    html.dark-mode .status-active { background-color: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; }
    html.dark-mode .status-archived { background-color: rgba(148, 163, 184, 0.15) !important; color: #94a3b8 !important; }
    html.dark-mode .package-form,
    html.dark-mode .package-form .form-label,
    html.dark-mode .package-form .form-control,
    html.dark-mode .package-form .form-select,
    html.dark-mode .package-form .form-check-label { color: #e2e8f0 !important; }
    html.dark-mode .included-service-item { border-color: rgba(255, 255, 255, 0.09) !important; }
</style>

<div>
    <div class="bg-animation">
        <div class="circle"></div>
        <div class="circle"></div>
        <div class="circle"></div>
    </div>
    
    <div class="content-wrapper">
        <div class="container-fluid">
            <div class="d-flex justify-content-end mb-4">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPackageModal">
                    <i class="bi bi-plus-lg me-2"></i>Add Service Package
                </button>
            </div>

            <!-- Message Display -->
            <?php if (!empty($msg)): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="alert alert-<?= $msg_type === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                        <?= $msg ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Service Packages Grid -->
            <div class="row">
                <?php if (empty($service_packages)): ?>
                    <div class="col-12">
                        <div class="card card-glass">
                            <div class="card-body text-center py-5">
                                <i class="bi bi-box-seam display-1 text-muted mb-3"></i>
                                <h4>No Service Packages Found</h4>
                                <p class="text-muted">Start by adding your first service package</p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPackageModal">
                                    <i class="bi bi-plus-lg me-2"></i>Add Service Package
                                </button>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($service_packages as $package): ?>
                        <?php
                        // Get package items
                        $items = [];
                        try {
                            $item_stmt = $pdo->prepare("
                                SELECT * FROM service_package_items 
                                WHERE package_id = ? 
                                ORDER BY sort_order ASC
                            ");
                            $item_stmt->execute([$package['id']]);
                            $items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (PDOException $e) {
                            error_log("Error fetching package items: " . $e->getMessage());
                        }
                        ?>
                        <div class="col-xl-4 col-lg-6 col-md-12 mb-4">
                            <div class="card package-card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h5 class="card-title mb-1"><?= htmlspecialchars($package['package_name']) ?></h5>
                                            <span class="status-badge status-<?= $package['status'] ?>">
                                                <?= ucfirst($package['status']) ?>
                                            </span>
                                        </div>
                                        <div class="dropdown">
                                            <button class="btn btn-light btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li>
                                                    <a class="dropdown-item" href="?edit=<?= $package['id'] ?>">
                                                        <i class="bi bi-pencil me-2"></i>Edit
                                                    </a>
                                                </li>
                                                <?php if ($package['status'] === 'active'): ?>
                                                    <li>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="package_id" value="<?= $package['id'] ?>">
                                                            <input type="hidden" name="archive_package" value="1">
                                                            <button type="submit" class="dropdown-item text-warning">
                                                                <i class="bi bi-archive me-2"></i>Archive
                                                            </button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>
                                                <li>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this package?');">
                                                        <input type="hidden" name="package_id" value="<?= $package['id'] ?>">
                                                        <input type="hidden" name="delete_package" value="1">
                                                        <button type="submit" class="dropdown-item text-danger">
                                                            <i class="bi bi-trash me-2"></i>Delete
                                                        </button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($package['description'])): ?>
                                        <p class="text-muted small mb-3"><?= htmlspecialchars($package['description']) ?></p>
                                    <?php endif; ?>
                                    
                                    <div class="price-tag mb-3">₱<?= number_format($package['price'], 2) ?></div>
                                    
                                    <div class="mb-3">
                                        <small class="text-muted">
                                            <i class="bi bi-clock me-1"></i><?= $package['duration_minutes'] ?> minutes
                                        </small>
                                        <?php if ($package['maintenance_interval_km']): ?>
                                            <span class="mx-2">|</span>
                                            <small class="text-muted">
                                                <i class="bi bi-speedometer2 me-1"></i><?= $package['maintenance_interval_km'] ?> KM
                                            </small>
                                        <?php endif; ?>
                                        <?php if ($package['maintenance_interval_months']): ?>
                                            <span class="mx-2">|</span>
                                            <small class="text-muted">
                                                <i class="bi bi-calendar me-1"></i><?= $package['maintenance_interval_months'] ?> months
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (!empty($items)): ?>
                                        <div class="mt-3">
                                            <small class="text-muted fw-bold">Includes (<?= count($items) ?> services):</small>
                                            <?php foreach ($items as $item): ?>
                                        <div class="service-item small">
                                            <i class="bi bi-check-circle-fill text-success me-1"></i>
                                            <?= htmlspecialchars($item['service_name']) ?>
                                        </div>
                                    <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($package['required_skills'])): ?>
                                        <div class="mt-3">
                                            <small class="text-muted fw-bold">Required Skills:</small>
                                            <div class="small">
                                                <?= htmlspecialchars($package['required_skills']) ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Package Modal -->
<div class="modal fade" id="addPackageModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Add Service Package</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" class="package-form">
                <div class="modal-body">
                    <input type="hidden" name="add_package" value="1">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Package Name *</label>
                            <input type="text" class="form-control" name="package_name" required placeholder="e.g., 1000 KM Preventive Maintenance">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Price (₱) *</label>
                            <input type="number" class="form-control" name="price" step="0.01" required placeholder="950.00">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="2" placeholder="Brief description of the service package"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Duration (minutes) *</label>
                            <input type="number" class="form-control" name="duration_minutes" required value="30">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Maintenance Interval (KM)</label>
                            <input type="number" class="form-control" name="maintenance_interval_km" placeholder="1000">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Maintenance Interval (months)</label>
                            <input type="number" class="form-control" name="maintenance_interval_months" placeholder="3">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Included Services</label>
                        <div id="includedServices">
                            <div class="included-service-item">
                                <div class="row">
                                    <div class="col-md-6">
                                        <input type="text" class="form-control mb-2" name="included_services[]" placeholder="Service name (e.g., Oil change)">
                                    </div>
                                    <div class="col-md-6">
                                        <input type="text" class="form-control mb-2" name="service_descriptions[]" placeholder="Description (optional)">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addServiceField()">
                            <i class="bi bi-plus me-1"></i>Add Service
                        </button>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Required Skills / Specialties</label>
                        <small class="text-muted d-block mb-2">Select the specialties a mechanic must have to perform this package.</small>
                        <?php if (empty($all_specialties)): ?>
                            <p class="text-danger">No specialties found. Please configure the specialties table.</p>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($all_specialties as $spec): ?>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="required_skills[]" value="<?= $spec['id'] ?>" id="add_spec_<?= $spec['id'] ?>">
                                            <label class="form-check-label" for="add_spec_<?= $spec['id'] ?>">
                                                <?= htmlspecialchars($spec['specialty_name']) ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Package</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Package Modal -->
<?php if ($editing_package): ?>
<div class="modal fade" id="editPackageModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Service Package</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" class="package-form">
                <div class="modal-body">
                    <input type="hidden" name="update_package" value="1">
                    <input type="hidden" name="package_id" value="<?= $editing_package['id'] ?>">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Package Name *</label>
                            <input type="text" class="form-control" name="package_name" required value="<?= htmlspecialchars($editing_package['package_name']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Price (₱) *</label>
                            <input type="number" class="form-control" name="price" step="0.01" required value="<?= $editing_package['price'] ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="2"><?= htmlspecialchars($editing_package['description']) ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Duration (minutes) *</label>
                            <input type="number" class="form-control" name="duration_minutes" required value="<?= $editing_package['duration_minutes'] ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Maintenance Interval (KM)</label>
                            <input type="number" class="form-control" name="maintenance_interval_km" value="<?= $editing_package['maintenance_interval_km'] ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Maintenance Interval (months)</label>
                            <input type="number" class="form-control" name="maintenance_interval_months" value="<?= $editing_package['maintenance_interval_months'] ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="active" <?= $editing_package['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="archived" <?= $editing_package['status'] === 'archived' ? 'selected' : '' ?>>Archived</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Included Services</label>
                        <div id="editIncludedServices">
                            <?php foreach ($package_items as $index => $item): ?>
                                <div class="included-service-item">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <input type="text" class="form-control mb-2" name="included_services[]" value="<?= htmlspecialchars($item['service_name']) ?>" placeholder="Service name">
                                        </div>
                                        <div class="col-md-6">
                                            <input type="text" class="form-control mb-2" name="service_descriptions[]" value="<?= htmlspecialchars($item['description']) ?>" placeholder="Description (optional)">
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addEditServiceField()">
                            <i class="bi bi-plus me-1"></i>Add Service
                        </button>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Required Skills / Specialties</label>
                        <small class="text-muted d-block mb-2">Select the specialties a mechanic must have to perform this package.</small>
                        <?php if (empty($all_specialties)): ?>
                            <p class="text-danger">No specialties found. Please configure the specialties table.</p>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($all_specialties as $spec): ?>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="required_skills[]" value="<?= $spec['id'] ?>" id="edit_spec_<?= $spec['id'] ?>" <?= in_array($spec['id'], $package_specialties) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="edit_spec_<?= $spec['id'] ?>">
                                                <?= htmlspecialchars($spec['specialty_name']) ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="admin_service_packages.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Update Package</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Auto-show edit modal if editing
    document.addEventListener('DOMContentLoaded', function() {
        var editModal = new bootstrap.Modal(document.getElementById('editPackageModal'));
        editModal.show();
    });
</script>
<?php endif; ?>

<script>
    function addServiceField() {
        const container = document.getElementById('includedServices');
        const newField = document.createElement('div');
        newField.className = 'included-service-item';
        newField.innerHTML = `
            <div class="row">
                <div class="col-md-6">
                    <input type="text" class="form-control mb-2" name="included_services[]" placeholder="Service name (e.g., Oil change)">
                </div>
                <div class="col-md-6">
                    <input type="text" class="form-control mb-2" name="service_descriptions[]" placeholder="Description (optional)">
                </div>
            </div>
        `;
        container.appendChild(newField);
    }

    function addEditServiceField() {
        const container = document.getElementById('editIncludedServices');
        const newField = document.createElement('div');
        newField.className = 'included-service-item';
        newField.innerHTML = `
            <div class="row">
                <div class="col-md-6">
                    <input type="text" class="form-control mb-2" name="included_services[]" placeholder="Service name">
                </div>
                <div class="col-md-6">
                    <input type="text" class="form-control mb-2" name="service_descriptions[]" placeholder="Description (optional)">
                </div>
            </div>
        `;
        container.appendChild(newField);
    }
</script>

<?php require 'admin_sidebar_footer.php'; ?>