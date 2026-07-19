<?php
require_once __DIR__ . '/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\AICoachWorkspaceSetupService;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\StartupJourneyService;
use CRM\Services\VideoBrandOverlayUi;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceLaunchChecklistService;
use CRM\Services\WorkspaceMarketplaceAccessService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . Auth::loginUrl(null, Auth::currentAuthState() === 'expired'));
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$isProtectedDemoJourney = false;
try {
    $isProtectedDemoJourney = (new DemoSessionScopeService())->activeSession($workspaceId) !== null;
} catch (Throwable $e) {
    $isProtectedDemoJourney = false;
}
$catalog = new WorkspaceSkillCatalogService();
$installer = new WorkspaceSkillInstallService($catalog);
$marketplaceAccess = new WorkspaceMarketplaceAccessService($catalog, $installer);
$pageExplainers = new MarketplacePageExplainerService();
$canViewJourney = Authorization::isSuperAdmin($user)
    || Authorization::can('workspace.skills.view', $user)
    || Authorization::can('workspace.skills.manage', $user);
if (!$canViewJourney) {
    Authorization::requirePermission('workspace.skills.view');
}

$canManageJourney = !$isProtectedDemoJourney && (Authorization::isSuperAdmin($user) || Authorization::can('workspace.skills.manage', $user));
$canViewFounderLoop = $isProtectedDemoJourney || Authorization::isSuperAdmin($user) || Authorization::can('founder_loop.view', $user);
$startupJourneyInstalled = $isProtectedDemoJourney || ($workspaceId > 0 && $installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS));
$aiCoachSetup = new AICoachWorkspaceSetupService($installer);
$aiCoachInstalled = $workspaceId > 0 && $aiCoachSetup->isInstalled($workspaceId);
$aiCoachWorkspaceEnabled = $aiCoachInstalled && $aiCoachSetup->isWorkspaceEnabled($workspaceId);
$aiCoachCompletionUrl = $aiCoachInstalled && $aiCoachWorkspaceEnabled
    ? 'dashboard.php#ai-coach'
    : 'workspace_skills.php?module=' . urlencode(WorkspaceSkillCatalogService::SKILL_AI_COACH) . '&setup_tab=workspace_readiness#setup';
$aiCoachCompletionLabel = $aiCoachInstalled && $aiCoachWorkspaceEnabled ? 'Open AI Coach' : 'Set up AI Coach';
if ($isProtectedDemoJourney) {
    $aiCoachCompletionUrl = 'dashboard.php#ai-coach';
    $aiCoachCompletionLabel = 'Open Clarity AI';
}
$startupJourneyAccess = $workspaceId > 0
    ? $marketplaceAccess->accessForModule($workspaceId, $userId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS)
    : [];
if ($isProtectedDemoJourney) {
    $startupJourneyAccess['can_configure'] = true;
}
if (!$startupJourneyInstalled || empty($startupJourneyAccess['can_configure'])) {
    header('Location: workspace_skills.php?module=' . urlencode(WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS));
    exit;
}

$journeyService = new StartupJourneyService();
$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageJourney) {
    try {
        if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid security token.');
        }
        $action = (string) ($_POST['startup_action'] ?? '');
        if ($action === 'save_stage') {
            $stageKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) ($_POST['startup_stage_key'] ?? '')))) ?? '';
            $stageResponses = is_array($_POST['startup_response'] ?? null) ? (array) $_POST['startup_response'] : [];
            $completeStage = (string) ($_POST['startup_stage_status'] ?? '') === 'completed';
            $journeyService->saveStage(
                $workspaceId,
                $userId,
                $stageKey,
                $stageResponses,
                (string) ($_POST['startup_stage_notes'] ?? ''),
                $completeStage
            );
            if (!$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS)) {
                $installer->install($workspaceId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, $userId, ['source' => 'startup_journey_hub']);
            }
            $success = $completeStage ? 'Stage completed.' : 'Draft saved.';
            $_GET['stage'] = $stageKey;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$journey = $journeyService->getJourney($workspaceId, $userId);
$stages = (array) ($journey['stages'] ?? []);
$progress = (array) ($journey['progress'] ?? []);
if ($isProtectedDemoJourney && $stages !== []) {
    $demoStageSummaries = [
        'customer_discovery' => [
            'prompt' => 'Riverside decision-makers, urgency, and buying signals are already captured.',
            'summary' => 'Amina and Rose need a revised proposal that covers finish choice, split terms, reminders, and a Friday installation window.',
        ],
        'jobs_to_be_done' => [
            'prompt' => 'The job is clear: keep a high-intent client moving without losing context.',
            'summary' => 'Riverside is hiring the workspace to coordinate proposal revision, procurement context, follow-up, and response timing.',
        ],
        'value_proposition' => [
            'prompt' => 'The value proposition is connected to the live Riverside follow-up.',
            'summary' => 'One workspace turns WhatsApp, email, triage, draft replies, tasks, targets, and contact intelligence into a single next action.',
        ],
        'lean_canvas' => [
            'prompt' => 'Business assumptions are saved and feeding Clarity AI.',
            'summary' => 'The demo company wins by responding faster to qualified project leads and making every promise inspectable.',
        ],
        'mvp' => [
            'prompt' => 'The operating loop is proven by the Riverside lead journey.',
            'summary' => 'A single lead generates a private thread, assistant draft, follow-up task, response target update, and contact intelligence.',
        ],
        'go_to_market' => [
            'prompt' => 'The demo workspace is ready to show a complete revenue follow-up motion.',
            'summary' => 'Riverside is the sales story: qualify, reply, revise the proposal, and protect the follow-up deadline.',
        ],
        'aarrr' => [
            'prompt' => 'Acquisition, activation, retention, referral, and revenue signals are measurable.',
            'summary' => 'The command center tracks response speed, qualified leads, proposal follow-up, and conversion momentum.',
        ],
        'okrs' => [
            'prompt' => 'Execution targets are tied to tasks and communication outcomes.',
            'summary' => 'The response-time target, Riverside task, and notification recap create a measurable operating cadence.',
        ],
    ];
    foreach ($stages as $stageKey => &$stage) {
        $fields = (array) ($stage['fields'] ?? []);
        $responses = (array) ($stage['responses'] ?? []);
        foreach ($fields as $fieldKey => $fieldLabel) {
            if (trim((string) ($responses[$fieldKey] ?? '')) === '') {
                $responses[$fieldKey] = ($demoStageSummaries[$stageKey]['summary'] ?? 'Protected demo evidence is already configured for this stage.');
            }
        }
        $totalFields = max(1, count($fields));
        $stage['responses'] = $responses;
        $stage['status'] = 'completed';
        $stage['filled_fields'] = $totalFields;
        $stage['total_fields'] = $totalFields;
        $stage['completed_at'] = $stage['completed_at'] ?? date('Y-m-d H:i:s', strtotime('-1 hour'));
        $stage['prompt'] = (string) ($demoStageSummaries[$stageKey]['prompt'] ?? ($stage['prompt'] ?? 'Protected demo stage is complete.'));
        $stage['notes'] = (string) ($demoStageSummaries[$stageKey]['summary'] ?? ($stage['notes'] ?? ''));
        $stage['readiness'] = [
            'status' => 'ready_to_complete',
            'label' => 'Complete',
            'completion_risk' => 'low',
            'strong_fields' => $totalFields,
            'summary' => (string) ($demoStageSummaries[$stageKey]['summary'] ?? 'Configured and ready.'),
            'next_missing_item' => 'No gap. This stage is complete in the protected demo.',
            'field_quality' => array_fill_keys(array_keys($fields), ['score' => 100, 'status' => 'strong']),
            'checklist' => [
                ['label' => 'Evidence captured', 'detail' => 'The Riverside scenario includes concrete buyer context.', 'ok' => true],
                ['label' => 'AI context ready', 'detail' => 'Clarity AI can use this stage for next-best-action guidance.', 'ok' => true],
            ],
        ];
    }
    unset($stage);
    $journey['stages'] = $stages;
    $journey['current_stage_key'] = isset($stages['go_to_market']) ? 'go_to_market' : (string) array_key_first($stages);
    $progress = [
        'total' => count($stages),
        'completed' => count($stages),
        'percent' => 100,
    ];
    $journey['progress'] = $progress;
}
$progressTotal = (int) ($progress['total'] ?? count($stages));
$progressCompleted = (int) ($progress['completed'] ?? 0);
$journeyComplete = $progressTotal > 0 && $progressCompleted >= $progressTotal;
$journeyProgressLabel = $journeyComplete
    ? 'Journey complete'
    : $progressCompleted . '/' . $progressTotal . ' stages complete';
$journeyProgressChipClass = $journeyComplete ? 'is-good' : ($progressCompleted > 0 ? 'is-active' : 'is-warning');
$launchChecklistService = new WorkspaceLaunchChecklistService();
$workspaceLaunchChecklist = $launchChecklistService->summary($workspaceId);
$workspaceLaunchItems = (array) ($workspaceLaunchChecklist['items'] ?? []);
if ($workspaceLaunchItems === []) {
    $workspaceLaunchItems = array_map(static function (array $definition): array {
        return $definition + ['task_status' => 'pending', 'complete' => false];
    }, $launchChecklistService->definitions());
}
if ($isProtectedDemoJourney) {
    $workspaceLaunchItems = [
        [
            'title' => 'Email and WhatsApp channels',
            'description' => 'Healthy and simulated-first for the Riverside demo.',
            'url' => 'workspace_skills.php',
            'icon' => 'fa-solid fa-message',
            'task_status' => 'completed',
            'complete' => true,
        ],
        [
            'title' => 'AI drafting and triage',
            'description' => 'Assistant suggestions are connected to private demo threads.',
            'url' => 'dashboard.php#ai-coach',
            'icon' => 'fa-solid fa-wand-magic-sparkles',
            'task_status' => 'completed',
            'complete' => true,
        ],
        [
            'title' => 'Targets and follow-up tasks',
            'description' => 'Response targets and next steps are ready for the Riverside story.',
            'url' => 'targets.php',
            'icon' => 'fa-solid fa-bullseye',
            'task_status' => 'completed',
            'complete' => true,
        ],
    ];
    $workspaceLaunchChecklist = [
        'items' => $workspaceLaunchItems,
        'complete_count' => count($workspaceLaunchItems),
        'open_count' => 0,
    ];
}
$requestedStage = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) ($_GET['stage'] ?? '')))) ?? '';
$currentStageKey = isset($stages[$requestedStage])
    ? $requestedStage
    : (string) ($journey['current_stage_key'] ?? 'customer_discovery');
$currentStage = (array) ($stages[$currentStageKey] ?? reset($stages) ?: []);
$csrf = Security::getCsrfToken();
$startupJourneyExplainer = $pageExplainers->getActive(MarketplacePageExplainerService::PAGE_STARTUP_JOURNEY);
$startupJourneyExplainerVideo = trim((string) ($startupJourneyExplainer['video_url'] ?? ''));
$startupJourneyAsset = static function (string $path): string {
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path) === 1 || str_starts_with($path, '/')) {
        return $path;
    }
    if (str_starts_with($path, 'assets/')) {
        $path = substr($path, 7);
    }
    if (str_starts_with($path, 'uploads/')) {
        return function_exists('publicUrl') ? publicUrl('../' . $path) : '../' . $path;
    }

    return function_exists('assetUrl') ? assetUrl($path) : 'assets/' . ltrim($path, '/');
};
$startupJourneyExplainerVideoUrl = $startupJourneyAsset($startupJourneyExplainerVideo);

function startupJourneyHasAny(array $journey, string $stageKey): bool
{
    $responses = (array) ($journey['stages'][$stageKey]['responses'] ?? []);
    foreach ($responses as $value) {
        if (trim((string) $value) !== '') {
            return true;
        }
    }
    return false;
}

function startupJourneyFilled(array $journey, string $stageKey): int
{
    return (int) ($journey['stages'][$stageKey]['filled_fields'] ?? 0);
}

function startupJourneyGuidance(array $journey, string $stageKey): array
{
    $stage = (array) ($journey['stages'][$stageKey] ?? []);
    $status = (string) ($stage['status'] ?? 'not_started');
    $cards = [];
    $customerEvidence = trim((string) ($journey['stages']['customer_discovery']['responses']['evidence'] ?? ''));
    $mvpStarted = startupJourneyHasAny($journey, 'mvp');
    $gtmStarted = startupJourneyHasAny($journey, 'go_to_market');
    $leanStarted = startupJourneyHasAny($journey, 'lean_canvas');
    $okrsStarted = startupJourneyHasAny($journey, 'okrs');
    $jtbdWeak = startupJourneyFilled($journey, 'jobs_to_be_done') < 2;
    $valueWeak = startupJourneyFilled($journey, 'value_proposition') < 3;
    $aarrWeak = startupJourneyFilled($journey, 'aarrr') < 3;

    if ($stageKey === 'mvp' && $mvpStarted && $customerEvidence === '') {
        $cards[] = [
            'tone' => 'warning',
            'title' => 'Customer signal gap',
            'meaning' => 'The MVP is taking shape before the customer learning is strong enough.',
            'action' => 'Add what real customers said or did before spending more on the test.',
        ];
    }
    if ($stageKey === 'go_to_market' && $gtmStarted && $aarrWeak) {
        $cards[] = [
            'tone' => 'warning',
            'title' => 'Channel test missing',
            'meaning' => 'The launch plan needs a measurable acquisition and activation baseline.',
            'action' => 'Define the first channel test and the activation moment it should create.',
        ];
    }
    if ($stageKey === 'lean_canvas' && $leanStarted && ($jtbdWeak || $valueWeak)) {
        $cards[] = [
            'tone' => 'warning',
            'title' => 'Business model before customer truth',
            'meaning' => 'Lean Canvas is useful, but Jobs To Be Done and value detail are still thin.',
            'action' => 'Tighten the job, pains, gains, and why the customer would care.',
        ];
    }
    if ($stageKey === 'okrs' && $okrsStarted && $aarrWeak) {
        $cards[] = [
            'tone' => 'warning',
            'title' => 'Growth baseline missing',
            'meaning' => 'The OKRs need AARRR metrics so progress can be measured.',
            'action' => 'Define acquisition, activation, retention, referral, and revenue signals first.',
        ];
    }

    if ($cards === []) {
        $cards[] = match ($status) {
            'completed' => [
                'tone' => 'good',
                'title' => 'Stage ready',
                'meaning' => 'This stage has enough context to guide AI recommendations.',
                'action' => 'Move to the next incomplete stage or revisit this one when your thinking changes.',
            ],
            'draft' => [
                'tone' => 'info',
                'title' => 'Draft in progress',
                'meaning' => 'You have started this stage, but it is not locked as complete yet.',
                'action' => 'Fill the missing fields, make the answers specific, then mark the stage complete.',
            ],
            default => [
                'tone' => 'info',
                'title' => 'Next founder step',
                'meaning' => 'This stage is the current gap in the startup operating context.',
                'action' => (string) ($stage['prompt'] ?? 'Capture the working assumptions for this stage.'),
            ],
        };
    }

    return $cards;
}

function startupJourneyGlobalAlerts(array $journey): array
{
    $alerts = [];
    if (startupJourneyHasAny($journey, 'mvp') && trim((string) ($journey['stages']['customer_discovery']['responses']['evidence'] ?? '')) === '') {
        $alerts[] = 'MVP thinking is ahead of customer discovery signals.';
    }
    if (startupJourneyHasAny($journey, 'go_to_market') && startupJourneyFilled($journey, 'aarrr') < 3) {
        $alerts[] = 'Go-to-market needs AARRR metrics before scaling spend.';
    }
    if (startupJourneyHasAny($journey, 'lean_canvas') && (startupJourneyFilled($journey, 'jobs_to_be_done') < 2 || startupJourneyFilled($journey, 'value_proposition') < 3)) {
        $alerts[] = 'Lean Canvas should be grounded in stronger JTBD and value proposition context.';
    }
    if (startupJourneyHasAny($journey, 'okrs') && startupJourneyFilled($journey, 'aarrr') < 3) {
        $alerts[] = 'OKRs need a growth metric baseline.';
    }
    return $alerts;
}

