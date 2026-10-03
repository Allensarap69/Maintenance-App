<?php
// generate_reports.php (Example)
require __DIR__ . '/../db.php';

// 1. Define the report period (e.g., Yesterday)
$report_date = date('Y-m-d', strtotime('yesterday'));
$start_of_day = $report_date . ' 00:00:00';
$end_of_day = $report_date . ' 23:59:59';

// 2. Calculate the Metrics from the bookings table
$stmt = $pdo->prepare("
    SELECT 
        COUNT(id) AS total_bookings,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS total_completed,
        SUM(CASE WHEN status = 'completed' THEN total_price ELSE 0 END) AS total_earnings
    FROM bookings 
    WHERE created_at BETWEEN ? AND ? 
");
$stmt->execute([$start_of_day, $end_of_day]);
$metrics = $stmt->fetch(PDO::FETCH_ASSOC);

if ($metrics && $metrics['total_bookings'] > 0) {
    // 3. Insert the metrics into the reports table
    $insert_stmt = $pdo->prepare("
        INSERT INTO reports 
        (report_date, total_bookings, total_completed, total_earnings, generated_by, created_at)
        VALUES (?, ?, ?, ?, 'System', NOW())
    ");
    
    $insert_stmt->execute([
        $report_date,
        $metrics['total_bookings'],
        $metrics['total_completed'],
        $metrics['total_earnings']
    ]);
    
    echo "Report generated successfully for {$report_date}.";
} else {
    echo "No relevant data found for {$report_date}.";
}

?>