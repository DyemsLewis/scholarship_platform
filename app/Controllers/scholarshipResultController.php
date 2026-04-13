<?php
// Controller/scholarshipResultController.php - Updated
require_once 'Database.php';
require_once __DIR__ . '/../Models/Scholarship.php';
require_once __DIR__ . '/../Config/helpers.php';

class ScholarshipService {
    private $scholarshipModel;
    
    public function __construct($pdo = null) {
        if (!$pdo) {
            $pdo = Database::getInstance()->getConnection();
        }
        $this->scholarshipModel = new Scholarship($pdo);
    }
    
    /**
     * Get all scholarships for guest view
     */
    public function getSampleScholarships($limit = 2) {
        return $this->scholarshipModel->getRandomScholarships($limit);
    }
    
    /**
     * Calculate distance between two coordinates
     */
    public function calculateDistance($lat1, $lon1, $lat2, $lon2) {
        return $this->scholarshipModel->calculateDistance($lat1, $lon1, $lat2, $lon2);
    }
    
    /**
     * Get scholarships matched to user based on GWA and location.
     * Scholarships are still shown without GWA, but eligibility stays unconfirmed
     * until the user uploads grades.
     */
    public function getMatchedScholarships($userGWA, $userCourse, $userLat = null, $userLng = null, array $userProfile = []) {
        // Get all active scholarships
        $scholarships = $this->scholarshipModel->getActiveScholarships();
        
        // Calculate match score for each scholarship
        $matchedScholarships = [];
        foreach ($scholarships as $scholarship) {
            // Expired scholarships stay visible on the board, but are rendered as closed.
            
            // Calculate match score - now handles null GWA
            $matchScore = $this->calculateMatchScore($scholarship, $userGWA, $userCourse, $userProfile);
            
            // Calculate distance if user location is available
            $distance = null;
            if ($userLat && $userLng && 
                isset($scholarship['latitude']) && $scholarship['latitude']) {
                
                $distance = $this->calculateDistance(
                    $userLat, 
                    $userLng, 
                    $scholarship['latitude'], 
                    $scholarship['longitude']
                );
            }
            
            // Add match score and distance to scholarship data
            $requiredGwa = null;
            if (isset($scholarship['min_gwa']) && $scholarship['min_gwa'] !== null && $scholarship['min_gwa'] !== '') {
                $requiredGwa = (float) $scholarship['min_gwa'];
            }

            $scholarship['match_score'] = $matchScore['score'];
            $scholarship['match_percentage'] = $matchScore['percentage'];
            $scholarship['is_eligible'] = $matchScore['eligible'];
            $scholarship['requires_gwa'] = $matchScore['requires_gwa'] ?? false;
            $scholarship['required_gwa'] = $requiredGwa;
            $scholarship['academic_score'] = $matchScore['academic_score'] ?? null;
            $scholarship['academic_metric_label'] = $matchScore['academic_metric_label'] ?? 'GWA';
            $scholarship['academic_document_label'] = $matchScore['academic_document_label'] ?? 'TOR/grades';
            $scholarship['profile_checks'] = $matchScore['profile_checks'] ?? [];
            $scholarship['profile_requirement_total'] = $matchScore['profile_requirement_total'] ?? 0;
            $scholarship['profile_requirement_met'] = $matchScore['profile_requirement_met'] ?? 0;
            $scholarship['profile_requirement_pending'] = $matchScore['profile_requirement_pending'] ?? 0;
            $scholarship['profile_requirement_failed'] = $matchScore['profile_requirement_failed'] ?? 0;
            $scholarship['profile_readiness_label'] = $matchScore['profile_readiness_label'] ?? 'Open profile policy';
            $scholarship['current_info_checks'] = $matchScore['current_info_checks'] ?? [];
            $scholarship['current_info_total'] = $matchScore['current_info_total'] ?? 0;
            $scholarship['current_info_met'] = $matchScore['current_info_met'] ?? 0;
            $scholarship['current_info_pending'] = $matchScore['current_info_pending'] ?? 0;
            $scholarship['current_info_warn'] = $matchScore['current_info_warn'] ?? 0;
            $scholarship['current_info_label'] = $matchScore['current_info_label'] ?? 'Current student context unavailable';
            $scholarship['distance'] = $distance;
            $scholarship['distance_km'] = $distance ? round($distance, 1) : null;
            $scholarship['formatted_distance'] = $this->scholarshipModel->formatDistance($distance);
            
            $matchedScholarships[] = $scholarship;
        }
        
                // Sort active scholarships first, then expired ones, with the best matches at the top.
        usort($matchedScholarships, function($a, $b) {
            $aExpired = !empty($a['is_expired']);
            $bExpired = !empty($b['is_expired']);

            if ($aExpired !== $bExpired) {
                return $aExpired <=> $bExpired;
            }

            return ($b['match_percentage'] ?? 0) <=> ($a['match_percentage'] ?? 0);
        });
        
        return $matchedScholarships;
    }

