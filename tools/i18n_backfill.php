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
//   php tools/i18n_backfill.php --pause=500    пауза перед запросом, мс (300)
//   php tools/i18n_backfill.php --config=/home/maknemyt/config.php
//
// Без --config ищет config.php рядом с репозиторием (так он лежит локально),
// потом в домашнем каталоге (так на сервере: репозиторий deploy_repo отдельно
// от сайта, config.php — над public_html).
//
// Повторный запуск ничего не переводит заново: готовое узнаётся по хешу.
// Код выхода 1 — что-то не перевелось (тогда сайт показывает там EN, без
// него RU), запуск можно повторить позже.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$opts = getopt('', ['config:', 'kind:', 'lang:', 'dry-run', 'pause:']);
$config = isset($opts['config']) ? (string)$opts['config'] : null;
if ($config === null) {
    $home = rtrim((string)getenv('HOME'), '/');
    foreach ([__DIR__ . '/../config.php', $home === '' ? '' : $home . '/config.php'] as $c) {
        if ($c !== '' && is_file($c)) { $config = $c; break; }
    }
}
if ($config === null || !is_file($config)) {
    fwrite(STDERR, "config.php не найден — укажите --config=PATH\n");
    exit(1);
}
define('CONFIG_PATH', $config);
require_once __DIR__ . '/../public_html/api/_bootstrap.php';
require_once __DIR__ . '/../public_html/api/lib/i18n_content.php';
require_once __DIR__ . '/../public_html/api/lib/giveaway.php';

const BACKFILL_KINDS = ['news', 'gw', 'item'];
// Подряд столько записей без единого перевода — Google отказывает целиком,
// дальше только копились бы ожидания.
const BACKFILL_GIVE_UP = 3;

$kinds = isset($opts['kind']) ? [(string)$opts['kind']] : BACKFILL_KINDS;
if (array_diff($kinds, BACKFILL_KINDS)) {
    fwrite(STDERR, "--kind: news, gw или item\n");
    exit(1);
}
$onlyLang = isset($opts['lang']) ? (string)$opts['lang'] : null;
if ($onlyLang !== null && !in_array($onlyLang, I18N_ITEM_LANGS, true)) {
    fwrite(STDERR, '--lang: ' . implode(', ', I18N_ITEM_LANGS) . "\n");
    exit(1);
}
$dry = isset($opts['dry-run']);
$pauseMs = isset($opts['pause']) ? max(0, (int)$opts['pause']) : 300;

$pdo = db();
if (!i18n_ready($pdo)) {
    fwrite(STDERR, "таблицы i18n_texts нет — сначала docs/migrations/2026-10-20-i18n-texts.sql\n");
    exit(1);
}

function backfill_langs(array $all, ?string $only): array {
    return $only === null ? $all : array_values(array_intersect($all, [$only]));
}

// Переводчик с паузой перед каждым запросом: бесплатный эндпоинт Google
// легко отвечает 429 на частые запросы с одного адреса.
function backfill_request_for(int $pauseMs): callable {
    return function (string $lang) use ($pauseMs): callable {
        return function (string $text) use ($lang, $pauseMs): ?string {
            if ($pauseMs > 0) { usleep($pauseMs * 1000); }
            return tr_google_request($text, $lang);
        };
    };
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

// [вид => [id записи => загрузчик её мест]]. Предметы — одна запись на весь
// тирлист: их удобнее переводить пачкой.
function backfill_targets(PDO $pdo, string $kind): array {
    if ($kind === 'item') {
        return ['tierlist' => function () use ($pdo): array { return i18n_items_load($pdo); }];
    }
    $out = [];
    if ($kind === 'news') {
        foreach ($pdo->query('SELECT id FROM news ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int)$id;
            $out['#' . $id] = function () use ($pdo, $id): array { return i18n_news_load($pdo, $id); };
        }
        return $out;
    }
    if (!gw_ready($pdo)) { return []; }
    foreach ($pdo->query('SELECT id FROM giveaways ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $id = (int)$id;
        $out['#' . $id] = function () use ($pdo, $id): array { return gw_i18n_load($pdo, $id); };
    }
    return $out;
}

$failedTotal = 0;
foreach ($kinds as $kind) {
    $langs = backfill_langs($kind === 'item' ? I18N_ITEM_LANGS : I18N_CONTENT_LANGS, $onlyLang);
    if (!$langs) { continue; }
    $sum = ['filled' => 0, 'reused' => 0, 'failed' => 0];
    $dead = 0;
    foreach (backfill_targets($pdo, $kind) as $name => $load) {
        if ($dry) {
            $n = backfill_missing($pdo, $kind, $load(), $langs);
            if ($n) { echo "$kind $name: не хватает $n\n"; }
            $sum['failed'] += $n;
            continue;
        }
        $st = i18n_fill($pdo, $kind, $load, $langs, backfill_request_for($pauseMs), microtime(true) + 3600, $kind === 'item');
        foreach ($sum as $k => $_) { $sum[$k] += $st[$k]; }
        if ($st['filled'] || $st['reused'] || $st['failed']) {
            echo "$kind $name: переведено {$st['filled']}, взято готовое {$st['reused']}, не вышло {$st['failed']}\n";
        }
        $dead = ($st['failed'] && !$st['filled']) ? $dead + 1 : 0;
        if ($dead >= BACKFILL_GIVE_UP) {
            fwrite(STDERR, "Google отказывает ($dead записи подряд без перевода) — остановлено, повторите позже\n");
            exit(1);
        }
    }
    echo $dry
        ? "== $kind: не хватает {$sum['failed']}\n"
        : "== $kind: переведено {$sum['filled']}, взято готовое {$sum['reused']}, не вышло {$sum['failed']}\n";
    $failedTotal += $sum['failed'];
}
exit($failedTotal ? 1 : 0);
