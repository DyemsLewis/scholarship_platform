<div class="module-card application-tracking-card wizard-application-tracking" id="applicationTracking">
    <div class="module-header application-tracking-header">
        <div class="module-icon">
            <i class="fas fa-route"></i>
        </div>
        <div class="application-tracking-copy">
            <h3 class="module-title">Application Tracking</h3>
            <p>Follow the latest progress of your scholarship submissions from review to final decision.</p>
        </div>
    </div>

    <div class="application-overview-grid">
        <div class="application-overview-item">
            <span class="application-overview-label">Total</span>
            <strong><?php echo (int) $applicationStats['total']; ?></strong>
        </div>
        <div class="application-overview-item">
            <span class="application-overview-label">Pending</span>
            <strong><?php echo (int) $applicationStats['pending']; ?></strong>
        </div>
        <div class="application-overview-item">
            <span class="application-overview-label">Approved</span>
            <strong><?php echo (int) $applicationStats['approved']; ?></strong>
        </div>
        <div class="application-overview-item">
            <span class="application-overview-label">Rejected</span>
            <strong><?php echo (int) $applicationStats['rejected']; ?></strong>
        </div>
    </div>

    <?php if (empty($applicationTimeline)): ?>
    <div class="application-tracking-empty">
        <i class="fas fa-folder-open"></i>
        <h4>No applications yet</h4>
        <p>Your submitted scholarship applications will appear here once you start applying.</p>
        <a href="scholarships.php" class="btn btn-primary">Browse Scholarships</a>
    </div>
    <?php else: ?>
    <div class="application-tracking-list">
        <?php foreach ($applicationTimeline as $applicationItem): ?>
        <?php
            $applicationStatus = strtolower(trim((string) ($applicationItem['status'] ?? 'pending')));
            $studentResponseStatus = strtolower(trim((string) ($applicationItem['student_response_status'] ?? '')));
            $studentAccepted = $applicationStatus === 'approved' && $studentResponseStatus === 'accepted';
            $statusPillLabel = $studentAccepted ? 'Accepted' : ucfirst($applicationStatus);
            $statusPillClass = $studentAccepted ? 'accepted' : $applicationStatus;
            $documentNotes = $applicationItem['document_notes'] ?? [];
            $referenceNumber = 'APP-' . str_pad((string) ((int) ($applicationItem['id'] ?? 0)), 5, '0', STR_PAD_LEFT);
            $providerName = trim((string) ($applicationItem['provider'] ?? ''));
            $providerName = $providerName !== '' ? $providerName : 'Scholarship provider';
            $deadlineValue = !empty($applicationItem['deadline'])
                ? applicationTrackingFormatTimelineDate((string) $applicationItem['deadline'], 'Not set')
                : 'Not set';
            $scoreValue = isset($applicationItem['probability_score']) && $applicationItem['probability_score'] !== null
                ? number_format((float) $applicationItem['probability_score'], 1) . '%'
                : 'Not calculated';
            $acceptUrl = buildEntityUrl(
                '../app/Controllers/application_response.php',
                'application',
                (int) ($applicationItem['id'] ?? 0),
                'accept',
                [
                    'action' => 'accept',
                    'id' => (int) ($applicationItem['id'] ?? 0)
                ]
            );
        ?>
        <article class="application-timeline-item status-<?php echo htmlspecialchars($applicationStatus); ?>">
            <div class="application-timeline-top">
                <div class="application-timeline-main">
                    <div class="application-timeline-head">
                        <span class="application-reference"><?php echo htmlspecialchars($referenceNumber); ?></span>
                        <span class="application-status-pill <?php echo htmlspecialchars($statusPillClass); ?>"><?php echo htmlspecialchars($statusPillLabel); ?></span>
                    </div>
                    <h4><?php echo htmlspecialchars((string) ($applicationItem['scholarship_name'] ?? 'Scholarship Application')); ?></h4>
                    <p><?php echo htmlspecialchars($providerName); ?></p>
                </div>
                <div class="application-timeline-summary">
                    <strong><?php echo htmlspecialchars((string) ($applicationItem['current_stage_title'] ?? 'Pending review')); ?></strong>
                    <span><?php echo htmlspecialchars((string) ($applicationItem['current_stage_note'] ?? '')); ?></span>
                </div>
            </div>

            <div class="application-meta-strip">
                <div class="application-meta-pill">
                    <span class="label">Submitted</span>
                    <span class="value"><?php echo htmlspecialchars(applicationTrackingFormatTimelineDate($applicationItem['applied_at'] ?? null)); ?></span>
                </div>
                <div class="application-meta-pill">
                    <span class="label">Deadline</span>
                    <span class="value"><?php echo htmlspecialchars($deadlineValue); ?></span>
                </div>
                <div class="application-meta-pill">
                    <span class="label">Score</span>
                    <span class="value"><?php echo htmlspecialchars($scoreValue); ?></span>
                </div>
                <div class="application-meta-pill">
                    <span class="label">Requirements</span>
                    <span class="value">
                        <?php
                        $totalRequirements = (int) ($applicationItem['documents_total_count'] ?? 0);
                        $verifiedRequirements = (int) ($applicationItem['documents_verified_count'] ?? 0);
                        echo htmlspecialchars($totalRequirements > 0 ? ($verifiedRequirements . '/' . $totalRequirements . ' verified') : 'No requirements');
                        ?>
                    </span>
                </div>
            </div>

            <?php if (!empty($applicationItem['can_accept'])): ?>
            <div class="application-response-row">
                <div class="application-response-copy">
                    <strong>Ready for your confirmation</strong>
                    <p>This application is already approved. Accept the scholarship to record your confirmation and notify the provider.</p>
                </div>
                <form
                    method="POST"
                    action="<?php echo htmlspecialchars($acceptUrl); ?>"
                    class="application-response-form"
                    data-scholarship-name="<?php echo htmlspecialchars((string) ($applicationItem['scholarship_name'] ?? 'this scholarship')); ?>">
                    <input type="hidden" name="action" value="accept">
                    <?php echo csrfInputField('application_accept'); ?>
                    <input type="hidden" name="id" value="<?php echo (int) ($applicationItem['id'] ?? 0); ?>">
                    <button type="submit" class="btn btn-primary application-response-btn">
                        <i class="fas fa-circle-check"></i> Accept Scholarship
                    </button>
                </form>
            </div>
            <?php elseif ($studentAccepted): ?>
            <div class="application-response-row accepted">
                <div class="application-response-copy">
                    <strong>Acceptance recorded</strong>
                    <p><?php echo htmlspecialchars((string) ($applicationItem['student_response_note'] ?? 'Your confirmation has been saved.')); ?></p>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($documentNotes)): ?>
            <details class="application-document-notes">
                <summary>
                    <span class="application-document-notes-label">
                        <i class="fas fa-message"></i>
                        Document notes
                    </span>
                    <span class="application-document-notes-count"><?php echo count($documentNotes); ?></span>
                </summary>
                <div class="application-document-notes-list">
                    <?php foreach ($documentNotes as $documentNote): ?>
                    <div class="application-document-note-item">
                        <div class="application-document-note-head">
                            <strong><?php echo htmlspecialchars((string) ($documentNote['name'] ?? 'Required Document')); ?></strong>
                            <span class="application-document-note-status status-<?php echo htmlspecialchars((string) ($documentNote['status'] ?? 'pending')); ?>">
                                <?php echo htmlspecialchars(ucfirst((string) ($documentNote['status'] ?? 'pending'))); ?>
                            </span>
                        </div>
                        <p><?php echo htmlspecialchars((string) ($documentNote['note'] ?? '')); ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>

            <div class="application-stage-grid">
                <?php foreach (($applicationItem['timeline_steps'] ?? []) as $step): ?>
                <div class="application-stage-item state-<?php echo htmlspecialchars((string) ($step['state'] ?? 'upcoming')); ?>">
                    <div class="application-stage-marker">
                        <i class="fas <?php echo htmlspecialchars((string) ($step['icon'] ?? 'fa-circle')); ?>"></i>
                    </div>
                    <div class="application-stage-copy">
                        <strong><?php echo htmlspecialchars((string) ($step['label'] ?? 'Stage')); ?></strong>
                        <p><?php echo htmlspecialchars((string) ($step['detail'] ?? '')); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
