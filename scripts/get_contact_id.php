<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Database;

Database::init(require __DIR__ . '/../config/database.php');

$contact = Database::queryOne('SELECT id, first_name, last_name FROM contacts ORDER BY id LIMIT 1');

if ($contact) {
    echo "Contact ID: {$contact['id']} - {$contact['first_name']} {$contact['last_name']}\n";
    echo "Link: /crm/public/contact_view.php?id={$contact['id']}\n";
} else {
    echo "No contacts found in database\n";
}
