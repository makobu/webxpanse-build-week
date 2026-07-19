<?php
/**
 * Third-Party Enrichment Service
 * 
 * Integrates with professional enrichment APIs for reliable contact data
 */

namespace CRM\Services;

use CRM\Database;
use CRM\CacheManager;

class ThirdPartyEnrichmentService
{
    private CacheManager $cache;
    private EmailDomainClassifier $emailDomainClassifier;
    
    public function __construct()
    {
        $this->cache = new CacheManager();
        $this->emailDomainClassifier = new EmailDomainClassifier();
    }
    
    /**
     * Enrich contact using Clearbit API
     * Requires: CLEARBIT_API_KEY in .env
     */
    public function enrichWithClearbit(string $email, ?string $domain = null): array
    {
        $apiKey = $_ENV['CLEARBIT_API_KEY'] ?? null;
        if (!$apiKey) {
            return ['status' => 'error', 'message' => 'Clearbit API key not configured'];
        }
        
        // Check cache
        $cacheKey = "clearbit_" . md5($email);
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        try {
            $url = "https://person.clearbit.com/v2/combined/find?email=" . urlencode($email);
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT => 10
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                
                $enriched = [
                    'status' => 'success',
                    'source' => 'clearbit',
                    'data' => $this->parseClearbitResponse($data)
                ];
                
                // Cache for 7 days
                $this->cache->set($cacheKey, json_encode($enriched), 86400 * 7);
                return $enriched;
            } elseif ($httpCode === 404) {
                return ['status' => 'not_found', 'message' => 'Contact not found in Clearbit'];
            } else {
                return ['status' => 'error', 'message' => 'Clearbit API error: HTTP ' . $httpCode];
            }
            
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Verify email address using Hunter.io API
     * Requires: HUNTER_API_KEY in .env
     */
    public function verifyEmail(string $email): array
    {
        $apiKey = $_ENV['HUNTER_API_KEY'] ?? null;
        if (!$apiKey) {
            return ['status' => 'error', 'message' => 'Hunter.io API key not configured'];
        }
        
        // Check cache
        $cacheKey = "hunter_verify_" . md5($email);
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        try {
            $url = "https://api.hunter.io/v2/email-verifier?email=" . urlencode($email) . "&api_key=" . $apiKey;
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                return ['status' => 'error', 'message' => 'CURL error: ' . $curlError];
            }
            
            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                
                if (isset($data['errors'])) {
                    return ['status' => 'error', 'message' => 'Hunter.io API error: ' . json_encode($data['errors'])];
                }
                
                $result = $data['data'] ?? [];
                $verificationData = [
                    'status' => 'success',
                    'email' => $result['email'] ?? $email,
                    'email_verified' => ($result['result'] ?? '') === 'deliverable',
                    'email_verification_status' => $result['result'] ?? null,
                    'confidence_score' => $result['score'] ?? null,
                    'sources' => $result['sources'] ?? [],
                    'smtp_check' => $result['smtp_check'] ?? null,
                    'smtp_score' => $result['smtp_score'] ?? null,
                    'regex_check' => $result['regex_check'] ?? null,
                    'disposable' => $result['disposable'] ?? false,
                    'webmail' => $result['webmail'] ?? false,
                    'mx_records' => $result['mx_records'] ?? null,
                    'mx_found' => $result['mx_found'] ?? null
                ];
                
                // Cache for 7 days
                $this->cache->set($cacheKey, json_encode($verificationData), 86400 * 7);
                return $verificationData;
            } else {
                $errorData = json_decode($response, true);
                $errorMessage = $errorData['errors'][0]['details'] ?? 'HTTP ' . $httpCode;
                return ['status' => 'error', 'message' => 'Hunter.io API error: ' . $errorMessage];
            }
            
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Find email using Hunter.io Email Finder API
     * Requires: first name + last name + company domain
     * Returns: email address and contact details
     */
    public function findEmailWithHunter(string $firstName, string $lastName, string $domain, ?string $company = null): array
    {
        $apiKey = $_ENV['HUNTER_API_KEY'] ?? null;
        if (!$apiKey) {
            return ['status' => 'error', 'message' => 'Hunter.io API key not configured'];
        }
        
        // Check cache
        $cacheKey = "hunter_find_" . md5($firstName . $lastName . $domain);
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        try {
            $url = "https://api.hunter.io/v2/email-finder?";
            $params = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'domain' => $domain,
                'api_key' => $apiKey
            ];
            if ($company) {
                $params['company'] = $company;
            }
            
            $url .= http_build_query($params);
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                return ['status' => 'error', 'message' => 'CURL error: ' . $curlError];
            }
            
            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                
                if (isset($data['errors'])) {
                    return ['status' => 'error', 'message' => 'Hunter.io API error: ' . json_encode($data['errors'])];
                }
                
                $result = $data['data'] ?? [];
                $enriched = [
                    'status' => 'success',
                    'source' => 'hunter_finder',
                    'data' => $this->parseHunterFinderResponse($result)
                ];
                
                // Cache for 7 days
                $this->cache->set($cacheKey, json_encode($enriched), 86400 * 7);
                return $enriched;
            } else {
                $errorData = json_decode($response, true);
                $errorMessage = $errorData['errors'][0]['details'] ?? 'HTTP ' . $httpCode;
                return ['status' => 'error', 'message' => 'Hunter.io API error: ' . $errorMessage];
            }
            
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Search domain using Hunter.io Domain Search API
     * Requires: company domain
     * Returns: list of emails and contacts from that domain
     */
    public function searchDomainWithHunter(string $domain, ?int $limit = 10, ?string $department = null): array
    {
        $apiKey = $_ENV['HUNTER_API_KEY'] ?? null;
        if (!$apiKey) {
            return ['status' => 'error', 'message' => 'Hunter.io API key not configured'];
        }
        
        // Check cache
        $cacheKey = "hunter_domain_" . md5($domain . $limit . $department);
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        try {
            $url = "https://api.hunter.io/v2/domain-search?";
            $params = [
                'domain' => $domain,
                'api_key' => $apiKey,
                'limit' => $limit ?? 10
            ];
            if ($department) {
                $params['department'] = $department;
            }
            
            $url .= http_build_query($params);
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                return ['status' => 'error', 'message' => 'CURL error: ' . $curlError];
            }
            
            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                
                if (isset($data['errors'])) {
                    return ['status' => 'error', 'message' => 'Hunter.io API error: ' . json_encode($data['errors'])];
                }
                
                $result = $data['data'] ?? [];
                $enriched = [
                    'status' => 'success',
                    'source' => 'hunter_domain',
                    'data' => $this->parseHunterDomainResponse($result)
                ];
                
                // Cache for 7 days
                $this->cache->set($cacheKey, json_encode($enriched), 86400 * 7);
                return $enriched;
            } else {
                $errorData = json_decode($response, true);
                $errorMessage = $errorData['errors'][0]['details'] ?? 'HTTP ' . $httpCode;
                return ['status' => 'error', 'message' => 'Hunter.io API error: ' . $errorMessage];
            }
            
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Enrich contact using Hunter.io API - Optimized to use the best endpoint
     * Intelligently chooses: Email Finder, Domain Search, or Email Verifier
     * Requires: HUNTER_API_KEY in .env
     */
    public function enrichWithHunter(string $email = null, ?string $firstName = null, ?string $lastName = null, ?string $company = null, ?string $domain = null): array
    {
        $apiKey = $_ENV['HUNTER_API_KEY'] ?? null;
        if (!$apiKey) {
            return ['status' => 'error', 'message' => 'Hunter.io API key not configured'];
        }
        
        // Extract domain from email if not provided
        if (!$domain && $email) {
            $domain = $this->extractDomainFromEmail($email);
        }
        // Extract domain from company website if available
        if (!$domain && $company) {
            $domain = $this->extractDomainFromCompany($company);
        }
        
        // Strategy 1: If we have email, verify it and get additional data
        if ($email) {
            $verificationResult = $this->verifyEmail($email);
            if ($verificationResult['status'] === 'success') {
                // If email is verified and we have domain, also do domain search for company info
                $enrichedData = [
                    'email' => $verificationResult['email'] ?? $email,
                    'email_verified' => $verificationResult['email_verified'] ?? false,
                    'email_verification_status' => $verificationResult['email_verification_status'] ?? null,
                    'confidence_score' => $verificationResult['confidence_score'] ?? null
                ];
                
                // If we have domain, get company-level data
                if ($domain) {
                    $domainResult = $this->searchDomainWithHunter($domain, 1);
                    if ($domainResult['status'] === 'success' && !empty($domainResult['data'])) {
                        // Merge company data from domain search
                        $enrichedData = array_merge($enrichedData, $domainResult['data']);
                    }
                }
                
                return [
                    'status' => 'success',
                    'source' => 'hunter',
                    'data' => $enrichedData
                ];
            }
        }
        
        // Strategy 2: If we have name + domain but no email, use Email Finder
        if ($firstName && $lastName && $domain) {
            $finderResult = $this->findEmailWithHunter($firstName, $lastName, $domain, $company);
            if ($finderResult['status'] === 'success' && !empty($finderResult['data'])) {
                return $finderResult;
            }
        }
        
        // Strategy 3: If we have domain but no email/name, use Domain Search
        if ($domain && !$email) {
            $domainResult = $this->searchDomainWithHunter($domain, 10);
            if ($domainResult['status'] === 'success' && !empty($domainResult['data'])) {
                return $domainResult;
            }
        }
        
        // Fallback: If we only have email, just verify it
        if ($email) {
            return [
                'status' => 'success',
                'source' => 'hunter',
                'data' => [
                    'email' => $email,
                    'email_verified' => false,
                    'email_verification_status' => null
                ]
            ];
        }
        
        return ['status' => 'error', 'message' => 'Insufficient data for Hunter.io enrichment'];
    }
    
