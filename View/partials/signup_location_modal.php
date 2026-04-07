<style>
    .signup-location-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1700;
        background: rgba(15, 23, 42, 0.58);
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .signup-location-dialog {
        width: min(920px, 100%);
        background: #fff;
        border-radius: 12px;
        max-height: min(92vh, 760px);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.35);
    }

    .signup-location-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        border-bottom: 1px solid #e2e8f0;
        background: linear-gradient(135deg, #f8fbff, #ffffff);
    }

    .signup-location-header h3 {
        margin: 0;
        color: #2c5aa0;
        font-size: 1.05rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .signup-location-close {
        border: none;
        background: transparent;
        font-size: 1.25rem;
        color: #64748b;
        cursor: pointer;
    }

    .signup-location-body {
        padding: 16px 20px;
        overflow-y: auto;
    }

    .signup-location-note {
        margin: 0 0 12px;
        padding: 10px 12px;
        border-radius: 8px;
        background: #eef4ff;
        border-left: 4px solid #3b82f6;
        color: #1e3a8a;
        font-size: 0.88rem;
    }

    #signupLocationPickerMap {
        height: 360px;
        border: 1px solid #dbe4ee;
        border-radius: 10px;
    }

    .signup-location-summary {
        margin-top: 10px;
        padding: 10px 12px;
        border: 1px solid #dbe4ee;
        border-radius: 8px;
        background: #f8fafc;
        font-size: 0.84rem;
        color: #334155;
    }

    .signup-location-warning {
        margin-top: 10px;
        min-height: 18px;
        font-size: 0.84rem;
        color: #b91c1c;
    }

    .signup-location-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        flex-wrap: wrap;
        padding: 14px 20px;
        border-top: 1px solid #e2e8f0;
        background: #fafcff;
    }

    @media (max-width: 768px) {
        .signup-location-overlay {
            padding: 12px;
            align-items: flex-end;
        }

        .signup-location-dialog {
            width: 100%;
            max-height: calc(100vh - 24px);
            border-radius: 18px 18px 0 0;
        }

        .signup-location-header,
        .signup-location-body,
        .signup-location-actions {
            padding-left: 16px;
            padding-right: 16px;
        }

        #signupLocationPickerMap {
            height: 260px;
        }

        .signup-location-actions {
            flex-direction: column-reverse;
        }

        .signup-location-actions .btn {
            width: 100%;
            justify-content: center;
        }
    }

    @media (max-width: 480px) {
        .signup-location-header h3 {
            font-size: 1rem;
        }

        #signupLocationPickerMap {
            height: 220px;
        }
    }
</style>

