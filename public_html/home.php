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
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="color-scheme" content="dark" />

<base href="/" />

<title>Maknemy — тирлист, цены и новости Blox Fruits | Макнеми</title>
<meta name="description" content="Maknemy — актуальный тир-лист Blox Fruits от создателя Maknemy: трейд-ценности фруктов, свежие новости меты и постоянные розыгрыши. Работаем с 2024 года." />
<link rel="canonical" href="https://maknemy.com/" />
<meta name="robots" content="index, follow, max-image-preview:large" />

<meta property="og:type" content="website" />
<meta property="og:site_name" content="Maknemy Tier List" />
<meta property="og:locale" content="ru_RU" />
<meta property="og:url" content="https://maknemy.com/" />
<meta property="og:title" content="Maknemy — тирлист, цены и новости Blox Fruits" />
<meta property="og:description" content="Актуальный тир-лист Blox Fruits от создателя Maknemy. Следите за изменениями меты, ловите розыгрыши и всегда будьте в центре игровых новостей." />
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
  "url": "https://maknemy.com/",
  "inLanguage": "ru",
  "description": "Тирлист трейд-ценностей Blox Fruits от Maknemy: фрукты, перманенты, геймпассы, скины и мутации.",
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

<link rel="stylesheet" href="css/base.css?v=10" />

<link rel="stylesheet" href="css/topbar.css?v=11" />

<script src="js/topbar.js?v=5" defer></script>

<link rel="stylesheet" href="css/design-page.css?v=32" />
<link rel="stylesheet" href="css/home.css?v=15" />

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
          <a class="mk-pill" href="/" aria-current="page">
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

          <button class="mk-pill" type="button" data-soon data-i18n-title="topbar.soon" title="В активной разработке">
            <svg viewBox="0 0 18 19" fill="none" aria-hidden="true"><path d="M6.17037 0.943433L4.48309 4.31799M11.8297 0.943433L13.517 4.31799M11.8297 9.4324L8.29262 13.2053L6.17037 11.4903M5.6697 17.9214H12.3304C14.2079 17.9214 15.7998 16.5408 16.0653 14.6821L17.0276 7.94613C17.2711 6.24146 15.9484 4.71631 14.2264 4.71631H3.77368C2.0517 4.71631 0.728943 6.24145 0.972468 7.94613L1.93474 14.6821C2.20027 16.5408 3.79212 17.9214 5.6697 17.9214Z" stroke="currentColor" stroke-width="1.88644" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="mk-pill-text" data-i18n="nav.trading">Трейдинг</span>
          </button>
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

      <button class="mk-chat" type="button" aria-label="Чат" data-soon data-i18n-title="topbar.soon" title="В активной разработке">
        <svg viewBox="0 0 25 25" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M12.0833 0C5.40989 0 0 5.40989 0 12.0833C0 14.2768 0.585445 16.3362 1.60861 18.1109C1.817 18.4723 1.85274 18.9124 1.67689 19.2907L0.645317 21.5102C0.0119158 22.7086 0.878898 24.1667 2.24942 24.1667H12.0833C18.7568 24.1667 24.1667 18.7568 24.1667 12.0833C24.1667 5.40989 18.7568 0 12.0833 0ZM8.45833 8.45833C7.79099 8.45833 7.25 8.99932 7.25 9.66667C7.25 10.334 7.79099 10.875 8.45833 10.875H10.875C11.5423 10.875 12.0833 10.334 12.0833 9.66667C12.0833 8.99932 11.5423 8.45833 10.875 8.45833H8.45833ZM8.45833 13.2917C7.79099 13.2917 7.25 13.8327 7.25 14.5C7.25 15.1673 7.79099 15.7083 8.45833 15.7083H15.7083C16.3757 15.7083 16.9167 15.1673 16.9167 14.5C16.9167 13.8327 16.3757 13.2917 15.7083 13.2917H8.45833Z" fill="currentColor"/></svg>
      </button>

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

