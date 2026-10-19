<?php
require_once __DIR__ . '/api/_bootstrap.php';
require_once __DIR__ . '/api/lib/metrika.php';

// Условия рекламы для рекламодателей — /ads. В разделах шапки её нет:
// ведёт сюда только кнопка «Реклама» рядом с Telegram. Классы и файлы без
// «ads»/«ad-»: такие имена режут блокировщики рекламы (см. README, promo).
// Почему сам адрес /ads можно — в .htaccess.
header('Cache-Control: no-cache, must-revalidate');
page_lscache();
?>
<!DOCTYPE html>
<html lang="<?= i18n_lang() ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="color-scheme" content="dark" />

<base href="/" />

<title><?= te('meta.promote.title') ?></title>
<meta name="description" content="<?= ta('meta.promote.desc') ?>" />
<link rel="canonical" href="<?= i18n_url('/ads') ?>" />
<?= i18n_alternates('/ads') ?>

<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="icon" type="image/png" href="/assets/favicon.png?v=2" sizes="256x256" />
<link rel="apple-touch-icon" href="/apple-touch-icon.png" />

<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/base.css?v=15" /><?php else: ?><link rel="stylesheet" href="css/base.css?v=15" /><?php endif; ?>

<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/topbar.css?v=30" /><?php else: ?><link rel="stylesheet" href="css/topbar.css?v=30" /><?php endif; ?>

<script src="js/auth.js?v=2" fetchpriority="high"></script>
<script src="js/topbar.js?v=32" defer fetchpriority="high"></script>

<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/design-page.css?v=43" /><?php else: ?><link rel="stylesheet" href="css/design-page.css?v=43" /><?php endif; ?>
<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/promote.css?v=1" /><?php else: ?><link rel="stylesheet" href="css/promote.css?v=1" /><?php endif; ?>
<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/halloween.css?v=4" /><?php endif; ?>

