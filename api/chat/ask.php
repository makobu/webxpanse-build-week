<?php
/**
 * Website Assistant Chat API
 * POST: Accepts user message, returns AI answer about the growth system website.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Services\AIService;
use CRM\Services\AIExecutionStatusService;
use CRM\Services\AIRuntimeControlService;
use CRM\Services\AIContextAssemblyService;
use CRM\Services\AIAdviceDomainRouterService;
use CRM\Services\AIDecisionOutcomeService;
use CRM\Services\AIOperatingContextService;
use CRM\Services\AIOutcomeClassifier;
use CRM\Services\AIRoleProfileService;
use CRM\Services\AIRetrievalQualityService;
use CRM\Services\AIQualificationPolicyService;
use CRM\Services\AITaskCompletionService;
use CRM\Services\AIThresholdUpdateService;
use CRM\Services\AITextResponseNormalizerService;
use CRM\Services\AIUserWorkContextService;
use CRM\Services\AICoachWorkspaceSetupService;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\ClarityPageContextService;
use CRM\Services\ClarityConversationService;
use CRM\Services\ClarityExplanationContextService;
use CRM\Services\ClarityOperatorControlsService;
use CRM\Services\ClarityQuestionIntentService;
use CRM\Services\ClarityResponsePresentationService;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\OrganizationIntelligenceContextService;
use CRM\Services\OrganizationIntelligenceConversationService;
use CRM\Services\OrganizationIntelligenceSnapshotService;
use CRM\Services\PluginRuntimeAuthorizationService;
use CRM\Services\SuperAdminOpsService;
use CRM\Services\WorkspaceOperatingBriefService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Modules\WebsiteAssistantContext;
use CRM\Modules\Tasks;
use CRM\Modules\UserPreferences;
use CRM\Modules\UserStrategyProfile;
use CRM\Modules\AITaskAutomationService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawInput = file_get_contents('php://input') ?: '';
$input = json_decode($rawInput !== '' ? $rawInput : '{}', true) ?: [];
if ($input === [] && $_POST !== []) {
    $input = $_POST;
}
$message = trim($input['message'] ?? $input['text'] ?? '');
$currentPage = trim($input['current_page'] ?? '') ?: null;
$requestedSurface = trim((string) ($input['surface'] ?? $input['mode'] ?? ''));
$organizationIntelligencePageContext = sanitizeOrganizationIntelligencePageContext($input['page_context'] ?? null, $currentPage);
$organizationIntelligenceConversation = null;
$organizationIntelligenceMessageId = null;
$organizationIntelligenceAssistantMessageId = null;
$organizationIntelligenceContextSnapshotId = null;
$clarityConversation = null;
$clarityConversationService = null;
$clarityConversationContext = [];
$clarityMessageId = null;
$clarityAssistantMessageId = null;

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Message is required']);
    exit;
}

function buildProtectedDemoChatAnswer(string $message, ?string $currentPage = null): string
{
    $lower = strtolower(trim($message));

    if (preg_match('/\b(why|priority|first|handle|handled|focus)\b.*\b(riverside|amina)\b|\b(riverside|amina)\b.*\b(first|priority|handle|handled)\b/i', $lower)) {
        return "**Why Riverside first**\n"
            . "- Amina is asking for proposal changes, payment terms, finish confirmation, and a Friday installation hold in one thread.\n"
            . "- That combination is a high-intent revenue moment: the buyer has context, urgency, and a concrete next step.\n"
            . "- Clarity connects the WhatsApp lead, Rose's email context, the assistant draft, the follow-up task, the response-time target, and Amina's contact record so the workspace knows exactly what to do next.";
    }

    if (preg_match('/\b(inbox|message|messages|whatsapp|email|lead|thread)\b/i', $lower)) {
        return "**Inbox context**\n"
            . "The Riverside inbox is showing a private session overlay: Amina's WhatsApp request, Rose's supporting email, triage, and the assistant draft all belong only to this demo session. Nothing is sent to a real provider.";
    }

    if (preg_match('/\b(draft|reply|response|proposal)\b/i', $lower)) {
        return "**Assistant draft**\n"
            . "The draft should confirm the matte stone finish, explain the split payment terms, hold the Friday installation slot, and propose a short confirmation call. It is specific enough to send after review, but still safely simulated for the demo.";
    }

    if (preg_match('/\b(task|tasks|follow.?up|due)\b/i', $lower)) {
        return "**Task follow-through**\n"
            . "Clarity turns the Riverside inbox thread into a high-priority 3:00 PM follow-up task. The task is linked back to Amina, Rose, and the procurement context so the next commitment does not float away from the client conversation.";
    }

    if (preg_match('/\b(target|targets|goal|response.?time|metric)\b/i', $lower)) {
        return "**Target movement**\n"
            . "The response-time target moves because the Riverside thread is a live qualified lead. The point is to show that Clarity does not only store messages; it turns operational behavior into visible momentum.";
    }

    if (preg_match('/\b(plugin|plugins|marketplace|capabilit)/i', $lower)) {
        return "**Configured capabilities**\n"
            . "In this protected demo, plugins are shown as already configured capabilities: email, WhatsApp, AI drafting, tasks, targets, templates, and contact intelligence are ready. Credential setup and real outbound delivery stay disabled.";
    }

    if (preg_match('/\b(contact|contacts|amina|intelligence|customer)\b/i', $lower)) {
        return "**Contact intelligence**\n"
            . "Amina's contact record carries the WhatsApp lead, Rose's email context, the assistant draft, the follow-up task, and the response target. That is the sophistication the demo is meant to sell: the contact becomes an operating memory, not a static address book row.";
    }

    if (preg_match('/\b(notification|notifications|toast|feed|recap)\b/i', $lower)) {
        return "**Notification feed**\n"
            . "The demo notifications are paced to tell one Riverside story. They should confirm progress, point to the right page, and stay private to the active demo session instead of repeating generic alerts.";
    }

    return "**Riverside demo context**\n"
        . "This workspace is fully configured for the protected demo. Follow the Riverside sequence: Inbox captures the lead, triage ranks it, Clarity drafts the response, Tasks create ownership, Targets show momentum, Plugins prove readiness, and Contacts preserve the full client memory.";
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$preferences = new UserPreferences();
$strategyProfile = new UserStrategyProfile();
$leanCanvasEnabled = $preferences->isLeanCanvasModeEnabled($userId);
$leanCanvasCompleteness = $strategyProfile->getLeanCanvasCompleteness($userId);
$leanCanvasMissingBlocks = $strategyProfile->getLeanCanvasMissingBlocks($userId);
$leanCanvasMetadata = buildLeanCanvasMetadata($leanCanvasEnabled, $leanCanvasCompleteness, $leanCanvasMissingBlocks);

$workspaceIdForDemo = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$clarityOperatorControlsEnabled = (new ClarityOperatorControlsService())->enabled($workspaceIdForDemo);
$protectedDemoSession = null;
try {
    $protectedDemoSession = $workspaceIdForDemo > 0 ? (new DemoSessionScopeService())->activeSession($workspaceIdForDemo) : null;
} catch (\Throwable $e) {
    $protectedDemoSession = null;
}

if ($protectedDemoSession !== null) {
    echo json_encode([
        'answer' => buildProtectedDemoChatAnswer($message, $currentPage),
        'metadata' => array_merge($leanCanvasMetadata, [
            'mode_variant' => 'protected_demo',
            'protected_demo' => true,
        ]),
        'diagnostics' => [
            'mode_variant' => 'protected_demo',
            'protected_demo' => true,
            'source' => 'protected_demo_chat',
            'current_page' => $currentPage,
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($requestedSurface === 'superadmin_ops') {
    if (!Authorization::isSuperAdmin($user)) {
        http_response_code(403);
        echo json_encode(['error' => 'Super Admin access is required for Clarity Ops.']);
        exit;
    }

    try {
        echo json_encode((new SuperAdminOpsService())->handleMessage($message, $user));
    } catch (\Throwable $e) {
        error_log('SuperAdminOps ask error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to get Super Admin Ops answer',
            'answer' => 'Sorry, Clarity Ops could not load the platform context. Please try again.',
            'metadata' => ['mode_variant' => 'superadmin_ops', 'superadmin_ops' => true],
        ]);
    }
    exit;
}

/**
 * @return array{handled:bool,answer:string}
 */
