<?php
session_start();
require 'db.php';
require_once 'feedback_helper.php';

// Only logged-in customers can submit feedback.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Customer';
ensure_feedback_table($pdo);

$type = $_GET['type'] ?? $_POST['feedback_type'] ?? '';
$id   = (int)($_GET['id'] ?? $_POST['reference_id'] ?? 0);
$return_url = $type === 'emergency' ? 'customer_emergency_service.php' : 'my_bookings.php#completed';

function feedback_redirect($url, $msg, $type) {
    $_SESSION['feedback_flash'] = ['msg' => $msg, 'type' => $type];
    header("Location: " . $url);
    exit;
}

if (!in_array($type, ['booking', 'emergency'], true) || $id <= 0) {
    feedback_redirect('my_bookings.php', 'Invalid feedback request.', 'error');
}

// ---------------------------------------------------------------
// Load the booking / emergency request and validate ownership+status
// ---------------------------------------------------------------
$context = null;
$existing = null;

if ($type === 'booking') {
    $stmt = $pdo->prepare("
        SELECT b.*, CONCAT(COALESCE(v.brand,''), ' ', COALESCE(v.model,''), ' (', COALESCE(v.plate_number,''), ')') AS vehicle_info
        FROM bookings b
        LEFT JOIN motorcycles v ON b.vehicle_id = v.id
        WHERE b.id = ? AND b.user_id = ?
    ");
    $stmt->execute([$id, $user_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        feedback_redirect('my_bookings.php', 'Booking not found.', 'error');
    }
    if (strtolower($booking['status']) !== 'completed') {
        feedback_redirect('my_bookings.php', 'Feedback can only be submitted for completed bookings.', 'warning');
    }

    $mechanic = get_booking_primary_mechanic($pdo, $id);
    $context = [
        'ref_label'   => 'Booking #' . $id,
        'vehicle'     => trim($booking['vehicle_info']) !== '()' ? $booking['vehicle_info'] : 'N/A',
        'date'        => !empty($booking['schedule_date']) ? date('M d, Y', strtotime($booking['schedule_date'])) : 'N/A',
        'mechanic_id'   => $mechanic['id'] ?? null,
        'mechanic_name' => $mechanic['name'] ?? null,
    ];
    $existing = get_booking_feedback($pdo, $id);
} else {
    $stmt = $pdo->prepare("
        SELECT esr.*, CONCAT(m.brand, ' ', m.model, ' (', m.plate_number, ')') AS vehicle_info,
               mech.name AS assigned_mechanic_name
        FROM emergency_service_requests esr
        JOIN motorcycles m ON esr.motorcycle_id = m.id
        LEFT JOIN mechanics mech ON esr.assigned_mechanic_id = mech.id
        WHERE esr.id = ? AND esr.customer_id = ?
    ");
    $stmt->execute([$id, $user_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        feedback_redirect('customer_emergency_service.php', 'Emergency request not found.', 'error');
    }
    if (strtolower($request['request_status']) !== 'completed') {
        feedback_redirect('customer_emergency_service.php', 'Feedback can only be submitted for completed emergency requests.', 'warning');
    }

    $context = [
        'ref_label'   => 'Emergency #' . sprintf('%04d', $id),
        'vehicle'     => $request['vehicle_info'],
        'date'        => !empty($request['created_at']) ? date('M d, Y', strtotime($request['created_at'])) : 'N/A',
        'mechanic_id'   => $request['assigned_mechanic_id'] ?: null,
        'mechanic_name' => $request['assigned_mechanic_name'] ?: null,
    ];
    $existing = get_emergency_feedback($pdo, $id);
}

// ---------------------------------------------------------------
// Handle submission
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    if ($existing) {
        feedback_redirect($return_url, 'You have already submitted feedback for this service.', 'warning');
    }

    $service_rating  = (int)($_POST['service_rating'] ?? 0);
    $mechanic_rating = (int)($_POST['mechanic_rating'] ?? 0);
    $comments        = trim($_POST['comments'] ?? '');

    if ($service_rating < 1 || $service_rating > 5) {
        feedback_redirect("rate_service.php?type={$type}&id={$id}", 'Please select a customer service rating.', 'error');
    }
    if ($context['mechanic_id'] && ($mechanic_rating < 1 || $mechanic_rating > 5)) {
        feedback_redirect("rate_service.php?type={$type}&id={$id}", 'Please select a mechanic rating.', 'error');
    }
    $mechanic_rating_db = $context['mechanic_id'] ? $mechanic_rating : null;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO service_feedback
                (customer_id, feedback_type, booking_id, emergency_request_id, mechanic_id, mechanic_rating, service_rating, comments)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $type,
            $type === 'booking' ? $id : null,
            $type === 'emergency' ? $id : null,
            $context['mechanic_id'],
            $mechanic_rating_db,
            $service_rating,
            $comments !== '' ? $comments : null
        ]);
        feedback_redirect($return_url, 'Thank you! Your feedback has been submitted.', 'success');
    } catch (PDOException $e) {
        error_log('Feedback submit failed: ' . $e->getMessage());
        if ($e->getCode() === '23000') {
            feedback_redirect($return_url, 'You have already submitted feedback for this service.', 'warning');
        }
        feedback_redirect("rate_service.php?type={$type}&id={$id}", 'Could not save your feedback. Please try again.', 'error');
    }
}