<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/giveaway.css?v=6" /><?php else: ?><link rel="stylesheet" href="css/giveaway.css?v=6" /><?php endif; ?>
<?php echo metrika_counter_html(); ?>
</head>
<body>

  <header class="mk-top">
    <a class="mk-top-brand" href="<?= u('/') ?>">
      <img class="mk-top-mark" src="assets/design/logo-mk-square.png" alt="" aria-hidden="true" />
      <img class="mk-top-word" src="assets/design/wordmark.svg" alt="MAKNEMY" />
    </a>

    <div class="mk-top-lang lang-switch" id="langSwitch" role="group"
         data-i18n-label="lang.switch" aria-label="<?= ta('lang.switch') ?>">
      <button class="chip" type="button" data-lang="ru" data-i18n="lang.ru" aria-pressed="false"><?= te('lang.ru') ?></button>
      <button class="chip" type="button" data-lang="en" data-i18n="lang.en" aria-pressed="false"><?= te('lang.en') ?></button>
    </div>

    <nav class="mk-top-bar" id="mkTopBar" aria-label="<?= ta('topbar.sections') ?>">
      <ul class="mk-nav">
        <li>
          <a class="mk-pill" href="<?= u('/') ?>">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M18.05 16.0302V8.423C18.05 7.48807 17.644 6.60551 16.9498 6.03152L11.833 1.80094C10.4608 0.666372 8.53926 0.666371 7.16704 1.80094L2.05028 6.03152C1.35606 6.60551 0.950013 7.48807 0.950013 8.423V16.0302C0.950013 17.1457 1.80067 18.05 2.85001 18.05H4.75001C5.79936 18.05 6.65001 17.1994 6.65001 16.15V13.0006C6.65001 11.8851 7.50067 10.9808 8.55002 10.9808H10.45C11.4994 10.9808 12.35 11.8851 12.35 13.0006V16.15C12.35 17.1994 13.2007 18.05 14.25 18.05H16.15C17.1994 18.05 18.05 17.1457 18.05 16.0302Z" stroke="currentColor" stroke-width="1.81101"/></svg>
            <span class="mk-pill-text" data-i18n="nav.home"><?= te('nav.home') ?></span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="<?= u('/tierlist') ?>">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M8.57627 3.7533C8.57627 3.22702 8.14799 2.79425 7.62582 2.85987C6.45486 3.00701 5.32947 3.42467 4.341 4.08515C3.08735 4.9228 2.11026 6.1134 1.53327 7.50637C0.95628 8.89935 0.805314 10.4321 1.09946 11.9109C1.39361 13.3897 2.11965 14.748 3.18579 15.8142C4.25193 16.8803 5.61027 17.6063 7.08904 17.9005C8.56781 18.1946 10.1006 18.0437 11.4936 17.4667C12.8866 16.8897 14.0771 15.9126 14.9148 14.659C15.5753 13.6705 15.9929 12.5451 16.1401 11.3741C16.2057 10.852 15.7729 10.4237 15.2466 10.4237H9.52918C9.0029 10.4237 8.57627 9.99705 8.57627 9.47077V3.7533Z" stroke="currentColor" stroke-width="1.82067"/><path d="M11.435 1.84748C11.435 1.3212 11.8638 0.887589 12.3847 0.962518C12.934 1.04153 13.4726 1.18898 13.9876 1.40232C14.7969 1.73754 15.5323 2.22887 16.1517 2.84828C16.7711 3.46768 17.2624 4.20302 17.5976 5.0123C17.811 5.52735 17.9584 6.06592 18.0374 6.61527C18.1124 7.13618 17.6787 7.56495 17.1525 7.56495L11.5303 7.56495C11.4777 7.56495 11.435 7.52228 11.435 7.46965V1.84748Z" stroke="currentColor" stroke-width="1.82067"/></svg>
            <span class="mk-pill-text" data-i18n="nav.tierlist"><?= te('nav.tierlist') ?></span>
          </a>
        </li>
        <li>

          <a class="mk-pill" href="<?= u('/trading') ?>">
            <svg viewBox="0 0 18 19" fill="none" aria-hidden="true"><path d="M6.17037 0.943433L4.48309 4.31799M11.8297 0.943433L13.517 4.31799M11.8297 9.4324L8.29262 13.2053L6.17037 11.4903M5.6697 17.9214H12.3304C14.2079 17.9214 15.7998 16.5408 16.0653 14.6821L17.0276 7.94613C17.2711 6.24146 15.9484 4.71631 14.2264 4.71631H3.77368C2.0517 4.71631 0.728943 6.24145 0.972468 7.94613L1.93474 14.6821C2.20027 16.5408 3.79212 17.9214 5.6697 17.9214Z" stroke="currentColor" stroke-width="1.88644" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="mk-pill-text" data-i18n="nav.trading"><?= te('nav.trading') ?></span>
          </a>
        </li>
        <li>

          <a class="mk-pill" href="<?= u('/calculator') ?>">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M5.70001 8.55001V13.3M13.3 10.45V13.3M9.5 5.70001V13.3M4.75001 18.05H14.25C16.3487 18.05 18.05 16.3487 18.05 14.25V4.75001C18.05 2.65134 16.3487 0.950022 14.25 0.950022H4.75001C2.65134 0.950022 0.950022 2.65134 0.950022 4.75001V14.25C0.950022 16.3487 2.65134 18.05 4.75001 18.05Z" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
            <span class="mk-pill-text" data-i18n="nav.calculator"><?= te('nav.calculator') ?></span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="<?= u('/stock') ?>">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M2.4 7.6V16.1C2.4 17.15 3.25 18.05 4.3 18.05H14.7C15.75 18.05 16.6 17.15 16.6 16.1V7.6M7.15 18.05V13.3C7.15 12.25 8 11.4 9.05 11.4H9.95C11 11.4 11.85 12.25 11.85 13.3V18.05M0.95 5.7L2.6 1.9C2.85 1.3 3.45 0.95 4.1 0.95H14.9C15.55 0.95 16.15 1.3 16.4 1.9L18.05 5.7C18.05 7 16.95 8.1 15.65 8.1C14.3 8.1 13.25 7 13.25 5.7C13.25 7 12.15 8.1 10.85 8.1H8.15C6.85 8.1 5.75 7 5.75 5.7C5.75 7 4.7 8.1 3.35 8.1C2.05 8.1 0.95 7 0.95 5.7Z" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="mk-pill-text" data-i18n="nav.stock"><?= te('nav.stock') ?></span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="<?= u('/news') ?>">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M18.05 9.50002C18.05 14.2221 14.222 18.05 9.49995 18.05M18.05 9.50002C18.05 4.77798 14.222 0.950013 9.49995 0.950013M18.05 9.50002C18.05 7.92601 14.222 6.65002 9.49995 6.65002C4.77792 6.65002 0.949949 7.92601 0.949949 9.50002M18.05 9.50002C18.05 11.074 14.222 12.35 9.49995 12.35C4.77792 12.35 0.949949 11.074 0.949949 9.50002M9.49995 18.05C4.77792 18.05 0.949949 14.2221 0.949949 9.50002M9.49995 18.05C11.074 18.05 12.35 14.2221 12.35 9.50002C12.35 4.77798 11.074 0.950013 9.49995 0.950013M9.49995 18.05C7.92594 18.05 6.64995 14.2221 6.64995 9.50002C6.64995 4.77798 7.92594 0.950013 9.49995 0.950013M0.949949 9.50002C0.949949 4.77798 4.77792 0.950013 9.49995 0.950013" stroke="currentColor" stroke-width="1.9"/></svg>
            <span class="mk-pill-text" data-i18n="nav.news"><?= te('nav.news') ?></span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="<?= u('/ads') ?>" aria-current="page">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M5.6 6.9V12.1M5.6 12.1L6.75 16.55C6.98 17.43 7.77 18.05 8.68 18.05C9.98 18.05 10.93 16.82 10.6 15.56L10.1 13.65M2.95 6.9H5.6L12.85 3.05C13.88 2.5 15.1 3.25 15.1 4.4V14.6C15.1 15.75 13.88 16.5 12.85 15.95L5.6 12.1H2.95C1.85 12.1 0.95 11.2 0.95 10.1V8.9C0.95 7.8 1.85 6.9 2.95 6.9ZM17.45 7.3C18.18 8.65 18.18 10.35 17.45 11.7" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="mk-pill-text" data-i18n="nav.promote"><?= te('nav.promote') ?></span>
          </a>
        </li>
      </ul>

      <a class="mk-promote" href="<?= u('/giveaways') ?>" data-i18n="nav.giveaways"><?= te('nav.giveaways') ?></a>

      <a class="mk-tg" href="https://t.me/theMaknemy" target="_blank" rel="noopener" aria-label="Telegram" title="Telegram">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill-rule="evenodd" fill="currentColor" d="M21.4 3.7 2.9 10.9c-.9.4-.9 1.5 0 1.8l4.5 1.4 1.7 5.4c.2.6 1 .8 1.5.3l2.5-2.4 4.5 3.3c.6.4 1.4.1 1.6-.6l3.2-15.4c.2-.8-.4-1.3-1-1Zm-4.2 3.9-7.9 7 .5 3 .6-2.4 6.8-7.6Z"/></svg>
      </a>

      <a class="mk-chat" href="<?= u('/chat') ?>" data-i18n-label="nav.chat" aria-label="<?= ta('nav.chat') ?>">
        <svg viewBox="0 0 25 25" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M12.0833 0C5.40989 0 0 5.40989 0 12.0833C0 14.2768 0.585445 16.3362 1.60861 18.1109C1.817 18.4723 1.85274 18.9124 1.67689 19.2907L0.645317 21.5102C0.0119158 22.7086 0.878898 24.1667 2.24942 24.1667H12.0833C18.7568 24.1667 24.1667 18.7568 24.1667 12.0833C24.1667 5.40989 18.7568 0 12.0833 0ZM8.45833 8.45833C7.79099 8.45833 7.25 8.99932 7.25 9.66667C7.25 10.334 7.79099 10.875 8.45833 10.875H10.875C11.5423 10.875 12.0833 10.334 12.0833 9.66667C12.0833 8.99932 11.5423 8.45833 10.875 8.45833H8.45833ZM8.45833 13.2917C7.79099 13.2917 7.25 13.8327 7.25 14.5C7.25 15.1673 7.79099 15.7083 8.45833 15.7083H15.7083C16.3757 15.7083 16.9167 15.1673 16.9167 14.5C16.9167 13.8327 16.3757 13.2917 15.7083 13.2917H8.45833Z" fill="currentColor"/></svg>
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
  <main class="pm-page<?= HALLOWEEN ? ' pm-hw' : '' ?>">
    <div class="pm-wrap">
      <section class="pm-hero" aria-labelledby="pmTitle">
        <img class="pm-hero-bloom" src="<?= HALLOWEEN ? 'assets/halloween/branch-r.webp' : 'assets/design/home/sakura.webp' ?>" width="<?= HALLOWEEN ? 995 : 868 ?>" height="<?= HALLOWEEN ? 936 : 890 ?>" alt="" aria-hidden="true" decoding="async" />
