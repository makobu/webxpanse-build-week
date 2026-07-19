<?php

namespace CRM\Services;

class MarketingPageQualityMatrix
{
    /**
     * @return array<string,mixed>
     */
    public static function build(?string $publicDir = null): array
    {
        $publicDir = $publicDir ?: dirname(__DIR__) . '/public';
        $internalPages = MarketingUi::internalPageFilenames();
        sort($internalPages);
        $publicOnlyPages = ['marketing_landing_public.php', 'marketing_track.php', 'marketing_unsubscribe.php'];

        $pages = [];
        $counts = [
            'total' => count($internalPages),
            'ready' => 0,
            'warning' => 0,
            'blocked' => 0,
            'public_only' => count($publicOnlyPages),
        ];

        foreach ($internalPages as $page) {
            $checks = self::checkInternalPage($publicDir, $page);
            $status = self::statusFromChecks($checks);
            $counts[$status]++;
            $pages[] = [
                'page' => $page,
                'section' => MarketingUi::pageSection($page),
                'status' => $status,
                'checks' => $checks,
                'issues' => array_values(array_filter($checks, static fn(array $check): bool => $check['status'] !== 'ready')),
            ];
        }

        $publicPages = [];
        foreach ($publicOnlyPages as $page) {
            $checks = self::checkPublicOnlyPage($publicDir, $page);
            $publicPages[] = [
                'page' => $page,
                'status' => self::statusFromChecks($checks),
                'checks' => $checks,
                'issues' => array_values(array_filter($checks, static fn(array $check): bool => $check['status'] !== 'ready')),
            ];
        }

        $missingRegistrations = self::missingInternalRegistrations($publicDir, $internalPages, $publicOnlyPages);
        foreach ($missingRegistrations as $page) {
            $counts['blocked']++;
            $pages[] = [
                'page' => $page,
                'section' => 'unregistered',
                'status' => 'blocked',
                'checks' => [[
                    'key' => 'registered',
                    'label' => 'Registered as internal Marketing page',
                    'status' => 'blocked',
                    'message' => 'The Marketing public page is not included in MarketingUi::internalPageFilenames().',
                ]],
                'issues' => [[
                    'key' => 'registered',
                    'label' => 'Registered as internal Marketing page',
                    'status' => 'blocked',
                    'message' => 'The Marketing public page is not included in MarketingUi::internalPageFilenames().',
                ]],
            ];
        }

        $overallStatus = $counts['blocked'] > 0 ? 'blocked' : ($counts['warning'] > 0 ? 'warning' : 'ready');

        return [
            'status' => $overallStatus,
            'counts' => $counts,
            'pages' => $pages,
            'public_only_pages' => $publicPages,
            'missing_registrations' => $missingRegistrations,
            'guardrails' => [
                'public_landing_chrome' => false,
                'public_tracking_chrome' => false,
                'secret_values_exposed' => false,
                'raw_debug_output_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<int,array{key:string,label:string,status:string,message:string}>
     */
    private static function checkInternalPage(string $publicDir, string $page): array
    {
        $path = rtrim($publicDir, '/\\') . DIRECTORY_SEPARATOR . $page;
        if (!is_file($path)) {
            return [[
                'key' => 'file_exists',
                'label' => 'File exists',
                'status' => 'blocked',
                'message' => 'The internal Marketing page file is missing.',
            ]];
        }

        $source = (string) file_get_contents($path);
        $isPreview = $page === 'marketing_landing_page_preview.php';

        $checks = [
            self::checkContains('auth_guard', 'Authenticated guard', $source, 'Auth::check(', 'blocked', 'Page checks for an authenticated session.'),
            self::checkContains('permission_guard', 'Marketing permission guard', $source, "Authorization::can('marketing.", 'blocked', 'Page checks a Marketing RBAC permission.'),
            self::checkDebugLeakage($source),
            self::checkSecretLeakage($source),
        ];

        if ($isPreview) {
            $checks[] = self::checkContains('preview_chrome', 'Authenticated preview chrome', $source, 'preview-banner', 'warning', 'Preview keeps authenticated-only controls.');
            $checks[] = self::checkContains('preview_shell', 'Landing preview shell', $source, 'landing-preview', 'warning', 'Preview keeps the landing preview shell hook.');
        } else {
            $checks[] = self::checkContains('premium_shell', 'Premium page shell', $source, 'page-premium', 'warning', 'Page uses the authenticated premium operator shell.');
            $checks[] = self::checkContains('page_header', 'Page header hook', $source, 'page-header', 'warning', 'Page exposes the shared operator header hook.');
            $checks[] = self::checkContains('header_actions', 'Header action hook', $source, 'page-header-actions', 'warning', 'Page keeps actions in the shared header action region.');
        }

        return $checks;
    }

    /**
     * @return array<int,array{key:string,label:string,status:string,message:string}>
     */
    private static function checkPublicOnlyPage(string $publicDir, string $page): array
    {
        $path = rtrim($publicDir, '/\\') . DIRECTORY_SEPARATOR . $page;
        if (!is_file($path)) {
            return [[
                'key' => 'file_exists',
                'label' => 'File exists',
                'status' => 'blocked',
                'message' => 'The public Marketing endpoint file is missing.',
            ]];
        }

        $source = (string) file_get_contents($path);

        return [
            [
                'key' => 'not_internal',
                'label' => 'Excluded from authenticated shell',
                'status' => MarketingUi::isInternalPage($page) ? 'blocked' : 'ready',
                'message' => MarketingUi::isInternalPage($page)
                    ? 'Public Marketing endpoint is incorrectly registered as an internal page.'
                    : 'Public Marketing endpoint remains outside authenticated operator chrome.',
            ],
            [
                'key' => 'no_operator_chrome',
                'label' => 'No operator chrome',
                'status' => (str_contains($source, 'marketing-operator-strip') || str_contains($source, 'MarketingUi')) ? 'blocked' : 'ready',
                'message' => 'Public endpoint does not load authenticated Marketing UI chrome.',
            ],
            self::checkDebugLeakage($source),
            self::checkSecretLeakage($source),
        ];
    }

    /**
     * @param string[] $internalPages
     * @param string[] $publicOnlyPages
     * @return string[]
     */
    private static function missingInternalRegistrations(string $publicDir, array $internalPages, array $publicOnlyPages): array
    {
        $paths = glob(rtrim($publicDir, '/\\') . DIRECTORY_SEPARATOR . 'marketing*.php') ?: [];
        $missing = [];
        foreach ($paths as $path) {
            $page = basename($path);
            if (in_array($page, $publicOnlyPages, true)) {
                continue;
            }
            if (!in_array($page, $internalPages, true)) {
                $missing[] = $page;
            }
        }
        sort($missing);

        return $missing;
    }

    /**
     * @param array<int,array{status:string}> $checks
     */
    private static function statusFromChecks(array $checks): string
    {
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === 'blocked') {
                return 'blocked';
            }
        }
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === 'warning') {
                return 'warning';
            }
        }

