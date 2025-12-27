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

<style>
/* (CSS/HTML body remains unchanged) */

/* ================================================= */
/* ===== THEME VARIABLES & ROOT STYLES (Unchanged) ===== */
/* ================================================= */
* { 
    margin:0; 
    padding:0; 
    box-sizing: border-box; /* Key change: Apply to all elements for predictable sizing */
}

html, body { 
    height: 100%; 
    font-family: 'Poppins', sans-serif; 
    margin: 0 !important;
    padding: 0 !important;
    overflow-x: hidden;
}

/* --- Theme variables unchanged --- */
html {
    /* --- LIGHT MODE (Teal Accent) --- */
    --text-color: #2c3e50;
    --background-color: #f7f9fb;
    --card-bg-color: #ffffff;
    --accent-color: #1abc9c;
    --accent-hover: #16a085;
    --border-color: rgba(0,0,0,0.1);
    --text-muted-color: #95a5a6;
    --left-bg-gradient: linear-gradient(145deg, #1abc9c, #2c3e50); 
    --theme-icon-color: #ffffff;
    --shadow-light: 0 4px 15px rgba(0,0,0,0.05);
    --shadow-heavy: 0 10px 30px rgba(0,0,0,0.1);
    --error-color: #e74c3c;
    transition: all 0.5s ease;
}
html.dark-mode {
    /* --- DARK MODE (Deep Teal Accent) --- */
    --text-color: #ecf0f1;
    --background-color: #1c2833;
    --card-bg-color: #2c3e50;
    --accent-color: #1abc9c;
    --accent-hover: #148f77;
    --border-color: rgba(255,255,255,0.1);
    --text-muted-color: #bdc3c7;
    --left-bg-gradient: linear-gradient(145deg, #1abc9c, #1c2833); 
    --theme-icon-color: #ecf0f1;
    --shadow-light: 0 4px 15px rgba(0,0,0,0.4);
    --shadow-heavy: 0 10px 30px rgba(0,0,0,0.6);
    --error-color: #ff6b6b;
}

body { 
    background-color: var(--background-color); 
    color: var(--text-color); 
    transition: background-color 0.5s ease, color 0.5s ease; 
}

/* ================================================= */
/* ===== SPLIT SCREEN LAYOUT (Bootstrap Replaced) ===== */
/* ================================================= */

.split-screen-container {
    min-height: 100vh;
    width: 100%; 
    display: flex; /* Key: Use Flexbox for layout */
    flex-wrap: wrap; /* Key: Allow wrapping for mobile/tablet */
}
/* Flex items: Left and Right sides */
.left-side, .right-side {
    width: 100%; /* Default to full width on small screens */
    flex-grow: 1; /* Allow them to grow */
}

.left-side {
    position: relative;
    display: flex;
    justify-content: center;
    align-items: center;
    text-align: center;
    padding: 2rem;
    overflow: hidden;
    height: 250px; /* Default height for mobile/tablet */
    min-height: 10px;
}

/* Mobile/Tablet Banner Styling Fixes (Adjusted to be simpler) */
@media (max-width: 767.98px) {
    .left-side {
        flex-shrink: 0;
        padding: 1rem;
    }
    .left-side h1 { font-size: 1.8rem; margin-bottom: 0rem; }
    
    .left-side p { 
        display: block; 
        font-size: 0.8rem; 
        max-width: 90%; 
        margin: 0 auto;
    }
    #logo-container { 
        width: 70px; 
        height: 70px; 
        margin: 0 auto 0.5rem; 
    }
}
@media (max-width: 480px) {
    .left-side h1 { font-size: 1.6rem; }
    .left-side p { font-size: 0.7rem; }
    #logo-container { width: 50px; height: 50px; }
}

/* Desktop Split-Screen Styling */
@media (min-width: 768px) {
    .split-screen-container {
        flex-wrap: nowrap; /* Prevent wrapping on desktop */
    }
    .left-side {
        width: 41.666667%; /* 5/12 for col-md-5 */
        min-height: 100vh; 
        height: auto; /* Let content define height */
    }
    .right-side {
        width: 58.333333%; /* 7/12 for col-md-7 */
    }

    /* Adjusting column widths for larger screens (col-lg-6) */
    @media (min-width: 992px) {
        .left-side {
            width: 50%; /* col-lg-6 */
        }
        .right-side {
            width: 50%; /* col-lg-6 */
        }
    }
    
    .left-side h1 { font-size: 3.2rem; }
    .left-side p { display: block; font-size: 1.25rem; }
    #logo-container { width: 120px; height: 120px; margin: 0 auto 1.5rem; }
}

/* Left Side Background Animation (Unchanged) */
.left-side::before {
    content: "";
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: var(--left-bg-gradient); 
    animation: backgroundPan 40s infinite linear alternate;
    transform-origin: 50% 50%;
    z-index: 0;
}
@keyframes backgroundPan { 
    0% { background-position: 0% 0%; transform: scale(1); } 
    50% { background-position: 100% 100%; transform: scale(1.05); } 
    100% { transform: scale(1); }
}

.left-side .content {
    position: relative; z-index: 1;
    text-shadow: 1px 1px 4px rgba(0,0,0,0.5);
    color: var(--theme-icon-color);
}
#logo-container {
    display: flex;
    justify-content: center;
    align-items: center;
    animation: floatText 6s infinite ease-in-out; 
}
#logo-container img { width: 100%; height: 100%; object-fit: contain; }
@keyframes floatText {
    0% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
    100% { transform: translateY(0); }
}

