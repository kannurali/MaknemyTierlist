<?php
// Дозаполняет переводы контента на языки сайта (таблица i18n_texts):
// новости, розыгрыши и описания предметов тирлиста — всё, чего нет или что
// переводит прежнюю версию русского текста. Новое и исправленное админка
// переводит сама после сохранения (api/lib/i18n_content.php); этот скрипт —
// для всего, что опубликовано до миграции, и для догона, если Google
// отказывал.
//
// Запуск из корня репозитория:
//   php tools/i18n_backfill.php                все виды и все языки
//   php tools/i18n_backfill.php --kind=news    только новости (news | gw | item)
//   php tools/i18n_backfill.php --lang=de      только этот язык
//   php tools/i18n_backfill.php --dry-run      сколько не хватает, без запросов
//   php tools/i18n_backfill.php --pause=3000   пауза перед запросом, мс (1500)
//   php tools/i18n_backfill.php --max-wait=120 сколько всего ждать Google, мин (360)
//   php tools/i18n_backfill.php --config=/home/maknemyt/config.php
//
// На сервере — в фоне, чтобы закрытый терминал не оборвал работу:
//   nohup php tools/i18n_backfill.php --config=$HOME/config.php > ~/i18n_backfill.log 2>&1 &
//
// Без --config ищет config.php рядом с репозиторием (так он лежит локально),
// потом в домашнем каталоге (так на сервере: репозиторий deploy_repo отдельно
// от сайта, config.php — над public_html).
//
// Бесплатный эндпоинт Google на частые запросы с одного адреса перестаёт
// отвечать (2026-10-20 на бою — после пяти постов при паузе 0,3 с). Тогда
// скрипт проверяет коротким запросом, отвечает ли Google вообще, и если нет —
// ждёт (5, 10, 20, 30 минут, дальше по часу) и продолжает с той же записи.
// Сдаётся, только когда ожидание в сумме превысит --max-wait. Повторный запуск
// ничего не переводит заново: готовое узнаётся по хешу.
//
// Код выхода 1 — что-то не перевелось (тогда сайт показывает там EN, без
// него RU), запуск можно повторить позже.

const BACKFILL_KINDS = ['news', 'gw', 'item'];
const BACKFILL_WAITS = [300, 600, 1200, 1800, 3600];
const BACKFILL_PAUSE_MS = 1500;
const BACKFILL_MAX_WAIT_MIN = 360;

// Сколько ждать перед попыткой номер $step + 1 после отказа Google.
function backfill_wait(int $step): int {
    return BACKFILL_WAITS[min($step, count(BACKFILL_WAITS) - 1)];
}

/**
 * Переводит записи по очереди и пережидает отказы Google.
 *
 * $targets — [имя записи => то, что отдаётся $fill]; $fill($target) —
 * перевод недостающего у записи, счётчики i18n_fill(); $probe() — отвечает
 * ли Google сейчас; $sleep($секунды), $log($строка) — для тестов подменяются.
 * $maxWait — сколько секунд всего можно прождать.
 *
 * Запись с непереведённым повторяется: если Google не отвечает — после
 * ожидания, сколько понадобится; если отвечает (значит, не даётся сам
 * текст) — ещё один раз, и дальше. failed в ответе — что осталось
 * непереведённым после последней попытки каждой записи.
 */
function backfill_run(array $targets, callable $fill, callable $probe, callable $sleep, callable $log, int $maxWait): array {
    $sum = ['filled' => 0, 'reused' => 0, 'failed' => 0, 'aborted' => false];
    $waited = 0;
    $step = 0;
    foreach ($targets as $name => $target) {
        $tries = 0;
        while (true) {
            $st = $fill($target);
            $sum['filled'] += $st['filled'];
            $sum['reused'] += $st['reused'];
            if ($st['filled'] || $st['reused'] || $st['failed']) {
                $log("$name: переведено {$st['filled']}, взято готовое {$st['reused']}, не вышло {$st['failed']}");
            }
            if ($st['filled'] || !$st['failed']) { $step = 0; }
            if (!$st['failed']) { break; }
            if ($probe()) {
                $step = 0;
                if (++$tries > 1) { $sum['failed'] += $st['failed']; break; }
                continue;
            }
            $wait = backfill_wait($step);
            if ($waited + $wait > $maxWait) {
                $sum['failed'] += $st['failed'];
                $sum['aborted'] = true;
                $log('Google не отвечает уже ' . intdiv($waited, 60) . ' мин — остановлено, повторите позже');
                return $sum;
            }
            $log('Google не отвечает — жду ' . intdiv($wait, 60) . " мин и продолжу с $name");
            $sleep($wait);
            $waited += $wait;
            $step++;
        }
    }
    return $sum;
}

