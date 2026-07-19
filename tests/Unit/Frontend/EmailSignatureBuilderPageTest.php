<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class EmailSignatureBuilderPageTest extends TestCase
{
    public function testCreateAndEditUseTheSharedModernBuilder(): void
    {
        $create = (string) file_get_contents(__DIR__ . '/../../../public/email_signature_create.php');
        $edit = (string) file_get_contents(__DIR__ . '/../../../public/email_signature_edit.php');
        $builder = (string) file_get_contents(__DIR__ . '/../../../views/email_signatures/builder.php');

        $this->assertStringContainsString("views/email_signatures/builder.php", $create);
        $this->assertStringContainsString("views/email_signatures/builder.php", $edit);
        $this->assertStringContainsString('$requiresQuillEditor = true', $create);
        $this->assertStringContainsString('$requiresQuillEditor = true', $edit);
        $this->assertStringNotContainsString('cdn.quilljs.com/1.3.6/quill.js', $create);
        $this->assertStringNotContainsString('cdn.quilljs.com/1.3.6/quill.js', $edit);

        $this->assertStringContainsString('data-builder-template', $builder);
        $this->assertStringContainsString('data-preview-device="mobile"', $builder);
        $this->assertStringContainsString('data-preview-theme="dark"', $builder);
        $this->assertStringContainsString('data-draft-banner', $builder);
        $this->assertStringContainsString('id="logo-upload-zone"', $builder);
        $this->assertStringContainsString('id="font_family"', $builder);
        $this->assertStringContainsString('id="font_size_preset"', $builder);
        $this->assertStringContainsString('DesignTypographyCatalog::emailFonts()', $create);
        $this->assertStringContainsString('DesignTypographyCatalog::emailSizes()', $edit);
        $this->assertStringContainsString('email-signature-builder.js', $create);
        $this->assertStringContainsString('email-signature-builder.js', $edit);
    }
}
