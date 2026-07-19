const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE_URL = 'http://localhost/crm/public';
const TEST_PASSWORD = 'test123456';
let fixture = null;

test.beforeAll(() => {
  fixture = ensureNurtureFixture();
});

function ensureNurtureFixture() {
  const suffix = `${Date.now()}${Math.floor(Math.random() * 100000)}`;
  const email = `nurture.visual.${suffix}@crm.local`;
  const code = `
    require_once getcwd() . '/vendor/autoload.php';
    require_once getcwd() . '/config/constants.php';
    CRM\\Database::init(require getcwd() . '/config/database.php');

    $email = '${email}';
    $password = '${TEST_PASSWORD}';

    foreach ([
      'nurture.read' => ['Read Nurture', 'View customer nurture profiles and care plans'],
      'nurture.write' => ['Write Nurture', 'Create customer nurture tasks and touchpoints'],
      'nurture.manage' => ['Manage Nurture', 'Manage customer care follow-up plans'],
      'marketing.read' => ['Read Marketing', 'View marketing command center and handoffs'],
      'marketing.write' => ['Write Marketing', 'Update marketing handoffs and campaigns'],
      'marketing.manage' => ['Manage Marketing', 'Manage marketing configuration'],
    ] as $permissionKey => $permissionCopy) {
      CRM\\Database::execute(
        "INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES (?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description)",
        [$permissionKey, $permissionCopy[0], $permissionCopy[1]]
      );
    }

    CRM\\Database::execute(
      "INSERT INTO role_permissions (role_id, permission_id, can_access)
       SELECT r.id, p.id, 1
       FROM roles r
       JOIN permissions p
       WHERE r.slug IN ('owner', 'sales')
         AND p.permission_key IN ('nurture.read', 'nurture.write', 'nurture.manage', 'marketing.read', 'marketing.write', 'marketing.manage')
       ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)"
    );

    CRM\\Database::execute(
      "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (UUID(), ?, ?, 'admin', NOW())",
      [$email, password_hash($password, PASSWORD_DEFAULT)]
    );
    $userId = (int) CRM\\Database::lastInsertId();
    CRM\\Authorization::assignUserRoleBySlug($userId, 'superadmin', $userId);

    $workspaceId = 1;
    (new CRM\\Services\\WorkspaceMembershipService())->addOrUpdateMembership($workspaceId, $userId, 'superadmin', true, $userId);
    CRM\\Authorization::assignWorkspaceUserRoleBySlug($workspaceId, $userId, 'superadmin', $userId);
    CRM\\Services\\WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'superadmin');
    CRM\\Session::set('user_id', $userId);

    (new CRM\\Modules\\UserStrategyProfile())->save($userId, [
      'target_market_focus' => 'Post-purchase operators',
      'ideal_customer_profile' => 'CRM teams that need reliable customer care transitions',
      'offer_angle' => 'Care continuity after a purchase',
      'segment_focus' => 'Founder-led service teams',
      'sales_motion' => 'Consultative sales',
      'deal_movement_strategy' => 'Move won customers into care with evidence',
      'outreach_posture' => 'Helpful, consent-aware follow-up',
      'positioning_notes' => 'Position nurture as customer success care after purchase evidence',
    ]);

    foreach ([
      'ai_coach' => ['ai_coach_enabled' => true, 'ai_coach' => ['enabled' => true]],
      'professional_marketer' => ['fixture' => 'nurture visual handoff gate'],
    ] as $skillKey => $config) {
      CRM\\Database::execute(
        "INSERT INTO workspace_skill_installs
            (workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at, disabled_at, uninstalled_at)
         VALUES (?, ?, 'installed', ?, ?, ?, NOW(), NULL, NULL)
         ON DUPLICATE KEY UPDATE
            status = 'installed',
            config_json = VALUES(config_json),
            updated_by_user_id = VALUES(updated_by_user_id),
            disabled_at = NULL,
            uninstalled_at = NULL,
            updated_at = NOW()",
        [$workspaceId, $skillKey, json_encode($config, JSON_UNESCAPED_SLASHES), $userId, $userId]
      );
    }

    CRM\\Database::execute(
      "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, company, stage, assigned_to, created_by, lead_score, engagement_score, metadata_json, created_at) VALUES (?, UUID(), 'Nora', 'Nurture', ?, 'Visual Success Co', 'won', ?, ?, 64, 58, ?, NOW())",
      [
        $workspaceId,
        'nora.nurture.${suffix}@example.com',
        $userId,
        $userId,
        json_encode([
          'source' => 'default_workspace_owner_contact',
          'default_workspace_contact_scope' => 'current_paying_customer',
          'current_paying_customer' => true,
          'owner_workspace_id' => $workspaceId,
        ], JSON_UNESCAPED_SLASHES),
      ]
    );
    $contactId = (int) CRM\\Database::lastInsertId();

    CRM\\Database::execute(
      "INSERT INTO deals (workspace_id, title, description, contact_id, assigned_to, created_by, stage, value, probability, actual_close_date, currency, created_at) VALUES (?, 'Visual customer package', 'Closed-won fixture for Customer Care.', ?, ?, ?, 'closed_won', 2400, 100, CURDATE(), 'USD', NOW())",
      [$workspaceId, $contactId, $userId, $userId]
    );

    $nurture = new CRM\\Modules\\Nurture();
    $nurture->getOrCreateProfile($contactId);
    $nurture->updateProfile($contactId, [
      'nurture_status' => 'needs_touch',
      'next_touch_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
      'next_touch_reason' => 'Customer check-in is due now.',
    ]);

    CRM\\Database::execute(
      "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, company, stage, assigned_to, created_by, lead_score, engagement_score, created_at) VALUES (?, UUID(), 'Parker', 'Prospect', ?, 'Visual Trial Co', 'qualified', ?, ?, 72, 66, NOW())",
      [$workspaceId, 'parker.prospect.${suffix}@example.com', $userId, $userId]
    );
    $prospectContactId = (int) CRM\\Database::lastInsertId();

    $renewalPlanName = 'Visual Renewal Care ${suffix}';
    $checkinPlanName = 'Visual Check-in Care ${suffix}';

    CRM\\Database::execute(
      "INSERT INTO nurture_programs (workspace_id, name, description, program_type, status, cadence, first_touch_delay_days, preferred_channel, default_touch_type, touch_guidance, created_by) VALUES (?, ?, 'Fixture program for visual QA.', 'customer_success', 'active', 'monthly', 14, 'whatsapp', 'renewal', 'Review value and renewal blockers.', ?)",
      [$workspaceId, $renewalPlanName, $userId]
    );
    $renewalProgramId = (int) CRM\\Database::lastInsertId();
    $nurture->enrollContacts($renewalProgramId, [$contactId]);

    CRM\\Database::execute(
      "INSERT INTO nurture_programs (workspace_id, name, description, program_type, status, cadence, first_touch_delay_days, preferred_channel, default_touch_type, touch_guidance, created_by) VALUES (?, ?, 'Unused fixture program for delete controls.', 'customer_success', 'active', 'monthly', 3, 'email', 'check_in', 'Confirm the next useful outcome.', ?)",
      [$workspaceId, $checkinPlanName, $userId]
    );

    foreach ([
      [$contactId, 'Visual customer package', 'Closed-won outreach source should open customer nurture.'],
      [$prospectContactId, null, 'Qualified outreach source still needs purchase evidence.'],
    ] as $handoffSeed) {
      CRM\\Database::execute(
        "INSERT INTO marketing_lead_handoffs
            (workspace_id, uuid, contact_id, deal_id, status, priority, assigned_to, source, handoff_note, sla_due_at, assigned_at, converted_at, metadata_json, created_by)
         VALUES (?, UUID(), ?, NULL, 'converted', 'high', ?, 'launch_campaign', ?, DATE_ADD(NOW(), INTERVAL 1 DAY), NOW(), NOW(), ?, ?)",
        [
          $workspaceId,
          (int) $handoffSeed[0],
          $userId,
          (string) $handoffSeed[2],
          json_encode(['source' => 'launch_campaign', 'campaign' => $handoffSeed[1] ?: 'Prospect offer'], JSON_UNESCAPED_SLASHES),
          $userId,
        ]
      );
      if (CRM\\Database::columnExists('marketing_lead_handoffs', 'sales_outcome')) {
        CRM\\Database::execute(
          "UPDATE marketing_lead_handoffs SET sales_outcome = 'converted', feedback_note = ?, feedback_by = ?, feedback_at = NOW() WHERE id = ?",
          [(string) $handoffSeed[2], $userId, (int) CRM\\Database::lastInsertId()]
        );
      }
    }

    echo json_encode([
      'email' => $email,
      'password' => $password,
      'workspace_id' => $workspaceId,
      'contact_id' => $contactId,
      'prospect_contact_id' => $prospectContactId,
      'renewal_plan_name' => $renewalPlanName,
      'checkin_plan_name' => $checkinPlanName,
    ], JSON_UNESCAPED_SLASHES);
  `;

  return JSON.parse(execFileSync('php', ['-r', code], {
    cwd: path.resolve(__dirname, '..'),
    encoding: 'utf8',
  }).trim());
}

