<?php
// Автоперевод новостей RU → EN.
//
// Переводчик — бесплатный эндпоинт Google translate_a/single (client=gtx):
// без ключа и без договора, поэтому Google вправе в любой момент ограничить
// или закрыть его. Отсюда главное правило файла: перевод — best effort.
// Любой сбой оставляет EN пустым, пост сохраняется как обычно, а читатель на
// английском видит русский текст — тот же откат, что был и до автоперевода
// (pickSpans() в js/news-blocks.js, nb_spans_text() в api/lib/news_blocks.php).
//
// Ручной перевод не трогается: заполняются только пустые EN-поля и те, что
// остались от прежней версии русского текста (см. tr_clear_stale()).
//
// PHP 7.4: без match, без str_contains, без именованных аргументов.

require_once __DIR__ . '/news_blocks.php';

const TR_ENDPOINT    = 'https://translate.googleapis.com/translate_a/single?client=gtx&dt=t&dj=1&sl=ru&tl=en';
// Потолок одного запроса в символах. Эндпоинт принимал и 16 000 (проверено
// 2026-09-19), но веб-переводчик Google сам режет по 5000 — держимся ниже.
const TR_CHUNK_CHARS = 4500;
// Абзацы одного запроса разделяются пустой строкой: Google переводит их по
// отдельности и возвращает с тем же разделителем.
const TR_SEP         = "\n\n";
// Общий бюджет времени на перевод одного поста. Сохранение ждёт перевода,
// и зависший Google не должен держать редактор дольше этого.
const TR_BUDGET_SEC  = 15;
// Форматированный кусок абзаца уходит в Google обёрнутым в <xN>…</xN>, где N —
// номер исходного спана. Пробелы внутри тега допускаются: Google иногда
// возвращает "< x1 >".
const TR_TAG_RE      = '~<\s*(/?)\s*x\s*(\d+)\s*>~i';

// Один запрос к Google. null — любой сбой: сеть, не-200, не тот JSON.
//
// Сначала TLS не выше 1.2: 2026-09-19 Google отвечал 429 «Sorry…» на КАЖДЫЙ
// запрос из PHP с curl 7.76 + OpenSSL 1.1.1 по TLS 1.3, а тот же запрос по
// TLS 1.2 (и curl.exe на Schannel по любому TLS) проходил. Какая сборка на
// бою — заранее не знать, поэтому при сбое вторая попытка идёт с настройками
// по умолчанию.
function tr_google_request(string $text): ?string {
    if (!function_exists('curl_init')) { return null; }
    $attempts = [];
    if (defined('CURL_SSLVERSION_MAX_TLSv1_2')) {
        $attempts[] = CURL_SSLVERSION_TLSv1_2 | CURL_SSLVERSION_MAX_TLSv1_2;
    }
    $attempts[] = null;
    foreach ($attempts as $ssl) {
        $ch = curl_init(TR_ENDPOINT);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['q' => $text]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($ssl !== null) { $opts[CURLOPT_SSLVERSION] = $ssl; }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code === 200 && is_string($raw)) {
            $out = tr_google_parse($raw);
            if ($out !== null) { return $out; }
        }
    }
    return null;
}

// Ответ dj=1: {"sentences":[{"trans":"…","orig":"…"},…],"src":"ru"}. Перевод —
// склейка всех trans по порядку: Google режет текст на предложения сам.
function tr_google_parse(string $raw): ?string {
    $d = json_decode($raw, true);
    if (!is_array($d) || !isset($d['sentences']) || !is_array($d['sentences'])) { return null; }
    $out = '';
    foreach ($d['sentences'] as $s) {
        if (is_array($s) && isset($s['trans']) && is_string($s['trans'])) { $out .= $s['trans']; }
    }
    return trim($out) === '' ? null : $out;
}

