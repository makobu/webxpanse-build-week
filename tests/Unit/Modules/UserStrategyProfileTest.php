<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\UserStrategyProfile;
use CRM\Tests\DatabaseTestCase;

class UserStrategyProfileTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Database::execute('DELETE FROM user_strategy_profiles');
    }

    public function testSaveAndGetKeepsProfilesSeparatedPerUser(): void
    {
        $module = new UserStrategyProfile();

        $module->save(11, [
            'target_market_focus' => 'Kenyan service businesses',
            'ideal_customer_profile' => 'Founder-led agencies',
            'offer_angle' => 'Fast AI implementation',
            'segment_focus' => 'Creative agencies',
            'sales_motion' => 'Warm outbound',
            'deal_movement_strategy' => 'Lead with audits then pilot projects',
            'outreach_posture' => 'Consultative',
            'positioning_notes' => 'Stay practical and ROI-led',
            'market_view' => 'Local service teams want faster proof before retainers.',
            'strategy_hypothesis' => 'Audit-first offers will increase qualified replies.',
            'draft_tone_preset' => 'warm',
            'draft_voice_notes' => 'Keep replies grounded and human.',
            'draft_cta_style' => 'soft',
            'draft_formality_level' => 'balanced',
            'draft_reading_level' => 'college',
        ]);
        $module->save(12, [
            'target_market_focus' => 'US B2B SaaS teams',
            'ideal_customer_profile' => 'Revenue operations leads',
            'offer_angle' => 'Pipeline clarity and automation trust',
            'segment_focus' => 'Series A SaaS',
            'sales_motion' => 'Partner-led',
            'deal_movement_strategy' => 'Start with expansion-ready use cases',
            'outreach_posture' => 'Executive and strategic',
            'positioning_notes' => 'Differentiate on governance',
            'market_view' => 'SaaS teams are under pressure to prove efficient growth.',
            'strategy_hypothesis' => 'Governance-led messaging will unlock RevOps buyers.',
            'draft_tone_preset' => 'direct',
            'draft_voice_notes' => 'Stay crisp and decisive.',
            'draft_cta_style' => 'clear',
            'draft_formality_level' => 'formal',
            'draft_reading_level' => 'professional',
        ]);

        $userA = $module->get(11);
        $userB = $module->get(12);

        $this->assertSame('Kenyan service businesses', $userA['target_market_focus'] ?? null);
        $this->assertSame('US B2B SaaS teams', $userB['target_market_focus'] ?? null);
        $this->assertSame('Creative agencies', $userA['segment_focus'] ?? null);
        $this->assertSame('Series A SaaS', $userB['segment_focus'] ?? null);
        $this->assertSame('warm', $userA['draft_tone_preset'] ?? null);
        $this->assertSame('direct', $userB['draft_tone_preset'] ?? null);
        $this->assertSame('Local service teams want faster proof before retainers.', $userA['market_view'] ?? null);
        $this->assertSame('Governance-led messaging will unlock RevOps buyers.', $userB['strategy_hypothesis'] ?? null);
        $this->assertSame('Keep replies grounded and human.', $userA['draft_voice_notes'] ?? null);
        $this->assertSame('formal', $userB['draft_formality_level'] ?? null);
        $this->assertSame('level_2', $userA['draft_reading_level'] ?? null);
        $this->assertSame('level_2', $userB['draft_reading_level'] ?? null);
    }

    public function testGetContextForPromptIncludesOnlyFilledStrategyFields(): void
    {
        $module = new UserStrategyProfile();
        $module->save(15, [
            'target_market_focus' => 'Regional consultancies',
            'offer_angle' => 'AI co-pilot for pipeline ops',
            'deal_movement_strategy' => 'Use proof-based follow-up',
            'market_view' => 'Buyers want confidence without a platform migration.',
            'strategy_hypothesis' => 'Proof-based follow-up will beat generic automation messaging.',
            'draft_tone_preset' => 'consultative',
            'draft_cta_style' => 'clear',
            'draft_reading_level' => 'junior_high',
        ]);

        $context = $module->getContextForPrompt(15);

        $this->assertStringContainsString('USER GTM STRATEGY', $context);
        $this->assertStringContainsString('Target market focus: Regional consultancies', $context);
        $this->assertStringContainsString('Offer angle: AI co-pilot for pipeline ops', $context);
        $this->assertStringContainsString('Deal movement strategy: Use proof-based follow-up', $context);
        $this->assertStringContainsString('Market view: Buyers want confidence without a platform migration.', $context);
        $this->assertStringContainsString('Strategy hypothesis: Proof-based follow-up will beat generic automation messaging.', $context);
        $this->assertStringContainsString('Draft tone preset: consultative', $context);
        $this->assertStringContainsString('Draft CTA style: clear', $context);
        $this->assertStringContainsString('Language level: Level 1 - Plain, direct, low-jargon', $context);
        $this->assertStringNotContainsString('Ideal customer profile:', $context);
    }
}
