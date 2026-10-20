<?php
// Контент на языках сайта: новости, розыгрыши, описания предметов тирлиста.
//
// Русский и английский живут, где жили: в колонках *_ru/*_en и в полях
// desc/descEn предметов. Остальные языки — в таблице i18n_texts, строка на
// место текста (вид, запись, поле, язык) с хешем русского исходника
// (docs/migrations/2026-10-20-i18n-texts.sql). Перевод исправленного
// текста по хешу больше не совпадает и не показывается, пока его не
// переведут заново, — откат идёт на EN, без него на RU (спека, раздел 5).
//
// Переводит Google тем же эндпоинтом, что EN поста (api/lib/translate.php),
// после ответа админке на сохранение (i18n_after_save()) и командой
// php tools/i18n_backfill.php для всего уже опубликованного.
//
// Выдача с ?lang= одноязычная той же формы, что и без него: текст языка
// страницы лежит в «своём» слоте (на русской странице в русских полях, на
// остальных — в английских), второй слот пуст. Клиент выбирает текст как
// раньше — «свой || другой», — и ему не нужно знать про восемь языков.
// Без ?lang= ответ прежний, на двух языках: его читают админка и скрипты из
// кеша браузеров, выпущенные до этого PR.
//
// Таблицы может не быть (окно между выкладкой и миграцией): чтение тогда
// отдаёт EN/RU, фоновый перевод молча ничего не делает.
//
// PHP 7.4: без match, str_contains и именованных аргументов.

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/news_blocks.php';
require_once __DIR__ . '/translate.php';

// На что переводим с русского. EN новостей и розыгрышей сюда не входит: он
// в колонках и переводится при сохранении, как и до этого PR.
const I18N_CONTENT_LANGS = ['de', 'fr', 'es', 'it', 'pt', 'pl', 'tr', 'uk'];
// У предметов перевод на EN тоже фоновый: ручного автоперевода у них не
// было, и описание без descEn показывалось на английской странице по-русски.
// Ручной descEn по-прежнему главнее (unit['manual']['en']).
const I18N_ITEM_LANGS = ['en', 'de', 'fr', 'es', 'it', 'pt', 'pl', 'tr', 'uk'];
// Поля предмета, которые переводятся, и их ручная английская пара. Значок
// (tag) не переводится: по нему работают фильтры тирлиста.
const I18N_ITEM_FIELDS = ['desc' => 'descEn', 'terms' => 'termsEn'];
// Сколько секунд фоновому переводу после сохранения. Восемь-девять языков
// по одному-двум запросам — несколько секунд; потолок нужен на случай, когда
// Google отвечает медленно, а первое сохранение тирлиста после миграции
// переводит сразу все описания.
const I18N_BG_BUDGET = 90;

function i18n_api_lang($raw): ?string {
    return is_string($raw) && in_array($raw, I18N_LANGS, true) ? $raw : null;
}

// Хеш русского исходника: строки как есть, спанов — с форматированием, так
// что перевод абзаца, у которого стало жирным другое слово, тоже устаревает.
function i18n_hash($ru): string {
    return sha1(is_array($ru) ? tr_spans_key($ru) : (string)$ru);
}

