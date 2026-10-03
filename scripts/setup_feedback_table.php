<?php
require __DIR__ . '/../db.php';

echo "<h2>Service Feedback Table Setup</h2>";
echo "<hr>";

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

    echo "✅ service_feedback table created successfully!<br>";
    echo "<hr>";
    echo "<p><strong>Note:</strong> This table stores customer ratings for mechanics and overall customer service, plus optional notes. Admins can review submissions in <a href='../admin_feedback.php'>Customer Feedback</a>.</p>";
} catch (PDOException $e) {
    echo "❌ Error creating service_feedback table: " . $e->getMessage() . "<br>";
    echo "<p>Please check your database connection and permissions.</p>";
}
?>
