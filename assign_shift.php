<?php
// assign_shift.php
require 'config.php';
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

// Ensure session is started and admin ID is available for exclusion
if (session_status() === PHP_SESSION_NONE) session_start();
$admin_id = $_SESSION['user_id'] ?? 0; // Get current admin ID, default to 0 if not set

$msg = "";
$error = "";

// --- FUNCTION TO FETCH ASSIGNED USERS ---
function fetch_assigned_users($pdo) {
    return $pdo->query("
        SELECT 
            u.id, 
            u.full_name, 
            s.name AS shift_name, 
            s.start_time, 
            s.end_time
        FROM 
            users u
        LEFT JOIN 
            shifts s ON u.shift_id = s.id
        ORDER BY 
            u.full_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// --- 1. Handle Delete/Unassign Action (POST Request) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user_id'])) {
    $delete_id = filter_var($_POST['delete_user_id'], FILTER_SANITIZE_NUMBER_INT);
    if ($delete_id) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET shift_id = NULL WHERE id = ?");
            $stmt->execute([$delete_id]);
            $success_msg = "User ID $delete_id has been successfully unassigned from their shift.";
            header("Location: assign_shift.php?msg=" . urlencode($success_msg));
            exit();
        } catch (PDOException $e) {
            error_log("Database error during shift removal: " . $e->getMessage());
            $error = "Failed to remove shift assignment from the database.";
        }
    } else {
        $error = "Invalid user ID provided for deletion.";
    }
}

// --- 2. Handle Shift Assignment (POST Request) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['shift_id'])) {
    $user_id = filter_var($_POST['user_id'], FILTER_SANITIZE_NUMBER_INT);
    $shift_id = filter_var($_POST['shift_id'], FILTER_SANITIZE_NUMBER_INT);

    if (empty($user_id) || empty($shift_id)) {
        $error = "Please select both a user and a shift.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET shift_id=? WHERE id=?");
            $stmt->execute([$shift_id, $user_id]);

            $user_name = $pdo->prepare("SELECT full_name FROM users WHERE id=?");
            $user_name->execute([$user_id]);
            $user_name = $user_name->fetchColumn();

            $shift_name = $pdo->prepare("SELECT name FROM shifts WHERE id=?");
            $shift_name->execute([$shift_id]);
            $shift_name = $shift_name->fetchColumn();

            $success_msg = "Shift " . htmlspecialchars($shift_name) . " assigned successfully to " . htmlspecialchars($user_name) . "! 🎉";
            header("Location: assign_shift.php?msg=" . urlencode($success_msg));
            exit();
        } catch (PDOException $e) {
            error_log("Database error during shift assignment: " . $e->getMessage());
            $error = "Failed to update shift assignment in the database.";
        }
    }
}

