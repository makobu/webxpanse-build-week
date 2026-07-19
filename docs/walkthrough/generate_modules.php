<?php
/**
 * webXpanse — 8 Module PDF Generator
 * Run: php docs/walkthrough/generate_modules.php
 * Output: docs/walkthrough/pdfs/module1_*.pdf … module8_*.pdf
 */

require_once __DIR__ . '/../../vendor/autoload.php';

define('SHOTS',   __DIR__ . '/screenshots');
define('OUT_DIR', __DIR__ . '/pdfs');
if (!is_dir(OUT_DIR)) mkdir(OUT_DIR, 0755, true);

// ── Brand colours ─────────────────────────────────────────────────────────────
define('C_PURPLE',  [91,  71, 200]);
define('C_BLUE',    [59, 130, 246]);
define('C_DARK',    [22,  22,  35]);
define('C_MID',     [90,  90, 110]);
define('C_LIGHT',   [247, 247, 252]);
define('C_RULE',    [220, 220, 232]);
define('C_WHITE',   [255, 255, 255]);
define('C_TINT',    [240, 238, 255]);

// Column geometry
define('LX', 15);   // left column X
define('LW', 108);  // left column width
define('RX', 128);  // right column X
define('RW', 62);   // right column width
define('IMG_W', 60); // image width in right column

// ── Custom PDF class ──────────────────────────────────────────────────────────
class ModulePDF extends TCPDF {
    public string $moduleNum   = '';
    public string $moduleTitle = '';
    public string $sectionName = '';

    public function Header(): void {
        if ($this->PageNo() === 1) return;
        $this->SetFillColor(...C_PURPLE);
        $this->Rect(0, 0, $this->getPageWidth(), 6, 'F');
        $this->SetFont('helvetica', 'B', 7.5);
        $this->SetTextColor(...C_WHITE);
        $this->SetXY(8, 1.2);
        $this->Cell(50, 4, 'WEBXPANSE', 0, 0, 'L');
        if ($this->sectionName) {
            $this->SetXY(0, 1.2);
            $this->Cell($this->getPageWidth() - 8, 4,
                'Module ' . $this->moduleNum . '  ·  ' . $this->sectionName, 0, 0, 'R');
        }
        $this->SetTextColor(...C_DARK);
    }

    public function Footer(): void {
        if ($this->PageNo() === 1) return;
        $this->SetY(-12);
        $this->SetDrawColor(...C_RULE);
        $this->Line(15, $this->GetY(), $this->getPageWidth() - 15, $this->GetY());
        $this->SetFont('helvetica', '', 7);
        $this->SetTextColor(...C_MID);
        $this->SetX(15);
        $this->Cell(0, 8, 'webXpanse  ·  Module ' . $this->moduleNum . ': ' . $this->moduleTitle, 0, 0, 'L');
        $this->Cell(0, 8, 'Page ' . $this->PageNo(), 0, 0, 'R');
    }
}

// ── PDF factory ───────────────────────────────────────────────────────────────
function newPDF(string $num, string $title): ModulePDF {
    $pdf = new ModulePDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->moduleNum   = $num;
    $pdf->moduleTitle = $title;
    $pdf->SetCreator('webXpanse');
    $pdf->SetAuthor('webXpanse Team');
    $pdf->SetTitle("Module $num: $title");
    $pdf->SetMargins(15, 18, 15);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    return $pdf;
}

// ── Cover page ────────────────────────────────────────────────────────────────
function coverPage(ModulePDF $pdf, string $num, string $title, string $goal, array $topics): void {
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $W = $pdf->getPageWidth();
    $H = $pdf->getPageHeight();

    // Background
    $pdf->SetFillColor(...C_LIGHT);
    $pdf->Rect(0, 0, $W, $H, 'F');

    // Top bar
    $pdf->SetFillColor(...C_PURPLE);
    $pdf->Rect(0, 0, $W, 8, 'F');
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetTextColor(...C_WHITE);
    $pdf->SetXY(10, 2);
    $pdf->Cell(50, 4, 'WEBXPANSE', 0, 0, 'L');

    // Decorative right circle
    $pdf->SetFillColor(91, 71, 200);
    $pdf->SetAlpha(0.07);
    $pdf->Circle(220, 0, 80, 0, 360, 'F');
    $pdf->SetAlpha(1);

    // Module badge
    $pdf->SetFillColor(...C_BLUE);
    $pdf->RoundedRect(15, 28, 32, 8, 3, '1111', 'F');
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(...C_WHITE);
    $pdf->SetXY(15, 29.5);
    $pdf->Cell(32, 5, 'MODULE ' . $num, 0, 0, 'C');

    // Title
    $pdf->SetFont('helvetica', 'B', 30);
    $pdf->SetTextColor(...C_DARK);
    $pdf->SetXY(15, 40);
    $pdf->MultiCell(170, 13, $title, 0, 'L', false, 1);

    // Goal line
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->SetTextColor(...C_PURPLE);
    $pdf->SetX(15);
    $pdf->MultiCell(155, 5.5, 'Goal: ' . $goal, 0, 'L', false, 1);

    // Divider
    $pdf->Ln(4);
    $pdf->SetDrawColor(...C_RULE);
    $pdf->SetLineWidth(0.4);
    $pdf->Line(15, $pdf->GetY(), $W - 15, $pdf->GetY());
    $pdf->Ln(5);

    // Topics
    $pdf->SetFont('helvetica', 'B', 8.5);
    $pdf->SetTextColor(...C_PURPLE);
    $pdf->Cell(0, 5, 'IN THIS MODULE', 0, 1, 'L');
    $pdf->Ln(1);

    foreach ($topics as $i => $topic) {
        $y = $pdf->GetY();
        // Number badge
        $pdf->SetFillColor(...C_PURPLE);
        $pdf->RoundedRect(15, $y + 0.5, 8, 7, 1.5, '1111', 'F');
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetTextColor(...C_WHITE);
        $pdf->SetXY(15, $y + 1.5);
        $pdf->Cell(8, 5, sprintf('%02d', $i + 1), 0, 0, 'C');
        // Text
        $pdf->SetFont('helvetica', '', 9.5);
        $pdf->SetTextColor(...C_DARK);
        $pdf->SetXY(27, $y + 1);
        $pdf->MultiCell(155, 5.5, $topic, 0, 'L', false, 1);
        $pdf->Ln(1);
    }

    // Bottom strip
    $pdf->SetFillColor(...C_PURPLE);
    $pdf->Rect(0, $H - 16, $W, 16, 'F');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(...C_WHITE);
    $pdf->SetXY(15, $H - 10);
    $pdf->Cell(0, 5, 'webXpanse  ·  Product Training  ·  ' . date('F Y'), 0, 0, 'L');
    $pdf->Cell(0, 5, 'acrm.webxpanse.com', 0, 0, 'R');

    $pdf->SetAutoPageBreak(true, 18);
}

