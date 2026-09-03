<?php
// Счётчик Яндекс Метрики — один на все публичные страницы сайта.
//
// Раньше код счётчика лежал прямо в index.php, и только там. В статистику
// из-за этого попадал один тирлист: главная, калькулятор и лента новостей не
// считались вовсе, хотя в шапке стоят равноправными разделами. По отчётам
// такое не видно в принципе — Метрика показывает ровно те страницы, до
// которых доехал счётчик, и раздел без счётчика выглядит как «туда не ходят»,
// а не как «там нечем считать». Нашлось это не через отчёты, а через поиск по
// исходникам при переезде на maknemy.com.
//
// Общего <head> у страниц нет — каждая пишет свой (см. home.php, index.php,
// calculator.php, news.php), поэтому разметка отдаётся функцией, а не
// include-ом шаблона: место вставки остаётся за страницей, а сам код счётчика
// живёт в единственном экземпляре.

// Номер счётчика. Этот же номер зашит в js/app.js — там он уходит в
// ym(..., 'reachGoal', ...) для кликов по рекламе, и общей константы у PHP с
// браузерным кодом нет. Расхождение ловит tests/metrika_test.php.
const METRIKA_ID = 111127188;

// Комментарии-маркеры вокруг блока — часть контракта, а не оформление: по ним
// metrika_strip() вырезает счётчик из админских страниц. Уберёшь их —
// собственные клики и вебвизор редактора начнут молча попадать в статистику
// сайта.
//
// Перевод строки в конце дописывается отдельно, потому что PHP съедает ровно
// один перевод сразу после закрывающего тега. Без него закрывающий маркер и
// </head> вызывающей страницы склеились бы в одну строку.
function metrika_counter_html(): string {
    $id = METRIKA_ID;
    $block = <<<HTML
<!-- Yandex.Metrika counter -->
<script type="text/javascript">
    (function(m,e,t,r,i,k,a){
        m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
        m[i].l=1*new Date();
        for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
        k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)
    })(window, document,'script','https://mc.yandex.ru/metrika/tag.js?id={$id}', 'ym');

    ym({$id}, 'init', {ssr:true, webvisor:true, trackHash:true, clickmap:true, ecommerce:"dataLayer", referrer: document.referrer, url: location.href, accurateTrackBounce:true, trackLinks:true});
</script>
<noscript><div><img src="https://mc.yandex.ru/watch/{$id}" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
<!-- /Yandex.Metrika counter -->
HTML;
    return $block . "\n";
}

// Убирает счётчик из уже отрендеренной публичной страницы.
//
// Нужно админке: /admin и /admin/news не хранят копию вёрстки, а исполняют
// index.php и news.php целиком (см. admin_render_public_page()), поэтому
// счётчик приезжает к ним вместе со всей разметкой. Свои же клики, прокрутки
// и записи вебвизора в статистике сайта — мусор, который к тому же не
// отфильтровать задним числом.
//
// Вызов живёт в admin_render_public_page(), а не в самих admin*.php: раньше
// вырезание стояло только в admin.php, и когда лента новостей получила свою
// админку, /admin/news осталась бы со счётчиком — ровно тот класс ошибки,
// который повторяется при каждой новой админ-странице.
function metrika_strip(string $html): string {
    $out = preg_replace(
        '~<!-- Yandex\.Metrika counter -->.*?<!-- /Yandex\.Metrika counter -->~su',
        '<!-- Метрика вырезана: админку в статистику сайта не считаем. -->',
        $html,
        1
    );
    // preg_replace возвращает null на сбое (например, backtrack limit на
    // очень длинной странице). Отдать null вместо страницы — уронить админку
    // целиком; лучше вернуть разметку как есть.
    return $out ?? $html;
}
