<?php
/**
 * Motorcycle Health and Maintenance Helper Functions
 * Provides functions for calculating health scores, warranty status, and maintenance schedules
 */

require_once __DIR__ . '/db.php';

const INSPECTION_VALUES = [
    'Good' => 100,
    'Fair' => 75,
    'Needs Attention' => 50,
    'Critical' => 25,
];

const MAINTENANCE_SCORE_VALUES = [
    0 => 0,
    1 => 50,
    2 => 75,
];

/**
 * Calculate motorcycle health score based on four weighted factors
 * @param array $motorcycle Motorcycle data from database
 * @param array|null $factors Optional by-ref array to receive score breakdown
 * @return int Health score (0-100)
 */
function calculateHealthScore($motorcycle, &$factors = null) {
    global $pdo;

    $maintenanceScore = getMaintenanceScore($pdo, $motorcycle['id'] ?? 0);
    $mileageScore = getMileageScore($motorcycle);
    $inspectionScore = getInspectionScore($pdo, $motorcycle['id'] ?? 0);
    $overdueScore = getOverdueScore($motorcycle);

    $overall = round(
        ($maintenanceScore * 0.30) +
        ($mileageScore * 0.20) +
        ($inspectionScore * 0.35) +
        ($overdueScore * 0.15)
    );
    $overall = max(0, min(100, $overall));

    $factors = [
        'maintenance_score' => $maintenanceScore,
        'maintenance_weight' => 0.30,
        'maintenance_contribution' => round($maintenanceScore * 0.30, 2),
        'mileage_score' => $mileageScore,
        'mileage_weight' => 0.20,
        'mileage_contribution' => round($mileageScore * 0.20, 2),
        'inspection_score' => $inspectionScore,
        'inspection_weight' => 0.35,
        'inspection_contribution' => round($inspectionScore * 0.35, 2),
        'overdue_score' => $overdueScore,
        'overdue_weight' => 0.15,
        'overdue_contribution' => round($overdueScore * 0.15, 2),
        'overall_score' => $overall,
    ];

    return $overall;
}

/**
 * Maintenance history factor (0-100)
 * 0 services in last 12 months = 25
 * 1 service = 50
 * 2 services = 75
 * 3+ services = 100
 */
function getMaintenanceScore($pdo, $motorcycle_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as service_count
            FROM (
                SELECT service_date AS service_date
                FROM maintenance_history
                WHERE motorcycle_id = ?
                  AND service_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
                UNION
                SELECT schedule_date AS service_date
                FROM bookings
                WHERE vehicle_id = ?
                  AND status = 'completed'
                  AND schedule_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
            ) AS combined
        ");
        $stmt->execute([$motorcycle_id, $motorcycle_id]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= 3) return 100;
        if (isset(MAINTENANCE_SCORE_VALUES[$count])) return MAINTENANCE_SCORE_VALUES[$count];
        return 100;
    } catch (PDOException $e) {
        error_log("Error calculating maintenance score: " . $e->getMessage());
        return 25;
    }
}

/**
 * Mileage / service interval factor (0-100)
 */
function getMileageScore($motorcycle) {
    $currentMileage = floatval($motorcycle['current_mileage'] ?? 0);
    $lastServiceMileage = floatval($motorcycle['last_service_mileage'] ?? 0);
    $intervalKm = (int) ($motorcycle['maintenance_interval_km'] ?? 5000);
    if ($intervalKm <= 0) $intervalKm = 5000;

    $milesSinceService = max(0, $currentMileage - $lastServiceMileage);
    $excess = max(0, $milesSinceService - $intervalKm);

    $score = 100 - (($excess / $intervalKm) * 100);
    return max(0, min(100, round($score)));
}

/**
 * Mechanic inspection factor (0-100)
 */
function getInspectionScore($pdo, $motorcycle_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT engine, brakes, tires, battery, lights, suspension, fluids
            FROM motorcycle_inspections
            WHERE motorcycle_id = ?
            ORDER BY inspection_date DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$motorcycle_id]);
        $inspection = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inspection) {
            return 100;
        }

        $total = 0;
        $count = 0;
        $components = ['engine', 'brakes', 'tires', 'battery', 'lights', 'suspension', 'fluids'];
        foreach ($components as $component) {
            $result = $inspection[$component] ?? 'Good';
            $total += INSPECTION_VALUES[$result] ?? 100;
            $count++;
        }

        return $count > 0 ? round($total / $count) : 100;
    } catch (PDOException $e) {
        error_log("Error calculating inspection score: " . $e->getMessage());
        return 100;
    }
}

