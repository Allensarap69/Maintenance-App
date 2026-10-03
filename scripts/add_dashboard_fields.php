<?php
/**
 * Add Dashboard Fields to Database
 * Run this script to add the necessary fields for the customer dashboard features
 */
require __DIR__ . '/../db.php';

echo "=== ADDING DASHBOARD FIELDS TO MOTORCYCLES TABLE ===\n\n";

try {
    // Add warranty status field
    echo "1. Adding warranty_status field...\n";
    $pdo->exec("ALTER TABLE motorcycles ADD COLUMN IF NOT EXISTS warranty_status ENUM('active', 'expired', 'none') DEFAULT 'none'");
    echo "   ✓ warranty_status field added\n";
} catch (PDOException $e) {
    echo "   ✗ Error adding warranty_status: " . $e->getMessage() . "\n";
}

try {
    // Add warranty expiry date
    echo "2. Adding warranty_expiry_date field...\n";
    $pdo->exec("ALTER TABLE motorcycles ADD COLUMN IF NOT EXISTS warranty_expiry_date DATE NULL");
    echo "   ✓ warranty_expiry_date field added\n";
} catch (PDOException $e) {
    echo "   ✗ Error adding warranty_expiry_date: " . $e->getMessage() . "\n";
}

try {
    // Add last maintenance date
    echo "3. Adding last_maintenance_date field...\n";
    $pdo->exec("ALTER TABLE motorcycles ADD COLUMN IF NOT EXISTS last_maintenance_date DATE NULL");
    echo "   ✓ last_maintenance_date field added\n";
} catch (PDOException $e) {
    echo "   ✗ Error adding last_maintenance_date: " . $e->getMessage() . "\n";
}

try {
    // Add next maintenance date
    echo "4. Adding next_maintenance_date field...\n";
    $pdo->exec("ALTER TABLE motorcycles ADD COLUMN IF NOT EXISTS next_maintenance_date DATE NULL");
    echo "   ✓ next_maintenance_date field added\n";
} catch (PDOException $e) {
    echo "   ✗ Error adding next_maintenance_date: " . $e->getMessage() . "\n";
}

try {
    // Add maintenance interval
    echo "5. Adding maintenance_interval_months field...\n";
    $pdo->exec("ALTER TABLE motorcycles ADD COLUMN IF NOT EXISTS maintenance_interval_months INT DEFAULT 6 COMMENT 'Months between scheduled maintenance'");
    echo "   ✓ maintenance_interval_months field added\n";
} catch (PDOException $e) {
    echo "   ✗ Error adding maintenance_interval_months: " . $e->getMessage() . "\n";
}

try {
    // Add health score
    echo "6. Adding health_score field...\n";
    $pdo->exec("ALTER TABLE motorcycles ADD COLUMN IF NOT EXISTS health_score INT DEFAULT 100 COMMENT 'Vehicle health score (0-100)'");
    echo "   ✓ health_score field added\n";
} catch (PDOException $e) {
    echo "   ✗ Error adding health_score: " . $e->getMessage() . "\n";
}

try {
    // Add last service mileage
    echo "7. Adding last_service_mileage field...\n";
    $pdo->exec("ALTER TABLE motorcycles ADD COLUMN IF NOT EXISTS last_service_mileage INT NULL");
    echo "   ✓ last_service_mileage field added\n";
} catch (PDOException $e) {
    echo "   ✗ Error adding last_service_mileage: " . $e->getMessage() . "\n";
}

echo "\n=== DATABASE UPDATE COMPLETE ===\n";
echo "You can now use the customer dashboard features.\n";
?>