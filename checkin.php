<?php
require 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit; 
}

$user_id = $_SESSION['user_id'];
$error = "";

// Fetch user's assigned shift or default to 8 hours
$stmt_shift = $pdo->prepare("SELECT s.total_work_hours 
    FROM user_shifts us 
    JOIN shifts s ON us.shift_id = s.id
    WHERE us.user_id=? AND us.assigned_date=CURDATE() LIMIT 1");
$stmt_shift->execute([$user_id]);
$shift = $stmt_shift->fetch();
$work_hours = $shift ? $shift['total_work_hours'] * 3600 : 8*3600;

// Handle POST for file upload and check-in/out
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check photo and required fields
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] != 0) {
        $error = "Please capture a photo before checking in/out. (Error Code: " . ($_FILES['photo']['error'] ?? 'N/A') . ")";
    } elseif (empty($_POST['latitude']) || empty($_POST['longitude'])) {
        $error = "Location data is missing. Please enable and capture your location.";
    } elseif (empty($_POST['store_name'])) {
        $error = "Please enter the store name.";
    } else {
        $photo_tmp_name = $_FILES['photo']['tmp_name'];
        $photo_name = time().'_'.basename($_FILES['photo']['name']);
        $photo_path = "uploads/".$photo_name;
        
        // Ensure uploads directory exists
        if (!is_dir('uploads')) { mkdir('uploads', 0777, true); }

        if (move_uploaded_file($photo_tmp_name, $photo_path)) {
            $lat = $_POST['latitude'];
            $lng = $_POST['longitude'];
            $store_name = $_POST['store_name'];

            $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id=? AND DATE(check_in)=CURDATE() ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user_id]);
            $last = $stmt->fetch();

            if (isset($_POST['check_in'])) {
                $stmt = $pdo->prepare("INSERT INTO attendance 
                    (user_id, check_in, check_in_photo, check_in_lat, check_in_lng, check_in_store) 
                    VALUES (?, NOW(), ?, ?, ?, ?)");
                $stmt->execute([$user_id, $photo_name, $lat, $lng, $store_name]);
                header("Location: dashboard.php"); exit;
            }

            if (isset($_POST['check_out'])) {
                if ($last && !$last['check_out']) {
                    $stmt = $pdo->prepare("UPDATE attendance SET 
                        check_out=NOW(), check_out_photo=?, check_out_lat=?, check_out_lng=?, check_out_store=? 
                        WHERE id=?");
                    $stmt->execute([$photo_name, $lat, $lng, $store_name, $last['id']]);
                    header("Location: dashboard.php"); exit;
                } else {
                    $error = "No active session to check out!";
                }
            }
        } else {
            $error = "Failed to upload photo file.";
        }
    }
}

// Fetch today's sessions
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id=? AND DATE(check_in)=CURDATE() ORDER BY check_in ASC");
$stmt->execute([$user_id]);
$sessions = $stmt->fetchAll();

