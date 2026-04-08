<?php
require_once 'Model.php';

class AdminData extends Model {
    protected $table = 'admin_data';
    protected $primaryKey = 'id';

    private $tableExistsCache = null;
    private $columnCache = [];

    public function tableExists(): bool
    {
        if ($this->tableExistsCache !== null) {
            return $this->tableExistsCache;
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute([':table_name' => $this->table]);
        $this->tableExistsCache = ((int) $stmt->fetchColumn()) > 0;

        return $this->tableExistsCache;
    }

    public function hasColumn(string $column): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        if (array_key_exists($column, $this->columnCache)) {
            return $this->columnCache[$column];
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
        $this->columnCache[$column] = ((int) $stmt->fetchColumn()) > 0;

        return $this->columnCache[$column];
    }

    public function getExpectedColumns(): array
    {
        return [
            'firstname',
            'lastname',
            'middleinitial',
            'suffix',
            'phone_number',
            'position',
            'department',
            'profile_photo',
            'access_level',
            'can_manage_users',
            'can_manage_scholarships',
            'can_review_documents',
            'can_view_reports',
            'is_super_admin',
            'created_by',
            'notes',
            'created_at',
            'updated_at'
        ];
    }

    public function getRequiredColumns(): array
    {
        return [
            'user_id',
            'firstname',
            'lastname',
            'phone_number',
            'position',
            'department',
            'access_level',
            'can_manage_users',
            'can_manage_scholarships',
            'can_review_documents',
            'can_view_reports',
            'is_super_admin'
        ];
    }

    public function getStorageStatus(): array
    {
        if (!$this->tableExists()) {
            return [
                'ready' => false,
                'table_exists' => false,
                'missing_columns' => $this->getRequiredColumns()
            ];
        }

        $missingColumns = [];
        foreach ($this->getRequiredColumns() as $column) {
            if (!$this->hasColumn($column)) {
                $missingColumns[] = $column;
            }
        }

        return [
            'ready' => empty($missingColumns),
            'table_exists' => true,
            'missing_columns' => $missingColumns
        ];
    }

    public function getByUserId(int $userId): ?array
    {
        if (!$this->tableExists() || !$this->hasColumn('user_id')) {
            return null;
        }

        $columns = ['id', 'user_id'];
        foreach ($this->getExpectedColumns() as $column) {
            if ($this->hasColumn($column)) {
                $columns[] = $column;
            }
        }

        $stmt = $this->pdo->prepare("
            SELECT " . implode(', ', $columns) . "
            FROM {$this->table}
            WHERE user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function saveForUser(int $userId, array $data): bool
    {
        $status = $this->getStorageStatus();
        if (!$status['ready']) {
            if (!$status['table_exists']) {
                throw new RuntimeException('Missing table: admin_data. Please run the admin/provider role migration first.');
            }

            throw new RuntimeException('Outdated admin_data table. Please run the updated admin/provider role migration.');
        }

        $payload = [];
        foreach ($data as $column => $value) {
            if ($column === 'user_id' || !$this->hasColumn($column)) {
                continue;
            }
            $payload[$column] = $value;
        }

        if (empty($payload)) {
            throw new RuntimeException('No admin profile fields are available to save.');
        }

        $existing = $this->getByUserId($userId);
        if ($existing) {
            return (bool) $this->update((int) $existing['id'], $payload);
        }

        return (bool) $this->create(array_merge(['user_id' => $userId], $payload));
    }
}
?>
