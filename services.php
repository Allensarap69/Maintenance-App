<?php
session_start();
require 'db.php'; // Ensure this file correctly sets up your $pdo connection

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$msg = "";
$required_specialties = []; // Initialize array for dropdown/checkbox data

// --- Database Setup Check: Fetch all Specialties ---
try {
    $stmt = $pdo->query("SELECT id, specialty_name FROM specialties ORDER BY specialty_name");
    $all_specialties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // This is a critical error if specialties are missing
    error_log("Error fetching specialties: " . $e->getMessage());
    $all_specialties = []; 
    $msg = "❌ Configuration Error: Could not load the list of specialties from the database.";
}


// --- 1. Handle DELETE Request (UNCHANGED) ---
if (isset($_GET['delete_id'])) {
    $delete_id = filter_input(INPUT_GET, 'delete_id', FILTER_VALIDATE_INT);
    if ($delete_id) {
        try {
            // Use transaction for safer deletion across tables
            $pdo->beginTransaction();
            
            // 1. Delete links from service_specialties
            $stmt_links = $pdo->prepare("DELETE FROM service_specialties WHERE service_id = ?");
            $stmt_links->execute([$delete_id]);
            
            // 2. Delete the service
            $stmt = $pdo->prepare("DELETE FROM services WHERE id = ?");
            $stmt->execute([$delete_id]);
            
            $pdo->commit();
            $msg = "✅ Service deleted successfully!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Service Deletion Error: " . $e->getMessage());
            $msg = "❌ Error deleting service: It might be linked to existing bookings.";
        }
    }
    header("Location: services.php?msg=" . urlencode($msg));
    exit;
}

// --- 2. Handle EDIT Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_service'])) {
    $service_id = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
    $name = filter_input(INPUT_POST, 'service_name', FILTER_SANITIZE_STRING);
    $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
    $duration_value = filter_input(INPUT_POST, 'duration_value', FILTER_VALIDATE_INT);
    $duration_label = filter_input(INPUT_POST, 'duration_label', FILTER_SANITIZE_STRING);
    $selected_specialty_ids = $_POST['specialty_ids'] ?? [];

    if ($service_id && $name && $price !== false && $duration_value !== false && $duration_label) {
        try {
            $pdo->beginTransaction();
            
            // 1. Update the service
            $stmt = $pdo->prepare("UPDATE services SET service_name = ?, price = ?, duration = ?, duration_minutes = ? WHERE id = ?");
            $stmt->execute([$name, $price, $duration_label, $duration_value, $service_id]);

            // 2. Delete existing specialty links
            $pdo->prepare("DELETE FROM service_specialties WHERE service_id = ?")->execute([$service_id]);

            // 3. Insert new specialty links
            if (!empty($selected_specialty_ids)) {
                $link_sql = "INSERT INTO service_specialties (service_id, specialty_id) VALUES (?, ?)";
                $link_stmt = $pdo->prepare($link_sql);

                foreach ($selected_specialty_ids as $specialty_id) {
                    $specialty_id = filter_var($specialty_id, FILTER_VALIDATE_INT);
                    if ($specialty_id) {
                        $link_stmt->execute([$service_id, $specialty_id]);
                    }
                }
            }

            $pdo->commit();
            $msg = "✅ Service {$name} updated successfully!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Service Edit Error: " . $e->getMessage());
            $msg = "❌ Error updating service: " . $e->getMessage();
        }
    } else {
        $msg = "❌ Invalid input provided.";
    }
}

// --- 3. Handle ADD Request (UPDATED) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_service'])) {
    $name = filter_input(INPUT_POST, 'service_name', FILTER_SANITIZE_STRING);
    $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
    $duration_value = filter_input(INPUT_POST, 'duration_value', FILTER_VALIDATE_INT);
    $duration_label = filter_input(INPUT_POST, 'duration_label', FILTER_SANITIZE_STRING);
    // NEW: Retrieve selected specialty IDs (will be an array or null)
    $selected_specialty_ids = $_POST['specialty_ids'] ?? []; 

    if ($name && $price !== false && $duration_value !== false && $duration_label) {
        try {
            $pdo->beginTransaction();
            
            // 1. Insert the new service
            $stmt = $pdo->prepare("INSERT INTO services (service_name, price, duration, duration_minutes) VALUES (?,?,?,?)");
            $stmt->execute([$name, $price, $duration_label, $duration_value]);
            $service_id = $pdo->lastInsertId(); // Get the ID of the new service

            // 2. Insert links into service_specialties
            if (!empty($selected_specialty_ids) && $service_id) {
                $link_sql = "INSERT INTO service_specialties (service_id, specialty_id) VALUES (?, ?)";
                $link_stmt = $pdo->prepare($link_sql);

                foreach ($selected_specialty_ids as $specialty_id) {
                    // Ensure the specialty ID is a valid integer before execution
                    $specialty_id = filter_var($specialty_id, FILTER_VALIDATE_INT);
                    if ($specialty_id) {
                        $link_stmt->execute([$service_id, $specialty_id]);
                    }
                }
            }

            $pdo->commit();
            $msg = "✅ Service {$name} added successfully, with " . count($selected_specialty_ids) . " skill(s) assigned!";

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Service Add Error: " . $e->getMessage());
            $msg = "❌ Error adding service: " . $e->getMessage();
        }
    } else {
           $msg = "❌ Invalid input provided.";
    }
}

