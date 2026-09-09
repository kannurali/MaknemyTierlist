<?php
require_once __DIR__ . '/api/_bootstrap.php';
require_once __DIR__ . '/api/lib/metrika.php';
require_once __DIR__ . '/api/lib/profile.php';

// Профиль игрока — /profile (Figma «профиль», node 244:7400).
//
// Профиль всегда СВОЙ: чужие по адресу не открываются, и чей это профиль
// решает сессия, а не параметр запроса. Разбор решений по вёрстке и данным —
// в docs/profile-page.md; здесь их нет намеренно, комментарии из отдаваемых
// файлов уехали бы к посетителю (политика коммита dcb9b8e).
//
// Вошёл человек или нет, решаем ЗДЕСЬ, а не скриптом: иначе страница сначала
// показала бы пустую карточку и только потом сменила её на предложение
// войти — и наоборот. Данные при этом всё равно приезжают запросом
// (/api/profile-stats.php): в базу страница не ходит, ей достаточно сессии.
//
// noindex: профиль показывает личные данные вошедшего, а поисковому роботу
// в него не войти — он увидел бы только гейт. По той же причине /profile нет
// в sitemap.xml.
//
// Cache-Control тот же, что у остальных страниц: файл несёт номера версий
// ?v= для css/js, и закешированная копия намертво прибила бы посетителя к
// старому коду.
header('Cache-Control: no-cache, must-revalidate');
start_site_session();
$pfAuthed = profile_me($_SESSION) !== '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="color-scheme" content="dark" />

<base href="/" />

<title>Профиль игрока | Maknemy Tier List</title>
<meta name="description" content="Профиль игрока Maknemy: ник, статистика сделок и репутация." />
<link rel="canonical" href="https://maknemy.com/profile" />
<meta name="robots" content="noindex, follow" />

<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="icon" type="image/png" href="/assets/favicon.png?v=2" sizes="256x256" />
<link rel="apple-touch-icon" href="/apple-touch-icon.png" />

