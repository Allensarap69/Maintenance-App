<?php
$host = getenv('DB_HOST') ?: "localhost";
$port = getenv('DB_PORT') ?: "3306";
$user = getenv('DB_USER') ?: "root";   // change if you have a different MySQL user
$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : "";       // add password if you set one
$db   = getenv('DB_NAME') ?: "maintenance_db";

$options = [];
if (getenv('DB_SSL') === '1' || getenv('DB_SSL') === 'true') {
    $options[PDO::MYSQL_ATTR_SSL_CA] = getenv('DB_SSL_CA') ?: '/etc/ssl/certs/ca-certificates.crt';
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
}

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("We're having trouble connecting right now. Please try again later.");
}
?>