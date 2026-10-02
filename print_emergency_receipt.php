<?php
session_start();
require 'db.php'; // Your database connection file

// 1. Validate ID and User
if (!isset($_GET['request_id']) || !isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['customer', 'admin'])) {
    header("Location: login.php");
    exit;
}

$request_id = (int)$_GET['request_id'];
$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

// 2. Fetch Full Emergency Request Data
$req_sql = "
    SELECT
        esr.*,
        m.brand AS moto_brand, m.model AS moto_model, m.year_model, m.plate_number, m.image AS moto_image,
        u.name AS customer_name, u.username AS customer_username, u.email AS customer_email, u.phone AS customer_phone,
        mech.name AS mechanic_name
    FROM emergency_service_requests esr
    LEFT JOIN motorcycles m ON esr.motorcycle_id = m.id
    LEFT JOIN users u ON esr.customer_id = u.id
    LEFT JOIN mechanics mech ON esr.assigned_mechanic_id = mech.id
    WHERE esr.id = ?
";
$req_params = [$request_id];
// Customers can only view their own requests; admins can view any
if (!$is_admin) {
    $req_sql .= " AND esr.customer_id = ?";
    $req_params[] = $user_id;
}
$stmt = $pdo->prepare($req_sql);
$stmt->execute($req_params);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) {
    exit("Emergency request not found or access denied.");
}

$status = strtolower(trim($req['request_status'] ?? ''));
if (in_array($status, ['pending', 'new'])) $group = 'pending';
elseif (in_array($status, ['accepted', 'assigned', 'accept'])) $group = 'accepted';
elseif (in_array($status, ['declined', 'rejected', 'decline', 'reject'])) $group = 'declined';
elseif (in_array($status, ['completed', 'complete'])) $group = 'completed';
else $group = 'pending';

$is_tow = (($req['service_type'] ?? 'onsite_repair') === 'tow_service');
$service_label = $is_tow ? 'Tow & Lift' : 'Fix On-Site';

$vehicle_display = trim("{$req['year_model']} {$req['moto_brand']} {$req['moto_model']}");
$customer_display = htmlspecialchars(($req['customer_name'] ?: $req['customer_username']) ?: 'N/A');
$customer_email = htmlspecialchars($req['customer_email'] ?: '');
$customer_phone = htmlspecialchars($req['customer_phone'] ?: '');
$mechanic_display = !empty($req['mechanic_name']) ? htmlspecialchars($req['mechanic_name']) : 'Not assigned';
$issue = htmlspecialchars($req['motorcycle_issue'] ?? 'N/A');
$priority = htmlspecialchars(ucfirst($req['priority'] ?? 'normal'));
$description = $req['problem_description'] ?? '';
$location = htmlspecialchars($req['location_description'] ?? ($req['location'] ?? 'No location'));
$admin_response = $req['admin_response'] ?? '';
$contact = !empty($req['contact_number']) ? htmlspecialchars($req['contact_number']) : 'No contact';
$coords = (!empty($req['latitude']) && !empty($req['longitude']))
    ? number_format($req['latitude'], 5) . ', ' . number_format($req['longitude'], 5)
    : '';
$created = !empty($req['created_at']) ? date('M d, Y g:i A', strtotime($req['created_at'])) : 'N/A';
$updated = !empty($req['updated_at']) ? date('M d, Y g:i A', strtotime($req['updated_at'])) : 'N/A';

$document_title = "Emergency Request";
$document_header = "Emergency Request";
$status_text = ucfirst($status);
$pill_class = 'invalid';

