<?php
// manage_department.php - Department CRUD Management

// 1. Configuration and Security
require 'config.php'; // Loads $pdo and security functions
require_admin(); // Access check

// Start session (ensure this is done in config.php or here if not)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Company Name constant for branding (UPDATED)
const COMPANY_NAME = "Vision Angles"; 

$admin_name = $_SESSION['full_name'] ?? 'Admin';
$message = '';
$message_type = '';

// --- Database Execution Wrapper ---
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

    if (empty($name)) {
        $message = 'Department name cannot be empty.';
        $message_type = 'danger';
    } else {
        // Step 1: Check if the department name already exists
        $check_sql = "SELECT COUNT(*) FROM departments WHERE name = ?";
        $check_stmt = pdo_execute_dept($pdo, $check_sql, [$name]);
        
        if ($check_stmt && $check_stmt->fetchColumn() > 0) {
            $message = "Creation failed: A department named '{$name}' already exists. Please choose a unique name.";
            $message_type = 'danger';
        } else {
            // Step 2: If the name is unique, proceed with insertion
            $insert_sql = "INSERT INTO departments (name) VALUES (?)";
            
            if (pdo_execute_dept($pdo, $insert_sql, [$name])) {
                $message = "Department '{$name}' added successfully! 🎉";
                $message_type = 'success';
            } else {
                 $message = 'Error adding department due to an unexpected database issue (e.g., table or column name mismatch).';
                 $message_type = 'danger';
            }
        }
    }
}

// 3. Handle UPDATE (Edit Department)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_department'])) {
    $id = filter_var($_POST['dept_id'], FILTER_VALIDATE_INT);
    $name = trim($_POST['department_name_edit']);
    
    if ($id !== false && !empty($name)) {
        // Check for duplicate name excluding the current ID
        $check_sql = "SELECT COUNT(*) FROM departments WHERE name = ? AND id != ?";
        $check_stmt = pdo_execute_dept($pdo, $check_sql, [$name, $id]);
        
        if ($check_stmt && $check_stmt->fetchColumn() > 0) {
            $message = "Update failed: A department named '{$name}' already exists. Please choose a unique name.";
            $message_type = 'danger';
        } else {
            $sql = "UPDATE departments SET name = ? WHERE id = ?";
            if (pdo_execute_dept($pdo, $sql, [$name, $id])) {
                $message = "Department updated successfully! 💾";
                $message_type = 'success';
            } else {
                 $message = 'Error updating department. The name might be a duplicate or there was a DB issue.';
                 $message_type = 'danger';
            }
        }
    } else {
          $message = 'Invalid data provided for update.';
          $message_type = 'danger';
    }
}

// 4. Handle DELETE (Delete Department)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_department'])) {
    $id = filter_var($_POST['dept_id_delete'], FILTER_VALIDATE_INT);
    
    if ($id !== false) {
        $sql = "DELETE FROM departments WHERE id = ?";
        if (pdo_execute_dept($pdo, $sql, [$id])) {
            $message = "Department deleted successfully! 🗑️";
            $message_type = 'success';
        } else {
            // Common error: Foreign key constraint (department used by users/records)
            $message = 'Error deleting department. It may be linked to existing users or records, which must be moved or deleted first.';
            $message_type = 'danger';
        }
    } else {
          $message = 'Invalid department ID for deletion.';
          $message_type = 'danger';
    }
}


// 5. Handle READ (List Departments)
$departments = [];

// Check for status messages from redirects (though redirects aren't used here, good practice to keep)
if (isset($_GET['status']) && isset($_GET['message'])) {
    $message_type = $_GET['status'] === 'success' ? 'success' : 'danger';
    $message = htmlspecialchars(urldecode($_GET['message']));
}

