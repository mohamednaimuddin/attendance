<?php
/**
 * ATTENDANCE REPORT PAGE
 * Displays and summarizes employee attendance data with filters.
 * MODIFIED: Implements a business rule where FRIDAY is classified as a Weekend/Overtime day.
 * UPDATED: Implements continuous date display, 12-hour time format.
 * * NEW FEATURE: Added date range dropdown (Current Month, Last Month, Custom).
 * * NEW FEATURE: Main filter uses single-select. Print modal uses multi-select.
 * * NEW FEATURE: Separated reports for multiple users with clean header display.
 * * NEW FEATURE: Enhanced responsiveness for mobile devices.
 * * NEW FEATURE: Print Options button styled in red.
 * * NEW FEATURE: Back to Dashboard button moved to top right and fixed on scroll.
 * * NEW FEATURE: Revamped Print Options modal design for better user experience.
 * * NEW FEATURE: Added dedicated report title and period display for print view.
 * * NEW FEATURE: **Implemented live search and dynamic selected user list in Print Options modal.**
 */

// --- Configuration and Session Setup (Assuming these exist and are correct) ---
require 'config.php'; 

date_default_timezone_set('Asia/Riyadh'); // or your correct timezone

// Fallback if require_admin() is missing
if (!function_exists('require_admin')) {
    function require_admin() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
            header("Location: index.php"); 
            exit;
        }
    }
}
require_admin();

if (session_status() === PHP_SESSION_NONE) session_start();

// --- Date Range Helper Calculations ---
$current_month_start = date('Y-m-01');
$current_month_end = date('Y-m-t'); 
$last_month_start = date('Y-m-01', strtotime('last month'));
$last_month_end = date('Y-m-t', strtotime('last month'));

// --- Fetch Admin Name for Print ---
$admin_name_for_print = $_SESSION['full_name'] ?? 'Admin User'; 
// --- END Admin Name Fetch ---

