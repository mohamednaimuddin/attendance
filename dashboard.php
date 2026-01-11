<?php
/**
 * ATTENDANCE DASHBOARD PAGE (RE-THEMED)
 * Allows users to check in/out with photo, location, and store name.
 * THEME: Sleek Minimalist (Teal/Navy).
 * LATEST MODIFICATION: Final display formats applied:
 * 1. Attendance History Date: d/m/Y, D (e.g., 14/10/2025, Mon)
 * 2. Attendance History Times: 12-hour (g:i A)
 */

require 'config.php';
// Placeholder PDO setup for demonstration if not in config.php
date_default_timezone_set('Asia/Riyadh');

if (!isset($pdo)) {
    // --- Mock PDO Class for Demo Environment ---
    $pdo = new class {
        public function prepare($sql) {
            return new class($sql) {
                private $sql;
                public function __construct($sql) { $this->sql = $sql; }
                public function execute($params = []) { 
                    return true; 
                }
                public function fetchAll() { 
                    // Mock attendance history for demonstration (using current year for relevance)
                    $today_in = date('Y-m-d 09:00:00');
                    $yesterday_in = date('Y-m-d 08:30:00', strtotime('-1 day'));
                    $yesterday_out = date('Y-m-d 19:30:00', strtotime('-1 day'));
                    $day_before_in = date('Y-m-d 09:15:00', strtotime('-2 day'));
                    $day_before_out = date('Y-m-d 18:45:00', strtotime('-2 day'));
                    
                    return [
                        ['id' => 125, 'user_id' => 1, 'check_in' => $today_in, 'check_out' => null, 'check_in_photo' => '1734200000.jpg', 'check_in_lat' => 10.1, 'check_in_lng' => 20.2, 'check_in_store' => 'Store A (Morning)', 'check_out_photo' => null, 'check_out_lat' => null, 'check_out_lng' => null, 'check_out_store' => null],
                        ['id' => 124, 'user_id' => 1, 'check_in' => $yesterday_in, 'check_out' => $yesterday_out, 'check_in_photo' => '1734100000.jpg', 'check_in_lat' => 10.3, 'check_in_lng' => 20.4, 'check_in_store' => 'Store B', 'check_out_photo' => '1734104000.jpg', 'check_out_lat' => 10.5, 'check_out_lng' => 20.6, 'check_out_store' => 'Store B'],
                        ['id' => 123, 'user_id' => 1, 'check_in' => $day_before_in, 'check_out' => $day_before_out, 'check_in_photo' => '1734000000.jpg', 'check_in_lat' => 10.7, 'check_in_lng' => 20.8, 'check_in_store' => 'Store C', 'check_out_photo' => '1734005000.jpg', 'check_out_lat' => 10.9, 'check_out_lng' => 20.0, 'check_out_store' => 'Store C'],
                    ];
                }
                public function fetch() { return null; }
            };
        }
    };
    // --- END Mock PDO Class ---
}


if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Check if user is logged in
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

// Mock session data for display purposes
if (!isset($_SESSION['user_id'])) { $_SESSION['user_id'] = 1; }
if (!isset($_SESSION['full_name'])) { $_SESSION['full_name'] = 'Demo Employee'; }

$user_id = $_SESSION['user_id'];
$company_name = "VISION ANGLES SECURITY"; 

// CAPITALIZE ONLY THE FIRST LETTER OF THE USER NAME
$display_user_name = ucfirst($_SESSION['full_name']); 

// Default work hours = 10 hours
$work_hours = 10 * 3600;

// Fetch all attendance sessions
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id=? ORDER BY check_in ASC");
$stmt->execute([$user_id]);
$sessions = $stmt->fetchAll();

// Get the last session for check-in/out button logic
$last = end($sessions);
$has_active_session = $last && !$last['check_out'];

// Format seconds to HH:MM (Function kept for calculation consistency)
function fmt($sec){ 
    $h=floor($sec/3600); 
    $m=floor(($sec%3600)/60); 
    return sprintf("%02d:%02d",$h,$m); 
}

// Calculate worked hours and overtime (Function kept for calculation consistency)
function calculateWorked($in, $out, $work_hours){
    if(!$out) return [0,0];
    $worked = strtotime($out) - strtotime($in);
    $overtime_threshold = $work_hours; 
    $overtime = ($worked > $overtime_threshold) ? ($worked - $overtime_threshold) : 0;
    return [$worked, $overtime];
}