    public function getMatchAssessmentForScholarship(array $scholarship, $userGWA, $userCourse, array $userProfile = []): array
    {
        return $this->calculateMatchScore($scholarship, $userGWA, $userCourse, $userProfile);
    }

    private function normalizeProfile(array $userProfile, $userCourse = ''): array {
        $profile = [
            'applicant_type' => strtolower(trim((string) ($userProfile['applicant_type'] ?? ''))),
            'year_level' => strtolower(trim((string) ($userProfile['year_level'] ?? ''))),
            'admission_status' => strtolower(trim((string) ($userProfile['admission_status'] ?? ''))),
            'shs_strand' => strtolower(trim((string) ($userProfile['shs_strand'] ?? ''))),
            'shs_average' => strtolower(trim((string) ($userProfile['shs_average'] ?? ''))),
            'course' => strtolower(trim((string) ($userProfile['course'] ?? $userCourse ?? ''))),
            'target_course' => strtolower(trim((string) ($userProfile['target_course'] ?? ''))),
            'school' => strtolower(trim((string) ($userProfile['school'] ?? ''))),
            'target_college' => strtolower(trim((string) ($userProfile['target_college'] ?? ''))),
            'enrollment_status' => strtolower(trim((string) ($userProfile['enrollment_status'] ?? ''))),
            'academic_standing' => strtolower(trim((string) ($userProfile['academic_standing'] ?? ''))),
            'city' => strtolower(trim((string) ($userProfile['city'] ?? ''))),
            'province' => strtolower(trim((string) ($userProfile['province'] ?? ''))),
            'citizenship' => strtolower(trim((string) ($userProfile['citizenship'] ?? ''))),
            'household_income_bracket' => strtolower(trim((string) ($userProfile['household_income_bracket'] ?? ''))),
            'special_category' => strtolower(trim((string) ($userProfile['special_category'] ?? '')))
        ];

        if ($profile['admission_status'] === '' && in_array($profile['applicant_type'], ['current_college', 'transferee', 'continuing_student'], true)) {
            $profile['admission_status'] = 'enrolled';
        }

        return $profile;
    }

    private function isCurrentCollegeApplicant(string $applicantType): bool {
        return in_array($applicantType, ['current_college', 'transferee', 'continuing_student'], true);
    }

    private function doesCitizenshipMatch(string $userCitizenship, string $targetCitizenship): bool {
        if ($userCitizenship === $targetCitizenship) {
            return true;
        }

        return $targetCitizenship === 'filipino' && $userCitizenship === 'dual_citizen';
    }

    private function doesApplicantTypeMatch(string $userApplicantType, string $targetApplicantType): bool {
        if ($targetApplicantType === 'current_college') {
            return in_array($userApplicantType, ['current_college', 'transferee', 'continuing_student'], true);
        }

        return $userApplicantType === $targetApplicantType;
    }

    private function normalizeLooseText(string $value): string {
        $normalized = strtolower(trim($value));
        $normalized = str_replace(['&', '/', '-', '_'], ' ', $normalized);
        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }

    private function admissionStatusOrder(string $status): int {
        $map = [
            'not_yet_applied' => 0,
            'applied' => 1,
            'admitted' => 2,
            'enrolled' => 3
        ];

        return $map[$status] ?? -1;
    }

