<?php
require __DIR__ . '/../db.php';

echo "<h2>Warranty Tables Setup</h2>";
echo "<hr>";

// Read and execute the SQL file
$sql_file = 'create_warranty_tables.sql';
$sql = file_get_contents($sql_file);

if ($sql === false) {
    die("Error reading SQL file: " . error_get_last()['message']);
}

try {
    $pdo->exec($sql);
    echo "✅ Warranty tables created successfully!<br>";
    echo "Tables created: warranties, warranty_claims<br>";
    echo "<hr>";
    echo "<p><strong>Note:</strong> Foreign key constraints were not included to ensure compatibility with your existing database structure. Data integrity will be handled through application validation.</p>";
    echo "<p><a href='admin_warranty.php'>Go to Warranty Management</a></p>";
} catch (PDOException $e) {
    echo "❌ Error creating warranty tables: " . $e->getMessage() . "<br>";
    echo "<p>Please check your database connection and permissions.</p>";
}
?>