// --- Filters & Input Sanitization (Using null coalescing operator ??) ---
// Main Filter: Single Select. $filter_user is a single ID or 'all'
$filter_user = filter_input(INPUT_GET, 'user', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? 'all';

// Multi-Select Array for Print Modal:
$temp_input_array = filter_input(INPUT_GET, 'user', FILTER_SANITIZE_NUMBER_INT, FILTER_REQUIRE_ARRAY);
// Use PHP 7.0+ null coalescing to ensure $filter_user_input_array is always an array
$filter_user_input_array = is_array($temp_input_array) ? array_filter($temp_input_array) : []; 

$date_range_preset = filter_input(INPUT_GET, 'range', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
$start_date = filter_input(INPUT_GET, 'start', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
$end_date = filter_input(INPUT_GET, 'end', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';

$print_mode = filter_input(INPUT_GET, 'print_mode', FILTER_SANITIZE_NUMBER_INT);


// --- Set dates based on preset if no custom dates are provided ---
$default_filter_applied = false;
if (empty($start_date) && empty($end_date)) {
    if ($date_range_preset === 'last_month') {
        $start_date = $last_month_start;
        $end_date = $last_month_end;
    } else { // Defaults to current_month
        $start_date = $current_month_start;
        $end_date = $current_month_end; 
        $date_range_preset = 'current_month'; // Ensure preset is set correctly
        $default_filter_applied = true;
    }
} else {
    // If custom dates are set, override preset
    $date_range_preset = 'custom';
}
// --- END Default Dates ---


// --- Fetch users (non-admins) ---
try {
    // Ensure PDO connection ($pdo) is available via config.php
    $users = $pdo->query("SELECT id, full_name FROM users WHERE is_admin = 0 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    $all_user_ids = array_column($users, 'id');
    $user_map = array_combine($all_user_ids, $users);
} catch (PDOException $e) {
    $users = [];
    $all_user_ids = [];
    $user_map = [];
}

// --- Determine Users to Report On (Handles both single and multi-select logic) ---
$users_to_report = [];
$sql_user_ids = [];

if ($print_mode) {
    // 1. PRINT MODE: Multi-select array determines users. If empty, report on ALL.
    if (!empty($filter_user_input_array)) {
        foreach ($filter_user_input_array as $user_id) {
            $user_id = (int)$user_id;
            if (isset($user_map[$user_id])) {
                $users_to_report[$user_id] = $user_map[$user_id];
                $sql_user_ids[] = $user_id;
            }
        }
    } else {
        // Report on ALL Users for Print Mode when nothing is selected
        $users_to_report = $user_map;
        $sql_user_ids = $all_user_ids;
    }
} elseif ($filter_user !== 'all' && $filter_user !== '') {
    // 2. MAIN FILTER: Single User Selected
    $user_id = (int)$filter_user;
    if (isset($user_map[$user_id])) {
        $users_to_report[$user_id] = $user_map[$user_id];
        $sql_user_ids[] = $user_id;
    }
} else {
    // 3. MAIN FILTER: 'All Users' Selected (Default view, single aggregated report)
    $users_to_report = $user_map;
    $sql_user_ids = $all_user_ids;
}


// --- Build Dynamic SQL Query ---
$sql = "SELECT a.*, u.full_name FROM attendance a JOIN users u ON a.user_id = u.id WHERE 1=1";
$params = [];

if (!empty($sql_user_ids)) {
    // Use IN clause for multiple users
    $placeholders = implode(',', array_fill(0, count($sql_user_ids), '?'));
    $sql .= " AND u.id IN ($placeholders)";
    $params = array_merge($params, $sql_user_ids);
}

// Filter by date range (inclusive)
if ($start_date !== '') {
    $sql .= " AND a.check_in >= ?";
    $params[] = date('Y-m-d 00:00:00', strtotime($start_date));
}
if ($end_date !== '') {
    $sql .= " AND a.check_in <= ?";
    $params[] = date('Y-m-d 23:59:59', strtotime($end_date));
}

$sql .= " ORDER BY u.full_name ASC, a.check_in ASC";

// --- Execute Query ---
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rows = [];
    // error_log("Error executing attendance query: " . $e->getMessage());
}

// --- Helper to format seconds (H:i) ---
function fmt($sec){
    if ($sec <= 0) return "00:00";
    $h = floor($sec/3600);
    $m = floor(($sec%3600)/60);
    return sprintf("%02d:%02d", $h, $m);
}

// Constants for work shift calculation
$SHIFT_HOURS = 10; 
$OVERTIME_BUFFER_MINUTES = 29; 
$shift_seconds = $SHIFT_HOURS * 3600;
$overtime_threshold = $shift_seconds + ($OVERTIME_BUFFER_MINUTES * 60);

// --- 1. Process Raw Data and Group by User/Day ---
$daily_attendance = [];
foreach ($rows as $r) {
    $check_in_time = strtotime($r['check_in']);
    $check_out_time = $r['check_out'] ? strtotime($r['check_out']) : null;
    $day = date("Y-m-d", $check_in_time);
    $user_id = $r['user_id'];
    
    // PHP 7.4+ null coalescing assignment operator (??=)
    $daily_attendance[$user_id] ??= ['name' => $r['full_name'], 'days' => []];
    $daily_attendance[$user_id]['days'][$day] ??= ['records' => [], 'total_worked_sec' => 0];
    
    // Calculate worked seconds for this specific in/out pair
    $worked_sec_instance = 0;
    if ($r['check_out']) {
        $end_ts = $check_out_time;
        // Check for midnight rollover (e.g., check-in 23:00, check-out 01:00)
        if ($end_ts < $check_in_time) {
             $end_ts = strtotime('+1 day', $check_out_time);
        }
        
        $worked_sec_instance = max(0, $end_ts - $check_in_time);
        $daily_attendance[$user_id]['days'][$day]['total_worked_sec'] += $worked_sec_instance;
    }
    
    // Store all records for display (***12-HOUR FORMAT***)
    $daily_attendance[$user_id]['days'][$day]['records'][] = [
        'check_in_fmt' => date('h:i:s A', $check_in_time), 
        'check_out_fmt' => $r['check_out'] ? date('h:i:s A', $check_out_time) : '-', 
        'check_in_store' => htmlspecialchars($r['check_in_store'] ?? '-'),
        'check_out_store' => htmlspecialchars($r['check_out_store'] ?? '-'),
    ];
}


// --- 2. Multi-Report Generation Logic (Determines if reports are separate or aggregated) ---
$reports_output = [];
$users_to_display = [];

// Determine who to iterate over for separate reports
if ($print_mode || ($filter_user !== 'all' && $filter_user !== '')) {
    $users_to_display = $users_to_report;
} else {
    // If not in print mode AND 'All Users' is selected, treat it as one aggregated report.
    $users_to_display[0] = ['id' => 0, 'full_name' => 'All Users']; 
    
    // Collect all unique days across all users for aggregation
    $aggregated_data = [];
    foreach ($daily_attendance as $u_id => $u_data) {
        foreach ($u_data['days'] as $day => $data) {
             $aggregated_data[$day] ??= $data; // Use ??=
             if ($aggregated_data[$day] !== $data) { // If it was initialized by another user
                 $aggregated_data[$day]['total_worked_sec'] += $data['total_worked_sec'];
                 $aggregated_data[$day]['records'] = array_merge($aggregated_data[$day]['records'], $data['records']);
             }
        }
    }
    // Set the aggregated data to the virtual user 0
    if(!empty($aggregated_data)) {
        $daily_attendance[0] = ['name' => 'All Users', 'days' => $aggregated_data];
    }
}


foreach ($users_to_display as $u_id => $u_data) {
    
    $current_user_name = $u_data['full_name'];
    // Use PHP 7.0+ null coalescing operator
    $current_user_data = $daily_attendance[$u_id]['days'] ?? []; 

    $final_summary_data = [];
    $total_work = 0;
    $total_ot = 0;
    
    $start_ts = strtotime($start_date);
    $end_ts = strtotime($end_date);

    for ($current_ts = $start_ts; $current_ts <= $end_ts; $current_ts = strtotime('+1 day', $current_ts)) {
        $day = date('Y-m-d', $current_ts);
        $day_of_week = date('D', $current_ts);
        $is_weekend = ($day_of_week === 'Fri'); 

        // Initialize summary for the day
        $final_summary_data[$day] = [
            'checkins' => ['-'], 'checkouts' => ['-'],
            'checkin_stores' => ['-'], 'checkout_stores' => ['-'],
            'standard_worked' => 0, 
            'overtime' => 0, 
            'total_elapsed' => 0,
            'is_weekend' => $is_weekend
        ];

        if (isset($current_user_data[$day])) {
            $daily_worked_time = $current_user_data[$day]['total_worked_sec'];
            $records = $current_user_data[$day]['records'];
            
            $final_summary_data[$day]['total_elapsed'] = $daily_worked_time; 

            // Format multiple records for display
            $final_summary_data[$day]['checkins'] = array_column($records, 'check_in_fmt');
            $final_summary_data[$day]['checkouts'] = array_column($records, 'check_out_fmt');
            $final_summary_data[$day]['checkin_stores'] = array_column($records, 'check_in_store');
            $final_summary_data[$day]['checkout_stores'] = array_column($records, 'check_out_store');
            
            // --- Apply Business Rules for Standard/Overtime Calculation and Accumulation ---
            if ($is_weekend) {
                $final_summary_data[$day]['overtime'] = $daily_worked_time;
                $final_summary_data[$day]['standard_worked'] = 0; 
                
                $total_ot += $daily_worked_time; 
                
                // DISPLAY CHANGE: Zero out the elapsed time for display on Friday (as it's all OT).
                $final_summary_data[$day]['total_elapsed'] = 0; 
            } else {
                // Weekday calculation 
                if ($daily_worked_time > $overtime_threshold) {
                    $standard_sec = $shift_seconds;
                    $overtime_sec = $daily_worked_time - $shift_seconds;
                    
                    $final_summary_data[$day]['overtime'] = $overtime_sec;
                    $final_summary_data[$day]['standard_worked'] = $standard_sec; 
                } else {
                    $final_summary_data[$day]['standard_worked'] = $daily_worked_time;
                    $final_summary_data[$day]['overtime'] = 0;
                }
                
                // We accumulate the full worked time in $total_work for Weekdays
                $total_work += $daily_worked_time; 
                $total_ot += $final_summary_data[$day]['overtime'];
            }
        }
    }
    
    // Store the processed data for later rendering
    $reports_output[] = [
        'name' => $current_user_name,
        'summary_data' => $final_summary_data,
        'total_work' => $total_work,
        'total_ot' => $total_ot,
        'has_records' => !empty($current_user_data)
    ];
}


// --- Prepare filtered Month/Year string for display ---
$filtered_month_year_display = '';
if ($start_date || $end_date) {
    $start_ts = strtotime($start_date);
    $end_ts = strtotime($end_date);
    
    // Logic for concise date range display
    if (date('Y-m-d', $start_ts) === date('Y-m-d', $end_ts)) {
           $filtered_month_year_display = date('F d, Y', $start_ts);
    } elseif (date('Y-m', $start_ts) === date('Y-m', $end_ts)) {
        $filtered_month_year_display = date('F d', $start_ts) . ' - ' . date('d, Y', $end_ts);
    } elseif (date('Y', $start_ts) === date('Y', $end_ts)) {
        $filtered_month_year_display = date('F d', $start_ts) . ' - ' . date('F d, Y', $end_ts);
    } else {
        $filtered_month_year_display = date('F d, Y', $start_ts) . ' - ' . date('F d, Y', $end_ts);
    }
    
    // Override with simple month/year if default current/last month filter was applied
    if ($default_filter_applied && $date_range_preset !== 'custom') {
        $filtered_month_year_display = date('F Y', $start_ts);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance Report - Visionangles</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ================================================= */
/* ===== TEAL/NAVY MINIMALIST THEME (Matching admin_dashboard.php) ===== */
/* ================================================= */
:root {
    /* --- LIGHT MODE (Teal Accent) --- */
    --text-color: #2c3e50;
    --bg-primary: #f7f9fb;
    --card-bg: #ffffff;
    --muted-text-color: #95a5a6;
    --main-color: #1abc9c; /* Primary: Deep Teal */
    --main-hover: #148f77; 
    --accent: #3498db; 
    --error: #e74c3c;
    --table-header: #1abc9c; /* Use main-color directly for table */
    --table-header-text: #ffffff;
    --border-color: rgba(0,0,0,0.1);
    --shadow: 0 10px 30px rgba(0,0,0,0.08);
    --hover-bg: rgba(26, 188, 156, 0.05); 
    transition: all 0.5s ease;
}

html.dark-mode {
    --text-color: #ecf0f1;
    --bg-primary: #1c2833;
    --card-bg: #2c3e50;
    --muted-text-color: #bdc3c7;
    --main-color: #1abc9c;
    --main-hover: #16a085;
    --accent: #3498db;
    --error: #ff6b6b;
    --table-header: #1abc9c;
    --table-header-text: #ffffff;
    --border-color: rgba(255, 255, 255, 0.1);
    --shadow: 0 10px 30px rgba(0,0,0,0.4);
    --hover-bg: rgba(26, 188, 156, 0.1);
}

body {
    font-family:'Poppins',sans-serif;
    background-color:var(--bg-primary);
    color:var(--text-color);
    padding:20px;
    transition: background-color 0.5s ease, color 0.5s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.report-card {
    background: var(--card-bg);
    padding: 30px;
    border-radius: 12px;
    box-shadow: var(--shadow);
    animation: fadeIn 0.8s ease-out;
    transition: all 0.5s ease;
}

.company-header {
    color: var(--main-color);
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 2rem;
    text-align: center;
    text-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    width: 100%;
}
.company-header a { color: inherit; text-decoration: none; transition: color 0.3s; }
.company-header a:hover { color: var(--main-hover); }

h2 {
    font-size:30px;
    font-weight:700;
    color:var(--main-color);
    margin-bottom:30px;
    text-align:center;
    transition: color 0.5s;
    text-transform: uppercase;
}

/* --- Custom Filter Button Style (Teal) --- */
.btn-primary-custom {
    background-color: var(--main-color);
    border-color: var(--main-color);
    color: var(--table-header-text); /* White */
    font-weight: 600;
}
.btn-primary-custom:hover {
    background-color: var(--main-hover);
    border-color: var(--main-hover);
    color: var(--table-header-text);
}
/* --- END Custom Filter Button Style --- */


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

/* Date display toggle - show full on screen, compact on print */
.date-full { display: inline; }
.date-compact { display: none; }

/* Hide print page header on screen */
.print-page-header { display: none; }

/* Table styles */
.table-responsive {
    border-radius:8px;
    overflow-x: auto;
    border: 1px solid var(--border-color);
}
.table {
    margin-bottom: 0;
    color: var(--text-color);
    min-width: 700px;
}

/* Updated for smaller screen font and no wrap */
.table-header-custom th {
    background-color: var(--table-header);
    color: var(--table-header-text);
    text-transform: uppercase;
    font-size: 0.8rem;
    border-bottom: 2px solid var(--main-hover);
    border-color: var(--border-color) !important;
    white-space: nowrap;
}
/* Updated for smaller screen padding */
.table tbody tr td { 
    padding: 0.35rem 0.5rem; 
}


tfoot td {
    font-weight: 700;
    background-color: var(--main-color) !important;
    color: var(--table-header-text) !important; 
}

/* Add custom class for Friday/Weekend row */
.table-warning {
    --bs-table-bg-type: var(--bs-warning-rgb);
    background-color: rgba(255, 193, 7, 0.1) !important;
}

/* Custom color for OT text */
.text-overtime {
    color: var(--error);
    font-weight: 600;
}

/* Dark Mode Switch Styling */
.theme-switch-wrapper {
    position: absolute;
    top: 20px;
    right: 20px;
    display: flex;
    align-items: center;
    z-index: 1000;
}

/* === PRINT BUTTON STYLE (RED) === */
.btn-print-options {
    background-color: #e74c3c; /* Red/Error Color */
    border-color: #e74c3c;
    color: #ffffff; /* White text */
}
.btn-print-options:hover {
    background-color: #c0392b; /* Darker Red on hover */
    border-color: #c0392b;
    color: #ffffff;
}
/* === END PRINT BUTTON STYLE === */

/* === BACK TO DASHBOARD FIXED BUTTON (NEW) === */
.btn-back-to-dashboard-fixed {
    position: fixed;
    top: 15px;
    right: 15px;
    z-index: 1010;
    font-weight: 600;
    padding: 8px 15px;
    border-radius: 8px;
    transition: all 0.3s ease;
    background-color: var(--card-bg); 
    border-color: var(--main-color);
    color: var(--main-color);
    box-shadow: var(--shadow);
}
.btn-back-to-dashboard-fixed:hover {
    background-color: var(--main-color);
    color: var(--table-header-text);
}
/* Hide the fixed button completely during print */
@media print {
    .btn-back-to-dashboard-fixed {
        display: none !important;
    }
}
/* Move the theme switch slightly to the left to avoid collision */
.theme-switch-wrapper {
    right: 180px;
}
/* === END FIXED BUTTON === */

/* --- Custom Modal Styling for Search/Checkboxes --- */
.search-dropdown-container {
    position: relative;
    z-index: 1055; /* Higher z-index to overlay other elements */
}
.search-input-group {
    margin-bottom: 0 !important;
}
.available-users-list {
    position: absolute;
    width: 100%;
    max-height: 250px;
    overflow-y: auto;
    background-color: var(--card-bg);
    border: 1px solid var(--border-color);
    border-top: none;
    border-radius: 0 0 8px 8px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    display: none; /* Hidden by default */
}
.available-users-list .list-item {
    padding: 8px 15px;
    cursor: pointer;
    transition: background-color 0.2s;
}
.available-users-list .list-item:hover {
    background-color: var(--hover-bg);
}
.available-users-list .list-item .form-check {
    margin: 0;
}

/* Selected Badges Area */
#selectedUsersBadges {
    min-height: 40px;
    padding: 8px;
    border: 1px dashed var(--muted-text-color);
    border-radius: 8px;
    margin-top: 10px;
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    align-items: center;
}
.user-badge {
    background-color: var(--main-color);
    color: white;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
}
.user-badge .remove-btn {
    background: none;
    border: none;
    color: white;
    font-weight: bold;
    margin-left: 5px;
    padding: 0;
    cursor: pointer;
    font-size: 1rem;
    line-height: 1;
}

/* === MOBILE RESPONSIVENESS ADJUSTMENTS === */
@media (max-width: 767px) {
    body { padding: 10px; }
    .report-card { padding: 15px; }
    .company-header { font-size: 1.5rem; margin-bottom: 1rem; }
    h2 { font-size: 24px; margin-bottom: 20px; }
    
    /* Filters: Stack inputs on mobile */
    .filter-section .row.g-3 > div {
        margin-bottom: 8px;
    }
    
    /* Buttons: Stack or make them fill width */
    .filter-buttons {
        flex-direction: column;
        gap: 5px !important;
    }

    /* Adjust fixed button position for mobile */
    .btn-back-to-dashboard-fixed {
        top: 10px;
        right: 10px;
        font-size: 0.75rem;
        padding: 5px 10px;
    }
    /* Move the theme switch even further left on mobile */
    .theme-switch-wrapper {
        right: 140px; 
    }
    
    .report-info { font-size: 0.8rem; margin-bottom: 10px; padding-left: 5px; }
}

/* Print Styles: COMPACT - FIT FULL MONTH ON ONE A4 PAGE */
@media print {
    @page {
        margin: 10cm;
        size: A4 portrait;
    }

    * {
        box-sizing: border-box;
    }

    html, body { 
        background: #fff; 
        padding: 0;
        margin: 0;
        height: 100%;
    }

    /* Hide old print header - using new per-page header instead */
    .print-header {
        display: none !important;
    }

    /* Hide non-report elements */
    .company-header, .filter-section, .btn, .text-center a, .theme-switch-wrapper, .modal, .container-fluid > .report-card > h2 {
        display:none !important;
    }
    
    .report-card {
        box-shadow:none !important;
        padding:0;
        border:none;
        max-width: 100% !important;
        height: 100%;
    }
    
    .table-responsive {
        box-shadow:none !important;
        padding:0;
        border:none;
        max-width: 100% !important;
        overflow: visible !important;
    }
    
    .container-fluid {
        padding: 0 !important;
        margin: 0 !important;
        height: 100%;
    }
    
    .user-report-section {
        margin: 0 !important;
        padding: 0 !important;
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    
    .user-report-section h4 {
        margin: 0 0 1px 0 !important;
        font-size: 8pt !important;
        flex-shrink: 0;
    }

    /* Page break between user reports for clean multi-user printing */
    .user-report-section:not(:last-child) {
        page-break-after: always;
        break-after: page;
    }

    .report-info {
        display:block;
        font-size: 8pt;
        margin-bottom: 1px;
        border-left: none;
        padding-left: 0;
        color: #000;
        text-align: left;
        flex-shrink: 0;
    }
    .report-info .user-name {
        font-size: 8pt;
        font-weight: 900;
        color: #000;
    }

    .print-footer { 
        display: block !important;
        position: static;
        margin-top: auto;
        width: 100%; 
        text-align: right; 
        font-size: 6pt; 
        padding: 1px 2px; 
        color: #555;
        flex-shrink: 0;
    }

    .table-responsive {
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    table { 
        width: 100%; 
        border-collapse: collapse; 
        color: #000; 
        table-layout: fixed;
        flex: 1;
        display: table;
    }

    th, td {
        border: 0.5pt solid #444;
        padding: 2px 3px; 
        font-size: 7pt;
        line-height: 1.2;
        white-space: nowrap;
        vertical-align: middle;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    /* Make rows stretch to fill available space */
    tbody {
        display: table-row-group;
    }
    
    tbody tr {
        height: calc((100vh - 100px) / 34);
    }
    
    /* Make date column show compact format */
    td:nth-child(1) .date-full { display: none !important; }
    td:nth-child(1) .date-compact { display: inline !important; }

    /* Distribute column widths for A4 portrait */
    th:nth-child(1), td:nth-child(1) { width: 12%; } /* Date - compact format */
    th:nth-child(2), td:nth-child(2) { width: 10%; } /* Check-In Time */
    th:nth-child(3), td:nth-child(3) { width: 21%; } /* Check-In Store */
    th:nth-child(4), td:nth-child(4) { width: 10%; } /* Check-Out Time */
    th:nth-child(5), td:nth-child(5) { width: 21%; } /* Check-Out Store */
    th:nth-child(6), td:nth-child(6) { width: 10%; } /* Work Time */
    th:nth-child(7), td:nth-child(7) { width: 10%; } /* Overtime */
    
    /* Allow store columns to wrap if needed */
    th:nth-child(3), td:nth-child(3),
    th:nth-child(5), td:nth-child(5) {
        white-space: normal;
        word-break: break-word;
        font-size: 6.5pt;
    }

    .table-header-custom th, tfoot td { 
        background-color: #ddd !important; 
        color: #000 !important; 
        -webkit-print-color-adjust: exact; 
        color-adjust: exact; 
        print-color-adjust: exact;
        font-size: 7pt; 
        font-weight: bold;
        padding: 3px 3px;
    }
    
    /* Remove striping for cleaner print */
    .table-striped > tbody > tr:nth-of-type(odd) > * {
        --bs-table-bg-type: transparent;
    }
    
    /* Keep weekend highlight subtle */
    .table-warning {
        background-color: #f0f0f0 !important;
    }
    
    /* Ensure footer fits on same page */
    tfoot {
        display: table-footer-group;
    }
    
    /* Keep header with table */
    thead {
        display: table-header-group;
    }
    
    /* Print page header - shown on each page */
    .print-page-header {
        display: block !important;
        text-align: center;
        margin-bottom: 3px;
        color: #000;
        border-bottom: 1.5pt solid #000;
        padding-bottom: 2px;
        flex-shrink: 0;
    }
    .print-page-header h1 {
        font-size: 12pt;
        font-weight: 700;
        margin: 0;
        text-transform: uppercase;
    }
    .print-page-header p {
        font-size: 8pt;
        margin: 1px 0 0 0;
        font-weight: 500;
    }
}
</style>
</head>
<body>

<a href="admin_dashboard.php" class="btn btn-outline-secondary btn-sm btn-back-to-dashboard-fixed">
    <i class="fas fa-arrow-left"></i> Dashboard
</a>
<div class="theme-switch-wrapper">
    <label class="theme-switch" for="theme-toggle" title="Toggle Dark/Light Mode">
        <input type="checkbox" id="theme-toggle">
        <div class="slider round"></div>
    </label>
</div>

<div class="company-header">
    <a href="admin_dashboard.php">
        <i class="fas fa-shield-alt me-2"></i>Vision Angles</a>
</div>

<div class="container-fluid py-4">
    
    <div class="print-header" style="display:none;">
        <h1>Attendance Report</h1>
    </div>

    <div class="report-card mx-auto" style="max-width: 1400px;">
        <h2 class="text-center"><i class="fas fa-chart-line me-2"></i> Attendance Report</h2>

        <div class="filter-section mb-3">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-12 col-md-3">
                    <label for="user-select" class="form-label visually-hidden">User</label>
                    <select id="user-select" name="user" class="form-select form-select-sm" size="1">
                        <option value="all" <?= ($filter_user === 'all')?'selected':'' ?>>All Users</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= ((string)$u['id'] === $filter_user)?'selected':'' ?>><?= htmlspecialchars($u['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-12 col-md-3">
                    <label for="range-select" class="form-label visually-hidden">Date Range</label>
                    <select id="range-select" name="range" class="form-select form-select-sm">
                        <option value="current_month" <?= ($date_range_preset === 'current_month')?'selected':'' ?>>Current Month</option>
                        <option value="last_month" <?= ($date_range_preset === 'last_month')?'selected':'' ?>>Last Month</option>
                        <option value="custom" <?= ($date_range_preset === 'custom')?'selected':'' ?>>Custom Date Range</option>
                    </select>
                </div>

                <div class="col-6 col-md-2 custom-date-fields" style="<?= ($date_range_preset !== 'custom') ? 'display:none;' : '' ?>">
                    <label for="start-date" class="form-label visually-hidden">Start Date</label>
                    <input id="start-date" type="date" name="start" placeholder="Start Date" value="<?= htmlspecialchars($start_date) ?>" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-2 custom-date-fields" style="<?= ($date_range_preset !== 'custom') ? 'display:none;' : '' ?>">
                    <label for="end-date" class="form-label visually-hidden">End Date</label>
                    <input id="end-date" type="date" name="end" placeholder="End Date" value="<?= htmlspecialchars($end_date) ?>" class="form-control form-control-sm">
                </div>
                
                <div class="col-12 col-md-4 d-flex gap-2 filter-buttons">
                    <button type="submit" class="btn btn-primary-custom btn-sm flex-fill">Filter <i class="fas fa-filter"></i></button>
                    <a href="attendance_report.php" class="btn btn-outline-secondary btn-sm flex-fill">Clear</a>
                    <button type="button" class="btn btn-print-options btn-sm flex-fill" data-bs-toggle="modal" data-bs-target="#printOptionsModal">
                        Print Options <i class="fas fa-print"></i>
                    </button>
                </div>
            </form>
        </div>

        <?php 
        // Determine what is being displayed for the header
        $report_name = 'All Users ';
        $is_multi_report = count($reports_output) > 1 || $print_mode;
        
        if ($is_multi_report) {
             $report_name = ''; // Blank the main title
        } elseif (count($reports_output) === 1 && $reports_output[0]['name'] !== 'All Users') {
             $report_name = htmlspecialchars($reports_output[0]['name']);
        }
        ?>

        <?php if (!$is_multi_report): ?>
        <div class="report-info">
            REPORT: <span><?= $report_name ?></span> | 
            PERIOD: <span><?= $filtered_month_year_display ?></span>
        </div>
        <?php endif; ?>


    <?php foreach ($reports_output as $report): ?>
    
    <div class="user-report-section my-4">
        <!-- Print header for each page -->
        <div class="print-page-header">
            <h1>Attendance Report</h1>
            <p><?= $filtered_month_year_display ?></p>
        </div>
        
        <?php 
            // Display employee name and period for each separate report
            $user_period_info = '';
            if ($is_multi_report) {
                // Determine if this is a sub-report (print mode or single user main filter)
                if (count($reports_output) > 1 || $print_mode) {
                    $user_period_info = 'EMPLOYEE: ' . htmlspecialchars($report['name']) . ' | PERIOD: ' . $filtered_month_year_display;
                }
            }
        ?>

        <?php if ($user_period_info): ?>
        <h4 class="mb-3 text-center" style="color:var(--main-hover); font-weight:600; text-transform:uppercase;">
            <span class="report-info user-name d-block mb-1"><?= $user_period_info ?></span>
        </h4>
        <?php endif; ?>
        
        <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle table-sm" style="font-size:0.85rem;">
            <thead class="table-header-custom">
                <tr>
                    <th>DATE</th><th>CHECK-IN</th><th>CHECK-IN STORE</th><th>CHECK-OUT</th><th>CHECK-OUT STORE</th><th>WORK TIME</th><th>OVERTIME</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!$report['has_records']): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">No attendance records found for <?= htmlspecialchars($report['name']) ?> in this period.</td></tr>
                <?php else: ?>
                <?php foreach ($report['summary_data'] as $day => $val):
                    $highlight_class = $val['is_weekend'] ? ' table-warning' : ''; 
                    
                    // Check if there are any real records for the day (not just the initialized placeholder array ['-'])
                    $has_work = !($val['checkins'] === ['-']); 
                    $row_class = $has_work ? '' : 'table-light text-muted'; 

                    $checkin_content = $has_work ? implode('<br>', $val['checkins']) : '-';
                    $checkout_content = $has_work ? implode('<br>', $val['checkouts']) : '-';
                    $checkin_store_content = $has_work ? implode('<br>', $val['checkin_stores']) : '-';
                    $checkout_store_content = $has_work ? implode('<br>', $val['checkout_stores']) : '-';
                    ?>
                    <tr class="<?= trim($highlight_class . ' ' . $row_class) ?>">
                        <td class="fw-bold">
                            <span class="date-full" style="white-space: nowrap;"><?= date('d-m-Y, D', strtotime($day)) ?></span>
                            <span class="date-compact" style="display: none; white-space: nowrap;"><?= date('d M, D', strtotime($day)) ?></span>
                        </td>
                        
                        <td><?= $checkin_content ?></td>
                        <td><?= $checkin_store_content ?></td>
                        <td><?= $checkout_content ?></td>
                        <td><?= $checkout_store_content ?></td>
                        
                        <td class="fw-bold">
                            <?= ($val['is_weekend'] && $has_work) ? '-' : fmt($val['total_elapsed']) ?>
                        </td>
                        
                        <td class="<?= $val['overtime']>0?'text-overtime':'' ?>">
                            <?= fmt($val['overtime']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot class="table-header-custom">
                <tr>
                    <td colspan="5" class="text-end">TOTAL TIME FOR <?= htmlspecialchars($report['name']) ?></td>
                    <td><?= fmt($report['total_work']) ?></td> 
                    <td class="text-overtime"><?= fmt($report['total_ot']) ?></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
    
    <?php endforeach; ?>
    </div>

    <div class="print-footer" style="display:none;">
        Generated by: <?= htmlspecialchars($admin_name_for_print) ?> on <?= date('d-m-Y h:i A') ?>
    </div>

    </div>

<div class="modal fade" id="printOptionsModal" tabindex="-1" aria-labelledby="printOptionsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white" style="background-color: var(--main-color) !important;">
                <h5 class="modal-title" id="printOptionsModalLabel"><i class="fas fa-print me-2"></i>Generate & Print Attendance Report</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="printForm" action="attendance_report.php" method="GET">
                <input type="hidden" name="print_mode" value="1"> 
                <div class="modal-body">
                    
                    <div class="mb-4 p-3 border rounded" style="border-color: var(--main-color) !important;">
                        <label for="print-range-select" class="form-label fw-bold"><i class="fas fa-calendar-alt me-1"></i> Select Report Period</label>
                        <select id="print-range-select" name="range" class="form-select form-select-sm">
                            <option value="current_month" <?= ($date_range_preset === 'current_month') ? 'selected' : '' ?>>Current Month (<?= date('F Y') ?>)</option>
                            <option value="last_month" <?= ($date_range_preset === 'last_month') ? 'selected' : '' ?>>Last Month (<?= date('F Y', strtotime('last month')) ?>)</option>
                            <option value="custom" <?= ($date_range_preset === 'custom') ? 'selected' : '' ?>>Custom Date Range</option>
                        </select>
                    </div>

                    <div class="row g-3 mb-4 print-custom-date-fields" style="<?= ($date_range_preset !== 'custom') ? 'display:none;' : '' ?> border-left: 3px solid var(--accent); padding-left: 10px;">
                        <div class="col-md-6">
                            <label for="print-start-date" class="form-label form-label-sm">Start Date</label>
                            <input id="print-start-date" type="date" name="start" class="form-control form-control-sm" value="<?= htmlspecialchars($start_date) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="print-end-date" class="form-label form-label-sm">End Date</label>
                            <input id="print-end-date" type="date" name="end" class="form-control form-control-sm" value="<?= htmlspecialchars($end_date) ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3 p-3 border rounded" style="background-color: var(--hover-bg); border-color: var(--border-color) !important;">
                        <label class="form-label fw-bold d-block mb-2"><i class="fas fa-search me-1"></i> Search & Select User(s)</label>
                        
                        <div class="search-dropdown-container">
                            <div class="input-group input-group-sm search-input-group">
                                <span class="input-group-text"><i class="fas fa-user-tag"></i></span>
                                <input type="text" id="userSearchInput" class="form-control" placeholder="Type name to search...">
                            </div>
                            
                            <div id="availableUsersList" class="available-users-list">
                                <div class="p-3 text-muted">Start typing a name to find employees.</div>
                            </div>
                        </div>

                        <label class="form-label fw-bold d-block mt-3"><i class="fas fa-check-square me-1"></i> Selected Users</label>
                        <div id="selectedUsersBadges">
                            <span class="text-muted small" id="noUsersSelectedText">No users selected. Select none to print all.</span>
                        </div>


                        <div class="form-text mt-3 text-danger fw-bold">
                            Tip: <span class="fw-bold text-danger">If no users are selected (list is empty), separate reports for ALL users will be generated.</span>
                        </div>
                        
                        <div class="mt-2 d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-success" id="selectAllSelected"><i class="fas fa-check-double"></i> Select All</button>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="deselectAllSelected"><i class="fas fa-times"></i> Deselect All</button>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-print-options">
                        <i class="fas fa-file-pdf me-1"></i> Generate & Print Report
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// --- GLOBAL DATA: Embed PHP user data into JavaScript ---
const USERS_DATA = <?= json_encode($users); ?>;
// Convert to a map for easy lookup: {id: {id, full_name}}
const USERS_MAP = USERS_DATA.reduce((map, user) => {
    map[user.id] = user;
    return map;
}, {});

// Store the currently selected user IDs
let selectedUserIds = new Set(<?= json_encode(array_map('intval', $filter_user_input_array)); ?>);


// --- Dark Mode Toggle Script ---
const toggleSwitch = document.getElementById('theme-toggle');
// Load theme from localStorage or system preference
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


// --- Date Range Dropdown Logic (Main & Print Filters) ---
function setupDateRangeLogic(rangeSelectId, customFieldsClass) {
    const rangeSelect = document.getElementById(rangeSelectId);
    const customDateFields = document.querySelectorAll(customFieldsClass);

    function toggleCustomDates() {
        const isCustom = rangeSelect.value === 'custom';
        customDateFields.forEach(field => {
            // Use 'block' for the main filter, 'flex' for the modal row
            field.style.display = isCustom ? (customFieldsClass.includes('row') ? 'flex' : 'block') : 'none';
        });
    }

    if (rangeSelect) {
        rangeSelect.addEventListener('change', toggleCustomDates);
        toggleCustomDates(); // Set initial state
    }
}

document.addEventListener('DOMContentLoaded', () => {
    setupDateRangeLogic('range-select', '.custom-date-fields');
    setupDateRangeLogic('print-range-select', '.print-custom-date-fields');
    
    // Initialize the Print Modal user list management
    renderSelectedUsers();
    setupSearchFunctionality();
    setupSelectAllButtons();

    // --- Print on Load Logic (for print_mode=1) ---
    <?php if ($print_mode): ?>
        const reportCard = document.querySelector('.report-card');
        if (reportCard) {
            reportCard.style.maxWidth = '100%'; 
        }
        
        setTimeout(function() {
            window.print();
            // Redirect back to the normal view after a short period
            setTimeout(() => {
                window.location.href = window.location.pathname; 
            }, 500); 
        }, 300); 
    <?php endif; ?>
});

// =========================================================================
// === PRINT MODAL USER SEARCH AND SELECTION LOGIC ===========================
// =========================================================================
const searchInput = document.getElementById('userSearchInput');
const availableUsersList = document.getElementById('availableUsersList');
const selectedUsersBadges = document.getElementById('selectedUsersBadges');
const printForm = document.getElementById('printForm');
const noUsersSelectedText = document.getElementById('noUsersSelectedText');


/**
 * Renders the search results in the dropdown list.
 * @param {string} searchTerm 
 */
function renderAvailableUsers(searchTerm) {
    const term = searchTerm.toLowerCase().trim();
    let html = '';
    let matches = 0;

    if (term.length === 0) {
        availableUsersList.innerHTML = '<div class="p-3 text-muted">Start typing a name to find employees.</div>';
        return;
    }

    USERS_DATA.forEach(user => {
        // Only show users who match the search term AND are NOT currently selected
        if (!selectedUserIds.has(parseInt(user.id)) && user.full_name.toLowerCase().includes(term)) {
            matches++;
            html += `
                <div class="list-item" data-user-id="${user.id}" data-user-name="${user.full_name}">
                    <div class="form-check">
                        <input class="form-check-input print-user-checkbox" type="checkbox" value="${user.id}" id="print-user-${user.id}"
                            onchange="toggleUserSelection(${user.id}, '${user.full_name.replace(/'/g, "\\'")}', this.checked)">
                        <label class="form-check-label" for="print-user-${user.id}">${user.full_name}</label>
                    </div>
                </div>
            `;
        }
    });

    if (matches === 0) {
        html = `<div class="p-3 text-muted">No employees found matching "${term}" or they are already selected.</div>`;
    }

    availableUsersList.innerHTML = html;
}

/**
 * Renders the currently selected users as badges and adds hidden inputs to the form.
 */
function renderSelectedUsers() {
    selectedUsersBadges.innerHTML = '';
    noUsersSelectedText.style.display = selectedUserIds.size === 0 ? 'inline' : 'none';

    // Remove all previous hidden inputs for users
    printForm.querySelectorAll('input[name="user[]"]').forEach(input => input.remove());

    selectedUserIds.forEach(userId => {
        const user = USERS_MAP[userId];
        if (!user) return;

        // 1. Create Badge for display
        const badge = document.createElement('span');
        badge.className = 'user-badge';
        badge.innerHTML = `
            ${user.full_name}
            <button type="button" class="remove-btn" onclick="toggleUserSelection(${userId}, '${user.full_name.replace(/'/g, "\\'")}', false)">
                &times;
            </button>
        `;
        selectedUsersBadges.appendChild(badge);

        // 2. Create Hidden Input for form submission
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'user[]';
        hiddenInput.value = userId;
        hiddenInput.id = `hidden-user-${userId}`;
        printForm.appendChild(hiddenInput);
    });
    
    // After selection change, re-render the available list to remove the newly selected user
    renderAvailableUsers(searchInput.value);
}

/**
 * Toggles a user's selection status.
 * @param {number|string} userId 
 * @param {string} userName 
 * @param {boolean} isSelected 
 */
function toggleUserSelection(userId, userName, isSelected) {
    const id = parseInt(userId);
    if (isSelected) {
        selectedUserIds.add(id);
    } else {
        selectedUserIds.delete(id);
    }
    renderSelectedUsers();
}

/**
 * Sets up the live search and dropdown visibility.
 */
function setupSearchFunctionality() {
    let timeout = null;

    searchInput.addEventListener('input', function() {
        clearTimeout(timeout);
        const term = this.value;
        
        if (term.length > 0) {
            availableUsersList.style.display = 'block';
        } else {
            availableUsersList.style.display = 'none';
        }

        // Debounce the search rendering for performance
        timeout = setTimeout(() => {
            renderAvailableUsers(term);
        }, 200);
    });

    // Hide dropdown on blur unless an item inside is clicked
    searchInput.addEventListener('blur', function() {
        // Use a timeout to allow click events on list items to register first
        setTimeout(() => {
            availableUsersList.style.display = 'none';
        }, 150);
    });
    
    // Show dropdown again on focus if there's text
    searchInput.addEventListener('focus', function() {
        if (this.value.length > 0) {
            availableUsersList.style.display = 'block';
            // Re-render in case users were selected/deselected elsewhere
            renderAvailableUsers(this.value); 
        }
    });
}

/**
 * Sets up the Select All / Deselect All buttons.
 */
function setupSelectAllButtons() {
    document.getElementById('selectAllSelected').addEventListener('click', function() {
        selectedUserIds.clear();
        USERS_DATA.forEach(user => selectedUserIds.add(parseInt(user.id)));
        renderSelectedUsers();
    });

    document.getElementById('deselectAllSelected').addEventListener('click', function() {
        selectedUserIds.clear();
        renderSelectedUsers();
    });
}

</script>
</body>
</html>