<?php
require __DIR__ . '/../db.php';

// Check motorcycles table structure
echo "=== MOTORCYCLES TABLE STRUCTURE ===\n";
try {
    $stmt = $pdo->query('DESCRIBE motorcycles');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        echo $column['Field'] . " - " . $column['Type'] . "\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Check if warranty_status field exists
echo "\n=== CHECKING FOR WARRANTY_STATUS FIELD ===\n";
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM motorcycles LIKE 'warranty_status'");
    $result = $stmt->fetch();
    if ($result) {
        echo "warranty_status field EXISTS\n";
    } else {
        echo "warranty_status field DOES NOT EXIST\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Check if last_maintenance_date field exists
echo "\n=== CHECKING FOR LAST_MAINTENANCE_DATE FIELD ===\n";
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM motorcycles LIKE 'last_maintenance_date'");
    $result = $stmt->fetch();
    if ($result) {
        echo "last_maintenance_date field EXISTS\n";
    } else {
        echo "last_maintenance_date field DOES NOT EXIST\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Check if next_maintenance_date field exists
echo "\n=== CHECKING FOR NEXT_MAINTENANCE_DATE FIELD ===\n";
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM motorcycles LIKE 'next_maintenance_date'");
    $result = $stmt->fetch();
    if ($result) {
        echo "next_maintenance_date field EXISTS\n";
    } else {
        echo "next_maintenance_date field DOES NOT EXIST\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>