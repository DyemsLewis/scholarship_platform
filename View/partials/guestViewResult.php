<div id="guestResultsView">
    <div class="guest-warning" style="margin-bottom: 24px;">
        <i class="fas fa-lock"></i>
        <p><strong>Personalized matching requires login.</strong> The Decision Support System analyzes your academic profile to calculate acceptance probabilities for each scholarship.</p>
    </div>

    <?php
    $sampleScholarships = $scholarshipService->getSampleScholarships(2);

    if (empty($sampleScholarships)): ?>
        <div class="guest-warning">
            <i class="fas fa-exclamation-circle"></i>
            <p>No scholarships available at the moment.</p>
        </div>
    <?php else: ?>
        <div class="guest-sample-grid">
            <?php foreach ($sampleScholarships as $scholarship): ?>
                <div class="scholarship-card guest-sample-card">
                    <div class="scholarship-header">
                        <div class="scholarship-name"><?php echo htmlspecialchars($scholarship['name']); ?></div>
                        <div class="probability-badge probability-50">Sample</div>
                    </div>
                    <div class="scholarship-details">
                        <div class="detail-item">
                            <span class="detail-label">Eligibility:</span>
                            <span>
                                <?php
                                echo htmlspecialchars($scholarship['eligibility'] ?? 'Check provider website for details');
                                $requiredGwa = null;
                                if (isset($scholarship['min_gwa']) && $scholarship['min_gwa'] !== null && $scholarship['min_gwa'] !== '') {
                                    $requiredGwa = (float) $scholarship['min_gwa'];
                                }
                                if ($requiredGwa !== null) {
                                    echo ' (Required GWA: &le; ' . number_format($requiredGwa, 2) . ')';
                                }
                                ?>
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Benefit:</span>
                            <span><?php echo htmlspecialchars($scholarship['benefits'] ?? 'Tuition coverage + Allowance'); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Provider:</span>
                            <span><?php echo htmlspecialchars($scholarship['provider']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Deadline:</span>
                            <span>
                                <?php
                                if ($scholarship['deadline']) {
                                    $deadline = date('F d, Y', strtotime($scholarship['deadline']));
                                    echo htmlspecialchars($deadline);
                                } else {
                                    echo 'Open until filled';
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                    <a href="login.php" class="btn btn-outline">Login to Apply</a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
