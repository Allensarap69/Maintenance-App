<?php
require __DIR__ . '/../db.php';

echo "<h2>Check Motorcycles Table</h2>";
echo "<hr>";

try {
    // Check if motorcycles table exists
    $tableExists = $pdo->query("SHOW TABLES LIKE 'motorcycles'")->fetch();
    
    if (!$tableExists) {
        echo "<p class='text-danger'>❌ The 'motorcycles' table does not exist in the database.</p>";
        echo "<p>This table is required for the warranty module to function properly.</p>";
    } else {
        echo "<p class='text-success'>✅ The 'motorcycles' table exists.</p>";
        
        // Check if there are any motorcycles
        $count = $pdo->query("SELECT COUNT(*) FROM motorcycles")->fetchColumn();
        echo "<p>Total motorcycles in database: <strong>$count</strong></p>";
        
        if ($count == 0) {
            echo "<p class='text-warning'>⚠️ No motorcycles found in the database.</p>";
            echo "<p>You need to add motorcycles first before you can register warranties.</p>";
            echo "<p><a href='manage_motorcycles.php' class='btn btn-primary'>Go to Manage Motorcycles</a></p>";
        } else {
            echo "<h3>Available Motorcycles:</h3>";
            $motorcycles = $pdo->query("SELECT id, brand, model, year_model FROM motorcycles ORDER BY brand ASC")->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<table class='table table-bordered'>";
            echo "<thead><tr><th>ID</th><th>Brand</th><th>Model</th><th>Year</th></tr></thead>";
            echo "<tbody>";
            foreach ($motorcycles as $moto) {
                echo "<tr>";
                echo "<td>{$moto['id']}</td>";
                echo "<td>{$moto['brand']}</td>";
                echo "<td>{$moto['model']}</td>";
                echo "<td>{$moto['year_model']}</td>";
                echo "</tr>";
            }
            echo "</tbody></table>";
        }
    }
} catch (PDOException $e) {
    echo "<p class='text-danger'>❌ Database error: " . $e->getMessage() . "</p>";
}
?>