// ── Content row: text left, small screenshot right ────────────────────────────
function contentRow(
    ModulePDF $pdf,
    string $sectionTitle,
    string $body,
    array  $bullets,
    string $imagePath,
    string $caption,
    string $tip     = '',
    string $whyText = ''
): void {
    $pdf->sectionName = $sectionTitle;
    $startPage = $pdf->getPage();
    $y0 = $pdf->GetY();

    // ── LEFT COLUMN ───────────────────────────────────────────────────────────
    $pdf->SetXY(LX, $y0);

    // Section title
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(...C_PURPLE);
    $pdf->MultiCell(LW, 6.5, $sectionTitle, 0, 'L', false, 1);

    // Purple underline
    $lineY = $pdf->GetY();
    $pdf->SetDrawColor(...C_PURPLE);
    $pdf->SetLineWidth(0.4);
    $pdf->Line(LX, $lineY, LX + LW, $lineY);
    $pdf->SetY($lineY + 2);

    // Body
    if ($body) {
        $pdf->SetFont('helvetica', '', 9.5);
        $pdf->SetTextColor(...C_DARK);
        $pdf->SetX(LX);
        $pdf->MultiCell(LW, 5, $body, 0, 'L', false, 1);
        $pdf->Ln(1);
    }

    // Why this matters
    if ($whyText) {
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->SetTextColor(...C_PURPLE);
        $pdf->SetX(LX);
        $pdf->MultiCell(LW, 4.8, "\xE2\x96\xB8" . '  ' . $whyText, 0, 'L', false, 1);
        $pdf->Ln(1);
        $pdf->SetTextColor(...C_DARK);
    }

    // Bullets
    if ($bullets) {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(...C_MID);
        foreach ($bullets as $b) {
            $pdf->SetX(LX + 3);
            $pdf->MultiCell(LW - 3, 4.8, "\xC2\xB7" . '  ' . $b, 0, 'L', false, 1);
        }
        $pdf->SetTextColor(...C_DARK);
        $pdf->Ln(1);
    }

    // Tip box
    if ($tip) {
        $tY = $pdf->GetY();
        $pdf->SetFillColor(...C_TINT);
        $pdf->SetDrawColor(...C_PURPLE);
        $pdf->SetLineWidth(0.35);
        $pdf->RoundedRect(LX, $tY, LW, 12, 2, '1111', 'DF');
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->SetTextColor(...C_PURPLE);
        $pdf->SetXY(LX + 3, $tY + 1.5);
        $pdf->MultiCell(LW - 6, 4.5, "\xE2\x84\xB9" . '  ' . $tip, 0, 'L', false, 1);
        $pdf->SetY($tY + 14);
        $pdf->SetTextColor(...C_DARK);
    }

    $leftEndY = $pdf->GetY();

    // ── RIGHT COLUMN — image ──────────────────────────────────────────────────
    if ($imagePath && file_exists($imagePath)) {
        [$iw, $ih] = getimagesize($imagePath);
        $ratio = $ih / max(1, $iw);
        $imgH  = min(70, IMG_W * $ratio); // cap at 70mm, proportional

        // If a page break occurred during left-column rendering, anchor image
        // to the top of the current page rather than the now-stale $y0
        $imageY = ($pdf->getPage() > $startPage)
            ? $pdf->GetMargins()['top'] + 8   // top margin + header gap
            : $y0;

        // Light border box
        $pdf->SetFillColor(250, 250, 253);
        $pdf->SetDrawColor(...C_RULE);
        $pdf->SetLineWidth(0.25);
        $pdf->RoundedRect(RX - 1, $imageY - 1, RW + 1, $imgH + 8, 2, '1111', 'DF');

        // Embed at full source resolution (resize=false), constrained to IMG_W × $imgH
        $pdf->Image($imagePath, RX, $imageY, IMG_W, $imgH, '', '', '', false, 300, '', false, false, 0, false);

        // Caption
        $capY = $imageY + $imgH + 1.5;
        $pdf->SetXY(RX, $capY);
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->SetTextColor(...C_MID);
        $pdf->MultiCell(RW, 3.5, $caption, 0, 'C', false, 1);
        $pdf->SetTextColor(...C_DARK);
    }

    // Advance past whichever column was taller
    $pdf->SetY(max($leftEndY, $y0 + 10) + 5);
}

// ── Section divider ───────────────────────────────────────────────────────────
function sectionDivider(ModulePDF $pdf, string $label): void {
    $pdf->Ln(2);
    $pdf->SetFillColor(...C_PURPLE);
    $pdf->SetFont('helvetica', 'B', 7.5);
    $pdf->SetTextColor(...C_WHITE);
    $y = $pdf->GetY();
    $pdf->Rect(LX, $y, LW + RW + 5, 6, 'F');
    $pdf->SetXY(LX + 3, $y + 1.2);
    $pdf->Cell(0, 4, strtoupper($label), 0, 1, 'L');
    $pdf->SetTextColor(...C_DARK);
    $pdf->Ln(4);
}

