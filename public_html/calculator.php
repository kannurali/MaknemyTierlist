<?php
require_once __DIR__ . '/api/_bootstrap.php';

// Страница статичная (данные тирлиста запрашивает клиент через
// GET /api/tierlist.php, см. js/calculator-page.js) — ни превью, собираемого
// из базы, ни og:*-данных строить не из чего, поэтому здесь нет ничего похожего
// на tierlist_og_data()/news_og_data() из index.php/news.php. Cache-Control тот
// же, что у остальных страниц редизайна: файл несёт номера версий ?v= для
// css/js, и закешированная копия намертво прибила бы посетителя к старому коду.
header('Cache-Control: no-cache, must-revalidate');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="color-scheme" content="dark" />

<!-- Пути внутри страницы документ-относительные ("css/base.css", "js/…").
     Сама она отдаётся с "/calculator" (глубина 0 от корня — как /tierlist и
     /news), поэтому база документа и так корень. <base> здесь по тому же
     принципу, что и в news.php/home.php: если адрес когда-нибудь уедет на
     глубину (например, появится /calculator/<id> для сохранённых пресетов),
     пути не поедут в несуществующие /calculator/css/…, /calculator/js/… . -->
<base href="/" />

<title>Калькулятор трейдов Blox Fruits | Maknemy Tier List</title>
<meta name="description" content="Калькулятор трейдов Blox Fruits от Maknemy: соберите обе стороны сделки по ценам нашего тирлиста и узнайте, выгодна ли она." />
<link rel="canonical" href="https://maknemytierlist.site/calculator" />
<meta name="robots" content="index, follow, max-image-preview:large" />

<meta property="og:type" content="website" />
<meta property="og:site_name" content="Maknemy Tier List" />
<meta property="og:locale" content="ru_RU" />
<meta property="og:url" content="https://maknemytierlist.site/calculator" />
<meta property="og:title" content="Калькулятор трейдов Blox Fruits" />
<meta property="og:description" content="Соберите обе стороны сделки по ценам тирлиста Maknemy и узнайте, выгодна ли она." />
<meta property="og:image" content="https://maknemytierlist.site/assets/og-image.jpg?v=2" />
<meta property="og:image:width" content="1920" />
<meta property="og:image:height" content="1080" />
<meta property="og:image:type" content="image/jpeg" />
<meta name="twitter:card" content="summary_large_image" />

<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="icon" type="image/png" href="/assets/favicon.png?v=2" sizes="256x256" />
<link rel="apple-touch-icon" href="/apple-touch-icon.png" />

