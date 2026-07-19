<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class SessionUxTest extends TestCase
{
    public function testAuthenticatedLayoutLoadsSessionUxController(): void
    {
        $layout = $this->readFile('views/layouts/base.php');

        $this->assertStringContainsString("apiUrl('session/status.php')", $layout);
        $this->assertStringContainsString('\CRM\Auth::loginUrl(null, true)', $layout);
        $this->assertStringContainsString('\CRM\Auth::loginUrl(null, false, true)', $layout);
        $this->assertStringContainsString("js/session-ux.js", $layout);
        $this->assertStringContainsString("'warnBeforeSeconds' => 300", $layout);
    }

    public function testSessionUxControllerHandlesAuthFailuresAndDrafts(): void
    {
        $script = $this->readFile('public/assets/js/session-ux.js');

        $this->assertStringContainsString("window.sessionStorage.setItem(draftKey", $script);
        $this->assertStringContainsString("window.sessionStorage.getItem(draftKey)", $script);
        $this->assertStringContainsString("['password', 'file', 'submit', 'button', 'reset']", $script);
        $this->assertStringContainsString("type === 'hidden' && field.getAttribute('data-session-draft') !== 'true'", $script);
        $this->assertStringContainsString('csrf|token|secret|password|api[_-]?key|private[_-]?key|credential', $script);
        $this->assertStringContainsString('[401, 403, 419].indexOf(response.status)', $script);
        $this->assertStringContainsString('response.clone().json()', $script);
        $this->assertStringContainsString('data-session-ux-login', $script);
        $this->assertStringContainsString("method: 'POST'", $script);
        $this->assertStringContainsString("'X-CRM-Session-Passive': '1'", $script);
        $this->assertStringContainsString('Your session will expire soon', $script);
        $this->assertStringContainsString('We couldn’t confirm your session', $script);
    }

    public function testDashboardWorkspaceRecoveryDoesNotLogoutUser(): void
    {
        $dashboard = $this->readFile('public/dashboard.php');

        $this->assertStringNotContainsString('Auth::logout();', $dashboard);
        $this->assertStringContainsString('WorkspaceContext::activateForUser($sessionUserId)', $dashboard);
        $this->assertStringContainsString("dashboard.php?workspace_recovered=1", $dashboard);
        $this->assertStringContainsString("Auth::loginUrl('dashboard.php', true)", $dashboard);
    }

    private function readFile(string $path): string
    {
        $contents = file_get_contents(__DIR__ . '/../../../' . $path);
        $this->assertNotFalse($contents);

        return str_replace(["\r\n", "\r"], "\n", (string) $contents);
    }
}
