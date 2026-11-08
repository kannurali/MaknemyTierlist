<?php
// Титулы хэллоуинского ивента Spooky Tokens для чужих глаз — рядом с ником в
// профиле, чате и объявлениях трейдинга. Отдельно от api/lib/hunt.php: чату
// и трейдингу нужен один запрос к hunt_titles, а не весь ивент с розыгрышами.
//
// Титул остаётся навсегда; текст берётся из titles_json своего сезона, так
// что правка текста в админке меняет его у всех, кто титул уже получил.
//
// У ника виден ОДИН титул — главный (решение владельца 08.11.2026): в чате,
// трейдинге и под ником профиля. Полный список — только в разделе «Титулы»
// профиля, там же человек выбирает главный (таблица title_picks). Не выбрал,
// или выбранного титула у него больше нет — главный первый по порядку:
// личный, потом заработанные, потом выданные в коде титулы ивента.

const HUNT_TITLE_KINDS    = ['login', 'hours'];
const HUNT_TITLES_DEFAULT = [
    'login' => ['ru' => 'Ночной дозор', 'en' => 'Night Watch'],
    'hours' => ['ru' => 'Хранитель склепа', 'en' => 'Crypt Keeper'],
];

// Титулы, выданные по слову владельца, — в коде, а не строками hunt_titles:
// ивент закончен, выдачи больше нет, а владельцу и разработчику достались
// оба его титула и по личному. Ключ — roblox_id (как в nick_logo.php).
// Запись без title — титул ивента, текст берётся у последнего сезона, чтобы
// правка в админке меняла его и здесь. Личный титул идёт первым; kind — вид
// плашки в css/hunt.css (data-kind). «Frost General» не переводится — слово
// владельца.
const HUNT_TITLES_GRANTED = [
    '2841062255' => [
        ['kind' => 'frost', 'title' => ['ru' => 'Frost General — maknemy owner', 'en' => 'Frost General — maknemy owner']],
        ['kind' => 'login'],
        ['kind' => 'hours'],
    ],
    '8755256557' => [
        ['kind' => 'hellfire', 'title' => ['ru' => 'Шут преисподней', 'en' => 'Jester of the Underworld']],
        ['kind' => 'login'],
        ['kind' => 'hours'],
    ],
];

function hunt_json($json): array {
    if (!is_string($json) || $json === '') { return []; }
    $v = json_decode($json, true);
    return is_array($v) ? $v : [];
}

// Тексты титулов сезона: {login:{ru,en}, hours:{ru,en}}, пустое — по умолчанию.
function hunt_titles_cfg(array $hunt): array {
    $v = hunt_json($hunt['titles_json'] ?? null);
    $out = [];
    foreach (HUNT_TITLE_KINDS as $k) {
        $t = is_array($v[$k] ?? null) ? $v[$k] : [];
        $out[$k] = [
            'ru' => is_string($t['ru'] ?? null) && $t['ru'] !== '' ? $t['ru'] : HUNT_TITLES_DEFAULT[$k]['ru'],
            'en' => is_string($t['en'] ?? null) && $t['en'] !== '' ? $t['en'] : HUNT_TITLES_DEFAULT[$k]['en'],
        ];
    }
    return $out;
}

/**
 * Титулы людей пакетом: [user_id => [{kind, title:{ru,en}, main}, …]]; у кого
 * нет — ключа нет. Ровно у одного титула человека main = true. Порядок списка
 * от выбора не зависит, чтобы раздел «Титулы» в профиле не прыгал. Таблиц ещё
 * нет (миграция не выполнена) — только выданные в коде: чат и лента от этого
 * не падают.
 */
