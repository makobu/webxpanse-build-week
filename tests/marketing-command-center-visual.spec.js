const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE_URL = process.env.CRM_BASE_URL || 'http://localhost/crm/public';
const TEST_EMAIL = 'test@crm.local';
const TEST_PASSWORD = 'test123456';
let operatorExportPackId = null;
let contentViewItemId = null;
let landingPageViewId = null;
let landingPagePreviewToken = null;
let landingPagePublicToken = null;

test.beforeAll(() => {
  execFileSync('php', ['scripts/setup_test_environment.php'], {
    cwd: path.resolve(__dirname, '..'),
    stdio: 'ignore',
  });
  operatorExportPackId = ensureOperatorExportPackFixture();
  contentViewItemId = ensureContentViewFixture();
  landingPageViewId = ensureLandingPageViewFixture();
  landingPagePreviewToken = landingPageViewId ? getLandingPagePreviewToken(landingPageViewId) : null;
  landingPagePublicToken = landingPageViewId ? ensureLandingPagePublicToken(landingPageViewId) : null;
});

function ensureOperatorExportPackFixture() {
  const code = `
    require_once getcwd() . '/vendor/autoload.php';
    require_once getcwd() . '/config/constants.php';
    CRM\\Database::init(require getcwd() . '/config/database.php');
    $user = CRM\\Database::queryOne('SELECT id FROM users WHERE email = ?', ['${TEST_EMAIL}']);
    if (!$user) { throw new RuntimeException('Test user missing.'); }
    $workspace = CRM\\Database::queryOne('SELECT id FROM workspaces ORDER BY id ASC LIMIT 1');
    if (!$workspace) { throw new RuntimeException('Workspace missing.'); }
    $userId = (int) $user['id'];
    $workspaceId = (int) $workspace['id'];
    $campaign = CRM\\Database::queryOne('SELECT id FROM campaigns WHERE workspace_id = ? AND name = ? ORDER BY id DESC LIMIT 1', [$workspaceId, 'Visual Operator Export Campaign']);
    if (!$campaign) {
      CRM\\Database::execute(
        "INSERT INTO campaigns (workspace_id, uuid, name, description, objective, channel_mix, status, created_by) VALUES (?, UUID(), ?, '', 'outbound', '[\\"email\\"]', 'active', ?)",
        [$workspaceId, 'Visual Operator Export Campaign', $userId]
      );
      $campaignId = (int) CRM\\Database::lastInsertId();
    } else {
      $campaignId = (int) $campaign['id'];
    }
    $pack = CRM\\Database::queryOne('SELECT id FROM marketing_operator_export_packs WHERE workspace_id = ? AND campaign_id = ? AND title = ? AND status <> ? ORDER BY id DESC LIMIT 1', [$workspaceId, $campaignId, 'Visual Operator Export Pack', 'archived']);
    if (!$pack) {
      $json = static fn(array $value): string => json_encode($value, JSON_UNESCAPED_SLASHES);
      CRM\\Database::execute(
        "INSERT INTO marketing_operator_export_packs (workspace_id, campaign_id, uuid, title, status, readiness_score, risk_snapshot_json, export_bundle_json, next_steps_json, integration_links_json, guardrails_json, metadata_json, owner_user_id, prepared_by, prepared_at, created_by) VALUES (?, ?, UUID(), ?, 'blocked', 68, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)",
        [
          $workspaceId,
          $campaignId,
          'Visual Operator Export Pack',
          $json([
            ['severity' => 'high', 'label' => 'Landing page approval', 'source' => 'Launch workspace', 'message' => 'Confirm destination before manual publishing.', 'href' => 'marketing_campaign_workspace.php?campaign_id=' . $campaignId],
            ['severity' => 'medium', 'label' => 'UTM review', 'source' => 'Tracking', 'message' => 'Check links before publishing.', 'href' => 'marketing_utm_links.php?campaign_id=' . $campaignId],
          ]),
          $json([
            ['key' => 'content_pack', 'label' => 'Content Pack', 'status' => 'ready', 'detail' => 'Copy is ready for manual use.', 'href' => 'marketing_campaign_workspace.php?campaign_id=' . $campaignId, 'operator_instruction' => 'Copy the saved campaign message into the channel tool.'],
            ['key' => 'landing_page', 'label' => 'Landing Page', 'status' => 'blocked', 'detail' => 'Destination needs approval.', 'href' => 'marketing_landing_pages.php', 'operator_instruction' => 'Approve the landing page before posting.'],
            ['key' => 'tracking', 'label' => 'Tracking Links', 'status' => 'warning', 'detail' => 'UTMs need a quick check.', 'href' => 'marketing_utm_links.php?campaign_id=' . $campaignId, 'operator_instruction' => 'Review tracking before copying links.'],
          ]),
          $json([
            ['label' => 'Approve destination', 'reason' => 'Landing page must be checked before launch.', 'href' => 'marketing_campaign_workspace.php?campaign_id=' . $campaignId, 'priority' => 'high'],
            ['label' => 'Check tracking links', 'reason' => 'UTMs should match the campaign channel.', 'href' => 'marketing_utm_links.php?campaign_id=' . $campaignId, 'priority' => 'medium'],
          ]),
          $json(['campaign_workspace' => 'marketing_campaign_workspace.php?campaign_id=' . $campaignId, 'utm_links' => 'marketing_utm_links.php?campaign_id=' . $campaignId]),
          $json(['manual_first' => true, 'external_publish' => false, 'external_send' => false, 'message' => 'Manual-first export pack only.']),
          $json(['source' => 'visual_test_fixture', 'manual_first' => true]),
          $userId,
          $userId,
          $userId,
        ]
      );
      $packId = (int) CRM\\Database::lastInsertId();
    } else {
      $packId = (int) $pack['id'];
    }
    echo (string) $packId;
  `;
  return execFileSync('php', ['-r', code], {
    cwd: path.resolve(__dirname, '..'),
    encoding: 'utf8',
  }).trim();
}

function ensureContentViewFixture() {
  const code = `
    require_once getcwd() . '/vendor/autoload.php';
    require_once getcwd() . '/config/constants.php';
    CRM\\Database::init(require getcwd() . '/config/database.php');
    $user = CRM\\Database::queryOne('SELECT id FROM users WHERE email = ?', ['${TEST_EMAIL}']);
    if (!$user) { throw new RuntimeException('Test user missing.'); }
    $workspace = CRM\\Database::queryOne('SELECT id FROM workspaces ORDER BY id ASC LIMIT 1');
    if (!$workspace) { throw new RuntimeException('Workspace missing.'); }
    $userId = (int) $user['id'];
    $workspaceId = (int) $workspace['id'];
    CRM\\Authorization::assignUserRoleBySlug($userId, 'superadmin', $userId);
    CRM\\Database::execute(
      "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by) VALUES (?, ?, 'owner', 'active', 1, NOW(), ?) ON DUPLICATE KEY UPDATE role_slug = 'owner', membership_status = 'active', is_owner = 1",
      [$workspaceId, $userId, $userId]
    );
    CRM\\Authorization::assignWorkspaceUserRoleBySlug($workspaceId, $userId, 'owner', $userId);
    CRM\\Services\\WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');
    $_SESSION['user_id'] = $userId;
    $marketing = new CRM\\Modules\\Marketing();
    $existing = CRM\\Database::queryOne('SELECT id FROM marketing_content_items WHERE workspace_id = ? AND title = ? ORDER BY id DESC LIMIT 1', [$workspaceId, 'Visual Content Review Fixture']);
    if ($existing) {
      $contentId = (int) $existing['id'];
    } else {
      $contentId = $marketing->createContentItem([
        'title' => 'Visual Content Review Fixture',
        'content_type' => 'email',
        'channel' => 'email',
        'status' => 'draft',
        'funnel_stage' => 'consideration',
        'objective' => 'Help a founder understand the next useful action.',
        'target_audience' => 'Founder operators',
        'draft_body' => 'A focused message that explains the offer, the proof, and one clear next step.',
        'readiness_score' => 78,
        'production_stage' => 'drafting',
        'production_score' => 64,
        'dependency_status' => 'waiting',
        'dependency_notes' => 'Need final proof point before launch.',
        'next_action' => 'Confirm the proof point and request review.',
        'created_by' => $userId,
        'owner_user_id' => $userId,
      ]);
      $marketing->addContentComment($contentId, 'Visual QA note: confirm the proof point before approval.', $userId);
      $marketing->requestContentReview($contentId, $userId, null, date('Y-m-d H:i:s', strtotime('+2 days')));
    }
    echo (string) $contentId;
  `;
  return execFileSync('php', ['-r', code], {
    cwd: path.resolve(__dirname, '..'),
    encoding: 'utf8',
  }).trim();
}

function ensureLandingPageViewFixture() {
  const code = `
    require_once getcwd() . '/vendor/autoload.php';
    require_once getcwd() . '/config/constants.php';
    CRM\\Database::init(require getcwd() . '/config/database.php');
    $user = CRM\\Database::queryOne('SELECT id FROM users WHERE email = ?', ['${TEST_EMAIL}']);
    if (!$user) { throw new RuntimeException('Test user missing.'); }
    $workspace = CRM\\Database::queryOne('SELECT id FROM workspaces ORDER BY id ASC LIMIT 1');
    if (!$workspace) { throw new RuntimeException('Workspace missing.'); }
    $userId = (int) $user['id'];
    $workspaceId = (int) $workspace['id'];
    CRM\\Database::execute(
      "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by) VALUES (?, ?, 'owner', 'active', 1, NOW(), ?) ON DUPLICATE KEY UPDATE role_slug = 'owner', membership_status = 'active', is_owner = 1",
      [$workspaceId, $userId, $userId]
    );
    CRM\\Authorization::assignWorkspaceUserRoleBySlug($workspaceId, $userId, 'owner', $userId);
    CRM\\Services\\WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');
    CRM\\Session::set('user_id', $userId);
    (new CRM\\Services\\WorkspaceSkillCatalogService())->syncDefinitions();
    CRM\\Database::execute(
      "INSERT INTO workspace_skill_installs (workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at) VALUES (?, ?, 'installed', '{}', ?, ?, NOW()) ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL, disabled_at = NULL",
      [$workspaceId, CRM\\Services\\WorkspaceSkillCatalogService::PLUGIN_DESIGN, $userId, $userId]
    );
    CRM\\Database::execute(
      "INSERT INTO workspace_skill_installs (workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at) VALUES (?, ?, 'installed', '{}', ?, ?, NOW()) ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL, disabled_at = NULL",
      [$workspaceId, CRM\\Services\\WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO, $userId, $userId]
    );
    CRM\\Services\\WorkspaceSkillCatalogService::resetRuntimeCaches();
    $marketing = new CRM\\Modules\\Marketing();
    $existing = CRM\\Database::queryOne('SELECT id FROM marketing_landing_pages WHERE workspace_id = ? AND title = ? ORDER BY id DESC LIMIT 1', [$workspaceId, 'Visual Landing Review Fixture']);
    if ($existing) {
      $landingPageId = (int) $existing['id'];
    } else {
      $landingPageId = $marketing->createLandingPage([
        'title' => 'Visual Landing Review Fixture',
        'slug' => 'visual-landing-review-fixture-' . time(),
        'headline' => 'A clearer destination for founder-led marketing',
        'seo_title' => 'Visual Landing Review Fixture',
        'meta_description' => 'A browser test fixture for the visual landing page review board.',
        'body_sections' => [
          ['heading' => 'Founder promise', 'body' => 'A simple page that explains the offer, the proof, and the next action.'],
          ['heading' => 'Why it matters', 'body' => 'The first screen stays visual while detailed evidence remains available.'],
        ],
        'cta_blocks' => [
          ['heading' => 'Book a quick review', 'body' => 'Use one visible action to keep the founder moving.'],
        ],
        'proof_blocks' => [
          ['heading' => 'Proof point', 'body' => 'The page has enough signal for visual QA.'],
        ],
        'faq_blocks' => [
          ['heading' => 'Is this automated publishing?', 'body' => 'No. Publishing remains manual and permission-gated.'],
        ],
        'thank_you_copy' => 'Thanks. We will follow up with the next useful step.',
        'conversion_goal' => 'lead_capture',
        'status' => 'draft',
        'metadata_json' => ['source' => 'visual_test_fixture'],
        'created_by' => $userId,
      ]);
    }
    echo (string) $landingPageId;
  `;
  return execFileSync('php', ['-r', code], {
    cwd: path.resolve(__dirname, '..'),
    encoding: 'utf8',
  }).trim();
}

function getLandingPagePreviewToken(landingPageId) {
  const code = `
    require_once getcwd() . '/vendor/autoload.php';
    require_once getcwd() . '/config/constants.php';
    CRM\\Database::init(require getcwd() . '/config/database.php');
    $row = CRM\\Database::queryOne('SELECT preview_token FROM marketing_landing_pages WHERE id = ?', [(int) ${Number.parseInt(landingPageId, 10)}]);
    echo (string) ($row['preview_token'] ?? '');
  `;
  return execFileSync('php', ['-r', code], {
    cwd: path.resolve(__dirname, '..'),
    encoding: 'utf8',
  }).trim();
}

function ensureLandingPagePublicToken(landingPageId) {
  const code = `
    require_once getcwd() . '/vendor/autoload.php';
    require_once getcwd() . '/config/constants.php';
    CRM\\Database::init(require getcwd() . '/config/database.php');
    $page = CRM\\Database::queryOne('SELECT workspace_id, slug, conversion_goal, created_by FROM marketing_landing_pages WHERE id = ?', [(int) ${Number.parseInt(landingPageId, 10)}]);
    if (!$page) { throw new RuntimeException('Landing page fixture missing.'); }
    $workspaceId = (int) $page['workspace_id'];
    $existing = CRM\\Database::queryOne('SELECT public_token FROM marketing_landing_page_publications WHERE workspace_id = ? AND landing_page_id = ? ORDER BY id DESC LIMIT 1', [$workspaceId, (int) ${Number.parseInt(landingPageId, 10)}]);
    $token = (string) ($existing['public_token'] ?? '');
    if ($token === '') { $token = bin2hex(random_bytes(16)); }
    $publicUrl = 'marketing_landing_public.php?token=' . rawurlencode($token);
    $readiness = json_encode(['score' => 100, 'missing' => []], JSON_UNESCAPED_SLASHES);
    if ($existing) {
      CRM\\Database::execute(
        "UPDATE marketing_landing_page_publications
         SET public_token = ?, public_url = ?, status = 'published', conversion_goal = ?, readiness_score = 100,
             readiness_json = ?, published_at = COALESCE(published_at, NOW()), unpublished_at = NULL
         WHERE workspace_id = ? AND landing_page_id = ?",
        [$token, $publicUrl, (string) ($page['conversion_goal'] ?? 'lead_capture'), $readiness, $workspaceId, (int) ${Number.parseInt(landingPageId, 10)}]
      );
    } else {
      CRM\\Database::execute(
        "INSERT INTO marketing_landing_page_publications
         (workspace_id, landing_page_id, uuid, slug, public_token, public_url, status, conversion_goal,
          readiness_score, readiness_json, metadata_json, published_at, created_by)
         VALUES (?, ?, UUID(), ?, ?, ?, 'published', ?, 100, ?, ?, NOW(), ?)",
        [
          $workspaceId,
          (int) ${Number.parseInt(landingPageId, 10)},
          (string) $page['slug'],
          $token,
          $publicUrl,
          (string) ($page['conversion_goal'] ?? 'lead_capture'),
          $readiness,
          json_encode(['source' => 'visual_test_fixture'], JSON_UNESCAPED_SLASHES),
          (int) ($page['created_by'] ?? 0) ?: null,
        ]
      );
    }
    CRM\\Database::execute("UPDATE marketing_landing_pages SET builder_status = 'published' WHERE workspace_id = ? AND id = ?", [$workspaceId, (int) ${Number.parseInt(landingPageId, 10)}]);
    echo $token;
  `;
  return execFileSync('php', ['-r', code], {
    cwd: path.resolve(__dirname, '..'),
    encoding: 'utf8',
  }).trim();
}

async function login(page) {
  await page.goto(`${BASE_URL}/login.php`);
  await page.fill('input[name="email"]', TEST_EMAIL);
  await page.fill('input[name="password"]', TEST_PASSWORD);
  await Promise.all([
    page.waitForURL((url) => !String(url).includes('login.php'), { timeout: 15000 }),
    page.click('button[type="submit"]'),
  ]);
}

async function dismissCookieBanner(page) {
  const accept = page.getByRole('button', { name: /Accept All/i });
  if (await accept.isVisible().catch(() => false)) {
    await accept.click({ force: true });
  }
}

async function createAudienceSegmentForPreview(page) {
  const name = `Visual Preview ${Date.now()}`;
  await page.goto(`${BASE_URL}/marketing_segment_edit.php`);
  await dismissCookieBanner(page);
  await page.fill('input[name="name"]', name);
  await page.fill('input[name="rules[0][value_text]"]', 'visual');
  await page.click('.marketing-sticky-actions button[type="submit"]');
  await page.waitForURL((url) => String(url).includes('marketing_segment_view.php'), { timeout: 15000 });
  const match = String(page.url()).match(/[?&]id=(\d+)/);
  expect(match).not.toBeNull();
  return match[1];
}

async function createCampaignBriefForReview(page) {
  const title = `Visual Brief ${Date.now()}`;
  await page.goto(`${BASE_URL}/marketing_brief_edit.php`);
  await dismissCookieBanner(page);
  await page.fill('input[name="title"]', title);
  await page.fill('input[name="objective"]', 'Increase qualified demo requests');
  await page.fill('input[name="audience"]', 'Founder operators');
  await page.fill('input[name="offer_text"]', 'A guided launch planning session');
  await page.fill('textarea[name="key_message"]', 'Turn a scattered marketing idea into one campaign that is clear enough to create, launch, and learn from.');
  await page.click('.marketing-brief-builder-today button[value="save"]');
  await page.waitForURL((url) => String(url).includes('marketing_brief_view.php'), { timeout: 15000 });
  const match = String(page.url()).match(/[?&]id=(\d+)/);
  expect(match).not.toBeNull();
  return match[1];
}

async function createJourneyForReview(page) {
  const name = `Visual Journey ${Date.now()}`;
  await page.goto(`${BASE_URL}/marketing_journey_edit.php`);
  await dismissCookieBanner(page);
  await page.fill('input[name="name"]', name);
  await page.fill('input[name="journey_goal"]', 'Move interested founders to a clear next step');
  await page.fill('input[name="steps[0][title]"]', 'Send the first helpful message');
  await page.locator('.marketing-journey-step-card details summary').first().click();
  await page.fill('textarea[name="steps[0][instructions]"]', 'Use the campaign brief to write one short, useful message.');
  await page.click('.marketing-journey-builder-save button[type="submit"]');
  await page.waitForURL((url) => String(url).includes('marketing_journey_view.php'), { timeout: 15000 });
  const match = String(page.url()).match(/[?&]id=(\d+)/);
  expect(match).not.toBeNull();
  return match[1];
}

async function createPlaybookForLibrary(page) {
  await page.goto(`${BASE_URL}/marketing_playbooks.php`);
  await dismissCookieBanner(page);
  await page.locator('details.marketing-playbook-tools summary').click();
  await page.locator('.marketing-playbook-template-option button[type="submit"]').first().click();
  await page.waitForURL((url) => String(url).includes('marketing_playbook_view.php'), { timeout: 15000 });
  const match = String(page.url()).match(/[?&]id=(\d+)/);
  expect(match).not.toBeNull();
  await page.goto(`${BASE_URL}/marketing_playbooks.php`);
  await dismissCookieBanner(page);
  return match[1];
}

async function openFirstDistributionBundle(page) {
  await page.goto(`${BASE_URL}/marketing_distribution.php`);
  await dismissCookieBanner(page);
  const bundleLinks = page.locator('.marketing-distribution-card-action');
  const count = await bundleLinks.count();
  expect(count).toBeGreaterThan(0);
  const href = await bundleLinks.first().getAttribute('href');
  expect(href).toBeTruthy();
  await page.goto(new URL(href, `${BASE_URL}/`).toString());
  await dismissCookieBanner(page);
}

async function openFirstOperatorExportPack(page) {
  expect(operatorExportPackId).toBeTruthy();
  await page.goto(`${BASE_URL}/marketing_operator_export_packs.php?id=${operatorExportPackId}`);
  await dismissCookieBanner(page);
}

async function expectTooltipVisible(locator, expectedContent = null) {
  await locator.hover();
  await expect.poll(async () => locator.evaluate((el) => {
    const style = window.getComputedStyle(el, '::after');
    const opacity = Number.parseFloat(style.opacity);
    return style.content.length > 4 && opacity > 0;
  })).toBe(true);

  const tooltip = await locator.evaluate((el) => {
    const style = window.getComputedStyle(el, '::after');
    return { content: style.content, opacity: Number.parseFloat(style.opacity) };
  });
  expect(tooltip.content.length).toBeGreaterThan(4);
  if (expectedContent !== null) {
    expect(tooltip.content).toContain(expectedContent);
  }
  expect(tooltip.opacity).toBeGreaterThan(0);
}

