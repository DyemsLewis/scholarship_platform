<?php
require_once __DIR__ . '/../Config/helpers.php';
require_once 'AdminData.php';
require_once 'ProviderData.php';
require_once 'StaffProfile.php';

class StaffAccountProfile {
    private $pdo;
    private $adminDataModel;
    private $providerDataModel;
    private $legacyStaffProfileModel;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->adminDataModel = new AdminData($pdo);
        $this->providerDataModel = new ProviderData($pdo);
        $this->legacyStaffProfileModel = new StaffProfile($pdo);
    }

    public function normalizeRole(string $role): string
    {
        $normalized = strtolower(trim($role));
        return in_array($normalized, ['provider', 'admin', 'super_admin'], true) ? $normalized : 'staff';
    }

    public function isProviderRole(string $role): bool
    {
        return $this->normalizeRole($role) === 'provider';
    }

    public function isAdminRole(string $role): bool
    {
        $normalized = $this->normalizeRole($role);
        return in_array($normalized, ['admin', 'super_admin'], true);
    }

    public function isStaffRole(string $role): bool
    {
        return $this->isProviderRole($role) || $this->isAdminRole($role);
    }

    private function normalizeNullable($value): ?string
    {
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeRequired($value): string
    {
        return trim((string) $value);
    }

    private function normalizeBoolean($value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }

    private function formatMiddleInitial($value): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        $trimmed = str_replace('.', '', $trimmed);
        $trimmed = strtoupper(substr($trimmed, 0, 1));
        return $trimmed === '' ? null : $trimmed . '.';
    }

    public function getStorageStatus(string $role): array
    {
        $normalizedRole = $this->normalizeRole($role);
        $preferredStatus = $this->isProviderRole($normalizedRole)
            ? $this->providerDataModel->getStorageStatus()
            : ($this->isAdminRole($normalizedRole) ? $this->adminDataModel->getStorageStatus() : ['ready' => false, 'table_exists' => false, 'missing_columns' => []]);

        if (!empty($preferredStatus['ready'])) {
            return [
                'ready' => true,
                'preferred_source' => $this->isProviderRole($normalizedRole) ? 'provider_data' : 'admin_data',
                'active_source' => $this->isProviderRole($normalizedRole) ? 'provider_data' : 'admin_data',
                'legacy' => false,
                'table_exists' => true,
                'missing_columns' => []
            ];
        }

        $legacyStatus = $this->legacyStaffProfileModel->getStorageStatus();
        if (!empty($legacyStatus['ready'])) {
            return [
                'ready' => true,
                'preferred_source' => $this->isProviderRole($normalizedRole) ? 'provider_data' : 'admin_data',
                'active_source' => 'staff_profiles',
                'legacy' => true,
                'table_exists' => !empty($preferredStatus['table_exists']),
                'missing_columns' => $preferredStatus['missing_columns'] ?? []
            ];
        }

        return [
            'ready' => false,
            'preferred_source' => $this->isProviderRole($normalizedRole) ? 'provider_data' : 'admin_data',
            'active_source' => null,
            'legacy' => false,
            'table_exists' => !empty($preferredStatus['table_exists']),
            'missing_columns' => $preferredStatus['missing_columns'] ?? []
        ];
    }

    public function getStorageMessage(string $role): string
    {
        $status = $this->getStorageStatus($role);
        $preferred = $status['preferred_source'] ?? 'staff profile';

        if (!empty($status['ready']) && !empty($status['legacy'])) {
            return 'Using legacy staff_profiles fallback. Run the admin/provider role migration to enable the new ' . $preferred . ' table.';
        }

        if (!empty($status['ready'])) {
            return ucfirst(str_replace('_', ' ', (string) $status['active_source'])) . ' is ready.';
        }

        if (empty($status['table_exists'])) {
            return 'Missing table: ' . $preferred . '. Please run the admin/provider role migration first.';
        }

        return 'Outdated ' . $preferred . ' table. Please run the updated admin/provider role migration.';
    }

    public function supportsProviderVerificationDocumentUpload(): bool
    {
        $providerStatus = $this->providerDataModel->getStorageStatus();
        if (empty($providerStatus['ready'])) {
            return false;
        }

        return $this->providerDataModel->hasColumn('verification_document');
    }

    public function getProviderVerificationDocumentUploadMessage(): string
    {
        if ($this->supportsProviderVerificationDocumentUpload()) {
            return 'Provider verification document storage is ready.';
        }

        return 'Verification file uploads require the provider_data table with the verification_document column. Run the admin/provider role migration first.';
    }

    public function getByUserId(int $userId, string $role, ?array $userRecord = null): ?array
    {
        $normalizedRole = $this->normalizeRole($role);
        if ($this->isProviderRole($normalizedRole)) {
            $providerRow = $this->providerDataModel->getByUserId($userId);
            if ($providerRow) {
                return $this->mapProviderRow($providerRow, $userRecord);
            }
        } elseif ($this->isAdminRole($normalizedRole)) {
            $adminRow = $this->adminDataModel->getByUserId($userId);
            if ($adminRow) {
                return $this->mapAdminRow($adminRow, $userRecord);
            }
        }

        $legacyRow = $this->legacyStaffProfileModel->getByUserId($userId);
        if (!$legacyRow) {
            return null;
        }

        return $this->isProviderRole($normalizedRole)
            ? $this->mapLegacyProviderRow($legacyRow, $userRecord)
            : $this->mapLegacyAdminRow($legacyRow, $userRecord);
    }

    public function saveForUser(int $userId, string $role, array $data, array $context = []): bool
    {
        $normalizedRole = $this->normalizeRole($role);
        if ($this->isProviderRole($normalizedRole)) {
            $payload = $this->prepareProviderPayload($data, $context);
            $hasVerificationDocument = trim((string) ($payload['verification_document'] ?? '')) !== '';
            $status = $this->providerDataModel->getStorageStatus();

            if ($hasVerificationDocument && !$this->supportsProviderVerificationDocumentUpload()) {
                throw new RuntimeException($this->getProviderVerificationDocumentUploadMessage());
            }

            if (!empty($status['ready'])) {
                return $this->providerDataModel->saveForUser($userId, $payload);
            }

            $legacyStatus = $this->legacyStaffProfileModel->getStorageStatus();
            if (!empty($legacyStatus['ready'])) {
                if ($hasVerificationDocument) {
                    throw new RuntimeException($this->getProviderVerificationDocumentUploadMessage());
                }

                return $this->legacyStaffProfileModel->saveForUser($userId, $this->mapProviderPayloadToLegacy($payload, $context));
            }

            throw new RuntimeException($this->getStorageMessage($role));
        }

        if ($this->isAdminRole($normalizedRole)) {
            $payload = $this->prepareAdminPayload($data, $context);
            $status = $this->adminDataModel->getStorageStatus();
            if (!empty($status['ready'])) {
                return $this->adminDataModel->saveForUser($userId, $payload);
            }

            $legacyStatus = $this->legacyStaffProfileModel->getStorageStatus();
            if (!empty($legacyStatus['ready'])) {
                return $this->legacyStaffProfileModel->saveForUser($userId, $this->mapAdminPayloadToLegacy($payload, $context));
            }

            throw new RuntimeException($this->getStorageMessage($role));
        }

        throw new RuntimeException('Unsupported role for staff account profile.');
    }

    public function buildDisplayName(string $role, ?array $profile, array $userRecord = []): string
    {
        $profile = $profile ?? [];

        if ($this->isProviderRole($role)) {
            $contactName = trim(implode(' ', array_filter([
                trim((string) ($profile['contact_person_firstname'] ?? '')),
                trim((string) ($profile['contact_person_lastname'] ?? ''))
            ])));
            if ($contactName !== '') {
                return $contactName;
            }
            if (trim((string) ($profile['organization_name'] ?? '')) !== '') {
                return trim((string) $profile['organization_name']);
            }
        } else {
            $displayName = trim(implode(' ', array_filter([
                trim((string) ($profile['firstname'] ?? '')),
                trim((string) ($profile['middleinitial'] ?? '')),
                trim((string) ($profile['lastname'] ?? ''))
            ])));
            if (trim((string) ($profile['suffix'] ?? '')) !== '') {
                $displayName .= ' ' . trim((string) $profile['suffix']);
            }
            if (trim($displayName) !== '') {
                return trim($displayName);
            }
        }

        return trim((string) ($userRecord['username'] ?? 'Staff')) ?: 'Staff';
    }

    private function mapAdminRow(array $row, ?array $userRecord): array
    {
        $role = $this->normalizeRole((string) ($userRecord['role'] ?? 'admin'));

        return [
            'profile_source' => 'admin_data',
            'role_profile_type' => 'admin',
            'firstname' => trim((string) ($row['firstname'] ?? '')),
            'lastname' => trim((string) ($row['lastname'] ?? '')),
            'middleinitial' => trim((string) ($row['middleinitial'] ?? '')),
            'suffix' => trim((string) ($row['suffix'] ?? '')),
            'phone_number' => trim((string) ($row['phone_number'] ?? '')),
            'position' => trim((string) ($row['position'] ?? '')),
            'department' => trim((string) ($row['department'] ?? '')),
            'profile_photo' => trim((string) ($row['profile_photo'] ?? '')),
            'access_level' => (int) ($row['access_level'] ?? ($userRecord['access_level'] ?? ($role === 'super_admin' ? 90 : 70))),
            'can_manage_users' => (int) ($row['can_manage_users'] ?? ($role === 'provider' ? 0 : 1)),
            'can_manage_scholarships' => (int) ($row['can_manage_scholarships'] ?? 1),
            'can_review_documents' => (int) ($row['can_review_documents'] ?? 1),
            'can_view_reports' => (int) ($row['can_view_reports'] ?? ($role === 'provider' ? 0 : 1)),
            'is_super_admin' => (int) ($row['is_super_admin'] ?? ($role === 'super_admin' ? 1 : 0)),
            'created_by' => $row['created_by'] ?? null,
            'notes' => trim((string) ($row['notes'] ?? ''))
        ];
    }

    private function mapProviderRow(array $row, ?array $userRecord): array
    {
        return [
            'profile_source' => 'provider_data',
            'role_profile_type' => 'provider',
            'organization_name' => trim((string) ($row['organization_name'] ?? '')),
            'contact_person_firstname' => trim((string) ($row['contact_person_firstname'] ?? '')),
            'contact_person_lastname' => trim((string) ($row['contact_person_lastname'] ?? '')),
            'contact_person_position' => trim((string) ($row['contact_person_position'] ?? '')),
            'phone_number' => trim((string) ($row['phone_number'] ?? '')),
            'mobile_number' => trim((string) ($row['mobile_number'] ?? '')),
            'organization_email' => trim((string) ($row['organization_email'] ?? ($userRecord['email'] ?? ''))),
            'website' => trim((string) ($row['website'] ?? '')),
            'organization_type' => trim((string) ($row['organization_type'] ?? '')),
            'address' => trim((string) ($row['address'] ?? '')),
            'house_no' => trim((string) ($row['house_no'] ?? '')),
            'street' => trim((string) ($row['street'] ?? '')),
            'barangay' => trim((string) ($row['barangay'] ?? '')),
            'city' => trim((string) ($row['city'] ?? '')),
            'province' => trim((string) ($row['province'] ?? '')),
            'latitude' => trim((string) ($row['latitude'] ?? '')),
            'longitude' => trim((string) ($row['longitude'] ?? '')),
            'location_name' => trim((string) ($row['location_name'] ?? '')),
            'zip_code' => trim((string) ($row['zip_code'] ?? '')),
            'description' => trim((string) ($row['description'] ?? '')),
            'logo' => trim((string) ($row['logo'] ?? '')),
            'verification_document' => trim((string) ($row['verification_document'] ?? '')),
            'is_verified' => (int) ($row['is_verified'] ?? 0),
            'verified_at' => $row['verified_at'] ?? null
        ];
    }

    private function mapLegacyAdminRow(array $row, ?array $userRecord): array
    {
        $role = $this->normalizeRole((string) ($userRecord['role'] ?? 'admin'));

        return [
            'profile_source' => 'staff_profiles',
            'role_profile_type' => 'admin',
            'firstname' => trim((string) ($row['firstname'] ?? '')),
            'lastname' => trim((string) ($row['lastname'] ?? '')),
            'middleinitial' => trim((string) ($row['middleinitial'] ?? '')),
            'suffix' => trim((string) ($row['suffix'] ?? '')),
            'phone_number' => trim((string) ($row['office_phone'] ?? '')),
            'position' => trim((string) ($row['position_title'] ?? '')),
            'department' => trim((string) ($row['department'] ?? '')),
            'profile_photo' => '',
            'access_level' => (int) ($userRecord['access_level'] ?? ($role === 'super_admin' ? 90 : 70)),
            'can_manage_users' => $role === 'super_admin' || $role === 'admin' ? 1 : 0,
            'can_manage_scholarships' => 1,
            'can_review_documents' => 1,
            'can_view_reports' => $role === 'super_admin' || $role === 'admin' ? 1 : 0,
            'is_super_admin' => $role === 'super_admin' ? 1 : 0,
            'created_by' => null,
            'notes' => trim((string) ($row['responsibility_scope'] ?? ''))
        ];
    }

    private function mapLegacyProviderRow(array $row, ?array $userRecord): array
    {
        return [
            'profile_source' => 'staff_profiles',
            'role_profile_type' => 'provider',
            'organization_name' => trim((string) ($row['organization_name'] ?? '')),
            'contact_person_firstname' => trim((string) ($row['firstname'] ?? '')),
            'contact_person_lastname' => trim((string) ($row['lastname'] ?? '')),
            'contact_person_position' => trim((string) ($row['position_title'] ?? '')),
            'phone_number' => trim((string) ($row['office_phone'] ?? '')),
            'mobile_number' => '',
            'organization_email' => trim((string) ($userRecord['email'] ?? '')),
            'website' => trim((string) ($row['website'] ?? '')),
            'organization_type' => trim((string) ($row['organization_type'] ?? '')),
            'address' => trim((string) ($row['office_address'] ?? '')),
            'house_no' => '',
            'street' => '',
            'barangay' => '',
            'city' => trim((string) ($row['city'] ?? '')),
            'province' => trim((string) ($row['province'] ?? '')),
            'zip_code' => '',
            'description' => trim((string) ($row['responsibility_scope'] ?? '')),
            'logo' => '',
            'verification_document' => '',
            'is_verified' => 0,
            'verified_at' => null
        ];
    }

    private function prepareAdminPayload(array $data, array $context): array
    {
        $role = $this->normalizeRole((string) ($context['role'] ?? 'admin'));
        $accessLevel = isset($data['access_level']) && is_numeric($data['access_level'])
            ? (int) $data['access_level']
            : (int) ($context['access_level'] ?? ($role === 'super_admin' ? 90 : 70));

        return [
            'firstname' => $this->normalizeRequired($data['firstname'] ?? ''),
            'lastname' => $this->normalizeRequired($data['lastname'] ?? ''),
            'middleinitial' => $this->formatMiddleInitial($data['middleinitial'] ?? null),
            'suffix' => $this->normalizeNullable($data['suffix'] ?? null),
            'phone_number' => $this->normalizeRequired($data['phone_number'] ?? ''),
            'position' => $this->normalizeRequired($data['position'] ?? ''),
            'department' => $this->normalizeRequired($data['department'] ?? ''),
            'profile_photo' => $this->normalizeNullable($data['profile_photo'] ?? null),
            'access_level' => $accessLevel,
            'can_manage_users' => array_key_exists('can_manage_users', $data) ? $this->normalizeBoolean($data['can_manage_users']) : ($role === 'provider' ? 0 : 1),
            'can_manage_scholarships' => array_key_exists('can_manage_scholarships', $data) ? $this->normalizeBoolean($data['can_manage_scholarships']) : 1,
            'can_review_documents' => array_key_exists('can_review_documents', $data) ? $this->normalizeBoolean($data['can_review_documents']) : 1,
            'can_view_reports' => array_key_exists('can_view_reports', $data) ? $this->normalizeBoolean($data['can_view_reports']) : ($role === 'provider' ? 0 : 1),
            'is_super_admin' => $role === 'super_admin' ? 1 : (array_key_exists('is_super_admin', $data) ? $this->normalizeBoolean($data['is_super_admin']) : 0),
            'created_by' => isset($context['created_by']) && is_numeric($context['created_by']) ? (int) $context['created_by'] : null,
            'notes' => $this->normalizeNullable($data['notes'] ?? null)
        ];
    }

    private function prepareProviderPayload(array $data, array $context): array
    {
        return [
            'organization_name' => $this->normalizeRequired($data['organization_name'] ?? ''),
            'contact_person_firstname' => $this->normalizeRequired($data['contact_person_firstname'] ?? ''),
            'contact_person_lastname' => $this->normalizeRequired($data['contact_person_lastname'] ?? ''),
            'contact_person_position' => $this->normalizeRequired($data['contact_person_position'] ?? ''),
            'phone_number' => $this->normalizeRequired($data['phone_number'] ?? ''),
            'mobile_number' => normalizePhilippineMobileNumber($data['mobile_number'] ?? null),
            'organization_email' => $this->normalizeRequired($data['organization_email'] ?? ($context['email'] ?? '')),
            'website' => $this->normalizeNullable($data['website'] ?? null),
            'organization_type' => $this->normalizeRequired($data['organization_type'] ?? ''),
            'address' => $this->normalizeRequired($data['address'] ?? ''),
            'house_no' => $this->normalizeNullable($data['house_no'] ?? null),
            'street' => $this->normalizeNullable($data['street'] ?? null),
            'barangay' => $this->normalizeNullable($data['barangay'] ?? null),
            'city' => $this->normalizeRequired($data['city'] ?? ''),
            'province' => $this->normalizeRequired($data['province'] ?? ''),
            'latitude' => $this->normalizeNullable($data['latitude'] ?? null),
            'longitude' => $this->normalizeNullable($data['longitude'] ?? null),
            'location_name' => $this->normalizeNullable($data['location_name'] ?? null),
            'zip_code' => $this->normalizeNullable($data['zip_code'] ?? null),
            'description' => $this->normalizeNullable($data['description'] ?? null),
            'logo' => $this->normalizeNullable($data['logo'] ?? null),
            'verification_document' => $this->normalizeNullable($data['verification_document'] ?? null),
            'is_verified' => array_key_exists('is_verified', $data) ? $this->normalizeBoolean($data['is_verified']) : 0,
            'verified_at' => $this->normalizeNullable($data['verified_at'] ?? null)
        ];
    }

    private function mapAdminPayloadToLegacy(array $payload, array $context): array
    {
        return [
            'firstname' => $payload['firstname'],
            'lastname' => $payload['lastname'],
            'middleinitial' => $payload['middleinitial'],
            'suffix' => $payload['suffix'],
            'organization_name' => $this->normalizeRequired($context['organization_name'] ?? ($payload['department'] ?? 'Administration Office')),
            'organization_type' => 'internal_administration',
            'department' => $payload['department'],
            'position_title' => $payload['position'],
            'staff_id_no' => $this->normalizeNullable($context['staff_id_no'] ?? null),
            'office_phone' => $payload['phone_number'],
            'office_address' => $this->normalizeNullable($context['office_address'] ?? null),
            'city' => $this->normalizeNullable($context['city'] ?? null),
            'province' => $this->normalizeNullable($context['province'] ?? null),
            'website' => null,
            'responsibility_scope' => $payload['notes']
        ];
    }

    private function mapProviderPayloadToLegacy(array $payload, array $context): array
    {
        return [
            'firstname' => $payload['contact_person_firstname'],
            'lastname' => $payload['contact_person_lastname'],
            'middleinitial' => null,
            'suffix' => null,
            'organization_name' => $payload['organization_name'],
            'organization_type' => $payload['organization_type'],
            'department' => $this->normalizeNullable($context['department'] ?? 'Scholarship Office'),
            'position_title' => $payload['contact_person_position'],
            'staff_id_no' => $this->normalizeNullable($context['staff_id_no'] ?? null),
            'office_phone' => $payload['phone_number'],
            'office_address' => $payload['address'],
            'city' => $payload['city'],
            'province' => $payload['province'],
            'website' => $payload['website'],
            'responsibility_scope' => $payload['description']
        ];
    }
}
?>
