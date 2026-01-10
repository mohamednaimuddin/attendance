<?php
// PHP backend logic for Password Reset Requests

// 1. Configuration and Session Setup
require 'config.php'; // MUST contain $pdo connection
require_admin(); // MUST contain admin session check and redirection

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$success_message = null;
$pending_items_per_page = 10;
$history_items_per_page = 5;

// --- 2. DATABASE ACTION HANDLERS ---

// Handle clearing ALL pending requests
if (isset($_POST['clear_all'])) {
    try {
        // Mark ALL unhandled requests as handled (UPDATED: uses status = 'Handled')
        $stmt = $pdo->prepare("UPDATE password_reset_requests SET status = 'Handled' WHERE status != 'Handled'");
        $stmt->execute();
        
        // Update session counter immediately
        $_SESSION['pending_reset_count'] = 0;

        header("Location: reset_requests.php?status=cleared");
        exit();
    } catch (PDOException $e) {
        error_log("DB Error clearing all requests: " . $e->getMessage());
        header("Location: reset_requests.php?status=error");
        exit();
    }
}

// Handle clearing a SINGLE request
if (isset($_POST['clear_single'])) {
    $request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    
    if ($request_id !== false && $request_id > 0) {
        try {
            // Mark single request as handled (UPDATED: uses status = 'Handled')
            $stmt = $pdo->prepare("UPDATE password_reset_requests SET status = 'Handled' WHERE id = ?");
            $stmt->execute([$request_id]);
            
            // Recalculate counter (or rely on the fetch below)
            $_SESSION['pending_reset_count'] = ($_SESSION['pending_reset_count'] ?? 1) - 1;

            header("Location: reset_requests.php?status=handled");
            exit();
        } catch (PDOException $e) {
            error_log("DB Error clearing single request: " . $e->getMessage());
            header("Location: reset_requests.php?status=error");
            exit();
        }
    }
}

// Check for status messages from redirects
if (isset($_GET['status'])) {
    if ($_GET['status'] == 'cleared') {
        $success_message = "All password reset requests have been cleared and marked as handled.";
    } elseif ($_GET['status'] == 'handled') {
        $success_message = "The request has been marked as handled.";
    } elseif ($_GET['status'] == 'error') {
        $success_message = "An unexpected database error occurred during the request handling.";
    }
}

// --- 3. PENDING REQUESTS PAGINATION SETUP ---
$pending_page = filter_input(INPUT_GET, 'pp', FILTER_VALIDATE_INT) ?? 1; // 'pp' for pending page
$pending_page = max(1, $pending_page);
$pending_offset = ($pending_page - 1) * $pending_items_per_page;

// 1. Get total count for pending requests
try {
    // UPDATED: Check for status != 'Handled'
    $total_pending_stmt = $pdo->prepare("SELECT COUNT(*) FROM password_reset_requests WHERE status != 'Handled' AND user_id IS NOT NULL");
    $total_pending_stmt->execute();
    $total_pending_items = $total_pending_stmt->fetchColumn();
    $total_pending_pages = ceil($total_pending_items / $pending_items_per_page);
} catch (PDOException $e) {
    error_log("DB Error counting pending requests: " . $e->getMessage());
    $total_pending_items = 0;
    $total_pending_pages = 1;
}