function recordClarityGuidanceOutcome(
    int $userId,
    ?string $currentPage,
    string $message,
    string $answer,
    string $actionType,
    array $classification
): int {
    $operatingContext = (new AIOperatingContextService())->buildForSurface($userId, 'clarity_chat', [
        'current_page' => $currentPage,
    ]);
    $operatingContext['assistant_confidence'] = 1.0;
    $policy = new AIQualificationPolicyService();
    $decision = $policy->evaluateAdviceEligibility($operatingContext);
    $runId = $policy->logGuidanceRun(
        $userId,
        'clarity_chat',
        (string) ($operatingContext['qualification_state']['effective_mode'] ?? '1'),
        $decision,
        array_merge($operatingContext, ['message' => $message]),
        ['answer' => $answer, 'action_type' => $actionType]
    );

    if ($runId > 0) {
        $run = Database::queryOne("SELECT * FROM ai_guidance_runs WHERE id = ?", [$runId]) ?: [];
        if ($run) {
            (new AIDecisionOutcomeService())->recordGuidanceOutcome($run, $classification);
        }
    }

    return $runId;
}

function buildClarityMessageHash(int $guidanceRunId, string $question, string $answer): string
{
    $normalizedAnswer = preg_replace('/\s+/', ' ', trim($answer)) ?? trim($answer);
    $normalizedQuestion = preg_replace('/\s+/', ' ', trim($question)) ?? trim($question);
    return hash('sha256', implode('|', [$guidanceRunId, $normalizedQuestion, $normalizedAnswer]));
}

function unwrapAssistantAnswer(string $answer): string
{
    return (new AITextResponseNormalizerService())->normalize($answer);
}
$questionIntent = (new ClarityQuestionIntentService())->classify($message, $currentPage);

function buildLeanCanvasMetadata(bool $enabled, int $completeness = 0, array $missingBlocks = []): array
{
    return [
        'mode_variant' => $enabled ? 'lean_canvas' : 'standard',
        'lean_canvas_enabled' => $enabled,
        'lean_canvas_completeness' => $completeness,
        'lean_canvas_missing_blocks' => array_values($missingBlocks),
    ];
}

function sanitizeOrganizationIntelligenceScalar($value, int $maxLength = 180): string
{
    if (!is_scalar($value)) {
        return '';
    }

    $text = preg_replace('/\s+/', ' ', strip_tags((string) $value)) ?? '';
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (strlen($text) <= $maxLength) {
        return $text;
    }

    return rtrim(substr($text, 0, max(0, $maxLength - 1))) . '...';
}

function sanitizeOrganizationIntelligenceSignalList($value, int $limit = 3): array
{
    if (!is_array($value)) {
        return [];
    }

    $signals = [];
    foreach ($value as $item) {
        $text = sanitizeOrganizationIntelligenceScalar($item, 180);
        if ($text === '' || in_array($text, $signals, true)) {
            continue;
        }
        $signals[] = $text;
        if (count($signals) >= $limit) {
            break;
        }
    }

    return $signals;
}

function sanitizeOrganizationIntelligencePriorities($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $priorities = [];
    foreach ($value as $priority) {
        if (!is_array($priority)) {
            continue;
        }
        $priorities[] = [
            'title' => sanitizeOrganizationIntelligenceScalar($priority['title'] ?? '', 90),
            'action' => sanitizeOrganizationIntelligenceScalar($priority['action'] ?? '', 160),
            'severity' => sanitizeOrganizationIntelligenceScalar($priority['severity'] ?? 'medium', 24),
            'confidence' => sanitizeOrganizationIntelligenceScalar($priority['confidence'] ?? 'moderate', 24),
            'evidence' => sanitizeOrganizationIntelligenceScalar($priority['evidence'] ?? '', 160),
        ];
        if (count($priorities) >= 3) {
            break;
        }
    }

    return $priorities;
}

function sanitizeOrganizationIntelligenceMetrics($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $metrics = [];
    foreach ($value as $metric) {
        if (!is_array($metric)) {
            continue;
        }
        $metrics[] = [
            'label' => sanitizeOrganizationIntelligenceScalar($metric['label'] ?? '', 60),
            'value' => sanitizeOrganizationIntelligenceScalar($metric['value'] ?? '', 40),
            'status' => sanitizeOrganizationIntelligenceScalar($metric['status'] ?? '', 40),
            'tone' => sanitizeOrganizationIntelligenceScalar($metric['tone'] ?? '', 40),
            'basis' => sanitizeOrganizationIntelligenceScalar($metric['basis'] ?? 'derived', 40),
        ];
        if (count($metrics) >= 6) {
            break;
        }
    }

    return $metrics;
}

function sanitizeOrganizationIntelligenceFunnelStages($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $stages = [];
    foreach ($value as $stage) {
        if (!is_array($stage)) {
            continue;
        }
        $stages[] = [
            'label' => sanitizeOrganizationIntelligenceScalar($stage['label'] ?? '', 60),
            'count' => max(0, (int) ($stage['count'] ?? 0)),
            'conversion_rate' => max(0.0, min(100.0, (float) ($stage['conversion_rate'] ?? 0))),
            'drop_off_rate' => max(0.0, min(100.0, (float) ($stage['drop_off_rate'] ?? 0))),
            'is_bottleneck' => !empty($stage['is_bottleneck']),
        ];
        if (count($stages) >= 6) {
            break;
        }
    }

    return $stages;
}

function sanitizeOrganizationIntelligencePageContext($rawContext, ?string $currentPage): array
{
    if ($currentPage !== 'hr_analytics.php' || !is_array($rawContext)) {
        return [];
    }
    if ((string) ($rawContext['page_key'] ?? '') !== 'organization_intelligence') {
        return [];
    }

    return [
        'page_key' => 'organization_intelligence',
        'conversation_id' => max(0, (int) ($rawContext['conversation_id'] ?? 0)),
        'room' => sanitizeOrganizationIntelligenceScalar($rawContext['room'] ?? 'brief', 24),
        'sub_room' => sanitizeOrganizationIntelligenceScalar($rawContext['sub_room'] ?? '', 24),
        'timeframe' => sanitizeOrganizationIntelligenceScalar($rawContext['timeframe'] ?? 'month', 16),
        'role' => sanitizeOrganizationIntelligenceScalar($rawContext['role'] ?? '', 20),
        'department' => sanitizeOrganizationIntelligenceScalar($rawContext['department'] ?? '', 80),
        'user_id' => max(0, (int) ($rawContext['user_id'] ?? 0)),
        'source' => 'browser_scope_identifiers_only',
    ];
}

