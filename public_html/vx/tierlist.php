<?php
// Шаблон тирлиста для сайта со скином vx (Vox Seas). Подключается из
// index.php, когда site_skin() === 'vx'; $og и $nxRev посчитаны там.
// Разметку читают js/app.js и js/topbar.js — id, классы и data-атрибуты
// те же, что в index.php. Рекламных мест, розыгрышей и доната здесь нет.
$vxName = site_name();
$vxEsc = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
// Запасные тексты превью в index.php написаны под основной сайт.
$vxOgTitle = stripos((string)$og['title'], 'maknemy') !== false ? t('vx.meta.tierlist.title') : (string)$og['title'];
$vxOgDesc = (string)$og['description'] === t('og.tierlist.desc') ? t('vx.meta.tierlist.desc') : (string)$og['description'];
$vxCard = og_brand_card();
$vxLd = json_encode([
    '@context'    => 'https://schema.org',
    '@type'       => 'WebSite',
    'name'        => $vxName,
    'url'         => i18n_url('/tierlist'),
    'inLanguage'  => i18n_lang(),
    'description' => t('vx.meta.tierlist.desc'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
$vxPlay = 'https://www.roblox.com/games/104067066727140/Vox-Seas';
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
<meta name="theme-color" content="#04121f" />

<title><?= te('vx.meta.tierlist.title') ?></title>
<meta name="description" content="<?= ta('vx.meta.tierlist.desc') ?>" />
<link rel="canonical" href="<?= i18n_url('/tierlist') ?>" />
<?= i18n_alternates('/tierlist') ?>
<meta name="robots" content="index, follow, max-image-preview:large" />

<meta property="og:type" content="website" />
<meta property="og:site_name" content="<?= $vxEsc($vxName) ?>" />
<meta property="og:locale" content="<?= i18n_og_locale() ?>" />
<meta property="og:url" content="<?= i18n_url('/tierlist') ?>" />
<meta property="og:title" content="<?= $vxEsc($vxOgTitle) ?>" />
<meta property="og:description" content="<?= $vxEsc($vxOgDesc) ?>" />
<meta property="og:image" content="<?= $vxEsc($vxCard['image']) ?>" />
<meta property="og:image:width" content="<?= (int)$vxCard['imageWidth'] ?>" />
<meta property="og:image:height" content="<?= (int)$vxCard['imageHeight'] ?>" />
<meta property="og:image:type" content="<?= $vxEsc($vxCard['imageType']) ?>" />
<meta property="og:image:alt" content="<?= $vxEsc($vxOgTitle) ?>" />
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="<?= $vxEsc($vxOgTitle) ?>" />
<meta name="twitter:description" content="<?= $vxEsc($vxOgDesc) ?>" />
<meta name="twitter:image" content="<?= $vxEsc($vxCard['image']) ?>" />

<script type="application/ld+json"><?= $vxLd ?></script>

<link rel="icon" type="image/png" href="/assets/vx/favicon.png" />
<link rel="apple-touch-icon" href="/assets/vx/favicon.png" />
<link rel="preload" as="font" type="font/woff2" href="/assets/vx/fonts/luckiest-guy.woff2" crossorigin />
<link rel="stylesheet" href="css/base.css?v=16" />
<link rel="stylesheet" href="css/styles.css?v=73" />
<link rel="stylesheet" href="css/vx/vx.css?v=3" />
<link rel="stylesheet" href="css/vx/tierlist.css?v=2" />

<script src="js/auth.js?v=2" fetchpriority="high"></script>
<script src="js/topbar.js?v=32" defer fetchpriority="high"></script>
</head>
<body class="vx vx-tierlist">

  <div class="vx-sea" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>

  <button class="like-fab" id="likeBtn" type="button" data-i18n-title="like.title" title="<?= ta('like.title') ?>" aria-pressed="false">
    <svg class="like-heart" viewBox="0 0 26 25" aria-hidden="true"><path d="M8.51699 1.50605e-10C7.71473 1.50605e-10 6.80749 0.0847873 5.9409 0.380955C0.716337 2.09147 -0.963377 7.68627 0.513224 12.2976L0.519428 12.3169L0.526232 12.3361C1.33984 14.6286 2.6507 16.709 4.35587 18.4202L4.36569 18.4301L4.37571 18.4397C6.80673 20.7773 9.45302 22.8156 12.3183 24.5843L12.9917 25L13.6696 24.5918C16.5412 22.8628 19.2383 20.7683 21.6402 18.4496L21.6477 18.4424L21.6551 18.435C23.375 16.7205 24.6859 14.6247 25.4876 12.33L25.4943 12.3109L25.5004 12.2915C26.949 7.69022 25.2777 2.08871 20.0892 0.403435C19.2398 0.120096 18.3633 1.50605e-10 17.5064 1.50605e-10C15.579 -1.27474e-05 14.1523 0.809219 13.0008 1.63401C11.8582 0.815494 10.422 1.50605e-10 8.51699 1.50605e-10Z" fill="currentColor"/></svg>
    <span class="like-count" id="likeCount">0</span>
    <span class="like-join" id="likeJoin" data-i18n="like.join" hidden><?= te('like.join') ?></span>
  </button>

  <header class="mk-top">
    <a class="mk-top-brand" href="<?= u('/tierlist') ?>">
      <img src="assets/vx/logo.png" alt="Vox Seas" width="480" height="367" />
    </a>

    <nav class="mk-top-bar" id="mkTopBar" aria-label="<?= ta('topbar.sections') ?>">
      <ul class="mk-nav">
        <li>
          <a class="mk-pill" href="<?= u('/tierlist') ?>" aria-current="page">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M8.57627 3.7533C8.57627 3.22702 8.14799 2.79425 7.62582 2.85987C6.45486 3.00701 5.32947 3.42467 4.341 4.08515C3.08735 4.9228 2.11026 6.1134 1.53327 7.50637C0.95628 8.89935 0.805314 10.4321 1.09946 11.9109C1.39361 13.3897 2.11965 14.748 3.18579 15.8142C4.25193 16.8803 5.61027 17.6063 7.08904 17.9005C8.56781 18.1946 10.1006 18.0437 11.4936 17.4667C12.8866 16.8897 14.0771 15.9126 14.9148 14.659C15.5753 13.6705 15.9929 12.5451 16.1401 11.3741C16.2057 10.852 15.7729 10.4237 15.2466 10.4237H9.52918C9.0029 10.4237 8.57627 9.99705 8.57627 9.47077V3.7533Z" stroke="currentColor" stroke-width="1.82067"/><path d="M11.435 1.84748C11.435 1.3212 11.8638 0.887589 12.3847 0.962518C12.934 1.04153 13.4726 1.18898 13.9876 1.40232C14.7969 1.73754 15.5323 2.22887 16.1517 2.84828C16.7711 3.46768 17.2624 4.20302 17.5976 5.0123C17.811 5.52735 17.9584 6.06592 18.0374 6.61527C18.1124 7.13618 17.6787 7.56495 17.1525 7.56495L11.5303 7.56495C11.4777 7.56495 11.435 7.52228 11.435 7.46965V1.84748Z" stroke="currentColor" stroke-width="1.82067"/></svg>
            <span class="mk-pill-text" data-i18n="nav.tierlist"><?= te('nav.tierlist') ?></span>
          </a>
        </li>
        <li>
          <a class="mk-pill" href="<?= u('/news') ?>">
            <svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M18.05 9.50002C18.05 14.2221 14.222 18.05 9.49995 18.05M18.05 9.50002C18.05 4.77798 14.222 0.950013 9.49995 0.950013M18.05 9.50002C18.05 7.92601 14.222 6.65002 9.49995 6.65002C4.77792 6.65002 0.949949 7.92601 0.949949 9.50002M18.05 9.50002C18.05 11.074 14.222 12.35 9.49995 12.35C4.77792 12.35 0.949949 11.074 0.949949 9.50002M9.49995 18.05C4.77792 18.05 0.949949 14.2221 0.949949 9.50002M9.49995 18.05C11.074 18.05 12.35 14.2221 12.35 9.50002C12.35 4.77798 11.074 0.950013 9.49995 0.950013M9.49995 18.05C7.92594 18.05 6.64995 14.2221 6.64995 9.50002C6.64995 4.77798 7.92594 0.950013 9.49995 0.950013M0.949949 9.50002C0.949949 4.77798 4.77792 0.950013 9.49995 0.950013" stroke="currentColor" stroke-width="1.9"/></svg>
            <span class="mk-pill-text" data-i18n="nav.news"><?= te('nav.news') ?></span>
          </a>
        </li>
        <li class="mk-nav-lang"><?= i18n_lang_menu() ?></li>
        <li class="mk-nav-main">
          <a class="vx-main" href="<?= SITE_DEFAULT_ORIGIN . u('/') ?>" target="_blank" rel="noopener" data-i18n-title="vx.main" title="<?= ta('vx.main') ?>">
            <img src="assets/design/logo-mk-square.png" alt="" width="160" height="160" />
            <span class="vx-main-text">Maknemy</span>
            <svg class="vx-main-out" viewBox="0 0 12 12" aria-hidden="true"><path d="M4.5 1.75h5.75V7.5M10.25 1.75 2 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </a>
        </li>
      </ul>

      <a class="vx-play" href="<?= $vxPlay ?>" target="_blank" rel="noopener" data-i18n="vx.play"><?= te('vx.play') ?></a>

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
    <div class="tb-brand">VOX SEAS<span>EDITOR</span></div>

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

      <header class="tl-header">
        <img class="vx-tl-logo" src="assets/vx/logo.png" alt="Vox Seas" width="480" height="367" />
        <div class="title-block">
          <h1 class="tl-title" id="tlTitle">VOX SEAS<br/>TIER LIST</h1>
          <div class="date" id="tlDate" spellcheck="false" data-i18n-title="stage.dateTitle" title="<?= ta('stage.dateTitle') ?>">17.02.2026</div>
        </div>
      </header>

      <main class="tiers" id="tiers"></main>

      <section class="legend">
        <h2 class="legend-title" data-i18n="legend.title"><?= te('legend.title') ?></h2>
        <div class="legend-grid">
          <div class="legend-col lc-types">
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-fr.svg" alt="FR" /><span class="lgl" data-i18n="legend.fv"><?= te('legend.fv') ?></span></div>
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-pm.svg" alt="PM" /><span class="lgl" data-i18n="legend.pm"><?= te('legend.pm') ?></span></div>
            <div class="lg"><img class="lgb" src="assets/design/legend/badge-gp.svg" alt="GP" /><span class="lgl" data-i18n="legend.gp"><?= te('legend.gp') ?></span></div>
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

  <footer class="vx-foot">
    <img class="vx-foot-logo" src="assets/vx/logo.png" alt="" width="480" height="367" loading="lazy" />
    <p class="vx-foot-name"><?= $vxEsc($vxName) ?></p>
    <p class="vx-foot-note" data-i18n="vx.foot"><?= te('vx.foot') ?></p>
    <p class="vx-foot-legal">
      <a href="/privacy" data-i18n="site.footPrivacy"><?= te('site.footPrivacy') ?></a>
      <a href="/terms" data-i18n="site.footTerms"><?= te('site.footTerms') ?></a>
    </p>
  </footer>

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
            <button data-v="gp" class="t-gp" data-i18n="modal.catPass"><?= te('modal.catPass') ?></button>
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
        <div class="vx-frame">
          <div class="vmodal-icon"><img id="vIcon" alt="" /></div>
          <div class="vx-frame-label"><span class="vmodal-pill-main" id="vValue"></span></div>
        </div>
        <div class="vmodal-pills">
          <div class="vmodal-pill vmodal-kind" id="vKind">
            <span class="vmodal-pill-main" id="vKindMain"></span>
            <span class="vmodal-pill-sub" id="vKindSub"></span>
          </div>
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

  <?= i18n_dict_tag() ?>
  <script src="js/i18n.js?v=84" fetchpriority="high"></script>
  <script src="js/content.js?v=4" fetchpriority="high"></script>
  <script src="js/tiers.js?v=2" fetchpriority="high"></script>
  <script src="js/protect.js?v=3" fetchpriority="high"></script>
  <script src="js/vx-site.js?v=1" fetchpriority="high"></script>
  <script src="js/app.js?v=103" fetchpriority="high"></script>
  <script src="js/news-blocks.js?v=4" fetchpriority="high"></script>
</body>
</html>
