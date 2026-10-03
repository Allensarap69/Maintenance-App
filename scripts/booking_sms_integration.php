<?php
/**
 * Example: How to integrate SMS notifications into your booking system
 * Add these functions to your existing booking management files
 */

require_once __DIR__ . '/../sms_helper.php';
require_once __DIR__ . '/../sms_config.php';

/**
 * Send SMS notification when booking is accepted
 */
function sendBookingAcceptedSMS($booking_id, $customer_phone, $pdo) {
    if (!SMS_ENABLED) return false;
    
    try {
        // Get booking details
        $stmt = $pdo->prepare("
            SELECT b.*, m.name as mechanic_name 
            FROM bookings b 
            LEFT JOIN mechanics m ON b.mechanic_id = m.id 
            WHERE b.id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) return false;
        
        // Format date and time
        $date = date('M j, Y', strtotime($booking['schedule_date']));
        $time = date('g:i A', strtotime($booking['schedule_start_time']));
        
        // Initialize SMS helper
        $sms = new SMSHelper(SMS_API_KEY, SMS_SENDER_NAME);
        
        // Send SMS
        $message = SMSTemplates::bookingAccepted($booking_id, $date, $time, $booking['mechanic_name']);
        $result = $sms->sendSMS($customer_phone, $message);
        
        // Log the result
        if (SMS_DEBUG) {
            error_log("SMS Sent - Booking Accepted: " . print_r($result, true));
        }
        
        return $result['success'];
        
    } catch (Exception $e) {
        error_log("SMS Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send SMS notification when booking is rejected
 */
function sendBookingRejectedSMS($booking_id, $customer_phone, $reason = '') {
    if (!SMS_ENABLED) return false;
    
    try {
        $sms = new SMSHelper(SMS_API_KEY, SMS_SENDER_NAME);
        $message = SMSTemplates::bookingRejected($booking_id, $reason);
        $result = $sms->sendSMS($customer_phone, $message);
        
        if (SMS_DEBUG) {
            error_log("SMS Sent - Booking Rejected: " . print_r($result, true));
        }
        
        return $result['success'];
        
    } catch (Exception $e) {
        error_log("SMS Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Example: How to modify your booking acceptance logic
 */
function acceptBookingWithSMS($booking_id, $pdo) {
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Get customer phone number
        $stmt = $pdo->prepare("
            SELECT u.phone 
            FROM bookings b 
            JOIN users u ON b.customer_id = u.id 
            WHERE b.id = ?
        ");
        $stmt->execute([$booking_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Update booking status
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'accepted' WHERE id = ?");
        $stmt->execute([$booking_id]);
        
        // Commit transaction
        $pdo->commit();
        
        // Send SMS notification
        if ($customer && $customer['phone']) {
            sendBookingAcceptedSMS($booking_id, $customer['phone'], $pdo);
        }
        
        return true;
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Booking acceptance error: " . $e->getMessage());
        return false;
    }
}

/**
 * Example: How to modify your booking rejection logic
 */
function rejectBookingWithSMS($booking_id, $reason, $pdo) {
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Get customer phone number
        $stmt = $pdo->prepare("
            SELECT u.phone 
            FROM bookings b 
            JOIN users u ON b.customer_id = u.id 
            WHERE b.id = ?
        ");
        $stmt->execute([$booking_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Update booking status
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$booking_id]);
        
        // Commit transaction
        $pdo->commit();
        
        // Send SMS notification
        if ($customer && $customer['phone']) {
            sendBookingRejectedSMS($booking_id, $customer['phone'], $reason);
        }
        
        return true;
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Booking rejection error: " . $e->getMessage());
        return false;
    }
}

// Example usage in your manage_bookings.php:
/*
if ($_POST['action'] === 'accept') {
    $booking_id = $_POST['booking_id'];
    if (acceptBookingWithSMS($booking_id, $pdo)) {
        $msg = "✅ Booking accepted and SMS notification sent!";
    } else {
        $msg = "❌ Error accepting booking.";
    }
}
*/
?>