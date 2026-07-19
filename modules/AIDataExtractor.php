<?php
/**
 * AI Data Extractor Module
 * 
 * Extracts structured data from unstructured sources using AI
 */

namespace CRM\Modules;

use CRM\Services\AIService;
use CRM\Database;
use CRM\CacheManager;
use CRM\Services\EmailDomainClassifier;

class AIDataExtractor
{
    private AIService $aiService;
    private CacheManager $cache;
    
    public function __construct()
    {
        $this->aiService = new AIService();
        $this->cache = new CacheManager();
    }
    
    /**
     * Extract data from website
     */
    public function extractFromWebsite(string $url, array $contactData = []): array
    {
        // Check cache first
        $cacheKey = "extract_web_" . md5($url);
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        try {
            // Fetch website content
            $html = $this->fetchWebsite($url);
            if (!$html) {
                return ['status' => 'error', 'message' => 'Failed to fetch website'];
            }
            
            // Extract text content (remove scripts, styles)
            $text = $this->extractTextFromHTML($html);
            
            // Use AI to extract structured data
            $prompt = $this->buildExtractionPrompt('website', $text, $contactData);
            $aiResponse = $this->aiService->process('data_extraction', [
                'prompt' => $prompt,
                'text' => $text,
                'content' => $text,
                'source_type' => 'website',
                'url' => $url,
                'contact_data' => $contactData
            ]);
            
            // Parse AI response
            $extractedData = $this->parseAIResponse($aiResponse);
            
            // Cache result
            $this->cache->set($cacheKey, json_encode($extractedData), 86400); // 24 hours
            
            return [
                'status' => 'success',
                'data' => $extractedData,
                'source_url' => $url,
                'confidence' => $extractedData['confidence'] ?? 0.0
            ];
            
        } catch (\Exception $e) {
            error_log("Website extraction error: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Extract data from email signature/body
     */
    public function extractFromEmailSignature(string $emailBody, array $contactData = []): array
    {
        // Check cache
        $cacheKey = "extract_email_" . md5($emailBody);
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        try {
            // Extract signature section (usually at the end)
            $signature = $this->extractSignature($emailBody);
            
            // Use AI to extract structured data
            $prompt = $this->buildExtractionPrompt('email_signature', $signature, $contactData);
            $aiResponse = $this->aiService->process('data_extraction', [
                'prompt' => $prompt,
                'text' => $signature,
                'content' => $signature,
                'source_type' => 'email_signature',
                'email_body' => $emailBody,
                'contact_data' => $contactData
            ]);
            
            $extractedData = $this->parseAIResponse($aiResponse);
            
            // Cache result
            $this->cache->set($cacheKey, json_encode($extractedData), 86400);
            
            return [
                'status' => 'success',
                'data' => $extractedData,
                'source_type' => 'email_signature',
                'confidence' => $extractedData['confidence'] ?? 0.0
            ];
            
        } catch (\Exception $e) {
            error_log("Email extraction error: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Extract data from LinkedIn profile URL
     */
    public function extractFromLinkedIn(string $profileUrl, array $contactData = []): array
    {
        // Note: LinkedIn scraping has legal limitations
        // This is a placeholder for when LinkedIn API is available
        // For now, we'll use AI to infer from URL and available data
        
        try {
            // Extract username from URL
            $username = $this->extractLinkedInUsername($profileUrl);
            
            // Use AI to infer data from LinkedIn profile URL
            $prompt = "Given this LinkedIn profile URL: {$profileUrl}\n";
            $prompt .= "And existing contact data: " . json_encode($contactData) . "\n";
            $prompt .= "Infer what information might be available on this LinkedIn profile.\n";
            $prompt .= "Return JSON with inferred fields and confidence scores.";
            
            $aiResponse = $this->aiService->process('data_extraction', [
                'prompt' => $prompt,
                'source_type' => 'linkedin',
                'url' => $profileUrl
            ]);
            
            $extractedData = $this->parseAIResponse($aiResponse);
            
            return [
                'status' => 'success',
                'data' => $extractedData,
                'source_url' => $profileUrl,
                'linkedin_username' => $username,
                'confidence' => $extractedData['confidence'] ?? 0.5
            ];
            
        } catch (\Exception $e) {
            error_log("LinkedIn extraction error: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Extract data from Twitter profile URL
     */
    public function extractFromTwitter(string $profileUrl, array $contactData = []): array
    {
        try {
            $username = $this->extractTwitterUsername($profileUrl);
            
            $prompt = "Given this Twitter profile URL: {$profileUrl}\n";
            $prompt .= "And existing contact data: " . json_encode($contactData) . "\n";
            $prompt .= "Infer what information might be available on this Twitter profile.\n";
            $prompt .= "Return JSON with inferred fields and confidence scores.";
            
            $aiResponse = $this->aiService->process('data_extraction', [
                'prompt' => $prompt,
                'source_type' => 'twitter',
                'url' => $profileUrl
            ]);
            
            $extractedData = $this->parseAIResponse($aiResponse);
            
            return [
                'status' => 'success',
                'data' => $extractedData,
                'source_url' => $profileUrl,
                'twitter_username' => $username,
                'confidence' => $extractedData['confidence'] ?? 0.5
            ];
            
        } catch (\Exception $e) {
            error_log("Twitter extraction error: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Extract data from company website
     */
    public function extractFromCompanyWebsite(string $domain, array $contactData = []): array
    {
        $url = 'https://' . ltrim($domain, 'https://');
        
        return $this->extractFromWebsite($url, $contactData);
    }
    
    /**
     * Build extraction prompt for AI
     */
    private function buildExtractionPrompt(string $sourceType, string $content, array $contactData = []): string
    {
        $prompt = "Extract structured contact information from the following {$sourceType} content:\n\n";
        $prompt .= "Content:\n" . substr($content, 0, 3000) . "\n\n";
        
        if (!empty($contactData)) {
            $prompt .= "Existing contact data:\n" . json_encode($contactData) . "\n\n";
        }
        
        $prompt .= "Return a JSON object with these fields:\n";
        $prompt .= "- name (first_name, last_name)\n";
        $prompt .= "- email\n";
        $prompt .= "- phone\n";
        $prompt .= "- job_title\n";
        $prompt .= "- company\n";
        $prompt .= "- company_website\n";
        $prompt .= "- company_size\n";
        $prompt .= "- company_industry\n";
        $prompt .= "- location\n";
        $prompt .= "- linkedin_url\n";
        $prompt .= "- twitter_url\n";
        $prompt .= "- other_social_profiles\n\n";
        
        $prompt .= "For each field, provide:\n";
        $prompt .= "- value: the extracted value (null if not found)\n";
        $prompt .= "- confidence: 0.0 to 1.0\n";
        $prompt .= "- source: where it was found in the content\n\n";
        
        $prompt .= "Also include:\n";
        $prompt .= "- overall_confidence: average confidence score\n\n";
        
        $prompt .= "Return ONLY valid JSON, no additional text. Format:\n";
        $prompt .= '{"first_name": {"value": "...", "confidence": 0.9, "source": "..."}, ...}';
        
        return $prompt;
    }
    
    /**
     * Parse AI response into structured data
     */
    private function parseAIResponse(string $aiResponse): array
    {
        // Try to extract JSON from response
        $jsonMatch = [];
        if (preg_match('/\{[\s\S]*\}/', $aiResponse, $jsonMatch)) {
            $data = json_decode($jsonMatch[0], true);
            if ($data) {
                // Normalize structure
                return $this->normalizeExtractedData($data);
            }
        }
        
        // Fallback: try parsing entire response as JSON
        $data = json_decode($aiResponse, true);
        if ($data) {
            return $this->normalizeExtractedData($data);
        }
        
        return ['error' => 'Failed to parse AI response'];
    }
    
    /**
     * Normalize extracted data structure
     */
    private function normalizeExtractedData(array $data): array
    {
        $normalized = [];
        $confidences = [];
        
        $fields = [
            'first_name', 'last_name', 'email', 'phone', 'job_title',
            'company', 'company_website', 'company_size', 'company_industry',
            'location', 'linkedin_url', 'twitter_url'
        ];
        
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                if (is_array($data[$field])) {
                    $normalized[$field] = $data[$field]['value'] ?? null;
                    $confidences[] = $data[$field]['confidence'] ?? 0.0;
                } else {
                    $normalized[$field] = $data[$field];
                    $confidences[] = 0.5; // Default confidence
                }
            } else {
                $normalized[$field] = null;
            }
        }
        
        // Calculate overall confidence
        $normalized['confidence'] = !empty($confidences) 
            ? array_sum($confidences) / count($confidences) 
            : 0.0;
        
        return $normalized;
    }
    
    /**
     * Fetch website content
     */
    private function fetchWebsite(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CRM Bot/1.0)',
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($httpCode === 200) ? $html : null;
    }
    
    /**
     * Extract text from HTML
     */
    private function extractTextFromHTML(string $html): string
    {
        // Remove scripts and styles
        $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $html);
        $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/mi', '', $html);
        
        // Convert to text
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        return substr($text, 0, 3000); // Limit length
    }
    
    /**
     * Extract signature from email body
     */
    private function extractSignature(string $emailBody): string
    {
        // Look for common signature delimiters
        $delimiters = ['--', '---', 'Best regards', 'Regards', 'Sincerely'];
        
        foreach ($delimiters as $delimiter) {
            $parts = explode($delimiter, $emailBody);
            if (count($parts) > 1) {
                return trim(end($parts));
            }
        }
        
        // If no delimiter found, take last 500 characters
        return substr($emailBody, -500);
    }
    
    /**
     * Discover LinkedIn URL for a contact
     * Uses multiple strategies to find LinkedIn profiles
     */
    public function discoverLinkedInUrl(array $contactData): array
    {
        $firstName = $contactData['first_name'] ?? '';
        $lastName = $contactData['last_name'] ?? '';
        $email = $contactData['email'] ?? '';
        $company = $contactData['company'] ?? '';
        $jobTitle = $contactData['job_title'] ?? '';
        
        // Need at least name to search
        if (empty($firstName) && empty($lastName)) {
            return ['status' => 'error', 'message' => 'Name required for LinkedIn discovery'];
        }
        
        // Check cache
        $cacheKey = "discover_linkedin_" . md5($firstName . $lastName . $company . $email);
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        try {
            // Strategy 1: Extract from company website if available
            if (!empty($company)) {
                $domain = $this->extractDomainFromEmail($email) ?? $this->guessDomainFromCompany($company);
                if ($domain) {
                    $linkedInFromWeb = $this->extractLinkedInFromWebsite($domain, $firstName, $lastName);
                    if ($linkedInFromWeb['status'] === 'success' && !empty($linkedInFromWeb['linkedin_url'])) {
                        $url = $linkedInFromWeb['linkedin_url'];
                        // Validate URL is not a placeholder - strict check
                        if ($this->isValidLinkedInUrl($url) && !$this->containsExample($url)) {
                            $result = [
                                'status' => 'success',
                                'linkedin_url' => $url,
                                'source' => 'website',
                                'confidence' => 0.8
                            ];
                            $this->cache->set($cacheKey, json_encode($result), 86400 * 7); // Cache for 7 days
                            return $result;
                        }
                    }
                }
            }
            
            // Strategy 2: Construct LinkedIn URL from actual contact name
            // NOTE: AI doesn't have access to LinkedIn database, so we construct URLs from names
            // This creates URLs based on the contact's actual name (e.g., john-smith from "John Smith")
            if (!empty($firstName) && !empty($lastName)) {
                // Clean names - remove special characters, keep only letters
                $cleanFirst = preg_replace('/[^a-zA-Z]/', '', $firstName);
                $cleanLast = preg_replace('/[^a-zA-Z]/', '', $lastName);
                
                // Only construct if we have valid names (not empty after cleaning)
                if (!empty($cleanFirst) && !empty($cleanLast)) {
                    // Construct URL: firstname-lastname (lowercase, hyphenated)
                    $username = strtolower($cleanFirst) . '-' . strtolower($cleanLast);
                    $constructedUrl = 'https://www.linkedin.com/in/' . $username;
                    
                    // Validate it's based on actual name, not placeholder
                    if (!$this->isPlaceholderUsername($username) && !$this->containsExample($username)) {
                        $result = [
                            'status' => 'success',
                            'linkedin_url' => $constructedUrl,
                            'source' => 'name_construction',
                            'confidence' => 0.5,
                            'note' => 'URL constructed from contact name - verify manually as actual profile may differ'
                        ];
                        $this->cache->set($cacheKey, json_encode($result), 86400 * 3);
                        return $result;
                    }
                }
            }
            
            return [
                'status' => 'not_found',
                'message' => 'LinkedIn URL could not be discovered',
                'confidence' => 0.0
            ];
            
        } catch (\Exception $e) {
            error_log("LinkedIn discovery error: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Extract LinkedIn URL from company website
     */
    private function extractLinkedInFromWebsite(string $domain, string $firstName, string $lastName): array
    {
        try {
            $url = 'https://' . ltrim($domain, 'https://');
            $html = $this->fetchWebsite($url);
            
            if (!$html) {
                return ['status' => 'error', 'message' => 'Failed to fetch website'];
            }
            
            // Look for LinkedIn URLs in the HTML
            $linkedInPattern = '/linkedin\.com\/in\/([a-zA-Z0-9\-]+)/i';
            preg_match_all($linkedInPattern, $html, $matches);
            
            if (!empty($matches[0])) {
                // Use AI to determine which LinkedIn URL matches the person
                $prompt = "Given this HTML content from {$domain}, find the LinkedIn profile URL for {$firstName} {$lastName}.\n";
                $prompt .= "Found LinkedIn URLs: " . implode(', ', $matches[0]) . "\n";
                $prompt .= "Return the most likely LinkedIn URL for this person, or null if not found.";
                
                $aiResponse = $this->aiService->process('data_extraction', [
                    'prompt' => $prompt,
                    'text' => substr($html, 0, 5000), // Limit HTML size
                    'source_type' => 'website'
                ]);
                
                // Extract LinkedIn URL from AI response
                if (preg_match('/linkedin\.com\/in\/([a-zA-Z0-9\-]+)/i', $aiResponse, $urlMatch)) {
                    $username = $urlMatch[1];
                    // Validate it's not a placeholder
                    if (!$this->isPlaceholderUsername($username)) {
                        $url = 'https://www.linkedin.com/in/' . $username;
                        if ($this->isValidLinkedInUrl($url)) {
                            return [
                                'status' => 'success',
                                'linkedin_url' => $url
                            ];
                        }
                    }
                }
            }
            
            return ['status' => 'not_found'];
            
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Use AI to discover LinkedIn URL
     */
    private function discoverLinkedInWithAI(string $firstName, string $lastName, ?string $company, ?string $jobTitle, ?string $email): array
    {
        // Don't use AI for discovery - it doesn't have access to LinkedIn's database
        // Instead, construct URL directly from the name
        return ['status' => 'not_found', 'message' => 'AI discovery disabled - use URL construction instead'];
        
        // OLD CODE - Disabled because AI doesn't have LinkedIn database access
        /*
        $prompt = "Construct a LinkedIn profile URL based on this person's name:\n";
        $prompt .= "First Name: {$firstName}\n";
        $prompt .= "Last Name: {$lastName}\n";
        $prompt .= "\n";
        $prompt .= "INSTRUCTIONS:\n";
        $prompt .= "1. Construct the URL using the person's ACTUAL name: {$firstName} {$lastName}\n";
        $prompt .= "2. Format: https://www.linkedin.com/in/[firstname-lastname] (all lowercase, hyphenated)\n";
        $prompt .= "3. Remove any special characters from names, keep only letters\n";
        $prompt .= "4. Example: John Smith → https://www.linkedin.com/in/john-smith\n";
        $prompt .= "5. DO NOT use placeholder words like 'example', 'sample', 'test'\n";
        $prompt .= "6. DO NOT use company name - use PERSON'S name only\n";
        $prompt .= "\n";
        $prompt .= "Return JSON: {\"linkedin_url\": \"https://www.linkedin.com/in/[constructed-url]\", \"confidence\": 0.5}\n";
        $prompt .= "The confidence is 0.5 because this is a constructed URL that needs verification.";
        */
        $prompt .= "Construct the LinkedIn URL based on:\n";
        $prompt .= "- First name + last name (lowercase, hyphenated, e.g., 'john-smith')\n";
        $prompt .= "- Common LinkedIn URL patterns (firstname-lastname, firstnamelastname)\n";
        $prompt .= "- Company context if available\n\n";
        $prompt .= "Return JSON: {\"linkedin_url\": \"https://www.linkedin.com/in/actual-username\", \"confidence\": 0.0-1.0, \"reasoning\": \"...\"}\n";
        $prompt .= "ONLY return URLs with confidence >= 0.6. If uncertain, return null.";
        
        try {
            $aiResponse = $this->aiService->process('data_extraction', [
                'prompt' => $prompt,
                'source_type' => 'linkedin_discovery',
                'contact_data' => [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'company' => $company,
                    'job_title' => $jobTitle,
                    'email' => $email
                ]
            ]);
            
            // Parse AI response
            $jsonMatch = [];
            if (preg_match('/\{[\s\S]*\}/', $aiResponse, $jsonMatch)) {
                $data = json_decode($jsonMatch[0], true);
                if ($data && !empty($data['linkedin_url'])) {
                    $url = $data['linkedin_url'];
                    // Validate URL is not a placeholder
                    if ($this->isPlaceholderUrl($url)) {
                        return ['status' => 'not_found', 'message' => 'AI returned placeholder URL'];
                    }
                    return [
                        'status' => 'success',
                        'linkedin_url' => $url,
                        'confidence' => $data['confidence'] ?? 0.6,
                        'reasoning' => $data['reasoning'] ?? ''
                    ];
                }
            }
            
            // Fallback: try to extract URL directly
            if (preg_match('/linkedin\.com\/in\/([a-zA-Z0-9\-]+)/i', $aiResponse, $urlMatch)) {
                $username = $urlMatch[1];
                // Validate username is not a placeholder
                if ($this->isPlaceholderUsername($username)) {
                    return ['status' => 'not_found', 'message' => 'Extracted placeholder username'];
                }
                return [
                    'status' => 'success',
                    'linkedin_url' => 'https://www.linkedin.com/in/' . $username,
                    'confidence' => 0.6
                ];
            }
            
            return ['status' => 'not_found'];
            
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Construct likely LinkedIn URL based on name patterns
     */
    private function constructLinkedInUrl(string $firstName, string $lastName, ?string $company): ?string
    {
        if (empty($firstName) || empty($lastName)) {
            return null;
        }
        
        // Clean names - remove special characters, keep only letters
        $cleanFirst = preg_replace('/[^a-zA-Z]/', '', $firstName);
        $cleanLast = preg_replace('/[^a-zA-Z]/', '', $lastName);
        
        if (empty($cleanFirst) || empty($cleanLast)) {
            return null;
        }
        
        // Common LinkedIn URL patterns
        $patterns = [
            // firstname-lastname (most common)
            strtolower($cleanFirst) . '-' . strtolower($cleanLast),
            // firstnamelastname
            strtolower($cleanFirst) . strtolower($cleanLast),
        ];
        
        // Return the most likely pattern (first one)
        return 'https://www.linkedin.com/in/' . $patterns[0];
    }
    
    /**
     * Check if URL is a placeholder/example URL
     */
    private function isPlaceholderUrl(string $url): bool
    {
        $placeholderPatterns = [
            '/example/i',
            '/sample/i',
            '/test/i',
            '/placeholder/i',
            '/demo/i',
            '/dummy/i',
            '/fake/i',
            '/company\//i', // LinkedIn company pages, not personal profiles
            '/example-company/i',
            '/examplecompany/i',
            '/test-company/i',
            '/testcompany/i',
        ];
        
        foreach ($placeholderPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if username is a placeholder
     */
    private function isPlaceholderUsername(string $username): bool
    {
        $placeholderUsernames = [
            'example',
            'sample',
            'test',
            'placeholder',
            'demo',
            'dummy',
            'fake',
            'example-company',
            'examplecompany',
            'test-company',
            'testcompany',
        ];
        
        $lowerUsername = strtolower($username);
        
        // Check for exact matches
        foreach ($placeholderUsernames as $placeholder) {
            if ($lowerUsername === $placeholder) {
                return true;
            }
        }
        
        // Check if username contains placeholder words
        foreach ($placeholderUsernames as $placeholder) {
            if (strpos($lowerUsername, $placeholder) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if string contains "example" (case insensitive)
     */
    private function containsExample(string $text): bool
    {
        return stripos($text, 'example') !== false;
    }
    
    /**
     * Validate LinkedIn URL format
     */
    private function isValidLinkedInUrl(string $url): bool
    {
        // REJECT company pages - only accept personal profiles (/in/)
        if (preg_match('/\/company\//i', $url)) {
            return false; // Company pages are not personal profiles
        }
        
        // Must be a personal profile (/in/), not company page (/company/)
        if (!preg_match('/^https?:\/\/(www\.)?linkedin\.com\/in\/[a-zA-Z0-9\-]+\/?$/', $url)) {
            return false;
        }
        
        // Must not be a placeholder
        if ($this->isPlaceholderUrl($url)) {
            return false;
        }
        
        // Must not contain "example" anywhere
        if ($this->containsExample($url)) {
            return false;
        }
        
        // Extract username and check it's not a placeholder
        $username = $this->extractLinkedInUsername($url);
        if ($username && ($this->isPlaceholderUsername($username) || $this->containsExample($username))) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Extract LinkedIn username from URL
     */
    private function extractLinkedInUsername(string $url): ?string
    {
        if (preg_match('/linkedin\.com\/in\/([^\/\?]+)/', $url, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    /**
     * Extract Twitter username from URL
     */
    private function extractTwitterUsername(string $url): ?string
    {
        if (preg_match('/twitter\.com\/([^\/\?]+)/', $url, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    /**
     * Extract domain from email address
     */
    private function extractDomainFromEmail(?string $email): ?string
    {
        return (new EmailDomainClassifier())->extractBusinessDomainFromEmail($email);
    }
    
    /**
     * Guess domain from company name
     */
    private function guessDomainFromCompany(string $companyName): ?string
    {
        if (empty($companyName)) {
            return null;
        }
        // Simple domain guessing - clean company name and add .com
        $cleanName = strtolower(preg_replace('/[^a-z0-9]/', '', $companyName));
        if (empty($cleanName)) {
            return null;
        }
        return $cleanName . '.com';
    }
}
