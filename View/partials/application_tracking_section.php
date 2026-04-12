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
    <div class="application-tracking-browser" data-application-tracking-widget>
        <div class="application-tracking-controls">
            <label class="application-tracking-search">
                <i class="fas fa-search"></i>
                <input
                    type="search"
                    class="application-tracking-search-input"
                    data-application-tracking-search
                    placeholder="Search by scholarship, provider, or reference"
                    aria-label="Search applications">
            </label>

            <div class="application-tracking-nav">
                <span class="application-tracking-position" data-application-tracking-position>
                    1 of <?php echo count($applicationTimeline); ?>
                </span>
                <button type="button" class="application-tracking-nav-btn" data-application-tracking-prev>
                    <i class="fas fa-arrow-left"></i> Previous
                </button>
                <button type="button" class="application-tracking-nav-btn is-primary" data-application-tracking-next>
                    Next <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>

        <div class="application-tracking-filter-state" data-application-tracking-empty hidden>
            <i class="fas fa-search"></i>
            <p>No application matches that search yet.</p>
        </div>

        <div class="application-tracking-list">
        <?php foreach ($applicationTimeline as $applicationIndex => $applicationItem): ?>
        <?php
            $applicationStatus = strtolower(trim((string) ($applicationItem['status'] ?? 'pending')));
            $studentResponseStatus = strtolower(trim((string) ($applicationItem['student_response_status'] ?? '')));
            $studentAccepted = $applicationStatus === 'approved' && $studentResponseStatus === 'accepted';
            $assessmentEnabled = !empty($applicationItem['assessment_enabled']);
            $assessmentTypeLabel = trim((string) ($applicationItem['assessment_type_label'] ?? 'Assessment'));
            $assessmentStatus = strtolower(trim((string) ($applicationItem['assessment_status'] ?? 'not_started')));
            $assessmentStatusLabel = trim((string) ($applicationItem['assessment_status_label'] ?? 'Assessment required'));
            $assessmentStatusNote = trim((string) ($applicationItem['assessment_status_note'] ?? 'Assessment updates will appear here after the provider posts them.'));
            $assessmentScheduleLabel = trim((string) ($applicationItem['assessment_schedule_label'] ?? ''));
            $assessmentSiteLabel = trim((string) ($applicationItem['assessment_site_label'] ?? ''));
            $assessmentNotes = trim((string) ($applicationItem['assessment_notes'] ?? ''));
            $assessmentActionUrl = trim((string) ($applicationItem['assessment_action_url'] ?? ''));
            $assessmentActionLabel = trim((string) ($applicationItem['assessment_action_label'] ?? ''));
            $assessmentActionExternal = !empty($applicationItem['assessment_action_external']);
            $assessmentPillClass = in_array($assessmentStatus, ['passed'], true)
                ? 'passed'
                : (in_array($assessmentStatus, ['failed'], true)
                    ? 'failed'
                    : (in_array($assessmentStatus, ['scheduled', 'under_review'], true)
                        ? 'scheduled'
                        : (in_array($assessmentStatus, ['ready', 'submitted'], true) ? 'ready' : 'pending')));
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
            $searchText = strtolower(trim(preg_replace('/\s+/', ' ', implode(' ', [
                $referenceNumber,
                (string) ($applicationItem['scholarship_name'] ?? ''),
                $providerName,
                $statusPillLabel,
                (string) ($applicationItem['current_stage_title'] ?? ''),
                $assessmentTypeLabel,
                $assessmentStatusLabel,
            ])) ?? ''));
        ?>
        <article
            class="application-timeline-item status-<?php echo htmlspecialchars($applicationStatus); ?><?php echo $applicationIndex === 0 ? ' is-active' : ''; ?>"
            data-application-tracking-item
            data-search-text="<?php echo htmlspecialchars($searchText); ?>"
            <?php echo $applicationIndex === 0 ? '' : 'hidden'; ?>>
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

            <?php if ($assessmentEnabled): ?>
            <section class="application-assessment-row status-<?php echo htmlspecialchars($assessmentPillClass); ?>">
                <div class="application-assessment-copy">
                    <div class="application-assessment-head">
                        <span class="application-assessment-pill status-<?php echo htmlspecialchars($assessmentPillClass); ?>">
                            <i class="fas <?php echo htmlspecialchars($assessmentTypeLabel === 'Remote Examination' ? 'fa-map-location-dot' : 'fa-laptop-file'); ?>"></i>
                            <?php echo htmlspecialchars($assessmentTypeLabel); ?>
                        </span>
                        <strong><?php echo htmlspecialchars($assessmentStatusLabel); ?></strong>
                    </div>
                    <p><?php echo htmlspecialchars($assessmentStatusNote); ?></p>

                    <div class="application-assessment-meta">
                        <?php if ($studentAccepted && $assessmentScheduleLabel !== '' && strtolower($assessmentScheduleLabel) !== 'not scheduled yet'): ?>
                        <span>
                            <i class="fas fa-calendar-day"></i>
                            Schedule: <?php echo htmlspecialchars($assessmentScheduleLabel); ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($studentAccepted && $assessmentSiteLabel !== '' && strtolower($assessmentSiteLabel) !== 'no site selected yet'): ?>
                        <span>
                            <i class="fas fa-location-dot"></i>
                            Site: <?php echo htmlspecialchars($assessmentSiteLabel); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($studentAccepted && $assessmentActionUrl !== '' && $assessmentActionLabel !== ''): ?>
                    <a
                        href="<?php echo htmlspecialchars($assessmentActionUrl); ?>"
                        class="btn btn-primary application-assessment-action"
                        <?php echo $assessmentActionExternal ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>
                    >
                        <i class="fas <?php echo htmlspecialchars($assessmentActionExternal ? 'fa-arrow-up-right-from-square' : 'fa-arrow-right'); ?>"></i>
                        <?php echo htmlspecialchars($assessmentActionLabel); ?>
                    </a>
                <?php endif; ?>
            </section>
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
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($applicationTimeline)): ?>
<script>
    (function () {
        const widgets = document.querySelectorAll('[data-application-tracking-widget]');
        if (!widgets.length) {
            return;
        }

        const normalizeSearch = (value) => (value || '')
            .toLowerCase()
            .replace(/\s+/g, ' ')
            .trim();

        widgets.forEach((widget) => {
            const searchInput = widget.querySelector('[data-application-tracking-search]');
            const prevButton = widget.querySelector('[data-application-tracking-prev]');
            const nextButton = widget.querySelector('[data-application-tracking-next]');
            const positionLabel = widget.querySelector('[data-application-tracking-position]');
            const emptyState = widget.querySelector('[data-application-tracking-empty]');
            const items = Array.from(widget.querySelectorAll('[data-application-tracking-item]'));

            if (!searchInput || !prevButton || !nextButton || !positionLabel || !emptyState || !items.length) {
                return;
            }

            let filteredItems = items.slice();
            let activeIndex = 0;

            const render = () => {
                items.forEach((item) => {
                    item.hidden = true;
                    item.classList.remove('is-active');
                });

                if (!filteredItems.length) {
                    emptyState.hidden = false;
                    positionLabel.textContent = '0 of 0';
                    prevButton.disabled = true;
                    nextButton.disabled = true;
                    return;
                }

                emptyState.hidden = true;
                if (activeIndex >= filteredItems.length) {
                    activeIndex = 0;
                }

                const activeItem = filteredItems[activeIndex];
                activeItem.hidden = false;
                activeItem.classList.add('is-active');

                positionLabel.textContent = (activeIndex + 1) + ' of ' + filteredItems.length;
                prevButton.disabled = filteredItems.length <= 1;
                nextButton.disabled = filteredItems.length <= 1;
            };

            const applyFilter = () => {
                const query = normalizeSearch(searchInput.value);
                filteredItems = items.filter((item) => {
                    const searchText = normalizeSearch(item.dataset.searchText || '');
                    return query === '' || searchText.includes(query);
                });
                activeIndex = 0;
                render();
            };

            prevButton.addEventListener('click', () => {
                if (filteredItems.length <= 1) {
                    return;
                }

                activeIndex = (activeIndex - 1 + filteredItems.length) % filteredItems.length;
                render();
            });

            nextButton.addEventListener('click', () => {
                if (filteredItems.length <= 1) {
                    return;
                }

                activeIndex = (activeIndex + 1) % filteredItems.length;
                render();
            });

            searchInput.addEventListener('input', applyFilter);
            render();
        });
    })();
</script>
<?php endif; ?>
