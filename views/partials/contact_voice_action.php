<?php

$contactVoiceActionVisible = false;
try {
    $contactVoiceActionVisible = !empty($contact['phone'])
        && (\CRM\Authorization::isSuperAdmin($user) || \CRM\Authorization::can('voice.calls.use', $user))
        && (new \CRM\Services\WorkspaceSkillInstallService())->canExposeRuntimeModule(
            (int) $activeWorkspaceId,
            \CRM\Services\WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER
        );
} catch (\Throwable $e) {
    $contactVoiceActionVisible = false;
}
?>
<?php if ($contactVoiceActionVisible): ?>
    <a class="contact-detail-action" href="call_center.php?contact_id=<?php echo (int) $contact['id']; ?>">
        <i class="fa-solid fa-headset" aria-hidden="true"></i>Voice Call
    </a>
<?php endif; ?>
