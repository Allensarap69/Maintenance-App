<?php
session_start();
require 'db.php'; // Include your database connection

// Security check: Only admins can access this page
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); // Or login.php
    exit;
}

$msg = "";
$msg_type = "";

// --- 1. PHP Logic for Setting Availability ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_availability'])) {
    $date_type = trim($_POST['date_type'] ?? 'single'); // 'single' or 'multiple'
    $single_date = trim($_POST['single_date'] ?? '');
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date = trim($_POST['end_date'] ?? '');
    
    // Shop hours
    $shop_start_time = trim($_POST['shop_start_time'] ?? '');
    $shop_end_time = trim($_POST['shop_end_time'] ?? '');
    
    // Lunch break times (Optional)
    $has_lunch_break = isset($_POST['has_lunch_break']);
    $lunch_start_time = $has_lunch_break ? trim($_POST['lunch_start_time'] ?? '') : null;
    $lunch_end_time = $has_lunch_break ? trim($_POST['lunch_end_time'] ?? '') : null;

    // Determine the date(s) to process
    $dates_to_process = [];
    $is_valid = true;
    
    // 1.1. Basic Validation (omitted for brevity, assume previous checks are here)
    // ... (Your previous validation logic remains here) ...

    // 1.2. Date Range Logic (omitted for brevity, assume previous checks are here)
    if ($is_valid) {
        if ($date_type === 'single') {
            if (empty($single_date)) {
                $msg = "❌ Please specify a single date.";
                $msg_type = "error";
                $is_valid = false;
            } else {
                $dates_to_process[] = $single_date;
            }
        } elseif ($date_type === 'multiple') {
             if (empty($start_date) || empty($end_date) || strtotime($start_date) > strtotime($end_date)) {
                $msg = "❌ Invalid date range selection.";
                $msg_type = "error";
                $is_valid = false;
             } else {
                $current_date_loop = $start_date;
                while (strtotime($current_date_loop) <= strtotime($end_date)) {
                    $dates_to_process[] = $current_date_loop;
                    $current_date_loop = date('Y-m-d', strtotime('+1 day', strtotime($current_date_loop)));
                }
             }
        }
    }

    // 1.3. Database Processing (omitted for brevity, assume previous logic remains here)
    if ($is_valid && !empty($dates_to_process)) {
        // ... (Your previous database INSERT/DELETE/UPDATE logic remains here) ...
         try {
            $pdo->beginTransaction();
            $stmt_delete = $pdo->prepare("DELETE FROM availability WHERE date = ?");
            $stmt_insert = $pdo->prepare("INSERT INTO availability (date, start_time, end_time, status) VALUES (?, ?, ?, 'available')");

            $dates_inserted = 0;
            foreach ($dates_to_process as $date) {
                $stmt_delete->execute([$date]); // Clear existing

                if (!$has_lunch_break || strtotime($shop_start_time) < strtotime($lunch_start_time)) {
                    $first_segment_end = $has_lunch_break ? $lunch_start_time : $shop_end_time;
                    $stmt_insert->execute([$date, $shop_start_time, $first_segment_end]);
                    $dates_inserted++;
                }

                if ($has_lunch_break && strtotime($lunch_end_time) < strtotime($shop_end_time)) {
                    $stmt_insert->execute([$date, $lunch_end_time, $shop_end_time]);
                    $dates_inserted++;
                }
            }
            $pdo->commit();
            
            $msg_text = "✅ Shop availability successfully set for **" . count($dates_to_process) . "** day(s). ";
            $msg_text .= "Total segments created: **" . $dates_inserted . "**. (Old slots overwritten.)";
            $msg = $msg_text;
            $msg_type = "success";

        } catch (PDOException $e) {
            $pdo->rollBack();
            $msg = "❌ Database Error: Could not set/update availability. (" . $e->getMessage() . ")";
            $msg_type = "error";
            error_log("Admin set availability range error: " . $e->getMessage());
        }
    }
}

// --- 2. Fetch and Group existing availability slots for display ---
$existing_availabilities = [];
try {
    // Fetch all availability data
    $stmt = $pdo->query("SELECT id, date, start_time, end_time FROM availability ORDER BY date DESC, start_time ASC"); 
    $all_availabilities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group the segments by date
    foreach ($all_availabilities as $avail) {
        $date_key = $avail['date'];
        if (!isset($existing_availabilities[$date_key])) {
            $existing_availabilities[$date_key] = [];
        }
        $existing_availabilities[$date_key][] = [
            'start_time' => $avail['start_time'],
            'end_time' => $avail['end_time']
        ];
    }
} catch (PDOException $e) {
    error_log("Error fetching existing availabilities for admin: " . $e->getMessage());
}

