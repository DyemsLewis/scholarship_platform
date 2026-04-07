// scholarship-modal.js - Updated without map
let map = null;
let marker = null;

function openScholarshipModal(scholarshipId) {
    const modal = document.getElementById('scholarshipModal');
    const overlay = document.getElementById('modalOverlay');
    const loading = document.getElementById('modalLoading');
    const content = document.getElementById('modalContent');
    const footer = document.getElementById('modalFooter');
    
    // Reset modal position and styles
    modal.style.display = 'block';
    overlay.style.display = 'block';
    loading.style.display = 'block';
    content.style.display = 'none';
    footer.style.display = 'none';
    
    // Ensure close button is visible
    const closeBtn = document.querySelector('.close-modal');
    if (closeBtn) {
        closeBtn.style.display = 'flex';
        closeBtn.style.zIndex = '1003';
    }
    
    // Reset modal scroll
    modal.scrollTop = 0;
    
    // Fetch scholarship details
    fetch('../Controller/get_scholarship_details.php?id=' + scholarshipId)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Update modal content
                document.getElementById('modalTitle').textContent = data.name;
                document.getElementById('modalProvider').innerHTML = `
                    <i class="fas fa-building"></i>
                    <span>${data.provider}</span>
                `;
                const hasRequiredGwa = data.required_gwa !== null && data.required_gwa !== '' && !Number.isNaN(Number(data.required_gwa));
                document.getElementById('modalGWA').innerHTML = hasRequiredGwa
                    ? `<span class="gwa-requirement">Required GWA: ${Number(data.required_gwa).toFixed(2)} or better</span>`
                    : `<span class="gwa-requirement">No GWA requirement</span>`;
                // Format deadline
                let deadlineHtml = `<i class="fas fa-calendar-alt"></i> ${data.deadline}`;
                if (data.days_left && data.days_left <= 7) {
                    deadlineHtml += `<br><small style="color: #dc2626; font-weight: 600;">(${data.days_left} days left)</small>`;
                }
                document.getElementById('modalDeadline').innerHTML = deadlineHtml;
                
                // Format location
                let locationText = 'Location not specified';
                if (data.address || data.city || data.province) {
                    locationText = `${data.address ? data.address + ', ' : ''}${data.city ? data.city + ', ' : ''}${data.province || ''}`;
                }
                document.getElementById('modalLocation').innerHTML = `<i class="fas fa-map-marker-alt"></i> ${locationText}`;
                
                // Format status
                const statusColor = data.status === 'active' ? '#059669' : '#9ca3af';
                document.getElementById('modalStatus').innerHTML = `
                    <span style="color: ${statusColor}; font-weight: 600;">
                        <i class="fas fa-${data.status === 'active' ? 'check-circle' : 'circle'}"></i> 
                        ${data.status === 'active' ? 'Active' : 'Inactive'}
                    </span>
                `;
                
                document.getElementById('modalEligibility').textContent = data.eligibility;
                
                // Format benefits as list
                let benefitsHtml = '';
                if (data.benefits) {
                    const benefits = data.benefits.split(',').map(benefit => benefit.trim());
                    benefitsHtml = benefits.map(benefit => `
                        <div style="display: flex; align-items: flex-start; margin-bottom: 8px;">
                            <i class="fas fa-check" style="color: #059669; margin-right: 10px; margin-top: 3px;"></i>
                            <span>${benefit}</span>
                        </div>
                    `).join('');
                } else {
                    benefitsHtml = '<p>No benefits specified</p>';
                }
                document.getElementById('modalBenefits').innerHTML = benefitsHtml;
                
                // Add description
                document.getElementById('modalDescription').innerHTML = `
                    <h4 style="margin-top: 30px; color: #4b5563; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
                        <i class="fas fa-info-circle" style="margin-right: 8px; color: #2c5aa0;"></i> Description
                    </h4>
                    <p style="line-height: 1.6; color: #4b5563; margin-top: 15px;">${data.description || 'No description available'}</p>
                `;
                
                // Hide loading, show content
                loading.style.display = 'none';
                content.style.display = 'block';
                footer.style.display = 'flex';
            } else {
                console.error('API returned error:', data.message);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.message || 'Failed to load scholarship details'
                });
                closeModal();
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to load scholarship details. Error: ' + error.message
            });
            closeModal();
        });
}

