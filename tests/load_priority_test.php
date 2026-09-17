<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';

// Очерёдность загрузки на медленном канале. Картинки главной весят в сотни
// раз больше скриптов, и без подсказки сервер отдаёт их данные первыми. С
// канала 10–15 КБ/с (Алматы → Роттердам, замер 2026-09-17) topbar.js весом
// 2,6 КБ приходил на 40-й секунде, DOMContentLoaded случался тогда же, и
// только после него шапка спрашивала /api/session.php. С fetchpriority="high"
// у своих скриптов та же главная доходила до DOMContentLoaded за 2–3 секунды.

$PUB = dirname(__DIR__) . '/public_html';

const LP_PAGES = ['home.php', 'index.php', 'news.php', 'calculator.php'];

function lp_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) throw new RuntimeException("не читается: $path");
    return $s;
}

// Свои внешние скрипты страницы: полный тег и src. Сторонние (https:, //)
// и встроенные <script> без src не считаются.
function lp_own_scripts(string $html): array {
    preg_match_all('~<script\b[^>]*\bsrc="([^"\'\s<>]*)"[^>]*>~i', $html, $m, PREG_SET_ORDER);
    $own = [];
    foreach ($m as $t) {
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $t[1])) continue;
        $own[] = ['tag' => $t[0], 'src' => $t[1]];
    }
    return $own;
}

test('свои скрипты публичных страниц просят высокий приоритет', function () use ($PUB) {
    foreach (LP_PAGES as $page) {
        $own = lp_own_scripts(lp_read("$PUB/$page"));
        assert_true(count($own) >= 3, "$page: свои скрипты нашлись (" . count($own) . ')');
        foreach ($own as $s) {
            assert_true(strpos($s['tag'], 'fetchpriority="high"') !== false,
                "$page: {$s['src']} без fetchpriority=\"high\"");
        }
    }
});

test('порядок исполнения прежний: без async, defer только у шапки', function () use ($PUB) {
    // fetchpriority меняет лишь очередь скачивания. Скрипты страницы
    // по-прежнему исполняются по порядку: i18n.js раньше всех, кто зовёт
    // перевод, а topbar.js — отложенно, после разбора разметки.
    foreach (LP_PAGES as $page) {
        foreach (lp_own_scripts(lp_read("$PUB/$page")) as $s) {
            assert_true(!preg_match('~\basync\b~i', $s['tag']), "$page: {$s['src']} стал async");
            $isTopbar = (bool)preg_match('~^js/topbar\.js\?~', $s['src']);
            assert_eq($isTopbar, (bool)preg_match('~\sdefer[\s>]~i', $s['tag']),
                "$page: defer у {$s['src']}");
        }
    }
});

test('разбор тегов видит то, что должен', function () {
    $own = lp_own_scripts('<script src="js/a.js?v=1" defer fetchpriority="high"></script>'
        . '<script src="https://mc.yandex.ru/metrika/tag.js"></script><script>var x = 1;</script>');
    assert_eq(1, count($own), 'только свой внешний скрипт');
    assert_eq('js/a.js?v=1', $own[0]['src'] ?? null, 'src');
});

run_tests();
