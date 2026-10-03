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
$customers = [];
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

// Default photos per model name (used when no uploaded image exists)
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

// Formats a stored phone number as 09** *** **** for display
function formatPhoneDisplay($phone) {
    $d = preg_replace('/\D/', '', (string)$phone);
    return strlen($d) === 11 ? substr($d, 0, 4) . ' ' . substr($d, 4, 3) . ' ' . substr($d, 7, 4) : (string)$phone;
}

// Renders the customer list (empty state or rows). Also used by the AJAX live-refresh endpoint.
function renderCustomerList(array $customers) {
    ?>
    <?php if (empty($customers)): ?>
    <div class="text-center py-5">
        <i class="bi bi-people display-1 text-muted"></i>
        <h4 class="mt-3 text-muted">No customers found</h4>
        <p class="text-muted">Start by adding a new customer</p>
    </div>
    <?php else: ?>
    <div class="customer-total"><i class="bi bi-people"></i> Total Customers: <strong><?= count($customers) ?></strong></div>
    <div class="customer-list" id="customersTable">
        <div class="customer-list-header">
            <span class="customer-col-name">Customer</span>
            <span class="customer-col-email">Email</span>
            <span class="customer-col-phone">Phone</span>
            <span class="customer-col-address">Address</span>
            <span class="customer-col-motorcycles">Motorcycles</span>
            <span class="customer-col-status">Status</span>
            <span class="customer-col-actions">Actions</span>
        </div>
        <?php foreach ($customers as $customer): ?>
        <div class="customer-row" data-username="<?= strtolower(htmlspecialchars($customer['username'])) ?>" data-email="<?= strtolower(htmlspecialchars($customer['email'])) ?>" data-phone="<?= strtolower(htmlspecialchars($customer['phone'] ?? '')) ?>" data-motorcycles="<?= strtolower(htmlspecialchars($customer['motorcycle_details'] ?? '')) ?>">
            <div class="customer-cell customer-col-name">
                <div class="customer-name"><?= htmlspecialchars($customer['username']) ?></div>
                <?php if ($customer['archived']): ?>
                    <div class="customer-meta"><span class="customer-status Archived">Archived</span></div>
                <?php endif; ?>
            </div>
            <div class="customer-cell customer-col-email"><?= htmlspecialchars($customer['email']) ?></div>
            <div class="customer-cell customer-col-phone"><?= htmlspecialchars(formatPhoneDisplay($customer['phone'])) ?></div>
            <div class="customer-cell customer-col-address" title="<?= htmlspecialchars($customer['address']) ?>"><?= htmlspecialchars($customer['address']) ?></div>
            <div class="customer-cell customer-col-motorcycles">
                <?php if ($customer['motorcycle_details']): ?>
                    <div class="customer-meta"><?= htmlspecialchars($customer['motorcycle_details']) ?></div>
                <?php else: ?>
                    <div class="customer-meta">No motorcycles</div>
                <?php endif; ?>
            </div>
            <div class="customer-cell customer-col-status">
                <span class="customer-status <?= htmlspecialchars($customer['status']) ?>"><?= $customer['status'] ?></span>
            </div>
            <div class="customer-cell customer-col-actions">
                <div class="action-group">
                    <button type="button" class="action-btn view" onclick="showCustomerDetail(<?= $customer['id'] ?>)" title="View Details">
                        <i class="bi bi-eye"></i>
                    </button>
                    <button type="button" class="action-btn edit" onclick="editCustomer(<?= $customer['id'] ?>)" title="Edit Customer">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button type="button" class="action-btn delete" onclick="deleteCustomer(<?= $customer['id'] ?>, '<?= htmlspecialchars($customer['username']) ?>')" title="Delete Customer">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php
}

// --- Status List for Buttons and Filtering ---
$status_list = [
    'All' => 'All Customers',
    'Active' => 'Active',
    'Inactive' => 'Inactive',
    'Archived' => 'Archived',
];

// --- Search functionality ---
$search = trim($_GET['search'] ?? '');

