<?php
/**
 * AI Enrichment Service
 * 
 * Main service orchestrating all enrichment operations
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\AIDataExtractor;
use CRM\Modules\AIDataInference;
use CRM\Modules\AIDataValidator;
use CRM\Modules\AIContextGenerator;
use CRM\Modules\Contacts;
use CRM\Services\AIService;
use CRM\Services\ThirdPartyEnrichmentService;

class AIEnrichmentService
{
    private const STRUCTURED_SOURCE_CONTROLLED_FIELDS = [
        'linkedin_url',
        'twitter_url',
        'company_website',
        'job_title',
        'location',
        'timezone',
    ];

    private AIDataExtractor $extractor;
    private AIDataInference $inference;
    private AIDataValidator $validator;
    private AIContextGenerator $contextGenerator;
    private AIService $aiService;
    private ?ThirdPartyEnrichmentService $thirdPartyService;
    private ?EmailDomainClassifier $emailDomainClassifier;
    
    public function __construct()
    {
        $this->extractor = new AIDataExtractor();
        $this->inference = new AIDataInference();
        $this->validator = new AIDataValidator();
        $this->contextGenerator = new AIContextGenerator();
        $this->aiService = new AIService();
        $this->thirdPartyService = new ThirdPartyEnrichmentService();
        $this->emailDomainClassifier = new EmailDomainClassifier();
    }
    
    /**
     * Main enrichment entry point
     */
    public function enrichContact(int $contactId, array $options = []): array
    {
        $logFile = __DIR__ . '/../../.cursor/enrichment_debug.log';
        $logStep = function($step, $message, $data = []) use ($logFile) {
            $logEntry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'step' => $step,
                'message' => $message,
                'data' => $data
            ];
            file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND);
        };
        
        $logStep('ENRICH_START', 'Starting enrichment', ['contact_id' => $contactId]);
        
        $contact = Database::queryOne("SELECT * FROM contacts WHERE id = ?", [$contactId]);
        if (!$contact) {
            $logStep('ENRICH_ERROR', 'Contact not found', ['contact_id' => $contactId]);
            throw new \Exception("Contact not found: $contactId");
        }
        $logStep('ENRICH_CONTACT', 'Contact loaded', ['email' => $contact['email'] ?? 'none', 'company' => $contact['company'] ?? 'none']);
        
        $extractWeb = $options['extract_web'] ?? true;
        $extractEmail = $options['extract_email'] ?? true;
        $extractSocial = $options['extract_social'] ?? true;
        $discoverLinkedIn = $options['discover_linkedin'] ?? true;
        $useThirdParty = $options['use_third_party'] ?? true; // Use professional APIs
        $inferFields = $options['infer_fields'] ?? true;
        $validateData = $options['validate_data'] ?? true;
        $sources = $options['sources'] ?? ['third_party', 'website', 'email', 'linkedin', 'twitter', 'inference'];
        
        $enrichedData = [];
        $enrichmentLog = [];
        $verifiedSources = []; // Track which fields came from verified API sources
        
        try {
            // Step 0: Use third-party enrichment APIs (most reliable) - ONLY verified source
            $logStep('STEP_0', 'Starting third-party enrichment', ['use_third_party' => $useThirdParty, 'has_email' => !empty($contact['email'])]);
            if ($useThirdParty && in_array('third_party', $sources) && !empty($contact['email'])) {
                // Try Clearbit first (most comprehensive)
                $domain = $this->extractDomain($contact['email']);
                $clearbitResult = $this->thirdPartyService->enrichWithClearbit($contact['email'], $domain);
                if ($clearbitResult['status'] === 'success' && !empty($clearbitResult['data'])) {
                    $enrichedData = array_merge($enrichedData, $clearbitResult['data']);
                    // Mark all Clearbit fields as verified
                    foreach (array_keys($clearbitResult['data']) as $field) {
                        $verifiedSources[$field] = 'clearbit';
                    }
                    $this->logEnrichmentSource($contactId, 'clearbit', $contact['email'], $clearbitResult);
                }
                
                // If Clearbit didn't work, try People Data Labs
                if (empty($enrichedData['linkedin_url'])) {
                    $pdlResult = $this->thirdPartyService->enrichWithPDL(
                        $contact['email'],
                        $contact['first_name'] ?? null,
                        $contact['last_name'] ?? null
                    );
                    if ($pdlResult['status'] === 'success' && !empty($pdlResult['data'])) {
                        // Merge PDL data, but don't overwrite Clearbit data
                        foreach ($pdlResult['data'] as $key => $value) {
                            if (empty($enrichedData[$key]) && !empty($value)) {
                                $enrichedData[$key] = $value;
                                $verifiedSources[$key] = 'pdl';
                            }
                        }
                        $this->logEnrichmentSource($contactId, 'pdl', $contact['email'], $pdlResult);
                    }
                }
                
                // Enrich with Hunter.io (optimized - uses best endpoint based on available data)
                $domain = $this->extractDomain($contact['email']) ?? $this->guessDomain($contact['company'] ?? '');
                $hunterResult = $this->thirdPartyService->enrichWithHunter(
                    $contact['email'] ?? null,
                    $contact['first_name'] ?? null,
                    $contact['last_name'] ?? null,
                    $contact['company'] ?? null,
                    $domain
                );
                if ($hunterResult['status'] === 'success' && !empty($hunterResult['data'])) {
                    // Merge all Hunter.io data (verified source)
                    foreach ($hunterResult['data'] as $key => $value) {
                        if (!empty($value) && !isset($enrichedData[$key])) {
                            $enrichedData[$key] = $value;
                            $verifiedSources[$key] = 'hunter';
                        }
                    }
                    // Ensure email verification fields are set
                    if (isset($hunterResult['data']['email_verified'])) {
                        $enrichedData['email_verified'] = $hunterResult['data']['email_verified'];
                        $verifiedSources['email_verified'] = 'hunter';
                    }
                    if (isset($hunterResult['data']['email_verification_status'])) {
                        $enrichedData['email_verification_status'] = $hunterResult['data']['email_verification_status'];
                        $verifiedSources['email_verification_status'] = 'hunter';
                    }
                    $this->logEnrichmentSource($contactId, 'hunter', $contact['email'] ?? $domain ?? 'unknown', $hunterResult);
                }
            }
            
            // Step 1: Extract from web sources (DISABLED - only use verified API data)
            // Web extraction uses AI inference which can generate placeholder data
            // Only third-party APIs (Step 0) should fill structured fields
            // if ($extractWeb && in_array('website', $sources)) {
            //     // Disabled to prevent AI-inferred placeholder data
            // }
            
            // Step 2: Discover and extract from LinkedIn (DISABLED - only use verified API data)
            // Social extraction uses AI inference which can generate placeholder data
            // Only third-party APIs (Step 0) should fill structured fields
            // LinkedIn/Twitter URLs from APIs are OK, but AI-extracted data is not
            // if ($extractSocial && in_array('linkedin', $sources)) {
            //     // Disabled to prevent AI-inferred placeholder data
            // }
            
            // Step 2b: Extract from Twitter (DISABLED - only use verified API data)
            // if ($extractSocial && in_array('twitter', $sources)) {
            //     // Disabled to prevent AI-inferred placeholder data
            // }
            
            // Step 3: Extract from email (if available)
            // Structured-field enrichment from email is intentionally inactive here.
            // Email-derived field updates must come from the email processing pipeline
            // where raw message content/signatures are explicitly available.
            if ($extractEmail && in_array('email', $sources)) {
                // This would be called from EmailFetcher when processing emails
                // For now, skip if no email content provided
            }
            
            // Step 4: AI Analysis - Generate context only (NOT filling fields)
            // Current contract: structured field updates in this method come from verified
            // third-party APIs in Step 0. AI output is stored as ai_context for insights.
            
            // Step 5: Validate existing data (only corrections, not AI guesses)
            if ($validateData) {
                $validationResult = $this->validator->validateContactData($contact);
                if ($validationResult['status'] === 'success') {
                    // Only merge validated corrections (data quality fixes, not AI inferences)
                    $validatedFields = [];
                    foreach ($validationResult['validated_data'] as $field => $value) {
                        // Only update if it's a correction of existing data, not an inference
                        if ($value && isset($contact[$field]) && $contact[$field] !== $value) {
                            // This is a correction, not an inference - safe to update
                            $enrichedData[$field] = $value;
                            $validatedFields[$field] = $value;
                        }
                    }
                    if (!empty($validatedFields)) {
                        $this->logEnrichment($contactId, 'validate', $validatedFields, $validationResult);
                    }
                }
            }
            
            // Step 6: Generate AI Context (insights and conclusions - NOT filling fields)
            $logStep('STEP_6', 'Starting AI context generation', ['infer_fields' => $inferFields, 'has_inference_source' => in_array('inference', $sources)]);
            $aiContext = null;
            if ($inferFields && in_array('inference', $sources)) {
                try {
                    $logStep('STEP_6', 'Calling contextGenerator->generateContext');
                    $aiContext = $this->contextGenerator->generateContext($contactId, $enrichedData, $contact);
                    $logStep('STEP_6', 'AI context generated', ['has_summary' => !empty($aiContext['summary']), 'insights_count' => count($aiContext['insights'] ?? [])]);
                    
                    // Store AI context in database (separate from structured fields)
                    Database::execute(
                        "UPDATE contacts SET ai_context = ? WHERE id = ?",
                        [json_encode($aiContext), $contactId]
                    );
                    
                    $this->logEnrichment($contactId, 'context_generation', [
                        'context_generated' => true,
                        'insights_count' => count($aiContext['insights'] ?? []),
                        'recommendations_count' => count($aiContext['recommendations'] ?? [])
                    ], $aiContext);
                } catch (\Exception $e) {
                    $logStep('STEP_6_ERROR', 'AI Context generation failed', [
                        'error' => $e->getMessage(),
                        'file' => basename($e->getFile()),
                        'line' => $e->getLine()
                    ]);
                    error_log("AI Context generation error: " . $e->getMessage());
                    // Don't fail enrichment if context generation fails
                }
            }
            
            // Step 7: Merge and update contact (ONLY verified API data, no AI-inferred data)
            // Filter out any placeholder data and only use verified API sources
            $verifiedData = [];
            foreach ($enrichedData as $field => $value) {
                // Only accept data from verified API sources (Clearbit, PDL, Hunter.io)
                // Reject data from web/social extraction which may use AI inference
                if (isset($verifiedSources[$field]) && !$this->isPlaceholderData($value, $field)) {
                    $verifiedData[$field] = $value;
                    $logStep('VERIFIED', "Accepted verified data", ['field' => $field, 'source' => $verifiedSources[$field]]);
                } else {
                    $logStep('REJECTED', "Rejected unverified/placeholder data", [
                        'field' => $field, 
                        'value' => is_string($value) ? substr($value, 0, 50) : 'non-string',
                        'source' => $verifiedSources[$field] ?? 'unknown'
                    ]);
                }
            }
            
            if (!empty($verifiedData)) {
                $mergedData = $this->mergeEnrichmentData($contact, $verifiedData);
                $fieldsUpdated = array_keys($mergedData);
                $provenanceMap = $this->buildVerifiedFieldProvenance($fieldsUpdated, $verifiedSources);
                
                // Store previous values before updating (for undo functionality)
                $previousValues = [];
                $contactsModule = new Contacts();
                $existingProvenance = $contactsModule->getFieldProvenanceMap($contact);
                $previousProvenance = [];
                foreach ($fieldsUpdated as $field) {
                    $previousValues[$field] = $contact[$field] ?? null;
                    $previousProvenance[$field] = $existingProvenance[$field] ?? null;
                }
                
                $this->updateContact($contactId, $mergedData, $provenanceMap);
                $this->calculateEnrichmentScore($contactId);
                
                // Log the enrichment with fields updated and previous values (for undo)
                $fieldsData = [];
                foreach ($fieldsUpdated as $field) {
                    $fieldsData[$field] = [
                        'new_value' => $mergedData[$field] ?? null,
                        'previous_value' => $previousValues[$field] ?? null
                        ,'new_provenance' => $provenanceMap[$field] ?? null,
                        'previous_provenance' => $previousProvenance[$field] ?? null,
                    ];
                }
                $this->logEnrichment($contactId, 'merge', $fieldsData, [
                    'status' => 'success',
                    'fields_updated' => $fieldsUpdated,
                    'enriched_data' => $mergedData,
                    'previous_values' => $previousValues,
                    'verified_fields_updated' => $fieldsUpdated,
                    'context_generated' => !empty($aiContext),
                ]);
                
                return [
                    'status' => 'success',
                    'contact_id' => $contactId,
                    'fields_updated' => $fieldsUpdated,
                    'enriched_data' => $mergedData,
                    'ai_context' => $aiContext,
                    'message' => 'Verified fields updated: ' . count($fieldsUpdated) . '. ' . ($aiContext ? 'Contact context enriched with AI insights.' : 'No new AI context generated.')
                ];
            } else {
                // Even if no structured fields were updated, generate AI context if requested
                $aiContext = null;
                if ($inferFields && in_array('inference', $sources)) {
                    try {
                        $aiContext = $this->contextGenerator->generateContext($contactId, [], $contact);
                        Database::execute(
                            "UPDATE contacts SET ai_context = ? WHERE id = ?",
                            [json_encode($aiContext), $contactId]
                        );
                    } catch (\Exception $e) {
                        error_log("AI Context generation error: " . $e->getMessage());
                    }
                }
                
                return [
                    'status' => 'success',
                    'contact_id' => $contactId,
                    'fields_updated' => [],
                    'enriched_data' => [],
                    'ai_context' => $aiContext,
                    'message' => 'No new verified structured fields were available. ' . ($aiContext ? 'Contact context enriched with AI insights.' : 'Contact may already be up to date.')
                ];
            }
            
        } catch (\Exception $e) {
            $logStep('ENRICH_ERROR', 'Enrichment failed', [
                'error' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 500)
            ]);
            error_log("Enrichment error for contact $contactId: " . $e->getMessage());
            $this->logEnrichment($contactId, 'extract', [], [
                'status' => 'failed',
                'error' => $e->getMessage()
            ]);
            
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'error_details' => [
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine()
                ]
            ];
        }
    }
    
    /**
     * Extract from email content
     */
    public function extractFromEmail(string $emailContent, array $contactData = []): array
    {
        $contactId = (int) ($contactData['contact_id'] ?? 0);
        $messageRef = (string) ($contactData['message_id'] ?? ($contactData['uid'] ?? ($contactData['from_email'] ?? 'email')));

        $exactFields = $this->extractExplicitStructuredFieldsFromCommunication($emailContent, $contactData);
        $appliedFields = [];
        $suggestions = [];

        if ($contactId > 0) {
            $contact = Database::queryOne("SELECT * FROM contacts WHERE id = ?", [$contactId]);
            if ($contact) {
                $contactsModule = new Contacts();

                if (!empty($exactFields)) {
                    $mergedData = $this->mergeEnrichmentData($contact, $exactFields);
                    if (!empty($mergedData)) {
                        $provenanceMap = [];
                        foreach (array_keys($mergedData) as $field) {
                            $provenanceMap[$field] = $contactsModule->buildFieldProvenanceEntry(
                                'communication_explicit',
                                $messageRef,
                                'Captured from email signature/message',
                                1.0
                            );
                        }

                        $previousProvenance = $contactsModule->getFieldProvenanceMap($contact);
                        $fieldAudit = [];
                        foreach ($mergedData as $field => $value) {
                            $fieldAudit[$field] = [
                                'new_value' => $value,
                                'previous_value' => $contact[$field] ?? null,
                                'new_provenance' => $provenanceMap[$field] ?? null,
                                'previous_provenance' => $previousProvenance[$field] ?? null,
                            ];
                        }

                        $this->updateContact($contactId, $mergedData, $provenanceMap);
                        $this->calculateEnrichmentScore($contactId);
                        $this->logEnrichment($contactId, 'extract', $fieldAudit, [
                            'status' => 'success',
                            'source_type' => 'communication_explicit',
                            'source_ref' => $messageRef,
                            'verified_fields_updated' => array_keys($mergedData),
                            'context_generated' => false,
                        ]);
                        $appliedFields = array_keys($mergedData);
                    }
                }

                $suggestions = $this->buildCommunicationSuggestions($emailContent, $contactData, $contact);
                if (!empty($suggestions)) {
                    $existingSuggestions = $contactsModule->getSuggestedStructuredFields($contact);
                    $contactsModule->saveSuggestedStructuredFields(
                        $contactId,
                        array_merge($existingSuggestions, $suggestions)
                    );
                }
            }
        }

        return [
            'status' => 'success',
            'fields_updated' => $appliedFields,
            'suggestions' => $suggestions,
            'message' => !empty($appliedFields)
                ? 'Captured explicit communication fields and updated provenance.'
                : (!empty($suggestions) ? 'Structured field suggestions captured for review.' : 'No exact structured field evidence found in communication.')
        ];
    }
    
    /**
     * Extract from web URL
     */
    public function extractFromWeb(string $url, array $contactData = []): array
    {
        return $this->extractor->extractFromWebsite($url, $contactData);
    }
    
    /**
     * Extract from social media
     */
    public function extractFromSocial(string $platform, string $profileUrl, array $contactData = []): array
    {
        // Validate LinkedIn URL before processing
        if (strtolower($platform) === 'linkedin' && !$this->isValidLinkedInProfileUrl($profileUrl)) {
            return ['status' => 'error', 'message' => 'Invalid LinkedIn URL: must be a personal profile (/in/), not a company page'];
        }
        
        return match(strtolower($platform)) {
            'linkedin' => $this->extractor->extractFromLinkedIn($profileUrl, $contactData),
            'twitter' => $this->extractor->extractFromTwitter($profileUrl, $contactData),
            default => ['status' => 'error', 'message' => "Unsupported platform: $platform"]
        };
    }
    
    /**
     * Validate LinkedIn profile URL - must be personal profile, not company page
     */
    private function isValidLinkedInProfileUrl(string $url): bool
    {
        // Reject company pages
        if (preg_match('/\/company\//i', $url)) {
            return false;
        }
        
        // Must be personal profile format
        if (!preg_match('/^https?:\/\/(www\.)?linkedin\.com\/in\/[a-zA-Z0-9\-]+\/?$/', $url)) {
            return false;
        }
        
        // Reject placeholder URLs
        $placeholderPatterns = ['/example/i', '/sample/i', '/test/i', '/placeholder/i', '/demo/i'];
        foreach ($placeholderPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate Twitter URL format
     */
    private function isValidTwitterUrl(string $url): bool
    {
        // Must be a valid Twitter URL
        if (!preg_match('/^https?:\/\/(www\.)?(twitter\.com|x\.com)\//i', $url)) {
            return false;
        }
        
        // Reject placeholder URLs
        return !$this->isPlaceholderData($url, 'twitter_url');
    }
    
    /**
     * Validate website URL format
     */
    private function isValidWebsiteUrl(string $url): bool
    {
        // Must be a valid HTTP/HTTPS URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Must be HTTP or HTTPS
        if (!preg_match('/^https?:\/\//i', $url)) {
            return false;
        }
        
        // Reject placeholder URLs
        return !$this->isPlaceholderData($url, 'company_website');
    }
    
    /**
     * Merge enrichment data intelligently
     * Only accepts verified data from API sources, rejects placeholder/AI-inferred data
     */
    private function mergeEnrichmentData(array $existing, array $new, array $sourceTracking = []): array
    {
        $merged = [];
        
        // Only update fields that are:
        // 1. Empty in existing data, OR
        // 2. Have higher confidence in new data
        // AND are from verified sources (not AI-inferred placeholders)
        
        foreach ($new as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            
            // Reject placeholder/fake data
            if ($this->isPlaceholderData($value, $field)) {
                error_log("Rejecting placeholder data for field {$field}: {$value}");
                continue;
            }
            
            // If field is empty in existing, use new value (if verified)
            if (empty($existing[$field])) {
                $merged[$field] = $value;
            }
            // If field exists but new value is different and seems better, update
            elseif ($this->shouldUpdateField($existing[$field], $value, $field)) {
                $merged[$field] = $value;
            }
        }
        
        return $merged;
    }
    
    /**
     * Check if data is placeholder/fake/unverified
     */
    private function isPlaceholderData($value, string $field): bool
    {
        if (!is_string($value)) {
            return false;
        }
        
        $lowerValue = strtolower($value);
        
        // Check for placeholder patterns
        $placeholderPatterns = [
            '/example/i',
            '/sample/i',
            '/test/i',
            '/placeholder/i',
            '/demo/i',
            '/examplecompany/i',
            '/example\.com/i',
            '/www\.example/i'
        ];
        
        foreach ($placeholderPatterns as $pattern) {
            if (preg_match($pattern, $lowerValue)) {
                return true;
            }
        }
        
        // For URLs, check for placeholder domains
        if (in_array($field, ['company_website', 'linkedin_url', 'twitter_url'])) {
            if (preg_match('/example|sample|test|placeholder|demo/i', $lowerValue)) {
                return true;
            }
            // Check for obviously fake URLs
            if (preg_match('/http:\/\/www\.example/i', $lowerValue)) {
                return true;
            }
        }
        
        // For company fields, check for placeholder company names
        if (in_array($field, ['company', 'company_industry', 'company_size'])) {
            if (preg_match('/example|sample|test|placeholder|demo/i', $lowerValue)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Determine if field should be updated
     */
    private function shouldUpdateField($existingValue, $newValue, string $field): bool
    {
        // For certain fields, prefer more complete values
        if (in_array($field, ['company_description', 'location'])) {
            return strlen($newValue) > strlen($existingValue);
        }
        
        // For URLs, prefer non-null values
        if (in_array($field, ['linkedin_url', 'twitter_url', 'company_website'])) {
            return !empty($newValue) && empty($existingValue);
        }
        
        // Default: don't overwrite existing values
        return false;
    }
    
    /**
     * Update contact with enriched data
     */
    private function updateContact(int $contactId, array $enrichedData, array $provenanceMap = []): void
    {
        if (empty($enrichedData)) {
            return;
        }
        
        $allowedFields = [
            'company', 'company_website', 'company_size', 'company_industry',
            'company_description', 'company_founded', 'company_revenue',
            'job_title', 'linkedin_url', 'twitter_url', 'location', 'timezone',
            'email_verified', 'email_verification_status', 'phone', 'first_name', 'last_name',
            'email', 'confidence_score'
        ];
        
        $updates = [];
        $params = [];
        
        foreach ($allowedFields as $field) {
            if (isset($enrichedData[$field]) && $enrichedData[$field] !== null) {
                $value = $enrichedData[$field];
                
                // Reject placeholder data
                if ($this->isPlaceholderData($value, $field)) {
                    error_log("Skipping placeholder data for field {$field}: {$value}");
                    continue;
                }
                
                // Validate LinkedIn URL before saving
                if ($field === 'linkedin_url' && !$this->isValidLinkedInProfileUrl($value)) {
                    error_log("Skipping invalid LinkedIn URL: " . $value);
                    continue; // Skip invalid URLs
                }
                
                // Validate Twitter URL format
                if ($field === 'twitter_url' && !$this->isValidTwitterUrl($value)) {
                    error_log("Skipping invalid Twitter URL: " . $value);
                    continue;
                }
                
                // Validate company website URL format
                if ($field === 'company_website' && !$this->isValidWebsiteUrl($value)) {
                    error_log("Skipping invalid company website URL: " . $value);
                    continue;
                }
                
                $updates[] = "$field = ?";
                $params[] = $value;
            }
        }
        
        if (!empty($updates)) {
            if (!empty($provenanceMap)) {
                $contact = Database::queryOne("SELECT metadata_json FROM contacts WHERE id = ?", [$contactId]);
                if ($contact) {
                    $metadata = json_decode((string) ($contact['metadata_json'] ?? ''), true);
                    if (!is_array($metadata)) {
                        $metadata = [];
                    }
                    $existingProvenance = is_array($metadata['field_provenance'] ?? null) ? $metadata['field_provenance'] : [];
                    foreach ($provenanceMap as $field => $entry) {
                        if ($entry === null) {
                            unset($existingProvenance[$field]);
                            continue;
                        }
                        $existingProvenance[$field] = $entry;
                    }
                    $metadata['field_provenance'] = $existingProvenance;
                    if (!isset($metadata['suggested_structured_fields']) || !is_array($metadata['suggested_structured_fields'])) {
                        $metadata['suggested_structured_fields'] = [];
                    }
                    $updates[] = "metadata_json = ?";
                    $params[] = json_encode($metadata, JSON_UNESCAPED_SLASHES);
                }
            }

            $updates[] = "last_enriched_at = NOW()";
            $params[] = $contactId;
            
            Database::execute(
                "UPDATE contacts SET " . implode(', ', $updates) . " WHERE id = ?",
                $params
            );
        }
    }
    
    /**
     * Calculate enrichment completeness score
     */
    public function calculateEnrichmentScore(int $contactId): int
    {
        $contact = Database::queryOne("SELECT * FROM contacts WHERE id = ?", [$contactId]);
        
        $score = 0;
        $maxScore = 100;
        
        // Basic fields (40 points)
        if (!empty($contact['first_name'])) $score += 5;
        if (!empty($contact['last_name'])) $score += 5;
        if (!empty($contact['email'])) $score += 10;
        if (!empty($contact['phone'])) $score += 5;
        if (!empty($contact['company'])) $score += 10;
        if (!empty($contact['job_title'])) $score += 5;
        
        // Company enrichment (30 points)
        if (!empty($contact['company_website'])) $score += 5;
        if (!empty($contact['company_size'])) $score += 5;
        if (!empty($contact['company_industry'])) $score += 5;
        if (!empty($contact['company_description'])) $score += 5;
        if (!empty($contact['company_founded'])) $score += 5;
        if (!empty($contact['company_revenue'])) $score += 5;
        
        // Social/Professional (20 points)
        if (!empty($contact['linkedin_url'])) $score += 10;
        if (!empty($contact['twitter_url'])) $score += 5;
        if (!empty($contact['location'])) $score += 5;
        
        // Verification (10 points)
        if ($contact['email_verified']) $score += 10;
        
        $score = min($score, $maxScore);
        
        Database::execute(
            "UPDATE contacts SET enrichment_score = ? WHERE id = ?",
            [$score, $contactId]
        );
        
        return $score;
    }
    
    /**
     * Log enrichment source
     */
    private function logEnrichmentSource(int $contactId, string $sourceType, string $sourceUrl, array $result): void
    {
        try {
            Database::execute(
                "INSERT INTO enrichment_sources (contact_id, source_type, source_url, extracted_data, ai_model_used, confidence_score) 
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $contactId,
                    $sourceType,
                    $sourceUrl,
                    json_encode($result['data'] ?? []),
                    'ai_enrichment',
                    $result['confidence'] ?? 0.0
                ]
            );
        } catch (\Exception $e) {
            // Log error but don't fail enrichment
            error_log("Failed to log enrichment source: " . $e->getMessage());
        }
    }
    
    /**
     * Log enrichment operation
     */
    private function logEnrichment(int $contactId, string $enrichmentType, array $fieldsUpdated, array $result): void
    {
        try {
            // Estimate cost (very rough - would need actual API cost tracking)
            $cost = 0.001; // Default small cost per operation
            
            // Convert fields_updated to associative array if it's a list of keys
            $fieldsData = [];
            if (!empty($fieldsUpdated)) {
                // If it's an associative array (field => value), use as is
                // If it's a list of field names, create associative array
                $firstKey = array_key_first($fieldsUpdated);
                if (is_numeric($firstKey) || $firstKey === 0) {
                    // It's a list of field names
                    foreach ($fieldsUpdated as $field) {
                        $fieldsData[$field] = true;
                    }
                } else {
                    // It's already an associative array (may contain previous_values)
                    $fieldsData = $fieldsUpdated;
                }
            }
            
            Database::execute(
                "INSERT INTO enrichment_history (contact_id, enrichment_type, fields_updated, ai_response, cost, status) 
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $contactId,
                    $enrichmentType,
                    json_encode($fieldsData),
                    json_encode($result),
                    $cost,
                    $result['status'] ?? 'success'
                ]
            );
        } catch (\Exception $e) {
            // Log error but don't fail enrichment
            error_log("Failed to log enrichment history: " . $e->getMessage());
        }
    }
    
    /**
     * Undo last enrichment for a contact
     * Restores previous values from the most recent enrichment
     */
    public function undoEnrichment(int $contactId): array
    {
        try {
            // Get the most recent enrichment history entry
            $lastEnrichment = Database::queryOne(
                "SELECT * FROM enrichment_history 
                 WHERE contact_id = ? AND enrichment_type = 'merge' 
                 ORDER BY created_at DESC 
                 LIMIT 1",
                [$contactId]
            );
            
            if (!$lastEnrichment) {
                return [
                    'status' => 'error',
                    'message' => 'No enrichment history found to undo'
                ];
            }
            
            // Parse fields_updated to get previous values
            $fieldsData = json_decode($lastEnrichment['fields_updated'], true);
            if (empty($fieldsData)) {
                return [
                    'status' => 'error',
                    'message' => 'No fields to restore'
                ];
            }
            
            // Build restore data (previous values)
            $restoreData = [];
            $restoreProvenance = [];
            foreach ($fieldsData as $field => $fieldInfo) {
                // Check if fieldInfo is an array with previous_value, or just a boolean
                if (is_array($fieldInfo) && isset($fieldInfo['previous_value'])) {
                    $restoreData[$field] = $fieldInfo['previous_value'];
                    $restoreProvenance[$field] = $fieldInfo['previous_provenance'] ?? null;
                } else {
                    // If no previous value stored, set to NULL (clear the field)
                    $restoreData[$field] = null;
                    $restoreProvenance[$field] = null;
                }
            }
            
            if (empty($restoreData)) {
                return [
                    'status' => 'error',
                    'message' => 'No previous values found to restore'
                ];
            }
            
            // Restore previous values
            $this->updateContact($contactId, $restoreData, $restoreProvenance);
            $this->calculateEnrichmentScore($contactId);
            
            // Clear AI context if it was generated during this enrichment
            $aiResponse = json_decode($lastEnrichment['ai_response'], true);
            if (!empty($aiResponse['ai_context'])) {
                Database::execute(
                    "UPDATE contacts SET ai_context = NULL WHERE id = ?",
                    [$contactId]
                );
            }
            
            // Log the undo operation
            $this->logEnrichment($contactId, 'undo', $restoreData, [
                'status' => 'success',
                'undone_enrichment_id' => $lastEnrichment['id'],
                'restored_fields' => array_keys($restoreData)
            ]);
            
            return [
                'status' => 'success',
                'message' => 'Enrichment undone successfully',
                'fields_restored' => array_keys($restoreData),
                'restored_data' => $restoreData
            ];
            
        } catch (\Exception $e) {
            error_log("Undo enrichment error: " . $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Helper: Extract domain from email
     */
    private function extractDomain(?string $email): ?string
    {
        return $this->emailDomainClassifier?->extractBusinessDomainFromEmail($email);
    }
    
    /**
     * Helper: Guess domain from company name
     */
    private function guessDomain(string $companyName): ?string
    {
        // Simple domain guessing
        $cleanName = strtolower(preg_replace('/[^a-z0-9]/', '', $companyName));
        if ($cleanName === '') {
            return null;
        }
        return $cleanName . '.com';
    }

    private function buildVerifiedFieldProvenance(array $fields, array $verifiedSources): array
    {
        $contactsModule = new Contacts();
        $map = [];
        foreach ($fields as $field) {
            $source = strtolower((string) ($verifiedSources[$field] ?? 'api'));
            $label = match ($source) {
                'clearbit' => 'Verified by Clearbit',
                'pdl' => 'Verified by People Data Labs',
                'hunter' => 'Verified by Hunter',
                default => 'Verified by enrichment API',
            };
            $map[$field] = $contactsModule->buildFieldProvenanceEntry(
                'api_verified',
                $source,
                $label,
                0.95
            );
        }

        return $map;
    }

    private function extractExplicitStructuredFieldsFromCommunication(string $emailContent, array $contactData): array
    {
        $fields = [];
        $content = trim($emailContent);

        if ($content === '') {
            return $fields;
        }

        if (preg_match('~https?://(?:www\.)?linkedin\.com/in/[A-Za-z0-9\-_%]+/?~i', $content, $match)) {
            $fields['linkedin_url'] = $match[0];
        }

        if (preg_match('~https?://(?:www\.)?(?:twitter\.com|x\.com)/[A-Za-z0-9_]+/?~i', $content, $match)) {
            $fields['twitter_url'] = $match[0];
        }

        if (preg_match_all('~https?://[^\s<>"\']+~i', $content, $matches)) {
            foreach (($matches[0] ?? []) as $url) {
                if (!isset($fields['linkedin_url']) && preg_match('~linkedin\.com/in/~i', $url)) {
                    $fields['linkedin_url'] = $url;
                    continue;
                }
                if (!isset($fields['twitter_url']) && preg_match('~(?:twitter\.com|x\.com)/~i', $url)) {
                    $fields['twitter_url'] = $url;
                    continue;
                }
                if (!isset($fields['company_website']) && $this->isValidWebsiteUrl($url) && !preg_match('~linkedin\.com|twitter\.com|x\.com~i', $url)) {
                    $fields['company_website'] = $url;
                }
            }
        }

        if (preg_match('/^(?:title|role|position)\s*:\s*(.+)$/im', $content, $match)) {
            $fields['job_title'] = trim($match[1]);
        }

        if (preg_match('/^(?:location|based in)\s*:\s*(.+)$/im', $content, $match)) {
            $fields['location'] = trim($match[1]);
        }

        if (preg_match('/^(?:timezone|time zone)\s*:\s*(.+)$/im', $content, $match)) {
            $fields['timezone'] = trim($match[1]);
        }

        foreach ($fields as $field => $value) {
            if ($this->isPlaceholderData($value, $field)) {
                unset($fields[$field]);
            }
        }

        return $fields;
    }

    private function buildCommunicationSuggestions(string $emailContent, array $contactData, array $contact): array
    {
        $raw = $this->extractor->extractFromEmailSignature($emailContent, $contactData);
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : (is_array($raw) ? $raw : []);
        $exactFields = $this->extractExplicitStructuredFieldsFromCommunication($emailContent, $contactData);
        $suggestions = [];
        $messageRef = (string) ($contactData['message_id'] ?? ($contactData['uid'] ?? ($contactData['from_email'] ?? 'email')));

        foreach (self::STRUCTURED_SOURCE_CONTROLLED_FIELDS as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value === '' || isset($exactFields[$field]) || $this->isPlaceholderData($value, $field)) {
                continue;
            }
            if (!empty($contact[$field]) && trim((string) $contact[$field]) === $value) {
                continue;
            }

            $suggestions[$field] = [
                'value' => $value,
                'source_type' => 'communication_explicit',
                'source_ref' => $messageRef,
                'source_label' => 'Suggested from email signature/message',
                'confidence' => 0.55,
                'captured_at' => date('c'),
            ];
        }

        return $suggestions;
    }
}
