<?php
require __DIR__ . '/../db.php';

echo "<h2>Service Package Tables Setup</h2>";
echo "<hr>";

// Read and execute the SQL file
$sql_file = 'create_service_package_tables.sql';
$sql = file_get_contents($sql_file);

if ($sql === false) {
    die("Error reading SQL file: " . error_get_last()['message']);
}

try {
    $pdo->exec($sql);
    echo "✅ Service package tables created successfully!<br>";
    echo "Tables created: service_packages, service_package_items<br>";
    echo "<hr>";
    echo "<p><strong>Note:</strong> Sample data has been inserted for testing purposes.</p>";
    echo "<p><a href='admin_service_packages.php'>Go to Service Package Management</a></p>";
} catch (PDOException $e) {
    echo "❌ Error creating service package tables: " . $e->getMessage() . "<br>";
    echo "<p>Please check your database connection and permissions.</p>";
}
?>