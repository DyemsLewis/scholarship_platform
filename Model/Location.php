<?php
// Model/Location.php
require_once 'Model.php';

class Location extends Model {
    protected $table = 'student_location';
    protected $primaryKey = 'id';
    private $columnCache = [];

    private function hasColumn(string $column): bool {
        if (array_key_exists($column, $this->columnCache)) {
            return $this->columnCache[$column];
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'student_location'
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([':column_name' => $column]);
        $this->columnCache[$column] = ((int) $stmt->fetchColumn()) > 0;

        return $this->columnCache[$column];
    }

    public function getByStudentId($studentId) {
        return $this->findOneBy('student_id', $studentId);
    }
    public function saveLocation($studentId, $latitude, $longitude, $location_name = null) {
        $existing = $this->getByStudentId($studentId);
        
        $locationData = [
            'student_id' => $studentId,
            'latitude' => $latitude,
            'longitude' => $longitude
        ];
        
        if ($location_name && $this->hasColumn('location_name')) {
            $locationData['location_name'] = $location_name;
        }

        if ($existing) {
            return $this->update($existing['id'], $locationData);
        } else {
            return $this->create($locationData);
        }
    }
    public function getStudentsNearby($latitude, $longitude, $radius = 50) {
        $sql = "
            SELECT 
                sl.*,
                sd.firstname,
                sd.lastname,
                sd.school,
                sd.course,
                (6371 * acos(cos(radians(:lat)) * cos(radians(sl.latitude)) * 
                cos(radians(sl.longitude) - radians(:lng)) + sin(radians(:lat)) * 
                sin(radians(sl.latitude)))) AS distance 
            FROM student_location sl
            JOIN student_data sd ON sl.student_id = sd.student_id
            HAVING distance < :radius 
            ORDER BY distance
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lat', $latitude);
        $stmt->bindValue(':lng', $longitude);
        $stmt->bindValue(':radius', $radius);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
}
?>
