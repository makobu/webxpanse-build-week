<?php

require __DIR__ . '/_bootstrap.php';

$canViewOperations = \CRM\Authorization::isSuperAdmin($user)
    || \CRM\Authorization::can('admin.users.manage', $user)
    || \CRM\Authorization::can('ai.operations.manage', $user)
    || \CRM\Authorization::can('workflows.manage', $user);

if (!$canViewOperations) {
    $forbidden();
}

try {
    $respond($operatingAnalytics->getOperationsHealthAnalytics($workspaceId, $timeframe));
} catch (\Throwable $e) {
    $fail('operations', $e);
}