// We still limit the initial display for performance, but the filter should access all if needed.
$display_availabilities = array_slice($existing_availabilities, 0, 90, true);
$pageTitle = 'Availability Setup';
?>

<?php require 'admin_sidebar_template.php'; ?>

<style>
    body .btn-primary {
        background: #FACC15 !important;
        border-color: #FACC15 !important;
        color: #111827 !important;
        box-shadow: none !important;
    }
    body .btn-primary:hover {
        background: #EAB308 !important;
        border-color: #EAB308 !important;
        color: #111827 !important;
        box-shadow: none !important;
    }
    .top-header {
        position: fixed !important;
        top: 0;
        left: var(--sidebar-width);
        right: 0;
        z-index: 100;
    }
    .main-content {
        padding-top: 75px !important;
        background: white !important;
    }
    @media (max-width: 991px) {
        .top-header { left: 0 !important; }
    }
    :root {
        --primary-color: #004d80; /* Dark Blue */
        --secondary-color: #6c757d; /* Gray */
        --accent-color: #ff8c00; /* Orange/Gold */
        --bg-light: #f4f7f9;
    }
    body { 
        background-color: var(--bg-light); 
        font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; 
    }
    .app-header { 
        background-color: var(--primary-color); 
        color: white; 
        padding: 20px 0; 
        margin-bottom: 30px; 
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2); 
    }
    .card-setup { 
        border-radius: 12px; 
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1); 
        border: none;
        height: 100%; 
    }
    .fixed-height-card-body {
        max-height: 60vh; 
        overflow-y: auto;
        padding: 0 !important; 
    }
    .date-header {
        font-size: 1em;
        font-weight: 700;
        padding: 10px 15px;
        background-color: #e9ecef;
        color: var(--primary-color);
        border-bottom: 1px solid #dee2e6;
        margin-top: 5px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .time-segment {
        display: flex;
        align-items: center;
        padding: 8px 15px;
        border-bottom: 1px solid #f8f9fa;
        background-color: white;
        transition: background-color 0.2s;
    }
    .time-segment:hover {
        background-color: #eef7ff;
    }
    /* NEW MONTH BUTTON STYLES */
    .month-filter-container {
        padding: 10px 0;
        background-color: #fff;
        border-bottom: 1px solid #dee2e6;
        margin-bottom: 5px;
        overflow-x: auto; /* Allows horizontal scroll if needed */
        white-space: nowrap; /* Prevents wrapping */
        border-radius: 12px 12px 0 0;
    }
    .month-btn {
        background-color: #e9ecef;
        color: var(--secondary-color);
        border: 1px solid #ced4da;
        padding: 5px 10px;
        margin: 0 2px;
        font-size: 0.85rem;
        border-radius: 8px;
        transition: all 0.2s;
        cursor: pointer;
        display: inline-block;
    }
    .month-btn:hover {
        background-color: #dee2e6;
    }
    .month-btn.active {
        background-color: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
        font-weight: bold;
    }
    .schedule-list li {
        list-style: none;
        padding: 0;
    }
    .schedule-list {
        padding-left: 0;
    }

    /* Smaller but readable text in both availability cards */
    .card-setup .card-header h4 {
        font-size: 1rem;
    }
    .card-setup .card-body {
        font-size: 0.85rem;
    }
    .card-setup h5,
    .card-setup .form-label,
    .card-setup .form-check-label,
    .card-setup .time-label {
        font-size: 0.85rem;
    }
    .main-content .card-setup h5 {
        color: #1e3a5f !important;
    }
    .card-setup input[name="date_type"]:checked + .form-check-label {
        color: #1e3a5f !important;
    }
    .card-setup input[name="date_type"] {
        margin-right: 0.5rem;
    }
    .card-setup .form-control,
    .card-setup .form-select,
    .card-setup .form-check-input {
        font-size: 0.85rem;
        padding: 8px 12px;
    }
    .card-setup .btn {
        font-size: 0.85rem;
    }
    .card-setup .date-header {
        font-size: 0.85rem;
    }
    .card-setup .time-range-text {
        font-size: 0.8rem;
    }
    .card-setup .badge {
        font-size: 0.75rem;
    }
    .av-card-header {
        background: var(--bg-light);
        color: var(--text-dark);
        padding: 15px 20px;
        border: none;
        position: relative;
    }
    .av-card-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #3b82f6, #FACC15, #3b82f6);
    }

    /* Dark mode overrides */
    html.dark-mode .date-header {
        background-color: #1a2540 !important;
        color: #93c5fd !important;
        border-bottom-color: rgba(255, 255, 255, 0.09) !important;
    }
    html.dark-mode .time-segment {
        background: #151f36 !important;
        border-bottom-color: rgba(255, 255, 255, 0.09) !important;
        color: #e2e8f0 !important;
    }
    html.dark-mode .time-segment:hover { background: #1a2540 !important; }
    html.dark-mode .month-filter-container {
        background: #151f36 !important;
        border-bottom-color: rgba(255, 255, 255, 0.09) !important;
    }
    html.dark-mode .month-btn {
        background: #1a2540 !important;
        color: #94a3b8 !important;
        border-color: #33415f !important;
    }
    html.dark-mode .month-btn:hover { background: #22304f !important; }
    html.dark-mode .month-btn.active { background: #22304f !important; color: #FDE047 !important; border-color: #33415f !important; }
    html.dark-mode .main-content .card-setup h5,
    html.dark-mode .card-setup input[name="date_type"]:checked + .form-check-label { color: #e2e8f0 !important; }
    html.dark-mode .av-card-header { color: #e2e8f0 !important; }
</style>

<div class="container-fluid">
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card card-setup">
                <div class="card-header av-card-header">
                    <h4 class="mb-0"><i class="bi bi-clock-history me-2"></i> Set New Schedule</h4>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="admin_availability.php">
                        <input type="hidden" name="set_availability" value="1">
                        
                        <h5 class="mb-3"><i class="bi bi-calendar-event me-2"></i> 1. Select Date Scope</h5>
                        <div class="mb-4">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="date_type" id="radio_single" value="single" checked onchange="toggleDateInputs('single')">
                                <label class="form-check-label fw-bold" for="radio_single">Single Day</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="date_type" id="radio_multiple" value="multiple" onchange="toggleDateInputs('multiple')">
                                <label class="form-check-label fw-bold" for="radio_multiple">Date Range</label>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-12 date-input-group" id="single_day_inputs">
                                <label for="single_date" class="form-label time-label">Date</label>
                                <input type="date" class="form-control" id="single_date" name="single_date" value="<?= date('Y-m-d') ?>">
                            </div>
                            
                            <div class="col-md-6 date-input-group" id="multiple_day_inputs">
                                <label for="start_date" class="form-label time-label">Start Date</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6 date-input-group" id="multiple_day_inputs_end">
                                <label for="end_date" class="form-label time-label">End Date</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
                            </div>
                        </div>
                        
                        <h5 class="mb-3"><i class="bi bi-shop me-2"></i> 2. Shop Operational Hours</h5>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="shop_start_time" class="form-label time-label">Shop Open Time</label>
                                <input type="time" class="form-control" id="shop_start_time" name="shop_start_time" required value="09:00">
                            </div>
                            <div class="col-md-6">
                                <label for="shop_end_time" class="form-label time-label">Shop Close Time</label>
                                <input type="time" class="form-control" id="shop_end_time" name="shop_end_time" required value="17:00">
                            </div>
                        </div>

                        <h5 class="mb-3"><i class="bi bi-briefcase-fill me-2"></i> 3. Lunch Break Settings (Optional)</h5>
                        <div class="lunch-break-box mb-4">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="has_lunch_break" name="has_lunch_break" onchange="toggleLunchInputs(this.checked)">
                                <label class="form-check-label fw-bold text-accent" for="has_lunch_break">
                                    <i class="bi bi-cup-hot-fill me-1"></i> Apply a Lunch Break
                                </label>
                            </div>
                            
                            <div class="row g-3 lunch-break-inputs" id="lunch_break_inputs">
                                <div class="col-md-6">
                                    <label for="lunch_start_time" class="form-label time-label">Break Starts</label>
                                    <input type="time" class="form-control" id="lunch_start_time" name="lunch_start_time" value="12:00">
                                </div>
                                <div class="col-md-6">
                                    <label for="lunch_end_time" class="form-label time-label">Break Ends</label>
                                    <input type="time" class="form-control" id="lunch_end_time" name="lunch_end_time" value="13:00">
                                </div>
                                <small class="text-muted mt-2">Bookings will not be possible between these times.</small>
                            </div>
                        </div>

                        <div class="mt-4 text-center border-top pt-3">
                            <button type="submit" class="btn btn-primary btn-lg w-100"><i class="bi bi-check2-circle me-2"></i> Apply Schedule</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card card-setup">
                <div class="card-header av-card-header">
                    <h4 class="mb-0"><i class="bi bi-list-task me-2"></i> Scheduled Availability</h4>
                </div>

                <div class="month-filter-container d-flex justify-content-start flex-nowrap mx-auto">
                    <button type="button" class="month-btn active" data-month="all" onclick="filterSchedule(this, 'all')">All</button>
                    <?php 
                    $months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                    foreach ($months as $index => $monthName): 
                        $monthNumber = str_pad($index + 1, 2, '0', STR_PAD_LEFT);
                    ?>
                        <button type="button" class="month-btn" data-month="<?= $monthNumber ?>" onclick="filterSchedule(this, '<?= $monthNumber ?>')"><?= $monthName ?></button>
                    <?php endforeach; ?>
                </div>
                
                <div class="card-body fixed-height-card-body">
                    <?php if (!empty($existing_availabilities)): ?>
                        <ul class="schedule-list" id="schedule-list">
                            <?php foreach ($existing_availabilities as $date => $segments): 
                                $monthNumber = date('m', strtotime($date));
                            ?>
                                <li data-month="<?= $monthNumber ?>">
                                    <div class="date-header">
                                        <span>
                                            <i class="bi bi-calendar-check me-2"></i> 
                                            <?= date('l, F j, Y', strtotime($date)) ?>
                                        </span>
                                        <span class="badge bg-primary rounded-pill"><?= count($segments) ?> Segment(s)</span>
                                    </div>
                                    <?php foreach ($segments as $segment): ?>
                                        <div class="time-segment">
                                            <i class="bi bi-clock-fill text-success"></i>
                                            <span class="time-range-text">
                                                <?= date('h:i A', strtotime($segment['start_time'])) ?> &mdash; 
                                                <?= date('h:i A', strtotime($segment['end_time'])) ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="alert alert-info m-3">
                            No shop availability segments have been set yet.
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer text-end p-2">
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleDateInputs(type) {
        // ... (Your previous JS logic for toggling date inputs remains here) ...
        const single = document.getElementById('single_day_inputs');
        const multiple_start = document.getElementById('multiple_day_inputs');
        const multiple_end = document.getElementById('multiple_day_inputs_end');
        
        const singleDateInput = document.getElementById('single_date');
        const startDateInput = document.getElementById('start_date');
        const endDateInput = document.getElementById('end_date');

        if (type === 'single') {
            single.style.display = 'block'; 
            multiple_start.style.display = 'none';
            multiple_end.style.display = 'none';

            singleDateInput.setAttribute('required', 'required');
            startDateInput.removeAttribute('required');
            endDateInput.removeAttribute('required');

        } else if (type === 'multiple') {
            single.style.display = 'none';
            multiple_start.style.display = 'block';
            multiple_end.style.display = 'block';

            singleDateInput.removeAttribute('required');
            startDateInput.setAttribute('required', 'required');
            endDateInput.setAttribute('required', 'required');
        }
    }
    
    function toggleLunchInputs(isChecked) {
        // ... (Your previous JS logic for toggling lunch inputs remains here) ...
        const lunchInputs = document.getElementById('lunch_break_inputs');
        const startInput = document.getElementById('lunch_start_time');
        const endInput = document.getElementById('lunch_end_time');

        if (isChecked) {
            lunchInputs.style.display = 'flex';
            startInput.setAttribute('required', 'required');
            endInput.setAttribute('required', 'required');
        } else {
            lunchInputs.style.display = 'none';
            startInput.removeAttribute('required');
            endInput.removeAttribute('required');
        }
    }

    function filterSchedule(clickedButton, month) {
        const scheduleList = document.getElementById('schedule-list');
        if (!scheduleList) return;

        // 1. Update Button Visuals
        document.querySelectorAll('.month-btn').forEach(btn => btn.classList.remove('active'));
        clickedButton.classList.add('active');

        // 2. Filter List Items
        const items = scheduleList.querySelectorAll('li');
        
        items.forEach(item => {
            const itemMonth = item.getAttribute('data-month');
            
            if (month === 'all' || itemMonth === month) {
                item.style.display = 'list-item';
            } else {
                item.style.display = 'none';
            }
        });
    }


    // Initialize on load
    document.addEventListener('DOMContentLoaded', () => {
         // 1. Initialize Date Inputs (Single Day is default)
        toggleDateInputs('single'); 
        
        // 2. Initialize Lunch Inputs (Hidden by default)
        toggleLunchInputs(document.getElementById('has_lunch_break').checked);
    });
</script>
<?php require 'admin_sidebar_footer.php'; ?>