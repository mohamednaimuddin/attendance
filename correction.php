<?php
/**
 * ATTENDANCE CORRECTION REQUEST PAGE
 * Allows a user to request a manual correction for a missed check-in or check-out.
 * THEME: Sleek Minimalist (Teal/Navy) - Optimized for maximum compactness and minimal spacing.
 * MODIFICATIONS:
 * - All margins/paddings aggressively reduced (e.g., .5rem, .25rem).
 * - Base font size reduced.
 * - Card padding minimized.
 * - Branding bar height reduced.
 * - Table font size and padding further reduced.
 */

require 'config.php';
// Placeholder PDO setup for demonstration if not in config.php
if (!isset($pdo)) {
    $pdo = new class {
        public function prepare($sql) {
            return new class($sql) {
                private $sql;
                public function __construct($sql) { $this->sql = $sql; }
                public function execute($params = []) {
                    // Mock data for finding existing attendance
                    if (str_contains($this->sql, 'SELECT id FROM attendance')) {
                        return new class {
                            public function fetch(int $fetch_style = 0) {
                                // Mock returning an existing ID for the date
                                return ['id' => 123];
                            }
                        };
                    }
                    return true;
                }
                public function fetchAll() {
                    // Mock request history for demonstration (5 entries, we'll only show 3)
                    return [
                        ['id' => 5, 'user_id' => 1, 'attendance_id' => 125, 'correction_date' => '2025-10-14', 'store_location' => 'Store E', 'new_check_in' => '10:00:00', 'new_check_out' => null, 'reason' => 'Forgot to check in.', 'status' => 'PENDING', 'created_at' => '2025-10-14 10:05:00'],
                        ['id' => 4, 'user_id' => 1, 'attendance_id' => null, 'correction_date' => '2025-10-13', 'store_location' => 'Store D', 'new_check_in' => '09:00:00', 'new_check_out' => '18:00:00', 'reason' => 'Whole day missed.', 'status' => 'REJECTED', 'created_at' => '2025-10-13 18:30:00'],
                        ['id' => 3, 'user_id' => 1, 'attendance_id' => 124, 'correction_date' => '2025-10-12', 'store_location' => 'Store C', 'new_check_in' => null, 'new_check_out' => '17:30:00', 'reason' => 'Phone battery died.', 'status' => 'APPROVED', 'created_at' => '2025-10-12 18:00:00'],
                        ['id' => 2, 'user_id' => 1, 'attendance_id' => 123, 'correction_date' => '2025-10-11', 'store_location' => 'Store B', 'new_check_in' => '08:00:00', 'new_check_out' => null, 'reason' => 'Forgot check-in.', 'status' => 'APPROVED', 'created_at' => '2025-10-11 10:00:00'],
                        ['id' => 1, 'user_id' => 1, 'attendance_id' => 122, 'correction_date' => '2025-10-10', 'store_location' => 'Store A', 'new_check_in' => null, 'new_check_out' => '19:00:00', 'reason' => 'Traffic delay.', 'status' => 'PENDING', 'created_at' => '2025-10-10 19:30:00'],
                    ];
                }
                public function fetch() { return null; }
            };
        }
    };
}


if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Check if user is logged in
if (!isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit; 
}

// DEFINE COMPANY NAME FOR BRANDING BAR
$company_name = "VISION ANGLES SECURITY"; 

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Handle POST request for submitting correction (Logic remains unchanged)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_type = $_POST['request_type'] ?? '';
    $correction_date = $_POST['correction_date'] ?? '';
    $correction_time = $_POST['correction_time'] ?? '';
    $store_name = $_POST['store_name'] ?? ''; // New data for 'store_location'
    $reason = $_POST['reason'] ?? '';
    
    $new_check_in = null;
    $new_check_out = null;
    $attendance_id = null;

    // 1. Simple validation
    if (empty($request_type) || empty($correction_date) || empty($correction_time) || empty($store_name) || empty($reason)) {
        // Validation now includes reason
        $error = "All required fields must be filled.";
    } elseif (!in_array($request_type, ['CHECK_IN', 'CHECK_OUT'])) {
        $error = "Invalid request type selected.";
    } else {
        try {
            $full_datetime = $correction_date . ' ' . $correction_time . ':00';

            if ($request_type === 'CHECK_IN') {
                $new_check_in = $full_datetime;
            } elseif ($request_type === 'CHECK_OUT') {
                $new_check_out = $full_datetime;
            }

            // 2. Find existing attendance record (if any) for the requested date
            $stmt_find = $pdo->prepare("
                SELECT id 
                FROM attendance 
                WHERE user_id = ? AND DATE(check_in) = ? 
                ORDER BY check_in DESC 
                LIMIT 1
            ");
            $stmt_find->execute([$user_id, $correction_date]);
            $existing_attendance = $stmt_find->fetch(PDO::FETCH_ASSOC);

            if ($existing_attendance) {
                $attendance_id = $existing_attendance['id'];
            }
            
            // 3. Insert the request into the correction_requests table
            $stmt = $pdo->prepare("
                INSERT INTO correction_requests (
                    user_id, 
                    attendance_id, 
                    correction_date, 
                    store_location, 
                    new_check_in, 
                    new_check_out, 
                    reason
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $user_id, 
                $attendance_id, 
                $correction_date, 
                $store_name,
                $new_check_in, 
                $new_check_out,
                $reason
            ]);

            $_SESSION['success_message'] = "Attendance correction request submitted successfully! It is now pending admin review.";
            header("Location: dashboard.php");
            exit;

        } catch (PDOException $e) {
            $error = "Database error: Could not submit request. " . $e->getMessage();
        }
    }
}

