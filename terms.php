<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/legal_page.php';

$websiteName = get_setting('website_name', 'Simple Online POS') ?: 'Simple Online POS';

render_legal_page([
    'title' => 'Terms & Conditions',
    'description' => 'The rules for using ' . $websiteName . ', including accounts, acceptable use, your store data, backups, and availability.',
    'path' => 'terms',
    'updated' => 'November 28, 2026',
    'toc' => [
        ['id' => 'acceptance', 'label' => 'Acceptance of these terms'],
        ['id' => 'accounts', 'label' => 'Accounts and eligibility'],
        ['id' => 'acceptable-use', 'label' => 'Acceptable use'],
        ['id' => 'roles-and-data', 'label' => 'Roles and your data'],
        ['id' => 'backups', 'label' => 'Backups and availability'],
        ['id' => 'emails', 'label' => 'Email messages'],
        ['id' => 'third-party-services', 'label' => 'Third-party services'],
        ['id' => 'intellectual-property', 'label' => 'Intellectual property'],
        ['id' => 'disclaimers', 'label' => 'Disclaimers and liability'],
        ['id' => 'termination', 'label' => 'Suspension and termination'],
        ['id' => 'changes', 'label' => 'Changes to these Terms'],
    ],
    'content' => __DIR__ . '/includes/legal_terms.php',
]);