<?php if (HALLOWEEN): ?>
        <img class="pm-hero-bat" src="assets/halloween/bat.webp" width="650" height="232" alt="" aria-hidden="true" decoding="async" />
<?php endif; ?>
        <div class="pm-hero-copy">
          <h1 class="pm-title" id="pmTitle"><?= th('ads.001') ?></h1>
          <p class="pm-lead"><?= th('ads.002') ?></p>
          <p class="pm-budget"><?= th('ads.003') ?></p>
          <p class="pm-reach"><?= th('ads.004') ?></p>
          <div class="pm-hero-cta">
            <a class="pm-btn" href="https://t.me/mksvtn" target="_blank" rel="noopener">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path fill-rule="evenodd" fill="currentColor" d="M21.4 3.7 2.9 10.9c-.9.4-.9 1.5 0 1.8l4.5 1.4 1.7 5.4c.2.6 1 .8 1.5.3l2.5-2.4 4.5 3.3c.6.4 1.4.1 1.6-.6l3.2-15.4c.2-.8-.4-1.3-1-1Zm-4.2 3.9-7.9 7 .5 3 .6-2.4 6.8-7.6Z"/></svg>
              <span><?= th('ads.005') ?></span>
            </a>
            <p class="pm-talk"><?= th('ads.006') ?></p>
          </div>
        </div>

        <div class="pm-stage" aria-hidden="true">
          <div class="pm-stage-page">
            <span class="pm-stage-bar">
              <img class="pm-stage-mark" src="assets/design/logo-mk-square.png" width="160" height="160" alt="" />
              <img class="pm-stage-word" src="assets/design/wordmark.svg" width="285" height="26" alt="" />
              <i></i><i></i><i></i><i></i>
            </span>
            <img class="pm-stage-rail is-l" src="assets/promo/placeholder-rail.webp" width="320" height="1200" alt="" decoding="async" />
            <img class="pm-stage-rail is-r" src="assets/promo/placeholder-rail.webp" width="320" height="1200" alt="" decoding="async" />
            <span class="pm-stage-col">
              <img class="pm-stage-strip" src="assets/promo/placeholder-strip.webp" width="1200" height="300" alt="" decoding="async" />
              <span class="pm-stage-tier"></span>
              <span class="pm-stage-tier"></span>
              <span class="pm-stage-tier"></span>
              <span class="pm-stage-tier"></span>
              <span class="pm-stage-link"></span>
            </span>
          </div>
          <div class="pm-stage-phone">
            <span class="pm-stage-notch"></span>
            <span class="pm-stage-col">
              <span class="pm-stage-tier"></span>
              <span class="pm-stage-tier"></span>
              <span class="pm-stage-tier"></span>
              <span class="pm-stage-tier"></span>
              <span class="pm-stage-tier"></span>
              <span class="pm-stage-tier"></span>
            </span>
            <img class="pm-stage-dock" src="assets/promo/placeholder-dock.webp" width="640" height="200" alt="" decoding="async" />
          </div>
          <img class="pm-stage-pop" src="assets/promo/house-tg-popup.webp" width="800" height="800" alt="" decoding="async" />
          <span class="pm-tag is-rail">300&nbsp;×&nbsp;1200</span>
          <span class="pm-tag is-pop">800&nbsp;×&nbsp;800</span>
          <span class="pm-tag is-dock">Mobile Sticky</span>
        </div>
      </section>

      <nav class="pm-toc" aria-label="<?= ta('ads.007') ?>">
        <ul>
          <li><a href="<?= u('/ads#pm-results') ?>"><?= th('ads.008') ?></a></li>
          <li><a href="<?= u('/ads#pm-card') ?>"><?= th('ads.009') ?></a></li>
          <li><a href="<?= u('/ads#pm-display') ?>"><?= th('ads.010') ?></a></li>
          <li><a href="<?= u('/ads#pm-bundle') ?>"><?= th('ads.011') ?></a></li>
          <li><a href="<?= u('/ads#pm-telegram') ?>">Telegram</a></li>
          <li><a href="<?= u('/ads#pm-combo') ?>"><?= th('ads.012') ?></a></li>
          <li><a href="<?= u('/ads#pm-clicks') ?>"><?= th('ads.013') ?></a></li>
          <li><a href="<?= u('/ads#pm-make') ?>"><?= th('ads.014') ?></a></li>
          <li><a href="<?= u('/ads#pm-trial') ?>"><?= th('ads.015') ?></a></li>
          <li><a href="<?= u('/ads#pm-projects') ?>"><?= th('ads.016') ?></a></li>
          <li><a href="<?= u('/ads#pm-rules') ?>"><?= th('ads.017') ?></a></li>
          <li><a href="<?= u('/ads#pm-order') ?>"><?= th('ads.018') ?></a></li>
        </ul>
      </nav>

      <section class="pm-sec" id="pm-results" aria-labelledby="pm-results-h">
        <h2 class="pm-h2" id="pm-results-h"><?= th('ads.019') ?></h2>
        <div class="pm-split">
          <p class="pm-main pm-intro"><?= th('ads.020') ?></p>
          <div class="pm-side">
            <ul class="pm-rate">
              <li><?= th('ads.021') ?></li>
              <li><?= th('ads.022') ?></li>
            </ul>
          </div>
          <p class="pm-main"><?= th('ads.023') ?></p>
        </div>

        <h3 class="pm-h3"><?= th('ads.024') ?></h3>
        <ul class="pm-list is-check is-cols">
          <li><?= th('ads.025') ?></li>
          <li><?= th('ads.026') ?></li>
          <li><?= th('ads.027') ?></li>
          <li><?= th('ads.028') ?></li>
        </ul>

        <div class="pm-notes is-flow">
          <p><?= th('ads.029') ?></p>
          <p><?= th('ads.030') ?></p>
          <p><?= th('ads.031') ?></p>
        </div>
      </section>

      <section class="pm-sec" id="pm-card" aria-labelledby="pm-card-h">
        <img class="pm-branch is-l" style="--b-y: -80px; --b-w: 400px; --b-x: 190px; --b-r: 84deg; --b-o: .8" src="<?= HALLOWEEN ? 'assets/halloween/branch-l.webp' : 'assets/design/home/sakura.webp' ?>" width="868" height="890" alt="" aria-hidden="true" loading="lazy" decoding="async" />
        <h2 class="pm-h2" id="pm-card-h"><?= th('ads.032') ?></h2>
        <div class="pm-split">
          <p class="pm-main pm-intro"><?= th('ads.033') ?></p>
          <div class="pm-side">
            <div class="pm-tbl">
              <table class="pm-table">
                <thead>
                  <tr><th scope="col"><?= th('ads.034') ?></th><th scope="col" class="pm-num"><?= th('ads.035') ?></th></tr>
                </thead>
                <tbody>
                  <tr><th scope="row"><span class="pm-row-name"><span class="pm-thumb" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-news.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span><?= th('ads.036') ?></span></th><td class="pm-cost">$2</td></tr>
                  <tr><th scope="row"><span class="pm-row-name"><span class="pm-thumb" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-prices.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span><?= th('ads.037') ?></span></th><td class="pm-cost">$3</td></tr>
                  <tr><th scope="row"><span class="pm-row-name"><span class="pm-thumb" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-fruits.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span><?= th('ads.038') ?></span></th><td class="pm-cost">$4</td></tr>
                  <tr><th scope="row"><span class="pm-row-name"><span class="pm-thumb" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-tier.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span><?= th('ads.039') ?></span></th><td class="pm-cost">$5</td></tr>
                  <tr class="is-all"><th scope="row"><?= th('ads.040') ?></th><td class="pm-cost">$11</td></tr>
                </tbody>
              </table>
            </div>
            <p class="pm-aside"><?= th('ads.041') ?></p>
          </div>
          <p class="pm-main"><?= th('ads.042') ?></p>
          <p class="pm-main"><?= th('ads.043') ?></p>
          <p class="pm-main pm-quiet"><?= th('ads.045') ?> <a href="<?= u('/ads#pm-results') ?>"><?= th('ads.044') ?></a>.</p>
        </div>
      </section>

      <section class="pm-sec" id="pm-display" aria-labelledby="pm-display-h">
