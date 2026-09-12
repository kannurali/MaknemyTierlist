<?php
require_once __DIR__ . '/api/_bootstrap.php';
require_once __DIR__ . '/api/lib/metrika.php';

// Чаты — /chat (Figma «трейдинг чат», node 244:5707).
//
// Разметка ниже — каркас: список диалогов, пузыри сообщений и форму отзыва
// наполняет js/chat-page.js по ответу /api/chat.php. Кто пишет — берётся из
// сессии Roblox, той же, что у api/session.php.
//
// Не вошедшему страница предлагает войти, а не делает вид, что переписки нет.
// Пока таблиц чата нет (миграция docs/migrations/2026-09-09-chat.sql не
// запущена) — тоже честное пустое состояние, а не 500.
//
// noindex, nofollow: переписка приватная, индексировать её нельзя, и ходить
// по ссылкам из неё поисковику незачем. В sitemap.xml её нет.
header('Cache-Control: no-cache, must-revalidate');

// Есть ли на сайте страница профиля. Она приезжает отдельной веткой, и до неё
// имя собеседника ссылкой быть не должно: ссылка в 404 хуже её отсутствия.
// Проверяем наличие файла, а не пишем флаг руками: так порядок вливания веток
// перестаёт иметь значение — появился profile.php, заработали и ссылки.
$ctProfile = is_file(__DIR__ . '/profile.php');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="color-scheme" content="dark" />

<base href="/" />

<title>Чаты | Maknemy Tier List</title>
<meta name="description" content="Личные сообщения игроков Maknemy: переписка по сделкам и отзывы." />
<link rel="canonical" href="https://maknemy.com/chat" />
<meta name="robots" content="noindex, nofollow" />



<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "WebApplication",
      "name": "Калькулятор трейдов Blox Fruits",
      "alternateName": ["Макнеми калькулятор", "Maknemy calculator", "Калькулятор трейдов Maknemy"],
      "url": "https://maknemy.com/calculator",
      "inLanguage": "ru",
      "applicationCategory": "UtilityApplication",
      "operatingSystem": "Any",
      "browserRequirements": "Requires JavaScript",
      "description": "Калькулятор трейдов Blox Fruits от Maknemy: обе стороны сделки считаются по ценам тирлиста Maknemy.",
      "isPartOf": {
        "@type": "WebSite",
        "name": "Maknemy Tier List",
        "url": "https://maknemy.com/"
      },
      "offers": {
        "@type": "Offer",
        "price": "0",
        "priceCurrency": "RUB"
      },
      "author": {
        "@type": "Person",
        "name": "Maknemy",
        "url": "https://t.me/mksvtnc"
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
          "name": "Калькулятор трейдов",
          "item": "https://maknemy.com/calculator"
        }
      ]
    }
  ]
}
</script>

<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="icon" type="image/png" href="/assets/favicon.png?v=2" sizes="256x256" />
<link rel="apple-touch-icon" href="/apple-touch-icon.png" />

<link rel="stylesheet" href="css/base.css?v=10" />

<link rel="stylesheet" href="css/topbar.css?v=11" />

<script src="js/topbar.js?v=5" defer></script>

