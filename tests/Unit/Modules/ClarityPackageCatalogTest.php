<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Modules\ClarityPackageCatalog;
use PHPUnit\Framework\TestCase;

class ClarityPackageCatalogTest extends TestCase
{
    public function testCoreAndGrowthPackagesExposeCapabilityMap(): void
    {
        $catalog = new ClarityPackageCatalog();
        $packages = $catalog->getPackages();

        $this->assertArrayHasKey(ClarityPackageCatalog::PACKAGE_SPRINT, $packages);
        $this->assertArrayHasKey(ClarityPackageCatalog::PACKAGE_LAUNCH, $packages);
        $this->assertArrayHasKey(ClarityPackageCatalog::PACKAGE_OPERATOR, $packages);
        $this->assertArrayHasKey(ClarityPackageCatalog::PACKAGE_CORE, $packages);
        $this->assertArrayHasKey(ClarityPackageCatalog::PACKAGE_GROWTH, $packages);
        $this->assertSame('Clarity Sprint', $packages[ClarityPackageCatalog::PACKAGE_SPRINT]['label']);
        $this->assertSame('AI Cofounder Launch', $packages[ClarityPackageCatalog::PACKAGE_LAUNCH]['label']);
        $this->assertSame('$149-$299 one-time', $packages[ClarityPackageCatalog::PACKAGE_LAUNCH]['price_range']);
        $this->assertSame('Clarity Operator', $packages[ClarityPackageCatalog::PACKAGE_OPERATOR]['label']);
        $this->assertSame('$49-$99/mo', $packages[ClarityPackageCatalog::PACKAGE_OPERATOR]['price_range']);
        $this->assertTrue((bool) ($packages[ClarityPackageCatalog::PACKAGE_CORE]['capabilities']['core_workflow'] ?? false));
        $this->assertFalse((bool) ($packages[ClarityPackageCatalog::PACKAGE_CORE]['capabilities']['growth_ai'] ?? true));
        $this->assertTrue((bool) ($packages[ClarityPackageCatalog::PACKAGE_GROWTH]['capabilities']['growth_ai'] ?? false));
        $this->assertTrue((bool) ($packages[ClarityPackageCatalog::PACKAGE_LAUNCH]['capabilities']['founder_guidance'] ?? false));
    }

    public function testSoloFoundersProfileIncludesFounderLaunchDefaults(): void
    {
        $catalog = new ClarityPackageCatalog();
        $profile = $catalog->getNicheProfile(ClarityPackageCatalog::NICHE_SOLO_FOUNDERS);

        $this->assertSame('Solo founders / side-hustlers', $profile['label']);
        $this->assertSame('solo_founder_launch', $profile['demo_seed_profile']);
        $this->assertSame('Idea clarity', $profile['stage_mapping'][0]['label']);
        $this->assertSame('Finalize first offer and pricing', $profile['task_templates'][0]['title']);
        $this->assertSame('30-Day AI Cofounder Launch', $profile['product_templates'][1]['name']);
        $this->assertSame('Paid Signal Type', $profile['custom_fields'][2]['field_name']);
        $this->assertSame(ClarityPackageCatalog::PACKAGE_LAUNCH, $profile['demo_scenarios'][0]['recommended_package']);
    }

    public function testInteriorsContractorsProfileIncludesStageMappingAndTaskTemplates(): void
    {
        $catalog = new ClarityPackageCatalog();
        $profile = $catalog->getNicheProfile(ClarityPackageCatalog::NICHE_INTERIORS_CONTRACTORS);

        $this->assertSame('Interiors / contractors', $profile['label']);
        $this->assertNotEmpty($profile['stage_mapping']);
        $this->assertNotEmpty($profile['task_templates']);
        $this->assertSame('Inquiry', $profile['stage_mapping'][0]['label']);
    }

    public function testWhatsAppHeavySmbProfileIncludesChatFirstDefaults(): void
    {
        $catalog = new ClarityPackageCatalog();
        $profile = $catalog->getNicheProfile(ClarityPackageCatalog::NICHE_WHATSAPP_HEAVY_SMB);

        $this->assertSame('WhatsApp-heavy businesses', $profile['label']);
        $this->assertSame('whatsapp_heavy_smb', $profile['demo_seed_profile']);
        $this->assertSame('New WhatsApp inquiry', $profile['stage_mapping'][0]['label']);
        $this->assertSame('Respond to new WhatsApp inquiry within SLA', $profile['task_templates'][0]['title']);
        $this->assertSame('WhatsApp Inquiry Handling', $profile['product_templates'][0]['name']);
        $this->assertSame('Lead Intent', $profile['custom_fields'][2]['field_name']);
    }

    public function testAgenciesProfileIncludesProposalAndKickoffDefaults(): void
    {
        $catalog = new ClarityPackageCatalog();
        $profile = $catalog->getNicheProfile(ClarityPackageCatalog::NICHE_AGENCIES);

        $this->assertSame('Agencies', $profile['label']);
        $this->assertSame('agencies', $profile['demo_seed_profile']);
        $this->assertSame('Proposal / scope sent', $profile['stage_mapping'][2]['label']);
        $this->assertSame('Follow up on proposal after 48 hours', $profile['task_templates'][1]['title']);
        $this->assertSame('Proposal and Scope Workflow', $profile['product_templates'][1]['name']);
        $this->assertSame('Kickoff Date', $profile['custom_fields'][2]['field_name']);
    }

    public function testDistributorsWholesalersProfileIncludesReorderDefaults(): void
    {
        $catalog = new ClarityPackageCatalog();
        $profile = $catalog->getNicheProfile(ClarityPackageCatalog::NICHE_DISTRIBUTORS_WHOLESALERS);

        $this->assertSame('Distributors / wholesalers', $profile['label']);
        $this->assertSame('distributors_wholesalers', $profile['demo_seed_profile']);
        $this->assertSame('Price list / quote shared', $profile['stage_mapping'][2]['label']);
        $this->assertSame('Follow up after price list is shared', $profile['task_templates'][1]['title']);
        $this->assertSame('Repeat Buyer Reorder Workflow', $profile['product_templates'][1]['name']);
        $this->assertSame('Reorder Window', $profile['custom_fields'][2]['field_name']);
    }
}
