#!/bin/bash
# Production deployment helper.
# Run from the CRM application root after uploading the release bundle.

set -euo pipefail

echo "=========================================="
echo "CRM Production Deployment"
echo "=========================================="
echo ""

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

fail() {
    echo -e "${RED}$1${NC}"
    exit 1
}

if [ ! -f composer.json ] || [ ! -d public ] || [ ! -d database/migrations ]; then
    fail "Run this script from the CRM application root."
fi

if [ ! -f .env ]; then
    echo -e "${YELLOW}.env is missing.${NC}"
    echo "Create .env on the server from env.production.template and fill live secrets before deploying."
    exit 1
fi

echo "Installing production dependencies..."
composer install --no-dev --optimize-autoloader
echo -e "${GREEN}Dependencies installed.${NC}"

echo ""
echo "Preparing runtime paths..."
composer run runtime:prepare
echo -e "${GREEN}Runtime paths ready.${NC}"

echo ""
echo "Applying baseline permissions..."
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod 775 cache logs tmp uploads
find cache logs tmp uploads -type d -exec chmod 775 {} \;
chmod 600 .env
echo -e "${GREEN}Permissions set.${NC}"

echo ""
echo "Checking fresh backup and restore readiness before migrations..."
composer run backup:check -- --json
echo -e "${GREEN}Backup and restore readiness passed.${NC}"

echo ""
echo "Running database migrations..."
composer run migrate
echo -e "${GREEN}Migrations completed.${NC}"

echo ""
echo "Running production readiness gates..."
composer run templates:validate -- --json
composer run security:audit -- --json
composer run integrations:check -- --json
composer run demo:quarantine -- --json
composer run backup:check -- --json
composer run automation:detectors -- --dry-run --json
composer run preflight:production -- --strict
echo -e "${GREEN}Strict production preflight passed.${NC}"

echo ""
echo "Checking database connection..."
php -r "
require 'vendor/autoload.php';
require 'config/constants.php';
\$config = require 'config/database.php';
\$pdo = new PDO(
    'mysql:host=' . \$config['host'] . ';dbname=' . \$config['name'],
    \$config['user'],
    \$config['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
\$pdo->query('SELECT 1');
echo \"Database connection successful\n\";
"

APP_URL_VALUE="$(php -r "
\$path = '.env';
\$url = '';
foreach (file(\$path, FILE_IGNORE_NEW_LINES) ?: [] as \$line) {
    \$line = trim(\$line);
    if (\$line === '' || \$line[0] === '#' || strpos(\$line, '=') === false) {
        continue;
    }
    [\$key, \$value] = array_map('trim', explode('=', \$line, 2));
    if (\$key === 'APP_URL') {
        \$url = trim(\$value, \"\\\"'\");
        break;
    }
}
echo rtrim(\$url, '/');
")"

if [ -n "$APP_URL_VALUE" ] && command -v curl >/dev/null 2>&1; then
    echo ""
    echo "Running HTTP smoke probe..."
    curl -fsS "$APP_URL_VALUE/api/health.php" >/dev/null
    echo -e "${GREEN}HTTP health endpoint responded.${NC}"
fi

echo ""
echo "=========================================="
echo -e "${GREEN}Deployment gates complete.${NC}"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. Run the browser smoke test from docs/live-upload-checklist.md."
echo "2. Confirm System Health has no critical checks."
echo "3. Record release commit, backup artifacts, migration result, preflight output, and smoke result."