        return 'ready';
    }

    /**
     * @return array{key:string,label:string,status:string,message:string}
     */
    private static function checkContains(string $key, string $label, string $source, string $needle, string $missingStatus, string $readyMessage): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => str_contains($source, $needle) ? 'ready' : $missingStatus,
            'message' => str_contains($source, $needle) ? $readyMessage : 'Expected page source hook is missing.',
        ];
    }

    /**
     * @return array{key:string,label:string,status:string,message:string}
     */
    private static function checkDebugLeakage(string $source): array
    {
        $debugNeedles = ['var_dump(', 'print_r(', 'debug_backtrace(', 'xdebug_'];
        foreach ($debugNeedles as $needle) {
            if (str_contains($source, $needle)) {
                return [
                    'key' => 'debug_output',
                    'label' => 'No raw debug output',
                    'status' => 'blocked',
                    'message' => 'Page source contains raw debug output marker: ' . $needle,
                ];
            }
        }

        return [
            'key' => 'debug_output',
            'label' => 'No raw debug output',
            'status' => 'ready',
            'message' => 'No raw debug output markers found.',
        ];
    }

    /**
     * @return array{key:string,label:string,status:string,message:string}
     */
    private static function checkSecretLeakage(string $source): array
    {
        $secretPatterns = [
            "echo \$_ENV",
            "print_r(\$_ENV",
            "var_dump(\$_ENV",
            'DB_PASSWORD',
            'MYSQL_PASSWORD',
            'OPENAI_API_KEY',
        ];

        foreach ($secretPatterns as $needle) {
            if (str_contains($source, $needle)) {
                return [
                    'key' => 'secret_output',
                    'label' => 'No secret output',
                    'status' => 'blocked',
                    'message' => 'Page source contains a secret-output risk marker: ' . $needle,
                ];
            }
        }

        return [
            'key' => 'secret_output',
            'label' => 'No secret output',
            'status' => 'ready',
            'message' => 'No obvious secret-output markers found.',
        ];
    }
}
