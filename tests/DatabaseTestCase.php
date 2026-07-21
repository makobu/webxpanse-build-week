<?php
/**
 * Database Test Case
 * 
 * Base class for database-related tests
 */

namespace CRM\Tests;

use CRM\Authorization;
use CRM\Database;
use CRM\EventBus;
use CRM\Modules\PerformanceMonitor;
use CRM\Session;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceScopeService;
use CRM\Services\WorkspaceSkillCatalogService;
use PDO;
use PDOException;

abstract class DatabaseTestCase extends TestCase
{
    private static ?string $templateDatabaseName = null;
    private static bool $templateDatabaseReady = false;
    /** @var array<string,true> */
    private static array $createdDatabaseNames = [];
    private static bool $cleanupRegistered = false;

    private ?string $testDatabaseName = null;
    private ?string $originalDatabaseName = null;

    protected function setUp(): void
    {
        PerformanceMonitor::reset();
        WorkspaceScopeService::resetGuards();
        EventBus::reset();
        Authorization::resetCaches();
        $this->originalDatabaseName = (string) ($_ENV['DB_NAME'] ?? 'crm_test');
        $this->testDatabaseName = $this->generateTestDatabaseName();
        self::trackTemporaryDatabase($this->testDatabaseName);
        self::registerDatabaseCleanup();
        $_ENV['DB_NAME'] = $this->testDatabaseName;
        putenv('DB_NAME=' . $this->testDatabaseName);

        parent::setUp();
        
        $this->runMigrations();
    }
    
    protected function tearDown(): void
    {
        $this->dropTestDatabase();
        $_ENV['DB_NAME'] = $this->originalDatabaseName ?: 'crm_test';
        putenv('DB_NAME=' . ($_ENV['DB_NAME'] ?? 'crm_test'));
        PerformanceMonitor::reset();
        WorkspaceScopeService::resetGuards();
        EventBus::reset();
        Authorization::resetCaches();
        parent::tearDown();
    }
    
