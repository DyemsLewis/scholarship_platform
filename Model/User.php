<?php
// Model/User.php
require_once 'Model.php';
require_once __DIR__ . '/../Config/password_policy.php';

class User extends Model {
    protected $table = 'users';
    protected $primaryKey = 'id';

    protected function tableHasColumn(string $column): bool {
        static $cache = [];
        $key = $this->table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
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
            ':column_name' => $column
        ]);

        $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
        return $cache[$key];
    }

    protected function statusValueSupported(string $status): bool {
        static $cache = [];
        $key = $this->table . '.status_value.' . $status;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        if (!$this->tableHasColumn('status')) {
            $cache[$key] = false;
            return false;
        }

        $stmt = $this->pdo->query("SHOW COLUMNS FROM {$this->table} LIKE 'status'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $type = strtolower((string) ($column['Type'] ?? ''));

        if ($type === '') {
            $cache[$key] = false;
            return false;
        }

        if (strpos($type, 'enum(') !== 0) {
            $cache[$key] = true;
            return true;
        }

        $cache[$key] = strpos($type, "'" . strtolower($status) . "'") !== false;
        return $cache[$key];
    }
    
    public function findByEmail($email) {
        return $this->findOneBy('email', $email);
    }
    public function authenticate($email, $password) {
        $sql = "
            SELECT 
                u.*,
                sd.firstname,
                sd.lastname,
                sd.course,
                sd.gwa,
                sd.school,
                sl.latitude,
                sl.longitude,
                CONCAT(sd.firstname, ' ', sd.lastname) as full_name
            FROM users u
            LEFT JOIN student_data sd ON u.id = sd.student_id
            LEFT JOIN student_location sl ON u.id = sl.student_id
            WHERE u.email = :email AND u.status = 'active'
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':email', $email);
        $stmt->execute();
        
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            return $user;
        }
        
        return false;
    }
    public function find($id) {
        $sql = "
            SELECT 
                u.*,
                sd.firstname,
                sd.lastname,
                sd.middleinitial,
                sd.course,
                sd.gwa,
                sd.school,
                sd.age,
                sd.last_gwa_update,
                sl.latitude,
                sl.longitude,
                CONCAT(sd.firstname, ' ', sd.lastname) as full_name
            FROM users u
            LEFT JOIN student_data sd ON u.id = sd.student_id
            LEFT JOIN student_location sl ON u.id = sl.student_id
            WHERE u.{$this->primaryKey} = :id
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        
        $user = $stmt->fetch();
        
        // If name is empty in users table, use the full_name from student_data
        if ($user && empty($user['name']) && !empty($user['full_name'])) {
            $user['name'] = $user['full_name'];
        }
        
        return $user;
    }
    
    public function findOneBy($column, $value) {
        if ($column === 'email') {
            $sql = "
                SELECT 
                    u.*,
                    sd.firstname,
                    sd.lastname,
                    sd.middleinitial,
                    sd.course,
                    sd.gwa,
                    sd.school,
                    sd.age,
                    sd.last_gwa_update,
                    sl.latitude,
                    sl.longitude,
                    CONCAT(sd.firstname, ' ', sd.lastname) as full_name
                FROM users u
                LEFT JOIN student_data sd ON u.id = sd.student_id
                LEFT JOIN student_location sl ON u.id = sl.student_id
                WHERE u.{$column} = :value
                LIMIT 1
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':value', $value);
            $stmt->execute();
            
            $user = $stmt->fetch();
            
            // If name is empty in users table, use the full_name from student_data
            if ($user && empty($user['name']) && !empty($user['full_name'])) {
                $user['name'] = $user['full_name'];
            }
            
            return $user;
        }
        
        // For other columns, use parent method
        return parent::findOneBy($column, $value);
    }
    
    public function register($data) {
        $errors = [];
        
        // Check for username field (not name)
        if (empty($data['username'])) $errors[] = 'Username is required';
        if (empty($data['email'])) $errors[] = 'Email is required';
        if (empty($data['password'])) {
            $errors[] = 'Password is required';
        } else {
            $passwordValidation = validateStrongPassword((string) $data['password'], [
                'username' => (string) ($data['username'] ?? ''),
                'email' => (string) ($data['email'] ?? ''),
                'firstname' => (string) ($data['firstname'] ?? ''),
                'lastname' => (string) ($data['lastname'] ?? ''),
                'name' => (string) ($data['name'] ?? ''),
            ]);

            if (!$passwordValidation['valid']) {
                $errors = array_merge($errors, $passwordValidation['errors']);
            }
        }
        
        // Check if email exists
        if ($this->findByEmail($data['email'])) {
            $errors[] = 'Email already registered';
        }

        if (!empty($data['username']) && $this->findOneBy('username', $data['username'])) {
            $errors[] = 'Username already taken';
        }
        
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }
        
        // Hash password
        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        
        // Set and validate role
        $allowedRoles = ['student', 'provider', 'admin', 'super_admin'];
        $requestedRole = strtolower(trim((string) ($data['role'] ?? 'student')));
        $data['role'] = in_array($requestedRole, $allowedRoles, true) ? $requestedRole : 'student';

        if ($this->tableHasColumn('access_level')) {
            $levelMap = [
                'student' => 10,
                'provider' => 40,
                'admin' => 70,
                'super_admin' => 90
            ];
            $data['access_level'] = $levelMap[$data['role']] ?? 10;
        }

        $allowedStatuses = ['inactive', 'active', 'suspended', 'pending'];
        $requestedStatus = strtolower(trim((string) ($data['status'] ?? 'active')));
        if (!in_array($requestedStatus, $allowedStatuses, true)) {
            $requestedStatus = 'active';
        }

        if ($requestedStatus === 'pending' && !$this->statusValueSupported('pending')) {
            $requestedStatus = $this->statusValueSupported('inactive') ? 'inactive' : 'active';
        } elseif (!$this->statusValueSupported($requestedStatus)) {
            $requestedStatus = 'active';
        }

        $data['status'] = $requestedStatus;

        if ($this->tableHasColumn('email_verified_at')) {
            $verifiedAt = trim((string) ($data['email_verified_at'] ?? ''));
            $data['email_verified_at'] = $verifiedAt !== '' ? $verifiedAt : null;
        } else {
            unset($data['email_verified_at']);
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        
        // Insert user - make sure the column names match your database
        $userId = $this->create($data);
        
        if ($userId) {
            return ['success' => true, 'user_id' => $userId];
        }
        
        return ['success' => false, 'errors' => ['Registration failed']];
    }
    
    public function updateProfile($userId, $data) {
        $allowedFields = ['name', 'school', 'course', 'latitude', 'longitude', 'location_name'];
        $updateData = array_intersect_key($data, array_flip($allowedFields));
        
        if ($this->update($userId, $updateData)) {
            return ['success' => true, 'message' => 'Profile updated successfully'];
        }
        
        return ['success' => false, 'message' => 'Profile update failed'];
    }
    
    public function changePassword($userId, $currentPassword, $newPassword) {
        $user = $this->find($userId);
        
        if (!$user) {
            return ['success' => false, 'message' => 'User not found'];
        }
        
        if (!password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'message' => 'Current password is incorrect'];
        }
        
        if (password_verify($newPassword, (string) ($user['password'] ?? ''))) {
            return ['success' => false, 'message' => 'New password must be different from your current password'];
        }

        $passwordValidation = validateStrongPassword((string) $newPassword, [
            'username' => (string) ($user['username'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'name' => trim((string) (($user['name'] ?? '') ?: (($user['username'] ?? '')))),
        ]);
        if (!$passwordValidation['valid']) {
            return ['success' => false, 'message' => $passwordValidation['errors'][0] ?? passwordPolicyHint()];
        }
        
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        if ($this->update($userId, ['password' => $hashedPassword])) {
            return ['success' => true, 'message' => 'Password changed successfully'];
        }
        
        return ['success' => false, 'message' => 'Password change failed'];
    }
    
    public function getStudentData($userId) {
        $stmt = $this->pdo->prepare("
            SELECT sd.* 
            FROM student_data sd
            WHERE sd.student_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }
    
    public function getLocation($userId) {
        $stmt = $this->pdo->prepare("
            SELECT sl.* 
            FROM student_location sl
            WHERE sl.student_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }
    
    public function updateLocation($userId, $latitude, $longitude) {
        // First check if location exists
        $existing = $this->getLocation($userId);
        
        if ($existing) {
            // Update existing location
            $stmt = $this->pdo->prepare("
                UPDATE student_location 
                SET latitude = ?, longitude = ? 
                WHERE student_id = ?
            ");
            return $stmt->execute([$latitude, $longitude, $userId]);
        } else {
            // Insert new location
            $stmt = $this->pdo->prepare("
                INSERT INTO student_location (student_id, latitude, longitude) 
                VALUES (?, ?, ?)
            ");
            return $stmt->execute([$userId, $latitude, $longitude]);
        }
    }
    
    public function updateStudentData($userId, $data) {
        try {
            // First check if student data exists
            $existing = $this->getStudentData($userId);
            
            if ($existing) {
                // Update existing student data
                $sql = "UPDATE student_data SET 
                        firstname = ?, 
                        lastname = ?, 
                        middleinitial = ?, 
                        school = ?, 
                        course = ? 
                        WHERE student_id = ?";
                
                $stmt = $this->pdo->prepare($sql);
                $result = $stmt->execute([
                    $data['firstname'],
                    $data['lastname'],
                    $data['middleinitial'] ?? '',
                    $data['school'],
                    $data['course'],
                    $userId
                ]);
                
                return $result;
                
            } else {
                // Insert new student data
                $sql = "INSERT INTO student_data 
                        (student_id, firstname, lastname, middleinitial, school, course) 
                        VALUES (?, ?, ?, ?, ?, ?)";
                
                $stmt = $this->pdo->prepare($sql);
                $result = $stmt->execute([
                    $userId,
                    $data['firstname'],
                    $data['lastname'],
                    $data['middleinitial'] ?? '',
                    $data['school'],
                    $data['course']
                ]);
                
                return $result;
            }
            
        } catch (PDOException $e) {
            error_log("Database error in updateStudentData: " . $e->getMessage());
            error_log("SQL State: " . $e->errorInfo[0] . ", Error Code: " . $e->errorInfo[1] . ", Message: " . $e->errorInfo[2]);
            return false;
        }
    }
}
?>
