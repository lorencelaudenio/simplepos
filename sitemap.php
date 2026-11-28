<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/xml; charset=utf-8');
$lastModified = gmdate('Y-m-d', filemtime(__FILE__) ?: time());

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
echo '  <url>' . PHP_EOL;
echo '    <loc>' . e(site_url('')) . '</loc>' . PHP_EOL;
echo '    <lastmod>' . e($lastModified) . '</lastmod>' . PHP_EOL;
echo '    <changefreq>weekly</changefreq>' . PHP_EOL;
echo '    <priority>1.0</priority>' . PHP_EOL;
echo '  </url>' . PHP_EOL;
echo '</urlset>' . PHP_EOL;
