<?php

namespace CRM\Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;

class LiveUploadChecklistTest extends TestCase
{
    private function rootFile(string $relativePath): string
    {
        $path = __DIR__ . '/../../../' . $relativePath;
        $contents = file_get_contents($path);

        $this->assertNotFalse($contents, $relativePath . ' should exist.');

        return str_replace(["\r\n", "\r"], "\n", (string) $contents);
    }

    public function testLiveUploadChecklistDocumentsProductionUploadRunbook(): void
    {
        $checklist = $this->rootFile('docs/live-upload-checklist.md');

        $this->assertStringContainsString('## 2. Upload File List', $checklist);
        $this->assertStringContainsString('## 3. Live `.env` Requirements', $checklist);
        $this->assertStringContainsString('## 4. Runtime Permissions', $checklist);
        $this->assertStringContainsString('## 5. Migration And Gate Order', $checklist);
        $this->assertStringContainsString('## 6. Post-Upload Smoke Test', $checklist);

        $this->assertStringContainsString('composer run runtime:prepare', $checklist);
        $this->assertStringContainsString('composer run backup:check -- --json', $checklist);
        $this->assertStringContainsString('composer run migrate', $checklist);
        $this->assertStringContainsString('composer run templates:validate -- --json', $checklist);
        $this->assertStringContainsString('composer run security:audit -- --json', $checklist);
        $this->assertStringContainsString('composer run integrations:check -- --json', $checklist);
        $this->assertStringContainsString('composer run demo:quarantine -- --json', $checklist);
        $this->assertStringContainsString('composer run automation:detectors -- --dry-run --json', $checklist);
        $this->assertStringContainsString('composer run preflight:production -- --strict', $checklist);

        $this->assertStringContainsString('curl -fsS https://your-live-domain/api/health.php', $checklist);
        $this->assertStringContainsString('curl -I https://your-live-domain/.env', $checklist);
        $this->assertStringContainsString('curl -I https://your-live-domain/scripts/create_admin_user.php', $checklist);
        $this->assertStringContainsString('npx playwright test tests/smoke/auth.smoke.spec.js', $checklist);
    }

    public function testServerGuardsAndDeployScriptsMatchChecklist(): void
    {
        $rootHtaccess = $this->rootFile('.htaccess');
        $deployScript = $this->rootFile('scripts/deploy_to_production.sh');
        $deployWrapper = $this->rootFile('scripts/deploy.sh');

        $this->assertStringContainsString('scripts|cli|docs', $rootHtaccess);
        $this->assertStringContainsString('env.production.template', $deployScript);
        $this->assertStringContainsString('composer install --no-dev --optimize-autoloader', $deployScript);
        $this->assertStringContainsString('composer run runtime:prepare', $deployScript);
        $this->assertStringContainsString('composer run backup:check -- --json', $deployScript);
        $this->assertStringContainsString('composer run migrate', $deployScript);
        $this->assertStringContainsString('composer run preflight:production -- --strict', $deployScript);
        $this->assertStringContainsString('api/health.php', $deployScript);
        $this->assertStringContainsString('deploy_to_production.sh', $deployWrapper);
    }
}
