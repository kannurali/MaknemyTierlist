<?php
require_once __DIR__ . '/api/_bootstrap.php';
require_once __DIR__ . '/api/lib/metrika.php';

// Трейдинг — /trading (Figma «трейдинг», нода 243:4078).
//
// Страница — каркас: ленту объявлений наполняет js/trading-page.js по ответу
// /api/trades.php, картинки и цены предметов — из того же тирлиста, что у
// калькулятора (/api/tierlist.php). Разметка от сессии не зависит, поэтому
// её можно держать в кеше LiteSpeed, как калькулятор: кто вошёл, решает
// скрипт.
//
// Макет → разметка: слева рекламный борт (как у калькулятора), в центре три
// кнопки («уточнить цены» → /calculator, «создать» → /trading/new,
// «поддержка» → /support), поиск и лента; справа выезжающая карточка своего
// профиля (Frame 96). Клик по чужому объявлению ведёт в чат с автором —
// в прототипе карточка ведёт на «трейдинг чат» (244:5707).
header('Cache-Control: no-cache, must-revalidate');
page_lscache();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="color-scheme" content="dark" />

<base href="/" />

<title>Трейдинг Blox Fruits — объявления об обмене | Maknemy</title>
<meta name="description" content="Доска трейдов Blox Fruits от Maknemy: объявления игроков об обмене фруктов, пермов и геймпассов по ценам тирлиста. Найдите предмет и напишите автору в чат." />
<link rel="canonical" href="https://maknemy.com/trading" />
<meta name="robots" content="index, follow, max-image-preview:large" />

<meta property="og:type" content="website" />
<meta property="og:site_name" content="Maknemy Tier List" />
<meta property="og:locale" content="ru_RU" />
<meta property="og:url" content="https://maknemy.com/trading" />
<meta property="og:title" content="Трейдинг Blox Fruits" />
<meta property="og:description" content="Объявления игроков об обмене по ценам тирлиста Maknemy." />
<meta name="twitter:card" content="summary" />

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "CollectionPage",
      "name": "Трейдинг Blox Fruits",
      "url": "https://maknemy.com/trading",
      "inLanguage": "ru",
      "description": "Объявления игроков Blox Fruits об обмене предметов по ценам тирлиста Maknemy.",
      "isPartOf": {
        "@type": "WebSite",
        "name": "Maknemy Tier List",
        "url": "https://maknemy.com/"
      }
    },
    {
      "@type": "BreadcrumbList",
      "itemListElement": [
        {
          "@type": "ListItem",
          "position": 1,
          "name": "Maknemy",
          "item": "https://maknemy.com/"
        },
        {
          "@type": "ListItem",
          "position": 2,
          "name": "Трейдинг",
          "item": "https://maknemy.com/trading"
        }
      ]
    }
  ]
}
</script>

<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="icon" type="image/png" href="/assets/favicon.png?v=2" sizes="256x256" />
<link rel="apple-touch-icon" href="/apple-touch-icon.png" />

<link rel="stylesheet" href="css/base.css?v=11" />

<link rel="stylesheet" href="css/topbar.css?v=12" />

<script src="js/auth.js?v=1" fetchpriority="high"></script>
<script src="js/topbar.js?v=8" defer fetchpriority="high"></script>

<link rel="stylesheet" href="css/design-page.css?v=33" />
<link rel="stylesheet" href="css/trading.css?v=1" />

<link rel="stylesheet" href="css/promo-dock.css?v=3" />

<link rel="stylesheet" href="css/promo-popup.css?v=3" />

