<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';

// Страницы трейдинга: /trading (лента), /trading/new (публикация), /support
// (центр обращений) и /admin/support. Как у calculator_page_test.php, здесь
// проверяется то, что не поймает ни компилятор, ни линтер: маршруты, общая
// шапка, версии подключённых файлов, словарь, связки между страницами.
// Логика сервера — в tests/trade_test.php.

$ROOT = dirname(__DIR__);
$PUB  = $ROOT . '/public_html';
$NEW  = ['trading.php', 'trade-new.php', 'support.php'];

function tp_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) throw new RuntimeException("не читается: $path");
    return str_replace("\r\n", "\n", $s);
}

function tp_header(string $s): string {
    $a = strpos($s, '<header class="mk-top">');
    $b = strpos($s, '</header>', $a === false ? 0 : $a);
    return ($a === false || $b === false) ? '' : substr($s, $a, $b - $a);
}

// --------------------------------------------------------------------------
//  Маршруты
// --------------------------------------------------------------------------

test('адреса трейдинга и поддержки ведут на свои файлы, слэш и .php — 301', function () use ($PUB) {
    $ht = tp_read($PUB . '/.htaccess');
    $rules = [
        'RewriteRule ^trading$ /trading.php [L]',
        'RewriteRule ^trading/new$ /trade-new.php [L]',
        'RewriteRule ^support$ /support.php [L]',
        'RewriteRule ^trading/$ /trading [L,R=301]',
        'RewriteRule ^trading/new/$ /trading/new [L,R=301]',
        'RewriteRule ^support/$ /support [L,R=301]',
        'RewriteRule ^admin/support/?$ /admin-support.php [L]',
    ];
    foreach ($rules as $r) { assert_true(strpos($ht, $r) !== false, "правило: $r"); }

    foreach (['trading\\\\.php' => '/trading', 'trade-new\\\\.php' => '/trading/new', 'support\\\\.php' => '/support'] as $file => $to) {
        assert_true((bool)preg_match(
            '~RewriteCond %\{ENV:REDIRECT_STATUS\} \^\$\s*\n\s*RewriteRule \^' . $file . '\$ ' . preg_quote($to, '~') . ' \[L,R=301\]~',
            $ht), "прямой адрес $to закрыт условием REDIRECT_STATUS");
    }
    assert_true(strpos($ht, 'RewriteRule ^trading/$') < strpos($ht, 'RewriteRule ^trading$'),
        'снятие слэша стоит раньше рероута');
});

test('лента — в sitemap, форма и поддержка — нет', function () use ($PUB) {
    $map = tp_read($PUB . '/sitemap.xml');
    assert_true(strpos($map, '<loc>https://maknemy.com/trading</loc>') !== false, '/trading в sitemap');
    assert_eq(false, strpos($map, 'maknemy.com/trading/new'), '/trading/new не индексируется');
    assert_eq(false, strpos($map, 'maknemy.com/support'), '/support не индексируется');

    $feed = tp_read($PUB . '/trading.php');
    assert_true(strpos($feed, '<link rel="canonical" href="https://maknemy.com/trading" />') !== false, 'canonical ленты');
    assert_true(strpos($feed, '<meta name="robots" content="index, follow') !== false, 'лента индексируется');
    foreach (['trade-new.php' => 'https://maknemy.com/trading/new', 'support.php' => 'https://maknemy.com/support'] as $f => $url) {
        $s = tp_read($PUB . '/' . $f);
        assert_true(strpos($s, '<link rel="canonical" href="' . $url . '" />') !== false, "$f: canonical");
        assert_true(strpos($s, '<meta name="robots" content="noindex, follow" />') !== false, "$f: noindex");
    }
});

// --------------------------------------------------------------------------
//  Шапка
// --------------------------------------------------------------------------