    public function evaluateProfileRequirements($scholarship, array $userProfile = []): array {
        $profile = $this->normalizeProfile($userProfile);
        $checks = [];
        $met = 0;
        $pending = 0;
        $failed = 0;

        $targetApplicantType = strtolower(trim((string) ($scholarship['target_applicant_type'] ?? 'all')));
        if ($targetApplicantType !== '' && $targetApplicantType !== 'all') {
            $status = 'pending';
            $detail = 'Set your applicant type';

            if ($profile['applicant_type'] !== '') {
                if ($this->doesApplicantTypeMatch($profile['applicant_type'], $targetApplicantType)) {
                    $status = 'met';
                    $detail = 'Matches ' . formatApplicantTypeLabel($targetApplicantType);
                } else {
                    $status = 'failed';
                    $detail = 'Requires ' . formatApplicantTypeLabel($targetApplicantType);
                }
            }

            $checks[] = [
                'key' => 'applicant_type',
                'label' => 'Applicant Type',
                'target' => formatApplicantTypeLabel($targetApplicantType),
                'status' => $status,
                'detail' => $detail
            ];
        }

        $targetYearLevel = strtolower(trim((string) ($scholarship['target_year_level'] ?? 'any')));
        if ($targetYearLevel !== '' && $targetYearLevel !== 'any') {
            $status = 'pending';
            $detail = 'Set your year level';

            if ($profile['year_level'] !== '') {
                if ($profile['year_level'] === $targetYearLevel) {
                    $status = 'met';
                    $detail = 'Matches ' . formatYearLevelLabel($targetYearLevel);
                } else {
                    $status = 'failed';
                    $detail = 'Requires ' . formatYearLevelLabel($targetYearLevel);
                }
            }

            $checks[] = [
                'key' => 'year_level',
                'label' => 'Year Level',
                'target' => formatYearLevelLabel($targetYearLevel),
                'status' => $status,
                'detail' => $detail
            ];
        }

        $requiredAdmissionStatus = strtolower(trim((string) ($scholarship['required_admission_status'] ?? 'any')));
        if ($requiredAdmissionStatus !== '' && $requiredAdmissionStatus !== 'any') {
            $status = 'pending';
            $detail = 'Set your admission status';

            if ($profile['admission_status'] !== '') {
                if ($this->admissionStatusOrder($profile['admission_status']) >= $this->admissionStatusOrder($requiredAdmissionStatus)) {
                    $status = 'met';
                    $detail = 'Meets ' . formatAdmissionStatusLabel($requiredAdmissionStatus);
                } else {
                    $status = 'failed';
                    $detail = 'Requires at least ' . formatAdmissionStatusLabel($requiredAdmissionStatus);
                }
            }

            $checks[] = [
                'key' => 'admission_status',
                'label' => 'Admission Status',
                'target' => formatAdmissionStatusLabel($requiredAdmissionStatus),
                'status' => $status,
                'detail' => $detail
            ];
        }

        $targetStrand = trim((string) ($scholarship['target_strand'] ?? ''));
        if ($targetStrand !== '') {
            $status = 'pending';
            $detail = 'Set your SHS strand';
            $normalizedTargetStrand = $this->normalizeLooseText($targetStrand);

            if ($profile['shs_strand'] !== '') {
                $normalizedUserStrand = $this->normalizeLooseText($profile['shs_strand']);
                if ($normalizedUserStrand === $normalizedTargetStrand || str_contains($normalizedTargetStrand, $normalizedUserStrand) || str_contains($normalizedUserStrand, $normalizedTargetStrand)) {
                    $status = 'met';
                    $detail = 'Matches ' . strtoupper($targetStrand);
                } else {
                    $status = 'failed';
                    $detail = 'Requires ' . strtoupper($targetStrand);
                }
            }

            $checks[] = [
                'key' => 'target_strand',
                'label' => 'SHS Strand',
                'target' => strtoupper($targetStrand),
                'status' => $status,
                'detail' => $detail
            ];
        }

        $targetCitizenship = strtolower(trim((string) ($scholarship['target_citizenship'] ?? 'all')));
        if ($targetCitizenship !== '' && $targetCitizenship !== 'all') {
            $status = 'pending';
            $detail = 'Set your citizenship';

            if ($profile['citizenship'] !== '') {
                if ($this->doesCitizenshipMatch($profile['citizenship'], $targetCitizenship)) {
                    $status = 'met';
                    $detail = 'Matches ' . formatCitizenshipLabel($targetCitizenship);
                } else {
                    $status = 'failed';
                    $detail = 'Requires ' . formatCitizenshipLabel($targetCitizenship);
                }
            }

            $checks[] = [
                'key' => 'target_citizenship',
                'label' => 'Citizenship',
                'target' => formatCitizenshipLabel($targetCitizenship),
                'status' => $status,
                'detail' => $detail
            ];
        }

        $targetIncomeBracket = strtolower(trim((string) ($scholarship['target_income_bracket'] ?? 'any')));
        if ($targetIncomeBracket !== '' && $targetIncomeBracket !== 'any') {
            $status = 'pending';
            $detail = 'Set your household income bracket';

            if ($profile['household_income_bracket'] !== '') {
                if ($profile['household_income_bracket'] === 'prefer_not_to_say') {
                    $status = 'pending';
                    $detail = 'Income bracket is not disclosed yet';
                } elseif ($profile['household_income_bracket'] === $targetIncomeBracket) {
                    $status = 'met';
                    $detail = 'Matches ' . formatHouseholdIncomeBracketLabel($targetIncomeBracket);
                } else {
                    $status = 'failed';
                    $detail = 'Priority is ' . formatHouseholdIncomeBracketLabel($targetIncomeBracket);
                }
            }

            $checks[] = [
                'key' => 'target_income_bracket',
                'label' => 'Income Bracket',
                'target' => formatHouseholdIncomeBracketLabel($targetIncomeBracket),
                'status' => $status,
                'detail' => $detail
            ];
        }

        $targetSpecialCategory = strtolower(trim((string) ($scholarship['target_special_category'] ?? 'any')));
        if ($targetSpecialCategory !== '' && $targetSpecialCategory !== 'any') {
            $status = 'pending';
            $detail = 'Set your special scholarship category';

            if ($profile['special_category'] !== '') {
                if ($profile['special_category'] === $targetSpecialCategory) {
                    $status = 'met';
                    $detail = 'Matches ' . formatSpecialCategoryLabel($targetSpecialCategory);
                } else {
                    $status = 'failed';
                    $detail = 'Requires ' . formatSpecialCategoryLabel($targetSpecialCategory);
                }
            }

            $checks[] = [
                'key' => 'target_special_category',
                'label' => 'Special Category',
                'target' => formatSpecialCategoryLabel($targetSpecialCategory),
                'status' => $status,
                'detail' => $detail
            ];
        }

        foreach ($checks as $check) {
            if ($check['status'] === 'met') {
                $met++;
            } elseif ($check['status'] === 'failed') {
                $failed++;
            } elseif ($check['status'] === 'pending') {
                $pending++;
            }
        }

        $total = count($checks);
        $eligible = $total === 0 ? true : ($failed === 0 && $pending === 0);
        $readinessLabel = 'Open profile policy';
        if ($total > 0) {
            if ($eligible) {
                $readinessLabel = 'Profile matches target audience';
            } elseif ($failed > 0) {
                $readinessLabel = 'Profile does not match all audience rules';
            } else {
                $readinessLabel = 'Complete your profile to confirm audience fit';
            }
        }

        return [
            'checks' => $checks,
            'total' => $total,
            'met' => $met,
            'pending' => $pending,
            'failed' => $failed,
            'eligible' => $eligible,
            'label' => $readinessLabel
        ];
    }

