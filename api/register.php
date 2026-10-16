<?php
// JSON registration endpoint for the React app — mirrors the POST
// handling in index.php (CSRF, phone validation, bcrypt hashing).
// POST (urlencoded): reg_username, reg_email, reg_password, reg_phone,
//                    reg_address, csrf_token
require __DIR__ . '/../db.php';
require __DIR__ . '/../security.php';
require __DIR__ . '/../management_helper.php';

header('Content-Type: application/json');

/**
 * Sanitize user input to prevent XSS
 */
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!verify_csrf_token()) {
    echo json_encode([
        'success' => false,
        'csrf_error' => true,
        'message' => 'Security validation failed. Please refresh the page and try again.'
    ]);
    exit;
}

if (empty($_POST['reg_password']) || empty($_POST['reg_username']) || empty($_POST['reg_email']) || empty($_POST['reg_phone']) || empty($_POST['reg_address'])) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
    exit;
}

$username = $_POST['reg_username'];
$email    = $_POST['reg_email'];
$password = password_hash($_POST['reg_password'], PASSWORD_BCRYPT);
$phone    = preg_replace('/\D/', '', $_POST['reg_phone']); // digits only
$address  = sanitize_input($_POST['reg_address']);

if (!preg_match('/^09\d{9}$/', $phone)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid 11-digit mobile number starting with 09 (e.g., 0917 123 4567).']);
    exit;
}

try {
    $check = $pdo->prepare("SELECT id FROM users WHERE email=?");
    $check->execute([$email]);

    if ($check->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'Email already registered. Please use a different email or login.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO users (username,email,password,phone,address,role) VALUES (?,?,?,?,?,'customer')");
    $stmt->execute([$username, $email, $password, $phone, $address]);
    log_audit($pdo, 'user_registered', 'user', $pdo->lastInsertId(), $email);

    echo json_encode(['success' => true, 'message' => 'Registration successful! You can now login.']);
} catch (PDOException $e) {
    error_log("Registration error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred during registration. Please try again.']);
}
