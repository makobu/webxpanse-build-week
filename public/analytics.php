<?php
/**
 * Enhanced Analytics Dashboard Page
 * Comprehensive business intelligence and insights
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Modules\AnalyticsDashboard;
use CRM\Modules\AnalyticsInsights;
use CRM\Modules\Deals;
use CRM\Modules\Currencies;
use CRM\Modules\OutcomeMetrics;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\OutcomeRolloutService;
use CRM\Services\PageGuideVideoUi;
use CRM\Services\WorkspaceBusinessIntelligenceGateService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$analytics = new AnalyticsDashboard();
$analyticsWorkspace = new AnalyticsWorkspaceService();
$insightsEngine = new AnalyticsInsights();
$dealsModule = new Deals();
$currenciesModule = new Currencies();
$defaultCurrency = $currenciesModule->getDefault();
$currentUserId = (int) ($user['id'] ?? 0);
$canViewAllAnalytics = Authorization::can('analytics.view_all', $user);
$workspaceId = $analyticsWorkspace->requireAnalyticsWorkspaceId();
(new WorkspaceBusinessIntelligenceGateService())->enforceWeb($workspaceId, $user, 'Business Intelligence');
$hasSelectedUserParam = array_key_exists('user_id', $_GET);
$selectedUserId = null;
if (!$hasSelectedUserParam) {
    $selectedUserId = $currentUserId > 0 ? $currentUserId : null;
} elseif ($canViewAllAnalytics && ($_GET['user_id'] ?? '') === '') {
    $selectedUserId = null;
} elseif ($canViewAllAnalytics && ctype_digit((string) ($_GET['user_id'] ?? ''))) {
    $selectedUserId = $analyticsWorkspace->ensureScopedUserId((int) $_GET['user_id'], $workspaceId);
} else {
    $selectedUserId = $currentUserId > 0 ? $currentUserId : null;
}
$allUsers = $analyticsWorkspace->listActiveWorkspaceUsers($workspaceId);
$selectedUserLabel = 'My Analytics';
if ($selectedUserId === null) {
    $selectedUserLabel = 'All Users';
} elseif ($selectedUserId !== $currentUserId) {
    foreach ($allUsers as $scopeUser) {
        if ((int) ($scopeUser['id'] ?? 0) === $selectedUserId) {
            $selectedUserLabel = trim((string) (($scopeUser['first_name'] ?? '') . ' ' . ($scopeUser['last_name'] ?? '')));
            if ($selectedUserLabel === '') {
                $selectedUserLabel = (string) ($scopeUser['email'] ?? 'Selected User');
            }
            break;
        }
    }
}
$showOutcomeAdminPanel = Authorization::can('admin.users.manage', $user);
$outcomeAdminSummary = null;
if ($showOutcomeAdminPanel) {
    try {
        $rollout = new OutcomeRolloutService();
        if ($rollout->isEnabledForUser((int) ($user['id'] ?? 0))) {
            $outcome = new OutcomeMetrics();
            $outcomeAdminSummary = [
                'ttfv' => $outcome->getTTFVSummary(14),
                'activation' => $outcome->getActivationRateSummary(7),
                'revenue_action' => $outcome->getRevenueActionRateSummary(7),
            ];
            $ttfvTrend = (float) ($outcomeAdminSummary['ttfv']['trend_vs_prev_pct'] ?? 0.0);
            $activationRate = (float) ($outcomeAdminSummary['activation']['rate'] ?? 0.0);
            $revenueActionRate = (float) ($outcomeAdminSummary['revenue_action']['rate'] ?? 0.0);
            if ($ttfvTrend > 20.0) {
                error_log('[OUTCOME_ALERT] TTFV worsening >20% WoW (' . $ttfvTrend . '%)');
            }
            if ($activationRate < 15.0) {
                error_log('[OUTCOME_ALERT] Activation rate drop risk: current=' . $activationRate . '%');
            }
            if ($revenueActionRate < 40.0) {
                error_log('[OUTCOME_ALERT] Revenue action rate below threshold: ' . $revenueActionRate . '%');
            }
        }
    } catch (\Throwable $e) {
        $outcomeAdminSummary = null;
    }
}

// Get timeframe filter
$timeframe = $_GET['timeframe'] ?? 'month';
$attributionModel = $_GET['attribution_model'] ?? 'last_touch';
$validTimeframes = ['today', 'week', 'month', 'year', 'all'];
if (!in_array($timeframe, $validTimeframes)) {
    $timeframe = 'month';
}
if (!in_array($attributionModel, ['first_touch', 'last_touch', 'linear', 'time_decay', 'position_based'], true)) {
    $attributionModel = 'last_touch';
}

// Calculate date ranges
$endDate = date('Y-m-d');
$startDate = match($timeframe) {
    'today' => date('Y-m-d'),
    'week' => date('Y-m-d', strtotime('-7 days')),
    'month' => date('Y-m-d', strtotime('-30 days')),
    'year' => date('Y-m-d', strtotime('-365 days')),
    default => date('Y-m-d', strtotime('-30 days'))
};

$previousStartDate = match($timeframe) {
    'today' => date('Y-m-d', strtotime('-1 day')),
    'week' => date('Y-m-d', strtotime('-14 days')),
    'month' => date('Y-m-d', strtotime('-60 days')),
    'year' => date('Y-m-d', strtotime('-730 days')),
    default => date('Y-m-d', strtotime('-60 days'))
};
$previousEndDate = $startDate;

// Get metrics
$metricsToday = $analytics->getRealTimeMetrics('today', $selectedUserId);
$metricsWeek = $analytics->getRealTimeMetrics('week', $selectedUserId);
$metricsMonth = $analytics->getRealTimeMetrics('month', $selectedUserId);
$metricsCurrent = $analytics->getRealTimeMetrics($timeframe, $selectedUserId);

// Get period comparison
$periodComparison = $analytics->getPeriodComparison($timeframe, match($timeframe) {
    'today' => 'week',
    'week' => 'month',
    'month' => 'year',
    default => 'month'
}, $selectedUserId);

// Get growth metrics
$growthMetrics = $analytics->getGrowthMetrics($timeframe, $selectedUserId);

// Get revenue analytics
$revenueAnalytics = $analytics->getRevenueAnalytics($startDate, $endDate, $selectedUserId);
$previousRevenue = $analytics->getRevenueAnalytics($previousStartDate, $previousEndDate, $selectedUserId);
$attributionRevenue = [];
$attributionChannels = [];
if (($_ENV['ATTRIBUTION_ENABLED'] ?? '1') === '1') {
    try {
        $attributionRevenue = $analytics->getAttributionRevenueByCampaign($attributionModel, $startDate, $endDate, $selectedUserId);
        $attributionChannels = $analytics->getAttributionChannelSplit($attributionModel, $startDate, $endDate, $selectedUserId);
    } catch (\Throwable $e) {
        $attributionRevenue = [];
        $attributionChannels = [];
    }
}

// Get activity patterns
$activityPatterns = $analytics->getActivityPatterns($timeframe, $selectedUserId);

// Get conversion funnel
$conversionFunnel = $analytics->getConversionFunnel($startDate, $endDate, $selectedUserId);

// Get bottlenecks
$bottlenecks = $analytics->getBottlenecks($timeframe, $selectedUserId);

// Get KPIs
$kpis = $analytics->getKPIs($timeframe, $selectedUserId);
$cohortRetention = $analytics->getCohortRetentionMetrics(6, 6, $selectedUserId);

// Get top performers
$topDeals = $analytics->getTopPerformers('deals', $timeframe, $selectedUserId);
$topContacts = $analytics->getTopPerformers('contacts', $timeframe, $selectedUserId);
$topUsers = $analytics->getTopPerformers('users', $timeframe, $selectedUserId);
$topSources = $analytics->getTopPerformers('sources', $timeframe, $selectedUserId);

// Prepare data for insights
$insightsData = [
    'email_open_rate' => $kpis['email_open_rate'] ?? 0,
    'conversion_rate' => ($kpis['win_rate'] ?? 0),
    'funnel_drop_offs' => array_map(function($stage, $data) {
        return [
            'from_stage' => $stage,
            'to_stage' => 'next',
            'drop_off_rate' => $data['drop_off_rate'] ?? 0
        ];
    }, array_keys($conversionFunnel), array_values($conversionFunnel)),
    'growth' => $growthMetrics['overall_growth'] ?? 0,
    'avg_response_time' => $activityPatterns['response_times']['avg_response_hours'] ?? null
];

// Generate insights
$insights = $insightsEngine->generateInsights($insightsData, [
    'funnel_drop_offs' => $insightsData['funnel_drop_offs'],
    'growth' => $insightsData['growth']
]);

// Identify opportunities
$opportunitiesData = [
    'high_value_deals' => array_filter($topDeals, fn($d) => ($d['value'] ?? 0) > 10000),
    'top_sources' => $topSources
];
$opportunities = $insightsEngine->identifyOpportunities($opportunitiesData);

// Generate recommendations
$recommendations = $insightsEngine->generateRecommendations($insights);

// Calculate benchmarks
$benchmarks = $insightsEngine->calculateBenchmarks([
    'email_open_rate' => $kpis['email_open_rate'] ?? 0,
    'email_click_rate' => $kpis['email_click_rate'] ?? 0,
    'conversion_rate' => $kpis['win_rate'] ?? 0,
    'response_time_hours' => $activityPatterns['response_times']['avg_response_hours'] ?? 0
]);

$contactScopeSql = '';
$contactScopeParams = [$workspaceId];
if ($selectedUserId !== null) {
    $contactScopeSql = ' AND assigned_to = ?';
    $contactScopeParams[] = $selectedUserId;
}

$emailScopeSql = '';
$emailScopeParams = [$workspaceId];
if ($selectedUserId !== null) {
    $emailScopeSql = ' AND e.user_id = ?';
    $emailScopeParams[] = $selectedUserId;
}

// Get daily trends for charts
$dailyTrends = Database::query(
    "SELECT 
        DATE(created_at) as date,
        COUNT(*) as contacts
     FROM contacts
     WHERE workspace_id = ?
       AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     {$contactScopeSql}
     GROUP BY DATE(created_at)
     ORDER BY date ASC",
    $contactScopeParams
);

// Get stage distribution
$stageDistribution = Database::query(
    "SELECT stage, COUNT(*) as count 
     FROM contacts 
     WHERE workspace_id = ?{$contactScopeSql}
     GROUP BY stage 
     ORDER BY 
        CASE stage
            WHEN 'new' THEN 1
            WHEN 'contacted' THEN 2
            WHEN 'qualified' THEN 3
            WHEN 'proposal' THEN 4
            WHEN 'negotiation' THEN 5
            WHEN 'won' THEN 6
            WHEN 'lost' THEN 7
        END",
    $contactScopeParams
);

// Get email performance
$emailPerformance = Database::query(
    "SELECT 
        DATE(e.created_at) as date,
        COUNT(DISTINCT e.id) as sent,
        COUNT(DISTINCT CASE WHEN et.tracking_type = 'open' THEN et.email_id END) as opened,
        COUNT(DISTINCT CASE WHEN et.tracking_type = 'click' THEN et.email_id END) as clicked
     FROM emails e
     LEFT JOIN email_tracking et ON e.id = et.email_id
     WHERE e.workspace_id = ?
       AND e.status IN ('sent', 'delivered', 'opened', 'clicked')
       AND e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     {$emailScopeSql}
     GROUP BY DATE(e.created_at)
     ORDER BY date ASC",
    $emailScopeParams
);

// Get lead source breakdown
$leadSourceBreakdown = Database::query(
    "SELECT lead_source, COUNT(*) as count 
     FROM contacts 
     WHERE workspace_id = ?
       AND lead_source IS NOT NULL
     {$contactScopeSql}
     GROUP BY lead_source 
     ORDER BY count DESC",
    $contactScopeParams
);

$timeframeLabels = [
    'today' => 'Today',
    'week' => 'Last 7 Days',
    'month' => 'Last 30 Days',
    'year' => 'Last Year',
    'all' => 'All Time',
];
$attributionLabels = [
    'last_touch' => 'Last Touch',
    'first_touch' => 'First Touch',
    'linear' => 'Linear',
    'time_decay' => 'Time Decay',
    'position_based' => 'Position Based',
];
$currentTimeframeLabel = $timeframeLabels[$timeframe] ?? 'Last 30 Days';
$currentAttributionLabel = $attributionLabels[$attributionModel] ?? 'Last Touch';
$hasFunnelBottlenecks = !empty($bottlenecks['highest_drop_offs'] ?? []);
$analyticsAsyncQuery = http_build_query([
    'timeframe' => $timeframe,
    'attribution_model' => $attributionModel,
    'user_id' => $selectedUserId === null ? '' : (string) $selectedUserId,
]);
$analyticsAsyncEndpoint = static function (string $endpoint) use ($analyticsAsyncQuery): string {
    return apiUrl('analytics/' . $endpoint . '.php') . '?' . $analyticsAsyncQuery;
};
$analyticsSectionUrl = static function (string $sectionKey) use ($timeframe, $attributionModel, $selectedUserId): string {
    return '?' . http_build_query([
        'timeframe' => $timeframe,
        'attribution_model' => $attributionModel,
        'user_id' => $selectedUserId === null ? '' : (string) $selectedUserId,
        'section' => $sectionKey,
    ]);
};
$analyticsSectionNav = [
    ['key' => 'overview', 'id' => 'analytics-section-overview', 'label' => 'Overview'],
    ['key' => 'operating', 'id' => 'analytics-section-operating', 'label' => 'Operating'],
    ['key' => 'revenue', 'id' => 'analytics-section-revenue', 'label' => 'Revenue'],
    ['key' => 'channels', 'id' => 'analytics-section-channels', 'label' => 'Channels'],
    ['key' => 'ai', 'id' => 'analytics-section-ai', 'label' => 'AI & Automation'],
    ['key' => 'founder', 'id' => 'analytics-section-founder', 'label' => 'Founder'],
    ['key' => 'targets', 'id' => 'analytics-section-targets', 'label' => 'Targets'],
    ['key' => 'marketplace', 'id' => 'analytics-section-marketplace', 'label' => 'Skills'],
    ['key' => 'operations', 'id' => 'analytics-section-operations', 'label' => 'Operations'],
    ['key' => 'kpis', 'id' => 'analytics-section-kpis', 'label' => 'KPIs'],
    ['key' => 'retention', 'id' => 'analytics-section-retention', 'label' => 'Retention'],
    ['key' => 'funnel', 'id' => 'analytics-section-funnel', 'label' => 'Funnel'],
    ['key' => 'activity', 'id' => 'analytics-section-activity', 'label' => 'Activity'],
    ['key' => 'performers', 'id' => 'analytics-section-performers', 'label' => 'Performers'],
    ['key' => 'trends', 'id' => 'analytics-section-trends', 'label' => 'Trends'],
    ['key' => 'opportunities', 'id' => 'analytics-section-opportunities', 'label' => 'Opportunities'],
];

$pageTitle = 'Analytics Dashboard - ' . brandProductName();
$analyticsGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_ANALYTICS);
ob_start();
?>

<link rel="stylesheet" href="assets/css/analytics-premium.css?v=analytics-calm-20260523">
<?php echo PageGuideVideoUi::assets(); ?>

<div class="analytics-premium">
    <!-- Header -->
    <header class="analytics-header">
        <div class="analytics-header-main">
            <div class="analytics-kicker"><i class="fas fa-chart-pie"></i> Business Intelligence</div>
            <h1>Analytics Dashboard</h1>
            <p class="subtitle">Comprehensive business intelligence and actionable insights for <?php echo htmlspecialchars($selectedUserLabel); ?></p>
            <div class="analytics-context-chips" aria-label="Current analytics context">
                <span class="analytics-chip"><i class="fas fa-calendar-days"></i><?php echo htmlspecialchars($currentTimeframeLabel); ?></span>
                <span class="analytics-chip"><i class="fas fa-route"></i><?php echo htmlspecialchars($currentAttributionLabel); ?></span>
                <span class="analytics-chip"><i class="fas fa-user"></i><?php echo htmlspecialchars($selectedUserLabel); ?></span>
            </div>
        </div>
        <div class="analytics-filter-bar" aria-label="Analytics filters">
            <label class="analytics-filter">
                <span>Period</span>
            <select 
                id="timeframe_filter" 
                onchange="window.location.href='?timeframe=' + this.value + '&attribution_model=<?php echo urlencode($attributionModel); ?>&user_id=<?php echo urlencode($selectedUserId === null ? '' : (string) $selectedUserId); ?>'"
                class="timeframe-select"
            >
                <option value="today" <?php echo $timeframe === 'today' ? 'selected' : ''; ?>>Today</option>
                <option value="week" <?php echo $timeframe === 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                <option value="month" <?php echo $timeframe === 'month' ? 'selected' : ''; ?>>Last 30 Days</option>
                <option value="year" <?php echo $timeframe === 'year' ? 'selected' : ''; ?>>Last Year</option>
                <option value="all" <?php echo $timeframe === 'all' ? 'selected' : ''; ?>>All Time</option>
            </select>
            </label>
            <label class="analytics-filter">
                <span>Attribution</span>
            <select
                id="attribution_model_filter"
                onchange="window.location.href='?timeframe=<?php echo urlencode($timeframe); ?>&attribution_model=' + this.value + '&user_id=<?php echo urlencode($selectedUserId === null ? '' : (string) $selectedUserId); ?>'"
                class="timeframe-select"
            >
                <option value="last_touch" <?php echo $attributionModel === 'last_touch' ? 'selected' : ''; ?>>Attribution: Last Touch</option>
                <option value="first_touch" <?php echo $attributionModel === 'first_touch' ? 'selected' : ''; ?>>Attribution: First Touch</option>
                <option value="linear" <?php echo $attributionModel === 'linear' ? 'selected' : ''; ?>>Attribution: Linear</option>
                <option value="time_decay" <?php echo $attributionModel === 'time_decay' ? 'selected' : ''; ?>>Attribution: Time Decay</option>
                <option value="position_based" <?php echo $attributionModel === 'position_based' ? 'selected' : ''; ?>>Attribution: Position Based</option>
            </select>
            </label>
            <label class="analytics-filter">
                <span>Scope</span>
            <select
                id="user_scope_filter"
                onchange="window.location.href='?timeframe=<?php echo urlencode($timeframe); ?>&attribution_model=<?php echo urlencode($attributionModel); ?>&user_id=' + this.value"
                class="timeframe-select"
            >
                <option value="<?php echo htmlspecialchars((string) $currentUserId); ?>" <?php echo $selectedUserId === $currentUserId ? 'selected' : ''; ?>>My Analytics</option>
                <?php if ($canViewAllAnalytics): ?>
                    <option value="" <?php echo $selectedUserId === null ? 'selected' : ''; ?>>All Users</option>
                    <?php foreach ($allUsers as $scopeUser): ?>
                        <?php
                        $scopeUserId = (int) ($scopeUser['id'] ?? 0);
                        if ($scopeUserId <= 0 || $scopeUserId === $currentUserId) {
                            continue;
                        }
                        $scopeUserLabel = trim((string) (($scopeUser['first_name'] ?? '') . ' ' . ($scopeUser['last_name'] ?? '')));
                        if ($scopeUserLabel === '') {
                            $scopeUserLabel = (string) ($scopeUser['email'] ?? ('User #' . $scopeUserId));
                        }
                        ?>
                        <option value="<?php echo $scopeUserId; ?>" <?php echo $selectedUserId === $scopeUserId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($scopeUserLabel); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            </label>
            <?php if ($analyticsGuideVideoUrl !== ''): ?>
                <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_ANALYTICS, 'Analytics page guide'); ?>
            <?php endif; ?>
        </div>
    </header>

    <section class="analytics-operating-snapshot analytics-async-section" id="analytics-section-operating" data-analytics-section-key="operating" data-analytics-async-section="operating_snapshot" data-analytics-endpoint="<?php echo htmlspecialchars($analyticsAsyncEndpoint('operating_snapshot')); ?>">
        <div class="analytics-async-head">
            <div>
                <h2><i class="fas fa-compass"></i> Operating Snapshot</h2>
                <p>Cross-product signals for the current workspace scope.</p>
            </div>
            <span class="analytics-async-status" data-analytics-async-status>Loading</span>
        </div>
        <div class="analytics-async-grid" data-analytics-metrics></div>
        <div class="analytics-insight-list" data-analytics-insights></div>
        <div class="analytics-async-chart-list" data-analytics-charts></div>
    </section>

    <section class="analytics-overview-summary" id="analytics-section-summary" data-analytics-section-key="summary">
    <!-- Executive Summary -->
    <div class="section-title-bar">
        <h2><i class="fas fa-chart-line"></i> Executive Summary</h2>
    </div>
    
    <div class="executive-summary-grid">
        <?php
        $summaryMetrics = [
            ['label' => 'Total Contacts', 'current' => (int) (Database::queryOne("SELECT COUNT(*) as count FROM contacts WHERE workspace_id = ?{$contactScopeSql}", $contactScopeParams)['count'] ?? 0), 'previous' => 0, 'key' => 'contacts', 'icon' => 'fa-address-book', 'importance' => 'high'],
            ['label' => 'Revenue', 'current' => $revenueAnalytics['total_revenue'], 'previous' => $previousRevenue['total_revenue'] ?? 0, 'key' => 'revenue', 'format' => 'currency', 'icon' => 'fa-dollar-sign', 'importance' => 'high'],
            ['label' => 'Win Rate', 'current' => $revenueAnalytics['win_rate'], 'previous' => $previousRevenue['win_rate'] ?? 0, 'key' => 'win_rate', 'format' => 'percent', 'icon' => 'fa-bullseye', 'importance' => 'medium'],
            ['label' => 'Email Open Rate', 'current' => $kpis['email_open_rate'], 'previous' => 0, 'key' => 'email_open', 'format' => 'percent', 'icon' => 'fa-envelope-open-text', 'importance' => 'medium']
        ];
        
        foreach ($summaryMetrics as $metric):
            $change = $metric['previous'] > 0 ? (($metric['current'] - $metric['previous']) / $metric['previous']) * 100 : ($metric['current'] > 0 ? 100 : 0);
            $trendClass = $change > 5 ? 'trend-up' : ($change < -5 ? 'trend-down' : 'trend-neutral');
            $trendIcon = $change > 5 ? 'Up' : ($change < -5 ? 'Down' : 'Flat');
            
            $displayValue = $metric['current'];
            if (isset($metric['format'])) {
                if ($metric['format'] === 'currency') {
                    if ($defaultCurrency) {
                        $displayValue = $currenciesModule->formatAmount($metric['current'], $defaultCurrency['code']);
                    } else {
                        $displayValue = '$' . number_format($metric['current'], 2);
                    }
                } elseif ($metric['format'] === 'percent') {
                    $displayValue = number_format($metric['current'], 1) . '%';
                } else {
                    $displayValue = number_format($metric['current']);
                }
            } else {
                $displayValue = number_format($metric['current']);
            }
        ?>
        <div class="summary-card summary-card-<?php echo htmlspecialchars($metric['importance']); ?>">
            <div class="summary-card-header">
                <div class="summary-label"><?php echo $metric['label']; ?></div>
                <div class="summary-icon"><i class="fas <?php echo htmlspecialchars($metric['icon']); ?>"></i></div>
            </div>
            <div class="summary-value"><?php echo $displayValue; ?></div>
            <?php if ($metric['previous'] > 0): ?>
            <div class="summary-trend <?php echo $trendClass; ?>">
                <span><?php echo $trendIcon; ?> <?php echo abs(round($change, 1)); ?>%</span>
                <span>vs previous period</span>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($outcomeAdminSummary)): ?>
    <div class="content-card analytics-snapshot-card">
        <div class="analytics-card-heading">
            <h3>Outcome Layer Weekly Snapshot</h3>
            <span>Admin health view</span>
        </div>
        <div class="analytics-mini-grid">
            <div class="analytics-mini-card">
                <div class="mini-label">TTFV Median (14d)</div>
                <div class="mini-value"><?php echo number_format((float) ($outcomeAdminSummary['ttfv']['median_hours'] ?? 0), 1); ?>h</div>
                <div class="mini-note">Trend <?php echo number_format((float) ($outcomeAdminSummary['ttfv']['trend_vs_prev_pct'] ?? 0), 1); ?>%</div>
            </div>
            <div class="analytics-mini-card">
                <div class="mini-label">Activation Rate (7d)</div>
                <div class="mini-value"><?php echo number_format((float) ($outcomeAdminSummary['activation']['rate'] ?? 0), 1); ?>%</div>
                <div class="mini-note"><?php echo (int) ($outcomeAdminSummary['activation']['activated'] ?? 0); ?>/<?php echo (int) ($outcomeAdminSummary['activation']['eligible'] ?? 0); ?> users</div>
            </div>
            <div class="analytics-mini-card">
                <div class="mini-label">Revenue Action Rate (7d)</div>
                <div class="mini-value"><?php echo number_format((float) ($outcomeAdminSummary['revenue_action']['rate'] ?? 0), 1); ?>%</div>
                <div class="mini-note"><?php echo (int) ($outcomeAdminSummary['revenue_action']['revenue_users'] ?? 0); ?>/<?php echo (int) ($outcomeAdminSummary['revenue_action']['active_users'] ?? 0); ?> active users</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    </section>

    <nav class="analytics-section-nav" aria-label="Analytics sections">
        <?php foreach ($analyticsSectionNav as $section): ?>
            <a
                href="<?php echo htmlspecialchars($analyticsSectionUrl($section['key'])); ?>"
                data-analytics-section-control="<?php echo htmlspecialchars($section['key']); ?>"
                data-analytics-section-target="<?php echo htmlspecialchars($section['id']); ?>"
            >
                <?php echo htmlspecialchars($section['label']); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="analytics-v2-sections" aria-label="Operating analytics">
        <details class="analytics-section analytics-details analytics-async-section" id="analytics-section-channels" data-analytics-section-key="channels" data-analytics-collapsible data-analytics-async-section="channels" data-analytics-endpoint="<?php echo htmlspecialchars($analyticsAsyncEndpoint('channels')); ?>" open>
            <summary>
                <span><i class="fas fa-satellite-dish"></i> Channels</span>
                <small>Email, WhatsApp, inbox, forms</small>
            </summary>
            <div class="analytics-async-body">
                <div class="analytics-async-status" data-analytics-async-status>Loading</div>
                <div class="analytics-async-grid" data-analytics-metrics></div>
                <div class="analytics-insight-list" data-analytics-insights></div>
                <div class="analytics-async-chart-list" data-analytics-charts></div>
            </div>
        </details>

        <details class="analytics-section analytics-details analytics-async-section" id="analytics-section-ai" data-analytics-section-key="ai" data-analytics-collapsible data-analytics-async-section="ai_automation" data-analytics-endpoint="<?php echo htmlspecialchars($analyticsAsyncEndpoint('ai_automation')); ?>" open>
            <summary>
                <span><i class="fas fa-wand-magic-sparkles"></i> AI & Automation</span>
                <small>Battery, blockers, workflows</small>
            </summary>
            <div class="analytics-async-body">
                <div class="analytics-async-status" data-analytics-async-status>Loading</div>
                <div class="analytics-async-grid" data-analytics-metrics></div>
                <div class="analytics-insight-list" data-analytics-insights></div>
                <div class="analytics-async-chart-list" data-analytics-charts></div>
            </div>
        </details>

        <details class="analytics-section analytics-details analytics-async-section" id="analytics-section-founder" data-analytics-section-key="founder" data-analytics-collapsible data-analytics-async-section="founder_journey" data-analytics-endpoint="<?php echo htmlspecialchars($analyticsAsyncEndpoint('founder_journey')); ?>">
            <summary>
                <span><i class="fas fa-route"></i> Founder & Journey</span>
                <small>Clarity Journey and Founder Loop</small>
            </summary>
            <div class="analytics-async-body">
                <div class="analytics-async-status" data-analytics-async-status>Open to load</div>
                <div class="analytics-async-grid" data-analytics-metrics></div>
                <div class="analytics-insight-list" data-analytics-insights></div>
                <div class="analytics-async-chart-list" data-analytics-charts></div>
            </div>
        </details>

        <details class="analytics-section analytics-details analytics-async-section" id="analytics-section-targets" data-analytics-section-key="targets" data-analytics-collapsible data-analytics-async-section="targets_tasks" data-analytics-endpoint="<?php echo htmlspecialchars($analyticsAsyncEndpoint('targets_tasks')); ?>">
            <summary>
                <span><i class="fas fa-bullseye"></i> Targets & Tasks</span>
                <small>Execution health</small>
            </summary>
            <div class="analytics-async-body">
                <div class="analytics-async-status" data-analytics-async-status>Open to load</div>
                <div class="analytics-async-grid" data-analytics-metrics></div>
                <div class="analytics-insight-list" data-analytics-insights></div>
                <div class="analytics-async-chart-list" data-analytics-charts></div>
            </div>
        </details>

        <details class="analytics-section analytics-details analytics-async-section" id="analytics-section-marketplace" data-analytics-section-key="marketplace" data-analytics-collapsible data-analytics-async-section="marketplace_skills" data-analytics-endpoint="<?php echo htmlspecialchars($analyticsAsyncEndpoint('marketplace_skills')); ?>">
            <summary>
                <span><i class="fas fa-puzzle-piece"></i> Marketplace & Skills</span>
                <small>Installed skills and usage</small>
            </summary>
            <div class="analytics-async-body">
                <div class="analytics-async-status" data-analytics-async-status>Open to load</div>
                <div class="analytics-async-grid" data-analytics-metrics></div>
                <div class="analytics-insight-list" data-analytics-insights></div>
                <div class="analytics-async-chart-list" data-analytics-charts></div>
            </div>
        </details>

        <details class="analytics-section analytics-details analytics-async-section" id="analytics-section-operations" data-analytics-section-key="operations" data-analytics-collapsible data-analytics-async-section="operations" data-analytics-endpoint="<?php echo htmlspecialchars($analyticsAsyncEndpoint('operations')); ?>">
            <summary>
                <span><i class="fas fa-shield-halved"></i> Operations</span>
                <small>Admin health signals</small>
            </summary>
            <div class="analytics-async-body">
                <div class="analytics-async-status" data-analytics-async-status>Open to load</div>
                <div class="analytics-async-grid" data-analytics-metrics></div>
                <div class="analytics-insight-list" data-analytics-insights></div>
                <div class="analytics-async-chart-list" data-analytics-charts></div>
            </div>
        </details>
    </div>

    <!-- Key Insights -->
    <?php if (!empty($insights)): ?>
    <details class="analytics-section analytics-details analytics-insights-section" data-analytics-section-key="insights" data-analytics-collapsible>
        <summary>
            <span><i class="fas fa-lightbulb"></i> Key Insights</span>
            <small><?php echo count($insights); ?> signals</small>
        </summary>
        <div class="insights-grid">
            <?php foreach (array_slice($insights, 0, 6) as $insight): 
                $iconMap = [
                    'warning' => 'fa-exclamation-triangle',
                    'success' => 'fa-check-circle',
                    'danger' => 'fa-times-circle',
                    'info' => 'fa-info-circle'
                ];
                $icon = $iconMap[$insight['type']] ?? 'fa-info-circle';
            ?>
            <div class="insight-card insight-<?php echo $insight['type']; ?>">
                <div class="insight-icon">
                    <i class="fas <?php echo $icon; ?>"></i>
                </div>
                <div class="insight-content">
                    <h3><?php echo htmlspecialchars($insight['title']); ?></h3>
                    <p><?php echo htmlspecialchars($insight['message']); ?></p>
                    <div class="insight-recommendation">
                        <strong>Recommendation:</strong> <?php echo htmlspecialchars($insight['recommendation']); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>

    <!-- Revenue Analytics -->
    <section class="analytics-section" id="analytics-section-revenue" data-analytics-section-key="revenue">
    <div class="section-title-bar">
        <h2><i class="fas fa-dollar-sign"></i> Revenue Analytics</h2>
    </div>
    
    <div class="revenue-grid">
        <div class="revenue-card">
            <div class="revenue-card-header">
                <div class="revenue-label">Total Revenue</div>
                <div class="revenue-icon"><i class="fas fa-dollar-sign"></i></div>
            </div>
            <div class="revenue-value">
                <?php 
                if ($defaultCurrency) {
                    echo $currenciesModule->formatAmount($revenueAnalytics['total_revenue'], $defaultCurrency['code']);
                } else {
                    echo '$' . number_format($revenueAnalytics['total_revenue'], 2);
                }
                ?>
            </div>
            <?php 
            $revenueChange = $previousRevenue['total_revenue'] > 0 
                ? (($revenueAnalytics['total_revenue'] - $previousRevenue['total_revenue']) / $previousRevenue['total_revenue']) * 100 
                : 0;
            $revenueTrend = $revenueChange > 0 ? 'trend-up' : ($revenueChange < 0 ? 'trend-down' : 'trend-neutral');
            $revenueIcon = $revenueChange > 0 ? 'Up' : ($revenueChange < 0 ? 'Down' : 'Flat');
            ?>
            <div class="revenue-trend <?php echo $revenueTrend; ?>">
                <?php echo $revenueIcon; ?> <?php echo abs(round($revenueChange, 1)); ?>% vs previous period
            </div>
        </div>
        
        <div class="revenue-card">
            <div class="revenue-card-header">
                <div class="revenue-label">Average Deal Size</div>
                <div class="revenue-icon"><i class="fas fa-balance-scale"></i></div>
            </div>
            <div class="revenue-value">
                <?php 
                if ($defaultCurrency) {
                    echo $currenciesModule->formatAmount($revenueAnalytics['avg_deal_size'], $defaultCurrency['code']);
                } else {
                    echo '$' . number_format($revenueAnalytics['avg_deal_size'], 2);
                }
                ?>
            </div>
            <div class="revenue-subtext"><?php echo $revenueAnalytics['deal_count']; ?> deals won</div>
        </div>
        
        <div class="revenue-card">
            <div class="revenue-card-header">
                <div class="revenue-label">Win Rate</div>
                <div class="revenue-icon"><i class="fas fa-trophy"></i></div>
            </div>
            <div class="revenue-value"><?php echo number_format($revenueAnalytics['win_rate'], 1); ?>%</div>
            <div class="revenue-subtext"><?php echo number_format(100 - $revenueAnalytics['win_rate'], 1); ?>% loss rate</div>
        </div>
        
        <div class="revenue-card">
            <div class="revenue-card-header">
                <div class="revenue-label">Sales Cycle</div>
                <div class="revenue-icon"><i class="fas fa-clock"></i></div>
            </div>
            <div class="revenue-value"><?php echo number_format($revenueAnalytics['avg_sales_cycle_days'], 0); ?> days</div>
            <div class="revenue-subtext">Average time to close</div>
        </div>
    </div>

    <?php if (!empty($attributionRevenue)): ?>
    <div class="content-card analytics-snapshot-card">
        <div class="analytics-card-heading">
            <h3>Attribution Snapshot (<?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($attributionModel))); ?>)</h3>
            <span>Top credited campaigns</span>
        </div>
        <div class="analytics-mini-grid analytics-mini-grid-wide">
            <?php foreach (array_slice($attributionRevenue, 0, 4) as $row): ?>
                <div class="analytics-mini-card">
                    <div class="mini-label mini-label-strong"><?php echo htmlspecialchars($row['campaign_name'] ?? 'Unassigned'); ?></div>
                    <div class="mini-note">
                        <?php
                        if ($defaultCurrency) {
                            echo $currenciesModule->formatAmount((float) ($row['credited_revenue'] ?? 0), $defaultCurrency['code']);
                        } else {
                            echo '$' . number_format((float) ($row['credited_revenue'] ?? 0), 2);
                        }
                        ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($attributionChannels)): ?>
            <div class="analytics-channel-split">
                Channel split:
                <?php foreach ($attributionChannels as $index => $channel): ?>
                    <?php echo $index > 0 ? ' | ' : ''; ?>
                    <?php echo htmlspecialchars($channel['channel']); ?> (<?php echo number_format((float) ($channel['total_weight'] ?? 0) * 100, 1); ?>%)
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Revenue Trend Chart -->
    <div class="chart-container">
        <div class="chart-header">
            <h3 class="chart-title">Revenue Trend</h3>
            <p class="chart-subtitle">Monthly revenue over time</p>
        </div>
        <canvas id="revenueChart"></canvas>
    </div>
    </section>

    <!-- Performance KPIs -->
    <section class="analytics-section" id="analytics-section-kpis" data-analytics-section-key="kpis">
    <div class="section-title-bar">
        <h2><i class="fas fa-tachometer-alt"></i> Performance KPIs</h2>
    </div>
    
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-icon"><i class="fas fa-chart-line"></i></div>
            <div class="kpi-label">Lifetime Value (LTV)</div>
            <div class="kpi-value">
                <?php 
                if ($defaultCurrency) {
                    echo $currenciesModule->formatAmount($kpis['ltv'], $defaultCurrency['code']);
                } else {
                    echo '$' . number_format($kpis['ltv'], 2);
                }
                ?>
            </div>
            <div class="kpi-benchmark">
                <?php 
                $ltvStatus = $benchmarks['ltv']['status'] ?? 'average';
                echo ucfirst($ltvStatus);
                ?>
            </div>
        </div>
        
        <div class="kpi-card">
            <div class="kpi-icon"><i class="fas fa-rocket"></i></div>
            <div class="kpi-label">Sales Velocity</div>
            <div class="kpi-value">
                <?php 
                if ($defaultCurrency) {
                    echo $currenciesModule->formatAmount($kpis['sales_velocity'], $defaultCurrency['code']);
                } else {
                    echo '$' . number_format($kpis['sales_velocity'], 2);
                }
                ?>
            </div>
            <div class="kpi-subtext">Per day</div>
        </div>
        
        <div class="kpi-card">
            <div class="kpi-icon"><i class="fas fa-envelope-open"></i></div>
            <div class="kpi-label">Email Open Rate</div>
            <div class="kpi-value"><?php echo number_format($kpis['email_open_rate'], 1); ?>%</div>
            <div class="kpi-benchmark">
                <?php 
                $emailStatus = $benchmarks['email_open_rate']['status'] ?? 'average';
                $statusLabels = ['excellent' => 'Excellent', 'good' => 'Good', 'average' => 'Average', 'below_average' => 'Below Avg'];
                echo $statusLabels[$emailStatus] ?? 'Average';
                ?>
            </div>
        </div>
        
        <div class="kpi-card">
            <div class="kpi-icon"><i class="fas fa-mouse-pointer"></i></div>
            <div class="kpi-label">Email Click Rate</div>
            <div class="kpi-value"><?php echo number_format($kpis['email_click_rate'], 1); ?>%</div>
            <div class="kpi-benchmark">
                <?php 
                $clickStatus = $benchmarks['email_click_rate']['status'] ?? 'average';
                echo $statusLabels[$clickStatus] ?? 'Average';
                ?>
            </div>
        </div>
    </div>
    </section>

    <!-- Cohort Retention -->
    <?php $cohortSummary = $cohortRetention['summary'] ?? []; ?>
    <details class="analytics-section analytics-details" id="analytics-section-retention" data-analytics-section-key="retention" data-analytics-collapsible>
    <summary>
        <span><i class="fas fa-layer-group"></i> Cohort Retention Metrics</span>
        <small><?php echo (int) ($cohortSummary['cohort_count'] ?? 0); ?> cohorts</small>
    </summary>
    <div class="content-card cohort-card">
        <div class="analytics-mini-grid">
            <div class="analytics-mini-card">
                <div class="mini-label">Avg M1 Retention</div>
                <div class="mini-value"><?php echo number_format((float) ($cohortSummary['avg_m1_retention'] ?? 0), 1); ?>%</div>
            </div>
            <div class="analytics-mini-card">
                <div class="mini-label">Avg M3 Retention</div>
                <div class="mini-value"><?php echo number_format((float) ($cohortSummary['avg_m3_retention'] ?? 0), 1); ?>%</div>
            </div>
            <div class="analytics-mini-card">
                <div class="mini-label">Avg M6 Retention</div>
                <div class="mini-value"><?php echo number_format((float) ($cohortSummary['avg_m6_retention'] ?? 0), 1); ?>%</div>
            </div>
            <div class="analytics-mini-card">
                <div class="mini-label">Cohorts Tracked</div>
                <div class="mini-value"><?php echo (int) ($cohortSummary['cohort_count'] ?? 0); ?></div>
            </div>
        </div>

        <div class="cohort-table-wrap">
            <table class="cohort-table">
                <thead>
                    <tr>
                        <th>Cohort</th>
                        <th class="is-numeric">Size</th>
                        <?php for ($p = 0; $p < (int) ($cohortRetention['period_months'] ?? 6); $p++): ?>
                            <th class="is-centered">M<?php echo $p; ?></th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($cohortRetention['matrix'] ?? []) as $cohortRow): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) ($cohortRow['cohort_label'] ?? '')); ?></td>
                            <td class="is-numeric"><?php echo (int) ($cohortRow['cohort_size'] ?? 0); ?></td>
                            <?php foreach (($cohortRow['periods'] ?? []) as $period): ?>
                                <?php
                                $rate = (float) ($period['retention_rate'] ?? 0);
                                $bg = '#fee2e2';
                                $fg = '#b91c1c';
                                if ($rate >= 75) { $bg = '#dcfce7'; $fg = '#166534'; }
                                elseif ($rate >= 50) { $bg = '#fef9c3'; $fg = '#854d0e'; }
                                elseif ($rate >= 25) { $bg = '#ffedd5'; $fg = '#9a3412'; }
                                ?>
                                <td class="is-centered">
                                    <span class="cohort-pill" style="background: <?php echo $bg; ?>; color: <?php echo $fg; ?>;">
                                        <?php echo number_format($rate, 1); ?>%
                                    </span>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($cohortRetention['matrix'])): ?>
                        <tr>
                            <td colspan="<?php echo 2 + (int) ($cohortRetention['period_months'] ?? 6); ?>" class="cohort-empty">
                                Not enough cohort data yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    </details>

    <!-- Enhanced Funnel Analysis -->
    <details class="analytics-section analytics-details" id="analytics-section-funnel" data-analytics-section-key="funnel" data-analytics-collapsible <?php echo $hasFunnelBottlenecks ? 'open' : ''; ?>>
    <summary>
        <span><i class="fas fa-filter"></i> Conversion Funnel Analysis</span>
        <small><?php echo $hasFunnelBottlenecks ? 'Bottleneck detected' : 'Open funnel details'; ?></small>
    </summary>
    
    <div class="funnel-container">
        <div class="funnel-visualization">
            <?php 
            $stages = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won'];
            $stageLabels = ['New', 'Contacted', 'Qualified', 'Proposal', 'Negotiation', 'Won'];
            $maxCount = max(array_column($conversionFunnel, 'count')) ?: 1;
            foreach ($stages as $index => $stage): 
                $data = $conversionFunnel[$stage] ?? ['count' => 0, 'drop_off_rate' => 0, 'avg_dwell_days' => 0];
                $count = $data['count'];
                $width = $maxCount > 0 ? ($count / $maxCount) * 100 : 0;
                $isBottleneck = false;
                foreach ($bottlenecks['highest_drop_offs'] ?? [] as $bottleneck) {
                    if ($bottleneck['from_stage'] === $stage) {
                        $isBottleneck = true;
                        break;
                    }
                }
            ?>
            <div class="funnel-stage <?php echo $isBottleneck ? 'bottleneck' : ''; ?>">
                <div class="funnel-stage-header">
                    <span class="funnel-stage-label"><?php echo $stageLabels[$index]; ?></span>
                    <span class="funnel-stage-count"><?php echo $count; ?> contacts</span>
                </div>
                <div class="funnel-bar-container">
                    <div class="funnel-bar" style="width: <?php echo $width; ?>%;">
                        <?php if ($count > 0): ?>
                            <span class="funnel-bar-text"><?php echo $count; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="funnel-stage-metrics">
                    <?php if ($index > 0 && $data['drop_off_rate'] > 0): ?>
                        <span class="funnel-metric drop-off">
                            <i class="fas fa-arrow-down"></i> <?php echo number_format($data['drop_off_rate'], 1); ?>% drop-off
                        </span>
                    <?php endif; ?>
                    <?php if ($data['avg_dwell_days'] > 0): ?>
                        <span class="funnel-metric dwell-time">
                            <i class="fas fa-clock"></i> <?php echo number_format($data['avg_dwell_days'], 1); ?> days avg
                        </span>
                    <?php endif; ?>
                    <?php if ($isBottleneck): ?>
                        <span class="funnel-metric bottleneck-alert">
                            <i class="fas fa-exclamation-triangle"></i> Bottleneck detected
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    </details>

    <!-- Activity & Engagement -->
    <details class="analytics-section analytics-details" id="analytics-section-activity" data-analytics-section-key="activity" data-analytics-collapsible>
    <summary>
        <span><i class="fas fa-chart-bar"></i> Activity & Engagement Patterns</span>
        <small>2 charts</small>
    </summary>
    
    <div class="charts-grid">
        <div class="chart-container">
            <div class="chart-header">
                <h3 class="chart-title">Activity by Day of Week</h3>
                <p class="chart-subtitle">Most active days</p>
            </div>
            <canvas id="activityDayChart"></canvas>
        </div>
        
        <div class="chart-container">
            <div class="chart-header">
                <h3 class="chart-title">Activity by Hour</h3>
                <p class="chart-subtitle">Peak engagement times</p>
            </div>
            <canvas id="activityHourChart"></canvas>
        </div>
    </div>
    </details>

    <!-- Top Performers -->
    <details class="analytics-section analytics-details" id="analytics-section-performers" data-analytics-section-key="performers" data-analytics-collapsible>
    <summary>
        <span><i class="fas fa-trophy"></i> Top Performers</span>
        <small>Deals and sources</small>
    </summary>
    
    <div class="performers-grid">
        <div class="performer-section">
            <h3>Top Deals by Value</h3>
            <?php if (empty($topDeals)): ?>
                <p class="empty-state">No deals data available</p>
            <?php else: ?>
                <div class="performer-list">
                    <?php foreach (array_slice($topDeals, 0, 5) as $deal): ?>
                    <div class="performer-item">
                        <div class="performer-name"><?php echo htmlspecialchars($deal['title']); ?></div>
                        <div class="performer-value">
                            <?php 
                            if ($defaultCurrency) {
                                echo $currenciesModule->formatAmount($deal['value'], $defaultCurrency['code']);
                            } else {
                                echo '$' . number_format($deal['value'], 2);
                            }
                            ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="performer-section">
            <h3>Top Lead Sources</h3>
            <?php if (empty($topSources)): ?>
                <p class="empty-state">No lead source data available</p>
            <?php else: ?>
                <div class="performer-list">
                    <?php foreach (array_slice($topSources, 0, 5) as $source): ?>
                    <div class="performer-item">
                        <div class="performer-name"><?php echo htmlspecialchars(ucfirst($source['lead_source'])); ?></div>
                        <div class="performer-value">
                            <?php echo number_format($source['conversion_rate'], 1); ?>% conversion
                        </div>
                        <div class="performer-subtext"><?php echo $source['contact_count']; ?> contacts</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    </details>

    <!-- Trend Analysis Charts -->
    <details class="analytics-section analytics-details" id="analytics-section-trends" data-analytics-section-key="trends" data-analytics-collapsible>
    <summary>
        <span><i class="fas fa-chart-area"></i> Trend Analysis</span>
        <small>4 charts</small>
    </summary>
    
    <div class="charts-grid">
        <div class="chart-container">
            <div class="chart-header">
                <h3 class="chart-title">Contacts Created (Last 30 Days)</h3>
                <p class="chart-subtitle">Daily contact creation trend</p>
            </div>
            <canvas id="contactsChart"></canvas>
        </div>
        
        <div class="chart-container">
            <div class="chart-header">
                <h3 class="chart-title">Email Performance</h3>
                <p class="chart-subtitle">Sent vs opened vs clicked</p>
            </div>
            <canvas id="emailChart"></canvas>
        </div>
        
        <div class="chart-container">
            <div class="chart-header">
                <h3 class="chart-title">Stage Distribution</h3>
                <p class="chart-subtitle">Contacts by stage</p>
            </div>
            <canvas id="stageChart"></canvas>
        </div>
        
        <div class="chart-container">
            <div class="chart-header">
                <h3 class="chart-title">Revenue by Lead Source</h3>
                <p class="chart-subtitle">Revenue contribution by source</p>
            </div>
            <?php if (empty($revenueAnalytics['revenue_by_source'])): ?>
                <div class="empty-state">No won revenue by lead source yet.</div>
            <?php else: ?>
                <canvas id="revenueSourceChart"></canvas>
            <?php endif; ?>
        </div>
    </div>
    </details>

    <!-- Opportunities -->
    <?php if (!empty($opportunities)): ?>
    <details class="analytics-section analytics-details" id="analytics-section-opportunities" data-analytics-section-key="opportunities" data-analytics-collapsible>
    <summary>
        <span><i class="fas fa-bullseye"></i> Opportunities</span>
        <small><?php echo count($opportunities); ?> actions</small>
    </summary>
    
    <div class="opportunities-grid">
        <?php foreach ($opportunities as $opp): ?>
        <div class="opportunity-card priority-<?php echo $opp['priority']; ?>">
            <div class="opportunity-icon">
                <i class="fas fa-<?php echo $opp['type'] === 'revenue' ? 'dollar-sign' : ($opp['type'] === 'acquisition' ? 'users' : 'arrow-up'); ?>"></i>
            </div>
            <div class="opportunity-content">
                <h3><?php echo htmlspecialchars($opp['title']); ?></h3>
                <p><?php echo htmlspecialchars($opp['description']); ?></p>
                <div class="opportunity-action">
                    <strong>Action:</strong> <?php echo htmlspecialchars($opp['action']); ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    </details>
    <?php else: ?>
    <details class="analytics-section analytics-details" id="analytics-section-opportunities" data-analytics-section-key="opportunities" data-analytics-collapsible>
    <summary>
        <span><i class="fas fa-bullseye"></i> Opportunities</span>
        <small>No actions yet</small>
    </summary>
    <p class="empty-state">No opportunity recommendations available for this period.</p>
    </details>
    <?php endif; ?>
</div>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_ANALYTICS, 'How to use Analytics', $analyticsGuideVideoUrl); ?>

<script>
(function () {
function resizeChartsIn(container) {
    if (typeof window.Chart === 'undefined' || !container) {
        return;
    }
    container.querySelectorAll('canvas').forEach(function (canvas) {
        var chart = window.Chart.getChart ? window.Chart.getChart(canvas) : null;
        if (chart && typeof chart.resize === 'function') {
            chart.resize();
        }
    });
}

function analyticsEscape(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function iconClass(icon) {
    var raw = String(icon || 'fa-circle').trim();
    return raw.indexOf('fa-brands') === 0 || raw.indexOf('fas ') === 0 ? raw : 'fas ' + raw;
}

function setAsyncStatus(section, text, state) {
    var status = section.querySelector('[data-analytics-async-status]');
    if (!status) {
        return;
    }
    status.textContent = text;
    status.dataset.state = state || 'idle';
}

function renderAsyncMetrics(section, metrics) {
    var target = section.querySelector('[data-analytics-metrics]');
    if (!target) {
        return;
    }
    if (!Array.isArray(metrics) || metrics.length === 0) {
        target.innerHTML = '';
        return;
    }

    target.innerHTML = metrics.map(function (metric) {
        var tone = analyticsEscape(metric.tone || 'neutral');
        return '<article class="analytics-operating-card analytics-tone-' + tone + '">' +
            '<div class="analytics-operating-icon"><i class="' + analyticsEscape(iconClass(metric.icon)) + '"></i></div>' +
            '<div class="analytics-operating-copy">' +
                '<span>' + analyticsEscape(metric.label) + '</span>' +
                '<strong>' + analyticsEscape(metric.value) + '</strong>' +
                '<small>' + analyticsEscape(metric.detail || '') + '</small>' +
            '</div>' +
        '</article>';
    }).join('');
}

function renderAsyncInsights(section, insights) {
    var target = section.querySelector('[data-analytics-insights]');
    if (!target) {
        return;
    }
    if (!Array.isArray(insights) || insights.length === 0) {
        target.innerHTML = '';
        return;
    }

    target.innerHTML = insights.map(function (insight) {
        return '<div class="analytics-async-insight analytics-tone-' + analyticsEscape(insight.tone || 'neutral') + '">' +
            analyticsEscape(insight.message || '') +
        '</div>';
    }).join('');
}

function renderAsyncCharts(section, charts) {
    var target = section.querySelector('[data-analytics-charts]');
    if (!target) {
        return;
    }
    if (!Array.isArray(charts) || charts.length === 0) {
        target.innerHTML = '';
        return;
    }

    target.innerHTML = charts.map(function (chart) {
        var labels = Array.isArray(chart.labels) ? chart.labels : [];
        var values = Array.isArray(chart.values) ? chart.values.map(function (value) { return Number(value) || 0; }) : [];
        var max = values.reduce(function (carry, value) { return Math.max(carry, value); }, 0) || 1;
        var rows = labels.map(function (label, index) {
            var value = values[index] || 0;
            var width = Math.max(3, Math.round((value / max) * 100));
            return '<div class="analytics-async-bar-row">' +
                '<span>' + analyticsEscape(label) + '</span>' +
                '<div class="analytics-async-bar-track"><i style="width:' + width + '%"></i></div>' +
                '<strong>' + analyticsEscape(value.toLocaleString()) + '</strong>' +
            '</div>';
        }).join('');

        return '<div class="analytics-async-chart">' +
            '<h3>' + analyticsEscape(chart.title || 'Trend') + '</h3>' +
            '<div class="analytics-async-bars">' + rows + '</div>' +
        '</div>';
    }).join('');
}

function loadAnalyticsSection(section) {
    if (!section || section.dataset.analyticsLoaded === 'true' || section.dataset.analyticsLoading === 'true') {
        return;
    }

    var endpoint = section.getAttribute('data-analytics-endpoint');
    if (!endpoint) {
        return;
    }

    section.dataset.analyticsLoading = 'true';
    setAsyncStatus(section, 'Loading', 'loading');

    fetch(endpoint, { headers: { 'Accept': 'application/json' } })
        .then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (payload) {
                return { status: response.status, ok: response.ok, payload: payload };
            });
        })
        .then(function (result) {
            var payload = result.payload || {};
            if (result.status === 403) {
                setAsyncStatus(section, 'Not permitted', 'forbidden');
                renderAsyncMetrics(section, []);
                renderAsyncInsights(section, [{ tone: 'neutral', message: 'You do not have permission to view this analytics section.' }]);
                renderAsyncCharts(section, []);
                return;
            }

            if (!result.ok || payload.success === false) {
                setAsyncStatus(section, 'Unavailable', 'error');
                renderAsyncMetrics(section, []);
                renderAsyncInsights(section, [{ tone: 'warning', message: payload.error || 'This analytics section is temporarily unavailable.' }]);
                renderAsyncCharts(section, []);
                return;
            }

            renderAsyncMetrics(section, payload.metrics || []);
            renderAsyncInsights(section, payload.insights || []);
            renderAsyncCharts(section, payload.charts || []);
            setAsyncStatus(section, 'Updated', 'ready');
            section.dataset.analyticsLoaded = 'true';
            window.setTimeout(function () { resizeChartsIn(section); }, 80);
        })
        .catch(function () {
            setAsyncStatus(section, 'Unavailable', 'error');
            renderAsyncMetrics(section, []);
            renderAsyncInsights(section, [{ tone: 'warning', message: 'This analytics section could not be loaded right now.' }]);
            renderAsyncCharts(section, []);
        })
        .finally(function () {
            section.dataset.analyticsLoading = 'false';
        });
}

var analyticsOverviewSections = ['operating', 'summary', 'channels', 'ai', 'revenue', 'kpis'];
var analyticsControls = Array.prototype.slice.call(document.querySelectorAll('[data-analytics-section-control]'));
var analyticsSections = Array.prototype.slice.call(document.querySelectorAll('[data-analytics-section-key]'));
var analyticsValidSections = analyticsControls.reduce(function (sections, control) {
    sections[control.getAttribute('data-analytics-section-control')] = true;
    return sections;
}, {});

function isAnalyticsSectionVisible(section) {
    return section && !section.classList.contains('analytics-section-hidden');
}

function resolveAnalyticsSectionKey(sectionKey) {
    return analyticsValidSections[sectionKey] ? sectionKey : 'overview';
}

function updateAnalyticsSectionUrl(sectionKey, replace) {
    if (!window.history || !window.history.pushState) {
        return;
    }

    var url = new URL(window.location.href);
    url.searchParams.set('section', sectionKey);
    var method = replace ? 'replaceState' : 'pushState';
    window.history[method]({ analyticsSection: sectionKey }, '', url.toString());
}

function activateAnalyticsSection(sectionKey, options) {
    options = options || {};
    sectionKey = resolveAnalyticsSectionKey(sectionKey || 'overview');
    var scrollAnchor = options.preserveScroll ? document.querySelector('.analytics-section-nav') : null;
    var scrollAnchorTop = scrollAnchor ? scrollAnchor.getBoundingClientRect().top : null;

    analyticsControls.forEach(function (control) {
        var isActive = control.getAttribute('data-analytics-section-control') === sectionKey;
        control.classList.toggle('is-active', isActive);
        control.setAttribute('aria-current', isActive ? 'true' : 'false');
    });

    analyticsSections.forEach(function (section) {
        var key = section.getAttribute('data-analytics-section-key');
        var shouldShow = sectionKey === 'overview'
            ? analyticsOverviewSections.indexOf(key) !== -1
            : key === sectionKey;
        section.classList.toggle('analytics-section-hidden', !shouldShow);

        if (shouldShow) {
            if (section.tagName.toLowerCase() === 'details') {
                section.open = true;
            }
            loadAnalyticsSection(section);
            window.setTimeout(function () { resizeChartsIn(section); }, 80);
        }
    });

    if (scrollAnchor && scrollAnchorTop !== null) {
        window.scrollBy(0, scrollAnchor.getBoundingClientRect().top - scrollAnchorTop);
    }

    if (options.updateUrl) {
        updateAnalyticsSectionUrl(sectionKey, Boolean(options.replaceUrl));
    }
}

analyticsControls.forEach(function (link) {
    link.addEventListener('click', function (event) {
        event.preventDefault();
        activateAnalyticsSection(link.getAttribute('data-analytics-section-control'), { updateUrl: true, preserveScroll: true });
    });
});

window.addEventListener('popstate', function () {
    var sectionKey = new URL(window.location.href).searchParams.get('section') || 'overview';
    activateAnalyticsSection(sectionKey, { updateUrl: false });
});

document.querySelectorAll('[data-analytics-collapsible]').forEach(function (section) {
    section.addEventListener('toggle', function () {
        if (section.open) {
            loadAnalyticsSection(section);
            window.setTimeout(function () { resizeChartsIn(section); }, 80);
        }
    });
});

document.querySelectorAll('[data-analytics-async-section]').forEach(function (section) {
    if (isAnalyticsSectionVisible(section) && (section.tagName.toLowerCase() !== 'details' || section.open)) {
        loadAnalyticsSection(section);
    }
});

activateAnalyticsSection(new URL(window.location.href).searchParams.get('section') || 'overview', { updateUrl: false });

function initAnalyticsCharts() {
if (typeof window.Chart === 'undefined') {
    return;
}

// Enhanced Chart.js Configuration
Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";
Chart.defaults.font.size = 11;
Chart.defaults.color = '#64748b';
Chart.defaults.elements.point.radius = 4;
Chart.defaults.elements.point.hoverRadius = 6;
Chart.defaults.elements.point.borderWidth = 2;
Chart.defaults.elements.line.borderWidth = 2;
Chart.defaults.elements.bar.borderRadius = 8;

const chartColors = {
    primary: '#2563eb',
    secondary: '#64748b',
    success: '#16a34a',
    warning: '#d97706',
    danger: '#dc2626',
    info: '#0f766e',
    slate: '#475569'
};

// Revenue Trend Chart
<?php
$revenueLabels = [];
$revenueData = [];
$revenueTrendMap = [];
foreach ($revenueAnalytics['revenue_trend'] as $trend) {
    $revenueTrendMap[(string) ($trend['month'] ?? '')] = (float) ($trend['revenue'] ?? 0);
}
$revenueStartMonth = new DateTimeImmutable(date('Y-m-01', strtotime($endDate . ' -11 months')));
$revenueEndMonth = (new DateTimeImmutable(date('Y-m-01', strtotime($endDate))))->modify('+1 month');
$revenuePeriod = new DatePeriod($revenueStartMonth, new DateInterval('P1M'), $revenueEndMonth);
foreach ($revenuePeriod as $month) {
    $key = $month->format('Y-m');
    $revenueLabels[] = $month->format('M Y');
    $revenueData[] = (float) ($revenueTrendMap[$key] ?? 0);
}
?>
const revenueCtx = document.getElementById('revenueChart');
if (revenueCtx) {
    new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($revenueLabels); ?>,
            datasets: [{
                label: 'Revenue',
                data: <?php echo json_encode($revenueData); ?>,
                borderColor: chartColors.success,
                backgroundColor: function(context) {
                    const chart = context.chart;
                    const {ctx, chartArea} = chart;
                    if (!chartArea) return null;
                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                    gradient.addColorStop(0, 'rgba(22, 163, 74, 0.14)');
                    gradient.addColorStop(0.5, 'rgba(22, 163, 74, 0.08)');
                    gradient.addColorStop(1, 'rgba(22, 163, 74, 0.04)');
                    return gradient;
                },
                tension: 0.5,
                fill: true,
                pointRadius: 5,
                pointHoverRadius: 8,
                pointBackgroundColor: '#ffffff',
                pointBorderColor: chartColors.success,
                pointBorderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    padding: 14,
                    titleFont: { size: 14, weight: '600', family: 'system-ui' },
                    bodyFont: { size: 13, weight: '500', family: 'system-ui' },
                    cornerRadius: 10,
                    callbacks: {
                        label: function(context) {
                            let value = context.parsed.y;
                            <?php if ($defaultCurrency): ?>
                                return 'Revenue: <?php echo $defaultCurrency['symbol']; ?>' + value.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            <?php else: ?>
                                return 'Revenue: $' + value.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            <?php endif; ?>
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(148, 163, 184, 0.18)', drawBorder: false },
                    ticks: {
                        color: '#64748b',
                        font: { size: 11, weight: '500' },
                        padding: 10,
                        callback: function(value) {
                            <?php if ($defaultCurrency): ?>
                                return '<?php echo $defaultCurrency['symbol']; ?>' + value.toLocaleString();
                            <?php else: ?>
                                return '$' + value.toLocaleString();
                            <?php endif; ?>
                        }
                    }
                },
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: { color: '#64748b', font: { size: 11, weight: '600' }, padding: 12 }
                }
            }
        }
    });
}

// Activity by Day Chart
<?php
$dayLabels = [];
$dayData = [];
$dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
foreach ($dayOrder as $day) {
    $dayLabels[] = $day;
    $found = false;
    foreach ($activityPatterns['by_day_of_week'] as $dayDataItem) {
        if ($dayDataItem['day_name'] === $day) {
            $dayData[] = (int)$dayDataItem['activity_count'];
            $found = true;
            break;
        }
    }
    if (!$found) {
        $dayData[] = 0;
    }
}
?>
const activityDayCtx = document.getElementById('activityDayChart');
if (activityDayCtx) {
    new Chart(activityDayCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($dayLabels); ?>,
            datasets: [{
                label: 'Activities',
                data: <?php echo json_encode($dayData); ?>,
                backgroundColor: function(context) {
                    const gradient = context.chart.ctx.createLinearGradient(0, 0, 0, 400);
                    gradient.addColorStop(0, 'rgba(37, 99, 235, 0.82)');
                    gradient.addColorStop(1, 'rgba(37, 99, 235, 0.5)');
                    return gradient;
                },
                borderRadius: { topLeft: 8, topRight: 8 },
                borderSkipped: false,
                maxBarThickness: 50
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    padding: 14,
                    titleFont: { size: 14, weight: '600', family: 'system-ui' },
                    bodyFont: { size: 13, weight: '500', family: 'system-ui' },
                    cornerRadius: 10
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(148, 163, 184, 0.18)', drawBorder: false },
                    ticks: { color: '#64748b', font: { size: 11, weight: '500' }, padding: 10, precision: 0 }
                },
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: { color: '#64748b', font: { size: 11, weight: '600' }, padding: 12 }
                }
            }
        }
    });
}

// Activity by Hour Chart
<?php
$hourLabels = [];
$hourData = [];
for ($i = 0; $i < 24; $i++) {
    $hourLabels[] = $i . ':00';
    $found = false;
    foreach ($activityPatterns['by_hour'] as $hourItem) {
        if ((int)$hourItem['hour'] === $i) {
            $hourData[] = (int)$hourItem['activity_count'];
            $found = true;
            break;
        }
    }
    if (!$found) {
        $hourData[] = 0;
    }
}
?>
const activityHourCtx = document.getElementById('activityHourChart');
if (activityHourCtx) {
    new Chart(activityHourCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($hourLabels); ?>,
            datasets: [{
                label: 'Activities',
                data: <?php echo json_encode($hourData); ?>,
                borderColor: chartColors.primary,
                backgroundColor: 'rgba(37, 99, 235, 0.08)',
                tension: 0.4,
                fill: true,
                pointRadius: 3,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    padding: 14,
                    titleFont: { size: 14, weight: '600', family: 'system-ui' },
                    bodyFont: { size: 13, weight: '500', family: 'system-ui' },
                    cornerRadius: 10
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(148, 163, 184, 0.18)', drawBorder: false },
                    ticks: { color: '#64748b', font: { size: 11, weight: '500' }, padding: 10, precision: 0 }
                },
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: { color: '#64748b', font: { size: 11, weight: '600' }, padding: 12 }
                }
            }
        }
    });
}

// Contacts Chart
<?php
$contactsLabels = [];
$contactsData = [];
$contactsTrendMap = [];
foreach ($dailyTrends as $trend) {
    $contactsTrendMap[(string) ($trend['date'] ?? '')] = (int) ($trend['contacts'] ?? 0);
}
$contactsPeriod = new DatePeriod(
    new DateTimeImmutable('-29 days'),
    new DateInterval('P1D'),
    new DateTimeImmutable('tomorrow')
);
foreach ($contactsPeriod as $day) {
    $key = $day->format('Y-m-d');
    $contactsLabels[] = $day->format('M j');
    $contactsData[] = (int) ($contactsTrendMap[$key] ?? 0);
}
?>
const contactsCtx = document.getElementById('contactsChart');
if (contactsCtx) {
    new Chart(contactsCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($contactsLabels); ?>,
            datasets: [{
                label: 'Contacts Created',
                data: <?php echo json_encode($contactsData); ?>,
                borderColor: chartColors.primary,
                backgroundColor: function(context) {
                    const chart = context.chart;
                    const {ctx, chartArea} = chart;
                    if (!chartArea) return null;
                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                    gradient.addColorStop(0, 'rgba(37, 99, 235, 0.14)');
                    gradient.addColorStop(0.5, 'rgba(37, 99, 235, 0.08)');
                    gradient.addColorStop(1, 'rgba(37, 99, 235, 0.04)');
                    return gradient;
                },
                tension: 0.5,
                fill: true,
                pointRadius: 5,
                pointHoverRadius: 8,
                pointBackgroundColor: '#ffffff',
                pointBorderColor: chartColors.primary,
                pointBorderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    padding: 14,
                    titleFont: { size: 14, weight: '600', family: 'system-ui' },
                    bodyFont: { size: 13, weight: '500', family: 'system-ui' },
                    cornerRadius: 10
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(148, 163, 184, 0.18)', drawBorder: false },
                    ticks: { color: '#64748b', font: { size: 11, weight: '500' }, padding: 10, precision: 0 }
                },
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: { color: '#64748b', font: { size: 11, weight: '600' }, padding: 12 }
                }
            }
        }
    });
}

// Email Performance Chart
<?php
$emailLabels = [];
$emailSentData = [];
$emailOpenedData = [];
$emailClickedData = [];
$emailPerformanceMap = [];
foreach ($emailPerformance as $trend) {
    $emailPerformanceMap[(string) ($trend['date'] ?? '')] = [
        'sent' => (int) ($trend['sent'] ?? 0),
        'opened' => (int) ($trend['opened'] ?? 0),
        'clicked' => (int) ($trend['clicked'] ?? 0),
    ];
}
$emailPeriod = new DatePeriod(
    new DateTimeImmutable('-29 days'),
    new DateInterval('P1D'),
    new DateTimeImmutable('tomorrow')
);
foreach ($emailPeriod as $day) {
    $key = $day->format('Y-m-d');
    $emailLabels[] = $day->format('M j');
    $emailSentData[] = (int) ($emailPerformanceMap[$key]['sent'] ?? 0);
    $emailOpenedData[] = (int) ($emailPerformanceMap[$key]['opened'] ?? 0);
    $emailClickedData[] = (int) ($emailPerformanceMap[$key]['clicked'] ?? 0);
}
?>
const emailCtx = document.getElementById('emailChart');
if (emailCtx) {
    new Chart(emailCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($emailLabels); ?>,
            datasets: [{
                label: 'Sent',
                data: <?php echo json_encode($emailSentData); ?>,
                backgroundColor: function(context) {
                    const gradient = context.chart.ctx.createLinearGradient(0, 0, 0, 400);
                    gradient.addColorStop(0, 'rgba(37, 99, 235, 0.82)');
                    gradient.addColorStop(1, 'rgba(37, 99, 235, 0.5)');
                    return gradient;
                },
                borderRadius: { topLeft: 8, topRight: 8 },
                borderSkipped: false,
                maxBarThickness: 50
            }, {
                label: 'Opened',
                data: <?php echo json_encode($emailOpenedData); ?>,
                backgroundColor: function(context) {
                    const gradient = context.chart.ctx.createLinearGradient(0, 0, 0, 400);
                    gradient.addColorStop(0, 'rgba(22, 163, 74, 0.82)');
                    gradient.addColorStop(1, 'rgba(22, 163, 74, 0.5)');
                    return gradient;
                },
                borderRadius: { topLeft: 8, topRight: 8 },
                borderSkipped: false,
                maxBarThickness: 50
            }, {
                label: 'Clicked',
                data: <?php echo json_encode($emailClickedData); ?>,
                backgroundColor: function(context) {
                    const gradient = context.chart.ctx.createLinearGradient(0, 0, 0, 400);
                    gradient.addColorStop(0, 'rgba(217, 119, 6, 0.78)');
                    gradient.addColorStop(1, 'rgba(217, 119, 6, 0.48)');
                    return gradient;
                },
                borderRadius: { topLeft: 8, topRight: 8 },
                borderSkipped: false,
                maxBarThickness: 50
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    align: 'end',
                    labels: {
                        usePointStyle: true,
                        padding: 18,
                        font: { size: 12, weight: '600', family: 'system-ui' },
                        color: '#64748b',
                        boxWidth: 12,
                        boxHeight: 12
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    padding: 14,
                    titleFont: { size: 14, weight: '600', family: 'system-ui' },
                    bodyFont: { size: 13, weight: '500', family: 'system-ui' },
                    cornerRadius: 10
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(148, 163, 184, 0.18)', drawBorder: false },
                    ticks: { color: '#64748b', font: { size: 11, weight: '500' }, padding: 10, precision: 0 }
                },
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: { color: '#64748b', font: { size: 11, weight: '600' }, padding: 12 }
                }
            }
        }
    });
}

// Stage Distribution Chart
<?php
$stageLabels = [];
$stageData = [];
$stageColors = ['#2563eb', '#16a34a', '#d97706', '#64748b', '#0f766e', '#94a3b8', '#dc2626'];
foreach ($stageDistribution as $index => $stage) {
    $stageLabels[] = ucfirst($stage['stage']);
    $stageData[] = (int)$stage['count'];
}
?>
const stageCtx = document.getElementById('stageChart');
if (stageCtx) {
    new Chart(stageCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($stageLabels); ?>,
            datasets: [{
                data: <?php echo json_encode($stageData); ?>,
                backgroundColor: <?php echo json_encode(array_slice($stageColors, 0, count($stageData))); ?>,
                borderWidth: 3,
                borderColor: '#ffffff',
                hoverBorderWidth: 4,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        usePointStyle: true,
                        padding: 15,
                        font: { size: 12, weight: '600', family: 'system-ui' },
                        color: '#64748b'
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    padding: 14,
                    titleFont: { size: 14, weight: '600', family: 'system-ui' },
                    bodyFont: { size: 13, weight: '500', family: 'system-ui' },
                    cornerRadius: 10,
                    displayColors: true
                }
            }
        }
    });
}

// Revenue by Source Chart
<?php
$sourceLabels = [];
$sourceData = [];
foreach ($revenueAnalytics['revenue_by_source'] as $source) {
    $sourceLabels[] = ucfirst($source['lead_source']);
    $sourceData[] = (float)$source['total_revenue'];
}
?>
const revenueSourceCtx = document.getElementById('revenueSourceChart');
if (revenueSourceCtx) {
    new Chart(revenueSourceCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($sourceLabels); ?>,
            datasets: [{
                label: 'Revenue',
                data: <?php echo json_encode($sourceData); ?>,
                backgroundColor: function(context) {
                    const gradient = context.chart.ctx.createLinearGradient(0, 0, 0, 400);
                    gradient.addColorStop(0, 'rgba(22, 163, 74, 0.82)');
                    gradient.addColorStop(1, 'rgba(22, 163, 74, 0.5)');
                    return gradient;
                },
                borderRadius: { topLeft: 8, topRight: 8 },
                borderSkipped: false,
                maxBarThickness: 60
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    padding: 14,
                    titleFont: { size: 14, weight: '600', family: 'system-ui' },
                    bodyFont: { size: 13, weight: '500', family: 'system-ui' },
                    cornerRadius: 10,
                    callbacks: {
                        label: function(context) {
                            let value = context.parsed.y;
                            <?php if ($defaultCurrency): ?>
                                return 'Revenue: <?php echo $defaultCurrency['symbol']; ?>' + value.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            <?php else: ?>
                                return 'Revenue: $' + value.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            <?php endif; ?>
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(148, 163, 184, 0.18)', drawBorder: false },
                    ticks: {
                        color: '#64748b',
                        font: { size: 11, weight: '500' },
                        padding: 10,
                        callback: function(value) {
                            <?php if ($defaultCurrency): ?>
                                return '<?php echo $defaultCurrency['symbol']; ?>' + value.toLocaleString();
                            <?php else: ?>
                                return '$' + value.toLocaleString();
                            <?php endif; ?>
                        }
                    }
                },
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: { color: '#64748b', font: { size: 11, weight: '600' }, padding: 12 }
                }
            }
        }
    });
}
}

function loadAnalyticsScriptOnce(src, onLoad) {
    if (typeof window.Chart !== 'undefined') {
        onLoad();
        return;
    }

    var existing = document.querySelector('script[data-analytics-key="chartjs"]');
    if (existing) {
        if (existing.dataset.loaded === 'true') {
            onLoad();
            return;
        }
        existing.addEventListener('load', onLoad, { once: true });
        return;
    }

    var script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.dataset.analyticsKey = 'chartjs';
    script.addEventListener('load', function () {
        script.dataset.loaded = 'true';
        onLoad();
    }, { once: true });
    document.body.appendChild(script);
}

function scheduleAnalyticsCharts() {
    loadAnalyticsScriptOnce('https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', initAnalyticsCharts);
}

window.addEventListener('load', function () {
    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(scheduleAnalyticsCharts, { timeout: 2500 });
        return;
    }
    window.setTimeout(scheduleAnalyticsCharts, 500);
}, { once: true });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>

