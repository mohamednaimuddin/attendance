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
<style>
/* ================================================= */
/* ===== SLEEK MINIMALIST THEME (TEAL/NAVY) - Applied from admin_dashboard.php ===== */
/* ================================================= */

/* ===== Global Variables (Light Mode Default) ===== */
:root {
    --text-color: #34495e; /* Corporate Navy Text */
    --bg-primary: #f4f7fa; /* Very Light Background */
    --card-bg: #ffffff;
    --accent-color: #009688; /* Primary Teal Accent */
    --accent-hover: #00796b;
    --error-color: #e74c3c;
    --success-color: #2ecc71; /* Brighter Green for Success */
    --border-color: rgba(0, 0, 0, 0.08);
    --shadow-light: 0 4px 12px rgba(0,0,0,0.05);
    --shadow-hover: 0 8px 16px rgba(0,0,0,0.15); 
    --nav-bg: #ffffff;
    --text-muted-color: #95a5a6; /* Silver/Gray */
    --form-input-bg: #f8f9fa; /* Light background for inputs */
    transition: all 0.5s ease;
}

/* ===== Dark Mode Variables (Teal/Navy Dark) ===== */
html.dark-mode {
    --text-color: #ecf0f1; 
    --bg-primary: #1b2029; /* Deep Slate Background */
    --card-bg: #2d3846; /* Darker Card Background */
    --accent-color: #4db6ac; /* Lighter Teal Accent */
    --accent-hover: #26a69a;
    --error-color: #ff6b6b;
    --success-color: #48c9b0; /* Lighter Teal Green */
    --border-color: rgba(255, 255, 255, 0.1);
    --shadow-light: 0 4px 12px rgba(0,0,0,0.3);
    --shadow-hover: 0 8px 20px rgba(0,0,0,0.5); 
    --nav-bg: #2d3846;
    --text-muted-color: #bdc3c7;
    --form-input-bg: rgba(255, 255, 255, 0.08); /* Dark background for inputs */
}

body {
    font-family: 'Inter', sans-serif;
    background-color: var(--bg-primary);
    color: var(--text-color);
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100vh;
    margin: 0;
    transition: background-color 0.5s ease, color 0.5s ease;
}

/* ===== Form Container and Card Styling (UPDATED SHADOWS/COLORS) ===== */
.form-container {
    max-width: 500px;
    width: 90%;
    padding: 2.5rem; 
    background-color: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px; 
    box-shadow: var(--shadow-light);
    transition: all 0.5s ease;
}

.form-container h2 {
    text-align: center;
    color: var(--accent-color); /* Teal Accent */
    font-weight: 800;
    margin-bottom: 2rem;
    letter-spacing: -0.5px;
    transition: color 0.5s ease;
}

/* ===== Input Group Styling (Teal Focus) ===== */
.input-group-custom {
    margin-bottom: 1.5rem;
    position: relative;
}
.input-group-custom .form-control, 
.input-group-custom select.form-control {
    appearance: none; 
    width: 100%;
    padding: 14px 18px;
    padding-left: 50px; 
    border-radius: 8px; 
    border: 1px solid var(--border-color);
    background: var(--form-input-bg);
    color: var(--text-color);
    font-size: 1rem;
    outline: none;
    transition: border-color 0.4s, box-shadow 0.4s, background 0.5s;
    
    /* Custom Arrow for Select (Light Mode) */
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%2334495e' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 1.25rem center;
    background-size: 10px;
}
html.dark-mode .input-group-custom select.form-control {
    /* Custom Arrow for Select (Dark Mode) */
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23ecf0f1' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
}
.input-group-custom .form-control::placeholder {
    color: var(--text-muted-color);
}
.input-group-custom .form-control:focus {
    border-color: var(--accent-color);
    box-shadow: 0 0 8px rgba(0, 150, 136, 0.3); /* Teal glow */
    background-color: var(--card-bg); 
}
html.dark-mode .input-group-custom .form-control:focus {
     box-shadow: 0 0 8px rgba(77, 182, 172, 0.4); /* Dark mode Teal glow */
}

.input-group-custom i {
    position: absolute;
    left: 18px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted-color);
    transition: color 0.3s ease;
    z-index: 10;
}
.input-group-custom .form-control:focus + i {
    color: var(--accent-color);
}

/* ===== Checkbox Styling (Refined) ===== */
.form-check {
    margin-bottom: 1.5rem;
    padding-left: 0;
    display: flex;
    align-items: center;
    color: var(--text-color);
}
.form-check-input {
    width: 1.25em;
    height: 1.25em;
    margin-right: 0.75rem;
    background-color: var(--form-input-bg);
    border: 1px solid var(--border-color);
    transition: background-color 0.3s, border-color 0.3s;
}
.form-check-input:checked {
    background-color: var(--accent-color);
    border-color: var(--accent-color);
}

