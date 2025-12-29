<?php
// logs.php
require 'config.php';

// Check session status before starting
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Security check for admin
if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header("Location: index.php"); 
    exit;
}

// Filters
$start = $_GET['start'] ?? date("Y-m-01");
$end = $_GET['end'] ?? date("Y-m-t");
$user_id = $_GET['user_id'] ?? 0;

// --- PAGINATION SETUP ---
$limit = 15; // Records per page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;
// ------------------------

// --- Fetch selected user name for display ---
$selected_user_name = 'All Users'; 
if ($user_id) { 
    try {
        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id=?"); 
        $stmt->execute([(int)$user_id]); 
        $name = $stmt->fetchColumn(); 
        if ($name) {
            $selected_user_name = $name;
        }
    } catch (PDOException $e) {
        error_log("Database error fetching single user: " . $e->getMessage());
    }
} 

// --- Prepare filtered Month/Year string for display (Cleaned up for clarity) --- 
$filtered_month_year_display = ''; 

if ($start || $end) { 
    $start_ts = strtotime($start); 
    $end_ts = strtotime($end); 
    
    // Day-Month-Year Display Logic
    if ($start && $end && date('Y-m-d', $start_ts) === date('Y-m-d', $end_ts)) {
        $filtered_month_year_display = date('j F Y', $start_ts);
    } elseif ($start && $end && date('Y-m', $start_ts) === date('Y-m', $end_ts)) { 
        $filtered_month_year_display = date('j', $start_ts) . ' - ' . date('j F Y', $end_ts); 
    } elseif ($start && $end && date('Y', $start_ts) === date('Y', $end_ts)) { 
        $filtered_month_year_display = date('j F', $start_ts) . ' - ' . date('j F Y', $end_ts); 
    } elseif ($start && $end) { 
        $filtered_month_year_display = date('j F Y', $start_ts) . ' - ' . date('j F Y', $end_ts); 
    } elseif ($start) { 
        $filtered_month_year_display = "FROM " . date('j F Y', $start_ts); 
    } elseif ($end) { 
        $filtered_month_year_display = "UP TO " . date('j F Y', $end_ts); 
    }
} 
// --- END: Prepare filtered Month/Year string for display ---


// Users list (only non-admin users)
try {
    $users = $pdo->query("SELECT id, full_name FROM users WHERE is_admin = 0 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching users: " . $e->getMessage());
    $users = [];
}


// --- FETCH TOTAL RECORDS FOR PAGINATION ---
// Count distinct user/day combinations (each row in the summary table)
$count_params = [$start, $end];
$count_sql = "SELECT COUNT(DISTINCT DATE(a.check_in), a.user_id)
        FROM attendance a
        JOIN users u ON a.user_id = u.id
        WHERE DATE(a.check_in) BETWEEN ? AND ?";
if ($user_id) { $count_sql .= " AND a.user_id=?"; $count_params[] = $user_id; }

try {
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($count_params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);
} catch (PDOException $e) {
    error_log("Database error fetching total records: " . $e->getMessage());
    $total_records = 0;
    $total_pages = 0;
}
// ------------------------------------------

// --- FETCHING DATA FOR CURRENT PAGE (MODIFIED) ---
// 1. Get the DISTINCT user_id and day from attendance, limited by pagination.
$pag_params = [$start, $end];
$pag_sql = "SELECT DISTINCT DATE(a.check_in) AS day, a.user_id
        FROM attendance a
        WHERE DATE(a.check_in) BETWEEN ? AND ?";
if ($user_id) { $pag_sql .= " AND a.user_id=?"; $pag_params[] = $user_id; }
// CHANGE: Order by day DESC to get latest first
$pag_sql .= " ORDER BY day DESC, a.user_id ASC LIMIT ? OFFSET ?"; 
//                               ^^^^
// Note: We keep a.user_id ASC as a secondary sort to keep a user's logs grouped if they have multiple entries on the same date range.