/**
 * Overdue services factor (0-100)
 */
function getOverdueScore($motorcycle) {
    $schedule = getMaintenanceSchedule($motorcycle);

    if (empty($schedule['next_date']) || !$schedule['is_overdue']) {
        return 100;
    }

    $daysOverdue = (int) ($schedule['days_overdue'] ?? 0);
    $score = 100 - ($daysOverdue * 5);
    return max(0, min(100, round($score)));
}

/**
 * Condition label for a score
 */
function getHealthScoreCondition($score) {
    if ($score >= 80) return 'Excellent';
    if ($score >= 60) return 'Good';
    if ($score >= 40) return 'Fair';
    return 'Poor';
}

/**
 * Bootstrap color class for a score
 */
function getHealthScoreColor($score) {
    if ($score >= 80) return 'success';
    if ($score >= 60) return 'info';
    if ($score >= 40) return 'warning';
    return 'danger';
}

/**
 * FontAwesome icon class for a score
 */
function getHealthScoreIcon($score) {
    if ($score >= 80) return 'fa-smile-beam';
    if ($score >= 60) return 'fa-meh';
    if ($score >= 40) return 'fa-frown';
    return 'fa-dizzy';
}

/**
 * Recommendation text for a score
 */
function getHealthScoreRecommendation($score) {
    if ($score >= 80) return 'Motorcycle is in excellent condition. Continue regular maintenance schedule.';
    if ($score >= 60) return 'Motorcycle is in good condition. Monitor wear and tear and consider scheduling upcoming maintenance.';
    if ($score >= 40) return 'Motorcycle needs attention. Schedule maintenance soon and check critical components.';
    return 'Motorcycle requires immediate maintenance. Multiple issues detected - please service soon.';
}

/**
 * Save health score to database with factor breakdown
 */
function saveHealthScore($motorcycle_id, $health_score, $factors = null) {
    global $pdo;

    try {
        $condition = getHealthScoreCondition($health_score);
        $recommendations = getHealthScoreRecommendation($health_score);
        $factorsJson = $factors ? json_encode($factors) : null;

        $stmt = $pdo->prepare("
            SELECT id FROM motorcycle_health_scores
            WHERE motorcycle_id = ? AND score_date = CURDATE()
        ");
        $stmt->execute([$motorcycle_id]);

        if ($stmt->rowCount() > 0) {
            $update = $pdo->prepare("
                UPDATE motorcycle_health_scores
                SET health_score = ?, condition_status = ?, maintenance_recommendations = ?, factors = ?
                WHERE id = ?
            ");
            $update->execute([$health_score, $condition, $recommendations, $factorsJson, $stmt->fetch()['id']]);
        } else {
            $insert = $pdo->prepare("
                INSERT INTO motorcycle_health_scores
                (motorcycle_id, health_score, condition_status, maintenance_recommendations, score_date, factors)
                VALUES (?, ?, ?, ?, CURDATE(), ?)
            ");
            $insert->execute([$motorcycle_id, $health_score, $condition, $recommendations, $factorsJson]);
        }
    } catch (PDOException $e) {
        error_log("Error saving health score: " . $e->getMessage());
    }
}

/**
 * Recalculate and update the stored health_score column
 */
function updateMotorcycleHealthScore($motorcycle_id) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT * FROM motorcycles WHERE id = ?");
        $stmt->execute([$motorcycle_id]);
        $motorcycle = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$motorcycle) return;

        $factors = [];
        $healthScore = calculateHealthScore($motorcycle, $factors);

        $update = $pdo->prepare("UPDATE motorcycles SET health_score = ? WHERE id = ?");
        $update->execute([$healthScore, $motorcycle_id]);

        saveHealthScore($motorcycle_id, $healthScore, $factors);
    } catch (PDOException $e) {
        error_log("Error updating motorcycle health score: " . $e->getMessage());
    }
}

/**
 * Get latest inspection record for a motorcycle
 */
function getLatestInspection($pdo, $motorcycle_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM motorcycle_inspections
            WHERE motorcycle_id = ?
            ORDER BY inspection_date DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$motorcycle_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log("Error fetching inspection: " . $e->getMessage());
        return null;
    }
}

