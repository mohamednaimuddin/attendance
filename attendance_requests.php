<?php
// attendance_requests.php

/**
 * ATTENDANCE CORRECTION REQUESTS PAGE (ADMIN)
 * - Retains full Approve/Reject/Cancel Approval logic.
 * - Applies the sleek minimalist dashboard theme (including Dark Mode toggle CSS).
 * - Enhanced UI/UX with badges for Status and Correction Type.
 * - NEW: Implements Pagination to show 10 requests per page.
 */

require 'config.php';
// require_admin(); // Ensure only admins can access this page (assuming this function exists elsewhere)

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

$message = $_SESSION['request_message'] ?? '';
unset($_SESSION['request_message']);

// --- PAGINATION & FILTER SETUP ---
$limit = 10;
$current_page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($current_page - 1) * $limit;

// --- GET AND SANITIZE FILTERS ---
$filter_user_id = $_GET['user_id'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_date = $_GET['correction_date'] ?? '';

// --- ACTION HANDLING: APPROVE / REJECT / CANCEL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'], $_POST['action'])) {
    $request_id = $_POST['request_id'];
    $action = $_POST['action'];
    
    // Capture filters and page from hidden inputs for redirect
    $post_filter_user_id = $_POST['user_id'] ?? '';
    $post_filter_status = $_POST['status'] ?? '';
    $post_filter_date = $_POST['correction_date'] ?? '';
    $post_current_page = $_POST['page'] ?? 1;

    try {
        $pdo->beginTransaction();

        // 1. Fetch the request details
        $stmt = $pdo->prepare("SELECT * FROM correction_requests WHERE id = ?");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new Exception("Request not found.");
        }
        
        $user_id = $request['user_id'];
        $attendance_id = $request['attendance_id'];
        $correction_date = $request['correction_date'];
        
        $new_check_in = $request['new_check_in']; // TIME value (e.g., '08:00:00')
        $new_check_out = $request['new_check_out']; // TIME value (e.g., '17:00:00')

        // Assume admin ID is stored in session
        $admin_id = $_SESSION['user_id'] ?? NULL;

        if ($action === 'APPROVE') {
            
            $target_attendance_id = $attendance_id;
            $original_check_in_datetime = NULL;
            $original_check_out_datetime = NULL;

            // If attendance_id is null, check for an existing record on that day.
            if (empty($target_attendance_id)) {
                $stmt_check = $pdo->prepare("SELECT id, check_in, check_out FROM attendance 
                                             WHERE user_id = ? AND DATE(check_in) = ? 
                                             ORDER BY check_in DESC LIMIT 1");
                $stmt_check->execute([$user_id, $correction_date]);
                $existing_attendance = $stmt_check->fetch(PDO::FETCH_ASSOC);
                
                if ($existing_attendance) {
                    $target_attendance_id = $existing_attendance['id'];
                    $original_check_in_datetime = $existing_attendance['check_in'];
                    $original_check_out_datetime = $existing_attendance['check_out'];
                }
            } else {
                // If attendance_id exists, fetch the current values before modification
                $stmt_fetch_original = $pdo->prepare("SELECT check_in, check_out FROM attendance WHERE id = ?");
                $stmt_fetch_original->execute([$target_attendance_id]);
                $original_attendance = $stmt_fetch_original->fetch(PDO::FETCH_ASSOC);

                if ($original_attendance) {
                    $original_check_in_datetime = $original_attendance['check_in'];
                    $original_check_out_datetime = $original_attendance['check_out'];
                }
            }

            // --- Apply Correction Logic ---
            if ($target_attendance_id) {
                // UPDATE existing record
                $updates = [];
                $params_update = []; // Renamed variable to avoid conflict
                
                if (!empty($new_check_in)) {
                    $updates[] = "check_in = ?";
                    $params_update[] = $correction_date . ' ' . $new_check_in;
                    // Update check_in_store if store location is provided
                    if (!empty($request['store_location'])) {
                        $updates[] = "check_in_store = ?";
                        $params_update[] = $request['store_location'];
                    }
                }
                if (!empty($new_check_out)) {
                    $updates[] = "check_out = ?";
                    $params_update[] = $correction_date . ' ' . $new_check_out;
                    // Update check_out_store if store location is provided
                    if (!empty($request['store_location'])) {
                        $updates[] = "check_out_store = ?";
                        $params_update[] = $request['store_location'];
                    }
                }
                
                // Always mark the record as edited when corrected
                $updates[] = "edited = ?";
                $params_update[] = 1;
                
                if (!empty($updates)) {
                    $sql_update = "UPDATE attendance SET " . implode(', ', $updates) . " WHERE id = ?";
                    $params_update[] = $target_attendance_id;
                    $pdo->prepare($sql_update)->execute($params_update);
                }
            } else {
                // INSERT new record
                $columns = ['user_id'];
                $placeholders = ['?'];
                $params_insert = [$user_id]; // Renamed variable to avoid conflict
                
                if (!empty($new_check_in)) {
                    $columns[] = 'check_in';
                    $placeholders[] = '?';
                    $params_insert[] = $correction_date . ' ' . $new_check_in;
                    // Add check_in_store if store location is provided
                    if (!empty($request['store_location'])) {
                        $columns[] = 'check_in_store';
                        $placeholders[] = '?';
                        $params_insert[] = $request['store_location'];
                    }
                }
                if (!empty($new_check_out)) {
                    $columns[] = 'check_out';
                    $placeholders[] = '?';
                    $params_insert[] = $correction_date . ' ' . $new_check_out;
                    // Add check_out_store if store location is provided
                    if (!empty($request['store_location'])) {
                        $columns[] = 'check_out_store';
                        $placeholders[] = '?';
                        $params_insert[] = $request['store_location'];
                    }
                }
                
                // Always mark new corrected records as edited
                $columns[] = 'edited';
                $placeholders[] = '?';
                $params_insert[] = 1;
                
                if (count($columns) > 1) { // Ensure at least one time value is being inserted
                    $sql_insert = "INSERT INTO attendance (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
                    $pdo->prepare($sql_insert)->execute($params_insert);
                    $target_attendance_id = $pdo->lastInsertId(); // Capture the new ID
                }
                
                // For an INSERT, the original values are explicitly NULL
                $original_check_in_datetime = NULL;
                $original_check_out_datetime = NULL;
            }

            // Mark request as Approved and **STORE ORIGINAL VALUES**
            $stmt_update_request = $pdo->prepare("
                UPDATE correction_requests 
                SET 
                    status = 'APPROVED', 
                    admin_id = ?, 
                    attendance_id = ?, 
                    original_check_in = ?, 
                    original_check_out = ? 
                WHERE id = ?
            ");
            $stmt_update_request->execute([
                $admin_id, 
                $target_attendance_id, 
                $original_check_in_datetime, 
                $original_check_out_datetime, 
                $request_id
            ]);
            
            $_SESSION['request_message'] = "Request ID {$request_id} has been APPROVED and attendance updated. ✅";

        } elseif ($action === 'REJECT') {
            // Mark request as Rejected (no changes to the attendance table are needed)
            $stmt_update_request = $pdo->prepare("UPDATE correction_requests SET status = 'REJECTED', admin_id = ? WHERE id = ?");
            $stmt_update_request->execute([$admin_id, $request_id]);
            $_SESSION['request_message'] = "Request ID {$request_id} has been REJECTED. ❌";

        } elseif ($action === 'CANCEL_APPROVAL') {
            
            // Check if the request was approved and has a target attendance ID
            if ($request['status'] !== 'APPROVED' || empty($request['attendance_id'])) {
                 throw new Exception("Cannot cancel approval: Request was not previously approved or is missing attendance ID.");
            }

            $target_attendance_id = $request['attendance_id'];
            $revert_check_in = $request['original_check_in']; // DATETIME or NULL
            $revert_check_out = $request['original_check_out']; // DATETIME or NULL

            // --- REVERT ATTENDANCE ENTRY LOGIC ---
            if ($revert_check_in === NULL && $revert_check_out === NULL) {
                // Case: The original record was an INSERT (both original times are NULL). Revert means DELETE.
                $sql_revert = "DELETE FROM attendance WHERE id = ?";
                $pdo->prepare($sql_revert)->execute([$target_attendance_id]);
            } else {
                // Case: The original record was an UPDATE. Revert means UPDATE back to original values (including NULLs).
                $revert_updates = [];
                $revert_params = [];
                
                // Use placeholders for non-NULL values, and inline NULL for NULL values
                $revert_updates[] = "check_in = " . ($revert_check_in === NULL ? 'NULL' : '?');
                if ($revert_check_in !== NULL) {
                    $revert_params[] = $revert_check_in;
                }

                $revert_updates[] = "check_out = " . ($revert_check_out === NULL ? 'NULL' : '?');
                if ($revert_check_out !== NULL) {
                    $revert_params[] = $revert_check_out;
                }

                $sql_revert = "UPDATE attendance SET " . implode(', ', $revert_updates) . " WHERE id = ?";
                $revert_params[] = $target_attendance_id;
                
                if (!empty($revert_updates)) {
                    $pdo->prepare($sql_revert)->execute($revert_params);
                }
            }
            // --- END REVERT ATTENDANCE ENTRY LOGIC ---

            // Mark request as PENDING
            $stmt_update_request = $pdo->prepare("UPDATE correction_requests SET status = 'PENDING', admin_id = ?, original_check_in = NULL, original_check_out = NULL WHERE id = ?");
            $stmt_update_request->execute([$admin_id, $request_id]);
            
            $_SESSION['request_message'] = "Approval for Request ID {$request_id} has been CANCELLED and the attendance entry was REVERTED to its original state. ↩️";
        }

        $pdo->commit();
        
        // Redirect back to the page, preserving filters and page number from POST
        $redirect_params = http_build_query([
            'user_id' => $post_filter_user_id,
            'status' => $post_filter_status,
            'correction_date' => $post_filter_date,
            'page' => $post_current_page // Preserve the current page after action
        ]);
        header("Location: attendance_requests.php?" . $redirect_params);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "<div class='alert alert-danger'>Error processing request: " . $e->getMessage() . "</div>";
    }
}
// --- END ACTION HANDLING ---

// --- FETCH ALL REQUESTS (WITH FILTERS AND PAGINATION) ---
$sql_where = [];
$bind_params = []; // This array now holds named parameters

// 1. Build the WHERE clause using NAMED placeholders (e.g., :user_id, :status)
if (!empty($filter_user_id)) {
    $sql_where[] = "cr.user_id = :user_id";
    $bind_params[':user_id'] = $filter_user_id;
}

if (!empty($filter_status)) {
    $sql_where[] = "cr.status = :status";
    $bind_params[':status'] = $filter_status;
}

if (!empty($filter_date)) {
    $sql_where[] = "cr.correction_date = :date";
    $bind_params[':date'] = $filter_date;
}


$where_clause = '';
if (!empty($sql_where)) {
    $where_clause = ' WHERE ' . implode(' AND ', $sql_where);
}

// 1. Get the TOTAL COUNT of filtered records
$count_query = "
    SELECT COUNT(cr.id) AS total_requests
    FROM correction_requests cr
    " . $where_clause;
$stmt_count = $pdo->prepare($count_query);
// Execute count query using the associative named parameter array ($bind_params)
$stmt_count->execute($bind_params); 
$total_requests = $stmt_count->fetchColumn();

$total_pages = ceil($total_requests / $limit);
// Re-check current page in case the filter caused the page to become invalid
$current_page = max(1, min($current_page, $total_pages > 0 ? $total_pages : 1));
$offset = ($current_page - 1) * $limit;

// 2. Fetch the REQUESTS for the current page
$requests_query = "
    SELECT 
        cr.*, 
        u.full_name AS user_name,
        a.full_name AS admin_name
    FROM correction_requests cr
    JOIN users u ON cr.user_id = u.id
    LEFT JOIN users a ON cr.admin_id = a.id
    " . $where_clause . "
    ORDER BY FIELD(cr.status, 'PENDING', 'APPROVED', 'REJECTED'), cr.created_at DESC
    LIMIT :limit OFFSET :offset
";

// --- START OF CORRECTED BINDING LOGIC (Pure Named) ---

// Line 297: The prepare call
$stmt = $pdo->prepare($requests_query);

// Combine all named parameters (filters + limit/offset) into one final array
$final_bind_params = array_merge(
    $bind_params,
    [':limit' => $limit, ':offset' => $offset]
);

// Execute the statement using the single, comprehensive associative array.
// This handles all binding automatically, eliminating the risk of positional mismatch.
$stmt->execute($final_bind_params);

$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- END OF CORRECTED BINDING LOGIC ---

// --- FETCH LIST OF NON-ADMIN USERS FOR FILTER DROPDOWN ---
// NOTE: Assumes 'is_admin' column exists and 0 means non-admin
$users_query = "SELECT id, full_name FROM users WHERE is_admin = 0 ORDER BY full_name";
$users = $pdo->query($users_query)->fetchAll(PDO::FETCH_ASSOC);

// Helper function to build the URL for pagination links, preserving filters
function build_pagination_url($page, $filter_user_id, $filter_status, $filter_date) {
    $params = [
        'page' => $page,
        'user_id' => $filter_user_id,
        'status' => $filter_status,
        'correction_date' => $filter_date
    ];
    // Remove empty parameters
    $params = array_filter($params);
    return 'attendance_requests.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Correction Requests - Visionangles</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
            --pending-color: #ffc107;
            --approved-color: #2ecc71;
            --rejected-color: #e74c3c;
            --border-color: rgba(0, 0, 0, 0.08);
            --shadow-subtle: 0 1px 3px rgba(0,0,0,0.05);
            transition: all 0.5s ease;
        }

        /* ===== Dark Mode Variables (High Contrast) ===== */
        html.dark-mode {
            --text-color: #f8f9fa;
            --bg-primary: #1b2029; /* Deep Slate Background */
            --card-bg: #29313d; /* Darker Card Background */
            --accent-color: #4db6ac; /* Lighter Teal Accent */
            --accent-hover: #26a69a; 
            --border-color: rgba(255, 255, 255, 0.1);
            --shadow-subtle: 0 1px 5px rgba(0,0,0,0.5);
            /* Adjust status colors for dark mode visibility */
            --pending-color: #ffeb3b; 
            --approved-color: #69f0ae;
            --rejected-color: #ff8a80; 
        }

        body { 
            background: var(--bg-primary); 
            color: var(--text-color); 
            font-family: 'Inter', sans-serif; 
        }
        
        /* --- General Layout --- */
        .container-fluid { 
            max-width: 1400px; 
            padding-top: 2rem; 
            padding-bottom: 2rem;
        }
        
        /* --- Header and Title --- */
        .dashboard-header {
            background-color: var(--card-bg);
            border-radius: 12px;
            box-shadow: 0 6px 15px rgba(0,0,0,0.07); /* Deeper shadow */
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
            transition: all 0.5s ease;
        }
        .dashboard-header h1 { 
            color: var(--accent-color); 
            font-weight: 800;
            margin: 0;
            font-size: 1.8rem;
        }

        /* --- Filter Card --- */
        .filter-card {
            background: var(--card-bg);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            padding: 20px;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
            transition: all 0.5s ease;
        }
        .filter-card .form-label {
            font-size: 0.85rem;
            color: var(--text-color);
            opacity: 0.8;
            margin-bottom: 0.2rem;
        }
        /* Style form elements for better dark mode visibility */
        .form-select, .form-control {
            background-color: var(--bg-primary); 
            color: var(--text-color);
            border-color: var(--border-color);
        }
        html.dark-mode .form-select, html.dark-mode .form-control {
            background-color: var(--bg-primary); 
            border-color: rgba(255, 255, 255, 0.2);
        }
        
        /* --- Table Card and Styling --- */
        .table-card { 
            background: var(--card-bg); 
            border-radius: 12px; 
            box-shadow: 0 4px 10px rgba(0,0,0,0.05); 
            padding: 20px; 
            border: 1px solid var(--border-color);
            transition: all 0.5s ease;
        }
        .table {
            --bs-table-bg: var(--card-bg);
            --bs-table-color: var(--text-color);
        }
        .table thead th {
            font-weight: 700;
            color: var(--text-color);
            background-color: rgba(0, 150, 136, 0.1); /* Light Teal header background */
            border-bottom: 2px solid var(--accent-color);
        }
        .table tbody tr {
            transition: background-color 0.2s ease-in-out;
            border-bottom: 1px solid var(--border-color);
        }
        .table tbody tr:hover {
            background-color: var(--bg-primary);
        }
        /* Highlight Pending rows subtly */
        .table-light {
            --bs-table-bg: var(--bg-primary);
        }

        /* Style for the hidden ID column on desktop */
        .col-id-hidden {
            display: none;
        }

        /* --- Badges --- */
        .status-badge { 
            padding: 0.4em 0.8em; 
            border-radius: 20px; 
            font-weight: 600; 
            font-size: 0.8rem;
            display: inline-block;
            text-transform: uppercase;
        }
        .status-PENDING { background-color: #fff9e6; color: #a07a00; border: 1px solid var(--pending-color); }
        .status-APPROVED { background-color: var(--approved-color); color: white; }
        .status-REJECTED { background-color: var(--rejected-color); color: white; }
        html.dark-mode .status-PENDING { background-color: rgba(255, 193, 7, 0.2); color: var(--pending-color); border: none; }
        html.dark-mode .status-APPROVED { background-color: var(--approved-color); color: #1b2029; }
        html.dark-mode .status-REJECTED { background-color: var(--rejected-color); color: #1b2029; }


        .type-badge {
            padding: 0.3em 0.6em;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 700;
            display: inline-block;
            text-transform: uppercase;
        }
        /* Consistent, themed type badges */
        .type-CHECKIN { background-color: #e8f5e9; color: #388e3c; } 
        .type-CHECKOUT { background-color: #ffe0b2; color: #f57f17; } 
        .type-INOUT { background-color: #e3f2fd; color: #1e88e5; } 
        html.dark-mode .type-CHECKIN { background-color: rgba(76, 175, 80, 0.2); color: #a5d6a7; } 
        html.dark-mode .type-CHECKOUT { background-color: rgba(255, 152, 0, 0.2); color: #ffcc80; } 
        html.dark-mode .type-INOUT { background-color: rgba(33, 150, 243, 0.2); color: #90caf9; } 
        
        /* --- Correction Time Display --- */
        .correction-time .d-block {
            font-size: 0.85rem;
            line-height: 1.2;
        }
        /* Ensure success/danger text colors respect dark mode */
        .text-success.fw-bold { color: var(--approved-color) !important; }
        .text-danger.fw-bold { color: var(--rejected-color) !important; }

        /* --- Pagination Styling --- */
        .pagination .page-link {
            color: var(--text-color);
            background-color: var(--card-bg);
            border-color: var(--border-color);
        }
        .pagination .page-item.active .page-link {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
            color: white;
        }
        .pagination .page-link:hover {
            background-color: var(--bg-primary);
            color: var(--accent-color);
        }
        html.dark-mode .pagination .page-link {
            color: var(--text-color);
            background-color: var(--card-bg);
            border-color: var(--border-color);
        }
        html.dark-mode .pagination .page-link:hover {
            background-color: var(--bg-primary);
            color: var(--accent-color);
        }


        /* --- Mobile Adjustments (Improved) --- */
        @media (max-width: 991px) {
            .table thead { display: none; }
            .table tbody tr { 
                display: block; 
                margin-bottom: 1rem; 
                border: 1px solid var(--border-color); 
                border-radius: 8px; 
                padding: 10px;
            }
            .table tbody tr td { 
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0.5rem 0; 
                border-top: none; 
            }
            .table tbody tr td::before {
                content: attr(data-label);
                font-weight: bold;
                color: var(--text-color);
                opacity: 0.7;
                text-align: left;
                flex-basis: 45%;
                font-size: 0.9rem;
            }
            /* Show the hidden ID column label/value on mobile for context */
            td[data-label="Request ID:"] { display: flex !important; }
            .col-id-hidden { display: table-cell; }
        }
    </style>
</head>
<body>

<div class="container-fluid">
    
    <div class="dashboard-header d-flex flex-wrap justify-content-between align-items-center">
        <h1><i class="fa-solid fa-clock-rotate-left me-2" style="color: var(--accent-color);"></i> Attendance Corrections</h1>
        <a href="admin_dashboard.php" class="btn btn-outline-secondary mt-2 mt-md-0" style="--bs-btn-border-color: var(--accent-color); --bs-btn-color: var(--accent-color);">
            <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
        </a>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= strpos($message, 'Error') !== false ? 'alert-danger' : 'alert-success' ?> alert-dismissible fade show" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="filter-card">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-12 col-lg-3 col-md-6">
                <label for="user_id" class="form-label fw-bold">Employee Name</label>
                <select name="user_id" id="user_id" class="form-select form-select-sm">
                    <option value="">All Employees</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['id'] ?>" <?= ($filter_user_id == $user['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-lg-3 col-md-6">
                <label for="correction_date" class="form-label fw-bold">Date Requested</label>
                <input type="date" name="correction_date" id="correction_date" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date) ?>">
            </div>
            <div class="col-12 col-lg-3 col-md-6">
                <label for="status" class="form-label fw-bold">Request Status</label>
                <select name="status" id="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="PENDING" <?= ($filter_status === 'PENDING') ? 'selected' : '' ?>>Pending</option>
                    <option value="APPROVED" <?= ($filter_status === 'APPROVED') ? 'selected' : '' ?>>Approved</option>
                    <option value="REJECTED" <?= ($filter_status === 'REJECTED') ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>
            <div class="col-12 col-lg-3 col-md-6 d-flex justify-content-end justify-content-md-start">
                <button type="submit" class="btn btn-primary btn-sm me-2" style="background-color: var(--accent-color); border-color: var(--accent-color);"><i class="fas fa-filter me-1"></i> Apply Filter</button>
                <a href="attendance_requests.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-eraser me-1"></i> Clear</a>
            </div>
        </form>
    </div>
    
    <div class="table-card">
        <?php if (empty($requests)): ?>
            <div class="alert alert-info text-center py-4">
                <i class="fas fa-inbox fa-2x d-block mb-2"></i>
                No attendance correction requests found matching the current filters.
            </div>
        <?php else: ?>
            <p class="text-muted small mb-3">Showing <?= $offset + 1 ?> - <?= min($offset + $limit, $total_requests) ?> of <?= $total_requests ?> requests.</p>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead>
                        <tr>
                            <th class="col-id-hidden">ID</th> 
                            <th>Employee</th>
                            <th>Date</th>
                            <th>Correction Details</th>
                            <th class="d-none d-md-table-cell">Location</th>
                            <th>Reason & Request Time</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $request): 
                            // --- LOGIC TO DETERMINE TYPE AND TIME FOR DISPLAY ---
                            $request_type_display = '';
                            $request_type_class = 'N-A';
                            $correction_time_in = !empty($request['new_check_in']) ? date('h:i A', strtotime($request['new_check_in'])) : '';
                            $correction_time_out = !empty($request['new_check_out']) ? date('h:i A', strtotime($request['new_check_out'])) : '';
                            $store_name_display = 'N/A'; 

                            if (!empty($request['new_check_in']) && !empty($request['new_check_out'])) {
                                $request_type_display = 'IN & OUT';
                                $request_type_class = 'INOUT';
                            } elseif (!empty($request['new_check_in'])) {
                                $request_type_display = 'Check-In';
                                $request_type_class = 'CHECKIN';
                            } elseif (!empty($request['new_check_out'])) {
                                $request_type_display = 'Check-Out';
                                $request_type_class = 'CHECKOUT';
                            }
                            
                            if (!empty($request['store_location'])) {
                                $store_name_display = htmlspecialchars($request['store_location']);
                            }
                            // --- END LOGIC ---
                        ?>
                        <tr class="<?= $request['status'] === 'PENDING' ? 'table-light' : '' ?>">
                            <td data-label="Request ID:" class="col-id-hidden">#<?= $request['id'] ?></td>
                            <td data-label="Employee:">
                                <span class="fw-bold text-dark" style="color: var(--text-color) !important;"><?= htmlspecialchars($request['user_name']) ?></span>
                            </td>
                            <td data-label="Date:">
                                <span class="badge bg-light text-dark fw-bold border border-secondary-subtle" style="background-color: var(--bg-primary) !important; color: var(--text-color) !important;"><?= date('Y-m-d', strtotime($request['correction_date'])) ?></span>
                            </td>
                            <td data-label="Correction Details:">
                                <span class="type-badge type-<?= $request_type_class ?> mb-1">
                                    <?= $request_type_display ?>
                                </span>
                                <div class="small correction-time">
                                    <?php if ($correction_time_in): ?>
                                        <span class="d-block text-success fw-bold">IN: <?= $correction_time_in ?></span>
                                    <?php endif; ?>
                                    <?php if ($correction_time_out): ?>
                                        <span class="d-block text-danger fw-bold">OUT: <?= $correction_time_out ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td data-label="Location:" class="d-none d-md-table-cell text-muted small">
                                <?= $store_name_display ?>
                            </td>
                            <td data-label="Reason & Request Time:">
                                <p class="mb-0 small text-truncate" data-bs-toggle="tooltip" data-bs-placement="top" title="<?= htmlspecialchars($request['reason']) ?>">
                                    <?= htmlspecialchars(substr($request['reason'], 0, 70)) . (strlen($request['reason']) > 70 ? '...' : '') ?>
                                </p>
                                <small class="text-muted d-block mt-1">
                                    Submitted: <?= date('M j, Y h:i A', strtotime($request['created_at'])) ?>
                                </small>
                            </td>
                            <td data-label="Status:">
                                <span class="status-badge status-<?= $request['status'] ?>">
                                    <?= ucfirst(strtolower($request['status'])) ?>
                                </span>
                                <?php if ($request['status'] !== 'PENDING' && $request['admin_name']): ?>
                                    <small class="d-block text-muted mt-1">by <?= htmlspecialchars($request['admin_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td data-label="Actions:">
                                <form method="post" class="action-dropdown" onsubmit="return confirm('Confirm action: <?= $request['status'] === 'APPROVED' ? 'Undo Approval and Revert Attendance?' : 'Process this request?' ?>');">
                                    <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                    <input type="hidden" name="user_id" value="<?= htmlspecialchars($filter_user_id) ?>">
                                    <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                                    <input type="hidden" name="correction_date" value="<?= htmlspecialchars($filter_date) ?>">
                                    <input type="hidden" name="page" value="<?= $current_page ?>">

                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="View Options">
                                            <i class="fas fa-cogs"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            
                                            <?php if ($request['status'] === 'PENDING'): ?>
                                                <li>
                                                    <button type="submit" name="action" value="APPROVE" class="dropdown-item text-success">
                                                        <i class="fas fa-check me-2"></i> Approve
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="submit" name="action" value="REJECT" class="dropdown-item text-danger">
                                                        <i class="fas fa-times me-2"></i> Reject
                                                    </button>
                                                </li>
                                            <?php elseif ($request['status'] === 'APPROVED'): ?>
                                                <li>
                                                    <button type="submit" name="action" value="CANCEL_APPROVAL" class="dropdown-item text-warning">
                                                        <i class="fas fa-undo me-2"></i> Undo Approval
                                                    </button>
                                                </li>
                                            <?php else: // REJECTED ?>
                                                <li><span class="dropdown-item text-muted small">Rejected (No further action)</span></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= build_pagination_url($current_page - 1, $filter_user_id, $filter_status, $filter_date) ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= ($current_page == $i) ? 'active' : '' ?>">
                                <a class="page-link" href="<?= build_pagination_url($i, $filter_user_id, $filter_status, $filter_date) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= build_pagination_url($current_page + 1, $filter_user_id, $filter_status, $filter_date) ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// --- Dark Mode Script (Preserved from original code) ---
const htmlElement = document.documentElement;

function applyTheme(theme) {
    if(theme === 'dark') {
        htmlElement.classList.add('dark-mode');
    } else {
        htmlElement.classList.remove('dark-mode');
    }
}

// Apply theme on load (you'd typically add a toggle switch to the admin dashboard if one isn't present)
const savedTheme = localStorage.getItem('theme');
const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
applyTheme(savedTheme || (prefersDark ? 'dark' : 'light'));

// Initialize tooltips
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
  return new bootstrap.Tooltip(tooltipTriggerEl)
})
</script>

</body>
</html>