// 2. Fetch Pending Requests (Existing Users ONLY)
try {
    // UPDATED: Check for status != 'Handled'
    $stmt = $pdo->prepare("SELECT prr.*, u.full_name, u.username 
        FROM password_reset_requests prr
        JOIN users u ON prr.user_id = u.id
        WHERE prr.status != 'Handled' 
        AND prr.user_id IS NOT NULL 
        ORDER BY prr.request_time DESC
        LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $pending_items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(2, $pending_offset, PDO::PARAM_INT);
    $stmt->execute();
    $pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("DB Error fetching pending requests: " . $e->getMessage());
    $pending_requests = [];
    // Only display error if no other success message has been set
    $success_message = $success_message ?? "Could not load pending requests due to a database error.";
}


// --- 4. HISTORY REQUESTS PAGINATION SETUP (5 ITEMS PER PAGE) ---
$history_page = filter_input(INPUT_GET, 'hp', FILTER_VALIDATE_INT) ?? 1; // 'hp' for history page
$history_page = max(1, $history_page);
$history_offset = ($history_page - 1) * $history_items_per_page;

// 1. Get total count for history requests (all handled requests)
try {
    // UPDATED: Check for status = 'Handled'
    $total_history_stmt = $pdo->prepare("SELECT COUNT(*) FROM password_reset_requests WHERE status = 'Handled'");
    $total_history_stmt->execute();
    $total_history_items = $total_history_stmt->fetchColumn();
    $total_history_pages = ceil($total_history_items / $history_items_per_page);
} catch (PDOException $e) {
    error_log("DB Error counting history requests: " . $e->getMessage());
    $total_history_items = 0;
    $total_history_pages = 1;
}

// 2. Fetch Handled Requests (History)
try {
    // UPDATED: Check for status = 'Handled'
    $stmt_history = $pdo->prepare("SELECT prr.*, u.full_name, u.username
        FROM password_reset_requests prr
        LEFT JOIN users u ON prr.user_id = u.id
        WHERE prr.status = 'Handled' 
        ORDER BY prr.request_time DESC 
        LIMIT ? OFFSET ?");
    $stmt_history->bindValue(1, $history_items_per_page, PDO::PARAM_INT);
    $stmt_history->bindValue(2, $history_offset, PDO::PARAM_INT);
    $stmt_history->execute();
    $handled_requests = $stmt_history->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("DB Error fetching handled requests: " . $e->getMessage());
    $handled_requests = [];
}


// Update session counter based on actual DB count for the dashboard bell
$_SESSION['pending_reset_count'] = $total_pending_items;

// Fetch the admin's name for a personalized welcome message
$admin_name = $_SESSION['full_name'] ?? 'Admin';


// --- 5. Helper function for rendering Bootstrap Pagination ---
function render_pagination($current_page, $total_pages, $param_name) {
    if ($total_pages <= 1) return '';

    // Get existing parameters to maintain state between pending/history tabs
    $other_param_name = ($param_name == 'pp') ? 'hp' : 'pp';
    $other_param_value = filter_input(INPUT_GET, $other_param_name, FILTER_VALIDATE_INT) ?? 1;

    $html = '<nav aria-label="Page navigation" class="d-flex justify-content-center mt-4"><ul class="pagination">';
    
    // Previous button
    $prev_disabled = ($current_page <= 1) ? 'disabled' : '';
    $prev_page = $current_page - 1;
    $prev_link = "?$param_name=$prev_page&$other_param_name=$other_param_value";
    $html .= '<li class="page-item ' . $prev_disabled . '"><a class="page-link" href="' . $prev_link . '" aria-label="Previous"><span aria-hidden="true">&laquo;</span></a></li>';

    for ($i = 1; $i <= $total_pages; $i++) {
        $active = ($i == $current_page) ? 'active' : '';
        $page_link = "?$param_name=$i&$other_param_name=$other_param_value";
        $html .= '<li class="page-item ' . $active . '"><a class="page-link" href="' . $page_link . '">' . $i . '</a></li>';
    }

    // Next button
    $next_disabled = ($current_page >= $total_pages) ? 'disabled' : '';
    $next_page = $current_page + 1;
    $next_link = "?$param_name=$next_page&$other_param_name=$other_param_value";
    $html .= '<li class="page-item ' . $next_disabled . '"><a class="page-link" href="' . $next_link . '" aria-label="Next"><span aria-hidden="true">&raquo;</span></a></li>';

    $html .= '</ul></nav>';
    return $html;
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
<title>Reset Requests - Visionangles</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="visionnew.png">
<link rel="stylesheet" href="assets/css/reset_requests.css">
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
        
        <a href="attendance_requests.php" class="nav-link ">
            <i class="fa-solid fa-clock-rotate-left" style="color: var(--warning-color);"></i> Correction Requests 
            <?php 
            if ($pending_corrections > 0 && !$corrections_dismissed): 
            ?>
                <span class="badge bg-warning rounded-pill ms-1 text-dark"><?= $pending_corrections ?></span>
            <?php endif; ?>
        </a>
        
        <a href="reset_requests.php" class="nav-link active">
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
<div class="main-content" id="main-content">
    <div class="container">
        <div class="text-center">
            <h1 class="page-header">
                <i class="fas fa-unlock-alt me-2"></i> User Reset Management
            </h1>
        </div>
        
        <?php if (isset($success_message)): ?>
            <div class="alert 
                <?= str_contains($success_message, 'error') ? 'alert-danger-custom' : (str_contains($success_message, 'No pending') ? 'alert-success-custom' : 'alert-info-custom') ?> 
                alert-custom text-center" role="alert">
                <?= htmlspecialchars($success_message) ?>
            </div>
        <?php endif; ?>

        <h3 class="h3-section text-danger"><i class="fas fa-exclamation-circle"></i> Pending Requests (<?= $total_pending_items ?>)</h3>
        <div class="main-card mb-5">
            <?php if ($total_pending_items > 0): ?>
            <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
                <h5 class="mb-0 text-muted" style="font-weight: 500;">
                    Showing Requests <?= $pending_offset + 1 ?>-<?= min($pending_offset + $pending_items_per_page, $total_pending_items) ?> of <?= $total_pending_items ?>
                </h5>
                <form method="post" onsubmit="return confirm('WARNING: Are you sure you want to clear ALL pending requests? This cannot be undone for auditing.');">
                    <button type="submit" name="clear_all" class="btn btn-sm btn-outline-danger">
                        <i class="fas fa-check-double me-1"></i> Mark All as Handled
                    </button>
                </form>
            </div>

            <div class="request-list">
                <?php foreach ($pending_requests as $request): ?>
                <div class="request-item">
                    <div class="d-flex align-items-center">
                        <div class="item-icon"><i class="fas fa-bell"></i></div>
                        <div class="item-details">
                            <strong><?= htmlspecialchars($request['full_name'] ?? $request['username'] ?? 'N/A') ?></strong>
                            <div class="item-meta">
                                Identifier: <?= htmlspecialchars($request['identifier']) ?> (User ID: <?= htmlspecialchars($request['user_id']) ?>)
                            </div>
                        </div>
                    </div>
                    <div class="item-action">
                        <span class="text-danger fw-bold me-3 d-inline-block">
                             <?= date('Y-m-d H:i:s', strtotime($request['request_time'])) ?>
                        </span>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                            <button type="submit" name="clear_single" class="btn btn-sm btn-accent" 
                                        title="Mark this single request as Handled">
                                <i class="fas fa-check"></i> Handled
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?= render_pagination($pending_page, $total_pending_pages, 'pp') ?>

            <?php else: ?>
                <div class="alert alert-success-custom alert-custom text-center" role="alert">
                    <i class="fas fa-handshake me-2"></i> Great job! No pending password reset requests from existing users.
                </div>
            <?php endif; ?>
        </div>
        
        <h3 class="h3-section text-muted"><i class="fas fa-history"></i> History (<?= $total_history_items ?> Handled)</h3>
        <div class="main-card">
            <?php if ($total_history_items > 0): ?>
            
            <div class="request-list">
                <?php foreach ($handled_requests as $request): ?>
                <div class="request-item">
                    <div class="d-flex align-items-center">
                        <div class="item-icon item-icon-history"><i class="fas fa-check"></i></div>
                        <div class="item-details">
                            <strong><?= htmlspecialchars($request['full_name'] ?? $request['username'] ?? 'N/A') ?></strong>
                            <div class="item-meta">
                                Identifier: <?= htmlspecialchars($request['identifier']) ?> (User ID: <?= htmlspecialchars($request['user_id'] ?? 'N/A') ?>)
                            </div>
                        </div>
                    </div>
                    <div class="item-status d-flex flex-column align-items-end">
                        <span class="badge bg-success mb-1">
                            <i class="fas fa-check-circle me-1"></i> Handled
                        </span>
                        <small class="text-muted">
                            <?= date('Y-m-d H:i:s', strtotime($request['request_time'])) ?>
                        </small>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?= render_pagination($history_page, $total_history_pages, 'hp') ?>

            <?php else: ?>
                <div class="alert alert-info-custom alert-custom text-center" role="alert">
                    <i class="fas fa-info-circle me-2"></i> No reset requests have been marked as handled yet.
                </div>
            <?php endif; ?>
        </div>        
    </div>
</div>
</div><!-- End main-container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Page Load Animation (Fade-In)
    document.addEventListener('DOMContentLoaded', () => {
        const mainContent = document.getElementById('main-content');
        // Add the 'loaded' class after the DOM is fully loaded to trigger the CSS transition
        mainContent.classList.add('loaded');
    });
</script>

</body>
</html>