/**
 * Get warranty status with expiry information
 */
function getWarrantyStatus($motorcycle) {
    $status = [
        'status' => 'none',
        'is_valid' => false,
        'days_remaining' => null,
        'expiry_date' => null,
        'message' => 'No warranty'
    ];

    if (empty($motorcycle['warranty_status']) || $motorcycle['warranty_status'] === 'none') {
        return $status;
    }

    $status['status'] = $motorcycle['warranty_status'];

    if (!empty($motorcycle['warranty_expiry_date'])) {
        $expiryDate = new DateTime($motorcycle['warranty_expiry_date']);
        $today = new DateTime();
        $status['expiry_date'] = $motorcycle['warranty_expiry_date'];

        if ($expiryDate > $today) {
            $status['is_valid'] = true;
            $status['days_remaining'] = $today->diff($expiryDate)->days;
            $status['message'] = 'Warranty Active';
        } else {
            $status['is_valid'] = false;
            $status['status'] = 'expired';
            $status['days_remaining'] = 0;
            $status['message'] = 'Warranty Expired';
        }
    } else {
        if ($motorcycle['warranty_status'] === 'active') {
            $status['is_valid'] = true;
            $status['message'] = 'Warranty Active (No expiry date)';
        } else {
            $status['is_valid'] = false;
            $status['message'] = 'Warranty ' . ucfirst($motorcycle['warranty_status']);
        }
    }

    return $status;
}

/**
 * Calculate next maintenance date based on last service and interval
 */
function getMaintenanceSchedule($motorcycle) {
    $schedule = [
        'next_date' => null,
        'days_until' => null,
        'is_overdue' => false,
        'days_overdue' => null,
        'last_date' => $motorcycle['last_maintenance_date'] ?? null,
        'interval_months' => $motorcycle['maintenance_interval_months'] ?? 6
    ];

    // If next maintenance date is already set, use it
    if (!empty($motorcycle['next_maintenance_date'])) {
        $nextDate = new DateTime($motorcycle['next_maintenance_date']);
        $today = new DateTime();
        $schedule['next_date'] = $motorcycle['next_maintenance_date'];

        if ($nextDate < $today) {
            $schedule['is_overdue'] = true;
            $schedule['days_overdue'] = $today->diff($nextDate)->days;
            $schedule['days_until'] = 0;
        } else {
            $schedule['days_until'] = $today->diff($nextDate)->days;
            $schedule['days_overdue'] = 0;
        }
    }
    // Calculate from last maintenance date if available
    elseif (!empty($motorcycle['last_maintenance_date'])) {
        $lastDate = new DateTime($motorcycle['last_maintenance_date']);
        $interval = new DateInterval('P' . ($motorcycle['maintenance_interval_months'] ?? 6) . 'M');
        $nextDate = $lastDate->add($interval);
        $today = new DateTime();

        $schedule['next_date'] = $nextDate->format('Y-m-d');

        if ($nextDate < $today) {
            $schedule['is_overdue'] = true;
            $schedule['days_overdue'] = $today->diff($nextDate)->days;
            $schedule['days_until'] = 0;
        } else {
            $schedule['days_until'] = $today->diff($nextDate)->days;
            $schedule['days_overdue'] = 0;
        }
    }
    // Use purchase date as fallback
    elseif (!empty($motorcycle['purchase_date'])) {
        $purchaseDate = new DateTime($motorcycle['purchase_date']);
        $interval = new DateInterval('P' . ($motorcycle['maintenance_interval_months'] ?? 6) . 'M');
        $nextDate = $purchaseDate->add($interval);
        $today = new DateTime();

        $schedule['next_date'] = $nextDate->format('Y-m-d');

        if ($nextDate < $today) {
            $schedule['is_overdue'] = true;
            $schedule['days_overdue'] = $today->diff($nextDate)->days;
            $schedule['days_until'] = 0;
        } else {
            $schedule['days_until'] = $today->diff($nextDate)->days;
            $schedule['days_overdue'] = 0;
        }
    }

    return $schedule;
}

/**
 * Get complete motorcycle dashboard data
 */
