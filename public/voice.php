<?php

require_once __DIR__ . '/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
$user = Auth::user();
if (Authorization::isSuperAdmin($user) || Authorization::can('voice.calls.use', $user)) {
    header('Location: call_center.php');
    exit;
}
if (Authorization::can('voice.customer_voice.review', $user)) {
    header('Location: customer_voice.php');
    exit;
}
header('Location: workspace_skills.php?module=voice_call_center');
exit;