if ($group === 'completed') {
    $document_title = "Emergency Service Report";
    $document_header = "Service Report";
    $status_text = 'Completed';
    $pill_class = 'completed';
} elseif ($group === 'accepted') {
    $document_title = "Emergency Request Slip";
    $document_header = "Request Slip";
    $status_text = 'Accepted';
    $pill_class = 'accepted';
} elseif ($group === 'pending') {
    $status_text = 'Pending';
    $pill_class = 'pending';
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title><?= $document_title ?> - #<?= sprintf('%04d', $request_id) ?></title>
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
        .pill.pending { background: rgba(250, 204, 21, 0.15); color: #fde047; border-color: rgba(253, 224, 71, 0.4); }
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

        /* Key-value rows */
        .kv { width: 100%; border-collapse: collapse; }
        .kv td {
            padding: 8px 0;
            border-bottom: 1px solid #eef2f7;
            font-size: 0.8rem;
            vertical-align: top;
        }
        .kv td.k {
            width: 140px;
            font-size: 0.62rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
            padding-top: 10px;
        }
        .kv td.val { color: var(--ink); font-weight: 600; }
        .kv tr:last-child td { border-bottom: none; }

        /* Attached photo */
        .evidence-img {
            width: 100%;
            max-height: 220px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid var(--line);
            display: block;
            margin-top: 4px;
        }

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
    <a href="<?= $is_admin ? 'emergency_status.php' : 'customer_emergency_service.php' ?>" class="tbtn back"><i class="bi bi-arrow-left"></i> Back</a>
    <button onclick="window.print()" class="tbtn print"><i class="bi bi-printer"></i> Print Document</button>
</div>

<div class="receipt">

    <div class="receipt-head">
        <div class="brand">
            <div class="brand-mark"><i class="bi bi-exclamation-triangle"></i></div>
            <div>
                <div class="brand-name">Advance Motorcycle Service</div>
                <div class="brand-sub">Official Service Document</div>
            </div>
        </div>
        <div class="doc-block">
            <div class="doc-type"><?= $document_header ?></div>
            <div class="doc-no">#<?= sprintf('%04d', $request_id) ?></div>
            <span class="pill <?= $pill_class ?>"><?= $status_text ?></span>
        </div>
    </div>

    <div class="meta-strip">
        <span><b>Issued:</b> <?= date('M d, Y') ?></span>
        <span><b>Created:</b> <?= $created ?></span>
        <span><b>Updated:</b> <?= $updated ?></span>
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
                <div class="s">Plate: <?= htmlspecialchars($req['plate_number'] ?: 'N/A') ?></div>
            </div>
            <div class="info-card">
                <h6>Assigned Mechanic</h6>
                <div class="v"><?= $mechanic_display ?></div>
            </div>
        </div>

        <div class="section-label">Request Details</div>
        <table class="kv">
            <tr><td class="k">Issue Type</td><td class="val"><?= $issue ?></td></tr>
            <tr><td class="k">Service Type</td><td class="val"><?= $service_label ?></td></tr>
            <tr><td class="k">Priority</td><td class="val"><?= $priority ?></td></tr>
            <tr><td class="k">Contact</td><td class="val"><?= $contact ?></td></tr>
            <?php if ($coords): ?>
            <tr><td class="k">Coordinates</td><td class="val"><?= $coords ?></td></tr>
            <?php endif; ?>
            <?php if ($location !== 'No location'): ?>
            <tr><td class="k">Location</td><td class="val"><?= $location ?></td></tr>
            <?php endif; ?>
            <?php if (trim($description) !== ''): ?>
            <tr><td class="k">Description</td><td class="val"><?= nl2br(htmlspecialchars($description)) ?></td></tr>
            <?php endif; ?>
            <tr><td class="k">Admin Response</td><td class="val"><?= !empty($admin_response) ? nl2br(htmlspecialchars($admin_response)) : 'No response recorded' ?></td></tr>
        </table>

        <?php if (!empty($req['image_path'])): ?>
        <div class="section-label" style="margin-top: 18px;">Attached Photo</div>
        <img class="evidence-img" src="<?= htmlspecialchars($req['image_path']) ?>" alt="Emergency photo">
        <?php endif; ?>

        <?php if ($group === 'completed'): ?>
        <div class="note">
            <strong>Service Completed.</strong> This emergency service request has been resolved and marked as complete.
        </div>
        <?php elseif ($group === 'accepted'): ?>
        <div class="note">
            <strong>Request Accepted.</strong> A mechanic has been assigned to this emergency request.
        </div>
        <?php elseif ($group === 'declined'): ?>
        <div class="note danger">This emergency request was declined.</div>
        <?php else: ?>
        <div class="note">
            <strong>Request Pending.</strong> This emergency request is awaiting admin response.
        </div>
        <?php endif; ?>

    </div>

    <div class="receipt-foot">
        Thank you for choosing Advance Motorcycle Service &middot; This is a computer-generated document.
    </div>

</div>

</body>
</html>
