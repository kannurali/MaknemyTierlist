<?php
require_once __DIR__ . '/api/_bootstrap.php';
require_once __DIR__ . '/api/lib/metrika.php';

// Условия рекламы для рекламодателей — /promote. В разделах шапки её нет:
// ведёт сюда только кнопка «Реклама» рядом с Telegram. Адрес и классы без
// «ads»/«ad-»: такие имена режут блокировщики рекламы (см. README, promo).
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

<title>Реклама на maknemy.com и в Telegram | Maknemy</title>
<meta name="description" content="Реклама среди игроков Blox Fruits на maknemy.com и в Telegram: баннеры, карточки конкурсов, участники конкурса, оплата за переходы. От $2." />
<link rel="canonical" href="https://maknemy.com/promote" />

<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="icon" type="image/png" href="/assets/favicon.png?v=2" sizes="256x256" />
<link rel="apple-touch-icon" href="/apple-touch-icon.png" />

<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/base.css?v=15" /><?php else: ?><link rel="stylesheet" href="css/base.css?v=15" /><?php endif; ?>

<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/topbar.css?v=27" /><?php else: ?><link rel="stylesheet" href="css/topbar.css?v=27" /><?php endif; ?>

<script src="js/auth.js?v=2" fetchpriority="high"></script>
<script src="js/topbar.js?v=28" defer fetchpriority="high"></script>

<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/design-page.css?v=43" /><?php else: ?><link rel="stylesheet" href="css/design-page.css?v=43" /><?php endif; ?>
<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/promote.css?v=1" /><?php else: ?><link rel="stylesheet" href="css/promote.css?v=1" /><?php endif; ?>
<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/halloween.css?v=4" /><?php endif; ?>

