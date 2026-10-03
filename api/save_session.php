<?php
session_start();
header('Content-Type: application/json');

// Read JSON data from the fetch request
$data = json_decode(file_get_contents("php://input"), true);

if ($data) {
    // Save the data to session
    $_SESSION['temp_service_ids'] = $data['service_ids'] ?? [];
    $_SESSION['temp_vehicle_type'] = $data['vehicle_type'] ?? '';
    $_SESSION['temp_duration'] = intval($data['duration'] ?? 0);
    
    echo json_encode(['status' => 'success', 'message' => 'Selections saved.']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'No data received.']);
}
?>