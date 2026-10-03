<?php
session_start();
require __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$mechanic_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$mechanic_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid mechanic ID']);
    exit;
}

try {
    // Fetch mechanic details with user account info
    $stmt = $pdo->prepare("
        SELECT m.*, u.username, u.email, u.phone, u.address
        FROM mechanics m
        LEFT JOIN users u ON m.user_id = u.id
        WHERE m.id = ?
    ");
    $stmt->execute([$mechanic_id]);
    $mechanic = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mechanic) {
        echo json_encode(['success' => false, 'error' => 'Mechanic not found']);
        exit;
    }
    
    // Fetch mechanic specialty IDs
    $spec_stmt = $pdo->prepare("
        SELECT specialty_id 
        FROM mechanic_specialties 
        WHERE mechanic_id = ?
    ");
    $spec_stmt->execute([$mechanic_id]);
    $specialties = $spec_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Fetch mechanic specialty names
    $spec_name_stmt = $pdo->prepare("
        SELECT s.specialty_name
        FROM mechanic_specialties ms
        JOIN specialties s ON ms.specialty_id = s.id
        WHERE ms.mechanic_id = ?
        ORDER BY s.specialty_name ASC
    ");
    $spec_name_stmt->execute([$mechanic_id]);
    $specialty_names = $spec_name_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode([
        'success' => true,
        'mechanic' => [
            'id' => $mechanic['id'],
            'name' => $mechanic['name'],
            'username' => $mechanic['username'] ?? '',
            'status' => $mechanic['status'],
            'email' => $mechanic['email'] ?? '',
            'phone' => $mechanic['phone'] ?? '',
            'address' => $mechanic['address'] ?? '',
            'created_at' => $mechanic['created_at'] ?? '',
            'specialties' => $specialties,
            'specialty_names' => $specialty_names
        ]
    ]);
} catch (PDOException $e) {
    error_log("Error fetching mechanic data: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>