// --- 1. Logic for Creating a Customer with Motorcycle ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_customer_motorcycle'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $birthdate = $_POST['birthdate'] ?? null;
    $gender = $_POST['gender'] ?? null;
    $status = $_POST['status'] ?? 'Active';
    
    // Motorcycle fields
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $year_model = $_POST['year_model'] ?? 0;
    $plate_number = trim($_POST['plate_number'] ?? '');
    $engine_number = trim($_POST['engine_number'] ?? '');
    $chassis_number = trim($_POST['chassis_number'] ?? '');
    $purchase_date = $_POST['purchase_date'] ?? '';
    $current_mileage = $_POST['current_mileage'] ?? 0;

    if (empty($username) || empty($email) || empty($password) || empty($phone) || empty($address)) {
        $msg = "❌ Please fill in all required customer fields.";
        $msg_type = "error";
    } elseif (!preg_match('/^09\d{9}$/', $phone)) {
        $msg = "❌ Please enter a valid 11-digit mobile number starting with 09 (e.g., 0917 123 4567).";
        $msg_type = "error";
    } elseif (empty($brand) || empty($model) || empty($color) || empty($year_model) || empty($plate_number) || empty($engine_number) || empty($chassis_number) || empty($purchase_date)) {
        $msg = "❌ Please fill in all required motorcycle fields.";
        $msg_type = "error";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Check if email is already registered
            $check = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $check->execute([$email]);
            
            if ($check->rowCount() > 0) {
                $pdo->rollBack();
                $msg = "❌ Email already registered. Please use a different email.";
                $msg_type = "error";
            } else {
                // Check if plate number is already registered
                $check_plate = $pdo->prepare("SELECT id FROM motorcycles WHERE plate_number=?");
                $check_plate->execute([$plate_number]);
                
                if ($check_plate->rowCount() > 0) {
                    $pdo->rollBack();
                    $msg = "❌ Plate number already registered. Please use a different plate number.";
                    $msg_type = "error";
                } else {
                    // Hash password
                    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                    
                    // Insert customer
                    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, phone, address, birthdate, gender, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'customer', ?)");
                    $stmt->execute([$username, $email, $hashed_password, $phone, $address, $birthdate, $gender, $status]);
                    
                    $customer_id = $pdo->lastInsertId();
                    
                    // Insert motorcycle
                    $moto_image = handleMotoImageUpload();
                    $stmt_motorcycle = $pdo->prepare("INSERT INTO motorcycles (user_id, brand, model, color, year_model, plate_number, engine_number, chassis_number, purchase_date, current_mileage, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt_motorcycle->execute([$customer_id, $brand, $model, $color, $year_model, $plate_number, $engine_number, $chassis_number, $purchase_date, $current_mileage, $moto_image]);
                    
                    $pdo->commit();
                    
                    $msg = "✅ Customer account and motorcycle registered successfully! Login credentials: Email: $email, Password: $password";
                    $msg_type = "success";
                }
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Customer & Motorcycle Creation Error: " . $e->getMessage());
            $msg = "❌ Database Error during creation: " . $e->getMessage();
            $msg_type = "error";
        }
    }
}
// --- END Combined Creation Logic ---

// --- 2. Logic for Editing a Customer ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_customer'])) {
    $customer_id = $_POST['customer_id'] ?? 0;
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $birthdate = $_POST['birthdate'] ?? null;
    $gender = $_POST['gender'] ?? null;
    $status = $_POST['status'] ?? 'Active';

    if (empty($username) || empty($email) || empty($phone) || empty($address)) {
        $msg = "❌ Please fill in all required fields.";
        $msg_type = "error";
    } elseif (!preg_match('/^09\d{9}$/', $phone)) {
        $msg = "❌ Please enter a valid 11-digit mobile number starting with 09 (e.g., 0917 123 4567).";
        $msg_type = "error";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, phone=?, address=?, birthdate=?, gender=?, status=? WHERE id=? AND role='customer'");
            $stmt->execute([$username, $email, $phone, $address, $birthdate, $gender, $status, $customer_id]);
            
            if ($stmt->rowCount()) {
                $msg = "✅ Customer information updated successfully!";
                $msg_type = "success";
            } else {
                $msg = "⚠️ Update failed. Customer may not exist.";
                $msg_type = "warning";
            }
        } catch (PDOException $e) {
            error_log("Customer Edit Error: " . $e->getMessage());
            $msg = "❌ Database Error during update: " . $e->getMessage();
            $msg_type = "error";
        }
    }
}
// --- END Edit Logic ---

// --- 3. Logic for Deleting a Customer ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_customer'])) {
    $customer_id = $_POST['customer_id'] ?? 0;

    try {
        // IMPORTANT: Before deleting, check if the customer has ANY associated, incomplete bookings.
        $check_bookings = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ? AND status IN ('pending', 'accepted', 'in_progress', 'deposit_submitted')");
        $check_bookings->execute([$customer_id]);
        
        if ($check_bookings->fetchColumn() > 0) {
            $msg = "❌ Cannot delete customer. They still have active bookings that need to be completed or cancelled.";
            $msg_type = "error";
        } else {
            // Use transactions to ensure both bookings and the user are deleted
            $pdo->beginTransaction();
            
            // 1. Delete completed/cancelled bookings from this customer
            $stmt_bookings = $pdo->prepare("DELETE FROM bookings WHERE user_id = ?");
            $stmt_bookings->execute([$customer_id]);
            
            // 2. Delete motorcycles associated with this customer
            $stmt_motorcycles = $pdo->prepare("DELETE FROM motorcycles WHERE user_id = ?");
            $stmt_motorcycles->execute([$customer_id]);
            
            // 3. Delete the customer
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'customer'");
            $stmt->execute([$customer_id]);
            
            $pdo->commit();

            if ($stmt->rowCount()) {
                $msg = "✅ Customer record and associated motorcycles successfully deleted!";
                $msg_type = "success";
            } else {
                $msg = "⚠️ Deletion failed. Customer may not exist or is not a customer account.";
                $msg_type = "warning";
            }
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        // Log the error for debugging
        error_log("Customer Deletion Error: " . $e->getMessage());
        $msg = "❌ Database Error during deletion: " . $e->getMessage();
        $msg_type = "error";
    }
}
// --- END Deletion Logic ---

