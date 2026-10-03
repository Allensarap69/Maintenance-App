<?php
require __DIR__ . '/../db.php';

echo "=== CHECKING TABLE STRUCTURES FOR FOREIGN KEYS ===\n\n";

// Check customers table structure
echo "=== CUSTOMERS TABLE STRUCTURE ===\n";
try {
    $stmt = $pdo->query('DESCRIBE customers');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        echo $column['Field'] . " - " . $column['Type'] . " - " . $column['Key'] . "\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== MOTORCYCLES TABLE STRUCTURE ===\n";
try {
    $stmt = $pdo->query('DESCRIBE motorcycles');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        echo $column['Field'] . " - " . $column['Type'] . " - " . $column['Key'] . "\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== CHECKING EXISTING TABLES ===\n";
try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        echo "- " . $table . "\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>