// Function to format seconds
function fmt($sec){ $h=floor($sec/3600); $m=floor(($sec%3600)/60); return sprintf("%02d:%02d",$h,$m); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Dark Olive Green Theme Variables */
        :root {
            --primary-color: #556B2F; /* Dark Olive Green */
            --secondary-color: #6B8E23; /* Olive Drab */
            --accent-color: #ADFF2F; /* Green Yellow */
            --background-color: #1a1a1a;
            --card-bg-color: #2a2a2a;
            --text-color: #e0e0e0;
            --muted-text-color: #a0a0a0;
            --border-color: #444;
            --table-header-bg: #3a3a3a;
            --table-row-hover: #353535;
            --danger-color: #dc3545;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--background-color);
            color: var(--text-color);
            padding-bottom: 30px;
        }

        .container {
            margin-top: 30px;
            max-width: 900px;
        }

        .dashboard-header {
            text-align: center;
            color: var(--accent-color);
            font-weight: 700;
            margin-bottom: 30px;
        }
        
        .card-form {
            background-color: var(--card-bg-color);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }

        .form-label {
            color: var(--muted-text-color);
            font-weight: 500;
        }

        .form-control {
            background-color: #333;
            border-color: var(--border-color);
            color: var(--text-color);
            border-radius: 8px;
        }

        .form-control::placeholder {
            color: var(--muted-text-color);
        }

        .btn {
            font-weight: 500;
            border-radius: 8px;
            transition: background-color 0.3s ease, transform 0.2s ease;
        }

        /* Check In Button */
        .btn-success {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-success:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
            transform: translateY(-2px);
        }

        /* Check Out Button */
        .btn-warning {
            background-color: #ffc107;
            border-color: #ffc107;
            color: #000;
        }

        .btn-warning:hover {
            background-color: #e0a800;
            border-color: #e0a800;
            color: #000;
            transform: translateY(-2px);
        }

        /* Logout/Secondary Button */
        .btn-secondary {
            background-color: transparent;
            border: 1px solid var(--border-color);
            color: var(--muted-text-color);
        }
        
        .btn-secondary:hover {
            color: var(--text-color);
            border-color: var(--text-color);
            transform: translateY(-2px);
        }
        
        /* Table Styling */
        .table-responsive {
            border-radius: 10px;
            overflow-x: auto;
            border: 1px solid var(--border-color);
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        
        .table {
            color: var(--text-color);
            margin-bottom: 0;
            background-color: var(--card-bg-color);
        }

        .table thead th {
            background-color: var(--table-header-bg);
            border-color: var(--border-color);
            color: var(--accent-color);
            font-weight: 600;
        }
        
        .table tbody tr:hover {
            background-color: var(--table-row-hover);
        }

        .table td, .table th {
            border-color: var(--border-color);
            vertical-align: middle;
        }
        
        .alert-danger {
            background-color: #dc354533;
            border-color: #dc3545;
            color: #dc3545;
        }

        .location-link {
            color: var(--accent-color);
            text-decoration: none;
        }

        .location-link:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }
        
        /* Custom file input style */
        .file-input-label {
            display: block;
            background-color: var(--secondary-color);
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: background-color 0.3s ease;
        }

        .file-input-label:hover {
            background-color: #79a13a;
        }
        
        .file-input-container input[type="file"] {
            display: none;
        }
        
        /* Responsive Table Styling (Mobile View) */
        @media (max-width: 768px) {
            .table-responsive {
                border: none;
                box-shadow: none;
                overflow-x: hidden;
            }
            .table thead {
                display: none;
            }
            .table tr {
                display: block;
                margin-bottom: 1.5rem;
                background-color: var(--card-bg-color);
                border: 1px solid var(--border-color);
                border-radius: 12px;
                padding: 1rem;
                box-shadow: 0 5px 15px rgba(0,0,0,0.3);
                white-space: normal;
            }
            .table td {
                display: block;
                text-align: right;
                padding: 0.5rem 1rem;
                border: none;
            }
            .table td::before {
                content: attr(data-label);
                float: left;
                font-weight: 600;
                color: var(--accent-color);
            }
        }
    </style>
</head>
<body>
<div class="container">
    <h2 class="dashboard-header">Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></h2>

    <div class="card-form">
        <?php if(isset($error) && !empty($error)) echo "<div class='alert alert-danger'>$error</div>"; ?>

        <!-- IMPORTANT: enctype="multipart/form-data" is required for file uploads -->
        <form method="post" enctype="multipart/form-data" id="attendanceForm">
            
            <div class="mb-4">
                <label for="photo" class="form-label h5 text-light">1. Capture Photo</label>
                <div class="file-input-container">
                    <!-- capture="camera" instructs the mobile browser to open the camera directly -->
                    <input type="file" name="photo" id="photo" accept="image/*" capture="camera" required>
                    <label for="photo" class="file-input-label">
                        <i class="bi bi-camera-fill"></i> Select/Capture Photo
                    </label>
                </div>
                <div id="photoStatus" class="mt-2 text-warning small">
                    <i class="bi bi-x-octagon"></i> No file selected.
                </div>
            </div>

            <div class="mb-4">
                <label for="store_name" class="form-label h5 text-light">2. Store/Location Name</label>
                <input type="text" name="store_name" id="store_name" class="form-control" placeholder="Enter store name" required>
            </div>

            <input type="hidden" name="latitude" id="latitude">
            <input type="hidden" name="longitude" id="longitude">

            <div class="d-grid gap-2">
                <?php
                $last = end($sessions);
                // Check if there's a last session and it hasn't been checked out
                if (!$last || $last['check_out']): ?>
                    <button class="btn btn-success btn-lg" type="submit" name="check_in" id="submitBtn">
                        <i class="bi bi-box-arrow-in-right"></i> Check In Now
                    </button>
                <?php else: ?>
                    <p class="text-center text-muted">
                        <i class="bi bi-clock-history"></i> Last Check-In: **<?= date('g:i A', strtotime($last['check_in'])) ?>** (Store: <?= htmlspecialchars($last['check_in_store']) ?>)
                    </p>
                    <button class="btn btn-warning btn-lg" type="submit" name="check_out" id="submitBtn">
                        <i class="bi bi-box-arrow-right"></i> Check Out Now
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <h4 class="text-center mt-5 mb-3" style="color:var(--text-color);">Today's Attendance History</h4>
    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Check-In Time</th>
                    <th>Check-Out Time</th>
                    <th>Worked (H:M)</th>
                    <th>Overtime (H:M)</th>
                    <th>Photos</th>
                    <th>Check-In Store</th>
                    <th>Check-Out Store</th>
                    <th>Location Map</th>
                </tr>
            </thead>
            <tbody>
            <?php
            foreach($sessions as $s):
                $worked = 0; $overtime = 0;
                if($s['check_out']) {
                    $worked = strtotime($s['check_out']) - strtotime($s['check_in']);
                    $overtime = max(0, $worked - $work_hours);
                }
            ?>
            <tr>
                <td data-label="Check-In Time"><?= date('H:i:s', strtotime($s['check_in'])) ?></td>
                <td data-label="Check-Out Time"><?= $s['check_out'] ? date('H:i:s', strtotime($s['check_out'])) : '-' ?></td>
                <td data-label="Worked (H:M)"><?= fmt($worked) ?></td>
                <td data-label="Overtime (H:M)" class="<?= $overtime > 0 ? 'text-danger fw-bold' : '' ?>"><?= fmt($overtime) ?></td>
                <td data-label="Photos" class="small">
                <?php 
                if($s['check_in_photo']) echo "<a href='uploads/{$s['check_in_photo']}' target='_blank' class='location-link'>In</a><br>";
                if($s['check_out_photo']) echo "<a href='uploads/{$s['check_out_photo']}' target='_blank' class='location-link'>Out</a>";
                ?>
                </td>
                <td data-label="Check-In Store"><?= htmlspecialchars($s['check_in_store'] ?? '-') ?></td>
                <td data-label="Check-Out Store"><?= htmlspecialchars($s['check_out_store'] ?? '-') ?></td>
                <td data-label="Location Map" class="small">
                    <?php if($s['check_in_lat'] && $s['check_in_lng']): ?>
                        IN: <a href="https://www.google.com/maps?q=<?= htmlspecialchars($s['check_in_lat']) ?>,<?= htmlspecialchars($s['check_in_lng']) ?>" target="_blank" class="location-link">Map</a><br>
                    <?php endif; ?>
                    <?php if($s['check_out_lat'] && $s['check_out_lng']): ?>
                        OUT: <a href="https://www.google.com/maps?q=<?= htmlspecialchars($s['check_out_lat']) ?>,<?= htmlspecialchars($s['check_out_lng']) ?>" target="_blank" class="location-link">Map</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="text-center mt-5">
        <a href="logout.php" class="btn btn-secondary w-100 w-md-auto">
            <i class="bi bi-power"></i> Logout
        </a>
    </div>
</div>

<script>
// Geolocation function using promises and SweetAlert2
function getLocation() {
    return new Promise((resolve, reject) => {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(resolve, reject, {
                enableHighAccuracy: true,
                timeout: 5000,
                maximumAge: 0
            });
        } else {
            reject({ message: "Geolocation not supported by this browser." });
        }
    });
}

