<?php
// Model/ScholarshipData.php
require_once 'Model.php';

class ScholarshipData extends Model {
    protected $table = 'scholarship_data';
    protected $primaryKey = 'id';
    private array $columnCache = [];

    private function hasColumn(string $columnName): bool
    {
        if (array_key_exists($columnName, $this->columnCache)) {
            return $this->columnCache[$columnName];
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            ':table_name' => $this->table,
            ':column_name' => $columnName,
        ]);

        $this->columnCache[$columnName] = ((int) $stmt->fetchColumn()) > 0;
        return $this->columnCache[$columnName];
    }
    
    /**
     * Get data by scholarship ID
     */
    public function getByScholarshipId($scholarshipId) {
        return $this->findOneBy('scholarship_id', $scholarshipId);
    }
    
    /**
     * Save or update scholarship data
     */
    public function saveData($scholarshipId, $data) {
        $existing = $this->getByScholarshipId($scholarshipId);
        
        $scholarshipData = [
            'scholarship_id' => $scholarshipId,
            'provider' => $data['provider'] ?? null,
            'benefits' => $data['benefits'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'province' => $data['province'] ?? null,
            'application_open_date' => $data['application_open_date'] ?? null,
            'deadline' => $data['deadline'] ?? null,
            'image' => $data['image'] ?? null,
            'application_process_label' => $data['application_process_label'] ?? null,
            'post_application_steps' => $data['post_application_steps'] ?? null,
            'renewal_conditions' => $data['renewal_conditions'] ?? null,
            'scholarship_restrictions' => $data['scholarship_restrictions'] ?? null,
            'target_applicant_type' => $data['target_applicant_type'] ?? null,
            'target_year_level' => $data['target_year_level'] ?? null,
            'required_admission_status' => $data['required_admission_status'] ?? null,
            'preferred_course' => $data['preferred_course'] ?? null,
            'target_strand' => $data['target_strand'] ?? null
        ];

        if ($this->hasColumn('allow_if_already_accepted')) {
            $scholarshipData['allow_if_already_accepted'] = $data['allow_if_already_accepted'] ?? 1;
        }

        foreach (['review_status', 'review_notes', 'reviewed_by_user_id', 'reviewed_at'] as $optionalField) {
            if (array_key_exists($optionalField, $data)) {
                $scholarshipData[$optionalField] = $data[$optionalField];
            }
        }
        
        if ($existing) {
            return $this->update($existing['id'], $scholarshipData);
        } else {
            return $this->create($scholarshipData);
        }
    }
    
    /**
     * Get scholarships with upcoming deadlines
     */
    public function getUpcomingDeadlines($days = 30) {
        $stmt = $this->pdo->prepare("
            SELECT sd.*, s.name as scholarship_name
            FROM scholarship_data sd
            JOIN scholarships s ON sd.scholarship_id = s.id
            WHERE sd.deadline IS NOT NULL
            AND sd.deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
            AND s.status = 'active'
            ORDER BY sd.deadline ASC
        ");
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
}
?>
