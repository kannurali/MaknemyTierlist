<?php
// Разделы сайта, которые бот открывает в мини-приложении (решение владельца
// 08.11.2026): «тирлист», «сток» и т. п. в группе или в личке — бот отвечает
// кнопкой, кнопка открывает страницу сайта поверх чата. Дизайн, данные и
// реклама — самой страницы, своей вёрстки у мини-приложения для них нет.
//
// Кнопка — ссылка https://t.me/<бот>?startapp=<ключ>: в группе Telegram не
// пускает кнопки web_app, а ссылка на главное мини-приложение бота
// (@BotFather → Configure Mini App, https://maknemy.com/tg-app) работает
// везде и переживает пересылку. Telegram дописывает ключ к адресу
// мини-приложения (?tgWebAppStartParam=<ключ>), tg-app.php по нему отдаёт
// переход на страницу (js/tg-open.js). Ключи розыгрышей — gw<id>, с ними
// не пересекаются.
//
// Отвечают: чат-менеджер в группах (cm_cmd_site, api/lib/cm/router.php) и
// личка бота (tg_handle_update, api/lib/telegram.php). Слова команд в
// чат-менеджере — CM_COMMANDS (parse.php), тест сверяет их с TG_PAGE_WORDS.

// ключ startapp => [путь на сайте, текст сообщения, надпись кнопки]
const TG_PAGES = [
    'tierlist'  => ['/tierlist', '📊 Тирлист Blox Fruits — цены и спрос на фрукты.', 'Открыть тирлист'],
    'stock'     => ['/stock', '🍎 Сток Blox Fruits — что сейчас у дилера и в Mirage.', 'Открыть сток'],
    'calc'      => ['/calculator', '🧮 Калькулятор трейдов — выгоден ли обмен.', 'Открыть калькулятор'],
    'trading'   => ['/trading', '🤝 Трейды — объявления игроков об обмене.', 'Открыть трейды'],
    'news'      => ['/news', '📰 Новости Blox Fruits и Maknemy.', 'Открыть новости'],
    'giveaways' => ['/giveaways', '🎁 Розыгрыши фруктов на Maknemy.', 'Открыть розыгрыши'],
];

// Слово команды (нижний регистр, «е» вместо «ё») => ключ. Латинские — они же
// команды меню бота: /tierlist, /stock, /calc, /trading, /news, /giveaways.
const TG_PAGE_WORDS = [
    'тирлист'     => 'tierlist',
    'тир лист'    => 'tierlist',
    'tierlist'    => 'tierlist',
    'сток'        => 'stock',
    'stock'       => 'stock',
    'калькулятор' => 'calc',
    'калк'        => 'calc',
    'calc'        => 'calc',
    'трейды'      => 'trading',
    'трейдинг'    => 'trading',
    'trading'     => 'trading',
    'новости'     => 'news',
    'news'        => 'news',
    'розыгрыши'   => 'giveaways',
    'giveaways'   => 'giveaways',
];

const TG_PAGES_SITE = 'https://maknemy.com';

// Ссылка кнопки. Имя бота неизвестно — сразу страница сайта: откроется в
// браузере, но откроется.
function tg_page_link(string $bot, string $key): string {
    $bot = ltrim($bot, '@');
    if ($bot === '' || !preg_match('/^[A-Za-z0-9_]{3,64}\z/', $bot)) { return TG_PAGES_SITE . TG_PAGES[$key][0]; }
    return 'https://t.me/' . $bot . '?startapp=' . $key;
}

// Ключ раздела по сообщению лички: «сток», «Сток», «/stock», «/stock@бот»,
// «!сток». Только сообщение целиком — иначе null.
function tg_page_from_text(string $text, string $bot): ?string {
    $t = trim(str_replace('ё', 'е', mb_strtolower($text)));
    if ($t !== '' && strpos('/!.', $t[0]) !== false) { $t = ltrim(substr($t, 1)); }
    if (preg_match('~^(.+?)@(\w+)\z~u', $t, $m)) {
        if (strcasecmp($m[2], ltrim($bot, '@')) !== 0) { return null; }
        $t = $m[1];
    }
    $t = (string)preg_replace('~\s+~u', ' ', $t);
    return TG_PAGE_WORDS[$t] ?? null;
}

// Куда мини-приложению перейти по ключу из ссылки; '' — ключ не раздел сайта.
function tg_page_path(string $start): string {
    return isset(TG_PAGES[$start]) ? TG_PAGES[$start][0] : '';
}

// Команды для меню бота (setMyCommands): латинские слова по разделам.
function tg_page_commands(): array {
    $out = [];
    foreach (TG_PAGE_WORDS as $word => $key) {
        if (preg_match('/^[a-z]+\z/', $word) && !isset($out[$key])) {
            $out[$key] = ['command' => $word, 'description' => TG_PAGES[$key][2]];
        }
    }
    return array_values($out);
}
