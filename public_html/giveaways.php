<?php
require_once __DIR__ . '/api/_bootstrap.php';
require_once __DIR__ . '/api/lib/metrika.php';

// Розыгрыши — /giveaways: идущие розыгрыши с полным описанием и кнопкой
// участия, ниже — прошедшие с победителями.
//
// Страница — каркас, как /stock: карточки рисует js/giveaways-page.js той же
// функцией, что и окно на остальных страницах (js/giveaway.js), по ответу
// /api/giveaways.php. Разметка от сессии не зависит и лежит в кеше LiteSpeed.
// Окна розыгрыша здесь нет: страница сама и есть розыгрыш.
//
// Ссылка из Telegram ведёт на /giveaways#g<id> — карточка с этим id
// подсвечивается и прокручивается в начало экрана.
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

<title><?= te('meta.giveaways.title') ?></title>
<meta name="description" content="<?= ta('meta.giveaways.desc') ?>" />
<link rel="canonical" href="<?= i18n_url('/giveaways') ?>" />
<?= i18n_alternates('/giveaways') ?>
<meta name="robots" content="index, follow, max-image-preview:large" />

<meta property="og:type" content="website" />
<meta property="og:site_name" content="Maknemy Tier List" />
<meta property="og:locale" content="<?= i18n_og_locale() ?>" />
<meta property="og:url" content="<?= i18n_url('/giveaways') ?>" />
<meta property="og:title" content="<?= ta('meta.giveaways.ogTitle') ?>" />
<meta property="og:description" content="<?= ta('meta.giveaways.ogDesc') ?>" />
<meta name="twitter:card" content="summary" />

<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="icon" type="image/png" href="/assets/favicon.png?v=2" sizes="256x256" />
<link rel="apple-touch-icon" href="/apple-touch-icon.png" />

<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/base.css?v=15" /><?php else: ?><link rel="stylesheet" href="css/base.css?v=15" /><?php endif; ?>

<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/topbar.css?v=30" /><?php else: ?><link rel="stylesheet" href="css/topbar.css?v=30" /><?php endif; ?>

<script src="js/auth.js?v=2" fetchpriority="high"></script>
<script src="js/topbar.js?v=31" defer fetchpriority="high"></script>

<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/design-page.css?v=43" /><?php else: ?><link rel="stylesheet" href="css/design-page.css?v=43" /><?php endif; ?>
<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/giveaway.css?v=7" /><?php else: ?><link rel="stylesheet" href="css/giveaway.css?v=7" /><?php endif; ?>
<link rel="stylesheet" href="css/promo-stamp.css?v=3" />
<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/halloween.css?v=4" /><?php endif; ?>

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
          <a class="mk-pill" href="<?= u('/ads') ?>">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M5.6 6.9V12.1M5.6 12.1L6.75 16.55C6.98 17.43 7.77 18.05 8.68 18.05C9.98 18.05 10.93 16.82 10.6 15.56L10.1 13.65M2.95 6.9H5.6L12.85 3.05C13.88 2.5 15.1 3.25 15.1 4.4V14.6C15.1 15.75 13.88 16.5 12.85 15.95L5.6 12.1H2.95C1.85 12.1 0.95 11.2 0.95 10.1V8.9C0.95 7.8 1.85 6.9 2.95 6.9ZM17.45 7.3C18.18 8.65 18.18 10.35 17.45 11.7" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="mk-pill-text" data-i18n="nav.promote"><?= te('nav.promote') ?></span>
          </a>
        </li>
      </ul>

      <a class="mk-promote" href="<?= u('/giveaways') ?>" aria-current="page" data-i18n="nav.giveaways"><?= te('nav.giveaways') ?></a>

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

  <main class="gw-page">
    <?= halloween_decor('giveaways', 'hw-decor hw-decor--column') ?>
    <header class="gw-head">
      <div class="gw-head-in">
        <img class="gw-head-branch<?= HALLOWEEN ? ' is-flip' : '' ?>" src="<?= HALLOWEEN ? 'assets/halloween/branch-r.webp' : 'assets/design/home/sakura.webp' ?>" alt="" aria-hidden="true" />
        <span class="gw-ghost gw-ghost-a" aria-hidden="true" data-i18n="gw.title"><?= te('gw.title') ?></span>
        <span class="gw-ghost gw-ghost-b" aria-hidden="true" data-i18n="gw.title"><?= te('gw.title') ?></span>
        <h1 class="gw-page-title" data-i18n="gw.title"><?= te('gw.title') ?></h1>
        <p class="gw-kicker" data-i18n="gw.kicker"><?= te('gw.kicker') ?></p>
        <p class="gw-lead" id="gwLead" data-i18n="gw.lead"><?= te('gw.lead') ?></p>
