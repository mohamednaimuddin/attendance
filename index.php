<?php
// index.php

// PHP Logic: Session Management and Authentication
require 'config.php'; // DB connection (PDO instance $pdo assumed available here)

// Start session safely
if (session_status() === PHP_SESSION_NONE) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
        'lifetime' => 0, // session cookie only (expires when browser closes)
        'path' => '/',
        'domain' => '',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

$error = null;
$username = '';
$company_name = "Vision Angles Security";

// -------------------------------------------------------------------------
// 🕒 SESSION EXPIRATION: Destroy session after 5 DAYS (total lifetime)
// -------------------------------------------------------------------------
$total_session_duration = 5 * 24 * 60 * 60; // 5 days in seconds

// If the session has started and exceeded 5 days, destroy it
if (isset($_SESSION['SESSION_START_TIME']) && (time() - $_SESSION['SESSION_START_TIME']) > $total_session_duration) {
    session_unset();
    session_destroy();
    header("Location: index.php?session_expired=1");
    exit;
}

// ==========================================================================
// 📥 Handle login form submission (Remember Me removed)
// ==========================================================================
if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, password_hash, is_admin, full_name FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['is_admin'] = boolval($user['is_admin']);
                $_SESSION['full_name'] = htmlspecialchars($user['full_name']);
                $_SESSION['SESSION_START_TIME'] = time(); // mark when session started

                $redirect_url = $_SESSION['is_admin'] ? "admin_dashboard.php" : "dashboard.php";
                header("Location: " . $redirect_url);
                exit;
            } else {
                $error = "Invalid username or password.";
            }
        } catch (PDOException $e) {
            error_log("Database error in login: " . $e->getMessage());
            $error = "A system error occurred. Please try again later.";
        }
    }
}

// ==========================================================================
// 🔁 Redirect if already logged in and within 5-day valid session
// ==========================================================================
if (isset($_SESSION['user_id']) && isset($_SESSION['SESSION_START_TIME']) && (time() - $_SESSION['SESSION_START_TIME']) <= $total_session_duration) {
    $redirect_url = (isset($_SESSION['is_admin']) && boolval($_SESSION['is_admin'])) 
        ? "admin_dashboard.php" 
        : "dashboard.php";
    header("Location: " . $redirect_url);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - <?= htmlspecialchars($company_name ?? 'Vision Angles') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="visionnew.png">
<link rel="stylesheet" href="assets/css/index.css">

</head>
<body>

<div class="split-screen-container">
        
    <div class="left-side">
        <div class="content">
            <div id="logo-container">
                <img src="vaslogo.png" alt="Vision Angles Security Logo">
            </div> 
            
            <h1><?= htmlspecialchars($company_name ?? 'Vision Angles Security') ?></h1> 
            
            <p>Monitor and track employee attendance efficiently with real-time insights.</p>
        </div>
    </div>

    <div class="right-side">
        <div class="login-card">
            <h2>Log In</h2>
            <?php if(isset($error)): ?>
                <div class="error-message" role="alert" aria-live="assertive">
                    <i class="fas fa-exclamation-circle icon-spacer"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <form method="post">
                <div class="input-group-custom">
                    <label for="username-input" class="visually-hidden">Username</label>
                    <input type="text" name="username" id="username-input" placeholder="Username" required autocomplete="username" value="<?= isset($username) ? htmlspecialchars($username) : '' ?>">
                    <i class="fas fa-user"></i>
                </div>
                <div class="input-group-custom">
                    <label for="password-input" class="visually-hidden">Password</label>
                    <div class="password-container">
                        <input type="password" name="password" id="password-input" placeholder="Password" required autocomplete="current-password">
                        <i class="fas fa-eye password-toggle-icon" id="togglePassword"></i>
                    </div>
                    <i class="fas fa-lock"></i>
                </div>
                
                
                                <div class="form-options">   
                    <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>            
                <button type="submit" name="login"><i class="fas fa-sign-in-alt icon-spacer"></i> LOGIN</button>
            </form>
        </div>
    </div>
</div>

<footer>
    <?= htmlspecialchars($company_name ?? 'Vision Angles Security') ?> EST. &copy; <?= date("Y") ?> All Rights Reserved.
</footer>

<script>

document.addEventListener('DOMContentLoaded', () => {
    const usernameInput = document.getElementById('username-input');
    if (usernameInput) usernameInput.focus();
    
    // --- PASSWORD TOGGLE LOGIC (NEW) ---
    const passwordInput = document.getElementById('password-input');
    const togglePassword = document.getElementById('togglePassword');

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function (e) {
            // toggle the type attribute
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // toggle the eye icon
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    }
});
</script>

</body>
</html>