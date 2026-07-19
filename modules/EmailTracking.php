<?php
/**
 * Email Tracking Module
 * 
 * Handles email open and click tracking
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\EmailTemplateLearningSampleService;
use CRM\Services\TouchpointIngestionService;

class EmailTracking
{
    private TouchpointIngestionService $touchpointIngestion;

    public function __construct()
    {
        $this->touchpointIngestion = new TouchpointIngestionService();
    }

    /**
     * Track email open
     */
    public function trackOpen(string $emailUuid): void
    {
        $email = Database::queryOne(
            "SELECT e.*, c.id as contact_id 
             FROM emails e 
             LEFT JOIN contacts c ON e.contact_id = c.id 
             WHERE e.uuid = ?",
            [$emailUuid]
        );
        
        if (!$email) {
            return;
        }
        
        // Check if already opened
        $existing = Database::queryOne(
            "SELECT id FROM email_tracking WHERE email_id = ? AND tracking_type = 'open'",
            [$email['id']]
        );
        
        if ($existing) {
            return; // Already tracked
        }
        
        // Insert tracking record
        Database::execute(
            "INSERT INTO email_tracking (email_id, tracking_type, ip_address, user_agent) 
             VALUES (?, 'open', ?, ?)",
            [
                $email['id'],
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]
        );
        
        // Update email status
        Database::execute(
            "UPDATE emails SET status = 'opened', opened_at = NOW() WHERE id = ? AND opened_at IS NULL",
            [$email['id']]
        );
        (new EmailTemplateLearningSampleService())->markEmailEngagement((int) $email['id'], 'open');
        
        // Publish event for workflow triggers
        if (isset($email['contact_id']) && $email['contact_id']) {
            $this->touchpointIngestion->ingestTouchpoint([
                'contact_id' => (int) $email['contact_id'],
                'campaign_id' => !empty($email['campaign_id']) ? (int) $email['campaign_id'] : null,
                'source_table' => 'emails',
                'source_id' => (int) $email['id'],
                'channel' => 'email',
                'touch_type' => 'email_opened',
                'metadata' => ['email_uuid' => $emailUuid]
            ]);

            \CRM\EventBus::publish('email.opened', [
                'email_id' => $email['id'],
                'contact_id' => $email['contact_id'],
                'email_uuid' => $emailUuid,
                'opened_at' => date('Y-m-d H:i:s')
            ]);
        }
    }
    
    /**
     * Track email click
     */
public function trackClick(string $emailUuid, string $url): void
    {
        $email = Database::queryOne(
            "SELECT e.*, c.id as contact_id 
             FROM emails e 
             LEFT JOIN contacts c ON e.contact_id = c.id 
             WHERE e.uuid = ?",
            [$emailUuid]
        );
        
        if (!$email) {
            return;
        }
        
        // Insert tracking record
        Database::execute(
            "INSERT INTO email_tracking (email_id, tracking_type, clicked_url, ip_address, user_agent) 
             VALUES (?, 'click', ?, ?, ?)",
            [
                $email['id'],
                $url,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]
        );
        
        // Update email status
        Database::execute(
            "UPDATE emails SET status = 'clicked', clicked_at = NOW() WHERE id = ? AND clicked_at IS NULL",
            [$email['id']]
        );
        (new EmailTemplateLearningSampleService())->markEmailEngagement((int) $email['id'], 'click');
        
        // Publish event for workflow triggers
        if (isset($email['contact_id']) && $email['contact_id']) {
            $this->touchpointIngestion->ingestTouchpoint([
                'contact_id' => (int) $email['contact_id'],
                'campaign_id' => !empty($email['campaign_id']) ? (int) $email['campaign_id'] : null,
                'source_table' => 'emails',
                'source_id' => (int) $email['id'],
                'channel' => 'email',
                'touch_type' => 'email_clicked',
                'metadata' => ['email_uuid' => $emailUuid, 'url' => $url]
            ]);

            \CRM\EventBus::publish('email.clicked', [
                'email_id' => $email['id'],
                'contact_id' => $email['contact_id'],
                'clicked_url' => $url,
                'email_uuid' => $emailUuid
            ]);
        }
    }
    
    /**
     * Get email tracking stats
     */
    public function getStats(int $emailId): array
    {
        $opens = Database::queryOne(
            "SELECT COUNT(*) as count FROM email_tracking WHERE email_id = ? AND tracking_type = 'open'",
            [$emailId]
        );
        
        $clicks = Database::queryOne(
            "SELECT COUNT(*) as count FROM email_tracking WHERE email_id = ? AND tracking_type = 'click'",
            [$emailId]
        );
        
        return [
            'opens' => (int) ($opens['count'] ?? 0),
            'clicks' => (int) ($clicks['count'] ?? 0)
        ];
    }
}
