<script>
// Debug: Log PHP variables
console.log('PHP Variables:', {
    userLatitude: '<?php echo $userLatitude ?? 'null'; ?>',
    userLongitude: '<?php echo $userLongitude ?? 'null'; ?>',
    userHasLocation: '<?php echo ($userLatitude && $userLongitude) ? 'true' : 'false'; ?>'
});

// Wait for everything to load
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($userLatitude && $userLongitude): ?>
        // Convert PHP values to JavaScript numbers
        const userLat = parseFloat('<?php echo $userLatitude; ?>');
        const userLng = parseFloat('<?php echo $userLongitude; ?>');
        
        console.log('✅ Parsed user location:', userLat, userLng);
        
        // Initialize map with user location
        if (!isNaN(userLat) && !isNaN(userLng)) {
            console.log('✅ Calling initMapUserLocation with valid numbers');
            
            // Make sure ScholarshipMap is available
            if (typeof ScholarshipMap !== 'undefined') {
                ScholarshipMap.initUserLocation(userLat, userLng);
            } else {
                console.log('⏳ ScholarshipMap not ready yet, waiting...');
                // Wait for the object to be available
                setTimeout(function() {
                    if (typeof ScholarshipMap !== 'undefined') {
                        ScholarshipMap.initUserLocation(userLat, userLng);
                    } else {
                        console.error('❌ ScholarshipMap not found');
                    }
                }, 500);
            }
        } else {
            console.error('❌ Invalid location values:', userLat, userLng);
        }
    <?php else: ?>
        console.log('❌ No user location available in session');
        // Show warning message
        setTimeout(function() {
            const messageDiv = document.createElement('div');
            messageDiv.className = 'info-message';
            messageDiv.style.cssText = 'background-color: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin-bottom: 15px; text-align: center; border-left: 4px solid #ffc107;';
            messageDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <strong>No location found in your profile.</strong> <a href="profile.php#edit" style="color: #856404; font-weight: bold; text-decoration: underline;">Click here to edit your profile location</a> to see distances and directions to scholarship providers.';
            
            const container = document.querySelector('.section-title');
            if (container) {
                container.insertAdjacentElement('afterend', messageDiv);
            }
        }, 1000);
    <?php endif; ?>
});
</script>