<link rel="stylesheet" href="css/base.css?v=4" />
<link rel="stylesheet" href="css/topbar.css?v=4" />
<!-- Фон страницы и подвал из редизайна — те же, что на главной и тирлисте. -->
<link rel="stylesheet" href="css/design-page.css?v=24" />
<link rel="stylesheet" href="css/calculator.css?v=1" />
</head>
<body>

  <!-- ================= Шапка ================= -->
  <header class="mk-top">
    <a class="mk-top-brand" href="/">
      <img class="mk-top-mark" src="assets/design/logo-mk-square.png" alt="" aria-hidden="true" />
      <img class="mk-top-word" src="assets/design/wordmark.svg" alt="MAKNEMY" />
    </a>

    <nav class="mk-top-bar" aria-label="Разделы сайта">
      <ul class="mk-nav">
        <li>
          <a class="mk-pill" href="/">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M18.05 16.0302V8.423C18.05 7.48807 17.644 6.60551 16.9498 6.03152L11.833 1.80094C10.4608 0.666372 8.53926 0.666371 7.16704 1.80094L2.05028 6.03152C1.35606 6.60551 0.950013 7.48807 0.950013 8.423V16.0302C0.950013 17.1457 1.80067 18.05 2.85001 18.05H4.75001C5.79936 18.05 6.65001 17.1994 6.65001 16.15V13.0006C6.65001 11.8851 7.50067 10.9808 8.55002 10.9808H10.45C11.4994 10.9808 12.35 11.8851 12.35 13.0006V16.15C12.35 17.1994 13.2007 18.05 14.25 18.05H16.15C17.1994 18.05 18.05 17.1457 18.05 16.0302Z" stroke="currentColor" stroke-width="1.81101"/></svg>
            <span class="mk-pill-text">Главная</span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="/tierlist">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M8.57627 3.7533C8.57627 3.22702 8.14799 2.79425 7.62582 2.85987C6.45486 3.00701 5.32947 3.42467 4.341 4.08515C3.08735 4.9228 2.11026 6.1134 1.53327 7.50637C0.95628 8.89935 0.805314 10.4321 1.09946 11.9109C1.39361 13.3897 2.11965 14.748 3.18579 15.8142C4.25193 16.8803 5.61027 17.6063 7.08904 17.9005C8.56781 18.1946 10.1006 18.0437 11.4936 17.4667C12.8866 16.8897 14.0771 15.9126 14.9148 14.659C15.5753 13.6705 15.9929 12.5451 16.1401 11.3741C16.2057 10.852 15.7729 10.4237 15.2466 10.4237H9.52918C9.0029 10.4237 8.57627 9.99705 8.57627 9.47077V3.7533Z" stroke="currentColor" stroke-width="1.82067"/><path d="M11.435 1.84748C11.435 1.3212 11.8638 0.887589 12.3847 0.962518C12.934 1.04153 13.4726 1.18898 13.9876 1.40232C14.7969 1.73754 15.5323 2.22887 16.1517 2.84828C16.7711 3.46768 17.2624 4.20302 17.5976 5.0123C17.811 5.52735 17.9584 6.06592 18.0374 6.61527C18.1124 7.13618 17.6787 7.56495 17.1525 7.56495L11.5303 7.56495C11.4777 7.56495 11.435 7.52228 11.435 7.46965V1.84748Z" stroke="currentColor" stroke-width="1.82067"/></svg>
            <span class="mk-pill-text">Тирлист</span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="#" aria-disabled="true" title="Раздел в разработке">
            <svg viewBox="0 0 18 19" fill="none" aria-hidden="true"><path d="M6.17037 0.943433L4.48309 4.31799M11.8297 0.943433L13.517 4.31799M11.8297 9.4324L8.29262 13.2053L6.17037 11.4903M5.6697 17.9214H12.3304C14.2079 17.9214 15.7998 16.5408 16.0653 14.6821L17.0276 7.94613C17.2711 6.24146 15.9484 4.71631 14.2264 4.71631H3.77368C2.0517 4.71631 0.728943 6.24145 0.972468 7.94613L1.93474 14.6821C2.20027 16.5408 3.79212 17.9214 5.6697 17.9214Z" stroke="currentColor" stroke-width="1.88644" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="mk-pill-text">Трейдинг</span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="/calculator" aria-current="page">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M5.70001 8.55001V13.3M13.3 10.45V13.3M9.5 5.70001V13.3M4.75001 18.05H14.25C16.3487 18.05 18.05 16.3487 18.05 14.25V4.75001C18.05 2.65134 16.3487 0.950022 14.25 0.950022H4.75001C2.65134 0.950022 0.950022 2.65134 0.950022 4.75001V14.25C0.950022 16.3487 2.65134 18.05 4.75001 18.05Z" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
            <span class="mk-pill-text">Калькулятор</span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="/news">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M18.05 9.50002C18.05 14.2221 14.222 18.05 9.49995 18.05M18.05 9.50002C18.05 4.77798 14.222 0.950013 9.49995 0.950013M18.05 9.50002C18.05 7.92601 14.222 6.65002 9.49995 6.65002C4.77792 6.65002 0.949949 7.92601 0.949949 9.50002M18.05 9.50002C18.05 11.074 14.222 12.35 9.49995 12.35C4.77792 12.35 0.949949 11.074 0.949949 9.50002M9.49995 18.05C4.77792 18.05 0.949949 14.2221 0.949949 9.50002M9.49995 18.05C11.074 18.05 12.35 14.2221 12.35 9.50002C12.35 4.77798 11.074 0.950013 9.49995 0.950013M9.49995 18.05C7.92594 18.05 6.64995 14.2221 6.64995 9.50002C6.64995 4.77798 7.92594 0.950013 9.49995 0.950013M0.949949 9.50002C0.949949 4.77798 4.77792 0.950013 9.49995 0.950013" stroke="currentColor" stroke-width="1.9"/></svg>
            <span class="mk-pill-text">Новости</span>
          </a>
        </li>
      </ul>

      <button class="mk-avatar" type="button" aria-label="Профиль" title="Профиль">
        <svg viewBox="0 0 34 34" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M17.0003 2.83325C13.0883 2.83325 9.91699 6.00457 9.91699 9.91659C9.91699 13.8286 13.0883 16.9999 17.0003 16.9999C20.9123 16.9999 24.0837 13.8286 24.0837 9.91659C24.0837 6.00457 20.9123 2.83325 17.0003 2.83325Z" fill="currentColor"/><path fill-rule="evenodd" clip-rule="evenodd" d="M12.7503 18.4167C10.3947 18.4167 8.12945 19.4913 6.80192 21.109C6.12816 21.9301 5.65451 22.946 5.61326 24.072C5.57114 25.2218 5.98621 26.3442 6.8422 27.3234C8.92833 29.7099 12.2591 31.1667 17.0003 31.1667C21.7415 31.1667 25.0723 29.7099 27.1584 27.3234C28.0144 26.3442 28.4294 25.2218 28.3873 24.072C28.3461 22.946 27.8724 21.9301 27.1987 21.109C25.8711 19.4913 23.6058 18.4167 21.2503 18.4167H12.7503Z" fill="currentColor"/></svg>
      </button>
    </nav>
  </header>

  <main class="tc-page">
    <div class="tc-wrap">

      <div class="tc-head">
        <div class="tc-head-text">
          <h1 class="tc-title" data-i18n="calc.title">Калькулятор трейдов</h1>
          <p class="tc-subtitle" data-i18n="calc.subtitle">Соберите обе стороны сделки — калькулятор покажет, выгодна ли она</p>
        </div>
        <!-- Тот же компонент, что в тулбаре тирлиста/ленты (base.css: .lang-switch/.chip) —
             своих стилей переключателю здесь заводить не нужно. -->
        <div class="lang-switch tc-lang" id="langSwitch" role="group"
             data-i18n-label="lang.switch" aria-label="Язык интерфейса">
          <button class="chip" type="button" data-lang="ru" data-i18n="lang.ru" aria-pressed="false">RU</button>
          <button class="chip" type="button" data-lang="en" data-i18n="lang.en" aria-pressed="false">EN</button>
        </div>
      </div>

      <p class="tc-state" id="tcState" role="status" aria-live="polite" hidden></p>

      <div class="tc-board">
        <section class="tc-side" data-side="left" aria-labelledby="tcGiveHeading">
          <div class="tc-side-head">
            <h2 class="tc-side-title" id="tcGiveHeading" data-i18n="calc.giveLabel">Вы отдаёте</h2>
            <button type="button" class="tc-clear-side" title="Очистить: Вы отдаёте" aria-label="Очистить: Вы отдаёте">✕</button>
          </div>

          <div class="tc-search">
            <label class="tc-sr-only" for="tcSearchLeft" data-i18n="calc.searchLabel">Поиск предмета</label>
            <input type="text" class="tc-search-input" id="tcSearchLeft"
                   data-i18n-placeholder="calc.searchPlaceholder" placeholder="Название предмета…"
                   autocomplete="off" spellcheck="false" />
            <ul class="tc-suggest" hidden></ul>
          </div>

          <ul class="tc-list"></ul>
          <p class="tc-empty" data-i18n="calc.emptySide">Пока пусто — добавьте предметы</p>

          <div class="tc-total-row">
            <span class="tc-total-label" data-i18n="calc.total">Итого</span>
            <strong class="tc-total-value">0</strong>
          </div>
        </section>

        <div class="tc-versus" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none"><path d="M7 7h13M16 3l4 4-4 4M17 17H4M8 13l-4 4 4 4" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>

        <section class="tc-side" data-side="right" aria-labelledby="tcGetHeading">
          <div class="tc-side-head">
            <h2 class="tc-side-title" id="tcGetHeading" data-i18n="calc.getLabel">Вы получаете</h2>
            <button type="button" class="tc-clear-side" title="Очистить: Вы получаете" aria-label="Очистить: Вы получаете">✕</button>
          </div>

          <div class="tc-search">
            <label class="tc-sr-only" for="tcSearchRight" data-i18n="calc.searchLabel">Поиск предмета</label>
            <input type="text" class="tc-search-input" id="tcSearchRight"
                   data-i18n-placeholder="calc.searchPlaceholder" placeholder="Название предмета…"
                   autocomplete="off" spellcheck="false" />
            <ul class="tc-suggest" hidden></ul>
          </div>

          <ul class="tc-list"></ul>
          <p class="tc-empty" data-i18n="calc.emptySide">Пока пусто — добавьте предметы</p>

          <div class="tc-total-row">
            <span class="tc-total-label" data-i18n="calc.total">Итого</span>
            <strong class="tc-total-value">0</strong>
          </div>
        </section>
      </div>

      <!-- role="status" + aria-live: разница и вердикт обязаны озвучиваться
           скринридером при каждом изменении состава сторон (см. ТЗ). Одна
           общая область, а не отдельная на каждый кусок — иначе смена сделки
           звучала бы двумя-тремя отдельными, рассинхронизированными репликами. -->
      <section class="tc-result" id="tcResult" role="status" aria-live="polite">
        <div class="tc-result-top">
          <div class="tc-diff">
            <span class="tc-diff-label" data-i18n="calc.diffLabel">Разница</span>
            <strong class="tc-diff-value" id="tcDiffValue">0 (0%)</strong>
          </div>
          <div class="tc-verdict" id="tcVerdict" data-verdict="fair">
            <span id="tcVerdictText" data-i18n="calc.verdictFair">Честная сделка</span>
          </div>
        </div>
        <p class="tc-threshold" id="tcThreshold">Сделка считается честной, если разница в пределах ±5%</p>
        <p class="tc-demand-note" id="tcDemandNote" hidden></p>
      </section>

      <p class="tc-disclaimer" data-i18n="calc.disclaimer">Значения — это оценка ценности по нашему тирлисту, а не игровое ограничение на обмен Blox Fruits. Решение — за вами.</p>

      <div class="tc-actions">
        <button type="button" class="tc-btn tc-btn-accent" id="tcShareBtn" data-i18n="calc.shareBtn">Скопировать ссылку</button>
        <button type="button" class="tc-btn tc-btn-ghost" id="tcClearAllBtn" data-i18n="calc.clearAll">Очистить всё</button>
      </div>
      <p class="tc-sr-only" id="tcShareStatus" role="status" aria-live="polite"></p>
    </div>
  </main>

  <!-- Подвал сайта — тот же, что на главной, тирлисте и в ленте. -->
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
  </footer>

  <script src="js/i18n.js?v=18"></script>
  <script src="js/calc.js?v=1"></script>
  <script src="js/calculator-page.js?v=1"></script>
</body>
</html>
