<?php
$profileModalLatitude = isset($userLatitude) && $userLatitude !== '' ? (float) $userLatitude : (isset($_SESSION['user_latitude']) ? (float) $_SESSION['user_latitude'] : null);
$profileModalLongitude = isset($userLongitude) && $userLongitude !== '' ? (float) $userLongitude : (isset($_SESSION['user_longitude']) ? (float) $_SESSION['user_longitude'] : null);

$profileModalAddress = trim(implode(', ', array_filter([
    $userHouseNo ?? '',
    $userStreet ?? '',
    $userBarangay ?? '',
    $userCity ?? '',
    $userProvince ?? ''
])));
if ($profileModalAddress === '') {
    $profileModalAddress = trim((string) ($userAddress ?? ''));
}

$profileModalLocationName = trim((string) ($userLocationName ?? ($_SESSION['user_location_name'] ?? '')));
$profileInitialSummary = $profileModalLocationName !== '' ? $profileModalLocationName : $profileModalAddress;
if ($profileInitialSummary === '') {
    $profileInitialSummary = 'No pin selected yet.';
}

$profileHasCoordinates = ($profileModalLatitude !== null && $profileModalLongitude !== null);
?>
<style>
    .profile-location-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1700;
        background: rgba(15, 23, 42, 0.58);
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .profile-location-dialog {
        width: min(920px, 100%);
        background: #fff;
        border-radius: 12px;
        max-height: min(92vh, 760px);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.35);
    }

    .profile-location-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        border-bottom: 1px solid #e2e8f0;
        background: linear-gradient(135deg, #f8fbff, #ffffff);
    }

    .profile-location-header h3 {
        margin: 0;
        color: #2c5aa0;
        font-size: 1.05rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .profile-location-close {
        border: none;
        background: transparent;
        font-size: 1.25rem;
        color: #64748b;
        cursor: pointer;
    }

    .profile-location-body {
        padding: 16px 20px;
        overflow-y: auto;
    }

    .profile-location-note {
        margin: 0 0 12px;
        padding: 10px 12px;
        border-radius: 8px;
        background: #eef4ff;
        border-left: 4px solid #3b82f6;
        color: #1e3a8a;
        font-size: 0.88rem;
    }

    #profileLocationPickerMap {
        height: 360px;
        border: 1px solid #dbe4ee;
        border-radius: 10px;
    }

    .profile-location-summary {
        margin-top: 10px;
        padding: 10px 12px;
        border: 1px solid #dbe4ee;
        border-radius: 8px;
        background: #f8fafc;
        font-size: 0.84rem;
        color: #334155;
    }

    .profile-location-warning {
        margin-top: 10px;
        min-height: 18px;
        font-size: 0.84rem;
        color: #b91c1c;
    }

    .profile-location-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        flex-wrap: wrap;
        padding: 14px 20px;
        border-top: 1px solid #e2e8f0;
        background: #fafcff;
    }

    @media (max-width: 768px) {
        .profile-location-overlay {
            padding: 12px;
            align-items: flex-end;
        }

        .profile-location-dialog {
            width: 100%;
            max-height: calc(100vh - 24px);
            border-radius: 18px 18px 0 0;
        }

        .profile-location-header,
        .profile-location-body,
        .profile-location-actions {
            padding-left: 16px;
            padding-right: 16px;
        }

        #profileLocationPickerMap {
            height: 260px;
        }

        .profile-location-actions {
            flex-direction: column-reverse;
        }

        .profile-location-actions .btn {
            width: 100%;
            justify-content: center;
        }
    }

    @media (max-width: 480px) {
        .profile-location-header h3 {
            font-size: 1rem;
        }

        #profileLocationPickerMap {
            height: 220px;
        }
    }
</style>

