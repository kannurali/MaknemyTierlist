<?php
// Шаблон ленты новостей для сайта со скином vx (Vox Seas). Подключается из
// news.php; $og, $canonicalUrl, $canonicalPath, $robots, $linkedPostId
// посчитаны там. Разметку читают js/news-page.js и js/topbar.js, а
// admin-news.php правит её по строкам: комментарий ADMIN-BAR и теги
// <script src="js/i18n.js…"> и <script src="js/news-page.js…"> — его якоря.
$vxName = site_name();
$vxEsc = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
// Запасные тексты превью в news.php написаны под основной сайт, а картинки
// поста (/api/og-news.php) на этом сайте нет — берём фирменную карточку.
$vxOgTitle = (string)$og['title'] === t('meta.news.ogTitle') ? t('vx.meta.news.ogTitle') : (string)$og['title'];
$vxOgDesc = (string)$og['description'] === t('meta.news.ogDesc') ? t('vx.meta.news.desc') : (string)$og['description'];
$vxCard = og_brand_card();
?>
<!DOCTYPE html>
<html lang="<?= i18n_lang() ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<?php if ($linkedPostId !== null): ?>
<meta name="nx-post" content="<?= (int)$linkedPostId ?>" />
<?php endif; ?>

<base href="/" />

<meta name="color-scheme" content="dark" />
<meta name="theme-color" content="#04121f" />

<title><?= te('vx.meta.news.title') ?></title>
<meta name="description" content="<?= ta('vx.meta.news.desc') ?>" />
<link rel="canonical" href="<?= $vxEsc($canonicalUrl) ?>" />
<?= i18n_alternates($canonicalPath) ?>
<meta name="robots" content="<?= $vxEsc($robots) ?>" />

<meta property="og:type" content="website" />
<meta property="og:site_name" content="<?= $vxEsc($vxName) ?>" />
<meta property="og:locale" content="<?= i18n_og_locale() ?>" />
<meta property="og:url" content="<?= $vxEsc($canonicalUrl) ?>" />
<meta property="og:title" content="<?= $vxEsc($vxOgTitle) ?>" />
<meta property="og:description" content="<?= $vxEsc($vxOgDesc) ?>" />
<meta property="og:image" content="<?= $vxEsc($vxCard['image']) ?>" />
<meta property="og:image:width" content="<?= (int)$vxCard['imageWidth'] ?>" />
<meta property="og:image:height" content="<?= (int)$vxCard['imageHeight'] ?>" />
<meta property="og:image:type" content="<?= $vxEsc($vxCard['imageType']) ?>" />
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="<?= $vxEsc($vxOgTitle) ?>" />
<meta name="twitter:description" content="<?= $vxEsc($vxOgDesc) ?>" />
<meta name="twitter:image" content="<?= $vxEsc($vxCard['image']) ?>" />

<link rel="icon" type="image/png" href="/assets/vx/favicon.png" />
<link rel="apple-touch-icon" href="/assets/vx/favicon.png" />
<link rel="preload" as="font" type="font/woff2" href="/assets/vx/fonts/luckiest-guy.woff2" crossorigin />

<link rel="stylesheet" href="css/base.css?v=16" />
<link rel="stylesheet" href="css/news.css?v=15" />
<link rel="stylesheet" href="css/vx/vx.css?v=4" />
<link rel="stylesheet" href="css/vx/news.css?v=1" />

