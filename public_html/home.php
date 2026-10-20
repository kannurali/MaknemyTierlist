<?php
require_once __DIR__ . '/api/_bootstrap.php';
require_once __DIR__ . '/api/lib/metrika.php';

// Превью корня — свой первый экран: логотип, «Работаем с 2024 года»,
// описание проекта и кнопки. У /tierlist своя карточка (og_brand_card() в
// api/lib/og.php) — разделы намеренно выглядят по-разному, ссылка на проект
// и ссылка на тирлист в чате должны различаться с первого взгляда.
//
// Снято с САМОЙ страницы при ширине окна 480 px, а не с десктопной вёрстки.
// Причина не в размере: на десктопе в первый экран входит макет телефона с
// телеграм-постом и рекламным баннером внутри, а рекламы других площадок на
// превью быть не должно. На узкой ширине вёрстка его не выводит вовсе, и
// кадр получается без неё сам по себе — не вырезкой и не замазыванием.
//
// Картинка статична намеренно: раньше здесь собиралось превью по живой
// строке тирлиста, и ссылка меняла вид от каждой правки цен. Побочный
// эффект, ради которого это стоило сделать так: главная больше не ходит в
// базу вообще. Вместе с запросом ушёл и try/catch вокруг него — падать при
// отрисовке стало нечему, а прежняя причина его существования (битое превью
// не должно ронять самую шаримую страницу) закрыта тем, что превью теперь
// просто файл.
//
// Обновляется вручную — пересъёмкой первого экрана — и обязан менять ?v= при
// каждой: без нового адреса Telegram и Discord продолжат показывать прежнюю
// картинку из кэша, даже если файл на диске уже другой.
//
// og:title, og:description и canonical ниже остаются про сайт целиком —
// так и было задумано, картинка тут единственное, что менялось.
$ogImage = [
    'image'       => 'https://maknemy.com/assets/og-home.jpg?v=1',
    'imageWidth'  => 1200,
    'imageHeight' => 630,
    'imageType'   => 'image/jpeg',
];

// Cache-Control такой же, как у index.php и news.php: файл несёт номера
// версий ?v= для css/js, и закешированная копия намертво прибила бы
// посетителя к старому коду.
header('Cache-Control: no-cache, must-revalidate');
// И минута в кеше LiteSpeed: см. page_lscache() в api/_bootstrap.php.
page_lscache();
?>
<!DOCTYPE html>
<html lang="<?= i18n_lang() ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="color-scheme" content="dark" />

<base href="/" />

<title><?= te('meta.home.title') ?></title>
<meta name="description" content="<?= ta('meta.home.desc') ?>" />
<link rel="canonical" href="<?= i18n_url('/') ?>" />
<?= i18n_alternates('/') ?>
<meta name="robots" content="index, follow, max-image-preview:large" />

<meta property="og:type" content="website" />
<meta property="og:site_name" content="Maknemy Tier List" />
<meta property="og:locale" content="<?= i18n_og_locale() ?>" />
<meta property="og:url" content="<?= i18n_url('/') ?>" />
<meta property="og:title" content="<?= ta('meta.home.ogTitle') ?>" />
<meta property="og:description" content="<?= ta('meta.home.ogDesc') ?>" />
<meta property="og:image" content="<?= htmlspecialchars($ogImage['image'], ENT_QUOTES, 'UTF-8') ?>" />
<meta property="og:image:width" content="<?= (int)$ogImage['imageWidth'] ?>" />
<meta property="og:image:height" content="<?= (int)$ogImage['imageHeight'] ?>" />
<meta property="og:image:type" content="<?= htmlspecialchars($ogImage['imageType'], ENT_QUOTES, 'UTF-8') ?>" />
<meta name="twitter:card" content="summary_large_image" />

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebSite",
  "name": "Maknemy Tier List",
  "alternateName": ["Макнеми тирлист", "Maknemy Tierlist", "Maknemy tier list Blox Fruits"],
  "url": "<?= i18n_url('/') ?>",
  "inLanguage": "<?= i18n_lang() ?>",
  "description": "<?= tj('meta.home.ogDesc') ?>",
  "author": {
    "@type": "Person",
    "name": "Maknemy",
    "url": "https://t.me/mksvtnc"
  }
}
</script>

<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="icon" type="image/png" href="/assets/favicon.png?v=2" sizes="256x256" />
<link rel="apple-touch-icon" href="/apple-touch-icon.png" />

<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/base.css?v=16" /><?php else: ?><link rel="stylesheet" href="css/base.css?v=16" /><?php endif; ?>

<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/topbar.css?v=31" /><?php else: ?><link rel="stylesheet" href="css/topbar.css?v=31" /><?php endif; ?>

<script src="js/auth.js?v=2" fetchpriority="high"></script>
<script src="js/protect.js?v=3" fetchpriority="high"></script>
<script src="js/topbar.js?v=32" defer fetchpriority="high"></script>