// --- 3. Fetch Users, Shifts, Assigned Users (MODIFIED: Exclude Admins for selection) ---
try {
    // SECURITY IMPROVEMENT: Exclude users who are admins (where is_admin = 1) from being assigned shifts.
    // If 'is_admin' column is available:
    $users_for_select = $pdo->query("SELECT id, full_name FROM users WHERE is_admin = 0 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

    // If 'is_admin' column is NOT available, a simpler approach is to exclude the currently logged-in user:
    /* $users_for_select = $pdo->prepare("SELECT id, full_name FROM users WHERE id != ? ORDER BY full_name");
    $users_for_select->execute([$admin_id]);
    $users_for_select = $users_for_select->fetchAll(PDO::FETCH_ASSOC);
    */

    $shifts = $pdo->query("SELECT id, name, start_time, end_time FROM shifts ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $assigned_users = fetch_assigned_users($pdo);
    if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);
} catch (PDOException $e) {
    error_log("Database error fetching users/shifts: " . $e->getMessage());
    $error = "Failed to load user and shift data.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Assign Shift - Visionangles</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="icon" type="image/png" href="visionnew.png">
<style>
/* ================================================= */
/* ===== TEAL/NAVY MINIMALIST THEME (Matching create_shift.php) ===== */
/* ================================================= */
:root {
    /* --- LIGHT MODE (Teal Accent) --- */
    --text-color: #2c3e50; /* Navy/Dark Grey */
    --bg-primary: #f7f9fb; /* Light background */
    --card-bg: #ffffff;
    --muted-text-color: #95a5a6;
    --main-color: #1abc9c; /* Primary: Deep Teal */
    --main-hover: #148f77; 
    --accent-color: #3498db; /* Secondary: Brighter Blue */
    --accent-hover: #2980b9; 
    --border-color: rgba(0,0,0,0.1);
    --form-input-bg: #f8f9fa;
    --error-color: #e74c3c;
    --success-color: #1abc9c; /* Deep Teal for primary action/success/shift badge */
    --shadow: 0 10px 30px rgba(0,0,0,0.08);
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
    --accent-color: #3498db; 
    --accent-hover: #2980b9;
    --border-color: rgba(255, 255, 255, 0.1);
    --form-input-bg: #1f2a3a;
    --error-color: #ff6b6b;
    --success-color: #1abc9c; /* Deep Teal */
    --shadow: 0 10px 30px rgba(0,0,0,0.4);
}

/* --- GENERAL STYLES & ANIMATIONS --- */
body { 
    font-family: 'Poppins', sans-serif; 
    background-color: var(--bg-primary); 
    color: var(--text-color); 
    display: flex; 
    flex-direction: column; 
    justify-content: flex-start; 
    align-items: center; 
    min-height: 100vh; 
    padding: 3rem 0; 
    margin: 0; 
    transition: background-color 0.5s ease, color 0.5s ease;
}
.form-container, .shift-list-container { 
    width: 90%; 
    padding: 3rem; 
    background-color: var(--card-bg); 
    border: 1px solid var(--border-color); 
    border-radius: 15px; 
    box-shadow: var(--shadow); 
    margin-bottom: 2rem; 
    transition: all 0.5s ease;
    animation: fadeIn 0.6s ease-out;
}
.form-container { max-width: 500px; }
.shift-list-container { max-width: 800px; padding: 2rem; }
.form-container h2, .shift-list-container h2 { 
    text-align: center; 
    color: var(--main-color);
    font-weight: 700; 
    margin-bottom: 2rem; 
    text-transform: uppercase;
    transition: color 0.5s;
}

/* --- COMPANY HEADER STYLING --- */
.company-header {
    color: var(--main-color);
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

/* --- FORM STYLING --- */
.input-group-custom { margin-bottom: 1.5rem; position: relative; }
.input-group-custom label { font-weight: 500; color: var(--text-color); margin-bottom: 0.5rem; display: block; transition: color 0.5s ease; }
.input-group-custom .form-select { 
    width: 100%; height: 52px; 
    padding: 10px 18px 10px 50px; 
    border-radius: 10px; 
    border: 2px solid var(--border-color); 
    background: var(--form-input-bg); 
    color: var(--text-color); 
    font-size: 1.05rem; 
    outline: none; 
    transition: border-color 0.4s, box-shadow 0.4s;
    /* Custom chevron icon, using MUTED color for default */
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%2395a5a6' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e"); 
    background-repeat: no-repeat; 
    background-position: right 1rem center; 
    background-size: 16px 12px;
}
html.dark-mode .input-group-custom .form-select { 
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23bdc3c7' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e"); 
}
.input-group-custom .form-select:focus { 
    border-color: var(--main-color); 
    box-shadow: 0 0 10px var(--main-color)40; 
    background-color: var(--card-bg);
}
.input-group-custom i { 
    position: absolute; 
    left: 18px; 
    bottom: 14px; 
    color: var(--muted-text-color); 
    transition: color 0.3s ease, transform 0.3s ease; 
    z-index: 11;
}
.input-group-custom .form-select:focus ~ i {
    color: var(--main-color); 
    transform: scale(1.1);
}

/* --- BUTTONS --- */
.btn-primary-accent { 
    background-color: var(--success-color); /* Deep Teal */
    border-color: var(--success-color); 
    color: white; 
    font-weight: 600; 
    border-radius: 10px; 
    padding: 0.75rem 1.5rem; 
    transition: background-color 0.3s, transform 0.3s, box-shadow 0.3s;
}
.btn-primary-accent:hover { 
    background-color: var(--main-hover); 
    border-color: var(--main-hover); 
    transform: translateY(-2px); 
    box-shadow: 0 6px 20px var(--success-color)50; 
}
.btn-outline-secondary { 
    background-color: transparent; 
    border: 1px solid var(--border-color);
    color: var(--text-color); 
    font-weight: 500; 
    border-radius: 10px; 
    padding: 0.75rem 1.5rem;
    transition: background-color 0.3s, color 0.3s, border-color 0.3s;
}
.btn-outline-secondary:hover { 
    color: var(--main-color); 
    border-color: var(--main-color); 
    background-color: var(--card-bg);
}
.btn-delete-custom { 
    background-color: var(--error-color); 
    border-color: var(--error-color); 
    color: white; 
    font-size: 0.85rem; 
    padding: 0.4rem 0.8rem; 
    border-radius: 5px; 
    transition: background-color 0.3s, transform 0.2s; 
}
.btn-delete-custom:hover { 
    background-color: #c0392b; 
    border-color: #c0392b; 
    transform: scale(1.05); 
}

/* --- TABLE STYLING --- */
.table-custom { 
    color: var(--text-color); 
    margin-bottom: 0; 
    border-radius: 10px; 
    overflow: hidden; 
    border: 1px solid var(--border-color); 
    background-color: var(--card-bg);
}
.table-custom thead { 
    background-color: var(--main-color); /* Deep Teal Header */
    color: white; 
    transition: background-color 0.5s;
}
.table-custom th { 
    font-weight: 600; 
    border: none; 
    padding: 1rem 1rem; 
    text-transform: uppercase; 
    font-size: 0.9rem; 
}
.table-custom td { 
    border-top: 1px solid var(--border-color); 
    border-bottom: none; 
    padding: 0.75rem 1rem; 
    font-weight: 500; 
    transition: background-color 0.3s;
}
.table-custom tbody tr:hover { background-color: var(--main-color)10; }
.table-custom tbody tr {
    opacity: 0;
    animation: slideInFromLeft 0.5s ease-out forwards;
}

/* --- THEME SWITCH (RELOCATED & STYLED) --- */
.theme-switch-wrapper { 
    position: fixed; 
    top: 1.5rem; /* Pushed up */
    right: 1.5rem; /* Pushed right */
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

/* --- ALERTS & BADGES --- */
.alert { 
    border-radius: 8px; 
    font-weight: 500;
    animation: fadeIn 0.4s ease-out;
    border-left: 5px solid;
}
.alert-success { 
    background-color: color-mix(in srgb, var(--success-color) 90%, var(--bg-primary)); 
    border-color: var(--success-color); 
    color: var(--text-color); 
    border-left-color: var(--success-color);
}
html.dark-mode .alert-success {
    background-color: color-mix(in srgb, var(--success-color) 20%, transparent);
    color: var(--text-color);
}
.alert-danger { 
    background-color: color-mix(in srgb, var(--error-color) 90%, var(--bg-primary)); 
    border-color: var(--error-color); 
    color: var(--text-color); 
    border-left-color: var(--error-color);
}
html.dark-mode .alert-danger {
    background-color: color-mix(in srgb, var(--error-color) 20%, transparent);
    color: var(--text-color);
}

.badge.bg-success { background-color: var(--success-color) !important; color: white; font-weight: 500; }
.badge-secondary-custom { background-color: var(--muted-text-color); color: var(--bg-primary); font-weight: 500; }

/* --- KEYFRAMES --- */
@keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes slideInFromLeft { from { opacity: 0; transform: translateX(-20px); } to { opacity: 1; transform: translateX(0); } }

/* Media Queries */
@media (max-width: 768px) { .shift-list-container { padding: 1.5rem; } .table-responsive { border: none !important; } }
@media (max-width: 576px) { .form-container { padding: 1.5rem; } }
</style>
</head>
<body>
<?php 
$delay = 0.05; 
$count = 0;
if ($assigned_users) {
    foreach($assigned_users as $user) {
        echo "<style>.table-custom tbody tr:nth-child(".($count+1).") { animation-delay: " . ($delay * $count) . "s; }</style>\n";
        $count++;
    }
}
?>

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

<div class="form-container">
    <h2><i class="fas fa-user-clock me-2"></i> Assign Shift</h2>
    <?php 
    if($msg) echo "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>" . htmlspecialchars($msg) . "</div>"; 
    if($error) echo "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-2'></i>" . htmlspecialchars($error) . "</div>";
    ?>
    <form method="post">
        <div class="input-group-custom mb-3">
            <label for="user_id">Select User</label>
            <select id="user_id" name="user_id" class="form-select" required>
                <option value="">-- Select Employee (Non-Admin) --</option>
                <?php foreach($users_for_select as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <i class="fas fa-users"></i>
        </div>
        <div class="input-group-custom mb-4">
            <label for="shift_id">Select Shift</label>
            <select id="shift_id" name="shift_id" class="form-select" required>
                <option value="">-- Select Shift Schedule --</option>
                <?php foreach($shifts as $s): ?>
                    <option value="<?= $s['id'] ?>">
                        <?= htmlspecialchars($s['name']) ?> (<?= date('H:i', strtotime($s['start_time'])) ?> - <?= date('H:i', strtotime($s['end_time'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <i class="fas fa-calendar-alt"></i>
        </div>
        <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary-accent"><i class="fas fa-save me-2"></i> Confirm Assignment</button>
            <a href="admin_dashboard.php" class="btn btn-outline-secondary mt-2"><i class="fas fa-arrow-left me-2"></i> Back to Dashboard</a>
        </div>
    </form>
</div>

<div class="shift-list-container">
    <h2><i class="fas fa-list-alt me-2"></i> Current Shift Assignments</h2>
    <?php if(empty($assigned_users)): ?>
        <p class="text-center text-muted">No users found or a database error occurred.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-custom align-middle">
            <thead>
                <tr>
                    <th>Employee Name</th>
                    <th>Shift</th>
                    <th>Time</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($assigned_users as $user): ?>
                <tr>
                    <td><i class="fas fa-user me-2"></i><?= htmlspecialchars($user['full_name']) ?></td>
                    <td>
                        <?php if($user['shift_name']): ?>
                            <span class="badge rounded-pill bg-success"><?= htmlspecialchars($user['shift_name']) ?></span>
                        <?php else: ?>
                            <span class="badge rounded-pill badge-secondary-custom">Unassigned</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($user['start_time']): ?>
                            <?= date('H:i', strtotime($user['start_time'])) ?> - <?= date('H:i', strtotime($user['end_time'])) ?>
                        <?php else: ?> N/A <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if($user['shift_name']): ?>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="delete_user_id" value="<?= $user['id'] ?>">
                            <button class="btn btn-delete-custom" title="Unassign <?= htmlspecialchars($user['full_name']) ?>" onclick="return confirm('Are you sure you want to UNASSIGN <?= addslashes($user['full_name']) ?> from their shift?');"><i class="fas fa-trash-alt"></i></button>
                        </form>
                        <?php else: ?> - <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Theme Toggle Script
const toggleSwitch = document.getElementById('theme-toggle');
// Check for saved theme preference or system preference
const currentTheme = localStorage.getItem('theme') ? localStorage.getItem('theme') : 
    (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

// Apply theme on load
if (currentTheme === 'dark') {
    document.documentElement.classList.add('dark-mode');
    toggleSwitch.checked = true;
} else {
     document.documentElement.classList.remove('dark-mode');
    toggleSwitch.checked = false;
}

// Event listener for theme change
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