// Шапка у новых страниц — та же, что у чата, байт в байт, кроме aria-current:
// расхождение копий уже ловилось при сборке #70 (кнопка чата осталась
// заглушкой на профиле).
test('шапка новых страниц совпадает с шапкой чата', function () use ($PUB, $NEW) {
    $ref = preg_replace('/ aria-current="page"/', '', tp_header(tp_read($PUB . '/chat.php')));
    assert_true($ref !== '', 'шапка чата найдена');
    foreach ($NEW as $f) {
        $h = tp_header(tp_read($PUB . '/' . $f));
        assert_eq($ref, preg_replace('/ aria-current="page"/', '', $h), "$f: шапка совпадает");
    }
});

test('«Трейдинг» подсвечен на своих страницах и больше нигде', function () use ($PUB, $NEW) {
    $all = array_merge($NEW, ['home.php', 'index.php', 'news.php', 'calculator.php', 'chat.php', 'profile.php']);
    foreach ($all as $f) {
        $h = tp_header(tp_read($PUB . '/' . $f));
        $on = strpos($h, '<a class="mk-pill" href="/trading" aria-current="page">') !== false;
        $want = in_array($f, ['trading.php', 'trade-new.php'], true);
        assert_eq($want, $on, "$f: aria-current у «Трейдинга»");
        assert_true(strpos($h, 'href="/trading"') !== false, "$f: ссылка на /trading");
    }
});

test('общие файлы подключены теми же версиями, что у чата', function () use ($PUB, $NEW) {
    $chat = tp_read($PUB . '/chat.php');
    foreach (['css/base.css', 'css/topbar.css', 'js/auth.js', 'js/topbar.js', 'css/design-page.css', 'js/i18n.js'] as $asset) {
        preg_match('~' . preg_quote($asset, '~') . '\?v=(\d+)~', $chat, $m);
        assert_true(!empty($m), "$asset есть у чата");
        foreach ($NEW as $f) {
            $s = tp_read($PUB . '/' . $f);
            assert_true(strpos($s, $asset . '?v=' . $m[1]) !== false, "$f: $asset?v={$m[1]}");
        }
    }
    foreach ($NEW as $f) {
        $s = tp_read($PUB . '/' . $f);
        assert_true(strpos($s, 'css/trading.css?v=') !== false, "$f: свои стили");
        assert_true(strpos($s, '<?php echo metrika_counter_html(); ?>') !== false, "$f: счётчик Метрики");
        assert_true(strpos($s, 'page_lscache();') !== false, "$f: разметка без сессии — в кеше LiteSpeed");
    }
    assert_true(strpos($chat, 'js/auth.js') < strpos($chat, 'js/topbar.js'), 'auth.js раньше topbar.js');

    // Рекламная полоса и окно без своих стилей растягивают страницу вширь:
    // на телефоне лента уезжала на 250 px вправо.
    $calc = tp_read($PUB . '/calculator.php');
    foreach (['trading.php', 'trade-new.php'] as $f) {
        $s = tp_read($PUB . '/' . $f);
        assert_true(strpos($s, 'id="promoDock"') !== false, "$f: рекламная полоса");
        foreach (['css/promo-dock.css', 'css/promo-popup.css'] as $asset) {
            preg_match('~' . preg_quote($asset, '~') . '\?v=(\d+)~', $calc, $m);
            assert_true(strpos($s, $asset . '?v=' . $m[1]) !== false, "$f: $asset как у калькулятора");
        }
    }
});

// --------------------------------------------------------------------------
//  Лента
// --------------------------------------------------------------------------

