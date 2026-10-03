<?php
session_start();
require __DIR__ . '/../db.php'; 

// Security check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access.']);
    exit;
}

header('Content-Type: application/json');

$mechanic_id = $_GET['mechanic_id'] ?? null;
$booking_date = $_GET['booking_date'] ?? null;
$booking_id = $_GET['booking_id'] ?? null; 
// Crucial: The duration is now dynamic from the frontend (assumed 60 min for demo)
$required_duration = intval($_GET['duration'] ?? 60); 

if (!$mechanic_id || !$booking_date || $required_duration <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters (mechanic, date, duration).']);
    exit;
}

// -----------------------------------------------------------
// --- CONFIGURATION ---
// -----------------------------------------------------------
$start_hour = 8; // Shop opens at 8 AM
$end_hour = 17;  // Shop closes at 5 PM (Services must end by this time)
$slot_increment_minutes = 30; // Check slots every 30 minutes
// NOTE: Assuming all EXISTING accepted bookings have a default duration of 60 minutes.
// In a real system, you must fetch the actual duration from the database for existing bookings.
$DEFAULT_EXISTING_DURATION = 60; 

// -----------------------------------------------------------
// --- 1. Fetch Existing Bookings and Calculate Blocked Time Ranges ---
// -----------------------------------------------------------
$booked_time_ranges = [];
try {
    // Fetch all accepted bookings for the chosen mechanic on that date, excluding the current booking if it's being rescheduled
    $stmt = $pdo->prepare("SELECT id, schedule_start_time FROM bookings 
                           WHERE mechanic_id = ? AND schedule_date = ? 
                           AND status = 'accepted' 
                           AND id != ?"); 
                           
    $stmt->execute([$mechanic_id, $booking_date, $booking_id]);
    $existing_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($existing_bookings as $booking) {
        $start_timestamp = strtotime($booking_date . ' ' . $booking['schedule_start_time']);
        // Calculate the end time of the existing booking
        $end_timestamp = $start_timestamp + ($DEFAULT_EXISTING_DURATION * 60); 

        $booked_time_ranges[] = [
            'booking_id' => $booking['id'], 
            'start' => $start_timestamp, // Start time in seconds
            'end' => $end_timestamp      // End time in seconds
        ];
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// -----------------------------------------------------------
// --- 2. Generate ALL Time Slots (Available or Blocked) ---
// -----------------------------------------------------------
$all_slots_status = [];
$now = new DateTime();
$target_date = new DateTime($booking_date);
$is_today = $target_date->format('Y-m-d') === $now->format('Y-m-d');
$shop_end_timestamp = strtotime($booking_date . ' ' . sprintf('%02d:00:00', $end_hour));

for ($hour = $start_hour; $hour < $end_hour; $hour++) {
    for ($minute = 0; $minute < 60; $minute += $slot_increment_minutes) {
        
        $slot_start_time = sprintf('%02d:%02d:00', $hour, $minute);
        $slot_start_timestamp = strtotime($booking_date . ' ' . $slot_start_time);
        
        // Calculate the proposed END time for the NEW booking
        $slot_end_timestamp = $slot_start_timestamp + ($required_duration * 60);

        $slot_info = [
            'time_value' => $slot_start_time, 
            'time_display' => date('h:i A', $slot_start_timestamp) . " - " . date('h:i A', $slot_end_timestamp),
            'status' => 'available', // Default status
            'conflict_reason' => ''
        ];
        
        // Check 1: Shop Hours Limit (Service must finish before shop close time)
        if ($slot_end_timestamp > $shop_end_timestamp) {
            $slot_info['status'] = 'blocked';
            $slot_info['conflict_reason'] = 'Shop Closed (Service ends past ' . date('h:i A', $shop_end_timestamp) . ')';
        }

        // Check 2: Past Time Limit (Cannot schedule in the past)
        elseif ($is_today && $slot_start_timestamp <= $now->getTimestamp()) {
            $slot_info['status'] = 'blocked';
            $slot_info['conflict_reason'] = 'Time in the Past';
        }

        // Check 3: Conflict with Existing Accepted Bookings
        else {
            foreach ($booked_time_ranges as $booked) {
                // Overlap logic: (new_start < existing_end) AND (new_end > existing_start)
                if ($slot_start_timestamp < $booked['end'] && $slot_end_timestamp > $booked['start']) {
                    $slot_info['status'] = 'blocked';
                    $slot_info['conflict_reason'] = 'Conflict: Booking #' . $booked['booking_id'];
                    break;
                }
            }
        }
        
        $all_slots_status[] = $slot_info;
    }
}

// -----------------------------------------------------------
// --- 3. Return Results ---
// -----------------------------------------------------------
echo json_encode([
    'success' => true,
    'duration_minutes' => $required_duration,
    'slots' => $all_slots_status, // Return ALL slots and their status
    'booked_ranges_count' => count($booked_time_ranges)
]);
?>