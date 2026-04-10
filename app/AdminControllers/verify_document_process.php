<?php
// AdminController/verify_document_process.php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/csrf.php';
require_once __DIR__ . '/../Config/provider_scope.php';
require_once __DIR__ . '/../Config/helpers.php';
require_once __DIR__ . '/../Models/UserDocument.php';
require_once __DIR__ . '/../Models/StudentData.php';
require_once __DIR__ . '/../Config/access_control.php';
require_once __DIR__ . '/../Models/ActivityLog.php';
require_once __DIR__ . '/../Models/Notification.php';

header('Content-Type: application/json; charset=UTF-8');

function isGradeReviewDocument(?string $documentType): bool
{
    return in_array((string) $documentType, ['grades', 'form_138'], true);
}

function canEditReviewedGwa(): bool
{
    return isAdminRole();
}

function canModerateDocumentDecision(): bool
{
    return isAdminRole();
}

function normalizeReviewedGwa($value): ?string
{
    $trimmed = trim((string) $value);
    if ($trimmed === '') {
        return null;
    }

    if (!is_numeric($trimmed)) {
        return '';
    }

    $numericValue = (float) $trimmed;
    if ($numericValue < 1.0 || $numericValue > 5.0) {
        return '';
    }

    return number_format($numericValue, 2, '.', '');
}

function getSupportingVerificationConfig(?string $documentType): ?array
{
    $configs = [
        'citizenship_proof' => [
            'field' => 'citizenship',
            'field_label' => 'citizenship / residency',
            'options' => [
                'filipino' => 'Filipino',
                'dual_citizen' => 'Dual Citizen',
                'permanent_resident' => 'Permanent Resident',
                'other' => 'Other / Additional Residency Proof'
            ]
        ],
        'income_proof' => [
            'field' => 'household_income_bracket',
            'field_label' => 'household income bracket',
            'options' => [
                'below_10000' => 'Below PHP 10,000 / month',
                '10000_20000' => 'PHP 10,000 - 20,000 / month',
                '20001_40000' => 'PHP 20,001 - 40,000 / month',
                '40001_80000' => 'PHP 40,001 - 80,000 / month',
                'above_80000' => 'Above PHP 80,000 / month',
                'prefer_not_to_say' => 'Prefer not to say'
            ]
        ],
        'special_category_proof' => [
            'field' => 'special_category',
            'field_label' => 'scholarship category',
            'options' => [
                'pwd' => 'Person with Disability (PWD)',
                'indigenous_peoples' => 'Indigenous Peoples',
                'solo_parent_dependent' => 'Dependent of Solo Parent',
                'working_student' => 'Working Student',
                'child_of_ofw' => 'Child of OFW',
                'four_ps_beneficiary' => '4Ps Beneficiary',
                'orphan' => 'Orphan / Ward'
            ]
        ]
    ];

    return $configs[(string) $documentType] ?? null;
}

