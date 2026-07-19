<?php

namespace CRM\Services;

class WorkspaceLaunchThrottleException extends \RuntimeException
{
    private int $retryAfter;

    public function __construct(string $message, int $retryAfter)
    {
        parent::__construct($message, 429);
        $this->retryAfter = max(1, $retryAfter);
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}
