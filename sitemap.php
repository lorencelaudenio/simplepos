<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/xml; charset=utf-8');

$pages = [
    ['path' => '', 'file' => __FILE__, 'changefreq' => 'weekly', 'priority' => '1.0'],
    ['path' => 'about', 'file' => __DIR__ . '/about.php', 'changefreq' => 'yearly', 'priority' => '0.6'],
    ['path' => 'privacy', 'file' => __DIR__ . '/privacy.php', 'changefreq' => 'yearly', 'priority' => '0.5'],
    ['path' => 'terms', 'file' => __DIR__ . '/terms.php', 'changefreq' => 'yearly', 'priority' => '0.5'],
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
foreach ($pages as $page) {
    $lastModified = gmdate('Y-m-d', filemtime((string)$page['file']) ?: time());
    echo '  <url>' . PHP_EOL;
    echo '    <loc>' . e(site_url((string)$page['path'])) . '</loc>' . PHP_EOL;
    echo '    <lastmod>' . e($lastModified) . '</lastmod>' . PHP_EOL;
    echo '    <changefreq>' . e((string)$page['changefreq']) . '</changefreq>' . PHP_EOL;
    echo '    <priority>' . e((string)$page['priority']) . '</priority>' . PHP_EOL;
    echo '  </url>' . PHP_EOL;
}
echo '</urlset>' . PHP_EOL;
