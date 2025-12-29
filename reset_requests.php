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
<style>
/* ================================================= */
/* ===== SLEEK MINIMALIST THEME (TEAL/NAVY) ===== */
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
}

body { 
    background: var(--bg-primary); 
    color: var(--text-color); 
    font-family: 'Inter', sans-serif;
    min-height: 100vh;
    transition: background 0.5s ease, color 0.5s ease;
}

/* --- Navigation Bar --- */
.navbar {
    background: var(--nav-bg); 
    z-index: 1000;
    position: sticky;
    top: 0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    border-bottom: 1px solid var(--border-color);
}
.navbar-brand { 
    font-weight: 800; 
    color: var(--accent-color) !important; 
    font-size: 1.8rem;
    letter-spacing: -0.5px;
}
.welcome-text { color: var(--text-color); font-weight: 500; margin-right: 15px; }


/* --- Animated Button Styles --- */
.btn { transition: all 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275); } 
.btn:hover { transform: translateY(-1px); }
.btn:active { transform: scale(0.95); } 

/* Adjusted button styles to fit the Teal theme */
.btn-accent {
    background-color: var(--accent-color) !important;
    border-color: var(--accent-color) !important;
    color: #fff;
    font-weight: 600;
}
.btn-accent:hover {
    background-color: var(--accent-hover) !important;
    border-color: var(--accent-hover) !important;
}

/* --- Pagination Styles (use accent color) --- */
.page-link {
    color: var(--accent-color);
    background-color: var(--card-bg);
    border: 1px solid var(--border-color);
}
.page-item.active .page-link {
    background-color: var(--accent-color);
    border-color: var(--accent-color);
    color: white;
}
.page-link:hover {
    color: var(--accent-hover);
    /* Using color-mix for a subtle hover effect */
    background-color: color-mix(in srgb, var(--card-bg) 95%, var(--accent-color));
    border-color: var(--accent-color);
}


/* ------------------------------------------- */
/* ===== SMALL DARK MODE TOGGLE STYLING ===== */
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
}
.theme-switch { 
    height: 20px; 
    width: 38px; 
    position: relative;
    display: inline-block;
}
.theme-switch input { 
    display: none; 
}
.slider { 
    background-color: var(--text-muted-color); 
    bottom: 0; 
    cursor: pointer; 
    left: 0; 
    position: absolute; 
    right: 0; 
    top: 0; 
    transition: .4s; 
}
.slider.round { 
    border-radius: 34px; 
}
.slider.round:before { 
    border-radius: 50%; 
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
}
input:checked + .slider { 
    background-color: var(--accent-color); 
}
input:checked + .slider:before { 
    transform: translateX(19px); 
}
/* ------------------------------------------- */


/* ===== Main Content Area Styling (Animated Fade-In) ===== */
.main-content {
    opacity: 0; 
    transform: translateY(20px); 
    transition: opacity 0.8s ease-out, transform 0.8s ease-out;
    padding: 2rem 0;
}
.main-content.loaded {
    opacity: 1; 
    transform: translateY(0); 
}
.page-header {
    font-size: 2.5rem;
    font-weight: 800;
    color: var(--text-color);
    margin-bottom: 3rem;
    padding-bottom: 0.5rem;
    display: inline-block;
    /* Changed border color to Teal Accent */
    border-bottom: 4px solid var(--accent-color); 
    line-height: 1.2;
}
.h3-section {
    font-weight: 700;
    margin-top: 3rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 10px;
}
/* Highlight Pending with Error/Accent color for visibility */
.h3-section.text-danger { 
    color: var(--error-color) !important; 
    border-bottom: 1px solid var(--border-color);
    padding-bottom: 10px;
}

/* ===== Card & List Styling (Redesign Focus) */
.main-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    box-shadow: var(--shadow-light);
    padding: 1.5rem;
    transition: box-shadow 0.3s ease, transform 0.3s ease, background 0.5s ease;
}
.main-card:hover {
    box-shadow: var(--shadow-hover);
    transform: translateY(-2px);
}

