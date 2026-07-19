<?php
/**
 * UTM Parameter Capture Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Tracking;
use CRM\Database;

class UTMCaptureTest extends DatabaseTestCase
{
    private Tracking $tracking;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->tracking = new Tracking();
    }
    
    public function testUTMParametersAreCaptured(): void
    {
        $visitorId = 'utm_test_' . uniqid();
        
        $data = [
            'visitor_id' => $visitorId,
            'page' => '/test',
            'utm' => [
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'summer_sale',
                'utm_term' => 'crm software',
                'utm_content' => 'ad_variant_1'
            ]
        ];
        
        $this->tracking->trackPageView($data);
        
        // Verify UTM parameters were stored
        $pageView = Database::queryOne(
            "SELECT * FROM page_views WHERE visitor_id = ? ORDER BY viewed_at DESC LIMIT 1",
            [$visitorId]
        );
        
        $this->assertNotNull($pageView);
        $this->assertEquals('google', $pageView['utm_source']);
        $this->assertEquals('cpc', $pageView['utm_medium']);
        $this->assertEquals('summer_sale', $pageView['utm_campaign']);
        $this->assertEquals('crm software', $pageView['utm_term']);
        $this->assertEquals('ad_variant_1', $pageView['utm_content']);
    }
    
    public function testPartialUTMParameters(): void
    {
        $visitorId = 'utm_partial_' . uniqid();
        
        $data = [
            'visitor_id' => $visitorId,
            'page' => '/test',
            'utm' => [
                'utm_source' => 'facebook',
                'utm_medium' => 'social'
            ]
        ];
        
        $this->tracking->trackPageView($data);
        
        $pageView = Database::queryOne(
            "SELECT * FROM page_views WHERE visitor_id = ? ORDER BY viewed_at DESC LIMIT 1",
            [$visitorId]
        );
        
        $this->assertNotNull($pageView);
        $this->assertEquals('facebook', $pageView['utm_source']);
        $this->assertEquals('social', $pageView['utm_medium']);
        $this->assertNull($pageView['utm_campaign']);
    }
}
