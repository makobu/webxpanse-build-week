<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class PublicDonationUiTest extends TestCase
{
    private function fileContents(string $path): string
    {
        $contents = file_get_contents(__DIR__ . '/../../../' . ltrim($path, '/'));

        $this->assertNotFalse($contents);

        return str_replace(["\r\n", "\r"], "\n", (string) $contents);
    }

    public function testEntrancePageLinksToPublicDonationPage(): void
    {
        $page = $this->fileContents('entrance.php');

        $this->assertStringContainsString('$donateUrl = $basePath . \'/public/donate.php\';', $page);
        $this->assertStringContainsString('\\CRM\\Database::init(require __DIR__ . \'/config/database.php\');', $page);
        $this->assertStringContainsString('$donationsEnabled = \\CRM\\Modules\\WorkspaceBillingSettings::donationsEnabled();', $page);
        $this->assertStringContainsString('<?php if ($donationsEnabled): ?>', $page);
        $this->assertStringContainsString('class="donate-cta-gold"', $page);
        $this->assertStringContainsString('data-public-donate-cta', $page);
        $this->assertStringContainsString('Support Startup AI Access', $page);
        $this->assertStringContainsString('if (donateBtn) {', $page);
    }

    public function testLoginPageShowsGoldDonationCta(): void
    {
        $page = $this->fileContents('public/login.php');

        $this->assertStringContainsString('$donateUrl = publicUrl(\'donate.php\');', $page);
        $this->assertStringContainsString('$initializeLoginDatabase();', $page);
        $this->assertStringContainsString('$donationsEnabled = WorkspaceBillingSettings::donationsEnabled();', $page);
        $this->assertStringContainsString('<div class="auth-page-shell">', $page);
        $this->assertStringContainsString('<?php if ($donationsEnabled): ?>', $page);
        $this->assertStringContainsString('class="donate-cta-gold"', $page);
        $this->assertStringContainsString('Support Startup AI Access', $page);
        $this->assertStringContainsString('data-public-donate-cta', $page);
        $this->assertStringContainsString('<a href="<?php echo htmlspecialchars($donateUrl); ?>" class="donate-cta-gold" data-public-donate-cta>', $page);
    }

    public function testPublicDonationPageHasAccountFreeDonationForm(): void
    {
        $page = $this->fileContents('public/donate.php');

        $this->assertStringContainsString('$donationsEnabled = WorkspaceBillingSettings::donationsEnabled();', $page);
        $this->assertStringContainsString('$donationEndpoint = apiUrl(\'donations/checkout.php\');', $page);
        $this->assertStringContainsString('$donationStatusEndpoint = apiUrl(\'donations/status.php\');', $page);
        $this->assertStringContainsString('<?php if (!$donationsEnabled): ?>', $page);
        $this->assertStringContainsString('Donation support is unavailable', $page);
        $this->assertStringContainsString('Donation checkout is currently turned off for this platform.', $page);
        $this->assertStringContainsString('data-public-donation-form', $page);
        $this->assertStringContainsString('data-donation-status-endpoint', $page);
        $this->assertStringContainsString('data-donation-result-state', $page);
        $this->assertStringContainsString('data-donation-result-title', $page);
        $this->assertStringContainsString('data-donation-reset', $page);
        $this->assertStringContainsString('donate-celebration', $page);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $page);
        $this->assertStringContainsString('pollDonationStatus', $page);
        $this->assertStringContainsString('Waiting for phone approval', $page);
        $this->assertStringContainsString('Thank you for supporting startup AI access', $page);
        $this->assertStringContainsString('<input type="number" name="amount" min="1" step="1" placeholder="Enter amount" required>', $page);
        $this->assertStringNotContainsString('<input type="number" name="amount" min="1" step="1" value="500" required>', $page);
        $this->assertStringContainsString('name="customer_email"', $page);
        $this->assertStringContainsString('name="customer_phone"', $page);
        $this->assertStringContainsString('.donate-field[hidden]', $page);
        $this->assertStringContainsString('data-donation-phone-field hidden', $page);
        $this->assertStringContainsString('data-donation-phone disabled', $page);
        $this->assertStringContainsString('data-donation-method-options', $page);
        $this->assertStringContainsString('donationPaymentOptions([\'KES\', \'USD\', \'NGN\'], true)', $page);
        $this->assertStringContainsString('rebuildMethodOptions()', $page);
        $this->assertStringContainsString('phoneField.hidden = !requiresPhone;', $page);
        $this->assertStringContainsString('phoneInput.required = hasMethods && requiresPhone;', $page);
        $this->assertStringContainsString('emailInput.disabled = !hasMethods || requiresPhone;', $page);
        $this->assertStringContainsString('Donation checkout is temporarily unavailable.', $page);
        $this->assertStringContainsString('Support Startup AI Access', $page);
        $this->assertStringContainsString('Your contribution helps small startups access AI tools without enterprise-sized costs.', $page);
        $this->assertStringContainsString('This checkout does not create an account', $page);
        $this->assertStringContainsString('new PlatformLegalIdentityService())->systemLegalName();', $page);
        $this->assertStringContainsString('class="donate-system-legal"', $page);
        $this->assertStringContainsString('System legal name:', $page);
        $this->assertStringContainsString('Donations do not change package access or AI Credit balances.', $page);

        $callback = $this->fileContents('public/billing_callback.php');
        $this->assertStringContainsString('donation_thank_you', $callback);
        $this->assertStringContainsString("'donation_status' => 'success'", $callback);
        $this->assertStringContainsString("checkout_type'] ?? '') === 'donation'", $callback);
    }

    public function testWorkspaceDonationModalEnforcesKesForMpesa(): void
    {
        $page = $this->fileContents('views/layouts/base.php');

        $this->assertStringContainsString('$workspaceDonationsEnabled = \\CRM\\Modules\\WorkspaceBillingSettings::donationsEnabled();', $page);
        $this->assertStringContainsString('<?php if ($workspaceDonationsEnabled): ?>', $page);
        $this->assertStringContainsString('data-workspace-donation-currency', $page);
        $this->assertStringContainsString('data-workspace-donation-method', $page);
        $this->assertStringContainsString('.workspace-donation-form label[hidden]', $page);
        $this->assertStringContainsString('<input type="number" min="1" step="1" name="amount" placeholder="Enter amount" required>', $page);
        $this->assertStringNotContainsString('<input type="number" min="1" step="1" name="amount" value="500" required>', $page);
        $this->assertStringContainsString('data-workspace-donation-phone disabled', $page);
        $this->assertStringContainsString('data-workspace-donation-method-options', $page);
        $this->assertStringContainsString('donationPaymentOptions([\'KES\', \'USD\', \'NGN\'], true)', $page);
        $this->assertStringContainsString('donationMethodOptions = JSON.parse', $page);
        $this->assertStringContainsString('donationPhoneInput.disabled = !requiresPhone;', $page);
        $this->assertStringContainsString('Donation checkout is temporarily unavailable.', $page);
        $this->assertStringContainsString('Support Startup AI Access', $page);
        $this->assertStringContainsString('Your contribution helps small startups access AI tools without enterprise-sized costs.', $page);
        $this->assertStringContainsString('new \\CRM\\Services\\PlatformLegalIdentityService())->systemLegalName();', $page);
        $this->assertStringContainsString('class="workspace-donation-system-legal"', $page);
        $this->assertStringContainsString('System legal name:', $page);
    }
}