    private function evaluateCurrentInformationSignals($scholarship, array $userProfile = []): array {
        $profile = $this->normalizeProfile($userProfile);
        $checks = [];
        $met = 0;
        $pending = 0;
        $warn = 0;

        $targetApplicantType = strtolower(trim((string) ($scholarship['target_applicant_type'] ?? 'all')));
        $needsCurrentCollegeSignals = $this->isCurrentCollegeApplicant($profile['applicant_type']) || $targetApplicantType === 'current_college';

        if ($needsCurrentCollegeSignals) {
            $status = 'pending';
            $detail = 'Set your enrollment status';
            $enrollmentStatus = $profile['enrollment_status'];
            if ($enrollmentStatus !== '') {
                if (in_array($enrollmentStatus, ['currently_enrolled', 'regular', 'irregular'], true)) {
                    $status = 'met';
                    $detail = 'Status: ' . formatEnrollmentStatusLabel($enrollmentStatus);
                } elseif ($enrollmentStatus === 'leave_of_absence') {
                    $status = 'warn';
                    $detail = 'Leave of absence may require manual scholarship review';
                } else {
                    $status = 'warn';
                    $detail = 'Enrollment status needs review';
                }
            }

            $checks[] = [
                'key' => 'enrollment_status',
                'label' => 'Enrollment Status',
                'target' => 'Active Enrollment',
                'status' => $status,
                'detail' => $detail
            ];

            $status = 'pending';
            $detail = 'Set your academic standing';
            $academicStanding = $profile['academic_standing'];
            if ($academicStanding !== '') {
                if ($academicStanding === 'probationary') {
                    $status = 'warn';
                    $detail = 'Probationary standing may need manual scholarship review';
                } else {
                    $status = 'met';
                    $detail = 'Standing: ' . formatAcademicStandingLabel($academicStanding);
                }
            }

            $checks[] = [
                'key' => 'academic_standing',
                'label' => 'Academic Standing',
                'target' => 'Good Standing',
                'status' => $status,
                'detail' => $detail
            ];
        }

        $scholarshipText = $this->normalizeLooseText(trim(implode(' ', [
            (string) ($scholarship['name'] ?? ''),
            (string) ($scholarship['description'] ?? ''),
            (string) ($scholarship['eligibility'] ?? ''),
            (string) ($scholarship['provider'] ?? ''),
            (string) ($scholarship['address'] ?? ''),
            (string) ($scholarship['city'] ?? ''),
            (string) ($scholarship['province'] ?? '')
        ])));

        $referenceCourse = $profile['target_course'] !== '' ? $profile['target_course'] : $profile['course'];
        $status = 'pending';
        $detail = 'Set your current or target course';
        if ($referenceCourse !== '') {
            $courseMatch = $this->analyzeCourseMatch($scholarship, $referenceCourse);
            $status = $courseMatch['status'];
            $detail = $courseMatch['detail'];
        }

        $checks[] = [
            'key' => 'course_pathway',
            'label' => 'Course Pathway',
            'target' => 'Relevant Program',
            'status' => $status,
            'detail' => $detail
        ];

        foreach ($checks as $check) {
            if ($check['status'] === 'met') {
                $met++;
            } elseif ($check['status'] === 'pending') {
                $pending++;
            } elseif ($check['status'] === 'warn') {
                $warn++;
            }
        }

        $total = count($checks);
        $scoreBonus = min(15, ($met * 3) + ($warn * 1) + ($pending > 0 ? 1 : 0));

        $label = 'Current student context unavailable';
        if ($total > 0) {
            if ($met === $total) {
                $label = 'Current student information strongly supports this match';
            } elseif ($pending > 0) {
                $label = 'Add current student information to improve match precision';
            } else {
                $label = 'Current student information partially supports this match';
            }
        }

        return [
            'checks' => $checks,
            'total' => $total,
            'met' => $met,
            'pending' => $pending,
            'warn' => $warn,
            'label' => $label,
            'score_bonus' => $scoreBonus
        ];
    }