<?php echo metrika_counter_html(); ?>
</head>
<body>

  <header class="mk-top">
    <a class="mk-top-brand" href="/">
      <img class="mk-top-mark" src="assets/design/logo-mk-square.png" alt="" aria-hidden="true" />
      <img class="mk-top-word" src="assets/design/wordmark.svg" alt="MAKNEMY" />
    </a>

    <div class="mk-top-lang lang-switch" id="langSwitch" role="group"
         data-i18n-label="lang.switch" aria-label="Язык интерфейса">
      <button class="chip" type="button" data-lang="ru" data-i18n="lang.ru" aria-pressed="false">RU</button>
      <button class="chip" type="button" data-lang="en" data-i18n="lang.en" aria-pressed="false">EN</button>
    </div>

    <nav class="mk-top-bar" id="mkTopBar" aria-label="Разделы сайта">
      <ul class="mk-nav">
        <li>
          <a class="mk-pill" href="/">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M18.05 16.0302V8.423C18.05 7.48807 17.644 6.60551 16.9498 6.03152L11.833 1.80094C10.4608 0.666372 8.53926 0.666371 7.16704 1.80094L2.05028 6.03152C1.35606 6.60551 0.950013 7.48807 0.950013 8.423V16.0302C0.950013 17.1457 1.80067 18.05 2.85001 18.05H4.75001C5.79936 18.05 6.65001 17.1994 6.65001 16.15V13.0006C6.65001 11.8851 7.50067 10.9808 8.55002 10.9808H10.45C11.4994 10.9808 12.35 11.8851 12.35 13.0006V16.15C12.35 17.1994 13.2007 18.05 14.25 18.05H16.15C17.1994 18.05 18.05 17.1457 18.05 16.0302Z" stroke="currentColor" stroke-width="1.81101"/></svg>
            <span class="mk-pill-text" data-i18n="nav.home">Главная</span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="/tierlist">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M8.57627 3.7533C8.57627 3.22702 8.14799 2.79425 7.62582 2.85987C6.45486 3.00701 5.32947 3.42467 4.341 4.08515C3.08735 4.9228 2.11026 6.1134 1.53327 7.50637C0.95628 8.89935 0.805314 10.4321 1.09946 11.9109C1.39361 13.3897 2.11965 14.748 3.18579 15.8142C4.25193 16.8803 5.61027 17.6063 7.08904 17.9005C8.56781 18.1946 10.1006 18.0437 11.4936 17.4667C12.8866 16.8897 14.0771 15.9126 14.9148 14.659C15.5753 13.6705 15.9929 12.5451 16.1401 11.3741C16.2057 10.852 15.7729 10.4237 15.2466 10.4237H9.52918C9.0029 10.4237 8.57627 9.99705 8.57627 9.47077V3.7533Z" stroke="currentColor" stroke-width="1.82067"/><path d="M11.435 1.84748C11.435 1.3212 11.8638 0.887589 12.3847 0.962518C12.934 1.04153 13.4726 1.18898 13.9876 1.40232C14.7969 1.73754 15.5323 2.22887 16.1517 2.84828C16.7711 3.46768 17.2624 4.20302 17.5976 5.0123C17.811 5.52735 17.9584 6.06592 18.0374 6.61527C18.1124 7.13618 17.6787 7.56495 17.1525 7.56495L11.5303 7.56495C11.4777 7.56495 11.435 7.52228 11.435 7.46965V1.84748Z" stroke="currentColor" stroke-width="1.82067"/></svg>
            <span class="mk-pill-text" data-i18n="nav.tierlist">Тирлист</span>
          </a>
        </li>
        <li>

          <a class="mk-pill" href="/trading" aria-current="page">
            <svg viewBox="0 0 18 19" fill="none" aria-hidden="true"><path d="M6.17037 0.943433L4.48309 4.31799M11.8297 0.943433L13.517 4.31799M11.8297 9.4324L8.29262 13.2053L6.17037 11.4903M5.6697 17.9214H12.3304C14.2079 17.9214 15.7998 16.5408 16.0653 14.6821L17.0276 7.94613C17.2711 6.24146 15.9484 4.71631 14.2264 4.71631H3.77368C2.0517 4.71631 0.728943 6.24145 0.972468 7.94613L1.93474 14.6821C2.20027 16.5408 3.79212 17.9214 5.6697 17.9214Z" stroke="currentColor" stroke-width="1.88644" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="mk-pill-text" data-i18n="nav.trading">Трейдинг</span>
          </a>
        </li>
        <li>

          <a class="mk-pill" href="/calculator">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M5.70001 8.55001V13.3M13.3 10.45V13.3M9.5 5.70001V13.3M4.75001 18.05H14.25C16.3487 18.05 18.05 16.3487 18.05 14.25V4.75001C18.05 2.65134 16.3487 0.950022 14.25 0.950022H4.75001C2.65134 0.950022 0.950022 2.65134 0.950022 4.75001V14.25C0.950022 16.3487 2.65134 18.05 4.75001 18.05Z" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
            <span class="mk-pill-text" data-i18n="nav.calculator">Калькулятор</span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="/news">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M18.05 9.50002C18.05 14.2221 14.222 18.05 9.49995 18.05M18.05 9.50002C18.05 4.77798 14.222 0.950013 9.49995 0.950013M18.05 9.50002C18.05 7.92601 14.222 6.65002 9.49995 6.65002C4.77792 6.65002 0.949949 7.92601 0.949949 9.50002M18.05 9.50002C18.05 11.074 14.222 12.35 9.49995 12.35C4.77792 12.35 0.949949 11.074 0.949949 9.50002M9.49995 18.05C4.77792 18.05 0.949949 14.2221 0.949949 9.50002M9.49995 18.05C11.074 18.05 12.35 14.2221 12.35 9.50002C12.35 4.77798 11.074 0.950013 9.49995 0.950013M9.49995 18.05C7.92594 18.05 6.64995 14.2221 6.64995 9.50002C6.64995 4.77798 7.92594 0.950013 9.49995 0.950013M0.949949 9.50002C0.949949 4.77798 4.77792 0.950013 9.49995 0.950013" stroke="currentColor" stroke-width="1.9"/></svg>
            <span class="mk-pill-text" data-i18n="nav.news">Новости</span>
          </a>
        </li>
      </ul>

      <a class="mk-chat" href="/chat" data-i18n-label="nav.chat" aria-label="Чат">
        <svg viewBox="0 0 25 25" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M12.0833 0C5.40989 0 0 5.40989 0 12.0833C0 14.2768 0.585445 16.3362 1.60861 18.1109C1.817 18.4723 1.85274 18.9124 1.67689 19.2907L0.645317 21.5102C0.0119158 22.7086 0.878898 24.1667 2.24942 24.1667H12.0833C18.7568 24.1667 24.1667 18.7568 24.1667 12.0833C24.1667 5.40989 18.7568 0 12.0833 0ZM8.45833 8.45833C7.79099 8.45833 7.25 8.99932 7.25 9.66667C7.25 10.334 7.79099 10.875 8.45833 10.875H10.875C11.5423 10.875 12.0833 10.334 12.0833 9.66667C12.0833 8.99932 11.5423 8.45833 10.875 8.45833H8.45833ZM8.45833 13.2917C7.79099 13.2917 7.25 13.8327 7.25 14.5C7.25 15.1673 7.79099 15.7083 8.45833 15.7083H15.7083C16.3757 15.7083 16.9167 15.1673 16.9167 14.5C16.9167 13.8327 16.3757 13.2917 15.7083 13.2917H8.45833Z" fill="currentColor"/></svg>
      </a>

      <button class="mk-avatar" type="button" aria-label="Профиль" data-soon data-i18n-title="topbar.soon" title="В активной разработке">
        <svg viewBox="0 0 34 34" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M17.0003 2.83325C13.0883 2.83325 9.91699 6.00457 9.91699 9.91659C9.91699 13.8286 13.0883 16.9999 17.0003 16.9999C20.9123 16.9999 24.0837 13.8286 24.0837 9.91659C24.0837 6.00457 20.9123 2.83325 17.0003 2.83325Z" fill="currentColor"/><path fill-rule="evenodd" clip-rule="evenodd" d="M12.7503 18.4167C10.3947 18.4167 8.12945 19.4913 6.80192 21.109C6.12816 21.9301 5.65451 22.946 5.61326 24.072C5.57114 25.2218 5.98621 26.3442 6.8422 27.3234C8.92833 29.7099 12.2591 31.1667 17.0003 31.1667C21.7415 31.1667 25.0723 29.7099 27.1584 27.3234C28.0144 26.3442 28.4294 25.2218 28.3873 24.072C28.3461 22.946 27.8724 21.9301 27.1987 21.109C25.8711 19.4913 23.6058 18.4167 21.2503 18.4167H12.7503Z" fill="currentColor"/></svg>
      </button>
    </nav>

    <button class="mk-top-toggle" type="button" id="mkTopToggle"
            aria-expanded="true" aria-controls="mkTopBar"
            data-i18n-label="topbar.showNav" aria-label="Показать разделы"
            data-i18n-title="topbar.showNav" title="Показать разделы">
      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 5.5 8.5 12l6.5 6.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
  </header>
  <svg class="tr-sprite" aria-hidden="true" focusable="false">
    <defs>
      <linearGradient id="trGrad" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#61B5E9"/><stop offset="1" stop-color="#2D4AED"/>
      </linearGradient>
    </defs>
    <symbol id="trIconUp" viewBox="0 0 26 24">
      <path d="M13 23.2C7.4 19.4 1.2 14.6 1.2 8.3 1.2 4.6 4 1.6 7.6 1.6c2.2 0 4 1 5.4 2.9 1.4-1.9 3.2-2.9 5.4-2.9 3.6 0 6.4 3 6.4 6.7 0 6.3-6.2 11.1-11.8 14.9Z" fill="currentColor"/>
    </symbol>
    <symbol id="trIconDown" viewBox="0 0 26 24">
      <path d="M12.2 4.2C10.9 2.6 9.3 1.6 7.4 1.6 3.9 1.6 1.2 4.6 1.2 8.3c0 6 5.7 10.7 11.1 14.4l-1.5-4.9 2.4-3.7-2.9-3.8 2.5-3.5-.6-2.6Z" fill="currentColor"/>
      <path d="M14.4 4.3c1.3-1.7 2.9-2.7 4.8-2.7 3.5 0 6.2 3 6.2 6.7 0 6-5.7 10.7-11.1 14.4l-.9-4.4 2.5-4.2-3-3.8 2.4-3.4-.9-2.6Z" fill="currentColor"/>
    </symbol>
    <symbol id="trIconSwap" viewBox="0 0 36 44">
      <path d="M6.6 2.2 33 10.6a1.5 1.5 0 0 1 0 2.8L6.6 21.8A1.5 1.5 0 0 1 4.6 20.4V3.6a1.5 1.5 0 0 1 2-1.4Z" fill="url(#trGrad)"/>
      <path d="M29.4 22.2 3 30.6a1.5 1.5 0 0 0 0 2.8l26.4 8.4a1.5 1.5 0 0 0 2-1.4V23.6a1.5 1.5 0 0 0-2-1.4Z" fill="url(#trGrad)"/>
    </symbol>
  </svg>

  <main class="tr-page">
    <div class="tr-frame">

      <div class="tr-rail-slot" aria-hidden="true">
        <aside class="tr-rail" id="trRail" data-i18n-label="promo.rail" aria-label="Реклама сбоку"></aside>
      </div>

      <section class="tr-main" aria-labelledby="trTitle">
        <h1 class="tr-sr-only" id="trTitle" data-i18n="trade.title">Трейдинг</h1>

        <nav class="tr-tools" data-i18n-label="trade.toolsLabel" aria-label="Действия">
          <a class="tr-tool" href="/calculator">
            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M0 5C0 2.239 2.239 0 5 0H15C17.761 0 20 2.239 20 5V15C20 17.761 17.761 20 15 20H5C2.239 20 0 17.761 0 15V5ZM13.707 7.707C14.098 7.317 14.098 6.683 13.707 6.293C13.317 5.902 12.683 5.902 12.293 6.293L6.293 12.293C5.902 12.683 5.902 13.317 6.293 13.707C6.683 14.098 7.317 14.098 7.707 13.707L13.707 7.707ZM14 12.5C14 13.328 13.328 14 12.5 14C11.672 14 11 13.328 11 12.5C11 11.672 11.672 11 12.5 11C13.328 11 14 11.672 14 12.5ZM7.5 9C8.328 9 9 8.328 9 7.5C9 6.672 8.328 6 7.5 6C6.672 6 6 6.672 6 7.5C6 8.328 6.672 9 7.5 9Z" fill="currentColor"/></svg>
            <span data-i18n="trade.toolPrices">Уточнить цены</span>
          </a>
          <a class="tr-tool" href="/trading/new">
            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M5 2C3.343 2 2 3.343 2 5V15C2 16.657 3.343 18 5 18H15C16.657 18 18 16.657 18 15V9C18 8.448 18.448 8 19 8C19.552 8 20 8.448 20 9V15C20 17.761 17.761 20 15 20H5C2.239 20 0 17.761 0 15V5C0 2.239 2.239 0 5 0H11C11.552 0 12 0.448 12 1C12 1.552 11.552 2 11 2H5Z" fill="currentColor"/><path d="M15.216 0.821C16.311 -0.274 18.085 -0.274 19.179 0.821C20.274 1.915 20.274 3.689 19.179 4.784L18.396 5.568C18.006 5.958 17.372 5.958 16.982 5.568L14.432 3.018C14.042 2.628 14.042 1.995 14.432 1.604L15.216 0.821ZM13.018 4.432C12.628 4.042 11.995 4.042 11.604 4.432L7.143 8.894C7.015 9.022 6.924 9.183 6.88 9.358L6.03 12.757C5.945 13.098 6.045 13.459 6.293 13.707C6.541 13.955 6.902 14.055 7.243 13.97L10.642 13.12C10.818 13.076 10.978 12.985 11.106 12.857L15.568 8.396C15.958 8.006 15.958 7.372 15.568 6.982L13.018 4.432Z" fill="currentColor"/></svg>
            <span data-i18n="trade.toolCreate">Создать</span>
          </a>
          <a class="tr-tool" href="/support">
            <svg viewBox="0 0 26 26" fill="none" aria-hidden="true"><path transform="translate(13 13) rotate(45) translate(-9.25 -9)" d="M7.417 1.082C8.216 -0.361 10.284 -0.361 11.083 1.082L18.141 13.829C19.317 15.954 17.426 18.472 15.066 17.924L11.794 17.163C10.889 16.953 10.248 16.144 10.248 15.211L10.248 9.851C10.248 9.298 9.801 8.849 9.25 8.849C8.699 8.849 8.252 9.298 8.252 9.851L8.252 15.211C8.252 16.143 7.611 16.952 6.706 17.163L3.434 17.924C1.074 18.472 -0.817 15.954 0.359 13.829L7.417 1.082Z" fill="currentColor"/></svg>
            <span data-i18n="trade.toolSupport">Поддержка</span>
          </a>
        </nav>

        <form class="tr-search" id="trSearch" role="search">
          <label class="tr-sr-only" for="trQuery" data-i18n="trade.searchLabel">Поиск по предметам и никам</label>
          <input class="tr-search-input" id="trQuery" type="search" autocomplete="off" spellcheck="false"
                 maxlength="40" data-i18n-placeholder="trade.searchPlaceholder" placeholder="Предмет или ник…" />
          <button class="tr-search-btn" type="submit" data-i18n-label="trade.searchGo" aria-label="Искать">
            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="8.5" cy="8.5" r="6.5" stroke="currentColor" stroke-width="2.2"/><path d="M18 18L13.5 13.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
          </button>
        </form>

        <div class="tr-board" id="trBoard">
          <p class="tr-state" id="trState" role="status" aria-live="polite"></p>

          <h2 class="tr-sub" id="trMineTitle" data-i18n="trade.mineTitle" hidden>Ваши объявления</h2>
          <ol class="tr-feed" id="trMine" aria-labelledby="trMineTitle" hidden></ol>

          <h2 class="tr-sub" id="trAllTitle" data-i18n="trade.allTitle" hidden>Все объявления</h2>
          <ol class="tr-feed" id="trFeed" data-i18n-label="trade.feedLabel" aria-label="Объявления"></ol>

          <button class="tr-more" id="trMore" type="button" data-i18n="trade.more" hidden>Показать ещё</button>
        </div>
      </section>

      <aside class="tr-me" id="trMe" data-i18n-label="trade.meLabel" aria-label="Ваш профиль" hidden>
        <div class="tr-me-card">
          <dl class="tr-me-info">
            <div class="tr-me-row"><dt data-i18n="trade.meNick">Ник</dt><dd id="trMeNick"></dd></div>
            <div class="tr-me-row"><dt data-i18n="trade.meId">ID</dt><dd id="trMeHandle"></dd></div>
          </dl>
          <a class="tr-me-btn" id="trMeBtn" href="/profile" data-i18n="trade.meProfile">Профиль</a>
          <span class="tr-me-ava" id="trMeAva" aria-hidden="true"></span>
        </div>
      </aside>
    </div>
  </main>

  <footer class="mk-foot">
    <img class="mk-foot-mark" src="assets/design/logo-mk-square.png" alt="MAKNEMY" />
    <ul class="mk-foot-roles">
      <li><span data-i18n="site.footAuthor">автор</span><span class="mk-foot-nick">MKSVTN</span></li>
      <li><span data-i18n="site.footDesigner">дизайнер</span><span class="mk-foot-nick">DANIKTOR</span></li>
      <li><span data-i18n="site.footAnalyst">аналитик</span><span class="mk-foot-nick">GLH</span></li>
      <li><span data-i18n="site.footAnalystAide">помощник аналитика</span><span class="mk-foot-nick" data-i18n="site.footHiring">активно ищем</span></li>
      <li><span data-i18n="site.footCoder">разработчик</span><span class="mk-foot-nick">The Fool</span></li>
    </ul>
    <p class="mk-foot-tagline" data-i18n="site.footTagline">макнеми тирлист - гарантия успешных трейдов</p>

    <p class="mk-foot-legal">
      <a href="/privacy" data-i18n="site.footPrivacy">Политика конфиденциальности</a>
      <a href="/terms" data-i18n="site.footTerms">Условия использования</a>
    </p>
  </footer>
  <div class="ptn-dock" id="promoDock" hidden
       data-i18n-label="promo.region" aria-label="Рекламные баннеры"></div>

  <div class="ptn-pop" id="promoPop" hidden role="dialog" aria-modal="true"
       data-i18n-label="promo.popLabel" aria-label="Рекламное сообщение"
       aria-labelledby="promoPopTitle">
    <div class="ptn-pop-card">
      <button class="ptn-pop-close" id="promoPopClose" type="button"
              data-i18n-label="promo.close" data-i18n-title="promo.close"
              aria-label="Закрыть рекламу" title="Закрыть рекламу">✕</button>
      <span class="ptn-chip" data-i18n="ad.chip">РЕКЛАМА</span>

      <div class="ptn-pop-media"><img class="ptn-pop-img" id="promoPopImg" alt="" /></div>

      <div class="ptn-pop-title" id="promoPopTitle"></div>
      <a class="btn primary ptn-pop-cta" id="promoPopCta" href="#" target="_blank" rel="noopener nofollow"></a>

      <span class="ptn-erid" id="promoPopErid" hidden></span>
    </div>
  </div>

  <script src="js/i18n.js?v=51" fetchpriority="high"></script>
  <script src="js/promo.js?v=12" fetchpriority="high"></script>

  <script src="js/promo-dock.js?v=5" fetchpriority="high"></script>

  <script src="js/promo-popup.js?v=3" fetchpriority="high"></script>
  <script src="js/calc.js?v=9" fetchpriority="high"></script>
  <script src="js/trading-page.js?v=1" fetchpriority="high"></script>
</body>
</html>
