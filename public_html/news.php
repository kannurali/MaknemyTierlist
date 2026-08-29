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
// Размеры едут вместе с картинкой, а не хардкодятся в <head>: статичный
// баннер и сгенерированное превью РАЗНОГО размера (1920x1080 против
// 1200x630), и одна пара чисел на оба случая всегда врёт про один из них.
// Врёт молча: краулер верстает карточку по объявленным числам, а не по
// файлу. Так это уже сделано в index.php — см. tierlist_og_fallback() там.
function news_og_fallback(): array {
    return [
        'image'       => 'https://maknemytierlist.site/assets/og-image.jpg?v=2',
        'imageWidth'  => 1920,
        'imageHeight' => 1080,
        'imageType'   => 'image/jpeg',
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
        'imageWidth'  => 1200,
        'imageHeight' => 630,
        'imageType'   => 'image/png',
        'title'       => $meta['title'],
        'description' => $meta['description'] !== '' ? $meta['description'] : news_og_fallback()['description'],
    ];
}

// ---------------------------------------------------------------------------
//  /news/<id> — постоянная ссылка на конкретный пост (не только на самый
//  свежий). .htaccess рероутит news/<id> сюда же как news.php?id=<id> — см.
//  комментарий там же про то, почему это не отдельный файл.
// ---------------------------------------------------------------------------

// Строгий разбор id из адресной строки — тот же принцип, что у
// og_parse_version() (api/lib/og.php) и read_row_id() (api/_bootstrap.php):
// только цифры, только положительное значение, никакого (int)-приведения
// мусора. id здесь — то, что ввёл посетитель в адресную строку (или скопировал
// из чужой ссылки), поэтому 'abc', '1abc', '-5', '1;drop' и т. п. обязаны
// отклоняться целиком, а не обрезаться до цифровой части.
function news_parse_post_id($raw): ?int {
    if (is_int($raw)) { return $raw > 0 ? $raw : null; }
    if (is_string($raw) && $raw !== '' && ctype_digit($raw)) {
        $n = (int)$raw;
        return $n > 0 ? $n : null;
    }
    return null;
}

// Пост по id — для og:* конкретной страницы и для подсказки клиенту, какую
// карточку подсветить (см. NX_LINKED_POST_ID ниже и focusLinkedPost() в
// js/news-page.js). null — пост с таким id не существует: вызывающая сторона
// отвечает 404 (см. дальше по файлу), а не 500 и не тихо показывает ленту как
// ни в чём не бывало.
function news_post_by_id(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(
        'SELECT id, category, title_ru, body_ru, image_url, published_at
           FROM news WHERE id = :id'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

// og:*/og:image конкретного поста — та же схема, что news_og_data() выше
// (og_news_summary()/og_news_meta() из api/lib/og.php), только по СВОЕЙ
// строке, а не по самой свежей. og:image указывает на og-news.php с ЕГО
// собственными id и версией (id.published_at, склеенные так же, как для
// самого свежего поста, см. og_news_summary()) — правку поста (значит, смену
// published_at) это превращает в новый URL картинки без ручной инвалидации
// кэша, ровно как и было задумано для /news в целом.
function news_post_og_data(array $row): array {
    $summary = og_news_summary($row);
    if ($summary === null) { return news_og_fallback(); }

    // og_news_summary() не кладёт id в свой результат (он не нужен ни одному
    // из её существующих вызывающих — оба раньше работали только с "самым
    // свежим" постом, у него id не требовался отдельно от версии) — берём
    // его из уже провалидированной (title непустой и т. д., иначе $summary
    // был бы null) исходной строки.
    $id = (int)($row['id'] ?? 0);

    $meta = og_news_meta($summary);
    return [
        'image'       => 'https://maknemytierlist.site/api/og-news.php?id=' . $id . '&v=' . $summary['version'],
        'imageWidth'  => 1200,
        'imageHeight' => 630,
        'imageType'   => 'image/png',
        'title'       => $meta['title'],
        'description' => $meta['description'] !== '' ? $meta['description'] : news_og_fallback()['description'],
    ];
}

$postId = news_parse_post_id($_GET['id'] ?? null);
$linkedPostId = null; // id поста, на который ведёт /news/<id> — null на обычном /news
$notFound = false;    // id есть, но такого поста нет — 404, а не тихая подмена на ленту
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
        $pdo = db();
        if ($postId !== null) {
            $row = news_post_by_id($pdo, $postId);
            if ($row === null) {
                // id синтаксически валиден, но такого поста нет (удалили,
                // опечатались, никогда не существовал) — не 500 и не молчаливый
                // показ всей ленты, как будто ссылка была на /news. Лента при
                // этом всё равно рисуется ниже (человеку, пришедшему по битой
                // ссылке, есть что почитать), но статус — настоящий 404, а
                // не 200: краулер обязан узнать, что этого конкретного адреса
                // не существует, а не проиндексировать его как дубликат /news.
                $notFound = true;
            } else {
                $linkedPostId = $postId;
                $og = news_post_og_data($row);
            }
        } else {
            $og = news_og_data($pdo);
        }
    } catch (Throwable $e) {
        error_log('news.php: og preview fallback: ' . $e->getMessage());
    }
    if ($notFound) { http_response_code(404); }
}