$stmt = pdo_execute_dept($pdo, "SELECT id, name FROM departments ORDER BY name ASC");
if ($stmt) {
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
<title>Manage Departments - Visionangles</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="visionnew.png">
<link rel="stylesheet" href="assets/css/admin_dashboard.css">
<style>
/* ================================================= */
/* ===== SLEEK MINIMALIST THEME (TEAL/NAVY) - Applied from admin_dashboard.php ===== */
/* ================================================= */

/* ===== Global Variables (Light Mode Default) ===== */
:root {
    --text-color: #34495e; /* Corporate Navy Text */
    --bg-primary: #f4f7fa; /* Very Light Background */
    --card-bg: #ffffff;
    --table-row-bg: #f8f9fa; /* Light background for even rows */
    --accent-color: #009688; /* Primary Teal Accent */
    --accent-hover: #00796b;
    --error-color: #e74c3c;
    --success-color: #2ecc71; 
    --border-color: rgba(0, 0, 0, 0.08);
    --shadow-light: 0 4px 12px rgba(0,0,0,0.05);
    --nav-bg: #ffffff;
    --text-muted-color: #95a5a6; 
    --table-row-hover: #e0f2f1; /* Light Teal Hover */
    transition: all 0.5s ease;
}

/* ===== Dark Mode Variables (Teal/Navy Dark) ===== */
html.dark-mode {
    --text-color: #ecf0f1; 
    --bg-primary: #1b2029; /* Deep Slate Background */
    --card-bg: #2d3846; /* Darker Card Background */
    --table-row-bg: #283b4c; /* Darker Navy Row Background */
    --accent-color: #4db6ac; /* Lighter Teal Accent */
    --accent-hover: #26a69a;
    --error-color: #ff6b6b;
    --success-color: #48c9b0;
    --border-color: rgba(255, 255, 255, 0.15);
    --shadow-light: 0 4px 12px rgba(0,0,0,0.4);
    --nav-bg: #2d3846;
    --text-muted-color: #bdc3c7;
    --table-row-hover: #405973;

    /* --- General Dark Mode Text Fixes --- */
    .table, .table td, .table th, .table th a, 
    .table tbody tr:hover td { 
        color: var(--text-color) !important;
    }
    .badge.bg-secondary {
        background-color: var(--text-muted-color) !important;
        color: var(--text-color) !important;
    }
}

body { 
    background: var(--bg-primary); 
    color: var(--text-color); 
    font-family: 'Inter', sans-serif; /* Updated Font Family */
    min-height: 100vh;
    transition: background 0.5s ease, color 0.5s ease;
}

/* --- Navigation Bar --- */
.navbar {
    background: var(--nav-bg);
    border-bottom: 1px solid var(--border-color);
    box-shadow: var(--shadow-light);
    padding: 1rem 0;
}
.navbar-brand {
    font-weight: 800; /* Bolder brand font */
    color: var(--accent-color) !important; /* Teal Accent */
    font-size: 1.65rem;
    transition: color 0.5s ease;
}
.welcome-text {
    font-weight: 500;
    color: var(--text-color);
    margin-right: 1.5rem;
    font-size: 0.95rem;
}
.btn-outline-danger {
    --bs-btn-color: var(--error-color);
    --bs-btn-border-color: var(--error-color);
    --bs-btn-hover-bg: var(--error-color);
    --bs-btn-hover-border-color: var(--error-color);
    --bs-btn-hover-color: white;
    border-radius: 6px;
}
.btn-secondary {
    --bs-btn-color: var(--text-muted-color);
    --bs-btn-border-color: var(--text-muted-color);
    --bs-btn-hover-color: var(--accent-color);
    --bs-btn-hover-border-color: var(--accent-color);
    --bs-btn-hover-bg: transparent;
    --bs-btn-active-bg: transparent;
    border-radius: 8px;
}

/* ------------------------------------------- */
/* ===== SMALL DARK MODE TOGGLE STYLING (CONSISTENT) ===== */
/* ------------------------------------------- */
.theme-switch-wrapper { 
    display: flex; 
    align-items: center;
}
.theme-switch-wrapper em { 
    margin-right: 8px; 
    font-size: 0.95rem; 
    font-style: normal; 
    color: var(--text-color); 
    font-weight: 600; 
    transition: color 0.5s ease;
    display: none; /* Hidden on mobile by default */
}
.theme-switch { 
    height: 20px;
    width: 38px;
    position: relative;
    display: inline-block;
}
.theme-switch input { display: none; }
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
    bottom: 1px; 
    content: ""; 
    height: 18px; 
    left: 1px; 
    position: absolute; 
    transition: .4s; 
    width: 18px; 
    border-radius: 50%; 
}
input:checked + .slider { 
    background-color: var(--accent-color); /* Teal track when checked */
}
input:checked + .slider:before { 
    transform: translateX(19px);
}
/* ------------------------------------------- */


