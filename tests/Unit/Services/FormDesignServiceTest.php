<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\FormDesignService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FormDesignServiceTest extends TestCase
{
    private FormDesignService $service;

    protected function setUp(): void
    {
        $this->service = new FormDesignService();
    }

    public function testRegistryContainsOnlyCodeOwnedStructuredFieldTypes(): void
    {
        $this->assertSame([
            'text', 'email', 'phone', 'number', 'textarea', 'select', 'radio', 'checkbox',
            'date', 'time', 'file', 'rating', 'nps', 'hidden', 'heading', 'paragraph',
            'divider', 'page_break',
        ], array_keys($this->service->fieldRegistry()));

        $manifest = strtolower((string) json_encode($this->service->editorManifest()));
        $this->assertStringNotContainsString('custom_html', $manifest);
        $this->assertStringNotContainsString('javascript', $manifest);
        $this->assertStringNotContainsString('custom_css', $manifest);
    }

    public function testNormalizationSanitizesContentAndRejectsUnknownFields(): void
    {
        $document = $this->service->normalizeDocument([
            'schema' => FormDesignService::SCHEMA_VERSION,
            'content' => [
                'title' => '<script>alert(1)</script><strong>Safe title</strong>',
                'redirect_url' => 'javascript:alert(1)',
            ],
            'steps' => [['id' => 'main', 'title' => '<b>Contact</b>']],
            'fields' => [[
                'id' => 'email',
                'type' => 'email',
                'step_id' => 'main',
                'name' => 'work email',
                'label' => '<em>Work email</em>',
                'mapping' => 'email',
            ]],
        ]);

        $this->assertSame('alert(1)Safe title', $document['content']['title']);
        $this->assertSame('', $document['content']['redirect_url']);
        $this->assertSame('Contact', $document['steps'][0]['title']);
        $this->assertSame('work_email', $document['fields'][0]['name']);
        $this->assertStringNotContainsString('<', (string) json_encode($document));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown form field type');
        $this->service->normalizeDocument([
            'schema' => FormDesignService::SCHEMA_VERSION,
            'fields' => [['type' => 'custom_html', 'name' => 'unsafe']],
        ]);
    }

    public function testConditionalVisibilitySupportsShowAndHideRules(): void
    {
        $document = $this->service->normalizeDocument([
            'schema' => FormDesignService::SCHEMA_VERSION,
            'steps' => [['id' => 'main', 'title' => 'Main']],
            'fields' => [
                ['id' => 'interest', 'type' => 'select', 'step_id' => 'main', 'name' => 'interest', 'label' => 'Interest', 'options' => ['Sales', 'Support']],
                ['id' => 'budget', 'type' => 'number', 'step_id' => 'main', 'name' => 'budget', 'label' => 'Budget', 'logic' => ['enabled' => true, 'action' => 'show', 'match' => 'all', 'conditions' => [['field_id' => 'interest', 'operator' => 'equals', 'value' => 'Sales']]]],
                ['id' => 'support_note', 'type' => 'textarea', 'step_id' => 'main', 'name' => 'support_note', 'label' => 'Support note', 'logic' => ['enabled' => true, 'action' => 'hide', 'match' => 'all', 'conditions' => [['field_id' => 'interest', 'operator' => 'equals', 'value' => 'Sales']]]],
            ],
        ]);

        $sales = $this->service->visibilityMap($document, ['interest' => 'Sales']);
        $this->assertTrue($sales['budget']);
        $this->assertFalse($sales['support_note']);

        $support = $this->service->visibilityMap($document, ['interest' => 'Support']);
        $this->assertFalse($support['budget']);
        $this->assertTrue($support['support_note']);
    }

    public function testRendererProducesMultiStepRuntimeWithoutExecutableMarkup(): void
    {
        $template = $this->template('lead_qualification');
        $html = $this->service->render($template['document'], [
            'mode' => 'public',
            'visitor_id' => 'visitor-safe',
            'form_uuid' => 'form-safe',
        ]);

        $this->assertStringContainsString('data-form-schema="crm.form/v1"', $html);
        $this->assertStringContainsString('data-form-step="contact"', $html);
        $this->assertStringContainsString('data-form-next', $html);
        $this->assertStringContainsString('data-form-previous', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringNotContainsString('<script', strtolower($html));
    }

    public function testFormThemePersistsCuratedHeadingBodyAndSizeChoices(): void
    {
        $document = $this->service->normalizeDocument([
            'schema' => FormDesignService::SCHEMA_VERSION,
            'theme' => ['heading_font' => 'georgia', 'body_font' => 'verdana', 'type_scale' => 'expressive'],
            'steps' => [['id' => 'main', 'title' => 'Main']],
            'fields' => [['id' => 'email', 'type' => 'email', 'step_id' => 'main', 'name' => 'email', 'label' => 'Email']],
        ]);

        $this->assertSame('georgia', $document['theme']['heading_font']);
        $this->assertSame('verdana', $document['theme']['body_font']);
        $this->assertSame('expressive', $document['theme']['type_scale']);
        $html = $this->service->render($document);
        $this->assertStringContainsString('--form-heading-font:Georgia, &quot;Times New Roman&quot;, serif', $html);
        $this->assertStringContainsString('--form-body-font:Verdana, Geneva, sans-serif', $html);
        $this->assertStringContainsString('--form-heading-scale:1.12', $html);
    }

    public function testLegacyAdapterAndApprovedTemplatesRemainStable(): void
    {
        $legacy = $this->service->legacyDocument([
            'name' => 'Legacy enquiry',
            'fields' => [['type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true]],
            'settings' => ['primary_color' => '#123456'],
        ]);
        $this->assertSame('Legacy enquiry', $legacy['content']['title']);
        $this->assertSame('#123456', $legacy['theme']['primary_color']);
        $this->assertSame('email', $this->service->legacyFields($legacy)[0]['type']);

        $first = $this->service->templates();
        $second = $this->service->templates();
        $this->assertCount(8, $first);
        $this->assertSame(
            array_column($first[0]['document']['fields'], 'id'),
            array_column($second[0]['document']['fields'], 'id'),
            'Template field identifiers must remain stable across reads.'
        );
        foreach ($first as $template) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $template['version']);
            $this->assertTrue($this->service->validateDocument($template['document'])['valid']);
        }
    }

    /** @return array<string,mixed> */
    private function template(string $key): array
    {
        foreach ($this->service->templates() as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }
        self::fail('Template not found: ' . $key);
    }
}