async function login(page) {
  await page.goto(`${BASE_URL}/login.php`);
  await page.fill('input[name="email"]', fixture.email);
  await page.fill('input[name="password"]', fixture.password);
  await Promise.all([
    page.waitForURL((url) => !String(url).includes('login.php'), { timeout: 15000 }),
    page.click('button[type="submit"]'),
  ]);
}

async function dismissCookieBanner(page) {
  const accept = page.getByRole('button', { name: /Accept All/i });
  if (await accept.isVisible().catch(() => false)) {
    await accept.click();
  }
}

test.describe('Customer nurture visual maturity', () => {
  test('desktop nurture is a minimalist care queue with secondary controls', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 720 });
    await login(page);
    await page.goto(`${BASE_URL}/nurture.php?tab=all`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/nurture\.php/);
    await expect(page.locator('.nurture-workbench-header h1')).toHaveText('Customer Care');
    await expect(page.locator('.nurture-header-main > p')).toContainText(/customer needs a check-in today|customers need check-ins today|No customer check-ins due today|customer is in Customer Care|customers are in Customer Care/);
    await expect(page.getByRole('button', { name: /Follow-up Plans/i }).first()).toBeVisible();
    await expect(page.locator('[data-care-plan-drawer]')).toBeHidden();
    await page.getByRole('button', { name: /Follow-up Plans/i }).first().click();
    const drawer = page.locator('[data-care-plan-drawer]');
    await expect(drawer).toBeVisible();
    await expect(drawer.locator('select[name="plan_first_touch_delay_days"]')).toBeVisible();
    await expect(drawer.locator('select[name="plan_preferred_channel"]')).toBeVisible();
    await expect(drawer.locator('select[name="plan_default_touch_type"]')).toBeVisible();
    await expect(drawer.locator('textarea[name="plan_touch_guidance"]')).toBeVisible();
    await expect(drawer.locator('select[name="plan_preferred_channel"]')).toContainText('SMS');
    await page.locator('[data-care-plan-close]').click();
    await expect(drawer).toBeHidden();
    await expect(page.locator('.nurture-cockpit')).toHaveCount(0);
    await expect(page.locator('.nurture-summary-tile')).toHaveCount(0);
    await expect(page.locator('.nurture-stage-card')).toHaveCount(0);
    await expect(page.locator('.nurture-today')).toHaveCount(0);
    await expect(page.locator('.page-header.nurture-workbench-header')).toBeVisible();
    await expect(page.locator('.filters-card.nurture-filter-panel')).toBeVisible();
    await expect(page.locator('.stage-stats.nurture-tabs')).toBeVisible();
    await expect(page.locator('.table-card.nurture-queue-shell')).toBeVisible();
    await expect(page.locator('[data-nurture-care-queue]')).toBeVisible();
    await expect(page.locator('[data-nurture-care-row]').first()).toBeVisible();
    await expect(page.locator('.nurture-tabs')).toBeVisible();
    await expect(page.locator('.nurture-table-wrap')).toBeVisible();
    await expect(page.locator('.nurture-card-list')).toBeHidden();
    await expect(page.locator('[data-nurture-filter-toggle]')).toBeVisible();
    await expect(page.locator('.nurture-toolbar')).toBeHidden();
    await page.locator('[data-nurture-filter-toggle] > summary').click();
    await expect(page.locator('.nurture-toolbar')).toBeVisible();
    await expect(page.locator('#owner_user_id')).toBeVisible();
    await expect(page.locator('#nurture_status')).toBeVisible();
    await page.locator('[data-nurture-filter-toggle] > summary').click();
    await expect(page.locator('.nurture-toolbar')).toBeHidden();
    await expect(page.locator('.nurture-insights')).toHaveCount(0);
    await expect(page.locator('.nurture-programs-help')).toHaveCount(0);
    await expect(page.locator('[data-nurture-selection-hint]')).toBeHidden();
    await expect(page.locator('[data-nurture-selected-action-bar]')).toBeHidden();
    await expect(page.locator('[data-nurture-apply]')).toBeDisabled();
    await expect(page.locator('[data-nurture-program-control]')).toBeHidden();
    const queueBox = await page.locator('[data-nurture-care-queue]').boundingBox();
    const firstRowBox = await page.locator('.nurture-table-wrap [data-nurture-care-row]').first().boundingBox();
    expect(queueBox?.y ?? 9999).toBeLessThan(620);
    expect(firstRowBox?.y ?? 9999).toBeLessThanOrEqual(720);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    await Promise.all([
      page.waitForURL(/tab=dormant/, { waitUntil: 'commit' }),
      page.locator('.nurture-tabs a[href="?tab=dormant"]').click({ force: true }),
    ]);
    await expect(page.locator('.nurture-tab.active')).toContainText('Quiet');

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('nurture-desktop.png'), fullPage: false });
  });

  test('mobile nurture uses stacked customer cards without horizontal overflow', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    await page.goto(`${BASE_URL}/nurture.php?tab=all`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/nurture\.php/);
    await expect(page.locator('.nurture-workbench-header h1')).toHaveText('Customer Care');
    await expect(page.locator('.nurture-cockpit')).toHaveCount(0);
    await expect(page.locator('.filters-card.nurture-filter-panel')).toBeVisible();
    await expect(page.locator('.nurture-toolbar')).toBeHidden();
    await page.getByRole('button', { name: /Follow-up Plans/i }).first().click();
    const mobileDrawer = page.locator('[data-care-plan-drawer]');
    await expect(mobileDrawer).toBeVisible();
    await expect(mobileDrawer.locator('select[name="plan_first_touch_delay_days"]')).toBeVisible();
    await expect(mobileDrawer.locator('select[name="plan_preferred_channel"]')).toBeVisible();
    await expect(mobileDrawer.locator('select[name="plan_default_touch_type"]')).toBeVisible();
    const drawerOverflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(drawerOverflow).toBeLessThanOrEqual(2);
    await page.locator('[data-care-plan-close]').click();
    await expect(mobileDrawer).toBeHidden();
    await expect(page.locator('.stage-stats.nurture-tabs')).toBeVisible();
    await expect(page.locator('[data-nurture-care-queue]')).toBeVisible();
    await expect(page.locator('.nurture-stage-card')).toHaveCount(0);
    await expect(page.locator('.nurture-table-wrap')).toBeHidden();
    await expect(page.locator('.nurture-card-list')).toBeVisible();
    const mobileCheckbox = page.locator('.nurture-card-list [data-nurture-check]').first();
    const checkboxBox = await mobileCheckbox.boundingBox();
    expect(checkboxBox?.x ?? 9999).toBeGreaterThanOrEqual(0);
    expect((checkboxBox?.x ?? 0) + (checkboxBox?.width ?? 0)).toBeLessThanOrEqual(390);
    await mobileCheckbox.check();
    await expect(page.locator('[data-nurture-selection-count]')).toHaveText('1');
    await expect(page.locator('[data-nurture-selected-action-bar]')).toBeVisible();
    await expect(page.locator('[data-nurture-apply]')).toBeEnabled();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);

    await page.mouse.move(1, 1);
    await page.evaluate(() => document.activeElement?.blur());
    await page.waitForTimeout(350);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('nurture-mobile.png'), fullPage: false });
  });

  test('nurture detail renders purchase context and outreach lineage cleanly', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 800 });
    await login(page);
    await page.goto(`${BASE_URL}/nurture_view.php?contact_id=${fixture.contact_id}`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/nurture_view\.php/);
    await expect(page.getByRole('heading', { name: /Nora Nurture/i })).toBeVisible();
    await expect(page.getByText('Why this customer is here')).toBeVisible();
    await expect(page.locator('.nurture-readiness-card')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Suggested next step' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Purchase' })).toBeVisible();
    await expect(page.getByText('Where this came from')).toBeVisible();
    await expect(page.locator('[data-care-settings-panel]')).toHaveCount(1);
    await expect(page.locator('[data-care-settings-panel][open]')).toHaveCount(0);
    await expect(page.locator('.nurture-metric-grid')).toHaveCount(0);
    const mojibakeMiddleDot = String.fromCharCode(0x00c2, 0x00b7);
    await expect(page.locator('main')).not.toContainText(mojibakeMiddleDot);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('nurture-detail.png'), fullPage: false });
  });

  test('programs page renders clear cards and enrollment context without errors', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 760 });
    await login(page);
    await page.goto(`${BASE_URL}/nurture_programs.php`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/nurture_programs\.php/);
    await expect(page.getByRole('heading', { name: /Advanced Follow-up Plans/i })).toBeVisible();
    await expect(page.locator('.program-summary')).toBeVisible();
    await expect(page.locator('.program-summary-tile')).toHaveCount(3);
    await expect(page.getByText(fixture.renewal_plan_name).first()).toBeVisible();
    await expect(page.getByText(fixture.checkin_plan_name).first()).toBeVisible();
    const renewalPlanRow = page.locator('[data-follow-up-plan-row]').filter({ hasText: fixture.renewal_plan_name });
    await expect(renewalPlanRow).toHaveCount(1);
    await expect(renewalPlanRow).toContainText('First: 2 weeks');
    await expect(renewalPlanRow).toContainText('WhatsApp');
    await expect(renewalPlanRow).toContainText('Renewal review');
    await expect(page.getByText(/total enrollments/i).first()).toBeVisible();
    await expect(page.locator('[data-follow-up-plan-row]').first()).toBeVisible();
    await expect(page.locator('[data-follow-up-plan-edit]').first()).toBeVisible();
    await expect(page.locator('[data-follow-up-plan-archive]').first()).toBeVisible();
    await expect(page.locator('[data-follow-up-plan-delete]').first()).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('nurture-programs.png'), fullPage: false });
  });

  test('marketing handoffs show sales next step or customer nurture CTA', async ({ page }, testInfo) => {
    const consoleErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        const text = message.text();
        if (!text.includes('Error fetching notification count: TypeError: Failed to fetch')) {
          consoleErrors.push(text);
        }
      }
    });
    page.on('pageerror', (error) => consoleErrors.push(error.message));

    await page.setViewportSize({ width: 1280, height: 820 });
    await login(page);
    await page.goto(`${BASE_URL}/marketing_handoffs.php?status=converted&contact_id=${fixture.contact_id}`);
    await dismissCookieBanner(page);
    consoleErrors.length = 0;

    await expect(page).toHaveURL(/marketing_handoffs\.php\?status=converted&contact_id=/);
    await expect(page.getByRole('heading', { name: /Lead Handoffs/i })).toBeVisible();
    await expect(page.locator('.marketing-handoff-nurture')).toHaveCount(1);
    await expect(page.getByText('Open Customer Care').first()).toBeVisible();

    await page.goto(`${BASE_URL}/marketing_handoffs.php?status=converted&contact_id=${fixture.prospect_contact_id}`);
    await expect(page).toHaveURL(/marketing_handoffs\.php\?status=converted&contact_id=/);
    await expect(page.locator('.marketing-handoff-nurture')).toHaveCount(1);
    await expect(page.getByText('Finish sales handoff').first()).toBeVisible();
    await expect(page.getByText('Needs purchase evidence').first()).toBeVisible();
    expect(consoleErrors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('marketing-handoff-nurture-cta.png'), fullPage: false });
  });
});