// Handle POST (Fixed: Always store 24-hour formatted timestamp)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['photo_data'])) {
        $error = "Please capture photo before checking in/out.";
    } elseif (empty($_POST['latitude']) || empty($_POST['longitude'])) {
        $error = "Please set your location before checking in/out.";
    } elseif (empty($_POST['store_name'])) {
        $error = "Please enter the store name.";
    } else {
        $photo_data = $_POST['photo_data'];
        $photo_name = time() . '.jpg';
        list(, $data) = explode(',', $photo_data);

        if (!is_dir('uploads')) { 
            mkdir('uploads', 0777, true); 
        }
        file_put_contents("uploads/" . $photo_name, base64_decode($data));

        $lat = $_POST['latitude'];
        $lng = $_POST['longitude'];
        $store_name = $_POST['store_name'];

        // Generate current time in 24-hour format
        $timeNow = date('Y-m-d H:i:s'); // ✅ Ensures 24-hour format storage

        if (isset($_POST['check_in'])) {
            $stmt = $pdo->prepare("INSERT INTO attendance 
                (user_id, check_in, check_in_photo, check_in_lat, check_in_lng, check_in_store) 
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $timeNow, $photo_name, $lat, $lng, $store_name]);

            header("Location: dashboard.php");
            exit;
        }

        if (isset($_POST['check_out'])) {
            if ($has_active_session) {
                $stmt = $pdo->prepare("UPDATE attendance SET 
                    check_out=?, check_out_photo=?, check_out_lat=?, check_out_lng=?, check_out_store=? 
                    WHERE id=?");
                $stmt->execute([$timeNow, $photo_name, $lat, $lng, $store_name, $last['id']]);

                header("Location: dashboard.php");
                exit;
            } else {
                $error = "No active session to check out!";
            }
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
<title>User Dashboard - Visionangles</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="icon" type="image/png" href="visionnew.png">
<style>
/* ================================================= */
/* ===== PROFESSIONAL MOBILE THEME (TEAL/NAVY) ===== */
/* ================================================= */

/* --- Global Variables --- */
:root {
    --text-color: #34495e; 
    --bg-primary: #f4f7f9; /* Lighter, cooler background */
    --card-bg: #ffffff;
    --accent-color: #009688; 
    --accent-hover: #00796b; 
    --danger: #e74c3c; 
    --danger-hover: #c0392b; 
    --navy-blue: #1c2833; /* Deep navy for contrast */
    --border-color: rgba(0, 0, 0, 0.1); 
    --shadow-pro: 0 4px 12px rgba(0, 0, 0, 0.08); 
    --warning: #f39c12; /* Standard Amber/Yellow */
    --warning-hover: #e08e00; 
}

body { 
    font-family: 'Inter', sans-serif; 
    background: var(--bg-primary); 
    color: var(--text-color); 
    padding-top: 4.5rem; 
    padding-bottom: 0.5rem; 
}
.container { 
    max-width: 800px; 
    padding-left: 0.4rem; 
    padding-right: 0.4rem; 
}

/* --- Branding (MODIFIED FOR FIXED HEADER) --- */
.branding-bar {
    position: fixed; 
    top: 0;
    left: 0;
    width: 100%; 
    z-index: 1030; 
    background: linear-gradient(135deg, var(--navy-blue) 0%, var(--accent-color) 100%);
    color: white; 
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3); 
    padding: 0.8rem 1rem; 
    display: flex;
    justify-content: center;
    align-items: center;
}
.branding {
    font-weight: 700;
    font-size: 1.1rem; 
    text-align: center;
    color: white; 
}


/* --- Card Form --- */
.card-form { 
    background: var(--card-bg); 
    border: none; 
    border-radius: 12px; 
    padding: 0.9rem; 
    box-shadow: var(--shadow-pro); 
    margin-bottom: 15px; 
}

/* --- Headers & Typography --- */
.header-row {
    margin-bottom: 0.1rem; 
    padding-top: 0.1rem; 
}
.header-row h2 {
    font-size: 1.3rem; 
    font-weight: 700;
}
.welcome-message {
    margin-top: 0rem; 
    margin-bottom: 0.5rem; 
    font-size: 0.8rem; 
    color: #5d6d7e; 
}
h4 {
    font-weight: 700; 
    color: var(--text-color); 
    border-bottom: 1px solid var(--border-color);
    padding-bottom: 0.3rem; 
    margin-top: 1.2rem; 
    margin-bottom: 0.6rem; 
    font-size: 1.1rem; 
}
.card-form h5 {
    font-weight: 600;
    color: var(--accent-color);
    margin-bottom: 0.3rem; 
    border-left: 3px solid var(--accent-color);
    padding-left: 10px;
    font-size: 0.95rem; 
}

/* --- Form Controls & Inputs --- */
.form-control {
    border-radius: 8px; 
    padding: 0.4rem 0.8rem; 
    font-size: 0.9rem; 
    background-color: var(--bg-primary);
    border: 1px solid var(--border-color);
}
.form-control:focus {
    border-color: var(--accent-color);
    box-shadow: 0 0 0 0.2rem rgba(0, 150, 136, 0.15);
}

/* --- Button Styling (MINIMALIST & SMALLER) --- */
.btn {
    border-radius: 8px; 
    font-weight: 600;
    transition: all 0.2s;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

/* Small Utility Buttons (Enable Camera/Location) */
.btn-secondary { 
    padding: 0.4rem 0.6rem; 
    font-size: 0.85rem; 
    background: var(--bg-primary); 
    border: 1px solid var(--border-color); 
    color: var(--text-color); 
}
.btn-secondary:hover { background: #e9ecef; }

/* Main Action Buttons (CHECK IN/OUT) - FIXED WARNING COLOR */
.btn-lg {
    padding: 0.6rem 1rem; 
    font-size: 0.95rem; 
    letter-spacing: 0.5px;
}
.btn-success { background: var(--accent-color); border:none; color: white; }
.btn-success:hover { background: var(--accent-hover); }
.btn-warning { 
    background: var(--warning); 
    border:none; 
    color: white; 
}
.btn-warning:hover { 
    background: var(--warning-hover); 
}

/* Correction Button Red (with Flexbox centering) */
.correction-btn {
    width: 34px; 
    height: 34px; 
    font-size: 1.1rem; 
    box-shadow: var(--shadow-pro);
    background-color: var(--danger); 
    border: none;
    color: white; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
}
.correction-btn:hover {
    background-color: var(--danger-hover); 
    color: white;
}
.correction-btn:focus {
    box-shadow: 0 0 0 0.2rem rgba(231, 76, 60, 0.3);
}

/* --- Active Session Status Box --- */
.active-session-status {
    padding: 0.6rem; 
    margin-bottom: 0.8rem; 
    font-size: 0.85rem; 
}

/* --- Table Styling --- */
.table-responsive { 
    margin-top:12px; 
    border-radius:10px;
    box-shadow: var(--shadow-pro);
}
.table thead th { 
    font-size: 0.8rem; 
    padding: 0.5rem; 
    font-weight: 600;
}
.table tbody td { 
    padding:0.5rem; 
    font-size: 0.8rem; 
}
.table small.text-muted { font-size: 0.7rem; }

.badge { padding: 0.3em 0.5em; font-size: 0.65rem; border-radius: 4px; }

/* --- Logout Button --- */
.logout-btn { 
    margin-top:15px; 
    font-size:0.85rem; 
    padding:0.5rem 1rem; 
    border-radius: 8px;
}

/* Camera/Location Container Grouping */
#cameraContainer {
    display: none; 
}
#cameraContainer.show {
    display: block; 
}
#video {
    display: none; 
}
#map {
    height: 150px !important; 
}

