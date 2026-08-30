<?php
require_once __DIR__ . '/api/_bootstrap.php';
require_once __DIR__ . '/api/lib/og.php';

// Превью-карточка ссылки (og:image/title/description) собирается из живого
// тирлиста ДО отдачи <head> — краулеры (Telegram, Discord, VK) не исполняют
// JS и читают только то, что уже есть в HTML. Тело страницы ниже байт-в-байт
// то же, что было в index.html (см. историю git) — сам тирлист как рисовался
// на клиенте js/app.js, так и рисуется.
//
// Никогда не роняет страницу: любая ошибка (нет БД, кривой JSON, тиры ещё
// пустые) откатывает превью на статичный баннер — см. og_tierlist_summary()
// в api/lib/og.php. Тирлист обязан открываться в любом случае, битое превью —
// не повод для 500.
// Картинка (og_tierlist_image()) — общая с home.php, см. api/lib/og.php:
// корень сайта рекламирует ровно ту же живую превьюшку, что и /tierlist.
// Здесь, в отличие от home.php, картинка идёт в комплекте со своими
// title/description — тирлисту нужны оба, а не только картинка.
function tierlist_og_fallback(): array {
    return og_tierlist_image(null) + [
        'title'       => 'Maknemy Tier List — трейд-ценности Blox Fruits',
        'description' => 'Актуальный тирлист трейд-ценностей Blox Fruits: фрукты, перманенты, геймпассы, скины и мутации. Спрос и тренды цен.',
    ];
}

