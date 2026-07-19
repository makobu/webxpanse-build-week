<?php

namespace CRM;

use PDOException;
use RuntimeException;
use Throwable;

class DatabaseConnectionException extends RuntimeException
{
    private string $category;
    private string $userMessage;

    /** @var array<string,mixed> */
    private array $safeDetails;

    /**
     * @param array<string,mixed> $safeDetails
     */
    public function __construct(
        string $message,
        string $category = 'connection_failed',
        string $userMessage = 'The application could not connect to the configured database.',
        array $safeDetails = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->category = $category;
        $this->userMessage = $userMessage;
        $this->safeDetails = $safeDetails;
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function configurationMissing(array $config = []): self
    {
        return new self(
            'Database configuration is not initialized. Call Database::init() first.',
            'configuration_missing',
            'Database configuration is missing or incomplete.',
            self::safeConfigDetails($config)
        );
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function fromPdoException(PDOException $exception, array $config): self
    {
        $message = $exception->getMessage();
        $category = self::categorizePdoMessage($message);
        $userMessage = match ($category) {
            'missing_database' => 'The configured database does not exist.',
            'access_denied' => 'The database rejected the configured username or password.',
            'server_unreachable' => 'The database server could not be reached.',
            default => 'The application could not connect to the configured database.',
        };

        $details = self::safeConfigDetails($config);
        $details['pdo_code'] = (string) $exception->getCode();
        $details['sanitized_error'] = self::sanitizePdoMessage($message, $config);

        return new self(
            'Database connection failed: ' . $details['sanitized_error'],
            $category,
            $userMessage,
            $details,
            $exception
        );
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getUserMessage(): string
    {
        return $this->userMessage;
    }

    /**
     * @return array<string,mixed>
     */
    public function getSafeDetails(): array
    {
        return $this->safeDetails;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function safeConfigDetails(array $config): array
    {
        return [
            'host' => self::safeString($config['host'] ?? ''),
            'database' => self::safeString($config['name'] ?? ''),
            'user' => self::safeString($config['user'] ?? ''),
            'password_configured' => array_key_exists('pass', $config) && (string) $config['pass'] !== '',
            'charset' => self::safeString($config['charset'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $config
     */
    private static function sanitizePdoMessage(string $message, array $config): string
    {
        $sanitized = preg_replace('/\s+/', ' ', trim($message)) ?? trim($message);
        $password = (string) ($config['pass'] ?? '');
        if ($password !== '') {
            $sanitized = str_replace($password, '[redacted]', $sanitized);
        }

        return $sanitized;
    }

    private static function categorizePdoMessage(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'unknown database')) {
            return 'missing_database';
        }
        if (str_contains($lower, 'access denied')) {
            return 'access_denied';
        }
        if (
            str_contains($lower, 'connection refused')
            || str_contains($lower, 'no such host')
            || str_contains($lower, 'php_network_getaddresses')
            || str_contains($lower, 'getaddrinfo')
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'actively refused')
        ) {
            return 'server_unreachable';
        }

        return 'connection_failed';
    }

    private static function safeString(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        return mb_substr($value, 0, 160);
    }
}
