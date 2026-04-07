<?php
$modalLatitude = isset($locationLatitude) && $locationLatitude !== '' ? (float) $locationLatitude : null;
$modalLongitude = isset($locationLongitude) && $locationLongitude !== '' ? (float) $locationLongitude : null;
$modalAddress = trim((string) ($locationAddress ?? ''));
$modalCity = trim((string) ($locationCity ?? ''));
$modalProvince = trim((string) ($locationProvince ?? ''));

$hasModalCoordinates = ($modalLatitude !== null && $modalLongitude !== null);
$initialLocationSummary = trim(implode(', ', array_filter([$modalAddress, $modalCity, $modalProvince])));
if ($initialLocationSummary === '') {
    $initialLocationSummary = 'No location selected yet.';
}
?>
<style>
    .scholarship-location-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1600;
        background: rgba(15, 23, 42, 0.58);
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .scholarship-location-dialog {
        width: min(920px, 100%);
        background: #fff;
        border-radius: 12px;
        max-height: min(92vh, 760px);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.35);
    }

    .scholarship-location-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        border-bottom: 1px solid #e2e8f0;
        background: linear-gradient(135deg, #f8fbff, #ffffff);
    }

    .scholarship-location-header h3 {
        margin: 0;
        color: #2c5aa0;
        font-size: 1.05rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .scholarship-location-close {
        border: none;
        background: transparent;
        font-size: 1.25rem;
        color: #64748b;
        cursor: pointer;
    }

    .scholarship-location-body {
        padding: 16px 20px;
        overflow-y: auto;
    }

    .scholarship-location-note {
        margin: 0 0 12px;
        padding: 10px 12px;
        border-radius: 8px;
        background: #eef4ff;
        border-left: 4px solid #3b82f6;
        color: #1e3a8a;
        font-size: 0.88rem;
    }

    #scholarshipLocationPickerMap {
        height: 360px;
        border: 1px solid #dbe4ee;
        border-radius: 10px;
    }

    .scholarship-location-summary {
        margin-top: 10px;
        padding: 10px 12px;
        border: 1px solid #dbe4ee;
        border-radius: 8px;
        background: #f8fafc;
        font-size: 0.84rem;
        color: #334155;
    }

    .scholarship-location-warning {
        margin-top: 10px;
        min-height: 18px;
        font-size: 0.84rem;
        color: #b91c1c;
    }

    .scholarship-location-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        flex-wrap: wrap;
        padding: 14px 20px;
        border-top: 1px solid #e2e8f0;
        background: #fafcff;
    }

    .scholarship-location-actions .btn-modern {
        padding: 9px 14px;
    }

    @media (max-width: 768px) {
        .scholarship-location-overlay {
            padding: 12px;
            align-items: flex-end;
        }

        .scholarship-location-dialog {
            width: 100%;
            max-height: calc(100vh - 24px);
            border-radius: 18px 18px 0 0;
        }

        .scholarship-location-header,
        .scholarship-location-body,
        .scholarship-location-actions {
            padding-left: 16px;
            padding-right: 16px;
        }

        #scholarshipLocationPickerMap {
            height: 260px;
        }

        .scholarship-location-actions {
            flex-direction: column-reverse;
        }

        .scholarship-location-actions .btn-modern {
            width: 100%;
            justify-content: center;
        }
    }

    @media (max-width: 480px) {
        .scholarship-location-header h3 {
            font-size: 1rem;
        }

        #scholarshipLocationPickerMap {
            height: 220px;
        }
    }
</style>

