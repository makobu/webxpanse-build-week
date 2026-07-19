<?php
/**
 * Clarity CRM — Module 1 Product Walkthrough PDF Generator
 * Run: php generate_walkthrough.php
 * Output: module1_getting_oriented.pdf
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$SHOTS = __DIR__ . '/screenshots';
$OUT   = __DIR__ . '/module1_getting_oriented.pdf';

// ── Brand colours ─────────────────────────────────────────────────────────────
define('C_PURPLE',   [91,  71, 200]);   // primary brand purple
define('C_BLUE',     [59, 130, 246]);   // accent blue
define('C_DARK',     [30,  30,  40]);   // near-black text
define('C_MID',      [80,  80, 100]);   // secondary text
define('C_LIGHT',    [245, 246, 250]);  // page background tint
define('C_WHITE',    [255, 255, 255]);
define('C_RULE',     [220, 220, 230]);  // divider line

// ── Custom TCPDF subclass (header/footer) ────────────────────────────────────
class WalkthroughPDF extends TCPDF {

    private int $sectionNum = 0;
    private string $sectionTitle = '';

    public function setSection(int $n, string $title): void {
        $this->sectionNum   = $n;
        $this->sectionTitle = $title;
    }

    public function Header(): void {
        if ($this->PageNo() === 1) return; // no header on cover

        // Thin top bar
        $this->SetFillColor(...C_PURPLE);
        $this->Rect(0, 0, $this->getPageWidth(), 6, 'F');

        // Logo-ish mark
        $this->SetFont('helvetica', 'B', 8);
        $this->SetTextColor(...C_WHITE);
        $this->SetXY(8, 1);
        $this->Cell(50, 5, 'CLARITY CRM', 0, 0, 'L');

        // Section label right side
        if ($this->sectionTitle) {
            $label = "Module 1  ·  " . $this->sectionTitle;
            $this->SetXY(0, 1);
            $this->Cell($this->getPageWidth() - 8, 5, $label, 0, 0, 'R');
        }

        $this->SetTextColor(...C_DARK);
    }

    public function Footer(): void {
        if ($this->PageNo() === 1) return;

        $this->SetY(-12);
        $this->SetDrawColor(...C_RULE);
        $this->Line(10, $this->GetY(), $this->getPageWidth() - 10, $this->GetY());
        $this->SetFont('helvetica', '', 7);
        $this->SetTextColor(...C_MID);
        $this->SetX(10);
        $this->Cell(0, 8, 'Clarity CRM  —  Module 1: Getting Oriented  —  Confidential', 0, 0, 'L');
        $this->Cell(0, 8, 'Page ' . $this->PageNo(), 0, 0, 'R');
    }
}

// ── Instantiate PDF ───────────────────────────────────────────────────────────
$pdf = new WalkthroughPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Clarity CRM');
$pdf->SetAuthor('Clarity CRM Team');
$pdf->SetTitle('Module 1: Getting Oriented — Clarity CRM Walkthrough');
$pdf->SetSubject('Product Walkthrough');
$pdf->SetMargins(15, 18, 15);
$pdf->SetAutoPageBreak(true, 18);
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);

// ── Helper: section heading ────────────────────────────────────────────────────
function sectionHeading(WalkthroughPDF $pdf, int $num, string $title, string $subtitle = ''): void {
    $pdf->setSection($num, $title);
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->SetTextColor(...C_PURPLE);
    $pdf->MultiCell(0, 9, $title, 0, 'L', false, 1);
    if ($subtitle) {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(...C_MID);
        $pdf->MultiCell(0, 5, $subtitle, 0, 'L', false, 1);
    }
    // Divider
    $pdf->SetDrawColor(...C_PURPLE);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(15, $pdf->GetY() + 1, $pdf->getPageWidth() - 15, $pdf->GetY() + 1);
    $pdf->Ln(5);
    $pdf->SetTextColor(...C_DARK);
}

// ── Helper: add screenshot image ──────────────────────────────────────────────
function addScreenshot(WalkthroughPDF $pdf, string $path, string $caption = ''): void {
    if (!file_exists($path)) return;
    $pageW   = $pdf->getPageWidth() - 30; // left+right margins
    $maxH    = 75; // max height in mm to keep pages clean

    // Shadow box
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    $pdf->SetFillColor(...C_LIGHT);
    $pdf->SetDrawColor(...C_RULE);
    $pdf->SetLineWidth(0.3);
    $pdf->RoundedRect($x - 1, $y - 1, $pageW + 2, $maxH + 4, 2, '1111', 'DF');

    $pdf->Image($path, $x, $y, $pageW, 0, '', '', '', true, 300, '', false, false, 0, 'T', false, false);

    // Ensure we move past the image
    $pdf->SetY($y + $maxH + 5);
    if ($caption) {
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(...C_MID);
        $pdf->MultiCell(0, 4, $caption, 0, 'C', false, 1);
        $pdf->SetTextColor(...C_DARK);
    }
    $pdf->Ln(3);
}

// ── Helper: body paragraph ────────────────────────────────────────────────────
function bodyText(WalkthroughPDF $pdf, string $text): void {
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(...C_DARK);
    $pdf->MultiCell(0, 5.5, $text, 0, 'L', false, 1);
    $pdf->Ln(2);
}

// ── Helper: bullet list ───────────────────────────────────────────────────────
function bullets(WalkthroughPDF $pdf, array $items, string $header = ''): void {
    if ($header) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetTextColor(...C_DARK);
        $pdf->MultiCell(0, 5.5, $header, 0, 'L', false, 1);
    }
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(...C_MID);
    foreach ($items as $item) {
        $pdf->SetX(20);
        $pdf->MultiCell(0, 5.5, chr(149) . '  ' . $item, 0, 'L', false, 1);
    }
    $pdf->SetTextColor(...C_DARK);
    $pdf->Ln(2);
}

// ── Helper: info callout box ──────────────────────────────────────────────────
function callout(WalkthroughPDF $pdf, string $text): void {
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    $w = $pdf->getPageWidth() - 30;
    $pdf->SetFillColor(240, 238, 255);
    $pdf->SetDrawColor(...C_PURPLE);
    $pdf->SetLineWidth(0.4);
    $pdf->RoundedRect($x, $y, $w, 14, 2, '1111', 'DF');
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(...C_PURPLE);
    $pdf->SetXY($x + 4, $y + 2);
    $pdf->MultiCell($w - 8, 5, chr(0xE2).chr(0x84).chr(0xB9).'  ' . $text, 0, 'L', false, 1);
    $pdf->SetTextColor(...C_DARK);
    $pdf->Ln(4);
}

// ════════════════════════════════════════════════════════════════════════════════
// COVER PAGE
// ════════════════════════════════════════════════════════════════════════════════
$pdf->SetAutoPageBreak(false); // prevent overflow creating blank page 2
$pdf->AddPage();

// Full-page background
$pdf->SetFillColor(...C_LIGHT);
$pdf->Rect(0, 0, $pdf->getPageWidth(), $pdf->getPageHeight(), 'F');

// Top accent bar
$pdf->SetFillColor(...C_PURPLE);
$pdf->Rect(0, 0, $pdf->getPageWidth(), 8, 'F');

// Large purple circle decoration (top-right)
$pdf->SetFillColor(91, 71, 200, 20);
$pdf->SetAlpha(0.08);
$pdf->Circle(220, -10, 80, 0, 360, 'F');
$pdf->SetAlpha(1);

// Logo text
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(...C_WHITE);
$pdf->SetXY(10, 1.5);
$pdf->Cell(60, 5, 'CLARITY CRM', 0, 0, 'L');

// Module badge
$pdf->SetFillColor(...C_BLUE);
$pdf->SetTextColor(...C_WHITE);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetXY(70, 55);
$pdf->RoundedRect(70, 55, 45, 8, 3, '1111', 'F');
$pdf->SetXY(70, 56.5);
$pdf->Cell(45, 5, 'MODULE 1', 0, 0, 'C');

// Main title
$pdf->SetFont('helvetica', 'B', 36);
$pdf->SetTextColor(...C_DARK);
$pdf->SetXY(15, 67);
$pdf->MultiCell(170, 15, "Getting\nOriented", 0, 'L', false, 1);

// Subtitle
$pdf->SetFont('helvetica', '', 14);
$pdf->SetTextColor(...C_MID);
$pdf->SetX(15);
$pdf->MultiCell(150, 7, 'A practical guide to your Clarity CRM workspace — dashboards, navigation, AI tools, and day-one setup.', 0, 'L', false, 1);

// Divider
$pdf->Ln(6);
$pdf->SetDrawColor(...C_RULE);
$pdf->SetLineWidth(0.5);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(6);

// What you'll learn
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(...C_PURPLE);
$pdf->Cell(0, 6, 'WHAT YOU\'LL LEARN', 0, 1, 'L');
$pdf->Ln(2);

$chapters = [
    ['01', 'Dashboard Walkthrough',         'Understand every widget at a glance'],
    ['02', 'The Today View',                 'Your daily command centre'],
    ['03', 'Navigation',                     'How Contacts, Deals, Inbox, Tasks & AI connect'],
    ['04', 'Profile & Workspace Setup',      'Configure notifications, profile, and preferences'],
    ['05', 'Key Concepts',                   'Workspaces, assignments, and permissions'],
];

foreach ($chapters as $ch) {
    $y = $pdf->GetY();
    // Number badge
    $pdf->SetFillColor(...C_PURPLE);
    $pdf->SetTextColor(...C_WHITE);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->RoundedRect(15, $y, 10, 8, 2, '1111', 'F');
    $pdf->SetXY(15, $y + 1.5);
    $pdf->Cell(10, 5, $ch[0], 0, 0, 'C');

    // Title
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(...C_DARK);
    $pdf->SetXY(29, $y + 0.5);
    $pdf->Cell(80, 5, $ch[1], 0, 0, 'L');

    // Desc
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(...C_MID);
    $pdf->SetXY(29, $y + 5);
    $pdf->Cell(160, 4, $ch[2], 0, 1, 'L');
    $pdf->Ln(2);
}

// Bottom strip
$pdf->SetFillColor(...C_PURPLE);
$pdf->Rect(0, $pdf->getPageHeight() - 20, $pdf->getPageWidth(), 20, 'F');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(...C_WHITE);
$pdf->SetXY(15, $pdf->getPageHeight() - 13);
$pdf->Cell(0, 6, 'Clarity CRM  ·  Product Training  ·  ' . date('F Y'), 0, 0, 'L');
$pdf->Cell(0, 6, 'clarity.crm', 0, 0, 'R');

// ════════════════════════════════════════════════════════════════════════════════
// SECTION 1 — DASHBOARD WALKTHROUGH
// ════════════════════════════════════════════════════════════════════════════════
$pdf->SetAutoPageBreak(true, 18); // restore for content pages
$pdf->AddPage();
sectionHeading($pdf, 1, '1. Dashboard Walkthrough', 'What each section tells you at a glance');

bodyText($pdf, 'The Dashboard is your control room. Every time you log in, it gives you an instant read on where your pipeline, tasks, and team stand — without having to click into individual records.');

addScreenshot($pdf, "$SHOTS/01_dashboard_top.png", 'Dashboard — Welcome screen with live pipeline summary');

bullets($pdf, [
    'Automation Readiness card — shows how complete your AI/automation setup is and what\'s blocking full automation.',
    'Main Gaps to Fix — actionable checklist to move toward autonomous operation.',
    'Setup score (e.g. 2/5) — tells you which foundations are still missing.',
    'AI Auto Responder, Commercial Layer, Workflow & Deal Automation status — each block shows its current readiness mode.',
    'The "Viewing: My Dashboard" toggle lets admins switch between personal and team-wide views.',
], 'What the top section contains:');

callout($pdf, 'Tip: Use the Dashboard as your morning briefing. Check Automation Readiness first, then move to the metrics below.');

// Page 2 of dashboard section — metrics
$pdf->AddPage();
sectionHeading($pdf, 1, '1. Dashboard Walkthrough', 'Metrics, pipeline & activity feeds');

addScreenshot($pdf, "$SHOTS/03_dashboard_today.png", 'Dashboard — Metric cards: leads, emails, tasks, deals, pipeline');

bullets($pdf, [
    'Leads Today — new contacts or leads created today; tracks your top-of-funnel activity.',
    'Emails Sent / Emails Opened — real-time outreach volume and engagement rate.',
    'Form Submissions — inbound leads captured through embedded forms.',
    'My Tasks (Pending / Overdue) — personal task health at a glance.',
    'My Open Deals — count and total value of deals you own.',
    'Total Pipeline — combined value of all open opportunities.',
    'Total Contacts — size of your contact database.',
], 'Metric cards explained:');

bodyText($pdf, 'All metrics show a trend arrow and percentage change vs. the previous period, so you can see momentum — not just snapshots.');

// ════════════════════════════════════════════════════════════════════════════════
// SECTION 2 — THE TODAY VIEW
// ════════════════════════════════════════════════════════════════════════════════
$pdf->AddPage();
sectionHeading($pdf, 2, '2. The Today View', 'Your daily command centre');

bodyText($pdf, 'The Today View is your personalised daily agenda. Powered by AI, it surfaces the highest-value actions you should take right now — not just a list of everything that\'s due.');

addScreenshot($pdf, "$SHOTS/02_dashboard_metrics.png", 'Automation Readiness panel — the first thing to resolve before full autonomous operation');

bullets($pdf, [
    'Stale deals needing follow-up — deals not updated in 7+ days, ranked by value.',
    'Tasks due today — all pending tasks with today\'s due date.',
    'Open deals count and pipeline value — quick sanity check on active opportunities.',
    'AI-generated prompts — if the above are all clear, you\'ll be nudged to add new leads.',
], 'What the Today view surfaces:');

callout($pdf, 'How to use it: Open the Dashboard first thing in the morning. Work through the Today items in order — stale deals, then tasks — before checking email.');

bullets($pdf, [
    'Click any stale deal card to jump directly into that deal\'s activity feed.',
    'Task counts link directly to your filtered task list.',
    'The AI Coach button (purple) opens personalised coaching suggestions based on your recent activity.',
    'Automation Readiness blockers link to the exact setting page needed to resolve them.',
], 'Quick actions from the Today view:');

// ════════════════════════════════════════════════════════════════════════════════
// SECTION 3 — NAVIGATION
// ════════════════════════════════════════════════════════════════════════════════
$pdf->AddPage();
sectionHeading($pdf, 3, '3. Navigation', 'Contacts, Deals, Inbox, Tasks, AI — how they connect');

bodyText($pdf, 'Clarity CRM\'s navigation is built around a single top bar. Every module is reachable within one or two clicks, and they\'re deliberately connected — a contact powers a deal, a deal drives a task, a task triggers an automation.');

addScreenshot($pdf, "$SHOTS/04_navigation_bar.png", 'Top navigation bar — primary modules and utility menus');

bullets($pdf, [
    'Dashboard (house icon) — your daily start point with metrics and AI coaching.',
    'Contacts (people icon) — manage leads, customers, and inbound inquiries.',
    'Deals (handshake icon) — your sales pipeline, from proposal to close.',
    'Inbox (envelope icon) — unified inbox for email, WhatsApp, and SMS threads.',
    'Targets (bullseye icon) — set and track revenue and activity targets.',
    'Analytics dropdown (chart icon) — reports, predictive analytics, attribution.',
    'Communication dropdown (envelope with arrow) — bulk email, SMS, WhatsApp, templates.',
    'More (...) — tags, saved searches, webhooks, API keys.',
    'Admin (gear icon) — users, permissions, custom fields, workflows (admin only).',
    'Bell icon — in-app notifications with unread badge.',
    'Settings icon — workspace and personal settings.',
    '? icon — help docs and notification preferences.',
], 'Navigation bar items:');

$pdf->Ln(2);
callout($pdf, 'The modules are connected: Contacts feed Deals, Deals create Tasks, Tasks trigger Automations. Everything links back to a Contact record.');

$pdf->AddPage();
sectionHeading($pdf, 3, '3. Navigation', 'Module deep-dive');

// Contacts
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(...C_PURPLE);
$pdf->Cell(0, 6, 'Contacts', 0, 1, 'L');
$pdf->SetTextColor(...C_DARK);
addScreenshot($pdf, "$SHOTS/05_contacts.png", 'Contacts page — filterable list with stage, tag, and assignment views');
bullets($pdf, [
    'Filter by stage (New, Contacted, Qualified, Proposal, Negotiation, Won, Lost).',
    'Tag filter for custom segmentation.',
    'Assignment filter — "My Contacts" vs. "Unassigned" vs. all.',
    'Import/Export CSV and LaunchPad quick-add tools.',
], 'Key features:');

$pdf->AddPage();
sectionHeading($pdf, 3, '3. Navigation', 'Deals, Inbox & Tasks');

// Deals
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(...C_PURPLE);
$pdf->Cell(0, 6, 'Deals', 0, 1, 'L');
$pdf->SetTextColor(...C_DARK);
addScreenshot($pdf, "$SHOTS/06_deals.png", 'Deals page — pipeline view with automation readiness and stage tabs');
bullets($pdf, [
    'Pipeline tabs: All Deals, Proposal, Negotiation, Won, List view.',
    'Deal Automation Rollout panel shows current mode (Manual / Suggest Only / Auto).',
    'Create deals manually or let AI suggest deal creation from email threads.',
], 'Key features:');

$pdf->Ln(3);

// Inbox
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(...C_PURPLE);
$pdf->Cell(0, 6, 'Inbox', 0, 1, 'L');
$pdf->SetTextColor(...C_DARK);
addScreenshot($pdf, "$SHOTS/07_inbox.png", 'Inbox — unified multi-channel communication hub');
bullets($pdf, [
    'Aggregates email, WhatsApp, and SMS into one view.',
    'Filter by channel, status (active/resolved/archived), triage priority, and contact.',
    'Triage Status panel surfaces what needs attention vs. what\'s resolved.',
    'Connect WhatsApp and inbound email channels from Settings.',
], 'Key features:');

$pdf->AddPage();
sectionHeading($pdf, 3, '3. Navigation', 'Tasks & AI');

// Tasks
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(...C_PURPLE);
$pdf->Cell(0, 6, 'Tasks', 0, 1, 'L');
$pdf->SetTextColor(...C_DARK);
addScreenshot($pdf, "$SHOTS/08_tasks.png", 'Tasks page — status overview and AI starter task suggestions');
bullets($pdf, [
    'Status columns: Pending, In Progress, Completed, My Tasks, Overdue.',
    'Auto-complete scan — AI checks for tasks that can be marked done automatically.',
    'AI Starter Tasks panel suggests high-value onboarding actions based on your setup state.',
    'Due dates, assignees, and follow-up links to Contact and Deal records.',
], 'Key features:');

$pdf->Ln(3);

// AI
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(...C_PURPLE);
$pdf->Cell(0, 6, 'AI Control Center', 0, 1, 'L');
$pdf->SetTextColor(...C_DARK);
addScreenshot($pdf, "$SHOTS/09_ai_control_center.png", 'AI Control Center — runtime controls for all AI surfaces');
bullets($pdf, [
    'Emergency Presets — instantly switch all AI to Paused, Safe Mode, or Full Auto.',
    'Surface Controls — fine-tune AI behaviour per module (email assistant, task automation, etc.).',
    'Active Controls, Paused Surfaces, Suggest-Only Surfaces counters.',
    'Recent control changes log for audit visibility.',
    'Links to Learning Review, Cross-Domain Orchestration, Recovery Workbench, and Diagnostics.',
], 'Key features:');

// ════════════════════════════════════════════════════════════════════════════════
// SECTION 4 — PROFILE & WORKSPACE SETUP
// ════════════════════════════════════════════════════════════════════════════════
$pdf->AddPage();
sectionHeading($pdf, 4, '4. Profile, Workspace & Notifications', 'Getting your environment ready on day one');

bodyText($pdf, 'Before working in Clarity CRM, spend 10 minutes on setup. A complete profile and workspace configuration unlocks automation features and ensures you receive the right notifications.');

addScreenshot($pdf, "$SHOTS/10_settings.png", 'Settings hub — the central configuration page for your workspace');

bullets($pdf, [
    'Manual AI and automation control — set default automation mode (safe, suggest-only, or auto).',
    'Company Profile — name, logo, contact details (required for invoicing and email signatures).',
    'Email Configuration — connect your SMTP/IMAP so outbound emails send from your domain.',
    'WhatsApp Integration — connect a WhatsApp Business number for unified inbox.',
    'Billing & Payments — set up payment gateway for invoice generation.',
    'AI Coach settings — configure coaching frequency and focus areas.',
    'Automation Configuration — global defaults for workflow triggers and deal automation.',
], 'Settings tabs to complete first:');

callout($pdf, 'Completion order: Company Profile → Email Config → Product Pricing → Invoicing. These four unlock 80% of automation features.');

$pdf->AddPage();
sectionHeading($pdf, 4, '4. Profile, Workspace & Notifications', 'Notification preferences');

addScreenshot($pdf, "$SHOTS/11_notification_preferences.png", 'Notification Preferences — per-event control of email and in-app alerts');

bodyText($pdf, 'Notification Preferences lets you choose exactly which events trigger an email vs. an in-app notification — so you stay informed without inbox overload.');

bullets($pdf, [
    'Each notification type (Contact Created, Deal Updated, Task Due, etc.) has independent Email and In-App toggles.',
    'Access via the ? menu in the top-right navigation → Notification Preferences.',
    'Changes take effect immediately — no save button required.',
    'In-app notifications appear as a badge on the bell icon in the nav bar.',
], 'How to use it:');

$pdf->Ln(4);

// Profile setup tip
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(...C_DARK);
$pdf->Cell(0, 6, 'Setting up your user profile', 0, 1, 'L');
$pdf->Ln(1);
bodyText($pdf, 'Your user profile (name, avatar, email signature) is separate from workspace settings. Find it under Admin → Users → click your name, or via the user avatar menu in the top-right corner.');

bullets($pdf, [
    'Upload a profile photo — it appears on Contact records and in the team directory.',
    'Set your display name — shown in activity logs, task assignments, and email signatures.',
    'Configure your time zone — ensures due dates and event times display correctly.',
    'Enable 2FA (Admin → Settings → 2FA) for account security.',
], 'Profile checklist:');

// ════════════════════════════════════════════════════════════════════════════════
// SECTION 5 — KEY CONCEPTS
// ════════════════════════════════════════════════════════════════════════════════
$pdf->AddPage();
sectionHeading($pdf, 5, '5. Key Concepts', 'Workspaces, assignments, and permissions');

bodyText($pdf, 'Understanding three core concepts will help you get the most out of Clarity CRM from day one: how the workspace is structured, how records are assigned, and who can do what.');

// Workspaces
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(...C_PURPLE);
$pdf->Cell(0, 6, 'Workspaces', 0, 1, 'L');
$pdf->SetTextColor(...C_DARK);
bodyText($pdf, 'A Clarity CRM instance is a single workspace shared by your whole team. All contacts, deals, and tasks live in one shared database — but views and assignments let individuals focus on what\'s theirs.');

bullets($pdf, [
    'One workspace = one company installation. Multi-company setups use separate instances.',
    'All users share the same contact and deal database.',
    '"Viewing: My Dashboard" filters the dashboard to records assigned to you.',
    '"Viewing: All Users" gives managers a team-wide view (requires appropriate permissions).',
    'Departments can be created under Admin → Departments to group users by team.',
]);

// Assignments
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(...C_PURPLE);
$pdf->Ln(2);
$pdf->Cell(0, 6, 'Assignments', 0, 1, 'L');
$pdf->SetTextColor(...C_DARK);
bodyText($pdf, 'Almost every record in Clarity CRM can be assigned to a specific user. Assignments drive personalised views, automation routing, and accountability.');

bullets($pdf, [
    'Contacts — assigned to the rep responsible for the relationship.',
    'Deals — assigned to the deal owner (controls whose pipeline it appears in).',
    'Tasks — assigned to whoever is responsible for completing the action.',
    'Unassigned records are visible to all users but won\'t appear in personal dashboard views.',
    'Assignments can be changed at any time from the record\'s detail view.',
    'Bulk reassignment is available via the Admin → Users section.',
]);

// Permissions
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(...C_PURPLE);
$pdf->Ln(2);
$pdf->Cell(0, 6, 'Permissions & Access Profiles', 0, 1, 'L');
$pdf->SetTextColor(...C_DARK);
bodyText($pdf, 'Permissions control what each user can see and do. They are managed through Access Profiles — groups of permissions that can be assigned to individual users.');

bullets($pdf, [
    'Access Profiles are configured under Admin → Access Profiles.',
    'Each profile defines read, write, and delete rights per module (Contacts, Deals, Tasks, etc.).',
    'Typical profiles: Admin (full access), Manager (team-wide view, no settings), Sales Rep (own records only), Read Only.',
    'The Admin navigation dropdown is hidden from users without admin permissions.',
    'Bulk email, reporting, and AI controls each have separate permission flags.',
    'User roles are set when creating or editing a user under Admin → Users.',
]);

callout($pdf, 'Best practice: Create a "Sales Rep" profile with write access to Contacts, Deals, and Tasks but read-only on Analytics and no access to Admin. Assign it to all frontline users.');

// ════════════════════════════════════════════════════════════════════════════════
// QUICK REFERENCE PAGE
// ════════════════════════════════════════════════════════════════════════════════
$pdf->AddPage();
$pdf->setSection(0, 'Quick Reference');

// Background
$pdf->SetFillColor(...C_LIGHT);
$pdf->Rect(0, 6, $pdf->getPageWidth(), $pdf->getPageHeight() - 6, 'F');

$pdf->Ln(4);
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetTextColor(...C_PURPLE);
$pdf->Cell(0, 8, 'Module 1 — Quick Reference Card', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(...C_MID);
$pdf->Cell(0, 5, 'Tear out or bookmark this page for fast access to key URLs and tips.', 0, 1, 'C');
$pdf->Ln(4);

$pdf->SetDrawColor(...C_RULE);
$pdf->SetLineWidth(0.3);
$pdf->Line(15, $pdf->GetY(), $pdf->getPageWidth() - 15, $pdf->GetY());
$pdf->Ln(4);

// Two-column quick ref
$colW = ($pdf->getPageWidth() - 40) / 2;
$startX = 15;
$col2X  = $startX + $colW + 10;
$startY = $pdf->GetY();

// Left column
$pdf->SetXY($startX, $startY);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetTextColor(...C_DARK);
$pdf->Cell($colW, 6, 'KEY PAGES', 0, 1, 'L');

$pages = [
    ['Dashboard',              '/dashboard.php'],
    ['Contacts',               '/contacts.php'],
    ['Deals',                  '/deals.php'],
    ['Inbox',                  '/inbox.php'],
    ['Tasks',                  '/tasks.php'],
    ['Settings',               '/settings.php'],
    ['Notification Prefs',     '/notification_preferences.php'],
    ['AI Control Center',      '/ai_control_center.php'],
    ['Users (Admin)',          '/users.php'],
];

foreach ($pages as $p) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(...C_DARK);
    $pdf->SetX($startX);
    $pdf->Cell(40, 5, $p[0], 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(...C_BLUE);
    $pdf->Cell($colW - 40, 5, $p[1], 0, 1, 'L');
}

// Right column
$pdf->SetXY($col2X, $startY);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetTextColor(...C_DARK);
$pdf->Cell($colW, 6, 'DAY-ONE CHECKLIST', 0, 1, 'L');

$checklist = [
    'Complete Company Profile in Settings',
    'Configure SMTP email connection',
    'Upload your profile photo & set display name',
    'Set your time zone in user profile',
    'Configure Notification Preferences',
    'Check Automation Readiness on Dashboard',
    'Review & set your Access Profile',
    'Add or import your first Contacts',
    'Create your first Deal in the pipeline',
    'Enable 2FA for account security',
];

foreach ($checklist as $item) {
    $y = $pdf->GetY();
    $pdf->SetXY($col2X, $y);
    // Checkbox
    $pdf->SetDrawColor(...C_PURPLE);
    $pdf->SetLineWidth(0.4);
    $pdf->Rect($col2X, $y + 0.5, 4, 4, 'D');
    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(...C_DARK);
    $pdf->SetXY($col2X + 6, $y);
    $pdf->Cell($colW - 6, 5, $item, 0, 1, 'L');
}

// Bottom note
$pdf->SetY($pdf->getPageHeight() - 30);
$pdf->SetDrawColor(...C_RULE);
$pdf->Line(15, $pdf->GetY(), $pdf->getPageWidth() - 15, $pdf->GetY());
$pdf->Ln(4);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(...C_MID);
$pdf->MultiCell(0, 5, "Next: Module 2 covers working with Contacts in depth — importing, tagging, lead scoring, and pipeline stage management.", 0, 'C', false, 1);

// ── Output ────────────────────────────────────────────────────────────────────
$pdf->Output($OUT, 'F');
echo "PDF generated: $OUT\n";
