<?php
require_once __DIR__ . '/api/_bootstrap.php';
require_once __DIR__ . '/api/lib/og.php';
require_once __DIR__ . '/api/lib/metrika.php';

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
// Размеры едут вместе с картинкой, а не хардкодятся в <head>: краулер верстает
// карточку по объявленным числам, а не по файлу, поэтому пара чисел обязана
// приходить оттуда же, откуда адрес. Сейчас оба варианта совпали на 1200x630,
// но склеивать их в один хардкод нельзя: как только запасная картинка снова
// станет другого размера, одна пара чисел начнёт молча врать про один из
// случаев. Так это уже сделано в index.php — см. tierlist_og_fallback() там.
function news_og_fallback(): array {
    return og_brand_card() + [
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
        'image'       => 'https://maknemy.com/api/og-news.php?v=' . $summary['version'],
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
        'image'       => 'https://maknemy.com/api/og-news.php?id=' . $id . '&v=' . $summary['version'],
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
$canonicalUrl = 'https://maknemy.com/news' . ($linkedPostId !== null ? '/' . $linkedPostId : '');
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

<link rel="stylesheet" href="css/base.css?v=8" />
<link rel="stylesheet" href="css/news.css?v=12" />
<!-- Общая шапка сайта: та же, что на главной и тирлисте. Идёт после
     news.css — прячет старый бренд и .nav-seg в тулбаре, их роль забрала
     она сама. -->
<link rel="stylesheet" href="css/topbar.css?v=8" />
<!-- Поведение шапки: компактный режим при прокрутке и плашка
     «В активной разработке» на разделах, которых ещё нет.
     defer — код лезет в DOM сразу, без ожидания события. -->
<script src="js/topbar.js?v=3" defer></script>
<!-- Фон страницы и подвал из редизайна. -->
<link rel="stylesheet" href="css/design-page.css?v=30" />
<!-- Лента по редизайну: перекрывает постерный вид из news.css. -->
<link rel="stylesheet" href="css/news-design.css?v=16" />
<!-- Нижняя рекламная полоса на телефоне: слот "dock", тот же документ
     /api/promo.php, что у бортов по бокам ленты. -->
<link rel="stylesheet" href="css/promo-dock.css?v=1" />
<!-- Рекламное окно: слот "popup", раз в сутки. Пока место не выкуплено,
     показывает собственное объявление о телеграм-канале. -->
<link rel="stylesheet" href="css/promo-popup.css?v=1" />
<!-- Счётчик Яндекс Метрики. Разметка у всех страниц общая и лежит в
     api/lib/metrika.php: искать её текст в этом файле бесполезно. -->
<?php echo metrika_counter_html(); ?>
</head>
<body class="news-bg">
  <header class="mk-top">
    <a class="mk-top-brand" href="/">
      <img class="mk-top-mark" src="assets/design/logo-mk-square.png" alt="" aria-hidden="true" />
      <img class="mk-top-word" src="assets/design/wordmark.svg" alt="MAKNEMY" />
    </a>

    <!-- Язык интерфейса. Стоит в самой шапке, а не в полосе под ней: шапка
         общая для всех страниц, значит и переключатель обязан быть в одном
         месте везде. При прокрутке уезжает влево вместе с логотипом
         (.mk-top.is-stuck .mk-top-lang в topbar.css).

         Тексты постов приходят из БД и не переводятся — переключатель
         влияет только на интерфейс. -->
    <div class="mk-top-lang lang-switch" id="langSwitch" role="group"
         data-i18n-label="lang.switch" aria-label="Язык интерфейса">
      <button class="chip" type="button" data-lang="ru" data-i18n="lang.ru" aria-pressed="false">RU</button>
      <button class="chip" type="button" data-lang="en" data-i18n="lang.en" aria-pressed="false">EN</button>
    </div>

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
          <!-- «Калькулятор» получил страницу — /calculator — и вышел из
               «В активной разработке»: рабочая ссылка, как остальные пункты
               меню. На самой странице /calculator эта же пилюля дополнительно
               несёт aria-current="page" (см. calculator.php). -->
          <a class="mk-pill" href="/calculator">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M5.70001 8.55001V13.3M13.3 10.45V13.3M9.5 5.70001V13.3M4.75001 18.05H14.25C16.3487 18.05 18.05 16.3487 18.05 14.25V4.75001C18.05 2.65134 16.3487 0.950022 14.25 0.950022H4.75001C2.65134 0.950022 0.950022 2.65134 0.950022 4.75001V14.25C0.950022 16.3487 2.65134 18.05 4.75001 18.05Z" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
            <span class="mk-pill-text">Калькулятор</span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="/news" aria-current="page">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M18.05 9.50002C18.05 14.2221 14.222 18.05 9.49995 18.05M18.05 9.50002C18.05 4.77798 14.222 0.950013 9.49995 0.950013M18.05 9.50002C18.05 7.92601 14.222 6.65002 9.49995 6.65002C4.77792 6.65002 0.949949 7.92601 0.949949 9.50002M18.05 9.50002C18.05 11.074 14.222 12.35 9.49995 12.35C4.77792 12.35 0.949949 11.074 0.949949 9.50002M9.49995 18.05C4.77792 18.05 0.949949 14.2221 0.949949 9.50002M9.49995 18.05C11.074 18.05 12.35 14.2221 12.35 9.50002C12.35 4.77798 11.074 0.950013 9.49995 0.950013M9.49995 18.05C7.92594 18.05 6.64995 14.2221 6.64995 9.50002C6.64995 4.77798 7.92594 0.950013 9.49995 0.950013M0.949949 9.50002C0.949949 4.77798 4.77792 0.950013 9.49995 0.950013" stroke="currentColor" stroke-width="1.9"/></svg>
            <span class="mk-pill-text">Новости</span>
          </a>
        </li>
      </ul>

      <!-- Чат. Появился в макете шапки (Figma, нода 244:7171): такой же
           круг с градиентом, что и профиль, слева от него. Раздела ещё нет,
           поэтому кнопка помечена data-soon и по клику отвечает «В активной
           разработке» — как «Трейдинг» и профиль. Вернуть раздел = убрать
           data-soon и заменить тег на <a href>.

           Волосяной разделитель между разделами и парой круглых кнопок
           теперь рисует она (.mk-chat::before в topbar.css): в макете чат
           стоит первым из пары, и разделитель у профиля оказался бы
           посреди неё. -->
      <button class="mk-chat" type="button" aria-label="Чат" data-soon data-i18n-title="topbar.soon" title="В активной разработке">
        <svg viewBox="0 0 25 25" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M12.0833 0C5.40989 0 0 5.40989 0 12.0833C0 14.2768 0.585445 16.3362 1.60861 18.1109C1.817 18.4723 1.85274 18.9124 1.67689 19.2907L0.645317 21.5102C0.0119158 22.7086 0.878898 24.1667 2.24942 24.1667H12.0833C18.7568 24.1667 24.1667 18.7568 24.1667 12.0833C24.1667 5.40989 18.7568 0 12.0833 0ZM8.45833 8.45833C7.79099 8.45833 7.25 8.99932 7.25 9.66667C7.25 10.334 7.79099 10.875 8.45833 10.875H10.875C11.5423 10.875 12.0833 10.334 12.0833 9.66667C12.0833 8.99932 11.5423 8.45833 10.875 8.45833H8.45833ZM8.45833 13.2917C7.79099 13.2917 7.25 13.8327 7.25 14.5C7.25 15.1673 7.79099 15.7083 8.45833 15.7083H15.7083C16.3757 15.7083 16.9167 15.1673 16.9167 14.5C16.9167 13.8327 16.3757 13.2917 15.7083 13.2917H8.45833Z" fill="currentColor"/></svg>
      </button>

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

  <!-- Полосы тулбара здесь больше нет. От неё оставался один переключатель
       языка (бренд и дублирующее меню прятал topbar.css, фильтры по
       категориям убраны по редизайну), а он переехал в шапку — держать ради
       пустого блока отдельную полосу под шапкой незачем. -->

  <main class="nw-page">
    <div class="nw-lead">

      <!-- Рекламные борта по бокам колонки (Frame 55 / Frame 56 в макете).
           Полосатая панель — это образ СВОБОДНОГО места, и стоит она
           всегда, куплен слот "rail" или нет: место, которое видно, можно
           продать, а спрятанное — нельзя. Так же ведут себя борта
           калькулятора (.tc-rail в calculator.css).

           Когда из /api/promo.php приезжает креатив, renderNewsPromo() в
           js/news-page.js вешает на борт класс has-ad, и полоски уступают
           картинке кампании. -->
      <!-- Два уровня не для красоты: внешняя колонка тянется на всю высоту
           ленты, а сама панель внутри неё липкая — так борт остаётся на
           виду всю прокрутку и при этом не вылезает за пределы ленты. -->
      <div class="nw-rail-slot nw-rail-l" aria-hidden="true">
        <aside class="nw-rail" id="newsRailL"
               data-i18n-label="promo.rail" aria-label="Реклама сбоку"></aside>
      </div>
      <div class="nw-rail-slot nw-rail-r" aria-hidden="true">
        <aside class="nw-rail" id="newsRailR"
               data-i18n-label="promo.rail" aria-label="Реклама сбоку"></aside>
      </div>

      <!-- Декор. Тот же набор картинок, что на главной, но разложен так,
           как в макете новостей. Клики не перехватывает. -->
      <div class="nw-deco nw-ghost nw-ghost-a" aria-hidden="true"></div>
      <div class="nw-deco nw-ghost nw-ghost-b" aria-hidden="true"></div>
      <img class="nw-deco nw-sakura nw-sakura-l" src="assets/design/home/sakura.webp" alt="" aria-hidden="true" />
      <img class="nw-deco nw-sakura nw-sakura-r" src="assets/design/home/sakura.webp" alt="" aria-hidden="true" />
      <div class="nw-deco nw-fig nw-fig-circle" aria-hidden="true"></div>
      <div class="nw-deco nw-fig nw-fig-square-lg" aria-hidden="true"></div>
      <div class="nw-deco nw-fig nw-fig-square-sm" aria-hidden="true"></div>
      <div class="nw-deco nw-fig nw-fig-tri" aria-hidden="true"></div>

      <!-- ADMIN-BAR -->

      <!-- Пояснение для /news/<id>, когда пост из ссылки не входит в
           последние 50, которые отдаёт api/news.php (пост существует —
           иначе сервер уже ответил бы 404 выше, — но за пределами ленты
           показать нечего). Заполняется и показывается из
           focusLinkedPost() в js/news-page.js, не из PHP: сама лента
           грузится и рисуется на клиенте. -->
      <div class="nw-notice" id="newsNotice" role="status" hidden></div>

      <!-- Заголовка страницы и фильтров по категориям здесь больше нет:
           в макете новостей их нет, и по решению заказчика они убраны.
           Разметку фильтров заполнял renderFilters() в js/news-page.js —
           он теперь молча выходит, не найдя контейнера, так что вернуть
           фильтры можно одним <div id="newsFilters">. -->
      <div class="nw-feed" id="feed"></div>
      <div class="nw-state" id="newsState" role="status" aria-live="polite" hidden></div>
    </div>
  </main>

  <!-- Подвал сайта — тот же, что на главной и тирлисте (стили в design-page.css). -->
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


  <!-- ====== Нижняя рекламная полоса (только телефоны) ======
       Горизонтальная пара к боковым бортам: на телефоне борта скрыты
       вместе с остальным декором, и место «сбоку от контента» там — низ
       экрана. Слот "dock" в /api/promo.php, тот же документ, что у бортов.

       Прямой потомок <body> намеренно: полоса прибита position: fixed, а
       любой предок с transform или container-type перехватил бы это на
       себя, и она прилипла бы к колонке, а не к экрану.

       hidden снимает js/promo-dock.js, когда приехал реальный креатив:
       пустая тёмная полоса поверх нижней трети телефона читается как
       поломка вёрстки, а не как свободное место. -->
  <div class="ptn-dock" id="promoDock" hidden
       data-i18n-label="promo.region" aria-label="Рекламные баннеры"></div>


  <!-- ====== Рекламное окно ======
       Всплывает через ~12 секунд после захода и не чаще раза в сутки
       (частоту задаёт сама кампания, см. capHours в js/promo.js). Пока
       слот "popup" не выкуплен, здесь показывается собственное
       объявление о телеграм-канале проекта — PROMO.HOUSE_TG.

       Разметка лежит в странице, а не собирается в JS: applyLang()
       проходит по [data-i18n*] при каждой смене языка, и статическая
       разметка получает перевод бесплатно.

       Прямой потомок <body>: окно прибито position: fixed, и предок с
       transform или container-type перехватил бы это на себя. -->
  <div class="ptn-pop" id="promoPop" hidden role="dialog" aria-modal="true"
       data-i18n-label="promo.popLabel" aria-label="Рекламное сообщение"
       aria-labelledby="promoPopTitle">
    <div class="ptn-pop-card">
      <button class="ptn-pop-close" id="promoPopClose" type="button"
              data-i18n-label="promo.close" data-i18n-title="promo.close"
              aria-label="Закрыть рекламу" title="Закрыть рекламу">✕</button>
      <span class="ptn-chip" data-i18n="ad.chip">РЕКЛАМА</span>
      <!-- alt пустой намеренно: доступное имя несёт само окно
           (data-i18n-label выше), и подпись на картинке дублировала бы
           его вторым «Реклама» подряд. -->
      <div class="ptn-pop-media"><img class="ptn-pop-img" id="promoPopImg" alt="" /></div>
      <!-- Текст и подпись кнопки платной кампании приходят от
           рекламодателя и не переводятся; у своего объявления вместо них
           ключи словаря — js/promo-popup.js вешает на узел data-i18n. -->
      <div class="ptn-pop-title" id="promoPopTitle"></div>
      <a class="btn primary ptn-pop-cta" id="promoPopCta" href="#" target="_blank" rel="noopener nofollow"></a>
      <!-- Токен маркировки. Заполняет js/promo-popup.js; без него узел скрыт. -->
      <span class="ptn-erid" id="promoPopErid" hidden></span>
    </div>
  </div>

  <!-- Редактора поста здесь нет: его вставляет admin-news.php на /admin/news.
       Посетитель ленты не качает ни модалку на восемь полей, ни кнопку
       «Добавить» — на публичной странице админской разметки ноль. -->

<?php if ($linkedPostId !== null): ?>
  <script>window.NX_LINKED_POST_ID = <?= (int)$linkedPostId ?>;</script>
<?php endif; ?>
  <script src="js/i18n.js?v=30"></script>
  <script src="js/news.js?v=4"></script>
  <script src="js/news-blocks.js?v=1"></script>
  <!-- Отбор рекламных кампаний — тот же модуль, что на тирлисте. -->
  <script src="js/promo.js?v=4"></script>
  <!-- Нижняя полоса на телефоне — общий модуль с калькулятором. -->
  <script src="js/promo-dock.js?v=2"></script>
  <!-- Рекламное окно — общий модуль с калькулятором. -->
  <script src="js/promo-popup.js?v=1"></script>
  <!-- Защита контента от копирования — тот же модуль, что и на тирлисте.
       ДО news-page.js: он зовёт NX_PROTECT на старте. -->
  <script src="js/protect.js?v=1"></script>
  <script src="js/news-page.js?v=21"></script>
</body>
</html>
<?php endif; ?>
