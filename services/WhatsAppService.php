<?php
/**
 * WhatsApp Business API Service
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\DemoModeManager;
use CRM\Services\WorkspaceContext;

class WhatsAppService
{
    private string $baseUrl = 'https://graph.facebook.com/v24.0';
    private string $phoneNumberId;
    private string $accessToken;
    private ?string $businessAccountId = null;
    private string $mode;
    
    public function __construct(string $mode = 'default')
    {
        $this->mode = $mode;
        $isAssistant = $mode === 'assistant';

        $phoneNumberId = $isAssistant
            ? ($_ENV['WHATSAPP_ASSISTANT_PHONE_NUMBER_ID'] ?? ($_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? ''))
            : ($_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? '');
        $this->phoneNumberId = preg_replace('/\s+/', '', trim($phoneNumberId));
        
        $accessToken = $isAssistant
            ? ($_ENV['WHATSAPP_ASSISTANT_ACCESS_TOKEN'] ?? ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? ''))
            : ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? '');
        $this->accessToken = trim($accessToken);
        
        $businessAccountId = $isAssistant
            ? ($_ENV['WHATSAPP_ASSISTANT_BUSINESS_ACCOUNT_ID'] ?? ($_ENV['WHATSAPP_BUSINESS_ACCOUNT_ID'] ?? ''))
            : ($_ENV['WHATSAPP_BUSINESS_ACCOUNT_ID'] ?? '');
        $this->businessAccountId = !empty($businessAccountId) ? trim($businessAccountId) : null;

        if ($isAssistant) {
            try {
                $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
                if ($workspaceId > 0) {
                    $assistantConfig = (new WorkspaceAssistantConfigService())->whatsappRuntimeConfig($workspaceId);
                    $workspacePhoneNumberId = trim((string) ($assistantConfig['assistant_phone_number_id'] ?? ''));
                    $workspaceAccessToken = trim((string) ($assistantConfig['access_token'] ?? ''));
                    if ($workspacePhoneNumberId !== '') {
                        $this->phoneNumberId = preg_replace('/\s+/', '', $workspacePhoneNumberId);
                    }
                    if ($workspaceAccessToken !== '') {
                        $this->accessToken = $workspaceAccessToken;
                    }
                }
            } catch (\Throwable $e) {
                // Fall back to assistant/platform environment credentials.
            }
        } else {
            try {
                $workspaceIntegration = (new WorkspaceConnectService())->getActiveWhatsAppIntegration();
                if ($workspaceIntegration) {
                    $workspacePhoneNumberId = trim((string) ($workspaceIntegration['phone_number_id'] ?? ''));
                    $workspaceAccessToken = trim((string) ($workspaceIntegration['access_token'] ?? ''));
                    $workspaceBusinessAccountId = trim((string) ($workspaceIntegration['whatsapp_business_account_id'] ?? ''));
                    if ($workspacePhoneNumberId !== '') {
                        $this->phoneNumberId = preg_replace('/\s+/', '', $workspacePhoneNumberId);
                    }
                    if ($workspaceAccessToken !== '') {
                        $this->accessToken = $workspaceAccessToken;
                    }
                    if ($workspaceBusinessAccountId !== '') {
                        $this->businessAccountId = $workspaceBusinessAccountId;
                    }
                }
            } catch (\Throwable $e) {
                // Fall back to platform environment credentials.
            }
        }
        
        // Only log configuration issues in debug mode; never log token previews.
        if ($this->isDebugEnabled()) {
            if (empty($this->phoneNumberId)) {
                error_log("WhatsAppService: WHATSAPP_PHONE_NUMBER_ID is empty.");
            }
            if (empty($this->accessToken)) {
                error_log("WhatsAppService: WHATSAPP_ACCESS_TOKEN is empty.");
            }
        }
    }
    
    /**
     * Send template message
     */
    public function sendTemplateMessage(string $to, string $templateName, string $languageCode = 'en_US', array $components = []): array
    {
        $this->guardManagedBillingBeforeSend($templateName);

        $data = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $languageCode]
            ]
        ];
        
        if (!empty($components)) {
            $data['template']['components'] = $components;
        }
        
        if ($this->isVerboseDebugEnabled()) {
            error_log('WhatsApp sendTemplateMessage: template=' . $templateName . ' lang=' . $languageCode . ' components=' . json_encode($data['template']['components'] ?? []));
        }
        
        return $this->makeRequest('POST', 'messages', $data);
    }
    
    /**
     * Build template components from params and template structure.
     * Converts user params to correct API format (text, image, currency, date_time).
     * Components are returned in correct order: header first, then body, then buttons.
     *
     * @param array $templateParams User params: ['body' => [...], 'header' => [...], 'buttons' => [...]]
     * @param array|null $templateStructure Template from getTemplates (with components, parameter_schema)
     * @return array API-ready components for sendTemplateMessage
     */
    public function buildTemplateComponents(array $templateParams, ?array $templateStructure = null): array
    {
        // Strip internal keys that are not template parameters
        $templateParams = array_diff_key($templateParams, ['_language' => 1]);
        
        $components = [];
        $schema = $templateStructure['parameter_schema'] ?? ['header' => [], 'body' => [], 'buttons' => []];
        $isCarousel = !empty($templateStructure['is_carousel']);
        $carouselSchema = $schema['carousel'] ?? null;
        
        // Carousel template: build carousel component
        if ($isCarousel && $carouselSchema) {
            $cards = $carouselSchema['cards'] ?? [];
            $hasParams = false;
            foreach ($cards as $card) {
                if (!empty($card['header']) || !empty($card['body']) || !empty($card['buttons'])) {
                    $hasParams = true;
                    break;
                }
            }
            // If carousel has no variables, send empty components (template uses static content)
            if (!$hasParams) {
                return [];
            }
            return $this->buildCarouselComponents($templateParams, $templateStructure, $carouselSchema);
        }
        
        // 1. Header (first - correct order)
        $headerValues = $templateParams['header'] ?? [];
        if (!is_array($headerValues)) {
            $headerValues = $headerValues ? [$headerValues] : [];
        }
        $headerTypes = $schema['header'] ?? [];
        if (!empty($headerValues)) {
            $params = [];
            foreach ($headerValues as $i => $v) {
                $paramType = $headerTypes[$i]['type'] ?? 'text';
                $params[] = $this->formatParameter(trim((string) $v), $paramType);
            }
            if (!empty($params)) {
                $components[] = ['type' => 'header', 'parameters' => $params];
            }
        }
        
        // 2. Body
        $bodyValues = $templateParams['body'] ?? [];
        if (!is_array($bodyValues)) {
            $bodyValues = $bodyValues ? [$bodyValues] : [];
        }
        $bodyTypes = $schema['body'] ?? [];
        if (!empty($bodyValues)) {
            $params = [];
            foreach ($bodyValues as $i => $v) {
                $paramType = $bodyTypes[$i]['type'] ?? 'text';
                $params[] = $this->formatParameter(trim((string) $v), $paramType);
            }
            if (!empty($params)) {
                $components[] = ['type' => 'body', 'parameters' => $params];
            }
        }
        
        // 3. Buttons (URL with dynamic param, or payload)
        $buttonValues = $templateParams['buttons'] ?? [];
        if (!is_array($buttonValues)) {
            $buttonValues = $buttonValues ? [$buttonValues] : [];
        }
        if (!empty($buttonValues) && !empty($templateStructure['components'] ?? [])) {
            $templateComponents = $templateStructure['components'];
            foreach ($templateComponents as $comp) {
                if (($comp['type'] ?? '') !== 'BUTTONS') {
                    continue;
                }
                $buttons = $comp['buttons'] ?? [];
                foreach ($buttons as $idx => $btn) {
                    if (($btn['type'] ?? '') === 'URL' && isset($buttonValues[$idx])) {
                        $components[] = [
                            'type' => 'button',
                            'sub_type' => 'url',
                            'index' => $idx,
                            'parameters' => [['type' => 'text', 'text' => trim((string) $buttonValues[$idx])]]
                        ];
                    }
                }
            }
        }
        
        // Pre-send validation: parameter counts should match schema when template structure exists
        if ($templateStructure) {
            $expectedBody = count($schema['body'] ?? []);
            $expectedHeader = count($schema['header'] ?? []);
            if ($expectedBody > 0 && count($bodyValues) !== $expectedBody) {
                throw new \RuntimeException(
                    "Template expects {$expectedBody} body parameter(s), got " . count($bodyValues) . ". " .
                    "Provide one value per line for each {{1}}, {{2}}, etc. placeholder."
                );
            }
            if ($expectedHeader > 0 && count($headerValues) !== $expectedHeader) {
                $headerType = $schema['header'][0]['type'] ?? 'text';
                $hint = match ($headerType) {
                    'image' => ' This template requires a header image. Enter an image URL (e.g. https://example.com/image.jpg) in the Header Parameters field.',
                    'video' => ' This template requires a header video. Enter a video URL in the Header Parameters field.',
                    'document' => ' This template requires a header document. Enter a document URL in the Header Parameters field.',
                    default => ' Fill in the Header Parameters field above.',
                };
                throw new \RuntimeException(
                    "Template expects {$expectedHeader} header parameter(s), got " . count($headerValues) . ".{$hint}"
                );
            }
        }
        
        return $components;
    }
    
    /**
     * Build carousel template components.
     * templateParams['carousel'] = [ { header: [url], body: [text,...], buttons: [url|payload,...] }, ... ]
     */
    private function buildCarouselComponents(array $templateParams, array $templateStructure, array $carouselSchema): array
    {
        $components = [];
        $rawComponents = $templateStructure['components'] ?? [];
        
        // 1. Top-level BODY (if present)
        foreach ($rawComponents as $comp) {
            if (($comp['type'] ?? '') === 'BODY') {
                $bodyVals = $templateParams['body'] ?? [];
                if (!is_array($bodyVals)) {
                    $bodyVals = $bodyVals ? [$bodyVals] : [];
                }
                $bodySchema = $schema['body'] ?? [];
                $params = $comp['parameters'] ?? [];
                if (!empty($bodyVals)) {
                    $params = [];
                    $bodyTypes = ($templateStructure['parameter_schema'] ?? [])['body'] ?? [];
                    foreach ($bodyVals as $i => $v) {
                        $paramType = $bodyTypes[$i]['type'] ?? 'text';
                        $params[] = $this->formatParameter(trim((string) $v), $paramType);
                    }
                    if (!empty($params)) {
                        $components[] = ['type' => 'body', 'parameters' => $params];
                    }
                }
                break;
            }
        }
        
        // 2. Carousel with cards
        $carouselCards = $templateParams['carousel'] ?? [];
        if (!is_array($carouselCards)) {
            $carouselCards = [];
        }
        $cardsSchema = $carouselSchema['cards'] ?? [];
        $templateCarousel = null;
        foreach ($rawComponents as $comp) {
            if (($comp['type'] ?? '') === 'CAROUSEL') {
                $templateCarousel = $comp;
                break;
            }
        }
        
        if (!$templateCarousel) {
            return $components;
        }
        
        $templateCards = $templateCarousel['cards'] ?? [];
        $numCards = max(count($carouselCards), count($templateCards), count($cardsSchema), 1);
        $apiCards = [];
        
        for ($cardIdx = 0; $cardIdx < $numCards; $cardIdx++) {
            $templateCard = $templateCards[$cardIdx] ?? [];
            $cardSchema = $cardsSchema[$cardIdx] ?? ['header' => [], 'body' => [], 'buttons' => []];
            $userCard = $carouselCards[$cardIdx] ?? [];
            if (!is_array($userCard)) {
                $userCard = [];
            }
            
            $cardComponents = [];
            
            // Build from userCard using cardSchema (don't rely on template structure which may differ)
            $headerVals = $userCard['header'] ?? [];
            if (!is_array($headerVals)) {
                $headerVals = $headerVals ? [$headerVals] : [];
            }
            $headerTypes = $cardSchema['header'] ?? [];
            if (!empty($headerVals)) {
                $val = trim((string) ($headerVals[0] ?? ''));
                if ($val !== '') {
                    $paramType = $headerTypes[0]['type'] ?? 'image';
                    $cardComponents[] = ['type' => 'header', 'parameters' => [$this->formatParameter($val, $paramType)]];
                }
            }
            
            $bodyVals = $userCard['body'] ?? [];
            if (!is_array($bodyVals)) {
                $bodyVals = $bodyVals ? [$bodyVals] : [];
            }
            $bodyTypes = $cardSchema['body'] ?? [];
            if (!empty($bodyVals)) {
                $params = [];
                foreach ($bodyVals as $i => $v) {
                    $paramType = ($bodyTypes[$i]['type'] ?? 'text');
                    $params[] = $this->formatParameter(trim((string) $v), $paramType);
                }
                if (!empty($params)) {
                    $cardComponents[] = ['type' => 'body', 'parameters' => $params];
                }
            }
            
            $buttonVals = $userCard['buttons'] ?? [];
            if (!is_array($buttonVals)) {
                $buttonVals = $buttonVals ? [$buttonVals] : [];
            }
            $templateCardComps = $templateCard['components'] ?? [];
            $btnIdx = 0;
            foreach ($templateCardComps as $cc) {
                if (strtolower($cc['type'] ?? '') === 'buttons') {
                    foreach ($cc['buttons'] ?? [] as $btn) {
                        if (($btn['type'] ?? '') === 'URL' && isset($buttonVals[$btnIdx])) {
                            $cardComponents[] = [
                                'type' => 'button',
                                'sub_type' => 'url',
                                'index' => $btnIdx,
                                'parameters' => [['type' => 'text', 'text' => trim((string) $buttonVals[$btnIdx])]]
                            ];
                        } elseif (($btn['type'] ?? '') === 'QUICK_REPLY' && isset($buttonVals[$btnIdx])) {
                            $cardComponents[] = [
                                'type' => 'button',
                                'sub_type' => 'quick_reply',
                                'index' => $btnIdx,
                                'parameters' => [['type' => 'payload', 'payload' => trim((string) $buttonVals[$btnIdx])]]
                            ];
                        }
                        $btnIdx++;
                    }
                    break;
                }
            }
            if (empty($templateCardComps) && !empty($buttonVals)) {
                foreach ($buttonVals as $btnIdx => $bv) {
                    $cardComponents[] = [
                        'type' => 'button',
                        'sub_type' => 'quick_reply',
                        'index' => $btnIdx,
                        'parameters' => [['type' => 'payload', 'payload' => trim((string) $bv)]]
                    ];
                }
            }
            
            // API requires each card to have at least one component
            if (empty($cardComponents)) {
                continue;
            }
            $apiCards[] = [
                'card_index' => count($apiCards),
                'components' => $cardComponents
            ];
        }
        
        if (!empty($apiCards)) {
            $components[] = ['type' => 'carousel', 'cards' => $apiCards];
        }
        
        return $components;
    }
    
    /**
     * Infer parameter type from example value (e.g. from Meta API example field).
     * Uses simple heuristics: currency (amount|CODE), date-like, URL for media.
     */
    private function inferTypeFromExampleValue(mixed $value): string
    {
        $s = is_scalar($value) ? trim((string) $value) : '';
        if ($s === '') {
            return 'text';
        }
        if (preg_match('/^\d+(\.\d+)?\s*\|\s*[A-Z]{3}$/i', $s)) {
            return 'currency';
        }
        if (preg_match('/\d{1,4}[-\/]\d{1,2}[-\/]\d{1,4}|\d{1,2}\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)/i', $s)) {
            return 'date_time';
        }
        if (preg_match('#^https?://#i', $s)) {
            return 'text'; // Could be image/video/document but we default to text without format context
        }
        return 'text';
    }

    /**
     * Infer body parameter schema from body text when API returns empty parameters.
     * Counts {{1}}, {{2}}, etc. placeholders and returns schema entries (default text).
     */
    private function inferBodySchemaFromText(string $bodyText): array
    {
        $schema = [];
        $max = 0;
        if (preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $m)) {
            foreach ($m[1] as $n) {
                $max = max($max, (int) $n);
            }
        }
        for ($i = 0; $i < $max; $i++) {
            $schema[] = ['type' => 'text'];
        }
        return $schema;
    }

    /**
     * Infer parameter type from API response - Meta may use type key or nested keys
     */
    private function inferParamType(array $param, string $fallback = 'text'): string
    {
        if (!empty($param['type'])) {
            return strtolower($param['type']);
        }
        if (isset($param['currency'])) {
            return 'currency';
        }
        if (isset($param['date_time'])) {
            return 'date_time';
        }
        if (isset($param['image'])) {
            return 'image';
        }
        if (isset($param['video'])) {
            return 'video';
        }
        if (isset($param['document'])) {
            return 'document';
        }
        return strtolower($fallback);
    }
    
    /**
     * Format a single parameter value for WhatsApp API.
     */
    private function formatParameter(string $value, string $paramType): array
    {
        $paramType = strtolower($paramType);
        switch ($paramType) {
            case 'currency':
                // amount_1000 = amount in 1000ths of unit (e.g. 10.50 USD = 10500)
                $parts = explode('|', $value, 2);
                $amount = preg_replace('/[^\d.]/', '', $parts[0] ?? '0');
                $code = strtoupper(trim($parts[1] ?? 'USD'));
                $amountFloat = (float) $amount;
                $amount1000 = (int) round($amountFloat * 1000);
                return [
                    'type' => 'currency',
                    'currency' => [
                        'fallback_value' => $value,
                        'code' => $code,
                        'amount_1000' => $amount1000
                    ]
                ];
            case 'date_time':
                return [
                    'type' => 'date_time',
                    'date_time' => ['fallback_value' => $value]
                ];
            case 'image':
            case 'video':
            case 'document':
                return [
                    'type' => $paramType,
                    $paramType => ['link' => $value]
                ];
            default:
                return ['type' => 'text', 'text' => $value];
        }
    }
    
    /**
     * Get template by name and language.
     *
     * @return array|null Template structure or null if not found
     */
    public function getTemplateByName(string $name, string $languageCode = 'en_US'): ?array
    {
        $templates = $this->getTemplates();
        $name = trim($name);
        $lang = trim($languageCode);
        foreach ($templates as $t) {
            $tName = $t['name'] ?? '';
            $tLang = $t['language'] ?? 'en_US';
            $langMatch = $tLang === $lang || $tLang === substr($lang, 0, 2) || $lang === substr($tLang, 0, 2);
            if ($tName === $name && $langMatch) {
                return $t;
            }
        }
        return null;
    }
    
    /**
     * Check if the 24-hour messaging window is open for a contact (last inbound WhatsApp message within 24 hours).
     */
    public function isWithin24HourWindow(int $contactId): bool
    {
        $workspaceId = $this->resolveWorkspaceId($contactId);
        $row = Database::queryOne(
            "SELECT 1 AS within_window
             FROM communications
             WHERE workspace_id = ?
               AND contact_id = ?
               AND channel = 'whatsapp'
               AND direction = 'inbound'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             LIMIT 1",
            [$workspaceId, $contactId]
        );

        return !empty($row['within_window']);
    }

    /**
     * Normalize a phone number for WhatsApp Cloud API.
     * Keeps digits only, removes leading plus and local leading zeros.
     */
    public function normalizePhoneNumber(string $phoneNumber): string
    {
        $normalized = preg_replace('/[^\d+]/', '', trim($phoneNumber));
        $normalized = ltrim($normalized, '+');
        $normalized = ltrim($normalized, '0');
        return (string) $normalized;
    }

    /**
     * Send text message
     */
    public function sendTextMessage(string $to, string $text): array
    {
        $to = $this->normalizePhoneNumber($to);
        if ($to === '') {
            throw new \RuntimeException('Invalid contact phone number for WhatsApp.');
        }
        $this->guardManagedBillingBeforeSend(null, 'service');

        $data = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $text]
        ];
        
        return $this->makeRequest('POST', 'messages', $data);
    }

    /**
     * Upload media file to WhatsApp and return media_id.
     * POST /{phone_number_id}/media with multipart/form-data.
     *
     * @param string $filePath Absolute path to file
     * @param string $mimeType MIME type (e.g. image/jpeg, video/mp4)
     * @return string|null Media ID or null on failure
     */
    public function uploadMedia(string $filePath, string $mimeType): ?string
    {
        if (!is_readable($filePath)) {
            return null;
        }
        $url = rtrim($this->baseUrl, '/') . '/' . $this->phoneNumberId . '/media';
        $filename = basename($filePath);
        $cfile = new \CURLFile($filePath, $mimeType, $filename);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'messaging_product' => 'whatsapp',
                'file' => $cfile
            ],
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            return null;
        }
        $result = json_decode($response, true);
        return isset($result['id']) ? (string) $result['id'] : null;
    }

    /**
     * Build media payload for image/video/document (id or link).
     */
    private function buildMediaPayload(string $mediaIdOrUrl, string $type, ?string $caption, ?string $filename = null): array
    {
        $isUrl = preg_match('#^https?://#i', $mediaIdOrUrl);
        $payload = [];
        if ($isUrl) {
            $payload['link'] = $mediaIdOrUrl;
        } else {
            $payload['id'] = $mediaIdOrUrl;
        }
        if ($caption !== null && $caption !== '') {
            $payload['caption'] = mb_substr($caption, 0, 1024);
        }
        if ($filename !== null && $filename !== '' && $type === 'document') {
            $payload['filename'] = $filename;
        }
        return [$type => $payload];
    }

    /**
     * Send image message (session message, within 24h window).
     */
    public function sendImageMessage(string $to, string $mediaIdOrUrl, ?string $caption = null): array
    {
        $to = $this->normalizePhoneNumber($to);
        if ($to === '') {
            throw new \RuntimeException('Invalid contact phone number for WhatsApp.');
        }

        $data = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'image',
            ...$this->buildMediaPayload($mediaIdOrUrl, 'image', $caption),
        ];
        return $this->makeRequest('POST', 'messages', $data);
    }

    /**
     * Send video message (session message, within 24h window).
     */
    public function sendVideoMessage(string $to, string $mediaIdOrUrl, ?string $caption = null): array
    {
        $to = $this->normalizePhoneNumber($to);
        if ($to === '') {
            throw new \RuntimeException('Invalid contact phone number for WhatsApp.');
        }

        $data = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'video',
            ...$this->buildMediaPayload($mediaIdOrUrl, 'video', $caption),
        ];
        return $this->makeRequest('POST', 'messages', $data);
    }

    /**
     * Send document message (session message, within 24h window).
     */
    public function sendDocumentMessage(string $to, string $mediaIdOrUrl, ?string $filename = null, ?string $caption = null): array
    {
        $data = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'document',
            ...$this->buildMediaPayload($mediaIdOrUrl, 'document', $caption, $filename),
        ];
        return $this->makeRequest('POST', 'messages', $data);
    }

    /**
     * Send sticker message (session message, within 24h window).
     * Stickers require media_id from uploaded WebP image.
     */
    public function sendStickerMessage(string $to, string $mediaId): array
    {
        $data = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'sticker',
            'sticker' => ['id' => $mediaId],
        ];
        return $this->makeRequest('POST', 'messages', $data);
    }
    
    /**
     * Store message in database
     */
    public function storeMessage(int $contactId, string $to, string $messageType, string $body, array $options = []): string
    {
        $workspaceId = $this->resolveWorkspaceId($contactId, isset($options['workspace_id']) ? (int) $options['workspace_id'] : null);
        $templateCategory = (string) ($options['template_category'] ?? $options['category'] ?? ($messageType === 'template' ? 'utility' : 'service'));
        if (Database::columnExists('contacts', 'whatsapp_opt_in_status')) {
            (new WhatsAppComplianceService())->assertCanSend($workspaceId, $contactId, $templateCategory);
        }

        $uuid = $this->generateUuid();
        $userId = $options['user_id'] ?? ($_SESSION['user_id'] ?? null);
        $whatsappMessageId = $options['whatsapp_message_id'] ?? null;
        $status = $whatsappMessageId ? 'sent' : 'pending';
        
        Database::execute(
            "INSERT INTO whatsapp_messages (workspace_id, uuid, contact_id, user_id, to_number, message_type, message_body, template_name, template_params, whatsapp_message_id, status, direction) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'outbound')",
            [
                $workspaceId,
                $uuid,
                $contactId,
                $userId,
                $to,
                $messageType,
                $body,
                $options['template_name'] ?? null,
                isset($options['template_params']) ? json_encode($options['template_params']) : null,
                $whatsappMessageId,
                $status
            ]
        );
        
        $messageId = (int) Database::lastInsertId();
        try {
            (new WhatsAppConnectionResolver())->annotateMessage($workspaceId, $messageId, array_merge($options, [
                'template_category' => $templateCategory,
            ]));
        } catch (\Throwable $e) {
            error_log('WhatsAppService: message billing annotation failed: ' . $e->getMessage());
        }
        
        // Also add to unified inbox (communications table) for outbound messages
        $commUuid = $this->generateUuid();
        try {
            $subject = $messageType === 'template' 
                ? 'WhatsApp Template: ' . ($options['template_name'] ?? 'Unknown')
                : 'WhatsApp Message';
            
            $commMetadata = [
                'whatsapp_uuid' => $uuid,
                'whatsapp_message_id' => $whatsappMessageId,
                'message_type' => $messageType,
                'template_name' => $options['template_name'] ?? null,
                'to_number' => $to
            ];
            if (!empty($options['media_id'])) {
                $commMetadata['media_id'] = $options['media_id'];
            }
            if (!empty($options['mime_type'])) {
                $commMetadata['mime_type'] = $options['mime_type'];
            }
            if (!empty($options['media_filename'])) {
                $commMetadata['media_filename'] = $options['media_filename'];
            }
            Database::execute(
                "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, subject, body, status, metadata, created_at) 
                 VALUES (?, ?, ?, 'whatsapp', 'outbound', ?, ?, ?, ?, NOW())",
                [
                    $workspaceId,
                    $commUuid,
                    $contactId,
                    $subject,
                    $body,
                    $status,
                    json_encode($commMetadata)
                ]
            );
            $communicationId = (int) Database::lastInsertId();
            try {
                (new ConversationIntelligenceService())->syncForCommunication($communicationId);
            } catch (\Throwable $e) {
                error_log("WhatsAppService: Conversation intelligence sync failed: " . $e->getMessage());
            }
            try {
                (new ContactIntelligenceService())->computeAndPersist((int) $contactId);
            } catch (\Throwable $e) {
                error_log("WhatsAppService: Contact intelligence refresh failed: " . $e->getMessage());
            }
            try {
                (new TargetIntelligenceService())->refreshAfterEntityChange('communications', $communicationId, [
                    'contact_id' => (int) $contactId,
                    'channel' => 'whatsapp',
                ]);
            } catch (\Throwable $e) {
                error_log("WhatsAppService: Target intelligence refresh failed: " . $e->getMessage());
            }
        } catch (\Exception $e) {
            // Log error but don't fail message sending
            error_log("Error adding WhatsApp message to communications: " . $e->getMessage());
            // Also log the full trace for debugging
            error_log("Stack trace: " . $e->getTraceAsString());
        }
        
        // Add to queue only when message is not yet sent (e.g. from bulk messaging)
        if (!$whatsappMessageId) {
            $queue = new WhatsAppQueue();
            $queue->push($messageId, $options['priority'] ?? 0, $options['scheduled_at'] ?? null);
        }

        return $uuid;
    }

    private function guardManagedBillingBeforeSend(?string $templateName = null, string $fallbackCategory = 'utility'): void
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0 || !Database::tableExists('workspace_whatsapp_integrations')) {
            return;
        }

        $connection = (new WhatsAppConnectionResolver())->resolve($workspaceId);
        if (empty($connection['is_platform_managed'])) {
            return;
        }
        (new WhatsAppFeatureGate())->assertManagedBillingEnabled($workspaceId);

        $category = $fallbackCategory;
        if ($templateName !== null && trim($templateName) !== '' && Database::tableExists('workspace_whatsapp_templates')) {
            $template = Database::queryOne(
                "SELECT category
                 FROM workspace_whatsapp_templates
                 WHERE workspace_id = ?
                   AND template_name = ?
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 1",
                [$workspaceId, trim($templateName)]
            );
            $category = (string) ($template['category'] ?? $category);
        }

        $credits = new WorkspaceWhatsAppCreditService();
        $estimate = $credits->estimateCost($workspaceId, $category, '');
        if ($estimate <= 0) {
            return;
        }

        $summary = $credits->summary($workspaceId);
        if ((float) ($summary['available_credits'] ?? 0) < $estimate) {
            throw new \RuntimeException('Managed WhatsApp credits are too low for this send.');
        }
    }

    private function resolveWorkspaceId(int $contactId, ?int $workspaceId = null): int
    {
        if ($workspaceId !== null && $workspaceId > 0) {
            return $workspaceId;
        }

        $contextWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($contextWorkspaceId > 0) {
            return $contextWorkspaceId;
        }

        $contact = Database::queryOne(
            "SELECT workspace_id
             FROM contacts
             WHERE id = ?
             LIMIT 1",
            [$contactId]
        );

        $resolvedWorkspaceId = (int) ($contact['workspace_id'] ?? 0);
        if ($resolvedWorkspaceId <= 0) {
            throw new \RuntimeException('Unable to resolve workspace for WhatsApp message.');
        }

        return $resolvedWorkspaceId;
    }
    
    /**
     * Mark an inbound message as read so the customer sees "read" in WhatsApp.
     * POST /{phone_number_id}/messages with status: read, message_id.
     */
    public function markMessageAsRead(string $whatsappMessageId): array
    {
        $data = [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $whatsappMessageId
        ];
        return $this->makeRequest('POST', 'messages', $data);
    }

    /**
     * Get temporary media URL from Meta for a given media ID (image/video/audio/document/sticker).
     * GET https://graph.facebook.com/v24.0/{media-id} returns { url: "..." }. URLs are temporary (e.g. 30 days).
     */
    public function getMediaUrl(string $mediaId): ?string
    {
        if (empty($this->accessToken)) {
            return null;
        }
        $url = rtrim($this->baseUrl, '/') . '/' . $mediaId . '?access_token=' . urlencode($this->accessToken);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $httpCode >= 400) {
            return null;
        }
        $result = json_decode($response, true);
        return isset($result['url']) ? (string) $result['url'] : null;
    }

    /**
     * Make API request
     */
    private function makeRequest(string $method, string $endpoint, array $data = []): array
    {
        $normalizedEndpoint = trim(ltrim($endpoint, '/'));
        $demoSimulation = false;
        try {
            $demoMode = new DemoModeManager();
            $demoSimulation = (new DemoWorkspaceService())->isDemoWorkspace((int) (WorkspaceContext::currentWorkspaceId() ?? 0))
                || ($demoMode->isEnabled() && $demoMode->isSimulationOnly());
        } catch (\Throwable $demoError) {
            $demoSimulation = (new DemoWorkspaceService())->isDemoWorkspace((int) (WorkspaceContext::currentWorkspaceId() ?? 0));
        }

        if (
            $demoSimulation &&
            strtoupper($method) === 'POST' &&
            str_starts_with($normalizedEndpoint, 'messages')
        ) {
            return [
                'messages' => [
                    ['id' => 'wamid.demo.' . substr(hash('sha256', json_encode($data) . microtime(true)), 0, 20)]
                ],
                'simulated' => true,
                'demo_mode' => true,
            ];
        }

        // Validate configuration
        if (empty($this->phoneNumberId)) {
            $envValue = $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? '(not set)';
            throw new \RuntimeException("WhatsApp Phone Number ID is not configured. Please set WHATSAPP_PHONE_NUMBER_ID in your environment variables. Current value in \$_ENV: '{$envValue}'");
        }
        
        // Validate Phone Number ID format (should be numeric only)
        if (!preg_match('/^\d+$/', $this->phoneNumberId)) {
            $displayValue = strlen($this->phoneNumberId) > 0 ? substr($this->phoneNumberId, 0, 50) : '(empty)';
            throw new \RuntimeException("Invalid WhatsApp Phone Number ID format. It should contain only digits. Current value: '{$displayValue}'");
        }
        
        if (empty($this->accessToken)) {
            $envValue = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? '(not set)';
            $envPreview = strlen($envValue) > 20 ? substr($envValue, 0, 20) . '...' : $envValue;
            throw new \RuntimeException("WhatsApp Access Token is not configured. Please set WHATSAPP_ACCESS_TOKEN in your environment variables. Current value in \$_ENV: '{$envPreview}'");
        }
        
        // Validate access token format (should start with EAA for permanent tokens)
        if (!preg_match('/^EAA/', $this->accessToken) && $this->isDebugEnabled()) {
            error_log("WhatsAppService: Access token format is non-standard.");
        }
        
        // Construct URL - build it properly with phone number ID
        // The endpoint should be just '/messages', we'll add the phone number ID here
        $endpoint = ltrim($endpoint, '/'); // Remove leading slash if present
        $url = rtrim($this->baseUrl, '/') . '/' . $this->phoneNumberId . '/' . $endpoint;
        
        // Validate URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException("Invalid WhatsApp API URL format: {$url}");
        }
        
        // Prepare Authorization header with access token
        $authHeader = 'Authorization: Bearer ' . $this->accessToken;
        
        // Debug: Log request details (only in debug mode)
        if ($this->isVerboseDebugEnabled()) {
            error_log("WhatsApp API Request: {$method} {$url}");
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                $authHeader,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        
        if (!empty($data)) {
            $jsonData = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            
            // Debug: Log request payload
            if ($this->isVerboseDebugEnabled()) {
                error_log("WhatsApp API Payload bytes: " . strlen($jsonData));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // Debug: Log response
        if ($this->isVerboseDebugEnabled()) {
            error_log("WhatsApp API Response HTTP {$httpCode}, bytes=" . strlen((string) $response));
        }
        
        // Handle cURL errors
        if ($response === false) {
            throw new \RuntimeException("WhatsApp API request failed: " . ($curlError ?: 'Unknown cURL error'));
        }
        
        // Handle empty response
        if (empty($response)) {
            throw new \RuntimeException("WhatsApp API returned empty response. HTTP Code: {$httpCode}");
        }
        
        $result = json_decode($response, true);
        
        // Handle JSON decode errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("WhatsApp API returned invalid JSON. Response: " . substr($response, 0, 200));
        }
        
        // Handle HTTP errors
        if ($httpCode >= 400) {
            $errorMessage = $result['error']['message'] ?? ($result['error']['error_user_msg'] ?? 'Unknown error');
            $errorCode = $result['error']['code'] ?? $httpCode;
            $errorType = $result['error']['type'] ?? 'API_ERROR';
            $errorSubcode = $result['error']['error_subcode'] ?? null;
            
            // Check for specific error codes related to 24-hour window
            if ($errorCode == 131047 || $errorSubcode == 131047 || 
                strpos(strtolower($errorMessage), '24') !== false ||
                strpos(strtolower($errorMessage), 'session') !== false ||
                strpos(strtolower($errorMessage), 'window') !== false) {
                throw new \RuntimeException("Text messages can only be sent within 24 hours of customer's last message. Use template messages for business-initiated communication. Error: {$errorMessage}");
            }
            
            // 132012: Parameter format mismatch - log full request for debugging
            if ($errorCode == 132012 && ($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                error_log("WhatsApp 132012: Parameter format mismatch. Full request was: " . json_encode($data, JSON_PRETTY_PRINT));
            }
            
            $hint = '';
            if ($errorCode == 132012) {
                $hint = ' Check that each parameter matches the template type (text, currency, date_time, image). Use the format: currency=amount|CODE, date_time=readable date.';
            }
            
            throw new \RuntimeException("WhatsApp API error ({$errorCode}): {$errorMessage} [Type: {$errorType}].{$hint}");
        }
        
        // Ensure we return an array
        return is_array($result) ? $result : [];
    }
    
    /**
     * Get approved message templates
     * 
     * @return array Array of approved templates with name, status, language, category, and components
     */
    public function getTemplates(): array
    {
        return $this->fetchMessageTemplates(true);
    }

    /**
     * Get message templates across provider lifecycle states for Template Center sync.
     *
     * @return array Array of templates with provider status and raw metadata
     */
    public function getTemplateCatalog(): array
    {
        return $this->fetchMessageTemplates(false);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchMessageTemplates(bool $approvedOnly): array
    {
        // WABA ID is REQUIRED for fetching templates - Phone Number ID doesn't work
        $wabaId = $this->businessAccountId;
        
        // If WABA ID is not set, try to get it from phone number details
        if (empty($wabaId) && !empty($this->phoneNumberId)) {
            try {
                $wabaId = $this->getWabaIdFromPhoneNumber();
            } catch (\Exception $e) {
                // Log but continue - we'll throw a better error below
                error_log("Could not get WABA ID from phone number: " . $e->getMessage());
            }
        }
        
        // WABA ID is required - templates cannot be fetched via Phone Number ID
        if (empty($wabaId)) {
            throw new \RuntimeException(
                'WhatsApp Business Account ID (WABA ID) is required to fetch templates. ' .
                'Phone Number ID cannot be used for this endpoint. ' .
                'Please set WHATSAPP_BUSINESS_ACCOUNT_ID in your .env file. ' .
                'You can find your WABA ID in Meta Business Manager → Business Settings → WhatsApp Accounts.'
            );
        }
        
        // Build URL for message templates endpoint - MUST use WABA ID
        $url = rtrim($this->baseUrl, '/') . '/' . $wabaId . '/message_templates';
        
        // Add query parameters to filter for approved templates only
        $url .= '?limit=100'; // Get up to 100 templates per page
        
        $allTemplates = [];
        $nextPageUrl = $url;
        
        // Handle pagination
        while ($nextPageUrl) {
            $ch = curl_init($nextPageUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->accessToken,
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($response === false) {
                throw new \RuntimeException("Failed to fetch templates: " . ($curlError ?: 'Unknown cURL error'));
            }
            
            $result = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Invalid JSON response when fetching templates: " . substr($response, 0, 200));
            }
            
            if ($httpCode >= 400) {
                $errorMessage = $result['error']['message'] ?? ($result['error']['error_user_msg'] ?? 'Unknown error');
                $errorCode = $result['error']['code'] ?? $httpCode;
                $errorType = $result['error']['type'] ?? '';
                
                // Provide helpful error message for common issues
                if ($errorCode == 100 || strpos(strtolower($errorMessage), 'invalid') !== false) {
                    throw new \RuntimeException("Failed to fetch templates. The endpoint may require a WhatsApp Business Account ID (WABA ID) instead of Phone Number ID. Please set WHATSAPP_BUSINESS_ACCOUNT_ID in your .env file. Error: {$errorMessage}");
                }
                
                throw new \RuntimeException("Failed to fetch templates ({$errorCode}): {$errorMessage}");
            }
            
            // Extract templates from response
            $templates = $result['data'] ?? [];
            
            // Debug: Log raw API response for first template when APP_DEBUG is true
            if (($_ENV['APP_DEBUG'] ?? 'false') === 'true' && !empty($templates)) {
                $raw = json_encode($templates[0], JSON_PRETTY_PRINT);
                error_log('WhatsApp template raw: ' . substr($raw, 0, 2000) . (strlen($raw) > 2000 ? '...' : ''));
            }
            
            // Process each template
            foreach ($templates as $template) {
                // Compose/send surfaces stay approved-only; Template Center sync can request all statuses.
                if (!$approvedOnly || strtoupper((string) ($template['status'] ?? '')) === 'APPROVED') {
                    // Extract component information
                    $components = $template['components'] ?? [];
                    $headerParams = 0;
                    $bodyParams = 0;
                    $footerParams = 0;
                    $buttonCount = 0;
                    
                    $isCarousel = false;
                    $carouselSchema = ['cards' => []];
                    foreach ($components as $component) {
                        $type = $component['type'] ?? '';
                        if ($type === 'CAROUSEL') {
                            $isCarousel = true;
                            $carouselCards = $component['cards'] ?? [];
                            foreach ($carouselCards as $cardIdx => $card) {
                                $cardSchema = ['header' => [], 'body' => [], 'buttons' => []];
                                $cardComps = $card['components'] ?? [];
                                foreach ($cardComps as $cc) {
                                    $ct = strtolower($cc['type'] ?? '');
                                    $cParams = $cc['parameters'] ?? [];
                                    if ($ct === 'header') {
                                        $fmt = strtoupper($cc['format'] ?? 'TEXT');
                                        if (!empty($cParams)) {
                                            foreach ($cParams as $p) {
                                                $cardSchema['header'][] = ['type' => is_array($p) ? $this->inferParamType($p, $fmt) : 'text'];
                                            }
                                        } elseif (in_array($fmt, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
                                            $cardSchema['header'][] = ['type' => strtolower($fmt)];
                                        }
                                    } elseif ($ct === 'body') {
                                        foreach ($cParams as $p) {
                                            $cardSchema['body'][] = ['type' => is_array($p) ? $this->inferParamType($p, 'TEXT') : 'text'];
                                        }
                                        if (empty($cParams)) {
                                            $bodyText = $cc['text'] ?? '';
                                            $cardSchema['body'] = $this->inferBodySchemaFromText($bodyText);
                                        }
                                    } elseif ($ct === 'buttons') {
                                        foreach ($cc['buttons'] ?? [] as $btn) {
                                            $cardSchema['buttons'][] = ['type' => ($btn['type'] ?? '') === 'URL' ? 'url' : 'payload'];
                                        }
                                    }
                                }
                                $carouselSchema['cards'][] = $cardSchema;
                                $headerParams += count($cardSchema['header']);
                                $bodyParams += count($cardSchema['body']);
                                $buttonCount += count($cardSchema['buttons']);
                            }
                        } elseif ($type === 'HEADER') {
                            $headerParams = count($component['parameters'] ?? []);
                        } elseif ($type === 'BODY') {
                            $bodyParams = count($component['parameters'] ?? []);
                        } elseif ($type === 'FOOTER') {
                            $footerParams = 1;
                        } elseif ($type === 'BUTTONS') {
                            $buttonCount = count($component['sub_type'] === 'QUICK_REPLY' ? ($component['buttons'] ?? []) : []);
                        }
                    }
                    
                    // Build parameter_schema with types for each component
                    // Meta API may use param['type'] or infer from param['currency'], param['date_time'], etc.
                    $parameterSchema = ['header' => [], 'body' => [], 'buttons' => [], 'carousel' => null];
                    if ($isCarousel) {
                        $parameterSchema['carousel'] = $carouselSchema;
                    }
                    foreach ($components as $component) {
                        $type = strtolower($component['type'] ?? '');
                        if ($type === 'carousel') {
                            continue; // Already handled above in carouselSchema
                        }
                        $params = $component['parameters'] ?? [];
                        if ($type === 'header') {
                            $format = strtoupper($component['format'] ?? 'TEXT');
                            if (!empty($params)) {
                                foreach ($params as $param) {
                                    if (!is_array($param)) {
                                        $parameterSchema['header'][] = ['type' => 'text'];
                                        continue;
                                    }
                                    $paramType = $this->inferParamType($param, $format);
                                    $parameterSchema['header'][] = ['type' => $paramType];
                                }
                            } else {
                                $example = $component['example'] ?? [];
                                $headerExamples = $example['header'] ?? [];
                                if (!empty($headerExamples) && is_array($headerExamples[0] ?? null)) {
                                    foreach ($headerExamples[0] as $ex) {
                                        $parameterSchema['header'][] = ['type' => strtolower($format)];
                                    }
                                } elseif (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
                                    $parameterSchema['header'][] = ['type' => strtolower($format)];
                                }
                            }
                        } elseif ($type === 'body') {
                            if (!empty($params)) {
                                foreach ($params as $param) {
                                    if (!is_array($param)) {
                                        $parameterSchema['body'][] = ['type' => 'text'];
                                        continue;
                                    }
                                    $paramType = $this->inferParamType($param, 'TEXT');
                                    $parameterSchema['body'][] = ['type' => $paramType];
                                }
                            } else {
                                // Fallback: try example field, then body text when API returns empty parameters
                                $example = $component['example'] ?? [];
                                $bodyExamples = $example['body_text'] ?? [];
                                if (!empty($bodyExamples) && is_array($bodyExamples[0] ?? null)) {
                                    $firstRow = $bodyExamples[0];
                                    foreach ($firstRow as $ex) {
                                        $parameterSchema['body'][] = ['type' => $this->inferTypeFromExampleValue($ex)];
                                    }
                                } else {
                                    $bodyText = $component['text'] ?? '';
                                    $parameterSchema['body'] = array_merge(
                                        $parameterSchema['body'],
                                        $this->inferBodySchemaFromText($bodyText)
                                    );
                                }
                            }
                        } elseif ($type === 'buttons') {
                            $buttons = $component['buttons'] ?? [];
                            foreach ($buttons as $btn) {
                                $btnType = strtoupper($btn['type'] ?? 'QUICK_REPLY');
                                $parameterSchema['buttons'][] = ['type' => $btnType === 'URL' ? 'payload' : 'payload'];
                            }
                        }
                    }
                    
                    // Get body text preview (first component of type BODY)
                    $bodyText = '';
                    foreach ($components as $component) {
                        if (($component['type'] ?? '') === 'BODY') {
                            $bodyText = $component['text'] ?? '';
                            break;
                        }
                    }
                    
                    // Use schema counts when parameters was empty (fallback from body text)
                    $headerParams = max($headerParams, count($parameterSchema['header']));
                    $bodyParams = max($bodyParams, count($parameterSchema['body']));
                    
                    // Normalize language (API may return string or {code: "en_US"})
                    $lang = $template['language'] ?? 'en_US';
                    $lang = is_array($lang) ? ($lang['code'] ?? 'en_US') : $lang;
                    // Build template data structure
                    $allTemplates[] = [
                        'id' => (string) ($template['id'] ?? $template['message_template_id'] ?? ''),
                        'name' => $template['name'] ?? '',
                        'status' => $template['status'] ?? 'UNKNOWN',
                        'language' => $lang,
                        'category' => $template['category'] ?? 'UTILITY',
                        'rejection_reason' => (string) ($template['rejected_reason'] ?? $template['reason'] ?? $template['status_reason'] ?? ''),
                        'components' => $components,
                        'body_text' => $bodyText,
                        'is_carousel' => $isCarousel,
                        'parameter_count' => [
                            'header' => $headerParams,
                            'body' => $bodyParams,
                            'footer' => $footerParams,
                            'buttons' => $buttonCount
                        ],
                        'parameter_schema' => $parameterSchema,
                        'provider_payload' => $template
                    ];
                }
            }
            
            // Check for pagination
            $paging = $result['paging'] ?? [];
            $nextPageUrl = $paging['next'] ?? null;
        }
        
        // Sort templates by name
        usort($allTemplates, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        return $allTemplates;
    }
    
    /**
     * Get WhatsApp Business Account ID from phone number details
     * 
     * @return string|null WABA ID if found, null otherwise
     */
    public function getWabaIdFromPhoneNumber(): ?string
    {
        if (empty($this->phoneNumberId)) {
            return null;
        }
        
        // Get phone number details - the WABA ID might be in the response
        // According to WhatsApp API, we can request the whatsapp_business_account relationship
        $url = rtrim($this->baseUrl, '/') . '/' . $this->phoneNumberId . '?fields=whatsapp_business_account{id}';
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            throw new \RuntimeException("Failed to fetch phone number details: " . ($curlError ?: 'Unknown cURL error'));
        }
        
        if ($httpCode !== 200) {
            $result = json_decode($response, true);
            $errorMsg = $result['error']['message'] ?? 'Unknown error';
            throw new \RuntimeException("Failed to fetch phone number details (HTTP {$httpCode}): {$errorMsg}");
        }
        
        $result = json_decode($response, true);
        if (is_array($result)) {
            // Check for WABA ID in various possible locations
            if (isset($result['whatsapp_business_account']['id'])) {
                return $result['whatsapp_business_account']['id'];
            }
            if (isset($result['whatsapp_business_account_id'])) {
                return $result['whatsapp_business_account_id'];
            }
            // Sometimes it's directly in the response
            if (isset($result['account_id'])) {
                return $result['account_id'];
            }
        }
        
        return null;
    }
    
    /**
     * Generate UUID
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function isDebugEnabled(): bool
    {
        return strtolower((string) ($_ENV['APP_DEBUG'] ?? 'false')) === 'true';
    }

    private function isVerboseDebugEnabled(): bool
    {
        return $this->isDebugEnabled() && strtolower((string) ($_ENV['WHATSAPP_VERBOSE_DEBUG'] ?? 'false')) === 'true';
    }
}
