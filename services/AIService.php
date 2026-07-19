<?php
/**
 * AI Service Router
 * Tiered AI strategy implementation
 */

namespace CRM\Services;

use CRM\Auth;
use CRM\CacheManager;

class AIService
{
    private const LOCAL_PROVIDER_COOLDOWN_SECONDS = 60;

    private CacheManager $cache;
    private AITokenRateLimiterService $tokenRateLimiter;
    private AITextResponseNormalizerService $textResponseNormalizer;
    private array $lastProviderStatus = [];
    private static int $localProviderCooldownUntil = 0;
    private static string $localProviderCooldownMessage = '';
    private array $modelMap = [
        'summarization' => 'tier1',
        'email_draft' => 'tier2',
        'whatsapp_draft' => 'tier2',
        'email_auto_reply' => 'tier2',
        'whatsapp_auto_reply' => 'tier2',
        'sms_auto_reply' => 'tier2',
        'sentiment' => 'tier1',
        'intent_detection' => 'tier1',
        'tone_adjustment' => 'tier2',
        'personalization' => 'tier2',
        'whatsapp_quick_replies' => 'tier2',
        'smart_tagging' => 'tier1',
        'auto_categorization' => 'tier1',
        'data_extraction' => 'tier2',
        'data_inference' => 'tier2',
        'data_validation' => 'tier2',
        'workflow_recommendations' => 'tier2',
        'workflow_generation' => 'tier2',
        'smart_email_template_pack' => 'tier2',
        'smart_workflow_template_pack' => 'tier2',
        'ai_coach_recommendations' => 'tier2',
        'email_assistant_question' => 'tier2',
        'email_assistant_intent' => 'tier2',
        'email_assistant_parse_task' => 'tier2',
        'email_assistant_parse_instructions' => 'tier2',
        'email_assistant_parse_contact' => 'tier2',
        'email_assistant_parse_contact_update' => 'tier2',
        'email_assistant_parse_note' => 'tier2',
        'email_assistant_parse_event' => 'tier2',
        'email_assistant_entity_resolution' => 'tier2',
        'email_assistant_customer_reply_goal' => 'tier2',
        'email_assistant_commercial_reply' => 'tier2',
        'email_assistant_change_explanation' => 'tier2',
        'email_assistant_ambiguity_summary' => 'tier2',
        'ai_context_diagnostics' => 'tier2',
        'ai_goal_relevance_score' => 'tier2',
        'ai_mode_qualification' => 'tier2',
        'ai_task_completion_match' => 'tier2',
        'website_assistant_question' => 'tier2',
        'onboarding_clarity_draft' => 'tier2',
        'startup_journey_report' => 'tier2',
        'marketplace_catalog_copy' => 'tier2',
        'social_post_variants' => 'tier2',
        'meeting_prep_summary' => 'tier2',
        'meeting_note_analysis' => 'tier2',
        'thread_summary' => 'tier2',
        'deal_next_steps' => 'tier2',
        'deal_summary' => 'tier2',
        'deal_close_date' => 'tier2',
        'deal_stage_suggestion' => 'tier2',
        'action_item_extraction' => 'tier2',
        'form_submission_analysis' => 'tier2',
        'search_query_parse' => 'tier2',
        'document_extraction' => 'tier2',
        'nl_report' => 'tier2',
    ];
    
    public function __construct()
    {
        $this->cache = new CacheManager();
        $this->tokenRateLimiter = new AITokenRateLimiterService();
        $this->textResponseNormalizer = new AITextResponseNormalizerService();
        $this->lastProviderStatus = [
            'provider' => 'none',
            'mode' => 'idle',
            'fallback_used' => false,
            'message' => 'No AI requests have been processed yet.',
        ];
    }
    
    /**
     * Process AI task
     */
    public function process(string $task, array $data, array $context = []): string
    {
        $profile = $this->taskProfile($task, $context);
        $workspaceId = $this->resolveWorkspaceId($context);
        $userId = $this->resolveUserId($context);
        $surface = (string) $profile['surface'];

        if (!$this->runtimeAllowsExecution($surface, $workspaceId)) {
            return '';
        }

        $cacheKey = $this->buildCacheKey($task, $data, $context, $workspaceId, $userId);
        $cached = ($cacheKey !== null && empty($context['force_refresh'])) ? $this->cache->get($cacheKey) : null;

        if ($cached !== null && $cached !== '') {
            $cachedContract = $data['_resolved_prompt']['output_contract'] ?? [
                'type' => (string) ($profile['output_type'] ?? 'text'),
            ];
            $validatedCache = $this->validateOutputContract((string) $cached, $cachedContract);
            if (!empty($validatedCache['valid'])) {
                $this->setProviderStatus([
                    'provider' => 'cache',
                    'mode' => 'cached',
                    'fallback_used' => false,
                    'success' => true,
                    'cache_hit' => true,
                    'workspace_id' => $workspaceId,
                    'user_id' => $userId,
                    'surface' => $surface,
                    'source' => 'cache',
                    'contract_valid' => true,
                    'prompt_truncated' => !empty($data['_resolved_prompt']['prompt_truncated']),
                    'message' => 'Cached AI result reused.',
                ]);
                return (string) ($validatedCache['output'] ?? $cached);
            }
            if ($cacheKey !== null) {
                $this->cache->delete($cacheKey);
            }
        }

        if (!empty($context['cache_only']) && $this->supportsCacheOnlyFallback($task)) {
            $this->setProviderStatus([
                'provider' => 'none',
                'mode' => 'cache_only_miss',
                'fallback_used' => true,
                'success' => false,
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'surface' => $surface,
                'source' => 'cache_only_miss',
                'blocked_reason' => 'cache_miss',
                'message' => 'No cached AI result was available, so the caller should use its deterministic fallback.',
            ]);
            return '';
        }

        $executionContext = $context + [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'surface' => $surface,
            'temperature' => $profile['temperature'],
            'max_tokens' => $profile['max_tokens'],
            'output_type' => $profile['output_type'],
            'reasoning_effort' => $profile['reasoning_effort'],
        ];

        $result = '';
        $attemptedProviders = [];
        foreach ($this->providerOrder((string) $profile['provider_order']) as $provider) {
            if ($provider === 'local' && !$this->isLocalProviderExplicitlyEnabled()) {
                continue;
            }

            $attemptedProviders[] = $provider;
            $result = $provider === 'local'
                ? $this->localML($task, $data, $executionContext)
                : $this->openSourceAPI($task, $data, $executionContext);

            if ($result !== '') {
                $this->lastProviderStatus['attempted_providers'] = $attemptedProviders;
                break;
            }

            if (in_array((string) ($this->lastProviderStatus['blocked_reason'] ?? ''), [
                'workspace_ai_key_required',
                'content_generation_key_required',
            ], true)) {
                break;
            }
        }

        if ($result === '' && $attemptedProviders === []) {
            $this->setProviderStatus([
                'provider' => 'none',
                'mode' => 'provider_unavailable',
                'fallback_used' => true,
                'success' => false,
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'surface' => $surface,
                'source' => 'deterministic_fallback',
                'blocked_reason' => 'provider_unavailable',
                'message' => 'No enabled AI provider is available. The caller should use its deterministic fallback.',
            ]);
        } elseif ($result === '') {
            $this->lastProviderStatus['attempted_providers'] = $attemptedProviders;
        }

        if ($result !== '') {
            $outputContract = $data['_resolved_prompt']['output_contract'] ?? [
                'type' => (string) ($profile['output_type'] ?? 'text'),
            ];
            $validated = $this->validateOutputContract($result, $outputContract);
            if (empty($validated['valid'])) {
                $this->setProviderStatus(array_merge($this->lastProviderStatus, [
                    'success' => false,
                    'fallback_used' => true,
                    'contract_valid' => false,
                    'blocked_reason' => 'invalid_output_contract',
                    'source' => 'deterministic_fallback',
                    'message' => (string) ($validated['message'] ?? 'AI output did not match the required contract.'),
                ]));
                $result = '';
            } else {
                $result = (string) ($validated['output'] ?? $result);
                $this->lastProviderStatus['contract_valid'] = true;
            }
        }

        if ($result !== '' && $cacheKey !== null) {
            $this->cache->set($cacheKey, $result, 3600);
        }
        if (!empty($data['_resolved_prompt']['prompt_truncated'])) {
            $this->lastProviderStatus['prompt_truncated'] = true;
        }
        
        return $result;
    }

    public function getLastProviderStatus(): array
    {
        return $this->lastProviderStatus;
    }

    public function processWithPrompt(string $task, array $resolvedPrompt, array $context = []): string
    {
        $data = $resolvedPrompt;
        $data['_resolved_prompt'] = $resolvedPrompt;
        $data['prompt'] = (string) ($resolvedPrompt['rendered_prompt'] ?? $resolvedPrompt['prompt'] ?? '');
        $data['text'] = (string) ($resolvedPrompt['rendered_prompt'] ?? $resolvedPrompt['prompt'] ?? '');

        $result = $this->process($task, $data, $context + [
            'surface' => (string) ($resolvedPrompt['surface'] ?? ''),
        ]);
        if ($result === '') {
            return '';
        }

        $validated = $this->validateOutputContract($result, $resolvedPrompt['output_contract'] ?? null);
        if (empty($validated['valid'])) {
            $this->setProviderStatus(array_merge($this->lastProviderStatus, [
                'success' => false,
                'fallback_used' => true,
                'contract_valid' => false,
                'blocked_reason' => 'invalid_output_contract',
                'source' => 'deterministic_fallback',
                'message' => (string) ($validated['message'] ?? 'AI output did not match the required contract.'),
            ]));
            return '';
        }

        $this->lastProviderStatus['contract_valid'] = true;
        return (string) ($validated['output'] ?? $result);
    }

