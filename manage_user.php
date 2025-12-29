<?php
// PHP backend logic for admin dashboard
// NOTE: I've cleaned up the non-breaking space characters ( ) from your PHP code.
require 'config.php';
// Assuming require_admin() starts the session and checks admin status
require_admin(); 

// Start session if not already started (required for session variables like user_id)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$msg = "";
$error = ""; // Initialize error message

// Generate a CSRF token for the session if it doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle delete action (moved from GET to POST for security)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user_id'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "CSRF token validation failed.";
    } else {
        $delete_id = filter_input(INPUT_POST, 'delete_user_id', FILTER_VALIDATE_INT);

        // Prevent admin from deleting their own account or invalid accounts
        if ($delete_id == $_SESSION['user_id'] || $delete_id === false || $delete_id === 0) { 
            $error = "You cannot delete your own account or an invalid user.";
        } else {
            // Check if the user to be deleted is an admin
            $stmt_check_admin = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
            $stmt_check_admin->execute([$delete_id]);
            $user_to_delete = $stmt_check_admin->fetch();

            if ($user_to_delete && $user_to_delete['is_admin']) {
                $error = "You cannot delete another admin user.";
            } else {
                // Check for primary key constraint violation before deletion (optional but good practice)
                try {
                    $pdo->beginTransaction();
                    
                    // IMPORTANT: If you have foreign keys (e.g., attendance.user_id), 
                    // ensure your database tables are set up with ON DELETE CASCADE, 
                    // or explicitly delete the related records here.
                    
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id=?");
                    
                    if ($stmt->execute([$delete_id])) {
                        $msg = "User deleted successfully! 🗑️";
                        $pdo->commit();
                    } else {
                        $pdo->rollBack();
                        $error = "Error deleting user.";
                    }
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    // Catch constraint violation errors and provide a friendly message
                    if (strpos($e->getMessage(), 'constraint violation') !== false || strpos($e->getMessage(), 'Integrity constraint') !== false) {
                        $error = "Cannot delete user. This user has existing attendance records or other linked data. Delete those first.";
                    } else {
                        error_log("DB Error in manage_user delete: " . $e->getMessage());
                        $error = "An unexpected database error occurred.";
                    }
                }
            }
        }
    }
}

