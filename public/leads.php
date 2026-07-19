<?php
/**
 * Leads route alias.
 *
 * Contacts are the CRM record backing both leads and customers. Keep the
 * expected daily-work URL inside the app instead of falling through to XAMPP.
 */

require_once __DIR__ . '/_public_bootstrap.php';

use CRM\Auth;
use CRM\Database;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$query = $_GET;
if (empty($query['stage'])) {
    $query['stage'] = 'new';
}

header('Location: contacts.php?' . http_build_query($query));
exit;
