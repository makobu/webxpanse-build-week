<?php
/**
 * AI Context Generator Module
 * 
 * Generates contextual insights and conclusions about contacts
 * without filling structured fields. AI analysis goes to ai_context field only.
 */

namespace CRM\Modules;

use CRM\Services\AIService;
use CRM\Database;
use CRM\CacheManager;

class AIContextGenerator
{
    private AIService $aiService;
    private CacheManager $cache;
    
    public function __construct()
    {
        $this->aiService = new AIService();
        $this->cache = new CacheManager();
    }
    
    /**
     * Generate AI context for a contact
     * 
     * @param int $contactId Contact ID
     * @param array $enrichmentData Data from enrichment sources (for analysis)
     * @param array $contact Current contact data
     * @return array Context data structure
     */
    public function generateContext(int $contactId, array $enrichmentData = [], array $contact = []): array
    {
        $logFile = __DIR__ . '/../../.cursor/enrichment_debug.log';
        $logDir = dirname($logFile);
        $canWriteDebugLog = is_dir($logDir) || @mkdir($logDir, 0777, true);
        $logStep = function($step, $message, $data = []) use ($logFile, $canWriteDebugLog) {
            if (!$canWriteDebugLog) {
                return;
            }
            $logEntry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'step' => 'CONTEXT_' . $step,
                'message' => $message,
                'data' => $data
            ];
            @file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND);
        };
        
        $logStep('START', 'Starting context generation', ['contact_id' => $contactId]);
        
        // Get contact if not provided
        if (empty($contact)) {
            $logStep('LOAD', 'Loading contact from database');
            $contact = Database::queryOne("SELECT * FROM contacts WHERE id = ?", [$contactId]);
            if (!$contact) {
                $logStep('ERROR', 'Contact not found');
                throw new \Exception("Contact not found: $contactId");
            }
            $logStep('LOAD', 'Contact loaded', ['has_email' => !empty($contact['email'])]);
        } else {
            $logStep('LOAD', 'Contact provided in parameters', ['has_email' => !empty($contact['email'])]);
        }
        
        // Get enrichment sources for context (if table exists)
        $enrichmentSources = [];
        try {
            $logStep('SOURCES', 'Fetching enrichment sources');
            $enrichmentSources = Database::query(
                "SELECT source_type, source_url, extracted_data, confidence_score, created_at 
                 FROM enrichment_sources 
                 WHERE contact_id = ? 
                 ORDER BY created_at DESC 
                 LIMIT 10",
                [$contactId]
            );
            $logStep('SOURCES', 'Enrichment sources fetched', ['count' => count($enrichmentSources)]);
        } catch (\Exception $e) {
            // Table might not exist yet, continue without enrichment sources
            $logStep('SOURCES_ERROR', 'Could not fetch enrichment sources', ['error' => $e->getMessage()]);
            error_log("Could not fetch enrichment sources: " . $e->getMessage());
        }
        
        // Get enrichment history (if table exists)
        $enrichmentHistory = [];
        try {
            $logStep('HISTORY', 'Fetching enrichment history');
            $enrichmentHistory = Database::query(
                "SELECT enrichment_type, fields_updated, ai_response, created_at 
                 FROM enrichment_history 
                 WHERE contact_id = ? 
                 ORDER BY created_at DESC 
                 LIMIT 5",
                [$contactId]
            );
            $logStep('HISTORY', 'Enrichment history fetched', ['count' => count($enrichmentHistory)]);
        } catch (\Exception $e) {
            // Table might not exist yet, continue without enrichment history
            $logStep('HISTORY_ERROR', 'Could not fetch enrichment history', ['error' => $e->getMessage()]);
            error_log("Could not fetch enrichment history: " . $e->getMessage());
        }

        $contactNotes = $this->getRecentContactNotes($contactId);
        
        // Analyze contact profile
        $logStep('ANALYZE', 'Analyzing contact profile');
        $analysis = $this->analyzeContactProfile($contact, $enrichmentSources, $enrichmentData, $contactNotes);
        $logStep('ANALYZE', 'Profile analysis complete', ['has_analysis' => !empty($analysis)]);
        
        // Identify key insights
        $logStep('INSIGHTS', 'Identifying key insights');
        $insights = $this->identifyKeyInsights($contact, $enrichmentSources, $enrichmentData, $contactNotes);
        $logStep('INSIGHTS', 'Insights identified', ['count' => count($insights)]);
        
        // Generate recommendations
        $logStep('RECOMMENDATIONS', 'Generating recommendations');
        $recommendations = $this->generateRecommendations($contact, $insights);
        $logStep('RECOMMENDATIONS', 'Recommendations generated', ['count' => count($recommendations)]);
        
        // Format summary
        $logStep('SUMMARY', 'Formatting summary');
        $summary = $this->formatContextSummary($insights, $analysis);
        $logStep('SUMMARY', 'Summary formatted', ['has_summary' => !empty($summary)]);
        
        // Build context structure
        $context = [
            'summary' => $summary,
            'insights' => $insights,
            'analysis' => $analysis,
            'recommendations' => $recommendations,
            'recent_notes' => $contactNotes,
            'generated_at' => date('c'),
            'model_version' => '1.0',
            'sources_analyzed' => array_unique(array_column($enrichmentSources, 'source_type'))
        ];
        
        return $context;
    }
    
    /**
     * Analyze contact profile and enrichment sources
     */
    private function analyzeContactProfile(array $contact, array $enrichmentSources, array $enrichmentData, array $contactNotes = []): array
    {
        $analysis = [
            'data_completeness' => $this->calculateCompleteness($contact),
            'data_quality' => $this->assessDataQuality($contact, $enrichmentSources),
            'professional_profile' => $this->analyzeProfessionalProfile($contact),
            'company_insights' => $this->analyzeCompany($contact, $enrichmentData),
            'notes_context' => $this->analyzeNotes($contactNotes),
        ];
        
        return $analysis;
    }
    
    /**
     * Identify key insights from contact data
     */
    private function identifyKeyInsights(array $contact, array $enrichmentSources, array $enrichmentData, array $contactNotes = []): array
    {
        $insights = [];
        
        // Use AI to generate insights
        try {
            $prompt = $this->buildInsightsPrompt($contact, $enrichmentSources, $enrichmentData, $contactNotes);
            $aiResponse = $this->aiService->process('data_inference', [
                'prompt' => $prompt,
                'contact_data' => $contact,
                'text' => json_encode([
                    'contact' => $contact,
                    'enrichment_sources' => $enrichmentSources,
                    'enrichment_data' => $enrichmentData,
                    'recent_notes' => $contactNotes,
                ])
            ]);
            
            $parsedInsights = $this->parseInsightsResponse($aiResponse);
            if (!empty($parsedInsights)) {
                $insights = $parsedInsights;
            }
        } catch (\Exception $e) {
            error_log("AI Context Generator Error: " . $e->getMessage());
            // Fallback to rule-based insights
            $insights = $this->generateRuleBasedInsights($contact, $enrichmentSources, $contactNotes);
        }
        
        return $insights;
    }
    
    /**
     * Generate recommendations based on contact data and insights
     */
    private function generateRecommendations(array $contact, array $insights): array
    {
        $recommendations = [];
        
        // Rule-based recommendations
        if (empty($contact['email_verified']) || $contact['email_verified'] === false) {
            $recommendations[] = "Email verification status is unknown - consider verifying email address";
        }
        
        if (empty($contact['linkedin_url']) && !empty($contact['company'])) {
            $recommendations[] = "LinkedIn profile not found - may be worth searching manually for professional context";
        }
        
        if (!empty($contact['company']) && empty($contact['company_website'])) {
            $recommendations[] = "Company website not found - could help with company research and outreach";
        }
        
        // AI-generated recommendations based on insights
        foreach ($insights as $insight) {
            if (isset($insight['recommendation']) && !empty($insight['recommendation'])) {
                $recommendations[] = $insight['recommendation'];
            }
        }
        
        return array_unique($recommendations);
    }
    
    /**
     * Format human-readable context summary
     */
    private function formatContextSummary(array $insights, array $analysis): string
    {
        $summaryParts = [];
        
        // Professional profile summary
        if (!empty($analysis['professional_profile'])) {
            $profile = $analysis['professional_profile'];
            if (!empty($profile['role_level'])) {
                $summaryParts[] = "Appears to be a " . $profile['role_level'] . " professional";
            }
        }
        
        // Company insights
        if (!empty($analysis['company_insights'])) {
            $company = $analysis['company_insights'];
            if (!empty($company['industry'])) {
                $summaryParts[] = "in the " . $company['industry'] . " industry";
            }
        }

        if (!empty($analysis['notes_context']['summary'])) {
            $summaryParts[] = $analysis['notes_context']['summary'];
        }
        
        // Key insights
        if (!empty($insights)) {
            $topInsight = $insights[0] ?? null;
            if ($topInsight && isset($topInsight['conclusion'])) {
                $summaryParts[] = $topInsight['conclusion'];
            }
        }
        
        if (empty($summaryParts)) {
            return "Contact profile analysis completed. Review insights below for detailed conclusions.";
        }
        
        return implode(". ", $summaryParts) . ".";
    }
    
    /**
     * Calculate data completeness score
     */
    private function calculateCompleteness(array $contact): float
    {
        $fields = [
            'first_name', 'last_name', 'email', 'phone', 'company',
            'job_title', 'company_website', 'linkedin_url', 'location'
        ];
        
        $filled = 0;
        foreach ($fields as $field) {
            if (!empty($contact[$field])) {
                $filled++;
            }
        }
        
        return round(($filled / count($fields)) * 100, 2);
    }
    
    /**
     * Assess data quality
     */
    private function assessDataQuality(array $contact, array $enrichmentSources): string
    {
        $apiSources = array_filter($enrichmentSources, function($source) {
            return $source['source_type'] === 'api';
        });
        
        if (count($apiSources) > 0) {
            return 'high'; // API sources are most reliable
        }
        
        if (!empty($contact['email_verified']) && $contact['email_verified'] === true) {
            return 'medium';
        }
        
        return 'low';
    }
    
    /**
     * Analyze professional profile
     */
    private function analyzeProfessionalProfile(array $contact): array
    {
        $profile = [];
        
        // Analyze job title
        if (!empty($contact['job_title'])) {
            $title = strtolower($contact['job_title']);
            if (preg_match('/\b(ceo|cto|cfo|president|director|vp|vice president|head of|chief)\b/i', $title)) {
                $profile['role_level'] = 'senior executive';
            } elseif (preg_match('/\b(manager|lead|senior)\b/i', $title)) {
                $profile['role_level'] = 'mid-level management';
            } else {
                $profile['role_level'] = 'individual contributor';
            }
        }
        
        return $profile;
    }
    
    /**
     * Analyze company information
     */
    private function analyzeCompany(array $contact, array $enrichmentData): array
    {
        $company = [];
        
        if (!empty($contact['company_industry'])) {
            $company['industry'] = $contact['company_industry'];
        }
        
        if (!empty($contact['company_size'])) {
            $company['size'] = $contact['company_size'];
        }
        
        return $company;
    }
    
    /**
     * Build prompt for AI insights generation
     */
    private function buildInsightsPrompt(array $contact, array $enrichmentSources, array $enrichmentData, array $contactNotes = []): string
    {
        $prompt = "Analyze the following contact information and generate key insights and conclusions.\n\n";
        $prompt .= "CONTACT DATA:\n";
        $prompt .= "- Name: " . ($contact['first_name'] ?? '') . " " . ($contact['last_name'] ?? '') . "\n";
        $prompt .= "- Email: " . ($contact['email'] ?? 'N/A') . "\n";
        $prompt .= "- Company: " . ($contact['company'] ?? 'N/A') . "\n";
        $prompt .= "- Job Title: " . ($contact['job_title'] ?? 'N/A') . "\n";
        $prompt .= "- Location: " . ($contact['location'] ?? 'N/A') . "\n";
        
        if (!empty($contact['company_website'])) {
            $prompt .= "- Company Website: " . $contact['company_website'] . "\n";
        }
        if (!empty($contact['company_industry'])) {
            $prompt .= "- Industry: " . $contact['company_industry'] . "\n";
        }
        if (!empty($contact['company_size'])) {
            $prompt .= "- Company Size: " . $contact['company_size'] . "\n";
        }
        
        $prompt .= "\nENRICHMENT SOURCES:\n";
        foreach ($enrichmentSources as $source) {
            $prompt .= "- " . strtoupper($source['source_type']) . ": " . ($source['source_url'] ?? 'N/A') . "\n";
        }

        $prompt .= "\nRECENT INTERNAL NOTES:\n";
        if (!empty($contactNotes)) {
            foreach ($contactNotes as $note) {
                $prompt .= "- " . ($note['created_at'] ?? '') . ": " . ($note['title'] ?? 'Note') . " :: " . ($note['excerpt'] ?? '') . "\n";
            }
        } else {
            $prompt .= "- No recent contact notes available.\n";
        }
        
        $prompt .= "\nGenerate insights in JSON format with the following structure:\n";
        $prompt .= "{\n";
        $prompt .= "  \"insights\": [\n";
        $prompt .= "    {\n";
        $prompt .= "      \"type\": \"professional_role|industry_focus|company_analysis|contact_quality|other\",\n";
        $prompt .= "      \"conclusion\": \"Brief conclusion about this aspect\",\n";
        $prompt .= "      \"confidence\": 0.0-1.0,\n";
        $prompt .= "      \"source\": \"inference|website_analysis|email_analysis|social_analysis\",\n";
        $prompt .= "      \"reasoning\": \"Explanation of why this conclusion was reached\"\n";
        $prompt .= "    }\n";
        $prompt .= "  ]\n";
        $prompt .= "}\n\n";
        $prompt .= "Focus on important insights that don't fit into structured fields. Do NOT suggest specific field values.";
        
        return $prompt;
    }
    
    /**
     * Parse AI response to extract insights
     */
    private function parseInsightsResponse(string $aiResponse): array
    {
        // Try to parse as JSON first
        $json = json_decode($aiResponse, true);
        if ($json && isset($json['insights'])) {
            return $json['insights'];
        }
        
        // Try to extract JSON from text response
        if (preg_match('/\{[\s\S]*"insights"[\s\S]*\}/', $aiResponse, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json && isset($json['insights'])) {
                return $json['insights'];
            }
        }
        
        // Fallback: return empty array
        return [];
    }
    
    /**
     * Generate rule-based insights as fallback
     */
    private function generateRuleBasedInsights(array $contact, array $enrichmentSources, array $contactNotes = []): array
    {
        $insights = [];
        
        // Professional role insight
        if (!empty($contact['job_title'])) {
            $title = strtolower($contact['job_title']);
            if (preg_match('/\b(ceo|cto|cfo|president|director|vp|vice president|head of|chief)\b/i', $title)) {
                $insights[] = [
                    'type' => 'professional_role',
                    'conclusion' => 'Holds a senior executive or leadership position',
                    'confidence' => 0.8,
                    'source' => 'inference',
                    'reasoning' => 'Job title indicates executive or senior management level'
                ];
            }
        }
        
        // Industry insight
        if (!empty($contact['company_industry'])) {
            $insights[] = [
                'type' => 'industry_focus',
                'conclusion' => 'Works in ' . $contact['company_industry'] . ' industry',
                'confidence' => 0.9,
                'source' => 'api_enrichment',
                'reasoning' => 'Industry information from verified enrichment source'
            ];
        }
        
        // Data quality insight
        $apiSources = array_filter($enrichmentSources, function($source) {
            return $source['source_type'] === 'api';
        });
        
        if (count($apiSources) > 0) {
            $insights[] = [
                'type' => 'contact_quality',
                'conclusion' => 'Contact data verified through professional enrichment APIs',
                'confidence' => 0.95,
                'source' => 'api_enrichment',
                'reasoning' => 'Data sourced from verified third-party enrichment providers'
            ];
        }

        if (!empty($contactNotes)) {
            $latestNote = $contactNotes[0];
            $insights[] = [
                'type' => 'relationship_context',
                'conclusion' => 'Recent internal notes exist for this contact and should inform follow-up.',
                'confidence' => 0.75,
                'source' => 'notes',
                'reasoning' => 'Latest note summary: ' . ($latestNote['excerpt'] ?? 'Internal note captured')
            ];
        }
        
        return $insights;
    }

    private function getRecentContactNotes(int $contactId): array
    {
        try {
            $notes = Database::query(
                "SELECT id, title, content, created_at
                 FROM notes
                 WHERE entity_type = 'contact' AND entity_id = ? AND is_private = 0
                 ORDER BY created_at DESC
                 LIMIT 5",
                [$contactId]
            );
        } catch (\Throwable $e) {
            return [];
        }

        return array_map(function (array $note): array {
            $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($note['content'] ?? ''))));
            return [
                'id' => (int) ($note['id'] ?? 0),
                'title' => (string) ($note['title'] ?? ''),
                'created_at' => (string) ($note['created_at'] ?? ''),
                'excerpt' => mb_substr($plain, 0, 220),
            ];
        }, $notes);
    }

    private function analyzeNotes(array $contactNotes): array
    {
        if (empty($contactNotes)) {
            return [
                'count' => 0,
                'summary' => null,
            ];
        }

        $latest = $contactNotes[0];

        return [
            'count' => count($contactNotes),
            'summary' => 'recent notes indicate active internal context',
            'latest_note_excerpt' => $latest['excerpt'] ?? '',
            'latest_note_at' => $latest['created_at'] ?? null,
        ];
    }
}