// Fetch all users (UPDATED: Added 'mobile_number' column)
$users = $pdo->query("SELECT id, username, full_name, email, mobile_number, department, is_admin FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch the admin's name for a personalized welcome message (used in CSS theme structure)
$admin_name = $_SESSION['full_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Users - Visionangles</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="icon" type="image/png" href="visionnew.png">
<style>
    /* ================================================= */
    /* ===== SLEEK MINIMALIST THEME (TEAL/NAVY) - RETAINED ===== */
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
        --table-header-bg: #009688; /* Teal Header */
        --table-header-text: #ffffff;
        --table-row-hover: #e6f6f5; /* Light Teal Hover */
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
        --table-header-bg: #26a69a; /* Darker Teal Header */
        --table-header-text: #ecf0f1;
        --table-row-hover: #3d5063;
    }

    body {
        font-family: 'Inter', sans-serif;
        background-color: var(--bg-primary);
        color: var(--text-color);
        min-height: 100vh;
        transition: background-color 0.5s ease, color 0.5s ease;
    }
    
    .container {
        margin-top: 30px;
        margin-bottom: 50px;
        max-width: 1200px;
    }

    .header-container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
    }

    .header-container h2 {
        color: var(--accent-color); /* Teal Accent for the main heading */
        font-weight: 800;
        transition: color 0.5s ease;
        letter-spacing: -0.5px;
    }

    /* --- Button Styling (Teal Accent) --- */
    .btn-success, .btn-primary { 
        background-color: var(--accent-color) !important;
        border-color: var(--accent-color) !important;
        color: #ffffff;
        font-weight: 600;
        border-radius: 6px;
        transition: background-color 0.3s ease, transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    .btn-success:hover, .btn-primary:hover {
        background-color: var(--accent-hover) !important;
        border-color: var(--accent-hover) !important;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 150, 136, 0.2);
    }

    .btn-danger {
        background-color: var(--error-color);
        border-color: var(--error-color);
        color: #ffffff;
        font-weight: 500;
        border-radius: 6px;
        transition: background-color 0.3s ease, transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    .btn-danger:hover {
        background-color: #c0392b; 
        border-color: #c0392b;
        transform: translateY(-2px);
    }
    .btn-danger:disabled {
        background-color: var(--text-muted-color);
        border-color: var(--text-muted-color);
    }

    .btn-secondary {
        background-color: var(--card-bg);
        border: 1px solid var(--border-color);
        color: var(--text-color);
        font-weight: 500;
        border-radius: 6px;
        transition: color 0.3s ease, border-color 0.3s ease, background-color 0.3s ease, transform 0.3s ease;
    }
    .btn-secondary:hover {
        color: var(--accent-color);
        border-color: var(--accent-color);
        transform: translateY(-1px);
    }
    
    /* --- Alerts (Styled with the theme's colors) --- */
    .alert {
        border-radius: 8px;
        font-weight: 500;
        margin-bottom: 20px;
        transition: background-color 0.5s ease, color 0.5s ease, border-color 0.5s ease;
        padding: 12px 20px;
    }
    
    .alert-success {
        background-color: color-mix(in srgb, var(--success-color) 15%, transparent);
        border-color: var(--success-color);
        color: var(--text-color);
    }
    .dark-mode .alert-success {
        background-color: color-mix(in srgb, var(--success-color) 20%, transparent);
        color: var(--text-color);
    }

    .alert-danger {
        background-color: color-mix(in srgb, var(--error-color) 15%, transparent);
        border-color: var(--error-color); 
        color: var(--text-color);
    }
    .dark-mode .alert-danger {
        background-color: color-mix(in srgb, var(--error-color) 20%, transparent);
        color: var(--text-color);
    }

    /* --- Table Styles (Minimalist Card Look) --- */
    .table-responsive {
        border-radius: 12px;
        overflow-x: auto;
        border: 1px solid var(--border-color);
        box-shadow: var(--shadow-light);
        background-color: var(--card-bg);
        transition: background-color 0.5s ease, border-color 0.5s ease;
    }
    
    .table {
        color: var(--text-color);
        margin-bottom: 0;
    }

    .table thead th {
        background-color: var(--table-header-bg); /* Teal Header */
        border-color: var(--border-color);
        color: var(--table-header-text); 
        font-weight: 700;
        white-space: nowrap;
        transition: background-color 0.5s ease, color 0.5s ease;
        padding: 1rem 0.75rem;
    }
    
    .table tbody tr {
        transition: background-color 0.3s ease;
        background-color: var(--card-bg);
    }

    .table tbody tr:hover {
        background-color: var(--table-row-hover);
    }

    .table td, .table th {
        border-color: var(--border-color);
        vertical-align: middle;
        white-space: nowrap;
        transition: border-color 0.5s ease, color 0.5s ease;
    }

    /* Admin checkmark color set to the success color (green) */
    .text-success {
        color: var(--success-color) !important;
        transition: color 0.5s ease;
    }
    .text-muted {
        color: var(--text-muted-color) !important;
    }

    /* --- Dark Mode Toggle Styles (Consistent with Teal Theme) --- */
    .theme-switch-wrapper {
        display: flex;
        align-items: center;
        justify-content: flex-end; 
        margin-bottom: 15px; 
    }
    .theme-switch-wrapper em {
        margin-right: 10px;
        font-size: 0.9rem;
        font-style: normal;
        color: var(--text-color);
        font-weight: 600;
        transition: color 0.5s ease;
    }
    .theme-switch { height: 30px; position: relative; width: 56px; }
    .theme-switch input { display:none; }
    .slider { background-color: var(--text-muted-color); bottom: 0; cursor: pointer; left: 0; position: absolute; right: 0; top: 0; transition: .4s; border-radius: 34px; }
    .slider:before { background-color: #fff; bottom: 3px; content: ""; height: 24px; left: 3px; position: absolute; transition: .4s; width: 24px; border-radius: 50%; }
    /* Teal for the checked slider track */
    input:checked + .slider { background-color: var(--accent-color); } 
    input:checked + .slider:before { transform: translateX(26px); }


    /* --- Responsive Table Styling (Mobile View) --- */
    @media (max-width: 768px) { 
        .table-responsive {
            border: none;
            box-shadow: none;
            overflow-x: hidden;
        }
        .table thead {
            display: none;
        }
        .table tr {
            margin-bottom: 1.5rem;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1rem;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            white-space: normal;
            display: block; /* Make rows stack */
        }
        .table td {
            display: block;
            text-align: right;
            padding: 0.5rem 1rem;
            border: none;
        }
        .table td::before {
            content: attr(data-label);
            float: left;
            font-weight: 600;
            color: var(--accent-color); /* Use teal accent color for label */
        }
        .table td:last-child {
            text-align: center;
            padding-top: 1rem;
            display: flex; /* Allow buttons to be side-by-side */
            justify-content: center;
            gap: 10px;
        }
        .header-container {
            flex-direction: column;
            align-items: flex-start;
        }
        .header-container .btn-success {
            width: 100%;
            margin-top: 15px;
        }
    }
</style>
</head>
<body>
<div class="container">
    <div class="theme-switch-wrapper">
        <em>Dark Mode</em>
        <label class="theme-switch" for="theme-toggle">
            <input type="checkbox" id="theme-toggle">
            <div class="slider round"></div>
        </label>
    </div>

    <div class="header-container">
        <h2><i class="fas fa-shield-alt me-2"></i> Vision Angles | Manage Users</h2>
        <a href="add_user.php" class="btn btn-success"><i class="fas fa-user-plus me-2"></i> Add New User</a>
    </div>

    <?php 
    if($msg) echo "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>" . htmlspecialchars($msg) . "</div>"; 
    if($error) echo "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-2'></i>" . htmlspecialchars($error) . "</div>";
    ?>

    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Mobile Number</th> 
                    <th>Department</th>
                    <th>Admin</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($users as $u): ?>
                <tr>
                    <td data-label="ID"><?= htmlspecialchars($u['id']) ?></td>
                    <td data-label="Username"><?= htmlspecialchars($u['username']) ?></td>
                    <td data-label="Full Name"><?= htmlspecialchars($u['full_name']) ?></td>
                    <td data-label="Email"><?= htmlspecialchars($u['email']) ?></td>
                    <td data-label="Mobile Number"><?= htmlspecialchars($u['mobile_number'] ?? 'N/A') ?></td>
                    
                    <td data-label="Department"><?= htmlspecialchars($u['department']) ?></td>
                    <td data-label="Admin"><?= $u['is_admin'] ? '<i class="fas fa-check-circle text-success"></i> Yes' : '<i class="fas fa-times-circle text-muted"></i> No' ?></td>
                    <td data-label="Action">
                        <a href="edit_user.php?id=<?= $u['id'] ?>" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Edit</a>
                        <?php 
                        // Disable delete button for the current admin or other admins
                        $is_deletable = !$u['is_admin'] && $u['id'] != ($_SESSION['user_id'] ?? 0);
                        ?>
                        <?php if ($is_deletable): ?>
                            <form method="POST" action="manage_user.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete user: <?= htmlspecialchars($u['full_name']) ?>? This action cannot be undone.');">
                                <input type="hidden" name="delete_user_id" value="<?= htmlspecialchars($u['id']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash-alt"></i> Delete</button>
                            </form>
                        <?php else: ?>
                            <button class="btn btn-danger btn-sm" disabled title="Cannot delete admin or your own account"><i class="fas fa-ban"></i> Delete</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="text-center mt-4">
        <a href="admin_dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i> Back to Dashboard</a>
    </div>
</div>

<script>
    // JavaScript for theme toggling
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>