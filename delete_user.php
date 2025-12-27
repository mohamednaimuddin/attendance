<?php
/**
 * delete_user.php
 * Handles the secure deletion of a non-admin user via a POST request.
 * It enforces admin privileges and includes CSRF protection.
 */

// Include configuration and enforce admin access
require 'config.php';
require_admin();

// Check if the form was submitted via POST and the user_id is set
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    
    // ===================================
    // 1. CSRF Token Validation
    // ===================================
    // Check if the token exists in POST and matches the one in the session
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        // Log the failure for security auditing (optional, but recommended)
        error_log("CSRF token validation failed for user deletion attempt by admin " . $_SESSION['user_id'] . ".");
        
        // Use a generic error message to prevent information leakage
        $_SESSION['error_msg'] = "Security check failed. Please try again.";
        header("Location: admin_dashboard.php");
        exit;
    }

    $user_id_to_delete = $_POST['user_id'];
    $current_admin_id = $_SESSION['user_id'];

    // ===================================
    // 2. Input Validation and Logic Checks
    // ===================================
    // Ensure the ID is a valid integer and is NOT the admin's own ID
    if (!filter_var($user_id_to_delete, FILTER_VALIDATE_INT) || $user_id_to_delete <= 0) {
        $_SESSION['error_msg'] = "Invalid user ID specified.";
    } elseif ($user_id_to_delete == $current_admin_id) {
        $_SESSION['error_msg'] = "You cannot delete your own admin account.";
    } else {
        // ===================================
        // 3. Database Deletion
        // ===================================
        try {
            // The query targets only non-admin users (is_admin=0) for safety
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND is_admin = 0");
            $stmt->execute([$user_id_to_delete]);

            $rows_deleted = $stmt->rowCount();

            if ($rows_deleted > 0) {
                $_SESSION['success_msg'] = "User ID **" . htmlspecialchars($user_id_to_delete) . "** successfully deleted. ✅";
            } else {
                $_SESSION['error_msg'] = "Deletion failed. User may not exist or is an admin account.";
            }
        } catch (PDOException $e) {
            error_log("Database error during user deletion: " . $e->getMessage());
            $_SESSION['error_msg'] = "A database error occurred during deletion.";
        }
    }

    // Redirect back to the admin dashboard
    header("Location: admin_dashboard.php");
    exit;
}

// ===================================
// 4. Fallback/Invalid Request Handling
// ===================================
// If the request was not a POST request with the required data, redirect
$_SESSION['error_msg'] = "Invalid request method.";
header("Location: admin_dashboard.php");
exit;
?>