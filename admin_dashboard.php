<?php
/**
 * ADMIN DASHBOARD PAGE
 * Modernized: Bubble-style quick tools, working alert badges, and notification logic.
 */

require 'config.php'; // includes $conn or $pdo and admin check
require_admin(); 

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start(); 
}

// ===================================================
// --- ALERT DISMISSAL LOGIC ---
// ===================================================
if (isset($_GET['dismiss'])) {
    switch ($_GET['dismiss']) {
        case 'corrections':
            $_SESSION['alert_corrections_dismissed'] = true;
            break;
        case 'resets':
            $_SESSION['alert_resets_dismissed'] = true;
            break;
        case 'all':
            $_SESSION['alert_corrections_dismissed'] = true;
            $_SESSION['alert_resets_dismissed'] = true;
            break;
    }
    header("Location: admin_dashboard.php");
    exit;
}

$corrections_dismissed = $_SESSION['alert_corrections_dismissed'] ?? false;
$resets_dismissed = $_SESSION['alert_resets_dismissed'] ?? false;

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
            <div class="theme-switch-wrapper d-flex justify-content-between align-items-center">
                <em id="theme-label-mobile" class="text-dark">Dark Mode</em>
                <label class="theme-switch theme-switch-mobile" for="theme-toggle-mobile">
                    <input type="checkbox" id="theme-toggle-mobile" role="switch" aria-labelledby="theme-label-mobile">
                    <div class="slider round"></div>
                </label>
            </div>
        </li>
        <li><hr class="dropdown-divider"></li>
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
<title>Admin Dashboard - Visionangles</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ================================================= */
/* ===== SLEEK MINIMALIST THEME (TEAL/NAVY) ===== */
/* ================================================= */

/* ===== Global Variables (Light Mode Default) ===== */
:root {
    --text-color: #34495e; /* Corporate Navy Text */
    --bg-primary: #f8f9fa; /* Off-White Background */
    --card-bg: #ffffff;
    --accent-color: #009688; /* Primary Teal Accent */
    --accent-hover: #00796b; 
    --sidebar-bg: #212529; /* Dark Charcoal Sidebar */
    --sidebar-text: #e0e0e0; /* Light Gray Text */
    --sidebar-active-bg: rgba(0, 150, 136, 0.2); /* Teal Transparent BG */
    --sidebar-active-text: #ffffff;
    --sidebar-active-border: #009688;
    --border-color: rgba(0, 0, 0, 0.08);
    --shadow-subtle: 0 1px 3px rgba(0,0,0,0.05);
    --error-color: #e74c3c;
    --warning-color: #f39c12; 
    transition: all 0.5s ease;
}

/* ===== Dark Mode Variables (High Contrast) ===== */
html.dark-mode {
    --text-color: #f8f9fa;
    --bg-primary: #1b2029; /* Deep Slate Background */
    --card-bg: #29313d; /* Darker Card Background */
    --accent-color: #4db6ac; /* Lighter Teal Accent */
    --accent-hover: #26a69a; 
    --sidebar-bg: #1f2833; /* Darker Charcoal */
    --sidebar-text: #f0f0f0; 
    --sidebar-active-bg: rgba(77, 182, 172, 0.15); 
    --sidebar-active-text: #ffffff;
    --sidebar-active-border: #4db6ac;
    --border-color: rgba(255, 255, 255, 0.1);
    --shadow-subtle: 0 1px 5px rgba(0,0,0,0.5);
    --error-color: #ff8a80; 
    --warning-color: #ffc400; 
}

body { 
    background: var(--bg-primary); 
    color: var(--text-color); 
    font-family: 'Inter', sans-serif; 
    min-height: 100vh;
    margin: 0;
    display: flex;
}

/* * ----------------------------------------------------
 * --- RESPONSIVENESS: Sidebar & Main Container ---
 * ----------------------------------------------------
 */