    /**
     * Calculate match score between user and scholarship.
     * When GWA is missing, scholarships are shown as estimated matches only.
     */
    private function calculateMatchScore($scholarship, $userGWA, $userCourse, array $userProfile = []) {
        $score = 0;
        $maxScore = 145;
        $eligible = true;
        $requiresGwa = false;
        $normalizedProfile = $this->normalizeProfile($userProfile, $userCourse);
        $academicScore = resolveApplicantAcademicScore(
            $normalizedProfile['applicant_type'] ?? '',
            $userGWA,
            $normalizedProfile['shs_average'] ?? null
        );
        $academicMetricLabel = getApplicantAcademicMetricLabel($normalizedProfile['applicant_type'] ?? '');
        $academicDocumentLabel = getApplicantAcademicDocumentLabel($normalizedProfile['applicant_type'] ?? '');
        $profileEvaluation = $this->evaluateProfileRequirements($scholarship, $userProfile);
        $currentInfoSignals = $this->evaluateCurrentInformationSignals($scholarship, $userProfile);
        if (!$profileEvaluation['eligible']) {
            $eligible = false;
        }

        $requiredGwa = null;
        if (isset($scholarship['min_gwa']) && $scholarship['min_gwa'] !== null && $scholarship['min_gwa'] !== '') {
            $requiredGwa = (float) $scholarship['min_gwa'];
        }

        // Check GWA requirement (40 points).
        // Only block on GWA when the scholarship actually defines a GWA limit.
        if ($requiredGwa !== null) {
            if ($academicScore !== null) {
                if ($academicScore <= $requiredGwa) {
                    $gwaScore = 40;
                    // Bonus for excellent GWA
                    if ($academicScore <= 1.5) {
                        $gwaScore += 10;
                    } elseif ($academicScore <= 1.75) {
                        $gwaScore += 5;
                    }
                    $score += $gwaScore;
                } else {
                    // User doesn't meet GWA requirement - still show but mark as not eligible
                    $eligible = false;
                    $score += 15; // Partial score for visibility
                }
            } else {
                // Scholarship requires GWA, but the user has not uploaded one yet.
                $score += 20;
                $eligible = false;
                $requiresGwa = true;
            }
        } else {
            // No GWA rule on the scholarship, so missing grades should not block eligibility.
            $score += 20;
        }
        
        // Course compatibility (30 points)
        $referenceCourse = $normalizedProfile['target_course'] !== ''
            ? $normalizedProfile['target_course']
            : ($normalizedProfile['course'] ?? '');
        if ($referenceCourse !== '') {
            $courseMatch = $this->analyzeCourseMatch($scholarship, $referenceCourse);
            $score += (int) ($courseMatch['score'] ?? 10);
        } else {
            $score += 15; // No course data
        }

        if ($profileEvaluation['total'] > 0) {
            $score += (int) round(($profileEvaluation['met'] / $profileEvaluation['total']) * 20);
        } else {
            $score += 20;
        }

        $score += (int) ($currentInfoSignals['score_bonus'] ?? 0);

        // Deadline proximity (15 points)
        if (!empty($scholarship['days_remaining']) && $scholarship['days_remaining'] > 0) {
            $daysRemaining = $scholarship['days_remaining'];
            
            if ($daysRemaining >= 60) {
                $score += 15;
            } elseif ($daysRemaining >= 30) {
                $score += 12;
            } elseif ($daysRemaining >= 14) {
                $score += 9;
            } elseif ($daysRemaining >= 7) {
                $score += 6;
            } else {
                $score += 3;
            }
        } else {
            $score += 8; // No deadline specified
        }
        
        // Provider reputation (15 points)
        $provider = $scholarship['provider'] ?? '';
        $prestigiousProviders = ['CHED', 'DOST', 'University of the Philippines', 'SM Foundation', 'Ayala Foundation'];
        
        $isPrestigious = false;
        foreach ($prestigiousProviders as $prestigious) {
            if (strpos($provider, $prestigious) !== false) {
                $isPrestigious = true;
                break;
            }
        }
        
        if ($isPrestigious) {
            $score += 15;
        } elseif (strpos($provider, 'University') !== false || strpos($provider, 'College') !== false) {
            $score += 10;
        } else {
            $score += 8;
        }
        
        // Calculate percentage
        $percentage = min(100, round(($score / $maxScore) * 100));
        
        return [
            'score' => $score,
            'percentage' => $percentage,
            'eligible' => $eligible,
            'requires_gwa' => $requiresGwa,
            'academic_score' => $academicScore,
            'academic_metric_label' => $academicMetricLabel,
            'academic_document_label' => $academicDocumentLabel,
            'profile_checks' => $profileEvaluation['checks'],
            'profile_requirement_total' => $profileEvaluation['total'],
            'profile_requirement_met' => $profileEvaluation['met'],
            'profile_requirement_pending' => $profileEvaluation['pending'],
            'profile_requirement_failed' => $profileEvaluation['failed'],
            'profile_readiness_label' => $profileEvaluation['label'],
            'current_info_checks' => $currentInfoSignals['checks'] ?? [],
            'current_info_total' => $currentInfoSignals['total'] ?? 0,
            'current_info_met' => $currentInfoSignals['met'] ?? 0,
            'current_info_pending' => $currentInfoSignals['pending'] ?? 0,
            'current_info_warn' => $currentInfoSignals['warn'] ?? 0,
            'current_info_label' => $currentInfoSignals['label'] ?? 'Current student context unavailable'
        ];
    }
    