#cameraContainer.show, #locationResult, #map {
    margin-top: 8px; 
}
#photoResult img {
    max-width: 100%;
}
.mb-4 {
    margin-bottom: 1rem !important; 
}


/* Mobile Specific Overrides */
@media (max-width: 767px) {
    .container {
        padding-left: 0.3rem; 
        padding-right: 0.3rem; 
    }
    .card-form {
        padding: 0.8rem; 
    }
    .d-grid button { 
        width: 100%;
    }
}
</style>
</head>

<body>

<div class="branding-bar">
    <div class="branding">
        <i class="bi bi-shield-lock me-2"></i><strong><?= htmlspecialchars($company_name) ?></strong>
    </div>
</div>
<div class="container">
    
    <div class="header-row d-flex justify-content-between align-items-center">
        <h2><i class="bi bi-person-circle me-2"></i> <?= htmlspecialchars($display_user_name) ?></h2>
        <a href="correction.php" class="btn correction-btn" title="Request Attendance Correction">
            <i class="bi bi-pencil-square"></i>
        </a>
    </div>
    <p class="welcome-message">Your Attendance Dashboard 👋</p>

<?php if(isset($error)) echo "<div class='alert alert-danger'>".htmlspecialchars($error)."</div>"; ?>

<div class="card-form">
<form method="post" onsubmit="captureLocation(event)">
    
    <?php if ($has_active_session): ?>
    <div class="active-session-status alert alert-warning">
        <p class="mb-0"><i class="bi bi-clock-history me-1"></i> Active Session Check-In: 
            <b><?= date('g:i A', strtotime($last['check_in'])) ?></b> (Store: <?= htmlspecialchars($last['check_in_store']) ?>)
    </div>
    <?php endif; ?>

    <div class="mb-3">
    <h5><i class="bi bi-shop"></i> Store/Location Name *</h5>
    <input 
        type="text" 
        name="store_name" 
        id="store_name" 
        class="form-control" 
        placeholder="Enter store name" 
        maxlength="40" 
        required>
    <div id="storeCounter" class="form-text text-end text-muted" style="font-size: 0.75rem;">
        <span id="storeCount">0</span>/40 characters
    </div>
