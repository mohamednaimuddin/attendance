<?php
// PHP backend logic for shift creation and management
require 'config.php'; // Ensure this file is included

// Ensure session is started for $_SESSION access
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// No restriction to admin for this page, but we assume an admin context for shift creation
$msg = "";
$error = "";
// The fallback value, which we will now hide in the dropdown unless it exists in the DB
$admin_department = $_SESSION['user_department'] ?? 'General'; 

// GET messages
if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);
if (isset($_GET['error'])) $error = htmlspecialchars($_GET['error']);

$days_map = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];

/**
 * Converts a comma-separated string of day numbers into a comma-separated string of day names.
 */
function get_day_names($days_string, $map){
    if(empty($days_string)) return '-';
    $nums = explode(',',$days_string);
    $names = [];
    foreach($nums as $n){
        $n=(int)$n;
        if(isset($map[$n])) $names[]=$map[$n];
    }
    return htmlspecialchars(implode(', ', $names));
}

// --- START: UPDATED DEPARTMENT FETCH LOGIC ---
// Fetch all departments using UNION to get a comprehensive list from all relevant tables
$db_departments_loaded = false;
try{
    // Combine unique department names from all tables that reference a department name
    $sql_dept_union = "
        SELECT name AS department_name FROM departments
        UNION
        SELECT DISTINCT department FROM shifts
        UNION
        SELECT DISTINCT department FROM users
        ORDER BY department_name ASC
    ";
    
    $stmt_dept = $pdo->query($sql_dept_union);
    // Fetch all department names into a simple indexed array
    $departments = $stmt_dept->fetchAll(PDO::FETCH_COLUMN, 0); 
    if(!empty($departments)) {
        $db_departments_loaded = true;
    }

}catch(PDOException $e){
    // Fallback if department table is missing or query fails
    $departments = [$admin_department]; 
    error_log("DB error fetching comprehensive departments: ".$e->getMessage());
}
// --- END: UPDATED DEPARTMENT FETCH LOGIC ---


// --- DELETE shift ---
if(isset($_GET['delete_id']) && is_numeric($_GET['delete_id'])){
    $delete_id=(int)$_GET['delete_id'];
    try{
        // Use prepared statements (already done, excellent!)
        // Set users referencing this shift to NULL
        $pdo->prepare("UPDATE users SET shift_id = NULL WHERE shift_id = ?")->execute([$delete_id]);
        
        $stmt=$pdo->prepare("DELETE FROM shifts WHERE id=?");
        $stmt->execute([$delete_id]);
        
        if($stmt->rowCount()>0){
            header("Location: create_shift.php?msg=" . urlencode("Shift ID {$delete_id} deleted and unassigned from all users."));
        }else{
            header("Location: create_shift.php?error=" . urlencode("Cannot delete shift ID {$delete_id}."));
        }
        exit;
    }catch(PDOException $e){
        error_log("DB error deleting shift: ".$e->getMessage());
        header("Location: create_shift.php?error=" . urlencode("DB error occurred during deletion."));
        exit;
    }
}

// --- EDIT fetch ---
$current_shift = [];
if(isset($_GET['edit_id']) && is_numeric($_GET['edit_id'])){
    $edit_id=(int)$_GET['edit_id'];
    $stmt_shift=$pdo->prepare("SELECT * FROM shifts WHERE id=?");
    $stmt_shift->execute([$edit_id]);
    $current_shift=$stmt_shift->fetch(PDO::FETCH_ASSOC);
    if(!$current_shift) $error="Shift not found.";
}

// --- Form variables (pre-fill for POST or EDIT) ---
$shift_id = $current_shift['id'] ?? null;
$name = $current_shift['name'] ?? '';
$department = $current_shift['department'] ?? $admin_department;
$start_time = $current_shift['start_time'] ?? '';
$end_time = $current_shift['end_time'] ?? '';
$total_work_hours = $current_shift['total_work_hours'] ?? '08:00:00';
$overtime_starts_at = $current_shift['overtime_starts_at'] ?? '08:00:00';
$overtime_enabled = $current_shift['overtime_enabled'] ?? 0;
$overtime_status = $current_shift['overtime_status'] ?? '';
$shift_start_date = $current_shift['shift_start_date'] ?? date('Y-m-d');
$shift_end_date = $current_shift['shift_end_date'] ?? date('Y-m-d', strtotime('+1 year')); // Default 1 year duration
$duration_days = $current_shift['duration_days'] ?? 366;
$weekend_days_array = !empty($current_shift['weekend_days']) ? explode(',',$current_shift['weekend_days']) : [];

