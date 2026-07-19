<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

final class DesignStudioImmersiveTest extends TestCase
{
    public function testBothEditorsDeliberatelyOwnTheViewportWithoutBypassingTheAppShell(): void
    {
        $landing = (string) file_get_contents(__DIR__ . '/../../../public/marketing_landing_page_edit.php');
        $form = (string) file_get_contents(__DIR__ . '/../../../public/form_edit.php');
        $css = (string) file_get_contents(__DIR__ . '/../../../public/assets/css/design-studio.css');

        foreach ([$landing, $form] as $source) {
            $this->assertStringContainsString("include __DIR__ . '/../views/layouts/base.php';", $source);
            $this->assertStringContainsString('design-studio-immersive', $source);
        }
        $this->assertStringContainsString('position: fixed;', $css);
        $this->assertStringContainsString('width: 100vw;', $css);
        $this->assertStringContainsString('height: 100dvh;', $css);
        $this->assertStringContainsString('inset: 0;', $css);
    }

    public function testEditorsExposeDragDropTemplatesInlineEditingAndResponsivePreviewControls(): void
    {
        $landing = (string) file_get_contents(__DIR__ . '/../../../public/assets/js/design-studio.js');
        $form = (string) file_get_contents(__DIR__ . '/../../../public/assets/js/form-studio.js');
        $runtime = (string) file_get_contents(__DIR__ . '/../../../public/assets/js/form-runtime.js');

        $this->assertStringContainsString('data-inline-prop', $landing);
        $this->assertStringContainsString("event.dataTransfer.setData('text/plain', 'add:'", $landing);
        $this->assertStringContainsString('data-apply-template', $landing);
        $this->assertStringContainsString('data-add-field', $form);
        $this->assertStringContainsString('data-apply-template', $form);
        $this->assertStringContainsString('data-viewport', $form);
        $this->assertStringContainsString('data-form-next', $runtime);
        $this->assertStringContainsString('crm-form-height', $runtime);
    }

    public function testMediaCanBeUploadedInlineAndColourControlsUpdateWithoutLeavingTheEditors(): void
    {
        $landingPage = (string) file_get_contents(__DIR__ . '/../../../public/marketing_landing_page_edit.php');
        $landingStudio = (string) file_get_contents(__DIR__ . '/../../../public/assets/js/design-studio.js');
        $formStudio = (string) file_get_contents(__DIR__ . '/../../../public/assets/js/form-studio.js');
        $uploadEndpoint = (string) file_get_contents(__DIR__ . '/../../../api/marketing/landing_page_media.php');

        $this->assertStringContainsString("apiUrl('marketing/landing_page_media.php')", $landingPage);
        $this->assertStringContainsString('id="designMediaUpload"', $landingPage);
        $this->assertStringContainsString('data-upload-media', $landingStudio);
        $this->assertStringContainsString('data-style-custom-background', $landingStudio);
        $this->assertStringContainsString('data-style-color-picker', $landingStudio);
        $this->assertStringContainsString('data-style-color-hex', $landingStudio);
        $this->assertStringContainsString("data.append('media_file', file)", $landingStudio);
        $this->assertStringContainsString("Authorization::can('marketing.write'", $uploadEndpoint);
        $this->assertStringContainsString('Security::validateCSRF', $uploadEndpoint);
        $this->assertStringContainsString('Brand media', $formStudio);
        $this->assertStringContainsString("indexOf('theme.') === 0", $formStudio);
    }
}