async function expectMarketingSurfaceFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-founder-hero',
      '.marketing-summary-tile',
      '.marketing-stage-card',
      '.marketing-today-action',
      '.marketing-advanced-tools',
      '.marketing-detailed-systems',
      '.marketing-system-signal-card',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingOnboardingFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-setup-summary',
      '.marketing-setup-readiness-card',
      '.marketing-setup-step-card',
      '.marketing-setup-today',
      '.marketing-setup-starter-card',
      '.marketing-setup-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingContextFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-context-summary',
      '.marketing-context-type-card',
      '.marketing-context-card-visual',
      '.marketing-context-card-action',
      '.marketing-context-list',
      '.marketing-context-saved-card',
      '.marketing-context-form',
      '.marketing-context-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingBrandFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-brand-summary',
      '.marketing-brand-type-card',
      '.marketing-brand-card-visual',
      '.marketing-brand-card-action',
      '.marketing-brand-profiles',
      '.marketing-brand-profile-card',
      '.marketing-brand-form',
      '.marketing-brand-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingPersonasFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-personas-summary',
      '.marketing-persona-signal-card',
      '.marketing-persona-signal-visual',
      '.marketing-persona-signal-action',
      '.marketing-personas-list',
      '.marketing-persona-profile-card',
      '.marketing-personas-form',
      '.marketing-personas-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingSegmentsFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-segments-summary',
      '.marketing-segment-signal-card',
      '.marketing-segment-signal-visual',
      '.marketing-segment-signal-action',
      '.marketing-segments-list',
      '.marketing-segments-next',
      '.marketing-segments-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingSegmentEditFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-segment-edit-summary',
      '.marketing-segment-builder-card',
      '.marketing-segment-builder-visual',
      '.marketing-segment-builder-action',
      '.marketing-segment-details',
      '.marketing-segment-builder-next',
      '.marketing-segment-rules',
      '.marketing-segment-edit-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingSegmentViewFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-segment-view-summary',
      '.marketing-segment-preview-card',
      '.marketing-segment-rules-preview',
      '.marketing-segment-contact-preview',
      '.marketing-segment-preview-next',
      '.marketing-segment-view-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingBriefsFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-briefs-summary',
      '.marketing-brief-signal-card',
      '.marketing-briefs-list',
      '.marketing-brief-card',
      '.marketing-briefs-today',
      '.marketing-briefs-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingBriefEditFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-brief-edit-summary',
      '.marketing-brief-builder-card',
      '.marketing-brief-core-fields',
      '.marketing-brief-edit-tools',
      '.marketing-brief-builder-today',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingBriefViewFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-brief-view-summary',
      '.marketing-brief-review-card',
      '.marketing-brief-message-card',
      '.marketing-brief-decision-item',
      '.marketing-brief-view-today',
      '.marketing-brief-view-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingCampaignWorkspaceFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-campaign-workspace-summary',
      '.marketing-campaign-stage-card',
      '.marketing-campaign-queue-card',
      '.marketing-campaign-focus-card',
      '.marketing-campaign-evidence-card',
      '.marketing-campaign-today',
      '.marketing-campaign-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingAudienceActivationFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-audience-summary',
      '.marketing-audience-stage-card',
      '.marketing-audience-activation-card',
      '.marketing-audience-today',
      '.marketing-audience-tools',
      '.marketing-audience-create-form',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingJourneysFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-journey-summary',
      '.marketing-journey-stage-card',
      '.marketing-journey-card',
      '.marketing-journey-today',
      '.marketing-journey-tools',
      '.marketing-journey-score-card',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingJourneyBuilderFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-journey-edit-summary',
      '.marketing-journey-builder-card',
      '.marketing-journey-core-fields',
      '.marketing-journey-step-card',
      '.marketing-journey-builder-today',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingJourneyReviewFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-journey-view-summary',
      '.marketing-journey-review-card',
      '.marketing-journey-readiness-card',
      '.marketing-journey-timeline-step',
      '.marketing-journey-view-today',
      '.marketing-journey-view-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingPlaybooksFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-playbooks-summary',
      '.marketing-playbook-stage-card',
      '.marketing-playbook-board',
      '.marketing-playbook-card',
      '.marketing-playbooks-today',
      '.marketing-playbook-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingPlaybookBuilderFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-playbook-edit-summary',
      '.marketing-playbook-builder-card',
      '.marketing-playbook-core-fields',
      '.marketing-playbook-foundation-fields',
      '.marketing-playbook-structure-fields',
      '.marketing-playbook-builder-today',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingPlaybookViewFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-playbook-view-summary',
      '.marketing-playbook-view-card',
      '.marketing-playbook-view-board',
      '.marketing-playbook-view-today',
      '.marketing-playbook-view-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingRoadmapFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-roadmap-summary',
      '.marketing-roadmap-stage-card',
      '.marketing-roadmap-board',
      '.marketing-roadmap-card',
      '.marketing-roadmap-today',
      '.marketing-roadmap-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingPersonaOfferFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-persona-offer-summary',
      '.marketing-persona-offer-stage-card',
      '.marketing-persona-offer-board',
      '.marketing-persona-offer-card',
      '.marketing-persona-offer-today',
      '.marketing-persona-offer-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingCalendarFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-calendar-summary',
      '.marketing-calendar-stage-card',
      '.marketing-calendar-board',
      '.marketing-calendar-date-card',
      '.marketing-calendar-milestone-card',
      '.marketing-calendar-today',
      '.marketing-calendar-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingReviewsFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-reviews-summary',
      '.marketing-reviews-stage-card',
      '.marketing-reviews-board',
      '.marketing-reviews-card',
      '.marketing-reviews-today',
      '.marketing-reviews-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingLaunchReadinessFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-launch-readiness-summary',
      '.marketing-launch-readiness-stage-card',
      '.marketing-launch-readiness-board',
      '.marketing-launch-readiness-card',
      '.marketing-launch-readiness-today',
      '.marketing-launch-readiness-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingLaunchControlFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-launch-control-summary',
      '.marketing-launch-control-stage-card',
      '.marketing-launch-control-board',
      '.marketing-launch-control-card',
      '.marketing-launch-control-today',
      '.marketing-launch-control-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingLaunchChecklistsFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-launch-checklists-summary',
      '.marketing-launch-checklists-stage-card',
      '.marketing-launch-checklists-board',
      '.marketing-launch-checklists-card',
      '.marketing-launch-checklists-today',
      '.marketing-launch-checklists-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingGuidedWorkflowsFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-guided-workflows-summary',
      '.marketing-guided-workflows-stage-card',
      '.marketing-guided-workflows-board',
      '.marketing-guided-workflows-card',
      '.marketing-guided-workflows-today',
      '.marketing-guided-workflows-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingTaskHubFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-task-hub-summary',
      '.marketing-task-hub-stage-card',
      '.marketing-task-hub-board',
      '.marketing-task-hub-queue-card',
      '.marketing-task-hub-today',
      '.marketing-task-hub-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingAssetsFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-assets-sidebar',
      '.marketing-assets-overview',
      '.marketing-assets-summary',
      '.marketing-assets-stage-card',
      '.marketing-assets-board',
      '.marketing-assets-operation-card',
      '.marketing-assets-library',
      '.marketing-assets-media-card',
      '.marketing-assets-today',
      '.marketing-assets-reporting',
      '.marketing-assets-tool-hub',
      '.marketing-assets-tool-panel',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingDistributionFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-distribution-summary',
      '.marketing-distribution-stage-card',
      '.marketing-distribution-board',
      '.marketing-distribution-post-card',
      '.marketing-distribution-today',
      '.marketing-distribution-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingDistributionBundleFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-distribution-bundle-summary',
      '.marketing-distribution-bundle-stage-card',
      '.marketing-distribution-bundle-copy',
      '.marketing-distribution-bundle-media',
      '.marketing-distribution-bundle-checklist',
      '.marketing-distribution-bundle-kits',
      '.marketing-distribution-bundle-today',
      '.marketing-distribution-bundle-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingOperatorPackFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-operator-pack-summary',
      '.marketing-operator-pack-stage-card',
      '.marketing-operator-pack-copy',
      '.marketing-operator-pack-risks',
      '.marketing-operator-pack-next',
      '.marketing-operator-pack-today',
      '.marketing-operator-pack-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingChannelExportsFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-channel-export-summary',
      '.marketing-channel-export-stage-card',
      '.marketing-channel-export-board',
      '.marketing-channel-export-media',
      '.marketing-channel-export-today',
      '.marketing-channel-export-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingUtmFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-utm-summary',
      '.marketing-utm-stage-card',
      '.marketing-utm-board',
      '.marketing-utm-create',
      '.marketing-utm-today',
      '.marketing-utm-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingEmailRunsFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-email-run-summary',
      '.marketing-email-run-stage-card',
      '.marketing-email-run-board',
      '.marketing-email-run-create',
      '.marketing-email-run-today',
      '.marketing-email-run-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingPerformanceFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-performance-summary',
      '.marketing-performance-stage-card',
      '.marketing-performance-loop',
      '.marketing-performance-goals',
      '.marketing-performance-today',
      '.marketing-performance-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingHandoffsFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-handoff-summary',
      '.marketing-handoff-stage-card',
      '.marketing-handoff-board',
      '.marketing-handoff-today',
      '.marketing-handoff-card',
      '.marketing-handoff-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingWeeklyReportFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-weekly-period',
      '.marketing-weekly-report-summary',
      '.marketing-weekly-report-stage-card',
      '.marketing-weekly-report-board',
      '.marketing-weekly-report-today',
      '.marketing-weekly-signal-card',
      '.marketing-weekly-report-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingMonthlyReportFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-monthly-period',
      '.marketing-monthly-report-summary',
      '.marketing-monthly-report-stage-card',
      '.marketing-monthly-report-board',
      '.marketing-monthly-report-today',
      '.marketing-monthly-signal-card',
      '.marketing-monthly-report-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingOperationsFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-operations-week',
      '.marketing-operations-summary',
      '.marketing-operations-stage-card',
      '.marketing-operations-board',
      '.marketing-operations-today',
      '.marketing-operations-card',
      '.marketing-operations-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingQualityFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-quality-summary',
      '.marketing-quality-stage-card',
      '.marketing-quality-board',
      '.marketing-quality-today',
      '.marketing-quality-card',
      '.marketing-quality-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingRelationshipsFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-relationships-summary',
      '.marketing-relationships-stage-card',
      '.marketing-relationships-board',
      '.marketing-relationships-today',
      '.marketing-relationships-card',
      '.marketing-relationships-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingSeoFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-seo-summary',
      '.marketing-seo-stage-card',
      '.marketing-seo-board',
      '.marketing-seo-today',
      '.marketing-seo-topic-card',
      '.marketing-seo-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingLandingFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-landing-summary',
      '.marketing-landing-stage-card',
      '.marketing-landing-board',
      '.marketing-landing-today',
      '.marketing-landing-card',
      '.marketing-landing-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingLandingEditFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.design-studio-toolbar',
      '.design-stage',
      '.design-canvas-viewport',
      '.design-canvas',
      '.design-mobile-nav',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingActionRouterFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-action-router-summary',
      '.marketing-action-router-stage-card',
      '.marketing-action-router-stage-visual',
      '.marketing-action-router-board',
      '.marketing-action-router-today',
      '.marketing-action-router-card',
      '.marketing-action-router-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingSystemMapFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-system-map-summary',
      '.marketing-system-map-stage-card',
      '.marketing-system-map-board',
      '.marketing-system-map-today',
      '.marketing-system-map-tools',
      '.marketing-system-map-detail-card',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingDecisionsFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-decisions-summary',
      '.marketing-decision-card',
      '.marketing-decisions-board',
      '.marketing-decisions-today',
      '.marketing-decisions-create',
      '.marketing-decisions-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingAssistantsFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-assistants-summary',
      '.marketing-assistant-card',
      '.marketing-assistants-board',
      '.marketing-assistants-today',
      '.marketing-assistants-run-panel',
      '.marketing-assistants-tools',
      '.marketing-assistants-detail-section',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingAdminFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-admin-summary',
      '.marketing-admin-board',
      '.marketing-admin-health-card',
      '.marketing-admin-today',
      '.marketing-admin-footprint',
      '.marketing-admin-tools',
      '.marketing-admin-detail-section',
      '.marketing-admin-form-panel',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingExecutionFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-execution-summary',
      '.marketing-execution-board',
      '.marketing-execution-card',
      '.marketing-execution-today',
      '.marketing-execution-tools',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingContentFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-content-summary',
      '.marketing-content-board',
      '.marketing-content-stage-card',
      '.marketing-content-today',
      '.marketing-content-tools',
      '.marketing-board-column',
      '.production-command-lane',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingCreativeFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-creative-summary',
      '.marketing-creative-board',
      '.marketing-creative-stage-card',
      '.marketing-creative-today',
      '.marketing-creative-tools',
      '.creative-stat',
      '.creative-alert',
      '.creative-pipeline-stage',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingContentViewFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-content-view-summary',
      '.marketing-content-view-board',
      '.marketing-content-view-card',
      '.marketing-content-view-today',
      '.marketing-content-view-tools',
      '.marketing-detail-card',
      '.tool-form',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingContentEditFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-content-edit-summary',
      '.marketing-content-edit-card',
      '.marketing-content-edit-core',
      '.marketing-content-edit-draft',
      '.marketing-content-edit-today',
      '.marketing-content-edit-tools',
      '.media-placement-card',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingLandingViewFits(page) {
  const overflowing = await page.evaluate(() => {
    const tooltipNodes = Array.from(document.querySelectorAll('[data-tooltip]'));
    const tooltipValues = tooltipNodes.map((element) => [element, element.getAttribute('data-tooltip')]);
    tooltipNodes.forEach((element) => element.removeAttribute('data-tooltip'));
    const selectors = [
      '.marketing-page-header',
      '.marketing-landing-view-summary',
      '.marketing-landing-view-board',
      '.marketing-landing-view-card',
      '.marketing-landing-view-today',
      '.marketing-landing-view-tools',
      '.marketing-detail-card',
      '.builder-check',
    ];

    const result = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
    tooltipValues.forEach(([element, value]) => {
      if (value !== null) {
        element.setAttribute('data-tooltip', value);
      }
    });

    return result;
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingLandingPreviewFits(page) {
  const overflowing = await page.evaluate(() => {
    const selectors = [
      '.marketing-landing-preview',
      '.landing-preview-shell',
      '.preview-banner',
      '.preview-banner-actions',
      '.landing-hero',
      '.landing-section',
      '.landing-media',
      '.media-gallery',
      '.cta-band',
    ];

    return selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingPublicLandingFits(page) {
  const overflowing = await page.evaluate(() => {
    const selectors = [
      '.marketing-public-shell',
      '.marketing-public-hero',
      '.marketing-public-section',
      '.marketing-public-cta',
      '.marketing-public-cta-link',
      '.marketing-public-gallery',
      '.media',
    ];

    return selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
  });

  expect(overflowing).toEqual([]);
}

async function expectMarketingUnsubscribeFits(page) {
  const overflowing = await page.evaluate(() => {
    const selectors = [
      '.marketing-public-preferences-card',
      '.marketing-public-preferences-status',
      '.marketing-public-preferences-form',
      '.marketing-public-preferences-muted',
    ];

    return selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)).map((element, index) => {
      const rect = element.getBoundingClientRect();
      return {
        selector,
        index,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        width: rect.width,
        left: rect.left,
        right: rect.right,
        viewport: window.innerWidth,
      };
    })).filter((item) => item.scrollWidth - item.clientWidth > 2 || item.left < -2 || item.right - item.viewport > 2);
  });

  expect(overflowing).toEqual([]);
}

test.describe('Marketing command center visual maturity', () => {
  test('desktop first viewport is visual, uncluttered, and interactive', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;
    await expect(page).toHaveURL(/marketing\.php/);

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-founder-hero h1')).toHaveText('Marketing Pro');
    await expect(page.locator('.marketing-founder-summary')).toBeVisible();
    await expect(page.locator('.marketing-lifecycle-graph')).toBeVisible();
    await expect(page.locator('.marketing-lifecycle-node')).toHaveCount(7);
    await expect(page.locator('.marketing-stage-grid')).toBeHidden();
    await expect(page.locator('.marketing-today-panel')).toBeVisible();
    await expect(page.locator('body')).not.toContainText('Guided Marketing Recommendations');
    await expect(page.locator('.marketing-today-panel')).not.toContainText(/recommendation/i);

    await expect(page.locator('details.marketing-advanced-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('details.marketing-detailed-systems')).not.toHaveAttribute('open', '');
    const lifecycleHrefs = await page.locator('.marketing-lifecycle-node').evaluateAll((nodes) =>
      nodes.map((node) => node.getAttribute('href'))
    );
    lifecycleHrefs.forEach((href) => expect(href).toBeTruthy());
    await expectMarketingSurfaceFits(page);

    await page.locator('details.marketing-detailed-systems summary').click();
    await expect(page.locator('.marketing-system-signal-card')).toHaveCount(6);
    await expect(page.locator('.marketing-system-signal-intro')).toContainText('Compact health view');
    await expect(page.locator('.marketing-legacy-command-sections')).toHaveCount(0);
    await expect(page.locator('.marketing-detailed-systems-body > .content-card')).toHaveCount(0);
    await expectMarketingSurfaceFits(page);
    await page.locator('.marketing-detailed-systems').screenshot({ path: testInfo.outputPath('marketing-system-signals-desktop.png') });
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(150);

    const topViewport = await page.evaluate(() => {
      const hero = document.querySelector('.marketing-founder-hero')?.getBoundingClientRect();
      const summary = document.querySelector('.marketing-founder-summary')?.getBoundingClientRect();
      const launchPacket = document.querySelector('.marketing-launch-packet-card')?.getBoundingClientRect();
      return {
        heroTop: hero ? Math.round(hero.top) : null,
        summaryBottom: summary ? Math.round(summary.bottom) : null,
        launchPacketTop: launchPacket ? Math.round(launchPacket.top) : null,
        viewportHeight: window.innerHeight,
      };
    });
    expect(topViewport.heroTop).toBeGreaterThanOrEqual(0);
    expect(topViewport.summaryBottom).toBeLessThanOrEqual(topViewport.viewportHeight);
    expect(topViewport.launchPacketTop).toBeLessThanOrEqual(topViewport.viewportHeight);

    const firstHelp = page.locator('.marketing-lifecycle-node').first();
    await expectTooltipVisible(firstHelp);

    const firstViewportText = await page.locator('body').innerText();
    expect(firstViewportText).not.toContain('Operator Command Flow');
    expect(firstViewportText).not.toContain('Unified Next Best Actions');

    const lifecycleHeights = await page.locator('.marketing-lifecycle-node').evaluateAll((nodes) =>
      nodes.map((node) => Math.round(node.getBoundingClientRect().height))
    );
    expect(Math.max(...lifecycleHeights) - Math.min(...lifecycleHeights)).toBeLessThanOrEqual(8);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-command-center-desktop.png'), fullPage: false });
  });

  test('mobile first viewport stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-founder-hero h1')).toHaveText('Marketing Pro');
    await expect(page.locator('.marketing-lifecycle-graph')).toBeVisible();
    await expect(page.locator('.marketing-lifecycle-node')).toHaveCount(7);
    await expect(page.locator('.marketing-stage-grid')).toBeHidden();
    await expect(page.locator('body')).not.toContainText('Guided Marketing Recommendations');
    await expect(page.locator('.marketing-today-panel')).not.toContainText(/recommendation/i);
    await page.locator('details.marketing-detailed-systems summary').click();
    await expect(page.locator('.marketing-system-signal-card')).toHaveCount(6);
    await expect(page.locator('.marketing-legacy-command-sections')).toHaveCount(0);
    await page.locator('.marketing-detailed-systems').screenshot({ path: testInfo.outputPath('marketing-system-signals-mobile.png') });

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingSurfaceFits(page);

    const lifecycleRail = await page.locator('.marketing-lifecycle-graph').boundingBox();
    expect(lifecycleRail.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-command-center-mobile.png'), fullPage: false });
  });
});

test.describe('Legacy Marketing menu refinement', () => {
  test('campaign automation, campaign detail, and attribution share the refined shell', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/campaigns.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/campaigns\.php/);
    await expect(page.locator('.marketing-campaigns-page.marketing-ui-refined')).toBeVisible();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Campaign Automation');
    await expect(page.locator('.marketing-campaigns-page [style]')).toHaveCount(0);
    const campaignLinks = page.locator('a.campaign-name');
    expect(await campaignLinks.count()).toBeGreaterThan(0);
    const campaignHref = await campaignLinks.first().getAttribute('href');
    expect(campaignHref).toMatch(/^campaign_view\.php\?id=\d+$/);
    const campaignStyles = await page.locator('.marketing-campaigns-page').evaluate((element) => {
      const styles = getComputedStyle(element);
      const headerStyles = getComputedStyle(element.querySelector('.marketing-page-header'));
      return {
        primary: styles.getPropertyValue('--marketing-refine-primary').trim(),
        headerRadius: headerStyles.borderRadius,
        overflow: document.documentElement.scrollWidth - window.innerWidth,
      };
    });
    expect(campaignStyles.primary).toBe('#0f67ea');
    expect(campaignStyles.headerRadius).toBe('12px');
    expect(campaignStyles.overflow).toBeLessThanOrEqual(2);
    await page.screenshot({ path: testInfo.outputPath('marketing-campaigns-refined-desktop.png'), fullPage: false });

    await page.goto(new URL(campaignHref, `${BASE_URL}/`).toString());
    await expect(page).toHaveURL(/campaign_view\.php\?id=/);
    await expect(page.locator('.marketing-campaign-view-page.marketing-ui-refined')).toBeVisible();
    await expect(page.locator('.marketing-campaign-view-page [style]')).toHaveCount(0);
    await expect(page.locator('.campaign-sequence-state')).toBeVisible();

    await page.goto(`${BASE_URL}/attribution_reports.php`);
    await expect(page).toHaveURL(/attribution_reports\.php/);
    await expect(page.locator('.marketing-attribution-page.marketing-ui-refined')).toBeVisible();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Attribution Reports');
    await expect(page.locator('.marketing-attribution-page [style]')).toHaveCount(0);
    await expect(page.locator('.filters-card')).toBeVisible();
    await expect(page.locator('.page-content')).toHaveCSS('opacity', '1');
    const attributionOverflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(attributionOverflow).toBeLessThanOrEqual(2);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-attribution-refined-desktop.png'), fullPage: false });
  });

  test('legacy Marketing menu pages remain usable on mobile', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/campaigns.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;
    await expect(page.locator('.marketing-campaigns-page.marketing-ui-refined')).toBeVisible();
    await expect(page.locator('.campaign-form-grid')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(2);
    await page.screenshot({ path: testInfo.outputPath('marketing-campaigns-refined-mobile.png'), fullPage: false });

    await page.goto(`${BASE_URL}/attribution_reports.php`);
    await expect(page.locator('.marketing-attribution-page.marketing-ui-refined')).toBeVisible();
    await expect(page.locator('.filters-card')).toBeVisible();
    await expect(page.locator('.page-content')).toHaveCSS('opacity', '1');
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(2);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-attribution-refined-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing onboarding visual maturity', () => {
  test('desktop founder setup board is visual, low-text, and gated by one advanced drawer', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_onboarding.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_onboarding\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Know Your Customer');
    await expect(page.locator('.marketing-setup-summary')).toBeVisible();
    await expect(page.locator('.marketing-setup-readiness-card')).toBeVisible();
    await expect(page.locator('.marketing-setup-step-card').first()).toBeVisible();
    await expect(page.locator('.marketing-setup-today')).toBeVisible();
    await expect(page.locator('details.marketing-setup-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-onboarding-page [style]')).toHaveCount(0);
    const setupCardCount = await page.locator('.marketing-setup-step-card').count();
    expect(setupCardCount).toBeGreaterThan(0);
    await expect(page.locator('.marketing-setup-step-card .marketing-stage-action')).toHaveCount(setupCardCount);
    const todayActionCount = await page.locator('.marketing-today-action').count();
    expect(todayActionCount).toBeGreaterThan(0);
    expect(todayActionCount).toBeLessThanOrEqual(5);
    await expect(page.locator('body')).not.toContainText('Recommended Next Steps');
    await expectMarketingOnboardingFits(page);

    const firstHelp = page.locator('.marketing-setup-step-card .marketing-tooltip-trigger').first();
    await firstHelp.hover();
    await expectTooltipVisible(firstHelp);

    await page.locator('details.marketing-setup-tools > summary').click();
    await expect(page.locator('.marketing-setup-tools-body')).toBeVisible();
    await expect(page.locator('.setup-action-card')).toHaveCount(9);
    await expectMarketingOnboardingFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-onboarding-desktop.png'), fullPage: false });
  });

  test('mobile founder setup board stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_onboarding.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Know Your Customer');
    await expect(page.locator('.marketing-setup-summary')).toBeVisible();
    await expect(page.locator('.marketing-setup-step-card').first()).toBeVisible();
    await expect(page.locator('details.marketing-setup-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-onboarding-page [style]')).toHaveCount(0);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingOnboardingFits(page);

    const firstCard = await page.locator('.marketing-setup-step-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-onboarding-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing context visual maturity', () => {
  test('desktop context library is visual, low-text, and drawer-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_context.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_context\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Context Library');
    await expect(page.locator('.marketing-context-summary')).toBeVisible();
    await expect(page.locator('.marketing-context-type-card')).toHaveCount(7);
    await expect(page.locator('.marketing-context-card-action')).toHaveCount(7);
    await expect(page.locator('.marketing-context-list')).toBeVisible();
    await expect(page.locator('.marketing-context-form')).toBeVisible();
    await expect(page.locator('details.marketing-context-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-page-header')).not.toContainText('Marketing Context Hub');
    await expect(page.locator('body')).not.toContainText('Context Completeness');
    await expectMarketingContextFits(page);

    const firstTypeCard = page.locator('.marketing-context-type-card').first();
    await expect(firstTypeCard.locator('.marketing-context-card-visual')).toBeVisible();
    await expect(firstTypeCard.locator('.marketing-context-card-action')).toHaveText('Review');
    await firstTypeCard.hover();
    await expectTooltipVisible(firstTypeCard);

    await page.locator('details.marketing-context-tools summary').click();
    await expect(page.locator('.marketing-context-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-context-filter')).toBeVisible();
    await expect(page.locator('.marketing-context-tools .marketing-advanced-tools-grid a')).toHaveCount(3);
    await expectMarketingContextFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-context-desktop.png'), fullPage: false });
  });

  test('mobile context library stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_context.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Context Library');
    await expect(page.locator('.marketing-context-summary')).toBeVisible();
    await expect(page.locator('.marketing-context-type-card').first()).toBeVisible();
    await expect(page.locator('.marketing-context-card-action')).toHaveCount(7);
    await expect(page.locator('details.marketing-context-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingContextFits(page);

    const firstCard = await page.locator('.marketing-context-type-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-context-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing brand visual maturity', () => {
  test('desktop brand library is visual, low-text, and drawer-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_brand.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_brand\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Brand Library');
    await expect(page.locator('.marketing-brand-summary')).toBeVisible();
    await expect(page.locator('.marketing-brand-type-card')).toHaveCount(6);
    await expect(page.locator('.marketing-brand-card-action')).toHaveCount(6);
    await expect(page.locator('.marketing-brand-profiles')).toBeVisible();
    await expect(page.locator('.marketing-brand-form')).toBeVisible();
    await expect(page.locator('details.marketing-brand-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('body')).not.toContainText('Template And Brand System');
    await expectMarketingBrandFits(page);

    const firstTypeCard = page.locator('.marketing-brand-type-card').first();
    await expect(firstTypeCard.locator('.marketing-brand-card-visual')).toBeVisible();
    await expect(firstTypeCard.locator('.marketing-brand-card-action')).toContainText(/Create|Review/);
    await firstTypeCard.hover();
    await expectTooltipVisible(firstTypeCard);

    await page.locator('details.marketing-brand-tools summary').click();
    await expect(page.locator('.marketing-brand-tools-body')).toBeVisible();
    await expect(page.locator('#add-template')).toBeVisible();
    await expectMarketingBrandFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-brand-desktop.png'), fullPage: false });
  });

  test('mobile brand library stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_brand.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Brand Library');
    await expect(page.locator('.marketing-brand-summary')).toBeVisible();
    await expect(page.locator('.marketing-brand-type-card').first()).toBeVisible();
    await expect(page.locator('.marketing-brand-card-action')).toHaveCount(6);
    await expect(page.locator('details.marketing-brand-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingBrandFits(page);

    const firstCard = await page.locator('.marketing-brand-type-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-brand-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing personas visual maturity', () => {
  test('desktop persona library is visual, low-text, and drawer-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_personas.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_personas\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Customer Personas');
    await expect(page.locator('.marketing-page-actions')).toContainText('Context');
    await expect(page.locator('.marketing-personas-summary')).toBeVisible();
    await expect(page.locator('.marketing-persona-signal-card')).toHaveCount(5);
    await expect(page.locator('.marketing-persona-signal-action')).toHaveCount(5);
    await expect(page.locator('.marketing-personas-list')).toBeVisible();
    await expect(page.locator('.marketing-personas-form')).toBeVisible();
    await expect(page.locator('details.marketing-personas-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('body')).not.toContainText('Define customer segments, pains, goals, objections, and preferred channels.');
    await expectMarketingPersonasFits(page);

    const firstSignalCard = page.locator('.marketing-persona-signal-card').first();
    await expect(firstSignalCard.locator('.marketing-persona-signal-visual')).toBeVisible();
    await expect(firstSignalCard.locator('.marketing-persona-signal-action')).toContainText(/Add|Review/);
    await firstSignalCard.hover();
    await expectTooltipVisible(firstSignalCard);

    await page.locator('details.marketing-personas-tools summary').click();
    await expect(page.locator('.marketing-personas-tools .marketing-advanced-tools-grid a')).toHaveCount(3);
    await expectMarketingPersonasFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-personas-desktop.png'), fullPage: false });
  });

  test('mobile persona library stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_personas.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Customer Personas');
    await expect(page.locator('.marketing-page-actions')).toContainText('Context');
    await expect(page.locator('.marketing-personas-summary')).toBeVisible();
    await expect(page.locator('.marketing-persona-signal-card').first()).toBeVisible();
    await expect(page.locator('.marketing-persona-signal-action')).toHaveCount(5);
    await expect(page.locator('details.marketing-personas-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingPersonasFits(page);

    const firstCard = await page.locator('.marketing-persona-signal-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-personas-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing segments visual maturity', () => {
  test('desktop audience builder is visual, low-text, and drawer-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_segments.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_segments\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Audience Builder');
    await expect(page.locator('.marketing-page-actions')).toContainText('Personas');
    await expect(page.locator('.marketing-segments-summary')).toBeVisible();
    await expect(page.locator('.marketing-segment-signal-card')).toHaveCount(5);
    await expect(page.locator('.marketing-segment-signal-visual')).toHaveCount(5);
    await expect(page.locator('.marketing-segment-signal-action')).toHaveCount(5);
    await expect(page.locator('.marketing-segments-list')).toBeVisible();
    await expect(page.locator('.marketing-segments-next')).toBeVisible();
    await expect(page.locator('details.marketing-segments-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('body')).not.toContainText('Build CRM-derived audiences from contacts, companies, deals, forms, tags, activities, lifecycle stage, and engagement.');
    await expect(page.locator('body')).not.toContainText('Audience Journey Cockpit');
    await expectMarketingSegmentsFits(page);

    const signalAnatomy = await page.evaluate(() => Array.from(document.querySelectorAll('.marketing-segment-signal-card')).map((card) => ({
      actions: card.querySelectorAll('.marketing-segment-signal-action').length,
      hasVisual: Boolean(card.querySelector('.marketing-segment-signal-visual')),
      actionText: (card.querySelector('.marketing-segment-signal-action')?.textContent || '').trim(),
    })));
    expect(signalAnatomy).toEqual(signalAnatomy.map(() => expect.objectContaining({
      actions: 1,
      hasVisual: true,
      actionText: expect.stringMatching(/^(Add|Review)$/),
    })));

    const signalHeights = await page.evaluate(() => Array.from(document.querySelectorAll('.marketing-segment-signal-card')).map((card) => Math.round(card.getBoundingClientRect().height)));
    expect(Math.max(...signalHeights) - Math.min(...signalHeights)).toBeLessThanOrEqual(8);

    const firstSignalCard = page.locator('.marketing-segment-signal-card').first();
    await firstSignalCard.hover();
    await expectTooltipVisible(firstSignalCard);

    await page.locator('details.marketing-segments-tools summary').click();
    await expect(page.locator('.marketing-segments-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-segments-tools .marketing-advanced-tools-grid a')).toHaveCount(3);
    await expectMarketingSegmentsFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-segments-desktop.png'), fullPage: false });
  });

  test('mobile audience builder stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_segments.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Audience Builder');
    await expect(page.locator('.marketing-segments-summary')).toBeVisible();
    await expect(page.locator('.marketing-segment-signal-card').first()).toBeVisible();
    await expect(page.locator('.marketing-segment-signal-visual').first()).toBeVisible();
    await expect(page.locator('.marketing-segment-signal-action').first()).toHaveText(/^(Add|Review)$/);
    await expect(page.locator('details.marketing-segments-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingSegmentsFits(page);

    const firstCard = await page.locator('.marketing-segment-signal-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-segments-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing segment rule builder visual maturity', () => {
  test('desktop rule builder is visual, focused, and drawer-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_segment_edit.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_segment_edit\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('New Audience Rules');
    await expect(page.locator('.marketing-page-actions')).toContainText('Audience');
    await expect(page.locator('.marketing-segment-edit-summary')).toBeVisible();
    await expect(page.locator('.marketing-segment-builder-card')).toHaveCount(4);
    await expect(page.locator('.marketing-segment-builder-visual')).toHaveCount(4);
    await expect(page.locator('.marketing-segment-builder-action')).toHaveCount(4);
    await expect(page.locator('.marketing-segment-details')).toBeVisible();
    await expect(page.locator('.marketing-segment-rule-card')).toHaveCount(6);
    await expect(page.locator('details.marketing-segment-edit-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('body')).not.toContainText('Create reusable CRM-derived audiences for briefs, landing pages, email runs, and channel export planning.');
    await expect(page.locator('body')).not.toContainText('Change the source, save if needed');
    await expectMarketingSegmentEditFits(page);

    const builderAnatomy = await page.evaluate(() => Array.from(document.querySelectorAll('.marketing-segment-builder-card')).map((card) => ({
      actions: card.querySelectorAll('.marketing-segment-builder-action').length,
      hasVisual: Boolean(card.querySelector('.marketing-segment-builder-visual')),
      actionText: (card.querySelector('.marketing-segment-builder-action')?.textContent || '').trim(),
    })));
    expect(builderAnatomy).toEqual(builderAnatomy.map(() => expect.objectContaining({
      actions: 1,
      hasVisual: true,
      actionText: expect.stringMatching(/^(Add|Review)$/),
    })));

    const builderHeights = await page.evaluate(() => Array.from(document.querySelectorAll('.marketing-segment-builder-card')).map((card) => Math.round(card.getBoundingClientRect().height)));
    expect(Math.max(...builderHeights) - Math.min(...builderHeights)).toBeLessThanOrEqual(8);

    const firstBuilderCard = page.locator('.marketing-segment-builder-card').first();
    await firstBuilderCard.hover();
    await expectTooltipVisible(firstBuilderCard);

    await page.locator('details.marketing-segment-edit-tools summary').click();
    await expect(page.locator('.marketing-segment-edit-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-segment-edit-tools .marketing-advanced-tools-grid a')).toHaveCount(3);
    await expectMarketingSegmentEditFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-segment-edit-desktop.png'), fullPage: false });
  });

  test('mobile rule builder stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_segment_edit.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('New Audience Rules');
    await expect(page.locator('.marketing-segment-edit-summary')).toBeVisible();
    await expect(page.locator('.marketing-segment-builder-card').first()).toBeVisible();
    await expect(page.locator('.marketing-segment-builder-visual').first()).toBeVisible();
    await expect(page.locator('.marketing-segment-builder-action').first()).toHaveText(/^(Add|Review)$/);
    await expect(page.locator('.marketing-segment-rule-card').first()).toBeVisible();
    await expect(page.locator('details.marketing-segment-edit-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingSegmentEditFits(page);

    const firstCard = await page.locator('.marketing-segment-builder-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-segment-edit-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing segment preview visual maturity', () => {
  test('desktop audience preview is visual, low-text, and drawer-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    const segmentId = await createAudienceSegmentForPreview(page);
    await page.goto(`${BASE_URL}/marketing_segment_view.php?id=${segmentId}`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_segment_view\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Audience Preview');
    await expect(page.locator('.marketing-segment-view-summary')).toBeVisible();
    await expect(page.locator('.marketing-segment-preview-card')).toHaveCount(5);
    await expect(page.locator('.marketing-segment-rules-preview')).toBeVisible();
    await expect(page.locator('.marketing-segment-contact-preview')).toBeVisible();
    await expect(page.locator('details.marketing-segment-view-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('body')).not.toContainText('Preview CRM-derived contacts, snapshot a stable manual execution list, and see where this audience is used.');
    await expect(page.locator('.preview-table')).toHaveCount(0);
    await expectMarketingSegmentViewFits(page);

    const firstPreviewCard = page.locator('.marketing-segment-preview-card').first();
    await firstPreviewCard.hover();
    await expectTooltipVisible(firstPreviewCard);

    await page.locator('details.marketing-segment-view-tools summary').click();
    await expect(page.locator('.marketing-segment-view-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-segment-view-tools .marketing-advanced-tools-grid a')).toHaveCount(3);
    await expectMarketingSegmentViewFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-segment-view-desktop.png'), fullPage: false });
  });

  test('mobile audience preview stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    const segmentId = await createAudienceSegmentForPreview(page);
    await page.goto(`${BASE_URL}/marketing_segment_view.php?id=${segmentId}`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Audience Preview');
    await expect(page.locator('.marketing-segment-view-summary')).toBeVisible();
    await expect(page.locator('.marketing-segment-preview-card').first()).toBeVisible();
    await expect(page.locator('.marketing-segment-contact-preview')).toBeVisible();
    await expect(page.locator('details.marketing-segment-view-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingSegmentViewFits(page);

    const firstCard = await page.locator('.marketing-segment-preview-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-segment-view-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing campaign plan board visual maturity', () => {
  test('desktop briefs board is visual, low-text, and drawer-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_briefs.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_briefs\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Campaign Plan Board');
    await expect(page.locator('.marketing-briefs-summary')).toBeVisible();
    await expect(page.locator('.marketing-brief-signal-card')).toHaveCount(5);
    await expect(page.locator('.marketing-briefs-list')).toBeVisible();
    await expect(page.locator('.marketing-briefs-today')).toBeVisible();
    await expect(page.locator('details.marketing-briefs-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('body')).not.toContainText('Define the objective, audience, offer, message, and campaign window before content moves into production.');
    await expect(page.locator('.marketing-row')).toHaveCount(0);
    await expectMarketingBriefsFits(page);

    const firstSignalCard = page.locator('.marketing-brief-signal-card').first();
    await firstSignalCard.hover();
    await expectTooltipVisible(firstSignalCard);

    await page.locator('details.marketing-briefs-tools summary').click();
    await expect(page.locator('.marketing-briefs-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-briefs-tools .marketing-advanced-tools-grid a')).toHaveCount(3);
    await expectMarketingBriefsFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-briefs-desktop.png'), fullPage: false });
  });

  test('mobile briefs board stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_briefs.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Campaign Plan Board');
    await expect(page.locator('.marketing-briefs-summary')).toBeVisible();
    await expect(page.locator('.marketing-brief-signal-card').first()).toBeVisible();
    await expect(page.locator('.marketing-briefs-list')).toBeVisible();
    await expect(page.locator('details.marketing-briefs-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingBriefsFits(page);

    const firstCard = await page.locator('.marketing-brief-signal-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-briefs-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing campaign brief builder visual maturity', () => {
  test('desktop brief builder is visual, focused, and drawer-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_brief_edit.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_brief_edit\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('New Campaign Brief');
    await expect(page.locator('.marketing-brief-edit-summary')).toBeVisible();
    await expect(page.locator('.marketing-brief-builder-card')).toHaveCount(5);
    await expect(page.locator('.marketing-brief-core-fields')).toBeVisible();
    await expect(page.locator('.marketing-brief-builder-today')).toBeVisible();
    await expect(page.locator('details.marketing-brief-edit-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('body')).not.toContainText('Lock the strategy before content production starts.');
    await expect(page.locator('.marketing-form-grid')).toHaveCount(0);
    await expect(page.locator('.marketing-brief-edit-page [style]')).toHaveCount(0);
    await expectMarketingBriefEditFits(page);

    const firstBuilderCard = page.locator('.marketing-brief-builder-card').first();
    await firstBuilderCard.hover();
    await expectTooltipVisible(firstBuilderCard);

    await page.locator('details.marketing-brief-edit-tools summary').click();
    await expect(page.locator('.marketing-brief-edit-tools-body')).toBeVisible();
    await expect(page.locator('select[name="campaign_id"]')).toBeVisible();
    await expectMarketingBriefEditFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-brief-edit-desktop.png'), fullPage: false });
  });

  test('mobile brief builder stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_brief_edit.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('New Campaign Brief');
    await expect(page.locator('.marketing-brief-edit-summary')).toBeVisible();
    await expect(page.locator('.marketing-brief-builder-card').first()).toBeVisible();
    await expect(page.locator('.marketing-brief-core-fields')).toBeVisible();
    await expect(page.locator('details.marketing-brief-edit-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-brief-edit-page [style]')).toHaveCount(0);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingBriefEditFits(page);

    const firstCard = await page.locator('.marketing-brief-builder-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-brief-edit-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing campaign brief review visual maturity', () => {
  test('desktop brief review is visual, low-text, and evidence-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    const briefId = await createCampaignBriefForReview(page);
    await page.goto(`${BASE_URL}/marketing_brief_view.php?id=${briefId}`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_brief_view\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toContainText('Visual Brief');
    await expect(page.locator('.marketing-page-header')).toContainText('Campaign Brief Review');
    await expect(page.locator('.marketing-brief-view-summary')).toBeVisible();
    await expect(page.locator('.marketing-brief-review-card')).toHaveCount(5);
    await expect(page.locator('.marketing-brief-message-card')).toBeVisible();
    await expect(page.locator('.marketing-brief-view-today')).toBeVisible();
    await expect(page.locator('details.marketing-brief-view-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-brief-view-page [style]')).toHaveCount(0);
    await expect(page.locator('.marketing-detail-grid')).toHaveCount(0);
    await expect(page.locator('.marketing-cardlet')).toHaveCount(0);
    await expectMarketingBriefViewFits(page);

    const firstReviewCard = page.locator('.marketing-brief-review-card').first();
    await firstReviewCard.hover();
    await expectTooltipVisible(firstReviewCard);

    await page.locator('details.marketing-brief-view-tools summary').click();
    await expect(page.locator('.marketing-brief-view-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-brief-evidence-card')).toHaveCount(3);
    await expectMarketingBriefViewFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-brief-view-desktop.png'), fullPage: false });
  });

  test('mobile brief review stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    const briefId = await createCampaignBriefForReview(page);
    await page.goto(`${BASE_URL}/marketing_brief_view.php?id=${briefId}`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toContainText('Visual Brief');
    await expect(page.locator('.marketing-brief-view-summary')).toBeVisible();
    await expect(page.locator('.marketing-brief-review-card').first()).toBeVisible();
    await expect(page.locator('.marketing-brief-message-card')).toBeVisible();
    await expect(page.locator('details.marketing-brief-view-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-brief-view-page [style]')).toHaveCount(0);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingBriefViewFits(page);

    const firstCard = await page.locator('.marketing-brief-review-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-brief-view-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing campaign workspace visual maturity', () => {
  test('desktop campaign workspace is visual, uncluttered, and tooltip-led', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_campaign_workspace.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_campaign_workspace\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Campaign Workspace');
    await expect(page.locator('.marketing-campaign-workspace-summary')).toBeVisible();
    await expect(page.locator('.marketing-campaign-stage-card')).toHaveCount(6);
    await expect(page.locator('.marketing-campaign-workspace-layout')).toBeVisible();
    await expect(page.locator('.marketing-campaign-today')).toBeVisible();
    await expect(page.locator('details.marketing-campaign-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.campaign-workspace-row')).toHaveCount(0);
    await expect(page.locator('.marketing-campaign-workspace-page [style]')).toHaveCount(0);
    const campaignQueueCount = await page.locator('.marketing-campaign-queue-card').count();
    await expect(page.locator('progress.marketing-campaign-meter')).toHaveCount(campaignQueueCount);
    await expectMarketingCampaignWorkspaceFits(page);

    const actionCounts = await page.locator('.marketing-campaign-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-campaign-stage-action').length)
    );
    expect(actionCounts).toEqual([1, 1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-campaign-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-campaign-tools summary').click();
    await expect(page.locator('.marketing-campaign-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-campaign-tools .marketing-advanced-tools-grid a')).toHaveCount(8);
    await expectMarketingCampaignWorkspaceFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-campaign-workspace-desktop.png'), fullPage: false });
  });

  test('mobile campaign workspace stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_campaign_workspace.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Campaign Workspace');
    await expect(page.locator('.marketing-campaign-workspace-summary')).toBeVisible();
    await expect(page.locator('.marketing-campaign-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-campaign-today')).toBeVisible();
    await expect(page.locator('details.marketing-campaign-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-campaign-workspace-page [style]')).toHaveCount(0);
    const campaignQueueCount = await page.locator('.marketing-campaign-queue-card').count();
    await expect(page.locator('progress.marketing-campaign-meter')).toHaveCount(campaignQueueCount);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingCampaignWorkspaceFits(page);

    const firstCard = await page.locator('.marketing-campaign-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-campaign-workspace-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing audience activation visual maturity', () => {
  test('desktop audience activation is visual, low-text, and drawer-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_audience_activation.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_audience_activation\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Audience Activation');
    await expect(page.locator('.marketing-audience-summary')).toBeVisible();
    await expect(page.locator('.marketing-audience-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-audience-layout')).toBeVisible();
    await expect(page.locator('.marketing-audience-today')).toBeVisible();
    await expect(page.locator('details.marketing-audience-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.activation-row')).toHaveCount(0);
    await expect(page.locator('.marketing-audience-activation-page [style]')).toHaveCount(0);
    const activationCardCount = await page.locator('.marketing-audience-activation-card').count();
    await expect(page.locator('progress.marketing-audience-fit-meter')).toHaveCount(activationCardCount);
    await expectMarketingAudienceActivationFits(page);

    const actionCounts = await page.locator('.marketing-audience-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-audience-stage-action').length)
    );
    expect(actionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-audience-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-audience-tools summary').click();
    await expect(page.locator('.marketing-audience-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-audience-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expect(page.locator('.marketing-audience-filter-card')).toBeVisible();
    await expect(page.locator('.marketing-audience-map-card')).toBeVisible();
    await expectMarketingAudienceActivationFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-audience-activation-desktop.png'), fullPage: false });
  });

  test('mobile audience activation stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_audience_activation.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Audience Activation');
    await expect(page.locator('.marketing-audience-summary')).toBeVisible();
    await expect(page.locator('.marketing-audience-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-audience-today')).toBeVisible();
    await expect(page.locator('details.marketing-audience-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-audience-activation-page [style]')).toHaveCount(0);
    const activationCardCount = await page.locator('.marketing-audience-activation-card').count();
    await expect(page.locator('progress.marketing-audience-fit-meter')).toHaveCount(activationCardCount);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingAudienceActivationFits(page);

    const firstCard = await page.locator('.marketing-audience-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-audience-activation-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing journeys visual maturity', () => {
  test('desktop journey map is visual, low-text, and drawer-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_journeys.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_journeys\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Journey Map');
    await expect(page.locator('.marketing-page-header')).toContainText('Marketing Journeys');
    await expect(page.locator('.marketing-journey-summary')).toBeVisible();
    await expect(page.locator('.marketing-journey-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-journey-layout')).toBeVisible();
    await expect(page.locator('.marketing-journey-today')).toBeVisible();
    await expect(page.locator('details.marketing-journey-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.journey-row')).toHaveCount(0);
    await expect(page.locator('.marketing-journeys-page [style]')).toHaveCount(0);
    await expect(page.locator('.marketing-journey-score-card .setup-score-ring[data-score]')).toHaveCount(1);
    await expectMarketingJourneysFits(page);

    const actionCounts = await page.locator('.marketing-journey-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-journey-stage-action').length)
    );
    expect(actionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-journey-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-journey-tools summary').click();
    await expect(page.locator('.marketing-journey-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-journey-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expect(page.locator('.marketing-journey-filter-card')).toBeVisible();
    await expect(page.locator('.marketing-journey-cockpit-card')).toBeVisible();
    await expect(page.locator('.marketing-journey-cockpit-card')).toContainText('Audience Journey Cockpit');
    await expectMarketingJourneysFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-journeys-desktop.png'), fullPage: false });
  });

  test('mobile journey map stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_journeys.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Journey Map');
    await expect(page.locator('.marketing-journey-summary')).toBeVisible();
    await expect(page.locator('.marketing-journey-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-journey-today')).toBeVisible();
    await expect(page.locator('details.marketing-journey-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-journeys-page [style]')).toHaveCount(0);
    await expect(page.locator('.marketing-journey-score-card .setup-score-ring[data-score]')).toHaveCount(1);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingJourneysFits(page);

    const firstCard = await page.locator('.marketing-journey-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-journeys-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing journey builder visual maturity', () => {
  test('desktop journey builder is visual, focused, and drawer-led', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_journey_edit.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_journey_edit\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Journey Builder');
    await expect(page.locator('.marketing-page-header')).toContainText('Marketing Journey');
    await expect(page.locator('.marketing-journey-edit-summary')).toBeVisible();
    await expect(page.locator('.marketing-journey-builder-card')).toHaveCount(5);
    await expect(page.locator('.marketing-journey-core-fields')).toBeVisible();
    await expect(page.locator('.marketing-journey-step-card')).toHaveCount(8);
    await expect(page.locator('.marketing-journey-builder-today')).toBeVisible();
    await expect(page.locator('.journey-step-row')).toHaveCount(0);
    await expectMarketingJourneyBuilderFits(page);

    const actionCounts = await page.locator('.marketing-journey-builder-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-journey-builder-action').length)
    );
    expect(actionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstCard = page.locator('.marketing-journey-builder-card').first();
    await firstCard.hover();
    await expectTooltipVisible(firstCard);

    await page.locator('.marketing-journey-step-card details summary').first().click();
    await expect(page.locator('.marketing-journey-step-tools-body').first()).toBeVisible();
    await expect(page.locator('textarea[name="steps[0][condition_json]"]')).toBeVisible();
    await expectMarketingJourneyBuilderFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-journey-builder-desktop.png'), fullPage: false });
  });

  test('mobile journey builder stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_journey_edit.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Journey Builder');
    await expect(page.locator('.marketing-journey-edit-summary')).toBeVisible();
    await expect(page.locator('.marketing-journey-builder-card').first()).toBeVisible();
    await expect(page.locator('.marketing-journey-core-fields')).toBeVisible();
    await expect(page.locator('.marketing-journey-builder-today')).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingJourneyBuilderFits(page);

    const firstCard = await page.locator('.marketing-journey-builder-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-journey-builder-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing journey review visual maturity', () => {
  test('desktop journey review is visual, low-text, and evidence-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await createJourneyForReview(page);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_journey_view\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header')).toContainText('Journey Readiness');
    await expect(page.locator('.marketing-journey-view-summary')).toBeVisible();
    await expect(page.locator('.marketing-journey-review-card')).toHaveCount(5);
    await expect(page.locator('.marketing-journey-readiness-card')).toBeVisible();
    await expect(page.locator('.marketing-journey-timeline-step').first()).toBeVisible();
    await expect(page.locator('.marketing-journey-view-today')).toBeVisible();
    await expect(page.locator('details.marketing-journey-view-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.journey-detail-grid')).toHaveCount(0);
    await expect(page.locator('.timeline-step')).toHaveCount(0);
    await expectMarketingJourneyReviewFits(page);

    const actionCounts = await page.locator('.marketing-journey-review-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-journey-review-action').length)
    );
    expect(actionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstCard = page.locator('.marketing-journey-review-card').first();
    await firstCard.hover();
    await expectTooltipVisible(firstCard);

    await page.locator('details.marketing-journey-view-tools summary').click();
    await expect(page.locator('.marketing-journey-view-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-journey-drafts-card')).toBeVisible();
    await expect(page.locator('.marketing-journey-checks-card')).toBeVisible();
    await expectMarketingJourneyReviewFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-journey-review-desktop.png'), fullPage: false });
  });

  test('mobile journey review stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await createJourneyForReview(page);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header')).toContainText('Journey Readiness');
    await expect(page.locator('.marketing-journey-view-summary')).toBeVisible();
    await expect(page.locator('.marketing-journey-review-card').first()).toBeVisible();
    await expect(page.locator('.marketing-journey-timeline-step').first()).toBeVisible();
    await expect(page.locator('.marketing-journey-view-today')).toBeVisible();
    await expect(page.locator('details.marketing-journey-view-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingJourneyReviewFits(page);

    const firstCard = await page.locator('.marketing-journey-review-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-journey-review-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing playbooks visual maturity', () => {
  test('desktop playbook library is visual, low-text, and drawer-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await createPlaybookForLibrary(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_playbooks\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Playbook Library');
    await expect(page.locator('.marketing-page-header')).toContainText('Campaign Playbooks');
    await expect(page.locator('.marketing-playbooks-summary')).toBeVisible();
    await expect(page.locator('.marketing-playbook-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-playbooks-layout')).toBeVisible();
    await expect(page.locator('.marketing-playbook-board')).toBeVisible();
    await expect(page.locator('.marketing-playbook-card').first()).toBeVisible();
    await expect(page.locator('.marketing-playbooks-today')).toBeVisible();
    await expect(page.locator('details.marketing-playbook-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.playbook-row')).toHaveCount(0);
    await expect(page.locator('.marketing-playbooks-page [style]')).toHaveCount(0);
    await expect(page.locator('.marketing-playbook-score-card .setup-score-ring[data-score]')).toHaveCount(1);
    await expectMarketingPlaybooksFits(page);

    const stageActionCounts = await page.locator('.marketing-playbook-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-playbook-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-playbook-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-playbook-tools summary').click();
    await expect(page.locator('.marketing-playbook-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-playbook-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expect(page.locator('.marketing-playbook-template-card')).toBeVisible();
    await expect(page.locator('.marketing-playbook-filter-card')).toBeVisible();
    await expectMarketingPlaybooksFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-playbooks-desktop.png'), fullPage: false });
  });

  test('mobile playbook library stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await createPlaybookForLibrary(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Playbook Library');
    await expect(page.locator('.marketing-playbooks-summary')).toBeVisible();
    await expect(page.locator('.marketing-playbook-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-playbook-board')).toBeVisible();
    await expect(page.locator('.marketing-playbook-card').first()).toBeVisible();
    await expect(page.locator('.marketing-playbooks-today')).toBeVisible();
    await expect(page.locator('details.marketing-playbook-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-playbooks-page [style]')).toHaveCount(0);
    await expect(page.locator('.marketing-playbook-score-card .setup-score-ring[data-score]')).toHaveCount(1);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingPlaybooksFits(page);

    const firstCard = await page.locator('.marketing-playbook-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-playbooks-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing playbook builder visual maturity', () => {
  test('desktop playbook builder is visual, focused, and drawer-led', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_playbook_edit.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_playbook_edit\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Playbook Builder');
    await expect(page.locator('.marketing-page-header')).toContainText('Campaign Playbook');
    await expect(page.locator('.marketing-playbook-edit-summary')).toBeVisible();
    await expect(page.locator('.marketing-playbook-builder-card')).toHaveCount(5);
    await expect(page.locator('.marketing-playbook-core-fields')).toBeVisible();
    await expect(page.locator('.marketing-playbook-foundation-fields')).toBeVisible();
    await expect(page.locator('.marketing-playbook-structure-fields')).toBeVisible();
    await expect(page.locator('.marketing-playbook-builder-today')).toBeVisible();
    await expect(page.locator('details.marketing-playbook-structure-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-form-grid')).toHaveCount(0);
    await expectMarketingPlaybookBuilderFits(page);

    const actionCounts = await page.locator('.marketing-playbook-builder-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-playbook-builder-action').length)
    );
    expect(actionCounts).toEqual([1, 1, 1, 1, 1]);

    await page.fill('input[name="name"]', `Visual Playbook ${Date.now()}`);
    await expect(page.locator('input[name="name"]')).toHaveValue(/Visual Playbook/);

    const firstCard = page.locator('.marketing-playbook-builder-card').first();
    await firstCard.hover();
    await expectTooltipVisible(firstCard);

    await page.locator('details.marketing-playbook-structure-tools summary').click();
    await expect(page.locator('.marketing-playbook-structure-tools-body')).toBeVisible();
    await expect(page.locator('textarea[name="stages"]')).toBeVisible();
    await expect(page.locator('textarea[name="success_metrics"]')).toBeVisible();
    await expectMarketingPlaybookBuilderFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-playbook-builder-desktop.png'), fullPage: false });
  });

  test('mobile playbook builder stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_playbook_edit.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Playbook Builder');
    await expect(page.locator('.marketing-playbook-edit-summary')).toBeVisible();
    await expect(page.locator('.marketing-playbook-builder-card').first()).toBeVisible();
    await expect(page.locator('.marketing-playbook-core-fields')).toBeVisible();
    await expect(page.locator('.marketing-playbook-builder-today')).toBeVisible();
    await expect(page.locator('details.marketing-playbook-structure-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingPlaybookBuilderFits(page);

    const firstCard = await page.locator('.marketing-playbook-builder-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-playbook-builder-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing playbook view visual maturity', () => {
  test('desktop playbook view is visual, focused, and drawer-led', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    const playbookId = await createPlaybookForLibrary(page);
    await page.goto(`${BASE_URL}/marketing_playbook_view.php?id=${playbookId}`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_playbook_view\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toBeVisible();
    await expect(page.locator('.marketing-page-header')).toContainText('Campaign Playbook');
    await expect(page.locator('.marketing-playbook-view-summary')).toBeVisible();
    await expect(page.locator('.marketing-playbook-view-layout')).toBeVisible();
    await expect(page.locator('.marketing-playbook-view-board')).toBeVisible();
    await expect(page.locator('.marketing-playbook-view-card')).toHaveCount(6);
    await expect(page.locator('.marketing-playbook-view-today')).toBeVisible();
    await expect(page.locator('details.marketing-playbook-view-tools')).toHaveCount(3);
    await expect(page.locator('.playbook-detail')).toHaveCount(0);
    await expectMarketingPlaybookViewFits(page);

    const actionCounts = await page.locator('.marketing-playbook-view-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-playbook-view-card-action').length)
    );
    expect(actionCounts).toEqual([1, 1, 1, 1, 1, 1]);

    const todayCount = await page.locator('.marketing-playbook-view-today-action').count();
    expect(todayCount).toBeGreaterThan(0);
    expect(todayCount).toBeLessThanOrEqual(5);

    const allDetailsClosed = await page.locator('details.marketing-playbook-view-tools').evaluateAll((details) =>
      details.every((detail) => !detail.hasAttribute('open'))
    );
    expect(allDetailsClosed).toBe(true);

    const firstCard = page.locator('.marketing-playbook-view-card').first();
    await firstCard.hover();
    await expectTooltipVisible(firstCard);

    await page.getByRole('link', { name: 'Review stages' }).click();
    await expect(page.locator('#playbook-evidence')).toHaveAttribute('open', '');
    await expect(page.locator('#playbook-evidence .marketing-playbook-view-tools-body')).toBeVisible();
    await expect(page.locator('#playbook-stages')).toBeVisible();
    await expect(page.locator('#playbook-briefs')).toBeVisible();
    await expect(page.locator('#playbook-runs')).toBeVisible();

    await page.locator('#playbook-actions summary').click();
    await expect(page.locator('#playbook-actions')).toContainText('Create Brief');
    await expect(page.locator('#playbook-actions')).toContainText('Apply Campaign Kit');
    await expectMarketingPlaybookViewFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.querySelectorAll('details[open]').forEach((detail) => detail.removeAttribute('open'));
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-playbook-view-desktop.png'), fullPage: false });
  });

  test('mobile playbook view stacks cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    const playbookId = await createPlaybookForLibrary(page);
    await page.goto(`${BASE_URL}/marketing_playbook_view.php?id=${playbookId}`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toBeVisible();
    await expect(page.locator('.marketing-playbook-view-summary')).toBeVisible();
    await expect(page.locator('.marketing-playbook-view-card').first()).toBeVisible();
    await expect(page.locator('.marketing-playbook-view-board')).toBeVisible();
    await expect(page.locator('.marketing-playbook-view-today')).toBeVisible();
    await expect(page.locator('details.marketing-playbook-view-tools')).toHaveCount(3);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingPlaybookViewFits(page);

    const firstCard = await page.locator('.marketing-playbook-view-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.locator('#playbook-evidence summary').click();
    await expect(page.locator('#playbook-stages')).toBeVisible();
    await expectMarketingPlaybookViewFits(page);

    await page.mouse.move(1, 1);
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-playbook-view-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing roadmap visual maturity', () => {
  test('desktop roadmap is a visual launch map with drawer-gated tools', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_roadmap.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_roadmap\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Campaign Roadmap');
    await expect(page.locator('.marketing-page-header')).toContainText('Launch Map');
    await expect(page.locator('.marketing-roadmap-summary')).toBeVisible();
    await expect(page.locator('.marketing-roadmap-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-roadmap-layout')).toBeVisible();
    await expect(page.locator('.marketing-roadmap-board')).toBeVisible();
    await expect(page.locator('.marketing-roadmap-today')).toBeVisible();
    await expect(page.locator('details.marketing-roadmap-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.roadmap-item')).toHaveCount(0);
    await expect(page.locator('.roadmap-filters')).toHaveCount(0);
    await expect(page.locator('.marketing-roadmap-page [style]')).toHaveCount(0);
    const roadmapCardCount = await page.locator('.marketing-roadmap-card').count();
    await expect(page.locator('progress.marketing-roadmap-meter')).toHaveCount(roadmapCardCount);
    await expectMarketingRoadmapFits(page);

    const stageActionCounts = await page.locator('.marketing-roadmap-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-roadmap-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-roadmap-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-roadmap-tools summary').click();
    await expect(page.locator('.marketing-roadmap-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-roadmap-filter-card')).toBeVisible();
    await expect(page.locator('.marketing-roadmap-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expectMarketingRoadmapFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-roadmap-desktop.png'), fullPage: false });
  });

  test('mobile roadmap stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_roadmap.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Campaign Roadmap');
    await expect(page.locator('.marketing-roadmap-summary')).toBeVisible();
    await expect(page.locator('.marketing-roadmap-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-roadmap-board')).toBeVisible();
    await expect(page.locator('.marketing-roadmap-today')).toBeVisible();
    await expect(page.locator('details.marketing-roadmap-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-roadmap-page [style]')).toHaveCount(0);
    const roadmapCardCount = await page.locator('.marketing-roadmap-card').count();
    await expect(page.locator('progress.marketing-roadmap-meter')).toHaveCount(roadmapCardCount);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingRoadmapFits(page);

    const firstCard = await page.locator('.marketing-roadmap-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-roadmap-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing persona offer matrix visual maturity', () => {
  test('desktop persona offer matrix is a visual fit map with drawer-gated tools', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_persona_offer_matrix.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_persona_offer_matrix\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Persona Offer Matrix');
    await expect(page.locator('.marketing-page-header')).toContainText('Offer Fit');
    await expect(page.locator('.marketing-persona-offer-summary')).toBeVisible();
    await expect(page.locator('.marketing-persona-offer-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-persona-offer-layout')).toBeVisible();
    await expect(page.locator('.marketing-persona-offer-board')).toBeVisible();
    await expect(page.locator('.marketing-persona-offer-today')).toBeVisible();
    await expect(page.locator('details.marketing-persona-offer-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.matrix-table')).toHaveCount(0);
    await expectMarketingPersonaOfferFits(page);

    const stageActionCounts = await page.locator('.marketing-persona-offer-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-persona-offer-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-persona-offer-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-persona-offer-tools summary').click();
    await expect(page.locator('.marketing-persona-offer-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-persona-offer-tool-card')).toBeVisible();
    await expect(page.locator('.marketing-persona-offer-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expectMarketingPersonaOfferFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-persona-offer-desktop.png'), fullPage: false });
  });

  test('mobile persona offer matrix stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_persona_offer_matrix.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Persona Offer Matrix');
    await expect(page.locator('.marketing-persona-offer-summary')).toBeVisible();
    await expect(page.locator('.marketing-persona-offer-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-persona-offer-board')).toBeVisible();
    await expect(page.locator('.marketing-persona-offer-today')).toBeVisible();
    await expect(page.locator('details.marketing-persona-offer-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingPersonaOfferFits(page);

    const firstCard = await page.locator('.marketing-persona-offer-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-persona-offer-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing calendar visual maturity', () => {
  test('desktop calendar is a visual schedule board with drawer-gated tools', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_calendar.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_calendar\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Calendar');
    await expect(page.locator('.marketing-page-header')).toContainText('Schedule Board');
    await expect(page.locator('.marketing-calendar-summary')).toBeVisible();
    await expect(page.locator('.marketing-calendar-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-calendar-layout')).toBeVisible();
    await expect(page.locator('.marketing-calendar-board')).toBeVisible();
    await expect(page.locator('.marketing-calendar-board')).toContainText('Editorial Calendar Plan');
    await expect(page.locator('.marketing-calendar-today')).toBeVisible();
    await expect(page.locator('details.marketing-calendar-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-calendar-grid')).toHaveCount(0);
    await expect(page.locator('.marketing-stats')).toHaveCount(0);
    await expectMarketingCalendarFits(page);

    const stageActionCounts = await page.locator('.marketing-calendar-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-calendar-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-calendar-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-calendar-tools > summary').click();
    await expect(page.locator('.marketing-calendar-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-calendar-filter-card')).toBeVisible();
    await expect(page.locator('.marketing-calendar-form-card')).toBeVisible();
    await expect(page.locator('.marketing-calendar-template-card')).toContainText('Calendar Templates');
    await expect(page.locator('.marketing-calendar-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expectMarketingCalendarFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-calendar-desktop.png'), fullPage: false });
  });

  test('mobile calendar stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_calendar.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Calendar');
    await expect(page.locator('.marketing-calendar-summary')).toBeVisible();
    await expect(page.locator('.marketing-calendar-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-calendar-board')).toBeVisible();
    await expect(page.locator('.marketing-calendar-today')).toBeVisible();
    await expect(page.locator('details.marketing-calendar-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingCalendarFits(page);

    const firstCard = await page.locator('.marketing-calendar-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-calendar-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing reviews visual maturity', () => {
  test('desktop reviews are a visual approval board with drawer-gated evidence', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_reviews.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_reviews\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Approval Workbench');
    await expect(page.locator('.marketing-page-header')).toContainText('Review Board');
    await expect(page.locator('.marketing-reviews-summary')).toBeVisible();
    await expect(page.locator('.marketing-reviews-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-reviews-layout')).toBeVisible();
    await expect(page.locator('.marketing-reviews-board')).toBeVisible();
    await expect(page.locator('.marketing-reviews-today')).toBeVisible();
    await expect(page.locator('details.marketing-reviews-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.queue-tabs')).toHaveCount(0);
    await expect(page.locator('.workflow-closure')).toHaveCount(0);
    await expectMarketingReviewsFits(page);

    const stageActionCounts = await page.locator('.marketing-reviews-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-reviews-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-reviews-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-reviews-tools > summary').click();
    await expect(page.locator('.marketing-reviews-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-reviews-queue-card')).toBeVisible();
    await expect(page.locator('.marketing-reviews-closure-card')).toContainText('Workflow Closure Board');
    await expect(page.locator('.marketing-reviews-closure-card')).toContainText('Blocked Reasons');
    await expect(page.locator('.marketing-reviews-workflow-card')).toContainText('Review next steps');
    await expect(page.locator('.marketing-reviews-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expectMarketingReviewsFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-reviews-desktop.png'), fullPage: false });
  });

  test('mobile reviews stack without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_reviews.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Approval Workbench');
    await expect(page.locator('.marketing-reviews-summary')).toBeVisible();
    await expect(page.locator('.marketing-reviews-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-reviews-board')).toBeVisible();
    await expect(page.locator('.marketing-reviews-today')).toBeVisible();
    await expect(page.locator('details.marketing-reviews-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingReviewsFits(page);

    const firstCard = await page.locator('.marketing-reviews-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-reviews-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing launch readiness visual maturity', () => {
  test('desktop launch readiness is a visual launch board with drawer-gated tools', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_launch_readiness.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_launch_readiness\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Launch Readiness');
    await expect(page.locator('.marketing-page-header')).toContainText('Launch Board');
    await expect(page.locator('.marketing-launch-readiness-summary')).toBeVisible();
    await expect(page.locator('.marketing-launch-readiness-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-launch-readiness-layout')).toBeVisible();
    await expect(page.locator('.marketing-launch-readiness-board')).toBeVisible();
    await expect(page.locator('.marketing-launch-readiness-today')).toBeVisible();
    await expect(page.locator('details.marketing-launch-readiness-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.launch-grid')).toHaveCount(0);
    await expect(page.locator('.launch-kpis')).toHaveCount(0);
    await expectMarketingLaunchReadinessFits(page);

    const stageActionCounts = await page.locator('.marketing-launch-readiness-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-launch-readiness-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-launch-readiness-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-launch-readiness-tools > summary').click();
    await expect(page.locator('.marketing-launch-readiness-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-launch-readiness-filter-card')).toBeVisible();
    await expect(page.locator('.marketing-launch-readiness-form-card')).toContainText('Evaluate Launch');
    await expect(page.locator('.marketing-launch-readiness-template-card')).toContainText('Templates');
    await expect(page.locator('.marketing-launch-readiness-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expectMarketingLaunchReadinessFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-launch-readiness-desktop.png'), fullPage: false });
  });

  test('mobile launch readiness stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_launch_readiness.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Launch Readiness');
    await expect(page.locator('.marketing-launch-readiness-summary')).toBeVisible();
    await expect(page.locator('.marketing-launch-readiness-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-launch-readiness-board')).toBeVisible();
    await expect(page.locator('.marketing-launch-readiness-today')).toBeVisible();
    await expect(page.locator('details.marketing-launch-readiness-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingLaunchReadinessFits(page);

    const firstCard = await page.locator('.marketing-launch-readiness-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-launch-readiness-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing launch control visual maturity', () => {
  test('desktop launch control is a visual control board with drawer-gated tools', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_launch_control.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_launch_control\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Launch Control Room');
    await expect(page.locator('.marketing-page-header')).toContainText('Control Board');
    await expect(page.locator('.marketing-launch-control-summary')).toBeVisible();
    await expect(page.locator('.marketing-launch-control-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-launch-control-layout')).toBeVisible();
    await expect(page.locator('.marketing-launch-control-board')).toBeVisible();
    await expect(page.locator('.marketing-launch-control-today')).toBeVisible();
    await expect(page.locator('details.marketing-launch-control-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.control-grid')).toHaveCount(0);
    await expect(page.locator('.control-kpis')).toHaveCount(0);
    await expectMarketingLaunchControlFits(page);

    const stageActionCounts = await page.locator('.marketing-launch-control-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-launch-control-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-launch-control-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-launch-control-tools > summary').click();
    await expect(page.locator('.marketing-launch-control-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-launch-control-filter-card')).toBeVisible();
    await expect(page.locator('.marketing-launch-control-form-card')).toContainText('Prepare Launch');
    await expect(page.locator('.marketing-launch-control-manual-card')).toContainText('Manual-first Safeguard');
    await expect(page.locator('.marketing-launch-control-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expectMarketingLaunchControlFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-launch-control-desktop.png'), fullPage: false });
  });

  test('mobile launch control stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_launch_control.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Launch Control Room');
    await expect(page.locator('.marketing-launch-control-summary')).toBeVisible();
    await expect(page.locator('.marketing-launch-control-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-launch-control-board')).toBeVisible();
    await expect(page.locator('.marketing-launch-control-today')).toBeVisible();
    await expect(page.locator('details.marketing-launch-control-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingLaunchControlFits(page);

    const firstCard = await page.locator('.marketing-launch-control-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-launch-control-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing launch checklists visual maturity', () => {
  test('desktop launch checklists are a visual checklist board with drawer-gated tools', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_launch_checklists.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_launch_checklists\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Campaign Launch Checklists');
    await expect(page.locator('.marketing-page-header')).toContainText('Checklist Board');
    await expect(page.locator('.marketing-launch-checklists-summary')).toBeVisible();
    await expect(page.locator('.marketing-launch-checklists-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-launch-checklists-layout')).toBeVisible();
    await expect(page.locator('.marketing-launch-checklists-board')).toBeVisible();
    await expect(page.locator('.marketing-launch-checklists-today')).toBeVisible();
    await expect(page.locator('details.marketing-launch-checklists-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.launch-checklist-grid')).toHaveCount(0);
    await expect(page.locator('.launch-checklist-row')).toHaveCount(0);
    await expectMarketingLaunchChecklistsFits(page);

    const stageActionCounts = await page.locator('.marketing-launch-checklists-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-launch-checklists-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-launch-checklists-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-launch-checklists-tools > summary').click();
    await expect(page.locator('.marketing-launch-checklists-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-launch-checklists-filter-card')).toBeVisible();
    await expect(page.locator('.marketing-launch-checklists-form-card')).toContainText('Create Checklist');
    await expect(page.locator('.marketing-launch-checklists-manual-card')).toContainText('Manual-first Safeguard');
    await expect(page.locator('.marketing-launch-checklists-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expectMarketingLaunchChecklistsFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-launch-checklists-desktop.png'), fullPage: false });
  });

  test('mobile launch checklists stack without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_launch_checklists.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Campaign Launch Checklists');
    await expect(page.locator('.marketing-launch-checklists-summary')).toBeVisible();
    await expect(page.locator('.marketing-launch-checklists-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-launch-checklists-board')).toBeVisible();
    await expect(page.locator('.marketing-launch-checklists-today')).toBeVisible();
    await expect(page.locator('details.marketing-launch-checklists-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingLaunchChecklistsFits(page);

    const firstCard = await page.locator('.marketing-launch-checklists-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-launch-checklists-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing guided workflows visual maturity', () => {
  test('desktop guided workflow board is visual, low-text, and drawer-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_guided_workflows.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_guided_workflows\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Guided Workflows');
    await expect(page.locator('.marketing-page-header')).toContainText('Workflow Board');
    await expect(page.locator('.marketing-guided-workflows-summary')).toBeVisible();
    await expect(page.locator('.marketing-guided-workflows-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-guided-workflows-layout')).toBeVisible();
    await expect(page.locator('.marketing-guided-workflows-board')).toBeVisible();
    await expect(page.locator('.marketing-guided-workflows-today')).toBeVisible();
    await expect(page.locator('details.marketing-guided-workflows-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.workflow-grid')).toHaveCount(0);
    await expect(page.locator('.workflow-kpis')).toHaveCount(0);
    await expect(page.locator('.marketing-guided-workflows-page [style]')).toHaveCount(0);
    const workflowCardCount = await page.locator('.marketing-guided-workflows-card').count();
    await expect(page.locator('progress.marketing-guided-workflows-progress')).toHaveCount(workflowCardCount);
    await expectMarketingGuidedWorkflowsFits(page);

    const stageActionCounts = await page.locator('.marketing-guided-workflows-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-guided-workflows-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-guided-workflows-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-guided-workflows-tools > summary').click();
    await expect(page.locator('.marketing-guided-workflows-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-guided-workflows-wizard-card')).toContainText('Workflow Wizards');
    await expect(page.locator('.marketing-guided-workflows-filter-card')).toBeVisible();
    await expect(page.locator('.marketing-guided-workflows-manual-card')).toContainText('Manual-first Safeguard');
    await expect(page.locator('.marketing-guided-workflows-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expectMarketingGuidedWorkflowsFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-guided-workflows-desktop.png'), fullPage: false });
  });

  test('mobile guided workflow board stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_guided_workflows.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Guided Workflows');
    await expect(page.locator('.marketing-guided-workflows-summary')).toBeVisible();
    await expect(page.locator('.marketing-guided-workflows-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-guided-workflows-board')).toBeVisible();
    await expect(page.locator('.marketing-guided-workflows-today')).toBeVisible();
    await expect(page.locator('details.marketing-guided-workflows-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-guided-workflows-page [style]')).toHaveCount(0);
    const workflowCardCount = await page.locator('.marketing-guided-workflows-card').count();
    await expect(page.locator('progress.marketing-guided-workflows-progress')).toHaveCount(workflowCardCount);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingGuidedWorkflowsFits(page);

    const firstCard = await page.locator('.marketing-guided-workflows-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-guided-workflows-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing task hub visual maturity', () => {
  test('desktop task hub is a visual task board with drawer-gated tools', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_task_hub.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_task_hub\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Task Hub');
    await expect(page.locator('.marketing-page-header')).toContainText('Task Board');
    await expect(page.locator('.marketing-task-hub-summary')).toBeVisible();
    await expect(page.locator('.marketing-task-hub-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-task-hub-layout')).toBeVisible();
    await expect(page.locator('.marketing-task-hub-board')).toBeVisible();
    await expect(page.locator('.marketing-task-hub-today')).toBeVisible();
    await expect(page.locator('details.marketing-task-hub-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.task-kpis')).toHaveCount(0);
    await expect(page.locator('.task-row')).toHaveCount(0);
    await expectMarketingTaskHubFits(page);

    const stageActionCounts = await page.locator('.marketing-task-hub-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-task-hub-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-task-hub-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-task-hub-tools > summary').click();
    await expect(page.locator('.marketing-task-hub-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-task-hub-filter-card')).toContainText('Queue Filter');
    await expect(page.locator('.marketing-task-hub-recent-card')).toContainText('Recent Task Actions');
    await expect(page.locator('.marketing-task-hub-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expectMarketingTaskHubFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-task-hub-desktop.png'), fullPage: false });
  });

  test('mobile task hub stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_task_hub.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Task Hub');
    await expect(page.locator('.marketing-task-hub-summary')).toBeVisible();
    await expect(page.locator('.marketing-task-hub-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-task-hub-board')).toBeVisible();
    await expect(page.locator('.marketing-task-hub-today')).toBeVisible();
    await expect(page.locator('details.marketing-task-hub-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingTaskHubFits(page);

    const firstCard = await page.locator('.marketing-task-hub-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-task-hub-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing assets visual maturity', () => {
  test('desktop asset library leads while reporting stays in a compact side disclosure', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_assets.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_assets\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Assets');
    await expect(page.locator('.marketing-page-header')).toContainText('Browse and manage reusable campaign files.');
    await expect(page.locator('.marketing-assets-layout')).toBeVisible();
    await expect(page.locator('.marketing-assets-library')).toBeVisible();
    await expect(page.locator('.marketing-assets-library')).toContainText('Asset Library');
    await expect(page.locator('.marketing-assets-media-card').first()).toBeVisible();
    await expect(page.locator('.marketing-assets-overview')).toBeVisible();
    await expect(page.locator('.marketing-assets-summary')).toBeVisible();
    await expect(page.locator('.marketing-assets-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-assets-today')).toBeVisible();
    await expect(page.locator('details.marketing-assets-reporting')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-assets-board')).toBeHidden();
    await expect(page.locator('.marketing-assets-stage-card').first()).toBeHidden();
    await expect(page.locator('[data-asset-workspace-toggle]')).toContainText('Asset tools');
    await expect(page.locator('[data-asset-workspace-toggle]')).toHaveAttribute('href', '#media-tools');
    await expect(page.locator('.marketing-assets-tool-hub')).toBeHidden();
    await expect(page.locator('[data-asset-tool-tab]')).toHaveCount(6);
    await expect(page.locator('#asset-tool-tab-add')).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#asset-tool-panel-add')).toBeHidden();
    await expect(page.locator('#asset-tool-panel-readiness')).toBeHidden();
    await expect(page.locator('details.marketing-assets-tools')).toHaveCount(0);
    await expect(page.locator('.marketing-row')).toHaveCount(0);
    await expect(page.locator('.media-card')).toHaveCount(0);
    await expect(page.locator('.media-library')).toHaveCount(0);
    await expect(page.locator('.media-ops-grid')).toHaveCount(0);
    await expectMarketingAssetsFits(page);

    const firstViewportOrder = await page.evaluate(() => {
      const header = document.querySelector('.marketing-page-header').getBoundingClientRect();
      const library = document.querySelector('.marketing-assets-library').getBoundingClientRect();
      const firstAsset = document.querySelector('.marketing-assets-media-card').getBoundingClientRect();
      const reporting = document.querySelector('.marketing-assets-reporting').getBoundingClientRect();
      const nextUp = document.querySelector('.marketing-assets-today').getBoundingClientRect();
      return {
        headerBottom: header.bottom,
        libraryTop: library.top,
        firstAssetTop: firstAsset.top,
        reportingTop: reporting.top,
        reportingBottom: reporting.bottom,
        nextUpTop: nextUp.top,
      };
    });
    expect(firstViewportOrder.libraryTop).toBeGreaterThanOrEqual(firstViewportOrder.headerBottom);
    expect(firstViewportOrder.firstAssetTop).toBeLessThan(620);
    expect(firstViewportOrder.reportingTop).toBeLessThan(firstViewportOrder.nextUpTop);
    expect(firstViewportOrder.reportingBottom).toBeLessThanOrEqual(720);

    await page.locator('details.marketing-assets-reporting > summary').click();
    await expect(page.locator('.marketing-assets-board')).toBeVisible();
    const firstStage = page.locator('.marketing-assets-stage-card').first();
    await expect(firstStage).toBeVisible();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);
    await page.locator('details.marketing-assets-reporting > summary').click();

    await page.locator('[data-asset-workspace-toggle]').click();
    await expect(page.locator('.marketing-assets-layout')).toBeHidden();
    await expect(page.locator('.marketing-assets-library')).toBeHidden();
    await expect(page.locator('.marketing-assets-sidebar')).toBeHidden();
    await expect(page.locator('.marketing-assets-tool-hub')).toBeVisible();
    await expect(page.locator('[data-asset-workspace-toggle]')).toContainText('View library');
    await expect(page.locator('[data-asset-workspace-toggle]')).toHaveAttribute('href', '#media-library');
    await expect(page.locator('#asset-tool-panel-add')).toBeVisible();

    await page.locator('#asset-tool-tab-add').press('ArrowDown');
    await expect(page.locator('#asset-tool-tab-readiness')).toHaveAttribute('aria-selected', 'true');
    await expect(page).toHaveURL(/#media-readiness$/);
    await expect(page.locator('#asset-tool-panel-readiness')).toBeVisible();
    await expect(page.locator('#asset-tool-panel-add')).toBeHidden();
    await expect(page.locator('.marketing-assets-readiness-card')).toContainText('Media Operational Readiness');

    await page.locator('#asset-tool-tab-usage').click();
    await expect(page.locator('#asset-tool-panel-usage')).toBeVisible();
    await expect(page.locator('.marketing-assets-usage-card')).toContainText('Media Usage Map');

    await page.locator('#asset-tool-tab-ai').click();
    await expect(page.locator('#asset-tool-panel-ai')).toBeVisible();
    await expect(page.locator('.marketing-assets-create-ai-card')).toContainText('Generate AI Media Brief');
    await expect(page.locator('.marketing-assets-ai-card')).not.toHaveAttribute('open', '');

    await page.locator('#asset-tool-tab-records').click();
    await expect(page.locator('#asset-tool-panel-records')).toBeVisible();
    await expect(page.locator('.marketing-assets-add-asset-card')).toContainText('Add Asset Record');
    await expect(page.locator('.marketing-assets-asset-list-card')).not.toHaveAttribute('open', '');

    await page.locator('#asset-tool-tab-connections').click();
    await expect(page.locator('#asset-tool-panel-connections')).toBeVisible();
    await expect(page.locator('.marketing-assets-tool-hub .marketing-advanced-tools-grid a')).toHaveCount(6);

    await page.locator('#asset-tool-tab-add').click();
    await expect(page.locator('#asset-tool-panel-add')).toBeVisible();
    await expect(page.locator('.marketing-assets-add-media-card')).toContainText('Add Media');
    await expect(page).toHaveURL(/#add-media$/);
    await expectMarketingAssetsFits(page);

    await page.locator('[data-asset-library-link]').click();
    await expect(page.locator('.marketing-assets-layout')).toBeVisible();
    await expect(page.locator('.marketing-assets-library')).toBeVisible();
    await expect(page.locator('.marketing-assets-tool-hub')).toBeHidden();
    await expect(page.locator('[data-asset-workspace-toggle]')).toContainText('Asset tools');

    await page.evaluate(() => { window.location.hash = 'media-readiness'; });
    await expect(page.locator('.marketing-assets-layout')).toBeHidden();
    await expect(page.locator('.marketing-assets-tool-hub')).toBeVisible();
    await expect(page.locator('#asset-tool-panel-readiness')).toBeVisible();
    await page.locator('[data-asset-library-link]').click();
    await expect(page.locator('.marketing-assets-layout')).toBeVisible();
    await expect(page.locator('.marketing-assets-tool-hub')).toBeHidden();

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-assets-desktop.png'), fullPage: false });
  });

  test('mobile asset library appears before stacked reporting controls', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_assets.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Assets');
    await expect(page.locator('.marketing-assets-library')).toBeVisible();
    await expect(page.locator('.marketing-assets-library')).toContainText('Asset Library');
    await expect(page.locator('.marketing-assets-media-card').first()).toBeVisible();
    await expect(page.locator('.marketing-assets-overview')).toBeVisible();
    await expect(page.locator('.marketing-assets-summary')).toBeVisible();
    await expect(page.locator('.marketing-assets-today')).toBeVisible();
    await expect(page.locator('details.marketing-assets-reporting')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-assets-stage-card').first()).toBeHidden();
    await expect(page.locator('.marketing-assets-board')).toBeHidden();
    await expect(page.locator('[data-asset-workspace-toggle]')).toContainText('Asset tools');
    await expect(page.locator('.marketing-assets-tool-hub')).toBeHidden();
    await expect(page.locator('[data-asset-tool-tab]')).toHaveCount(6);
    await expect(page.locator('.marketing-assets-tool-tabs')).toHaveAttribute('aria-orientation', 'horizontal');
    await expect(page.locator('#asset-tool-tab-add')).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#asset-tool-panel-add')).toBeHidden();
    await expect(page.locator('#asset-tool-panel-usage')).toBeHidden();
    await expect(page.locator('details.marketing-assets-tools')).toHaveCount(0);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingAssetsFits(page);

    const firstCard = await page.locator('.marketing-assets-media-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    const sourceOrder = await page.evaluate(() => {
      const library = document.querySelector('.marketing-assets-library');
      const overview = document.querySelector('.marketing-assets-overview');
      return library.compareDocumentPosition(overview) & Node.DOCUMENT_POSITION_FOLLOWING;
    });
    expect(sourceOrder).toBeTruthy();

    await page.locator('details.marketing-assets-reporting > summary').click();
    await expect(page.locator('.marketing-assets-board')).toBeVisible();
    await page.locator('details.marketing-assets-reporting > summary').click();

    await page.locator('[data-asset-workspace-toggle]').click();
    await expect(page.locator('.marketing-assets-layout')).toBeHidden();
    await expect(page.locator('.marketing-assets-tool-hub')).toBeVisible();
    await expect(page.locator('#asset-tool-panel-add')).toBeVisible();
    await expect(page.locator('.marketing-assets-tool-tabs')).toHaveAttribute('aria-orientation', 'horizontal');

    await page.locator('#asset-tool-tab-usage').click();
    await expect(page.locator('#asset-tool-panel-usage')).toBeVisible();
    await expect(page.locator('#asset-tool-panel-add')).toBeHidden();
    await page.locator('#asset-tool-tab-add').click();
    await expect(page.locator('#asset-tool-panel-add')).toBeVisible();
    await expectMarketingAssetsFits(page);

    await page.locator('[data-asset-library-link]').click();
    await expect(page.locator('.marketing-assets-layout')).toBeVisible();
    await expect(page.locator('.marketing-assets-library')).toBeVisible();
    await expect(page.locator('.marketing-assets-tool-hub')).toBeHidden();
    await expect(page.locator('[data-asset-workspace-toggle]')).toContainText('Asset tools');

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-assets-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing creative visual maturity', () => {
  test('desktop creative production is visual, low-text, and drawer-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_creative.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_creative\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Creative Production');
    await expect(page.locator('.marketing-page-header')).toContainText('Turn visual gaps into one clear creative action');
    await expect(page.getByRole('link', { name: /Command Center/i })).toHaveCount(0);
    await expect(page.locator('.marketing-creative-summary')).toBeVisible();
    await expect(page.locator('.marketing-creative-layout')).toBeVisible();
    await expect(page.locator('.marketing-creative-board')).toContainText('Creative Board');
    await expect(page.locator('.marketing-creative-today')).toBeVisible();
    await expect(page.locator('details.marketing-creative-tools')).toHaveCount(2);
    await expect(page.locator('details.marketing-creative-tools').first()).not.toHaveAttribute('open', '');
    await expect(page.locator('details.marketing-creative-tools').last()).not.toHaveAttribute('open', '');
    await expectMarketingCreativeFits(page);

    const cardCount = await page.locator('.marketing-creative-stage-card').count();
    expect(cardCount).toBe(6);
    const cardActions = await page.locator('.marketing-creative-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-creative-card-action').length)
    );
    cardActions.forEach((count) => expect(count).toBe(1));

    const todayActionCount = await page.locator('.marketing-creative-today-action').count();
    expect(todayActionCount).toBeGreaterThan(0);
    expect(todayActionCount).toBeLessThanOrEqual(5);

    const firstCard = page.locator('.marketing-creative-stage-card').first();
    await expectTooltipVisible(firstCard);

    await page.locator('#creative-evidence-tools > summary').click();
    await expect(page.locator('#creative-evidence-tools')).toHaveAttribute('open', '');
    await expect(page.locator('#creative-evidence-tools')).toContainText('AI Creative Workspace');
    await expect(page.locator('#creative-evidence-tools')).toContainText('Media Production Workflow');
    await expect(page.locator('#creative-evidence-tools')).toContainText('Campaign Creative Requirements');
    await expectMarketingCreativeFits(page);

    await page.locator('#creative-create-tools > summary').click();
    await expect(page.locator('#creative-create-tools')).toHaveAttribute('open', '');
    await expect(page.locator('#creative-create-tools')).toContainText('Creative Attention Queue');
    await expect(page.locator('#creative-create-tools')).toContainText('Create');
    await expectMarketingCreativeFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-creative-desktop.png'), fullPage: false });
  });

  test('mobile creative production stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_creative.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Creative Production');
    await expect(page.locator('.marketing-creative-summary')).toBeVisible();
    await expect(page.locator('.marketing-creative-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-creative-today')).toBeVisible();
    await expect(page.locator('details.marketing-creative-tools').first()).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingCreativeFits(page);

    const firstCard = await page.locator('.marketing-creative-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-creative-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing distribution visual maturity', () => {
  test('desktop distribution board is visual, low-text, and drawer-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_distribution.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_distribution\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Distribution Queue');
    await expect(page.locator('.marketing-page-header')).toContainText('Distribution Board');
    await expect(page.locator('.marketing-distribution-summary')).toBeVisible();
    await expect(page.locator('.marketing-distribution-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-distribution-layout')).toBeVisible();
    await expect(page.locator('.marketing-distribution-board')).toBeVisible();
    await expect(page.locator('.marketing-distribution-today')).toBeVisible();
    await expect(page.locator('details.marketing-distribution-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-grid')).toHaveCount(0);
    await expect(page.locator('.marketing-row')).toHaveCount(0);
    await expectMarketingDistributionFits(page);

    const stageActionCounts = await page.locator('.marketing-distribution-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-distribution-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-distribution-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-distribution-tools > summary').click();
    await expect(page.locator('.marketing-distribution-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-distribution-next-card')).toContainText('Send / Export next steps');
    await expect(page.locator('.marketing-distribution-readiness-card')).toContainText('Media Operational Readiness');
    await expect(page.locator('.marketing-distribution-create-card')).toContainText('Create Variant');
    await expect(page.locator('.marketing-distribution-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expectMarketingDistributionFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-distribution-desktop.png'), fullPage: false });
  });

  test('mobile distribution board stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_distribution.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Distribution Queue');
    await expect(page.locator('.marketing-distribution-summary')).toBeVisible();
    await expect(page.locator('.marketing-distribution-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-distribution-board')).toBeVisible();
    await expect(page.locator('.marketing-distribution-today')).toBeVisible();
    await expect(page.locator('details.marketing-distribution-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingDistributionFits(page);

    const firstCard = await page.locator('.marketing-distribution-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-distribution-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing distribution bundle visual maturity', () => {
  test('desktop export bundle is a visual package review with drawer-gated metadata', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await openFirstDistributionBundle(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_distribution_bundle\.php\?id=\d+/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Export Bundle');
    await expect(page.locator('.marketing-page-header')).toContainText('No external publishing happens here');
    await expect(page.locator('.marketing-distribution-bundle-summary')).toBeVisible();
    await expect(page.locator('.marketing-distribution-bundle-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-distribution-bundle-layout')).toBeVisible();
    await expect(page.locator('.marketing-distribution-bundle-copy')).toBeVisible();
    await expect(page.locator('.marketing-distribution-bundle-media')).toBeVisible();
    await expect(page.locator('.marketing-distribution-bundle-checklist')).toBeVisible();
    await expect(page.locator('.marketing-distribution-bundle-kits')).toBeVisible();
    await expect(page.locator('.marketing-distribution-bundle-today')).toBeVisible();
    await expect(page.locator('details.marketing-distribution-bundle-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.bundle-grid')).toHaveCount(0);
    await expect(page.locator('.bundle-pre')).toHaveCount(0);
    await expect(page.locator('.media-pack')).toHaveCount(0);
    await expectMarketingDistributionBundleFits(page);

    const stageActionCounts = await page.locator('.marketing-distribution-bundle-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-distribution-bundle-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-distribution-bundle-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-distribution-bundle-tools > summary').click();
    await expect(page.locator('.marketing-distribution-bundle-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-distribution-bundle-raw-kits')).toContainText('Channel Media Kits Payload');
    await expect(page.locator('.marketing-distribution-bundle-metadata')).toContainText('Metadata');
    await expect(page.locator('.marketing-distribution-bundle-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expectMarketingDistributionBundleFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-distribution-bundle-desktop.png'), fullPage: false });
  });

  test('mobile export bundle stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await openFirstDistributionBundle(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Export Bundle');
    await expect(page.locator('.marketing-distribution-bundle-summary')).toBeVisible();
    await expect(page.locator('.marketing-distribution-bundle-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-distribution-bundle-copy')).toBeVisible();
    await expect(page.locator('.marketing-distribution-bundle-media')).toBeVisible();
    await expect(page.locator('.marketing-distribution-bundle-today')).toBeVisible();
    await expect(page.locator('details.marketing-distribution-bundle-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingDistributionBundleFits(page);

    const firstCard = await page.locator('.marketing-distribution-bundle-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-distribution-bundle-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing operator export packs visual maturity', () => {
  test('desktop operator pack is a visual handoff board with drawer-gated metadata', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await openFirstOperatorExportPack(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_operator_export_packs\.php\?id=\d+/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Operator Export Packs');
    await expect(page.locator('.marketing-page-header')).toContainText('Safe campaign handoffs');
    await expect(page.locator('.marketing-operator-pack-summary')).toBeVisible();
    await expect(page.locator('.marketing-operator-pack-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-operator-pack-layout')).toBeVisible();
    await expect(page.locator('.marketing-operator-pack-copy')).toBeVisible();
    await expect(page.locator('.marketing-operator-pack-risks')).toBeVisible();
    await expect(page.locator('.marketing-operator-pack-next')).toBeVisible();
    await expect(page.locator('.marketing-operator-pack-today')).toBeVisible();
    await expect(page.locator('details.marketing-operator-pack-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.export-pack-layout')).toHaveCount(0);
    await expect(page.locator('.export-pack-grid')).toHaveCount(0);
    await expect(page.locator('.export-pack-row')).toHaveCount(0);
    await expectMarketingOperatorPackFits(page);

    const stageActionCounts = await page.locator('.marketing-operator-pack-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-operator-pack-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-operator-pack-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-operator-pack-tools > summary').click();
    await expect(page.locator('.marketing-operator-pack-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-operator-pack-guardrails')).toContainText('No sending or publishing happens here');
    await expect(page.locator('.marketing-operator-pack-metadata')).toContainText('Snapshot Metadata');
    await expect(page.locator('.marketing-operator-pack-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expectMarketingOperatorPackFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-operator-export-pack-desktop.png'), fullPage: false });
  });

  test('mobile operator pack stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await openFirstOperatorExportPack(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Operator Export Packs');
    await expect(page.locator('.marketing-operator-pack-summary')).toBeVisible();
    await expect(page.locator('.marketing-operator-pack-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-operator-pack-copy')).toBeVisible();
    await expect(page.locator('.marketing-operator-pack-risks')).toBeVisible();
    await expect(page.locator('.marketing-operator-pack-today')).toBeVisible();
    await expect(page.locator('details.marketing-operator-pack-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingOperatorPackFits(page);

    const firstCard = await page.locator('.marketing-operator-pack-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-operator-export-pack-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing channel exports visual maturity', () => {
  test('desktop channel exports is a visual channel board with drawer-gated operations', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_channel_exports.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_channel_exports\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Channel Export Bundles');
    await expect(page.locator('.marketing-page-header')).toContainText('Package posts for manual publishing by channel');
    await expect(page.locator('.marketing-channel-export-summary')).toBeVisible();
    await expect(page.locator('.marketing-channel-export-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-channel-export-layout')).toBeVisible();
    await expect(page.locator('.marketing-channel-export-board')).toBeVisible();
    await expect(page.locator('.marketing-channel-export-media')).toBeVisible();
    await expect(page.locator('.marketing-channel-export-today')).toBeVisible();
    await expect(page.locator('details.marketing-channel-export-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-channel-export-page [style]')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('Package distribution posts for manual publishing by channel. No external social');
    await expectMarketingChannelExportsFits(page);

    const stageActionCounts = await page.locator('.marketing-channel-export-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-channel-export-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-channel-export-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-channel-export-tools > summary').click();
    await expect(page.locator('.marketing-channel-export-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-channel-export-tools-body')).toContainText('Live Setup Wizard');
    await expect(page.locator('.marketing-channel-export-tools-body')).toContainText('Integration Readiness');
    await expectMarketingChannelExportsFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-channel-exports-desktop.png'), fullPage: false });
  });

  test('mobile channel exports stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_channel_exports.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Channel Export Bundles');
    await expect(page.locator('.marketing-channel-export-summary')).toBeVisible();
    await expect(page.locator('.marketing-channel-export-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-channel-export-board')).toBeVisible();
    await expect(page.locator('.marketing-channel-export-media')).toBeVisible();
    await expect(page.locator('.marketing-channel-export-today')).toBeVisible();
    await expect(page.locator('details.marketing-channel-export-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-channel-export-page [style]')).toHaveCount(0);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingChannelExportsFits(page);

    const firstCard = await page.locator('.marketing-channel-export-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-channel-exports-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing UTM links visual maturity', () => {
  test('desktop UTM links is a visual tracking board with tooltip-gated detail', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_utm_links.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_utm_links\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('UTM Links');
    await expect(page.locator('.marketing-page-header')).toContainText('Make manual campaign links trackable');
    await expect(page.locator('.marketing-utm-summary')).toBeVisible();
    await expect(page.locator('.marketing-utm-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-utm-layout')).toBeVisible();
    await expect(page.locator('.marketing-utm-board')).toBeVisible();
    await expect(page.locator('.marketing-utm-create')).toBeVisible();
    await expect(page.locator('.marketing-utm-today')).toBeVisible();
    await expect(page.locator('details.marketing-utm-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('body')).not.toContainText('Generate campaign/content tracking links without changing publishing systems');
    await expect(page.locator('.marketing-grid')).toHaveCount(0);
    await expect(page.locator('.marketing-row')).toHaveCount(0);
    await expectMarketingUtmFits(page);

    const stageActionCounts = await page.locator('.marketing-utm-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-utm-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-utm-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-utm-tools > summary').click();
    await expect(page.locator('.marketing-utm-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-utm-tools .marketing-advanced-tools-grid a')).toHaveCount(4);
    await expectMarketingUtmFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-utm-links-desktop.png'), fullPage: false });
  });

  test('mobile UTM links stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_utm_links.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('UTM Links');
    await expect(page.locator('.marketing-utm-summary')).toBeVisible();
    await expect(page.locator('.marketing-utm-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-utm-board')).toBeVisible();
    await expect(page.locator('.marketing-utm-create')).toBeVisible();
    await expect(page.locator('.marketing-utm-today')).toBeVisible();
    await expect(page.locator('details.marketing-utm-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingUtmFits(page);

    const firstCard = await page.locator('.marketing-utm-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-utm-links-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing email runs visual maturity', () => {
  test('desktop email runs is a visual manual-send board with drawer-gated detail', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_email_runs.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_email_runs\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Email Runs');
    await expect(page.locator('.marketing-page-header')).toContainText('Prepare manual email sends without sending externally');
    await expect(page.locator('.marketing-email-run-summary')).toBeVisible();
    await expect(page.locator('.marketing-email-run-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-email-run-layout')).toBeVisible();
    await expect(page.locator('.marketing-email-run-board')).toBeVisible();
    await expect(page.locator('.marketing-email-run-create')).toBeVisible();
    await expect(page.locator('.marketing-email-run-today')).toBeVisible();
    await expect(page.locator('details.marketing-email-run-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('body')).not.toContainText('Prepare email campaign runs, export manual send bundles');
    await expect(page.locator('.email-run-grid')).toHaveCount(0);
    await expect(page.locator('.email-run-row')).toHaveCount(0);
    await expectMarketingEmailRunsFits(page);

    const stageActionCounts = await page.locator('.marketing-email-run-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-email-run-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-email-run-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await expect(page.locator('.marketing-email-run-form-section')).toHaveCount(3);
    await page.locator('details.marketing-email-run-tools > summary').click();
    await expect(page.locator('.marketing-email-run-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-email-run-filter')).toBeVisible();
    await expect(page.locator('.marketing-email-run-tools .marketing-advanced-tools-grid a')).toHaveCount(5);
    await expectMarketingEmailRunsFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-email-runs-desktop.png'), fullPage: false });
  });

  test('mobile email runs stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_email_runs.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Email Runs');
    await expect(page.locator('.marketing-email-run-summary')).toBeVisible();
    await expect(page.locator('.marketing-email-run-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-email-run-board')).toBeVisible();
    await expect(page.locator('.marketing-email-run-create')).toBeVisible();
    await expect(page.locator('.marketing-email-run-today')).toBeVisible();
    await expect(page.locator('details.marketing-email-run-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingEmailRunsFits(page);

    const firstCard = await page.locator('.marketing-email-run-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-email-runs-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing performance visual maturity', () => {
  test('desktop performance is a visual learning board with drawer-gated analysis', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_performance.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_performance\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Performance');
    await expect(page.locator('.marketing-page-header')).toContainText('Learn what happened and choose the next test');
    await expect(page.locator('.marketing-performance-summary')).toBeVisible();
    await expect(page.locator('.marketing-performance-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-performance-layout')).toBeVisible();
    await expect(page.locator('.marketing-performance-loop')).toBeVisible();
    await expect(page.locator('.marketing-performance-goals')).toBeVisible();
    await expect(page.locator('.marketing-performance-today')).toBeVisible();
    await expect(page.locator('.marketing-performance-secondary-grid')).toBeVisible();
    await expect(page.locator('details.marketing-performance-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('body')).not.toContainText('Track manual distribution readiness, attribution, ROI, content influence');
    await expect(page.locator('.marketing-grid')).toHaveCount(0);
    await expect(page.locator('.performance-columns')).toHaveCount(0);
    await expect(page.locator('.marketing-row')).toHaveCount(0);
    await expectMarketingPerformanceFits(page);

    const stageActionCounts = await page.locator('.marketing-performance-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-performance-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-performance-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await expect(page.locator('#campaign-revenue-loop')).toContainText('Campaign-To-Revenue Loop');
    await expect(page.locator('#conversion-goals')).toContainText('Conversion Goals');
    await expect(page.locator('#attribution-pipeline')).toContainText('Influenced Leads');
    await page.locator('details.marketing-performance-tools > summary').click();
    await expect(page.locator('.marketing-performance-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-performance-tools .marketing-advanced-tools-grid a')).toHaveCount(5);
    await expect(page.locator('.marketing-performance-detail-section').first()).toContainText('Results next steps');
    await expectMarketingPerformanceFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-performance-desktop.png'), fullPage: false });
  });

  test('mobile performance stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_performance.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Performance');
    await expect(page.locator('.marketing-performance-summary')).toBeVisible();
    await expect(page.locator('.marketing-performance-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-performance-loop')).toBeVisible();
    await expect(page.locator('.marketing-performance-goals')).toBeVisible();
    await expect(page.locator('.marketing-performance-today')).toBeVisible();
    await expect(page.locator('details.marketing-performance-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingPerformanceFits(page);

    const firstCard = await page.locator('.marketing-performance-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-performance-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing handoffs visual maturity', () => {
  test('desktop handoffs is a visual follow-up board with drawer-gated tools', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_handoffs.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_handoffs\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Lead Handoffs');
    await expect(page.locator('.marketing-page-header')).toContainText('Move interested leads into clear sales follow-up');
    await expect(page.locator('.marketing-handoff-summary')).toBeVisible();
    await expect(page.locator('.marketing-handoff-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-handoff-layout')).toBeVisible();
    await expect(page.locator('.marketing-handoff-board')).toBeVisible();
    await expect(page.locator('.marketing-handoff-today')).toBeVisible();
    await expect(page.locator('details.marketing-handoff-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('body')).not.toContainText('Turn captured marketing intent into sales follow-up tasks');
    await expect(page.locator('.handoff-grid')).toHaveCount(0);
    await expect(page.locator('.handoff-columns')).toHaveCount(0);
    await expect(page.locator('.handoff-row')).toHaveCount(0);
    await expectMarketingHandoffsFits(page);

    const stageActionCounts = await page.locator('.marketing-handoff-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-handoff-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-handoff-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await expect(page.locator('.marketing-handoff-board')).toContainText('Follow-Up Queue');
    await page.locator('details.marketing-handoff-tools > summary').click();
    await expect(page.locator('.marketing-handoff-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-handoff-tools .marketing-advanced-tools-grid a')).toHaveCount(4);
    await expect(page.locator('.marketing-handoff-filter')).toContainText('Queue Filter');
    await expect(page.locator('.marketing-handoff-detail-section').last()).toContainText('Routing Rules');
    await expectMarketingHandoffsFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-handoffs-desktop.png'), fullPage: false });
  });

  test('mobile handoffs stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_handoffs.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Lead Handoffs');
    await expect(page.locator('.marketing-handoff-summary')).toBeVisible();
    await expect(page.locator('.marketing-handoff-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-handoff-board')).toBeVisible();
    await expect(page.locator('.marketing-handoff-today')).toBeVisible();
    await expect(page.locator('details.marketing-handoff-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingHandoffsFits(page);

    const firstCard = await page.locator('.marketing-handoff-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-handoffs-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing weekly report visual maturity', () => {
  test('desktop weekly report is a visual learning board with drawer-gated evidence', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_weekly_report.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_weekly_report\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Weekly Marketing Report');
    await expect(page.locator('.marketing-page-header')).toContainText('Review the week and choose one lesson');
    await expect(page.locator('.marketing-weekly-period')).toBeVisible();
    await expect(page.locator('.marketing-weekly-report-summary')).toBeVisible();
    await expect(page.locator('.marketing-weekly-report-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-weekly-report-layout')).toBeVisible();
    await expect(page.locator('.marketing-weekly-report-board')).toBeVisible();
    await expect(page.locator('.marketing-weekly-report-today')).toBeVisible();
    await expect(page.locator('.marketing-weekly-signal-card')).toHaveCount(4);
    await expect(page.locator('details.marketing-weekly-report-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.report-grid')).toHaveCount(0);
    await expect(page.locator('.report-card')).toHaveCount(0);
    await expect(page.locator('.report-list')).toHaveCount(0);
    await expectMarketingWeeklyReportFits(page);

    const stageActionCounts = await page.locator('.marketing-weekly-report-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-weekly-report-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-weekly-report-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await expect(page.locator('.marketing-weekly-report-board')).toContainText('This Week');
    await page.locator('details.marketing-weekly-report-tools > summary').click();
    await expect(page.locator('.marketing-weekly-report-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-weekly-report-tools .marketing-advanced-tools-grid a')).toHaveCount(4);
    await expect(page.locator('.marketing-weekly-detail-grid')).toContainText('Campaign ROI');
    await expect(page.locator('#execution-evidence')).toContainText('Execution Evidence');
    await expectMarketingWeeklyReportFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-weekly-report-desktop.png'), fullPage: false });
  });

  test('mobile weekly report stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_weekly_report.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Weekly Marketing Report');
    await expect(page.locator('.marketing-weekly-period')).toBeVisible();
    await expect(page.locator('.marketing-weekly-report-summary')).toBeVisible();
    await expect(page.locator('.marketing-weekly-report-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-weekly-report-board')).toBeVisible();
    await expect(page.locator('.marketing-weekly-report-today')).toBeVisible();
    await expect(page.locator('details.marketing-weekly-report-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingWeeklyReportFits(page);

    const firstCard = await page.locator('.marketing-weekly-report-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-weekly-report-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing monthly report visual maturity', () => {
  test('desktop monthly report is a visual strategy board with drawer-gated evidence', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_monthly_report.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_monthly_report\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Monthly Marketing Report');
    await expect(page.locator('.marketing-page-header')).toContainText('Review the month and choose the next bet');
    await expect(page.locator('.marketing-monthly-period')).toBeVisible();
    await expect(page.locator('.marketing-monthly-report-summary')).toBeVisible();
    await expect(page.locator('.marketing-monthly-report-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-monthly-report-layout')).toBeVisible();
    await expect(page.locator('.marketing-monthly-report-board')).toBeVisible();
    await expect(page.locator('.marketing-monthly-report-today')).toBeVisible();
    await expect(page.locator('.marketing-monthly-signal-card')).toHaveCount(4);
    await expect(page.locator('details.marketing-monthly-report-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.report-grid')).toHaveCount(0);
    await expect(page.locator('.report-card')).toHaveCount(0);
    await expect(page.locator('.report-list')).toHaveCount(0);
    await expectMarketingMonthlyReportFits(page);

    const stageActionCounts = await page.locator('.marketing-monthly-report-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-monthly-report-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-monthly-report-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await expect(page.locator('.marketing-monthly-report-board')).toContainText('This Month');
    await page.locator('details.marketing-monthly-report-tools > summary').click();
    await expect(page.locator('.marketing-monthly-report-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-monthly-report-tools .marketing-advanced-tools-grid a')).toHaveCount(4);
    await expect(page.locator('.marketing-monthly-detail-grid')).toContainText('Campaign ROI');
    await expect(page.locator('#monthly-evidence')).toContainText('Execution Evidence');
    await expectMarketingMonthlyReportFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-monthly-report-desktop.png'), fullPage: false });
  });

  test('mobile monthly report stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_monthly_report.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Monthly Marketing Report');
    await expect(page.locator('.marketing-monthly-period')).toBeVisible();
    await expect(page.locator('.marketing-monthly-report-summary')).toBeVisible();
    await expect(page.locator('.marketing-monthly-report-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-monthly-report-board')).toBeVisible();
    await expect(page.locator('.marketing-monthly-report-today')).toBeVisible();
    await expect(page.locator('details.marketing-monthly-report-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingMonthlyReportFits(page);

    const firstCard = await page.locator('.marketing-monthly-report-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-monthly-report-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing operations visual maturity', () => {
  test('desktop operations is a visual weekly work board with drawer-gated tools', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_operations.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_operations\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Operations');
    await expect(page.locator('.marketing-page-header')).toContainText('Plan the week and keep campaign work moving');
    await expect(page.locator('.marketing-operations-week')).toBeVisible();
    await expect(page.locator('.marketing-operations-summary')).toBeVisible();
    await expect(page.locator('.marketing-operations-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-operations-layout')).toBeVisible();
    await expect(page.locator('.marketing-operations-board')).toContainText('Weekly Queue');
    await expect(page.locator('.marketing-operations-today')).toBeVisible();
    await expect(page.locator('details.marketing-operations-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.operations-grid')).toHaveCount(0);
    await expect(page.locator('.operations-stats')).toHaveCount(0);
    await expect(page.locator('.operations-row')).toHaveCount(0);
    await expect(page.locator('.automation-loop-grid')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('Run recurring planning rhythms');
    await expectMarketingOperationsFits(page);

    const stageActionCounts = await page.locator('.marketing-operations-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-operations-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-operations-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-operations-tools > summary').click();
    await expect(page.locator('.marketing-operations-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-operations-tools .marketing-advanced-tools-grid a')).toHaveCount(5);
    await expect(page.locator('#ai-queue')).toContainText('AI Queue Suggestions');
    await expect(page.locator('#manual-automation')).toContainText('Manual Automation Readiness');
    await expect(page.locator('.marketing-operations-form-grid')).toBeVisible();
    await expectMarketingOperationsFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-operations-desktop.png'), fullPage: false });
  });

  test('mobile operations stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_operations.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Operations');
    await expect(page.locator('.marketing-operations-week')).toBeVisible();
    await expect(page.locator('.marketing-operations-summary')).toBeVisible();
    await expect(page.locator('.marketing-operations-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-operations-board')).toBeVisible();
    await expect(page.locator('.marketing-operations-today')).toBeVisible();
    await expect(page.locator('details.marketing-operations-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingOperationsFits(page);

    const firstCard = await page.locator('.marketing-operations-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-operations-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing quality visual maturity', () => {
  test('desktop quality is a visual review board with drawer-gated tools', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_quality.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_quality\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Content Quality Review');
    await expect(page.locator('.marketing-page-header')).toContainText('Check copy before it goes into the campaign');
    await expect(page.locator('.marketing-quality-summary')).toBeVisible();
    await expect(page.locator('.marketing-quality-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-quality-layout')).toBeVisible();
    await expect(page.locator('.marketing-quality-board')).toContainText('Review Queue');
    await expect(page.locator('.marketing-quality-today')).toBeVisible();
    await expect(page.locator('details.marketing-quality-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.quality-grid')).toHaveCount(0);
    await expect(page.locator('.quality-command')).toHaveCount(0);
    await expect(page.locator('.quality-row')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('Quality Review Command Center');
    await expectMarketingQualityFits(page);

    const stageActionCounts = await page.locator('.marketing-quality-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-quality-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-quality-stage-card').first();
    await firstStage.hover();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-quality-tools > summary').click();
    await expect(page.locator('.marketing-quality-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-quality-tools .marketing-advanced-tools-grid a')).toHaveCount(5);
    await expect(page.locator('.marketing-quality-tools-body')).toContainText('Review Dimensions');
    await expect(page.locator('.marketing-quality-tools-body')).toContainText('Run Check');
    await expect(page.locator('.marketing-quality-guardrail')).toContainText('Advisory checks do not publish externally');
    await expectMarketingQualityFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-quality-desktop.png'), fullPage: false });
  });

  test('mobile quality stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_quality.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Content Quality Review');
    await expect(page.locator('.marketing-quality-summary')).toBeVisible();
    await expect(page.locator('.marketing-quality-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-quality-board')).toBeVisible();
    await expect(page.locator('.marketing-quality-today')).toBeVisible();
    await expect(page.locator('details.marketing-quality-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingQualityFits(page);

    const firstCard = await page.locator('.marketing-quality-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-quality-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing relationships visual maturity', () => {
  test('desktop relationships is a visual connection map with drawer-gated tools', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_relationships.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_relationships\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Campaign Connection Map');
    await expect(page.locator('.marketing-page-header')).toContainText('See what is connected and what needs linking');
    await expect(page.locator('.marketing-relationships-summary')).toBeVisible();
    await expect(page.locator('.marketing-relationships-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-relationships-layout')).toBeVisible();
    await expect(page.locator('.marketing-relationships-board')).toContainText('Connection Map');
    await expect(page.locator('.marketing-relationships-today')).toBeVisible();
    await expect(page.locator('details.marketing-relationships-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.relationship-stats')).toHaveCount(0);
    await expect(page.locator('.relationship-layout')).toHaveCount(0);
    await expect(page.locator('.relationship-row')).toHaveCount(0);
    await expect(page.locator('.relationship-panel')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('Map how campaigns, briefs, content');
    await expectMarketingRelationshipsFits(page);

    const stageActionCounts = await page.locator('.marketing-relationships-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-relationships-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-relationships-stage-card').first();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-relationships-tools > summary').click();
    await expect(page.locator('.marketing-relationships-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-relationships-tools .marketing-advanced-tools-grid a')).toHaveCount(5);
    await expect(page.locator('.marketing-relationships-tools-body')).toContainText('Orphaned Records');
    await expect(page.locator('.marketing-relationships-tools-body')).toContainText('Recommended Actions');
    await expect(page.locator('#connection-guardrails')).toContainText('No external sending');
    await expectMarketingRelationshipsFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-relationships-desktop.png'), fullPage: false });
  });

  test('mobile relationships stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_relationships.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Campaign Connection Map');
    await expect(page.locator('.marketing-relationships-summary')).toBeVisible();
    await expect(page.locator('.marketing-relationships-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-relationships-board')).toBeVisible();
    await expect(page.locator('.marketing-relationships-today')).toBeVisible();
    await expect(page.locator('details.marketing-relationships-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingRelationshipsFits(page);

    const firstCard = await page.locator('.marketing-relationships-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-relationships-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing SEO visual maturity', () => {
  test('desktop SEO is a visual topic map with drawer-gated tools', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_seo.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_seo\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('SEO Topic Map');
    await expect(page.locator('.marketing-page-header')).toContainText('Choose search topics that support the campaign');
    await expect(page.locator('.marketing-seo-summary')).toBeVisible();
    await expect(page.locator('.marketing-seo-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-seo-layout')).toBeVisible();
    await expect(page.locator('.marketing-seo-board')).toContainText('Topic Plan');
    await expect(page.locator('.marketing-seo-today')).toBeVisible();
    await expect(page.locator('details.marketing-seo-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-grid')).toHaveCount(0);
    await expect(page.locator('.marketing-row')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('Plan keywords, search intent, funnel stage');
    await expectMarketingSeoFits(page);

    const stageActionCounts = await page.locator('.marketing-seo-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-seo-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-seo-stage-card').first();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-seo-tools > summary').click();
    await expect(page.locator('.marketing-seo-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-seo-tools .marketing-advanced-tools-grid a')).toHaveCount(5);
    await expect(page.locator('.marketing-seo-tools-body')).toContainText('Filter Topics');
    await expect(page.locator('.marketing-seo-tools-body')).toContainText('Add Topic');
    await expect(page.locator('.marketing-seo-signal-grid')).toBeVisible();
    await expectMarketingSeoFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-seo-desktop.png'), fullPage: false });
  });

  test('mobile SEO stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_seo.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('SEO Topic Map');
    await expect(page.locator('.marketing-seo-summary')).toBeVisible();
    await expect(page.locator('.marketing-seo-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-seo-board')).toBeVisible();
    await expect(page.locator('.marketing-seo-today')).toBeVisible();
    await expect(page.locator('details.marketing-seo-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingSeoFits(page);

    const firstCard = await page.locator('.marketing-seo-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-seo-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing landing pages visual maturity', () => {
  test('desktop landing pages is a visual destination board with drawer-gated tools', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_landing_pages.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_landing_pages\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Landing Page Board');
    await expect(page.locator('.marketing-page-header')).toContainText('Prepare the campaign destination before launch');
    await expect(page.locator('.marketing-landing-summary')).toBeVisible();
    await expect(page.locator('.marketing-landing-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-landing-layout')).toBeVisible();
    await expect(page.locator('.marketing-landing-board')).toContainText('Landing Page Plans');
    await expect(page.locator('.marketing-landing-today')).toBeVisible();
    await expect(page.locator('details.marketing-landing-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-row')).toHaveCount(0);
    await expect(page.locator('.landing-visual-grid')).toHaveCount(0);
    await expect(page.locator('.landing-visual-layout')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('Plan CRM-native landing page copy');
    await expectMarketingLandingFits(page);

    const stageActionCounts = await page.locator('.marketing-landing-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-landing-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-landing-stage-card').first();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-landing-tools > summary').click();
    await expect(page.locator('.marketing-landing-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-landing-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expect(page.locator('.marketing-landing-tools-body')).toContainText('Landing Visual Readiness');
    await expect(page.locator('.marketing-landing-tools-body')).toContainText('Media Readiness');
    await expect(page.locator('.marketing-landing-tools-body')).toContainText('Filter Pages');
    await expect(page.locator('.marketing-landing-signal-grid')).toBeVisible();
    await expectMarketingLandingFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-landing-pages-desktop.png'), fullPage: false });
  });

  test('mobile landing pages stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_landing_pages.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Landing Page Board');
    await expect(page.locator('.marketing-landing-summary')).toBeVisible();
    await expect(page.locator('.marketing-landing-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-landing-board')).toBeVisible();
    await expect(page.locator('.marketing-landing-today')).toBeVisible();
    await expect(page.locator('details.marketing-landing-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingLandingFits(page);

    const firstCard = await page.locator('.marketing-landing-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-landing-pages-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing action router visual maturity', () => {
  test('desktop action router is a visual route board with drawer-gated tools', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_action_router.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_action_router\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Action Router');
    await expect(page.locator('.marketing-page-header')).toContainText('Choose the next useful Marketing move');
    await expect(page.locator('.marketing-page-actions')).toContainText('Marketing');
    await expect(page.locator('.marketing-page-actions')).toContainText('Refresh Next Moves');
    await expect(page.locator('.marketing-action-router-summary')).toBeVisible();
    await expect(page.locator('.marketing-action-router-stage-card')).toHaveCount(5);
    await expect(page.locator('.marketing-action-router-stage-visual')).toHaveCount(5);
    await expect(page.locator('.marketing-action-router-layout')).toBeVisible();
    await expect(page.locator('.marketing-action-router-board')).toContainText('Route Board');
    await expect(page.locator('.marketing-action-router-today')).toBeVisible();
    await expect(page.locator('details.marketing-action-router-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.action-router-grid')).toHaveCount(0);
    await expect(page.locator('.action-router-stats')).toHaveCount(0);
    await expect(page.locator('.action-router-card')).toHaveCount(0);
    await expect(page.locator('.crm-integration-lane')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('Turn Marketing recommendations into safe CRM-linked actions');
    await expectMarketingActionRouterFits(page);

    const stageActionCounts = await page.locator('.marketing-action-router-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-action-router-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-action-router-stage-card').first();
    await expect(firstStage.locator('.marketing-action-router-stage-visual')).toBeVisible();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-action-router-tools > summary').click();
    await expect(page.locator('.marketing-action-router-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-action-router-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expect(page.locator('.marketing-action-router-tools-body')).toContainText('Filter Queue');
    await expect(page.locator('.marketing-action-router-tools-body')).toContainText('CRM Integration Action Center');
    await expect(page.locator('.marketing-action-router-tools-body')).toContainText('Connected Systems');
    await expect(page.locator('.marketing-action-router-tools-body')).toContainText('Router Guardrails');
    await expectMarketingActionRouterFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-action-router-desktop.png'), fullPage: false });
  });

  test('mobile action router stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_action_router.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Action Router');
    await expect(page.locator('.marketing-page-actions')).toContainText('Refresh Next Moves');
    await expect(page.locator('.marketing-action-router-summary')).toBeVisible();
    await expect(page.locator('.marketing-action-router-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-action-router-board')).toBeVisible();
    await expect(page.locator('.marketing-action-router-today')).toBeVisible();
    await expect(page.locator('details.marketing-action-router-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingActionRouterFits(page);

    const firstCard = await page.locator('.marketing-action-router-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-action-router-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing system map visual maturity', () => {
  test('desktop system map is a visual health map with drawer-gated evidence', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_system_map.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_system_map\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing System Map');
    await expect(page.locator('.marketing-page-header')).toContainText('See system health and open the weakest area first');
    await expect(page.locator('.marketing-system-map-summary')).toBeVisible();
    await expect(page.locator('.marketing-system-map-stage-card')).toHaveCount(8);
    await expect(page.locator('.marketing-system-map-layout')).toBeVisible();
    await expect(page.locator('.marketing-system-map-board')).toContainText('System Health Map');
    await expect(page.locator('.marketing-system-map-today')).toBeVisible();
    await expect(page.locator('details.marketing-system-map-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.system-map-summary')).toHaveCount(0);
    await expect(page.locator('.system-map-grid')).toHaveCount(0);
    await expect(page.locator('.system-map-stage')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('See how setup, context, strategy, content, media, distribution');
    await expectMarketingSystemMapFits(page);

    const stageActionCounts = await page.locator('.marketing-system-map-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-system-map-stage-action').length)
    );
    expect(stageActionCounts).toEqual([1, 1, 1, 1, 1, 1, 1, 1]);

    const firstStage = page.locator('.marketing-system-map-stage-card').first();
    await expectTooltipVisible(firstStage);

    await page.locator('details.marketing-system-map-tools > summary').click();
    await expect(page.locator('.marketing-system-map-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-system-map-tools .marketing-advanced-tools-grid a')).toHaveCount(6);
    await expect(page.locator('.marketing-system-map-tools-body')).toContainText('System Evidence');
    await expect(page.locator('.marketing-system-map-tools-body')).toContainText('Guardrails');
    await expect(page.locator('.marketing-system-map-detail-card')).toHaveCount(8);
    await expectMarketingSystemMapFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-system-map-desktop.png'), fullPage: false });
  });

  test('mobile system map stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_system_map.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing System Map');
    await expect(page.locator('.marketing-system-map-summary')).toBeVisible();
    await expect(page.locator('.marketing-system-map-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-system-map-board')).toBeVisible();
    await expect(page.locator('.marketing-system-map-today')).toBeVisible();
    await expect(page.locator('details.marketing-system-map-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingSystemMapFits(page);

    const firstCard = await page.locator('.marketing-system-map-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-system-map-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing decisions visual maturity', () => {
  test('desktop decision center is a visual decision board with drawer-gated tools', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_decisions.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_decisions\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Decision Center');
    await expect(page.locator('.marketing-page-header')).toContainText('Choose the next safe campaign move');
    await expect(page.locator('.marketing-decisions-summary')).toBeVisible();
    await expect(page.locator('.marketing-decisions-layout')).toBeVisible();
    await expect(page.locator('.marketing-decisions-board')).toContainText('Decision Board');
    await expect(page.locator('.marketing-decisions-today')).toBeVisible();
    await expect(page.locator('.marketing-decisions-create')).toBeVisible();
    await expect(page.locator('details.marketing-decisions-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.decision-grid')).toHaveCount(0);
    await expect(page.locator('.decision-stats')).toHaveCount(0);
    await expect(page.locator('.decision-row')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('Turn launch blockers, AI recommendations, media gaps');
    await expectMarketingDecisionsFits(page);

    const todayActionCount = await page.locator('.marketing-decisions-today-action').count();
    expect(todayActionCount).toBeGreaterThan(0);
    expect(todayActionCount).toBeLessThanOrEqual(4);

    const decisionCardCount = await page.locator('.marketing-decision-card').count();
    if (decisionCardCount > 0) {
      const visiblePrimaryActions = await page.locator('.marketing-decision-card').evaluateAll((cards) =>
        cards.map((card) => card.querySelectorAll('.marketing-decision-actions > form .btn-premium-primary').length)
      );
      visiblePrimaryActions.forEach((count) => expect(count).toBeLessThanOrEqual(1));
    }

    const firstMetric = page.locator('.marketing-decisions-summary .marketing-summary-tile').first();
    await expectTooltipVisible(firstMetric);

    await page.locator('details.marketing-decisions-tools > summary').click();
    await expect(page.locator('.marketing-decisions-tools-body')).toBeVisible();
    await expect(page.locator('.marketing-decisions-tool-link')).toHaveCount(3);
    await expect(page.locator('.marketing-decisions-boundary')).toContainText('Manual-first boundary');
    await expectMarketingDecisionsFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-decisions-desktop.png'), fullPage: false });
  });

  test('mobile decision center stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_decisions.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Decision Center');
    await expect(page.locator('.marketing-decisions-summary')).toBeVisible();
    await expect(page.locator('.marketing-decisions-board')).toBeVisible();
    await expect(page.locator('.marketing-decisions-today')).toBeVisible();
    await expect(page.locator('.marketing-decisions-create')).toBeVisible();
    await expect(page.locator('details.marketing-decisions-tools')).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingDecisionsFits(page);

    const firstCardCount = await page.locator('.marketing-decision-card').count();
    if (firstCardCount > 0) {
      const firstCard = await page.locator('.marketing-decision-card').first().boundingBox();
      expect(firstCard.width).toBeLessThanOrEqual(390);
    }

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-decisions-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing assistants visual maturity', () => {
  test('desktop assistants page is a visual AI help board with drawer-gated context', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_assistants.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_assistants\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Professional Marketer');
    await expect(page.locator('.marketing-page-header')).toContainText('Choose a specialist and ask');
    await expect(page.locator('.marketing-assistants-summary')).toBeVisible();
    await expect(page.locator('.marketing-assistants-layout')).toBeVisible();
    await expect(page.locator('.marketing-assistants-board')).toContainText('Specialists');
    await expect(page.locator('.marketing-assistants-today')).toBeVisible();
    await expect(page.locator('.marketing-assistants-run-panel')).toBeVisible();
    await expect(page.locator('details.marketing-assistants-tools')).toHaveCount(2);
    await expect(page.locator('details.marketing-assistants-tools').first()).not.toHaveAttribute('open', '');
    await expect(page.locator('.assistant-grid')).toHaveCount(0);
    await expect(page.locator('.assistant-command-grid')).toHaveCount(0);
    await expect(page.locator('.ai-brain-panel')).toHaveCount(0);
    await expect(page.locator('.ai-context-gate')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('Use CRM context to create draft-side recommendations');
    await expectMarketingAssistantsFits(page);

    const assistantCardCount = await page.locator('.marketing-assistant-card').count();
    expect(assistantCardCount).toBeGreaterThan(0);
    const visibleCardActions = await page.locator('.marketing-assistant-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-assistant-card-action').length)
    );
    visibleCardActions.forEach((count) => expect(count).toBe(1));

    const todayActionCount = await page.locator('.marketing-assistants-today-action').count();
    expect(todayActionCount).toBeGreaterThan(0);
    expect(todayActionCount).toBeLessThanOrEqual(5);

    const firstCard = page.locator('.marketing-assistant-card').first();
    await expectTooltipVisible(firstCard);

    const seoCard = page.locator('[data-assistant-type="seo_researcher"]');
    await seoCard.click();
    await expect(seoCard).toHaveClass(/is-selected/);
    await expect(page.locator('#marketing-assistant-type')).toHaveValue('seo_researcher');
    await expect(page.locator('[data-selected-specialist]')).toHaveText('SEO Researcher');
    await expect(page.locator('#marketing-assistant-prompt')).toHaveAttribute('placeholder', /topic, landing page, or audience/i);

    await page.locator('details.marketing-assistants-form-more > summary').click();
    await expect(page.locator('details.marketing-assistants-form-more')).toHaveAttribute('open', '');
    await expect(page.locator('#run-assistant')).toContainText('Linked Landing Page');
    await expectMarketingAssistantsFits(page);

    await page.locator('details.marketing-assistants-tools > summary').first().click();
    await expect(page.locator('.marketing-assistants-tools-body').first()).toBeVisible();
    await expect(page.locator('.marketing-assistants-tools-body').first()).toContainText('AI Workspace Brain');
    await expect(page.locator('.marketing-assistants-tools-body').first()).toContainText('AI Context Quality Gate');
    await expect(page.locator('.marketing-assistants-tools-body').first()).toContainText('AI Context Evidence');
    await expectMarketingAssistantsFits(page);

    await page.locator('#strategy-gap-analysis > summary').click();
    await expect(page.locator('#strategy-gap-analysis')).toHaveAttribute('open', '');
    await expect(page.locator('#strategy-gap-analysis')).toContainText('Run Strategy Gap Analysis');
    await expectMarketingAssistantsFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-assistants-desktop.png'), fullPage: false });
  });

  test('mobile assistants page stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_assistants.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Professional Marketer');
    await expect(page.locator('.marketing-assistants-summary')).toBeVisible();
    await expect(page.locator('.marketing-assistant-card').first()).toBeVisible();
    await expect(page.locator('.marketing-assistants-today')).toBeVisible();
    await expect(page.locator('.marketing-assistants-run-panel')).toBeVisible();
    await expect(page.locator('details.marketing-assistants-tools').first()).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingAssistantsFits(page);

    const firstCard = await page.locator('.marketing-assistant-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-assistants-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing admin visual maturity', () => {
  test('desktop admin page is a visual health board with drawer-gated diagnostics', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_admin.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_admin\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Admin Diagnostics');
    await expect(page.locator('.marketing-page-header')).toContainText('Keep Marketing safe');
    await expect(page.locator('.marketing-admin-summary')).toBeVisible();
    await expect(page.locator('.marketing-admin-layout')).toBeVisible();
    await expect(page.locator('.marketing-admin-board')).toContainText('Admin Health Board');
    await expect(page.locator('.marketing-admin-today')).toBeVisible();
    await expect(page.locator('.marketing-admin-footprint')).toBeVisible();
    await expect(page.locator('details.marketing-admin-tools')).toHaveCount(2);
    await expect(page.locator('details.marketing-admin-tools').first()).not.toHaveAttribute('open', '');
    await expect(page.locator('details.marketing-admin-tools').last()).not.toHaveAttribute('open', '');
    await expect(page.locator('.admin-grid')).toHaveCount(0);
    await expect(page.locator('.admin-stats')).toHaveCount(0);
    await expect(page.locator('.qa-check-grid')).toHaveCount(0);
    await expect(page.locator('.workflow-admin-lanes')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('Review production readiness, audit history');
    await expectMarketingAdminFits(page);

    const healthCardCount = await page.locator('.marketing-admin-health-card').count();
    expect(healthCardCount).toBe(6);
    const cardActions = await page.locator('.marketing-admin-health-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-admin-health-action').length)
    );
    cardActions.forEach((count) => expect(count).toBe(1));

    const todayActionCount = await page.locator('.marketing-admin-today-action').count();
    expect(todayActionCount).toBeGreaterThan(0);
    expect(todayActionCount).toBeLessThanOrEqual(5);

    const firstCard = page.locator('.marketing-admin-health-card').first();
    await expectTooltipVisible(firstCard);

    await page.locator('details.marketing-admin-tools > summary').first().click();
    await expect(page.locator('#admin-diagnostics')).toHaveAttribute('open', '');
    await expect(page.locator('#admin-diagnostics')).toContainText('Marketing QA Console');
    await expect(page.locator('#admin-diagnostics')).toContainText('No-secret diagnostics');
    await expect(page.locator('#admin-diagnostics')).toContainText('Creative Prompt Registry');
    await expect(page.locator('#admin-diagnostics')).toContainText('Operating Loop Prompts');
    await expect(page.locator('#admin-diagnostics')).toContainText('Integration Readiness');
    await expectMarketingAdminFits(page);

    await page.locator('details.marketing-admin-tools > summary').last().click();
    await expect(page.locator('#admin-actions')).toHaveAttribute('open', '');
    await expect(page.locator('#admin-actions')).toContainText('Manager-only controls for demo/starter data');
    await expect(page.locator('#admin-actions')).toContainText('No hard delete');
    await expect(page.locator('#admin-actions')).toContainText('Report Exports');
    await expectMarketingAdminFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-admin-desktop.png'), fullPage: false });
  });

  test('mobile admin page stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_admin.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Admin Diagnostics');
    await expect(page.locator('.marketing-admin-summary')).toBeVisible();
    await expect(page.locator('.marketing-admin-health-card').first()).toBeVisible();
    await expect(page.locator('.marketing-admin-today')).toBeVisible();
    await expect(page.locator('details.marketing-admin-tools').first()).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingAdminFits(page);

    const firstCard = await page.locator('.marketing-admin-health-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-admin-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing execution visual maturity', () => {
  test('desktop execution center is a visual execution map with drawer-gated machinery', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_execution.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_execution\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Execution Control Center');
    await expect(page.locator('.marketing-page-header')).toContainText('Move campaigns from ready to done');
    await expect(page.locator('.marketing-execution-summary')).toBeVisible();
    await expect(page.locator('.marketing-execution-layout')).toBeVisible();
    await expect(page.locator('.marketing-execution-board')).toContainText('Execution Map');
    await expect(page.locator('.marketing-execution-today')).toBeVisible();
    await expect(page.locator('details.marketing-execution-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('body')).not.toContainText('See what is ready, blocked, awaiting review');
    await expect(page.locator('.marketing-execution-page [style]')).toHaveCount(0);
    await expectMarketingExecutionFits(page);

    const cardCount = await page.locator('.marketing-execution-card').count();
    expect(cardCount).toBe(6);
    const cardActions = await page.locator('.marketing-execution-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-execution-card-action').length)
    );
    cardActions.forEach((count) => expect(count).toBe(1));

    const todayActionCount = await page.locator('.marketing-execution-today-action').count();
    expect(todayActionCount).toBeGreaterThan(0);
    expect(todayActionCount).toBeLessThanOrEqual(5);

    const firstCard = page.locator('.marketing-execution-card').first();
    await expectTooltipVisible(firstCard);

    await page.locator('details.marketing-execution-tools > summary').click();
    await expect(page.locator('details.marketing-execution-tools')).toHaveAttribute('open', '');
    await expect(page.locator('.marketing-execution-tools-body')).toContainText('Live Setup Wizard');
    await expect(page.locator('.marketing-execution-tools-body')).toContainText('Campaign Execution Console');
    await expect(page.locator('.marketing-execution-tools-body')).toContainText('Execution Safety Boundary');
    await expectMarketingExecutionFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-execution-desktop.png'), fullPage: false });
  });

  test('mobile execution center stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_execution.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Marketing Execution Control Center');
    await expect(page.locator('.marketing-execution-summary')).toBeVisible();
    await expect(page.locator('.marketing-execution-card').first()).toBeVisible();
    await expect(page.locator('.marketing-execution-today')).toBeVisible();
    await expect(page.locator('details.marketing-execution-tools')).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-execution-page [style]')).toHaveCount(0);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingExecutionFits(page);

    const firstCard = await page.locator('.marketing-execution-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-execution-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing content visual maturity', () => {
  test('desktop content studio is visual, low-text, and drawer-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_content.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_content\.php/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Content Studio');
    await expect(page.locator('.marketing-page-header')).toContainText('Choose the next piece to draft');
    await expect(page.getByRole('link', { name: /Command Center/i })).toHaveCount(0);
    await expect(page.getByRole('link', { name: /^Calendar$/i })).toHaveCount(0);
    await expect(page.locator('.marketing-content-summary')).toBeVisible();
    await expect(page.locator('.marketing-content-layout')).toBeVisible();
    await expect(page.locator('.marketing-content-board')).toContainText('Content Board');
    await expect(page.locator('.marketing-content-today')).toBeVisible();
    await expect(page.locator('details.marketing-content-tools')).toHaveCount(2);
    await expect(page.locator('details.marketing-content-tools').first()).not.toHaveAttribute('open', '');
    await expect(page.locator('details.marketing-content-tools').last()).not.toHaveAttribute('open', '');
    await expectMarketingContentFits(page);

    const cardCount = await page.locator('.marketing-content-stage-card').count();
    expect(cardCount).toBe(6);
    const cardActions = await page.locator('.marketing-content-stage-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-content-card-action').length)
    );
    cardActions.forEach((count) => expect(count).toBe(1));

    const todayActionCount = await page.locator('.marketing-content-today-action').count();
    expect(todayActionCount).toBeGreaterThan(0);
    expect(todayActionCount).toBeLessThanOrEqual(5);

    const firstCard = page.locator('.marketing-content-stage-card').first();
    await expectTooltipVisible(firstCard);

    await page.locator('#content-guidance > summary').click();
    await expect(page.locator('#content-guidance')).toHaveAttribute('open', '');
    await expect(page.locator('#content-guidance')).toContainText('Content next steps');
    await expect(page.locator('#content-guidance')).toContainText('Media Operational Readiness');
    await expectMarketingContentFits(page);

    await page.locator('#content-workbench > summary').click();
    await expect(page.locator('#content-workbench')).toHaveAttribute('open', '');
    await expect(page.locator('#content-workbench')).toContainText('Production Command Queue');
    await expect(page.locator('#content-workbench')).toContainText('Load guard active');
    await expectMarketingContentFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-content-desktop.png'), fullPage: false });
  });

  test('mobile content studio stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_content.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Content Studio');
    await expect(page.locator('.marketing-content-summary')).toBeVisible();
    await expect(page.locator('.marketing-content-stage-card').first()).toBeVisible();
    await expect(page.locator('.marketing-content-today')).toBeVisible();
    await expect(page.locator('details.marketing-content-tools').first()).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingContentFits(page);

    const firstCard = await page.locator('.marketing-content-stage-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-content-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing content builder visual maturity', () => {
  test('desktop content builder is visual, focused, and drawer-led', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    expect(contentViewItemId).toBeTruthy();
    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_content_edit.php?id=${contentViewItemId}`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_content_edit\.php\?id=/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Content Builder');
    await expect(page.locator('.marketing-page-header')).toContainText('Marketing Content: create one clear piece');
    await expect(page.locator('.marketing-content-edit-summary')).toBeVisible();
    await expect(page.locator('.marketing-content-edit-stage-grid')).toBeVisible();
    await expect(page.locator('.marketing-content-edit-card')).toHaveCount(6);
    await expect(page.locator('.marketing-content-edit-core')).toBeVisible();
    await expect(page.locator('.marketing-content-edit-draft')).toBeVisible();
    await expect(page.locator('.marketing-content-edit-today')).toBeVisible();
    await expect(page.locator('details.marketing-content-edit-tools')).toHaveCount(4);
    await expect(page.locator('details.marketing-content-edit-tools').first()).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-form-grid')).toHaveCount(0);
    await expectMarketingContentEditFits(page);

    const actionCounts = await page.locator('.marketing-content-edit-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-content-edit-card-action').length)
    );
    expect(actionCounts).toEqual([1, 1, 1, 1, 1, 1]);

    const todayCount = await page.locator('.marketing-content-edit-today-action').count();
    expect(todayCount).toBeGreaterThan(0);
    expect(todayCount).toBeLessThanOrEqual(5);

    const firstCard = page.locator('.marketing-content-edit-card').first();
    await expectTooltipVisible(firstCard);

    await page.getByRole('link', { name: 'Add media' }).click();
    await expect(page.locator('#content-media-tools')).toHaveAttribute('open', '');
    await expect(page.locator('#content-media-tools')).toContainText('Media Attachments');
    await expectMarketingContentEditFits(page);

    await page.getByRole('link', { name: 'Plan review' }).click();
    await expect(page.locator('#content-review-tools')).toHaveAttribute('open', '');
    await expect(page.locator('#content-review-tools')).toContainText('Approval Checklist');
    await expectMarketingContentEditFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.querySelectorAll('details[open]').forEach((detail) => detail.removeAttribute('open'));
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-content-builder-desktop.png'), fullPage: false });
  });

  test('mobile content builder stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    expect(contentViewItemId).toBeTruthy();
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_content_edit.php?id=${contentViewItemId}`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Content Builder');
    await expect(page.locator('.marketing-content-edit-summary')).toBeVisible();
    await expect(page.locator('.marketing-content-edit-card').first()).toBeVisible();
    await expect(page.locator('.marketing-content-edit-core')).toBeVisible();
    await expect(page.locator('.marketing-content-edit-today')).toBeVisible();
    await expect(page.locator('details.marketing-content-edit-tools').first()).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingContentEditFits(page);

    const firstCard = await page.locator('.marketing-content-edit-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.getByRole('link', { name: 'Add media' }).click();
    await expect(page.locator('#content-media-tools')).toHaveAttribute('open', '');
    await expectMarketingContentEditFits(page);

    await page.mouse.move(1, 1);
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-content-builder-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing content view visual maturity', () => {
  test('desktop content review board is visual, low-text, and drawer-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    expect(contentViewItemId).toBeTruthy();
    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_content_view.php?id=${contentViewItemId}`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_content_view\.php\?id=/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Visual Content Review Fixture');
    await expect(page.locator('.marketing-page-header')).toContainText('Review the draft, clear blockers');
    await expect(page.getByRole('link', { name: /Distribution/i })).toHaveCount(0);
    await expect(page.getByRole('link', { name: /UTM Links/i })).toHaveCount(0);
    await expect(page.locator('.marketing-content-view-summary')).toBeVisible();
    await expect(page.locator('.marketing-content-view-layout')).toBeVisible();
    await expect(page.locator('.marketing-content-view-board')).toContainText('Content Review Board');
    await expect(page.locator('.marketing-content-view-today')).toBeVisible();
    await expect(page.locator('details.marketing-content-view-tools')).toHaveCount(2);
    await expect(page.locator('details.marketing-content-view-tools').first()).not.toHaveAttribute('open', '');
    await expect(page.locator('details.marketing-content-view-tools').last()).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-content-view-page [style]')).toHaveCount(0);
    await expectMarketingContentViewFits(page);

    const cardCount = await page.locator('.marketing-content-view-card').count();
    expect(cardCount).toBe(6);
    const cardActions = await page.locator('.marketing-content-view-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-content-view-card-action').length)
    );
    cardActions.forEach((count) => expect(count).toBe(1));

    const todayActionCount = await page.locator('.marketing-content-view-today-action').count();
    expect(todayActionCount).toBeGreaterThan(0);
    expect(todayActionCount).toBeLessThanOrEqual(5);

    const firstCard = page.locator('.marketing-content-view-card').first();
    await expectTooltipVisible(firstCard);

    await page.locator('#content-evidence > summary').click();
    await expect(page.locator('#content-evidence')).toHaveAttribute('open', '');
    await expect(page.locator('#content-evidence')).toContainText('Draft Body');
    await expect(page.locator('#content-evidence')).toContainText('Attached Media');
    await expect(page.locator('#content-evidence')).toContainText('Content Production Pipeline');
    await expectMarketingContentViewFits(page);

    await page.locator('#content-review > summary').click();
    await expect(page.locator('#content-review')).toHaveAttribute('open', '');
    await expect(page.locator('#content-review')).toContainText('Content Creation Tools');
    await expect(page.locator('#content-review')).toContainText('Approvals');
    await expect(page.locator('#content-review')).toContainText('Comments');
    await expect(page.locator('#content-review')).toContainText('Versions');
    await expectMarketingContentViewFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-content-view-desktop.png'), fullPage: false });
  });

  test('mobile content review board stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    expect(contentViewItemId).toBeTruthy();
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_content_view.php?id=${contentViewItemId}`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Visual Content Review Fixture');
    await expect(page.locator('.marketing-content-view-summary')).toBeVisible();
    await expect(page.locator('.marketing-content-view-card').first()).toBeVisible();
    await expect(page.locator('.marketing-content-view-today')).toBeVisible();
    await expect(page.locator('details.marketing-content-view-tools').first()).not.toHaveAttribute('open', '');
    await expect(page.locator('.marketing-content-view-page [style]')).toHaveCount(0);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingContentViewFits(page);

    const firstCard = await page.locator('.marketing-content-view-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-content-view-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing landing page builder visual maturity', () => {
  test('desktop landing page builder is visual, focused, and drawer-led', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    expect(landingPageViewId).toBeTruthy();
    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_landing_page_edit.php?id=${landingPageViewId}`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_landing_page_edit\.php\?id=/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.design-studio-toolbar h1')).toHaveText('Visual Landing Review Fixture');
    await expect(page.locator('.design-studio-shell')).toBeVisible();
    await expect(page.locator('.design-library')).toBeVisible();
    await expect(page.locator('.design-stage')).toBeVisible();
    await expect(page.locator('.design-inspector')).toBeVisible();
    await expect(page.locator('.design-block-tile')).toHaveCount(12);
    expect(await page.locator('#designCanvas [data-block-id]').count()).toBeGreaterThan(0);
    await expect(page.locator('#designCanvasViewport')).toHaveClass(/is-desktop/);
    await expect(page.locator('#designValidity')).toBeVisible();
    await expectMarketingLandingEditFits(page);

    await page.locator('#designCanvas [data-block-id]').first().click();
    await expect(page.locator('#designInspectorTitle')).not.toHaveText('Page');
    await page.locator('[data-action="templates"]').click();
    await expect(page.locator('#designTemplateDialog')).toHaveAttribute('open', '');
    await expect(page.locator('#designTemplateList .design-template-card')).toHaveCount(8);
    await page.locator('#designTemplateDialog button[aria-label="Close"]').click();
    await page.locator('[data-action="validation"]').click();
    await expect(page.locator('#designValidationDialog')).toHaveAttribute('open', '');
    await expect(page.locator('#designValidationDetails')).not.toBeEmpty();
    await page.locator('#designValidationDialog button[aria-label="Close"]').click();
    await expectMarketingLandingEditFits(page);

    await page.mouse.move(1, 1);
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-landing-page-builder-desktop.png'), fullPage: false });
  });

  test('mobile landing page builder stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    expect(landingPageViewId).toBeTruthy();
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_landing_page_edit.php?id=${landingPageViewId}`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.design-studio-toolbar h1')).toHaveText('Visual Landing Review Fixture');
    await expect(page.locator('.design-stage')).toBeVisible();
    await expect(page.locator('.design-mobile-nav')).toBeVisible();
    await expect(page.locator('#designCanvasViewport')).toHaveClass(/is-mobile/);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingLandingEditFits(page);

    await page.locator('[data-mobile-panel="blocks"]').click();
    await expect(page.locator('[data-design-studio]')).toHaveClass(/has-library/);
    await expect(page.locator('.design-block-tile').first()).toBeVisible();
    await page.locator('[data-action="close-library"]').click();
    await page.locator('#designCanvas [data-block-id]').first().click();
    await expect(page.locator('[data-design-studio]')).toHaveClass(/has-inspector/);
    await expect(page.locator('#designInspectorTitle')).not.toHaveText('Page');
    await expectMarketingLandingEditFits(page);

    await page.mouse.move(1, 1);
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-landing-page-builder-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing landing page view visual maturity', () => {
  test('desktop destination review board is visual, low-text, and drawer-gated', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    expect(landingPageViewId).toBeTruthy();
    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_landing_page_view.php?id=${landingPageViewId}`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_landing_page_view\.php\?id=/);
    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Visual Landing Review Fixture');
    await expect(page.locator('.marketing-page-header')).toContainText('Review the destination, visuals');
    await expect(page.getByRole('link', { name: /UTM Links/i })).toHaveCount(0);
    await expect(page.getByRole('link', { name: /Distribution/i })).toHaveCount(0);
    await expect(page.locator('.marketing-landing-view-summary')).toBeVisible();
    await expect(page.locator('.marketing-landing-view-layout')).toBeVisible();
    await expect(page.locator('.marketing-landing-view-board')).toContainText('Destination Review Board');
    await expect(page.locator('.marketing-landing-view-today')).toBeVisible();
    await expect(page.locator('details.marketing-landing-view-tools')).toHaveCount(2);
    await expect(page.locator('details.marketing-landing-view-tools').first()).not.toHaveAttribute('open', '');
    await expect(page.locator('details.marketing-landing-view-tools').last()).not.toHaveAttribute('open', '');
    await expectMarketingLandingViewFits(page);

    const cardCount = await page.locator('.marketing-landing-view-card').count();
    expect(cardCount).toBe(6);
    const cardActions = await page.locator('.marketing-landing-view-card').evaluateAll((cards) =>
      cards.map((card) => card.querySelectorAll('.marketing-landing-view-card-action').length)
    );
    cardActions.forEach((count) => expect(count).toBe(1));

    const todayActionCount = await page.locator('.marketing-landing-view-today-action').count();
    expect(todayActionCount).toBeGreaterThan(0);
    expect(todayActionCount).toBeLessThanOrEqual(5);

    const firstCard = page.locator('.marketing-landing-view-card').first();
    await expectTooltipVisible(firstCard);

    await page.locator('#landing-evidence > summary').click();
    await expect(page.locator('#landing-evidence')).toHaveAttribute('open', '');
    await expect(page.locator('#landing-evidence')).toContainText('Landing Visual Readiness');
    await expect(page.locator('#landing-evidence')).toContainText('Publishing Foundation');
    await expect(page.locator('#landing-evidence')).toContainText('Destination Details');
    await expectMarketingLandingViewFits(page);

    await page.locator('#landing-preview > summary').click();
    await expect(page.locator('#landing-preview')).toHaveAttribute('open', '');
    await expect(page.locator('#landing-preview')).toContainText('Landing Page Preview');
    await expect(page.locator('#landing-preview')).toContainText('Version Snapshots');
    await expect(page.locator('#landing-preview')).toContainText('AI Visual Direction');
    await expectMarketingLandingViewFits(page);

    await page.mouse.move(1, 1);
    await page.evaluate(() => {
      document.activeElement?.blur();
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-landing-page-view-desktop.png'), fullPage: false });
  });

  test('mobile destination review board stacks without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    expect(landingPageViewId).toBeTruthy();
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_landing_page_view.php?id=${landingPageViewId}`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page.locator('.marketing-operator-strip')).toBeHidden();
    await expect(page.locator('.marketing-page-header h1')).toHaveText('Visual Landing Review Fixture');
    await expect(page.locator('.marketing-landing-view-summary')).toBeVisible();
    await expect(page.locator('.marketing-landing-view-card').first()).toBeVisible();
    await expect(page.locator('.marketing-landing-view-today')).toBeVisible();
    await expect(page.locator('details.marketing-landing-view-tools').first()).not.toHaveAttribute('open', '');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await expectMarketingLandingViewFits(page);

    const firstCard = await page.locator('.marketing-landing-view-card').first().boundingBox();
    expect(firstCard.width).toBeLessThanOrEqual(390);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-landing-page-view-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing landing page preview visual maturity', () => {
  test('desktop authenticated preview uses shared chrome without page-local clutter', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    expect(landingPagePreviewToken).toBeTruthy();
    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_landing_page_preview.php?token=${landingPagePreviewToken}&viewport=desktop`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_landing_page_preview\.php\?token=.*viewport=desktop/);
    await expect(page.locator('.marketing-landing-preview')).toHaveClass(/is-desktop/);
    await expect(page.locator('.landing-preview-shell')).toBeVisible();
    await expect(page.locator('.preview-banner')).toBeVisible();
    await expect(page.locator('.preview-banner')).toContainText('Authenticated preview only');
    await expect(page.locator('.preview-banner-actions a.is-active')).toHaveText('Desktop Preview');
    await expect(page.locator('.landing-hero h1')).toHaveText('A clearer destination for founder-led marketing');
    await expect(page.locator('.landing-section').first()).toContainText('Founder promise');
    await expect(page.locator('.cta-band')).toContainText('Book a quick review');
    await expect(page.locator('.landing-preview style')).toHaveCount(0);
    await expectMarketingLandingPreviewFits(page);

    const shell = await page.locator('.landing-preview-shell').boundingBox();
    expect(shell.width).toBeGreaterThan(900);
    expect(shell.width).toBeLessThanOrEqual(1120);

    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-landing-page-preview-desktop.png'), fullPage: false });
  });

  test('mobile authenticated preview keeps the visual page framed without overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    expect(landingPagePreviewToken).toBeTruthy();
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_landing_page_preview.php?token=${landingPagePreviewToken}&viewport=mobile`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_landing_page_preview\.php\?token=.*viewport=mobile/);
    await expect(page.locator('.marketing-landing-preview')).toHaveClass(/is-mobile/);
    await expect(page.locator('.landing-preview-shell')).toBeVisible();
    await expect(page.locator('.preview-banner-actions a.is-active')).toHaveText('Mobile Preview');
    await expect(page.locator('.landing-hero h1')).toHaveText('A clearer destination for founder-led marketing');
    await expect(page.locator('.cta-band')).toContainText('Book a quick review');
    await expect(page.locator('.landing-preview style')).toHaveCount(0);
    await expectMarketingLandingPreviewFits(page);

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    const shell = await page.locator('.landing-preview-shell').boundingBox();
    expect(shell.width).toBeLessThanOrEqual(390);

    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-landing-page-preview-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing public landing page visual maturity', () => {
  test('desktop published landing page uses the public visual stylesheet only', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    expect(landingPagePublicToken).toBeTruthy();
    await page.setViewportSize({ width: 1280, height: 720 });
    await page.goto(`${BASE_URL}/marketing_landing_public.php?token=${landingPagePublicToken}`);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_landing_public\.php\?token=/);
    await expect(page.locator('body.marketing-public-landing')).toBeVisible();
    await expect(page.locator('link[href*="marketing-public.css"]')).toHaveCount(1);
    await expect(page.locator('link[href*="marketing-ui.css"]')).toHaveCount(0);
    await expect(page.locator('style')).toHaveCount(0);
    await expect(page.locator('.marketing-public-shell')).toBeVisible();
    await expect(page.locator('.marketing-public-hero h1')).toHaveText('A clearer destination for founder-led marketing');
    await expect(page.locator('.marketing-public-section').first()).toContainText('Founder promise');
    await expect(page.locator('.marketing-public-cta')).toContainText('Book a quick review');
    await expect(page.locator('.marketing-operator-strip')).toHaveCount(0);
    await expectMarketingPublicLandingFits(page);

    const shell = await page.locator('.marketing-public-shell').boundingBox();
    expect(shell.width).toBeGreaterThan(1000);
    expect(shell.width).toBeLessThanOrEqual(1082);

    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-public-landing-desktop.png'), fullPage: false });
  });

  test('mobile published landing page stacks cleanly without authenticated chrome', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    expect(landingPagePublicToken).toBeTruthy();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${BASE_URL}/marketing_landing_public.php?token=${landingPagePublicToken}`);
    consoleErrors.length = 0;

    await expect(page.locator('body.marketing-public-landing')).toBeVisible();
    await expect(page.locator('link[href*="marketing-public.css"]')).toHaveCount(1);
    await expect(page.locator('link[href*="marketing-ui.css"]')).toHaveCount(0);
    await expect(page.locator('.marketing-public-hero h1')).toHaveText('A clearer destination for founder-led marketing');
    await expect(page.locator('.marketing-public-section').first()).toContainText('Founder promise');
    await expect(page.locator('.marketing-public-cta')).toContainText('Book a quick review');
    await expect(page.locator('.marketing-operator-strip')).toHaveCount(0);
    await expectMarketingPublicLandingFits(page);

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    const shell = await page.locator('.marketing-public-shell').boundingBox();
    expect(shell.width).toBeLessThanOrEqual(390);

    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-public-landing-mobile.png'), fullPage: false });
  });
});

test.describe('Marketing unsubscribe visual maturity', () => {
  test('desktop unsubscribe confirmation uses public card styling without authenticated chrome', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await page.goto(`${BASE_URL}/marketing_unsubscribe.php?t=visualtest`);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_unsubscribe\.php\?t=visualtest/);
    await expect(page.locator('body.marketing-public-preferences')).toBeVisible();
    await expect(page.locator('link[href*="marketing-public.css"]')).toHaveCount(1);
    await expect(page.locator('link[href*="marketing-ui.css"]')).toHaveCount(0);
    await expect(page.locator('style')).toHaveCount(0);
    await expect(page.locator('.marketing-public-preferences-card')).toBeVisible();
    await expect(page.locator('.marketing-public-preferences-status')).toHaveText('Confirmation required');
    await expect(page.locator('.marketing-public-preferences-card h1')).toHaveText('Manage email preferences');
    await expect(page.locator('.marketing-public-preferences-form button')).toHaveText('Unsubscribe me');
    await expect(page.locator('.marketing-public-preferences-muted')).toContainText('Opening this page does not unsubscribe you.');
    await expect(page.locator('.marketing-operator-strip')).toHaveCount(0);
    await expectMarketingUnsubscribeFits(page);

    const card = await page.locator('.marketing-public-preferences-card').boundingBox();
    expect(card.width).toBeLessThanOrEqual(520);

    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-unsubscribe-desktop.png'), fullPage: false });
  });

  test('mobile unsubscribe confirmation keeps the card centered without overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${BASE_URL}/marketing_unsubscribe.php?t=visualtest`);
    consoleErrors.length = 0;

    await expect(page.locator('body.marketing-public-preferences')).toBeVisible();
    await expect(page.locator('link[href*="marketing-public.css"]')).toHaveCount(1);
    await expect(page.locator('link[href*="marketing-ui.css"]')).toHaveCount(0);
    await expect(page.locator('.marketing-public-preferences-status')).toHaveText('Confirmation required');
    await expect(page.locator('.marketing-public-preferences-card h1')).toHaveText('Manage email preferences');
    await expect(page.locator('.marketing-public-preferences-form button')).toHaveText('Unsubscribe me');
    await expectMarketingUnsubscribeFits(page);

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    const card = await page.locator('.marketing-public-preferences-card').boundingBox();
    expect(card.width).toBeLessThanOrEqual(390);

    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-unsubscribe-mobile.png'), fullPage: false });
  });
});