<main class="hm">

  <section class="hm-lead">
    <div class="hm-panel-main"></div>
    <div class="hm-panel-text"></div>

    <h1 class="hm-title">
      <img class="hm-word" src="assets/design/wordmark.svg" alt="MAKNEMY" />
    </h1>

    <div class="hm-ghost hm-ghost-c hm-deco" aria-hidden="true"></div>

    <p class="hm-since hm-ak" data-i18n="home.since">Работаем с 2024 года</p>

    <p class="hm-desc" data-i18n="home.desc">Актуальный тир-лист Blox Fruits от создателя Maknemy. Следите за изменениями меты, ловите самые щедрые розыгрыши и всегда будьте в центре игровых новостей.</p>

    <div class="hm-ticker">
      <div class="hm-ticker-track">
        <ul class="hm-ticker-list">
          <li data-i18n="home.tickerPrices">самые точные цены</li>
          <li data-i18n="home.tickerGiveaways">постоянные розыгрыши</li>
          <li data-i18n="home.tickerCalc">калькулятор цен</li>
          <li data-i18n="home.tickerNews">свежие новости</li>
        </ul>
        <ul class="hm-ticker-list" aria-hidden="true">
          <li data-i18n="home.tickerPrices">самые точные цены</li>
          <li data-i18n="home.tickerGiveaways">постоянные розыгрыши</li>
          <li data-i18n="home.tickerCalc">калькулятор цен</li>
          <li data-i18n="home.tickerNews">свежие новости</li>
        </ul>
      </div>
    </div>

    <div class="hm-lead-actions">

      <a class="hm-btn hm-btn-accent" href="/tierlist"><span class="hm-btn-label"><span class="hm-btn-word hm-btn-word-rest" data-i18n="home.btnFruits">фрукты</span></span></a>
      <a class="hm-btn hm-btn-ghost" href="https://t.me/theMaknemy" target="_blank" rel="noopener"><svg class="hm-btn-dash" viewBox="0 0 273 72" preserveAspectRatio="none" aria-hidden="true"><rect x="1.1" y="1.1" width="270.8" height="69.8" fill="none" vector-effect="non-scaling-stroke"/></svg><span class="hm-btn-label"><span class="hm-btn-word hm-btn-word-rest" data-i18n="home.btnAbout">о нас</span><span class="hm-btn-word hm-btn-word-hover" data-i18n="home.btnAbout">о нас</span></span></a>
    </div>

    <div class="hm-cards hm-anim">
      <ul class="hm-cards-row">
        <li>
          <div class="hm-card" aria-disabled="true" data-soon data-i18n-title="topbar.soon" title="В активной разработке">
            <div class="hm-card-body"></div>
            <img class="hm-card-art" src="assets/design/home/card-fruits.webp" alt="" aria-hidden="true" />
            <h2 class="hm-card-name hm-ak" data-i18n="home.cardFruits">Фрукты</h2>
            <div class="hm-card-rule"></div>
            <p class="hm-card-note" data-i18n="home.cardFruitsNote">а какой фрукт предложат тебе?</p>
            <span class="hm-card-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </div>
        </li>
        <li>
          <a class="hm-card" href="/tierlist">
            <div class="hm-card-body"></div>
            <img class="hm-card-art" src="assets/design/home/card-tier.webp" alt="" aria-hidden="true" />
            <h2 class="hm-card-name hm-ak" data-i18n="home.cardTier">Тир</h2>
            <div class="hm-card-rule"></div>
            <p class="hm-card-note" data-i18n="home.cardTierNote">ваш гид в мире трейдов уже готов!</p>
            <span class="hm-card-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </a>
        </li>
        <li>
          <a class="hm-card" href="/calculator">
            <div class="hm-card-body"></div>
            <img class="hm-card-art" src="assets/design/home/card-prices.webp" alt="" aria-hidden="true" />
            <h2 class="hm-card-name hm-ak" data-i18n="home.cardPrices">Цены</h2>
            <div class="hm-card-rule"></div>
            <p class="hm-card-note" data-i18n="home.cardPricesNote">сравнить цены в реальном времени уже не проблема!</p>
            <span class="hm-card-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </a>
        </li>
        <li>
          <a class="hm-card" href="https://t.me/theMaknemy" target="_blank" rel="noopener">
            <div class="hm-card-body"></div>
            <img class="hm-card-art" src="assets/design/home/card-giveaways.webp" alt="" aria-hidden="true" />
            <h2 class="hm-card-name hm-card-name--tight hm-ak" data-i18n="home.cardGiveaways">Розыгрыши</h2>
            <div class="hm-card-rule"></div>
            <p class="hm-card-note" data-i18n="home.cardGiveawaysNote">любимые призы только на нашем канале!</p>
            <span class="hm-card-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </a>
        </li>
        <li>
          <a class="hm-card" href="/news">
            <div class="hm-card-body"></div>
            <img class="hm-card-art" src="assets/design/home/card-news.webp" alt="" aria-hidden="true" />
            <h2 class="hm-card-name hm-card-name--tight hm-ak" data-i18n="home.cardNews">Новости</h2>
            <div class="hm-card-rule"></div>
            <p class="hm-card-note" data-i18n="home.cardNewsNote">узнай самые свежие новинки в твоей любимой игре!</p>
            <span class="hm-card-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </a>
        </li>
      </ul>
    </div>

    <div class="hm-ghost hm-ghost-a hm-deco hm-anim" aria-hidden="true"></div>
    <div class="hm-ghost hm-ghost-b hm-deco hm-anim" aria-hidden="true"></div>
    <div class="hm-phone hm-deco hm-anim" aria-hidden="true"></div>
    <div class="hm-fig hm-fig-square-lg hm-deco hm-anim" aria-hidden="true"></div>
    <img class="hm-sakura hm-sakura-l hm-deco hm-anim" src="assets/design/home/sakura.webp" alt="" aria-hidden="true" />
    <img class="hm-sakura hm-sakura-r hm-deco hm-anim" src="assets/design/home/sakura.webp" alt="" aria-hidden="true" />
    <div class="hm-fig hm-fig-circle hm-deco hm-anim" aria-hidden="true"></div>
    <div class="hm-fig hm-fig-square-sm hm-deco hm-anim" aria-hidden="true"></div>
    <div class="hm-fig hm-fig-tri hm-deco hm-anim" aria-hidden="true"></div>
  </section>

  <section class="hm-faq">
    <div class="hm-faq-inner">
      <div class="hm-faq-head">
        <h2 class="hm-ak" data-i18n="home.faqTitle">Немного о важном</h2>
        <p data-i18n="home.faqSub">ваша гарантия успешных трейдов - ваша любознательность!</p>
      </div>

      <ul class="hm-faq-list">
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false" data-i18n="home.faqQ1">Кто такой Maknemy и как появился этот проект?</button>
          <div class="hm-faq-a">
            <p data-i18n="home.faqA1p1">Maknemy — мой медийный проект, посвящённый Roblox и Blox Fruits. Я создаю новости, обзоры, разборы обновлений и контент о трейдинге.</p>
            <p data-i18n="home.faqA1p2">В процессе я заметил, что игрокам часто не хватает одного понятного места, где можно быстро узнать примерную ценность предметов и разобраться в изменениях рынка. Так появился Maknemy Tierlist — проект, который объединяет мою аналитику, сайт и сообщества вокруг трейдинга.</p>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false" data-i18n="home.faqQ2">Что можно найти в Maknemy Tierlist?</button>
          <div class="hm-faq-a">
            <p data-i18n="home.faqA2p1">Maknemy Tierlist помогает ориентироваться в экономике Blox Fruits. На сайте собраны оценки фруктов, перманентных фруктов, пассов, оружия, аксессуаров и конфигураций — бывших скинов и мутаций.</p>
            <p data-i18n="home.faqA2p2">Проект также включает обновления цен, новости рынка и материалы, которые помогают игрокам лучше понимать происходящее в трейдах. Главная площадка проекта — сайт maknemy.com.</p>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false" data-i18n="home.faqQ3">Как формируются цены на фрукты, пассы и конфигурации?</button>
          <div class="hm-faq-a">
            <p data-i18n="home.faqA3p1">Цены не должны основываться только на одной цифре или случайном калькуляторе. При оценке учитываются спрос, редкость предмета, его доступность, популярность, изменения после обновлений и то, насколько игроки действительно готовы обменивать его на другие ценности.</p>
            <p data-i18n="home.faqA3p2">Также важны сообщения и наблюдения самого сообщества. Если предмет стал чаще появляться в трейдах, потерял спрос или, наоборот, начал резко дорожать, это отражается на его актуальной оценке.</p>
            <p data-i18n="home.faqA3p3">При этом цена в тир-листе — это ориентир по рынку, а не официальная стоимость и не гарантия выгоды.</p>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false" data-i18n="home.faqQ4">Почему цены меняются и как следить за актуальными значениями?</button>
          <div class="hm-faq-a">
            <p data-i18n="home.faqA4p1">Экономика Blox Fruits постоянно меняется. После выхода обновлений, реворков, новых фруктов, скинов или механик интерес игроков может резко измениться.</p>
            <p data-i18n="home.faqA4p2">Предмет, который вчера считался очень востребованным, сегодня может потерять спрос. То же самое работает и наоборот: редкий или популярный предмет может начать цениться значительно выше.</p>
            <p data-i18n="home.faqA4p3">Поэтому не стоит ориентироваться на старые скриншоты и давно сохранённые списки. Лучше проверять текущую версию тир-листа, дату обновления цен и последние новости проекта.</p>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false" data-i18n="home.faqQ5">Как использовать тир-лист, чтобы не переплачивать?</button>
          <div class="hm-faq-a">
            <p data-i18n="home.faqA5p1">Сначала нужно сравнить ценность обеих сторон сделки, но нельзя смотреть только на итоговые цифры. Важно учитывать спрос на каждый предмет и понимать, насколько легко его потом обменять.</p>
            <p data-i18n="home.faqA5p2">Иногда предмет может иметь высокую оценку, но почти никому не быть нужным. А другой предмет может стоить немного меньше, но пользоваться большим спросом и быстрее продаваться или обмениваться.</p>
            <p data-i18n="home.faqA5p3">Maknemy Tierlist стоит использовать как отправную точку для анализа сделки. Окончательное решение всегда должно учитывать текущие предложения, спрос и твою собственную цель.</p>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false" data-i18n="home.faqQ6">Как не попасться на скам при трейде?</button>
          <div class="hm-faq-a">
            <p data-i18n="home.faqA6p1">Никому нельзя передавать пароль, cookies, коды подтверждения или данные аккаунта ради «проверки предметов». Maknemy Tierlist никогда не требует отправлять такие данные.</p>
            <p data-i18n="home.faqA6p2">Перед подтверждением сделки нужно внимательно проверить имена игроков, предметы и их количество. Нельзя доверять одним только скриншотам, обещаниям, срочности или сообщениям от якобы администраторов.</p>
            <p data-i18n="home.faqA6p3">Также нужно осторожно относиться к подозрительным ссылкам, фальшивым сайтам и кросс-трейдам. Тир-лист помогает оценить сделку, но не может гарантировать честность другого игрока.</p>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false" data-i18n="home.faqQ7">Что дальше ждёт Maknemy Tierlist?</button>
          <div class="hm-faq-a">
            <p data-i18n="home.faqA7p1">Maknemy Tierlist не должен оставаться просто таблицей с ценами. Сейчас проект развивается дальше: сайт готовится выйти из бета-версии, обновляется дизайн и структура новостей.</p>
            <p data-i18n="home.faqA7p2">В будущем планируется добавить публичную площадку для поиска трейдов и объективный калькулятор сделок, который будет помогать сравнивать предложения без искусственного завышения или занижения цен.</p>
            <p data-i18n="home.faqA7p3">При этом даже самый удобный калькулятор не сможет полностью заменить понимание спроса и рынка. Главная цель проекта — дать игрокам полезную основу для решений и постепенно превратить Maknemy Tierlist в полноценную торговую платформу для Roblox.</p>
          </div>
        </li>
      </ul>
    </div>
  </section>
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

<script src="js/i18n.js?v=36"></script>
<script src="js/home.js?v=5"></script>
</body>
</html>