<link rel="stylesheet" href="css/base.css?v=11" />
<link rel="stylesheet" href="css/topbar.css?v=12" />
<script src="js/auth.js?v=1" defer></script>
<script src="js/topbar.js?v=6" defer></script>
<link rel="stylesheet" href="css/design-page.css?v=32" />
<link rel="stylesheet" href="css/profile.css?v=1" />
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

  <main class="pf-page">
    <p class="pf-gate" id="pfGate" data-i18n="profile.login"<?php if ($pfAuthed): ?> hidden<?php endif; ?>>Войдите через Roblox — кнопка входа в шапке справа</p>

    <section class="pf-card" id="pfCard" aria-labelledby="pfNick"<?php if (!$pfAuthed): ?> hidden<?php endif; ?>>

      <div class="pf-avatar">
        <img id="pfAvatar" alt="" hidden />
        <svg class="pf-avatar-empty" viewBox="0 0 34 34" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M17.0003 2.83325C13.0883 2.83325 9.91699 6.00457 9.91699 9.91659C9.91699 13.8286 13.0883 16.9999 17.0003 16.9999C20.9123 16.9999 24.0837 13.8286 24.0837 9.91659C24.0837 6.00457 20.9123 2.83325 17.0003 2.83325Z" fill="currentColor"/><path fill-rule="evenodd" clip-rule="evenodd" d="M12.7503 18.4167C10.3947 18.4167 8.12945 19.4913 6.80192 21.109C6.12816 21.9301 5.65451 22.946 5.61326 24.072C5.57114 25.2218 5.98621 26.3442 6.8422 27.3234C8.92833 29.7099 12.2591 31.1667 17.0003 31.1667C21.7415 31.1667 25.0723 29.7099 27.1584 27.3234C28.0144 26.3442 28.4294 25.2218 28.3873 24.072C28.3461 22.946 27.8724 21.9301 27.1987 21.109C25.8711 19.4913 23.6058 18.4167 21.2503 18.4167H12.7503Z" fill="currentColor"/></svg>
      </div>

      <nav class="pf-menu" data-i18n-label="profile.menuLabel" aria-label="Действия с аккаунтом">
        <button class="pf-menu-toggle" type="button" id="pfMenuToggle"
                aria-expanded="true" aria-controls="pfMenuList"
                aria-label="Свернуть меню профиля" title="Свернуть меню профиля">
          <span></span><span></span><span></span>
        </button>
        <ul class="pf-menu-list" id="pfMenuList">
          <li><button class="pf-menu-item" type="button" data-soon data-i18n-title="topbar.soon" title="В активной разработке" data-i18n="profile.menuChats">Чаты</button></li>
          <li><button class="pf-menu-item" type="button" data-soon data-i18n-title="topbar.soon" title="В активной разработке" data-i18n="profile.menuHelp">Помощь</button></li>
          <li><button class="pf-menu-item" type="button" id="pfLogout" data-i18n="profile.menuLogout">Выйти из аккаунта</button></li>
          <li><button class="pf-menu-item" type="button" id="pfSwitch" data-i18n="profile.menuSwitch">Сменить аккаунт</button></li>
          <li class="pf-menu-danger"><button class="pf-menu-item" type="button" data-soon data-i18n-title="topbar.soon" title="В активной разработке" data-i18n="profile.menuDelete">Удалить аккаунт</button></li>
        </ul>
      </nav>

      <h1 class="pf-nick" id="pfNick" data-i18n="profile.nick">Игровой ник</h1>
      <p class="pf-handle" id="pfHandle" data-i18n="profile.handle">@никнейм</p>

      <div class="pf-meta">
        <div class="pf-status" id="pfStatus" role="img"
             data-i18n-label="profile.statusUnknown" aria-label="Статус неизвестен">
          <i data-state="online"></i><i data-state="offline"></i>
        </div>

        <p class="pf-react">
          <span class="pf-react-item">
            <svg viewBox="0 0 32 25" fill="none" aria-hidden="true"><path d="M16 24.5C16 24.5 1.5 16.2 1.5 8.2 1.5 4 4.8 1 8.7 1c2.9 0 5.6 1.7 7.3 4.3C17.7 2.7 20.4 1 23.3 1 27.2 1 30.5 4 30.5 8.2c0 8-14.5 16.3-14.5 16.3Z" fill="currentColor"/></svg>
            <b id="pfLikes">0</b>
            <span class="pf-sr-only" data-i18n="profile.likes">Положительных отзывов</span>
          </span>
          <span class="pf-react-item">
            <svg viewBox="0 0 28 37" fill="none" aria-hidden="true"><path d="M14.6 12.2 11 8.4 15.4 1 12.2 0C8.9.4 6 2.4 4.4 5.3 2.4 8.9 3.3 13.2 5.6 16.4c2.4 3.4 6 6.2 8.4 8.1l-2.6 5.3 3.9-3.6 2.7 4.9-1.4-6.2c2.5-2.1 5.7-4.8 7.8-8 2-3.1 2.7-7.1.9-10.5" fill="currentColor"/></svg>
            <b id="pfDislikes">0</b>
            <span class="pf-sr-only" data-i18n="profile.dislikes">Отрицательных отзывов</span>
          </span>
        </p>
      </div>

      <figure class="pf-chart" id="pfChart" aria-labelledby="pfChartTitle">
        <figcaption class="pf-chart-head">
          <span class="pf-chart-title" id="pfChartTitle" data-i18n="profile.chartTitle">Сделки по дням</span>

          <span class="pf-chart-legend">
            <span class="pf-legend pf-legend-ok" data-i18n="profile.chartOk">Успешно</span>
            <span class="pf-legend pf-legend-no" data-i18n="profile.chartFail">Отказ</span>
          </span>

          <label class="pf-chart-month">
            <span class="pf-sr-only" data-i18n="profile.chartMonth">Месяц</span>
            <select id="pfMonth" class="pf-chart-month-select"></select>
          </label>
        </figcaption>

        <div class="pf-plot" id="pfPlot" tabindex="0"
             data-i18n-label="profile.chartHint"
             aria-label="Поле графика: стрелками влево и вправо — значения по дням"></div>

        <p class="pf-chart-readout" id="pfReadout" role="status" aria-live="polite" hidden></p>
        <p class="pf-chart-empty" id="pfChartEmpty" hidden></p>

        <div class="pf-bar-row">
          <span class="pf-bar-label" data-i18n="profile.chartSum">Оборот</span>
          <span class="pf-bar-now" id="pfBarNow">0</span>
          <div class="pf-bar" role="presentation"><i class="pf-bar-fill" id="pfBarFill"></i></div>
          <span class="pf-bar-max" id="pfBarMax">0</span>
        </div>

        <table class="pf-sr-only" id="pfChartTable"></table>
      </figure>

      <h2 class="pf-about-title" data-i18n="profile.aboutTitle">О себе</h2>
      <div class="pf-about">
        <label class="pf-sr-only" for="pfAboutInput" data-i18n="profile.aboutTitle">О себе</label>
        <textarea class="pf-about-input" id="pfAboutInput" rows="2" disabled
                  data-i18n-placeholder="profile.aboutEmpty"
                  placeholder="Опишите себя так, чтобы остальным захотелось с вами трейд ;)"></textarea>
        <p class="pf-about-foot">
          <span class="pf-about-status" id="pfAboutStatus" role="status" aria-live="polite"></span>
          <span class="pf-about-count" id="pfAboutCount">0 / 280</span>
          <button class="pf-about-save" type="button" id="pfAboutSave" data-i18n="profile.aboutSave" disabled>Сохранить</button>
        </p>
      </div>

      <dl class="pf-stats">
        <div class="pf-stat">
          <dt data-i18n="profile.statDeals">Сделки</dt>
          <dd id="pfStatDeals">0</dd>
        </div>
        <div class="pf-stat">
          <dt data-i18n="profile.statCreated">Созданные</dt>
          <dd id="pfStatCreated">0</dd>
        </div>
        <div class="pf-stat">
          <dt data-i18n="profile.statCancelled">Отменённые</dt>
          <dd id="pfStatCancelled">0</dd>
        </div>
      </dl>

      <p class="pf-stats-note" data-i18n="profile.statsNote">Чем больше сделок — тем выше опыт!</p>
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
  </footer>

  <script src="js/i18n.js?v=42"></script>
  <script src="js/profile-page.js?v=1" defer></script>
  <script src="js/profile-chart.js?v=1" defer></script>
</body>
</html>