<?php if (HALLOWEEN): ?>
        <img class="pm-bat is-r" style="--bat-x: 60px; --bat-y: 520px; --bat-w: 120px; --bat-r: -22deg" src="assets/halloween/bat.webp" width="650" height="232" alt="" aria-hidden="true" loading="lazy" decoding="async" />
<?php endif; ?>
        <img class="pm-branch is-r is-flip" style="--b-y: 360px; --b-w: 340px; --b-x: 150px; --b-r: -24deg; --b-o: .7" src="<?= HALLOWEEN ? 'assets/halloween/branch-r.webp' : 'assets/design/home/sakura.webp' ?>" width="<?= HALLOWEEN ? 995 : 868 ?>" height="<?= HALLOWEEN ? 936 : 890 ?>" alt="" aria-hidden="true" loading="lazy" decoding="async" />
        <h2 class="pm-h2" id="pm-display-h"><?= th('ads.046') ?></h2>
        <div class="pm-split">
          <p class="pm-main pm-intro"><?= th('ads.047') ?></p>
          <ul class="pm-main pm-list pm-terms">
            <li><?= th('ads.048') ?></li>
            <li><?= th('ads.049') ?></li>
          </ul>
          <p class="pm-main pm-quiet"><?= th('ads.050') ?></p>
          <div class="pm-side is-wide">
            <div class="pm-tbl">
              <table class="pm-table is-three">
                <caption><?= th('ads.051') ?></caption>
                <thead>
                  <tr><th scope="col"><?= th('ads.052') ?></th><th scope="col" class="pm-num"><?= th('ads.053') ?></th><th scope="col" class="pm-num"><?= th('ads.054') ?></th></tr>
                </thead>
                <tbody>
                  <tr><th scope="row"><span class="pm-row-name"><span class="pm-thumb" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-news.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span><?= th('ads.055') ?></span></th><td class="pm-cost">$7</td><td class="pm-cost">$10</td></tr>
                  <tr><th scope="row"><span class="pm-row-name"><span class="pm-thumb" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-prices.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span><?= th('ads.056') ?></span></th><td class="pm-cost">$15</td><td class="pm-cost">$20</td></tr>
                  <tr><th scope="row"><span class="pm-row-name"><span class="pm-thumb" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-tier.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span><?= th('ads.057') ?></span></th><td class="pm-cost">$20</td><td class="pm-cost">$25</td></tr>
                  <tr><th scope="row"><span class="pm-row-name"><span class="pm-thumb" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-fruits.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span><?= th('ads.058') ?></span></th><td class="pm-cost">$30</td><td class="pm-cost">$40</td></tr>
                  <tr class="is-all"><th scope="row"><?= th('ads.059') ?></th><td class="pm-cost">$60</td><td class="pm-cost">$75</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <h3 class="pm-h3"><?= th('ads.060') ?></h3>
        <ul class="pm-formats">
          <li class="pm-fmt is-rail">
            <span class="pm-fmt-media" aria-hidden="true">
              <img src="assets/promo/placeholder-rail.webp" width="320" height="1200" alt="" loading="lazy" decoding="async" />
              <img src="assets/promo/placeholder-rail.webp" width="320" height="1200" alt="" loading="lazy" decoding="async" />
            </span>
            <span class="pm-fmt-text"><?= th('ads.061') ?></span>
          </li>
          <li class="pm-fmt is-strip">
            <span class="pm-fmt-media" aria-hidden="true"><img src="assets/promo/placeholder-strip.webp" width="1200" height="300" alt="" loading="lazy" decoding="async" /></span>
            <span class="pm-fmt-text"><?= th('ads.062') ?></span>
          </li>
          <li class="pm-fmt is-dock">
            <span class="pm-fmt-media" aria-hidden="true"><img src="assets/promo/placeholder-dock.webp" width="640" height="200" alt="" loading="lazy" decoding="async" /></span>
            <span class="pm-fmt-text"><?= th('ads.063') ?></span>
          </li>
          <li class="pm-fmt is-pop">
            <span class="pm-fmt-media" aria-hidden="true"><img src="assets/promo/house-tg-popup.webp" width="800" height="800" alt="" loading="lazy" decoding="async" /></span>
            <span class="pm-fmt-text"><?= th('ads.064') ?></span>
          </li>
          <li class="pm-fmt is-page">
            <span class="pm-thumb is-big" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-news.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
            <span class="pm-fmt-text"><?= th('ads.065') ?></span>
          </li>
          <li class="pm-fmt is-page">
            <span class="pm-thumb is-big" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-fruits.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
            <span class="pm-fmt-text"><?= th('ads.066') ?></span>
          </li>
          <li class="pm-fmt is-page">
            <span class="pm-thumb is-big" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-tier.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
            <span class="pm-fmt-text"><?= th('ads.067') ?></span>
          </li>
        </ul>

        <div class="pm-notes">
          <p><?= th('ads.068') ?></p>
          <p class="pm-boost">
            <img src="assets/design/legend/trend-up.svg" width="28" height="33" alt="" aria-hidden="true" loading="lazy" decoding="async" />
            <span><?= th('ads.069') ?></span>
          </p>
          <p><?= th('ads.073') ?></p>
        </div>
      </section>

      <section class="pm-sec" id="pm-bundle" aria-labelledby="pm-bundle-h">
        <h2 class="pm-h2" id="pm-bundle-h"><?= th('ads.074') ?></h2>
        <p class="pm-intro"><?= th('ads.075') ?></p>
        <ul class="pm-tiers">
          <li>
            <span class="pm-deck" aria-hidden="true">
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-tier.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-prices.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
            </span>
            <p><?= th('ads.076') ?></p>
          </li>
          <li>
            <span class="pm-deck" aria-hidden="true">
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-tier.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-prices.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-news.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
            </span>
            <p><?= th('ads.077') ?></p>
          </li>
          <li>
            <span class="pm-deck" aria-hidden="true">
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-tier.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-prices.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-news.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-fruits.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
            </span>
            <p><?= th('ads.078') ?></p>
          </li>
        </ul>
        <div class="pm-notes is-two">
          <p><?= th('ads.079') ?></p>
          <p><?= th('ads.080') ?></p>
        </div>
      </section>

      <section class="pm-sec" id="pm-telegram" aria-labelledby="pm-telegram-h">
        <img class="pm-branch is-l is-flip" style="--b-y: 120px; --b-w: 330px; --b-x: 140px; --b-r: 18deg; --b-o: .65" src="<?= HALLOWEEN ? 'assets/halloween/branch-l.webp' : 'assets/design/home/sakura.webp' ?>" width="868" height="890" alt="" aria-hidden="true" loading="lazy" decoding="async" />