test('лента: кнопки из макета ведут куда в прототипе', function () use ($PUB) {
    $s = tp_read($PUB . '/trading.php');
    assert_true(strpos($s, '<a class="tr-tool" href="/calculator">') !== false, '«уточнить цены» → калькулятор');
    assert_true(strpos($s, '<a class="tr-tool" href="/trading/new">') !== false, '«создать» → форма');
    assert_true(strpos($s, '<a class="tr-tool" href="/support">') !== false, '«поддержка» → центр обращений');
    foreach (['trFeed', 'trMine', 'trState', 'trMore', 'trQuery', 'trBoard', 'trRail', 'trRailR'] as $id) {
        assert_true(strpos($s, 'id="' . $id . '"') !== false, "#$id в разметке");
    }
    foreach (['trIconUp', 'trIconDown', 'trIconSwap'] as $id) {
        assert_true(strpos($s, '<symbol id="' . $id . '"') !== false, "значок $id объявлен один раз");
    }
    $order = ['js/i18n.js', 'js/promo.js', 'js/calc.js', 'js/trading-page.js'];
    $at = array_map(function ($f) use ($s) { return strpos($s, 'src="' . $f . '?v='); }, $order);
    assert_true(!in_array(false, $at, true), 'скрипты подключены');
    $sorted = $at; sort($sorted);
    assert_eq($sorted, $at, 'словарь и calc.js раньше скрипта ленты');
});

test('лента: страница стоит, прокручивается только блок объявлений', function () use ($PUB) {
    $s   = tp_read($PUB . '/trading.php');
    $css = tp_read($PUB . '/css/trading.css');
    $js  = tp_read($PUB . '/js/trading-page.js');
    assert_true(strpos($s, '<body class="tr-body">') !== false, 'у ленты свой body');
    assert_true(strpos($css, "height: 100dvh;") !== false, 'body ровно в высоту окна');
    assert_true(strpos($css, '.tr-body > .mk-foot { display: none; }') !== false, 'подвал под лентой не выглядывает');
    preg_match('~\n\.tr-board \{(.*?)\n\}~s', $css, $m);
    $board = $m[1] ?? '';
    assert_true(strpos($board, 'flex: 1 1 0;') !== false, 'блок занимает остаток окна');
    assert_true(strpos($board, 'overflow-y: auto;') !== false, 'и прокручивается сам');
    assert_true(strpos($css, 'overflow: visible;') === false, 'на телефоне прокрутку у блока не отбирают');
    assert_true(strpos($js, '{ root: $("#trBoard"), rootMargin: "240px" }') !== false,
        'подгрузка следит за прокруткой блока, а не окна');
});

test('скрипт ленты: API, чат с черновиком, закрытие, реклама', function () use ($PUB) {
    $js    = tp_read($PUB . '/js/trading-page.js');
    $cards = tp_read($PUB . '/js/trade-cards.js');
    assert_true(strpos($js, '"/api/trades.php"') !== false, 'лента');
    assert_true(strpos($js, 'TRADE_CARDS.make(') !== false, 'карточки — из общего модуля');
    assert_true(strpos($cards, '"/api/trade_close.php"') !== false, 'закрытие');
    assert_true(strpos($cards, '"/chat?to=" + encodeURIComponent(offer.author.id)') !== false
        && strpos($cards, '+ "&offer=" + encodeURIComponent(offer.id)') !== false
        && strpos($cards, '+ "&draft=" + encodeURIComponent(draftFor(offer))') !== false,
        'чужое объявление открывает чат с автором, номером объявления и черновиком');
    assert_true(strpos($cards, '"/profile?id=" + encodeURIComponent(offer.author.id)') !== false, 'ник ведёт на профиль');
    assert_true(strpos($cards, 'CALC.buildCatalogIndex(CALC.flattenTierlist(d.tierlist))') !== false,
        'картинки и цены — из тирлиста, как у калькулятора');
    $js .= $cards;
    assert_true(strpos($js, 'const PROMO_PAGE = "calc"') !== false, 'реклама — как у калькулятора');
    assert_true(strpos($js, 'promo.houseFor(') !== false, 'борт берёт объявление из общего модуля');
    assert_true(strpos($js, 'NX_PROMO_DOCK.render(dock, doc, PROMO_PAGE)') !== false, 'полоса тем же документом');
    assert_true(strpos($js, 'window.confirm(') !== false, 'закрытие переспрашивает');
    assert_true(strpos($js, 'img.referrerPolicy = "no-referrer"') !== false, 'аватары Roblox без реферера');
});

