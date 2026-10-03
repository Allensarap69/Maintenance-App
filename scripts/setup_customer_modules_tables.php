<?php
require __DIR__ . '/../db.php';

echo "<h2>Customer Modules Tables Setup</h2>";
echo "<hr>";

// Read and execute the SQL file
$sql_file = 'create_customer_modules_tables.sql';
$sql = file_get_contents($sql_file);

if ($sql === false) {
    die("Error reading SQL file: " . error_get_last()['message']);
}

try {
    $pdo->exec($sql);
    echo "✅ Customer modules tables created successfully!<br>";
    echo "Tables created: maintenance_history, emergency_service_requests, motorcycle_health_scores, emergency_request_updates<br>";
    echo "<hr>";
    echo "<p><strong>Note:</strong> These tables support the new customer-facing modules for maintenance history, emergency service requests, and health score tracking.</p>";
    echo "<p><a href='manage_customers_motorcycles.php'>Go to Customer Management</a></p>";
} catch (PDOException $e) {
    echo "❌ Error creating customer modules tables: " . $e->getMessage() . "<br>";
    echo "<p>Please check your database connection and permissions.</p>";
}
?>