/* --- Request List/Item Styling for Responsiveness --- */
.request-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 15px 0;
    border-bottom: 1px solid var(--border-color);
    transition: background-color 0.3s ease;
}
.request-item:last-child {
    border-bottom: none;
}
.request-item:hover {
    background-color: color-mix(in srgb, var(--card-bg) 95%, var(--accent-color));
}
.item-icon {
    width: 40px;
    height: 40px;
    min-width: 40px;
    border-radius: 50%;
    background-color: var(--error-color); /* Pending uses Error color for urgency */
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    margin-right: 15px;
}
.item-icon-history {
    background-color: var(--success-color); /* History uses Success color */
}
.item-details strong {
    display: block;
    font-weight: 600;
    font-size: 1.05rem;
}
.item-meta {
    font-size: 0.85rem;
    color: var(--text-muted-color);
}
.item-action, .item-status {
    text-align: right;
    width: 250px; 
    min-width: 150px;
}

/* --- RESPONSIVENESS: Mobile Layout Adjustments (Max 768px) --- */
@media (max-width: 768px) {
    .request-item {
        flex-direction: column; 
        align-items: flex-start;
        padding: 15px 0 10px 0; 
    }
    .item-icon {
        margin-right: 10px;
        margin-bottom: 10px; 
    }
    .item-details {
        margin-bottom: 15px;
    }
    .item-action, .item-status {
        width: 100%; 
        text-align: left;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    .item-action span {
        margin-right: 0 !important; 
        order: 2; 
    }
    .item-action form {
        order: 1; 
    }
    .item-status small {
        order: 2;
    }
    .item-status .badge {
        order: 1;
    }
}


/* --- Alert Styling --- */
@keyframes fadeInDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.alert-custom {
    animation: fadeInDown 0.5s ease-out forwards;
}
.alert-success-custom {
    background-color: var(--card-bg);
    color: var(--success-color);
    border: 1px solid var(--success-color);
    font-weight: 600;
}
.alert-danger-custom {
    background-color: var(--card-bg);
    color: var(--error-color);
    border: 1px solid var(--error-color);
    font-weight: 600;
}
.alert-info-custom {
    background-color: var(--card-bg);
    color: var(--accent-color);
    border: 1px solid var(--accent-color);
    font-weight: 600;
}
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg">
    <div class="container-fluid container">
        <a class="navbar-brand" href="admin_dashboard.php">
            <i class="fas fa-shield-alt me-2"></i>Vision Angles
        </a>
        <div class="d-flex align-items-center">
            
            
            <div class="theme-switch-wrapper me-3">
                <em id="theme-label">Dark Mode</em>
                <label class="theme-switch" for="theme-toggle">
                    <input type="checkbox" id="theme-toggle">
                    <span class="slider round"></span>
                </label>
            </div>
            
            </div>
    </div>
</nav>

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

        <div class="text-center mt-5 mb-4">
            <a href="admin_dashboard.php" class="btn btn-lg btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
            </a>
        </div>
        
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // JavaScript for theme toggling (Ensures theme state is saved/loaded)
    const themeToggle = document.getElementById('theme-toggle');
    const htmlElement = document.documentElement;

    function applyTheme(theme) {
        if (theme === 'dark') {
            htmlElement.classList.add('dark-mode');
            themeToggle.checked = true;
        } else {
            htmlElement.classList.remove('dark-mode');
            themeToggle.checked = false;
        }
    }

    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

    // Prioritize saved theme, then OS preference, otherwise default to 'light'
    const initialTheme = savedTheme || (prefersDark ? 'dark' : 'light');
    applyTheme(initialTheme);

    // Add event listener for the toggle switch
    themeToggle.addEventListener('change', () => {
        const newTheme = themeToggle.checked ? 'dark' : 'light';
        applyTheme(newTheme);
        localStorage.setItem('theme', newTheme);
    });

    // Page Load Animation (Fade-In)
    document.addEventListener('DOMContentLoaded', () => {
        const mainContent = document.getElementById('main-content');
        // Add the 'loaded' class after the DOM is fully loaded to trigger the CSS transition
        mainContent.classList.add('loaded');
    });
</script>

</body>
</html>