    public function buildPromptFromRegistry(string $surface, string $promptKey, array $bundle, array $inputs = [], ?int $version = null): array
    {
        $registry = new AIPromptRegistryService();
        $resolved = ($version !== null && $version > 0)
            ? $registry->getPromptVersion($surface, $promptKey, $version)
            : $registry->getActivePrompt($surface, $promptKey);
        $resolved = $resolved ?? [
            'surface' => $surface,
            'prompt_key' => $promptKey,
            'version' => 0,
            'status' => 'active',
            'system_prompt_text' => 'Legacy inline prompt fallback.',
            'instruction_text' => '',
            'output_contract_json' => ['type' => 'legacy_inline'],
            'metadata_json' => ['legacy_inline' => true],
        ];

        $legacyPrompt = trim((string) ($inputs['legacy_prompt'] ?? ''));
        $responseStyleInstruction = $this->responseStyleInstructionFromPromptContext($bundle, $inputs);
        $usesLegacyInline = !empty($resolved['metadata_json']['legacy_inline']) || (($resolved['output_contract_json']['type'] ?? '') === 'legacy_inline');
        if ($usesLegacyInline && $legacyPrompt !== '') {
            $uncappedPrompt = $this->appendResponseStyleInstruction($legacyPrompt, $responseStyleInstruction);
            $renderedPrompt = $this->limitPromptChars($uncappedPrompt, (int) ($inputs['max_prompt_chars'] ?? 18000));

            return [
                'surface' => $surface,
                'prompt_key' => $promptKey,
                'prompt_version' => (int) ($resolved['version'] ?? 0),
                'system_prompt' => $this->limitPromptChars((string) ($resolved['system_prompt_text'] ?? ''), 6000),
                'instruction_prompt' => (string) ($resolved['instruction_text'] ?? ''),
                'context_bundle' => $bundle,
                'user_prompt' => $renderedPrompt,
                'rendered_prompt' => $renderedPrompt,
                'output_contract' => $resolved['output_contract_json'] ?? null,
                'metadata' => $resolved['metadata_json'] ?? [],
                'prompt_truncated' => $renderedPrompt !== $uncappedPrompt,
            ];
        }

        $sections = [];
        if (!empty($resolved['instruction_text'])) {
            $sections[] = "INSTRUCTIONS:\n" . trim((string) $resolved['instruction_text']);
        }
        $outputContractType = strtolower((string) ($resolved['output_contract_json']['type'] ?? ''));
        if (!empty($resolved['output_contract_json']) && !in_array($outputContractType, ['text', 'legacy_inline'], true)) {
            $sections[] = "OUTPUT CONTRACT:\n" . json_encode($resolved['output_contract_json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } elseif ($outputContractType === 'text') {
            $sections[] = "PLAIN TEXT RESPONSE:\nReturn the answer itself as normal prose. Do not wrap it in JSON or emit schema metadata.";
        }
        if ($responseStyleInstruction !== '') {
            $sections[] = "RESPONSE STYLE:\n" . $responseStyleInstruction;
        }
        $sections[] = "CONTEXT BUNDLE SUMMARY:\n" . json_encode([
            'surface' => $bundle['surface'] ?? $surface,
            'prompt_key' => $bundle['prompt_key'] ?? $promptKey,
            'bundle_quality' => $bundle['bundle_quality'] ?? [],
            'block_count' => count((array) ($bundle['blocks'] ?? [])),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $sections[] = "CONTEXT BLOCKS:\n" . json_encode($bundle['blocks'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $promptInputs = $this->sanitizePromptInputs($inputs);
        if ($promptInputs !== []) {
            $sections[] = "INPUTS:\n" . json_encode($promptInputs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        $finalAnswerSection = '';
        if ($surface === 'clarity_chat') {
            $styleContract = (array) ($bundle['response_style_contract'] ?? []);
            $finalAnswerSection = "FINAL ANSWER CONTRACT:\n" . (new WorkspaceLanguageLevelService())->clarityPublicAnswerInstruction(
                $styleContract['level'] ?? null
            );
            $sections[] = $finalAnswerSection;
        }

        $uncappedPrompt = implode("\n\n", array_filter($sections));
        $renderedPrompt = $finalAnswerSection !== ''
            ? $this->limitPromptCharsPreservingSuffix($uncappedPrompt, $finalAnswerSection, (int) ($inputs['max_prompt_chars'] ?? 18000))
            : $this->limitPromptChars($uncappedPrompt, (int) ($inputs['max_prompt_chars'] ?? 18000));

        return [
            'surface' => $surface,
            'prompt_key' => $promptKey,
            'prompt_version' => (int) ($resolved['version'] ?? 0),
            'system_prompt' => $this->limitPromptChars((string) ($resolved['system_prompt_text'] ?? ''), 6000),
            'instruction_prompt' => (string) ($resolved['instruction_text'] ?? ''),
            'context_bundle' => $bundle,
            'user_prompt' => $renderedPrompt,
            'rendered_prompt' => $renderedPrompt,
            'output_contract' => $resolved['output_contract_json'] ?? null,
            'metadata' => $resolved['metadata_json'] ?? [],
            'prompt_truncated' => $renderedPrompt !== $uncappedPrompt,
        ];
    }

    private function responseStyleInstructionFromPromptContext(array $bundle, array $inputs = []): string
    {
        foreach ([
            $bundle['response_style_contract'] ?? null,
            $inputs['response_style_contract'] ?? null,
            $inputs['operating_context']['ai_settings']['response_style_contract'] ?? null,
        ] as $contract) {
            $instruction = $this->responseStyleInstructionFromContract($contract);
            if ($instruction !== '') {
                return $instruction;
            }
        }

        foreach ((array) ($bundle['blocks'] ?? []) as $block) {
            if ((string) ($block['type'] ?? '') !== 'response_style_contract') {
                continue;
            }
            $instruction = $this->responseStyleInstructionFromContract($block['content'] ?? null);
            if ($instruction !== '') {
                return $instruction;
            }
        }

        $userId = (int) (
            $inputs['user_id']
            ?? $inputs['owner_user_id']
            ?? $inputs['context']['user_id']
            ?? $inputs['context']['owner_user_id']
            ?? 0
        );

        return $this->responseStyleInstructionFromContract(
            (new WorkspaceLanguageLevelService())->currentResponseStyleContract($userId > 0 ? $userId : null)
        );
    }

    private function responseStyleInstructionFromContract(mixed $contract): string
    {
        if (!is_array($contract) || $contract === []) {
            return '';
        }

        return trim((string) ($contract['prompt_instruction'] ?? ''));
    }

    private function appendResponseStyleInstruction(string $prompt, string $instruction): string
    {
        if ($instruction === '' || stripos($prompt, 'LANGUAGE LEVEL:') !== false) {
            return $prompt;
        }

        return rtrim($prompt) . "\n\nRESPONSE STYLE:\n" . $instruction;
    }
    
    /**
     * Local ML (Ollama)
     */
    private function localML(string $task, array $data, array $context = []): string
    {
        $url = ($_ENV['OLLAMA_URL'] ?? 'http://localhost:11434') . '/api/generate';
        if ($this->shouldUseImmediateFastFallback($task, $context)) {
            return $this->returnFastFallbackResult($url);
        }

        $resolvedPrompt = (string) (($data['_resolved_prompt']['user_prompt'] ?? $data['_resolved_prompt']['rendered_prompt'] ?? $data['prompt'] ?? ''));
        $systemPrompt = trim((string) ($data['_resolved_prompt']['system_prompt'] ?? ''));

        $prompt = $resolvedPrompt !== '' ? $resolvedPrompt : match($task) {
            'summarization' => "Summarize: " . substr($data['text'], 0, 2000),
            'sentiment' => "Analyze sentiment: " . substr($data['text'], 0, 1000),
            'email_draft' => $this->buildEmailDraftPrompt($data),
            'whatsapp_draft' => $this->buildWhatsAppDraftPrompt($data),
            'email_auto_reply' => $this->buildAutoReplyPrompt($data, 'email'),
            'whatsapp_auto_reply' => $this->buildAutoReplyPrompt($data, 'whatsapp'),
            'sms_auto_reply' => $this->buildAutoReplyPrompt($data, 'sms'),
            'intent_detection' => "Detect intent in: " . substr($data['text'] ?? '', 0, 1000),
            'tone_adjustment' => $this->buildToneAdjustmentPrompt($data),
            'personalization' => $this->buildPersonalizationPrompt($data),
            'whatsapp_quick_replies' => $this->buildQuickRepliesPrompt($data),
            'smart_tagging' => "Suggest tags for: " . substr($data['text'] ?? '', 0, 1000),
            'auto_categorization' => "Categorize: " . substr($data['text'] ?? '', 0, 1000),
            'workflow_recommendations' => $this->buildWorkflowRecommendationsPrompt($data),
            'workflow_generation' => $this->buildWorkflowGenerationPrompt($data),
            'email_assistant_question' => $this->buildEmailAssistantQuestionPrompt($data),
            'email_assistant_intent' => $this->buildEmailAssistantIntentPrompt($data),
            'email_assistant_parse_task' => $this->buildEmailAssistantParseTaskPrompt($data),
            'email_assistant_parse_instructions' => $this->buildEmailAssistantParseInstructionsPrompt($data),
            'email_assistant_parse_contact' => $this->buildEmailAssistantParseContactPrompt($data),
            'email_assistant_parse_contact_update' => $this->buildEmailAssistantParseContactUpdatePrompt($data),
            'email_assistant_parse_note' => $this->buildEmailAssistantParseNotePrompt($data),
            'email_assistant_parse_event' => $this->buildEmailAssistantParseEventPrompt($data),
            'email_assistant_entity_resolution' => $this->buildEmailAssistantEntityResolutionPrompt($data),
            'email_assistant_customer_reply_goal' => $this->buildEmailAssistantCustomerReplyGoalPrompt($data),
            'email_assistant_commercial_reply' => $this->buildEmailAssistantCommercialReplyPrompt($data),
            'email_assistant_change_explanation' => $this->buildEmailAssistantChangeExplanationPrompt($data),
            'email_assistant_ambiguity_summary' => $this->buildEmailAssistantAmbiguitySummaryPrompt($data),
            'ai_context_diagnostics' => $this->buildAIContextDiagnosticsPrompt($data),
            'ai_goal_relevance_score' => $this->buildAIGoalRelevancePrompt($data),
            'ai_mode_qualification' => $this->buildAIModeQualificationPrompt($data),
            'ai_task_completion_match' => $this->buildAITaskCompletionMatchPrompt($data),
            'website_assistant_question' => $this->buildWebsiteAssistantPrompt($data),
            'meeting_prep_summary' => $this->buildMeetingPrepPrompt($data),
            'meeting_note_analysis' => $this->buildMeetingNoteAnalysisPrompt($data),
            'thread_summary' => $this->buildThreadSummaryPrompt($data),
            'deal_next_steps' => $this->buildDealNextStepsPrompt($data),
            'deal_summary' => $this->buildDealSummaryPrompt($data),
            'deal_close_date' => $this->buildDealCloseDatePrompt($data),
            'deal_stage_suggestion' => $this->buildDealStageSuggestionPrompt($data),
            'action_item_extraction' => $this->buildActionItemExtractionPrompt($data),
            'form_submission_analysis' => $this->buildFormSubmissionAnalysisPrompt($data),
            'search_query_parse' => $this->buildSearchQueryParsePrompt($data),
            'document_extraction' => $this->buildDocumentExtractionPrompt($data),
            'nl_report' => $this->buildNLReportPrompt($data),
            default => $data['text'] ?? ''
        };
        if ($systemPrompt !== '') {
            $prompt = "SYSTEM:\n" . $systemPrompt . "\n\nUSER:\n" . $prompt;
        }

        $localModel = trim((string) ($_ENV['AI_LOCAL_MODEL'] ?? $_ENV['OLLAMA_MODEL'] ?? 'mistral:7b'));
        if ($localModel === '') {
            $localModel = 'mistral:7b';
        }
        $reservation = $this->tokenRateLimiter->beginRequest(
            'ollama',
            $localModel,
            $task,
            $prompt,
            !empty($context['user_id']) ? (int) $context['user_id'] : null,
            [
                'provider_source' => WorkspaceAIProviderResolverService::SOURCE_LOCAL_FALLBACK,
                'workspace_id' => (int) ($context['workspace_id'] ?? 0),
                'credential_scope' => (string) ($context['credential_scope'] ?? WorkspaceAIProviderConfigService::SCOPE_GENERAL),
            ]
        );
        
        $payload = [
            'model' => $localModel,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => (float) ($context['temperature'] ?? 0.4),
                'num_predict' => (int) ($context['max_tokens'] ?? 1200),
            ],
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5
        ]);
        
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false) {
            $this->tokenRateLimiter->failRequest($reservation);
            $this->markLocalProviderFailure($curlError !== '' ? $curlError : 'unknown transport error');
            $this->setProviderStatus([
                'provider' => 'ollama',
                'mode' => 'local_fallback',
                'fallback_used' => true,
                'success' => false,
                'http_code' => $httpCode,
                'message' => $curlError !== '' ? $curlError : 'unknown transport error',
                'url' => $url,
                'workspace_id' => (int) ($context['workspace_id'] ?? 0),
                'user_id' => (int) ($context['user_id'] ?? 0),
                'surface' => (string) ($context['surface'] ?? 'global'),
                'model' => $localModel,
                'source' => 'local_provider',
            ]);
            error_log('AIService localML request failed for task ' . $task . ': ' . ($curlError !== '' ? $curlError : 'unknown transport error'));
            return '';
        }
        if ($httpCode >= 400) {
            $this->tokenRateLimiter->failRequest($reservation);
            $this->markLocalProviderFailure('Local AI returned HTTP ' . $httpCode);
            $this->setProviderStatus([
                'provider' => 'ollama',
                'mode' => 'local_fallback',
                'fallback_used' => true,
                'success' => false,
                'http_code' => $httpCode,
                'message' => 'Local AI returned HTTP ' . $httpCode,
                'url' => $url,
                'workspace_id' => (int) ($context['workspace_id'] ?? 0),
                'user_id' => (int) ($context['user_id'] ?? 0),
                'surface' => (string) ($context['surface'] ?? 'global'),
                'model' => $localModel,
                'source' => 'local_provider',
            ]);
            error_log('AIService localML request returned HTTP ' . $httpCode . ' for task ' . $task);
            return '';
        }
        
        $result = json_decode($response, true);
        if (!is_array($result)) {
            $this->tokenRateLimiter->failRequest($reservation);
            $this->markLocalProviderFailure('Local AI returned invalid JSON.');
            $this->setProviderStatus([
                'provider' => 'ollama',
                'mode' => 'local_fallback',
                'fallback_used' => true,
                'success' => false,
                'http_code' => $httpCode,
                'message' => 'Local AI returned invalid JSON.',
                'url' => $url,
                'workspace_id' => (int) ($context['workspace_id'] ?? 0),
                'user_id' => (int) ($context['user_id'] ?? 0),
                'surface' => (string) ($context['surface'] ?? 'global'),
                'model' => $localModel,
                'source' => 'local_provider',
            ]);
            error_log('AIService localML request returned invalid JSON for task ' . $task);
            return '';
        }
        $this->clearLocalProviderFailure();
        $this->setProviderStatus([
            'provider' => 'ollama',
            'mode' => 'local_fallback',
            'fallback_used' => true,
            'success' => true,
            'http_code' => $httpCode,
            'message' => 'Local fallback AI request succeeded.',
            'url' => $url,
            'workspace_id' => (int) ($context['workspace_id'] ?? 0),
            'user_id' => (int) ($context['user_id'] ?? 0),
            'surface' => (string) ($context['surface'] ?? 'global'),
            'model' => $localModel,
            'source' => 'local_provider',
            'contract_valid' => null,
        ]);
        $output = (string) ($result['response'] ?? '');
        $this->tokenRateLimiter->completeRequest($reservation, $output);
        return $output;
    }
    
    /**
     * Open source API (OpenAI or compatible)
     */
    private function openSourceAPI(string $task, array $data, array $context = []): string
    {
        $workspaceId = (int) ($context['workspace_id'] ?? $this->resolveWorkspaceId($context));
        $providerResolver = new WorkspaceAIProviderResolverService();
        $requireWorkspaceProvider = !empty($context['require_workspace_provider']);
        $credentialScope = (new WorkspaceAIProviderConfigService())->normalizeCredentialScope(
            (string) ($context['credential_scope'] ?? WorkspaceAIProviderConfigService::SCOPE_GENERAL)
        );
        $resolvedProvider = $requireWorkspaceProvider
            ? $providerResolver->resolveWorkspaceOwned($workspaceId, $task, $credentialScope)
            : $providerResolver->resolveForWorkspace($workspaceId, $task, $credentialScope);
        $providerConfig = (array) ($resolvedProvider['provider_config'] ?? AIRuntimeConfig::getProviderConfig());
        $apiUrl = (string) ($providerConfig['normalized_api_url'] ?? '');
        $apiKey = trim((string) ($resolvedProvider['api_key'] ?? ($requireWorkspaceProvider ? '' : ($_ENV['AI_API_KEY'] ?? ''))));

        if (empty($resolvedProvider['available']) || $apiUrl === '' || $apiKey === '') {
            $this->setProviderStatus([
                'provider' => 'remote',
                'mode' => in_array((string) ($resolvedProvider['blocked_reason'] ?? ''), ['workspace_ai_key_required', 'content_generation_key_required'], true)
                    ? 'workspace_key_required'
                    : 'fallback_only',
                'fallback_used' => true,
                'success' => false,
                'message' => (string) ($resolvedProvider['message'] ?? $providerConfig['message'] ?? 'Remote AI provider is not configured.'),
                'url' => $apiUrl,
                'provider_source' => (string) ($resolvedProvider['source'] ?? WorkspaceAIProviderResolverService::SOURCE_ENV),
                'credential_scope' => $credentialScope,
                'workspace_id' => $workspaceId,
                'user_id' => (int) ($context['user_id'] ?? 0),
                'surface' => (string) ($context['surface'] ?? 'global'),
                'source' => 'remote_provider',
                'blocked_reason' => (string) ($resolvedProvider['blocked_reason'] ?? 'remote_ai_not_configured'),
            ]);
            error_log('AIService remote provider unavailable for task ' . $task . ': ' . ($resolvedProvider['message'] ?? $providerConfig['message'] ?? 'missing AI configuration'));
            return '';
        }
        
        $resolvedPrompt = (string) (($data['_resolved_prompt']['user_prompt'] ?? $data['_resolved_prompt']['rendered_prompt'] ?? $data['prompt'] ?? ''));
        $systemPrompt = trim((string) ($data['_resolved_prompt']['system_prompt'] ?? ''));

        $prompt = $resolvedPrompt !== '' ? $resolvedPrompt : match($task) {
            'email_draft' => $this->buildEmailDraftPrompt($data),
            'whatsapp_draft' => $this->buildWhatsAppDraftPrompt($data),
            'email_auto_reply' => $this->buildAutoReplyPrompt($data, 'email'),
            'whatsapp_auto_reply' => $this->buildAutoReplyPrompt($data, 'whatsapp'),
            'sms_auto_reply' => $this->buildAutoReplyPrompt($data, 'sms'),
            'tone_adjustment' => $this->buildToneAdjustmentPrompt($data),
            'personalization' => $this->buildPersonalizationPrompt($data),
            'whatsapp_quick_replies' => $this->buildQuickRepliesPrompt($data),
            'data_extraction' => $this->buildDataExtractionPrompt($data),
            'data_inference' => $this->buildDataInferencePrompt($data),
            'data_validation' => $this->buildDataValidationPrompt($data),
            'workflow_recommendations' => $this->buildWorkflowRecommendationsPrompt($data),
            'workflow_generation' => $this->buildWorkflowGenerationPrompt($data),
            'ai_coach_recommendations' => $data['text'] ?? $data['prompt'] ?? '',
            'email_assistant_question' => $this->buildEmailAssistantQuestionPrompt($data),
            'email_assistant_intent' => $this->buildEmailAssistantIntentPrompt($data),
            'email_assistant_parse_task' => $this->buildEmailAssistantParseTaskPrompt($data),
            'email_assistant_parse_instructions' => $this->buildEmailAssistantParseInstructionsPrompt($data),
            'email_assistant_parse_contact' => $this->buildEmailAssistantParseContactPrompt($data),
            'email_assistant_parse_contact_update' => $this->buildEmailAssistantParseContactUpdatePrompt($data),
            'email_assistant_parse_note' => $this->buildEmailAssistantParseNotePrompt($data),
            'email_assistant_parse_event' => $this->buildEmailAssistantParseEventPrompt($data),
            'email_assistant_entity_resolution' => $this->buildEmailAssistantEntityResolutionPrompt($data),
            'email_assistant_customer_reply_goal' => $this->buildEmailAssistantCustomerReplyGoalPrompt($data),
            'email_assistant_commercial_reply' => $this->buildEmailAssistantCommercialReplyPrompt($data),
            'email_assistant_change_explanation' => $this->buildEmailAssistantChangeExplanationPrompt($data),
            'email_assistant_ambiguity_summary' => $this->buildEmailAssistantAmbiguitySummaryPrompt($data),
            'ai_context_diagnostics' => $this->buildAIContextDiagnosticsPrompt($data),
            'ai_goal_relevance_score' => $this->buildAIGoalRelevancePrompt($data),
            'ai_mode_qualification' => $this->buildAIModeQualificationPrompt($data),
            'ai_task_completion_match' => $this->buildAITaskCompletionMatchPrompt($data),
            'website_assistant_question' => $this->buildWebsiteAssistantPrompt($data),
            'meeting_prep_summary' => $this->buildMeetingPrepPrompt($data),
            'meeting_note_analysis' => $this->buildMeetingNoteAnalysisPrompt($data),
            'thread_summary' => $this->buildThreadSummaryPrompt($data),
            'deal_next_steps' => $this->buildDealNextStepsPrompt($data),
            'deal_summary' => $this->buildDealSummaryPrompt($data),
            'deal_close_date' => $this->buildDealCloseDatePrompt($data),
            'deal_stage_suggestion' => $this->buildDealStageSuggestionPrompt($data),
            'action_item_extraction' => $this->buildActionItemExtractionPrompt($data),
            'form_submission_analysis' => $this->buildFormSubmissionAnalysisPrompt($data),
            'search_query_parse' => $this->buildSearchQueryParsePrompt($data),
            'document_extraction' => $this->buildDocumentExtractionPrompt($data),
            'nl_report' => $this->buildNLReportPrompt($data),
            default => $data['text'] ?? $data['prompt'] ?? ''
        };
        
        $systemPrompt = $systemPrompt !== ''
            ? $systemPrompt
            : 'You are Clarity, the AI co-founder for structured growth. Follow the requested output contract exactly.';

        $isOpenAI = ($providerConfig['provider_type'] ?? '') === 'openai_compatible';
        $isOfficialOpenAI = $isOpenAI && str_contains(strtolower($apiUrl), 'api.openai.com');
        $model = trim((string) ($providerConfig['model'] ?? ($_ENV['AI_MODEL'] ?? 'gpt-3.5-turbo')));
        if ($isOfficialOpenAI && $task === 'website_assistant_question' && trim((string) ($_ENV['AI_CLARITY_MODEL'] ?? '')) !== '') {
            $model = trim((string) $_ENV['AI_CLARITY_MODEL']);
        }
        $reasoningEffort = $this->normalizeReasoningEffort((string) ($context['reasoning_effort'] ?? 'low'));
        $responsesEnabled = strtolower(trim((string) ($_ENV['AI_RESPONSES_ENABLED'] ?? $_ENV['AI_CLARITY_RESPONSES_ENABLED'] ?? 'true'))) !== 'false';
        $useResponses = $isOfficialOpenAI
            && ($task === 'website_assistant_question' || $credentialScope === WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION)
            && $responsesEnabled;
        $requestUrl = $useResponses ? $this->responsesApiUrl($apiUrl) : $apiUrl;
        $apiSurface = $useResponses ? 'responses' : 'chat_completions';
        $context['task'] = $task;
        $context['output_contract'] = $data['_resolved_prompt']['output_contract'] ?? null;

        if ($useResponses) {
            $payload = [
                'model' => $model,
                'instructions' => $systemPrompt,
                'input' => $prompt,
                'max_output_tokens' => $this->responsesMaxOutputTokens($reasoningEffort, (int) ($context['max_tokens'] ?? 1600)),
            ];
            if ($this->supportsReasoningEffort($model)) {
                $payload['reasoning'] = ['effort' => $reasoningEffort];
            }
            $structuredFormat = $this->structuredOutputFormat($data['_resolved_prompt']['output_contract'] ?? null, $task);
            if ($structuredFormat !== []) {
                $payload['text'] = ['format' => $structuredFormat];
            }
        } else {
            $payload = $this->chatCompletionsPayload($model, $systemPrompt, $prompt, $context);
        }
        $providerName = (string) (($providerConfig['provider_type'] ?? '') === 'openai_compatible' ? 'openai' : 'remote_ai');
        $reservation = $this->tokenRateLimiter->beginRequest(
            $providerName,
            (string) ($payload['model'] ?? ''),
            $task,
            $prompt,
            !empty($context['user_id']) ? (int) $context['user_id'] : null,
            [
                'provider_source' => (string) ($resolvedProvider['source'] ?? WorkspaceAIProviderResolverService::SOURCE_ENV),
                'provider_config_workspace_id' => !empty($resolvedProvider['config_workspace_id']) ? (int) $resolvedProvider['config_workspace_id'] : null,
                'workspace_id' => $workspaceId,
                'credential_scope' => $credentialScope,
            ]
        );
        
        $remoteResponse = $this->executeRemoteRequest($requestUrl, $apiKey, $payload, $context);
        $response = $remoteResponse['response'];
        $httpCode = $remoteResponse['http_code'];
        $curlError = $remoteResponse['curl_error'];

        // A workspace may point at an older OpenAI-compatible gateway. If the
        // Responses endpoint is unsupported, retry once using Chat Completions.
        if ($useResponses && $curlError === '' && in_array($httpCode, [400, 404, 405, 422], true)) {
            $apiSurface = 'chat_completions_fallback';
            $requestUrl = $apiUrl;
            $payload = $this->chatCompletionsPayload($model, $systemPrompt, $prompt, $context);
            $remoteResponse = $this->executeRemoteRequest($requestUrl, $apiKey, $payload, $context);
            $response = $remoteResponse['response'];
            $httpCode = $remoteResponse['http_code'];
            $curlError = $remoteResponse['curl_error'];
        }
        
        if ($httpCode !== 200 || !empty($curlError)) {
            $this->tokenRateLimiter->failRequest($reservation);
            // Log error for debugging
            error_log("AI API Error: HTTP $httpCode - $curlError - Response: " . substr($response, 0, 200));
            $this->setProviderStatus([
                'provider' => 'remote',
                'mode' => 'remote_then_fallback',
                'fallback_used' => true,
                'success' => false,
                'http_code' => (int) $httpCode,
                'message' => $curlError !== '' ? $curlError : 'Remote AI request failed with HTTP ' . $httpCode,
                'url' => $requestUrl,
                'provider_source' => (string) ($resolvedProvider['source'] ?? WorkspaceAIProviderResolverService::SOURCE_ENV),
                'credential_scope' => $credentialScope,
                'workspace_id' => $workspaceId,
                'user_id' => (int) ($context['user_id'] ?? 0),
                'surface' => (string) ($context['surface'] ?? 'global'),
                'model' => (string) ($payload['model'] ?? ''),
                'api_surface' => $apiSurface,
                'reasoning_effort' => $reasoningEffort,
                'source' => 'remote_provider',
            ]);
            return '';
        }
        
        $result = json_decode($response, true);
        if (!is_array($result)) {
            $this->tokenRateLimiter->failRequest($reservation);
            $this->setProviderStatus([
                'provider' => 'remote',
                'mode' => 'remote_invalid_response',
                'fallback_used' => true,
                'success' => false,
                'http_code' => (int) $httpCode,
                'message' => 'Remote AI returned invalid JSON.',
                'url' => $requestUrl,
                'provider_source' => (string) ($resolvedProvider['source'] ?? WorkspaceAIProviderResolverService::SOURCE_ENV),
                'credential_scope' => $credentialScope,
                'workspace_id' => $workspaceId,
                'user_id' => (int) ($context['user_id'] ?? 0),
                'surface' => (string) ($context['surface'] ?? 'global'),
                'model' => (string) ($payload['model'] ?? ''),
                'api_surface' => $apiSurface,
                'reasoning_effort' => $reasoningEffort,
                'source' => 'remote_provider',
                'blocked_reason' => 'invalid_provider_response',
            ]);
            return '';
        }
        $output = $apiSurface === 'responses'
            ? $this->responsesOutputText($result)
            : (string) ($result['choices'][0]['message']['content'] ?? $result['response'] ?? '');
        if (trim($output) === '') {
            $this->tokenRateLimiter->failRequest($reservation);
            $this->setProviderStatus([
                'provider' => 'remote',
                'mode' => 'remote_empty_response',
                'fallback_used' => true,
                'success' => false,
                'http_code' => (int) $httpCode,
                'message' => 'Remote AI returned an empty response.',
                'url' => $requestUrl,
                'provider_source' => (string) ($resolvedProvider['source'] ?? WorkspaceAIProviderResolverService::SOURCE_ENV),
                'credential_scope' => $credentialScope,
                'workspace_id' => $workspaceId,
                'user_id' => (int) ($context['user_id'] ?? 0),
                'surface' => (string) ($context['surface'] ?? 'global'),
                'model' => (string) ($payload['model'] ?? ''),
                'api_surface' => $apiSurface,
                'reasoning_effort' => $reasoningEffort,
                'source' => 'remote_provider',
                'blocked_reason' => 'empty_provider_response',
            ]);
            return '';
        }
        $usageTokens = (int) ($result['usage']['total_tokens'] ?? 0);
        if ($usageTokens <= 0) {
            $usageTokens = $this->tokenRateLimiter->estimateTokens($prompt, $output);
        }
        $this->tokenRateLimiter->completeRequest($reservation, $output, 0, $usageTokens);
        $this->setProviderStatus([
            'provider' => 'remote',
            'mode' => 'remote',
            'fallback_used' => false,
            'success' => true,
            'http_code' => (int) $httpCode,
            'message' => 'Remote AI request succeeded.',
            'url' => $requestUrl,
            'provider_source' => (string) ($resolvedProvider['source'] ?? WorkspaceAIProviderResolverService::SOURCE_ENV),
            'provider_config_workspace_id' => !empty($resolvedProvider['config_workspace_id']) ? (int) $resolvedProvider['config_workspace_id'] : null,
            'credential_scope' => $credentialScope,
            'workspace_id' => $workspaceId,
            'user_id' => (int) ($context['user_id'] ?? 0),
            'surface' => (string) ($context['surface'] ?? 'global'),
            'model' => (string) ($payload['model'] ?? ''),
            'api_surface' => $apiSurface,
            'reasoning_effort' => $reasoningEffort,
            'source' => 'remote_provider',
            'contract_valid' => null,
        ]);
        
        return $output;
    }

    private function responsesApiUrl(string $apiUrl): string
    {
        $apiUrl = rtrim($apiUrl, '/');
        if (str_ends_with($apiUrl, '/chat/completions')) {
            return substr($apiUrl, 0, -strlen('/chat/completions')) . '/responses';
        }
        return str_ends_with($apiUrl, '/responses') ? $apiUrl : $apiUrl . '/responses';
    }

    private function normalizeReasoningEffort(string $effort): string
    {
        $effort = strtolower(trim($effort));
        return in_array($effort, ['minimal', 'low', 'medium', 'high'], true) ? $effort : 'low';
    }

    private function supportsReasoningEffort(string $model): bool
    {
        $model = strtolower(trim($model));
        return preg_match('/^(gpt-5|o[1-9])/', $model) === 1;
    }

    private function responsesMaxOutputTokens(string $reasoningEffort, int $requested): int
    {
        $minimum = match ($this->normalizeReasoningEffort($reasoningEffort)) {
            'high' => 900,
            'medium' => 600,
            default => 256,
        };
        return max($minimum, min(4000, $requested));
    }

    /** @return array<string,mixed> */
    private function chatCompletionsPayload(string $model, string $systemPrompt, string $prompt, array $context): array
    {
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
        if ($this->supportsReasoningEffort($model)) {
            $payload['max_completion_tokens'] = (int) ($context['max_tokens'] ?? 1600);
        } else {
            $payload['temperature'] = (float) ($context['temperature'] ?? 0.4);
            $payload['max_tokens'] = (int) ($context['max_tokens'] ?? 1600);
        }
        $structuredFormat = $this->structuredOutputFormat($context['output_contract'] ?? null, (string) ($context['task'] ?? 'structured_output'));
        if ($structuredFormat !== []) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => (string) $structuredFormat['name'],
                    'strict' => true,
                    'schema' => (array) $structuredFormat['schema'],
                ],
            ];
        }
        return $payload;
    }

    /** @return array<string,mixed> */
    private function structuredOutputFormat(mixed $contract, string $task): array
    {
        if (!is_array($contract)) {
            return [];
        }

        $schema = is_array($contract['schema'] ?? null) ? (array) $contract['schema'] : [];
        if ($schema === []) {
            return [];
        }

        $name = strtolower(trim((string) ($contract['name'] ?? $task)));
        $name = preg_replace('/[^a-z0-9_]+/', '_', $name) ?? '';
        $name = trim($name, '_');
        if ($name === '') {
            $name = 'structured_output';
        }

        return [
            'type' => 'json_schema',
            'name' => substr($name, 0, 64),
            'strict' => true,
            'schema' => $schema,
        ];
    }

    /** @return array{response:string,http_code:int,curl_error:string} */
    private function executeRemoteRequest(string $url, string $apiKey, array $payload, array $context): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => !empty($context['fast_fallback']) ? 2 : 10,
            CURLOPT_TIMEOUT => !empty($context['fast_fallback']) ? 5 : 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = (string) curl_error($ch);
        curl_close($ch);

        return [
            'response' => is_string($response) ? $response : '',
            'http_code' => $httpCode,
            'curl_error' => $curlError,
        ];
    }

    private function responsesOutputText(array $result): string
    {
        $direct = trim((string) ($result['output_text'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }

        $parts = [];
        foreach ((array) ($result['output'] ?? []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (!in_array((string) ($content['type'] ?? ''), ['output_text', 'text'], true)) {
                    continue;
                }
                $text = trim((string) ($content['text'] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }
        return implode("\n", $parts);
    }

    /**
     * @return array{surface:string,provider_order:string,temperature:float,max_tokens:int,output_type:string,reasoning_effort:string}
     */
    private function taskProfile(string $task, array $context = []): array
    {
        $tier = (string) ($this->modelMap[$task] ?? 'tier2');
        $surface = trim((string) ($context['surface'] ?? ''));
        if ($surface === '') {
            $surface = match (true) {
                $task === 'website_assistant_question' => 'clarity_chat',
                $task === 'ai_coach_recommendations' => 'coach',
                str_starts_with($task, 'workflow_') => 'workflow',
                str_starts_with($task, 'email_assistant_customer_'),
                in_array($task, ['email_auto_reply', 'whatsapp_auto_reply', 'sms_auto_reply', 'thread_summary'], true) => 'customer_thread',
                str_starts_with($task, 'email_assistant_commercial_') => 'commercial_assistant',
                str_starts_with($task, 'email_assistant_'),
                in_array($task, ['email_draft', 'whatsapp_draft', 'tone_adjustment', 'personalization'], true) => 'assistant',
                in_array($task, ['action_item_extraction', 'ai_task_completion_match'], true) => 'task_automation',
                default => 'global',
            };
        }

        $jsonTasks = [
            'data_extraction', 'data_inference', 'data_validation', 'workflow_generation',
            'ai_coach_recommendations', 'email_assistant_intent', 'email_assistant_parse_task',
            'email_assistant_parse_instructions', 'email_assistant_parse_contact',
            'email_assistant_parse_contact_update', 'email_assistant_parse_note',
            'email_assistant_parse_event', 'email_assistant_entity_resolution',
            'email_assistant_customer_reply_goal', 'ai_goal_relevance_score',
            'ai_mode_qualification', 'ai_task_completion_match', 'meeting_note_analysis',
            'deal_next_steps', 'deal_close_date', 'deal_stage_suggestion',
            'action_item_extraction', 'form_submission_analysis', 'search_query_parse',
            'document_extraction', 'nl_report',
        ];
        $draftTasks = [
            'email_draft', 'whatsapp_draft', 'email_auto_reply', 'whatsapp_auto_reply',
            'sms_auto_reply', 'tone_adjustment', 'personalization',
            'email_assistant_commercial_reply', 'website_assistant_question',
        ];
        $outputType = in_array($task, $jsonTasks, true) ? 'json' : 'text';
        $temperature = $outputType === 'json' ? 0.15 : (in_array($task, $draftTasks, true) ? 0.55 : 0.35);
        $maxTokens = $outputType === 'json' ? 1400 : (in_array($task, $draftTasks, true) ? 1800 : 1200);

        return [
            'surface' => $surface,
            'provider_order' => $tier === 'tier1' ? 'local_first' : 'remote_first',
            'temperature' => (float) ($context['temperature'] ?? $temperature),
            'max_tokens' => max(128, min(4000, (int) ($context['max_tokens'] ?? $maxTokens))),
            'output_type' => (string) ($context['output_type'] ?? $outputType),
            'reasoning_effort' => (string) ($context['reasoning_effort'] ?? ($task === 'website_assistant_question' ? 'low' : 'medium')),
        ];
    }

    /** @return array<int,string> */
    private function providerOrder(string $order): array
    {
        return $order === 'local_first' ? ['local', 'remote'] : ['remote', 'local'];
    }

    private function resolveWorkspaceId(array $context): int
    {
        $current = 0;
        try {
            $current = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        } catch (\Throwable $e) {
            $current = 0;
        }

        if ($current > 0) {
            return $current;
        }

        return max(0, (int) ($context['workspace_id'] ?? 0));
    }

    private function resolveUserId(array $context): int
    {
        $current = (int) (Auth::userId() ?? 0);
        return $current > 0 ? $current : max(0, (int) ($context['user_id'] ?? 0));
    }

    private function buildCacheKey(string $task, array $data, array $context, int $workspaceId, int $userId): ?string
    {
        if ($workspaceId <= 0 || $userId <= 0) {
            return null;
        }

        $payload = json_encode([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'task' => $task,
            'data' => $data,
            'surface' => (string) ($context['surface'] ?? ''),
            'reasoning_effort' => (string) ($context['reasoning_effort'] ?? ''),
            'credential_scope' => (string) ($context['credential_scope'] ?? WorkspaceAIProviderConfigService::SCOPE_GENERAL),
            'clarity_model' => $task === 'website_assistant_question' ? (string) ($_ENV['AI_CLARITY_MODEL'] ?? '') : '',
            'fast_fallback' => !empty($context['fast_fallback']),
            'fallback_policy' => (string) ($context['fallback_policy'] ?? ''),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($payload)) {
            return null;
        }

        return 'ai_v2_w' . $workspaceId . '_u' . $userId . '_' . hash('sha256', $payload);
    }

    private function runtimeAllowsExecution(string $surface, int $workspaceId): bool
    {
        if ($workspaceId <= 0) {
            return true;
        }

        try {
            $control = (new AIRuntimeControlService())->getEffectiveControl($surface, $workspaceId);
        } catch (\Throwable $e) {
            return true;
        }

        $mode = (string) ($control['control_mode'] ?? 'normal');
        if (!in_array($mode, ['paused', 'diagnostics_only'], true)) {
            return true;
        }

        $this->setProviderStatus([
            'provider' => 'none',
            'mode' => $mode,
            'fallback_used' => true,
            'success' => false,
            'workspace_id' => $workspaceId,
            'surface' => $surface,
            'source' => 'runtime_control',
            'blocked_reason' => 'runtime_' . $mode,
            'message' => trim((string) ($control['reason'] ?? '')) ?: 'AI execution is constrained by the workspace runtime control.',
        ]);
        return false;
    }

    /** @return array{valid:bool,output:string,message:string} */
    private function validateOutputContract(string $output, mixed $contract): array
    {
        $trimmed = trim($output);
        if ($trimmed === '') {
            return ['valid' => false, 'output' => '', 'message' => 'AI returned an empty response.'];
        }
        if (!is_array($contract) || $contract === []) {
            return ['valid' => true, 'output' => $output, 'message' => ''];
        }

        $type = strtolower(trim((string) ($contract['type'] ?? 'text')));
        if (in_array($type, ['text', 'legacy_inline'], true)) {
            $normalized = $this->textResponseNormalizer->normalize($output);
            if ($normalized === '') {
                return ['valid' => false, 'output' => '', 'message' => 'AI returned a metadata-only or empty text response.'];
            }
            return ['valid' => true, 'output' => $normalized, 'message' => ''];
        }
        if (!in_array($type, ['json', 'object', 'json_object', 'json_schema'], true)) {
            return ['valid' => true, 'output' => $output, 'message' => ''];
        }

        $json = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed) ?? $trimmed;
        $decoded = json_decode(trim($json), true);
        if (!is_array($decoded)) {
            return ['valid' => false, 'output' => '', 'message' => 'AI output was not valid JSON.'];
        }

        $required = array_values(array_unique(array_merge(
            (array) ($contract['required'] ?? []),
            (array) ($contract['sections'] ?? []),
            (array) ($contract['schema']['required'] ?? [])
        )));
        foreach ($required as $key) {
            $key = trim((string) $key);
            if ($key !== '' && !array_key_exists($key, $decoded)) {
                return ['valid' => false, 'output' => '', 'message' => 'AI output was missing required field: ' . $key . '.'];
            }
        }

        foreach ((array) ($contract['schema']['properties'] ?? []) as $key => $property) {
            if (!array_key_exists($key, $decoded) || !is_array($property)) {
                continue;
            }
            $expectedType = (string) ($property['type'] ?? '');
            $value = $decoded[$key];
            $typeMatches = match ($expectedType) {
                'string' => is_string($value),
                'array', 'object' => is_array($value),
                'number' => is_int($value) || is_float($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                default => true,
            };
            if (!$typeMatches) {
                return ['valid' => false, 'output' => '', 'message' => 'AI output field ' . $key . ' had the wrong type.'];
            }
        }

        $normalized = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return [
            'valid' => is_string($normalized),
            'output' => is_string($normalized) ? $normalized : $output,
            'message' => is_string($normalized) ? '' : 'AI output could not be normalized.',
        ];
    }

    private function sanitizePromptInputs(array $inputs): array
    {
        foreach ([
            'legacy_prompt', 'context', 'context_bundle', 'operating_context', 'website_context',
            'clarity_page_context', 'user_work_context', 'response_style_contract',
        ] as $duplicateKey) {
            unset($inputs[$duplicateKey]);
        }

        return $inputs;
    }

    private function limitPromptChars(string $prompt, int $maxChars): string
    {
        $maxChars = max(500, min(30000, $maxChars));
        if (strlen($prompt) <= $maxChars) {
            return $prompt;
        }

        return rtrim(substr($prompt, 0, max(1, $maxChars - 28))) . "\n\n[Prompt content truncated]";
    }

    private function limitPromptCharsPreservingSuffix(string $prompt, string $suffix, int $maxChars): string
    {
        $maxChars = max(500, min(30000, $maxChars));
        if (strlen($prompt) <= $maxChars) {
            return $prompt;
        }

        $marker = "\n\n[Prompt content truncated]\n\n";
        $reserved = strlen($marker) + strlen($suffix);
        $prefixChars = max(1, $maxChars - $reserved);

        return rtrim(substr($prompt, 0, $prefixChars)) . $marker . $suffix;
    }

    private function setProviderStatus(array $status): void
    {
        $this->lastProviderStatus = array_merge([
            'provider' => 'unknown',
            'mode' => 'unknown',
            'fallback_used' => false,
            'success' => false,
            'cache_hit' => false,
            'http_code' => 0,
            'message' => '',
            'url' => '',
            'workspace_id' => 0,
            'user_id' => 0,
            'surface' => 'global',
            'model' => '',
            'source' => '',
            'blocked_reason' => '',
            'contract_valid' => null,
            'attempted_providers' => [],
            'prompt_truncated' => false,
        ], $status);
    }

    private function shouldUseImmediateFastFallback(string $task, array $context): bool
    {
        if (!$this->supportsFastFallback($task) || empty($context['fast_fallback'])) {
            return false;
        }

        if (!$this->isLocalProviderExplicitlyEnabled()) {
            $this->setProviderStatus([
                'provider' => 'ollama',
                'mode' => 'fast_fallback',
                'fallback_used' => true,
                'success' => false,
                'message' => 'Skipped local AI probe in fast-fallback mode because AI_LOCAL_ENABLED is not true.',
                'url' => (string) ($_ENV['OLLAMA_URL'] ?? 'http://localhost:11434'),
            ]);
            return true;
        }

        if ($this->hasActiveLocalProviderCooldown()) {
            $this->setProviderStatus([
                'provider' => 'ollama',
                'mode' => 'fast_fallback_cooldown',
                'fallback_used' => true,
                'success' => false,
                'message' => self::$localProviderCooldownMessage !== ''
                    ? self::$localProviderCooldownMessage
                    : 'Skipped local AI probe in fast-fallback mode due to recent provider failure.',
                'url' => (string) ($_ENV['OLLAMA_URL'] ?? 'http://localhost:11434'),
            ]);
            return true;
        }

        return false;
    }

    private function returnFastFallbackResult(string $url): string
    {
        $this->setProviderStatus([
            'provider' => 'ollama',
            'mode' => $this->lastProviderStatus['mode'] ?? 'fast_fallback',
            'fallback_used' => true,
            'success' => false,
            'message' => $this->lastProviderStatus['message'] ?? 'Fast fallback used.',
            'url' => $url,
        ]);

        return '';
    }

    private function supportsFastFallback(string $task): bool
    {
        return in_array($task, ['sentiment', 'intent_detection', 'marketplace_catalog_copy', 'ai_coach_recommendations', 'startup_journey_report'], true);
    }

    private function supportsCacheOnlyFallback(string $task): bool
    {
        return in_array($task, ['ai_coach_recommendations'], true);
    }

    private function isLocalProviderExplicitlyEnabled(): bool
    {
        $value = strtolower(trim((string) ($_ENV['AI_LOCAL_ENABLED'] ?? $_ENV['OLLAMA_ENABLED'] ?? 'false')));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function hasActiveLocalProviderCooldown(): bool
    {
        return self::$localProviderCooldownUntil > time();
    }

    private function markLocalProviderFailure(string $message): void
    {
        self::$localProviderCooldownUntil = time() + self::LOCAL_PROVIDER_COOLDOWN_SECONDS;
        self::$localProviderCooldownMessage = 'Skipped local AI probe in fast-fallback mode due to recent provider failure: ' . $message;
    }

    private function clearLocalProviderFailure(): void
    {
        self::$localProviderCooldownUntil = 0;
        self::$localProviderCooldownMessage = '';
    }

    public function getTokenUsageSummary(): array
    {
        return $this->tokenRateLimiter->getUsageSummary();
    }
    
    /**
     * Build email draft prompt
     */
    private function buildEmailDraftPrompt(array $data): string
    {
        $context = $data['context'] ?? [];
        $purpose = $data['purpose'] ?? 'general';
        $tone = $data['tone'] ?? 'professional';
        $options = $data['options'] ?? [];
        
        // Extract options with sensible defaults
        $length = $options['length'] ?? 'medium'; // short, medium, long
        $style = $options['style'] ?? 'paragraph'; // paragraph, bullet_points, numbered
        $includePoints = $options['include_points'] ?? [];
        $callToAction = $options['call_to_action'] ?? null;
        $customInstructions = $options['custom_instructions'] ?? '';
        $emailFormat = $options['format'] ?? 'html'; // html, plain_text, both
        $intention = trim((string) ($options['intention'] ?? $data['intention'] ?? ''));
        $currentBody = trim((string) ($options['current_body'] ?? ''));
        $draftMode = trim((string) ($options['mode'] ?? ''));
        
        // Build comprehensive prompt
        $prompt = "You are an expert email writer. Generate a {$tone} email draft.\n\n";
        
        // Purpose and context
        $prompt .= "PURPOSE: {$purpose}\n";
        $prompt .= "TONE: {$tone}\n";
        $prompt .= "LENGTH: {$length}\n";
        $prompt .= "STYLE: {$style}\n";
        if ($draftMode !== '') {
            $prompt .= "DRAFT MODE: {$draftMode}\n";
        }
        if ($intention !== '') {
            $prompt .= "\nUSER INTENTION:\n{$intention}\n";
        }
        if ($currentBody !== '') {
            $prompt .= "\nEXISTING DRAFT TO IMPROVE:\n{$currentBody}\n";
        }
        
        if (!empty($customInstructions)) {
            $prompt .= "\nCUSTOM INSTRUCTIONS:\n{$customInstructions}\n";
        }
        
        // Our Company Profile
        if (!empty($context['our_company'])) {
            $company = $context['our_company'];
            $prompt .= "\nOUR COMPANY PROFILE:\n";
            $prompt .= "- Company Name: {$company['name']}\n";
            if (!empty($company['tagline'])) {
                $prompt .= "- Tagline: {$company['tagline']}\n";
            }
            if (!empty($company['description'])) {
                $prompt .= "- Description: {$company['description']}\n";
            }
            if (!empty($company['mission'])) {
                $prompt .= "- Mission: {$company['mission']}\n";
            }
            if (!empty($company['values'])) {
                $prompt .= "- Values: {$company['values']}\n";
            }
            if (!empty($company['industry'])) {
                $prompt .= "- Industry: {$company['industry']}\n";
            }
            if (!empty($company['website'])) {
                $prompt .= "- Website: {$company['website']}\n";
            }
            if (!empty($company['location'])) {
                $prompt .= "- Location: {$company['location']}\n";
            }
            if (!empty($company['timezone'])) {
                $prompt .= "- Timezone: {$company['timezone']}\n";
            }
        }
        
        // Our Products/Services
        if (!empty($context['our_products']) && is_array($context['our_products'])) {
            $prompt .= "\nOUR PRODUCTS/SERVICES:\n";
            foreach ($context['our_products'] as $index => $product) {
                $prompt .= ($index + 1) . ". {$product['name']}";
                if (!empty($product['category'])) {
                    $prompt .= " ({$product['category']})";
                }
                $prompt .= "\n";
                if (!empty($product['description'])) {
                    $prompt .= "   Description: {$product['description']}\n";
                }
                if (!empty($product['features']) && is_array($product['features'])) {
                    $prompt .= "   Features: " . implode(', ', $product['features']) . "\n";
                }
                if (!empty($product['benefits'])) {
                    $prompt .= "   Benefits: {$product['benefits']}\n";
                }
                if (!empty($product['target_audience'])) {
                    $prompt .= "   Target Audience: {$product['target_audience']}\n";
                }
                if (!empty($product['use_cases'])) {
                    $prompt .= "   Use Cases: {$product['use_cases']}\n";
                }
            }
        }
        
        // Contact information
        if (!empty($context['contact'])) {
            $contact = $context['contact'];
            $prompt .= "\nCONTACT INFORMATION:\n";
            $prompt .= "- Name: {$contact['name']}\n";
            $prompt .= "- Email: {$contact['email']}\n";
            if (!empty($contact['phone'])) {
                $prompt .= "- Phone: {$contact['phone']}\n";
            }
            if (!empty($contact['job_title'])) {
                $prompt .= "- Job Title: {$contact['job_title']}\n";
            }
            if (!empty($contact['company'])) {
                $prompt .= "- Company: {$contact['company']}\n";
            }
            if (!empty($contact['location'])) {
                $prompt .= "- Location: {$contact['location']}\n";
            }
            if (!empty($contact['timezone'])) {
                $prompt .= "- Timezone: {$contact['timezone']}\n";
            }
            if (!empty($contact['stage'])) {
                $prompt .= "- Stage: {$contact['stage']}\n";
            }
            if (!empty($contact['lead_score'])) {
                $prompt .= "- Composite Lead Score: {$contact['lead_score']}\n";
            }
            if (!empty($contact['ml_score'])) {
                $prompt .= "- ML Score: {$contact['ml_score']}\n";
            }
        }
        
        // Contact's Business Profile
        if (!empty($context['business_profile'])) {
            $business = $context['business_profile'];
            $prompt .= "\nCONTACT'S BUSINESS PROFILE:\n";
            if (!empty($business['company_industry'])) {
                $prompt .= "- Industry: {$business['company_industry']}\n";
            }
            if (!empty($business['company_size'])) {
                $prompt .= "- Company Size: {$business['company_size']}\n";
            }
            if (!empty($business['company_revenue'])) {
                $prompt .= "- Revenue: {$business['company_revenue']}\n";
            }
            if (!empty($business['company_description'])) {
                $prompt .= "- Description: {$business['company_description']}\n";
            }
            if (!empty($business['company_founded'])) {
                $prompt .= "- Founded: {$business['company_founded']}\n";
            }
            if (!empty($business['company_website'])) {
                $prompt .= "- Website: {$business['company_website']}\n";
            }
        }
        
        // Location & Timezone Context
        if (!empty($context['contact']['timezone']) || !empty($context['our_company']['timezone'])) {
            $prompt .= "\nLOCATION & TIMEZONE CONTEXT:\n";
            if (!empty($context['contact']['timezone'])) {
                $prompt .= "- Contact Timezone: {$context['contact']['timezone']}\n";
            }
            if (!empty($context['our_company']['timezone'])) {
                $prompt .= "- Our Timezone: {$context['our_company']['timezone']}\n";
            }
            if (!empty($context['contact']['location'])) {
                $prompt .= "- Contact Location: {$context['contact']['location']}\n";
            }
        }
        
        // Relationship Context
        if (!empty($context['relationship_context'])) {
            $rel = $context['relationship_context'];
            $prompt .= "\nRELATIONSHIP CONTEXT:\n";
            if (!empty($rel['tags']) && is_array($rel['tags'])) {
                $prompt .= "- Tags: " . implode(', ', $rel['tags']) . "\n";
            }
            if (!empty($rel['deals']) && is_array($rel['deals'])) {
                $prompt .= "- Active Deals:\n";
                foreach ($rel['deals'] as $deal) {
                    $prompt .= "  * {$deal['name']} ({$deal['stage']}, Value: {$deal['value']})\n";
                }
            }
            if (!empty($rel['notes']) && is_array($rel['notes'])) {
                $prompt .= "- Recent Notes:\n";
                foreach (array_slice($rel['notes'], 0, 3) as $note) {
                    if (!empty($note['title'])) {
                        $prompt .= "  * {$note['title']}: {$note['content']}\n";
                    }
                }
            }
        }
        
        // Specific points to include
        if (!empty($includePoints) && is_array($includePoints)) {
            $prompt .= "\nMUST INCLUDE THESE POINTS:\n";
            foreach ($includePoints as $index => $point) {
                if (!empty(trim($point))) {
                    $prompt .= ($index + 1) . ". " . trim($point) . "\n";
                }
            }
        }
        
        // Call to action
        if (!empty($callToAction)) {
            $prompt .= "\nCALL TO ACTION: {$callToAction}\n";
        }
        
        // Recent activities
        if (!empty($context['recent_activities'])) {
            $prompt .= "\nRECENT ACTIVITIES:\n";
            foreach ($context['recent_activities'] as $activity) {
                $activityDate = $activity['date'] ?? '';
                $prompt .= "- {$activity['type']}: {$activity['description']}";
                if ($activityDate) {
                    $prompt .= " ({$activityDate})";
                }
                $prompt .= "\n";
            }
        }
        
        // Communication history
        if (!empty($context['communication_history'])) {
            $prompt .= "\nPREVIOUS COMMUNICATION:\n";
            foreach ($context['communication_history'] as $email) {
                $emailDate = $email['date'] ?? '';
                $prompt .= "- Subject: {$email['subject']}";
                if ($emailDate) {
                    $prompt .= " ({$emailDate})";
                }
                $prompt .= "\n";
            }
        }
        
        // AI Context Insights (if available)
        if (!empty($context['ai_insights']) && is_array($context['ai_insights'])) {
            $prompt .= "\nAI-GENERATED INSIGHTS (use to inform personalization and messaging strategy):\n";
            if (!empty($context['ai_summary'])) {
                $prompt .= "- Summary: {$context['ai_summary']}\n";
            }
            $prompt .= "- Key Insights:\n";
            foreach (array_slice($context['ai_insights'], 0, 5) as $insight) {
                $insightType = $insight['type'] ?? 'insight';
                $conclusion = $insight['conclusion'] ?? '';
                $confidence = isset($insight['confidence']) ? ' (confidence: ' . round($insight['confidence'] * 100) . '%)' : '';
                $prompt .= "  * {$insightType}: {$conclusion}{$confidence}\n";
            }
            if (!empty($context['ai_recommendations']) && is_array($context['ai_recommendations'])) {
                $prompt .= "- Recommendations:\n";
                foreach (array_slice($context['ai_recommendations'], 0, 3) as $rec) {
                    $prompt .= "  * {$rec}\n";
                }
            }
            if (!empty($context['ai_analysis'])) {
                $analysis = $context['ai_analysis'];
                if (!empty($analysis['professional_profile']['role_level'])) {
                    $prompt .= "- Professional Role Level: {$analysis['professional_profile']['role_level']}\n";
                }
                if (!empty($analysis['data_quality'])) {
                    $prompt .= "- Data Quality: {$analysis['data_quality']}\n";
                }
            }
        }
        
        // Key Instructions for using company info and products
        $prompt .= "\nKEY INSTRUCTIONS:\n";
        $prompt .= "- Treat OUR COMPANY INFORMATION as the sender identity and source of truth for what we offer\n";
        $prompt .= "- Treat CONTACT INFORMATION and business_profile as the recipient context only\n";
        $prompt .= "- Never describe the recipient as joining or being welcomed to their own company\n";
        if ($intention !== '' || $currentBody !== '') {
            $prompt .= "- This is a one-off assisted draft, not a reusable template. Do not output template instructions, template labels, or generic template copy\n";
            $prompt .= "- If an existing draft is provided, preserve the user's meaning while improving structure, spelling, clarity, and professional flow\n";
            $prompt .= "- If only an intention is provided, turn it into a clear email with a natural opening, concise body, and one next step\n";
        }
        if ($purpose === 'welcome') {
            $prompt .= "- This is a welcome email to our service, onboarding flow, or working relationship. Welcome them to us, not to their own business\n";
            $prompt .= "- You may mention the recipient's company only as background context for why this welcome matters to them\n";
        }
        $prompt .= "- Use our company name, mission, and values naturally in the email\n";
        $prompt .= "- Reference relevant products/services that match the contact's industry, role, or needs\n";
        $prompt .= "- Match the tone to our company values and the contact's professional context\n";
        $prompt .= "- Consider timezone differences when suggesting meeting times or deadlines\n";
        $prompt .= "- Use relationship context (tags, deals, notes) to personalize the message\n";
        $prompt .= "- Leverage AI insights to inform tone, content, and messaging strategy\n";
        $prompt .= "- Consider AI recommendations when crafting the email\n";
        $prompt .= "- Don't oversell - be helpful and authentic\n";
        
        // Call-to-action guidance
        if (!empty($callToAction)) {
            $prompt .= "- Include this specific call to action clearly in the body of the email: {$callToAction}\n";
        } else {
            $prompt .= "- Even if no call to action is provided, include a single, clear next step appropriate to the purpose (e.g. reply, schedule a call, confirm details, or take a simple action)\n";
        }
        
        // Closing / sign-off guidance
        $prompt .= "- Always end the email with an appropriate closing (for example 'Best regards' or 'Kind regards') followed by a sender name\n";
        $companyNameForSignoff = $context['our_company']['name'] ?? 'our company';
        $prompt .= "- If no specific sender name is given, sign off as 'The {$companyNameForSignoff} Team' and do not invent a personal name\n";
        
        // Formatting instructions
        $prompt .= "\nFORMATTING REQUIREMENTS:\n";
        $lengthDesc = match($length) {
            'short' => '2-3 sentences',
            'long' => '2-3 paragraphs',
            default => '1-2 paragraphs'
        };
        $prompt .= "- Length: {$lengthDesc}\n";
        
        $styleDesc = match($style) {
            'bullet_points' => 'Use bullet points for key information',
            'numbered' => 'Use numbered list for steps or sequential information',
            default => 'Use paragraphs'
        };
        $prompt .= "- Style: {$styleDesc}\n";
        $prompt .= "- Format: {$emailFormat}\n";
        $prompt .= "- The body must contain a clear call to action and a closing with a signature name as described above\n";
        
        $prompt .= "\nGenerate the email. Return JSON format:\n";
        $prompt .= "{\n";
        $prompt .= '  "subject": "Email subject line",' . "\n";
        $prompt .= '  "body_html": "HTML formatted email body",' . "\n";
        $prompt .= '  "body_text": "Plain text version",' . "\n";
        if (!empty($includePoints)) {
            $prompt .= '  "key_points": ["point1", "point2"],' . "\n";
        }
        if (!empty($callToAction)) {
            $prompt .= '  "call_to_action": "Suggested CTA"' . "\n";
        }
        $prompt .= "}\n";
        
        return $prompt;
    }
    
    /**
     * Build WhatsApp draft prompt
     */
    private function buildWhatsAppDraftPrompt(array $data): string
    {
        $context = $data['context'] ?? [];
        $purpose = $data['purpose'] ?? 'general';
        $tone = $data['tone'] ?? 'casual';
        
        $prompt = "Generate a {$tone} WhatsApp message for: {$purpose}\n\n";
        
        // Our Company (brief for WhatsApp)
        if (!empty($context['our_company'])) {
            $company = $context['our_company'];
            $prompt .= "OUR COMPANY:\n";
            $prompt .= "- Name: {$company['name']}\n";
            if (!empty($company['tagline'])) {
                $prompt .= "- Tagline: {$company['tagline']}\n";
            }
        }
        
        // Our Products (brief, top 3 most relevant)
        if (!empty($context['our_products']) && is_array($context['our_products'])) {
            $prompt .= "\nOUR PRODUCTS (brief reference only):\n";
            foreach (array_slice($context['our_products'], 0, 3) as $product) {
                $prompt .= "- {$product['name']}";
                if (!empty($product['category'])) {
                    $prompt .= " ({$product['category']})";
                }
                $prompt .= "\n";
            }
        }
        
        // Contact info
        if (!empty($context['contact'])) {
            $contact = $context['contact'];
            $prompt .= "\nCONTACT:\n";
            $prompt .= "- Name: {$contact['name']}\n";
            if (!empty($contact['first_name'])) {
                $prompt .= "- First Name: {$contact['first_name']}\n";
            }
            if (!empty($contact['company'])) {
                $prompt .= "- Company: {$contact['company']}\n";
            }
            if (!empty($contact['job_title'])) {
                $prompt .= "- Role: {$contact['job_title']}\n";
            }
        }
        
        // Business context (brief)
        if (!empty($context['business_profile']['company_industry'])) {
            $prompt .= "\nCONTACT'S INDUSTRY: {$context['business_profile']['company_industry']}\n";
        }
        
        // Recent activities (brief, max 2)
        if (!empty($context['recent_activities'])) {
            $prompt .= "\nRECENT ACTIVITY:\n";
            foreach (array_slice($context['recent_activities'], 0, 2) as $activity) {
                $prompt .= "- {$activity['type']}: {$activity['description']}\n";
            }
        }
        
        // AI Context Insights (if available)
        if (!empty($context['ai_insights']) && is_array($context['ai_insights'])) {
            $prompt .= "\nAI-GENERATED INSIGHTS (use to inform personalization):\n";
            $prompt .= "- Summary: " . ($context['ai_summary'] ?? 'N/A') . "\n";
            foreach (array_slice($context['ai_insights'], 0, 3) as $insight) {
                $prompt .= "- " . ($insight['type'] ?? 'insight') . ": " . ($insight['conclusion'] ?? '') . "\n";
            }
            if (!empty($context['ai_recommendations']) && is_array($context['ai_recommendations'])) {
                $prompt .= "\nAI Recommendations:\n";
                foreach (array_slice($context['ai_recommendations'], 0, 2) as $rec) {
                    $prompt .= "- {$rec}\n";
                }
            }
        }
        
        $prompt .= "\nKeep it concise (under 160 characters if possible, but can be longer if needed).";
        $prompt .= "\nUse casual, friendly tone appropriate for WhatsApp.";
        $prompt .= "\nReference our company/products naturally if relevant.";
        $prompt .= "\nLeverage AI insights to personalize the message appropriately.";
        $prompt .= "\nReturn JSON: {\"message\": \"...\"}";
        
        return $prompt;
    }

    /**
     * Build multi-channel auto reply prompt.
     */
    private function buildAutoReplyPrompt(array $data, string $channel): string
    {
        $context = $data['context'] ?? [];
        $incoming = trim((string) ($data['incoming_message'] ?? ''));
        $options = $data['options'] ?? [];
        $maxChars = (int) ($options['max_chars'] ?? ($channel === 'sms' ? 320 : ($channel === 'whatsapp' ? 700 : 4000)));
        $forbidHallucinations = !empty($options['forbid_hallucinations']);
        $draftStyleInstructions = trim((string) ($options['draft_style_instructions'] ?? ''));

        $ctx = json_encode([
            'contact' => $context['contact'] ?? [],
            'recent_communications' => $context['recent_communications'] ?? [],
            'deals' => $context['deals'] ?? [],
            'notes' => $context['notes'] ?? [],
            'company_profile' => $context['company_profile'] ?? [],
            'draft_style' => $context['draft_style'] ?? [],
        ], JSON_PRETTY_PRINT);

        $prompt = "You are an assistant drafting a customer reply for the current business.\n";
        $prompt .= "Use the active company profile as the source of truth for what the business offers.\n";
        $prompt .= "Do not describe the business as selling CRM, software, SaaS, or a platform unless the company profile clearly says so.\n";
        $prompt .= "If the company profile is weak or incomplete, use neutral language like services, solution, offer, or team instead of inventing products.\n";
        $prompt .= "Humanize the reply. Keep it concise, remove unnecessary detail, and make one clear intention easy to understand.\n";
        $prompt .= "Generate a safe, concise {$channel} reply to the incoming customer message.\n\n";
        if ($draftStyleInstructions !== '') {
            $prompt .= "DRAFT STYLE INSTRUCTIONS:\n{$draftStyleInstructions}\n\n";
        }
        if ($forbidHallucinations) {
            $prompt .= "CRITICAL: Never invent account details, pricing, order status, legal claims, or facts not present in context.\n";
            $prompt .= "If required facts are missing, ask a short clarification question.\n";
        }
        if ($channel === 'email') {
            $prompt .= "EMAIL RULES:\n";
            $prompt .= "- Reply logically to the latest open point in the thread.\n";
            $prompt .= "- Do not restate unrelated thread history when a narrower answer is enough.\n";
            $prompt .= "- Include a neutral business signoff in the plain-text reply.\n";
        } elseif ($channel === 'whatsapp') {
            $prompt .= "WHATSAPP RULES:\n";
            $prompt .= "- Write like a live chat, not like an email.\n";
            $prompt .= "- Keep it short and conversational.\n";
            $prompt .= "- If the draft style says this is a same-day continuation, do not start with Hi or Hello plus the contact name.\n";
        } elseif ($channel === 'sms') {
            $prompt .= "SMS RULES:\n";
            $prompt .= "- Keep it brief, direct, and conversational.\n";
            $prompt .= "- Focus on one clear next step.\n";
        }
        $prompt .= "CHANNEL: {$channel}\n";
        $prompt .= "MAX_CHARS: {$maxChars}\n";
        $prompt .= "INCOMING_MESSAGE:\n{$incoming}\n\n";
        $prompt .= "CRM_CONTEXT_JSON:\n{$ctx}\n\n";
        $prompt .= "Return ONLY valid JSON with this shape:\n";
        $prompt .= "{\n";
        $prompt .= '  "subject": "For email only, otherwise empty string",' . "\n";
        $prompt .= '  "reply_text": "Plain text reply suitable for channel",' . "\n";
        $prompt .= '  "body_html": "Optional HTML body for email, else empty string",' . "\n";
        $prompt .= '  "confidence": 0.0,' . "\n";
        $prompt .= '  "requires_human": false,' . "\n";
        $prompt .= '  "reasoning_tags": ["tag1", "tag2"]' . "\n";
        $prompt .= "}\n";

        return $prompt;
    }
    
    /**
     * Build tone adjustment prompt
     */
    private function buildToneAdjustmentPrompt(array $data): string
    {
        $text = $data['text'] ?? '';
        $currentTone = $data['current_tone'] ?? 'neutral';
        $targetTone = $data['target_tone'] ?? 'professional';
        
        return "Adjust the tone of the following text from {$currentTone} to {$targetTone}:\n\n{$text}\n\nReturn the adjusted text:";
    }
    
    /**
     * Build personalization prompt
     */
    private function buildPersonalizationPrompt(array $data): string
    {
        $template = $data['template'] ?? '';
        $contact = $data['contact'] ?? [];
        
        $prompt = "Personalize this email template for the contact:\n\n";
        $prompt .= "Contact: {$contact['first_name']} {$contact['last_name']}\n";
        if (!empty($contact['company'])) {
            $prompt .= "Company: {$contact['company']}\n";
        }
        $prompt .= "\nTemplate:\n{$template}\n\nReturn the personalized version:";
        
        return $prompt;
    }
    
    /**
     * Build quick replies prompt
     */
    private function buildQuickRepliesPrompt(array $data): string
    {
        $message = $data['message'] ?? '';
        $contact = $data['contact'] ?? [];
        
        return "Generate 3-5 quick reply suggestions for this WhatsApp message:\n\n{$message}\n\nReturn JSON array: [\"reply1\", \"reply2\", ...]";
    }
    
    /**
     * Build data extraction prompt
     */
    private function buildDataExtractionPrompt(array $data): string
    {
        $sourceType = $data['source_type'] ?? 'website';
        $text = $data['text'] ?? $data['content'] ?? '';
        $contactData = $data['contact_data'] ?? [];
        $url = $data['url'] ?? '';
        
        $prompt = "Extract structured contact and company information from the following {$sourceType} content.\n\n";
        
        if ($url) {
            $prompt .= "Source URL: {$url}\n\n";
        }
        
        if (!empty($contactData)) {
            $prompt .= "Existing contact data:\n";
            foreach ($contactData as $key => $value) {
                if (!empty($value)) {
                    $prompt .= "- " . ucfirst(str_replace('_', ' ', $key)) . ": {$value}\n";
                }
            }
            $prompt .= "\n";
        }
        
        $prompt .= "Content to extract from:\n{$text}\n\n";
        $prompt .= "Extract the following fields if available:\n";
        $prompt .= "- job_title\n";
        $prompt .= "- location\n";
        $prompt .= "- company_website\n";
        $prompt .= "- company_size\n";
        $prompt .= "- company_industry\n";
        $prompt .= "- company_description\n";
        $prompt .= "- company_founded (year only)\n";
        $prompt .= "- company_revenue\n";
        $prompt .= "- linkedin_url\n";
        $prompt .= "- twitter_url\n";
        $prompt .= "- timezone\n\n";
        $prompt .= "Return JSON format: {\"job_title\": \"...\", \"location\": \"...\", \"confidence\": 0.0-1.0, ...}";
        
        return $prompt;
    }
    
    /**
     * Build data inference prompt
     */
    private function buildDataInferencePrompt(array $data): string
    {
        $contactData = $data['contact_data'] ?? [];
        
        $prompt = "Based on the following contact information, infer missing fields using logical reasoning and common patterns.\n\n";
        $prompt .= "Contact Data:\n";
        foreach ($contactData as $key => $value) {
            if (!empty($value)) {
                $prompt .= "- " . ucfirst(str_replace('_', ' ', $key)) . ": {$value}\n";
            }
        }
        $prompt .= "\n";
        $prompt .= "Infer the following fields if possible:\n";
        $prompt .= "- job_title (from email domain, company, or name patterns)\n";
        $prompt .= "- company_website (from company name or email domain)\n";
        $prompt .= "- company_industry (from company name or website)\n";
        $prompt .= "- location (from email domain, company name, or timezone)\n";
        $prompt .= "- timezone (from location or email domain)\n";
        $prompt .= "- linkedin_url (from name and company)\n";
        $prompt .= "- twitter_url (from name)\n\n";
        $prompt .= "Never infer company website, company domain, account identity, location, or timezone from public email provider domains such as gmail.com, yahoo.com, outlook.com, hotmail.com, icloud.com, or protonmail.com.\n\n";
        $prompt .= "Return JSON format: {\"inferred_fields\": {\"job_title\": \"...\", \"confidence_scores\": {\"job_title\": 0.0-1.0}, \"reasoning\": \"...\"}}";
        
        return $prompt;
    }
    
    /**
     * Build data validation prompt
     */
    private function buildDataValidationPrompt(array $data): string
    {
        $contactData = $data['contact_data'] ?? [];
        
        $prompt = "Validate and correct the following contact data. Check for:\n";
        $prompt .= "- Email format correctness\n";
        $prompt .= "- Phone number format\n";
        $prompt .= "- Company name standardization\n";
        $prompt .= "- Job title capitalization\n";
        $prompt .= "- URL format (ensure http:// or https://)\n";
        $prompt .= "- Data consistency\n\n";
        $prompt .= "Contact Data:\n";
        foreach ($contactData as $key => $value) {
            if (!empty($value)) {
                $prompt .= "- " . ucfirst(str_replace('_', ' ', $key)) . ": {$value}\n";
            }
        }
        $prompt .= "\n";
        $prompt .= "Return JSON format: {\"validated_data\": {\"email\": \"...\", ...}, \"corrections\": [{\"field\": \"...\", \"old\": \"...\", \"new\": \"...\"}], \"quality_score\": 0.0-1.0, \"issues\": [\"...\"]}";

        return $prompt;
    }

    /**
     * Build workflow recommendations prompt
     */
    private function buildWorkflowRecommendationsPrompt(array $data): string
    {
        $context = $data['context'] ?? [];
        $metrics = $context['metrics'] ?? [];
        $featureUsage = $context['feature_usage'] ?? [];
        $templates = $context['templates'] ?? [];
        $industry = $context['industry'] ?? 'general';

        $templateList = [];
        foreach ($templates as $t) {
            $templateList[] = '- id ' . ($t['id'] ?? 0) . ': ' . ($t['name'] ?? '') . ' (' . ($t['category'] ?? '') . ') - ' . ($t['description'] ?? '');
        }

        $prompt = "You are Clarity, an automation strategist inside an AI Business Incubator. Recommend workflow templates for this user based on their context.\n\n";
        $prompt .= "CONTEXT:\n";
        $prompt .= "- Contacts: " . ($featureUsage['contact_count'] ?? 0) . "\n";
        $prompt .= "- Deals: " . ($featureUsage['deals_used'] ? 'yes' : 'no') . "\n";
        $prompt .= "- Workflows already used: " . ($featureUsage['workflows_used'] ? 'yes' : 'no') . "\n";
        $prompt .= "- Active workflows count: " . ($featureUsage['workflow_count'] ?? 0) . "\n";
        $prompt .= "- Leads today: " . ($metrics['leads_today'] ?? 0) . "\n";
        $prompt .= "- Emails sent (week): " . ($metrics['emails_sent_week'] ?? 0) . "\n";
        $prompt .= "- Form submissions (month): " . ($metrics['form_submissions_month'] ?? 0) . "\n";
        $prompt .= "- Industry: " . $industry . "\n\n";
        $prompt .= "AVAILABLE TEMPLATES:\n" . (empty($templateList) ? '- none' : implode("\n", $templateList)) . "\n\n";
        $prompt .= "Return ONLY valid JSON (no markdown) with this structure:\n";
        $prompt .= "{\"recommendations\": [{\"template_id\": 1, \"reason\": \"short reason\", \"impact\": \"high|medium|low\", \"effort\": \"low|medium|high\"}], \"suggested_workflow\": null}\n";
        $prompt .= "Recommend 3-5 templates. Use template_id from the list. suggested_workflow can be null or a custom workflow suggestion.";

        return $prompt;
    }

    private function buildWorkflowGenerationPrompt(array $data): string
    {
        $prompt = trim((string) ($data['prompt'] ?? $data['text'] ?? ''));
        $currentWorkflow = $data['current_workflow'] ?? [];
        $availableTriggers = array_values(array_map('strval', (array) ($data['available_triggers'] ?? [])));
        $availableActions = array_values(array_map('strval', (array) ($data['available_actions'] ?? [])));

        return "You are generating a workflow graph for a CRM automation builder.\n\n"
            . "Return ONLY valid JSON with this exact shape:\n"
            . "{\"graph\":{\"version\":2,\"meta\":{\"name\":\"Workflow name\",\"mode\":\"mixed\"},\"nodes\":[{\"id\":\"trigger_1\",\"type\":\"trigger\",\"subtype\":\"contact_created\",\"position\":{\"x\":120,\"y\":140},\"config\":{\"type\":\"contact_created\"}}],\"edges\":[{\"id\":\"edge_1\",\"source\":\"trigger_1\",\"target\":\"action_1\",\"branch\":\"default\",\"order\":0}]}}\n\n"
            . "Rules:\n"
            . "- Use exactly one trigger node.\n"
            . "- Use only available triggers and actions.\n"
            . "- Use type 'action' for action nodes and 'delay' only for wait_for_days.\n"
            . "- Keep config minimal but valid.\n"
            . "- Do not include markdown.\n\n"
            . "AVAILABLE TRIGGERS:\n" . json_encode($availableTriggers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n"
            . "AVAILABLE ACTIONS:\n" . json_encode($availableActions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n"
            . "CURRENT WORKFLOW:\n" . json_encode($currentWorkflow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n"
            . "USER REQUEST:\n" . $prompt;
    }

    /**
     * Build email assistant question prompt
     */
    private function buildEmailAssistantQuestionPrompt(array $data): string
    {
        $question = $data['question'] ?? $data['text'] ?? '';
        $context = $data['context'] ?? [];
        $ctx = json_encode($context, JSON_PRETTY_PRINT);
        return "You are Clarity, the AI co-founder inside an AI Business Incubator. Answer this question using the provided business context. Keep it concise (2-4 sentences), calm, and strategic.\n\n"
            . "CONTEXT:\n{$ctx}\n\n"
            . "QUESTION: {$question}\n\n"
            . "ANSWER:";
    }

    /**
     * Build website assistant prompt (for in-app help chat bubble)
     */
    private function buildWebsiteAssistantPrompt(array $data): string
    {
        $question = $data['question'] ?? $data['message'] ?? $data['text'] ?? '';
        $context = $data['context'] ?? [];
        $qualification = $data['qualification'] ?? [];
        $operatingContext = $data['operating_context'] ?? [];
        $languageInstruction = $this->responseStyleInstructionFromPromptContext([
            'response_style_contract' => (array) ($operatingContext['ai_settings']['response_style_contract'] ?? []),
            'blocks' => [],
        ], [
            'response_style_contract' => $context['response_style_contract'] ?? null,
        ]);
        $ctx = json_encode($context, JSON_PRETTY_PRINT);
        $qualificationJson = json_encode($qualification, JSON_PRETTY_PRINT);
        $operatingContextJson = json_encode($operatingContext, JSON_PRETTY_PRINT);
        return "You are Clarity, the AI co-founder inside an AI Business Incubator. Answer questions about this website: features, how to use them, where to find things, navigation. Be concise (2-4 sentences).\n"
            . "Use calm strategic language. Prefer Now / Next / Later framing when recommending actions. Avoid competitive/comparative framing unless explicitly requested.\n\n"
            . ($languageInstruction !== '' ? $languageInstruction . "\n\n" : '')
            . "White-label requirement: never mention OpenAI, ChatGPT, GPT model names, API providers, or third-party AI vendors. Refer to yourself only as Clarity or the assistant.\n\n"
            . "CONTEXT:\n{$ctx}\n\n"
            . "AI OPERATING CONTEXT:\n{$operatingContextJson}\n\n"
            . "QUALIFICATION STATE:\n{$qualificationJson}\n\n"
            . "If context quality is low, say what is missing instead of sounding certain.\n\n"
            . "QUESTION: {$question}\n\n"
            . "Return ONLY the answer as plain text. Do not use JSON or any structured format.";
    }

    private function buildAIContextDiagnosticsPrompt(array $data): string
    {
        return "Analyze this AI operating context and return ONLY valid JSON with keys "
            . "{\"context_quality_score\":0.0,\"missing_context_flags\":[],\"readiness_gaps\":[],\"reason_codes\":[]}\n\n"
            . json_encode($data, JSON_PRETTY_PRINT);
    }

    private function buildAIGoalRelevancePrompt(array $data): string
    {
        return "Score how relevant this advice is to the user's active goals. Return ONLY valid JSON: "
            . "{\"goal_relevance_score\":0.0,\"reason_codes\":[\"...\"]}\n\n"
            . json_encode($data, JSON_PRETTY_PRINT);
    }

    private function buildAIModeQualificationPrompt(array $data): string
    {
        return "Evaluate the best AI guidance mode. Return ONLY valid JSON: "
            . "{\"mode\":\"1|2|3\",\"reason_codes\":[\"...\"],\"scores\":{\"foundation_need\":0.0,\"operations_readiness\":0.0,\"context_quality\":0.0}}\n\n"
            . json_encode($data, JSON_PRETTY_PRINT);
    }

    private function buildAITaskCompletionMatchPrompt(array $data): string
    {
        return "Given a task and evidence, decide if the evidence explicitly completes the task. "
            . "Return ONLY valid JSON: {\"is_match\":true,\"confidence\":0.0,\"reason_codes\":[\"explicit_evidence\"],\"evidence_type\":\"string\"}\n\n"
            . "Never treat vague inference as a match.\n\n"
            . json_encode($data, JSON_PRETTY_PRINT);
    }

    /**
     * Build email assistant intent classification prompt
     */
    private function buildEmailAssistantIntentPrompt(array $data): string
    {
        $text = $data['text'] ?? '';
        $intents = 'question|create_task|create_contact|update_contact|delete_contact|add_note|get_pipeline|list_tasks|schedule_event|run_report|get_status|enrich_contact|verify_contact_email|verify_email_value|create_invoice|update_invoice|list_invoices|send_invoice|finalize_invoice|mark_invoice_paid|convert_quote_to_invoice|approve_commercial_action|reject_commercial_action|summarize_commercial_automation_state|draft_customer_reply|send_customer_reply|revise_quote_with_context|explain_quote_changes|summarize_thread_state|list_pending_commercial_approvals|resolve_assistant_ambiguity|show_last_assistant_action|rerun_assistant_action|unknown';
        return "Classify the intent of this message from an admin to the business growth system. "
            . "Return ONLY valid JSON: {\"intent\": \"{$intents}\"}\n\n"
            . "MESSAGE: {$text}";
    }

    /**
     * Build email assistant parse task prompt
     */
    private function buildEmailAssistantParseTaskPrompt(array $data): string
    {
        $text = $data['text'] ?? '';
        return "Extract task title and optional due date from this instruction. "
            . "Return ONLY valid JSON: {\"title\": \"task title\", \"due_date\": \"Y-m-d or null\"}\n\n"
            . "Support formats like: 'tomorrow', 'next Monday', '2025-02-25'.\n\n"
            . "INSTRUCTION: {$text}";
    }

    /**
     * Build email assistant parse instructions prompt (multi-instruction)
     */
    private function buildEmailAssistantParseInstructionsPrompt(array $data): string
    {
        $text = $data['text'] ?? '';
        return "Parse this message into separate instructions. Each instruction should have its own intent. "
            . "Return ONLY valid JSON array: [{\"instruction\": \"text\", \"intent\": \"intent_name\"}, ...]\n\n"
            . "Valid intents: question, create_task, create_contact, update_contact, delete_contact, add_note, get_pipeline, list_tasks, schedule_event, run_report, enrich_contact, verify_contact_email, verify_email_value, unknown\n\n"
            . "If there is only one instruction, return array with one element. If multiple distinct instructions, return multiple elements.\n\n"
            . "MESSAGE: {$text}";
    }

    /**
     * Build email assistant parse contact prompt
     */
    private function buildEmailAssistantParseContactPrompt(array $data): string
    {
        $text = $data['text'] ?? '';
        return "Extract contact details from this instruction. "
            . "Return ONLY valid JSON: {\"first_name\": \"...\", \"last_name\": \"...\", \"email\": \"...\", \"phone\": \"...\", \"company\": \"...\"}\n\n"
            . "Use null for missing fields. Email is required for creating a contact.\n\n"
            . "INSTRUCTION: {$text}";
    }

    /**
     * Build email assistant parse contact update prompt
     */
    private function buildEmailAssistantParseContactUpdatePrompt(array $data): string
    {
        $text = $data['text'] ?? '';
        return "Extract contact identifier and fields to update from this instruction. "
            . "Return ONLY valid JSON: {\"contact_identifier\": \"name or email\", \"updates\": {\"first_name\": \"...\", \"last_name\": \"...\", \"email\": \"...\", \"phone\": \"...\", \"company\": \"...\", \"job_title\": \"...\", \"stage\": \"...\"}}\n\n"
            . "contact_identifier identifies which contact to update (e.g. 'John', 'john@example.com'). updates contains only the fields to change. Use null for missing fields.\n\n"
            . "INSTRUCTION: {$text}";
    }

    /**
     * Build email assistant parse note prompt
     */
    private function buildEmailAssistantParseNotePrompt(array $data): string
    {
        $text = $data['text'] ?? '';
        return "Extract who the note is for and the note content from this instruction. "
            . "Return ONLY valid JSON: {\"contact_identifier\": \"name or email\", \"content\": \"note text\"}\n\n"
            . "contact_identifier identifies the contact (e.g. 'John', 'john@example.com'). content is the note to add.\n\n"
            . "INSTRUCTION: {$text}";
    }

    /**
     * Build email assistant parse event prompt
     */
    private function buildEmailAssistantParseEventPrompt(array $data): string
    {
        $text = $data['text'] ?? '';
        return "Extract event details from this instruction. "
            . "Return ONLY valid JSON: {\"title\": \"...\", \"start_time\": \"Y-m-d H:i:s\", \"end_time\": \"Y-m-d H:i:s or null\", \"description\": \"...\"}\n\n"
            . "Support formats like: 'tomorrow 2pm', 'next Monday 10am', '2025-02-25 14:00'. Use current date as reference if needed.\n\n"
            . "INSTRUCTION: {$text}";
    }

    private function buildEmailAssistantEntityResolutionPrompt(array $data): string
    {
        $text = $data['text'] ?? '';
        $context = json_encode($data['context'] ?? [], JSON_PRETTY_PRINT);
        return "Resolve CRM entities referenced in this assistant request. Return ONLY valid JSON with this shape:\n"
            . "{\"contact_query\": \"...\", \"deal_query\": \"...\", \"invoice_query\": \"...\", \"approval_query\": \"...\", \"confidence\": 0.0, \"reason\": \"...\"}\n\n"
            . "CONTEXT:\n{$context}\n\nREQUEST:\n{$text}";
    }

    private function buildEmailAssistantCustomerReplyGoalPrompt(array $data): string
    {
        $text = $data['text'] ?? '';
        $context = json_encode($data['context'] ?? [], JSON_PRETTY_PRINT);
        return "Infer the commercial goal of this customer thread. Return ONLY valid JSON:\n"
            . "{\"goal\": \"draft|send|revise|explain\", \"reason\": \"...\", \"confidence\": 0.0}\n\n"
            . "CONTEXT:\n{$context}\n\nTHREAD:\n{$text}";
    }

    private function buildEmailAssistantCommercialReplyPrompt(array $data): string
    {
        $context = json_encode($data, JSON_PRETTY_PRINT);
        return "Draft a polished commercial email reply. Return ONLY valid JSON:\n"
            . "{\"subject\": \"...\", \"plain_body\": \"...\", \"explanation\": \"...\"}\n\n"
            . "Stay concise, professional, and grounded in the provided context. Do not invent discounts, terms, or attachments.\n\n"
            . "CONTEXT:\n{$context}";
    }

    private function buildEmailAssistantChangeExplanationPrompt(array $data): string
    {
        $context = json_encode($data, JSON_PRETTY_PRINT);
        return "Explain the commercial changes between document versions. Return ONLY valid JSON:\n"
            . "{\"summary\": \"...\", \"customer_friendly\": \"...\"}\n\n"
            . "CONTEXT:\n{$context}";
    }

    private function buildEmailAssistantAmbiguitySummaryPrompt(array $data): string
    {
        $context = json_encode($data, JSON_PRETTY_PRINT);
        return "Summarize assistant ambiguity and request clarification. Return ONLY valid JSON:\n"
            . "{\"message\": \"...\", \"top_candidates\": [\"...\"], \"reason\": \"...\"}\n\n"
            . "CONTEXT:\n{$context}";
    }

    /**
     * Build meeting prep summary prompt
     */
    private function buildMeetingPrepPrompt(array $data): string
    {
        $context = $data['context'] ?? [];
        $text = $data['text'] ?? json_encode($context, JSON_PRETTY_PRINT);
        return "You are Clarity, the AI co-founder inside an AI Business Incubator. Generate a concise meeting prep brief from this contact and deal context.\n"
            . "Use a calm strategic tone and include Now / Next / Later priorities when useful.\n\n"
            . "CONTEXT:\n{$text}\n\n"
            . "Return ONLY valid JSON:\n"
            . "{\"summary\": \"2-3 sentence overview\", \"key_points\": [\"point1\", \"point2\", ...], "
            . "\"open_questions\": [\"question1\", ...], \"suggested_topics\": [\"topic1\", ...]}\n\n"
            . "Include 3-5 key points, 1-3 open questions, and 2-4 suggested discussion topics.";
    }

    private function buildMeetingNoteAnalysisPrompt(array $data): string
    {
        $context = $data['context'] ?? [];
        $text = $data['text'] ?? json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return "You are Clarity, the AI co-founder inside an AI Business Incubator. Analyze a meeting transcript and convert it into CRM-safe updates.\n"
            . "Only suggest contact field updates for factual, clearly stated details. Keep summaries concise and operational.\n"
            . "If deal progression is uncertain, return null for suggested_stage.\n\n"
            . "MEETING CONTEXT:\n{$text}\n\n"
            . "Return ONLY valid JSON with this shape:\n"
            . "{"
            . "\"summary\":\"short operational meeting summary\","
            . "\"relationship_context\":\"1-3 sentence context memory for future interactions\","
            . "\"next_step\":\"single best next step or empty string\","
            . "\"confidence\":0.0,"
            . "\"contact_updates\":{"
            . "\"job_title\":{\"value\":\"\",\"confidence\":0.0,\"reason\":\"\"},"
            . "\"location\":{\"value\":\"\",\"confidence\":0.0,\"reason\":\"\"},"
            . "\"company_website\":{\"value\":\"\",\"confidence\":0.0,\"reason\":\"\"},"
            . "\"linkedin_url\":{\"value\":\"\",\"confidence\":0.0,\"reason\":\"\"},"
            . "\"twitter_url\":{\"value\":\"\",\"confidence\":0.0,\"reason\":\"\"},"
            . "\"timezone\":{\"value\":\"\",\"confidence\":0.0,\"reason\":\"\"}"
            . "},"
            . "\"action_items\":[{\"text\":\"\",\"due\":\"Y-m-d or null\",\"assignee_hint\":\"\"}],"
            . "\"deal_stage\":{\"suggested_stage\":\"prospecting|qualification|proposal|negotiation|closed_won|closed_lost|null\",\"confidence\":0.0,\"reason\":\"\"}"
            . "}\n\n"
            . "Use only the supported contact fields. Omit empty contact update objects when nothing reliable is stated.";
    }

    /**
     * Build thread summary prompt
     */
    private function buildThreadSummaryPrompt(array $data): string
    {
        $text = $data['text'] ?? '';
        return "Summarize this conversation thread. Include: main topic, key decisions, open questions, suggested next step.\n\n"
            . "CONVERSATION:\n{$text}\n\n"
            . "Return a concise summary (2-4 sentences).";
    }

    /**
     * Build deal next steps prompt
     */
    private function buildDealNextStepsPrompt(array $data): string
    {
        $context = $data['context'] ?? [];
        $text = $data['text'] ?? json_encode($context, JSON_PRETTY_PRINT);
        return "You are Clarity, the AI co-founder inside an AI Business Incubator. Suggest 2-4 concrete next steps for this deal based on its stage, notes, and activities.\n"
            . "Use calm strategic tone. Prefer Now / Next / Later framing where appropriate.\n\n"
            . "DEAL CONTEXT:\n{$text}\n\n"
            . "Return ONLY valid JSON: {\"next_steps\": [{\"action\": \"...\", \"priority\": \"high|medium|low\"}, ...]}";
    }

    /**
     * Build deal summary prompt
     */
    private function buildDealSummaryPrompt(array $data): string
    {
        $text = $data['text'] ?? '';
        return "Summarize this deal's history and current status in 2-4 sentences. Include key milestones and recent activity.\n\n"
            . "DEAL CONTEXT:\n{$text}";
    }

    /**
     * Build deal close date prompt
     */
    private function buildDealCloseDatePrompt(array $data): string
    {
        $context = $data['context'] ?? [];
        $text = $data['text'] ?? json_encode($context, JSON_PRETTY_PRINT);
        return "Based on this deal context (stage, notes, activities, expected close date), predict a likely close date.\n\n"
            . "DEAL CONTEXT:\n{$text}\n\n"
            . "Return ONLY valid JSON: {\"predicted_date\": \"Y-m-d\", \"confidence\": 0.0-1.0, \"reasoning\": \"...\"}";
    }

    /**
     * Build deal stage suggestion prompt for automation
     */
    private function buildDealStageSuggestionPrompt(array $data): string
    {
        $evidence = $data['evidence'] ?? [];
        $text = $data['text'] ?? json_encode($evidence, JSON_PRETTY_PRINT);
        $allowedStages = $data['allowed_stages'] ?? ['prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost'];
        $currentStage = $data['current_stage'] ?? 'prospecting';
        $stagesStr = implode(', ', $allowedStages);
        return "You are Clarity, the AI co-founder inside an AI Business Incubator. Suggest the NEXT deal stage based on communication evidence.\n"
            . "Keep rationale practical and non-comparative.\n\n"
            . "CURRENT STAGE: {$currentStage}\n"
            . "ALLOWED STAGES: {$stagesStr}\n\n"
            . "EVIDENCE:\n{$text}\n\n"
            . "Return ONLY valid JSON: {\"suggested_stage\": \"<one of allowed stages>\", \"confidence\": 0.0-1.0, \"reasoning\": \"brief explanation\", \"evidence_flags\": [\"flag1\", \"flag2\"]}.\n"
            . "Only suggest closed_won or closed_lost when there is explicit acceptance or rejection evidence. Prefer forward progression (prospecting->qualification->proposal->negotiation) when evidence supports it.";
    }

    /**
     * Build action item extraction prompt
     */
    private function buildActionItemExtractionPrompt(array $data): string
    {
        $text = $data['text'] ?? $data['content'] ?? '';
        return "Extract action items from this note. Return ONLY valid JSON:\n"
            . "{\"actions\": [{\"text\": \"action description\", \"due\": \"Y-m-d or null\", \"assignee_hint\": \"...\"}]}\n\n"
            . "NOTE CONTENT:\n{$text}";
    }

    /**
     * Build form submission analysis prompt
     */
    private function buildFormSubmissionAnalysisPrompt(array $data): string
    {
        $formData = $data['form_data'] ?? [];
        $text = is_array($formData) ? json_encode($formData, JSON_PRETTY_PRINT) : (string) $formData;
        return "Analyze this form submission. Suggest tags, contact stage, and follow-up action.\n\n"
            . "SUBMISSION:\n{$text}\n\n"
            . "Return ONLY valid JSON: {\"tags\": [\"tag1\", ...], \"suggested_stage\": \"new|qualified|...\", \"follow_up_suggestion\": \"...\"}";
    }

    /**
     * Build search query parse prompt
     */
    private function buildSearchQueryParsePrompt(array $data): string
    {
        $query = $data['query'] ?? $data['text'] ?? '';
        return "Convert this natural-language workspace search into structured filters. Return ONLY valid JSON:\n"
            . "{\"filters\": {\"stage\": \"...\", \"tags\": [], \"min_score\": null, \"entity_types\": [\"contact\", \"deal\", ...]}, \"keywords\": [\"...\"]}\n\n"
            . "Use null for unknown filters. Extract keywords for fallback text search.\n\n"
            . "QUERY: {$query}";
    }

    /**
     * Build document extraction prompt
     */
    private function buildDocumentExtractionPrompt(array $data): string
    {
        $text = $data['text'] ?? $data['content'] ?? '';
        $sourceType = $data['source_type'] ?? 'document';
        return "Extract entities and suggest tags from this {$sourceType} content.\n\n"
            . "CONTENT:\n{$text}\n\n"
            . "Return ONLY valid JSON: {\"entities\": {\"names\": [], \"dates\": [], \"amounts\": []}, \"suggested_tags\": [], \"summary\": \"...\"}";
    }

    /**
     * Build natural language report prompt
     */
    private function buildNLReportPrompt(array $data): string
    {
        $question = $data['question'] ?? $data['text'] ?? '';
        $context = $data['context'] ?? [];
        $ctx = json_encode($context, JSON_PRETTY_PRINT);
        return "You are Clarity, the AI co-founder inside an AI Business Incubator. Answer this question based on the provided business context.\n\n"
            . "CONTEXT:\n{$ctx}\n\n"
            . "QUESTION: {$question}\n\n"
            . "Return ONLY valid JSON: {\"report_type\": \"...\", \"query_params\": {}, \"answer\": \"...\", \"suggested_query\": \"...\"}";
    }
}
