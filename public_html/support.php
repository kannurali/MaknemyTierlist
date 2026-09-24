<?php
require_once __DIR__ . '/api/_bootstrap.php';
require_once __DIR__ . '/api/lib/metrika.php';

// Центр обращений — /support (Figma нода 272:2454, окно благодарности —
// «оверлей» 272:2783). Ведут сюда «поддержка» на ленте трейдинга, «в
// поддержку!» в предупреждении о безопасности и «Помощь» в меню профиля.
//
// Обращение уходит в /api/support.php и видно администратору на
// /admin/support. Писать может только вошедший через Roblox (ответ приходит
// в личный чат сайта); остальным страница предлагает войти или «написать
// лично» — ссылка на Telegram из прототипа макета.
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

<title>Центр обращений | Maknemy Tier List</title>
<meta name="description" content="Поддержка Maknemy: сообщите о недобросовестном трейдере или проблеме на сайте." />
<link rel="canonical" href="https://maknemy.com/support" />
<meta name="robots" content="noindex, follow" />

<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="icon" type="image/png" href="/assets/favicon.png?v=2" sizes="256x256" />
<link rel="apple-touch-icon" href="/apple-touch-icon.png" />

<link rel="stylesheet" href="css/base.css?v=12" />

<link rel="stylesheet" href="css/topbar.css?v=12" />

<script src="js/auth.js?v=1" fetchpriority="high"></script>
<script src="js/topbar.js?v=11" defer fetchpriority="high"></script>

<link rel="stylesheet" href="css/design-page.css?v=34" />
<link rel="stylesheet" href="css/trading.css?v=4" />

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

          <a class="mk-pill" href="/trading">
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
  <main class="sp-page">
    <section class="sp-card" aria-labelledby="spTitle">
      <svg class="sp-icon" viewBox="0 0 100 80" fill="none" aria-hidden="true">
        <defs>
          <linearGradient id="spGrad" x1="0" y1="0" x2="100" y2="80" gradientUnits="userSpaceOnUse">
            <stop stop-color="#61B5E9"/><stop offset="1" stop-color="#2D4AED"/>
          </linearGradient>
        </defs>
        <path d="M6 24c0-4.4 3.6-8 8-8h10L47 2.2C52.3-.9 59 2.9 59 9v62c0 6.1-6.7 9.9-12 6.8L24 64H14c-4.4 0-8-3.6-8-8V24Z" fill="url(#spGrad)"/>
        <path d="M71 22c5.2 4.4 8.5 11 8.5 18S76.2 53.6 71 58" stroke="url(#spGrad)" stroke-width="7" stroke-linecap="round"/>
        <path d="M83 12c8.2 7 13.3 17.1 13.3 28S91.2 61 83 68" stroke="url(#spGrad)" stroke-width="7" stroke-linecap="round"/>
      </svg>
      <h1 class="sp-title" id="spTitle" data-i18n="support.title">Центр обращений</h1>

      <p class="sp-lead">
        <span data-i18n="support.lead1a">Перед обращением просим</span>
        <em data-i18n="support.lead1b">соблюдать вежливость</em>
        <span data-i18n="support.lead1c">к персоналу и</span>
        <em data-i18n="support.lead1d">не нести ложную информацию.</em>
      </p>
      <p class="sp-lead">
        <span data-i18n="support.lead2a">Подробно опишите свою проблему, и мы оперативно поможем вам с</span>
        <em data-i18n="support.lead2b">её решением!</em>
      </p>

      <form class="sp-form" id="spForm" novalidate>
        <label class="tr-sr-only" for="spBody" data-i18n="support.label">Опишите свою проблему</label>
        <textarea class="sp-input" id="spBody" rows="5" maxlength="1000"
                  data-i18n-placeholder="support.placeholder" placeholder="Опишите свою проблему…"></textarea>
        <p class="sp-status" id="spStatus" role="status" aria-live="polite"></p>
        <div class="sp-actions">
          <button class="sp-send" id="spSend" type="submit" data-i18n="support.send">Отправить</button>
          <a class="sp-direct" href="https://t.me/theMaknemy" target="_blank" rel="noopener" data-i18n="support.direct">Написать лично</a>
        </div>
      </form>
    </section>

    <div class="sp-thanks" id="spThanks" role="dialog" aria-modal="true" aria-labelledby="spThanksTitle" hidden>
      <div class="sp-thanks-card">
        <button class="sp-thanks-close" id="spThanksClose" type="button" data-i18n-label="support.close" aria-label="Закрыть">✕</button>
        <svg class="sp-thanks-hearts" viewBox="0 0 170 120" fill="none" aria-hidden="true">
          <path d="M85 112C60 95 30 74 30 45 30 28 43 14 59 14c11 0 20 5.5 26 14 6-8.5 15-14 26-14 16 0 29 14 29 31 0 29-30 50-55 67Z" fill="#fff"/>
          <path d="M22 118C13 112 2 104 2 93c0-6.3 4.8-11.5 10.8-11.5 3.9 0 7.1 2 9.2 5 2.1-3 5.3-5 9.2-5 6 0 10.8 5.2 10.8 11.5 0 11-11 19-20 25Z" fill="#fff"/>
          <path d="M148 118c-9-6-20-14-20-25 0-6.3 4.8-11.5 10.8-11.5 3.9 0 7.1 2 9.2 5 2.1-3 5.3-5 9.2-5 6 0 10.8 5.2 10.8 11.5 0 11-11 19-20 25Z" fill="#fff"/>
        </svg>
        <h2 class="sp-thanks-title" id="spThanksTitle" data-i18n="support.thanksTitle">Спасибо за ваше доверие</h2>
        <p class="sp-thanks-text" data-i18n="support.thanksText">В ближайшее время мы решим вашу проблему!</p>
        <p class="sp-thanks-sign"><span data-i18n="support.thanksSign">С любовью</span> <img src="assets/design/wordmark.svg" alt="MAKNEMY" /></p>
      </div>
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
  <script src="js/i18n.js?v=56" fetchpriority="high"></script>
  <script src="js/support-page.js?v=1" defer></script>
</body>
</html>
