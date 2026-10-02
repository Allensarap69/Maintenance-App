<?php
session_start();
// Check if the user is logged in as Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// --- INITIALIZE VARIABLES TO AVOID UNDEFINED WARNINGS ---
$msg = "";
$msg_type = "";
$motorcycles = [];
$current_status = $_GET['status'] ?? 'All';

// Ensure database connection is attempted AFTER session check
require 'db.php';

// Ensure image column exists for per-motorcycle photos
try {
    $pdo->exec("ALTER TABLE motorcycles ADD COLUMN IF NOT EXISTS image VARCHAR(255) NULL");
} catch (PDOException $e) {
    error_log("Motorcycles image column check failed: " . $e->getMessage());
}

// Handle a motorcycle image upload; returns stored path or null
function handleMotoImageUpload() {
    if (!isset($_FILES['moto_image']) || $_FILES['moto_image']['error'] !== UPLOAD_ERR_OK) return null;
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($_FILES['moto_image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed) || $_FILES['moto_image']['size'] > 5 * 1024 * 1024) return null;
    $upload_dir = 'uploads/motorcycles/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    $file_name = 'moto_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    return move_uploaded_file($_FILES['moto_image']['tmp_name'], $upload_dir . $file_name) ? $upload_dir . $file_name : null;
}

// --- Status List for Buttons and Filtering ---
$status_list = [
    'All' => 'All Motorcycles',
    'active' => 'Active',
    'archived' => 'Archived',
];

// --- Search functionality ---
$search = $_GET['search'] ?? '';

// --- 1. Logic for Creating a Motorcycle ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_motorcycle'])) {
    $user_id = $_POST['user_id'] ?? 0;
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $year_model = $_POST['year_model'] ?? 0;
    $plate_number = trim($_POST['plate_number'] ?? '');
    $engine_number = trim($_POST['engine_number'] ?? '');
    $chassis_number = trim($_POST['chassis_number'] ?? '');
    $purchase_date = $_POST['purchase_date'] ?? '';
    $current_mileage = $_POST['current_mileage'] ?? 0;

    if (empty($user_id) || empty($brand) || empty($model) || empty($color) || empty($year_model) || empty($plate_number) || empty($engine_number) || empty($chassis_number) || empty($purchase_date)) {
        $msg = "❌ Please fill in all required fields.";
        $msg_type = "error";
    } else {
        try {
            // Check if plate number is already registered
            $check = $pdo->prepare("SELECT id FROM motorcycles WHERE plate_number=?");
            $check->execute([$plate_number]);
            
            if ($check->rowCount() > 0) {
                $msg = "❌ Plate number already registered. Please use a different plate number.";
                $msg_type = "error";
            } else {
                $image = handleMotoImageUpload();
                $stmt = $pdo->prepare("INSERT INTO motorcycles (user_id, brand, model, color, year_model, plate_number, engine_number, chassis_number, purchase_date, current_mileage, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $brand, $model, $color, $year_model, $plate_number, $engine_number, $chassis_number, $purchase_date, $current_mileage, $image]);
                
                $msg = "✅ Motorcycle registered successfully!";
                $msg_type = "success";
            }
        } catch (PDOException $e) {
            error_log("Motorcycle Creation Error: " . $e->getMessage());
            $msg = "❌ Database Error during creation: " . $e->getMessage();
            $msg_type = "error";
        }
    }
}
// --- END Creation Logic ---

// --- 2. Logic for Editing a Motorcycle ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_motorcycle'])) {
    $motorcycle_id = $_POST['motorcycle_id'] ?? 0;
    $user_id = $_POST['user_id'] ?? 0;
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $year_model = $_POST['year_model'] ?? 0;
    $plate_number = trim($_POST['plate_number'] ?? '');
    $engine_number = trim($_POST['engine_number'] ?? '');
    $chassis_number = trim($_POST['chassis_number'] ?? '');
    $purchase_date = $_POST['purchase_date'] ?? '';
    $current_mileage = $_POST['current_mileage'] ?? 0;

    if (empty($user_id) || empty($brand) || empty($model) || empty($color) || empty($year_model) || empty($plate_number) || empty($engine_number) || empty($chassis_number) || empty($purchase_date)) {
        $msg = "❌ Please fill in all required fields.";
        $msg_type = "error";
    } else {
        try {
            $image = handleMotoImageUpload();
            if ($image) {
                $stmt = $pdo->prepare("UPDATE motorcycles SET user_id=?, brand=?, model=?, color=?, year_model=?, plate_number=?, engine_number=?, chassis_number=?, purchase_date=?, current_mileage=?, image=? WHERE id=?");
                $stmt->execute([$user_id, $brand, $model, $color, $year_model, $plate_number, $engine_number, $chassis_number, $purchase_date, $current_mileage, $image, $motorcycle_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE motorcycles SET user_id=?, brand=?, model=?, color=?, year_model=?, plate_number=?, engine_number=?, chassis_number=?, purchase_date=?, current_mileage=? WHERE id=?");
                $stmt->execute([$user_id, $brand, $model, $color, $year_model, $plate_number, $engine_number, $chassis_number, $purchase_date, $current_mileage, $motorcycle_id]);
            }

            $check = $pdo->prepare("SELECT id FROM motorcycles WHERE id=?");
            $check->execute([$motorcycle_id]);
            if ($check->rowCount()) {
                $msg = "✅ Motorcycle information updated successfully!";
                $msg_type = "success";
            } else {
                $msg = "⚠️ Update failed. Motorcycle may not exist.";
                $msg_type = "warning";
            }
        } catch (PDOException $e) {
            error_log("Motorcycle Edit Error: " . $e->getMessage());
            $msg = "❌ Database Error during update: " . $e->getMessage();
            $msg_type = "error";
        }
    }
}
// --- END Edit Logic ---

// --- 3. Logic for Deleting a Motorcycle ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_motorcycle'])) {
    $motorcycle_id = $_POST['motorcycle_id'] ?? 0;

    try {
        // Check if motorcycle has associated bookings
        $check_bookings = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE vehicle_id = ? AND status IN ('pending', 'accepted', 'in_progress', 'deposit_submitted')");
        $check_bookings->execute([$motorcycle_id]);
        
        if ($check_bookings->fetchColumn() > 0) {
            $msg = "❌ Cannot delete motorcycle. It still has active bookings that need to be completed or cancelled.";
            $msg_type = "error";
        } else {
            $stmt = $pdo->prepare("DELETE FROM motorcycles WHERE id = ?");
            $stmt->execute([$motorcycle_id]);
            
            if ($stmt->rowCount()) {
                $msg = "✅ Motorcycle record successfully deleted!";
                $msg_type = "success";
            } else {
                $msg = "⚠️ Deletion failed. Motorcycle may not exist.";
                $msg_type = "warning";
            }
        }
    } catch (PDOException $e) {
        error_log("Motorcycle Deletion Error: " . $e->getMessage());
        $msg = "❌ Database Error during deletion: " . $e->getMessage();
        $msg_type = "error";
    }
}
// --- END Deletion Logic ---

// --- 4. Logic for Archiving a Motorcycle ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_motorcycle'])) {
    $motorcycle_id = $_POST['motorcycle_id'] ?? 0;
    $archive = $_POST['archive'] ?? 0;

    try {
        $stmt = $pdo->prepare("UPDATE motorcycles SET status = ? WHERE id = ?");
        $stmt->execute([$archive ? 'archived' : 'active', $motorcycle_id]);
        
        if ($stmt->rowCount()) {
            $msg = $archive ? "✅ Motorcycle archived successfully!" : "✅ Motorcycle unarchived successfully!";
            $msg_type = "success";
        } else {
            $msg = "⚠️ Archive operation failed. Motorcycle may not exist.";
            $msg_type = "warning";
        }
    } catch (PDOException $e) {
        error_log("Motorcycle Archive Error: " . $e->getMessage());
        $msg = "❌ Database Error during archive: " . $e->getMessage();
        $msg_type = "error";
    }
}
// --- END Archive Logic ---

// --- 5. Logic for Updating Mileage ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_mileage'])) {
    $motorcycle_id = $_POST['motorcycle_id'] ?? 0;
    $new_mileage = $_POST['new_mileage'] ?? 0;

    try {
        $stmt = $pdo->prepare("UPDATE motorcycles SET current_mileage = ? WHERE id = ?");
        $stmt->execute([$new_mileage, $motorcycle_id]);
        
        if ($stmt->rowCount()) {
            $msg = "✅ Mileage updated successfully!";
            $msg_type = "success";
        } else {
            $msg = "⚠️ Mileage update failed. Motorcycle may not exist.";
            $msg_type = "warning";
        }
    } catch (PDOException $e) {
        error_log("Mileage Update Error: " . $e->getMessage());
        $msg = "❌ Database Error during mileage update: " . $e->getMessage();
        $msg_type = "error";
    }
}
// --- END Mileage Update Logic ---