// Переводит список текстов. Возвращает массив с теми же ключами: строка —
// перевод, null — этот текст перевести не удалось.
//
// $request — функция «текст → перевод или null». На бою это
// tr_google_request(), в тестах — подделка без сети.
//
// Подряд идущие однострочные тексты склеиваются в один запрос через
// TR_SEP. Текст с переводом строки внутри идёт отдельно: его собственная
// пустая строка сломала бы разбиение ответа. Если Google вернул другое число
// абзацев (склеил или разбил), тексты этой группы переводятся по одному.
//
// Два неудачных запроса подряд обрывают перевод: Google лежит или отказал, и
// каждый следующий запрос только добавил бы ожидания к сохранению поста.
function tr_translate_texts(array $texts, callable $request, float $deadline): array {
    $out = array_fill_keys(array_keys($texts), null);

    $groups = [];
    $cur = [];
    $len = 0;
    foreach ($texts as $k => $t) {
        $n = mb_strlen($t);
        if (strpos($t, "\n") !== false || $n > TR_CHUNK_CHARS) {
            if ($cur) { $groups[] = $cur; $cur = []; $len = 0; }
            $groups[] = [$k];
            continue;
        }
        if ($cur && $len + strlen(TR_SEP) + $n > TR_CHUNK_CHARS) {
            $groups[] = $cur;
            $cur = [];
            $len = 0;
        }
        $len += ($cur ? strlen(TR_SEP) : 0) + $n;
        $cur[] = $k;
    }
    if ($cur) { $groups[] = $cur; }

    $fails = 0;
    $one = function ($text) use ($request, &$fails): ?string {
        $r = $request($text);
        if ($r === null || trim($r) === '') { $fails++; return null; }
        $fails = 0;
        return trim($r);
    };

    foreach ($groups as $g) {
        if ($fails >= 2 || microtime(true) > $deadline) { break; }
        $joined = [];
        foreach ($g as $k) { $joined[] = $texts[$k]; }
        $res = $one(implode(TR_SEP, $joined));
        if ($res === null) { continue; }
        if (count($g) === 1) { $out[$g[0]] = $res; continue; }

        $parts = preg_split('~\n\s*\n~u', $res);
        if (is_array($parts) && count($parts) === count($g)) {
            foreach ($g as $i => $k) {
                $p = trim($parts[$i]);
                $out[$k] = $p === '' ? null : $p;
            }
            continue;
        }
        foreach ($g as $k) {
            if ($fails >= 2 || microtime(true) > $deadline) { break; }
            $out[$k] = $one($texts[$k]);
        }
    }
    return $out;
}

// --- Спаны ------------------------------------------------------------------

function tr_span_is_plain(array $sp): bool {
    foreach ($sp as $k => $v) {
        if ($k !== 's' && $v) { return false; }
    }
    return true;
}

function tr_spans_text($spans): string {
    $out = '';
    foreach ((is_array($spans) ? $spans : []) as $sp) { $out .= (string)($sp['s'] ?? ''); }
    return $out;
}

function tr_spans_empty($spans): bool {
    return trim(tr_spans_text($spans)) === '';
}