/* Right Side (Form) */
.right-side {
    display: flex;
    justify-content: center;
    align-items: center; 
    padding: 3rem 1rem;
    background-color: var(--background-color);
    transition: background-color 0.5s ease;
}
@media (max-width: 767.98px) {
    .right-side {
        align-items: flex-start;
        padding-top: 1rem;
        padding-bottom: 50px; 
    }
}

.login-card {
    background: var(--card-bg-color);
    border-radius: 16px;
    padding: 1.5rem 1rem;
    width: 100%;
    max-width: 420px;
    box-shadow: var(--shadow-heavy);
    text-align: center;
    border: 1px solid var(--border-color);
    transition: transform 0.4s ease, box-shadow 0.4s ease;
}
.login-card:hover { transform: perspective(1000px) rotateX(1deg) translateY(-5px); box-shadow: 0 15px 40px rgba(0,0,0,0.2); }
@media (max-width: 767.98px) {
    .login-card:hover { transform: none; box-shadow: var(--shadow-heavy); }
}
.login-card h2 { margin-bottom: 2.5rem; font-weight: 700; color: var(--text-color); text-transform: uppercase; letter-spacing: 3px; font-size: 1.8rem; }

/* Custom Input Group Styling (Adjusted margins) */
.input-group-custom { margin-bottom: 1.5rem; position: relative; }
.input-group-custom input {
    width: 100%; padding: 16px 18px; padding-left: 50px; border-radius: 12px;
    border: 1px solid var(--border-color); background: var(--card-bg-color); 
    color: var(--text-color); font-size: 1rem; font-weight: 500; outline: none;
    transition: border-color 0.3s, box-shadow 0.3s; box-shadow: var(--shadow-light);
}
.input-group-custom input:focus { border-color: var(--accent-color); box-shadow: 0 0 10px 3px var(--accent-color, rgba(26, 188, 156, 0.2)), var(--shadow-light); }
.input-group-custom > i.fas { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: var(--text-muted-color); font-size: 1.1rem; transition: color 0.3s ease; }
.input-group-custom input:focus + i.fas { color: var(--accent-color); }

/* --- STYLES FOR PASSWORD TOGGLE --- */
.password-container {
    position: relative; /* To position the toggle icon */
    width: 100%;
}

/* Base input in password container (adjust padding to make space for the toggle icon) */
.password-container input {
    padding-right: 50px; /* Make space for the new eye icon */
}

.password-toggle-icon {
    position: absolute;
    right: 18px; /* Position from the right edge */
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted-color);
    font-size: 1.1rem;
    cursor: pointer;
    transition: color 0.3s ease;
    z-index: 10; /* Ensure it's above the input */
}

.password-toggle-icon:hover {
    color: var(--accent-color);
}


/* Adjust the forgot link */
/* Adjust the forgot link */
.forgot-link {
    display: inline-block;
    margin-top: 20px; /* Current top margin */
    /* ✅ ADD THIS LINE */
    margin-bottom: 20px; /* Add bottom margin to push the button down */
    
    color: var(--accent-color); 
    font-weight: 500;
    font-size: 0.9rem;
    text-decoration: none;
    transition: color 0.3s ease;
}

.forgot-link:hover {
    color: var(--accent-hover); /* 👈 darker shade when hovering */
    text-decoration: underline;
}

