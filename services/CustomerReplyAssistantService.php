<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\AIAutoResponderConfig;
use CRM\Modules\CompanyProfile;
use CRM\Modules\EmailSignatures;
use CRM\Modules\UserStrategyProfile;

class CustomerReplyAssistantService
{
    private const DEFAULT_READING_LEVEL = WorkspaceLanguageLevelService::DEFAULT_LEVEL;

    private EmailAssistantApplicationService $applicationService;
    private EmailAssistantThreadContextService $threadContextService;
    private AIService $aiService;
    private EmailDraftOutputNormalizer $draftOutputNormalizer;
    private CompanyProfile $companyProfile;
    private EmailSignatures $emailSignatures;
    private UserStrategyProfile $userStrategyProfile;
    private AIAutoResponderConfig $autoResponderConfig;
    private WorkspaceLanguageLevelService $languageLevels;

    public function __construct()
    {
        $this->applicationService = new EmailAssistantApplicationService();
        $this->threadContextService = new EmailAssistantThreadContextService();
        $this->aiService = new AIService();
        $this->draftOutputNormalizer = new EmailDraftOutputNormalizer();
        $this->companyProfile = new CompanyProfile();
        $this->emailSignatures = new EmailSignatures();
        $this->userStrategyProfile = new UserStrategyProfile();
        $this->autoResponderConfig = new AIAutoResponderConfig();
        $this->languageLevels = new WorkspaceLanguageLevelService();
    }

    public function resolveDraftStyleContext(int $userId, string $channel, array $options = [], array $additionalContext = []): array
    {
        $channel = strtolower(trim($channel)) !== '' ? strtolower(trim($channel)) : 'email';
        $strategy = $userId > 0 ? ($this->userStrategyProfile->get($userId) ?: []) : [];
        $autoConfig = $this->autoResponderConfig->get();
        $channelDefaults = (array) ($autoConfig['channels'][$channel] ?? []);
        $continuity = $this->resolveConversationContinuity($channel, $additionalContext);

        $tone = trim((string) ($options['tone'] ?? ''));
        if ($tone === '') {
            $tone = trim((string) ($strategy['draft_tone_preset'] ?? ''));
        }
        if ($tone === '') {
            $tone = $this->mapOutreachPostureToTone((string) ($strategy['outreach_posture'] ?? ''));
        }
        if ($tone === '') {
            $tone = $channel === 'whatsapp' ? 'casual' : 'professional';
        }

        $voiceNotesParts = array_values(array_filter([
            trim((string) ($strategy['draft_voice_notes'] ?? '')),
            trim((string) ($strategy['offer_angle'] ?? '')) !== '' ? 'Offer angle: ' . trim((string) $strategy['offer_angle']) : '',
            trim((string) ($strategy['positioning_notes'] ?? '')) !== '' ? 'Positioning notes: ' . trim((string) $strategy['positioning_notes']) : '',
            trim((string) ($strategy['sales_motion'] ?? '')) !== '' ? 'Sales motion: ' . trim((string) $strategy['sales_motion']) : '',
            trim((string) ($additionalContext['voice_notes'] ?? '')),
        ]));
        $includeClearCta = array_key_exists('draft_include_clear_cta', $channelDefaults)
            ? !empty($channelDefaults['draft_include_clear_cta'])
            : true;
        $ctaStyle = trim((string) ($strategy['draft_cta_style'] ?? '')) ?: ($includeClearCta ? 'clear' : 'soft');
        $formalityLevel = trim((string) ($strategy['draft_formality_level'] ?? '')) ?: $this->defaultFormalityForChannel($channel);
        $readingLevel = $this->languageLevels->normalize(trim((string) ($strategy['draft_reading_level'] ?? '')) ?: self::DEFAULT_READING_LEVEL);
        $lengthBand = (string) ($channelDefaults['draft_length_band'] ?? ($channel === 'email' ? 'balanced' : 'short'));
        $fullness = (string) ($channelDefaults['draft_fullness'] ?? ($channel === 'email' ? 'balanced' : 'concise'));
        $voiceNotes = implode("\n", $voiceNotesParts);
        $signoff = $channel === 'email' ? 'Best regards,' : '';
        $defaultSignature = $channel === 'email' ? $this->resolveDefaultEmailSignature($userId) : [];
        $signatureName = $this->resolveDefaultSignatureName($userId);

        return [
            'subject_user_id' => $userId,
            'channel' => $channel,
            'tone' => $tone,
            'tone_preset' => trim((string) ($strategy['draft_tone_preset'] ?? '')),
            'voice_notes' => $voiceNotes,
            'cta_style' => $ctaStyle,
            'formality_level' => $formalityLevel,
            'reading_level' => $readingLevel,
            'length_band' => $lengthBand,
            'fullness' => $fullness,
            'include_clear_cta' => $includeClearCta,
            'max_chars' => (int) ($channelDefaults['max_chars'] ?? ($channel === 'sms' ? 320 : ($channel === 'whatsapp' ? 700 : 4000))),
            'continuity' => $continuity,
            'default_signoff' => $signoff,
            'default_signature_name' => $signatureName,
            'default_signature_id' => !empty($defaultSignature['id']) ? (int) $defaultSignature['id'] : null,
            'default_signature' => $defaultSignature,
            'strategy_context' => [
                'outreach_posture' => (string) ($strategy['outreach_posture'] ?? ''),
                'offer_angle' => (string) ($strategy['offer_angle'] ?? ''),
                'sales_motion' => (string) ($strategy['sales_motion'] ?? ''),
                'segment_focus' => (string) ($strategy['segment_focus'] ?? ''),
                'deal_movement_strategy' => (string) ($strategy['deal_movement_strategy'] ?? ''),
            ],
            'channel_defaults' => [
                'draft_length_band' => (string) ($channelDefaults['draft_length_band'] ?? ''),
                'draft_fullness' => (string) ($channelDefaults['draft_fullness'] ?? ''),
                'draft_include_clear_cta' => $includeClearCta,
            ],
            'instruction_text' => $this->buildDraftStyleInstructionText([
                'channel' => $channel,
                'tone' => $tone,
                'voice_notes' => $voiceNotes,
                'cta_style' => $ctaStyle,
                'formality_level' => $formalityLevel,
                'reading_level' => $readingLevel,
                'length_band' => $lengthBand,
                'fullness' => $fullness,
                'include_clear_cta' => $includeClearCta,
                'continuity' => $continuity,
            ]),
        ];
    }

