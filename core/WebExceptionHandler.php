<?php

namespace CRM;

use CRM\Services\SystemReadinessService;
use Throwable;

class WebExceptionHandler
{
    public static function register(): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return;
        }

        set_exception_handler([self::class, 'handle']);
    }

    public static function handle(Throwable $throwable): void
    {
        if ($throwable instanceof DatabaseConnectionException) {
            self::clearOutputBuffers();
            http_response_code(503);
            header('Content-Type: text/html; charset=utf-8');
            echo self::renderDatabaseSetupRequiredPage($throwable);
            return;
        }

        error_log('Unhandled application error: ' . $throwable->getMessage());
        self::clearOutputBuffers();
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo self::renderGenericErrorPage();
    }

    public static function renderDatabaseSetupRequiredPage(DatabaseConnectionException $exception): string
    {
        $readiness = self::safeReadiness();
        $checks = (array) ($readiness['checks'] ?? []);
        $safeDetails = $exception->getSafeDetails();
        $database = htmlspecialchars((string) ($safeDetails['database'] ?? 'crm_db'), ENT_QUOTES, 'UTF-8');
        $host = htmlspecialchars((string) ($safeDetails['host'] ?? 'localhost'), ENT_QUOTES, 'UTF-8');
        $nextStep = self::nextStep($exception);

        $rows = '';
        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }
            $status = htmlspecialchars((string) ($check['status'] ?? 'unknown'), ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string) ($check['label'] ?? $check['key'] ?? 'Check'), ENT_QUOTES, 'UTF-8');
            $message = htmlspecialchars((string) ($check['message'] ?? ''), ENT_QUOTES, 'UTF-8');
            $rows .= '<tr><td>' . $label . '</td><td><span class="status status-' . $status . '">' . strtoupper($status) . '</span></td><td>' . $message . '</td></tr>';
        }

        return '<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System setup required</title>
    <style>
        :root { color-scheme: light; --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; --panel:#fff; --bg:#f8fafc; --danger:#b91c1c; --warn:#a16207; --ok:#047857; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:Arial, Helvetica, sans-serif; background:var(--bg); color:var(--ink); line-height:1.5; }
        main { max-width:980px; margin:0 auto; padding:48px 20px; }
        .panel { background:var(--panel); border:1px solid var(--line); border-radius:8px; padding:28px; box-shadow:0 14px 35px rgba(15,23,42,.08); }
        h1 { margin:0 0 8px; font-size:30px; letter-spacing:0; }
        p { margin:0 0 16px; color:var(--muted); }
        .callout { border-left:4px solid var(--danger); background:#fef2f2; padding:14px 16px; margin:22px 0; color:#7f1d1d; }
        .meta { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin:20px 0; }
        .meta div { border:1px solid var(--line); border-radius:8px; padding:12px; background:#f8fafc; }
        .meta strong { display:block; font-size:12px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
        table { width:100%; border-collapse:collapse; margin-top:18px; font-size:14px; }
        th, td { text-align:left; border-bottom:1px solid var(--line); padding:12px 10px; vertical-align:top; }
        th { color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
        .status { display:inline-block; min-width:78px; text-align:center; border-radius:999px; padding:3px 8px; font-size:12px; font-weight:700; }
        .status-ok { background:#dcfce7; color:var(--ok); }
        .status-warning, .status-unknown { background:#fef3c7; color:var(--warn); }
        .status-critical { background:#fee2e2; color:var(--danger); }
        .actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:22px; }
        a { color:#1d4ed8; text-decoration:none; font-weight:700; }
        .button { display:inline-block; border:1px solid #1d4ed8; border-radius:6px; padding:10px 14px; background:#1d4ed8; color:#fff; }
        @media (max-width: 700px) { .meta { grid-template-columns:1fr; } table { display:block; overflow-x:auto; } }
    </style>
</head>
<body>
<main>
    <section class="panel" aria-labelledby="setup-title">
        <h1 id="setup-title">System setup required</h1>
        <p>The CRM is running, but it cannot connect to the configured database yet.</p>
        <div class="callout"><strong>' . htmlspecialchars($exception->getUserMessage(), ENT_QUOTES, 'UTF-8') . '</strong><br>' . htmlspecialchars($nextStep, ENT_QUOTES, 'UTF-8') . '</div>
        <div class="meta">
            <div><strong>Configured host</strong>' . $host . '</div>
            <div><strong>Configured database</strong>' . $database . '</div>
        </div>
        <table>
            <thead><tr><th>Check</th><th>Status</th><th>Message</th></tr></thead>
            <tbody>' . $rows . '</tbody>
        </table>
        <div class="actions">
            <a class="button" href="system_health.php">Open system health</a>
            <a href="login.php">Return to login</a>
        </div>
    </section>
</main>
</body>
</html>';
    }

    public static function renderGenericErrorPage(): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Application error</title></head><body style="font-family:Arial,Helvetica,sans-serif;background:#f8fafc;color:#0f172a;margin:0;"><main style="max-width:760px;margin:0 auto;padding:48px 20px;"><section style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:28px;"><h1 style="margin-top:0;">Application error</h1><p style="color:#64748b;">Something went wrong while loading this page. Check the server logs for details.</p></section></main></body></html>';
    }

    private static function nextStep(DatabaseConnectionException $exception): string
    {
        return match ($exception->getCategory()) {
            'missing_database' => 'Create the configured database in MySQL or update DB_NAME in .env to the existing CRM database, then run the migrations if needed.',
            'access_denied' => 'Check DB_USER and DB_PASS in .env, then reload the page.',
            'server_unreachable' => 'Start MySQL in XAMPP or update DB_HOST in .env if MySQL runs elsewhere.',
            'configuration_missing' => 'Set DB_HOST, DB_NAME, DB_USER, and DB_PASS in .env.',
            default => 'Review database settings in .env and confirm MySQL is available.',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private static function safeReadiness(): array
    {
        try {
            if (!class_exists(SystemReadinessService::class)) {
                $serviceFile = dirname(__DIR__) . '/services/SystemReadinessService.php';
                if (file_exists($serviceFile)) {
                    require_once $serviceFile;
                }
            }
            return (new SystemReadinessService())->check();
        } catch (Throwable $e) {
            return [
                'status' => 'critical',
                'checks' => [
                    [
                        'key' => 'readiness',
                        'label' => 'Readiness Diagnostics',
                        'status' => 'critical',
                        'message' => 'Readiness diagnostics could not run.',
                    ],
                ],
            ];
        }
    }

    private static function clearOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
