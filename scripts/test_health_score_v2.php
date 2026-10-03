<?php
/**
 * Health Score v2 end-to-end test.
 * Creates a test customer + motorcycle, runs the six scenarios,
 * and rolls back the transaction at the end.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../motorcycle_health_helper.php';

function showScore($label, $motorcycle) {
    $factors = [];
    $score = calculateHealthScore($motorcycle, $factors);
    echo "\n$label\n";
    echo "Overall Score: $score\n";
    foreach ($factors as $k => $v) {
        if (strpos($k, '_score') !== false) {
            echo "  $k: $v\n";
        }
    }
    return $score;
}

echo "=== HEALTH SCORE V2 END-TO-END TEST ===\n";

try {
    $pdo->beginTransaction();

    // Create test customer
    $unique = 'test_' . time();
    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password, name, role, status, archived)
        VALUES (?, ?, ?, ?, 'customer', 'Active', 0)
    ");
    $stmt->execute([$unique, "$unique@test.local", 'testpass', 'Test Customer']);
    $customer_id = $pdo->lastInsertId();

    // Create test motorcycle (baseline)
    $purchase = date('Y-m-d');
    $stmt = $pdo->prepare("
        INSERT INTO motorcycles (user_id, brand, model, color, year_model, plate_number,
            engine_number, chassis_number, purchase_date, current_mileage, last_maintenance_date,
            last_service_mileage, next_maintenance_date, maintenance_interval_months, maintenance_interval_km,
            warranty_status, status)
        VALUES (?, 'HONDA', 'Wave', 'Black', 2024, ?, 'TEST123', 'TESTCHS', ?, 1000, ?, 0, ?, 6, 5000, 'none', 'active')
    ");
    $next_due = date('Y-m-d', strtotime('+6 months'));
    $stmt->execute([$customer_id, $unique, $purchase, $purchase, $next_due]);
    $motorcycle_id = $pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT * FROM motorcycles WHERE id = ?");
    $stmt->execute([$motorcycle_id]);
    $motorcycle = $stmt->fetch(PDO::FETCH_ASSOC);

    // Test 1: No overdue maintenance, baseline
    $s1 = showScore("TEST 1 - Baseline (no overdue)", $motorcycle);

    // Test 2: Make oil change overdue
    $overdue_date = date('Y-m-d', strtotime('-21 days'));
    $pdo->prepare("UPDATE motorcycles SET next_maintenance_date = ? WHERE id = ?")
        ->execute([$overdue_date, $motorcycle_id]);
    $stmt->execute([$motorcycle_id]);
    $motorcycle = $stmt->fetch(PDO::FETCH_ASSOC);
    $s2 = showScore("TEST 2 - Oil change overdue (21 days)", $motorcycle);

    if ($s2 >= $s1) {
        echo "\nFAIL: Score did not decrease when overdue.\n";
    } else {
        echo "\nPASS: Score decreased from $s1 to $s2.\n";
    }

    // Test 3: Complete the oil change
    $service_date = date('Y-m-d');
    $service_mileage = 2000;
    $stmt_ins = $pdo->prepare("
        INSERT INTO maintenance_history (motorcycle_id, customer_id, service_date, mileage, service_type,
            parts_replaced, cost, mechanic_remarks, performed_by)
        VALUES (?, ?, ?, ?, 'Oil Change', 'Oil filter', 500.00, 'Oil change completed', 'Test Mechanic')
    ");
    $stmt_ins->execute([$motorcycle_id, $customer_id, $service_date, $service_mileage]);

    // Update motorcycle schedule/mileage the same way the maintenance forms do
    $pdo->prepare("
        UPDATE motorcycles
        SET current_mileage = ?,
            last_maintenance_date = ?,
            last_service_mileage = GREATEST(COALESCE(last_service_mileage, 0), ?),
            next_maintenance_date = DATE_ADD(?, INTERVAL maintenance_interval_months MONTH)
        WHERE id = ? AND current_mileage < ?
    ")->execute([$service_mileage, $service_date, $service_mileage, $service_date, $motorcycle_id, $service_mileage]);

    updateMotorcycleHealthScore($motorcycle_id);

    $stmt->execute([$motorcycle_id]);
    $motorcycle = $stmt->fetch(PDO::FETCH_ASSOC);
    $s3 = showScore("TEST 3 - Oil change completed", $motorcycle);

    $schedule = getMaintenanceSchedule($motorcycle);
    if ($schedule['is_overdue']) {
        echo "\nFAIL: Overdue status was not cleared.\n";
    } else {
        echo "\nPASS: Overdue status cleared. Next service: {$schedule['next_date']}\n";
    }

    if ($s3 <= $s2) {
        echo "FAIL: Score did not increase after completing maintenance.\n";
    } else {
        echo "PASS: Score increased from $s2 to $s3.\n";
    }

    // Test 4: Record a poor inspection
    $pdo->prepare("
        INSERT INTO motorcycle_inspections
        (motorcycle_id, inspection_date, engine, brakes, tires, battery, lights, suspension, fluids, mechanic_remarks, performed_by)
        VALUES (?, ?, 'Needs Attention', 'Needs Attention', 'Needs Attention', 'Needs Attention',
                'Needs Attention', 'Needs Attention', 'Needs Attention', 'Multiple items need work', 'Test Mechanic')
    ")->execute([$motorcycle_id, $service_date]);

    updateMotorcycleHealthScore($motorcycle_id);

    $stmt->execute([$motorcycle_id]);
    $motorcycle = $stmt->fetch(PDO::FETCH_ASSOC);
    $s4 = showScore("TEST 4 - Poor inspection (Needs Attention)", $motorcycle);

    if ($s4 >= $s3) {
        echo "\nFAIL: Score did not decrease with a poor inspection.\n";
    } else {
        echo "\nPASS: Score decreased from $s3 to $s4.\n";
    }

    // Test 5: Correct the inspection issue
    $pdo->prepare("
        INSERT INTO motorcycle_inspections
        (motorcycle_id, inspection_date, engine, brakes, tires, battery, lights, suspension, fluids, mechanic_remarks, performed_by)
        VALUES (?, ?, 'Good', 'Good', 'Good', 'Good', 'Good', 'Good', 'Good', 'All issues resolved', 'Test Mechanic')
    ")->execute([$motorcycle_id, $service_date]);

    updateMotorcycleHealthScore($motorcycle_id);

    $stmt->execute([$motorcycle_id]);
    $motorcycle = $stmt->fetch(PDO::FETCH_ASSOC);
    $s5 = showScore("TEST 5 - Inspection corrected", $motorcycle);

    if ($s5 <= $s4) {
        echo "\nFAIL: Score did not increase after inspection was corrected.\n";
    } else {
        echo "\nPASS: Score increased from $s4 to $s5.\n";
    }

    // Test 6: Score must not automatically be 100 after one service
    if ($s3 === 100 || $s5 === 100) {
        echo "\nFAIL: Score should not be 100 after a single maintenance/inspection.\n";
    } else {
        echo "\nPASS: Score is not 100 after one service (final score: $s5).\n";
    }

    // Roll everything back
    $pdo->rollBack();
    echo "\n=== TEST DATA ROLLED BACK ===\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