/* Desktop Default (lg breakpoint: >= 992px) */
.sidebar {
    width: 230px; 
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    background: var(--sidebar-bg);
    color: var(--sidebar-text);
    box-shadow: 1px 0 5px rgba(0,0,0,0.15); 
    display: flex;
    flex-direction: column;
    z-index: 1050; 
    transition: all 0.3s ease; 
    overflow-y: auto; 
}

.main-container {
    margin-left: 230px; 
    width: calc(100% - 230px);
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    transition: all 0.3s ease; 
}

/* Adjust for small screens (Mobile/Tablet: < 992px) */
@media (max-width: 991.98px) {
    .sidebar { 
        width: 100%; 
        height: auto; 
        position: relative; 
        overflow-y: visible;
        order: 1;
        display: block;
    }
    .sidebar-nav {
        /* Layout for mobile links - 2 per row */
        display: flex;
        flex-wrap: wrap;
        padding: 0.5rem 0.5rem;
        justify-content: flex-start;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08); 
    }
    .sidebar-nav a.nav-link {
        flex: 1 1 45%; 
        text-align: left; 
        margin: 0.25rem;
        padding: 0.5rem 0.75rem; 
        border-left: none;
        border: 1px solid rgba(255, 255, 255, 0.1);
        font-size: 0.85rem; 
    }
    .sidebar-nav a.nav-link i {
        margin-right: 6px; 
        display: inline-block; 
        width: auto; 
    }
    .main-container { 
        margin-left: 0; 
        width: 100%; 
        order: 2;
    }
    
    /* Hide non-essential elements on mobile sidebar */
    .sidebar-title { 
        display: none !important; 
    }
    
    /* Header/Top Bar Adjustments for Mobile */
    .header-bar { 
        padding: 0.75rem 1rem;
    }
    .dashboard-title { 
        font-size: 1.3rem; 
    }
}
/* ---------------------------------------------------- */
/* ---------------- END RESPONSIVENESS CSS ------------ */
/* ---------------------------------------------------- */


/* --- SIDEBAR COMPONENTS (unchanged) --- */
.sidebar-header {
    padding: 1rem 1.25rem;
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--accent-color); 
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}
/* ... rest of sidebar styles ... */
.sidebar-nav {
    flex-grow: 1;
    padding: 1rem 0;
}
.sidebar-title {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--sidebar-text);
    opacity: 0.6;
    text-transform: uppercase;
    padding: 0.5rem 1.25rem 0.5rem;
    margin-top: 1rem;
    display: block;
}
.nav-link {
    color: var(--sidebar-text);
    padding: 0.65rem 1.25rem;
    display: block;
    font-size: 0.95rem;
    font-weight: 500;
    border-left: 3px solid transparent; 
    transition: all 0.2s ease;
}
.nav-link:hover {
    background: rgba(255, 255, 255, 0.05);
    color: #fff;
}
.nav-link.active {
    background: var(--sidebar-active-bg);
    border-left-color: var(--sidebar-active-border);
    color: var(--sidebar-active-text);
    font-weight: 600;
}
.nav-link i {
    width: 20px;
    text-align: center;
    margin-right: 10px;
    color: var(--accent-color); 
    transition: color 0.2s ease;
}
.nav-link.active i {
    color: var(--sidebar-active-border); 
}

