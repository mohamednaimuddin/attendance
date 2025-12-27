<?php
require 'config.php';
// Ensure only admins can access this page
require_admin();

$msg = "";
$error = "";

// Get user ID and ensure it's a valid integer
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if(!$id) {
    // Use session message for a cleaner redirect
    $_SESSION['error_msg'] = "User ID not specified.";
    header("Location: manage_user.php");
    exit;
}

// Fetch user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if(!$user){
    $_SESSION['error_msg'] = "User not found.";
    header("Location: manage_user.php");
    exit;
}

// ----------------------------------------------------------------------
// --- UPDATED DYNAMIC DEPARTMENT LOADING (From dedicated 'departments' table) ---
// Fetch all department names from the dedicated 'departments' table.
// This ensures all available departments are displayed, even if no user is currently assigned.
$stmt_departments = $pdo->query("SELECT name FROM departments ORDER BY name");
$departments = $stmt_departments->fetchAll(PDO::FETCH_COLUMN);
// --- END UPDATED DYNAMIC DEPARTMENT LOADING ---
// ----------------------------------------------------------------------


// Generate a CSRF token for the session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        error_log("CSRF token validation failed on edit_user.php for user ID: " . $id);
        $error = "Security check failed. Please refresh and try again.";
        // IMPORTANT: We skip processing the rest of the POST request if CSRF fails
    } else {

        // Input sanitization and collection
        $username = trim($_POST['username']);
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $mobile_number = trim($_POST['mobile_number']);
        $department = trim($_POST['department']); 
        $password = $_POST['password'];
        
        // Admin check logic
        if ($id == $_SESSION['user_id'] && !isset($_POST['is_admin'])) {
            $is_admin = 1; // Prevent admin from demoting themselves
            if (empty($error)) {
                $error = "You cannot revoke your own admin rights.";
            }
        } else {
            $is_admin = isset($_POST['is_admin']) ? 1 : 0;
        }
        
        // Basic validation
        if (empty($error) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format.";
        }
        
        // Optional: Basic Mobile Number Validation (allows empty string)
        if (empty($error) && !empty($mobile_number) && !preg_match('/^\+?\d[\d\s-]{7,15}\d$/', $mobile_number)) {
            $error = "Invalid mobile number format. Please use only numbers, spaces, and hyphens.";
        }
        
        // VALIDATION: Department check logic is slightly complex here, but we will allow 
        // a submitted department to update the list, so no strict validation needed beyond presence.
        
        // Check duplicate username, excluding the current user
        if (empty($error)) {
            $stmt_check_username = $pdo->prepare("SELECT id FROM users WHERE username=? AND id<>?");
            $stmt_check_username->execute([$username, $id]);
            if($stmt_check_username->rowCount() > 0){
                $error = "Username already exists. Choose another.";
            }
        }
        
        // Check duplicate email, excluding the current user
        if (empty($error)) {
            $stmt_check_email = $pdo->prepare("SELECT id FROM users WHERE email=? AND id<>?");
            $stmt_check_email->execute([$email, $id]);
            if($stmt_check_email->rowCount() > 0){
                $error = "Email address already in use by another user.";
            }
        }
        
        // Check duplicate mobile number, excluding the current user (if mobile number is provided)
        if (empty($error) && !empty($mobile_number)) {
            $stmt_check_mobile = $pdo->prepare("SELECT id FROM users WHERE mobile_number=? AND id<>?");
            $stmt_check_mobile->execute([$mobile_number, $id]);
            if($stmt_check_mobile->rowCount() > 0){
                $error = "Mobile number is already registered to another user.";
            }
        }


        if (empty($error)) {
            try {
                if(!empty($password)){
                    // Hash new password
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    
                    // UPDATE query with password change
                    $sql = "UPDATE users SET username=?, full_name=?, email=?, mobile_number=?, department=?, is_admin=?, password_hash=? WHERE id=?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$username, $full_name, $email, $mobile_number, $department, $is_admin, $hash, $id]);
                } else {
                    // UPDATE query without password change
                    $sql = "UPDATE users SET username=?, full_name=?, email=?, mobile_number=?, department=?, is_admin=? WHERE id=?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$username, $full_name, $email, $mobile_number, $department, $is_admin, $id]);
                }
                
                $msg = "User " . htmlspecialchars($username) . " updated successfully! 🎉";
                
                // Refresh user data (crucial to display latest changes in the form fields)
                $stmt_refresh = $pdo->prepare("SELECT * FROM users WHERE id=?");
                $stmt_refresh->execute([$id]);
                $user = $stmt_refresh->fetch();

                // Re-fetch department list from the dedicated table (no change needed here)
                $stmt_departments = $pdo->query("SELECT name FROM departments ORDER BY name");
                $departments = $stmt_departments->fetchAll(PDO::FETCH_COLUMN);

            } catch (PDOException $e) {
                error_log("Database update failed for user ID " . $id . ": " . $e->getMessage());
                $error = "Failed to update user due to a database error.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit User</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    /* ===================================== */
    /* ===== Theme Styling (TEAL/NAVY Minimalist Scheme) (UPDATED) ===== */
    /* ===================================== */
    :root {
        --bg-primary: #f7f9fb; /* Very Light Gray/Blue */
        --card-bg: rgba(255, 255, 255, 0.95);
        --text-color: #2c3e50; /* Deep Slate Text */
        --muted-text-color: #95a5a6;
        --accent-color: #1abc9c; /* Primary TEAL Accent (Light Mode) */
        --accent-hover: #16a085; /* Darker Teal */
        --border-color: rgba(0, 0, 0, 0.08);
        --form-input-bg: rgba(255, 255, 255, 0.9);
        --success-color: #2ecc71; /* Standard Success Green */
        --error-color: #e74c3c;
        transition: all 0.5s ease;
    }

    /* ===== Dark Mode Variables (Deep Navy/Teal) ===== */
    html.dark-mode {
        --bg-primary: #1c2833; /* Deep Navy Background (UPDATED) */
        --card-bg: rgba(44, 62, 80, 0.9); /* Darker Card Background */
        --text-color: #ecf0f1;
        --muted-text-color: #bdc3c7;
        --accent-color: #1abc9c; /* Deep Teal (Consistency) */
        --accent-hover: #16a085;
        --border-color: rgba(255, 255, 255, 0.1);
        --form-input-bg: rgba(255, 255, 255, 0.1);
        --success-color: #27ae60;
        --error-color: #ff6b6b;
    }

    body {
        font-family: 'Poppins', sans-serif;
        background-color: var(--bg-primary);
        color: var(--text-color);
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
        margin: 0;
        transition: background-color 0.5s ease, color 0.5s ease;
    }

    .form-container {
        max-width: 500px;
        width: 90%;
        padding: 2.5rem;
        background-color: var(--card-bg);
        backdrop-filter: blur(10px); 
        border: 1px solid var(--border-color);
        border-radius: 15px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        transition: all 0.5s ease;
    }

    .form-container h2 {
        text-align: center;
        color: var(--accent-color);
        font-weight: 700;
        margin-bottom: 2rem;
        text-transform: uppercase;
    }

    /* ===== Form Controls ===== */
    .form-control {
        background-color: var(--form-input-bg);
        border: 1px solid var(--border-color);
        color: var(--text-color);
        border-radius: 10px;
        padding: 0.75rem 1rem;
        transition: border-color 0.3s, box-shadow 0.3s;
        height: 52px; /* Uniform height */
    }
    
    .form-control::placeholder {
        color: var(--muted-text-color);
    }

    .form-control:focus {
        border-color: var(--accent-color);
        /* Light Mode Shadow (Teal) (UPDATED) */
        box-shadow: 0 0 8px rgba(26, 188, 156, 0.5); 
        background-color: var(--form-input-bg);
        color: var(--text-color);
    }
    html.dark-mode .form-control:focus {
        /* Dark Mode Shadow (Teal) (UPDATED) */
        box-shadow: 0 0 8px rgba(26, 188, 156, 0.5); 
    }
    
    /* Style for the select element and its options */
    select.form-control {
        appearance: none; /* Removes default browser styling for select */
        /* Light mode arrow color (Dark text) (UPDATED) */
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%232c3e50' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right 1rem center;
        background-size: 0.65em 0.65em;
        padding-right: 2.5rem; 
        padding-left: 1rem; 
    }
    html.dark-mode select.form-control {
        /* Dark mode arrow color (Light text) (UPDATED) */
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23bdc3c7' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
    }


    .form-check {
        margin-bottom: 1.5rem;
        padding-left: 0;
        display: flex;
        align-items: center;
        color: var(--text-color);
        font-weight: 500;
    }

    .form-check .form-check-input {
        width: 1.25em;
        height: 1.25em;
        margin-right: 0.75rem;
        background-color: var(--form-input-bg);
        border: 1px solid var(--border-color);
        border-radius: 0.25rem;
        cursor: pointer;
        flex-shrink: 0;
    }
    
    .form-check-input:checked {
        background-color: var(--accent-color);
        border-color: var(--accent-color);
    }
    
    /* Checkbox Styling */
    .form-check-input:checked[type=checkbox] {
        /* Custom checkbox background image with white fill */
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3e%3cpath fill='none' stroke='%23ecf0f1' stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='m6 10 3 3 6-6'/%3e%3c/svg%3e");
        background-size: 100% 100%;
        background-position: center center;
        background-repeat: no-repeat;
    }
    
    .form-check-input:focus {
        border-color: var(--accent-color);
        /* Light Mode Focus Shadow (Teal) (UPDATED) */
        box-shadow: 0 0 0 .25rem rgba(26, 188, 156, 0.25);
       }
    html.dark-mode .form-check-input:focus {
        /* Dark Mode Focus Shadow (Teal) (UPDATED) */
        box-shadow: 0 0 0 .25rem rgba(26, 188, 156, 0.25);
       }


    /* ===== Buttons (Accent Color) ===== */
    .btn-success {
        /* Use white text for better contrast on accent color */
        color: white !important; 
        background-color: var(--accent-color);
        border-color: var(--accent-color);
        font-weight: 600;
        border-radius: 10px;
        padding: 0.75rem 1.5rem;
        transition: transform 0.3s, background-color 0.3s, box-shadow 0.3s;
    }
    
    .btn-success:hover {
        background-color: var(--accent-hover);
        border-color: var(--accent-hover);
        transform: translateY(-2px);
        /* Light Mode Hover Shadow (Teal) (UPDATED) */
        box-shadow: 0 4px 15px rgba(26, 188, 156, 0.4); 
        color: white;
    }
    html.dark-mode .btn-success:hover {
        /* Dark Mode Hover Shadow (Teal) (UPDATED) */
        box-shadow: 0 4px 15px rgba(26, 188, 156, 0.4);
    }

    .btn-secondary {
        background-color: transparent;
        border: 1px solid var(--border-color);
        color: var(--muted-text-color);
        font-weight: 500;
        border-radius: 10px;
        padding: 0.75rem 1.5rem;
        transition: color 0.3s, border-color 0.3s;
    }

    .btn-secondary:hover {
        color: var(--accent-color);
        border-color: var(--accent-color);
    }

    /* ===== Alerts (The primary success alert will use the standard green/error red) ===== */
    .alert {
        border-radius: 10px;
        font-weight: 500;
        padding: 1rem;
        opacity: 0.9;
        margin-bottom: 1.5rem;
        border: 1px solid;
    }

    .alert-success {
        /* Lighter Green tint */
        background-color: color-mix(in srgb, var(--success-color) 90%, transparent);
        border-color: var(--success-color);
        color: var(--text-color);
    }
    .alert-danger {
        /* Lighter Red tint */
        background-color: color-mix(in srgb, var(--error-color) 90%, transparent); 
        border-color: var(--error-color);
        color: var(--text-color);
    }
    
    html.dark-mode .alert-success {
        /* Dark mode green tint */
        background-color: color-mix(in srgb, var(--success-color) 70%, transparent);
        color: var(--text-color);
    }
    html.dark-mode .alert-danger {
        /* Dark mode red tint */
        background-color: color-mix(in srgb, var(--error-color) 70%, transparent);
        color: var(--text-color);
    }
    
    /* ===== Theme Toggle Switch Styling (Fixed Position) ===== */
    .theme-switch-wrapper {
        position: fixed; 
        top: 1.5rem; 
        right: 1.5rem; 
        display: flex;
        align-items: center;
        z-index: 1000;
    }
    .theme-switch { height: 30px; position: relative; width: 56px; }
    .theme-switch input { display:none; }
    .slider {
        background-color: var(--muted-text-color);
        bottom: 0;
        cursor: pointer;
        left: 0;
        position: absolute;
        right: 0;
        top: 0;
        transition: .4s;
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
    }
    input:checked + .slider { background-color: var(--accent-color); }
    input:checked + .slider:before { transform: translateX(26px); }
    .slider.round { border-radius: 34px; }
    .slider.round:before { border-radius: 50%; }

</style>
</head>
<body>
<div class="theme-switch-wrapper">
    <label class="theme-switch" for="theme-toggle">
        <input type="checkbox" id="theme-toggle">
        <div class="slider round"></div>
    </label>
</div>

<div class="form-container">
    <h2><i class="fas fa-user-edit me-2"></i> Edit User: ID <?= htmlspecialchars($user['id']) ?></h2>

    <?php 
    // Note: str_replace is used to remove potential Markdown formatting from the success message
    if($msg) echo "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>" . htmlspecialchars(str_replace('**', '', $msg)) . "</div>"; 
    if($error) echo "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-2'></i>" . htmlspecialchars($error) . "</div>";
    
    // Check for session messages (e.g., from failed redirects)
    if(isset($_SESSION['error_msg'])): ?>
        <div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-2'></i><?= htmlspecialchars($_SESSION['error_msg']) ?></div>
        <?php unset($_SESSION['error_msg']); // Clear message after display 
    endif;
    ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        
        <div class="mb-3">
            <input type="text" name="username" placeholder="Username" class="form-control" required value="<?= htmlspecialchars($user['username']) ?>">
        </div>
        
        <div class="mb-3">
            <input type="password" name="password" placeholder="New Password (leave blank to keep current)" class="form-control">
        </div>
        
        <div class="mb-3">
            <input type="text" name="full_name" placeholder="Full Name" class="form-control" required value="<?= htmlspecialchars($user['full_name']) ?>">
        </div>
        
        <div class="mb-3">
            <input type="email" name="email" placeholder="Email Address" class="form-control" required value="<?= htmlspecialchars($user['email']) ?>">
        </div>
        
        <div class="mb-3">
            <input type="tel" name="mobile_number" placeholder="Mobile Number (Optional)" class="form-control" value="<?= htmlspecialchars($user['mobile_number'] ?? '') ?>">
        </div>
        
        <div class="mb-3">
            <select name="department" class="form-control" required>
                <option value="" disabled>Select Department/Role</option>
                <?php 
                // Loop through the dynamically fetched department list
                foreach ($departments as $dept): 
                    // Check if the current user's department matches the department in the loop
                    $selected = ($user['department'] === $dept) ? 'selected' : '';
                ?>
                    <option value="<?= htmlspecialchars($dept) ?>" <?= $selected ?>>
                        <?= htmlspecialchars($dept) ?>
                    </option>
                <?php endforeach; ?>
                    <?php 
                    // CRITICAL ADDITION: If the user's current department is not in the departments table 
                    // (e.g., due to old data or manual entry), ensure it still appears as the selected option.
                    // Check if the current user's department is non-empty AND not already in the fetched list
                if (!empty($user['department']) && !in_array($user['department'], $departments)): ?>
                    <option value="<?= htmlspecialchars($user['department']) ?>" selected>
                        <?= htmlspecialchars($user['department']) ?> (Current - Missing from Department List)
                    </option>
                <?php endif; ?>
            </select>
        </div>
        
        <div class="form-check">
            <input type="checkbox" name="is_admin" id="isAdmin" class="form-check-input" <?= $user['is_admin']?'checked':'' ?>
                <?= ($user['id'] == $_SESSION['user_id']) ? 'disabled' : '' ?> >
            <label class="form-check-label" for="isAdmin">
                <i class="fas fa-crown me-1"></i> Admin <?php if ($user['id'] == $_SESSION['user_id']): ?>
                    <small class="text-muted">(Cannot demote yourself)</small>
                <?php endif; ?>
            </label>
            <?php if ($user['id'] == $_SESSION['user_id']): ?>
            <input type="hidden" name="is_admin" value="<?= $user['is_admin'] ?>">
            <?php endif; ?>
        </div>
        
        <div class="d-grid gap-2">
            <button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i> Update User</button>
            <a href="manage_user.php" class="btn btn-secondary mt-2"><i class="fas fa-arrow-left me-2"></i> Back to User List</a>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // JavaScript for theme toggling (Consistent across all pages)
    const themeToggle = document.getElementById('theme-toggle');
    const body = document.documentElement;

    // Set initial theme based on localStorage or OS preference
    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

    function applyTheme(isDark) {
        if (isDark) {
            body.classList.add('dark-mode');
            themeToggle.checked = true;
            localStorage.setItem('theme', 'dark');
        } else {
            body.classList.remove('dark-mode');
            themeToggle.checked = false;
            localStorage.setItem('theme', 'light');
        }
    }

    // Apply initial theme
    const initialIsDark = (savedTheme === 'dark' || (!savedTheme && prefersDark));
    applyTheme(initialIsDark);

    // Add event listener for the toggle switch
    themeToggle.addEventListener('change', () => {
        applyTheme(themeToggle.checked);
    });
</script>
</body>
</html>