    /**
     * Calculate course compatibility score
     */
    private function analyzeCourseMatch($scholarship, $userCourse): array {
        $text = strtolower(
            ($scholarship['name'] ?? '') . ' ' . 
            ($scholarship['description'] ?? '') . ' ' . 
            ($scholarship['eligibility'] ?? '')
        );
        $courseLabel = trim((string) $userCourse);
        $course = strtolower($courseLabel);
        
        $stemKeywords = ['stem', 'science', 'engineering', 'technology', 'computer', 'math', 'it', 'information technology', 'bsit', 'bscs'];
        $businessKeywords = ['business', 'management', 'accountancy', 'marketing', 'finance', 'bsba', 'bsa'];
        $artsKeywords = ['arts', 'humanities', 'communication', 'design', 'media', 'mass comm'];
        $healthKeywords = ['nursing', 'medicine', 'health', 'pharmacy', 'medical', 'bsn', 'bsph'];
        $architectureKeywords = ['architecture', 'archi', 'bs arch'];
        
        $isUserStem = $this->textContainsAny($course, $stemKeywords);
        $isUserBusiness = $this->textContainsAny($course, $businessKeywords);
        $isUserArts = $this->textContainsAny($course, $artsKeywords);
        $isUserHealth = $this->textContainsAny($course, $healthKeywords);
        $isUserArchitecture = $this->textContainsAny($course, $architectureKeywords);
        
        $isScholarshipStem = $this->textContainsAny($text, $stemKeywords);
        $isScholarshipBusiness = $this->textContainsAny($text, $businessKeywords);
        $isScholarshipArts = $this->textContainsAny($text, $artsKeywords);
        $isScholarshipHealth = $this->textContainsAny($text, $healthKeywords);
        $isScholarshipArchitecture = $this->textContainsAny($text, $architectureKeywords);

        $courseReference = $courseLabel !== '' ? $courseLabel : 'the recorded course';
        
        if (($isUserStem && $isScholarshipStem) || 
            ($isUserBusiness && $isScholarshipBusiness) || 
            ($isUserArts && $isScholarshipArts) || 
            ($isUserHealth && $isScholarshipHealth) ||
            ($isUserArchitecture && $isScholarshipArchitecture)) {
            return [
                'score' => 30,
                'status' => 'met',
                'detail' => 'Passed course check because ' . $courseReference . ' matches the scholarship focus area.'
            ];
        }

        if (strpos($text, 'all courses') !== false || strpos($text, 'any course') !== false || strpos($text, 'open to all') !== false) {
            return [
                'score' => 25,
                'status' => 'met',
                'detail' => 'Passed course check because this scholarship is open to all courses.'
            ];
        }

        return [
            'score' => 10,
            'status' => 'warn',
            'detail' => 'Course check is weaker because the scholarship details do not clearly list ' . $courseReference . ' as a priority course.'
        ];
    }

