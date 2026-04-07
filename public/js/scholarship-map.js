/**
 * scholarship-map.js - Complete working version with user location
 */

function showScholarshipMapAlert(title, text = '', icon = 'warning') {
    if (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function') {
        Swal.fire({
            icon,
            title,
            text,
            confirmButtonColor: '#2c5aa0'
        });
        return;
    }

    window.alert(text || title);
}

// Create a single global namespace object
const ScholarshipMap = {
    // Private variables
    map: null,
    routingControl: null,
    userMarker: null,
    scholarshipMarker: null,
    userLat: null,
    userLng: null,
    
    // Initialize user location from PHP session
    initUserLocation: function(lat, lng) {
        // Validate inputs
        if (lat === undefined || lng === undefined || lat === null || lng === null) {
            this.userLat = null;
            this.userLng = null;
            this.updateLocationStatus('No location found in your profile', 'warning');
            return false;
        }
        
        // Parse to float
        const parsedLat = parseFloat(lat);
        const parsedLng = parseFloat(lng);
        
        // Check if valid numbers
        if (!isNaN(parsedLat) && !isNaN(parsedLng) && isFinite(parsedLat) && isFinite(parsedLng)) {
            this.userLat = parsedLat;
            this.userLng = parsedLng;
            
            // Update any UI elements that depend on location
            this.updateLocationStatus('Location loaded from your profile', 'success');
            
            return true;
        } else {
            this.userLat = null;
            this.userLng = null;
            this.updateLocationStatus('No location found in your profile', 'warning');
            return false;
        }
    },
    
    // Check if location is available
    hasUserLocation: function() {
        return this.userLat !== null && this.userLng !== null && 
               !isNaN(this.userLat) && !isNaN(this.userLng) &&
               isFinite(this.userLat) && isFinite(this.userLng);
    },
    
    // Update location status message
    updateLocationStatus: function(message, type) {
        const statusDiv = document.getElementById('locationStatus');
        if (statusDiv) {
            statusDiv.innerHTML = message;
            statusDiv.className = 'location-status ' + type;
            statusDiv.style.display = 'block';
        }
    },
    
    // Show scholarship on map
    showOnMap: function(scholarshipId, scholarshipName, lat, lng) {
        // Validate scholarship coordinates
        const scholarLat = parseFloat(lat);
        const scholarLng = parseFloat(lng);
        
        if (isNaN(scholarLat) || isNaN(scholarLng)) {
            showScholarshipMapAlert('Map Unavailable', 'Location coordinates are not available for this scholarship.');
            return;
        }
        
        // Show modal
        const modal = document.getElementById('mapModal');
        if (!modal) {
            return;
        }

        // Ensure modal renders at document root to avoid stacking-context issues.
        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }

        const nav = document.querySelector('[data-mobile-nav]');
        if (nav && nav.classList.contains('is-open')) {
            nav.classList.remove('is-open');
            document.body.classList.remove('nav-open');
        }

        modal.style.zIndex = '30000';
        modal.style.display = 'block';
        document.body.classList.add('map-modal-open');
        
        // Set title based on user location availability
        const titleElement = document.getElementById('mapModalTitle');
        if (this.hasUserLocation()) {
            titleElement.textContent = `${scholarshipName} - Route from your location`;
        } else {
            titleElement.textContent = scholarshipName + ' - Location';
        }
        
        // Wait for modal to be visible
        setTimeout(() => {
            // Get map container
            const mapContainer = document.getElementById('map');
            if (!mapContainer) {
                return;
            }
            
            // Clear previous map
            mapContainer.innerHTML = '';
            
            // Create new map
            this.map = L.map('map').setView([scholarLat, scholarLng], 13);
            
            // Add tile layer
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(this.map);
            
            // Add scholarship marker (red)
            const scholarIcon = L.divIcon({
                className: 'scholarship-marker',
                html: '<div style="background: #f44336; width: 20px; height: 20px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3);"></div>',
                iconSize: [26, 26],
                popupAnchor: [0, -13]
            });
            
            this.scholarshipMarker = L.marker([scholarLat, scholarLng], { icon: scholarIcon }).addTo(this.map)
                .bindPopup(`
                    <div style="text-align: center;">
                        <b style="color: #f44336;">${scholarshipName}</b><br>
                        <small>Scholarship Provider</small>
                    </div>
                `)
                .openPopup();
            
            // If user has location, add user marker and route
            if (this.hasUserLocation()) {
                // Add user marker (blue with pulse)
                const userIcon = L.divIcon({
                    className: 'user-marker',
                    html: `
                        <div style="position: relative;">
                            <div style="width: 20px; height: 20px; background: #2196F3; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3); animation: pulse 1.5s ease-out infinite;"></div>
                            <div style="position: absolute; top: -25px; left: -15px; background: #2196F3; color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; white-space: nowrap;">You</div>
                        </div>
                    `,
                    iconSize: [26, 26],
                    popupAnchor: [0, -13]
                });
                
                this.userMarker = L.marker([this.userLat, this.userLng], { icon: userIcon }).addTo(this.map)
                    .bindPopup(`
                        <div style="text-align: center;">
                            <b style="color: #2196F3;">Your Location</b><br>
                            <small>From your profile</small>
                        </div>
                    `);
                
                // Calculate distance
                const distance = this.calculateDistance(this.userLat, this.userLng, scholarLat, scholarLng);
                
                // Draw route line
                const latlngs = [
                    [this.userLat, this.userLng],
                    [scholarLat, scholarLng]
                ];
                
                // Add dashed line
                L.polyline(latlngs, {
                    color: '#2196F3',
                    weight: 4,
                    opacity: 0.7,
                    dashArray: '10, 10'
                }).addTo(this.map);
                
                // Add distance label in the middle
                const midPoint = [
                    (this.userLat + scholarLat) / 2,
                    (this.userLng + scholarLng) / 2
                ];
                
                L.marker(midPoint, {
                    icon: L.divIcon({
                        className: 'distance-label',
                        html: `<div style="background: white; padding: 4px 10px; border-radius: 20px; border: 2px solid #2196F3; font-weight: bold; font-size: 12px; box-shadow: 0 2px 5px rgba(0,0,0,0.2);">${distance} km</div>`,
                        iconSize: [80, 30]
                    })
                }).addTo(this.map);
                
                // Fit bounds to show both markers
                const bounds = L.latLngBounds([this.userLat, this.userLng], [scholarLat, scholarLng]);
                const isMobileView = window.matchMedia('(max-width: 768px)').matches;
                this.map.fitBounds(bounds, { padding: isMobileView ? [36, 36] : [70, 70] });
                
                // Add route information panel (simplified)
                const infoControl = L.control({ position: 'bottomleft' });
                infoControl.onAdd = (map) => {
                    this._infoDiv = L.DomUtil.create('div', 'route-info-panel');
                    this._infoDiv.innerHTML = `
                        <div style="background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.2); margin: 10px; min-width: 200px;">
                            <h4 style="margin: 0 0 10px 0; color: #2196F3; border-bottom: 1px solid #eee; padding-bottom: 5px;">
                                <i class="fas fa-route"></i> Route Information
                            </h4>
                            <div style="font-size: 14px;">
                                <div style="margin-bottom: 8px;">
                                    <i class="fas fa-map-marker-alt" style="color: #2196F3;"></i> 
                                    <strong>Distance:</strong> ${distance} km
                                </div>
                                <div style="margin-top: 8px; color: #666; font-size: 11px; border-top: 1px dashed #eee; padding-top: 5px;">
                                    <i class="fas fa-info-circle"></i> Straight line distance
                                </div>
                            </div>
                        </div>
                    `;
                    return this._infoDiv;
                };
                infoControl.addTo(this.map);
            } else {
                // Add info message when no user location
                const infoControl = L.control({ position: 'bottomleft' });
                infoControl.onAdd = (map) => {
                    this._infoDiv = L.DomUtil.create('div', 'no-location-info');
                    this._infoDiv.innerHTML = `
                        <div style="background: #fff3cd; padding: 15px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin: 10px; border-left: 4px solid #ffc107;">
                            <i class="fas fa-exclamation-triangle" style="color: #856404;"></i>
                            <strong style="color: #856404;"> No location found</strong>
                            <p style="margin: 5px 0 0 0; color: #856404; font-size: 12px;">
                                <a href="profile.php#edit" style="color: #856404; font-weight: bold;">Click here</a> to edit your profile location and see route information.
                            </p>
                        </div>
                    `;
                    return this._infoDiv;
                };
                infoControl.addTo(this.map);
            }
            
            // Force map to resize
            setTimeout(() => {
                this.map.invalidateSize();
            }, 120);
            
        }, 300);
    },
    
    // Calculate distance using Haversine formula
    calculateDistance: function(lat1, lon1, lat2, lon2) {
        const R = 6371; // Earth's radius in km
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = 
            Math.sin(dLat/2) * Math.sin(dLat/2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * 
            Math.sin(dLon/2) * Math.sin(dLon/2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        return (R * c).toFixed(1);
    },
    
    // Close map modal
    closeModal: function() {
        const modal = document.getElementById('mapModal');
        if (modal) {
            modal.style.display = 'none';
        }
        document.body.classList.remove('map-modal-open');
        
        // Clean up map
        if (this.map) {
            this.map.remove();
            this.map = null;
            this.routingControl = null;
            this.userMarker = null;
            this.scholarshipMarker = null;
        }
    }
};

// Add CSS styles
function addMapStyles() {
    if (!document.getElementById('map-styles')) {
        const style = document.createElement('style');
        style.id = 'map-styles';
        style.textContent = `
            @keyframes pulse {
                0% { transform: scale(1); opacity: 1; }
                50% { transform: scale(1.2); opacity: 0.7; }
                100% { transform: scale(1); opacity: 1; }
            }
            
            .location-status {
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 10px 20px;
                border-radius: 5px;
                z-index: 1000;
                display: none;
            }
            
            .location-status.success {
                background: #4CAF50;
                color: white;
                display: block;
            }
            
            .location-status.warning {
                background: #ff9800;
                color: white;
                display: block;
            }
            
            .route-info-panel {
                font-family: Arial, sans-serif;
            }
            
            .route-info-panel i {
                width: 20px;
            }
            
            .leaflet-popup-content {
                font-family: Arial, sans-serif;
            }
        `;
        document.head.appendChild(style);
    }
}

// Initialize on load
document.addEventListener('DOMContentLoaded', function() {
    addMapStyles();
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('mapModal');
        if (event.target == modal) {
            ScholarshipMap.closeModal();
        }
    };
    
    // Close with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            ScholarshipMap.closeModal();
        }
    });
});

// Handle window resize
window.addEventListener('resize', function() {
    const modal = document.getElementById('mapModal');
    if (ScholarshipMap.map && modal && modal.style.display === 'block') {
        ScholarshipMap.map.invalidateSize();
    }
});

// Create global shortcuts for backward compatibility
const initMapUserLocation = (lat, lng) => ScholarshipMap.initUserLocation(lat, lng);
const showOnMap = (id, name, lat, lng) => ScholarshipMap.showOnMap(id, name, lat, lng);
const closeMapModal = () => ScholarshipMap.closeModal();
const hasUserLocation = () => ScholarshipMap.hasUserLocation();

