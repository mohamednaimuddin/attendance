<?php
// PHP backend logic for Add User processing
require 'config.php';
require_admin(); // Ensures only logged-in admins can access this page

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$msg = "";
$error = "";

// --- DYNAMICALLY LOAD DEPARTMENTS FROM DATABASE ---
$departments_list = [];
try {
    $stmt_dept = $pdo->query("SELECT name FROM departments ORDER BY name ASC");
    // Fetch departments as a simple indexed array of names for validation
    $departments_list = $stmt_dept->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Critical error: cannot fetch departments
    error_log("Database error fetching departments: " . $e->getMessage());
    $error = "CRITICAL: Could not load department list from the database.";
}
// ----------------------------------------------------

// Initialize variables for sticky form fields
$username = '';
$full_name = '';
$email = '';
$mobile_number = '';
$department = ''; // Will hold the selected department
$is_admin = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    // 1. Input Sanitization/Validation
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $mobile_number = trim($_POST['mobile_number']);
    $department = trim($_POST['department']); // Get the selected department from the dropdown
    // Sanitize the boolean value correctly
    $is_admin = isset($_POST['is_admin']) ? 1 : 0;

    // Basic Input Checks
    if (empty($username) || empty($password) || empty($full_name) || empty($email) || empty($department)) {
        $error = "All fields except Mobile Number are required.";
    } elseif (!in_array($department, $departments_list)) { // Validate Department selection against the DYNAMIC list
        $error = "The selected department is invalid or does not exist in the database.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { // Validate email format
        $error = "The email address is not valid.";
    } elseif (strlen($password) < 8) { // Basic password length check
        $error = "Password must be at least 8 characters long.";
    } elseif (!empty($mobile_number) && !preg_match('/^\+?\d[\d\s-]{7,15}\d$/', $mobile_number)) { 
        // Simple validation for international format: optional +, then 8-16 digits/spaces/hyphens
        $error = "The mobile number format is invalid.";
    } else {
        // 2. Check if username OR email already exists (Security check)
        try {
            // Check for existing username OR email using a single query
            $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            
            if ($stmt->rowCount() > 0) {
                // Determine which one exists for a slightly better error message
                $existing_user = $stmt->fetch(PDO::FETCH_ASSOC);
                $error_detail = "";
                if ($existing_user['username'] == $username) $error_detail = "Username";
                if ($existing_user['email'] == $email) $error_detail .= ($error_detail ? " and Email" : "Email");
                $error = $error_detail . " already exists. Please choose another.";
            } else {
                // 3. Hash Password (Security: Using password_hash)
                $hash = password_hash($password, PASSWORD_DEFAULT);
                
                // 4. Insert New User (Security: Using prepared statements)
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, email, mobile_number, department, is_admin) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hash, $full_name, $email, $mobile_number, $department, $is_admin]);
                $msg = "User **$full_name** added successfully! 🎉";
                
                // Optional: Clear form variables after success
                $username = $full_name = $email = $mobile_number = $department = '';
                $is_admin = 0;
            }
        } catch (PDOException $e) {
            error_log("Database error during user addition: " . $e->getMessage());
            $error = "An unexpected error occurred during account creation. Please check the database structure.";
        }
    }
}

// Set $is_admin and $department back to the POST value if there was an error, so sticky fields work
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error) {
    $is_admin = isset($_POST['is_admin']) ? 1 : 0;
    $department = $_POST['department'] ?? '';
}
// ===================================================
// --- DATABASE COUNTS (CORRECTIONS & RESETS) ---
// ===================================================

$pending_resets = 0;
$pending_corrections = 0;

// ✅ Ensure both mysqli and PDO support (depending on config.php)
if (isset($conn) && $conn instanceof mysqli) {
    // --- Using mysqli ---
    try {
        // Pending password reset requests
        $sql_resets = "SELECT COUNT(*) AS total FROM password_reset_requests WHERE status = 'PENDING'";
        $res_resets = mysqli_query($conn, $sql_resets);
        if ($res_resets && $row = mysqli_fetch_assoc($res_resets)) {
            $pending_resets = (int)$row['total'];
        }

        // Pending correction requests
        $sql_corrections = "SELECT COUNT(*) AS total FROM correction_requests WHERE status = 'PENDING'";
        $res_corrections = mysqli_query($conn, $sql_corrections);
        if ($res_corrections && $row = mysqli_fetch_assoc($res_corrections)) {
            $pending_corrections = (int)$row['total'];
        }
    } catch (Exception $e) {
        error_log("MySQLi DB Error: " . $e->getMessage());
    }
} elseif (isset($pdo) && $pdo instanceof PDO) {
    // --- Using PDO ---
    try {
        $stmt1 = $pdo->query("SELECT COUNT(*) FROM password_reset_requests WHERE status = 'PENDING'");
        $pending_resets = (int)$stmt1->fetchColumn();

        $stmt2 = $pdo->query("SELECT COUNT(*) FROM correction_requests WHERE status = 'PENDING'");
        $pending_corrections = (int)$stmt2->fetchColumn();
    } catch (PDOException $e) {
        error_log("PDO DB Error: " . $e->getMessage());
    }
} else {
    error_log("❌ No valid DB connection found in config.php");
}

// ===================================================
// --- ADMIN NAME & TOTAL ALERTS ---
// ===================================================
$admin_name = $_SESSION['full_name'] ?? 'Admin';