    public function formatDraftForChannel(array $draft, array $draftStyle, string $channel, array $context = []): array
    {
        $draft = $this->normalizeBusinessGrounding($draft, $context + ['draft_style' => $draftStyle]);
        return $this->applyDraftStyleToGeneratedDraft($draft, $draftStyle, $channel, $context);
    }

    public function generateDraftFromCommunication(int $communicationId, int $userId, array $options = []): array
    {
        $communication = Database::queryOne("SELECT * FROM communications WHERE id = ?", [$communicationId]);
        if (!$communication) {
            return $this->generateDraftFromContact(
                (int) ($options['contact_id'] ?? 0),
                (string) ($options['channel'] ?? 'email'),
                $userId,
                $options + ['communication_id' => $communicationId]
            );
        }

        $channel = strtolower((string) ($options['channel'] ?? $communication['channel'] ?? 'email'));
        $threadContext = $this->threadContextService->buildForCommunication($communicationId);
        $draftStyle = $this->resolveDraftStyleContext($userId, $channel, $options, ['thread_context' => $threadContext]);
        $context = [
            'communication_id' => $communicationId,
            'contact_id' => (int) ($communication['contact_id'] ?? 0),
            'thread_id' => (int) ($threadContext['thread_id'] ?? 0),
            'channel' => $channel,
            'surface' => (string) ($options['surface'] ?? 'conversation'),
            'mode' => $this->resolveMode((string) ($options['surface'] ?? 'conversation')),
            'goal' => (string) ($options['goal'] ?? 'draft'),
            'communication' => $communication,
            'thread_context' => $threadContext,
            'purpose' => (string) ($options['purpose'] ?? 'reply'),
            'tone' => (string) $draftStyle['tone'],
            'draft_style' => $draftStyle,
        ];

        $assistantResult = null;
        if ($channel === 'email' && $userId > 0) {
            $assistantResult = $this->applicationService->handleCustomerThreadDraft($communicationId, $userId, [
                'goal' => (string) ($options['goal'] ?? 'draft'),
            ]);
        }

        $fallback = $this->buildFallbackDraft($context);
        $aiFallback = $this->buildAIFallbackDraft($context);
        if ($this->hasUsableDraft($aiFallback)) {
            $fallback = array_merge($fallback, $aiFallback);
        }

        return $this->normalizeReplyResult($assistantResult ?? [], $fallback, $context);
    }

    public function generateDraftFromContact(int $contactId, string $channel, int $userId, array $options = []): array
    {
        $channel = strtolower(trim($channel)) !== '' ? strtolower(trim($channel)) : 'email';
        $communication = null;

        if ($contactId > 0) {
            $communication = Database::queryOne(
                "SELECT *
                 FROM communications
                 WHERE contact_id = ? AND channel = ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1",
                [$contactId, $channel]
            );
            if (!$communication && $channel !== 'email') {
                $communication = Database::queryOne(
                    "SELECT *
                     FROM communications
                     WHERE contact_id = ?
                     ORDER BY created_at DESC, id DESC
                     LIMIT 1",
                    [$contactId]
                );
            }
        }

        if ($communication) {
            return $this->generateDraftFromCommunication((int) $communication['id'], $userId, $options + [
                'contact_id' => $contactId,
                'channel' => $channel,
            ]);
        }

        $threadContext = $contactId > 0 ? $this->threadContextService->buildForContact($contactId, $channel) : [];
        return $this->generateDraftFromThreadContext($threadContext, $userId, $options + [
            'contact_id' => $contactId,
            'channel' => $channel,
        ]);
    }

    public function generateDraftFromThreadContext(array $threadContext, int $userId, array $options = []): array
    {
        $communicationId = (int) ($options['communication_id'] ?? $threadContext['communication_id'] ?? 0);
        if ($communicationId > 0) {
            return $this->generateDraftFromCommunication($communicationId, $userId, $options);
        }

        $context = [
            'communication_id' => 0,
            'contact_id' => (int) ($options['contact_id'] ?? $threadContext['contact']['id'] ?? 0),
            'thread_id' => (int) ($threadContext['thread_id'] ?? 0),
            'channel' => strtolower((string) ($options['channel'] ?? $threadContext['channel'] ?? 'email')),
            'surface' => (string) ($options['surface'] ?? 'inbox'),
            'mode' => $this->resolveMode((string) ($options['surface'] ?? 'inbox')),
            'goal' => (string) ($options['goal'] ?? 'draft'),
            'thread_context' => $threadContext,
            'communication' => [],
            'purpose' => (string) ($options['purpose'] ?? 'reply'),
        ];
        $context['draft_style'] = $this->resolveDraftStyleContext($userId, (string) $context['channel'], $options, ['thread_context' => $threadContext]);
        $context['tone'] = (string) ($context['draft_style']['tone'] ?? ($context['channel'] === 'whatsapp' ? 'casual' : 'professional'));

        return $this->normalizeReplyResult([], $this->buildFallbackDraft($context), $context);
    }

