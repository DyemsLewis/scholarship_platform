<?php
require_once 'Model.php';

class StaffProfile extends Model {
    protected $table = 'staff_profiles';
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
            'organization_name',
            'organization_type',
            'department',
            'position_title',
            'staff_id_no',
            'office_phone',
            'office_address',
            'city',
            'province',
            'website',
            'responsibility_scope',
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
            'organization_name',
            'organization_type',
            'department',
            'position_title',
            'office_phone',
            'office_address',
            'city',
            'province'
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
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        return $profile ?: null;
    }

    public function saveForUser(int $userId, array $data): bool
    {
        $status = $this->getStorageStatus();
        if (!$status['ready']) {
            if (!$status['table_exists']) {
                throw new RuntimeException('Missing table: staff_profiles. Please run the staff profile migration first.');
            }

            throw new RuntimeException('Outdated staff_profiles table. Please run the updated staff profile migration.');
        }

        $payload = [];
        foreach ($data as $column => $value) {
            if ($column === 'user_id' || !$this->hasColumn($column)) {
                continue;
            }
            $payload[$column] = $value;
        }

        if (empty($payload)) {
            throw new RuntimeException('No staff profile fields are available to save.');
        }

        $existing = $this->getByUserId($userId);
        if ($existing) {
            return (bool) $this->update((int) $existing['id'], $payload);
        }

        return (bool) $this->create(array_merge(['user_id' => $userId], $payload));
    }
}
?>
