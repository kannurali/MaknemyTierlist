<?php
require_once __DIR__ . '/api/_bootstrap.php';
require_once __DIR__ . '/api/lib/og.php';

// Страница в остальном статична: ни списков, ни текста, собираемого из
// базы, здесь нет. Исключение — og:image: корень сайта самый шаримый адрес
// (в Telegram и Discord пересылают именно его, а не /tierlist), и он не
// имеет права рекламировать замороженный баннер, пока тирлист под /tierlist
// показывает живые данные. Картинка (только картинка — og:title,
// og:description и canonical ниже остаются про сайт целиком, так и было
// задумано) берётся из того же источника, что и og:image у index.php:
// og_tierlist_image() в api/lib/og.php строит её по summary, а summary —
// тот же og_tierlist_summary() над той же строкой tierlist, что читает
// index.php. Второй копии этой логики нет нарочно — расхождение между
// / и /tierlist иначе замечалось бы только когда кто-то уже прислал ссылку
// с чужой картинкой.
//
// Как и у index.php: любая ошибка (нет БД, кривой JSON, тиры ещё пустые)
// откатывает картинку на статичный баннер — см. og_tierlist_image(null) /
// og_tierlist_summary() в api/lib/og.php. Главная обязана открываться в
// любом случае, битое превью — не повод для 500.
$ogImage = og_tierlist_image(null);