</div>


    <div class="mb-3"> <h5><i class="bi bi-camera-fill"></i> Photo Capture *</h5>
        <button type="button" id="enableCamera" class="btn btn-secondary w-100"><i class="bi bi-webcam"></i> Enable Camera</button>
        <div id="cameraContainer">
            <video id="video" autoplay playsinline style="width:100%;"></video> 
            <canvas id="canvas" style="display:none;"></canvas>
            <button type="button" id="captureBtn" class="btn btn-success mt-2 w-100" style="display:none;"><i class="bi bi-camera"></i> Capture Photo</button> <div id="photoResult" class="mt-2"></div> </div>
        <input type="hidden" name="photo_data" id="photo_data" required>
    </div>

    <div class="mb-3"> <h5><i class="bi bi-geo-alt-fill"></i> Location Tracking *</h5>
        <button type="button" id="enableLocation" class="btn btn-secondary w-100"><i class="bi bi-compass"></i> Get Current Location</button>
        <div id="locationResult" class="mt-2"></div> <input type="hidden" name="latitude" id="latitude" required>
        <input type="hidden" name="longitude" id="longitude" required>
        <div id="map" style="height: 200px; display: none;"></div>
    </div>

    <div class="d-grid gap-2 mt-3"> <?php if (!$has_active_session): ?>
        <button class="btn btn-success btn-lg" name="check_in"><i class="bi bi-box-arrow-in-right"></i> CHECK IN NOW</button>
    <?php else: ?>
        <button class="btn btn-warning btn-lg" name="check_out"><i class="bi bi-box-arrow-right"></i> CHECK OUT NOW</button>
    <?php endif; ?>
    </div>
</form>
</div>

<h4><i class="bi bi-calendar-check"></i> Latest Attendance History</h4>
<div class="table-responsive">
<table class="table table-striped table-hover align-middle">
<thead>
<tr>
<th>Date</th>
<th>In Time</th>
<th>Out Time</th> 
</tr>
</thead>
<tbody>
<?php
// Display only the last 5 sessions
$latest_sessions = array_slice($sessions, -5); 
$latest_sessions = array_reverse($latest_sessions);

foreach($latest_sessions as $s):
list($worked, $overtime) = calculateWorked($s['check_in'], $s['check_out'], $work_hours);
?>
<tr>
<td>
    <?= date('d/m/Y, D', strtotime($s['check_in'])) ?>
</td>
<td>
    <?= date('g:i A', strtotime($s['check_in'])) ?>
    <br><small class="text-muted"><?= htmlspecialchars($s['check_in_store'] ?? '-') ?></small>
</td>
<td>
    <?php if ($s['check_out']): ?>
        <?= date('g:i A', strtotime($s['check_out'])) ?>
        <br><small class="text-muted"><?= htmlspecialchars($s['check_out_store'] ?? '-') ?></small>
    <?php else: ?>
        <span class="badge bg-danger">ACTIVE</span> <?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="text-center">
    <a href="logout.php" class="logout-btn d-inline-block btn btn-outline-secondary"><i class="bi bi-power"></i> Logout</a> </div>
</div>

