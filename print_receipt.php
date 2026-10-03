<?php
session_start();
require 'db.php'; // Your database connection file

// 1. Validate ID and User
if (!isset($_GET['booking_id']) || !isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['customer', 'admin'])) {
    header("Location: login.php");
    exit;
}

$booking_id = (int)$_GET['booking_id'];
$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

// --- Utility Functions ---

/**
 * Fetches all mechanic names assigned to a single booking 
 * using the many-to-many junction table (booking_mechanics).
 */
function get_all_mechanic_names($pdo, $booking_id) {
    try {
        // 1. Try to get all mechanics from the junction table
        $stmt_ids = $pdo->prepare("SELECT mechanic_id FROM booking_mechanics WHERE booking_id = ?");
        $stmt_ids->execute([$booking_id]);
        $mechanic_ids = array_column($stmt_ids->fetchAll(PDO::FETCH_ASSOC), 'mechanic_id');

        if (empty($mechanic_ids)) {
            // 2. Fallback: If junction table is empty, check the single ID from the 'bookings' table
            $stmt_single = $pdo->prepare("SELECT m.name FROM bookings b LEFT JOIN mechanics m ON b.mechanic_id = m.id WHERE b.id = ? AND b.mechanic_id IS NOT NULL");
            $stmt_single->execute([$booking_id]);
            $single_name = $stmt_single->fetchColumn();
            return $single_name ? htmlspecialchars($single_name) : 'To Be Assigned';
        }

        // 3. Fetch the names for all collected IDs
        $placeholders = rtrim(str_repeat('?,', count($mechanic_ids)), ',');
        $stmt_names = $pdo->prepare("SELECT name FROM mechanics WHERE id IN ($placeholders)");
        $stmt_names->execute($mechanic_ids);
        $names = array_column($stmt_names->fetchAll(PDO::FETCH_ASSOC), 'name');
        
        return htmlspecialchars(implode(', ', $names));

    } catch (PDOException $e) {
        error_log("Error fetching multiple mechanics for booking ID " . $booking_id . ": " . $e->getMessage());
        return "Error fetching mechanics";
    }
}


function get_service_names_and_prices($pdo, $service_ids_json) {
    $ids = json_decode($service_ids_json, true);
    if (empty($ids) || !is_array($ids)) {
        return ['names' => "N/A", 'items' => []];
    }

    $placeholders = rtrim(str_repeat('?,', count($ids)), ',');
    try {
        $stmt = $pdo->prepare("SELECT service_name, price FROM services WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $names = array_column($services, 'service_name');
        return ['names' => implode(', ', $names), 'items' => $services];
    } catch (PDOException $e) {
        return ['names' => "Error fetching services", 'items' => []];
    }
}
// --- End Utility Functions ---


// 2. Fetch Full Booking Data
$booking_sql = "
    SELECT
        b.*,
        v.brand, v.model, v.year_model, v.plate_number,
        m.name AS mechanic_name,
        u.name AS customer_name, u.username AS customer_username, u.email AS customer_email, u.phone AS customer_phone,
        -- Fetch payment details
        p.payment_method,
        p.amount AS payment_amount,
        p.status AS payment_status,
        p.transaction_ref,
        p.transaction_type
    FROM bookings b
    LEFT JOIN motorcycles v ON b.vehicle_id = v.id
    LEFT JOIN mechanics m ON b.mechanic_id = m.id
    LEFT JOIN users u ON b.user_id = u.id
    -- JOIN payments table to fetch any payment details related to this booking
    LEFT JOIN payments p ON b.id = p.booking_id
    WHERE b.id = ?
";
$booking_params = [$booking_id];
// Customers can only view their own bookings; admins can view any
if (!$is_admin) {
    $booking_sql .= " AND b.user_id = ?";
    $booking_params[] = $user_id;
}
$stmt = $pdo->prepare($booking_sql);
$stmt->execute($booking_params);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    exit("Booking not found or access denied.");
}

// === NEW TIME CALCULATION LOGIC ===
$start_time_ts = strtotime($booking['schedule_start_time']);
$end_time_ts = strtotime('+1 hour', $start_time_ts); // Assuming a default 1-hour service duration
$time_display = date('h:i A', $start_time_ts) . ' - ' . date('h:i A', $end_time_ts);
// ==================================

