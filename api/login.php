<?php
// JSON login endpoint for the React app — mirrors the POST handling in
// index.php (CSRF, progressive delay, MD5->bcrypt upgrade, audit log).
// POST (urlencoded): email, password, csrf_token
require __DIR__ . '/../db.php';
require __DIR__ . '/../security.php';
require __DIR__ . '/../management_helper.php';

header('Content-Type: application/json');

/**
 * Secure password verification - supports both old MD5 and new bcrypt
 */
function verify_password($password, $hash) {
    // Check if it's a bcrypt hash (starts with $2y$)
    if (strpos($hash, '$2y$') === 0 || strpos($hash, '$2a$') === 0) {
        return password_verify($password, $hash);
    }
    // Check if it's MD5 (32 hex characters)
    elseif (strlen($hash) === 32 && ctype_xdigit($hash)) {
        return hash_equals($hash, md5($password));
    }
    // Plain text comparison (for very old entries - should be migrated)
    else {
        return hash_equals($hash, $password);
    }
}

function delay_payload($info) {
    return [
        'delay' => $info['delay'] ?? 0,
        'locked' => $info['locked'] ?? false,
        'attempts' => $info['attempts'] ?? 0,
        'remaining' => $info['remaining'] ?? 0,
    ];
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

$email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
$password = $_POST['password'] ?? '';

// Progressive delay check (server-side, keyed on email + IP)
$delay_info = check_login_delay($pdo, $email);

if ($delay_info['locked'] || $delay_info['delay'] > 0) {
    echo json_encode(['success' => false, 'message' => $delay_info['message'], 'delay' => delay_payload($delay_info)]);
    exit;
}

if (empty($_POST['email']) || empty($_POST['password'])) {
    echo json_encode(['success' => false, 'message' => 'Please enter both email and password.', 'delay' => delay_payload($delay_info)]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, role, username, password FROM users WHERE email=?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !verify_password($password, $user['password'])) {
        $user = null;
    }

    if ($user) {
        // Transparently upgrade weak MD5/plaintext hashes to bcrypt
        $stored_hash = $user['password'];
        $is_bcrypt = strpos($stored_hash, '$2y$') === 0 || strpos($stored_hash, '$2a$') === 0;
        if (!$is_bcrypt) {
            try {
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                    ->execute([password_hash($password, PASSWORD_BCRYPT), $user['id']]);
            } catch (PDOException $e) {
                error_log("Password rehash failed for user {$user['id']}: " . $e->getMessage());
            }
        }

        // Successful login: clear failed attempts
        clear_login_rate_limit($pdo, $email);

        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role']    = $user['role'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['last_login'] = time();

        log_audit($pdo, 'login_success', 'user', $user['id'], $email);

        $dashboard_files = [
            'admin' => 'dashboard_admin.php',
            'mechanic' => 'dashboard_mechanic.php',
            'customer' => 'dashboard_customer.php'
        ];
        echo json_encode([
            'success' => true,
            'role' => $user['role'],
            'redirect' => $dashboard_files[$user['role']] ?? 'dashboard_customer.php'
        ]);
        exit;
    }

    // Failed login - record attempt and report new delay
    record_failed_login($pdo, $email);
    log_audit($pdo, 'login_failed', 'user', null, $email);
    $delay_info = check_login_delay($pdo, $email);

    $msg = 'Invalid email or password.';
    if ($delay_info['delay'] > 0) {
        $msg = 'Invalid credentials. ' . $delay_info['message'];
    }
    echo json_encode(['success' => false, 'message' => $msg, 'delay' => delay_payload($delay_info)]);
} catch (PDOException $e) {
    error_log("Login error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
}