test('чат подставляет черновик из объявления', function () use ($PUB) {
    $js = tp_read($PUB . '/js/chat-page.js');
    assert_true(strpos($js, '/[?&]draft=([^&]*)/') !== false, 'черновик читается из адреса');
    assert_true(strpos($js, 'if (id && offerDraft && !drafts[id]) { drafts[id] = offerDraft; }') !== false,
        'и кладётся в черновик ветки, не затирая начатый');
    assert_true(strpos($js, '.slice(0, 500)') !== false, 'длина черновика ограничена');
    assert_true(strpos($js, '/[?&]offer=(\\d{1,10})(?:&|$)/') !== false, 'номер объявления — из адреса');
    assert_true(strpos($js, 'if (offerRef[sentThread]) { payload.offer = offerRef[sentThread]; }') !== false,
        'и уходит с первым сообщением');
    assert_true(strpos($js, 'delete offerRef[sentThread];') !== false, 'один раз');

    $api = tp_read($PUB . '/api/chat_send.php');
    assert_true(strpos($api, 'trade_note_reply(db(), $me, $thread, $offer, time());') !== false,
        'отправка засчитывает отклик');
    assert_true(strpos($api, 'if ($status === 200 && $offer > 0) {') !== false, 'только после успешной отправки');
});

test('в своём профиле — все свои объявления и остаток лимита', function () use ($PUB) {
    $s = tp_read($PUB . '/profile.php');
    $a = strpos($s, '<section class="pf-trades"');
    assert_true($a !== false, 'блок «Мои объявления»');
    assert_true(strpos(substr($s, 0, $a), '<?php if ($pfSelf): ?>', strrpos(substr($s, 0, $a), '</section>')) !== false,
        'только на своём профиле');
    foreach (['id="pfTrades"', 'id="pfTradesState"', 'id="pfTradesQuota"', 'href="/trading/new"'] as $needle) {
        assert_true(strpos($s, $needle) !== false, "в разметке: $needle");
    }
    foreach (['trIconUp', 'trIconDown', 'trIconSwap'] as $id) {
        assert_true(strpos($s, '<symbol id="' . $id . '"') !== false, "значок $id для карточек");
    }
    $order = ['js/i18n.js', 'js/calc.js', 'js/trade-cards.js', 'js/profile-trades.js'];
    $at = array_map(function ($f) use ($s) { return strpos($s, 'src="' . $f . '?v='); }, $order);
    assert_true(!in_array(false, $at, true), 'скрипты подключены');
    $sorted = $at; sort($sorted);
    assert_eq($sorted, $at, 'в правильном порядке');
    assert_true(strpos($s, 'css/trading.css?v=') !== false, 'стили карточек');

    $js = tp_read($PUB . '/js/profile-trades.js');
    assert_true(strpos($js, '"/api/trades.php?view=mine"') !== false, 'свои объявления');
    assert_true(strpos($js, 'TRADE_CARDS.close(') !== false, 'закрытие — тем же модулем');
    $tn = tp_read($PUB . '/js/trade-new.js');
    assert_true(strpos($tn, '"/api/trades.php?view=quota"') !== false, 'форма знает остаток лимита');
});

// --------------------------------------------------------------------------
//  Публикация
// --------------------------------------------------------------------------