try {
    $pag_stmt = $pdo->prepare($pag_sql);
    
    // Bind base parameters (date range and user_id if present)
    $pag_stmt->bindValue(1, $pag_params[0], PDO::PARAM_STR);
    $pag_stmt->bindValue(2, $pag_params[1], PDO::PARAM_STR);
    $param_index = 3;
    $param_index = 3;
if (!empty($user_id)) {
    $pag_stmt->bindValue($param_index++, $user_id, PDO::PARAM_INT);
}
    
    // Bind LIMIT and OFFSET
    $pag_stmt->bindValue($param_index++, $limit, PDO::PARAM_INT);
    $pag_stmt->bindValue($param_index, $offset, PDO::PARAM_INT);
    
    $pag_stmt->execute();
    $paginated_days = $pag_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching paginated days: " . $e->getMessage());
    $paginated_days = [];
}

$rows = [];
if (!empty($paginated_days)) {
    // 2. Fetch all logs corresponding to the user/day combinations on the current page.
    $fetch_params = [$start, $end];
    $fetch_sql = "SELECT a.*, u.full_name, u.department, s.start_time AS shift_start, s.end_time AS shift_end
            FROM attendance a
            JOIN users u ON a.user_id = u.id
            LEFT JOIN shifts s ON u.shift_id = s.id
            WHERE DATE(a.check_in) BETWEEN ? AND ?";
    if ($user_id) { $fetch_sql .= " AND a.user_id=?"; $fetch_params[] = $user_id; }
    $fetch_sql .= " ORDER BY a.user_id, a.check_in ASC";

    try {
        $stmt = $pdo->prepare($fetch_sql); 
        $stmt->execute($fetch_params); 
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error fetching attendance: " . $e->getMessage());
        $rows = [];
    }
}

// Organize data (pre-filter to only include logs for paginated days)
$data = [];
$paginated_keys = [];
foreach ($paginated_days as $item) {
    $paginated_keys[$item['user_id'] . '_' . $item['day']] = true;
}

foreach ($rows as $r) {
    $uid = $r['user_id'];
    $check_in_time = strtotime($r['check_in']);
    $day = date("Y-m-d", $check_in_time);
    $key = $uid . '_' . $day;
    
    // Only process if this user/day combo is on the current page
    if (!isset($paginated_keys[$key])) continue; 

    $check_out_time = $r['check_out'] ? strtotime($r['check_out']) : null;
    // Handle overnight shift by adding a day to check-out if it's before check-in
    if ($check_out_time && $check_out_time < $check_in_time) $check_out_time = strtotime('+1 day', $check_out_time);
    
    // Determine if the day is Friday for the rule
    $day_of_week = date('D', $check_in_time);
    $is_friday = ($day_of_week === 'Fri'); 

    if (!isset($data[$uid][$day])) {
        $data[$uid][$day] = [
            'user'=>$r['full_name'],
            'dept'=>$r['department'],
            'logs'=>[],
            'is_friday' => $is_friday 
        ];
    }
    $data[$uid][$day]['logs'][] = $r;
}

// Time formatting helper function
function fmt($sec){ 
    if ($sec <= 0) return "00:00"; 
    $h = floor($sec/3600); 
    $m = floor(($sec%3600)/60); 
    return sprintf("%02d:%02d",$h,$m); 
}

// Default shift calculation (10 hours for worked, 10 hours 39 min threshold for OT)
$shift_seconds = 10*3600; // 10 hours
$overtime_threshold = $shift_seconds + (39*60); // 10h 39m

// Summary Calculation
$summary = [];
foreach ($data as $uid => $days) {
    ksort($days);
    foreach ($days as $day => $val) {
        $worked_sec_total = 0;
        $overtime_sec = 0;
        $standard_sec = 0; // Standard (non-overtime) time

        foreach ($val['logs'] as $log) {
            // Only calculate if check-out exists
            if (!$log['check_out']) continue;
            
            // Handle check-out before check-in (overnight)
            $co_time = strtotime($log['check_out']);
            $ci_time = strtotime($log['check_in']);
            if ($co_time < $ci_time) $co_time = strtotime('+1 day', $co_time);

            $worked_sec_total += $co_time - $ci_time;
        }

        // --- APPLY FRIDAY/WEEKDAY LOGIC ---
        if ($val['is_friday']) {
            // RULE: If Friday, all time is Overtime. Standard time is 0.
            $overtime_sec = $worked_sec_total;
            $standard_sec = 0;

        } else {
            // RULE: Weekday/Weekend (non-Friday) calculation (10h shift + 39m buffer)
            if ($worked_sec_total > $overtime_threshold) {
                // If over threshold, standard time is capped at shift hours, excess is OT
                $overtime_sec = $worked_sec_total - $shift_seconds;
                $standard_sec = $shift_seconds;
            } else {
                // If less than or equal to threshold, all time is standard work
                $overtime_sec = 0;
                $standard_sec = $worked_sec_total;
            }
        }
        // --- END FRIDAY/WEEKDAY LOGIC ---

        $summary[$uid][$day] = [
            'user' => $val['user'],
            'dept' => $val['dept'],
            'worked' => $standard_sec, // Standard Time (Max 10h)
            'overtime' => $overtime_sec,
            'total_worked_time' => $worked_sec_total, // New: Sum of standard and overtime
            'logs' => $val['logs'],
            'is_friday' => $val['is_friday'] 
        ];
    }
}

