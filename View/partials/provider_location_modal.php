<?php
$providerModalLatitude = providerOldValue($providerOld, 'latitude');
$providerModalLongitude = providerOldValue($providerOld, 'longitude');
$providerModalLocationName = providerOldValue($providerOld, 'location_name');
$providerModalAddress = trim(implode(', ', array_filter([
    providerOldValue($providerOld, 'house_no'),
    providerOldValue($providerOld, 'street'),
    providerOldValue($providerOld, 'barangay'),
    providerOldValue($providerOld, 'city'),
    providerOldValue($providerOld, 'province')
])));
$providerInitialSummary = $providerModalLocationName !== '' ? $providerModalLocationName : $providerModalAddress;
if ($providerInitialSummary === '') {
    $providerInitialSummary = 'No pin selected yet.';
}
$providerHasCoordinates = $providerModalLatitude !== '' && $providerModalLongitude !== '';
?>
<style>
    .provider-location-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1700;
        background: rgba(15, 23, 42, 0.58);
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .provider-location-dialog {
        width: min(920px, 100%);
        background: #fff;
        border-radius: 12px;
        max-height: min(92vh, 760px);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.35);
    }

    .provider-location-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        border-bottom: 1px solid #e2e8f0;
        background: linear-gradient(135deg, #f8fbff, #ffffff);
    }

    .provider-location-header h3 {
        margin: 0;
        color: #2c5aa0;
        font-size: 1.05rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .provider-location-close {
        border: none;
        background: transparent;
        font-size: 1.25rem;
        color: #64748b;
        cursor: pointer;
    }

    .provider-location-body {
        padding: 16px 20px;
        overflow-y: auto;
    }

    .provider-location-note {
        margin: 0 0 12px;
        padding: 10px 12px;
        border-radius: 8px;
        background: #eef4ff;
        border-left: 4px solid #3b82f6;
        color: #1e3a8a;
        font-size: 0.88rem;
    }

    #providerLocationPickerMap {
        height: 360px;
        border: 1px solid #dbe4ee;
        border-radius: 10px;
    }

    .provider-location-summary {
        margin-top: 10px;
        padding: 10px 12px;
        border: 1px solid #dbe4ee;
        border-radius: 8px;
        background: #f8fafc;
        font-size: 0.84rem;
        color: #334155;
    }

    .provider-location-warning {
        margin-top: 10px;
        min-height: 18px;
        font-size: 0.84rem;
        color: #b91c1c;
    }

    .provider-location-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        flex-wrap: wrap;
        padding: 14px 20px;
        border-top: 1px solid #e2e8f0;
        background: #fafcff;
    }
</style>

<div id="providerLocationModal" class="provider-location-overlay">
    <div class="provider-location-dialog">
        <div class="provider-location-header">
            <h3><i class="fas fa-map-pin"></i> Set Organization Location</h3>
            <button type="button" id="closeProviderLocationModal" class="provider-location-close">&times;</button>
        </div>
        <div class="provider-location-body">
            <p class="provider-location-note">Drop a pin on the map, detect the current location, or convert the organization address into an exact pin.</p>
            <div id="providerLocationPickerMap"></div>
            <div class="provider-location-summary">
                <strong>Status:</strong> <span id="providerLocationStatus"><?php echo $providerHasCoordinates ? 'Pin selected' : 'No pin selected'; ?></span><br>
                <strong>Location:</strong> <span id="providerLocationSummary"><?php echo htmlspecialchars($providerInitialSummary); ?></span>
            </div>
            <div id="providerLocationWarning" class="provider-location-warning"></div>
        </div>
        <div class="provider-location-actions">
            <button type="button" id="geocodeProviderAddressBtn" class="btn btn-outline" style="padding: 9px 14px;">
                <i class="fas fa-location-arrow"></i> Set Pin from Address
            </button>
            <button type="button" id="detectProviderLocationBtn" class="btn btn-outline" style="padding: 9px 14px;">
                <i class="fas fa-crosshairs"></i> Detect My Location
            </button>
            <button type="button" id="applyProviderLocationBtn" class="btn btn-primary" style="padding: 9px 14px;">
                <i class="fas fa-check"></i> Use This Pin
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    const modal = document.getElementById('providerLocationModal');
    if (!modal || window.__providerLocationModalInitialized) {
        return;
    }
    window.__providerLocationModalInitialized = true;

    const closeBtn = document.getElementById('closeProviderLocationModal');
    const detectBtn = document.getElementById('detectProviderLocationBtn');
    const geocodeAddressBtn = document.getElementById('geocodeProviderAddressBtn');
    const applyBtn = document.getElementById('applyProviderLocationBtn');
    const warningEl = document.getElementById('providerLocationWarning');
    const statusEl = document.getElementById('providerLocationStatus');
    const summaryEl = document.getElementById('providerLocationSummary');
    const pinStatusEl = document.getElementById('providerPinStatusText');

    const latInput = document.getElementById('providerLatitude');
    const lngInput = document.getElementById('providerLongitude');
    const locationNameInput = document.getElementById('providerLocationName');
    const houseNoInput = document.getElementById('houseNo');
    const streetInput = document.getElementById('street');
    const barangayInput = document.getElementById('barangay');
    const cityInput = document.getElementById('city');
    const provinceInput = document.getElementById('province');

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

    function setProviderPinStatus(text) {
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
            map = L.map('providerLocationPickerMap').setView([startLat, startLng], hasStartPin ? 15 : 11);
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
        setProviderPinStatus('Pin selected. This map location will be saved with the organization address.');
        closeModal();
    }

    document.addEventListener('click', function(e) {
        const trigger = e.target.closest('.open-provider-location-modal');
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

    refreshSummary(<?php echo json_encode($providerHasCoordinates ? 'Pin selected' : 'No pin selected'); ?>);
})();
</script>
