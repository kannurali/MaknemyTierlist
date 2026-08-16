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
        image_size TEXT NOT NULL DEFAULT 'full',
        published_at INTEGER NOT NULL
    )");
    $pdo->exec("INSERT INTO tierlist (id, data, rev) VALUES (1, '{}', 0)");
    $pdo->exec("INSERT INTO likes (id, count) VALUES (1, 0)");
    return $pdo;
}
