<?php
session_start();
require 'db.php';

// Validate ID and User
if (!isset($_GET['maintenance_id']) || !isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header("Location: index.php");
    exit;
}

$maintenance_id = (int)$_GET['maintenance_id'];
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT mh.*, m.brand, m.model, m.year_model, m.plate_number
    FROM maintenance_history mh
    JOIN motorcycles m ON mh.motorcycle_id = m.id
    WHERE mh.id = ? AND mh.customer_id = ?
");
$stmt->execute([$maintenance_id, $user_id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    exit("Record not found or access denied.");
}

$parts = array_filter(array_map('trim', explode(',', $record['parts_replaced'] ?? '')));
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title>Maintenance Service Report - #<?= $maintenance_id ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #004d80;
            --light-bg: #f4f7f9;
        }
        body {
            background-color: var(--light-bg);
            font-family: 'Segoe UI', Arial, sans-serif;
            padding: 30px;
        }
        .report-container {
            max-width: 700px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
        }
        .header-section {
            background-color: var(--primary-color);
            color: white;
            padding: 25px 35px;
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
        }
        .header-section h1 { font-weight: 600; margin-bottom: 0; font-size: 1.8rem; }
        .header-section p.lead { font-size: 0.95rem; }
        .header-section h5 { font-size: 1rem; }
        .report-body { padding: 25px 35px; }
        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            border-bottom: 1px dashed #eee;
        }
        .detail-item strong {
            font-weight: 600;
            color: var(--primary-color);
            width: 170px;
            flex-shrink: 0;
            font-size: 0.9rem;
        }
        .detail-item span {
            text-align: right;
            color: #343a40;
            font-size: 0.9rem;
            flex-grow: 1;
        }
        .section-title {
            color: var(--primary-color);
            font-weight: 600;
            border-bottom: 2px solid #ddd;
            padding-bottom: 4px;
            margin-top: 25px;
            margin-bottom: 12px;
            font-size: 1.2rem;
        }
        @media print {
            body { background-color: white; padding: 0; }
            .report-container {
                max-width: 100%;
                box-shadow: none;
                border-radius: 0;
                margin: 0;
            }
            .no-print { display: none !important; }
            .header-section, .report-body { padding: 20px 30px; }
            .header-section h1 { font-size: 1.7rem; }
            .section-title { margin-top: 20px; margin-bottom: 10px; font-size: 1.1rem; }
            .detail-item strong, .detail-item span { font-size: 0.8rem; }
        }
    </style>
    <link rel="stylesheet" href="fonts.css">
</head>
<body>

<div class="report-container">
    <div class="no-print p-3 bg-light text-center">
        <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer me-2"></i> Print / Save as PDF</button>
        <a href="customer_maintenance_history.php" class="btn btn-outline-secondary ms-2"><i class="bi bi-arrow-left-circle me-2"></i> Back to History</a>
    </div>

    <div class="header-section d-flex justify-content-between align-items-center">
        <div>
            <h1>Maintenance Service Report</h1>
            <p class="lead mb-0 mt-1">
                <span class="badge bg-primary fs-6">Completed</span>
                &nbsp; Service #<?= $maintenance_id ?>
            </p>
        </div>
        <div class="text-end">
            <h5 class="mb-0">Date: <?= date('M d, Y') ?></h5>
        </div>
    </div>

    <div class="report-body">
        <h4 class="section-title"><i class="bi bi-calendar-check me-2"></i> Service Summary</h4>
        <div class="row">
            <div class="col-md-6">
                <div class="detail-item"><strong>Service Date:</strong> <span><?= date('F j, Y', strtotime($record['service_date'])) ?></span></div>
                <div class="detail-item"><strong>Mileage:</strong> <span><?= number_format($record['mileage']) ?> km</span></div>
                <div class="detail-item"><strong>Service Type:</strong> <span><?= htmlspecialchars($record['service_type']) ?></span></div>
            </div>
            <div class="col-md-6">
                <div class="detail-item"><strong>Vehicle:</strong> <span><?= htmlspecialchars(trim(($record['year_model'] ?? '') . ' ' . $record['brand'] . ' ' . $record['model'])) ?></span></div>
                <div class="detail-item"><strong>Plate No:</strong> <span><?= htmlspecialchars($record['plate_number']) ?></span></div>
                <div class="detail-item"><strong>Performed By:</strong> <span><?= $record['performed_by'] ? htmlspecialchars($record['performed_by']) : '—' ?></span></div>
            </div>
        </div>

        <h4 class="section-title"><i class="bi bi-list-check me-2"></i> Parts & Services</h4>
        <?php if (!empty($parts)): ?>
            <ul>
                <?php foreach ($parts as $part): ?>
                    <li><?= htmlspecialchars($part) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="text-muted">No parts or services listed.</p>
        <?php endif; ?>

        <h4 class="section-title"><i class="bi bi-chat-left-text me-2"></i> Remarks</h4>
        <p><?= $record['mechanic_remarks'] ? htmlspecialchars($record['mechanic_remarks']) : 'No remarks.' ?></p>

        <h4 class="section-title"><i class="bi bi-cash me-2"></i> Cost</h4>
        <div class="detail-item">
            <strong>Total Cost:</strong>
            <span class="fw-bold text-success fs-5">₱<?= number_format($record['cost'], 2) ?></span>
        </div>
    </div>
</div>

</body>
</html>
