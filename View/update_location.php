<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/db_config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $latitude = $_POST['latitude'] ?? '';
    $longitude = $_POST['longitude'] ?? '';
    $locationName = trim($_POST['location_name'] ?? '');

    if ($latitude !== '' && $longitude !== '') {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT id FROM student_data WHERE student_id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $studentData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($studentData) {
                $stmt = $pdo->prepare('SELECT id FROM student_location WHERE student_id = ?');
                $stmt->execute([$studentData['id']]);
                $locationExists = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($locationExists) {
                    $stmt = $pdo->prepare('UPDATE student_location SET latitude = ?, longitude = ? WHERE student_id = ?');
                    $stmt->execute([$latitude, $longitude, $studentData['id']]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO student_location (student_id, latitude, longitude) VALUES (?, ?, ?)');
                    $stmt->execute([$studentData['id'], $latitude, $longitude]);
                }

                $pdo->commit();

                $_SESSION['user_latitude'] = $latitude;
                $_SESSION['user_longitude'] = $longitude;
                $_SESSION['user_location_name'] = $locationName;

                $message = 'Location updated successfully.';
            } else {
                $pdo->rollBack();
                $message = 'Student data not found. Please complete your profile first.';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = 'Error updating location: ' . $e->getMessage();
        }
    } else {
        $message = 'Latitude and longitude are required.';
    }
}

$currentLatitude = $_SESSION['user_latitude'] ?? '';
$currentLongitude = $_SESSION['user_longitude'] ?? '';
$currentLocationName = $_SESSION['user_location_name'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Location</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #1f2937;
        }

        .page {
            max-width: 760px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
            padding: 28px;
        }

        h1 {
            margin-top: 0;
            color: #1d4ed8;
        }

        .note,
        .message,
        .preview {
            border-radius: 10px;
            padding: 14px 16px;
            margin: 16px 0;
        }

        .note {
            background: #eef4ff;
        }

        .message {
            background: #ecfdf5;
            color: #166534;
        }

        .preview {
            background: #f8fafc;
            border: 1px solid #dbe4ee;
            display: none;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
        }

        .btn {
            border: 0;
            border-radius: 10px;
            padding: 12px 18px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: #2563eb;
            color: #fff;
        }

        .btn-secondary {
            background: #0f766e;
            color: #fff;
        }

        .btn-outline {
            background: #fff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
        }

        .muted {
            color: #64748b;
            font-size: 14px;
        }

        .warning {
            margin-top: 14px;
            color: #b91c1c;
            min-height: 20px;
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="card">
            <h1><i class="fas fa-location-dot"></i> Update Your Location</h1>
            <p class="muted">Save your current coordinates so the scholarship map can calculate distance and route information correctly.</p>

            <?php if ($message !== ''): ?>
                <div class="message"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <div class="note">
                <strong>Current saved location</strong><br>
                Latitude: <?php echo htmlspecialchars((string) $currentLatitude ?: 'Not set'); ?><br>
                Longitude: <?php echo htmlspecialchars((string) $currentLongitude ?: 'Not set'); ?><br>
                Location: <?php echo htmlspecialchars($currentLocationName ?: 'Not set'); ?>
            </div>

            <form method="POST" id="locationForm">
                <input type="hidden" name="latitude" id="latitude" value="<?php echo htmlspecialchars((string) $currentLatitude); ?>">
                <input type="hidden" name="longitude" id="longitude" value="<?php echo htmlspecialchars((string) $currentLongitude); ?>">
                <input type="hidden" name="location_name" id="location_name" value="<?php echo htmlspecialchars($currentLocationName); ?>">

                <div class="actions">
                    <button type="button" class="btn btn-secondary" onclick="getLocation()">
                        <i class="fas fa-crosshairs"></i>
                        Detect My Current Location
                    </button>
                    <button type="submit" class="btn btn-primary" id="saveBtn" disabled>
                        <i class="fas fa-save"></i>
                        Save Location
                    </button>
                    <a href="scholarships.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i>
                        Back to Scholarships
                    </a>
                </div>
            </form>

            <div id="locationPreview" class="preview">
                <h3>Detected Location</h3>
                <p id="previewLat"></p>
                <p id="previewLng"></p>
                <p id="previewAddress"></p>
            </div>

            <div class="warning" id="warning"></div>
        </div>
    </div>

    <script>
        function getLocation() {
            const warning = document.getElementById('warning');
            const saveBtn = document.getElementById('saveBtn');
            const preview = document.getElementById('locationPreview');

            warning.textContent = '';

            if (!navigator.geolocation) {
                warning.textContent = 'Geolocation is not supported by this browser.';
                return;
            }

            saveBtn.disabled = true;
            warning.textContent = 'Getting your location. Please allow access if prompted.';

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;

                    document.getElementById('latitude').value = lat;
                    document.getElementById('longitude').value = lng;
                    document.getElementById('previewLat').innerHTML = '<strong>Latitude:</strong> ' + lat;
                    document.getElementById('previewLng').innerHTML = '<strong>Longitude:</strong> ' + lng;

                    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
                        .then(response => response.json())
                        .then(data => {
                            const address = data.display_name || 'Address not found';
                            document.getElementById('location_name').value = address;
                            document.getElementById('previewAddress').innerHTML = '<strong>Address:</strong> ' + address;
                            preview.style.display = 'block';
                            saveBtn.disabled = false;
                            warning.textContent = 'Location detected. Click Save Location to continue.';
                        })
                        .catch(() => {
                            document.getElementById('location_name').value = `${lat}, ${lng}`;
                            document.getElementById('previewAddress').innerHTML = '<strong>Address:</strong> Could not resolve address';
                            preview.style.display = 'block';
                            saveBtn.disabled = false;
                            warning.textContent = 'Coordinates detected. Click Save Location to continue.';
                        });
                },
                function(error) {
                    let errorMsg = 'An unknown error occurred.';

                    switch (error.code) {
                        case error.PERMISSION_DENIED:
                            errorMsg = 'Location access denied. Please enable location access in your browser.';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            errorMsg = 'Location information is unavailable.';
                            break;
                        case error.TIMEOUT:
                            errorMsg = 'Location request timed out.';
                            break;
                    }

                    warning.textContent = errorMsg;
                    saveBtn.disabled = true;
                }
            );
        }

        window.addEventListener('load', function() {
            if (document.getElementById('latitude').value) {
                document.getElementById('saveBtn').disabled = false;
            }
        });
    </script>
</body>
</html>
