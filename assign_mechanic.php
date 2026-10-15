<?php

session_start();
require 'db.php'; // Make sure this file correctly sets up your $pdo connection

// SMS integration
require_once 'sms_helper.php';
require_once 'sms_config.php';
require_once 'SMSTemplates.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// --- Helper Function (Copied from manage_bookings) ---

/**
 * Creates a record in the reports table for a given booking.
 */
function create_report_record($pdo, $booking_id, $report_type) {
    if ($report_type !== 'Confirmation Slip') return true; 

    try {
        // 1. Check if the report already exists
        $stmt = $pdo->prepare("SELECT id FROM reports WHERE booking_id = ? AND report_type = ?");
        $stmt->execute([$booking_id, $report_type]);
        if ($stmt->fetch()) { return true; }

        // 2. Fetch required details (price) from the booking
        $stmt = $pdo->prepare("SELECT total_price FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) { return false; }
        
        $final_amount = $booking['total_price'];
        $generated_by = $_SESSION['username'] ?? 'Admin System'; 
        $notes = 'Confirmation Slip Generated upon Appointment Acceptance';

        // 3. INSERT the new report record
        $sql = "INSERT INTO reports (booking_id, report_type, report_date, final_amount, detailed_notes, generated_by)
                VALUES (?, ?, CURDATE(), ?, ?, ?)";
                    
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$booking_id, $report_type, $final_amount, $notes, $generated_by]);

    } catch (PDOException $e) {
        error_log("Report Creation Error: " . $e->getMessage());
        return false;
    }
}


// ----------------------------------------------------------------------
// --- 1. Mechanic Assignment POST Logic (PROPOSE ASSIGNMENT) ---
// ----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_mechanic'])) {
    $booking_id = $_POST['booking_id'] ?? null;
    $mechanic_id = $_POST['mechanic_id'] ?? null;
    // NEW: Capture the selected time slot
    $schedule_start_time = $_POST['schedule_start_time'] ?? null; 

    if (!$booking_id || !$mechanic_id || !$schedule_start_time) {
        $msg = "❌ Invalid Booking ID, Mechanic ID, or Schedule Time provided.";
        $msg_type = "error";
    } else {
        $stmt_name = $pdo->prepare("SELECT name FROM mechanics WHERE id = ?");
        $stmt_name->execute([$mechanic_id]);
        $mechanic_name = $stmt_name->fetchColumn();

        if ($mechanic_name) {
            // Store PROPOSAL in session, including the new time
            $_SESSION['proposed_assignment'] = [
                'booking_id' => $booking_id,
                'mechanic_id' => $mechanic_id,
                'mechanic_name' => $mechanic_name,
                'schedule_start_time' => $schedule_start_time // Save the proposed time
            ];

            $display_time = date('h:i A', strtotime($schedule_start_time));
            $msg = "✅ Proposed assignment for Booking #{$booking_id} to {$mechanic_name} at **{$display_time}** saved! Click **CONFIRM APPOINTMENT** on the card to finalize it.";
            $msg_type = "success";
        } else {
            $msg = "❌ Proposed assignment failed. Mechanic not found.";
            $msg_type = "error";
        }
    }
    // Redirect back to the display page
    header("Location: manage_bookings.php?msg=" . urlencode($msg) . "&type=" . urlencode($msg_type) . "&proposed=" . $booking_id);
    exit;
}