// Ключ сравнения: одинаковый текст с одинаковым форматированием даёт одну и
// ту же строку при любом порядке ключей и с выброшенными false-флагами —
// {"s":"a","b":false} и {"s":"a"} для читателя одно и то же.
function tr_spans_key($spans): string {
    $norm = [];
    foreach ((is_array($spans) ? $spans : []) as $sp) {
        $f = [];
        foreach ($sp as $k => $v) {
            if ($k === 's' || $v) { $f[$k] = $v; }
        }
        ksort($f);
        $norm[] = $f;
    }
    return (string)json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Абзац со спанами → строка для Google. Простые спаны идут как есть,
// форматированные — в теге <xN> с номером исходного спана: по нему
// tr_spans_decode() вернёт переводу жирность, ссылку и прочее.
function tr_spans_encode(array $spans): string {
    $out = '';
    foreach ($spans as $i => $sp) {
        $s = (string)$sp['s'];
        $out .= tr_span_is_plain($sp) ? $s : '<x' . $i . '>' . $s . '</x' . $i . '>';
    }
    return $out;
}

function tr_format_key(array $sp): string {
    $f = [];
    foreach ($sp as $k => $v) {
        if ($k !== 's' && $v) { $f[$k] = $v; }
    }
    ksort($f);
    return (string)json_encode($f, JSON_UNESCAPED_SLASHES);
}

// Соседние куски с одинаковым форматированием склеиваются — та же форма, в
// которой спаны собирает редактор (blockToSpans() в js/news-editor.js).
function tr_push_span(array &$out, array $sp): void {
    $n = count($out);
    if ($n && tr_format_key($out[$n - 1]) === tr_format_key($sp)) {
        $out[$n - 1]['s'] .= $sp['s'];
        return;
    }
    $out[] = $sp;
}

// Google теряет пробел на краю тега: «<x1>Призы: </x1>1-5» приходит как
// «<x1>Prizes:</x1>1-5», и слова слипаются. Если у исходного спана пробел
// на краю был, а у перевода нет — он возвращается. Двойной пробел, который
// из-за этого может выйти на стыке, убирает tr_join_spaces().
function tr_keep_edge_space(string $seg, string $orig): string {
    if (preg_match('~\s$~u', $orig) && !preg_match('~\s$~u', $seg)) { $seg .= ' '; }
    if (preg_match('~^\s~u', $orig) && !preg_match('~^\s~u', $seg)) { $seg = ' ' . $seg; }
    return $seg;
}

function tr_join_spaces(array $spans): array {
    for ($i = 1, $n = count($spans); $i < $n; $i++) {
        if (preg_match('~\s$~u', $spans[$i - 1]['s']) && preg_match('~^\s~u', $spans[$i]['s'])) {
            $spans[$i]['s'] = (string)preg_replace('~^\s~u', '', $spans[$i]['s']);
        }
    }
    return $spans;
}

// Перевод с тегами → спаны. null — разметка не сходится (незнакомый номер,
// тег закрыт не в том порядке, остался открытым): тогда вызывающий
// переводит спаны по одному, см. tr_fallback_texts().
//
// Код внутри абзаца не переводится: вместо ответа Google встаёт исходный
// текст спана, и только один раз, даже если Google разрезал тег надвое.
function tr_spans_decode(string $text, array $orig): ?array {
    $parts = preg_split(TR_TAG_RE, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts)) { return null; }
    $out = [];
    $stack = [];
    $codeDone = [];
    $n = count($parts);
    for ($k = 0; $k < $n; $k += 3) {
        $seg = $parts[$k];
        if ($seg !== '') {
            if (!$stack) {
                tr_push_span($out, ['s' => $seg]);
            } else {
                $top = $stack[count($stack) - 1];
                $sp = $orig[$top];
                if (!empty($sp['c'])) {
                    if (empty($codeDone[$top])) {
                        tr_push_span($out, $sp);
                        $codeDone[$top] = true;
                    }
                } else {
                    $sp['s'] = tr_keep_edge_space($seg, (string)$sp['s']);
                    tr_push_span($out, $sp);
                }
            }
        }
        if ($k + 2 >= $n) { break; }
        $idx = (int)$parts[$k + 2];
        if (!isset($orig[$idx]) || tr_span_is_plain($orig[$idx])) { return null; }
        if ($parts[$k + 1] === '/') {
            if (!$stack || $stack[count($stack) - 1] !== $idx) { return null; }
            array_pop($stack);
        } else {
            $stack[] = $idx;
        }
    }
    if ($stack) { return null; }

    $res = [];
    foreach (tr_join_spaces($out) as $sp) {
        if ($sp['s'] !== '') { $res[] = $sp; }
    }
    if (!$res || count($res) > NB_LIMIT_SPANS || tr_spans_empty($res)) { return null; }
    return $res;
}

