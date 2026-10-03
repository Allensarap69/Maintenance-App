<?php
session_start();
require 'db.php';
require_once 'feedback_helper.php';

ensure_feedback_table($pdo);

// --- Filter: all | booking | emergency ---
$filter = $_GET['type'] ?? 'all';
if (!in_array($filter, ['all', 'booking', 'emergency'], true)) {
    $filter = 'all';
}

// --- Stats ---
$stats = [
    'total' => 0,
    'avg_mechanic' => null,
    'avg_service' => null,
    'with_comments' => 0,
];
try {
    $row = $pdo->query("
        SELECT COUNT(*) AS total,
               AVG(mechanic_rating) AS avg_mechanic,
               AVG(service_rating) AS avg_service,
               SUM(CASE WHEN comments IS NOT NULL AND comments != '' THEN 1 ELSE 0 END) AS with_comments
        FROM service_feedback
    ")->fetch(PDO::FETCH_ASSOC);
    if ($row) $stats = $row;
} catch (PDOException $e) {
    error_log('Feedback stats error: ' . $e->getMessage());
}

// --- Feedback list ---
$feedback_rows = [];
try {
    $sql = "
        SELECT f.*,
               u.name AS customer_name, u.username AS customer_username,
               m.name AS mechanic_name
        FROM service_feedback f
        LEFT JOIN users u ON f.customer_id = u.id
        LEFT JOIN mechanics m ON f.mechanic_id = m.id
    ";
    if ($filter !== 'all') {
        $sql .= " WHERE f.feedback_type = " . $pdo->quote($filter);
    }
    $sql .= " ORDER BY f.created_at DESC";
    $feedback_rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Feedback list error: ' . $e->getMessage());
}

$pageTitle = 'Customer Feedback';
?>
<?php require 'admin_sidebar_template.php'; ?>

<style>
    .feedback-stat-card {
        border-radius: 12px;
        padding: 16px 18px;
        background: var(--bg-card);
        border: 1px solid var(--card-border, var(--border-color));
        display: flex;
        align-items: center;
        gap: 14px;
        height: 100%;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .feedback-stat-card:hover { transform: translateY(-2px); }
    .feedback-stat-icon {
        width: 46px; height: 46px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.3rem; flex-shrink: 0;
    }
    .fsi-primary { color: #3b82f6; }
    .fsi-warning { color: #EAB308; }
    .fsi-success { color: #10b981; }
    .fsi-info    { color: #06b6d4; }
    .feedback-stat-value { font-size: 1.4rem; font-weight: 700; line-height: 1.1; color: var(--text-dark); }
    .feedback-stat-label { font-size: 0.75rem; color: var(--text-light); font-weight: 600; }

    .feedback-table thead th {
        background-color: #f1f5f9;
        color: var(--text-dark);
        font-size: 0.78rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        text-align: center;
        white-space: nowrap;
        border-bottom: 2px solid var(--border-color);
        padding: 10px 12px;
    }
    .feedback-table td { vertical-align: middle; font-size: 0.82rem; text-align: center; }
    .type-badge { font-size: 0.7rem; padding: 4px 10px; border-radius: 50px; font-weight: 600; }
    .type-booking { background: rgba(59,130,246,0.1); color: #3b82f6; }
    .type-emergency { background: rgba(239,68,68,0.1); color: #ef4444; }
    .star-display { white-space: nowrap; }
    .star-display i { font-size: 0.85rem; }
    .rating-num { font-size: 0.75rem; color: var(--text-light); }

    /* --- Comments column --- */
    .feedback-table td.comments-cell {
        text-align: center;
        min-width: 220px;
        max-width: 340px;
    }
    .comment-line {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }
    .comment-snippet {
        flex: 0 1 auto;
        min-width: 0;
        color: var(--text-dark);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.5;
    }
    .comment-snippet i {
        color: var(--accent-color);
        margin-right: 4px;
        vertical-align: -0.08em;
    }
    .btn-view-comment {
        flex-shrink: 0;
        background: none;
        border: 1px solid var(--border-color);
        border-radius: 50px;
        padding: 2px 12px;
        font-size: 0.72rem;
        font-weight: 600;
        color: var(--accent-color);
        cursor: pointer;
        transition: background 0.2s ease, color 0.2s ease;
    }
    .btn-view-comment:hover {
        background: var(--accent-color);
        border-color: var(--accent-color);
        color: #fff;
    }
    .comment-empty { color: var(--text-light); }

    .filter-pills .btn {
        border-radius: 50px;
        font-size: 0.8rem;
        padding: 6px 16px;
        border-color: #0f172a;
        color: #0f172a;
        font-weight: 500;
    }
    .filter-pills .btn:hover,
    .filter-pills .btn:focus {
        background: #0f172a;
        color: #fff;
    }
    .filter-pills .btn.active {
        background: #0f172a;
        color: #fff;
        border-color: #0f172a;
    }

    /* --- Comment detail modal --- */
    .feedback-modal .modal-body { font-size: 0.85rem; }
    .fc-meta-row {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        border-bottom: 1px solid var(--border-color);
        font-size: 0.8rem;
    }
    .fc-meta-row span { color: var(--text-light); }
    .fc-meta-row strong { color: var(--text-dark); text-align: right; }
    .fc-ratings {
        display: flex;
        gap: 24px;
        padding: 10px 0;
    }
    .fc-rating-item { display: flex; align-items: center; gap: 8px; font-size: 0.8rem; }
    .fc-rating-item > span:first-child { color: var(--text-light); }
    .fc-comment-label {
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--text-light);
        margin: 8px 0 6px;
    }
    .fc-comment-box {
        background: #f8fafc;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 14px 16px;
        color: var(--text-dark);
        line-height: 1.6;
        white-space: pre-wrap;
        word-break: break-word;
        max-height: 300px;
        overflow-y: auto;
    }

    /* --- Dark mode --- */
    html[data-theme="dark"] .feedback-stat-card {
        background: var(--bg-card);
        border-color: var(--card-border);
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.35);
    }
    html[data-theme="dark"] .feedback-stat-card:hover {
        background: #22335a;
        border-color: rgba(250, 204, 21, 0.25);
    }
    html[data-theme="dark"] .feedback-table thead th {
        background: #22335a;
        color: #e2e8f0;
        border-bottom-color: rgba(255, 255, 255, 0.12);
    }
    html[data-theme="dark"] .type-booking { background: rgba(59,130,246,0.18); color: #93c5fd; }
    html[data-theme="dark"] .type-emergency { background: rgba(239,68,68,0.18); color: #fca5a5; }
    html[data-theme="dark"] .btn-view-comment { color: #FACC15; }
    html[data-theme="dark"] .filter-pills .btn,
    html[data-theme="dark"] .btn-outline-secondary {
        color: #cbd5e1;
        border-color: rgba(255, 255, 255, 0.25);
    }
    html[data-theme="dark"] .filter-pills .btn:hover,
    html[data-theme="dark"] .btn-outline-secondary:hover {
        background: rgba(255, 255, 255, 0.08);
        color: #fff;
    }
    html[data-theme="dark"] .filter-pills .btn.active {
        background: #FACC15;
        border-color: #FACC15;
        color: #111827;
    }
    html[data-theme="dark"] .fc-comment-box {
        background: rgba(255, 255, 255, 0.05);
        border-color: rgba(255, 255, 255, 0.1);
    }

    /* --- Fill available viewport height --- */
    .feedback-wrap {
        display: flex;
        flex-direction: column;
        min-height: calc(100vh - 140px);
    }
    .feedback-card {
        flex: 1;
        display: flex;
        flex-direction: column;
    }
    .feedback-card .table-responsive {
        flex: 1;
        max-height: max(240px, calc(100vh - 380px));
        overflow-y: auto;
    }
    .feedback-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
    }
    .feedback-table td.empty-cell {
        height: 45vh;
        vertical-align: middle;
    }
</style>

<div class="container-fluid py-4 feedback-wrap">
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="feedback-stat-card">
                <div class="feedback-stat-icon fsi-primary"><i class="bi bi-chat-square-text"></i></div>
                <div>
                    <div class="feedback-stat-value"><?= (int)$stats['total'] ?></div>
                    <div class="feedback-stat-label">Total Feedback</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="feedback-stat-card">
                <div class="feedback-stat-icon fsi-warning"><i class="bi bi-star-fill"></i></div>
                <div>
                    <div class="feedback-stat-value"><?= $stats['avg_mechanic'] !== null ? number_format((float)$stats['avg_mechanic'], 1) . ' / 5' : 'N/A' ?></div>
                    <div class="feedback-stat-label">Avg. Mechanic Rating</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="feedback-stat-card">
                <div class="feedback-stat-icon fsi-success"><i class="bi bi-hand-thumbs-up"></i></div>
                <div>
                    <div class="feedback-stat-value"><?= $stats['avg_service'] !== null ? number_format((float)$stats['avg_service'], 1) . ' / 5' : 'N/A' ?></div>
                    <div class="feedback-stat-label">Avg. Service Rating</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="feedback-stat-card">
                <div class="feedback-stat-icon fsi-info"><i class="bi bi-chat-left-quote"></i></div>
                <div>
                    <div class="feedback-stat-value"><?= (int)$stats['with_comments'] ?></div>
                    <div class="feedback-stat-label">With Comments</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card p-4 feedback-card">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h2 class="mb-0 text-primary" style="font-size:1.3rem;"><i class="bi bi-star-fill me-2"></i>Customer Feedback &amp; Ratings</h2>
            <div class="filter-pills btn-group">
                <a href="admin_feedback.php?type=all" class="btn btn-outline-secondary <?= $filter === 'all' ? 'active' : '' ?>">All</a>
                <a href="admin_feedback.php?type=booking" class="btn btn-outline-secondary <?= $filter === 'booking' ? 'active' : '' ?>">Bookings</a>
                <a href="admin_feedback.php?type=emergency" class="btn btn-outline-secondary <?= $filter === 'emergency' ? 'active' : '' ?>">Emergency</a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle feedback-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Type</th>
                        <th>Reference</th>
                        <th>Mechanic</th>
                        <th>Mechanic Rating</th>
                        <th>Service Rating</th>
                        <th>Comments</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($feedback_rows)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted empty-cell">
                                <i class="bi bi-inbox d-block mb-2" style="font-size:2rem;opacity:0.4;"></i>
                                No customer feedback yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($feedback_rows as $row): ?>
                            <?php
                                $customer = $row['customer_name'] ?: ($row['customer_username'] ?: 'N/A');
                                $ref_label = $row['feedback_type'] === 'emergency'
                                    ? 'Emergency #' . sprintf('%04d', (int)$row['emergency_request_id'])
                                    : 'Booking #' . (int)$row['booking_id'];
                                $type_label = $row['feedback_type'] === 'emergency' ? 'Emergency' : 'Booking';
                                $date_label = !empty($row['created_at']) ? date('M d, Y g:i A', strtotime($row['created_at'])) : 'N/A';
                                $mechanic_label = $row['mechanic_name'] ?: '—';
                                $comment = trim((string)$row['comments']);
                                $comment_words = $comment !== '' ? preg_split('/\s+/u', $comment) : [];
                                $is_long = count($comment_words) > 7;
                                $comment_preview = implode(' ', array_slice($comment_words, 0, 7)) . ($is_long ? ' …' : '');
                            ?>
                            <tr>
                                <td class="text-muted" style="white-space:nowrap;"><?= $date_label ?></td>
                                <td><?= htmlspecialchars($customer) ?></td>
                                <td>
                                    <span class="type-badge <?= $row['feedback_type'] === 'emergency' ? 'type-emergency' : 'type-booking' ?>">
                                        <?= $type_label ?>
                                    </span>
                                </td>
                                <td class="text-muted"><?= $ref_label ?></td>
                                <td><?= $row['mechanic_name'] ? htmlspecialchars($row['mechanic_name']) : '<span class="text-muted">—</span>' ?></td>
                                <td>
                                    <?php if ($row['mechanic_rating'] !== null): ?>
                                        <?= render_stars((int)$row['mechanic_rating']) ?>
                                        <span class="rating-num"><?= (int)$row['mechanic_rating'] ?>/5</span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= render_stars((int)$row['service_rating']) ?>
                                    <span class="rating-num"><?= (int)$row['service_rating'] ?>/5</span>
                                </td>
                                <td class="comments-cell">
                                    <?php if ($comment !== ''): ?>
                                        <div class="comment-line">
                                            <span class="comment-snippet"><i class="bi bi-chat-left-quote-fill"></i><?= htmlspecialchars($comment_preview) ?></span>
                                            <button type="button" class="btn-view-comment"
                                                data-bs-toggle="modal"
                                                data-bs-target="#feedbackCommentModal"
                                                data-customer="<?= htmlspecialchars($customer, ENT_QUOTES) ?>"
                                                data-type="<?= htmlspecialchars($type_label, ENT_QUOTES) ?>"
                                                data-ref="<?= htmlspecialchars($ref_label, ENT_QUOTES) ?>"
                                                data-mechanic="<?= htmlspecialchars($mechanic_label, ENT_QUOTES) ?>"
                                                data-date="<?= htmlspecialchars($date_label, ENT_QUOTES) ?>"
                                                data-mrating="<?= (int)($row['mechanic_rating'] ?? 0) ?>"
                                                data-srating="<?= (int)$row['service_rating'] ?>"
                                                data-comment="<?= htmlspecialchars($comment, ENT_QUOTES) ?>">
                                                View
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="comment-empty">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Comment detail modal -->
<div class="modal fade" id="feedbackCommentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content feedback-modal">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-chat-left-quote me-2"></i>Customer Feedback</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="fc-meta">
                    <div class="fc-meta-row"><span>Customer</span><strong id="fcCustomer"></strong></div>
                    <div class="fc-meta-row"><span>Type</span><strong id="fcType"></strong></div>
                    <div class="fc-meta-row"><span>Reference</span><strong id="fcRef"></strong></div>
                    <div class="fc-meta-row"><span>Mechanic</span><strong id="fcMechanic"></strong></div>
                    <div class="fc-meta-row"><span>Date</span><strong id="fcDate"></strong></div>
                </div>
                <div class="fc-ratings">
                    <div class="fc-rating-item" id="fcMechWrap"><span>Mechanic</span><span id="fcMechStars"></span></div>
                    <div class="fc-rating-item"><span>Service</span><span id="fcServStars"></span></div>
                </div>
                <div class="fc-comment-label">Comment</div>
                <div class="fc-comment-box" id="fcComment"></div>
            </div>
        </div>
    </div>
</div>

<script>
    function fcStarsHtml(n) {
        n = parseInt(n, 10) || 0;
        var h = '';
        for (var i = 1; i <= 5; i++) {
            h += '<i class="bi ' + (i <= n ? 'bi-star-fill' : 'bi-star') + '" style="color:#EAB308;font-size:0.9rem;"></i>';
        }
        return h + ' <span class="rating-num">' + n + '/5</span>';
    }

    document.querySelectorAll('.btn-view-comment').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = this.dataset;
            document.getElementById('fcCustomer').textContent = d.customer || 'N/A';
            document.getElementById('fcType').textContent = d.type || 'N/A';
            document.getElementById('fcRef').textContent = d.ref || 'N/A';
            document.getElementById('fcMechanic').textContent = d.mechanic || '—';
            document.getElementById('fcDate').textContent = d.date || 'N/A';
            document.getElementById('fcMechStars').innerHTML = fcStarsHtml(d.mrating);
            document.getElementById('fcServStars').innerHTML = fcStarsHtml(d.srating);
            document.getElementById('fcComment').textContent = d.comment || '';
        });
    });
</script>

<?php require 'admin_sidebar_footer.php'; ?>
