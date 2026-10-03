<?php
session_start();
require __DIR__ . '/../db.php';
require __DIR__ . '/../motorcycle_health_helper.php';

// Check if the user is logged in as Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if (!isset($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Customer ID is required']);
    exit;
}

$customer_id = intval($_GET['id']);

try {
    $include_motorcycles = isset($_GET['include_motorcycles']) && $_GET['include_motorcycles'] == '1';
    
    $stmt = $pdo->prepare("
        SELECT 
            u.id, 
            u.username, 
            u.name,
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
            u.id = ? AND u.role = 'customer'
        GROUP BY u.id
    ");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($customer) {
        $motorcycles = [];
        
        if ($include_motorcycles) {
            $stmt_moto = $pdo->prepare("
                SELECT id, user_id, brand, model, color, year_model, plate_number, 
                       engine_number, chassis_number, purchase_date, current_mileage, status,
                       warranty_status, warranty_expiry_date, last_maintenance_date, 
                       next_maintenance_date, maintenance_interval_months, maintenance_interval_km,
                       last_service_mileage, image, created_at, updated_at
                FROM motorcycles 
                WHERE user_id = ?
                ORDER BY created_at DESC
            ");
            $stmt_moto->execute([$customer_id]);
            $motorcycles = $stmt_moto->fetchAll(PDO::FETCH_ASSOC);

            foreach ($motorcycles as &$moto) {
                $moto['health_score'] = calculateHealthScore($moto);
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'customer' => $customer, 'motorcycles' => $motorcycles]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
    }
} catch (PDOException $e) {
    error_log("Error fetching customer data: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>