// Default weekend days if creating new shift
if(empty($_POST) && empty($current_shift) && empty($weekend_days_array)){
    $weekend_days_array=[6,7]; // Default to Sat/Sun
}

// Prepare the final list of departments for the dropdown.
$available_departments = $departments;

// --- CRITICAL CHANGE: Remove the fallback 'General' if departments were loaded successfully (i.e., it's not a real department) ---
if ($db_departments_loaded && ($key = array_search('General', $available_departments)) !== false && $admin_department === 'General') {
    // We only filter if the department list was actually pulled from the DB AND the shift we are editing is NOT currently set to 'General'.
    if ($department !== 'General') {
        unset($available_departments[$key]);
        $available_departments = array_values($available_departments); // Re-index array
    }
}


// Ensure the currently selected department for the shift being edited is present in the list (even if filtered out above)
if (!empty($department) && !in_array($department, $available_departments)) {
    $available_departments[] = $department;
    sort($available_departments);
}


// --- Handle POST (Create/Update) ---
if($_SERVER['REQUEST_METHOD']==='POST'){
    $shift_id=trim($_POST['shift_id'] ?? null);
    $name=trim($_POST['name'] ?? '');
    $department=trim($_POST['department'] ?? $admin_department);
    $start_time=trim($_POST['start_time'] ?? '');
    $end_time=trim($_POST['end_time'] ?? '');
    $total_work_hours=trim($_POST['total_work_hours'] ?? '08:00:00');
    $overtime_enabled = isset($_POST['overtime_enabled']) ? 1 : 0;
    $overtime_starts_at=trim($_POST['overtime_starts_at'] ?? '08:00:00');
    $overtime_status=trim($_POST['overtime_status'] ?? '');
    $shift_start_date=trim($_POST['shift_start_date'] ?? date('Y-m-d'));
    $shift_end_date=trim($_POST['shift_end_date'] ?? date('Y-m-d'));
    $weekend_days_post=$_POST['weekend_days'] ?? [];
    $weekend_days=!empty($weekend_days_post)?implode(',',array_map('intval',$weekend_days_post)):'';
    $weekend_days_array=array_map('intval',$weekend_days_post);

    // Recalculate duration based on submitted dates
    $duration_days = (strtotime($shift_end_date) - strtotime($shift_start_date))/ (60*60*24) + 1;
    $duration_days = max(0, $duration_days); // Ensure non-negative duration

    // Input Validation (essential security and usability)
    if(empty($name) || empty($department) || empty($start_time) || empty($end_time) || empty($shift_start_date) || empty($shift_end_date)){
        $error="Please fill in all required fields (Name, Department, Times, and Dates).";
    }elseif(strtotime($shift_end_date) < strtotime($shift_start_date)){
        $error="Shift End Date cannot be before Shift Start Date.";
    }else{
        try{
            if($shift_id){
                $sql="UPDATE shifts SET name=?,department=?,start_time=?,end_time=?,weekend_days=?,total_work_hours=?,overtime_enabled=?,overtime_starts_at=?,overtime_status=?,duration_days=?,shift_start_date=?,shift_end_date=? WHERE id=?";
                $stmt=$pdo->prepare($sql);
                $stmt->execute([$name,$department,$start_time,$end_time,$weekend_days,$total_work_hours,$overtime_enabled,$overtime_starts_at,$overtime_status,$duration_days,$shift_start_date,$shift_end_date,$shift_id]);
                $success_msg="Shift {$name} updated successfully! 🎉";
            }else{
                $sql="INSERT INTO shifts (name,department,start_time,end_time,weekend_days,total_work_hours,overtime_enabled,overtime_starts_at,overtime_status,duration_days,shift_start_date,shift_end_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
                $stmt=$pdo->prepare($sql);
                $stmt->execute([$name,$department,$start_time,$end_time,$weekend_days,$total_work_hours,$overtime_enabled,$overtime_starts_at,$overtime_status,$duration_days,$shift_start_date,$shift_end_date]);
                $success_msg="New shift {$name} created successfully! ✨";
            }
            header("Location: create_shift.php?msg=" . urlencode($success_msg));
            exit;
        }catch(PDOException $e){
            error_log("DB error creating/updating shift: ".$e->getMessage());
            // Important: Do NOT expose raw $e->getMessage() on a public-facing page!
            $error="Database error occurred. Please check the logs."; 
        }
    }
}

