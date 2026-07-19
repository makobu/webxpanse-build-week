<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Modules\Contacts;
use CRM\Services\ContactIntelligenceService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$contactsModule = new Contacts();
$intelligenceService = new ContactIntelligenceService();
$user = Auth::user();
$contacts = $contactsModule->getAll(
    120,
    0,
    null,
    Authorization::can('contacts.view_all', $user) ? 'all' : 'mine_unassigned',
    (int) ($user['id'] ?? 0)
);
$reviewRows = [];
$contactIds = array_map(
    static fn (array $contact): int => (int) ($contact['id'] ?? 0),
    $contacts
);
$storedIntelligenceById = $intelligenceService->getStoredForContactIds($contactIds);

foreach ($contacts as $contact) {
    $contactId = (int) ($contact['id'] ?? 0);
    $intel = $storedIntelligenceById[$contactId] ?? $intelligenceService->getStoredOrCompute($contactId);
    if (!$intel) {
        continue;
    }
    $quality = $intel['data_quality'] ?? [];
    $riskFlags = $intel['relationship_health']['risk_flags'] ?? [];
    $duplicateCount = count($quality['likely_duplicates'] ?? []);
    if (!empty($quality['is_incomplete']) || !empty($quality['is_stale']) || $duplicateCount > 0) {
        $reviewRows[] = [
            'contact' => $contact,
            'quality' => $quality,
            'health' => $intel['relationship_health'] ?? [],
            'duplicate_count' => $duplicateCount,
            'risk_flags' => $riskFlags,
        ];
    }
}

usort($reviewRows, static function (array $a, array $b): int {
    return (($b['duplicate_count'] ?? 0) <=> ($a['duplicate_count'] ?? 0))
        ?: (((int) !empty($b['quality']['is_stale'])) <=> ((int) !empty($a['quality']['is_stale'])));
});

$pageTitle = 'Contact Review Queue - ' . brandProductName();
ob_start();
?>

<div style="margin-bottom: var(--spacing-xl); display: flex; justify-content: space-between; gap: var(--spacing-md); align-items: start;">
    <div>
        <h1 style="margin: 0 0 var(--spacing-xs); color: var(--midnight-black);">Contact Review Queue</h1>
        <p style="margin: 0; color: var(--charcoal-grey);">Review stale, incomplete, and likely duplicate contacts before they turn into data debt.</p>
    </div>
    <a href="contacts.php" style="background: white; color: var(--midnight-black); border: 1px solid var(--border-color); border-radius: 999px; padding: 10px 16px; text-decoration: none; font-weight: 600;">Back to Contacts</a>
</div>

<div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden;">
    <?php if (empty($reviewRows)): ?>
        <div style="padding: var(--spacing-xl); color: var(--charcoal-grey);">No contacts currently need review.</div>
    <?php else: ?>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 1px solid var(--border-color);">
                    <th style="padding: 14px; text-align: left;">Contact</th>
                    <th style="padding: 14px; text-align: left;">Health</th>
                    <th style="padding: 14px; text-align: left;">Issues</th>
                    <th style="padding: 14px; text-align: left;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reviewRows as $row): ?>
                    <tr style="border-bottom: 1px solid #edf2f7;">
                        <td style="padding: 14px;">
                            <div style="font-weight: 600; color: var(--midnight-black);"><?php echo htmlspecialchars(trim(($row['contact']['first_name'] ?? '') . ' ' . ($row['contact']['last_name'] ?? ''))); ?></div>
                            <div style="font-size: 12px; color: var(--charcoal-grey);"><?php echo htmlspecialchars((string) ($row['contact']['email'] ?? '')); ?></div>
                        </td>
                        <td style="padding: 14px;">
                            <div style="font-weight: 700; color: var(--midnight-black);"><?php echo (int) ($row['health']['score'] ?? 0); ?>/100</div>
                            <div style="font-size: 12px; color: var(--charcoal-grey);"><?php echo htmlspecialchars((string) ($row['health']['band'] ?? 'unknown')); ?></div>
                        </td>
                        <td style="padding: 14px;">
                            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                <?php if (!empty($row['quality']['is_incomplete'])): ?>
                                    <span style="background: #fff7ed; color: #c2410c; border-radius: 999px; padding: 5px 10px; font-size: 12px; font-weight: 600;">Incomplete</span>
                                <?php endif; ?>
                                <?php if (!empty($row['quality']['is_stale'])): ?>
                                    <span style="background: #fef2f2; color: #b91c1c; border-radius: 999px; padding: 5px 10px; font-size: 12px; font-weight: 600;">Stale</span>
                                <?php endif; ?>
                                <?php if (($row['duplicate_count'] ?? 0) > 0): ?>
                                    <span style="background: #eef2ff; color: #4338ca; border-radius: 999px; padding: 5px 10px; font-size: 12px; font-weight: 600;"><?php echo (int) $row['duplicate_count']; ?> possible duplicates</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($row['quality']['missing_critical_fields'])): ?>
                                <div style="font-size: 12px; color: var(--charcoal-grey); margin-top: 8px;">Missing: <?php echo htmlspecialchars(implode(', ', $row['quality']['missing_critical_fields'])); ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 14px;">
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <a href="contact_view.php?id=<?php echo (int) $row['contact']['id']; ?>" style="background: var(--accent-blue); color: white; text-decoration: none; border-radius: 999px; padding: 8px 12px; font-size: 12px; font-weight: 600;">Open</a>
                                <?php if (($row['duplicate_count'] ?? 0) > 0): ?>
                                    <?php $firstDup = $row['quality']['likely_duplicates'][0]['id'] ?? null; ?>
                                    <?php if ($firstDup): ?>
                                        <a href="contact_merge.php?source_id=<?php echo (int) $row['contact']['id']; ?>&target_id=<?php echo (int) $firstDup; ?>" style="background: white; color: var(--midnight-black); text-decoration: none; border: 1px solid var(--border-color); border-radius: 999px; padding: 8px 12px; font-size: 12px; font-weight: 600;">Review Merge</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