    private function calculateCourseScore($scholarship, $userCourse) {
        $courseMatch = $this->analyzeCourseMatch($scholarship, $userCourse);
        return (int) ($courseMatch['score'] ?? 10);
    }
    
    /**
     * Helper function to check if text contains any keywords
     */
    private function textContainsAny($text, $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get scholarships near a location
     */
    public function getScholarshipsNearLocation($lat, $lng, $radius = 50) {
        return $this->scholarshipModel->getNearbyScholarships($lat, $lng, $radius);
    }
    
    /**
     * Get probability badge class based on percentage
     */
    public function getProbabilityBadgeClass($percentage) {
        if ($percentage >= 70) {
            return 'probability-70';
        } elseif ($percentage >= 50) {
            return 'probability-50';
        } elseif ($percentage >= 30) {
            return 'probability-30';
        } else {
            return 'probability-20';
        }
    }
    
    /**
     * Format match text based on percentage
     */
    public function getMatchText($percentage) {
        if ($percentage >= 85) {
            return 'Excellent Match';
        } elseif ($percentage >= 70) {
            return 'High Match';
        } elseif ($percentage >= 50) {
            return 'Good Match';
        } elseif ($percentage >= 30) {
            return 'Fair Match';
        } else {
            return 'Low Match';
        }
    }
    
    /**
     * Get travel time estimate
     */
    public function estimateTravelTime($distance, $mode = 'driving') {
        if (!$distance) {
            return null;
        }
        
        $speeds = [
            'walking' => 5,
            'biking' => 15,
            'driving' => 40,
            'public_transport' => 25
        ];
        
        $speed = $speeds[$mode] ?? $speeds['driving'];
        $hours = $distance / $speed;
        
        if ($hours < 1) {
            $minutes = round($hours * 60);
            return "~{$minutes} minutes";
        } else {
            return "~" . round($hours, 1) . " hours";
        }
    }
    
    /**
     * Format distance for display
     */
    public function formatDistance($distance) {
        return $this->scholarshipModel->formatDistance($distance);
    }
}

if (!function_exists('scholarshipMatchGuideSentence')) {
    function scholarshipMatchGuideSentence(string $text): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($normalized === '') {
            return '';
        }

        return preg_match('/[.!?]$/', $normalized) ? $normalized : ($normalized . '.');
    }
}