// --- 4. Logic for Archiving a Customer ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_customer'])) {
    $customer_id = $_POST['customer_id'] ?? 0;
    $archive = $_POST['archive'] ?? 0;

    try {
        $stmt = $pdo->prepare("UPDATE users SET archived = ? WHERE id = ? AND role = 'customer'");
        $stmt->execute([$archive, $customer_id]);
        
        if ($stmt->rowCount()) {
            $msg = $archive ? "✅ Customer archived successfully!" : "✅ Customer unarchived successfully!";
            $msg_type = "success";
        } else {
            $msg = "⚠️ Archive operation failed. Customer may not exist.";
            $msg_type = "warning";
        }
    } catch (PDOException $e) {
        error_log("Customer Archive Error: " . $e->getMessage());
        $msg = "❌ Database Error during archive: " . $e->getMessage();
        $msg_type = "error";
    }
}
// --- END Archive Logic ---

// --- 5. Logic for Resetting Password ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $customer_id = $_POST['customer_id'] ?? 0;
    $new_password = $_POST['new_password'] ?? '';

    if (empty($new_password) || strlen($new_password) < 6) {
        $msg = "❌ Password must be at least 6 characters.";
        $msg_type = "error";
    } else {
        try {
            $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'customer'");
            $stmt->execute([$hashed_password, $customer_id]);
            
            if ($stmt->rowCount()) {
                $msg = "✅ Password reset successfully! New password: $new_password";
                $msg_type = "success";
            } else {
                $msg = "⚠️ Password reset failed. Customer may not exist.";
                $msg_type = "warning";
            }
        } catch (PDOException $e) {
            error_log("Password Reset Error: " . $e->getMessage());
            $msg = "❌ Database Error during password reset: " . $e->getMessage();
            $msg_type = "error";
        }
    }
}
// --- END Password Reset Logic ---

// --- 6. Logic for Updating Customer Status ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $customer_id = $_POST['customer_id'] ?? 0;
    $new_status = $_POST['new_status'] ?? 'Active';

    try {
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'customer'");
        $stmt->execute([$new_status, $customer_id]);
        
        if ($stmt->rowCount()) {
            $msg = "✅ Customer status updated to $new_status!";
            $msg_type = "success";
        } else {
            $msg = "⚠️ Status update failed. Customer may not exist.";
            $msg_type = "warning";
        }
    } catch (PDOException $e) {
        error_log("Status Update Error: " . $e->getMessage());
        $msg = "❌ Database Error during status update: " . $e->getMessage();
        $msg_type = "error";
    }
}
// --- END Status Update Logic ---

// --- 7. Logic for Adding Motorcycle to Existing Customer ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_motorcycle'])) {
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
                $moto_image = handleMotoImageUpload();
                $stmt = $pdo->prepare("INSERT INTO motorcycles (user_id, brand, model, color, year_model, plate_number, engine_number, chassis_number, purchase_date, current_mileage, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $brand, $model, $color, $year_model, $plate_number, $engine_number, $chassis_number, $purchase_date, $current_mileage, $moto_image]);
                
                $msg = "✅ Motorcycle added successfully!";
                $msg_type = "success";
            }
        } catch (PDOException $e) {
            error_log("Motorcycle Creation Error: " . $e->getMessage());
            $msg = "❌ Database Error during creation: " . $e->getMessage();
            $msg_type = "error";
        }
    }
}
// --- END Add Motorcycle Logic ---

