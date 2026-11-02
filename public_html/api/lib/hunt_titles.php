<?php
// Титулы хэллоуинского ивента Spooky Tokens для чужих глаз — рядом с ником в
// профиле, чате и объявлениях трейдинга. Отдельно от api/lib/hunt.php: чату
// и трейдингу нужен один запрос к hunt_titles, а не весь ивент с розыгрышами.
//
// Титул остаётся навсегда; текст берётся из titles_json своего сезона, так
// что правка текста в админке меняет его у всех, кто титул уже получил.

const HUNT_TITLE_KINDS    = ['login', 'hours'];
const HUNT_TITLES_DEFAULT = [
    'login' => ['ru' => 'Ночной дозор', 'en' => 'Night Watch'],
    'hours' => ['ru' => 'Хранитель склепа', 'en' => 'Crypt Keeper'],
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
 * Титулы людей пакетом: [user_id => [{kind, title:{ru,en}}, …]]; у кого нет —
 * ключа нет. Таблиц ещё нет (миграция не выполнена) — пусто: чат и лента от
 * этого не падают.
 */
function hunt_titles_for(PDO $pdo, array $uids): array {
    $uids = array_values(array_unique(array_filter(array_map('strval', $uids), function ($u) {
        return $u !== '' && ctype_digit($u);
    })));
    if (!$uids) { return []; }
    try {
        $in = implode(',', array_fill(0, count($uids), '?'));
        $st = $pdo->prepare("SELECT t.user_id, t.kind, h.titles_json FROM hunt_titles t
                               JOIN hunts h ON h.id = t.hunt_id
                              WHERE t.user_id IN ($in) ORDER BY t.created_at, t.kind");
        $st->execute($uids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $kind = (string)$r['kind'];
            if (!in_array($kind, HUNT_TITLE_KINDS, true)) { continue; }
            $out[(string)$r['user_id']][] = ['kind' => $kind, 'title' => hunt_titles_cfg($r)[$kind]];
        }
        return $out;
    } catch (PDOException $e) {
        return [];
    }
}

// Дописать поле titles в список людей [id => человек].
function hunt_titles_attach(PDO $pdo, array $people): array {
    $titles = hunt_titles_for($pdo, array_keys($people));
    foreach ($people as $id => $p) {
        $people[$id]['titles'] = $titles[(string)$id] ?? [];
    }
    return $people;
}

// Титул на языке страницы: русский — русский, остальные — английский.
function hunt_title_text(array $title, string $lang): string {
    return (string)($lang === 'ru' ? $title['ru'] : $title['en']);
}