/* --- SIDEBAR FOOTER (Desktop Only) --- */
.sidebar-footer {
    padding: 0.75rem 1.25rem;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
}
.welcome-text {
    font-size: 0.85rem;
    font-weight: 400;
    color: var(--sidebar-text);
    opacity: 0.8;
    margin-bottom: 0.5rem;
}
.logout-btn-footer {
    width: 100%;
    margin-top: 1rem;
    background-color: var(--error-color);
    color: #fff;
    border: none;
    font-weight: 600;
    transition: background-color 0.2s;
}
.logout-btn-footer:hover {
    background-color: #c0392b; 
    color: #fff;
}
.theme-switch-wrapper {
    display: flex; 
    align-items: center;
    margin-top: 0.5rem;
}
.theme-switch-wrapper em {
    margin-right: 8px;
    font-size: 0.85rem;
    font-style: normal; 
    font-weight: 500; 
    color: var(--sidebar-text);
}
.theme-switch { 
    height: 20px;
    width: 38px;
    position: relative;
    margin: 0; 
    padding: 0;
}
@media (max-width: 991.98px) {
    .theme-switch-mobile {
        transform: scale(0.85); 
        transform-origin: right center;
    }
}
/* ... rest of theme switch styles (slider, input:checked) ... */
.theme-switch input { display:none; }
.slider { 
    background-color: #6c757d; 
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
    background-color: var(--accent-color); 
}
input:checked + .slider:before { 
    transform: translateX(19px);
}


/* --- HEADER BAR (TOP) --- */
.header-bar {
    background: var(--card-bg);
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
    box-shadow: var(--shadow-subtle);
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    z-index: 1000;
    transition: background 0.5s ease;
}
.dropdown-menu {
    z-index: 1100;
}
.dashboard-title {
    font-size: 1.5rem; 
    font-weight: 600;
    color: var(--text-color);
    margin: 0;
}

/* ... rest of card styles and badge styles ... */
.notification-icon-container .btn {
    color: var(--text-color);
    font-size: 1.1rem;
    margin: 0; 
    padding: 0;
    border: none;
}

.notification-badge-pulse {
    animation: pulse-ring 1s cubic-bezier(0.2, 0, 0.8, 1) infinite;
}
@keyframes pulse-ring {
    0% {
        box-shadow: 0 0 0 0 rgba(231, 76, 60, 0.4);
    }
    100% {
        box-shadow: 0 0 0 8px rgba(231, 76, 60, 0);
    }
}
.header-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
}
.main-content {
    padding: 1.5rem;
    flex-grow: 1;
}

@keyframes card-fade-in {
    0% { opacity: 0; transform: translateY(10px); }
    100% { opacity: 1; transform: translateY(0); }
}
.dashboard-card {
    background: var(--card-bg);
    color: var(--text-color);
    border: 1px solid var(--border-color);
    box-shadow: none; 
    transition: all 0.3s ease;
    height: 100%;
    text-decoration: none;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 1.5rem 1rem; 
    opacity: 0; 
    transform: translateY(10px);
    animation: card-fade-in 0.5s ease forwards;
    position: relative; 
    border-radius: 50px; 
    justify-content: center;
}
.dashboard-card:hover { 
    transform: translateY(-2px); 
    box-shadow: 0 6px 15px rgba(0,0,0,0.15); 
    border-color: var(--accent-hover);
}
.dashboard-card-icon-wrapper {
    background: var(--accent-color);
    width: 60px;
    height: 60px;
    border-radius: 50%; 
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.5rem;
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}
.dashboard-card i { 
    font-size: 1.8rem; 
    color: #ffffff !important; 
    margin-bottom: 0; 
}
.dashboard-card:hover .dashboard-card-icon-wrapper {
    background: var(--accent-hover);
}
.dashboard-card h5 { 
    font-weight: 600; 
    font-size: 0.85rem; 
    text-align: center;
    color: var(--text-color); 
    margin-top: 0.5rem;
    line-height: 1.2;
}
.card-warning .dashboard-card-icon-wrapper { 
    background: var(--warning-color); 
}
.card-error .dashboard-card-icon-wrapper { 
    background: var(--error-color); 
}
.card-warning:hover .dashboard-card-icon-wrapper { 
    background: #e67e22; 
}
.card-error:hover .dashboard-card-icon-wrapper { 
    background: #c0392b; 
}
.card-badge {
    position: absolute;
    top: -10px; 
    right: -10px; 
    z-index: 50;
    padding: 0.5em 0.8em;
}

