<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/legal_page.php';

$websiteName = get_setting('website_name', 'Simple Online POS') ?: 'Simple Online POS';

render_public_page([
    'title' => 'Privacy Policy',
    'description' => 'How ' . $websiteName . ' collects, uses, shares, and protects information when you create an account and run your store.',
    'path' => 'privacy',
    'updated' => 'November 28, 2026',
    'toc' => [
        ['id' => 'overview', 'label' => 'Overview'],
        ['id' => 'information-we-collect', 'label' => 'Information we collect'],
        ['id' => 'how-we-use-it', 'label' => 'How we use it'],
        ['id' => 'who-can-see-it', 'label' => 'Who can see it'],
        ['id' => 'cookies-and-third-parties', 'label' => 'Cookies & third parties'],
        ['id' => 'retention', 'label' => 'How long we keep information'],
        ['id' => 'security', 'label' => 'Security'],
        ['id' => 'your-choices', 'label' => 'Your choices'],
        ['id' => 'additional-information', 'label' => 'Additional information'],
    ],
    'content' => __DIR__ . '/includes/legal_privacy.php',
]);
