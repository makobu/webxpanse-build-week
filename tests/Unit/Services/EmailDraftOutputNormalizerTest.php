<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\EmailDraftOutputNormalizer;
use CRM\Tests\TestCase;

class EmailDraftOutputNormalizerTest extends TestCase
{
    public function testNormalizeEmailDraftPayloadParsesFencedJsonWithoutLeakingContract(): void
    {
        $normalizer = new EmailDraftOutputNormalizer();

        $normalized = $normalizer->normalizeEmailDraftPayload(<<<'RAW'
```json
{
  "subject": "Welcome to webXpanse, Catherine!",
  "body_html": "<p>Dear Catherine,</p><p>Welcome to webXpanse.</p>",
  "body_text": "Dear Catherine,\n\nWelcome to webXpanse."
}
```
RAW);

        $this->assertSame('Welcome to webXpanse, Catherine!', $normalized['subject']);
        $this->assertStringContainsString('Welcome to webXpanse.', $normalized['body_html']);
        $this->assertStringContainsString('Welcome to webXpanse.', $normalized['body_text']);
        $this->assertStringNotContainsString('```json', $normalized['body_html']);
    }

    public function testNormalizeEmailDraftPayloadRejectsPromptContractEcho(): void
    {
        $normalizer = new EmailDraftOutputNormalizer();

        $normalized = $normalizer->normalizeEmailDraftPayload(<<<'RAW'
```json
{
  "subject": "Email subject line",
  "body_html": "HTML formatted email body",
  "body_text": "Plain text version"
}
```
RAW);

        $this->assertSame('Email subject line', $normalized['subject']);
        $this->assertSame('', $normalized['body_html']);
        $this->assertSame('', $normalized['body_text']);
    }

    public function testNormalizeEmailDraftPayloadStripsDocumentLevelHtmlMarkup(): void
    {
        $normalizer = new EmailDraftOutputNormalizer();

        $normalized = $normalizer->normalizeEmailDraftPayload([
            'subject' => 'Welcome aboard',
            'body_html' => '<!DOCTYPE html><html><head><title>Ignore</title><style>body{color:red;}</style></head><body><p>Hello Catherine,</p><p>Welcome to webXpanse.</p></body></html>',
        ]);

        $this->assertSame('Welcome aboard', $normalized['subject']);
        $this->assertStringNotContainsString('<html', $normalized['body_html']);
        $this->assertStringNotContainsString('<head', $normalized['body_html']);
        $this->assertStringContainsString('Welcome to webXpanse.', $normalized['body_html']);
        $this->assertStringContainsString('Welcome to webXpanse.', $normalized['body_text']);
    }

    public function testNormalizeEmailDraftPayloadConvertsHtmlLeakageInBodyTextToPlainText(): void
    {
        $normalizer = new EmailDraftOutputNormalizer();

        $normalized = $normalizer->normalizeEmailDraftPayload([
            'subject' => 'Hello',
            'body_text' => '<p>Hello <strong>Catherine</strong>,</p><p>Thanks for your time.</p>',
        ]);

        $this->assertSame('Hello', $normalized['subject']);
        $this->assertSame("Hello Catherine,\n\nThanks for your time.", $normalized['body_text']);
        $this->assertStringContainsString('Hello Catherine,', $normalized['body_html']);
    }

    public function testNormalizeEmailDraftPayloadRejectsMixedPromptContractAndMalformedPayloads(): void
    {
        $normalizer = new EmailDraftOutputNormalizer();

        $contractEcho = $normalizer->normalizeEmailDraftPayload([
            'subject' => 'Welcome',
            'body_html' => '<!DOCTYPE html><html><body><p>Return JSON using the output contract.</p></body></html>',
            'body_text' => 'Plain text version',
        ]);

        $mixedPayload = $normalizer->normalizeEmailDraftPayload([
            'subject' => 'Follow up',
            'body_html' => '{"body_html":"<p>Bad</p>"}',
            'body_text' => '<p>Hello <strong>team</strong>,</p><p>Checking in.</p>',
        ]);

        $this->assertSame('', $contractEcho['body_html']);
        $this->assertSame('', $contractEcho['body_text']);
        $this->assertSame("Hello team,\n\nChecking in.", $mixedPayload['body_text']);
        $this->assertStringContainsString('Hello team,', $mixedPayload['body_html']);
    }
}