<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/giveaway.css?v=3" /><?php else: ?><link rel="stylesheet" href="css/giveaway.css?v=3" /><?php endif; ?>
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
          <a class="mk-pill" href="/stock">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M2.4 7.6V16.1C2.4 17.15 3.25 18.05 4.3 18.05H14.7C15.75 18.05 16.6 17.15 16.6 16.1V7.6M7.15 18.05V13.3C7.15 12.25 8 11.4 9.05 11.4H9.95C11 11.4 11.85 12.25 11.85 13.3V18.05M0.95 5.7L2.6 1.9C2.85 1.3 3.45 0.95 4.1 0.95H14.9C15.55 0.95 16.15 1.3 16.4 1.9L18.05 5.7C18.05 7 16.95 8.1 15.65 8.1C14.3 8.1 13.25 7 13.25 5.7C13.25 7 12.15 8.1 10.85 8.1H8.15C6.85 8.1 5.75 7 5.75 5.7C5.75 7 4.7 8.1 3.35 8.1C2.05 8.1 0.95 7 0.95 5.7Z" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="mk-pill-text" data-i18n="nav.stock">Сток</span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="/news">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M18.05 9.50002C18.05 14.2221 14.222 18.05 9.49995 18.05M18.05 9.50002C18.05 4.77798 14.222 0.950013 9.49995 0.950013M18.05 9.50002C18.05 7.92601 14.222 6.65002 9.49995 6.65002C4.77792 6.65002 0.949949 7.92601 0.949949 9.50002M18.05 9.50002C18.05 11.074 14.222 12.35 9.49995 12.35C4.77792 12.35 0.949949 11.074 0.949949 9.50002M9.49995 18.05C4.77792 18.05 0.949949 14.2221 0.949949 9.50002M9.49995 18.05C11.074 18.05 12.35 14.2221 12.35 9.50002C12.35 4.77798 11.074 0.950013 9.49995 0.950013M9.49995 18.05C7.92594 18.05 6.64995 14.2221 6.64995 9.50002C6.64995 4.77798 7.92594 0.950013 9.49995 0.950013M0.949949 9.50002C0.949949 4.77798 4.77792 0.950013 9.49995 0.950013" stroke="currentColor" stroke-width="1.9"/></svg>
            <span class="mk-pill-text" data-i18n="nav.news">Новости</span>
          </a>
        </li>
      </ul>

      <a class="mk-promote" href="/promote" aria-current="page" data-i18n="nav.promote">Реклама</a>

      <a class="mk-tg" href="https://t.me/theMaknemy" target="_blank" rel="noopener" aria-label="Telegram" title="Telegram">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill-rule="evenodd" fill="currentColor" d="M21.4 3.7 2.9 10.9c-.9.4-.9 1.5 0 1.8l4.5 1.4 1.7 5.4c.2.6 1 .8 1.5.3l2.5-2.4 4.5 3.3c.6.4 1.4.1 1.6-.6l3.2-15.4c.2-.8-.4-1.3-1-1Zm-4.2 3.9-7.9 7 .5 3 .6-2.4 6.8-7.6Z"/></svg>
      </a>

      <a class="mk-chat" href="/chat" data-i18n-label="nav.chat" aria-label="Чат">
        <svg viewBox="0 0 25 25" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M12.0833 0C5.40989 0 0 5.40989 0 12.0833C0 14.2768 0.585445 16.3362 1.60861 18.1109C1.817 18.4723 1.85274 18.9124 1.67689 19.2907L0.645317 21.5102C0.0119158 22.7086 0.878898 24.1667 2.24942 24.1667H12.0833C18.7568 24.1667 24.1667 18.7568 24.1667 12.0833C24.1667 5.40989 18.7568 0 12.0833 0ZM8.45833 8.45833C7.79099 8.45833 7.25 8.99932 7.25 9.66667C7.25 10.334 7.79099 10.875 8.45833 10.875H10.875C11.5423 10.875 12.0833 10.334 12.0833 9.66667C12.0833 8.99932 11.5423 8.45833 10.875 8.45833H8.45833ZM8.45833 13.2917C7.79099 13.2917 7.25 13.8327 7.25 14.5C7.25 15.1673 7.79099 15.7083 8.45833 15.7083H15.7083C16.3757 15.7083 16.9167 15.1673 16.9167 14.5C16.9167 13.8327 16.3757 13.2917 15.7083 13.2917H8.45833Z" fill="currentColor"/></svg>
      </a>

      <button class="mk-avatar" type="button" aria-label="Профиль" data-soon data-i18n-title="topbar.soon" title="В активной разработке">
        <svg viewBox="0 0 34 34" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M17.0003 2.83325C13.0883 2.83325 9.91699 6.00457 9.91699 9.91659C9.91699 13.8286 13.0883 16.9999 17.0003 16.9999C20.9123 16.9999 24.0837 13.8286 24.0837 9.91659C24.0837 6.00457 20.9123 2.83325 17.0003 2.83325Z" fill="currentColor"/><path fill-rule="evenodd" clip-rule="evenodd" d="M12.7503 18.4167C10.3947 18.4167 8.12945 19.4913 6.80192 21.109C6.12816 21.9301 5.65451 22.946 5.61326 24.072C5.57114 25.2218 5.98621 26.3442 6.8422 27.3234C8.92833 29.7099 12.2591 31.1667 17.0003 31.1667C21.7415 31.1667 25.0723 29.7099 27.1584 27.3234C28.0144 26.3442 28.4294 25.2218 28.3873 24.072C28.3461 22.946 27.8724 21.9301 27.1987 21.109C25.8711 19.4913 23.6058 18.4167 21.2503 18.4167H12.7503Z" fill="currentColor"/></svg>
      </button>
    </nav>

    <button class="mk-top-toggle" type="button" id="mkTopToggle"
            aria-expanded="false" aria-controls="mkTopBar"
            data-i18n-label="topbar.showNav" aria-label="Показать разделы"
            data-i18n-title="topbar.showNav" title="Показать разделы">
    </button>
  </header>
  <main class="pm-page">
    <div class="pm-wrap">
      <section class="pm-hero" aria-labelledby="pmTitle">
        <img class="pm-hero-bloom" src="<?= HALLOWEEN ? 'assets/halloween/branch-r.webp' : 'assets/design/home/sakura.webp' ?>" width="<?= HALLOWEEN ? 995 : 868 ?>" height="<?= HALLOWEEN ? 936 : 890 ?>" alt="" aria-hidden="true" loading="lazy" decoding="async" />
<?php if (HALLOWEEN): ?>
        <img class="pm-hero-bat" src="assets/halloween/bat.webp" width="650" height="232" alt="" aria-hidden="true" decoding="async" />