<?php if (HALLOWEEN): ?>
        <img class="pm-bat is-l" style="--bat-x: 70px; --bat-y: 40px; --bat-w: 88px; --bat-r: 12deg" src="assets/halloween/bat.webp" width="650" height="232" alt="" aria-hidden="true" loading="lazy" decoding="async" />
<?php endif; ?>
        <h2 class="pm-h2" id="pm-telegram-h"><?= th('ads.081') ?></h2>
        <div class="pm-split is-even">
          <div class="pm-main">
            <div class="pm-tbl">
              <table class="pm-table">
                <thead>
                  <tr><th scope="col"><?= th('ads.082') ?></th><th scope="col" class="pm-num"><?= th('ads.083') ?></th></tr>
                </thead>
                <tbody>
                  <tr><th scope="row"><?= th('ads.084') ?></th><td class="pm-cost">$18</td></tr>
                  <tr><th scope="row"><?= th('ads.085') ?></th><td class="pm-cost">$13</td></tr>
                  <tr><th scope="row"><?= th('ads.086') ?></th><td class="pm-cost">$3</td></tr>
                  <tr><th scope="row"><?= th('ads.087') ?></th><td class="pm-cost">$2</td></tr>
                </tbody>
              </table>
            </div>
            <p class="pm-aside"><?= th('ads.088') ?></p>
          </div>
          <div class="pm-side pm-deal">
            <p class="pm-deal-title"><?= th('ads.089') ?></p>
            <ul class="pm-list">
              <li><?= th('ads.090') ?></li>
              <li><?= th('ads.091') ?></li>
              <li><?= th('ads.092') ?></li>
            </ul>
            <p class="pm-deal-extra"><?= th('ads.093') ?></p>
          </div>
        </div>
      </section>

      <section class="pm-sec" id="pm-combo" aria-labelledby="pm-combo-h">
        <h2 class="pm-h2" id="pm-combo-h"><?= th('ads.094') ?></h2>
        <div class="pm-tbl is-wide">
          <table class="pm-table pm-combo">
            <thead>
              <tr><th scope="col"><?= th('ads.095') ?></th><th scope="col"><?= th('ads.096') ?></th><th scope="col" class="pm-num"><?= th('ads.097') ?></th></tr>
            </thead>
            <tbody>
              <tr><th scope="row"><?= th('ads.098') ?></th><td class="pm-what"><?= th('ads.099') ?></td><td class="pm-cost">$20</td></tr>
              <tr><th scope="row"><?= th('ads.100') ?></th><td class="pm-what"><?= th('ads.101') ?></td><td class="pm-cost">$40</td></tr>
              <tr><th scope="row"><?= th('ads.102') ?></th><td class="pm-what"><?= th('ads.103') ?></td><td class="pm-cost">$125</td></tr>
            </tbody>
          </table>
        </div>
        <div class="pm-notes is-two">
          <p><?= th('ads.104') ?></p>
          <p class="pm-plus"><?= th('ads.105') ?></p>
        </div>
      </section>

      <section class="pm-sec" id="pm-clicks" aria-labelledby="pm-clicks-h">
        <img class="pm-branch is-r" style="--b-y: 40px; --b-w: 380px; --b-x: 170px; --b-r: -8deg; --b-o: .75" src="<?= HALLOWEEN ? 'assets/halloween/branch-r.webp' : 'assets/design/home/sakura.webp' ?>" width="<?= HALLOWEEN ? 995 : 868 ?>" height="<?= HALLOWEEN ? 936 : 890 ?>" alt="" aria-hidden="true" loading="lazy" decoding="async" />
        <h2 class="pm-h2" id="pm-clicks-h"><?= th('ads.106') ?></h2>
        <div class="pm-split">
          <p class="pm-main pm-intro"><?= th('ads.107') ?></p>
          <div class="pm-side">
            <div class="pm-tbl">
              <table class="pm-table">
                <thead>
                  <tr><th scope="col"><?= th('ads.108') ?></th><th scope="col" class="pm-num"><?= th('ads.109') ?></th></tr>
                </thead>
                <tbody>
                  <tr><th scope="row"><?= th('ads.110') ?></th><td class="pm-cost">$24</td></tr>
                  <tr><th scope="row"><?= th('ads.111') ?></th><td class="pm-cost">$36</td></tr>
                </tbody>
              </table>
            </div>
          </div>
          <p class="pm-main"><?= th('ads.112') ?></p>
          <p class="pm-main"><?= th('ads.113') ?></p>
          <p class="pm-main pm-quiet"><?= th('ads.114') ?></p>
        </div>
      </section>

      <section class="pm-sec" id="pm-make" aria-labelledby="pm-make-h">
        <h2 class="pm-h2" id="pm-make-h"><?= th('ads.115') ?></h2>
        <div class="pm-make">
          <div class="pm-make-copy">
            <p class="pm-intro"><?= th('ads.116') ?></p>
            <p class="pm-quiet"><?= th('ads.117') ?></p>
          </div>
          <div class="pm-make-pair" aria-hidden="true">
            <img src="assets/promo/placeholder-dock.webp" width="640" height="200" alt="" loading="lazy" decoding="async" />
            <svg class="pm-make-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <img src="assets/promo/channel-dock.webp" width="640" height="200" alt="" loading="lazy" decoding="async" />
          </div>
        </div>
      </section>

      <section class="pm-sec is-trial" id="pm-trial" aria-labelledby="pm-trial-h">
        <h2 class="pm-h2" id="pm-trial-h"><?= th('ads.118') ?></h2>
        <div class="pm-trial-body">
          <p class="pm-intro"><?= th('ads.119') ?></p>
          <p class="pm-quiet"><?= th('ads.120') ?></p>
        </div>
      </section>

      <section class="pm-sec" id="pm-projects" aria-labelledby="pm-projects-h">
        <img class="pm-branch is-l" style="--b-y: 40px; --b-w: 340px; --b-x: 250px; --b-r: 96deg; --b-o: .7" src="<?= HALLOWEEN ? 'assets/halloween/branch-l.webp' : 'assets/design/home/sakura.webp' ?>" width="868" height="890" alt="" aria-hidden="true" loading="lazy" decoding="async" />
