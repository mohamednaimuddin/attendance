<?php
// update_log.php - A server-side script. It does not produce HTML, 
// so no CSS/theme is applied here. It handles data processing and redirection.

require 'config.php';
// Check session status before starting
if (session_status() === PHP_SESSION_NONE) session_start();

// Only admins can update
if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    // Redirect to login or index if not admin
    header("Location: index.php"); 
    exit;
}

// Helper function to build redirect URL with filters
function buildRedirectUrl($filters) {
    $params = [];
    if (!empty($filters['start'])) $params['start'] = $filters['start'];
    if (!empty($filters['end'])) $params['end'] = $filters['end'];
    if (!empty($filters['user_id']) && $filters['user_id'] > 0) $params['user_id'] = $filters['user_id'];
    if (!empty($filters['page']) && $filters['page'] > 1) $params['page'] = $filters['page'];
    
    $query = http_build_query($params);
    return 'logs.php' . ($query ? '?' . $query : '');
}

// Validate POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get filter parameters to preserve them
    $filters = [
        'start' => trim($_POST['filter_start'] ?? ''),
        'end' => trim($_POST['filter_end'] ?? ''),
        'user_id' => (int)($_POST['filter_user_id'] ?? 0),
        'page' => (int)($_POST['filter_page'] ?? 1)
    ];
    $redirect_url = buildRedirectUrl($filters);
    
    // Sanitize and validate inputs
    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    $check_in = trim($_POST['check_in'] ?? '');
    $check_out = trim($_POST['check_out'] ?? ''); // Nullable

    // Basic validation checks
    if (!$id) {
        $_SESSION['error'] = "Invalid log ID provided.";
        header("Location: " . $redirect_url);
        exit;
    }

    if (empty($check_in) && empty($check_out)) {
        $_SESSION['error'] = "Check-In time is required for a valid attendance log.";
        header("Location: " . $redirect_url);
        exit;
    }

    try {
        // Prepare datetime objects for consistency
        $check_in_dt = !empty($check_in) ? date('Y-m-d H:i:s', strtotime($check_in)) : null;
        
        // Handle check_out, which can be empty/null
        $check_out_dt = !empty($check_out) ? date('Y-m-d H:i:s', strtotime($check_out)) : null;

        // --- Overnight Shift Logic ---
        // If check-out is before check-in, assume it's the next day
        if ($check_in_dt && $check_out_dt && strtotime($check_out_dt) < strtotime($check_in_dt)) {
            $check_out_dt = date('Y-m-d H:i:s', strtotime($check_out_dt . ' +1 day'));
        }

        // Prepare the SQL statement to update the record and mark it as edited
        $sql = "UPDATE attendance 
                SET check_in = ?, check_out = ?, edited = 1 
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        
        // Execute with prepared values
        $stmt->execute([$check_in_dt, $check_out_dt, $id]);

        $_SESSION['success'] = "Attendance log **#{$id}** updated successfully! (Marked as Edited)";
        
    } catch (PDOException $e) {
        // Log the error for internal review
        error_log("DB Error in update_log.php: " . $e->getMessage());
        $_SESSION['error'] = "A database error occurred while updating the log. Please check server logs.";
    } catch (Exception $e) {
        $_SESSION['error'] = "An unexpected error occurred: " . $e->getMessage();
    }

    // Redirect back to the logs page with filters preserved
    header("Location: " . $redirect_url);
    exit;
} else {
    // If accessed directly without POST data
    header("Location: logs.php");
    exit;
}
?>