<?php
// Model/StudentData.php
class StudentData {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    private function hasColumn(string $column): bool {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'student_data'
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([':column_name' => $column]);
        $cache[$column] = ((int) $stmt->fetchColumn()) > 0;

        return $cache[$column];
    }

    private function normalizeString($value): ?string {
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function buildPayload(array $data, array $existing = []): array {
        $payload = [];

        $resolve = function (string $key, $fallback = null) use ($data, $existing) {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
            if (array_key_exists($key, $existing)) {
                return $existing[$key];
            }
            return $fallback;
        };

        $columnMap = [
            'firstname' => fn() => $this->normalizeString($resolve('firstname', '')),
            'lastname' => fn() => $this->normalizeString($resolve('lastname', '')),
            'middleinitial' => fn() => $this->normalizeString($resolve('middleinitial', '')),
            'suffix' => fn() => $this->normalizeString($resolve('suffix', null)),
            'age' => fn() => (int) ($resolve('age', 0) ?? 0),
            'birthdate' => fn() => $this->normalizeString($resolve('birthdate', null)),
            'gender' => fn() => $this->normalizeString($resolve('gender', null)),
            'applicant_type' => fn() => $this->normalizeString($resolve('applicant_type', null)),
            'course' => fn() => $this->normalizeString($resolve('course', '')),
            'school' => fn() => $this->normalizeString($resolve('school', '')),
            'shs_school' => fn() => $this->normalizeString($resolve('shs_school', null)),
            'shs_strand' => fn() => $this->normalizeString($resolve('shs_strand', null)),
            'shs_graduation_year' => fn() => $this->normalizeString($resolve('shs_graduation_year', null)),
            'shs_average' => fn() => $this->normalizeString($resolve('shs_average', null)),
            'admission_status' => fn() => $this->normalizeString($resolve('admission_status', null)),
            'target_college' => fn() => $this->normalizeString($resolve('target_college', null)),
            'target_course' => fn() => $this->normalizeString($resolve('target_course', null)),
            'year_level' => fn() => $this->normalizeString($resolve('year_level', null)),
            'enrollment_status' => fn() => $this->normalizeString($resolve('enrollment_status', null)),
            'academic_standing' => fn() => $this->normalizeString($resolve('academic_standing', null)),
            'address' => fn() => $this->normalizeString($resolve('address', null)),
            'house_no' => fn() => $this->normalizeString($resolve('house_no', null)),
            'street' => fn() => $this->normalizeString($resolve('street', null)),
            'barangay' => fn() => $this->normalizeString($resolve('barangay', null)),
            'city' => fn() => $this->normalizeString($resolve('city', null)),
            'province' => fn() => $this->normalizeString($resolve('province', null)),
            'mobile_number' => fn() => $this->normalizeString($resolve('mobile_number', null)),
            'citizenship' => fn() => $this->normalizeString($resolve('citizenship', null)),
            'household_income_bracket' => fn() => $this->normalizeString($resolve('household_income_bracket', null)),
            'special_category' => fn() => $this->normalizeString($resolve('special_category', null)),
            'parent_background' => fn() => $this->normalizeString($resolve('parent_background', null)),
            'profile_image_path' => fn() => $this->normalizeString($resolve('profile_image_path', null))
        ];

        foreach ($columnMap as $column => $resolver) {
            if ($this->hasColumn($column)) {
                $payload[$column] = $resolver();
            }
        }

        return $payload;
    }

    public function saveStudentData($user_id, $data) {
        try {
            $existing = $this->getStudentData($user_id);
            $payload = $this->buildPayload($data, $existing ?: []);

            if ($existing) {
                if (empty($payload)) {
                    return true;
                }

                $setClause = implode(', ', array_map(function ($column) {
                    return "{$column} = :{$column}";
                }, array_keys($payload)));

                $sql = "UPDATE student_data SET {$setClause} WHERE student_id = :student_id";
                $stmt = $this->pdo->prepare($sql);
                foreach ($payload as $column => $value) {
                    $stmt->bindValue(":{$column}", $value);
                }
                $stmt->bindValue(':student_id', $user_id, PDO::PARAM_INT);

                return $stmt->execute();
            }

            $insertPayload = array_merge(['student_id' => $user_id], $payload);
            $columns = implode(', ', array_keys($insertPayload));
            $placeholders = ':' . implode(', :', array_keys($insertPayload));
            $sql = "INSERT INTO student_data ({$columns}) VALUES ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);

            foreach ($insertPayload as $column => $value) {
                $stmt->bindValue(":{$column}", $value);
            }

            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Database error in saveStudentData: " . $e->getMessage());
            return false;
        }
    }

    public function getStudentData($user_id) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM student_data WHERE student_id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting student data: " . $e->getMessage());
            return null;
        }
    }

    public function getByUserId($user_id) {
        return $this->getStudentData($user_id);
    }
}
?>
