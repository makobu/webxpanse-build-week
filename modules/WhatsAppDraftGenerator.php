<?php
/**
 * WhatsApp Draft Generator Module
 * 
 * AI-powered WhatsApp message draft generation
 */

namespace CRM\Modules;

use CRM\Auth;
use CRM\Services\AIService;
use CRM\Services\CustomerReplyAssistantService;
use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Modules\Activities;
use CRM\Modules\CompanyProfile;
use CRM\Modules\Products;

class WhatsAppDraftGenerator
{
    private AIService $aiService;
    private CustomerReplyAssistantService $customerReplyAssistant;
    private Contacts $contacts;
    private Activities $activities;
    private CompanyProfile $companyProfile;
    private Products $products;
    
    public function __construct()
    {
        $this->aiService = new AIService();
        $this->customerReplyAssistant = new CustomerReplyAssistantService();
        $this->contacts = new Contacts();
        $this->activities = new Activities();
        $this->companyProfile = new CompanyProfile();
        $this->products = new Products();
    }
    
    /**
     * Generate WhatsApp message draft
     */
    public function generateDraft(int $contactId, string $purpose, string $tone = 'casual', array $options = []): array
    {
        $userId = (int) (Auth::user()['id'] ?? 0);
        if ($userId > 0) {
            try {
                $reply = $this->customerReplyAssistant->generateDraftFromContact($contactId, 'whatsapp', $userId, [
                    'surface' => (string) ($options['surface'] ?? 'legacy_whatsapp_generator'),
                    'goal' => 'draft',
                    'purpose' => $purpose,
                    'tone' => $tone,
                ]);
                if (!empty($reply['draft']) && trim((string) ($reply['draft']['plain_body'] ?? '')) !== '') {
                    return [
                        'message' => (string) ($reply['draft']['plain_body'] ?? ''),
                        'body_html' => (string) ($reply['draft']['html_body'] ?? ''),
                        'body_text' => (string) ($reply['draft']['plain_body'] ?? ''),
                        'tone' => $tone,
                        'personalized' => true,
                        'suggestions' => [],
                        'source' => (string) ($reply['source'] ?? 'fallback'),
                        'policy' => (array) ($reply['policy'] ?? []),
                        'summary_text' => (string) ($reply['summary_text'] ?? ''),
                        'fallback' => (bool) ($reply['fallback'] ?? false),
                        'warning' => $reply['warning'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
            }
        }

        return $this->generateLegacyDraft($contactId, $purpose, $tone, $options);
    }

    /**
     * Legacy WhatsApp draft path kept as compatibility fallback.
     */
    private function generateLegacyDraft(int $contactId, string $purpose, string $tone = 'casual', array $options = []): array
    {
        // Get contact context
        $contact = $this->contacts->getById($contactId);
        if (!$contact) {
            throw new \Exception("Contact not found");
        }
        
        // Get company profile
        $companyProfile = $this->companyProfile->get();
        
        // Get products/services (for WhatsApp, we'll focus on most relevant)
        $allProducts = $this->products->list();
        
        // Get recent activities
        $recentActivities = $this->activities->getByContact($contactId, 3, 0);
        
        // Load AI context if available
        $aiContext = null;
        if (!empty($contact['ai_context'])) {
            $aiContext = json_decode($contact['ai_context'], true);
        }
        
        // Build context (WhatsApp messages are typically shorter and more casual)
        $context = [
            'our_company' => $companyProfile ? [
                'name' => $companyProfile['company_name'] ?? 'Our Company',
                'tagline' => $companyProfile['company_tagline'] ?? '',
                'description' => $companyProfile['company_description'] ?? '',
                'owner_additional_context' => $companyProfile['owner_company_context'] ?? '',
                'website' => $companyProfile['company_website'] ?? ''
            ] : null,
            'our_products' => array_map(function($product) {
                return [
                    'name' => $product['name'],
                    'description' => substr($product['description'] ?? '', 0, 150),
                    'category' => $product['category']
                ];
            }, array_slice($allProducts, 0, 5)), // Limit to 5 most relevant for WhatsApp
            'contact' => [
                'name' => trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')),
                'first_name' => $contact['first_name'] ?? '',
                'phone' => $contact['phone'] ?? '',
                'company' => $contact['company'] ?? '',
                'job_title' => $contact['job_title'] ?? '',
                'location' => $contact['location'] ?? '',
                'timezone' => $contact['timezone'] ?? '',
                'stage' => $contact['stage'] ?? 'new'
            ],
            'business_profile' => [
                'company_industry' => $contact['company_industry'] ?? '',
                'company_size' => $contact['company_size'] ?? ''
            ],
            'recent_activities' => array_map(function($activity) {
                return [
                    'type' => $activity['activity_type'],
                    'description' => substr($activity['description'] ?? '', 0, 100),
                    'date' => $activity['created_at']
                ];
            }, array_slice($recentActivities, 0, 2)),
            'purpose' => $purpose,
            'tone' => $tone
        ];
        
        // Add AI context insights if available
        if ($aiContext) {
            $context['ai_insights'] = $aiContext['insights'] ?? [];
            $context['ai_recommendations'] = $aiContext['recommendations'] ?? [];
            $context['ai_summary'] = $aiContext['summary'] ?? '';
        }
        
        // Generate draft using AI
        $draft = $this->aiService->process('whatsapp_draft', [
            'context' => $context,
            'purpose' => $purpose,
            'tone' => $tone,
            'options' => $options
        ]);
        
        // Parse AI response
        $draftData = json_decode($draft, true);
        if (!$draftData) {
            $draftData = [
                'message' => $draft,
                'tone' => $tone
            ];
        }
        
        return [
            'message' => $draftData['message'] ?? $draft,
            'tone' => $tone,
            'personalized' => true,
            'suggestions' => $draftData['suggestions'] ?? [],
            'source' => 'legacy_generator',
        ];
    }
    
    /**
     * Generate quick reply suggestions
     */
    public function getQuickReplies(int $contactId, string $incomingMessage): array
    {
        $contact = $this->contacts->getById($contactId);
        if (!$contact) {
            return [];
        }
        
        return $this->aiService->process('whatsapp_quick_replies', [
            'message' => $incomingMessage,
            'contact' => $contact
        ]);
    }
}
