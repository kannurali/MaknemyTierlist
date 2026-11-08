<?php
// Мини-приложение бота. Ключ из ссылки t.me/<бот>?startapp=<ключ> Telegram
// дописывает к адресу (?tgWebAppStartParam=…). Ключ раздела сайта
// (api/lib/tg_pages.php: tierlist, stock, …) — страница сразу уходит на этот
// раздел (js/tg-open.js), без экрана розыгрыша. ch<id> — шахматная партия
// чат-менеджера (js/tg-chess.js, api/cm_chess.php). Остальное (gw<id> или
// пусто) — розыгрыш, js/tg-app.js. Карта разделов лежит в <meta> и для
// случая, когда ключ пришёл только в initData: tg-open.js проверит его там и
// партию тоже перезагрузит с ключом в адресе.
require_once __DIR__ . '/api/lib/tg_pages.php';
header('Cache-Control: no-cache, must-revalidate');
$tgStart = is_string($_GET['tgWebAppStartParam'] ?? null) ? $_GET['tgWebAppStartParam'] : '';
$tgChess = preg_match('/^ch[1-9][0-9]{0,9}\z/', $tgStart) === 1;
$tgGo = $tgChess ? '' : tg_page_path($tgStart);
$tgMap = [];
foreach (TG_PAGES as $tgKey => $tgPage) { $tgMap[$tgKey] = $tgPage[0]; }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<meta name="robots" content="noindex, nofollow" />
<meta name="color-scheme" content="dark light" />
<meta name="format-detection" content="telephone=no" />
<?php if ($tgChess): ?>
<title>Шахматы — MAKNEMY</title>
<script src="https://telegram.org/js/telegram-web-app.js?59"></script>
<base href="/" />
<link rel="stylesheet" href="css/tg-chess.css?v=1" />
<script src="js/tg-chess.js?v=1" defer></script>
<?php else: ?>
<meta name="nx-tg-pages" content="<?= htmlspecialchars(json_encode($tgMap), ENT_QUOTES, 'UTF-8') ?>" />
<base href="/" />
<title><?= $tgGo !== '' ? 'MAKNEMY' : 'Розыгрыш — MAKNEMY' ?></title>
<script src="https://telegram.org/js/telegram-web-app.js?59"></script>
<link rel="stylesheet" href="css/tg-app.css?v=3" />
<script src="js/tg-open.js?v=2" defer></script>
<?php if ($tgGo === ''): ?>
<script src="js/tg-app.js?v=4" defer></script>
<?php endif; ?>
<?php endif; ?>
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
</head>
<body>
<?php if ($tgChess): ?>
<main class="tc" id="tc" aria-busy="true"></main>
<noscript><p class="tc-noscript">Включите JavaScript, чтобы играть в шахматы.</p></noscript>
<?php elseif ($tgGo !== ''): ?>
<main class="ta" id="ta" aria-busy="true"></main>
<noscript><p class="ta-noscript">Включите JavaScript или откройте <a href="<?= htmlspecialchars(TG_PAGES_SITE . $tgGo, ENT_QUOTES, 'UTF-8') ?>">страницу на сайте</a>.</p></noscript>
<?php else: ?>
<main class="ta" id="ta" aria-live="polite" aria-busy="true">
<p class="ta-sr" id="ta-wait"></p>
<div class="ta-skel" aria-hidden="true">
<span class="ta-skel-stage"></span>
<span class="ta-skel-flag"></span>
<span class="ta-skel-card"></span>
<span class="ta-skel-row"></span>
<span class="ta-skel-tiles"><span></span><span></span><span></span></span>
</div>
</main>
<noscript><p class="ta-noscript">Включите JavaScript или откройте <a href="https://maknemy.com/giveaways">розыгрыши на сайте</a>.</p></noscript>
<?php endif; ?>
</body>
</html>