function hunt_titles_for(PDO $pdo, array $uids): array {
    $uids = array_values(array_unique(array_filter(array_map('strval', $uids), function ($u) {
        return $u !== '' && ctype_digit($u);
    })));
    if (!$uids) { return []; }
    $out = [];
    try {
        $in = implode(',', array_fill(0, count($uids), '?'));
        $st = $pdo->prepare("SELECT t.user_id, t.kind, h.titles_json FROM hunt_titles t
                               JOIN hunts h ON h.id = t.hunt_id
                              WHERE t.user_id IN ($in) ORDER BY t.created_at, t.kind");
        $st->execute($uids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $kind = (string)$r['kind'];
            if (!in_array($kind, HUNT_TITLE_KINDS, true)) { continue; }
            $out[(string)$r['user_id']][] = ['kind' => $kind, 'title' => hunt_titles_cfg($r)[$kind]];
        }
    } catch (PDOException $e) {
        $out = [];
    }
    $out = hunt_titles_granted($pdo, $uids, $out);
    return hunt_titles_mark_main($out, $out ? hunt_title_picks($pdo, array_keys($out)) : []);
}

// Выбор главного титула пакетом: [user_id => kind]. Таблицы title_picks нет
// (миграция не выполнена) — пусто, главным становится первый по порядку.
function hunt_title_picks(PDO $pdo, array $uids): array {
    $uids = array_values(array_filter(array_map('strval', $uids), 'ctype_digit'));
    if (!$uids) { return []; }
    try {
        $in = implode(',', array_fill(0, count($uids), '?'));
        $st = $pdo->prepare("SELECT user_id, kind FROM title_picks WHERE user_id IN ($in)");
        $st->execute($uids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[(string)$r['user_id']] = (string)$r['kind']; }
        return $out;
    } catch (PDOException $e) {
        return [];
    }
}

// Поставить main: выбранному, если такой титул у человека есть, иначе первому.
function hunt_titles_mark_main(array $out, array $picks): array {
    foreach ($out as $uid => $list) {
        $kinds = array_column($list, 'kind');
        $at = array_search($picks[(string)$uid] ?? '', $kinds, true);
        if ($at === false) { $at = 0; }
        foreach ($list as $i => $t) { $out[$uid][$i]['main'] = $i === $at; }
    }
    return $out;
}

// Главный титул из списка hunt_titles_for; пустой список — null.
function hunt_title_main(array $list): ?array {
    foreach ($list as $t) {
        if (!empty($t['main'])) { return $t; }
    }
    return $list[0] ?? null;
}

/**
 * Сделать титул главным. Возвращает 'ok', 'bad_kind' (такого титула у
 * человека нет — выбрать можно только свой) или 'unavailable' (таблицы
 * title_picks нет: миграция не выполнена).
 *
 * Своими руками, а не ON DUPLICATE KEY: тесты идут на SQLite.
 */
function hunt_title_pick(PDO $pdo, string $uid, string $kind, int $now): string {
    $have = array_column(hunt_titles_for($pdo, [$uid])[$uid] ?? [], 'kind');
    if (!in_array($kind, $have, true)) { return 'bad_kind'; }
    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM title_picks WHERE user_id = :u')->execute([':u' => $uid]);
        $pdo->prepare('INSERT INTO title_picks (user_id, kind, updated_at) VALUES (:u, :k, :t)')
            ->execute([':u' => $uid, ':k' => $kind, ':t' => $now]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return 'unavailable';
    }
    return 'ok';
}

// Тексты титулов последнего сезона; нет таблицы или сезона — по умолчанию.
function hunt_titles_latest(PDO $pdo): array {
    try {
        $row = $pdo->query("SELECT titles_json FROM hunts WHERE status IN ('live', 'drawn') ORDER BY id DESC LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);
        return hunt_titles_cfg($row ?: []);
    } catch (PDOException $e) {
        return hunt_titles_cfg([]);
    }
}

// Дописать выданные в коде титулы (HUNT_TITLES_GRANTED): личный — первым,
// титул ивента, который человек и так заработал, второй раз не добавляется.
function hunt_titles_granted(PDO $pdo, array $uids, array $out): array {
    $season = null;
    foreach ($uids as $uid) {
        $grants = HUNT_TITLES_GRANTED[$uid] ?? null;
        if (!$grants) { continue; }
        $earned = $out[$uid] ?? [];
        $have = array_column($earned, 'kind');
        $own = [];
        $event = [];
        foreach ($grants as $g) {
            if (in_array($g['kind'], $have, true)) { continue; }
            if (isset($g['title'])) {
                $own[] = ['kind' => $g['kind'], 'title' => $g['title']];
                continue;
            }
            if ($season === null) { $season = hunt_titles_latest($pdo); }
            $event[] = ['kind' => $g['kind'], 'title' => $season[$g['kind']]];
        }
        $out[$uid] = array_merge($own, $earned, $event);
    }
    return $out;
}

// Дописать поле titles в список людей [id => человек]. В нём только главный
// титул (или пусто): чат и трейдинг показывают у ника один. Поле осталось
// списком, чтобы закешированный у посетителя старый js читал его как раньше.
function hunt_titles_attach(PDO $pdo, array $people): array {
    $titles = hunt_titles_for($pdo, array_keys($people));
    foreach ($people as $id => $p) {
        $main = hunt_title_main($titles[(string)$id] ?? []);
        $people[$id]['titles'] = $main !== null ? [$main] : [];
    }
    return $people;
}

// Титул на языке страницы: русский — русский, остальные — английский.
function hunt_title_text(array $title, string $lang): string {
    return (string)($lang === 'ru' ? $title['ru'] : $title['en']);
}