if (!isset($_SESSION['user_id']) || !isRoleIn(['provider', 'admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$csrfValidation = csrfValidateRequest('document_review');
if (!$csrfValidation['valid']) {
    echo json_encode(['success' => false, 'message' => $csrfValidation['message']]);
    exit();
}

$action = $_POST['action'] ?? '';
$documentId = $_POST['document_id'] ?? null;
$userId = $_POST['user_id'] ?? null;

if (in_array($action, ['verify', 'reject'], true) && !canModerateDocumentDecision()) {
    echo json_encode(['success' => false, 'message' => 'Only admins can verify or reject documents.']);
    exit();
}

if (!$documentId || !$userId) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

if (!providerCanAccessDocument($pdo, (int) $documentId, (int) $userId)) {
    echo json_encode(['success' => false, 'message' => 'You can only verify documents for applicants in your own scholarship programs.']);
    exit();
}

$documentModel = new UserDocument($pdo);
$documentDetailStmt = $pdo->prepare("
    SELECT
        ud.id,
        ud.document_type,
        ud.status,
        ud.file_name,
        ud.admin_notes,
        ud.rejection_reason,
        dt.name AS document_name,
        CONCAT(COALESCE(sd.firstname, ''), ' ', COALESCE(sd.lastname, '')) AS student_name,
        u.username
    FROM user_documents ud
    JOIN users u ON ud.user_id = u.id
    LEFT JOIN student_data sd ON u.id = sd.student_id
    LEFT JOIN document_types dt ON ud.document_type = dt.code
    WHERE ud.id = ? AND ud.user_id = ?
    LIMIT 1
");
$documentDetailStmt->execute([(int) $documentId, (int) $userId]);
$documentDetails = $documentDetailStmt->fetch(PDO::FETCH_ASSOC) ?: null;

try {
    if ($action === 'verify') {
        $reviewedGwa = null;
        $profileUpdate = [];
        $verificationNote = null;
        if (canEditReviewedGwa() && $documentDetails && isGradeReviewDocument($documentDetails['document_type'] ?? null)) {
            $reviewedGwa = normalizeReviewedGwa($_POST['gwa'] ?? null);

            if ($reviewedGwa === null) {
                echo json_encode(['success' => false, 'message' => 'Enter the reviewed GWA before verifying this document.']);
                exit();
            }

            if ($reviewedGwa === '') {
                echo json_encode(['success' => false, 'message' => 'GWA must be a valid number between 1.00 and 5.00.']);
                exit();
            }
        }

        $supportingVerificationConfig = $documentDetails
            ? getSupportingVerificationConfig($documentDetails['document_type'] ?? null)
            : null;

        if ($supportingVerificationConfig !== null) {
            $verificationValue = trim((string) ($_POST['verification_value'] ?? ''));
            if ($verificationValue === '' || !array_key_exists($verificationValue, $supportingVerificationConfig['options'])) {
                echo json_encode(['success' => false, 'message' => 'Select the verified value before verifying this supporting document.']);
                exit();
            }

            $profileUpdate[$supportingVerificationConfig['field']] = $verificationValue;
            $verificationNote = 'Verified as: ' . $supportingVerificationConfig['options'][$verificationValue];
        }

        $result = $documentModel->verifyDocument($documentId, $userId, $reviewedGwa, $profileUpdate, $verificationNote);
        
        if ($result) {
            if ($documentDetails) {
                $targetName = trim((string) ($documentDetails['student_name'] ?? ''));
                if ($targetName === '') {
                    $targetName = (string) ($documentDetails['username'] ?? 'Student');
                }

                $activityLog = new ActivityLog($pdo);
                $activityLog->log('verify', 'document', 'Verified an uploaded document.', [
                    'entity_id' => (int) $documentId,
                    'entity_name' => (string) ($documentDetails['document_name'] ?? $documentDetails['document_type'] ?? 'Document'),
                    'target_user_id' => (int) $userId,
                    'target_name' => $targetName,
                        'details' => [
                            'file_name' => (string) ($documentDetails['file_name'] ?? ''),
                            'status' => 'verified',
                            'reviewed_gwa' => $reviewedGwa,
                            'verification_note' => $verificationNote,
                            'profile_update' => $profileUpdate
                        ]
                    ]);

                try {
                    $documentLabel = trim((string) ($documentDetails['document_name'] ?? $documentDetails['document_type'] ?? 'Document'));
                    $notificationMessage = $documentLabel . ' was verified successfully.';

                    if ($reviewedGwa !== null && isGradeReviewDocument($documentDetails['document_type'] ?? null)) {
                        $notificationMessage .= ' Your recorded GWA is now ' . number_format((float) $reviewedGwa, 2) . '.';
                    }

                    if ($verificationNote !== null) {
                        $notificationMessage .= ' ' . $verificationNote . '.';
                    }

                    $notificationModel = new Notification($pdo);
                    $notificationModel->createForUser(
                        (int) $userId,
                        'document_verified',
                        'Document verified',
                        $notificationMessage,
                        [
                            'entity_type' => 'document',
                            'entity_id' => (int) $documentId,
                            'link_url' => 'documents.php'
                        ]
                    );
                } catch (Throwable $notificationError) {
                    error_log('verify_document_process verify notification error: ' . $notificationError->getMessage());
                }
            }
            if ($reviewedGwa !== null) {
                $successMessage = 'Document verified and GWA updated successfully.';
            } elseif (!empty($profileUpdate)) {
                $successMessage = 'Supporting document verified and profile value saved successfully.';
            } else {
                $successMessage = 'Document verified successfully';
            }
            echo json_encode(['success' => true, 'message' => $successMessage]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to verify document']);
        }
        
    } elseif ($action === 'save_note') {
        $reviewerNote = trim((string) ($_POST['reviewer_note'] ?? ''));
        $existingAdminNotes = (string) ($documentDetails['admin_notes'] ?? '');
        $systemNote = stripReviewerDocumentNote($existingAdminNotes);
        $updatedAdminNote = composeDocumentAdminNote($systemNote, $reviewerNote);

        $result = $documentModel->saveAdminNote($documentId, $userId, $updatedAdminNote);

        if ($result) {
            if ($documentDetails) {
                $targetName = trim((string) ($documentDetails['student_name'] ?? ''));
                if ($targetName === '') {
                    $targetName = (string) ($documentDetails['username'] ?? 'Student');
                }

                $activityLog = new ActivityLog($pdo);
                $activityLog->log('note', 'document', 'Updated a reviewer note on an uploaded document.', [
                    'entity_id' => (int) $documentId,
                    'entity_name' => (string) ($documentDetails['document_name'] ?? $documentDetails['document_type'] ?? 'Document'),
                    'target_user_id' => (int) $userId,
                    'target_name' => $targetName,
                    'details' => [
                        'file_name' => (string) ($documentDetails['file_name'] ?? ''),
                        'status' => (string) ($documentDetails['status'] ?? ''),
                        'reviewer_note' => $reviewerNote
                    ]
                ]);

                try {
                    $documentLabel = trim((string) ($documentDetails['document_name'] ?? $documentDetails['document_type'] ?? 'Document'));
                    $notificationMessage = $reviewerNote !== ''
                        ? $documentLabel . ' has a new reviewer note: ' . $reviewerNote
                        : $documentLabel . ' reviewer note was cleared.';

                    $notificationModel = new Notification($pdo);
                    $notificationModel->createForUser(
                        (int) $userId,
                        'document_note',
                        'Document note updated',
                        $notificationMessage,
                        [
                            'entity_type' => 'document',
                            'entity_id' => (int) $documentId,
                            'link_url' => 'documents.php'
                        ]
                    );
                } catch (Throwable $notificationError) {
                    error_log('verify_document_process save_note notification error: ' . $notificationError->getMessage());
                }
            }

            echo json_encode([
                'success' => true,
                'message' => $reviewerNote !== '' ? 'Reviewer note saved successfully.' : 'Reviewer note cleared successfully.'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save the reviewer note.']);
        }

    } elseif ($action === 'update_gwa') {
        if (!canEditReviewedGwa()) {
            echo json_encode(['success' => false, 'message' => 'Only admins can update the reviewed GWA.']);
            exit();
        }

        if (!$documentDetails || !isGradeReviewDocument($documentDetails['document_type'] ?? null)) {
            echo json_encode(['success' => false, 'message' => 'GWA can only be updated for TOR or Form 138 documents.']);
            exit();
        }

        $reviewedGwa = normalizeReviewedGwa($_POST['gwa'] ?? null);
        if ($reviewedGwa === null || $reviewedGwa === '') {
            echo json_encode(['success' => false, 'message' => 'GWA must be a valid number between 1.00 and 5.00.']);
            exit();
        }

        $result = $documentModel->saveStudentGwa((int) $userId, $reviewedGwa);

        if ($result) {
            if ($documentDetails) {
                $targetName = trim((string) ($documentDetails['student_name'] ?? ''));
                if ($targetName === '') {
                    $targetName = (string) ($documentDetails['username'] ?? 'Student');
                }

                $activityLog = new ActivityLog($pdo);
                $activityLog->log('update', 'document', 'Updated the reviewed GWA from a grade document.', [
                    'entity_id' => (int) $documentId,
                    'entity_name' => (string) ($documentDetails['document_name'] ?? $documentDetails['document_type'] ?? 'Document'),
                    'target_user_id' => (int) $userId,
                    'target_name' => $targetName,
                    'details' => [
                        'file_name' => (string) ($documentDetails['file_name'] ?? ''),
                        'reviewed_gwa' => $reviewedGwa
                    ]
                ]);
            }

            echo json_encode(['success' => true, 'message' => 'GWA updated successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update the student GWA.']);
        }

    } elseif ($action === 'reject') {
        $reason = $_POST['reason'] ?? '';
        
        if (empty($reason)) {
            echo json_encode(['success' => false, 'message' => 'Rejection reason is required']);
            exit();
        }
        
        $result = $documentModel->rejectDocument($documentId, $userId, $reason);
        
        if ($result) {
            if ($documentDetails) {
                $targetName = trim((string) ($documentDetails['student_name'] ?? ''));
                if ($targetName === '') {
                    $targetName = (string) ($documentDetails['username'] ?? 'Student');
                }

                $activityLog = new ActivityLog($pdo);
                $activityLog->log('reject', 'document', 'Rejected an uploaded document.', [
                    'entity_id' => (int) $documentId,
                    'entity_name' => (string) ($documentDetails['document_name'] ?? $documentDetails['document_type'] ?? 'Document'),
                    'target_user_id' => (int) $userId,
                    'target_name' => $targetName,
                    'details' => [
                        'file_name' => (string) ($documentDetails['file_name'] ?? ''),
                        'status' => 'rejected',
                        'reason' => $reason
                    ]
                ]);

                try {
                    $documentLabel = trim((string) ($documentDetails['document_name'] ?? $documentDetails['document_type'] ?? 'Document'));
                    $notificationModel = new Notification($pdo);
                    $notificationModel->createForUser(
                        (int) $userId,
                        'document_rejected',
                        'Document rejected',
                        $documentLabel . ' was rejected. Reason: ' . $reason,
                        [
                            'entity_type' => 'document',
                            'entity_id' => (int) $documentId,
                            'link_url' => 'documents.php'
                        ]
                    );
                } catch (Throwable $notificationError) {
                    error_log('verify_document_process reject notification error: ' . $notificationError->getMessage());
                }
            }
            echo json_encode(['success' => true, 'message' => 'Document rejected successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to reject document']);
        }
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log("Document verification error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>
