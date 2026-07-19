<?php

namespace CRM\Tests\Unit\Core;

use CRM\ConcurrencyConflictException;
use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Tests\DatabaseTestCase;

class ConcurrencyTest extends DatabaseTestCase
{
    private Contacts $contacts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contacts = new Contacts();
    }

    public function testMatchedTokenUpdateIncrementsLockVersion(): void
    {
        $contactId = $this->createContact('Token');
        $contact = $this->contacts->getById($contactId);

        $this->contacts->update($contactId, [
            'first_name' => 'Updated',
            'expected_lock_version' => (int) ($contact['lock_version'] ?? 0),
        ]);

        $updated = $this->contacts->getById($contactId);
        $this->assertSame('Updated', $updated['first_name']);
        $this->assertSame((int) ($contact['lock_version'] ?? 0) + 1, (int) ($updated['lock_version'] ?? 0));
    }

    public function testStaleTokenThrowsConcurrencyConflict(): void
    {
        $contactId = $this->createContact('Stale');
        $contact = $this->contacts->getById($contactId);
        $staleVersion = (int) ($contact['lock_version'] ?? 0);

        $this->contacts->update($contactId, [
            'first_name' => 'First update',
            'expected_lock_version' => $staleVersion,
        ]);

        $this->expectException(ConcurrencyConflictException::class);
        $this->contacts->update($contactId, [
            'first_name' => 'Second update',
            'expected_lock_version' => $staleVersion,
        ]);
    }

    public function testMissingTokenRemainsCompatibleAndIncrements(): void
    {
        $contactId = $this->createContact('Internal');
        $contact = $this->contacts->getById($contactId);

        $this->contacts->update($contactId, [
            'first_name' => 'Internal update',
        ]);

        $updated = $this->contacts->getById($contactId);
        $this->assertSame('Internal update', $updated['first_name']);
        $this->assertSame((int) ($contact['lock_version'] ?? 0) + 1, (int) ($updated['lock_version'] ?? 0));
    }

    private function createContact(string $firstName): int
    {
        $created = $this->contacts->create([
            'first_name' => $firstName,
            'last_name' => 'Contract',
            'email' => strtolower($firstName) . '.' . bin2hex(random_bytes(3)) . '@example.test',
        ]);

        return (int) ($created['id'] ?? 0);
    }
}
