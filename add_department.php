<?php
// add_department.php - Dedicated page for adding a new Department

// 1. Configuration and Security
require 'config.php'; // Loads $pdo and security functions
require_admin();     // Access check

$admin_name = $_SESSION['full_name'] ?? 'Admin';
$message = '';
$message_type = '';

// --- Database Execution Wrapper (Copied from manage_department.php) ---
/**
 * Executes a prepared statement securely.
 * @param PDO $pdo The PDO connection object.
 */
function pdo_execute_dept($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        // Log the error for developer debugging
        error_log("DB Error in Department CRUD: " . $e->getMessage());
        return false;
    }
}

// 2. Handle CREATE (Add Department)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_department'])) {
    $name = trim($_POST['department_name']);
    $description = filter_var(trim($_POST['department_description'] ?? ''), FILTER_SANITIZE_STRING);

    if (empty($name)) {
        $message = 'Department name cannot be empty.';
        $message_type = 'danger';
    } else {
        $sql = "INSERT INTO departments (name, description) VALUES (?, ?)";

        try {
            if (pdo_execute_dept($pdo, $sql, [$name, $description])) {
                // Success: Redirect back to the main management page with a status message
                $success_message = urlencode("Department '{$name}' added successfully!");
                header("Location: manage_department.php?status=success&message={$success_message}");
                exit;
            } else {
                // PDO Error: Likely a unique constraint violation or other database issue
                $message = 'Error adding department. A department with that name might already exist.';
                $message_type = 'danger';
            }
        } catch (PDOException $e) {
            $message = 'Error adding department. A department with that name might already exist.';
            $message_type = 'danger';
        }
    }
}

// 3. HTML Output
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add New Department - Visionangles</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* Basic Styles - Copying from manage_department.php for consistency */
:root { 
    --bg-primary: #ecf0f1; --accent-color: #2ecc71; --card-bg: white; 
    --text-color: #2c3e50; --nav-bg: #ffffff;
}
body { background: var(--bg-primary); color: var(--text-color); font-family: sans-serif; }
.navbar { background: var(--nav-bg); }
.btn-accent { 
    background-color: var(--accent-color); 
    border-color: var(--accent-color); 
    color: white; 
    transition: background-color 0.3s, transform 0.2s;
}
.btn-accent:hover {
    background-color: #27ae60;
    border-color: #27ae60;
    transform: translateY(-1px);
}
.data-container { background: var(--card-bg); padding: 2rem; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
.page-header { border-bottom: 3px solid var(--accent-color); margin-bottom: 2rem; }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg">
    <div class="container-fluid container">
        <a class="navbar-brand" href="admin_dashboard.php"><i class="fas fa-chart-pie me-2"></i>Admin Panel</a>
        <div class="d-flex align-items-center">
            <span class="welcome-text d-none d-md-inline me-3">Welcome, **<?= htmlspecialchars($admin_name) ?>**</span>
            <a href="logout.php" class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-right-from-bracket me-1"></i> Logout</a>
        </div>
    </div>
</nav>

<div class="main-content container py-4">
    
    <div class="d-flex justify-content-between align-items-center page-header">
        <h1><i class="fa-solid fa-building me-2"></i> Add New Department</h1>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="data-container">
        <form action="add_department.php" method="POST">
            <div class="mb-3">
                <label for="department_name" class="form-label">Department Name</label>
                <input type="text" class="form-control" id="department_name" name="department_name" required value="<?= htmlspecialchars($_POST['department_name'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label for="department_description" class="form-label">Description (Optional)</label>
                <textarea class="form-control" id="department_description" name="department_description" rows="3"><?= htmlspecialchars($_POST['department_description'] ?? '') ?></textarea>
            </div>
            <div class="d-flex justify-content-between pt-3">
                <a href="manage_department.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Department List
                </a>
                <button type="submit" name="add_department" class="btn btn-accent">
                    <i class="fa-solid fa-plus me-1"></i> Save New Department
                </button>
            </div>
        </form>
    </div>
    
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>