// Ensure defaults are set if the LEFT JOIN found no payment record
$has_payment_record = !empty($booking['payment_method']); 
$booking['payment_method'] = $booking['payment_method'] ?? 'N/A';
$booking['payment_amount'] = (float)($booking['payment_amount'] ?? 0);
$booking['payment_status'] = $booking['payment_status'] ?? 'unpaid';
$booking['transaction_ref'] = $booking['transaction_ref'] ?? null; 
$booking['transaction_type'] = $booking['transaction_type'] ?? null; 

// Determine which amount to treat as a verified deposit for calculation purposes
$deposit_amount = ($booking['transaction_type'] === 'deposit' && $booking['payment_status'] === 'verified') 
                ? $booking['payment_amount'] 
                : 0; 

$total_price = (float)($booking['total_price'] ?? 0);

$status = strtolower($booking['status']);
$services_data = get_service_names_and_prices($pdo, $booking['service_ids']);
$assigned_mechanics_list = get_all_mechanic_names($pdo, $booking_id);

$subtotal = 0;
foreach ($services_data['items'] as $item) {
    $subtotal += (float)$item['price'];
}
$final_amount_due = $subtotal - $deposit_amount;

$vehicle_display = trim("{$booking['year_model']} {$booking['brand']} {$booking['model']}");
$customer_display = htmlspecialchars(($booking['customer_name'] ?: $booking['customer_username']) ?: 'N/A');
$customer_email = htmlspecialchars($booking['customer_email'] ?: '');
$customer_phone = htmlspecialchars($booking['customer_phone'] ?: '');

$document_title = "Document";
$document_header = "Booking Details";
$status_text = ucfirst($status);
$pill_class = 'invalid';

