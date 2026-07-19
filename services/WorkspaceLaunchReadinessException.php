<?php

namespace CRM\Services;

class WorkspaceLaunchReadinessException extends \RuntimeException
{
    private string $surface;

    /** @var array<string,mixed> */
    private array $readiness;

    /**
     * @param array<string,mixed> $readiness
     */
    public function __construct(string $surface, array $readiness, ?\Throwable $previous = null)
    {
        $this->surface = $surface;
        $this->readiness = $readiness;

        parent::__construct(
            (string) ($readiness['customer_message'] ?? 'This workspace surface is temporarily unavailable until the latest SaaS migrations are applied.'),
            0,
            $previous
        );
    }

    public function surface(): string
    {
        return $this->surface;
    }

    /**
     * @return array<string,mixed>
     */
    public function readiness(): array
    {
        return $this->readiness;
    }

    public function operatorMessage(): string
    {
        return (string) ($this->readiness['operator_message'] ?? $this->getMessage());
    }
}