function getMotorcycleDashboardData($motorcycle_id) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT m.*, u.name as customer_name, u.email as customer_email
            FROM motorcycles m
            JOIN users u ON m.user_id = u.id
            WHERE m.id = ?
        ");
        $stmt->execute([$motorcycle_id]);
        $motorcycle = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$motorcycle) {
            return null;
        }

        $factors = [];
        $healthScore = calculateHealthScore($motorcycle, $factors);

        return [
            'motorcycle' => $motorcycle,
            'health_score' => $healthScore,
            'factors' => $factors,
            'warranty' => getWarrantyStatus($motorcycle),
            'maintenance' => getMaintenanceSchedule($motorcycle),
            'latest_inspection' => getLatestInspection($pdo, $motorcycle_id)
        ];

    } catch (PDOException $e) {
        error_log("Error fetching motorcycle dashboard data: " . $e->getMessage());
        return null;
    }
}

/**
 * Get all motorcycles for a customer with dashboard data
 */
function getCustomerMotorcyclesDashboard($user_id) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT m.*
            FROM motorcycles m
            WHERE m.user_id = ? AND m.status = 'active'
            ORDER BY m.created_at DESC
        ");
        $stmt->execute([$user_id]);
        $motorcycles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dashboardData = [];
        foreach ($motorcycles as $motorcycle) {
            $factors = [];
            $dashboardData[] = [
                'motorcycle' => $motorcycle,
                'health_score' => calculateHealthScore($motorcycle, $factors),
                'factors' => $factors,
                'warranty' => getWarrantyStatus($motorcycle),
                'maintenance' => getMaintenanceSchedule($motorcycle),
                'latest_inspection' => getLatestInspection($pdo, $motorcycle['id'])
            ];
        }

        return $dashboardData;

    } catch (PDOException $e) {
        error_log("Error fetching customer motorcycles dashboard: " . $e->getMessage());
        return [];
    }
}

/**
 * Record a completed booking as a maintenance_history record, refresh the
 * motorcycle's maintenance schedule and health score, and mark the related
 * admin recommendation as applied.
 */
