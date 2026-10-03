<?php
// Test script to verify service package tables setup
require __DIR__ . '/../db.php';

echo "<h2>Service Package Database Test</h2>";
echo "<hr>";

try {
    // Check if tables exist
    $tables = $pdo->query("SHOW TABLES LIKE 'service_%'")->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($tables)) {
        echo "<p class='text-danger'>❌ Service package tables do not exist yet.</p>";
        echo "<p>Please run <a href='setup_service_package_tables.php'>setup_service_package_tables.php</a> to create the tables.</p>";
    } else {
        echo "<p class='text-success'>✅ Service package tables found: " . implode(', ', $tables) . "</p>";
        
        // Check data in service_packages
        $package_count = $pdo->query("SELECT COUNT(*) FROM service_packages")->fetchColumn();
        echo "<p>Service packages in database: <strong>$package_count</strong></p>";
        
        if ($package_count > 0) {
            echo "<h3>Sample Data:</h3>";
            $packages = $pdo->query("SELECT * FROM service_packages LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
            echo "<table class='table table-bordered'>";
            echo "<thead><tr><th>ID</th><th>Name</th><th>Price</th><th>Duration</th><th>Status</th></tr></thead>";
            echo "<tbody>";
            foreach ($packages as $pkg) {
                echo "<tr>";
                echo "<td>{$pkg['id']}</td>";
                echo "<td>{$pkg['package_name']}</td>";
                echo "<td>₱" . number_format($pkg['price'], 2) . "</td>";
                echo "<td>{$pkg['duration_minutes']} min</td>";
                echo "<td>{$pkg['status']}</td>";
                echo "</tr>";
            }
            echo "</tbody></table>";
        }
        
        echo "<hr>";
        echo "<p><a href='admin_service_packages.php' class='btn btn-primary'>Go to Service Package Management</a></p>";
    }
} catch (PDOException $e) {
    echo "<p class='text-danger'>❌ Database error: " . $e->getMessage() . "</p>";
}
?>