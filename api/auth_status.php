<?php
// Tells the React landing page whether a session already exists so it
// can redirect to the right dashboard (same as the top of index.php).
require __DIR__ . '/../db.php';
require __DIR__ . '/../security.php';

header('Content-Type: application/json');

if (isset($_SESSION['user_id'])) {
    $user_role = $_SESSION['role'] ?? 'customer';
    $dashboard_files = [
        'admin' => 'dashboard_admin.php',
        'mechanic' => 'dashboard_mechanic.php',
        'customer' => 'dashboard_customer.php'
    ];
    echo json_encode([
        'logged_in' => true,
        'role' => $user_role,
        'username' => $_SESSION['username'] ?? '',
        'redirect' => $dashboard_files[$user_role] ?? 'dashboard_customer.php'
    ]);
} else {
    echo json_encode(['logged_in' => false]);
}
