<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$monthlySales = [];
$monthlyServiceCounts = [];
$serviceNames = [];

// 1. Fetch all services to map IDs to Names
try {
    $stmt = $pdo->query("SELECT id, service_name FROM services");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $serviceNames[$row['id']] = $row['service_name'];
    }
} catch (PDOException $e) {
    // Handle error
}

// 2. Fetch all COMPLETED bookings for analysis
try {
    // Only fetch bookings that have a 'completed' status to represent actual sales.
    $stmt = $pdo->query("SELECT schedule_date, total_price, service_ids FROM bookings WHERE status='completed' ORDER BY schedule_date ASC");
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($bookings as $booking) {
        $monthKey = date('Y-m', strtotime($booking['schedule_date']));
        $monthLabel = date('F Y', strtotime($booking['schedule_date']));
        $price = floatval($booking['total_price']);
        
        // --- Sales Calculation ---
        if (!isset($monthlySales[$monthKey])) {
            $monthlySales[$monthKey] = [
                'label' => $monthLabel,
                'total_sales' => 0,
            ];
        }
        $monthlySales[$monthKey]['total_sales'] += $price;

        // --- Most Availed Services Calculation ---
        $serviceIds = json_decode($booking['service_ids'], true);
        if (is_array($serviceIds)) {
            if (!isset($monthlyServiceCounts[$monthKey])) {
                $monthlyServiceCounts[$monthKey] = [];
            }
            
            foreach ($serviceIds as $serviceId) {
                $serviceId = intval($serviceId); // Ensure it's an integer
                
                // Initialize the count for this service for this month
                if (!isset($monthlyServiceCounts[$monthKey][$serviceId])) {
                    $monthlyServiceCounts[$monthKey][$serviceId] = 0;
                }
                $monthlyServiceCounts[$monthKey][$serviceId]++;
            }
        }
    }

} catch (PDOException $e) {
    // Handle error
    $error_msg = "Database error: Could not fetch booking data for reports.";
}


// 3. Determine the top service for each month
$topServicesPerMonth = [];
foreach ($monthlyServiceCounts as $monthKey => $counts) {
    // Sort counts in descending order
    arsort($counts);
    
    // Get the ID of the most availed service
    $topServiceId = key($counts);
    $topCount = current($counts);
    
    // Check if a service name exists for this ID
    $serviceName = $serviceNames[$topServiceId] ?? 'Unknown Service';

    $topServicesPerMonth[$monthKey] = [
        'name' => $serviceName,
        'count' => $topCount,
    ];
}


// Reverse the array to show the most recent month first in the display
$monthlySales = array_reverse($monthlySales);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title>Reports & Analytics</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #004d80;
            --accent-color: #f7b32d;
            --bg-light: #f4f7f9;
        }
        body { background-color: var(--bg-light); }
        .app-header {
            background-color: var(--primary-color);
            color: white;
            padding: 20px 0;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        .report-card {
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
        }
        .sales-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--accent-color);
        }
        .top-service {
            background-color: #e9f5e9;
            border-left: 5px solid #28a745;
            padding: 10px;
            border-radius: 5px;
        }
    </style>
    <link rel="stylesheet" href="fonts.css">
</head>
<body>

<header class="app-header">
    <div class="container d-flex justify-content-between align-items-center">
        <a href="dashboard_admin.php" class="btn btn-outline-light d-flex align-items-center">
            <i class="bi bi-arrow-left me-2"></i> Back to Dashboard
        </a>
        <h1 class="mb-0 flex-grow-1 text-center"><i class="bi bi-graph-up me-2"></i> Business Reports</h1>
        <div style="width: 170px;"></div>
    </div>
</header>

<div class="container my-5">
    
    <?php if (isset($error_msg)): ?>
        <div class="alert alert-danger text-center mb-4"><?= $error_msg ?></div>
    <?php endif; ?>

    <h3 class="mb-4 text-primary"><i class="bi bi-bar-chart me-2"></i> Monthly Performance (Completed Bookings)</h3>

    <?php if (empty($monthlySales)): ?>
        <div class="alert alert-info text-center">
            No completed bookings data is available to generate reports.
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($monthlySales as $monthKey => $data): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card report-card h-100">
                        <div class="card-header bg-light fw-bold text-center fs-5">
                            <?= htmlspecialchars($data['label']) ?>
                        </div>
                        <div class="card-body">
                            
                            <div class="mb-4 text-center">
                                <small class="text-muted d-block mb-1">Total Sales</small>
                                <div class="sales-value">₱<?= number_format($data['total_sales'], 2) ?></div>
                            </div>
                            
                            <?php 
                                $topService = $topServicesPerMonth[$monthKey] ?? null;
                            ?>
                            <div class="top-service">
                                <small class="fw-bold d-block mb-1 text-success"><i class="bi bi-trophy me-1"></i> Most Availed Service</small>
                                <p class="mb-0">
                                    <strong class="text-dark"><?= htmlspecialchars($topService['name'] ?? 'N/A') ?></strong><br>
                                    <small class="text-muted">(<?= $topService['count'] ?? 0 ?> times availed)</small>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>