// Map variables
        let map = null;
        let routingControl = null;
        let userMarker = null;
        let scholarshipMarker = null;
        
        // User location from PHP session
        const userLat = <?php echo $userLatitude ?: 'null'; ?>;
        const userLng = <?php echo $userLongitude ?: 'null'; ?>;
        
        function showOnMap(scholarshipId, scholarshipName, lat, lng) {
            // Show modal
            document.getElementById('mapModal').style.display = 'block';
            document.getElementById('mapModalTitle').textContent = scholarshipName + ' - Location';
            
            // Initialize map after modal is visible
            setTimeout(function() {
                if (!map) {
                    // Create map
                    map = L.map('map').setView([lat, lng], 13);
                    
                    // Add tile layer
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                    }).addTo(map);
                } else {
                    // Clear existing markers and routing
                    if (userMarker) map.removeLayer(userMarker);
                    if (scholarshipMarker) map.removeLayer(scholarshipMarker);
                    if (routingControl) map.removeControl(routingControl);
                    
                    // Reset view
                    map.setView([lat, lng], 13);
                }
                
                // Add scholarship marker
                scholarshipMarker = L.marker([lat, lng]).addTo(map)
                    .bindPopup(`
                        <b>${scholarshipName}</b><br>
                        Location: ${lat.toFixed(4)}, ${lng.toFixed(4)}<br>
                        <i>Scholarship provider location</i>
                    `).openPopup();
                
                // If user has location, add user marker and routing
                if (userLat && userLng) {
                    // Add user marker
                    userMarker = L.marker([userLat, userLng], {
                        icon: L.divIcon({
                            className: 'user-location-marker',
                            html: '<i class="fas fa-user-circle" style="font-size: 30px; color: #2196F3;"></i>',
                            iconSize: [30, 30]
                        })
                    }).addTo(map)
                        .bindPopup('<b>Your Location</b>').openPopup();
                    
                    // Add routing
                    routingControl = L.Routing.control({
                        waypoints: [
                            L.latLng(userLat, userLng),
                            L.latLng(lat, lng)
                        ],
                        routeWhileDragging: false,
                        showAlternatives: false,
                        fitSelectedRoutes: true,
                        lineOptions: {
                            styles: [{color: '#6366f1', weight: 4}]
                        },
                        createMarker: function() { return null; } // Don't create markers (we already have them)
                    }).addTo(map);
                    
                    // Fit bounds to show both markers
                    const bounds = L.latLngBounds([userLat, userLng], [lat, lng]);
                    map.fitBounds(bounds, {padding: [50, 50]});
                }
            }, 100);
        }
        
        function closeMapModal() {
            document.getElementById('mapModal').style.display = 'none';
            
            // Clean up map when modal is closed
            if (map) {
                map.remove();
                map = null;
                routingControl = null;
                userMarker = null;
                scholarshipMarker = null;
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('mapModal');
            if (event.target == modal) {
                closeMapModal();
            }
        }
        
        // JavaScript for dynamic behavior
        document.addEventListener('DOMContentLoaded', function() {
            const isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
            const hasGWA = <?php echo ($userGWA !== null) ? 'true' : 'false'; ?>;
            
            if (isLoggedIn && hasGWA) {
                // Add animation to match percentages
                const badges = document.querySelectorAll('.probability-badge');
                badges.forEach(badge => {
                    badge.style.animation = 'fadeIn 0.5s ease-in';
                });
                
                // Add tooltip functionality
                const scholarshipCards = document.querySelectorAll('.scholarship-card');
                scholarshipCards.forEach(card => {
                    card.addEventListener('mouseenter', function() {
                        this.style.transform = 'translateY(-5px)';
                        this.style.boxShadow = '0 10px 20px rgba(0,0,0,0.1)';
                    });
                    
                    card.addEventListener('mouseleave', function() {
                        this.style.transform = 'translateY(0)';
                        this.style.boxShadow = '0 5px 15px rgba(0,0,0,0.05)';
                    });
                });
            }
            
            // If user doesn't have location, show a message
            <?php if ($isLoggedIn && !$userHasLocation): ?>
            setTimeout(function() {
                const messageDiv = document.createElement('div');
                messageDiv.className = 'info-message';
                messageDiv.style.cssText = 'background-color: #e3f2fd; color: #0d47a1; padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center;';
                messageDiv.innerHTML = '<i class="fas fa-info-circle"></i> Enable location services to see distances and directions to scholarship providers.';
                
                const container = document.querySelector('.section-title');
                if (container) {
                    container.insertAdjacentElement('afterend', messageDiv);
                }
            }, 1000);
            <?php endif; ?>
        });
        
        // Add CSS animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .probability-70 { 
                background: linear-gradient(135deg, #4CAF50, #2E7D32); 
                color: white;
            }
            .probability-50 { 
                background: linear-gradient(135deg, #FF9800, #EF6C00); 
                color: white;
            }
            .probability-30 { 
                background: linear-gradient(135deg, #F44336, #C62828); 
                color: white;
            }
            .highlight-warning { color: #FF9800; font-weight: bold; }
            .deadline-warning { color: #f44336; font-weight: bold; }
            .scholarship-card.not-eligible { opacity: 0.7; }
            .eligibility-warning { color: #f44336; font-size: 0.9em; margin-top: 5px; }
            .eligibility-warning i { margin-right: 5px; }
            
            /* User location marker */
            .user-location-marker {
                background: none;
                border: none;
            }
            
            /* Modal styles */
            .modal {
                animation: fadeIn 0.3s;
            }
            
            .modal-content {
                animation: slideIn 0.3s;
            }
            
            @keyframes slideIn {
                from {
                    transform: translateY(-30px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
            
            /* Map container */
            #map {
                z-index: 1;
            }
            
            /* Distance indicator */
            .detail-item i.fa-map-marker-alt {
                margin-right: 5px;
            }
        `;
        document.head.appendChild(style);
