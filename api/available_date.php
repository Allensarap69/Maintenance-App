<?php
require __DIR__ . '/../db.php';

// Example: fetch available slots
$stmt = $pdo->query("SELECT id, date, start_time, end_time FROM slots WHERE is_booked=0");
$slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($slots);