// --- 8. Logic for Editing a Motorcycle ---
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
            $moto_image = handleMotoImageUpload();
            if ($moto_image) {
                $stmt = $pdo->prepare("UPDATE motorcycles SET user_id=?, brand=?, model=?, color=?, year_model=?, plate_number=?, engine_number=?, chassis_number=?, purchase_date=?, current_mileage=?, image=? WHERE id=?");
                $stmt->execute([$user_id, $brand, $model, $color, $year_model, $plate_number, $engine_number, $chassis_number, $purchase_date, $current_mileage, $moto_image, $motorcycle_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE motorcycles SET user_id=?, brand=?, model=?, color=?, year_model=?, plate_number=?, engine_number=?, chassis_number=?, purchase_date=?, current_mileage=? WHERE id=?");
                $stmt->execute([$user_id, $brand, $model, $color, $year_model, $plate_number, $engine_number, $chassis_number, $purchase_date, $current_mileage, $motorcycle_id]);
            }

            if ($stmt->rowCount()) {
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
// --- END Edit Motorcycle Logic ---

// --- 9. Logic for Deleting a Motorcycle ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_motorcycle'])) {
    $motorcycle_id = $_POST['motorcycle_id'] ?? 0;

    try {
        // Check if motorcycle has associated bookings
        $check_bookings = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE motorcycle_id = ? AND status IN ('pending', 'accepted', 'in_progress', 'deposit_submitted')");
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
// --- END Delete Motorcycle Logic ---

// --- 10. Logic for Archiving a Motorcycle ---
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
// --- END Archive Motorcycle Logic ---

// --- 11. Logic for Updating Mileage ---
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

// --- Build Query for Displaying Customers with their Motorcycles ---
try {
    $query = "SELECT u.*,
              (SELECT COUNT(*) FROM motorcycles WHERE user_id = u.id) as motorcycle_count,
              (SELECT GROUP_CONCAT(CONCAT_WS('~|~', brand, model, plate_number, COALESCE(image,'')) SEPARATOR ';;')
               FROM motorcycles WHERE user_id = u.id) as motorcycle_rows
              FROM users u
              WHERE u.role = 'customer'";
    
    $params = [];
    
    // Apply status filter
    if ($current_status !== 'All') {
        if ($current_status === 'Archived') {
            $query .= " AND u.archived = 1";
        } elseif ($current_status === 'Active') {
            $query .= " AND u.status = 'Active' AND u.archived = 0";
        } elseif ($current_status === 'Inactive') {
            $query .= " AND u.status = 'Inactive' AND u.archived = 0";
        }
    }
    
    // Apply search filter (customers + motorcycles)
    if (!empty($search)) {
        $query .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.address LIKE ? OR EXISTS (SELECT 1 FROM motorcycles m WHERE m.user_id = u.id AND (m.brand LIKE ? OR m.model LIKE ? OR m.color LIKE ? OR m.plate_number LIKE ? OR m.engine_number LIKE ? OR m.chassis_number LIKE ?)))";
        $searchTerm = "%$search%";
        $params = array_fill(0, 10, $searchTerm);
    }
    
    $query .= " ORDER BY u.username ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build per-motorcycle display items (text + resolved image)
    foreach ($customers as &$c) {
        $c['moto_items'] = [];
        if (!empty($c['motorcycle_rows'])) {
            foreach (explode(';;', $c['motorcycle_rows']) as $row) {
                $parts = explode('~|~', $row, 4);
                $b = $parts[0] ?? ''; $m = $parts[1] ?? ''; $p = $parts[2] ?? ''; $img = $parts[3] ?? '';
                $c['moto_items'][] = [
                    'text' => trim("$b $m") . ($p !== '' ? " ($p)" : ''),
                    'img'  => $img !== '' ? $img : ($modelImages[$m] ?? null),
                ];
            }
        }
        $c['motorcycle_details'] = implode(', ', array_column($c['moto_items'], 'text'));
    }
    unset($c);

} catch (PDOException $e) {
    error_log("Error fetching customers: " . $e->getMessage());
    $customers = [];
}

// --- AJAX endpoint: return only the customer list markup for live auto-refresh ---
if (isset($_GET['ajax_list'])) {
    renderCustomerList($customers);
    exit;
}

// --- Fetch Customers for Dropdown (for adding motorcycle to existing customer) ---
$customers_dropdown = [];
try {
    $stmt = $pdo->query("SELECT id, username, email FROM users WHERE role = 'customer' AND archived = 0 ORDER BY username ASC");
    $customers_dropdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching customers for dropdown: " . $e->getMessage());
}

$pageTitle = 'Customer & Motorcycle Management';
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
    .table { color: var(--text-dark); }
    .main-content {
        padding-top: 90px !important;
        display: flex;
        flex-direction: column;
        height: 100vh !important;
        overflow: hidden !important;
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
        box-shadow: 0 4px 15px rgba(250, 204, 21, 0.2) !important;
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
        transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
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
        transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        box-shadow: none !important;
    }
    body .btn-primary:hover {
        transform: translateY(-2px);
        background: #EAB308 !important;
        border-color: #EAB308 !important;
        box-shadow: none !important;
        color: #111827 !important;
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
    body .btn-add-customer,
    body .btn-primary.btn-add-customer {
        background: #FACC15 !important;
        border: 1px solid #FACC15 !important;
        color: #111827 !important;
        padding: 8px 20px !important;
        font-size: 0.8rem !important;
        border-radius: 50px;
        font-weight: 600 !important;
        box-shadow: none !important;
    }
    body .btn-add-customer:hover,
    body .btn-primary.btn-add-customer:hover {
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

    /* View Customer Dialog */
    .vc-card {
        background: #ffffff;
        border: 1px solid var(--card-border);
        border-radius: 12px;
        padding: 0.85rem;
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
    .vc-section-title i { width: 16px; height: 16px; color: var(--accent-color); }

    /* Customer list view */
    .customer-total {
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-muted);
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .customer-list {
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
    .customer-list::-webkit-scrollbar {
        width: 0;
        background: transparent;
    }
    .customer-list-header,
    .customer-row {
        display: grid;
        grid-template-columns: 2fr 1.8fr 1fr 1.6fr 1.2fr 0.9fr 0.8fr;
        align-items: center;
        gap: 0.75rem;
        padding: 0.65rem 1rem;
        font-size: 0.8rem;
    }
    .customer-list-header {
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
    .customer-row {
        border-bottom: 1px solid var(--card-border);
        
        color: var(--text-dark);
    }
    .customer-row:last-child { border-bottom: none; }
    .customer-row:hover { background: transparent; }
    .customer-cell { min-width: 0; }
    .customer-name {
        font-weight: 700;
        color: var(--text-dark);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .customer-meta {
        font-size: 0.72rem;
        color: var(--text-dark);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .customer-status {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.55rem;
        border-radius: 99px;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: capitalize;
    }
    .customer-status.Active { background: #dcfce7; color: #15803d; }
    .customer-status.Inactive { background: #fffbeb; color: #EAB308; }
    .customer-status.Archived { background: #e2e8f0; color: #475569; }
    .customer-row .action-group { justify-content: flex-start; }

    @media (max-width: 991px) {
        .customer-list-header { display: none; }
        .customer-row {
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            padding: 0.75rem;
        }
        .customer-col-name { grid-column: 1 / -1; }
        .customer-col-actions { grid-column: 1 / -1; }
    }

    /* Table Text */
    #customersTable { font-size: 0.85rem; }
    #customersTable thead th { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
    #customersTable td { vertical-align: middle; }
    #customersTable .badge { font-size: 0.7rem; }

    /* List Action Buttons */
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
        transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, visibility 0.2s ease, max-height 0.2s ease;
        padding: 0;
        color: #FACC15;
    }
    .action-btn:hover { color: #EAB308; }

    /* Master-Detail Split View */
    .vc-master-detail { display: flex; gap: 1.5rem; align-items: stretch; flex: 1; min-height: 0; }
    .vc-master-col { flex: 1 1 58%; min-width: 0; display: flex; flex-direction: column; min-height: 0; }
    #customerListSection { flex: 1; display: flex; flex-direction: column; min-height: 0; }
    @media (max-width: 991px) {
        .vc-master-detail { flex-direction: column; }
    }
    #customerRows { display: contents; }
    .customer-list-section {
        transition: opacity 0.2s ease;
    }
    .customer-list-section.loading {
        opacity: 0.5 !important;
        pointer-events: none;
    }

    /* View dialog cards (same style as manage_motorcycles view modal) */
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
    #viewCustomerModal .modal-body {
        padding: 1rem;
    }
    #viewCustomerModal .modal-footer {
        padding: 0.75rem 1rem;
    }
    #viewModalTitle {
        font-weight: 700;
    }
    #viewModalSubtitle {
        font-size: 0.85rem;
    }
    .view-moto-image {
        display: flex;
        justify-content: center;
        margin-bottom: 1rem;
    }
    .view-moto-image img {
        max-width: 240px;
        max-height: 150px;
        object-fit: contain;
        border-radius: 12px;
        mix-blend-mode: multiply;
    }

    /* Dark mode overrides */
    html[data-theme="dark"] .customer-status.Active { background: rgba(16, 185, 129, 0.15) !important; color: #34d399 !important; }
    html[data-theme="dark"] .customer-status.Inactive { background: rgba(250, 204, 21, 0.15) !important; color: #FDE047 !important; }
    html[data-theme="dark"] .customer-status.Archived { background: rgba(148, 163, 184, 0.15) !important; color: #cbd5e1 !important; }
    html[data-theme="dark"] .view-moto-image img { mix-blend-mode: normal; }
</style>

<!-- Alert Message -->
<?php if (!empty($msg)): ?>
<div class="alert alert-<?= $msg_type ?> alert-dismissible fade show" role="alert">
    <?= $msg ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Master-Detail Split View -->
<div class="vc-master-detail">
    <div class="vc-master-col">
        <!-- Filter and Search -->
        <div id="customerListSection" class="customer-list-section">
<form method="GET" class="row g-2 mb-4 align-items-end" onsubmit="return false;">
    <div class="col-12 col-md-5 col-lg-4">
        <div class="input-group">
            <input type="hidden" name="status" value="<?= $current_status ?>">
            <input type="text" name="search" class="form-control search-input" placeholder="Search customers..." value="<?= htmlspecialchars($search) ?>" oninput="liveSearchCustomers(this.value)">
            <button type="submit" class="btn btn-primary search-btn">
                <i class="bi bi-search"></i>
            </button>
        </div>
    </div>
    <div class="col-12 col-md">
        <div class="d-flex gap-2 flex-wrap">
            <?php foreach ($status_list as $key => $label): ?>
                <a href="?status=<?= $key ?>&search=<?= urlencode($search) ?>" class="btn btn-outline-<?= $current_status === $key ? 'primary' : 'secondary' ?> status-pill btn-sm px-3 py-2">
                    <?= $label ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="col-12 col-md-auto d-flex align-items-end">
        <button type="button" class="btn btn-primary btn-add-customer" data-bs-toggle="modal" data-bs-target="#addCustomerMotorcycleModal">
            <i class="bi bi-person-plus me-2"></i>Add Customer & Motorcycle
        </button>
    </div>
</form>

<!-- Customers Table (auto-refreshes via AJAX polling) -->
<div id="customerRows">
    <?php renderCustomerList($customers); ?>
</div>

</div>
</div>
</div>



<!-- View Customer Modal -->
<div class="modal fade" id="viewCustomerModal" tabindex="-1" aria-labelledby="viewModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="viewModalTitle">Customer Profile</h5>
                    <div class="text-muted" id="viewModalSubtitle">Select a customer to view details</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="viewCustomerBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Customer & Motorcycle Modal -->
<div class="modal fade" id="addCustomerMotorcycleModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Customer & Motorcycle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <h6 class="mb-3">Customer Information</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required minlength="6">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" required inputmode="numeric" maxlength="13" pattern="09[0-9]{2} [0-9]{3} [0-9]{4}" placeholder="09** *** ****" title="11-digit mobile number starting with 09 (e.g., 0917 123 4567)" oninput="formatPhoneNumber(this)">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <input type="text" name="address" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Birthdate</label>
                            <input type="date" name="birthdate" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Gender</label>
                            <select name="gender" class="form-select">
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <hr>
                    <h6 class="mb-3">Motorcycle Information</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Brand</label>
                            <input type="text" name="brand" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Model</label>
                            <input type="text" name="model" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Color</label>
                            <input type="text" name="color" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Year Model</label>
                            <input type="number" name="year_model" class="form-control" required min="1900" max="2099">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Plate Number</label>
                            <input type="text" name="plate_number" class="form-control" required maxlength="7" pattern="[A-Z]{3}[0-9]{4}" title="3 letters followed by 4 numbers (e.g., ABC1234)" placeholder="(3 letters, 4 numbers)" oninput="formatPlateNumber(this)">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Engine Number</label>
                            <input type="text" name="engine_number" class="form-control" required minlength="11" maxlength="17" pattern="[A-Z0-9]{11,17}" placeholder="17 characters only" title="11-17 uppercase letters or numbers" oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '')">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Chassis Number</label>
                            <input type="text" name="chassis_number" class="form-control" required minlength="17" maxlength="17" pattern="[A-Z0-9]{17}" placeholder="17 characters only" title="17 uppercase letters or numbers" oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '')">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Purchase Date</label>
                            <input type="date" name="purchase_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Current Mileage (km)</label>
                            <input type="number" name="current_mileage" class="form-control" value="0" min="0">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Motorcycle Image</label>
                            <input type="file" name="moto_image" class="form-control" accept="image/*">
                            <small class="text-muted">Optional — JPG, PNG, WEBP or GIF, max 5MB. If empty, the model photo is used.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_customer_motorcycle" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Create Customer & Motorcycle
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal fade" id="editCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="customer_id" id="edit_customer_id">
                    <div class="vc-card">
                        <div class="vc-section-title">Customer Information</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Username *</label>
                                <input type="text" name="username" id="edit_username" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email *</label>
                                <input type="email" name="email" id="edit_email" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone *</label>
                                <input type="text" name="phone" id="edit_phone" class="form-control" required inputmode="numeric" maxlength="13" pattern="09[0-9]{2} [0-9]{3} [0-9]{4}" placeholder="09** *** ****" title="11-digit mobile number starting with 09 (e.g., 0917 123 4567)" oninput="formatPhoneNumber(this)">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Address *</label>
                                <input type="text" name="address" id="edit_address" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Birthdate</label>
                                <input type="date" name="birthdate" id="edit_birthdate" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Gender</label>
                                <select name="gender" id="edit_gender" class="form-select">
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select name="status" id="edit_status" class="form-select">
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_customer" class="btn btn-primary">
                        <i class="bi bi-pencil"></i> Update Customer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Motorcycle Modal -->
<div class="modal fade" id="addMotorcycleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Motorcycle to Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Customer *</label>
                        <select name="user_id" id="motorcycle_customer_id" class="form-select" required>
                            <option value="">Select Customer</option>
                            <?php foreach ($customers_dropdown as $cust): ?>
                            <option value="<?= $cust['id'] ?>"><?= htmlspecialchars($cust['username']) ?> (<?= htmlspecialchars($cust['email']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Brand *</label>
                            <input type="text" name="brand" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Model *</label>
                            <input type="text" name="model" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Color *</label>
                            <input type="text" name="color" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Year Model *</label>
                            <input type="number" name="year_model" class="form-control" required min="1900" max="2099">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Plate Number *</label>
                            <input type="text" name="plate_number" class="form-control" required maxlength="7" pattern="[A-Z]{3}[0-9]{4}" title="3 letters followed by 4 numbers (e.g., ABC1234)" placeholder="(3 letters, 4 numbers)" oninput="formatPlateNumber(this)">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Engine Number *</label>
                            <input type="text" name="engine_number" class="form-control" required minlength="11" maxlength="17" pattern="[A-Z0-9]{11,17}" placeholder="17 characters only" title="11-17 uppercase letters or numbers" oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '')">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Chassis Number *</label>
                            <input type="text" name="chassis_number" class="form-control" required minlength="17" maxlength="17" pattern="[A-Z0-9]{17}" placeholder="17 characters only" title="17 uppercase letters or numbers" oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '')">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Purchase Date *</label>
                            <input type="date" name="purchase_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Current Mileage (km)</label>
                            <input type="number" name="current_mileage" class="form-control" value="0" min="0">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Motorcycle Image</label>
                            <input type="file" name="moto_image" class="form-control" accept="image/*">
                            <small class="text-muted">Optional — JPG, PNG, WEBP or GIF, max 5MB. If empty, the model photo is used.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_motorcycle" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Add Motorcycle
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reset Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="customer_id" id="reset_customer_id">
                    <p>Reset password for: <strong id="reset_customer_name"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">New Password *</label>
                        <input type="password" name="new_password" class="form-control" required minlength="6">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reset_password" class="btn btn-warning">
                        <i class="bi bi-key"></i> Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>



<script>
// Formats a phone value as 09** *** **** (11 digits, digits only)
function formatPhoneString(value) {
    var digits = (value || '').replace(/\D/g, '').slice(0, 11);
    var parts = [];
    if (digits.length > 0) parts.push(digits.slice(0, 4));
    if (digits.length > 4) parts.push(digits.slice(4, 7));
    if (digits.length > 7) parts.push(digits.slice(7, 11));
    return parts.join(' ');
}
function formatPhoneNumber(input) {
    input.value = formatPhoneString(input.value);
}

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

function motoImageFor(moto) {
    const match = MOTORCYCLE_MODELS.find(function(m) { return m.name === moto.model; });
    return moto.image || (match ? match.img : '');
}

function liveSearchCustomers(query) {
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

    document.querySelectorAll('.customer-row').forEach(function(row) {
        const username = (row.dataset.username || '').toLowerCase();
        const parts = username.split(/[ _-]+/);
        const match = !q || username.startsWith(q) || parts.some(function(part) {
            return part.startsWith(q);
        });
        row.style.display = match ? '' : 'none';
    });
}

function editCustomer(customerId) {
    // Fetch customer data via AJAX
    fetch('api/get_customer_data.php?id=' + customerId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('edit_customer_id').value = data.customer.id;
                document.getElementById('edit_username').value = data.customer.username;
                document.getElementById('edit_email').value = data.customer.email;
                document.getElementById('edit_phone').value = formatPhoneString(data.customer.phone);
                document.getElementById('edit_address').value = data.customer.address;
                document.getElementById('edit_birthdate').value = data.customer.birthdate || '';
                document.getElementById('edit_gender').value = data.customer.gender || '';
                document.getElementById('edit_status').value = data.customer.status;
                
                new bootstrap.Modal(document.getElementById('editCustomerModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to fetch customer data', 'error');
        });
}

function addMotorcycleToCustomer(customerId, customerName) {
    document.getElementById('motorcycle_customer_id').value = customerId;
    new bootstrap.Modal(document.getElementById('addMotorcycleModal')).show();
}

function resetPassword(customerId, customerName) {
    document.getElementById('reset_customer_id').value = customerId;
    document.getElementById('reset_customer_name').textContent = customerName;
    new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
}

function archiveCustomer(customerId, archive) {
    const action = archive ? 'archive' : 'unarchive';
    Swal.fire({
        title: `Are you sure you want to ${action} this customer?`,
        text: "This action can be reversed later.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: `Yes, ${action} it!`,
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="customer_id" value="${customerId}">
                <input type="hidden" name="archive" value="${archive}">
                <input type="hidden" name="archive_customer" value="1">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function deleteCustomer(customerId, customerName) {
    Swal.fire({
        title: 'Are you sure you want to delete this customer?',
        text: "This will also delete all their motorcycles and cannot be undone!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#d33'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="customer_id" value="${customerId}">
                <input type="hidden" name="delete_customer" value="1">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function viewEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function viewDate(s) {
    if (!s) return 'N/A';
    const d = new Date(s);
    return isNaN(d) ? viewEsc(s) : d.toLocaleDateString();
}

function showCustomerDetail(customerId) {
    const modalEl = document.getElementById('viewCustomerModal');
    const body = document.getElementById('viewCustomerBody');
    document.getElementById('viewModalTitle').textContent = 'Customer Profile';
    document.getElementById('viewModalSubtitle').textContent = 'Loading customer details...';
    body.innerHTML = `<div class='text-center py-5'><div class='spinner-border text-primary' role='status'><span class='visually-hidden'>Loading...</span></div></div>`;
    bootstrap.Modal.getOrCreateInstance(modalEl).show();

    fetch('api/get_customer_data.php?id=' + customerId + '&include_motorcycles=1')
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                body.innerHTML = `<div class='text-danger text-center py-4'>${viewEsc(data.message || 'Unable to load customer details.')}</div>`;
                return;
            }

            const customer = data.customer;
            const motorcycles = data.motorcycles || [];

            document.getElementById('viewModalTitle').textContent = customer.username || 'Customer Profile';
            document.getElementById('viewModalSubtitle').textContent = customer.email || '';

            let html = `<div class='row g-2'>
                <div class='col-12'>
                    <div class='view-card'>
                        <h6 class='vc-section-title'>Customer Information</h6>
                        <div class='view-item'><span class='view-label'>Username</span><span class='view-value'>${viewEsc(customer.username) || 'N/A'}</span></div>
                        <div class='view-item'><span class='view-label'>Email</span><span class='view-value'>${viewEsc(customer.email) || 'N/A'}</span></div>
                        <div class='view-item'><span class='view-label'>Phone</span><span class='view-value'>${viewEsc(formatPhoneString(customer.phone)) || 'N/A'}</span></div>
                        <div class='view-item'><span class='view-label'>Address</span><span class='view-value'>${viewEsc(customer.address) || 'N/A'}</span></div>
                        <div class='view-item'><span class='view-label'>Status</span><span class='view-value'><span class='badge ${customer.status === 'Active' ? 'bg-success' : 'bg-secondary'}'>${viewEsc(customer.status) || 'N/A'}</span></span></div>
                        <div class='view-item'><span class='view-label'>Total Bookings</span><span class='view-value'>${customer.total_bookings || 0}</span></div>
                    </div>
                </div>`;

            if (!motorcycles.length) {
                html += `<div class='col-12'><div class='view-card text-center text-muted'>No motorcycles registered for this customer.</div></div>`;
            }

            motorcycles.forEach(function(moto, i) {
                const imgSrc = motoImageFor(moto);
                const status = moto.status || '';
                html += `
                ${imgSrc ? `<div class='col-12'><div class='view-moto-image'><img src='${viewEsc(imgSrc)}' alt='${viewEsc(moto.model || 'Motorcycle')}'></div></div>` : ''}
                <div class='col-md-6'>
                    <div class='view-card'>
                        <h6 class='vc-section-title'>Motorcycle ${motorcycles.length > 1 ? '#' + (i + 1) + ' ' : ''}Details</h6>
                        <div class='view-item'><span class='view-label'>Brand</span><span class='view-value'>${viewEsc(moto.brand) || 'N/A'}</span></div>
                        <div class='view-item'><span class='view-label'>Model</span><span class='view-value'>${viewEsc(moto.model) || 'N/A'}</span></div>
                        <div class='view-item'><span class='view-label'>Color</span><span class='view-value'>${viewEsc(moto.color) || 'N/A'}</span></div>
                        <div class='view-item'><span class='view-label'>Year Model</span><span class='view-value'>${viewEsc(moto.year_model) || 'N/A'}</span></div>
                    </div>
                </div>
                <div class='col-md-6'>
                    <div class='view-card'>
                        <h6 class='vc-section-title'>Identification</h6>
                        <div class='view-item'><span class='view-label'>Plate Number</span><span class='view-value'><span class='badge bg-info'>${viewEsc(moto.plate_number) || 'N/A'}</span></span></div>
                        <div class='view-item'><span class='view-label'>Engine Number</span><span class='view-value'>${viewEsc(moto.engine_number) || 'N/A'}</span></div>
                        <div class='view-item'><span class='view-label'>Chassis Number</span><span class='view-value'>${viewEsc(moto.chassis_number) || 'N/A'}</span></div>
                    </div>
                </div>
                <div class='col-md-6'>
                    <div class='view-card'>
                        <h6 class='vc-section-title'>Additional Information</h6>
                        <div class='view-item'><span class='view-label'>Purchase Date</span><span class='view-value'>${viewDate(moto.purchase_date)}</span></div>
                        <div class='view-item'><span class='view-label'>Current Mileage</span><span class='view-value'><span class='badge bg-success'>${parseFloat(moto.current_mileage || 0).toFixed(2)} km</span></span></div>
                        <div class='view-item'><span class='view-label'>Status</span><span class='view-value'>${status ? status.charAt(0).toUpperCase() + status.slice(1) : 'N/A'}</span></div>
                        <div class='view-item'><span class='view-label'>Registered</span><span class='view-value'>${viewDate(moto.created_at)}</span></div>
                    </div>
                </div>
                <div class='col-md-6'>
                    <div class='view-card'>
                        <h6 class='vc-section-title'>Maintenance &amp; Warranty</h6>
                        <div class='view-item'><span class='view-label'>Warranty Status</span><span class='view-value'>${viewEsc(moto.warranty_status) || 'N/A'}</span></div>
                        <div class='view-item'><span class='view-label'>Warranty Expiry</span><span class='view-value'>${viewDate(moto.warranty_expiry_date)}</span></div>
                        <div class='view-item'><span class='view-label'>Last Maintenance</span><span class='view-value'>${viewDate(moto.last_maintenance_date)}</span></div>
                        <div class='view-item'><span class='view-label'>Next Maintenance</span><span class='view-value'>${viewDate(moto.next_maintenance_date)}</span></div>
                        <div class='view-item'><span class='view-label'>Health Score</span><span class='view-value'>${moto.health_score != null ? viewEsc(moto.health_score) : 'N/A'}</span></div>
                    </div>
                </div>`;
            });
            html += `</div>`;
            body.innerHTML = html;
        })
        .catch(error => {
            console.error('Error:', error);
            body.innerHTML = `<div class='text-danger text-center py-4'>Failed to fetch customer data. Please try again.</div>`;
        });
}

// Smooth status tab switching without full page reload
let isLoadingCustomerList = false;
document.addEventListener('click', function(e) {
    const pill = e.target.closest('.status-pill');
    if (!pill || isLoadingCustomerList) return;
    
    const url = new URL(pill.href, window.location.href);
    const section = document.getElementById('customerListSection');
    if (!section) return;
    
    e.preventDefault();
    isLoadingCustomerList = true;
    section.classList.add('loading');
    
    fetch(url)
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newSection = doc.getElementById('customerListSection');
            if (newSection) {
                section.outerHTML = newSection.outerHTML;
                if (window.history && window.history.pushState) {
                    window.history.pushState({}, '', url);
                }
                const loadedSection = document.getElementById('customerListSection');
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
            isLoadingCustomerList = false;
        });
});

// --- Live auto-refresh: poll for customer list changes so new registrations appear without reload ---
(function () {
    var POLL_MS = 5000;
    var lastListHtml = null;

    function refreshCustomerList() {
        if (document.hidden) return;
        var region = document.getElementById('customerRows');
        if (!region) return;

        var url = new URL(window.location.href);
        url.searchParams.set('ajax_list', '1');
        url.searchParams.set('_', Date.now());

        fetch(url.toString())
            .then(function (res) { return res.text(); })
            .then(function (html) {
                var region = document.getElementById('customerRows');
                if (!region) return;
                var trimmed = html.trim();
                if (lastListHtml !== null && trimmed === lastListHtml) return;
                lastListHtml = trimmed;
                region.innerHTML = trimmed;
                var searchInput = document.querySelector('#customerListSection input[name="search"]');
                liveSearchCustomers(searchInput ? searchInput.value : '');
            })
            .catch(function () { /* ignore and retry on next poll */ });
    }

    setInterval(refreshCustomerList, POLL_MS);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) refreshCustomerList();
    });
})();
</script>

<?php require 'admin_sidebar_footer.php'; ?>
