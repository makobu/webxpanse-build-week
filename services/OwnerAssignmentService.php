<?php
/**
 * Owner Assignment Service
 *
 * Resolves owner for inbound triage actions.
 */

namespace CRM\Services;

use CRM\Database;

class OwnerAssignmentService
{
    /**
     * Resolve owner for a contact based on:
     * 1) contact owner
     * 2) latest open deal owner
     * 3) least-loaded sales/admin user
     * 4) any admin
     */
    public function resolveOwnerForContact(int $contactId): int
    {
        if ($contactId <= 0) {
            return 0;
        }

        $contact = Database::queryOne(
            "SELECT assigned_to FROM contacts WHERE id = ? LIMIT 1",
            [$contactId]
        );
        $contactOwner = (int) ($contact['assigned_to'] ?? 0);
        if ($contactOwner > 0 && $this->isActiveUser($contactOwner)) {
            return $contactOwner;
        }

        $dealOwner = Database::queryOne(
            "SELECT assigned_to
             FROM deals
             WHERE contact_id = ?
               AND stage IN ('prospecting','qualification','proposal','negotiation')
               AND assigned_to IS NOT NULL
             ORDER BY updated_at DESC, id DESC
             LIMIT 1",
            [$contactId]
        );
        $dealAssignedTo = (int) ($dealOwner['assigned_to'] ?? 0);
        if ($dealAssignedTo > 0 && $this->isActiveUser($dealAssignedTo)) {
            return $dealAssignedTo;
        }

        $leastLoaded = Database::queryOne(
            "SELECT u.id
             FROM users u
             LEFT JOIN contacts c ON c.assigned_to = u.id
             WHERE u.role IN ('sales', 'admin', 'owner')
             GROUP BY u.id
             ORDER BY COUNT(c.id) ASC, u.id ASC
             LIMIT 1"
        );
        $leastLoadedId = (int) ($leastLoaded['id'] ?? 0);
        if ($leastLoadedId > 0) {
            return $leastLoadedId;
        }

        $admin = Database::queryOne(
            "SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1"
        );
        return (int) ($admin['id'] ?? 0);
    }

    public function assignContactOwner(int $contactId, int $ownerId): void
    {
        if ($contactId <= 0 || $ownerId <= 0) {
            return;
        }
        Database::execute(
            "UPDATE contacts SET assigned_to = ? WHERE id = ?",
            [$ownerId, $contactId]
        );
    }

    private function isActiveUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $row = Database::queryOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$userId]);
        return !empty($row['id']);
    }
}