// --- FETCH REQUEST HISTORY (MODIFIED TO LIMIT 3) ---
try {
    // Added LIMIT 3 to the SQL query
    $stmt = $pdo->prepare("SELECT * FROM correction_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 3");
    $stmt->execute([$user_id]);
    $requests = $stmt->fetchAll();
} catch (PDOException $e) {
    $history_error = "Could not load request history.";
    $requests = [];
}
// -----------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Correction Request - Visionangles</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* ================================================= */
        /* ===== SLEEK MINIMALIST THEME (TEAL/NAVY) - COMPACT V3 (THEMED STATUS/BUTTON) ===== */
        /* ================================================= */

        /* --- Global Variables (Light Mode Default) --- */
        :root {
            --text-color: #34495e; 
            --bg-primary: #f8f9fa; 
            --card-bg: #ffffff;
            --accent-color: #009688; /* Primary Teal */
            --accent-hover: #00796b; 
            --danger: #e74c3c; 
            --success: #2ecc71; 
            --warning: #f39c12; 
            --navy-blue: #1c2833; /* Deep Navy */
            --border-color: rgba(0, 0, 0, 0.08);
            --shadow-subtle: 0 1px 3px rgba(0,0,0,0.05);
            --input-bg-color: #f2f2f2;
            --font-size-base: 0.9rem; /* REDUCED BASE FONT SIZE */

            /* --- THEME-SPECIFIC STATUS COLORS (Teal/Navy Palette) --- */
            --status-pending-bg: #e0f2f1; /* Very light Teal background */
            --status-pending-text: #00796b; /* Dark Teal text */
            --status-approved-bg: #2ecc71; /* Corporate Success Green */
            --status-rejected-bg: #e74c3c; /* Corporate Danger Red */
        }

        html.dark-mode {
            /* ... (Dark mode variables remain mostly the same for color, but inherit font size) ... */
            --text-color: #f8f9fa;
            --bg-primary: #1b2029;
            --card-bg: #29313d; 
            --accent-color: #4db6ac; 
            --accent-hover: #26a69a; 
            --danger: #ff8a80; 
            --success: #69f0ae;
            --warning: #ffc400; 
            --border-color: rgba(255, 255, 255, 0.1);
            --shadow-subtle: 0 1px 5px rgba(0,0,0,0.5);
            --input-bg-color: #343a40;
            
            /* --- DARK MODE THEME-SPECIFIC STATUS COLORS --- */
            --status-pending-bg: #1c2833; /* Dark Navy background */
            --status-pending-text: #4db6ac; /* Light Teal text */
            --status-approved-bg: #69f0ae; /* Light Green */
            --status-rejected-bg: #ff8a80; /* Light Red */
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background: var(--bg-primary); 
            color: var(--text-color); 
            min-height: 100vh;
            /* AGGRESSIVELY REDUCED PADDING for fixed bar and compact layout */
            padding-top: 3.5rem; /* Tighter than 4.5rem */
            padding-bottom: 0.25rem; 
            font-size: var(--font-size-base); /* APPLY REDUCED BASE FONT */
        }
        .container { 
            max-width: 650px; /* Tighter max width */
            padding-left: 0.5rem; 
            padding-right: 0.5rem;
        }
        
        /* --- Branding (FIXED HEADER) --- */
        .branding-bar {
            position: fixed; 
            top: 0;
            left: 0;
            width: 100%; 
            z-index: 1030; 
            background: linear-gradient(135deg, var(--navy-blue) 0%, var(--accent-color) 100%);
            color: white; 
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2); 
            padding: 0.5rem 1rem; /* TIGHTER PADDING */
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .branding {
            font-weight: 700;
            font-size: 1rem; /* Smaller font */
            text-align: center;
            color: white; 
        }
        .container > .branding { display: none !important; }

        /* --- Card Form --- */
        .card-form { 
            background: var(--card-bg); 
            border: 1px solid var(--border-color); 
            border-radius: 10px; /* Slightly smaller radius */
            padding: 1rem; /* REDUCED CARD PADDING */
            box-shadow: var(--shadow-subtle); 
            margin-bottom: 15px; /* Reduced margin */
        }

        h2 { 
            font-weight: 700; 
            color: var(--accent-color); 
            margin-bottom: 1rem; /* Reduced margin */
            text-align: center; 
            font-size: 1.4rem; /* Smaller H2 */
        }
        h3 {
            font-weight: 600;
            color: var(--accent-color); 
            border-bottom: 1px solid var(--border-color); /* Thinner border */
            padding-bottom: 0.3rem; /* Reduced padding */
            margin-top: 1rem; /* Reduced margin */
            margin-bottom: 0.8rem; /* Reduced margin */
            font-size: 1.1rem; /* Smaller H3 */
        }

        .form-label {
            font-size: 0.85rem; /* Smaller label font */
            margin-bottom: 0.2rem; /* Tighter label spacing */
        }
        .form-control, .form-select {
            border-radius: 6px; /* Smaller radius */
            padding: 0.4rem 0.8rem; /* REDUCED INPUT PADDING */
            border: 1px solid var(--border-color);
            background-color: var(--input-bg-color); 
            color: var(--text-color);
            font-size: 0.85rem; /* Smaller input font */
        }
        .mb-3 {
            margin-bottom: 0.75rem !important; /* Reduced general form spacing */
        }
        
        /* --- Button Styles (Teal/Navy Theme) --- */
        .btn {
            border-radius: 6px;
            padding: 0.5rem 0.9rem; /* Smaller button padding */
            font-weight: 600;
            font-size: 0.9rem; /* Smaller button font */
        }
        .btn-sm {
            padding: 0.2rem 0.5rem; /* Very small back button */
            font-size: 0.75rem;
            margin-bottom: 0.5rem !important; /* Tighter back button margin */
        }
        .btn-primary { 
            background: var(--accent-color); /* Primary Teal */
            border: none;
            color: white;
            box-shadow: 0 4px 10px rgba(28, 40, 51, 0.2); /* Navy-tinted shadow */
            max-width: 250px; 
        }
        .btn-primary:hover {
            background: var(--accent-hover); /* Darker Teal */
            transform: translateY(-1px);
            box-shadow: 0 5px 12px rgba(28, 40, 51, 0.3);
        }
        
        /* --- Table Styles for History (Mobile Responsive) --- */
        .table-responsive {
            border-radius: 10px;
            margin-bottom: 10px; 
        }
        .table { margin-bottom: 0; }
        .table thead th { 
            font-size: 0.8rem; /* Smaller table header font */
            padding: 0.4rem; /* Reduced table header padding */
        }
        .table td, .table th { 
            padding: 0.4rem; /* REDUCED TABLE CELL PADDING */
            font-size: 0.8rem; /* Smaller table body font */
        } 
        .table td small {
            font-size: 0.7rem;
        }

        .status-badge {
            padding: 0.3em 0.6em; /* Smaller badge */
            border-radius: 4px;
            font-size: 0.65rem; 
            font-weight: 700;
            line-height: 1;
        }
        /* --- Status Color Overrides (Themed) --- */
        .status-PENDING { 
            background-color: var(--status-pending-bg); 
            color: var(--status-pending-text); 
            border: 1px solid var(--status-pending-text);
        }
        .status-APPROVED { 
            background-color: var(--status-approved-bg); 
            color: white; 
        }
        .status-REJECTED { 
            background-color: var(--status-rejected-bg); 
            color: white; 
        }

        @media (max-width: 767px) {
            .card-form {
                padding: 0.75rem; /* Even smaller mobile padding */
            }
            .table tbody tr { 
                margin-bottom: 0.4rem; /* Tighter mobile spacing */
                padding: 0.4rem; 
            }
            .table tbody tr td {
                padding: 0.2rem 0.5rem; 
                font-size: 0.8rem; /* Standardizing mobile font size */
            }
            .table tbody tr td::before {
                font-size: 0.7rem; /* Smaller mobile label */
            }
            .table tbody tr td small {
                font-size: 0.7rem; /* Smaller secondary text */
            }
        }
    </style>