<?php if (HALLOWEEN): ?>
        <img class="pm-bat is-r" style="--bat-x: 90px; --bat-y: 60px; --bat-w: 104px; --bat-r: -16deg" src="assets/halloween/bat.webp" width="650" height="232" alt="" aria-hidden="true" loading="lazy" decoding="async" />
<?php endif; ?>
        <h2 class="pm-h2" id="pm-projects-h"><?= th('ads.121') ?></h2>
        <p class="pm-intro"><?= th('ads.122') ?></p>
        <ul class="pm-list is-cols">
          <li><?= th('ads.123') ?></li>
          <li><?= th('ads.124') ?></li>
          <li><?= th('ads.125') ?></li>
          <li><?= th('ads.126') ?></li>
        </ul>
        <p><?= th('ads.127') ?></p>
      </section>

      <section class="pm-sec" id="pm-rules" aria-labelledby="pm-rules-h">
        <h2 class="pm-h2" id="pm-rules-h"><?= th('ads.128') ?></h2>
        <ul class="pm-rules">
          <li><?= th('ads.129') ?></li>
          <li><?= th('ads.130') ?></li>
          <li><?= th('ads.131') ?></li>
          <li><?= th('ads.132') ?></li>
          <li><?= th('ads.133') ?></li>
          <li><?= th('ads.134') ?></li>
          <li><?= th('ads.135') ?></li>
          <li><?= th('ads.136') ?></li>
        </ul>
      </section>

      <section class="pm-sec pm-order" id="pm-order" aria-labelledby="pm-order-h">
        <img class="pm-branch is-r is-flip" style="--b-y: -40px; --b-w: 420px; --b-x: 200px; --b-r: 12deg; --b-o: .85" src="<?= HALLOWEEN ? 'assets/halloween/branch-r.webp' : 'assets/design/home/sakura.webp' ?>" width="<?= HALLOWEEN ? 995 : 868 ?>" height="<?= HALLOWEEN ? 936 : 890 ?>" alt="" aria-hidden="true" loading="lazy" decoding="async" />