function i18n_ready(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM i18n_texts LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// --------------------------------------------------------------------------
//  Места с текстом
// --------------------------------------------------------------------------
//
// Место — ['ru' => строка или спаны, 'spans' => bool]; у предмета ещё
// 'manual' => ['en' => bool] — есть ли ручной EN. Пустой русский текст
// местом не считается: переводить нечего.

// Блоки поста или розыгрыша. Имя поля — номер блока и пункта списка:
// b3 — абзац, цитата или подпись картинки блока 3, b3.1 — пункт 1 списка.
// Код (t: code) не переводится.
function i18n_block_field(array $u): string {
    return 'b' . $u[0] . ($u[1] === null ? '' : '.' . $u[1]);
}

function i18n_block_units(array $blocks): array {
    $out = [];
    foreach (tr_units($blocks) as $u) {
        $ru = tr_unit_get($blocks, $u, 'ru');
        if (tr_spans_empty($ru)) { continue; }
        $out[i18n_block_field($u)] = ['ru' => $ru, 'spans' => true];
    }
    return $out;
}

// Пост: заголовок и блоки, у поста старого формата — абзацы body_ru (p0, p1…).
function i18n_units_news(string $titleRu, ?array $blocks, string $bodyRu): array {
    $out = [];
    if (trim($titleRu) !== '') { $out['title'] = ['ru' => $titleRu, 'spans' => false]; }
    if ($blocks !== null) { return $out + i18n_block_units($blocks); }
    foreach (tr_paragraphs($bodyRu) as $i => $p) { $out['p' . $i] = ['ru' => $p, 'spans' => false]; }
    return $out;
}

// Розыгрыш: заголовок, блоки и свои условия — c2 и c2.btn, номер как в
// gw_conds(). $conds — [['ru' => текст, 'btn_ru' => надпись], …].
function i18n_units_gw(string $titleRu, array $blocks, array $conds): array {
    $out = [];
    if (trim($titleRu) !== '') { $out['title'] = ['ru' => $titleRu, 'spans' => false]; }
    $out += i18n_block_units($blocks);
    foreach (array_values($conds) as $i => $c) {
        $text = (string)($c['ru'] ?? '');
        $btn = (string)($c['btn_ru'] ?? '');
        if (trim($text) !== '') { $out['c' . $i] = ['ru' => $text, 'spans' => false]; }
        if (trim($btn) !== '') { $out['c' . $i . '.btn'] = ['ru' => $btn, 'spans' => false]; }
    }
    return $out;
}

// Предметы тирлиста: [id предмета => [desc|terms => место]].
function i18n_units_items(array $tl): array {
    $out = [];
    foreach (i18n_items($tl) as $it) {
        $id = $it['id'];
        foreach (I18N_ITEM_FIELDS as $base => $enKey) {
            $ru = trim((string)($it[$base] ?? ''));
            if ($ru === '') { continue; }
            $out[$id][$base] = [
                'ru' => $ru, 'spans' => false,
                'manual' => ['en' => trim((string)($it[$enKey] ?? '')) !== ''],
            ];
        }
    }
    return $out;
}

// Предметы с годным id (строка до 40 символов — столько вмещает ref_id).
function i18n_items(array $tl): array {
    $out = [];
    foreach ((is_array($tl['tiers'] ?? null) ? $tl['tiers'] : []) as $tier) {
        foreach ((is_array($tier['items'] ?? null) ? $tier['items'] : []) as $it) {
            if (!is_array($it) || !isset($it['id']) || !is_string($it['id'])) { continue; }
            if ($it['id'] === '' || strlen($it['id']) > 40) { continue; }
            $out[] = $it;
        }
    }
    return $out;
}

// --------------------------------------------------------------------------
//  Таблица
// --------------------------------------------------------------------------

// Переводы вида $kind на язык $lang: [ref_id][field] = ['hash', 'text'].
// $refs — только эти записи; без таблицы — пусто.
function i18n_rows(PDO $pdo, string $kind, string $lang, array $refs): array {
    $refs = array_values(array_unique(array_map('strval', $refs)));
    if (!$refs) { return []; }
    $out = [];
    try {
        foreach (array_chunk($refs, 200) as $chunk) {
            $st = $pdo->prepare(
                'SELECT ref_id, field, src_hash, text FROM i18n_texts
                  WHERE kind = ? AND lang = ? AND ref_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $st->execute(array_merge([$kind, $lang], $chunk));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string)$r['ref_id']][(string)$r['field']] = ['hash' => (string)$r['src_hash'], 'text' => (string)$r['text']];
            }
        }
    } catch (PDOException $e) {
        return [];
    }
    return $out;
}

// Перевод места или null: строки нет, она переводит прежнюю версию текста
// или (у спанов) испорчена.
function i18n_pick(array $rows, string $field, ?array $unit) {
    if ($unit === null || !isset($rows[$field])) { return null; }
    $row = $rows[$field];
    if ($row['hash'] !== i18n_hash($unit['ru'])) { return null; }
    if (empty($unit['spans'])) { return $row['text'] === '' ? null : $row['text']; }
    $spans = json_decode($row['text'], true);
    return nb_valid_spans($spans) && !tr_spans_empty($spans) ? $spans : null;
}

