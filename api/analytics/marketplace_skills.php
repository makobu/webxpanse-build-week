<?php

require __DIR__ . '/_bootstrap.php';

$canViewSkills = \CRM\Authorization::isSuperAdmin($user)
    || \CRM\Authorization::can('workspace.skills.view', $user)
    || \CRM\Authorization::can('workspace.skills.manage', $user);

if (!$canViewSkills) {
    $forbidden();
}

try {
    $respond($operatingAnalytics->getMarketplaceSkillAnalytics($workspaceId, $viewerUserId));
} catch (\Throwable $e) {
    $fail('marketplace_skills', $e);
}
