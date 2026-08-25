<?php
require_once __DIR__ . '/api/_bootstrap.php';
require_once __DIR__ . '/api/lib/og.php';

// Превью-карточка ссылки (og:image/title/description) собирается из самого
// свежего поста ДО отдачи <head> — краулеры (Telegram, Discord, VK) не
// исполняют JS и читают только то, что уже есть в HTML. Тело страницы ниже
// байт-в-байт то же, что было в news.html (см. историю git) — сама лента
// как рисовалась на клиенте js/news-page.js, так и рисуется.
//
// Никогда не роняет страницу: любая ошибка (нет БД, кривой JSON, постов ещё
// нет) откатывает превью на статичный баннер — см. og_news_summary() в
// api/lib/og.php. Лента обязана открываться в любом случае, битое превью —
// не повод для 500.
function news_og_fallback(): array {
    return [
        'image'       => 'https://maknemytierlist.site/assets/og-image.jpg?v=2',
        'title'       => 'Новости Blox Fruits и обновления тирлиста',
        'description' => 'Апдейты игры, изменения трейд-ценностей и анонсы проекта.',
    ];
}

function news_og_data(PDO $pdo): array {
    $row = $pdo->query(
        'SELECT id, category, title_ru, body_ru, image_url, published_at
           FROM news ORDER BY published_at DESC, id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    $summary = og_news_summary($row === false ? null : $row);
    if ($summary === null) { return news_og_fallback(); }

    $meta = og_news_meta($summary);
    return [
        'image'       => 'https://maknemytierlist.site/api/og-news.php?v=' . $summary['version'],
        'title'       => $meta['title'],
        'description' => $meta['description'] !== '' ? $meta['description'] : news_og_fallback()['description'],
    ];
}

$og = news_og_fallback();
if (!defined('TESTING') && !defined('NX_ADMIN_RENDER')) {
    // Та же защита, что .htaccess раньше давал news.html через
    // <FilesMatch "\.html$">: без no-cache Safari после деплоя часами
    // держит старую страницу с устаревшими ?v= у css/js. FilesMatch не годится
    // для .php — его паттерн по имени файла зацепил бы заодно api/news.php
    // (лента) и переписал бы её собственный Cache-Control, поэтому заголовок
    // ставится здесь же, в самой странице, как и остальные PHP-эндпоинты
    // проекта уже делают (см. api/tierlist.php, api/news.php).
    //
    // NX_ADMIN_RENDER — эту же страницу зовёт admin_render_public_page() из
    // /admin/news, чтобы забрать её вывод (см. admin_page.php). Там уже стоит
    // свой Cache-Control (no-store, из admin_page_headers()), и более мягкое
    // значение отсюда переписало бы его; поход в БД за og:* админке не нужен —
    // эти теги она не показывает. На публичной /news этот флаг не выставлен,
    // поведение страницы для посетителей не меняется.
    header('Cache-Control: no-cache, must-revalidate');
    try {
        $og = news_og_data(db());
    } catch (Throwable $e) {
        error_log('news.php: og preview fallback: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<!-- Как и на тирлисте: без этой строчки принудительное затемнение в
     Яндекс.Браузере инвертирует монохромные логотипы в шапке. -->
<meta name="color-scheme" content="dark" />

<title>Новости Blox Fruits и обновления тирлиста | Maknemy Tier List</title>
<meta name="description" content="Новости Blox Fruits, изменения трейд-ценностей в тирлисте Maknemy и анонсы проекта: апдейты, ребалансы, розыгрыши." />
<link rel="canonical" href="https://maknemytierlist.site/news" />
<meta name="robots" content="index, follow, max-image-preview:large" />

<meta property="og:type" content="website" />
<meta property="og:site_name" content="Maknemy Tier List" />
<meta property="og:locale" content="ru_RU" />
<meta property="og:url" content="https://maknemytierlist.site/news" />
<meta property="og:title" content="<?= htmlspecialchars($og['title'], ENT_QUOTES, 'UTF-8') ?>" />
<meta property="og:description" content="<?= htmlspecialchars($og['description'], ENT_QUOTES, 'UTF-8') ?>" />
<meta property="og:image" content="<?= htmlspecialchars($og['image'], ENT_QUOTES, 'UTF-8') ?>" />
<meta property="og:image:width" content="1200" />
<meta property="og:image:height" content="630" />
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="<?= htmlspecialchars($og['title'], ENT_QUOTES, 'UTF-8') ?>" />
<meta name="twitter:description" content="<?= htmlspecialchars($og['description'], ENT_QUOTES, 'UTF-8') ?>" />
<meta name="twitter:image" content="<?= htmlspecialchars($og['image'], ENT_QUOTES, 'UTF-8') ?>" />

<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="apple-touch-icon" href="/apple-touch-icon.png" />

<link rel="stylesheet" href="css/base.css?v=3" />
<link rel="stylesheet" href="css/news.css?v=8" />
</head>
<body>
  <div class="toolbar" id="toolbar">
    <div class="tb-brand">MAKNEMY<span>NEWS</span></div>

    <nav class="nav-seg" aria-label="Разделы сайта">
      <a href="/" data-i18n="news.navTierlist">Тирлист</a>
      <a href="/news" aria-current="page" data-i18n="news.navNews">Новости</a>
    </nav>

    <div class="tb-group lang-switch" id="langSwitch" role="group"
         data-i18n-label="lang.switch" aria-label="Язык интерфейса">
      <button class="chip" type="button" data-lang="ru" data-i18n="lang.ru" aria-pressed="false">RU</button>
      <button class="chip" type="button" data-lang="en" data-i18n="lang.en" aria-pressed="false">EN</button>
    </div>
  </div>

  <div class="stage-wrap">
    <div class="stage" id="stage">
      <div class="petals" aria-hidden="true"></div>

      <header class="nw-header">
        <img class="bf-logo" src="assets/poster/logo-bf.png" alt="Blox Fruits" />
        <div class="nw-title-wrap">
          <h1 class="nw-title" data-i18n="news.pageTitle">НОВОСТИ</h1>
        </div>
        <img class="brand-logo" src="assets/poster/marks.png" alt="" />
      </header>

      <!-- ADMIN-BAR -->

      <div class="nw-filters" id="newsFilters" role="group"
           data-i18n-label="news.filterGroupLabel" aria-label="Фильтр по категориям"></div>

      <main class="nw-feed" id="feed"></main>
      <div class="nw-state" id="newsState" role="status" aria-live="polite" hidden></div>
    </div>
  </div>

  <!-- Редактора поста здесь нет: его вставляет admin-news.php на /admin/news.
       Посетитель ленты не качает ни модалку на восемь полей, ни кнопку
       «Добавить» — на публичной странице админской разметки ноль. -->

  <script src="js/i18n.js?v=10"></script>
  <script src="js/news.js?v=4"></script>
  <script src="js/news-page.js?v=10"></script>
</body>
</html>