    /**
     * Extract domain from email address
     */
    private function extractDomainFromEmail(string $email): ?string
    {
        return $this->emailDomainClassifier->extractBusinessDomainFromEmail($email);
    }
    
    /**
     * Extract domain from company name or website
     */
    private function extractDomainFromCompany(string $company): ?string
    {
        // If it's already a domain/URL, extract it
        if (preg_match('/https?:\/\/(?:www\.)?([^\/]+)/i', $company, $matches)) {
            return $this->normalizeBusinessDomain($matches[1]);
        }
        // If it looks like a domain
        if (preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)*$/i', $company)) {
            return $this->normalizeBusinessDomain($company);
        }
        return null;
    }

    private function normalizeBusinessDomain(?string $domain): ?string
    {
        $normalized = $this->emailDomainClassifier->normalizeDomain($domain);
        if ($normalized === null || $this->emailDomainClassifier->isPublicEmailProviderDomain($normalized)) {
            return null;
        }

        return $normalized;
    }
    
    /**
     * Parse Hunter.io Email Finder response
     */
    private function parseHunterFinderResponse(array $result): array
    {
        return [
            'email' => $result['email'] ?? null,
            'first_name' => $result['first_name'] ?? null,
            'last_name' => $result['last_name'] ?? null,
            'full_name' => $result['full_name'] ?? null,
            'job_title' => $result['position'] ?? null,
            'company' => $result['company'] ?? null,
            'company_website' => $result['website'] ?? null,
            'phone' => $result['phone_number'] ?? null,
            'linkedin_url' => $result['linkedin'] ?? null,
            'twitter_url' => $result['twitter'] ?? null,
            'location' => $result['country'] ?? null,
            'email_verified' => ($result['verification']['result'] ?? '') === 'deliverable',
            'email_verification_status' => $result['verification']['result'] ?? null,
            'confidence_score' => $result['score'] ?? null,
            'sources' => $result['sources'] ?? []
        ];
    }
    
    /**
     * Parse Hunter.io Domain Search response
     */
    private function parseHunterDomainResponse(array $result): array
    {
        $data = [
            'company' => $result['organization'] ?? null,
            'company_website' => $result['domain'] ? 'https://' . $result['domain'] : null,
            'company_description' => $result['description'] ?? null,
            'company_industry' => $result['industry'] ?? null,
            'company_size' => $result['employees'] ?? null,
            'company_founded' => $result['founded'] ?? null,
            'linkedin_url' => $result['linkedin'] ?? null,
            'twitter_url' => $result['twitter'] ?? null,
            'facebook_url' => $result['facebook'] ?? null,
            'phone' => $result['phone_numbers'] ?? null,
            'emails' => []
        ];
        
        // Parse emails from domain search
        if (!empty($result['emails']) && is_array($result['emails'])) {
            foreach ($result['emails'] as $emailData) {
                $data['emails'][] = [
                    'email' => $emailData['value'] ?? null,
                    'first_name' => $emailData['first_name'] ?? null,
                    'last_name' => $emailData['last_name'] ?? null,
                    'job_title' => $emailData['position'] ?? null,
                    'department' => $emailData['department'] ?? null,
                    'linkedin_url' => $emailData['linkedin'] ?? null,
                    'twitter_url' => $emailData['twitter'] ?? null,
                    'confidence_score' => $emailData['confidence'] ?? null,
                    'sources' => $emailData['sources'] ?? []
                ];
            }
        }
        
        return $data;
    }
    
    /**
     * Enrich contact using People Data Labs API
     * Requires: PDL_API_KEY in .env
     */
    public function enrichWithPDL(string $email, ?string $firstName = null, ?string $lastName = null): array
    {
        $apiKey = $_ENV['PDL_API_KEY'] ?? null;
        if (!$apiKey) {
            return ['status' => 'error', 'message' => 'People Data Labs API key not configured'];
        }
        
        // Check cache
        $cacheKey = "pdl_" . md5($email . $firstName . $lastName);
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        try {
            $url = "https://api.peopledatalabs.com/v5/person/enrich";
            
            $params = ['api_key' => $apiKey];
            if ($email) $params['email'] = $email;
            if ($firstName) $params['first_name'] = $firstName;
            if ($lastName) $params['last_name'] = $lastName;
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($params),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT => 10
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                
                $enriched = [
                    'status' => 'success',
                    'source' => 'pdl',
                    'data' => $this->parsePDLResponse($data)
                ];
                
                // Cache for 7 days
                $this->cache->set($cacheKey, json_encode($enriched), 86400 * 7);
                return $enriched;
            } else {
                return ['status' => 'error', 'message' => 'PDL API error: HTTP ' . $httpCode];
            }
            
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Parse Clearbit response into our format
     */
    private function parseClearbitResponse(array $data): array
    {
        $person = $data['person'] ?? [];
        $company = $data['company'] ?? [];
        
        return [
            'first_name' => $person['name']['givenName'] ?? null,
            'last_name' => $person['name']['familyName'] ?? null,
            'email' => $person['email'] ?? null,
            'phone' => $person['phone'] ?? null,
            'job_title' => $person['employment']['title'] ?? null,
            'company' => $company['name'] ?? null,
            'company_website' => $company['domain'] ? 'https://' . $company['domain'] : null,
            'company_size' => $company['metrics']['employees'] ?? null,
            'company_industry' => $company['category']['industry'] ?? null,
            'company_description' => $company['description'] ?? null,
            'company_founded' => $company['foundedYear'] ?? null,
            'location' => $this->formatLocation($person['geo'] ?? []),
            'linkedin_url' => $person['linkedin']['handle'] ? 'https://www.linkedin.com/in/' . $person['linkedin']['handle'] : null,
            'twitter_url' => $person['twitter']['handle'] ? 'https://twitter.com/' . $person['twitter']['handle'] : null,
        ];
    }
    
    /**
     * Parse Hunter.io response
     */
    private function parseHunterResponse(array $data): array
    {
        $result = $data['data'] ?? [];
        
        return [
            'email' => $result['email'] ?? null,
            'email_verified' => ($result['result'] ?? '') === 'deliverable',
            'email_verification_status' => $result['result'] ?? null,
            'first_name' => $result['first_name'] ?? null,
            'last_name' => $result['last_name'] ?? null,
            'phone' => $result['phone_number'] ?? null,
            'company' => $result['company'] ?? null,
            'linkedin_url' => $result['linkedin'] ?? null,
            'twitter_url' => $result['twitter'] ?? null,
        ];
    }
    
    /**
     * Parse People Data Labs response
     */
    private function parsePDLResponse(array $data): array
    {
        return [
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['emails'][0]['address'] ?? null,
            'phone' => $data['phone_numbers'][0]['number'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'company' => $data['job_company_name'] ?? null,
            'company_website' => $data['job_company_website'] ?? null,
            'company_size' => $data['job_company_size'] ?? null,
            'company_industry' => $data['job_company_industry'] ?? null,
            'location' => $this->formatPDLLocation($data),
            'linkedin_url' => $data['profiles'][0]['url'] ?? null,
            'twitter_url' => $this->findTwitterUrl($data['profiles'] ?? []),
        ];
    }
    
    /**
     * Format location from Clearbit geo data
     */
    private function formatLocation(array $geo): ?string
    {
        $parts = [];
        if (!empty($geo['city'])) $parts[] = $geo['city'];
        if (!empty($geo['state'])) $parts[] = $geo['state'];
        if (!empty($geo['country'])) $parts[] = $geo['country'];
        return !empty($parts) ? implode(', ', $parts) : null;
    }
    
    /**
     * Format location from PDL data
     */
    private function formatPDLLocation(array $data): ?string
    {
        $parts = [];
        if (!empty($data['location_names'][0])) $parts[] = $data['location_names'][0];
        if (!empty($data['location_region'])) $parts[] = $data['location_region'];
        if (!empty($data['location_country'])) $parts[] = $data['location_country'];
        return !empty($parts) ? implode(', ', $parts) : null;
    }
    
    /**
     * Find Twitter URL from profiles array
     */
    private function findTwitterUrl(array $profiles): ?string
    {
        foreach ($profiles as $profile) {
            if (($profile['network'] ?? '') === 'twitter') {
                return $profile['url'] ?? null;
            }
        }
        return null;
    }
}
