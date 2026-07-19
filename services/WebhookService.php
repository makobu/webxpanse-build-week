<?php
/**
 * Webhook Service
 * 
 * Integrates webhooks with the event system
 */

namespace CRM\Services;

use CRM\Modules\Webhooks;
use CRM\EventBus;

class WebhookService
{
    private Webhooks $webhooks;
    
    public function __construct()
    {
        $this->webhooks = new Webhooks();
        $this->subscribeToEvents();
    }
    
    /**
     * Subscribe to all relevant events
     */
    private function subscribeToEvents(): void
    {
        // Contact events
        EventBus::subscribe('contact.created', function($data) {
            $this->webhooks->trigger('contact.created', $data);
        });
        
        EventBus::subscribe('contact.updated', function($data) {
            $this->webhooks->trigger('contact.updated', $data);
        });
        
        EventBus::subscribe('contact.deleted', function($data) {
            $this->webhooks->trigger('contact.deleted', $data);
        });
        
        // Deal events
        EventBus::subscribe('deal.created', function($data) {
            $this->webhooks->trigger('deal.created', $data);
        });
        
        EventBus::subscribe('deal.updated', function($data) {
            $this->webhooks->trigger('deal.updated', $data);
        });
        
        EventBus::subscribe('deal.won', function($data) {
            $this->webhooks->trigger('deal.won', $data);
        });
        
        EventBus::subscribe('deal.lost', function($data) {
            $this->webhooks->trigger('deal.lost', $data);
        });
        
        // Task events
        EventBus::subscribe('task.created', function($data) {
            $this->webhooks->trigger('task.created', $data);
        });
        
        EventBus::subscribe('task.completed', function($data) {
            $this->webhooks->trigger('task.completed', $data);
        });
        
        EventBus::subscribe('task.overdue', function($data) {
            $this->webhooks->trigger('task.overdue', $data);
        });
        
        // Event/Calendar events
        EventBus::subscribe('event.created', function($data) {
            $this->webhooks->trigger('event.created', $data);
        });
        
        EventBus::subscribe('event.updated', function($data) {
            $this->webhooks->trigger('event.updated', $data);
        });
        
        // Email events
        EventBus::subscribe('email.sent', function($data) {
            $this->webhooks->trigger('email.sent', $data);
        });
        
        EventBus::subscribe('email.opened', function($data) {
            $this->webhooks->trigger('email.opened', $data);
        });
        
        EventBus::subscribe('email.clicked', function($data) {
            $this->webhooks->trigger('email.clicked', $data);
        });
        
        // Form events
        EventBus::subscribe('form.submitted', function($data) {
            $this->webhooks->trigger('form.submitted', $data);
        });
        
        // Workflow events
        EventBus::subscribe('workflow.triggered', function($data) {
            $this->webhooks->trigger('workflow.triggered', $data);
        });
    }
}