test('форма публикации — панель калькулятора с подписями из макета', function () use ($PUB) {
    $s = tp_read($PUB . '/trade-new.php');
    assert_true(strpos($s, '<main class="tc-page tn-page">') !== false, 'панель калькулятора');
    assert_true(strpos($s, 'data-i18n="trade.givePill" aria-hidden="true">Вы предлагаете</span>') !== false, '«вы предлагаете»');
    assert_true(strpos($s, 'data-i18n="trade.wantPill" aria-hidden="true">Вы хотите</span>') !== false, '«вы хотите»');
    assert_eq(false, strpos($s, 'id="tcResult"'), 'вердикта калькулятора нет — его место у предупреждения');
    assert_true(strpos($s, 'id="tcCatalogBackdrop"') !== false, 'каталог предметов');
    assert_true(strpos($s, 'id="tnPublish"') !== false, 'кнопка публикации');
    assert_true(strpos($s, 'class="tn-warn"') !== false, 'предупреждение о безопасности');
    assert_true(strpos($s, '<a class="tn-warn-link" href="/support"') !== false, '«в поддержку!» ведёт в центр обращений');

    $calc = tp_read($PUB . '/calculator.php');
    foreach (['js/calc.js', 'js/calculator-page.js', 'css/calculator.css'] as $asset) {
        preg_match('~' . preg_quote($asset, '~') . '\?v=(\d+)~', $calc, $m);
        assert_true(strpos($s, $asset . '?v=' . $m[1]) !== false, "$asset той же версии, что у калькулятора");
    }
    assert_true(strpos($s, 'src="js/calculator-page.js?v=') < strpos($s, 'src="js/trade-new.js?v='),
        'trade-new.js после скрипта панели');
});

test('скрипт панели умеет жить без вердикта и отдаёт собранное', function () use ($PUB) {
    $js = tp_read($PUB . '/js/calculator-page.js');
    assert_true(strpos($js, "if (!resultEl) return;") !== false, 'без #tcResult вердикт не рисуется');
    assert_true(strpos($js, 'if (!$("#tcClearAllBtn") || !$("#tcShareBtn")) return;') !== false, 'без кнопок калькулятора');
    assert_true(strpos($js, 'document.dispatchEvent(new CustomEvent("tc:change"));') !== false, 'событие об изменении');
    assert_true(strpos($js, 'window.NX_CALC = {') !== false, 'собранное доступно снаружи');

    $tn = tp_read($PUB . '/js/trade-new.js');
    assert_true(strpos($tn, '"/api/trade_create.php"') !== false, 'публикация');
    assert_true(strpos($tn, 'document.addEventListener("tc:change"') !== false, 'кнопка следит за панелью');
    assert_true(strpos($tn, 'location.assign("/trading?posted="') !== false, 'после публикации — в ленту');
    assert_true(strpos($tn, 'window.MKAuth.startUrl()') !== false, 'вход возвращает сюда же с черновиком');
});

// --------------------------------------------------------------------------
//  Поддержка
// --------------------------------------------------------------------------

test('центр обращений: форма, «написать лично» и окно благодарности', function () use ($PUB) {
    $s = tp_read($PUB . '/support.php');
    assert_true(strpos($s, 'id="spForm"') !== false, 'форма');
    assert_true(strpos($s, 'maxlength="1000"') !== false, 'предел как на сервере (SUPPORT_BODY_MAX)');
    assert_true(strpos($s, 'href="https://t.me/theMaknemy"') !== false, '«написать лично» — Telegram из прототипа');
    assert_true((bool)preg_match('/id="spThanks"[^>]*hidden/', $s), 'окно благодарности скрыто до отправки');
    $js = tp_read($PUB . '/js/support-page.js');
    assert_true(strpos($js, '"/api/support.php"') !== false, 'отправка');
    assert_true(strpos($js, 'const MIN_CHARS = 10;') !== false, 'минимум как на сервере (SUPPORT_BODY_MIN)');

    $lib = tp_read($PUB . '/api/lib/support.php');
    assert_true(strpos($lib, 'const SUPPORT_BODY_MIN = 10;') !== false, 'серверный минимум');
    assert_true(strpos($lib, 'const SUPPORT_BODY_MAX = 1000;') !== false, 'серверный максимум');
});