// Запасной путь: каждый спан переводится отдельно и сохраняет свои флаги.
// Связность фразы страдает, зато разметка не может разъехаться. Возвращает
// [номер спана => текст без краевых пробелов] для тех спанов, которые
// переводить есть что: код и пробелы остаются как были.
function tr_fallback_texts(array $spans): array {
    $texts = [];
    foreach ($spans as $i => $sp) {
        $s = trim((string)$sp['s']);
        if ($s !== '' && empty($sp['c'])) { $texts[$i] = $s; }
    }
    return $texts;
}

// Google срезает краевые пробелы, а между спанами они значимы («слово
// <b>жирное</b> слово») — поэтому пробелы вокруг берутся из оригинала.
function tr_fallback_apply(array $spans, array $translated): ?array {
    $out = [];
    foreach ($spans as $i => $sp) {
        if (array_key_exists($i, $translated)) {
            if ($translated[$i] === null) { return null; }
            preg_match('~^(\s*).*?(\s*)$~su', (string)$sp['s'], $m);
            $sp['s'] = ($m[1] ?? '') . $translated[$i] . ($m[2] ?? '');
        }
        tr_push_span($out, $sp);
    }
    return tr_spans_empty($out) ? null : $out;
}

// Google меняет длинное тире на дефис: «• Z — атака» → «• Z - attack».
// Возвращаем тире, но только если в оригинале нет своего « - » — иначе не
// отличить, какой дефис был задуман.
function tr_fix_dashes(string $en, string $ru): string {
    if (strpos($ru, '—') !== false && strpos($ru, ' - ') === false) {
        return str_replace(' - ', ' — ', $en);
    }
    return $en;
}

// --- Места с текстом в блоках -----------------------------------------------

// Все места, где у блока лежит пара RU/EN со спанами: [блок, пункт списка
// или null, ключ RU, ключ EN]. Код (t: code) сюда не входит намеренно: его
// не переводят, и пустой EN у него и так показывает исходник.
function tr_units(array $blocks): array {
    $units = [];
    foreach ($blocks as $bi => $b) {
        $t = $b['t'] ?? '';
        if ($t === 'p' || $t === 'quote') {
            $units[] = [$bi, null, 'ru', 'en'];
        } elseif ($t === 'list') {
            foreach ($b['items'] as $ii => $_) { $units[] = [$bi, $ii, 'ru', 'en']; }
        } elseif ($t === 'image' || $t === 'album') {
            $units[] = [$bi, null, 'cap_ru', 'cap_en'];
        }
    }
    return $units;
}

function tr_unit_get(array $blocks, array $u, string $lang): array {
    [$bi, $ii, $ruKey, $enKey] = $u;
    $key = $lang === 'en' ? $enKey : $ruKey;
    $v = $ii === null ? ($blocks[$bi][$key] ?? []) : ($blocks[$bi]['items'][$ii][$key] ?? []);
    return is_array($v) ? $v : [];
}

function tr_unit_set_en(array &$blocks, array $u, array $spans): void {
    [$bi, $ii, , $enKey] = $u;
    if ($ii === null) {
        $blocks[$bi][$enKey] = $spans;
    } else {
        $blocks[$bi]['items'][$ii][$enKey] = $spans;
    }
}

// То же, что toParagraphs() в js/news.js.
function tr_paragraphs(string $text): array {
    $parts = preg_split('~\n\s*\n~u', str_replace(["\r\n", "\r"], "\n", $text));
    $out = [];
    foreach ((is_array($parts) ? $parts : []) as $p) {
        $p = trim($p);
        if ($p !== '') { $out[] = $p; }
    }
    return $out;
}

