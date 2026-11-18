<?php
// Оболочка юридических страниц для сайта со скином vx (Vox Seas):
// /privacy и /terms. Подключается из privacy.php и terms.php вместо
// api/lib/legal_page.php, который нарисован под основной сайт. Как и там,
// страница статичная: без скриптов шапки, без переключателя языка и профиля,
// текст сразу на двух языках.

function vx_legal_open(string $slug, string $title, string $description): void {
    $esc  = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $url  = $esc(site_origin() . '/' . $slug);
    $t    = $esc($title);
    $d    = $esc($description);
    $name = $esc(site_name());
    $main = $esc(SITE_DEFAULT_ORIGIN . '/');
    header('Cache-Control: no-cache, must-revalidate');
    page_lscache();
    echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="color-scheme" content="dark" />
<meta name="theme-color" content="#04121f" />
<base href="/" />

<title>{$t}</title>
<meta name="description" content="{$d}" />
<link rel="canonical" href="{$url}" />
<meta name="robots" content="index, follow" />

<meta property="og:type" content="website" />
<meta property="og:site_name" content="{$name}" />
<meta property="og:locale" content="ru_RU" />
<meta property="og:url" content="{$url}" />
<meta property="og:title" content="{$t}" />
<meta property="og:description" content="{$d}" />

<link rel="icon" type="image/png" href="/assets/vx/favicon.png" />
<link rel="apple-touch-icon" href="/assets/vx/favicon.png" />

<link rel="stylesheet" href="css/base.css?v=16" />
<link rel="stylesheet" href="css/vx/vx.css?v=4" />
<link rel="stylesheet" href="css/vx/legal.css?v=3" />
</head>
<body class="vx vx-legal">

  <header class="mk-top">
    <a class="mk-top-brand" href="/tierlist">
      <img src="assets/vx/logo.png" alt="Vox Seas" width="480" height="367" />
    </a>
    <nav class="mk-top-bar" aria-label="Разделы сайта">
      <ul class="mk-nav">
        <li><a class="mk-pill" href="/tierlist">Тирлист</a></li>
        <li><a class="mk-pill" href="/news">Новости</a></li>
      </ul>
      <a class="vx-main" href="{$main}" target="_blank" rel="noopener" title="Основной сайт Maknemy">
        <img src="assets/design/logo-mk-square.png" alt="" width="160" height="160" />
        <span class="vx-main-text">Maknemy</span>
        <svg class="vx-main-out" viewBox="0 0 12 12" aria-hidden="true"><path d="M4.5 1.75h5.75V7.5M10.25 1.75 2 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
    </nav>
  </header>

  <main class="vx-lg">

HTML;
}

function vx_legal_close(): void {
    $name = htmlspecialchars(site_name(), ENT_QUOTES, 'UTF-8');
    echo <<<HTML

  </main>

  <footer class="vx-foot">
    <img class="vx-foot-logo" src="assets/vx/logo.png" alt="" width="480" height="367" loading="lazy" />
    <p class="vx-foot-name">{$name}</p>
    <p class="vx-foot-legal">
      <a href="/privacy">Политика конфиденциальности</a>
      <a href="/terms">Условия использования</a>
    </p>
  </footer>

  <script src="/js/protect.js?v=3" fetchpriority="high"></script>
</body>
</html>

HTML;
}

function vx_legal_section(string $lang): void {
    $attr = $lang === 'en' ? ' lang="en"' : '';
    echo "\n    <section class=\"vx-lg-doc\"{$attr}>\n";
}

function vx_legal_section_close(): void {
    echo "    </section>\n";
}