// [имя записи => загрузчик её мест]. Предметы — одна запись на весь
// тирлист: их удобнее переводить пачкой.
function backfill_targets(PDO $pdo, string $kind): array {
    if ($kind === 'item') {
        return ['item tierlist' => function () use ($pdo): array { return i18n_items_load($pdo); }];
    }
    $out = [];
    if ($kind === 'news') {
        foreach ($pdo->query('SELECT id FROM news ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int)$id;
            $out['news #' . $id] = function () use ($pdo, $id): array { return i18n_news_load($pdo, $id); };
        }
        return $out;
    }
    if (!gw_ready($pdo)) { return []; }
    foreach ($pdo->query('SELECT id FROM giveaways ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $id = (int)$id;
        $out['gw #' . $id] = function () use ($pdo, $id): array { return gw_i18n_load($pdo, $id); };
    }
    return $out;
}

// Сколько мест не хватает: то же сравнение по хешу, что в i18n_fill(), без
// запросов и без учёта готовых переводов, которые можно взять у соседнего поля.
function backfill_missing(PDO $pdo, string $kind, array $units, array $langs): int {
    $n = 0;
    foreach ($langs as $lang) {
        $rows = i18n_rows($pdo, $kind, $lang, array_keys($units));
        foreach ($units as $ref => $fields) {
            foreach ($fields as $field => $u) {
                if (!empty($u['manual'][$lang])) { continue; }
                $have = $rows[(string)$ref][$field]['hash'] ?? null;
                if ($have !== i18n_hash($u['ru'])) { $n++; }
            }
        }
    }
    return $n;
}

// Переводчик с паузой перед каждым запросом.
function backfill_request(string $text, string $lang, int $pauseMs): ?string {
    if ($pauseMs > 0) { usleep($pauseMs * 1000); }
    return tr_google_request($text, $lang);
}

function backfill_main(array $opts): int {
    $config = isset($opts['config']) ? (string)$opts['config'] : null;
    if ($config === null) {
        $home = rtrim((string)getenv('HOME'), '/');
        foreach ([__DIR__ . '/../config.php', $home === '' ? '' : $home . '/config.php'] as $c) {
            if ($c !== '' && is_file($c)) { $config = $c; break; }
        }
    }
    if ($config === null || !is_file($config)) {
        fwrite(STDERR, "config.php не найден — укажите --config=PATH\n");
        return 1;
    }
    define('CONFIG_PATH', $config);
    require_once __DIR__ . '/../public_html/api/_bootstrap.php';
    require_once __DIR__ . '/../public_html/api/lib/i18n_content.php';
    require_once __DIR__ . '/../public_html/api/lib/giveaway.php';

    $kinds = isset($opts['kind']) ? [(string)$opts['kind']] : BACKFILL_KINDS;
    if (array_diff($kinds, BACKFILL_KINDS)) {
        fwrite(STDERR, "--kind: news, gw или item\n");
        return 1;
    }
    $onlyLang = isset($opts['lang']) ? (string)$opts['lang'] : null;
    if ($onlyLang !== null && !in_array($onlyLang, I18N_ITEM_LANGS, true)) {
        fwrite(STDERR, '--lang: ' . implode(', ', I18N_ITEM_LANGS) . "\n");
        return 1;
    }
    $dry = isset($opts['dry-run']);
    $pauseMs = isset($opts['pause']) ? max(0, (int)$opts['pause']) : BACKFILL_PAUSE_MS;
    $maxWait = 60 * (isset($opts['max-wait']) ? max(0, (int)$opts['max-wait']) : BACKFILL_MAX_WAIT_MIN);

    $pdo = db();
    if (!i18n_ready($pdo)) {
        fwrite(STDERR, "таблицы i18n_texts нет — сначала docs/migrations/2026-10-20-i18n-texts.sql\n");
        return 1;
    }

    $log = function (string $line) { echo '[' . date('H:i') . '] ' . $line . "\n"; };
    $requestFor = function (string $lang) use ($pauseMs): callable {
        return function (string $text) use ($lang, $pauseMs): ?string { return backfill_request($text, $lang, $pauseMs); };
    };
    $probe = function () use ($pauseMs): bool { return backfill_request('Привет', 'de', $pauseMs) !== null; };
    $sleep = function (int $s) { sleep($s); };

    $failed = 0;
    foreach ($kinds as $kind) {
        $langs = I18N_ITEM_LANGS;
        if ($kind !== 'item') { $langs = I18N_CONTENT_LANGS; }
        if ($onlyLang !== null) { $langs = array_values(array_intersect($langs, [$onlyLang])); }
        if (!$langs) { continue; }
        $targets = backfill_targets($pdo, $kind);
        if ($dry) {
            $n = 0;
            foreach ($targets as $name => $load) {
                $m = backfill_missing($pdo, $kind, $load(), $langs);
                if ($m) { echo "$name: не хватает $m\n"; }
                $n += $m;
            }
            echo "== $kind: не хватает $n\n";
            $failed += $n;
            continue;
        }
        $fill = function (callable $load) use ($pdo, $kind, $langs, $requestFor): array {
            return i18n_fill($pdo, $kind, $load, $langs, $requestFor, microtime(true) + 3600, $kind === 'item');
        };
        $sum = backfill_run($targets, $fill, $probe, $sleep, $log, $maxWait);
        echo "== $kind: переведено {$sum['filled']}, взято готовое {$sum['reused']}, не вышло {$sum['failed']}\n";
        $failed += $sum['failed'];
        if ($sum['aborted']) { return 1; }
    }
    return $failed ? 1 : 0;
}

if (PHP_SAPI === 'cli' && !defined('TESTING')) {
    exit(backfill_main(getopt('', ['config:', 'kind:', 'lang:', 'dry-run', 'pause:', 'max-wait:'])));
}