// Канонический адрес — свой у каждого поста (иначе /news/<id> и /news были
// бы двумя URL с формально разным содержимым og:*, но одним и тем же
// canonical, что путает краулеров). У 404-случая свой персональный адрес
// невалиден — канонизируем на общую ленту, туда же указывает и og:url.
$canonicalUrl = 'https://maknemytierlist.site/news' . ($linkedPostId !== null ? '/' . $linkedPostId : '');
// noindex на 404: сама лента ниже всё равно рисуется (см. комментарий выше),
// но индексировать битый адрес как рабочую страницу незачем — 404 в статусе
// это уже даёт понять большинству краулеров, noindex просто не оставляет
// шанса на исключение для тех, кто по какой-то причине проигнорирует статус.
$robots = $notFound ? 'noindex, follow' : 'index, follow, max-image-preview:large';
?>
<?php if (!defined('TESTING')): ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<!-- news.php отдаётся и на /news, и на /news/<id> (см. .htaccess) —
     относительные пути ниже («css/base.css», «js/news.js», …) без этого
     тега резолвились бы от адреса ДОКУМЕНТА, а не от корня: на /news/<id>
     (на один уровень глубже /news) это увело бы их в несуществующие
     /news/css/…, /news/js/… — та же ловушка, которую /news/ → /news 301
     выше в .htaccess уже решает для одного конкретного случая. Явный
     <base> решает её сразу для любой глубины запроса, а не только для
     той, что предусмотрели заранее. -->
<base href="/" />
<!-- Как и на тирлисте: без этой строчки принудительное затемнение в
     Яндекс.Браузере инвертирует монохромные логотипы в шапке. -->
<meta name="color-scheme" content="dark" />

<title>Новости Blox Fruits и обновления тирлиста | Maknemy Tier List</title>
<meta name="description" content="Новости Blox Fruits, изменения трейд-ценностей в тирлисте Maknemy и анонсы проекта: апдейты, ребалансы, розыгрыши." />
<link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>" />
<meta name="robots" content="<?= htmlspecialchars($robots, ENT_QUOTES, 'UTF-8') ?>" />

<meta property="og:type" content="website" />
<meta property="og:site_name" content="Maknemy Tier List" />
<meta property="og:locale" content="ru_RU" />
<meta property="og:url" content="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>" />
<meta property="og:title" content="<?= htmlspecialchars($og['title'], ENT_QUOTES, 'UTF-8') ?>" />
<!-- og:description здесь нет намеренно: мессенджер рисует его абзацем под
     заголовком, и карточка превращалась в стену текста, которая забивала
     собой картинку с заголовком. На поиск это не влияет — выдача берёт
     meta name="description" выше. Значение по-прежнему считается в
     news_og_data() и покрыто tests/og_test.php: вернуть тег — одна строка. -->
<meta property="og:image" content="<?= htmlspecialchars($og['image'], ENT_QUOTES, 'UTF-8') ?>" />
<meta property="og:image:width" content="<?= (int)$og['imageWidth'] ?>" />
<meta property="og:image:height" content="<?= (int)$og['imageHeight'] ?>" />
<meta property="og:image:type" content="<?= htmlspecialchars($og['imageType'], ENT_QUOTES, 'UTF-8') ?>" />
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="<?= htmlspecialchars($og['title'], ENT_QUOTES, 'UTF-8') ?>" />
<meta name="twitter:image" content="<?= htmlspecialchars($og['image'], ENT_QUOTES, 'UTF-8') ?>" />

<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="apple-touch-icon" href="/apple-touch-icon.png" />

<link rel="stylesheet" href="css/base.css?v=4" />
<link rel="stylesheet" href="css/news.css?v=12" />
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

      <!-- Пояснение для /news/<id>, когда пост из ссылки не входит в
           последние 50, которые отдаёт api/news.php (пост существует —
           иначе сервер уже ответил бы 404 выше, — но за пределами ленты
           показать нечего). Заполняется и показывается из
           focusLinkedPost() в js/news-page.js, не из PHP: сама лента
           грузится и рисуется на клиенте. -->
      <div class="nw-notice" id="newsNotice" role="status" hidden></div>

      <main class="nw-feed" id="feed"></main>
      <div class="nw-state" id="newsState" role="status" aria-live="polite" hidden></div>
    </div>
  </div>

  <!-- Редактора поста здесь нет: его вставляет admin-news.php на /admin/news.
       Посетитель ленты не качает ни модалку на восемь полей, ни кнопку
       «Добавить» — на публичной странице админской разметки ноль. -->

<?php if ($linkedPostId !== null): ?>
  <script>window.NX_LINKED_POST_ID = <?= (int)$linkedPostId ?>;</script>
<?php endif; ?>
  <script src="js/i18n.js?v=13"></script>
  <script src="js/news.js?v=4"></script>
  <script src="js/news-blocks.js?v=1"></script>
  <!-- Защита контента от копирования — тот же модуль, что и на тирлисте.
       ДО news-page.js: он зовёт NX_PROTECT на старте. -->
  <script src="js/protect.js?v=1"></script>
  <script src="js/news-page.js?v=15"></script>
</body>
</html>
<?php endif; ?>
