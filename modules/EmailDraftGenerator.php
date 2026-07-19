<?php
/**
 * Email Draft Generator Module
 * 
 * AI-powered email draft generation with context awareness, tone adjustment, and personalization
 */

namespace CRM\Modules;

use CRM\Auth;
use CRM\Services\AIService;
use CRM\Services\AIExecutionStatusService;
use CRM\Services\CustomerReplyAssistantService;
use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Modules\Activities;
use CRM\Modules\CompanyProfile;
use CRM\Modules\Products;
use CRM\Modules\Deals;
use CRM\Modules\Notes;
use CRM\Modules\Tags;
use CRM\Services\EmailDraftOutputNormalizer;

class EmailDraftGenerator
{
    private AIService $aiService;
    private CustomerReplyAssistantService $customerReplyAssistant;
    private Contacts $contacts;
    private Activities $activities;
    private CompanyProfile $companyProfile;
    private Products $products;
    private Deals $deals;
    private Notes $notes;
    private Tags $tags;
    private EmailDraftOutputNormalizer $draftOutputNormalizer;
    
    public function __construct()
    {
        $this->aiService = new AIService();
        $this->customerReplyAssistant = new CustomerReplyAssistantService();
        $this->contacts = new Contacts();
        $this->activities = new Activities();
        $this->companyProfile = new CompanyProfile();
        $this->products = new Products();
        $this->deals = new Deals();
        $this->notes = new Notes();
        $this->tags = new Tags();
        $this->draftOutputNormalizer = new EmailDraftOutputNormalizer();
    }
    