// Final list of logs for the current page, ensuring correct order
$final_summary_list = [];
foreach ($paginated_days as $item) {
    $uid = $item['user_id'];
    $day = $item['day'];
    if (isset($summary[$uid][$day])) {
        $final_summary_list[] = $summary[$uid][$day];
    }
}
// --- END FETCHING DATA FOR CURRENT PAGE ---

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Attendance Logs - Visionangles</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="visionnew.png">
<style>
/* ================================================= */
/* ===== TEAL/NAVY MINIMALIST THEME (Matching admin_dashboard.php) ===== */
/* ================================================= */
:root {
    /* --- LIGHT MODE (Teal Accent) --- */
    --text-color: #2c3e50; /* Navy/Dark Grey */
    --bg-primary: #f7f9fb; /* Light background */
    --card-bg: #ffffff;
    --muted-text-color: #95a5a6;
    --main-color: #1abc9c; /* Primary: Deep Teal */
    --main-hover: #148f77; 
    --action-button-color: #3498db; /* Secondary: Brighter Blue for primary actions (Add Log) */
    --action-button-hover: #2980b9;
    --accent: #f39c12; /* Sun Yellow/Orange for Warnings/Highlights (Filter) */
    --accent-hover: #e67e22;
    --success: #2ecc71;
    --error: #e74c3c; /* Red for Edited/Error */
    --table-header: var(--main-color);
    --table-header-text: #ffffff;
    --border-color: rgba(0,0,0,0.1);
    --shadow: 0 10px 30px rgba(0,0,0,0.08);
    --hover-bg: rgba(26, 188, 156, 0.05); /* Light teal hover */
    --friday-highlight-light: #fffacd; /* Lemon Chiffon for light mode */
    --friday-highlight-text-light: #5a5a00; /* Darker yellow/brown text */
    transition: all 0.5s ease;
}

html.dark-mode {
    /* --- DARK MODE (Deep Teal Accent) --- */
    --text-color: #ecf0f1; /* Light Text */
    --bg-primary: #1c2833; /* Deep Navy Background */
    --card-bg: #2c3e50; /* Darker Navy Card */
    --muted-text-color: #bdc3c7;
    --main-color: #1abc9c; /* Primary: Deep Teal */
    --main-hover: #16a085;
    --action-button-color: #3498db; 
    --action-button-hover: #2980b9;
    --accent: #f1c40f; /* Yellow/Gold for Warnings/Highlights */
    --accent-hover: #f39c12;
    --success: #2ecc71;
    --error: #ff6b6b; /* Lighter Red for dark mode visibility */
    --table-header: var(--main-color);
    --table-header-text: #ffffff;
    --border-color: rgba(255, 255, 255, 0.1);
    --shadow: 0 10px 30px rgba(0,0,0,0.4);
    --hover-bg: rgba(26, 188, 156, 0.1); /* Light teal hover */
    --friday-highlight-dark: #3a3000; /* Darker yellow for dark mode */
    --friday-highlight-text-dark: #ffeeba; /* Lighter yellow text */
}

/* --- BASE STYLES & ANIMATION --- */
body { 
    font-family:'Poppins',sans-serif; 
    background-color:var(--bg-primary); 
    color:var(--text-color); 
    transition: background-color 0.5s ease, color 0.5s ease;
}