</style>
</head>
<body>

<aside class="sidebar d-print-none d-lg-flex">
    <div class="sidebar-header">
        <i class="fas fa-cubes me-2"></i>Vision Angles
    </div>

    <nav class="sidebar-nav">
        <a href="admin_dashboard.php" class="nav-link active">
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
        <a href="add_user.php" class="nav-link">
            <i class="fa-solid fa-user-plus"></i> Add New User
        </a>
        <a href="manage_department.php" class="nav-link">
            <i class="fa-solid fa-sitemap"></i> Manage Department
        </a>

        <span class="sidebar-title">Time & Shifts</span>
        <a href="create_shift.php" class="nav-link">
            <i class="fa-solid fa-business-time"></i> Create Shift
        </a>
        <a href="assign_shift.php" class="nav-link">
            <i class="fa-solid fa-user-clock"></i> Assign Shifts
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
        
        <div class="theme-switch-wrapper d-flex justify-content-between align-items-center">
            <em id="theme-label">Dark Mode</em>
            <label class="theme-switch" for="theme-toggle">
                <input type="checkbox" id="theme-toggle" role="switch" aria-labelledby="theme-label">
                <div class="slider round"></div>
            </label>
        </div>

        <a href="logout.php" class="btn logout-btn-footer">
            <i class="fa-solid fa-right-from-bracket me-2"></i> Logout
        </a>
    </div>
</aside>