function recordCompletedBookingHistory($booking_id) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT b.vehicle_id, b.user_id, b.mechanic_id, b.schedule_date, b.service_ids, b.package_ids, b.total_price, m.current_mileage
            FROM bookings b
            JOIN motorcycles m ON b.vehicle_id = m.id
            WHERE b.id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking_data) return;

        $service_ids = json_decode($booking_data['service_ids'] ?? '[]', true) ?: [];
        $package_ids = json_decode($booking_data['package_ids'] ?? '[]', true) ?: [];
        $item_names = [];
        if (!empty($service_ids)) {
            $placeholders = rtrim(str_repeat('?,', count($service_ids)), ',');
            $s_stmt = $pdo->prepare("SELECT service_name FROM services WHERE id IN ($placeholders)");
            $s_stmt->execute($service_ids);
            $item_names = array_merge($item_names, $s_stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        if (!empty($package_ids)) {
            $placeholders = rtrim(str_repeat('?,', count($package_ids)), ',');
            $p_stmt = $pdo->prepare("SELECT package_name FROM service_packages WHERE id IN ($placeholders)");
            $p_stmt->execute($package_ids);
            $item_names = array_merge($item_names, $p_stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $service_type = !empty($item_names) ? 'Booked: ' . implode(', ', $item_names) : 'Booked Service';

        // Mileage milestone from booked packages (e.g. "1000 KM" first service).
        // A brand-new bike (0 km) completing its 1k service advances to 1,000 km;
        // a bike already past the milestone keeps its higher mileage.
        $target_mileage = 0;
        if (!empty($package_ids)) {
            $placeholders = rtrim(str_repeat('?,', count($package_ids)), ',');
            $km_stmt = $pdo->prepare("SELECT MAX(maintenance_interval_km) FROM service_packages WHERE id IN ($placeholders)");
            $km_stmt->execute($package_ids);
            $target_mileage = (int) $km_stmt->fetchColumn();
        }
        $current_mileage = (int) round($booking_data['current_mileage'] ?? 0);
        if ($current_mileage <= 0 && $target_mileage < 1000) {
            // Brand-new motorcycle: its first completed service is the 1k break-in milestone
            $target_mileage = 1000;
        }
        $service_mileage = max($current_mileage, $target_mileage);

        $performed_by = 'AutoCare Pro';
        $mech_q = $pdo->prepare("SELECT name FROM mechanics WHERE id = ?");
        $mech_q->execute([$booking_data['mechanic_id'] ?? 0]);
        $mech_name = $mech_q->fetchColumn();
        if (!$mech_name) {
            $mech_q = $pdo->prepare("SELECT me.name FROM booking_mechanics bm JOIN mechanics me ON me.id = bm.mechanic_id WHERE bm.booking_id = ? LIMIT 1");
            $mech_q->execute([$booking_id]);
            $mech_name = $mech_q->fetchColumn();
        }
        if ($mech_name) $performed_by = $mech_name;

        $insert = $pdo->prepare("
            INSERT INTO maintenance_history
            (motorcycle_id, customer_id, service_date, mileage, service_type, parts_replaced, cost, mechanic_remarks, performed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $booking_data['vehicle_id'],
            $booking_data['user_id'],
            $booking_data['schedule_date'],
            $service_mileage,
            $service_type,
            !empty($item_names) ? implode(', ', $item_names) : 'N/A',
            $booking_data['total_price'] ?? 0,
            'Completed booking #' . $booking_id,
            $performed_by
        ]);

        $update = $pdo->prepare("
            UPDATE motorcycles
            SET last_maintenance_date = ?,
                current_mileage = GREATEST(COALESCE(current_mileage, 0), ?),
                last_service_mileage = GREATEST(COALESCE(last_service_mileage, 0), ?),
                next_maintenance_date = DATE_ADD(?, INTERVAL COALESCE(maintenance_interval_months, 6) MONTH)
            WHERE id = ?
        ");
        $update->execute([
            $booking_data['schedule_date'],
            $service_mileage,
            $service_mileage,
            $booking_data['schedule_date'],
            $booking_data['vehicle_id']
        ]);

        updateMotorcycleHealthScore($booking_data['vehicle_id']);

        try {
            $mark = $pdo->prepare("
                UPDATE customer_notifications
                SET is_applied = 1
                WHERE customer_id = ? AND motorcycle_id = ? AND is_applied = 0
                ORDER BY id DESC
                LIMIT 1
            ");
            $mark->execute([$booking_data['user_id'], $booking_data['vehicle_id']]);
        } catch (PDOException $e) {
            error_log("Failed to mark notification applied: " . $e->getMessage());
        }
    } catch (PDOException $e) {
        error_log("recordCompletedBookingHistory error: " . $e->getMessage());
    }
}

/**
 * Record a completed emergency service request as a maintenance_history
 * record and refresh the motorcycle's maintenance schedule + health score.
 */
function recordCompletedEmergencyHistory($emergency_id) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT esr.motorcycle_id, esr.customer_id, esr.motorcycle_issue, esr.problem_description,
                   esr.service_type, esr.assigned_mechanic_id, m.current_mileage, mech.name AS mechanic_name
            FROM emergency_service_requests esr
            JOIN motorcycles m ON esr.motorcycle_id = m.id
            LEFT JOIN mechanics mech ON esr.assigned_mechanic_id = mech.id
            WHERE esr.id = ?
        ");
        $stmt->execute([$emergency_id]);
        $e = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$e) return;

        $issue = trim($e['motorcycle_issue'] ?? '') !== '' ? $e['motorcycle_issue'] : 'Emergency Service';
        $service_label = ($e['service_type'] === 'tow_service') ? 'Tow Service' : 'Fix On-Site';
        $service_type = 'Emergency: ' . $issue;
        $today = date('Y-m-d');

        $insert = $pdo->prepare("
            INSERT INTO maintenance_history
            (motorcycle_id, customer_id, service_date, mileage, service_type, parts_replaced, cost, mechanic_remarks, performed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $e['motorcycle_id'],
            $e['customer_id'],
            $today,
            $e['current_mileage'],
            $service_type,
            $issue,
            0,
            'Completed emergency request #' . $emergency_id . ' (' . $service_label . ')',
            $e['mechanic_name'] ?: 'AutoCare Pro'
        ]);

        $update = $pdo->prepare("
            UPDATE motorcycles
            SET last_maintenance_date = ?,
                last_service_mileage = GREATEST(COALESCE(last_service_mileage, 0), ?),
                next_maintenance_date = DATE_ADD(?, INTERVAL COALESCE(maintenance_interval_months, 6) MONTH)
            WHERE id = ?
        ");
        $update->execute([$today, $e['current_mileage'], $today, $e['motorcycle_id']]);

        updateMotorcycleHealthScore($e['motorcycle_id']);
    } catch (PDOException $e) {
        error_log("recordCompletedEmergencyHistory error: " . $e->getMessage());
    }
}