function showLocationOnMap(latitude, longitude, name) {
    const modal = document.getElementById('scholarshipModal');
    const overlay = document.getElementById('modalOverlay');
    const loading = document.getElementById('modalLoading');
    const content = document.getElementById('modalContent');
    const footer = document.getElementById('modalFooter');
    
    // Reset modal position and styles
    modal.style.display = 'block';
    overlay.style.display = 'block';
    loading.style.display = 'none';
    content.style.display = 'grid';
    footer.style.display = 'flex';
    
    // Reset map container
    const mapContainer = document.getElementById('scholarshipMap');
    if (mapContainer) {
        mapContainer.innerHTML = ''; // Clear previous map
    }
    
    // Clear map if exists
    if (map) {
        map.remove();
        map = null;
    }
    
    // Set minimal content for location view
    document.getElementById('modalTitle').textContent = name;
    document.getElementById('modalProvider').innerHTML = `
        <i class="fas fa-building"></i>
        <span>Location Information</span>
    `;
    
    // Hide other sections and show only map
    const modalDetails = document.querySelector('.modal-details');
    const eligibilityBox = document.querySelector('.eligibility-box');
    const benefitsBox = document.querySelector('.benefits-box');
    const modalDescription = document.getElementById('modalDescription');
    
    if (modalDetails) modalDetails.style.display = 'none';
    if (eligibilityBox) eligibilityBox.style.display = 'none';
    if (benefitsBox) benefitsBox.style.display = 'none';
    if (modalDescription) modalDescription.style.display = 'none';
    
    // Show map container
    const modalContent = document.querySelector('.modal-content-wrapper');
    if (modalContent) {
        modalContent.innerHTML = `
            <div class="modal-map-full">
                <div id="scholarshipMap" style="height: 400px; width: 100%;"></div>
            </div>
        `;
    }
    
    // Initialize map with location
    setTimeout(() => {
        initMap(latitude, longitude, name);
    }, 100);
    
    // Force redraw
    setTimeout(() => {
        if (map) {
            map.invalidateSize();
        }
    }, 200);
}

function initMap(latitude, longitude, title) {
    // Initialize map
    const mapContainer = document.getElementById('scholarshipMap');
    if (!mapContainer) return;
    
    map = L.map(mapContainer).setView([latitude, longitude], 13);
    
    // Add OpenStreetMap tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);
    
    // Custom marker icon
    const customIcon = L.divIcon({
        html: `<div style="
            background: linear-gradient(135deg, #2c5aa0 0%, #4a7bc8 100%);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            box-shadow: 0 2px 10px rgba(44, 90, 160, 0.3);
            border: 3px solid white;
        ">
            <i class="fas fa-map-marker-alt"></i>
        </div>`,
        className: 'custom-marker',
        iconSize: [40, 40],
        iconAnchor: [20, 40]
    });
    
    // Add marker
    marker = L.marker([latitude, longitude], { icon: customIcon })
        .addTo(map)
        .bindPopup(`<b>${title}</b>`);
    
    // Open popup by default
    marker.openPopup();
}

function closeModal() {
    const modal = document.getElementById('scholarshipModal');
    const overlay = document.getElementById('modalOverlay');
    
    modal.style.display = 'none';
    overlay.style.display = 'none';
    
    // Remove map
    if (map) {
        map.remove();
        map = null;
    }
    
    // Reset modal content to original state for next time
    const modalContent = document.querySelector('.modal-content-wrapper');
    if (modalContent) {
        modalContent.innerHTML = `
            <div class="modal-info-full">
                <div class="modal-details">
                    <div class="detail-row">
                        <span class="detail-label">GWA Requirement:</span>
                        <span class="detail-value" id="modalGWA"></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Deadline:</span>
                        <span class="detail-value" id="modalDeadline"></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Location:</span>
                        <span class="detail-value" id="modalLocation"></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value" id="modalStatus"></span>
                    </div>
                </div>
                
                <div class="eligibility-box">
                    <h4><i class="fas fa-check-circle"></i> Eligibility Requirements</h4>
                    <p id="modalEligibility"></p>
                </div>
                
                <div class="benefits-box">
                    <h4><i class="fas fa-gift"></i> Benefits</h4>
                    <div id="modalBenefits"></div>
                </div>
                
                <div id="modalDescription"></div>
            </div>
        `;
    }
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // Close modal when clicking outside
    const overlay = document.getElementById('modalOverlay');
    if (overlay) {
        overlay.addEventListener('click', closeModal);
    }
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });
    
    // Prevent modal close when clicking inside modal
    const modal = document.getElementById('scholarshipModal');
    if (modal) {
        modal.addEventListener('click', function(event) {
            event.stopPropagation();
        });
    }
    
    // Add event listeners to all details buttons
    const detailsButtons = document.querySelectorAll('.details-btn');
    detailsButtons.forEach(button => {
        button.addEventListener('click', function(event) {
            event.stopPropagation();
            const scholarshipId = this.getAttribute('data-id');
            openScholarshipModal(scholarshipId);
        });
    });
    
    // Add event listeners to location buttons
    const locationButtons = document.querySelectorAll('.location-btn:not(.disabled)');
    locationButtons.forEach(button => {
        button.addEventListener('click', function(event) {
            event.stopPropagation();
            const lat = this.getAttribute('data-lat');
            const lng = this.getAttribute('data-lng');
            const name = this.getAttribute('data-name');
            
            if (lat && lng) {
                showLocationOnMap(parseFloat(lat), parseFloat(lng), name);
            }
        });
    });
    
    // Also add event listeners to the entire scholarship card if desired
    const scholarshipCards = document.querySelectorAll('.scholarship-card');
    scholarshipCards.forEach(card => {
        card.addEventListener('click', function(event) {
            // Don't trigger if clicking on the button (to avoid duplicate events)
            if (!event.target.closest('.details-btn') && 
                !event.target.closest('.location-btn') &&
                !event.target.classList.contains('details-btn') &&
                !event.target.classList.contains('location-btn')) {
                const scholarshipId = this.getAttribute('data-id');
                openScholarshipModal(scholarshipId);
            }
        });
    });
});
