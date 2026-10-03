<?php
/**
 * Test Dashboard Features
 * This script tests the new customer dashboard features
 */

require __DIR__ . '/../db.php';
require __DIR__ . '/../motorcycle_health_helper.php';

echo "=== TESTING CUSTOMER DASHBOARD FEATURES ===\n\n";

// Test 1: Check if database fields exist
echo "Test 1: Checking database fields...\n";
try {
    $stmt = $pdo->query('DESCRIBE motorcycles');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'Field');
    
    $requiredFields = ['warranty_status', 'warranty_expiry_date', 'last_maintenance_date', 'next_maintenance_date', 'maintenance_interval_months', 'health_score', 'last_service_mileage'];
    
    foreach ($requiredFields as $field) {
        if (in_array($field, $columnNames)) {
            echo "  ✓ $field field exists\n";
        } else {
            echo "  ✗ $field field missing\n";
        }
    }
} catch (PDOException $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

// Test 2: Test health score calculation
echo "\nTest 2: Testing health score calculation...\n";
try {
    // Get a sample motorcycle
    $stmt = $pdo->query("SELECT * FROM motorcycles LIMIT 1");
    $motorcycle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($motorcycle) {
        $healthScore = calculateHealthScore($motorcycle);
        echo "  ✓ Health score calculated: $healthScore for motorcycle ID {$motorcycle['id']}\n";
    } else {
        echo "  ⚠ No motorcycles found to test\n";
    }
} catch (PDOException $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

// Test 3: Test warranty status
echo "\nTest 3: Testing warranty status...\n";
try {
    $stmt = $pdo->query("SELECT * FROM motorcycles LIMIT 1");
    $motorcycle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($motorcycle) {
        $warrantyStatus = getWarrantyStatus($motorcycle);
        echo "  ✓ Warranty status: {$warrantyStatus['status']}\n";
        echo "    Message: {$warrantyStatus['message']}\n";
        echo "    Valid: " . ($warrantyStatus['is_valid'] ? 'Yes' : 'No') . "\n";
    } else {
        echo "  ⚠ No motorcycles found to test\n";
    }
} catch (PDOException $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

// Test 4: Test maintenance schedule
echo "\nTest 4: Testing maintenance schedule...\n";
try {
    $stmt = $pdo->query("SELECT * FROM motorcycles LIMIT 1");
    $motorcycle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($motorcycle) {
        $maintenanceSchedule = getMaintenanceSchedule($motorcycle);
        echo "  ✓ Maintenance schedule calculated\n";
        echo "    Next date: " . ($maintenanceSchedule['next_date'] ?? 'Not set') . "\n";
        echo "    Days until: " . ($maintenanceSchedule['days_until'] ?? 'N/A') . "\n";
        echo "    Overdue: " . ($maintenanceSchedule['is_overdue'] ? 'Yes' : 'No') . "\n";
    } else {
        echo "  ⚠ No motorcycles found to test\n";
    }
} catch (PDOException $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

// Test 5: Test complete dashboard data
echo "\nTest 5: Testing complete dashboard data...\n";
try {
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'customer' LIMIT 1");
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($customer) {
        $dashboardData = getCustomerMotorcyclesDashboard($customer['id']);
        echo "  ✓ Retrieved dashboard data for customer ID {$customer['id']}\n";
        echo "    Number of motorcycles: " . count($dashboardData) . "\n";
        
        if (!empty($dashboardData)) {
            foreach ($dashboardData as $index => $data) {
                echo "    Motorcycle " . ($index + 1) . ":\n";
                echo "      Health Score: {$data['health_score']}\n";
                echo "      Warranty: {$data['warranty']['status']}\n";
                echo "      Maintenance: " . ($data['maintenance']['next_date'] ?? 'Not set') . "\n";
            }
        }
    } else {
        echo "  ⚠ No customers found to test\n";
    }
} catch (PDOException $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

echo "\n=== TEST COMPLETE ===\n";
echo "If all tests passed, the dashboard features are ready to use.\n";
echo "Run add_dashboard_fields.php if any database fields are missing.\n";
?>