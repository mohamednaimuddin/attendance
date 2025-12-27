<?php
// ============================================
// CONFIGURATION FILE (Updated)
// ============================================

// --- DATABASE CONFIGURATION ---
$host = 'localhost';
$db   = 'atdnce_test';
$user = 'root';
$pass = '';

// --- SESSION HANDLING ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    // --- 1. PDO CONNECTION (Primary) ---
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    // --- 2. MySQLi CONNECTION (for legacy code using $conn) ---
    $conn = new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) {
        throw new Exception("MySQLi connection failed: " . $conn->connect_error);
    }

    // --- 3. Ensure 'Management' Department Exists ---
    try {
        $stmt_dept_check = $pdo->prepare("SELECT id FROM departments WHERE name = 'Management'");
        $stmt_dept_check->execute();
        $dept_row = $stmt_dept_check->fetch();

        if (!$dept_row) {
            $stmt_dept_create = $pdo->prepare("INSERT INTO departments (name, description) VALUES ('Management', 'Top-level administrative department.')");
            $stmt_dept_create->execute();
            $dept_id = $pdo->lastInsertId();
        } else {
            $dept_id = $dept_row['id'];
        }
    } catch (PDOException $e) {
        error_log("Warning: 'departments' table missing or invalid: " . $e->getMessage());
        $dept_id = null;
    }

    // --- 4. Ensure Initial Admin User Exists ---
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1");
    if ($stmt->fetchColumn() == 0) {
        $username = 'admin';
        $password_hash = password_hash('admin123', PASSWORD_DEFAULT);
        $full_name = 'Administrator';
        $is_admin = 1;

        $sql = "INSERT INTO users (username, password_hash, full_name, is_admin, department_id)
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$username, $password_hash, $full_name, $is_admin, $dept_id]);
    }

} catch (Exception $e) {
    error_log("Database initialization failed: " . $e->getMessage());
    die("A database error occurred. Please try again later.");
}

// ============================================
// SECURITY & ACCESS CONTROL FUNCTIONS
// ============================================

// --- ADMIN ACCESS CHECK ---
if (!function_exists('require_admin')) {
    function require_admin() {
        if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
            http_response_code(403);
            die("Access denied.");
        }
    }
}

// --- USER LOGIN CHECK ---
if (!function_exists('require_login')) {
    function require_login() {
        if (!isset($_SESSION['user_id'])) {
            header("Location: index.php");
            exit;
        }
    }
}

// --- SESSION REGENERATION ---
if (!function_exists('regenerate_session_id')) {
    function regenerate_session_id() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
?>