const attendanceForm = document.getElementById('attendanceForm');
const latitudeInput = document.getElementById('latitude');
const longitudeInput = document.getElementById('longitude');
const photoInput = document.getElementById('photo');
const photoStatus = document.getElementById('photoStatus');
const storeNameInput = document.getElementById('store_name');

// Update photo status display
photoInput.addEventListener('change', function() {
    if (this.files && this.files.length > 0) {
        photoStatus.innerHTML = '<i class="bi bi-check-circle-fill"></i> **Photo Selected:** ' + this.files[0].name;
        photoStatus.classList.remove('text-warning');
        photoStatus.classList.add('text-success');
    } else {
        photoStatus.innerHTML = '<i class="bi bi-x-octagon"></i> No file selected.';
        photoStatus.classList.remove('text-success');
        photoStatus.classList.add('text-warning');
    }
});

// Intercept form submission to capture location
attendanceForm.addEventListener('submit', async function(event) {
    event.preventDefault(); // Stop initial submission
    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.innerHTML;
    
    // Quick validation before starting location
    if (storeNameInput.value.trim() === '') {
        Swal.fire({
            icon: 'warning',
            title: 'Store Name Required',
            text: 'Please enter the store/location name.',
            confirmButtonColor: 'var(--primary-color)'
        });
        return;
    }
    
    if (!photoInput.files || photoInput.files.length === 0) {
         Swal.fire({
            icon: 'warning',
            title: 'Photo Required',
            text: 'Please capture or select a photo before checking in/out.',
            confirmButtonColor: 'var(--primary-color)'
        });
        return;
    }

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Capturing Location...';

    try {
        const position = await getLocation();
        
        // Set location fields
        latitudeInput.value = position.coords.latitude;
        longitudeInput.value = position.coords.longitude;

        // Success: Change button text briefly and submit form
        submitBtn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Location Captured! Submitting...';
        
        // Submit the form programmatically after location data is set
        attendanceForm.submit(); 

    } catch (error) {
        // Handle Geolocation error
        console.error("Geolocation Error:", error);
        
        let errorMessage = "Could not get your location. Please ensure location services are enabled.";
        if (error.code === 1) {
            errorMessage = "Location access denied. Please allow location access for this page.";
        } else if (error.message) {
             errorMessage = error.message;
        }

        Swal.fire({
            icon: 'error',
            title: 'Location Error',
            text: errorMessage,
            confirmButtonColor: 'var(--primary-color)'
        });
        
        // Reset button state
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