<?php if (HALLOWEEN): ?>
        <img class="pm-bat is-r" style="--bat-x: 110px; --bat-y: -30px; --bat-w: 76px; --bat-r: 8deg" src="assets/halloween/bat.webp" width="650" height="232" alt="" aria-hidden="true" loading="lazy" decoding="async" />
<?php endif; ?>
        <div class="pm-order-copy">
          <h2 class="pm-h2" id="pm-order-h"><?= th('ads.137') ?></h2>
          <p><?= th('ads.138') ?></p>
          <p class="pm-order-all"><?= th('ads.139') ?></p>
        </div>
        <a class="pm-btn" href="https://t.me/mksvtn" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path fill-rule="evenodd" fill="currentColor" d="M21.4 3.7 2.9 10.9c-.9.4-.9 1.5 0 1.8l4.5 1.4 1.7 5.4c.2.6 1 .8 1.5.3l2.5-2.4 4.5 3.3c.6.4 1.4.1 1.6-.6l3.2-15.4c.2-.8-.4-1.3-1-1Zm-4.2 3.9-7.9 7 .5 3 .6-2.4 6.8-7.6Z"/></svg>
          <span>@mksvtn</span>
        </a>
      </section>
    </div>
  </main>

  <footer class="mk-foot">
    <img class="mk-foot-mark" src="assets/design/logo-mk-square.png" alt="MAKNEMY" />
    <ul class="mk-foot-roles">
      <li><span data-i18n="site.footAuthor"><?= te('site.footAuthor') ?></span><span class="mk-foot-nick">MKSVTN</span></li>
      <li><span data-i18n="site.footDesigner"><?= te('site.footDesigner') ?></span><span class="mk-foot-nick">DANIKTOR</span></li>
      <li><span data-i18n="site.footAnalyst"><?= te('site.footAnalyst') ?></span><span class="mk-foot-nick">GLH</span></li>
      <li><span data-i18n="site.footAnalystAide"><?= te('site.footAnalystAide') ?></span><span class="mk-foot-nick" data-i18n="site.footHiring"><?= te('site.footHiring') ?></span></li>
      <li><span data-i18n="site.footCoder"><?= te('site.footCoder') ?></span><span class="mk-foot-nick">The Fool</span></li>
    </ul>
    <p class="mk-foot-tagline" data-i18n="site.footTagline"><?= te('site.footTagline') ?></p>

    <p class="mk-foot-legal">
      <a href="/privacy" data-i18n="site.footPrivacy"><?= te('site.footPrivacy') ?></a>
      <a href="/terms" data-i18n="site.footTerms"><?= te('site.footTerms') ?></a>
    </p>
  </footer>
  <?= i18n_dict_tag() ?>
  <script src="js/i18n.js?v=84" fetchpriority="high"></script>
  <script src="js/news-blocks.js?v=4" fetchpriority="high"></script>
  <script src="js/giveaway.js?v=13" fetchpriority="high"></script>
  <script src="js/giveaway-pop.js?v=1" fetchpriority="high"></script>
</body>
</html>