<div class="main-container">

    <header class="header-bar d-print-none">
        <h1 class="dashboard-title">
            Dashboard
        </h1>
        
        <div class="header-actions d-flex align-items-center">
            <?php 
            if ($total_alerts > 0):
            ?>
                <div class="dropdown notification-icon-container me-3">
                    <button class="btn p-0 position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?= $total_alerts ?> Pending Alert(s)">
                        <i class="fa-solid fa-bell"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-circle bg-danger border border-light notification-badge-pulse" style="font-size: 0.7rem; padding: 0.3em 0.5em;">
                            <?= $total_alerts ?>
                            <span class="visually-hidden">New alerts</span>
                        </span>
                    </button>

                    <ul class="dropdown-menu dropdown-menu-end" style="min-width: 250px;">
                        <li class="dropdown-header fw-bold">Pending Requests (<?= $total_alerts ?>)</li>
                        <li><hr class="dropdown-divider"></li>
                        
                        <?php if ($pending_corrections > 0 && !$corrections_dismissed): ?>
                            <li class="d-flex justify-content-between align-items-center px-3 py-2 bg-warning bg-opacity-10">
                                <a href="attendance_requests.php" class="dropdown-item p-0 text-dark fw-bold">
                                    <i class="fa-solid fa-clock-rotate-left me-2 text-warning"></i> 
                                    <?= $pending_corrections ?> Correction Request<?= $pending_corrections > 1 ? 's' : '' ?>
                                </a>
                                <a href="?dismiss=corrections" class="btn btn-sm btn-link text-muted p-0" title="Dismiss this alert">
                                    <i class="fa-solid fa-xmark"></i>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php if ($pending_resets > 0 && !$resets_dismissed): ?>
                            <li class="d-flex justify-content-between align-items-center px-3 py-2 bg-danger bg-opacity-10">
                                <a href="reset_requests.php" class="dropdown-item p-0 text-danger fw-bold">
                                    <i class="fa-solid fa-key me-2 text-danger"></i> 
                                    <?= $pending_resets ?> Password Reset<?= $pending_resets > 1 ? 's' : '' ?>
                                </a>
                                <a href="?dismiss=resets" class="btn btn-sm btn-link text-muted p-0" title="Dismiss this alert">
                                    <i class="fa-solid fa-xmark"></i>
                                </a>
                            </li>
                        <?php endif; ?>

                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-center text-muted" href="?dismiss=all">
                                Dismiss All Notifications
                            </a>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>

            <?= $mobile_footer_content ?>
            
            </div>
    </header>

    <main class="main-content">
        
        <h2 class="h5 mb-4 fw-bold">Quick Access Tools</h2>
        <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-4">
            
            <div class="col">
                <a href="attendance_requests.php" class="dashboard-card card-warning">
                    <div class="dashboard-card-icon-wrapper">
                        <i class="fa-solid fa-clock-rotate-left"></i> 
                    </div>
                    <h5>Correction Requests</h5>
                    <?php 
                    if ($pending_corrections > 0 && !$corrections_dismissed): 
                    ?>
                        <span class="badge bg-warning rounded-pill card-badge text-dark"><?= $pending_corrections ?></span>
                    <?php endif; ?>
                </a>
            </div>

            <div class="col">
                <a href="reset_requests.php" class="dashboard-card card-error">
                    <div class="dashboard-card-icon-wrapper">
                        <i class="fa-solid fa-key"></i>
                    </div>
                    <h5>Password Requests</h5>
                    <?php 
                    if ($pending_resets > 0 && !$resets_dismissed): 
                    ?>
                        <span class="badge bg-danger rounded-pill card-badge"><?= $pending_resets ?></span>
                    <?php endif; ?>
                </a>
            </div>

            <div class="col">
                <a href="add_user.php" class="dashboard-card">
                    <div class="dashboard-card-icon-wrapper">
                        <i class="fa-solid fa-user-plus"></i>
                    </div>
                    <h5>Add New User</h5>
                </a>
            </div>
            
            <div class="col">
                <a href="attendance_report.php" class="dashboard-card">
                    <div class="dashboard-card-icon-wrapper">
                        <i class="fa-solid fa-chart-line"></i>
                    </div>
                    <h5>Attendance Reports</h5>
                </a>
            </div>
            
            <div class="col">
                <a href="logs.php" class="dashboard-card">
                    <div class="dashboard-card-icon-wrapper">
                        <i class="fa-solid fa-bug"></i>
                    </div>
                    <h5>Activity Logs</h5>
                </a>
            </div>

        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
    // JavaScript for theme toggling
    const themeToggleDesktop = document.getElementById('theme-toggle');
    const themeToggleMobile = document.getElementById('theme-toggle-mobile');
    const htmlElement = document.documentElement;

    function applyTheme(theme) {
        if (theme === 'dark') {
            htmlElement.classList.add('dark-mode');
            if (themeToggleDesktop) themeToggleDesktop.checked = true;
            if (themeToggleMobile) themeToggleMobile.checked = true;
        } else {
            htmlElement.classList.remove('dark-mode');
            if (themeToggleDesktop) themeToggleDesktop.checked = false;
            if (themeToggleMobile) themeToggleMobile.checked = false;
        }
    }

    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

    // Prioritize saved theme, then OS preference, otherwise default to 'light'
    const initialTheme = savedTheme || (prefersDark ? 'dark' : 'light');
    applyTheme(initialTheme);

    // Function to handle theme change and save preference
    const handleThemeChange = (event) => {
        const newTheme = event.target.checked ? 'dark' : 'light';
        
        // Find the other toggle and set its state
        if (event.target.id === 'theme-toggle' && themeToggleMobile) {
            themeToggleMobile.checked = event.target.checked;
        } else if (event.target.id === 'theme-toggle-mobile' && themeToggleDesktop) {
            themeToggleDesktop.checked = event.target.checked;
        }

        applyTheme(newTheme);
        localStorage.setItem('theme', newTheme);
    };

    // Add event listeners for the toggle switches
    if (themeToggleDesktop) {
        themeToggleDesktop.addEventListener('change', handleThemeChange);
    }
    if (themeToggleMobile) {
        themeToggleMobile.addEventListener('change', handleThemeChange);
    }
</script>

</body>
</html>