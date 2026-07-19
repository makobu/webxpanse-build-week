<?php
/**
 * Customers route alias.
 *
 * Contacts are the CRM record backing both leads and customers. Won contacts
 * are the closest existing customer workflow in the current product.
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
    $query['stage'] = 'won';
}

header('Location: contacts.php?' . http_build_query($query));
exit;