// ----------------------------------------------------------------------
// --- 2. CONFIRM APPOINTMENT Logic (FINALIZE ACCEPTANCE) ---
// ----------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'finalize_accept' && isset($_SESSION['proposed_assignment'])) {
    
    $booking_id = $_SESSION['proposed_assignment']['booking_id'];
    $mechanic_id = $_SESSION['proposed_assignment']['mechanic_id'];
    // Retrieve the proposed time
    $schedule_start_time = $_SESSION['proposed_assignment']['schedule_start_time']; 
    
    if ($booking_id && $mechanic_id && $schedule_start_time) {
        $pdo->beginTransaction();
        try {
            // Update the booking with the mechanic_id, new status, and the assigned time
            $stmt_booking = $pdo->prepare("UPDATE bookings SET mechanic_id = ?, status = 'accepted', schedule_start_time = ? WHERE id = ? AND (status = 'pending' OR status = 'deposit_submitted')");
            $stmt_booking->execute([$mechanic_id, $schedule_start_time, $booking_id]);

            if ($stmt_booking->rowCount() > 0) {
                // Mark the mechanic as Busy
                $stmt_mechanic = $pdo->prepare("UPDATE mechanics SET status = 'Busy', current_booking_id = ? WHERE id = ?");
                $stmt_mechanic->execute([$booking_id, $mechanic_id]);
                
                // Create Confirmation Slip Report Record
                $report_success = create_report_record($pdo, $booking_id, 'Confirmation Slip');

                if (!$report_success) {
                    error_log("Failed to create Confirmation Slip report for Booking #{$booking_id}");
                }

                $pdo->commit();

                // Send appointment confirmation SMS (best-effort)
                $stmt = $pdo->prepare("
                    SELECT b.user_id, b.vehicle_id, b.schedule_date, b.schedule_start_time, b.service_ids, u.phone
                    FROM bookings b
                    JOIN users u ON b.user_id = u.id
                    WHERE b.id = ?
                ");
                $stmt->execute([$booking_id]);
                $bookingData = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($bookingData && !empty($bookingData['phone']) && SMS_ENABLED) {
                    $serviceIds = json_decode($bookingData['service_ids'] ?? '[]', true) ?: [];
                    $serviceNames = 'Service Appointment';
                    if (!empty($serviceIds)) {
                        $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
                        $svcStmt = $pdo->prepare("SELECT service_name FROM services WHERE id IN ($placeholders)");
                        $svcStmt->execute($serviceIds);
                        $names = $svcStmt->fetchAll(PDO::FETCH_COLUMN);
                        if ($names) $serviceNames = implode(', ', $names);
                    }

                    $sms = new SMSHelper();
                    $sms->sendSMS(
                        $bookingData['phone'],
                        SMSTemplates::appointmentConfirmation(
                            $booking_id,
                            date('M j, Y', strtotime($bookingData['schedule_date'])),
                            date('g:i A', strtotime($bookingData['schedule_start_time'])),
                            $serviceNames
                        ),
                        'APPOINTMENT_CONFIRMATION',
                        [
                            'user_id' => $bookingData['user_id'],
                            'customer_id' => $bookingData['user_id'],
                            'booking_id' => $booking_id,
                            'motorcycle_id' => $bookingData['vehicle_id'] ?? null,
                            'notification_key' => 'APPOINTMENT_CONFIRMATION_' . $booking_id
                        ]
                    );
                }

                $display_time = date('h:i A', strtotime($schedule_start_time));
                $msg = "🎉 Appointment for Booking #{$booking_id} **CONFIRMED** at **{$display_time}**! Mechanic is assigned and the job is marked as **ACCEPTED**.";
                $msg_type = "success";
            } else {
                $pdo->rollBack();
                $msg = "❌ Confirmation failed. Booking #{$booking_id} might already have been changed or deposit not approved.";
                $msg_type = "error";
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "❌ Database Error during final confirmation: " . $e->getMessage();
            $msg_type = "error";
        }
    } else {
        $msg = "❌ Confirmation failed. No valid proposal (mechanic or time) found in session.";
        $msg_type = "error";
    }

    // Clear the session variable and redirect
    unset($_SESSION['proposed_assignment']);
    header("Location: manage_bookings.php?msg=" . urlencode($msg) . "&type=" . urlencode($msg_type));
    exit;

} 

// ----------------------------------------------------------------------
// --- 3. Deposit Approval/Rejection Logic (GET) ---
// ----------------------------------------------------------------------
if (isset($_GET['action']) && in_array($_GET['action'], ['deposit_approved', 'deposit_rejected']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $booking_id = $_GET['id'];
    
    $pdo->beginTransaction();
    try {
        
        $stmt_payment_check = $pdo->prepare("SELECT id FROM payments WHERE booking_id = ? AND transaction_type = 'deposit'");
        $stmt_payment_check->execute([$booking_id]);
        $payment_id = $stmt_payment_check->fetchColumn();

        if (!$payment_id) {
            throw new Exception("Deposit payment record not found for Booking #{$booking_id}.");
        }

        if ($action === 'deposit_approved') {
            $stmt_booking = $pdo->prepare("UPDATE bookings SET status='pending', deposit_verified_at=NOW() WHERE id=? AND status='deposit_submitted'");
            $stmt_booking->execute([$booking_id]);
            
            $stmt_payment = $pdo->prepare("UPDATE payments SET status='verified' WHERE id=?");
            $stmt_payment->execute([$payment_id]);

            $msg = "✅ GCash Deposit for Booking #{$booking_id} **APPROVED**. Booking is now ready for mechanic assignment.";
            $msg_type = "success";
            
        } elseif ($action === 'deposit_rejected') {
            $stmt_booking = $pdo->prepare("UPDATE bookings SET status='deposit_rejected' WHERE id=? AND status='deposit_submitted'");
            $stmt_booking->execute([$booking_id]);

            $stmt_payment = $pdo->prepare("UPDATE payments SET status='failed' WHERE id=?");
            $stmt_payment->execute([$payment_id]);

            $msg = "❌ GCash Deposit for Booking #{$booking_id} **REJECTED**. The customer must re-upload or contact support.";
            $msg_type = "error";
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "❌ Database Error during deposit status update: " . $e->getMessage();
        $msg_type = "error";
    }
    
    header("Location: manage_bookings.php?msg=" . urlencode($msg) . "&type=" . urlencode($msg_type));
    exit;
}


// ----------------------------------------------------------------------
// --- 4. Booking REJECTION Logic (GET) ---
// ----------------------------------------------------------------------
if (isset($_GET['action'], $_GET['id'])) {
    $action = strtolower($_GET['action']);
    $id = $_GET['id'];
    
    if ($action !== 'rejected') {
        $msg = "❌ Invalid or unauthorized action.";
        $msg_type = "error";
        header("Location: manage_bookings.php?msg=" . urlencode($msg) . "&type=" . urlencode($msg_type));
        exit;
    }

    // Clear proposal if it exists for this booking
    if (isset($_SESSION['proposed_assignment']) && $_SESSION['proposed_assignment']['booking_id'] == $id) {
        unset($_SESSION['proposed_assignment']);
    }

    $mechanic_id = null;
    $stmt_check = $pdo->prepare("SELECT mechanic_id FROM bookings WHERE id = ?");
    $stmt_check->execute([$id]);
    $booking_data = $stmt_check->fetch(PDO::FETCH_ASSOC);
    if ($booking_data) {
        $mechanic_id = $booking_data['mechanic_id'];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE bookings SET status=? WHERE id=?");
        $stmt->execute(['rejected', $id]);
        
        // Free up mechanic if one was assigned 
        if ($mechanic_id) {
            $stmt_free = $pdo->prepare("UPDATE mechanics SET status = 'Available', current_booking_id = NULL WHERE id = ?");
            $stmt_free->execute([$mechanic_id]);
        }
        
        $pdo->commit();
        $msg = "Status for Booking #{$id} updated to **Rejected**!";
        $msg_type = "success";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "❌ Database Error during status update: " . $e->getMessage();
        $msg_type = "error";
    }
    
    header("Location: manage_bookings.php?msg=" . urlencode($msg) . "&type=" . urlencode($msg_type));
    exit;
}

// Fallback in case a user lands on this page without a valid action
header("Location: manage_bookings.php");
exit;
?>