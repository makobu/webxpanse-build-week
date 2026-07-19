<?php

namespace CRM\Tests\Unit\Services;

use CRM\Modules\CompanyProfile;
use CRM\Modules\IdeaValidationContext;
use CRM\Modules\UserStrategyProfile;
use CRM\Services\SmartTemplateContextService;
use CRM\Tests\DatabaseTestCase;

class SmartTemplateContextServiceTest extends DatabaseTestCase
{
    public function testReadinessRequiresAllThreeContextSources(): void
    {
        $service = new SmartTemplateContextService();
        $companyProfile = new CompanyProfile();
        $strategyProfile = new UserStrategyProfile();
        $ideaValidation = new IdeaValidationContext();

        $companyProfile->update([
            'company_name' => 'Acme Labs',
            'company_description' => 'We help teams operationalize AI.',
            'company_industry' => 'Software',
        ]);
        $strategyProfile->save(5, [
            'target_market_focus' => 'SMB service teams',
            'ideal_customer_profile' => 'Founder-led agencies',
            'offer_angle' => 'Practical AI systems',
            'sales_motion' => 'Founder-led consultative sales',
        ]);

        $notReady = $service->getReadiness(5);
        $this->assertFalse($notReady['is_ready']);
        $this->assertSame('idea_validation', $notReady['missing_requirements'][0]['section']);

        $ideaValidation->save(5, [
            'value_proposition' => 'Clear AI operating systems',
            'target_market' => 'Founder-led agencies',
            'pain_points' => 'Leaky follow-up',
            'differentiator' => 'Fast implementation with governance',
        ]);

        $ready = $service->getReadiness(5);
        $this->assertTrue($ready['is_ready']);
        $this->assertCount(0, $ready['missing_requirements']);
        $this->assertNotEmpty($ready['context_hash']);
    }

    public function testContextBundleIncludesStructuredBlocks(): void
    {
        $companyProfile = new CompanyProfile();
        $strategyProfile = new UserStrategyProfile();
        $ideaValidation = new IdeaValidationContext();

        $companyProfile->update([
            'company_name' => 'Northwind',
            'company_description' => 'A modern CRM rollout partner',
            'company_industry' => 'Consulting',
        ]);
        $strategyProfile->save(9, [
            'target_market_focus' => 'Professional services firms',
            'ideal_customer_profile' => 'Operations leaders',
            'offer_angle' => 'Automation without chaos',
            'sales_motion' => 'Warm consultative outreach',
        ]);
        $ideaValidation->save(9, [
            'value_proposition' => 'Faster follow-through with better visibility',
            'target_market' => 'Mid-market consultancies',
            'pain_points' => 'Slow handoffs',
            'differentiator' => 'Done-with-you implementation',
        ]);

        $bundle = (new SmartTemplateContextService())->buildContextBundle(9, 'email_pack_generation');

        $this->assertSame('smart_templates', $bundle['surface']);
        $this->assertSame('email_pack_generation', $bundle['prompt_key']);
        $this->assertCount(3, $bundle['blocks']);
        $this->assertSame('company_profile', $bundle['blocks'][0]['key']);
        $this->assertStringContainsString('COMPANY PROFILE', $bundle['blocks'][0]['content']);
    }
}
