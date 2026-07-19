<?php
/**
 * AI Data Inference Module
 * 
 * Infers missing contact fields using AI reasoning
 */

namespace CRM\Modules;

use CRM\Services\AIService;
use CRM\CacheManager;
use CRM\Services\EmailDomainClassifier;

class AIDataInference
{
    private AIService $aiService;
    private CacheManager $cache;
    private EmailDomainClassifier $emailDomainClassifier;
    
    public function __construct()
    {
        $this->aiService = new AIService();
        $this->cache = new CacheManager();
        $this->emailDomainClassifier = new EmailDomainClassifier();
    }
    
    /**
     * Infer missing fields from available contact data
     */
    public function inferMissingFields(array $contactData): array
    {
        $contactData = $this->sanitizeInferenceInputs($contactData);

        // Check cache
        $cacheKey = "infer_" . md5(json_encode($contactData));
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        try {
            $prompt = $this->buildInferencePrompt($contactData);
            $aiResponse = $this->aiService->process('data_inference', [
                'prompt' => $prompt,
                'contact_data' => $contactData,
                'text' => json_encode($contactData)
            ]);
            
            $inferredData = $this->parseInferenceResponse($aiResponse);
            
            // Cache result
            $this->cache->set($cacheKey, json_encode($inferredData), 3600); // 1 hour
            
            return [
                'status' => 'success',
                'inferred_fields' => $inferredData['inferred_fields'] ?? [],
                'confidence_scores' => $inferredData['confidence_scores'] ?? [],
                'reasoning' => $inferredData['reasoning'] ?? ''
            ];
            
        } catch (\Exception $e) {
            error_log("Inference error: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Infer job title from email and company
     */
    public function inferJobTitle(string $email, ?string $company = null, ?string $name = null): array
    {
        $contactData = [
            'email' => $email,
            'company' => $company,
            'name' => $name
        ];
        
        $result = $this->inferMissingFields($contactData);
        
        return [
            'job_title' => $result['inferred_fields']['job_title'] ?? null,
            'confidence' => $result['confidence_scores']['job_title'] ?? 0.0
        ];
    }
    
    /**
     * Infer company domain from company name and email
     */
    public function inferCompanyDomain(string $companyName, ?string $email = null): array
    {
        $contactData = [
            'company' => $companyName,
            'email' => $email
        ];
        
        $result = $this->inferMissingFields($contactData);
        
        return [
            'company_website' => $result['inferred_fields']['company_website'] ?? null,
            'confidence' => $result['confidence_scores']['company_website'] ?? 0.0
        ];
    }
    
    /**
     * Infer industry from company name and job title
     */
    public function inferIndustry(?string $companyName = null, ?string $jobTitle = null): array
    {
        $contactData = [
            'company' => $companyName,
            'job_title' => $jobTitle
        ];
        
        $result = $this->inferMissingFields($contactData);
        
        return [
            'company_industry' => $result['inferred_fields']['company_industry'] ?? null,
            'confidence' => $result['confidence_scores']['company_industry'] ?? 0.0
        ];
    }
    
    /**
     * Infer location from email, phone, and company
     */
    public function inferLocation(?string $email = null, ?string $phone = null, ?string $company = null): array
    {
        $contactData = [
            'email' => $email,
            'phone' => $phone,
            'company' => $company
        ];
        
        $result = $this->inferMissingFields($contactData);
        
        return [
            'location' => $result['inferred_fields']['location'] ?? null,
            'confidence' => $result['confidence_scores']['location'] ?? 0.0
        ];
    }
    
    /**
     * Infer social profile URLs
     */
    public function inferSocialProfiles(string $name, ?string $company = null): array
    {
        $contactData = [
            'name' => $name,
            'company' => $company
        ];
        
        $result = $this->inferMissingFields($contactData);
        
        return [
            'linkedin_url' => $result['inferred_fields']['linkedin_url'] ?? null,
            'twitter_url' => $result['inferred_fields']['twitter_url'] ?? null,
            'confidence' => max(
                $result['confidence_scores']['linkedin_url'] ?? 0.0,
                $result['confidence_scores']['twitter_url'] ?? 0.0
            )
        ];
    }
    
    /**
     * Generate contextual insights instead of field values
     * This method is now used by AIContextGenerator, but kept for backward compatibility
     */
    public function generateContextualInsights(array $contactData): array
    {
        try {
            $prompt = $this->buildInsightsPrompt($contactData);
            $aiResponse = $this->aiService->process('data_inference', [
                'prompt' => $prompt,
                'contact_data' => $contactData,
                'text' => json_encode($contactData)
            ]);
            
            $insights = $this->parseInsightsResponse($aiResponse);
            
            return [
                'status' => 'success',
                'insights' => $insights
            ];
            
        } catch (\Exception $e) {
            error_log("Contextual insights error: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Build insights prompt (for context generation, not field filling)
     */
    private function buildInsightsPrompt(array $contactData): string
    {
        $prompt = "Analyze the following contact information and generate key insights and conclusions.\n\n";
        $prompt .= "CONTACT DATA:\n";
        $prompt .= json_encode($contactData, JSON_PRETTY_PRINT) . "\n\n";
        
        $prompt .= "Generate insights about:\n";
        $prompt .= "- Professional role and seniority level\n";
        $prompt .= "- Industry focus and company characteristics\n";
        $prompt .= "- Data quality and completeness\n";
        $prompt .= "- Important observations that don't fit structured fields\n\n";
        
        $prompt .= "Return JSON with:\n";
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
        $prompt .= "IMPORTANT: Do NOT suggest specific field values. Focus on insights, conclusions, and observations.";
        
        return $prompt;
    }
    
    /**
     * Parse insights response
     */
    private function parseInsightsResponse(string $aiResponse): array
    {
        // Try to extract JSON
        $jsonMatch = [];
        if (preg_match('/\{[\s\S]*\}/', $aiResponse, $jsonMatch)) {
            $data = json_decode($jsonMatch[0], true);
            if ($data && isset($data['insights'])) {
                return $data['insights'];
            }
        }
        
        // Fallback
        $data = json_decode($aiResponse, true);
        return $data['insights'] ?? [];
    }
    
    /**
     * Build inference prompt (kept for backward compatibility, but now focuses on insights)
     */
    private function buildInferencePrompt(array $contactData): string
    {
        // Redirect to insights prompt
        return $this->buildInsightsPrompt($contactData);
    }

    private function sanitizeInferenceInputs(array $contactData): array
    {
        $email = trim((string) ($contactData['email'] ?? ''));
        if ($email === '') {
            return $contactData;
        }

        if ($this->emailDomainClassifier->extractBusinessDomainFromEmail($email) === null) {
            unset($contactData['email']);
            $contactData['public_email_provider'] = true;
        }

        return $contactData;
    }
    
    /**
     * Parse inference response
     */
    private function parseInferenceResponse(string $aiResponse): array
    {
        // Try to extract JSON
        $jsonMatch = [];
        if (preg_match('/\{[\s\S]*\}/', $aiResponse, $jsonMatch)) {
            $data = json_decode($jsonMatch[0], true);
            if ($data) {
                return $data;
            }
        }
        
        // Fallback
        $data = json_decode($aiResponse, true);
        return $data ?: ['inferred_fields' => [], 'confidence_scores' => [], 'reasoning' => ''];
    }
}
