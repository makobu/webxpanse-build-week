<?php

namespace Tests\Unit\Services;

use CRM\Services\LandingPageDesignService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LandingPageDesignServiceTest extends TestCase
{
    private LandingPageDesignService $service;

    protected function setUp(): void
    {
        $this->service = new LandingPageDesignService();
    }

    public function testRegistryExposesOnlyCodeOwnedStructuredBlocks(): void
    {
        $registry = $this->service->blockRegistry();

        $this->assertSame([
            'hero', 'text_image', 'benefits', 'logo_strip', 'testimonials', 'pricing',
            'faq', 'gallery', 'crm_form', 'booking', 'whatsapp', 'cta',
        ], array_keys($registry));
        $manifestJson = json_encode($this->service->editorManifest());
        $this->assertIsString($manifestJson);
        $this->assertStringNotContainsString('custom_html', $manifestJson);
        $this->assertStringNotContainsString('javascript', strtolower($manifestJson));
        $this->assertStringNotContainsString('custom_css', $manifestJson);
    }

    public function testNormalizationStripsMarkupAndRejectsUnknownBlocks(): void
    {
        $document = $this->service->normalizeDocument([
            'schema' => LandingPageDesignService::SCHEMA_VERSION,
            'blocks' => [[
                'id' => 'hero_safe',
                'type' => 'hero',
                'props' => [
                    'heading' => '<script>alert(1)</script><strong>Safe heading</strong>',
                    'primary_href' => 'javascript:alert(1)',
                ],
            ], [
                'id' => 'cta_safe',
                'type' => 'cta',
                'props' => ['heading' => 'Continue', 'button_label' => 'Contact', 'button_href' => '#contact'],
            ]],
        ]);

        $this->assertSame('alert(1)Safe heading', $document['blocks'][0]['props']['heading']);
        $this->assertSame('', $document['blocks'][0]['props']['primary_href']);
        $this->assertStringNotContainsString('<', json_encode($document));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown design block');
        $this->service->normalizeDocument([
            'schema' => LandingPageDesignService::SCHEMA_VERSION,
            'blocks' => [['id' => 'unsafe', 'type' => 'custom_html', 'props' => ['html' => '<b>unsafe</b>']]],
        ]);
    }

    public function testValidationRequiresRealConversionConnectionsAndAccessibleMedia(): void
    {
        $document = $this->service->normalizeDocument([
            'schema' => LandingPageDesignService::SCHEMA_VERSION,
            'blocks' => [[
                'id' => 'hero_one',
                'type' => 'hero',
                'props' => ['heading' => 'A focused page', 'media_file_id' => 12, 'image_alt' => ''],
            ], [
                'id' => 'form_one',
                'type' => 'crm_form',
                'props' => ['heading' => 'Contact us', 'form_id' => 0],
            ]],
        ]);

        $invalid = $this->service->validateDocument($document);
        $this->assertFalse($invalid['valid']);
        $this->assertStringContainsString('not connected to a CRM form', implode(' ', $invalid['errors']));
        $this->assertStringContainsString('image description', implode(' ', $invalid['warnings']));

        $document['blocks'][1]['props']['form_id'] = 9;
        $valid = $this->service->validateDocument($document, ['form_id' => 9]);
        $this->assertTrue($valid['valid']);
        $this->assertSame(95, $valid['score']);
    }

    public function testBookingBlockAcceptsOnlyCorePublicBookingRoutes(): void
    {
        $base = [
            'schema' => LandingPageDesignService::SCHEMA_VERSION,
            'blocks' => [[
                'id' => 'booking_one',
                'type' => 'booking',
                'props' => ['heading' => 'Book', 'booking_url' => 'https://attacker.example/embed'],
            ]],
        ];
        $normalized = $this->service->normalizeDocument($base);
        $this->assertSame('', $normalized['blocks'][0]['props']['booking_url']);
        $this->assertFalse($this->service->validateDocument($normalized)['valid']);

        $base['blocks'][0]['props']['booking_url'] = 'meeting_schedule.php?workspace=acme&profile=default';
        $normalized = $this->service->normalizeDocument($base);
        $this->assertSame('meeting_schedule.php?workspace=acme&profile=default', $normalized['blocks'][0]['props']['booking_url']);
        $this->assertTrue($this->service->validateDocument($normalized)['valid']);
    }

    public function testRendererEscapesContentAndUsesCoreOwnedFormEndpoint(): void
    {
        $document = $this->service->normalizeDocument([
            'schema' => LandingPageDesignService::SCHEMA_VERSION,
            'blocks' => [[
                'id' => 'hero_one',
                'type' => 'hero',
                'props' => ['heading' => 'Grow <strong>now</strong>', 'primary_label' => 'Contact', 'primary_href' => '#contact'],
            ], [
                'id' => 'form_one',
                'type' => 'crm_form',
                'props' => ['heading' => 'Contact', 'form_id' => 7],
            ]],
        ], ['form_id' => 7]);

        $html = $this->service->render($document, [
            'page' => ['form_id' => 7],
            'forms_by_id' => [7 => ['uuid' => 'safe-form-uuid']],
            'public_token' => 'publictoken123',
        ]);

        $this->assertStringContainsString('Grow now', $html);
        $this->assertStringNotContainsString('<strong>now</strong>', $html);
        $this->assertStringContainsString('form.php?uuid=safe-form-uuid&amp;embed=1&amp;landing_token=publictoken123', $html);
        $this->assertStringContainsString('data-marketing-cta="1"', $html);
        $this->assertStringNotContainsString('<script', strtolower($html));
    }

    public function testRendererPreservesCustomBlockBackgroundsAndAddsReadableContrastClass(): void
    {
        $document = $this->service->normalizeDocument([
            'schema' => LandingPageDesignService::SCHEMA_VERSION,
            'blocks' => [[
                'id' => 'hero_light',
                'type' => 'hero',
                'props' => ['heading' => 'Light hero'],
                'style' => ['background' => '#f2e9dc'],
            ], [
                'id' => 'cta_dark',
                'type' => 'cta',
                'props' => ['heading' => 'Dark call to action'],
                'style' => ['background' => '#17324d'],
            ], [
                'id' => 'form_custom',
                'type' => 'crm_form',
                'props' => ['heading' => 'Custom form block'],
                'style' => ['background' => '#e8f4ff'],
            ]],
        ]);

        $html = $this->service->render($document);

        $this->assertStringContainsString('id="hero_light" class="ds-block ds-block--hero ds-align--left ds-space--normal ds-block--custom-background"', $html);
        $this->assertStringContainsString('id="cta_dark" class="ds-block ds-block--cta ds-align--left ds-space--normal ds-block--custom-background ds-block--dark-background"', $html);
        $this->assertStringContainsString('id="form_custom" class="ds-block ds-block--crm_form ds-align--left ds-space--normal ds-block--custom-background"', $html);
        $this->assertStringContainsString('--ds-block-bg:#17324d', $html);
    }

    public function testTypographySupportsGlobalBrandChoicesAndSafeBlockOverrides(): void
    {
        $document = $this->service->normalizeDocument([
            'schema' => LandingPageDesignService::SCHEMA_VERSION,
            'theme' => ['heading_font' => 'georgia', 'body_font' => 'verdana', 'type_scale' => 'expressive'],
            'blocks' => [[
                'id' => 'hero_type',
                'type' => 'hero',
                'props' => ['heading' => 'Distinctive type'],
                'style' => ['heading_font' => 'courier', 'body_font' => 'arial', 'type_scale' => 'compact'],
            ]],
        ]);

        $this->assertSame('georgia', $document['theme']['heading_font']);
        $this->assertSame('verdana', $document['theme']['body_font']);
        $this->assertSame('compact', $document['blocks'][0]['style']['type_scale']);

        $html = $this->service->render($document);
        $this->assertStringContainsString('--ds-heading-font:Georgia, &quot;Times New Roman&quot;, serif', $html);
        $this->assertStringContainsString('--ds-body-font:Verdana, Geneva, sans-serif', $html);
        $this->assertStringContainsString('--ds-heading-font:&quot;Courier New&quot;, Courier, monospace', $html);
        $this->assertStringContainsString('--ds-body-font:Arial, Helvetica, sans-serif', $html);
    }

    public function testDraftFormsCanBePreviewedButCannotBePublishedInsideALandingPage(): void
    {
        $document = $this->service->normalizeDocument([
            'schema' => LandingPageDesignService::SCHEMA_VERSION,
            'blocks' => [[
                'id' => 'form_one',
                'type' => 'crm_form',
                'props' => ['heading' => 'Contact', 'form_id' => 7],
            ]],
        ], ['form_id' => 7]);

        $validation = $this->service->validateDocument($document, [
            'form_id' => 7,
            'form_design_status' => 'draft',
        ]);
        $this->assertFalse($validation['valid']);
        $this->assertStringContainsString('Publish the form before publishing this page', implode(' ', $validation['errors']));

        $preview = $this->service->render($document, [
            'mode' => 'preview',
            'forms_by_id' => [7 => ['uuid' => 'draft-form', 'preview_token' => 'preview123']],
        ]);
        $this->assertStringContainsString('form.php?uuid=draft-form&amp;embed=1&amp;preview_token=preview123', $preview);

        $public = $this->service->render($document, [
            'mode' => 'public',
            'forms_by_id' => [7 => ['uuid' => 'draft-form', 'preview_token' => 'preview123']],
        ]);
        $this->assertStringNotContainsString('preview_token', $public);
    }

    public function testApprovedTemplatesDeclareVersionedManifests(): void
    {
        $templates = $this->service->templates();
        $secondRead = (new LandingPageDesignService())->templates();
        $this->assertCount(8, $templates);
        $this->assertSame(
            array_column($templates[0]['document']['blocks'], 'id'),
            array_column($secondRead[0]['document']['blocks'], 'id'),
            'Approved template block IDs must remain stable across reads for reliable diffs and rollback.'
        );
        foreach ($templates as $template) {
            $this->assertSame('approved', $template['status']);
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $template['version']);
            $normalized = $this->service->normalizeDocument($template['document']);
            $this->assertSame(LandingPageDesignService::SCHEMA_VERSION, $normalized['schema']);
            $this->assertNotEmpty($normalized['blocks']);
        }
    }
}