/* Animation Keyframes */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.container { animation: fadeIn 0.6s ease-out; }

/* COMPANY HEADER STYLING */
.company-header {
    color: var(--main-color); /* Use Teal */
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 2rem;
    text-align: center;
    text-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    width: 100%;
}
.company-header a {
    color: inherit; 
    text-decoration: none;
    transition: color 0.3s;
}
.company-header a:hover {
    color: var(--main-hover);
}

.card { 
    background-color:var(--card-bg); 
    border-radius:15px; 
    border:1px solid var(--border-color); 
    box-shadow:var(--shadow);
    transition: all 0.5s ease;
}

h3.heading { 
    font-size:30px; 
    font-weight:700; 
    color:var(--main-color); 
    display:flex; 
    align-items:center; 
    gap:10px; 
    margin-bottom: 1.5rem;
    transition: color 0.5s;
    text-transform: uppercase;
}

/* --- FORM & BUTTONS --- */
.form-control, .form-select { 
    border-radius: 8px; 
    border: 1px solid var(--border-color);
    background-color: var(--card-bg);
    color: var(--text-color);
    transition: border-color 0.3s, box-shadow 0.3s;
}
.form-control:focus, .form-select:focus {
    border-color: var(--main-color);
    box-shadow: 0 0 0 0.25rem var(--main-color)40; 
    background-color: var(--card-bg);
}

.btn { border-radius: 8px; font-weight: 600; transition: transform 0.3s, background-color 0.3s, box-shadow 0.3s; }

/* Filter Button (Accent color: Yellow/Orange) */
.btn-warning { 
    background-color: var(--accent); 
    border-color: var(--accent);
    color: var(--text-color); /* Dark text on light accent background */
}
html.dark-mode .btn-warning {
    color: var(--text-color) !important;
}
.btn-warning:hover { 
    background-color: var(--accent-hover); 
    border-color: var(--accent-hover);
    transform: translateY(-1px);
    box-shadow: 0 4px 10px var(--accent)40;
}

/* Add Log, Edit, Table Header (Main/Action Color) */
/* btn-success for Add Log is changed to action-button-color (Blue) */
.btn-success, .btn-primary { 
    background-color: var(--action-button-color); 
    border-color: var(--action-button-color); 
    color: #ffffff !important; 
}
.btn-success:hover, .btn-primary:hover { 
    background-color: var(--action-button-hover);
    border-color: var(--action-button-hover); 
    transform: translateY(-1px);
    box-shadow: 0 4px 10px var(--action-button-color)40;
}

/* Clear (Secondary/Neutral color) */
.btn-secondary { 
    background-color: var(--muted-text-color); 
    border-color: var(--muted-text-color); 
    color: #fff !important; 
} 
.btn-secondary:hover { 
    background-color: #515c6b; 
    border-color: #515c6b; 
    transform: translateY(-1px); 
} 


/* CUSTOM: View/Info Button (Teal/Minimalist) */
.btn-info { 
    background-color: var(--card-bg); 
    border: 1px solid var(--main-color)50; 
    color: var(--main-color); 
    font-weight: 500;
}
.btn-info:hover { 
    background-color: var(--main-color); 
    color: #ffffff;
    border-color: var(--main-color);
    transform: scale(1.05);
}
html.dark-mode .btn-info {
    background-color: var(--card-bg);
    border: 1px solid var(--main-color)80; 
    color: var(--main-color); 
}
html.dark-mode .btn-info:hover { 
    background-color: var(--main-color); 
    color: var(--card-bg);
    border-color: var(--main-color);
}


/* Secondary Button (Used for Back to Dashboard/Cancel) */
.btn-outline-secondary {
    color: var(--muted-text-color);
    border-color: var(--border-color);
}
.btn-outline-secondary:hover {
    color: var(--main-color);
    border-color: var(--main-color);
    background-color: var(--hover-bg);
}

/* Report Info Style */
.report-info { 
    font-weight: 700; 
    text-transform: uppercase; 
    font-size: 0.9rem; 
    margin-bottom: 20px; 
    color: var(--muted-text-color); 
    padding-left: 10px; 
    border-left: 4px solid var(--main-color); 
    transition: all 0.5s ease; 
} 
.report-info span { color: var(--main-color); transition: color 0.5s; } 

