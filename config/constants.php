<?php
/**
 * Application Constants
 */

require_once __DIR__ . '/../includes/helpers.php';

// Global application timezone bootstrap.
// Default is Africa/Nairobi unless APP_TIMEZONE is explicitly configured.
if (!function_exists('crmNormalizeTimezone')) {
    function crmNormalizeTimezone(string $tz): string
    {
        $v = trim($tz);
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            $v = trim($v, "\"'");
        }
        return $v;
    }
}
if (!defined('CRM_TIMEZONE_BOOTSTRAPPED')) {
    define('CRM_TIMEZONE_BOOTSTRAPPED', true);
    $appTimezone = crmNormalizeTimezone((string) ($_ENV['APP_TIMEZONE'] ?? 'Africa/Nairobi'));
    if ($appTimezone === '') {
        $appTimezone = 'Africa/Nairobi';
    }
    try {
        date_default_timezone_set($appTimezone);
    } catch (\Throwable $e) {
        date_default_timezone_set('Africa/Nairobi');
    }
}

if (!function_exists('crmEnvBool')) {
    function crmEnvBool(string $key, bool $default = false): bool
    {
        $value = getenv($key);
        if ($value === false && array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        }
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}

if (!function_exists('crmAppEnv')) {
    function crmAppEnv(): string
    {
        $env = getenv('APP_ENV');
        if ($env === false) {
            $env = $_ENV['APP_ENV'] ?? 'production';
        }
        $env = strtolower(trim((string) $env));
        return $env !== '' ? $env : 'production';
    }
}

if (!function_exists('crmPublicDebugEnabled')) {
    function crmPublicDebugEnabled(): bool
    {
        if (crmAppEnv() === 'production') {
            return false;
        }
        return crmEnvBool('APP_DEBUG') || crmEnvBool('DEBUG');
    }
}

if (!function_exists('crmPublicDiagnosticsEnabled')) {
    function crmPublicDiagnosticsEnabled(): bool
    {
        return crmAppEnv() === 'development' || crmEnvBool('ENABLE_PUBLIC_DIAGNOSTICS');
    }
}

define('APP_NAME', $_ENV['APP_NAME'] ?? 'Clarity CRM');
define('APP_VERSION', '1.0.0');

// GDPR Configuration
define('PRIVACY_CONTACT_EMAIL', $_ENV['PRIVACY_CONTACT_EMAIL'] ?? 'privacy@example.com');
define('COMPANY_NAME', $_ENV['COMPANY_NAME'] ?? 'Clarity CRM');
define('ROOT_PATH', dirname(__DIR__));
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('VIEWS_PATH', ROOT_PATH . '/views');
define('CACHE_PATH', ROOT_PATH . '/cache');

if (!defined('CRM_WEB_EXCEPTION_HANDLER_REGISTERED')) {
    define('CRM_WEB_EXCEPTION_HANDLER_REGISTERED', true);
    $handlerFile = ROOT_PATH . '/core/WebExceptionHandler.php';
    if (file_exists($handlerFile)) {
        require_once $handlerFile;
        if (class_exists('\CRM\WebExceptionHandler')) {
            \CRM\WebExceptionHandler::register();
        }
    }
}

// Brand and voice defaults for repositioning from CRM language.
if (!function_exists('brandProductName')) {
    function brandProductName(): string
    {
        $name = trim((string) ($_ENV['BRAND_PRODUCT_NAME'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $appName = trim((string) ($_ENV['APP_NAME'] ?? ''));
        return $appName !== '' ? $appName : 'Clarity CRM';
    }
}

if (!function_exists('brandAssistantName')) {
    function brandAssistantName(): string
    {
        $name = trim((string) ($_ENV['BRAND_ASSISTANT_NAME'] ?? ''));
        return $name !== '' ? $name : 'Clarity';
    }
}

if (!function_exists('brandTaglinePrimary')) {
    function brandTaglinePrimary(): string
    {
        return trim((string) ($_ENV['BRAND_TAGLINE_PRIMARY'] ?? 'Manage follow-up with clarity. Scale with confidence.'));
    }
}

if (!function_exists('brandTaglineSecondary')) {
    function brandTaglineSecondary(): string
    {
        return trim((string) ($_ENV['BRAND_TAGLINE_SECONDARY'] ?? 'Your AI-assisted CRM for WhatsApp, email, tasks, and pipeline.'));
    }
}

if (!function_exists('brandPositioningLine')) {
    function brandPositioningLine(): string
    {
        return trim((string) ($_ENV['BRAND_POSITIONING_LINE'] ?? 'The execution CRM for conversation-led sales teams.'));
    }
}

if (!function_exists('brandValueProp')) {
    function brandValueProp(): string
    {
        return trim((string) ($_ENV['BRAND_VALUE_PROP'] ?? 'A workflow system that keeps follow-up, ownership, and pipeline movement visible.'));
    }
}

if (!function_exists('brandOutcomeLine')) {
    function brandOutcomeLine(): string
    {
        return trim((string) ($_ENV['BRAND_OUTCOME_LINE'] ?? 'Keep customer conversations, next actions, and pipeline movement in one place.'));
    }
}

// User Roles
define('ROLE_ADMIN', 'admin');
define('ROLE_EXPERT', 'expert');
define('ROLE_SALES', 'sales');
define('ROLE_MARKETING', 'marketing');
define('ROLE_VIEWER', 'viewer');

// Contact Stages
define('STAGE_NEW', 'new');
define('STAGE_CONTACTED', 'contacted');
define('STAGE_QUALIFIED', 'qualified');
define('STAGE_PROPOSAL', 'proposal');
define('STAGE_NEGOTIATION', 'negotiation');
define('STAGE_WON', 'won');
define('STAGE_LOST', 'lost');

// Activity Types
define('ACTIVITY_EMAIL', 'email');
define('ACTIVITY_CALL', 'call');
define('ACTIVITY_NOTE', 'note');
define('ACTIVITY_MEETING', 'meeting');
define('ACTIVITY_STATUS_CHANGE', 'status_change');
define('ACTIVITY_FORM_SUBMIT', 'form_submit');

/**
 * Get base path for redirects (works for both localhost and production)
 * 
 * @return string Base path (e.g., '/crm/public', '/public', or '')
 */
if (!function_exists('getBasePath')) {
    function normalizePathPrefix(string $path): string {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        while (strpos($path, '/public/public') !== false) {
            $path = str_replace('/public/public', '/public', $path);
        }
        return rtrim($path, '/');
    }

    function getBasePath(): string {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $httpHost = $_SERVER['HTTP_HOST'] ?? '';

        // An explicit base path is the deployment contract. This keeps cloned
        // subdirectory installs, virtual hosts, and production deployments
        // independent from the repository's folder name.
        $configuredBasePath = normalizePathPrefix((string) ($_ENV['BASE_PATH'] ?? ''));
        if ($configuredBasePath !== '') {
            return $configuredBasePath;
        }

        $appUrlPath = parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH);
        $appUrlPath = is_string($appUrlPath) ? normalizePathPrefix($appUrlPath) : '';
        
        // Detect if we're on localhost
        $isLocalhost = strpos($httpHost, 'localhost') !== false 
                    || strpos($httpHost, '127.0.0.1') !== false
                    || strpos($httpHost, '::1') !== false;
        
        // Derive any localhost subdirectory through its public web root instead
        // of assuming the checkout is always named "crm".
        if ($isLocalhost) {
            foreach ([$scriptName, $requestUri] as $candidate) {
                $candidatePath = parse_url((string) $candidate, PHP_URL_PATH);
                $candidatePath = is_string($candidatePath) ? normalizePathPrefix($candidatePath) : '';
                if ($candidatePath !== '' && preg_match('#^(.*?/public)(?:/|$)#', $candidatePath, $matches) === 1) {
                    return normalizePathPrefix((string) $matches[1]);
                }
            }

            return $appUrlPath;
        }
        
        // Production should not depend on a /crm folder. Prefer root URLs.
        // If APP_URL has a path segment (e.g. https://domain.com/subapp), honor it.
        if ($appUrlPath !== '' && $appUrlPath !== '/public') {
            return $appUrlPath;
        }

        // SiteGround-style deployment: web root is public_html and requests are rewritten
        // internally to /public/*. Public URLs should stay rooted at "/".
        return '';
    }
}

/**
 * Get API base path (e.g., '/crm' or '') from current base path.
 */
if (!function_exists('getApiBasePath')) {
    function getApiBasePath(): string {
        $base = normalizePathPrefix(getBasePath());
        if ($base === '' || $base === '/public') {
            return '';
        }
        return rtrim(str_replace('/public', '', $base), '/');
    }
}

/**
 * Build a URL under the public base path.
 */
if (!function_exists('publicUrl')) {
    function publicUrl(string $path): string {
        $path = '/' . ltrim($path, '/');
        $url = rtrim(normalizePathPrefix(getBasePath()), '/') . $path;
        if ($url === '' || $url[0] !== '/') {
            $url = '/' . ltrim($url, '/');
        }
        return preg_replace('#/+#', '/', $url) ?? $url;
    }
}

/**
 * Build a URL under the API base path.
 */
if (!function_exists('apiUrl')) {
    function apiUrl(string $path): string {
        $path = '/api/' . ltrim($path, '/');
        $url = rtrim(normalizePathPrefix(getApiBasePath()), '/') . $path;
        if ($url === '' || $url[0] !== '/') {
            $url = '/' . ltrim($url, '/');
        }
        return preg_replace('#/+#', '/', $url) ?? $url;
    }
}

/**
 * Build a URL under public assets.
 */
if (!function_exists('assetUrl')) {
    function assetUrl(string $path): string {
        $path = '/assets/' . ltrim($path, '/');
        $url = rtrim(normalizePathPrefix(getBasePath()), '/') . $path;
        if ($url === '' || $url[0] !== '/') {
            $url = '/' . ltrim($url, '/');
        }
        return preg_replace('#/+#', '/', $url) ?? $url;
    }
}

/**
 * Normalize legacy hardcoded /crm/public URLs in rendered HTML output.
 *
 * This provides a centralized compatibility pass so legacy templates that still
 * contain absolute "/crm/public/..." links work correctly in production setups
 * where URLs are rooted differently.
 */
if (!function_exists('normalizeLegacyPublicUrls')) {
    function normalizeLegacyPublicUrls(string $buffer): string
    {
        $basePath = rtrim(getBasePath(), '/');
        if ($basePath === '/crm/public') {
            return $buffer;
        }

        $publicPrefix = ($basePath === '') ? '/' : ($basePath . '/');

        // Fix links/scripts/actions with hardcoded /crm/public prefix.
        $buffer = str_replace('"/crm/public/', '"' . $publicPrefix, $buffer);
        $buffer = str_replace("'/crm/public/", "'" . $publicPrefix, $buffer);
        $buffer = str_replace('(/crm/public/', '(' . $publicPrefix, $buffer);
        $buffer = str_replace('=/crm/public/', '=' . $publicPrefix, $buffer);

        // Safety: collapse malformed filesystem-style URL fragments if emitted.
        $buffer = preg_replace(
            '#/public/home/customer/www/[^/]+/public_html/public/#',
            '/',
            $buffer
        ) ?? $buffer;

        return $buffer;
    }
}

if (!defined('CRM_PUBLIC_URL_NORMALIZER_ENABLED')) {
    define('CRM_PUBLIC_URL_NORMALIZER_ENABLED', true);

    $isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $isApiRequest = strpos($requestUri, '/api/') === 0 || strpos($scriptName, '/api/') !== false;
    $isPublicPage = strpos($scriptName, '/public/') !== false;

    // Only normalize HTML pages, never API or CLI output.
    if (!$isCli && !$isApiRequest && $isPublicPage) {
        ob_start('normalizeLegacyPublicUrls');
    }
}
