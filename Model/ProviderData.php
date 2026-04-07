<?php
require_once 'Model.php';

class ProviderData extends Model {
    protected $table = 'provider_data';
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
            'organization_name',
            'contact_person_firstname',
            'contact_person_lastname',
            'contact_person_position',
            'phone_number',
            'mobile_number',
            'organization_email',
            'website',
            'organization_type',
            'address',
            'house_no',
            'street',
            'barangay',
            'city',
            'province',
            'latitude',
            'longitude',
            'location_name',
            'zip_code',
            'description',
            'logo',
            'verification_document',
            'is_verified',
            'verified_at',
            'created_at',
            'updated_at'
        ];
    }

    public function getRequiredColumns(): array
    {
        return [
            'user_id',
            'organization_name',
            'contact_person_firstname',
            'contact_person_lastname',
            'contact_person_position',
            'phone_number',
            'organization_email',
            'organization_type',
            'address',
            'city',
            'province',
            'is_verified'
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
                throw new RuntimeException('Missing table: provider_data. Please run the admin/provider role migration first.');
            }

            throw new RuntimeException('Outdated provider_data table. Please run the updated admin/provider role migration.');
        }

        $payload = [];
        foreach ($data as $column => $value) {
            if ($column === 'user_id' || !$this->hasColumn($column)) {
                continue;
            }
            $payload[$column] = $value;
        }

        if (empty($payload)) {
            throw new RuntimeException('No provider profile fields are available to save.');
        }

        $existing = $this->getByUserId($userId);
        if ($existing) {
            return (bool) $this->update((int) $existing['id'], $payload);
        }

        return (bool) $this->create(array_merge(['user_id' => $userId], $payload));
    }
}
?>