// ── Exercise page ─────────────────────────────────────────────────────────────
function exercisePage(ModulePDF $pdf, string $title, array $steps, array $quickRef = []): void {
    $pdf->AddPage();
    $pdf->sectionName = 'Exercise';

    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetTextColor(...C_PURPLE);
    $pdf->MultiCell(0, 7, "\xE2\x9C\x8E" . '  ' . $title, 0, 'L', false, 1);
    $pdf->SetDrawColor(...C_PURPLE);
    $pdf->SetLineWidth(0.4);
    $pdf->Line(LX, $pdf->GetY(), $pdf->getPageWidth() - 15, $pdf->GetY());
    $pdf->Ln(5);

    // Steps
    $pdf->SetFont('helvetica', 'B', 9.5);
    $pdf->SetTextColor(...C_DARK);
    $pdf->Cell(0, 5, 'Step-by-step:', 0, 1, 'L');
    $pdf->Ln(1);

    foreach ($steps as $i => $step) {
        $y = $pdf->GetY();
        $pdf->SetFillColor(...C_PURPLE);
        $pdf->Circle(LX + 4, $y + 3.5, 3.5, 0, 360, 'F');
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetTextColor(...C_WHITE);
        $pdf->SetXY(LX + 1.3, $y + 1.5);
        $pdf->Cell(6, 4, $i + 1, 0, 0, 'C');

        $pdf->SetFont('helvetica', '', 9.5);
        $pdf->SetTextColor(...C_DARK);
        $pdf->SetXY(LX + 10, $y + 0.5);
        $pdf->MultiCell(155, 5.5, $step, 0, 'L', false, 1);
        $pdf->Ln(1);
    }

    if ($quickRef) {
        $pdf->Ln(3);
        $pdf->SetFillColor(...C_LIGHT);
        $pdf->SetDrawColor(...C_RULE);
        $pdf->SetLineWidth(0.3);
        $qY = $pdf->GetY();
        $h  = count($quickRef) * 6 + 10;
        $pdf->RoundedRect(LX, $qY, 170, $h, 2, '1111', 'DF');
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->SetTextColor(...C_PURPLE);
        $pdf->SetXY(LX + 4, $qY + 2);
        $pdf->Cell(0, 5, 'QUICK REFERENCE', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(...C_DARK);
        foreach ($quickRef as $ref) {
            $pdf->SetX(LX + 4);
            $pdf->MultiCell(160, 5.5, "\xE2\x80\xA2" . '  ' . $ref, 0, 'L', false, 1);
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// MODULE DEFINITIONS
// ══════════════════════════════════════════════════════════════════════════════
$M1 = SHOTS . '/Module 1/';
$M2 = SHOTS . '/Module 2/';
$M3 = SHOTS . '/Module 3/';
$M4 = SHOTS . '/Module 4/';
$M5 = SHOTS . '/Module 5/';
$M6 = SHOTS . '/Module 6/';
$M7 = SHOTS . '/Module 7/';
$M8 = SHOTS . '/Module 8/';

// ── MODULE 1: Getting Oriented ────────────────────────────────────────────────
$pdf = newPDF('1', 'Getting Oriented');
coverPage($pdf, '1', "Getting\nOriented",
    'Understand the platform, navigate confidently, and set up your daily workflow from day one.',
    [
        'The Dashboard — your real-time command centre',
        'The Today view — how Clarity focuses your day',
        'Navigation — Contacts, Deals, Inbox, Tasks, AI',
        'Setting up your profile, workspace & notifications',
        'Key concepts: workspaces, assignments, permissions',
    ]
);

$pdf->AddPage();
contentRow($pdf,
    'First: Entering the Platform',
    'webXpanse starts at the login screen at acrm.webxpanse.com. Once you sign in, you land on the Dashboard — a live snapshot of everything happening in your business right now.',
    ['Use your email and password to log in', 'The "Welcome Back" screen confirms your dashboard is loading', 'The orange battery indicator shows your Automation Readiness score'],
    $M1 . '{276F8D8A-B4BA-4A06-B055-A4D7A895B15C}.png',
    'Login at acrm.webxpanse.com',
    'Bookmark the login URL — it\'s your daily starting point.',
    'Automation Readiness tells you how much of the system is actively working for you vs. sitting idle.'
);

contentRow($pdf,
    'Dashboard — The Control Room',
    'The Dashboard gives you an instant read on your pipeline, tasks, and outreach — without opening a single record. Every number here is live.',
    [
        'Leads Today — new contacts captured from all sources',
        'Emails Sent / Opened — outreach volume and engagement rate',
        'My Tasks — pending actions assigned to you',
        'My Open Deals — deals you own and their combined value',
        'Total Pipeline — full value of all open opportunities',
        'Total Contacts — size of your database',
    ],
    $M1 . '{0BABE67F-B303-48E3-80DB-7A91E3924068}.png',
    'Metric cards — all numbers update in real time',
    '',
    'If all metrics are zero, your first priority is to import contacts and create at least one deal to activate the dashboard.'
);

$pdf->AddPage();
contentRow($pdf,
    'The Today View — Revenue Focus',
    'Every morning, Clarity (your AI co-pilot) analyses your pipeline and tells you exactly what to do. This is the most important 30 seconds of your day.',
    [
        'Open the Clarity chat (blue bubble, bottom-right corner)',
        'Read "Today\'s Revenue Focus" — it shows stale deals, overdue tasks, and pipeline gaps',
        'Act on the list in order — highest-value actions first',
        'Ask Clarity a question: "What should I prioritise today?"',
    ],
    $M1 . '{EAAB89E5-8202-49C1-A49E-6B7627404378}.png',
    'Clarity AI — Today\'s Revenue Focus message',
    '',
    'The Today brief is AI-generated from your live data. If it says "add 5 new leads" — your pipeline needs refilling.'
);

contentRow($pdf,
    'Navigation — How Everything Connects',
    'The top navigation bar links every module. They share data: a Contact becomes a Deal, a Deal creates Tasks, Tasks trigger Automations. Nothing lives in isolation.',
    [
        'Dashboard (house) — daily metrics and AI coaching',
        'Contacts (people) — your leads and customers',
        'Deals (handshake) — your sales pipeline',
        'Inbox (envelope) — all conversations in one place',
        'Targets (bullseye) — revenue and activity goals',
        'Analytics dropdown — reports and attribution',
        'Settings (gear) — workspace and profile configuration',
        'Bell — notifications and alerts',
    ],
    $M1 . '{FC3C7ACE-8868-4E62-8A54-408A34CC6AD0}.png',
    'Top navigation — full workspace in one bar'
);

$pdf->AddPage();
contentRow($pdf,
    'AI Coach — Your Strategic Advisor',
    'The AI Coach is separate from the daily Clarity chat. It helps you define your strategy — target market, offer positioning, and deal movement approach. Set it up once; it improves AI suggestions across the platform.',
    [
        'Recommendations — proactive suggestions based on your pipeline state',
        'Idea Validation — test your market assumptions before scaling',
        'Strategy — define your ICP, offer angle, and sales motion',
    ],
    $M1 . '{9109EF5F-B856-41DB-87A3-45428D5A0963}.png',
    'AI Coach — Recommendations tab',
    'Fill in the Strategy tab first. It personalises every AI output in the system.',
    'A contact\'s pain point feeds the AI Coach. The more context you log, the smarter the suggestions.'
);

contentRow($pdf,
    'Dashboard Widgets — Activity & Pipeline',
    'Below the metric cards, the Dashboard shows Recent Activities, Recent Contacts, Upcoming Events, and Stage Distribution. These feed your weekly review routine.',
    [
        'Recent Activities — last 10 logged interactions (calls, emails, notes)',
        'Stage Distribution — how many contacts sit at each pipeline stage',
        'Deal Value by Stage — where your revenue is concentrated',
        'Upcoming Events — calendar reminders linked to contacts',
        'Recent Contacts — last 10 contacts added or updated',
    ],
    $M1 . '{6BEFBD0C-7E29-4C55-B7F9-C4D5228D615D}.png',
    'Stage Distribution and Deal Value widgets'
);

exercisePage($pdf,
    'Module 1 Exercise — Getting Oriented',
    [
        'Log in at acrm.webxpanse.com and note your Automation Readiness score.',
        'Click through every item in the top navigation bar. Identify what each section is for.',
        'Open the Clarity chat (blue bubble). Read your Today\'s Revenue Focus message.',
        'Type one question into Clarity, e.g. "What should I work on first?"',
        'Open the AI Coach and fill in the Strategy tab (target market, ICP, offer angle).',
        'Go to Settings and confirm your email configuration is connected.',
        'Set your Notification Preferences — choose which events trigger email vs. in-app alerts.',
    ],
    [
        'Dashboard = your daily start point. Open it first, every morning.',
        'Clarity AI chat = Today\'s brief. Use it before checking email.',
        'Navigation connects everything — contacts → deals → tasks → automation.',
        'Settings URL: acrm.webxpanse.com/settings.php',
        'Notification Preferences: acrm.webxpanse.com/notification_preferences.php',
    ]
);

$pdf->Output(OUT_DIR . '/module1_getting_oriented.pdf', 'F');
echo "✓ Module 1\n";

// ── MODULE 2: Contacts ────────────────────────────────────────────────────────
$pdf = newPDF('2', 'Contacts — Your Most Valuable Asset');
coverPage($pdf, '2', "Contacts —\nYour Most\nValuable Asset",
    'Build, organise, and maintain a contact database that actually helps you close deals.',
    [
        'Adding contacts: manual, CSV import, and inbound capture',
        'Contact fields — what to fill in and what not to skip',
        'Stages, tags, and filters — organising for scale',
        'Activity timeline — every touch in one place',
        'AI insights: risk flags, next best actions, communication intelligence',
        'Company context — linked deals, tasks, and data quality',
    ]
);

$pdf->AddPage();
contentRow($pdf,
    'The Contacts List — Your Pipeline View',
    'The Contacts page is more than a directory. It shows every lead\'s stage, quality score, and last activity — so you can spot who needs attention without opening each record.',
    [
        'Stage tabs (New, Contacted, Qualified, Proposal, Negotiation, Won, Lost) — click to filter instantly',
        'Search by name, email, or company',
        'Tag filter — find contacts by segment or campaign',
        'Quality score — AI-calculated completeness and engagement rating',
        'Bulk actions — select multiple and reassign, tag, or export at once',
    ],
    $M2 . '{79B61CF6-65D5-47D5-BEC3-D11AAF1875E0}.png',
    'Contacts list — stage tabs, search, filter bar',
    '',
    'Contacts without a stage are invisible to pipeline reporting. Always assign a stage when adding a contact.'
);

contentRow($pdf,
    'Adding & Importing Contacts',
    'You can add contacts three ways. Manual is for one-off adds. CSV import is for bulk onboarding. Inbound capture happens automatically through forms, WhatsApp, or email replies.',
    [
        'New Contact button — fill in name, email, phone, company, stage',
        'Import CSV — maps your spreadsheet columns to system fields in 3 steps',
        'LaunchPad — guided bulk-add with validation and duplicate detection',
        'Inbound capture — new form submissions become contacts automatically',
        'Minimum required: first name + email OR phone (never skip both)',
    ],
    $M2 . '{4CC7B6A6-A490-475D-9DE5-0A15A220CB75}.png',
    'Contact list with quality scores and stage badges'
);

$pdf->AddPage();
contentRow($pdf,
    'Contact Fields — What to Fill In',
    'Incomplete contacts reduce AI accuracy and make targeting impossible. Fill these fields on every contact you add.',
    [
        'Email — required for outreach automation',
        'Phone — required for WhatsApp and call logging',
        'Company — connects this contact to company-level deals',
        'Stage — where they sit in your pipeline right now',
        'Lead Source — how they found you (form, referral, social, WhatsApp)',
        'Job Title — helps the AI tailor communication tone',
        'Location — enables regional filtering and timezone awareness',
    ],
    $M2 . '{737907B2-F084-4C94-AA12-3C6275183CE1}.png',
    'Contact detail — Contact & Company Information',
    'Use Quick Edit (right panel) to update fields without opening the full edit form — saves 30 seconds per contact.',
    'Missing fields = missing intelligence. The AI cannot suggest next actions without knowing role, company, or source.'
);

contentRow($pdf,
    'Quick Edit & Inline Updates',
    'The Quick Edit panel lets you update the most important fields without leaving the contact view. Use it after every call or meeting.',
    [
        'Update phone, company, role, and location inline',
        'Change stage with a single dropdown — no full page load',
        'Save with one click — change is reflected immediately in the list view',
        'Use it after every touchpoint: "Called → no answer → update to Contacted"',
    ],
    $M2 . '{A1E7D0B7-8F72-4D27-A0B0-791C8F5E0458}.png',
    'Quick Edit panel — inline field updates'
);

$pdf->AddPage();
contentRow($pdf,
    'Activity Timeline — Log Every Touch',
    'The Activity Timeline is the history of your relationship with a contact. Every email, call, note, and deal is visible in one scrollable feed. If it\'s not here, it didn\'t happen.',
    [
        'Email — sent and received messages linked to this contact',
        'WhatsApp — conversation thread history',
        'Notes — your written observations and follow-up context',
        'Deals — deal cards linked to this contact',
        'Tasks — actions assigned for this contact',
        'Meetings — calendar events and meeting notes',
    ],
    $M2 . '{15A35DAA-1A7D-408C-A5FC-A3F9D33B3450}.png',
    'Relationship Timeline — all channels in one view',
    'Log a note after every call. Future you (and your AI) will thank you.',
    'The timeline is what the AI reads to generate summaries, next actions, and risk flags.'
);

contentRow($pdf,
    'Adding Notes',
    'Notes are your private memory for each contact. Use them to capture what was said, what was promised, and what to do next. The rich text editor supports formatting, links, and private-only visibility.',
    [
        'Title is optional — leave blank for quick notes',
        'Tick "Private note" to hide it from teammates',
        'Reference deal names, objections, or timelines in the note body',
        'Notes appear in the Activity Timeline and are read by the AI for next-action suggestions',
    ],
    $M2 . '{93D8B91F-9ED7-4F79-882C-4232C9268127}.png',
    'Notes editor — rich text, private option'
);

$pdf->AddPage();
contentRow($pdf,
    'AI Insights — Risk Flags & Next Actions',
    'The AI scans each contact and surfaces two things: what\'s wrong and what to do about it. Check these panels before any outreach.',
    [
        'Risk Flags — "No response in 21 days", "Contact is stale", "Missing critical fields"',
        'Next Best Actions — AI-recommended next steps: re-engage, complete fields, schedule call',
        'Communication Intelligence — preferred channel, sentiment trend, objections, unresolved commitments',
        'AI Insights & Context — enrichment status and enriched data (company, role, social)',
    ],
    $M2 . '{CC6DB344-86E0-45AA-9922-58A7F6B2A14D}.png',
    'Risk Flags — stale and unresponsive contacts',
    '',
    'Risk flags don\'t fix themselves. A flag means you need to act within 24 hours or the contact goes cold permanently.'
);

contentRow($pdf,
    'Company & Account Context',
    'When a contact is linked to a company, you see all related contacts, shared deals, tasks, and a data quality score in one panel. This is where account-based selling lives.',
    [
        'Related Contacts — all people from the same company',
        'Shared Records — how many deals, invoices, and tasks link to this company',
        'Data Quality — completeness score, stale flag, duplicate warnings',
        'Missing fields flagged in red — click to complete them',
    ],
    $M2 . '{04BCC1DB-0CBC-4E82-87D9-7938AC874B50}.png',
    'Company & Account Context panel'
);

exercisePage($pdf,
    'Module 2 Exercise — Contacts',
    [
        'Go to Contacts → Import CSV. Download the sample template and fill in 20 contacts.',
        'Import the file. Review any errors in the import log and fix them.',
        'Use the Tag filter to segment your contacts: add a tag "ICP" to your best-fit leads.',
        'Open one contact. Fill in all missing fields shown in the Data Quality panel.',
        'Add a note to 5 contacts describing where they are in your sales process.',
        'Check the Risk Flags panel on 3 contacts. Take the suggested Next Best Action for each.',
        'Review the Communication Intelligence panel — note the preferred channel for each.',
    ],
    [
        'Minimum fields: name + email or phone + stage + lead source',
        'Stage must always be set — drives all pipeline reporting',
        'Log notes after every call or meeting — "if it\'s not in the CRM, it didn\'t happen"',
        'Risk flag = act within 24 hours or lose the lead',
        'Import URL: acrm.webxpanse.com/contacts_import.php',
    ]
);

$pdf->Output(OUT_DIR . '/module2_contacts.pdf', 'F');
echo "✓ Module 2\n";

// ── MODULE 3: Inbox ───────────────────────────────────────────────────────────
$pdf = newPDF('3', 'The Inbox — Every Conversation in One Place');
coverPage($pdf, '3', "The Inbox —\nEvery Conversation\nin One Place",
    'Never miss a message. Manage email, WhatsApp, and all channels from a single inbox with a clear triage habit.',
    [
        'What the Unified Inbox is and why it replaces your email tab',
        'Conversation statuses: Open, Unread, Resolved — building a triage habit',
        'Assigning conversations to teammates and filtering by channel',
        'Replying, using templates, and attaching files',
        'Connecting email and WhatsApp channels',
    ]
);

$pdf->AddPage();
contentRow($pdf,
    'The Unified Inbox',
    'The Inbox aggregates every customer conversation — email, WhatsApp, SMS — into one view, linked to the contact record. You never need to switch between your email client and the CRM again.',
    [
        'All channels in one list: email, WhatsApp, SMS',
        'Each thread is linked to a contact — click to see their full history',
        'Unread count shown as a badge on the Inbox nav icon',
        'Filter by channel, status, triage priority, or contact name',
        'Status shows: Open, Resolved, Archived',
    ],
    $M3 . '01_inbox_list.png',
    'Unified Inbox — all channels, one list',
    'Open the Inbox every morning before checking your regular email. This is now your primary communication dashboard.',
    'A unified inbox prevents conversations from falling through the cracks when colleagues are away or channels are missed.'
);

contentRow($pdf,
    'Triage: Open → Reply → Resolve',
    'The triage habit is simple: every conversation moves from Open to Resolved. If you replied and it needs a follow-up, it stays Open. If the thread is complete, mark it Resolved.',
    [
        'Open — needs your attention or is awaiting a reply',
        'Resolved — conversation complete, no action needed',
        'Archived — keep for records, remove from active view',
        'Unread — new message received, not yet viewed',
        'Priority labels — tag high-priority threads for urgent triage',
    ],
    $M3 . '02_inbox_filters.png',
    'Inbox filters — channel, status, triage priority'
);

$pdf->AddPage();
contentRow($pdf,
    'Replying and Using Templates',
    'When you open a conversation, you can reply directly from the Inbox. Use email templates to send polished, consistent messages in seconds — no copy-pasting.',
    [
        'Click a thread to open the conversation view',
        'Type a reply or click Templates to insert a saved response',
        'Attach files — proposals, invoices, or spec sheets',
        'Assign the conversation to a teammate if it needs handoff',
        'Mark as Resolved when the thread is closed',
    ],
    $M3 . '04_email_compose.png',
    'Email compose — reply, template, attachment'
);

contentRow($pdf,
    'Email Templates — Save Time on Every Reply',
    'Templates are pre-written messages you can insert with one click. Build a library of templates for your most common scenarios: intro, follow-up, proposal send, rejection handling.',
    [
        'Find templates at Communications → Email Templates',
        'Templates support merge fields: {first_name}, {company}, {deal_value}',
        'Create templates for: intro, follow-up, proposal, decline, re-engagement',
        'A good template saves 3–5 minutes per reply — 20+ replies/day = 1 hour saved',
    ],
    $M3 . '05_email_templates.png',
    'Email Templates library'
);

$pdf->AddPage();
contentRow($pdf,
    'Assigning & Filtering Conversations',
    'If you have a team, assign conversations to the right person. Use filters to focus your view on what matters to you right now.',
    [
        'Assign — transfer ownership to a teammate who can best handle it',
        'Filter by Channel — see only email, only WhatsApp, or only SMS',
        'Filter by Contact — see all threads from one company or person',
        'Filter by Status — isolate Open threads to work your triage queue',
        'Search — find a conversation by keyword or contact name',
    ],
    $M3 . '03_inbox_conversation.png',
    'Inbox — open conversation view'
);

exercisePage($pdf,
    'Module 3 Exercise — Inbox',
    [
        'Open the Inbox. Count your unread messages. Set a goal: zero unread by end of day.',
        'Open 5 conversations. For each: read, reply or note-to-self, then set status (Open/Resolved).',
        'Find a conversation that needs a teammate — assign it to them with a note.',
        'Use a template to reply to one conversation. Edit the merge fields before sending.',
        'Filter by WhatsApp channel. Review all WhatsApp threads and resolve the completed ones.',
        'Set up a triage habit: Open Inbox at 9am, 1pm, and 4pm each day.',
    ],
    [
        'Inbox = your primary communication hub. Check it before email.',
        'Triage habit: Open → Reply → Resolve. Nothing stays unread overnight.',
        'Templates save 3–5 min per reply. Build your library in the first week.',
        'Assign conversations instead of forwarding emails — keeps history linked.',
        'Inbox URL: acrm.webxpanse.com/inbox.php',
    ]
);

$pdf->Output(OUT_DIR . '/module3_inbox.pdf', 'F');
echo "✓ Module 3\n";

// ── MODULE 4: Deals ───────────────────────────────────────────────────────────
$pdf = newPDF('4', 'Deals — Running Your Pipeline');
coverPage($pdf, '4', "Deals —\nRunning Your\nPipeline",
    'Turn contacts into revenue by managing every deal through a clear, trackable pipeline.',
    [
        'Creating deals and linking them to contacts',
        'Pipeline stages — matching them to your actual sales process',
        'Deal cards: value, close date, assigned owner',
        'Moving deals and logging what happened at each stage',
        'Spotting stalled deals before they die',
    ]
);

$pdf->AddPage();
contentRow($pdf,
    'The Deals Pipeline',
    'The Deals page is your sales pipeline. Every deal moves left to right through stages. The goal is always: move deals forward or close them — never let them sit still.',
    [
        'All Deals — full pipeline overview with value totals',
        'Stage tabs: Prospecting, Qualification, Proposal, Negotiation, Closed Won/Lost',
        'Deal cards show: title, contact, value, expected close date, owner',
        'Automation Rollout panel — shows current AI deal automation mode',
        'List view — spreadsheet layout for sorting and bulk actions',
    ],
    $M4 . '01_deals_list.png',
    'Deals pipeline — stage tabs and deal cards',
    '',
    'A deal without a close date is a wish, not a plan. Always set an expected close date — it drives pipeline forecasting.'
);

contentRow($pdf,
    'Creating a Deal',
    'Create a deal the moment a contact shows serious buying intent. Link it to the contact so all activity, notes, and emails appear on both records.',
    [
        'Click + New Deal from the Deals page or from a contact record',
        'Title — be specific: "Skyline Phase 2 — 40 Windows" not "Window Deal"',
        'Value — enter your best estimate even if not confirmed',
        'Stage — where is this deal right now in your process?',
        'Expected Close Date — your best estimate of when money changes hands',
        'Assigned To — who owns this deal? (default: you)',
        'Link to Contact — connects all communication and activity history',
    ],
    $M4 . '03_deal_create.png',
    'Create Deal form — link to contact'
);

$pdf->AddPage();
contentRow($pdf,
    'Deal Detail — Full History',
    'The Deal detail view shows everything about this opportunity: the linked contact, deal value, all activities, notes, and tasks. This is where you manage the relationship.',
    [
        'Deal summary at top: value, stage, probability, close date',
        'Activity feed — all emails, calls, notes logged against this deal',
        'Tasks — actions attached to this deal with due dates',
        'Notes — your private deal observations and negotiation context',
        'Files — proposals, contracts, or quotes attached',
    ],
    $M4 . '04_deal_detail_top.png',
    'Deal detail — summary and activity'
);

contentRow($pdf,
    'Moving Deals & Logging Progress',
    'Every time you move a deal to the next stage, log what happened. This creates an audit trail and feeds the AI\'s deal intelligence — it learns what moves deals forward.',
    [
        'Change stage via the Stage dropdown on the deal detail',
        'After every stage change: add a note explaining why it moved',
        'Add a task: "Next action" so nothing falls through',
        'Update the expected close date if circumstances changed',
        'Log a call or email activity to record the conversation that caused the move',
    ],
    $M4 . '06_deals_proposal_stage.png',
    'Proposal stage — deals awaiting response',
    'Never move a deal without logging why. "Moved to Negotiation — verbal agreement on price, contract to follow" is the standard.',
    'Deals that move forward with logged reasons have 3x higher close rates than those moved without context.'
);

$pdf->AddPage();
contentRow($pdf,
    'Spotting Stalled Deals',
    'A stalled deal is a deal that hasn\'t been updated in 7+ days. The AI flags these on your Dashboard (Today\'s Revenue Focus). Deal recovery is your highest-ROI activity.',
    [
        'Check Dashboard daily for stale deals in the Today\'s Revenue Focus section',
        'A stale deal means: no email, no call, no note, no stage change in 7+ days',
        'Recovery action: send a direct re-engagement email or WhatsApp message',
        'If no response in 21 days: move to Lost or Closed — keep the pipeline clean',
        'Sort by Last Activity in list view to find all stale deals at once',
    ],
    $M4 . '05_deal_detail_activity.png',
    'Deal activity feed — logged calls, emails, notes'
);

exercisePage($pdf,
    'Module 4 Exercise — Deals',
    [
        'Create 3 deals linked to existing contacts. Set a realistic value and close date for each.',
        'Move each deal to a different stage: one to Proposal, one to Negotiation, one stays in Qualification.',
        'After each stage move, add a note: what happened that caused the deal to advance?',
        'Add a task to each deal: "Follow up in 3 days" with a due date.',
        'Sort deals by Last Activity. Identify any deals not touched in 7+ days.',
        'For each stale deal: send a re-engagement email from within the deal record.',
    ],
    [
        'Always link a deal to a contact — this connects all communication history.',
        'Title deals specifically: "Company — Product — Phase" format.',
        'Set expected close date on every deal — required for forecasting.',
        'Log a note after every stage move — "if it\'s not logged, it didn\'t happen".',
        'Stale = no update in 7 days. Check daily. Act immediately.',
        'Deals URL: acrm.webxpanse.com/deals.php',
    ]
);

$pdf->Output(OUT_DIR . '/module4_deals.pdf', 'F');
echo "✓ Module 4\n";

// ── MODULE 5: Tasks ───────────────────────────────────────────────────────────
$pdf = newPDF('5', 'Tasks — Nothing Falls Through the Cracks');
coverPage($pdf, '5', "Tasks —\nNothing Falls\nThrough the Cracks",
    'Use tasks to track every commitment you make — and ensure every follow-up actually happens.',
    [
        'Tasks vs. calendar events — when to use each',
        'Creating tasks from conversations, deals, and contacts',
        'Due dates, priorities, and assignments',
        'Your daily task view — start and end of day routine',
        'Managing overdue tasks before they pile up',
    ]
);

$pdf->AddPage();
contentRow($pdf,
    'The Tasks Dashboard',
    'The Tasks page shows all your actions in one place. Status cards at the top give you an instant health check: how many are pending, in progress, overdue, and completed today.',
    [
        'Pending — tasks not yet started',
        'In Progress — tasks actively being worked on',
        'Completed — done today (resets daily)',
        'My Tasks — only tasks assigned to you',
        'Overdue — tasks past their due date (red — act immediately)',
        'AI Starter Tasks — actions the system recommends based on your data gaps',
    ],
    $M5 . '01_tasks_list.png',
    'Tasks overview — status cards and list',
    'Check your overdue count first thing every morning. Zero overdue is the goal.',
    'An overdue task means you missed a commitment. Three or more overdue = your follow-up system has broken down.'
);

contentRow($pdf,
    'Creating Tasks',
    'Create a task any time you make a commitment or identify an action that needs to happen. Link it to the relevant contact or deal so context is always attached.',
    [
        'Title — be action-specific: "Call Amara re: pricing" not "Call"',
        'Due Date — always set one. Tasks without due dates never get done.',
        'Priority — Urgent/High for today, Medium/Low for this week',
        'Assigned To — you or a teammate',
        'Link to Contact — connects task to the contact\'s activity timeline',
        'Description — any context needed to complete the task',
    ],
    $M5 . '03_task_create.png',
    'Create Task form'
);

$pdf->AddPage();
contentRow($pdf,
    'Tasks vs. Calendar Events',
    'Tasks and events serve different purposes. Understanding the difference prevents double-booking and keeps your workflow clean.',
    [
        'Task — an action YOU need to do: "Send proposal", "Make follow-up call"',
        'Event — a scheduled time block with another person: "Demo call at 2pm"',
        'Use tasks for solo actions with a deadline',
        'Use events for meetings, calls, and appointments that go in your calendar',
        'Tasks appear on the Tasks page and Dashboard. Events appear in the Calendar.',
        'Link both tasks and events to the same contact for complete history',
    ],
    $M5 . '02_tasks_status_cards.png',
    'Task status cards — overdue highlighted in red'
);

contentRow($pdf,
    'Daily Task Routine',
    'Start and end every day with a quick task review. This 5-minute habit prevents things from falling through the cracks and keeps your pipeline moving.',
    [
        'MORNING (5 min): Open Tasks → check overdue first → work through "due today"',
        'As you complete tasks: mark them done immediately — don\'t batch it',
        'Create new tasks as commitments arise during the day',
        'EVENING (5 min): Review "due tomorrow" → adjust priorities if needed',
        'Any overdue tasks not resolved today: reschedule or escalate',
    ],
    $M5 . '04_task_detail.png',
    'Task detail — linked contact, due date, priority',
    '"Check tasks before checking email." Email is reactive. Tasks are proactive.'
);

exercisePage($pdf,
    'Module 5 Exercise — Tasks',
    [
        'Go to Tasks. Count your overdue tasks. Resolve or reschedule all of them right now.',
        'Create a 5-task follow-up sequence for one of your open deals:',
        '   Task 1: "Send proposal" — due today',
        '   Task 2: "Follow up call" — due in 3 days',
        '   Task 3: "Check in if no reply" — due in 7 days',
        '   Task 4: "Re-engagement email" — due in 14 days',
        '   Task 5: "Close or lost decision" — due in 21 days',
        'Link all 5 tasks to the deal\'s contact record.',
        'Set priorities: Task 1 = High, Task 2 = High, Tasks 3–5 = Medium.',
        'Tomorrow morning: open Tasks first before your email. Report back on what you found.',
    ],
    [
        'Tasks without due dates never get done. Always set a date.',
        'Link tasks to contacts — creates a complete relationship history.',
        'Overdue = broken commitment. Zero overdue is the daily goal.',
        'The task feed IS your CRM activity log. Use it.',
        'Tasks URL: acrm.webxpanse.com/tasks.php',
        'Create Task: acrm.webxpanse.com/task_create.php',
    ]
);

$pdf->Output(OUT_DIR . '/module5_tasks.pdf', 'F');
echo "✓ Module 5\n";

// ── MODULE 6: AI Features ─────────────────────────────────────────────────────
$pdf = newPDF('6', 'The AI Features — Work Smarter');
coverPage($pdf, '6', "The AI Features —\nWork Smarter",
    'Let the system handle the thinking so you can focus on relationships and decisions.',
    [
        'What the AI can and can\'t do — setting the right expectations',
        'AI Today brief — your daily briefing from Clarity',
        'Drafting emails and replies with AI assistance',
        'Contact summarisation — understand any contact in 10 seconds',
        'Top AI actions — letting the system surface what needs attention',
        'When to trust AI suggestions and when to override them',
    ]
);

$pdf->AddPage();
contentRow($pdf,
    'What AI Can and Can\'t Do',
    'The AI in webXpanse is a co-pilot, not an autopilot. It reads your data, suggests actions, drafts messages, and flags risks — but it doesn\'t replace your judgement on relationships and deals.',
    [
        'CAN: Draft emails based on deal context and contact history',
        'CAN: Flag stale contacts, overdue tasks, and at-risk deals',
        'CAN: Summarise a contact\'s full history in seconds',
        'CAN: Suggest next best actions based on pipeline signals',
        'CAN\'T: Replace a genuine conversation or read tone accurately every time',
        'CAN\'T: Make final decisions on deal terms or pricing',
        'RULE: Always read AI output before sending. Edit to sound like you.',
    ],
    $M6 . '01_ai_control_center.png',
    'AI Control Center — runtime settings',
    'AI saves time on drafting and surfacing insights. You save time for relationship-building and judgment calls.'
);

contentRow($pdf,
    'AI Control Center',
    'The AI Control Center is your master switch. Use it to set how aggressively the AI acts across different surfaces — from fully manual (you approve everything) to suggest-only or auto.',
    [
        'Emergency Presets: Pause All, Safe Mode, Full Auto',
        'Surface Controls: Email Assistant, Task Automation, Deal Automation',
        'Current mode shown per surface: Normal / Suggest Only / Paused',
        'Recent changes log — audit trail of who changed what and when',
        'Start in Suggest Only mode. Graduate to auto once you trust the outputs.',
    ],
    $M6 . '02_ai_surface_controls.png',
    'AI surface controls — per-module mode settings'
);

$pdf->AddPage();
contentRow($pdf,
    'Email Assistant — AI-Drafted Replies',
    'The Email Assistant helps you draft responses to conversations. It reads the full thread history and your contact\'s profile, then suggests a contextually appropriate reply.',
    [
        'Open a conversation in the Inbox or a contact\'s email history',
        'Click "Draft with AI" or use the compose toolbar',
        'Review the draft — it will sound like a template until you personalise it',
        'Edit: adjust tone, add specifics, remove anything generic',
        'NEVER send an AI draft without reading it. Always add one personal detail.',
    ],
    $M6 . '03_email_assistant.png',
    'Email Assistant capabilities — what it can draft',
    'Read before you send. An AI email that sounds robotic will damage trust. Add your voice.',
    'AI email drafts are a starting point, not a final product. Editing takes 60 seconds. Starting from scratch takes 5 minutes.'
);

contentRow($pdf,
    'Contact Summarisation',
    'When you open a contact you haven\'t spoken to in weeks, you need context fast. The AI Summary reads their full history — emails, notes, deals, calls — and gives you a 3-line brief.',
    [
        'Find the AI Insights & Context panel on any contact detail page',
        'Click "Generate Summary" to get a plain-language brief',
        'Summary includes: last interaction, current status, key context, suggested action',
        'Use before any call or meeting to re-establish context in 10 seconds',
        'Summary quality depends on logged activity — log more, get better summaries',
    ],
    $M6 . '04_ai_learning_review.png',
    'AI Learning Review — system intelligence building'
);

$pdf->AddPage();
contentRow($pdf,
    'Top AI Actions — Let the System Surface Priorities',
    'The AI continuously monitors your pipeline and surfaces the actions with the highest impact. These appear on the Dashboard Today view and in the Clarity chat.',
    [
        'Stale deals — flag deals not updated in 7+ days',
        'Overdue tasks — surface tasks past their due date',
        'Unresponsive contacts — contacts with no reply in 21+ days',
        'Pipeline gaps — alert when your pipeline drops below a healthy threshold',
        'Completion opportunities — tasks the AI can auto-complete based on detected actions',
    ],
    $M6 . '05_ai_diagnostics.png',
    'AI Diagnostics — blocked actions and incidents'
);

contentRow($pdf,
    'When to Trust AI vs. When to Override',
    'The AI is calibrated on your historical data. The more you log, the better it gets. Override when the AI recommendation doesn\'t match what you know about the relationship.',
    [
        'TRUST: Risk flags and stale contact alerts — the data doesn\'t lie',
        'TRUST: Email draft structure and tone for warm leads',
        'TRUST: Task suggestions based on deal stage and contact history',
        'OVERRIDE: Pricing suggestions that don\'t match your market knowledge',
        'OVERRIDE: Email tone when the relationship is unusually informal or sensitive',
        'OVERRIDE: Close date predictions when you have inside knowledge',
        'After overriding: log a note explaining why — it trains the AI',
    ],
    $M6 . '06_email_compose_ai.png',
    'Email compose — AI context for drafting'
);

exercisePage($pdf,
    'Module 6 Exercise — AI Features',
    [
        'Open the AI Control Center. Note your current mode per surface. Set Email Assistant to Suggest Only.',
        'Find a stalled deal on your Dashboard Today view (overdue 7+ days).',
        'Open the linked contact\'s record. Read the AI Risk Flags and Next Best Actions.',
        'Click "Draft with AI" for a follow-up email to this contact.',
        'Read the draft carefully. Edit it: add one specific personal detail, adjust tone.',
        'Send the email. Log a note: "Sent AI-assisted re-engagement email. Edited X and Y."',
        'Check the AI Diagnostics page. Note any blocked actions and resolve them.',
    ],
    [
        'AI = co-pilot, not autopilot. Always review before acting.',
        'Never send an AI email without editing. Add one personal detail minimum.',
        'Logging more = better AI. Notes, calls, emails all feed the model.',
        'Override with a note — this trains the AI for next time.',
        'AI Control Center: acrm.webxpanse.com/ai_control_center.php',
        'Email Assistant: acrm.webxpanse.com/email_assistant_capabilities.php',
    ]
);

$pdf->Output(OUT_DIR . '/module6_ai_features.pdf', 'F');
echo "✓ Module 6\n";

// ── MODULE 7: Notifications ───────────────────────────────────────────────────
$pdf = newPDF('7', 'Notifications & Staying on Top of Activity');
coverPage($pdf, '7', "Notifications &\nStaying on Top\nof Activity",
    'Get the right alerts at the right time — without notification overload.',
    [
        'Notification types: task due, new conversation, deal update',
        'Setting preferences so you see what matters',
        'Badge counts — what each number means',
        'Notifications screen vs. the Inbox — knowing where to look',
        'Avoiding notification fatigue',
    ]
);

$pdf->AddPage();
contentRow($pdf,
    'Notification Types',
    'webXpanse sends two kinds of notifications: in-app (bell badge) and email. You control exactly which events trigger which type.',
    [
        'Task Due — your task has reached its due date',
        'Task Overdue — a task is past its due date',
        'New Conversation — someone replied to your email or WhatsApp',
        'Deal Updated — a deal you own or follow changed stage',
        'New Contact — a form submission created a new lead',
        'Deal Won / Deal Lost — outcome events on your pipeline',
        'Mention — a teammate mentioned you in a note or activity',
    ],
    $M7 . '01_notifications_list.png',
    'Notifications screen — all alerts in one list',
    'The notifications screen is for catching up. The Inbox is for acting. Don\'t confuse the two.',
    'Too many notifications means you\'ll start ignoring them. Set only the ones you\'ll actually act on.'
);

contentRow($pdf,
    'Setting Notification Preferences',
    'Notification Preferences lets you choose per event type: email, in-app, both, or neither. Set these in week one and review monthly.',
    [
        'Access: ? icon in top nav → Notification Preferences',
        'Each event type has Email and In-App toggles — set independently',
        'Recommended in-app only: Task Due, New Conversation, Mention',
        'Recommended email + in-app: Task Overdue, New Lead, Deal Won',
        'Recommended off (to avoid noise): minor system updates, auto-log confirmations',
        'Changes take effect immediately — no save button needed',
    ],
    $M7 . '03_notification_prefs.png',
    'Notification Preferences — per-event toggles'
);

$pdf->AddPage();
contentRow($pdf,
    'Badge Counts — What the Numbers Mean',
    'The bell icon in the top navigation shows a red badge with your unread notification count. Other badges appear on different nav icons.',
    [
        'Bell badge — unread in-app notifications',
        'Number on Inbox icon — unread conversations',
        'Task count on Dashboard — pending + overdue tasks',
        'Goal: bell badge at zero by end of each day',
        'High badge counts signal: too many events, or too little response time',
    ],
    $M7 . '05_nav_bell_closeup.png',
    'Nav bell with notification badge count'
);

contentRow($pdf,
    'Notifications Screen vs. Inbox',
    'These are two different tools. Confusing them wastes time. Use each for its purpose.',
    [
        'Notifications Screen — a log of events: task alerts, deal changes, system events',
        'Inbox — a working tool: reply to messages, triage, assign, resolve',
        'Workflow: check Notifications to see what\'s new → go to Inbox to act on conversations',
        'Clear notifications by clicking them — they mark as read when opened',
        'Bulk mark-all-read if the count is too high — start fresh',
    ],
    $M7 . '02_notifications_scroll.png',
    'Notifications list — events with timestamps'
);

$pdf->AddPage();
contentRow($pdf,
    'Avoiding Notification Fatigue',
    'Notification fatigue happens when you receive so many alerts that you start ignoring them all — including important ones. Prevent it with a disciplined preference setup.',
    [
        'Rule 1: Only enable notifications for events you will act on within 24 hours',
        'Rule 2: If you\'ve ignored a notification type 3 times, disable it',
        'Rule 3: Check notifications at set times (9am, 1pm, 4pm) — not constantly',
        'Rule 4: Use the Inbox for conversations, not notifications',
        'Rule 5: Review your preferences monthly — needs change as your volume grows',
    ],
    $M7 . '04_notification_prefs_lower.png',
    'Notification preferences — lower event types'
);

exercisePage($pdf,
    'Module 7 Exercise — Notifications',
    [
        'Open Notification Preferences (? icon → Notification Preferences).',
        'Review every event type. For each: ask "Will I act on this within 24 hours?"',
        'Turn off any notification type you would realistically ignore.',
        'Enable email notifications for: Task Overdue and New Contact.',
        'Set in-app only for: Task Due, New Conversation, and Mention.',
        'Open the Notifications screen and clear all unread by clicking each one.',
        'Check your bell badge at 9am, 1pm, and 4pm for 3 days. Note your average count.',
    ],
    [
        'Notification Preferences URL: acrm.webxpanse.com/notification_preferences.php',
        'Notifications screen URL: acrm.webxpanse.com/notifications.php',
        'Bell = notification log. Inbox = action space. Use them differently.',
        'Zero unread bell by end of day = healthy notification discipline.',
        'If your badge is always >20, your preferences need a reset.',
    ]
);

$pdf->Output(OUT_DIR . '/module7_notifications.pdf', 'F');
echo "✓ Module 7\n";

// ── MODULE 8: Reporting & Optimising ─────────────────────────────────────────
$pdf = newPDF('8', 'Reporting & Optimising Your Workflow');
coverPage($pdf, '8', "Reporting &\nOptimising Your\nWorkflow",
    'Know your numbers, spot patterns, and make decisions that move your pipeline forward.',
    [
        'Reading your dashboard summary: the 5 numbers that matter',
        'Running a weekly pipeline review in 15 minutes',
        'Spotting patterns: where do deals stall? Which contacts go cold?',
        'Using filters and saved views for your most common checks',
        'What good looks like at 30, 60, and 90 days',
    ]
);

$pdf->AddPage();
contentRow($pdf,
    'The 5 Numbers That Matter',
    'You don\'t need to track everything — you need to track the right things. These 5 metrics tell you the health of your entire sales operation at a glance.',
    [
        '1. New Contacts Added — top of funnel. Target: 5–10 per week minimum.',
        '2. Conversations Started — how many new threads opened this week.',
        '3. Deals Advanced — how many deals moved to the next stage.',
        '4. Tasks Completed On Time — ratio of done vs. overdue. Target: >80%.',
        '5. Pipeline Value by Stage — total KSh in each stage. Look for imbalances.',
    ],
    $M8 . '02_dashboard_metrics.png',
    'Dashboard metric cards — live pipeline numbers',
    'If any of these 5 is trending down for 2 weeks in a row, stop and investigate before it becomes a problem.',
    'Vanity metric: total contacts. Actionable metric: contacts moved to Qualified this week.'
);

contentRow($pdf,
    'Dashboard Charts — Trends Over Time',
    'Below the metric cards, the Dashboard shows trend charts. These are more useful than snapshots because they reveal direction — are things getting better or worse?',
    [
        'Contacts Created (Last 7 Days) — is your top-of-funnel growing?',
        'Email Performance — sent vs. opened trend — is your outreach landing?',
        'Stage Distribution — where is contact flow getting stuck?',
        'Deal Value by Stage — is value moving forward or pooling in early stages?',
    ],
    $M8 . '03_dashboard_charts.png',
    'Dashboard trend charts — 7-day windows'
);

$pdf->AddPage();
contentRow($pdf,
    'Analytics — Deeper Reporting',
    'The Analytics page goes beyond the dashboard. Use it for weekly reviews, team performance checks, and identifying patterns in your pipeline.',
    [
        'Contact creation trends — are you adding enough new leads?',
        'Deal stage conversion rates — where is your pipeline leaking?',
        'Email open and reply rates — which outreach is working?',
        'Activity volume — calls, emails, notes per rep per week',
        'Pipeline velocity — how long does a deal take to move through each stage?',
    ],
    $M8 . '04_analytics_overview.png',
    'Analytics overview — conversion and activity data',
    '',
    'The analytics page is only as good as your data. Contacts without activities, deals without notes = blank charts.'
);

contentRow($pdf,
    'The 15-Minute Weekly Pipeline Review',
    'Every Friday (or Monday morning), run this review. It takes 15 minutes and gives you a clear action plan for the week ahead.',
    [
        'Step 1 (2 min): Open Dashboard. Check your 5 numbers vs. last week.',
        'Step 2 (3 min): Open Deals. Filter by "Last Activity" oldest first. Identify stale deals.',
        'Step 3 (3 min): For each stale deal: decide — advance, nurture, or close as lost.',
        'Step 4 (3 min): Open Tasks. Resolve all overdue. Reschedule if needed.',
        'Step 5 (2 min): Open Analytics. Check stage distribution. Any stage with 5+ contacts = needs attention.',
        'Step 6 (2 min): Set 3 priorities for the week. Create tasks for each.',
    ],
    $M8 . '05_analytics_charts.png',
    'Analytics charts — stage and pipeline breakdown'
);

$pdf->AddPage();
contentRow($pdf,
    'Using Filters & Saved Views',
    'Saved filters let you create custom views you return to every week without reconfiguring. Set these up in your first week.',
    [
        'Contacts: filter by Stage=Proposal + Last Activity > 7 days = your stale proposal list',
        'Contacts: filter by Stage=New + Lead Source=Form = fresh inbound to qualify',
        'Deals: sort by Expected Close Date ASC = what should close soonest',
        'Tasks: filter by Status=Overdue + Assigned To=Me = my personal action list',
        'Save each filter as a Saved Search — access from the More menu',
    ],
    $M8 . '07_contacts_filter_view.png',
    'Contacts with stage and filter view active'
);

contentRow($pdf,
    'What Good Looks Like — 30 / 60 / 90 Days',
    'Your metrics will look different at each milestone. Use these benchmarks to assess whether you\'re on track.',
    [
        'Day 30: Database imported and clean. First deals created. Tasks running. No overdue > 2.',
        'Day 30: Inbox habit established. Templates built. Notifications configured.',
        'Day 60: Weekly review habit running. Stale deal rate < 20%. Pipeline has 10+ active deals.',
        'Day 60: AI outputs reviewed daily. Email assistant in use. Contact summaries used before calls.',
        'Day 90: Predictable pipeline. Conversion rate per stage tracked. 1+ deal closed from CRM.',
        'Day 90: Ready to scale: add team members, enable automation, or increase lead volume.',
    ],
    $M8 . '06_predictive_analytics.png',
    'Predictive Analytics — forward-looking signals'
);

exercisePage($pdf,
    'Module 8 Exercise — Weekly Review',
    [
        'Open your Dashboard. Write down your 5 key numbers right now.',
        'Open Deals. Sort by Last Activity (oldest first). List every deal not touched in 7+ days.',
        'For each stale deal: email or WhatsApp the contact today. Use AI to draft if needed.',
        'Open Tasks. Count overdue. Resolve or reschedule all of them.',
        'Open Analytics. Find the stage with the most contacts stuck. Identify why.',
        'Create 3 priority tasks for this week. Assign due dates and mark High priority.',
        'Set a recurring calendar reminder: "CRM Weekly Review" — every Friday at 9am, 15 minutes.',
    ],
    [
        '5 metrics: New Contacts, Conversations Started, Deals Advanced, Tasks Done On Time, Pipeline by Stage.',
        'Weekly review takes 15 minutes. Do it every week without exception.',
        'Stale deal (7 days no update) = act today or lose it.',
        '30/60/90 benchmarks: clean data → active pipeline → predictable revenue.',
        'Analytics URL: acrm.webxpanse.com/analytics.php',
        'Predictive Analytics: acrm.webxpanse.com/predictive_analytics.php',
    ]
);

$pdf->Output(OUT_DIR . '/module8_reporting.pdf', 'F');
echo "✓ Module 8\n";

echo "\nAll 8 PDFs generated in: " . OUT_DIR . "\n";