// Пары «EN → какие RU он переводил» в прежней версии поста. Ключи — из
// tr_spans_key(). Легаси-пост (без body_json) редактор превращает в абзацы
// попарно, RU и EN с одним номером в один блок (blocksFromLegacy() в
// js/news-editor.js) — здесь то же самое.
function tr_old_pairs(?array $old): array {
    $map = [];
    if (!$old) { return $map; }
    $blocks = null;
    if (!empty($old['body_json'])) {
        $d = json_decode((string)$old['body_json'], true);
        $v = news_blocks_validate($d);
        if ($v['ok']) { $blocks = $v['blocks']; }
    }
    if ($blocks !== null) {
        foreach (tr_units($blocks) as $u) {
            $map[tr_spans_key(tr_unit_get($blocks, $u, 'en'))][tr_spans_key(tr_unit_get($blocks, $u, 'ru'))] = true;
        }
        return $map;
    }
    $ru = tr_paragraphs((string)($old['body_ru'] ?? ''));
    $en = tr_paragraphs((string)($old['body_en'] ?? ''));
    $n = max(count($ru), count($en));
    for ($i = 0; $i < $n; $i++) {
        $r = isset($ru[$i]) ? [['s' => $ru[$i]]] : [];
        $e = isset($en[$i]) ? [['s' => $en[$i]]] : [];
        $map[tr_spans_key($e)][tr_spans_key($r)] = true;
    }
    return $map;
}

// Устаревший перевод: EN пришёл ровно таким, каким был в прежней версии, а
// RU рядом с ним уже другой. Значит, русский текст поправили, а английский
// никто не трогал — он переводит то, чего в посте больше нет. Такой EN
// очищается и переводится заново. EN, который правили вместе с RU или
// написали впервые, в прежней версии не встречается и остаётся как есть.
//
// Очистка может опустошить блок целиком: если у абзаца стёрли русский текст,
// а английский остался от старой версии, без очистки он показывался бы и на
// русской странице (откат на второй язык). Такие блоки и пункты списка
// выбрасываются — как пустые блоки выбрасывает редактор (currentDoc()).
function tr_clear_stale(array $blocks, array $oldPairs): array {
    if (!$oldPairs) { return $blocks; }
    $clearedBlocks = [];
    $clearedItems = [];
    foreach (tr_units($blocks) as $u) {
        $en = tr_unit_get($blocks, $u, 'en');
        if (tr_spans_empty($en)) { continue; }
        $enKey = tr_spans_key($en);
        if (!isset($oldPairs[$enKey])) { continue; }
        if (isset($oldPairs[$enKey][tr_spans_key(tr_unit_get($blocks, $u, 'ru'))])) { continue; }
        tr_unit_set_en($blocks, $u, []);
        $clearedBlocks[$u[0]] = true;
        if ($u[1] !== null) { $clearedItems[$u[0]][$u[1]] = true; }
    }
    if (!$clearedBlocks) { return $blocks; }

    $out = [];
    foreach ($blocks as $bi => $b) {
        if (isset($clearedBlocks[$bi])) {
            if ($b['t'] === 'p' || $b['t'] === 'quote') {
                if (tr_spans_empty($b['ru']) && tr_spans_empty($b['en'])) { continue; }
            } elseif ($b['t'] === 'list') {
                $items = [];
                foreach ($b['items'] as $ii => $it) {
                    if (isset($clearedItems[$bi][$ii]) && tr_spans_empty($it['ru']) && tr_spans_empty($it['en'])) { continue; }
                    $items[] = $it;
                }
                $hasText = false;
                foreach ($items as $it) {
                    if (!tr_spans_empty($it['ru']) || !tr_spans_empty($it['en'])) { $hasText = true; }
                }
                if (!$hasText) { continue; }
                $b['items'] = $items;
            }
        }
        $out[] = $b;
    }
    return $out;
}