<script>
// --- Camera and Photo Logic ---
let stream = null;
const enableCameraBtn = document.getElementById('enableCamera');
const cameraContainer = document.getElementById('cameraContainer');
const video = document.getElementById('video');
const canvas = document.getElementById('canvas');
const captureBtn = document.getElementById('captureBtn');
const photoInput = document.getElementById('photo_data');
const photoResult = document.getElementById('photoResult');

// Initialize the capture button state
captureBtn.style.display = "none"; 

enableCameraBtn.addEventListener('click', () => {
    // ENFORCES BACK (ENVIRONMENT) CAMERA ONLY and STARTS THE STREAM
    navigator.mediaDevices.getUserMedia({ video: { facingMode: { exact: "environment" } } })
        .then(s => {
            stream = s; 
            video.srcObject = stream;
            
            // Key step: Makes the live preview and capture button visible.
            video.style.display="block"; 
            captureBtn.style.display="block";
            
            // Animates the camera section into view (due to #cameraContainer CSS)
            cameraContainer.classList.add('show'); 
            
            // Hides the "Enable Camera" button.
            enableCameraBtn.style.display="none";
        })
        .catch(err => {
            // Display an error if the environment camera is not accessible
            Swal.fire({icon:'error',title:'Camera Error',text:'Could not access the rear camera. Please check device permissions: '+err.message});
        });
});

captureBtn.addEventListener('click', () => {
    // Stop the stream and hide the video element after capturing
    canvas.width = video.videoWidth; canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video,0,0);
    photoInput.value = canvas.toDataURL('image/jpeg',0.8);
    if(stream) stream.getTracks().forEach(t=>t.stop());
    video.style.display="none"; 
    captureBtn.style.display="none"; // Hide the capture button after use
    Swal.fire({icon:'success',title:'Photo Captured!',timer:1500,showConfirmButton:false});
    
    photoResult.innerHTML = `
    <div class="text-center mt-2">
        <img src="${photoInput.value}" alt="Captured Photo" style="width:100%;max-width:300px;border-radius:10px;box-shadow:0 4px 10px rgba(0,0,0,0.1);">
        <p class="text-success mt-2 fw-bold"><i class="bi bi-check-circle-fill"></i> Photo Captured</p>
    </div>`;

});

// --- Location Logic ---
const enableLocationBtn = document.getElementById('enableLocation');
const locationResult = document.getElementById('locationResult');
const latInput = document.getElementById('latitude');
const lngInput = document.getElementById('longitude');

enableLocationBtn.addEventListener('click', ()=>{
    if(navigator.geolocation){
        Swal.fire({
            title: 'Getting Location...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });
        
        navigator.geolocation.getCurrentPosition(pos=>{
            Swal.close();
            latInput.value=pos.coords.latitude; lngInput.value=pos.coords.longitude;
            enableLocationBtn.style.display="none";
            Swal.fire({icon:'success',title:'Location Captured!',timer:1500,showConfirmButton:false});
            
            // Using the standard, reliable Google Maps API link format
            const mapLink = `https://maps.google.com/?q=${latInput.value},${lngInput.value}`;
            locationResult.innerHTML=`<p class="text-success mb-0 fw-bold"><i class="bi bi-check-circle-fill"></i> Location Ready!</p>
                <a href="${mapLink}" target="_blank" class="btn btn-link p-0"><i class="bi bi-geo-alt"></i> View on Map</a>`;
        }, err=>{
            Swal.fire({icon:'error',title:'Location Error',text:err.message});
            // Clear inputs on error
            latInput.value = '';
            lngInput.value = '';
        }, {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 0
        });
    } else Swal.fire({icon:'error',title:'Location Error',text:'Geolocation not supported by this browser/device.'});
});

// --- Form Submission Validation ---
function captureLocation(event){
    let prevent=false;
    if(!latInput.value||!lngInput.value){ Swal.fire({icon:'warning',title:'Action Required',text:'Please get your current location before submitting!'}); prevent=true; }
    if(!photoInput.value){ Swal.fire({icon:'warning',title:'Action Required',text:'Please capture a photo before submitting!'}); prevent=true; }
    if(prevent) event.preventDefault();
}
// --- Store Name Character Counter ---
const storeInput = document.getElementById('store_name');
const storeCount = document.getElementById('storeCount');

storeInput.addEventListener('input', () => {
    storeCount.textContent = storeInput.value.length;
    if (storeInput.value.length >= storeInput.maxLength) {
        storeCount.style.color = 'red';
    } else {
        storeCount.style.color = '#6c757d'; // default muted color
    }
});

</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>