/* ===== Button Styling (Teal Accent) ===== */
.btn-primary-accent {
    background-color: var(--accent-color) !important;
    border-color: var(--accent-color) !important;
    color: white; 
    font-weight: 600;
    border-radius: 8px;
    padding: 0.75rem 1.5rem;
    transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275), background-color 0.3s, box-shadow 0.3s;
}

.btn-primary-accent:hover {
    background-color: var(--accent-hover) !important;
    border-color: var(--accent-hover) !important;
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0, 150, 136, 0.3); /* Teal glow on hover */
    color: white; 
}

.btn-outline-secondary {
    background-color: var(--card-bg);
    border: 1px solid var(--border-color);
    color: var(--text-color);
    font-weight: 500;
    border-radius: 8px;
    padding: 0.75rem 1.5rem;
    transition: color 0.3s, border-color 0.3s, background-color 0.3s, transform 0.3s ease;
}

.btn-outline-secondary:hover {
    color: var(--accent-color);
    border-color: var(--accent-color);
    transform: translateY(-1px);
}
.btn-outline-secondary:focus {
    box-shadow: 0 0 0 0.25rem rgba(0, 150, 136, 0.25);
}

/* ===== Alert Messages (Teal/Navy style) ===== */
.alert {
    border-radius: 8px;
    font-weight: 500;
    border: 1px solid;
    padding: 12px 20px;
}
.alert-success {
    background-color: color-mix(in srgb, var(--success-color) 15%, transparent); 
    border-color: var(--success-color);
    color: var(--text-color);
}
.alert-danger {
    background-color: color-mix(in srgb, var(--error-color) 15%, transparent); 
    border-color: var(--error-color);
    color: var(--text-color);
}


/* ===== Theme Toggle Switch Styling (Consistent) ===== */
.theme-switch-wrapper {
    position: absolute; 
    top: 1.5rem; 
    right: 1.5rem; 
    display: flex;
    align-items: center;
    z-index: 10;
}
.theme-switch-wrapper em {
    margin-right: 10px;
    font-size: 0.9rem;
    font-style: normal;
    color: var(--text-color);
    font-weight: 600;
    transition: color 0.5s ease;
    display: none; 
}
.theme-switch {
    height: 30px;
    position: relative;
    width: 56px;
}
.theme-switch input { display:none; }
.slider {
    background-color: var(--text-muted-color);
    bottom: 0;
    cursor: pointer;
    left: 0;
    position: absolute;
    right: 0;
    top: 0;
    transition: .4s;
    border-radius: 34px;
}
.slider:before {
    background-color: #fff;
    bottom: 3px;
    content: "";
    height: 24px;
    left: 3px;
    position: absolute;
    transition: .4s;
    width: 24px;
    border-radius: 50%;
}
input:checked + .slider { background-color: var(--accent-color); } /* Teal */
input:checked + .slider:before { transform: translateX(26px); }

@media (max-width: 576px) {
    .form-container { 
        padding: 1.5rem; 
        margin-top: 5rem; /* Give space for the toggle */
        height: auto;
    }
    body {
        align-items: flex-start; /* Start content from the top */
        height: auto;
        min-height: 100vh;
    }
    .theme-switch-wrapper { top: 0.75rem; right: 0.75rem; }
}
</style>
</head>
<body>
<div class="theme-switch-wrapper">
    <em>Dark Mode</em>
    <label class="theme-switch" for="theme-toggle">
        <input type="checkbox" id="theme-toggle">
        <div class="slider round"></div>
    </label>
</div>

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
            <a href="admin_dashboard.php" class="btn btn-outline-secondary mt-2">
                <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
            </a>
        </div>
    </form>
</div>

<script>
    // JavaScript for theme toggling (Consistent)
    const themeToggle = document.getElementById('theme-toggle');
    const body = document.documentElement;

    function applyTheme(theme) {
        if (theme === 'dark') {
            body.classList.add('dark-mode');
            themeToggle.checked = true;
        } else {
            body.classList.remove('dark-mode');
            themeToggle.checked = false;
        }
    }

    // Set initial theme based on localStorage or OS preference
    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

    const initialTheme = savedTheme || (prefersDark ? 'dark' : 'light');
    applyTheme(initialTheme);

    // Add event listener for the toggle switch
    themeToggle.addEventListener('change', () => {
        const newTheme = themeToggle.checked ? 'dark' : 'light';
        applyTheme(newTheme);
        localStorage.setItem('theme', newTheme);
    });
</script>

</body>
</html>