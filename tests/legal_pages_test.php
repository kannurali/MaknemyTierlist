<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';

// Правовые страницы /privacy и /terms.
//
// Проверять их особенно важно не из-за вёрстки, а из-за того, что на них
// ссылается карточка OAuth-приложения Roblox: адрес там меняется только через
// повторное ревью, поэтому уехавший маршрут или пропавшая английская версия —
// это не «страница стала хуже», а заявка, которую отклонят.
//
// Второе, что здесь закреплено, — соответствие текста коду. Политика
// перечисляет ровно то, что сайт делает: не хранит паролей, включает вебвизор
// Метрики, не запрашивает инвентарь. Если такой пункт исчезнет из текста,
// документ станет враньём молча.

$ROOT = dirname(__DIR__);
$PUB  = $ROOT . '/public_html';

function legal_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) throw new RuntimeException("не читается: $path");
    return $s;
}

/**
 * Текст без переносов и отступов разметки. Без этого любая проверка фразы
 * длиннее строки ложно падает: в исходнике абзацы перенесены по 80 символов,
 * и «не связан с Roblox Corporation» реально лежит в файле как
 * «не связан \n      с Roblox Corporation».
 */
function legal_flat(string $html): string {
    return (string)preg_replace('/\s+/u', ' ', $html);
}

/**
 * Два языковых блока страницы по отдельности — видимым текстом, без тегов и
 * без переносов.
 *
 * Искать по всей странице нельзя: в ней ещё лежит код счётчика Метрики, где
 * её номер встречается пять раз, а проверяем мы текст документа. Теги
 * снимаются по той же причине, что и переносы: «не связан с Roblox
 * Corporation» набрано в файле как «<b>не связан</b> с Roblox Corporation»,
 * и проверка фразы спотыкалась бы о выделение внутри неё.
 */
function legal_sections(string $html): array {
    $parts = explode('<section class="lg-doc"', $html);
    array_shift($parts);
    $out = [];
    foreach ($parts as $part) {
        $end = strpos($part, '</section>');
        $out[] = legal_flat(strip_tags($end === false ? $part : substr($part, 0, $end)));
    }
    return $out;
}

/** Отрендеренная страница целиком — так же, как её увидит браузер. */
function legal_render(string $file): string {
    ob_start();
    require $file;
    return (string)ob_get_clean();
}

$PRIVACY = legal_render($PUB . '/privacy.php');
$TERMS   = legal_render($PUB . '/terms.php');
$DOCS    = ['privacy' => $PRIVACY, 'terms' => $TERMS];

// --------------------------------------------------------------------------
//  Маршруты
// --------------------------------------------------------------------------

test('/privacy и /terms ведут на свои файлы', function () use ($PUB) {
    $ht = legal_read($PUB . '/.htaccess');
    foreach (['privacy', 'terms'] as $slug) {
        // Подстрокой, а не регуляркой с якорем на конец строки: файл хранится
        // с CRLF, и `$` в многострочном режиме спотыкается о возврат каретки
        // (та же причина, что в home_page_test.php).
        assert_true(strpos($ht, "RewriteRule ^{$slug}$ /{$slug}.php [L]") !== false,
            "внутренний рероут /{$slug} → /{$slug}.php");
        assert_true(strpos($ht, "RewriteRule ^{$slug}/$ /{$slug} [L,R=301]") !== false,
            "слэш на конце снимается внешним 301, иначе база документа уедет в /{$slug}/");
    }
});

// Без условия на REDIRECT_STATUS правило ^privacy\.php$ поймало бы и
// внутренний рероут строкой ниже — получился бы бесконечный редирект.
test('301 с privacy.php защищён условием REDIRECT_STATUS', function () use ($PUB) {
    $ht = legal_read($PUB . '/.htaccess');
    foreach (['privacy', 'terms'] as $slug) {
        $at = strpos($ht, "RewriteRule ^{$slug}\\.php$ /{$slug} [L,R=301]");
        assert_true($at !== false, "301 с /{$slug}.php на канонический адрес");
        $before = substr($ht, max(0, $at - 120), min($at, 120));
        assert_true(strpos($before, 'RewriteCond %{ENV:REDIRECT_STATUS} ^$') !== false,
            "перед 301 с /{$slug}.php обязано стоять условие REDIRECT_STATUS");
    }
});