function tierlist_og_data(PDO $pdo): array {
    $row = $pdo->query('SELECT data, rev FROM tierlist WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    $summary = og_tierlist_summary($row['data'] ?? null, $row['rev'] ?? null);
    if ($summary === null) { return tierlist_og_fallback(); }

    $meta = og_tierlist_meta($summary);
    $fallback = tierlist_og_fallback();
    return og_tierlist_image($summary) + [
        'title'       => $meta['title'],
        'description' => $meta['description'] !== '' ? $meta['description'] : $fallback['description'],
    ];
}

$og = tierlist_og_fallback();
if (!defined('TESTING') && !defined('NX_ADMIN_RENDER')) {
    // Та же защита, что .htaccess раньше давал index.html через
    // <FilesMatch "\.html$"> (см. комментарий там же про Safari, часами
    // державший старую страницу после деплоя). FilesMatch не годится для
    // .php — совпадение по одному лишь имени файла зацепило бы заодно
    // api/news.php (лента) и переписало бы её собственный Cache-Control,
    // поэтому заголовок ставится здесь же, в самой странице — так же, как
    // это уже делают остальные PHP-эндпоинты проекта (api/tierlist.php,
    // api/news.php).
    //
    // NX_ADMIN_RENDER — эту же страницу зовёт admin_render_public_page() из
    // /admin, чтобы забрать её вывод (см. admin_page.php). Там уже стоит свой
    // Cache-Control (no-store, из admin_page_headers()), и более мягкое
    // значение отсюда переписало бы его; поход в БД за og:* админке тоже не
    // нужен — эти теги она не показывает. На публичном / этот флаг не
    // выставлен, поведение страницы для посетителей не меняется.
    header('Cache-Control: no-cache, must-revalidate');
    try {
        $og = tierlist_og_data(db());
    } catch (Throwable $e) {
        error_log('index.php: og preview fallback: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<!-- Сайт всегда тёмный. Без этой строчки «Тёмная тема для сайтов» в Яндекс.Браузере
     считает страницу светлой, включает принудительное затемнение и инвертирует
     монохромные картинки — белые логотипы на плашках тиров становились чёрными. -->
<meta name="color-scheme" content="dark" />

<!-- ============ SEO ============ -->
<!-- Заголовок и описание — то, что видно в выдаче Google/Яндекса.
     Название бренда в разных написаниях (латиницей и кириллицей), потому
     что ищут и «Maknemy tier list», и «макнеми тирлист». -->
<title>Maknemy Tier List — трейд-ценности Blox Fruits | Макнеми тирлист</title>
<meta name="description" content="Maknemy Tier List — актуальный тирлист трейд-ценностей Blox Fruits: фрукты, перманенты, геймпассы, скины и мутации. Спрос, тренды роста и падения, обновляется вручную. Макнеми тирлист." />
<link rel="canonical" href="https://maknemytierlist.site/tierlist" />
<meta name="robots" content="index, follow, max-image-preview:large" />

<!-- Превью-карточка при отправке ссылки в Telegram, Discord, ВК —
     og:image/title/description echo живые данные тирлиста, см. tierlist_og_data() выше. -->
<meta property="og:type" content="website" />
<meta property="og:site_name" content="Maknemy Tier List" />
<meta property="og:locale" content="ru_RU" />
<meta property="og:url" content="https://maknemytierlist.site/tierlist" />
<meta property="og:title" content="<?= htmlspecialchars($og['title'], ENT_QUOTES, 'UTF-8') ?>" />
<meta property="og:description" content="<?= htmlspecialchars($og['description'], ENT_QUOTES, 'UTF-8') ?>" />
<meta property="og:image" content="<?= htmlspecialchars($og['image'], ENT_QUOTES, 'UTF-8') ?>" />
<meta property="og:image:width" content="<?= (int)$og['imageWidth'] ?>" />
<meta property="og:image:height" content="<?= (int)$og['imageHeight'] ?>" />
<meta property="og:image:type" content="<?= htmlspecialchars($og['imageType'], ENT_QUOTES, 'UTF-8') ?>" />
<meta property="og:image:alt" content="<?= htmlspecialchars($og['title'], ENT_QUOTES, 'UTF-8') ?>" />
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="<?= htmlspecialchars($og['title'], ENT_QUOTES, 'UTF-8') ?>" />
<meta name="twitter:description" content="<?= htmlspecialchars($og['description'], ENT_QUOTES, 'UTF-8') ?>" />
<meta name="twitter:image" content="<?= htmlspecialchars($og['image'], ENT_QUOTES, 'UTF-8') ?>" />

<!-- Разметка для поисковиков: связывает сайт с брендом Maknemy -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebSite",
  "name": "Maknemy Tier List",
  "alternateName": ["Макнеми тирлист", "Maknemy Tierlist", "Maknemy tier list Blox Fruits"],
  "url": "https://maknemytierlist.site/",
  "inLanguage": "ru",
  "description": "Тирлист трейд-ценностей Blox Fruits от Maknemy: фрукты, перманенты, геймпассы, скины и мутации.",
  "author": {
    "@type": "Person",
    "name": "Maknemy",
    "url": "https://t.me/mksvtnc"
  }
}
</script>

<!-- Иконка сайта. Копия .ico лежит ещё и в корне: краулеры Google и Яндекса
     (а также часть агрегаторов превью) не читают HTML, а просто дёргают
     /favicon.ico — без этого файла в выдаче рисовался серый глобус.
     Пути абсолютные, иначе на любом URL глубже корня они бы поехали.
     sizes="any" убран: он объявляет иконку масштабируемой, а это не SVG. -->
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="icon" type="image/png" href="/assets/favicon.png?v=2" sizes="256x256" />
<link rel="apple-touch-icon" href="/apple-touch-icon.png" />
<link rel="stylesheet" href="css/base.css?v=4" />
<link rel="stylesheet" href="css/styles.css?v=48" />
<!-- Новая шапка из редизайна. Идёт после styles.css: перекрывает старый
     бренд и .nav-seg в тулбаре. -->
<link rel="stylesheet" href="css/topbar.css?v=4" />
<!-- Хром страницы тирлиста по редизайну: фон, панель фильтров, подвал. -->
<link rel="stylesheet" href="css/design-page.css?v=24" />

<!-- Yandex.Metrika counter -->
<script type="text/javascript">
    (function(m,e,t,r,i,k,a){
        m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
        m[i].l=1*new Date();
        for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
        k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)
    })(window, document,'script','https://mc.yandex.ru/metrika/tag.js?id=111127188', 'ym');

    ym(111127188, 'init', {ssr:true, webvisor:true, trackHash:true, clickmap:true, ecommerce:"dataLayer", referrer: document.referrer, url: location.href, accurateTrackBounce:true, trackLinks:true});
</script>
<noscript><div><img src="https://mc.yandex.ru/watch/111127188" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
<!-- /Yandex.Metrika counter -->
</head>
<body>
  <!-- Кнопки входа здесь больше нет: админка живёт на отдельном адресе /admin,
       и роль там решает сервер до отдачи разметки. Тулбар редактирования ниже
       остаётся в файле — эту же разметку отдаёт admin.php, дописав к ней
       window.NX_ADMIN_PAGE. Посетителю все админские группы скрыты. -->

  <!-- ====== Floating like button (правый нижний угол, для всех посетителей) ====== -->
  <button class="like-fab" id="likeBtn" type="button" data-i18n-title="like.title" title="Поставить лайк" aria-pressed="false">
    <span class="like-heart" aria-hidden="true">🤍</span>
    <span class="like-count" id="likeCount">0</span>
  </button>

  <!-- ====== Кнопка доната — открывает окно со ссылками и QR ====== -->
  <button class="donate-fab" id="donateBtn" type="button" data-i18n-title="donate.title" title="Поддержать проект" hidden>
    <span class="donate-heart" aria-hidden="true">💜</span>
    <span class="donate-label" data-i18n="donate.button">Поддержать</span>
  </button>

  <!-- ================= Шапка сайта (редизайн) ================= -->
  <!-- Разделы «Трейдинг» и «Калькулятор» есть в макете, но страниц под них
       ещё нет — обе пилюли и аватар «Профиль» ведут на /soon, честную
       страницу «раздел строится» (см. public_html/soon.php). -->
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
          <a class="mk-pill" href="/tierlist" aria-current="page">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M8.57627 3.7533C8.57627 3.22702 8.14799 2.79425 7.62582 2.85987C6.45486 3.00701 5.32947 3.42467 4.341 4.08515C3.08735 4.9228 2.11026 6.1134 1.53327 7.50637C0.95628 8.89935 0.805314 10.4321 1.09946 11.9109C1.39361 13.3897 2.11965 14.748 3.18579 15.8142C4.25193 16.8803 5.61027 17.6063 7.08904 17.9005C8.56781 18.1946 10.1006 18.0437 11.4936 17.4667C12.8866 16.8897 14.0771 15.9126 14.9148 14.659C15.5753 13.6705 15.9929 12.5451 16.1401 11.3741C16.2057 10.852 15.7729 10.4237 15.2466 10.4237H9.52918C9.0029 10.4237 8.57627 9.99705 8.57627 9.47077V3.7533Z" stroke="currentColor" stroke-width="1.82067"/><path d="M11.435 1.84748C11.435 1.3212 11.8638 0.887589 12.3847 0.962518C12.934 1.04153 13.4726 1.18898 13.9876 1.40232C14.7969 1.73754 15.5323 2.22887 16.1517 2.84828C16.7711 3.46768 17.2624 4.20302 17.5976 5.0123C17.811 5.52735 17.9584 6.06592 18.0374 6.61527C18.1124 7.13618 17.6787 7.56495 17.1525 7.56495L11.5303 7.56495C11.4777 7.56495 11.435 7.52228 11.435 7.46965V1.84748Z" stroke="currentColor" stroke-width="1.82067"/></svg>
            <span class="mk-pill-text">Тирлист</span>
          </a>
        </li>
        <li>
          <!-- Раздела ещё нет, но у "не туда" появился честный адрес: клик
               больше не пишет "#" в строку и не проваливается в никуда, а
               ведёт на /soon — страницу, которая прямо говорит, что раздел
               строится. Поэтому уже настоящая <a>, а не отключённый <span>. -->
          <a class="mk-pill" href="/soon?section=trading">
            <svg viewBox="0 0 18 19" fill="none" aria-hidden="true"><path d="M6.17037 0.943433L4.48309 4.31799M11.8297 0.943433L13.517 4.31799M11.8297 9.4324L8.29262 13.2053L6.17037 11.4903M5.6697 17.9214H12.3304C14.2079 17.9214 15.7998 16.5408 16.0653 14.6821L17.0276 7.94613C17.2711 6.24146 15.9484 4.71631 14.2264 4.71631H3.77368C2.0517 4.71631 0.728943 6.24145 0.972468 7.94613L1.93474 14.6821C2.20027 16.5408 3.79212 17.9214 5.6697 17.9214Z" stroke="currentColor" stroke-width="1.88644" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="mk-pill-text">Трейдинг</span>
          </a>
        </li>
        <li>
          <!-- Тот же адрес, с именем своего раздела — soon.php называет
               "Калькулятор" по строгому белому списку значений section. -->
          <a class="mk-pill" href="/soon?section=calculator">
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

      <!-- Профиля тоже пока нет — аватар ведёт на ту же страницу, что и
           «Трейдинг»/«Калькулятор». <a>, а не <button> с обработчиком:
           переход на страницу — ровно то, для чего существует ссылка, и это
           не требует ни строки JS. Доступное имя «Профиль» перенесено без
           изменений. -->
      <a class="mk-avatar" href="/soon?section=profile" aria-label="Профиль" title="Профиль">
        <svg viewBox="0 0 34 34" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M17.0003 2.83325C13.0883 2.83325 9.91699 6.00457 9.91699 9.91659C9.91699 13.8286 13.0883 16.9999 17.0003 16.9999C20.9123 16.9999 24.0837 13.8286 24.0837 9.91659C24.0837 6.00457 20.9123 2.83325 17.0003 2.83325Z" fill="currentColor"/><path fill-rule="evenodd" clip-rule="evenodd" d="M12.7503 18.4167C10.3947 18.4167 8.12945 19.4913 6.80192 21.109C6.12816 21.9301 5.65451 22.946 5.61326 24.072C5.57114 25.2218 5.98621 26.3442 6.8422 27.3234C8.92833 29.7099 12.2591 31.1667 17.0003 31.1667C21.7415 31.1667 25.0723 29.7099 27.1584 27.3234C28.0144 26.3442 28.4294 25.2218 28.3873 24.072C28.3461 22.946 27.8724 21.9301 27.1987 21.109C25.8711 19.4913 23.6058 18.4167 21.2503 18.4167H12.7503Z" fill="currentColor"/></svg>
      </a>
    </nav>
  </header>

  <!-- ================= Toolbar ================= -->
  <div class="toolbar" id="toolbar">
    <div class="tb-brand">MAKNEMY<span>EDITOR</span></div>

    <nav class="nav-seg" aria-label="Разделы сайта">
      <a href="/tierlist" aria-current="page" data-i18n="news.navTierlist">Тирлист</a>
      <a href="/news" data-i18n="news.navNews">Новости</a>
    </nav>

    <!-- Кнопки редактирования — только для админа -->
    <div class="tb-group" id="tbEdit" hidden>
      <button class="btn" id="btnAddTier" data-i18n="admin.addTier" data-i18n-title="admin.addTierTitle" title="Добавить новый тир">＋ Тир</button>
      <button class="btn" id="btnAddItem" data-i18n="admin.addItem" data-i18n-title="admin.addItemTitle" title="Добавить предмет в первый тир">＋ Предмет</button>
      <button class="btn" id="btnSort" data-i18n="admin.sort" data-i18n-title="admin.sortTitle" title="Отсортировать все предметы по цене (по убыванию)">⇅ Сортировать</button>
    </div>

    <div class="tb-group filters" id="filters">
      <button class="chip" data-f="configurators" data-i18n="filters.configurators" data-i18n-title="filters.configuratorsTitle" title="Скины, хроматики и мутации">Конфигураторы</button>
      <button class="chip" data-f="fruits" data-i18n="filters.fruits" data-i18n-title="filters.fruitsTitle" title="Обычные фрукты">Фрукты</button>
      <button class="chip" data-f="perms" data-i18n="filters.perms" data-i18n-title="filters.permsTitle" title="Перманентные фрукты">Пермы</button>
      <button class="chip" data-f="passes" data-i18n="filters.passes" data-i18n-title="filters.passesTitle" title="Геймпассы и воучеры">Пассы</button>
      <button class="chip all" data-f="all" data-i18n="filters.all" data-i18n-title="filters.allTitle" title="Показать всё">Все</button>
    </div>

    <!-- Язык интерфейса. Содержимое тирлиста (названия, реклама, титры) идёт
         из БД и не переводится — переключатель влияет только на интерфейс. -->
    <div class="tb-group lang-switch" id="langSwitch" role="group"
         data-i18n-label="lang.switch" aria-label="Язык интерфейса">
      <button class="chip" type="button" data-lang="ru" data-i18n="lang.ru" aria-pressed="false">RU</button>
      <button class="chip" type="button" data-lang="en" data-i18n="lang.en" aria-pressed="false">EN</button>
    </div>

    <!-- Переключатели — только для админа -->
    <div class="tb-group" id="tbToggles" hidden>
      <label class="switch" data-i18n-title="admin.autoSortTitle" title="Автоматически переставлять предмет по цене при её изменении">
        <input type="checkbox" id="autoSortToggle" checked />
        <span class="track"><span class="thumb"></span></span>
        <span class="switch-label" data-i18n="admin.autoSort">Автосорт</span>
      </label>
      <label class="switch" id="editToggleWrap" data-i18n-title="admin.editingTitle" title="Режим редактирования">
        <input type="checkbox" id="editToggle" />
        <span class="track"><span class="thumb"></span></span>
        <span class="switch-label" data-i18n="admin.editing">Редактирование</span>
      </label>
    </div>

    <!-- Сохранить (опубликовать изменения для всех) — только для админа -->
    <div class="tb-group" id="tbPublish" hidden>
      <button class="btn save-btn clean" id="btnSave" data-i18n-title="admin.saveTitle" title="Опубликовать изменения для всех">✓ Сохранено</button>
    </div>

    <!-- Скачивание PNG — только для админа, как и всё ниже -->
    <div class="tb-group" id="tbPng" hidden>
      <button class="btn primary" id="btnPng" data-i18n="admin.png" data-i18n-title="admin.pngTitle" title="Скачать тирлист как PNG">⬇ Скачать PNG</button>
    </div>
    <div class="tb-group" id="tbAdminActions" hidden>
      <button class="btn" id="btnExport" data-i18n="admin.export" data-i18n-title="admin.exportTitle" title="Сохранить как JSON">Экспорт</button>
      <button class="btn" id="btnImport" data-i18n="admin.import" data-i18n-title="admin.importTitle" title="Загрузить JSON">Импорт</button>
      <button class="btn danger" id="btnReset" data-i18n="admin.reset" data-i18n-title="admin.resetTitle" title="Сбросить к шаблону">Сброс</button>
    </div>

    <input type="file" id="importFile" accept="application/json" hidden />
    <input type="file" id="tierLogoFile" accept="image/*" hidden />
    <input type="file" id="ptnImgFile" accept="image/*" hidden />
    <span class="tb-saved" id="savedHint"></span>
  </div>

  <!-- ================= Stage (exported area) ================= -->
  <div class="stage-wrap">
    <div class="stage" id="stage">
      <div class="petals" aria-hidden="true"></div>

      <!-- Header -->
      <header class="tl-header">
        <img class="bf-logo" src="assets/poster/logo-bf.png" alt="Blox Fruits" />
        <div class="title-block">
          <div class="date" id="tlDate" spellcheck="false" data-i18n-title="stage.dateTitle" title="Кликните, чтобы изменить дату">17.02.2026</div>
          <h1 class="tl-title" id="tlTitle">MAKNEMY<br/>TIER LIST</h1>
        </div>
        <img class="brand-logo" src="assets/poster/marks.png" data-i18n-alt="stage.brandAlt" alt="Логотип" />
      </header>

      <!-- Tiers + ad block injected here -->
      <main class="tiers" id="tiers"></main>

      <!-- Легенда. Новый макет: одна плашка, заголовок по центру сверху, под
           ним три колонки — типы предмета, спрос и тренды цены. Значки типов
           набраны текстом (Proto Sans + градиент и обводка в CSS), точки спроса
           нарисованы кругами, значки трендов — SVG из макета. -->
      <section class="legend">
        <h2 class="legend-title" data-i18n="legend.title">ПОМОЩЬ НОВИЧКАМ</h2>
        <div class="legend-grid">
          <div class="legend-col lc-types">
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-fv.svg" alt="FV" /><span class="lgl" data-i18n="legend.fv">Фрукт</span></div>
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-cs.svg" alt="CS" /><span class="lgl" data-i18n="legend.cs">Конфигурация скин</span></div>
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-cm.svg" alt="CM" /><span class="lgl" data-i18n="legend.cm">Конфигурация мутация</span></div>
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-ms.svg" alt="MS" /><span class="lgl" data-i18n="legend.ms">Скины мутации</span></div>
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-pm.svg" alt="PM" /><span class="lgl" data-i18n="legend.pm">Перманент</span></div>
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-gp.svg" alt="GP" /><span class="lgl" data-i18n="legend.gp">Геймпасс</span></div>
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-cr.svg" alt="CR" /><span class="lgl" data-i18n="legend.cr">Хроматик</span></div>
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-vh.svg" alt="VH" /><span class="lgl" data-i18n="legend.vh">Ваучер</span></div>
          </div>

          <div class="legend-col lc-demand">
            <div class="lg"><span class="lgd d-green"></span><span class="lgl" data-i18n="legend.good">Хорошо</span></div>
            <div class="lg"><span class="lgd d-yellow"></span><span class="lgl" data-i18n="legend.mid">Средне</span></div>
            <div class="lg"><span class="lgd d-orange"></span><span class="lgl" data-i18n="legend.low">Ниже среднего</span></div>
            <div class="lg"><span class="lgd d-red"></span><span class="lgl" data-i18n="legend.bad">Плохо</span></div>
          </div>

          <div class="legend-col lc-trends">
            <div class="lg"><img class="trend tr-wip" src="assets/design/legend/trend-wip.svg" alt="" /><span class="lgl" data-i18n="legend.wip">Под вопросом</span></div>
            <div class="lg"><img class="trend tr-up" src="assets/design/legend/trend-up.svg" alt="" /><span class="lgl" data-i18n="legend.up">Рост цены</span></div>
            <div class="lg"><img class="trend tr-swap" src="assets/design/legend/trend-swap.svg" alt="" /><span class="lgl" data-i18n="legend.swap">Перерассмотр цены</span></div>
            <div class="lg"><img class="trend tr-down" src="assets/design/legend/trend-down.svg" alt="" /><span class="lgl" data-i18n="legend.down">Падение цены</span></div>
            <div class="lg"><img class="trend tr-new" src="assets/design/legend/trend-new.png" alt="" /><span class="lgl" data-i18n="legend.new">Новый</span></div>
          </div>
        </div>
      </section>

      <!-- Footer (ссылки рендерятся из state в renderFooter — редактируемые) -->
      <footer class="tl-footer" id="tlFooter"></footer>

      <!-- Строка команды отсюда убрана: в редизайне её место занял подвал
           страницы ниже (.mk-foot), и две одинаковые строки подряд не нужны.
           Сами данные (state.credits) остались в базе нетронутыми —
           renderCredits() в app.js просто выходит, не найдя контейнер. -->
    </div>
  </div>

  <!-- ================= Подвал страницы (редизайн) =================
       В макете подвал — часть страницы, а не постера: знак, строка ролей и
       слоган на чёрной плашке. Ники участников остаются в .credits внутри
       сцены — они редактируются админом и уезжают в PNG, а здесь по макету
       только названия ролей. -->
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

  <!-- ====== Боковые рекламные борта (только широкий десктоп) ======
       Снаружи .stage-wrap: .stage — контейнер с overflow: hidden, внутри него
       fixed-элемент был бы обрезан. Побочный полезный эффект — борта не
       попадают в PNG-экспорт, в отличие от карусели и попапа. -->
  <aside class="ptn-rail ptn-rail-l" id="promoRailL" hidden
         data-i18n-label="promo.rail" aria-label="Реклама сбоку"></aside>
  <aside class="ptn-rail ptn-rail-r" id="promoRailR" hidden
         data-i18n-label="promo.rail" aria-label="Реклама сбоку"></aside>

  <!-- ====== Прилипающий баннер внизу экрана (только телефон) ======
       Тоже снаружи .stage-wrap, и по той же причине, что попап и борта:
       у .stage стоит container-type, а контейнер перехватывает
       position: fixed на потомках — изнутри сцены баннер прилип бы к ней,
       а не к экрану. Плюс собственный container-type здесь обязателен:
       вся вёрстка баннера в единицах cqw, и без него они бы считались не
       от той ширины. -->
  <div class="ptn-dock" id="promoDock" hidden
       data-i18n-label="promo.region" aria-label="Рекламные баннеры"></div>

  <!-- ================= Item editor modal ================= -->
  <div class="modal-backdrop" id="modal" hidden>
    <div class="modal">
      <div class="modal-head">
        <h3 id="modalTitle" data-i18n="modal.itemTitle">Предмет</h3>
        <button class="icon-btn" id="modalClose" data-i18n-title="modal.close" title="Закрыть">✕</button>
      </div>
      <div class="modal-body">
        <div class="field icon-field">
          <div class="icon-preview"><img id="mIconPreview" alt="" /></div>
          <div class="icon-actions">
            <label class="btn small"><span data-i18n="modal.iconUpload">Загрузить иконку</span><input type="file" id="mIconFile" accept="image/*" hidden /></label>
            <button class="btn small ghost" id="mIconReset" data-i18n="modal.iconReset">Стандартная</button>
          </div>
        </div>
        <div class="field">
          <label data-i18n="modal.name">Название</label>
          <input type="text" id="mName" data-i18n-placeholder="modal.namePlaceholder" placeholder="Напр. Dragon" />
        </div>
        <div class="field">
          <label data-i18n="modal.value">Значение</label>
          <input type="text" id="mValue" inputmode="numeric" data-i18n-placeholder="modal.valuePlaceholder" placeholder="Напр. 60000" />
        </div>
        <div class="field">
          <label data-i18n="modal.desc">Описание · RU</label>
          <textarea id="mDesc" rows="3" data-i18n-placeholder="modal.descPlaceholder" placeholder="Кратко о фрукте — показывается при просмотре"></textarea>
        </div>
        <div class="field">
          <label data-i18n="modal.descEn">Описание · EN (необязательно)</label>
          <textarea id="mDescEn" rows="3" data-i18n-placeholder="modal.descEnPlaceholder" placeholder="Английская версия — для англоязычного интерфейса. Пусто — покажется русское."></textarea>
        </div>
        <div class="field">
          <label class="switch new-switch" data-i18n-title="modal.newTitle" title="Показать значок NEW на предмете (новый или изменённый)">
            <input type="checkbox" id="mNew" />
            <span class="track"><span class="thumb"></span></span>
            <span class="switch-label" data-i18n="modal.new">Значок «NEW» (новый / изменён)</span>
          </label>
        </div>
        <div class="field">
          <label class="switch new-switch" data-i18n-title="modal.wipTitle" title="Показать значок «?» на предмете (цена под вопросом). Работает вместе с NEW">
            <input type="checkbox" id="mWip" />
            <span class="track"><span class="thumb"></span></span>
            <span class="switch-label" data-i18n="modal.wip">Значок «?» (под вопросом)</span>
          </label>
        </div>
        <div class="field">
          <label data-i18n="modal.fruitType">Тип фрукта</label>
          <div class="seg seg-toggle" id="mFruit">
            <button data-v="f" class="active"><span data-i18n="modal.fruitPlain">Обычный</span> <small>F</small></button>
            <button data-v="p"><span data-i18n="modal.fruitPerm">Перманент</span> <small>P</small></button>
          </div>
        </div>
        <div class="field">
          <label data-i18n="modal.category">Категория (необязательно)</label>
          <div class="seg" id="mType2">
            <button data-v="" class="active">—</button>
            <button data-v="s" class="t-s" data-i18n="modal.catSkin">S · Скин</button>
            <button data-v="m" class="t-m" data-i18n="modal.catMutation">M · Мутация</button>
            <button data-v="gp" class="t-gp" data-i18n="modal.catPass">GP · Пасс</button>
            <button data-v="cr" class="t-cr" data-i18n="modal.catChromatic">CR · Хроматик</button>
          </div>
        </div>
        <div class="field">
          <label data-i18n="modal.demand">Спрос</label>
          <div class="seg" id="mDemand">
            <button data-v="" class="active">—</button>
            <button data-v="green"><img class="dot" src="assets/dot-green.png" alt="" /></button>
            <button data-v="yellow"><img class="dot" src="assets/dot-yellow.png" alt="" /></button>
            <button data-v="orange"><img class="dot" src="assets/dot-orange.png" alt="" /></button>
            <button data-v="red"><img class="dot" src="assets/dot-red.png" alt="" /></button>
          </div>
        </div>
        <div class="field">
          <label data-i18n="modal.trend">Тренд</label>
          <div class="seg" id="mTrend">
            <button data-v="" class="active">—</button>
            <button data-v="up"><img class="trend" src="assets/trend-up.png" alt="" /></button>
            <button data-v="swap"><img class="trend tr-swap" src="assets/trend-swap.png" alt="" /></button>
            <button data-v="down"><img class="trend" src="assets/trend-down.png" alt="" /></button>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn danger" id="mDelete" data-i18n="modal.delete">Удалить</button>
        <button class="btn primary" id="mSave" data-i18n="modal.done">Готово</button>
      </div>
    </div>
  </div>

  <!-- ============ Item VIEW modal (для всех — клик по предмету) ============ -->
  <div class="modal-backdrop" id="viewModal" hidden>
    <div class="modal vmodal">
      <button class="icon-btn vmodal-close" id="viewClose" data-i18n-title="modal.close" title="Закрыть">✕</button>
      <div class="vmodal-body">
        <div class="vmodal-icon"><img id="vIcon" alt="" /></div>
        <div class="vmodal-name" id="vName"></div>
        <div class="vmodal-price">
          <span class="vmodal-price-val" id="vValue"></span>
          <img class="tbadge" id="vBadge" alt="" hidden />
        </div>
        <div class="vmodal-desc" id="vDesc"></div>
      </div>
    </div>
  </div>

  <!-- ============ Донат-модалка (ссылки + QR) ============ -->
  <div class="modal-backdrop" id="donateModal" hidden>
    <div class="modal dmodal">
      <button class="icon-btn dmodal-close" id="donateClose" data-i18n-title="modal.close" title="Закрыть">✕</button>
      <div class="dmodal-body">
        <div class="dmodal-title" data-i18n="donate.modalTitle">💜 Поддержать проект</div>
        <div class="dmodal-sub" data-i18n="donate.modalSub">Спасибо, что помогаешь держать тирлист живым!</div>
        <div class="dmodal-links">
          <a class="btn primary dmodal-link" id="donateLinkDA" href="#" target="_blank" rel="noopener">DonationAlerts</a>
          <a class="btn dmodal-link" id="donateLinkHub" href="#" target="_blank" rel="noopener" data-i18n="donate.linkHub">Все способы (dalink)</a>
        </div>
        <!-- Правка ссылок прямо на сайте — видно админу в режиме «Редактирование» -->
        <div class="dmodal-tools edit-only">
          <button class="btn small" id="donateEditDA" data-i18n="donate.editDA" data-i18n-title="donate.editDATitle" title="Изменить ссылку DonationAlerts">🔗 DonationAlerts</button>
          <button class="btn small" id="donateEditHub" data-i18n="donate.editHub" data-i18n-title="donate.editHubTitle" title="Изменить ссылку на хаб (dalink)">🔗 Все способы</button>
          <label class="btn small" data-i18n-title="donate.qrUploadTitle" title="Загрузить новый QR-код"><span data-i18n="donate.qrUpload">🖼 QR</span><input type="file" id="donateQrFile" accept="image/*" hidden /></label>
          <button class="btn small ghost" id="donateQrReset" data-i18n="donate.qrReset" data-i18n-title="donate.qrResetTitle" title="Вернуть стандартный QR">Стандартный QR</button>
        </div>
        <div class="dmodal-qr">
          <img id="donateQr" src="assets/qr-donate.png?v=1" data-i18n-alt="donate.qrAlt" alt="QR для доната" />
          <div class="dmodal-qr-cap" data-i18n="donate.qrCaption">Наведи камеру телефона</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ====== Рекламное окно (всплывает через ~12 секунд после захода) ======
       Разметка лежит здесь, а не собирается в JS: applyLang() переводит
       [data-i18n*] при каждой смене языка, и статическая разметка получает
       перевод бесплатно. Собранный в JS баннер обновлений этого не умеет и
       остаётся на языке, который был при его создании — повторять этот баг
       не будем.
       Место тоже не случайное: снаружи .stage-wrap. У .stage стоят
       overflow: hidden и container-type: inline-size, из него ничто не может
       вылезти. -->
  <div class="ptn-pop" id="promoPop" hidden role="dialog" aria-modal="true"
       data-i18n-label="promo.popLabel" aria-label="Рекламное сообщение"
       aria-labelledby="promoPopTitle">
    <div class="ptn-pop-card">
      <button class="ptn-pop-close" id="promoPopClose" type="button"
              data-i18n-label="promo.close" data-i18n-title="promo.close"
              aria-label="Закрыть рекламу" title="Закрыть рекламу">✕</button>
      <span class="ptn-chip" data-i18n="ad.chip">РЕКЛАМА</span>
      <div class="ptn-pop-media"><img class="ptn-pop-img" id="promoPopImg" alt="" data-i18n-alt="ad.imageAlt" /></div>
      <!-- Текст и подпись кнопки приходят от рекламодателя — это контент, он
           не переводится. -->
      <div class="ptn-pop-title" id="promoPopTitle"></div>
      <a class="btn primary ptn-pop-cta" id="promoPopCta" href="#" target="_blank" rel="noopener nofollow"></a>
      <!-- Токен маркировки. Заполняет app.js; без него узел скрыт. -->
      <span class="ptn-erid" id="promoPopErid" hidden></span>
    </div>
  </div>

  <!-- html2canvas грузится по требованию из app.js (только при экспорте PNG) -->
  <script src="js/i18n.js?v=19"></script>
  <script src="js/content.js?v=1"></script>
  <script src="js/tiers.js?v=1"></script>
  <!-- Логика показа рекламы. Обязательно ДО app.js: он читает PROMO при
       первом render(). Файл намеренно не называется js/ads.js — это имя
       режут сетевые фильтры блокировщиков. -->
  <script src="js/promo.js?v=2"></script>
  <!-- Защита контента от копирования — общая с лентой новостей.
       ДО app.js: он зовёт NX_PROTECT в setupProtection() на старте. -->
  <script src="js/protect.js?v=1"></script>
  <script src="js/app.js?v=62"></script>
</body>
</html>
