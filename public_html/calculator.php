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
<link rel="canonical" href="https://maknemy.com/calculator" />
<meta name="robots" content="index, follow, max-image-preview:large" />

<meta property="og:type" content="website" />
<meta property="og:site_name" content="Maknemy Tier List" />
<meta property="og:locale" content="ru_RU" />
<meta property="og:url" content="https://maknemy.com/calculator" />
<meta property="og:title" content="Калькулятор трейдов Blox Fruits" />
<meta property="og:description" content="Соберите обе стороны сделки по ценам тирлиста Maknemy и узнайте, выгодна ли она." />
<meta property="og:image" content="https://maknemy.com/assets/og-image.jpg?v=2" />
<meta property="og:image:width" content="1920" />
<meta property="og:image:height" content="1080" />
<meta property="og:image:type" content="image/jpeg" />
<meta name="twitter:card" content="summary_large_image" />

<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="icon" type="image/png" href="/assets/favicon.png?v=2" sizes="256x256" />
<link rel="apple-touch-icon" href="/apple-touch-icon.png" />

<link rel="stylesheet" href="css/base.css?v=7" />
<!-- Шапка редизайна: отсюда же приезжает @font-face для Oswald, которым
     набрана вся страница. -->
<link rel="stylesheet" href="css/topbar.css?v=7" />
<!-- Поведение шапки: компактный режим при прокрутке и плашка
     «В активной разработке» на разделах, которых ещё нет.
     defer — код лезет в DOM сразу, без ожидания события. -->