// --- Fetch all shifts for the table ---
try{
    $stmt_shifts=$pdo->query("SELECT * FROM shifts ORDER BY name ASC");
    $shifts=$stmt_shifts->fetchAll(PDO::FETCH_ASSOC);
}catch(PDOException $e){
    error_log("DB error fetching shifts: ".$e->getMessage());
    $error="Failed to load shifts from the database.";
    $shifts=[];
}

/**
 * Generates <option> tags for work hours.
 */
function generate_hour_options($start,$end,$selected_value){
    $options="";
    for($h=$start;$h<=$end;$h++){
        $value=sprintf("%02d:00:00",$h);
        $selected=($value===$selected_value)?'selected':'';
        // Note: $h is safe to output as it is a controlled integer
        $options.="<option value='{$value}' {$selected}>{$h} hours</option>"; 
    }
    return $options;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $shift_id?'Edit Shift':'Create Shift' ?> - Visionangles</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="visionnew.png">

<style>
/* ================================================= */
/* ===== TEAL/NAVY MINIMALIST THEME (REVISED) ====== */
/* ================================================= */
* { box-sizing: border-box; }
html, body { height: 100%; font-family: 'Poppins', sans-serif; }

/* Apply a clean, minimalist Teal/Navy theme regardless of dark mode setting, 
    but keep the dark mode switch structure for robustness */
html {
    /* --- LIGHT MODE (Teal Accent) --- */
    --text-color: #2c3e50; /* Navy/Dark Grey */
    --background-color: #f7f9fb; /* Light background */
    --card-bg-color: #ffffff;
    --accent-color: #1abc9c; /* Primary: Deep Teal */
    --accent-hover: #148f77; 
    --secondary-color: #3498db; /* Secondary: Brighter Blue */
    --border-color: rgba(0,0,0,0.1);
    --text-muted-color: #95a5a6;
    --shadow-light: 0 4px 15px rgba(0,0,0,0.05);
    --shadow-heavy: 0 10px 30px rgba(0,0,0,0.1);
    --error-color: #e74c3c;
    transition: all 0.5s ease;
}
html.dark-mode {
    /* --- DARK MODE (Teal Accent) --- */
    --text-color: #ecf0f1; /* Light Text */
    --background-color: #1c2833; /* Deep Navy Background */
    --card-bg-color: #2c3e50; /* Darker Navy Card */
    --accent-color: #1abc9c; /* Primary: Deep Teal */
    --accent-hover: #148f77; 
    --secondary-color: #3498db;
    --border-color: rgba(255,255,255,0.1);
    --text-muted-color: #bdc3c7;
    --shadow-light: 0 4px 15px rgba(0,0,0,0.4);
    --shadow-heavy: 0 10px 30px rgba(0,0,0,0.6);
    --error-color: #ff6b6b;
}

/* Base Body/Layout */
body { 
    background-color: var(--background-color); 
    color: var(--text-color); 
    transition: background-color 0.5s ease, color 0.5s ease; 
    padding-top: 2rem;
    padding-bottom: 2rem;
    opacity: 0;
    animation: pageLoad 1s ease-out forwards; 
}
@keyframes pageLoad {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Card and Heading */
.card { 
    background: var(--card-bg-color); 
    color: var(--text-color); 
    border-radius: 16px;
    box-shadow: var(--shadow-heavy); 
    border: 1px solid var(--border-color);
    transition: background-color 0.5s, border-color 0.5s, box-shadow 0.4s;
    animation: fadeIn 0.8s ease-out;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
h2 {
    color: var(--accent-color); /* Headings use the main accent color (Teal) */
    font-weight: 700;
    text-align: center;
    margin-bottom: 1.5rem;
    text-transform: uppercase;
    letter-spacing: 1.5px;
}
h4 {
    color: var(--text-color);
    font-weight: 600;
    margin-top: 1rem;
    margin-bottom: 1.5rem;
}

/* Company Name Header (New Element) */
.company-header {
    color: var(--accent-color); /* Use accent color for branding */
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 2rem;
    text-align: center;
    text-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    padding: 0 15px; /* Keep padding for mobile layout */
}

/* Form Elements */
.form-control, .form-select, .form-check-input {
    border-radius: 10px;
    border: 1px solid var(--border-color);
    background-color: var(--background-color); /* Lighter background for inputs */
    color: var(--text-color);
    font-weight: 500;
    transition: border-color 0.3s, box-shadow 0.3s, background-color 0.3s;
}
.form-control:focus, .form-select:focus {
    border-color: var(--accent-color);
    box-shadow: 0 0 0 0.25rem color-mix(in srgb, var(--accent-color) 40%, transparent);
    background-color: var(--card-bg-color);
}
.form-check-label {
    color: var(--text-color);
}
.form-check-input:checked {
    background-color: var(--accent-color);
    border-color: var(--accent-color);
}

/* Buttons */
.btn {
    border-radius: 10px;
    font-weight: 600;
    transition: transform 0.3s, background-color 0.3s, box-shadow 0.3s;
    border: none;
}
.btn-primary { 
    background-color: var(--accent-color); /* Main action is Teal */
    box-shadow: 0 4px 15px color-mix(in srgb, var(--accent-color) 50%, transparent); 
}
.btn-primary:hover { 
    background-color: var(--accent-hover); 
    transform: translateY(-2px);
    box-shadow: 0 8px 20px color-mix(in srgb, var(--accent-color) 60%, transparent);
}
.btn-secondary {
    background-color: var(--text-muted-color);
}
.btn-secondary:hover {
    background-color: #889495;
}
.btn-danger {
    background-color: var(--error-color);
}
.btn-danger:hover {
    background-color: #c0392b;
}

/* Alerts */
.alert {
    border-radius: 10px;
    font-weight: 500;
    border: 1px solid transparent;
}
.alert-success {
    color: #155724; /* Dark text for light mode */
    background-color: color-mix(in srgb, var(--accent-color) 85%, transparent);
    border-left: 5px solid var(--accent-color);
}
html.dark-mode .alert-success {
    color: #ccf6e4;
    background-color: color-mix(in srgb, var(--accent-color) 20%, transparent);
}
.alert-danger {
    color: #721c24; /* Dark text for light mode */
    background-color: color-mix(in srgb, var(--error-color) 85%, transparent);
    border-left: 5px solid var(--error-color);
}
html.dark-mode .alert-danger {
    color: #fddddd;
    background-color: color-mix(in srgb, var(--error-color) 20%, transparent);
}

/* Table Styling */
.table {
    --bs-table-bg: var(--card-bg-color);
    --bs-table-color: var(--text-color);
    --bs-table-border-color: var(--border-color);
}
.table th {
    color: var(--accent-color); /* Table headers use the main accent color (Teal) */
    border-bottom: 2px solid var(--border-color);
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.9rem;
}
.table-hover > tbody > tr:hover > * {
    --bs-table-accent-bg: color-mix(in srgb, var(--accent-color) 10%, transparent); 
    color: var(--text-color);
}

/* Theme Switch is hidden as requested */
.theme-switch-wrapper { display: none !important; }

/* Back Button Styling (Secondary Button) */
.back-to-dash-btn {
    background-color: #6c757d; /* Standard secondary gray */
    color: white;
    border: none;
    box-shadow: 0 4px 10px rgba(108, 117, 125, 0.3);
}
.back-to-dash-btn:hover {
    background-color: #5a6268;
    transform: translateY(-2px);
    box-shadow: 0 8px 15px rgba(108, 117, 125, 0.4);
}
html.dark-mode .back-to-dash-btn {
    background-color: #7f8c8d; /* Lighter secondary gray for dark mode */
}
html.dark-mode .back-to-dash-btn:hover {
    background-color: #95a5a6;
}

</style>
</head>
<body>

<div class="container mt-4">
    <div class="company-header">
        <a href="admin_dashboard.php" style="color: inherit; text-decoration: none;">
            <i class="fas fa-shield-alt me-2"></i>Vision Angles
        </a>
    </div>

    <div class="card p-4 mb-4">
        <h2><i class="fas fa-business-time me-2"></i><?= $shift_id?'Edit Shift':'Create New Shift' ?></h2>
        <?php if($msg) echo "<div class='alert alert-success' role='alert'><i class='fas fa-check-circle me-2'></i>$msg</div>"; ?>
        <?php if($error) echo "<div class='alert alert-danger' role='alert'><i class='fas fa-exclamation-triangle me-2'></i>$error</div>"; ?>
        <form method="post">
            <?php if($shift_id): ?><input type="hidden" name="shift_id" value="<?= htmlspecialchars($shift_id) ?>"><?php endif; ?>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Shift Name</label>
                    <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($name) ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Department</label>
                    <select class="form-select" name="department" required>
                        <?php 
                        // Using the comprehensive list of departments: $available_departments
                        foreach($available_departments as $dept){ 
                            $selected = ($dept === $department) ? 'selected' : ''; 
                            echo "<option value='" . htmlspecialchars($dept) . "' {$selected}>" . htmlspecialchars($dept) . "</option>"; 
                        } ?>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Start Time</label>
                    <input type="time" class="form-control" name="start_time" value="<?= htmlspecialchars($start_time) ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">End Time</label>
                    <input type="time" class="form-control" name="end_time" value="<?= htmlspecialchars($end_time) ?>" required>
                </div>
            </div>
            
            <hr class="text-muted my-4">

            <div class="row align-items-end">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Total Work Hours</label>
                    <select name="total_work_hours" id="total_work_hours" class="form-select" onchange="updateOvertimeOptions()">
                        <?= generate_hour_options(4,12,$total_work_hours) ?>
                    </select>
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">Enable Overtime</label>
                    <div class="form-check form-switch mt-1">
                        <input class="form-check-input" type="checkbox" id="overtime_enabled" name="overtime_enabled" role="switch" <?= $overtime_enabled ? 'checked' : '' ?> onchange="toggleOvertimeFields()">
                        <label class="form-check-label" for="overtime_enabled">Overtime Active</label>
                    </div>
                </div>

                <div class="col-md-4 mb-3 overtime-field" style="display:<?= $overtime_enabled ? 'block' : 'none' ?>;">
                    <label class="form-label">OT Starts After</label>
                    <select name="overtime_starts_at" id="overtime_starts_at" class="form-select"></select>
                </div>
            </div>

            <div class="mb-3 overtime-field" style="display:<?= $overtime_enabled ? 'block' : 'none' ?>;">
                <label class="form-label">Overtime Remarks (Optional)</label>
                <input type="text" class="form-control" name="overtime_status" value="<?= htmlspecialchars($overtime_status) ?>" placeholder="e.g., Double pay rate, approval required, etc.">
            </div>

            <hr class="text-muted my-4">

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Shift Start Date</label>
                    <input type="date" class="form-control" name="shift_start_date" id="shift_start_date" value="<?= htmlspecialchars($shift_start_date) ?>" required onchange="updateDuration()">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Shift End Date</label>
                    <input type="date" class="form-control" name="shift_end_date" id="shift_end_date" value="<?= htmlspecialchars($shift_end_date) ?>" required onchange="updateDuration()">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Shift Duration (Days)</label>
                <input type="number" class="form-control" id="duration_days" name="duration_days" value="<?= htmlspecialchars($duration_days) ?>" readonly placeholder="Calculated automatically">
            </div>

            <div class="mb-4 p-3 border rounded">
                <label class="form-label mb-2">Weekend Days <small class="text-muted">(Check all that apply)</small></label><br>
                <?php foreach($days_map as $num=>$day_name): 
                    $checked = in_array($num,$weekend_days_array)?'checked':''; ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="day-<?= $num ?>" name="weekend_days[]" value="<?= $num ?>" <?= $checked ?>>
                        <label class="form-check-label" for="day-<?= $num ?>"><?= $day_name ?></label>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="d-grid">
                <button class="btn btn-primary btn-lg"><i class="fas fa-save me-2"></i> <?= $shift_id?'Update Shift':'Create Shift' ?></button>
            </div>
        </form>
    </div>

    <div class="card p-4 mb-5">
        <h4><i class="fas fa-list-alt me-2"></i>Existing Shifts</h4>
        <div class="table-responsive">
            <table class="table table-hover mt-3 align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Dept</th>
                        <th>Hours</th>
                        <th>Weekend</th>
                        <th>OT</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($shifts): foreach($shifts as $i => $s): ?>
                    <tr style="animation-delay: <?= $i * 0.05 ?>s;">
                        <td><?= $s['id'] ?></td>
                        <td><?= htmlspecialchars($s['name']) ?></td>
                        <td><?= htmlspecialchars($s['department']) ?></td>
                        <td><?= substr($s['start_time'], 0, 5) ?> - <?= substr($s['end_time'], 0, 5) ?></td>
                        <td><?= get_day_names($s['weekend_days'],$days_map) ?></td>
                        <td><?= $s['overtime_enabled'] ? '<i class="fas fa-check-circle text-success" style="color:var(--accent-color) !important;"></i>' : '<i class="fas fa-times-circle text-danger" style="color:var(--error-color) !important;"></i>' ?></td>
                        <td class="text-center">
                            <a href="?edit_id=<?= $s['id'] ?>" class="btn btn-sm btn-primary" title="Edit Shift"><i class="fas fa-edit"></i></a>
                            <a href="?delete_id=<?= $s['id'] ?>" onclick="return confirm('Are you sure you want to delete shift ID <?= $s['id'] ?>? This will unassign all users from this shift.')" class="btn btn-sm btn-danger" title="Delete Shift"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-center text-muted">No shifts found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="text-center mb-5">
        <a href="admin_dashboard.php" class="btn btn-lg back-to-dash-btn">
            <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
        </a>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// --- THEME TOGGLE SCRIPT (Keep functional logic even if the UI element is hidden) ---
const themeToggle=document.getElementById('theme-toggle');
const htmlEl=document.documentElement;
const themeLabel = document.getElementById('theme-label');

function applyTheme(theme){
    if(theme === 'dark') {
        htmlEl.classList.add('dark-mode');
        // Note: themeToggle.checked = true; is not needed if the element is removed
        if (themeLabel) themeLabel.textContent = "Light Mode";
    } else {
        htmlEl.classList.remove('dark-mode');
        // Note: themeToggle.checked = false; is not needed if the element is removed
        if (themeLabel) themeLabel.textContent = "Dark Mode";
    }
}
// Get saved theme or check system preference on load
let savedTheme=localStorage.getItem('theme')||(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');
applyTheme(savedTheme);

// If the toggle exists (i.e. if it were re-added later)
if (themeToggle) {
    themeToggle.addEventListener('change',()=>{
        let newTheme=themeToggle.checked?'dark':'light';
        applyTheme(newTheme);
        localStorage.setItem('theme',newTheme);
    });
}

// Listen for system preference changes
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
    if (!localStorage.getItem('theme')) {
        applyTheme(e.matches ? 'dark' : 'light');
    }
});


// --- DYNAMIC OVERTIME OPTIONS ---
function updateOvertimeOptions(){
    let workHoursSelect=document.getElementById('total_work_hours');
    let overtimeSelect=document.getElementById('overtime_starts_at');
    let maxHours=parseInt(workHoursSelect.value.split(':')[0]);
    
    // PHP variable passed to JS for pre-selection in edit mode
    let currentSelectedValue='<?= $overtime_starts_at ?>'; 
    
    let options='';
    for(let h=1;h<=maxHours;h++){
        let val=`${String(h).padStart(2,'0')}:00:00`;
        let selected=(val===currentSelectedValue)?'selected':'';
        options+=`<option value="${val}" ${selected}>${h} hours</option>`;
    }
    overtimeSelect.innerHTML=options;
}
document.addEventListener('DOMContentLoaded', updateOvertimeOptions);


// --- TOGGLE OVERTIME FIELDS VISIBILITY ---
function toggleOvertimeFields(){
    let enabled=document.getElementById('overtime_enabled').checked;
    document.querySelectorAll('.overtime-field').forEach(el=>{
        el.style.display=enabled?'block':'none'; 
    });
}
document.addEventListener('DOMContentLoaded', toggleOvertimeFields);


// --- DURATION CALCULATION ---
function updateDuration(){
    let startInput=document.getElementById('shift_start_date').value;
    let endInput=document.getElementById('shift_end_date').value;

    if(!startInput || !endInput) {
        document.getElementById('duration_days').value = 1; // Default to 1 if dates are empty
        return;
    }

    let start=new Date(startInput);
    let end=new Date(endInput);
    
    // To handle timezone issues and ensure correct day difference, reset time to noon
    start.setHours(12, 0, 0, 0);
    end.setHours(12, 0, 0, 0);

    // Calculate difference in milliseconds, convert to days, and add 1 for inclusive count
    let diff=Math.floor((end.getTime() - start.getTime()) / (1000 * 60 * 60 * 24)) + 1; 
    
    // Ensure duration is not less than 1 (a shift must last at least one day)
    if(diff<1) diff=1;
    document.getElementById('duration_days').value=diff;
}
document.addEventListener('DOMContentLoaded', updateDuration);
</script>
</body>
</html>