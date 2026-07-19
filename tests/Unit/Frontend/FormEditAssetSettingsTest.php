<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class FormEditAssetSettingsTest extends TestCase
{
    public function testFormStudioCanUploadAndExplicitlyRemoveBrandAssets(): void
    {
        $formEdit = file_get_contents(__DIR__ . '/../../../public/form_edit.php');
        $studio = file_get_contents(__DIR__ . '/../../../public/assets/js/form-studio.js');

        $this->assertNotFalse($formEdit);
        $this->assertNotFalse($studio);
        $this->assertStringContainsString("id=\"formLogoUpload\"", (string) $formEdit);
        $this->assertStringContainsString("id=\"formHeaderUpload\"", (string) $formEdit);
        $this->assertStringContainsString("state.document.theme.logo_path = '';", (string) $studio);
        $this->assertStringContainsString("state.document.theme.header_image_path = '';", (string) $studio);
        $this->assertStringContainsString("uploadAsset('logo'", (string) $studio);
        $this->assertStringContainsString("uploadAsset('header_image'", (string) $studio);
    }

    public function testPublicFormUsesTheSameSchemaRendererAndBrandStructureAsTheCanvas(): void
    {
        $publicForm = file_get_contents(__DIR__ . '/../../../public/form.php');
        $editor = file_get_contents(__DIR__ . '/../../../public/form_edit.php');
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/form-studio-public.css');

        $this->assertNotFalse($publicForm);
        $this->assertNotFalse($editor);
        $this->assertNotFalse($css);
        $this->assertStringContainsString('FormDesignService', (string) $publicForm);
        $this->assertStringContainsString('$design->render($document', (string) $publicForm);
        $this->assertStringContainsString('$design->render($document', (string) $editor);
        $this->assertStringContainsString('.form-runtime__header + .form-runtime__inner .form-runtime__logo', (string) $css);
        $this->assertStringContainsString('.form-studio-public-body--embedded', (string) $css);
    }
}
