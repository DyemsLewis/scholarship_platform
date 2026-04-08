<?php
// Model/Scholarship.php
require_once 'Model.php';

class Scholarship extends Model {
    protected $table = 'scholarships';
    protected $primaryKey = 'id';
    private $columnCache = [];
    private $tableCache = [];

    private function hasColumn(string $tableName, string $columnName): bool {
        $cacheKey = $tableName . '.' . $columnName;
        if (array_key_exists($cacheKey, $this->columnCache)) {
            return $this->columnCache[$cacheKey];
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            ':table_name' => $tableName,
            ':column_name' => $columnName
        ]);

        $this->columnCache[$cacheKey] = ((int) $stmt->fetchColumn()) > 0;
        return $this->columnCache[$cacheKey];
    }

    private function hasTable(string $tableName): bool {
        if (array_key_exists($tableName, $this->tableCache)) {
            return $this->tableCache[$tableName];
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute([':table_name' => $tableName]);

        $this->tableCache[$tableName] = ((int) $stmt->fetchColumn()) > 0;
        return $this->tableCache[$tableName];
    }

    private function getRemoteExamLocations(int $scholarshipId): array {
        if (!$this->hasTable('scholarship_remote_exam_locations')) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT id, site_name, address, city, province, latitude, longitude
            FROM scholarship_remote_exam_locations
            WHERE scholarship_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$scholarshipId]);
        return $stmt->fetchAll() ?: [];
    }

    private function scholarshipDataSelectColumns(): array {
        $columns = [
            'provider',
            'benefits',
            'address',
            'city',
            'province',
            'deadline',
            'image',
            'assessment_requirement',
            'assessment_link',
            'assessment_details',
            'target_applicant_type',
            'target_year_level',
            'required_admission_status',
            'target_strand',
            'target_citizenship',
            'target_income_bracket',
            'target_special_category'
        ];

        $select = [];
        foreach ($columns as $column) {
            if ($this->hasColumn('scholarship_data', $column)) {
                $select[] = "sd.{$column}";
            } else {
                $select[] = "NULL AS {$column}";
            }
        }

        return $select;
    }

    private function canResolveProviderWebsite(): bool
    {
        return $this->hasTable('provider_data')
            && $this->hasColumn('provider_data', 'organization_name')
            && $this->hasColumn('provider_data', 'website');
    }

    private function providerWebsiteSelectExpression(): string
    {
        return $this->canResolveProviderWebsite()
            ? 'pd.website AS provider_website'
            : 'NULL AS provider_website';
    }

    private function providerWebsiteJoinClause(): string
    {
        if (!$this->canResolveProviderWebsite()) {
            return '';
        }

        return "
            LEFT JOIN provider_data pd
                ON LOWER(TRIM(COALESCE(pd.organization_name, ''))) = LOWER(TRIM(COALESCE(sd.provider, '')))
        ";
    }
    
    /**
     * Get all active scholarships with complete data
     */
    public function getActiveScholarships() {
        $selectSd = implode(",\n                ", $this->scholarshipDataSelectColumns());
        $reviewVisibilityClause = $this->hasColumn('scholarship_data', 'review_status')
            ? " AND COALESCE(sd.review_status, 'approved') = 'approved'"
            : '';
        $stmt = $this->pdo->prepare("
            SELECT 
                s.*,
                {$selectSd},
                {$this->providerWebsiteSelectExpression()},
                sl.latitude,
                sl.longitude
            FROM scholarships s
            LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
            {$this->providerWebsiteJoinClause()}
            LEFT JOIN scholarship_location sl ON s.id = sl.scholarship_id
            WHERE s.status = 'active'{$reviewVisibilityClause}
            ORDER BY s.created_at DESC
        ");
        $stmt->execute();
        $scholarships = $stmt->fetchAll();
        
        // Enrich each scholarship with calculated fields
        foreach ($scholarships as &$scholarship) {
            $scholarship = $this->enrichScholarshipData($scholarship);
        }
        
        return $scholarships;
    }
    
    /**
     * Get scholarship by ID with all related data
     */
    public function getScholarshipById($id) {
        $selectSd = implode(",\n                ", $this->scholarshipDataSelectColumns());
        $reviewVisibilityClause = $this->hasColumn('scholarship_data', 'review_status')
            ? " AND COALESCE(sd.review_status, 'approved') = 'approved'"
            : '';
        $stmt = $this->pdo->prepare("
            SELECT 
                s.*,
                {$selectSd},
                {$this->providerWebsiteSelectExpression()},
                sl.latitude,
                sl.longitude
            FROM scholarships s
            LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
            {$this->providerWebsiteJoinClause()}
            LEFT JOIN scholarship_location sl ON s.id = sl.scholarship_id
            WHERE s.id = :id AND s.status = 'active'{$reviewVisibilityClause}
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $scholarship = $stmt->fetch();
        
        if ($scholarship) {
            $scholarship = $this->enrichScholarshipData($scholarship);
        }
        
        return $scholarship;
    }
    
    /**
     * Enrich scholarship data with calculated fields
     */
    private function enrichScholarshipData($scholarship) {
        // Add deadline status
        if (!empty($scholarship['deadline'])) {
            $deadline = new DateTime($scholarship['deadline']);
            $today = new DateTime();
            
            $interval = $today->diff($deadline);
            $daysRemaining = $interval->days;
            
            // If deadline is in the past, make days remaining negative
            if ($deadline < $today) {
                $daysRemaining = -$daysRemaining;
            }
            
            $scholarship['days_remaining'] = $daysRemaining;
            $scholarship['is_expired'] = $deadline < $today;
            $scholarship['is_urgent'] = !$scholarship['is_expired'] && $daysRemaining <= 7;
        } else {
            $scholarship['days_remaining'] = null;
            $scholarship['is_expired'] = false;
            $scholarship['is_urgent'] = false;
        }
        
        // Format location
        if (!empty($scholarship['address'])) {
            $scholarship['location_name'] = $scholarship['address'];
        } elseif (!empty($scholarship['city']) && !empty($scholarship['province'])) {
            $scholarship['location_name'] = $scholarship['city'] . ', ' . $scholarship['province'];
        } elseif (!empty($scholarship['city'])) {
            $scholarship['location_name'] = $scholarship['city'];
        } else {
            $scholarship['location_name'] = 'Location not specified';
        }
        
        // Format benefits as array
        if (!empty($scholarship['benefits'])) {
            $scholarship['benefits_array'] = array_map('trim', explode(',', $scholarship['benefits']));
        } else {
            $scholarship['benefits_array'] = [];
        }

        $scholarship['remote_exam_locations'] = [];
        if (!empty($scholarship['id'])) {
            $scholarship['remote_exam_locations'] = $this->getRemoteExamLocations((int) $scholarship['id']);
        }
        
        return $scholarship;
    }
    
    /**
     * Get random scholarships for guest view
     */
    public function getRandomScholarships($limit = 2) {
        $selectSd = implode(",\n                ", $this->scholarshipDataSelectColumns());
        $reviewVisibilityClause = $this->hasColumn('scholarship_data', 'review_status')
            ? " AND COALESCE(sd.review_status, 'approved') = 'approved'"
            : '';
        $stmt = $this->pdo->prepare("
            SELECT 
                s.*,
                {$selectSd},
                {$this->providerWebsiteSelectExpression()}
            FROM scholarships s
            LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
            {$this->providerWebsiteJoinClause()}
            WHERE s.status = 'active' {$reviewVisibilityClause}
            ORDER BY RAND() 
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $scholarships = $stmt->fetchAll();
        
        foreach ($scholarships as &$scholarship) {
            $scholarship = $this->enrichScholarshipData($scholarship);
        }
        
        return $scholarships;
    }
    
    /**
     * Get scholarships by GWA requirement
     */
    public function getByGWA($gwa) {
        $selectSd = implode(",\n                ", $this->scholarshipDataSelectColumns());
        $stmt = $this->pdo->prepare("
            SELECT 
                s.*,
                {$selectSd},
                {$this->providerWebsiteSelectExpression()}
            FROM scholarships s
            LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
            {$this->providerWebsiteJoinClause()}
            WHERE s.status = 'active' 
            AND COALESCE(s.min_gwa, s.max_gwa) >= :gwa
            ORDER BY COALESCE(s.min_gwa, s.max_gwa) ASC
        ");
        $stmt->bindValue(':gwa', $gwa);
        $stmt->execute();
        
        $scholarships = $stmt->fetchAll();
        
        foreach ($scholarships as &$scholarship) {
            $scholarship = $this->enrichScholarshipData($scholarship);
        }
        
        return $scholarships;
    }
    
    /**
     * Get scholarships near a location
     */
    public function getNearbyScholarships($latitude, $longitude, $radius = 50) {
        $selectSd = implode(",\n                ", $this->scholarshipDataSelectColumns());
        $sql = "
            SELECT 
                s.*,
                {$selectSd},
                {$this->providerWebsiteSelectExpression()},
                sl.latitude,
                sl.longitude,
                (6371 * acos(cos(radians(:lat)) * cos(radians(sl.latitude)) * 
                cos(radians(sl.longitude) - radians(:lng)) + sin(radians(:lat)) * 
                sin(radians(sl.latitude)))) AS distance 
            FROM scholarships s
            LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
            {$this->providerWebsiteJoinClause()}
            LEFT JOIN scholarship_location sl ON s.id = sl.scholarship_id
            WHERE s.status = 'active' 
            AND sl.latitude IS NOT NULL 
            AND sl.longitude IS NOT NULL
            HAVING distance < :radius 
            ORDER BY distance
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lat', $latitude);
        $stmt->bindValue(':lng', $longitude);
        $stmt->bindValue(':radius', $radius);
        $stmt->execute();
        
        $scholarships = $stmt->fetchAll();
        
        foreach ($scholarships as &$scholarship) {
            $scholarship = $this->enrichScholarshipData($scholarship);
        }
        
        return $scholarships;
    }
    
    /**
     * Calculate distance between two coordinates
     */
    public function calculateDistance($lat1, $lon1, $lat2, $lon2) {
        if (!$lat1 || !$lon1 || !$lat2 || !$lon2) {
            return null;
        }
        
        $earthRadius = 6371;
        
        $latDiff = deg2rad($lat2 - $lat1);
        $lonDiff = deg2rad($lon2 - $lon1);
        
        $a = sin($latDiff/2) * sin($latDiff/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lonDiff/2) * sin($lonDiff/2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $distance = $earthRadius * $c;
        
        return round($distance, 2);
    }
    
    /**
     * Format distance for display
     */
    public function formatDistance($distance) {
        if ($distance === null) {
            return 'Distance unknown';
        }
        
        if ($distance < 1) {
            $meters = round($distance * 1000);
            return "{$meters} meters away";
        } elseif ($distance < 10) {
            return number_format($distance, 1) . ' km away';
        } else {
            return round($distance) . ' km away';
        }
    }
}
?>
