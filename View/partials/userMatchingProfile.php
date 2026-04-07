<div id="userResultsView">
    <div class="results-section" style="padding: 0;">
        <div style="background: linear-gradient(135deg, var(--primary), #1e3a6b); padding: 20px; border-radius: 12px 12px 0 0; color: white;">
            <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
                <div style="width: 64px; height: 64px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(0,0,0,0.2); border: 3px solid rgba(255,255,255,0.3);">
                    <span style="font-size: 1.6rem; color: var(--primary); font-weight: bold;">
                        <?php echo strtoupper(substr((string)($userName ?? 'U'), 0, 1)); ?>
                    </span>
                </div>
                <div style="flex: 1;">
                    <h2 style="color: white; margin: 0 0 4px; font-size: 1.3rem;">
                        <?php echo htmlspecialchars($userName ?? 'Student'); ?>
                    </h2>
                    <p style="margin: 0; color: rgba(255,255,255,0.9); font-size: 0.85rem;">
                        <?php echo $userGWA ? 'Profile Ready' : 'Upload grades to complete profile'; ?>
                    </p>
                </div>
            </div>
        </div>

        <?php
        $verifiedDocs = isset($documentVerifiedCount) ? (int) $documentVerifiedCount : 0;
        $pendingDocs = isset($documentPendingCount) ? (int) $documentPendingCount : 0;
        ?>
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin: 18px 20px 20px 20px;">
            <div style="background: #f8fafc; padding: 12px 10px; border-radius: 8px; text-align: center;">
                <div style="font-size: 0.72rem; color: var(--gray); margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">GWA</div>
                <div style="font-size: 1.15rem; font-weight: 700; color: var(--primary);">
                    <?php echo $userGWA ? number_format((float)$userGWA, 2) : '-'; ?>
                </div>
            </div>

            <div style="background: #f8fafc; padding: 12px 10px; border-radius: 8px; text-align: center;">
                <div style="font-size: 0.72rem; color: var(--gray); margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Documents</div>
                <div style="font-size: 1.15rem; font-weight: 700; color: var(--primary);">
                    <?php echo $verifiedDocs; ?>
                </div>
                <div style="font-size: 0.72rem; color: var(--gray); margin-top: 2px;">
                    <?php echo $pendingDocs > 0 ? ($pendingDocs . ' pending') : 'all checked'; ?>
                </div>
            </div>
        </div>
    </div>
</div>