    public function normalizeReplyResult(array $assistantResult, array $fallback, array $context): array
    {
        $policy = array_merge([
            'decision' => 'suggest_only',
            'confidence_score' => 0.0,
            'context_quality_score' => 0.0,
            'goal_relevance_score' => 0.0,
            'mode' => '1',
            'threshold' => 0.0,
            'reasons' => [],
            'warnings' => [],
            'approval_required' => false,
            'can_execute' => false,
        ], (array) ($assistantResult['policy'] ?? []));

        $draft = array_merge([
            'subject' => '',
            'plain_body' => '',
            'html_body' => '',
            'explanation' => '',
        ], (array) ($assistantResult['draft'] ?? []));

        $source = 'email_assistant_v2';
        $usedFallback = false;
        $warning = null;
        if (!$this->hasUsableDraft($draft)) {
            $draft = array_merge($draft, $fallback);
            $source = 'fallback';
            $usedFallback = true;
            $warning = 'assistant_draft_unavailable';
            if (empty($policy['warnings'])) {
                $policy['warnings'] = ['assistant_draft_unavailable'];
            } elseif (!in_array('assistant_draft_unavailable', $policy['warnings'], true)) {
                $policy['warnings'][] = 'assistant_draft_unavailable';
            }
        }

        $draft = $this->draftOutputNormalizer->normalizeCanonicalDraft($draft);

        $summaryText = trim((string) ($assistantResult['summary_text'] ?? ''));
        if ($summaryText === '') {
            $summaryText = trim((string) ($draft['explanation'] ?? ''));
        }
        if ($summaryText === '') {
            $summaryText = $usedFallback
                ? 'A safe fallback draft was prepared because a structured assistant draft was not available.'
                : 'Assistant draft ready.';
        }

        $draft = $this->formatDraftForChannel($draft, (array) ($context['draft_style'] ?? []), (string) ($context['channel'] ?? 'email'), $context);

        $result = [
            'success' => true,
            'source' => $source,
            'channel' => (string) ($context['channel'] ?? 'email'),
            'communication_id' => (int) ($context['communication_id'] ?? 0),
            'contact_id' => (int) ($context['contact_id'] ?? 0),
            'thread_id' => (int) ($context['thread_id'] ?? 0),
            'mode' => (string) ($context['mode'] ?? 'customer_thread'),
            'intent' => (string) ($context['goal'] ?? 'draft_customer_reply') === 'send' ? 'send_customer_reply' : 'draft_customer_reply',
            'policy' => $policy,
            'draft' => $draft,
            'summary_text' => $summaryText,
            'fallback' => $usedFallback,
            'warning' => $warning,
            'run_id' => $assistantResult['run_id'] ?? null,
            'resolution_status' => (string) ($assistantResult['resolution_status'] ?? ($usedFallback ? 'resolved' : 'blocked')),
            'execution_status' => (string) ($assistantResult['execution_status'] ?? ($usedFallback ? 'planned' : 'rejected')),
        ];

        $result['compat'] = $this->buildCompatibilityFields($result);
        return $result;
    }

    public function buildFallbackDraft(array $context): array
    {
        $channel = strtolower((string) ($context['channel'] ?? 'email'));
        $communication = (array) ($context['communication'] ?? []);
        $threadContext = (array) ($context['thread_context'] ?? []);
        $latestInbound = $this->extractLatestInbound($threadContext, $communication);
        $companyContext = $this->getCompanyReplyContext();
        $profileIsStrong = $this->hasStrongBusinessContext($companyContext);
        $draftStyle = (array) ($context['draft_style'] ?? []);

        if ($channel === 'whatsapp') {
            $plain = $latestInbound !== ''
                ? ($profileIsStrong
                    ? 'Thanks for your message. I have reviewed your request and will follow up with details that fit your needs shortly.'
                    : 'Thanks for your message. I have noted your request and will get back to you shortly.')
                : ($profileIsStrong
                    ? 'Thanks for reaching out. We will follow up shortly with the best next step for your request.'
                    : 'Thanks for your message. I will get back to you shortly.');

            $plain = $this->applyDraftStyleToFallbackText($plain, $draftStyle, $channel);

            return [
                'subject' => '',
                'plain_body' => $plain,
                'html_body' => nl2br(htmlspecialchars($plain, ENT_QUOTES, 'UTF-8')),
                'explanation' => 'Fallback WhatsApp reply prepared.',
            ];
        }

        $baseSubject = trim((string) ($communication['subject'] ?? 'Message'));
        $subject = preg_match('/^\s*re\s*:/i', $baseSubject) ? $baseSubject : 'Re: ' . $baseSubject;
        $plain = $latestInbound !== ''
            ? ($profileIsStrong
                ? 'Thank you for your message. I have reviewed your request and will follow up shortly with the most relevant details.'
                : 'Thank you for your message. I have reviewed it and will follow up shortly.')
            : ($profileIsStrong
                ? 'Thank you for reaching out. We will review your request and share the most relevant next step shortly.'
                : 'Thank you for your message. I will follow up shortly.');
        $plain = $this->applyDraftStyleToFallbackText($plain, $draftStyle, $channel);

        return [
            'subject' => $subject,
            'plain_body' => $plain,
            'html_body' => nl2br(htmlspecialchars($plain, ENT_QUOTES, 'UTF-8')),
            'explanation' => 'Fallback email reply prepared.',
        ];
    }