<?php
// Подписка на обновления розыгрышей. Гость видит строку gw-lead как есть;
// вошедшему js/gw-notify.js прячет её и показывает этот блок: вопрос и
// «Подписаться» (бот ещё не подключён или подписка выключена) либо «Вы
// подписаны» и настройки напоминаний. Разметка от сессии не зависит —
// страница лежит в кеше, состояние выбирает скрипт по tg_link.php.
?>
        <section class="gw-nt" id="gwNotify" aria-labelledby="gwNtTitle" data-state="ask" hidden>
          <span class="gw-nt-tile" aria-hidden="true">
            <svg class="gw-nt-bell" viewBox="0 0 24 24" focusable="false"><path d="M12 3.2a5.6 5.6 0 0 0-5.6 5.6v3.5c0 1.3-.5 2.5-1.4 3.4l-.6.7h15.2l-.6-.7c-.9-.9-1.4-2.1-1.4-3.4V8.8A5.6 5.6 0 0 0 12 3.2Z"/><path d="M9.7 19.6a2.4 2.4 0 0 0 4.6 0"/></svg>
            <svg class="gw-nt-tick" viewBox="0 0 24 24" focusable="false"><path d="m5.6 12.6 4.1 4.1 8.7-9.3"/></svg>
          </span>
          <div class="gw-nt-body">
            <div class="gw-nt-text" aria-live="polite">
              <p class="gw-nt-title" id="gwNtTitle">Хотите первыми узнавать о новых розыгрышах?</p>
              <p class="gw-nt-note" id="gwNtNote"></p>
            </div>
            <div class="gw-nt-actions">
              <button class="gw-nt-go" id="gwNtGo" type="button">
                <svg class="gw-nt-plane" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill-rule="evenodd" fill="currentColor" d="M21.4 3.7 2.9 10.9c-.9.4-.9 1.5 0 1.8l4.5 1.4 1.7 5.4c.2.6 1 .8 1.5.3l2.5-2.4 4.5 3.3c.6.4 1.4.1 1.6-.6l3.2-15.4c.2-.8-.4-1.3-1-1Zm-4.2 3.9-7.9 7 .5 3 .6-2.4 6.8-7.6Z"/></svg>
                <span data-i18n="gw.notify.go"><?= te('gw.notify.go') ?></span>
              </button>
              <a class="gw-nt-go" id="gwNtBot" href="https://t.me/" target="_blank" rel="noopener" hidden>
                <svg class="gw-nt-plane" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill-rule="evenodd" fill="currentColor" d="M21.4 3.7 2.9 10.9c-.9.4-.9 1.5 0 1.8l4.5 1.4 1.7 5.4c.2.6 1 .8 1.5.3l2.5-2.4 4.5 3.3c.6.4 1.4.1 1.6-.6l3.2-15.4c.2-.8-.4-1.3-1-1Zm-4.2 3.9-7.9 7 .5 3 .6-2.4 6.8-7.6Z"/></svg>
                <span data-i18n="gw.notify.openBot"><?= te('gw.notify.openBot') ?></span>
              </a>
              <button class="gw-sub gw-sub-btn gw-nt-set" id="gwNtSet" type="button" aria-expanded="false" aria-controls="gwNtPanel" hidden>
                <span data-i18n="gw.notify.settings"><?= te('gw.notify.settings') ?></span>
                <svg class="gw-nt-chev" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6.5 9.5 5.5 5.5 5.5-5.5"/></svg>
              </button>
            </div>
            <p class="gw-nt-msg" id="gwNtMsg" role="status" aria-live="polite"></p>

            <form class="gw-nt-panel" id="gwNtPanel" novalidate hidden>
              <label class="gw-nt-sw">
                <span class="gw-nt-sw-text">
                  <span class="gw-nt-sw-name" id="gwNtOnName" data-i18n="gw.notify.msgs"><?= te('gw.notify.msgs') ?></span>
                  <span class="gw-nt-sw-hint" id="gwNtOnHint" data-i18n="gw.notify.msgsHint"><?= te('gw.notify.msgsHint') ?></span>
                </span>
                <input class="gw-nt-switch" id="gwNtOn" type="checkbox" role="switch" aria-labelledby="gwNtOnName" aria-describedby="gwNtOnHint" />
              </label>

              <fieldset class="gw-nt-rem" id="gwNtRem">
                <legend class="gw-nt-legend" data-i18n="gw.notify.remind"><?= te('gw.notify.remind') ?></legend>
                <div class="gw-nt-row">
                  <input class="gw-nt-box" id="gwNtTime" type="checkbox" />
                  <label class="gw-nt-label" id="gwNtTimeL" for="gwNtTime" data-i18n="gw.notify.timeLabel"><?= te('gw.notify.timeLabel') ?></label>
                  <span class="gw-nt-ctl">
                    <input class="gw-nt-num" id="gwNtTimeN" type="number" inputmode="numeric" min="1" step="1" aria-labelledby="gwNtTimeL" />
                    <span class="gw-nt-sel">
                      <select id="gwNtTimeU" data-i18n-label="gw.notify.unit" aria-label="<?= ta('gw.notify.unit') ?>">
                        <option value="60">минут</option>
                        <option value="3600">часов</option>
                        <option value="86400">дней</option>
                      </select>
                    </span>
                  </span>
                </div>
                <div class="gw-nt-row">
                  <input class="gw-nt-box" id="gwNtPlaces" type="checkbox" aria-describedby="gwNtPlacesH" />
                  <label class="gw-nt-label" id="gwNtPlacesL" for="gwNtPlaces" data-i18n="gw.notify.placesLabel"><?= te('gw.notify.placesLabel') ?></label>
                  <span class="gw-nt-ctl">
                    <input class="gw-nt-num" id="gwNtPlacesN" type="number" inputmode="numeric" min="1" max="100000" step="1" aria-labelledby="gwNtPlacesL gwNtPlacesU" />
                    <span class="gw-nt-unit" id="gwNtPlacesU">мест</span>
                  </span>
                  <span class="gw-nt-hint" id="gwNtPlacesH" data-i18n="gw.notify.placesHint"><?= te('gw.notify.placesHint') ?></span>
                </div>
              </fieldset>

              <div class="gw-nt-foot">
                <button class="gw-nt-save" id="gwNtSave" type="submit" data-i18n="gw.notify.save"><?= te('gw.notify.save') ?></button>
                <p class="gw-nt-saved" id="gwNtSaved" role="status" aria-live="polite"></p>
              </div>
            </form>
          </div>
        </section>
        <img class="gw-head-gift" src="<?= hw_img('assets/design/home/card-giveaways.webp') ?>" width="482" height="482" alt="" aria-hidden="true" />
      </div>
    </header>

    <div class="gw-lid">
      <div class="gw-rail-slot gw-rail-l" aria-hidden="true">
        <aside class="gw-rail" id="gwRailL" data-i18n-label="promo.rail" aria-label="<?= ta('promo.rail') ?>"></aside>
      </div>
      <div class="gw-rail-slot gw-rail-r" aria-hidden="true">
        <aside class="gw-rail" id="gwRailR" data-i18n-label="promo.rail" aria-label="<?= ta('promo.rail') ?>"></aside>
      </div>
      <img class="gw-deco gw-branch-l" src="<?= HALLOWEEN ? 'assets/halloween/branch.webp' : 'assets/design/home/sakura.webp' ?>" alt="" aria-hidden="true" />
      <img class="gw-deco gw-branch-r<?= HALLOWEEN ? ' is-hw' : '' ?>" src="<?= HALLOWEEN ? 'assets/halloween/gw-branch-r.webp' : 'assets/design/home/sakura.webp' ?>" alt="" aria-hidden="true" />

      <div class="gw-wrap">
        <p class="gw-state" id="gwState" role="status" aria-live="polite" data-i18n="gw.loading"><?= te('gw.loading') ?></p>
        <div class="gw-live" id="gwLive"></div>

        <section id="gwPastWrap" hidden>
          <h2 class="gw-section-title" data-i18n="gw.past"><?= te('gw.past') ?></h2>
          <div class="gw-past" id="gwPast"></div>
        </section>
      </div>
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
  <script src="js/i18n.js?v=83" fetchpriority="high"></script>
  <script src="js/promo.js?v=31" fetchpriority="high"></script>
  <script src="js/giveaway.js?v=14" fetchpriority="high"></script>
  <script src="js/giveaways-page.js?v=3" fetchpriority="high"></script>
  <script src="js/gw-notify.js?v=1" fetchpriority="high"></script>
</body>
</html>
