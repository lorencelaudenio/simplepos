<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/legal_page.php';

$websiteName = get_setting('website_name', 'Simple Online POS') ?: 'Simple Online POS';

render_public_page([
    'title' => 'About',
    'description' => 'Learn what ' . $websiteName . ' does, who it is built for, and how accounts, store data, and backups work.',
    'path' => 'about',
    'updated' => 'November 28, 2026',
    'eyebrow' => 'About this app',
    'eyebrow_icon' => 'bi-info-circle-fill',
    'related' => true,
    'related_prompt' => 'Related documents:',
    'related_path' => 'privacy',
    'related_label' => 'Read the Privacy Policy',
    'toc' => [
        ['id' => 'what-it-is', 'label' => 'What it is'],
        ['id' => 'who-its-for', 'label' => 'Who it is for'],
        ['id' => 'features', 'label' => 'What you can do'],
        ['id' => 'accounts', 'label' => 'Accounts and roles'],
        ['id' => 'data-and-privacy', 'label' => 'Your store data'],
        ['id' => 'security-and-backups', 'label' => 'Security and backups'],
        ['id' => 'get-started', 'label' => 'Get started'],
    ],
    'content' => __DIR__ . '/includes/about_content.php',
]);
