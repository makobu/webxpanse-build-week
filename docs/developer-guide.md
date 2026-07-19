# Developer Guide

Complete guide for developers working on the WebXpanse business operating platform.

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Code Structure](#code-structure)
3. [Module Development](#module-development)
4. [Service Integration](#service-integration)
5. [Database Schema](#database-schema)
6. [API Development](#api-development)
7. [Testing](#testing)
8. [Best Practices](#best-practices)

---

## Architecture Overview

### System Architecture

The CRM follows a modular, MVC-inspired architecture:

```
┌─────────────────────────────────────────┐
│           Public Interface              │
│  (public/*.php, views/*.php)            │
└──────────────┬──────────────────────────┘
               │
┌──────────────▼──────────────────────────┐
│         Business Logic Layer            │
│         (modules/*.php)                 │
└──────────────┬──────────────────────────┘
               │
┌──────────────▼──────────────────────────┐
│         Service Layer                   │
│         (services/*.php)                 │
└──────────────┬──────────────────────────┘
               │
┌──────────────▼──────────────────────────┐
│         Core Framework                  │
│         (core/*.php)                     │
└──────────────┬──────────────────────────┘
               │
┌──────────────▼──────────────────────────┐
│         Database Layer                   │
│         (MySQL)                          │
└─────────────────────────────────────────┘
```

### Design Patterns

- **Front Controller Pattern** - All requests go through `public/index.php`
- **Module Pattern** - Business logic organized in modules
- **Service Layer** - External integrations in services
- **Repository Pattern** - Data access abstraction (where used)

---

## Code Structure

### Directory Organization

```
crm/
├── api/                    # API endpoints
│   ├── contacts.php        # Contacts API
│   ├── activities.php      # Activities API
│   └── ...
├── cli/                    # CLI scripts and workers
│   ├── email_worker.php   # Email queue worker
│   └── ...
├── config/                 # Configuration files
│   ├── constants.php       # Application constants
│   ├── database.php        # Database configuration
│   └── security.php        # Security settings
├── core/                   # Core framework classes
│   ├── Database.php        # Database abstraction
│   ├── Auth.php            # Authentication
│   ├── Security.php        # Security utilities
│   └── ...
├── database/               # Database migrations
│   └── migrations/          # SQL migration files
├── docs/                   # Documentation
├── modules/                # Business logic modules
│   ├── Contacts.php        # Contact management
│   ├── Activities.php      # Activity management
│   └── ...
├── public/                 # Public web directory
│   ├── index.php           # Front controller
│   ├── dashboard.php       # Dashboard page
│   └── assets/             # CSS, JS, images
├── services/               # External service integrations
│   ├── EmailService.php    # Email service
│   ├── WhatsAppService.php # WhatsApp service
│   └── ...
├── tests/                  # Test suite
│   ├── Unit/               # Unit tests
│   └── Integration/        # Integration tests
├── uploads/                 # User uploaded files
├── vendor/                 # Composer dependencies
└── views/                  # PHP templates
    ├── layouts/            # Layout templates
    └── components/         # Reusable components
```

### Naming Conventions

- **Classes**: PascalCase (e.g., `Contacts`, `EmailService`)
- **Files**: Match class names (e.g., `Contacts.php`)
- **Methods**: camelCase (e.g., `getById()`, `createContact()`)
- **Variables**: camelCase (e.g., `$contactId`, `$emailAddress`)
- **Constants**: UPPER_SNAKE_CASE (e.g., `MAX_FILE_SIZE`)
- **Database tables**: snake_case (e.g., `email_templates`)

---

## Module Development

### Creating a New Module

1. **Create the module file** in `modules/`:

```php
<?php
/**
 * MyModule - Description
 * 
 * Handles [module functionality]
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;

class MyModule
{
    /**
     * Create a new record
     */
    public function create(array $data): int
    {
        // Validate required fields
        if (empty($data['name'])) {
            throw new \Exception("Name is required");
        }
        
        // Sanitize inputs
        $name = Security::sanitizeInput($data['name'], 'string');
        
        // Insert into database
        Database::execute(
            "INSERT INTO my_table (name, created_at) VALUES (?, NOW())",
            [$name]
        );
        
        return (int) Database::lastInsertId();
    }
    
    /**
     * Get record by ID
     */
    public function getById(int $id): ?array
    {
        return Database::queryOne(
            "SELECT * FROM my_table WHERE id = ?",
            [$id]
        );
    }
    
    /**
     * Update record
     */
    public function update(int $id, array $data): bool
    {
        // Implementation
    }
    
    /**
     * Delete record
     */
    public function delete(int $id): bool
    {
        // Implementation
    }
}
```

2. **Create database migration** in `database/migrations/`:

```sql
-- Create my_table
CREATE TABLE IF NOT EXISTS my_table (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

3. **Create public interface** in `public/`:

```php
<?php
/**
 * My Module Page
 */

require_once __DIR__ . '/../vendor/autoload.php';
// ... initialization code ...

use CRM\Modules\MyModule;

$module = new MyModule();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Process form
}

// Display page
```

4. **Add tests** in `tests/Unit/Modules/`:

```php
<?php

namespace Tests\Unit\Modules;

use PHPUnit\Framework\TestCase;
use CRM\Modules\MyModule;

class MyModuleTest extends TestCase
{
    public function testCreate()
    {
        // Test implementation
    }
}
```

### Module Best Practices

1. **Always sanitize inputs** using `Security::sanitizeInput()`
2. **Validate required fields** before database operations
3. **Use prepared statements** (via `Database::execute()`)
4. **Return appropriate types** (int for IDs, bool for success, array for data)
5. **Throw exceptions** for errors, don't return error codes
6. **Log important actions** using `AuditLogger::log()`

---

## Service Integration

### Creating a Service

Services handle external integrations (email, WhatsApp, AI, etc.):

```php
<?php
/**
 * MyService - External Service Integration
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Config;

class MyService
{
    private string $apiUrl;
    private string $apiKey;
    
    public function __construct()
    {
        $this->apiUrl = $_ENV['MY_SERVICE_URL'] ?? '';
        $this->apiKey = $_ENV['MY_SERVICE_KEY'] ?? '';
    }
    
    /**
     * Call external API
     */
    public function callApi(array $data): array
    {
        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new \Exception("API call failed: HTTP $httpCode");
        }
        
        return json_decode($response, true);
    }
}
```

### Queue Integration

For long-running operations, use the queue system:

```php
use CRM\Services\EmailQueue;

// Add to queue
EmailQueue::add([
    'to' => 'user@example.com',
    'subject' => 'Hello',
    'body' => 'Message'
]);

// Worker processes queue items
```

---

## Database Schema

### Working with Migrations

1. **Create migration file** in `database/migrations/`:
   - Name format: `XXX_description.sql`
   - Use sequential numbers (001, 002, 003...)

2. **Migration structure**:
```sql
-- Description of what this migration does
CREATE TABLE IF NOT EXISTS my_table (
    id INT PRIMARY KEY AUTO_INCREMENT,
    -- columns
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

3. **Run migrations**:
```bash
php database/migrations/migrate.php
```

### Database Best Practices

1. **Always use transactions** for multi-step operations
2. **Add indexes** for frequently queried columns
3. **Use foreign keys** for data integrity
4. **Use prepared statements** to prevent SQL injection
5. **Use appropriate data types** (INT, VARCHAR, TEXT, JSON, etc.)
6. **Add timestamps** (created_at, updated_at)

### Using the Database Class

```php
use CRM\Database;

// Query with parameters
$results = Database::query(
    "SELECT * FROM contacts WHERE stage = ? LIMIT ?",
    ['new', 10]
);

// Single row
$contact = Database::queryOne(
    "SELECT * FROM contacts WHERE id = ?",
    [1]
);

// Execute (INSERT, UPDATE, DELETE)
Database::execute(
    "UPDATE contacts SET stage = ? WHERE id = ?",
    ['contacted', 1]
);

// Get last insert ID
$id = Database::lastInsertId();
```

---

## API Development

### Creating an API Endpoint

1. **Create endpoint file** in `api/`:

```php
<?php
/**
 * MyModule API Endpoint
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../public/index.php';

use CRM\Auth;
use CRM\Modules\MyModule;
use CRM\Security;

header('Content-Type: application/json');

// Require authentication
Auth::requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$module = new MyModule();

try {
    switch ($method) {
        case 'GET':
            $id = $_GET['id'] ?? null;
            if ($id) {
                $result = $module->getById((int) $id);
                echo json_encode($result ?: ['error' => 'Not found'], JSON_PRETTY_PRINT);
            } else {
                $results = $module->getAll();
                echo json_encode(['results' => $results], JSON_PRETTY_PRINT);
            }
            break;
            
        case 'POST':
            $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!Security::validateCSRF($csrfToken)) {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid CSRF token']);
                break;
            }
            
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $result = $module->create($data);
            http_response_code(201);
            echo json_encode($result, JSON_PRETTY_PRINT);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
```

### API Best Practices

1. **Always return JSON** with proper Content-Type header
2. **Use appropriate HTTP status codes**
3. **Validate CSRF tokens** for state-changing operations
4. **Handle errors gracefully** with error messages
5. **Use consistent response format**
6. **Document endpoints** in API documentation

---

## Testing

### Writing Unit Tests

```php
<?php

namespace Tests\Unit\Modules;

use PHPUnit\Framework\TestCase;
use CRM\Modules\MyModule;

class MyModuleTest extends TestCase
{
    private MyModule $module;
    
    protected function setUp(): void
    {
        $this->module = new MyModule();
    }
    
    public function testCreate()
    {
        $result = $this->module->create([
            'name' => 'Test'
        ]);
        
        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);
    }
    
    public function testCreateRequiresName()
    {
        $this->expectException(\Exception::class);
        $this->module->create([]);
    }
}
```

### Writing Integration Tests

```php
<?php

namespace Tests\Integration;

use Tests\DatabaseTestCase;
use CRM\Modules\MyModule;

class MyModuleIntegrationTest extends DatabaseTestCase
{
    public function testCreateAndRetrieve()
    {
        $module = new MyModule();
        
        $id = $module->create(['name' => 'Test']);
        $result = $module->getById($id);
        
        $this->assertNotNull($result);
        $this->assertEquals('Test', $result['name']);
    }
}
```

### Running Tests

```bash
# All tests
composer test

# Unit tests only
vendor/bin/phpunit tests/Unit

# Integration tests only
vendor/bin/phpunit tests/Integration

# Specific test file
vendor/bin/phpunit tests/Unit/Modules/ContactsTest.php
```

---

## Best Practices

### Code Quality

1. **Follow PSR-12 coding standards**
2. **Write self-documenting code** with clear variable names
3. **Add comments** for complex logic
4. **Keep functions small** and focused
5. **Avoid deep nesting** (max 3-4 levels)

### Security

1. **Always sanitize user input** using `Security::sanitizeInput()`
2. **Use prepared statements** for all database queries
3. **Validate CSRF tokens** on state-changing operations
4. **Hash passwords** using `password_hash()`
5. **Log security events** using audit logging

### Performance

1. **Use indexes** on frequently queried columns
2. **Implement pagination** for large datasets
3. **Use caching** for expensive operations
4. **Optimize queries** (avoid N+1 problems)
5. **Lazy load** data when possible

### Error Handling

1. **Throw exceptions** for errors, don't return error codes
2. **Use specific exception types** when appropriate
3. **Log errors** for debugging
4. **Display user-friendly messages** to end users
5. **Never expose sensitive information** in error messages

### Documentation

1. **Document all public methods** with PHPDoc
2. **Include parameter types** and return types
3. **Add examples** for complex functions
4. **Update documentation** when code changes
5. **Keep README updated**

---

## Common Tasks

### Adding a New Field to a Table

1. Create migration:
```sql
ALTER TABLE contacts ADD COLUMN new_field VARCHAR(255) NULL;
```

2. Update module to handle new field
3. Update forms to include new field
4. Update API to return new field

### Adding a New Page

1. Create PHP file in `public/`
2. Include initialization code
3. Handle GET/POST requests
4. Render view using layout
5. Add navigation link

### Adding a New API Endpoint

1. Create endpoint file in `api/`
2. Add authentication check
3. Handle HTTP methods
4. Return JSON responses
5. Document in API docs

---

## Debugging

### Enable Debug Mode

Set in `.env`:
```
APP_DEBUG=true
```

Use this only in local development. Production deployments should keep
`APP_DEBUG=false` and leave the legacy `DEBUG` flag unset.

### View Logs

- Application logs: Check `logs/` directory
- PHP errors: Check PHP error log
- Database queries: Enable query logging

### Common Issues

1. **"Class not found"** - Run `composer dump-autoload`
2. **"Table doesn't exist"** - Run migrations
3. **"CSRF token invalid"** - Check session and token generation
4. **"Permission denied"** - Check file permissions

---

## Resources

- **PHP Documentation**: https://www.php.net/docs.php
- **MySQL Documentation**: https://dev.mysql.com/doc/
- **Composer Documentation**: https://getcomposer.org/doc/
- **PHPUnit Documentation**: https://phpunit.de/documentation.html

---

*Last Updated: 2026-01-24*