<?php endif; ?>
        <div class="pm-hero-copy">
          <h1 class="pm-title" id="pmTitle">Реклама на maknemy.com и в Telegram</h1>
          <p class="pm-lead">Продвигайте конкурс, канал или проект среди игроков Blox Fruits. У Maknemy можно заказать размещение от одних суток, рекламу на неделю или месяц, оплату за переходы и привлечение участников конкурса.</p>
          <p class="pm-budget">Небольшой бюджет — тоже подходит: рекламная вставка или карточка конкурса — от <b class="pm-fig">$2</b>, продвижение конкурса с оплатой за результат — <b class="pm-fig">$12</b> за 1&nbsp;000 участников.</p>
          <p class="pm-reach">На maknemy.com — около 30&nbsp;000 просмотров страниц в сутки суммарно по сайту. Тирлист — самая посещаемая страница. Посещаемость меняется; просмотры страниц не равны числу уникальных посетителей или показов конкретного объявления.</p>
          <div class="pm-hero-cta">
            <a class="pm-btn" href="https://t.me/mksvtn" target="_blank" rel="noopener">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path fill-rule="evenodd" fill="currentColor" d="M21.4 3.7 2.9 10.9c-.9.4-.9 1.5 0 1.8l4.5 1.4 1.7 5.4c.2.6 1 .8 1.5.3l2.5-2.4 4.5 3.3c.6.4 1.4.1 1.6-.6l3.2-15.4c.2-.8-.4-1.3-1-1Zm-4.2 3.9-7.9 7 .5 3 .6-2.4 6.8-7.6Z"/></svg>
              <span>Заказать рекламу</span>
            </a>
            <p class="pm-talk">Обсудить размещение: telegram <a class="pm-at" href="https://t.me/maknemy" target="_blank" rel="noopener">@maknemy</a>.</p>
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

      <nav class="pm-toc" aria-label="Разделы страницы">
        <ul>
          <li><a href="#pm-results">Участники конкурса</a></li>
          <li><a href="#pm-card">Карточка конкурса</a></li>
          <li><a href="#pm-display">Баннеры</a></li>
          <li><a href="#pm-bundle">Несколько страниц</a></li>
          <li><a href="#pm-telegram">Telegram</a></li>
          <li><a href="#pm-combo">Сайт + Telegram</a></li>
          <li><a href="#pm-clicks">Оплата за переходы</a></li>
          <li><a href="#pm-make">Создание рекламы</a></li>
          <li><a href="#pm-trial">Начните с суток</a></li>
          <li><a href="#pm-projects">Проекты</a></li>
          <li><a href="#pm-rules">Правила</a></li>
          <li><a href="#pm-order">Заказать</a></li>
        </ul>
      </nav>

      <section class="pm-sec is-key has-art" id="pm-results" aria-labelledby="pm-results-h">
        <img class="pm-art" src="<?= hw_img('assets/design/home/card-giveaways.webp') ?>" width="482" height="482" alt="" aria-hidden="true" loading="lazy" decoding="async" />
        <h2 class="pm-h2" id="pm-results-h">Участники конкурса&nbsp;— $12 за 1&nbsp;000</h2>
        <div class="pm-split">
          <p class="pm-main pm-intro">Хотите привлечь аудиторию в свой конкурс? Продвинем его на maknemy.com. Также можно согласовать включение ваших соцсетей в условия партнёрского розыгрыша.</p>
          <div class="pm-side">
            <ul class="pm-rate">
              <li><span class="pm-rate-name"><b>1&nbsp;000</b> подтверждённых участников</span><span class="pm-leader"> — </span><b class="pm-rate-cost">$12</b><span class="pm-vh">.</span></li>
              <li><span class="pm-rate-name"><b>2&nbsp;000</b> подтверждённых участников</span><span class="pm-leader"> — </span><b class="pm-rate-cost">$24</b><span class="pm-vh">.</span></li>
            </ul>
          </div>
          <p class="pm-main">Посетитель сайта переходит на конкурс, выполняет согласованные условия и подтверждает участие. Список обязательных соцсетей и способ проверки определяем до оплаты.</p>
        </div>

        <h3 class="pm-h3">Что входит в гарантированный объём:</h3>
        <ul class="pm-checks">
          <li>Участник пришёл через ссылку нашей кампании.</li>
          <li>Участвует в этом конкурсе впервые и выполнил проверяемые условия.</li>
          <li>Один аккаунт засчитывается один раз в заказе. Повторные регистрации и выявленные искусственные участия исключаются.</li>
          <li>Выполнение обязательных условий проверяется при подведении итогов.</li>
        </ul>

        <div class="pm-notes">
          <p>Это участники конкурса: их количество может отличаться от числа новых подписчиков в каждой соцсети, поскольку часть людей уже может быть на вас подписана. Условия, связанные с другими площадками, включаем в гарантию только при возможности корректной проверки и соответствии правилам этих площадок.</p>
          <p>Срок набора, способ учёта, организатора и ответственного за выдачу призов согласуем заранее. $12 — стоимость продвижения; расходы на призы согласуются отдельно.</p>
          <p>Итоги проходят в объявленную дату. При недоборе к окончанию согласованного срока возвращаем оплату за невыполненный объём пропорционально недостающему количеству участников. По отдельной договорённости остаток можно перенести на следующую кампанию. Сохранение подписки и дальнейшая активность после конкурса в гарантию не входят.</p>
        </div>
      </section>

      <section class="pm-sec" id="pm-card" aria-labelledby="pm-card-h">
        <h2 class="pm-h2" id="pm-card-h">Карточка конкурса на сайте</h2>
        <div class="pm-split">
          <p class="pm-main pm-intro">Карточка с изображением приза, краткими условиями, сроком и кнопкой перехода на конкурс. Отображается на компьютерах и телефонах в блоке партнёрских конкурсов.</p>
          <div class="pm-side">
            <div class="pm-tbl">
              <table class="pm-table">
                <thead>
                  <tr><th scope="col">Страница</th><th scope="col" class="pm-num">24 часа</th></tr>
                </thead>
                <tbody>
                  <tr><th scope="row"><span class="pm-row-name"><span class="pm-thumb" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-news.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>Новости</span></th><td class="pm-cost">$2</td></tr>
                  <tr><th scope="row"><span class="pm-row-name"><span class="pm-thumb" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-prices.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>Калькулятор</span></th><td class="pm-cost">$3</td></tr>
                  <tr><th scope="row"><span class="pm-row-name"><span class="pm-thumb" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-fruits.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>Трейды</span></th><td class="pm-cost">$4</td></tr>
                  <tr><th scope="row"><span class="pm-row-name"><span class="pm-thumb" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-tier.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>Тирлист</span></th><td class="pm-cost">$5</td></tr>
                  <tr class="is-all"><th scope="row"><span class="pm-row-name">Все четыре страницы</span></th><td class="pm-cost">$11</td></tr>
                </tbody>
              </table>
            </div>
            <p class="pm-aside">На тирлисте также доступно размещение на 30 дней за $70 — около $2,33 в день. Недельные и остальные длительные размещения рассчитываются отдельно.</p>
          </div>
          <p class="pm-main">В одном блоке — не более четырёх рекламодателей. Каждому резервируется не менее 25% показов этого блока. Это доля показов блока, а не доля всей аудитории сайта.</p>
          <p class="pm-main">Для запуска достаточно ссылки, изображения приза и условий. Оформление карточки по нашему шаблону входит в стоимость.</p>
          <p class="pm-main pm-quiet">Карточка продаётся на срок: количество переходов и участников в её стоимость не гарантируется. Для заказа определённого количества участников выберите предложение <a href="#pm-results">«Участники конкурса — $12 за 1&nbsp;000»</a>.</p>
        </div>
      </section>

      <section class="pm-sec" id="pm-display" aria-labelledby="pm-display-h">
        <h2 class="pm-h2" id="pm-display-h">Баннерная реклама на сайте</h2>
        <div class="pm-split">
          <p class="pm-main pm-intro">Размещайте рекламу на тирлисте, в калькуляторе, новостях или на странице трейдов. Можно заказать отдельный баннер, пакет на выбранной странице или охватить все четыре страницы.</p>
          <ul class="pm-main pm-terms">
            <li><strong>Основной пакет</strong> — все доступные обычные рекламные места выбранной страницы без всплывающего окна.</li>
            <li><strong>Полный пакет</strong> — основной пакет и всплывающее окно.</li>
          </ul>
          <p class="pm-main pm-quiet">Размещение доступно от одних суток, на 7 дней, 30 дней и дольше. Для недельных и месячных заказов действует более выгодная стоимость за день; расчёт предоставляем перед бронированием.</p>
          <div class="pm-side is-wide">
            <div class="pm-tbl">
              <table class="pm-table is-three">
                <caption>Цены за 24 часа</caption>
                <thead>
                  <tr><th scope="col">Страница</th><th scope="col" class="pm-num">Основной пакет</th><th scope="col" class="pm-num">Полный пакет со всплывающим окном</th></tr>
                </thead>
                <tbody>
                  <tr><th scope="row"><span class="pm-row-name"><span class="pm-thumb" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-news.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>Новости</span></th><td class="pm-cost">$7</td><td class="pm-cost">$10</td></tr>
                  <tr><th scope="row"><span class="pm-row-name"><span class="pm-thumb" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-prices.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>Калькулятор</span></th><td class="pm-cost">$15</td><td class="pm-cost">$20</td></tr>
                  <tr><th scope="row"><span class="pm-row-name"><span class="pm-thumb" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-tier.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>Тирлист</span></th><td class="pm-cost">$20</td><td class="pm-cost">$25</td></tr>
                  <tr><th scope="row"><span class="pm-row-name"><span class="pm-thumb" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-fruits.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>Трейды</span></th><td class="pm-cost">$30</td><td class="pm-cost">$40</td></tr>
                  <tr class="is-all"><th scope="row"><span class="pm-row-name">Все четыре страницы</span></th><td class="pm-cost">$60</td><td class="pm-cost">$75</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <h3 class="pm-h3">Какие рекламные места можно заказать</h3>
        <ul class="pm-formats">
          <li class="pm-fmt is-rail">
            <span class="pm-fmt-media" aria-hidden="true">
              <img src="assets/promo/placeholder-rail.webp" width="320" height="1200" alt="" loading="lazy" decoding="async" />
              <img src="assets/promo/placeholder-rail.webp" width="320" height="1200" alt="" loading="lazy" decoding="async" />
            </span>
            <span class="pm-fmt-text">Боковые баннеры на компьютерах — один или два, формат 300&nbsp;×&nbsp;1200.</span>
          </li>
          <li class="pm-fmt is-strip">
            <span class="pm-fmt-media" aria-hidden="true"><img src="assets/promo/placeholder-strip.webp" width="1200" height="300" alt="" loading="lazy" decoding="async" /></span>
            <span class="pm-fmt-text">Центральный баннер на тирлисте.</span>
          </li>
          <li class="pm-fmt is-dock">
            <span class="pm-fmt-media" aria-hidden="true"><img src="assets/promo/placeholder-dock.webp" width="640" height="200" alt="" loading="lazy" decoding="async" /></span>
            <span class="pm-fmt-text">Нижний мобильный баннер — Mobile Sticky.</span>
          </li>
          <li class="pm-fmt is-pop">
            <span class="pm-fmt-media" aria-hidden="true"><img src="assets/promo/house-tg-popup.webp" width="800" height="800" alt="" loading="lazy" decoding="async" /></span>
            <span class="pm-fmt-text">Всплывающее окно — 800&nbsp;×&nbsp;800.</span>
          </li>
          <li class="pm-fmt is-page">
            <span class="pm-thumb is-big" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-news.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
            <span class="pm-fmt-text">Баннер между публикациями в новостях.</span>
          </li>
          <li class="pm-fmt is-page">
            <span class="pm-thumb is-big" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-fruits.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
            <span class="pm-fmt-text">Баннер между объявлениями трейдов.</span>
          </li>
          <li class="pm-fmt is-page">
            <span class="pm-thumb is-big" aria-hidden="true"><img src="<?= hw_img('assets/design/home/card-tier.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
            <span class="pm-fmt-text">Рекламная ссылка внизу тирлиста.</span>
          </li>
        </ul>

        <div class="pm-notes is-three">
          <p>Отдельные места доступны по индивидуальному расчёту. Состав пакета, расположение и условия показа подтверждаем до оплаты. В зависимости от страницы пакет включает доступные места на компьютерах и телефонах.</p>
          <p class="pm-boost">
            <img src="assets/design/legend/trend-up.svg" width="28" height="33" alt="" aria-hidden="true" loading="lazy" decoding="async" />
            <span>Поднять баннер в верхнюю часть ленты новостей: <b>+$3</b> за сутки / <b>+$15</b> за 7 дней / <b>+$30</b> за 30 дней.</span>
          </p>
          <p>Блок партнёрских конкурсов оплачивается отдельно. Основной и полный пакеты сами по себе не дают эксклюзивность страницы. Полный выкуп рекламных мест страницы, включая условия размещения конкурентов и партнёрских конкурсов, обсуждается отдельно.</p>
        </div>
      </section>

      <section class="pm-sec" id="pm-bundle" aria-labelledby="pm-bundle-h">
        <h2 class="pm-h2" id="pm-bundle-h">Несколько страниц и весь сайт</h2>
        <p class="pm-intro">Выберите страницы и тип пакета на каждой из них:</p>
        <ul class="pm-tiers">
          <li>
            <span class="pm-deck" aria-hidden="true">
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-tier.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-prices.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
            </span>
            <p><strong>Две страницы</strong><span class="pm-vh"> — </span><span class="pm-tier-text"><b>скидка 10%</b> от суммы выбранных баннерных пакетов.</span></p>
          </li>
          <li>
            <span class="pm-deck" aria-hidden="true">
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-tier.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-prices.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-news.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
            </span>
            <p><strong>Три страницы</strong><span class="pm-vh"> — </span><span class="pm-tier-text"><b>скидка 15%</b>.</span></p>
          </li>
          <li class="is-all">
            <span class="pm-deck" aria-hidden="true">
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-tier.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-prices.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-news.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
              <span class="pm-thumb"><img src="<?= hw_img('assets/design/home/card-fruits.webp') ?>" width="482" height="482" alt="" loading="lazy" decoding="async" /></span>
            </span>
            <p><strong>Все четыре страницы</strong><span class="pm-vh"> — </span><span class="pm-tier-text">готовый пакет: <b>$60</b> за сутки без всплывающих окон или <b>$75</b> со всплывающими окнами.</span></p>
          </li>
        </ul>
        <div class="pm-notes is-two">
          <p>Можно сочетать основной и полный пакеты на разных страницах. На неделю, месяц и более длительный срок подготовим отдельное предложение.</p>
          <p>Скидки на несколько страниц относятся к баннерным пакетам и не суммируются с готовыми комбо. Карточки конкурсов, оплата за переходы и участники рассчитываются по своим условиям.</p>
        </div>
      </section>

      <section class="pm-sec" id="pm-telegram" aria-labelledby="pm-telegram-h">
        <h2 class="pm-h2" id="pm-telegram-h">Реклама в Telegram&nbsp;— <a class="pm-at" href="https://t.me/theMaknemy" target="_blank" rel="noopener">@theMaknemy</a></h2>
        <div class="pm-tg">
          <div class="pm-tg-main">
            <div class="pm-tbl">
              <table class="pm-table">
                <thead>
                  <tr><th scope="col">Формат</th><th scope="col" class="pm-num">Стоимость</th></tr>
                </thead>
                <tbody>
                  <tr><th scope="row">Репост конкурса на 24 часа</th><td class="pm-cost">$18</td></tr>
                  <tr><th scope="row">Рекламный пост на 24 часа</th><td class="pm-cost">$13</td></tr>
                  <tr><th scope="row">Напоминание</th><td class="pm-cost">$3</td></tr>
                  <tr><th scope="row">Рекламная вставка внизу обычного поста</th><td class="pm-cost">$2</td></tr>
                </tbody>
              </table>
            </div>
            <p class="pm-aside">В рекламный пост и репост конкурса включены закреп и первый час без новых публикаций. Напоминание и вставка — отдельные форматы; их расписание согласуется заранее.</p>
            <div class="pm-deal">
              <p class="pm-deal-title">Контракт на 30 дней&nbsp;— $60:</p>
              <ul class="pm-checks is-one">
                <li>4 рекламных поста.</li>
                <li>8 рекламных вставок.</li>
                <li>Заранее согласованное расписание.</li>
              </ul>
              <p class="pm-deal-extra">Заменить рекламный пост на репост конкурса — <b>+$5</b> за каждую замену.</p>
            </div>
          </div>
          <figure class="pm-tg-phone">
            <img src="assets/design/home/iphone.webp" width="1629" height="1629" alt="Рекламный пост «Ваша реклама» в Telegram-канале Maknemy на экране телефона" loading="lazy" decoding="async" />
          </figure>
        </div>
      </section>

      <section class="pm-sec" id="pm-combo" aria-labelledby="pm-combo-h">
        <h2 class="pm-h2" id="pm-combo-h">Конкурс: сайт + Telegram</h2>
        <div class="pm-tbl is-flat">
          <table class="pm-table pm-combo">
            <thead>
              <tr><th scope="col">Пакет</th><th scope="col">Что входит</th><th scope="col" class="pm-num">Цена</th></tr>
            </thead>
            <tbody>
              <tr><th scope="row">Проба</th><td class="pm-what">Карточка на тирлисте на сутки + один репост конкурса в Telegram</td><td class="pm-cost">$20</td></tr>
              <tr><th scope="row">Неделя</th><td class="pm-what">Карточка на тирлисте на 7 дней + один репост + одно напоминание</td><td class="pm-cost">$40</td></tr>
              <tr><th scope="row">Месяц</th><td class="pm-what">Карточка на тирлисте на 30 дней + четыре репоста + четыре напоминания</td><td class="pm-cost">$125</td></tr>
            </tbody>
          </table>
        </div>
        <div class="pm-notes is-two">
          <p>Расписание публикаций согласуется с датами конкурса или серии конкурсов. Эти пакеты оплачиваются за размещение; гарантированное количество участников заказывается отдельно.</p>
          <p class="pm-plus">К любому баннерному размещению можно добавить Telegram-пост за <b>$13</b> или репост конкурса за <b>$18</b>. Закреп уже включён.</p>
        </div>
      </section>

      <section class="pm-sec has-art" id="pm-clicks" aria-labelledby="pm-clicks-h">
        <img class="pm-art" src="<?= hw_img('assets/design/home/card-prices.webp') ?>" width="482" height="482" alt="" aria-hidden="true" loading="lazy" decoding="async" />
        <h2 class="pm-h2" id="pm-clicks-h">Оплата за переходы</h2>
        <div class="pm-split">
          <p class="pm-main pm-intro">Если важен объём переходов на канал, конкурс, сайт или другой проект, можно заказать кампанию с оплатой за фактически учтённые клики.</p>
          <div class="pm-side">
            <p class="pm-per"><b>1,8₽</b> за подтверждённый переход.</p>
            <div class="pm-tbl">
              <table class="pm-table">
                <thead>
                  <tr><th scope="col">Объём</th><th scope="col" class="pm-num">Бюджет</th></tr>
                </thead>
                <tbody>
                  <tr><th scope="row">500 переходов</th><td class="pm-cost">900₽</td></tr>
                  <tr><th scope="row">1&nbsp;000 переходов</th><td class="pm-cost">1&nbsp;800₽</td></tr>
                </tbody>
              </table>
            </div>
          </div>
          <p class="pm-main">До запуска согласуем ссылку, источник статистики, срок и бюджет. Повторные и выявленные подозрительные клики исключаются из оплачиваемого объёма.</p>
          <p class="pm-main">Если к завершению кампании бюджет использован не полностью, остаток возвращается или размещение продлевается по договорённости, если предложение ещё действует.</p>
          <p class="pm-main pm-quiet">Этот формат гарантирует согласованный объём учтённых переходов либо возврат неиспользованного бюджета. Вступление в канал, участие в конкурсе, покупки и сохранение подписки в стоимость клика не входят.</p>
        </div>
      </section>

      <section class="pm-sec" id="pm-make" aria-labelledby="pm-make-h">
        <h2 class="pm-h2" id="pm-make-h">Создание и адаптация рекламы</h2>
        <div class="pm-make">
          <div class="pm-make-copy">
            <p class="pm-intro">Поможем подготовить простые баннеры и адаптировать материалы под рекламные места сайта. Можно использовать готовый макет или заказать оформление по согласованному заданию.</p>
            <p class="pm-quiet">Карточка конкурса по нашему шаблону включена в стоимость её размещения. Создание других баннеров, адаптация размеров и дополнительные правки рассчитываются до начала работы.</p>
          </div>
          <div class="pm-make-pair" aria-hidden="true">
            <img class="is-from" src="assets/promo/placeholder-dock.webp" width="640" height="200" alt="" loading="lazy" decoding="async" />
            <span class="pm-make-arrow"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <img class="is-to" src="assets/promo/channel-dock.webp" width="640" height="200" alt="" loading="lazy" decoding="async" />
          </div>
        </div>
      </section>

      <section class="pm-sec is-trial" id="pm-trial" aria-labelledby="pm-trial-h">
        <h2 class="pm-h2" id="pm-trial-h">Начните с суток</h2>
        <div class="pm-trial-body">
          <p class="pm-intro">Стоимость тестовых суток засчитывается в недельное или месячное размещение при продлении до окончания теста и наличии свободных дат. Общий срок считается с первоначального запуска.</p>
          <p class="pm-quiet">Для размещений на срок количество переходов, подписок, участников и продаж не гарантируется. UTM-разметка, согласованное отслеживание переходов и отчёт по кампании входят в стоимость.</p>
        </div>
      </section>

      <section class="pm-sec has-logo" id="pm-projects" aria-labelledby="pm-projects-h">
        <img class="pm-bf" src="assets/poster/logo-bf.png" width="158" height="85" alt="Blox Fruits" loading="lazy" decoding="async" />
        <h2 class="pm-h2" id="pm-projects-h">Какие проекты мы принимаем</h2>
        <p class="pm-intro">В первую очередь рассматриваем:</p>
        <ul class="pm-checks">
          <li>Блогеров, Telegram-каналы, YouTube-каналы и сообщества по Blox Fruits и Roblox.</li>
          <li>Честные конкурсы с понятными условиями и подтверждёнными призами.</li>
          <li>Игры, полезные сайты, гайды и сервисы для игроков.</li>
          <li>Дизайнеров, монтажёров, разработчиков и другие подходящие аудитории проекты.</li>
        </ul>
        <p class="pm-after">При выборе формата учитываем вашу цель, бюджет, сроки и тематику проекта. Для небольших каналов доступны вставки и карточки конкурсов; для регулярного продвижения — недельные и месячные размещения.</p>
      </section>

      <section class="pm-sec is-rules" id="pm-rules" aria-labelledby="pm-rules-h">
        <h2 class="pm-h2" id="pm-rules-h">Правила размещения</h2>
        <ul class="pm-rules">
          <li><strong>Согласование до оплаты.</strong> Нужны ссылка на проект, описание предложения, материалы и желаемые даты. Фиксируем формат, стоимость, срок, условия учёта и расписание. Бронь подтверждается после согласования и оплаты.</li>
          <li><strong>Достоверная реклама.</strong> Объявление и страница перехода должны соответствовать друг другу. Существенную замену ссылки, условий или содержания после согласования нужно согласовать повторно. Рекламные материалы обозначаются как реклама или партнёрское размещение.</li>
          <li><strong>Подходящее содержание.</strong> Не размещаем мошеннические предложения, фишинг, накрутку, читы и эксплойты, продажу аккаунтов, неофициальную продажу игровых предметов и валюты за реальные деньги, азартные игры и контент 18+. Размещение проходит предварительное рассмотрение.</li>
          <li><strong>Честные конкурсы.</strong> В условиях должны быть указаны реальные призы, обязательные действия, дата итогов, способ выбора победителей и порядок получения наград. Ответственного за призы определяем до запуска. Итоги и выдачу призов подтверждает организатор.</li>
          <li><strong>Понятные гарантии.</strong> Размещение на срок, переходы и подтверждённые участники — разные услуги. Гарантия действует только для показателя, прямо указанного в заказе. Окупаемость, продажи и дальнейшая активность аудитории не обещаются.</li>
          <li><strong>Недобор и возврат.</strong> Для участников возвращается стоимость недостающего объёма; для кликов — неиспользованный бюджет. Перенос остатка на другую кампанию возможен по договорённости. Условия отмены или переноса размещения на срок фиксируем перед оплатой.</li>
          <li><strong>Технические перерывы.</strong> Если размещение недоступно по нашей вине, компенсируем время продлением или возвратом за недоставленный срок по договорённости.</li>
          <li><strong>Условия показа и эксклюзивность.</strong> Страницы, рекламные места, ротация и ограничения для конкурентов фиксируются при бронировании. Эксклюзивность действует только при отдельном согласовании.</li>
        </ul>
      </section>

      <section class="pm-order" id="pm-order" aria-labelledby="pm-order-h">
        <div class="pm-order-copy">
          <h2 class="pm-h2" id="pm-order-h">Заказать рекламу</h2>
          <p>Напишите <a class="pm-at" href="https://t.me/mksvtn" target="_blank" rel="noopener">@mksvtn</a> и отправьте ссылку на проект, цель рекламы, бюджет и желаемые даты. Подберём формат и подтвердим свободные места.</p>
          <p class="pm-order-all">Все рекламные предложения: <a class="pm-at" href="https://t.me/mksvtn_ads" target="_blank" rel="noopener">@mksvtn_ads</a>.</p>
        </div>
        <a class="pm-btn is-light" href="https://t.me/mksvtn" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path fill-rule="evenodd" fill="currentColor" d="M21.4 3.7 2.9 10.9c-.9.4-.9 1.5 0 1.8l4.5 1.4 1.7 5.4c.2.6 1 .8 1.5.3l2.5-2.4 4.5 3.3c.6.4 1.4.1 1.6-.6l3.2-15.4c.2-.8-.4-1.3-1-1Zm-4.2 3.9-7.9 7 .5 3 .6-2.4 6.8-7.6Z"/></svg>
          <span>@mksvtn</span>
        </a>
      </section>
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
  <script src="js/i18n.js?v=75" fetchpriority="high"></script>
  <script src="js/news-blocks.js?v=3" fetchpriority="high"></script>
  <script src="js/giveaway.js?v=5" fetchpriority="high"></script>
  <script src="js/giveaway-pop.js?v=1" fetchpriority="high"></script>
</body>
</html>