// Count all active alerts that are not dismissed
$total_alerts = 0;
if ($pending_corrections > 0 && !$corrections_dismissed) {
    $total_alerts += $pending_corrections;
}
if ($pending_resets > 0 && !$resets_dismissed) {
    $total_alerts += $pending_resets;
}
// ===================================================
// --- MOBILE HEADER CONTENT ---
// ===================================================
$mobile_footer_content = '
<div class="dropdown d-inline-block d-lg-none ms-2">
    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Settings">
        <i class="fa-solid fa-gear"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li class="px-3 py-2">
            <span class="welcome-text text-dark d-block">Welcome, ' . htmlspecialchars($admin_name) . '</span>
        </li>
        <li class="dropdown-divider"></li>
        <li class="px-3 py-1">
            <a href="logout.php" class="btn btn-sm btn-danger w-100">
                <i class="fa-solid fa-right-from-bracket me-2"></i> Logout
            </a>
        </li>
    </ul>
</div>
';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add User - Visionangles</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="icon" type="image/png" href="visionnew.png">
<link rel="stylesheet" href="assets/css/add_user.css">
<link rel="stylesheet" href="assets/css/admin_dashboard.css">
</head>
<body>
    <aside class="sidebar d-print-none d-lg-flex">
    <div class="sidebar-header">
        <i class="fas fa-cubes me-2"></i>Vision Angles
    </div>

    <nav class="sidebar-nav">
        <a href="admin_dashboard.php" class="nav-link ">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
        
        <a href="attendance_requests.php" class="nav-link">
            <i class="fa-solid fa-clock-rotate-left" style="color: var(--warning-color);"></i> Correction Requests 
            <?php 
            if ($pending_corrections > 0 && !$corrections_dismissed): 
            ?>
                <span class="badge bg-warning rounded-pill ms-1 text-dark"><?= $pending_corrections ?></span>
            <?php endif; ?>
        </a>
        
        <a href="reset_requests.php" class="nav-link">
            <i class="fa-solid fa-key" style="color: var(--error-color);"></i> Password Requests 
            <?php 
            if ($pending_resets > 0 && !$resets_dismissed): 
            ?>
                <span class="badge bg-danger rounded-pill ms-1"><?= $pending_resets ?></span>
            <?php endif; ?>
        </a>


        <span class="sidebar-title">User Management</span>
        <a href="manage_user.php" class="nav-link">
            <i class="fa-solid fa-users-gear"></i> Manage Users
        </a>
        <a href="add_user.php" class="nav-link active">
            <i class="fa-solid fa-user-plus"></i> Add New User
        </a>
        <a href="manage_department.php" class="nav-link">
            <i class="fa-solid fa-sitemap"></i> Manage Department
        </a>

        <span class="sidebar-title">Reporting & Logs</span>
        <a href="attendance_report.php" class="nav-link">
            <i class="fa-solid fa-chart-line"></i> Attendance Reports
        </a>
        <a href="logs.php" class="nav-link">
            <i class="fa-solid fa-bug"></i> Activity Logs
        </a>
    </nav>

    <div class="sidebar-footer d-none d-lg-block"> 
        <span class="welcome-text"> <?= htmlspecialchars($admin_name) ?></span>
        
        <a href="logout.php" class="btn logout-btn-footer">
            <i class="fa-solid fa-right-from-bracket me-2"></i> Logout
        </a>
    </div>
</aside>

<div class="main-container">
<div class="content-wrapper">
<div class="form-container">
    <h2><i class="fas fa-user-plus me-2"></i> Add New System User</h2>
    <?php 
    // Ensure HTML special characters are handled and markdown bolding is removed from $msg
    if($msg) echo "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>" . htmlspecialchars(str_replace('**', '', $msg)) . "</div>"; 
    if($error) echo "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-2'></i>" . htmlspecialchars($error) . "</div>";
    ?>
    <form method="post">
        
        <div class="input-group-custom">
            <input type="text" name="username" placeholder="Username" class="form-control" required autocomplete="off" value="<?= htmlspecialchars($username) ?>">
            <i class="fas fa-user"></i>
        </div>
        
        <div class="input-group-custom">
            <input type="password" name="password" placeholder="Password (Min 8 characters)" class="form-control" required autocomplete="new-password">
            <i class="fas fa-lock"></i>
        </div>
        
        <div class="input-group-custom">
            <input type="text" name="full_name" placeholder="Full Name" class="form-control" required value="<?= htmlspecialchars($full_name) ?>">
            <i class="fas fa-signature"></i>
        </div>
        
        <div class="input-group-custom">
            <input type="email" name="email" placeholder="Email Address (Required for Password Reset)" class="form-control" required value="<?= htmlspecialchars($email) ?>">
            <i class="fas fa-envelope"></i>
        </div>

        <div class="input-group-custom">
            <input type="tel" name="mobile_number" placeholder="Mobile Number (Optional)" class="form-control" value="<?= htmlspecialchars($mobile_number) ?>">
            <i class="fas fa-phone"></i>
        </div>
        
        <div class="input-group-custom">
            <select name="department" class="form-control" required>
                <option value="" disabled <?= (empty($department) && !$error) ? 'selected' : '' ?>>Select Department/Role</option>
                <?php foreach ($departments_list as $dept): ?>
                    <option value="<?= htmlspecialchars($dept) ?>" <?= ($department === $dept) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dept) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <i class="fas fa-briefcase"></i>
        </div>
        
        <div class="form-check">
            <input type="checkbox" name="is_admin" id="isAdmin" class="form-check-input" <?= $is_admin ? 'checked' : '' ?>>
            <label class="form-check-label ms-3" for="isAdmin">
                <i class="fas fa-shield-alt me-1"></i> Grant Administrative Privileges
            </label>
        </div>
        
        <div class="d-grid gap-2">
            <button class="btn btn-primary-accent" type="submit">
                <i class="fas fa-save me-2"></i> Create Account
            </button>
        </div>
    </form>
</div>
</div>
</div><!-- End main-container -->

</body>
</html>