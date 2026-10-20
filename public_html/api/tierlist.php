<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/i18n_content.php';

// Тирлист целиком. Данные отдаются только по адресу текущей ревизии
// ?rev=<n>: такой ответ не меняется никогда, браузер держит его год, а
// LiteSpeed — в своём кеше, и повторный запрос обходится без PHP. Любой
// другой адрес — без ?rev=, со старой ревизией, с мусором — уводится 302 на
// текущую.
//
// Раньше полный ответ с immutable получал любой ?rev=. Случайное число в
// адресе обходило любой кеш, и каждый такой запрос стоил PHP, базы и ~21 КБ
// трафика. Теперь он стоит одного короткого запроса к базе и редиректа.
// Отставший клиент (ревизия сменилась между опросом и загрузкой) получает
// свежие данные на один редирект позже.
//
// rev и data читаются одним запросом: иначе публикация между двумя чтениями
// положила бы новые данные под адрес старой ревизии — навсегда.
//
// &lang=<язык> после rev — описания и условия предметов на одном языке
// страницы (api/lib/i18n_content.php). Без него — прежний ответ на двух
// языках: его читают админка, калькулятор, чат и трейды. Неизвестный язык
// уводится 302 на адрес без него.
//
// Третье значение ответа 200 — можно ли кешировать навсегда. Переводы
// описаний пишутся в фоне уже после сохранения тирлиста, под той же
// ревизией: ответ, собранный раньше, чем они легли, на год застрял бы с
// английским откатом. Такой ответ кешируется ненадолго (TIERLIST_PARTIAL_CACHE).
const TIERLIST_PARTIAL_CACHE = 300;

function tierlist_route(PDO $pdo, $rawRev, $rawLang = null): array {
    $lang = i18n_api_lang($rawLang);
    $row = $pdo->query("SELECT data, rev FROM tierlist WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $rev = $row ? (int)$row['rev'] : 0;
    if (parse_rev_param($rawRev) !== $rev || ($rawLang !== null && $lang === null)) {
        return [302, ['location' => '/api/tierlist.php?rev=' . $rev . ($lang !== null ? '&lang=' . $lang : '')]];
    }
    $likes = (int)$pdo->query("SELECT count FROM likes WHERE id = 1")->fetchColumn();
    $tierlist = ($row && $row['data']) ? json_decode($row['data'], true) : null;
    $final = true;
    if ($lang !== null && is_array($tierlist)) {
        $ids = array_column(i18n_items($tierlist), 'id');
        $rows = $lang === I18N_DEFAULT ? [] : i18n_rows($pdo, 'item', $lang, $ids);
        $enRows = in_array($lang, I18N_CONTENT_LANGS, true) ? i18n_rows($pdo, 'item', 'en', $ids) : [];
        [$tierlist, $final] = i18n_tierlist_localize($tierlist, $lang, $rows, $enRows);
    }
    return [200, ['tierlist' => $tierlist, 'likes' => $likes], $final];
}

if (!defined('TESTING')) {
    $res = tierlist_route(db(), $_GET['rev'] ?? null, $_GET['lang'] ?? null);
    [$status, $payload] = $res;
    if ($status === 302) {
        header('Cache-Control: no-store');
        lscache_off();
        header('Location: ' . $payload['location'], true, 302);
        exit;
    }
    if ($res[2]) {
        header('Cache-Control: public, max-age=31536000, immutable');
        lscache_public(86400);
    } else {
        header('Cache-Control: public, max-age=' . TIERLIST_PARTIAL_CACHE);
        lscache_public(TIERLIST_PARTIAL_CACHE);
    }
    json_out($payload, $status);
}