/* --- TABLE STYLES --- */
.table { border-radius: 12px; overflow: hidden; border: 1px solid var(--border-color); }
.table thead th { 
    background-color:var(--table-header); 
    color:var(--table-header-text); 
    font-weight:600;
    text-transform: uppercase;
    font-size: 0.9rem;
    padding: 1rem 0.75rem;
    border-color: var(--border-color);
}
.table tbody tr:not(.collapse-row) { transition: background-color 0.3s ease; }
.table-hover tbody tr:hover { 
    background-color: var(--hover-bg); /* Subtle theme hover */
}

/* NEW: Friday highlight for summary rows */
.friday-row-highlight {
    background-color: var(--friday-highlight-light) !important;
    color: var(--friday-highlight-text-light);
    border-left: 5px solid var(--accent); /* Use main accent color for a strong left border */
}
html.dark-mode .friday-row-highlight {
    background-color: var(--friday-highlight-dark) !important;
    color: var(--friday-highlight-text-dark);
    border-left: 5px solid var(--accent);
}
.friday-row-highlight:hover {
    background-color: var(--friday-highlight-light) !important; /* Keep the shade on hover */
    opacity: 0.9;
}
html.dark-mode .friday-row-highlight:hover {
    background-color: var(--friday-highlight-dark) !important;
    opacity: 0.9;
}


.collapse-row { 
    background-color: var(--hover-bg); 
    border-color: var(--border-color) !important; 
    animation: fadeIn 0.4s ease-out;
}

.table-sm td { font-size: 0.85rem; }

/* Highlight row for edited log (Red/White) */
.edited-row { 
    background-color: var(--error)20 !important; /* Lighter shade of Red for row */
    border-left: 5px solid var(--error) !important;
}
.sub-log-row[style*="background-color"] { 
    /* This targets the sub-rows inside the collapse */
    background-color: var(--error)10 !important; 
}


