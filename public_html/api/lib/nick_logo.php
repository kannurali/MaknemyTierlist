<?php
// Знак рядом с ником — у владельца сайта и у разработчика.
//
// Список один на весь сайт. Профиль печатает знак сам (profile.php), а
// трейдинг, чат и меню в шапке получают путь полем logo в данных
// пользователя — trade_authors(), chat_user_row(), roblox_load_user() — и
// рисуют его скриптом. Второй список в JS разошёлся бы с этим при первой
// же правке.
//
// Maknemy (Roblox Shamill_prod) — «MK», как в подвале сайта, но без
// прозрачных полей квадратной версии: иначе знак выходил бы ниже букв ника.
// The Fool (Roblox kan_nurali) — шут. Ключи — roblox_id, пути абсолютные:
// скрипты вставляют их на страницах вроде /trading/new, где относительный
// путь уехал бы в /trading/assets/….
const NICK_LOGOS = [
    '2841062255' => '/assets/design/logo-mk.png',
    '8755256557' => '/assets/design/logo-fool.png',
];

/** Путь к знаку у ника этого игрока, либо null — знака у него нет. */
function nick_logo(string $id): ?string {
    return NICK_LOGOS[$id] ?? null;
}
