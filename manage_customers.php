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
$current_status = $_GET['status'] ?? 'All';

// Ensure database connection is attempted AFTER session check
require 'db.php'; 

// --- Status List for Buttons and Filtering ---
$status_list = [
    'All' => 'All Customers',
    'Active' => 'Active',
    'Inactive' => 'Inactive',
    'Archived' => 'Archived',
];

// --- Search functionality ---
$search = $_GET['search'] ?? '';

// --- 1. Logic for Creating a Customer ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_customer'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $birthdate = $_POST['birthdate'] ?? null;
    $gender = $_POST['gender'] ?? null;
    $status = $_POST['status'] ?? 'Active';

    if (empty($username) || empty($email) || empty($password) || empty($phone) || empty($address)) {
        $msg = "❌ Please fill in all required fields.";
        $msg_type = "error";
    } else {
        try {
            // Check if email is already registered
            $check = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $check->execute([$email]);
            
            if ($check->rowCount() > 0) {
                $msg = "❌ Email already registered. Please use a different email.";
                $msg_type = "error";
            } else {
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password, phone, address, birthdate, gender, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'customer', ?)");
                $stmt->execute([$username, $email, $hashed_password, $phone, $address, $birthdate, $gender, $status]);
                
                $msg = "✅ Customer account created successfully! Login credentials: Email: $email, Password: $password";
                $msg_type = "success";
            }
        } catch (PDOException $e) {
            error_log("Customer Creation Error: " . $e->getMessage());
            $msg = "❌ Database Error during creation: " . $e->getMessage();
            $msg_type = "error";
        }
    }
}
// --- END Creation Logic ---

// --- 2. Logic for Editing a Customer ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_customer'])) {
    $customer_id = $_POST['customer_id'] ?? 0;
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $birthdate = $_POST['birthdate'] ?? null;
    $gender = $_POST['gender'] ?? null;
    $status = $_POST['status'] ?? 'Active';

    if (empty($username) || empty($email) || empty($phone) || empty($address)) {
        $msg = "❌ Please fill in all required fields.";
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
            
            // 2. Delete the customer
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'customer'");
            $stmt->execute([$customer_id]);
            
            $pdo->commit();

            if ($stmt->rowCount()) {
                $msg = "✅ Customer record successfully deleted!";
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
    $new_status = $_POST['status'] ?? 'Active';

    try {
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'customer'");
        $stmt->execute([$new_status, $customer_id]);
        
        if ($stmt->rowCount()) {
            $msg = "✅ Customer status updated successfully!";
            $msg_type = "success";
        } else {
            $msg = "⚠️ Status update failed. Customer may not exist.";
            $msg_type = "warning";
        }
    } catch (PDOException $e) {
        error_log("Customer Status Update Error: " . $e->getMessage());
        $msg = "❌ Database Error during status update: " . $e->getMessage();
        $msg_type = "error";
    }
}
// --- END Status Update Logic ---

// --- 7. Fetch Customers with Filtering and Search Logic ---
$sql = "
    SELECT 
        u.id, 
        u.username, 
        u.email, 
        u.phone, 
        u.address, 
        u.birthdate,
        u.gender,
        u.status,
        u.archived,
        u.created_at,
        COUNT(b.id) AS total_bookings
    FROM 
        users u
    LEFT JOIN 
        bookings b ON u.id = b.user_id
    WHERE 
        u.role = 'customer'
";

$params = [];
$where_clause = "";

// Apply search filter
if (!empty($search)) {
    $where_clause .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

// Apply status filter
if ($current_status !== 'All' && array_key_exists($current_status, $status_list)) {
    if ($current_status === 'Archived') {
        $where_clause .= " AND u.archived = 1";
    } else {
        $where_clause .= " AND u.status = ? AND u.archived = 0";
        $params[] = $current_status;
    }
} else {
    $where_clause .= " AND u.archived = 0";
}

// Pagination: count rows matching the same filters, then limit this page
require_once __DIR__ . '/pagination_helper.php';
$pg = paginate(0, 10);
try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE u.role = 'customer'" . $where_clause);
    $count_stmt->execute($params);
    $pg = paginate((int)$count_stmt->fetchColumn(), 10);
} catch (PDOException $e) {
    error_log("Error counting customers: " . $e->getMessage());
}

// GROUP BY is necessary because we use the COUNT aggregate function
$sql .= $where_clause . " GROUP BY u.id ORDER BY u.created_at DESC"
      . " LIMIT {$pg['per_page']} OFFSET {$pg['offset']}";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Log the error for debugging
    error_log("Error fetching customer list: " . $e->getMessage());
    $msg = "❌ Error fetching customer list: " . $e->getMessage();
    $msg_type = "error";
    // Keep $customers as an empty array [] to avoid the Fatal Error on count()
}
// --- END Fetch Logic ---
$pageTitle = 'Manage Customers';
?>

