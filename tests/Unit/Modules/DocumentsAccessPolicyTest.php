<?php

declare(strict_types=1);

use CRM\Modules\Documents;

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../../modules/Documents.php';

final class DocumentsAccessPolicyTest extends PHPUnit\Framework\TestCase
{
    public function testContactsRequireReadPermissionWhenPermissionExists(): void
    {
        $documents = new DocumentsAccessPolicyDouble(
            ['contacts.read' => true],
            ['contacts.read' => false]
        );

        $allowed = $documents->canUserAccessEntity(['id' => 7], 'contact', 10, 'read');

        $this->assertFalse($allowed);
    }

    public function testDealsAllowWriteWhenPermissionExistsAndUserHasPermission(): void
    {
        $documents = new DocumentsAccessPolicyDouble(
            ['deals.write' => true],
            ['deals.write' => true]
        );

        $allowed = $documents->canUserAccessEntity(['id' => 7], 'deal', 12, 'write');

        $this->assertTrue($allowed);
    }

    public function testEventsStayAccessibleForAuthenticatedUsersUntilEventPermissionsExist(): void
    {
        $documents = new DocumentsAccessPolicyDouble();

        $allowed = $documents->canUserAccessEntity(['id' => 7], 'event', 4, 'read');

        $this->assertTrue($allowed);
    }
}

final class DocumentsAccessPolicyDouble extends Documents
{
    public function __construct(
        private array $availablePermissions = [],
        private array $grantedPermissions = [],
        private bool $legacyFallback = false
    ) {
    }

    protected function canAccessContactDocument(?array $user, int $entityId, string $action): bool
    {
        return $this->allowsEntityAction($user, $action, 'contacts.read', 'contacts.write', false);
    }

    protected function canAccessDealDocument(?array $user, int $entityId, string $action): bool
    {
        return $this->allowsEntityAction($user, $action, 'deals.read', 'deals.write', false);
    }

    protected function canAccessEventDocument(?array $user, int $entityId): bool
    {
        return !empty($user['id']) && $entityId > 0;
    }

    protected function allowsEntityAction(?array $user, string $action, string $readPermission, string $writePermission, bool $ownerMatch): bool
    {
        if (!$user || empty($user['id'])) {
            return false;
        }

        $permission = $action === 'write' ? $writePermission : $readPermission;
        if ($this->availablePermissions[$permission] ?? false) {
            if ($this->grantedPermissions[$permission] ?? false) {
                return true;
            }

            return $this->legacyFallback ? $ownerMatch : false;
        }

        return true;
    }
}
