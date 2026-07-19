<?php

namespace CRM\Services;

class ProductionCleanupVerificationService
{
    /** @var array<int,string>|null */
    private ?array $stagedFilesOverride;
    private string $rootPath;

    public function __construct(
        ?string $rootPath = null,
        private ?DemoQuarantineVerificationService $demoVerifier = null,
        private ?SecurityRoleHardeningAuditService $securityAudit = null,
        ?array $stagedFilesOverride = null
    ) {
        $this->rootPath = rtrim($rootPath ?: dirname(__DIR__), "\\/");
        $this->stagedFilesOverride = $stagedFilesOverride;
    }

    /**
     * @return array<string,mixed>
     */
    public function verify(): array
    {
        $demoReport = ($this->demoVerifier ?? new DemoQuarantineVerificationService())->verify();
        $securityReport = ($this->securityAudit ?? new SecurityRoleHardeningAuditService(null, $this->rootPath))->audit();
        $staged = $this->stagedFiles();
        $findings = [];
        $summary = [
            'default_workspace_demo_rows' => 0,
            'default_workspace_demo_scoped_rows' => 0,
            'default_workspace_demo_marker_rows' => 0,
            'default_workspace_presentation_rows' => 0,
            'default_workspace_demo_session_rows' => 0,
            'default_workspace_demo_entity_rows' => 0,
            'protected_demo_workspace_rows' => 0,
            'non_demo_workspace_scoped_rows' => 0,
            'public_setup_artifacts' => 0,
            'staged_files_checked' => count($staged['files']),
            'staged_generated_artifacts' => 0,
            'git_inventory_available' => $staged['available'],
            'findings' => 0,
            'critical' => 0,
            'warning' => 0,
            'by_rule' => [],
        ];

        $this->summarizeDemoReport($demoReport, $findings, $summary);
        $setupFindings = $this->summarizeSecurityReport($securityReport, $findings, $summary);
        $stagedGenerated = $this->checkStagedGeneratedArtifacts($staged, $findings, $summary);
        $this->checkPublicInstaller($findings, $summary);

        foreach ($findings as $finding) {
            $severity = (string) ($finding['severity'] ?? 'warning');
            if (!isset($summary[$severity])) {
                $severity = 'warning';
            }
            $summary[$severity]++;
            $summary['findings']++;
            $rule = (string) ($finding['rule'] ?? 'unknown');
            $summary['by_rule'][$rule] = (int) ($summary['by_rule'][$rule] ?? 0) + 1;
        }

        return [
            'status' => $summary['critical'] > 0 ? 'critical' : ($summary['warning'] > 0 ? 'warning' : 'ok'),
            'summary' => $summary,
            'sections' => [
                'demo_quarantine' => [
                    'status' => $demoReport['status'] ?? 'unknown',
                    'summary' => $demoReport['summary'] ?? [],
                    'finding_count' => count((array) ($demoReport['findings'] ?? [])),
                ],
                'public_setup_artifacts' => [
                    'count' => count($setupFindings),
                    'findings' => $setupFindings,
                ],
                'staged_generated_artifacts' => [
                    'available' => $staged['available'],
                    'files_checked' => count($staged['files']),
                    'files' => $stagedGenerated,
                ],
            ],
            'findings' => $findings,
            'checked_at' => date('c'),
        ];
    }

    /**
     * @param array<string,mixed> $demoReport
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function summarizeDemoReport(array $demoReport, array &$findings, array &$summary): void
    {
        $demoSummary = (array) ($demoReport['summary'] ?? []);
        $summary['default_workspace_demo_scoped_rows'] = (int) ($demoSummary['default_demo_scoped_rows'] ?? 0);
        $summary['default_workspace_demo_marker_rows'] = (int) ($demoSummary['default_demo_marker_rows'] ?? 0);
        $summary['default_workspace_presentation_rows'] = (int) ($demoSummary['default_presentation_rows'] ?? 0);
        $summary['default_workspace_demo_session_rows'] = (int) ($demoSummary['default_demo_session_rows'] ?? 0);
        $summary['default_workspace_demo_entity_rows'] = (int) ($demoSummary['default_demo_entity_rows'] ?? 0);
        $summary['protected_demo_workspace_rows'] = (int) ($demoSummary['protected_demo_scoped_rows'] ?? 0);
        $summary['non_demo_workspace_scoped_rows'] = (int) ($demoSummary['non_demo_workspace_scoped_rows'] ?? 0);
        $summary['default_workspace_demo_rows'] = $summary['default_workspace_demo_scoped_rows']
            + $summary['default_workspace_demo_marker_rows']
            + $summary['default_workspace_presentation_rows']
            + $summary['default_workspace_demo_session_rows']
            + $summary['default_workspace_demo_entity_rows'];

        $status = (string) ($demoReport['status'] ?? 'unknown');
        if ($status === 'critical') {
            $this->addFinding($findings, 'critical', 'demo_quarantine_not_clean', 'Default workspace demo or presentation contamination is still present.', [
                'target' => 'demo_quarantine',
                'evidence' => $demoSummary,
                'recommendation' => 'Run the demo quarantine report and review/remediate the listed default-workspace rows before live upload.',
            ]);
        } elseif (in_array($status, ['warning', 'unknown'], true)) {
            $this->addFinding($findings, 'warning', 'demo_quarantine_needs_review', 'Demo quarantine verification has warnings or could not fully run.', [
                'target' => 'demo_quarantine',
                'evidence' => $demoSummary,
                'recommendation' => 'Review demo quarantine warnings and keep protected demo data isolated from production workspace records.',
            ]);
        }
    }

    /**
     * @param array<string,mixed> $securityReport
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     * @return array<int,array<string,mixed>>
     */
    private function summarizeSecurityReport(array $securityReport, array &$findings, array &$summary): array
    {
        $setupFindings = [];
        foreach ((array) ($securityReport['findings'] ?? []) as $finding) {
            if (!is_array($finding) || (string) ($finding['rule'] ?? '') !== 'public_setup_or_test_script_present') {
                continue;
            }
            $setupFindings[] = $finding;
        }

        $summary['public_setup_artifacts'] = count($setupFindings);
        if ($setupFindings !== []) {
            $severity = $this->hasCritical($setupFindings) ? 'critical' : 'warning';
            $this->addFinding($findings, $severity, 'public_setup_artifacts_present', 'Public setup, debug, seed, migrate, or test scripts remain reachable by path.', [
                'target' => 'public_setup_artifacts',
                'count' => count($setupFindings),
                'files' => array_values(array_map(
                    static fn(array $row): string => (string) ($row['file'] ?? ''),
                    $setupFindings
                )),
                'recommendation' => 'Remove obsolete scripts or gate required diagnostics behind local-only, Super Admin, authorization, and CSRF checks.',
            ]);
        }

        return $setupFindings;
    }