<script src="js/topbar.js?v=3" defer></script>
<!-- Фон страницы и подвал из редизайна — те же, что на главной и тирлисте. -->
<link rel="stylesheet" href="css/design-page.css?v=28" />
<link rel="stylesheet" href="css/calculator.css?v=15" />
</head>
<body>

  <!-- ================= Шапка ================= -->
  <header class="mk-top">
    <a class="mk-top-brand" href="/">
      <img class="mk-top-mark" src="assets/design/logo-mk-square.png" alt="" aria-hidden="true" />
      <img class="mk-top-word" src="assets/design/wordmark.svg" alt="MAKNEMY" />
    </a>

    <!-- Разделы и профиль лежат в одной плашке: аватар — последний элемент
         .mk-top-bar, за волосяным разделителем (см. topbar.css). Отдельной
         кнопкой рядом с меню он читался как чужой элемент. -->
    <nav class="mk-top-bar" id="mkTopBar" aria-label="Разделы сайта">
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
          <!-- «Трейдинг» с сайта пока снят, профиля тоже нет. Это
               <button data-soon>, а не мёртвый <span> и не href="#": кнопка
               кликается и по клику показывает «В активной разработке»
               (js/topbar.js). Пилюля, которая молчит в ответ на клик,
               читается как поломка сайта, а якорь-пустышка только дописывает
               "#" в адресную строку. Вернуть раздел — заменить тег на <a>
               с href и убрать data-soon (так уже сделано с «Калькулятором»
               ниже — у него теперь есть страница).

               aria-disabled намеренно нет: кнопка отвечает на нажатие, а
               «disabled» в ARIA значит «не работает вовсе» — скринридер
               объявил бы её недоступной, и до объяснения было бы не
               добраться. Приглушённый вид даёт селектор [data-soon]. -->
          <button class="mk-pill" type="button" data-soon data-i18n-title="topbar.soon" title="В активной разработке">
            <svg viewBox="0 0 18 19" fill="none" aria-hidden="true"><path d="M6.17037 0.943433L4.48309 4.31799M11.8297 0.943433L13.517 4.31799M11.8297 9.4324L8.29262 13.2053L6.17037 11.4903M5.6697 17.9214H12.3304C14.2079 17.9214 15.7998 16.5408 16.0653 14.6821L17.0276 7.94613C17.2711 6.24146 15.9484 4.71631 14.2264 4.71631H3.77368C2.0517 4.71631 0.728943 6.24145 0.972468 7.94613L1.93474 14.6821C2.20027 16.5408 3.79212 17.9214 5.6697 17.9214Z" stroke="currentColor" stroke-width="1.88644" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="mk-pill-text">Трейдинг</span>
          </button>
        </li>
        <li>
          <!-- «Калькулятор» получил страницу — /calculator (см. эту же
               страницу) — и вышел из «В активной разработке»: рабочая
               ссылка, как «Главная»/«Тирлист»/«Новости» выше. На самой
               странице калькулятора пилюля дополнительно несёт
               aria-current="page" — тем же приёмом, что «Тирлист» на
               index.php и «Новости» на news.php отмечают текущий раздел. -->
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

      <button class="mk-avatar" type="button" aria-label="Профиль" data-soon data-i18n-title="topbar.soon" title="В активной разработке">
        <svg viewBox="0 0 34 34" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M17.0003 2.83325C13.0883 2.83325 9.91699 6.00457 9.91699 9.91659C9.91699 13.8286 13.0883 16.9999 17.0003 16.9999C20.9123 16.9999 24.0837 13.8286 24.0837 9.91659C24.0837 6.00457 20.9123 2.83325 17.0003 2.83325Z" fill="currentColor"/><path fill-rule="evenodd" clip-rule="evenodd" d="M12.7503 18.4167C10.3947 18.4167 8.12945 19.4913 6.80192 21.109C6.12816 21.9301 5.65451 22.946 5.61326 24.072C5.57114 25.2218 5.98621 26.3442 6.8422 27.3234C8.92833 29.7099 12.2591 31.1667 17.0003 31.1667C21.7415 31.1667 25.0723 29.7099 27.1584 27.3234C28.0144 26.3442 28.4294 25.2218 28.3873 24.072C28.3461 22.946 27.8724 21.9301 27.1987 21.109C25.8711 19.4913 23.6058 18.4167 21.2503 18.4167H12.7503Z" fill="currentColor"/></svg>
      </button>
    </nav>

    <!-- Язычок. Как только страница уходит из самого верха, шапка гасит фон
         и убирает плашку разделов за правый край — экран освобождается
         целиком. Язычок остаётся единственным способом вернуть меню, и
         поэтому он не декор: без него навигации на прокрученной странице
         не было бы вовсе.

         aria-expanded говорит о состоянии плашки, aria-controls связывает
         кнопку с ней по id — скринридер объявит «свёрнуто/развёрнуто», а не
         просто «кнопка». Подпись меняет js/topbar.js вместе с состоянием. -->
    <button class="mk-top-toggle" type="button" id="mkTopToggle"
            aria-expanded="true" aria-controls="mkTopBar"
            data-i18n-label="topbar.showNav" aria-label="Показать разделы"
            data-i18n-title="topbar.showNav" title="Показать разделы">
      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 5.5 8.5 12l6.5 6.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
  </header>

  <!-- Раскладка страницы — макет Figma «калькулятор» (node 127:303): фрейм
       «лид» 1443×1038 сразу под шапкой, внутри всё стоит по макетным
       координатам (см. css/calculator.css). Порядок элементов в разметке —
       смысловой (доска, затем вердикт), позиции задаёт CSS. -->
  <main class="tc-page">
    <div class="tc-frame">

      <!-- Рекламные борта 248×670 по краям — реальные размещения слота "rail"
           (renderRails() в js/calculator-page.js): тот же документ
           /api/promo.php и тот же модуль js/promo.js, что у тирлиста и ленты.
           Пока слот не куплен, борт остаётся тем, чем он и является в
           макете, — полосатой заглушкой. -->
      <div class="tc-rail-slot tc-rail-slot-l" aria-hidden="true">
        <aside class="tc-rail" id="tcRailL" data-i18n-label="promo.rail" aria-label="Реклама сбоку"></aside>
      </div>
      <div class="tc-rail-slot tc-rail-slot-r" aria-hidden="true">
        <aside class="tc-rail" id="tcRailR" data-i18n-label="promo.rail" aria-label="Реклама сбоку"></aside>
      </div>

      <!-- Столбики состояния по бокам доски (в макете — «Frame 42/41»).
           Левый показывает состояние стороны «ВЫ», правый — «ВАМ». Порог
           честности тот же, что у вердикта (CALC.THRESHOLD_PCT), иначе
           столбики спорили бы с надписью в карточке. aria-hidden: это
           дублирование вердикта цветом, а сам вердикт уже объявляется
           через role="status". -->
      <div class="tc-gauge tc-gauge-l" id="tcGaugeL" data-state="none" aria-hidden="true">
        <span></span><span></span><span></span>
      </div>
      <div class="tc-gauge tc-gauge-r" id="tcGaugeR" data-state="none" aria-hidden="true">
        <span></span><span></span><span></span>
      </div>

      <!-- ================= Доска сравнения ================= -->
      <div class="tc-board">

        <div class="tc-hero">
          <h1 class="tc-title" data-i18n="calc.title">Сравнить цены</h1>
          <p class="tc-subtitle" data-i18n="calc.subtitle">Сравните цены фруктов в реальном времени!</p>
        </div>

        <!-- Пилюли сторон, стрелки-указатели и «vs» между ними. Обёртка не
             занимает места на макетной сетке — она нужна телефону, где всё
             это собирается в одну строку. -->
        <div class="tc-marks" aria-hidden="true">
          <span class="tc-pill tc-pill-l" data-i18n="calc.givePill">ВЫ</span>
          <span class="tc-arrow tc-arrow-l">
            <svg viewBox="0 0 33 12" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M32.155 11.5353H0L14.5132 8.72569L12.1752 0L32.155 11.5353Z" fill="url(#tcArrowGrad)"/>
              <defs>
                <linearGradient id="tcArrowGrad" x1="16.0775" y1="0" x2="16.0775" y2="11.5353" gradientUnits="userSpaceOnUse">
                  <stop stop-color="#61B5E9"/><stop offset="1" stop-color="#2D4AED"/>
                </linearGradient>
              </defs>
            </svg>
          </span>
          <span class="tc-vs" data-i18n="calc.versus">VS</span>
          <span class="tc-arrow tc-arrow-r">
            <svg viewBox="0 0 33 12" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M32.155 11.5353H0L14.5132 8.72569L12.1752 0L32.155 11.5353Z" fill="url(#tcArrowGrad)"/>
            </svg>
          </span>
          <span class="tc-pill tc-pill-r" data-i18n="calc.getPill">ВАМ</span>
        </div>

        <section class="tc-side" data-side="left" aria-labelledby="tcGiveHeading">
          <!-- Полный смысл стороны остаётся доступным именем секции для
               скринридера; на глаз в макете — короткая пилюля «ВЫ». -->
          <h2 class="tc-sr-only" id="tcGiveHeading" data-i18n="calc.giveLabel">Вы отдаёте</h2>

          <ul class="tc-slots" data-side="left"></ul>

          <div class="tc-meters">
            <span class="tc-meter-bar">
              <span class="tc-meter-mark" data-role="mark" aria-hidden="true">
                <svg viewBox="0 0 9 9" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path d="M6.418 5.067 4.054 2.702 1.689 5.067" stroke="#fff" stroke-width="1.013" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </span>
            </span>
            <span class="tc-meter-icon tc-meter-icon-points" aria-hidden="true">
              <svg viewBox="0 0 13 13" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M8.61375 3.54677L8.16057 2.64039C7.81725 1.95375 7.11546 1.52002 6.34778 1.52002H5.81285C5.04517 1.52002 4.34346 1.95359 4.00015 2.64022L3.54688 3.54677" stroke="#fff" stroke-width="1.01337" stroke-linecap="round"/>
                <path d="M5.06694 10.6405H4.29131C3.28269 10.6405 2.42757 9.89885 2.28493 8.90037L1.768 5.2819C1.63719 4.36617 2.34776 3.54688 3.27279 3.54688H8.88787C9.8129 3.54688 10.5235 4.36617 10.3927 5.28191L10.2455 6.31225" stroke="#fff" stroke-width="1.01337" stroke-linecap="round"/>
                <path d="M9.62704 9.12016C9.62704 9.95967 8.94648 10.6402 8.10698 10.6402C7.26747 10.6402 6.58691 9.95967 6.58691 9.12016C6.58691 8.28065 7.26747 7.6001 8.10698 7.6001C8.94648 7.6001 9.62704 8.28065 9.62704 9.12016Z" stroke="#fff" stroke-width="1.01337"/>
              </svg>
            </span>
            <span class="tc-meter-label tc-meter-label-points" data-i18n="calc.pointsLabel">Пойнты</span>
            <strong class="tc-meter-value tc-meter-value-points" data-role="points">0</strong>
            <span class="tc-meter-icon tc-meter-icon-demand" aria-hidden="true">
              <svg viewBox="0 0 12 8" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M0.5 6.6 4.34 2.03 7.1 5.32 11.15 0.51" stroke="#fff" stroke-width="1.013" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M6.6 0.95 11.1 0.5 11.55 5.05" stroke="#fff" stroke-width="1.013" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </span>
            <span class="tc-meter-label tc-meter-label-demand" data-i18n="calc.demandLabel">Спрос</span>
            <span class="tc-demand-dot" data-demand="none" data-role="demand" aria-hidden="true"></span>
          </div>
        </section>

        <section class="tc-side" data-side="right" aria-labelledby="tcGetHeading">
          <h2 class="tc-sr-only" id="tcGetHeading" data-i18n="calc.getLabel">Вы получаете</h2>

          <ul class="tc-slots" data-side="right"></ul>

          <div class="tc-meters">
            <span class="tc-meter-bar">
              <span class="tc-meter-mark" data-role="mark" aria-hidden="true">
                <svg viewBox="0 0 9 9" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path d="M6.418 5.067 4.054 2.702 1.689 5.067" stroke="#fff" stroke-width="1.013" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </span>
            </span>
            <span class="tc-meter-icon tc-meter-icon-points" aria-hidden="true">
              <svg viewBox="0 0 13 13" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M8.61375 3.54677L8.16057 2.64039C7.81725 1.95375 7.11546 1.52002 6.34778 1.52002H5.81285C5.04517 1.52002 4.34346 1.95359 4.00015 2.64022L3.54688 3.54677" stroke="#fff" stroke-width="1.01337" stroke-linecap="round"/>
                <path d="M5.06694 10.6405H4.29131C3.28269 10.6405 2.42757 9.89885 2.28493 8.90037L1.768 5.2819C1.63719 4.36617 2.34776 3.54688 3.27279 3.54688H8.88787C9.8129 3.54688 10.5235 4.36617 10.3927 5.28191L10.2455 6.31225" stroke="#fff" stroke-width="1.01337" stroke-linecap="round"/>
                <path d="M9.62704 9.12016C9.62704 9.95967 8.94648 10.6402 8.10698 10.6402C7.26747 10.6402 6.58691 9.95967 6.58691 9.12016C6.58691 8.28065 7.26747 7.6001 8.10698 7.6001C8.94648 7.6001 9.62704 8.28065 9.62704 9.12016Z" stroke="#fff" stroke-width="1.01337"/>
              </svg>
            </span>
            <span class="tc-meter-label tc-meter-label-points" data-i18n="calc.pointsLabel">Пойнты</span>
            <strong class="tc-meter-value tc-meter-value-points" data-role="points">0</strong>
            <span class="tc-meter-icon tc-meter-icon-demand" aria-hidden="true">
              <svg viewBox="0 0 12 8" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M0.5 6.6 4.34 2.03 7.1 5.32 11.15 0.51" stroke="#fff" stroke-width="1.013" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M6.6 0.95 11.1 0.5 11.55 5.05" stroke="#fff" stroke-width="1.013" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </span>
            <span class="tc-meter-label tc-meter-label-demand" data-i18n="calc.demandLabel">Спрос</span>
            <span class="tc-demand-dot" data-demand="none" data-role="demand" aria-hidden="true"></span>
          </div>
        </section>

        <!-- Итоговая строка под сторонами: полоса во всю доску, знак
             равенства и разница в пойнтах. -->
        <div class="tc-total">
          <span class="tc-total-line" aria-hidden="true"></span>
          <span class="tc-total-eq" aria-hidden="true"></span>
          <strong class="tc-total-num" id="tcTotalNum">0</strong>
        </div>
      </div>

      <!-- role="status" + aria-live: разница и вердикт обязаны озвучиваться
           скринридером при каждом изменении состава сторон. Одна общая
           область, а не отдельная на каждый кусок — иначе смена сделки
           звучала бы двумя-тремя рассинхронизированными репликами. -->
      <section class="tc-result" id="tcResult" role="status" aria-live="polite">
        <span class="tc-result-badge" id="tcVerdictBadge" data-verdict="none" aria-hidden="true">
          <!-- viewBox равен значку макета (Frame 51, 134×134), поэтому
               координаты ниже — те же числа, что в Figma, без пересчёта.
               Все четыре лица лежат в разметке, показывает одно CSS по
               data-verdict: строить их в JS значило бы собирать SVG строкой. -->
          <svg viewBox="0 0 134 134" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="67" cy="67" r="39.5" stroke="#fff" stroke-width="5"/>
            <g class="tc-face tc-face-none">
              <circle cx="50.5" cy="62.5" r="8.5" fill="#d9d9d9"/>
              <circle cx="84.5" cy="62.5" r="8.5" fill="#d9d9d9"/>
              <path d="M55.5 81.5C55.5 81.5 59.5 88 67 88C74.5 88 78.5 81.5 78.5 81.5"
                    stroke="#fff" stroke-width="5" stroke-linecap="round"/>
            </g>
            <g class="tc-face tc-face-lose" stroke="#fff" stroke-width="5" stroke-linecap="round">
              <path d="M75 63L88.5 66.69"/>
              <path d="M45 66.66L58.52 63.03"/>
              <path d="M57.03 87C57.03 87 55.98 79 66 79C76.02 79 74.97 87 74.97 87"/>
            </g>
            <g class="tc-face tc-face-win" stroke="#fff" stroke-width="5" stroke-linecap="round">
              <path d="M45 66.62C45 66.62 46.5 62.53 50.5 61.46C54.5 60.39 58.52 62.99 58.52 62.99"/>
              <path d="M87.97 66.48C87.97 66.48 86.53 62.55 82.68 61.52C78.84 60.49 74.97 62.99 74.97 62.99"/>
              <path d="M74.97 78.96C74.97 78.96 76.02 86.96 66 86.96C55.98 86.96 57.03 78.96 57.03 78.96"/>
            </g>
            <g class="tc-face tc-face-fair" fill="#fff">
              <rect x="47" y="59" width="7" height="7" rx="3.5"/>
              <rect x="80" y="59" width="7" height="7" rx="3.5"/>
              <rect x="47" y="71" width="40" height="7" rx="3.5"/>
            </g>
          </svg>
        </span>
        <div class="tc-result-card">
          <h2 class="tc-verdict-heading" id="tcVerdictHeading" data-i18n="calc.verdictPrompt">Проверим?</h2>
          <span class="tc-result-line" aria-hidden="true"></span>
          <span class="tc-verdict-state" id="tcVerdictState"></span>
          <strong class="tc-verdict-number" id="tcVerdictNumber">0%</strong>
        </div>
      </section>
    </div>

    <!-- ============ Служебная полоса ============
         Переключателя языка, кнопок и оговорок в макете нет: доска там
         всегда пустая, делиться нечем и объяснять нечего. На сайте всё это
         нужно, поэтому вынесено под макетный фрейм — композиция макета
         остаётся нетронутой. -->
    <div class="tc-extras">

      <!-- Тот же компонент, что в тулбаре тирлиста/ленты (base.css:
           .lang-switch/.chip) — своих стилей переключателю здесь заводить
           не нужно. -->
      <div class="lang-switch tc-lang" id="langSwitch" role="group"
           data-i18n-label="lang.switch" aria-label="Язык интерфейса">
        <button class="chip" type="button" data-lang="ru" data-i18n="lang.ru" aria-pressed="false">RU</button>
        <button class="chip" type="button" data-lang="en" data-i18n="lang.en" aria-pressed="false">EN</button>
      </div>

      <p class="tc-state" id="tcState" role="status" aria-live="polite" hidden></p>

      <!-- Подсказка про спрос лежит рядом с вердиктом по смыслу, но не внутри
           карточки: в макете у карточки фиксированная высота 199, и абзац
           переменной длины ломал бы её. Своя aria-live — чтобы предупреждение
           всё равно прозвучало, как когда оно жило внутри #tcResult. -->
      <p class="tc-demand-note" id="tcDemandNote" role="status" aria-live="polite" hidden></p>

      <p class="tc-threshold" id="tcThreshold">Сделка считается честной, если разница в пределах ±5%</p>
      <p class="tc-disclaimer" data-i18n="calc.disclaimer">Значения — это оценка ценности по нашему тирлисту, а не игровое ограничение на обмен Blox Fruits. Решение — за вами.</p>

      <div class="tc-actions">
        <button type="button" class="tc-btn tc-btn-accent" id="tcShareBtn" data-i18n="calc.shareBtn">Скопировать ссылку</button>
        <button type="button" class="tc-btn tc-btn-ghost" id="tcClearAllBtn" data-i18n="calc.clearAll">Очистить всё</button>
      </div>
      <p class="tc-sr-only" id="tcShareStatus" role="status" aria-live="polite"></p>
    </div>

    <!-- ============ Каталог предметов — отдельная панель поверх страницы ============ -->
  </main>

  <!-- Оверлей каталога лежит прямым потомком <body>, а НЕ внутри .tc-page.
       У .tc-page есть position:relative и z-index:2 — это контекст наложения,
       и внутри него z-index:80 у оверлея ничего не значил: весь блок целиком
       оставался на уровне 2, ниже шапки с её 60. Поле поиска уходило под
       шапку и было не видно, что набираешь. Подкручивать числа тут
       бесполезно — надо стоять вне запирающего контейнера. -->
    <div class="tc-cat-backdrop" id="tcCatalogBackdrop" hidden>
      <div class="tc-cat" role="dialog" aria-modal="true" aria-labelledby="tcCatalogTitle" id="tcCatalog">
        <div class="tc-cat-head">
          <div class="tc-cat-search">
            <label class="tc-sr-only" for="tcCatalogSearch" data-i18n="calc.searchLabel">Поиск предмета</label>
            <input type="text" id="tcCatalogSearch" class="tc-search-input"
                   data-i18n-placeholder="calc.searchPlaceholder" placeholder="Название предмета…"
                   autocomplete="off" spellcheck="false" />
          </div>
          <!-- Лупа в макете стоит отдельным кружком справа от поля. Это
               подпись к полю, а не кнопка: список фильтруется по вводу, и
               нажимать тут нечего. -->
          <span class="tc-cat-search-btn" aria-hidden="true">
            <svg class="tc-cat-search-icon" viewBox="0 0 20 20" fill="none">
              <circle cx="8.5" cy="8.5" r="6.5" stroke="currentColor" stroke-width="1.8" />
              <path d="M18 18L13.5 13.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            </svg>
          </span>
        </div>
        <div class="tc-cat-sub">
          <span class="tc-pill" id="tcCatalogTitle" data-i18n="calc.catalogPill">Каталог</span>
          <button type="button" class="tc-cat-close" id="tcCatalogClose" data-i18n-label="calc.catalogClose" aria-label="Закрыть каталог">✕</button>
        </div>
        <p class="tc-cat-status" id="tcCatalogStatus" role="status" aria-live="polite"></p>
        <ul class="tc-cat-grid" id="tcCatalogGrid"></ul>
        <p class="tc-cat-footer" data-i18n="calc.catalogFooter">Используйте калькулятор с умом!</p>
      </div>
    </div>

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

  <script src="js/i18n.js?v=27"></script>
  <script src="js/promo.js?v=2"></script>
  <script src="js/calc.js?v=6"></script>
  <script src="js/calculator-page.js?v=13"></script>
</body>
</html>