/* ===== Main Content Area Styling ===== */
.main-content {
    flex-grow: 1;
    padding: 40px 15px;
}
.page-header { 
    font-size: 2.2rem;
    font-weight: 700;
    color: var(--text-color);
    margin-bottom: 2.5rem;
    border-bottom: 3px solid var(--accent-color); /* Teal bottom border */
    padding-bottom: 0.5rem;
    transition: border-color 0.5s ease, color 0.5s ease;
}
.data-container { 
    background: var(--card-bg); 
    padding: 2rem; 
    border-radius: 12px; /* Smoother corners */
    box-shadow: var(--shadow-light); 
    border: 1px solid var(--border-color);
    transition: background 0.5s ease, border-color 0.5s ease, box-shadow 0.5s ease;
}
.btn-accent, .btn-primary { 
    background-color: var(--accent-color); 
    border-color: var(--accent-color); 
    color: white; 
    font-weight: 600; /* Slightly bolder for action */
    border-radius: 8px;
    transition: background-color 0.3s, transform 0.2s, border-color 0.3s, box-shadow 0.3s;
}
.btn-accent:hover, .btn-primary:hover {
    background-color: var(--accent-hover);
    border-color: var(--accent-hover);
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(0, 150, 136, 0.2); /* Teal shadow */
    color: white;
}
.btn-outline-primary {
    --bs-btn-color: var(--accent-color);
    --bs-btn-border-color: var(--accent-color);
    --bs-btn-hover-color: white;
    --bs-btn-hover-bg: var(--accent-hover);
    --bs-btn-hover-border-color: var(--accent-hover);
    border-radius: 6px;
}
.table th { 
    background-color: var(--accent-color); 
    color: white; 
    font-weight: 600;
    border: none;
    transition: background-color 0.5s ease;
}

/* --- TABLE BODY ROW STYLING --- */
.table td {
    vertical-align: middle;
    color: var(--text-color);
    transition: color 0.5s ease;
}
.table-striped > tbody > tr:nth-of-type(odd) > * {
    background-color: var(--card-bg); 
}
.table-striped > tbody > tr:nth-of-type(even) > * {
    background-color: var(--table-row-bg); 
}
.table-hover tbody tr:hover > * {
    background-color: var(--table-row-hover);
}
/* ------------------------------ */

.modal-content {
    background: var(--card-bg);
    color: var(--text-color);
    border: 1px solid var(--border-color);
    border-radius: 12px;
}
.modal-header {
    border-bottom: 1px solid var(--border-color);
}
.modal-footer {
    border-top: 1px solid var(--border-color);
}
.form-control {
    background-color: var(--table-row-bg); /* Use the even row color for inputs */
    color: var(--text-color);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    transition: background-color 0.5s, color 0.5s, border-color 0.5s;
}
.form-control:focus {
    border-color: var(--accent-color);
    box-shadow: 0 0 0 0.25rem rgba(0, 150, 136, 0.25); /* Teal focus ring */
    background-color: var(--card-bg);
}
</style>
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
        
        <a href="attendance_requests.php" class="nav-link ">
            <i class="fa-solid fa-clock-rotate-left" style="color: var(--warning-color);"></i> Correction Requests 
            <?php 
            if ($pending_corrections > 0 && !$corrections_dismissed): 
            ?>
                <span class="badge bg-warning rounded-pill ms-1 text-dark"><?= $pending_corrections ?></span>
            <?php endif; ?>
        </a>
        
        <a href="reset_requests.php" class="nav-link ">
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
        <a href="add_user.php" class="nav-link">
            <i class="fa-solid fa-user-plus"></i> Add New User
        </a>
        <a href="manage_department.php" class="nav-link active">
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

