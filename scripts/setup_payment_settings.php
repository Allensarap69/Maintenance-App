<?php
require __DIR__ . '/../db.php';

echo "<h2>Payment Settings Table Setup</h2>";
echo "<hr>";

// EMV/QR Ph payload decoded from the current g.png.jpg GCash QR image.
$default_payload = '00020101021127830012com.p2pqrpay0111GXCHPHM2XXX02089996440303152170200000006560417DWQM4TK3JDO7CBK0B5204601653036085802PH5910NO****A G.6007Glamang61041234630489FE';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_settings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            gcash_name VARCHAR(100) NOT NULL DEFAULT '',
            gcash_number VARCHAR(20) NOT NULL DEFAULT '',
            qr_image_path VARCHAR(255) NOT NULL DEFAULT 'g.png.jpg',
            emv_payload TEXT NULL,
            deposit_percentage DECIMAL(5,2) NOT NULL DEFAULT 50.00,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ payment_settings table created (or already exists).<br>";

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO payment_settings
            (id, gcash_name, gcash_number, qr_image_path, emv_payload, deposit_percentage)
        VALUES (1, '', '', 'g.png.jpg', ?, 50.00)
    ");
    $stmt->execute([$default_payload]);
    echo "✅ Default settings row seeded.<br>";

    echo "<hr>";
    echo "<p><a href='../admin_payment_settings.php'>Go to Payment Settings</a></p>";
} catch (PDOException $e) {
    echo "❌ Error creating payment_settings table: " . $e->getMessage() . "<br>";
}
?>