$alerts = startupJourneyGlobalAlerts($journey);
$currentGuidance = startupJourneyGuidance($journey, $currentStageKey);
$nextStageKey = (string) ($journey['current_stage_key'] ?? $currentStageKey);
$stageShortLabels = [
    'customer_discovery' => 'Discovery',
    'jobs_to_be_done' => 'JTBD',
    'value_proposition' => 'Value',
    'lean_canvas' => 'Canvas',
    'mvp' => 'MVP',
    'go_to_market' => 'GTM',
    'aarrr' => 'AARRR',
    'okrs' => 'OKRs',
];
$stageTooltips = [
    'customer_discovery' => 'Customer Discovery - confirm the problem with real people before scaling.',
    'jobs_to_be_done' => 'Jobs To Be Done - what the customer is hiring the product to accomplish.',
    'value_proposition' => 'Value Proposition Canvas - connect jobs, pains, gains, and the offer.',
    'lean_canvas' => 'Lean Canvas - summarize the business-model assumptions AI and CRM use.',
    'mvp' => 'Minimum Viable Product - smallest test that proves demand.',
    'go_to_market' => 'Go-To-Market Strategy - who to target, what to say, and how to sell.',
    'aarrr' => 'AARRR: Acquisition, Activation, Retention, Referral, Revenue - find where growth is working or breaking.',
    'okrs' => 'OKRs: Objectives and Key Results - turn the journey into measurable execution.',
];
$fieldTooltips = [
    'customer_discovery' => [
        'target_customer' => ['means' => 'The exact person, role, company type, or buyer group you are learning from.', 'include' => 'Segment, role, size, location, and the situation that makes them relevant.', 'example' => 'Solo agency founders in Nairobi with 3-10 active client accounts.'],
        'interview_count' => ['means' => 'The number of real customer conversations completed so far.', 'include' => 'Only calls, meetings, chats, or direct responses from real prospects.', 'example' => '12 interviews with agency founders, 4 from referrals and 8 from LinkedIn outreach.'],
        'observed_problem' => ['means' => 'The problem as customers described it, not just your internal assumption.', 'include' => 'Plain customer language, repeated complaints, and context around when it happens.', 'example' => 'They said leads go cold because follow-up is scattered across WhatsApp, email, and memory.'],
        'evidence' => ['means' => 'Proof that the problem is real and worth solving.', 'include' => 'Quotes, repeated patterns, paid tests, usage data, or lost-money examples.', 'example' => '9 of 12 founders said missed follow-up cost them at least one deal last month.'],
        'riskiest_assumption' => ['means' => 'The belief that could break the idea if it is false.', 'include' => 'One clear assumption that must be tested next.', 'example' => 'Founders will pay before the product integrates deeply with WhatsApp.'],
    ],
    'jobs_to_be_done' => [
        'job_statement' => ['means' => 'The progress the customer is trying to make when they use or buy this.', 'include' => 'Use a when/I want/so I can structure if helpful.', 'example' => 'When new leads arrive, I want follow-up organized so I can close deals without losing track.'],
        'triggers' => ['means' => 'The situation that makes the customer start looking for a better way.', 'include' => 'Events, pressure, deadlines, failures, or emotional moments.', 'example' => 'A hot lead asks for pricing and then disappears after no timely follow-up.'],
        'current_alternatives' => ['means' => 'What customers use or do today instead of your product.', 'include' => 'Tools, spreadsheets, manual work, people, or doing nothing.', 'example' => 'Google Sheets, WhatsApp starred messages, notebooks, and lightweight CRM tools.'],
        'desired_outcomes' => ['means' => 'The result that would make customers feel they made progress.', 'include' => 'Business results, time saved, risk reduced, or confidence gained.', 'example' => 'Every warm lead has a next step, owner, and follow-up date.'],
        'success_criteria' => ['means' => 'How customers judge whether the job was done well.', 'include' => 'Specific measures or signals they would personally care about.', 'example' => 'Fewer missed follow-ups, more demos booked, and a clear weekly pipeline view.'],
    ],
    'value_proposition' => [
        'customer_jobs' => ['means' => 'Tasks, goals, or progress the customer is trying to achieve.', 'include' => 'Functional, social, and emotional jobs where relevant.', 'example' => 'Track leads, follow up on time, and feel in control of revenue.'],
        'pains' => ['means' => 'Obstacles, risks, costs, and frustrations in the current way.', 'include' => 'Time loss, money loss, confusion, anxiety, or failed outcomes.', 'example' => 'Leads are scattered, follow-up is inconsistent, and owners cannot see what is slipping.'],
        'gains' => ['means' => 'Benefits or wins the customer wants from a better solution.', 'include' => 'Desired outcomes, speed, confidence, revenue, or status.', 'example' => 'A clean pipeline, fewer lost opportunities, and faster close decisions.'],
        'products_services' => ['means' => 'The products, services, or features you are offering.', 'include' => 'Only the parts that directly support the customer job.', 'example' => 'AI-guided CRM workspace with pipeline, reminders, and follow-up suggestions.'],
        'pain_relievers' => ['means' => 'How your offer reduces the pains customers already feel.', 'include' => 'Specific mechanisms that remove friction or risk.', 'example' => 'Automatic reminders and next-step prompts reduce missed follow-ups.'],
        'gain_creators' => ['means' => 'How your offer creates the outcomes customers want.', 'include' => 'Clear value moments and improvements customers can feel.', 'example' => 'A weekly revenue view helps founders act on the most valuable opportunities first.'],
    ],
    'lean_canvas' => [
        'problem' => ['means' => 'The top problems worth solving first.', 'include' => 'One to three urgent problems grounded in customer evidence.', 'example' => 'Founder-led teams lose deals because follow-up and pipeline ownership are unclear.'],
        'customer_segments' => ['means' => 'The first customer groups most likely to need and buy this.', 'include' => 'Narrow early adopters before broad markets.', 'example' => 'Small marketing agencies with founder-led sales and repeat inbound leads.'],
        'unique_value_proposition' => ['means' => 'The clear promise that explains why this matters now.', 'include' => 'A direct outcome, audience, and differentiator.', 'example' => 'An AI operating layer that helps founders stop losing revenue opportunities.'],
        'solution' => ['means' => 'The smallest believable solution to the stated problem.', 'include' => 'Core workflow, feature, or service that creates the first value.', 'example' => 'Lead capture, next-step reminders, and AI follow-up coaching.'],
        'channels' => ['means' => 'How customers will discover, trust, and try the offer.', 'include' => 'Specific acquisition and distribution paths.', 'example' => 'Founder communities, LinkedIn outreach, agency partnerships, and demos.'],
        'revenue_streams' => ['means' => 'How the business earns money.', 'include' => 'Pricing model, payment timing, and main revenue types.', 'example' => 'Monthly SaaS subscription plus paid onboarding for teams.'],
        'cost_structure' => ['means' => 'The main costs to build, deliver, and grow the business.', 'include' => 'Product, AI, hosting, support, sales, marketing, and delivery costs.', 'example' => 'Hosting, AI usage, support, founder sales time, and content/outreach spend.'],
        'key_metrics' => ['means' => 'Numbers that prove the business is learning and growing.', 'include' => 'Activity, activation, revenue, retention, and quality measures.', 'example' => 'Customer interviews, demo bookings, paid pilots, follow-ups completed, churn.'],
        'unfair_advantage' => ['means' => 'A strength that is difficult for competitors to copy quickly.', 'include' => 'Data, distribution, relationships, domain expertise, or workflow depth.', 'example' => 'Founder-growth workflows trained from real CRM, finance, and execution context.'],
    ],
    'mvp' => [
        'mvp_hypothesis' => ['means' => 'The core belief the MVP must prove or disprove.', 'include' => 'A testable statement linked to demand or value.', 'example' => 'Agency founders will pay for AI-guided follow-up before full automation exists.'],
        'smallest_test' => ['means' => 'The lowest-cost version that can create real evidence.', 'include' => 'A concierge test, landing page, demo, spreadsheet, or thin feature.', 'example' => 'Run a manual follow-up review for 5 founders and measure paid interest.'],
        'required_features' => ['means' => 'Only the features needed to run the MVP test.', 'include' => 'Must-have functions; exclude nice-to-have polish.', 'example' => 'Lead list, next action, reminder date, and AI follow-up suggestion.'],
        'success_metric' => ['means' => 'The number or signal that proves the test worked.', 'include' => 'One primary metric tied to demand, value, or payment.', 'example' => '3 of 10 demo users convert to paid pilots within 14 days.'],
        'experiment_budget' => ['means' => 'The time or money limit before deciding what was learned.', 'include' => 'Budget cap, timebox, and stop/continue rule.', 'example' => 'Spend no more than $300 and 2 weeks before reviewing results.'],
    ],
    'go_to_market' => [
        'beachhead_segment' => ['means' => 'The first narrow market where you will focus launch effort.', 'include' => 'A segment specific enough to target and message clearly.', 'example' => 'Marketing agency founders with 5-25 employees and active inbound leads.'],
        'message' => ['means' => 'The core promise customers should understand quickly.', 'include' => 'Problem, outcome, and why your approach is different.', 'example' => 'Stop losing warm leads with an AI CRM that tells you what to do next.'],
        'channels' => ['means' => 'Where the first customers will come from.', 'include' => 'Specific platforms, partners, communities, events, or outbound paths.', 'example' => 'LinkedIn founder outreach, local agency groups, referrals, and demo webinars.'],
        'sales_motion' => ['means' => 'The steps from first contact to payment.', 'include' => 'Outreach, qualification, demo, proposal, pilot, and close steps.', 'example' => 'DM, discovery call, live demo, 14-day pilot, paid monthly plan.'],
        'launch_plan' => ['means' => 'The sequence of actions for the first market push.', 'include' => 'Timeline, channel actions, owner, and expected output.', 'example' => 'Week 1: interview 10 founders. Week 2: book 5 demos. Week 3: close 2 pilots.'],
        'conversion_goal' => ['means' => 'The target result that tells you the launch test worked.', 'include' => 'A measurable booking, pilot, activation, or revenue target.', 'example' => 'Convert 20% of demos into paid pilots by the end of the month.'],
    ],
    'aarrr' => [
        'acquisition' => ['means' => 'How customers first discover or enter your funnel.', 'include' => 'Channels, campaigns, referrals, or inbound sources.', 'example' => 'LinkedIn outreach, founder referrals, and agency community posts.'],
        'activation' => ['means' => 'The first moment where a user feels real value.', 'include' => 'A behavior that signals the product clicked for them.', 'example' => 'They add leads and complete their first AI-guided follow-up plan.'],
        'retention' => ['means' => 'Why customers come back or keep paying.', 'include' => 'Recurring value, habits, saved time, or revenue impact.', 'example' => 'Weekly pipeline reviews reveal missed revenue and next actions.'],
        'referral' => ['means' => 'Why customers would share or recommend the product.', 'include' => 'Shareable results, social proof, incentives, or collaboration loops.', 'example' => 'Founders share a cleaner pipeline report with their team or peers.'],
        'revenue' => ['means' => 'How usage turns into paid revenue.', 'include' => 'Pricing triggers, upgrade moments, plan limits, or services.', 'example' => 'Users pay monthly when the system manages active opportunities and follow-up.'],
    ],
    'okrs' => [
        'objective' => ['means' => 'One meaningful goal for the next execution cycle.', 'include' => 'A clear, qualitative outcome that focuses the work.', 'example' => 'Prove paid demand among marketing agency founders.'],
        'key_result_1' => ['means' => 'A measurable result that proves progress toward the objective.', 'include' => 'A number, deadline, and outcome rather than a task.', 'example' => 'Interview 30 agency founders by June 30.'],
        'key_result_2' => ['means' => 'A second measurable result that balances the objective.', 'include' => 'A different signal such as demos, activation, or revenue.', 'example' => 'Book 10 qualified product demos from the target segment.'],
        'key_result_3' => ['means' => 'A third measurable result that confirms business value.', 'include' => 'A conversion, retention, revenue, or learning target.', 'example' => 'Convert 3 paid pilot customers with at least $500 total revenue.'],
        'review_cadence' => ['means' => 'How often you will review results and adjust execution.', 'include' => 'Meeting rhythm, owner, and what gets reviewed.', 'example' => 'Review every Friday: interviews, demos, paid pilots, blockers, and next actions.'],
    ],
];
$stageIcons = [
    'customer_discovery' => 'fa-comments',
    'jobs_to_be_done' => 'fa-briefcase',
    'value_proposition' => 'fa-gem',
    'lean_canvas' => 'fa-table-columns',
    'mvp' => 'fa-flask',
    'go_to_market' => 'fa-bullhorn',
    'aarrr' => 'fa-chart-line',
    'okrs' => 'fa-bullseye',
];
$stageKeys = array_keys($stages);
$currentStageIndex = array_search($currentStageKey, $stageKeys, true);
$currentStageIndex = $currentStageIndex === false ? 0 : (int) $currentStageIndex;
$previousStageKey = $stageKeys[$currentStageIndex - 1] ?? '';
$linearNextStageKey = $stageKeys[$currentStageIndex + 1] ?? '';
$primaryGuidance = (array) ($currentGuidance[0] ?? []);
$currentStatus = (string) ($currentStage['status'] ?? 'not_started');
$currentFields = (array) ($currentStage['fields'] ?? []);
$currentReadiness = (array) ($currentStage['readiness'] ?? []);
$currentReadyToComplete = (string) ($currentReadiness['status'] ?? '') === 'ready_to_complete';
$showCompleteStage = $currentStatus !== 'completed' && $currentReadyToComplete;
$showReopenStage = $currentStatus === 'completed';
$currentFieldQuality = (array) ($currentReadiness['field_quality'] ?? []);
$currentChecklist = (array) ($currentReadiness['checklist'] ?? []);
$currentSummary = (string) ($currentReadiness['summary'] ?? 'No saved answers yet.');
$nextMissingItem = (string) ($currentReadiness['next_missing_item'] ?? 'Start with the first unanswered field.');
$readinessLabel = (string) ($currentReadiness['label'] ?? 'Not started');
$visibleFields = array_slice($currentFields, 0, 3, true);
$extraFields = array_slice($currentFields, 3, null, true);
$linearNextStageUrl = $linearNextStageKey !== '' ? 'startup_journey.php?stage=' . urlencode($linearNextStageKey) : '';
$strongFields = (int) ($currentReadiness['strong_fields'] ?? 0);
$answerBrief = $strongFields > 0
    ? $strongFields . ' strong answer' . ($strongFields === 1 ? '' : 's')
    : 'Add a real example, number, quote, or customer signal.';