// Determine document titles and styles based on the status check
if ($status === 'accepted') {
    $document_title = "Appointment Confirmation Slip";
    $document_header = "Confirmation Slip";
    $status_text = 'Accepted';
    $pill_class = 'accepted';
} elseif ($status === 'completed') {
    $document_title = "Final Service Invoice (Receipt)";
    $document_header = "Final Invoice";
    $status_text = 'Completed';
    $pill_class = 'completed';
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light" class="public-page">
<head>
    <meta charset="UTF-8">
    <title><?= $document_title ?> - #<?= sprintf('%04d', $booking_id) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --navy-dark: #0f172a;
            --navy: #1e3a5f;
            --gold: #FACC15;
            --ink: #111827;
            --muted: #6b7280;
            --line: #e5e7eb;
            --soft: #f8fafc;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 50%, #f1f5f9 100%);
            font-family: 'Segoe UI', Arial, sans-serif;
            color: var(--ink);
            padding: 24px 12px;
        }

        /* Toolbar (screen only) */
        .toolbar {
            max-width: 640px;
            margin: 0 auto 14px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .tbtn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid var(--line);
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease, visibility 0.15s ease, max-height 0.15s ease;
            font-family: inherit;
        }
        .tbtn.print { background: var(--navy); color: #fff; border-color: var(--navy); }
        .tbtn.print:hover { background: var(--navy-dark); }
        .tbtn.back { background: #fff; color: var(--ink); }
        .tbtn.back:hover { background: var(--soft); }

        /* Receipt card */
        .receipt {
            max-width: 640px;
            margin: 0 auto;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
            overflow: hidden;
        }

        /* Header — matches admin sidebar navy gradient + gold accent */
        .receipt-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 22px 28px;
            background: linear-gradient(135deg, var(--navy-dark) 0%, var(--navy) 100%);
            color: #fff;
            position: relative;
        }
        .receipt-head::after {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 3px;
            background: linear-gradient(90deg, #3b82f6 0%, var(--gold) 100%);
        }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand-mark {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: rgba(250, 204, 21, 0.15);
            border: 1px solid rgba(250, 204, 21, 0.4);
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .brand-name { font-size: 0.95rem; font-weight: 800; color: #fff; }
        .brand-sub { font-size: 0.66rem; color: rgba(255, 255, 255, 0.6); margin-top: 2px; }
        .doc-block { text-align: right; }
        .doc-type {
            font-size: 0.6rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            color: var(--gold);
        }
        .doc-no { font-size: 1.35rem; font-weight: 800; color: #fff; line-height: 1.15; }
        .pill {
            display: inline-block;
            margin-top: 5px;
            padding: 3px 10px;
            border-radius: 99px;
            font-size: 0.58rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border: 1px solid;
        }
        .pill.accepted { background: rgba(16, 185, 129, 0.15); color: #34d399; border-color: rgba(52, 211, 153, 0.4); }
        .pill.completed { background: rgba(59, 130, 246, 0.15); color: #93c5fd; border-color: rgba(147, 197, 253, 0.4); }
        .pill.invalid { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border-color: rgba(252, 165, 165, 0.4); }

        /* Meta strip */
        .meta-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 24px;
            padding: 10px 28px;
            background: var(--soft);
            border-bottom: 1px solid var(--line);
            font-size: 0.74rem;
            color: var(--muted);
        }
        .meta-strip b { color: var(--ink); font-weight: 700; }

        .receipt-body { padding: 20px 28px 24px; }

        /* Info cards */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }
        .info-card {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
        }
        .info-card h6 {
            font-size: 0.56rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--navy);
            margin-bottom: 4px;
        }
        .info-card .v { font-size: 0.8rem; font-weight: 700; color: var(--ink); }
        .info-card .s { font-size: 0.68rem; color: var(--muted); margin-top: 1px; }

        /* Section label */
        .section-label {
            font-size: 0.6rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--muted);
            margin-bottom: 6px;
        }

        /* Items table */
        .items { width: 100%; border-collapse: collapse; }
        .items th {
            font-size: 0.58rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            text-align: left;
            padding: 8px 0;
            border-bottom: 2px solid var(--navy);
        }
        .items td {
            padding: 9px 0;
            border-bottom: 1px solid #eef2f7;
            font-size: 0.8rem;
        }
        .items .price { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }

        /* Totals */
        .totals { width: 250px; margin-left: auto; margin-top: 4px; }
        .totals .row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 0.78rem;
            color: var(--ink);
        }
        .totals .row.deposit { color: #047857; font-weight: 700; }
        .totals .row.grand {
            border-top: 2px solid var(--navy);
            margin-top: 5px;
            padding-top: 9px;
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--navy);
        }

        /* Payment chips */
        .pay-strip { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 20px; }
        .pay-chip {
            background: var(--soft);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 0.7rem;
            color: var(--muted);
        }
        .pay-chip b { color: var(--ink); font-weight: 700; }
        .pay-chip .ok { color: #047857; font-weight: 800; }
        .pay-chip .warn { color: #b45309; font-weight: 800; }

        /* Note box */
        .note {
            margin-top: 20px;
            border-left: 3px solid var(--gold);
            background: var(--soft);
            border-radius: 0 8px 8px 0;
            padding: 10px 14px;
            font-size: 0.74rem;
            color: #4b5563;
            line-height: 1.5;
        }
        .note strong { color: var(--ink); }
        .note ul { margin: 5px 0 0 16px; }
        .note.danger { border-left-color: #ef4444; color: #b91c1c; font-weight: 700; }

        /* Footer */
        .receipt-foot {
            padding: 14px 28px;
            border-top: 1px dashed var(--line);
            text-align: center;
            font-size: 0.66rem;
            color: #9ca3af;
        }

        @media (max-width: 560px) {
            .receipt-head { flex-direction: column; align-items: flex-start; }
            .doc-block { text-align: left; }
            .info-grid { grid-template-columns: 1fr; }
            .totals { width: 100%; }
        }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none !important; }
            .receipt { box-shadow: none; border-radius: 0; max-width: 100%; }
            .receipt-head { padding: 18px 24px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .receipt-body { padding: 16px 24px 18px; }
            .meta-strip { padding: 8px 24px; }
            .receipt-foot { padding: 12px 24px; }
        }
    </style>
    <link rel="stylesheet" href="fonts.css">
</head>
<body>

<div class="toolbar">
    <a href="<?= $is_admin ? 'bookings_status.php' : 'my_bookings.php' ?>" class="tbtn back"><i class="bi bi-arrow-left"></i> Back</a>
    <button onclick="window.print()" class="tbtn print"><i class="bi bi-printer"></i> Print Document</button>
</div>

<div class="receipt">

    <div class="receipt-head">
        <div class="brand">
            <div class="brand-mark"><i class="bi bi-wrench-adjustable-circle"></i></div>
            <div>
                <div class="brand-name">Advance Motorcycle Service</div>
                <div class="brand-sub">Official Service Document</div>
            </div>
        </div>
        <div class="doc-block">
            <div class="doc-type"><?= $document_header ?></div>
            <div class="doc-no">#<?= sprintf('%04d', $booking_id) ?></div>
            <span class="pill <?= $pill_class ?>"><?= $status_text ?></span>
        </div>
    </div>

    <?php if ($status === 'accepted' || $status === 'completed'): ?>

    <div class="meta-strip">
        <span><b>Issued:</b> <?= date('M d, Y') ?></span>
        <span><b>Schedule:</b> <?= htmlspecialchars($booking['schedule_date']) ?> &middot; <?= $time_display ?></span>
        <span><b>Service(s):</b> <?= htmlspecialchars($services_data['names']) ?></span>
    </div>

    <div class="receipt-body">

        <div class="info-grid">
            <div class="info-card">
                <h6>Customer</h6>
                <div class="v"><?= $customer_display ?></div>
                <?php if ($customer_phone): ?><div class="s"><?= $customer_phone ?></div><?php endif; ?>
                <?php if ($customer_email): ?><div class="s"><?= $customer_email ?></div><?php endif; ?>
            </div>
            <div class="info-card">
                <h6>Vehicle</h6>
                <div class="v"><?= htmlspecialchars($vehicle_display ?: 'N/A') ?></div>
                <div class="s">Plate: <?= htmlspecialchars($booking['plate_number'] ?: 'N/A') ?></div>
            </div>
            <div class="info-card">
                <h6><?= $status === 'completed' ? 'Mechanic(s)' : 'Assigned Mechanic(s)' ?></h6>
                <div class="v"><?= $assigned_mechanics_list ?></div>
            </div>
        </div>

        <div class="section-label"><?= $status === 'completed' ? 'Itemized Charges' : 'Service Details' ?></div>
        <table class="items">
            <thead>
                <tr>
                    <th>Service / Item Description</th>
                    <th class="price" style="width: 130px;">Price</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($services_data['items'] as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['service_name']) ?></td>
                    <td class="price">₱<?= number_format($item['price'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="totals">
            <div class="row">
                <span>Subtotal</span>
                <span>₱<?= number_format($subtotal, 2) ?></span>
            </div>
            <?php if ($deposit_amount > 0): ?>
            <div class="row deposit">
                <span>Verified Deposit Applied</span>
                <span>-₱<?= number_format($deposit_amount, 2) ?></span>
            </div>
            <?php endif; ?>
            <div class="row grand">
                <span><?= $status === 'completed' ? 'Total Amount Due' : 'Estimated Total' ?></span>
                <span>₱<?= number_format($final_amount_due, 2) ?></span>
            </div>
        </div>

        <?php if ($has_payment_record): ?>
        <div class="pay-strip">
            <div class="pay-chip">Method: <b><?= htmlspecialchars(ucfirst($booking['payment_method'])) ?></b></div>
            <?php if (strtolower($booking['payment_method']) !== 'cash'): ?>
            <div class="pay-chip">Status: <span class="<?= $booking['payment_status'] === 'verified' ? 'ok' : 'warn' ?>"><?= htmlspecialchars(ucfirst($booking['payment_status'])) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($booking['transaction_ref'])): ?>
            <div class="pay-chip">Ref. No: <b><?= htmlspecialchars($booking['transaction_ref']) ?></b></div>
            <?php endif; ?>
            <div class="pay-chip">Amount Paid: <b>₱<?= number_format($booking['payment_amount'], 2) ?></b></div>
        </div>
        <?php endif; ?>

        <?php if ($status === 'accepted'): ?>
        <div class="note">
            <strong>Important:</strong> This confirms your service booking — please be ready on time.
            <ul>
                <?php if ($deposit_amount > 0): ?>
                <li>A verified deposit of <strong>₱<?= number_format($deposit_amount, 2) ?></strong> has been applied.</li>
                <li>Remaining balance of <strong>₱<?= number_format($total_price - $deposit_amount, 2) ?></strong> is due upon completion.</li>
                <?php else: ?>
                <li>The total estimated price is <strong>₱<?= number_format($total_price, 2) ?></strong>, due upon completion.</li>
                <?php endif; ?>
            </ul>
        </div>
        <?php else: ?>
        <div class="note">
            <strong>Service Completed.</strong>
            <?php if ($deposit_amount > 0): ?>
                A deposit was applied, leaving a final balance of <strong>₱<?= number_format($final_amount_due, 2) ?></strong>.
            <?php else: ?>
                The total service amount of <strong>₱<?= number_format($subtotal, 2) ?></strong> was charged.
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

    <?php else: ?>
    <div class="receipt-body">
        <div class="note danger">This document is only available for Accepted or Completed bookings.</div>
    </div>
    <?php endif; ?>

    <div class="receipt-foot">
        Thank you for choosing Advance Motorcycle Service &middot; This is a computer-generated document.
    </div>

</div>

</body>
</html>
