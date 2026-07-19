<?php
declare(strict_types=1);

namespace CRM;

class ConcurrencyConflictException extends \RuntimeException
{
    /**
     * @param array<string,mixed> $currentRecord
     * @param array<string,mixed> $submittedFields
     */
    public function __construct(
        private readonly string $entityType,
        private readonly int $entityId,
        private readonly ?int $expectedVersion,
        private readonly array $currentRecord,
        private readonly array $submittedFields = [],
        string $message = 'This record changed since you opened it.'
    ) {
        parent::__construct($message, 409);
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    public function getExpectedVersion(): ?int
    {
        return $this->expectedVersion;
    }

    public function getCurrentVersion(): ?int
    {
        return isset($this->currentRecord['lock_version']) ? (int) $this->currentRecord['lock_version'] : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function getCurrentRecord(): array
    {
        return $this->currentRecord;
    }

    /**
     * @return array<string,mixed>
     */
    public function getSubmittedFields(): array
    {
        return $this->submittedFields;
    }
}