$exampleFields = [];
foreach ((array) ($fieldTooltips[$currentStageKey] ?? []) as $fieldKey => $tooltip) {
    $example = trim((string) ($tooltip['example'] ?? ''));
    if ($example !== '') {
        $exampleFields[$fieldKey] = [
            'label' => (string) ($currentFields[$fieldKey] ?? $fieldKey),
            'example' => $example,
        ];
    }
}
$renderQuestionTooltip = static function (string $key, string $text, string $label): void {
    $tooltipId = 'journey-question-tip-' . (preg_replace('/[^a-z0-9_-]+/', '-', strtolower($key)) ?: 'help');
    ?>
    <span class="journey-tooltip-wrap">
        <button class="journey-tooltip" type="button" data-journey-tooltip-trigger aria-label="<?php echo htmlspecialchars($label); ?>" aria-describedby="<?php echo htmlspecialchars($tooltipId); ?>" aria-expanded="false">?</button>
        <span class="journey-tooltip-bubble" id="<?php echo htmlspecialchars($tooltipId); ?>" role="tooltip"><?php echo htmlspecialchars($text); ?></span>
    </span>
    <?php
};
$renderJourneyField = static function (string $stageKey, string $field, string $label, array $stage, array $fieldTooltips, array $fieldQuality, bool $canManageJourney): void {
    $fieldSlug = (string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower($stageKey . '-' . $field));
    $fieldId = 'startup-field-' . $fieldSlug;
    $tooltipId = 'startup-field-tip-' . $fieldSlug;
    $tooltip = (array) ($fieldTooltips[$stageKey][$field] ?? []);
    $value = (string) (($stage['responses'][$field] ?? '') ?: '');
    $quality = (array) ($fieldQuality[$field] ?? ['label' => 'Empty', 'tone' => 'muted', 'message' => 'Add a specific answer.']);
    $qualityTone = preg_replace('/[^a-z0-9_-]+/', '-', strtolower((string) ($quality['tone'] ?? 'muted'))) ?: 'muted';
    ?>
    <label class="journey-field" for="<?php echo htmlspecialchars($fieldId); ?>">
        <span class="journey-field-label-row">
            <span><?php echo htmlspecialchars($label); ?></span>
            <span class="journey-field-tools">
                <span class="journey-quality is-<?php echo htmlspecialchars($qualityTone); ?>" title="<?php echo htmlspecialchars((string) ($quality['message'] ?? 'Answer quality.')); ?>"><?php echo htmlspecialchars((string) ($quality['label'] ?? 'Empty')); ?></span>
                <?php if ($canManageJourney): ?>
                    <button class="journey-field-tool" type="button" data-draft-field="<?php echo htmlspecialchars($field); ?>" data-field-label="<?php echo htmlspecialchars($label); ?>" title="AI draft assist"><i class="fa-solid fa-wand-magic-sparkles"></i></button>
                <?php endif; ?>
                <?php if ($tooltip !== []): ?>
                    <span class="journey-field-help-wrap">
                        <span class="journey-field-help" tabindex="0" aria-label="Help for <?php echo htmlspecialchars($label); ?>" aria-describedby="<?php echo htmlspecialchars($tooltipId); ?>">?</span>
                        <span class="journey-field-tooltip" id="<?php echo htmlspecialchars($tooltipId); ?>" role="tooltip">
                            <span><strong>Means:</strong> <?php echo htmlspecialchars((string) ($tooltip['means'] ?? '')); ?></span>
                            <span><strong>Include:</strong> <?php echo htmlspecialchars((string) ($tooltip['include'] ?? '')); ?></span>
                            <?php if ((string) ($tooltip['example'] ?? '') !== ''): ?><span><strong>Example:</strong> <?php echo htmlspecialchars((string) $tooltip['example']); ?></span><?php endif; ?>
                        </span>
                    </span>
                <?php endif; ?>
            </span>
        </span>
        <textarea id="<?php echo htmlspecialchars($fieldId); ?>" name="startup_response[<?php echo htmlspecialchars($field); ?>]" <?php echo $tooltip !== [] ? 'aria-describedby="' . htmlspecialchars($tooltipId) . '"' : ''; ?>><?php echo htmlspecialchars($value); ?></textarea>
    </label>
    <?php
};

