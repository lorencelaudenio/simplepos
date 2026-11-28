<?php
declare(strict_types=1);

/** Shared shell for public legal pages (privacy policy, terms and conditions). */
function render_legal_page(array $page): void
{
    $websiteName = get_setting('website_name', 'Simple Online POS') ?: 'Simple Online POS';
    $title = (string)($page['title'] ?? 'Legal');
    $description = (string)($page['description'] ?? '');
    $path = (string)($page['path'] ?? '');
    $updated = (string)($page['updated'] ?? '');
    $toc = is_array($page['toc'] ?? null) ? $page['toc'] : [];
    $contentFile = (string)($page['content'] ?? '');
    $contactEmail = get_setting('smtp_from', '');
    if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $contactEmail = '';
    }
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="referrer" content="same-origin">
  <?php seo_tags([
      'title' => $title . ' - ' . $websiteName,
      'description' => $description,
      'path' => $path,
      'robots' => 'index, follow',
      'site_name' => $websiteName,
  ]); ?>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=4">
  <link rel="stylesheet" href="assets/css/legal.css?v=1">
</head>
<body class="legal-page">
  <header class="legal-header">
    <div class="legal-header-inner">
      <a class="brand" href="index" aria-label="<?= e($websiteName) ?> home">
        <span class="brand-mark"><i class="bi bi-shop"></i></span><span><?= e($websiteName) ?></span>
      </a>
      <nav class="legal-nav" aria-label="Legal page navigation">
        <a href="index"><i class="bi bi-house-door"></i> Home</a>
        <a href="login"><i class="bi bi-box-arrow-in-right"></i> Sign in</a>
        <a href="privacy" class="<?= $path === 'privacy' ? 'active' : '' ?>">Privacy Policy</a>
        <a href="terms" class="<?= $path === 'terms' ? 'active' : '' ?>">Terms &amp; Conditions</a>
      </nav>
    </div>
  </header>

  <main class="legal-main">
    <div class="legal-intro">
      <span class="legal-eyebrow"><i class="bi bi-shield-check"></i> Legal information</span>
      <h1><?= e($title) ?></h1>
      <p><?= e($description) ?></p>
      <?php if ($updated !== ''): ?><p class="legal-meta">Last updated: <?= e($updated) ?></p><?php endif; ?>
    </div>

    <div class="legal-layout">
      <?php if ($toc !== []): ?>
        <aside class="legal-toc" aria-label="On this page">
          <h2>On this page</h2>
          <ol>
            <?php foreach ($toc as $item): ?>
              <?php if (!is_array($item)) { continue; } ?>
              <li><a href="#<?= e((string)($item['id'] ?? '')) ?>"><?= e((string)($item['label'] ?? '')) ?></a></li>
            <?php endforeach; ?>
          </ol>
          <p class="legal-toc-note">Need the other policy? <a href="<?= $path === 'privacy' ? 'terms' : 'privacy' ?>"><?= $path === 'privacy' ? 'Read the Terms &amp; Conditions' : 'Read the Privacy Policy' ?></a></p>
        </aside>
      <?php endif; ?>

      <article class="legal-content">
        <?php
        if ($contentFile !== '' && is_file($contentFile)) {
            include $contentFile;
        } else {
            echo '<p>This document is temporarily unavailable. Please try again later.</p>';
        }
        ?>
      </article>
    </div>
  </main>

  <footer class="legal-footer">
    <span><?= e($websiteName) ?></span>
    <nav aria-label="Footer links">
      <a href="index">Home</a>
      <a href="privacy">Privacy Policy</a>
      <a href="terms">Terms &amp; Conditions</a>
      <a href="login">Sign in</a>
    </nav>
  </footer>
</body>
</html>
    <?php
}