<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/design-page.css?v=43" /><?php else: ?><link rel="stylesheet" href="css/design-page.css?v=43" /><?php endif; ?>
<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/home.css?v=32" /><?php else: ?><link rel="stylesheet" href="css/home.css?v=32" /><?php endif; ?>
<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/halloween.css?v=4" /><?php endif; ?>

<?php echo metrika_counter_html(); ?>
</head>
<body>

  <header class="mk-top">
    <a class="mk-top-brand" href="<?= u('/') ?>">
      <img class="mk-top-mark" src="assets/design/logo-mk-square.png" alt="" aria-hidden="true" />
      <img class="mk-top-word" src="assets/design/wordmark.svg" alt="MAKNEMY" />
    </a>


    <nav class="mk-top-bar" id="mkTopBar" aria-label="<?= ta('topbar.sections') ?>">
      <ul class="mk-nav">
        <li>
          <a class="mk-pill" href="<?= u('/') ?>" aria-current="page">
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
        <li class="mk-nav-lang"><?= i18n_lang_menu() ?></li>
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

<main class="hm">

  <section class="hm-lead">
    <div class="hm-panel-main"></div>
    <div class="hm-panel-text"></div>

    <h1 class="hm-title">
      <img class="hm-word" src="assets/design/wordmark.svg" alt="MAKNEMY" />
    </h1>

    <div class="hm-ghost hm-ghost-c hm-deco" aria-hidden="true"></div>

    <p class="hm-since hm-ak" data-i18n="home.since"><?= te('home.since') ?></p>

    <p class="hm-desc" data-i18n="home.desc"><?= te('home.desc') ?></p>

    <div class="hm-ticker">
      <div class="hm-ticker-track">
        <ul class="hm-ticker-list">
          <li data-i18n="home.tickerPrices"><?= te('home.tickerPrices') ?></li>
          <li data-i18n="home.tickerGiveaways"><?= te('home.tickerGiveaways') ?></li>
          <li data-i18n="home.tickerCalc"><?= te('home.tickerCalc') ?></li>
          <li data-i18n="home.tickerNews"><?= te('home.tickerNews') ?></li>
        </ul>
        <ul class="hm-ticker-list" aria-hidden="true">
          <li data-i18n="home.tickerPrices"><?= te('home.tickerPrices') ?></li>
          <li data-i18n="home.tickerGiveaways"><?= te('home.tickerGiveaways') ?></li>
          <li data-i18n="home.tickerCalc"><?= te('home.tickerCalc') ?></li>
          <li data-i18n="home.tickerNews"><?= te('home.tickerNews') ?></li>
        </ul>
        <ul class="hm-ticker-list" aria-hidden="true">
          <li data-i18n="home.tickerPrices"><?= te('home.tickerPrices') ?></li>
          <li data-i18n="home.tickerGiveaways"><?= te('home.tickerGiveaways') ?></li>
          <li data-i18n="home.tickerCalc"><?= te('home.tickerCalc') ?></li>
          <li data-i18n="home.tickerNews"><?= te('home.tickerNews') ?></li>
        </ul>
      </div>
    </div>

    <div class="hm-lead-actions">

      <a class="hm-btn hm-btn-accent" href="<?= u('/tierlist') ?>"><span class="hm-btn-label"><span class="hm-btn-word hm-btn-word-rest" data-i18n="home.btnFruits"><?= te('home.btnFruits') ?></span></span></a>
      <a class="hm-btn hm-btn-ghost" href="<?= u('/profile?id=2841062255') ?>"><svg class="hm-btn-dash" viewBox="0 0 273 72" preserveAspectRatio="none" aria-hidden="true"><rect x="1.1" y="1.1" width="270.8" height="69.8" fill="none" vector-effect="non-scaling-stroke"/></svg><span class="hm-btn-label"><span class="hm-btn-word hm-btn-word-rest" data-i18n="home.btnAbout"><?= te('home.btnAbout') ?></span></span></a>
    </div>

    <div class="hm-cards hm-anim">
      <ul class="hm-cards-row">
        <li>
          <a class="hm-card" href="<?= u('/trading') ?>">
            <div class="hm-card-body"></div>
            <img class="hm-card-art" src="<?= hw_img('assets/design/home/card-fruits.webp') ?>" alt="" aria-hidden="true" />
            <h2 class="hm-card-name hm-ak" data-i18n="home.cardFruits"><?= te('home.cardFruits') ?></h2>
            <div class="hm-card-rule"></div>
            <p class="hm-card-note" data-i18n="home.cardFruitsNote"><?= te('home.cardFruitsNote') ?></p>
            <span class="hm-card-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </a>
        </li>
        <li>
          <a class="hm-card" href="<?= u('/tierlist') ?>">
            <div class="hm-card-body"></div>
            <img class="hm-card-art" src="<?= hw_img('assets/design/home/card-tier.webp') ?>" alt="" aria-hidden="true" />
            <h2 class="hm-card-name hm-ak" data-i18n="home.cardTier"><?= te('home.cardTier') ?></h2>
            <div class="hm-card-rule"></div>
            <p class="hm-card-note" data-i18n="home.cardTierNote"><?= te('home.cardTierNote') ?></p>
            <span class="hm-card-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </a>
        </li>
        <li>
          <a class="hm-card" href="<?= u('/calculator') ?>">
            <div class="hm-card-body"></div>
            <img class="hm-card-art" src="<?= hw_img('assets/design/home/card-prices.webp') ?>" alt="" aria-hidden="true" />
            <h2 class="hm-card-name hm-ak" data-i18n="home.cardPrices"><?= te('home.cardPrices') ?></h2>
            <div class="hm-card-rule"></div>
            <p class="hm-card-note" data-i18n="home.cardPricesNote"><?= te('home.cardPricesNote') ?></p>
            <span class="hm-card-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </a>
        </li>
        <li>
          <a class="hm-card" href="<?= u('/giveaways') ?>">
            <div class="hm-card-body"></div>
            <img class="hm-card-art" src="<?= hw_img('assets/design/home/card-giveaways.webp') ?>" alt="" aria-hidden="true" />
            <h2 class="hm-card-name hm-card-name--tight hm-ak" data-i18n="home.cardGiveaways"><?= te('home.cardGiveaways') ?></h2>
            <div class="hm-card-rule"></div>
            <p class="hm-card-note" data-i18n="home.cardGiveawaysNote"><?= te('home.cardGiveawaysNote') ?></p>
            <span class="hm-card-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </a>
        </li>
        <li>
          <a class="hm-card" href="<?= u('/news') ?>">
            <div class="hm-card-body"></div>
            <img class="hm-card-art" src="<?= hw_img('assets/design/home/card-news.webp') ?>" alt="" aria-hidden="true" />
            <h2 class="hm-card-name hm-card-name--tight hm-ak" data-i18n="home.cardNews"><?= te('home.cardNews') ?></h2>
            <div class="hm-card-rule"></div>
            <p class="hm-card-note" data-i18n="home.cardNewsNote"><?= te('home.cardNewsNote') ?></p>
            <span class="hm-card-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </a>
        </li>
      </ul>
    </div>

    <div class="hm-ghost hm-ghost-a hm-deco hm-anim" aria-hidden="true"></div>
    <div class="hm-ghost hm-ghost-b hm-deco hm-anim" aria-hidden="true"></div>
    <div class="hm-phone hm-deco hm-anim" aria-hidden="true"></div>
    <svg class="hm-glass-defs" width="0" height="0" aria-hidden="true" focusable="false">
      <filter id="hmGlassCircle" x="0" y="0" width="1" height="1" primitiveUnits="objectBoundingBox" color-interpolation-filters="sRGB">
        <feImage href="assets/design/home/glass-circle.png" x="0" y="0" width="1" height="1" preserveAspectRatio="none" result="map" />
        <feDisplacementMap in="SourceGraphic" in2="map" scale="0.2014" xChannelSelector="R" yChannelSelector="G" result="lens" />
        <feGaussianBlur in="lens" stdDeviation="0.0092" />
      </filter>
      <filter id="hmGlassSquareLg" x="0" y="0" width="1" height="1" primitiveUnits="objectBoundingBox" color-interpolation-filters="sRGB">
        <feImage href="assets/design/home/glass-square-lg.png" x="0" y="0" width="1" height="1" preserveAspectRatio="none" result="map" />
        <feDisplacementMap in="SourceGraphic" in2="map" scale="0.2196" xChannelSelector="R" yChannelSelector="G" result="lens" />
        <feGaussianBlur in="lens" stdDeviation="0.0243" />
      </filter>
      <filter id="hmGlassSquareSm" x="0" y="0" width="1" height="1" primitiveUnits="objectBoundingBox" color-interpolation-filters="sRGB">
        <feImage href="assets/design/home/glass-square-sm.png" x="0" y="0" width="1" height="1" preserveAspectRatio="none" result="map" />
        <feDisplacementMap in="SourceGraphic" in2="map" scale="0.3514" xChannelSelector="R" yChannelSelector="G" />
      </filter>
      <filter id="hmGlassTri" x="0" y="0" width="1" height="1" primitiveUnits="objectBoundingBox" color-interpolation-filters="sRGB">
        <feImage href="assets/design/home/glass-tri.png" x="0" y="0" width="1" height="1" preserveAspectRatio="none" result="map" />
        <feDisplacementMap in="SourceGraphic" in2="map" scale="0.0986" xChannelSelector="R" yChannelSelector="G" result="lens" />
        <feGaussianBlur in="lens" stdDeviation="0.0037" />
      </filter>
    </svg>
    <div class="hm-fig hm-fig-square-lg hm-deco hm-anim" aria-hidden="true"></div>
    <img class="hm-sakura hm-sakura-l hm-deco hm-anim" src="<?= HALLOWEEN ? 'assets/halloween/branch-l.webp' : 'assets/design/home/sakura.webp' ?>" alt="" aria-hidden="true" />
    <img class="hm-sakura hm-sakura-r hm-deco hm-anim" src="<?= HALLOWEEN ? 'assets/halloween/branch-r.webp' : 'assets/design/home/sakura.webp' ?>" alt="" aria-hidden="true" />
    <div class="hm-fig hm-fig-circle hm-deco hm-anim" aria-hidden="true"></div>
    <div class="hm-fig hm-fig-square-sm hm-deco hm-anim" aria-hidden="true"></div>
    <div class="hm-fig hm-fig-tri hm-deco hm-anim" aria-hidden="true"></div>
    <?= halloween_decor('home-lead', 'hw-decor hw-decor--home hm-deco') ?>
  </section>

  <section class="hm-faq">
    <div class="hm-faq-inner">
      <div class="hm-faq-head">
        <h2 class="hm-ak" data-i18n="home.faqTitle"><?= te('home.faqTitle') ?></h2>
        <p data-i18n="home.faqSub"><?= te('home.faqSub') ?></p>
      </div>

      <ul class="hm-faq-list">
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false" data-i18n="home.faqQ1"><?= te('home.faqQ1') ?></button>
          <div class="hm-faq-a">
            <div class="hm-faq-a-in">
              <p data-i18n="home.faqA1p1"><?= te('home.faqA1p1') ?></p>
              <p data-i18n="home.faqA1p2"><?= te('home.faqA1p2') ?></p>
            </div>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false" data-i18n="home.faqQ2"><?= te('home.faqQ2') ?></button>
          <div class="hm-faq-a">
            <div class="hm-faq-a-in">
              <p data-i18n="home.faqA2p1"><?= te('home.faqA2p1') ?></p>
            </div>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false" data-i18n="home.faqQ3"><?= te('home.faqQ3') ?></button>
          <div class="hm-faq-a">
            <div class="hm-faq-a-in">
              <p data-i18n="home.faqA3p1"><?= te('home.faqA3p1') ?></p>
              <p data-i18n="home.faqA3p2"><?= te('home.faqA3p2') ?></p>
            </div>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false" data-i18n="home.faqQ4"><?= te('home.faqQ4') ?></button>
          <div class="hm-faq-a">
            <div class="hm-faq-a-in">
              <p data-i18n="home.faqA4p1"><?= te('home.faqA4p1') ?></p>
              <p data-i18n="home.faqA4p2"><?= te('home.faqA4p2') ?></p>
              <p data-i18n="home.faqA4p3"><?= te('home.faqA4p3') ?></p>
            </div>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false" data-i18n="home.faqQ5"><?= te('home.faqQ5') ?></button>
          <div class="hm-faq-a">
            <div class="hm-faq-a-in">
              <p data-i18n="home.faqA5p1"><?= te('home.faqA5p1') ?></p>
              <p data-i18n="home.faqA5p2"><?= te('home.faqA5p2') ?></p>
            </div>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false" data-i18n="home.faqQ6"><?= te('home.faqQ6') ?></button>
          <div class="hm-faq-a">
            <div class="hm-faq-a-in">
              <p data-i18n="home.faqA6p1"><?= te('home.faqA6p1') ?></p>
              <p data-i18n="home.faqA6p2"><?= te('home.faqA6p2') ?></p>
              <p data-i18n="home.faqA6p3"><?= te('home.faqA6p3') ?></p>
            </div>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false" data-i18n="home.faqQ7"><?= te('home.faqQ7') ?></button>
          <div class="hm-faq-a">
            <div class="hm-faq-a-in">
              <p data-i18n="home.faqA7p1"><?= te('home.faqA7p1') ?></p>
              <p data-i18n="home.faqA7p2"><?= te('home.faqA7p2') ?></p>
            </div>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false" data-i18n="home.faqQ8"><?= te('home.faqQ8') ?></button>
          <div class="hm-faq-a">
            <div class="hm-faq-a-in">
              <p data-i18n="home.faqA8p1"><?= te('home.faqA8p1') ?></p>
              <p data-i18n="home.faqA8p2"><?= te('home.faqA8p2') ?></p>
            </div>
          </div>
        </li>
      </ul>
    </div>
    <?= halloween_decor('home-faq', 'hw-decor hw-decor--home') ?>
  </section>
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
<script src="js/home.js?v=8" fetchpriority="high"></script>
</body>
</html>