<script src="js/auth.js?v=2" fetchpriority="high"></script>
<script src="js/topbar.js?v=32" defer fetchpriority="high"></script>
</head>
<body class="vx vx-news">

  <div class="vx-sea" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>

  <header class="mk-top">
    <a class="mk-top-brand" href="<?= u('/tierlist') ?>">
      <img src="assets/vx/logo.png" alt="Vox Seas" width="480" height="367" />
    </a>

    <nav class="mk-top-bar" id="mkTopBar" aria-label="<?= ta('topbar.sections') ?>">
      <ul class="mk-nav">
        <li>
          <a class="mk-pill" href="<?= u('/tierlist') ?>">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M8.57627 3.7533C8.57627 3.22702 8.14799 2.79425 7.62582 2.85987C6.45486 3.00701 5.32947 3.42467 4.341 4.08515C3.08735 4.9228 2.11026 6.1134 1.53327 7.50637C0.95628 8.89935 0.805314 10.4321 1.09946 11.9109C1.39361 13.3897 2.11965 14.748 3.18579 15.8142C4.25193 16.8803 5.61027 17.6063 7.08904 17.9005C8.56781 18.1946 10.1006 18.0437 11.4936 17.4667C12.8866 16.8897 14.0771 15.9126 14.9148 14.659C15.5753 13.6705 15.9929 12.5451 16.1401 11.3741C16.2057 10.852 15.7729 10.4237 15.2466 10.4237H9.52918C9.0029 10.4237 8.57627 9.99705 8.57627 9.47077V3.7533Z" stroke="currentColor" stroke-width="1.82067"/><path d="M11.435 1.84748C11.435 1.3212 11.8638 0.887589 12.3847 0.962518C12.934 1.04153 13.4726 1.18898 13.9876 1.40232C14.7969 1.73754 15.5323 2.22887 16.1517 2.84828C16.7711 3.46768 17.2624 4.20302 17.5976 5.0123C17.811 5.52735 17.9584 6.06592 18.0374 6.61527C18.1124 7.13618 17.6787 7.56495 17.1525 7.56495L11.5303 7.56495C11.4777 7.56495 11.435 7.52228 11.435 7.46965V1.84748Z" stroke="currentColor" stroke-width="1.82067"/></svg>
            <span class="mk-pill-text" data-i18n="nav.tierlist"><?= te('nav.tierlist') ?></span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="<?= u('/news') ?>" aria-current="page">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M18.05 9.50002C18.05 14.2221 14.222 18.05 9.49995 18.05M18.05 9.50002C18.05 4.77798 14.222 0.950013 9.49995 0.950013M18.05 9.50002C18.05 7.92601 14.222 6.65002 9.49995 6.65002C4.77792 6.65002 0.949949 7.92601 0.949949 9.50002M18.05 9.50002C18.05 11.074 14.222 12.35 9.49995 12.35C4.77792 12.35 0.949949 11.074 0.949949 9.50002M9.49995 18.05C4.77792 18.05 0.949949 14.2221 0.949949 9.50002M9.49995 18.05C11.074 18.05 12.35 14.2221 12.35 9.50002C12.35 4.77798 11.074 0.950013 9.49995 0.950013M9.49995 18.05C7.92594 18.05 6.64995 14.2221 6.64995 9.50002C6.64995 4.77798 7.92594 0.950013 9.49995 0.950013M0.949949 9.50002C0.949949 4.77798 4.77792 0.950013 9.49995 0.950013" stroke="currentColor" stroke-width="1.9"/></svg>
            <span class="mk-pill-text" data-i18n="nav.news"><?= te('nav.news') ?></span>
          </a>
        </li>
        <li class="mk-nav-lang"><?= i18n_lang_menu() ?></li>
      </ul>

      <a class="vx-main" href="<?= SITE_DEFAULT_ORIGIN . u('/') ?>" target="_blank" rel="noopener" data-i18n-title="vx.main" title="<?= ta('vx.main') ?>">
        <img src="assets/design/logo-mk-square.png" alt="" width="160" height="160" />
        <span class="vx-main-text">Maknemy</span>
        <svg class="vx-main-out" viewBox="0 0 12 12" aria-hidden="true"><path d="M4.5 1.75h5.75V7.5M10.25 1.75 2 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>

      <button class="mk-avatar" type="button" aria-label="<?= ta('topbar.profile') ?>" data-soon data-i18n-title="topbar.soon" title="<?= ta('topbar.soon') ?>">
        <svg viewBox="0 0 34 34" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M17.0003 2.83325C13.0883 2.83325 9.91699 6.00457 9.91699 9.91659C9.91699 13.8286 13.0883 16.9999 17.0003 16.9999C20.9123 16.9999 24.0837 13.8286 24.0837 9.91659C24.0837 6.00457 20.9123 2.83325 17.0003 2.83325Z" fill="currentColor"/><path fill-rule="evenodd" clip-rule="evenodd" d="M12.7503 18.4167C10.3947 18.4167 8.12945 19.4913 6.80192 21.109C6.12816 21.9301 5.65451 22.946 5.61326 24.072C5.57114 25.2218 5.98621 26.3442 6.8422 27.3234C8.92833 29.7099 12.2591 31.1667 17.0003 31.1667C21.7415 31.1667 25.0723 29.7099 27.1584 27.3234C28.0144 26.3442 28.4294 25.2218 28.3873 24.072C28.3461 22.946 27.8724 21.9301 27.1987 21.109C25.8711 19.4913 23.6058 18.4167 21.2503 18.4167H12.7503Z" fill="currentColor"/></svg>
      </button>
    </nav>

    <button class="mk-top-toggle" type="button" id="mkTopToggle"
            aria-expanded="false" aria-controls="mkTopBar"
            data-i18n-label="topbar.showNav" aria-label="<?= ta('topbar.showNav') ?>"
            data-i18n-title="topbar.showNav" title="<?= ta('topbar.showNav') ?>">
    </button>
  </header>

  <main class="nw-page">
    <div class="nw-lead">

      <div class="vx-news-head">
        <h1 class="vx-news-title" data-i18n="nav.news"><?= te('nav.news') ?></h1>
      </div>

      <!-- ADMIN-BAR -->

      <div class="nw-notice" id="newsNotice" role="status" hidden></div>

      <div class="nw-feed" id="feed"></div>
      <div class="nw-state" id="newsState" role="status" aria-live="polite" hidden></div>
    </div>
  </main>

  <footer class="vx-foot">
    <img class="vx-foot-logo" src="assets/vx/logo.png" alt="" width="480" height="367" loading="lazy" />
    <p class="vx-foot-name"><?= $vxEsc($vxName) ?></p>
    <p class="vx-foot-note" data-i18n="vx.foot"><?= te('vx.foot') ?></p>
    <p class="vx-foot-legal">
      <a href="/privacy" data-i18n="site.footPrivacy"><?= te('site.footPrivacy') ?></a>
      <a href="/terms" data-i18n="site.footTerms"><?= te('site.footTerms') ?></a>
    </p>
  </footer>

  <?= i18n_dict_tag() ?>
  <script src="js/i18n.js?v=84" fetchpriority="high"></script>
  <script src="js/news.js?v=6" fetchpriority="high"></script>
  <script src="js/news-blocks.js?v=4" fetchpriority="high"></script>
  <script src="js/protect.js?v=3" fetchpriority="high"></script>
  <script src="js/news-page.js?v=35" fetchpriority="high"></script>
</body>
</html>