<div id="profileLocationModal" class="profile-location-overlay">
    <div class="profile-location-dialog">
        <div class="profile-location-header">
            <h3><i class="fas fa-map-pin"></i> Set Profile Location</h3>
            <button type="button" id="closeProfileLocationModal" class="profile-location-close">&times;</button>
        </div>
        <div class="profile-location-body">
            <p class="profile-location-note">Click on the map to drop a pin, detect your current location, or use your typed address.</p>
            <div id="profileLocationPickerMap"></div>
            <div class="profile-location-summary">
                <strong>Status:</strong> <span id="profileLocationStatus"><?php echo $profileHasCoordinates ? 'Pin selected' : 'No pin selected'; ?></span><br>
                <strong>Location:</strong> <span id="profileLocationSummary"><?php echo htmlspecialchars($profileInitialSummary); ?></span>
            </div>
            <div id="profileLocationWarning" class="profile-location-warning"></div>
        </div>
        <div class="profile-location-actions">
            <button type="button" id="geocodeProfileAddressBtn" class="btn btn-outline" style="padding: 9px 14px;">
                <i class="fas fa-location-arrow"></i> Set Pin from Address
            </button>
            <button type="button" id="detectProfileLocationBtn" class="btn btn-outline" style="padding: 9px 14px;">
                <i class="fas fa-crosshairs"></i> Detect My Location
            </button>
            <button type="button" id="applyProfileLocationBtn" class="btn btn-primary" style="padding: 9px 14px;">
                <i class="fas fa-check"></i> Use This Pin
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    const modal = document.getElementById('profileLocationModal');
    if (!modal || window.__profileLocationModalInitialized) {
        return;
    }
    window.__profileLocationModalInitialized = true;

    const closeBtn = document.getElementById('closeProfileLocationModal');
    const detectBtn = document.getElementById('detectProfileLocationBtn');
    const geocodeAddressBtn = document.getElementById('geocodeProfileAddressBtn');
    const applyBtn = document.getElementById('applyProfileLocationBtn');
    const warningEl = document.getElementById('profileLocationWarning');
    const statusEl = document.getElementById('profileLocationStatus');
    const summaryEl = document.getElementById('profileLocationSummary');
    const pinStatusEl = document.getElementById('editPinStatusText');

    const latInput = document.getElementById('editLatitude');
    const lngInput = document.getElementById('editLongitude');
    const locationNameInput = document.getElementById('editLocationName');
    const houseNoInput = document.getElementById('editHouseNo');
    const streetInput = document.getElementById('editStreet');
    const barangayInput = document.getElementById('editBarangay');
    const cityInput = document.getElementById('editCity');
    const provinceInput = document.getElementById('editProvince');

    let map = null;
    let marker = null;
    let selectedLat = null;
    let selectedLng = null;
    let selectedDisplayLocation = '';

    const defaultLat = 14.5995;
    const defaultLng = 120.9842;

    function parseCoordinate(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }
        const parsed = parseFloat(value);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function setWarning(text) {
        warningEl.textContent = text || '';
    }

    function setPinStatus(text) {
        if (pinStatusEl) {
            pinStatusEl.textContent = text;
        }
    }

    function buildAddressText(includeHouseNo = true) {
        const parts = [];
        if (includeHouseNo && houseNoInput && houseNoInput.value.trim() !== '') parts.push(houseNoInput.value.trim());
        if (streetInput && streetInput.value.trim() !== '') parts.push(streetInput.value.trim());
        if (barangayInput && barangayInput.value.trim() !== '') parts.push(barangayInput.value.trim());
        if (cityInput && cityInput.value.trim() !== '') parts.push(cityInput.value.trim());
        if (provinceInput && provinceInput.value.trim() !== '') parts.push(provinceInput.value.trim());
        return parts.join(', ');
    }

    function refreshSummary(statusText) {
        if (statusText) {
            statusEl.textContent = statusText;
        }

        const preferredText = selectedDisplayLocation || (locationNameInput && locationNameInput.value.trim()) || buildAddressText();
        summaryEl.textContent = preferredText || 'No pin selected yet.';
    }

    function syncCoordinatesToForm() {
        if (latInput) {
            latInput.value = selectedLat !== null ? selectedLat.toFixed(8) : '';
        }
        if (lngInput) {
            lngInput.value = selectedLng !== null ? selectedLng.toFixed(8) : '';
        }
    }

    function reverseGeocode(lat, lng) {
        const reverseUrl = `https://nominatim.openstreetmap.org/reverse?format=json&addressdetails=1&lat=${lat}&lon=${lng}`;
        return fetch(reverseUrl)
            .then((resp) => resp.json())
            .catch(() => null);
    }

    function geocodeAddress(query) {
        const searchUrl = `https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=ph&q=${encodeURIComponent(query)}`;
        return fetch(searchUrl)
            .then((resp) => resp.json())
            .then((data) => {
                if (!Array.isArray(data) || data.length === 0 || !data[0].lat || !data[0].lon) {
                    return null;
                }
                return data[0];
            })
            .catch(() => null);
    }

    function applyAddressParts(data) {
        if (!data) {
            return;
        }

        const addr = data.address || {};
        const houseNumber = addr.house_number || '';
        const road = addr.road || addr.pedestrian || addr.footway || '';
        const city = addr.city || addr.town || addr.municipality || addr.village || addr.suburb || '';
        const province = addr.state || addr.region || addr.province || '';
        const fallbackDisplayName = (data.display_name || '').trim();

        if (houseNoInput && houseNoInput.value.trim() === '' && houseNumber !== '') {
            houseNoInput.value = houseNumber;
        }
        if (streetInput && streetInput.value.trim() === '' && road !== '') {
            streetInput.value = road;
        }
        if (cityInput && cityInput.value.trim() === '' && city !== '') {
            cityInput.value = city;
        }
        if (provinceInput && provinceInput.value.trim() === '' && province !== '') {
            provinceInput.value = province;
        }
        if (fallbackDisplayName !== '') {
            selectedDisplayLocation = fallbackDisplayName;
            if (locationNameInput && locationNameInput.value.trim() === '') {
                locationNameInput.value = fallbackDisplayName;
            }
        }
    }

    function placeMarker(lat, lng) {
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
        syncCoordinatesToForm();
    }

    function setPin(lat, lng, lookupAddress) {
        placeMarker(lat, lng);
        refreshSummary('Pin selected');

        if (!lookupAddress) {
            return;
        }

        reverseGeocode(lat, lng).then(function(data) {
            applyAddressParts(data);
            refreshSummary('Pin selected');
        });
    }

    function initMap() {
        const initialLat = parseCoordinate(latInput ? latInput.value : null);
        const initialLng = parseCoordinate(lngInput ? lngInput.value : null);
        const startLat = initialLat !== null ? initialLat : <?php echo json_encode($profileModalLatitude !== null ? $profileModalLatitude : 14.5995); ?>;
        const startLng = initialLng !== null ? initialLng : <?php echo json_encode($profileModalLongitude !== null ? $profileModalLongitude : 120.9842); ?>;
        const hasStartPin = initialLat !== null && initialLng !== null;

        if (!map) {
            map = L.map('profileLocationPickerMap').setView([startLat || defaultLat, startLng || defaultLng], hasStartPin ? 15 : 11);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            map.on('click', function(e) {
                setWarning('');
                setPin(e.latlng.lat, e.latlng.lng, true);
            });
        } else {
            map.setView([startLat || defaultLat, startLng || defaultLng], hasStartPin ? 15 : 11);
        }

        if (hasStartPin) {
            setPin(startLat, startLng, false);
        } else {
            selectedLat = null;
            selectedLng = null;
            if (marker) {
                map.removeLayer(marker);
                marker = null;
            }
            refreshSummary('No pin selected');
        }

        setTimeout(function() {
            map.invalidateSize();
        }, 120);
    }

    function openModal() {
        modal.style.display = 'flex';
        setWarning('');
        initMap();
    }

    function closeModal() {
        modal.style.display = 'none';
        setWarning('');
    }

    function detectLocation() {
        if (!navigator.geolocation) {
            setWarning('Geolocation is not supported by this browser.');
            return;
        }

        setWarning('Detecting your current location...');
        navigator.geolocation.getCurrentPosition(
            function(position) {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                if (map) {
                    map.setView([lat, lng], 16);
                }
                setPin(lat, lng, true);
                setWarning('Location detected. Click "Use This Pin" to apply it.');
            },
            function(error) {
                let message = 'Unable to detect current location.';
                if (error.code === error.PERMISSION_DENIED) message = 'Location permission denied.';
                if (error.code === error.POSITION_UNAVAILABLE) message = 'Location data unavailable.';
                if (error.code === error.TIMEOUT) message = 'Location request timed out.';
                setWarning(message);
            }
        );
    }

    function setPinFromAddress() {
        const query = buildAddressText(false);
        if (!query) {
            setWarning('Please complete your address fields first.');
            return;
        }

        setWarning('Searching address...');
        geocodeAddress(query).then(function(result) {
            if (!result) {
                setWarning('Address not found. Try a more complete address or set the pin manually.');
                return;
            }

            const lat = parseFloat(result.lat);
            const lng = parseFloat(result.lon);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                setWarning('Address was found but coordinates are invalid.');
                return;
            }

            if (result.display_name) {
                selectedDisplayLocation = result.display_name;
            }
            if (map) {
                map.setView([lat, lng], 16);
            }
            setPin(lat, lng, false);
            setWarning('Address located. Click "Use This Pin" to apply it.');
        });
    }

    function applyAndClose() {
        if (selectedLat === null || selectedLng === null) {
            setWarning('Please select a pin on the map first.');
            return;
        }

        syncCoordinatesToForm();
        const addressText = buildAddressText();
        if (locationNameInput) {
            locationNameInput.value = (selectedDisplayLocation || addressText || locationNameInput.value || '').trim();
        }
        refreshSummary('Pin selected');
        setPinStatus('Pin selected. It will be saved when you submit profile changes.');
        closeModal();
    }

    document.addEventListener('click', function(e) {
        const trigger = e.target.closest('.open-profile-location-modal');
        if (!trigger) {
            return;
        }
        e.preventDefault();
        openModal();
    });

    closeBtn.addEventListener('click', closeModal);
    detectBtn.addEventListener('click', detectLocation);
    if (geocodeAddressBtn) {
        geocodeAddressBtn.addEventListener('click', setPinFromAddress);
    }
    applyBtn.addEventListener('click', applyAndClose);

    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.style.display === 'flex') {
            closeModal();
        }
    });

    selectedDisplayLocation = <?php echo json_encode($profileInitialSummary); ?>;
    refreshSummary(<?php echo $profileHasCoordinates ? json_encode('Pin selected') : json_encode('No pin selected'); ?>);
})();
</script>
