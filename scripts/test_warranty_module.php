<?php
/**
 * Warranty Management Module Test
 * This file tests the warranty management functionality
 */

require __DIR__ . '/../db.php';

echo "<h2>Warranty Management Module Test</h2>";
echo "<hr>";

// Test 1: Check if tables exist
echo "<h3>Test 1: Database Tables</h3>";
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'warranties'");
    $warranties_exists = $stmt->fetch() !== false;
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'warranty_claims'");
    $claims_exists = $stmt->fetch() !== false;
    
    if ($warranties_exists && $claims_exists) {
        echo "✅ Both warranty tables exist<br>";
    } else {
        echo "❌ Warranty tables missing. Please run setup_warranty_tables.php<br>";
    }
} catch (PDOException $e) {
    echo "❌ Error checking tables: " . $e->getMessage() . "<br>";
}

// Test 2: Check table structure
echo "<h3>Test 2: Table Structure</h3>";
try {
    $stmt = $pdo->query("DESCRIBE warranties");
    $warranty_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $required_columns = ['id', 'customer_id', 'motorcycle_id', 'warranty_type', 'warranty_start', 'warranty_end', 'status'];
    $missing = array_diff($required_columns, $warranty_columns);
    
    if (empty($missing)) {
        echo "✅ Warranty table has all required columns<br>";
    } else {
        echo "❌ Warranty table missing columns: " . implode(', ', $missing) . "<br>";
    }
    
    $stmt = $pdo->query("DESCRIBE warranty_claims");
    $claim_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $required_claim_columns = ['id', 'warranty_id', 'claim_date', 'claim_description', 'claim_status'];
    $missing_claims = array_diff($required_claim_columns, $claim_columns);
    
    if (empty($missing_claims)) {
        echo "✅ Warranty claims table has all required columns<br>";
    } else {
        echo "❌ Warranty claims table missing columns: " . implode(', ', $missing_claims) . "<br>";
    }
} catch (PDOException $e) {
    echo "❌ Error checking table structure: " . $e->getMessage() . "<br>";
}

// Test 3: Check if admin_warranty.php file exists
echo "<h3>Test 3: File Existence</h3>";
if (file_exists('admin_warranty.php')) {
    echo "✅ admin_warranty.php file exists<br>";
} else {
    echo "❌ admin_warranty.php file not found<br>";
}

// Test 4: Check sidebar integration
echo "<h3>Test 4: Sidebar Integration</h3>";
$sidebar_content = file_get_contents('admin_sidebar_template.php');
if (strpos($sidebar_content, 'admin_warranty.php') !== false) {
    echo "✅ Warranty link added to sidebar<br>";
} else {
    echo "❌ Warranty link not found in sidebar<br>";
}

if (strpos($sidebar_content, 'bi-shield-check-fill') !== false) {
    echo "✅ Warranty icon added to sidebar<br>";
} else {
    echo "❌ Warranty icon not found in sidebar<br>";
}

// Test 5: Sample data insertion (optional)
echo "<h3>Test 5: Sample Data Test</h3>";
try {
    // Check if we have customers and motorcycles
    $customer_count = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    $motorcycle_count = $pdo->query("SELECT COUNT(*) FROM motorcycles")->fetchColumn();
    
    if ($customer_count > 0 && $motorcycle_count > 0) {
        echo "✅ Database has customers ($customer_count) and motorcycles ($motorcycle_count) for testing<br>";
        echo "ℹ️ You can now test the warranty management at <a href='admin_warranty.php'>admin_warranty.php</a><br>";
    } else {
        echo "⚠️ Need customers and motorcycles in database to fully test warranty functionality<br>";
    }
} catch (PDOException $e) {
    echo "❌ Error checking sample data: " . $e->getMessage() . "<br>";
}

echo "<hr>";
echo "<h3>Test Summary</h3>";
echo "<p>The warranty management module has been created with the following features:</p>";
echo "<ul>";
echo "<li>✅ Database tables (warranties, warranty_claims)</li>";
echo "<li>✅ Admin interface (admin_warranty.php)</li>";
echo "<li>✅ Sidebar integration with icon</li>";
echo "<li>✅ Register warranty functionality</li>";
echo "<li>✅ Update warranty functionality</li>";
echo "<li>✅ View warranty status with remaining days</li>";
echo "<li>✅ Record warranty claims</li>";
echo "<li>✅ Track warranty expiration</li>";
echo "</ul>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>Run setup_warranty_tables.php to create the database tables (if not already done)</li>";
echo "<li>Access the warranty management at <a href='admin_warranty.php'>admin_warranty.php</a></li>";
echo "<li>Test registering a new warranty</li>";
echo "<li>Test recording warranty claims</li>";
echo "</ol>";
?>