// Fetch message from URL if redirected (e.g., after delete)
if (isset($_GET['msg'])) {
    $msg = urldecode($_GET['msg']);
}

// --- Fetch Service for Editing ---
$editing_service = null;
$editing_specialties = [];
if (isset($_GET['edit_id']) && !empty($_GET['edit_id'])) {
    try {
        $edit_id = filter_input(INPUT_GET, 'edit_id', FILTER_VALIDATE_INT);
        if ($edit_id) {
            $stmt = $pdo->prepare("SELECT * FROM services WHERE id = ?");
            $stmt->execute([$edit_id]);
            $editing_service = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($editing_service) {
                // Get current specialties for this service
                $spec_stmt = $pdo->prepare("
                    SELECT specialty_id 
                    FROM service_specialties 
                    WHERE service_id = ?
                ");
                $spec_stmt->execute([$editing_service['id']]);
                $editing_specialties = $spec_stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching service for editing: " . $e->getMessage());
        $msg = "❌ Error loading service for editing.";
    }
}


// --- 3. Fetch Services with Specialties for Display (UPDATED) ---

// Join services with specialties to display the required skills
$sql = "
    SELECT 
        s.id, s.service_name, s.price, s.duration, s.duration_minutes,
        GROUP_CONCAT(sp.specialty_name SEPARATOR ' | ') AS required_skills
    FROM services s
    LEFT JOIN service_specialties ss ON s.id = ss.service_id
    LEFT JOIN specialties sp ON ss.specialty_id = sp.id
    GROUP BY s.id, s.service_name, s.price, s.duration, s.duration_minutes
    ORDER BY s.id DESC
";
$services = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);


// Array of common durations (Value is in Minutes, Label is Human Readable)
$durations = [
    30 => '30 minutes',
    60 => '1 hour',
    90 => '1 hour 30 minutes',
    120 => '2 hours',
    150 => '2 hours 30 minutes',
    180 => '3 hours',
    240 => '4 hours',
    300 => '5 hours',
    360 => '6 hours',
    420 => '7 hours',
    480 => '8 hours',
];
$pageTitle = 'Manage Services';
?>

<?php require 'admin_sidebar_template.php'; ?>

<style>
    body .btn-primary {
        background: #FACC15 !important;
        border-color: #FACC15 !important;
        color: #111827 !important;
        box-shadow: none !important;
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
    .specialty-tag {
        color: #1e3a5f !important;
        font-size: 0.75rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .checkbox-label {
        font-size: 0.9rem;
        margin-bottom: 0.5rem;
        display: block;
    }

    /* Add/Edit Service Form - matches Customer & Motorcycle modal */
    .service-form-card {
        border: none;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        background: #ffffff;
        height: auto;
    }
    .service-form-header {
        background: var(--bg-light);
        color: var(--text-dark);
        padding: 15px 20px;
        border: none;
        position: relative;
    }
    .service-form-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #3b82f6, #FACC15, #3b82f6);
    }
    .service-form-title {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-dark);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .service-form-card form {
        padding: 15px;
    }
    .main-content .service-form-card .form-label {
        font-weight: 600;
        color: #1e3a5f;
        font-size: 0.8rem;
        margin-bottom: 5px;
    }
    .service-form-card .form-control,
    .service-form-card .form-select {
        border-radius: 12px;
        padding: 8px 12px;
        border: 2px solid rgba(0,0,0,0.1);
        font-size: 0.85rem;
        background-color: #f8fafc;
        transition: all 0.2s ease;
    }
    .service-form-card .form-control:focus,
    .service-form-card .form-select:focus {
        border-color: #FACC15;
        box-shadow: 0 0 0 0.2rem rgba(250,204,21,0.25);
        background-color: #ffffff;
    }
    .service-form-card .btn-primary {
        background: #FACC15 !important;
        border: 1px solid #FACC15 !important;
        border-radius: 12px;
        padding: 6px 14px;
        font-size: 0.85rem;
        font-weight: 600;
        color: #111827;
    }
    .service-form-card .btn-primary:hover {
        transform: translateY(-2px);
        background: #EAB308 !important;
        border-color: #EAB308 !important;
        box-shadow: none !important;
    }
    .service-form-card .mb-3 {
        margin-bottom: 0.6rem !important;
    }
    .service-list-card {
        height: 100%;
    }
    .service-list-card .list-group {
        max-height: calc(100vh - 240px);
        overflow-y: auto;
    }
    .service-list-card .service-item {
        opacity: 1;
    }

    /* Available Services Card List */
    .service-list {
        max-height: calc(100vh - 240px);
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    .service-list::-webkit-scrollbar { display: none; }
    .service-item {
        background: #ffffff;
        border: 1px solid rgba(0, 0, 0, 0.1);
        border-radius: 12px;
        padding: 1rem;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }
    .service-item-main { flex: 1; min-width: 220px; }
    .service-item-name {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-dark);
        margin-bottom: 0.25rem;
    }
    .service-item-meta {
        font-size: 0.78rem;
        color: var(--text-muted);
        margin-bottom: 0.3rem;
    }
    .service-item-skills {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        align-items: center;
    }
    .service-item-actions {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        flex-shrink: 0;
    }
    .service-item-actions .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.7rem;
        border-radius: 6px;
    }
    .service-item-price {
        font-size: 0.95rem;
        font-weight: 700;
        color: #10b981;
        margin-right: 0.5rem;
    }
    .service-list-empty {
        text-align: center;
        padding: 2rem;
        color: var(--text-muted);
    }

    /* Dark mode overrides */
    html.dark-mode .specialty-tag { color: #93c5fd !important; }
    html.dark-mode .service-form-header,
    html.dark-mode .service-form-title { color: #e2e8f0 !important; }
    html.dark-mode .main-content .service-form-card .form-label { color: #cbd5e1 !important; }
    html.dark-mode .service-item-name { color: #e2e8f0 !important; }
</style>

<div class="container-fluid">
    
    <?php if (!empty($msg)): ?>
        <div class="alert <?= strpos($msg, '') !== false ? 'alert-success' : 'alert-danger' ?> text-center mb-4 fw-bold">
            <?= $msg ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        
        <div class="col-md-4">
            <div class="card service-form-card">
                <div class="service-form-header">
                    <h5 class="service-form-title mb-0">
                        <i class="bi bi-<?= $editing_service ? 'pencil-square' : 'plus-circle' ?> me-2"></i>
                        <?= $editing_service ? 'Edit Service' : 'Add New Service' ?>
                    </h5>
                </div>
                
                <form method="post">
                    <?php if ($editing_service): ?>
                        <input type="hidden" name="service_id" value="<?= $editing_service['id'] ?>">
                        <input type="hidden" name="edit_service" value="1">
                    <?php else: ?>
                        <input type="hidden" name="add_service" value="1">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label for="service_name" class="form-label fw-bold">Service Name:</label>
                        <input type="text" class="form-control" id="service_name" name="service_name" required
                               value="<?= $editing_service ? htmlspecialchars($editing_service['service_name']) : '' ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label for="price" class="form-label fw-bold">Price (₱):</label>
                        <div class="input-group">
                            <span class="input-group-text">₱</span>
                            <input type="number" step="0.01" class="form-control" id="price" name="price" min="0" required
                                   value="<?= $editing_service ? htmlspecialchars($editing_service['price']) : '' ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="duration" class="form-label fw-bold">Estimated Duration:</label>
                        <select class="form-select" name="duration_value" id="duration" required>
                            <option value="" disabled selected>Select Time</option>
                            <?php foreach ($durations as $value => $label): ?>
                                <option value="<?= $value ?>" data-label="<?= $label ?>" 
                                        <?= $editing_service && $editing_service['duration_minutes'] == $value ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="duration_label" id="duration_label_hidden"
                               value="<?= $editing_service ? htmlspecialchars($editing_service['duration']) : '' ?>">
                    </div>

                    <div class="mb-4 border p-3 rounded">
                        <label class="form-label fw-bold mb-2">Required Mechanic Skills:</label>
                        <small class="text-muted d-block mb-2">Select all specialties needed to perform this service.</small>
                        <div class="row row-cols-1 g-2">
                            <?php if (empty($all_specialties)): ?>
                                <p class="text-danger">No specialties found. Please configure the `specialties` table.</p>
                            <?php else: ?>
                                <?php foreach ($all_specialties as $spec): ?>
                                    <div class="col">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" 
                                                   name="specialty_ids[]" 
                                                   value="<?= $spec['id'] ?>" 
                                                   id="spec_<?= $spec['id'] ?>"
                                                   <?= in_array($spec['id'], $editing_specialties) ? 'checked' : '' ?>>
                                            <label class="form-check-label checkbox-label" for="spec_<?= $spec['id'] ?>">
                                                <?= htmlspecialchars($spec['specialty_name']) ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <?php if ($editing_service): ?>
                            <a href="services.php" class="btn btn-secondary flex-grow-1">Cancel</a>
                            <button type="submit" class="btn btn-primary flex-grow-1 btn-lg">
                                <i class="bi bi-floppy me-2"></i> Update Service
                            </button>
                        <?php else: ?>
                            <button type="submit" class="btn btn-primary w-100 btn-lg">
                                <i class="bi bi-floppy me-2"></i> Add Service
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card card-custom p-4 service-list-card">
                <h3 class="mb-4 text-dark"><i class="bi bi-list-check me-2"></i>Available Services (<?= count($services) ?>)</h3>
                
                <div class="service-list">
                    <?php if (empty($services)): ?>
                        <div class="service-list-empty">No services have been added yet.</div>
                    <?php else: ?>
                        <?php foreach ($services as $s): ?>
                            <div class="service-item">
                                <div class="service-item-main">
                                    <div class="service-item-name"><?= htmlspecialchars($s['service_name']) ?></div>
                                    <div class="service-item-meta">
                                        <i class="bi bi-clock me-1"></i> <?= htmlspecialchars($s['duration']) ?> (<?= htmlspecialchars($s['duration_minutes'] ?? 'N/A') ?> mins)
                                    </div>
                                    <div class="service-item-skills">
                                        <?php 
                                            $skills = explode(' | ', $s['required_skills']);
                                            if (empty($skills) || empty($s['required_skills'])):
                                        ?>
                                            <span class="specialty-tag">General/Unspecified</span>
                                        <?php else: ?>
                                            <?php foreach ($skills as $skill): ?>
                                                <span class="specialty-tag"><?= htmlspecialchars(trim($skill)) ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="service-item-actions">
                                    <span class="service-item-price">₱<?= number_format($s['price'], 2) ?></span>
                                    <a href="services.php?edit_id=<?= $s['id'] ?>"
                                       class="btn btn-sm btn-outline-primary me-2">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a>
                                    <a href="services.php?delete_id=<?= $s['id'] ?>"
                                       class="btn btn-sm btn-outline-danger"
                                       onclick="return confirm('Are you sure you want to delete the service: <?= htmlspecialchars($s['service_name']) ?>? This will remove all associated skill requirements.');">
                                        <i class="bi bi-trash"></i> Delete
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Script to ensure the human-readable duration label is saved
    const durationSelect = document.getElementById('duration');
    const durationLabelHidden = document.getElementById('duration_label_hidden');

    function updateDurationLabel() {
        const selectedOption = durationSelect.options[durationSelect.selectedIndex];
        if (selectedOption.value) {
            // Get the human readable label from the data-label attribute
            durationLabelHidden.value = selectedOption.getAttribute('data-label');
        } else {
            durationLabelHidden.value = '';
        }
    }

    // Initialize on load (only if not already set from edit mode)
    if (!durationLabelHidden.value) {
        updateDurationLabel(); 
    }

    // Update on change
    durationSelect.addEventListener('change', updateDurationLabel);
</script>

<script>
    // Add animations to service list items
    document.addEventListener('DOMContentLoaded', function() {
        const items = document.querySelectorAll('.service-item');
        items.forEach((item, index) => {
            item.style.opacity = '0';
            item.style.transform = 'translateX(20px)';
            setTimeout(() => {
                item.style.transition = 'all 0.4s ease';
                item.style.opacity = '1';
                item.style.transform = 'translateX(0)';
            }, index * 100);
        });
        
        // Add subtle hover lift effect to cards (no tilt to prevent click issues)
        document.querySelectorAll('.card').forEach(card => {
            card.style.transition = 'transform 0.3s ease, box-shadow 0.3s ease';
            card.addEventListener('mouseenter', () => {
                card.style.transform = 'translateY(-5px)';
                card.style.boxShadow = '0 15px 30px rgba(0, 0, 0, 0.1)';
            });
            card.addEventListener('mouseleave', () => {
                card.style.transform = 'translateY(0)';
                card.style.boxShadow = '';
            });
        });
    });
</script>

<?php require 'admin_sidebar_footer.php'; ?>