// Upsert без ON DUPLICATE KEY / ON CONFLICT: тесты идут на SQLite.
function i18n_put(PDO $pdo, string $kind, string $ref, string $field, string $lang, string $hash, string $text, int $now): void {
    $key = [':k' => $kind, ':r' => $ref, ':f' => $field, ':l' => $lang];
    $pdo->prepare('DELETE FROM i18n_texts WHERE kind = :k AND ref_id = :r AND field = :f AND lang = :l')->execute($key);
    $pdo->prepare(
        'INSERT INTO i18n_texts (kind, ref_id, field, lang, src_hash, text, updated_at)
         VALUES (:k, :r, :f, :l, :h, :t, :u)'
    )->execute($key + [':h' => $hash, ':t' => $text, ':u' => $now]);
}

// Удалённый пост или розыгрыш уносит свои переводы.
function i18n_forget(PDO $pdo, string $kind, string $ref): void {
    try {
        $pdo->prepare('DELETE FROM i18n_texts WHERE kind = :k AND ref_id = :r')->execute([':k' => $kind, ':r' => $ref]);
    } catch (PDOException $e) {
        // Таблицы ещё нет — забывать нечего.
    }
}

// --------------------------------------------------------------------------
//  Перевод недостающего
// --------------------------------------------------------------------------

/**
 * Переводит всё, чего нет или что устарело, на языки $langs.
 *
 * $load() — места записей вида $kind сейчас: [ref => [field => место]].
 * Зовётся заново перед записью каждого языка: пока Google переводил, админ
 * мог сохранить запись ещё раз, и перевод прежнего текста под новым хешем
 * не записывается (его переведёт задача того сохранения).
 * $requestFor($lang) — переводчик «русский → $lang» (tr_google_request в
 * обёртке, в тестах — подделка).
 * $wholeKind — строки записей, которых нет в $load(), тоже удаляются (так у
 * предметов: удалённый из тирлиста предмет уносит свои переводы). Иначе
 * чистятся только поля записей из $load().
 *
 * Перевод того же текста в той же записи (абзац сдвинулся, блок вставили
 * выше) берётся из таблицы без запроса к Google.
 *
 * Возвращает счётчики: filled — переведено, reused — взято готовое,
 * failed — не вышло (останется откат на EN/RU до следующего раза).
 */