// Cache-Control такой же, как у index.php и news.php: файл несёт номера
// версий ?v= для css/js, и закешированная копия намертво прибила бы
// посетителя к старому коду.
header('Cache-Control: no-cache, must-revalidate');
if (!defined('TESTING') && !defined('NX_ADMIN_RENDER')) {
    try {
        $pdo = db();
        $row = $pdo->query('SELECT data, rev FROM tierlist WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $summary = og_tierlist_summary($row['data'] ?? null, $row['rev'] ?? null);
        $ogImage = og_tierlist_image($summary);
    } catch (Throwable $e) {
        error_log('home.php: og image fallback: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="color-scheme" content="dark" />

<!-- Пути внутри страницы документ-относительные. Сама она отдаётся с "/",
     где база и так корень, но <base> оставлен явно — тот же приём, что в
     news.php: если адрес когда-нибудь уедет на глубину, пути не поедут. -->
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

<link rel="stylesheet" href="css/base.css?v=5" />
<!-- Шапка редизайна: отсюда же приезжает @font-face для Oswald, которым
     набрана вся страница. -->
<link rel="stylesheet" href="css/topbar.css?v=7" />
<!-- Поведение шапки: компактный режим при прокрутке и плашка
     «В активной разработке» на разделах, которых ещё нет.
     defer — код лезет в DOM сразу, без ожидания события. -->
<script src="js/topbar.js?v=3" defer></script>
<!-- Фон страницы и подвал из редизайна — те же, что на тирлисте. -->
<link rel="stylesheet" href="css/design-page.css?v=26" />
<link rel="stylesheet" href="css/home.css?v=13" />
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
          <a class="mk-pill" href="/" aria-current="page">
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
          <!-- «Трейдинг» и «Калькулятор» с сайта пока сняты, профиля тоже нет.
               Это <button data-soon>, а не мёртвый <span> и не href="#":
               кнопка кликается и по клику показывает «В активной разработке»
               (js/topbar.js). Пилюля, которая молчит в ответ на клик,
               читается как поломка сайта, а якорь-пустышка только дописывает
               "#" в адресную строку. Вернуть раздел — заменить тег на <a>
               с href и убрать data-soon.

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
          <button class="mk-pill" type="button" data-soon data-i18n-title="topbar.soon" title="В активной разработке">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M5.70001 8.55001V13.3M13.3 10.45V13.3M9.5 5.70001V13.3M4.75001 18.05H14.25C16.3487 18.05 18.05 16.3487 18.05 14.25V4.75001C18.05 2.65134 16.3487 0.950022 14.25 0.950022H4.75001C2.65134 0.950022 0.950022 2.65134 0.950022 4.75001V14.25C0.950022 16.3487 2.65134 18.05 4.75001 18.05Z" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
            <span class="mk-pill-text">Калькулятор</span>
          </button>
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

<main class="hm">

  <!-- ================= Лид =================
       Порядок элементов внутри — это порядок слоёв макета снизу вверх:
       сначала плашки и текст, потом карточки, и только сверху весь декор
       (лепестки, мокап, стеклянные фигуры). Менять порядок нельзя, он же
       задаёт перекрытия. -->
  <section class="hm-lead">
    <div class="hm-panel-main"></div>
    <div class="hm-panel-text"></div>

    <h1 class="hm-title">
      <img class="hm-word" src="assets/design/wordmark.svg" alt="MAKNEMY" />
    </h1>

    <div class="hm-ghost hm-ghost-c hm-deco" aria-hidden="true"></div>

    <p class="hm-since hm-ak">Работаем с 2024 года</p>

    <p class="hm-desc">Актуальный тир-лист Blox Fruits от создателя Maknemy. Следите за изменениями меты, ловите самые щедрые розыгрыши и всегда будьте в центре игровых новостей.</p>

    <!-- Бегущая строка. Список продублирован намеренно: прокрутка идёт
         ровно на одну копию, поэтому шов не виден. Копия скрыта от
         скринридеров, чтобы пункты не читались дважды. -->
    <div class="hm-ticker">
      <div class="hm-ticker-track">
        <ul class="hm-ticker-list">
          <li>самые точные цены</li>
          <li>постоянные розыгрыши</li>
          <li>калькулятор цен</li>
          <li>свежие новости</li>
        </ul>
        <ul class="hm-ticker-list" aria-hidden="true">
          <li>самые точные цены</li>
          <li>постоянные розыгрыши</li>
          <li>калькулятор цен</li>
          <li>свежие новости</li>
        </ul>
      </div>
    </div>

    <div class="hm-lead-actions">
      <!-- Раздела фруктов ещё нет. Кнопка сверстана целиком, но это <span>:
           когда страница появится, меняется только тег и href. -->
      <a class="hm-btn hm-btn-accent" href="/tierlist"><span class="hm-btn-label"><span class="hm-btn-word hm-btn-word-rest">фрукты</span></span></a>
      <a class="hm-btn hm-btn-ghost" href="https://t.me/theMaknemy" target="_blank" rel="noopener"><svg class="hm-btn-dash" viewBox="0 0 273 72" preserveAspectRatio="none" aria-hidden="true"><rect x="1.1" y="1.1" width="270.8" height="69.8" fill="none" vector-effect="non-scaling-stroke"/></svg><span class="hm-btn-label"><span class="hm-btn-word hm-btn-word-rest">о нас</span><span class="hm-btn-word hm-btn-word-hover">о нас</span></span></a>
    </div>

    <!-- Ряд карточек. Два уровня вложенности не декоративные: в макете
         контейнер и сами карточки едут в анимации по разным осям. -->
    <div class="hm-cards hm-anim">
      <ul class="hm-cards-row">
        <li>
          <div class="hm-card" aria-disabled="true" data-soon data-i18n-title="topbar.soon" title="В активной разработке">
            <div class="hm-card-body"></div>
            <img class="hm-card-art" src="assets/design/home/card-fruits.webp" alt="" aria-hidden="true" />
            <h2 class="hm-card-name hm-ak">Фрукты</h2>
            <div class="hm-card-rule"></div>
            <p class="hm-card-note">а какой фрукт предложат тебе?</p>
            <span class="hm-card-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </div>
        </li>
        <li>
          <a class="hm-card" href="/tierlist">
            <div class="hm-card-body"></div>
            <img class="hm-card-art" src="assets/design/home/card-tier.webp" alt="" aria-hidden="true" />
            <h2 class="hm-card-name hm-ak">Тир</h2>
            <div class="hm-card-rule"></div>
            <p class="hm-card-note">ваш гид в мире трейдов уже готов!</p>
            <span class="hm-card-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </a>
        </li>
        <li>
          <div class="hm-card" aria-disabled="true" data-soon data-i18n-title="topbar.soon" title="В активной разработке">
            <div class="hm-card-body"></div>
            <img class="hm-card-art" src="assets/design/home/card-prices.webp" alt="" aria-hidden="true" />
            <h2 class="hm-card-name hm-ak">Цены</h2>
            <div class="hm-card-rule"></div>
            <p class="hm-card-note">сравнить цены в реальном времени уже не проблема!</p>
            <span class="hm-card-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </div>
        </li>
        <li>
          <a class="hm-card" href="https://t.me/theMaknemy" target="_blank" rel="noopener">
            <div class="hm-card-body"></div>
            <img class="hm-card-art" src="assets/design/home/card-giveaways.webp" alt="" aria-hidden="true" />
            <h2 class="hm-card-name hm-card-name--tight hm-ak">Розыгрыши</h2>
            <div class="hm-card-rule"></div>
            <p class="hm-card-note">любимые призы только на нашем канале!</p>
            <span class="hm-card-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </a>
        </li>
        <li>
          <a class="hm-card" href="/news">
            <div class="hm-card-body"></div>
            <img class="hm-card-art" src="assets/design/home/card-news.webp" alt="" aria-hidden="true" />
            <h2 class="hm-card-name hm-card-name--tight hm-ak">Новости</h2>
            <div class="hm-card-rule"></div>
            <p class="hm-card-note">узнай самые свежие новинки в твоей любимой игре!</p>
            <span class="hm-card-go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </a>
        </li>
      </ul>
    </div>

    <!-- ===== Декор ===== -->
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

  <!-- ================= Вопросы ================= -->
  <section class="hm-faq">
    <div class="hm-faq-inner">
      <div class="hm-faq-head">
        <h2 class="hm-ak">Немного о важном</h2>
        <p>ваша гарантия успешных трейдов - ваша любознательность!</p>
      </div>

      <!-- Ответы разбиты на абзацы: <p> внутри .hm-faq-a, стили под них
           лежат в home.css. Механика раскрытия работает по
           aria-expanded (js/home.js) и текста не касается. -->
      <ul class="hm-faq-list">
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false">Кто такой Maknemy и как появился этот проект?</button>
          <div class="hm-faq-a">
            <p>Maknemy — мой медийный проект, посвящённый Roblox и Blox Fruits. Я создаю новости, обзоры, разборы обновлений и контент о трейдинге.</p>
            <p>В процессе я заметил, что игрокам часто не хватает одного понятного места, где можно быстро узнать примерную ценность предметов и разобраться в изменениях рынка. Так появился Maknemy Tierlist — проект, который объединяет мою аналитику, сайт и сообщества вокруг трейдинга.</p>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false">Что можно найти в Maknemy Tierlist?</button>
          <div class="hm-faq-a">
            <p>Maknemy Tierlist помогает ориентироваться в экономике Blox Fruits. На сайте собраны оценки фруктов, перманентных фруктов, пассов, оружия, аксессуаров и конфигураций — бывших скинов и мутаций.</p>
            <p>Проект также включает обновления цен, новости рынка и материалы, которые помогают игрокам лучше понимать происходящее в трейдах. Главная площадка проекта — сайт maknemy.com.</p>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false">Как формируются цены на фрукты, пассы и конфигурации?</button>
          <div class="hm-faq-a">
            <p>Цены не должны основываться только на одной цифре или случайном калькуляторе. При оценке учитываются спрос, редкость предмета, его доступность, популярность, изменения после обновлений и то, насколько игроки действительно готовы обменивать его на другие ценности.</p>
            <p>Также важны сообщения и наблюдения самого сообщества. Если предмет стал чаще появляться в трейдах, потерял спрос или, наоборот, начал резко дорожать, это отражается на его актуальной оценке.</p>
            <p>При этом цена в тир-листе — это ориентир по рынку, а не официальная стоимость и не гарантия выгоды.</p>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false">Почему цены меняются и как следить за актуальными значениями?</button>
          <div class="hm-faq-a">
            <p>Экономика Blox Fruits постоянно меняется. После выхода обновлений, реворков, новых фруктов, скинов или механик интерес игроков может резко измениться.</p>
            <p>Предмет, который вчера считался очень востребованным, сегодня может потерять спрос. То же самое работает и наоборот: редкий или популярный предмет может начать цениться значительно выше.</p>
            <p>Поэтому не стоит ориентироваться на старые скриншоты и давно сохранённые списки. Лучше проверять текущую версию тир-листа, дату обновления цен и последние новости проекта.</p>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false">Как использовать тир-лист, чтобы не переплачивать?</button>
          <div class="hm-faq-a">
            <p>Сначала нужно сравнить ценность обеих сторон сделки, но нельзя смотреть только на итоговые цифры. Важно учитывать спрос на каждый предмет и понимать, насколько легко его потом обменять.</p>
            <p>Иногда предмет может иметь высокую оценку, но почти никому не быть нужным. А другой предмет может стоить немного меньше, но пользоваться большим спросом и быстрее продаваться или обмениваться.</p>
            <p>Maknemy Tierlist стоит использовать как отправную точку для анализа сделки. Окончательное решение всегда должно учитывать текущие предложения, спрос и твою собственную цель.</p>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false">Как не попасться на скам при трейде?</button>
          <div class="hm-faq-a">
            <p>Никому нельзя передавать пароль, cookies, коды подтверждения или данные аккаунта ради «проверки предметов». Maknemy Tierlist никогда не требует отправлять такие данные.</p>
            <p>Перед подтверждением сделки нужно внимательно проверить имена игроков, предметы и их количество. Нельзя доверять одним только скриншотам, обещаниям, срочности или сообщениям от якобы администраторов.</p>
            <p>Также нужно осторожно относиться к подозрительным ссылкам, фальшивым сайтам и кросс-трейдам. Тир-лист помогает оценить сделку, но не может гарантировать честность другого игрока.</p>
          </div>
        </li>
        <li class="hm-faq-item">
          <button class="hm-faq-q" type="button" aria-expanded="false">Что дальше ждёт Maknemy Tierlist?</button>
          <div class="hm-faq-a">
            <p>Maknemy Tierlist не должен оставаться просто таблицей с ценами. Сейчас проект развивается дальше: сайт готовится выйти из бета-версии, обновляется дизайн и структура новостей.</p>
            <p>В будущем планируется добавить публичную площадку для поиска трейдов и объективный калькулятор сделок, который будет помогать сравнивать предложения без искусственного завышения или занижения цен.</p>
            <p>При этом даже самый удобный калькулятор не сможет полностью заменить понимание спроса и рынка. Главная цель проекта — дать игрокам полезную основу для решений и постепенно превратить Maknemy Tierlist в полноценную торговую платформу для Roblox.</p>
          </div>
        </li>
      </ul>
    </div>
  </section>
</main>

<!-- ================= Подвал =================
     Тот же .mk-foot, что на тирлисте: стили лежат в design-page.css. -->
<footer class="mk-foot">
  <img class="mk-foot-mark" src="assets/design/logo-mk-square.png" alt="MAKNEMY" />
  <ul class="mk-foot-roles">
    <li><span>автор</span><span class="mk-foot-nick">MKSVTN</span></li>
    <li><span>дизайнер</span><span class="mk-foot-nick">DANIKTOR</span></li>
    <li><span>аналитик</span><span class="mk-foot-nick">GLH</span></li>
    <li><span>помощник аналитика</span><span class="mk-foot-nick">активно ищем</span></li>
    <li><span>разработчик</span><span class="mk-foot-nick">The Fool</span></li>
  </ul>
  <p class="mk-foot-tagline">макнеми тирлист - гарантия успешных трейдов</p>
</footer>

<script src="js/home.js?v=3"></script>
</body>
</html>