.login-card button {
    /* Override default margin-top to position correctly with new form-options div */
    margin-top: 10xp; 
    width: 100%; padding: 16px; font-size: 1.1rem; font-weight: 600;
    border: none; border-radius: 12px; cursor: pointer; color: var(--theme-icon-color);
    background-color: var(--accent-color); 
    box-shadow: 0 4px 20px var(--accent-color, rgba(26, 188, 156, 0.4)); 
    position: relative; overflow: hidden; z-index: 1; transition: all 0.4s ease;
}

/* Mobile adjustments */
@media (max-width: 480px) {
    .form-options {
        flex-direction: column;
        align-items: flex-start;
        margin-bottom: 1.5rem;
    }
    .forgot-link {
        margin-top: 10px;
        align-self: center;
    }
}

.login-card button:hover { transform: translateY(-4px); background-color: var(--accent-hover); box-shadow: 0 12px 25px var(--accent-color, rgba(26, 188, 156, 0.5)); }
.error-message {
    color: var(--error-color); font-weight: 600; margin-bottom: 1.5rem; padding: 10px 15px;
    border-radius: 8px; background-color: var(--error-color)1A; border: 1px solid var(--error-color); 
    font-size: 0.95rem;
    text-align: left; /* Ensure error message text aligns well */
}
/* .forgot-link { display: block; margin-top: 30px; font-size: 0.9rem; color: var(--text-muted-color); text-decoration: none; transition: color 0.3s; } */
.forgot-link:hover { color: var(--accent-color); }

/* Theme Switch Position (Unchanged) */
.theme-switch-wrapper { 
    position: fixed; top: 1rem; right: 1rem; display: flex; align-items: center; z-index: 1000; 
}
.theme-switch-wrapper em { margin-right: 6px; font-size: 0.85rem; font-weight: 600; color: var(--text-color); transition: color 0.5s ease; }
.theme-switch.small { position: relative; display: inline-block; width: 38px; height: 20px; }
.theme-switch.small input { display: none; }
.theme-switch.small .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--text-muted-color); transition: 0.4s; border-radius: 34px; }
.theme-switch.small .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 2px; bottom: 2px; background-color: white; transition: 0.4s; border-radius: 50%; }
.theme-switch.small input:checked + .slider { background-color: var(--accent-color); }
.theme-switch.small input:checked + .slider:before { transform: translateX(18px); }

/* Footer (Unchanged) */
footer {
    position: fixed; bottom: 0; width: 100%; background-color: transparent; text-align: center;
    padding: 10px 0; font-size: 0.8rem; color: var(--text-muted-color); border-top: none; 
    box-shadow: none; backdrop-filter: blur(0); z-index: 101; 
}

/* New utility class to replace Bootstrap's visually-hidden */
.visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border-width: 0;
}
/* New utility class to replace Bootstrap's me-2 (margin-end: 0.5rem) */
.icon-spacer {
    margin-right: 0.5rem;
}
</style>
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
        
        <div class="theme-switch-wrapper">
            <em id="theme-label">Toggle Theme</em>
            <label class="theme-switch small" for="theme-toggle">
                <input type="checkbox" id="theme-toggle" role="switch" aria-labelledby="theme-label">
                <div class="slider round"></div>
            </label>
        </div>

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
// --- Theme Toggle Logic (Unchanged) ---
const themeToggle = document.getElementById('theme-toggle');
const htmlElement = document.documentElement;
const themeLabel = document.getElementById('theme-label');
const prefersDark = window.matchMedia('(prefers-color-scheme: dark)');

function applyTheme(theme) {
    if(theme === 'dark') {
        htmlElement.classList.add('dark-mode');
        themeToggle.checked = true;
        themeLabel.textContent = "Light Mode";
    } else {
        htmlElement.classList.remove('dark-mode');
        themeToggle.checked = false;
        themeLabel.textContent = "Dark Mode";
    }
}
function getInitialTheme() {
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme) {
        return savedTheme;
    } else {
        return prefersDark.matches ? 'dark' : 'light';
    }
}

applyTheme(getInitialTheme());

themeToggle.addEventListener('change', () => {
    const newTheme = themeToggle.checked ? 'dark' : 'light';
    applyTheme(newTheme);
    localStorage.setItem('theme', newTheme);
});

prefersDark.addEventListener('change', (e) => {
    if (!localStorage.getItem('theme')) {
        applyTheme(e.matches ? 'dark' : 'light');
    }
});

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