if (!function_exists('scholarshipMatchGuideReasonFromCheck')) {
    function scholarshipMatchGuideReasonFromCheck(array $check): string
    {
        $key = strtolower(trim((string) ($check['key'] ?? '')));
        $status = strtolower(trim((string) ($check['status'] ?? 'pending')));
        $target = trim((string) ($check['target'] ?? ''));
        $detail = trim((string) ($check['detail'] ?? ''));
        $fallbackLabel = trim((string) ($check['label'] ?? 'this scholarship'));

        switch ($key) {
            case 'applicant_type':
                if ($status === 'met') {
                    return scholarshipMatchGuideSentence('Passed applicant type because the recorded profile matches ' . $target);
                }
                if ($status === 'failed') {
                    return scholarshipMatchGuideSentence('Applicant type does not pass because this scholarship requires ' . $target);
                }
                return 'Applicant type is still missing, so this scholarship check is not confirmed yet.';

            case 'year_level':
                if ($status === 'met') {
                    return scholarshipMatchGuideSentence('Passed year level because the recorded profile matches ' . $target);
                }
                if ($status === 'failed') {
                    return scholarshipMatchGuideSentence('Year level does not pass because this scholarship requires ' . $target);
                }
                return 'Year level is still missing, so this scholarship check is not confirmed yet.';

            case 'admission_status':
                if ($status === 'met') {
                    return scholarshipMatchGuideSentence('Passed admission status because the recorded profile already meets ' . $target);
                }
                if ($status === 'failed') {
                    return scholarshipMatchGuideSentence('Admission status does not pass because this scholarship requires at least ' . $target);
                }
                return 'Admission status is still missing, so this scholarship check is not confirmed yet.';

            case 'target_strand':
                if ($status === 'met') {
                    return scholarshipMatchGuideSentence('Passed SHS strand because the recorded profile matches ' . $target);
                }
                if ($status === 'failed') {
                    return scholarshipMatchGuideSentence('SHS strand does not pass because this scholarship requires ' . $target);
                }
                return 'SHS strand is still missing, so this scholarship check is not confirmed yet.';

            case 'target_citizenship':
                if ($status === 'met') {
                    return scholarshipMatchGuideSentence('Passed citizenship because the recorded profile matches ' . $target);
                }
                if ($status === 'failed') {
                    return scholarshipMatchGuideSentence('Citizenship does not pass because this scholarship requires ' . $target);
                }
                return 'Citizenship is still missing, so this scholarship check is not confirmed yet.';

            case 'target_income_bracket':
                if ($status === 'met') {
                    return scholarshipMatchGuideSentence('Passed income bracket because the recorded profile matches the scholarship priority for ' . $target);
                }
                if ($status === 'failed') {
                    return scholarshipMatchGuideSentence('Income bracket is a weaker signal because the scholarship priority is ' . $target);
                }
                if ($detail !== '' && stripos($detail, 'not disclosed') !== false) {
                    return scholarshipMatchGuideSentence($detail);
                }
                return 'Household income bracket is still missing, so this scholarship check is not confirmed yet.';

            case 'target_special_category':
                if ($status === 'met') {
                    return scholarshipMatchGuideSentence('Passed special category because the recorded profile matches ' . $target);
                }
                if ($status === 'failed') {
                    return scholarshipMatchGuideSentence('Special category does not pass because this scholarship requires ' . $target);
                }
                return 'Special scholarship category is still missing, so this scholarship check is not confirmed yet.';

            case 'enrollment_status':
                if ($status === 'met') {
                    $statusLabel = preg_replace('/^Status:\s*/i', '', $detail) ?: $target;
                    return scholarshipMatchGuideSentence('Passed enrollment status because the applicant is marked as ' . $statusLabel);
                }
                if ($detail !== '') {
                    return scholarshipMatchGuideSentence($detail);
                }
                return 'Enrollment status is still missing, so the current student context check is not confirmed yet.';

            case 'academic_standing':
                if ($status === 'met') {
                    $standingLabel = preg_replace('/^Standing:\s*/i', '', $detail) ?: $target;
                    return scholarshipMatchGuideSentence('Passed academic standing because the applicant is in ' . $standingLabel);
                }
                if ($detail !== '') {
                    return scholarshipMatchGuideSentence($detail);
                }
                return 'Academic standing is still missing, so the current student context check is not confirmed yet.';

            case 'course_pathway':
            case 'school_context':
                if ($detail !== '') {
                    return scholarshipMatchGuideSentence($detail);
                }
                return scholarshipMatchGuideSentence($fallbackLabel . ' still needs more information');
        }

        if ($detail !== '') {
            return scholarshipMatchGuideSentence($detail);
        }

        if ($status === 'met') {
            return scholarshipMatchGuideSentence('Passed ' . strtolower($fallbackLabel) . ' check');
        }

        if ($status === 'failed') {
            return scholarshipMatchGuideSentence(ucfirst($fallbackLabel) . ' does not pass yet');
        }

        return scholarshipMatchGuideSentence(ucfirst($fallbackLabel) . ' still needs more information');
    }
}

if (!function_exists('scholarshipMatchGuideSummary')) {
    function scholarshipMatchGuideSummary(?int $matchPercentage, bool $requiresAcademicRecord, bool $isGuest = false): string
    {
        if ($isGuest) {
            return 'This is a personalized fit score. Sign in and complete your student details to see exactly which scholarship checks you already pass.';
        }

        if ($requiresAcademicRecord) {
            return 'This score is still estimated because the academic record is missing. The checks below explain which parts of the scholarship already pass using the profile data on file.';
        }

        if ($matchPercentage !== null && $matchPercentage >= 80) {
            return 'This score is high because several key scholarship checks are already passing.';
        }

        if ($matchPercentage !== null && $matchPercentage >= 60) {
            return 'This score passed because some major scholarship checks are already passing, even though a few other signals are still weaker or incomplete.';
        }

        if ($matchPercentage !== null) {
            return 'This score is lower because only some scholarship checks are passing right now, while other signals still need work.';
        }

        return 'The DSS explains this fit score by showing which scholarship checks already pass and which ones still limit the result.';
    }
}
?>