</head>
<body>
<div class="branding-bar">
    <div class="branding">
        <i class="bi bi-shield-lock me-1"></i><strong><?= htmlspecialchars($company_name) ?></strong>
    </div>
</div>
<div class="container mt-2"> 
    <a href="dashboard.php" class="btn btn-sm btn-outline-secondary mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    
    <div class="card-form">
        <h2><i class="bi bi-pencil-square me-1"></i> Correction Request </h2>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 px-3"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            
            <div class="mb-3">
                <label for="request_type" class="form-label fw-bold">Correction Type *</label>
                <select name="request_type" id="request_type" class="form-select" required>
                    <option value="" disabled selected>Select Check-In or Check-Out</option>
                    <option value="CHECK_IN">Missed Check-In</option>
                    <option value="CHECK_OUT">Missed Check-Out</option>
                </select>
            </div>

            <div class="row">
                <div class="col-12 col-md-6 mb-3"> 
                    <label for="correction_date" class="form-label fw-bold">Date *</label>
                    <input type="date" name="correction_date" id="correction_date" class="form-control" max="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-12 col-md-6 mb-3"> 
                    <label for="correction_time" class="form-label fw-bold">Time *</label>
                    <input type="time" name="correction_time" id="correction_time" class="form-control" required>
                </div>
            </div>

            <div class="mb-3">
                <label for="store_name" class="form-label fw-bold">Store/Location *</label>
                <input type="text" name="store_name" id="store_name" class="form-control" placeholder="The store/site name" required>
            </div>

            <div class="mb-3"> 
                <label for="reason" class="form-label fw-bold">Reason *</label>
                <textarea name="reason" id="reason" rows="2" class="form-control" placeholder="E.g., Forgot to check out, phone died, etc." required></textarea>
            </div>

            <div class="d-grid mt-3"> 
                <button type="submit" class="btn btn-primary mx-auto"><i class="bi bi-send-fill me-1"></i> Submit Request</button>
            </div>
        </form>

        <p class="text-center text-muted mt-3 mb-0" style="font-size: 0.75rem;">Your request will be reviewed by an administrator.</p>
    </div>
    
    <h3><i class="bi bi-list-check me-1"></i> Recent Requests</h3> 
    <?php if (isset($history_error)): ?>
        <div class="alert alert-warning py-2 px-3"><?= htmlspecialchars($history_error) ?></div>
    <?php elseif (empty($requests)): ?>
        <div class="alert alert-info text-center py-2 px-3">You haven't submitted any correction requests yet.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                    <tr>
                        <th scope="col">Type</th>
                        <th scope="col">Date & Time</th>
                        <th scope="col" class="d-none d-md-table-cell">Store/Reason</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request): ?>
                    <?php
                        // Determine the type and time to display based on the new schema
                        $display_type = '';
                        $display_datetime = '';
                        
                        if (!empty($request['new_check_in'])) {
                            $display_type = 'CHECK-IN';
                            $display_datetime = $request['new_check_in'];
                        } elseif (!empty($request['new_check_out'])) {
                            $display_type = 'CHECK-OUT';
                            $display_datetime = $request['new_check_out'];
                        } else {
                            $display_type = 'N/A';
                            $display_datetime = $request['correction_date'];
                        }

                        $display_time_str = date('H:i', strtotime($display_datetime));
                        $display_date_str = date('d/m/Y', strtotime($request['correction_date']));
                    ?>
                    <tr>
                        <td data-label="Type:">
                            <?= $display_type ?>
                        </td>
                        <td data-label="Date & Time:">
                            <?= $display_date_str ?> at <?= $display_time_str ?>
                        </td>
                        <td data-label="Store/Reason:" class="d-none d-md-table-cell">
                            <?php if (!empty($request['store_location'])): ?>
                                <small class="text-dark d-block">Loc: <?= htmlspecialchars($request['store_location']) ?></small>
                            <?php endif; ?>
                            <?php if (!empty($request['reason'])): ?>
                                <small class="text-muted d-block mt-0">Reason: <?= htmlspecialchars($request['reason']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status:">
                            <?php
                                $status = strtoupper($request['status']);
                                $status_text = match($status) {
                                    'PENDING' => 'Pending Review',
                                    'APPROVED' => 'Approved',
                                    'REJECTED' => 'Rejected',
                                    default => 'Unknown'
                                };
                            ?>
                            <span class="status-badge status-<?= $status ?>"><?= $status_text ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    
    </div> 
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>