// Flash (e.g. validation error redirects back here)
$toast_msg = '';
$toast_type = 'info';
if (isset($_SESSION['feedback_flash'])) {
    $toast_msg = $_SESSION['feedback_flash']['msg'];
    $toast_type = $_SESSION['feedback_flash']['type'];
    unset($_SESSION['feedback_flash']);
}

$active_page = basename($_SERVER['PHP_SELF']);
$pageTitle = 'Rate Your Service';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <script>
        (function () {
            var t = localStorage.getItem('theme');
            if (localStorage.getItem('themeManual') !== '1' || (t !== 'dark' && t !== 'light')) {
                t = 'light';
                localStorage.setItem('theme', t);
            }
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="fonts.css">
    <style>
        :root {
            --primary-color: #0f172a;
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
            --accent-color: #3b82f6;
            --accent-gradient: linear-gradient(135deg, #3b82f6 0%, #FACC15 100%);
            --bg-light: #f8fafc;
            --text-dark: #1e293b;
            --text-light: #64748b;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-light);
            display: flex;
            min-height: 100vh;
        }
        .main-content {
            flex: 1;
            margin-left: 280px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .top-bar {
            background: var(--primary-gradient);
            padding: 15px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1020;
            border-bottom: 2px solid rgba(250, 204, 21, 0.3);
        }
        .top-bar-title { font-size: 1.2rem; font-weight: 700; color: white; }
        .top-bar-user { display: flex; align-items: center; gap: 12px; }
        .top-bar-user-avatar {
            width: 40px; height: 40px; background: #1e3a5f; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 600; border: 2px solid rgba(255,255,255,0.2);
        }
        .top-bar-user-name { color: white; font-size: 0.9rem; font-weight: 600; }
        .top-bar-user-role { color: rgba(255,255,255,0.7); font-size: 0.75rem; }
        .content-area { padding: 30px; flex: 1; }

        .feedback-card {
            max-width: 640px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .feedback-header {
            background: var(--primary-gradient);
            color: white;
            padding: 24px 28px;
        }
        .feedback-header h4 { font-size: 1.1rem; font-weight: 700; margin-bottom: 4px; }
        .feedback-header p { font-size: 0.82rem; opacity: 0.8; margin: 0; }
        .feedback-body { padding: 24px 28px; font-size: 0.85rem; }

        .context-row {
            display: flex; justify-content: space-between; padding: 8px 0;
            border-bottom: 1px solid #f1f5f9; font-size: 0.82rem;
        }
        .context-row .label { color: var(--text-light); font-weight: 500; }
        .context-row .value { color: var(--text-dark); font-weight: 600; text-align: right; }

        .rating-group { margin-top: 20px; }
        .rating-label { font-weight: 600; font-size: 0.85rem; color: var(--text-dark); margin-bottom: 8px; display: block; }
        .rating-label small { color: var(--text-light); font-weight: 400; }

        .star-input { display: flex; gap: 6px; }
        .star-input .star-btn {
            background: none; border: none; padding: 2px;
            font-size: 1.9rem; color: #cbd5e1; cursor: pointer;
            transition: transform 0.15s ease, color 0.15s ease;
            line-height: 1;
        }
        .star-input .star-btn:hover { transform: scale(1.15); }
        .star-input .star-btn.active { color: #EAB308; }
        .star-value-text { font-size: 0.78rem; color: var(--text-light); margin-top: 4px; }

        .btn-submit-feedback {
            background: var(--primary-gradient);
            border: none; color: white; font-weight: 600;
            padding: 10px 28px; border-radius: 50px;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.3);
        }
        .btn-submit-feedback:hover { color: white; transform: translateY(-2px); }
        .btn-back-link { color: var(--text-light); font-size: 0.82rem; text-decoration: none; }
        .btn-back-link:hover { color: var(--accent-color); }

        .submitted-box {
            text-align: center; padding: 20px 0 4px;
        }
        .submitted-box .check-icon {
            width: 64px; height: 64px; border-radius: 50%;
            background: rgba(16, 185, 129, 0.12); color: #10b981;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 2rem; margin-bottom: 12px;
        }
        .submitted-comment {
            background: #f8fafc; border-radius: 12px; padding: 14px 16px;
            text-align: left; font-size: 0.82rem; color: var(--text-dark);
            border: 1px solid #e2e8f0; margin-top: 14px;
        }

        /* --- Dark mode --- */
        html[data-theme="dark"] .feedback-card {
            background: #1a2b4f;
            border: 1px solid rgba(255, 255, 255, 0.09);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.35);
        }
        html[data-theme="dark"] .feedback-body,
        html[data-theme="dark"] .context-row .value,
        html[data-theme="dark"] .rating-label { color: #e2e8f0; }
        html[data-theme="dark"] .context-row { border-bottom-color: rgba(255, 255, 255, 0.08); }
        html[data-theme="dark"] .context-row .label,
        html[data-theme="dark"] .rating-label small,
        html[data-theme="dark"] .star-value-text,
        html[data-theme="dark"] .btn-back-link { color: #94a3b8; }
        html[data-theme="dark"] .star-input .star-btn { color: #3b4d7d; }
        html[data-theme="dark"] .star-input .star-btn.active { color: #EAB308; }
        html[data-theme="dark"] .submitted-comment {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.1);
            color: #e2e8f0;
        }
    </style>
</head>
<body>

<?php include 'customer_sidebar.php'; ?>

<div class="main-content">
    <div class="top-bar">
        <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
            <h1 class="top-bar-title">Rate Your Service</h1>
        </div>
        <div class="top-bar-user">
            <div class="top-bar-user-avatar"><?= strtoupper(substr($username, 0, 1)) ?></div>
            <div class="top-bar-user-info">
                <span class="top-bar-user-name"><?= htmlspecialchars($username) ?></span>
                <span class="top-bar-user-role">Customer Account</span>
            </div>
        </div>
    </div>

    <div class="content-area">
        <div class="feedback-card">
            <div class="feedback-header">
                <h4><i class="bi bi-star-fill me-2" style="color:#FACC15;"></i><?= $existing ? 'Your Feedback' : 'How was your experience?' ?></h4>
                <p><?= $existing ? 'You already submitted feedback for this service.' : 'Your feedback helps us improve our service.' ?></p>
            </div>
            <div class="feedback-body">
                <div class="context-row"><span class="label">Reference</span><span class="value"><?= htmlspecialchars($context['ref_label']) ?></span></div>
                <div class="context-row"><span class="label">Vehicle</span><span class="value"><?= htmlspecialchars($context['vehicle']) ?></span></div>
                <div class="context-row"><span class="label">Date</span><span class="value"><?= htmlspecialchars($context['date']) ?></span></div>
                <div class="context-row" style="border-bottom:none;"><span class="label">Mechanic</span><span class="value"><?= $context['mechanic_name'] ? htmlspecialchars($context['mechanic_name']) : 'Not assigned' ?></span></div>

                <?php if ($existing): ?>
                    <div class="submitted-box">
                        <div class="check-icon"><i class="bi bi-check-lg"></i></div>
                        <?php if ($existing['mechanic_rating'] !== null): ?>
                            <div class="mb-2">
                                <div class="rating-label">Mechanic Rating</div>
                                <?= render_stars((int)$existing['mechanic_rating']) ?>
                            </div>
                        <?php endif; ?>
                        <div class="mb-2">
                            <div class="rating-label">Customer Service Rating</div>
                            <?= render_stars((int)$existing['service_rating']) ?>
                        </div>
                        <?php if (!empty($existing['comments'])): ?>
                            <div class="submitted-comment">
                                <i class="bi bi-chat-left-quote me-1" style="color:var(--accent-color);"></i><?= nl2br(htmlspecialchars($existing['comments'])) ?>
                            </div>
                        <?php endif; ?>
                        <a href="<?= $return_url ?>" class="btn btn-submit-feedback mt-4"><i class="bi bi-arrow-left me-1"></i>Back</a>
                    </div>
                <?php else: ?>
                <form method="POST" id="feedbackForm">
                    <input type="hidden" name="submit_feedback" value="1">
                    <input type="hidden" name="feedback_type" value="<?= htmlspecialchars($type) ?>">
                    <input type="hidden" name="reference_id" value="<?= (int)$id ?>">

                    <?php if ($context['mechanic_id']): ?>
                    <div class="rating-group">
                        <span class="rating-label">Rate your mechanic <small>(<?= htmlspecialchars($context['mechanic_name']) ?>)</small></span>
                        <div class="star-input" data-target="mechanic_rating">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <button type="button" class="star-btn" data-value="<?= $i ?>"><i class="bi bi-star-fill"></i></button>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" name="mechanic_rating" id="mechanic_rating" value="0">
                        <div class="star-value-text" id="mechanic_rating_text">Tap a star to rate</div>
                    </div>
                    <?php endif; ?>

                    <div class="rating-group">
                        <span class="rating-label">Rate our customer service</span>
                        <div class="star-input" data-target="service_rating">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <button type="button" class="star-btn" data-value="<?= $i ?>"><i class="bi bi-star-fill"></i></button>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" name="service_rating" id="service_rating" value="0">
                        <div class="star-value-text" id="service_rating_text">Tap a star to rate</div>
                    </div>

                    <div class="rating-group">
                        <label class="rating-label" for="comments">Notes about our customer service <small>(optional)</small></label>
                        <textarea class="form-control" name="comments" id="comments" rows="4" maxlength="1000"
                                  placeholder="Tell us what went well or what we could improve..."></textarea>
                    </div>

                    <div class="d-flex align-items-center justify-content-between mt-4">
                        <a href="<?= $return_url ?>" class="btn-back-link"><i class="bi bi-arrow-left me-1"></i>Back</a>
                        <button type="submit" class="btn btn-submit-feedback"><i class="bi bi-send me-1"></i>Submit Feedback</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include 'floating_toast.php'; ?>
<script>
    var ratingWords = {1: 'Poor', 2: 'Fair', 3: 'Good', 4: 'Very Good', 5: 'Excellent'};

    document.querySelectorAll('.star-input').forEach(function (group) {
        var targetId = group.getAttribute('data-target');
        var hidden = document.getElementById(targetId);
        var label = document.getElementById(targetId + '_text');
        var buttons = group.querySelectorAll('.star-btn');

        function paint(value) {
            buttons.forEach(function (btn) {
                btn.classList.toggle('active', parseInt(btn.dataset.value, 10) <= value);
            });
        }

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var value = parseInt(this.dataset.value, 10);
                hidden.value = value;
                paint(value);
                if (label) label.textContent = value + ' / 5 — ' + ratingWords[value];
            });
            btn.addEventListener('mouseenter', function () {
                paint(parseInt(this.dataset.value, 10));
            });
        });
        group.addEventListener('mouseleave', function () {
            paint(parseInt(hidden.value, 10) || 0);
        });
    });

    var form = document.getElementById('feedbackForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            var serviceRating = document.getElementById('service_rating');
            var mechanicRating = document.getElementById('mechanic_rating');
            if (mechanicRating && parseInt(mechanicRating.value, 10) < 1) {
                e.preventDefault();
                window.showToast ? showToast('Please select a mechanic rating.', 'warning') : alert('Please select a mechanic rating.');
                return;
            }
            if (!serviceRating || parseInt(serviceRating.value, 10) < 1) {
                e.preventDefault();
                window.showToast ? showToast('Please select a customer service rating.', 'warning') : alert('Please select a customer service rating.');
            }
        });
    }

    function confirmLogout() {
        window.location.href = 'logout.php';
    }
</script>
</body>
</html>