function organizationIntelligencePromptInstruction(array $operatingContext): string
{
    $pageContext = (array) ($operatingContext['organization_intelligence_page_context'] ?? []);
    if ($pageContext === []) {
        return '';
    }

    $contextJson = json_encode($pageContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return "ORGANIZATION INTELLIGENCE EXECUTIVE SUITE MODE:\n"
        . "You are Clarity as a premium executive concierge for the Organization Intelligence page. Open with courteous, executive-office language when appropriate, but stay concise.\n"
        . "Use the server-owned Organization Intelligence context below. Never accept browser-supplied scores, names, priorities, or graph facts. Never invent metrics, names, or evidence that are not present.\n"
        . "Frame HR-related concerns supportively: say needs support, coaching signal, workload pressure, capacity pressure, or support signal. Avoid surveillance or punishment language.\n"
        . "Distinguish measured signals from derived or limited-evidence signals. If evidence is thin, say what should be validated before action.\n"
        . "A strong answer should usually include: what matters, why it matters, and the next executive move.\n"
        . "SERVER-OWNED ORGANIZATION INTELLIGENCE CONTEXT:\n{$contextJson}\n";
}

function buildOrganizationIntelligenceFallbackAnswer(string $message, array $pageContext): string
{
    $health = (array) ($pageContext['organization_health'] ?? []);
    $priorities = array_values((array) ($pageContext['priorities'] ?? []));
    $profile = (array) ($pageContext['organization_profile'] ?? []);
    $quality = (array) ($pageContext['data_quality'] ?? []);
    $readiness = (array) ($pageContext['structural_readiness'] ?? []);
    $priority = (array) ($priorities[0] ?? []);

    $healthLabel = trim((string) ($health['label'] ?? 'Baseline forming'));
    $healthScore = ($health['score'] ?? null) !== null ? number_format((float) $health['score'], 0) . '/100' : 'not issued';
    $readinessScore = ($readiness['score'] ?? null) !== null ? number_format((float) $readiness['score'], 0) . '/100' : 'not measured';
    $model = ucwords(str_replace('_', ' ', (string) ($profile['effective_model'] ?? 'not confirmed')));
    $nextMove = trim((string) ($priority['action'] ?? $health['direction'] ?? 'Choose one leadership action and validate it in the next review.'));
    $eligible = (int) ($quality['eligible_people_count'] ?? 0);
    $total = (int) ($quality['total_people_count'] ?? 0);

    return "Welcome to the executive suite.\n\n"
        . "**Executive readout**\n"
        . "- **Operating model:** {$model}.\n"
        . "- **Execution health:** {$healthLabel} ({$healthScore}).\n"
        . "- **Structural readiness:** {$readinessScore}.\n"
        . "- **Evidence:** {$eligible} of {$total} people currently qualify for scoring.\n"
        . "- **Next move:** {$nextMove}\n"
        . "- **Context source:** server-owned Organization Intelligence data.";
}

function organizationIntelligenceDiagnosticContext(array $operatingContext): array
{
    $pageContext = (array) ($operatingContext['organization_intelligence_page_context'] ?? []);
    if ($pageContext === []) {
        return $operatingContext;
    }
    unset($operatingContext['organization_intelligence_conversation_history']);
    unset($operatingContext['organization_intelligence_conversation_summary']);
    $profile = (array) ($pageContext['organization_profile'] ?? []);
    $quality = (array) ($pageContext['data_quality'] ?? []);
    $operatingContext['organization_intelligence_page_context'] = [
        'source' => (string) ($pageContext['source'] ?? 'server_owned_organization_intelligence'),
        'schema_version' => (int) ($pageContext['schema_version'] ?? 2),
        'room' => (string) ($pageContext['room'] ?? 'brief'),
        'sub_room' => (string) ($pageContext['sub_room'] ?? ''),
        'effective_model' => (string) ($profile['effective_model'] ?? ''),
        'calculation_version' => (string) ($quality['calculation_version'] ?? ''),
        'eligible_people_count' => (int) ($quality['eligible_people_count'] ?? 0),
        'total_people_count' => (int) ($quality['total_people_count'] ?? 0),
    ];
    return $operatingContext;
}

function hasReadySkillContract(array $operatingContext, string $skillKey): bool
{
    foreach ((array) ($operatingContext['installed_skill_contracts'] ?? []) as $contract) {
        if ((string) ($contract['key'] ?? '') === $skillKey && !empty($contract['readiness']['ready'])) {
            return true;
        }
    }

    return false;
}

function getLeanCanvasBlockMap(): array
{
    return [
        'problem' => ['problem', 'problems'],
        'customer_segments' => ['customer segment', 'customer segments', 'segment', 'segments'],
        'unique_value_proposition' => ['uvp', 'unique value proposition', 'value proposition'],
        'solution' => ['solution', 'solutions'],
        'channels' => ['channel', 'channels'],
        'revenue_streams' => ['revenue stream', 'revenue streams', 'revenue model'],
        'cost_structure' => ['cost structure', 'costs', 'cost model'],
        'key_metrics' => ['key metric', 'key metrics', 'metrics'],
        'unfair_advantage' => ['unfair advantage', 'advantage', 'moat'],
    ];
}

function detectLeanCanvasUpdateCandidate(string $message): ?array
{
    $normalized = trim($message);
    if ($normalized === '') {
        return null;
    }

    foreach (getLeanCanvasBlockMap() as $block => $aliases) {
        $aliasPattern = implode('|', array_map(static fn(string $alias): string => preg_quote($alias, '/'), $aliases));
        if (preg_match('/^\s*(?:set|update|change|replace)\s+(?:my\s+)?(' . $aliasPattern . ')\s+(?:to|as)\s+(.+)\s*$/i', $normalized, $matches)) {
            $value = trim((string) ($matches[2] ?? ''));
            if ($value === '') {
                return null;
            }

            return [
                'block' => $block,
                'label' => leanCanvasBlockLabel($block),
                'value' => $value,
                'confirmation_prompt' => 'Confirm updating ' . leanCanvasBlockLabel($block) . ' to: ' . $value,
            ];
        }
    }

    return null;
}

function leanCanvasBlockLabel(string $block): string
{
    return match ($block) {
        'problem' => 'Problem',
        'customer_segments' => 'Customer Segments',
        'unique_value_proposition' => 'Unique Value Proposition',
        'solution' => 'Solution',
        'channels' => 'Channels',
        'revenue_streams' => 'Revenue Streams',
        'cost_structure' => 'Cost Structure',
        'key_metrics' => 'Key Metrics',
        'unfair_advantage' => 'Unfair Advantage',
        default => ucwords(str_replace('_', ' ', $block)),
    };
}

function clarityLanguageInstruction(array $operatingContext): string
{
    $contract = (array) ($operatingContext['ai_settings']['response_style_contract'] ?? []);

    return trim((string) ($contract['prompt_instruction'] ?? ''));
}

function buildLeanCanvasLegacyPrompt(string $question, array $context, array $operatingContext, array $qualification): string
{
    $ctx = json_encode($context, JSON_PRETTY_PRINT);
    $qualificationJson = json_encode($qualification, JSON_PRETTY_PRINT);
    $operatingContextJson = json_encode($operatingContext, JSON_PRETTY_PRINT);
    $languageInstruction = clarityLanguageInstruction($operatingContext);
    $organizationIntelligenceInstruction = organizationIntelligencePromptInstruction($operatingContext);

    return "You are Clarity in Clarity Journey mode. Answer using the user's Clarity Journey as the primary strategy-development frame; Lean Canvas is one compatibility stage inside it.\n"
        . "Strict skill boundary: business advice is allowed only inside the ready installed skill contracts in AI OPERATING CONTEXT.installed_skill_contracts. Do not provide generic business advice outside those contracts.\n"
        . "Prioritize incomplete journey stages, contradictions, validation experiments, MVP tests, channels, metrics, finance constraints, and execution advice tied to the journey.\n"
        . "When AI OPERATING CONTEXT includes founder_operating_loop_context, recommend the next action inside the loop: founder setup, business model clarity, monthly budget, runway, pricing, first customers, then weekly review.\n"
        . "If the journey is incomplete, ask targeted follow-up questions for the next highest-impact missing stage.\n"
        . "If onboarding_state.is_operational is false, answer the user's question but connect the advice to the most relevant setup gap when it would change the next action. Do not derail unrelated questions into setup-only advice.\n"
        . "If the user appears to be editing a block, suggest a confirmation-friendly update rather than saving directly.\n"
        . "Keep the answer concise, practical, and grounded in the supplied context. Format readable answers with short paragraphs, **bold labels**, and hyphen bullets when useful.\n"
        . ($languageInstruction !== '' ? $languageInstruction . "\n" : '')
        . ($organizationIntelligenceInstruction !== '' ? $organizationIntelligenceInstruction . "\n" : '')
        . "If recommending Marketplace items, recommend at most one plugin or one bundle in a single answer. Do not repeat obvious page counters unless they imply a next action.\n\n"
        . "CONTEXT:\n{$ctx}\n\n"
        . "AI OPERATING CONTEXT:\n{$operatingContextJson}\n\n"
        . "QUALIFICATION STATE:\n{$qualificationJson}\n\n"
        . "QUESTION: {$question}\n\n"
        . "Return ONLY the answer as plain text. Do not use JSON or any structured format.";
}

function buildStandardClarityLegacyPrompt(string $question, array $context, array $operatingContext, array $qualification): string
{
    $ctx = json_encode($context, JSON_PRETTY_PRINT);
    $qualificationJson = json_encode($qualification, JSON_PRETTY_PRINT);
    $operatingContextJson = json_encode($operatingContext, JSON_PRETTY_PRINT);
    $languageInstruction = clarityLanguageInstruction($operatingContext);
    $organizationIntelligenceInstruction = organizationIntelligencePromptInstruction($operatingContext);

    return "You are Clarity, the AI co-founder inside an AI Business Incubator. Answer questions about this website: features, how to use them, where to find things, navigation. Be concise (2-4 sentences).\n"
        . "Strict skill boundary: product help and CRM operational help are allowed. Business advice is allowed only inside ready installed skill contracts in AI OPERATING CONTEXT.installed_skill_contracts; otherwise say which skill/setup is needed instead of giving generic advice.\n"
        . "If founder_operating_loop_context is present, anchor founder advice to the next loop action: setup, business model clarity, monthly budget, runway, pricing, first customers, or weekly review.\n"
        . "Use calm strategic language. Prefer short paragraphs, **bold labels**, and hyphen bullets when they make the answer easier to scan. Prefer Now / Next / Later framing when recommending actions. Avoid competitive/comparative framing unless explicitly requested.\n"
        . ($languageInstruction !== '' ? $languageInstruction . "\n" : '')
        . ($organizationIntelligenceInstruction !== '' ? $organizationIntelligenceInstruction . "\n" : '')
        . "If recommending Marketplace items, recommend at most one plugin or one bundle in a single answer. Do not repeat obvious page counters unless they imply a next action.\n\n"
        . "If onboarding_state.is_operational is false, answer the user's question and, when relevant, connect the answer to the most important missing setup action from onboarding_state.setup_actions. Do not force setup coaching into unrelated answers.\n\n"
        . "CONTEXT:\n{$ctx}\n\n"
        . "AI OPERATING CONTEXT:\n{$operatingContextJson}\n\n"
        . "QUALIFICATION STATE:\n{$qualificationJson}\n\n"
        . "If context quality is low, say what is missing instead of sounding certain.\n\n"
        . "QUESTION: {$question}\n\n"
        . "Return ONLY the answer as plain text. Do not use JSON or any structured format.";
}

function isOperatingBriefRequest(string $message): bool
{
    $normalized = strtolower(trim($message));
    if ($normalized === '') {
        return false;
    }

    return preg_match('/\b(generate|create|make|show|write|draft|refresh)\b.*\b(my\s+)?(operating|workspace)\s+brief\b/i', $normalized) === 1
        || preg_match('/^\s*(my\s+)?(operating|workspace)\s+brief\s*$/i', $normalized) === 1;
}

function handleTaskAutomationCommand(string $message, int $userId, ?string $currentPage = null): array
{
    $runtimeControls = new AIRuntimeControlService();
    $control = $runtimeControls->getEffectiveControl('task_automation');

    $prefs = new UserPreferences();
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    $aiCoachSetup = new AICoachWorkspaceSetupService();
    $aiCoachRuntimeAvailable = $workspaceId > 0
        && $aiCoachSetup->isWorkspaceEnabled($workspaceId)
        && (new PluginRuntimeAuthorizationService())->canAccessRuntimeModule($workspaceId, $userId, WorkspaceSkillCatalogService::SKILL_AI_COACH);
    if (!$aiCoachRuntimeAvailable || empty($aiCoachSetup->getDashboardBriefGate($workspaceId, $userId)['recommendations_ready'])) {
        return ['handled' => false, 'answer' => ''];
    }

    $mode = $prefs->getEffectiveAIGuidanceMode($userId);
    $msg = trim($message);
    $msgLower = strtolower($msg);

    $tasks = new Tasks();

    $isCreateCommand = preg_match('/^(add|create)\s+(a\s+)?task\b/i', $msg) === 1
        || preg_match('/^remind me to\b/i', $msg) === 1;
    $isCompleteCommand = preg_match('/^(complete|mark|done)\s+/i', $msg) === 1
        || preg_match('/\b(accomplished|done)\b/i', $msg) === 1;
    $isDeleteCommand = preg_match('/^(delete|remove)\s+task\b/i', $msg) === 1;
    $isSyncCommand = preg_match('/^(sync|create|generate)\s+(my\s+)?(ai\s+)?tasks\b/i', $msg) === 1;

    if (!$isCreateCommand && !$isCompleteCommand && !$isDeleteCommand && !$isSyncCommand) {
        return ['handled' => false, 'answer' => ''];
    }

    if (in_array((string) ($control['control_mode'] ?? 'normal'), ['paused', 'diagnostics_only'], true)) {
        return [
            'handled' => true,
            'answer' => trim((string) ($control['reason'] ?? 'Task automation is temporarily paused by an administrator.')),
        ];
    }
    if ((string) ($control['control_mode'] ?? 'normal') === 'suggest_only') {
        return [
            'handled' => true,
            'answer' => trim((string) ($control['reason'] ?? 'Task automation is in suggest-only mode. No task mutations are allowed right now.')),
        ];
    }

    // Guardian mode: never allow AI task automation.
    if ($mode === '3') {
        return [
            'handled' => true,
            'answer' => 'AI task automation is disabled in Guardian Mode. You can still add tasks manually in Tasks.',
        ];
    }

    if ($isSyncCommand) {
        $automation = new AITaskAutomationService();
        $sync = $automation->manualSyncTasks($userId);
        $created = (int) ($sync['created_count'] ?? 0);
        $classifier = new AIOutcomeClassifier();
        if ($created > 0) {
            $answer = "Done. I created {$created} AI task(s) from your current priorities.";
            recordClarityGuidanceOutcome(
                $userId,
                $currentPage,
                $msg,
                $answer,
                'sync_ai_tasks',
                $classifier->classifyCoachOutcome([
                    'action_taken' => true,
                    'created_count' => $created,
                ])
            );
            return [
                'handled' => true,
                'answer' => $answer,
            ];
        }
        $answer = 'No new AI tasks were created. You may already have matching open tasks.';
        recordClarityGuidanceOutcome(
            $userId,
            $currentPage,
            $msg,
            $answer,
            'sync_ai_tasks',
            $classifier->classifyCoachOutcome([
                'action_taken' => false,
            ])
        );
        return [
            'handled' => true,
            'answer' => $answer,
        ];
    }

    // Create task from chat
    if ($isCreateCommand) {
        $title = $msg;
        $title = preg_replace('/^(add|create)\s+(a\s+)?task\s*[:\-]?\s*/i', '', $title);
        $title = preg_replace('/^remind me to\s*/i', '', $title);
        $title = trim((string) $title);

        if ($title === '') {
            return ['handled' => true, 'answer' => 'Please provide a task title. Example: "Add task: Follow up with ACME"'];
        }

        $taskId = $tasks->create([
            'title' => $title,
            'description' => '[AI-CHAT] Created from Clarity chat',
            'created_by' => $userId,
            'assigned_to' => $userId,
            'status' => 'pending',
            'priority' => 'medium',
            'due_date' => date('Y-m-d 18:00:00'),
            'metadata_json' => [
                'source_surface' => 'clarity_chat',
                'source_recommendation_type' => 'chat_task_command',
                'task_intent' => 'ops',
                'auto_complete_allowed' => false,
                'protected_from_auto_complete' => false,
            ],
        ]);

        $answer = "Task created (#{$taskId}): {$title}";
        $guidanceRunId = recordClarityGuidanceOutcome(
            $userId,
            $currentPage,
            $msg,
            $answer,
            'create_task',
            (new AIOutcomeClassifier())->classifyCoachOutcome([
                'action_taken' => true,
                'task_id' => $taskId,
            ])
        );

        Database::execute(
            "UPDATE tasks SET metadata_json = ? WHERE id = ?",
            [
                json_encode([
                    'source_surface' => 'clarity_chat',
                    'source_recommendation_type' => 'chat_task_command',
                    'task_intent' => 'ops',
                    'auto_complete_allowed' => false,
                    'protected_from_auto_complete' => false,
                    'guidance_run_id' => $guidanceRunId > 0 ? $guidanceRunId : null,
                    'predicted_confidence' => 1.0,
                    'policy_decision' => 'allow',
                ]),
                $taskId,
            ]
        );

        return [
            'handled' => true,
            'answer' => $answer,
        ];
    }

    // Helper: locate one of user's active tasks by id or by title phrase.
    $task = null;
    if (preg_match('/\btask\s*#?(\d+)\b/i', $msg, $m)) {
        $taskId = (int) $m[1];
        $candidate = $tasks->getById($taskId);
        if ($candidate && ((int) ($candidate['assigned_to'] ?? 0) === $userId || (int) ($candidate['created_by'] ?? 0) === $userId)) {
            $task = $candidate;
        }
    } else {
        $phrase = $msg;
        $phrase = preg_replace('/^(complete|mark|done|delete|remove)\s+/i', '', $phrase);
        $phrase = preg_replace('/\b(task|as done|done|accomplished)\b/i', '', (string) $phrase);
        $phrase = trim((string) $phrase, " \t\n\r\0\x0B\"'");
        if ($phrase !== '') {
            $list = $tasks->getAll([
                'assigned_to' => $userId,
                'search' => $phrase,
            ], 5, 0);
            foreach ($list as $row) {
                $status = (string) ($row['status'] ?? '');
                if (!in_array($status, ['completed', 'cancelled'], true)) {
                    $task = $row;
                    break;
                }
            }
        }
    }

    if (!$task) {
        return [
            'handled' => true,
            'answer' => 'I could not find that task in your active list. Use the task ID or a clearer title.',
        ];
    }

    $taskId = (int) ($task['id'] ?? 0);
    $taskTitle = (string) ($task['title'] ?? 'Task');

    if ($isDeleteCommand) {
        $tasks->delete($taskId);
        $answer = "Task removed: {$taskTitle}";
        recordClarityGuidanceOutcome(
            $userId,
            $currentPage,
            $msg,
            $answer,
            'delete_task',
            ['outcome_label' => 'rejected', 'outcome_score' => 0.0, 'metadata' => ['task_id' => $taskId]]
        );
        return ['handled' => true, 'answer' => $answer];
    }

    if ($isCompleteCommand) {
        $taskMeta = [];
        if (!empty($task['metadata_json'])) {
            $taskMeta = json_decode((string) $task['metadata_json'], true) ?: [];
        }
        $taskMeta['completion_source'] = 'manual_chat';
        $tasks->update($taskId, ['status' => 'completed', 'metadata_json' => $taskMeta]);

        // Remove immediately if user asked remove/delete or it was AI auto-generated.
        $deleteAfterComplete = (strpos($msgLower, 'remove') !== false || strpos($msgLower, 'delete') !== false)
            || AITaskAutomationService::isAIAutoTask($task);
        if ($deleteAfterComplete) {
            $tasks->delete($taskId);
            $answer = "Task completed and removed: {$taskTitle}";
            recordClarityGuidanceOutcome(
                $userId,
                $currentPage,
                $msg,
                $answer,
                'complete_task',
                ['outcome_label' => 'completed', 'outcome_score' => 1.0, 'metadata' => ['task_id' => $taskId, 'removed' => true]]
            );
            return ['handled' => true, 'answer' => $answer];
        }
        $answer = "Task marked completed: {$taskTitle}";
        recordClarityGuidanceOutcome(
            $userId,
            $currentPage,
            $msg,
            $answer,
            'complete_task',
            ['outcome_label' => 'completed', 'outcome_score' => 1.0, 'metadata' => ['task_id' => $taskId]]
        );
        return ['handled' => true, 'answer' => $answer];
    }

    return ['handled' => false, 'answer' => ''];
}

try {
    $clarityControl = (new AIRuntimeControlService())->getEffectiveControl('clarity_chat');
    if (in_array((string) ($clarityControl['control_mode'] ?? 'normal'), ['paused', 'diagnostics_only'], true)) {
        echo json_encode([
            'answer' => trim((string) ($clarityControl['reason'] ?? 'Clarity is temporarily paused by an administrator.')),
            'metadata' => $leanCanvasMetadata,
            'diagnostics' => [
                'runtime_control' => $clarityControl,
                'decision' => (string) ($clarityControl['control_mode'] ?? 'paused') === 'diagnostics_only' ? 'suggest_only' : 'blocked',
                'effective_mode' => 'operations',
            ],
        ]);
        exit;
    }

    $organizationIntelligenceCanManage = $organizationIntelligencePageContext === [] || Authorization::can('hr.analytics.manage', $user);
    $handled = $organizationIntelligenceCanManage
        ? handleTaskAutomationCommand($message, $userId, $currentPage)
        : ['handled' => false, 'answer' => ''];
    if (!empty($handled['handled'])) {
        echo json_encode([
            'answer' => $handled['answer'],
            'metadata' => $leanCanvasMetadata,
        ]);
        exit;
    }

    if (isOperatingBriefRequest($message)) {
        $workspaceId = (new AnalyticsWorkspaceService())->requireAnalyticsWorkspaceId();
        $brief = (new WorkspaceOperatingBriefService())->generate($workspaceId, $userId);
        $metadata = array_merge($leanCanvasMetadata, [
            'mode_variant' => 'operating_brief',
            'operating_brief_generated' => true,
        ]);
        echo json_encode([
            'answer' => (string) ($brief['markdown'] ?? 'I generated the workspace operating brief.'),
            'metadata' => $metadata,
            'diagnostics' => [
                'mode_variant' => 'operating_brief',
                'operating_brief_generated' => true,
                'readiness' => $brief['readiness'] ?? [],
            ],
        ]);
        exit;
    }

    $operatingContext = (new AIOperatingContextService())->buildForSurface($userId, 'clarity_chat', [
        'current_page' => $currentPage,
    ]);
    $workspaceId = (int) ($operatingContext['identity']['workspace_id'] ?? WorkspaceContext::currentWorkspaceId() ?? 0);
    if ($organizationIntelligencePageContext !== []) {
        $organizationIntelligenceScope = $organizationIntelligencePageContext;
        if (
            !empty($questionIntent['references_self'])
            && empty($organizationIntelligenceScope['user_id'])
        ) {
            $organizationIntelligenceScope['user_id'] = $userId;
        }
        $organizationIntelligenceScope['question_intent'] = $questionIntent;
        $organizationIntelligenceResult = (new OrganizationIntelligenceContextService())->build(
            $workspaceId,
            $userId,
            $organizationIntelligenceScope
        );
        $organizationIntelligencePageContext = (array) $organizationIntelligenceResult['context'];
        $organizationIntelligenceConversationService = new OrganizationIntelligenceConversationService();
        $organizationIntelligenceConversation = $organizationIntelligenceConversationService->currentOrCreate(
            $workspaceId,
            $userId,
            !empty($organizationIntelligenceScope['conversation_id']) ? (int) $organizationIntelligenceScope['conversation_id'] : null
        );
        $latestOrganizationSnapshot = (new OrganizationIntelligenceSnapshotService())->latest($workspaceId);
        $organizationIntelligenceContextSnapshotId = !empty($latestOrganizationSnapshot['id']) ? (int) $latestOrganizationSnapshot['id'] : null;
        $conversationId = (int) ($organizationIntelligenceConversation['id'] ?? 0);
        $room = (string) ($organizationIntelligenceResult['room'] ?? 'brief');
        $subRoom = (string) ($organizationIntelligenceResult['sub_room'] ?? '');
        $scope = (array) ($organizationIntelligenceResult['filters'] ?? []);
        $organizationIntelligenceConversationService->appendScopeDividerIfChanged(
            $conversationId,
            $workspaceId,
            $userId,
            $room,
            $subRoom,
            $scope,
            $organizationIntelligenceContextSnapshotId
        );
        $organizationIntelligenceMessageId = $organizationIntelligenceConversationService->append(
            $conversationId,
            $workspaceId,
            $userId,
            'user',
            $message,
            $room,
            $subRoom,
            $scope,
            $organizationIntelligenceContextSnapshotId
        );
        $operatingContext['organization_intelligence_page_context'] = $organizationIntelligencePageContext;
        $operatingContext['organization_intelligence_conversation_history'] = $organizationIntelligenceConversationService->history(
            $conversationId,
            $workspaceId,
            $userId,
            20
        );
        $operatingContext['organization_intelligence_conversation_summary'] = (string) ($organizationIntelligenceConversation['rolling_summary'] ?? '');
    } else {
        $clarityConversationService = new ClarityConversationService();
        $requestedConversationId = max(0, (int) ($input['conversation_id'] ?? 0));
        $clarityConversation = $clarityConversationService->currentOrCreate(
            $workspaceId,
            $userId,
            $requestedConversationId > 0 ? $requestedConversationId : null
        );
        $clarityMessageId = $clarityConversationService->append(
            (int) ($clarityConversation['id'] ?? 0),
            $workspaceId,
            $userId,
            'user',
            $message,
            (string) ($currentPage ?? 'dashboard.php'),
            ['question_intent' => $questionIntent]
        );
        $clarityConversationContext = $clarityConversationService->promptContext(
            $clarityConversation,
            $workspaceId,
            $userId
        );
        $operatingContext['clarity_conversation_context'] = $clarityConversationContext;
    }
    $clarityPageContext = (new ClarityPageContextService())->build($workspaceId, $userId, $currentPage, $operatingContext);
    $operatingContext['clarity_page_context'] = $clarityPageContext;
    $explanationContext = (new ClarityExplanationContextService())->build(
        $message,
        $currentPage,
        $questionIntent,
        $clarityPageContext,
        $organizationIntelligencePageContext
    );
    $operatingContext['clarity_question_intent'] = $questionIntent;
    $operatingContext['clarity_explanation_context'] = $explanationContext;
    $leanCanvasEnabled = hasReadySkillContract($operatingContext, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS);
    $leanCanvasMetadata = buildLeanCanvasMetadata($leanCanvasEnabled, $leanCanvasCompleteness, $leanCanvasMissingBlocks);

    if ($leanCanvasEnabled) {
        $canvasUpdateCandidate = detectLeanCanvasUpdateCandidate($message);
        if ($canvasUpdateCandidate !== null) {
            echo json_encode([
                'answer' => 'I can update your ' . strtolower($canvasUpdateCandidate['label']) . '. Please confirm below and I will save it.',
                'metadata' => array_merge($leanCanvasMetadata, [
                    'clarity_page_context_attached' => $clarityPageContext !== [],
                    'clarity_page_context_kind' => (string) ($clarityPageContext['opening_insight']['kind'] ?? ''),
                ]),
                'canvas_update_candidate' => $canvasUpdateCandidate,
            ]);
            exit;
        }
    }

    $roleProfile = (new AIRoleProfileService())->buildProfile($userId, $operatingContext);
    $userWorkContextService = new AIUserWorkContextService();
    $userWorkContext = $userWorkContextService->buildContext($userId, 'clarity_chat', $operatingContext);
    $operatingContext['role_profile'] = $roleProfile;
    $operatingContext['user_work_context'] = $userWorkContext;
    $contextBuilder = new WebsiteAssistantContext();
    $context = $contextBuilder->build($userId, $currentPage, $operatingContext, $userWorkContext, $clarityPageContext);
    if ($organizationIntelligencePageContext !== []) {
        $context['organization_intelligence'] = $organizationIntelligencePageContext;
    }
    $guidanceOperatingContext = organizationIntelligenceDiagnosticContext($operatingContext);
    $policy = new AIQualificationPolicyService();
    $decision = $policy->evaluateAdviceEligibility($operatingContext);
    $skillRouteDecision = (new AIAdviceDomainRouterService())->evaluate($message, $operatingContext, $questionIntent);
    $operatingContext['skill_route_decision'] = $skillRouteDecision;
    if (($skillRouteDecision['skill_route_decision'] ?? '') === 'blocked_skill_required') {
        $recommendedKey = (string) ($skillRouteDecision['recommended_skill_key'] ?? '');
        $recommendedLabel = $recommendedKey !== ''
            ? (string) ((new WorkspaceSkillCatalogService())->findForWorkspace($recommendedKey, (int) ($operatingContext['identity']['workspace_id'] ?? 0))['label'] ?? $recommendedKey)
            : 'a matching workspace skill';
        $answer = 'I can help with product navigation and CRM operations now, but business advice needs a ready installed skill for this domain. '
            . 'Install or complete setup for ' . $recommendedLabel . ' in Workspace Marketplace, then ask again with that context.';
        $guidanceRunId = $policy->logGuidanceRun(
            $userId,
            'clarity_chat',
            (string) ($operatingContext['qualification_state']['effective_mode'] ?? '1'),
            $decision,
            array_merge($guidanceOperatingContext, [
                'message' => $organizationIntelligencePageContext !== [] ? '[redacted organization intelligence message]' : $message,
                'organization_intelligence_conversation_id' => !empty($organizationIntelligenceConversation['id']) ? (int) $organizationIntelligenceConversation['id'] : null,
                'organization_intelligence_context_source' => (string) ($organizationIntelligencePageContext['source'] ?? ''),
            ]),
            ['answer' => $organizationIntelligencePageContext !== [] ? '[redacted organization intelligence answer]' : $answer, 'skill_route_decision' => $skillRouteDecision]
        );
        if (!empty($organizationIntelligenceConversation['id'])) {
            $organizationIntelligenceAssistantMessageId = (new OrganizationIntelligenceConversationService())->append(
                (int) $organizationIntelligenceConversation['id'],
                $workspaceId,
                $userId,
                'assistant',
                $answer,
                (string) ($organizationIntelligencePageContext['room'] ?? 'brief'),
                (string) ($organizationIntelligencePageContext['sub_room'] ?? ''),
                (array) ($organizationIntelligencePageContext['scope'] ?? []),
                $organizationIntelligenceContextSnapshotId,
                $guidanceRunId
            );
        } elseif ($clarityConversationService !== null && !empty($clarityConversation['id'])) {
            $clarityAssistantMessageId = $clarityConversationService->append(
                (int) $clarityConversation['id'],
                $workspaceId,
                $userId,
                'assistant',
                $answer,
                (string) ($currentPage ?? 'dashboard.php'),
                ['question_intent' => $questionIntent],
                $guidanceRunId
            );
        }
        echo json_encode([
            'answer' => $answer,
            'conversation_id' => !empty($organizationIntelligenceConversation['id'])
                ? (int) $organizationIntelligenceConversation['id']
                : (!empty($clarityConversation['id']) ? (int) $clarityConversation['id'] : null),
            'message_id' => $organizationIntelligenceAssistantMessageId ?? $clarityAssistantMessageId,
            'context_snapshot_id' => $organizationIntelligenceContextSnapshotId,
            'metadata' => array_merge($leanCanvasMetadata, $skillRouteDecision, [
                'clarity_page_context_attached' => $clarityPageContext !== [],
                'clarity_page_context_kind' => (string) ($clarityPageContext['opening_insight']['kind'] ?? ''),
                'question_intent' => $questionIntent,
            ]),
            'diagnostics' => [
                'guidance_run_id' => $guidanceRunId,
                'skill_route_decision' => $skillRouteDecision,
                'effective_mode' => $operatingContext['qualification_state']['effective_mode'] ?? '1',
                'decision' => $decision['decision'] ?? 'blocked_skill_required',
                'clarity_page_context_attached' => $clarityPageContext !== [],
                'clarity_page_context_kind' => (string) ($clarityPageContext['opening_insight']['kind'] ?? ''),
                'question_intent' => $questionIntent,
            ],
        ]);
        exit;
    }
    $taskCompletion = new AITaskCompletionService();
    $autoCompletedTasks = $taskCompletion->scanForCompletionEvidence($userId);
    $operatingContext['task_state']['explicit_evidence_available'] = !empty($autoCompletedTasks);

    $aiService = new AIService();
    $contextAssembly = new AIContextAssemblyService();
    $promptKey = $leanCanvasEnabled ? 'clarity_question_answer_lean_canvas' : 'clarity_question_answer';
    $bundle = $contextAssembly->buildContextBundle('clarity_chat', $promptKey, [
        'user_id' => $userId,
        'current_page' => $currentPage,
        'question' => $message,
        'website_context' => $context,
        'operating_context' => $operatingContext,
        'clarity_page_context' => $clarityPageContext,
        'organization_intelligence_context' => $organizationIntelligencePageContext,
        'explanation_context' => $explanationContext,
        'conversation_context' => $clarityConversationContext,
        'question_intent' => $questionIntent,
        'qualification' => $decision,
    ]);
    $retrievalQuality = new AIRetrievalQualityService();
    $bundleQuality = $retrievalQuality->scoreBundle($bundle);
    $resolvedPrompt = $aiService->buildPromptFromRegistry('clarity_chat', $promptKey, $bundle, [
        'question' => $message,
        'legacy_context' => $context,
        'legacy_prompt' => $leanCanvasEnabled
            ? buildLeanCanvasLegacyPrompt($message, $context, $operatingContext, $decision)
            : buildStandardClarityLegacyPrompt($message, $context, $operatingContext, $decision),
    ]);
    $answer = $aiService->processWithPrompt('website_assistant_question', $resolvedPrompt, [
        'question' => $message,
        'message' => $message,
        'surface' => 'clarity_chat',
        'reasoning_effort' => (string) ($questionIntent['reasoning_effort'] ?? 'low'),
        'question_intent' => $questionIntent,
    ]);

    $answer = unwrapAssistantAnswer($answer);
    $usedDeterministicFallback = $answer === '';
    if ($answer === '' && $organizationIntelligencePageContext !== []) {
        $answer = buildOrganizationIntelligenceFallbackAnswer($message, $organizationIntelligencePageContext);
    }
    $answer = $answer !== '' ? $answer : "I couldn't generate an answer. Try rephrasing your question.";
    $answer = unwrapAssistantAnswer($answer);
    $responseStyleContract = (array) ($operatingContext['ai_settings']['response_style_contract'] ?? []);
    $presentation = (new ClarityResponsePresentationService())->present($answer, $responseStyleContract, $questionIntent);
    $answer = (string) $presentation['answer'];
    $aiStatus = $clarityOperatorControlsEnabled
        ? (new AIExecutionStatusService())->present($aiService->getLastProviderStatus(), [
            'surface' => 'clarity_chat',
            'fallback' => $usedDeterministicFallback,
            'source' => $usedDeterministicFallback ? 'deterministic_fallback' : '',
            'message' => $usedDeterministicFallback
                ? 'Clarity used a safe fallback because the AI provider did not return a usable answer.'
                : '',
        ])
        : null;

    $guidanceRunId = $policy->logGuidanceRun(
        $userId,
        'clarity_chat',
        (string) ($operatingContext['qualification_state']['effective_mode'] ?? '1'),
        $decision,
        array_merge($guidanceOperatingContext, [
            'prompt_key' => $promptKey,
            'prompt_version' => (int) ($resolvedPrompt['prompt_version'] ?? 0),
            'context_bundle_summary' => $contextAssembly->summarizeBundle($bundle),
            'context_bundle_quality' => $bundleQuality,
            'role_profile' => $roleProfile,
            'user_work_context' => $userWorkContext,
            'clarity_page_context_attached' => $clarityPageContext !== [],
            'clarity_page_context_kind' => (string) ($clarityPageContext['opening_insight']['kind'] ?? ''),
            'organization_intelligence_context_attached' => $organizationIntelligencePageContext !== [],
            'organization_intelligence_conversation_id' => !empty($organizationIntelligenceConversation['id']) ? (int) $organizationIntelligenceConversation['id'] : null,
            'organization_intelligence_context_source' => (string) ($organizationIntelligencePageContext['source'] ?? ''),
            'question_intent' => $questionIntent,
            'reasoning_effort' => (string) ($questionIntent['reasoning_effort'] ?? 'low'),
            'explanation_context_attached' => $explanationContext !== [],
            'clarity_conversation_id' => !empty($clarityConversation['id']) ? (int) $clarityConversation['id'] : null,
        ]),
        [
            'answer' => $organizationIntelligencePageContext !== [] ? '[redacted organization intelligence answer]' : $answer,
            'prompt_key' => $promptKey,
            'prompt_version' => (int) ($resolvedPrompt['prompt_version'] ?? 0),
            'context_bundle_quality' => $bundleQuality,
            'role_profile' => $roleProfile,
            'role_summary' => $roleProfile['summary'] ?? '',
            'user_work_context_summary' => $userWorkContextService->summarize($userWorkContext),
            'mode_variant' => $leanCanvasMetadata['mode_variant'],
            'lean_canvas_enabled' => $leanCanvasEnabled,
            'lean_canvas_completeness' => $leanCanvasCompleteness,
            'lean_canvas_missing_blocks' => $leanCanvasMissingBlocks,
            'language_level' => (string) ($responseStyleContract['label'] ?? ''),
            'clarity_page_context_attached' => $clarityPageContext !== [],
            'clarity_page_context_kind' => (string) ($clarityPageContext['opening_insight']['kind'] ?? ''),
            'organization_intelligence_context_attached' => $organizationIntelligencePageContext !== [],
            'question_intent' => $questionIntent,
            'explanation_context_attached' => $explanationContext !== [],
            'presentation_guard_applied' => (bool) $presentation['guard_applied'],
        ]
    );
    $messageHash = $guidanceRunId > 0 ? buildClarityMessageHash($guidanceRunId, $message, $answer) : '';
    if (!empty($organizationIntelligenceConversation['id'])) {
        $organizationIntelligenceAssistantMessageId = (new OrganizationIntelligenceConversationService())->append(
            (int) $organizationIntelligenceConversation['id'],
            $workspaceId,
            $userId,
            'assistant',
            $answer,
            (string) ($organizationIntelligencePageContext['room'] ?? 'brief'),
            (string) ($organizationIntelligencePageContext['sub_room'] ?? ''),
            (array) ($organizationIntelligencePageContext['scope'] ?? []),
            $organizationIntelligenceContextSnapshotId,
            $guidanceRunId,
            $messageHash
        );
    } elseif ($clarityConversationService !== null && !empty($clarityConversation['id'])) {
        $clarityAssistantMessageId = $clarityConversationService->append(
            (int) $clarityConversation['id'],
            $workspaceId,
            $userId,
            'assistant',
            $answer,
            (string) ($currentPage ?? 'dashboard.php'),
            ['question_intent' => $questionIntent],
            $guidanceRunId,
            $messageHash
        );
    }
    $publicDiagnostics = [
        'operator_controls_enabled' => $clarityOperatorControlsEnabled,
    ];
    if ($clarityOperatorControlsEnabled) {
        $publicDiagnostics = array_merge($publicDiagnostics, [
            'surface' => 'clarity_chat',
            'ai_status' => $aiStatus,
            'guidance_run_id' => $guidanceRunId,
            'message_hash' => $messageHash,
            'skill_route_decision' => $skillRouteDecision,
            'prompt_key' => $promptKey,
            'prompt_version' => (int) ($resolvedPrompt['prompt_version'] ?? 0),
            'context_bundle_quality' => $bundleQuality,
            'effective_mode' => $operatingContext['qualification_state']['effective_mode'] ?? '1',
            'context_quality_score' => $decision['context_quality_score'],
            'decision' => $decision['decision'],
            'goal_relevance_score' => $operatingContext['goal_state']['goal_relevance_score'] ?? 0,
            'missing_context_flags' => $operatingContext['missing_context_flags'],
            'auto_completed_tasks' => count($autoCompletedTasks),
            'mode_variant' => $leanCanvasMetadata['mode_variant'],
            'lean_canvas_enabled' => $leanCanvasEnabled,
            'lean_canvas_completeness' => $leanCanvasCompleteness,
            'lean_canvas_missing_blocks' => $leanCanvasMissingBlocks,
            'response_style_contract' => $responseStyleContract,
            'role_profile' => $roleProfile,
            'role_summary' => $roleProfile['summary'] ?? '',
            'user_work_context_summary' => $userWorkContextService->summarize($userWorkContext),
            'clarity_page_context_attached' => $clarityPageContext !== [],
            'clarity_page_context_kind' => (string) ($clarityPageContext['opening_insight']['kind'] ?? ''),
            'organization_intelligence_context_attached' => $organizationIntelligencePageContext !== [],
            'question_intent' => $questionIntent,
            'reasoning_effort' => (string) ($questionIntent['reasoning_effort'] ?? 'low'),
            'explanation_context_attached' => $explanationContext !== [],
            'clarity_conversation_context_attached' => $clarityConversationContext !== [],
            'provider' => $aiService->getLastProviderStatus(),
            'presentation_guard_applied' => (bool) $presentation['guard_applied'],
            'presentation_removed_section_count' => (int) $presentation['removed_section_count'],
            'role_threshold_recommendations' => $decision['role_threshold_recommendations']
                ?? (new AIThresholdUpdateService())->getRoleAwareThresholdRecommendation('clarity_chat', 'advice', $userId, $roleProfile),
            'role_decision_effects' => [
                'context_bundle_includes_user_context' => true,
                'role_slice' => $roleProfile['role_profile'] ?? 'sales_rep',
                'prompt_bias' => $roleProfile['prompt_bias'] ?? [],
            ],
        ]);
    }

    echo json_encode([
        'answer' => $answer,
        'ai_status' => $aiStatus,
        'conversation_id' => !empty($organizationIntelligenceConversation['id'])
            ? (int) $organizationIntelligenceConversation['id']
            : (!empty($clarityConversation['id']) ? (int) $clarityConversation['id'] : null),
        'message_id' => $organizationIntelligenceAssistantMessageId ?? $clarityAssistantMessageId,
        'context_snapshot_id' => $organizationIntelligenceContextSnapshotId,
        'metadata' => array_merge($leanCanvasMetadata, $skillRouteDecision, [
            'operator_controls_enabled' => $clarityOperatorControlsEnabled,
            'language_level' => (string) ($responseStyleContract['label'] ?? ''),
            'clarity_page_context_attached' => $clarityPageContext !== [],
            'clarity_page_context_kind' => (string) ($clarityPageContext['opening_insight']['kind'] ?? ''),
            'organization_intelligence_context_attached' => $organizationIntelligencePageContext !== [],
            'question_intent' => $questionIntent,
            'explanation_context_attached' => $explanationContext !== [],
        ]),
        'diagnostics' => $publicDiagnostics,
    ]);
} catch (\Throwable $e) {
    error_log('WebsiteAssistant ask error: ' . $e->getMessage());
    if ($e instanceof \InvalidArgumentException) {
        http_response_code(422);
        echo json_encode([
            'error' => $e->getMessage(),
            'answer' => '',
            'ai_status' => ['status' => 'rejected', 'source' => 'scope_validation'],
        ]);
        exit;
    }
    $organizationIntelligenceFallback = ($organizationIntelligencePageContext ?? []) !== [];
    if (!$organizationIntelligenceFallback) {
        http_response_code(500);
    }
    $fallbackAnswer = $organizationIntelligenceFallback
        ? buildOrganizationIntelligenceFallbackAnswer($message ?? '', $organizationIntelligencePageContext)
        : "Sorry, something went wrong. Please try again.";
    echo json_encode([
        'error' => $organizationIntelligenceFallback ? null : 'Failed to get answer',
        'answer' => $fallbackAnswer,
        'ai_status' => (new AIExecutionStatusService())->present([], [
            'surface' => 'clarity_chat',
            'fallback' => true,
            'source' => 'deterministic_fallback',
            'blocked_reason' => 'request_failed',
            'message' => 'Clarity used a safe fallback after the request failed.',
        ]),
        'metadata' => array_merge($leanCanvasMetadata ?? buildLeanCanvasMetadata(false), [
            'clarity_page_context_attached' => !empty($clarityPageContext ?? []),
            'clarity_page_context_kind' => (string) (($clarityPageContext['opening_insight']['kind'] ?? '') ?: ''),
            'organization_intelligence_context_attached' => $organizationIntelligenceFallback,
            'mode_variant' => $organizationIntelligenceFallback ? 'organization_intelligence_fallback' : (($leanCanvasMetadata['mode_variant'] ?? null) ?: 'standard'),
        ]),
        'diagnostics' => [
            'source' => $organizationIntelligenceFallback ? 'organization_intelligence_context_fallback' : 'chat_error',
            'clarity_page_context_attached' => !empty($clarityPageContext ?? []),
            'clarity_page_context_kind' => (string) (($clarityPageContext['opening_insight']['kind'] ?? '') ?: ''),
        ],
    ]);
}