<link rel="stylesheet" href="css/design-page.css?v=32" />
<link rel="stylesheet" href="css/chat.css?v=5" />



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

      <a class="mk-chat" href="/chat" data-i18n-label="nav.chat" aria-label="Чат" aria-current="page">
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

  <main class="ct-page">
    <p class="ct-gate" id="ctGate" hidden></p>

    <div class="ct-shell" id="ctShell"<?= $ctProfile ? ' data-profile="1"' : '' ?> hidden>
      <aside class="ct-rail" id="ctRail" aria-labelledby="ctRailTitle">
        <h2 class="ct-sr-only" id="ctRailTitle" data-i18n="chat.threads">Диалоги</h2>
        <ul class="ct-list" id="ctList" role="tablist" aria-orientation="vertical"></ul>
        <p class="ct-rail-empty" id="ctRailEmpty" hidden></p>
      </aside>

      <section class="ct-room" id="ctRoom" role="tabpanel" aria-labelledby="ctRoomTitle">
        <div class="ct-room-head">
          <button class="ct-rail-toggle" type="button" id="ctRailToggle"
                  aria-expanded="false" aria-controls="ctList"
                  data-i18n-label="chat.showList" aria-label="Показать список диалогов">
            <span></span><span></span><span></span>
          </button>
          <h1 class="ct-room-title" id="ctRoomTitle" data-i18n="chat.title">Чаты</h1>
        </div>

        <ol class="ct-log" id="ctLog" role="log" aria-live="polite" aria-relevant="additions"></ol>
        <p class="ct-room-empty" id="ctRoomEmpty" hidden></p>

        <form class="ct-compose" id="ctCompose" hidden>
          <button class="ct-emoji-btn" type="button" id="ctEmojiBtn"
                  aria-expanded="false" aria-controls="ctEmoji"
                  data-i18n-label="chat.emoji" aria-label="Смайлики"
                  data-i18n-title="chat.emoji" title="Смайлики">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M8.3 14.2c.9 1.3 2.2 2 3.7 2s2.8-.7 3.7-2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="9.1" cy="9.8" r="1.25" fill="currentColor"/><circle cx="14.9" cy="9.8" r="1.25" fill="currentColor"/></svg>
          </button>

          <div class="ct-emoji" id="ctEmoji" role="group"
               data-i18n-label="chat.emoji" aria-label="Смайлики" hidden></div>

          <label class="ct-sr-only" for="ctInput" data-i18n="chat.inputLabel">Сообщение</label>
          <input class="ct-input" id="ctInput" type="text" autocomplete="off"
                 maxlength="2000" data-i18n-placeholder="chat.placeholder"
                 placeholder="Напишите сообщение…" />
          <button class="ct-send" type="submit" data-i18n="chat.send">Отправить</button>
        </form>
      </section>

      <form class="ct-review" id="ctReview" hidden>
        <span class="ct-review-label" data-i18n="chat.review">Отзыв</span>

        <label class="ct-sr-only" for="ctReviewText" data-i18n="chat.reviewLabel">Текст отзыва</label>
        <input class="ct-review-input" id="ctReviewText" type="text" autocomplete="off"
               maxlength="500" data-i18n-placeholder="chat.reviewPlaceholder"
               placeholder="Как прошла сделка?" />

        <fieldset class="ct-stars" id="ctStars">
          <legend class="ct-sr-only" data-i18n="chat.stars">Оценка</legend>
          <label class="ct-star"><input type="radio" name="stars" value="1" /><span aria-hidden="true">★</span><span class="ct-sr-only">1</span></label>
          <label class="ct-star"><input type="radio" name="stars" value="2" /><span aria-hidden="true">★</span><span class="ct-sr-only">2</span></label>
          <label class="ct-star"><input type="radio" name="stars" value="3" /><span aria-hidden="true">★</span><span class="ct-sr-only">3</span></label>
          <label class="ct-star"><input type="radio" name="stars" value="4" /><span aria-hidden="true">★</span><span class="ct-sr-only">4</span></label>
          <label class="ct-star"><input type="radio" name="stars" value="5" /><span aria-hidden="true">★</span><span class="ct-sr-only">5</span></label>
        </fieldset>

        <button class="ct-review-send" type="submit" data-i18n="chat.reviewSend">Отправить</button>
        <p class="ct-review-status" id="ctReviewStatus" role="status" aria-live="polite"></p>
      </form>
    </div>

    <p class="ct-empty" id="ctEmpty" hidden></p>
  </main>

    <div class="tc-cat-backdrop" id="tcCatalogBackdrop" hidden>
      <div class="tc-cat" role="dialog" aria-modal="true" aria-labelledby="tcCatalogTitle" id="tcCatalog">
        <div class="tc-cat-head">
          <div class="tc-cat-search">
            <label class="tc-sr-only" for="tcCatalogSearch" data-i18n="calc.searchLabel">Поиск предмета</label>
            <input type="text" id="tcCatalogSearch" class="tc-search-input"
                   data-i18n-placeholder="calc.searchPlaceholder" placeholder="Название предмета…"
                   autocomplete="off" spellcheck="false" />
          </div>

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

  <script src="js/i18n.js?v=44"></script>
  <script src="js/chat-page.js?v=7" defer></script>


</body>
</html>
