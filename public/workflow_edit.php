<?php

$workflowId = isset($_GET['id']) ? (int) ($_GET['id'] ?? 0) : 0;
if ($workflowId > 0) {
    header('Location: workflow_create.php?id=' . $workflowId);
    exit;
}

header('Location: workflows.php');
exit;