// Главная функция. $post — проверенный пост из validate_news_post()
// (title_ru, title_en, body_ru, body_en). $blocks — проверенные блоки или
// null у легаси-формы. $old — прежняя строка поста (title_ru, title_en,
// body_ru, body_en, body_json) или null у нового поста.
//
// Возвращает ['post', 'blocks', 'filled', 'failed']: пост и блоки с
// заполненным EN и счётчики — сколько мест переведено и сколько не вышло.
// Пределы длины здесь не проверяются: это дело handle_news_save(), который
// откатывается на непереведённую версию, если перевод в них не влез.
function news_autotranslate(array $post, ?array $blocks, ?array $old, callable $request, ?float $deadline = null): array {
    $deadline = $deadline ?? (microtime(true) + TR_BUDGET_SEC);

    // Заголовок устарел по той же логике, что и абзацы в tr_clear_stale().
    if ($old && $post['title_en'] !== '' && $post['title_en'] === (string)$old['title_en']
        && $post['title_ru'] !== (string)$old['title_ru']) {
        $post['title_en'] = '';
    }

    $texts = [];
    $jobs = [];
    if ($post['title_en'] === '' && $post['title_ru'] !== '') {
        $texts['title'] = $post['title_ru'];
    }

    $paras = [];
    if ($blocks !== null) {
        $blocks = tr_clear_stale($blocks, tr_old_pairs($old));
        foreach (tr_units($blocks) as $u) {
            $ru = tr_unit_get($blocks, $u, 'ru');
            if (tr_spans_empty($ru) || !tr_spans_empty(tr_unit_get($blocks, $u, 'en'))) { continue; }
            $key = 'u' . count($jobs);
            $jobs[$key] = $u;
            $texts[$key] = tr_spans_encode($ru);
        }
    } else {
        if ($old && $post['body_en'] !== '' && $post['body_en'] === (string)$old['body_en']
            && $post['body_ru'] !== (string)$old['body_ru']) {
            $post['body_en'] = '';
        }
        if ($post['body_en'] === '' && $post['body_ru'] !== '') {
            $paras = tr_paragraphs($post['body_ru']);
            foreach ($paras as $i => $p) { $texts['p' . $i] = $p; }
        }
    }

    if (!$texts) {
        return ['post' => $post, 'blocks' => $blocks, 'filled' => 0, 'failed' => 0];
    }

    $res = tr_translate_texts($texts, $request, $deadline);
    $filled = 0;
    $failed = 0;

    if (array_key_exists('title', $res)) {
        if ($res['title'] === null) {
            $failed++;
        } else {
            $post['title_en'] = tr_fix_dashes($res['title'], $post['title_ru']);
            $filled++;
        }
    }

    $retry = [];
    foreach ($jobs as $key => $u) {
        if ($res[$key] === null) { $failed++; continue; }
        $ru = tr_unit_get($blocks, $u, 'ru');
        $spans = tr_spans_decode($res[$key], $ru);
        if ($spans === null) { $retry[$key] = $u; continue; }
        tr_unit_set_en($blocks, $u, tr_fix_spans_dashes($spans, tr_spans_text($ru)));
        $filled++;
    }

    if ($retry) {
        $texts2 = [];
        foreach ($retry as $key => $u) {
            foreach (tr_fallback_texts(tr_unit_get($blocks, $u, 'ru')) as $i => $t) {
                $texts2[$key . ':' . $i] = $t;
            }
        }
        $res2 = $texts2 ? tr_translate_texts($texts2, $request, $deadline) : [];
        foreach ($retry as $key => $u) {
            $ru = tr_unit_get($blocks, $u, 'ru');
            $tr = [];
            foreach (tr_fallback_texts($ru) as $i => $_) { $tr[$i] = $res2[$key . ':' . $i] ?? null; }
            $spans = tr_fallback_apply($ru, $tr);
            if ($spans === null) { $failed++; continue; }
            tr_unit_set_en($blocks, $u, tr_fix_spans_dashes($spans, tr_spans_text($ru)));
            $filled++;
        }
    }

    if ($paras) {
        $en = [];
        foreach ($paras as $i => $p) {
            if ($res['p' . $i] === null) { $en = null; break; }
            $en[] = tr_fix_dashes($res['p' . $i], $p);
        }
        if ($en === null) {
            $failed++;
        } else {
            $post['body_en'] = implode("\n\n", $en);
            $filled++;
        }
    }

    return ['post' => $post, 'blocks' => $blocks, 'filled' => $filled, 'failed' => $failed];
}

function tr_fix_spans_dashes(array $spans, string $ru): array {
    foreach ($spans as $i => $sp) { $spans[$i]['s'] = tr_fix_dashes((string)$sp['s'], $ru); }
    return $spans;
}