test('обе страницы объявлены в sitemap', function () use ($PUB) {
    $sm = legal_read($PUB . '/sitemap.xml');
    assert_true(strpos($sm, '<loc>https://maknemy.com/privacy</loc>') !== false, '/privacy');
    assert_true(strpos($sm, '<loc>https://maknemy.com/terms</loc>') !== false, '/terms');
});

// --------------------------------------------------------------------------
//  Разметка страниц
// --------------------------------------------------------------------------

test('у каждой страницы свой canonical и title', function () use ($DOCS) {
    foreach ($DOCS as $slug => $html) {
        assert_true(strpos($html, '<link rel="canonical" href="https://maknemy.com/' . $slug . '" />') !== false,
            "$slug: canonical");
        assert_true((bool)preg_match('/<title>.+\| Maknemy Tier List<\/title>/', $html),
            "$slug: title");
    }
});

// Обе версии на одной странице — это не удобство, а требование: ревьюер
// Roblox англоязычный, а переключатель языка сюда не подключён.
test('на странице обе версии, английская помечена lang', function () use ($DOCS) {
    foreach ($DOCS as $slug => $html) {
        assert_eq(2, substr_count($html, '<section class="lg-doc"'), "$slug: два языковых блока");
        assert_true(strpos($html, '<section class="lg-doc" lang="en">') !== false,
            "$slug: у английского блока обязан быть lang=en");
        assert_eq(2, substr_count($html, '<h1>'), "$slug: по заголовку на язык");
    }
});

test('страницы находимы: закрыты от индексации не должны быть', function () use ($DOCS) {
    foreach ($DOCS as $slug => $html) {
        assert_true(strpos($html, 'content="index, follow"') !== false, "$slug: индексируется");
        assert_eq(0, substr_count($html, 'noindex'), "$slug: без noindex");
    }
});

test('подключены стили правовых страниц и общий хром', function () use ($DOCS) {
    foreach ($DOCS as $slug => $html) {
        foreach (['css/base.css', 'css/topbar.css', 'css/design-page.css', 'css/legal.css'] as $css) {
            assert_true(strpos($html, $css) !== false, "$slug: подключён $css");
        }
    }
});

// Ниже 761px topbar.css прячет .mk-pill-text, и пилюля без значка становится
// пустым синим кружком — так и было, пока иконки не добавили.
test('пилюли в шапке несут иконки, а не только подписи', function () use ($DOCS) {
    foreach ($DOCS as $slug => $html) {
        assert_eq(2, substr_count($html, '<svg viewBox="0 0 19 19"'), "$slug: значок у каждой пилюли");
    }
});

test('в подвале правовых страниц ссылки друг на друга', function () use ($DOCS) {
    foreach ($DOCS as $slug => $html) {
        assert_true(strpos($html, '<a href="/privacy">') !== false, "$slug: ссылка на политику");
        assert_true(strpos($html, '<a href="/terms">') !== false, "$slug: ссылка на условия");
    }
});

// --------------------------------------------------------------------------
//  Текст обязан совпадать с тем, что делает код
// --------------------------------------------------------------------------