<div class="main-content container py-4">
    
    <div class="d-flex justify-content-between align-items-center page-header">
        <h1><i class="fa-solid fa-building me-2"></i>Manage Departments</h1>
        <button class="btn btn-accent" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
            <i class="fa-solid fa-plus me-1"></i> Add New Department
        </button>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="data-container">
        <h3>Department List (<?= count($departments) ?> total)</h3>
        <div class="table-responsive mt-4">
            <table class="table table-hover table-striped">
                <thead>
                    <tr>
                        <th style="width: 15%;">#ID</th>
                        <th>Department Name</th>
                        <th class="text-center" style="width: 20%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($departments) > 0): ?>
                        <?php foreach ($departments as $dept): ?>
                        <tr>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($dept['id']) ?></span></td>
                            <td><?= htmlspecialchars($dept['name']) ?></td>
                            <td class="text-center">
                                <a href="#" 
                                    class="btn btn-sm btn-outline-primary me-2" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#editDepartmentModal"
                                    data-id="<?= htmlspecialchars($dept['id']) ?>"
                                    data-name="<?= htmlspecialchars($dept['name']) ?>"
                                    title="Edit">
                                    <i class="fa-solid fa-pencil"></i>
                                </a>
                                <a href="#" 
                                    class="btn btn-sm btn-outline-danger"
                                    data-bs-toggle="modal" 
                                    data-bs-target="#deleteDepartmentModal"
                                    data-id="<?= htmlspecialchars($dept['id']) ?>"
                                    data-name="<?= htmlspecialchars($dept['name']) ?>"
                                    title="Delete">
                                    <i class="fa-solid fa-trash-can"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted py-4">
                                <i class="fas fa-box-open me-2"></i> No departments found. Click "Add New Department" to get started!
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="text-center mt-5">
        <a href="admin_dashboard.php" class="btn btn-lg btn-secondary">
            <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
        </a>
    </div>
    </div>

<div class="modal fade" id="addDepartmentModal" tabindex="-1" aria-labelledby="addDepartmentModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="manage_department.php" method="POST">
        <div class="modal-header">
          <h5 class="modal-title" id="addDepartmentModalLabel"><i class="fa-solid fa-plus-circle me-2 text-primary"></i> Add New Department</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="department_name" class="form-label">Department Name</label>
            <input type="text" class="form-control" id="department_name" name="department_name" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="add_department" class="btn btn-accent">Save Department</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="editDepartmentModal" tabindex="-1" aria-labelledby="editDepartmentModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="manage_department.php" method="POST">
        <div class="modal-header">
          <h5 class="modal-title" id="editDepartmentModalLabel"><i class="fa-solid fa-pencil me-2 text-primary"></i> Edit Department</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" name="dept_id" id="dept_id_edit">
            <div class="mb-3">
                <label for="department_name_edit" class="form-label">Department Name</label>
                <input type="text" class="form-control" id="department_name_edit" name="department_name_edit" required>
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" name="edit_department" class="btn btn-accent">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="deleteDepartmentModal" tabindex="-1" aria-labelledby="deleteDepartmentModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="manage_department.php" method="POST">
        <div class="modal-header">
          <h5 class="modal-title text-danger" id="deleteDepartmentModalLabel"><i class="fa-solid fa-triangle-exclamation me-2"></i>Confirm Deletion</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" name="dept_id_delete" id="dept_id_delete">
            <p>Are you sure you want to delete department: <strong class="text-danger" id="dept_name_delete_placeholder"></strong>?</p>
            <div class="alert alert-warning small">This action cannot be undone and may fail if the department is linked to users or other records due to database constraints.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="delete_department" class="btn btn-danger">Yes, Delete Department</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>

    // JavaScript to dynamically populate the Edit and Delete Modals
    const editModal = document.getElementById('editDepartmentModal');
    editModal.addEventListener('show.bs.modal', event => {
        const button = event.relatedTarget; 
        const id = button.getAttribute('data-id');
        const name = button.getAttribute('data-name');

        editModal.querySelector('#dept_id_edit').value = id;
        editModal.querySelector('#department_name_edit').value = name;
    });

    const deleteModal = document.getElementById('deleteDepartmentModal');
    deleteModal.addEventListener('show.bs.modal', event => {
        const button = event.relatedTarget;
        const id = button.getAttribute('data-id');
        const name = button.getAttribute('data-name');

        deleteModal.querySelector('#dept_id_delete').value = id;
        deleteModal.querySelector('#dept_name_delete_placeholder').textContent = name;
    });
</script>
</body>
</html>