<?php
require_once __DIR__ . '/api/_bootstrap.php';
require_once __DIR__ . '/api/lib/og.php';
require_once __DIR__ . '/api/lib/metrika.php';

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
// Картинка — фирменная карточка «MAKNEMY TIER LIST» (og_brand_card() в
// api/lib/og.php), одна и та же при любых данных. Раньше здесь строилось
// живое превью с топ-5 предметов и ценами, общее с home.php; заменено по
// решению владельца проекта — ссылка на тирлист должна выглядеть одинаково
// узнаваемо, а не меняться от каждого редактирования цен.
//
// Живое превью никуда не делось: og_tierlist_image() и генератор
// api/og-tierlist.php остались на месте и продолжают обслуживать home.php.
// Поэтому "/" и /tierlist теперь показывают РАЗНЫЕ картинки — это осознанно,
// а не рассинхрон: прежний комментарий здесь предостерегал ровно от такого
// расхождения, пока оно было случайным.
//
// title/description остаются живыми и здесь: они не картинка, в чате видны
// текстом рядом с ней, и дата с топом предметов там к месту.
function tierlist_og_fallback(): array {
    return og_brand_card() + [
        'title'       => t('og.tierlist.title'),
        'description' => t('og.tierlist.desc'),
    ];
}

// Ревизия тирлиста, отданная странице вместе с разметкой. Нужна не превью,
// а самой странице: с ней js/app.js идёт сразу за данными по
// /api/tierlist.php?rev=<n>, минуя /api/state.php. Раньше до первого предмета
// было два запроса подряд — сначала крошечный state, потом сам тирлист, — и
// всё это время на экране висел каркас. Ответ на конкретную ревизию помечен
// immutable, поэтому у вернувшегося посетителя он берётся из кэша браузера.
// null — БД недоступна или пуста: страница просто работает как раньше.
$nxRev = null;

