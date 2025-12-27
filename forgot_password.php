<?php
require 'config.php'; // Ensure this sets up $pdo

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = null;
$error = null;
$identifier_value = $_POST['identifier'] ?? '';

if (isset($_POST['request_reset'])) {
    $identifier = trim($_POST['identifier']);
    $identifier_value = htmlspecialchars($identifier);

    if (empty($identifier)) {
        $error = "Please enter your username or email address.";
    } else {
        try {
            // Check if the user exists
            $stmt = $pdo->prepare("SELECT id, username FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $user_id = $user['id'];
                $username = $user['username'];
            } else {
                // Log the request even if the user isn't found to prevent enumeration attacks
                $user_id = NULL;
                $username = $identifier;
            }

            // Insert request into password_reset_requests
            $insert = $pdo->prepare("
                INSERT INTO password_reset_requests (user_id, username, identifier, request_time, status)
                VALUES (?, ?, ?, NOW(), 'Pending')
            ");
            $insert->execute([$user_id, $username, $identifier]);

            // Always show a generic success message to prevent user enumeration
            $message = "Your request for password assistance has been noted and logged for administrator review. Please await further instructions via email or internal communication.";
            $identifier_value = '';

        } catch (PDOException $e) {
            error_log("DB error during password reset request: " . $e->getMessage());
            $error = "A system error occurred. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password - Visionangles</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ================================================= */
/* ===== TEAL/NAVY MINIMALIST THEME APPLIED ===== */
/* ================================================= */
* { box-sizing: border-box; margin:0; padding:0; }
html, body { height:100%; font-family: 'Poppins', sans-serif; }
html {
    /* --- LIGHT MODE (Teal Accent) --- */
    --text-color: #2c3e50; /* Navy/Dark Grey */
    --background-color: #f7f9fb;
    --card-bg-color: #ffffff;
    --accent-color: #1abc9c; /* PRIMARY: Deep Teal */
    --accent-hover: #16a085; /* Darker Deep Teal */
    --border-color: rgba(0,0,0,0.1);
    --text-muted-color: #95a5a6;
    /* Updated gradient to use Teal/Navy colors */
    --left-bg-gradient: linear-gradient(145deg, #1abc9c, #2c3e50); 
    --theme-icon-color: #ffffff;
    --shadow-light: 0 4px 15px rgba(0,0,0,0.05);
    --shadow-heavy: 0 10px 30px rgba(0,0,0,0.1);
    --error-color: #e74c3c;
    --success-color: #27ae60; /* Admin Green */
    --success-bg: rgba(39, 174, 96, 0.1);
    transition: all 0.5s ease;
}
html.dark-mode {
    /* --- DARK MODE (Deep Teal Accent) --- */
    --text-color: #ecf0f1;
    --background-color: #1c2833; /* Deep Navy Background */
    --card-bg-color: #2c3e50; /* Darker Navy Card */
    --accent-color: #1abc9c; /* PRIMARY: Deep Teal */
    --accent-hover: #148f77; 
    --border-color: rgba(255,255,255,0.1);
    --text-muted-color: #bdc3c7;
    /* Updated gradient to use consistent Dark Mode colors */
    --left-bg-gradient: linear-gradient(145deg, #1abc9c, #1c2833); 
    --theme-icon-color: #ecf0f1;
    --shadow-light: 0 4px 15px rgba(0,0,0,0.4);
    --shadow-heavy: 0 10px 30px rgba(0,0,0,0.6);
    --error-color: #ff6b6b;
    --success-color: #2ecc71; /* Brighter Admin Green */
    --success-bg: rgba(46, 204, 113, 0.15);
}

/* Base and Layout Styles */
body { background-color: var(--background-color); color: var(--text-color); transition: background-color 0.5s, color 0.5s; }
.visually-hidden { position:absolute !important; width:1px !important; height:1px !important; overflow:hidden !important; clip:rect(0,0,0,0) !important; }
.split-screen { display:flex; height:100vh; overflow:hidden; }

/* Left Side (Gradient and Content) */
.left-side { flex:1; display:flex; justify-content:center; align-items:center; text-align:center; position:relative; padding:2rem; overflow:hidden; box-shadow: inset -3px 0 8px rgba(0,0,0,0.05); }
.left-side::before { content:""; position:absolute; top:0; left:0; width:100%; height:100%; background:var(--left-bg-gradient); animation: backgroundPan 40s infinite linear alternate; transform-origin:50% 50%; }
@keyframes backgroundPan { 0% { background-position:0% 0%; transform:scale(1);} 50% { background-position:100% 100%; transform:scale(1.05);} 100% {transform:scale(1);} }
.left-side .content { position:relative; z-index:1; text-shadow: 1px 1px 4px rgba(0,0,0,0.5); color: var(--theme-icon-color); }
.left-side h1 { font-size:3.5rem; font-weight:700; margin-bottom:0.5rem; }
.left-side p { font-size:1.25rem; max-width:85%; margin:0 auto; font-weight:300; }

/* Right Side (Card and Form) */
.right-side { flex:1; display:flex; justify-content:center; align-items:center; padding:2rem; background-color: var(--background-color); transition:background-color 0.5s; }
.login-card { background: var(--card-bg-color); border-radius:16px; padding:3rem 2.5rem; width:100%; max-width:420px; box-shadow: var(--shadow-heavy); text-align:center; border:1px solid var(--border-color); }
.login-card h2 { margin-bottom:1.5rem; font-weight:700; color: var(--text-color); text-transform:uppercase; font-size:1.8rem; letter-spacing:2px; }
.input-group { position:relative; margin-bottom:1.5rem; }
/* Input style updated to use card-bg in dark mode for contrast */
.input-group input { width:100%; padding:16px 18px; padding-left:50px; border-radius:12px; border:1px solid var(--border-color); background: var(--card-bg-color); color: var(--text-color); outline:none; font-size:1rem; font-weight:500; box-shadow: var(--shadow-light); transition: all 0.3s; }
.input-group input:focus { border-color: var(--accent-color); background: var(--card-bg-color); box-shadow: 0 0 0 3px var(--accent-color, rgba(26, 188, 156,0.2)), var(--shadow-light); }
.input-group i { position:absolute; left:18px; top:50%; transform:translateY(-50%); color: var(--text-muted-color); font-size:1.1rem; }
.input-group input:focus + i { color: var(--accent-color); }

/* Button style updated to use accent colors */
.login-card button { width:100%; padding:16px; margin-top:1.5rem; font-size:1.1rem; font-weight:600; border:none; border-radius:12px; cursor:pointer; color: var(--theme-icon-color); background-color: var(--accent-color); box-shadow: 0 4px 15px var(--accent-color, rgba(26, 188, 156, 0.4)); transition: all 0.3s; }
.login-card button:hover { transform:translateY(-3px); background-color: var(--accent-hover); box-shadow:0 8px 20px var(--accent-color, rgba(26, 188, 156, 0.5)); }

/* Message Boxes updated to use new success/error colors */
.error-message { font-weight:600; margin-bottom:1.5rem; padding:15px; border-radius:10px; text-align:left; color: var(--error-color); background-color: rgba(231,76,60,0.1); border:1px solid var(--error-color); }
.success-message { font-weight:600; margin-bottom:1.5rem; padding:15px; border-radius:10px; text-align:left; color: var(--success-color); background-color: var(--success-bg); border:1px solid var(--success-color); }

.security-notice { color: var(--text-color); font-weight:600; margin-bottom:1.5rem; font-size:0.95rem; border-left:4px solid var(--accent-color); padding-left:10px; text-align:left; }
.back-link { display:block; margin-top:20px; font-size:0.9rem; color: var(--text-muted-color); text-decoration:none; transition: color 0.3s; }
.back-link:hover { color: var(--accent-color); }

/* Theme Toggle (No changes needed) */
.theme-switch-wrapper { position:absolute; top:1rem; right:1rem; display:flex; align-items:center; }
.theme-switch-wrapper em { margin-right:8px; font-size:0.85rem; font-weight:600; color: var(--text-color); }
.theme-switch.small { height:20px; width:38px; position:relative; }
.theme-switch.small input { display:none; }
.theme-switch.small .slider { position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background-color: var(--text-muted-color); transition:.4s; border-radius:34px; }
.theme-switch.small .slider:before { position:absolute; content:""; height:18px; width:18px; left:1px; bottom:1px; background-color:#fff; transition:.4s; border-radius:50%; }
.theme-switch.small input:checked + .slider { background-color: var(--accent-color); }
.theme-switch.small input:checked + .slider:before { transform:translateX(18px); }

/* Responsive (No changes needed) */
@media(max-width:992px){.split-screen{flex-direction:column;height:auto;min-height:100vh;}.left-side{flex:unset;height:250px;padding:1rem;}.left-side h1{font-size:2.2rem;}.left-side p{font-size:1rem;max-width:90%;}.right-side{flex:1;padding:1rem;align-items:flex-start;}.login-card{max-width:100%;width:100%;margin:1rem 0;padding:2rem 1.5rem;border-radius:12px;box-shadow:var(--shadow-light);}.login-card h2{margin-bottom:1rem;font-size:1.4rem;}.input-group input{padding:14px 18px;padding-left:45px;border-radius:10px;font-size:1rem;}.login-card button{padding:14px;font-size:1.1rem;border-radius:10px;}.theme-switch-wrapper{top:0.5rem;right:0.5rem;}}
</style>
</head>
<body>
<div class="split-screen">
    <div class="left-side">
        <div class="content">
            <i class="fas fa-question-circle fa-4x mb-3" style="color:var(--theme-icon-color);"></i>
            <h1>Password Assistance</h1>
            <p>Admin intervention is required to safely restore access to your account.</p>
        </div>
    </div>
    <div class="right-side">
        <div class="theme-switch-wrapper">
            <em id="theme-label">Dark Mode</em>
            <label class="theme-switch small" for="theme-toggle">
                <input type="checkbox" id="theme-toggle" role="switch" aria-labelledby="theme-label">
                <div class="slider round"></div>
            </label>
        </div>
        <div class="login-card">
            <h2>Access Restoration</h2>
            <?php if($error): ?>
                <div class="error-message" role="alert"><i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if($message): ?>
                <div class="success-message" role="alert"><i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <p class="security-notice">
                Important: This system requires Admin review for security. Please submit your user identifier to queue your request.
            </p>
            <form method="post">
                <div class="input-group">
                    <label for="identifier-input" class="visually-hidden">Username or Email</label>
                    <input type="text" name="identifier" id="identifier-input" placeholder="Enter your Username or Email" value="<?= $identifier_value ?>" required autocomplete="off">
                    <i class="fas fa-user-tag"></i>
                </div>
                <button type="submit" name="request_reset"><i class="fas fa-bullhorn me-2"></i>Notify Admin of Request</button>
            </form>
            <a href="index.php" class="back-link"><i class="fas fa-chevron-left me-1"></i> Back to Login</a>
        </div>
    </div>
</div>

<script>
const themeToggle = document.getElementById('theme-toggle');
const htmlEl = document.documentElement;

function applyTheme(theme) {
    if(theme==='dark'){ htmlEl.classList.add('dark-mode'); themeToggle.checked=true; }
    else { htmlEl.classList.remove('dark-mode'); themeToggle.checked=false; }
}

document.addEventListener('DOMContentLoaded', () => {
    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    applyTheme(savedTheme || (prefersDark ? 'dark':'light'));

    const identifierInput = document.getElementById('identifier-input');
    if(identifierInput) identifierInput.focus();
});

themeToggle.addEventListener('change', () => {
    const newTheme = themeToggle.checked ? 'dark' : 'light';
    applyTheme(newTheme);
    localStorage.setItem('theme', newTheme);
});

// Sync theme with system preference if no local storage setting is found
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
    if (!localStorage.getItem('theme')) {
        applyTheme(e.matches ? 'dark' : 'light');
    }
});
</script>
</body>
</html>