# Contributing Guide

Guidelines for contributing to the WebXpanse business operating platform.

## Getting Started

### Prerequisites

- PHP 8.1+
- MySQL 8.0+
- Composer
- Git
- Basic knowledge of PHP and MySQL

### Development Setup

1. **Fork the repository**

2. **Clone your fork**:
   ```bash
   git clone <your-fork-url>
   cd crm
   ```

3. **Install dependencies**:
   ```bash
   composer install
   ```

4. **Set up environment**:
   ```bash
   cp .env.example .env
   # Edit .env with your local settings
   ```

5. **Run migrations**:
   ```bash
   php database/migrations/migrate.php
   ```

6. **Create test data** (optional):
   ```bash
   php scripts/create_test_admin.php
   ```

---

## Development Workflow

### Branch Naming

Use descriptive branch names:
- `feature/contact-merge`
- `bugfix/email-tracking`
- `docs/api-documentation`
- `refactor/database-layer`

### Commit Messages

Follow conventional commit format:

```
type(scope): subject

body (optional)

footer (optional)
```

**Types**:
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation
- `style`: Code style changes
- `refactor`: Code refactoring
- `test`: Adding tests
- `chore`: Maintenance tasks

**Examples**:
```
feat(contacts): add bulk merge functionality

fix(email): resolve SMTP connection timeout

docs(api): update contacts endpoint documentation
```

### Pull Request Process

1. **Create feature branch** from `main`
2. **Make changes** following coding standards
3. **Write/update tests**
4. **Update documentation**
5. **Test thoroughly**
6. **Create pull request** with:
   - Clear description
   - Related issues
   - Screenshots (if UI changes)
   - Test results

---

## Coding Standards

### PHP Code Style

Follow **PSR-12** coding standards:

```php
<?php
/**
 * Class description
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;

class MyModule
{
    /**
     * Method description
     */
    public function myMethod(string $param): array
    {
        // Implementation
    }
}
```

### Naming Conventions

- **Classes**: PascalCase (`Contacts`, `EmailService`)
- **Methods**: camelCase (`getById()`, `createContact()`)
- **Variables**: camelCase (`$contactId`, `$emailAddress`)
- **Constants**: UPPER_SNAKE_CASE (`MAX_FILE_SIZE`)
- **Database tables**: snake_case (`email_templates`)

### Code Organization

1. **Namespace declarations** at top
2. **Use statements** after namespace
3. **Class declaration**
4. **Properties**
5. **Constructor**
6. **Public methods**
7. **Private/protected methods**

### Documentation

- **PHPDoc** for all public methods
- **Inline comments** for complex logic
- **Type hints** for parameters and return values

Example:
```php
/**
 * Create a new contact
 * 
 * @param array $data Contact data (first_name, email required)
 * @return array Result with status and id
 * @throws Exception If validation fails
 */
public function create(array $data): array
{
    // Implementation
}
```

---

## Testing

### Writing Tests

1. **Unit Tests**: Test individual methods
2. **Integration Tests**: Test module interactions
3. **Coverage**: Aim for 80%+ code coverage

### Test Structure

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
    
    public function testCreateSuccess()
    {
        $result = $this->module->create(['name' => 'Test']);
        $this->assertIsInt($result);
    }
    
    public function testCreateRequiresName()
    {
        $this->expectException(\Exception::class);
        $this->module->create([]);
    }
}
```

### Running Tests

```bash
# All tests
composer test

# Specific test suite
vendor/bin/phpunit tests/Unit
vendor/bin/phpunit tests/Integration

# Specific test file
vendor/bin/phpunit tests/Unit/Modules/ContactsTest.php
```

---

## Adding New Features

### Feature Checklist

- [ ] Create feature branch
- [ ] Write code following standards
- [ ] Add database migration (if needed)
- [ ] Write unit tests
- [ ] Write integration tests
- [ ] Update documentation
- [ ] Test manually
- [ ] Update CHANGELOG
- [ ] Create pull request

### Module Development

See [Developer Guide](developer-guide.md#module-development) for detailed module development instructions.

### API Development

See [Developer Guide](developer-guide.md#api-development) for API endpoint development.

---

## Database Changes

### Creating Migrations

1. **Create migration file** in `database/migrations/`:
   - Format: `XXX_description.sql`
   - Use sequential numbers

2. **Write SQL**:
   ```sql
   -- Add new column to contacts
   ALTER TABLE contacts ADD COLUMN new_field VARCHAR(255) NULL;
   ```

3. **Test migration**:
   ```bash
   php database/migrations/migrate.php
   ```

4. **Rollback plan**: Document how to reverse migration

### Migration Best Practices

- Use `IF NOT EXISTS` / `IF EXISTS` for safety
- Add indexes for new columns
- Use transactions where possible
- Test on sample data first
- Document breaking changes

---

## Documentation

### Updating Documentation

When adding features, update:

1. **User Guide** (`docs/user-guide.md`) - If user-facing
2. **API Documentation** (`docs/api.md`) - If API changes
3. **Developer Guide** (`docs/developer-guide.md`) - If developer-facing
4. **README.md** - If major feature
5. **CHANGELOG.md** - Always

### Documentation Style

- Use clear, concise language
- Include examples
- Add screenshots for UI changes
- Keep table of contents updated

---

## Code Review

### Review Checklist

- [ ] Code follows PSR-12 standards
- [ ] All tests pass
- [ ] Documentation updated
- [ ] No security vulnerabilities
- [ ] Performance considerations addressed
- [ ] Error handling is appropriate
- [ ] Code is maintainable

### Review Process

1. **Automated checks** (if configured)
2. **Peer review** by team members
3. **Address feedback**
4. **Approval** from maintainer
5. **Merge** to main branch

---

## Security

### Security Guidelines

1. **Never commit**:
   - Passwords
   - API keys
   - `.env` files
   - Private keys

2. **Always**:
   - Sanitize user input
   - Use prepared statements
   - Validate CSRF tokens
   - Hash passwords
   - Log security events

3. **Report vulnerabilities** privately

---

## Questions?

- **Documentation**: Check `docs/` directory
- **Issues**: Create an issue in repository
- **Discussions**: Use repository discussions (if available)

---

## License

By contributing, you agree that your contributions will be licensed under the same license as the project.

---

*Last Updated: 2026-01-24*
