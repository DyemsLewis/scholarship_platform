<?php
// Model/ScholarshipLocation.php
require_once 'Model.php';

class ScholarshipLocation extends Model {
    protected $table = 'scholarship_location';
    protected $primaryKey = 'id';
    
    /**
     * Get location by scholarship ID
     */
    public function getByScholarshipId($scholarshipId) {
        return $this->findOneBy('scholarship_id', $scholarshipId);
    }
    
    /**
     * Save scholarship location
     */
    public function saveLocation($scholarshipId, $latitude, $longitude) {
        $existing = $this->getByScholarshipId($scholarshipId);
        
        $locationData = [
            'scholarship_id' => $scholarshipId,
            'latitude' => $latitude,
            'longitude' => $longitude
        ];
        
        if ($existing) {
            return $this->update($existing['id'], $locationData);
        } else {
            return $this->create($locationData);
        }
    }
}
?>