<?php
require __DIR__ . '/../db.php';

echo "<h2>Mechanic Login Setup</h2>";
echo "<hr>";

try {
    // 1. Add 'mechanic' to the role enum
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
    $roleCol = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($roleCol && strpos($roleCol['Type'], 'mechanic') === false) {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','customer','mechanic') DEFAULT 'customer'");
        echo "✅ Updated users.role enum to include 'mechanic'<br>";
    } else {
        echo "ℹ️ users.role already supports 'mechanic'<br>";
    }

    // 2. Add user_id to mechanics table
    $stmt = $pdo->query("SHOW COLUMNS FROM mechanics LIKE 'user_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE mechanics ADD COLUMN user_id INT NULL AFTER id");
        echo "✅ Added user_id column to mechanics table<br>";
    } else {
        echo "ℹ️ user_id column already exists in mechanics table<br>";
    }

    // 3. Backfill existing mechanics with user records (optional, so they can log in)
    $stmt = $pdo->query("SELECT m.id, m.name FROM mechanics m LEFT JOIN users u ON m.user_id = u.id WHERE m.user_id IS NULL OR u.id IS NULL");
    $orphans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($orphans)) {
        $insertUser = $pdo->prepare("INSERT INTO users (username, name, email, password, phone, address, role, status, archived) VALUES (?, ?, ?, ?, ?, ?, 'mechanic', 'Active', 0)");
        $updateMech = $pdo->prepare("UPDATE mechanics SET user_id = ? WHERE id = ?");
        foreach ($orphans as $mechanic) {
            $safeEmail = 'mechanic' . $mechanic['id'] . '@shop.local';
            $hashed = password_hash('mechanic123', PASSWORD_BCRYPT);
            $username = preg_replace('/[^a-zA-Z0-9]/', '', $mechanic['name']) . $mechanic['id'];
            $insertUser->execute([$username, $mechanic['name'], $safeEmail, $hashed, '', '']);
            $newUserId = $pdo->lastInsertId();
            $updateMech->execute([$newUserId, $mechanic['id']]);
        }
        echo "✅ Backfilled " . count($orphans) . " existing mechanic(s) with user accounts<br>";
    } else {
        echo "ℹ️ No orphan mechanics to backfill<br>";
    }

    echo "<hr><p><strong>Setup complete.</strong> Mechanics can now be added with login credentials.</p>";
    echo "<p><a href='manage_mechanics.php'>Go to Manage Mechanics</a></p>";
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
    echo "<p>Please check your database connection and permissions.</p>";
}
?>