function i18n_fill(PDO $pdo, string $kind, callable $load, array $langs, callable $requestFor, float $deadline, bool $wholeKind = false): array {
    $stats = ['filled' => 0, 'reused' => 0, 'failed' => 0];
    if (!i18n_ready($pdo)) { return $stats; }

    $units = $load();
    foreach ($langs as $lang) {
        if (microtime(true) > $deadline) { break; }
        $rows = i18n_rows($pdo, $kind, $lang, array_keys($units));
        $todo = [];
        $ready = [];
        foreach ($units as $ref => $fields) {
            $ref = (string)$ref;
            foreach ($fields as $field => $u) {
                if (!empty($u['manual'][$lang])) { continue; }
                $hash = i18n_hash($u['ru']);
                if (isset($rows[$ref][$field]) && $rows[$ref][$field]['hash'] === $hash) { continue; }
                $same = null;
                foreach ($rows[$ref] ?? [] as $row) {
                    if ($row['hash'] === $hash) { $same = $row['text']; break; }
                }
                if ($same !== null) {
                    $ready[] = [$ref, $field, $hash, $same];
                    $stats['reused']++;
                } else {
                    $todo[$ref . '|' . $field] = $u;
                }
            }
        }
        if ($todo) {
            $res = tr_translate_units($todo, $requestFor($lang), $deadline);
            foreach ($todo as $key => $u) {
                if ($res[$key] === null) { $stats['failed']++; continue; }
                [$ref, $field] = explode('|', $key, 2);
                $text = is_array($res[$key])
                    ? (string)json_encode($res[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : $res[$key];
                $ready[] = [$ref, $field, i18n_hash($u['ru']), $text];
                $stats['filled']++;
            }
        }
        if (!$ready) { continue; }

        $units = $load();
        $now = time();
        foreach ($ready as [$ref, $field, $hash, $text]) {
            $cur = $units[$ref][$field] ?? null;
            if ($cur === null || i18n_hash($cur['ru']) !== $hash) { continue; }
            i18n_put($pdo, $kind, $ref, $field, $lang, $hash, $text, $now);
        }
    }

    i18n_prune($pdo, $kind, $load(), $wholeKind);
    return $stats;
}

// Строки полей, которых в записи больше нет (абзац удалили, условие убрали).
function i18n_prune(PDO $pdo, string $kind, array $units, bool $wholeKind): void {
    try {
        if ($wholeKind) {
            $st = $pdo->prepare('SELECT DISTINCT ref_id, field FROM i18n_texts WHERE kind = :k');
            $st->execute([':k' => $kind]);
            $have = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $have = [];
            foreach (array_keys($units) as $ref) {
                $st = $pdo->prepare('SELECT DISTINCT ref_id, field FROM i18n_texts WHERE kind = :k AND ref_id = :r');
                $st->execute([':k' => $kind, ':r' => (string)$ref]);
                $have = array_merge($have, $st->fetchAll(PDO::FETCH_ASSOC));
            }
        }
        $del = $pdo->prepare('DELETE FROM i18n_texts WHERE kind = :k AND ref_id = :r AND field = :f');
        foreach ($have as $r) {
            if (isset($units[(string)$r['ref_id']][(string)$r['field']])) { continue; }
            $del->execute([':k' => $kind, ':r' => (string)$r['ref_id'], ':f' => (string)$r['field']]);
        }
    } catch (PDOException $e) {
        error_log('i18n_prune: ' . $e->getMessage());
    }
}

function i18n_google_for(string $lang): callable {
    return function (string $text) use ($lang): ?string { return tr_google_request($text, $lang); };
}

// --------------------------------------------------------------------------
//  Задачи после сохранения
// --------------------------------------------------------------------------

// Строка поста в места: [id => места] или пусто, если поста нет.
function i18n_news_load(PDO $pdo, int $id): array {
    $st = $pdo->prepare('SELECT title_ru, body_ru, body_json FROM news WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { return []; }
    return [(string)$id => i18n_units_news((string)$row['title_ru'], i18n_doc_blocks($row['body_json']), (string)$row['body_ru'])];
}

function i18n_job_news(PDO $pdo, int $id, callable $requestFor, float $deadline): array {
    $load = function () use ($pdo, $id): array { return i18n_news_load($pdo, $id); };
    return i18n_fill($pdo, 'news', $load, I18N_CONTENT_LANGS, $requestFor, $deadline);
}

function i18n_items_load(PDO $pdo): array {
    $raw = $pdo->query('SELECT data FROM tierlist WHERE id = 1')->fetchColumn();
    $tl = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($tl) ? i18n_units_items($tl) : [];
}

function i18n_job_items(PDO $pdo, callable $requestFor, float $deadline): array {
    $load = function () use ($pdo): array { return i18n_items_load($pdo); };
    return i18n_fill($pdo, 'item', $load, I18N_ITEM_LANGS, $requestFor, $deadline, true);
}

/**
 * Запустить перевод после ответа админке. Ответ уже ушёл (json_out()), здесь
 * он закрывается для браузера — tg_finish_response() из api/lib/telegram.php,
 * который endpoint уже подключил, — и скрипт работает дальше. Любая ошибка
 * только пишется в лог: сохранение уже состоялось.
 * $job(float $deadline) — сама задача.
 */
function i18n_after_save(callable $job): void {
    if (function_exists('tg_finish_response')) { tg_finish_response(); }
    if (function_exists('set_time_limit')) { @set_time_limit(I18N_BG_BUDGET + 30); }
    try {
        $job(microtime(true) + I18N_BG_BUDGET);
    } catch (Throwable $e) {
        error_log('i18n_after_save: ' . $e->getMessage());
    }
}

// --------------------------------------------------------------------------
//  Одноязычная выдача
// --------------------------------------------------------------------------

// body_json из базы (строка) или из выдачи (разобранный документ) → блоки
// или null, если их нет или они не проходят проверку.
function i18n_doc_blocks($bodyJson): ?array {
    $doc = is_string($bodyJson) ? json_decode($bodyJson, true) : $bodyJson;
    if (!is_array($doc)) { return null; }
    $v = news_blocks_validate($doc);
    return $v['ok'] ? $v['blocks'] : null;
}

// Текст места на языке $lang: перевод, иначе EN, иначе RU; на русском —
// RU, иначе EN. «Пусто» — как на клиенте: пустая строка или пустой массив.
function i18n_choose(string $lang, $ru, $en, $tr) {
    $has = function ($v): bool { return is_array($v) ? count($v) > 0 : trim((string)$v) !== ''; };
    if ($lang === I18N_DEFAULT) { return $has($ru) ? $ru : $en; }
    if ($tr !== null) { return $tr; }
    return $has($en) ? $en : $ru;
}

// Блоки на одном языке: свой слот (ru на русской странице, en на прочих)
// заполнен, второй пуст.
function i18n_blocks_localize(array $blocks, string $lang, array $rows): array {
    $self = $lang === I18N_DEFAULT ? 'ru' : 'en';
    $units = i18n_block_units($blocks);
    foreach ($blocks as $bi => $b) {
        if (($b['t'] ?? '') === 'code') {
            $text = i18n_choose($lang, (string)$b['ru'], (string)$b['en'], null);
            $blocks[$bi]['ru'] = $self === 'ru' ? $text : '';
            $blocks[$bi]['en'] = $self === 'en' ? $text : '';
        }
    }
    foreach (tr_units($blocks) as $u) {
        [$bi, $ii, $ruKey, $enKey] = $u;
        $field = i18n_block_field($u);
        $tr = $self === 'en' ? i18n_pick($rows, $field, $units[$field] ?? null) : null;
        $text = i18n_choose($lang, tr_unit_get($blocks, $u, 'ru'), tr_unit_get($blocks, $u, 'en'), $tr);
        $set = [$ruKey => $self === 'ru' ? $text : [], $enKey => $self === 'en' ? $text : []];
        foreach ($set as $k => $v) {
            if ($ii === null) { $blocks[$bi][$k] = $v; } else { $blocks[$bi]['items'][$ii][$k] = $v; }
        }
    }
    return $blocks;
}

// Пост из выдачи api/news.php (title_ru/en, body_ru/en, body_json — документ
// или null) на одном языке. $rows — i18n_rows() этого поста на $lang.
function i18n_news_localize(array $post, string $lang, array $rows): array {
    $self = $lang === I18N_DEFAULT ? 'ru' : 'en';
    $blocks = i18n_doc_blocks($post['body_json'] ?? null);
    $units = i18n_units_news((string)$post['title_ru'], $blocks, (string)$post['body_ru']);
    $pick = function (string $field) use ($self, $rows, $units) {
        return $self === 'en' ? i18n_pick($rows, $field, $units[$field] ?? null) : null;
    };

    $title = i18n_choose($lang, (string)$post['title_ru'], (string)$post['title_en'], $pick('title'));
    if ($blocks !== null) {
        $loc = i18n_blocks_localize($blocks, $lang, $rows);
        $post['body_json'] = ['v' => NB_DOC_VERSION, 'blocks' => $loc];
        $body = news_blocks_plain($loc, $self);
    } else {
        $paras = [];
        foreach (array_keys($units) as $field) {
            if ($field === 'title') { continue; }
            $tr = $pick($field);
            if ($tr === null) { $paras = null; break; }
            $paras[] = $tr;
        }
        $body = i18n_choose($lang, (string)$post['body_ru'], (string)$post['body_en'], $paras ? implode("\n\n", $paras) : null);
    }
    $post['title_ru'] = $self === 'ru' ? $title : '';
    $post['title_en'] = $self === 'en' ? $title : '';
    $post['body_ru'] = $self === 'ru' ? $body : '';
    $post['body_en'] = $self === 'en' ? $body : '';
    return $post;
}

// Розыгрыш из выдачи api/giveaways.php (gw_public_item()) на одном языке.
function i18n_gw_localize(array $item, string $lang, array $rows): array {
    $self = $lang === I18N_DEFAULT ? 'ru' : 'en';
    $blocks = is_array($item['body'] ?? null) ? $item['body'] : [];
    $conds = [];
    foreach ((is_array($item['conds'] ?? null) ? $item['conds'] : []) as $c) {
        $conds[] = ['ru' => (string)($c['text']['ru'] ?? ''), 'btn_ru' => (string)($c['btn']['ru'] ?? '')];
    }
    $units = i18n_units_gw((string)($item['title']['ru'] ?? ''), $blocks, $conds);
    $pair = function (array $p, string $field) use ($lang, $self, $rows, $units): array {
        $tr = $self === 'en' ? i18n_pick($rows, $field, $units[$field] ?? null) : null;
        $text = i18n_choose($lang, (string)($p['ru'] ?? ''), (string)($p['en'] ?? ''), $tr);
        return ['ru' => $self === 'ru' ? $text : '', 'en' => $self === 'en' ? $text : ''];
    };

    $item['title'] = $pair(is_array($item['title'] ?? null) ? $item['title'] : [], 'title');
    $item['body'] = i18n_blocks_localize($blocks, $lang, $rows);
    foreach ((is_array($item['conds'] ?? null) ? $item['conds'] : []) as $i => $c) {
        $item['conds'][$i]['text'] = $pair(is_array($c['text'] ?? null) ? $c['text'] : [], 'c' . $i);
        $item['conds'][$i]['btn'] = $pair(is_array($c['btn'] ?? null) ? $c['btn'] : [], 'c' . $i . '.btn');
    }
    return $item;
}

/**
 * Тирлист на одном языке: desc/terms предметов в своём слоте (desc/terms на
 * русском, descEn/termsEn на прочих), второй пуст. $rows — переводы
 * предметов на $lang, $enRows — на en (откат «язык → EN» берёт и
 * автоперевод EN, когда ручного нет).
 *
 * Второе значение — всё ли переведено: ответ на ревизию отдаётся с кешем на
 * год, и пока фоновый перевод не закончил, кешировать его так нельзя (см.
 * api/tierlist.php).
 */
function i18n_tierlist_localize(array $tl, string $lang, array $rows, array $enRows = []): array {
    $self = $lang === I18N_DEFAULT ? 'ru' : 'en';
    if ($lang === 'en') { $enRows = $rows; }
    $complete = true;
    foreach ((is_array($tl['tiers'] ?? null) ? $tl['tiers'] : []) as $ti => $tier) {
        foreach ((is_array($tier['items'] ?? null) ? $tier['items'] : []) as $ii => $it) {
            if (!is_array($it)) { continue; }
            $id = isset($it['id']) && is_string($it['id']) ? $it['id'] : '';
            foreach (I18N_ITEM_FIELDS as $base => $enKey) {
                $ru = trim((string)($it[$base] ?? ''));
                $en = trim((string)($it[$enKey] ?? ''));
                $tr = null;
                if ($self === 'en' && $ru !== '') {
                    $unit = ['ru' => $ru, 'spans' => false];
                    if ($en === '') { $en = (string)i18n_pick($enRows[$id] ?? [], $base, $unit); }
                    if ($lang !== 'en') { $tr = i18n_pick($rows[$id] ?? [], $base, $unit); }
                    if ($lang === 'en' ? $en === '' : $tr === null) { $complete = false; }
                }
                $text = i18n_choose($lang, $ru, $en, $tr);
                $tl['tiers'][$ti]['items'][$ii][$base] = $self === 'ru' ? $text : '';
                $tl['tiers'][$ti]['items'][$ii][$enKey] = $self === 'en' ? $text : '';
            }
        }
    }
    return [$tl, $complete];
}