    public function buildCompatibilityFields(array $reply): array
    {
        $draft = (array) ($reply['draft'] ?? []);
        $plainBody = trim((string) ($draft['plain_body'] ?? ''));
        $htmlBody = trim((string) ($draft['html_body'] ?? ''));

        if ($plainBody === '' && $htmlBody !== '') {
            $plainBody = trim(strip_tags($htmlBody));
        }
        if ($htmlBody === '' && $plainBody !== '') {
            $htmlBody = nl2br(htmlspecialchars($plainBody, ENT_QUOTES, 'UTF-8'));
        }

        return [
            'subject' => (string) ($draft['subject'] ?? ''),
            'body' => $plainBody,
            'plain_body' => $plainBody,
            'html_body' => $htmlBody,
        ];
    }

    private function hasUsableDraft(array $draft): bool
    {
        return trim((string) ($draft['plain_body'] ?? '')) !== ''
            || trim((string) ($draft['html_body'] ?? '')) !== ''
            || trim((string) ($draft['message'] ?? '')) !== '';
    }

    private function resolveMode(string $surface): string
    {
        return match ($surface) {
            'email_view' => 'email_view',
            'inbox' => 'inbox_suggest',
            default => 'customer_thread',
        };
    }

    private function buildAIFallbackDraft(array $context): array
    {
        $incomingMessage = $this->extractLatestInbound((array) ($context['thread_context'] ?? []), (array) ($context['communication'] ?? []));
        if ($incomingMessage === '') {
            return [];
        }

        $contactId = (int) ($context['contact_id'] ?? 0);
        $contact = $contactId > 0
            ? (Database::queryOne(
                "SELECT first_name, last_name, email, phone, company, job_title
                 FROM contacts
                 WHERE id = ?",
                [$contactId]
            ) ?: [])
            : [];

        $recentMessages = [];
        $messages = (array) (($context['thread_context']['messages'] ?? $context['thread_context']) ?: []);
        $companyContext = $this->getCompanyReplyContext();
        $draftStyle = (array) ($context['draft_style'] ?? []);
        foreach (array_slice($messages, -8) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $cleanBody = trim((string) ($message['clean_body'] ?? ''));
            if ($cleanBody === '') {
                $cleanBody = ConversationMessageCleaner::cleanBody((string) ($message['body'] ?? ''), (string) ($context['channel'] ?? 'email'));
            }
            $recentMessages[] = [
                'direction' => (string) ($message['direction'] ?? ''),
                'subject' => trim((string) ($message['subject'] ?? '')),
                'body' => $cleanBody,
                'created_at' => (string) ($message['created_at'] ?? ''),
            ];
        }

        try {
            $raw = $this->aiService->process(
                strtolower((string) ($context['channel'] ?? 'email')) === 'whatsapp' ? 'whatsapp_auto_reply' : 'email_auto_reply',
                [
                    'incoming_message' => $incomingMessage,
                    'context' => [
                        'contact' => [
                            'name' => trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''))),
                            'email' => (string) ($contact['email'] ?? ''),
                            'phone' => (string) ($contact['phone'] ?? ''),
                            'company' => (string) ($contact['company'] ?? ''),
                            'job_title' => (string) ($contact['job_title'] ?? ''),
                        ],
                        'recent_communications' => $recentMessages,
                        'deals' => [],
                        'notes' => [],
                        'company_profile' => $companyContext,
                        'draft_style' => $draftStyle,
                    ],
                    'options' => [
                        'forbid_hallucinations' => true,
                        'max_chars' => (int) ($draftStyle['max_chars'] ?? 700),
                        'draft_style_instructions' => (string) ($draftStyle['instruction_text'] ?? ''),
                    ],
                ]
            );
        } catch (\Throwable $e) {
            return [];
        }

        $normalized = $this->draftOutputNormalizer->normalizeEmailDraftPayload((string) $raw);
        $plain = trim((string) ($normalized['body_text'] ?? ''));
        $subject = trim((string) ($normalized['subject'] ?? ''));

        if ($plain === '') {
            return [];
        }

        return [
            'subject' => $subject,
            'plain_body' => $plain,
            'html_body' => (string) ($normalized['body_html'] ?? nl2br(htmlspecialchars($plain, ENT_QUOTES, 'UTF-8'))),
            'explanation' => 'Fallback draft prepared from recent conversation context.',
        ];
    }

    private function getCompanyReplyContext(): array
    {
        $profile = $this->companyProfile->get() ?: [];

        return [
            'company_name' => trim((string) ($profile['company_name'] ?? '')),
            'company_tagline' => trim((string) ($profile['company_tagline'] ?? '')),
            'company_description' => trim((string) ($profile['company_description'] ?? '')),
            'owner_company_context' => trim((string) ($profile['owner_company_context'] ?? '')),
            'company_industry' => trim((string) ($profile['company_industry'] ?? '')),
            'company_website' => trim((string) ($profile['company_website'] ?? '')),
            'company_timezone' => trim((string) ($profile['company_timezone'] ?? '')),
        ];
    }

    private function hasStrongBusinessContext(array $companyContext): bool
    {
        return trim((string) ($companyContext['company_description'] ?? '')) !== ''
            || trim((string) ($companyContext['owner_company_context'] ?? '')) !== ''
            || trim((string) ($companyContext['company_tagline'] ?? '')) !== '';
    }

    private function normalizeBusinessGrounding(array $draft, array $context): array
    {
        $companyContext = $this->getCompanyReplyContext();
        $companyName = trim((string) ($companyContext['company_name'] ?? ''));
        $supportsCrmLanguage = $this->companyProfileExplicitlySupportsCrm($companyContext);
        $purpose = strtolower(trim((string) ($context['purpose'] ?? '')));
        $contact = (array) ($context['contact'] ?? []);
        $recipientCompany = trim((string) ($contact['company'] ?? ''));
        $subject = trim((string) ($draft['subject'] ?? ''));

        $plainBody = trim((string) ($draft['plain_body'] ?? ''));
        $htmlBody = trim((string) ($draft['html_body'] ?? ''));
        if ($plainBody === '' && $htmlBody !== '') {
            $plainBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));
        }
        if ($plainBody === '') {
            return $draft;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $plainBody);
        if ($companyName !== '') {
            $normalized = str_replace(['[Company Name]', '{{company_name}}', '{company_name}'], $companyName, $normalized);
            $subject = str_replace(['[Company Name]', '{{company_name}}', '{company_name}'], $companyName, $subject);
        }

        if (
            $purpose === 'welcome'
            && $companyName !== ''
            && $recipientCompany !== ''
            && strcasecmp($recipientCompany, $companyName) !== 0
        ) {
            $welcomePatterns = [
                '/\bwelcome to\s+' . preg_quote($recipientCompany, '/') . '\b/i' => 'Welcome to ' . $companyName,
                '/\bon board(?:ed)?(?:\s+with|\s+to)?\s+' . preg_quote($recipientCompany, '/') . '\b/i' => 'on board with ' . $companyName,
                '/\bjourney with\s+' . preg_quote($recipientCompany, '/') . '\b/i' => 'journey with ' . $companyName,
            ];
            foreach ($welcomePatterns as $pattern => $replacement) {
                $normalized = preg_replace($pattern, $replacement, $normalized) ?? $normalized;
                $subject = preg_replace($pattern, $replacement, $subject) ?? $subject;
            }
        }

        if (!$supportsCrmLanguage) {
            $replacements = [
                '/\bour CRM\b/i' => 'our services',
                '/\bthis CRM\b/i' => 'this solution',
                '/\bthe CRM\b/i' => 'the solution',
                '/\bCRM\b/i' => 'business',
                '/\bSaaS\b/i' => 'service',
                '/\bsoftware platform\b/i' => 'service offering',
            ];
            foreach ($replacements as $pattern => $replacement) {
                $normalized = preg_replace($pattern, $replacement, $normalized) ?? $normalized;
            }
        }

        $draft['plain_body'] = trim($normalized);
        $draft['html_body'] = nl2br(htmlspecialchars($draft['plain_body'], ENT_QUOTES, 'UTF-8'));
        $draft['subject'] = trim($subject);
        return $draft;
    }

    private function companyProfileExplicitlySupportsCrm(array $companyContext): bool
    {
        $haystack = strtolower(trim(implode(' ', array_filter([
            (string) ($companyContext['company_tagline'] ?? ''),
            (string) ($companyContext['company_description'] ?? ''),
            (string) ($companyContext['owner_company_context'] ?? ''),
            (string) ($companyContext['company_industry'] ?? ''),
        ]))));

        if ($haystack === '') {
            return false;
        }

        return str_contains($haystack, 'crm')
            || str_contains($haystack, 'customer relationship management')
            || str_contains($haystack, 'saas')
            || str_contains($haystack, 'software');
    }

    private function mapOutreachPostureToTone(string $outreachPosture): string
    {
        $normalized = strtolower(trim($outreachPosture));
        if ($normalized === '') {
            return '';
        }
        if (str_contains($normalized, 'consult')) {
            return 'consultative';
        }
        if (str_contains($normalized, 'warm') || str_contains($normalized, 'friendly')) {
            return 'warm';
        }
        if (str_contains($normalized, 'direct') || str_contains($normalized, 'assertive')) {
            return 'direct';
        }
        if (str_contains($normalized, 'founder') || str_contains($normalized, 'personal')) {
            return 'friendly';
        }

        return '';
    }

    private function defaultFormalityForChannel(string $channel): string
    {
        return match (strtolower($channel)) {
            'whatsapp', 'sms' => 'casual',
            default => 'balanced',
        };
    }

    private function buildDraftStyleInstructionText(array $style): string
    {
        $lines = [];
        $channel = strtolower(trim((string) ($style['channel'] ?? 'email')));
        if (!empty($style['tone'])) {
            $lines[] = 'Tone: ' . $style['tone'];
        }
        if (!empty($style['formality_level'])) {
            $lines[] = 'Formality: ' . $style['formality_level'];
        }
        if (!empty($style['reading_level'])) {
            $languageContext = $this->languageLevels->context($style['reading_level']);
            $lines[] = 'Language level: ' . $languageContext['label'];
            $lines[] = $languageContext['prompt'];
        }
        if (!empty($style['cta_style'])) {
            $lines[] = 'CTA style: ' . $style['cta_style'];
        }
        if (!empty($style['length_band'])) {
            $lines[] = 'Preferred length: ' . $style['length_band'];
        }
        if (!empty($style['fullness'])) {
            $lines[] = 'Reply fullness: ' . $style['fullness'];
        }
        $lines[] = !empty($style['include_clear_cta'])
            ? 'Include a clear next-step CTA when the context supports it.'
            : 'Do not force a strong CTA when a softer close is enough.';
        $lines[] = 'Keep the reply human, concise, and centered on one clear intent.';
        $lines[] = 'Remove unnecessary detail, avoid over-explaining, and do not try to cover everything in one breath.';
        if (!empty($style['voice_notes'])) {
            $lines[] = 'Voice notes: ' . trim((string) $style['voice_notes']);
        }
        if ($channel === 'email') {
            $lines[] = 'Email rules: respond to the latest open point, avoid recapping unrelated history, and include a neutral business signoff.';
        } elseif ($channel === 'whatsapp') {
            $lines[] = 'WhatsApp rules: keep it short, natural, and chat-like rather than email-like.';
            if (!empty($style['continuity']['same_day_continuation'])) {
                $lines[] = 'This is a same-day continuing WhatsApp conversation, so do not start with Hi or Hello plus the contact name.';
            } else {
                $lines[] = 'If this is a new-day WhatsApp message, a light greeting is allowed but not required.';
            }
        } elseif ($channel === 'sms') {
            $lines[] = 'SMS rules: keep it brief, direct, conversational, and focused on one clear next step.';
        }

        return implode("\n", array_filter($lines));
    }

    private function applyDraftStyleToFallbackText(string $text, array $draftStyle, string $channel): string
    {
        $plain = trim($text);
        if ($plain === '') {
            return $plain;
        }

        $tone = strtolower(trim((string) ($draftStyle['tone'] ?? '')));
        if ($tone === 'warm' || $tone === 'friendly') {
            $plain = preg_replace('/^thank you/i', 'Thanks so much', $plain) ?? $plain;
            $plain = preg_replace('/^thanks/i', 'Thanks so much', $plain) ?? $plain;
        } elseif ($tone === 'direct') {
            $plain = preg_replace('/\bshortly\b/i', 'soon', $plain) ?? $plain;
            $plain = preg_replace('/\bthank you for your message\.\s*/i', 'Thanks for your message. ', $plain) ?? $plain;
        } elseif ($tone === 'consultative') {
            if (!str_contains(strtolower($plain), 'next step')) {
                $plain = rtrim($plain, '.') . '. I will share the next best step based on your situation.';
            }
        }

        $plain = $this->simplifyForReadingLevel($plain, (string) ($draftStyle['reading_level'] ?? self::DEFAULT_READING_LEVEL));
        $plain = $this->removeUnnecessaryDetail($plain);

        if (!empty($draftStyle['include_clear_cta'])) {
            $ctaSentence = match (strtolower((string) ($draftStyle['cta_style'] ?? 'clear'))) {
                'direct' => $channel === 'email'
                    ? 'Please reply with the best time to continue, and I will move this forward.'
                    : 'Reply with the best time and I will move this forward.',
                'soft' => $channel === 'email'
                    ? 'If helpful, feel free to reply with any detail you would like us to focus on next.'
                    : 'If helpful, reply with what you want us to focus on next.',
                default => $channel === 'email'
                    ? 'Please reply with the best next step for you, and I will tailor the follow-up.'
                    : 'Reply with the best next step for you, and I will tailor the follow-up.',
            };
            if (!$this->containsEquivalentCta($plain, $channel)) {
                $plain = rtrim($plain, '.') . '. ' . $ctaSentence;
            }
        }

        return $this->finalizeChannelText($plain, $draftStyle, $channel);
    }

    private function applyDraftStyleToGeneratedDraft(array $draft, array $draftStyle, string $channel, array $context = []): array
    {
        $plain = trim((string) ($draft['plain_body'] ?? ''));
        if ($plain === '' && trim((string) ($draft['html_body'] ?? '')) !== '') {
            $plain = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", (string) $draft['html_body'])));
        }
        if ($plain === '') {
            return $draft;
        }

        $normalizedPlain = $this->removeUnnecessaryDetail(
            $this->simplifyForReadingLevel($plain, (string) ($draftStyle['reading_level'] ?? self::DEFAULT_READING_LEVEL))
        );

        if (strtolower($channel) === 'email') {
            $emailBodies = $this->composeEmailBodies($normalizedPlain, $draftStyle);
            $draft['plain_body'] = $emailBodies['plain_body'];
            $draft['html_body'] = $emailBodies['html_body'];
            if ($emailBodies['signature_id'] !== null) {
                $draft['signature_id'] = $emailBodies['signature_id'];
            }
            if ($emailBodies['signature_html'] !== '') {
                $draft['signature_html'] = $emailBodies['signature_html'];
            }
            if ($emailBodies['signature_text'] !== '') {
                $draft['signature_text'] = $emailBodies['signature_text'];
            }
            $draft['body_includes_signature'] = $emailBodies['body_includes_signature'];
        } else {
            $draft['plain_body'] = $this->finalizeChannelText(
                $normalizedPlain,
                $draftStyle,
                $channel,
                $context
            );
            $draft['html_body'] = nl2br(htmlspecialchars($draft['plain_body'], ENT_QUOTES, 'UTF-8'));
        }

        if (strtolower($channel) === 'email' && trim((string) ($draft['subject'] ?? '')) === '') {
            $baseSubject = trim((string) ($context['communication']['subject'] ?? ''));
            if ($baseSubject !== '') {
                $draft['subject'] = preg_match('/^\s*re\s*:/i', $baseSubject) ? $baseSubject : 'Re: ' . $baseSubject;
            }
        }
        return $draft;
    }

    private function containsEquivalentCta(string $plain, string $channel): bool
    {
        $normalized = strtolower($plain);
        return str_contains($normalized, 'reply with')
            || str_contains($normalized, 'let me know')
            || ($channel === 'email' && str_contains($normalized, 'please reply'));
    }

    private function finalizeChannelText(string $text, array $draftStyle, string $channel, array $context = []): string
    {
        $channel = strtolower($channel);
        $plain = trim(str_replace(["\r\n", "\r"], "\n", $text));
        $plain = preg_replace("/[ \t]+/", ' ', $plain) ?? $plain;
        $plain = preg_replace("/\n{3,}/", "\n\n", $plain) ?? $plain;
        $plain = trim($plain);

        if ($channel === 'email') {
            return trim($this->ensureEmailSignoff($plain, $draftStyle));
        }

        $plain = $this->removeEmailLikeSignoff($plain);
        if (!empty($draftStyle['continuity']['same_day_continuation'])) {
            $plain = $this->stripGreeting($plain);
        }

        if ($channel === 'whatsapp') {
            $plain = $this->makeConversationalForChat($plain);
        } elseif ($channel === 'sms') {
            $plain = $this->makeConversationalForChat($plain, 2);
        }

        return trim($plain);
    }

    private function ensureEmailSignoff(string $plain, array $draftStyle): string
    {
        return $this->composeEmailBodies($plain, $draftStyle)['plain_body'];
    }

    private function removeEmailLikeSignoff(string $plain): string
    {
        $lines = preg_split("/\n+/", trim($plain)) ?: [];
        $filtered = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^(best regards|kind regards|regards|warm regards|sincerely|thanks|thank you|cheers),?$/i', $trimmed)) {
                continue;
            }
            $filtered[] = $trimmed;
        }
        return implode("\n", $filtered);
    }

    private function stripGreeting(string $plain): string
    {
        $updated = preg_replace('/^\s*(hi|hello|hey)\s+[^,\n]{1,40},?\s*/i', '', $plain);
        return trim((string) ($updated ?? $plain));
    }

    private function makeConversationalForChat(string $plain, int $maxLines = 3): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($plain)) ?: [];
        $sentences = array_values(array_filter(array_map(static fn ($value) => trim((string) $value), $sentences)));
        if (count($sentences) <= 1) {
            return trim($plain);
        }
        return implode("\n", array_slice($sentences, 0, $maxLines));
    }

    private function simplifyForReadingLevel(string $text, string $readingLevel): string
    {
        $readingLevel = $this->languageLevels->normalize($readingLevel);
        $replacements = [];
        if ($readingLevel === WorkspaceLanguageLevelService::LEVEL_1) {
            $replacements = [
                '/\bregarding\b/i' => 'about',
                '/\bassist\b/i' => 'help',
                '/\badditional\b/i' => 'more',
                '/\bshortly\b/i' => 'soon',
                '/\bappreciate\b/i' => 'value',
                '/\bprior\b/i' => 'earlier',
                '/\bfurther\b/i' => 'more',
            ];
        } elseif ($readingLevel === WorkspaceLanguageLevelService::LEVEL_2) {
            $replacements = [
                '/\bregarding\b/i' => 'about',
                '/\badditional\b/i' => 'more',
                '/\bprior\b/i' => 'earlier',
            ];
        }

        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return trim($text);
    }

    private function removeUnnecessaryDetail(string $text): string
    {
        $replacements = [
            '/\bI have reviewed your request and\b/i' => '',
            '/\bI have carefully reviewed\b/i' => 'I reviewed',
            '/\bPlease feel free to\b/i' => 'Please',
            '/\bAt your earliest convenience\b/i' => 'When you can',
            '/\bIn order to\b/i' => 'To',
            '/\bfor your reference\b/i' => '',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    private function resolveConversationContinuity(string $channel, array $additionalContext): array
    {
        $timezone = $this->resolveDraftTimezone($additionalContext);
        $latestActivityAt = $this->extractLatestActivityAt($additionalContext);
        $sameDayContinuation = false;

        if (in_array($channel, ['whatsapp', 'sms'], true) && $latestActivityAt !== '') {
            $activityDate = $this->parseStoredDateTime($latestActivityAt, $timezone);
            if ($activityDate instanceof \DateTimeImmutable) {
                $sameDayContinuation = $activityDate->format('Y-m-d') === (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d');
            }
        }

        return [
            'timezone' => $timezone,
            'latest_activity_at' => $latestActivityAt,
            'same_day_continuation' => $sameDayContinuation,
        ];
    }

    private function extractLatestActivityAt(array $additionalContext): string
    {
        $messages = [];
        if (!empty($additionalContext['thread_context']['messages']) && is_array($additionalContext['thread_context']['messages'])) {
            $messages = $additionalContext['thread_context']['messages'];
        } elseif (!empty($additionalContext['recent_communications']) && is_array($additionalContext['recent_communications'])) {
            $messages = $additionalContext['recent_communications'];
        }

        $latest = '';
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $createdAt = trim((string) ($message['created_at'] ?? ''));
            if ($createdAt === '') {
                continue;
            }
            if ($latest === '' || strcmp($createdAt, $latest) > 0) {
                $latest = $createdAt;
            }
        }

        return $latest;
    }

    private function resolveDraftTimezone(array $additionalContext): string
    {
        $companyContext = $this->getCompanyReplyContext();
        $candidates = [
            (string) ($additionalContext['company_profile']['company_timezone'] ?? ''),
            (string) ($additionalContext['thread_context']['company_timezone'] ?? ''),
            (string) ($companyContext['company_timezone'] ?? ''),
            (string) ($_ENV['APP_TIMEZONE'] ?? ''),
            date_default_timezone_get(),
            'UTC',
        ];

        foreach ($candidates as $candidate) {
            $timezone = $this->normalizeTimezoneIdentifier((string) $candidate);
            if ($this->isValidTimezone($timezone)) {
                return $timezone;
            }
        }

        return 'UTC';
    }

    private function parseStoredDateTime(string $value, string $displayTimezone): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $sourceTimezone = $this->normalizeTimezoneIdentifier(trim((string) ($_ENV['DB_TIMEZONE'] ?? ($_ENV['APP_TIMEZONE'] ?? $displayTimezone))));
        if (!$this->isValidTimezone($sourceTimezone)) {
            $sourceTimezone = $displayTimezone;
        }

        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone($sourceTimezone)))
                ->setTimezone(new \DateTimeZone($displayTimezone));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveDefaultSignatureName(int $userId = 0): string
    {
        if ($userId > 0) {
            $user = Database::queryOne(
                "SELECT first_name, last_name, email
                 FROM users
                 WHERE id = ?",
                [$userId]
            ) ?: [];
            $fullName = trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
            if ($fullName !== '') {
                return $fullName;
            }

            $email = trim((string) ($user['email'] ?? ''));
            if ($email !== '' && str_contains($email, '@')) {
                $emailName = strstr($email, '@', true);
                $emailName = trim(str_replace(['.', '_', '-'], ' ', (string) $emailName));
                if ($emailName !== '') {
                    return ucwords($emailName);
                }
            }
        }

        $company = $this->companyProfile->get() ?: [];
        $candidates = [
            trim((string) ($company['company_name'] ?? '')),
            brandProductName(),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'Team';
    }

    private function resolveDefaultEmailSignature(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $signature = $this->emailSignatures->getDefaultForUser($userId);
        if (!$signature) {
            return [];
        }

        $signatureText = $this->emailSignatures->getSignatureText($signature);
        $signatureHtml = $this->emailSignatures->getSignatureHtml($signature);

        return [
            'id' => (int) ($signature['id'] ?? 0),
            'name' => (string) ($signature['name'] ?? ''),
            'content_html' => (string) ($signature['content_html'] ?? ''),
            'content_text' => $signatureText,
            'rendered_html' => $signatureHtml,
            'rendered_text' => $signatureText,
        ];
    }

    private function composeEmailBodies(string $plain, array $draftStyle): array
    {
        $basePlain = trim($this->stripTrailingEmailSignoffBlock($plain));
        if ($basePlain === '') {
            return [
                'plain_body' => '',
                'html_body' => '',
                'signature_id' => null,
                'signature_html' => '',
                'signature_text' => '',
                'body_includes_signature' => false,
            ];
        }

        $signature = (array) ($draftStyle['default_signature'] ?? []);
        $signatureText = trim((string) ($signature['rendered_text'] ?? $signature['content_text'] ?? ''));
        $signatureHtml = trim((string) ($signature['rendered_html'] ?? $signature['content_html'] ?? ''));

        if ($signatureText === '' && $signatureHtml !== '') {
            $signatureText = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $signatureHtml)), ENT_QUOTES, 'UTF-8'));
        }
        if ($signatureHtml === '' && $signatureText !== '') {
            $signatureHtml = nl2br(htmlspecialchars($signatureText, ENT_QUOTES, 'UTF-8'));
        }

        if ($signatureText !== '') {
            return [
                'plain_body' => rtrim($basePlain, ", \n\t") . "\n\n" . $signatureText,
                'html_body' => nl2br(htmlspecialchars(rtrim($basePlain, ", \n\t"), ENT_QUOTES, 'UTF-8')) . '<br><br>' . $signatureHtml,
                'signature_id' => !empty($signature['id']) ? (int) $signature['id'] : null,
                'signature_html' => $signatureHtml,
                'signature_text' => $signatureText,
                'body_includes_signature' => true,
            ];
        }

        $signoff = trim((string) ($draftStyle['default_signoff'] ?? 'Best regards,'));
        $signatureName = trim((string) ($draftStyle['default_signature_name'] ?? ''));
        $plainSuffix = $signoff;
        if ($signatureName !== '') {
            $plainSuffix .= "\n" . $signatureName;
        }

        return [
            'plain_body' => rtrim($basePlain, ", \n\t") . "\n\n" . $plainSuffix,
            'html_body' => nl2br(htmlspecialchars(rtrim($basePlain, ", \n\t"), ENT_QUOTES, 'UTF-8')) . '<br><br>' . nl2br(htmlspecialchars($plainSuffix, ENT_QUOTES, 'UTF-8')),
            'signature_id' => null,
            'signature_html' => '',
            'signature_text' => $plainSuffix,
            'body_includes_signature' => false,
        ];
    }

    private function stripTrailingEmailSignoffBlock(string $plain): string
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $plain));
        if ($normalized === '') {
            return '';
        }

        $lines = preg_split("/\n+/", $normalized) ?: [];
        $signoffPattern = '/^(best regards|kind regards|regards|warm regards|sincerely|thanks|thank you|cheers),?$/i';

        for ($index = count($lines) - 1; $index >= 0; $index--) {
            if (preg_match($signoffPattern, trim((string) $lines[$index]))) {
                $lines = array_slice($lines, 0, $index);
                break;
            }
        }

        $trimmedLines = [];
        foreach ($lines as $line) {
            $trimmedLines[] = rtrim((string) $line);
        }

        return trim(implode("\n", $trimmedLines));
    }

    private function normalizeTimezoneIdentifier(string $rawTimezone): string
    {
        $timezone = trim($rawTimezone);
        if ($timezone === '') {
            return '';
        }

        return str_replace(' ', '_', $timezone);
    }

    private function isValidTimezone(string $timezone): bool
    {
        if ($timezone === '') {
            return false;
        }

        try {
            new \DateTimeZone($timezone);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function extractLatestInbound(array $threadContext, array $communication): string
    {
        $messages = (array) ($threadContext['messages'] ?? $threadContext);
        $channel = strtolower((string) ($threadContext['channel'] ?? $communication['channel'] ?? 'email'));
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = (array) ($messages[$i] ?? []);
            if ((string) ($message['direction'] ?? '') !== 'inbound') {
                continue;
            }
            $body = trim((string) ($message['clean_body'] ?? ''));
            if ($body === '') {
                $body = ConversationMessageCleaner::cleanBody((string) ($message['body'] ?? ''), $channel);
            }
            if ($body !== '') {
                return $body;
            }
        }

        return ConversationMessageCleaner::cleanBody((string) ($communication['body'] ?? ''), $channel);
    }

    private function extractJsonObject(string $text): ?array
    {
        $raw = trim($text);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{[\s\S]*\}/', $raw, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
