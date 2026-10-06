<?php
// Copy to config.php. In production place ABOVE public_html and point
// _bootstrap.php's CONFIG_PATH at it. NEVER commit the real config.php.
return [
    // PDO DSN. Local XAMPP example below; production uses the cPanel DB.
    'dsn'      => 'mysql:host=127.0.0.1;dbname=nexus;charset=utf8mb4',
    'db_user'  => 'root',
    'db_pass'  => '',
    // Админы — Roblox id аккаунтов сайта: число из адреса
    // roblox.com/users/<id>/profile (пункт «Профиль в Roblox» в меню аватара).
    // Входят на сайт через Roblox и видят всю панель: тирлист, новости,
    // рекламу, обращения. Пароля у админки нет. Всем, кого нет в списках,
    // /admin отвечает обычным 404.
    'admin_ids' => [],
    // Absolute path to the writable images directory.
    'images_dir' => __DIR__ . '/public_html/images',

    // --- Roblox OAuth (вход посетителей) -------------------------------
    // Пустой client_id или secret = вход выключен: api/roblox_start.php
    // отвечает 503, а шапка не показывает кнопку (см. README).
    // Приложение заводится на create.roblox.com/dashboard/credentials.
    'roblox_client_id'     => '',
    'roblox_client_secret' => '',
    // Должен СОВПАДАТЬ посимвольно с Redirect URI в настройках
    // приложения у Roblox. Задан явно, а не собран из $_SERVER['HTTP_HOST']:
    // заголовок Host приходит от клиента и подделывается.
    'roblox_redirect_uri'  => 'https://maknemy.com/api/roblox_callback.php',
    // Кнопку входа видят все посетители только при true. Пока приложение в
    // Roblox не прошло ревью, войти могут лишь 10 разных аккаунтов, поэтому
    // до одобрения здесь false: кнопку показывают только тем, кто открыл
    // сайт по ссылке https://maknemy.com/?signin (она же — Entry Link).
    'roblox_login_public'  => false,

    // --- Уведомления в Telegram (api/lib/telegram.php) -----------------
    // Бот заводится у @BotFather: /newbot → токен вида 123456:ABC-DEF…
    // Пустой токен или имя = уведомления выключены, колокольчика в чате и в профиле нет,
    // рассылок цен и новостей тоже.
    // После того как токен вписан: /admin/support → «Подключить вебхук».
    'tg_bot_token'  => '',
    // Имя бота без @ — из него собирается ссылка t.me/<имя>?start=…
    'tg_bot_name'   => '',
    // Модераторы — Roblox id аккаунтов сайта (как в адресе /profile?id=…).
    // В панели им открыты только обращения (/admin/support). Им же бот пишет
    // о новых обращениях, если они подключили Telegram колокольчиком (в чате или в профиле) —
    // админ, которому нужны эти уведомления, вписывает себя и сюда.
    'moderator_ids' => [],
    // Бан — Roblox id (как в адресе /profile?id=…). Действует со следующего
    // запроса: забаненного выкидывает из аккаунта на всех устройствах, войти
    // заново он не может, его объявления пропадают из ленты и профиля.
    // Переписка у собеседников остаётся. Снять бан — убрать id из списка:
    // объявления, которые ещё не истекли, вернутся.
    // Временный бан — id => срок по Москве; срок вышел, бан снялся сам:
    //   'banned_ids' => [
    //       '1234567890',                        // навсегда
    //       '2222222222' => '2026-10-20',        // до 20.10.2026 00:00
    //       '3333333333' => '2026-10-20 18:30',  // до 18:30 того дня
    //   ],
    'banned_ids' => [],

    // --- Сток фруктов из Discord (api/lib/stock.php) -------------------
    // Сток публикует чужой бот Vulcan в канал нашего Discord-сервера, а наш
    // бот (Fool's eyes) читает этот канал в момент смены стока — cron в cPanel
    // будит его в первые 15 минут каждого часа, в остальное время он спит:
    //   0-15 * * * * /usr/local/bin/php /home/maknemyt/public_html/api/stock_pull.php >/dev/null 2>&1
    // Пустой токен или канал = сбор выключен, /stock показывает последний
    // известный сток.
    // Токен: discord.com/developers/applications → приложение → Bot → Reset Token.
    'discord_bot_token'     => '',
    // id канала с постами Vulcan: Discord в режиме разработчика →
    // правый клик по каналу → «Копировать ID канала».
    'discord_stock_channel' => '',

    // --- GitHub push webhook (api/deploy.php) ---------------------------
    // Leave 'deploy_secret' empty to keep the endpoint disabled: it then
    // answers 503 and never runs anything.
    // Generate: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    // Paste the same value into the webhook's Secret field on GitHub.
    'deploy_secret' => '',
    // Absolute path of the cPanel Git clone (NOT the web root).
    'deploy_repo'   => '/home/maknemyt/repositories/NexusTierlist',
    // Web root the repo's public_html/ is copied into.
    'deploy_path'   => '/home/maknemyt/public_html',
    'deploy_branch' => 'master',
    // Optional: absolute path to git if auto-detection fails.
    // 'deploy_git'  => '/usr/local/cpanel/3rdparty/bin/git',
    // Optional: defaults to deploy.log next to this file.
    // 'deploy_log'  => '/home/maknemyt/deploy.log',
];
