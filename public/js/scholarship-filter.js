// Search and Sort Functionality
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded - initializing scholarship filter');
    
    const searchInput = document.getElementById('searchScholarship');
    const sortSelect = document.getElementById('sortScholarships');
    const eligibleCheckbox = document.getElementById('showOnlyEligible');
    const scholarshipsContainer = document.getElementById('scholarshipsContainer');
    const resultsCount = document.getElementById('resultsCount');

    function createNoResultsMessage(searchTerm, showOnlyEligible) {
        const wrapper = document.createElement('div');
        wrapper.id = 'noResultsMessage';
        wrapper.className = 'scholarship-empty-state scholarship-empty-state-filter';

        const message = showOnlyEligible
            ? 'Try turning off "Show only ready to apply" or update your profile and documents to unlock more scholarships.'
            : 'Try changing your search term or clearing the filters to see more scholarship matches.';

        wrapper.innerHTML = `
            <div class="scholarship-empty-icon">
                <i class="fas fa-search"></i>
            </div>
            <div class="scholarship-empty-copy">
                <span class="scholarship-empty-kicker">Search Result</span>
                <h3>No matching scholarships found</h3>
                <p>${message}</p>
            </div>
            <div class="scholarship-empty-actions">
                <button type="button" class="scholarship-empty-action" data-clear-scholarship-filters="true">
                    <i class="fas fa-sliders"></i>
                    Clear Filters
                </button>
            </div>
        `;

        return wrapper;
    }
    
    // Debug: Check if elements exist
    console.log('Elements found:', {
        searchInput: !!searchInput,
        sortSelect: !!sortSelect,
        eligibleCheckbox: !!eligibleCheckbox,
        scholarshipsContainer: !!scholarshipsContainer,
        resultsCount: !!resultsCount
    });
    
    // Function to refresh scholarships list
    function refreshScholarshipsList() {
        return Array.from(document.querySelectorAll('.scholarship-card'));
    }
    
    function filterAndSortScholarships() {
        console.log('Filtering and sorting with sort value:', sortSelect ? sortSelect.value : 'no sort select');
        
        // Get fresh list of scholarships
        const allScholarships = refreshScholarshipsList();
        
        if (allScholarships.length === 0) {
            console.log('No scholarships found');
            return;
        }
        
        // Debug: Log first card's data attributes
        console.log('Sample card data:', {
            name: allScholarships[0].dataset.name,
            requirements: allScholarships[0].dataset.requirements || allScholarships[0].dataset.match,
            deadline: allScholarships[0].dataset.deadline,
            distance: allScholarships[0].dataset.distance,
            eligible: allScholarships[0].dataset.eligible
        });
        
        const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const sortValue = sortSelect ? sortSelect.value : 'requirements-high';
        const showOnlyEligible = eligibleCheckbox ? eligibleCheckbox.checked : false;
        
        console.log('Filter params:', { searchTerm, sortValue, showOnlyEligible });
        
        // First, filter the scholarships
        let filteredScholarships = allScholarships.filter(card => {
            // Get data attributes with fallbacks
            const name = (card.dataset.name || '').toLowerCase();
            const provider = (card.dataset.provider || '').toLowerCase();
            const benefits = (card.dataset.benefits || '').toLowerCase();
            const isEligible = card.dataset.eligible === 'true';
            
            // Search in name, provider, and benefits
            const matchesSearch = searchTerm === '' || 
                name.includes(searchTerm) || 
                provider.includes(searchTerm) || 
                benefits.includes(searchTerm);
            
            // Filter by eligibility if checkbox is checked
            const matchesEligible = !showOnlyEligible || isEligible;
            
            return matchesSearch && matchesEligible;
        });
        
        console.log('After filtering:', filteredScholarships.length, 'scholarships');
        
        // Then, sort the filtered scholarships
        if (sortValue && filteredScholarships.length > 0) {
            filteredScholarships.sort((a, b) => {
                const aExpired = a.dataset.expired === 'true';
                const bExpired = b.dataset.expired === 'true';

                if (aExpired !== bExpired) {
                    return aExpired ? 1 : -1;
                }

                let comparison = 0;
                
                switch(sortValue) {
                    case 'requirements-high':
                        const requirementsAHigh = parseFloat(a.dataset.requirements || a.dataset.match) || 0;
                        const requirementsBHigh = parseFloat(b.dataset.requirements || b.dataset.match) || 0;
                        comparison = requirementsBHigh - requirementsAHigh;
                        break;
                        
                    case 'requirements-low':
                        const requirementsALow = parseFloat(a.dataset.requirements || a.dataset.match) || 0;
                        const requirementsBLow = parseFloat(b.dataset.requirements || b.dataset.match) || 0;
                        comparison = requirementsALow - requirementsBLow;
                        break;
                        
                    case 'name-asc':
                        comparison = (a.dataset.name || '').localeCompare(b.dataset.name || '');
                        break;
                        
                    case 'name-desc':
                        comparison = (b.dataset.name || '').localeCompare(a.dataset.name || '');
                        break;
                        
                    case 'deadline-asc':
                        const deadlineA_asc = a.dataset.deadline || '9999-12-31';
                        const deadlineB_asc = b.dataset.deadline || '9999-12-31';
                        comparison = deadlineA_asc.localeCompare(deadlineB_asc);
                        break;
                        
                    case 'deadline-desc':
                        const deadlineA_desc = a.dataset.deadline || '9999-12-31';
                        const deadlineB_desc = b.dataset.deadline || '9999-12-31';
                        comparison = deadlineB_desc.localeCompare(deadlineA_desc);
                        break;
                        
                    case 'distance-asc':
                        const distA_asc = parseFloat(a.dataset.distance) || 999999;
                        const distB_asc = parseFloat(b.dataset.distance) || 999999;
                        comparison = distA_asc - distB_asc;
                        break;
                        
                    case 'distance-desc':
                        const distA_desc = parseFloat(a.dataset.distance) || 0;
                        const distB_desc = parseFloat(b.dataset.distance) || 0;
                        comparison = distB_desc - distA_desc;
                        break;
                        
                    default:
                        comparison = 0;
                }
                
                return comparison;
            });
            
            // Log first few sorted items to verify sorting
            console.log('First 3 sorted items:', filteredScholarships.slice(0, 3).map(card => ({
                name: card.dataset.name,
                requirements: card.dataset.requirements || card.dataset.match,
                distance: card.dataset.distance,
                deadline: card.dataset.deadline
            })));
        }
        
        // CRITICAL FIX: Reorder the DOM elements
        // First, hide all scholarships
        allScholarships.forEach(card => {
            card.style.display = 'none';
            card.dataset.filterVisible = 'false';
        });
        
        // Then, append the filtered and sorted scholarships back to the container in the correct order
        // This physically reorders them in the DOM
        filteredScholarships.forEach(card => {
            card.style.display = 'block';
            card.dataset.filterVisible = 'true';
            scholarshipsContainer.appendChild(card); // This moves the element to the end
        });
        
        // Update results count
        const totalScholarships = allScholarships.length;
        const visibleCount = filteredScholarships.length;
        
        if (resultsCount) {
            if (searchTerm || showOnlyEligible) {
                resultsCount.innerHTML = `Showing ${visibleCount} of ${totalScholarships} scholarships`;
            } else {
                resultsCount.innerHTML = `Showing all ${totalScholarships} scholarships`;
            }
        }
        
        // Show/hide no results message
        let noResultsMsg = document.getElementById('noResultsMessage');
        if (filteredScholarships.length === 0) {
            if (!noResultsMsg) {
                noResultsMsg = createNoResultsMessage(searchTerm, showOnlyEligible);
                scholarshipsContainer.appendChild(noResultsMsg);
            }
        } else if (noResultsMsg) {
            noResultsMsg.remove();
        }

        document.dispatchEvent(new CustomEvent('card-pagination:update', {
            detail: {
                containerId: 'scholarshipsContainer',
                resetPage: true
            }
        }));

        console.log('Filter complete. Showing', visibleCount, 'of', totalScholarships);
    }
    
    // Add event listeners
    if (searchInput) {
        searchInput.addEventListener('input', filterAndSortScholarships);
        console.log('Added input listener to search');
    }
    
    if (sortSelect) {
        sortSelect.addEventListener('change', function(e) {
            console.log('Sort changed to:', e.target.value);
            filterAndSortScholarships();
        });
        console.log('Added change listener to sort select');
    }
    
    if (eligibleCheckbox) {
        eligibleCheckbox.addEventListener('change', filterAndSortScholarships);
        console.log('Added change listener to eligibility checkbox');
    }

    document.addEventListener('click', function (event) {
        const clearButton = event.target.closest('[data-clear-scholarship-filters="true"]');
        if (!clearButton) {
            return;
        }

        if (searchInput) {
            searchInput.value = '';
        }
        if (eligibleCheckbox) {
            eligibleCheckbox.checked = false;
        }
        if (sortSelect) {
            sortSelect.value = 'requirements-high';
        }

        filterAndSortScholarships();
    });
    
    // Add clear search button functionality
    if (searchInput) {
        const searchContainer = searchInput.parentElement;
        if (searchContainer) {
            // Check if clear button already exists
            let clearButton = searchContainer.querySelector('.fa-times-circle');
            
            if (!clearButton) {
                clearButton = document.createElement('i');
                clearButton.className = 'fas fa-times-circle';
                clearButton.style.cursor = 'pointer';
                clearButton.style.color = '#999';
                clearButton.style.display = 'none';
                clearButton.style.marginLeft = '10px';
                
                // Style for positioning
                if (window.getComputedStyle(searchContainer).position === 'static') {
                    searchContainer.style.position = 'relative';
                }
                clearButton.style.position = 'absolute';
                clearButton.style.right = '15px';
                clearButton.style.top = '50%';
                clearButton.style.transform = 'translateY(-50%)';
                clearButton.style.zIndex = '10';
                
                searchContainer.appendChild(clearButton);
                
                clearButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (searchInput) {
                        searchInput.value = '';
                        clearButton.style.display = 'none';
                        filterAndSortScholarships();
                        searchInput.focus();
                    }
                });
                
                searchInput.addEventListener('input', function() {
                    clearButton.style.display = this.value ? 'block' : 'none';
                });
            }
        }
    }
    
    // Run the default sort immediately, then once more after late assets finish loading.
    console.log('Running initial filter');
    filterAndSortScholarships();

    window.addEventListener('load', function() {
        filterAndSortScholarships();
    }, { once: true });
});