<?php require 'admin_sidebar_template.php'; ?>

<style>
    .badge-active { background-color: #10b981; }
    .badge-inactive { background-color: #6b7280; }
    
    .customer-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
        border: none;
        margin-bottom: 15px;
    }
    .customer-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 40px rgba(0,0,0,0.12);
    }
    .customer-avatar {
        width: 50px;
        height: 50px;
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.5rem;
        font-weight: 600;
    }
    .filter-btn {
        border-radius: 20px;
        padding: 8px 16px;
        font-size: 0.85rem;
        font-weight: 500;
        transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, visibility 0.3s ease, max-height 0.3s ease;
    }
    .filter-btn.active {
        background: var(--accent-gradient);
        border-color: transparent;
        color: white;
        box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
    }
    .status-indicator {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 6px;
        animation: pulse 2s infinite;
    }
    .status-active { background: #10b981; }
    .status-inactive { background: #6b7280; }
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-people-fill text-warning me-2"></i>Manage Customers</h4>
            <p class="text-muted mb-0 small"><?= count($customers) ?> customers registered</p>
        </div>
        <button class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#createCustomerModal">
            <i class="bi bi-person-plus-fill me-2"></i> Add New Customer
        </button>
    </div>

    <!-- Search Bar -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0">
                    <i class="bi bi-search text-muted"></i>
                </span>
                <input type="text" class="form-control border-start-0" id="searchInput" placeholder="Search by name, email, or phone..." value="<?= htmlspecialchars($search) ?>">
            </div>
        </div>
    </div>
    
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type == 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
            <?= $msg_type == 'success' ? '<i class="bi bi-check-circle-fill me-2"></i>' : '<i class="bi bi-x-octagon-fill me-2"></i>' ?>
            <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap gap-2 mb-4 align-items-center">
        <span class="text-muted small me-2">Filter by:</span>
        <?php foreach ($status_list as $value => $label): ?>
            <?php 
                $active_class = ($current_status == $value) ? 'active' : 'btn-outline-secondary';
                $url = ($value == 'All') ? 'manage_customers.php' : 'manage_customers.php?status=' . urlencode($value);
            ?>
            <a href="<?= $url ?>" class="btn btn-sm filter-btn <?= $active_class ?>">
                <?= $label ?>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>#ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Birthdate</th>
                    <th>Gender</th>
                    <th>Bookings</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">
                            <?php if ($current_status === 'All'): ?>
                                No customers have been registered yet.
                            <?php else: ?>
                                No customers found with status: <?= htmlspecialchars($status_list[$current_status] ?? $current_status) ?>.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td><?= $customer['id'] ?></td>
                            <td><strong><?= htmlspecialchars($customer['username']) ?></strong></td>
                            <td><?= htmlspecialchars($customer['email']) ?></td>
                            <td><?= htmlspecialchars($customer['phone']) ?></td>
                            <td><?= $customer['birthdate'] ? date('M d, Y', strtotime($customer['birthdate'])) : '-' ?></td>
                            <td><?= htmlspecialchars($customer['gender'] ?? '-') ?></td>
                            <td>
                                <span class="badge bg-info"><?= $customer['total_bookings'] ?></span>
                            </td>
                            <td>
                                <?php
                                    $status = htmlspecialchars($customer['status']);
                                    $class = '';
                                    if ($status == 'Active') $class = 'badge-active';
                                    else if ($status == 'Inactive') $class = 'badge-inactive';
                                    else $class = 'bg-dark'; // Default for other states
                                ?>
                                <span class="badge <?= $class ?>"><?= $status ?></span>
                            </td>
                            <td><?= date('M d, Y', strtotime($customer['created_at'])) ?></td>
                            <td>
                                <div class="btn-group" role="group">
                                    <button class="btn btn-sm btn-info text-white" onclick="viewCustomer(<?= $customer['id'] ?>)" title="View Profile">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-primary" onclick="editCustomer(<?= $customer['id'] ?>)" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-warning text-white" onclick="resetPassword(<?= $customer['id'] ?>, '<?= htmlspecialchars($customer['username']) ?>')" title="Reset Password">
                                        <i class="bi bi-key"></i>
                                    </button>
                                    <button class="btn btn-sm btn-secondary" onclick="toggleArchive(<?= $customer['id'] ?>, '<?= htmlspecialchars($customer['username']) ?>', <?= $customer['archived'] ?>)" title="Archive/Unarchive">
                                        <i class="bi bi-archive"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $customer['id'] ?>, '<?= htmlspecialchars($customer['username']) ?>')" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($pg) ?>
</div>

<form id="deleteForm" method="POST" action="manage_customers.php" style="display: none;">
    <input type="hidden" name="delete_customer" value="1">
    <input type="hidden" name="customer_id" id="deleteCustomerId">
</form>

<form id="statusForm" method="POST" action="manage_customers.php" style="display: none;">
    <input type="hidden" name="update_status" value="1">
    <input type="hidden" name="customer_id" id="statusCustomerId">
    <input type="hidden" name="status" id="newStatus">
</form>

<form id="archiveForm" method="POST" action="manage_customers.php" style="display: none;">
    <input type="hidden" name="archive_customer" value="1">
    <input type="hidden" name="customer_id" id="archiveCustomerId">
    <input type="hidden" name="archive" id="archiveValue">
</form>

<!-- Create Customer Modal -->
<div class="modal fade" id="createCustomerModal" tabindex="-1" aria-labelledby="createCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="createCustomerModalLabel"><i class="bi bi-person-plus me-2"></i>Create New Customer Account</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="manage_customers.php">
                <input type="hidden" name="create_customer" value="1">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="username" class="form-label">Full Name *</label>
                            <input type="text" class="form-control" id="username" name="username" required placeholder="e.g., Juan Dela Cruz">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email Address *</label>
                            <input type="email" class="form-control" id="email" name="email" required placeholder="e.g., juan@example.com">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="password" class="form-label">Password *</label>
                            <input type="password" class="form-control" id="password" name="password" required placeholder="Min. 6 characters">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Phone Number *</label>
                            <input type="tel" class="form-control" id="phone" name="phone" required placeholder="e.g., 09123456789">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="birthdate" class="form-label">Birthdate</label>
                            <input type="date" class="form-control" id="birthdate" name="birthdate">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="gender" class="form-label">Gender</label>
                            <select class="form-select" id="gender" name="gender">
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="address" class="form-label">Address *</label>
                        <textarea class="form-control" id="address" name="address" required rows="2" placeholder="Full Address (Street, Barangay, City)"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="status" class="form-label">Account Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="Active" selected>Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-2"></i>Create Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal fade" id="editCustomerModal" tabindex="-1" aria-labelledby="editCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title" id="editCustomerModalLabel"><i class="bi bi-pencil me-2"></i>Edit Customer Information</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="manage_customers.php">
                <input type="hidden" name="edit_customer" value="1">
                <input type="hidden" name="customer_id" id="editCustomerId">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_username" class="form-label">Full Name *</label>
                            <input type="text" class="form-control" id="edit_username" name="username" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_email" class="form-label">Email Address *</label>
                            <input type="email" class="form-control" id="edit_email" name="email" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_phone" class="form-label">Phone Number *</label>
                            <input type="tel" class="form-control" id="edit_phone" name="phone" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_birthdate" class="form-label">Birthdate</label>
                            <input type="date" class="form-control" id="edit_birthdate" name="birthdate">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_gender" class="form-label">Gender</label>
                            <select class="form-select" id="edit_gender" name="gender">
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_status" class="form-label">Account Status</label>
                            <select class="form-select" id="edit_status" name="status">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_address" class="form-label">Address *</label>
                        <textarea class="form-control" id="edit_address" name="address" required rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-save me-2"></i>Update Information</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Customer Modal -->
<div class="modal fade" id="viewCustomerModal" tabindex="-1" aria-labelledby="viewCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="viewCustomerModalLabel"><i class="bi bi-eye me-2"></i>Customer Profile</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="viewCustomerContent">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title" id="resetPasswordModalLabel"><i class="bi bi-key me-2"></i>Reset Password</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="manage_customers.php">
                <input type="hidden" name="reset_password" value="1">
                <input type="hidden" name="customer_id" id="resetCustomerId">
                <div class="modal-body">
                    <p>Reset password for: <strong id="resetCustomerName"></strong></p>
                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password *</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required placeholder="Min. 6 characters">
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm Password *</label>
                        <input type="password" class="form-control" id="confirm_password" required placeholder="Re-enter new password">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-key me-2"></i>Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Search functionality
    document.getElementById('searchInput').addEventListener('keyup', function(e) {
        if (e.key === 'Enter') {
            const searchValue = this.value.trim();
            const url = searchValue ? 'manage_customers.php?search=' + encodeURIComponent(searchValue) : 'manage_customers.php';
            window.location.href = url;
        }
    });

    // SweetAlert Deletion Confirmation
    function confirmDelete(id, name) {
        Swal.fire({
            title: 'Are you sure?',
            html: `You are about to delete the customer ${name}. This action cannot be undone.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, Delete It!'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('deleteCustomerId').value = id;
                document.getElementById('deleteForm').submit();
            }
        })
    }

    // SweetAlert Status Update
    function updateStatus(id, name, currentStatus) {
        const newStatus = currentStatus === 'Active' ? 'Inactive' : 'Active';
        Swal.fire({
            title: 'Change Status?',
            html: `Change ${name}'s status from ${currentStatus} to ${newStatus}?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#f59e0b',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, Change It!'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('statusCustomerId').value = id;
                document.getElementById('newStatus').value = newStatus;
                document.getElementById('statusForm').submit();
            }
        })
    }

    // Archive/Unarchive Customer
    function toggleArchive(id, name, isArchived) {
        const action = isArchived ? 'unarchive' : 'archive';
        Swal.fire({
            title: `${action.charAt(0).toUpperCase() + action.slice(1)} Customer?`,
            html: `You are about to ${action} ${name}.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: isArchived ? '#10b981' : '#6b7280',
            cancelButtonColor: '#6c757d',
            confirmButtonText: `Yes, ${action.charAt(0).toUpperCase() + action.slice(1)}!`
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('archiveCustomerId').value = id;
                document.getElementById('archiveValue').value = isArchived ? 0 : 1;
                document.getElementById('archiveForm').submit();
            }
        })
    }

    // Reset Password
    function resetPassword(id, name) {
        document.getElementById('resetCustomerId').value = id;
        document.getElementById('resetCustomerName').textContent = name;
        document.getElementById('new_password').value = '';
        document.getElementById('confirm_password').value = '';
        new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
    }

    // Edit Customer
    function editCustomer(id) {
        // Fetch customer data via AJAX
        fetch(`get_customer_data.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('editCustomerId').value = data.customer.id;
                    document.getElementById('edit_username').value = data.customer.username;
                    document.getElementById('edit_email').value = data.customer.email;
                    document.getElementById('edit_phone').value = data.customer.phone;
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
                Swal.fire('Error', 'Failed to fetch customer data', 'error');
            });
    }

    // View Customer Profile
    function viewCustomer(id) {
        fetch(`get_customer_data.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const customer = data.customer;
                    const content = `
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Customer ID:</strong> ${customer.id}</p>
                                <p><strong>Full Name:</strong> ${customer.username}</p>
                                <p><strong>Email:</strong> ${customer.email}</p>
                                <p><strong>Phone:</strong> ${customer.phone}</p>
                                <p><strong>Address:</strong> ${customer.address}</p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Birthdate:</strong> ${customer.birthdate ? new Date(customer.birthdate).toLocaleDateString() : 'Not specified'}</p>
                                <p><strong>Gender:</strong> ${customer.gender || 'Not specified'}</p>
                                <p><strong>Status:</strong> <span class="badge ${customer.status === 'Active' ? 'bg-success' : 'bg-secondary'}">${customer.status}</span></p>
                                <p><strong>Total Bookings:</strong> ${customer.total_bookings}</p>
                                <p><strong>Member Since:</strong> ${new Date(customer.created_at).toLocaleDateString()}</p>
                            </div>
                        </div>
                    `;
                    document.getElementById('viewCustomerContent').innerHTML = content;
                    new bootstrap.Modal(document.getElementById('viewCustomerModal')).show();
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Failed to fetch customer data', 'error');
            });
    }

    // Password confirmation validation
    document.getElementById('resetPasswordModal').addEventListener('submit', function(e) {
        const password = document.getElementById('new_password').value;
        const confirm = document.getElementById('confirm_password').value;
        
        if (password !== confirm) {
            e.preventDefault();
            Swal.fire('Error', 'Passwords do not match', 'error');
        } else if (password.length < 6) {
            e.preventDefault();
            Swal.fire('Error', 'Password must be at least 6 characters', 'error');
        }
    });
</script>

<script>
    // Animate table rows on load
    document.addEventListener('DOMContentLoaded', function() {
        const rows = document.querySelectorAll('tbody tr');
        rows.forEach((row, index) => {
            row.style.opacity = '0';
            row.style.transform = 'translateX(-20px)';
            setTimeout(() => {
                row.style.transition = 'all 0.4s ease';
                row.style.opacity = '1';
                row.style.transform = 'translateX(0)';
            }, index * 50);
        });
        
        // Add hover effect to filter buttons
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('mouseenter', function() {
                if (!this.classList.contains('active')) {
                    this.style.transform = 'translateY(-2px)';
                    this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
                }
            });
            btn.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = 'none';
            });
        });
    });
</script>

<?php require 'admin_sidebar_footer.php'; ?>