$pageTitle = 'Clarity Journey - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<style>
.journey-shell{box-sizing:border-box;max-width:1320px;margin:0 auto;padding:2.75rem clamp(1.1rem,3vw,2.5rem) 3rem;display:grid;gap:1.15rem;color:#172033;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif}
.journey-hero{text-align:center;display:grid;justify-items:center;gap:.9rem;padding:1.1rem 0 1.35rem}
.journey-hero-status{display:flex;align-items:center;justify-content:center;gap:.7rem;flex-wrap:wrap}.journey-progress-battery{position:relative;box-sizing:border-box;width:142px;height:58px;border:3px solid #172033;border-radius:17px;background:#fff;padding:5px;display:flex;align-items:center}.journey-progress-battery:after{content:"";position:absolute;right:-8px;top:18px;width:5px;height:18px;border-radius:0 8px 8px 0;background:#172033}.journey-progress-fill{height:100%;min-width:34px;border-radius:10px;background:#fbbf24;display:flex;align-items:center;justify-content:center;color:#172033;font-weight:600;transition:width .2s ease}.journey-progress-score{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:600;pointer-events:none}
.journey-hero h1{margin:0;color:#1f1f1f;font-size:clamp(2.35rem,6vw,4.8rem);font-weight:200;letter-spacing:-.04em;line-height:1.05}.journey-hero-subtitle{max-width:760px;margin:0;color:#6b7280;font-size:clamp(.98rem,1.8vw,1.22rem);font-weight:300;line-height:1.55}.journey-actions{display:flex;gap:.5rem;align-items:center;justify-content:center;flex-wrap:wrap;margin-top:.1rem}.journey-icon-btn{height:2.25rem;min-width:2.25rem;display:inline-flex;align-items:center;justify-content:center;gap:.4rem;border:1px solid #dbe4f0;border-radius:9px;background:#fff;color:#334155;text-decoration:none;font-size:.82rem;font-weight:600;padding:0 .7rem;cursor:pointer}.journey-icon-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;box-shadow:0 8px 18px rgba(37,99,235,.15)}.journey-icon-btn:hover{border-color:#cbd5e1;box-shadow:0 8px 18px rgba(15,23,42,.06)}.journey-guide-action{position:relative;border-color:rgba(37,99,235,.24);box-shadow:0 8px 18px rgba(37,99,235,.10);animation:journeyGuidePulse 2.8s ease-in-out infinite}.journey-guide-action i{animation:journeyGuideIcon 2.8s ease-in-out infinite}@keyframes journeyGuidePulse{0%,100%{transform:translateY(0);box-shadow:0 8px 18px rgba(37,99,235,.10)}45%{transform:translateY(-1px);border-color:rgba(37,99,235,.42);box-shadow:0 14px 28px rgba(37,99,235,.20),0 0 0 5px rgba(37,99,235,.07)}}@keyframes journeyGuideIcon{0%,100%{transform:scale(1)}45%{transform:scale(1.12)}}@media(prefers-reduced-motion:reduce){.journey-guide-action,.journey-guide-action i{animation:none}}
.journey-completion-panel{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:1rem;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;padding:1rem 1.1rem;box-shadow:0 14px 30px rgba(37,99,235,.08)}.journey-completion-panel[hidden]{display:none}.journey-completion-kicker{color:#1d4ed8;font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase}.journey-completion-title{margin:.15rem 0 0;color:#172033;font-size:1.2rem;font-weight:600;line-height:1.2}.journey-completion-copy{margin:.28rem 0 0;color:#475569;font-size:.92rem;line-height:1.45}.journey-completion-actions{display:flex;align-items:center;justify-content:flex-end;gap:.5rem;flex-wrap:wrap}.journey-completion-action{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;min-height:2.35rem;border:1px solid #2563eb;border-radius:9px;background:#2563eb;color:#fff;text-decoration:none;font-size:.84rem;font-weight:700;padding:0 .85rem;white-space:nowrap;box-shadow:0 10px 20px rgba(37,99,235,.16)}.journey-completion-action:hover{border-color:#1d4ed8;background:#1d4ed8;box-shadow:0 14px 26px rgba(37,99,235,.22)}.journey-completion-action.secondary{background:#fff;color:#1d4ed8;box-shadow:none}.journey-completion-action.secondary:hover{background:#f8fbff;color:#1e40af}
.journey-chip{display:inline-flex;align-items:center;gap:.32rem;width:fit-content;border:1px solid #e2e8f0;border-radius:999px;background:#f8fafc;color:#64748b;font-size:.72rem;font-weight:600;line-height:1;padding:.36rem .58rem;white-space:nowrap}.journey-chip.is-warning{border-color:#fde68a;background:#fef3c7;color:#a16207}.journey-chip.is-good{border-color:#bbf7d0;background:#f0fdf4;color:#166534}.journey-chip.is-active{border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8}.journey-autosave-status{display:inline-flex;align-items:center;gap:.35rem;color:#64748b;font-size:.78rem;font-weight:600}.journey-autosave-status:before{content:"";width:.44rem;height:.44rem;border-radius:999px;background:#94a3b8}.journey-autosave-status.is-saving:before,.journey-autosave-status.is-pending:before{background:#2563eb}.journey-autosave-status.is-saved:before{background:#16a34a}.journey-autosave-status.is-error{color:#b91c1c}.journey-autosave-status.is-error:before{background:#ef4444}
.journey-icon-btn.danger{border-color:#fecaca;background:#fff;color:#b91c1c}.journey-icon-btn.danger:hover{border-color:#fca5a5;background:#fff5f5;color:#991b1b}.journey-save-recovery{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-top:.75rem;border:1px solid #fecaca;border-radius:10px;background:#fff5f5;color:#991b1b;padding:.65rem .75rem;font-size:.82rem;font-weight:600}.journey-save-recovery[hidden]{display:none}.journey-save-recovery span{flex:1 1 220px}.journey-save-recovery .journey-icon-btn{height:2rem;border-color:#fecaca}.journey-draft-meta{display:flex;align-items:center;gap:.42rem;flex-wrap:wrap}.journey-draft-meta .journey-chip{font-size:.66rem}.journey-draft-review{margin:.2rem 0 0;color:#64748b;font-size:.78rem;line-height:1.4}
.journey-rail{display:flex;justify-content:center;gap:.32rem;overflow:visible;padding:1.9rem 0 .35rem}.journey-rail-link{position:relative;display:inline-flex;align-items:center;gap:.32rem;min-width:fit-content;border:1px solid #e5edf6;border-radius:999px;background:#fff;color:#64748b;text-decoration:none;font-weight:500;font-size:.74rem;padding:.36rem .54rem}.journey-rail-link:before{content:"";width:.38rem;height:.38rem;border-radius:999px;background:#cbd5e1}.journey-rail-link i{display:none}.journey-rail-link.is-active{border-color:#bfdbfe;color:#1d4ed8;background:#f8fbff}.journey-rail-link.is-active:before{background:#2563eb}.journey-rail-link.is-completed:before{background:#16a34a}.journey-rail-link.is-draft:before{background:#0ea5e9}.journey-rail-link:focus-visible{outline:2px solid rgba(37,99,235,.28);outline-offset:3px}.journey-rail-tooltip{box-sizing:border-box;position:absolute;left:50%;bottom:calc(100% + .55rem);z-index:50;width:max-content;max-width:260px;transform:translate(-50%,.25rem);opacity:0;visibility:hidden;pointer-events:none;border:1px solid #e2e8f0;border-radius:10px;background:#172033;color:#fff;box-shadow:0 14px 34px rgba(15,23,42,.18);padding:.55rem .65rem;font-size:.76rem;font-weight:400;line-height:1.35;text-align:left;white-space:normal}.journey-rail-tooltip:after{content:"";position:absolute;left:50%;top:100%;transform:translateX(-50%);border:.38rem solid transparent;border-top-color:#172033}.journey-rail-link:hover .journey-rail-tooltip,.journey-rail-link:focus .journey-rail-tooltip,.journey-rail-link:focus-visible .journey-rail-tooltip{opacity:1;visibility:visible;transform:translate(-50%,0)}
.journey-alert-strip{display:flex;justify-content:center;gap:.45rem;overflow-x:auto}.journey-alert{display:inline-flex;align-items:center;gap:.38rem;border:1px solid #fde68a;background:#fffbeb;color:#a16207;border-radius:999px;padding:.38rem .62rem;font-size:.76rem;font-weight:600;white-space:nowrap}
.journey-focus-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(250px,.32fr);gap:1rem;align-items:stretch;margin-top:.25rem}.journey-decision-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:1.25rem 1.35rem;box-shadow:0 16px 36px rgba(15,23,42,.055);display:grid;gap:.9rem}.journey-decision-top{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap}.journey-stage-label{display:inline-flex;align-items:center;gap:.45rem;color:#64748b;font-size:.82rem;font-weight:500}.journey-decision-card h2{margin:0;color:#172033;font-size:clamp(1.35rem,2.4vw,2.05rem);font-weight:300;letter-spacing:-.025em;line-height:1.18}.journey-next-strip{display:flex;align-items:center;justify-content:space-between;gap:.8rem;border-top:1px solid #edf2f7;padding-top:.85rem;color:#475569;font-size:.9rem;font-weight:400}.journey-next-strip strong{color:#172033;font-weight:600}.journey-mini-grid{display:grid;grid-template-columns:1fr;gap:.7rem}.journey-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:.78rem .85rem;min-width:0}.journey-card.is-warning{border-color:#fde68a;background:#fffbeb}.journey-card.is-good{border-color:#bbf7d0;background:#f0fdf4}.journey-card-head{display:flex;align-items:center;justify-content:space-between;gap:.5rem;color:#64748b;font-size:.78rem;font-weight:500}.journey-card-value{margin-top:.38rem;color:#172033;font-size:1rem;font-weight:600;line-height:1.2}.journey-card-brief{margin:.28rem 0 0;color:#6b7280;font-size:.78rem;line-height:1.35;font-weight:400}
.journey-main{display:grid;grid-template-columns:minmax(0,1fr) minmax(260px,.31fr);gap:1rem;align-items:start;margin-top:1.15rem}.journey-panel{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1rem;box-shadow:none}.journey-panel-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:.75rem}.journey-panel-head h2{margin:0;color:#172033;font-size:1rem;font-weight:500}.journey-kicker{font-size:.72rem;text-transform:uppercase;font-weight:600;color:#64748b;letter-spacing:.05em}.journey-title{margin:.15rem 0 0;color:#172033;font-size:1.05rem;font-weight:500;line-height:1.2}.journey-copy{color:#64748b;line-height:1.4;margin:0;font-size:.88rem}
.journey-form-section{border:1px solid #e2e8f0;border-radius:10px;background:#fff;padding:1.05rem;margin-bottom:1rem}.journey-form-section-title{display:flex;align-items:center;gap:.45rem;margin:0;color:#172033;font-size:1rem;font-weight:500}.journey-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1.05rem 1rem;margin-top:.9rem}.journey-field{position:relative;display:grid;gap:.58rem;min-width:0;color:#475569;font-weight:500;font-size:.82rem}.journey-field-label-row{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:start;gap:.65rem;line-height:1.42}.journey-field-label-row > span:first-child{min-width:0}.journey-field-tools{display:inline-flex;align-items:center;justify-content:flex-end;gap:.32rem;flex:0 0 auto;flex-wrap:wrap}.journey-quality{display:inline-flex;align-items:center;border:1px solid #e2e8f0;border-radius:999px;background:#f8fafc;color:#64748b;font-size:.64rem;font-weight:600;line-height:1;padding:.22rem .38rem}.journey-quality.is-warning{border-color:#fde68a;background:#fffbeb;color:#a16207}.journey-quality.is-active{border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8}.journey-quality.is-good{border-color:#bbf7d0;background:#f0fdf4;color:#166534}.journey-quality.is-muted{color:#94a3b8}.journey-field-tool{display:inline-flex;align-items:center;justify-content:center;width:1.25rem;height:1.25rem;border:1px solid #dbe4f0;border-radius:999px;background:#fff;color:#64748b;cursor:pointer;font-size:.7rem}.journey-field-tool:hover{border-color:#bfdbfe;color:#2563eb}.journey-field-help-wrap{position:relative;display:inline-flex;align-items:center}.journey-field-help{display:inline-flex;align-items:center;justify-content:center;width:1rem;height:1rem;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#64748b;font-size:.66rem;font-weight:600;line-height:1;cursor:help}.journey-field-help:focus-visible{outline:2px solid rgba(37,99,235,.28);outline-offset:2px}.journey-field-tooltip{box-sizing:border-box;position:absolute;right:0;bottom:calc(100% + .55rem);z-index:60;width:max-content;max-width:300px;display:grid;gap:.35rem;transform:translateY(.25rem);opacity:0;visibility:hidden;pointer-events:none;border:1px solid #e2e8f0;border-radius:10px;background:#172033;color:#fff;box-shadow:0 14px 34px rgba(15,23,42,.18);padding:.65rem .75rem;font-size:.76rem;font-weight:400;line-height:1.35;text-align:left;white-space:normal}.journey-field-tooltip strong{color:#bfdbfe;font-weight:600}.journey-field-tooltip:after{content:"";position:absolute;right:.55rem;top:100%;border:.38rem solid transparent;border-top-color:#172033}.journey-field-help-wrap:hover .journey-field-tooltip,.journey-field-help:focus + .journey-field-tooltip,.journey-field-help:focus-visible + .journey-field-tooltip{opacity:1;visibility:visible;transform:translateY(0)}.journey-field textarea{width:100%;box-sizing:border-box;min-height:118px;border:1px solid #dbe4f0;border-radius:10px;padding:.78rem .82rem;color:#172033;background:#fff;line-height:1.5;resize:vertical}.journey-notes-field{margin-top:.85rem}.journey-notes-field textarea{min-height:156px}.journey-field textarea:focus{outline:2px solid rgba(37,99,235,.16);border-color:#93c5fd}
.journey-checklist{display:grid;gap:.42rem;margin-top:.6rem}.journey-check{display:flex;align-items:flex-start;gap:.48rem;color:#64748b;font-size:.8rem;line-height:1.35}.journey-check i{margin-top:.08rem;color:#94a3b8}.journey-check.is-ok{color:#166534}.journey-check.is-ok i{color:#16a34a}.journey-split-actions{display:flex;align-items:center;gap:.45rem;flex-wrap:wrap}.journey-example-item{border:1px solid #e2e8f0;border-radius:8px;padding:.52rem .6rem;color:#475569;font-size:.78rem;line-height:1.35}.journey-example-item strong{display:block;color:#172033;font-weight:600}.journey-draft-output{border:1px solid #dbeafe;background:#f8fbff;border-radius:10px;padding:.72rem;display:grid;gap:.55rem;color:#172033;font-size:.86rem;line-height:1.45}.journey-focus-nav{display:none}.journey-focus-mode .journey-field{display:none}.journey-focus-mode .journey-field.is-focus-current{display:grid}.journey-focus-mode .journey-focus-nav{display:inline-flex}.journey-mode-note{color:#64748b;font-size:.78rem}
.journey-icon-btn.is-focus-active{background:#172033;border-color:#172033;color:#fff;box-shadow:0 10px 22px rgba(15,23,42,.16)}.journey-icon-btn.is-focus-active:hover{border-color:#0f172a;box-shadow:0 14px 26px rgba(15,23,42,.2)}.journey-focus-strip{display:none;align-items:center;justify-content:space-between;gap:1rem;margin:0 0 1rem;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;padding:.85rem 1rem;box-shadow:0 14px 28px rgba(37,99,235,.09)}.journey-focus-strip[hidden]{display:none}.journey-focus-strip-main{display:grid;gap:.2rem;min-width:0}.journey-focus-strip-kicker{color:#1d4ed8;font-size:.72rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase}.journey-focus-strip-title{color:#172033;font-size:1rem;font-weight:650;line-height:1.25}.journey-focus-strip-actions{display:flex;align-items:center;gap:.45rem;flex:0 0 auto}.journey-focus-strip-actions .journey-icon-btn[disabled]{opacity:.45;cursor:not-allowed;box-shadow:none}.journey-shell.is-focus-mode{max-width:1120px}.journey-shell.is-focus-mode .journey-hero{padding:.55rem 0 .75rem;gap:.45rem}.journey-shell.is-focus-mode .journey-hero h1{font-size:clamp(1.85rem,4.4vw,3.1rem)}.journey-shell.is-focus-mode .journey-hero-subtitle,.journey-shell.is-focus-mode .journey-rail,.journey-shell.is-focus-mode .journey-alert-strip,.journey-shell.is-focus-mode .journey-mini-grid,.journey-shell.is-focus-mode .journey-inspector{opacity:.38}.journey-shell.is-focus-mode .journey-focus-grid{grid-template-columns:1fr}.journey-shell.is-focus-mode .journey-decision-card{border-color:#bfdbfe;background:#f8fbff;box-shadow:0 18px 40px rgba(37,99,235,.12)}.journey-shell.is-focus-mode .journey-main{grid-template-columns:minmax(0,1fr);max-width:860px;width:100%;justify-self:center}.journey-shell.is-focus-mode .journey-panel{border-color:#bfdbfe;box-shadow:0 18px 42px rgba(37,99,235,.12)}.journey-shell.is-focus-mode .journey-focus-strip{display:flex}.journey-shell.is-focus-mode .journey-form-grid{grid-template-columns:1fr}.journey-shell.is-focus-mode .journey-field.is-focus-current{border:1px solid #bfdbfe;border-radius:12px;background:#f8fbff;padding:1rem;box-shadow:0 12px 26px rgba(37,99,235,.08)}.journey-shell.is-focus-mode .journey-field.is-focus-current textarea{min-height:220px;background:#fff}
.journey-inspector{display:grid;gap:.75rem}.journey-ai-card{display:grid;gap:.38rem;border:1px solid #dbeafe;background:#f8fbff;border-radius:10px;padding:.72rem;color:#1e3a8a;font-size:.84rem}.journey-ai-card.is-warning{border-color:#fde68a;background:#fffbeb;color:#92400e}.journey-ai-card.is-good{border-color:#bbf7d0;background:#f0fdf4;color:#166534}.journey-ai-card strong{color:#172033;font-weight:600}.journey-help-btn{border:0;background:transparent;color:#2563eb;font-size:.82rem;font-weight:600;padding:0;cursor:pointer;text-align:left}.journey-tooltip-wrap{position:relative;display:inline-flex;align-items:center}.journey-tooltip{appearance:none;display:inline-flex;align-items:center;justify-content:center;width:1rem;height:1rem;padding:0;border-radius:999px;border:1px solid #cbd5e1;color:#64748b;background:#fff;font-size:.66rem;font-weight:600;line-height:1;cursor:help}.journey-tooltip:hover{border-color:#bfdbfe;color:#2563eb}.journey-tooltip:focus-visible{outline:2px solid rgba(37,99,235,.28);outline-offset:2px}.journey-tooltip-bubble{box-sizing:border-box;position:absolute;right:0;bottom:calc(100% + .55rem);z-index:70;width:max-content;max-width:280px;transform:translateY(.25rem);opacity:0;visibility:hidden;pointer-events:none;border:1px solid #e2e8f0;border-radius:10px;background:#172033;color:#fff;box-shadow:0 14px 34px rgba(15,23,42,.18);padding:.6rem .7rem;font-size:.76rem;font-weight:400;line-height:1.35;text-align:left;white-space:normal}.journey-tooltip-bubble:after{content:"";position:absolute;right:.55rem;top:100%;border:.38rem solid transparent;border-top-color:#172033}.journey-tooltip-wrap:hover .journey-tooltip-bubble,.journey-tooltip:focus + .journey-tooltip-bubble,.journey-tooltip:focus-visible + .journey-tooltip-bubble,.journey-tooltip-wrap.is-open .journey-tooltip-bubble{opacity:1;visibility:visible;transform:translateY(0)}.journey-tooltip-wrap.is-open .journey-tooltip{border-color:#bfdbfe;color:#2563eb}.journey-empty-note{color:#64748b;font-size:.82rem;line-height:1.4;font-weight:400}
.journey-launch-list{display:grid;gap:.5rem}.journey-launch-item{display:grid;grid-template-columns:auto 1fr auto;gap:.55rem;align-items:center;border:1px solid #e2e8f0;border-radius:9px;background:#fff;color:#172033;text-decoration:none;padding:.58rem .62rem}.journey-launch-item:hover{border-color:#bfdbfe;background:#f8fbff}.journey-launch-item i{color:#2563eb}.journey-launch-item.is-complete i{color:#16a34a}.journey-launch-title{display:block;color:#172033;font-size:.84rem;font-weight:600;line-height:1.2}.journey-launch-meta{display:block;color:#64748b;font-size:.74rem;line-height:1.3;margin-top:.12rem}.journey-launch-status{display:inline-flex;border:1px solid #e2e8f0;border-radius:999px;background:#f8fafc;color:#64748b;font-size:.65rem;font-weight:700;line-height:1;padding:.22rem .38rem;white-space:nowrap}.journey-launch-status.is-complete{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
.journey-drawer-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.28);z-index:12000;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .18s ease,visibility .18s ease}.journey-drawer{box-sizing:border-box;position:fixed;top:0;right:0;width:min(440px,100vw);height:100vh;background:#fff;border-left:1px solid #e2e8f0;z-index:12001;transform:translateX(100%);visibility:hidden;pointer-events:none;transition:transform .2s ease,visibility .2s ease;box-shadow:-18px 0 42px rgba(15,23,42,.16);display:grid;grid-template-rows:auto 1fr;padding:1rem}.journey-drawer:not(.is-open){display:none}.journey-drawer.is-open{transform:translateX(0);visibility:visible;pointer-events:auto}.journey-drawer-backdrop.is-open{opacity:1;visibility:visible;pointer-events:auto}.journey-drawer-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding-bottom:.75rem;border-bottom:1px solid #e2e8f0}.journey-map-list{overflow:auto;display:grid;gap:.55rem;padding-top:.75rem}.journey-map-item{display:grid;grid-template-columns:auto 1fr auto;gap:.65rem;align-items:center;border:1px solid #e2e8f0;border-radius:8px;padding:.68rem;text-decoration:none;color:#0f172a}.journey-map-item.is-active{border-color:#2563eb;background:#eff6ff}.journey-map-number{width:2rem;height:2rem;border-radius:8px;background:#f1f5f9;display:inline-flex;align-items:center;justify-content:center;font-weight:950}.journey-map-title{font-weight:950}.journey-map-meta{color:#64748b;font-size:.78rem;font-weight:800}
.journey-mobile-actions{display:none}.journey-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.journey-video-modal[hidden]{display:none}.journey-video-modal{position:fixed;inset:0;z-index:13000;box-sizing:border-box;display:grid;place-items:center;padding:clamp(1rem,3vw,2rem);overflow:hidden;background:rgba(15,23,42,.62)}.journey-video-dialog{width:min(920px,100%);max-height:min(760px,calc(100vh - 2rem));overflow:hidden;display:flex;flex-direction:column;border:1px solid rgba(226,232,240,.7);border-radius:16px;background:#fff;box-shadow:0 30px 80px rgba(15,23,42,.28)}.journey-video-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem 1.1rem;border-bottom:1px solid rgba(15,23,42,.08)}.journey-video-title{margin:0;color:#0f172a;font-size:1.05rem;font-weight:600;line-height:1.2}.journey-video-close{width:2.35rem;height:2.35rem;display:inline-flex;align-items:center;justify-content:center;border:1px solid rgba(148,163,184,.32);border-radius:10px;background:#fff;color:#334155;cursor:pointer}.journey-video-close:hover,.journey-video-close:focus-visible{border-color:rgba(37,99,235,.34);color:#1d4ed8;outline:none}.journey-video-frame{background:#020617;min-height:0;flex:1 1 auto}.journey-video-frame video{width:100%;max-height:min(640px,calc(100vh - 7.25rem));display:block;object-fit:contain;background:#020617}.journey-video-empty{display:grid;gap:.45rem;padding:2rem;background:#f8fafc;color:#475569;text-align:center}.journey-video-empty i{color:#2563eb;font-size:1.7rem}.journey-video-empty strong{color:#0f172a;font-size:1rem;font-weight:600}.journey-video-empty span{font-size:.9rem}
@media(max-width:980px){.journey-shell{padding-top:1.75rem}.journey-focus-grid,.journey-main{grid-template-columns:1fr}.journey-mini-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:680px){.journey-shell{padding:.95rem .95rem 5rem;gap:.8rem}.journey-hero{gap:.7rem;padding:.65rem 0}.journey-progress-battery{width:116px;height:48px;border-width:2px;border-radius:14px}.journey-progress-battery:after{top:15px;height:15px}.journey-hero h1{font-size:clamp(2rem,13vw,3rem)}.journey-hero-subtitle{font-size:.94rem}.journey-actions{gap:.4rem}.journey-icon-btn span{display:none}.journey-completion-panel{grid-template-columns:1fr;padding:.9rem}.journey-completion-actions{justify-content:stretch}.journey-completion-action{width:100%;white-space:normal}.journey-rail{justify-content:flex-start;overflow-x:auto;overflow-y:visible;padding-top:.35rem;padding-bottom:.8rem}.journey-rail-tooltip{position:fixed;left:1rem;right:1rem;bottom:4.4rem;width:auto;max-width:none;transform:translateY(.25rem)}.journey-rail-tooltip:after{display:none}.journey-rail-link:hover .journey-rail-tooltip,.journey-rail-link:focus .journey-rail-tooltip,.journey-rail-link:focus-visible .journey-rail-tooltip{transform:translateY(0)}.journey-focus-grid{gap:.75rem}.journey-mini-grid{grid-template-columns:1fr}.journey-decision-card{padding:1rem}.journey-next-strip{align-items:flex-start;flex-direction:column}.journey-main{gap:.75rem;margin-top:.65rem}.journey-field-tooltip,.journey-tooltip-bubble{position:fixed;left:1rem;right:1rem;bottom:4.4rem;width:auto;max-width:none}.journey-field-tooltip:after,.journey-tooltip-bubble:after{display:none}.journey-drawer{width:100vw}.journey-mobile-actions{position:fixed;left:0;right:0;bottom:0;z-index:11000;display:flex;gap:.45rem;justify-content:center;background:rgba(255,255,255,.96);border-top:1px solid #e2e8f0;padding:.55rem}.journey-form-section{padding:.95rem}.journey-form-grid{grid-template-columns:1fr;gap:.9rem}.journey-field-label-row{grid-template-columns:1fr;gap:.42rem}.journey-field-tools{justify-content:flex-start}}
.journey-report-drawer{width:min(760px,100vw)}.journey-report-content{overflow:auto;padding-top:.85rem;display:grid;gap:.85rem}.journey-report-status{border:1px solid #dbeafe;background:#eff6ff;color:#1e3a8a;border-radius:8px;padding:.65rem .75rem;font-size:.86rem;font-weight:800}.journey-report-status.is-error{border-color:#fecaca;background:#fef2f2;color:#991b1b}.journey-report-section{border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:.85rem;display:grid;gap:.65rem}.journey-report-section h3{margin:0;color:#0f172a;font-size:.98rem}.journey-report-section p{margin:0;color:#475569;font-size:.88rem;line-height:1.45}.journey-report-meta{display:flex;flex-wrap:wrap;gap:.4rem}.journey-report-swot{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem}.journey-report-quadrant{border:1px solid #e2e8f0;border-radius:8px;padding:.7rem;background:#f8fafc;display:grid;gap:.55rem}.journey-report-quadrant strong{color:#0f172a}.journey-report-item{display:grid;gap:.25rem;border-top:1px solid #e2e8f0;padding-top:.5rem}.journey-report-item:first-of-type{border-top:0;padding-top:0}.journey-report-item span,.journey-report-item small{color:#64748b;font-size:.8rem;line-height:1.35}.journey-report-list{display:grid;gap:.5rem}.journey-report-row{display:grid;gap:.35rem;border:1px solid #e2e8f0;border-radius:8px;padding:.65rem;background:#fff}.journey-report-row-head{display:flex;justify-content:space-between;gap:.5rem;align-items:center}.journey-report-row-head strong{color:#0f172a}.journey-report-labels{display:flex;gap:.35rem;flex-wrap:wrap}.journey-report-label{border:1px solid #cbd5e1;border-radius:999px;padding:.12rem .45rem;font-size:.72rem;font-weight:900;color:#334155;background:#f8fafc}.journey-report-label.is-contradicted,.journey-report-label.is-stale,.journey-report-label.is-thin{border-color:#fed7aa;background:#fff7ed;color:#9a3412}.journey-report-label.is-evidence-backed,.journey-report-label.is-completed{border-color:#bbf7d0;background:#f0fdf4;color:#166534}.journey-report-actions{display:flex;gap:.45rem;flex-wrap:wrap}.journey-report-empty{display:grid;gap:.5rem;text-align:center;padding:1.25rem;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b}.journey-report-empty i{font-size:1.6rem;color:#2563eb}.journey-report-link{color:#2563eb;font-size:.8rem;font-weight:900;text-decoration:none}.journey-report-link:hover{text-decoration:underline}@media(max-width:680px){.journey-report-swot{grid-template-columns:1fr}.journey-report-row-head{align-items:flex-start;flex-direction:column}.journey-report-actions .journey-icon-btn{width:100%;justify-content:center}}
</style>

<?php echo VideoBrandOverlayUi::assets(); ?>

<div class="journey-shell">
    <header class="journey-hero">
        <div class="journey-hero-status">
            <div class="journey-progress-battery" title="Completed stages out of the eight-stage journey.">
                <div class="journey-progress-fill" data-journey-progress-fill style="width:<?php echo max(8, min(100, (int) ($progress['percent'] ?? 0))); ?>%"></div>
                <div class="journey-progress-score" data-journey-progress-score><?php echo max(0, min(100, (int) ($progress['percent'] ?? 0))); ?>%</div>
            </div>
            <span class="journey-chip <?php echo htmlspecialchars($journeyProgressChipClass); ?>" data-journey-progress-chip title="Completed stages across the full Clarity Journey."><?php echo htmlspecialchars($journeyProgressLabel); ?></span>
        </div>
        <h1>Clarity Journey</h1>
        <p class="journey-hero-subtitle"><?php echo htmlspecialchars((string) ($currentStage['prompt'] ?? 'Capture the next founder decision.')); ?></p>
        <div class="journey-actions" aria-label="Clarity Journey guide">
            <button class="journey-icon-btn journey-guide-action" type="button" data-startup-journey-video-open title="<?php echo $startupJourneyExplainerVideoUrl !== '' ? 'Watch the Clarity Journey page guide' : 'Guide video is waiting for setup'; ?>"><i class="fa-solid fa-play-circle"></i><span>Watch guide</span></button>
            <button class="journey-icon-btn" type="button" data-journey-map-open title="Open Journey Map"><i class="fa-solid fa-route"></i><span>Journey Map</span></button>
            <button class="journey-icon-btn" type="button" data-journey-report-open title="Open Journey Report"><i class="fa-solid fa-file-lines"></i><span>Journey Report</span></button>
            <button class="journey-icon-btn" type="button" data-focus-toggle aria-pressed="false" title="Toggle focused question mode"><i class="fa-solid fa-crosshairs"></i><span data-focus-toggle-label>Focus Mode</span></button>
        </div>
    </header>

    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <?php if ($canViewFounderLoop): ?>
        <section class="journey-completion-panel" data-journey-completion-panel aria-label="Clarity Journey completion" <?php echo $journeyComplete ? '' : 'hidden'; ?>>
            <div>
                <div class="journey-completion-kicker">Foundation complete</div>
                <h2 class="journey-completion-title">Clarity Journey is complete</h2>
                <p class="journey-completion-copy">Your business foundation is saved. Founder Loop turns it into first deals. AI Coach will use this context for ongoing recommendations.</p>
            </div>
            <div class="journey-completion-actions">
                <a class="journey-completion-action" href="founder_operating_loop.php"><i class="fa-solid fa-compass" aria-hidden="true"></i><span>Start Founder Loop</span></a>
                <a class="journey-completion-action secondary" href="<?php echo htmlspecialchars($aiCoachCompletionUrl); ?>"><i class="fa-solid fa-sparkles" aria-hidden="true"></i><span><?php echo htmlspecialchars($aiCoachCompletionLabel); ?></span></a>
            </div>
        </section>
    <?php endif; ?>

    <nav class="journey-rail" aria-label="Clarity Journey compact progress">
        <?php foreach ($stages as $stageKey => $stage): ?>
            <?php $status = (string) ($stage['status'] ?? 'not_started'); ?>
            <?php $tooltipId = 'journey-rail-tip-' . preg_replace('/[^a-z0-9_-]+/', '-', (string) $stageKey); ?>
            <?php $tooltipText = (string) ($stageTooltips[$stageKey] ?? ($stage['prompt'] ?? 'Open this stage.')); ?>
            <a class="journey-rail-link <?php echo $currentStageKey === (string) $stageKey ? 'is-active' : ''; ?> is-<?php echo htmlspecialchars($status); ?>" data-stage-rail-link="<?php echo htmlspecialchars((string) $stageKey); ?>" href="startup_journey.php?stage=<?php echo urlencode((string) $stageKey); ?>" aria-describedby="<?php echo htmlspecialchars($tooltipId); ?>" title="<?php echo htmlspecialchars($tooltipText); ?>">
                <i class="fa-solid <?php echo htmlspecialchars((string) ($stageIcons[$stageKey] ?? 'fa-circle-dot')); ?>"></i><?php echo htmlspecialchars((string) ($stageShortLabels[$stageKey] ?? ($stage['label'] ?? 'Stage'))); ?>
                <span class="journey-rail-tooltip" id="<?php echo htmlspecialchars($tooltipId); ?>" role="tooltip"><?php echo htmlspecialchars($tooltipText); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($alerts !== []): ?>
        <div class="journey-alert-strip" aria-label="Clarity Journey alerts">
            <?php foreach ($alerts as $alert): ?><span class="journey-alert" title="<?php echo htmlspecialchars($alert); ?>"><i class="fa-solid fa-triangle-exclamation"></i><?php echo htmlspecialchars($alert); ?></span><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="journey-focus-grid" aria-label="Clarity Journey focus">
        <article class="journey-decision-card" title="<?php echo htmlspecialchars((string) ($currentStage['prompt'] ?? 'Current stage prompt.')); ?>">
            <div class="journey-decision-top">
                <span class="journey-stage-label"><i class="fa-solid <?php echo htmlspecialchars((string) ($stageIcons[$currentStageKey] ?? 'fa-circle-dot')); ?>"></i><?php echo htmlspecialchars((string) ($currentStage['label'] ?? 'Stage')); ?></span>
                <span class="journey-chip <?php echo ($currentReadiness['status'] ?? '') === 'ready_to_complete' ? 'is-good' : 'is-active'; ?>" data-readiness-chip title="Ready when the answers can guide the next decision."><?php echo htmlspecialchars($readinessLabel); ?></span>
            </div>
            <h2><?php echo htmlspecialchars((string) ($currentStage['prompt'] ?? 'Capture the next founder decision.')); ?></h2>
            <div class="journey-next-strip">
                <span><strong>Next:</strong> <?php echo htmlspecialchars($nextMissingItem); ?></span>
                <span class="journey-chip <?php echo ($currentReadiness['status'] ?? '') === 'ready_to_complete' ? 'is-good' : 'is-active'; ?>" data-readiness-chip title="<?php echo htmlspecialchars((string) ($currentReadiness['completion_risk'] ?? 'medium')); ?> completion risk"><?php echo htmlspecialchars($readinessLabel); ?></span>
            </div>
        </article>
        <aside class="journey-mini-grid" aria-label="Clarity Journey quick status">
            <article class="journey-card" title="Completed stages out of the full Clarity Journey.">
                <div class="journey-card-head"><span>Progress</span><i class="fa-solid fa-chart-simple"></i></div>
                <div class="journey-card-value" data-journey-progress-value><?php echo (int) ($progress['completed'] ?? 0); ?>/<?php echo (int) ($progress['total'] ?? count($stages)); ?></div>
                <p class="journey-card-brief" data-journey-progress-brief><?php echo max(0, min(100, (int) ($progress['percent'] ?? 0))); ?>% complete</p>
            </article>
            <article class="journey-card <?php echo $strongFields > 0 ? 'is-good' : ''; ?>" title="Strong answers include a real example, number, quote, or customer signal.">
                <div class="journey-card-head"><span>Answer quality</span><i class="fa-solid fa-list-check"></i></div>
                <div class="journey-card-value"><?php echo htmlspecialchars($strongFields > 0 ? 'Strong' : 'Needs detail'); ?></div>
                <p class="journey-card-brief"><?php echo htmlspecialchars($answerBrief); ?></p>
            </article>
            <article class="journey-card <?php echo (string) ($primaryGuidance['tone'] ?? '') === 'warning' ? 'is-warning' : ''; ?>" title="<?php echo htmlspecialchars((string) ($primaryGuidance['meaning'] ?? 'Inline coach summary.')); ?>">
                <div class="journey-card-head"><span>AI Coach</span><i class="fa-solid fa-wand-magic-sparkles"></i></div>
                <div class="journey-card-value"><?php echo htmlspecialchars((string) ($primaryGuidance['title'] ?? 'Guidance')); ?></div>
                <button class="journey-help-btn" type="button" data-journey-explain-open>Explain</button>
            </article>
            <article class="journey-card" title="Saved answer summary for this stage.">
                <div class="journey-card-head"><span>Summary</span><i class="fa-solid fa-align-left"></i></div>
                <p class="journey-card-brief"><?php echo htmlspecialchars($currentSummary); ?></p>
            </article>
        </aside>
    </section>

    <div class="journey-main">
        <section class="journey-panel">
            <div class="journey-panel-head">
                <div>
                    <div class="journey-kicker">Focused workspace</div>
                    <h2><?php echo htmlspecialchars((string) ($currentStage['order'] ?? '')); ?>. <?php echo htmlspecialchars((string) ($currentStage['label'] ?? 'Stage')); ?></h2>
                </div>
                <?php $renderQuestionTooltip('focused-workspace', 'Only the selected stage is expanded. Use Journey Map to open another stage.', 'Help for focused workspace'); ?>
            </div>
            <div class="journey-focus-strip" data-focus-strip hidden>
                <div class="journey-focus-strip-main">
                    <span class="journey-focus-strip-kicker" data-focus-strip-status>Focus 1/1</span>
                    <strong class="journey-focus-strip-title" data-focus-strip-title><?php echo htmlspecialchars((string) ($currentStage['label'] ?? 'Current question')); ?></strong>
                </div>
                <div class="journey-focus-strip-actions">
                    <button class="journey-icon-btn" type="button" data-focus-prev title="Previous focus question"><i class="fa-solid fa-arrow-left"></i><span>Previous</span></button>
                    <button class="journey-icon-btn primary" type="button" data-focus-next title="Next focus question"><i class="fa-solid fa-arrow-right"></i><span>Next</span></button>
                </div>
            </div>
            <?php if ($canManageJourney): ?>
                <form method="POST" id="startup-stage-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="startup_action" value="save_stage">
                    <input type="hidden" name="startup_stage_key" value="<?php echo htmlspecialchars($currentStageKey); ?>">
                    <input type="hidden" id="startup-stage-status" name="startup_stage_status" value="draft">

                    <section class="journey-form-section">
                        <h3 class="journey-form-section-title">Inputs <?php $renderQuestionTooltip('inputs', 'Capture the few assumptions needed for this stage.', 'Help for inputs'); ?></h3>
                        <div class="journey-form-grid">
                            <?php foreach ($visibleFields as $field => $label): ?>
                                <?php $renderJourneyField($currentStageKey, (string) $field, (string) $label, $currentStage, $fieldTooltips, $currentFieldQuality, $canManageJourney); ?>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <?php if ($extraFields !== []): ?>
                        <section class="journey-form-section">
                            <h3 class="journey-form-section-title">More inputs <?php $renderQuestionTooltip('more-inputs', 'Additional fields stay available without crowding the page.', 'Help for more inputs'); ?></h3>
                            <div class="journey-form-grid">
                                <?php foreach ($extraFields as $field => $label): ?>
                                    <?php $renderJourneyField($currentStageKey, (string) $field, (string) $label, $currentStage, $fieldTooltips, $currentFieldQuality, $canManageJourney); ?>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>

                    <section class="journey-form-section">
                        <h3 class="journey-form-section-title">Notes <?php $renderQuestionTooltip('notes', 'Use notes for context that does not fit cleanly in the answers above.', 'Help for notes'); ?></h3>
                        <label class="journey-field journey-notes-field">Stage notes
                            <textarea name="startup_stage_notes"><?php echo htmlspecialchars((string) ($currentStage['notes'] ?? '')); ?></textarea>
                        </label>
                    </section>

                    <div class="journey-actions">
                        <span class="journey-mode-note" data-focus-status>Dashboard mode</span>
                        <span class="journey-autosave-status" data-autosave-status aria-live="polite">Autosave ready</span>
                        <button class="journey-icon-btn primary" type="button" data-complete-stage <?php echo $showCompleteStage ? '' : 'hidden'; ?> title="Complete this stage after the readiness checks pass"><i class="fa-solid fa-circle-check"></i><span>Complete stage</span></button>
                        <button class="journey-icon-btn danger" type="button" data-reopen-stage <?php echo $showReopenStage ? '' : 'hidden'; ?> title="Reopen this stage as a draft"><i class="fa-solid fa-rotate-left"></i><span>Reopen stage</span></button>
                        <button class="journey-icon-btn journey-focus-nav" type="button" data-focus-prev title="Previous focus question"><i class="fa-solid fa-arrow-left"></i><span>Prev</span></button>
                        <button class="journey-icon-btn journey-focus-nav" type="button" data-focus-next title="Next focus question"><i class="fa-solid fa-arrow-right"></i><span>Next</span></button>
                        <?php if (isset($stages[$nextStageKey]) && $nextStageKey !== $currentStageKey): ?>
                            <a class="journey-icon-btn" href="startup_journey.php?stage=<?php echo urlencode($nextStageKey); ?>" title="Open next incomplete stage"><i class="fa-solid fa-arrow-right"></i><span>Continue</span></a>
                        <?php endif; ?>
                    </div>
                    <div class="journey-save-recovery" data-save-recovery hidden>
                        <span data-save-recovery-message>Save failed.</span>
                        <button class="journey-icon-btn" type="button" data-save-retry><i class="fa-solid fa-rotate"></i><span>Retry save</span></button>
                        <button class="journey-icon-btn" type="button" data-leave-anyway><i class="fa-solid fa-arrow-right"></i><span>Leave anyway</span></button>
                    </div>
                </form>
            <?php else: ?>
                <p class="journey-empty-note"><?php echo htmlspecialchars($isProtectedDemoJourney ? 'Protected demo is view-only here. The strategy context is already complete and feeding Clarity AI.' : 'Your access can view Clarity Journey, but saving stages requires Marketplace management access.'); ?></p>
            <?php endif; ?>
        </section>

        <aside class="journey-inspector">
            <section class="journey-panel">
                <div class="journey-panel-head">
                    <div>
                        <div class="journey-kicker">Stage readiness</div>
                        <h2>Inspector</h2>
                    </div>
                    <?php $renderQuestionTooltip('inspector', 'Lean Canvas remains synced for compatibility.', 'Help for inspector'); ?>
                </div>
                <div style="display:grid;gap:.55rem;">
                    <span class="journey-chip" data-inspector-status title="Selected stage status."><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $currentStatus))); ?></span>
                    <span class="journey-chip" data-field-count-chip title="Completed fields inside this stage."><?php echo (int) ($currentStage['filled_fields'] ?? 0); ?> of <?php echo (int) ($currentStage['total_fields'] ?? 0); ?> fields</span>
                    <span class="journey-chip <?php echo ($currentReadiness['status'] ?? '') === 'ready_to_complete' ? 'is-good' : 'is-active'; ?>" data-readiness-chip title="Readiness is based on answer specificity."><?php echo htmlspecialchars($readinessLabel); ?></span>
                    <span class="journey-chip is-good" data-completed-at-chip <?php echo (string) ($currentStage['completed_at'] ?? '') !== '' ? '' : 'hidden'; ?>>Completed <?php echo htmlspecialchars((string) ($currentStage['completed_at'] ?? '')); ?></span>
                    <p class="journey-empty-note"><?php echo htmlspecialchars((string) ($currentStage['prompt'] ?? 'Capture the next founder assumption.')); ?></p>
                    <div class="journey-checklist">
                        <?php foreach ($currentChecklist as $check): ?>
                            <?php $ok = !empty($check['ok']); ?>
                            <div class="journey-check <?php echo $ok ? 'is-ok' : ''; ?>">
                                <i class="fa-solid <?php echo $ok ? 'fa-circle-check' : 'fa-circle'; ?>"></i>
                                <span><strong><?php echo htmlspecialchars((string) ($check['label'] ?? 'Check')); ?></strong><br><?php echo htmlspecialchars((string) ($check['detail'] ?? '')); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <section class="journey-panel">
                <div class="journey-kicker">AI Guidance</div>
                <?php foreach ($currentGuidance as $card): ?>
                    <div class="journey-ai-card is-<?php echo htmlspecialchars((string) ($card['tone'] ?? 'info')); ?>" style="margin-top:.55rem;">
                        <strong><?php echo htmlspecialchars((string) ($card['title'] ?? 'Guidance')); ?></strong>
                        <span><?php echo htmlspecialchars((string) ($card['action'] ?? 'Continue the current stage.')); ?></span>
                    </div>
                <?php endforeach; ?>
            </section>
            <section class="journey-panel">
                <div class="journey-panel-head">
                    <div>
                        <div class="journey-kicker">Launch handoffs</div>
                        <h2><?php echo htmlspecialchars($isProtectedDemoJourney ? 'Workspace readiness' : 'Workspace setup'); ?></h2>
                    </div>
                    <span class="journey-chip <?php echo ((int) ($workspaceLaunchChecklist['open_count'] ?? 0)) === 0 ? 'is-good' : 'is-active'; ?>">
                        <?php echo (int) ($workspaceLaunchChecklist['complete_count'] ?? count(array_filter($workspaceLaunchItems, static fn(array $item): bool => !empty($item['complete'])))); ?>/<?php echo count($workspaceLaunchItems); ?>
                    </span>
                </div>
                <div class="journey-launch-list">
                    <?php foreach ($workspaceLaunchItems as $launchItem): ?>
                        <?php $launchComplete = !empty($launchItem['complete']); ?>
                        <a class="journey-launch-item <?php echo $launchComplete ? 'is-complete' : ''; ?>" href="<?php echo htmlspecialchars((string) ($launchItem['url'] ?? 'workspace_skills.php')); ?>">
                            <i class="<?php echo htmlspecialchars((string) ($launchItem['icon'] ?? 'fa-solid fa-circle-dot')); ?>" aria-hidden="true"></i>
                            <span>
                                <span class="journey-launch-title"><?php echo htmlspecialchars((string) ($launchItem['title'] ?? 'Setup item')); ?></span>
                                <span class="journey-launch-meta"><?php echo htmlspecialchars((string) ($launchItem['description'] ?? 'Open the next setup step.')); ?></span>
                            </span>
                            <span class="journey-launch-status <?php echo $launchComplete ? 'is-complete' : ''; ?>"><?php echo $launchComplete ? 'Done' : 'Open'; ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </aside>
    </div>

    <div class="journey-drawer-backdrop" data-journey-map-close></div>
    <aside class="journey-drawer" aria-hidden="true" aria-label="Journey Map">
        <div class="journey-drawer-head">
            <div>
                <div class="journey-kicker">Journey Map</div>
                <h2 class="journey-title">All stages</h2>
            </div>
            <button class="journey-icon-btn" type="button" data-journey-map-close title="Close Journey Map"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="journey-map-list">
            <?php foreach ($stages as $stageKey => $stage): ?>
                <?php $status = (string) ($stage['status'] ?? 'not_started'); ?>
                <a class="journey-map-item <?php echo $currentStageKey === (string) $stageKey ? 'is-active' : ''; ?>" href="startup_journey.php?stage=<?php echo urlencode((string) $stageKey); ?>" title="<?php echo htmlspecialchars((string) ($stage['prompt'] ?? 'Open this stage.')); ?>">
                    <span class="journey-map-number"><?php echo (int) ($stage['order'] ?? 0); ?></span>
                    <span>
                        <span class="journey-map-title"><?php echo htmlspecialchars((string) ($stage['label'] ?? 'Stage')); ?></span>
                        <span class="journey-map-meta"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $status))); ?> - <?php echo (int) ($stage['filled_fields'] ?? 0); ?>/<?php echo (int) ($stage['total_fields'] ?? 0); ?> fields</span>
                    </span>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="journey-drawer-backdrop" data-journey-report-close></div>
    <aside class="journey-drawer journey-report-drawer" aria-hidden="true" aria-label="Journey Report" data-journey-report-drawer>
        <div class="journey-drawer-head">
            <div>
                <div class="journey-kicker">Journey Report</div>
                <h2 class="journey-title">Strategy evidence</h2>
            </div>
            <button class="journey-icon-btn" type="button" data-journey-report-close title="Close Journey Report"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="journey-report-content">
            <div class="journey-report-status" data-journey-report-status>Open the report to load the latest saved snapshot.</div>
            <div class="journey-report-actions">
                <?php if ($canManageJourney): ?>
                    <button class="journey-icon-btn primary" type="button" data-journey-report-generate title="Generate a fresh Journey Report"><i class="fa-solid fa-wand-magic-sparkles"></i><span>Generate report</span></button>
                <?php endif; ?>
            </div>
            <div data-journey-report-body>
                <div class="journey-report-empty">
                    <i class="fa-solid fa-file-lines" aria-hidden="true"></i>
                    <strong>No report loaded yet</strong>
                    <span>Load the latest saved report or generate a fresh Journey Report from current Clarity Journey evidence.</span>
                </div>
            </div>
        </div>
    </aside>

    <div class="journey-drawer-backdrop" data-journey-explain-close></div>
    <aside class="journey-drawer" aria-hidden="true" aria-label="AI coach explanation" data-journey-explain-drawer>
        <div class="journey-drawer-head">
            <div>
                <div class="journey-kicker">Inline coach</div>
                <h2 class="journey-title"><?php echo htmlspecialchars((string) ($primaryGuidance['title'] ?? 'Guidance')); ?></h2>
            </div>
            <button class="journey-icon-btn" type="button" data-journey-explain-close title="Close explanation"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="overflow:auto;padding-top:.85rem;display:grid;gap:.75rem;">
            <section class="journey-ai-card is-<?php echo htmlspecialchars((string) ($primaryGuidance['tone'] ?? 'info')); ?>">
                <strong>What this means</strong>
                <span><?php echo htmlspecialchars((string) ($primaryGuidance['meaning'] ?? 'This is the active Clarity Journey guidance.')); ?></span>
            </section>
            <section class="journey-ai-card">
                <strong>Next best action</strong>
                <span><?php echo htmlspecialchars((string) ($primaryGuidance['action'] ?? 'Continue the selected stage.')); ?></span>
            </section>
            <p class="journey-empty-note">Lean Canvas remains the compatibility stage for older AI Coach, Clarity, and Marketplace contracts.</p>
        </div>
    </aside>

    <div class="journey-drawer-backdrop" data-examples-close></div>
    <aside class="journey-drawer" aria-hidden="true" aria-label="Stage examples" data-examples-drawer>
        <div class="journey-drawer-head">
            <div>
                <div class="journey-kicker">Examples</div>
                <h2 class="journey-title"><?php echo htmlspecialchars((string) ($currentStage['label'] ?? 'Stage')); ?></h2>
            </div>
            <button class="journey-icon-btn" type="button" data-examples-close title="Close examples"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="overflow:auto;padding-top:.85rem;display:grid;gap:.65rem;">
            <section class="journey-ai-card">
                <strong>Use examples as a starting point</strong>
                <span>Replace the details with your real customer, offer, and signals.</span>
            </section>
            <?php foreach ($exampleFields as $example): ?>
                <section class="journey-example-item">
                    <strong><?php echo htmlspecialchars((string) ($example['label'] ?? 'Field')); ?></strong>
                    <span><?php echo htmlspecialchars((string) ($example['example'] ?? '')); ?></span>
                </section>
            <?php endforeach; ?>
            <section class="journey-ai-card is-warning">
                <strong>Weak answer pattern</strong>
                <span>Broad claims like “everyone needs this” or “we will use social media” are too vague for useful AI guidance.</span>
            </section>
        </div>
    </aside>

    <div class="journey-drawer-backdrop" data-draft-close></div>
    <aside class="journey-drawer" aria-hidden="true" aria-label="AI draft assist" data-draft-drawer>
        <div class="journey-drawer-head">
            <div>
                <div class="journey-kicker">AI Draft Assist</div>
                <h2 class="journey-title" data-draft-title>Draft answer</h2>
            </div>
            <button class="journey-icon-btn" type="button" data-draft-close title="Close AI draft"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="overflow:auto;padding-top:.85rem;display:grid;gap:.75rem;">
            <div class="journey-draft-output" data-draft-output>
                <span>Select a field to draft a starter answer.</span>
            </div>
            <div class="journey-split-actions">
                <button class="journey-icon-btn primary" type="button" data-draft-use disabled><i class="fa-solid fa-check"></i><span>Use draft</span></button>
                <button class="journey-icon-btn" type="button" data-draft-refresh disabled><i class="fa-solid fa-rotate"></i><span>Refresh</span></button>
            </div>
            <p class="journey-draft-review" data-draft-review-note>Use draft inserts editable text only and saves it as a draft. Review it before completing the stage.</p>
        </div>
    </aside>

    <?php if ($canManageJourney): ?>
        <div class="journey-mobile-actions" aria-label="Clarity Journey mobile actions">
            <span class="journey-autosave-status" data-autosave-status-mobile aria-live="polite">Autosave ready</span>
            <button class="journey-icon-btn primary" type="button" data-complete-stage <?php echo $showCompleteStage ? '' : 'hidden'; ?> title="Complete stage"><i class="fa-solid fa-circle-check"></i><span>Complete</span></button>
            <button class="journey-icon-btn danger" type="button" data-reopen-stage <?php echo $showReopenStage ? '' : 'hidden'; ?> title="Reopen stage"><i class="fa-solid fa-rotate-left"></i><span>Reopen</span></button>
            <?php if (isset($stages[$nextStageKey]) && $nextStageKey !== $currentStageKey): ?><a class="journey-icon-btn" href="startup_journey.php?stage=<?php echo urlencode($nextStageKey); ?>" title="Continue"><i class="fa-solid fa-arrow-right"></i><span>Next</span></a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<div class="journey-video-modal" data-startup-journey-video-modal role="dialog" aria-modal="true" aria-labelledby="startup-journey-video-title" hidden>
    <div class="journey-video-dialog">
        <div class="journey-video-head">
            <h2 class="journey-video-title" id="startup-journey-video-title">How to use Clarity Journey</h2>
            <button class="journey-video-close" type="button" data-startup-journey-video-close aria-label="Close guide video">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </div>
        <div class="journey-video-frame">
            <?php if ($startupJourneyExplainerVideoUrl !== ''): ?>
                <?php echo VideoBrandOverlayUi::frame(
                    '<video controls preload="metadata" playsinline data-startup-journey-video>'
                    . '<source src="' . htmlspecialchars($startupJourneyExplainerVideoUrl, ENT_QUOTES, 'UTF-8') . '">'
                    . 'Your browser does not support embedded video playback.'
                    . '</video>'
                ); ?>
            <?php else: ?>
                <div class="journey-video-empty">
                    <i class="fa-solid fa-play-circle" aria-hidden="true"></i>
                    <strong>Guide video is not configured yet.</strong>
                    <span>A superadmin can upload the Clarity Journey page guide from the Marketplace catalog editor.</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
(function(){
    var guideOpenButton = document.querySelector('[data-startup-journey-video-open]');
    var guideModal = document.querySelector('[data-startup-journey-video-modal]');
    var guideCloseButton = guideModal ? guideModal.querySelector('[data-startup-journey-video-close]') : null;
    var guideVideo = guideModal ? guideModal.querySelector('[data-startup-journey-video]') : null;
    var guideLastFocused = null;
    function openGuideModal() {
        if (!guideModal) return;
        guideLastFocused = document.activeElement;
        if (guideModal.parentNode !== document.body) {
            document.body.appendChild(guideModal);
        }
        guideModal.hidden = false;
        guideModal.scrollTop = 0;
        document.body.style.overflow = 'hidden';
        if (guideVideo) {
            try { guideVideo.currentTime = 0; } catch (error) {}
            var playAttempt = guideVideo.play();
            if (playAttempt && typeof playAttempt.catch === 'function') {
                playAttempt.catch(function () {});
            }
        }
        if (guideCloseButton) guideCloseButton.focus();
    }
    function closeGuideModal() {
        if (!guideModal) return;
        guideModal.hidden = true;
        document.body.style.overflow = '';
        if (guideVideo) {
            guideVideo.pause();
            try { guideVideo.currentTime = 0; } catch (error) {}
        }
        if (guideLastFocused && typeof guideLastFocused.focus === 'function') {
            guideLastFocused.focus();
        }
    }
    if (guideOpenButton && guideModal) {
        guideOpenButton.addEventListener('click', openGuideModal);
        if (guideCloseButton) guideCloseButton.addEventListener('click', closeGuideModal);
        guideModal.addEventListener('click', function(event) {
            if (event.target === guideModal) closeGuideModal();
        });
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && !guideModal.hidden) closeGuideModal();
        });
    }

    var journeyTooltipTriggers = document.querySelectorAll('[data-journey-tooltip-trigger]');
    function closeJourneyTooltips(exceptTrigger) {
        journeyTooltipTriggers.forEach(function(trigger) {
            if (exceptTrigger && trigger === exceptTrigger) return;
            trigger.setAttribute('aria-expanded', 'false');
            var wrap = trigger.closest ? trigger.closest('.journey-tooltip-wrap') : trigger.parentNode;
            if (wrap) wrap.classList.remove('is-open');
        });
    }
    journeyTooltipTriggers.forEach(function(trigger) {
        trigger.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            var wrap = trigger.closest ? trigger.closest('.journey-tooltip-wrap') : trigger.parentNode;
            var shouldOpen = !wrap || !wrap.classList.contains('is-open');
            closeJourneyTooltips(trigger);
            if (wrap) wrap.classList.toggle('is-open', shouldOpen);
            trigger.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        });
    });
    document.addEventListener('click', function(event) {
        if (event.target && event.target.closest && event.target.closest('.journey-tooltip-wrap')) return;
        closeJourneyTooltips();
    });
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') closeJourneyTooltips();
    });

    function wire(openSelector, drawerSelector, closeSelector) {
        var openers = document.querySelectorAll(openSelector);
        var drawer = document.querySelector(drawerSelector);
        var closers = document.querySelectorAll(closeSelector);
        var backdrop = closers.length ? closers[0] : null;
        function openDrawer(){
            if (!drawer) return;
            drawer.classList.add('is-open');
            drawer.setAttribute('aria-hidden', 'false');
            if (backdrop) backdrop.classList.add('is-open');
        }
        function closeDrawer(){
            if (!drawer) return;
            drawer.classList.remove('is-open');
            drawer.setAttribute('aria-hidden', 'true');
            if (backdrop) backdrop.classList.remove('is-open');
        }
        openers.forEach(function(btn){ btn.addEventListener('click', openDrawer); });
        closers.forEach(function(btn){ btn.addEventListener('click', closeDrawer); });
        document.addEventListener('keydown', function(event){ if (event.key === 'Escape') closeDrawer(); });
    }
    wire('[data-journey-map-open]', '.journey-drawer[aria-label="Journey Map"]', '[data-journey-map-close]');
    wire('[data-journey-report-open]', '[data-journey-report-drawer]', '[data-journey-report-close]');
    wire('[data-journey-explain-open]', '[data-journey-explain-drawer]', '[data-journey-explain-close]');
    wire('[data-examples-open]', '[data-examples-drawer]', '[data-examples-close]');

    var draftDrawer = document.querySelector('[data-draft-drawer]');
    var draftBackdrop = document.querySelector('[data-draft-close]');
    var draftOutput = document.querySelector('[data-draft-output]');
    var draftTitle = document.querySelector('[data-draft-title]');
    var draftUse = document.querySelector('[data-draft-use]');
    var draftRefresh = document.querySelector('[data-draft-refresh]');
    var activeDraftField = '';
    var activeDraftText = '';
    var activeDraftMeta = null;
    var pendingAiAssistMetadata = null;
    var pendingSaveSource = '';
    var journeySaveEndpoint = <?php echo json_encode(apiUrl('startup_journey/save_stage.php')); ?>;
    var journeyDraftEndpoint = <?php echo json_encode(apiUrl('startup_journey/draft_field.php')); ?>;
    var journeyReportEndpoint = <?php echo json_encode(apiUrl('startup_journey/report.php')); ?>;
    var completionNextStageUrl = <?php echo json_encode($linearNextStageUrl); ?>;
    function drawerOpen(drawer, backdrop) {
        if (!drawer) return;
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        if (backdrop) backdrop.classList.add('is-open');
    }
    function drawerClose(drawer, backdrop) {
        if (!drawer) return;
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        if (backdrop) backdrop.classList.remove('is-open');
    }
    function currentResponses() {
        var responses = {};
        document.querySelectorAll('[name^="startup_response["]').forEach(function(field){
            var match = field.name.match(/^startup_response\[(.+)\]$/);
            if (match) responses[match[1]] = field.value || '';
        });
        return responses;
    }
    function responseSelector(fieldKey) {
        return '[name="startup_response[' + String(fieldKey).replace(/[^a-z0-9_]/gi, '') + ']"]';
    }
    function loadDraft(fieldKey, fieldLabel) {
        activeDraftField = fieldKey;
        activeDraftText = '';
        activeDraftMeta = null;
        if (draftTitle) draftTitle.textContent = fieldLabel || 'Draft answer';
        if (draftOutput) draftOutput.innerHTML = '<span>Drafting...</span>';
        if (draftUse) draftUse.disabled = true;
        if (draftRefresh) draftRefresh.disabled = true;
        drawerOpen(draftDrawer, draftBackdrop);
        var field = document.querySelector(responseSelector(fieldKey));
        fetch(journeyDraftEndpoint, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                csrf_token: <?php echo json_encode($csrf); ?>,
                stage_key: <?php echo json_encode($currentStageKey); ?>,
                field_key: fieldKey,
                current_value: field ? field.value : '',
                responses: currentResponses()
            })
        }).then(function(response){ return response.json(); }).then(function(payload){
            if (!payload || !payload.success) throw new Error((payload && payload.error) || 'Draft failed');
            activeDraftText = payload.draft || '';
            activeDraftMeta = {
                field_key: fieldKey,
                confidence: payload.confidence || 'low',
                source_type: payload.draft_source || (payload.fallback_used ? 'fallback' : 'ai'),
                source_label: payload.source_label || (payload.fallback_used ? 'Starter fallback' : 'AI generated')
            };
            if (draftOutput) {
                draftOutput.innerHTML = '<div class="journey-draft-meta"><strong>Suggested draft</strong><span class="journey-chip"></span><span class="journey-chip"></span></div><span data-draft-text></span><small></small>';
                var chips = draftOutput.querySelectorAll('.journey-chip');
                if (chips[0]) chips[0].textContent = activeDraftMeta.source_label;
                if (chips[1]) chips[1].textContent = 'Confidence: ' + activeDraftMeta.confidence;
                draftOutput.querySelector('[data-draft-text]').textContent = activeDraftText || 'Add more context, then try again.';
                draftOutput.querySelector('small').textContent = payload.reasoning || '';
            }
            if (draftUse) draftUse.disabled = activeDraftText === '';
            if (draftRefresh) draftRefresh.disabled = false;
        }).catch(function(error){
            if (draftOutput) draftOutput.innerHTML = '<span>' + error.message + '</span>';
            if (draftRefresh) draftRefresh.disabled = false;
        });
    }
    document.querySelectorAll('[data-draft-field]').forEach(function(button){
        button.addEventListener('click', function(){
            loadDraft(button.getAttribute('data-draft-field') || '', button.getAttribute('data-field-label') || 'Draft answer');
        });
    });
    document.querySelectorAll('[data-draft-close]').forEach(function(button){
        button.addEventListener('click', function(){ drawerClose(draftDrawer, draftBackdrop); });
    });
    if (draftUse) {
        draftUse.addEventListener('click', function(){
            if (!activeDraftField || !activeDraftText) return;
            var field = document.querySelector(responseSelector(activeDraftField));
            if (field) {
                field.value = activeDraftText;
                pendingSaveSource = 'ai_draft_assist';
                pendingAiAssistMetadata = activeDraftMeta || {
                    field_key: activeDraftField,
                    confidence: 'low',
                    source_type: 'unknown'
                };
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.focus();
            }
            drawerClose(draftDrawer, draftBackdrop);
        });
    }
    if (draftRefresh) {
        draftRefresh.addEventListener('click', function(){
            if (!activeDraftField) return;
            loadDraft(activeDraftField, draftTitle ? draftTitle.textContent : 'Draft answer');
        });
    }

    var form = document.getElementById('startup-stage-form');
    var autosaveStatus = document.querySelector('[data-autosave-status]');
    var autosaveStatusMobile = document.querySelector('[data-autosave-status-mobile]');
    var stageKeyInput = form ? form.querySelector('[name="startup_stage_key"]') : null;
    var notesField = form ? form.querySelector('[name="startup_stage_notes"]') : null;
    var saveRecovery = document.querySelector('[data-save-recovery]');
    var saveRecoveryMessage = document.querySelector('[data-save-recovery-message]');
    var saveRetry = document.querySelector('[data-save-retry]');
    var leaveAnyway = document.querySelector('[data-leave-anyway]');
    var blockedNavigationHref = '';
    var lastSaveOptions = null;
    var saveTimer = null;
    var dirty = false;
    var saving = false;
    var queued = false;
    var savePromise = Promise.resolve();

    function statusLabel(status) {
        return String(status || 'not_started').replace(/_/g, ' ').replace(/\b\w/g, function(letter){ return letter.toUpperCase(); });
    }
    function setAutosaveStatus(text, state) {
        [autosaveStatus, autosaveStatusMobile].forEach(function(node){
            if (!node) return;
            node.textContent = text;
            node.classList.remove('is-pending', 'is-saving', 'is-saved', 'is-error');
            if (state) node.classList.add('is-' + state);
        });
    }
    function showSaveRecovery(message, href) {
        blockedNavigationHref = href || blockedNavigationHref || '';
        if (saveRecoveryMessage) saveRecoveryMessage.textContent = message || 'Save failed.';
        if (saveRecovery) saveRecovery.hidden = false;
    }
    function hideSaveRecovery() {
        if (saveRecovery) saveRecovery.hidden = true;
        blockedNavigationHref = '';
    }
    function setChipState(node, state) {
        if (!node) return;
        node.classList.remove('is-good', 'is-active', 'is-warning');
        if (state) node.classList.add(state);
    }
    function journeyProgressCounts(progress) {
        progress = progress || {};
        var completed = parseInt(progress.completed || 0, 10);
        var total = parseInt(progress.total || 0, 10);
        return {
            completed: isNaN(completed) ? 0 : Math.max(0, completed),
            total: isNaN(total) ? 0 : Math.max(0, total)
        };
    }
    function journeyProgressLabel(counts) {
        return counts.total > 0 && counts.completed >= counts.total
            ? 'Journey complete'
            : String(counts.completed) + '/' + String(counts.total) + ' stages complete';
    }
    function journeyProgressChipClass(counts) {
        return counts.total > 0 && counts.completed >= counts.total
            ? 'is-good'
            : (counts.completed > 0 ? 'is-active' : 'is-warning');
    }
    function setStageActionVisibility(stageStatus, readiness) {
        var readinessStatus = readiness && readiness.status ? readiness.status : '';
        var canComplete = stageStatus !== 'completed' && readinessStatus === 'ready_to_complete';
        document.querySelectorAll('[data-complete-stage]').forEach(function(button){ button.hidden = !canComplete; });
        document.querySelectorAll('[data-reopen-stage]').forEach(function(button){ button.hidden = stageStatus !== 'completed'; });
    }
    function updateAutosaveUi(payload) {
        var stageStatus = payload.stage_status || 'not_started';
        var readiness = payload.readiness || {};
        var progress = payload.progress || {};
        var progressCounts = journeyProgressCounts(progress);
        var percent = Math.max(0, Math.min(100, parseInt(progress.percent || 0, 10)));
        var statusText = statusLabel(stageStatus);
        var readinessText = readiness.label || statusLabel(readiness.status || 'not_started');
        var readinessState = readiness.status === 'ready_to_complete' ? 'is-good' : 'is-active';
        var journeyProgressChip = document.querySelector('[data-journey-progress-chip]');
        var inspectorStatus = document.querySelector('[data-inspector-status]');
        var progressFill = document.querySelector('[data-journey-progress-fill]');
        var progressScore = document.querySelector('[data-journey-progress-score]');
        var progressValue = document.querySelector('[data-journey-progress-value]');
        var progressBrief = document.querySelector('[data-journey-progress-brief]');
        var fieldCount = document.querySelector('[data-field-count-chip]');
        var completedAt = document.querySelector('[data-completed-at-chip]');
        var currentRail = stageKeyInput ? document.querySelector('[data-stage-rail-link="' + stageKeyInput.value + '"]') : null;
        var completionPanel = document.querySelector('[data-journey-completion-panel]');

        if (journeyProgressChip) {
            journeyProgressChip.textContent = journeyProgressLabel(progressCounts);
            setChipState(journeyProgressChip, journeyProgressChipClass(progressCounts));
        }
        if (inspectorStatus) {
            inspectorStatus.textContent = statusText;
        }
        document.querySelectorAll('[data-readiness-chip]').forEach(function(chip){
            chip.textContent = readinessText;
            setChipState(chip, readinessState);
            if (readiness.completion_risk) chip.title = readiness.completion_risk + ' completion risk';
        });
        if (progressFill) progressFill.style.width = Math.max(8, percent) + '%';
        if (progressScore) progressScore.textContent = percent + '%';
        if (progressValue) progressValue.textContent = String(progressCounts.completed) + '/' + String(progressCounts.total);
        if (progressBrief) progressBrief.textContent = percent + '% complete';
        if (fieldCount) fieldCount.textContent = String(payload.filled_fields || 0) + ' of ' + String(payload.total_fields || 0) + ' fields';
        if (completedAt) {
            completedAt.hidden = !(stageStatus === 'completed' && payload.completed_at);
            if (!completedAt.hidden) completedAt.textContent = 'Completed ' + payload.completed_at;
        }
        if (currentRail) {
            currentRail.classList.remove('is-not_started', 'is-draft', 'is-completed');
            currentRail.classList.add('is-' + stageStatus);
        }
        if (completionPanel) {
            completionPanel.hidden = !(progressCounts.total > 0 && progressCounts.completed >= progressCounts.total);
        }
        setStageActionVisibility(stageStatus, readiness);
    }
    function currentNotes() {
        return notesField ? notesField.value || '' : '';
    }
    function parseJsonResponse(response, fallbackMessage) {
        var contentType = response.headers ? (response.headers.get('content-type') || '') : '';
        return response.text().then(function(text){
            var payload = null;
            if (text && contentType.indexOf('application/json') !== -1) {
                try {
                    payload = JSON.parse(text);
                } catch (error) {
                    throw new Error(fallbackMessage || 'Save failed');
                }
            } else if (text) {
                try {
                    payload = JSON.parse(text);
                } catch (error) {
                    payload = null;
                }
            }
            if (!response.ok || !payload || !payload.success) {
                throw new Error((payload && payload.error) || fallbackMessage || 'Save failed');
            }
            return payload;
        });
    }
    var reportBody = document.querySelector('[data-journey-report-body]');
    var reportStatus = document.querySelector('[data-journey-report-status]');
    var reportGenerate = document.querySelector('[data-journey-report-generate]');
    var reportLoaded = false;
    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function(char){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char] || char;
        });
    }
    function setReportStatus(text, isError) {
        if (!reportStatus) return;
        reportStatus.textContent = text;
        reportStatus.classList.toggle('is-error', !!isError);
    }
    function reportChip(label) {
        var clean = String(label || '').replace(/[^a-z0-9-]+/gi, '-').toLowerCase();
        return '<span class="journey-report-label is-' + escapeHtml(clean) + '">' + escapeHtml(label) + '</span>';
    }
    function renderReportEmpty(message) {
        if (!reportBody) return;
        reportBody.innerHTML = '<div class="journey-report-empty"><i class="fa-solid fa-file-lines" aria-hidden="true"></i><strong>No Journey Report yet</strong><span>' + escapeHtml(message || 'Generate a fresh report from current Clarity Journey evidence.') + '</span></div>';
    }
    function renderReportList(items, emptyText, itemRenderer) {
        items = Array.isArray(items) ? items : [];
        if (!items.length) return '<p>' + escapeHtml(emptyText) + '</p>';
        return '<div class="journey-report-list">' + items.map(itemRenderer).join('') + '</div>';
    }
    function renderSwot(swot) {
        swot = swot || {};
        if (swot.status !== 'available') {
            return '<section class="journey-report-section"><h3>SWOT Analysis</h3><p>' + escapeHtml(swot.reason || 'AI narrative SWOT is unavailable for this report.') + '</p></section>';
        }
        var labels = {
            strengths: 'Strengths',
            weaknesses: 'Weaknesses',
            opportunities: 'Opportunities',
            threats: 'Threats'
        };
        var quadrants = ['strengths', 'weaknesses', 'opportunities', 'threats'].map(function(key){
            var items = Array.isArray(swot[key]) ? swot[key] : [];
            return '<article class="journey-report-quadrant"><strong>' + labels[key] + '</strong>' + (items.length ? items.map(function(item){
                var refs = Array.isArray(item.source_refs) && item.source_refs.length ? '<small>Sources: ' + escapeHtml(item.source_refs.join(', ')) + '</small>' : '';
                return '<div class="journey-report-item"><b>' + escapeHtml(item.title || 'Signal') + '</b><span>' + escapeHtml(item.evidence || '') + '</span><small>Confidence: ' + escapeHtml(item.confidence || 'medium') + '</small>' + refs + (item.next_action ? '<small>Next: ' + escapeHtml(item.next_action) + '</small>' : '') + '</div>';
            }).join('') : '<p>No items returned for this quadrant.</p>') + '</article>';
        }).join('');
        return '<section class="journey-report-section"><h3>SWOT Analysis</h3><div class="journey-report-swot">' + quadrants + '</div></section>';
    }
    function renderReport(report) {
        if (!reportBody) return;
        if (!report) {
            renderReportEmpty('Generate a fresh report from current Clarity Journey evidence.');
            return;
        }
        var meta = report.report_meta || {};
        var health = report.journey_health || {};
        var progress = health.progress || {};
        var stageHealth = Array.isArray(report.stage_health) ? report.stage_health : [];
        var conflicts = Array.isArray(report.assumption_conflicts) ? report.assumption_conflicts : [];
        var actions = Array.isArray(report.next_actions) ? report.next_actions : [];
        var bridge = report.founder_loop_bridge || {};
        var caveats = Array.isArray(report.caveats) ? report.caveats : [];
        var metaLine = [];
        if (meta.artifact_created_at) metaLine.push('Saved ' + meta.artifact_created_at);
        else if (meta.generated_at) metaLine.push('Generated ' + meta.generated_at);
        if (meta.ai_status) metaLine.push('AI: ' + meta.ai_status);
        var stageHtml = renderReportList(stageHealth, 'No stage health available yet.', function(stage){
            var labels = Array.isArray(stage.labels) ? stage.labels : [];
            var warnings = Array.isArray(stage.warnings) ? stage.warnings : [];
            return '<article class="journey-report-row"><div class="journey-report-row-head"><strong>' + escapeHtml(stage.label || stage.stage_key || 'Stage') + '</strong><span>' + escapeHtml(stage.filled_fields || 0) + '/' + escapeHtml(stage.total_fields || 0) + ' fields</span></div><div class="journey-report-labels">' + labels.map(reportChip).join('') + '</div><p>' + escapeHtml(stage.summary || stage.readiness_label || '') + '</p>' + (warnings.length ? '<small>' + escapeHtml(warnings.join(' ')) + '</small>' : '') + (stage.review_url ? '<a class="journey-report-link" href="' + escapeHtml(stage.review_url) + '">Review this stage</a>' : '') + '</article>';
        });
        var conflictHtml = renderReportList(conflicts, 'No assumption conflicts detected in the current evidence.', function(conflict){
            return '<article class="journey-report-row"><div class="journey-report-row-head"><strong>' + escapeHtml(conflict.type || 'Assumption') + '</strong><span class="journey-report-label">' + escapeHtml(conflict.severity || 'medium') + '</span></div><p>' + escapeHtml(conflict.operating_evidence || '') + '</p><small>' + escapeHtml(conflict.recommended_action || '') + '</small>' + (conflict.stage_key ? '<a class="journey-report-link" href="startup_journey.php?stage=' + encodeURIComponent(conflict.stage_key) + '">Review this stage</a>' : '') + '</article>';
        });
        var actionHtml = renderReportList(actions, 'No next actions generated yet.', function(action){
            return '<article class="journey-report-row"><div class="journey-report-row-head"><strong>' + escapeHtml(action.title || 'Next action') + '</strong><span class="journey-report-label">' + escapeHtml(action.priority || 'medium') + '</span></div><p>' + escapeHtml(action.description || '') + '</p>' + (action.cta_url ? '<a class="journey-report-link" href="' + escapeHtml(action.cta_url) + '">' + escapeHtml(action.cta_label || 'Open') + '</a>' : '') + '</article>';
        });
        reportBody.innerHTML = ''
            + '<section class="journey-report-section"><h3>Executive Summary</h3><p>' + escapeHtml(report.executive_summary || 'No executive summary available.') + '</p><div class="journey-report-meta">' + metaLine.map(function(item){ return '<span class="journey-chip">' + escapeHtml(item) + '</span>'; }).join('') + '</div></section>'
            + '<section class="journey-report-section"><h3>Journey Health</h3><p>' + escapeHtml(health.message || '') + '</p><div class="journey-report-meta"><span class="journey-chip">' + escapeHtml(progress.completed || 0) + '/' + escapeHtml(progress.total || 0) + ' complete</span><span class="journey-chip">' + escapeHtml(progress.percent || 0) + '%</span><span class="journey-chip">' + escapeHtml(health.conflict_count || 0) + ' conflicts</span></div><div class="journey-report-labels">' + (Array.isArray(health.labels) ? health.labels.map(reportChip).join('') : '') + '</div></section>'
            + renderSwot(report.swot || {})
            + '<section class="journey-report-section"><h3>Stage Health</h3>' + stageHtml + '</section>'
            + '<section class="journey-report-section"><h3>Evidence Warnings</h3>' + conflictHtml + '</section>'
            + '<section class="journey-report-section"><h3>Founder Loop Handoff</h3><p>' + escapeHtml(bridge.next_action || 'No Founder Loop handoff is available yet.') + '</p><div class="journey-report-meta"><span class="journey-chip">' + escapeHtml(bridge.current_step_label || 'No active step') + '</span><span class="journey-chip">' + escapeHtml(bridge.commitment_count || 0) + ' commitments</span></div></section>'
            + '<section class="journey-report-section"><h3>Next Actions</h3>' + actionHtml + '</section>'
            + (caveats.length ? '<section class="journey-report-section"><h3>Caveats</h3>' + renderReportList(caveats, '', function(caveat){ return '<article class="journey-report-row"><p>' + escapeHtml(caveat) + '</p></article>'; }) + '</section>' : '');
    }
    function loadJourneyReport(force) {
        if (!reportBody || (!force && reportLoaded)) return;
        setReportStatus('Loading latest Journey Report...', false);
        fetch(journeyReportEndpoint, { method: 'GET' })
            .then(function(response){ return parseJsonResponse(response, 'Could not load Journey Report'); })
            .then(function(payload){
                reportLoaded = true;
                if (payload.latest_report) {
                    renderReport(payload.latest_report);
                    var meta = payload.latest_report.report_meta || {};
                    setReportStatus(meta.artifact_created_at ? 'Latest saved report loaded from ' + meta.artifact_created_at + '.' : 'Latest saved report loaded.', false);
                } else {
                    renderReportEmpty('No saved report exists yet. Generate one from current Journey evidence.');
                    setReportStatus('No saved Journey Report yet.', false);
                }
            })
            .catch(function(error){
                setReportStatus(error.message || 'Could not load Journey Report', true);
            });
    }
    document.querySelectorAll('[data-journey-report-open]').forEach(function(button){
        button.addEventListener('click', function(){ loadJourneyReport(false); });
    });
    if (reportGenerate) {
        reportGenerate.addEventListener('click', function(){
            reportGenerate.disabled = true;
            setReportStatus('Generating Journey Report...', false);
            fetch(journeyReportEndpoint, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ csrf_token: <?php echo json_encode($csrf); ?> })
            }).then(function(response){
                return parseJsonResponse(response, 'Could not generate Journey Report');
            }).then(function(payload){
                reportLoaded = true;
                renderReport(payload.report || null);
                setReportStatus('Fresh Journey Report generated and saved.', false);
            }).catch(function(error){
                setReportStatus(error.message || 'Could not generate Journey Report', true);
            }).finally(function(){
                reportGenerate.disabled = false;
            });
        });
    }
    function saveSuccessMessage(payload) {
        if (payload && payload.manual_completion_required) {
            return payload.completion_message
                || ('Draft saved. Complete this stage after reviewing: ' + ((payload.readiness && payload.readiness.next_missing_item) || 'the remaining items.'));
        }
        if (payload && payload.completion_allowed === false && payload.readiness && payload.readiness.next_missing_item) {
            return payload.completion_message
                || ('Draft saved. Complete is blocked until this stage has enough detail. Next: ' + payload.readiness.next_missing_item);
        }
        return (payload && payload.completion_message)
            || (payload && payload.stage_status === 'completed' ? 'Stage completed' : 'Draft saved');
    }
    function saveNow(force, options) {
        options = options || {};
        if (!form) return Promise.resolve();
        if (!dirty && !force) return Promise.resolve();
        if (saving) {
            queued = true;
            lastSaveOptions = options;
            return savePromise.then(function(){ return queued ? saveNow(true, lastSaveOptions || options) : undefined; });
        }
        var saveSource = options.source || pendingSaveSource || 'autosave';
        var completionIntent = options.completionIntent === 'complete' ? 'complete' : 'draft';
        var aiAssistMetadata = options.aiAssistMetadata || pendingAiAssistMetadata || null;
        lastSaveOptions = {
            source: saveSource,
            completionIntent: completionIntent,
            aiAssistMetadata: aiAssistMetadata
        };
        dirty = false;
        saving = true;
        queued = false;
        setAutosaveStatus(completionIntent === 'complete' ? 'Completing stage...' : 'Saving draft...', 'saving');
        var body = {
            csrf_token: <?php echo json_encode($csrf); ?>,
            stage_key: stageKeyInput ? stageKeyInput.value : <?php echo json_encode($currentStageKey); ?>,
            responses: currentResponses(),
            notes: currentNotes(),
            completion_intent: completionIntent,
            save_source: saveSource
        };
        if (aiAssistMetadata) {
            body.ai_assist_metadata = aiAssistMetadata;
        }
        savePromise = fetch(journeySaveEndpoint, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body)
        }).then(function(response){
            return parseJsonResponse(response, 'Save failed').then(function(payload){
                updateAutosaveUi(payload);
                hideSaveRecovery();
                pendingSaveSource = '';
                pendingAiAssistMetadata = null;
                setAutosaveStatus(saveSuccessMessage(payload), 'saved');
                if (completionIntent === 'complete' && payload.stage_status !== 'completed') {
                    showSaveRecovery(saveSuccessMessage(payload), options.href || '');
                }
                return payload;
            });
        }).catch(function(error){
            dirty = true;
            setAutosaveStatus('Save failed', 'error');
            showSaveRecovery(error.message || 'Save failed', options.href || '');
            throw error;
        }).finally(function(){
            saving = false;
            if (queued) {
                queued = false;
                saveNow(true, lastSaveOptions || {}).catch(function(){});
            }
        });
        return savePromise;
    }
    function scheduleSave() {
        if (!form) return;
        dirty = true;
        setAutosaveStatus('Unsaved changes', 'pending');
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(function(){
            saveNow(true, {
                source: pendingSaveSource || 'autosave',
                completionIntent: 'draft',
                aiAssistMetadata: pendingAiAssistMetadata
            }).catch(function(){});
        }, 700);
    }
    if (form) {
        form.addEventListener('submit', function(event){
            event.preventDefault();
            saveNow(true, { source: 'manual', completionIntent: 'draft' }).catch(function(){});
        });
        document.querySelectorAll('[data-complete-stage]').forEach(function(button){
            button.addEventListener('click', function(){
                window.clearTimeout(saveTimer);
                saveNow(true, { source: 'manual', completionIntent: 'complete' }).then(function(payload){
                    if (payload && payload.stage_status === 'completed' && completionNextStageUrl) {
                        window.location.href = completionNextStageUrl;
                    }
                }).catch(function(){});
            });
        });
        document.querySelectorAll('[data-reopen-stage]').forEach(function(button){
            button.addEventListener('click', function(){
                window.clearTimeout(saveTimer);
                saveNow(true, { source: 'manual', completionIntent: 'draft' }).catch(function(){});
            });
        });
        if (saveRetry) {
            saveRetry.addEventListener('click', function(){
                window.clearTimeout(saveTimer);
                var retryHref = blockedNavigationHref;
                saveNow(true, lastSaveOptions || { source: 'manual', completionIntent: 'draft' }).then(function(){
                    if (retryHref) window.location.href = retryHref;
                }).catch(function(){});
            });
        }
        if (leaveAnyway) {
            leaveAnyway.addEventListener('click', function(){
                if (blockedNavigationHref) {
                    window.location.href = blockedNavigationHref;
                    return;
                }
                hideSaveRecovery();
            });
        }
        form.querySelectorAll('textarea').forEach(function(field){
            field.addEventListener('input', scheduleSave);
            field.addEventListener('change', scheduleSave);
        });
        document.querySelectorAll('.journey-rail-link, .journey-map-item, .journey-icon-btn[href], .journey-completion-action').forEach(function(link){
            link.addEventListener('click', function(event){
                if (!dirty && !saving) return;
                var href = link.getAttribute('href');
                if (!href || href === '#') return;
                event.preventDefault();
                window.clearTimeout(saveTimer);
                saveNow(true, { source: pendingSaveSource || 'autosave', completionIntent: 'draft', aiAssistMetadata: pendingAiAssistMetadata, href: href }).then(function(){ window.location.href = href; }).catch(function(){});
            });
        });
        window.addEventListener('beforeunload', function(){
            if (!dirty) return;
            var beaconPayload = {
                csrf_token: <?php echo json_encode($csrf); ?>,
                stage_key: stageKeyInput ? stageKeyInput.value : <?php echo json_encode($currentStageKey); ?>,
                responses: currentResponses(),
                notes: currentNotes(),
                completion_intent: 'draft',
                save_source: 'beacon'
            };
            if (pendingAiAssistMetadata) {
                beaconPayload.ai_assist_metadata = pendingAiAssistMetadata;
            }
            var payload = JSON.stringify(beaconPayload);
            if (navigator.sendBeacon) {
                navigator.sendBeacon(journeySaveEndpoint, new Blob([payload], { type: 'application/json' }));
            }
        });
    }
    var journeyShell = document.querySelector('.journey-shell');
    var focusButtons = document.querySelectorAll('[data-focus-toggle]');
    var focusToggleLabels = document.querySelectorAll('[data-focus-toggle-label]');
    var focusStrip = document.querySelector('[data-focus-strip]');
    var focusStripStatus = document.querySelector('[data-focus-strip-status]');
    var focusStripTitle = document.querySelector('[data-focus-strip-title]');
    var focusPrevButtons = document.querySelectorAll('[data-focus-prev]');
    var focusNextButtons = document.querySelectorAll('[data-focus-next]');
    var focusFields = Array.prototype.slice.call(document.querySelectorAll('.journey-field textarea[name^="startup_response["]')).map(function(textarea){
        var field = textarea.closest('.journey-field');
        var labelNode = field ? field.querySelector('.journey-field-label-row > span:first-child') : null;
        var label = labelNode ? labelNode.textContent.trim() : '';
        if (!label) {
            label = String(textarea.name || 'Current question').replace(/^startup_response\[/, '').replace(/\]$/, '').replace(/_/g, ' ');
        }
        return { field: field, textarea: textarea, label: label };
    }).filter(function(entry){ return entry.field; });
    var focusIndex = 0;
    var focusStatus = document.querySelector('[data-focus-status]');
    function focusStatusText() {
        return 'Focus ' + (focusIndex + 1) + '/' + focusFields.length;
    }
    function scrollFocusTarget(target) {
        if (target && typeof target.scrollIntoView === 'function') {
            target.scrollIntoView({ block: 'center' });
        }
    }
    function renderFocus() {
        if (!form) return;
        var on = form.classList.contains('journey-focus-mode');
        var current = focusFields[focusIndex] || null;
        var statusText = on && focusFields.length ? focusStatusText() : 'Dashboard mode';
        if (journeyShell) journeyShell.classList.toggle('is-focus-mode', on);
        focusFields.forEach(function(entry, index){ entry.field.classList.toggle('is-focus-current', !on || index === focusIndex); });
        focusButtons.forEach(function(button){
            button.classList.toggle('is-focus-active', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
            button.setAttribute('title', on ? 'Exit focused question mode' : 'Toggle focused question mode');
        });
        focusToggleLabels.forEach(function(label){ label.textContent = on ? 'Focus On' : 'Focus Mode'; });
        if (focusStrip) focusStrip.hidden = !on;
        if (focusStripStatus) focusStripStatus.textContent = statusText;
        if (focusStripTitle) focusStripTitle.textContent = current ? current.label : 'Current question';
        if (focusStatus) focusStatus.textContent = statusText;
        focusPrevButtons.forEach(function(button){ button.disabled = !on || focusIndex <= 0; });
        focusNextButtons.forEach(function(button){ button.disabled = !on || focusIndex >= focusFields.length - 1; });
    }
    focusButtons.forEach(function(button){
        button.addEventListener('click', function(){
            if (!form) return;
            form.classList.toggle('journey-focus-mode');
            renderFocus();
            if (form.classList.contains('journey-focus-mode')) {
                scrollFocusTarget(focusStrip || (focusFields[focusIndex] && focusFields[focusIndex].field));
            }
        });
    });
    focusPrevButtons.forEach(function(button){
        button.addEventListener('click', function(){
            if (!focusFields.length) return;
            focusIndex = Math.max(0, focusIndex - 1);
            renderFocus();
            scrollFocusTarget(focusFields[focusIndex].field);
        });
    });
    focusNextButtons.forEach(function(button){
        button.addEventListener('click', function(){
            if (!focusFields.length) return;
            focusIndex = Math.min(focusFields.length - 1, focusIndex + 1);
            renderFocus();
            scrollFocusTarget(focusFields[focusIndex].field);
        });
    });
    renderFocus();
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
