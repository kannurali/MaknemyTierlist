<?php
// Zero-dependency test runner. Usage: require this, call test(), then run_tests().
$GLOBALS['__tests'] = [];
$GLOBALS['__asserts'] = 0;
$GLOBALS['__fails'] = 0;

function test(string $name, callable $fn): void { $GLOBALS['__tests'][$name] = $fn; }

function assert_eq($exp, $got, string $msg = ''): void {
    $GLOBALS['__asserts']++;
    if ($exp !== $got) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "  ASSERT FAIL $msg\n    expected: " . var_export($exp, true) .
            "\n    got:      " . var_export($got, true) . "\n");
    }
}

function assert_true($c, string $msg = ''): void { assert_eq(true, (bool)$c, $msg); }

function assert_throws(callable $fn, string $msg = ''): void {
    $GLOBALS['__asserts']++;
    try { $fn(); } catch (Throwable $e) { return; }
    $GLOBALS['__fails']++;
    fwrite(STDERR, "  ASSERT FAIL $msg — expected an exception, none thrown\n");
}

function run_tests(): void {
    foreach ($GLOBALS['__tests'] as $name => $fn) {
        try { $fn(); echo "ok - $name\n"; }
        catch (Throwable $e) {
            $GLOBALS['__fails']++;
            fwrite(STDERR, "ERROR - $name: " . $e->getMessage() . "\n");
        }
    }
    echo "\n{$GLOBALS['__asserts']} assertions, {$GLOBALS['__fails']} failures\n";
    exit($GLOBALS['__fails'] ? 1 : 0);
}

// In-memory SQLite DB seeded with the app schema (portable subset of schema.sql).
function test_db(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE tierlist (id INTEGER PRIMARY KEY, data TEXT NOT NULL, rev INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE likes (id INTEGER PRIMARY KEY, count INTEGER NOT NULL DEFAULT 0)");
    // Deliberately left with no row: schema.sql does not seed one either, and
    // the first save has to create it. Tests assert the empty-document path.
    $pdo->exec("CREATE TABLE promo (id INTEGER PRIMARY KEY, data TEXT NOT NULL, rev INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE news (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category TEXT NOT NULL,
        title_ru TEXT NOT NULL,
        title_en TEXT NOT NULL DEFAULT '',
        body_ru TEXT NOT NULL,
        -- DEFAULT нет намеренно: в MySQL его у TEXT не бывает, и с ним не
        -- создаётся вся таблица. Схемы обязаны совпадать, иначе тесты проверяют
        -- не ту таблицу, которая работает на бою.
        body_en TEXT NOT NULL,
        image_url TEXT NOT NULL DEFAULT '',
        -- Зеркалит schema.sql: свободная ширина/выравнивание/обтекание вместо
        -- прежних трёх пресетов image_size.
        image_pct INTEGER NOT NULL DEFAULT 100,
        image_align TEXT NOT NULL DEFAULT 'center',
        image_wrap INTEGER NOT NULL DEFAULT 0,
        -- Зеркалит schema.sql: NULL, без DEFAULT — пост без картинки или
        -- сохранённый до появления этих колонок не хранит ни ширины, ни
        -- высоты (см. комментарий в schema.sql).
        image_width INTEGER,
        image_height INTEGER,
        -- Зеркалит schema.sql: структурированное тело поста. LONGTEXT в
        -- SQLite — просто TEXT.
        body_json TEXT NULL,
        published_at INTEGER NOT NULL,
        -- Зеркалит schema.sql: анонимный счётчик лайков поста, своя
        -- колонка вместо отдельной таблицы.
        likes INTEGER NOT NULL DEFAULT 0
    )");
    // Зеркалит schema.sql: вошедшие через Roblox. BIGINT UNSIGNED в
    // SQLite — просто INTEGER, но roblox_id везде ходит строкой: id
    // игрока у Roblox — это claim `sub`, то есть текст из цифр.
    $pdo->exec("CREATE TABLE users (
        roblox_id INTEGER PRIMARY KEY,
        username TEXT NOT NULL DEFAULT '',
        display_name TEXT NOT NULL DEFAULT '',
        avatar_url TEXT NOT NULL DEFAULT '',
        created_at INTEGER NOT NULL,
        last_login_at INTEGER NOT NULL,
        -- Зеркалит schema.sql: присутствие, отдельно от времени входа.
        last_seen_at INTEGER NOT NULL DEFAULT 0,
        -- Зеркалит schema.sql: текст «о себе» со страницы профиля.
        about TEXT NULL DEFAULT NULL,
        -- Зеркалит schema.sql: репутация из чатов.
        likes INTEGER NOT NULL DEFAULT 0,
        dislikes INTEGER NOT NULL DEFAULT 0
    )");
    // Зеркалит schema.sql: кто уже лайкнул тирлист или пост.
    $pdo->exec("CREATE TABLE like_votes (
        user_id INTEGER NOT NULL,
        target TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        PRIMARY KEY (user_id, target)
    )");
    $pdo->exec("INSERT INTO tierlist (id, data, rev) VALUES (1, '{}', 0)");
    $pdo->exec("INSERT INTO likes (id, count) VALUES (1, 0)");
    return $pdo;
}

// Тесты читают исходники страниц и ищут в них готовую русскую разметку.
// После перевода шаблонов на te()/ta()/u() в исходнике стоят вызовы вместо
// текста; здесь они заменяются тем, что дают по-русски, — так проверки видят
// ту же разметку, что и раньше. Остальной PHP не трогается.
function i18n_flatten(string $src): string {
    static $ru = null;
    if ($ru === null) {
        $raw = @file_get_contents(dirname(__DIR__) . '/public_html/lang/ru.json');
        $ru = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }
    $src = preg_replace_callback("~<\\?= (te|ta)\\('([^']+)'\\) \\?>~", function ($m) use ($ru) {
        $s = array_key_exists($m[2], $ru) ? (string)$ru[$m[2]] : $m[2];
        return htmlspecialchars($s, ($m[1] === 'te' ? ENT_NOQUOTES : ENT_QUOTES) | ENT_SUBSTITUTE, 'UTF-8');
    }, $src);
    $src = preg_replace("~<\\?= u\\('([^']*)'\\) \\?>~", '$1', $src);
    $src = preg_replace("~<\\?= i18n_url\\('([^']*)'\\) \\?>~", 'https://maknemy.com$1', $src);
    $src = str_replace(['<?= i18n_lang() ?>', '<?= i18n_og_locale() ?>'], ['ru', 'ru_RU'], $src);
    $src = preg_replace("~<\\?= i18n_alternates\\([^\\n]*?\\) \\?>\\n?~", '', $src);
    $src = str_replace("<?php if (i18n_lang() === 'ru'): ?>\n<script type=\"application/ld+json\">",
        '<script type="application/ld+json">', $src);
    $src = preg_replace('~(<script type="application/ld\+json">.*?</script>\n)<\?php endif; \?>\n~s', '$1', $src);
    return $src;
}
