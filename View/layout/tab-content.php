<?php
$profileApplicantTypeLabel = formatApplicantTypeLabel($userApplicantType ?? '');
$profileAdmissionStatusLabel = formatAdmissionStatusLabel($userAdmissionStatus ?? '');
$profileYearLevelLabel = formatYearLevelLabel($userYearLevel ?? '');
$profileEnrollmentStatusLabel = formatEnrollmentStatusLabel($userEnrollmentStatus ?? '');
$profileAcademicStandingLabel = formatAcademicStandingLabel($userAcademicStanding ?? '');
$profileCitizenshipLabel = formatCitizenshipLabel($userCitizenship ?? '');
$profileHouseholdIncomeLabel = formatHouseholdIncomeBracketLabel($userHouseholdIncomeBracket ?? '');
$profileSpecialCategoryLabel = formatSpecialCategoryLabel($userSpecialCategory ?? '');
$profileGenderKey = strtolower(trim((string) ($userGender ?? '')));
$profileGenderMap = [
    'male' => ['label' => 'Male', 'icon' => 'mars'],
    'female' => ['label' => 'Female', 'icon' => 'venus'],
    'other' => ['label' => 'Other', 'icon' => 'genderless'],
    'prefer_not_to_say' => ['label' => 'Prefer not to say', 'icon' => 'genderless']
];
$profileGenderMeta = $profileGenderMap[$profileGenderKey] ?? null;
$isIncomingApplicant = ($userApplicantType ?? '') === 'incoming_freshman';
$isCurrentlyEnrolled = in_array((string) ($userEnrollmentStatus ?? ''), ['currently_enrolled', 'regular', 'irregular'], true);
$showShsDetails = !$isCurrentlyEnrolled;
$showCollegeProgressFields = !$isIncomingApplicant;
$uploadedProfileAvatarUrl = !empty($userProfileImageUrl) ? $userProfileImageUrl : null;
$profileAvatarUrl = $uploadedProfileAvatarUrl ?: getDefaultProfileImageUrl('../');
$editMiddleInitialValue = strtoupper(substr(str_replace('.', '', trim((string) ($userMiddleInitial ?? ''))), 0, 1));
$normalizedEditMobileNumber = normalizePhilippineMobileNumber($userMobileNumber ?? '');
$editLocalMobileNumberValue = $normalizedEditMobileNumber !== null ? substr($normalizedEditMobileNumber, 3) : '';
?>
<style>
    .profile-overview-hero {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-bottom: 20px;
        background: linear-gradient(135deg, var(--primary), #1e3a6b);
        padding: 20px;
        border-radius: 12px;
        color: white;
    }

    .profile-quick-stats {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 20px;
    }

    .profile-info-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .profile-name-grid {
        display: grid;
        grid-template-columns: 2fr 1fr 2fr;
        gap: 8px;
        margin-bottom: 12px;
    }

    .profile-two-col-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .profile-address-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        margin-top: 6px;
    }

    .profile-account-status {
        background: #f8fafc;
        padding: 12px;
        border-radius: 8px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        font-size: 0.85rem;
    }

    .profile-account-meta {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .profile-form-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .profile-phone-input-shell {
        position: relative;
        display: flex;
        align-items: center;
    }

    .profile-phone-input-icon {
        position: absolute;
        left: 12px;
        color: var(--primary);
        font-size: 0.95rem;
        z-index: 1;
    }

    .profile-phone-prefix {
        position: absolute;
        left: 40px;
        color: var(--dark);
        font-weight: 600;
        font-size: 0.9rem;
        z-index: 1;
        user-select: none;
    }

    .profile-phone-input-shell input[type="text"] {
        width: 100%;
        min-width: 0;
        padding: 8px 10px 8px 80px !important;
        font-size: 0.9rem;
    }

    .profile-avatar-circle {
        width: 70px;
        height: 70px;
        background: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        border: 3px solid rgba(255,255,255,0.3);
        overflow: hidden;
        flex-shrink: 0;
    }

    .profile-avatar-circle img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .profile-avatar-upload {
        display: grid;
        gap: 10px;
        margin-bottom: 14px;
        padding: 12px;
        border-radius: 10px;
        background: #f8fafc;
        border: 1px solid #dbe4ee;
    }

    .profile-avatar-upload-row {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .profile-avatar-upload-copy {
        min-width: 0;
        flex: 1;
    }

    .profile-avatar-upload-copy strong {
        display: block;
        color: var(--dark);
        font-size: 0.9rem;
        margin-bottom: 4px;
    }

    .profile-avatar-upload-copy p {
        margin: 0;
        color: var(--gray);
        font-size: 0.75rem;
        line-height: 1.5;
    }

    .profile-avatar-preview {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        background: #ffffff;
        border: 2px solid #dbe7fb;
        box-shadow: 0 8px 18px rgba(44, 90, 160, 0.12);
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--primary);
        font-size: 1.3rem;
        font-weight: 700;
        flex-shrink: 0;
    }

    .profile-avatar-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .profile-info-grid > div,
    .profile-name-grid > div,
    .profile-two-col-grid > div,
    .profile-address-grid > div {
        min-width: 0;
    }

    @media (max-width: 768px) {
        .profile-overview-hero {
            flex-direction: column;
            align-items: flex-start;
        }

        .profile-overview-hero .btn {
            width: 100%;
            justify-content: center;
        }

        .profile-info-grid,
        .profile-two-col-grid,
        .profile-address-grid {
            grid-template-columns: 1fr !important;
        }

        .profile-name-grid {
            grid-template-columns: 1fr 1fr !important;
        }

        .profile-name-grid > div:last-child {
            grid-column: 1 / -1;
        }

        .profile-account-status {
            flex-direction: column;
            align-items: stretch;
        }

        .profile-account-meta {
            flex-wrap: wrap;
        }

        .profile-form-actions .btn {
            flex: 1 1 100%;
        }
    }

    @media (max-width: 576px) {
        .profile-quick-stats {
            grid-template-columns: 1fr !important;
        }

        .profile-name-grid {
            grid-template-columns: 1fr !important;
        }

        .profile-name-grid > div:last-child {
            grid-column: auto;
        }
    }
</style>
<div style="padding: 20px;">
    <!-- VIEW PROFILE TAB - REDESIGNED (COMPACT VERSION) -->
    <div id="profileViewContent">
        <!-- Profile Header with Avatar -->
        <div class="profile-overview-hero">
            <!-- Avatar -->
            <div class="profile-avatar-circle">
                <img src="<?php echo htmlspecialchars($profileAvatarUrl); ?>" alt="<?php echo htmlspecialchars($userDisplayName); ?> profile picture">
            </div>
            
            <!-- Basic Info -->
            <div style="flex: 1;">
                <h2 style="color: white; margin: 0 0 5px; font-size: 1.4rem;">
                    <?php echo htmlspecialchars($userDisplayName); ?>
                </h2>
                <p style="color: rgba(255,255,255,0.9); margin: 0; font-size: 0.9rem; display: flex; align-items: center; gap: 5px;">
                    <i class="fas fa-graduation-cap"></i> 
                    <?php echo htmlspecialchars($userCourse ?: 'Student'); ?>
                </p>
            </div>
            
            <!-- Edit Button -->
            <button class="btn" onclick="switchProfileTab('edit')" style="background: rgba(255,255,255,0.2); color: white; border: none; padding: 8px 15px; border-radius: 20px; font-size: 0.85rem;">
                <i class="fas fa-edit"></i> Edit
            </button>
        </div>
        
        <!-- Quick Stats Row - Now only 3 items (removed age) -->
        <div class="profile-quick-stats">
            <div style="background: #f8fafc; padding: 12px 5px; border-radius: 8px; text-align: center;">
                <div style="font-size: 1.3rem; font-weight: bold; color: var(--primary); line-height: 1.2;">
                    <?php echo $userGWA ? number_format((float)$userGWA, 2) : 'N/A'; ?>
                </div>
                <div style="color: var(--gray); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px;">GWA</div>
            </div>
            
            <div style="background: #f8fafc; padding: 12px 5px; border-radius: 8px; text-align: center;">
                <div style="font-size: 1.3rem; font-weight: bold; color: var(--primary); line-height: 1.2;">
                    <?php echo $matchesCount; ?>
                </div>
                <div style="color: var(--gray); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px;">Matches</div>
            </div>
            
            <div style="background: #f8fafc; padding: 12px 5px; border-radius: 8px; text-align: center;">
                <div style="font-size: 1.3rem; font-weight: bold; color: var(--primary); line-height: 1.2;">
                    <?php echo isset($documentStats) ? ($documentStats['verified'] ?? 0) : 0; ?>
                </div>
                <div style="color: var(--gray); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px;">Verified</div>
            </div>
        </div>
        
        <!-- Personal Information - Compact Grid with Age and Birthdate -->
        <div style="margin-bottom: 15px;">
            <h4 style="color: var(--dark); font-size: 1rem; margin: 0 0 10px; display: flex; align-items: center; gap: 5px;">
                <i class="fas fa-user-circle" style="color: var(--primary); font-size: 0.9rem;"></i>
                Personal Info
            </h4>
            
            <div class="profile-info-grid">
                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">Username</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <?php echo htmlspecialchars($userDisplayName); ?>
                    </div>
                </div>
                
                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">Full Name</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <?php 
                        $fullName = trim($userFirstName . ' ' . $userMiddleInitial . ' ' . $userLastName);
                        echo htmlspecialchars($fullName ?: 'Not set'); 
                        ?>
                    </div>
                </div>
                
                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">Email</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem; word-break: break-all;">
                        <?php echo htmlspecialchars($userEmail); ?>
                    </div>
                </div>
                
                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">Gender</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <?php 
                        if ($profileGenderMeta) {
                            echo '<i class="fas fa-' . $profileGenderMeta['icon'] . '" style="margin-right: 5px; color: var(--primary);"></i>';
                            echo htmlspecialchars($profileGenderMeta['label']);
                        } else {
                            echo 'Not set';
                        }
                        ?>
                    </div>
                </div>
                
                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">Birthdate</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-birthday-cake" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php 
                        if (!empty($userBirthdate)) {
                            echo date('F j, Y', strtotime($userBirthdate));
                        } else {
                            echo 'Not set';
                        }
                        ?>
                    </div>
                </div>
                
                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">Age</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-calendar-alt" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php 
                        if (!empty($userAge)) {
                            echo htmlspecialchars($userAge) . ' years old';
                        } elseif (!empty($userBirthdate)) {
                            $age = getAgeFromBirthdate($userBirthdate);
                            echo $age . ' years old';
                        } else {
                            echo 'Not set';
                        }
                        ?>
                    </div>
                </div>
                
                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">School</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-university" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($userSchool ?: 'Not set'); ?>
                    </div>
                </div>
                
                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">Course</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-book-open" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($userCourse ?: 'Not set'); ?>
                    </div>
                </div>

                <div style="background: #f8fafc; padding: 10px; border-radius: 8px; grid-column: 1 / -1;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">Address</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-location-dot" style="margin-right: 5px; color: #10b981;"></i>
                        <?php
                        $profileAddressText = trim(implode(', ', array_filter([
                            $userHouseNo ?? '',
                            $userStreet ?? '',
                            $userBarangay ?? '',
                            $userCity ?? '',
                            $userProvince ?? ''
                        ])));
                        if ($profileAddressText === '') {
                            $profileAddressText = trim((string) ($userAddress ?? ''));
                        }
                        echo htmlspecialchars($profileAddressText !== '' ? $profileAddressText : 'Not set');
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <div style="margin-bottom: 15px;">
            <h4 style="color: var(--dark); font-size: 1rem; margin: 0 0 10px; display: flex; align-items: center; gap: 5px;">
                <i class="fas fa-graduation-cap" style="color: var(--primary); font-size: 0.9rem;"></i>
                Academic Background
            </h4>

            <div class="profile-info-grid">
                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">Applicant Type</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-user-graduate" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($profileApplicantTypeLabel); ?>
                    </div>
                </div>

                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;"><?php echo $isIncomingApplicant ? 'Admission Status' : 'Enrollment Status'; ?></div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-clipboard-check" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($isIncomingApplicant ? $profileAdmissionStatusLabel : $profileEnrollmentStatusLabel); ?>
                    </div>
                </div>

                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;"><?php echo $isIncomingApplicant ? 'Current / Last School' : 'College / University'; ?></div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-university" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($userSchool ?: 'Not set'); ?>
                    </div>
                </div>

                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;"><?php echo $isIncomingApplicant ? 'Planned Course' : 'Course'; ?></div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-book-open" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($userCourse ?: 'Not set'); ?>
                    </div>
                </div>

                <?php if ($isIncomingApplicant): ?>
                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">Target College</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-school" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($userTargetCollege ?: 'Not set'); ?>
                    </div>
                </div>
                <?php endif; ?>

                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">Target Course</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-compass-drafting" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($userTargetCourse ?: $userCourse ?: 'Not set'); ?>
                    </div>
                </div>

                <?php if ($showCollegeProgressFields): ?>
                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">Year Level</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-layer-group" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($profileYearLevelLabel); ?>
                    </div>
                </div>

                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">Academic Standing</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-award" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($profileAcademicStandingLabel); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($showShsDetails): ?>
                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">SHS School</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-school" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($userShsSchool ?: 'Not set'); ?>
                    </div>
                </div>

                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">SHS Strand</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-sitemap" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($userShsStrand ?: 'Not set'); ?>
                    </div>
                </div>

                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">SHS Graduation Year</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-calendar-day" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($userShsGraduationYear ?: 'Not set'); ?>
                    </div>
                </div>

                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">SHS Average</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-percent" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($userShsAverage ?: 'Not set'); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div style="margin-bottom: 15px;">
            <h4 style="color: var(--dark); font-size: 1rem; margin: 0 0 10px; display: flex; align-items: center; gap: 5px;">
                <i class="fas fa-id-card-clip" style="color: var(--primary); font-size: 0.9rem;"></i>
                Scholarship Background
            </h4>

            <div class="profile-info-grid">
                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">Mobile Number</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-phone" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($userMobileNumber ?: 'Not set'); ?>
                    </div>
                </div>

                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">Citizenship</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-flag" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($profileCitizenshipLabel); ?>
                    </div>
                </div>

                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">Household Income</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-wallet" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($profileHouseholdIncomeLabel); ?>
                    </div>
                </div>

                <div style="background: #f8fafc; padding: 10px; border-radius: 8px;">
                    <div style="color: var(--gray); font-size: 0.7rem; margin-bottom: 2px;">Special Category</div>
                    <div style="font-weight: 500; color: var(--dark); font-size: 0.9rem;">
                        <i class="fas fa-users-rectangle" style="margin-right: 5px; color: var(--primary);"></i>
                        <?php echo htmlspecialchars($profileSpecialCategoryLabel); ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- GWA Row -->
        <div style="margin-bottom: 15px;">
            <!-- GWA Card -->
            <div style="background: linear-gradient(135deg, #f0f4ff, #ffffff); padding: 12px; border-radius: 10px; border: 1px solid #e0e7ff;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 35px; height: 35px; background: var(--primary); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-chart-line" style="color: white; font-size: 1rem;"></i>
                    </div>
                    <div>
                        <div style="color: var(--gray); font-size: 0.7rem;">GWA</div>
                        <div style="font-size: 1.3rem; font-weight: bold; color: var(--primary); line-height: 1;">
                            <?php echo $userGWA ? number_format((float)$userGWA, 2) : 'N/A'; ?>
                        </div>
                        <?php if($userGWA): ?>
                        <div style="color: var(--gray); font-size: 0.65rem;">
                            <?php 
                            if($userGWA <= 1.5) echo 'Excellent';
                            elseif($userGWA <= 1.75) echo 'Very Good';
                            elseif($userGWA <= 2.0) echo 'Good';
                            else echo 'Satisfactory';
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Account Status - Compact -->
        <div class="profile-account-status">
            <div class="profile-account-meta">
                <span style="background: #10b981; color: white; padding: 3px 10px; border-radius: 15px; font-size: 0.75rem; font-weight: 500;">
                    <i class="fas fa-check-circle" style="margin-right: 3px;"></i>Active
                </span>
                <span style="color: var(--gray);">
                    <i class="far fa-calendar-alt"></i> Since <?php echo isset($userCreatedAt) ? date('M Y', strtotime($userCreatedAt)) : 'N/A'; ?>
                </span>
            </div>
            
            <button class="btn btn-outline" onclick="switchProfileTab('password')" style="padding: 5px 12px; font-size: 0.75rem;">
                <i class="fas fa-lock"></i> Security
            </button>
        </div>
    </div>
    
    <!-- EDIT PROFILE TAB -->
    <div id="profileEditContent" style="display: none;">
        <h3 style="margin-bottom: 15px; color: var(--primary); font-size: 1.2rem;">Edit Profile</h3>
        
        <form id="editProfileForm" enctype="multipart/form-data">
            <!-- Username display (read-only) -->
            <div style="margin-bottom: 12px;">
                <label for="editUsername" style="font-size: 0.8rem;">Username</label>
                <input type="text" id="editUsername" value="<?php echo htmlspecialchars($userDisplayName); ?>" disabled style="width: 100%; padding: 8px; font-size: 0.9rem; background: #f5f5f5;">
                <small style="font-size: 0.7rem;">Username cannot be changed</small>
            </div>

            <div class="profile-avatar-upload">
                <div class="profile-avatar-upload-row">
                <div class="profile-avatar-preview" id="profileAvatarPreview" data-initials="<?php echo htmlspecialchars(getUserInitials($userDisplayName)); ?>">
                    <span id="profileAvatarPreviewFallback" hidden><?php echo htmlspecialchars(getUserInitials($userDisplayName)); ?></span>
                    <img src="<?php echo htmlspecialchars($profileAvatarUrl); ?>" data-original-src="<?php echo htmlspecialchars($profileAvatarUrl); ?>" alt="<?php echo htmlspecialchars($userDisplayName); ?> profile picture" id="profileAvatarPreviewImage">
                </div>
                    <div class="profile-avatar-upload-copy">
                        <strong>Profile Picture</strong>
                        <p>Upload a clear square photo. JPG, PNG, or WEBP up to 3MB.</p>
                    </div>
                </div>
                <div>
                    <label for="editProfileImage" style="font-size: 0.8rem;">Choose image</label>
                    <input type="file" id="editProfileImage" name="profile_image" accept="image/jpeg,image/png,image/webp" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                    <small id="editProfileImageStatus" style="font-size: 0.7rem; display: block; margin-top: 6px; color: var(--gray);">
                        <?php echo $uploadedProfileAvatarUrl ? 'Current photo is saved. Upload a new one to replace it.' : 'Using the default avatar. Upload a photo to replace it.'; ?>
                    </small>
                </div>
            </div>
            
            <!-- Name Fields -->
            <div class="profile-name-grid">
                <div>
                    <label for="editFirstName" style="font-size: 0.8rem;">First *</label>
                    <input type="text" id="editFirstName" name="firstname" 
                            value="<?php echo htmlspecialchars($userFirstName); ?>" 
                            required style="width: 100%; padding: 8px; font-size: 0.9rem;">
                </div>
                
                <div>
                    <label for="editMiddleInitial" style="font-size: 0.8rem;">MI</label>
                    <input type="text" id="editMiddleInitial" name="middleinitial" 
                           value="<?php echo htmlspecialchars($editMiddleInitialValue); ?>" 
                           maxlength="1" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                </div>
                
                <div>
                    <label for="editLastName" style="font-size: 0.8rem;">Last *</label>
                    <input type="text" id="editLastName" name="lastname" 
                            value="<?php echo htmlspecialchars($userLastName); ?>" 
                            required style="width: 100%; padding: 8px; font-size: 0.9rem;">
                </div>
            </div>
            
            <div style="margin-bottom: 12px;">
                <label for="editEmail" style="font-size: 0.8rem;">Email</label>
                <input type="email" id="editEmail" value="<?php echo htmlspecialchars($userEmail); ?>" disabled style="width: 100%; padding: 8px; font-size: 0.9rem; background: #f5f5f5;">
            </div>
            
            <!-- Birthdate and Gender -->
            <div class="profile-two-col-grid" style="margin-bottom: 12px;">
                <div>
                    <label for="editBirthdate" style="font-size: 0.8rem;">Birthdate</label>
                    <input type="text" id="editBirthdate" value="<?php echo !empty($userBirthdate) ? date('F j, Y', strtotime($userBirthdate)) : 'Not set'; ?>" disabled style="width: 100%; padding: 8px; font-size: 0.9rem; background: #f5f5f5;">
                </div>
                
                <div>
                    <label for="editGender" style="font-size: 0.8rem;">Gender</label>
                    <select id="editGender" name="gender" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                        <option value="">Select gender</option>
                        <option value="male" <?php echo ($profileGenderKey === 'male') ? 'selected' : ''; ?>>Male</option>
                        <option value="female" <?php echo ($profileGenderKey === 'female') ? 'selected' : ''; ?>>Female</option>
                        <option value="other" <?php echo ($profileGenderKey === 'other') ? 'selected' : ''; ?>>Other</option>
                        <option value="prefer_not_to_say" <?php echo ($profileGenderKey === 'prefer_not_to_say') ? 'selected' : ''; ?>>Prefer not to say</option>
                    </select>
                </div>
            </div>
            <small style="font-size: 0.7rem; display: block; margin-bottom: 12px;">Birthdate stays read-only here, but you can update gender directly.</small>
            
            <div style="margin-bottom: 12px;">
                <label for="editApplicantType" style="font-size: 0.8rem;">Applicant Type *</label>
                <select id="editApplicantType" name="applicant_type" required style="width: 100%; padding: 8px; font-size: 0.9rem;">
                    <option value="">Select applicant type</option>
                    <option value="incoming_freshman" <?php echo ($userApplicantType ?? '') === 'incoming_freshman' ? 'selected' : ''; ?>>Incoming Freshman</option>
                    <option value="current_college" <?php echo ($userApplicantType ?? '') === 'current_college' ? 'selected' : ''; ?>>Current College Student</option>
                    <option value="transferee" <?php echo ($userApplicantType ?? '') === 'transferee' ? 'selected' : ''; ?>>Transferee</option>
                    <option value="continuing_student" <?php echo ($userApplicantType ?? '') === 'continuing_student' ? 'selected' : ''; ?>>Continuing Student</option>
                </select>
            </div>

            <div class="profile-two-col-grid" style="margin-bottom: 15px;">
                <div>
                    <label for="editSchool" id="editSchoolLabel" style="font-size: 0.8rem;"><?php echo $isIncomingApplicant ? 'Current / Last School *' : 'College / University *'; ?></label>
                    <input type="text" id="editSchool" name="school" 
                            value="<?php echo htmlspecialchars($userSchool); ?>" 
                            required style="width: 100%; padding: 8px; font-size: 0.9rem;">
                </div>
                
                <div>
                    <label for="editCourse" id="editCourseLabel" style="font-size: 0.8rem;"><?php echo $isIncomingApplicant ? 'Planned Course *' : 'Course *'; ?></label>
                    <input type="text" id="editCourse" name="course" 
                            value="<?php echo htmlspecialchars($userCourse); ?>" 
                            required style="width: 100%; padding: 8px; font-size: 0.9rem;">
                </div>
            </div>

            <div style="margin-bottom: 14px; padding: 12px; border-radius: 8px; background: #f8fafc; border: 1px solid #dbe4ee;">
                <div style="font-size: 0.85rem; font-weight: 600; color: var(--primary); margin-bottom: 10px;">
                    <i class="fas fa-graduation-cap"></i> Academic Background
                </div>

                <div class="profile-two-col-grid" style="margin-bottom: 10px;">
                    <div>
                        <label for="editAdmissionStatus" id="editAdmissionStatusLabel" style="font-size: 0.75rem;"><?php echo $isIncomingApplicant ? 'Admission Status *' : 'Admission / Enrollment Status'; ?></label>
                        <select id="editAdmissionStatus" name="admission_status" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                            <option value="">Select status</option>
                            <option value="not_yet_applied" <?php echo ($userAdmissionStatus ?? '') === 'not_yet_applied' ? 'selected' : ''; ?>>Not Yet Applied</option>
                            <option value="applied" <?php echo ($userAdmissionStatus ?? '') === 'applied' ? 'selected' : ''; ?>>Applied</option>
                            <option value="admitted" <?php echo ($userAdmissionStatus ?? '') === 'admitted' ? 'selected' : ''; ?>>Admitted</option>
                            <option value="enrolled" <?php echo ($userAdmissionStatus ?? '') === 'enrolled' ? 'selected' : ''; ?>>Enrolled</option>
                        </select>
                    </div>

                    <div class="edit-current-student-field">
                        <label for="editYearLevel" style="font-size: 0.75rem;">Year Level *</label>
                        <select id="editYearLevel" name="year_level" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                            <option value="">Select year level</option>
                            <option value="1st_year" <?php echo ($userYearLevel ?? '') === '1st_year' ? 'selected' : ''; ?>>1st Year</option>
                            <option value="2nd_year" <?php echo ($userYearLevel ?? '') === '2nd_year' ? 'selected' : ''; ?>>2nd Year</option>
                            <option value="3rd_year" <?php echo ($userYearLevel ?? '') === '3rd_year' ? 'selected' : ''; ?>>3rd Year</option>
                            <option value="4th_year" <?php echo ($userYearLevel ?? '') === '4th_year' ? 'selected' : ''; ?>>4th Year</option>
                            <option value="5th_year_plus" <?php echo ($userYearLevel ?? '') === '5th_year_plus' ? 'selected' : ''; ?>>5th Year+</option>
                        </select>
                    </div>

                    <div class="edit-current-student-field">
                        <label for="editEnrollmentStatus" style="font-size: 0.75rem;">Enrollment Status *</label>
                        <select id="editEnrollmentStatus" name="enrollment_status" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                            <option value="">Select enrollment status</option>
                            <option value="currently_enrolled" <?php echo ($userEnrollmentStatus ?? '') === 'currently_enrolled' ? 'selected' : ''; ?>>Currently Enrolled</option>
                            <option value="regular" <?php echo ($userEnrollmentStatus ?? '') === 'regular' ? 'selected' : ''; ?>>Regular</option>
                            <option value="irregular" <?php echo ($userEnrollmentStatus ?? '') === 'irregular' ? 'selected' : ''; ?>>Irregular</option>
                            <option value="leave_of_absence" <?php echo ($userEnrollmentStatus ?? '') === 'leave_of_absence' ? 'selected' : ''; ?>>Leave of Absence</option>
                        </select>
                    </div>

                    <div class="edit-current-student-field">
                        <label for="editAcademicStanding" style="font-size: 0.75rem;">Academic Standing</label>
                        <select id="editAcademicStanding" name="academic_standing" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                            <option value="">Select standing</option>
                            <option value="good_standing" <?php echo ($userAcademicStanding ?? '') === 'good_standing' ? 'selected' : ''; ?>>Good Standing</option>
                            <option value="deans_list" <?php echo ($userAcademicStanding ?? '') === 'deans_list' ? 'selected' : ''; ?>>Dean's List</option>
                            <option value="probationary" <?php echo ($userAcademicStanding ?? '') === 'probationary' ? 'selected' : ''; ?>>Probationary</option>
                            <option value="graduating" <?php echo ($userAcademicStanding ?? '') === 'graduating' ? 'selected' : ''; ?>>Graduating</option>
                        </select>
                    </div>

                    <div class="edit-incoming-field">
                        <label for="editShsSchool" style="font-size: 0.75rem;">Senior High School *</label>
                        <input type="text" id="editShsSchool" name="shs_school" value="<?php echo htmlspecialchars($userShsSchool ?? ''); ?>" placeholder="Senior high school name" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                    </div>

                    <div class="edit-incoming-field">
                        <label for="editShsStrand" style="font-size: 0.75rem;">SHS Strand *</label>
                        <input type="text" id="editShsStrand" name="shs_strand" value="<?php echo htmlspecialchars($userShsStrand ?? ''); ?>" placeholder="e.g., STEM, ABM, HUMSS" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                    </div>

                    <div class="edit-incoming-field">
                        <label for="editShsGraduationYear" style="font-size: 0.75rem;">SHS Graduation Year *</label>
                        <input type="number" id="editShsGraduationYear" name="shs_graduation_year" value="<?php echo htmlspecialchars((string) ($userShsGraduationYear ?? '')); ?>" placeholder="e.g., 2026" min="2000" max="<?php echo date('Y') + 6; ?>" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                    </div>

                    <div class="edit-incoming-field">
                        <label for="editTargetCollege" style="font-size: 0.75rem;">Preferred College / University *</label>
                        <input type="text" id="editTargetCollege" name="target_college" value="<?php echo htmlspecialchars($userTargetCollege ?? ''); ?>" placeholder="College you plan to attend" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                    </div>

                    <div>
                        <label for="editTargetCourse" style="font-size: 0.75rem;">Target Course</label>
                        <input type="text" id="editTargetCourse" name="target_course" value="<?php echo htmlspecialchars($userTargetCourse ?? $userCourse ?? ''); ?>" placeholder="Planned or preferred course" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                    </div>
                </div>
                <small style="font-size: 0.7rem;">Incoming freshmen use SHS and admission details. Current college students use year level and enrollment details.</small>
            </div>

            <div style="margin-bottom: 14px; padding: 12px; border-radius: 8px; background: #f8fafc; border: 1px solid #dbe4ee;">
                <div style="font-size: 0.85rem; font-weight: 600; color: var(--primary); margin-bottom: 10px;">
                    <i class="fas fa-id-card-clip"></i> Scholarship Background
                </div>

                <div class="profile-two-col-grid">
                    <div>
                        <label for="editMobileNumber" style="font-size: 0.75rem;">Mobile Number</label>
                        <div class="profile-phone-input-shell">
                            <span class="profile-phone-input-icon"><i class="fas fa-phone"></i></span>
                            <span class="profile-phone-prefix">+63</span>
                            <input type="text" id="editMobileNumber" inputmode="numeric" maxlength="10" value="<?php echo htmlspecialchars($editLocalMobileNumberValue); ?>" placeholder="9123456789" style="width: 100%; font-size: 0.9rem;">
                            <input type="hidden" id="editMobileNumberHidden" name="mobile_number" value="<?php echo htmlspecialchars($normalizedEditMobileNumber ?? ''); ?>">
                        </div>
                        <small style="font-size: 0.7rem; display: block; margin-top: 6px;">Enter the 10-digit mobile number after the country code.</small>
                    </div>

                    <div>
                        <label for="editCitizenship" style="font-size: 0.75rem;">Citizenship</label>
                        <select id="editCitizenship" name="citizenship" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                            <option value="">Select citizenship</option>
                            <option value="filipino" <?php echo ($userCitizenship ?? '') === 'filipino' ? 'selected' : ''; ?>>Filipino</option>
                            <option value="dual_citizen" <?php echo ($userCitizenship ?? '') === 'dual_citizen' ? 'selected' : ''; ?>>Dual Citizen</option>
                            <option value="permanent_resident" <?php echo ($userCitizenship ?? '') === 'permanent_resident' ? 'selected' : ''; ?>>Permanent Resident</option>
                            <option value="other" <?php echo ($userCitizenship ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <div>
                        <label for="editHouseholdIncomeBracket" style="font-size: 0.75rem;">Household Income Bracket</label>
                        <select id="editHouseholdIncomeBracket" name="household_income_bracket" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                            <option value="">Select income bracket</option>
                            <option value="below_10000" <?php echo ($userHouseholdIncomeBracket ?? '') === 'below_10000' ? 'selected' : ''; ?>>Below PHP 10,000 / month</option>
                            <option value="10000_20000" <?php echo ($userHouseholdIncomeBracket ?? '') === '10000_20000' ? 'selected' : ''; ?>>PHP 10,000 - 20,000 / month</option>
                            <option value="20001_40000" <?php echo ($userHouseholdIncomeBracket ?? '') === '20001_40000' ? 'selected' : ''; ?>>PHP 20,001 - 40,000 / month</option>
                            <option value="40001_80000" <?php echo ($userHouseholdIncomeBracket ?? '') === '40001_80000' ? 'selected' : ''; ?>>PHP 40,001 - 80,000 / month</option>
                            <option value="above_80000" <?php echo ($userHouseholdIncomeBracket ?? '') === 'above_80000' ? 'selected' : ''; ?>>Above PHP 80,000 / month</option>
                            <option value="prefer_not_to_say" <?php echo ($userHouseholdIncomeBracket ?? '') === 'prefer_not_to_say' ? 'selected' : ''; ?>>Prefer not to say</option>
                        </select>
                    </div>

                    <div>
                        <label for="editSpecialCategory" style="font-size: 0.75rem;">Special Scholarship Category</label>
                        <select id="editSpecialCategory" name="special_category" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                            <option value="">Select category</option>
                            <option value="none" <?php echo ($userSpecialCategory ?? '') === 'none' ? 'selected' : ''; ?>>None</option>
                            <option value="pwd" <?php echo ($userSpecialCategory ?? '') === 'pwd' ? 'selected' : ''; ?>>Person with Disability (PWD)</option>
                            <option value="indigenous_peoples" <?php echo ($userSpecialCategory ?? '') === 'indigenous_peoples' ? 'selected' : ''; ?>>Indigenous Peoples</option>
                            <option value="solo_parent_dependent" <?php echo ($userSpecialCategory ?? '') === 'solo_parent_dependent' ? 'selected' : ''; ?>>Dependent of Solo Parent</option>
                            <option value="working_student" <?php echo ($userSpecialCategory ?? '') === 'working_student' ? 'selected' : ''; ?>>Working Student</option>
                            <option value="child_of_ofw" <?php echo ($userSpecialCategory ?? '') === 'child_of_ofw' ? 'selected' : ''; ?>>Child of OFW</option>
                            <option value="four_ps_beneficiary" <?php echo ($userSpecialCategory ?? '') === 'four_ps_beneficiary' ? 'selected' : ''; ?>>4Ps Beneficiary</option>
                            <option value="orphan" <?php echo ($userSpecialCategory ?? '') === 'orphan' ? 'selected' : ''; ?>>Orphan / Ward</option>
                        </select>
                    </div>
                </div>
                <small style="font-size: 0.7rem; display: block; margin-top: 8px;">These details are optional, but they help scholarship providers review eligibility and priority sectors.</small>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="font-size: 0.8rem;">Address</label>
                <div class="profile-address-grid">
                    <div>
                        <label for="editHouseNo" style="font-size: 0.75rem;">House #</label>
                        <input type="text" id="editHouseNo" name="house_no" value="<?php echo htmlspecialchars($userHouseNo ?? ''); ?>" placeholder="e.g., BLK 1 LOT 2" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                    </div>
                    <div>
                        <label for="editStreet" style="font-size: 0.75rem;">Street</label>
                        <input type="text" id="editStreet" name="street" value="<?php echo htmlspecialchars($userStreet ?? ''); ?>" placeholder="Street name" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                    </div>
                    <div>
                        <label for="editBarangay" style="font-size: 0.75rem;">Barangay</label>
                        <input type="text" id="editBarangay" name="barangay" value="<?php echo htmlspecialchars($userBarangay ?? ''); ?>" placeholder="Barangay" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                    </div>
                    <div>
                        <label for="editCity" style="font-size: 0.75rem;">City / Municipality</label>
                        <input type="text" id="editCity" name="city" value="<?php echo htmlspecialchars($userCity ?? ''); ?>" placeholder="City or municipality" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label for="editProvince" style="font-size: 0.75rem;">Province</label>
                        <input type="text" id="editProvince" name="province" value="<?php echo htmlspecialchars($userProvince ?? ''); ?>" placeholder="Province" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                    </div>
                </div>
            </div>

            <div style="margin-bottom: 14px; padding: 10px; border-radius: 8px; background: #f8fafc; border: 1px solid #dbe4ee;">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                    <span style="font-size: 0.8rem; color: var(--dark);">
                        <i class="fas fa-map-pin" style="color: #10b981;"></i> Set your pin if browser geolocation is unavailable.
                    </span>
                    <button type="button" class="btn btn-outline open-profile-location-modal" style="padding: 6px 12px; font-size: 0.8rem;">
                        <i class="fas fa-map-marker-alt"></i> Set Pin on Map
                    </button>
                </div>
                <small id="editPinStatusText" style="font-size: 0.72rem; color: var(--gray); display: block; margin-top: 6px;">
                    <?php if (!empty($userLatitude) && !empty($userLongitude)): ?>
                        Pin is currently set.
                    <?php else: ?>
                        No pin selected yet. We can still geocode your address automatically.
                    <?php endif; ?>
                </small>
            </div>

            <input type="hidden" id="editLatitude" name="latitude" value="<?php echo htmlspecialchars((string) ($userLatitude ?? '')); ?>">
            <input type="hidden" id="editLongitude" name="longitude" value="<?php echo htmlspecialchars((string) ($userLongitude ?? '')); ?>">
            <input type="hidden" id="editLocationName" name="location_name" value="<?php echo htmlspecialchars((string) ($userLocationName ?? '')); ?>">
            
            <div class="profile-form-actions">
                <button type="submit" class="btn btn-primary" style="padding: 8px 15px; font-size: 0.9rem;">
                    <i class="fas fa-save"></i> Save
                </button>
                <button type="button" class="btn btn-outline" onclick="switchProfileTab('view')" style="padding: 8px 15px; font-size: 0.9rem;">
                    Cancel
                </button>
            </div>
        </form>
    </div>
    
    <!-- PASSWORD TAB -->
    <div id="profilePasswordContent" style="display: none;">
        <h3 style="margin-bottom: 15px; color: var(--primary); font-size: 1.2rem;">Change Password</h3>
        
        <form id="changePasswordForm"
              data-username="<?php echo htmlspecialchars((string) ($_SESSION['user_username'] ?? '')); ?>"
              data-email="<?php echo htmlspecialchars((string) ($_SESSION['user_email'] ?? '')); ?>"
              data-name="<?php echo htmlspecialchars(trim((string) (($_SESSION['user_display_name'] ?? '') ?: (($_SESSION['user_firstname'] ?? '') . ' ' . ($_SESSION['user_lastname'] ?? ''))))); ?>">
            <!-- Add hidden action field -->
            <input type="hidden" name="action" value="change_password">
            
            <div style="margin-bottom: 12px;">
                <label for="currentPassword" style="font-size: 0.8rem;">Current Password</label>
                <input type="password" id="currentPassword" name="current_password" required style="width: 100%; padding: 8px; font-size: 0.9rem;">
            </div>
            
            <div style="margin-bottom: 12px;">
                <label for="newPassword" style="font-size: 0.8rem;">New Password</label>
                <input type="password" id="newPassword" name="new_password" minlength="<?php echo passwordPolicyMinLength(); ?>" required style="width: 100%; padding: 8px; font-size: 0.9rem;">
            </div>
            
            <div style="margin-bottom: 15px;">
                <label for="confirmPassword" style="font-size: 0.8rem;">Confirm New Password</label>
                <input type="password" id="confirmPassword" name="confirm_password" minlength="<?php echo passwordPolicyMinLength(); ?>" required style="width: 100%; padding: 8px; font-size: 0.9rem;">
                <small style="font-size: 0.7rem;"><?php echo htmlspecialchars(passwordPolicyHint()); ?></small>
            </div>
            
            <div class="profile-form-actions">
                <button type="submit" class="btn btn-primary" style="padding: 8px 15px; font-size: 0.9rem;">
                    <i class="fas fa-key"></i> Update
                </button>
                <button type="button" class="btn btn-outline" onclick="switchProfileTab('view')" style="padding: 8px 15px; font-size: 0.9rem;">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

