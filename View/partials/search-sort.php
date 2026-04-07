<div class="scholarship-toolbar">
    <div class="toolbar-search">
        <i class="fas fa-search"></i>
        <input type="text" id="searchScholarship" placeholder="Search scholarships, providers, or benefits">
    </div>

    <div class="toolbar-controls">
        <div class="toolbar-select">
            <i class="fas fa-sort"></i>
            <select id="sortScholarships">
                <option value="requirements-high">Requirements Ready (High to Low)</option>
                <option value="requirements-low">Requirements Ready (Low to High)</option>
                <option value="name-asc">Name (A to Z)</option>
                <option value="name-desc">Name (Z to A)</option>
                <option value="deadline-asc">Deadline (Soonest)</option>
                <option value="deadline-desc">Deadline (Latest)</option>
                <option value="distance-asc">Distance (Nearest first)</option>
                <option value="distance-desc">Distance (Farthest first)</option>
            </select>
        </div>

        <label class="toolbar-checkbox">
            <input type="checkbox" id="showOnlyEligible">
            <span>Show only ready to apply</span>
        </label>
    </div>
</div>

<div class="toolbar-meta">
    <div id="resultsCount" class="toolbar-results-count"></div>
    <div id="activeFilters" class="toolbar-active-filters"></div>
</div>