test('политика заявляет ровно то, что делает api/roblox_callback.php', function () use ($PRIVACY) {
    [$ru, $en] = legal_sections($PRIVACY);
    // Паролей не храним — их не видит даже сервер (вход целиком на roblox.com).
    assert_true(mb_strpos($ru, 'не хранит паролей') !== false, 'RU: про пароли');
    assert_true(mb_strpos($en, 'stores no passwords') !== false, 'EN: про пароли');
    // Токены Roblox не сохраняются — см. roblox_exchange_code().
    assert_true(mb_strpos($ru, 'Токены доступа Roblox нигде не сохраняются') !== false, 'RU: про токены');
    assert_true(mb_strpos($en, 'access tokens are never stored') !== false, 'EN: про токены');
    // Инвентарь и трейды не запрашиваются: у приложения только openid+profile.
    assert_true(mb_strpos($ru, 'инвентарь и историю трейдов мы не запрашиваем') !== false, 'RU: про скоупы');
    assert_true(mb_strpos($en, 'inventory or trade history') !== false, 'EN: про скоупы');
    // Вебвизор включён в api/lib/metrika.php — про запись действий обязаны сказать.
    assert_true(mb_strpos($ru, 'Вебвизор') !== false, 'RU: вебвизор раскрыт');
    assert_true(mb_strpos($en, 'Webvisor') !== false, 'EN: вебвизор раскрыт');
    // Номер счётчика — тот же, что в METRIKA_ID, и в каждой из двух версий.
    assert_true(strpos($ru, '111127188') !== false, 'RU: номер счётчика');
    assert_true(strpos($en, '111127188') !== false, 'EN: номер счётчика');
});

test('номер счётчика в политике совпадает с METRIKA_ID', function () use ($ROOT) {
    require_once $ROOT . '/public_html/api/lib/metrika.php';
    assert_eq(111127188, METRIKA_ID, 'счётчик не менялся молча');
});

test('условия предупреждают, что цены — оценка, а не гарантия', function () use ($TERMS) {
    [$ru, $en] = legal_sections($TERMS);
    assert_true(mb_strpos($ru, 'оценка команды проекта') !== false, 'RU: статус цен');
    assert_true(mb_strpos($en, "team's estimate") !== false, 'EN: статус цен');
    assert_true(mb_strpos($ru, 'на свой страх и риск') !== false, 'RU: риск сделок');
    assert_true(mb_strpos($en, 'at your own risk') !== false, 'EN: риск сделок');
    // Сайт никогда не спрашивает пароль Roblox — главная антифишинговая фраза
    // для игроков, которых регулярно разводят копиями сайтов.
    assert_true(mb_strpos($ru, 'это мошенничество') !== false, 'RU: предупреждение про пароль');
    assert_true(mb_strpos($en, 'it is a scam') !== false, 'EN: предупреждение про пароль');
});

// Требование Roblox к сторонним приложениям: не выдавать себя за Roblox.
test('оба документа отрицают связь с Roblox Corporation', function () use ($DOCS) {
    foreach ($DOCS as $slug => $html) {
        [$ru, $en] = legal_sections($html);
        assert_true(mb_strpos($ru, 'не связан с Roblox Corporation') !== false, "$slug: RU");
        assert_true(mb_strpos($en, 'affiliated with Roblox Corporation') !== false, "$slug: EN");
    }
});

test('в каждом документе есть контакт для связи', function () use ($DOCS) {
    foreach ($DOCS as $slug => $html) {
        assert_eq(2, substr_count($html, 'https://t.me/theMaknemy'), "$slug: контакт в обеих версиях");
    }
});

// --------------------------------------------------------------------------
//  Ссылки из общего подвала сайта
// --------------------------------------------------------------------------

// Roblox требует, чтобы политику можно было найти с сайта, а не только по
// прямой ссылке из карточки приложения.
test('все публичные страницы ведут в подвале на /privacy и /terms', function () use ($PUB) {
    foreach (['home.php', 'index.php', 'news.php', 'calculator.php'] as $f) {
        $s = legal_read($PUB . '/' . $f);
        assert_true(strpos($s, 'class="mk-foot-legal"') !== false, "$f: блок ссылок в подвале");
        assert_true(strpos($s, '<a href="/privacy" data-i18n="site.footPrivacy">') !== false, "$f: политика");
        assert_true(strpos($s, '<a href="/terms" data-i18n="site.footTerms">') !== false, "$f: условия");
    }
});

test('подписи ссылок переведены на оба языка', function () use ($PUB) {
    $i18n = legal_read($PUB . '/js/i18n.js');
    foreach (['site.footPrivacy', 'site.footTerms'] as $key) {
        assert_eq(2, substr_count($i18n, '"' . $key . '"'), "$key: ru и en");
    }
});

run_tests();