// --- Redirect after POST so a page refresh does not resubmit or re-show the alert ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($msg)) {
    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_type'] = $msg_type;
    $params = [];
    if ($current_status !== 'All') $params['status'] = $current_status;
    if (!empty($search)) $params['search'] = $search;
    header("Location: manage_motorcycles.php" . ($params ? '?' . http_build_query($params) : ''));
    exit;
}

// --- Flash message from previous POST redirect ---
if (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msg_type = $_SESSION['flash_type'] ?? '';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// --- Fetch Customers for Dropdown ---
$customers = [];
try {
    $stmt = $pdo->query("SELECT id, username as name, email FROM users WHERE role = 'customer' AND archived = 0 ORDER BY username ASC");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching customers: " . $e->getMessage());
}

// --- Build Query for Displaying Motorcycles ---
try {
    $query = "SELECT m.*, u.username as customer_name, u.email as customer_email 
              FROM motorcycles m 
              JOIN users u ON m.user_id = u.id 
              WHERE 1=1";
    
    // Apply status filter
    if ($current_status !== 'All') {
        $query .= " AND m.status = :status";
    }
    
    // Apply search filter
    if (!empty($search)) {
        $query .= " AND (m.brand LIKE :search OR m.model LIKE :search OR m.plate_number LIKE :search OR u.username LIKE :search)";
    }
    
    $query .= " ORDER BY m.brand ASC, m.model ASC";
    
    $stmt = $pdo->prepare($query);
    
    if ($current_status !== 'All') {
        $stmt->bindValue(':status', $current_status);
    }
    
    if (!empty($search)) {
        $searchParam = "%$search%";
        $stmt->bindValue(':search', $searchParam);
    }
    
    $stmt->execute();
    $motorcycles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching motorcycles: " . $e->getMessage());
    $motorcycles = [];
}

$pageTitle = "Motorcycle Management";
require 'admin_sidebar_template.php';
?>

<style>
    .top-header {
        position: fixed !important;
        top: 0;
        left: var(--sidebar-width);
        right: 0;
        z-index: 100;
    }
    .main-content {
        padding-top: 90px !important;
        background: #f8fafc !important;
        color: var(--text-dark);
        display: flex;
        flex-direction: column;
        height: 100vh;
        overflow: hidden;
    }
    .bg-animation,
    .floating-tools {
        display: none !important;
    }
    @media (max-width: 991px) {
        .top-header { left: 0 !important; }
    }

    /* Status Filter Pills */
    .status-pill {
        border-radius: 50px !important;
        padding: 8px 20px !important;
        font-size: 0.8rem !important;
        font-weight: 600 !important;
        text-transform: capitalize;
        transition: box-shadow 0.2s ease;
        background: transparent !important;
        color: var(--text-dark) !important;
        border: 1px solid var(--card-border) !important;
        box-shadow: none !important;
    }
    .status-pill:hover,
    .status-pill:focus,
    .status-pill:active {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1) !important;
        background: transparent !important;
        color: var(--text-dark) !important;
        border-color: var(--card-border) !important;
        transform: none;
    }
    .status-pill.btn-outline-primary,
    .status-pill.btn-outline-primary:hover,
    .status-pill.btn-outline-primary:focus,
    .status-pill.btn-outline-primary:active {
        background: #FACC15 !important;
        border: 2px solid #FACC15 !important;
        color: #111827 !important;
        box-shadow: none !important;
    }

    /* Enhanced Modal Styling */
    .modal-content {
        border-radius: 20px;
        border: none;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        overflow: hidden;
    }
    .modal-header {
        background: var(--bg-light);
        color: var(--text-dark);
        padding: 20px 25px;
        border: none;
        position: relative;
    }
    .modal-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg,
            transparent 0%,
            rgba(250, 204, 21, 0.8) 20%,
            rgba(59, 130, 246, 0.8) 50%,
            rgba(250, 204, 21, 0.8) 80%,
            transparent 100%
        );
    }
    .modal-title {
        font-weight: 600;
        font-size: 1.2rem;
    }
    .modal-body {
        padding: 25px;
        max-height: 70vh;
        overflow-y: auto;
    }
    .modal-footer {
        padding: 20px 25px;
        border-top: 1px solid rgba(0, 0, 0, 0.1);
    }
    body .modal .modal-dialog { max-width: 500px; }
    .modal-header { padding: 12px 18px; }
    .modal-body { padding: 15px; max-height: 70vh; }
    .modal-footer { padding: 12px 18px; }
    .modal-title { font-size: 1rem; }
    body .modal-footer .btn-primary,
    body .modal-footer .btn-secondary { padding: 6px 14px; font-size: 0.8rem; border-radius: 8px; }
    .form-control, .form-select {
        border: 2px solid rgba(0, 0, 0, 0.1);
        border-radius: 12px;
        padding: 12px 16px;
        font-size: 0.9rem;
        transition: all 0.3s ease;
    }
    .form-control:focus, .form-select:focus {
        border-color: #1e293b;
        box-shadow: 0 0 0 4px rgba(30, 41, 59, 0.1);
    }
    body .modal .form-control,
    body .modal .form-select {
        padding: 8px 12px;
        font-size: 0.85rem;
    }
    .form-label {
        font-weight: 600;
        font-size: 0.85rem;
        color: var(--text-dark);
        margin-bottom: 8px;
    }
    body .modal .form-label { font-size: 0.78rem; margin-bottom: 4px; }
    body .btn-primary {
        background: #FACC15 !important;
        border-color: #FACC15 !important;
        color: #111827 !important;
        padding: 10px 24px;
        border-radius: 12px;
        font-weight: 600;
        transition: all 0.3s ease;
        box-shadow: none !important;
    }
    body .btn-primary:hover {
        transform: translateY(-2px);
        background: #EAB308 !important;
        border-color: #EAB308 !important;
        color: #111827 !important;
        box-shadow: none !important;
    }
    .search-input {
        padding: 6px 12px !important;
        font-size: 0.85rem !important;
        min-height: 38px;
        border-color: #E5E7EB !important;
    }
    .search-input:focus {
        border-color: #3b82f6 !important;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2) !important;
    }
    body .search-btn,
    body .btn-primary.search-btn {
        padding: 6px 12px !important;
        font-size: 0.85rem !important;
        background: #FACC15 !important;
        border-color: #FACC15 !important;
        color: #111827 !important;
        box-shadow: none !important;
    }
    body .search-btn:hover,
    body .btn-primary.search-btn:hover {
        background: #EAB308 !important;
        border-color: #EAB308 !important;
    }
    body .btn-add-motorcycle,
    body .btn-primary.btn-add-motorcycle {
        background: #FACC15 !important;
        border: 1px solid #FACC15 !important;
        color: #111827 !important;
        padding: 8px 20px !important;
        font-size: 0.8rem !important;
        border-radius: 50px;
        font-weight: 600 !important;
        box-shadow: none !important;
    }
    body .btn-add-motorcycle:hover,
    body .btn-primary.btn-add-motorcycle:hover {
        background: #EAB308 !important;
        border-color: #EAB308 !important;
        box-shadow: none !important;
    }
    .btn-secondary {
        border-radius: 12px;
        padding: 10px 24px;
        font-weight: 500;
    }
    hr {
        border-color: rgba(0, 0, 0, 0.1);
        margin: 20px 0;
    }
    h6 {
        font-weight: 700;
        color: var(--text-dark);
        font-size: 1rem;
    }

    /* Motorcycle Cards */
    .moto-card {
        background: #ffffff;
        border: 1px solid var(--card-border);
        border-radius: 8px;
        padding: 0.5rem;
        color: var(--text-dark);
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        transition: box-shadow 0.2s ease;
    }
    .moto-card:hover {
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
    }
    .moto-card h5 {
        font-weight: 700;
        color: var(--text-dark);
        font-size: 0.95rem;
    }
    .moto-card .text-muted {
        color: var(--text-muted) !important;
    }
    .moto-card .badge {
        font-size: 0.75rem;
        font-weight: 600;
    }

    /* Action Buttons */
    .action-group { display: inline-flex; align-items: center; gap: 0.6rem; }
    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: auto;
        height: auto;
        border: none;
        font-size: 1rem;
        background: transparent;
        cursor: pointer;
        transition: all 0.2s ease;
        padding: 0;
    }
    .action-btn { color: #FACC15; }
    .action-btn:hover { color: #EAB308; }

    /* View/Edit Card Wrapper */
    .vc-card {
        background: #ffffff;
        border: 1px solid var(--card-border);
        border-radius: 8px;
        padding: 0.35rem;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
    }
    .vc-section-title {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.72rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--text-muted);
        margin-bottom: 0.75rem;
    }
    .moto-card hr { margin: 0.5rem 0; }
    .moto-card .mb-3 { margin-bottom: 0.5rem !important; }
    .moto-card p { margin-bottom: 0.25rem; }
    .moto-card .row.g-2 { --bs-gutter-y: 0.35rem; }
    #motorcyclesGrid { align-items: start; }

    /* List view */
    #motoListSection {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }
    .moto-total {
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-muted);
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .moto-list {
        background: transparent;
        border: 1px solid var(--card-border);
        border-radius: 12px;
        overflow-x: hidden;
        overflow-y: auto;
        flex: 1;
        min-height: 0;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    .moto-list::-webkit-scrollbar {
        width: 0;
        background: transparent;
    }
    .moto-list-header,
    .moto-row {
        display: grid;
        grid-template-columns: 2fr 1.8fr 1fr 0.8fr 1fr 0.9fr 1.4fr;
        align-items: center;
        gap: 0.75rem;
        padding: 0.65rem 1rem;
        font-size: 0.8rem;
    }
    .moto-list-header {
        position: sticky;
        top: 0;
        z-index: 5;
        background: #f8fafc;
        border-bottom: 1px solid var(--card-border);
        font-weight: 700;
        color: #1e3a5f;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        font-size: 0.65rem;
    }
    .moto-row {
        background: transparent;
        border-bottom: 1px solid var(--card-border);
        transition: background 0.15s ease;
    }
    .moto-row:last-child { border-bottom: none; }
    .moto-row:hover { background: rgba(0, 0, 0, 0.02); }
    .moto-cell { min-width: 0; }
    .moto-name {
        font-weight: 700;
        color: var(--text-dark);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .moto-sub {
        font-size: 0.72rem;
        color: var(--text-dark);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .moto-plate {
        display: inline-flex;
        align-items: center;
        padding: 0.15rem 0.45rem;
        border-radius: 6px;
        background: #e0f2fe;
        color: var(--text-dark);
        font-weight: 700;
        font-size: 0.75rem;
    }
    .moto-status {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.55rem;
        border-radius: 99px;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: capitalize;
    }
    .moto-status.active { background: #dcfce7; color: #15803d; }
    .moto-status.archived { background: #fffbeb; color: #EAB308; }
    .moto-row .moto-col-mileage { font-weight: 700; color: var(--text-dark); }
    .moto-row .moto-col-year { color: var(--text-dark); }

    @media (max-width: 991px) {
        .moto-list-header { display: none; }
        .moto-row {
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            padding: 0.75rem;
        }
        .moto-col-vehicle { grid-column: 1 / -1; }
        .moto-col-actions { grid-column: 1 / -1; justify-self: start; }
    }
    .moto-list-section {
        transition: opacity 0.2s ease;
    }
    .moto-list-section.loading {
        opacity: 0.5 !important;
        pointer-events: none;
    }

    /* View dialog cards */
    .view-card {
        background: #ffffff;
        border: 1px solid var(--card-border);
        border-radius: 12px;
        padding: 1rem;
        height: 100%;
    }
    .view-card .vc-section-title {
        margin-bottom: 0.75rem;
        color: #1e3a5f;
    }
    .view-card .view-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.5rem;
        padding: 0.35rem 0;
        border-bottom: 1px solid var(--card-border);
    }
    .view-card .view-item:last-child {
        border-bottom: none;
    }
    .view-card .view-label {
        color: var(--text-muted);
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 500;
        white-space: nowrap;
    }
    .view-card .view-value {
        font-weight: 600;
        color: var(--text-dark);
        text-align: right;
        word-break: break-word;
        max-width: 60%;
    }
    .view-card .badge {
        color: #fff !important;
    }
    #viewMotorcycleModal .modal-body {
        padding: 1rem;
    }
    #viewMotorcycleModal .modal-footer {
        padding: 0.75rem 1rem;
    }
    #viewModalTitle {
        font-weight: 700;
    }
    #viewModalSubtitle {
        font-size: 0.85rem;
    }

    /* Model picker with photos */
    .model-picker {
        position: relative;
    }
    .model-picker-toggle {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        border: 2px solid rgba(0, 0, 0, 0.1);
        border-radius: 12px;
        padding: 8px 12px;
        font-size: 0.85rem;
        background: #fff;
        color: var(--text-dark);
        transition: all 0.3s ease;
        text-align: left;
        cursor: pointer;
    }
    .model-picker-toggle:hover,
    .model-picker-toggle.open {
        border-color: #1e293b;
    }
    .model-picker-toggle.is-invalid {
        border-color: #dc3545;
    }
    .model-picker-selected {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }
    .model-picker-thumb {
        width: 44px;
        height: 32px;
        object-fit: contain;
        mix-blend-mode: multiply;
    }
    .model-picker-caret {
        font-size: 0.65rem;
        color: #6b7280;
    }
    .model-picker-menu {
        display: none;
        position: absolute;
        top: calc(100% + 6px);
        left: 0;
        right: 0;
        background: #fff;
        border: 1px solid var(--card-border);
        border-radius: 12px;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
        max-height: 260px;
        overflow-y: auto;
        z-index: 20;
    }
    .model-picker-menu.open {
        display: block;
    }
    .model-picker-option {
        display: flex;
        align-items: center;
        gap: 12px;
        width: 100%;
        padding: 8px 12px;
        border: none;
        background: #fff;
        font-size: 0.85rem;
        font-weight: 500;
        color: var(--text-dark);
        text-align: left;
        cursor: pointer;
    }
    .model-picker-option:hover {
        background: #f1f5f9;
    }
    .model-picker-option img {
        width: 56px;
        height: 40px;
        object-fit: contain;
        mix-blend-mode: multiply;
    }
    .model-picker-option .model-picker-other-icon {
        width: 56px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        color: #6b7280;
    }
    .model-picker-custom {
        margin-top: 8px;
    }
    .model-picker-custom[hidden] {
        display: none;
    }

    /* Model photos in list and view modal */
    .moto-vehicle-info {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .moto-thumb {
        width: 52px;
        height: 38px;
        object-fit: contain;
        flex-shrink: 0;
        mix-blend-mode: multiply;
    }
    .view-moto-image {
        display: flex;
        justify-content: center;
        margin-bottom: 1rem;
    }
    .view-moto-image[hidden] {
        display: none;
    }

    /* Floating auto-dismiss alert */
    .floating-alert {
        position: fixed;
        top: 80px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 2000;
        min-width: 320px;
        max-width: 90%;
        padding: 14px 22px;
        border-radius: 12px;
        font-size: 0.9rem;
        font-weight: 600;
        text-align: center;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        animation: alertSlideDown 0.4s ease;
        transition: opacity 0.4s ease, transform 0.4s ease;
        pointer-events: none;
    }
    .floating-alert.success { background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
    .floating-alert.error   { background: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }
    .floating-alert.warning { background: #fff3cd; color: #664d03; border: 1px solid #ffecb5; }
    .floating-alert.hide {
        opacity: 0;
        transform: translateX(-50%) translateY(-20px);
    }
    @keyframes alertSlideDown {
        from { opacity: 0; transform: translateX(-50%) translateY(-20px); }
        to   { opacity: 1; transform: translateX(-50%) translateY(0); }
    }

    .view-moto-image img {
        max-width: 240px;
        max-height: 150px;
        object-fit: contain;
        border-radius: 12px;
        mix-blend-mode: multiply;
    }
    .edit-moto-preview {
        width: 80px;
        height: 56px;
        object-fit: contain;
        border-radius: 8px;
        flex-shrink: 0;
        mix-blend-mode: multiply;
    }

    /* Dark mode overrides */
    html.dark-mode .moto-card { background: #1a2b4f !important; border-color: rgba(255, 255, 255, 0.12) !important; color: #e2e8f0 !important; }
    html.dark-mode .moto-list-header {
        background: #22335a !important;
        color: #93c5fd !important;
        border-bottom-color: rgba(255, 255, 255, 0.09) !important;
    }
    html.dark-mode .moto-list { border-color: rgba(255, 255, 255, 0.09) !important; }
    html.dark-mode .moto-row { border-bottom-color: rgba(255, 255, 255, 0.09) !important; }
    html.dark-mode .moto-row:hover { background: rgba(255, 255, 255, 0.05) !important; }
    html.dark-mode .moto-plate {
        background: rgba(14, 165, 233, 0.15) !important;
        color: #7dd3fc !important;
    }
    html.dark-mode .moto-status.active { background: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; }
    html.dark-mode .moto-status.archived { background: rgba(250, 204, 21, 0.15) !important; color: #FDE047 !important; }

    /* Model picker dropdown */
    html.dark-mode .model-picker-toggle { background: #16233f !important; border-color: #3b4d7d !important; color: #e2e8f0 !important; }
    html.dark-mode .model-picker-toggle:hover,
    html.dark-mode .model-picker-toggle.open { border-color: #FACC15 !important; }
    html.dark-mode .model-picker-caret { color: #94a3b8 !important; }
    html.dark-mode .model-picker-menu { background: #22335a !important; border-color: rgba(255, 255, 255, 0.1) !important; }
    html.dark-mode .model-picker-option { background: #22335a !important; color: #e2e8f0 !important; }
    html.dark-mode .model-picker-option:hover { background: #2a3d6b !important; }
    html.dark-mode .model-picker-option .model-picker-other-icon { color: #94a3b8 !important; }
    html.dark-mode .model-picker-thumb,
    html.dark-mode .model-picker-option img { mix-blend-mode: normal; background: transparent; }

    /* Motorcycle photo thumbs */
    html.dark-mode .moto-thumb, html.dark-mode .view-moto-image img,
    html.dark-mode .edit-moto-preview { mix-blend-mode: normal; background: transparent; }

    /* Floating alerts */
    html.dark-mode .floating-alert.success { background: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; border-color: rgba(16, 185, 129, 0.35) !important; }
    html.dark-mode .floating-alert.error { background: rgba(239, 68, 68, 0.15) !important; color: #f87171 !important; border-color: rgba(239, 68, 68, 0.35) !important; }
    html.dark-mode .floating-alert.warning { background: rgba(250, 204, 21, 0.12) !important; color: #FDE047 !important; border-color: rgba(250, 204, 21, 0.35) !important; }
</style>

<!-- Alert Message -->
<?php if (!empty($msg)): ?>
<div class="floating-alert <?= $msg_type ?>" id="pageAlert" role="alert">
    <?= $msg ?>
</div>
<?php endif; ?>

<!-- Filter and Search -->
<div id="motoListSection" class="moto-list-section">
<form method="GET" class="row g-2 mb-4 align-items-end" onsubmit="return false;">
    <div class="col-12 col-md-5 col-lg-4">
        <div class="input-group">
            <input type="hidden" name="status" value="<?= $current_status ?>">
            <input type="text" name="search" class="form-control search-input"
                   placeholder="Search by brand, model, plate number, or customer name..."
                   value="<?= htmlspecialchars($search) ?>"
                   oninput="liveSearchMotorcycles(this.value)">
            <button type="submit" class="btn btn-primary search-btn">
                <i class="bi bi-search"></i>
            </button>
        </div>
    </div>
    <div class="col-12 col-md">
        <div class="d-flex gap-2 flex-wrap">
            <?php foreach ($status_list as $status => $label): ?>
                <a href="?status=<?= $status ?>&search=<?= urlencode($search) ?>"
                   class="btn btn-outline-<?= $current_status === $status ? 'primary' : 'secondary' ?> status-pill btn-sm px-3 py-2">
                    <?= $label ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="col-12 col-md-auto d-flex align-items-end">
        <button type="button" class="btn btn-primary btn-add-motorcycle" data-bs-toggle="modal" data-bs-target="#addMotorcycleModal">
            <i class="bi bi-plus-circle me-2"></i>Register Motorcycle
        </button>
    </div>
</form>

    <!-- Cards -->
    <?php
    $modelImages = [
        'Click 125'  => 'click125.png',
        'Click 160'  => 'click160.png',
        'ADV 160'    => 'adv.png',
        'PCX 160'    => 'pcx.png',
        'XRM 125'    => 'xrm.png',
        'Mio i 125'  => 'mio.png',
        'NMAX'       => 'nmax.png',
        'Aerox'      => 'ea.png',
        'Sniper 155' => 'snip.png',
        'Raider'     => 'rai.png',
        'Smash 115'  => 'sma.png',
        'Bajaj'      => 'bad.png',
    ];
    $modelImages = array_map(function ($p) { return $p . '?v=3'; }, $modelImages);
    ?>
    <?php if (empty($motorcycles)): ?>
        <div class="text-center py-5">
            <i class="bi bi-motorcycle display-1 text-muted"></i>
            <h4 class="mt-3 text-muted">No motorcycles found</h4>
            <p class="text-muted">Start by registering a new motorcycle</p>
        </div>
    <?php else: ?>
        <div class="moto-total"><i class="bi bi-motorcycle"></i> Total Motorcycles: <strong><?= count($motorcycles) ?></strong></div>
        <div class="moto-list" id="motorcyclesGrid">
            <div class="moto-list-header">
                <span class="moto-col-vehicle">Motorcycle</span>
                <span class="moto-col-owner">Owner</span>
                <span class="moto-col-plate">Plate</span>
                <span class="moto-col-year">Year</span>
                <span class="moto-col-mileage">Mileage</span>
                <span class="moto-col-status">Status</span>
                <span class="moto-col-actions">Actions</span>
            </div>
            <?php foreach ($motorcycles as $motorcycle): ?>
                <?php $searchData = strtolower($motorcycle['brand'] . ' ' . $motorcycle['model'] . ' ' . $motorcycle['plate_number'] . ' ' . $motorcycle['customer_name'] . ' ' . $motorcycle['customer_email']); ?>
                <div class="moto-row" data-search="<?= htmlspecialchars($searchData) ?>">
                    <div class="moto-cell moto-col-vehicle">
                        <div class="moto-vehicle-info">
                            <?php $motoImgSrc = !empty($motorcycle['image']) ? $motorcycle['image'] : ($modelImages[$motorcycle['model']] ?? null); ?>
                            <?php if ($motoImgSrc): ?>
                                <img class="moto-thumb" src="<?= htmlspecialchars($motoImgSrc) ?>" alt="<?= htmlspecialchars($motorcycle['model']) ?>">
                            <?php endif; ?>
                            <div>
                                <div class="moto-name"><?= htmlspecialchars($motorcycle['brand']) ?> <?= htmlspecialchars($motorcycle['model']) ?></div>
                                <div class="moto-sub"><?= htmlspecialchars($motorcycle['color']) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="moto-cell moto-col-owner">
                        <div class="moto-name"><?= htmlspecialchars($motorcycle['customer_name']) ?></div>
                        <div class="moto-sub"><?= htmlspecialchars($motorcycle['customer_email']) ?></div>
                    </div>
                    <div class="moto-cell moto-col-plate">
                        <span class="moto-plate"><?= htmlspecialchars($motorcycle['plate_number']) ?></span>
                    </div>
                    <div class="moto-cell moto-col-year"><?= htmlspecialchars($motorcycle['year_model']) ?></div>
                    <div class="moto-cell moto-col-mileage"><?= number_format($motorcycle['current_mileage'], 2) ?> km</div>
                    <div class="moto-cell moto-col-status">
                        <span class="moto-status <?= $motorcycle['status'] ?>"><?= ucfirst($motorcycle['status']) ?></span>
                    </div>
                    <div class="moto-cell moto-col-actions">
                        <div class="action-group">
                            <button class="action-btn view" data-bs-toggle="modal" data-bs-target="#viewMotorcycleModal" onclick="viewMotorcycle(<?= htmlspecialchars(json_encode($motorcycle)) ?>)" title="View">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button class="action-btn edit" data-bs-toggle="modal" data-bs-target="#editMotorcycleModal" onclick="editMotorcycle(<?= htmlspecialchars(json_encode($motorcycle)) ?>)" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="action-btn delete" onclick="deleteMotorcycle(<?= $motorcycle['id'] ?>)" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Add Motorcycle Modal -->
<div class="modal fade" id="addMotorcycleModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Register New Motorcycle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="create_motorcycle" value="1">
                    <div class="vc-card">
                        <div class="vc-section-title">Motorcycle Information</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Customer Owner </label>
                                <select name="user_id" class="form-select" required>
                                    <option value="">Select Customer</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?= $customer['id'] ?>">
                                            <?= htmlspecialchars($customer['name']) ?> (<?= htmlspecialchars($customer['email']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Brand </label>
                                <input type="text" name="brand" id="add_brand" class="form-control" required placeholder="e.g., Honda, Yamaha">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Model </label>
                                <div class="model-picker" id="add_model_picker">
                                    <input type="hidden" name="model" id="add_model">
                                    <button type="button" class="model-picker-toggle">
                                        <span class="model-picker-selected">
                                            <img class="model-picker-thumb" src="" alt="" hidden>
                                            <span class="model-picker-label">Select Model</span>
                                        </span>
                                        <span class="model-picker-caret">&#9662;</span>
                                    </button>
                                    <div class="model-picker-menu"></div>
                                    <input type="text" class="form-control model-picker-custom" placeholder="Type model name" hidden>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Color </label>
                                <input type="text" name="color" class="form-control" required placeholder="e.g., Black, Red">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Year Model </label>
                                <input type="number" name="year_model" class="form-control" required min="1900" max="2099" placeholder="2024">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Plate Number</label>
                                <input type="text" name="plate_number" class="form-control" required maxlength="7" pattern="[A-Z]{3}[0-9]{4}" title="3 letters followed by 4 numbers (e.g., ABC1234)" placeholder="(3 letters, 4 numbers)" oninput="formatPlateNumber(this)">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Current Mileage (km)</label>
                                <input type="number" name="current_mileage" class="form-control" step="0.01" value="0" placeholder="0.00">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Engine Number</label>
                                <input type="text" name="engine_number" class="form-control" required minlength="11" maxlength="17" pattern="[A-Z0-9]{11,17}" title="11-17 uppercase letters or numbers" placeholder="17 characters only" oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '')">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Chassis Number</label>
                                <input type="text" name="chassis_number" class="form-control" required minlength="17" maxlength="17" pattern="[A-Z0-9]{17}" title="17 uppercase letters or numbers" placeholder="17 characters only" oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '')">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Purchase Date </label>
                                <input type="date" name="purchase_date" class="form-control" required>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Motorcycle Image</label>
                                <input type="file" name="moto_image" class="form-control" accept="image/*">
                                <small class="text-muted">Optional — JPG, PNG, WEBP or GIF, max 5MB. If empty, the model photo is used.</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Register Motorcycle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Motorcycle Modal -->
<div class="modal fade" id="editMotorcycleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Motorcycle Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="edit_motorcycle" value="1">
                    <input type="hidden" name="motorcycle_id" id="edit_motorcycle_id">
                    <div class="vc-card">
                        <div class="vc-section-title">Motorcycle Information</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Customer Owner *</label>
                                <select name="user_id" id="edit_user_id" class="form-select" required>
                                    <option value="">Select Customer</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?= $customer['id'] ?>">
                                            <?= htmlspecialchars($customer['name']) ?> (<?= htmlspecialchars($customer['email']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Brand *</label>
                                <input type="text" name="brand" id="edit_brand" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Model *</label>
                                <div class="model-picker" id="edit_model_picker">
                                    <input type="hidden" name="model" id="edit_model">
                                    <button type="button" class="model-picker-toggle">
                                        <span class="model-picker-selected">
                                            <img class="model-picker-thumb" src="" alt="" hidden>
                                            <span class="model-picker-label">Select Model</span>
                                        </span>
                                        <span class="model-picker-caret">&#9662;</span>
                                    </button>
                                    <div class="model-picker-menu"></div>
                                    <input type="text" class="form-control model-picker-custom" placeholder="Type model name" hidden>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Color *</label>
                                <input type="text" name="color" id="edit_color" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Year Model *</label>
                                <input type="number" name="year_model" id="edit_year_model" class="form-control" required min="1900" max="2099">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Plate Number</label>
                                <input type="text" name="plate_number" id="edit_plate_number" class="form-control" required maxlength="8" title="e.g., ABC1234" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Current Mileage (km)</label>
                                <input type="number" name="current_mileage" id="edit_current_mileage" class="form-control" step="0.01">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Engine Number</label>
                                <input type="text" name="engine_number" id="edit_engine_number" class="form-control" required maxlength="17" title="Letters and numbers only" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Chassis Number</label>
                                <input type="text" name="chassis_number" id="edit_chassis_number" class="form-control" required maxlength="17" title="Letters and numbers only" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Purchase Date *</label>
                                <input type="date" name="purchase_date" id="edit_purchase_date" class="form-control" required>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Motorcycle Image</label>
                                <div class="d-flex align-items-center gap-3">
                                    <img id="edit_moto_image_preview" src="" alt="" class="edit-moto-preview" hidden>
                                    <input type="file" name="moto_image" id="edit_moto_image" class="form-control" accept="image/*">
                                </div>
                                <small class="text-muted">Leave empty to keep the current image.</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Motorcycle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Motorcycle Modal -->
<div class="modal fade" id="viewMotorcycleModal" tabindex="-1" aria-labelledby="viewModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="viewModalTitle">Motorcycle Profile</h5>
                    <div class="text-muted" id="viewModalSubtitle">Select a motorcycle to view details</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="view-moto-image" id="view_model_image_wrap" hidden>
                    <img id="view_model_image" src="" alt="Motorcycle">
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <div class="view-card">
                            <h6 class="vc-section-title">Owner Information</h6>
                            <div class="view-item"><span class="view-label">Name</span><span class="view-value" id="view_customer_name"></span></div>
                            <div class="view-item"><span class="view-label">Email</span><span class="view-value" id="view_customer_email"></span></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="view-card">
                            <h6 class="vc-section-title">Motorcycle Details</h6>
                            <div class="view-item"><span class="view-label">Brand</span><span class="view-value" id="view_brand"></span></div>
                            <div class="view-item"><span class="view-label">Model</span><span class="view-value" id="view_model"></span></div>
                            <div class="view-item"><span class="view-label">Color</span><span class="view-value" id="view_color"></span></div>
                            <div class="view-item"><span class="view-label">Year Model</span><span class="view-value" id="view_year_model"></span></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="view-card">
                            <h6 class="vc-section-title">Identification</h6>
                            <div class="view-item"><span class="view-label">Plate Number</span><span class="view-value"><span id="view_plate_number" class="badge bg-info"></span></span></div>
                            <div class="view-item"><span class="view-label">Engine Number</span><span class="view-value" id="view_engine_number"></span></div>
                            <div class="view-item"><span class="view-label">Chassis Number</span><span class="view-value" id="view_chassis_number"></span></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="view-card">
                            <h6 class="vc-section-title">Additional Information</h6>
                            <div class="view-item"><span class="view-label">Purchase Date</span><span class="view-value" id="view_purchase_date"></span></div>
                            <div class="view-item"><span class="view-label">Current Mileage</span><span class="view-value"><span id="view_current_mileage" class="badge bg-success"></span></span></div>
                            <div class="view-item"><span class="view-label">Status</span><span class="view-value" id="view_status"></span></div>
                            <div class="view-item"><span class="view-label">Registered</span><span class="view-value" id="view_created_at"></span></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Update Mileage Modal -->
<div class="modal fade" id="updateMileageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Mileage</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="update_mileage" value="1">
                    <input type="hidden" name="motorcycle_id" id="mileage_motorcycle_id">
                    <div class="mb-3">
                        <label class="form-label">Motorcycle</label>
                        <input type="text" id="mileage_motorcycle_info" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Current Mileage</label>
                        <input type="text" id="mileage_current" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Mileage (km) *</label>
                        <input type="number" name="new_mileage" class="form-control" step="0.01" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Mileage</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function formatPlateNumber(input) {
    let val = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    let result = '';
    for (let i = 0; i < val.length && i < 7; i++) {
        let c = val[i];
        if (i < 3) {
            if (/[A-Z]/.test(c)) result += c;
        } else {
            if (/[0-9]/.test(c)) result += c;
        }
    }
    input.value = result;
}

const MOTORCYCLE_MODELS = [
    { name: 'Click 125',  brand: 'Honda',  img: 'click125.png' },
    { name: 'Click 160',  brand: 'Honda',  img: 'click160.png' },
    { name: 'ADV 160',    brand: 'Honda',  img: 'adv.png' },
    { name: 'PCX 160',    brand: 'Honda',  img: 'pcx.png' },
    { name: 'XRM 125',    brand: 'Honda',  img: 'xrm.png' },
    { name: 'Mio i 125',  brand: 'Yamaha', img: 'mio.png' },
    { name: 'NMAX',       brand: 'Yamaha', img: 'nmax.png' },
    { name: 'Aerox',      brand: 'Yamaha', img: 'ea.png' },
    { name: 'Sniper 155', brand: 'Yamaha', img: 'snip.png' },
    { name: 'Raider',     brand: 'Suzuki', img: 'rai.png' },
    { name: 'Smash 115',  brand: 'Suzuki', img: 'sma.png' },
    { name: 'Bajaj',      brand: 'Bajaj',  img: 'bad.png' }
];

function initModelPicker(pickerId, brandInputId) {
    const picker = document.getElementById(pickerId);
    if (!picker) return null;
    const hidden = picker.querySelector('input[type="hidden"]');
    const toggle = picker.querySelector('.model-picker-toggle');
    const menu = picker.querySelector('.model-picker-menu');
    const thumb = picker.querySelector('.model-picker-thumb');
    const label = picker.querySelector('.model-picker-label');
    const custom = picker.querySelector('.model-picker-custom');
    const brandInput = brandInputId ? document.getElementById(brandInputId) : null;

    MOTORCYCLE_MODELS.forEach(function(m) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'model-picker-option';
        btn.innerHTML = '<img src="' + m.img + '" alt=""><span>' + m.name + '</span>';
        btn.addEventListener('click', function() { selectModel(m); });
        menu.appendChild(btn);
    });
    const otherBtn = document.createElement('button');
    otherBtn.type = 'button';
    otherBtn.className = 'model-picker-option';
    otherBtn.innerHTML = '<span class="model-picker-other-icon">+</span><span>Other (type manually)</span>';
    otherBtn.addEventListener('click', function() { selectModel(null); });
    menu.appendChild(otherBtn);

    function selectModel(m) {
        if (m) {
            hidden.value = m.name;
            label.textContent = m.name;
            thumb.src = m.img;
            thumb.hidden = false;
            custom.hidden = true;
            custom.required = false;
            if (brandInput) brandInput.value = m.brand;
        } else {
            hidden.value = custom.value;
            label.textContent = 'Other model';
            thumb.hidden = true;
            custom.hidden = false;
            custom.required = true;
            custom.focus();
        }
        toggle.classList.remove('is-invalid');
        close();
    }

    function close() {
        menu.classList.remove('open');
        toggle.classList.remove('open');
    }

    custom.addEventListener('input', function() {
        hidden.value = custom.value;
        if (custom.value.trim()) toggle.classList.remove('is-invalid');
    });
    toggle.addEventListener('click', function() {
        menu.classList.toggle('open');
        toggle.classList.toggle('open');
    });
    document.addEventListener('click', function(e) {
        if (!picker.contains(e.target)) close();
    });

    return {
        element: picker,
        setValue: function(name) {
            const m = MOTORCYCLE_MODELS.find(function(x) { return x.name === name; });
            if (m) {
                hidden.value = m.name;
                label.textContent = m.name;
                thumb.src = m.img;
                thumb.hidden = false;
                custom.hidden = true;
                custom.required = false;
                custom.value = '';
            } else if (name) {
                hidden.value = name;
                label.textContent = 'Other model';
                thumb.hidden = true;
                custom.hidden = false;
                custom.required = true;
                custom.value = name;
            } else {
                this.reset();
            }
            toggle.classList.remove('is-invalid');
        },
        reset: function() {
            hidden.value = '';
            label.textContent = 'Select Model';
            thumb.hidden = true;
            thumb.src = '';
            custom.hidden = true;
            custom.required = false;
            custom.value = '';
            toggle.classList.remove('is-invalid');
            close();
        },
        validate: function() {
            if (!hidden.value.trim()) {
                toggle.classList.add('is-invalid');
                return false;
            }
            return true;
        }
    };
}

const addModelPicker = initModelPicker('add_model_picker', 'add_brand');
const editModelPicker = initModelPicker('edit_model_picker', 'edit_brand');

const addMotoForm = document.querySelector('#addMotorcycleModal form');
if (addMotoForm) {
    addMotoForm.addEventListener('submit', function(e) {
        if (addModelPicker && !addModelPicker.validate()) e.preventDefault();
    });
}
const editMotoForm = document.querySelector('#editMotorcycleModal form');
if (editMotoForm) {
    editMotoForm.addEventListener('submit', function(e) {
        if (editModelPicker && !editModelPicker.validate()) e.preventDefault();
    });
}
const addMotoModal = document.getElementById('addMotorcycleModal');
if (addMotoModal) {
    addMotoModal.addEventListener('hidden.bs.modal', function() {
        if (addModelPicker) addModelPicker.reset();
    });
}

const pageAlert = document.getElementById('pageAlert');
if (pageAlert) {
    setTimeout(function() {
        pageAlert.classList.add('hide');
        setTimeout(function() { pageAlert.remove(); }, 450);
    }, 1200);
}

function liveSearchMotorcycles(query) {
    const trimmed = query.trim();
    const q = trimmed.toLowerCase();
    const url = new URL(window.location.href);
    if (q) url.searchParams.set('search', trimmed);
    else url.searchParams.delete('search');
    window.history.replaceState({}, '', url);

    document.querySelectorAll('.status-pill').forEach(function(a) {
        const u = new URL(a.href, window.location.origin);
        if (q) u.searchParams.set('search', trimmed);
        else u.searchParams.delete('search');
        a.href = u.toString();
    });

    document.querySelectorAll('.moto-row').forEach(function(row) {
        const data = (row.dataset.search || '').toLowerCase();
        const match = !q || data.includes(q);
        row.style.display = match ? '' : 'none';
    });
}

function viewMotorcycle(motorcycle) {
    document.getElementById('viewModalTitle').textContent = (motorcycle.brand || '') + ' ' + (motorcycle.model || '');
    document.getElementById('viewModalSubtitle').textContent = motorcycle.plate_number || '';
    const modelMatch = MOTORCYCLE_MODELS.find(function(m) { return m.name === motorcycle.model; });
    const imgSrc = motorcycle.image || (modelMatch ? modelMatch.img : '');
    const imgWrap = document.getElementById('view_model_image_wrap');
    const img = document.getElementById('view_model_image');
    if (imgSrc) {
        img.src = imgSrc;
        img.alt = motorcycle.model || 'Motorcycle';
        imgWrap.hidden = false;
    } else {
        img.src = '';
        imgWrap.hidden = true;
    }
    document.getElementById('view_customer_name').textContent = motorcycle.customer_name;
    document.getElementById('view_customer_email').textContent = motorcycle.customer_email;
    document.getElementById('view_brand').textContent = motorcycle.brand;
    document.getElementById('view_model').textContent = motorcycle.model;
    document.getElementById('view_color').textContent = motorcycle.color;
    document.getElementById('view_year_model').textContent = motorcycle.year_model;
    document.getElementById('view_plate_number').textContent = motorcycle.plate_number;
    document.getElementById('view_engine_number').textContent = motorcycle.engine_number;
    document.getElementById('view_chassis_number').textContent = motorcycle.chassis_number;
    document.getElementById('view_purchase_date').textContent = new Date(motorcycle.purchase_date).toLocaleDateString();
    document.getElementById('view_current_mileage').textContent = parseFloat(motorcycle.current_mileage).toFixed(2) + ' km';
    document.getElementById('view_status').textContent = motorcycle.status.charAt(0).toUpperCase() + motorcycle.status.slice(1);
    document.getElementById('view_created_at').textContent = new Date(motorcycle.created_at).toLocaleDateString();
}

function editMotorcycle(motorcycle) {
    document.getElementById('edit_motorcycle_id').value = motorcycle.id;
    document.getElementById('edit_user_id').value = motorcycle.user_id;
    document.getElementById('edit_brand').value = motorcycle.brand;
    if (editModelPicker) editModelPicker.setValue(motorcycle.model);
    else document.getElementById('edit_model').value = motorcycle.model;
    document.getElementById('edit_color').value = motorcycle.color;
    document.getElementById('edit_year_model').value = motorcycle.year_model;
    document.getElementById('edit_plate_number').value = motorcycle.plate_number;
    document.getElementById('edit_current_mileage').value = motorcycle.current_mileage;
    document.getElementById('edit_engine_number').value = motorcycle.engine_number;
    document.getElementById('edit_chassis_number').value = motorcycle.chassis_number;
    document.getElementById('edit_purchase_date').value = motorcycle.purchase_date;
    const editImgMatch = MOTORCYCLE_MODELS.find(function(m) { return m.name === motorcycle.model; });
    const editImgSrc = motorcycle.image || (editImgMatch ? editImgMatch.img : '');
    const editPreview = document.getElementById('edit_moto_image_preview');
    if (editImgSrc) {
        editPreview.src = editImgSrc;
        editPreview.hidden = false;
    } else {
        editPreview.src = '';
        editPreview.hidden = true;
    }
    document.getElementById('edit_moto_image').value = '';
}

function updateMileage(motorcycle) {
    document.getElementById('mileage_motorcycle_id').value = motorcycle.id;
    document.getElementById('mileage_motorcycle_info').value = motorcycle.brand + ' ' + motorcycle.model + ' (' + motorcycle.plate_number + ')';
    document.getElementById('mileage_current').value = parseFloat(motorcycle.current_mileage).toFixed(2) + ' km';
}

function archiveMotorcycle(id, archive) {
    const action = archive ? 'archive' : 'unarchive';
    Swal.fire({
        title: 'Are you sure?',
        text: `You are about to ${action} this motorcycle.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: `Yes, ${action} it!`
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="archive_motorcycle" value="1">
                <input type="hidden" name="motorcycle_id" value="${id}">
                <input type="hidden" name="archive" value="${archive}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function deleteMotorcycle(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: 'You will not be able to recover this motorcycle record!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="delete_motorcycle" value="1">
                <input type="hidden" name="motorcycle_id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// Smooth status tab switching without full page reload
let isLoadingMotoList = false;
document.addEventListener('click', function(e) {
    const pill = e.target.closest('.status-pill');
    if (!pill || isLoadingMotoList) return;
    
    const url = new URL(pill.href, window.location.href);
    const section = document.getElementById('motoListSection');
    if (!section) return;
    
    e.preventDefault();
    isLoadingMotoList = true;
    section.classList.add('loading');
    
    fetch(url)
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newSection = doc.getElementById('motoListSection');
            if (newSection) {
                section.outerHTML = newSection.outerHTML;
                if (window.history && window.history.pushState) {
                    window.history.pushState({}, '', url);
                }
                const loadedSection = document.getElementById('motoListSection');
                if (loadedSection) {
                    loadedSection.style.opacity = '0.5';
                    void loadedSection.offsetWidth;
                    loadedSection.style.opacity = '1';
                }
            } else {
                window.location.href = url;
            }
        })
        .catch(() => {
            window.location.href = url;
        })
        .finally(() => {
            isLoadingMotoList = false;
        });
});
</script>

<?php require 'admin_sidebar_footer.php'; ?>