    /**
     * @param array{available:bool,files:array<int,string>} $staged
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     * @return array<int,string>
     */
    private function checkStagedGeneratedArtifacts(array $staged, array &$findings, array &$summary): array
    {
        if (!$staged['available']) {
            $this->addFinding($findings, 'warning', 'git_staged_inventory_unavailable', 'Git staged-file inventory could not be read.', [
                'target' => 'git',
                'recommendation' => 'Run git status and verify no generated artifacts are staged before committing or uploading.',
            ]);
            return [];
        }

        $generated = [];
        foreach ($staged['files'] as $file) {
            if ($this->isGeneratedArtifactPath($file)) {
                $generated[] = $file;
            }
        }

        $summary['staged_generated_artifacts'] = count($generated);
        if ($generated !== []) {
            $this->addFinding($findings, 'critical', 'staged_generated_artifacts_present', 'Generated/runtime artifacts are staged for commit or upload.', [
                'target' => 'git_staged_artifacts',
                'files' => $generated,
                'recommendation' => 'Unstage generated screenshots, logs, backups, caches, archives, local env files, vendor, node_modules, and upload noise before release.',
            ]);
        }

        return $generated;
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function checkPublicInstaller(array &$findings, array &$summary): void
    {
        if (!is_file($this->rootPath . '/public/install_once.php')) {
            return;
        }

        $summary['public_setup_artifacts'] = max(1, (int) ($summary['public_setup_artifacts'] ?? 0));
        $this->addFinding($findings, 'critical', 'public_install_once_present', 'public/install_once.php is present in the webroot.', [
            'target' => 'public/install_once.php',
            'recommendation' => 'Remove public/install_once.php before live upload.',
        ]);
    }

    private function isGeneratedArtifactPath(string $path): bool
    {
        $normalized = strtolower(str_replace('\\', '/', trim($path)));
        if ($normalized === '') {
            return false;
        }

        foreach ([
            '.env',
            '.env.',
            'backups/',
            'cache/',
            'logs/',
            'tmp/',
            'exports/',
            'output/',
            'screenshots/',
            'tests/screenshots/',
            'playwright-report/',
            'test-results/',
            '.playwright-mcp/',
            'vendor/',
            'node_modules/',
        ] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        if (str_starts_with($normalized, 'uploads/') && !str_starts_with($normalized, 'uploads/test-assets/')) {
            return true;
        }

        foreach (['.log', '.cache', '.bak', '.backup', '.dump', '.sql', '.sql.gz', '.zip', '.tar', '.tar.gz', '.rar'] as $suffix) {
            if (str_ends_with($normalized, $suffix)) {
                return true;
            }
        }

        return (bool) preg_match('/(^|\/)[^\/]+-(smoke|desktop|mobile|fixed|final|content|full|check)\.png$/', $normalized);
    }

    /**
     * @return array{available:bool,files:array<int,string>}
     */
    private function stagedFiles(): array
    {
        if ($this->stagedFilesOverride !== null) {
            return [
                'available' => true,
                'files' => array_values(array_map('strval', $this->stagedFilesOverride)),
            ];
        }

        $command = 'git -C ' . escapeshellarg($this->rootPath) . ' diff --cached --name-only --';
        $output = [];
        $exitCode = 1;
        exec($command, $output, $exitCode);

        return [
            'available' => $exitCode === 0,
            'files' => $exitCode === 0 ? array_values(array_filter(array_map('strval', $output))) : [],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     */
    private function hasCritical(array $findings): bool
    {
        foreach ($findings as $finding) {
            if ((string) ($finding['severity'] ?? '') === 'critical') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $context
     */
    private function addFinding(array &$findings, string $severity, string $rule, string $message, array $context): void
    {
        $findings[] = [
            'severity' => $severity,
            'rule' => $rule,
            'message' => $message,
        ] + $context;
    }
}
