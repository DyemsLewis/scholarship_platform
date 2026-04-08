<?php
$locationModalLatitude = isset($userLatitude) ? (float) $userLatitude : (isset($_SESSION['user_latitude']) ? (float) $_SESSION['user_latitude'] : 0.0);
$locationModalLongitude = isset($userLongitude) ? (float) $userLongitude : (isset($_SESSION['user_longitude']) ? (float) $_SESSION['user_longitude'] : 0.0);
$locationModalAddress = isset($userLocationName) && trim((string) $userLocationName) !== ''
    ? trim((string) $userLocationName)
    : trim((string) ($_SESSION['user_location_name'] ?? ''));
$locationModalHasCoordinates = !($locationModalLatitude === 0.0 && $locationModalLongitude === 0.0);
?>
<div id="locationPickerModal" class="modal" style="display:none; position: fixed; z-index: 1400; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6);">
    <div class="modal-content" style="background: #fff; margin: 3% auto; border-radius: 12px; width: min(94%, 860px); box-shadow: 0 20px 50px rgba(15,23,42,0.25); overflow: hidden;">
        <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #e5e7eb; background: linear-gradient(135deg, #f8fbff, #ffffff);">
            <h3 style="margin:0; color:#2c5aa0; font-size:1.1rem;">
                <i class="fas fa-map-pin"></i> Set Location Pin
            </h3>
            <button type="button" id="closeLocationPickerModal" style="background:none; border:none; font-size:1.3rem; color:#64748b; cursor:pointer;">&times;</button>
        </div>

        <div style="padding: 14px 20px;">
            <div style="background:#eef4ff; border-left:4px solid #3b82f6; color:#1e3a8a; border-radius:8px; padding:10px 12px; font-size:0.9rem; margin-bottom:12px;">
                Click on the map to place your pin, or use your current device location.
            </div>

            <div id="locationPickerMap" style="height: 360px; width: 100%; border-radius: 10px; border: 1px solid #dbe4ee;"></div>

            <div id="locationPickerPreview" style="margin-top:10px; background:#f8fafc; border:1px solid #dbe4ee; border-radius:8px; padding:10px 12px; font-size:0.88rem;">
                <strong>Location Status:</strong> <span id="locationPickerStatus"><?php echo $locationModalHasCoordinates ? 'Pin set' : 'No pin set yet'; ?></span><br>
                <strong>Address:</strong> <span id="locationPickerAddress"><?php echo htmlspecialchars($locationModalAddress !== '' ? $locationModalAddress : 'Not available yet'); ?></span>
            </div>

            <div id="locationPickerWarning" style="margin-top:10px; color:#b91c1c; font-size:0.86rem; min-height:18px;"></div>
        </div>

        <div style="display:flex; flex-wrap:wrap; gap:10px; justify-content:flex-end; padding:14px 20px; border-top:1px solid #e5e7eb; background:#fafcff;">
            <button type="button" id="detectLocationBtn" class="btn btn-outline" style="padding:9px 14px;">
                <i class="fas fa-crosshairs"></i> Detect My Location
            </button>
            <button type="button" id="saveLocationBtn" class="btn btn-primary" style="padding:9px 14px;">
                <i class="fas fa-save"></i> Save Location
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    const modal = document.getElementById('locationPickerModal');
    if (!modal || window.__locationPickerModalInitialized) {
        return;
    }
    window.__locationPickerModalInitialized = true;

    const closeBtn = document.getElementById('closeLocationPickerModal');
    const detectBtn = document.getElementById('detectLocationBtn');
    const saveBtn = document.getElementById('saveLocationBtn');
    const warningEl = document.getElementById('locationPickerWarning');
    const statusEl = document.getElementById('locationPickerStatus');
    const addressEl = document.getElementById('locationPickerAddress');

    let map = null;
    let marker = null;
    let selectedLat = <?php echo json_encode($locationModalLatitude); ?>;
    let selectedLng = <?php echo json_encode($locationModalLongitude); ?>;
    let selectedAddress = <?php echo json_encode($locationModalAddress); ?>;
    const hasInitialCoordinates = <?php echo $locationModalHasCoordinates ? 'true' : 'false'; ?>;

    function showWarning(text) {
        warningEl.textContent = text || '';
    }

    function updatePreview(addressText) {
        statusEl.textContent = 'Pin selected';
        addressEl.textContent = addressText || 'Address lookup unavailable';
    }

    function setMarker(lat, lng) {
        selectedLat = lat;
        selectedLng = lng;

        if (marker) {
            marker.setLatLng([lat, lng]);
        } else {
            marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            marker.on('dragend', function(e) {
                const pos = e.target.getLatLng();
                setPin(pos.lat, pos.lng, true);
            });
        }
    }

    function reverseGeocode(lat, lng) {
        const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`;
        return fetch(url)
            .then(resp => resp.json())
            .then(data => data.display_name || '')
            .catch(() => '');
    }

    function setPin(lat, lng, doReverseLookup) {
        setMarker(lat, lng);
        if (doReverseLookup) {
            reverseGeocode(lat, lng).then(address => {
                selectedAddress = address || selectedAddress || `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                updatePreview(selectedAddress);
            });
        } else {
            updatePreview(selectedAddress);
        }
    }

    function initMap() {
        if (map) {
            setTimeout(() => map.invalidateSize(), 120);
            return;
        }

        const fallbackLat = 14.5995;
        const fallbackLng = 120.9842;
        const startLat = hasInitialCoordinates ? selectedLat : fallbackLat;
        const startLng = hasInitialCoordinates ? selectedLng : fallbackLng;
        const startZoom = hasInitialCoordinates ? 15 : 11;

        map = L.map('locationPickerMap').setView([startLat, startLng], startZoom);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        map.on('click', function(e) {
            showWarning('');
            setPin(e.latlng.lat, e.latlng.lng, true);
        });

        if (hasInitialCoordinates) {
            setPin(selectedLat, selectedLng, false);
        }
    }

    function openModal() {
        modal.style.display = 'block';
        showWarning('');
        initMap();
    }

    function closeModal() {
        modal.style.display = 'none';
        showWarning('');
    }

    function detectCurrentLocation() {
        showWarning('');
        if (!navigator.geolocation) {
            showWarning('Geolocation is not supported by this browser.');
            return;
        }

        showWarning('Getting your current location...');
        navigator.geolocation.getCurrentPosition(
            function(position) {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                if (map) {
                    map.setView([lat, lng], 16);
                }
                setPin(lat, lng, true);
                showWarning('Location detected. Save to apply changes.');
            },
            function(error) {
                let msg = 'Unable to detect current location.';
                if (error.code === error.PERMISSION_DENIED) msg = 'Location permission denied.';
                if (error.code === error.POSITION_UNAVAILABLE) msg = 'Location information unavailable.';
                if (error.code === error.TIMEOUT) msg = 'Location request timed out.';
                showWarning(msg);
            }
        );
    }

    function saveLocation() {
        if (!selectedLat || !selectedLng) {
            showWarning('Please select a location pin first.');
            return;
        }

        const params = new URLSearchParams({
            latitude: String(selectedLat),
            longitude: String(selectedLng),
            location_name: selectedAddress || ''
        });

        saveBtn.disabled = true;
        const previousText = saveBtn.innerHTML;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        showWarning('');

        fetch('../app/Controllers/location_controller.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: params
        })
        .then(resp => resp.json())
        .then(data => {
            if (!data.success) {
                showWarning(data.message || 'Failed to save location.');
                return;
            }

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'success',
                    title: 'Location Updated',
                    text: data.message || 'Location updated successfully.',
                    timer: 1600,
                    showConfirmButton: false
                }).then(() => window.location.reload());
            } else {
                window.location.reload();
            }
        })
        .catch(() => {
            showWarning('Failed to save location. Please try again.');
        })
        .finally(() => {
            saveBtn.disabled = false;
            saveBtn.innerHTML = previousText;
        });
    }

    document.addEventListener('click', function(e) {
        const trigger = e.target.closest('.open-location-modal');
        if (!trigger) {
            return;
        }
        e.preventDefault();
        openModal();
    });

    closeBtn.addEventListener('click', closeModal);
    detectBtn.addEventListener('click', detectCurrentLocation);
    saveBtn.addEventListener('click', saveLocation);

    window.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.style.display === 'block') {
            closeModal();
        }
    });
})();
</script>