test('обращения видны в админке отдельной вкладкой', function () use ($PUB) {
    $nav = tp_read($PUB . '/api/lib/admin_page.php');
    assert_true(strpos($nav, "'support' => ['/admin/support', 'Обращения']") !== false, 'вкладка в общей панели');
    $page = tp_read($PUB . '/admin-support.php');
    assert_true(strpos($page, "admin_page_guard('moderator');") !== false, 'страница закрыта: модераторы и админы');
    assert_true(strpos($page, "admin_nav('support')") !== false, 'своя вкладка подсвечена');
    assert_true(strpos($page, 'action="/api/support_status.php"') !== false, 'отметка — формой');
    $api = tp_read($PUB . '/api/support_status.php');
    assert_true(strpos($api, 'require_moderator();') !== false, 'отметка — модератору или админу');
    assert_true(strpos($api, 'require_post();') !== false, 'и только POST');
});

// --------------------------------------------------------------------------
//  Общее
// --------------------------------------------------------------------------

test('все ключи словаря с новых страниц есть в обоих языках', function () use ($PUB, $NEW) {
    $i18n = tp_read($PUB . '/js/i18n.js');
    $ru = substr($i18n, 0, strpos($i18n, "\n    en: {"));
    $en = substr($i18n, strpos($i18n, "\n    en: {"));
    $keys = [];
    foreach ($NEW as $f) {
        preg_match_all('/data-i18n(?:-label|-placeholder|-title)?="([a-zA-Z.]+)"/', tp_read($PUB . '/' . $f), $m);
        $keys = array_merge($keys, $m[1]);
    }
    foreach (['trading-page.js', 'trade-new.js', 'support-page.js', 'trade-cards.js', 'profile-trades.js'] as $f) {
        preg_match_all('/"((?:trade|support)\.[a-zA-Z]+)"/', tp_read($PUB . '/js/' . $f), $m);
        $keys = array_merge($keys, $m[1]);
    }
    foreach (array_unique($keys) as $k) {
        assert_true(strpos($ru, '"' . $k . '":') !== false, "ru: $k");
        assert_true(strpos($en, '"' . $k . '":') !== false, "en: $k");
    }
});

test('новые скрипты и стили отдаются без комментариев и без innerHTML', function () use ($PUB) {
    foreach (['js/trading-page.js', 'js/trade-new.js', 'js/support-page.js', 'js/trade-cards.js', 'js/profile-trades.js'] as $f) {
        $s = tp_read($PUB . '/' . $f);
        assert_eq(0, preg_match('~(^|[^:"\'])//\s~m', $s), "$f: без // комментариев");
        assert_eq(0, preg_match('~/\*~', $s), "$f: без /* */");
        assert_eq(0, substr_count($s, 'innerHTML'), "$f: только textContent — в ленте чужой текст");
    }
    foreach (['css/trading.css', 'css/support-admin.css'] as $f) {
        assert_eq(0, preg_match('~/\*~', tp_read($PUB . '/' . $f)), "$f: без комментариев");
    }
    foreach (['trading.php', 'trade-new.php', 'support.php'] as $f) {
        $html = preg_replace('/<\?php.*?\?>/s', '', tp_read($PUB . '/' . $f));
        assert_eq(0, substr_count($html, '<!--'), "$f: без HTML-комментариев");
    }
});

test('таблицы трейдинга в schema.sql совпадают с миграцией', function () use ($ROOT) {
    $schema = tp_read($ROOT . '/schema.sql');
    $mig    = tp_read($ROOT . '/docs/migrations/2026-09-23-trading.sql');
    foreach (['trade_offers', 'profile_trades', 'support_tickets'] as $t) {
        $re = '/CREATE TABLE IF NOT EXISTS ' . $t . ' \(.*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s';
        assert_true((bool)preg_match($re, $mig, $a), "$t в миграции");
        assert_true((bool)preg_match($re, $schema, $b), "$t в schema.sql");
        assert_eq($a[0] ?? '', $b[0] ?? '', "$t: одно и то же определение");
    }
});

run_tests();