/* --- BADGES --- */
.badge { font-weight: 600; padding: 0.4em 0.8em; border-radius: 50rem; transition: background-color 0.3s; }
.badge-worked { background-color: var(--success); color: #ffffff; }
/* Changed badge-overtime to use main-color for better contrast in dark mode */
.badge-overtime { background-color: var(--main-color); color: #ffffff; } 
.badge-pending { background-color: var(--error); color: #ffffff; }

/* Badge for Edited (Red/White) */
.badge-edited { 
    background-color: var(--error); 
    color: #ffffff !important; 
    border: 1px solid var(--error);
}

/* --- MODAL STYLES --- */
.modal-content {
    background-color: var(--card-bg);
    color: var(--text-color);
    border: 1px solid var(--border-color);
    border-radius: 15px;
    box-shadow: var(--shadow);
}
.modal-header { border-bottom-color: var(--border-color); }
.modal-footer { border-top-color: var(--border-color); }

/* --- THEME SWITCH --- */
.theme-switch-wrapper { 
    position: fixed; 
    top: 1.5rem; 
    right: 1.5rem; 
    display: flex; 
    align-items: center; 
    z-index: 100; 
}
.theme-switch { height: 30px; position: relative; width: 56px; }
.theme-switch input { display:none; }
.slider { background-color: var(--muted-text-color); bottom: 0; cursor: pointer; left: 0; position: absolute; right: 0; top: 0; transition: .4s; }
.slider:before { background-color: #fff; bottom: 3px; content: ""; height: 24px; left: 3px; position: absolute; transition: .4s; width: 24px; }
input:checked + .slider { background-color: var(--main-color); }
input:checked + .slider:before { transform: translateX(26px); }
.slider.round { border-radius: 34px; }
.slider.round:before { border-radius: 50%; }

</style>
</head>
<body>

<div class="theme-switch-wrapper">
    <label class="theme-switch" for="theme-toggle" title="Toggle Dark/Light Mode">
        <input type="checkbox" id="theme-toggle">
        <div class="slider round"></div>
    </label>
</div>

<div class="company-header">
    <a href="admin_dashboard.php">
        <i class="fas fa-shield-alt me-2"></i>Vision Angles
    </a>
</div>

<div class="container my-5">
<div class="card p-4">
<h3 class="heading"><i class="fas fa-history"></i> Attendance Logs</h3>

<form method="get" class="row g-3 mb-3"> 
<div class="col-md-3 col-sm-6">
    <label class="form-label visually-hidden">Start Date</label>
    <input type="date" name="start" value="<?=htmlspecialchars($start)?>" class="form-control form-control-sm" title="Start Date">
</div>
<div class="col-md-3 col-sm-6">
    <label class="form-label visually-hidden">End Date</label>
    <input type="date" name="end" value="<?=htmlspecialchars($end)?>" class="form-control form-control-sm" title="End Date">
</div>
<div class="col-md-3 col-12">
    <label class="form-label visually-hidden">Select User</label>
    <select name="user_id" class="form-select form-select-sm" title="Filter by User">
        <option value="0" <?=!$user_id?'selected':''?>>All Users</option>
        <?php foreach($users as $u): ?>
        <option value="<?=$u['id']?>" <?=$user_id==$u['id']?'selected':''?>><?=htmlspecialchars($u['full_name'])?></option>
        <?php endforeach; ?>
    </select>
</div>
<div class="col-md-3 col-12 d-flex gap-1">
<button class="btn btn-sm btn-warning" type="submit"><i class="fas fa-filter"></i> Filter</button>
<a href="logs.php" class="btn btn-sm btn-secondary"><i class="fas fa-eraser"></i> Clear</a>
<button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addMissingModal"><i class="fas fa-plus-circle"></i> Add Log</button>
</div>
<input type="hidden" name="page" value="<?=$page?>"> 
</form>

<?php if($selected_user_name != 'All Users' || $filtered_month_year_display): ?> 
<div class="report-info"> 
    <?php if($selected_user_name != 'All Users'): ?>EMPLOYEE: <span><?= htmlspecialchars($selected_user_name) ?></span><?php endif; ?> 
    <?php if($selected_user_name != 'All Users' && $filtered_month_year_display): ?> | <?php endif; ?> 
    <?php if($filtered_month_year_display): ?>PERIOD: <span><?= $filtered_month_year_display ?></span><?php endif; ?> 
</div> 
<?php endif; ?> 
<div class="table-responsive">
<table class="table table-bordered table-hover align-middle">
<thead>
<tr><th>User</th><th>Dept</th><th>Date</th><th>Total Work Time</th><th>Overtime</th><th>Details</th></tr>
</thead>
<tbody>
<?php if(empty($final_summary_list)): ?>
<tr><td colspan="6" class="text-center text-muted py-4">No attendance records found for the selected period/user on this page.</td></tr>
<?php else: ?>
<?php foreach($final_summary_list as $val): // Iterate over the final, paginated list
$anyEdited = false;
foreach($val['logs'] as $log) {
    if(!empty($log['edited'])) { $anyEdited = true; break; }
}
// Generate unique ID for collapse based on user and date
$day_ts = strtotime(date('Y-m-d', strtotime($val['logs'][0]['check_in'])));
$log_uid = $val['logs'][0]['user_id'];
// Determine if it's a Friday row for styling
$friday_highlight_class = $val['is_friday'] ? 'friday-row-highlight' : '';
?>
<tr class="log-summary-row <?= $anyEdited ? 'edited-row' : '' ?> <?= $friday_highlight_class ?>">
<td><?=htmlspecialchars($val['user'])?></td>
<td><?=htmlspecialchars($val['dept'])?></td>
<td><?=date('d-m-Y D', $day_ts)?></td>
<td><span class="badge badge-worked"><?=fmt($val['total_worked_time'])?></span></td>
<td><span class="badge <?= $val['overtime']>0?'badge-overtime':'bg-secondary' ?>"><?=fmt($val['overtime'])?></span></td>
<td><button class="btn btn-sm btn-info" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?=$log_uid?>-<?=$day_ts?>"><i class="fas fa-eye"></i> View</button></td>
</tr>

<tr class="collapse collapse-row" id="collapse-<?=$log_uid?>-<?=$day_ts?>">
<td colspan="6" class="p-0">
<div class="p-3">
<table class="table table-sm table-borderless mb-0">
<thead class="table-light"><tr><th>Check-In</th><th>Check-Out</th><th>Store</th><th>Photo</th><th>Location</th><th>Action</th></tr></thead>
<tbody>
<?php foreach($val['logs'] as $log):
$isEdited = !empty($log['edited']);
?>
<tr class="sub-log-row" <?= $isEdited ? 'style="background-color: var(--error)10;"' : '' ?>>
<td class="pt-2 pb-2">
    <?=date('d-m-Y h:i:s A', strtotime($log['check_in']))?>
    <?= $isEdited ? '<span class="badge badge-edited">Edited</span>' : '' ?>
</td>
<td class="pt-2 pb-2">
    <?php if($log['check_out']): ?>
        <?=date('d-m-Y h:i:s A', strtotime($log['check_out']))?>
    <?php else: ?>
        <span class="badge badge-pending">Pending</span>
    <?php endif; ?>
</td>
<td class="pt-2 pb-2">
    In: <?=htmlspecialchars($log['check_in_store'] ?? 'N/A')?><br>
    Out: <?=htmlspecialchars($log['check_out_store'] ?? 'N/A')?>
</td>
<td class="pt-2 pb-2">
<?php if($log['check_in_photo']): ?><a href="uploads/<?=$log['check_in_photo']?>" target="_blank"><i class="fas fa-image me-1"></i>In</a><?php endif; ?>
<?php if($log['check_out_photo']): ?><a href="uploads/<?=$log['check_out_photo']?>" target="_blank" class="ms-2"><i class="fas fa-image me-1"></i>Out</a><?php endif; ?>
</td>
<td class="pt-2 pb-2">
<?php 
// NOTE: Google Maps URL syntax is often tricky. Use this generic format for maximum compatibility.
if($log['check_in_lat'] && $log['check_in_lng']): ?>
<a href="http://maps.google.com/maps?q=<?=$log['check_in_lat']?>,<?=$log['check_in_lng']?>" target="_blank"><i class="fas fa-map-marker-alt me-1"></i>In</a>
<?php endif; ?>
<?php if($log['check_out_lat'] && $log['check_out_lng']): ?>
<a href="http://maps.google.com/maps?q=<?=$log['check_out_lat']?>,<?=$log['check_out_lng']?>" target="_blank" class="ms-2"><i class="fas fa-map-marker-alt me-1"></i>Out</a>
<?php endif; ?>
</td>
<td class="pt-2 pb-2">
<button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editModal<?=$log['id']?>"><i class="fas fa-edit"></i> Edit</button>

<div class="modal fade" id="editModal<?=$log['id']?>" tabindex="-1" aria-labelledby="editModalLabel<?=$log['id']?>" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="update_log.php">
        <div class="modal-header">
          <h5 class="modal-title" id="editModalLabel<?=$log['id']?>">Edit Log (<?=htmlspecialchars($log['full_name'])?>)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" value="<?=$log['id']?>">
          <div class="mb-3">
            <label class="form-label">Check-In</label>
            <input type="datetime-local" name="check_in" value="<?=date('Y-m-d\TH:i', strtotime($log['check_in']))?>" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Check-Out</label>
            <input type="datetime-local" name="check_out" value="<?= $log['check_out'] ? date('Y-m-d\TH:i', strtotime($log['check_out'])) : '' ?>" class="form-control">
            <div class="form-text">Leave blank if the user hasn't checked out yet.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save changes</button>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>

<?php 
// Prepare base URL for pagination links, maintaining existing filters
$base_url = 'logs.php?' . http_build_query([
    'start' => $start,
    'end' => $end,
    'user_id' => $user_id,
    // 'page' will be added in the loop
]);
?>

<div class="d-flex justify-content-between align-items-center mt-4">
    <small class="text-muted">
        Showing logs <?= min($offset + 1, $total_records) ?> to <?= min($offset + $limit, $total_records) ?> of <?= $total_records ?> total.
    </small>

    <?php if ($total_pages > 1): ?>
    <nav aria-label="Page navigation">
        <ul class="pagination pagination-sm justify-content-end mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $base_url . '&page=' . ($page - 1) ?>" aria-label="Previous">
                    <span aria-hidden="true">«</span>
                </a>
            </li>

            <?php 
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);

            if ($start_page > 1) {
                echo '<li class="page-item"><a class="page-link" href="' . $base_url . '&page=1">1</a></li>';
                if ($start_page > 2) {
                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
            }

            for ($i = $start_page; $i <= $end_page; $i++): ?>
            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= $base_url . '&page=' . $i ?>"><?= $i ?></a>
            </li>
            <?php endfor;

            if ($end_page < $total_pages) {
                if ($end_page < $total_pages - 1) {
                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
                echo '<li class="page-item"><a class="page-link" href="' . $base_url . '&page=' . $total_pages . '">' . $total_pages . '</a></li>';
            }
            ?>

            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $base_url . '&page=' . ($page + 1) ?>" aria-label="Next">
                    <span aria-hidden="true">»</span>
                </a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>
</div>
</div>

<div class="modal fade" id="addMissingModal" tabindex="-1" aria-labelledby="addMissingModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="addMissingForm" method="POST" action="add_missing_log.php">
        <div class="modal-header">
          <h5 class="modal-title" id="addMissingModalLabel">Add Missing Log</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">User</label>
            <select name="user_id" class="form-select" required>
              <?php foreach($users as $u): ?>
              <option value="<?=$u['id']?>"><?=htmlspecialchars($u['full_name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="row">
            <div class="col-6 mb-3">
              <label class="form-label">Check-In Date</label>
              <input type="date" name="check_in_date" id="check_in_date" class="form-control" required>
            </div>
            <div class="col-6 mb-3">
              <label class="form-label">Check-In Time</label>
              <input type="time" name="check_in_time" id="check_in_time" class="form-control" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Check-In Store</label>
            <input type="text" name="check_in_store" id="check_in_store" class="form-control" placeholder="Store Name/Location" required>
          </div>

          <hr class="my-4">
          
          <div class="row">
            <div class="col-6 mb-3">
              <label class="form-label">Check-Out Date </label>
              <input type="date" name="check_out_date" id="check_out_date" class="form-control">
            </div>
            <div class="col-6 mb-3">
              <label class="form-label">Check-Out Time </label>
              <input type="time" name="check_out_time" id="check_out_time" class="form-control">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Check-Out Store </label>
            <input type="text" name="check_out_store" id="check_out_store" class="form-control" placeholder="Store Name/Location">
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Add Log</button>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="text-center my-4">
    <a href="admin_dashboard.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// --- Validation Script for Add Missing Log ---
document.getElementById('addMissingForm').addEventListener('submit', function(e){
    // Retrieve all relevant input values
    const ciDate = document.getElementById('check_in_date').value.trim();
    const ciTime = document.getElementById('check_in_time').value.trim();
    const ciStore = document.getElementById('check_in_store').value.trim();

    const coDate = document.getElementById('check_out_date').value.trim();
    const coTime = document.getElementById('check_out_time').value.trim();
    const coStore = document.getElementById('check_out_store').value.trim();

    // 1. Check-In Fields (All three must be present)
    if(!ciDate || !ciTime || !ciStore){
        alert("Check-In Date, Time, and Store are required to create a log.");
        e.preventDefault();
        return false;
    }

    // 2. Check-Out Fields (All three or none)
    const coFilledCount = [coDate, coTime, coStore].filter(val => val.length > 0).length;

    // The condition is: if more than zero fields are filled (meaning one or two are filled), 
    // but not all three, then it's an error.
    if (coFilledCount > 0 && coFilledCount < 3) {
        alert("If you are adding Check-Out details, Check-Out Date, Time, and Store must all be filled.");
        e.preventDefault();
        return false;
    }

    return true; // Proceed with submission
});

// --- Dark Mode Toggle Script (Rest of the script remains unchanged) ---
const toggleSwitch = document.getElementById('theme-toggle');

// Load theme preference from localStorage or default to system preference
const currentTheme = localStorage.getItem('theme') ? localStorage.getItem('theme') : 
    (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

if (currentTheme === 'dark') {
    document.documentElement.classList.add('dark-mode');
    toggleSwitch.checked = true;
}

toggleSwitch.addEventListener('change', function() {
    if (this.checked) {
        document.documentElement.classList.add('dark-mode');
        localStorage.setItem('theme', 'dark');
    } else {
        document.documentElement.classList.remove('dark-mode');
        localStorage.setItem('theme', 'light');
    }
});
</script>
</body>
</html>