function tierlist_og_data(PDO $pdo): array {
    global $nxRev;
    $row = $pdo->query('SELECT data, rev FROM tierlist WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if (isset($row['rev']) && is_numeric($row['rev'])) { $nxRev = (int)$row['rev']; }
    $summary = og_tierlist_summary($row['data'] ?? null, $row['rev'] ?? null);
    if ($summary === null) { return tierlist_og_fallback(); }

    $meta = og_tierlist_meta($summary);
    $fallback = tierlist_og_fallback();
    return og_brand_card() + [
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
    // Минута в кеше LiteSpeed — см. page_lscache() в api/_bootstrap.php.
    // Ревизия из <meta name="nx-rev"> закешированной копии может отстать на эту
    // минуту — тогда app.js получит данные через редирект tierlist.php на
    // текущую ревизию, а следующий опрос state.php всё выровняет.
    page_lscache();
}
?>
<!DOCTYPE html>
<html lang="<?= i18n_lang() ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<base href="/" />
<?php if ($nxRev !== null): ?>
<meta name="nx-rev" content="<?= (int)$nxRev ?>" />
<?php endif; ?>

<meta name="color-scheme" content="dark" />
<?php if (HALLOWEEN): ?><meta name="nx-theme" content="halloween" /><?php endif; ?>

<title><?= te('meta.tierlist.title') ?></title>
<meta name="description" content="<?= ta('meta.tierlist.desc') ?>" />
<link rel="canonical" href="<?= i18n_url('/tierlist') ?>" />
<?= i18n_alternates('/tierlist') ?>
<meta name="robots" content="index, follow, max-image-preview:large" />

<meta property="og:type" content="website" />
<meta property="og:site_name" content="Maknemy Tier List" />
<meta property="og:locale" content="<?= i18n_og_locale() ?>" />
<meta property="og:url" content="<?= i18n_url('/tierlist') ?>" />
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

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebSite",
  "name": "Maknemy Tier List",
  "alternateName": ["Макнеми тирлист", "Maknemy Tierlist", "Maknemy tier list Blox Fruits"],
  "url": "<?= i18n_url('/') ?>",
  "inLanguage": "<?= i18n_lang() ?>",
  "description": "<?= tj('ld.tierlist.desc') ?>",
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
<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/base.css?v=16" /><?php else: ?><link rel="stylesheet" href="css/base.css?v=16" /><?php endif; ?>
<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/styles.css?v=73" /><?php else: ?><link rel="stylesheet" href="css/styles.css?v=73" /><?php endif; ?>

<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/topbar.css?v=31" /><?php else: ?><link rel="stylesheet" href="css/topbar.css?v=31" /><?php endif; ?>

<script src="js/auth.js?v=2" fetchpriority="high"></script>
<script src="js/topbar.js?v=32" defer fetchpriority="high"></script>

<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/design-page.css?v=43" /><?php else: ?><link rel="stylesheet" href="css/design-page.css?v=43" /><?php endif; ?>
<link rel="stylesheet" href="css/promo-stamp.css?v=3" />
<link rel="stylesheet" href="css/promo-dock-mini.css?v=2" />
<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/halloween.css?v=4" /><?php endif; ?>

<?php if (HALLOWEEN): ?><link rel="stylesheet" href="css/hw/giveaway.css?v=7" /><?php else: ?><link rel="stylesheet" href="css/giveaway.css?v=7" /><?php endif; ?>
<link rel="stylesheet" href="css/gw-card.css?v=1" />
<?php echo metrika_counter_html(); ?>
</head>
<body>

  <button class="like-fab" id="likeBtn" type="button" data-i18n-title="like.title" title="<?= ta('like.title') ?>" aria-pressed="false">
    <svg class="like-heart" viewBox="0 0 26 25" aria-hidden="true"><path d="M8.51699 1.50605e-10C7.71473 1.50605e-10 6.80749 0.0847873 5.9409 0.380955C0.716337 2.09147 -0.963377 7.68627 0.513224 12.2976L0.519428 12.3169L0.526232 12.3361C1.33984 14.6286 2.6507 16.709 4.35587 18.4202L4.36569 18.4301L4.37571 18.4397C6.80673 20.7773 9.45302 22.8156 12.3183 24.5843L12.9917 25L13.6696 24.5918C16.5412 22.8628 19.2383 20.7683 21.6402 18.4496L21.6477 18.4424L21.6551 18.435C23.375 16.7205 24.6859 14.6247 25.4876 12.33L25.4943 12.3109L25.5004 12.2915C26.949 7.69022 25.2777 2.08871 20.0892 0.403435C19.2398 0.120096 18.3633 1.50605e-10 17.5064 1.50605e-10C15.579 -1.27474e-05 14.1523 0.809219 13.0008 1.63401C11.8582 0.815494 10.422 1.50605e-10 8.51699 1.50605e-10Z" fill="currentColor"/></svg>
    <span class="like-count" id="likeCount">0</span>
    <span class="like-join" id="likeJoin" data-i18n="like.join" hidden><?= te('like.join') ?></span>
  </button>

  <button class="donate-fab" id="donateBtn" type="button" data-i18n-title="donate.title" title="<?= ta('donate.title') ?>" hidden>
    <svg class="donate-heart" viewBox="0 0 26 25" aria-hidden="true"><path d="M8.51699 1.50605e-10C7.71473 1.50605e-10 6.80749 0.0847873 5.9409 0.380955C0.716337 2.09147 -0.963377 7.68627 0.513224 12.2976L0.519428 12.3169L0.526232 12.3361C1.33984 14.6286 2.6507 16.709 4.35587 18.4202L4.36569 18.4301L4.37571 18.4397C6.80673 20.7773 9.45302 22.8156 12.3183 24.5843L12.9917 25L13.6696 24.5918C16.5412 22.8628 19.2383 20.7683 21.6402 18.4496L21.6477 18.4424L21.6551 18.435C23.375 16.7205 24.6859 14.6247 25.4876 12.33L25.4943 12.3109L25.5004 12.2915C26.949 7.69022 25.2777 2.08871 20.0892 0.403435C19.2398 0.120096 18.3633 1.50605e-10 17.5064 1.50605e-10C15.579 -1.27474e-05 14.1523 0.809219 13.0008 1.63401C11.8582 0.815494 10.422 1.50605e-10 8.51699 1.50605e-10Z" fill="currentColor"/></svg>
    <span class="donate-label" data-i18n="donate.button"><?= te('donate.button') ?></span>
  </button>

  <header class="mk-top">
    <a class="mk-top-brand" href="<?= u('/') ?>">
      <img class="mk-top-mark" src="assets/design/logo-mk-square.png" alt="" aria-hidden="true" />
      <img class="mk-top-word" src="assets/design/wordmark.svg" alt="MAKNEMY" />
    </a>


    <nav class="mk-top-bar" id="mkTopBar" aria-label="<?= ta('topbar.sections') ?>">
      <ul class="mk-nav">
        <li>
          <a class="mk-pill" href="<?= u('/') ?>">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M18.05 16.0302V8.423C18.05 7.48807 17.644 6.60551 16.9498 6.03152L11.833 1.80094C10.4608 0.666372 8.53926 0.666371 7.16704 1.80094L2.05028 6.03152C1.35606 6.60551 0.950013 7.48807 0.950013 8.423V16.0302C0.950013 17.1457 1.80067 18.05 2.85001 18.05H4.75001C5.79936 18.05 6.65001 17.1994 6.65001 16.15V13.0006C6.65001 11.8851 7.50067 10.9808 8.55002 10.9808H10.45C11.4994 10.9808 12.35 11.8851 12.35 13.0006V16.15C12.35 17.1994 13.2007 18.05 14.25 18.05H16.15C17.1994 18.05 18.05 17.1457 18.05 16.0302Z" stroke="currentColor" stroke-width="1.81101"/></svg>
            <span class="mk-pill-text" data-i18n="nav.home"><?= te('nav.home') ?></span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="<?= u('/tierlist') ?>" aria-current="page">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M8.57627 3.7533C8.57627 3.22702 8.14799 2.79425 7.62582 2.85987C6.45486 3.00701 5.32947 3.42467 4.341 4.08515C3.08735 4.9228 2.11026 6.1134 1.53327 7.50637C0.95628 8.89935 0.805314 10.4321 1.09946 11.9109C1.39361 13.3897 2.11965 14.748 3.18579 15.8142C4.25193 16.8803 5.61027 17.6063 7.08904 17.9005C8.56781 18.1946 10.1006 18.0437 11.4936 17.4667C12.8866 16.8897 14.0771 15.9126 14.9148 14.659C15.5753 13.6705 15.9929 12.5451 16.1401 11.3741C16.2057 10.852 15.7729 10.4237 15.2466 10.4237H9.52918C9.0029 10.4237 8.57627 9.99705 8.57627 9.47077V3.7533Z" stroke="currentColor" stroke-width="1.82067"/><path d="M11.435 1.84748C11.435 1.3212 11.8638 0.887589 12.3847 0.962518C12.934 1.04153 13.4726 1.18898 13.9876 1.40232C14.7969 1.73754 15.5323 2.22887 16.1517 2.84828C16.7711 3.46768 17.2624 4.20302 17.5976 5.0123C17.811 5.52735 17.9584 6.06592 18.0374 6.61527C18.1124 7.13618 17.6787 7.56495 17.1525 7.56495L11.5303 7.56495C11.4777 7.56495 11.435 7.52228 11.435 7.46965V1.84748Z" stroke="currentColor" stroke-width="1.82067"/></svg>
            <span class="mk-pill-text" data-i18n="nav.tierlist"><?= te('nav.tierlist') ?></span>
          </a>
        </li>
        <li>

          <a class="mk-pill" href="<?= u('/trading') ?>">
            <svg viewBox="0 0 18 19" fill="none" aria-hidden="true"><path d="M6.17037 0.943433L4.48309 4.31799M11.8297 0.943433L13.517 4.31799M11.8297 9.4324L8.29262 13.2053L6.17037 11.4903M5.6697 17.9214H12.3304C14.2079 17.9214 15.7998 16.5408 16.0653 14.6821L17.0276 7.94613C17.2711 6.24146 15.9484 4.71631 14.2264 4.71631H3.77368C2.0517 4.71631 0.728943 6.24145 0.972468 7.94613L1.93474 14.6821C2.20027 16.5408 3.79212 17.9214 5.6697 17.9214Z" stroke="currentColor" stroke-width="1.88644" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="mk-pill-text" data-i18n="nav.trading"><?= te('nav.trading') ?></span>
          </a>
        </li>
        <li>

          <a class="mk-pill" href="<?= u('/calculator') ?>">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M5.70001 8.55001V13.3M13.3 10.45V13.3M9.5 5.70001V13.3M4.75001 18.05H14.25C16.3487 18.05 18.05 16.3487 18.05 14.25V4.75001C18.05 2.65134 16.3487 0.950022 14.25 0.950022H4.75001C2.65134 0.950022 0.950022 2.65134 0.950022 4.75001V14.25C0.950022 16.3487 2.65134 18.05 4.75001 18.05Z" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
            <span class="mk-pill-text" data-i18n="nav.calculator"><?= te('nav.calculator') ?></span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="<?= u('/stock') ?>">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M2.4 7.6V16.1C2.4 17.15 3.25 18.05 4.3 18.05H14.7C15.75 18.05 16.6 17.15 16.6 16.1V7.6M7.15 18.05V13.3C7.15 12.25 8 11.4 9.05 11.4H9.95C11 11.4 11.85 12.25 11.85 13.3V18.05M0.95 5.7L2.6 1.9C2.85 1.3 3.45 0.95 4.1 0.95H14.9C15.55 0.95 16.15 1.3 16.4 1.9L18.05 5.7C18.05 7 16.95 8.1 15.65 8.1C14.3 8.1 13.25 7 13.25 5.7C13.25 7 12.15 8.1 10.85 8.1H8.15C6.85 8.1 5.75 7 5.75 5.7C5.75 7 4.7 8.1 3.35 8.1C2.05 8.1 0.95 7 0.95 5.7Z" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="mk-pill-text" data-i18n="nav.stock"><?= te('nav.stock') ?></span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="<?= u('/news') ?>">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M18.05 9.50002C18.05 14.2221 14.222 18.05 9.49995 18.05M18.05 9.50002C18.05 4.77798 14.222 0.950013 9.49995 0.950013M18.05 9.50002C18.05 7.92601 14.222 6.65002 9.49995 6.65002C4.77792 6.65002 0.949949 7.92601 0.949949 9.50002M18.05 9.50002C18.05 11.074 14.222 12.35 9.49995 12.35C4.77792 12.35 0.949949 11.074 0.949949 9.50002M9.49995 18.05C4.77792 18.05 0.949949 14.2221 0.949949 9.50002M9.49995 18.05C11.074 18.05 12.35 14.2221 12.35 9.50002C12.35 4.77798 11.074 0.950013 9.49995 0.950013M9.49995 18.05C7.92594 18.05 6.64995 14.2221 6.64995 9.50002C6.64995 4.77798 7.92594 0.950013 9.49995 0.950013M0.949949 9.50002C0.949949 4.77798 4.77792 0.950013 9.49995 0.950013" stroke="currentColor" stroke-width="1.9"/></svg>
            <span class="mk-pill-text" data-i18n="nav.news"><?= te('nav.news') ?></span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="<?= u('/ads') ?>">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M5.6 6.9V12.1M5.6 12.1L6.75 16.55C6.98 17.43 7.77 18.05 8.68 18.05C9.98 18.05 10.93 16.82 10.6 15.56L10.1 13.65M2.95 6.9H5.6L12.85 3.05C13.88 2.5 15.1 3.25 15.1 4.4V14.6C15.1 15.75 13.88 16.5 12.85 15.95L5.6 12.1H2.95C1.85 12.1 0.95 11.2 0.95 10.1V8.9C0.95 7.8 1.85 6.9 2.95 6.9ZM17.45 7.3C18.18 8.65 18.18 10.35 17.45 11.7" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="mk-pill-text" data-i18n="nav.promote"><?= te('nav.promote') ?></span>
          </a>
        </li>
        <li class="mk-nav-lang"><?= i18n_lang_menu() ?></li>
      </ul>

      <a class="mk-promote" href="<?= u('/giveaways') ?>" data-i18n="nav.giveaways"><?= te('nav.giveaways') ?></a>

      <a class="mk-tg" href="https://t.me/theMaknemy" target="_blank" rel="noopener" aria-label="Telegram" title="Telegram">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill-rule="evenodd" fill="currentColor" d="M21.4 3.7 2.9 10.9c-.9.4-.9 1.5 0 1.8l4.5 1.4 1.7 5.4c.2.6 1 .8 1.5.3l2.5-2.4 4.5 3.3c.6.4 1.4.1 1.6-.6l3.2-15.4c.2-.8-.4-1.3-1-1Zm-4.2 3.9-7.9 7 .5 3 .6-2.4 6.8-7.6Z"/></svg>
      </a>

      <a class="mk-chat" href="<?= u('/chat') ?>" data-i18n-label="nav.chat" aria-label="<?= ta('nav.chat') ?>">
        <svg viewBox="0 0 25 25" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M12.0833 0C5.40989 0 0 5.40989 0 12.0833C0 14.2768 0.585445 16.3362 1.60861 18.1109C1.817 18.4723 1.85274 18.9124 1.67689 19.2907L0.645317 21.5102C0.0119158 22.7086 0.878898 24.1667 2.24942 24.1667H12.0833C18.7568 24.1667 24.1667 18.7568 24.1667 12.0833C24.1667 5.40989 18.7568 0 12.0833 0ZM8.45833 8.45833C7.79099 8.45833 7.25 8.99932 7.25 9.66667C7.25 10.334 7.79099 10.875 8.45833 10.875H10.875C11.5423 10.875 12.0833 10.334 12.0833 9.66667C12.0833 8.99932 11.5423 8.45833 10.875 8.45833H8.45833ZM8.45833 13.2917C7.79099 13.2917 7.25 13.8327 7.25 14.5C7.25 15.1673 7.79099 15.7083 8.45833 15.7083H15.7083C16.3757 15.7083 16.9167 15.1673 16.9167 14.5C16.9167 13.8327 16.3757 13.2917 15.7083 13.2917H8.45833Z" fill="currentColor"/></svg>
      </a>

      <button class="mk-avatar" type="button" aria-label="<?= ta('topbar.profile') ?>" data-soon data-i18n-title="topbar.soon" title="<?= ta('topbar.soon') ?>">
        <svg viewBox="0 0 34 34" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M17.0003 2.83325C13.0883 2.83325 9.91699 6.00457 9.91699 9.91659C9.91699 13.8286 13.0883 16.9999 17.0003 16.9999C20.9123 16.9999 24.0837 13.8286 24.0837 9.91659C24.0837 6.00457 20.9123 2.83325 17.0003 2.83325Z" fill="currentColor"/><path fill-rule="evenodd" clip-rule="evenodd" d="M12.7503 18.4167C10.3947 18.4167 8.12945 19.4913 6.80192 21.109C6.12816 21.9301 5.65451 22.946 5.61326 24.072C5.57114 25.2218 5.98621 26.3442 6.8422 27.3234C8.92833 29.7099 12.2591 31.1667 17.0003 31.1667C21.7415 31.1667 25.0723 29.7099 27.1584 27.3234C28.0144 26.3442 28.4294 25.2218 28.3873 24.072C28.3461 22.946 27.8724 21.9301 27.1987 21.109C25.8711 19.4913 23.6058 18.4167 21.2503 18.4167H12.7503Z" fill="currentColor"/></svg>
      </button>
    </nav>

    <button class="mk-top-toggle" type="button" id="mkTopToggle"
            aria-expanded="false" aria-controls="mkTopBar"
            data-i18n-label="topbar.showNav" aria-label="<?= ta('topbar.showNav') ?>"
            data-i18n-title="topbar.showNav" title="<?= ta('topbar.showNav') ?>">
    </button>
  </header>

  <div class="toolbar" id="toolbar">
    <div class="tb-brand">MAKNEMY<span>EDITOR</span></div>

    <nav class="nav-seg" aria-label="<?= ta('topbar.sections') ?>">
      <a href="<?= u('/tierlist') ?>" aria-current="page" data-i18n="news.navTierlist"><?= te('news.navTierlist') ?></a>
      <a href="<?= u('/news') ?>" data-i18n="news.navNews"><?= te('news.navNews') ?></a>
    </nav>

    <div class="tb-group" id="tbEdit" hidden>
      <button class="btn" id="btnAddTier" data-i18n="admin.addTier" data-i18n-title="admin.addTierTitle" title="<?= ta('admin.addTierTitle') ?>"><?= te('admin.addTier') ?></button>
      <button class="btn" id="btnAddItem" data-i18n="admin.addItem" data-i18n-title="admin.addItemTitle" title="<?= ta('admin.addItemTitle') ?>"><?= te('admin.addItem') ?></button>
      <button class="btn" id="btnSort" data-i18n="admin.sort" data-i18n-title="admin.sortTitle" title="<?= ta('admin.sortTitle') ?>"><?= te('admin.sort') ?></button>
    </div>

    <div class="tb-group filters" id="filters">
      <button class="chip" data-f="configurators" data-i18n="filters.configurators" data-i18n-title="filters.configuratorsTitle" title="<?= ta('filters.configuratorsTitle') ?>"><?= te('filters.configurators') ?></button>
      <button class="chip" data-f="fruits" data-i18n="filters.fruits" data-i18n-title="filters.fruitsTitle" title="<?= ta('filters.fruitsTitle') ?>"><?= te('filters.fruits') ?></button>
      <button class="chip" data-f="perms" data-i18n="filters.perms" data-i18n-title="filters.permsTitle" title="<?= ta('filters.permsTitle') ?>"><?= te('filters.perms') ?></button>
      <button class="chip" data-f="passes" data-i18n="filters.passes" data-i18n-title="filters.passesTitle" title="<?= ta('filters.passesTitle') ?>"><?= te('filters.passes') ?></button>
      <button class="chip all" data-f="all" data-i18n="filters.all" data-i18n-title="filters.allTitle" title="<?= ta('filters.allTitle') ?>"><?= te('filters.all') ?></button>
    </div>

    <div class="tb-group" id="tbToggles" hidden>
      <label class="switch" data-i18n-title="admin.autoSortTitle" title="<?= ta('admin.autoSortTitle') ?>">
        <input type="checkbox" id="autoSortToggle" checked />
        <span class="track"><span class="thumb"></span></span>
        <span class="switch-label" data-i18n="admin.autoSort"><?= te('admin.autoSort') ?></span>
      </label>
      <label class="switch" id="editToggleWrap" data-i18n-title="admin.editingTitle" title="<?= ta('admin.editingTitle') ?>">
        <input type="checkbox" id="editToggle" />
        <span class="track"><span class="thumb"></span></span>
        <span class="switch-label" data-i18n="admin.editing"><?= te('admin.editing') ?></span>
      </label>
    </div>

    <div class="tb-group" id="tbPublish" hidden>
      <button class="btn save-btn clean" id="btnSave" data-i18n-title="admin.saveTitle" title="<?= ta('admin.saveTitle') ?>"><?= te('admin.saved') ?></button>
    </div>

    <div class="tb-group" id="tbPng" hidden>
      <button class="btn primary" id="btnPng" data-i18n="admin.png" data-i18n-title="admin.pngTitle" title="<?= ta('admin.pngTitle') ?>"><?= te('admin.png') ?></button>
    </div>
    <div class="tb-group" id="tbAdminActions" hidden>
      <button class="btn" id="btnExport" data-i18n="admin.export" data-i18n-title="admin.exportTitle" title="<?= ta('admin.exportTitle') ?>"><?= te('admin.export') ?></button>
      <button class="btn" id="btnImport" data-i18n="admin.import" data-i18n-title="admin.importTitle" title="<?= ta('admin.importTitle') ?>"><?= te('admin.import') ?></button>
      <button class="btn danger" id="btnReset" data-i18n="admin.reset" data-i18n-title="admin.resetTitle" title="<?= ta('admin.resetTitle') ?>"><?= te('admin.reset') ?></button>
    </div>

    <input type="file" id="importFile" accept="application/json" hidden />
    <input type="file" id="tierLogoFile" accept="image/*" hidden />
    <input type="file" id="ptnImgFile" accept="image/*" hidden />
    <span class="tb-saved" id="savedHint"></span>
  </div>

  <div class="stage-wrap">
    <div class="stage" id="stage">
      <div class="petals" aria-hidden="true"></div>

      <header class="tl-header">
        <img class="bf-logo" src="assets/poster/logo-bf.png" alt="Blox Fruits" />
        <div class="title-block">
          <div class="date" id="tlDate" spellcheck="false" data-i18n-title="stage.dateTitle" title="<?= ta('stage.dateTitle') ?>">17.02.2026</div>
          <h1 class="tl-title" id="tlTitle">MAKNEMY<br/>TIER LIST</h1>
        </div>
        <img class="brand-logo" src="assets/poster/marks.png" data-i18n-alt="stage.brandAlt" alt="<?= ta('stage.brandAlt') ?>" />
      </header>

      <main class="tiers" id="tiers" data-gwc-tiers></main>

      <section class="legend">
        <h2 class="legend-title" data-i18n="legend.title"><?= te('legend.title') ?></h2>
        <div class="legend-grid">
          <div class="legend-col lc-types">
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-fr.svg" alt="FR" /><span class="lgl" data-i18n="legend.fv"><?= te('legend.fv') ?></span></div>
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-cs.svg" alt="CS" /><span class="lgl" data-i18n="legend.cs"><?= te('legend.cs') ?></span></div>
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-cm.svg" alt="CM" /><span class="lgl" data-i18n="legend.cm"><?= te('legend.cm') ?></span></div>
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-ms.svg" alt="MS" /><span class="lgl" data-i18n="legend.ms"><?= te('legend.ms') ?></span></div>
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-pm.svg" alt="PM" /><span class="lgl" data-i18n="legend.pm"><?= te('legend.pm') ?></span></div>
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-gp.svg" alt="GP" /><span class="lgl" data-i18n="legend.gp"><?= te('legend.gp') ?></span></div>
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-cr.svg" alt="CR" /><span class="lgl" data-i18n="legend.cr"><?= te('legend.cr') ?></span></div>
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-vh.svg" alt="VH" /><span class="lgl" data-i18n="legend.vh"><?= te('legend.vh') ?></span></div>
          </div>

          <div class="legend-col lc-demand">

            <div class="lg"><span class="lgd d-neon"></span><span class="lgl" data-i18n="legend.neon"><?= te('legend.neon') ?></span></div>
            <div class="lg"><span class="lgd d-green"></span><span class="lgl" data-i18n="legend.good"><?= te('legend.good') ?></span></div>
            <div class="lg"><span class="lgd d-yellow"></span><span class="lgl" data-i18n="legend.mid"><?= te('legend.mid') ?></span></div>
            <div class="lg"><span class="lgd d-orange"></span><span class="lgl" data-i18n="legend.low"><?= te('legend.low') ?></span></div>
            <div class="lg"><span class="lgd d-red"></span><span class="lgl" data-i18n="legend.bad"><?= te('legend.bad') ?></span></div>
          </div>

          <div class="legend-col lc-trends">
            <div class="lg"><img class="trend tr-wip" src="assets/design/legend/trend-wip.svg" alt="" /><span class="lgl" data-i18n="legend.wip"><?= te('legend.wip') ?></span></div>
            <div class="lg"><img class="trend tr-up" src="assets/design/legend/trend-up.svg" alt="" /><span class="lgl" data-i18n="legend.up"><?= te('legend.up') ?></span></div>
            <div class="lg"><img class="trend tr-swap" src="assets/design/legend/trend-swap.svg" alt="" /><span class="lgl" data-i18n="legend.swap"><?= te('legend.swap') ?></span></div>
            <div class="lg"><img class="trend tr-down" src="assets/design/legend/trend-down.svg" alt="" /><span class="lgl" data-i18n="legend.down"><?= te('legend.down') ?></span></div>
            <div class="lg"><img class="trend tr-new" src="assets/design/legend/trend-new.png" alt="" /><span class="lgl" data-i18n="legend.new"><?= te('legend.new') ?></span></div>
          </div>
        </div>
      </section>

      <footer class="tl-footer" id="tlFooter"></footer>

    </div>
  </div>

  <footer class="mk-foot">
    <img class="mk-foot-mark" src="assets/design/logo-mk-square.png" alt="MAKNEMY" />
    <ul class="mk-foot-roles">
      <li><span data-i18n="site.footAuthor"><?= te('site.footAuthor') ?></span><span class="mk-foot-nick">MKSVTN</span></li>
      <li><span data-i18n="site.footDesigner"><?= te('site.footDesigner') ?></span><span class="mk-foot-nick">DANIKTOR</span></li>
      <li><span data-i18n="site.footAnalyst"><?= te('site.footAnalyst') ?></span><span class="mk-foot-nick">GLH</span></li>
      <li><span data-i18n="site.footAnalystAide"><?= te('site.footAnalystAide') ?></span><span class="mk-foot-nick" data-i18n="site.footHiring"><?= te('site.footHiring') ?></span></li>
      <li><span data-i18n="site.footCoder"><?= te('site.footCoder') ?></span><span class="mk-foot-nick">The Fool</span></li>
    </ul>
    <p class="mk-foot-tagline" data-i18n="site.footTagline"><?= te('site.footTagline') ?></p>

    <p class="mk-foot-legal">
      <a href="/privacy" data-i18n="site.footPrivacy"><?= te('site.footPrivacy') ?></a>
      <a href="/terms" data-i18n="site.footTerms"><?= te('site.footTerms') ?></a>
    </p>
  </footer>

  <aside class="ptn-rail ptn-rail-l" id="promoRailL" hidden
         data-i18n-label="promo.rail" aria-label="<?= ta('promo.rail') ?>"></aside>
  <aside class="ptn-rail ptn-rail-r" id="promoRailR" hidden
         data-i18n-label="promo.rail" aria-label="<?= ta('promo.rail') ?>"></aside>

  <div class="ptn-dock" id="promoDock" hidden
       data-i18n-label="promo.region" aria-label="<?= ta('promo.region') ?>"></div>

  <div class="modal-backdrop" id="modal" hidden>
    <div class="modal">
      <div class="modal-head">
        <h3 id="modalTitle" data-i18n="modal.itemTitle"><?= te('modal.itemTitle') ?></h3>
        <button class="icon-btn" id="modalClose" data-i18n-title="modal.close" title="<?= ta('modal.close') ?>">✕</button>
      </div>
      <div class="modal-body">
        <div class="field icon-field">
          <div class="icon-preview"><img id="mIconPreview" alt="" /></div>
          <div class="icon-actions">
            <label class="btn small"><span data-i18n="modal.iconUpload"><?= te('modal.iconUpload') ?></span><input type="file" id="mIconFile" accept="image/*" hidden /></label>
            <button class="btn small ghost" id="mIconReset" data-i18n="modal.iconReset"><?= te('modal.iconReset') ?></button>
          </div>
        </div>
        <div class="field">
          <label data-i18n="modal.name"><?= te('modal.name') ?></label>
          <input type="text" id="mName" data-i18n-placeholder="modal.namePlaceholder" placeholder="<?= ta('modal.namePlaceholder') ?>" />
        </div>
        <div class="field">
          <label data-i18n="modal.value"><?= te('modal.value') ?></label>
          <input type="text" id="mValue" inputmode="numeric" data-i18n-placeholder="modal.valuePlaceholder" placeholder="<?= ta('modal.valuePlaceholder') ?>" />
        </div>
        <div class="field">
          <label data-i18n="modal.desc"><?= te('modal.desc') ?></label>
          <textarea id="mDesc" rows="3" data-i18n-placeholder="modal.descPlaceholder" placeholder="<?= ta('modal.descPlaceholder') ?>"></textarea>
        </div>
        <div class="field">
          <label data-i18n="modal.descEn"><?= te('modal.descEn') ?></label>
          <textarea id="mDescEn" rows="3" data-i18n-placeholder="modal.descEnPlaceholder" placeholder="<?= ta('modal.descEnPlaceholder') ?>"></textarea>
        </div>

        <div class="field">
          <label data-i18n="modal.terms"><?= te('modal.terms') ?></label>
          <textarea id="mTerms" rows="3" data-i18n-placeholder="modal.termsPlaceholder" placeholder="<?= ta('modal.termsPlaceholder') ?>"></textarea>
        </div>
        <div class="field">
          <label data-i18n="modal.termsEn"><?= te('modal.termsEn') ?></label>
          <textarea id="mTermsEn" rows="3" data-i18n-placeholder="modal.termsEnPlaceholder" placeholder="<?= ta('modal.termsEnPlaceholder') ?>"></textarea>
        </div>

        <div class="field">
          <label data-i18n="modal.tag"><?= te('modal.tag') ?></label>
          <input type="text" id="mTag" maxlength="24" data-i18n-placeholder="modal.tagPlaceholder" placeholder="<?= ta('modal.tagPlaceholder') ?>" />
        </div>
        <div class="field">
          <label data-i18n="modal.tagEn"><?= te('modal.tagEn') ?></label>
          <input type="text" id="mTagEn" maxlength="24" data-i18n-placeholder="modal.tagEnPlaceholder" placeholder="<?= ta('modal.tagEnPlaceholder') ?>" />
        </div>
        <div class="field">
          <label data-i18n="modal.fruitType"><?= te('modal.fruitType') ?></label>
          <div class="seg seg-toggle" id="mFruit">
            <button data-v="f" class="active"><span data-i18n="modal.fruitPlain"><?= te('modal.fruitPlain') ?></span> <small>FR</small></button>
            <button data-v="p"><span data-i18n="modal.fruitPerm"><?= te('modal.fruitPerm') ?></span> <small>PM</small></button>
          </div>
        </div>
        <div class="field">
          <label data-i18n="modal.category"><?= te('modal.category') ?></label>

          <div class="seg" id="mType2">
            <button data-v="" class="active">—</button>
            <button data-v="cs" class="t-cs" data-i18n="modal.catConfigSkin"><?= te('modal.catConfigSkin') ?></button>
            <button data-v="cm" class="t-cm" data-i18n="modal.catConfigMutation"><?= te('modal.catConfigMutation') ?></button>
            <button data-v="ms" class="t-ms" data-i18n="modal.catMutationSkin"><?= te('modal.catMutationSkin') ?></button>
            <button data-v="cr" class="t-cr" data-i18n="modal.catChromatic"><?= te('modal.catChromatic') ?></button>
            <button data-v="gp" class="t-gp" data-i18n="modal.catPass"><?= te('modal.catPass') ?></button>
            <button data-v="vh" class="t-vh" data-i18n="modal.catVoucher"><?= te('modal.catVoucher') ?></button>
          </div>
        </div>
        <div class="field">
          <label data-i18n="modal.demand"><?= te('modal.demand') ?></label>

          <div class="seg" id="mDemand">
            <button data-v="" class="active">—</button>
            <button data-v="neon" data-i18n-title="legend.neon" title="<?= ta('legend.neon') ?>"><img class="dot" src="assets/dot-neon.png" alt="" /></button>
            <button data-v="green"><img class="dot" src="assets/dot-green.png" alt="" /></button>
            <button data-v="yellow"><img class="dot" src="assets/dot-yellow.png" alt="" /></button>
            <button data-v="orange"><img class="dot" src="assets/dot-orange.png" alt="" /></button>
            <button data-v="red"><img class="dot" src="assets/dot-red.png" alt="" /></button>
          </div>
        </div>
        <div class="field">
          <label data-i18n="modal.trend"><?= te('modal.trend') ?></label>

          <div class="seg" id="mTrend">
            <button data-v="" class="active">—</button>
            <button data-flag="wip" data-i18n-title="modal.wipTitle" title="<?= ta('modal.wipTitle') ?>"><img class="trend tr-wip" src="assets/design/legend/trend-wip.svg" alt="?" /></button>
            <button data-v="up" data-i18n-title="legend.up" title="<?= ta('legend.up') ?>"><img class="trend" src="assets/design/legend/trend-up.svg" alt="" /></button>
            <button data-v="swap" data-i18n-title="legend.swap" title="<?= ta('legend.swap') ?>"><img class="trend tr-swap" src="assets/design/legend/trend-swap.svg" alt="" /></button>
            <button data-v="down" data-i18n-title="legend.down" title="<?= ta('legend.down') ?>"><img class="trend" src="assets/design/legend/trend-down.svg" alt="" /></button>
            <button data-flag="flag" data-i18n-title="modal.newTitle" title="<?= ta('modal.newTitle') ?>"><img class="trend tr-new" src="assets/design/legend/trend-new.png" alt="NEW" /></button>
          </div>
        </div>
        <div class="field">
          <label data-i18n="modal.glow"><?= te('modal.glow') ?></label>
          <div class="seg" id="mGlow">
            <button data-v="" class="active">—</button>
            <button data-v="gold" class="seg-glow glow-gold" data-i18n="modal.glowGold" data-i18n-title="modal.glowTitle" title="<?= ta('modal.glowTitle') ?>"><?= te('modal.glowGold') ?></button>
            <button data-v="green" class="seg-glow glow-green" data-i18n="modal.glowGreen" data-i18n-title="modal.glowTitle" title="<?= ta('modal.glowTitle') ?>"><?= te('modal.glowGreen') ?></button>
            <button data-v="red" class="seg-glow glow-red" data-i18n="modal.glowRed" data-i18n-title="modal.glowTitle" title="<?= ta('modal.glowTitle') ?>"><?= te('modal.glowRed') ?></button>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn danger" id="mDelete" data-i18n="modal.delete"><?= te('modal.delete') ?></button>
        <button class="btn primary" id="mSave" data-i18n="modal.done"><?= te('modal.done') ?></button>
      </div>
    </div>
  </div>

  <div class="modal-backdrop" id="viewModal" hidden>
    <div class="modal vmodal">
      <div class="vmodal-head">
        <div class="vmodal-name" id="vName"></div>
        <button class="icon-btn vmodal-close" id="viewClose" data-i18n-title="modal.close" title="<?= ta('modal.close') ?>">✕</button>
      </div>
      <div class="vmodal-body">
        <div class="vmodal-icon"><img id="vIcon" alt="" /></div>
        <div class="vmodal-rule"></div>
        <div class="vmodal-pill vmodal-kind" id="vKind">
          <span class="vmodal-pill-main" id="vKindMain"></span>
          <span class="vmodal-pill-sub" id="vKindSub"></span>
        </div>
        <div class="vmodal-pills">
          <div class="vmodal-pill"><span class="vmodal-pill-main" id="vValue"></span></div>
          <div class="vmodal-pill" id="vTagPill" hidden><span class="vmodal-pill-main" id="vTag"></span></div>
        </div>
        <section class="vmodal-panel">
          <div class="vmodal-panel-title" data-i18n="view.descTitle"><?= te('view.descTitle') ?></div>
          <div class="vmodal-panel-text" id="vDesc"></div>
        </section>
        <section class="vmodal-panel" id="vTermsPanel" hidden>

          <svg class="vmodal-panel-icon" viewBox="0 0 69 61" aria-hidden="true" focusable="false">
            <mask id="vTermsWarn" maskUnits="userSpaceOnUse" x="0" y="0" width="69" height="61">
              <rect width="69" height="61" fill="#fff" />
              <rect x="31" y="17" width="7" height="22" rx="3.5" fill="#000" />
              <circle cx="34.5" cy="47" r="4" fill="#000" />
            </mask>
            <path d="M34.5 6 L63.5 55.5 L5.5 55.5 Z" fill="currentColor" stroke="currentColor"
                  stroke-width="7" stroke-linejoin="round" mask="url(#vTermsWarn)" />
          </svg>
          <div class="vmodal-panel-title" data-i18n="view.termsTitle"><?= te('view.termsTitle') ?></div>
          <div class="vmodal-panel-text" id="vTerms"></div>
        </section>
      </div>
    </div>
  </div>

  <div class="modal-backdrop" id="donateModal" hidden>
    <div class="modal dmodal">
      <button class="icon-btn dmodal-close" id="donateClose" data-i18n-title="modal.close" title="<?= ta('modal.close') ?>">✕</button>
      <div class="dmodal-body">
        <div class="dmodal-title" data-i18n="donate.modalTitle"><?= te('donate.modalTitle') ?></div>
        <div class="dmodal-sub" data-i18n="donate.modalSub"><?= te('donate.modalSub') ?></div>
        <div class="dmodal-links">
          <a class="btn primary dmodal-link" id="donateLinkDA" href="#" target="_blank" rel="noopener">DonationAlerts</a>
          <a class="btn dmodal-link" id="donateLinkHub" href="#" target="_blank" rel="noopener" data-i18n="donate.linkHub"><?= te('donate.linkHub') ?></a>
        </div>

        <div class="dmodal-tools edit-only">
          <button class="btn small" id="donateEditDA" data-i18n="donate.editDA" data-i18n-title="donate.editDATitle" title="<?= ta('donate.editDATitle') ?>"><?= te('donate.editDA') ?></button>
          <button class="btn small" id="donateEditHub" data-i18n="donate.editHub" data-i18n-title="donate.editHubTitle" title="<?= ta('donate.editHubTitle') ?>"><?= te('donate.editHub') ?></button>
          <label class="btn small" data-i18n-title="donate.qrUploadTitle" title="<?= ta('donate.qrUploadTitle') ?>"><span data-i18n="donate.qrUpload"><?= te('donate.qrUpload') ?></span><input type="file" id="donateQrFile" accept="image/*" hidden /></label>
          <button class="btn small ghost" id="donateQrReset" data-i18n="donate.qrReset" data-i18n-title="donate.qrResetTitle" title="<?= ta('donate.qrResetTitle') ?>"><?= te('donate.qrReset') ?></button>
        </div>
        <div class="dmodal-qr">
          <img id="donateQr" src="assets/qr-donate.png?v=1" data-i18n-alt="donate.qrAlt" alt="<?= ta('donate.qrAlt') ?>" />
          <div class="dmodal-qr-cap" data-i18n="donate.qrCaption"><?= te('donate.qrCaption') ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="ptn-pop" id="promoPop" hidden role="dialog" aria-modal="true"
       data-i18n-label="promo.popLabel" aria-label="<?= ta('promo.popLabel') ?>"
       aria-labelledby="promoPopTitle">
    <div class="ptn-pop-card">
      <button class="ptn-pop-close" id="promoPopClose" type="button"
              data-i18n-label="promo.close" data-i18n-title="promo.close"
              aria-label="<?= ta('promo.close') ?>" title="<?= ta('promo.close') ?>">✕</button>
      <span class="ptn-chip" data-i18n="ad.chip"><?= te('ad.chip') ?></span>
      <div class="ptn-pop-media"><img class="ptn-pop-img" id="promoPopImg" alt="" data-i18n-alt="ad.imageAlt" /></div>

      <div class="ptn-pop-title" id="promoPopTitle"></div>
      <a class="btn primary ptn-pop-cta" id="promoPopCta" href="#" target="_blank" rel="noopener nofollow"></a>

      <span class="ptn-erid" id="promoPopErid" hidden></span>
    </div>
  </div>

  <?= i18n_dict_tag() ?>
  <script src="js/i18n.js?v=84" fetchpriority="high"></script>
  <script src="js/content.js?v=4" fetchpriority="high"></script>
  <script src="js/tiers.js?v=2" fetchpriority="high"></script>

  <script src="js/promo.js?v=32" fetchpriority="high"></script>
  <script src="js/promo-dock.js?v=11" fetchpriority="high"></script>

  <script src="js/protect.js?v=3" fetchpriority="high"></script>
  <script src="js/app.js?v=101" fetchpriority="high"></script>
  <script src="js/news-blocks.js?v=4" fetchpriority="high"></script>
  <script src="js/giveaway.js?v=15" fetchpriority="high"></script>
  <script src="js/giveaway-pop.js?v=1" fetchpriority="high"></script>
  <script src="js/gw-card.js?v=3" fetchpriority="high"></script>
</body>
</html>