    /**
     * Run database migrations
     */
    protected function runMigrations(): void
    {
        $dbHost = getenv('DB_HOST') !== false ? (string) getenv('DB_HOST') : ($_ENV['DB_HOST'] ?? 'localhost');
        $dbName = $this->testDatabaseName ?? ($_ENV['DB_NAME'] ?? 'crm_test');
        $dbUser = getenv('DB_USER') !== false ? (string) getenv('DB_USER') : ($_ENV['DB_USER'] ?? 'root');
        $dbPass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : ($_ENV['DB_PASS'] ?? '');

        Database::close();

        $pdo = $this->createAdminConnection($dbHost, $dbUser, $dbPass);

        $templateDatabaseName = $this->ensureTemplateDatabase($pdo);
        $this->cloneTemplateDatabase($pdo, $templateDatabaseName, $dbName);

        Database::init([
            'host' => $dbHost,
            'name' => $dbName,
            'user' => $dbUser,
            'pass' => $dbPass,
            'charset' => 'utf8mb4',
            'options' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => true,
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ],
        ]);
        Authorization::resetCaches();
        WorkspaceSkillCatalogService::resetRuntimeCaches();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.save_path', sys_get_temp_dir());
            session_save_path(sys_get_temp_dir());
        }
        Session::start();
        Session::set('active_workspace_id', 1);
        Session::set('active_workspace_uuid', '00000000-0000-4000-8000-000000000001');
        Session::set('active_workspace_slug', 'default');
        Session::set('active_workspace_name', 'Default Workspace');
        Session::set('active_workspace_role', 'owner');
        Session::set('active_workspace_membership_id', 1);
        WorkspaceContext::activateRuntimeWorkspace(1);
    }

    private function ensureTemplateDatabase(PDO $pdo): string
    {
        if (self::$templateDatabaseName === null) {
            self::$templateDatabaseName = $this->generateTemplateDatabaseName();
        }

        if (self::$templateDatabaseReady) {
            return self::$templateDatabaseName;
        }

        $templateDatabaseName = self::$templateDatabaseName;
        if ($this->templateDatabaseMatches($pdo, $templateDatabaseName)) {
            self::$templateDatabaseReady = true;
            return $templateDatabaseName;
        }

        self::dropTemporaryDatabase($pdo, $templateDatabaseName);
        $pdo->exec("CREATE DATABASE `" . self::quoteIdentifier($templateDatabaseName) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `" . self::quoteIdentifier($templateDatabaseName) . "`");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS migrations (
                id INT PRIMARY KEY AUTO_INCREMENT,
                migration_name VARCHAR(255) UNIQUE NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $migrationDir = __DIR__ . '/../database/migrations';
        $files = glob($migrationDir . '/*.sql') ?: [];
        natsort($files);

        foreach ($files as $file) {
            $migrationName = basename($file);
            $sql = file_get_contents($file);
            $statements = $this->splitSqlStatements($sql ?: '');

            foreach ($statements as $statement) {
                if ($statement !== '') {
                    try {
                        $this->executeMigrationStatement($pdo, $statement);
                    } catch (PDOException $e) {
                        $message = $e->getMessage();
                        $isRepeatableMigrationError =
                            strpos($message, 'already exists') !== false ||
                            strpos($message, 'Duplicate column name') !== false ||
                            strpos($message, 'Duplicate key name') !== false ||
                            strpos($message, 'Duplicate foreign key constraint name') !== false;
                        $isAlterTable = stripos(ltrim($statement), 'ALTER TABLE') === 0;
                        $isMissingOptionalTable = $isAlterTable && strpos($message, 'Base table or view not found') !== false;

                        if (!$isRepeatableMigrationError && !$isMissingOptionalTable) {
                            throw new \RuntimeException(
                                sprintf(
                                    'Migration %s failed: %s. Statement: %s',
                                    $migrationName,
                                    $message,
                                    substr(preg_replace('/\s+/', ' ', trim($statement)), 0, 240)
                                ),
                                0,
                                $e
                            );
                        }
                    }
                }
            }

            $stmt = $pdo->prepare("INSERT INTO migrations (migration_name) VALUES (?)");
            $stmt->execute([$migrationName]);
        }

        self::$templateDatabaseReady = true;
        return $templateDatabaseName;
    }

    private function cloneTemplateDatabase(PDO $pdo, string $templateDatabaseName, string $dbName): void
    {
        $quotedTemplate = self::quoteIdentifier($templateDatabaseName);
        $quotedTarget = self::quoteIdentifier($dbName);

        self::trackTemporaryDatabase($dbName);
        self::dropTemporaryDatabase($pdo, $dbName);
        $pdo->exec("CREATE DATABASE `{$quotedTarget}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $tables = $pdo->query(
            "SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = " . $pdo->quote($templateDatabaseName) . "
               AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($tables as $table) {
                $quotedTable = self::quoteIdentifier((string) $table);
                $pdo->exec("CREATE TABLE `{$quotedTarget}`.`{$quotedTable}` LIKE `{$quotedTemplate}`.`{$quotedTable}`");
                $pdo->exec("INSERT INTO `{$quotedTarget}`.`{$quotedTable}` SELECT * FROM `{$quotedTemplate}`.`{$quotedTable}`");
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function dropTestDatabase(): void
    {
        if ($this->testDatabaseName === null) {
            return;
        }

        Session::destroy();
        WorkspaceContext::clear();

        $dbHost = getenv('DB_HOST') !== false ? (string) getenv('DB_HOST') : ($_ENV['DB_HOST'] ?? 'localhost');
        $dbUser = getenv('DB_USER') !== false ? (string) getenv('DB_USER') : ($_ENV['DB_USER'] ?? 'root');
        $dbPass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : ($_ENV['DB_PASS'] ?? '');

        Database::close();

        try {
            $pdo = $this->createAdminConnection($dbHost, $dbUser, $dbPass);
            self::dropTemporaryDatabase($pdo, $this->testDatabaseName);
        } catch (\Throwable $e) {
            // Ignore teardown failures so test results are not masked.
        }
    }

    private function createAdminConnection(string $host, string $user, string $pass): PDO
    {
        return self::createAdminPdo($host, $user, $pass);
    }

    private static function createAdminPdo(string $host, string $user, string $pass): PDO
    {
        return new PDO(
            "mysql:host={$host};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]
        );
    }

    private static function registerDatabaseCleanup(): void
    {
        if (self::$cleanupRegistered) {
            return;
        }

        register_shutdown_function([self::class, 'cleanupCreatedDatabasesAtShutdown']);
        self::$cleanupRegistered = true;
    }

    private static function trackTemporaryDatabase(string $databaseName): void
    {
        if (!self::isTemporaryDatabaseName($databaseName)) {
            return;
        }

        self::$createdDatabaseNames[$databaseName] = true;
    }

    public static function cleanupCreatedDatabasesAtShutdown(): void
    {
        if (self::$createdDatabaseNames === []) {
            return;
        }

        $dbHost = getenv('DB_HOST') !== false ? (string) getenv('DB_HOST') : ($_ENV['DB_HOST'] ?? 'localhost');
        $dbUser = getenv('DB_USER') !== false ? (string) getenv('DB_USER') : ($_ENV['DB_USER'] ?? 'root');
        $dbPass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : ($_ENV['DB_PASS'] ?? '');

        Database::close();

        try {
            $pdo = self::createAdminPdo($dbHost, $dbUser, $dbPass);
        } catch (\Throwable $e) {
            return;
        }

        foreach (array_reverse(array_keys(self::$createdDatabaseNames)) as $databaseName) {
            try {
                self::dropTemporaryDatabase($pdo, $databaseName);
            } catch (\Throwable $e) {
                // Best-effort shutdown cleanup only.
            }
        }
    }

    private function executeMigrationStatement(PDO $pdo, string $statement): void
    {
        $statement = \CRM\MigrationStatementNormalizer::normalize($pdo, $statement);
        if (trim($statement) === '') {
            return;
        }

        $stmt = $pdo->prepare($statement);
        $stmt->execute();

        do {
            if ($stmt->columnCount() > 0) {
                $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } while ($stmt->nextRowset());

        $stmt->closeCursor();
    }

    private function generateTestDatabaseName(): string
    {
        $token = implode(':', [
            static::class,
            $this->name(),
            (string) getmypid(),
            (string) ($_ENV['TEST_TOKEN'] ?? ''),
            (string) ($_ENV['PARATEST'] ?? ''),
        ]);

        return 'crm_test_' . substr(hash('sha256', $token), 0, 16);
    }

    private function generateTemplateDatabaseName(): string
    {
        $migrationFiles = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
        natsort($migrationFiles);
        $signatureParts = [
            __DIR__,
            (string) ($_ENV['TEST_TOKEN'] ?? ''),
        ];
        foreach ($migrationFiles as $file) {
            $signatureParts[] = basename($file) . ':' . (string) filemtime($file) . ':' . (string) filesize($file);
        }

        return 'crm_test_template_' . substr(hash('sha256', implode('|', $signatureParts)), 0, 16);
    }

    private function templateDatabaseMatches(PDO $pdo, string $databaseName): bool
    {
        if (!self::isTemporaryDatabaseName($databaseName)) {
            return false;
        }

        $exists = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.SCHEMATA
             WHERE SCHEMA_NAME = " . $pdo->quote($databaseName)
        )->fetchColumn() > 0;
        if (!$exists) {
            return false;
        }

        $migrationFiles = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
        natsort($migrationFiles);
        $expected = array_map('basename', $migrationFiles);
        if ($expected === []) {
            return false;
        }

        try {
            $rows = $pdo->query(
                "SELECT migration_name
                 FROM `" . self::quoteIdentifier($databaseName) . "`.migrations
                 ORDER BY migration_name"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return false;
        }

        sort($expected);
        sort($rows);
        return $expected === array_values(array_map('strval', $rows));
    }

    private static function dropTemporaryDatabase(PDO $pdo, string $databaseName): void
    {
        if (!self::isTemporaryDatabaseName($databaseName)) {
            throw new \InvalidArgumentException('Refusing to drop a non-test database.');
        }

        $pdo->exec('DROP DATABASE IF EXISTS `' . self::quoteIdentifier($databaseName) . '`');
    }

    private static function isTemporaryDatabaseName(string $databaseName): bool
    {
        return preg_match('/^crm_test_(?:template_)?[A-Fa-f0-9]{16}$/', $databaseName) === 1;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new \InvalidArgumentException('Invalid database identifier.');
        }

        return $identifier;
    }

    /**
     * @return array{host:string,name:string,user:string,pass:string,charset:string}
     */
    protected function currentTestDatabaseConfig(): array
    {
        return [
            'host' => getenv('DB_HOST') !== false ? (string) getenv('DB_HOST') : ($_ENV['DB_HOST'] ?? 'localhost'),
            'name' => (string) ($this->testDatabaseName ?? $_ENV['DB_NAME'] ?? 'crm_test'),
            'user' => getenv('DB_USER') !== false ? (string) getenv('DB_USER') : ($_ENV['DB_USER'] ?? 'root'),
            'pass' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : ($_ENV['DB_PASS'] ?? ''),
            'charset' => getenv('DB_CHARSET') !== false ? (string) getenv('DB_CHARSET') : ($_ENV['DB_CHARSET'] ?? 'utf8mb4'),
        ];
    }

    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $inSingleQuote = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];

            if ($inSingleQuote) {
                $current .= $char;
                if ($char === "'") {
                    if ($i + 1 < $len && $sql[$i + 1] === "'") {
                        $current .= "'";
                        $i++;
                    } else {
                        $inSingleQuote = false;
                    }
                }
                continue;
            }

            if ($char === "'") {
                $current .= $char;
                $inSingleQuote = true;
                continue;
            }

            if ($char === ';') {
                $statement = $this->cleanSqlStatement($current);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $statement = $this->cleanSqlStatement($current);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    private function cleanSqlStatement(string $statement): string
    {
        $statement = trim($statement);
        if ($statement === '') {
            return '';
        }

        $cleaned = [];
        foreach (preg_split('/\R/', $statement) as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0) {
                continue;
            }
            $cleaned[] = $line;
        }

        return trim(implode("\n", $cleaned));
    }
}