<div id="signupLocationModal" class="signup-location-overlay">
    <div class="signup-location-dialog">
        <div class="signup-location-header">
            <h3><i class="fas fa-map-pin"></i> Set Signup Location</h3>
            <button type="button" id="closeSignupLocationModal" class="signup-location-close">&times;</button>
        </div>
        <div class="signup-location-body">
            <p class="signup-location-note">Use this when geolocation is unavailable. You can drop a pin on the map, detect your current location, or convert your address into a pin.</p>
            <div id="signupLocationPickerMap"></div>
            <div class="signup-location-summary">
                <strong>Status:</strong> <span id="signupLocationStatus">No pin selected</span><br>
                <strong>Location:</strong> <span id="signupLocationSummary">No pin selected yet.</span>
            </div>
            <div id="signupLocationWarning" class="signup-location-warning"></div>
        </div>
        <div class="signup-location-actions">
            <button type="button" id="geocodeSignupAddressBtn" class="btn btn-outline" style="padding: 9px 14px;">
                <i class="fas fa-location-arrow"></i> Set Pin from Address
            </button>
            <button type="button" id="detectSignupLocationBtn" class="btn btn-outline" style="padding: 9px 14px;">
                <i class="fas fa-crosshairs"></i> Detect My Location
            </button>
            <button type="button" id="applySignupLocationBtn" class="btn btn-primary" style="padding: 9px 14px;">
                <i class="fas fa-check"></i> Use This Pin
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    const modal = document.getElementById('signupLocationModal');
    if (!modal || window.__signupLocationModalInitialized) {
        return;
    }
    window.__signupLocationModalInitialized = true;

    const closeBtn = document.getElementById('closeSignupLocationModal');
    const detectBtn = document.getElementById('detectSignupLocationBtn');
    const geocodeAddressBtn = document.getElementById('geocodeSignupAddressBtn');
    const applyBtn = document.getElementById('applySignupLocationBtn');
    const warningEl = document.getElementById('signupLocationWarning');
    const statusEl = document.getElementById('signupLocationStatus');
    const summaryEl = document.getElementById('signupLocationSummary');
    const signupPinStatusEl = document.getElementById('signupPinStatusText');

    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    const locationNameInput = document.getElementById('location_name');
    const houseNoInput = document.getElementById('signupHouseNo');
    const streetInput = document.getElementById('signupStreet');
    const barangayInput = document.getElementById('signupBarangay');
    const cityInput = document.getElementById('signupCity');
    const provinceInput = document.getElementById('signupProvince');

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

    function setSignupPinStatus(text) {
        if (signupPinStatusEl) {
            signupPinStatusEl.textContent = text;
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

    function callGeocodeController(action, payload) {
        const params = new URLSearchParams({ action });
        Object.keys(payload || {}).forEach(function(key) {
            params.append(key, payload[key]);
        });

        return fetch('../Controller/geocode_controller.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: params.toString()
        }).then(async function(resp) {
            let data = null;
            try {
                data = await resp.json();
            } catch (error) {
                data = null;
            }

            if (!resp.ok || !data || !data.success) {
                const message = (data && data.message) ? data.message : 'Location service is unavailable right now.';
                throw new Error(message);
            }

            return data.data || null;
        });
    }

    function reverseGeocode(lat, lng) {
        return callGeocodeController('reverse', { lat: String(lat), lng: String(lng) })
            .catch(function() {
                return null;
            });
    }

    function geocodeAddress(query) {
        return callGeocodeController('search', { query: query });
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
        const hasStartPin = initialLat !== null && initialLng !== null;
        const startLat = initialLat !== null ? initialLat : defaultLat;
        const startLng = initialLng !== null ? initialLng : defaultLng;

        if (typeof L === 'undefined') {
            setWarning('Map failed to load. Please refresh and try again.');
            return;
        }

        if (!map) {
            map = L.map('signupLocationPickerMap').setView([startLat, startLng], hasStartPin ? 15 : 11);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            map.on('click', function(e) {
                setWarning('');
                setPin(e.latlng.lat, e.latlng.lng, true);
            });
        } else {
            map.setView([startLat, startLng], hasStartPin ? 15 : 11);
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
        if (!window.isSecureContext && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
            setWarning('Detect My Location needs HTTPS. Use Set Pin from Address or open this site in HTTPS.');
            return;
        }

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
            },
            {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            }
        );
    }

    function setPinFromAddress() {
        const query = buildAddressText(false);
        if (!query) {
            setWarning('Please complete Street, Barangay, City, and Province first.');
            return;
        }

        setWarning('Searching address...');
        geocodeAddress(query).then(function(result) {
            if (!result) {
                setWarning('Address not found. Try a more complete address or set the pin manually.');
                return;
            }

            const lat = parseFloat(result.lat);
            const lng = parseFloat(result.lng);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                setWarning('Address was found but coordinates are invalid.');
                return;
            }

            if (result.display_name) {
                selectedDisplayLocation = result.display_name;
            }
            applyAddressParts(result);
            if (map) {
                map.setView([lat, lng], 16);
            }
            setPin(lat, lng, false);
            setWarning('Address located. Click "Use This Pin" to apply it.');
        }).catch(function(error) {
            setWarning(error.message || 'Unable to locate address right now.');
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
        setSignupPinStatus('Pin selected. This map location will be used for registration.');
        closeModal();
    }

    document.addEventListener('click', function(e) {
        const trigger = e.target.closest('.open-signup-location-modal');
        if (!trigger) {
            return;
        }
        e.preventDefault();
        openModal();
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', closeModal);
    }
    if (detectBtn) {
        detectBtn.addEventListener('click', detectLocation);
    }
    if (geocodeAddressBtn) {
        geocodeAddressBtn.addEventListener('click', setPinFromAddress);
    }
    if (applyBtn) {
        applyBtn.addEventListener('click', applyAndClose);
    }

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

    const initialLat = parseCoordinate(latInput ? latInput.value : null);
    const initialLng = parseCoordinate(lngInput ? lngInput.value : null);
    if (initialLat !== null && initialLng !== null) {
        statusEl.textContent = 'Pin selected';
        setSignupPinStatus('Pin already selected. You can keep it or set a new one.');
    }
    refreshSummary(initialLat !== null && initialLng !== null ? 'Pin selected' : 'No pin selected');
})();
</script>
