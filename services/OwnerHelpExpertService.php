<?php

namespace CRM\Services;

use CRM\Database;

class OwnerHelpExpertService
{
    private const PROFILE_STATUSES = ['active', 'hidden'];
    private const APPROVAL_STATUSES = ['draft', 'pending', 'approved', 'rejected'];
    private const VERIFICATION_LEVELS = ['self_declared', 'platform_verified', 'system_verified'];
    private const PHOTO_MAX_BYTES = 2097152;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function activeExperts(): array
    {
        if (!$this->profilesTableReady()) {
            return [];
        }

        $approvalSql = $this->approvalColumnReady() ? " AND p.approval_status = 'approved'" : '';
        $orderSql = $this->sortColumnReady() ? 'p.sort_order ASC, p.id ASC' : 'p.id ASC';
        $rows = Database::query(
            "SELECT p.*, u.email, u.first_name, u.last_name
             FROM owner_help_expert_profiles p
             JOIN users u ON u.id = p.user_id
             WHERE p.profile_status = 'active'
               AND p.is_internal = 1{$approvalSql}
             ORDER BY {$orderSql}"
        );

        $experts = [];
        foreach ($rows as $row) {
            $expert = $this->normalizeProfile($row);
            $expert['skills'] = $this->skillsForProfile((int) $expert['id']);
            $experts[] = $expert;
        }

        return $experts;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $profileId): ?array
    {
        if ($profileId <= 0 || !$this->profilesTableReady()) {
            return null;
        }

        $approvalSql = $this->approvalColumnReady() ? " AND p.approval_status = 'approved'" : '';
        $row = Database::queryOne(
            "SELECT p.*, u.email, u.first_name, u.last_name
             FROM owner_help_expert_profiles p
             JOIN users u ON u.id = p.user_id
             WHERE p.id = ?
               AND p.profile_status = 'active'
               AND p.is_internal = 1{$approvalSql}
             LIMIT 1",
            [$profileId]
        );
        if (!$row) {
            return null;
        }

        $expert = $this->normalizeProfile($row);
        $expert['skills'] = $this->skillsForProfile((int) $expert['id']);

        return $expert;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function adminProfiles(): array
    {
        if (!$this->profilesTableReady()) {
            return [];
        }

        $orderSql = $this->sortColumnReady() ? 'p.sort_order ASC, p.id DESC' : 'p.id DESC';
        $rows = Database::query(
            "SELECT p.*, u.email, u.first_name, u.last_name
             FROM owner_help_expert_profiles p
             LEFT JOIN users u ON u.id = p.user_id
             WHERE p.is_internal = 1
             ORDER BY {$orderSql}"
        );

        $profiles = [];
        foreach ($rows as $row) {
            $profile = $this->normalizeProfile($row);
            $profile['skills'] = $this->skillsForProfile((int) $profile['id']);
            $profiles[] = $profile;
        }

        return $profiles;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function adminProfile(int $profileId): ?array
    {
        if ($profileId <= 0 || !$this->profilesTableReady()) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT p.*, u.email, u.first_name, u.last_name
             FROM owner_help_expert_profiles p
             LEFT JOIN users u ON u.id = p.user_id
             WHERE p.id = ? AND p.is_internal = 1
             LIMIT 1",
            [$profileId]
        );
        if (!$row) {
            return null;
        }

        $profile = $this->normalizeProfile($row);
        $profile['skills'] = $this->skillsForProfile((int) $profile['id']);

        return $profile;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function saveProfile(array $data, int $actorUserId): int
    {
        if (!$this->profilesTableReady()) {
            throw new \RuntimeException('Expert profile storage is not available.');
        }

        $profileId = max(0, (int) ($data['profile_id'] ?? 0));
        $userId = max(0, (int) ($data['user_id'] ?? 0));
        if ($userId <= 0) {
            throw new \RuntimeException('Choose a real workspace user for this expert profile.');
        }
        $profileStatus = $this->normalizeChoice((string) ($data['profile_status'] ?? 'active'), self::PROFILE_STATUSES, 'active');
        $approvalStatus = $this->approvalColumnReady()
            ? $this->normalizeChoice((string) ($data['approval_status'] ?? 'pending'), self::APPROVAL_STATUSES, 'pending')
            : 'approved';
        $rejectionNote = $approvalStatus === 'rejected' ? $this->cleanMultiline((string) ($data['rejection_note'] ?? '')) : '';
        $publicSlug = $this->normalizeSlug((string) ($data['public_slug'] ?? ''));
        $sortOrder = (int) ($data['sort_order'] ?? 100);

        $fields = [
            'user_id' => $userId > 0 ? $userId : null,
            'role_label' => $this->cleanSingleLine((string) ($data['role_label'] ?? 'Setup Specialist'), 120),
            'headline' => $this->cleanSingleLine((string) ($data['headline'] ?? ''), 220),
            'bio' => $this->cleanMultiline((string) ($data['bio'] ?? '')),
            'cv_summary' => $this->cleanMultiline((string) ($data['cv_summary'] ?? '')),
            'setup_areas_json' => json_encode($this->linesToList((string) ($data['setup_areas'] ?? '')), JSON_UNESCAPED_SLASHES),
            'industries_json' => json_encode($this->linesToList((string) ($data['industries'] ?? '')), JSON_UNESCAPED_SLASHES),
            'languages_json' => json_encode($this->linesToList((string) ($data['languages'] ?? '')), JSON_UNESCAPED_SLASHES),
            'timezone' => $this->cleanSingleLine((string) ($data['timezone'] ?? ''), 80),
            'availability_summary' => $this->cleanSingleLine((string) ($data['availability_summary'] ?? ''), 220),
            'profile_status' => $profileStatus,
            'approval_status' => $approvalStatus,
            'rejection_note' => $rejectionNote !== '' ? $rejectionNote : null,
            'sort_order' => $sortOrder,
            'public_slug' => $publicSlug !== '' ? $publicSlug : null,
            'approved_by' => null,
            'approved_at' => null,
        ];

        if ($fields['role_label'] === '') {
            $fields['role_label'] = 'Setup Specialist';
        }

        if ($approvalStatus === 'approved') {
            $fields['approved_by'] = $actorUserId > 0 ? $actorUserId : null;
            $fields['approved_at'] = date('Y-m-d H:i:s');
            $fields['rejection_note'] = null;
        }

        if ($profileId > 0 && !$this->adminProfile($profileId)) {
            throw new \RuntimeException('Expert profile was not found.');
        }

        if ($profileId > 0) {
            Database::execute(
                "UPDATE owner_help_expert_profiles
                 SET user_id = ?, role_label = ?, headline = ?, bio = ?, cv_summary = ?,
                     setup_areas_json = ?, industries_json = ?, languages_json = ?, timezone = ?,
                     availability_summary = ?, profile_status = ?, approval_status = ?,
                     approved_by = CASE WHEN ? = 'approved' THEN ? ELSE approved_by END,
                     approved_at = CASE WHEN ? = 'approved' THEN COALESCE(approved_at, ?) ELSE approved_at END,
                     rejection_note = ?, sort_order = ?, public_slug = ?, updated_at = NOW()
                 WHERE id = ? AND is_internal = 1",
                [
                    $fields['user_id'],
                    $fields['role_label'],
                    $fields['headline'],
                    $fields['bio'],
                    $fields['cv_summary'],
                    $fields['setup_areas_json'],
                    $fields['industries_json'],
                    $fields['languages_json'],
                    $fields['timezone'],
                    $fields['availability_summary'],
                    $fields['profile_status'],
                    $fields['approval_status'],
                    $approvalStatus,
                    $fields['approved_by'],
                    $approvalStatus,
                    $fields['approved_at'],
                    $fields['rejection_note'],
                    $fields['sort_order'],
                    $fields['public_slug'],
                    $profileId,
                ]
            );
            return $profileId;
        }

        Database::execute(
            "INSERT INTO owner_help_expert_profiles
                (user_id, role_label, headline, bio, cv_summary, setup_areas_json, industries_json,
                 languages_json, timezone, availability_summary, profile_status, is_internal,
                 approval_status, approved_by, approved_at, rejection_note, sort_order, public_slug,
                 created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $fields['user_id'],
                $fields['role_label'],
                $fields['headline'],
                $fields['bio'],
                $fields['cv_summary'],
                $fields['setup_areas_json'],
                $fields['industries_json'],
                $fields['languages_json'],
                $fields['timezone'],
                $fields['availability_summary'],
                $fields['profile_status'],
                $fields['approval_status'],
                $fields['approved_by'],
                $fields['approved_at'],
                $fields['rejection_note'],
                $fields['sort_order'],
                $fields['public_slug'],
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function updateApproval(int $profileId, string $status, int $actorUserId, string $rejectionNote = ''): void
    {
        if (!$this->approvalColumnReady()) {
            throw new \RuntimeException('Expert approval storage is not available.');
        }
        if (!$this->adminProfile($profileId)) {
            throw new \RuntimeException('Expert profile was not found.');
        }

        $status = $this->normalizeChoice($status, self::APPROVAL_STATUSES, 'pending');
        $note = $status === 'rejected' ? $this->cleanMultiline($rejectionNote) : '';

        Database::execute(
            "UPDATE owner_help_expert_profiles
             SET approval_status = ?,
                 approved_by = CASE WHEN ? = 'approved' THEN ? ELSE approved_by END,
                 approved_at = CASE WHEN ? = 'approved' THEN NOW() ELSE approved_at END,
                 rejection_note = ?,
                 updated_at = NOW()
             WHERE id = ? AND is_internal = 1",
            [
                $status,
                $status,
                $actorUserId > 0 ? $actorUserId : null,
                $status,
                $note !== '' ? $note : null,
                $profileId,
            ]
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    public function saveSkill(int $profileId, array $data): int
    {
        if ($profileId <= 0 || !Database::tableExists('owner_help_expert_skills')) {
            throw new \RuntimeException('Expert skill storage is not available.');
        }
        if (!$this->adminProfile($profileId)) {
            throw new \RuntimeException('Expert profile was not found.');
        }

        $skillId = max(0, (int) ($data['skill_id'] ?? 0));
        $label = $this->cleanSingleLine((string) ($data['skill_label'] ?? ''), 120);
        if ($label === '') {
            throw new \RuntimeException('Skill label is required.');
        }

        $key = $this->normalizeSlug((string) ($data['skill_key'] ?? ''));
        if ($key === '') {
            $key = $this->normalizeSlug($label);
        }
        $level = $this->normalizeChoice((string) ($data['verification_level'] ?? 'self_declared'), self::VERIFICATION_LEVELS, 'self_declared');
        $evidence = $this->cleanSingleLine((string) ($data['evidence_label'] ?? ''), 180);
        $sortOrder = (int) ($data['sort_order'] ?? 100);

        if ($skillId > 0) {
            Database::execute(
                "UPDATE owner_help_expert_skills
                 SET skill_key = ?, skill_label = ?, verification_level = ?, evidence_label = ?,
                     sort_order = ?, updated_at = NOW()
                 WHERE id = ? AND expert_profile_id = ?",
                [$key, $label, $level, $evidence, $sortOrder, $skillId, $profileId]
            );
            return $skillId;
        }

        Database::execute(
            "INSERT INTO owner_help_expert_skills
                (expert_profile_id, skill_key, skill_label, verification_level, evidence_label, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [$profileId, $key, $label, $level, $evidence, $sortOrder]
        );

        return (int) Database::lastInsertId();
    }

    public function deleteSkill(int $profileId, int $skillId): void
    {
        if ($profileId <= 0 || $skillId <= 0 || !Database::tableExists('owner_help_expert_skills')) {
            return;
        }

        Database::execute(
            "DELETE FROM owner_help_expert_skills WHERE id = ? AND expert_profile_id = ?",
            [$skillId, $profileId]
        );
    }

    /**
     * @param array<string,mixed> $file
     */
    public function storeProfilePhoto(int $profileId, array $file): string
    {
        if (!$this->photoColumnReady()) {
            throw new \RuntimeException('Profile photo storage is not available.');
        }
        if (!$this->adminProfile($profileId)) {
            throw new \RuntimeException('Expert profile was not found.');
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return '';
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Profile photo upload failed.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::PHOTO_MAX_BYTES) {
            throw new \RuntimeException('Profile photo must be 2MB or smaller.');
        }
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \RuntimeException('Profile photo upload was not valid.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => '',
        };
        if ($extension === '') {
            throw new \RuntimeException('Profile photo must be a JPG, PNG, or WebP image.');
        }

        $directory = dirname(__DIR__) . '/uploads/owner_help_experts/' . $profileId;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to prepare profile photo storage.');
        }

        $filename = 'profile-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $destination = $directory . '/' . $filename;
        if (!move_uploaded_file($tmpName, $destination)) {
            throw new \RuntimeException('Unable to store profile photo.');
        }

        $relativePath = 'uploads/owner_help_experts/' . $profileId . '/' . $filename;
        Database::execute(
            "UPDATE owner_help_expert_profiles SET profile_photo_path = ?, updated_at = NOW() WHERE id = ? AND is_internal = 1",
            [$relativePath, $profileId]
        );

        return $relativePath;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function skillsForProfile(int $profileId): array
    {
        if ($profileId <= 0 || !Database::tableExists('owner_help_expert_skills')) {
            return [];
        }

        return array_map(fn(array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'skill_key' => (string) ($row['skill_key'] ?? ''),
            'skill_label' => (string) ($row['skill_label'] ?? 'Skill'),
            'verification_level' => (string) ($row['verification_level'] ?? 'self_declared'),
            'evidence_label' => (string) ($row['evidence_label'] ?? ''),
            'sort_order' => (int) ($row['sort_order'] ?? 100),
            'is_verified' => in_array((string) ($row['verification_level'] ?? ''), ['platform_verified', 'system_verified'], true),
        ], Database::query(
            "SELECT *
             FROM owner_help_expert_skills
             WHERE expert_profile_id = ?
             ORDER BY sort_order ASC, skill_label ASC",
            [$profileId]
        ));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeProfile(array $row): array
    {
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        if ($name === '') {
            $name = (string) (($row['email'] ?? '') ?: 'Internal expert');
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'name' => $name,
            'email' => (string) ($row['email'] ?? ''),
            'role_label' => (string) ($row['role_label'] ?? 'Setup Specialist'),
            'headline' => (string) ($row['headline'] ?? ''),
            'bio' => (string) ($row['bio'] ?? ''),
            'cv_summary' => (string) ($row['cv_summary'] ?? ''),
            'setup_areas' => $this->decodeList($row['setup_areas_json'] ?? null),
            'industries' => $this->decodeList($row['industries_json'] ?? null),
            'languages' => $this->decodeList($row['languages_json'] ?? null),
            'timezone' => (string) ($row['timezone'] ?? ''),
            'availability_summary' => (string) ($row['availability_summary'] ?? ''),
            'profile_photo_path' => (string) ($row['profile_photo_path'] ?? ''),
            'profile_status' => (string) ($row['profile_status'] ?? 'active'),
            'approval_status' => (string) ($row['approval_status'] ?? 'approved'),
            'approved_by' => isset($row['approved_by']) ? (int) $row['approved_by'] : null,
            'approved_at' => (string) ($row['approved_at'] ?? ''),
            'rejection_note' => (string) ($row['rejection_note'] ?? ''),
            'sort_order' => (int) ($row['sort_order'] ?? 100),
            'public_slug' => (string) ($row['public_slug'] ?? ''),
            'is_internal' => !empty($row['is_internal']),
        ];
    }

    public function updateProfileStatus(int $profileId, string $status): void
    {
        if ($profileId <= 0 || !$this->profilesTableReady()) {
            throw new \RuntimeException('Expert profile was not found.');
        }
        if (!$this->adminProfile($profileId)) {
            throw new \RuntimeException('Expert profile was not found.');
        }

        $status = $this->normalizeChoice($status, self::PROFILE_STATUSES, 'hidden');
        Database::execute(
            "UPDATE owner_help_expert_profiles
             SET profile_status = ?, updated_at = NOW()
             WHERE id = ? AND is_internal = 1",
            [$status, $profileId]
        );
    }

    public function deleteProfile(int $profileId): void
    {
        if ($profileId <= 0 || !$this->profilesTableReady()) {
            throw new \RuntimeException('Expert profile was not found.');
        }
        if (!$this->adminProfile($profileId)) {
            throw new \RuntimeException('Expert profile was not found.');
        }

        $activeRequests = $this->activeRequestCount($profileId);
        if ($activeRequests > 0) {
            throw new \RuntimeException('This expert profile is assigned to active help requests. Hide it or reassign those requests before deleting it.');
        }

        Database::beginTransaction();
        try {
            if (Database::tableExists('owner_help_service_requests')) {
                Database::execute(
                    "UPDATE owner_help_service_requests
                     SET expert_profile_id = NULL, updated_at = NOW()
                     WHERE expert_profile_id = ?",
                    [$profileId]
                );
            }
            if (Database::tableExists('owner_help_expert_skills')) {
                Database::execute(
                    "DELETE FROM owner_help_expert_skills WHERE expert_profile_id = ?",
                    [$profileId]
                );
            }
            Database::execute(
                "DELETE FROM owner_help_expert_profiles WHERE id = ? AND is_internal = 1",
                [$profileId]
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * @param array<int,int> $userIds
     */
    public function deleteProfilesForUsers(array $userIds): int
    {
        $userIds = array_values(array_unique(array_filter(
            array_map(static fn(mixed $value): int => (int) $value, $userIds),
            static fn(int $userId): bool => $userId > 0
        )));
        if ($userIds === [] || !$this->profilesTableReady()) {
            return 0;
        }

        $placeholders = $this->placeholders($userIds);
        $profileRows = Database::query(
            "SELECT id FROM owner_help_expert_profiles WHERE user_id IN ({$placeholders})",
            $userIds
        );
        $profileIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int) ($row['id'] ?? 0),
            $profileRows
        ), static fn(int $profileId): bool => $profileId > 0));
        if ($profileIds === []) {
            return 0;
        }

        $profilePlaceholders = $this->placeholders($profileIds);
        if (Database::tableExists('owner_help_service_requests')) {
            Database::execute(
                "UPDATE owner_help_service_requests
                 SET expert_profile_id = NULL, updated_at = NOW()
                 WHERE expert_profile_id IN ({$profilePlaceholders})",
                $profileIds
            );
        }
        if (Database::tableExists('owner_help_expert_skills')) {
            Database::execute(
                "DELETE FROM owner_help_expert_skills WHERE expert_profile_id IN ({$profilePlaceholders})",
                $profileIds
            );
        }

        return Database::execute(
            "DELETE FROM owner_help_expert_profiles WHERE id IN ({$profilePlaceholders})",
            $profileIds
        );
    }

    public function activeRequestCount(int $profileId): int
    {
        if ($profileId <= 0 || !Database::tableExists('owner_help_service_requests')) {
            return 0;
        }

        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM owner_help_service_requests
             WHERE expert_profile_id = ?
               AND lifecycle_status NOT IN ('resolved', 'cancelled')",
            [$profileId]
        )['c'] ?? 0);
    }

    /**
     * @return array<int,string>
     */
    private function decodeList(mixed $json): array
    {
        if (is_array($json)) {
            $decoded = $json;
        } elseif (is_string($json) && trim($json) !== '') {
            $decoded = json_decode($json, true);
        } else {
            $decoded = [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $value): string => trim((string) $value),
            $decoded
        ), static fn(string $value): bool => $value !== ''));
    }

    /**
     * @return array<int,string>
     */
    private function linesToList(string $value): array
    {
        $parts = preg_split('/[\r\n,]+/', $value) ?: [];
        return array_values(array_unique(array_filter(array_map(
            static fn(string $part): string => trim($part),
            $parts
        ), static fn(string $part): bool => $part !== '')));
    }

    private function cleanSingleLine(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }
        return substr($value, 0, $maxLength);
    }

    private function cleanMultiline(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", trim($value));
        return preg_replace("/\n{3,}/", "\n\n", $value) ?? '';
    }

    /**
     * @param array<int,string> $allowed
     */
    private function normalizeChoice(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function normalizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    /**
     * @param array<int,mixed> $values
     */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    private function profilesTableReady(): bool
    {
        return Database::tableExists('owner_help_expert_profiles');
    }

    private function approvalColumnReady(): bool
    {
        return $this->profilesTableReady() && Database::columnExists('owner_help_expert_profiles', 'approval_status');
    }

    private function sortColumnReady(): bool
    {
        return $this->profilesTableReady() && Database::columnExists('owner_help_expert_profiles', 'sort_order');
    }

    private function photoColumnReady(): bool
    {
        return $this->profilesTableReady() && Database::columnExists('owner_help_expert_profiles', 'profile_photo_path');
    }
}
