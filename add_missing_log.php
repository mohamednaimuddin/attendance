<?php
require 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Only admins can add missing logs
if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_POST['user_id'] ?? '';

    // Check-In fields
    $check_in_date = $_POST['check_in_date'] ?? '';
    $check_in_time = $_POST['check_in_time'] ?? '';
    $check_in_store = $_POST['check_in_store'] ?? '';

    // Check-Out fields
    $check_out_date = $_POST['check_out_date'] ?? '';
    $check_out_time = $_POST['check_out_time'] ?? '';
    $check_out_store = $_POST['check_out_store'] ?? '';

    // Validate user_id
    if (empty($user_id)) {
        $_SESSION['error'] = "User is required!";
        header("Location: logs.php");
        exit;
    }

    try {
        // Prepare check-in datetime
        $check_in = null;
        if ($check_in_date) {
            if (!$check_in_time || !$check_in_store) {
                $_SESSION['error'] = "Check-In Time and Store are required when Check-In Date is filled!";
                header("Location: logs.php");
                exit;
            }
            $check_in = date('Y-m-d H:i:s', strtotime("$check_in_date $check_in_time"));
        }

        // Prepare check-out datetime
        $check_out = null;
        if ($check_out_date) {
            if (!$check_out_time || !$check_out_store) {
                $_SESSION['error'] = "Check-Out Time and Store are required when Check-Out Date is filled!";
                header("Location: logs.php");
                exit;
            }
            $check_out = date('Y-m-d H:i:s', strtotime("$check_out_date $check_out_time"));
        }

        // Insert into database
        $stmt = $pdo->prepare("INSERT INTO attendance 
            (user_id, check_in, check_out, check_in_store, check_out_store, edited) 
            VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->execute([
            $user_id,
            $check_in,
            $check_out,
            $check_in_store ?: null,
            $check_out_store ?: null
        ]);

        $_SESSION['success'] = "Missing log added successfully!";
    } catch (Exception $e) {
        $_SESSION['error'] = "Error adding log: " . $e->getMessage();
    }

    header("Location: logs.php");
    exit;
} else {
    header("Location: logs.php");
    exit;
}
