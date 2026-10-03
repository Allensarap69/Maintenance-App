<?php
/**
 * Health Score v2 Database Setup
 * Run once to add the inspection table and mileage interval column.
 */
require __DIR__ . '/../db.php';

echo "=== Health Score v2 Database Setup ===\n\n";

try {
    echo "1. Adding maintenance_interval_km to motorcycles...\n";
    $pdo->exec("ALTER TABLE motorcycles ADD COLUMN IF NOT EXISTS maintenance_interval_km INT DEFAULT 5000 COMMENT 'KM between scheduled maintenance'");
    $pdo->exec("UPDATE motorcycles SET maintenance_interval_km = 5000 WHERE maintenance_interval_km IS NULL");
    echo "   Done.\n";
} catch (PDOException $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

try {
    echo "2. Creating motorcycle_inspections table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS motorcycle_inspections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            motorcycle_id INT NOT NULL,
            inspection_date DATE NOT NULL,
            engine ENUM('Good','Fair','Needs Attention','Critical') DEFAULT 'Good',
            brakes ENUM('Good','Fair','Needs Attention','Critical') DEFAULT 'Good',
            tires ENUM('Good','Fair','Needs Attention','Critical') DEFAULT 'Good',
            battery ENUM('Good','Fair','Needs Attention','Critical') DEFAULT 'Good',
            lights ENUM('Good','Fair','Needs Attention','Critical') DEFAULT 'Good',
            suspension ENUM('Good','Fair','Needs Attention','Critical') DEFAULT 'Good',
            fluids ENUM('Good','Fair','Needs Attention','Critical') DEFAULT 'Good',
            mechanic_remarks TEXT,
            performed_by VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_motorcycle (motorcycle_id),
            INDEX idx_inspection_date (inspection_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "   Done.\n";
} catch (PDOException $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

echo "\n=== Setup Complete ===\n";
