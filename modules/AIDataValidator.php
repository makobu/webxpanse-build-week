<?php
/**
 * AI Data Validator Module
 * 
 * Validates and corrects contact data using AI
 */

namespace CRM\Modules;

use CRM\Services\AIService;
use CRM\CacheManager;

class AIDataValidator
{
    private AIService $aiService;
    private CacheManager $cache;
    
    public function __construct()
    {
        $this->aiService = new AIService();
        $this->cache = new CacheManager();
    }
    
    /**
     * Validate and correct contact data
     */
    public function validateContactData(array $contactData): array
    {
        // Check cache
        $cacheKey = "validate_" . md5(json_encode($contactData));
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        try {
            $prompt = $this->buildValidationPrompt($contactData);
            $aiResponse = $this->aiService->process('data_validation', [
                'prompt' => $prompt,
                'contact_data' => $contactData,
                'text' => json_encode($contactData)
            ]);
            
            $validatedData = $this->parseValidationResponse($aiResponse);
            
            // Cache result
            $this->cache->set($cacheKey, json_encode($validatedData), 86400); // 24 hours
            
            return [
                'status' => 'success',
                'validated_data' => $validatedData['validated_data'] ?? $contactData,
                'corrections' => $validatedData['corrections'] ?? [],
                'quality_score' => $validatedData['quality_score'] ?? 0,
                'issues' => $validatedData['issues'] ?? []
            ];
            
        } catch (\Exception $e) {
            error_log("Validation error: " . $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'validated_data' => $contactData
            ];
        }
    }
    
    /**
     * Validate email format
     */
    public function validateEmailFormat(string $email): array
    {
        $contactData = ['email' => $email];
        $result = $this->validateContactData($contactData);
        
        return [
            'email' => $result['validated_data']['email'] ?? $email,
            'is_valid' => !in_array('email_format', $result['issues'] ?? []),
            'corrections' => array_filter($result['corrections'] ?? [], function($c) {
                return $c['field'] === 'email';
            })
        ];
    }
    
    /**
     * Validate and standardize phone format
     */
    public function validatePhoneFormat(string $phone): array
    {
        $contactData = ['phone' => $phone];
        $result = $this->validateContactData($contactData);
        
        return [
            'phone' => $result['validated_data']['phone'] ?? $phone,
            'is_valid' => !in_array('phone_format', $result['issues'] ?? []),
            'corrections' => array_filter($result['corrections'] ?? [], function($c) {
                return $c['field'] === 'phone';
            })
        ];
    }
    
    /**
     * Validate and standardize company name
     */
    public function validateCompanyName(string $company): array
    {
        $contactData = ['company' => $company];
        $result = $this->validateContactData($contactData);
        
        return [
            'company' => $result['validated_data']['company'] ?? $company,
            'is_valid' => !in_array('company_name', $result['issues'] ?? []),
            'corrections' => array_filter($result['corrections'] ?? [], function($c) {
                return $c['field'] === 'company';
            })
        ];
    }
    
    /**
     * Standardize job title
     */
    public function validateJobTitle(string $title): array
    {
        $contactData = ['job_title' => $title];
        $result = $this->validateContactData($contactData);
        
        return [
            'job_title' => $result['validated_data']['job_title'] ?? $title,
            'is_valid' => !in_array('job_title', $result['issues'] ?? []),
            'corrections' => array_filter($result['corrections'] ?? [], function($c) {
                return $c['field'] === 'job_title';
            })
        ];
    }
    
    /**
     * Detect data quality issues
     */
    public function detectDataQualityIssues(array $contactData): array
    {
        $result = $this->validateContactData($contactData);
        
        return [
            'quality_score' => $result['quality_score'] ?? 0,
            'issues' => $result['issues'] ?? [],
            'corrections_needed' => count($result['corrections'] ?? []),
            'corrections' => $result['corrections'] ?? []
        ];
    }
    
    /**
     * Build validation prompt
     */
    private function buildValidationPrompt(array $contactData): string
    {
        $prompt = "Validate and correct this contact data:\n";
        $prompt .= json_encode($contactData, JSON_PRETTY_PRINT) . "\n\n";
        
        $prompt .= "Check for:\n";
        $prompt .= "- Email format errors (e.g., missing @, invalid domain)\n";
        $prompt .= "- Phone number format inconsistencies (standardize to international format)\n";
        $prompt .= "- Company name variations (e.g., 'Inc.' vs 'Inc', 'LLC' vs 'L.L.C.')\n";
        $prompt .= "- Job title standardization (e.g., 'CEO' vs 'Chief Executive Officer')\n";
        $prompt .= "- Name formatting (proper capitalization, remove extra spaces)\n";
        $prompt .= "- Data completeness (identify missing critical fields)\n";
        $prompt .= "- Data consistency (e.g., email domain matches company)\n\n";
        
        $prompt .= "Return JSON with:\n";
        $prompt .= "- validated_data: corrected values (only include fields that were corrected)\n";
        $prompt .= "- corrections: [{field, old_value, new_value, reason}]\n";
        $prompt .= "- quality_score: 0-100 (overall data quality)\n";
        $prompt .= "- issues: [list of issues found, e.g., 'email_format', 'phone_format', 'company_name', 'job_title', 'missing_fields']\n\n";
        
        $prompt .= "Only correct obvious errors. Return ONLY valid JSON.";
        
        return $prompt;
    }
    
    /**
     * Parse validation response
     */
    private function parseValidationResponse(string $aiResponse): array
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
        return $data ?: [
            'validated_data' => [],
            'corrections' => [],
            'quality_score' => 50,
            'issues' => []
        ];
    }
}
