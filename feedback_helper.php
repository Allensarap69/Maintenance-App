<?php
/**
 * Service Feedback Helper
 *
 * Supports the customer feedback feature: after a booking or emergency
 * request is completed, the customer rates the mechanic (1-5 stars),
 * rates the company's customer service (1-5 stars) and can leave notes.
 * Admins review submissions on admin_feedback.php.
 */

/**
 * Lazily create the service_feedback table. Fail-open: if DDL fails we
 * log and continue so pages never break.
 */
function ensure_feedback_table($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS service_feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            feedback_type ENUM('booking','emergency') NOT NULL,
            booking_id INT DEFAULT NULL,
            emergency_request_id INT DEFAULT NULL,
            mechanic_id INT DEFAULT NULL,
            mechanic_rating TINYINT DEFAULT NULL,
            service_rating TINYINT DEFAULT NULL,
            comments TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_feedback_booking (booking_id),
            UNIQUE KEY uq_feedback_emergency (emergency_request_id),
            KEY idx_feedback_customer (customer_id),
            KEY idx_feedback_mechanic (mechanic_id),
            CONSTRAINT fk_feedback_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_feedback_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
            CONSTRAINT fk_feedback_emergency FOREIGN KEY (emergency_request_id) REFERENCES emergency_service_requests (id) ON DELETE CASCADE,
            CONSTRAINT fk_feedback_mechanic FOREIGN KEY (mechanic_id) REFERENCES mechanics (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (Exception $e) {
        error_log('service_feedback table init failed: ' . $e->getMessage());
    }
}

/**
 * Fetch the feedback row for a completed booking, or null.
 */
function get_booking_feedback($pdo, $booking_id) {
    ensure_feedback_table($pdo);
    try {
        $stmt = $pdo->prepare("SELECT * FROM service_feedback WHERE booking_id = ? LIMIT 1");
        $stmt->execute([(int)$booking_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        error_log('get_booking_feedback failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Fetch the feedback row for a completed emergency request, or null.
 */
function get_emergency_feedback($pdo, $request_id) {
    ensure_feedback_table($pdo);
    try {
        $stmt = $pdo->prepare("SELECT * FROM service_feedback WHERE emergency_request_id = ? LIMIT 1");
        $stmt->execute([(int)$request_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        error_log('get_emergency_feedback failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Resolve the mechanic to rate for a booking: the primary mechanic_id on
 * the booking, falling back to the first row in booking_mechanics.
 * Returns ['id' => int, 'name' => string] or null.
 */
function get_booking_primary_mechanic($pdo, $booking_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT m.id, m.name
            FROM bookings b
            JOIN mechanics m ON m.id = b.mechanic_id
            WHERE b.id = ?
        ");
        $stmt->execute([(int)$booking_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;

        $stmt = $pdo->prepare("
            SELECT m.id, m.name
            FROM booking_mechanics bm
            JOIN mechanics m ON m.id = bm.mechanic_id
            WHERE bm.booking_id = ?
            ORDER BY bm.mechanic_id ASC
            LIMIT 1
        ");
        $stmt->execute([(int)$booking_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        error_log('get_booking_primary_mechanic failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Render a read-only star display for a 1-5 rating.
 * Returns an HTML string (bootstrap icons).
 */
function render_stars($rating, $max = 5) {
    $rating = (int)$rating;
    $html = '<span class="star-display" title="' . $rating . ' out of ' . $max . '">';
    for ($i = 1; $i <= $max; $i++) {
        $html .= '<i class="bi ' . ($i <= $rating ? 'bi-star-fill' : 'bi-star') . '" style="color:#EAB308;"></i>';
    }
    $html .= '</span>';
    return $html;
}