    /**
     * Generate email draft with context awareness
     *
     * @param array|null $threadContext Optional. When replying to inbox thread: [{ subject, body, direction, created_at }, ...]
     */
    public function generateDraft(int $contactId, string $purpose, string $tone = 'professional', array $options = [], ?array $threadContext = null): array
    {
        $userId = (int) (Auth::user()['id'] ?? 0);
        $surface = (string) ($options['surface'] ?? 'legacy_email_generator');

        if ($userId > 0) {
            try {
                $reply = $this->customerReplyAssistant->generateDraftFromContact($contactId, 'email', $userId, [
                    'surface' => $surface,
                    'goal' => 'draft',
                    'purpose' => $purpose,
                    'tone' => $tone,
                ]);
                $shouldUseAssistantDraft = !empty($reply['draft']) && !$this->shouldPreferLegacyComposeFallback($reply, $surface);
                if ($shouldUseAssistantDraft && (
                    trim((string) ($reply['draft']['plain_body'] ?? '')) !== ''
                    || trim((string) ($reply['draft']['html_body'] ?? '')) !== ''
                )) {
                    $normalizedDraft = $this->draftOutputNormalizer->normalizeCanonicalDraft((array) ($reply['draft'] ?? []));
                    $bodyHtml = (string) ($normalizedDraft['body_html'] ?? $normalizedDraft['html_body'] ?? '');
                    $bodyText = (string) ($normalizedDraft['body_text'] ?? $normalizedDraft['plain_body'] ?? '');

                    return [
                        'subject' => (string) ($normalizedDraft['subject'] ?? $this->generateSubject($purpose, $this->contacts->getById($contactId) ?: [])),
                        'body_html' => $bodyHtml,
                        'body_text' => $bodyText,
                        'signature_id' => !empty($reply['draft']['signature_id']) ? (int) $reply['draft']['signature_id'] : null,
                        'signature_html' => (string) ($reply['draft']['signature_html'] ?? ''),
                        'signature_text' => (string) ($reply['draft']['signature_text'] ?? ''),
                        'body_includes_signature' => (bool) ($reply['draft']['body_includes_signature'] ?? false),
                        'tone' => $tone,
                        'personalized' => true,
                        'suggestions' => [],
                        'source' => (string) ($reply['source'] ?? 'email_assistant_v2'),
                        'policy' => (array) ($reply['policy'] ?? []),
                        'summary_text' => (string) ($reply['summary_text'] ?? ''),
                        'fallback' => (bool) ($reply['fallback'] ?? false),
                        'warning' => $reply['warning'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
            }
        }

        return $this->generateLegacyDraft($contactId, $purpose, $tone, $options, $threadContext);
    }

    public function generateDraftFromIntention(?int $contactId, string $intention, string $tone = 'professional', array $options = []): array
    {
        $intention = trim($intention);
        $mode = (string) ($options['mode'] ?? 'draft_from_intention');
        $currentBody = trim((string) ($options['current_body'] ?? ''));
        if ($intention === '' && $currentBody === '') {
            throw new \InvalidArgumentException('Tell the AI what you want to say, or provide an existing draft to polish.');
        }

        $contact = null;
        if ($contactId !== null && $contactId > 0) {
            $contact = $this->contacts->getById($contactId) ?: null;
        }
        $companyProfile = $this->companyProfile->get();
        $context = [
            'our_company' => $companyProfile ? [
                'name' => $companyProfile['company_name'] ?? brandProductName(),
                'tagline' => $companyProfile['company_tagline'] ?? '',
                'description' => $companyProfile['company_description'] ?? '',
                'mission' => $companyProfile['company_mission'] ?? '',
                'values' => $companyProfile['company_values'] ?? '',
                'industry' => $companyProfile['company_industry'] ?? '',
                'website' => $companyProfile['company_website'] ?? '',
            ] : null,
            'contact' => $contact ? [
                'name' => trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')),
                'email' => $contact['email'] ?? '',
                'company' => $contact['company'] ?? '',
                'job_title' => $contact['job_title'] ?? '',
                'stage' => $contact['stage'] ?? '',
                'lead_source' => $contact['lead_source'] ?? '',
            ] : null,
            'purpose' => (string) ($options['purpose'] ?? 'custom_intention'),
            'tone' => $tone,
        ];

        $draftOptions = $options;
        $draftOptions['intention'] = $intention;
        $draftOptions['current_body'] = $currentBody;
        $draftOptions['mode'] = $mode;
        $draftOptions['surface'] = (string) ($options['surface'] ?? 'email_compose');

        $draftData = [];
        $usedFallback = false;
        $providerStatus = [];
        try {
            $draft = $this->aiService->process('email_draft', [
                'context' => $context,
                'purpose' => (string) ($options['purpose'] ?? 'custom_intention'),
                'tone' => $tone,
                'options' => $draftOptions,
            ]);
            $normalizedDraft = $this->draftOutputNormalizer->normalizeEmailDraftPayload($draft, [
                'subject' => $this->generateIntentionSubject($intention, $currentBody, $contact ?: []),
            ]);
            $draftData = json_decode((string) $draft, true);
            $providerStatus = $this->aiService->getLastProviderStatus();
            if (!is_array($draftData)) {
                $draftData = [];
            }
            $bodyHtml = (string) ($normalizedDraft['body_html'] ?? '');
            $bodyText = (string) ($normalizedDraft['body_text'] ?? '');
        } catch (\Throwable $e) {
            $providerStatus = $this->aiService->getLastProviderStatus();
            $bodyHtml = '';
            $bodyText = '';
        }

        if ($this->isLowSignalDraft($bodyText, $bodyHtml)) {
            $usedFallback = true;
            $draftData = array_merge(
                $draftData,
                $this->buildIntentionFallbackDraft($contact, $intention, $tone, $draftOptions)
            );
            $bodyHtml = (string) $draftData['body_html'];
            $bodyText = (string) $draftData['body_text'];
        }

        $userId = (int) (Auth::user()['id'] ?? 0);
        $formattedDraft = $this->customerReplyAssistant->formatDraftForChannel([
            'subject' => (string) ($draftData['subject'] ?? $this->generateIntentionSubject($intention, $currentBody, $contact ?: [])),
            'plain_body' => $bodyText,
            'html_body' => $bodyHtml,
        ], $this->customerReplyAssistant->resolveDraftStyleContext($userId, 'email', [
            'tone' => $tone,
        ]), 'email', [
            'purpose' => (string) ($options['purpose'] ?? 'custom_intention'),
            'contact' => $contact ?: [],
        ]);

        $aiStatus = (new AIExecutionStatusService())->present($providerStatus, [
            'surface' => 'assistant',
            'fallback' => $usedFallback,
            'source' => $usedFallback ? 'deterministic_fallback' : '',
            'message' => $usedFallback
                ? 'A safe draft was prepared without a usable provider response.'
                : '',
        ]);

        return [
            'subject' => (string) ($formattedDraft['subject'] ?? $draftData['subject'] ?? $this->generateIntentionSubject($intention, $currentBody, $contact ?: [])),
            'body_html' => (string) ($formattedDraft['body_html'] ?? $formattedDraft['html_body'] ?? $bodyHtml),
            'body_text' => (string) ($formattedDraft['body_text'] ?? $formattedDraft['plain_body'] ?? $bodyText),
            'signature_id' => !empty($formattedDraft['signature_id']) ? (int) $formattedDraft['signature_id'] : null,
            'signature_html' => (string) ($formattedDraft['signature_html'] ?? ''),
            'signature_text' => (string) ($formattedDraft['signature_text'] ?? ''),
            'body_includes_signature' => (bool) ($formattedDraft['body_includes_signature'] ?? false),
            'tone' => $tone,
            'personalized' => $contact !== null,
            'suggestions' => $draftData['suggestions'] ?? [],
            'source' => $usedFallback
                ? 'deterministic_fallback'
                : ($mode === 'polish_existing' ? 'ai_polish_draft' : 'ai_intention_draft'),
            'draft_mode' => $mode,
            'fallback' => $usedFallback,
            'ai_status' => $aiStatus,
        ];
    }

    /**
     * Legacy AI draft path kept as compatibility fallback.
     *
     * @param array|null $threadContext Optional. When replying to inbox thread: [{ subject, body, direction, created_at }, ...]
     */
    private function generateLegacyDraft(int $contactId, string $purpose, string $tone = 'professional', array $options = [], ?array $threadContext = null): array
    {
        $draftUserId = (int) (Auth::user()['id'] ?? 0);
        // Get contact context
        $contact = $this->contacts->getById($contactId);
        if (!$contact) {
            throw new \Exception("Contact not found");
        }
        
        // Get company profile
        $companyProfile = $this->companyProfile->get();
        
        // Get products/services
        $allProducts = $this->products->list();
        
        // Get deals for this contact
        $contactDeals = Database::query(
            "SELECT title, value, stage, created_at 
             FROM deals 
             WHERE contact_id = ? 
             ORDER BY created_at DESC 
             LIMIT 3",
            [$contactId]
        );
        
        // Get notes for this contact
        $contactNotes = Database::query(
            "SELECT title, content, created_at 
             FROM notes 
             WHERE entity_type = 'contact' AND entity_id = ? 
             ORDER BY created_at DESC 
             LIMIT 5",
            [$contactId]
        );
        
        // Get tags for this contact
        $contactTags = Database::query(
            "SELECT t.name 
             FROM tags t
             INNER JOIN tag_assignments ta ON t.id = ta.tag_id
             WHERE ta.entity_type = 'contact' AND ta.entity_id = ?",
            [$contactId]
        );
        
        // Get recent activities for context
        $recentActivities = $this->activities->getByContact($contactId, 5, 0);
        
        // Get communication history (use thread context if provided for inbox reply)
        if ($threadContext !== null) {
            $emails = array_map(fn($m) => [
                'subject' => $m['subject'] ?? '',
                'body_html' => $m['body'] ?? '',
                'created_at' => $m['created_at'] ?? '',
            ], $threadContext);
        } else {
            $emails = Database::query(
                "SELECT subject, body_html, created_at 
                 FROM emails 
                 WHERE contact_id = ? 
                 ORDER BY created_at DESC 
                 LIMIT 3",
                [$contactId]
            );
        }
        
        // Load AI context if available
        $aiContext = null;
        if (!empty($contact['ai_context'])) {
            $aiContext = json_decode($contact['ai_context'], true);
        }
        
        // Build comprehensive context for AI
        $context = [
            'our_company' => $companyProfile ? [
                'name' => $companyProfile['company_name'] ?? 'Our Company',
                'tagline' => $companyProfile['company_tagline'] ?? '',
                'description' => $companyProfile['company_description'] ?? '',
                'mission' => $companyProfile['company_mission'] ?? '',
                'values' => $companyProfile['company_values'] ?? '',
                'owner_additional_context' => $companyProfile['owner_company_context'] ?? '',
                'website' => $companyProfile['company_website'] ?? '',
                'industry' => $companyProfile['company_industry'] ?? '',
                'location' => $companyProfile['company_location'] ?? '',
                'timezone' => $companyProfile['company_timezone'] ?? ''
            ] : null,
            'our_products' => array_map(function($product) {
                return [
                    'name' => $product['name'],
                    'description' => $product['description'],
                    'category' => $product['category'],
                    'features' => json_decode($product['features'] ?? '[]', true),
                    'benefits' => $product['benefits'],
                    'target_audience' => $product['target_audience'],
                    'use_cases' => $product['use_cases']
                ];
            }, $allProducts),
            'contact' => [
                'name' => trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')),
                'email' => $contact['email'],
                'phone' => $contact['phone'] ?? '',
                'company' => $contact['company'] ?? '',
                'job_title' => $contact['job_title'] ?? '',
                'location' => $contact['location'] ?? '',
                'timezone' => $contact['timezone'] ?? '',
                'stage' => $contact['stage'] ?? 'new',
                'lead_score' => $contact['lead_score'] ?? 0,
                'ml_score' => $contact['ml_score'] ?? null,
                'lead_source' => $contact['lead_source'] ?? 'form'
            ],
            'business_profile' => [
                'company_industry' => $contact['company_industry'] ?? '',
                'company_size' => $contact['company_size'] ?? '',
                'company_revenue' => $contact['company_revenue'] ?? '',
                'company_description' => $contact['company_description'] ?? '',
                'company_founded' => $contact['company_founded'] ?? null,
                'company_website' => $contact['company_website'] ?? '',
                'linkedin_url' => $contact['linkedin_url'] ?? '',
                'twitter_url' => $contact['twitter_url'] ?? ''
            ],
            'relationship_context' => [
                'tags' => array_column($contactTags, 'name'),
                'deals' => array_map(function($deal) {
                    return [
                        'name' => $deal['title'],
                        'value' => $deal['value'],
                        'stage' => $deal['stage'],
                        'date' => $deal['created_at']
                    ];
                }, $contactDeals),
                'notes' => array_map(function($note) {
                    return [
                        'title' => $note['title'],
                        'content' => substr(strip_tags($note['content'] ?? ''), 0, 200),
                        'date' => $note['created_at']
                    ];
                }, $contactNotes)
            ],
            'recent_activities' => array_map(function($activity) {
                return [
                    'type' => $activity['activity_type'],
                    'description' => $activity['description'],
                    'date' => $activity['created_at']
                ];
            }, $recentActivities),
            'communication_history' => array_map(function($email) {
                return [
                    'subject' => $email['subject'],
                    'date' => $email['created_at']
                ];
            }, $emails),
            'purpose' => $purpose,
            'tone' => $tone
        ];
        
        // Add AI context insights if available
        if ($aiContext) {
            $context['ai_insights'] = $aiContext['insights'] ?? [];
            $context['ai_recommendations'] = $aiContext['recommendations'] ?? [];
            $context['ai_summary'] = $aiContext['summary'] ?? '';
            $context['ai_analysis'] = $aiContext['analysis'] ?? [];
        }
        
        // Generate draft using AI
        $draft = $this->aiService->process('email_draft', [
            'context' => $context,
            'purpose' => $purpose,
            'tone' => $tone,
            'options' => $options
        ]);
        
        // Parse AI response (assuming JSON format)
        $normalizedDraft = $this->draftOutputNormalizer->normalizeEmailDraftPayload($draft, [
            'subject' => $this->generateSubject($purpose, $contact),
        ]);
        $draftData = json_decode((string) $draft, true);
        if (!is_array($draftData)) {
            $draftData = [];
        }

        $bodyHtml = (string) ($normalizedDraft['body_html'] ?? '');
        $bodyText = (string) ($normalizedDraft['body_text'] ?? '');

        if ($this->isLowSignalDraft($bodyText, $bodyHtml)) {
            $structuredFallback = $this->buildStructuredFallbackDraft($contact, $purpose, $tone, $options);
            $draftData = array_merge($draftData, $structuredFallback);
            $bodyHtml = $structuredFallback['body_html'];
            $bodyText = $structuredFallback['body_text'];
        }

        $draftData = $this->normalizeLegacyBusinessGrounding($draftData, $contact, $companyProfile, $purpose);
        $bodyHtml = (string) ($draftData['body_html'] ?? $bodyHtml);
        $bodyText = (string) ($draftData['body_text'] ?? $bodyText);

        $formattedDraft = $this->customerReplyAssistant->formatDraftForChannel([
            'subject' => (string) ($draftData['subject'] ?? $normalizedDraft['subject'] ?? $this->generateSubject($purpose, $contact)),
            'plain_body' => $bodyText,
            'html_body' => $bodyHtml,
        ], $this->customerReplyAssistant->resolveDraftStyleContext($draftUserId, 'email', [
            'tone' => $tone,
        ]), 'email', [
            'purpose' => $purpose,
            'contact' => $contact,
        ]);

        return [
            'subject' => (string) ($formattedDraft['subject'] ?? $draftData['subject'] ?? $this->generateSubject($purpose, $contact)),
            'body_html' => (string) ($formattedDraft['body_html'] ?? $formattedDraft['html_body'] ?? $bodyHtml),
            'body_text' => (string) ($formattedDraft['body_text'] ?? $formattedDraft['plain_body'] ?? $bodyText),
            'signature_id' => !empty($formattedDraft['signature_id']) ? (int) $formattedDraft['signature_id'] : null,
            'signature_html' => (string) ($formattedDraft['signature_html'] ?? ''),
            'signature_text' => (string) ($formattedDraft['signature_text'] ?? ''),
            'body_includes_signature' => (bool) ($formattedDraft['body_includes_signature'] ?? false),
            'tone' => $tone,
            'personalized' => true,
            'suggestions' => $draftData['suggestions'] ?? [],
            'source' => 'legacy_generator',
        ];
    }

    private function isLowSignalDraft(string $bodyText, string $bodyHtml): bool
    {
        $plain = strtolower(trim(strip_tags($bodyText !== '' ? $bodyText : $bodyHtml)));
        if ($plain === '') {
            return true;
        }

        $normalized = preg_replace('/\s+/', ' ', $plain);
        $genericMessages = [
            'thank you for your message. i will follow up shortly.',
            'thank you for your message. i have reviewed it and will follow up shortly.',
            'thanks for your message. i will get back to you shortly.',
            'thanks for your message. i have noted it and will get back to you shortly.',
        ];

        return in_array($normalized, $genericMessages, true) || strlen($normalized) < 40;
    }

    private function buildStructuredFallbackDraft(array $contact, string $purpose, string $tone, array $options): array
    {
        $firstName = trim((string) ($contact['first_name'] ?? ''));
        $fullName = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
        $greetingName = $firstName !== '' ? $firstName : ($fullName !== '' ? $fullName : 'there');
        $company = trim((string) ($contact['company'] ?? ''));
        $cta = trim((string) ($options['call_to_action'] ?? ''));
        $customInstructions = trim((string) ($options['custom_instructions'] ?? ''));
        $points = array_values(array_filter(array_map('trim', (array) ($options['include_points'] ?? []))));
        $style = trim((string) ($options['style'] ?? ''));

        $intro = match ($purpose) {
            'welcome' => "Welcome to our services. I wanted to introduce myself and share how we can support you.",
            'proposal' => $company !== ''
                ? "I am following up with a proposal tailored for {$company} based on your needs."
                : "I am following up with a proposal tailored to what you shared with us.",
            'meeting' => "I would love to schedule a time for us to connect and discuss the next steps.",
            'thank_you' => "Thank you for your time and for the opportunity to connect.",
            'follow_up' => "I wanted to follow up on our recent conversation and keep things moving.",
            default => "I wanted to reach out with a quick follow-up.",
        };

        $toneLine = match ($tone) {
            'friendly' => "I hope you are doing well.",
            'formal' => "I trust you are well.",
            'casual' => "Hope you are having a good day.",
            default => "I hope this message finds you well.",
        };

        $bodyParts = [
            "Hi {$greetingName},",
            '',
            $toneLine,
            $intro,
        ];

        if ($points !== []) {
            $bodyParts[] = '';
            $bodyParts[] = 'A few key points for you:';
            foreach ($points as $point) {
                $bodyParts[] = "- {$point}";
            }
        }

        if ($customInstructions !== '') {
            $bodyParts[] = '';
            $bodyParts[] = $customInstructions;
        }

        if ($cta !== '') {
            $bodyParts[] = '';
            $bodyParts[] = $cta;
        } else {
            $bodyParts[] = '';
            $bodyParts[] = match ($purpose) {
                'meeting' => 'Please share a convenient time for a quick call.',
                'proposal' => 'Please let me know if you would like me to walk you through the proposal in more detail.',
                'thank_you' => 'Please feel free to reach out if there is anything else you need from me.',
                default => 'Please let me know if you would like me to send more details or answer any questions.',
            };
        }

        if ($style === 'concise') {
            $bodyParts = array_slice($bodyParts, 0, min(count($bodyParts), 7));
        }

        $bodyParts[] = '';
        $bodyParts[] = 'Best regards,';

        $bodyText = trim(implode("\n", $bodyParts));

        return [
            'subject' => $this->generateSubject($purpose, $contact),
            'body_text' => $bodyText,
            'body_html' => nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')),
            'suggestions' => [],
        ];
    }

    private function buildIntentionFallbackDraft(?array $contact, string $intention, string $tone, array $options): array
    {
        $currentBody = trim((string) ($options['current_body'] ?? ''));
        $mode = (string) ($options['mode'] ?? 'draft_from_intention');
        $firstName = trim((string) ($contact['first_name'] ?? ''));
        $fullName = trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')));
        $greetingName = $firstName !== '' ? $firstName : ($fullName !== '' ? $fullName : 'there');
        $points = array_values(array_filter(array_map('trim', (array) ($options['include_points'] ?? []))));
        $cta = trim((string) ($options['call_to_action'] ?? ''));
        $customInstructions = trim((string) ($options['custom_instructions'] ?? ''));

        $bodyParts = ["Hi {$greetingName},"];
        $bodyParts[] = '';

        if ($mode === 'polish_existing' && $currentBody !== '') {
            $bodyParts[] = trim($currentBody);
        } else {
            $bodyParts[] = $intention !== ''
                ? $intention
                : 'I wanted to send a clear follow-up and keep the next step easy to respond to.';
        }

        if ($points !== []) {
            $bodyParts[] = '';
            $bodyParts[] = 'Key points:';
            foreach ($points as $point) {
                $bodyParts[] = '- ' . $point;
            }
        }

        if ($customInstructions !== '') {
            $bodyParts[] = '';
            $bodyParts[] = $customInstructions;
        }

        $bodyParts[] = '';
        $bodyParts[] = $cta !== '' ? $cta : 'Please let me know what works best for you.';
        $bodyParts[] = '';
        $bodyParts[] = match ($tone) {
            'formal' => 'Kind regards,',
            'casual' => 'Thanks,',
            default => 'Best regards,',
        };

        $bodyText = trim(implode("\n", $bodyParts));

        return [
            'subject' => $this->generateIntentionSubject($intention, $currentBody, $contact ?: []),
            'body_text' => $bodyText,
            'body_html' => nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')),
            'suggestions' => [],
        ];
    }
    
    /**
     * Generate subject line
     */
    private function generateSubject(string $purpose, array $contact): string
    {
        $name = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
        $firstName = $contact['first_name'] ?? 'there';
        $company = $contact['company'] ?? 'you';
        $companyProfile = $this->companyProfile->get() ?: [];
        $ourCompany = trim((string) (($companyProfile['company_name'] ?? '') ?: brandProductName()));

        $subjects = [
            'follow_up' => "Following up - {$name}",
            'welcome' => "Welcome to {$ourCompany}, {$firstName}!",
            'proposal' => "Proposal for {$company}",
            'meeting' => "Meeting request - {$name}",
            'thank_you' => "Thank you, {$firstName}!",
            'default' => "Re: Your inquiry"
        ];
        
        return $subjects[$purpose] ?? $subjects['default'];
    }

    private function generateIntentionSubject(string $intention, string $currentBody, array $contact): string
    {
        $source = trim($intention !== '' ? $intention : $currentBody);
        $source = preg_replace('/\s+/', ' ', strip_tags($source)) ?? $source;
        $company = trim((string) ($contact['company'] ?? ''));

        if ($source === '') {
            return $company !== '' ? 'Quick follow-up for ' . $company : 'Quick follow-up';
        }

        $source = rtrim($source, ".!? \t\n\r\0\x0B");
        if (strlen($source) > 58) {
            $source = substr($source, 0, 55) . '...';
        }

        return $source;
    }
    
    /**
     * Adjust tone of existing email
     */
    public function adjustTone(string $emailBody, string $currentTone, string $targetTone): string
    {
        return $this->aiService->process('tone_adjustment', [
            'text' => $emailBody,
            'current_tone' => $currentTone,
            'target_tone' => $targetTone
        ]);
    }
    
    /**
     * Personalize email template
     */
    public function personalizeTemplate(string $template, int $contactId): string
    {
        $contact = $this->contacts->getById($contactId);
        if (!$contact) {
            return $template;
        }
        
        // Replace template variables
        $replacements = [
            '{first_name}' => $contact['first_name'] ?? '',
            '{last_name}' => $contact['last_name'] ?? '',
            '{full_name}' => trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')),
            '{email}' => $contact['email'] ?? '',
            '{company}' => $contact['company'] ?? '',
            '{phone}' => $contact['phone'] ?? ''
        ];
        
        $personalized = str_replace(array_keys($replacements), array_values($replacements), $template);
        
        // Use AI for advanced personalization
        return $this->aiService->process('personalization', [
            'template' => $personalized,
            'contact' => $contact
        ]);
    }
    
    /**
     * Get draft suggestions based on contact stage
     */
    public function getSuggestions(int $contactId): array
    {
        $contact = $this->contacts->getById($contactId);
        if (!$contact) {
            return [];
        }
        
        $stage = $contact['stage'] ?? 'new';
        $suggestions = [];
        
        switch ($stage) {
            case 'new':
                $suggestions[] = [
                    'purpose' => 'welcome',
                    'tone' => 'friendly',
                    'description' => 'Welcome email for new contact'
                ];
                break;
            case 'contacted':
                $suggestions[] = [
                    'purpose' => 'follow_up',
                    'tone' => 'professional',
                    'description' => 'Follow-up email'
                ];
                break;
            case 'qualified':
                $suggestions[] = [
                    'purpose' => 'proposal',
                    'tone' => 'professional',
                    'description' => 'Send proposal'
                ];
                break;
        }
        
        return $suggestions;
    }

    private function shouldPreferLegacyComposeFallback(array $reply, string $surface): bool
    {
        if (!in_array($surface, ['email_compose', 'bulk_email'], true)) {
            return false;
        }

        return !empty($reply['fallback']) && (int) ($reply['communication_id'] ?? 0) === 0;
    }

    private function normalizeLegacyBusinessGrounding(array $draftData, array $contact, ?array $companyProfile, string $purpose): array
    {
        if ($purpose !== 'welcome') {
            return $draftData;
        }

        $recipientCompany = trim((string) ($contact['company'] ?? ''));
        $ourCompany = trim((string) (($companyProfile['company_name'] ?? '') ?: brandProductName()));
        if ($recipientCompany === '' || $ourCompany === '' || strcasecmp($recipientCompany, $ourCompany) === 0) {
            return $draftData;
        }

        foreach (['subject', 'body_html', 'body_text'] as $field) {
            if (!isset($draftData[$field]) || !is_scalar($draftData[$field])) {
                continue;
            }

            $draftData[$field] = $this->replaceRecipientCompanyWelcomeLanguage(
                (string) $draftData[$field],
                $recipientCompany,
                $ourCompany
            );
        }

        return $draftData;
    }

    private function replaceRecipientCompanyWelcomeLanguage(string $content, string $recipientCompany, string $ourCompany): string
    {
        $quotedRecipient = preg_quote($recipientCompany, '/');

        $patterns = [
            '/welcome\s+to\s+' . $quotedRecipient . '\b/i' => 'welcome to ' . $ourCompany,
            '/joining\s+' . $quotedRecipient . '\b/i' => 'joining ' . $ourCompany,
            '/onboarding\s+at\s+' . $quotedRecipient . '\b/i' => 'onboarding with ' . $ourCompany,
            '/your\s+new\s+role\s+at\s+' . $quotedRecipient . '\b/i' => 'your journey with ' . $ourCompany,
        ];

        foreach ($patterns as $pattern => $replacement) {
            $content = preg_replace($pattern, $replacement, $content) ?? $content;
        }

        return $content;
    }
}