<div id="scholarshipLocationModal" class="scholarship-location-overlay">
    <div class="scholarship-location-dialog">
        <div class="scholarship-location-header">
            <h3><i class="fas fa-map-pin"></i> <span id="scholarshipLocationModalTitleText">Select Scholarship Location</span></h3>
            <button type="button" id="closeScholarshipLocationModal" class="scholarship-location-close">&times;</button>
        </div>
        <div class="scholarship-location-body">
            <p class="scholarship-location-note">Click on the map to drop a pin, use your current device location, or search using the address fields.</p>
            <div id="scholarshipLocationPickerMap"></div>
            <div class="scholarship-location-summary">
                <strong>Status:</strong> <span id="scholarshipLocationStatus"><?php echo $hasModalCoordinates ? 'Pin selected' : 'No pin selected'; ?></span><br>
                <strong>Location:</strong> <span id="scholarshipLocationSummary"><?php echo htmlspecialchars($initialLocationSummary); ?></span>
            </div>
            <div id="scholarshipLocationWarning" class="scholarship-location-warning"></div>
        </div>
        <div class="scholarship-location-actions">
            <button type="button" id="geocodeScholarshipAddressBtn" class="btn-modern btn-outline-modern">
                <i class="fas fa-location-arrow"></i> Set Pin from Address
            </button>
            <button type="button" id="detectScholarshipLocationBtn" class="btn-modern btn-outline-modern">
                <i class="fas fa-crosshairs"></i> Detect My Location
            </button>
            <button type="button" id="applyScholarshipLocationBtn" class="btn-modern btn-primary-modern">
                <i class="fas fa-check"></i> Use This Pin
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    const modal = document.getElementById('scholarshipLocationModal');
    if (!modal || window.__scholarshipLocationModalInitialized) {
        return;
    }
    window.__scholarshipLocationModalInitialized = true;

    const closeBtn = document.getElementById('closeScholarshipLocationModal');
    const detectBtn = document.getElementById('detectScholarshipLocationBtn');
    const geocodeAddressBtn = document.getElementById('geocodeScholarshipAddressBtn');
    const applyBtn = document.getElementById('applyScholarshipLocationBtn');
    const warningEl = document.getElementById('scholarshipLocationWarning');
    const statusEl = document.getElementById('scholarshipLocationStatus');
    const summaryEl = document.getElementById('scholarshipLocationSummary');
    const titleTextEl = document.getElementById('scholarshipLocationModalTitleText');

    const defaultFieldRefs = {
        latInput: document.getElementById('latitudeInput'),
        lngInput: document.getElementById('longitudeInput'),
        addressInput: document.getElementById('addressInput'),
        cityInput: document.getElementById('cityInput'),
        provinceInput: document.getElementById('provinceInput')
    };
    let activeFieldRefs = { ...defaultFieldRefs };
    const defaultModalTitle = 'Select Scholarship Location';

    let map = null;
    let marker = null;
    let selectedLat = null;
    let selectedLng = null;
    let selectedDisplayLocation = '';
    let onApplyCallback = null;

    const defaultLat = 14.5995;
    const defaultLng = 120.9842;

    function parseCoordinate(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }
        const parsed = parseFloat(value);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function buildSummaryText() {
        const parts = [];
        if (activeFieldRefs.addressInput && activeFieldRefs.addressInput.value.trim() !== '') {
            parts.push(activeFieldRefs.addressInput.value.trim());
        }
        if (activeFieldRefs.cityInput && activeFieldRefs.cityInput.value.trim() !== '') {
            parts.push(activeFieldRefs.cityInput.value.trim());
        }
        if (activeFieldRefs.provinceInput && activeFieldRefs.provinceInput.value.trim() !== '') {
            parts.push(activeFieldRefs.provinceInput.value.trim());
        }
        if (parts.length > 0) {
            return parts.join(', ');
        }
        return selectedDisplayLocation || 'No location selected yet.';
    }

    function setWarning(text) {
        warningEl.textContent = text || '';
    }

    function refreshSummary(statusText) {
        if (statusText) {
            statusEl.textContent = statusText;
        }
        summaryEl.textContent = buildSummaryText();
    }

    function syncCoordinatesToForm() {
        if (activeFieldRefs.latInput) {
            activeFieldRefs.latInput.value = selectedLat !== null ? selectedLat.toFixed(8) : '';
        }
        if (activeFieldRefs.lngInput) {
            activeFieldRefs.lngInput.value = selectedLng !== null ? selectedLng.toFixed(8) : '';
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

    function applyLocationParts(data) {
        if (!data) {
            return;
        }

        const addr = data.address || {};
        const houseNumber = addr.house_number || '';
        const road = addr.road || addr.pedestrian || addr.footway || '';
        const streetAddress = [houseNumber, road].filter(Boolean).join(' ').trim();
        const city = addr.city || addr.town || addr.municipality || addr.village || addr.suburb || '';
        const province = addr.state || addr.region || addr.province || '';
        const fallbackDisplayName = (data.display_name || '').trim();

        if (activeFieldRefs.addressInput && streetAddress !== '') {
            activeFieldRefs.addressInput.value = streetAddress;
        } else if (activeFieldRefs.addressInput && fallbackDisplayName !== '' && activeFieldRefs.addressInput.value.trim() === '') {
            activeFieldRefs.addressInput.value = fallbackDisplayName.split(',')[0].trim();
        }

        if (activeFieldRefs.cityInput && city !== '') {
            activeFieldRefs.cityInput.value = city;
        }
        if (activeFieldRefs.provinceInput && province !== '') {
            activeFieldRefs.provinceInput.value = province;
        }

        if (fallbackDisplayName !== '') {
            selectedDisplayLocation = fallbackDisplayName;
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
            applyLocationParts(data);
            refreshSummary('Pin selected');
        });
    }

    function initMap() {
        const initialLat = parseCoordinate(activeFieldRefs.latInput ? activeFieldRefs.latInput.value : null);
        const initialLng = parseCoordinate(activeFieldRefs.lngInput ? activeFieldRefs.lngInput.value : null);
        const startLat = initialLat !== null ? initialLat : <?php echo json_encode($modalLatitude !== null ? $modalLatitude : 14.5995); ?>;
        const startLng = initialLng !== null ? initialLng : <?php echo json_encode($modalLongitude !== null ? $modalLongitude : 120.9842); ?>;
        const hasStartPin = initialLat !== null && initialLng !== null;

        if (!map) {
            map = L.map('scholarshipLocationPickerMap').setView([startLat || defaultLat, startLng || defaultLng], hasStartPin ? 15 : 11);
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

    function setActiveFields(options) {
        activeFieldRefs = {
            latInput: options && options.latInput ? options.latInput : defaultFieldRefs.latInput,
            lngInput: options && options.lngInput ? options.lngInput : defaultFieldRefs.lngInput,
            addressInput: options && options.addressInput ? options.addressInput : defaultFieldRefs.addressInput,
            cityInput: options && options.cityInput ? options.cityInput : defaultFieldRefs.cityInput,
            provinceInput: options && options.provinceInput ? options.provinceInput : defaultFieldRefs.provinceInput
        };
    }

    function openModal(options) {
        setActiveFields(options || null);
        onApplyCallback = options && typeof options.onApply === 'function' ? options.onApply : null;

        const dynamicTitle = options && typeof options.title === 'string' && options.title.trim() !== ''
            ? options.title.trim()
            : defaultModalTitle;
        if (titleTextEl) {
            titleTextEl.textContent = dynamicTitle;
        }

        selectedDisplayLocation = '';
        const currentSummary = buildSummaryText();
        if (currentSummary !== 'No location selected yet.') {
            selectedDisplayLocation = currentSummary;
        }
        modal.style.display = 'flex';
        setWarning('');
        initMap();
    }

    function closeModal() {
        modal.style.display = 'none';
        setWarning('');
        onApplyCallback = null;
        setActiveFields(null);
        if (titleTextEl) {
            titleTextEl.textContent = defaultModalTitle;
        }
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
        const query = buildSummaryText();
        if (!query || query === 'No location selected yet.') {
            setWarning('Please enter address, city, or province first.');
            return;
        }

        setWarning('Searching address...');
        geocodeAddress(query).then(function(result) {
            if (!result) {
                setWarning('Address not found. Try a more complete address, or set the pin manually on the map.');
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
        refreshSummary('Pin selected');
        if (typeof onApplyCallback === 'function') {
            onApplyCallback({
                latitude: selectedLat,
                longitude: selectedLng,
                summary: buildSummaryText()
            });
        }
        closeModal();
    }

    window.openScholarshipLocationModal = function(options) {
        openModal(options || null);
    };

    document.addEventListener('click', function(e) {
        const trigger = e.target.closest('.open-scholarship-location-modal');
        if (!trigger) {
            return;
        }
        e.preventDefault();
        openModal(null);
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

    selectedDisplayLocation = <?php echo json_encode($initialLocationSummary); ?>;
    refreshSummary(<?php echo $hasModalCoordinates ? json_encode('Pin selected') : json_encode('No pin selected'); ?>);
})();
</script>
